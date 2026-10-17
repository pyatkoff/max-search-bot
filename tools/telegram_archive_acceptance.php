<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
// Auxiliary read-only acceptance; execute through stdin, never install in webroot.
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
    $query = static function(string $sql, array $values) use ($pdo): array {
        $q = $pdo->prepare($sql); $q->execute($values); return $q->fetch(PDO::FETCH_ASSOC);
    };
    $scope = ['anytour', TelegramArchiveBotConfig::USERNAME];
    $accounts = $query('SELECT COUNT(*) AS registered, SUM(provider_enabled=1) AS connected, SUM(approved=1 AND provider_enabled=1) AS approved_connected, SUM(approved=1 AND provider_enabled=1 AND manager_id IS NULL) AS shared_approved FROM telegram_archive_accounts WHERE project_key=? AND bot_key=?', $scope);
    $where = 'm.project_key=? AND EXISTS (SELECT 1 FROM telegram_archive_accounts a WHERE a.project_key=m.project_key AND a.account_id=m.account_id AND a.bot_key=?) AND (m.source_kind=\'desktop_import\' OR (m.source_kind=\'business_message\' AND JSON_UNQUOTE(JSON_EXTRACT(m.record_json,\'$.bot_key\'))=?))';
    $recordScope = array_merge($scope, [TelegramArchiveBotConfig::USERNAME]);
    $records = $query('SELECT COUNT(*) AS total, SUM(m.source_kind=\'business_message\') AS live, SUM(m.source_kind=\'desktop_import\') AS imported, SUM(m.is_deleted=1) AS deleted, MAX(m.version_at) AS latest_source_time FROM telegram_dialogue_messages m WHERE '.$where, $recordScope);
    $test = $query('SELECT COUNT(*) AS matches, SUM(JSON_UNQUOTE(JSON_EXTRACT(m.record_json,\'$.direction\'))=\'inbound\') AS inbound, SUM(JSON_UNQUOTE(JSON_EXTRACT(m.record_json,\'$.direction\'))=\'outbound\') AS outbound FROM telegram_dialogue_messages m WHERE '.$where.' AND m.source_kind=\'business_message\' AND m.is_deleted=0 AND BINARY JSON_UNQUOTE(JSON_EXTRACT(m.record_json,\'$.text\'))=BINARY ?', array_merge($recordScope, ['Тест архива 1710']));
    $pdo->rollBack();
    $numbers = static fn(array $row): array => array_map(static fn($v): int => (int)$v, $row);
    $latest = $records['latest_source_time']; unset($records['latest_source_time']);
    echo json_encode(['ok'=>true,'checked_at_utc'=>gmdate('c'),'bot'=>(new TelegramArchiveBotConfig())->publicStatus(),
        'accounts'=>$numbers($accounts),'records'=>$numbers($records),
        'latest_source_message_utc'=>$latest === null ? null : gmdate('c', (int)$latest),
        'synthetic_test'=>$numbers($test)], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    echo '{"ok":false,"error":"archive_acceptance_unavailable"}'."\n";
    exit(1);
}
