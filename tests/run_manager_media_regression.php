<?php
require_once __DIR__ . '/../integrations/MaxIncomingAdapter.php';
require_once __DIR__ . '/../integrations/TelegramIncomingAdapter.php';
require_once __DIR__ . '/../services/ConversationRecorder.php';
require_once __DIR__ . '/../services/ManagerOutboundService.php';
require_once __DIR__ . '/../services/ManagerMessageMediaService.php';
require_once __DIR__ . '/../services/MaxInboundMediaArchiveService.php';

$failed = 0;
function mediaCheck(string $name, $actual, $expected): void {
    global $failed;
    $ok = $actual === $expected;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . PHP_EOL;
    if (!$ok) {
        echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
        echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $failed++;
    }
}
function maxMediaStream(string $bytes) {
    $stream=tmpfile();
    if($stream===false)return null;
    fwrite($stream,$bytes);rewind($stream);return $stream;
}

$update = ['update_type'=>'message_created','message'=>['sender'=>['user_id'=>123,'first_name'=>'Тест'],'body'=>['mid'=>'mid.media.1','text'=>'Посмотрите варианты','attachments'=>[
    ['type'=>'image','payload'=>['url'=>'https://cdn.example/photo.jpg','token'=>'img.1']],
    ['type'=>'video','payload'=>['url'=>'https://cdn.example/video.mp4','token'=>'vid.1']],
    ['type'=>'audio','payload'=>['url'=>'https://cdn.example/audio.mp3','token'=>'aud.1'],'transcription'=>'голос'],
    ['type'=>'file','payload'=>['url'=>'https://cdn.example/offer.pdf','token'=>'file.1','name'=>'offer.pdf']],
    ['type'=>'inline_keyboard','payload'=>['buttons'=>[]]],
]]]];
$incoming = MaxIncomingAdapter::fromUpdate($update);
mediaCheck('media update remains a message', $incoming['type'] ?? null, 'message');
mediaCheck('media update keeps caption text', $incoming['text'] ?? null, 'Посмотрите варианты');
mediaCheck('four supported attachments normalized', count($incoming['attachments'] ?? []), 4);
mediaCheck('image url retained', $incoming['attachments'][0]['url'] ?? null, 'https://cdn.example/photo.jpg');
mediaCheck('MAX provider is explicit', $incoming['attachments'][0]['provider'] ?? null, 'max');
mediaCheck('video token retained', $incoming['attachments'][1]['token'] ?? null, 'vid.1');
mediaCheck('audio transcription retained', $incoming['attachments'][2]['transcription'] ?? null, 'голос');
mediaCheck('file name retained', $incoming['attachments'][3]['name'] ?? null, 'offer.pdf');
$replyUpdate=['update_type'=>'message_created','message'=>['sender'=>['user_id'=>123],'body'=>['mid'=>'mid.reply','text'=>'Этот вариант'],'link'=>['type'=>'reply','mid'=>'mid.offer','sender'=>['name'=>'Менеджер'],'message'=>['body'=>['text'=>'Отель A']]]]];
$replyIncoming=MaxIncomingAdapter::fromUpdate($replyUpdate);
mediaCheck('MAX reply context type retained',$replyIncoming['linked_message']['type']??null,'reply');
mediaCheck('MAX reply context mid retained',$replyIncoming['linked_message']['mid']??null,'mid.offer');
$forwardUpdate=['update_type'=>'message_created','message'=>['sender'=>['user_id'=>123],'body'=>['mid'=>'mid.forward','text'=>''],'link'=>['type'=>'forward','mid'=>'mid.source','message'=>['body'=>['attachments'=>[['type'=>'video','payload'=>['token'=>'video-token']]]]]]]];
$forwardIncoming=MaxIncomingAdapter::fromUpdate($forwardUpdate);
mediaCheck('MAX forward context retained',$forwardIncoming['linked_message']['type']??null,'forward');
mediaCheck('forwarded MAX video retained',$forwardIncoming['attachments'][0]['type']??null,'video');
$tgReply=['message'=>['message_id'=>11,'from'=>['id'=>7,'first_name'=>'Клиент'],'text'=>'Этот нравится','reply_to_message'=>['message_id'=>10,'from'=>['id'=>99,'first_name'=>'Менеджер'],'caption'=>'Отель A','photo'=>[['file_id'=>'p1','width'=>100,'height'=>100]]]]];
$tgIncoming=TelegramIncomingAdapter::fromUpdate($tgReply);
mediaCheck('Telegram reply context retained',$tgIncoming['linked_message']['type']??null,'reply');
mediaCheck('Telegram reply target retained',$tgIncoming['linked_message']['mid']??null,'10');
mediaCheck('Telegram replied photo summarized',$tgIncoming['linked_message']['attachments'][0]['type']??null,'image');
mediaCheck('forwarded MAX video token retained',$forwardIncoming['attachments'][0]['token']??null,'video-token');
$linkedProjection=ManagerMessageMediaService::hydrate([['id'=>991,'direction'=>'inbound','sender_type'=>'customer','text'=>'Этот вариант']]);
$mediaServiceSource=(string)file_get_contents(__DIR__.'/../services/ManagerMessageMediaService.php');
mediaCheck('workspace projection retains linked attachment summaries',strpos($mediaServiceSource,"if(\$summary)\$out['attachments']=\$summary;")!==false,true);

