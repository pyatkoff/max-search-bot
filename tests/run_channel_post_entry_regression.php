<?php
declare(strict_types=1);
require_once __DIR__ . '/fixtures/channel_post_entry_fixture.php';
[$pdo, $registry, $publications] = createChannelPostEntryFixture();
$passed = 0; $failed = 0; $trafficFiles = []; $sent = []; $applicationMessages = [];
function cpeCheck(string $name, $actual, $expected = true): void {
    global $passed, $failed;
    if ($actual === $expected) { $passed++; echo 'PASS  ' . $name . PHP_EOL; }
    else { $failed++; echo 'FAIL  ' . $name . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual) . PHP_EOL; }
}
function cpeConversation(string $platform, int $user): array {
    global $pdo;
    $q = $pdo->prepare('SELECT * FROM conversations WHERE project_key=? AND channel=? AND external_chat_id=? ORDER BY id DESC LIMIT 1');
    $q->execute(['anytour', $platform, (string)($platform === 'max' ? -$user : $user)]); return $q->fetch() ?: [];
}
function cpeSeed(string $platform, int $user, string $status = 'ai', ?int $manager = null, ?int $source = null): array {
    $source ??= $platform === 'max' ? 1 : 3;
    $incoming = ['platform' => $platform, 'type' => 'message', 'source_key' => $source === 1 ? 'max:anytour-main' : ($source === 3 ? 'telegram:anytour-main' : ($platform === 'max' ? 'max_anytour_msk1' : 'tg_anytour_msk')), 'message_id' => 'fixture.seed.' . $user, 'text' => 'Earlier customer text', 'user' => ['chat_id' => $platform === 'max' ? -$user : $user, 'external_user_id' => $user, 'first_name' => 'Fixture', 'last_name' => 'Tourist']];
    ConversationRecorder::inbound($incoming);
    global $pdo;
    $q = $pdo->prepare('UPDATE conversations SET status=?,manager_id=?,source_id=?,entry_channel=? WHERE project_key=? AND channel=? AND external_chat_id=?');
    $q->execute([$status, $manager, $source, 'previous-entry', 'anytour', $platform, (string)($platform === 'max' ? -$user : $user)]);
    return cpeConversation($platform, $user);
}
function cpeMessages(int $conversation): array {
    global $pdo; $q = $pdo->prepare('SELECT * FROM messages WHERE conversation_id=? ORDER BY id'); $q->execute([$conversation]); return $q->fetchAll();
}
function cpeEventCount(int $conversation, string $type): int {
    global $pdo; $q = $pdo->prepare('SELECT COUNT(*) FROM conversation_events WHERE conversation_id=? AND event_type=?'); $q->execute([$conversation, $type]); return (int)$q->fetchColumn();
}
function cpeStartMax(int $user, string $payload, int $event = 1): void {
    global $trafficFiles, $maxMessenger;
    IntegrationRegistry::useMessenger($maxMessenger);
    $trafficFiles[TrafficAttributionService::file(dirname(__DIR__), -$user)] = true;
    MaxUpdateHandler::handleStarted(['update_type' => 'bot_started', 'timestamp' => 1791960000000 + $event, 'payload' => $payload, 'user' => ['user_id' => $user, 'name' => 'Fixture Tourist']]);
}
function cpeStartTelegram(int $user, string $payload, IncomingUpdateDispatcher $dispatcher, TelegramMessengerAdapter $messenger, int $event = 1): bool {
    return TelegramWebhookHandler::dispatchUpdate(['update_id' => 710000 + $event, 'message' => ['message_id' => 710000 + $event, 'from' => ['id' => $user, 'first_name' => 'Fixture', 'last_name' => 'Tourist'], 'chat' => ['id' => $user, 'type' => 'private'], 'text' => '/start ' . $payload]], $dispatcher, $messenger);
}
final class CpeDispatcher extends IncomingUpdateDispatcher {
    public int $dispatchCount = 0;
    public function dispatch(?array $incoming): bool { $this->dispatchCount++; return parent::dispatch($incoming); }
}
$messenger = new TelegramMessengerAdapter(static function (string $method, array $payload) use (&$sent): bool { $sent[] = compact('method', 'payload'); return true; }, 'system');
$maxMessenger = new MaxMessengerAdapter(
    static function ($chat, string $text) use (&$sent): bool { $sent[] = ['method' => 'max.send', 'payload' => compact('chat', 'text')]; return true; },
    static function ($chat, string $text, array $buttons) use (&$sent): bool { $sent[] = ['method' => 'max.sendWithButtons', 'payload' => compact('chat', 'text', 'buttons')]; return true; },
    'system'
);
IntegrationRegistry::useMessenger($messenger);
$application = new DialogueApplication(static function ($message, $incoming) use (&$applicationMessages): void { $applicationMessages[] = $incoming; });
$dispatcher = new CpeDispatcher($application);

