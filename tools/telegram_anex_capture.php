<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$phase = 'scope';
$started = false;
try {
    $root = getcwd();
    if ($root !== '/var/www/anytoour/data/www/app.anytoour.ru') throw new RuntimeException('scope');
    require_once $root.'/config.php';
    require_once $root.'/services/ProjectAccessService.php';
    require_once $root.'/services/TelegramArchiveBotConfig.php';
    require_once $root.'/services/TelegramArchiveAccountRegistry.php';
    require_once $root.'/services/TelegramDialogueFiles.php';
    if (ProjectConfig::projectId() !== 'anytour') throw new RuntimeException('project');
    ProjectAccessService::initializeReadOnly();
    $pdo = ConversationDb::connection();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('driver');
    $phase = 'preflight';
    $q = $pdo->prepare('SELECT COUNT(*) AS registered, SUM(provider_enabled=1) AS connected FROM telegram_archive_accounts WHERE project_key=? AND bot_key=?');
    $q->execute(['anytour', TelegramArchiveBotConfig::USERNAME]);
    $counts = $q->fetch(PDO::FETCH_ASSOC);
    if ((int)$counts['registered'] !== 2 || (int)$counts['connected'] !== 2) throw new RuntimeException('changed_scope');
    $q = $pdo->prepare("SELECT a.account_id FROM telegram_archive_accounts a WHERE a.project_key=? AND a.bot_key=? AND a.provider_enabled=1 AND a.approved=0 AND NOT EXISTS (SELECT 1 FROM telegram_dialogue_messages m WHERE m.project_key=a.project_key AND m.account_id=a.account_id AND m.source_kind='desktop_import')");
    $q->execute(['anytour', TelegramArchiveBotConfig::USERNAME]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1) throw new RuntimeException('ambiguous_pending_account');
    $account = TelegramDialogueNormalizer::id($rows[0]['account_id']);
    $q = $pdo->prepare("SELECT COUNT(*) FROM telegram_archive_accounts a WHERE a.project_key=? AND a.bot_key=? AND a.account_id<>? AND a.provider_enabled=1 AND a.approved=1 AND a.manager_id IS NULL AND (SELECT COUNT(*) FROM telegram_dialogue_messages m WHERE m.project_key=a.project_key AND m.account_id=a.account_id AND m.source_kind='desktop_import')>=75911");
    $q->execute(['anytour', TelegramArchiveBotConfig::USERNAME, $account]);
    if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('existing_account_mismatch');
    $phase = 'receipt';
    $directory = dirname($root).'/.telegram-anex-capture-20261018';
    if (!file_exists($directory) && !mkdir($directory, 0700)) throw new RuntimeException('private_directory');
    clearstatcache(true, $directory);
    if (is_link($directory) || !is_dir($directory) || realpath($directory) !== $directory || (fileperms($directory) & 0777) !== 0700
        || (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid())) throw new RuntimeException('private_boundary');
    $receipt = ['source_project'=>'anex', 'account_id'=>$account, 'operation'=>'enable_shared_capture', 'source_main'=>'9ed2ab244a4b4e1a704ac0841dba53a3e4280da8', 'started_at_utc'=>gmdate('c')];
    TelegramDialogueFiles::write($directory.'/started.json', static function(callable $emit) use ($receipt): void { $emit(json_encode($receipt, JSON_THROW_ON_ERROR)."\n"); });
    $started = true;
    $phase = 'apply';
    $registry = new TelegramArchiveAccountRegistry($pdo);
    $registry->setCapture('anytour', TelegramArchiveBotConfig::USERNAME, $account, true, null);
    $phase = 'readback';
    $row = $registry->account('anytour', TelegramArchiveBotConfig::USERNAME, $account);
    if (!$row || !(bool)$row['approved'] || !(bool)$row['provider_enabled'] || $row['manager_id'] !== null) throw new RuntimeException('capture_not_confirmed');
    $result = ['ok'=>true, 'source_project'=>'anex', 'capture_enabled'=>true, 'shared'=>true];
    $phase = 'result_receipt';
    TelegramDialogueFiles::write($directory.'/result.json', static function(callable $emit) use ($result, $account): void { $emit(json_encode($result+['account_id'=>$account,'completed_at_utc'=>gmdate('c')], JSON_THROW_ON_ERROR)."\n"); });
    echo json_encode($result, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    echo json_encode(['ok'=>false, 'phase'=>$phase, 'apply_started'=>$started], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
