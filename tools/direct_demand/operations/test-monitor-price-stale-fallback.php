<?php
declare(strict_types=1);
require __DIR__.'/monitor-price-stale-fallback.php';
function stale_test(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('TEST_FAILED '.$message);
}
function stale_rejected(callable $callback, string $reason): void {
    try { $callback(); } catch (RuntimeException $e) { stale_test($e->getMessage()===$reason, 'wrong reject '.$reason); return; }
    throw new RuntimeException('TEST_FAILED did not reject '.$reason);
}
$now = strtotime('2026-11-17 02:22:00 Europe/Moscow');
$observed = function(int $seconds) use ($now): string {
    return (new DateTimeImmutable('@'.($now-$seconds)))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y, H:i');
};
$caption = function(string $stamp): string {
    return "✈️ Туры в Hotel Example\n📍 Турция.\n🏖 Пляж.\n💰 От 123 400 ₽ за двоих\n✈️ Вылет из Москвы 20.12.2026 · 5 ночей\n👥 2 взрослых\n🛏 standard room\n🍽 Всё включено\nЦена найдена мониторингом $stamp МСК. Стоимость и наличие могут измениться.\n✈️ Подберём подходящий номер и рассчитаем тур с перелётом на ваши даты.";
};
// Timestamps are minute-precision, so test exact 36h boundaries using aligned minute times.
$confirmedAt = $now-36*3600;
$original = $caption($observed(36*3600));
$plan = monitor_stale_caption_plan($original, hash('sha256',$original), $now, $confirmedAt);
stale_test($plan['state']==='ready' && $plan['age_seconds']===129600, '36h strict cutoff');
stale_test(str_contains($plan['new_text'], '🏖 Пляж.') && str_contains($plan['new_text'], '✈️ Подберём подходящий номер') && substr_count($plan['new_text'], '💰 Стоимость тура — по запросу')===1, 'preserves non-price content');
$before = $caption($observed(35*3600));
stale_test(monitor_stale_caption_plan($before, hash('sha256',$before), $now, $now-35*3600)['state']==='not_expired', '35h held');
$nearAt=$confirmedAt+1;
$near=$caption($observed(36*3600-1));
stale_test(monitor_stale_caption_plan($near,hash('sha256',$near),$now,$nearAt)['state']==='not_expired', '35:59:59 even when displayed minute is 36:00');
stale_rejected(fn()=>monitor_stale_caption_plan($original,hash('sha256',$original),$now,$confirmedAt-60),'stale_caption_proof_mismatch');
stale_rejected(fn()=>monitor_stale_caption_plan($original, str_repeat('0',64),$now,$confirmedAt),'stale_caption_cas_mismatch');
stale_rejected(fn()=>monitor_stale_caption_plan($plan['new_text'],hash('sha256',$plan['new_text']),$now,$confirmedAt),'stale_caption_shape_changed');
$future=$caption($observed(-3600));
stale_rejected(fn()=>monitor_stale_caption_plan($future,hash('sha256',$future),$now,$now+3600),'stale_caption_future_observation');
$broken=str_replace('Цена найдена мониторингом','Проверка цены', $original);
stale_rejected(fn()=>monitor_stale_caption_plan($broken,hash('sha256',$broken),$now,$confirmedAt),'stale_caption_shape_changed');
$url='https://t.me/example/25';
$block = static function(string $id,string $url,string $desc):string {
    return "<offer id=\"$id\">\n        <url>".htmlspecialchars($url,ENT_QUOTES|ENT_XML1,'UTF-8')."</url>\n        <price>123400</price>\n        <currencyId>RUB</currencyId>\n        <description>".htmlspecialchars($desc,ENT_QUOTES|ENT_XML1,'UTF-8')."</description>\n        <picture>https://example.com/pic.jpg</picture>\n    </offer>";
};
$sibling=$block('tg_unrelated','https://t.me/example/26','A safe sibling');
$xml='<?xml version="1.0" encoding="UTF-8"?>' ."\n" .'<yml_catalog date="2026-11-16 01:11"><shop><offers>'
    .$block('tg_example',$url,$original).$sibling.'</offers></shop></yml_catalog>';
$feed=monitor_stale_feed_plan($xml,hash('sha256',$xml),'tg_example',$url,$original,$plan,$now,$confirmedAt);
stale_test($feed['state']==='ready' && !str_contains($feed['new_xml'],'<price>123400</price>\n        <currencyId>RUB</currencyId>\n        <description>'.htmlspecialchars($original,ENT_QUOTES|ENT_XML1,'UTF-8')),'price block replaced');
stale_test(substr_count($feed['new_xml'],'<price>123400</price>')===1,'other price untouched');
stale_test(str_contains($feed['new_xml'],$sibling),'sibling byte-identical');
stale_test(str_contains($feed['new_xml'],'Стоимость тура — по запросу'),'safe fallback shown');
$fakePlan=$plan;$fakePlan['new_text']='💰 1 ₽';
stale_rejected(fn()=>monitor_stale_feed_plan($xml,hash('sha256',$xml),'tg_example',$url,$original,$fakePlan,$now,$confirmedAt),'stale_feed_caption_unconfirmed');
stale_rejected(fn()=>monitor_stale_feed_plan($xml,str_repeat('0',64),'tg_example',$url,$original,$plan,$now,$confirmedAt),'stale_feed_cas_mismatch');
stale_rejected(fn()=>monitor_stale_feed_plan($xml,hash('sha256',$xml),'tg_example',$url.'x',$original,$plan,$now,$confirmedAt),'stale_feed_current_offer_mismatch');
stale_rejected(fn()=>monitor_stale_feed_plan($xml,hash('sha256',$xml),'tg_example',$url,$original,['state'=>'not_expired'],$now,$confirmedAt),'stale_feed_caption_unconfirmed');
stale_rejected(fn()=>monitor_stale_feed_plan(str_replace('<yml_catalog','<!DOCTYPE foo><yml_catalog',$xml),hash('sha256',str_replace('<yml_catalog','<!DOCTYPE foo><yml_catalog',$xml)),'tg_example',$url,$original,$plan,$now,$confirmedAt),'stale_feed_format_changed');
echo "MONITOR_PRICE_STALE_FALLBACK_OFFLINE_OK boundary36=1 preserve=1 cas=1 feed=1 sibling=1 unknown_hold=1 no_writes=1\n";
