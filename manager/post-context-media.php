<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerConversationService.php';
require_once dirname(__DIR__).'/services/ChannelPostMediaService.php';

ManagerHttp::start();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
if (ManagerHttp::managerId() <= 0) ManagerHttp::requireManager();
$file = null;
try {
    ProjectAccessService::initializeReadOnly();
    $manager = ManagerHttp::requireManager();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'], 405);
    $conversationId = filter_var($_GET['conversation_id'] ?? '', FILTER_VALIDATE_INT);
    $entryId = filter_var($_GET['entry_id'] ?? '', FILTER_VALIDATE_INT);
    $attachment = filter_var($_GET['attachment'] ?? 0, FILTER_VALIDATE_INT);
    if ($conversationId === false || $conversationId <= 0 || $entryId === false || $entryId <= 0 || $attachment !== 0) ManagerHttp::respond(['ok'=>false,'error'=>'not_found'], 404);
    // Fresh canonical routing/source/project ACL is required even for a cached image.
    $conversation = ManagerConversationService::visibleConversation($conversationId, (int)$manager['id']);
    if (!$conversation) ManagerHttp::respond(['ok'=>false,'error'=>'not_found'], 404);
    $registry = new ChannelPostRegistryService();
    if ($registry->versionForEntry($conversationId, (string)$conversation['project_key'], $entryId) === null) ManagerHttp::respond(['ok'=>false,'error'=>'not_found'], 404);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $file = (new ChannelPostMediaService($registry))->openEntry($conversationId, (string)$conversation['project_key'], $entryId, $attachment);
    if ($file === null) ManagerHttp::respond(['ok'=>false,'error'=>'post_image_unavailable'], 502);
    header('Content-Type: '.$file['mime']);
    header('Content-Length: '.$file['size']);
    header('Content-Disposition: inline; filename="post-image"');
    fpassthru($file['stream']);
} catch (Throwable $error) {
    // Provider credentials, signed URLs and raw errors never reach browser or logs.
    ManagerHttp::respond(['ok'=>false,'error'=>'post_image_unavailable'], 502);
} finally {
    if (is_array($file) && is_resource($file['stream'] ?? null)) fclose($file['stream']);
}
