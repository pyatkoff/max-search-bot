<?php
declare(strict_types=1);
define('OWNER_SELECTION_EMPHASIS',true);
define('OWNER_SELECTION_FIXTURE_LIBRARY',true);
require_once __DIR__.'/run_owner_tour_selection_preview_regression.php';

$sha=str_repeat('a',40);
trioAssert(array_keys(ownerSelectionHotels())===['imperial'],'Emphasis preview contains only the fixed Imperial example');
trioAssert(ownerSelectionDemoPrefix()==='owner-emphasis-20261021','Emphasis has its own permanent receipt namespace');
trioAssert(ownerSelectionArguments(['--expected-sha='.$sha,'--confirm-owner-emphasis'])===$sha,'Exact fixed emphasis activation is accepted');
foreach ([[],['--expected-sha='.$sha,'--confirm-owner-trio'],
    ['--expected-sha='.$sha,'--confirm-owner-emphasis','--confirm-owner-trio'],
    ['--expected-sha='.$sha,'--confirm-owner-emphasis','--chat-id=1']] as $args) {
    trioAssert(ownerSelectionArguments($args)===null,'Old mode, mixed confirmation and arbitrary recipient input cannot activate emphasis');
}

$conditions="📅 5–12 июня 2027 · 7 ночей\n✈️ Из Москвы · 2 взрослых\n🛏️ Улучшенный номер\n🍽️ Ультра всё включено\nПерелёт, трансфер и страховка включены.";
$footer="Демо: даты, цена и условия — пример.\nФото номера — пример интерьера отеля.";
$hotel=ownerSelectionHotels()['imperial'];
trioAssert(ownerSelectionConditions('imperial')===$conditions,'Only the four agreed condition lines gain restrained emoji');
trioAssert(ownerSelectionFooter()===$footer,'Demo and illustrative room-photo caveats stay explicit');
$text=ownerSelectionMaxText('imperial');
trioAssert($text===$hotel['title']." · Турция, Лара\n".$hotel['price']."\n".$conditions."\n\n".$footer,'MAX preserves price-first facts, exact conditions and footer');
$telegram=ownerSelectionTelegramBody('imperial');
trioAssert($telegram['rich_message']['blocks'][0]['type']==='slideshow'&&count($telegram['rich_message']['blocks'][0]['blocks'])===3,'Telegram keeps the proven three-photo native carousel');
trioAssert(telegramOwnerPreviewText($telegram['rich_message']['blocks'][1]['text'])===[[true,$hotel['title']],[false,' · Турция, Лара']]
    &&telegramOwnerPreviewText($telegram['rich_message']['blocks'][2]['text'])===[[true,$hotel['price']]],'Only hotel and total price retain bold emphasis');
trioAssert($telegram['rich_message']['blocks'][3]['text']===$conditions
    &&$telegram['rich_message']['blocks'][4]===['type'=>'footer','text'=>$footer],'Telegram condition text and gray footer preserve all exact facts');
trioAssert($telegram['reply_markup']===['inline_keyboard'=>[[['text'=>'Нравится','callback_data'=>'owner_preview_like_20261021']]]],'The single preview-like button is unchanged');
trioAssert(telegramOwnerPreviewMatches(trioNative('telegram','imperial'),'881001','881099','slideshow',$telegram),'Native Telegram emphasis response verifies');

$identity=['user'=>'772001','bot'=>'772099','chat'=>'-770001'];
$native=trioNative('max','imperial');
trioAssert(ownerSelectionMaxMatches($native,'mid.trio_imperial',$identity,'imperial'),'Native MAX emphasis response verifies with reordered strong-span keys');
foreach ([$hotel['title'],$hotel['price']] as $bold) {
    $prefix=substr($text,0,strpos($text,$bold));
    $from=(int)(strlen(mb_convert_encoding($prefix,'UTF-16LE','UTF-8'))/2);
    $length=(int)(strlen(mb_convert_encoding($bold,'UTF-16LE','UTF-8'))/2);
    trioAssert(preg_match('/[^\x{0000}-\x{FFFF}]/u',$prefix)===0,'All astral emoji follow both bold spans');
    $found=false;
    foreach ($native['body']['markup'] as $span) if ($span['from']===$from&&$span['length']===$length&&$span['type']==='strong') $found=true;
    trioAssert($found,'Native bold offsets remain exact UTF-16 positions despite later emoji');
}

[$out,$calls,$directory,$downloads,$uploads]=trioRun();
trioAssert($out['ok']&&array_keys($out['cards'])===['telegram-imperial','max-imperial']
    &&trioSends($calls)===2&&count($downloads)===3&&$uploads===3,'Exactly two native emphasis cards share three real downloads and three MAX uploads');
trioAssert(array_slice($calls,0,3)===[['telegram','getMe'],['telegram','getChat'],['max','GET','/messages/mid.owner_inbound']],'Both channel identities are independently proved before a message is created');
$receipts=glob($directory.'/*.json');sort($receipts);
trioAssert($receipts===[$directory.'/owner-emphasis-20261021-max-imperial.json',$directory.'/owner-emphasis-20261021-telegram-imperial.json'],'Only the two separate fixed emphasis receipts are created');
foreach ($receipts as $path) {
    $record=json_decode(file_get_contents($path),true);
    trioAssert($record['state']==='verified'&&$record['post_reserved']===true&&(fileperms($path)&0777)===0600,'Each new card has a verified private permanent once receipt');
}
[$again,$calls,$_,$downloads,$uploads]=trioRun('',$directory);
trioAssert($again['ok']&&trioSends($calls)===0&&$downloads===[]&&$uploads===0,'Verified emphasis cards never resend or repeat media transfers');

foreach (['tg_timeout','tg_reject','tg_throw','max_timeout'] as $failure) {
    [$failed,$calls,$directory]=trioRun($failure);
    trioAssert(!$failed['ok']&&trioSends($calls)===($failure==='max_timeout'?2:1),'Uncertain first Telegram creation stops MAX; uncertain MAX creation stops the batch');
    [$again,$calls,$_,$downloads,$uploads]=trioRun('',$directory);
    trioAssert(!$again['ok']&&trioSends($calls)===0&&$downloads===[]&&$uploads===0,'Unknown or rejected emphasis sends cannot be repeated on explicit rerun');
}
[$failed,$calls,$_,$downloads,$uploads]=trioRun('image');
trioAssert(!$failed['ok']&&trioSends($calls)===0&&$uploads===0,'Unavailable real image prevents both emphasis cards');

foreach ($directories as $directory) if (is_dir($directory)) {
    foreach (glob($directory.'/*')?:[] as $path) unlink($path);
    rmdir($directory);
}
echo "OWNER TOUR CARD EMPHASIS PREVIEW: $checks checks PASSED\n";
