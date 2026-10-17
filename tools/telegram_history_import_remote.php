<?php
declare(strict_types=1);
// One-use auxiliary wrapper. Streamed by SSH; never installed in the checkout.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
const HISTORY_SOURCE_HASH = '655025adabf918ee41a1fee9ff1d67cd91fa89ad6c60101459194c4834b0bea7';
const HISTORY_SOURCE_BYTES = 53715353;
$started = false;
try {
    $root = '/var/www/anytoour/data/www/app.anytoour.ru';
    if (getcwd() !== $root || count($argv) !== 2) throw new RuntimeException('scope');
    require_once $root.'/services/TelegramDialogueFiles.php';
    require_once $root.'/services/TelegramDialogueArchive.php';
    require_once $root.'/services/TelegramArchiveBotConfig.php';
    $bytes = TelegramDialogueFiles::read($argv[1]);
    if (strlen($bytes) !== HISTORY_SOURCE_BYTES || !hash_equals(HISTORY_SOURCE_HASH, hash('sha256', $bytes))) throw new RuntimeException('source');
    $input = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
    unset($bytes);
    if (!is_array($input)) throw new RuntimeException('export');
    $account = TelegramDialogueNormalizer::id($input['personal_information']['user_id'] ?? null);
    $chats = [];
    foreach (($input['chats']['list'] ?? []) as $chat) {
        if (is_array($chat) && ($chat['type'] ?? '') === 'personal_chat') $chats[] = TelegramDialogueNormalizer::id($chat['id'] ?? null);
    }
    if (count($chats) !== 5956 || count(array_unique($chats)) !== 5956) throw new RuntimeException('chat_scope');
    $normalized = TelegramDialogueNormalizer::desktop($input, $account, $chats);
    unset($input, $chats);
    $expectedCounts = ['selected_chats'=>5956, 'messages'=>75911, 'service_messages'=>362, 'excluded_chats'=>1];
    if ($normalized['counts'] !== $expectedCounts) throw new RuntimeException('counts');
    require_once $root.'/config.php';
    require_once $root.'/services/ProjectAccessService.php';
    if (ProjectConfig::projectId() !== 'anytour') throw new RuntimeException('project');
    ProjectAccessService::initializeReadOnly();
    $pdo = ConversationDb::connection();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('driver');
    $q = $pdo->prepare('SELECT COUNT(*) FROM telegram_archive_accounts WHERE project_key=? AND bot_key=? AND account_id=? AND provider_enabled=1 AND approved=1 AND manager_id IS NULL');
    $q->execute(['anytour', TelegramArchiveBotConfig::USERNAME, $account]);
    if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('shared_account_mismatch');
    // Durable aggregate-only receipt prevents blind repetition after disconnects.
    $receiptDir = dirname($root).'/.telegram-history-import-receipts';
    if (!file_exists($receiptDir) && !mkdir($receiptDir, 0700)) throw new RuntimeException('receipt_directory');
    clearstatcache(true, $receiptDir);
    if (is_link($receiptDir) || realpath($receiptDir) !== $receiptDir || !is_dir($receiptDir)
        || (fileperms($receiptDir) & 0777) !== 0700
        || (function_exists('posix_geteuid') && fileowner($receiptDir) !== posix_geteuid())) throw new RuntimeException('receipt_boundary');
    TelegramDialogueFiles::write($receiptDir.'/'.HISTORY_SOURCE_HASH.'.started.json', static function(callable $emit): void {
        $emit(json_encode(['state'=>'started', 'source_sha256'=>HISTORY_SOURCE_HASH, 'started_at_utc'=>gmdate('c')], JSON_THROW_ON_ERROR)."\n");
    });
    $started = true;
    $storage = (new TelegramDialogueArchive($pdo))->import('anytour', $normalized['messages'], null);
    if (array_sum($storage) !== 75911) throw new RuntimeException('storage_counts');
    $result = ['ok'=>true, 'matched_shared_account'=>true, 'counts'=>$expectedCounts, 'storage'=>$storage];
    $encoded = json_encode($result, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    TelegramDialogueFiles::write($receiptDir.'/'.HISTORY_SOURCE_HASH.'.result.json', static function(callable $emit) use ($encoded): void { $emit($encoded); });
    echo $encoded;
} catch (Throwable $error) {
    // Never include source content, identities, paths, SQL or credentials.
    fwrite(STDERR, $started ? "Private import outcome requires receipt inspection.\n" : "Private import validation failed before apply.\n");
    exit(1);
}
