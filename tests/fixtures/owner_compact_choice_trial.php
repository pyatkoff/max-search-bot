<?php
declare(strict_types=1);
define('OWNER_COMPACT_CHOICE_OFFLINE_TEST',true);
define('OWNER_EXPLICIT_PHOTO_FIXTURE_LIBRARY',true);
require_once __DIR__.'/owner_photo_choice_explicit_spec.php';
require_once dirname(__DIR__,2).'/tools/owner_tour_compact_choice_trial.php';
$spec=ownerCompactChoiceSpec();$callback='tour_like_2001_'.str_repeat('b',24);
$old=ownerPhotoChoiceCard($callback,ownerLiveQuoteSpec());$card=ownerPhotoChoiceCard($callback,$spec);
photoChoiceAssert(str_contains($card['plain_text'],'📅 С 5 июня 2027 · 7 ночей')
    && !str_contains($card['plain_text'],'5–12') && !str_contains($card['plain_text'],'UTC'), 'Compact date uses only departure and nights without a computed return or technical timestamp');
photoChoiceAssert($card['snapshot']['is_demo'] && !$card['snapshot']['price_verified']
    && str_contains($card['plain_text'],'Демо: цена и условия — пример.')
    && str_contains($card['plain_text'],'Фото номера — пример интерьера.'), 'Historical visual sample cannot become a fresh verified quote or a guaranteed room photo');
photoChoiceAssert(mb_strlen($card['plain_text'])<mb_strlen($old['plain_text'])*0.7
    && substr_count($card['telegram']['rich_message']['blocks'][4]['text'],"\n")===1,
    'The actual native caption loses the oversized technical paragraph and has a two-line footer');
photoChoiceAssert(html_entity_decode(strip_tags($card['max']['text']),ENT_QUOTES|ENT_HTML5,'UTF-8')===$card['plain_text']
    && count($card['max_markup'])===2 && count($card['telegram']['rich_message']['blocks'][0]['blocks'])===3,
    'Both native messages share exact compact conditions, two bold spans and three photographs');
$sha=str_repeat('a',40);
photoChoiceAssert(ownerCompactChoiceArguments(['--expected-sha='.$sha,'--confirm-owner-photo-choice','--revision=compact-v1'])===$sha,'Only the fixed compact revision invocation is accepted');
foreach ([[],['--expected-sha='.$sha,'--confirm-owner-photo-choice'],
    ['--expected-sha='.$sha,'--confirm-owner-photo-choice','--revision=other'],
    ['--expected-sha='.$sha,'--confirm-owner-photo-choice','--revision=compact-v1','--recipient=1'],
    ['--expected-sha='.$sha,'--confirm-owner-photo-choice','--revision=compact-v1','--revision=compact-v1']] as $args) {
    photoChoiceAssert(ownerCompactChoiceArguments($args)===null,'Missing, duplicate, arbitrary revision or recipient cannot authorize creation');
}
$bad=$spec;$bad['snapshot']['is_demo']=false;
[$out,$calls,$directory,$writes]=explicitRun($bad);
photoChoiceAssert(!$out['ok'] && $calls===[] && $writes===[],'Compact historical sample cannot silently claim real current conditions');
[$out,$calls,$directory,$writes]=explicitRun($spec);
photoChoiceAssert($out['ok'] && photoChoiceSends($calls)===2 && count($writes)===4,'New compact snapshots deliver through the actual native proof and canonical binding runner');
[$again,$calls,$_,$writes]=explicitRun($spec,$directory);
photoChoiceAssert($again['ok'] && photoChoiceSends($calls)===0
    && count(array_filter($writes,static fn($w)=>$w[0]==='prepare'))===0,'Known compact messages recover without another snapshot or creation');
photoChoiceAssert(!file_exists($directory.'/owner-live-quote-20261021-grand-park-telegram.json')
    && !file_exists($directory.'/owner-photo-choice-20261021-telegram.json'),'Compact revision never opens or renames either historical once receipt');
$assetDirectory=sys_get_temp_dir().'/owner-compact-assets-'.bin2hex(random_bytes(8));mkdir($assetDirectory,0700);$directories[]=$assetDirectory;
foreach ([0,1,2] as $index) {
    $image=ownerCompactChoiceDownload($index,$assetDirectory);
    photoChoiceAssert(is_array($image),'Only verified fixed assets are copied into the private upload directory');
    $size=getimagesize($image['path']);
    photoChoiceAssert($size[0]*3===$size[1]*4 && $size[0]>=800 && $image['mime']==='image/jpeg'
        && (fileperms($image['path'])&0777)===0600,'Every real native input has the same 4:3 framing and useful resolution');
    unlink($image['path']);
}
photoChoiceAssert(ownerCompactChoiceDownload(3,$assetDirectory)===null,'Arbitrary asset indexes cannot enter the uploader');
foreach ($directories as $directory) if (is_dir($directory)) {foreach (glob($directory.'/*')?:[] as $path) unlink($path);foreach(glob($directory.'/.owner-preview-image-*')?:[] as $path)unlink($path);rmdir($directory);}
echo "OWNER COMPACT PHOTO CHOICE: $checks checks PASSED\n";
