<?php
declare(strict_types=1);

require_once __DIR__.'/fixtures/repair_max_webhook_fixture.php';

$passed = 0;
function repairCheck(string $name, bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $passed++;
    echo 'PASS '.$name.PHP_EOL;
}

function repairEntryPayload(PDO $pdo, int $conversationId): array
{
    $q = $pdo->prepare("SELECT payload_json FROM conversation_events WHERE conversation_id=? AND event_type='bot_started' AND payload_json LIKE ? ORDER BY id DESC LIMIT 1");
    $q->execute([$conversationId,'%"repair_entry_reply":%']);
    return json_decode((string)$q->fetchColumn(), true) ?: [];
}

function repairEntryIntent(PDO $pdo, int $conversationId): array
{
    return repairEntryPayload($pdo,$conversationId)['repair_entry_reply'] ?? [];
}

$catalog = AppleService39PilotCatalog::entries();
repairCheck('catalog contains exactly the fifteen reviewed repair entries', count($catalog) === 15);
foreach ($catalog as $entry) {
    $payload = AppleService39PilotCatalog::payloadForPost($entry['post_id']);
    repairCheck('canonical payload maps to reviewed model and service '.$entry['post_id'],
        $payload === 'as39_'.$entry['post_id'] && strlen($payload) <= 64
        && preg_match('/\A[A-Za-z0-9_-]+\z/D',$payload) === 1
        && AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot',$payload) === $entry);
}
$battery = AppleService39PilotCatalog::forPost('repair-iphone-x-battery-replacement');
repairCheck('original pilot alias remains compatible', AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot','as39_x_battery') === $battery);
repairCheck('catalog fails closed for other projects bots and unreviewed post keys',
    AppleService39PilotCatalog::resolve('repair_two','id390521129062_bot','as39_x_battery') === null
    && AppleService39PilotCatalog::resolve('appleservice39','id999999999002_bot','as39_x_battery') === null
    && AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot','as39_repair-iphone-16-display') === null
    && AppleService39PilotCatalog::payloadForPost('service-menu') === null);
$displayX = AppleService39PilotCatalog::forPost('repair-iphone-x-display');
$display15 = AppleService39PilotCatalog::forPost('repair-iphone-15-pro-max-display');
repairCheck('display options and unknown-price qualification are retained', $displayX['price_kind'] === 'variants'
    && str_contains($displayX['price_text'],'12 000') && str_contains($displayX['price_text'],'6 000')
    && $display15['price_kind'] === 'on_request' && str_contains($display15['price_text'],'по запросу')
    && preg_match('/[0-9]/',$display15['price_text']) === 0);

$pdo = repairFixture();
$globalBefore = ProjectConfig::all();
$legacyBefore = [
    $pdo->query("SELECT * FROM conversations WHERE project_key='anytour'")->fetchAll(),
    $pdo->query("SELECT * FROM customer_channels WHERE project_key='anytour'")->fetchAll(),
    $pdo->query('SELECT * FROM messages WHERE conversation_id=100')->fetchAll(),
];
$sent = [];
$notifications = [];
$archives = [];
$factory = static function (ProjectMaxConnection $connection) use ($pdo, &$sent): MaxMessengerAdapter {
    return new MaxMessengerAdapter(static function ($chatId, string $text) use ($connection, $pdo, &$sent): string {
        repairCheck('provider write follows core commit', !$pdo->inTransaction());
        $row = repairConversation($pdo, $connection->projectKey(), abs((int)$chatId));
        repairCheck('provider write sees durable open manager conversation', in_array($row['status'] ?? '', ['waiting_manager','manager'], true));
        $sent[] = ['project'=>$connection->projectKey(),'chat'=>$chatId,'text'=>$text];
        return 'synthetic_reply_'.count($sent);
    }, null, 'system', null, false);
};
$notify = static function (int $id, string $body, string $mid) use ($pdo, &$notifications): void {
    repairCheck('notification follows shared history commit', !$pdo->inTransaction());
    $notifications[] = [$id,$mid];
};
$archive = static function (string $mid, string $project, int $id) use (&$archives): int {
    $archives[] = [$mid,$project,$id];
    return 0;
};
$handle = static function (string $project, array $update, ?callable $transport = null) use ($factory,$notify,$archive): array {
    $result = RepairMaxWebhookService::handle($project, str_repeat($project === 'appleservice39' ? 'a' : 'b',32), $update, $transport ?? $factory, $notify);
    RepairMaxWebhookService::archiveCommitted($result, $archive);
    return $result;
};

$before = (int)$pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
repairCheck('invalid secret rejects before persistence', RepairMaxWebhookService::handle('appleservice39','wrong',repairStart(7001),$factory,$notify)['status'] === 403);
repairCheck('request cannot choose a different project with Apple secret', RepairMaxWebhookService::handle('repair_two',str_repeat('a',32),repairStart(7001),$factory,$notify)['status'] === 403);
repairCheck('unknown project is closed', RepairMaxWebhookService::handle('unregistered',str_repeat('a',32),repairStart(7001),$factory,$notify)['status'] === 403);
repairCheck('authentication failures create no shared state', (int)$pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn() === $before && !$sent && !$notifications);

$first = $handle('appleservice39',repairStart(7001));
$apple = repairConversation($pdo,'appleservice39',7001);
$context = json_decode((string)$apple['metadata_json'],true);
repairCheck('pilot start persists into the shared queue', $first['status'] === 200 && $apple['status'] === 'waiting_manager');
repairCheck('reviewed model and service survive without invented post URL', $context['repair_context'] === ['model'=>'iPhone X','service'=>'Замена аккумулятора','entry_code'=>'as39_x_battery'] && !isset($context['repair_context']['post_url']));
repairCheck('platform start is an event and never a fake customer message', (int)$pdo->query('SELECT COUNT(*) FROM messages WHERE conversation_id='.(int)$apple['id']." AND direction='inbound'")->fetchColumn() === 0);
repairCheck('bot reply uses Apple brand reviewed price and a service-specific question', count($sent) === 1
    && $sent[0]['text'] === AppleService39PilotCatalog::responseText($battery,'AppleService39')
    && str_contains($sent[0]['text'],'3 500') && str_contains($sent[0]['text'],'Актуальную цену'));
repairCheck('successful bot reply is recorded against exact Apple conversation', (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE conversation_id=".(int)$apple['id']." AND direction='outbound' AND external_message_id='synthetic_reply_1'")->fetchColumn() === 1 && repairEntryIntent($pdo,(int)$apple['id'])['state'] === 'delivered');
repairCheck('contextual handoff has no second generic greeting intent', repairIntent($pdo,(int)$apple['id']) === []);
$pushCount = count($notifications);
repairCheck('same start replay is acknowledged as duplicate', $handle('appleservice39',repairStart(7001))['result'] === 'duplicate');
repairCheck('start replay repeats neither greeting nor notification', count($sent) === 1 && count($notifications) === $pushCount);

$sharedMessage = repairMessage(7001,'shared_mid');
repairCheck('Apple accepts same provider mid as existing AnyTour', $handle('appleservice39',$sharedMessage)['status'] === 200);
repairCheck('second registered repair project accepts same MAX user and mid', $handle('repair_two',$sharedMessage)['status'] === 200);
$second = repairConversation($pdo,'repair_two',7001);
repairCheck('same MAX user has independent project conversation and customer channel', $apple['id'] !== $second['id'] && $apple['customer_channel_id'] !== $second['customer_channel_id']);
repairCheck('provider mid has one inbound row in each project', (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE external_message_id='shared_mid' AND direction='inbound'")->fetchColumn() === 3);
$count = count($notifications);
$messageCount = (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
repairCheck('message replay does not notify or duplicate history', $handle('appleservice39',$sharedMessage)['result'] === 'duplicate' && count($notifications) === $count && (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === $messageCount);

$pdo->exec("UPDATE conversations SET status='manager',manager_id=88 WHERE id=".(int)$apple['id']);
$count = count($sent);
$handle('appleservice39',repairStart(7001,'as39_x_battery',1800000000001));
$owned = repairConversation($pdo,'appleservice39',7001);
repairCheck('explicit fresh repair entry replies without changing assigned manager or routing', $owned['status'] === 'manager' && (int)$owned['manager_id'] === 88 && $owned['source_id'] === $apple['source_id'] && count($sent) === $count+1);
$count = count($sent);
$handle('appleservice39',repairStart(7001,'unknown_payload',1800000000002));
repairCheck('unknown start preserves known model and service', (json_decode((string)repairConversation($pdo,'appleservice39',7001)['metadata_json'],true)['repair_context'] ?? null) === $context['repair_context']);
$handle('appleservice39',repairMessage(7001,'photo_mid',[['type'=>'image','payload'=>['token'=>'synthetic-photo-token']]]));
repairCheck('media archive receives exact project and conversation', end($archives) === ['photo_mid','appleservice39',(int)$apple['id']] && count($sent) === $count);

$handle('appleservice39',repairMessage(7004,'generic_before_entry'));
$generic = repairConversation($pdo,'appleservice39',7004);
$count = count($sent);
$pushCount = count($notifications);
$entry = repairStart(7004,'as39_x_battery',1800000000100);
repairCheck('known repair start upgrades an existing general conversation without typing', $handle('appleservice39',$entry)['status'] === 200 && count($sent) === $count+1);
$upgraded = repairConversation($pdo,'appleservice39',7004);
repairCheck('entry upgrade keeps the same conversation and one handoff', $upgraded['id'] === $generic['id'] && $upgraded['status'] === 'waiting_manager' && (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=".(int)$generic['id']." AND event_type='waiting_manager'")->fetchColumn() === 1 && count($notifications) === $pushCount);
repairCheck('upgraded conversation projects the requested repair context', (json_decode((string)$upgraded['metadata_json'],true)['repair_context'] ?? null) === $context['repair_context']);
repairCheck('entry retry does not repeat its reply or handoff', $handle('appleservice39',$entry)['result'] === 'duplicate' && count($sent) === $count+1 && count($notifications) === $pushCount);
$count = count($sent);
$handle('appleservice39',repairStart(7004,'',1800000000101));
$handle('appleservice39',repairStart(7004,'as39_repair-iphone-16-display',1800000000102));
repairCheck('empty or unknown reentry remains quiet in an existing conversation', count($sent) === $count && count($notifications) === $pushCount);
$newEntry = repairStart(7004,'as39_repair-iphone-15-pro-max-display',1800000000103);
$handle('appleservice39',$newEntry);
$changedContext = json_decode((string)repairConversation($pdo,'appleservice39',7004)['metadata_json'],true)['repair_context'] ?? [];
repairCheck('another reviewed post replaces the visible context and answers that exact repair', count($sent) === $count+1
    && end($sent)['text'] === AppleService39PilotCatalog::responseText($display15,'AppleService39')
    && $changedContext === ['model'=>'iPhone 15 Pro Max','service'=>'Замена дисплея','entry_code'=>$newEntry['payload']]
    && count($notifications) === $pushCount);

foreach ($catalog as $index=>$entry) {
    $result = $handle('appleservice39',repairStart(7100+$index,$entry['start_payload']));
    $row = repairConversation($pdo,'appleservice39',7100+$index);
    repairCheck('reviewed entry sends exact captured answer '.$entry['post_id'], $result['status'] === 200
        && end($sent)['text'] === AppleService39PilotCatalog::responseText($entry,'AppleService39')
        && repairEntryIntent($pdo,(int)$row['id'])['state'] === 'delivered'
        && repairIntent($pdo,(int)$row['id']) === []);
}


$navigationPosts = ['menu','model-iphone-x','model-iphone-15','model-iphone-15-plus','model-iphone-15-pro',
    'model-iphone-15-pro-max','service-battery-replacement','service-front-glass-replacement','service-display'];
foreach ($navigationPosts as $i=>$postId) {
    $entry = AppleService39PilotCatalog::landingEntryForPost($postId);
    $payload = AppleService39PilotCatalog::landingPayloadForPost($postId);
    repairCheck('navigation entry is exact and allowlisted '.$postId, $entry !== null && $payload === 'as39_'.$postId
        && AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot',$payload) === $entry);
    $result = $handle('appleservice39',repairStart(7300+$i,$payload));
    $conversation = repairConversation($pdo,'appleservice39',7300+$i);
    repairCheck('navigation bot entry replies and mirrors in common history '.$postId, $result['status'] === 200
        && $conversation['status'] === 'waiting_manager'
        && end($sent)['text'] === AppleService39PilotCatalog::responseText($entry,'AppleService39')
        && repairEntryIntent($pdo,(int)$conversation['id'])['state'] === 'delivered');
    if ($entry['scope'] === 'model') repairCheck('model-only entry asks about the problem without guessing a service',
        str_contains(end($sent)['text'],$entry['model']) && !preg_match('/[0-9] [0-9]{3} ₽/',end($sent)['text']));
}
$beforeMenu = json_decode((string)repairConversation($pdo,'appleservice39',7001)['metadata_json'],true)['repair_context'];
$handle('appleservice39',repairStart(7001,'as39_menu',1800000099000));
repairCheck('menu consultation preserves previously known device and repair',
    (json_decode((string)repairConversation($pdo,'appleservice39',7001)['metadata_json'],true)['repair_context'] ?? null) === $beforeMenu);
repairCheck('unapproved navigation remains closed',
    AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot','as39_model-iphone-16') === null
    && AppleService39PilotCatalog::resolve('repair_two','id390521129062_bot','as39_menu') === null);

$before = (int)$pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
$ignored = [
    ['update_type'=>'message_callback','callback'=>['user'=>['user_id'=>8001]]],
    array_replace_recursive(repairMessage(8001,'bot_mid'),['message'=>['sender'=>['is_bot'=>true]]]),
    array_replace_recursive(repairMessage(8001,'channel_mid'),['message'=>['recipient'=>['chat_type'=>'channel']]]),
    array_replace_recursive(repairMessage(8001,'group_mid'),['message'=>['recipient'=>['chat_type'=>'chat']]]),
    array_replace_recursive(repairStart(8001),['user'=>['is_bot'=>true]]),
    array_replace(repairStart(8001),['chat_type'=>'channel']),
];
foreach ($ignored as $index=>$event) repairCheck('non-customer/private event ignored '.$index, $handle('appleservice39',$event)['result'] === 'ignored');
repairCheck('ignored events create no conversations', (int)$pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn() === $before);

$registry = new ChannelPostRegistryService($pdo);
$channel = ['project_key'=>'appleservice39','platform'=>'max','bot_key'=>'id390521129062_bot','channel_id'=>'90001','source_key'=>'max:appleservice39-posts','display_name'=>'Synthetic repairs','bot_url'=>'https://max.ru/id390521129062_bot','button_text'=>'Обсудить ремонт','is_enabled'=>true];
$registry->configureChannel($channel);
$publication = $registry->registerPublication('appleservice39','max','id390521129062_bot','-90001','synthetic_post',['text'=>'Synthetic repair publication','title'=>'Repair','post_url'=>'https://max.ru/c/-90001/synthetic_post']);
repairCheck('genuine scoped registry start is accepted', $handle('appleservice39',repairStart(7002,$publication['start_payload']))['status'] === 200);
$postConversation = repairConversation($pdo,'appleservice39',7002);
$managerContext = $registry->managerContext((int)$postConversation['id'],'appleservice39');
repairCheck('genuine publication pointer is visible through existing manager context', $managerContext['current']['publication_id'] === $publication['publication_id'] && (int)$postConversation['source_id'] === 22);
repairCheck('another project cannot resolve or attach Apple publication', $handle('repair_two',repairStart(7002,$publication['start_payload']))['status'] === 200 && $registry->managerContext((int)repairConversation($pdo,'repair_two',7002)['id'],'repair_two')['current']['unavailable']);
$registry->configureChannel(array_replace($channel,['bot_key'=>'id999999999003_bot','channel_id'=>'90003','bot_url'=>'https://max.ru/id999999999003_bot']));
$otherBotPost = $registry->registerPublication('appleservice39','max','id999999999003_bot','-90003','other_bot_post',['text'=>'Synthetic other bot publication']);
$handle('appleservice39',repairStart(7003,$otherBotPost['start_payload']));
repairCheck('same project wrong bot token is unavailable', $registry->managerContext((int)repairConversation($pdo,'appleservice39',7003)['id'],'appleservice39')['current']['unavailable']);

$entryCount = (int)$pdo->query('SELECT COUNT(*) FROM post_entries')->fetchColumn();
$messageCount = (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
$pdo->beginTransaction();
$refused = false;
try { $registry->recordEntry((int)$postConversation['id'],$publication,'outer-default'); } catch (LogicException $error) { $refused = $error->getMessage() === 'channel_registry_requires_own_transaction'; }
repairCheck('registry default still refuses an outer transaction', $refused && $pdo->inTransaction());
repairCheck('entry can explicitly join core transaction', $registry->recordEntry((int)$postConversation['id'],$publication,'outer-joined',true) !== null);
ConversationRecorder::outboundForConversation((int)$postConversation['id'],'max','Synthetic rollback text','system');
$pdo->rollBack();
repairCheck('outer rollback removes both entry and message', (int)$pdo->query('SELECT COUNT(*) FROM post_entries')->fetchColumn() === $entryCount && (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === $messageCount);

$before = (int)$pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
$notifyBefore = count($notifications);
$pdo->exec("CREATE TRIGGER fail_repair_inbound BEFORE INSERT ON messages WHEN NEW.external_message_id='fail_mid' BEGIN SELECT RAISE(ABORT,'synthetic inbound failure'); END;");
repairCheck('failed persistence cannot acknowledge success', $handle('appleservice39',repairMessage(7010,'fail_mid'))['status'] === 503);
repairCheck('failed persistence rolls back identity and suppresses external effects', (int)$pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn() === $before && repairConversation($pdo,'appleservice39',7010) === [] && count($notifications) === $notifyBefore);
$pdo->exec('DROP TRIGGER fail_repair_inbound');
repairCheck('provider retry after storage recovery persists normally', $handle('appleservice39',repairMessage(7010,'fail_mid'))['status'] === 200);

$before = (int)$pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
$sentBefore = count($sent);
$notifyBefore = count($notifications);
$pdo->exec("CREATE TRIGGER fail_repair_entry_capture BEFORE UPDATE ON conversation_events WHEN NEW.event_type='bot_started' AND NEW.payload_json LIKE '%\"repair_entry_reply\":%' BEGIN SELECT RAISE(ABORT,'synthetic entry capture failure'); END;");
repairCheck('entry intent must persist before acknowledgement or external effects', $handle('appleservice39',repairStart(7011))['status'] === 503
    && (int)$pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn() === $before
    && repairConversation($pdo,'appleservice39',7011) === [] && count($sent) === $sentBefore && count($notifications) === $notifyBefore);
$pdo->exec('DROP TRIGGER fail_repair_entry_capture');
repairCheck('entry capture recovery records and sends the original update once', $handle('appleservice39',repairStart(7011))['status'] === 200 && count($sent) === $sentBefore+1);

$constructionFailure = static function (): MaxMessengerAdapter { throw new RuntimeException('synthetic pre-send failure'); };
$pendingStart = repairStart(7020);
repairCheck('failure after core commit before provider attempt returns retryable status', $handle('appleservice39',$pendingStart,$constructionFailure)['status'] === 503);
$pendingConversation = repairConversation($pdo,'appleservice39',7020);
repairCheck('pre-send failure leaves persisted pending entry answer', repairEntryIntent($pdo,(int)$pendingConversation['id'])['state'] === 'pending');
$captured = repairEntryPayload($pdo,(int)$pendingConversation['id']);
$catalogProperty = new ReflectionProperty(AppleService39PilotCatalog::class,'catalog');
$catalogProperty->setAccessible(true);
$catalogBefore = $catalogProperty->getValue();
$changedCatalog = $catalogBefore;
$changedCatalog['repair-iphone-x-battery-replacement']['price_text'] = 'Synthetic changed catalog text';
$catalogProperty->setValue(null,$changedCatalog);
$laterMetadata = json_decode((string)$pendingConversation['metadata_json'],true);
$laterMetadata['repair_context'] = ['model'=>'Later model','service'=>'Later service','entry_code'=>'later'];
$q = $pdo->prepare('UPDATE conversations SET metadata_json=? WHERE id=?');
$q->execute([json_encode($laterMetadata),(int)$pendingConversation['id']]);
$before = count($sent);
$notifyBefore = count($notifications);
repairCheck('same delivery replay recovers only the unsent pending entry answer', $handle('appleservice39',$pendingStart)['result'] === 'duplicate' && count($sent) === $before+1 && count($notifications) === $notifyBefore && repairEntryIntent($pdo,(int)$pendingConversation['id'])['state'] === 'delivered');
$catalogProperty->setValue(null,$catalogBefore);
$mirror = $pdo->query('SELECT text,metadata_json FROM messages WHERE conversation_id='.(int)$pendingConversation['id']." AND direction='outbound' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
repairCheck('pending recovery uses immutable captured text and entry context', end($sent)['text'] === $captured['repair_entry_reply']['text']
    && $mirror['text'] === $captured['repair_entry_reply']['text']
    && (json_decode((string)$mirror['metadata_json'],true)['repair_context'] ?? null) === $captured['repair_context']);

$attempts = 0;
$ambiguous = static function () use (&$attempts): MaxMessengerAdapter {
    return new MaxMessengerAdapter(static function () use (&$attempts): bool { $attempts++; throw new RuntimeException('synthetic ambiguous provider outcome'); },null,'system',null,false);
};
$ambiguousStart = repairStart(7021);
repairCheck('ambiguous provider outcome retains durable inbound queue', $handle('appleservice39',$ambiguousStart,$ambiguous)['status'] === 200);
$ambiguousConversation = repairConversation($pdo,'appleservice39',7021);
repairCheck('ambiguous provider receipt is explicit and has no confirmed outbound mirror', repairEntryIntent($pdo,(int)$ambiguousConversation['id'])['state'] === 'unknown' && (int)$pdo->query('SELECT COUNT(*) FROM messages WHERE conversation_id='.(int)$ambiguousConversation['id'])->fetchColumn() === 0);
$handle('appleservice39',$ambiguousStart,$ambiguous);
repairCheck('unknown provider write is never automatically repeated', $attempts === 1);

$pdo->exec("CREATE TRIGGER fail_repair_outbound BEFORE INSERT ON messages WHEN NEW.direction='outbound' BEGIN SELECT RAISE(ABORT,'synthetic mirror failure'); END;");
$mirrorStart = repairStart(7022);
repairCheck('successful provider send without history receipt returns truthful failure', $handle('appleservice39',$mirrorStart)['status'] === 503);
$mirrorConversation = repairConversation($pdo,'appleservice39',7022);
repairCheck('failed outbound mirror leaves unknown delivery and no false history row', repairEntryIntent($pdo,(int)$mirrorConversation['id'])['state'] === 'unknown' && (int)$pdo->query('SELECT COUNT(*) FROM messages WHERE conversation_id='.(int)$mirrorConversation['id'])->fetchColumn() === 0);
$pdo->exec('DROP TRIGGER fail_repair_outbound');
$before = count($sent);
$handle('appleservice39',$mirrorStart);
repairCheck('history failure replay cannot repeat an already attempted provider send', count($sent) === $before);

$ownedPending = repairStart(7023,'');
$handle('appleservice39',$ownedPending,$constructionFailure);
$ownedPendingConversation = repairConversation($pdo,'appleservice39',7023);
$pdo->exec("UPDATE conversations SET status='manager',manager_id=99 WHERE id=".(int)$ownedPendingConversation['id']);
$before = count($sent);
$handle('appleservice39',$ownedPending);
repairCheck('manager ownership suppresses pending recovery greeting', count($sent) === $before && repairIntent($pdo,(int)$ownedPendingConversation['id'])['state'] === 'suppressed');

$claimRaceSends = 0;
$managerTakesBeforeClaim = static function (ProjectMaxConnection $connection) use ($pdo,&$claimRaceSends): MaxMessengerAdapter {
    $row = repairConversation($pdo,$connection->projectKey(),7024);
    $pdo->exec("UPDATE conversations SET status='manager',manager_id=99 WHERE id=".(int)$row['id']);
    return new MaxMessengerAdapter(static function () use (&$claimRaceSends): string { $claimRaceSends++; return 'unexpected_reply'; },null,'system',null,false);
};
$handle('appleservice39',repairStart(7024,''),$managerTakesBeforeClaim);
repairCheck('manager taking conversation between initial read and delivery claim suppresses send', $claimRaceSends === 0 && repairConversation($pdo,'appleservice39',7024)['status'] === 'manager');

$entryManagerTakes = static function (ProjectMaxConnection $connection) use ($pdo,$factory): MaxMessengerAdapter {
    $row = repairConversation($pdo,$connection->projectKey(),7025);
    $pdo->exec("UPDATE conversations SET status='manager',manager_id=99 WHERE id=".(int)$row['id']);
    return $factory($connection);
};
$before = count($sent);
$handle('appleservice39',repairStart(7025),$entryManagerTakes);
$entryOwned = repairConversation($pdo,'appleservice39',7025);
repairCheck('explicit contextual answer is allowed when manager takes ownership before claim', count($sent) === $before+1
    && $entryOwned['status'] === 'manager' && (int)$entryOwned['manager_id'] === 99
    && repairEntryIntent($pdo,(int)$entryOwned['id'])['state'] === 'delivered');

$oldGeneric = repairMessage(7026,'old_pending_generic');
$handle('appleservice39',$oldGeneric,$constructionFailure);
$genericPending = repairConversation($pdo,'appleservice39',7026);
repairCheck('generic handoff can retain an unsent greeting', repairIntent($pdo,(int)$genericPending['id'])['state'] === 'pending');
$before = count($sent);
$handle('appleservice39',repairStart(7026));
$handle('appleservice39',$oldGeneric);
repairCheck('contextual entry suppresses only the obsolete pending generic answer', count($sent) === $before+1
    && repairEntryIntent($pdo,(int)$genericPending['id'])['state'] === 'delivered'
    && repairIntent($pdo,(int)$genericPending['id'])['state'] === 'suppressed'
    && (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=".(int)$genericPending['id']." AND event_type='waiting_manager'")->fetchColumn() === 1);

$closedStart = repairStart(7027);
$handle('appleservice39',$closedStart,$constructionFailure);
$closedEntry = repairConversation($pdo,'appleservice39',7027);
$pdo->exec("UPDATE conversations SET status='closed' WHERE id=".(int)$closedEntry['id']);
$before = count($sent);
$conversationCount = (int)$pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
$handle('appleservice39',$closedStart);
repairCheck('delayed entry retry after close sends nothing and does not reopen', count($sent) === $before
    && (int)$pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn() === $conversationCount
    && repairEntryIntent($pdo,(int)$closedEntry['id'])['state'] === 'suppressed');
$handle('appleservice39',repairStart(7027,'as39_x_battery',1800000000200));
$reopenedEntry = repairConversation($pdo,'appleservice39',7027);
repairCheck('genuinely new entry after close creates one new contextual handoff', count($sent) === $before+1
    && $reopenedEntry['id'] !== $closedEntry['id'] && $reopenedEntry['status'] === 'waiting_manager'
    && repairEntryIntent($pdo,(int)$reopenedEntry['id'])['state'] === 'delivered');

$reassociatedStart = repairStart(7028);
$handle('appleservice39',$reassociatedStart,$constructionFailure);
$reassociated = repairConversation($pdo,'appleservice39',7028);
$q = $pdo->prepare('UPDATE conversations SET customer_channel_id=? WHERE id=?');
$q->execute([(int)$apple['customer_channel_id'],(int)$reassociated['id']]);
$before = count($sent);
$handle('appleservice39',$reassociatedStart);
repairCheck('pending reply refuses a reassociated customer channel', count($sent) === $before
    && repairEntryIntent($pdo,(int)$reassociated['id'])['state'] === 'suppressed');

$closeBeforeClaim = static function (ProjectMaxConnection $connection) use ($pdo,$factory): MaxMessengerAdapter {
    $row = repairConversation($pdo,$connection->projectKey(),7029);
    $pdo->exec("UPDATE conversations SET status='archived' WHERE id=".(int)$row['id']);
    return $factory($connection);
};
$before = count($sent);
$handle('appleservice39',repairStart(7029),$closeBeforeClaim);
$archived = repairConversation($pdo,'appleservice39',7029);
repairCheck('claim refuses an entry archived after its first eligibility read', count($sent) === $before
    && repairEntryIntent($pdo,(int)$archived['id'])['state'] === 'suppressed');

$afterClaimStart = repairStart(7030);
$handle('appleservice39',$afterClaimStart,$constructionFailure);
$afterClaim = repairConversation($pdo,'appleservice39',7030);
$pdo->exec("CREATE TRIGGER rebind_repair_after_claim AFTER UPDATE ON conversation_events WHEN NEW.conversation_id=".(int)$afterClaim['id']." AND NEW.event_type='bot_started' AND NEW.payload_json LIKE '%\"state\":\"attempted\"%' BEGIN UPDATE conversations SET metadata_json='{\"max_bot_key\":\"id999999999002_bot\"}' WHERE id=NEW.conversation_id; END;");
$before = count($sent);
$handle('appleservice39',$afterClaimStart);
$pdo->exec('DROP TRIGGER rebind_repair_after_claim');
repairCheck('second eligibility read refuses a different bot binding after claim', count($sent) === $before
    && repairEntryIntent($pdo,(int)$afterClaim['id'])['state'] === 'suppressed');

$concurrentStart = repairStart(7031);
$innerResult = null;
$competingClaim = static function (ProjectMaxConnection $connection) use ($handle,$factory,$concurrentStart,&$innerResult): MaxMessengerAdapter {
    $innerResult = $handle('appleservice39',$concurrentStart,$factory);
    return $factory($connection);
};
$before = count($sent);
$notifyBefore = count($notifications);
$handle('appleservice39',$concurrentStart,$competingClaim);
$concurrent = repairConversation($pdo,'appleservice39',7031);
repairCheck('competing replay can win one claim without duplicating send or handoff', ($innerResult['result'] ?? '') === 'duplicate'
    && count($sent) === $before+1 && count($notifications) === $notifyBefore+1
    && repairEntryIntent($pdo,(int)$concurrent['id'])['state'] === 'delivered'
    && (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=".(int)$concurrent['id']." AND event_type='bot_started'")->fetchColumn() === 1
    && (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=".(int)$concurrent['id']." AND event_type='waiting_manager'")->fetchColumn() === 1);

$firstRegionEntry = repairStart(7040,'123456789_region_00054_campaign_42',1800000000997);
repairCheck('registered real service forwards first advertising entry through scoped recorder', $handle('appleservice39',$firstRegionEntry)['status'] === 200
    && (ConversationEntryRegionService::forChat(-7040,'max','appleservice39',$pdo)['yandex_region_id'] ?? '') === '54');
$firstRegionConversation = repairConversation($pdo,'appleservice39',7040);
$pdo->exec("UPDATE conversations SET status='manager',manager_id=88 WHERE id=".(int)$firstRegionConversation['id']);
$beforeRegionEntry = repairConversation($pdo,'appleservice39',7040);
$protectedRegionEntry = array_intersect_key($beforeRegionEntry,array_flip(['source_id','manager_id','status']));
$beforeRegionSends = count($sent);
$regionEntry = repairStart(7040,'123456789_region_00039_campaign_42',1800000000998);
$regionResult=$handle('appleservice39',$regionEntry);
repairCheck('registered real service forwards advertising reentry through scoped recorder', $regionResult['status'] === 200
    && (ConversationEntryRegionService::forChat(-7040,'max','appleservice39',$pdo)['yandex_region_id'] ?? '') === '39');
$regionConversation = repairConversation($pdo,'appleservice39',7040);
$regionMetadata = json_decode((string)$regionConversation['metadata_json'],true);
repairCheck('registered region receipt preserves routing manager and repair context',
    array_intersect_key($regionConversation,array_flip(array_keys($protectedRegionEntry))) === $protectedRegionEntry
    && ($regionMetadata['repair_context'] ?? null) === (json_decode((string)$beforeRegionEntry['metadata_json'],true)['repair_context'] ?? null)
    && $regionConversation['attribution_region'] === '39' && count($sent) === $beforeRegionSends
    && ($regionMetadata['first_entry_region']['yandex_region_id'] ?? '') === '54');
$regionReceiptCount = (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=".(int)$regionConversation['id']." AND event_type='entry_region_observed'")->fetchColumn();
repairCheck('registered entry retry keeps exactly one geography receipt per genuine start', $handle('appleservice39',$regionEntry)['result'] === 'duplicate'
    && $regionReceiptCount === 2
    && (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=".(int)$regionConversation['id']." AND event_type='entry_region_observed'")->fetchColumn() === $regionReceiptCount);
repairCheck('registered advertising geography never crosses into the AnyTour scope',ConversationEntryRegionService::forChat(-7040,'max','anytour',$pdo) === null);

$pdo->exec("UPDATE conversations SET status='closed' WHERE id=".(int)$second['id']);
$before = (int)$pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
$handle('repair_two',$sharedMessage);
repairCheck('old message replay after close cannot reopen a new conversation', (int)$pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn() === $before);
repairCheck('scoped inbound cannot generate AnyTour wizard provenance', !ConversationRecorder::wizardValueApplied(-7001,'country','1'));
repairCheck('global AnyTour configuration remains byte-for-byte unchanged', ProjectConfig::all() === $globalBefore);
repairCheck('existing AnyTour conversation identity and history remain unchanged', $legacyBefore === [
    $pdo->query("SELECT * FROM conversations WHERE project_key='anytour'")->fetchAll(),
    $pdo->query("SELECT * FROM customer_channels WHERE project_key='anytour'")->fetchAll(),
    $pdo->query('SELECT * FROM messages WHERE conversation_id=100')->fetchAll(),
]);
repairCheck('repair ingress never boots legacy MAX wizard class', !class_exists('MaxSearchApi',false));
echo 'TOTAL '.$passed.' passed'.PHP_EOL;