$nestedUpdate=['update_type'=>'message_created','message'=>['sender'=>['user_id'=>123],'body'=>['mid'=>'mid.media.2','attachments'=>[
    ['type'=>'image','payload'=>['photos'=>[['token'=>'nested-token','url'=>'https://cdn.example/nested-photo.jpg']]]],
]]]];
$nested=MaxIncomingAdapter::fromUpdate($nestedUpdate);
mediaCheck('nested MAX photo token is retained', $nested['attachments'][0]['token'] ?? null, 'nested-token');
mediaCheck('nested MAX photo url is retained', $nested['attachments'][0]['url'] ?? null, 'https://cdn.example/nested-photo.jpg');
mediaCheck('media-only preview is useful', ConversationRecorder::attachmentPreview([['type'=>'image'],['type'=>'audio']]), '📎 Фото, Аудио');
mediaCheck('manager synthetic image label is recognized', ManagerMessageMediaService::isSyntheticAttachmentPreview(['direction'=>'outbound','sender_type'=>'manager','text'=>'📎 Фото'], [['type'=>'image','url'=>'media-file.php?id=x']]), true);
mediaCheck('manager real caption is preserved', ManagerMessageMediaService::isSyntheticAttachmentPreview(['direction'=>'outbound','sender_type'=>'manager','text'=>'Посмотрите этот отель'], [['type'=>'image','url'=>'media-file.php?id=x']]), false);
mediaCheck('customer attachment preview is not suppressed', ManagerMessageMediaService::isSyntheticAttachmentPreview(['direction'=>'inbound','sender_type'=>'customer','text'=>'📎 Фото'], [['type'=>'image']]), false);
mediaCheck('image mime maps to image', ManagerOutboundService::attachmentTypeForMime('image/jpeg'), 'image');
mediaCheck('video mime maps to video', ManagerOutboundService::attachmentTypeForMime('video/mp4'), 'video');
mediaCheck('audio mime maps to audio', ManagerOutboundService::attachmentTypeForMime('audio/mpeg'), 'audio');
mediaCheck('document mime maps to file', ManagerOutboundService::attachmentTypeForMime('application/pdf'), 'file');

