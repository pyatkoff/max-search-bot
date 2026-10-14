<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerConversationService.php';
require_once dirname(__DIR__).'/services/ChannelPostRegistryService.php';

ManagerHttp::startJson();
// Missing sessions never reach the registry or a provider.
if (ManagerHttp::managerId() <= 0) ManagerHttp::requireManager();
try {
    // This read must not run the legacy project UPSERT or mark messages read.
    ProjectAccessService::initializeReadOnly();
    $manager = ManagerHttp::requireManager();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        ManagerHttp::respond(['ok'=>false, 'error'=>'method_not_allowed'], 405);
    }
    $data = ManagerHttp::body();
    ManagerHttp::requireCsrf($data);
    $id = $data['conversation_id'] ?? null;
    if (($data['action'] ?? '') !== 'detail' || !is_int($id) || $id <= 0) {
        ManagerHttp::respond(['ok'=>false, 'error'=>'invalid_conversation'], 422);
    }
    // Recheck the current project/source/routing permissions on every read.
    // A publication identifier on its own never grants access to its content.
    $conversation = ManagerConversationService::visibleConversation($id, (int)$manager['id']);
    if (!$conversation) ManagerHttp::respond(['ok'=>false, 'error'=>'not_found'], 404);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $context = (new ChannelPostRegistryService())->managerContext($id, (string)$conversation['project_key'], 5);
    ManagerHttp::respond(['ok'=>true, 'conversation_id'=>$id, 'context'=>$context]);
} catch (Throwable $e) {
    // Neither storage errors nor provider media/credentials are returned or logged.
    ManagerHttp::respond(['ok'=>false, 'error'=>'post_context_unavailable'], 503);
}
