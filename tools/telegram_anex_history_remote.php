<?php
declare(strict_types=1);
// One-use auxiliary wrapper, streamed over pinned SSH, never installed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
const ANEX_MANIFEST_JSON = '{"version":1,"source_project":"anex","original":{"sha256":"769dbdf0365a471916d0cf228a322d374c8ed0616359f9e9bbe5046233bc5052","bytes":159135328},"parts":[{"part":1,"path":"payload/part-001.json","sha256":"4adb698a2b1d70db0550271167f695679a3b9842f3bf49c2b6b1c35321d2ad46","bytes":50331218,"selected_chats":2461,"messages":103278,"service_messages":115,"excluded_chats":1},{"part":2,"path":"payload/part-002.json","sha256":"5e4e1ebf99baa05ac0c9f4749c880dd8fe09d1de4a925491732068e909c8737c","bytes":50325170,"selected_chats":5858,"messages":99468,"service_messages":281,"excluded_chats":1},{"part":3,"path":"payload/part-003.json","sha256":"c72a66cf59e94c3a251a88fdd20c9144036adf6f9cbbecdf38047580b7113a63","bytes":21684465,"selected_chats":3281,"messages":43116,"service_messages":144,"excluded_chats":1}]}';
$phase = 'scope';
$started = false;
$partNumber = 0;
function anexSafeDirectory(string $path): bool {
    $s = @lstat($path);
    return $s && !is_link($path) && realpath($path) === $path
        && ($s['mode'] & 0170000) === 0040000 && ($s['mode'] & 0777) === 0700
        && (!function_exists('posix_geteuid') || $s['uid'] === posix_geteuid());
}
function anexPrivateJson(string $path): array {
    $s = @lstat($path);
    if (!$s || is_link($path) || realpath($path) !== $path
        || ($s['mode'] & 0170000) !== 0100000 || ($s['mode'] & 0777) !== 0600 || $s['size'] > 16384
        || (function_exists('posix_geteuid') && $s['uid'] !== posix_geteuid())) throw new RuntimeException('private_receipt');
    $v = json_decode(TelegramDialogueFiles::read($path), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($v)) throw new RuntimeException('private_receipt');
    return $v;
}
function anexSyncDirectory(string $path): void {
    $handle = @fopen($path, 'r');
    if ($handle === false) throw new RuntimeException('receipt_directory_sync');
    try {
        if (!function_exists('fsync') || !@fsync($handle)) throw new RuntimeException('receipt_directory_sync');
    } finally { fclose($handle); }
}
function anexPublicResult(array $value, array $counts, int $part): array {
    if (($value['ok'] ?? false) !== true || ($value['source_project'] ?? '') !== 'anex'
        || ($value['part'] ?? null) !== $part || ($value['counts'] ?? null) !== $counts
        || !is_array($value['storage'] ?? null) || array_keys($value['storage']) !== ['inserted','updated','unchanged']) throw new RuntimeException('result_receipt');
    foreach ($value['storage'] as $n) if (!is_int($n) || $n < 0) throw new RuntimeException('result_receipt');
    if (array_sum($value['storage']) !== $counts['messages']) throw new RuntimeException('result_receipt');
    return ['ok'=>true,'source_project'=>'anex','part'=>$part,'reused_receipt'=>true,'counts'=>$counts,'storage'=>$value['storage']];
}
try {
    $root = '/var/www/anytoour/data/www/app.anytoour.ru';
    if (getcwd() !== $root || count($argv) !== 4 || $argv[1] !== 'import'
        || !preg_match('/\A[1-3]\z/', $argv[2])) throw new RuntimeException('scope');
    $partNumber = (int)$argv[2];
    $manifest = json_decode(ANEX_MANIFEST_JSON, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1 || ($manifest['source_project'] ?? '') !== 'anex'
        || ($manifest['original']['sha256'] ?? '') !== '769dbdf0365a471916d0cf228a322d374c8ed0616359f9e9bbe5046233bc5052'
        || ($manifest['original']['bytes'] ?? null) !== 159135328 || count($manifest['parts'] ?? []) !== 3) throw new RuntimeException('manifest');
    $part = $manifest['parts'][$partNumber-1];
    if (($part['part'] ?? null) !== $partNumber || !is_string($part['sha256'] ?? null)
        || !preg_match('/\A[a-f0-9]{64}\z/', $part['sha256'])) throw new RuntimeException('manifest');
    $expected = ['selected_chats'=>$part['selected_chats'],'messages'=>$part['messages'],
        'service_messages'=>$part['service_messages'],'excluded_chats'=>$part['excluded_chats']];
    require_once $root.'/services/TelegramDialogueFiles.php';
    require_once $root.'/services/TelegramDialogueArchive.php';
    require_once $root.'/services/TelegramArchiveBotConfig.php';
    $phase = 'source';
    $bytes = TelegramDialogueFiles::read($argv[3]);
    $phase = 'hash';
    if (strlen($bytes) !== $part['bytes'] || !hash_equals($part['sha256'], hash('sha256', $bytes))) throw new RuntimeException('source');
    $phase = 'normalize';
    $input = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
    unset($bytes);
    if (!is_array($input) || isset($input['personal_information']) || !is_array($input['chats']['list'] ?? null)) throw new RuntimeException('input');
    $selected = []; $saved = [];
    foreach ($input['chats']['list'] as $chat) {
        if (!is_array($chat)) throw new RuntimeException('chat');
        if (($chat['type'] ?? '') === 'personal_chat') $selected[] = TelegramDialogueNormalizer::id($chat['id'] ?? null);
        elseif (($chat['type'] ?? '') === 'saved_messages') $saved[] = $chat;
        else throw new RuntimeException('chat_type');
    }
    if (count($saved) !== 1 || ($saved[0]['messages'] ?? null) !== [] || count($selected) !== $part['selected_chats']
        || count(array_unique($selected)) !== count($selected)) throw new RuntimeException('owner_evidence');
    $account = TelegramDialogueNormalizer::id($saved[0]['id'] ?? null);
    $phase = 'owner_binding';
    $bindingDir = dirname($root).'/.telegram-anex-capture-20261018';
    if (!anexSafeDirectory($bindingDir)) throw new RuntimeException('owner_binding');
    $binding = anexPrivateJson($bindingDir.'/result.json');
    if (($binding['ok'] ?? false) !== true || ($binding['source_project'] ?? '') !== 'anex'
        || ($binding['capture_enabled'] ?? false) !== true || ($binding['shared'] ?? false) !== true
        || !hash_equals(TelegramDialogueNormalizer::id($binding['account_id'] ?? null), $account)) throw new RuntimeException('owner_binding');
    $phase = 'normalize';
    $normalized = TelegramDialogueNormalizer::desktop($input, $account, $selected);
    unset($input, $selected, $saved);
    if ($normalized['counts'] !== $expected) throw new RuntimeException('canonical_counts');
    foreach ($normalized['messages'] as &$record) $record['source_project'] = 'anex';
    unset($record);
    $phase = 'project';
    require_once $root.'/config.php';
    require_once $root.'/services/ProjectAccessService.php';
    if (ProjectConfig::projectId() !== 'anytour') throw new RuntimeException('project');
    ProjectAccessService::initializeReadOnly();
    $pdo = ConversationDb::connection();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('driver');
    $phase = 'shared_account';
    $q = $pdo->prepare('SELECT COUNT(*) FROM telegram_archive_accounts WHERE project_key=? AND bot_key=? AND account_id=? AND provider_enabled=1 AND approved=1 AND manager_id IS NULL');
    $q->execute(['anytour',TelegramArchiveBotConfig::USERNAME,$account]);
    if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('shared_account');
    $q = $pdo->prepare("SELECT COUNT(*) FROM telegram_dialogue_messages WHERE project_key=? AND account_id=? AND COALESCE(JSON_TYPE(JSON_EXTRACT(record_json, '$.manager_id')), 'NULL') <> 'NULL'");
    $q->execute(['anytour',$account]);
    if ((int)$q->fetchColumn() !== 0) throw new RuntimeException('shared_account');
    $phase = 'receipt';
    $directory = dirname($root).'/.telegram-anex-history-20261018';
    if (!file_exists($directory)) {
        if (!mkdir($directory, 0700)) throw new RuntimeException('receipt_directory');
        anexSyncDirectory(dirname($directory));
    }
    clearstatcache(true, $directory);
    if (!anexSafeDirectory($directory)) throw new RuntimeException('receipt_directory');
    $stem = $directory.'/part-'.str_pad((string)$partNumber,3,'0',STR_PAD_LEFT).'.'.$part['sha256'];
    $startedPath = $stem.'.started.json'; $resultPath = $stem.'.result.json';
    if (file_exists($resultPath) || is_link($resultPath)) {
        $started = true;
        $start = anexPrivateJson($startedPath); $prior = anexPrivateJson($resultPath);
        foreach ([$start,$prior] as $receipt) {
            if (($receipt['account_id'] ?? null) !== $account || ($receipt['source_project'] ?? '') !== 'anex'
                || ($receipt['original_sha256'] ?? '') !== $manifest['original']['sha256']
                || ($receipt['part_sha256'] ?? '') !== $part['sha256'] || ($receipt['part'] ?? null) !== $partNumber) throw new RuntimeException('receipt_identity');
        }
        echo json_encode(anexPublicResult($prior,$expected,$partNumber), JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        exit;
    }
    if (file_exists($startedPath) || is_link($startedPath)) { $started = true; throw new RuntimeException('unknown_prior_apply'); }
    $identity = ['account_id'=>$account,'source_project'=>'anex','original_sha256'=>$manifest['original']['sha256'],
        'part_sha256'=>$part['sha256'],'part'=>$partNumber];
    $phase = 'start';
    TelegramDialogueFiles::write($startedPath, static function(callable $emit) use ($identity): void {
        $emit(json_encode($identity+['started_at_utc'=>gmdate('c')], JSON_THROW_ON_ERROR)."\n");
    });
    $started = true;
    anexSyncDirectory($directory);
    $phase = 'apply';
    $storage = (new TelegramDialogueArchive($pdo))->import('anytour',$normalized['messages'],null);
    if (array_sum($storage) !== $expected['messages']) throw new RuntimeException('storage_count');
    $result = ['ok'=>true,'source_project'=>'anex','part'=>$partNumber,'reused_receipt'=>false,'counts'=>$expected,'storage'=>$storage];
    $phase = 'result_receipt';
    TelegramDialogueFiles::write($resultPath, static function(callable $emit) use ($identity,$result): void {
        $emit(json_encode($result+$identity+['completed_at_utc'=>gmdate('c')], JSON_THROW_ON_ERROR)."\n");
    });
    anexSyncDirectory($directory);
    echo json_encode($result, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    echo json_encode(['ok'=>false,'phase'=>$phase,'apply_started'=>$started,'part'=>$partNumber], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
