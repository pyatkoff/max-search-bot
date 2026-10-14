<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/ChannelPostAutomationService.php';
require_once __DIR__ . '/../integrations/MaxIncomingAdapter.php';
$fixtures = require __DIR__ . '/fixtures/channel_post_automation.php';
$failed = 0; $passed = 0;
function channelPostCheck(string $name, $actual, $expected = true): void {
    global $failed, $passed;
    $ok = $actual === $expected;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . PHP_EOL;
    if ($ok) $passed++; else { $failed++; echo '      expected=' . json_encode($expected) . ' actual=' . json_encode($actual) . PHP_EOL; }
}

final class FixturePostRegistry
{
    public array $channels = [];
    public array $posts = [];
    public int $claimCount = 0;
    public int $writeCount = 0;
    private int $nextVersion = 1;

    public function __construct(string $platform = 'max') {
        $bot = $platform === 'max' ? 'id9704048781_1_bot' : 'Any_tour_bot';
        $channel = [
            'id' => 1, 'project_id' => 1, 'project_key' => 'anytour', 'platform' => $platform,
            'channel_id' => $platform === 'max' ? '-80095447388179' : '-1001234567890',
            'bot_key' => $bot, 'canonical_bot_username' => $bot,
            'bot_url' => ($platform === 'max' ? 'https://max.ru/' : 'https://t.me/') . $bot,
            'source_id' => 4, 'source_key' => $platform . ':anytour-msk1',
            'button_text' => 'Рассчитать на мои даты', 'is_enabled' => 1,
        ];
        $this->channels[$channel['channel_id']] = $channel;
    }
    public function channel(string $project, string $platform, string $bot, string $id): ?array {
        $c = $this->channels[$id] ?? null;
        return $c && $c['is_enabled'] && $c['project_key'] === $project && $c['platform'] === $platform && $c['bot_key'] === $bot ? $c : null;
    }
    public function registerPublication(string $project, string $platform, string $bot, string $id, string $mid, array $snapshot): ?array {
        $channel = $this->channel($project, $platform, $bot, $id);
        if (!$channel) return null;
        $key = $platform . ':' . $id . ':' . $mid;
        $post = $this->posts[$key] ?? null;
        if (!$post) $post = ['publication_id' => count($this->posts) + 1, 'start_payload' => 'p_' . str_pad((string)(count($this->posts) + 1), 24, 'A'), 'channel' => $channel, 'project_key' => $project, 'platform' => $platform, 'bot_key' => $bot, 'external_message_id' => $mid, 'source_key' => $channel['source_key'], 'source_id' => $channel['source_id']];
        if (!isset($post['snapshot']) || $post['snapshot']['content_hash'] !== $snapshot['content_hash']) {
            $post['snapshot'] = $snapshot; $post['version_id'] = $this->nextVersion++;
            $post['content_hash'] = $snapshot['content_hash'];
            $post['state'] = 'pending'; $post['write_attempted'] = false;
            $post['claim_token'] = ''; $post['receipt'] = [];
        }
        $this->posts[$key] = $post;
        return $this->publication($post['publication_id']);
    }
    public function publication(int $id): ?array {
        foreach ($this->posts as $post) if ($post['publication_id'] === $id) {
            $c = $this->channel($post['project_key'], $post['platform'], $post['bot_key'], $post['channel']['channel_id']);
            if (!$c || $c['source_id'] !== $post['source_id']) return null;
            $post['channel'] = $c; return $post;
        }
        return null;
    }
    public function pendingPublications(string $project, string $platform, string $bot, int $limit): array {
        $out = [];
        foreach ($this->posts as $post) {
            $fresh = $this->publication($post['publication_id']);
            if ($fresh && $post['project_key'] === $project && $post['platform'] === $platform && $post['bot_key'] === $bot && in_array($post['state'], ['pending', 'unknown'], true)) $out[] = $fresh;
        }
        usort($out, static fn($a, $b) => ($a['state'] === 'pending' ? 0 : 1) <=> ($b['state'] === 'pending' ? 0 : 1));
        return array_slice($out, 0, $limit);
    }
    public function claimPublication(int $id, int $version, bool $reconcile = false): ?array {
        foreach ($this->posts as &$post) if ($post['publication_id'] === $id && $post['version_id'] === $version && $post['state'] === ($reconcile ? 'unknown' : 'pending')) {
            $post['state'] = 'claimed'; $post['claim_token'] = 'claim-' . ++$this->claimCount;
            $post['reconcile_only'] = $reconcile;
            return $this->publication($id);
        }
        return null;
    }
    public function markExternalWriteStarted(int $id, int $version, string $token): bool {
        foreach ($this->posts as &$post) if ($post['publication_id'] === $id && $post['version_id'] === $version && $post['claim_token'] === $token && $post['state'] === 'claimed' && !$post['write_attempted']) {
            $post['state'] = 'write_started'; $post['write_attempted'] = true; $this->writeCount++; return true;
        }
        return false;
    }
    public function finishPublication(int $id, int $version, string $token, string $state, string $reason = '', array $receipt = []): bool {
        foreach ($this->posts as &$post) if ($post['publication_id'] === $id && $post['version_id'] === $version && $post['claim_token'] === $token && in_array($post['state'], ['claimed', 'write_started'], true)) {
            $post['state'] = $state; $post['claim_token'] = ''; $post['reason'] = $reason; $post['receipt'] = $receipt; return true;
        }
        return false;
    }
    public function confirmObservedButton(int $id, int $version, array $receipt): bool {
        foreach ($this->posts as &$post) if ($post['publication_id'] === $id && $post['version_id'] === $version && in_array($post['state'], ['pending', 'claimed', 'write_started', 'unknown'], true)) {
            $post['state'] = 'sent'; $post['claim_token'] = ''; $post['receipt'] = $receipt; return true;
        }
        return false;
    }
}

