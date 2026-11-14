<?php
declare(strict_types=1);
// One read of the original TV512 / collector43520564593 incident. No recovery or writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');

const INCIDENT_READ_ROOT = '/var/www/anytoour/data/channel-post-operations';
const INCIDENT_READ_RUNTIME = '/var/www/anytoour/data/www/app.anytoour.ru';
const INCIDENT_READ_INSTALLED = '9a8c8dc93e2abb102be663e48b93a097d98927ea';
const INCIDENT_READ_TV = 512;
const INCIDENT_READ_COLLECTOR = '43520564593';
const INCIDENT_READ_LINE_CAP = 2000000;
const INCIDENT_READ_PINS = [
    'integrations/ChannelPostApiTransport.php' => '300634d77cb9fddf51adf9869974f956cca6e3f9',
    'migrations/033_channel_post_context.sql' => '0530458030a4bfe6b6cc55d1e5e441745c29b947',
    'migrations/036_channel_post_member_delivery.sql' => '1bda463e52b33130ad12752ddef4ee5c92699c05',
    'migrations/040_channel_post_reconciliation.sql' => '0f24b838eacd45333c5ade9a2991d060488c6035',
    'services/ChannelPostAdminService.php' => 'b80eefc801054bfe6fdb58ec345ecdf687b2e89a',
    'services/ChannelPostAutomationService.php' => 'f5975ab95de5f45e087eb7a0f768f566c5bca648',
    'services/ChannelPostButtonTransport.php' => '7b6711f1de63000346d8e5789f2dbb7f725535b8',
    'services/ChannelPostEntryService.php' => 'f0e04e9b4e0ed6859ced96279cbf0619931596e6',
    'services/ChannelPostMaxAlbumRecovery.php' => 'de266cd068587cc3b2ff83f308a966ea66887a04',
    'services/ChannelPostMediaService.php' => 'f794efd0f6f24df916e18909ff6281b0bc928433',
    'services/ChannelPostMemberDeliveryService.php' => '20c1d7984898b2a5996a5f9e20e4d2d8c20b4778',
    'services/ChannelPostReconciliationService.php' => '43133f576845c14446102dedfffb7f0de2d4ba1c',
    'services/ChannelPostReconciliationState.php' => 'd9583ceca80d1424a44ade3561c21282a23a52f0',
    'services/ChannelPostRegistryService.php' => 'db8181426091a685a79ee55991cde22671f0d9dd',
    'services/ChannelPostRuntime.php' => '2f1dd9b77474fdbaa92f0402f3b0aa170c595474',
    'services/ChannelPostTelegramAlbum.php' => 'faa1a14bc279a4af33936282126bf0d46e343040',
    'services/ChannelPostWebhookConfigService.php' => 'd5fdcb056088f1e3a541b4046325d97eef6417d9',
    'services/ConversationDb.php' => 'bb6281faf55aca7beeae7f8a57389d19751936f7',
    'services/MaxTlsConfig.php' => '032c0211ee3f39c6f0d27c669abd5b467e321dea',
    'services/MaxTransport.php' => '2cb4f8786b4704a8e62a3a9c40dc2cc73dad6159',
    'services/ProjectConfig.php' => '63c17c8103412f8cff8cdcc4bbb062e744106af9',
    'services/ProjectConfiguration.php' => '07019d473c588e3386ace42977db93092e8112e3',
];

