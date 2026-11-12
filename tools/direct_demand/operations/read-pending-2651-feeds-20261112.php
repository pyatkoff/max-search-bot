<?php
declare(strict_types=1);
// One bounded read of the existing TV2651 pending operation. No recovery or writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');

const READ_ROOT = '/var/www/anytoour/data/channel-post-operations';
const READ_RUNTIME = '/var/www/anytoour/data/www/app.anytoour.ru';
const READ_EXPECTED_TV = 2651;
const READ_LINE_CAP = 2000000;
const READ_RECENT = 6;

$out = [
    'ok' => false,
    'mode' => 'read_only_original_pending_monitor_operation',
    'checked_at' => gmdate('c'),
    'expected_tourvisor_hotel_id' => READ_EXPECTED_TV,
    'filesystem_writes' => 0,
    'database_reads' => 0,
    'database_writes' => 0,
    'provider_requests' => 0,
    'supplier_requests' => 0,
    'current_registry_read_performed' => false,
    'runtime_git_head' => null,
];
$readLocks = [];

function read_need(bool $ok, string $code): void {
    if (!$ok) throw new RuntimeException($code);
}
function read_hash($value): ?string {
    return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1 ? $value : null;
}
function read_int($value): ?int {
    return is_int($value) && $value >= 0 ? $value : null;
}
function read_code($value): ?string {
    return is_string($value) && preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D', $value) === 1 ? $value : null;
}
function read_public_url($value): ?string {
    if (!is_string($value)) return null;
    return preg_match('~\Ahttps://(?:max\.ru/channel_anytour/[A-Za-z0-9_-]+|t\.me/any_toour/[0-9]+)\z~D', $value) === 1 ? $value : null;
}
function read_identity($value): ?string {
    return is_string($value) && preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D', $value) === 1 ? $value : null;
}
function read_stamp($value): ?string {
    return is_string($value) && preg_match('/\A[0-9T:Z+.\-]{10,40}\z/D', $value) === 1 ? $value : null;
}
function read_runtime_head(): ?string {
    if (realpath(READ_RUNTIME) !== READ_RUNTIME) return null;
    $pipes = [];
    $process = proc_open(
        ['git', '-C', READ_RUNTIME, 'rev-parse', '--verify', 'HEAD'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes, READ_RUNTIME, null, ['bypass_shell' => true]
    );
    if (!is_resource($process)) return null;
    $raw = stream_get_contents($pipes[1], 128);
    fclose($pipes[1]);
    $exit = proc_close($process);
    $head = is_string($raw) ? trim($raw) : '';
    return $exit === 0 && preg_match('/\A[a-f0-9]{40}\z/D', $head) === 1 ? $head : null;
}
/** Existing-file rb plus shared lock only. The callback's exclusive lock wins. */
function read_journal(string $path, int $cap, callable $inspect): array {
    global $readLocks;
    read_need(!is_link(dirname($path)) && realpath(dirname($path)) === dirname($path), 'read_directory_invalid');
    read_need(!is_link($path) && is_file($path) && realpath($path) === $path, 'read_journal_missing_or_invalid');
    $before = lstat($path);
    read_need(is_array($before) && ($before['mode'] & 0170000) === 0100000 && $before['size'] > 0 && $before['size'] <= $cap, 'read_journal_size_invalid');
    $stream = fopen($path, 'rb');
    read_need(is_resource($stream), 'read_journal_open_failed');
    $readLocks[] = $stream;
    read_need(flock($stream, LOCK_SH | LOCK_NB), 'read_original_operation_still_locked');
    $locked = fstat($stream);
    read_need(is_array($locked) && $locked['ino'] === $before['ino'] && $locked['dev'] === $before['dev'] && $locked['size'] === $before['size'], 'read_journal_identity_changed');
    $hash = hash_init('sha256');
    $bytes = 0;
    $lines = 0;
    $last = null;
    while (!feof($stream)) {
        $line = fgets($stream, READ_LINE_CAP + 2);
        if ($line === false) { read_need(feof($stream), 'read_journal_read_failed'); break; }
        $bytes += strlen($line);
        read_need($bytes <= $cap && strlen($line) <= READ_LINE_CAP, 'read_journal_limit_exceeded');
        hash_update($hash, $line);
        read_need(str_ends_with($line, "\n"), 'read_journal_partial_line_stop');
        try { $state = json_decode($line, true, 64, JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { throw new RuntimeException('read_journal_json_invalid'); }
        read_need(is_array($state), 'read_journal_shape_invalid');
        $lines++;
        $inspect($state, $lines);
        $last = $state;
    }
    $after = fstat($stream);
    clearstatcache(true, $path);
    $named = lstat($path);
    read_need(is_array($after) && is_array($named) && $bytes === $locked['size']
        && $after['ino'] === $locked['ino'] && $after['dev'] === $locked['dev'] && $after['size'] === $locked['size']
        && $named['ino'] === $locked['ino'] && $named['dev'] === $locked['dev'] && $named['size'] === $locked['size']
        && !is_link($path) && realpath($path) === $path, 'read_journal_changed_during_read');
    return ['sha256' => hash_final($hash), 'bytes' => $bytes, 'line_count' => $lines, 'complete_jsonl' => true, 'last' => $last];
}
function read_post_summary(array $post, string $platform, string $operation, int $tv): array {
    $plan = is_array($post['plan'] ?? null) ? $post['plan'] : [];
    $receipt = is_array($post['receipt'] ?? null) ? $post['receipt'] : [];
    $token = $plan['token'] ?? null;
    $validToken = is_string($token) && preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/D', $token) === 1;
    $button = $validToken ? ($platform === 'max' ? 'https://max.ru/id9704048781_1_bot?start=' : 'https://t.me/anyru_bot?start=') . $token : null;
    $planText = is_string($plan['text'] ?? null) ? $plan['text'] : null;
    $receiptText = is_string($receipt['text'] ?? null) ? $receipt['text'] : null;
    $signature = is_string($plan['photo_signature'] ?? null) ? $plan['photo_signature'] : null;
    $result = [
        'platform' => $platform,
        'state' => read_code($post['state'] ?? null),
        'edit_request_start_ms' => read_int($post['edit_request_start_ms'] ?? null),
        'provider_edit_returned' => is_bool($post['provider_edit_returned'] ?? null) ? $post['provider_edit_returned'] : null,
        'plan' => [
            'platform_matches' => ($plan['platform'] ?? null) === $platform,
            'logical_id' => read_identity($plan['logical_id'] ?? null),
            'publication_id' => read_int($plan['publication_id'] ?? null),
            'message_id' => read_identity($plan['message_id'] ?? null),
            'old_version_id' => read_int($plan['old_version_id'] ?? null),
            'post_url' => read_public_url($plan['post_url'] ?? null),
            'target_text_sha256' => $planText === null ? null : hash('sha256', $planText),
            'photo_signature_sha256' => $signature === null ? null : hash('sha256', $signature),
            'existing_token_present' => $validToken,
            'update_text' => is_bool($plan['update_text'] ?? null) ? $plan['update_text'] : null,
            'monitor_record_id' => read_hash($plan['monitor_price']['record_id'] ?? null),
            'monitor_record_matches_operation' => ($plan['monitor_price']['record_id'] ?? null) === substr($operation, -64),
            'monitor_hotel_matches' => ($plan['monitor_price']['tourvisor_hotel_id'] ?? null) === $tv,
        ],
        'receipt_present' => $receipt !== [],
        'receipt' => [
            'state' => read_code($receipt['state'] ?? null),
            'publication_id' => read_int($receipt['publication_id'] ?? null),
            'message_id' => read_identity($receipt['message_id'] ?? null),
            'version_id' => read_int($receipt['version_id'] ?? null),
            'previous_version_id' => read_int($receipt['previous_version_id'] ?? null),
            'post_url' => read_public_url($receipt['post_url'] ?? null),
            'text_sha256' => read_hash($receipt['text_sha256'] ?? null),
            'text_hash_valid' => $receiptText !== null && hash('sha256', $receiptText) === ($receipt['text_sha256'] ?? null),
            'text_matches_plan' => $receiptText !== null && $planText !== null && $receiptText === $planText,
            'identity_matches_plan' => $receipt !== [] && ($receipt['platform'] ?? null) === $platform
                && ($receipt['publication_id'] ?? null) === ($plan['publication_id'] ?? null)
                && ($receipt['message_id'] ?? null) === ($plan['message_id'] ?? null)
                && ($receipt['post_url'] ?? null) === ($plan['post_url'] ?? null),
            'photo_matches_plan' => $signature !== null && ($receipt['photo_signature'] ?? null) === $signature,
            'button_matches_existing_token' => $button !== null && ($receipt['button_url'] ?? null) === $button
                && ($receipt['button_text'] ?? null) === '✈️ Узнать стоимость тура',
            'body_photo_button_verified' => ($receipt['body_photo_button_verified'] ?? null) === true,
            'existing_message_reused' => ($receipt['existing_message_reused'] ?? null) === true,
            'monitor_record_matches_operation' => ($receipt['monitor_price']['record_id'] ?? null) === substr($operation, -64),
        ],
        'button_result_present' => is_array($post['button_result'] ?? null),
        'button_result_state' => read_code($post['button_result']['state'] ?? null),
        'button_result_reason' => read_code($post['button_result']['reason'] ?? null),
        'button_result_error' => read_code($post['button_result']['error'] ?? null),
        'current_registry_version_id' => null,
        'current_registry_text_sha256' => null,
    ];
    $response = is_array($post['provider_response'] ?? null) ? $post['provider_response'] : [];
    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    $description = is_string($data['description'] ?? null) ? $data['description'] : '';
    $descriptionCode = match ($description) {
        'Bad Request: message caption is too long' => 'telegram_caption_too_long',
        'Bad Request: message to edit not found' => 'telegram_message_to_edit_not_found',
        'Bad Request: message can\'t be edited' => 'telegram_message_cannot_be_edited',
        'Forbidden: bot is not a member of the channel chat' => 'telegram_bot_not_channel_member',
        default => null,
    };
    if (str_starts_with($description, 'Bad Request: message is not modified')) $descriptionCode = 'telegram_message_not_modified';
    if (preg_match('/\AToo Many Requests: retry after [0-9]+\z/D', $description) === 1) $descriptionCode = 'telegram_rate_limited';
    $message = is_array($data['result'] ?? null) ? $data['result'] : [];
    $keyboard = $message['reply_markup']['inline_keyboard'] ?? null;
    $photoMatches = false;
    foreach (is_array($message['photo'] ?? null) ? $message['photo'] : [] as $photo) {
        if (is_array($photo) && $signature !== null && 'telegram:' . (string)($photo['file_unique_id'] ?? '') === $signature) $photoMatches = true;
    }
    $result['recorded_provider_response'] = [
        'present' => $response !== [],
        'http' => read_int($response['http'] ?? null),
        'ok' => is_bool($data['ok'] ?? null) ? $data['ok'] : null,
        'error_code' => read_int($data['error_code'] ?? null),
        'description_code' => $descriptionCode,
        'description_sha256' => $description === '' ? null : hash('sha256', $description),
        'retry_after_seconds' => read_int($data['parameters']['retry_after'] ?? null),
        'result_message_present' => $message !== [],
        'caption_matches_plan' => $planText !== null && ($message['caption'] ?? null) === $planText,
        'message_id_matches_plan' => isset($message['message_id'], $plan['message_id']) && (string)$message['message_id'] === (string)$plan['message_id'],
        'channel_identity_matches' => isset($message['chat']['id']) && (string)$message['chat']['id'] === '-1002262666616'
            && ($message['chat']['username'] ?? null) === 'any_toour',
        'photo_identity_contains_plan' => $photoMatches,
        'only_expected_button' => $button !== null && is_array($keyboard) && count($keyboard) === 1
            && is_array($keyboard[0] ?? null) && count($keyboard[0]) === 1
            && ($keyboard[0][0]['url'] ?? null) === $button && ($keyboard[0][0]['text'] ?? null) === '✈️ Узнать стоимость тура',
    ];
    return $result;
}

try {
    read_need(count($argv) === 1, 'read_arguments_invalid');
    read_need(!is_link(READ_ROOT) && realpath(READ_ROOT) === READ_ROOT, 'read_private_root_invalid');
    $out['runtime_git_head'] = read_runtime_head();
    read_need($out['runtime_git_head'] !== null, 'read_runtime_git_head_unavailable');
    $globalRecent = [];
    $global = read_journal(READ_ROOT . '/monitor-price-after-collection-v1.jsonl', 100000000,
        static function (array $state, int $line) use (&$globalRecent): void {
            read_need(($state['schema'] ?? null) === 1 && array_key_exists('pending', $state) && is_array($state['completed'] ?? null), 'read_global_schema_invalid');
            $globalRecent[] = ['line' => $line, 'pending' => $state['pending']];
            if (count($globalRecent) > READ_RECENT) array_shift($globalRecent);
        });
    $state = $global['last'];
    $out['global_state_for_reconciliation'] = $state;
    unset($global['last']);
    $out['global_journal'] = $global;
    $out['global_journal']['completed_count'] = count($state['completed']);
    $pending = $state['pending'];
    if ($pending === null) {
        $out['pending'] = null;
        $out['status'] = 'no_pending_operation';
        $out['ok'] = true;
    } else {
        read_need(is_array($pending) && ($pending['tourvisor_hotel_id'] ?? null) === READ_EXPECTED_TV, 'read_pending_target_changed');
        $operation = $pending['operation'] ?? null;
        read_need(is_string($operation) && preg_match('/\Acommercial-monitor-price-h' . READ_EXPECTED_TV . '-[a-f0-9]{64}\z/D', $operation) === 1, 'read_operation_invalid');
        read_need(in_array($pending['phase'] ?? null, ['post_started', 'feed_started'], true), 'read_pending_phase_invalid');
        $out['pending'] = ['tourvisor_hotel_id' => READ_EXPECTED_TV, 'operation' => $operation, 'phase' => $pending['phase'], 'at' => read_stamp($pending['at'] ?? null)];
        $out['pending']['child_failure'] = is_array($pending['child_failure'] ?? null) ? array_intersect_key($pending['child_failure'], array_flip(['exit_code','error'])) : null;
        $out['pending']['feed_operation'] = read_identity($pending['feed_operation'] ?? null);
        $out['feed_files'] = [];
        if (($pending['phase'] ?? null) === 'feed_started') {
            $feedOp = $pending['feed_operation'] ?? null;
            read_need(is_string($feedOp) && preg_match('/\\Acommercial-channel-hotel-feeds-[0-9]{8}-update[1-9][0-9]*\\z/D', $feedOp) === 1, 'read_feed_operation_invalid');
            $names = ['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'];
            read_need(is_array($pending['files'] ?? null) && array_column($pending['files'],'name') === $names, 'read_feed_scope_invalid');
            $feedDir = READ_RUNTIME . '/feed';
            read_need(!is_link($feedDir) && realpath($feedDir) === $feedDir, 'read_feed_directory_invalid');
            foreach ($pending['files'] as $file) {
                $path = $feedDir . '/' . $file['name'];
                read_need(!is_link($path) && is_file($path) && realpath($path) === $path && filesize($path) > 0 && filesize($path) <= 4000000, 'read_feed_file_invalid');
                $hash = hash_file('sha256',$path);
                read_need(is_string($hash),'read_feed_hash_failed');
                $tmp = $feedDir . '/.' . $feedOp . '-' . $file['name'] . '.tmp';
                $tmpExists = file_exists($tmp) || is_link($tmp);
                $tmpHash = null;
                if ($tmpExists && !is_link($tmp) && is_file($tmp) && filesize($tmp) <= 4000000) $tmpHash = hash_file('sha256',$tmp);
                $out['feed_files'][] = [
                    'name'=>$file['name'],'expected_previous_sha256'=>read_hash($file['expected_previous_sha256']??null),
                    'expected_next_sha256'=>read_hash($file['sha256']??null),'expected_next_bytes'=>read_int($file['bytes']??null),
                    'expected_rows'=>read_int($file['rows']??null),'current_sha256'=>$hash,'current_bytes'=>filesize($path),
                    'matches_previous'=>hash_equals((string)($file['expected_previous_sha256']??''),$hash),
                    'matches_next'=>hash_equals((string)($file['sha256']??''),$hash),
                    'temporary_file_exists'=>$tmpExists,'temporary_file_is_symlink'=>is_link($tmp),'temporary_sha256'=>$tmpHash,
                ];
            }
        }
        $out['global_journal']['recent_same_pending'] = [];
        foreach ($globalRecent as $recent) {
            if (($recent['pending']['operation'] ?? null) === $operation) {
                $out['global_journal']['recent_same_pending'][] = ['line' => $recent['line'], 'phase' => read_code($recent['pending']['phase'] ?? null), 'at' => read_stamp($recent['pending']['at'] ?? null)];
            }
        }
        $recent = [];
        $stateCounts = ['max' => [], 'telegram' => []];
        $anyPostIntent = false;
        $anyConfigIntent = false;
        $manifestHash = null;
        $operationDir = READ_ROOT . '/' . $operation;
        $out['original_receipt_presence'] = [
            'directory_exists' => is_dir($operationDir),
            'directory_is_symlink' => is_link($operationDir),
            'receipt_exists' => file_exists($operationDir . '/receipt.jsonl'),
            'receipt_is_symlink' => is_link($operationDir . '/receipt.jsonl'),
        ];
        $journal = read_journal(READ_ROOT . '/' . $operation . '/receipt.jsonl', 10000000,
            static function (array $entry, int $line) use ($operation, &$recent, &$stateCounts, &$anyPostIntent, &$anyConfigIntent, &$manifestHash): void {
                read_need(($entry['operation'] ?? null) === $operation && read_hash($entry['manifest_hash'] ?? null) !== null
                    && is_array($entry['posts'] ?? null) && is_array($entry['configs'] ?? null), 'read_original_journal_scope_invalid');
                if ($manifestHash === null) $manifestHash = $entry['manifest_hash'];
                read_need($entry['manifest_hash'] === $manifestHash, 'read_original_manifest_changed');
                $anyConfigIntent = $anyConfigIntent || $entry['configs'] !== [];
                $anyPostIntent = $anyPostIntent || $entry['posts'] !== [];
                $posts = [];
                foreach ($entry['posts'] as $key => $post) {
                    read_need(is_string($key) && is_array($post), 'read_original_post_shape_invalid');
                    $platform = explode(':', $key, 2)[0];
                    read_need(in_array($platform, ['max', 'telegram'], true) && !isset($posts[$platform]), 'read_original_platform_scope_invalid');
                    $summary = read_post_summary($post, $platform, $operation, READ_EXPECTED_TV);
                    read_need($summary['state'] !== null, 'read_original_post_state_invalid');
                    $stateCounts[$platform][$summary['state']] = ($stateCounts[$platform][$summary['state']] ?? 0) + 1;
                    $posts[$platform] = $summary;
                }
                $recent[] = ['line' => $line, 'complete' => ($entry['complete'] ?? null) === true,
                    'error' => read_code($entry['error'] ?? null), 'reason' => read_code($entry['reason'] ?? null), 'posts' => $posts];
                if (count($recent) > READ_RECENT) array_shift($recent);
            });
        $last = $journal['last'];
        unset($journal['last']);
        read_need(($last['configs'] ?? null) === [] && !$anyConfigIntent, 'read_full_state_configs_not_empty');
        $out['original_state_for_reconciliation'] = $last;
        $snapshot = is_array($last['monitor_snapshot'] ?? null) ? $last['monitor_snapshot'] : [];
        $offer = $snapshot['item']['offers'][0] ?? [];
        $lastSummary = $recent[count($recent) - 1];
        $out['original_journal'] = $journal + [
            'operation' => $operation,
            'manifest_hash' => $manifestHash,
            'complete' => ($last['complete'] ?? null) === true,
            'any_post_intent_recorded' => $anyPostIntent,
            'any_config_intent_recorded' => $anyConfigIntent,
            'platform_state_counts' => $stateCounts,
            'last_state' => $lastSummary,
            'recent_states' => $recent,
            'monitor_snapshot' => [
                'format_matches' => ($snapshot['format'] ?? null) === 'anytour-hotel-monitor-prices-v1',
                'generated_at' => read_stamp($snapshot['generatedAt'] ?? null),
                'tourvisor_hotel_id' => read_int($snapshot['item']['tourvisorHotelId'] ?? null),
                'record_id' => read_hash($offer['recordId'] ?? null),
                'record_matches_operation' => ($offer['recordId'] ?? null) === substr($operation, -64),
                'expires_at' => read_stamp($offer['expiresAt'] ?? null),
                'age_seconds_at_export' => read_int($offer['ageSecondsAtExport'] ?? null),
            ],
            'feed_binding_hotel_matches' => ($last['feed_binding']['tourvisor_hotel_id'] ?? null) === READ_EXPECTED_TV,
        ];
        $out['status'] = ($last['complete'] ?? null) === true ? 'original_journal_complete_read' : 'original_journal_incomplete_read';
        $out['child_stdout_error_not_recovered'] = true;
        $out['ok'] = true;
    }
} catch (Throwable $e) {
    $out['error'] = read_code($e->getMessage()) ?? 'read_original_journal_failed';
}
foreach (array_reverse($readLocks) as $stream) {
    if (is_resource($stream)) { flock($stream, LOCK_UN); fclose($stream); }
}
echo 'MONITOR_ORIGINAL_JOURNAL_READ=' . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
exit($out['ok'] ? 0 : 2);