final class FixturePostProvider
{
    public array $message;
    public array $calls = [];
    public string $mode = 'success';
    public string $bot = '';
    public $onRead = null;
    public bool $failVerification = false;
    public function __construct(array $message) { $this->message = $message; }
    public function request(string $platform, string $method, string $path, array $query, ?array $body): array {
        $this->calls[] = compact('platform', 'method', 'path', 'query', 'body');
        $bot = $this->bot !== '' ? $this->bot : ($platform === 'max' ? 'id9704048781_1_bot' : 'Any_tour_bot');
        if ($path === '/me') return $this->ok(['user_id' => 42, 'is_bot' => true, 'username' => $bot]);
        if (strpos($path, '/members/me') !== false) return $this->ok(['user_id' => 42, 'is_bot' => true, 'username' => $bot, 'is_admin' => true, 'permissions' => $this->mode === 'no_rights' ? ['read_all_messages'] : ['read_all_messages', 'edit']]);
        if (preg_match('~^/chats/(-[0-9]+)$~', $path, $m)) return $this->ok(['chat_id' => $m[1], 'type' => $this->mode === 'wrong_channel_type' ? 'chat' : 'channel']);
        if ($path === 'getMe') return $this->ok(['ok' => true, 'result' => ['id' => 42, 'is_bot' => true, 'username' => $bot]]);
        if ($path === 'getChatMember') return $this->ok(['ok' => true, 'result' => ['user' => ['id' => 42, 'is_bot' => true, 'username' => $bot], 'status' => 'administrator', 'can_edit_messages' => $this->mode !== 'no_rights']]);
        if ($path === 'getChat') return $this->ok(['ok' => true, 'result' => ['id' => $body['chat_id'], 'type' => $this->mode === 'wrong_channel_type' ? 'supergroup' : 'channel']]);
        if ($method === 'GET' && strpos($path, '/messages/') === 0) {
            if ($this->onRead) ($this->onRead)();
            if ($this->failVerification && $this->writes() > 0) return ['http' => 0, 'transport_error' => true, 'data' => null];
            return $this->ok($this->message);
        }
        if ($method === 'PUT' || $path === 'editMessageReplyMarkup') {
            if ($this->mode === 'rejected') return ['http' => 403, 'data' => ['success' => false], 'transport_error' => false];
            if ($this->mode !== 'timeout_unapplied') {
                if ($platform === 'max') {
                    $original = $this->message['body']['attachments']; $mapped = [];
                    foreach ($body['attachments'] as $attachment) {
                        if ($attachment['type'] === 'image') foreach ($original as $old) if (($old['type'] ?? '') === 'image' && $old['payload']['token'] === $attachment['payload']['token']) $attachment = $old;
                        $mapped[] = $attachment;
                    }
                    $this->message['body']['attachments'] = $mapped;
                } else $this->message['reply_markup'] = $body['reply_markup'];
            }
            if (strpos($this->mode, 'timeout') === 0) throw new RuntimeException('synthetic timeout after body may be accepted');
            return $this->ok($platform === 'max' ? ['success' => true] : ['ok' => true, 'result' => $this->message]);
        }
        throw new RuntimeException('unexpected fixture request');
    }
    private function ok(array $data): array { return ['http' => 200, 'data' => $data, 'transport_error' => false]; }
    public function writes(): int { return count(array_filter($this->calls, static fn($c) => $c['method'] === 'PUT' || $c['path'] === 'editMessageReplyMarkup')); }
}

function channelPostScenario(array $message, string $platform = 'max'): array {
    $registry = new FixturePostRegistry($platform); $provider = new FixturePostProvider($message);
    $transport = new ChannelPostButtonTransport([$provider, 'request']);
    return [$registry, $provider, $transport, new ChannelPostAutomationService($registry, $transport)];
}
function channelPostUpdate(array $message, string $platform = 'max', bool $edited = false): array {
    return $platform === 'max' ? ['update_type' => $edited ? 'message_edited' : 'message_created', 'message' => $message] : ['update_id' => $edited ? 901 : 900, $edited ? 'edited_channel_post' : 'channel_post' => $message];
}
function channelPostReceive(ChannelPostAutomationService $service, array $message, string $platform = 'max', bool $edited = false): array {
    return $service->receive($platform, channelPostUpdate($message, $platform, $edited), 'anytour', $platform === 'max' ? 'id9704048781_1_bot' : 'Any_tour_bot');
}
function channelPostRun(ChannelPostAutomationService $service, string $platform = 'max'): array {
    return $service->processPending('anytour', $platform, $platform === 'max' ? 'id9704048781_1_bot' : 'Any_tour_bot');
}

