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
    public array $chatFields = [];
    public $onRead = null;
    public bool $failVerification = false;
    public function __construct(array $message) { $this->message = $message; }
    public function request(string $platform, string $method, string $path, array $query, ?array $body): array {
        $this->calls[] = compact('platform', 'method', 'path', 'query', 'body');
        $bot = $this->bot !== '' ? $this->bot : ($platform === 'max' ? 'id9704048781_1_bot' : 'Any_tour_bot');
        if ($path === '/me') return $this->ok(['user_id' => 42, 'is_bot' => true, 'username' => $bot]);
        if (strpos($path, '/members/me') !== false) return $this->ok(['user_id' => 42, 'is_bot' => true, 'username' => $bot, 'is_admin' => true, 'permissions' => $this->mode === 'no_rights' ? ['read_all_messages'] : ['read_all_messages', 'edit']]);
        if (preg_match('~^/chats/(-[0-9]+)$~', $path, $m)) return $this->ok(['chat_id' => $m[1], 'type' => $this->mode === 'wrong_channel_type' ? 'chat' : 'channel'] + $this->chatFields);
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
                        if (in_array($attachment['type'], ['image','video'], true)) foreach ($original as $old) if (($old['type'] ?? '') === $attachment['type'] && $old['payload']['token'] === $attachment['payload']['token']) $attachment = $old;
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
$publicPost = $fixtures['max'];
$publicPost['url'] = 'https://max.ru/channel_anytour/AaFyQVMSUW4';
$publicEvent = ChannelPostIncomingAdapter::fromUpdate('max', channelPostUpdate($publicPost));
channelPostCheck('MAX provider public-slug permalink retained', $publicEvent['snapshot']['post_url'], $publicPost['url']);
channelPostCheck('MAX public slug never selects publication identity', [$publicEvent['channel_id'], $publicEvent['external_message_id']], [$event['channel_id'], $event['external_message_id']]);
foreach (['http://max.ru/channel_anytour/Post', 'https://max.ru.evil.test/channel_anytour/Post', 'https://user@max.ru/channel_anytour/Post', 'https://max.ru:443/channel_anytour/Post', 'https://max.ru/channel_anytour/Post?start=other', 'https://max.ru/channel_anytour/Post#other', 'https://max.ru/channel_anytour/../Post', 'https://max.ru/channel%5fanytour/Post', "https://max.ru/channel_anytour/Post\n", 'https://max.ru/c/-222/Other'] as $badPublicUrl) {
    $invalidPublic = $publicPost; $invalidPublic['url'] = $badPublicUrl;
    channelPostCheck('MAX unsafe or other numeric channel permalink rejected ' . $badPublicUrl, ChannelPostIncomingAdapter::maxSnapshot($invalidPublic)['post_url'], '');
}
$invalidPublic = $publicPost; $invalidPublic['recipient']['chat_type'] = 'dialog';
channelPostCheck('MAX public permalink requires channel envelope', ChannelPostIncomingAdapter::maxSnapshot($invalidPublic)['post_url'], '');
$invalidPublic = $publicPost; $invalidPublic['recipient']['chat_id'] = 'invalid';
channelPostCheck('MAX public permalink requires canonical recipient ID', ChannelPostIncomingAdapter::maxSnapshot($invalidPublic)['post_url'], '');
[$publicStore, $publicProvider, $publicTransport, $publicService] = channelPostScenario($publicPost);
$publicQueued = channelPostReceive($publicService, $publicPost);
channelPostCheck('MAX public permalink uses registered recipient and existing token owner', array_values($publicStore->posts)[0]['snapshot']['post_url'], $publicPost['url']);
$publicUnmanaged = $publicPost; $publicUnmanaged['recipient']['chat_id'] = -222;
channelPostCheck('MAX valid public URL cannot admit an unregistered recipient', channelPostReceive($publicService, $publicUnmanaged)['queued'], false);
channelPostCheck('MAX public URL observation performs no network access', count($publicProvider->calls), 0);
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
channelPostCheck('MAX keyboard contains only our individual URL button', $provider->message['body']['attachments'][1]['payload']['buttons'], [[['type'=>'link','text'=>'Рассчитать на мои даты','url'=>ChannelPostButtonTransport::buttonUrl(array_values($store->posts)[0])]]]);
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
    if ($platform === 'max') {
        $provider->mode = 'success';
        $channel['expected_public_url'] = 'https://max.ru/example_channel';
        $provider->chatFields = ['status' => 'active', 'is_public' => true, 'link' => 'https://max.ru/example_channel/'];
        channelPostCheck('MAX capability verification matches the exact public channel', $transport->verifyChannel($channel)['state'], 'ready');
        $provider->chatFields['link'] = 'https://max.ru/other_channel';
        channelPostCheck('MAX capability rejects a different managed channel', $transport->verifyChannel($channel)['reason'], 'public_channel_identity_mismatch');
        $provider->chatFields['link'] = $channel['expected_public_url'];
        $provider->chatFields['is_public'] = false;
        channelPostCheck('MAX capability rejects a nonpublic channel', $transport->verifyChannel($channel)['reason'], 'public_channel_identity_mismatch');
        $channel['expected_public_url'] = 'https://max.ru/join/example_channel';
        $rejected = $transport->verifyChannel($channel);
        channelPostCheck('MAX invalid public channel URL fails before provider reads', [$rejected['reason'], $rejected['receipt']['request_count']], ['invalid_expected_public_channel_url', 0]);
        channelPostCheck('MAX public channel verification performs no write', $provider->writes(), 0);
    }

    [$store, $provider, $transport, $service] = channelPostScenario($fixtures[$platform], $platform);
    $callback = $fixtures[$platform];
    if ($platform === 'max') $callback['body']['attachments'][1]['payload']['buttons'][0][] = ['type' => 'callback', 'text' => 'Чужая кнопка', 'payload' => 'foreign'];
    else $callback['reply_markup']['inline_keyboard'][0][] = ['text' => 'Чужая кнопка', 'callback_data' => 'foreign'];
    $provider->message = $callback; channelPostReceive($service, $callback, $platform);
    $replaced = channelPostRun($service, $platform);
    channelPostCheck($platform . ' owner-requested foreign keyboard replacement succeeds', $replaced[0]['state'] ?? '', 'sent');
    channelPostCheck($platform . ' foreign callback removed with one write', $provider->writes(), 1);
    $rows = $platform === 'max' ? $provider->message['body']['attachments'][1]['payload']['buttons'] : $provider->message['reply_markup']['inline_keyboard'];
    channelPostCheck($platform . ' replacement contains only our own URL', count($rows) === 1 && count($rows[0]) === 1 && ($rows[0][0]['url'] ?? '') === ChannelPostButtonTransport::buttonUrl(array_values($store->posts)[0]));
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

// Production webhook 2026-10-20 contained four quote spans that GET omitted.
// All text here is synthetic; retain the observed span offsets and overlap.
$quoteEvent = $fixtures['max'];
$quoteEvent['body']['text'] = str_repeat('т', 1300);
$quoteEvent['body']['markup'] = [
    ['from'=>0,'length'=>63,'type'=>'strong'], ['from'=>0,'length'=>63,'type'=>'heading'],
    ['url'=>'https://example.test/one','from'=>440,'length'=>9,'type'=>'link'],
    ['from'=>440,'length'=>9,'type'=>'strong'], ['from'=>450,'length'=>82,'type'=>'quote'],
    ['from'=>537,'length'=>29,'type'=>'strong'], ['from'=>607,'length'=>77,'type'=>'quote'],
    ['from'=>689,'length'=>27,'type'=>'strong'], ['from'=>750,'length'=>91,'type'=>'quote'],
    ['from'=>845,'length'=>39,'type'=>'strong'], ['from'=>918,'length'=>79,'type'=>'quote'],
];
$quoteRead = $quoteEvent;
$quoteRead['body']['markup'] = array_values(array_filter($quoteRead['body']['markup'], static fn(array $span):bool=>$span['type']!=='quote'));
[$store, $provider, $transport, $service] = channelPostScenario($quoteRead);
$queuedQuote = channelPostReceive($service, $quoteEvent);
$quoteHash = array_values($store->posts)[0]['content_hash'];
$quoteResult = channelPostRun($service)[0];
channelPostCheck('MAX event-only quote spans do not block the one button edit', [$quoteResult['state'], $provider->writes()], ['sent', 1]);
channelPostCheck('quote omission keeps exact provider text markup image and forward context', $provider->message['body']['text']===$quoteRead['body']['text'] && $provider->message['body']['markup']===$quoteRead['body']['markup'] && $provider->message['body']['attachments'][0]===$quoteRead['body']['attachments'][0] && $provider->message['link']===$quoteRead['link']);
channelPostCheck('quote tolerance leaves the immutable event and durable content hash unchanged', array_values($store->posts)[0]['snapshot']['markup']===$quoteEvent['body']['markup'] && array_values($store->posts)[0]['content_hash']===$quoteHash && ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::maxSnapshot($quoteEvent))!==ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::maxSnapshot($quoteRead)));
$quoteSelfEdit = $provider->message; $quoteSelfEdit['body']['markup'] = $quoteEvent['body']['markup'];
channelPostCheck('quote-shaped own edit keeps the existing publication version', channelPostReceive($service,$quoteSelfEdit,'max',true)['version_id'], $queuedQuote['version_id']);
channelPostCheck('quote-shaped completed replay never repeats the provider edit', [channelPostRun($service),$provider->writes()], [[],1]);

foreach (['text','photo','forward','link','style','span','partial_quotes','new_quote','unknown_style'] as $change) {
    $changed = $quoteRead;
    if($change==='text')$changed['body']['text'].='changed';
    elseif($change==='photo')$changed['body']['attachments'][0]['payload']['photo_id']=902;
    elseif($change==='forward')$changed['link']['mid']='mid.different';
    elseif($change==='link')$changed['body']['markup'][2]['url']='https://example.test/changed';
    elseif($change==='style')$changed['body']['markup'][0]['type']='emphasized';
    elseif($change==='span')$changed['body']['markup'][0]['length']++;
    elseif($change==='partial_quotes')$changed['body']['markup'][]=['from'=>450,'length'=>82,'type'=>'quote'];
    elseif($change==='new_quote')$changed['body']['markup'][]=['from'=>10,'length'=>12,'type'=>'quote'];
    else $changed['body']['markup'][]=['from'=>450,'length'=>82,'type'=>'blockquote'];
    [$store,$provider,$transport,$service] = channelPostScenario($changed);
    channelPostReceive($service,$quoteEvent);
    channelPostCheck('quote omission does not hide changed '.$change, [channelPostRun($service)[0]['reason'],$provider->writes()], ['post_changed_since_event',0]);
}
foreach (['extra_field','negative_start','string_length','zero_length','out_of_bounds'] as $invalid) {
    $bad = $quoteEvent;
    if($invalid==='extra_field')$bad['body']['markup'][4]['extra']='unrecognized';
    elseif($invalid==='negative_start')$bad['body']['markup'][4]['from']=-1;
    elseif($invalid==='string_length')$bad['body']['markup'][4]['length']='82';
    elseif($invalid==='zero_length')$bad['body']['markup'][4]['length']=0;
    else $bad['body']['markup'][4]['length']=10000;
    [$store,$provider,$transport,$service] = channelPostScenario($quoteRead);
    channelPostReceive($service,$bad);
    channelPostCheck('malformed quote stays blocked: '.$invalid, [channelPostRun($service)[0]['reason'],$provider->writes()], ['post_changed_since_event',0]);
}
[$store,$provider,$transport,$service] = channelPostScenario($quoteRead);
channelPostReceive($service,$quoteEvent);$provider->mode='timeout_unapplied';
$quoteUnknown = channelPostRun($service)[0]; $quoteReconcile = channelPostRun($service)[0];
channelPostCheck('quote omission cannot authorize retry of an unknown edit', [$quoteUnknown['state'],$quoteReconcile['state'],$provider->writes()], ['unknown','unknown',1]);
[$store,$provider] = channelPostScenario($quoteRead);
$strictAfter = new ChannelPostButtonTransport(static function($platform,$method,$path,$query,$body)use($provider){
    if($method==='GET'&&str_starts_with($path,'/messages/')&&$provider->writes()>0)$provider->message['body']['markup'][]=['from'=>450,'length'=>82,'type'=>'quote'];
    return $provider->request($platform,$method,$path,$query,$body);
});
$service = new ChannelPostAutomationService($store,$strictAfter);channelPostReceive($service,$quoteEvent);
$strictResult=channelPostRun($service)[0];
channelPostCheck('post-write preservation stays strict even for a quote-only change', [$strictResult['state'],$strictResult['receipt']['content_preserved'],$provider->writes()], ['unknown',false,1]);

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
channelPostCheck('Telegram keyboard contains only our individual URL button', $provider->message['reply_markup']['inline_keyboard'], [[['text'=>'Рассчитать на мои даты','url'=>ChannelPostButtonTransport::buttonUrl(array_values($store->posts)[0])]]]);
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
    $unsupportedType = $platform === 'max' ? 'audio' : 'video';
    if ($platform === 'max') $unsupported['body']['attachments'][0] = ['type' => 'audio', 'payload' => ['token' => 'fixture-audio']];
    else { unset($unsupported['photo']); $unsupported['video'] = ['file_id' => 'fixture-video']; }
    $r = channelPostReceive($service, $unsupported, $platform);
    channelPostCheck($platform . ' unsupported ' . $unsupportedType . ' stored as attention', $r['recognized'] && $r['state'] === 'requires_attention');
    channelPostCheck($platform . ' unsupported ' . $unsupportedType . ' does not call provider', count($provider->calls), 0);
    channelPostCheck($platform . ' unsupported ' . $unsupportedType . ' not fake queued success', $r['queued'], false);
}

$aloftVideo = [
    'chat' => ['id' => '-1001845835645', 'username' => 'anytour', 'type' => 'channel'],
    'message_id' => 1207,
    'date' => 1758623277,
    'caption' => "Aloft Palm Jumeirah\n📍 Дубай, ОАЭ\n\n💰 От 89 843 ₽ за двоих\nЦена найдена мониторингом 19.11.2026, 04:01 МСК.",
    'video' => ['file_id' => 'first-video-file-id', 'file_unique_id' => 'stable-video-identity'],
];
$aloftSnapshot = ChannelPostIncomingAdapter::telegramSnapshot($aloftVideo);
channelPostCheck('the approved Aloft caption keeps its contextual hotel and native video identity',
    [$aloftSnapshot['metadata']['capability'], $aloftSnapshot['metadata']['hotel_catalog_id'],
        $aloftSnapshot['attachments'][0]['file_unique_id'], $aloftSnapshot['post_url']],
    ['supported', 61159, 'stable-video-identity', 'https://t.me/anytour/1207']);
$aloftProvider = new FixturePostProvider($aloftVideo);
$aloftProvider->bot = 'anyru_bot';
$aloftTransport = new ChannelPostButtonTransport([$aloftProvider, 'request']);
$aloftOperation = [
    'platform' => 'telegram', 'external_message_id' => '1207', 'start_payload' => 'p_aloftfixture',
    'snapshot' => $aloftSnapshot,
    'channel' => ['channel_id' => '-1001845835645', 'bot_key' => 'anyru_bot',
        'bot_url' => 'https://t.me/anyru_bot', 'is_enabled' => true, 'post_mode' => 'append_button'],
];
$aloftResult = $aloftTransport->attempt($aloftOperation, static fn(): bool => true);
channelPostCheck('Aloft keyboard edit verifies once and never sends replacement video or caption',
    [$aloftResult['state'], $aloftProvider->writes(), array_keys(end($aloftProvider->calls)['body']),
        $aloftProvider->message['video'], $aloftProvider->message['caption']],
    ['sent', 1, ['chat_id', 'message_id', 'reply_markup'], $aloftVideo['video'], $aloftVideo['caption']]);
$renewedAloft = $aloftVideo;
$renewedAloft['video']['file_id'] = 'renewed-video-file-id';
channelPostCheck('the same Aloft video survives file credential renewal',
    ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::telegramSnapshot($renewedAloft)),
    ChannelPostIncomingAdapter::contentFingerprint($aloftSnapshot));
$renewedAloft['video']['file_unique_id'] = 'different-video';
channelPostCheck('a substituted Aloft video cannot confirm preservation',
    ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::telegramSnapshot($renewedAloft))
        !== ChannelPostIncomingAdapter::contentFingerprint($aloftSnapshot));
$wrongChannel = $aloftVideo['chat'];
$wrongChannel['id'] = '-1001845835646';
foreach ([
    'different channel' => ['chat' => $wrongChannel],
    'different post' => ['message_id' => 1043],
    'changed publication date' => ['date' => 1758623278],
    'old offer' => ['caption' => 'Aloft Palm Jumeirah. Прежнее предложение'],
    'bundled video' => ['media_group_id' => 'album'],
    'unstable provider file' => ['video' => ['file_id' => 'first-video-file-id']],
] as $scopeName => $patch) {
    $snapshot = ChannelPostIncomingAdapter::telegramSnapshot(array_replace($aloftVideo, $patch));
    channelPostCheck('archive video remains unsupported for ' . $scopeName,
        [$snapshot['metadata']['capability_reason'], isset($snapshot['metadata']['hotel_catalog_id'])],
        ['unsupported_media', false]);
}

[$store, $provider, $transport, $service] = channelPostScenario($fixtures['telegram'], 'telegram');
$album = $fixtures['telegram']; $album['media_group_id'] = 'album-91';
$one = channelPostReceive($service, $album, 'telegram'); $album['message_id'] = 62; $album['photo'][1]['file_unique_id'] = 'next-photo';
$two = channelPostReceive($service, $album, 'telegram');
channelPostCheck('Telegram album items share one post token', count($store->posts) === 1 && $one['publication_id'] === $two['publication_id']);
channelPostCheck('Telegram photo album accepted for grouped processing', $two['state'], 'pending');
channelPostCheck('Telegram album receive remains network-free', $provider->writes(), 0);

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

