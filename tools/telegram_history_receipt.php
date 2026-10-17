<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
// Auxiliary inspection only: no source input, provider requests or database writes.
function historyReceiptSafeFile(string $path): bool
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    return is_array($stat) && ($stat['mode'] & 0170000) === 0100000
        && ($stat['mode'] & 0777) === 0600 && !is_link($path)
        && realpath($path) === $path && $stat['size'] <= 16384
        && (!function_exists('posix_geteuid') || $stat['uid'] === posix_geteuid());
}
function historyReceiptAggregate(string $path): ?array
{
    if (!historyReceiptSafeFile($path)) return null;
    try {
        $bytes = @file_get_contents($path, false, null, 0, 16385);
        if (!is_string($bytes) || strlen($bytes) > 16384) return null;
        $value = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_keys($value) !== ['ok','matched_shared_account','counts','storage']
            || $value['ok'] !== true || $value['matched_shared_account'] !== true) return null;
        $keys = ['counts'=>['selected_chats','messages','service_messages','excluded_chats'], 'storage'=>['inserted','updated','unchanged']];
        foreach ($keys as $group=>$expected) {
            if (!is_array($value[$group]) || array_keys($value[$group]) !== $expected) return null;
            foreach ($value[$group] as $number) if (!is_int($number) || $number < 0) return null;
        }
        return ['counts'=>$value['counts'], 'storage'=>$value['storage']];
    } catch (Throwable $error) { return null; }
}
$pdo = null;
try {
    $root = getcwd();
    if ($root !== '/var/www/anytoour/data/www/app.anytoour.ru') throw new RuntimeException('scope');
    $receiptDir = dirname($root).'/.telegram-history-import-receipts';
    $hash = '655025adabf918ee41a1fee9ff1d67cd91fa89ad6c60101459194c4834b0bea7';
    $startedPath = $receiptDir.'/'.$hash.'.started.json';
    $resultPath = $receiptDir.'/'.$hash.'.result.json';
    clearstatcache(true, $receiptDir);
    $directorySafe = !is_link($receiptDir) && is_dir($receiptDir) && realpath($receiptDir) === $receiptDir
        && (fileperms($receiptDir) & 0777) === 0700
        && (!function_exists('posix_geteuid') || fileowner($receiptDir) === posix_geteuid());
    $receipts = ['directory_exists'=>file_exists($receiptDir) || is_link($receiptDir),
        'directory_safe'=>$directorySafe, 'parent_writable'=>is_writable(dirname($root)),
        'started_exists'=>file_exists($startedPath) || is_link($startedPath),
        'started_safe'=>$directorySafe && historyReceiptSafeFile($startedPath),
        'result_exists'=>file_exists($resultPath) || is_link($resultPath),
        'result_safe'=>$directorySafe && historyReceiptSafeFile($resultPath),
        'result_counts'=>$directorySafe ? historyReceiptAggregate($resultPath) : null];
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
        'synthetic_test'=>$numbers($test),'receipts'=>$receipts], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    echo '{"ok":false,"error":"history_receipt_inspection_unavailable"}'."\n";
    exit(1);
}