$event = ChannelPostIncomingAdapter::fromUpdate('max', channelPostUpdate($fixtures['max']));
channelPostCheck('actual external MAX mid retained, no URL decoding', $event['external_message_id'], $fixtures['max']['body']['mid']);
channelPostCheck('caption whitespace kept exactly', $event['snapshot']['text'], $fixtures['max']['body']['text']);
channelPostCheck('provider-native markup retained', $event['snapshot']['markup'], $fixtures['max']['body']['markup']);
channelPostCheck('channel envelope recognized without customer sender', ChannelPostIncomingAdapter::isChannelUpdate('max', ['message' => ['recipient' => ['chat_type' => 'channel']]]));
channelPostCheck('ordinary MAX conversation excluded', ChannelPostIncomingAdapter::fromUpdate('max', ['update_type' => 'message_created', 'message' => ['recipient' => ['chat_type' => 'dialog']]]), null);
channelPostCheck('ordinary Telegram conversation excluded', ChannelPostIncomingAdapter::isChannelUpdate('telegram', ['message' => $fixtures['telegram']]), false);
$channelCallback = [
    'update_type' => 'message_callback', 'message' => $fixtures['max'],
    'callback' => ['callback_id' => 'fixture-channel-callback', 'payload' => 'legacy_channel_action', 'user' => ['user_id' => 777, 'first_name' => 'Fixture']],
];
$personalCallback = MaxIncomingAdapter::fromUpdate($channelCallback);
channelPostCheck('MAX channel callback remains a canonical personal interaction', [$personalCallback['type'], $personalCallback['platform'], $personalCallback['user']['chat_id'], $personalCallback['user']['external_user_id']], ['callback', 'max', -777, '777']);
channelPostCheck('MAX channel callback keeps legacy callback ID and payload', [$personalCallback['callback_id'], $personalCallback['callback_data']], ['fixture-channel-callback', 'legacy_channel_action']);
[$callbackStore, $callbackProvider, $callbackTransport, $callbackService] = channelPostScenario($fixtures['max']);
$callbackReceipt = $callbackService->receive('max', $channelCallback, 'anytour', 'id9704048781_1_bot');
channelPostCheck('publication automation does not consume MAX channel button callback', [$callbackReceipt['recognized'], $callbackReceipt['queued']], [false, false]);
channelPostCheck('MAX channel callback creates no publication job or provider call', count($callbackStore->posts) === 0 && count($callbackProvider->calls) === 0);

[$store, $provider, $transport, $service] = channelPostScenario($fixtures['max']);
$first = channelPostReceive($service, $fixtures['max']); $duplicate = channelPostReceive($service, $fixtures['max']);
channelPostCheck('receive durably queues with no network calls', $first['queued'] && count($provider->calls) === 0);
channelPostCheck('duplicate update retains one publication/version', count($store->posts) === 1 && $first['version_id'] === $duplicate['version_id']);
$result = channelPostRun($service);
channelPostCheck('human MAX image post verified', $result[0]['state'], 'sent');
channelPostCheck('one external write only', $provider->writes(), 1);
channelPostCheck('MAX worker bounded to five calls', count($provider->calls), 5);
$write = array_values(array_filter($provider->calls, static fn($c) => $c['method'] === 'PUT'))[0];
channelPostCheck('MAX edit omits text, markup, format and link', array_keys($write['body']), ['attachments']);
channelPostCheck('MAX image uses existing token without import', $write['body']['attachments'][0], ['type' => 'image', 'payload' => ['token' => 'fixture-private-image-token']]);
channelPostCheck('MAX text/markup/photo IDs/link preserved', $provider->message['body']['text'] === $fixtures['max']['body']['text'] && $provider->message['body']['markup'] === $fixtures['max']['body']['markup'] && $provider->message['body']['attachments'][0]['payload']['photo_id'] === 901 && $provider->message['link'] === $fixtures['max']['link']);
channelPostCheck('original URL row remains before new row', $provider->message['body']['attachments'][1]['payload']['buttons'][0], $fixtures['max']['body']['attachments'][1]['payload']['buttons'][0]);
channelPostCheck('safe result never includes original text/media tokens', strpos(json_encode($result), 'fixture-private') === false && strpos(json_encode($result), 'Турция') === false);
channelPostCheck('completed worker replay performs no external operation', channelPostRun($service), []);
$selfEdit = channelPostReceive($service, $provider->message, 'max', true);
channelPostCheck('own edit does not create new version', $selfEdit['version_id'], $first['version_id']);
channelPostCheck('own edit does not duplicate button', $provider->writes(), 1);
$signed = $provider->message; $signed['body']['attachments'][0]['payload']['token'] = 'new-signed-token'; $signed['body']['attachments'][0]['payload']['url'] = 'https://cdn.example.test/photo?renewed';
channelPostCheck('signed image token changes do not create new version', channelPostReceive($service, $signed, 'max', true)['version_id'], $first['version_id']);
$edited = $provider->message; $edited['body']['text'] = 'Другие даты, новая цена'; $provider->message = $edited;
$newVersion = channelPostReceive($service, $edited, 'max', true);
channelPostCheck('SMM content edit retains token and appends version', $newVersion['version_id'] > $first['version_id'] && count($store->posts) === 1);
channelPostCheck('already present new-version button does not write again', $newVersion['state'], 'sent');

