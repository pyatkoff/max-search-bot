<?php
declare(strict_types=1);
require_once __DIR__ . '/../integrations/ChannelPostApiTransport.php';
require_once __DIR__ . '/../integrations/ChannelPostIncomingAdapter.php';

/**
 * Bounded channel-only edits. No uploads, customer sends, webhook/config changes,
 * retry loops or raw provider logging. The caller durably claims a write first.
 */
final class ChannelPostButtonTransport
{
    private $request;
    private array $receipt = [];

    public function __construct(?callable $request = null)
    {
        $this->request = $request ?: [self::class, 'networkRequest'];
    }

    /** Read-only capability check for explicit channel opt-in in the admin UI. */
    public function verifyChannel(array $channel): array
    {
        $this->receipt = ['request_count' => 0, 'write_attempts' => 0, 'http_results' => []];
        try {
            $platform = (string)($channel['platform'] ?? '');
            $id = (string)($channel['channel_id'] ?? '');
            $bot = (string)($channel['canonical_bot_username'] ?? $channel['bot_key'] ?? '');
            self::check(in_array($platform, ['max', 'telegram'], true), 'unsupported_platform');
            self::check(ChannelPostIncomingAdapter::channelId($id) !== '', 'invalid_channel_id');
            self::check(self::botUrlMatches($platform, (string)($channel['bot_url'] ?? ''), $bot), 'canonical_bot_url_mismatch');
            $this->identity($platform, $bot, $id);
            $chat = $platform === 'max'
                ? self::readable($this->call('max', 'GET', '/chats/' . $id, [], null, 'channel_type'), 'max')
                : self::readable($this->call('telegram', 'POST', 'getChat', [], ['chat_id' => $id], 'channel_type'), 'telegram');
            self::check(($chat['type'] ?? '') === 'channel' && (string)($chat[$platform === 'max' ? 'chat_id' : 'id'] ?? '') === $id, 'channel_type_mismatch');
            return $this->result('ready', 'capability_verified');
        } catch (ChannelPostTransportFailure $e) {
            return $this->result($e->getMessage() === 'provider_read_failed' ? 'unknown' : 'requires_attention', $e->getMessage());
        } catch (Throwable $e) {
            return $this->result('unknown', 'verification_failure');
        }
    }

