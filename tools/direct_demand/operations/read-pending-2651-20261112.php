<?php
declare(strict_types=1);
// One bounded journal + READ ONLY registry + one MAX GET for saved TV2651 plans. No recovery or writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');

const READ_ROOT = '/var/www/anytoour/data/channel-post-operations';
const READ_RUNTIME = '/var/www/anytoour/data/www/app.anytoour.ru';
const READ_EXPECTED_TV = 2651;
const READ_LINE_CAP = 2000000;
const READ_RECENT = 6;
const READ_EXPECTED_RUNTIME = '53382c760a5cf07a2075751670a46c35ee77feb9';
const READ_EXPECTED_OPERATION = 'commercial-monitor-price-h2651-3c6637d73d3424159b7d01b9ffcf2e2051fc3fbae084bb645c7b259c33413d11';
const READ_EXPECTED_GLOBAL_SHA256 = 'cb30682781ed2a55972564c98604ca26694e0c3154141ad936dd5e06252baf51';
const READ_EXPECTED_ORIGINAL_SHA256 = '2e8404f8804911fa062f458bd2adee0426a35a4428c99a377be4fd5518d06b27';
const READ_EXPECTED_MANIFEST_SHA256 = '4515961a91d0f940a3df10361f9dab1adad7360d24b3f55b067c4e81411a9784';
const READ_RUNTIME_PINS_B64 = 'eyJpbnRlZ3JhdGlvbnMvQ2hhbm5lbFBvc3RBcGlUcmFuc3BvcnQucGhwIjoiMzAwNjM0ZDc3Y2I5ZmRkZjUxYWRmOTg2OTk3NGY5NTZjY2E2ZTNmOSIsIm1pZ3JhdGlvbnMvMDMzX2NoYW5uZWxfcG9zdF9jb250ZXh0LnNxbCI6IjA1MzA0NTgwMzBhNGJmZTZiNmNjNTVkMWU1ZTQ0MTc0NWMyOWI5NDciLCJtaWdyYXRpb25zLzAzNl9jaGFubmVsX3Bvc3RfbWVtYmVyX2RlbGl2ZXJ5LnNxbCI6IjFiZGE0NjNlNTJiMzMxMzBhZDEyNzUyZGRlZjRlZTVjOTI2OTljMDUiLCJtaWdyYXRpb25zLzA0MF9jaGFubmVsX3Bvc3RfcmVjb25jaWxpYXRpb24uc3FsIjoiMGYyNGI4MzhlYWNkNDUzMzNjNWFkZTlhMjk5MWQwNjA0ODhjNjAzNSIsInNlcnZpY2VzL0NoYW5uZWxQb3N0QWRtaW5TZXJ2aWNlLnBocCI6ImI4MGVlZmM4MDEwNTRiZmU2ZmRiNThlYzM0NWVjZGY2ODdiMmU4OWEiLCJzZXJ2aWNlcy9DaGFubmVsUG9zdEF1dG9tYXRpb25TZXJ2aWNlLnBocCI6ImY1OTc1YWI5NWRlNWY0NWUwODdlYjdhMGY3NjhmNTY2YzViY2E2NDgiLCJzZXJ2aWNlcy9DaGFubmVsUG9zdEJ1dHRvblRyYW5zcG9ydC5waHAiOiI3YjY3MTFmMWRlNjMwMDAzNDZkOGU1Nzg5ZjJkYmI3ZjcyNTUzNWI4Iiwic2VydmljZXMvQ2hhbm5lbFBvc3RFbnRyeVNlcnZpY2UucGhwIjoiZjBlMDRlOWI0ZTBlZDY4NTljZWQ5NjI3OWNiZjA2MTk5MzE1OTZlNiIsInNlcnZpY2VzL0NoYW5uZWxQb3N0TWF4QWxidW1SZWNvdmVyeS5waHAiOiJkZTI2NmNkMDY4NTg3Y2MzYjJmZjgzZjMwOGE5NjZlYTY2ODg3YTA0Iiwic2VydmljZXMvQ2hhbm5lbFBvc3RNZWRpYVNlcnZpY2UucGhwIjoiZjc5NGVmZDBmNmYyNGRmOTE2ZTE4OTA5ZmY2MjgxYjBiYzkyODQzMyIsInNlcnZpY2VzL0NoYW5uZWxQb3N0TWVtYmVyRGVsaXZlcnlTZXJ2aWNlLnBocCI6IjIwYzFkNzk4NDg5OGIyYTU5OTZhNWY5ZTIwZTRkMmQ4YzIwYjQ3NzgiLCJzZXJ2aWNlcy9DaGFubmVsUG9zdFJlY29uY2lsaWF0aW9uU2VydmljZS5waHAiOiIyYjU1OWIwNjdhOTFhYmI5NDAyYTlkYzJmM2NlNzVkMWRkZWRjN2MzIiwic2VydmljZXMvQ2hhbm5lbFBvc3RSZWNvbmNpbGlhdGlvblN0YXRlLnBocCI6ImQ5NTgzY2VjYTgwZDE0MjRhNDRhZGUzNTYxYzIxMjgyYTIzYTUyZjAiLCJzZXJ2aWNlcy9DaGFubmVsUG9zdFJlZ2lzdHJ5U2VydmljZS5waHAiOiI4MzM1YmYyZmIwYzFjZDc4ZjI2OGQ0ZTkwZmFkMWE0NDY1ZmE2MzkwIiwic2VydmljZXMvQ2hhbm5lbFBvc3RSdW50aW1lLnBocCI6IjJmMWRkOWI3NzQ3NGZkYmFhOTJmMDQwMmYzYjBhYTE3MGM1OTU0NzQiLCJzZXJ2aWNlcy9DaGFubmVsUG9zdFRlbGVncmFtQWxidW0ucGhwIjoiZmFhMWExNGJjMjc5YTRhZjMzOTM2MjgyMTI2YmYwZDQ2ZTM0MzA0MCIsInNlcnZpY2VzL0NoYW5uZWxQb3N0V2ViaG9va0NvbmZpZ1NlcnZpY2UucGhwIjoiZDVmZGNiMDU2MDg4ZjFlM2E1NDFiNDA0NjMyNWQ5N2VlZjY0MTdkOSIsInNlcnZpY2VzL0NvbnZlcnNhdGlvbkRiLnBocCI6ImJiNjI4MWZhZjU1YWNhN2JlZWFlN2Y4YTU3Mzg5ZDE5NzUxOTM2ZjciLCJzZXJ2aWNlcy9NYXhUbHNDb25maWcucGhwIjoiMDMyYzAyMTFlZTNmMzljNmYwZDI3YzY2OWFiZDViNDY3ZTMyMWRlYSIsInNlcnZpY2VzL01heFRyYW5zcG9ydC5waHAiOiIyY2I0Zjg3ODZiNDcwNGE4ZTYyYTNhOWM0MGRjMmNjNzNkYWQ2MTU5Iiwic2VydmljZXMvUHJvamVjdENvbmZpZy5waHAiOiI2M2MxN2M4MTAzNDEyZjhjZmY4Y2RjYzRiYmIwNjJlNzQ0MTA2YWY5Iiwic2VydmljZXMvUHJvamVjdENvbmZpZ3VyYXRpb24ucGhwIjoiMDcwMTlkNDczYzU4OGUzMzg2YWNlNDI5NzdkYjkzMDkyZTgxMTJlMyJ9';

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