foreach (['max', 'telegram'] as $platform) {
    [$store, $provider, $transport, $service] = channelPostScenario($fixtures[$platform], $platform);
    $unmanaged = $fixtures[$platform];
    if ($platform === 'max') $unmanaged['recipient']['chat_id'] = -222;
    else $unmanaged['chat']['id'] = -100222;
    channelPostCheck($platform . ' unregistered channel consumed without queue', channelPostReceive($service, $unmanaged, $platform)['queued'], false);
    channelPostCheck($platform . ' unregistered channel performs no API access', count($provider->calls), 0);
    channelPostReceive($service, $fixtures[$platform], $platform); $provider->bot = 'wrong_bot';
    $blocked = channelPostRun($service, $platform);
    channelPostCheck($platform . ' exact canonical bot mismatch blocked', $blocked[0]['reason'], 'canonical_bot_identity_mismatch');
    channelPostCheck($platform . ' identity mismatch no write', $provider->writes(), 0);

    [$store, $provider, $transport, $service] = channelPostScenario($fixtures[$platform], $platform);
    channelPostReceive($service, $fixtures[$platform], $platform); $provider->mode = 'no_rights';
    channelPostCheck($platform . ' missing edit rights require attention', channelPostRun($service, $platform)[0]['state'], 'requires_attention');
    channelPostCheck($platform . ' missing edit rights no write', $provider->writes(), 0);

    [$store, $provider, $transport, $service] = channelPostScenario($fixtures[$platform], $platform);
    $channel = array_values($store->channels)[0]; $channel['is_enabled'] = 0;
    channelPostCheck($platform . ' admin verification read-only for disabled channel', $transport->verifyChannel($channel)['state'], 'ready');
    channelPostCheck($platform . ' capability verification performs no write', $provider->writes(), 0);
    $provider->mode = 'wrong_channel_type';
    channelPostCheck($platform . ' admin rejects non-channel chat', $transport->verifyChannel($channel)['reason'], 'channel_type_mismatch');

    [$store, $provider, $transport, $service] = channelPostScenario($fixtures[$platform], $platform);
    $callback = $fixtures[$platform];
    if ($platform === 'max') $callback['body']['attachments'][1]['payload']['buttons'][0][] = ['type' => 'callback', 'text' => 'Чужая кнопка', 'payload' => 'foreign'];
    else $callback['reply_markup']['inline_keyboard'][0][] = ['text' => 'Чужая кнопка', 'callback_data' => 'foreign'];
    $provider->message = $callback; channelPostReceive($service, $callback, $platform);
    channelPostCheck($platform . ' foreign callback ownership fail closed', channelPostRun($service, $platform)[0]['reason'], 'foreign_keyboard_owner');
    channelPostCheck($platform . ' existing foreign callback not rewritten', $provider->writes(), 0);
}

[$store, $provider, $transport, $service] = channelPostScenario($fixtures['max']);
channelPostReceive($service, $fixtures['max']); $provider->mode = 'timeout_applied';
channelPostCheck('lost MAX response reconciled by one GET', channelPostRun($service)[0]['state'], 'sent');
channelPostCheck('lost response performs at most one PUT', $provider->writes(), 1);

[$store, $provider, $transport, $service] = channelPostScenario($fixtures['max']);
channelPostReceive($service, $fixtures['max']); $provider->mode = 'timeout_unapplied';
channelPostCheck('ambiguous unapplied MAX edit is UNKNOWN', channelPostRun($service)[0]['state'], 'unknown');
$reconciled = channelPostRun($service);
channelPostCheck('UNKNOWN MAX worker reconciles with GET only', $reconciled[0]['reconcile_only'] && $reconciled[0]['state'] === 'unknown' && $provider->writes() === 1);
channelPostCheck('UNKNOWN claim cannot issue second external write', $store->writeCount, 1);

[$store, $provider, $transport, $service] = channelPostScenario($fixtures['max']);
channelPostReceive($service, $fixtures['max']); $provider->mode = 'timeout_applied'; $provider->failVerification = true;
channelPostCheck('write accepted but verification unavailable stays UNKNOWN', channelPostRun($service)[0]['state'], 'unknown');
$provider->failVerification = false;
channelPostCheck('later exact GET resolves UNKNOWN without another PUT', channelPostRun($service)[0]['state'] === 'sent' && $provider->writes() === 1);

[$store, $provider, $transport, $service] = channelPostScenario($fixtures['max']);
channelPostReceive($service, $fixtures['max']); $provider->message['body']['text'] = 'SMM changed before worker';
channelPostCheck('fresh MAX post changed since webhook blocked', channelPostRun($service)[0]['reason'], 'post_changed_since_event');
channelPostCheck('stale post causes no external write', $provider->writes(), 0);

[$store, $provider, $transport, $service] = channelPostScenario($fixtures['max']);
channelPostReceive($service, $fixtures['max']);
$provider->onRead = static function () use ($store): void { $store->channels['-80095447388179']['button_text'] = 'Changed config'; };
channelPostCheck('configuration race detected before write reservation', channelPostRun($service)[0]['reason'], 'write_claim_or_config_changed');
channelPostCheck('configuration race does not modify original post', $provider->writes(), 0);

[$store, $provider, $transport, $service] = channelPostScenario($fixtures['max']);
channelPostReceive($service, $fixtures['max']); $store->channels['-80095447388179']['source_id'] = 9;
channelPostCheck('retired or changed source cannot process pending operation', channelPostRun($service), []);