$protectedMax=ManagerMessageMediaService::publicAttachments(77,[['type'=>'image','provider'=>'max','token'=>'private-token','url'=>'https://provider.example/photo.jpg']],'max','inbound');
mediaCheck('inbound MAX photo uses protected local endpoint',$protectedMax[0]['url']??null,'media-file.php?message_id=77&attachment=0');
mediaCheck('MAX token is not projected to browser',str_contains(json_encode($protectedMax),'private-token'),false);
mediaCheck('MAX provider URL is not projected to browser',str_contains(json_encode($protectedMax),'provider.example'),false);
$protectedVideo=ManagerMessageMediaService::publicAttachments(79,[['type'=>'video','provider'=>'max','token'=>'video-private']],'max','inbound');
mediaCheck('inbound MAX video uses protected local endpoint',$protectedVideo[0]['url']??null,'media-file.php?message_id=79&attachment=0');
mediaCheck('MAX video token is not projected to browser',str_contains(json_encode($protectedVideo),'video-private'),false);
$maxOutbound=ManagerMessageMediaService::publicAttachments(78,[['type'=>'image','provider'=>'max','url'=>'https://provider.example/out.jpg']],'max','outbound');
mediaCheck('outbound MAX preview contract remains unchanged',$maxOutbound[0]['url']??null,'https://provider.example/out.jpg');

// Token-only inbound MAX image must be recoverable from the saved message id and
// archived as exact private bytes before it is served to the manager.
$tmpDir=sys_get_temp_dir().'/max-search-incoming-media-'.bin2hex(random_bytes(5));
putenv('MAX_SEARCH_INCOMING_MEDIA_DIR='.$tmpDir);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a/2kAAAAASUVORK5CYII=');
$messageCalls=[];$mediaCalls=[];
$messageFetcher=static function(string $mid)use(&$messageCalls){
    $messageCalls[]=$mid;
    return ['body'=>['attachments'=>[['type'=>'image','payload'=>['token'=>'saved-token','url'=>'https://cdn.max.test/recovered.png']]]]];
};
$mediaFetcher=static function(string $url,int $limit)use(&$mediaCalls,$png){$mediaCalls[]=[$url,$limit];return maxMediaStream($png);};
$local=MaxInboundMediaArchiveService::archiveMedia(42,0,'saved-mid',['type'=>'image','provider'=>'max','token'=>'saved-token'],$messageFetcher,$mediaFetcher);
mediaCheck('token-only MAX image resolves saved message once',$messageCalls,['saved-mid']);
mediaCheck('resolved MAX photo downloads provider URL',$mediaCalls[0][0]??null,'https://cdn.max.test/recovered.png');
mediaCheck('MAX media archive uses bounded 50MB fetch',$mediaCalls[0][1]??null,MaxInboundMediaArchiveService::MAX_MEDIA_BYTES);
mediaCheck('MAX photo has private local descriptor',isset($local['id'])&&preg_match('/^[a-f0-9]{32}$/D',(string)$local['id'])===1,true);
$localFile=is_array($local)?MaxInboundMediaArchiveService::openLocal($local):null;
mediaCheck('archived MAX photo MIME is detected from bytes',$localFile['mime']??null,'image/png');
mediaCheck('archived MAX photo bytes survive provider independence',is_array($localFile)?stream_get_contents($localFile['stream']):null,$png);
if(is_array($localFile)&&is_resource($localFile['stream']??null))fclose($localFile['stream']);
$bad=MaxInboundMediaArchiveService::archiveMedia(43,0,'saved-mid',['type'=>'image','url'=>'https://cdn.max.test/not-image'],static fn()=>null,static fn()=>maxMediaStream('<html>not an image</html>'));
mediaCheck('active non-image payload is never archived as photo',$bad,null);
if(is_dir($tmpDir)){foreach((array)glob($tmpDir.'/*') as $file)@unlink($file);@rmdir($tmpDir);}putenv('MAX_SEARCH_INCOMING_MEDIA_DIR');