try {
    foreach (['max', 'telegram'] as $platform) {
        $payload = $publications[$platform]['start_payload'];
        cpeCheck($platform . ' token resolves exact canonical bot/project/platform', ChannelPostEntryService::resolve($platform, $payload)['publication_id'] ?? null, $publications[$platform]['publication_id']);
        cpeCheck($platform . ' malformed p token fails closed', ChannelPostEntryService::resolve($platform, 'p_bad'), null);
        cpeCheck($platform . ' foreign platform token cannot resolve', ChannelPostEntryService::resolve($platform === 'max' ? 'telegram' : 'max', $payload), null);
    }
    $normalConfig = ProjectConfig::all(); $wrongConfig = $normalConfig; $wrongConfig['channel_posts']['max']['bot_key'] = 'wrong_bot'; $wrongConfig['channel_posts']['max']['bot_url'] = 'https://max.ru/wrong_bot';
    ProjectConfig::resetForTests($wrongConfig);
    cpeCheck('wrong canonical MAX bot cannot resolve token', ChannelPostEntryService::resolve('max', $publications['max']['start_payload']), null);
    ProjectConfig::resetForTests($normalConfig);
    $wrongConfig = $normalConfig; $wrongConfig['id'] = 'other'; ProjectConfig::resetForTests($wrongConfig);
    cpeCheck('foreign project cannot resolve known post token', ChannelPostEntryService::resolve('max', $publications['max']['start_payload']), null);
    ProjectConfig::resetForTests($normalConfig);
    $wrongConfig = $normalConfig; $wrongConfig['channel_posts']['telegram']['bot_key'] = 'Wrong_bot'; $wrongConfig['channel_posts']['telegram']['bot_url'] = 'https://t.me/Wrong_bot';
    ProjectConfig::resetForTests($wrongConfig);
    cpeCheck('wrong canonical Telegram bot cannot resolve token', ChannelPostEntryService::resolve('telegram', $publications['telegram']['start_payload']), null);
    ProjectConfig::resetForTests($normalConfig);
    cpeCheck('Telegram addressed start extracts opaque token', ChannelPostEntryService::telegramPayload(['text' => '/start@Any_tour_bot ' . $publications['telegram']['start_payload']]), $publications['telegram']['start_payload']);

    $user = 98773101; $payload = $publications['max']['start_payload'];
    cpeStartMax($user, $payload);
    $new = cpeConversation('max', $user);
    cpeCheck('new MAX post entry creates canonical manager queue', $new['status'] ?? null, 'waiting_manager');
    cpeCheck('new MAX post entry binds post source', (int)($new['source_id'] ?? 0), 2);
    cpeCheck('new MAX post entry records immutable version pointer', $registry->managerContext((int)$new['id'], 'anytour')['current']['version_id'] ?? null, $publications['max']['version_id']);
    cpeCheck('MAX start event creates no invented customer message', count(array_filter(cpeMessages((int)$new['id']), static fn($m) => $m['direction'] === 'inbound')), 0);
    cpeCheck('MAX post entry never resets questionnaire', MaxSearchApi::$resets, []);

    $user = 98773102; $payload = $publications['telegram']['start_payload'];
    $appsBefore = count($applicationMessages);
    cpeStartTelegram($user, $payload, $dispatcher, $messenger, 2);
    $new = cpeConversation('telegram', $user);
    cpeCheck('new TG post entry creates canonical manager queue', $new['status'] ?? null, 'waiting_manager');
    cpeCheck('new TG post entry binds post source', (int)($new['source_id'] ?? 0), 4);
    cpeCheck('new TG post entry does not fall through to AI', count($applicationMessages), $appsBefore);
    cpeCheck('new TG post entry records immutable version pointer', $registry->managerContext((int)$new['id'], 'anytour')['current']['version_id'] ?? null, $publications['telegram']['version_id']);

    $event = 20;
    foreach (['max', 'telegram'] as $platform) foreach (['manager', 'waiting_manager', 'ai'] as $status) {
        $user = 98773200 + $event++; $before = cpeSeed($platform, $user, $status, $status === 'waiting_manager' ? null : 7);
        $id = (int)$before['id']; $messages = cpeMessages($id); $sendCount = count($sent); $dispatchCount = $dispatcher->dispatchCount;
        $queueEvents = cpeEventCount($id, 'waiting_manager'); $requests = cpeEventCount($id, 'manager_request');
        $paid = ['yclid' => '1234567890123456', 'region_id' => '213', 'campaign_id' => '714260445'];
        if ($platform === 'max') {
            $trafficFiles[TrafficAttributionService::file(dirname(__DIR__), -$user)] = true;
            TrafficAttributionService::save(dirname(__DIR__), -$user, $paid['yclid'], $paid['region_id'], $paid['campaign_id'], 'previous-paid', 'max:anytour-main');
            cpeStartMax($user, $publications[$platform]['start_payload'], $event++);
            cpeStartMax($user, $publications[$platform]['start_payload'], $event++);
        } else {
            cpeStartTelegram($user, $publications[$platform]['start_payload'], $dispatcher, $messenger, $event++);
            cpeStartTelegram($user, $publications[$platform]['start_payload'], $dispatcher, $messenger, $event++);
        }
        $after = cpeConversation($platform, $user); $label = $platform . ' / ' . $status;
        cpeCheck('owned post start preserves manager/status/source: ' . $label, [$after['manager_id'], $after['status'], $after['source_id']], [$before['manager_id'], $before['status'], $before['source_id']]);
        cpeCheck('owned post start refreshes entry attribution: ' . $label, $after['entry_channel'], $platform === 'max' ? 'max_anytour_msk1' : 'tg_anytour_msk');
        cpeCheck('owned post start remains silent: ' . $label, count($sent), $sendCount);
        cpeCheck('owned post start preserves transcript: ' . $label, cpeMessages($id), $messages);
        cpeCheck('owned post start does not create another request: ' . $label, [cpeEventCount($id, 'waiting_manager'), cpeEventCount($id, 'manager_request')], [$queueEvents, $requests]);
        cpeCheck('owned post start records source post: ' . $label, $registry->managerContext($id, 'anytour')['current']['publication_id'] ?? null, $publications[$platform]['publication_id']);
        if ($platform === 'telegram') cpeCheck('owned TG start avoids dispatcher/push path: ' . $label, $dispatcher->dispatchCount, $dispatchCount);
        else {
            $meta = TrafficAttributionService::get(dirname(__DIR__), -$user);
            cpeCheck('owned MAX post start preserves prior paid attribution: ' . $label, [$meta['yclid'], $meta['region_id'], $meta['campaign_id']], array_values($paid));
        }
        if ($platform === 'max') cpeStartMax($user, 'p_unavailable', $event++);
        else cpeStartTelegram($user, 'p_unavailable', $dispatcher, $messenger, $event++);
        $invalid = cpeConversation($platform, $user);
        cpeCheck('unavailable post start preserves ownership: ' . $label, [$invalid['manager_id'], $invalid['status'], $invalid['source_id']], [$before['manager_id'], $before['status'], $before['source_id']]);
        cpeCheck('unavailable post start remains silent: ' . $label, count($sent), $sendCount);
        cpeCheck('unavailable post start preserves transcript: ' . $label, cpeMessages($id), $messages);
        $unavailableContext = $registry->managerContext($id, 'anytour');
        cpeCheck('unavailable post clears stale current context: ' . $label, !empty($unavailableContext['current']['unavailable']) && $unavailableContext['current']['version_id'] === null);
        cpeCheck('unavailable post keeps earlier publication in history: ' . $label, $unavailableContext['history'][0]['publication_id'] ?? null, $publications[$platform]['publication_id']);
        if ($platform === 'telegram') cpeCheck('unavailable TG start avoids dispatcher/push path: ' . $label, $dispatcher->dispatchCount, $dispatchCount);
        else {
            $meta = TrafficAttributionService::get(dirname(__DIR__), -$user);
            cpeCheck('invalid MAX post start retains prior paid attribution: ' . $label, [$meta['yclid'], $meta['region_id'], $meta['campaign_id']], array_values($paid));
        }
    }

    foreach (['p_bad!', 'p_' . str_repeat('A', 80)] as $badPayload) {
        $user = 98773800 + $event++; $owned = cpeSeed('telegram', $user, 'manager', 7);
        $messages = cpeMessages((int)$owned['id']); $before = $dispatcher->dispatchCount;
        cpeStartTelegram($user, $badPayload, $dispatcher, $messenger, $event++);
        cpeCheck('malformed reserved TG post payload remains quiet: ' . strlen($badPayload), $dispatcher->dispatchCount, $before);
        cpeCheck('malformed reserved TG post payload keeps transcript: ' . strlen($badPayload), cpeMessages((int)$owned['id']), $messages);
    }

    $user = 98774001; $owned = cpeSeed('max', $user, 'manager', 7);
    $trafficFiles[TrafficAttributionService::file(dirname(__DIR__), -$user)] = true;
    TrafficAttributionService::save(dirname(__DIR__), -$user, '1234567890123456', '213', '714260445', 'previous-paid', 'max:anytour-main');
    cpeStartMax($user, $publications['max']['start_payload'], $event++);
    $sendCount = count($sent); $appsBefore = count($applicationMessages);
    $incoming = MaxIncomingAdapter::fromUpdate(['update_type' => 'message_created', 'message' => ['sender' => ['user_id' => $user, 'name' => 'Fixture Tourist'], 'recipient' => ['chat_type' => 'dialog'], 'body' => ['mid' => 'mid.ordinary-question', 'text' => 'А на другие даты с ребёнком?']]]);
    $dispatcher->dispatch($incoming);
    $afterQuestion = cpeConversation('max', $user);
    cpeCheck('deferred MAX attribution sync preserves original assigned routing source', $afterQuestion['source_id'], $owned['source_id']);
    cpeCheck('deferred MAX question preserves assigned manager/status', [$afterQuestion['manager_id'], $afterQuestion['status']], [$owned['manager_id'], $owned['status']]);
    cpeCheck('deferred MAX question gets no automatic bot reply', count($sent), $sendCount);
    cpeCheck('deferred MAX question stays outside AI application', count($applicationMessages), $appsBefore);
    cpeCheck('deferred MAX ordinary question is recorded exactly once', count(array_filter(cpeMessages((int)$owned['id']), static fn($m) => $m['external_message_id'] === 'mid.ordinary-question')), 1);
    cpeCheck('deferred MAX question still points to clicked source post', $registry->managerContext((int)$owned['id'], 'anytour')['current']['publication_id'], $publications['max']['publication_id']);
    cpeCheck('deferred MAX paid IDs preserved in canonical conversation', [$afterQuestion['attribution_region'], $afterQuestion['attribution_campaign']], ['213', '714260445']);

    foreach (['max', 'telegram'] as $index => $platform) {
        $user = 98774100 + $index; $owned = cpeSeed($platform, $user, 'manager', 7);
        $id = (int)$owned['id']; $payload = $publications[$platform]['start_payload'];
        $firstEvent = $event++;
        if ($platform === 'max') cpeStartMax($user, $payload, $firstEvent);
        else cpeStartTelegram($user, $payload, $dispatcher, $messenger, $firstEvent);
        $beforeVersion = $registry->managerContext($id, 'anytour')['current'];
        $changed = $registry->registerPublication('anytour', $platform, $platform === 'max' ? 'id9704048781_1_bot' : 'Any_tour_bot', $platform === 'max' ? '-80095447388179' : '-1001234567890', $platform === 'max' ? 'mid.fixture-entry' : '31', ['text' => $platform . ' новая цена и даты', 'format' => 'native', 'published_at' => '2026-10-14 08:00:00', 'edited_at' => '2026-10-14 09:00:00']);
        cpeCheck('saved manager context remains pinned after SMM edit: ' . $platform, $registry->managerContext($id, 'anytour')['current']['version_id'], $beforeVersion['version_id']);
        $secondEvent = $event++;
        if ($platform === 'max') cpeStartMax($user, $payload, $secondEvent);
        else cpeStartTelegram($user, $payload, $dispatcher, $messenger, $secondEvent);
        $context = $registry->managerContext($id, 'anytour');
        cpeCheck('fresh entry points to latest content version: ' . $platform, $context['current']['version_id'], $changed['version_id']);
        cpeCheck('earlier entry retains original post snapshot: ' . $platform, $context['history'][0]['text'], $platform . ' исходный тур');
        $q = $pdo->prepare('SELECT COUNT(*) FROM post_entries WHERE conversation_id=?'); $q->execute([$id]); $entryCount = (int)$q->fetchColumn();
        if ($platform === 'max') cpeStartMax($user, $payload, $secondEvent);
        else cpeStartTelegram($user, $payload, $dispatcher, $messenger, $secondEvent);
        $q->execute([$id]);
        cpeCheck('same authenticated start replay creates no duplicate context: ' . $platform, (int)$q->fetchColumn(), $entryCount);
        cpeCheck('context revision never changes assigned routing ownership: ' . $platform, [cpeConversation($platform, $user)['source_id'], cpeConversation($platform, $user)['manager_id']], [$owned['source_id'], $owned['manager_id']]);
    }

    foreach (['max', 'telegram'] as $platform) {
        $user = 98773500 + $event++; $before = cpeSeed($platform, $user);
        ConversationRecorder::eventByChat($platform, $platform === 'max' ? -$user : $user, 'source_handling_choice', ['choice' => 'ai']);
        $appsBefore = count($applicationMessages);
        if ($platform === 'max') cpeStartMax($user, $publications[$platform]['start_payload'], $event++);
        else cpeStartTelegram($user, $publications[$platform]['start_payload'], $dispatcher, $messenger, $event++);
        $after = cpeConversation($platform, $user);
        cpeCheck('explicit post overrides old self-service choice: ' . $platform, $after['status'], 'waiting_manager');
        cpeCheck('explicit post rebinds unowned source: ' . $platform, (int)$after['source_id'], $platform === 'max' ? 2 : 4);
        cpeCheck('explicit post does not dispatch AI application: ' . $platform, count($applicationMessages), $appsBefore);
    }

    $before = count(MaxSearchApi::$greetings);
    cpeStartMax(98773901, '123456789_213_campaign_42', $event++);
    cpeCheck('legacy paid MAX non-post start retains original renderer', count(MaxSearchApi::$greetings), $before + 1);
    cpeCheck('legacy paid MAX payload unchanged', [MaxSearchApi::$greetings[$before]['meta']['yclid'], MaxSearchApi::$greetings[$before]['meta']['region_id'], MaxSearchApi::$greetings[$before]['meta']['campaign_id']], ['123456789', '213', '42']);
    $before = count($applicationMessages); cpeStartTelegram(98773902, 'legacy_unknown', $dispatcher, $messenger, $event++);
    cpeCheck('legacy TG non-post start still reaches original application', count($applicationMessages), $before + 1);
    cpeCheck('legacy TG non-post source still default', $applicationMessages[$before]['source_key'], 'telegram:anytour-main');
    cpeCheck('no accidental source configuration additions', (int)$pdo->query('SELECT COUNT(*) FROM conversation_sources')->fetchColumn(), 9);

    $postFixtures = require __DIR__ . '/fixtures/channel_post_automation.php';
    foreach (['max', 'telegram'] as $platform) {
        $message = $postFixtures[$platform];
        if ($platform === 'max') $message['body']['mid'] = 'mid.runtime-storage-retry';
        else $message['message_id'] = 733;
        $update = $platform === 'max'
            ? ['update_type' => 'message_created', 'timestamp' => 1791961000000, 'message' => $message]
            : ['update_id' => 733, 'channel_post' => $message];
        $pdo->exec('ALTER TABLE post_publications RENAME TO post_publications_unavailable');
        $failedReceipt = ChannelPostRuntime::receive($platform, $update);
        cpeCheck($platform . ' active channel storage outage remains recognized', $failedReceipt['recognized']);
        cpeCheck($platform . ' active channel storage outage does not report queued success', $failedReceipt['queued'], false);
        cpeCheck($platform . ' runtime marks transient storage failure for provider retry', ChannelPostRuntime::storageFailed($platform));
        $pdo->exec('ALTER TABLE post_publications_unavailable RENAME TO post_publications');
        $restored = ChannelPostRuntime::receive($platform, $update);
        cpeCheck($platform . ' same channel envelope queues after database recovery', $restored['queued']);
        cpeCheck($platform . ' successful recovery clears storage failure flag', ChannelPostRuntime::storageFailed($platform), false);
        $token = $registry->publication($restored['publication_id'])['start_payload'];
        $again = ChannelPostRuntime::receive($platform, $update);
        cpeCheck($platform . ' provider retry retains stable publication/version', [$again['publication_id'], $again['version_id']], [$restored['publication_id'], $restored['version_id']]);
        cpeCheck($platform . ' provider retry retains stable opaque token', $registry->publication($again['publication_id'])['start_payload'], $token);
    }

    $pdo->exec("UPDATE conversation_sources SET handling_mode='ai' WHERE id=2");
    cpeCheck('AI-only publication source cannot route to manager', ChannelPostEntryService::resolve('max', $publications['max']['start_payload']), null);
    $pdo->exec("UPDATE conversation_sources SET handling_mode='manager',is_active=0 WHERE id=2");
    cpeCheck('retired publication source fails closed', ChannelPostEntryService::resolve('max', $publications['max']['start_payload']), null);
} catch (Throwable $error) {
    $failed++; echo 'FAIL  entry integration exception: ' . $error->getMessage() . PHP_EOL;
} finally {
    foreach (array_keys($trafficFiles) as $file) { @unlink($file); @unlink($file . '.tmp'); }
    ConversationDb::resetForTests(); IntegrationRegistry::resetForTests(); ProjectConfig::resetForTests(null);
}

