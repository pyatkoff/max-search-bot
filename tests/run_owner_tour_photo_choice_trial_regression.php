<?php
declare(strict_types=1);
define('OWNER_PHOTO_CHOICE_OFFLINE_TEST', true);
require_once dirname(__DIR__).'/tools/owner_tour_photo_choice_trial.php';
$checks = 0; $directories = [];
function photoChoiceAssert(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
function photoChoiceRow(string $channel): array {
    return $channel === 'telegram' ? ['id'=>'1','project_key'=>'anytour','channel'=>'telegram','status'=>'manager','external_user_id'=>'881001','conversation_chat_id'=>'881001','channel_chat_id'=>'881001','message_sender_id'=>'881001','external_message_id'=>'177']
        : ['id'=>'2','project_key'=>'anytour','channel'=>'max','status'=>'manager','external_user_id'=>'772001','conversation_chat_id'=>'772001','channel_chat_id'=>'772001','message_sender_id'=>'772001','external_message_id'=>'mid.owner_inbound','original_display_name'=>'Pavel'];
}
function photoChoiceReceipt(string $directory, string $channel): array { return json_decode(file_get_contents($directory.'/owner-photo-choice-20261021-'.$channel.'.json'), true); }
function photoChoiceNative(string $channel, string $callback): array {
    $body = ownerPhotoChoiceBody($channel, $callback);
    if ($channel === 'telegram') {
        foreach ([0,1,2] as $index) $body['rich_message']['blocks'][0]['blocks'][$index]['photo'] = [['file_id'=>'native-photo-'.$index,'width'=>1100,'height'=>825]];
        return ['message_id'=>200,'chat'=>['id'=>881001,'type'=>'private'],'from'=>['id'=>881099,'is_bot'=>true], 'rich_message'=>$body['rich_message'],'reply_markup'=>$body['reply_markup']];
    }
    $card = ownerPhotoChoiceCard($callback); $images = [];
    foreach ([0,1,2] as $index) $images[] = ['type'=>'image','payload'=>['photo_id'=>500+$index,'url'=>'https://native.example/photo'.$index]];
    return ['body'=>['mid'=>'mid.photo_choice','text'=>$card['plain_text'],'markup'=>array_reverse($card['max_markup']), 'attachments'=>array_merge($images, $body['attachments'])],
        'sender'=>['user_id'=>772099],'recipient'=>['user_id'=>772001,'chat_id'=>-770001,'chat_type'=>'dialog']];
}
function photoChoiceSends(array $calls): int { return count(array_filter($calls, static fn($c)=>($c[0] === 'telegram' && $c[1] === 'sendRichMessage') || ($c[0] === 'max' && $c[1] === 'POST' && $c[2] === '/messages'))); }
function photoChoiceRun(string $failure = '', ?string $directory = null, array $changes = [], string $imageVersion = ''): array {
    global $directories;
    if ($directory === null) { $directory = sys_get_temp_dir().'/owner-photo-choice-'.bin2hex(random_bytes(8)); $directories[] = $directory; }
    $calls = []; $writes = [];
    $tg = static function($method, $body, $token, $images) use (&$calls, $directory, $failure): array {
        $calls[] = ['telegram',$method];
        if ($method === 'getMe') return ['data'=>['id'=>881099,'username'=>$failure === 'bot' ? 'other_bot' : 'anyru_bot','is_bot'=>true]];
        if ($method === 'getChat') return ['data'=>['id'=>881001,'type'=>$failure === 'chat' ? 'group' : 'private']];
        photoChoiceAssert($method === 'sendRichMessage' && $body['chat_id'] === '881001' && count($images) === 3, 'Three uploaded photos target only the independently proved Telegram owner');
        $record = photoChoiceReceipt($directory, 'telegram');
        photoChoiceAssert($record['post_reserved'] === true && $record['state'] === 'post_reserved' && $record['offer_id'] === 1001, 'Snapshot and durable creation reservation precede Telegram POST');
        photoChoiceAssert($record['photo_sha256'] === array_map(static fn($image)=>hash_file('sha256',$image['path']),$images), 'Telegram uploads the exact bytes persisted in the once receipt');
        photoChoiceAssert($body['reply_markup']['inline_keyboard'][0][0]['callback_data'] === $record['callback_data'], 'Telegram callback is bound to the prepared offer');
        if ($failure === 'tg_throw') throw new RuntimeException('PRIVATE_TOKEN');
        if (in_array($failure, ['tg_timeout','tg_reject'], true)) return ['data'=>null,'diagnostic'=>['http_status'=>$failure === 'tg_reject' ? 400 : 0,'error_category'=>'transport']];
        $native = photoChoiceNative('telegram',$record['callback_data']);
        if ($failure === 'tg_text') $native['rich_message']['blocks'][3]['text'] = 'Changed dates';
        if ($failure === 'tg_owner') $native['chat']['id'] = 881002;
        if ($failure === 'tg_button') $native['reply_markup']['inline_keyboard'][0][0]['callback_data'] = 'owner_preview_like_20261021';
        if ($failure === 'tg_photos') array_pop($native['rich_message']['blocks'][0]['blocks']);
        return ['data'=>$native,'diagnostic'=>['http_status'=>200,'curl_code'=>0,'error_category'=>'none']];
    };
    $max = static function($method,$path,$query,$body,$token) use (&$calls,$directory,$failure): array {
        $calls[] = ['max',$method,$path]; $diagnostic = ['http_status'=>200,'curl_code'=>0,'error_category'=>'none','api_code'=>null];
        if ($path === '/messages/mid.owner_inbound') return ['data'=>['body'=>['mid'=>'mid.owner_inbound'],'sender'=>['user_id'=>772001],
            'recipient'=>['user_id'=>772099,'chat_id'=>-770001,'chat_type'=>$failure === 'max_identity' ? 'chat' : 'dialog']],'diagnostic'=>$diagnostic];
        if ($path === '/uploads') return ['data'=>['url'=>$failure === 'upload_host' ? 'https://other.example/uploadImage?token=private' : 'https://iu.oneme.ru/uploadImage?token=private'],'diagnostic'=>$diagnostic];
        $record = photoChoiceReceipt($directory,'max');
        if ($method === 'GET') {
            photoChoiceAssert($path === '/messages/mid.photo_choice', 'Known MAX MID allows only its exact readback');
            $native = photoChoiceNative('max',$record['callback_data']);
            if ($failure === 'max_read') $native['body']['text'] = 'Changed conditions';
            if ($failure === 'max_mid') $native['body']['mid'] = 'mid.another';
            if ($failure === 'max_button') $native['body']['attachments'][3]['payload']['buttons'][0][0]['payload'] = 'owner_preview_like_20261021';
            if ($failure === 'max_photos') array_shift($native['body']['attachments']);
            if ($failure === 'max_owner') $native['recipient']['user_id'] = 772002;
            return ['data'=>$native,'diagnostic'=>$diagnostic];
        }
        photoChoiceAssert($method === 'POST' && $path === '/messages' && $query === ['user_id'=>'772001'], 'MAX sends only to the separately proved owner');
        photoChoiceAssert($record['post_reserved'] && $record['state'] === 'post_reserved' && $record['offer_id'] === 1002 && count($record['image_tokens']) === 3, 'MAX source hashes, tokens, callback and permanent reservation are durable before POST');
        photoChoiceAssert($body === ownerPhotoChoiceBody('max',$record['callback_data'],$record['image_tokens']), 'MAX message contains exactly the three cached tokens and prepared callback');
        if ($failure === 'max_timeout') return ['data'=>null,'diagnostic'=>['http_status'=>0,'error_category'=>'transport']];
        return ['data'=>['message'=>['body'=>['mid'=>'mid.photo_choice']]],'diagnostic'=>$diagnostic];
    };
    $targets = [];
    foreach (['telegram','max'] as $channel) { $row = array_replace(photoChoiceRow($channel),$changes[$channel] ?? []); $targets[$channel] = static fn()=>$row; }
    $prepare = static function(int $conversationId,array $snapshot) use (&$writes,&$calls,$failure): array {
        $writes[] = ['prepare',$conversationId];
        photoChoiceAssert(in_array(['telegram','getMe'],$calls,true) && in_array(['telegram','getChat'],$calls,true) && in_array(['max','GET','/messages/mid.owner_inbound'],$calls,true), 'Both native private identities precede every snapshot');
        photoChoiceAssert($snapshot === ownerPhotoChoiceSnapshot() && $snapshot['is_demo'] && !$snapshot['price_verified'] && $snapshot['flights'] === [], 'Snapshot stores exact demo conditions without fabricated inclusions/flights');
        if ($failure === 'prepare') return ['ok'=>false];
        $id = 1000 + $conversationId; return ['ok'=>true,'offer_id'=>$id,'callback_data'=>'tour_like_'.$id.'_'.str_repeat('a',24)];
    };
    $delivered = static function(int $offerId,string $mid,string $chat) use (&$writes,&$calls,$failure): array {
        $writes[] = ['delivered',$offerId,$mid,$chat];
        photoChoiceAssert(($offerId === 1001 && $mid === '200' && $chat === '881001') || ($offerId === 1002 && $mid === 'mid.photo_choice' && $chat === '-770001'), 'Delivery stores only the exact prepared offer/native message/private chat tuple');
        if ($offerId === 1002) photoChoiceAssert(in_array(['max','GET','/messages/mid.photo_choice'],$calls,true), 'MAX must read back the native photo card before delivery binding');
        return ['ok'=>!($failure === 'record' && $offerId === 1001)];
    };
    $download = static function(int $index,string $dir) use (&$calls,$failure,$imageVersion): ?array {
        $calls[] = ['download',$index];
        if ($failure === 'download' && $index === 1) return null;
        $path = $dir.'/.owner-preview-image-'.$index.'-'.bin2hex(random_bytes(8)).'.tmp';
        file_put_contents($path,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aafEAAAAASUVORK5CYII=').$index.$imageVersion); chmod($path,0600);
        return ['path'=>$path,'mime'=>'image/png'];
    };
    $upload = static function(string $url,array $image) use (&$calls,$directory,$failure): array {
        preg_match('/image-([012])-/',basename($image['path']),$m); $index = (int)$m[1]; $calls[] = ['upload',$index];
        $record = photoChoiceReceipt($directory,'max');
        photoChoiceAssert($record['photo_sha256'][$index] === hash_file('sha256',$image['path']), 'MAX upload is bound to persisted input bytes');
        if ($failure === 'upload' && $index === 1) return ['data'=>null,'diagnostic'=>['http_status'=>0,'error_category'=>'transport']];
        return ['data'=>['photos'=>[['token'=>'photo-token-'.$index]]],'diagnostic'=>['http_status'=>200,'curl_code'=>0,'error_category'=>'none']];
    };
    $sha = str_repeat('a',40);
    $out = ownerPhotoChoiceRun($sha,$sha,'anytour',['telegram'=>'PRIVATE_TG_TOKEN','max'=>'PRIVATE_MAX_TOKEN'],$targets,
        ['telegram'=>$tg,'max'=>$max],$directory,$prepare,$delivered,['download'=>$download,'upload'=>$upload]);
    photoChoiceAssert(!str_contains(json_encode($out),'PRIVATE_') && !str_contains(json_encode($out),'881001') && !str_contains(json_encode($out),'tour_like_') && !str_contains(json_encode($out),'photo-token-'), 'Public result omits credentials, owner IDs, callback capabilities and image tokens');
    return [$out,$calls,$directory,$writes];
}
if (!defined('OWNER_PHOTO_CHOICE_FIXTURE_LIBRARY')) {
$sha = str_repeat('a',40);
photoChoiceAssert(ownerPhotoChoiceArguments(['--expected-sha='.$sha,'--confirm-owner-photo-choice']) === $sha, 'Fixed photo trial activation parses');
foreach ([[],['--expected-sha='.$sha,'--confirm-owner-photo-choice','--chat-id=1'],['--expected-sha='.$sha,'--confirm-owner-photo-choice','--confirm-owner-choice'],['--expected-sha='.$sha,'--confirm-owner-send']] as $args)
    photoChoiceAssert(ownerPhotoChoiceArguments($args) === null, 'No arbitrary recipients or mixed modes');
$callback = 'tour_like_1001_'.str_repeat('a',24);
foreach (['telegram','max'] as $channel) {
    $identity = $channel === 'telegram' ? ['user'=>'881001','bot'=>'881099','chat'=>'881001'] : ['user'=>'772001','bot'=>'772099','chat'=>'-770001'];
    $mid = $channel === 'telegram' ? '200' : 'mid.photo_choice'; $native = photoChoiceNative($channel,$callback);
    photoChoiceAssert(ownerPhotoChoiceMatches($channel,$native,$mid,$identity,$callback), 'Exact native photo, formatting and callback verifies');
    photoChoiceAssert(!ownerPhotoChoiceMatches($channel,$native,'other-mid',$identity,$callback), 'Foreign native MID cannot verify');
    photoChoiceAssert(!ownerPhotoChoiceMatches($channel,$native,$mid,$identity,'tour_like_1001_'.str_repeat('b',24)), 'Foreign callback capability cannot verify');
    if ($channel === 'max') {
        $native['body']['attachments'][0]['payload'] = [];
        photoChoiceAssert(!ownerPhotoChoiceMatches($channel,$native,$mid,$identity,$callback), 'Empty MAX image payload is not native photo evidence');
    }
}
[$out,$calls,$directory,$writes] = photoChoiceRun();
photoChoiceAssert($out['ok'] && photoChoiceSends($calls) === 2 && count($out['cards']) === 2, 'Exactly two private photo cards are delivered');
photoChoiceAssert(count(array_filter($calls,static fn($c)=>$c[0] === 'download')) === 3 && count(array_filter($calls,static fn($c)=>$c[0] === 'upload')) === 3, 'Three source photos are downloaded once and uploaded to MAX once');
photoChoiceAssert(count(array_filter($writes,static fn($w)=>$w[0] === 'prepare')) === 2 && count(array_filter($writes,static fn($w)=>$w[0] === 'delivered')) === 2, 'Each card has its own exact snapshot and native binding');
photoChoiceAssert(photoChoiceReceipt($directory,'telegram')['photo_sha256'] === photoChoiceReceipt($directory,'max')['photo_sha256'], 'Both channels use identical ordered photo bytes');
[$again,$calls,$_,$writes] = photoChoiceRun('', $directory);
photoChoiceAssert($again['ok'] && photoChoiceSends($calls) === 0 && count(array_filter($calls,static fn($c)=>in_array($c[0],['download','upload'],true))) === 0 && count(array_filter($writes,static fn($w)=>$w[0] === 'prepare')) === 0, 'Known receipts recover verification only, without downloads, uploads, snapshots or duplicate cards');
foreach (['tg_timeout','tg_reject','tg_throw','tg_text','tg_owner','tg_button','tg_photos','max_timeout'] as $failure) {
    [$out,$calls,$directory] = photoChoiceRun($failure);
    photoChoiceAssert(!$out['ok'] && photoChoiceSends($calls) === ($failure === 'max_timeout' ? 2 : 1), 'Unknown/rejected/malformed result stops card delivery');
    [$again,$calls,$_,$writes] = photoChoiceRun('', $directory);
    photoChoiceAssert(!$again['ok'] && photoChoiceSends($calls) === 0 && count(array_filter($writes,static fn($w)=>$w[0] === 'prepare')) === 0, 'Unknown or cached mismatched result cannot repeat snapshot or POST');
}
foreach (['record','max_read','max_mid','max_button','max_photos','max_owner'] as $failure) {
    [$out,$calls,$directory] = photoChoiceRun($failure); photoChoiceAssert(!$out['ok'], 'Failed native proof or DB binding is not delivered');
    [$again,$calls,$_,$writes] = photoChoiceRun('', $directory);
    photoChoiceAssert($again['ok'] && photoChoiceSends($calls) === ($failure === 'record' ? 1 : 0), 'Known MID recovers proof/binding without repeating that card');
}
[$out,$calls,$directory] = photoChoiceRun('upload');
photoChoiceAssert(!$out['ok'] && photoChoiceSends($calls) === 1 && count(photoChoiceReceipt($directory,'max')['image_tokens']) === 1, 'A partial upload preserves the completed token before failure');
[$again,$calls] = photoChoiceRun('', $directory);
photoChoiceAssert($again['ok'] && !in_array(['upload',0],$calls,true) && in_array(['upload',1],$calls,true) && in_array(['upload',2],$calls,true) && photoChoiceSends($calls) === 1, 'Retry reuses the source-bound token and creates only the unsent MAX card');
[$out,$calls,$directory] = photoChoiceRun('upload');
[$again,$calls,$_,$writes] = photoChoiceRun('', $directory, [], 'changed-source');
photoChoiceAssert(!$again['ok'] && $again['state'] === 'source_changed' && photoChoiceSends($calls) === 0 && $writes === [] && count(array_filter($calls,static fn($c)=>$c[0] === 'upload')) === 0, 'Changed source bytes cannot reuse cached uploads or old sent-card inputs');
foreach (['bot','chat','max_identity','prepare','download','upload_host'] as $failure) {
    [$out,$calls,$_,$writes] = photoChoiceRun($failure); photoChoiceAssert(!$out['ok'], 'Invalid identity/source/prepare/upload origin cannot complete');
    if (in_array($failure,['bot','chat','max_identity','download'],true)) photoChoiceAssert(photoChoiceSends($calls) === 0 && $writes === [], 'Preflight failure precedes all business writes and card sends');
}
foreach (['telegram','max'] as $channel) { [$out,$calls,$_,$writes] = photoChoiceRun('',null,[$channel=>['channel'=>'website']]); photoChoiceAssert(!$out['ok'] && photoChoiceSends($calls) === 0 && $writes === [], 'Channel cannot be inferred or substituted'); }
foreach (['json','flag','callback','posthash','native_mid'] as $corruption) {
    [$out,$calls,$directory] = photoChoiceRun(); $path = $directory.'/owner-photo-choice-20261021-max.json'; $record = photoChoiceReceipt($directory,'max');
    if ($corruption === 'flag') $record['post_reserved'] = false;
    elseif ($corruption === 'callback') $record['callback_data'] = 'tour_like_999_'.str_repeat('b',24);
    elseif ($corruption === 'posthash') $record['post_sha256'] = str_repeat('0',64);
    elseif ($corruption === 'native_mid') $record['state'] = 'post_reserved';
    file_put_contents($path,$corruption === 'json' ? '{' : json_encode($record));
    [$again,$calls,$_,$writes] = photoChoiceRun('', $directory);
    photoChoiceAssert(!$again['ok'] && photoChoiceSends($calls) === 0 && $writes === [], 'Corrupt later receipt cannot authorize any prepare or send');
}
[$out,$calls,$directory] = photoChoiceRun(); $path = $directory.'/owner-photo-choice-20261021-max.json'; unlink($path); symlink('/does/not/exist',$path);
[$again,$calls,$_,$writes] = photoChoiceRun('', $directory);
photoChoiceAssert(!$again['ok'] && photoChoiceSends($calls) === 0 && $writes === [], 'Receipt symlink cannot redirect writes or authorize sends'); unlink($path);
foreach ($directories as $directory) if (is_dir($directory)) { foreach (glob($directory.'/*') ?: [] as $path) unlink($path); foreach (glob($directory.'/.owner-preview-image-*') ?: [] as $path) unlink($path); rmdir($directory); }
echo "OWNER TOUR PHOTO CHOICE: $checks checks PASSED\n";
$fixtureOutput = []; $fixtureCode = 0;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/fixtures/owner_photo_choice_explicit_spec.php').' 2>&1', $fixtureOutput, $fixtureCode);
echo implode(PHP_EOL, $fixtureOutput).PHP_EOL;
if ($fixtureCode !== 0) throw new RuntimeException('Explicit owner photo-choice specification regression failed');
$liveOutput = []; $liveCode = 0;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/fixtures/owner_live_quote_trial.php').' 2>&1', $liveOutput, $liveCode);
echo implode(PHP_EOL, $liveOutput).PHP_EOL;
if ($liveCode !== 0) throw new RuntimeException('Fixed owner live-quote regression failed');
$compactOutput = []; $compactCode = 0;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/fixtures/owner_compact_choice_trial.php').' 2>&1', $compactOutput, $compactCode);
echo implode(PHP_EOL, $compactOutput).PHP_EOL;
if ($compactCode !== 0) throw new RuntimeException('Compact owner card regression failed');
}