[$store, $provider, $transport, $service] = channelPostScenario($fixtures['max']);
channelPostReceive($service, $fixtures['max']); $post = array_values($store->posts)[0];
$claim = $store->claimPublication($post['publication_id'], $post['version_id']);
channelPostCheck('second concurrent worker cannot claim same version', $store->claimPublication($post['publication_id'], $post['version_id']), null);
channelPostCheck('reserved version is excluded from other worker queue', channelPostRun($service), []);
channelPostCheck('single durable write reservation succeeds', $store->markExternalWriteStarted($post['publication_id'], $post['version_id'], $claim['claim_token']));
channelPostCheck('repeated durable write reservation rejected', $store->markExternalWriteStarted($post['publication_id'], $post['version_id'], $claim['claim_token']), false);

[$store, $provider, $transport, $service] = channelPostScenario($fixtures['telegram'], 'telegram');
$first = channelPostReceive($service, $fixtures['telegram'], 'telegram'); $tgResult = channelPostRun($service, 'telegram');
channelPostCheck('Telegram human photo post reply markup verified', $tgResult[0]['state'], 'sent');
$edit = array_values(array_filter($provider->calls, static fn($c) => $c['path'] === 'editMessageReplyMarkup'))[0];
channelPostCheck('Telegram edit changes reply markup only', array_keys($edit['body']), ['chat_id', 'message_id', 'reply_markup']);
channelPostCheck('Telegram caption/photo/entities preserved', $provider->message['caption'] === $fixtures['telegram']['caption'] && $provider->message['caption_entities'] === $fixtures['telegram']['caption_entities'] && $provider->message['photo'] === $fixtures['telegram']['photo']);
channelPostCheck('Telegram original URL keyboard row retained', $provider->message['reply_markup']['inline_keyboard'][0], $fixtures['telegram']['reply_markup']['inline_keyboard'][0]);
channelPostCheck('Telegram own edit retains same immutable version', channelPostReceive($service, $provider->message, 'telegram', true)['version_id'], $first['version_id']);

[$store, $provider, $transport, $service] = channelPostScenario($fixtures['telegram'], 'telegram');
channelPostReceive($service, $fixtures['telegram'], 'telegram'); $provider->mode = 'timeout_applied';
channelPostCheck('Telegram lost response stays UNKNOWN awaiting channel event', channelPostRun($service, 'telegram')[0]['state'], 'unknown');
channelPostCheck('Telegram UNKNOWN never retries edit', channelPostRun($service, 'telegram')[0]['state'] === 'unknown' && $provider->writes() === 1);
channelPostCheck('Telegram edited_channel_post confirms ambiguous edit', channelPostReceive($service, $provider->message, 'telegram', true)['state'], 'sent');
channelPostCheck('Telegram resolved operation drops from worker queue', channelPostRun($service, 'telegram'), []);

foreach (['max', 'telegram'] as $platform) {
    $plain = $fixtures[$platform];
    if ($platform === 'max') { $plain['body']['attachments'] = []; $plain['body']['markup'] = []; }
    else { unset($plain['photo'], $plain['caption'], $plain['caption_entities'], $plain['reply_markup']); $plain['text'] = 'Обычный пост о туре'; }
    [$store, $provider, $transport, $service] = channelPostScenario($plain, $platform);
    channelPostReceive($service, $plain, $platform);
    channelPostCheck($platform . ' standard text-only post receives button', channelPostRun($service, $platform)[0]['state'], 'sent');
    channelPostCheck($platform . ' standard text-only post edited once', $provider->writes(), 1);
}

[$store, $provider, $transport, $service] = channelPostScenario($fixtures['max']);
$missingToken = $fixtures['max']; unset($missingToken['body']['attachments'][0]['payload']['token']);
channelPostReceive($service, $missingToken);
channelPostCheck('MAX incoming missing signed token uses fresh GET mapping', channelPostRun($service)[0]['state'], 'sent');

[$store, $provider, $transport, $service] = channelPostScenario($fixtures['max']);
channelPostReceive($service, $fixtures['max']); $provider->message['recipient']['chat_id'] = -999;
channelPostCheck('fresh exact MAX mid in wrong channel rejected', channelPostRun($service)[0]['reason'], 'post_identity_mismatch');
channelPostCheck('wrong channel identity cannot write post', $provider->writes(), 0);

[$store, $provider, $transport, $service] = channelPostScenario($fixtures['max']);
channelPostReceive($service, $fixtures['max']); $provider->mode = 'rejected';
channelPostCheck('explicit API edit rejection is attention, not fake success', channelPostRun($service)[0]['state'], 'requires_attention');
channelPostCheck('explicit rejected version does not repeat write', channelPostRun($service) === [] && $provider->writes() === 1);

