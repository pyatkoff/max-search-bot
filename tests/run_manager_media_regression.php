<?php
require_once __DIR__ . '/../integrations/MaxIncomingAdapter.php';
require_once __DIR__ . '/../services/ConversationRecorder.php';
require_once __DIR__ . '/../services/ManagerOutboundService.php';
require_once __DIR__ . '/../services/ManagerMessageMediaService.php';

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
mediaCheck('video token retained', $incoming['attachments'][1]['token'] ?? null, 'vid.1');
mediaCheck('audio transcription retained', $incoming['attachments'][2]['transcription'] ?? null, 'голос');
mediaCheck('file name retained', $incoming['attachments'][3]['name'] ?? null, 'offer.pdf');
mediaCheck('media-only preview is useful', ConversationRecorder::attachmentPreview([['type'=>'image'],['type'=>'audio']]), '📎 Фото, Аудио');
mediaCheck('manager synthetic image label is recognized', ManagerMessageMediaService::isSyntheticAttachmentPreview(['direction'=>'outbound','sender_type'=>'manager','text'=>'📎 Фото'], [['type'=>'image','url'=>'media-file.php?id=x']]), true);
mediaCheck('manager real caption is preserved', ManagerMessageMediaService::isSyntheticAttachmentPreview(['direction'=>'outbound','sender_type'=>'manager','text'=>'Посмотрите этот отель'], [['type'=>'image','url'=>'media-file.php?id=x']]), false);
mediaCheck('customer attachment preview is not suppressed', ManagerMessageMediaService::isSyntheticAttachmentPreview(['direction'=>'inbound','sender_type'=>'customer','text'=>'📎 Фото'], [['type'=>'image']]), false);
mediaCheck('image mime maps to image', ManagerOutboundService::attachmentTypeForMime('image/jpeg'), 'image');
mediaCheck('video mime maps to video', ManagerOutboundService::attachmentTypeForMime('video/mp4'), 'video');
mediaCheck('audio mime maps to audio', ManagerOutboundService::attachmentTypeForMime('audio/mpeg'), 'audio');
mediaCheck('document mime maps to file', ManagerOutboundService::attachmentTypeForMime('application/pdf'), 'file');

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
$contextSource = (string)file_get_contents(__DIR__ . '/../services/ManagerRequestContext.php');
mediaCheck('MAX adapter passes normalized media to IncomingMessage', strpos($adapterSource, 'self::mediaAttachments($update)') !== false, true);
mediaCheck('recorder stores attachments in metadata', strpos($recorderSource, '$metadata[\'attachments\'] = $attachments') !== false, true);
mediaCheck('manager detail hydrates media metadata', strpos($apiSource, 'ManagerMessageMediaService::hydrate') !== false, true);
mediaCheck('Workspace V2 renders image media', strpos($conversationUiSource, "a.type==='image'") !== false && strpos($conversationUiSource, "document.createElement('img')") !== false && strpos($conversationUiSource, 'n.loading=\'lazy\'') !== false, true);
mediaCheck('Workspace V2 renders video media', strpos($conversationUiSource, "a.type==='video'") !== false && strpos($conversationUiSource, "document.createElement('video')") !== false && strpos($conversationUiSource, 'n.controls=true') !== false, true);
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
mediaCheck('synthetic manager media label is removed during hydration', strpos($mediaHydratorSource, 'isSyntheticAttachmentPreview') !== false, true);

echo $failed === 0 ? "MANAGER MEDIA: OK\n" : "MANAGER MEDIA: FAIL ({$failed})\n";
exit($failed > 0 ? 1 : 0);
