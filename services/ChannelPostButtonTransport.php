<?php
declare(strict_types=1);
require_once __DIR__ . '/../integrations/ChannelPostApiTransport.php';
require_once __DIR__ . '/../integrations/ChannelPostIncomingAdapter.php';
require_once __DIR__ . '/ChannelPostTelegramAlbum.php';

/**
 * Bounded channel-only edits. No uploads, customer sends, webhook/config changes,
 * retry loops or raw provider logging. The caller durably claims a write first.
 */
final class ChannelPostButtonTransport
{
    private $request;
    private bool $customRequest;
    private array $receipt = [];

    public function __construct(?callable $request = null)
    {
        $this->customRequest = $request !== null
            && $request !== [self::class, 'networkRequest'] && $request !== self::class.'::networkRequest'
            && $request !== [ChannelPostApiTransport::class, 'buttonRequest'] && $request !== ChannelPostApiTransport::class.'::buttonRequest';
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
            $verifyPublicUrl = array_key_exists('expected_public_url', $channel);
            $expectedUrl = $channel['expected_public_url'] ?? null;
            if ($verifyPublicUrl) {
                self::check($platform === 'max' && is_string($expectedUrl)
                    && preg_match('~\Ahttps://max\.ru/[A-Za-z0-9_]{1,191}\z~D', $expectedUrl) === 1,
                    'invalid_expected_public_channel_url');
            }
            $this->identity($platform, $bot, $id);
            $chat = $platform === 'max'
                ? self::readable($this->call('max', 'GET', '/chats/' . $id, [], null, 'channel_type'), 'max')
                : self::readable($this->call('telegram', 'POST', 'getChat', [], ['chat_id' => $id], 'channel_type'), 'telegram');
            self::check(($chat['type'] ?? '') === 'channel' && (string)($chat[$platform === 'max' ? 'chat_id' : 'id'] ?? '') === $id, 'channel_type_mismatch');
            if ($verifyPublicUrl) {
                self::check(($chat['status'] ?? null) === 'active' && ($chat['is_public'] ?? null) === true
                    && is_string($chat['link'] ?? null) && rtrim($chat['link'], '/') === $expectedUrl,
                    'public_channel_identity_mismatch');
            }
            return $this->result('ready', 'capability_verified');
        } catch (ChannelPostTransportFailure $e) {
            return $this->result($e->getMessage() === 'provider_read_failed' ? 'unknown' : 'requires_attention', $e->getMessage());
        } catch (Throwable $e) {
            return $this->result('unknown', 'verification_failure');
        }
    }

    /** Read-only proof for the separately saved publisher; the entry bot stays anyru_bot. */
    public function verifyPublisher(array $channel, array $binding): array
    {
        $this->receipt = ['request_count'=>0, 'write_attempts'=>0, 'http_results'=>[]];
        try {
            $this->publisherContext($channel, $binding);
            $this->publisherIdentity($binding);
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
            $manual = array_key_exists('manual_button_repair', $operation)
                || isset($operation['publisher']) || ($operation['receipt']['repair_kind'] ?? '') === 'aloft_public_keyboard'
                || ($snapshot['metadata']['observation_kind'] ?? '') === 'public_read';
            self::check(in_array($platform, ['max', 'telegram'], true), 'unsupported_platform');
            self::check(ChannelPostIncomingAdapter::channelId($channelId) !== '', 'invalid_channel_id');
            self::check(($channel['is_enabled'] ?? false) && ($channel['post_mode'] ?? 'append_button') === 'append_button', 'channel_not_enabled');
            self::check($bot !== '' && self::botUrlMatches($platform, (string)($channel['bot_url'] ?? ''), $bot), 'canonical_bot_url_mismatch');
            self::check($url !== '' && $label !== '' && self::length($label) <= 128, 'invalid_button');
            if ($manual) {
                $this->manualOperation($operation, $channel, $snapshot, $mid);
                if ($reconcileOnly || !empty($operation['write_attempted']) || ($operation['write_attempts'] ?? 0) > 0)
                    return $this->result('unknown', 'manual_edit_requires_native_reconciliation');
                self::check(($operation['state'] ?? '') === 'claimed'
                    && is_string($operation['claim_token'] ?? null)
                    && preg_match('/\A[a-f0-9]{32}\z/D', $operation['claim_token']) === 1
                    && ($operation['write_attempts'] ?? null) === 0, 'manual_write_claim_required');
            } else {
                self::check(($snapshot['metadata']['capability'] ?? '') === 'supported', (string)($snapshot['metadata']['capability_reason'] ?? 'unsupported_post'));
            }
            $telegramAlbum = $platform === 'telegram' && str_starts_with($mid, 'group:');
            $targetSnapshot = $telegramAlbum ? ChannelPostTelegramAlbum::target($snapshot) : $snapshot;
            if ($telegramAlbum) {
                self::check($mid === 'group:' . (string)($snapshot['metadata']['media_group_id'] ?? ''), 'album_identity_mismatch');
                $mid = (string)($targetSnapshot['metadata']['actual_message_id'] ?? '');
            }
            self::check($mid !== '' && strpos($mid, 'group:') !== 0, 'invalid_message_id');
            if ($manual) $this->publisherIdentity($operation['publisher']);
            else $this->identity($platform, $bot, $channelId);

            if ($platform === 'max') {
                self::check((bool)preg_match('/\A[a-zA-Z0-9_.-]{1,200}\z/', $mid), 'invalid_message_id');
                $current = $this->maxMessage($mid, $channelId, 'current_post');
                $currentSnapshot = ChannelPostIncomingAdapter::maxSnapshot($current);
                self::check(is_array($currentSnapshot), 'current_post_invalid');
                self::check(self::maxContentMatches($snapshot, $currentSnapshot), 'post_changed_since_event');
                self::check(($currentSnapshot['metadata']['capability'] ?? '') === 'supported', (string)($currentSnapshot['metadata']['capability_reason'] ?? 'unsupported_post'));
                $body = self::maxEditBody($current, $url, $label, (string)$channel['bot_url']);
                if (ChannelPostIncomingAdapter::containsOnlyButton($currentSnapshot, $url, $label)) return $this->result('sent', 'already_present');
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
                    $this->receipt['button_verified'] = is_array($afterSnapshot) && ChannelPostIncomingAdapter::containsOnlyButton($afterSnapshot, $url, $label);
                    if ($contentEqual && $this->receipt['button_verified']) return $this->result('sent', self::accepted($put, 'max') ? 'verified' : 'verified_after_unknown_response');
                } catch (ChannelPostTransportFailure $e) {
                    $this->receipt['verification_reason'] = $e->getMessage();
                }
                return $this->result(self::explicitReject($put) ? 'requires_attention' : 'unknown', self::explicitReject($put) ? 'provider_rejected_edit' : 'edit_outcome_unknown');
            }

            self::check((bool)preg_match('/\A[1-9][0-9]{0,19}\z/', $mid), 'invalid_message_id');
            $clear = ($operation['button_mode'] ?? '') === 'clear_keyboard';
            self::check(!$manual || !$clear, 'manual_keyboard_cleanup_forbidden');
            self::check(!$clear || isset($operation['delivery_member_id']), 'unclaimed_keyboard_cleanup');
            $rows = $clear ? [] : [[['text'=>$label, 'url'=>$url]]];
            $correct = $clear ? empty($targetSnapshot['keyboard']) : ChannelPostIncomingAdapter::containsOnlyButton($targetSnapshot, $url, $label);
            if ($correct) return $manual
                ? $this->result('requires_attention', 'public_button_requires_native_verification')
                : $this->result('sent', 'observed_in_channel_update');
            // Telegram Bot API has no getMessage. UNKNOWN is reconciled only by a
            // subsequently observed edited_channel_post, never a second edit.
            if ($reconcileOnly) return $this->result('unknown', 'telegram_awaiting_edit_event');
            self::check($beforeWrite() === true, 'write_claim_or_config_changed');
            $edited = $this->call('telegram', 'POST', 'editMessageReplyMarkup', [], [
                'chat_id' => $channelId, 'message_id' => $mid, 'reply_markup' => ['inline_keyboard' => $rows],
            ], 'append_button');
            if ($manual) return $this->verifyManualEdit($edited, $snapshot, $url, $label);
            if (self::accepted($edited, 'telegram') && is_array($edited['data']['result'] ?? null)) {
                $returned = $edited['data']['result'];
                self::check((string)($returned['chat']['id'] ?? '') === $channelId && (string)($returned['message_id'] ?? '') === $mid, 'edited_post_identity_mismatch');
                $after = ChannelPostIncomingAdapter::telegramSnapshot($returned);
                $contentEqual = is_array($after)
                    && ChannelPostIncomingAdapter::contentFingerprint($targetSnapshot, $url, (string)$channel['bot_url']) === ChannelPostIncomingAdapter::contentFingerprint($after, $url, (string)$channel['bot_url']);
                $this->receipt['content_preserved'] = $contentEqual;
                $this->receipt['button_verified'] = is_array($after) && ($clear ? empty($after['keyboard']) : ChannelPostIncomingAdapter::containsOnlyButton($after, $url, $label));
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

    private function publisherContext(array $channel, array $binding): void
    {
        self::check($this->customRequest, 'publisher_transport_required');
        self::check(($channel['project_key'] ?? '') === 'anytour' && ($channel['platform'] ?? '') === 'telegram'
            && ($channel['channel_id'] ?? '') === '-1001845835645'
            && ($channel['bot_key'] ?? '') === 'anyru_bot' && ($channel['canonical_bot_username'] ?? '') === 'anyru_bot'
            && ($channel['bot_url'] ?? '') === 'https://t.me/anyru_bot'
            && !empty($channel['is_enabled']) && ($channel['post_mode'] ?? 'append_button') === 'append_button',
            'manual_channel_identity_mismatch');
        self::check(count($binding) === 3 && ($binding['username'] ?? '') === 'ANYTOURBOT_bot'
            && is_string($binding['bot_id'] ?? null) && preg_match('/\A[1-9][0-9]{4,18}\z/D', $binding['bot_id']) === 1
            && is_int($binding['credential_version'] ?? null) && $binding['credential_version'] > 0,
            'publisher_binding_invalid');
        $this->receipt['publisher_username'] = $binding['username'];
        $this->receipt['publisher_bot_id'] = $binding['bot_id'];
        $this->receipt['credential_version'] = $binding['credential_version'];
    }

    private function publisherIdentity(array $binding): void
    {
        $me = self::readable($this->call('telegram', 'POST', 'getMe', [], [], 'bot_identity'), 'telegram');
        self::check(($me['is_bot'] ?? null) === true && is_string($me['username'] ?? null)
            && strcasecmp($me['username'], $binding['username']) === 0 && (string)($me['id'] ?? '') === $binding['bot_id'],
            'publisher_bot_identity_mismatch');
        $member = self::readable($this->call('telegram', 'POST', 'getChatMember', [],
            ['chat_id'=>'-1001845835645', 'user_id'=>$me['id']], 'channel_rights'), 'telegram');
        self::check(($member['user']['is_bot'] ?? null) === true
            && (string)($member['user']['id'] ?? '') === $binding['bot_id'] && is_string($member['user']['username'] ?? null)
            && strcasecmp($member['user']['username'], $binding['username']) === 0, 'membership_identity_mismatch');
        self::check(($member['status'] ?? '') === 'creator'
            || (($member['status'] ?? '') === 'administrator' && ($member['can_edit_messages'] ?? false) === true),
            'channel_edit_permission_required');
        $chat = self::readable($this->call('telegram', 'POST', 'getChat', [],
            ['chat_id'=>'-1001845835645'], 'channel_type'), 'telegram');
        self::check(($chat['type'] ?? '') === 'channel' && (string)($chat['id'] ?? '') === '-1001845835645'
            && ($chat['username'] ?? '') === 'anytour', 'channel_type_mismatch');
    }

    private function manualOperation(array $operation, array $channel, array $snapshot, string $mid): void
    {
        self::check(($operation['manual_button_repair'] ?? null) === true
            && ($operation['project_key'] ?? '') === 'anytour' && ($operation['platform'] ?? '') === 'telegram'
            && ($operation['bot_key'] ?? '') === 'anyru_bot' && $mid === '1678'
            && (int)($operation['publication_id'] ?? 0) > 0 && (int)($operation['version_id'] ?? 0) > 0
            && is_array($operation['publisher'] ?? null), 'manual_repair_context_required');
        $binding = $operation['publisher'];
        $this->publisherContext($channel, $binding);
        try { $baseline = ChannelPostIncomingAdapter::aloftPublicFingerprint($snapshot); }
        catch (Throwable $ignored) { throw new ChannelPostTransportFailure('aloft_public_baseline_invalid'); }
        $stored = $operation['receipt'] ?? [];
        self::check(is_array($stored) && ($stored['repair_kind'] ?? '') === 'aloft_public_keyboard'
            && ($stored['publisher_username'] ?? null) === $binding['username']
            && ($stored['publisher_bot_id'] ?? null) === $binding['bot_id']
            && ($stored['credential_version'] ?? null) === $binding['credential_version']
            && ($stored['channel_config_version'] ?? null) === (int)($channel['config_version'] ?? 0)
            && ($stored['channel_config_version'] ?? 0) > 0
            && ($stored['public_baseline_sha256'] ?? '') === $baseline
            && is_string($stored['plan_digest'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $stored['plan_digest']) === 1
            && preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/D', (string)($operation['start_payload'] ?? '')) === 1,
            'manual_repair_preview_changed');
        foreach (['repair_kind', 'channel_config_version', 'public_baseline_sha256', 'plan_digest'] as $key)
            $this->receipt[$key] = $stored[$key];
        $this->receipt['native_media_identity_before_verified'] = false;
    }

    private function verifyManualEdit(array $response, array $before, string $url, string $label): array
    {
        if (self::accepted($response, 'telegram') && is_array($response['data']['result'] ?? null)) {
            $message = $response['data']['result'];
            $this->receipt['provider_result_sha256'] = self::hash($message);
            $comparison = ChannelPostIncomingAdapter::aloftNativeComparison($message, $before);
            $keyboard = $message['reply_markup']['inline_keyboard'] ?? null;
            $button = is_array($keyboard) ? ($keyboard[0][0] ?? null) : null;
            $buttonVerified = is_array($keyboard) && array_is_list($keyboard) && count($keyboard) === 1
                && is_array($keyboard[0]) && array_is_list($keyboard[0]) && count($keyboard[0]) === 1
                && is_array($button) && count($button) === 2
                && ($button['text'] ?? null) === $label && ($button['url'] ?? null) === $url;
            foreach (['public_caption_matched', 'publication_date_matched', 'media_type_duration_matched',
                'native_media_identity_before_verified', 'native_video_unique_id'] as $key)
                if (array_key_exists($key, $comparison)) $this->receipt[$key] = $comparison[$key];
            $this->receipt['button_verified'] = $comparison['message_identity_matched'] && $buttonVerified;
            // This request has no caption or media fields. There was no native
            // pre-edit file ID; do not invent a before/after media comparison.
            $this->receipt['body_unchanged_by_method'] = true;
            if ($comparison['matches'] && $buttonVerified) {
                $this->receipt['verified'] = true;
                return $this->result('sent', 'verified');
            }
            return $this->result('unknown', 'manual_edit_verification_failed');
        }
        return $this->result(self::explicitReject($response) ? 'requires_attention' : 'unknown',
            self::explicitReject($response) ? 'provider_rejected_edit' : 'edit_outcome_unknown');
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

    private static function maxContentMatches(array $observed, array $current): bool
    {
        if (ChannelPostIncomingAdapter::contentFingerprint($observed) === ChannelPostIncomingAdapter::contentFingerprint($current)) return true;
        $markup = $observed['markup'] ?? null;
        $currentMarkup = $current['markup'] ?? null;
        if (!is_array($markup) || !array_is_list($markup) || !is_array($currentMarkup) || !array_is_list($currentMarkup)) return false;
        // MAX can include quote spans in its webhook but omit all of them in
        // GET /messages. Accept only that observed omission before our
        // attachments-only edit; retain every other content/formatting check.
        foreach ($currentMarkup as $span) if (is_array($span) && ($span['type'] ?? '') === 'quote') return false;
        $textLength = strlen(mb_convert_encoding((string)($observed['text'] ?? ''), 'UTF-16LE', 'UTF-8')) / 2;
        $withoutQuotes = []; $quotes = 0;
        foreach ($markup as $span) {
            if (!is_array($span) || ($span['type'] ?? '') !== 'quote') { $withoutQuotes[] = $span; continue; }
            if (count($span) !== 3 || !is_int($span['from'] ?? null) || !is_int($span['length'] ?? null)
                || $span['from'] < 0 || $span['length'] <= 0 || $span['from'] > $textLength
                || $span['length'] > $textLength - $span['from']) return false;
            $quotes++;
        }
        if ($quotes === 0) return false;
        $observed['markup'] = $withoutQuotes;
        return ChannelPostIncomingAdapter::contentFingerprint($observed) === ChannelPostIncomingAdapter::contentFingerprint($current);
    }

    private static function maxEditBody(array $message, string $url, string $label, string $botUrl): array
    {
        $out = [];
        foreach ((array)($message['body']['attachments'] ?? []) as $attachment) {
            self::check(is_array($attachment) && is_array($attachment['payload'] ?? null), 'invalid_attachment');
            $type = $attachment['type'] ?? ''; $payload = $attachment['payload'];
            if ($type === 'image') {
                self::check(is_string($payload['token'] ?? null) && $payload['token'] !== '', 'image_token_missing');
                // Preserve every original image and its order; never import a URL.
                $out[] = ['type'=>'image', 'payload'=>['token'=>$payload['token']]];
            } elseif ($type === 'video') {
                self::check(ChannelPostIncomingAdapter::maxVideoTokenValid($payload['token'] ?? null), 'video_token_invalid');
                // Reuse the exact clip from the fresh message, without upload or download.
                $out[] = ['type'=>'video', 'payload'=>['token'=>$payload['token']]];
            } elseif ($type !== 'inline_keyboard') {
                throw new ChannelPostTransportFailure('unsupported_media');
            }
            // The owner explicitly requests replacing all previous buttons,
            // including another bot's open_app/callback, with our one URL.
        }
        self::check(count($out) <= ChannelPostIncomingAdapter::maxPhotoLimit(), 'attachment_limit');
        $out[] = ['type'=>'inline_keyboard', 'payload'=>['buttons'=>[[['type'=>'link','text'=>$label,'url'=>$url]]]]];
        // Omit text/markup/format/link: an attachments-only edit preserves them.
        return ['attachments'=>$out];
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
        if ($platform === 'telegram' && ($this->receipt['publisher_username'] ?? '') === 'ANYTOURBOT_bot'
            && !$response['transport_error'] && ($response['data']['ok'] ?? null) === false) {
            $code = $response['data']['error_code'] ?? null;
            if (is_int($code) && $code >= 100 && $code <= 599) $this->receipt['telegram_error_code'] = $code;
            $description = strtolower(is_string($response['data']['description'] ?? null) ? $response['data']['description'] : '');
            $reason = 'provider_rejected';
            foreach ([
                'message_id_invalid'=>'message_id_invalid', 'message to edit not found'=>'message_not_found',
                "message can't be edited"=>'message_not_editable', 'message cannot be edited'=>'message_not_editable',
                'message is not modified'=>'message_not_modified', 'not enough rights'=>'insufficient_edit_rights',
                'bot is not a member'=>'bot_not_in_channel', 'bot was kicked'=>'bot_not_in_channel',
                'chat not found'=>'chat_not_found', 'unauthorized'=>'unauthorized', 'too many requests'=>'rate_limited',
            ] as $needle=>$normalized) {
                if (str_contains($description, $needle)) { $reason = $normalized; break; }
            }
            $this->receipt['telegram_error_reason'] = $reason;
        }
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
