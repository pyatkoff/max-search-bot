<?php
declare(strict_types=1);

/**
 * One-shot authorized MAX channel-post button probe. Not a web endpoint.
 * php /tmp/<private-dir>/max_channel_button_probe.php --expected-sha=<40 hex> --inspect-only
 * php /tmp/<private-dir>/max_channel_button_probe.php --expected-sha=<40 hex> --apply
 *
 * Official schema: max-messenger-bot/max-bot-api-schemas/schema_2026_07_01.json
 * PhotoAttachmentPayload.token -> PhotoAttachmentRequestPayload.token (existing attachment).
 * Only images and an existing inline keyboard are accepted; unsupported media fail closed.
 * Never print API bodies, media URLs/tokens, headers, credentials or exception messages.
 */
final class MaxChannelButtonProbeFailure extends RuntimeException {}

final class MaxChannelButtonProbe
{
    public const APP = '/var/www/anytoour/data/www/app.anytoour.ru';
    public const CHANNEL = '-80095447388179';
    public const POST_URL = 'https://max.ru/c/-80095447388179/AaE53iyDMmw';
    // Observed in the sole post returned by this authorized test channel.
    // Its channel bytes and post bytes exactly match the owner-supplied link.
    // This is a fixed measured target, not a general undocumented URL decoder.
    public const POST_MID = 'mid.ffffb727534f6fed01a139de2c83326c';
    public const BOT = 'id9704048781_1_bot';
    public const LABEL = 'Тестовая кнопка';
    private $transport;
    private $backup;
    private array $summary;

    public function __construct(callable $transport, callable $backup)
    {
        $this->transport = $transport;
        $this->backup = $backup;
        $this->summary = [
            'status' => 'blocked', 'reason' => null, 'bot_match' => false,
            'rights' => [], 'request_count' => 0, 'write_attempts' => 0,
            'http_results' => [], 'private_backup_saved' => false,
            'image_count_before' => null, 'image_count_after' => null,
            'image_ids_before' => [], 'image_ids_after' => [],
            'text_hash_equal' => null, 'markup_hash_equal' => null,
            'image_ids_equal' => null, 'keyboard_verified' => false,
        ];
    }

    private static function check(bool $condition, string $code): void
    {
        if (!$condition) throw new MaxChannelButtonProbeFailure($code);
    }