/** A MAX album is one actual MID containing all ordered image attachments. */
function channelPostMaxAlbum(array $base, int $count, bool $keyboard = true): array {
    $images = [];
    for ($i = 0; $i < $count; $i++) {
        $images[] = ['type'=>'image','payload'=>['photo_id'=>1901+$i,'token'=>'fixture-album-token-'.$i,'url'=>'https://cdn.example.test/album-'.$i]];
    }
    $base['body']['mid'] = 'mid.album-'.$count.'-'.($keyboard ? 'keyboard' : 'plain');
    $base['body']['attachments'] = $images;
    if ($keyboard) $base['body']['attachments'][] = ['type'=>'inline_keyboard','payload'=>['buttons'=>[[
        ['type'=>'open_app','text'=>'Комментарии','contact_id'=>424242,'payload'=>'fixture-foreign-private-launch'],
    ]]]];
    return $base;
}
foreach ([2, 6, 10, 11] as $photoCount) foreach ([false, true] as $hasKeyboard) {
    $album = channelPostMaxAlbum($fixtures['max'], $photoCount, $hasKeyboard);
    [$albumStore, $albumProvider, $albumTransport, $albumService] = channelPostScenario($album);
    $accepted = channelPostReceive($albumService, $album);
    channelPostCheck('MAX '.$photoCount.' photos durably queue with no API request', !empty($accepted['queued']) && count($albumProvider->calls) === 0);
    $done = channelPostRun($albumService);
    channelPostCheck('MAX '.$photoCount.' photos receive only our button', $done[0]['state'] ?? '', 'sent');
    channelPostCheck('MAX album performs one bounded edit', [$albumProvider->writes(), count($albumProvider->calls)], [1,5]);
    $after = $albumProvider->message;
    $afterMedia = array_values(array_filter($after['body']['attachments'], static fn($a)=>$a['type']==='image'));
    $beforeMedia = array_values(array_filter($album['body']['attachments'], static fn($a)=>$a['type']==='image'));
    channelPostCheck('MAX every photo and original ordering preserved', $afterMedia, $beforeMedia);
    channelPostCheck('MAX exact caption markup and forward context preserved', [$after['body']['text'],$after['body']['markup'],$after['link']], [$album['body']['text'],$album['body']['markup'],$album['link']]);
    $keys = array_values(array_filter($after['body']['attachments'], static fn($a)=>$a['type']==='inline_keyboard'));
    $own = ChannelPostButtonTransport::buttonUrl(array_values($albumStore->posts)[0]);
    channelPostCheck('MAX foreign open_app replaced by one keyboard and one own link', count($keys)===1 && $keys[0]['payload']['buttons'] === [[['type'=>'link','text'=>'Рассчитать на мои даты','url'=>$own]]]);
    $writes = array_values(array_filter($albumProvider->calls, static fn($c)=>$c['method']==='PUT'));
    channelPostCheck('MAX album edits attachments only', isset($writes[0]) && array_keys($writes[0]['body'])===['attachments']);
    $self = channelPostReceive($albumService, $after, 'max', true);
    channelPostCheck('MAX keyboard replacement is not a tour content version', $self['version_id'] ?? 0, $accepted['version_id']);
    channelPostCheck('MAX album self event and worker do not duplicate edit', channelPostRun($albumService)===[] && $albumProvider->writes()===1);
}
foreach ([12,13] as $photoCount) foreach ([false,true] as $hasKeyboard) {
    $album = channelPostMaxAlbum($fixtures['max'], $photoCount, $hasKeyboard);
    [$s,$p,$tr,$svc] = channelPostScenario($album);
    $blocked = channelPostReceive($svc,$album);
    channelPostCheck('MAX total attachment limit reserves our keyboard', [$blocked['state'] ?? '', $blocked['reason'] ?? '', count($p->calls)], ['requires_attention','attachment_limit',0]);
}
$album = channelPostMaxAlbum($fixtures['max'],6);
$changedKeyboard=$album; $changedKeyboard['body']['attachments'][6]['payload']['buttons']=[[['type'=>'callback','text'=>'Другой бот','payload'=>'foreign']]];
channelPostCheck('keyboard-only changes do not change tour fingerprint', ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::maxSnapshot($album)), ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::maxSnapshot($changedKeyboard)));
$rotated=$album;
foreach ($rotated['body']['attachments'] as &$attachment) if ($attachment['type']==='image') { $attachment['payload']['token'].='-rotated'; $attachment['payload']['url'].='?renewed'; }
unset($attachment);
channelPostCheck('all album signed token rotations keep content fingerprint', ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::maxSnapshot($album)), ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::maxSnapshot($rotated)));
[$s,$p,$tr,$svc]=channelPostScenario($album); channelPostReceive($svc,$album);
$p->message['body']['attachments'][5]['payload']['photo_id']=999999;
channelPostCheck('changed last album photo rejects stale edit', channelPostRun($svc)[0]['reason'] ?? '', 'post_changed_since_event');
channelPostCheck('stale album cannot write', $p->writes(),0);
$bad=$album; unset($bad['body']['attachments'][5]['payload']['photo_id']);
[$s,$p,$tr,$svc]=channelPostScenario($bad); $r=channelPostReceive($svc,$bad);
channelPostCheck('bad last album image is not hidden by album support', [$r['reason'] ?? '',count($p->calls)],['image_stable_id_missing',0]);
$bad=$album; $bad['body']['attachments'][5]=['type'=>'audio','payload'=>['token'=>'fixture-audio']];
[$s,$p,$tr,$svc]=channelPostScenario($bad); $r=channelPostReceive($svc,$bad);
channelPostCheck('mixed album keeps unsupported-media guard', [$r['reason'] ?? '',count($p->calls)],['unsupported_media',0]);
[$s,$p,$tr,$svc]=channelPostScenario($album); channelPostReceive($svc,$album);
unset($p->message['body']['attachments'][5]['payload']['token']);
channelPostCheck('every album photo needs a fresh reusable token', channelPostRun($svc)[0]['reason'] ?? '', 'image_token_missing');
channelPostCheck('missing last image token cannot drop a photo or write', $p->writes(),0);
foreach (['timeout_applied','timeout_unapplied'] as $mode) {
    [$s,$p,$tr,$svc]=channelPostScenario($album); channelPostReceive($svc,$album); $p->mode=$mode;
    $r=channelPostRun($svc); channelPostCheck('album lost response has honest outcome', $r[0]['state'] ?? '', $mode==='timeout_applied'?'sent':'unknown');
    channelPostRun($svc);
    channelPostCheck('album lost response never repeats PUT', $p->writes(),1);
}

// MAX renews video tokens and URLs while preserving explicit payload.id.
$videoAttachment = ['type'=>'video','payload'=>['id'=>12345678901234,'token'=>'fixture-private-video-token_A1','url'=>'https://cdn.example.test/video?expires=1'],
    'thumbnail'=>['url'=>'https://cdn.example.test/video-thumb?expires=1'],'width'=>1920,'height'=>1080,'duration'=>37];
$videoPost = channelPostMaxAlbum($fixtures['max'],0);
$videoPost['body']['mid'] = 'mid.video-only';
array_unshift($videoPost['body']['attachments'],$videoAttachment);
$mixedVideoPost = channelPostMaxAlbum($fixtures['max'],2);
$mixedVideoPost['body']['mid'] = 'mid.image-video-image';
array_splice($mixedVideoPost['body']['attachments'],1,0,[$videoAttachment]);
foreach (['video-only'=>$videoPost,'mixed'=>$mixedVideoPost] as $videoKind=>$videoMessage) {
    [$videoStore,$videoProvider,$videoTransport,$videoService]=channelPostScenario($videoMessage);
    $videoObserved=channelPostReceive($videoService,$videoMessage);
    channelPostCheck('MAX '.$videoKind.' is queued without provider requests',[$videoObserved['state'],count($videoProvider->calls)],['pending',0]);
    $videoResult=channelPostRun($videoService);
    channelPostCheck('MAX '.$videoKind.' receives a verified button with one bounded edit',[$videoResult[0]['state']??'',$videoProvider->writes(),count($videoProvider->calls)],['sent',1,5]);
    $beforeMedia=array_values(array_filter($videoMessage['body']['attachments'],static fn($a)=>$a['type']!=='inline_keyboard'));
    $afterMedia=array_values(array_filter($videoProvider->message['body']['attachments'],static fn($a)=>$a['type']!=='inline_keyboard'));
    channelPostCheck('MAX '.$videoKind.' preserves exact media and order including video metadata',$afterMedia,$beforeMedia);
    channelPostCheck('MAX '.$videoKind.' preserves caption markup and forward context',[$videoProvider->message['body']['text'],$videoProvider->message['body']['markup'],$videoProvider->message['link']],[$videoMessage['body']['text'],$videoMessage['body']['markup'],$videoMessage['link']]);
    $videoWrite=array_values(array_filter($videoProvider->calls,static fn($c)=>$c['method']==='PUT'))[0];
    $writtenMedia=array_slice($videoWrite['body']['attachments'],0,-1);
    channelPostCheck('MAX '.$videoKind.' sends only existing typed tokens and a keyboard',array_keys($videoWrite['body'])===['attachments']&&$writtenMedia===array_map(static fn($a)=>['type'=>$a['type'],'payload'=>['token'=>$a['payload']['token']]],$beforeMedia));
    $videoOwn=ChannelPostButtonTransport::buttonUrl(array_values($videoStore->posts)[0]);
    channelPostCheck('MAX '.$videoKind.' has only its unique button',ChannelPostIncomingAdapter::containsOnlyButton(ChannelPostIncomingAdapter::maxSnapshot($videoProvider->message),$videoOwn,'Рассчитать на мои даты'));
    channelPostCheck('MAX '.$videoKind.' snapshot retains complete original video metadata',array_values($videoStore->posts)[0]['snapshot']['raw_body'],$videoMessage);
    channelPostCheck('MAX '.$videoKind.' receipt does not expose media tokens',strpos(json_encode($videoResult),'fixture-private')===false);
    $videoSelf=channelPostReceive($videoService,$videoProvider->message,'max',true);
    channelPostCheck('MAX '.$videoKind.' self-edit keeps version and never repeats PUT',[$videoSelf['version_id'],channelPostRun($videoService),$videoProvider->writes()],[$videoObserved['version_id'],[],1]);
}
$videoHash=ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::maxSnapshot($videoPost));
$videoRenewed=$videoPost;
$videoRenewed['body']['attachments'][0]['payload']['token']='fixture-private-video-token_B2';
$videoRenewed['body']['attachments'][0]['payload']['id']='12345678901234';
$videoRenewed['body']['attachments'][0]['payload']['url']='https://cdn.example.test/video?expires=2';
$videoRenewed['body']['attachments'][0]['thumbnail']['url']='https://cdn.example.test/video-thumb?expires=2';
channelPostCheck('MAX renewed token playback and thumbnail URLs keep numeric or string clip identity',ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::maxSnapshot($videoRenewed)),$videoHash);
[$videoStore,$videoProvider,$videoTransport,$videoService]=channelPostScenario($videoPost);
channelPostReceive($videoService,$videoPost);$videoProvider->message=$videoRenewed;
channelPostCheck('MAX fresh renewed video URLs still permit exact-token edit',channelPostRun($videoService)[0]['state']??'','sent');
$videoChanged=$videoPost;$videoChanged['body']['attachments'][0]['payload']['id']=12345678901235;
channelPostCheck('MAX different video ID changes identity even with the same reusable token',ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::maxSnapshot($videoChanged))!==$videoHash);
[$videoStore,$videoProvider,$videoTransport,$videoService]=channelPostScenario($videoPost);
channelPostReceive($videoService,$videoPost);$videoProvider->message=$videoChanged;
channelPostCheck('MAX changed clip before edit is rejected without PUT',[channelPostRun($videoService)[0]['reason']??'',$videoProvider->writes()],['post_changed_since_event',0]);
foreach ([null,'',123,true,'bad token','bad/token',"bad-token\n",['unexpected']] as $invalidVideoToken) {
    $invalidVideo=$videoPost;$invalidVideo['body']['attachments'][0]['payload']['token']=$invalidVideoToken;
    [$videoStore,$videoProvider,$videoTransport,$videoService]=channelPostScenario($invalidVideo);
    $videoInvalid=channelPostReceive($videoService,$invalidVideo);
    channelPostCheck('MAX invalid or missing video identity requires attention before provider access',[$videoInvalid['state'],$videoInvalid['reason'],count($videoProvider->calls)],['requires_attention','video_token_invalid',0]);
}
foreach ([null,'',0,-1,true,12345678901234.0,'012345678901234','+12345678901234','1e14','12345678901234 ','123456789012345678901',[]] as $invalidVideoId) {
    $invalidVideo=$videoPost;$invalidVideo['body']['attachments'][0]['payload']['id']=$invalidVideoId;
    [$videoStore,$videoProvider,$videoTransport,$videoService]=channelPostScenario($invalidVideo);
    $videoInvalid=channelPostReceive($videoService,$invalidVideo);
    channelPostCheck('MAX missing or malformed stable video ID fails before provider access',[$videoInvalid['state'],$videoInvalid['reason'],count($videoProvider->calls)],['requires_attention','video_stable_id_missing',0]);
}
[$videoStore,$videoProvider,$videoTransport,$videoService]=channelPostScenario($videoPost);
$rotatingObserved=channelPostReceive($videoService,$videoPost);
$videoProvider->onRead=static function()use($videoProvider):void{
    $videoProvider->message['body']['attachments'][0]['payload']['token']='fixture-rotated-read-'.count($videoProvider->calls);
    $videoProvider->message['body']['attachments'][0]['payload']['url']='https://renewed-cdn.example.test/video?read='.count($videoProvider->calls);
};
$rotatingResult=channelPostRun($videoService)[0];
$rotatingWrite=array_values(array_filter($videoProvider->calls,static fn($c)=>$c['method']==='PUT'))[0];
channelPostCheck('MAX video token rotation before and after PUT preserves the same clip',[$rotatingResult['state'],$rotatingResult['receipt']['content_preserved'],$rotatingResult['receipt']['button_verified'],$videoProvider->writes()],['sent',true,true,1]);
channelPostCheck('MAX edit uses the exact token obtained immediately before PUT',$rotatingWrite['body']['attachments'][0]['payload']['token'],'fixture-rotated-read-3');
$rotatingSelf=channelPostReceive($videoService,$videoProvider->message,'max',true);
channelPostCheck('MAX rotated-token self edit does not create another version or PUT',[$rotatingSelf['version_id'],channelPostRun($videoService),$videoProvider->writes()],[$rotatingObserved['version_id'],[],1]);
foreach ([10,11] as $mixedPhotoCount) {
    $videoBoundary=channelPostMaxAlbum($fixtures['max'],$mixedPhotoCount);
    array_splice($videoBoundary['body']['attachments'],1,0,[$videoAttachment]);
    [$videoStore,$videoProvider,$videoTransport,$videoService]=channelPostScenario($videoBoundary);
    $videoBoundaryResult=channelPostReceive($videoService,$videoBoundary);
    if($mixedPhotoCount===10)channelPostCheck('MAX ten photos and one video reserve the twelfth slot for the button',channelPostRun($videoService)[0]['state']??'','sent');
    else channelPostCheck('MAX twelve mixed media cannot silently lose an attachment',[$videoBoundaryResult['state'],$videoBoundaryResult['reason'],$videoProvider->writes()],['requires_attention','attachment_limit',0]);
}
foreach (['timeout_applied','timeout_unapplied'] as $videoMode) {
    [$videoStore,$videoProvider,$videoTransport,$videoService]=channelPostScenario($videoPost);
    channelPostReceive($videoService,$videoPost);$videoProvider->mode=$videoMode;
    $videoResult=channelPostRun($videoService);
    channelPostCheck('MAX video lost response has honest readback outcome',$videoResult[0]['state']??'',$videoMode==='timeout_applied'?'sent':'unknown');
    channelPostRun($videoService);
    channelPostCheck('MAX video lost response never repeats PUT',$videoProvider->writes(),1);
}
[$videoStore,$videoProvider,$videoTransport,$videoService]=channelPostScenario($videoPost);
channelPostReceive($videoService,$videoPost);
$videoProvider->onRead=static function()use($videoProvider):void{if($videoProvider->writes()>0)$videoProvider->message['body']['attachments'][0]['payload']['id']=12345678901236;};
$videoResult=channelPostRun($videoService);
channelPostCheck('MAX changed video in post-edit readback cannot be marked preserved',[$videoResult[0]['state']??'',$videoResult[0]['receipt']['content_preserved']??null],['unknown',false]);
channelPostRun($videoService);
channelPostCheck('MAX mismatched video readback never triggers another PUT',$videoProvider->writes(),1);
$durableVideo=$videoPost;$durableVideo['body']['mid']='mid.video-durable-registry';
$durableVideoProvider=new FixturePostProvider($durableVideo);
$durableVideoService=new ChannelPostAutomationService($actualRegistry,new ChannelPostButtonTransport([$durableVideoProvider,'request']));
$durableVideoObserved=channelPostReceive($durableVideoService,$durableVideo);
$durableVideoResult=$durableVideoService->processPublication('anytour','max','id9704048781_1_bot',$durableVideoObserved['publication_id'],$durableVideoObserved['version_id']);
$durableVideoSaved=$actualRegistry->publication($durableVideoObserved['publication_id']);
channelPostCheck('actual registry stores video edit receipt with one durable write',[$durableVideoResult['state'],$durableVideoSaved['state'],$durableVideoSaved['write_attempts']],['sent','sent',1]);
channelPostCheck('actual immutable video snapshot retains complete original message',$durableVideoSaved['snapshot']['raw_body'],$durableVideo);
channelPostCheck('actual video self-edit keeps original version and token',channelPostReceive($durableVideoService,$durableVideoProvider->message,'max',true)['version_id'],$durableVideoObserved['version_id']);