// A CLI recovery runner deployed under the document root must exit before it
// loads configuration when requested over HTTP, even with apply-like arguments.
$serverRoot = sys_get_temp_dir() . '/channel-post-worker-http-' . bin2hex(random_bytes(8));
$server = null;
try {
    mkdir($serverRoot, 0700); mkdir($serverRoot . '/tools', 0700);
    copy(dirname(__DIR__) . '/tools/channel_post_worker.php', $serverRoot . '/tools/channel_post_worker.php');
    file_put_contents($serverRoot . '/config.php', '<?php file_put_contents(__DIR__."/config-loaded","yes"); throw new RuntimeException("fixture config must not load over HTTP");');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('fixture_http_socket_unavailable');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    mkdir($serverRoot . '/private-tmp', 0700);
    $server = proc_open([PHP_BINARY, '-d', 'sys_temp_dir=' . $serverRoot . '/private-tmp', '-S', $address, '-t', $serverRoot], [0 => ['pipe', 'r'], 1 => ['file', $serverRoot . '/server.log', 'a'], 2 => ['file', $serverRoot . '/server.log', 'a']], $pipes);
    if (!is_resource($server)) throw new RuntimeException('fixture_http_server_unavailable');
    fclose($pipes[0]);
    for ($i = 0; $i < 30; $i++) { $ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1); if ($ready) { fclose($ready); break; } usleep(50000); }
    $body = file_get_contents('http://' . $address . '/tools/channel_post_worker.php?--platform=max&--run', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]));
    cpeCheck('CLI worker requested over HTTP returns 404', strpos($http_response_header[0] ?? '', '404') !== false);
    cpeCheck('CLI worker HTTP guard runs before configuration/secrets loading', is_file($serverRoot . '/config-loaded'), false);
    cpeCheck('CLI worker HTTP rejection exposes no output', $body, '');

    $bootstrap = '<?php require_once ' . var_export(__DIR__ . '/fixtures/channel_post_entry_fixture.php', true) . '; [$fixturePdo]=createChannelPostEntryFixture(); $fixturePdo->exec("ALTER TABLE post_publications RENAME TO post_publications_unavailable");';
    file_put_contents($serverRoot . '/max-storage-webhook.php', $bootstrap . ' define("MAX_SEARCH_WEBHOOK_SECRET","fixture-max-secret"); MaxUpdateHandler::handle();');
    $httpFixtures = require __DIR__ . '/fixtures/channel_post_automation.php';
    $maxEnvelope = ['update_type' => 'message_created', 'timestamp' => 1791962000000, 'message' => $httpFixtures['max']];
    $maxEnvelope['message']['body']['mid'] = 'mid.http-storage-retry';
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nX-Max-Bot-Api-Secret: fixture-max-secret\r\n", 'content' => json_encode($maxEnvelope), 'ignore_errors' => true, 'timeout' => 3]]);
    $maxBody = file_get_contents('http://' . $address . '/max-storage-webhook.php', false, $context);
    cpeCheck('actual MAX channel webhook storage outage returns retryable 503', strpos($http_response_header[0] ?? '', '503') !== false);
    cpeCheck('actual MAX storage failure exposes only safe retry body', $maxBody, 'retry');
    cpeCheck('MAX channel storage failure occurs before personal dedupe claim', is_file($serverRoot . '/private-tmp/max-search-bot-update-dedupe.json'), false);
    $maxBody = file_get_contents('http://' . $address . '/max-storage-webhook.php', false, $context);
    cpeCheck('MAX retry remains retryable instead of being swallowed as duplicate', strpos($http_response_header[0] ?? '', '503') !== false && $maxBody === 'retry');
    cpeCheck('MAX repeated failed channel delivery never consumes personal claim', is_file($serverRoot . '/private-tmp/max-search-bot-update-dedupe.json'), false);

    mkdir($serverRoot . '/services', 0700); mkdir($serverRoot . '/handlers', 0700);
    file_put_contents($serverRoot . '/config.php', $bootstrap . ' define("TELEGRAM_WEBHOOK_SECRET","fixture-tg-secret");');
    file_put_contents($serverRoot . '/services/RuntimeBootstrap.php', '<?php class RuntimeBootstrap { public static function boot($root):void {} }');
    file_put_contents($serverRoot . '/maxsearchclass.php', '<?php // Legacy boundary already injected by the integration fixture.');
    file_put_contents($serverRoot . '/handlers/TelegramWebhookHandler.php', '<?php require_once ' . var_export(dirname(__DIR__) . '/handlers/TelegramWebhookHandler.php', true) . ';');
    copy(dirname(__DIR__) . '/telegram_webhook.php', $serverRoot . '/telegram-storage-webhook.php');
    $tgEnvelope = ['update_id' => 734, 'channel_post' => $httpFixtures['telegram']];
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nX-Telegram-Bot-Api-Secret-Token: fixture-tg-secret\r\n", 'content' => json_encode($tgEnvelope), 'ignore_errors' => true, 'timeout' => 3]]);
    $tgBody = file_get_contents('http://' . $address . '/telegram-storage-webhook.php', false, $context);
    cpeCheck('actual Telegram outer webhook storage outage returns retryable 500', strpos($http_response_header[0] ?? '', '500') !== false);
    cpeCheck('Telegram storage outage returns safe failure rather than acknowledgement', json_decode((string)$tgBody, true), ['ok' => false, 'error' => 'internal_error']);
} catch (Throwable $error) {
    $failed++; echo 'FAIL  worker HTTP guard fixture: ' . $error->getMessage() . PHP_EOL;
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if (is_dir($serverRoot)) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($serverRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $file) { if ($file->isDir()) @rmdir($file->getPathname()); else @unlink($file->getPathname()); }
        @rmdir($serverRoot);
    }
}
echo 'Channel post entry: ' . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