$adapterSource = (string)file_get_contents(__DIR__ . '/../integrations/MaxIncomingAdapter.php');
$maxAdapterSource = (string)file_get_contents(__DIR__ . '/../integrations/MaxMessengerAdapter.php');
$recorderSource = (string)file_get_contents(__DIR__ . '/../services/ConversationRecorder.php');
$apiSource = (string)file_get_contents(__DIR__ . '/../manager/api.php');
$conversationUiSource = (string)file_get_contents(__DIR__ . '/../manager/assets/workspace-v2-conversation.js');
$mediaUiSource = (string)file_get_contents(__DIR__ . '/../manager/assets/workspace-v2-media.js');
$transportSource = (string)file_get_contents(__DIR__ . '/../services/MaxTransport.php');
$outboundSource = (string)file_get_contents(__DIR__ . '/../services/ManagerOutboundService.php');
$uploadSource = (string)file_get_contents(__DIR__ . '/../manager/media-upload.php');
$httpSource = (string)file_get_contents(__DIR__ . '/../manager/lib/ManagerHttp.php');
$cacheSource = (string)file_get_contents(__DIR__ . '/../services/ManagerMediaCache.php');
$fileEndpointSource = (string)file_get_contents(__DIR__ . '/../manager/media-file.php');
$mediaHydratorSource = (string)file_get_contents(__DIR__ . '/../services/ManagerMessageMediaService.php');
$maxArchiveSource = (string)file_get_contents(__DIR__ . '/../services/MaxInboundMediaArchiveService.php');
$maxDownloadAdapterSource = (string)file_get_contents(__DIR__ . '/../integrations/MaxInboundMediaDownloadAdapter.php');
$maxHandlerSource = (string)file_get_contents(__DIR__ . '/../handlers/MaxUpdateHandler.php');
$contextSource = (string)file_get_contents(__DIR__ . '/../services/ManagerRequestContext.php');
mediaCheck('MAX adapter passes normalized media to IncomingMessage', strpos($adapterSource, 'self::mediaAttachments($update)') !== false, true);
mediaCheck('recorder stores attachments in metadata', strpos($recorderSource, '$metadata[\'attachments\'] = $attachments') !== false, true);
mediaCheck('manager detail hydrates media metadata', strpos($apiSource, 'ManagerMessageMediaService::hydrate') !== false, true);
mediaCheck('Workspace V2 renders image media', strpos($conversationUiSource, "a.type==='image'") !== false && strpos($conversationUiSource, "document.createElement('img')") !== false && strpos($conversationUiSource, 'n.loading=\'lazy\'') !== false, true);
mediaCheck('Workspace V2 renders video media', strpos($conversationUiSource, "a.type==='video'") !== false && strpos($conversationUiSource, "document.createElement('video')") !== false && strpos($conversationUiSource, 'n.controls=true') !== false, true);
mediaCheck('Workspace V2 renders linked reply context', strpos($conversationUiSource, 'renderLinkedMessage') !== false && strpos($conversationUiSource, 'Ответ на сообщение') !== false && strpos($conversationUiSource, 'Пересланное сообщение') !== false, true);
mediaCheck('Workspace V2 renders audio media', strpos($conversationUiSource, "a.type==='audio'") !== false && strpos($conversationUiSource, "document.createElement('audio')") !== false && strpos($conversationUiSource, 'n.controls=true') !== false, true);
mediaCheck('Workspace V2 renders files as safe links', strpos($conversationUiSource, "document.createElement('a')") !== false && strpos($conversationUiSource, "n.target='_blank'") !== false && strpos($conversationUiSource, "n.rel='noopener'") !== false, true);
mediaCheck('MAX media flow starts with uploads endpoint', strpos($transportSource, "'/uploads'") !== false && strpos($transportSource, '[\'type\'=>$type]') !== false, true);
mediaCheck('MAX media upload is multipart data', strpos($transportSource, 'new CURLFile(') !== false && strpos($transportSource, '[\'data\'=>new CURLFile') !== false, true);
mediaCheck('MAX media send uses attachments payload', strpos($transportSource, '$body=[\'attachments\'=>[$attachment]]') !== false, true);
mediaCheck('video and audio preserve upload-endpoint token', strpos($transportSource, 'in_array($type,[\'video\',\'audio\'],true) ? $prefetchedToken') !== false, true);
mediaCheck('outbound media is restricted to owned MAX conversation', strpos($outboundSource, '$channel!==\'max\'') !== false && strpos($outboundSource, '(int)$c[\'manager_id\']!==$managerId') !== false, true);
mediaCheck('upload endpoint uses shared Manager HTTP auth and csrf boundary', strpos($uploadSource, "require_once __DIR__.'/lib/ManagerHttp.php'") !== false && strpos($uploadSource, 'ManagerHttp::requireManager()') !== false && strpos($uploadSource, 'ManagerHttp::requireCsrf($_POST)') !== false && strpos($uploadSource, 'ManagerRequestContext::') === false && strpos($httpSource, 'ManagerRequestContext::validCsrf') !== false, true);
mediaCheck('media endpoints no longer duplicate session cookie policy', strpos($uploadSource, 'session_set_cookie_params') === false && strpos($fileEndpointSource, 'session_set_cookie_params') === false && strpos($contextSource, "session_name('anytour_manager_panel')") !== false, true);
mediaCheck('Workspace V2 media owner uses multipart FormData', strpos($mediaUiSource, 'new FormData()') !== false && strpos($mediaUiSource, "fetch('media-upload.php'") !== false && strpos($mediaUiSource, "data.append('conversation_id'") !== false && strpos($mediaUiSource, "data.append('file'") !== false, true);
mediaCheck('successful manager upload creates private preview cache', strpos($uploadSource, 'ManagerMediaCache::store') !== false, true);
mediaCheck('failed MAX send removes unused cached preview', strpos($uploadSource, 'ManagerMediaCache::remove') !== false, true);
mediaCheck('outbound history stores preview URL', strpos($maxAdapterSource, '$metadataAttachment[\'url\']=trim($previewUrl)') !== false, true);
mediaCheck('preview cache uses bounded retention', strpos($cacheSource, 'TTL_SECONDS = 604800') !== false && strpos($cacheSource, 'self::prune()') !== false, true);
mediaCheck('preview endpoint uses shared authenticated manager context', strpos($fileEndpointSource, "require_once __DIR__.'/lib/ManagerHttp.php'") !== false && strpos($fileEndpointSource, 'ManagerHttp::start();') !== false && strpos($fileEndpointSource, 'ManagerHttp::requireManager();') !== false && strpos($fileEndpointSource, 'ManagerHttp::managerId();') !== false && strpos($fileEndpointSource, 'ManagerRequestContext::') === false, true);
mediaCheck('preview endpoint checks conversation visibility', strpos($fileEndpointSource, 'ManagerConversationService::detail') !== false, true);
mediaCheck('MAX media endpoint uses authorized provider service',strpos($fileEndpointSource,'ManagerMaxMediaService::attachment')!==false&&strpos($fileEndpointSource,'ManagerMaxMediaService::open')!==false,true);
mediaCheck('MAX inbound archive delegates provider transport',strpos($maxArchiveSource,'MaxInboundMediaDownloadAdapter::fetchMessage')!==false&&strpos($maxArchiveSource,'MaxInboundMediaDownloadAdapter::fetchMedia')!==false,true);
mediaCheck('MAX inbound download adapter keeps strict TLS',strpos($maxDownloadAdapterSource,'MaxTlsConfig::strictCurlOptions()')!==false,true);
mediaCheck('MAX video token resolver is provider-owned',strpos($maxDownloadAdapterSource,'function fetchVideo')!==false&&strpos($maxDownloadAdapterSource,"'/videos/'")!==false,true);
mediaCheck('MAX inbound media archive runs after response flush when FPM supports it',strpos($maxHandlerSource,'fastcgi_finish_request')!==false&&strpos($maxHandlerSource,'archiveRecordedMessage')!==false,true);
mediaCheck('synthetic manager media label is removed during hydration', strpos($mediaHydratorSource, 'isSyntheticAttachmentPreview') !== false, true);

echo $failed === 0 ? "MANAGER MEDIA: OK\n" : "MANAGER MEDIA: FAIL ({$failed})\n";
exit($failed > 0 ? 1 : 0);
