<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$pdo = null;
try {
    $root = getcwd();
    if ($root !== '/var/www/anytoour/data/www/app.anytoour.ru') throw new RuntimeException('scope');
    $directory = dirname($root).'/.telegram-anex-capture-20261018';
    $path = $directory.'/result.json';
    clearstatcache();
    $dir = @lstat($directory); $file = @lstat($path);
    if (!$dir || !$file || is_link($directory) || is_link($path)
        || realpath($directory) !== $directory || realpath($path) !== $path
        || ($dir['mode'] & 0170000) !== 0040000 || ($dir['mode'] & 0777) !== 0700
        || ($file['mode'] & 0170000) !== 0100000 || ($file['mode'] & 0777) !== 0600 || $file['size'] > 16384
        || (function_exists('posix_geteuid') && ($dir['uid'] !== posix_geteuid() || $file['uid'] !== posix_geteuid()))) throw new RuntimeException('binding');
    $bytes = file_get_contents($path, false, null, 0, 16385);
    $binding = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($binding) || ($binding['ok'] ?? false) !== true || ($binding['source_project'] ?? '') !== 'anex'
        || ($binding['capture_enabled'] ?? false) !== true || ($binding['shared'] ?? false) !== true) throw new RuntimeException('binding');
    require_once $root.'/config.php';
    require_once $root.'/services/ProjectAccessService.php';
    require_once $root.'/services/TelegramArchiveBotConfig.php';
    require_once $root.'/services/TelegramDialogueNormalizer.php';
    if (ProjectConfig::projectId() !== 'anytour') throw new RuntimeException('project');
    $account = TelegramDialogueNormalizer::id($binding['account_id'] ?? null);
    ProjectAccessService::initializeReadOnly();
    $pdo = ConversationDb::connection();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('driver');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $q = $pdo->prepare('SELECT provider_enabled,approved,manager_id FROM telegram_archive_accounts WHERE project_key=? AND bot_key=? AND account_id=?');
    $q->execute(['anytour', TelegramArchiveBotConfig::USERNAME, $account]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new RuntimeException('account');
    $where = "project_key=? AND account_id=? AND (source_kind='desktop_import' OR (source_kind='business_message' AND JSON_UNQUOTE(JSON_EXTRACT(record_json,'$.bot_key'))=?))";
    $q = $pdo->prepare("SELECT SUM(source_kind='business_message') AS live,SUM(source_kind='desktop_import') AS imported FROM telegram_dialogue_messages WHERE ".$where);
    $q->execute(['anytour', $account, TelegramArchiveBotConfig::USERNAME]);
    $records = $q->fetch(PDO::FETCH_ASSOC);
    $q = $pdo->prepare("SELECT COUNT(*) AS matches,SUM(JSON_UNQUOTE(JSON_EXTRACT(record_json,'$.direction'))='inbound') AS inbound,SUM(JSON_UNQUOTE(JSON_EXTRACT(record_json,'$.direction'))='outbound') AS outbound FROM telegram_dialogue_messages WHERE project_key=? AND account_id=? AND source_kind='business_message' AND is_deleted=0 AND JSON_UNQUOTE(JSON_EXTRACT(record_json,'$.bot_key'))=? AND LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(record_json,'$.text'))))=?");
    $q->execute(['anytour', $account, TelegramArchiveBotConfig::USERNAME, 'тест anex']);
    $test = $q->fetch(PDO::FETCH_ASSOC);
    $pdo->rollBack();
    $numbers = static fn(array $values): array => array_map(static fn($value): int => (int)$value, $values);
    echo json_encode(['ok'=>true,'source_project'=>'anex','checked_at_utc'=>gmdate('c'),
        'connected'=>(bool)$row['provider_enabled'],'capture_enabled'=>(bool)$row['approved'],'shared'=>$row['manager_id'] === null,
        'records'=>$numbers($records),'synthetic_test'=>$numbers($test)], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    echo '{"ok":false,"error":"anex_live_inspection_unavailable"}'."\n";
    exit(1);
}