foreach (['max', 'telegram'] as $platform) {
    [$store, $provider, $transport, $service] = channelPostScenario($fixtures[$platform], $platform);
    $unsupported = $fixtures[$platform];
    if ($platform === 'max') $unsupported['body']['attachments'][0] = ['type' => 'video', 'payload' => ['token' => 'fixture-video']];
    else { unset($unsupported['photo']); $unsupported['video'] = ['file_id' => 'fixture-video']; }
    $r = channelPostReceive($service, $unsupported, $platform);
    channelPostCheck($platform . ' unsupported video stored as attention', $r['recognized'] && $r['state'] === 'requires_attention');
    channelPostCheck($platform . ' unsupported video does not call provider', count($provider->calls), 0);
    channelPostCheck($platform . ' unsupported video not fake queued success', $r['queued'], false);
}
[$store, $provider, $transport, $service] = channelPostScenario($fixtures['telegram'], 'telegram');
$album = $fixtures['telegram']; $album['media_group_id'] = 'album-91';
$one = channelPostReceive($service, $album, 'telegram'); $album['message_id'] = 62; $album['photo'][1]['file_unique_id'] = 'next-photo';
$two = channelPostReceive($service, $album, 'telegram');
channelPostCheck('Telegram album items share one post token', count($store->posts) === 1 && $one['publication_id'] === $two['publication_id']);
channelPostCheck('unsupported album honestly requires attention', $two['state'], 'requires_attention');
channelPostCheck('unsupported album creates no API write', $provider->writes(), 0);

$dbAbsent = new ChannelPostAutomationService(new class { public function channel(...$args) { throw new RuntimeException('DB unavailable'); } });
channelPostCheck('channel DB outage remains consumed before customer dispatcher', channelPostReceive($dbAbsent, $fixtures['max'])['recognized']);
channelPostCheck('malformed channel remains consumed before customer dispatcher', $dbAbsent->receive('max', ['update_type' => 'message_created', 'message' => ['recipient' => ['chat_type' => 'channel']]], 'anytour', 'id9704048781_1_bot')['recognized']);

