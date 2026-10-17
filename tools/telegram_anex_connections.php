<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$pdo = null;
try {
    $root = getcwd();
    if ($root !== '/var/www/anytoour/data/www/app.anytoour.ru') throw new RuntimeException('scope');
    require_once $root.'/config.php';
    require_once $root.'/services/ProjectAccessService.php';
    require_once $root.'/services/TelegramArchiveBotConfig.php';
    if (ProjectConfig::projectId() !== 'anytour') throw new RuntimeException('project');
    ProjectAccessService::initializeReadOnly();
    $pdo = ConversationDb::connection();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('driver');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $q = $pdo->prepare("SELECT COUNT(*) AS registered, SUM(a.provider_enabled=1) AS connected, SUM(a.provider_enabled=1 AND a.approved=1) AS capture_enabled, SUM(a.provider_enabled=1 AND a.approved=0) AS capture_pending, SUM(a.provider_enabled=1 AND a.approved=1 AND a.manager_id IS NULL) AS shared_enabled, SUM(a.provider_enabled=1 AND EXISTS (SELECT 1 FROM telegram_dialogue_messages m WHERE m.project_key=a.project_key AND m.account_id=a.account_id AND m.source_kind='desktop_import')) AS connected_with_history, SUM(a.provider_enabled=1 AND NOT EXISTS (SELECT 1 FROM telegram_dialogue_messages m WHERE m.project_key=a.project_key AND m.account_id=a.account_id AND m.source_kind='desktop_import')) AS connected_without_history FROM telegram_archive_accounts a WHERE a.project_key=? AND a.bot_key=?");
    $q->execute(['anytour', TelegramArchiveBotConfig::USERNAME]);
    $accounts = $q->fetch(PDO::FETCH_ASSOC);
    $q = $pdo->prepare("SELECT COUNT(*) AS total, SUM(m.source_kind='desktop_import') AS imported, SUM(m.source_kind='business_message') AS live FROM telegram_dialogue_messages m WHERE m.project_key=? AND EXISTS (SELECT 1 FROM telegram_archive_accounts a WHERE a.project_key=m.project_key AND a.account_id=m.account_id AND a.bot_key=?) AND (m.source_kind='desktop_import' OR (m.source_kind='business_message' AND JSON_UNQUOTE(JSON_EXTRACT(m.record_json,'$.bot_key'))=?))");
    $q->execute(['anytour', TelegramArchiveBotConfig::USERNAME, TelegramArchiveBotConfig::USERNAME]);
    $records = $q->fetch(PDO::FETCH_ASSOC);
    $pdo->rollBack();
    $numbers = static fn(array $row): array => array_map(static fn($v): int => (int)$v, $row);
    echo json_encode(['ok'=>true, 'checked_at_utc'=>gmdate('c'), 'accounts'=>$numbers($accounts), 'records'=>$numbers($records)], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    echo '{"ok":false,"error":"connection_inventory_unavailable"}'."\n";
    exit(1);
}
