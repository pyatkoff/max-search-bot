<?php
declare(strict_types=1);

require_once __DIR__.'/../services/PromotedHotelPriceWriter.php';

function promotedPriceCheck(bool $ok, string $case): void
{
    if (!$ok) throw new RuntimeException($case);
}

$caption = "✈️ Seagull & Sea\n\nХургада, Египет";
$price = "💰 От 102 233 ₽ за двоих\n✈️ Вылет из Москвы 16.01.2027 · 5 ночей\n"
    ."👥 2 взрослых\n🛏 standard room\n🍽 Всё включено\n"
    ."Цена найдена мониторингом 19.11.2026, 04:01 МСК. Стоимость и наличие могут измениться.";
$first = PromotedHotelPriceWriter::revision($caption,$price,true);
promotedPriceCheck($first === $caption."\n\n".$price, 'caption_copy_changed');
$next = str_replace(['102 233','04:01'],['102 222','04:02'],$price);
promotedPriceCheck(PromotedHotelPriceWriter::revision($first,$next,true) === $caption."\n\n".$next, 'duplicate_price_blocks');
promotedPriceCheck(PromotedHotelPriceWriter::revision($caption."\n\n".$next,$price,true) === $caption."\n\n".$next, 'older_price_overwrite');
promotedPriceCheck(PromotedHotelPriceWriter::revision($first,$price,true) === $first, 'same_price_not_idempotent');
$length = intdiv(strlen(mb_convert_encoding('✈️ Seagull & Sea','UTF-16LE','UTF-8')),2);
$html = PromotedHotelPriceWriter::maxHtml($caption,$first,[['type'=>'strong','from'=>0,'length'=>$length]]);
promotedPriceCheck(str_starts_with($html,"<b>✈️ Seagull &amp; Sea</b>\n\n") && str_ends_with($html,$price), 'max_markup_lost');
promotedPriceCheck(PromotedHotelPriceWriter::maxPublicCaption('message:{text:"JAZ Fanara &amp; Residence"}')
    === 'JAZ Fanara & Residence', 'max_public_html_entities_not_decoded');

foreach ([
    fn()=>PromotedHotelPriceWriter::revision('Старая цена 100 000 ₽',$price,true),
    fn()=>PromotedHotelPriceWriter::revision(str_repeat('😀',510),$price,true),
    fn()=>PromotedHotelPriceWriter::maxHtml('😀 open','😀 open tail',[['type'=>'strong','from'=>1,'length'=>1]]),
    fn()=>PromotedHotelPriceWriter::maxHtml('old','new',[['type'=>'strong','from'=>0,'length'=>3]]),
] as $reject) {
    try { $reject(); } catch (RuntimeException) { continue; }
    throw new RuntimeException('unsafe_revision_accepted');
}

$tg = ['hotel_id'=>415, 'platform'=>'telegram', 'publication_id'=>20, 'url'=>'https://t.me/anytour/1754'];
$max = ['hotel_id'=>174, 'platform'=>'max', 'publication_id'=>3574, 'url'=>'https://max.ru/anytour/AaHzN34rD6Q'];
$targets = [$tg, $max];
$targetBytes = json_encode($targets, JSON_THROW_ON_ERROR);
$feedHash = hash('sha256', 'offline-price-fixture');
$plan = ['feed_sha256'=>$feedHash, 'targets_sha256'=>hash('sha256', $targetBytes),
    'target_hotels'=>2, 'eligible_hotels'=>2, 'held'=>[], 'plans'=>[
        ['hotel_id'=>415, 'price_block'=>$price, 'telegram_url'=>$tg['url'], 'telegram_publication_id'=>20],
        ['hotel_id'=>174, 'price_block'=>$price, 'max_url'=>$max['url'], 'max_publication_id'=>3574],
    ]];
$jobs = PromotedHotelPriceWriter::jobsForPlan($plan, $feedHash, $targetBytes);
promotedPriceCheck(array_column($jobs, 'publication') === [20,3574]
    && array_column($jobs, 'platform') === ['telegram','max'] && array_column($jobs, 'url') === [$tg['url'],$max['url']],
    'single_platform_misrouted');
$held = $plan;
array_pop($held['plans']);
$held['eligible_hotels'] = 1;
$held['held'][] = ['hotel_id'=>174,'reason'=>'missing_or_duplicate_feed_offer'];
promotedPriceCheck(count(PromotedHotelPriceWriter::jobsForPlan($held, $feedHash, $targetBytes)) === 1, 'held_hotel_edited');

$rejectPlan = static function (array $p, array $scope, string $reason) use ($feedHash): void {
    $raw = json_encode($scope, JSON_THROW_ON_ERROR);
    $p['targets_sha256'] = hash('sha256', $raw);
    try {
        PromotedHotelPriceWriter::jobsForPlan($p, $feedHash, $raw);
    } catch (RuntimeException $error) {
        promotedPriceCheck($error->getMessage() === $reason, 'unexpected_plan_rejection');
        return;
    }
    throw new RuntimeException('unsafe_plan_accepted');
};
foreach (['max_publication_id'=>20, 'max_url'=>'https://max.ru/anytour/Other',
    'telegram_url'=>$tg['url'], 'telegram_publication_id'=>20, 'other_url'=>$tg['url'], 'hotel_id'=>415] as $field=>$wrong) {
    $new = $plan;
    $new['plans'][1][$field] = $wrong;
    $rejectPlan($new, $targets, 'price_plan_mismatch');
}
$mismatch = $held;
$mismatch['held'] = [];
$rejectPlan($mismatch, $targets, 'price_plan_mismatch');
$mismatch = $held;
$mismatch['held'][0]['hotel_id'] = 415;
$rejectPlan($mismatch, $targets, 'price_plan_mismatch');
$mismatch = $plan;
$mismatch['feed_sha256'] = hash('sha256', 'different snapshot');
$rejectPlan($mismatch, $targets, 'price_plan_mismatch');
try {
    PromotedHotelPriceWriter::jobsForPlan($plan, $feedHash, $targetBytes."\n");
    throw new LogicException('roster_hash_mismatch_accepted');
} catch (RuntimeException $error) {
    promotedPriceCheck($error->getMessage() === 'price_plan_mismatch', 'wrong_scope_rejection');
}
$rejectPlan($plan, [$tg, array_replace($max, ['publication_id'=>20])], 'duplicate_promoted_target');
$rejectPlan($plan, [$tg, array_replace($tg, ['hotel_id'=>174,'publication_id'=>3574])], 'duplicate_promoted_target');
$rejectPlan($plan, [$tg, array_replace($tg, ['url'=>'https://t.me/anytour/1792','publication_id'=>3575])], 'duplicate_promoted_target');
$rejectPlan($plan, [$tg, array_replace($max, ['url'=>'https://max.ru/channel_anytour/wrong'])], 'invalid_promoted_target');
echo "promoted hotel price editor regression passed\n";