// Exercise receive -> actual migration/registry CAS -> transport -> durable
// receipt, rather than relying only on a fake registry's interpretation.
require_once __DIR__ . '/fixtures/channel_post_registry_fixture.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
createChannelPostRegistryFixture($pdo);
$pdo->exec("INSERT INTO projects(id,project_key,display_name,is_active) VALUES (1,'anytour','AnyTour',1)");
$pdo->exec("INSERT INTO conversation_sources(id,project_id,source_key,display_name,channel,is_active) VALUES (4,1,'max:anytour-msk1','MAX test','max',1),(5,1,'telegram:anytour-msk1','TG test','telegram',1)");
$actualRegistry = new ChannelPostRegistryService($pdo);
$actualRegistry->configureChannel([
    'project_key' => 'anytour', 'platform' => 'max', 'bot_key' => 'id9704048781_1_bot',
    'channel_id' => '80095447388179', 'bot_url' => 'https://max.ru/id9704048781_1_bot',
    'source_key' => 'max:anytour-msk1', 'is_enabled' => true,
]);
$actualProvider = new FixturePostProvider($fixtures['max']);
$actualService = new ChannelPostAutomationService($actualRegistry, new ChannelPostButtonTransport([$actualProvider, 'request']));
$original = channelPostReceive($actualService, $fixtures['max']);
channelPostCheck('actual migrated registry queues channel publication', $original['state'], 'pending');
$actualPost = $actualRegistry->publication($original['publication_id']);
channelPostCheck('actual immutable snapshot retains exact caption whitespace', $actualPost['snapshot']['text'], $fixtures['max']['body']['text']);
channelPostCheck('actual stored token is stable and URL-safe', (bool)preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/', $actualPost['start_payload']));
channelPostCheck('actual duplicate envelope consumed without new queue event', channelPostReceive($actualService, $fixtures['max'])['reason'], 'duplicate_channel_observation');
channelPostCheck('actual queue does not lose duplicate pending item', count($actualRegistry->pendingPublications('anytour', 'max', 'id9704048781_1_bot')), 1);
$actualResult = channelPostRun($actualService);
channelPostCheck('actual registry write lease and completion persist sent', $actualResult[0]['state'], 'sent');
$completed = $actualRegistry->publication($original['publication_id']);
channelPostCheck('actual durable write counter never exceeds one', $completed['write_attempts'], 1);
channelPostCheck('actual safe receipt preserves evidence counts', $completed['receipt']['request_count'] === 5 && $completed['receipt']['write_attempts'] === 1 && $completed['receipt']['button_verified']);
channelPostCheck('actual self-edit keeps version and token', channelPostReceive($actualService, $actualProvider->message, 'max', true)['version_id'], $original['version_id']);
$oldOwnEdit = $actualProvider->message;
channelPostCheck('actual snapshot did not overwrite original with self-edited body', $actualRegistry->publication($original['publication_id'])['snapshot']['raw_body'], $fixtures['max']);
$changed = $actualProvider->message; $changed['body']['text'] = 'Новая цена: 160 000 ₽'; $changed['timestamp'] += 10000; $actualProvider->message = $changed;
$revision = channelPostReceive($actualService, $changed, 'max', true);
channelPostCheck('actual content revision uses same publication/token', $revision['version_id'] > $original['version_id'] && $actualRegistry->publication($original['publication_id'])['start_payload'] === $actualPost['start_payload']);
channelPostCheck('actual old envelope replay cannot confirm or reset new version', channelPostReceive($actualService, $fixtures['max'])['state'], 'ignored');
channelPostCheck('actual old replay leaves newest content intact', $actualRegistry->publication($original['publication_id'])['snapshot']['text'], 'Новая цена: 160 000 ₽');
channelPostCheck('actual older equivalent-body self-edit replay ignored', channelPostReceive($actualService, $oldOwnEdit, 'max', true)['reason'], 'duplicate_channel_observation');

$orderMessage = $fixtures['max']; $orderMessage['body']['mid'] = 'mid.revision-order';
$orderOne = channelPostUpdate($orderMessage); $orderOne['timestamp'] = $orderMessage['timestamp'] + 1;
$orderInitial = $actualService->receive('max', $orderOne, 'anytour', 'id9704048781_1_bot');
$orderMessage['body']['text'] = 'Latest within same second';
$orderLatest = channelPostUpdate($orderMessage, 'max', true); $orderLatest['timestamp'] = $orderMessage['timestamp'] + 100;
$orderNext = $actualService->receive('max', $orderLatest, 'anytour', 'id9704048781_1_bot');
$orderMessage['body']['text'] = 'Older within same second';
$orderOld = channelPostUpdate($orderMessage, 'max', true); $orderOld['timestamp'] = $orderMessage['timestamp'] + 2;
channelPostCheck('actual MAX event revision comparison retains millisecond precision', $actualService->receive('max', $orderOld, 'anytour', 'id9704048781_1_bot')['reason'], 'stale_channel_observation');
channelPostCheck('actual out-of-order event keeps latest version content', $actualRegistry->publication($orderInitial['publication_id'])['snapshot']['text'], 'Latest within same second');
$orderMessage['body']['text'] = 'Latest within same second';
$maxSameContent = channelPostUpdate($orderMessage, 'max', true); $maxSameContent['timestamp'] = $orderMessage['timestamp'] + 110;
$maxSameReceipt = $actualService->receive('max', $maxSameContent, 'anytour', 'id9704048781_1_bot');
channelPostCheck('MAX identical-content observation keeps immutable content version', $maxSameReceipt['version_id'], $orderNext['version_id']);
$orderMessage['body']['text'] = 'Delayed MAX content older than latest same-content observation';
$maxMiddle = channelPostUpdate($orderMessage, 'max', true); $maxMiddle['timestamp'] = $orderMessage['timestamp'] + 105;
$maxMiddleReceipt = $actualService->receive('max', $maxMiddle, 'anytour', 'id9704048781_1_bot');
channelPostCheck('MAX same-content observation advances accepted event clock', $maxMiddleReceipt['reason'] ?? '', 'stale_channel_observation');
channelPostCheck('MAX accepted event clock never restores unseen older content', $actualRegistry->publication($orderInitial['publication_id'])['snapshot']['text'], 'Latest within same second');
$orderClaim = $actualRegistry->claimPublication($orderNext['publication_id'], $orderNext['version_id']);
$actualRegistry->finishPublication($orderNext['publication_id'], $orderNext['version_id'], $orderClaim['claim_token'], 'requires_attention', 'test_finished');

$pendingMessage = $fixtures['max']; $pendingMessage['body']['mid'] = 'mid.pending-unknown';
$actualProvider->message = $pendingMessage; $actualProvider->mode = 'timeout_unapplied';
$unknownPost = channelPostReceive($actualService, $pendingMessage);
channelPostCheck('actual ambiguous channel write persisted UNKNOWN', channelPostRun($actualService)[0]['state'], 'unknown');
$priorWrites = $actualProvider->writes();
channelPostCheck('actual UNKNOWN claim reconciles only', channelPostRun($actualService)[0]['reconcile_only']);
channelPostCheck('actual UNKNOWN retry does not issue second PUT', $actualProvider->writes(), $priorWrites);
channelPostCheck('actual UNKNOWN durable write counter remains one', $actualRegistry->publication($unknownPost['publication_id'])['write_attempts'], 1);

$pendingMessage['body']['mid'] = 'mid.next-pending';
$next = channelPostReceive($actualService, $pendingMessage);
$queue = $actualRegistry->pendingPublications('anytour', 'max', 'id9704048781_1_bot', 1);
channelPostCheck('actual pending posts precede unresolved UNKNOWN to avoid starvation', $queue[0]['publication_id'], $next['publication_id']);
$claim = $actualRegistry->claimPublication($next['publication_id'], $next['version_id']);
channelPostCheck('actual second worker CAS claim rejected', $actualRegistry->claimPublication($next['publication_id'], $next['version_id']), null);
channelPostCheck('actual external write CAS succeeds once', $actualRegistry->markExternalWriteStarted($next['publication_id'], $next['version_id'], $claim['claim_token']));
channelPostCheck('actual repeated external write CAS rejected', $actualRegistry->markExternalWriteStarted($next['publication_id'], $next['version_id'], $claim['claim_token']), false);

$actualRegistry->configureChannel([
    'project_key' => 'anytour', 'platform' => 'telegram', 'bot_key' => 'Any_tour_bot',
    'channel_id' => '-1001234567890', 'bot_url' => 'https://t.me/Any_tour_bot',
    'source_key' => 'telegram:anytour-msk1', 'is_enabled' => true,
]);
$actualTgProvider = new FixturePostProvider($fixtures['telegram']);
$actualTgService = new ChannelPostAutomationService($actualRegistry, new ChannelPostButtonTransport([$actualTgProvider, 'request']));
$sameSecond = $fixtures['telegram']; $sameSecond['message_id'] = 65; $sameSecond['edit_date'] = 1791972394;
$sameSecond['caption'] = 'Newest Telegram edit in this second';
$newerEnvelope = ['update_id' => 1004, 'edited_channel_post' => $sameSecond];
$newestReceipt = $actualTgService->receive('telegram', $newerEnvelope, 'anytour', 'Any_tour_bot');
channelPostCheck('Telegram adapter retains authenticated event sequence', $actualRegistry->publication($newestReceipt['publication_id'])['snapshot']['metadata']['source_sequence'], '1004');
channelPostCheck('Telegram adapter sequence belongs only to update_id scope', $actualRegistry->publication($newestReceipt['publication_id'])['snapshot']['metadata']['source_sequence_scope'], 'telegram_update_id');
$newestToken = $actualRegistry->publication($newestReceipt['publication_id'])['start_payload'];
$sameSecond['caption'] = 'Older Telegram edit from the same second';
$olderEnvelope = ['update_id' => 1003, 'edited_channel_post' => $sameSecond];
$olderReceipt = $actualTgService->receive('telegram', $olderEnvelope, 'anytour', 'Any_tour_bot');
channelPostCheck('unseen earlier TG update in same edit second is stale', $olderReceipt['reason'] ?? '', 'stale_channel_observation');
channelPostCheck('TG same-second out-of-order event never restores older content', $actualRegistry->publication($newestReceipt['publication_id'])['snapshot']['text'], 'Newest Telegram edit in this second');
channelPostCheck('TG stale same-second event preserves token and version', [$actualRegistry->publication($newestReceipt['publication_id'])['start_payload'], $olderReceipt['version_id']], [$newestToken, $newestReceipt['version_id']]);
$sameSecond['caption'] = 'Newest Telegram edit in this second';
$sameContentEnvelope = ['update_id' => 1006, 'edited_channel_post' => $sameSecond];
$sameContentReceipt = $actualTgService->receive('telegram', $sameContentEnvelope, 'anytour', 'Any_tour_bot');
channelPostCheck('newer identical-content TG edit preserves immutable content version', $sameContentReceipt['version_id'], $newestReceipt['version_id']);
$sameSecond['caption'] = 'Delayed edit before the latest identical-content observation';
$middleEnvelope = ['update_id' => 1005, 'edited_channel_post' => $sameSecond];
$middleReceipt = $actualTgService->receive('telegram', $middleEnvelope, 'anytour', 'Any_tour_bot');
channelPostCheck('same-content TG observation advances event ordering watermark', $middleReceipt['reason'] ?? '', 'stale_channel_observation');
channelPostCheck('TG ordering watermark prevents restoring unseen older content', $actualRegistry->publication($newestReceipt['publication_id'])['snapshot']['text'], 'Newest Telegram edit in this second');
$sameSecond['caption'] = 'Newer second may restart the Telegram sequence'; $sameSecond['edit_date']++;
$laterTimeEnvelope = ['update_id' => 1002, 'edited_channel_post' => $sameSecond];
$laterReceipt = $actualTgService->receive('telegram', $laterTimeEnvelope, 'anytour', 'Any_tour_bot');
channelPostCheck('Telegram edit timestamp remains primary over sequence', $laterReceipt['version_id'] > $newestReceipt['version_id'] && $actualRegistry->publication($laterReceipt['publication_id'])['snapshot']['text'] === 'Newer second may restart the Telegram sequence');
$laterSameContent = $sameSecond; $laterSameContent['edit_date']++;
$laterSameReceipt = $actualTgService->receive('telegram', ['update_id' => 1001, 'edited_channel_post' => $laterSameContent], 'anytour', 'Any_tour_bot');
channelPostCheck('TG newer date with identical content preserves version snapshot', $laterSameReceipt['version_id'], $laterReceipt['version_id']);
$sameSecond['caption'] = 'Stale prior-second edit with a higher sequence';
$priorSecondReceipt = $actualTgService->receive('telegram', ['update_id' => 2000, 'edited_channel_post' => $sameSecond], 'anytour', 'Any_tour_bot');
channelPostCheck('TG same-content observation advances primary date watermark', $priorSecondReceipt['reason'] ?? '', 'stale_channel_observation');
channelPostCheck('TG date watermark dominates higher stale sequence', $actualRegistry->publication($laterReceipt['publication_id'])['snapshot']['text'], 'Newer second may restart the Telegram sequence');
$orderClaim = $actualRegistry->claimPublication($laterReceipt['publication_id'], $laterReceipt['version_id']);
$actualRegistry->finishPublication($laterReceipt['publication_id'], $laterReceipt['version_id'], $orderClaim['claim_token'], 'requires_attention', 'test_finished');
$tgOriginal = channelPostReceive($actualTgService, $fixtures['telegram'], 'telegram');
$actualTgProvider->mode = 'timeout_applied';
channelPostCheck('actual Telegram ambiguous edit stored UNKNOWN', channelPostRun($actualTgService, 'telegram')[0]['state'], 'unknown');
channelPostCheck('actual Telegram self-edit resolves UNKNOWN without another write', channelPostReceive($actualTgService, $actualTgProvider->message, 'telegram', true)['state'], 'sent');
channelPostCheck('actual Telegram token/version remain stable after reconciliation', $actualRegistry->publication($tgOriginal['publication_id'])['version_id'], $tgOriginal['version_id']);
channelPostCheck('actual Telegram durable write count remains one', $actualRegistry->publication($tgOriginal['publication_id'])['write_attempts'], 1);
echo 'Channel post automation: ' . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