    public function attempt(array $operation, callable $beforeWrite, bool $reconcileOnly = false): array
    {
        $this->receipt = ['request_count' => 0, 'write_attempts' => 0, 'http_results' => []];
        try {
            $platform = (string)($operation['platform'] ?? $operation['channel']['platform'] ?? '');
            $channel = (array)($operation['channel'] ?? []);
            $channelId = (string)($channel['channel_id'] ?? $operation['channel_id'] ?? '');
            $mid = (string)($operation['external_message_id'] ?? $operation['message_id'] ?? $operation['snapshot']['metadata']['actual_message_id'] ?? '');
            $snapshot = (array)($operation['snapshot'] ?? []);
            $url = self::buttonUrl($operation);
            $label = (string)($channel['button_text'] ?? 'Рассчитать на мои даты');
            $bot = (string)($channel['canonical_bot_username'] ?? $channel['bot_key'] ?? $operation['bot_key'] ?? '');
            self::check(in_array($platform, ['max', 'telegram'], true), 'unsupported_platform');
            self::check(ChannelPostIncomingAdapter::channelId($channelId) !== '', 'invalid_channel_id');
            self::check(($channel['is_enabled'] ?? false) && ($channel['post_mode'] ?? 'append_button') === 'append_button', 'channel_not_enabled');
            self::check($bot !== '' && self::botUrlMatches($platform, (string)($channel['bot_url'] ?? ''), $bot), 'canonical_bot_url_mismatch');
            self::check($url !== '' && $label !== '' && self::length($label) <= 128, 'invalid_button');
            self::check(($snapshot['metadata']['capability'] ?? '') === 'supported', (string)($snapshot['metadata']['capability_reason'] ?? 'unsupported_post'));
            self::check($mid !== '' && strpos($mid, 'group:') !== 0, 'album_not_supported');
            $this->identity($platform, $bot, $channelId);

            if ($platform === 'max') {
                self::check((bool)preg_match('/\A[a-zA-Z0-9_.-]{1,200}\z/', $mid), 'invalid_message_id');
                $current = $this->maxMessage($mid, $channelId, 'current_post');
                $currentSnapshot = ChannelPostIncomingAdapter::maxSnapshot($current);
                self::check(is_array($currentSnapshot), 'current_post_invalid');
                $expectedHash = ChannelPostIncomingAdapter::contentFingerprint($snapshot, $url, (string)$channel['bot_url']);
                self::check($expectedHash === ChannelPostIncomingAdapter::contentFingerprint($currentSnapshot, $url, (string)$channel['bot_url']), 'post_changed_since_event');
                self::check(($currentSnapshot['metadata']['capability'] ?? '') === 'supported', (string)($currentSnapshot['metadata']['capability_reason'] ?? 'unsupported_post'));
                $body = self::maxEditBody($current, $url, $label, (string)$channel['bot_url']);
                if (ChannelPostIncomingAdapter::containsButton($currentSnapshot, $url, $label)) return $this->result('sent', 'already_present');
                if ($reconcileOnly) return $this->result('unknown', 'button_not_observed');
                self::check($beforeWrite() === true, 'write_claim_or_config_changed');
                $put = $this->call('max', 'PUT', '/messages', ['message_id' => $mid], $body, 'append_button');
                // A lost response may follow a successful edit. Read once; never retry PUT.
                try {
                    $after = $this->maxMessage($mid, $channelId, 'verify_post');
                    $afterSnapshot = ChannelPostIncomingAdapter::maxSnapshot($after);
                    $contentEqual = is_array($afterSnapshot)
                        && ChannelPostIncomingAdapter::contentFingerprint($currentSnapshot, $url, (string)$channel['bot_url']) === ChannelPostIncomingAdapter::contentFingerprint($afterSnapshot, $url, (string)$channel['bot_url'])
                        && self::hash($current['body']['text']) === self::hash($after['body']['text'])
                        && self::hash($current['body']['markup'] ?? null) === self::hash($after['body']['markup'] ?? null)
                        && self::hash($current['link'] ?? null) === self::hash($after['link'] ?? null);
                    $this->receipt['content_preserved'] = $contentEqual;
                    $this->receipt['button_verified'] = is_array($afterSnapshot) && ChannelPostIncomingAdapter::containsButton($afterSnapshot, $url, $label);
                    if ($contentEqual && $this->receipt['button_verified']) return $this->result('sent', self::accepted($put, 'max') ? 'verified' : 'verified_after_unknown_response');
                } catch (ChannelPostTransportFailure $e) {
                    $this->receipt['verification_reason'] = $e->getMessage();
                }
                return $this->result(self::explicitReject($put) ? 'requires_attention' : 'unknown', self::explicitReject($put) ? 'provider_rejected_edit' : 'edit_outcome_unknown');
            }

            self::check((bool)preg_match('/\A[1-9][0-9]{0,19}\z/', $mid), 'invalid_message_id');
            $rows = self::appendRows((array)($snapshot['keyboard'] ?? []), $url, $label, (string)$channel['bot_url'], false);
            if (ChannelPostIncomingAdapter::containsButton($snapshot, $url, $label)) return $this->result('sent', 'observed_in_channel_update');
            // Telegram Bot API has no getMessage. UNKNOWN is reconciled only by a
            // subsequently observed edited_channel_post, never a second edit.
            if ($reconcileOnly) return $this->result('unknown', 'telegram_awaiting_edit_event');
            self::check($beforeWrite() === true, 'write_claim_or_config_changed');
            $edited = $this->call('telegram', 'POST', 'editMessageReplyMarkup', [], [
                'chat_id' => $channelId, 'message_id' => $mid, 'reply_markup' => ['inline_keyboard' => $rows],
            ], 'append_button');
            if (self::accepted($edited, 'telegram') && is_array($edited['data']['result'] ?? null)) {
                $returned = $edited['data']['result'];
                self::check((string)($returned['chat']['id'] ?? '') === $channelId && (string)($returned['message_id'] ?? '') === $mid, 'edited_post_identity_mismatch');
                $after = ChannelPostIncomingAdapter::telegramSnapshot($returned);
                $contentEqual = is_array($after)
                    && ChannelPostIncomingAdapter::contentFingerprint($snapshot, $url, (string)$channel['bot_url']) === ChannelPostIncomingAdapter::contentFingerprint($after, $url, (string)$channel['bot_url']);
                $this->receipt['content_preserved'] = $contentEqual;
                $this->receipt['button_verified'] = is_array($after) && ChannelPostIncomingAdapter::containsButton($after, $url, $label);
                if ($contentEqual && $this->receipt['button_verified']) return $this->result('sent', 'verified');
            }
            return $this->result(self::explicitReject($edited) ? 'requires_attention' : 'unknown', self::explicitReject($edited) ? 'provider_rejected_edit' : 'edit_outcome_unknown');
        } catch (ChannelPostTransportFailure $e) {
            return $this->result($this->receipt['write_attempts'] > 0 ? 'unknown' : 'requires_attention', $e->getMessage());
        } catch (Throwable $e) {
            return $this->result($this->receipt['write_attempts'] > 0 ? 'unknown' : 'requires_attention', 'transport_failure');
        }
    }