    private static function hash($value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function request(string $method, string $path, array $query, ?array $body, string $operation): array
    {
        self::check($this->summary['request_count'] < 6, 'request_budget_exhausted');
        if ($method === 'PUT') {
            self::check($path === '/messages' && $this->summary['write_attempts'] === 0, 'write_scope_rejected');
            $this->summary['write_attempts']++;
        } else {
            self::check($method === 'GET', 'method_scope_rejected');
        }
        $this->summary['request_count']++;
        try {
            $r = ($this->transport)($method, $path, $query, $body);
        } catch (Throwable $e) {
            // A timeout after sending a PUT is ambiguous; do not repeat it.
            $r = ['http' => 0, 'data' => null, 'transport_error' => true];
        }
        $http = (int)($r['http'] ?? 0);
        $error = ($r['transport_error'] ?? false) === true;
        $this->summary['http_results'][] = [
            'operation' => $operation, 'http' => $http,
            'transport_error' => $error, 'json_valid' => is_array($r['data'] ?? null),
        ];
        return ['http' => $http, 'data' => $r['data'] ?? null, 'transport_error' => $error];
    }

    private static function readable(array $response): array
    {
        self::check(!$response['transport_error'] && $response['http'] === 200 && is_array($response['data']), 'read_failed');
        return $response['data'];
    }

    private static function validateMessage(array $m): string
    {
        self::check(self::targetMatches($m), 'post_url_or_mid_mismatch');
        self::check((string)($m['recipient']['chat_id'] ?? '') === self::CHANNEL, 'post_channel_mismatch');
        self::check(($m['recipient']['chat_type'] ?? null) === 'channel', 'post_type_mismatch');
        self::check(is_array($m['body'] ?? null), 'post_body_missing');
        $mid = $m['body']['mid'] ?? null;
        self::check(is_string($mid) && (bool)preg_match('/\A[a-zA-Z0-9_.-]{1,200}\z/', $mid), 'post_mid_invalid');
        self::check(array_key_exists('text', $m['body']), 'post_text_missing');
        self::check(is_string($m['body']['text']) || $m['body']['text'] === null, 'post_text_invalid');
        self::check(is_array($m['body']['attachments'] ?? null), 'post_attachments_missing');
        return $mid;
    }

    private static function targetMatches(array $m): bool
    {
        $url = $m['url'] ?? null;
        return ($m['body']['mid'] ?? null) === self::POST_MID
            && ($url === self::POST_URL || $url === null || $url === '');
    }

    private static function fingerprint(array $m): string
    {
        // Compare the content a channel editor can change. List/detail envelopes,
        // sequence values and signed image transport tokens may differ on reads.
        // Exact MID/channel/type have already been checked independently.
        $attachments = [];
        foreach ($m['body']['attachments'] as $a) {
            if (($a['type'] ?? '') === 'image') {
                $attachments[] = ['type' => 'image', 'photo_id' => (string)($a['payload']['photo_id'] ?? '')];
            } elseif (($a['type'] ?? '') === 'inline_keyboard') {
                $attachments[] = ['type' => 'inline_keyboard', 'buttons' => $a['payload']['buttons'] ?? null];
            } else {
                $attachments[] = $a; // Unsupported media still fail closed before PUT.
            }
        }
        return self::hash([$m['body']['text'], $m['body']['markup'] ?? null, $attachments, $m['link'] ?? null]);
    }

    private static function imageIds(array $m): array
    {
        $ids = [];
        foreach ($m['body']['attachments'] as $a) {
            if (($a['type'] ?? '') !== 'image') continue;
            $id = $a['payload']['photo_id'] ?? null;
            self::check(is_int($id) || (is_string($id) && preg_match('/\A\d+\z/', $id)), 'image_stable_id_missing');
            $ids[] = self::hash((string)$id);
        }
        return $ids;
    }

    private static function existingButton(array $m): bool
    {
        foreach ($m['body']['attachments'] as $a) {
            if (($a['type'] ?? '') !== 'inline_keyboard') continue;
            foreach (($a['payload']['buttons'] ?? []) as $row) {
                foreach ($row as $button) {
                    if (($button['type'] ?? '') === 'link' && ($button['text'] ?? '') === self::LABEL && ($button['url'] ?? '') === self::POST_URL) return true;
                }
            }
        }
        return false;
    }

    private static function safeSchema(array $m): array
    {
        $out = [];
        foreach (array_slice($m['body']['attachments'], 0, 12) as $a) {
            $payload = is_array($a['payload'] ?? null) ? $a['payload'] : [];
            $keys = array_values(array_filter(array_keys($payload), static function ($k): bool {
                return is_string($k) && (bool)preg_match('/\A[a-zA-Z_]{1,40}\z/', $k);
            }));
            sort($keys);
            $type = $a['type'] ?? '';
            $out[] = [
                'type' => in_array($type, ['image', 'video', 'audio', 'file', 'inline_keyboard', 'share', 'contact', 'location', 'sticker'], true) ? $type : 'unsupported',
                'payload_keys' => array_slice($keys, 0, 20),
                'has_token' => is_string($payload['token'] ?? null) && $payload['token'] !== '',
                'has_url' => is_string($payload['url'] ?? null) && $payload['url'] !== '',
                'has_photo_id' => isset($payload['photo_id']),
            ];
        }
        return $out;
    }

    private static function appendKeyboard(array $m): array
    {
        $out = [];
        $keyboardIndex = null;
        foreach ($m['body']['attachments'] as $a) {
            self::check(is_array($a), 'attachment_invalid');
            $type = $a['type'] ?? '';
            $payload = $a['payload'] ?? null;
            self::check(is_array($payload), 'attachment_payload_invalid');
            if ($type === 'image') {
                // Reuse only the official existing-image token. A URL import could
                // create a new image and is deliberately not attempted by this probe.
                self::check(is_string($payload['token'] ?? null) && trim($payload['token']) !== '', 'image_token_missing');
                $out[] = ['type' => 'image', 'payload' => ['token' => $payload['token']]];
            } elseif ($type === 'inline_keyboard') {
                self::check($keyboardIndex === null && is_array($payload['buttons'] ?? null), 'keyboard_invalid');
                self::check(count($payload['buttons']) < 30, 'keyboard_row_limit');
                foreach ($payload['buttons'] as $row) {
                    self::check(is_array($row) && count($row) <= 7, 'keyboard_row_invalid');
                    foreach ($row as $button) {
                        self::check(is_array($button) && is_string($button['type'] ?? null) && is_string($button['text'] ?? null), 'keyboard_button_invalid');
                        self::check($button['type'] === 'link' && is_string($button['url'] ?? null), 'foreign_keyboard_button_rejected');
                    }
                }
                // Keyboard button properties are request/response shared by schema.
                $keyboardIndex = count($out);
                $out[] = ['type' => 'inline_keyboard', 'payload' => ['buttons' => $payload['buttons']]];
            } else {
                throw new MaxChannelButtonProbeFailure('unsupported_existing_attachment');
            }
        }
        $new = ['type' => 'link', 'text' => self::LABEL, 'url' => self::POST_URL];
        if ($keyboardIndex === null) {
            $out[] = ['type' => 'inline_keyboard', 'payload' => ['buttons' => [[$new]]]];
        } else {
            $out[$keyboardIndex]['payload']['buttons'][] = [$new];
        }
        self::check(count($out) <= 12, 'attachment_limit');
        // Omit text/format/link entirely to preserve caption and entity markup.
        return ['attachments' => $out];
    }

    private function verify(array $before, array $after): bool
    {
        $this->summary['image_ids_after'] = self::imageIds($after);
        $this->summary['image_count_after'] = count($this->summary['image_ids_after']);
        $this->summary['image_ids_equal'] = $this->summary['image_ids_before'] === $this->summary['image_ids_after'];
        $this->summary['text_hash_equal'] = self::hash($before['body']['text']) === self::hash($after['body']['text']);
        $this->summary['markup_hash_equal'] = self::hash($before['body']['markup'] ?? null) === self::hash($after['body']['markup'] ?? null);
        $this->summary['link_hash_equal'] = self::hash($before['link'] ?? null) === self::hash($after['link'] ?? null);
        $this->summary['keyboard_verified'] = self::existingButton($after);
        // Also verify original keyboard rows survived, in their original order.
        $beforeRows = [];
        $afterRows = [];
        foreach ($before['body']['attachments'] as $a) if (($a['type'] ?? '') === 'inline_keyboard') $beforeRows = $a['payload']['buttons'] ?? [];
        foreach ($after['body']['attachments'] as $a) if (($a['type'] ?? '') === 'inline_keyboard') $afterRows = $a['payload']['buttons'] ?? [];
        $this->summary['original_keyboard_equal'] = self::hash($beforeRows) === self::hash(array_slice($afterRows, 0, count($beforeRows)));
        return $this->summary['image_ids_equal'] && $this->summary['text_hash_equal'] && $this->summary['markup_hash_equal'] && $this->summary['link_hash_equal'] && $this->summary['keyboard_verified'] && $this->summary['original_keyboard_equal'];
    }

    public function run(bool $inspectOnly): array
    {
        try {
            $me = self::readable($this->request('GET', '/me', [], null, 'bot_identity'));
            self::check(($me['is_bot'] ?? null) === true && ($me['username'] ?? null) === self::BOT, 'bot_identity_mismatch');
            self::check(isset($me['user_id']) && (is_int($me['user_id']) || (is_string($me['user_id']) && preg_match('/\A\d+\z/', $me['user_id']))), 'bot_id_missing');
            $this->summary['bot_match'] = true;
            $member = self::readable($this->request('GET', '/chats/' . self::CHANNEL . '/members/me', [], null, 'channel_membership'));
            self::check(($member['is_bot'] ?? null) === true && ($member['username'] ?? null) === self::BOT && (string)($member['user_id'] ?? '') === (string)($me['user_id'] ?? ''), 'membership_identity_mismatch');
            self::check(($member['is_admin'] ?? false) === true, 'bot_not_channel_admin');
            $permissions = is_array($member['permissions'] ?? null) ? $member['permissions'] : [];
            foreach (['read_all_messages', 'edit', 'edit_message', 'write', 'post_edit_delete_message'] as $p) {
                if (in_array($p, $permissions, true)) $this->summary['rights'][] = $p;
            }
            self::check(in_array('read_all_messages', $permissions, true), 'read_permission_missing');
            if (!$inspectOnly) self::check(in_array('edit', $permissions, true) || in_array('edit_message', $permissions, true), 'edit_permission_missing');
            $list = self::readable($this->request('GET', '/messages', ['chat_id' => self::CHANNEL, 'count' => 20], null, 'bounded_channel_posts'));
            self::check(is_array($list['messages'] ?? null) && count($list['messages']) <= 20, 'message_list_invalid');
            $this->summary['message_count'] = count($list['messages']);
            if ($inspectOnly) {
                $this->summary['post_candidates'] = [];
                foreach ($list['messages'] as $candidate) {
                    if (!is_array($candidate)) continue;
                    $url = $candidate['url'] ?? null;
                    $urlAllowed = is_string($url) && strlen($url) <= 300
                        && (strpos($url, 'https://max.ru/c/' . self::CHANNEL . '/') === 0
                            || strpos($url, 'https://max.ru/c/' . ltrim(self::CHANNEL, '-') . '/') === 0)
                        && strpos($url, '?') === false && strpos($url, '#') === false;
                    $midCandidate = $candidate['body']['mid'] ?? null;
                    $midAllowed = is_string($midCandidate) && preg_match('/\A[a-zA-Z0-9_.-]{1,200}\z/', $midCandidate);
                    $this->summary['post_candidates'][] = [
                        'mid' => $midAllowed ? $midCandidate : null,
                        'url' => $urlAllowed ? $url : null,
                        'url_present' => is_string($url) && $url !== '',
                        'channel_match' => (string)($candidate['recipient']['chat_id'] ?? '') === self::CHANNEL,
                        'channel_type_match' => ($candidate['recipient']['chat_type'] ?? null) === 'channel',
                        'attachment_schema' => self::safeSchema(['body' => ['attachments' => is_array($candidate['body']['attachments'] ?? null) ? $candidate['body']['attachments'] : []]]),
                    ];
                }
            }
            $matches = array_values(array_filter($list['messages'], static function ($m): bool { return is_array($m) && self::targetMatches($m); }));
            self::check(count($matches) === 1, 'exact_post_not_unique_or_missing');
            $initial = $matches[0];
            $mid = self::validateMessage($initial);
            $current = self::readable($this->request('GET', '/messages/' . rawurlencode($mid), [], null, 'fresh_exact_post'));
            self::check(self::validateMessage($current) === $mid, 'fresh_mid_mismatch');
            $this->summary['read_comparison'] = [
                'text_equal' => self::hash($initial['body']['text']) === self::hash($current['body']['text']),
                'markup_equal' => self::hash($initial['body']['markup'] ?? null) === self::hash($current['body']['markup'] ?? null),
                'image_ids_equal' => self::imageIds($initial) === self::imageIds($current),
                'link_equal' => self::hash($initial['link'] ?? null) === self::hash($current['link'] ?? null),
                'content_fingerprint_equal' => self::fingerprint($initial) === self::fingerprint($current),
            ];
            self::check(self::fingerprint($initial) === self::fingerprint($current), 'post_changed_during_read');
            $this->summary['attachment_schema'] = self::safeSchema($current);
            $this->summary['image_ids_before'] = self::imageIds($current);
            $this->summary['image_count_before'] = count($this->summary['image_ids_before']);
            self::check($this->summary['image_count_before'] > 0, 'expected_image_missing');
            if (self::existingButton($current)) {
                $this->verify($current, $current);
                $this->summary['status'] = 'already_present';
                return $this->summary;
            }
            try {
                $body = self::appendKeyboard($current);
                $this->summary['request_mapping_ready'] = true;
            } catch (MaxChannelButtonProbeFailure $e) {
                $this->summary['request_mapping_ready'] = false;
                if (!$inspectOnly) throw $e;
                $this->summary['mapping_block_reason'] = $e->getMessage();
            }
            if ($inspectOnly) {
                $this->summary['status'] = 'inspected';
                return $this->summary;
            }
            self::check(($this->backup)($current) === true, 'private_backup_failed');
            $this->summary['private_backup_saved'] = true;
            $put = $this->request('PUT', '/messages', ['message_id' => $mid], $body, 'single_button_edit');
            $errorCode = is_array($put['data']) ? ($put['data']['code'] ?? null) : null;
            if (is_string($errorCode) && preg_match('/\A[a-z][a-z_.-]{0,79}\z/', $errorCode)) {
                $this->summary['api_error_code'] = $errorCode;
            }
            $explicitReject = !$put['transport_error'] && (($put['http'] >= 400 && $put['http'] < 500) || (is_array($put['data']) && ($put['data']['success'] ?? null) === false));
            $accepted = !$put['transport_error'] && $put['http'] === 200 && is_array($put['data']) && ($put['data']['success'] ?? null) === true;
            $this->summary['write_response'] = $explicitReject ? 'rejected' : ($accepted ? 'accepted' : 'unknown');
            // Always reconcile once. Never retry PUT, including transport timeout.
            $after = self::readable($this->request('GET', '/messages/' . rawurlencode($mid), [], null, 'reconcile_exact_post'));
            self::check(self::validateMessage($after) === $mid, 'reconcile_mid_mismatch');
            $verified = $this->verify($current, $after);
            if ($explicitReject) {
                $this->summary['status'] = 'rejected';
                $this->summary['reason'] = 'api_rejected_edit';
            } elseif ($verified) {
                $this->summary['status'] = $accepted ? 'verified' : 'verified_after_unknown_response';
            } else {
                $this->summary['status'] = 'unverified';
                $this->summary['reason'] = 'readback_did_not_verify_preservation_and_button';
            }
        } catch (MaxChannelButtonProbeFailure $e) {
            $this->summary['reason'] = $e->getMessage();
            if ($this->summary['write_attempts'] > 0) $this->summary['status'] = 'unverified';
        } catch (Throwable $e) {
            $this->summary['reason'] = 'internal_failure';
            if ($this->summary['write_attempts'] > 0) $this->summary['status'] = 'unverified';
        }
        return $this->summary;
    }
}

function maxChannelButtonProbeMain(array $argv): int
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    error_reporting(0);
    $summary = ['status' => 'blocked', 'reason' => 'startup_failure', 'request_count' => 0, 'write_attempts' => 0];
    try {
        $sha = null;
        $mode = null;
        foreach (array_slice($argv, 1) as $arg) {
            if (strpos($arg, '--expected-sha=') === 0 && $sha === null) $sha = substr($arg, 15);
            elseif ($arg === '--inspect-only' && $mode === null) $mode = 'inspect';
            elseif ($arg === '--apply' && $mode === null) $mode = 'apply';
            else throw new MaxChannelButtonProbeFailure('invalid_arguments');
        }
        if ($sha === null) $sha = getenv('MAX_CHANNEL_PROBE_EXPECTED_SHA') ?: null;
        if (!is_string($sha) || !preg_match('/\A[a-f0-9]{40}\z/', $sha) || $mode === null) throw new MaxChannelButtonProbeFailure('sha_or_mode_missing');
        $script = realpath(__FILE__) ?: '';
        if (strpos($script, '/tmp/') !== 0 || ((fileperms($script) & 0777) !== 0700) || ((fileperms(dirname($script)) & 0777) !== 0700)) throw new MaxChannelButtonProbeFailure('script_not_private_temp');
        $head = trim((string)shell_exec('git -C ' . escapeshellarg(MaxChannelButtonProbe::APP) . ' rev-parse HEAD 2>/dev/null'));
        if ($head !== $sha) throw new MaxChannelButtonProbeFailure('server_sha_mismatch');
        if (!extension_loaded('curl')) throw new MaxChannelButtonProbeFailure('curl_missing');
        // Only load deployment secrets after the fixed app checkout passes SHA gate.
        ob_start();
        try {
            require_once MaxChannelButtonProbe::APP . '/config.php';
            require_once MaxChannelButtonProbe::APP . '/services/MaxTlsConfig.php';
        } finally {
            ob_end_clean();
        }
        if (!defined('MAX_SEARCH_TOKEN') || !is_string(MAX_SEARCH_TOKEN) || trim(MAX_SEARCH_TOKEN) === '') throw new MaxChannelButtonProbeFailure('max_token_missing');
        $token = MAX_SEARCH_TOKEN;
        $tls = MaxTlsConfig::strictCurlOptions();
        if (($tls[CURLOPT_SSL_VERIFYPEER] ?? false) !== true || ($tls[CURLOPT_SSL_VERIFYHOST] ?? 0) !== 2) throw new MaxChannelButtonProbeFailure('strict_tls_missing');
        $transport = static function (string $method, string $path, array $query, ?array $body) use ($token, $tls): array {
            $url = 'https://platform-api2.max.ru' . $path;
            if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $ch = curl_init($url);
            $opts = $tls + [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => ['Authorization: ' . $token, 'Accept: application/json', 'Content-Type: application/json'],
            ];
            if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_errno($ch) !== 0;
            curl_close($ch);
            $data = is_string($raw) && strlen($raw) <= 2097152 ? json_decode($raw, true, 64) : null;
            unset($raw);
            return ['http' => $http, 'data' => is_array($data) ? $data : null, 'transport_error' => $err];
        };
        $backup = static function (array $message): bool {
            // The workflow owns this mode-0700 temporary directory and retains it
            // on an unverified write so the exact pre-edit body remains available.
            $dir = dirname(__FILE__);
            $oldUmask = umask(0077);
            try {
                if (strpos($dir, '/tmp/') !== 0 || ((fileperms($dir) & 0777) !== 0700)) return false;
                $raw = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (strlen($raw) > 1048576) return false;
                $handle = fopen($dir . '/original.json', 'x');
                if ($handle === false) return false;
                try {
                    if (!chmod($dir . '/original.json', 0600)) return false;
                    return fwrite($handle, $raw) === strlen($raw) && fflush($handle);
                } finally { fclose($handle); }
            } finally { umask($oldUmask); }
        };
        $summary = (new MaxChannelButtonProbe($transport, $backup))->run($mode === 'inspect');
        $summary['server_sha_match'] = true;
        $summary['mode'] = $mode;
    } catch (MaxChannelButtonProbeFailure $e) {
        $summary['reason'] = $e->getMessage();
    } catch (Throwable $e) {
        $summary['reason'] = 'startup_failure';
    }
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    return in_array($summary['status'], ['inspected', 'already_present', 'verified', 'verified_after_unknown_response'], true) ? 0 : 2;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(maxChannelButtonProbeMain($argv));
}
