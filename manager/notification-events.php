<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerIncomingNotificationService.php';

ManagerHttp::startJson();
// Reject missing sessions before connecting to the database.
if (ManagerHttp::managerId() <= 0) ManagerHttp::requireManager();
$pdo = null;
try {
    $pdo = ConversationDb::connection();
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    // Authentication itself uses the project bootstrap: initialize before it.
    ProjectAccessService::initializeReadOnly();
    ManagerHttp::requireManager();
    $data = ManagerHttp::body();
    ManagerHttp::requireCsrf($data);
    $cursor = $data['cursor'] ?? null;
    if (($data['action'] ?? '') !== 'poll' || ($cursor !== null && (!is_int($cursor) || $cursor < 0 || $cursor > 9007199254740991))) {
        $pdo->rollBack();
        ManagerHttp::respond(['ok'=>false, 'error'=>'invalid_notification_cursor'], 422);
    }
    $result = ManagerIncomingNotificationService::poll(ManagerHttp::managerId(), $cursor);
    $pdo->rollBack();
    ManagerHttp::respond(['ok'=>true] + $result);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    // Never expose a DB exception, transcript, attachment URL or credential here.
    ManagerHttp::respond(['ok'=>false, 'error'=>'notification_feed_unavailable'], 503);
}