    public static function buttonUrl(array $operation): string
    {
        $token = (string)($operation['start_payload'] ?? '');
        $base = rtrim((string)($operation['channel']['bot_url'] ?? ''), '/');
        if (!preg_match('/\Ap_[A-Za-z0-9_-]{8,60}\z/', $token)) return '';
        return $base !== '' ? $base . '?start=' . rawurlencode($token) : '';
    }

    private function identity(string $platform, string $bot, string $channelId): void
    {
        if ($platform === 'max') {
            $me = self::readable($this->call('max', 'GET', '/me', [], null, 'bot_identity'), 'max');
            self::check(($me['is_bot'] ?? null) === true && ($me['username'] ?? null) === $bot && isset($me['user_id']), 'canonical_bot_identity_mismatch');
            $member = self::readable($this->call('max', 'GET', '/chats/' . $channelId . '/members/me', [], null, 'channel_rights'), 'max');
            self::check(($member['is_bot'] ?? null) === true && ($member['username'] ?? null) === $bot && (string)($member['user_id'] ?? '') === (string)$me['user_id'], 'membership_identity_mismatch');
            self::check(($member['is_admin'] ?? false) === true, 'channel_admin_required');
            $permissions = (array)($member['permissions'] ?? []);
            self::check(in_array('read_all_messages', $permissions, true), 'channel_read_permission_required');
            self::check(in_array('edit', $permissions, true) || in_array('edit_message', $permissions, true), 'channel_edit_permission_required');
            return;
        }
        $me = self::readable($this->call('telegram', 'POST', 'getMe', [], [], 'bot_identity'), 'telegram');
        self::check(($me['is_bot'] ?? null) === true && ($me['username'] ?? null) === $bot && isset($me['id']), 'canonical_bot_identity_mismatch');
        $member = self::readable($this->call('telegram', 'POST', 'getChatMember', [], ['chat_id' => $channelId, 'user_id' => $me['id']], 'channel_rights'), 'telegram');
        self::check((string)($member['user']['id'] ?? '') === (string)$me['id'] && ($member['user']['username'] ?? null) === $bot, 'membership_identity_mismatch');
        self::check(($member['status'] ?? '') === 'creator' || (($member['status'] ?? '') === 'administrator' && ($member['can_edit_messages'] ?? false) === true), 'channel_edit_permission_required');
    }

    private function maxMessage(string $mid, string $channelId, string $purpose): array
    {
        $message = self::readable($this->call('max', 'GET', '/messages/' . rawurlencode($mid), [], null, $purpose), 'max');
        self::check(($message['body']['mid'] ?? '') === $mid && (string)($message['recipient']['chat_id'] ?? '') === $channelId && ($message['recipient']['chat_type'] ?? '') === 'channel', 'post_identity_mismatch');
        return $message;
    }

    private static function maxEditBody(array $message, string $url, string $label, string $botUrl): array
    {
        $out = []; $keyboardIndex = null;
        foreach ((array)($message['body']['attachments'] ?? []) as $attachment) {
            self::check(is_array($attachment) && is_array($attachment['payload'] ?? null), 'invalid_attachment');
            $type = $attachment['type'] ?? ''; $payload = $attachment['payload'];
            if ($type === 'image') {
                self::check(is_string($payload['token'] ?? null) && $payload['token'] !== '', 'image_token_missing');
                // Official existing-image request mapping. Never reimport the URL.
                $out[] = ['type' => 'image', 'payload' => ['token' => $payload['token']]];
            } elseif ($type === 'inline_keyboard') {
                self::check($keyboardIndex === null && is_array($payload['buttons'] ?? null), 'invalid_keyboard');
                $keyboardIndex = count($out);
                $out[] = ['type' => 'inline_keyboard', 'payload' => ['buttons' => self::appendRows($payload['buttons'], $url, $label, $botUrl, true)]];
            } else throw new ChannelPostTransportFailure('unsupported_media');
        }
        if ($keyboardIndex === null) $out[] = ['type' => 'inline_keyboard', 'payload' => ['buttons' => [[['type' => 'link', 'text' => $label, 'url' => $url]]]]];
        self::check(count($out) <= 12, 'attachment_limit');
        // MAX observed probe proved attachments-only PUT preserves native text,
        // caption entities and links. Do not reconstruct any of those fields.
        return ['attachments' => $out];
    }

