<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/TourOfferCardRenderer.php';
$checks = 0;
function cardAssert(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
function cardSnapshot(array $changes = []): array {
    return array_replace(['hotel'=>'Delphin Imperial 5★','departure'=>'Москва','date_from'=>'2027-06-05','date_to'=>'2027-06-12',
        'nights'=>7,'adults'=>2,'child_ages'=>[],'room'=>'Улучшенный номер','meal'=>'Ультра всё включено',
        'flights'=>[],'amount'=>'238400','currency'=>'RUB','price_basis'=>'party','is_demo'=>true,'price_verified'=>false,
        'rationale'=>'','compromise'=>''], $changes);
}
function cardPresentation(array $changes = []): array {
    return array_replace(['destination'=>'Турция, Лара','inclusions'=>['Перелёт, трансфер и страховка включены.'],
        'photos'=>[['role'=>'cover','url'=>'https://www.delphinhotel.com/main_pics/pages/medium/2996.png'],
            ['role'=>'beach','url'=>'https://www.delphinhotel.com/main_pics/pages/big/4270.png'],
            ['role'=>'room','url'=>'https://www.delphinhotel.com/main_pics/pages/medium/709.png']], 'room_illustrative'=>true], $changes);
}
function cardReject(array $snapshot, array $presentation, string $callback, string $message): void {
    try { TourOfferCardRenderer::render($snapshot, $presentation, $callback); }
    catch (InvalidArgumentException $expected) { cardAssert(true, $message); return; }
    cardAssert(false, $message);
}
function cardTelegramText($text): string {
    if (is_string($text)) return $text;
    if (isset($text['type'])) return cardTelegramText($text['text']);
    return implode('', array_map('cardTelegramText', $text));
}
$callback = 'tour_like_1001_'.str_repeat('a',24);
$card = TourOfferCardRenderer::render(cardSnapshot(), cardPresentation(), $callback);
$blocks = $card['telegram']['rich_message']['blocks'];
cardAssert($card['snapshot'] === TourChoiceService::normalize(cardSnapshot()), 'Renderer shares the exact immutable snapshot normalizer');
cardAssert(count($blocks) === 5 && $blocks[0]['type'] === 'slideshow' && count($blocks[0]['blocks']) === 3, 'One native Telegram slideshow contains three photos');
foreach ([0,1,2] as $index) cardAssert($blocks[0]['blocks'][$index] === ['type'=>'photo','photo'=>['type'=>'photo','media'=>'attach://photo'.$index]], 'Ordered native attachment names are deterministic');
cardAssert($blocks[1]['text'] === [['type'=>'bold','text'=>'Delphin Imperial 5★'],' · Турция, Лара']
    && $blocks[2]['text'] === ['type'=>'bold','text'=>'238 400 ₽ за двоих'], 'Only hotel and complete party price are emphasized');
cardAssert(str_starts_with($blocks[3]['text'], "📅 5–12 июня 2027 · 7 ночей\n✈️ Москва · 2 взрослых\n🛏️ Улучшенный номер\n🍽️ Ультра всё включено"), 'Approved four emoji lines preserve exact departure and conditions');
cardAssert($card['telegram']['reply_markup'] === ['inline_keyboard'=>[[['text'=>'Нравится','callback_data'=>$callback]]]], 'Telegram button carries the real offer callback');
cardAssert($card['max']['format'] === 'html' && $card['max']['attachments'] === [['type'=>'inline_keyboard','payload'=>['buttons'=>[[['type'=>'callback','text'=>'Нравится','payload'=>$callback]]]]]], 'MAX has one exact callback keyboard and awaits separately uploaded images');
$telegramPlain = implode("\n", array_map(static fn(array $block): string=>cardTelegramText($block['text']), array_slice($blocks,1,3)))."\n\n".cardTelegramText($blocks[4]['text']);
cardAssert($telegramPlain === $card['plain_text'] && html_entity_decode(strip_tags($card['max']['text']), ENT_QUOTES|ENT_HTML5, 'UTF-8') === $card['plain_text'], 'Both native bodies match the recorder mirror exactly');
cardAssert(str_contains($blocks[4]['text'], 'Демо:') && str_contains($blocks[4]['text'], 'Актуальность цены не проверена.')
    && str_contains($blocks[4]['text'], 'Фото номера — пример интерьера отеля.'), 'Demo, unverified price and illustrative room remain explicit');

$family = TourOfferCardRenderer::render(cardSnapshot(['adults'=>1,'child_ages'=>[5,5,0],'amount'=>'1234.50','currency'=>'EUR','is_demo'=>false,
    'flights'=>['Москва — Анталья 10:00–14:00','Анталья — Москва 15:00–19:00'], 'rationale'=>'Подходит семье.', 'compromise'=>'До пляжа 400 м.']), cardPresentation(['inclusions'=>[], 'room_illustrative'=>false]), $callback);
cardAssert(str_contains($family['plain_text'], '1 234,50 € за 4 человека') && str_contains($family['plain_text'], '1 взрослый · 3 ребёнка (5 лет, 5 лет, 0 лет)'), 'Exact decimal party price, child count and duplicate ages survive');
cardAssert(str_contains($family['plain_text'], "Перелёт: Москва — Анталья 10:00–14:00\nПерелёт: Анталья — Москва 15:00–19:00")
    && str_contains($family['plain_text'], 'Почему этот вариант: Подходит семье.') && str_contains($family['plain_text'], 'Учесть: До пляжа 400 м.'), 'Flight legs, rationale and compromise are retained');
cardAssert(!str_contains($family['plain_text'], 'Демо:') && !str_contains($family['plain_text'], 'Фото номера — пример')
    && !str_contains($family['plain_text'], 'страховка') && str_contains($family['plain_text'], 'Актуальность цены не проверена.'), 'Non-demo is still unverified and cannot inherit invented inclusions or photo claims');
foreach ([1=>'ночь',2=>'ночи',5=>'ночей',11=>'ночей',21=>'ночь',22=>'ночи'] as $nights=>$word) {
    $text = TourOfferCardRenderer::render(cardSnapshot(['nights'=>$nights]),cardPresentation(),$callback)['plain_text'];
    cardAssert(str_contains($text, ' · '.$nights.' '.$word."\n"), 'Russian night inflection preserves the supplied duration');
}
foreach ([3=>'человека',5=>'человек'] as $size=>$word) {
    $text = TourOfferCardRenderer::render(cardSnapshot(['adults'=>$size]),cardPresentation(),$callback)['plain_text'];
    cardAssert(str_contains($text, ' ₽ за '.$size.' '.$word."\n"), 'Whole-party price uses the correct person inflection');
}
$single = TourOfferCardRenderer::render(cardSnapshot(['adults'=>1,'amount'=>'999999999.99','currency'=>'USD']),cardPresentation(['destination'=>'']),$callback);
cardAssert(str_contains($single['plain_text'], '999 999 999,99 $ за одного') && !str_starts_with($single['plain_text'], "Delphin Imperial 5★ ·"), 'Largest exact decimal and optional destination do not invent a location');
$crossMonth = TourOfferCardRenderer::render(cardSnapshot(['date_from'=>'2027-05-28','date_to'=>'2027-06-04']),cardPresentation(),$callback);
$crossYear = TourOfferCardRenderer::render(cardSnapshot(['date_from'=>'2027-12-28','date_to'=>'2028-01-04']),cardPresentation(),$callback);
cardAssert(str_contains($crossMonth['plain_text'],'28 мая — 4 июня 2027') && str_contains($crossYear['plain_text'],'28 декабря 2027 — 4 января 2028'), 'Cross-month/year ranges display their exact endpoints');
$escaped = TourOfferCardRenderer::render(cardSnapshot(['hotel'=>'🌴 A&B <Hotel>','departure'=>'<Москва & область>','room'=>'"Suite" <sea>','meal'=>'A&B']),cardPresentation(['destination'=>'Турция & <Лара>','inclusions'=>['<Transfer> & insurance']]),$callback);
cardAssert(str_starts_with($escaped['max']['text'], '<b>🌴 A&amp;B &lt;Hotel&gt;</b> · Турция &amp; &lt;Лара&gt;')
    && str_contains($escaped['max']['text'], '&quot;Suite&quot; &lt;sea&gt;') && !str_contains($escaped['max']['text'], '<Transfer>'), 'External text cannot inject MAX HTML');
cardAssert(cardTelegramText($escaped['telegram']['rich_message']['blocks'][1]['text']) === '🌴 A&B <Hotel> · Турция & <Лара>', 'Telegram plain rich text retains literal external characters');
$expectedTitleUnits = strlen(mb_convert_encoding('🌴 A&B <Hotel>','UTF-16LE','UTF-8')) / 2;
$expectedPriceFrom = strlen(mb_convert_encoding("🌴 A&B <Hotel> · Турция & <Лара>\n",'UTF-16LE','UTF-8')) / 2;
cardAssert($escaped['max_markup'] === [['type'=>'strong','from'=>0,'length'=>(int)$expectedTitleUnits],
    ['type'=>'strong','from'=>(int)$expectedPriceFrom,'length'=>strlen(mb_convert_encoding('238 400 ₽ за двоих','UTF-16LE','UTF-8')) / 2]], 'MAX expected markup uses UTF16 units after an astral hotel character');

foreach (['owner_preview_like_20261021', 'tour_like_0_'.str_repeat('a',24),'tour_like_01_'.str_repeat('a',24),
    'tour_like_1_'.str_repeat('A',24),'tour_like_1_'.str_repeat('a',23),'tour_like_'.str_repeat('1',20).'_'.str_repeat('a',24),$callback."\n"] as $bad) {
    cardReject(cardSnapshot(), cardPresentation(), $bad, 'Preview, malformed or oversized callbacks cannot become a real offer card');
}
foreach ([['amount'=>'0'],['amount'=>238400],['currency'=>'BTC'],['price_basis'=>'person'],['price_verified'=>true],['is_demo'=>'yes'],['child_ages'=>[5,'5']],
    ['date_from'=>'2027-02-30'],['date_to'=>'2027-06-05'],['hotel'=>"Hotel\nInjected"],['meal'=>"bad\xff"]] as $change) {
    cardReject(cardSnapshot($change),cardPresentation(),$callback,'Invalid snapshot cannot be presented as a valid offer');
}
foreach ([['room_illustrative'=>null],['room_illustrative'=>'true'],['destination'=>"Лара\nInjected"],['inclusions'=>['']],['inclusions'=>["A\nB"]],['inclusions'=>'transfer'],['unknown'=>'value']] as $change) {
    cardReject(cardSnapshot(),cardPresentation($change),$callback,'Presentation text and photo honesty are explicit and validated');
}
foreach (['http://www.delphinhotel.com/a.png','https://user:pass@www.delphinhotel.com/a.png','https://www.delphinhotel.com:443/a.png',
    'https://127.0.0.1/a.png','https://localhost/a.png','https://hotel.internal/a.png','https://www.delphinhotel.com/a.png#fragment',
    "https://www.delphinhotel.com/a\nb.png",'https://www.delphinhotel.com/a%0ab.png','https://www.delphinhotel.com\\@evil.com/a.png'] as $url) {
    $presentation = cardPresentation();$presentation['photos'][0]['url']=$url;
    cardReject(cardSnapshot(),$presentation,$callback,'Photo source metadata requires a bounded public HTTPS URL');
}
$presentation = cardPresentation();array_pop($presentation['photos']);cardReject(cardSnapshot(),$presentation,$callback,'Incomplete photo sets cannot impersonate the three-photo format');
$presentation = cardPresentation();$presentation['photos'][2]['role']='cover';cardReject(cardSnapshot(),$presentation,$callback,'Photo roles must retain cover/beach/room order');
$compact = TourOfferCardRenderer::render(cardSnapshot(['rationale'=>'Подходит семье.','compromise'=>'До пляжа 400 м.']),cardPresentation(['layout'=>'compact-v1']),$callback);
cardAssert(str_contains($compact['plain_text'],'С 5 июня 2027 · 7 ночей') && !str_contains($compact['plain_text'],'5–12 июня')
    && str_contains($compact['plain_text'],'Почему этот вариант: Подходит семье.') && str_contains($compact['plain_text'],'Учесть: До пляжа 400 м.'),
    'Compact layout omits a computed return without dropping substantive rationale or compromises');
cardReject(cardSnapshot(['is_demo'=>false]),cardPresentation(['layout'=>'compact-v1']),$callback,'Compact visual samples cannot remove the unverified-price notice from a real offer');
foreach (['other','',false] as $layout) cardReject(cardSnapshot(),cardPresentation(['layout'=>$layout]),$callback,'Only the fixed compact revision is an accepted layout');
foreach ([1,2,3] as $photoCount) {
    $presentation=cardPresentation(['layout'=>'gallery-v1']);$presentation['photos']=array_slice($presentation['photos'],0,$photoCount);
    $gallery=TourOfferCardRenderer::render(cardSnapshot(['is_demo'=>false]),$presentation,$callback);
    cardAssert(count($gallery['telegram']['rich_message']['blocks'][0]['blocks'])===$photoCount,'Automatic gallery keeps its actual photo count');
    cardAssert(str_contains($gallery['plain_text'],'Фотографии отеля.')&&!str_contains($gallery['plain_text'],'Фото номера —'),'Automatic gallery makes no selected-room image assertion');
}
foreach ([[],array_merge(cardPresentation()['photos'],[cardPresentation()['photos'][0]])] as $photos) cardReject(cardSnapshot(),cardPresentation(['layout'=>'gallery-v1','photos'=>$photos]),$callback,'Automatic gallery still requires one to three ordered photos');
echo "TOUR OFFER CARD RENDERER: $checks checks PASSED\n";
