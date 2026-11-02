<?php
declare(strict_types=1);

// Streamed, fixed-target CLI diagnostic. No imports, workers, claims or writes.
if (PHP_SAPI !== 'cli') exit(2);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(E_ALL);
set_error_handler(static function (): void { throw new RuntimeException('runtime_error'); });
ob_start();

const DIAG_ROOT = '/var/www/anytoour/data/www/app.anytoour.ru';
const DIAG_EXPECTED_SHA = 'e768a32947cdb489091083d97ae1772741089699';
const DIAG_TARGETS = [
    ['url' => 'https://max.ru/c/-72182945320979/AaGcF3UiZcg', 'channel_id' => '-72182945320979', 'code' => 'AaGcF3UiZcg'],
    ['url' => 'https://max.ru/anytour/AaGcGD9TWvI', 'alias' => 'https://max.ru/anytour', 'code' => 'AaGcGD9TWvI'],
];
const DIAG_MAX_CHANNELS = 8;
const DIAG_MAX_GETS = 15;
const DIAG_HISTORY_DAYS = 30;
final class ReportedMaxPostDiagnosticFailure extends RuntimeException {}

$pdo = null;
$getCount = 0;
$chatCache = [];
$receipts = [];
try {
    $head = []; $status = []; $headExit = 1; $statusExit = 1;
    exec('git --no-optional-locks -C '.escapeshellarg(DIAG_ROOT).' rev-parse HEAD 2>/dev/null', $head, $headExit);
    exec('git --no-optional-locks -C '.escapeshellarg(DIAG_ROOT).' status --porcelain --untracked-files=no 2>/dev/null', $status, $statusExit);
    if ($headExit !== 0 || $statusExit !== 0 || $head !== [DIAG_EXPECTED_SHA] || $status !== []) diagFail('installed_checkout_changed');

    require_once DIAG_ROOT.'/config.php';
    require_once DIAG_ROOT.'/services/ConversationDb.php';
    if (!ConversationDb::isConfigured()) diagFail('database_not_configured');
    $pdo = ConversationDb::connection();
    // Must precede the runtime/ProjectConfig dependency graph.
    $pdo->exec('START TRANSACTION READ ONLY');
    require_once DIAG_ROOT.'/services/ChannelPostRuntime.php';
    require_once DIAG_ROOT.'/integrations/ChannelPostApiTransport.php';
    $identity = ChannelPostRuntime::identity('max');
    if (!$identity) diagFail('canonical_identity_unavailable');
    $me = diagProviderGet('/me');
    if (($me['is_bot'] ?? false) !== true || ($me['username'] ?? '') !== $identity['bot_key']) diagFail('provider_identity_mismatch');

    $q = $pdo->prepare('SELECT pc.id,pc.channel_id,pc.bot_url,pc.button_text,pc.is_enabled,pc.config_version FROM post_channels pc JOIN projects p ON p.id=pc.project_id WHERE p.project_key=? AND p.is_active=1 AND pc.platform=\'max\' AND pc.bot_key=? ORDER BY pc.id LIMIT 9');
    $q->execute([$identity['project_key'], $identity['bot_key']]);
    $channels = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($channels) > DIAG_MAX_CHANNELS) diagFail('configured_channel_limit');
    foreach (DIAG_TARGETS as $target) {
        try { $receipts[] = diagDiagnose($pdo, $channels, $target); }
        catch (Throwable $e) { $receipts[] = ['url' => $target['url'], 'ok' => false, 'reason' => diagSafeReason($e)]; }
    }
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $receipts = array_map(static fn(array $t): array => ['url' => $t['url'], 'ok' => false, 'reason' => diagSafeReason($e)], DIAG_TARGETS);
}
if (ob_get_level()) ob_end_clean();
$ok = count($receipts) === 2 && !in_array(false, array_column($receipts, 'ok'), true);
echo json_encode([
    'schema' => 'anytour.reported_max_posts.readonly.v1', 'ok' => $ok,
    'expected_installed_sha' => DIAG_EXPECTED_SHA, 'generated_at_utc' => gmdate('c'),
    'database_writes' => 0, 'provider_writes' => 0, 'provider_gets' => $getCount,
    'limits' => ['configured_channels' => DIAG_MAX_CHANNELS, 'provider_gets' => DIAG_MAX_GETS, 'history_pages_per_target' => 1, 'history_page_size' => 100, 'history_days' => DIAG_HISTORY_DAYS],
    'targets' => $receipts,
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
exit($ok ? 0 : 1);

function diagFail(string $reason): void { throw new ReportedMaxPostDiagnosticFailure($reason); }
function diagSafeReason(Throwable $e): string { return $e instanceof ReportedMaxPostDiagnosticFailure ? $e->getMessage() : 'diagnostic_unavailable'; }
function diagProviderGet(string $path, array $query = []): array
{
    global $getCount;
    if (++$getCount > DIAG_MAX_GETS) diagFail('provider_get_limit');
    $r = ChannelPostApiTransport::buttonRequest('max', 'GET', $path, $query, null);
    if (!empty($r['transport_error']) || (int)($r['http'] ?? 0) !== 200 || !is_array($r['data'] ?? null)) diagFail('provider_read_unavailable');
    return $r['data'];
}
function diagChat(array $channel): array
{
    global $chatCache;
    $id = (string)$channel['channel_id'];
    if (!isset($chatCache[$id])) {
        $c = diagProviderGet('/chats/'.$id);
        if (($c['type'] ?? '') !== 'channel' || (string)($c['chat_id'] ?? '') !== $id) diagFail('provider_channel_mismatch');
        // Retain only mapping evidence, never the optional pinned message.
        $chatCache[$id] = ['link' => is_string($c['link'] ?? null) ? rtrim($c['link'], '/') : ''];
    }
    return $chatCache[$id];
}
function diagSavedMatches(PDO $pdo, array $channels, string $value, bool $suffix): array
{
    if (!$channels) return [];
    $ids = array_map(static fn(array $c): int => (int)$c['id'], $channels);
    $url = "JSON_UNQUOTE(JSON_EXTRACT(vm.snapshot_json,'$.raw_body.url'))";
    $predicate = $suffix ? 'BINARY RIGHT('.$url.',?)=BINARY ?' : 'BINARY '.$url.'=BINARY ?';
    // Exact URL (or literal suffix within one already resolved channel) only.
    // LIMIT 2 rejects ambiguity without loading an unrelated post's contents.
    $sql = 'SELECT pp.id AS publication_id,pp.post_channel_id,pp.external_message_id FROM post_publications pp WHERE pp.post_channel_id IN ('.implode(',', array_fill(0, count($ids), '?')).') AND EXISTS (SELECT 1 FROM post_versions vm WHERE vm.publication_id=pp.id AND '.$predicate.') LIMIT 2';
    $args = $ids;
    if ($suffix) $args[] = strlen($value);
    $args[] = $value;
    $q = $pdo->prepare($sql); $q->execute($args);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1) diagFail('saved_target_ambiguous');
    return $rows;
}
function diagResolveChannel(array $channels, array $target, ?array $saved): array
{
    if (isset($target['channel_id'])) {
        foreach ($channels as $c) if ((string)$c['channel_id'] === $target['channel_id']) return $c;
        diagFail('target_channel_not_configured');
    }
    // An exact authenticated saved permalink supplies a tentative ID; a fresh
    // exact message URL or current Chat.link must independently confirm it.
    if ($saved) foreach ($channels as $c) if ((int)$c['id'] === (int)$saved['post_channel_id']) return $c;
    $matches = [];
    foreach ($channels as $c) if (diagChat($c)['link'] === $target['alias']) $matches[] = $c;
    if (count($matches) !== 1) diagFail('target_alias_not_unique');
    return $matches[0];
}
function diagValidMessage(array $m, array $channel, ?string $mid = null): bool
{
    $id = $m['body']['mid'] ?? null; $stamp = $m['timestamp'] ?? null;
    return ($m['recipient']['chat_type'] ?? '') === 'channel'
        && (string)($m['recipient']['chat_id'] ?? '') === (string)$channel['channel_id']
        && is_string($id) && (bool)preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D', $id) && ($mid === null || $id === $mid)
        && (is_int($stamp) || is_string($stamp)) && (bool)preg_match('/\A[1-9][0-9]{0,15}\z/D', (string)$stamp);
}
function diagMatchesUrl(array $m, array $channel, array $target): bool
{
    if (!diagValidMessage($m, $channel)) return false;
    $url = $m['url'] ?? null;
    if (!is_string($url)) return false;
    if ($url === $target['url']) return true;
    $snapshot = ChannelPostIncomingAdapter::maxSnapshot($m);
    if (!$snapshot || ($snapshot['post_url'] ?? '') !== $url || substr($url, -strlen('/'.$target['code'])) !== '/'.$target['code']) return false;
    return isset($target['channel_id']) || diagChat($channel)['link'] === $target['alias'];
}
function diagDiagnose(PDO $pdo, array $channels, array $target): array
{
    $exact = diagSavedMatches($pdo, $channels, $target['url'], false);
    $saved = $exact[0] ?? null;
    $channel = diagResolveChannel($channels, $target, $saved);
    if ($saved && (int)$saved['post_channel_id'] !== (int)$channel['id']) diagFail('saved_channel_mismatch');
    if (!$saved) $saved = diagSavedMatches($pdo, [$channel], '/'.$target['code'], true)[0] ?? null;
    $lookup = $exact ? 'saved_exact_url' : 'saved_resolved_channel_permalink';
    if ($saved) {
        $mid = (string)$saved['external_message_id'];
    } else {
        $lookup = 'provider_bounded_recent_page';
        $before = (int)floor(microtime(true) * 1000); $after = max(1, $before - DIAG_HISTORY_DAYS * 86400000);
        $page = diagProviderGet('/messages', ['chat_id' => (string)$channel['channel_id'], 'from' => $before, 'to' => $after, 'count' => 100]);
        $messages = $page['messages'] ?? null;
        if (!is_array($messages) || !array_is_list($messages) || count($messages) > 100) diagFail('provider_page_invalid');
        $matches = [];
        foreach ($messages as $m) {
            if (!is_array($m) || !diagValidMessage($m, $channel) || (int)$m['timestamp'] < $after || (int)$m['timestamp'] > $before) diagFail('provider_page_identity_mismatch');
            if (diagMatchesUrl($m, $channel, $target)) $matches[] = $m;
        }
        if (count($matches) !== 1) diagFail('target_not_unique_in_bounded_page');
        $mid = $matches[0]['body']['mid'];
    }
    if (!preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D', $mid)) diagFail('saved_message_id_invalid');
    $message = diagProviderGet('/messages/'.rawurlencode($mid));
    if (!diagValidMessage($message, $channel, $mid) || !diagMatchesUrl($message, $channel, $target)) diagFail('fresh_target_identity_mismatch');
    $snapshot = ChannelPostIncomingAdapter::maxSnapshot($message);
    if (!$snapshot) diagFail('fresh_snapshot_invalid');
    $q = $pdo->prepare('SELECT pp.id AS publication_id,pp.current_version_id,pp.start_payload,pv.version_number,pv.state,pv.error_reason,pv.write_attempts,pv.content_hash,(pv.claim_token IS NOT NULL OR pv.claimed_at IS NOT NULL) AS claim_exists,EXISTS(SELECT 1 FROM post_versions older WHERE older.publication_id=pp.id AND older.id<>pp.current_version_id AND (older.state IN (\'unknown\',\'claimed\',\'write_started\') OR older.claim_token IS NOT NULL OR older.claimed_at IS NOT NULL)) AS older_unsafe_version_exists FROM post_publications pp LEFT JOIN post_versions pv ON pv.id=pp.current_version_id AND pv.publication_id=pp.id WHERE pp.post_channel_id=? AND pp.external_message_id=? LIMIT 2');
    $q->execute([(int)$channel['id'], $mid]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1) diagFail('registry_target_ambiguous');
    $row = $rows[0] ?? null;
    $registry = null; $owned = null; $onlyOwned = null;
    if ($row) {
        $registry = ['publication_id' => (int)$row['publication_id'], 'current_version_id' => (int)$row['current_version_id'], 'version_number' => (int)$row['version_number'], 'state' => diagSafeTag($row['state']), 'error_reason' => diagSafeTag($row['error_reason']), 'write_attempts' => (int)$row['write_attempts'], 'claim_exists' => (bool)$row['claim_exists'], 'older_unsafe_version_exists' => (bool)$row['older_unsafe_version_exists'], 'content_hash' => diagSafeHash($row['content_hash'])];
        $buttonUrl = (string)$channel['bot_url'].'?start='.(string)$row['start_payload'];
        $owned = ChannelPostIncomingAdapter::containsButton($snapshot, $buttonUrl, (string)$channel['button_text']);
        $onlyOwned = ChannelPostIncomingAdapter::containsOnlyButton($snapshot, $buttonUrl, (string)$channel['button_text']);
    }
    $counts = ['image_count' => 0, 'video_count' => 0, 'other_media_count' => 0, 'media_count' => 0, 'video_tokens_valid' => [], 'image_tokens_present' => [], 'keyboard_button_count' => 0];
    foreach ($message['body']['attachments'] ?? [] as $a) {
        if (!is_array($a)) diagFail('fresh_attachment_invalid');
        if (($a['type'] ?? '') === 'inline_keyboard') {
            foreach ((array)($a['payload']['buttons'] ?? []) as $buttons) $counts['keyboard_button_count'] += is_array($buttons) ? count($buttons) : 0;
            continue;
        }
        $counts['media_count']++; $token = $a['payload']['token'] ?? null;
        if (($a['type'] ?? '') === 'video') { $counts['video_count']++; $counts['video_tokens_valid'][] = is_string($token) && (bool)preg_match('/\A[A-Za-z0-9_-]+\z/D', $token); }
        elseif (($a['type'] ?? '') === 'image') { $counts['image_count']++; $counts['image_tokens_present'][] = is_string($token) && $token !== ''; }
        else $counts['other_media_count']++;
    }
    return ['url' => $target['url'], 'ok' => true, 'lookup' => $lookup, 'channel_id' => (string)$channel['channel_id'], 'external_message_id' => $mid, 'published_ms' => (int)$message['timestamp'], 'channel_enabled' => (bool)$channel['is_enabled'], 'config_version' => (int)$channel['config_version'], 'media' => $counts, 'owned_button_present' => $owned, 'only_owned_button' => $onlyOwned, 'installed_capability_reason' => diagSafeTag($snapshot['metadata']['capability_reason'] ?? ''), 'fresh_content_hash_installed_adapter' => ChannelPostIncomingAdapter::contentFingerprint($snapshot), 'registry' => $registry];
}
function diagSafeTag($value): string { return is_string($value) && preg_match('/\A[a-z0-9_]{0,100}\z/D', $value) ? $value : 'unrecognized'; }
function diagSafeHash($value): ?string { return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) ? $value : null; }