    private static function appendRows(array $rows, string $url, string $label, string $botUrl, bool $max): array
    {
        self::check(count($rows) <= 30, 'keyboard_row_limit');
        $ownedCount = 0; $exactCount = 0;
        foreach ($rows as $row) {
            self::check(is_array($row) && $row !== [] && count($row) <= ($max ? 7 : 8), 'invalid_keyboard_row');
            foreach ($row as $button) {
                self::check(is_array($button) && is_string($button['text'] ?? null) && is_string($button['url'] ?? null), 'foreign_keyboard_owner');
                self::check(!$max || ($button['type'] ?? '') === 'link', 'foreign_keyboard_owner');
                self::check($max || array_diff(array_keys($button), ['text', 'url']) === [], 'foreign_keyboard_owner');
                self::check((bool)preg_match('/\Ahttps?:\/\/[^\s]{1,2048}\z/', $button['url']), 'invalid_keyboard_url');
                $owned = preg_match('/\A' . preg_quote(rtrim($botUrl, '/') . '?start=', '/') . 'p_[A-Za-z0-9_-]{8,60}\z/', $button['url']);
                if ($owned) $ownedCount++;
                if ($button['url'] === $url && $button['text'] === $label) $exactCount++;
            }
        }
        self::check($ownedCount <= 1 && ($ownedCount === 0 || $exactCount === 1), 'conflicting_publication_button');
        if ($exactCount === 1) return $rows;
        self::check(count($rows) < 30, 'keyboard_row_limit');
        $button = ['text' => $label, 'url' => $url];
        if ($max) $button = ['type' => 'link'] + $button;
        $rows[] = [$button];
        return $rows;
    }

    private function call(string $platform, string $method, string $path, array $query, ?array $body, string $purpose): array
    {
        self::check($this->receipt['request_count'] < 5, 'request_budget_exhausted');
        if ($purpose === 'append_button') {
            self::check($this->receipt['write_attempts'] === 0, 'write_budget_exhausted');
            $this->receipt['write_attempts']++;
        }
        $this->receipt['request_count']++;
        try { $result = ($this->request)($platform, $method, $path, $query, $body); }
        catch (Throwable $e) { $result = ['http' => 0, 'data' => null, 'transport_error' => true]; }
        $response = ['http' => (int)($result['http'] ?? 0), 'data' => is_array($result['data'] ?? null) ? $result['data'] : null, 'transport_error' => ($result['transport_error'] ?? false) === true];
        $this->receipt['http_results'][] = ['operation' => $purpose, 'http' => $response['http'], 'transport_error' => $response['transport_error']];
        return $response;
    }

    private static function readable(array $response, string $platform): array
    {
        self::check(!$response['transport_error'] && $response['http'] >= 200 && $response['http'] < 300 && is_array($response['data']), 'provider_read_failed');
        if ($platform === 'telegram') {
            self::check(($response['data']['ok'] ?? false) === true && is_array($response['data']['result'] ?? null), 'provider_read_failed');
            return $response['data']['result'];
        }
        return $response['data'];
    }

    private static function accepted(array $response, string $platform): bool
    {
        return !$response['transport_error'] && $response['http'] >= 200 && $response['http'] < 300
            && is_array($response['data']) && ($response['data'][$platform === 'max' ? 'success' : 'ok'] ?? false) === true;
    }

    private static function explicitReject(array $response): bool
    {
        return !$response['transport_error'] && (($response['http'] >= 400 && $response['http'] < 500)
            || (is_array($response['data']) && (($response['data']['success'] ?? null) === false || ($response['data']['ok'] ?? null) === false)));
    }

    private static function botUrlMatches(string $platform, string $url, string $bot): bool
    {
        return $url === ($platform === 'max' ? 'https://max.ru/' : 'https://t.me/') . $bot;
    }

    private function result(string $state, string $reason): array
    {
        return ['state' => $state, 'reason' => $reason, 'receipt' => $this->receipt];
    }

    private static function check(bool $condition, string $reason): void
    {
        if (!$condition) throw new ChannelPostTransportFailure($reason !== '' ? $reason : 'unsupported_post');
    }

    private static function hash($value): string { return hash('sha256', (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
    private static function length(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value); }

    /** Compatibility injection seam; provider HTTP implementation is integration-owned. */
    public static function networkRequest(string $platform, string $method, string $path, array $query, ?array $body): array
    {
        return ChannelPostApiTransport::buttonRequest($platform, $method, $path, $query, $body);
    }
}

final class ChannelPostTransportFailure extends RuntimeException {}