/** Read-only git command; no shell, stdout is bounded and never logged verbatim. */
function read_context_git(array $arguments): string {
    $pipes = [];
    $process = proc_open(array_merge(['git', '-C', READ_RUNTIME], $arguments),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes, READ_RUNTIME, null, ['bypass_shell' => true]);
    read_need(is_resource($process), 'read_runtime_git_command_failed');
    $raw = stream_get_contents($pipes[1], 8193); fclose($pipes[1]); $exit = proc_close($process);
    read_need($exit === 0 && is_string($raw) && strlen($raw) <= 8192, 'read_runtime_git_command_failed');
    return trim($raw);
}
function read_context_runtime(): array {
    read_need(realpath(getcwd()) === READ_RUNTIME && read_runtime_head() === READ_EXPECTED_RUNTIME, 'read_current_runtime_changed');
    read_need(read_context_git(['status', '--porcelain', '--untracked-files=no']) === '', 'read_tracked_tree_changed');
    $pins = json_decode(base64_decode(READ_RUNTIME_PINS_B64, true), true, 32, JSON_THROW_ON_ERROR);
    read_need(is_array($pins) && count($pins) === 22, 'read_runtime_pin_count_invalid');
    foreach ($pins as $relative => $expected) {
        read_need(is_string($relative) && preg_match('~\A(?:services|integrations|migrations)/[A-Za-z0-9_.-]+\z~D', $relative) === 1
            && is_string($expected) && preg_match('/\A[a-f0-9]{40}\z/D', $expected) === 1, 'read_runtime_pin_invalid');
        $file = READ_RUNTIME . '/' . $relative;
        read_need(!is_link(dirname($file)) && !is_link($file) && is_file($file) && realpath($file) === $file, 'read_runtime_file_invalid');
        $size = filesize($file);
        read_need(is_int($size) && $size > 0 && $size <= 2000000, 'read_runtime_file_size_invalid');
        $raw = file_get_contents($file);
        read_need(is_string($raw) && strlen($raw) === $size
            && hash('sha1', 'blob ' . strlen($raw) . "\0" . $raw) === $expected, 'read_runtime_pin_changed');
    }
    return ['git_head' => READ_EXPECTED_RUNTIME, 'tracked_tree_clean' => true, 'pins_checked' => 22, 'pins_match' => 22];
}
function read_context_photo(array $snapshot): ?string {
    $attachments = $snapshot['attachments'] ?? null;
    if (!is_array($attachments) || count($attachments) !== 1 || !is_array($attachments[0] ?? null)
        || ($attachments[0]['type'] ?? null) !== 'image') return null;
    $photo = $attachments[0]; $provider = $photo['provider'] ?? null;
    $id = (string)($photo['photo_id'] ?? $photo['file_unique_id'] ?? '');
    if (!in_array($provider, ['max', 'telegram'], true) || preg_match('/\A[A-Za-z0-9_-]{1,191}\z/D', $id) !== 1) return null;
    return $provider . ':' . $id;
}
function read_transport_class($error): array {
    if (empty($error)) return ['present' => false, 'code' => null, 'class' => 'none'];
    $code = filter_var($error, FILTER_VALIDATE_INT);
    if (!is_int($code) || $code < 1 || $code > 999) return ['present' => true, 'code' => null, 'class' => 'non_numeric_transport_error'];
    $class = match ($code) {
        5, 6 => 'name_resolution_failed', 7 => 'connection_failed', 28 => 'timeout',
        35, 51, 58, 60, 77, 80, 83 => 'tls_failed', 52 => 'empty_reply',
        55 => 'send_failed', 56 => 'receive_failed', default => 'other_transport_error',
    };
    return ['present' => true, 'code' => $code, 'class' => $class];
}
/** All live inputs are journal plan IDs. Exactly one MAX GET, no Telegram calls. */
function read_current_context(array $last, string $operation, array &$out): void {
    $out['current_context_runtime'] = read_context_runtime();
    $plans = [];
    foreach ($last['posts'] as $key => $post) {
        $platform = explode(':', (string)$key, 2)[0]; $plan = $post['plan'] ?? null;
        read_need(in_array($platform, ['max', 'telegram'], true) && is_array($plan) && !isset($plans[$platform]), 'read_current_plan_scope_invalid');
        read_need(($plan['platform'] ?? null) === $platform && is_int($plan['publication_id'] ?? null) && $plan['publication_id'] > 0
            && is_int($plan['old_version_id'] ?? null) && $plan['old_version_id'] > 0
            && read_identity($plan['logical_id'] ?? null) !== null && read_identity($plan['message_id'] ?? null) !== null
            && read_public_url($plan['post_url'] ?? null) !== null
            && is_string($plan['text'] ?? null) && strlen($plan['text']) <= 20000
            && is_string($plan['photo_signature'] ?? null)
            && is_string($plan['token'] ?? null) && preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/D', $plan['token']) === 1
            && ($plan['monitor_price']['tourvisor_hotel_id'] ?? null) === READ_EXPECTED_TV
            && ($plan['monitor_price']['record_id'] ?? null) === substr($operation, -64), 'read_current_plan_identity_invalid');
        read_need(str_starts_with($plan['post_url'], $platform === 'max' ? 'https://max.ru/channel_anytour/' : 'https://t.me/any_toour/'), 'read_current_plan_channel_invalid');
        $plans[$platform] = $plan;
    }
    read_need(isset($plans['max']) && count($plans) <= 2, 'read_current_max_plan_missing');
    read_need(count($plans) === 1 && $plans['max']['publication_id'] === 957 && $plans['max']['old_version_id'] === 2995
        && $plans['max']['message_id'] === 'mid.ffffb6caf0606fed01a1b793c24c6a94'
        && $plans['max']['post_url'] === 'https://max.ru/channel_anytour/AaG3k8JMapQ', 'read_saved_max_plan_changed');
    read_need(count(array_unique(array_column($plans, 'publication_id'))) === count($plans), 'read_current_publication_duplicate');
    $out['current_context'] = ['registry_plan_count' => count($plans), 'max_get_limit' => 1, 'telegram_requests' => 0, 'provider_writes' => 0];
    $pdo = null;
    try {
        ob_start();
        try {
            require READ_RUNTIME . '/config.php';
            require_once READ_RUNTIME . '/services/ChannelPostRuntime.php';
            require_once READ_RUNTIME . '/services/ChannelPostAdminService.php';
            require_once READ_RUNTIME . '/services/ChannelPostEntryService.php';
            require_once READ_RUNTIME . '/integrations/ChannelPostApiTransport.php';
            require_once READ_RUNTIME . '/services/ChannelPostButtonTransport.php';
        } finally { ob_end_clean(); }
        $identity = ChannelPostRuntime::identity('max');
        read_need(is_array($identity) && ($identity['project_key'] ?? null) === 'anytour'
            && ($identity['bot_key'] ?? null) === 'id9704048781_1_bot', 'read_current_max_bot_changed');
        $pdo = ConversationDb::connection();
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        $out['database_session_read_only'] = true;
        $out['database_read_count_basis'] = 'registry.publication calls; each uses SELECT statements only';
        $registry = new ChannelPostRegistryService($pdo);
        $targets = [
            'max' => ['bot' => 'id9704048781_1_bot', 'channel' => '-80492244209683', 'source' => 'max_anytour_msk1'],
            'telegram' => ['bot' => 'anyru_bot', 'channel' => '-1002262666616', 'source' => 'tg_anytour_msk'],
        ];
        $publications = [];
        foreach ($plans as $platform => $plan) {
            $out['database_reads']++;
            $publication = $registry->publication($plan['publication_id']);
            $out['current_registry_read_performed'] = true;
            $out['registry_current'][$platform] = ['publication_id' => $plan['publication_id'], 'found' => is_array($publication)];
            read_need(is_array($publication), 'read_current_publication_missing');
            $target = $targets[$platform]; $snapshot = $publication['snapshot'] ?? null;
            read_need(($publication['project_key'] ?? null) === 'anytour' && ($publication['platform'] ?? null) === $platform
                && ($publication['bot_key'] ?? null) === $target['bot'] && ($publication['channel_id'] ?? null) === $target['channel']
                && ($publication['source_key'] ?? null) === $target['source'] && (int)($publication['publication_id'] ?? 0) === $plan['publication_id']
                && ($publication['external_message_id'] ?? null) === $plan['message_id'] && is_array($snapshot), 'read_current_publication_identity_changed');
            $currentText = is_string($snapshot['text'] ?? null) ? $snapshot['text'] : null;
            $photo = read_context_photo($snapshot);
            $registryButton = ChannelPostButtonTransport::buttonUrl($publication);
            $planButton = ($platform === 'max' ? 'https://max.ru/id9704048781_1_bot?start=' : 'https://t.me/anyru_bot?start=') . $plan['token'];
            $out['registry_current'][$platform] += [
                'version_id' => read_int($publication['version_id'] ?? null), 'state' => read_code($publication['state'] ?? null),
                'message_id' => read_identity($publication['external_message_id'] ?? null),
                'post_url' => read_public_url($snapshot['post_url'] ?? null),
                'text_sha256' => $currentText === null ? null : hash('sha256', $currentText),
                'photo_signature' => $photo,
                'monitor_record_id' => read_hash($snapshot['metadata']['monitor_price_record_id'] ?? null),
                'source_event_id' => is_string($snapshot['metadata']['source_event_id'] ?? null)
                    && preg_match('/\A[A-Za-z0-9_.:-]{1,400}\z/D', $snapshot['metadata']['source_event_id']) === 1 ? $snapshot['metadata']['source_event_id'] : null,
                'source_revision_ms' => (is_int($snapshot['metadata']['source_revision_ms'] ?? null) || is_string($snapshot['metadata']['source_revision_ms'] ?? null))
                    && preg_match('/\A[0-9]{1,17}\z/D', (string)$snapshot['metadata']['source_revision_ms']) === 1 ? (string)$snapshot['metadata']['source_revision_ms'] : null,
                'revision_clock_origin' => read_code($snapshot['metadata']['revision_clock_origin'] ?? null),
                'operation_observed_at_ms' => (is_int($snapshot['metadata']['operation_observed_at_ms'] ?? null) || is_string($snapshot['metadata']['operation_observed_at_ms'] ?? null))
                    && preg_match('/\A[0-9]{1,17}\z/D', (string)$snapshot['metadata']['operation_observed_at_ms']) === 1 ? (string)$snapshot['metadata']['operation_observed_at_ms'] : null,
                'observation_kind' => read_code($snapshot['metadata']['observation_kind'] ?? null),
                'old_version_id_from_plan' => $plan['old_version_id'],
                'current_version_equals_old' => ($publication['version_id'] ?? null) === $plan['old_version_id'],
                'current_version_greater_than_old' => is_int($publication['version_id'] ?? null) && $publication['version_id'] > $plan['old_version_id'],
                'text_matches_target_plan' => $currentText !== null && $currentText === $plan['text'],
                'photo_matches_plan' => $photo !== null && $photo === $plan['photo_signature'],
                'url_matches_plan' => ($snapshot['post_url'] ?? null) === $plan['post_url'],
                'button_url_matches_plan' => $registryButton === $planButton,
                'token_matches_plan' => is_string($publication['start_payload'] ?? null) && hash_equals($plan['token'], $publication['start_payload']),
                'token_sha256' => is_string($publication['start_payload'] ?? null) ? hash('sha256', $publication['start_payload']) : null,
                'snapshot_only_expected_button' => ChannelPostIncomingAdapter::containsOnlyButton($snapshot, $planButton, '✈️ Узнать стоимость тура'),
            ];
            $publications[$platform] = $publication;
        }
        $out['current_context']['registry_read_complete'] = true;
        $plan = $plans['max']; $publication = $publications['max']; $snapshot = $publication['snapshot'];
        $out['max_readback'] = ['request_method' => 'GET', 'message_id' => $plan['message_id'], 'fixed_channel_id' => '-80492244209683', 'attempted' => true];
        $out['provider_requests']++;
        try {
            $response = ChannelPostApiTransport::buttonRequest('max', 'GET', '/messages/' . $plan['message_id'], [], null);
            read_need(is_array($response), 'read_max_response_shape_invalid');
            $transport = read_transport_class($response['transport_error'] ?? null);
            $http = filter_var($response['http'] ?? null, FILTER_VALIDATE_INT);
            $http = is_int($http) && $http >= 0 && $http <= 599 ? $http : null;
            $data = is_array($response['data'] ?? null) ? $response['data'] : null;
            $bodyJson = $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $providerCode = $data['code'] ?? null;
            $providerCode = is_string($providerCode) && preg_match('/\A[a-z][a-z0-9_.-]{0,80}\z/Di', $providerCode) === 1 ? $providerCode : null;
            $out['max_readback'] += [
                'http' => $http, 'transport_error_class' => $transport['class'], 'transport_error_code' => $transport['code'],
                'http_success' => !$transport['present'] && $http === 200 && $data !== null,
                'body' => ['decoded_object_present' => $data !== null, 'provider_error_code' => $providerCode,
                    'message_field_present' => is_array($data) && array_key_exists('message', $data),
                    'body_field_present' => is_array($data['body'] ?? null),
                    'decoded_sha256' => is_string($bodyJson) ? hash('sha256', $bodyJson) : null],
            ];
            $live = !$transport['present'] && $http === 200 && $data !== null ? ChannelPostIncomingAdapter::maxSnapshot($data) : null;
            $liveText = is_array($live) && is_string($live['text'] ?? null) ? $live['text'] : null;
            $livePhoto = is_array($live) ? read_context_photo($live) : null;
            $liveUrl = is_array($live) ? read_public_url($live['post_url'] ?? null) : null;
            $planButton = 'https://max.ru/id9704048781_1_bot?start=' . $plan['token'];
            $registryButton = ChannelPostButtonTransport::buttonUrl($publication);
            $recipientChannel = $data['recipient']['chat_id'] ?? null;
            $nativeMid = read_identity($data['body']['mid'] ?? null);
            $out['max_readback'] += [
                'snapshot_present' => is_array($live),
                'native_body_mid' => $nativeMid,
                'native_body_mid_matches_plan' => $nativeMid !== null && $nativeMid === $plan['message_id'],
                'native_body_mid_matches_registry' => $nativeMid !== null && $nativeMid === ($publication['external_message_id'] ?? null),
                'recipient_channel_present' => is_int($recipientChannel) || is_string($recipientChannel),
                'recipient_channel_matches_fixed' => (string)$recipientChannel === '-80492244209683',
                'actual_text_sha256' => $liveText === null ? null : hash('sha256', $liveText),
                'plan_text_sha256' => hash('sha256', $plan['text']),
                'registry_text_sha256' => is_string($snapshot['text'] ?? null) ? hash('sha256', $snapshot['text']) : null,
                'text_matches_plan' => $liveText !== null && $liveText === $plan['text'],
                'text_matches_registry' => $liveText !== null && $liveText === ($snapshot['text'] ?? null),
                'photo_signature' => $livePhoto,
                'photo_matches_plan' => $livePhoto !== null && $livePhoto === $plan['photo_signature'],
                'photo_matches_registry' => $livePhoto !== null && $livePhoto === read_context_photo($snapshot),
                'post_url' => $liveUrl,
                'url_matches_plan' => $liveUrl !== null && $liveUrl === $plan['post_url'],
                'url_matches_registry' => $liveUrl !== null && $liveUrl === ($snapshot['post_url'] ?? null),
                'only_expected_plan_button' => is_array($live) && ChannelPostIncomingAdapter::containsOnlyButton($live, $planButton, '✈️ Узнать стоимость тура'),
                'only_expected_registry_button' => is_array($live) && ChannelPostIncomingAdapter::containsOnlyButton($live, $registryButton, '✈️ Узнать стоимость тура'),
            ];
        } catch (Throwable $e) {
            $out['max_readback']['read_error_code'] = read_code($e->getMessage()) ?? 'read_max_request_failed';
        }
        $out['current_context']['read_attempt_completed'] = true;
        $out['current_context']['captured_at'] = gmdate('c');
    } finally {
        if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); $out['database_transaction_rolled_back'] = true; }
    }
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
    unset($global['last']);
    $out['global_journal'] = $global;
    $out['global_journal']['completed_count'] = count($state['completed']);
    read_need($global['sha256'] === READ_EXPECTED_GLOBAL_SHA256 && $global['bytes'] === 6324466 && $global['line_count'] === 398 && count($state['completed']) === 131, 'read_global_changed_since_original_capture');
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
        read_need($operation === READ_EXPECTED_OPERATION && $pending['phase'] === 'post_started', 'read_pending_operation_changed_since_capture');
        $out['pending'] = ['tourvisor_hotel_id' => READ_EXPECTED_TV, 'operation' => $operation, 'phase' => $pending['phase'], 'at' => read_stamp($pending['at'] ?? null)];
        if (is_array($pending['child_failure'] ?? null)) {
            $out['pending']['child_failure'] = ['exit_code' => read_int($pending['child_failure']['exit_code'] ?? null), 'error' => read_code($pending['child_failure']['error'] ?? null)];
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
        read_need($journal['sha256'] === READ_EXPECTED_ORIGINAL_SHA256 && $journal['bytes'] === 26418 && $journal['line_count'] === 3 && $manifestHash === READ_EXPECTED_MANIFEST_SHA256, 'read_original_changed_since_capture');
        $publicLast = $last;
        foreach ($publicLast['posts'] as &$publicPost) {
            if (is_array($publicPost['plan'] ?? null) && array_key_exists('token', $publicPost['plan'])) {
                $publicToken = $publicPost['plan']['token'];
                $publicPost['plan']['token_sha256'] = is_string($publicToken) ? hash('sha256', $publicToken) : null;
                unset($publicPost['plan']['token']);
            }
        }
        unset($publicPost, $publicToken);
        $out['original_state_for_reconciliation'] = $publicLast;
        $out['original_state_redacted_fields'] = ['posts.*.plan.token'];
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
        read_current_context($last, $operation, $out);
        $out['status'] = ($last['complete'] ?? null) === true ? 'original_journal_complete_read' : 'original_journal_incomplete_read';
        $out['child_stdout_error_not_recovered'] = !isset($out['pending']['child_failure']['error']);
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