function incident_need(bool $ok, string $code): void {
    if (!$ok) throw new RuntimeException($code);
}
function incident_json($value): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function incident_code($value): ?string {
    return is_string($value) && preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D', $value) === 1 ? $value : null;
}
function incident_hash($value): ?string {
    return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1 ? $value : null;
}
function incident_stamp($value): string {
    incident_need(is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D', $value) === 1, 'incident_timestamp_invalid');
    $parsed = new DateTimeImmutable($value);
    $errors = DateTimeImmutable::getLastErrors();
    incident_need($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0), 'incident_timestamp_invalid');
    return $value;
}
function incident_public_url($value, string $platform): ?string {
    $pattern = $platform === 'max'
        ? '~\Ahttps://max\.ru/channel_anytour/[A-Za-z0-9_-]+\z~D'
        : '~\Ahttps://t\.me/any_toour/[1-9][0-9]*\z~D';
    return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
}
function incident_identity($value): ?string {
    return is_string($value) && preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D', $value) === 1 ? $value : null;
}
/** --no-optional-locks prevents git status from refreshing the production index. */
function incident_git(array $arguments, int $cap = 32768): string {
    $pipes = [];
    $process = proc_open(array_merge(['git', '--no-optional-locks', '-C', INCIDENT_READ_RUNTIME], $arguments),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes, INCIDENT_READ_RUNTIME, null, ['bypass_shell' => true]);
    incident_need(is_resource($process), 'incident_git_start_failed');
    $raw = stream_get_contents($pipes[1], $cap + 1);
    fclose($pipes[1]);
    $exit = proc_close($process);
    incident_need($exit === 0 && is_string($raw) && strlen($raw) <= $cap, 'incident_git_read_failed');
    return trim($raw);
}
/** Existing regular files, rb and shared nonblocking locks only. All locks survive until output. */
function incident_journal(string $path, int $cap, callable $inspect): array {
    global $incidentLocks;
    incident_need(!is_link(dirname($path)) && realpath(dirname($path)) === dirname($path)
        && !is_link($path) && is_file($path) && realpath($path) === $path, 'incident_journal_path_invalid');
    $before = lstat($path);
    incident_need(is_array($before) && ($before['mode'] & 0170000) === 0100000
        && $before['size'] > 0 && $before['size'] <= $cap, 'incident_journal_size_invalid');
    $stream = fopen($path, 'rb');
    incident_need(is_resource($stream), 'incident_journal_open_failed');
    $incidentLocks[] = ['stream' => $stream, 'path' => $path, 'before' => $before];
    incident_need(flock($stream, LOCK_SH | LOCK_NB), 'incident_original_operation_still_locked');
    $stat = fstat($stream);
    incident_need(is_array($stat) && $stat['ino'] === $before['ino'] && $stat['dev'] === $before['dev']
        && $stat['size'] === $before['size'], 'incident_journal_identity_changed');
    $hash = hash_init('sha256'); $bytes = 0; $lines = 0; $last = null;
    while (!feof($stream)) {
        $line = fgets($stream, INCIDENT_READ_LINE_CAP + 2);
        if ($line === false) { incident_need(feof($stream), 'incident_journal_read_failed'); break; }
        $bytes += strlen($line);
        incident_need($bytes <= $cap && strlen($line) <= INCIDENT_READ_LINE_CAP
            && str_ends_with($line, "\n"), 'incident_journal_partial_or_oversize_line');
        hash_update($hash, $line);
        try { $entry = json_decode($line, true, 64, JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { throw new RuntimeException('incident_journal_json_invalid'); }
        incident_need(is_array($entry), 'incident_journal_shape_invalid');
        $inspect($entry, ++$lines); $last = $entry;
    }
    incident_need($bytes === $stat['size'], 'incident_journal_read_size_changed');
    incident_check_locks();
    return ['sha256' => hash_final($hash), 'bytes' => $bytes, 'line_count' => $lines,
        'complete_jsonl' => true, 'last' => $last];
}
function incident_check_locks(): void {
    global $incidentLocks;
    foreach ($incidentLocks as $item) {
        $after = fstat($item['stream']);
        clearstatcache(true, $item['path']); $named = lstat($item['path']);
        incident_need(is_array($after) && is_array($named) && !is_link($item['path'])
            && realpath($item['path']) === $item['path'], 'incident_journal_changed_during_read');
        foreach (['ino', 'dev', 'size', 'mtime', 'ctime'] as $field) {
            incident_need($after[$field] === $item['before'][$field] && $named[$field] === $item['before'][$field],
                'incident_journal_changed_during_read');
        }
    }
}
/** Allow only the capability check's fixed codes and numeric HTTP receipts, never provider bodies. */
function incident_capability(array $cap): array {
    $reasons = ['capability_verified', 'unsupported_platform', 'invalid_channel_id', 'canonical_bot_url_mismatch',
        'invalid_expected_public_channel_url', 'canonical_bot_identity_mismatch', 'membership_identity_mismatch',
        'channel_admin_required', 'channel_read_permission_required', 'channel_edit_permission_required',
        'provider_read_failed', 'channel_type_mismatch', 'public_channel_identity_mismatch', 'verification_failure',
        'request_budget_exhausted'];
    incident_need(in_array($cap['state'] ?? null, ['ready', 'unknown', 'requires_attention'], true)
        && in_array($cap['reason'] ?? null, $reasons, true) && is_array($cap['receipt'] ?? null), 'incident_capability_shape_changed');
    $r = $cap['receipt'];
    incident_need(($r['write_attempts'] ?? null) === 0 && is_int($r['request_count'] ?? null)
        && $r['request_count'] >= 0 && $r['request_count'] <= 3 && is_array($r['http_results'] ?? null)
        && count($r['http_results']) === $r['request_count'], 'incident_capability_budget_changed');
    $http = [];
    foreach ($r['http_results'] as $result) {
        incident_need(is_array($result) && in_array($result['operation'] ?? null, ['bot_identity', 'channel_rights', 'channel_type'], true)
            && is_int($result['http'] ?? null) && $result['http'] >= 0 && $result['http'] <= 599
            && is_bool($result['transport_error'] ?? null), 'incident_capability_receipt_invalid');
        $http[] = ['operation' => $result['operation'], 'http' => $result['http'], 'transport_error' => $result['transport_error']];
    }
    return ['state' => $cap['state'], 'reason' => $cap['reason'], 'request_count' => $r['request_count'],
        'write_attempts' => 0, 'http_results' => $http];
}

$incidentLocks = [];
$out = ['ok' => false, 'mode' => 'read_only_monitor_price_incident_20261114', 'checked_at' => gmdate('c'),
    'expected_tourvisor_hotel_id' => INCIDENT_READ_TV, 'expected_collector_run_id' => INCIDENT_READ_COLLECTOR,
    'filesystem_writes' => 0, 'database_writes' => 0, 'message_writes' => 0, 'supplier_requests' => 0,
    'provider_requests' => 0, 'channel_rows_read' => 0, 'publication_identity_lookups' => 0, 'publication_metadata_reads' => 0,
    'historical_capability_reason' => 'not_recorded_by_original_editor', 'current_registry' => []];

try {
    incident_need(count($argv) === 1, 'incident_arguments_invalid');
    incident_need(!is_link(INCIDENT_READ_ROOT) && realpath(INCIDENT_READ_ROOT) === INCIDENT_READ_ROOT
        && !is_link(INCIDENT_READ_RUNTIME) && realpath(INCIDENT_READ_RUNTIME) === INCIDENT_READ_RUNTIME, 'incident_root_invalid');
    incident_need(chdir(INCIDENT_READ_RUNTIME), 'incident_runtime_unavailable');
    $out['runtime_git_head'] = incident_git(['rev-parse', '--verify', 'HEAD']);
    incident_need($out['runtime_git_head'] === INCIDENT_READ_INSTALLED, 'incident_installed_source_changed');
    incident_need(incident_git(['status', '--porcelain', '--untracked-files=no']) === '', 'incident_tracked_tree_changed');
    incident_need(count(INCIDENT_READ_PINS) === 22, 'incident_pin_count_invalid');
    foreach (INCIDENT_READ_PINS as $path => $pin) {
        incident_need(!is_link(INCIDENT_READ_RUNTIME . '/' . $path)
            && realpath(INCIDENT_READ_RUNTIME . '/' . $path) === INCIDENT_READ_RUNTIME . '/' . $path, 'incident_pinned_path_invalid');
    }
    $actualPins = explode("\n", incident_git(array_merge(['hash-object', '--'], array_keys(INCIDENT_READ_PINS))));
    incident_need($actualPins === array_values(INCIDENT_READ_PINS), 'incident_publishing_surface_changed');
    $out['runtime_tracked_clean'] = true; $out['runtime_pin_count'] = count($actualPins);
    $out['runtime_pins_sha256'] = hash('sha256', incident_json(INCIDENT_READ_PINS));

    $firstCycle = null; $cycleFirstLine = null; $globalRecent = [];
    $global = incident_journal(INCIDENT_READ_ROOT . '/monitor-price-after-collection-v1.jsonl', 100000000,
        static function (array $entry, int $line) use (&$firstCycle, &$cycleFirstLine, &$globalRecent): void {
            incident_need(($entry['schema'] ?? null) === 1 && array_key_exists('pending', $entry)
                && is_array($entry['completed'] ?? null), 'incident_global_schema_invalid');
            if (($entry['nightly_cycle']['collector_run_id'] ?? null) === INCIDENT_READ_COLLECTOR) {
                $firstCycle = $firstCycle ?? $entry['nightly_cycle']; $cycleFirstLine = $cycleFirstLine ?? $line;
                incident_need($entry['nightly_cycle'] === $firstCycle, 'incident_original_cycle_changed');
            }
            $globalRecent[] = ['line' => $line, 'pending' => $entry['pending']];
            if (count($globalRecent) > 6) array_shift($globalRecent);
        });
    $state = $global['last']; unset($global['last']);
    $completedJson = incident_json($state['completed']);
    $out['global_journal'] = $global + ['completed_count' => count($state['completed']),
        'completed_tuple_sha256' => hash('sha256', $completedJson), 'completed_tuple_json_bytes' => strlen($completedJson)];
    $pending = $state['pending']; $cycle = $state['nightly_cycle'] ?? null;
    incident_need(is_array($pending) && ($pending['tourvisor_hotel_id'] ?? null) === INCIDENT_READ_TV
        && ($pending['phase'] ?? null) === 'post_started', 'incident_pending_target_changed');
    $operation = $pending['operation'] ?? null;
    incident_need(is_string($operation) && preg_match('/\Acommercial-monitor-price-h512-[a-f0-9]{64}\z/D', $operation) === 1,
        'incident_pending_operation_invalid');
    incident_need(is_array($pending['child_failure'] ?? null)
        && ($pending['child_failure']['exit_code'] ?? null) === 2
        && ($pending['child_failure']['error'] ?? null) === 'provider_channel_rights_changed', 'incident_pending_failure_changed');
    incident_need(is_array($cycle) && $cycle === $firstCycle && ($cycle['collector_run_id'] ?? null) === INCIDENT_READ_COLLECTOR
        && ($cycle['state'] ?? null) === 'started' && ($cycle['night_date'] ?? null) === '2026-11-14', 'incident_current_cycle_changed');
    $out['pending'] = ['tourvisor_hotel_id' => INCIDENT_READ_TV, 'operation' => $operation, 'phase' => 'post_started',
        'at' => incident_stamp($pending['at'] ?? null), 'child_failure' => ['exit_code' => 2, 'error' => 'provider_channel_rights_changed'],
        'tuple_sha256' => hash('sha256', incident_json($pending))];
    $out['cycle'] = ['collector_run_id' => INCIDENT_READ_COLLECTOR, 'state' => 'started', 'night_date' => '2026-11-14',
        'collection_started_at' => incident_stamp($cycle['collection_started_at'] ?? null),
        'started_at' => incident_stamp($cycle['started_at'] ?? null),
        'budget_seconds' => $cycle['budget_seconds'] ?? null, 'finish_reserve_seconds' => $cycle['finish_reserve_seconds'] ?? null,
        'first_journal_line' => $cycleFirstLine, 'tuple_sha256' => hash('sha256', incident_json($cycle))];
    $out['global_journal']['recent_same_pending'] = [];
    foreach ($globalRecent as $recent) {
        if (($recent['pending']['operation'] ?? null) === $operation) {
            $out['global_journal']['recent_same_pending'][] = ['line' => $recent['line'],
                'phase' => incident_code($recent['pending']['phase'] ?? null),
                'child_failure_present' => is_array($recent['pending']['child_failure'] ?? null)];
        }
    }

    $firstChild = null; $allSame = true; $anyPost = false; $anyConfig = false; $anyComplete = false; $anyTerminal = false;
    $child = incident_journal(INCIDENT_READ_ROOT . '/' . $operation . '/receipt.jsonl', 10000000,
        static function (array $entry, int $line) use ($operation, &$firstChild, &$allSame, &$anyPost, &$anyConfig, &$anyComplete, &$anyTerminal): void {
            incident_need(($entry['operation'] ?? null) === $operation && incident_hash($entry['manifest_hash'] ?? null) !== null
                && is_array($entry['posts'] ?? null) && is_array($entry['configs'] ?? null), 'incident_child_scope_invalid');
            $firstChild = $firstChild ?? $entry; $allSame = $allSame && $entry === $firstChild;
            $anyPost = $anyPost || $entry['posts'] !== []; $anyConfig = $anyConfig || $entry['configs'] !== [];
            $anyComplete = $anyComplete || (array_key_exists('complete', $entry) && $entry['complete'] !== false);
            $anyTerminal = $anyTerminal || array_key_exists('terminal', $entry) || array_key_exists('_terminal_journal_sha256', $entry);
        });
    $original = $child['last']; unset($child['last']);
    $out['original_journal'] = $child + ['operation' => $operation, 'manifest_hash' => $original['manifest_hash'],
        'all_entries_identical' => $allSame, 'any_post_intent_recorded' => $anyPost, 'any_config_intent_recorded' => $anyConfig,
        'any_complete_recorded' => $anyComplete, 'any_terminal_recorded' => $anyTerminal,
        'all_history_no_write' => $allSame && !$anyPost && !$anyConfig && !$anyComplete && !$anyTerminal];
    incident_need($out['original_journal']['all_history_no_write'], 'incident_original_write_or_changed_history');
    $snapshot = $original['monitor_snapshot'] ?? null; $binding = $original['feed_binding'] ?? null;
    incident_need(is_array($snapshot) && ($snapshot['format'] ?? null) === 'anytour-hotel-monitor-prices-v1'
        && ($snapshot['item']['tourvisorHotelId'] ?? null) === INCIDENT_READ_TV && is_array($snapshot['item']['offers'] ?? null)
        && count($snapshot['item']['offers']) === 1 && is_array($binding)
        && ($binding['tourvisor_hotel_id'] ?? null) === INCIDENT_READ_TV, 'incident_snapshot_or_feed_identity_changed');
    $offer = $snapshot['item']['offers'][0];
    incident_need(is_array($offer) && ($offer['recordId'] ?? null) === substr($operation, -64), 'incident_record_identity_changed');
    $generatedAt = incident_stamp($snapshot['generatedAt'] ?? null); $expiresAt = incident_stamp($offer['expiresAt'] ?? null);
    $out['original_journal']['monitor_snapshot'] = ['sha256' => hash('sha256', incident_json($snapshot)),
        'generated_at' => $generatedAt, 'record_id' => $offer['recordId'], 'tourvisor_hotel_id' => INCIDENT_READ_TV,
        'expires_at' => $expiresAt, 'expires_epoch' => (new DateTimeImmutable($expiresAt))->getTimestamp(),
        'expired_at_read' => (new DateTimeImmutable($expiresAt))->getTimestamp() < time(),
        'age_seconds_at_export' => is_int($offer['ageSecondsAtExport'] ?? null) ? $offer['ageSecondsAtExport'] : null];
    $out['original_journal']['feed_binding_sha256'] = hash('sha256', incident_json($binding));

    ob_start();
    try {
        require INCIDENT_READ_RUNTIME . '/config.php';
        require_once INCIDENT_READ_RUNTIME . '/services/ChannelPostRuntime.php';
        require_once INCIDENT_READ_RUNTIME . '/services/ChannelPostRegistryService.php';
        require_once INCIDENT_READ_RUNTIME . '/services/ChannelPostButtonTransport.php';
    } finally { ob_end_clean(); }
    $pdo = ConversationDb::connection();
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    incident_need($pdo->inTransaction(), 'incident_read_only_transaction_unavailable');
    $out['database_session_read_only'] = true;
    $registry = new ChannelPostRegistryService($pdo);
    $targets = ['max' => ['bot' => 'id9704048781_1_bot', 'channel' => '-80492244209683', 'source' => 'max_anytour_msk1', 'id' => 9],
        'telegram' => ['bot' => 'anyru_bot', 'channel' => '-1002262666616', 'source' => 'tg_anytour_msk', 'id' => 10]];
    foreach ($targets as $platform => $target) {
        $identity = ChannelPostRuntime::identity($platform);
        incident_need(is_array($identity) && ($identity['project_key'] ?? null) === 'anytour'
            && ($identity['bot_key'] ?? null) === $target['bot'], 'incident_configured_bot_changed');
        $channel = $registry->channel('anytour', $platform, $target['bot'], $target['channel']); $out['channel_rows_read']++;
        incident_need(is_array($channel) && (int)$channel['id'] === $target['id'] && $channel['source_key'] === $target['source']
            && (int)$channel['config_version'] === 2 && $channel['button_text'] === '✈️ Узнать стоимость тура', 'incident_channel_configuration_changed');
        $cap = incident_capability((new ChannelPostButtonTransport())->verifyChannel($channel));
        $out['capabilities'][$platform] = $cap; $out['provider_requests'] += $cap['request_count'];

        // Exact URLs in the original feed binding are the only permitted publication lookup inputs.
        $url = incident_public_url($binding['posts'][$platform] ?? null, $platform);
        $out['current_registry'][$platform] = ['state' => 'unknown', 'reason' => 'source_publication_identity_unavailable'];
        if ($url === null) continue;
        $q = $pdo->prepare("SELECT pp.id AS publication_id FROM post_publications pp JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.post_channel_id=? AND JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json, '$.post_url'))=? ORDER BY pp.id LIMIT 2");
        $q->execute([$target['id'], $url]); $ids = $q->fetchAll(PDO::FETCH_COLUMN); $out['publication_identity_lookups']++;
        if (count($ids) !== 1) {
            $out['current_registry'][$platform] = ['state' => 'unknown', 'reason' => 'source_url_identity_not_unique', 'post_url' => $url, 'matches_bounded' => count($ids)];
            continue;
        }
        $publication = $registry->publication((int)$ids[0]); $out['publication_metadata_reads']++;
        incident_need(is_array($publication) && $publication['project_key'] === 'anytour' && $publication['platform'] === $platform
            && $publication['bot_key'] === $target['bot'] && $publication['channel_id'] === $target['channel']
            && $publication['source_key'] === $target['source'] && ($publication['snapshot']['post_url'] ?? null) === $url,
            'incident_current_publication_identity_changed');
        $s = $publication['snapshot'];
        $out['current_registry'][$platform] = ['state' => incident_code($publication['state'] ?? null),
            'publication_id' => (int)$publication['publication_id'], 'version_id' => (int)$publication['version_id'],
            'message_id' => incident_identity($publication['external_message_id'] ?? null), 'post_url' => $url,
            'text_sha256' => hash('sha256', (string)($s['text'] ?? '')),
            'attachments_sha256' => hash('sha256', incident_json($s['attachments'] ?? [])),
            'monitor_price_record_id' => incident_hash($s['metadata']['monitor_price_record_id'] ?? null),
            'write_attempts' => (int)$publication['write_attempts'], 'error_reason' => incident_code($publication['error_reason'] ?? null),
            'identity_source' => 'original_feed_binding_exact_public_url'];
    }
    $out['current_capabilities_ready'] = ($out['capabilities']['max']['state'] ?? null) === 'ready'
        && ($out['capabilities']['telegram']['state'] ?? null) === 'ready';
    incident_check_locks();
    incident_need(incident_git(['rev-parse', '--verify', 'HEAD']) === INCIDENT_READ_INSTALLED
        && incident_git(['status', '--porcelain', '--untracked-files=no']) === '', 'incident_runtime_changed_during_read');
    $out['status'] = 'original_no_write_incident_and_current_capabilities_read';
    $out['completed_at'] = gmdate('c'); $out['ok'] = true;
} catch (Throwable $e) {
    $out['error'] = incident_code($e->getMessage()) ?? 'incident_read_failed';
}
if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    try { $pdo->rollBack(); }
    catch (Throwable $e) { $out['ok'] = false; $out['error'] = 'incident_read_transaction_close_failed'; }
}
foreach (array_reverse($incidentLocks) as $item) {
    if (is_resource($item['stream'])) { flock($item['stream'], LOCK_UN); fclose($item['stream']); }
}
echo 'MONITOR_PRICE_INCIDENT_READ=' . incident_json($out) . "\n";
exit($out['ok'] ? 0 : 2);
