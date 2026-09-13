<?php
$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once $baseDir.'/services/ManagerConversationService.php';
require_once $baseDir.'/services/ManagerMediaCache.php';
ManagerHttp::start();

$manager=ManagerHttp::requireManager();
$managerId=ManagerHttp::managerId();
if (isset($_GET['message_id'])) {
    require_once $baseDir.'/services/ManagerTelegramMediaService.php';
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    $messageId = filter_var($_GET['message_id'], FILTER_VALIDATE_INT);
    $index = filter_var($_GET['attachment'] ?? 0, FILTER_VALIDATE_INT);
    $file = null;
    try {
        $attachment = $messageId !== false && $index !== false ? ManagerTelegramMediaService::attachment($messageId, $index, $managerId) : null;
        if (!$attachment) { http_response_code(404); echo 'Вложение недоступно.'; exit; }
        // Release the session lock before a potentially slow Telegram download.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $file = ManagerTelegramMediaService::open($attachment);
        if (!$file) { http_response_code(502); echo 'Не удалось загрузить вложение из Telegram. Повторите попытку.'; exit; }
        header('Content-Type: '.$file['mime']);
        header('Content-Length: '.$file['size']);
        header('Content-Disposition: '.($file['inline'] ? 'inline' : 'attachment').'; filename="attachment"; filename*=UTF-8\'\''.rawurlencode($file['name']));
        fpassthru($file['stream']);
    } catch (Throwable $e) {
        $large = $e->getCode() === 413;
        http_response_code($large ? 413 : 502);
        echo $large ? 'Файл больше 20 МБ: Telegram Bot API не позволяет его скачать.' : 'Не удалось загрузить вложение из Telegram. Повторите попытку.';
    } finally {
        if (is_array($file) && is_resource($file['stream'] ?? null)) fclose($file['stream']);
    }
    exit;
}
$id=(string)($_GET['id']??'');$media=ManagerMediaCache::get($id);
if(!$media){http_response_code(404);exit;}
$conversationId=(int)($media['conversation_id']??0);
if($conversationId<=0||!ManagerConversationService::detail($conversationId,$managerId)){http_response_code(403);exit;}
$path=(string)($media['path']??'');if($path===''||!is_file($path)){http_response_code(404);exit;}
$mime=(string)($media['mime']??'application/octet-stream');
$name=(string)($media['name']??'attachment');
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($path));
header('Content-Disposition: inline; filename="'.str_replace(['"','\\'],['_','_'],$name).'"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
