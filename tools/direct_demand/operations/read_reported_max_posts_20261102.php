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
];
const DIAG_MAX_CHANNELS = 1;
const DIAG_MAX_GETS = 4;
const DIAG_HISTORY_HOURS = 24;
final class ReportedMaxPostDiagnosticFailure extends RuntimeException {}

$pdo = null;
$getCount = 0;
$diagShortProbe = null;
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

    $q = $pdo->prepare('SELECT pc.id,pc.channel_id,pc.bot_url,pc.button_text,pc.is_enabled,pc.config_version FROM post_channels pc JOIN projects p ON p.id=pc.project_id WHERE p.project_key=? AND p.is_active=1 AND pc.platform=\'max\' AND pc.bot_key=? AND pc.channel_id=? ORDER BY pc.id LIMIT 2');
    $q->execute([$identity['project_key'], $identity['bot_key'], DIAG_TARGETS[0]['channel_id']]);
    $channels = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($channels) > DIAG_MAX_CHANNELS) diagFail('configured_channel_limit');
    foreach (DIAG_TARGETS as $target) {
        try { $receipts[] = diagDiagnose($pdo, $channels, $target); }
        catch (Throwable $e) { $receipts[] = ['url' => $target['url'], 'ok' => false, 'reason' => diagSafeReason($e), 'short_id_probe' => $diagShortProbe]; }
    }
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $receipts = array_map(static fn(array $t): array => ['url' => $t['url'], 'ok' => false, 'reason' => diagSafeReason($e)], DIAG_TARGETS);
}
if (ob_get_level()) ob_end_clean();
$ok = count($receipts) === 1 && !in_array(false, array_column($receipts, 'ok'), true);
echo json_encode([
    'schema' => 'anytour.reported_max_first_post.readonly.v2', 'ok' => $ok,
    'expected_installed_sha' => DIAG_EXPECTED_SHA, 'generated_at_utc' => gmdate('c'),
    'database_writes' => 0, 'provider_writes' => 0, 'provider_gets' => $getCount,
    'limits' => ['configured_channels' => DIAG_MAX_CHANNELS, 'provider_gets' => DIAG_MAX_GETS, 'history_pages_per_target' => 1, 'history_page_size' => 10, 'history_hours' => DIAG_HISTORY_HOURS],
    'targets' => $receipts,
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
exit($ok ? 0 : 1);

function diagFail(string $reason): void { throw new ReportedMaxPostDiagnosticFailure($reason); }
function diagSafeReason(Throwable $e): string { return $e instanceof ReportedMaxPostDiagnosticFailure ? $e->getMessage() : 'diagnostic_unavailable'; }
function diagProviderResponse(string $path, array $query = []): array
{
    global $getCount;
    if ($getCount >= DIAG_MAX_GETS) diagFail('provider_get_limit');
    $getCount++;
    return ChannelPostApiTransport::buttonRequest('max', 'GET', $path, $query, null);
}
function diagProviderGet(string $path, array $query = []): array
{
    $r = diagProviderResponse($path, $query);
    if (!empty($r['transport_error']) || (int)($r['http'] ?? 0) !== 200 || !is_array($r['data'] ?? null)) diagFail('provider_read_unavailable');
    return $r['data'];
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
function diagResolveChannel(array $channels, array $target): array
{
    foreach ($channels as $c) if ((string)$c['channel_id'] === $target['channel_id']) return $c;
    diagFail('target_channel_not_configured');
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
    return true;
}
function diagDiagnose(PDO $pdo, array $channels, array $target): array
{
    global $diagShortProbe;
    $channel = diagResolveChannel($channels, $target);
    $short = diagProviderResponse('/messages/'.$target['code']);
    $shortHttp = (int)($short['http'] ?? 0); $resolvedStamp = null; $mid = '';
    if (empty($short['transport_error']) && in_array($shortHttp, [400, 404], true)) {
        $diagShortProbe = ['state' => 'short_id_not_resolved', 'http' => $shortHttp];
    } elseif (empty($short['transport_error']) && $shortHttp === 200 && is_array($short['data'] ?? null)) {
        $resolved = $short['data'];
        if (!diagValidMessage($resolved, $channel) || !preg_match('/\Amid\.[A-Za-z0-9_.-]{1,187}\z/D', $resolved['body']['mid']) || !diagLiteralUrlConsistent($resolved, $target)) diagFail('short_id_resolution_mismatch');
        $mid = $resolved['body']['mid']; $resolvedStamp = (int)$resolved['timestamp'];
        $diagShortProbe = ['state' => 'provider_resolved', 'http' => 200];
        $lookup = 'short_id_provider_resolution';
    } else diagFail('short_id_read_unavailable');

    if ($mid === '') {
        $exact = diagSavedMatches($pdo, [$channel], $target['url'], false);
        $saved = $exact[0] ?? (diagSavedMatches($pdo, [$channel], '/'.$target['code'], true)[0] ?? null);
        if ($saved) { $mid = (string)$saved['external_message_id']; $lookup = $exact ? 'saved_exact_url' : 'saved_resolved_channel_permalink'; }
    }
    if ($mid === '') {
        $lookup = 'provider_bounded_recent_page';
        $before = (int)floor(microtime(true) * 1000); $after = max(1, $before - DIAG_HISTORY_HOURS * 3600000);
        $page = diagProviderGet('/messages', ['chat_id' => (string)$channel['channel_id'], 'from' => $before, 'to' => $after, 'count' => 10]);
        $messages = $page['messages'] ?? null;
        if (!is_array($messages) || !array_is_list($messages) || count($messages) > 10) diagFail('provider_page_invalid');
        $matches = []; $candidates = []; $missingUrls = 0; $rejectedUrls = 0;
        foreach ($messages as $m) {
            if (!is_array($m) || !diagValidMessage($m, $channel) || (int)$m['timestamp'] < $after || (int)$m['timestamp'] > $before) diagFail('provider_page_identity_mismatch');
            if (diagMatchesUrl($m, $channel, $target)) $matches[] = $m;
            $meta = diagMessageMetadata($m);
            if ($meta['permalink']['state'] === 'missing') $missingUrls++;
            if (!$meta['adapter_preserves_permalink'] && $meta['permalink']['state'] !== 'missing') $rejectedUrls++;
            $candidates[] = $meta;
        }
        if (count($matches) !== 1) return [
            'url' => $target['url'], 'ok' => false, 'reason' => count($matches) === 0 ? 'target_not_found_in_bounded_page' : 'target_ambiguous_in_bounded_page',
            'channel_id' => (string)$channel['channel_id'], 'config_version' => (int)$channel['config_version'], 'short_id_probe' => $diagShortProbe,
            'page' => ['from_ms' => $before, 'to_ms' => $after, 'count' => count($messages), 'match_count' => count($matches), 'missing_url_count' => $missingUrls, 'adapter_rejected_url_count' => $rejectedUrls],
            'candidates' => $candidates,
        ];
        $mid = $matches[0]['body']['mid'];
    }
    if (!preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D', $mid)) diagFail('saved_message_id_invalid');
    $message = diagProviderGet('/messages/'.rawurlencode($mid));
    if (!diagValidMessage($message, $channel, $mid)) diagFail('fresh_target_identity_mismatch');
    if ($resolvedStamp !== null ? ((int)$message['timestamp'] !== $resolvedStamp || !diagLiteralUrlConsistent($message, $target)) : !diagMatchesUrl($message, $channel, $target)) diagFail('fresh_target_identity_mismatch');
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
    return ['url' => $target['url'], 'ok' => true, 'lookup' => $lookup, 'short_id_probe' => $diagShortProbe, 'channel_id' => (string)$channel['channel_id'], 'external_message_id' => $mid, 'published_ms' => (int)$message['timestamp'], 'channel_enabled' => (bool)$channel['is_enabled'], 'config_version' => (int)$channel['config_version'], 'media' => diagMediaCounts($message), 'owned_button_present' => $owned, 'only_owned_button' => $onlyOwned, 'installed_capability_reason' => diagSafeTag($snapshot['metadata']['capability_reason'] ?? ''), 'fresh_content_hash_installed_adapter' => ChannelPostIncomingAdapter::contentFingerprint($snapshot), 'registry' => $registry];
}
function diagMediaCounts(array $message): array
{
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
    return $counts;
}
function diagMessageMetadata(array $message): array
{
    $snapshot = ChannelPostIncomingAdapter::maxSnapshot($message); $url = $message['url'] ?? null;
    return ['external_message_id' => $message['body']['mid'], 'published_ms' => (int)$message['timestamp'], 'permalink' => diagSafePermalink($url), 'adapter_preserves_permalink' => is_string($url) && $url !== '' && $snapshot !== null && ($snapshot['post_url'] ?? '') === $url, 'media' => diagMediaCounts($message)];
}
function diagSafePermalink($url): array
{
    if ($url === null || $url === '') return ['state' => 'missing', 'url' => null];
    if (!is_string($url) || strlen($url) > 2048) return ['state' => 'invalid', 'url' => null];
    $p = parse_url($url);
    if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || ($p['host'] ?? '') !== 'max.ru' || isset($p['user']) || isset($p['pass']) || isset($p['port'])) return ['state' => 'unsafe_origin', 'url' => null];
    if (!preg_match('/\A\/(?:c\/-?[1-9][0-9]{0,19}|[A-Za-z][A-Za-z0-9_]{0,63})\/[A-Za-z0-9_-]{1,100}\/?\z/D', $p['path'] ?? '')) return ['state' => 'unrecognized_path', 'url' => null];
    // Query and fragment values are never exported, even on the trusted host.
    $redacted = isset($p['query']) || isset($p['fragment']);
    return ['state' => $redacted ? 'query_or_fragment_redacted' : 'observed', 'url' => 'https://max.ru'.$p['path']];
}
function diagLiteralUrlConsistent(array $message, array $target): bool
{
    $url = $message['url'] ?? null;
    if ($url === null || $url === '') return true;
    $safe = diagSafePermalink($url);
    // A missing/unknown link shape is not used as identity evidence. A known
    // canonical link must not contradict the provider's direct resolution.
    if ($safe['url'] === null) return $safe['state'] === 'unrecognized_path';
    $path = (string)parse_url($safe['url'], PHP_URL_PATH);
    if (basename(rtrim($path, '/')) !== $target['code']) return false;
    if (preg_match('/\A\/c\/(-?[1-9][0-9]{0,19})\//', $path, $m) && $m[1] !== $target['channel_id']) return false;
    return true;
}
function diagSafeTag($value): string { return is_string($value) && preg_match('/\A[a-z0-9_]{0,100}\z/D', $value) ? $value : 'unrecognized'; }
function diagSafeHash($value): ?string { return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) ? $value : null; }