// Historical snapshots and hashes stay immutable during the token-to-ID change.
$legacyVideoSnapshot=ChannelPostIncomingAdapter::maxSnapshot($videoPost);
unset($legacyVideoSnapshot['attachments'][0]['video_id']);
channelPostCheck('old normalized video safely recovers its ID from bound raw media',ChannelPostIncomingAdapter::contentFingerprint($legacyVideoSnapshot),$videoHash);
foreach (['token','count','type','order'] as $brokenBinding) {
    $unbound=ChannelPostIncomingAdapter::maxSnapshot($mixedVideoPost);
    unset($unbound['attachments'][1]['video_id']);
    if($brokenBinding==='token')$unbound['raw_body']['body']['attachments'][1]['payload']['token']='unbound-token';
    elseif($brokenBinding==='count')array_pop($unbound['attachments']);
    elseif($brokenBinding==='type')$unbound['raw_body']['body']['attachments'][1]['type']='image';
    else $unbound['raw_body']['body']['attachments']=array_reverse($unbound['raw_body']['body']['attachments']);
    channelPostCheck('legacy raw video ID rejects mismatched '.$brokenBinding.' binding',ChannelPostIncomingAdapter::maxVideoIds($unbound)[1]??null,'');
}
foreach (['sent','unknown','claimed','write_started','unresolved_unknown'] as $legacyState) {
    $legacyMessage=$videoPost;$legacyMessage['body']['mid']='mid.legacy-token-hash-'.$legacyState;
    $legacySnapshot=ChannelPostIncomingAdapter::maxSnapshot($legacyMessage);
    unset($legacySnapshot['attachments'][0]['video_id']);
    if($legacyState==='unresolved_unknown')unset($legacySnapshot['raw_body']['body']['attachments'][0]['payload']['id']);
    $opaqueSnapshot=$legacySnapshot;unset($opaqueSnapshot['raw_body']);
    $legacySnapshot['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($opaqueSnapshot);
    $legacyPost=$actualRegistry->registerPublication('anytour','max','id9704048781_1_bot',(string)$legacyMessage['recipient']['chat_id'],$legacyMessage['body']['mid'],$legacySnapshot);
    $state=$legacyState==='unresolved_unknown'?'unknown':$legacyState;$held=in_array($state,['claimed','write_started'],true);
    $q=$pdo->prepare('UPDATE post_versions SET state=?,write_attempts=?,claim_token=?,claimed_at=? WHERE id=?');
    $q->execute([$state,$state==='claimed'?0:1,$held?'fixture-held':null,$held?gmdate('Y-m-d H:i:s'):null,$legacyPost['version_id']]);
    $rowBefore=$pdo->query('SELECT * FROM post_versions WHERE id='.(int)$legacyPost['version_id'])->fetch();
    $incoming=$legacyMessage;$incoming['body']['attachments'][0]['payload']['token']='fixture-renewed-'.$legacyState;
    if($state==='sent')$incoming['body']['attachments']=[ $incoming['body']['attachments'][0], ['type'=>'inline_keyboard','payload'=>['buttons'=>[[['type'=>'link','text'=>'Рассчитать на мои даты','url'=>ChannelPostButtonTransport::buttonUrl($legacyPost)]]]]] ];
    $incomingSnapshot=ChannelPostIncomingAdapter::fromUpdate('max',channelPostUpdate($incoming,'max',true))['snapshot'];
    $incomingSnapshot['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($incomingSnapshot);
    $same=$actualRegistry->registerPublication('anytour','max','id9704048781_1_bot',(string)$incoming['recipient']['chat_id'],$incoming['body']['mid'],$incomingSnapshot);
    channelPostCheck('token fingerprint upgrade retains '.$legacyState.' operation without replay',[$same['version_id'],$same['state'],$same['start_payload']],[$legacyPost['version_id'],$state,$legacyPost['start_payload']]);
    channelPostCheck('token fingerprint upgrade never rewrites immutable '.$legacyState.' snapshot or hash',$pdo->query('SELECT * FROM post_versions WHERE id='.(int)$legacyPost['version_id'])->fetch(),$rowBefore);
    if($legacyState==='unknown') {
        $incoming['body']['attachments']=[ $incoming['body']['attachments'][0], ['type'=>'inline_keyboard','payload'=>['buttons'=>[[['type'=>'link','text'=>'Рассчитать на мои даты','url'=>ChannelPostButtonTransport::buttonUrl($legacyPost)]]]]] ];
        $legacyProvider=new FixturePostProvider($incoming);
        $legacyService=new ChannelPostAutomationService($actualRegistry,new ChannelPostButtonTransport([$legacyProvider,'request']));
        $confirmed=channelPostReceive($legacyService,$incoming,'max',true);
        channelPostCheck('authenticated own button confirms old token-hash UNKNOWN without a provider request',[$confirmed['state'],$confirmed['version_id'],count($legacyProvider->calls),$actualRegistry->publication($legacyPost['publication_id'])['write_attempts']],['sent',$legacyPost['version_id'],0,1]);
        $confirmedRow=$pdo->query('SELECT * FROM post_versions WHERE id='.(int)$legacyPost['version_id'])->fetch();
        channelPostCheck('old UNKNOWN confirmation preserves its original snapshot and hash',[$confirmedRow['snapshot_json'],$confirmedRow['content_hash']],[$rowBefore['snapshot_json'],$rowBefore['content_hash']]);
    }
}

foreach (['max','telegram'] as $platform) {
    [$s,$p,$tr,$svc]=channelPostScenario($fixtures[$platform],$platform);
    channelPostReceive($svc,$fixtures[$platform],$platform); channelPostRun($svc,$platform);
    $withExtra=$p->message;
    if ($platform==='max') $withExtra['body']['attachments'][1]['payload']['buttons'][]=[['type'=>'callback','text'=>'Чужая','payload'=>'foreign']];
    else $withExtra['reply_markup']['inline_keyboard'][]=[['text'=>'Чужая','callback_data'=>'foreign']];
    $snapshot=$platform==='max'?ChannelPostIncomingAdapter::maxSnapshot($withExtra):ChannelPostIncomingAdapter::telegramSnapshot($withExtra);
    $own=ChannelPostButtonTransport::buttonUrl(array_values($s->posts)[0]);
    channelPostCheck($platform.' an own button plus foreign rows is not an exact finished keyboard', ChannelPostIncomingAdapter::containsButton($snapshot,$own,'Рассчитать на мои даты') && count($snapshot['keyboard'])>1);
    // A new pending operation with an already-present own link must still remove foreign rows.
    $p2=new FixturePostProvider($withExtra); $op=array_values($s->posts)[0]; $op['snapshot']=$snapshot;
    $tr2=new ChannelPostButtonTransport([$p2,'request']); $r=$tr2->attempt($op,static fn()=>true);
    channelPostCheck($platform.' own-plus-foreign keyboard is replaced, not accepted early', [$r['state'],$p2->writes()],['sent',1]);
}


foreach (['max','telegram'] as $platform) {
    [$s,$p,$tr,$svc]=channelPostScenario($fixtures[$platform],$platform);
    $first=channelPostReceive($svc,$fixtures[$platform],$platform);
    $second=$fixtures[$platform];
    if($platform==='max') $second['body']['mid']='mid.explicit-target';
    else $second['message_id']=123456;
    $target=channelPostReceive($svc,$second,$platform);$p->message=$second;
    $wrong=$svc->processPublication('wrong',$platform,$platform==='max'?'id9704048781_1_bot':'Any_tour_bot',$target['publication_id'],$target['version_id']);
    channelPostCheck($platform.' exact target rejects another project without provider access',[$wrong['reason'],count($p->calls)],['target_publication_changed',0]);
    $badVersion=$svc->processPublication('anytour',$platform,$platform==='max'?'id9704048781_1_bot':'Any_tour_bot',$target['publication_id'],$target['version_id']+100);
    channelPostCheck($platform.' exact target rejects stale version without provider access',[$badVersion['reason'],count($p->calls)],['target_publication_changed',0]);
    $done=$svc->processPublication('anytour',$platform,$platform==='max'?'id9704048781_1_bot':'Any_tour_bot',$target['publication_id'],$target['version_id']);
    channelPostCheck($platform.' explicit operation sends only exact publication',[$done['state'],$p->writes(),$s->publication($first['publication_id'])['state']],['sent',1,'pending']);
    $again=$svc->processPublication('anytour',$platform,$platform==='max'?'id9704048781_1_bot':'Any_tour_bot',$target['publication_id'],$target['version_id']);
    channelPostCheck($platform.' completed explicit target does not replay',[$again['reason'],$p->writes()],['target_not_claimable',1]);
}



/** Exact per-message provider: sibling cleanup cannot edit or verify the anchor. */
final class FixtureAlbumPostProvider
{
    public array $messages = [];
    public array $calls = [];
    public string $mode = 'success';
    public function __construct(array $message) { $this->add($message); }
    public function add(array $message): void { $this->messages[(string)$message['message_id']] = $message; }
    public function request(string $platform,string $method,string $path,array $query,?array $body): array {
        $this->calls[] = compact('platform','method','path','query','body');
        $mid = $path === 'editMessageReplyMarkup' ? (string)($body['message_id'] ?? '') : (string)array_key_first($this->messages);
        if (!isset($this->messages[$mid])) throw new RuntimeException('unobserved album message');
        $provider = new FixturePostProvider($this->messages[$mid]); $provider->mode = $this->mode;
        try { return $provider->request($platform,$method,$path,$query,$body); }
        finally { $this->messages[$mid] = $provider->message; }
    }
    public function writes(): int { return count(array_filter($this->calls,static fn($c)=>$c['path']==='editMessageReplyMarkup')); }
}

/** Real registry + transport: one anchor button and cleanup for every sibling. */
$tgGroupBase=$fixtures['telegram'];$tgGroupBase['media_group_id']='tg-ten-photos';
$tgGroupBase['message_id']=701;$tgGroupBase['photo'][1]['file_unique_id']='group-photo-701';
$tgGroupProvider=new FixtureAlbumPostProvider($tgGroupBase);
$tgGroupService=new ChannelPostAutomationService($actualRegistry,new ChannelPostButtonTransport([$tgGroupProvider,'request']));
$tgGroupToken=null;$tgGroupReceipt=null;
foreach(range(701,710) as $index=>$mid) {
    $member=$tgGroupBase;$member['message_id']=$mid;
    $member['photo'][1]['file_unique_id']='group-photo-'.$mid;
    $member['photo'][1]['file_id']='group-file-'.$mid;
    if($mid!==701) unset($member['caption'],$member['caption_entities']);
    $tgGroupProvider->add($member);
    $r=$tgGroupService->receive('telegram',['update_id'=>7000+$index,'channel_post'=>$member],'anytour','Any_tour_bot');
    if($tgGroupToken===null)$tgGroupToken=$actualRegistry->publication($r['publication_id'])['start_payload'];
    $tgGroupReceipt=$r;
    $tgGroupService->processPublication('anytour','telegram','Any_tour_bot',$r['publication_id'],$r['version_id']);
}
$tgGroupLatest=$actualRegistry->publication($tgGroupReceipt['publication_id']);
channelPostCheck('Telegram ten updates have one token and all ten recorded images',[$tgGroupLatest['start_payload'],count($tgGroupLatest['snapshot']['attachments']),$tgGroupLatest['snapshot']['text']],[$tgGroupToken,10,$tgGroupBase['caption']]);
channelPostCheck('Telegram complete group writes one anchor and clears nine foreign sibling keyboards',[$tgGroupProvider->writes(),$tgGroupLatest['state'],$tgGroupLatest['snapshot']['metadata']['actual_message_id']],[10,'sent','701']);
$anchorRows=$tgGroupProvider->messages['701']['reply_markup']['inline_keyboard'];
$groupUrl=ChannelPostButtonTransport::buttonUrl($tgGroupLatest);
channelPostCheck('Telegram complete group has exactly the individual AnyTour anchor button',$anchorRows,[[['text'=>'Рассчитать на мои даты','url'=>$groupUrl]]]);
foreach(range(702,710) as $mid) channelPostCheck('Telegram foreign sibling '.$mid.' is empty without a duplicate own link',$tgGroupProvider->messages[(string)$mid]['reply_markup']['inline_keyboard'],[]);
channelPostCheck('Telegram every album image remains attached to its original numeric message',array_map(static fn($m)=>$m['photo'][1]['file_unique_id'],array_values($tgGroupProvider->messages)),array_map(static fn($mid)=>'group-photo-'.$mid,range(701,710)));
$tgGroupWrites=array_values(array_filter($tgGroupProvider->calls,static fn($c)=>$c['path']==='editMessageReplyMarkup'));
channelPostCheck('Telegram group edit omits photo and caption fields',isset($tgGroupWrites[0])&&array_keys($tgGroupWrites[0]['body'])===['chat_id','message_id','reply_markup']&&(string)$tgGroupWrites[0]['body']['message_id']==='701');
$tgSelf=$tgGroupProvider->messages['701'];$tgSelf['edit_date']=$tgSelf['date']+1;
$self=$tgGroupService->receive('telegram',['update_id'=>7100,'edited_channel_post'=>$tgSelf],'anytour','Any_tour_bot');
channelPostCheck('Telegram anchor self edit retains the verified whole aggregate without another write',[$self['state'],$tgGroupProvider->writes(),count($actualRegistry->publication($self['publication_id'])['snapshot']['attachments'])],['sent',10,10]);
$tgUnknown=$tgGroupBase;$tgUnknown['media_group_id']='tg-unknown-album';$tgUnknown['message_id']=801;
$unknownProvider=new FixtureAlbumPostProvider($tgUnknown);$unknownProvider->mode='timeout_applied';
$unknownService=new ChannelPostAutomationService($actualRegistry,new ChannelPostButtonTransport([$unknownProvider,'request']));
$ur=$unknownService->receive('telegram',['update_id'=>8000,'channel_post'=>$tgUnknown],'anytour','Any_tour_bot');
$uo=$unknownService->processPublication('anytour','telegram','Any_tour_bot',$ur['publication_id'],$ur['version_id']);
channelPostCheck('Telegram group ambiguous reply is honestly unknown',$uo['state'],'unknown');
$other=$tgUnknown;$other['message_id']=802;$other['photo'][1]['file_unique_id']='group-photo-802';unset($other['caption'],$other['caption_entities']);
$unknownProvider->add($other);$unknownProvider->mode='success';
$ur=$unknownService->receive('telegram',['update_id'=>8001,'channel_post'=>$other],'anytour','Any_tour_bot');
$unknownService->processPublication('anytour','telegram','Any_tour_bot',$ur['publication_id'],$ur['version_id']);
channelPostCheck('Telegram later foreign sibling is cleared while the anchor stays unknown',[$unknownProvider->writes(),$actualRegistry->publication($ur['publication_id'])['state']],[2,'unknown']);
$unknownWrites=array_values(array_filter($unknownProvider->calls,static fn($c)=>$c['path']==='editMessageReplyMarkup'));
channelPostCheck('Telegram UNKNOWN anchor is never written twice while another member is cleaned',array_map(static fn($c)=>(string)$c['body']['message_id'],$unknownWrites),['801','802']);
channelPostCheck('Telegram sibling clear removes its foreign keyboard despite unknown anchor',$unknownProvider->messages['802']['reply_markup']['inline_keyboard'],[]);
$ownEvent=$unknownProvider->messages['801'];$ownEvent['edit_date']=$ownEvent['date']+2;
$resolved=$unknownService->receive('telegram',['update_id'=>8002,'edited_channel_post'=>$ownEvent],'anytour','Any_tour_bot');
channelPostCheck('authenticated target event resolves grouped UNKNOWN with both images',[$resolved['state'],$unknownProvider->writes(),count($actualRegistry->publication($resolved['publication_id'])['snapshot']['attachments'])],['sent',2,2]);


foreach(['max','telegram'] as $platform){
    $message=$fixtures[$platform];
    if($platform==='max')$message['body']['mid']='mid.late-foreign-keyboard';
    else $message['message_id']=9901;
    $provider=new FixturePostProvider($message);
    $service=new ChannelPostAutomationService($actualRegistry,new ChannelPostButtonTransport([$provider,'request']));
    $initial=channelPostReceive($service,$message,$platform);
    $service->processPublication('anytour',$platform,$platform==='max'?'id9704048781_1_bot':'Any_tour_bot',$initial['publication_id'],$initial['version_id']);
    $foreign=$provider->message;
    if($platform==='max'){
        $foreign['body']['attachments'][1]['payload']['buttons'][]=[['type'=>'open_app','text'=>'Комментарии другого бота','contact_id'=>4242,'payload'=>'foreign']];
        $foreign['timestamp']+=5000;
    }else{
        $foreign['reply_markup']['inline_keyboard'][]=[['text'=>'Комментарии другого бота','callback_data'=>'foreign']];
        $foreign['edit_date']=$foreign['date']+5;
    }
    $provider->message=$foreign;
    $observed=channelPostReceive($service,$foreign,$platform,true);
    channelPostCheck($platform.' newer foreign keyboard after a verified edit queues a fresh correction',[$observed['state'],$observed['version_id']>$initial['version_id']],['pending',true]);
    $service->processPublication('anytour',$platform,$platform==='max'?'id9704048781_1_bot':'Any_tour_bot',$observed['publication_id'],$observed['version_id']);
    $snapshot=$platform==='max'?ChannelPostIncomingAdapter::maxSnapshot($provider->message):ChannelPostIncomingAdapter::telegramSnapshot($provider->message);
    $current=$actualRegistry->publication($observed['publication_id']);
    channelPostCheck($platform.' late foreign button is removed without changing tour text',ChannelPostIncomingAdapter::containsOnlyButton($snapshot,ChannelPostButtonTransport::buttonUrl($current),'Рассчитать на мои даты')&&$provider->writes()===2&&$current['state']==='sent');
    channelPostReceive($service,$foreign,$platform,true);
    channelPostCheck($platform.' duplicate foreign edit does not repeat the correction',$provider->writes(),2);
    $message2=$message;
    if($platform==='max')$message2['body']['mid']='mid.unknown-new-foreign-keyboard';
    else $message2['message_id']=9902;
    $provider2=new FixturePostProvider($message2);$provider2->mode='timeout_unapplied';
    $service2=new ChannelPostAutomationService($actualRegistry,new ChannelPostButtonTransport([$provider2,'request']));
    $r=channelPostReceive($service2,$message2,$platform);
    $service2->processPublication('anytour',$platform,$platform==='max'?'id9704048781_1_bot':'Any_tour_bot',$r['publication_id'],$r['version_id']);
    $newWrong=$message2;
    if($platform==='max'){$newWrong['timestamp']+=9000;$newWrong['body']['attachments'][1]['payload']['buttons'][]=[['type'=>'callback','text'=>'Чужая','payload'=>'foreign']];}
    else{$newWrong['edit_date']=$newWrong['date']+9;$newWrong['reply_markup']['inline_keyboard'][]=[['text'=>'Чужая','callback_data'=>'foreign']];}
    $provider2->message=$newWrong;
    $r2=channelPostReceive($service2,$newWrong,$platform,true);
    $service2->processPublication('anytour',$platform,$platform==='max'?'id9704048781_1_bot':'Any_tour_bot',$r2['publication_id'],$r2['version_id']);
    channelPostCheck($platform.' newer wrong-keyboard event cannot reset ambiguous write',[$provider2->writes(),$actualRegistry->publication($r2['publication_id'])['state']],[1,'unknown']);
}

// Public HTML and provider-shaped synthetic responses for the one manual repair.
// No Telegram credentials or network calls are used by these cases.
$aloftHtml = <<<'HTML'
<div class="tgme_widget_message" data-post="anytour/1678">
<a class="tgme_widget_message_video_player" href="https://t.me/anytour/1678"><video src="https://cdn4.telesco.pe/file/fixture1678.mp4?token=temporary"></video><time class="message_video_duration">0:28</time></a>
<div class="tgme_widget_message_text"><b>Отель Aloft Palm Jumeirah</b><br>Сохранить <tg-emoji><i><b>📎</b></i></tg-emoji> подпись &amp; пробелы.</div>
<a class="tgme_widget_message_date" href="https://t.me/anytour/1678"><time datetime="2026-08-11T11:51:56+00:00">11:51</time></a>
<div class="tgme_widget_message_inline_keyboard"><div class="tgme_widget_message_inline_row"><a class="tgme_widget_message_inline_button url_button" href="https://t.me/AnyTour_MSK">Менеджер</a></div></div>
</div>
HTML;
$aloftPublic = ChannelPostIncomingAdapter::aloftPublicSnapshot($aloftHtml);
$aloftHash = ChannelPostIncomingAdapter::aloftPublicFingerprint($aloftPublic);
channelPostCheck('Aloft public parser preserves plain caption and honest provenance',
    [$aloftPublic['text'], $aloftPublic['title'], $aloftPublic['metadata']['observation_kind'], $aloftPublic['metadata']['capability'], $aloftPublic['raw_body']],
    ["Отель Aloft Palm Jumeirah\nСохранить 📎 подпись & пробелы.", 'Aloft Palm Jumeirah', 'public_read', 'requires_attention', []]);
channelPostCheck('Aloft public media never invents native IDs or retains signed credentials',
    $aloftPublic['attachments'], [['type'=>'video', 'provider'=>'telegram', 'duration'=>28, 'public_media_path'=>'/file/fixture1678.mp4']]);
$aloftNormalized = $aloftPublic; $aloftNormalized['media_group_id'] = ''; $aloftNormalized['metadata']['checked_at'] = 'later';
channelPostCheck('Aloft preview hash survives recording time and registry normalization',
    ChannelPostIncomingAdapter::aloftPublicFingerprint($aloftNormalized), $aloftHash);
foreach ([
    str_replace('data-post="anytour/1678"', 'data-post="anytour/1207"', $aloftHtml),
    $aloftHtml.$aloftHtml, str_replace('2026-08-11T', '2026-08-12T', $aloftHtml),
    str_replace('0:28', '0:29', $aloftHtml), str_replace('Aloft Palm Jumeirah', 'Another hotel', $aloftHtml),
    str_replace('<video ', '<a class="tgme_widget_message_photo_wrap"></a><video ', $aloftHtml),
    str_replace('data-post=', 'data-group="album" data-post=', $aloftHtml),
    str_replace('https://cdn4.telesco.pe', 'https://cdn4.telesco.pe.evil.test', $aloftHtml),
] as $index=>$invalidHtml) {
    $denied = false;
    try { ChannelPostIncomingAdapter::aloftPublicSnapshot($invalidHtml); }
    catch (InvalidArgumentException $e) { $denied = true; }
    channelPostCheck('Aloft malformed or different public target rejected '.$index, $denied);
}
foreach (['text', 'media', 'keyboard'] as $field) {
    $changed = $aloftPublic;
    if ($field === 'text') $changed['text'] .= ' Changed';
    elseif ($field === 'media') $changed['attachments'][0]['public_media_path'] = '/file/changed.mp4';
    else $changed['keyboard'][0][0]['url'] = 'https://t.me/another_bot';
    channelPostCheck('Aloft plan detects changed '.$field, ChannelPostIncomingAdapter::aloftPublicFingerprint($changed) !== $aloftHash);
}
$changed = $aloftPublic; $changed['keyboard'] = [];
channelPostCheck('Aloft body comparison excludes only replaceable public keyboard',
    ChannelPostIncomingAdapter::aloftPublicContentFingerprint($changed), ChannelPostIncomingAdapter::aloftPublicContentFingerprint($aloftPublic));
$aloftPublisher = ['username'=>'ANYTOURBOT_bot', 'bot_id'=>'123456789', 'credential_version'=>3];
$aloftChannel = ['project_key'=>'anytour', 'platform'=>'telegram', 'channel_id'=>'-1001845835645',
    'bot_key'=>'anyru_bot', 'canonical_bot_username'=>'anyru_bot', 'bot_url'=>'https://t.me/anyru_bot',
    'button_text'=>'Рассчитать на мои даты', 'is_enabled'=>1, 'post_mode'=>'append_button', 'config_version'=>7];
$aloftOperation = ['publication_id'=>901, 'version_id'=>902, 'project_key'=>'anytour', 'platform'=>'telegram',
    'bot_key'=>'anyru_bot', 'external_message_id'=>'1678', 'start_payload'=>'p_'.str_repeat('A',24),
    'channel'=>$aloftChannel, 'snapshot'=>$aloftPublic, 'state'=>'claimed', 'write_attempts'=>0,
    'write_attempted'=>false, 'claim_token'=>str_repeat('a',32), 'manual_button_repair'=>true,
    'publisher'=>$aloftPublisher, 'receipt'=>['repair_kind'=>'aloft_public_keyboard',
        'publisher_username'=>'ANYTOURBOT_bot', 'publisher_bot_id'=>'123456789', 'credential_version'=>3,
        'channel_config_version'=>7, 'public_baseline_sha256'=>$aloftHash, 'plan_digest'=>str_repeat('b',64)]];
$aloftNative = ['chat'=>['id'=>-1001845835645, 'type'=>'channel', 'username'=>'anytour'], 'message_id'=>1678,
    'date'=>1786449116, 'caption'=>$aloftPublic['text'],
    'video'=>['duration'=>28, 'file_id'=>'native-video-file', 'file_unique_id'=>'native-video-unique']];
function aloftFixtureTransport(array $message, string $mode='success'): array {
    $provider = (object)['calls'=>[], 'writes'=>0, 'message'=>$message, 'mode'=>$mode];
    $request = static function (string $platform,string $method,string $path,array $query,?array $body) use ($provider): array {
        $provider->calls[] = compact('platform','method','path','query','body');
        $ok = static fn(array $result): array => ['http'=>200, 'data'=>['ok'=>true, 'result'=>$result], 'transport_error'=>false];
        $me = ['id'=>$provider->mode === 'wrong_id' ? 987654321 : 123456789, 'is_bot'=>true,
            'username'=>$provider->mode === 'wrong_publisher' ? 'anyru_bot' : 'ANYTOURBOT_bot'];
        if ($path === 'getMe') return $ok($me);
        if ($path === 'getChatMember') return $ok(['user'=>$me, 'status'=>'administrator', 'can_edit_messages'=>$provider->mode !== 'no_rights']);
        if ($path === 'getChat') return $ok(['id'=>-1001845835645, 'type'=>$provider->mode === 'wrong_chat' ? 'supergroup' : 'channel', 'username'=>'anytour']);
        if ($path !== 'editMessageReplyMarkup') throw new RuntimeException('unexpected fixture method');
        $provider->writes++;
        $provider->message['reply_markup'] = $body['reply_markup'];
        if ($provider->mode === 'timeout') throw new RuntimeException('synthetic lost response');
        if ($provider->mode === 'rejected') return ['http'=>400, 'data'=>['ok'=>false, 'error_code'=>400,
            'description'=>'Bad Request: MESSAGE_ID_INVALID sensitive-not-for-receipt'], 'transport_error'=>false];
        if ($provider->mode === 'bare_true') return ['http'=>200, 'data'=>['ok'=>true, 'result'=>true], 'transport_error'=>false];
        if ($provider->mode === 'mixed_keyboard') $provider->message['reply_markup']['inline_keyboard'][] = [['text'=>'Other', 'url'=>'https://example.test/']];
        return $ok($provider->message);
    };
    return [new ChannelPostButtonTransport($request), $provider];
}
[$aloftTransport, $aloftProvider] = aloftFixtureTransport($aloftNative);
$proof = $aloftTransport->verifyPublisher($aloftChannel, $aloftPublisher);
channelPostCheck('Aloft publisher verifies separately from destination in three read-only calls',
    [$proof['state'], array_column($aloftProvider->calls,'path'), $aloftProvider->writes],
    ['ready', ['getMe','getChatMember','getChat'], 0]);
$defaultAttempt = (new ChannelPostButtonTransport())->attempt($aloftOperation, static fn(): bool=>true);
channelPostCheck('Aloft manual operation refuses default token transport before any call',
    [$defaultAttempt['reason'], $defaultAttempt['receipt']['request_count']], ['publisher_transport_required', 0]);
foreach (['wrong_id','wrong_publisher','no_rights','wrong_chat'] as $mode) {
    [$transport, $provider] = aloftFixtureTransport($aloftNative, $mode);
    $result = $transport->attempt($aloftOperation, static fn(): bool=>true);
    channelPostCheck('Aloft publisher proof blocks '.$mode, [$result['state'], $provider->writes], ['requires_attention',0]);
}
foreach (['marker','claim','target','destination','public_provenance','digest','credential'] as $field) {
    $operation = $aloftOperation;
    if ($field === 'marker') unset($operation['manual_button_repair']);
    elseif ($field === 'claim') $operation['state'] = 'pending';
    elseif ($field === 'target') $operation['external_message_id'] = '1207';
    elseif ($field === 'destination') $operation['channel']['bot_url'] = 'https://t.me/ANYTOURBOT_bot';
    elseif ($field === 'public_provenance') $operation['snapshot']['metadata']['capability'] = 'supported';
    elseif ($field === 'digest') $operation['snapshot']['text'] .= ' changed';
    else $operation['publisher']['credential_version']++;
    [$transport,$provider] = aloftFixtureTransport($aloftNative);
    $result = $transport->attempt($operation, static fn(): bool=>true);
    channelPostCheck('Aloft stale or unapproved '.$field.' makes zero calls', [$result['state'],count($provider->calls)], ['requires_attention',0]);
}
[$transport,$provider] = aloftFixtureTransport($aloftNative);
$claims = 0;
$claim = static function () use (&$claims): bool { return ++$claims === 1; };
$result = $transport->attempt($aloftOperation, $claim);
channelPostCheck('Aloft exact approved operation verifies one native keyboard edit',
    [$result['state'], $provider->writes, array_column($provider->calls,'path')], ['sent',1,['getMe','getChatMember','getChat','editMessageReplyMarkup']]);
$body = $provider->calls[3]['body'];
channelPostCheck('Aloft native write has only fixed identity and one destination button',
    $body, ['chat_id'=>'-1001845835645','message_id'=>'1678','reply_markup'=>['inline_keyboard'=>[[
        ['text'=>'Рассчитать на мои даты','url'=>'https://t.me/anyru_bot?start=p_'.str_repeat('A',24)],
    ]]]]);
channelPostCheck('Aloft receipt distinguishes public comparison from missing native before identity',
    [$result['receipt']['verified'], $result['receipt']['body_unchanged_by_method'], $result['receipt']['public_caption_matched'],
        $result['receipt']['publication_date_matched'], $result['receipt']['media_type_duration_matched'],
        $result['receipt']['native_media_identity_before_verified'], $result['receipt']['native_video_unique_id'],
        $result['receipt']['plan_digest'], strlen($result['receipt']['provider_result_sha256'])],
    [true,true,true,true,true,false,'native-video-unique',str_repeat('b',64),64]);
$repeat = $transport->attempt($aloftOperation, $claim);
channelPostCheck('Aloft durable before-write refusal prevents replay', [$repeat['reason'],$provider->writes], ['write_claim_or_config_changed',1]);
foreach (['identity','caption','date','duration','file_id','unique_id','album','photo','mixed_keyboard','bare_true','timeout','rejected'] as $case) {
    $native = $aloftNative;
    if ($case === 'identity') $native['message_id'] = 1207;
    elseif ($case === 'caption') $native['caption'] .= ' changed';
    elseif ($case === 'date') $native['date']++;
    elseif ($case === 'duration') $native['video']['duration'] = 29;
    elseif ($case === 'file_id') unset($native['video']['file_id']);
    elseif ($case === 'unique_id') unset($native['video']['file_unique_id']);
    elseif ($case === 'album') $native['media_group_id'] = 'another-album';
    elseif ($case === 'photo') $native['photo'] = [];
    [$transport,$provider] = aloftFixtureTransport($native, $case);
    $result = $transport->attempt($aloftOperation, static fn(): bool=>true);
    channelPostCheck('Aloft native '.$case.' is never fabricated as verified',
        [$result['state'],$provider->writes,isset($result['receipt']['verified'])], [$case === 'rejected' ? 'requires_attention' : 'unknown',1,false]);
    if ($case === 'rejected') channelPostCheck('Aloft native rejection is normalized without raw provider detail',
        [$result['receipt']['telegram_error_code'],$result['receipt']['telegram_error_reason'],str_contains(json_encode($result),'sensitive-not-for-receipt')],
        [400,'message_id_invalid',false]);
    $written = $aloftOperation; $written['write_attempts'] = 1; $written['write_attempted'] = true; $written['state'] = 'unknown';
    $callsBefore = count($provider->calls);
    $again = $transport->attempt($written, static fn(): bool=>true, true);
    channelPostCheck('Aloft written '.$case.' never retries during reconciliation',
        [$again['state'],$provider->writes,count($provider->calls)], ['unknown',1,$callsBefore]);
}

// Exercise the existing registry, writer and orchestration together. Each case
// owns an isolated database; provider replies and public HTML remain synthetic.
function aloftManualScenario(string $html, array $native, string $mode='success'): array {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    createChannelPostRegistryFixture($pdo);
    $pdo->exec("INSERT INTO projects VALUES(1,'anytour','AnyTour',1);
        INSERT INTO conversation_sources VALUES(9,1,'tg_anytour_msk','Telegram Москва','telegram',1,'manager')");
    $registry = new ChannelPostRegistryService($pdo);
    $registry->configureChannel(['project_key'=>'anytour', 'platform'=>'telegram', 'bot_key'=>'anyru_bot',
        'canonical_bot_username'=>'anyru_bot', 'channel_id'=>'-1001845835645', 'source_key'=>'tg_anytour_msk',
        'display_name'=>'AnyTour Москва', 'bot_url'=>'https://t.me/anyru_bot', 'button_text'=>'✈️ Рассчитать на мои даты', 'is_enabled'=>true]);
    [$transport,$provider] = aloftFixtureTransport($native,$mode);
    $public = (object)['html'=>$html,'reads'=>0,'hook'=>null];
    $reader = static function () use ($public): string {
        $public->reads++;
        if ($public->hook !== null) ($public->hook)($public->reads);
        return $public->html;
    };
    return [$pdo,$registry,$provider,$public,new ChannelPostAutomationService($registry,$transport,$reader)];
}
[$manualDb,$manualRegistry,$manualProvider,$manualPublic,$manualService] = aloftManualScenario($aloftHtml,$aloftNative);
$inspected = $manualService->inspectManualTelegramButton($aloftPublisher);
channelPostCheck('Aloft inspect reads publisher and public post without creating a publication',
    [$inspected['ok'],$inspected['state'],$manualProvider->writes,(int)$manualDb->query('SELECT COUNT(*) FROM post_publications')->fetchColumn()],
    [true,'inspected',0,0]);
$prepared = $manualService->prepareManualTelegramButton($aloftPublisher);
$plan = $prepared['preview'] ?? [];
channelPostCheck('Aloft service prepares the exact held preview with its own hotel URL',
    [$prepared['ok'],$plan['state']??null,$plan['reason']??null,$plan['source_id']??null,$plan['hotel_id']??null,
        $plan['plan_matches']??null,$manualProvider->writes,isset($plan['button_url']) && preg_match('~\Ahttps://t\.me/anyru_bot\?start=p_[A-Za-z0-9_-]{24}\z~D',$plan['button_url'])===1],
    [true,'requires_attention','manual_approval_required',9,61159,true,0,true]);
channelPostCheck('Aloft public preview never invents native diagnostic fields',
    array_key_exists('native_diagnostics', $plan['observation']) && $plan['observation']['native_diagnostics'] === null);
$row = $manualRegistry->manualTelegramButtonPublication();
$resolved = $manualRegistry->resolveToken('anytour','telegram','anyru_bot',$row['start_payload']);
require_once dirname(__DIR__).'/services/SourceQualificationPrompt.php';
channelPostCheck('Aloft own token enters the existing hotel qualification flow',
    SourceQualificationPrompt::publicationContext($resolved)['hotel_name'], 'Aloft Palm Jumeirah');
channelPostCheck('Aloft prepared token resolves only its original publication and hotel context',
    [$resolved['external_message_id'],$resolved['source_key'],$resolved['snapshot']['title'],$resolved['snapshot']['metadata']['hotel_catalog_id'],
        $manualRegistry->resolveToken('anytour','telegram','ANYTOURBOT_bot',$row['start_payload'])],
    ['1678','tg_anytour_msk','Aloft Palm Jumeirah',61159,null]);
$same = $manualService->prepareManualTelegramButton($aloftPublisher);
channelPostCheck('Aloft repeated preparation keeps the exact token and version',
    [$same['preview']['button_url'],$same['preview']['version_id'],(int)$manualDb->query('SELECT COUNT(*) FROM post_versions')->fetchColumn()],
    [$plan['button_url'],$plan['version_id'],1]);
$calls = count($manualProvider->calls);
$manualService->processPending('anytour','telegram','anyru_bot');
$manualService->processPublication('anytour','telegram','anyru_bot',$plan['publication_id'],$plan['version_id']);
channelPostCheck('Aloft ordinary workers cannot consume the held manual preview',
    [count($manualProvider->calls),$manualProvider->writes,$manualRegistry->manualTelegramButtonPublication()['state']],
    [$calls,0,'requires_attention']);
foreach (['digest','publication','version','credential'] as $case) {
    $binding=$aloftPublisher;$id=$plan['publication_id'];$version=$plan['version_id'];$digest=$plan['plan_digest'];
    if ($case==='digest') $digest=str_repeat('f',64);
    elseif ($case==='publication') $id++;
    elseif ($case==='version') $version++;
    else $binding['credential_version']++;
    $refused=$manualService->applyManualTelegramButton($binding,$id,$version,$digest);
    channelPostCheck('Aloft changed approval '.$case.' stops before provider and claim',
        [$refused['ok'],$refused['reason'],count($manualProvider->calls),$manualRegistry->manualTelegramButtonPublication()['attempt_count']],
        [false,'manual_preview_changed',$calls,0]);
}
$revoked=$manualService->applyManualTelegramButton($aloftPublisher,$plan['publication_id'],$plan['version_id'],$plan['plan_digest'],
    static fn():bool=>false);
channelPostCheck('Aloft authorization revoked after public read cannot consume the approved plan',
    [$revoked['reason'],$manualRegistry->manualTelegramButtonPublication()['attempt_count'],count($manualProvider->calls),$manualDb->inTransaction()],
    ['forbidden',0,$calls,false]);
$applied=$manualService->applyManualTelegramButton($aloftPublisher,$plan['publication_id'],$plan['version_id'],$plan['plan_digest']);
$saved=$manualRegistry->manualTelegramButtonPublication();
channelPostCheck('Aloft exact approval uses one durable existing edit and saves the native receipt',
    [$applied['ok'],$applied['state'],$manualProvider->writes,$saved['state'],$saved['write_attempts'],
        $saved['receipt']['verified']??false,$saved['receipt']['native_media_identity_before_verified']??null],
    [true,'sent',1,'sent',1,true,false]);
$afterCalls=count($manualProvider->calls);
$again=$manualService->applyManualTelegramButton($aloftPublisher,$plan['publication_id'],$plan['version_id'],$plan['plan_digest']);
$manualService->processPending('anytour','telegram','anyru_bot');
channelPostCheck('Aloft confirmed POST replay cannot make a second provider call',
    [$again['ok'],$manualProvider->writes,count($manualProvider->calls)], [false,1,$afterCalls]);

// Presentation reads an already stored successor. The large private sentinels
// must not enter its bounded DTO, nor can reading it repair the original state.
$diagnosticMessage = $aloftNative;
$diagnosticMessage['message_id'] = '1678';
$diagnosticMessage['chat']['id'] = '-1001845835645';
$diagnosticMessage['chat']['username'] = 'AnyTour';
$diagnosticMessage['date'] = '1786449116';
$diagnosticMessage['edit_date'] = 1786449216;
$diagnosticMessage['caption'] = str_replace("\n", "\r\n", $aloftNative['caption']);
$diagnosticMessage['text'] = null;
$diagnosticMessage['photo'] = [];
$diagnosticMessage['video']['duration'] = 28.5;
$diagnosticMessage['video']['file_id'] = 'private_native_file_'.str_repeat('f', 900);
$diagnosticMessage['video']['file_unique_id'] = 'private-native-unique:invalid';
$diagnosticMessage['private_token'] = 'private_bot_token:'.str_repeat('t', 8192);
$diagnosticMessage['reply_markup']['inline_keyboard'] = [[[
    'text'=>'✈️ Рассчитать на мои даты', 'url'=>$plan['button_url'],
    'callback_data'=>'private_callback_'.str_repeat('c', 8192),
    'private_unknown_key'=>'private_unknown_value_'.str_repeat('v', 8192),
]]];
$diagnosticReceived = $manualService->receive('telegram', ['update_id'=>123456, 'edited_channel_post'=>$diagnosticMessage], 'anytour', 'anyru_bot');
$diagnosticBefore = $manualRegistry->manualTelegramButtonPublication();
$diagnosticCalls = [count($manualProvider->calls), $manualProvider->writes, $manualPublic->reads];
$manualDb->exec('PRAGMA query_only=ON');
$diagnosticStatus = $manualService->manualTelegramButtonStatus();
$diagnosticAfter = $manualRegistry->manualTelegramButtonPublication();
$manualDb->exec('PRAGMA query_only=OFF');
$diagnostic = $diagnosticStatus['observation']['native_diagnostics'];
channelPostCheck('Aloft stored native diagnostics preserve actual public scalar types and caption bytes',
    [$diagnostic['message_id'], $diagnostic['chat_id'], $diagnostic['chat_type'], $diagnostic['chat_username'],
        $diagnostic['date'], $diagnostic['video_duration'], $diagnostic['caption'], $diagnostic['text_key_present']],
    [['present'=>true,'type'=>'string','value'=>'1678'], ['present'=>true,'type'=>'string','value'=>'-1001845835645'],
        ['present'=>true,'type'=>'string','value'=>'channel'], ['present'=>true,'type'=>'string','value'=>'AnyTour'],
        ['present'=>true,'type'=>'string','value'=>'1786449116'], ['present'=>true,'type'=>'double','value'=>28.5],
        ['present'=>true,'type'=>'string','byte_length'=>strlen($diagnosticMessage['caption']), 'sha256'=>hash('sha256',$diagnosticMessage['caption'])], true]);
$diagnosticJson = json_encode($diagnostic, JSON_THROW_ON_ERROR);
channelPostCheck('Aloft native diagnostic DTO exposes bounded field facts without credentials or callback values',
    [$diagnostic['video_file_id'], $diagnostic['video_file_unique_id'], $diagnostic['alternative_media_keys'], $diagnostic['keyboard'],
        strlen($diagnosticJson) < 4096, str_contains($diagnosticJson, 'private_'), str_contains($diagnosticJson, 'private-native')],
    [['present'=>true,'type'=>'string','byte_length'=>strlen($diagnosticMessage['video']['file_id']),'valid'=>true],
        ['present'=>true,'type'=>'string','byte_length'=>strlen($diagnosticMessage['video']['file_unique_id']),'valid'=>false], ['photo'],
        ['present'=>true,'type'=>'array','row_count'=>1,'rows_are_list'=>true,'single_row_button_count'=>1,'single_row_is_list'=>true,
            'only_button_key_count'=>4,'only_button_keys'=>['text','url','callback_data'],'other_button_key_count'=>1], true, false, false]);
channelPostCheck('Aloft native diagnostic status is query-only and preserves both immutable versions without provider reads',
    [$diagnosticReceived['reason']??'', $diagnosticBefore === $diagnosticAfter,
        [count($manualProvider->calls), $manualProvider->writes, $manualPublic->reads] === $diagnosticCalls,
        $diagnosticStatus['state'], $diagnosticStatus['write_attempts'], $diagnosticStatus['original_attempt']['version_id'],
        $diagnosticStatus['original_attempt']['state'], $diagnosticStatus['original_attempt']['write_attempts']],
    ['manual_repair_requires_review', true, true, 'requires_attention', 0, $plan['version_id'], 'sent', 1]);

foreach (['public_before_claim','public_before_write','config_before_write','native_before_write','timeout'] as $case) {
    [$db,$registry,$provider,$public,$service] = aloftManualScenario($aloftHtml,$aloftNative,$case==='timeout'?'timeout':'success');
    $preview=$service->prepareManualTelegramButton($aloftPublisher)['preview'];
    if ($case==='public_before_claim') $public->html=str_replace('Сохранить','Новая подпись: сохранить',$aloftHtml);
    else $public->hook=static function (int $read) use ($case,$public,$db,$service,$aloftNative): void {
        if ($read!==3) return;
        if ($case==='public_before_write') $public->html=str_replace('Сохранить','Новая подпись: сохранить',$public->html);
        elseif ($case==='config_before_write') $db->exec('UPDATE post_channels SET config_version=config_version+1');
        elseif ($case==='native_before_write') {
            $message=$aloftNative;$message['caption'].=' новый текст';$message['edit_date']=$message['date']+100;
            $service->receive('telegram',['update_id'=>12345,'edited_channel_post'=>$message],'anytour','anyru_bot');
        }
    };
    $outcome=$service->applyManualTelegramButton($aloftPublisher,$preview['publication_id'],$preview['version_id'],$preview['plan_digest']);
    $expectedWrites=$case==='timeout'?1:0;
    channelPostCheck('Aloft '.$case.' cannot produce false success or an unsafe edit',
        [$outcome['ok'],$provider->writes], [false,$expectedWrites]);
    $beforeCalls=count($provider->calls);
    $service->processPending('anytour','telegram','anyru_bot');
    $current=$registry->manualTelegramButtonPublication();
    $service->processPublication('anytour','telegram','anyru_bot',$current['publication_id'],$current['version_id']);
    $service->applyManualTelegramButton($aloftPublisher,$preview['publication_id'],$preview['version_id'],$preview['plan_digest']);
    channelPostCheck('Aloft '.$case.' stays fenced from all repeat writes',
        [$provider->writes,count($provider->calls)], [$expectedWrites,$beforeCalls]);
    if ($case==='timeout') channelPostCheck('Aloft lost response retains UNKNOWN and durable attempt',
        [$current['state'],$current['write_attempts']], ['unknown',1]);
}

// Execute the actual integration with provider I/O intercepted in a namespace.
// No application secrets are loaded and native curl can never contact Telegram.
foreach (['CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_RETURNTRANSFER','CURLOPT_FOLLOWLOCATION',
    'CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_CUSTOMREQUEST','CURLOPT_HTTPHEADER',
    'CURLOPT_WRITEFUNCTION','CURLOPT_POSTFIELDS','CURLINFO_HTTP_CODE','CURLINFO_TOTAL_TIME'] as $index => $constant) {
    define('HotelMediaApiFixture\\'.$constant, 20000 + $index);
}
$GLOBALS['hotel_media_http'] = [];
eval(<<<'FIXTURE'
namespace HotelMediaApiFixture;
const TELEGRAM_BOT_TOKEN = '123456:fixture_deployment_token_only';
function extension_loaded(string $name): bool { return $name === 'curl'; }
function defined(string $name): bool { return $name === 'TELEGRAM_BOT_TOKEN' || \defined($name); }
function curl_init(string $url): object { return (object)['url'=>$url,'options'=>[]]; }
function curl_setopt_array(object $handle, array $options): bool { $handle->options=$options; return true; }
function curl_exec(object $handle): bool {
    $GLOBALS['hotel_media_http'][]=['url'=>$handle->url,'options'=>$handle->options];
    if (!empty($GLOBALS['hotel_media_fixture_timeout'])) return false;
    ($handle->options[CURLOPT_WRITEFUNCTION])($handle,'{"ok":true,"result":{"message_id":862}}');
    return true;
}
function curl_getinfo(object $handle, int $option): int|float {
    if ($option === CURLINFO_TOTAL_TIME) return 15.012;
    return empty($GLOBALS['hotel_media_fixture_timeout']) ? 200 : 0;
}
function curl_errno(object $handle): int { return empty($GLOBALS['hotel_media_fixture_timeout']) ? 0 : 28; }
function curl_close(object $handle): void {}
FIXTURE);
$hotelTransportSource = (string)file_get_contents(__DIR__.'/../integrations/ChannelPostApiTransport.php');
$hotelTransportSource = preg_replace('/\A<\?php\s*declare\(strict_types=1\);\s*/', '', $hotelTransportSource, 1, $replaced);
channelPostCheck('hotel transport fixture retains the actual integration body', $replaced, 1);
$hotelTransportSource = str_replace('__DIR__', var_export(dirname(__DIR__).'/integrations', true), $hotelTransportSource);
eval('namespace HotelMediaApiFixture; use \MaxTlsConfig; '.$hotelTransportSource);

$hotelMediaBody = [
    'media'=>['type'=>'photo','media'=>'AgACAgIAAxkBAAIB_fixture_photo_1',
        'caption'=>"✈️ Туры в проверенный отель\nСохранённая фотография.",'caption_entities'=>[]],
    'reply_markup'=>['inline_keyboard'=>[[['text'=>'✈️ Рассчитать на мои даты',
        'url'=>'https://t.me/anyru_bot?start=p_'.str_repeat('A',24)]]]],
];
foreach (['862','328','1083','288'] as $mid) {
    channelPostCheck('hotel media builds exact fixed target '.$mid,
        HotelMediaApiFixture\ChannelPostApiTransport::hotelMediaPayload('-1001845835645',$mid,$hotelMediaBody),
        ['chat_id'=>'-1001845835645','message_id'=>(int)$mid]+$hotelMediaBody);
}
channelPostCheck('hotel media pure preparation performs no HTTP', $GLOBALS['hotel_media_http'], []);
$hotelMediaResult = HotelMediaApiFixture\ChannelPostApiTransport::hotelMediaRequest('-1001845835645','862',$hotelMediaBody);
$hotelWire = $GLOBALS['hotel_media_http'][0];
channelPostCheck('hotel media performs exactly one deployment-bot editMessageMedia',
    [$hotelMediaResult['http'],$hotelMediaResult['transport_error'],count($GLOBALS['hotel_media_http']),$hotelWire['url'],
        json_decode($hotelWire['options'][HotelMediaApiFixture\CURLOPT_POSTFIELDS],true)],
    [200,false,1,'https://api.telegram.org/bot'.HotelMediaApiFixture\TELEGRAM_BOT_TOKEN.'/editMessageMedia',
        ['chat_id'=>'-1001845835645','message_id'=>862]+$hotelMediaBody]);
channelPostCheck('hotel media preserves strict TLS, no redirects and original deadlines',
    array_map(static fn($key)=>$hotelWire['options'][$key], [
        HotelMediaApiFixture\CURLOPT_SSL_VERIFYPEER,HotelMediaApiFixture\CURLOPT_SSL_VERIFYHOST,
        HotelMediaApiFixture\CURLOPT_FOLLOWLOCATION,HotelMediaApiFixture\CURLOPT_CONNECTTIMEOUT,
        HotelMediaApiFixture\CURLOPT_TIMEOUT,HotelMediaApiFixture\CURLOPT_CUSTOMREQUEST]),
    [true,2,false,2,15,'POST']);
$GLOBALS['hotel_media_fixture_timeout'] = true;
$attemptCount = count($GLOBALS['hotel_media_http']);
$timeoutResult = HotelMediaApiFixture\ChannelPostApiTransport::hotelMediaRequest('-1001845835645','862',$hotelMediaBody);
channelPostCheck('uncertain hotel media edit saves numeric transport facts after exactly one attempt',
    [$timeoutResult, count($GLOBALS['hotel_media_http']) - $attemptCount],
    [['http'=>0, 'data'=>null, 'transport_error'=>true, 'curl_errno'=>28, 'elapsed_ms'=>15012], 1]);
$ordinary = HotelMediaApiFixture\ChannelPostApiTransport::buttonRequest('telegram','POST','getMe',[],[]);
$ordinaryWire = end($GLOBALS['hotel_media_http']);
channelPostCheck('ordinary Telegram calls retain their time budget and response shape',
    [$ordinaryWire['options'][HotelMediaApiFixture\CURLOPT_CONNECTTIMEOUT], $ordinaryWire['options'][HotelMediaApiFixture\CURLOPT_TIMEOUT], $ordinary],
    [2,6,['http'=>0, 'data'=>null, 'transport_error'=>true]]);
unset($GLOBALS['hotel_media_fixture_timeout']);

$hotelMediaDeny = static function(string $case, array $body, string $channel='-1001845835645', string $mid='862'): void {
    $before=count($GLOBALS['hotel_media_http']);
    channelPostCheck('hotel media rejects '.$case.' before HTTP',
        [HotelMediaApiFixture\ChannelPostApiTransport::hotelMediaPayload($channel,$mid,$body),
            HotelMediaApiFixture\ChannelPostApiTransport::hotelMediaRequest($channel,$mid,$body),
            count($GLOBALS['hotel_media_http'])],
        [null,['http'=>0,'data'=>null,'transport_error'=>true],$before]);
};
foreach (['1678','1207','455','0862',"862\n",'-862'] as $mid) $hotelMediaDeny('target '.$mid,$hotelMediaBody,'-1001845835645',$mid);
$hotelMediaDeny('source channel',$hotelMediaBody,'-1001234567890');
$hotelMediaDeny('MAX channel',$hotelMediaBody,'-68765999669267');
foreach (['video','document','animation'] as $type) {
    $bad=$hotelMediaBody;$bad['media']['type']=$type;$hotelMediaDeny('media type '.$type,$bad);
}
foreach (['','https://example.test/photo.jpg','http://example.test/photo.jpg','attach://photo',
    'file:///tmp/photo.jpg','/tmp/photo.jpg','file id',"file\0id",str_repeat('A',1025)] as $i=>$file) {
    $bad=$hotelMediaBody;$bad['media']['media']=$file;$hotelMediaDeny('non-reusable file '.$i,$bad);
}
foreach ([''," \n",'caption'."\0",'caption'."\t",'caption'."\r",'caption'."\u{0085}",
    'caption'."\xC3\x28",str_repeat('A',1025),str_repeat('😀',513)] as $i=>$caption) {
    $bad=$hotelMediaBody;$bad['media']['caption']=$caption;$hotelMediaDeny('invalid caption '.$i,$bad);
}
foreach ([str_repeat('Я',1024),str_repeat('😀',512)] as $caption) {
    $boundary=$hotelMediaBody;$boundary['media']['caption']=$caption;
    channelPostCheck('hotel caption accepts exactly 1024 UTF16 units',
        HotelMediaApiFixture\ChannelPostApiTransport::hotelMediaPayload('-1001845835645','862',$boundary),
        ['chat_id'=>'-1001845835645','message_id'=>862]+$boundary);
}
foreach ([null,'[]',[['type'=>'bold','offset'=>0,'length'=>1]]] as $i=>$entities) {
    $bad=$hotelMediaBody;$bad['media']['caption_entities']=$entities;$hotelMediaDeny('caption entities '.$i,$bad);
}
foreach ([
    'https://t.me/ANYTOURBOT_bot?start=p_'.str_repeat('A',24),
    'https://t.me.evil.test/anyru_bot?start=p_'.str_repeat('A',24),
    'http://t.me/anyru_bot?start=p_'.str_repeat('A',24),
    'https://t.me/anyru_bot?start=p_'.str_repeat('A',23),
    'https://t.me/anyru_bot?start=p_'.str_repeat('A',25),
    'https://t.me/anyru_bot?start=p_'.str_repeat('A',24).'&extra=1',
] as $i=>$url) {
    $bad=$hotelMediaBody;$bad['reply_markup']['inline_keyboard'][0][0]['url']=$url;$hotelMediaDeny('noncanonical bot URL '.$i,$bad);
}
foreach (['',' ',str_repeat('A',129),"button\ntext","button\u{0085}","\xC3\x28"] as $i=>$text) {
    $bad=$hotelMediaBody;$bad['reply_markup']['inline_keyboard'][0][0]['text']=$text;$hotelMediaDeny('invalid button text '.$i,$bad);
}
foreach (['body','media','markup','button','row','keyboard','album','media_shape','markup_shape','button_shape'] as $case) {
    $bad=$hotelMediaBody;
    if ($case==='body') $bad['chat_id']='-1001845835645';
    elseif ($case==='media') $bad['media']['parse_mode']='HTML';
    elseif ($case==='markup') $bad['reply_markup']['resize_keyboard']=true;
    elseif ($case==='button') $bad['reply_markup']['inline_keyboard'][0][0]['callback_data']='other';
    elseif ($case==='row') $bad['reply_markup']['inline_keyboard'][0][]=$bad['reply_markup']['inline_keyboard'][0][0];
    elseif ($case==='keyboard') $bad['reply_markup']['inline_keyboard'][]=$bad['reply_markup']['inline_keyboard'][0];
    elseif ($case==='album') $bad['media']=[$bad['media'],$bad['media']];
    elseif ($case==='media_shape') $bad['media']='photo';
    elseif ($case==='markup_shape') $bad['reply_markup']='inline_keyboard';
    else $bad['reply_markup']['inline_keyboard'][0][0]='button';
    $hotelMediaDeny('extra or malformed '.$case,$bad);
}

$hotelDenied=['http'=>0,'data'=>null,'transport_error'=>true];
$hotelFullBody=['chat_id'=>'-1001845835645','message_id'=>862]+$hotelMediaBody;
$publisherFixtureToken='654321:'.str_repeat('B',24);
$before=count($GLOBALS['hotel_media_http']);
channelPostCheck('generic button transport does not acquire a media route',
    HotelMediaApiFixture\ChannelPostApiTransport::buttonRequest('telegram','POST','editMessageMedia',[],$hotelFullBody),$hotelDenied);
channelPostCheck('caption transport still excludes replacement media',
    HotelMediaApiFixture\ChannelPostApiTransport::captionRequest('telegram','-1001845835645','862',$hotelMediaBody),$hotelDenied);
channelPostCheck('saved publisher cannot use the media route',
    HotelMediaApiFixture\ChannelPostApiTransport::publisherButtonRequest($publisherFixtureToken,'POST','editMessageMedia',[],$hotelFullBody),$hotelDenied);
$publisherKeyboard=['chat_id'=>'-1001845835645','message_id'=>862,'reply_markup'=>$hotelMediaBody['reply_markup']];
channelPostCheck('saved publisher keyboard remains limited to Aloft 1678',
    HotelMediaApiFixture\ChannelPostApiTransport::publisherButtonRequest($publisherFixtureToken,'POST','editMessageReplyMarkup',[],$publisherKeyboard),$hotelDenied);
channelPostCheck('existing transport boundaries reject before all HTTP',count($GLOBALS['hotel_media_http']),$before);
$captionOnly=['caption'=>$hotelMediaBody['media']['caption'],'caption_entities'=>[],'reply_markup'=>$hotelMediaBody['reply_markup']];
HotelMediaApiFixture\ChannelPostApiTransport::captionRequest('telegram','-1001845835645','862',$captionOnly);
channelPostCheck('existing caption route keeps its exact method and body',
    [count($GLOBALS['hotel_media_http']),$GLOBALS['hotel_media_http'][$before]['url'],
        json_decode($GLOBALS['hotel_media_http'][$before]['options'][HotelMediaApiFixture\CURLOPT_POSTFIELDS],true)],
    [$before+1,'https://api.telegram.org/bot'.HotelMediaApiFixture\TELEGRAM_BOT_TOKEN.'/editMessageCaption',
        ['chat_id'=>'-1001845835645','message_id'=>862]+$captionOnly]);
$publisherKeyboard['message_id']=1678;
HotelMediaApiFixture\ChannelPostApiTransport::publisherButtonRequest($publisherFixtureToken,'POST','editMessageReplyMarkup',[],$publisherKeyboard);
channelPostCheck('existing publisher route still edits only the accepted keyboard with its saved credential',
    [count($GLOBALS['hotel_media_http']),$GLOBALS['hotel_media_http'][$before+1]['url'],
        json_decode($GLOBALS['hotel_media_http'][$before+1]['options'][HotelMediaApiFixture\CURLOPT_POSTFIELDS],true)],
    [$before+2,'https://api.telegram.org/bot'.$publisherFixtureToken.'/editMessageReplyMarkup',$publisherKeyboard]);

// Exercise the real editor's durable once-only flow; only its internal effects
// are fixtures. Requiring the CLI file does not bootstrap or invoke its runner.
require_once __DIR__.'/../tools/anytour_existing_post_batch.php';
$nextTransferRaw = file_get_contents(__DIR__.'/../'.AnyTour\ExistingPostBatch\NEXT_TRANSFER_PLAN_FILE);
$nextTransfer = AnyTour\ExistingPostBatch\transferPlanFromBytes($nextTransferRaw, '30f93518da51279979f7669538f7c09f6096733e9423f755dc21981af821b98d', true);
channelPostCheck('next fixed hotel transfer carries no old price and preserves exact destinations',
    [array_column($nextTransfer['rows'], 'target_message_id'), array_column($nextTransfer['rows'], 'price_record_id'),
        $nextTransfer['monitor_source_url']],
    [['1588','997','1101'], [null,null,null], null]);
channelPostCheck('new public post scope is only available to the next fixed transfer',
    [AnyTour\ExistingPostBatch\transferPublicAllowed('anytour', '1588'),
        AnyTour\ExistingPostBatch\transferPublicAllowed('anytour', '1588', true),
        AnyTour\ExistingPostBatch\transferPublicAllowed('anytour', '862', true)], [false,true,false]);
$remainingTransfer = AnyTour\ExistingPostBatch\selectRemainingTransferRows($nextTransfer);
channelPostCheck('continuation selects only the two untouched posts',
    [array_column($remainingTransfer['rows'],'hotel_id'),array_column($remainingTransfer['rows'],'target_message_id')],
    [[408,466],['997','1101']]);
$rankedDigest = AnyTour\ExistingPostBatch\RANKED_ORIGINAL_PLAN;
$rankedRaw = file_get_contents(__DIR__.'/../config/anytour_ranked_46_20261123_01.json');
$rankedPlan = AnyTour\ExistingPostBatch\transferPlanFromBytes($rankedRaw, $rankedDigest, $rankedDigest);
$rankedTail = AnyTour\ExistingPostBatch\selectRankedContinuationRows($rankedPlan, $rankedDigest);
$rankedRow = $rankedTail['rows'][0];
foreach (['occupied'=>[0,false],'occupied string'=>['0',false],'acquired'=>[1,true],'acquired string'=>['1',true],
    'NULL'=>[null,'transfer_target_lock_unconfirmed'],'SQL failure'=>[false,'transfer_target_lock_unconfirmed'],
    'unexpected result'=>[2,'transfer_target_lock_unconfirmed']] as $case=>[$reply,$expected]) {
    try { $observed=AnyTour\ExistingPostBatch\transferTargetLockAcquired($reply); }
    catch (RuntimeException $e) { $observed=$e->getMessage(); }
    channelPostCheck('ranked destination lock '.$case,$observed,$expected);
}
$targetDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$targetDb->sqliteCreateFunction('JSON_UNQUOTE',static fn($value)=>$value);
$targetDb->exec('CREATE TABLE post_publications (id INTEGER, post_channel_id INTEGER, external_message_id TEXT,current_version_id INTEGER)');
$targetDb->exec('CREATE TABLE post_versions (id INTEGER, publication_id INTEGER,snapshot_json TEXT)');
foreach (['empty'=>null,'same hotel'=>'transfer_hotel_already_in_large_channel','same post'=>'transfer_target_identity_exists'] as $case=>$expected) {
    if ($case==='same hotel') {
        $targetDb->exec("INSERT INTO post_publications VALUES(1,6,'99999',1)");
        $targetDb->prepare('INSERT INTO post_versions VALUES(1,1,?)')->execute([json_encode(['metadata'=>['hotel_catalog_id'=>(string)$rankedRow['hotel_id']]])]);
    } elseif ($case==='same post') {
        $targetDb->prepare('INSERT INTO post_publications VALUES(2,6,?,NULL)')->execute([$rankedRow['target_message_id']]);
    }
    $reason=null;
    try { AnyTour\ExistingPostBatch\transferNoTarget($targetDb,$rankedRow); }
    catch (RuntimeException $e) { $reason=$e->getMessage(); }
    channelPostCheck('ranked destination inventory '.$case,$reason,$expected);
}
$sameSource = ['project_key'=>'anytour','platform'=>'telegram','bot_key'=>'anyru_bot','channel_id'=>'-1002262666616',
    'channel'=>['id'=>10],'message_id'=>$rankedRow['source_message_id'],'state'=>'sent',
    'publication_id'=>$rankedRow['source_publication_id'],'version_id'=>$rankedRow['source_version_id'] + 1,
    'snapshot'=>['metadata'=>['hotel_catalog_id'=>$rankedRow['hotel_id']]]];
foreach (['newer'=>[[],true],'unchanged'=>[['version_id'=>$rankedRow['source_version_id']],false],
    'wrong hotel'=>[['snapshot'=>[]],false],'wrong source'=>[['publication_id'=>0],false],
    'wrong channel'=>[['channel_id'=>'another'],false],'missing version'=>[['version_id'=>null],false]] as $case=>[$change,$expected]) {
    channelPostCheck('independent source hold: '.$case,
        AnyTour\ExistingPostBatch\transferSourceRevisionAdvanced(array_replace($sameSource, $change), $rankedRow), $expected);
}
$runContext = ['native_run_id'=>'12345','application_sha'=>str_repeat('a',40),
    'continuation_of_ranked_run_id'=>AnyTour\ExistingPostBatch\RANKED_ORIGINAL_RUN];
$lostResult = ['hotel_id'=>$rankedRow['hotel_id'],'message_id'=>$rankedRow['target_message_id'],
    'ok'=>false,'state'=>'response_saved_completion_requires_readback','reason'=>'hotel_transfer_response_unconfirmed',
    'provider_write_attempts'=>1,'reserved_publication_id'=>40];
$lostRequest = ['chat_id'=>'-1001845835645','message_id'=>(int)$rankedRow['target_message_id']];
$evidence = [
    ['state'=>'intent_saved','operation'=>AnyTour\ExistingPostBatch\transferProfileOperation($rankedDigest),
        'row'=>$rankedRow,'execution'=>$runContext,'plan_sha256'=>$rankedDigest],
    ['state'=>'identity_reserved','plan_sha256'=>$rankedDigest,'reservation'=>['publication_id'=>40]],
    ['state'=>'request_prepared','request'=>$lostRequest,'request_sha256'=>hash('sha256',json_encode($lostRequest))],
    ['state'=>'provider_edit_started','provider_write_attempts'=>1],
    ['state'=>'provider_response_saved','response'=>['http'=>0,'data'=>null,'transport_error'=>true]],
    $lostResult,
];
foreach (['complete'=>[null,[],true],'wrong run'=>[0,['execution'=>[]],false],
    'wrong target'=>[0,['row'=>[]],false],'wrong plan'=>[0,['plan_sha256'=>'foreign'],false],
    'missing request digest'=>[2,['request_sha256'=>''],false], 'two requests'=>[3,['provider_write_attempts'=>2],false],
    'API отказ'=>[4,['response'=>['http'=>403,'data'=>null,'transport_error'=>false]],false],
    'changed terminal'=>[5,['reserved_publication_id'=>77],false],'missing terminal'=>[5,null,false]] as $case=>[$index,$change,$expected]) {
    $rows = $evidence;
    if ($index !== null && $change === null) array_pop($rows);
    elseif ($index !== null) $rows[$index] = array_replace($rows[$index],$change);
    $receipt = tmpfile();
    foreach ($rows as $event) AnyTour\ExistingPostBatch\transferJournal($receipt,$event);
    channelPostCheck('durable isolated transport failure: '.$case,
        AnyTour\ExistingPostBatch\transferTransportLossRecorded($receipt,$rankedRow,$rankedDigest,$runContext,$lostResult), $expected);
    foreach ([true,false] as $healthy) {
        $pings = 0;
        $effects = ['context'=>$runContext,'recheck_after_transport_loss'=>static function () use (&$pings,$healthy): void {
            ++$pings;
            if (!$healthy) throw new RuntimeException('transfer_native_rights_unconfirmed');
        }];
        $observed = AnyTour\ExistingPostBatch\transferCheckSharedAccess($receipt,$rankedRow,$rankedDigest,$effects,$lostResult);
        channelPostCheck('shared check after '.$case.' '.($healthy ? 'healthy' : 'unavailable'),
            [$pings,$observed['shared_access_rechecked'] ?? false,$observed['shared_access_error'] ?? null,$observed['ok']],
            [$expected ? 1 : 0,$expected && $healthy,$expected && !$healthy ? 'transfer_native_rights_unconfirmed' : null,false]);
    }
    fclose($receipt);
}
channelPostCheck('no-price HOTEL input excludes the previous monitor quote with a valid caption digest',
    [AnyTour\ExistingPostBatch\transferCaptionOmitsMonitorPrice("Пляж\n💰 От 108 689 ₽ за двоих"),
     AnyTour\ExistingPostBatch\transferCaptionOmitsMonitorPrice("Цена найдена мониторингом 19.11.2026, 09:18 МСК."),
     AnyTour\ExistingPostBatch\transferCaptionOmitsMonitorPrice('В ресторане H2O ужинают под водой.')], [false,false,true]);
$pricePath = tempnam(sys_get_temp_dir(), 'hotel-price-lease-');
$owner = fopen($pricePath, 'rb'); $worker = fopen($pricePath, 'rb');
try {
    flock($owner, LOCK_SH);
    channelPostCheck('data plan waits for the established PRICE owner without taking its lock',
        AnyTour\ExistingPostBatch\acquireRankedPriceLease($worker,$pricePath,10),false);
    flock($owner, LOCK_UN);
    channelPostCheck('data plan acquires the same free inode before any row',
        AnyTour\ExistingPostBatch\acquireRankedPriceLease($worker,$pricePath,10),true);
    flock($worker,LOCK_UN);
    rename($pricePath,$pricePath.'.old'); touch($pricePath);
    try {AnyTour\ExistingPostBatch\acquireRankedPriceLease($worker,$pricePath,10); $reason = 'allowed';}
    catch (RuntimeException $e) {$reason=$e->getMessage();}
    channelPostCheck('an exchanged PRICE inode never grants a write',$reason,'transfer_price_lease_unknown');
} finally {
    fclose($worker); fclose($owner); unlink($pricePath);
    if (is_file($pricePath.'.old')) unlink($pricePath.'.old');
}
// A later HOTEL plan can isolate one journaled timeout only against its own installed receipt.
$indexPath = __DIR__.'/../config/anytour_ranked_index_45387052894.json';
$newIndex = json_decode(file_get_contents($indexPath), true, 16, JSON_THROW_ON_ERROR);
$newDigest = $newIndex['plans'][0]['sha256'];
$newBytes = file_get_contents(__DIR__.'/../'.$newIndex['plans'][0]['path']);
$newPlan = AnyTour\ExistingPostBatch\transferPlanFromBytes($newBytes, $newDigest, $newDigest);
$newRow = $newPlan['rows'][0];
$newContext = ['native_run_id'=>'12345', 'application_sha'=>str_repeat('a',40), 'approved_index_sha256'=>hash_file('sha256',$indexPath)];
$newResult = array_replace($lostResult, ['hotel_id'=>$newRow['hotel_id'], 'message_id'=>$newRow['target_message_id']]);
$newRequest = array_replace($lostRequest, ['message_id'=>(int)$newRow['target_message_id']]);
$events = $evidence;
$events[0] = array_replace($events[0], ['execution'=>$newContext, 'operation'=>$newPlan['operation'], 'row'=>$newRow, 'plan_sha256'=>$newDigest]);
$events[1]['plan_sha256'] = $newDigest;
$events[2] = array_replace($events[2], ['request'=>$newRequest, 'request_sha256'=>hash('sha256', json_encode($newRequest))]);
$events[5] = $newResult;
$file = tmpfile();
foreach ($events as $event) AnyTour\ExistingPostBatch\transferJournal($file, $event);
foreach (['release-approved'=>$newContext, 'foreign approval'=>array_replace($newContext,['approved_index_sha256'=>str_repeat('b',64)]),
    'missing run'=>array_diff_key($newContext,['native_run_id'=>true])] as $case=>$context) {
    channelPostCheck('data plan journal '.$case, AnyTour\ExistingPostBatch\transferTransportLossRecorded(
        $file,$newRow,$newDigest,$context,$newResult), $case==='release-approved');
}
fclose($file);
$existingDir = sys_get_temp_dir().'/ranked-previous-operation-'.bin2hex(random_bytes(10));
mkdir($existingDir,0700);
try {
    $cases=['authentic uncertain'=>null,'prior success'=>'success','false success'=>'false_success',
        'foreign run'=>'foreign_run','foreign app'=>'foreign_app','broken final'=>'truncated',
        'request changed'=>'changed_request','unconfirmed provider reply'=>'foreign_response',
        'symlink'=>'link','missing journal'=>'missing'];
    foreach ($cases as $case=>$change) {
        $events=$evidence;
        if ($change==='success' || $change==='false_success') {
            $events[5]=array_replace($lostResult,['state'=>'native_and_registry_verified','ok'=>true,'publication_id'=>40,
                'hotel_id_verified'=>true,'original_message_id_and_date_preserved'=>true,'photo_caption_button_verified'=>true]);
            if ($change==='success') $events[4]['response']=['http'=>200,'transport_error'=>false,
                'data'=>['ok'=>true,'result'=>['message_id'=>(int)$rankedRow['target_message_id']]]];
        } elseif ($change==='truncated') array_pop($events);
        elseif ($change==='changed_request') $events[2]['request']['message_id']=0;
        elseif ($change==='foreign_response') $events[4]['response']['http']=401;
        $path=$existingDir.'/receipt.jsonl';
        $fixture=$change==='link' ? $existingDir.'/other.jsonl' : $path;
        if ($change!=='missing') {
            $journal=fopen($fixture,'x+b');
            foreach ($events as $event) AnyTour\ExistingPostBatch\transferJournal($journal,$event);
            fclose($journal);
            if ($change==='link') symlink($fixture,$path);
        }
        try {
            $prior=AnyTour\ExistingPostBatch\transferCurrentRunJournal($existingDir,$rankedRow,$rankedDigest,
                $change==='foreign_run' ? '12346' : $runContext['native_run_id'],
                $change==='foreign_app' ? str_repeat('b',40) : $runContext['application_sha']);
            $observed=[$prior['reason'],$prior['prior_terminal_state'],$prior['prior_journal_sha256']===hash_file('sha256',$path)];
        } catch (Throwable $error) { $observed=$error->getMessage(); }
        $expected=$change===null || $change==='success' ? ['transfer_current_run_journal_requires_readback',
            $change===null ? 'response_saved_completion_requires_readback' : 'native_and_registry_verified',true] : 'transfer_previous_operation_do_not_replay';
        channelPostCheck('existing per-target operation: '.$case,$observed,$expected);
        if (is_link($path) || file_exists($path)) unlink($path);
        if ($change==='link') unlink($fixture);
    }
} finally { rmdir($existingDir); }
// Synthetic closed predecessor on real files; never a live provider receipt.
$resumeDir=sys_get_temp_dir().'/ranked-cross-run-'.bin2hex(random_bytes(10));mkdir($resumeDir,0700);
try {
    foreach (['unknown','success','changed_run','changed_app','partial','active','failed_readback','missing_version'] as $case) {
        $events=$evidence;
        if (in_array($case,['success','failed_readback','missing_version'],true)) {
            $events[4]['response']=['http'=>200,'transport_error'=>false,'data'=>['ok'=>true,'result'=>['message_id'=>(int)$rankedRow['target_message_id']]]];
            $events[5]=array_replace($lostResult,['state'=>'native_and_registry_verified','ok'=>true,'publication_id'=>40,'version_id'=>41,
                'hotel_id_verified'=>true,'original_message_id_and_date_preserved'=>true,'photo_caption_button_verified'=>true]);
        }
        if($case==='missing_version')unset($events[5]['version_id']);
        if($case==='partial')array_pop($events);
        $path=$resumeDir.'/receipt.jsonl';$h=fopen($path,'x+b');
        foreach($events as $event)AnyTour\ExistingPostBatch\transferJournal($h,$event);
        if($case==='active')flock($h,LOCK_EX|LOCK_NB);else fclose($h);
        $origin=['native_run_id'=>$case==='changed_run'?'999999':$runContext['native_run_id'],
            'application_sha'=>$case==='changed_app'?str_repeat('b',40):$runContext['application_sha']];
        $reads=0;$before=hash_file('sha256',$path);
        try {
            $prior=AnyTour\ExistingPostBatch\transferResumePrior($resumeDir,$rankedRow,$rankedDigest,$origin,
                static function(array $original)use(&$reads,$case):void{++$reads;if($original['prior_publication_id']!==40||$original['prior_version_id']!==41)throw new RuntimeException('original_identity_changed');if($case==='failed_readback')throw new RuntimeException('transfer_resume_route_unconfirmed');});
            $observed=[$prior['reason'],$prior['prior_terminal_state'],$prior['prior_public_readback_verified'],$reads];
        }catch(Throwable $e){$observed=$e->getMessage();}
        $expected=in_array($case,['success','failed_readback'],true)
            ?['transfer_previous_run_journal_requires_readback','native_and_registry_verified',$case==='success',1]
            :($case==='unknown'?['transfer_previous_run_journal_requires_readback','response_saved_completion_requires_readback',false,0]
            :($case==='missing_version'?'transfer_resume_prior_identity_invalid':'transfer_previous_operation_do_not_replay'));
        channelPostCheck('cross-run exact closed predecessor: '.$case,$observed,$expected);
        channelPostCheck('cross-run original journal unchanged: '.$case,hash_file('sha256',$path),$before);
        if($case==='active')fclose($h);unlink($path);
    }
}finally{rmdir($resumeDir);}

// An explicit zero-write continuation cannot select another plan or reuse the old execution identity.
foreach ([['wrong_plan',str_repeat('d',64),'999999',str_repeat('b',40),false],
    ['old_run','c71a7e945c0978910267ecb729dd25449a45b66e5fa4874c669c7592fae27aab','45349080956',str_repeat('b',40),false],
    ['old_application','c71a7e945c0978910267ecb729dd25449a45b66e5fa4874c669c7592fae27aab','999999','8d723f1c08f094fd845c7c34da29607fc294ff46',false],
    ['ranked_resume','c71a7e945c0978910267ecb729dd25449a45b66e5fa4874c669c7592fae27aab','999999',str_repeat('b',40),true]] as [$case,$digest,$run,$app,$ranked]) {
    $reason=null;
    try { AnyTour\ExistingPostBatch\applyTransferPlan(__DIR__,$digest,$app,$run,$digest,false,$ranked,false,true); }
    catch (RuntimeException $e) { $reason=$e->getMessage(); }
    channelPostCheck('zero-write continuation rejects before runtime: '.$case,$reason,'transfer_closed_prewrite_scope_invalid');
}

channelPostCheck('ranked continuation selects exactly 37 untouched original rows',
    [$rankedTail['operation'], count($rankedTail['rows']), $rankedTail['rows'],
        array_intersect(['224','249','266'], array_column($rankedTail['rows'], 'target_message_id'))],
    [AnyTour\ExistingPostBatch\RANKED_CONTINUATION_OPERATION, 37, array_slice($rankedPlan['rows'], 3), []]);
$invalidRankedPlan = $rankedPlan;
[$invalidRankedPlan['rows'][1], $invalidRankedPlan['rows'][2]] = [$rankedPlan['rows'][2], $rankedPlan['rows'][1]];
foreach ([[$invalidRankedPlan, $rankedDigest], [$rankedPlan, str_repeat('0',64)]] as [$candidate, $digest]) {
    $reason = null;
    try { AnyTour\ExistingPostBatch\selectRankedContinuationRows($candidate, $digest); }
    catch (RuntimeException $e) { $reason = $e->getMessage(); }
    channelPostCheck('ranked continuation refuses changed prefix or plan digest', $reason, 'transfer_ranked_continuation_scope_invalid');
}
$rejectedTransfer = ['http'=>400,'transport_error'=>false,
    'data'=>['ok'=>false,'error_code'=>400,'description'=>'Bad Request: MESSAGE_ID_INVALID']];
$firstTransferEvents = [
    ['state'=>'intent_saved','operation'=>AnyTour\ExistingPostBatch\NEXT_TRANSFER_OPERATION,
        'row'=>$nextTransfer['rows'][0],'plan_sha256'=>hash('sha256',$nextTransferRaw),
        'execution'=>['native_run_id'=>AnyTour\ExistingPostBatch\ORIGINAL_NEXT_TRANSFER_RUN,
            'application_sha'=>AnyTour\ExistingPostBatch\ORIGINAL_NEXT_TRANSFER_APP]],
    ['state'=>'identity_reserved'],['state'=>'request_prepared'],
    ['state'=>'provider_edit_started','provider_write_attempts'=>1],
    ['state'=>'provider_response_saved','response'=>$rejectedTransfer],
    ['state'=>'response_saved_completion_requires_readback','provider_write_attempts'=>1],
];
AnyTour\ExistingPostBatch\requireOriginalNextTransferRejection($firstTransferEvents,$nextTransfer['rows'][0],hash('sha256',$nextTransferRaw));
$firstTransferEvents[4]['response']['transport_error'] = true;
try {
    AnyTour\ExistingPostBatch\requireOriginalNextTransferRejection($firstTransferEvents,$nextTransfer['rows'][0],hash('sha256',$nextTransferRaw));
    channelPostCheck('uncertain Telegram response stops the continuation', false);
} catch (RuntimeException $error) {
    channelPostCheck('uncertain Telegram response stops the continuation', $error->getMessage(), 'transfer_original_rejection_unconfirmed');
}
$stellaPlanRaw = file_get_contents(__DIR__.'/../config/anytour_stella_alternate_20261123.json');
$stellaDigest = 'c71a7e945c0978910267ecb729dd25449a45b66e5fa4874c669c7592fae27aab';
$stellaAlternate = AnyTour\ExistingPostBatch\transferPlanFromBytes($stellaPlanRaw, $stellaDigest, $stellaDigest);
channelPostCheck('alternate Stella plan preserves the approved source, photo, and caption',
    $stellaAlternate['rows'], [array_replace($nextTransfer['rows'][2], [
        'target_message_id'=>'925', 'target_published_unixtime'=>1731241439,
        'target_caption_sha256'=>'f73587cf7dcc1fafa3c303bd8948dbafbdf3fc8ae07992acf5472e8f0d6a220e',
        'target_photo_marker_sha256'=>'00581a7eaa9f78288dfc78567412b44ab4cd8b12ba195548a2cb3272f5a6573e',
    ])]);
channelPostCheck('alternate Stella plan only allows the new target',
    [AnyTour\ExistingPostBatch\transferPublicAllowed('anytour', '925', $stellaDigest),
        AnyTour\ExistingPostBatch\transferPublicAllowed('anytour', '1101', $stellaDigest),
        AnyTour\ExistingPostBatch\transferPublicAllowed('anytour', '997', $stellaDigest)], [true,false,false]);
// Load the same tracked dependency path as applyTransferPlan, without its runtime
// bootstrap. This synthetic saved export is test data, never native evidence.
require_once __DIR__.'/../tools/direct_demand/operations/monitor-price-hook-contract.php';
$transferPriceNow = strtotime('2026-11-23T08:00:00Z');
$transferPriceRecord = hash('sha256','synthetic hotel transfer price');
$transferPriceTemplate = "✈️ Туры в Тестовый отель\n📍 Турция · Анталья\n\nУзнать стоимость тура\n\n"
    ."💰 [Минимальная актуальная цена тура — подтвердить перед выпуском]\n"
    .'[Город вылета · даты · ночи · состав туристов · номер · питание · время проверки]';
$transferPriceCaption = "✈️ Туры в Тестовый отель\n📍 Турция · Анталья\n\nУзнать стоимость тура\n\n"
    ."💰 От 87 006 ₽ за двоих\n✈️ Вылет из Москвы 26.11.2026 · 5 ночей\n👥 2 взрослых\n"
    ."🛏 standard room\n🍽 Всё включено\n"
    .'Цена найдена мониторингом 23.11.2026, 10:00 МСК. Стоимость и наличие могут измениться.';
$transferPriceRow = ['hotel_id'=>17595,'price_record_id'=>$transferPriceRecord,
    'price_template'=>$transferPriceTemplate,'next_caption'=>$transferPriceCaption,
    'next_caption_sha256'=>hash('sha256',$transferPriceCaption)];
$transferPricePlan = ['monitor_source_url'=>AnyTour\ExistingPostBatch\TRANSFER_MONITOR_URL];
$transferPriceExport = ['ok'=>true,'format'=>'anytour-hotel-monitor-prices-v1',
    'generatedAt'=>gmdate(DATE_ATOM,$transferPriceNow),'identityNamespace'=>'tourvisor',
    'source'=>'scheduled_monitor','observationWindowHours'=>36,
    'timeBasis'=>'database-relative; stored observation timezone is not asserted',
    'supplierRequests'=>0,'writes'=>0,
    'scope'=>['tourvisorHotelIds'=>[17595,358,393,1260],'departureId'=>1,'adults'=>2,
        'childrenCount'=>0,'currency'=>'RUB','nightsFrom'=>5,'nightsTo'=>14,
        'dateFrom'=>'2026-11-24','dateTo'=>'2027-10-31'],
    'items'=>[['tourvisorHotelId'=>17595,'state'=>'observed','offers'=>[[
        'tourvisorHotelId'=>17595,'tourId'=>'synthetic-transfer-tour','recordId'=>$transferPriceRecord,
        'departureId'=>1,'departureDate'=>'2026-11-26','nights'=>5,'adults'=>2,'childrenCount'=>0,
        'price'=>87006,'currency'=>'RUB','roomType'=>'standard room','mealId'=>7,
        'source'=>'scheduled_monitor','priceKind'=>'listing_observation',
        'currentPriceVerified'=>false,'fullPackagePriceVerified'=>false,'availabilityVerified'=>false,
        'fuelIncluded'=>null,'ageSecondsAtExport'=>3600,'expiresAt'=>gmdate(DATE_ATOM,$transferPriceNow+900),
    ]]]]];
$transferPrice = AnyTour\ExistingPostBatch\transferPrice($transferPricePlan,$transferPriceRow,$transferPriceExport,$transferPriceNow);
channelPostCheck('real hotel editor loads its deployed pure contracts and reproduces the approved caption',
    [$transferPrice['record_id'],$transferPrice['price'],$transferPrice['observed_at'],
        monitor_hook_draft($transferPriceTemplate,$transferPrice)],
    [$transferPriceRecord,87006,'2026-11-23T07:00:00Z',$transferPriceCaption]);
foreach (['changed_price','changed_record','expired_export','expired_observation'] as $case) {
    $export=$transferPriceExport;$now=$transferPriceNow;
    if ($case==='changed_price') $export['items'][0]['offers'][0]['price']=87007;
    if ($case==='changed_record') $export['items'][0]['offers'][0]['recordId']=hash('sha256','another synthetic price');
    if ($case==='expired_export') $now+=900;
    if ($case==='expired_observation') $export['items'][0]['offers'][0]['ageSecondsAtExport']=36*3600;
    $reason=null;
    try { AnyTour\ExistingPostBatch\transferPrice($transferPricePlan,$transferPriceRow,$export,$now); }
    catch (RuntimeException $error) { $reason=$error->getMessage(); }
    channelPostCheck('real hotel editor refuses '.$case.' through the loaded price contract',$reason,
        str_starts_with($case,'changed_')?'transfer_price_or_caption_changed':'monitor_price_expired');
}
$transferFixtureRoot = sys_get_temp_dir().'/channel-hotel-control-'.bin2hex(random_bytes(10));
if (!mkdir($transferFixtureRoot,0700)) throw new RuntimeException('fixture_operations_creation_failed');
$transferFixtureRoot = realpath($transferFixtureRoot);
$transferFixtureDirectories = [];
$transferReceiptRead = static function(string $path): array {
    $lines=file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) throw new RuntimeException('fixture_receipt_missing');
    return array_map(static fn($line)=>json_decode($line,true,64,JSON_THROW_ON_ERROR),$lines);
};
$historicalPost = '<div data-post="anytour/862"><div class="tgme_widget_message_text">Old offer</div>'
    .'<time datetime="2024-09-19T12:22:11+00:00"></time>';
$historicalWidget = AnyTour\ExistingPostBatch\parseTransferPublic($historicalPost.'</div>', 'anytour', '862');
$historicalArchive = AnyTour\ExistingPostBatch\parseTransferPublic($historicalPost
    .'<a class="tgme_widget_message_inline_button" href="https://t.me/AnyTour_MSK">Contact</a></div>', 'anytour', '862');
AnyTour\ExistingPostBatch\transferGuardTargetKeyboard($historicalWidget, $historicalWidget);
foreach ([
    [$historicalWidget, $historicalArchive, 'transfer_target_keyboard_changed'],
    [$historicalArchive, $historicalWidget, 'transfer_target_keyboard_changed'],
    [$historicalWidget, array_replace($historicalWidget, ['published_unixtime'=>1]), 'transfer_target_public_changed'],
] as [$embedded, $archive, $expectedHold]) {
    $result = null;
    try { AnyTour\ExistingPostBatch\transferGuardTargetKeyboard($embedded, $archive); }
    catch (RuntimeException $error) { $result = $error->getMessage(); }
    channelPostCheck('historical Telegram archive gate '.$expectedHold, $result, $expectedHold);
}

$rankedPublicPrewriteReasons = [
    'public_identity_changed'=>true,
    'transfer_source_public_changed'=>true,
    'transfer_target_public_changed'=>true,
    'transfer_target_keyboard_changed'=>true,
    'public_post_missing'=>true,
    'transfer_read_unavailable'=>false,
];
$transferScenario = static function(string $case) use ($transferFixtureRoot,&$transferFixtureDirectories,$transferReceiptRead,$hotelMediaBody,$rankedPublicPrewriteReasons): array {
    $operations=$transferFixtureRoot.'/'.$case;
    if (!mkdir($operations,0700)) throw new RuntimeException('fixture_case_creation_failed');
    $transferFixtureDirectories[]=$operations;
    $row=['hotel_id'=>17595,'target_message_id'=>'862','source_message_id'=>'455'];
    $digest=str_repeat('d',64);
    $receipt=$operations.'/anytour-issue1566-historical-tg-media-862-v1/receipt.jsonl';
    $reservation=['publication_id'=>901,'version_id'=>902,'button_url'=>$hotelMediaBody['reply_markup']['inline_keyboard'][0][0]['url']];
    $response=$case==='request_timeout' ? ['http'=>0,'data'=>null,'transport_error'=>true]
        : ['http'=>200,'data'=>['ok'=>true,'result'=>['message_id'=>862,'caption'=>$hotelMediaBody['media']['caption']]],'transport_error'=>false];
    $saved=['state'=>'sent','message_id'=>'862','publication_id'=>901,'version_id'=>902,
        'content_hash'=>str_repeat('c',64),'snapshot'=>['metadata'=>['hotel_catalog_id'=>17595]],
        'start_payload'=>'p_'.str_repeat('A',24),'channel'=>['bot_url'=>'https://t.me/anyru_bot']];
    $evidence=['source_caption_sha256'=>str_repeat('e',64),'selected_record_id'=>str_repeat('f',64)];
    $state=(object)['calls'=>[],'requests'=>0,'completions'=>0,'runtime'=>0,'started_before_request'=>false,
        'completion_receipts'=>[],'request_bodies'=>[],'reservation_arguments'=>[]];
    $effects=[
        'reserve'=>static function() use ($state,$reservation): array {
            $state->calls[]='reserve';return $reservation;
        },
        'prepare'=>static function(array $received) use ($case,$state,$hotelMediaBody,$evidence,$rankedPublicPrewriteReasons): array {
            $state->calls[]='prepare';$state->reservation_arguments[]=$received;
            if ($case==='source_advanced_after_reservation') throw new RuntimeException('transfer_source_revision_advanced');
            if (array_key_exists($case,$rankedPublicPrewriteReasons)) throw new RuntimeException($case);
            $body=$hotelMediaBody;
            if ($case==='invalid_payload') $body['media']['type']='video';
            if ($case==='invalid_prepared') return ['body'=>$body];
            return ['body'=>$body,'evidence'=>$evidence];
        },
        'assert'=>static function(array $received) use ($case,$state): void {
            $state->calls[]='assert';$state->reservation_arguments[]=$received;
            if ($case==='prewrite_cas') throw new RuntimeException('hotel_transfer_source_changed');
        },
        'request'=>static function(array $body) use ($case,$state,$receipt,$transferReceiptRead,$response) {
            $state->calls[]='request';++$state->requests;$state->request_bodies[]=$body;
            $events=$transferReceiptRead($receipt);$last=end($events);
            $state->started_before_request=($last['state']??null)==='provider_edit_started'
                && ($last['provider_write_attempts']??null)===1;
            if ($case==='request_throw') throw new RuntimeException('fixture_request_failed');
            return $case==='invalid_response' ? null : $response;
        },
        'complete'=>static function(array $received,array $native) use ($case,$state,$receipt,$transferReceiptRead,$saved): array {
            $state->calls[]='complete';++$state->completions;$state->reservation_arguments[]=$received;
            $events=$transferReceiptRead($receipt);
            $responses=array_values(array_filter($events,static fn($event)=>($event['state']??null)==='provider_response_saved'));
            $last=end($events);
            $state->completion_receipts[]=['response'=>$native,'saved_response'=>$responses[0]['response']??null,
                'saved_count'=>count($responses),'last_state'=>$last['state']??null];
            if (($case==='complete_busy' && $state->completions<3) || $case==='complete_busy_exhausted') {
                throw new RuntimeException('hotel_transfer_native_claim_busy');
            }
            if ($case==='request_timeout') throw new RuntimeException('hotel_transfer_provider_outcome_unknown');
            if ($case==='complete_drift') throw new RuntimeException('hotel_transfer_native_identity_changed');
            if ($case==='complete_error') throw new LogicException('private fixture failure details');
            if ($case==='complete_unknown') $saved['state']='unknown';
            if ($case==='complete_wrong_hotel') $saved['snapshot']['metadata']['hotel_catalog_id']=358;
            return $saved;
        },
        'runtime'=>static function() use ($case,$state): void {
            $state->calls[]='runtime';++$state->runtime;
            if (($case==='prewrite_runtime' && $state->runtime===1) || ($case==='runtime_after_response' && $state->runtime===2)) {
                throw new RuntimeException('transfer_runtime_changed');
            }
        },
        'context'=>['native_run_id'=>'fixture-run','application_sha'=>str_repeat('a',40)],
    ];
    // A context is optional and must not be necessary for a successful control flow.
    if ($case==='complete_busy') unset($effects['context']);
    if ($case==='source_advanced_after_reservation' || array_key_exists($case,$rankedPublicPrewriteReasons)) {
        $effects['context']['continuation_of_ranked_run_id']=AnyTour\ExistingPostBatch\RANKED_ORIGINAL_RUN;
    }
    $result=AnyTour\ExistingPostBatch\transferExecuteRow($row,$digest,$effects,$operations);
    $events=$transferReceiptRead($receipt);
    $beforeReplay=clone $state;$beforeHash=hash_file('sha256',$receipt);
    $replay=AnyTour\ExistingPostBatch\transferExecuteRow($row,$digest,$effects,$operations);
    return compact('result','replay','events','state','beforeReplay','beforeHash','receipt','response','reservation','evidence','effects');
};
$transferExpected=[
    'success'=>[true,'native_and_registry_verified',null,1,1],
    'source_advanced_after_reservation'=>[false,'held_before_provider','transfer_source_revision_advanced',0,0],
    'prewrite_cas'=>[false,'held_before_provider','hotel_transfer_source_changed',0,0],
    'invalid_payload'=>[false,'held_before_provider','transfer_media_payload_invalid',0,0],
    'invalid_prepared'=>[false,'held_before_provider','transfer_prepared_evidence_invalid',0,0],
    'prewrite_runtime'=>[false,'held_before_provider','transfer_runtime_changed',0,0],
    'request_throw'=>[false,'provider_outcome_unknown','fixture_request_failed',1,0],
    'invalid_response'=>[false,'provider_outcome_unknown','transfer_provider_response_invalid',1,0],
    'request_timeout'=>[false,'response_saved_completion_requires_readback','hotel_transfer_provider_outcome_unknown',1,1],
    'complete_busy'=>[true,'native_and_registry_verified',null,1,3],
    'complete_busy_exhausted'=>[false,'response_saved_completion_requires_readback','hotel_transfer_native_claim_busy',1,3],
    'complete_unknown'=>[false,'response_saved_completion_requires_readback','transfer_completion_unconfirmed',1,1],
    'complete_wrong_hotel'=>[false,'response_saved_completion_requires_readback','transfer_completion_unconfirmed',1,1],
    'complete_drift'=>[false,'response_saved_completion_requires_readback','hotel_transfer_native_identity_changed',1,1],
    'complete_error'=>[false,'response_saved_completion_requires_readback','transfer_verification_failed',1,1],
    'runtime_after_response'=>[false,'response_saved_completion_requires_readback','transfer_runtime_changed',1,0],
];
foreach ($rankedPublicPrewriteReasons as $reason=>$canContinue) {
    $transferExpected[$reason]=[false,'held_before_provider',$reason,0,0];
    channelPostCheck('real hotel editor classifies '.$reason.' before reserving a target',
        AnyTour\ExistingPostBatch\transferLocalPublicHold($reason),$canContinue);
}
try {
    foreach ($transferExpected as $case=>$expected) {
        $flow=$transferScenario($case);$state=$flow['state'];$result=$flow['result'];
        if (array_key_exists($case,$rankedPublicPrewriteReasons)) {
            channelPostCheck('public pre-write refusal '.$case.' keeps reservation and zero attempts',
                [$result['local_prewrite_hold_recorded']??false,$result['reserved_publication_id'],$state->requests],
                [$rankedPublicPrewriteReasons[$case],901,0]);
        }
        if ($case==='source_advanced_after_reservation') {
            channelPostCheck('durable late source change holds its reservation with zero provider attempts',
                [$result['source_revision_hold_recorded'] ?? false,$result['reserved_publication_id'],$result['provider_write_attempts']],
                [true,901,0]);
        }
        channelPostCheck('real hotel editor '.$case.' has exact outcome and bounded effects',
            [$result['ok'],$result['state'],$result['reason']??null,$state->requests,$state->completions],$expected);
        channelPostCheck('real hotel editor '.$case.' refuses replay without effects or journal changes',
            [$flow['replay']['ok'],$flow['replay']['state'],$flow['replay']['reason'],$flow['replay']['provider_write_attempts'],
                (array)$state,hash_file('sha256',$flow['receipt'])],
            [false,'held_before_provider','transfer_previous_operation_do_not_replay',0,(array)$flow['beforeReplay'],$flow['beforeHash']]);
        $events=$flow['events'];$states=array_column($events,'state');
        channelPostCheck('real hotel editor '.$case.' retains its durable terminal evidence',
            [$events[0]['state'],end($states),$result['provider_write_attempts']],
            ['intent_saved',$expected[1],$expected[3]]);
        if ($state->requests>0) channelPostCheck('real hotel editor '.$case.' marks the single attempt before request',
            [$state->started_before_request,$state->request_bodies], [true,[$hotelMediaBody]]);
        if ($state->completions>0) {
            channelPostCheck('real hotel editor '.$case.' fsyncs native response before every completion',
                $state->completion_receipts,array_fill(0,$state->completions,[
                    'response'=>$flow['response'],'saved_response'=>$flow['response'],'saved_count'=>1,
                    'last_state'=>'provider_response_saved']));
        }
        if (str_starts_with($expected[1],'response_saved_')) {
            $responses=array_values(array_filter($events,static fn($event)=>($event['state']??null)==='provider_response_saved'));
            channelPostCheck('real hotel editor '.$case.' keeps the original response for readback',
                [count($responses),$responses[0]['response']??null], [1,$flow['response']]);
        }
        if ($case==='success') {
            channelPostCheck('real hotel editor executes the required order without a second writer',
                $state->calls,['reserve','prepare','runtime','assert','request','runtime','complete','runtime']);
            channelPostCheck('real hotel editor persists exact request, evidence and execution context',
                [$states,$events[2]['request'],$events[2]['evidence'],$events[0]['execution'],
                    $events[2]['request_sha256'],$state->reservation_arguments,$result['button_url']],
                [['intent_saved','identity_reserved','request_prepared','provider_edit_started','provider_response_saved','native_and_registry_verified'],
                    $hotelFullBody,$flow['evidence'],$flow['effects']['context'],
                    hash('sha256',json_encode($hotelFullBody,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),
                    array_fill(0,3,$flow['reservation']),$hotelMediaBody['reply_markup']['inline_keyboard'][0][0]['url']]);
        }
        if ($case==='complete_busy') channelPostCheck('real hotel editor retries only busy completion with optional context absent',
            [$state->calls,$events[0]['execution']], [
                ['reserve','prepare','runtime','assert','request','runtime','complete','complete','complete','runtime'],[]]);
    }
} finally {
    // Remove only the exact files/directories this fixture created; never recurse
    // or follow links through a runtime-supplied path.
    foreach (array_reverse($transferFixtureDirectories) as $operations) {
        $operation=$operations.'/anytour-issue1566-historical-tg-media-862-v1';
        $receipt=$operation.'/receipt.jsonl';
        if (is_file($receipt) && !is_link($receipt)) unlink($receipt);
        if (is_dir($operation) && !is_link($operation)) rmdir($operation);
        if (is_dir($operations) && !is_link($operations)) rmdir($operations);
    }
    if (is_dir($transferFixtureRoot) && !is_link($transferFixtureRoot)) rmdir($transferFixtureRoot);
}
channelPostCheck('real hotel editor fixtures remove only their isolated operation directories',!file_exists($transferFixtureRoot));

require_once __DIR__ . '/../tools/anytour_existing_post_max_transfer.php';
$maxTransferPlan = \AnyTour\ExistingPostBatch\maxTransferPlan(dirname(__DIR__), \AnyTour\ExistingPostBatch\MAX_TRANSFER_DIGEST);
channelPostCheck('MAX transfer plan binds just Sugar Marina and one unchanged old address', [
    $maxTransferPlan['hotel_id'], $maxTransferPlan['source']['url'], $maxTransferPlan['target']['url'],
    preg_match('/[0-9₽]/u', $maxTransferPlan['text']),
], [800, 'https://max.ru/channel_anytour/AaG2GG5XH2I', 'https://max.ru/anytour/AZ7exN6EPIU', 0]);
try {
    \AnyTour\ExistingPostBatch\maxTransferPlan(dirname(__DIR__), str_repeat('0', 64));
    channelPostCheck('MAX transfer rejects a different plan digest', false);
} catch (RuntimeException $error) {
    channelPostCheck('MAX transfer rejects a different plan digest', $error->getMessage(), 'max_transfer_plan_not_approved');
}
channelPostCheck('MAX transfer rejects any other historical post before native I/O',
    ChannelPostApiTransport::maxHotelTransferRequest('-68765999669267', 'mid.ffffc1752b045fed019edec4de843c86',
        $maxTransferPlan['text'], 'fixture', 'Рассчитать на мои даты', 'https://max.ru/fixture_bot?start=p_'.str_repeat('a', 24)),
    ['http'=>0, 'data'=>null, 'transport_error'=>true]);


$maxRecoveryTarget = $maxTransferPlan['target'];
$oldPublicEnvelope = '<script>message:{id:116775577486376060,link:'
    .json_encode($maxRecoveryTarget['url'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    .',createdAt:"2026-06-19T07:25:04.516Z",updatedAt:null,text:"Sugar Marina\nA &amp; B"}</script>';
channelPostCheck('MAX transfer reads the full primary caption from the current public envelope',
    AnyTour\ExistingPostBatch\maxTransferPublicCaption($oldPublicEnvelope, $maxRecoveryTarget), "Sugar Marina\nA & B");
foreach ([
    str_replace('2026-06-19T07:25:04.516Z', '2026-06-19T07:25:04.517Z', $oldPublicEnvelope),
    str_replace('/AZ7exN6EPIU', '/other', $oldPublicEnvelope),
] as $wrongPublicEnvelope) {
    try {
        AnyTour\ExistingPostBatch\maxTransferPublicCaption($wrongPublicEnvelope, $maxRecoveryTarget);
        channelPostCheck('MAX transfer refuses mismatched public identity', true, false);
    } catch (RuntimeException $error) {
        channelPostCheck('MAX transfer refuses mismatched public identity', $error->getMessage(), 'max_transfer_public_identity_changed');
    }
}
$originalMaxFields = ['operation'=>AnyTour\ExistingPostBatch\MAX_TRANSFER_OPERATION, 'hotel_id'=>800, 'ok'=>false,
    'provider_write_attempts'=>0, 'supplier_requests'=>0, 'bot_starts'=>0,
    'target_url'=>$maxRecoveryTarget['url'], 'plan_sha256'=>AnyTour\ExistingPostBatch\MAX_TRANSFER_DIGEST,
    'application_sha'=>AnyTour\ExistingPostBatch\MAX_TRANSFER_ORIGINAL_APPLICATION,
    'native_run_id'=>AnyTour\ExistingPostBatch\MAX_TRANSFER_ORIGINAL_RUN];
$maxStartLine = json_encode(['state'=>'preflight_started'] + $originalMaxFields, JSON_THROW_ON_ERROR)."\n";
$maxHeldLine = json_encode(['state'=>'hold', 'reason'=>'max_public_caption_unavailable'] + $originalMaxFields, JSON_THROW_ON_ERROR)."\n";
channelPostCheck('MAX recovery accepts the exact attempt with no write or reservation',
    AnyTour\ExistingPostBatch\maxTransferOriginalNoWrite($maxStartLine.$maxHeldLine, $maxRecoveryTarget),
    hash('sha256', $maxStartLine.$maxHeldLine));
try {
    AnyTour\ExistingPostBatch\maxTransferOriginalNoWrite(
        $maxStartLine.str_replace('"provider_write_attempts":0', '"provider_write_attempts":1', $maxHeldLine), $maxRecoveryTarget);
    channelPostCheck('MAX recovery refuses a previous write attempt', true, false);
} catch (RuntimeException $error) {
    channelPostCheck('MAX recovery refuses a previous write attempt', $error->getMessage(), 'max_transfer_original_journal_invalid');
}

require_once __DIR__ . '/support/ranked-unreached.php';
\AnyTour\Tests\RankedUnreached\run($rankedTail, $rankedDigest);
require_once __DIR__ . '/ranked_telegram_public_cases.php';
\AnyTour\Tests\RankedPublicReadback\runCases();

echo 'Channel post automation: ' . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed === 0 ? 0 : 1);


