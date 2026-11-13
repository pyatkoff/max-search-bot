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
    && AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot','as39_repair-iphone-17-ultra-display') === null
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
$handle('appleservice39',repairStart(7004,'as39_repair-iphone-17-ultra-display',1800000000102));
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


$navigationPosts = array_merge((new AppleService39CoverageRevision())->order(), ['menu','model-iphone-x','model-iphone-15','model-iphone-15-plus','model-iphone-15-pro',
    'model-iphone-15-pro-max','service-battery-replacement','service-front-glass-replacement','service-display']);
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
    AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot','as39_model-iphone-999') === null
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

// A second company obtains its entry context from its own immutable server settings.
// Keep the original Apple fixtures and all their delivery/race cases above intact.
$configuredPdo = repairFixture();
$configuredRegistry = repairFixtureRegistry();
$configuredRegistry['repair_two']['project']['business'] = ['profile'=>'device_repair','repair'=>['entries'=>[
    'rx_battery'=>['model'=>'Pixel 8','service'=>'Замена аккумулятора'],
    'rx_display'=>['model'=>'Pixel 8 Pro','service'=>'Замена дисплея'],
]]];
$configuredRegistry['repair_two']['project']['messenger'] = ['max'=>[
    'webhook_url'=>'https://app.anytoour.ru/project_max_webhook.php?project=repair_two',
]];
ProjectMaxConnection::resetForTests($configuredRegistry);
$configuredSent = [];
$configuredNotifications = [];
$configuredFactory = static function (ProjectMaxConnection $connection) use ($configuredPdo,&$configuredSent): MaxMessengerAdapter {
    return new MaxMessengerAdapter(static function ($chat, string $text) use ($connection,$configuredPdo,&$configuredSent): string {
        repairCheck('configured company sends only after the shared transaction commits', !$configuredPdo->inTransaction());
        $configuredSent[] = ['project'=>$connection->projectKey(),'bot'=>$connection->botKey(),'chat'=>$chat,'text'=>$text];
        return 'configured_reply_'.count($configuredSent);
    },null,'system',null,false);
};
$configuredNotify = static function (int $conversationId, string $body, string $mid) use (&$configuredNotifications): void {
    $configuredNotifications[] = [$conversationId,$mid];
};
$configuredHandle = static function (string $project, array $update, ?callable $factory = null) use ($configuredFactory,$configuredNotify): array {
    return RepairMaxWebhookService::handle($project,str_repeat($project === 'appleservice39' ? 'a' : 'b',32),$update,$factory ?? $configuredFactory,$configuredNotify);
};

$configuredStart = repairStart(9401,'rx_battery',1800000100000);
repairCheck('configured profile alias accepts the registered second repair company', $configuredHandle('repair_two',$configuredStart)['status'] === 200);
$configuredSecond = repairConversation($configuredPdo,'repair_two',9401);
$configuredContext = json_decode((string)$configuredSecond['metadata_json'],true)['repair_context'] ?? null;
repairCheck('configured model and service reach the canonical conversation metadata', $configuredContext === [
    'model'=>'Pixel 8','service'=>'Замена аккумулятора','entry_code'=>'rx_battery',
]);
$configuredIntent = repairEntryIntent($configuredPdo,(int)$configuredSecond['id']);
repairCheck('configured entry owns one captured delivered reply with its settings fingerprint',
    ($configuredIntent['state'] ?? '') === 'delivered' && ($configuredIntent['entry_code'] ?? '') === 'rx_battery'
    && preg_match('/\A[a-f0-9]{64}\z/D',(string)($configuredIntent['config_sha256'] ?? '')) === 1
    && repairIntent($configuredPdo,(int)$configuredSecond['id']) === []);
repairCheck('configured answer uses only its own brand model and service', count($configuredSent) === 1
    && $configuredSent[0]['project'] === 'repair_two' && $configuredSent[0]['bot'] === 'id999999999002_bot'
    && str_contains($configuredSent[0]['text'],'Repair Two') && str_contains($configuredSent[0]['text'],'Pixel 8')
    && mb_stripos($configuredSent[0]['text'],'Замена аккумулятора',0,'UTF-8') !== false
    && !str_contains($configuredSent[0]['text'],'AppleService39') && !str_contains($configuredSent[0]['text'],'iPhone')
    && !str_contains($configuredSent[0]['text'],'3 500') && !str_contains($configuredSent[0]['text'],'3 месяца'));
$configuredPushes = count($configuredNotifications);
repairCheck('configured delivery replay reuses one event without another reply or push',
    $configuredHandle('repair_two',$configuredStart)['result'] === 'duplicate' && count($configuredSent) === 1
    && count($configuredNotifications) === $configuredPushes
    && (int)$configuredPdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=".(int)$configuredSecond['id']." AND event_type='bot_started'")->fetchColumn() === 1);

repairCheck('Apple keeps the exact reviewed entry for the same customer and timestamp',
    $configuredHandle('appleservice39',repairStart(9401,'as39_x_battery',1800000100000))['status'] === 200
    && end($configuredSent)['text'] === AppleService39PilotCatalog::responseText($battery,'AppleService39'));
$configuredApple = repairConversation($configuredPdo,'appleservice39',9401);
repairCheck('same MAX customer has independent project identity and repair context',
    $configuredApple['id'] !== $configuredSecond['id']
    && $configuredApple['customer_channel_id'] !== $configuredSecond['customer_channel_id']
    && (json_decode((string)$configuredApple['metadata_json'],true)['repair_context'] ?? null) === [
        'model'=>'iPhone X','service'=>'Замена аккумулятора','entry_code'=>'as39_x_battery',
    ] && (json_decode((string)repairConversation($configuredPdo,'repair_two',9401)['metadata_json'],true)['repair_context'] ?? null) === $configuredContext);
$configuredBefore = count($configuredSent);
$configuredMid = repairMessage(9401,'same-configured-provider-mid');
repairCheck('identical provider message IDs persist once in each configured project',
    $configuredHandle('repair_two',$configuredMid)['status'] === 200
    && $configuredHandle('appleservice39',$configuredMid)['status'] === 200
    && (int)$configuredPdo->query("SELECT COUNT(*) FROM messages WHERE external_message_id='same-configured-provider-mid' AND direction='inbound'")->fetchColumn() === 2);
repairCheck('ordinary messages to both waiting companies remain quiet', count($configuredSent) === $configuredBefore);
repairCheck('foreign secret cannot use a configured company entry',
    RepairMaxWebhookService::handle('repair_two',str_repeat('a',32),repairStart(9410,'rx_battery'),$configuredFactory,$configuredNotify)['status'] === 403
    && repairConversation($configuredPdo,'repair_two',9410) === [] && count($configuredSent) === $configuredBefore);

$configuredPdo->exec("UPDATE conversations SET status='manager',manager_id=88 WHERE id=".(int)$configuredSecond['id']);
$configuredHandle('repair_two',repairStart(9401,'as39_x_battery',1800000100001));
$configuredHandle('repair_two',repairStart(9401,'unregistered_context',1800000100002));
repairCheck('Apple or unknown entry cannot overwrite a configured manager conversation',
    count($configuredSent) === $configuredBefore
    && (json_decode((string)repairConversation($configuredPdo,'repair_two',9401)['metadata_json'],true)['repair_context'] ?? null) === $configuredContext
    && (int)repairConversation($configuredPdo,'repair_two',9401)['manager_id'] === 88);
$configuredHandle('appleservice39',repairStart(9401,'rx_battery',1800000100003));
repairCheck('configured repair payload cannot import another company context into Apple',
    count($configuredSent) === $configuredBefore
    && (json_decode((string)repairConversation($configuredPdo,'appleservice39',9401)['metadata_json'],true)['repair_context']['model'] ?? '') === 'iPhone X');

$managerConnections = [];
ProjectMaxMessengerFactory::resetForTests(static function (ProjectMaxConnection $connection, string $sender) use (&$managerConnections): MaxMessengerAdapter {
    return new MaxMessengerAdapter(static function ($chat, string $text) use ($connection,$sender,&$managerConnections): string {
        $managerConnections[] = [$connection->projectKey(),$connection->botKey(),$connection->token(),$sender,$chat,$text];
        return 'configured_manager_'.count($managerConnections);
    },null,$sender,null,false);
});
try {
    $configuredOwnMessenger = ProjectMaxMessengerFactory::forConversation(repairConversation($configuredPdo,'repair_two',9401),'manager');
    $configuredAppleMessenger = ProjectMaxMessengerFactory::forConversation($configuredApple,'manager');
    repairCheck('both canonical conversations resolve their independent manager transports',
        $configuredOwnMessenger instanceof MaxMessengerAdapter && $configuredAppleMessenger instanceof MaxMessengerAdapter
        && $configuredOwnMessenger->send(-9401,'Synthetic second-company manager answer')
        && $configuredAppleMessenger->send(-9401,'Synthetic Apple manager answer'));
    repairCheck('manager sends retain exact company bot token and sender selection', $managerConnections === [
        ['repair_two','id999999999002_bot','synthetic-second-token','manager',-9401,'Synthetic second-company manager answer'],
        ['appleservice39','id390521129062_bot','synthetic-apple-token','manager',-9401,'Synthetic Apple manager answer'],
    ]);
    $configuredPdo->exec("UPDATE conversations SET metadata_json='{\"max_bot_key\":\"id390521129062_bot\"}' WHERE id=".(int)$configuredSecond['id']);
    repairCheck('another saved bot binding cannot choose the other company transport',
        ProjectMaxMessengerFactory::forConversation(repairConversation($configuredPdo,'repair_two',9401),'manager') === null
        && count($managerConnections) === 2);
} finally { ProjectMaxMessengerFactory::resetForTests(); }

$pendingConfiguredStart = repairStart(9420,'rx_battery',1800000100020);
repairCheck('configured pre-send failure retains its exact unsent entry',
    $configuredHandle('repair_two',$pendingConfiguredStart,$constructionFailure)['status'] === 503);
$configuredPending = repairConversation($configuredPdo,'repair_two',9420);
$configuredSavedIntent = repairEntryIntent($configuredPdo,(int)$configuredPending['id']);
$configuredBefore = count($configuredSent);
$changedSettings = $configuredRegistry;
$changedSettings['repair_two']['project']['business']['repair']['entries']['rx_battery']['model'] = 'Changed device';
ProjectMaxConnection::resetForTests($changedSettings);
$configuredHandle('repair_two',$pendingConfiguredStart);
repairCheck('changed entry settings cannot dispatch a previously captured pending answer',
    count($configuredSent) === $configuredBefore && repairEntryIntent($configuredPdo,(int)$configuredPending['id']) === $configuredSavedIntent);
$removedSettings = $configuredRegistry;
unset($removedSettings['repair_two']['project']['business']['repair']['entries']['rx_battery']);
ProjectMaxConnection::resetForTests($removedSettings);
$configuredHandle('repair_two',$pendingConfiguredStart);
repairCheck('removed configured entry retains its pending intent without a provider attempt',
    count($configuredSent) === $configuredBefore && repairEntryIntent($configuredPdo,(int)$configuredPending['id']) === $configuredSavedIntent);
ProjectMaxConnection::resetForTests($configuredRegistry);
repairCheck('restoring the exact configuration permits only the original unsent intent',
    $configuredHandle('repair_two',$pendingConfiguredStart)['result'] === 'duplicate'
    && count($configuredSent) === $configuredBefore+1
    && repairEntryIntent($configuredPdo,(int)$configuredPending['id'])['state'] === 'delivered'
    && end($configuredSent)['text'] === $configuredSavedIntent['text']);

$configuredUnknownAttempts = 0;
$configuredAmbiguous = static function () use (&$configuredUnknownAttempts): MaxMessengerAdapter {
    return new MaxMessengerAdapter(static function () use (&$configuredUnknownAttempts): bool {
        $configuredUnknownAttempts++; throw new RuntimeException('Synthetic configured delivery timeout');
    },null,'system',null,false);
};
$configuredUnknownStart = repairStart(9421,'rx_battery',1800000100021);
$configuredHandle('repair_two',$configuredUnknownStart,$configuredAmbiguous);
$configuredUnknown = repairConversation($configuredPdo,'repair_two',9421);
repairCheck('configured ambiguous provider outcome is explicitly unknown',
    $configuredUnknownAttempts === 1 && repairEntryIntent($configuredPdo,(int)$configuredUnknown['id'])['state'] === 'unknown');
ProjectMaxConnection::resetForTests($removedSettings);
$configuredHandle('repair_two',$configuredUnknownStart,$configuredAmbiguous);
ProjectMaxConnection::resetForTests($configuredRegistry);
$configuredHandle('repair_two',$configuredUnknownStart,$configuredAmbiguous);
repairCheck('removed and restored settings cannot replay an attempted configured send',
    $configuredUnknownAttempts === 1 && repairEntryIntent($configuredPdo,(int)$configuredUnknown['id'])['state'] === 'unknown');

$conflictingSettings = $configuredRegistry;
$conflictingSettings['repair_two']['project']['business']['kind'] = 'tourism';
ProjectMaxConnection::resetForTests($conflictingSettings);
$configuredBefore = count($configuredSent);
repairCheck('conflicting repair profile and kind fail before history or transport',
    $configuredHandle('repair_two',repairStart(9430,'rx_battery'))['status'] === 403
    && repairConversation($configuredPdo,'repair_two',9430) === [] && count($configuredSent) === $configuredBefore);
ProjectMaxConnection::resetForTests($configuredRegistry);
repairCheck('configured company tests leave the global AnyTour settings untouched', ProjectConfig::all() === $globalBefore);
// Search-intent successor: isolated fixtures only; no real customer/provider actions.
$searchPosts = [
    'model-iphone-17','repair-iphone-16-display','service-rear-glass-replacement',
    'symptom-not-charging','service-rear-camera-replacement','service-earpiece-replacement',
];
foreach ($searchPosts as $post) {
    $entry = AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot','as39_'.$post);
    repairCheck('finite successor resolves its exact post '.$post, $entry !== null && $entry['post_id'] === $post
        && strlen($entry['start_payload']) <= 64
        && AppleService39PilotCatalog::resolve('repair_two','id390521129062_bot','as39_'.$post) === null
        && AppleService39PilotCatalog::resolve('appleservice39','id999999999002_bot','as39_'.$post) === null);
}
repairCheck('original strict repair catalog remains fifteen entries', AppleService39PilotCatalog::entries() === $catalog);
$family17 = AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot','as39_model-iphone-17');
$familyKnown = AppleService39PilotCatalog::entryContext($family17,
    ['model'=>'iPhone 17 Pro Max','service'=>'Замена дисплея','price'=>3500],'as39_model-iphone-17');
repairCheck('family entry preserves the compatible exact variant without a price', $familyKnown['model'] === 'iPhone 17 Pro Max'
    && $familyKnown['service'] === 'Замена дисплея' && !isset($familyKnown['price'])
    && !str_contains(AppleService39PilotCatalog::contextResponseText($family17,'AppleService39',$familyKnown),'Какая у вас версия'));
$familyUnknown = AppleService39PilotCatalog::entryContext($family17,
    ['model'=>'iPhone X','service'=>'Замена аккумулятора'],'as39_model-iphone-17');
repairCheck('incompatible prior model cannot turn a family into an exact variant or inherit its repair',
    $familyUnknown['model'] === 'Серия iPhone 17' && $familyUnknown['service'] === ''
    && $familyUnknown['model_family'] === 'iphone-17');
$searchPdo = repairFixture();
$searchSent = [];
$searchFactory = static function () use ($searchPdo,&$searchSent): MaxMessengerAdapter {
    return new MaxMessengerAdapter(static function ($chat, string $text) use ($searchPdo,&$searchSent): string {
        repairCheck('new contextual reply still follows the canonical commit', !$searchPdo->inTransaction());
        $searchSent[] = ['chat'=>$chat,'text'=>$text];
        return 'synthetic_search_reply_'.count($searchSent);
    },null,'system',null,false);
};
$searchHandle = static function (array $update, ?callable $transport = null) use ($searchFactory): array {
    return RepairMaxWebhookService::handle('appleservice39',str_repeat('a',32),$update,
        $transport ?? $searchFactory,static function (): void {});
};
$searchHandle(repairStart(9501,'as39_x_battery',1800000200001));
$searchConversation = repairConversation($searchPdo,'appleservice39',9501);
$searchId = (int)$searchConversation['id'];
$searchSavedEvent = repairEntryPayload($searchPdo,$searchId);
$searchSavedEventJson = $searchPdo->query("SELECT payload_json FROM conversation_events WHERE conversation_id=$searchId AND event_type='bot_started' ORDER BY id DESC LIMIT 1")->fetchColumn();
$searchPdo->exec("UPDATE conversations SET status='manager',manager_id=88 WHERE id=$searchId");
$searchHandle(repairStart(9501,'as39_service-display',1800000200002));
$searchConversation = repairConversation($searchPdo,'appleservice39',9501);
$searchContext = json_decode($searchConversation['metadata_json'],true)['repair_context'];
$searchEvent = repairEntryPayload($searchPdo,$searchId);
repairCheck('service entry retains model source and assigned manager in the same dialogue',
    (int)$searchConversation['id'] === $searchId && $searchConversation['status'] === 'manager'
    && (int)$searchConversation['manager_id'] === 88 && (int)$searchConversation['source_id'] === 21
    && $searchContext['model'] === 'iPhone X' && $searchContext['service'] === 'Замена дисплея');
repairCheck('captured event and reply use the same merged context without repeating the model question',
    $searchEvent['repair_context'] === $searchContext && str_contains(end($searchSent)['text'],'iPhone X')
    && !str_contains(end($searchSent)['text'],'Какая у вас модель')
    && !str_contains(end($searchSent)['text'],'3 500'));
$searchOldEvent = $searchPdo->query("SELECT payload_json FROM conversation_events WHERE conversation_id=$searchId AND event_type='bot_started' ORDER BY id ASC LIMIT 1")->fetchColumn();
repairCheck('new source entry never regenerates a historical captured answer', $searchOldEvent === $searchSavedEventJson);
$searchExactStart = repairStart(9501,'as39_repair-iphone-16-display',1800000200003);
$searchHandle($searchExactStart);
$searchContext = json_decode(repairConversation($searchPdo,'appleservice39',9501)['metadata_json'],true)['repair_context'];
repairCheck('explicit model16 replaces the old device without price or free diagnosis inheritance',
    $searchContext === ['model'=>'iPhone 16','service'=>'Замена дисплея','entry_code'=>'as39_repair-iphone-16-display']
    && !str_contains(end($searchSent)['text'],'Какая у вас модель')
    && !str_contains(end($searchSent)['text'],'3 500')
    && !str_contains(end($searchSent)['text'],'бесплат'));
$searchSentBefore = count($searchSent);
$searchHandle($searchExactStart);
repairCheck('same new native start never repeats the outgoing reply', count($searchSent) === $searchSentBefore);
$searchHandle(repairStart(9501,'as39_menu',1800000200004));
$searchAfterMenu = json_decode(repairConversation($searchPdo,'appleservice39',9501)['metadata_json'],true)['repair_context'];
repairCheck('menu preserves current repair and captures the correct model without asking it again',
    $searchAfterMenu === $searchContext && repairEntryPayload($searchPdo,$searchId)['repair_context']['model'] === 'iPhone 16'
    && str_contains(end($searchSent)['text'],'iPhone 16') && !str_contains(end($searchSent)['text'],'Какая у вас модель'));
$searchHandle(repairStart(9501,'as39_symptom-not-charging',1800000200005));
$searchSymptom = repairEntryPayload($searchPdo,$searchId)['repair_context'];
repairCheck('symptom retains the device but neither battery nor connector is diagnosed',
    $searchSymptom['model'] === 'iPhone 16' && $searchSymptom['service'] === 'iPhone не заряжается'
    && $searchSymptom['symptom'] === 'not_charging'
    && !str_contains(end($searchSent)['text'],'Замена аккумулятора')
    && !str_contains(end($searchSent)['text'],'замена разъёма')
    && !str_contains(end($searchSent)['text'],'Какая у вас модель'));
repairCheck('new entries create no second waiting transition or manager assignment',
    (int)$searchPdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=$searchId AND event_type='waiting_manager'")->fetchColumn() === 1
    && (int)repairConversation($searchPdo,'appleservice39',9501)['manager_id'] === 88);
foreach (['model-iphone-17'=>9502,'service-rear-glass-replacement'=>9503,
    'service-rear-camera-replacement'=>9504,'service-earpiece-replacement'=>9505] as $post=>$user) {
    $searchHandle(repairStart($user,'as39_'.$post,1800000200100+$user));
    $conversation = repairConversation($searchPdo,'appleservice39',$user);
    $event = repairEntryPayload($searchPdo,(int)$conversation['id']);
    repairCheck('new broad entry captures nonempty labels for the existing Manager card '.$post,
        ($event['repair_context']['model'] !== '' || $event['repair_context']['service'] !== '')
        && $event['repair_entry_reply']['post_id'] === $post
        && $event['repair_entry_reply']['state'] === 'delivered');
}
$searchUnknownAttempts = 0;
$searchAmbiguous = static function () use (&$searchUnknownAttempts): MaxMessengerAdapter {
    return new MaxMessengerAdapter(static function () use (&$searchUnknownAttempts): bool {
        $searchUnknownAttempts++; throw new RuntimeException('Synthetic new-entry timeout');
    },null,'system',null,false);
};
$searchUnknownStart = repairStart(9510,'as39_service-rear-camera-replacement',1800000201000);
$searchHandle($searchUnknownStart,$searchAmbiguous);
$searchUnknownConversation = repairConversation($searchPdo,'appleservice39',9510);
$searchUnknownIntent = repairEntryIntent($searchPdo,(int)$searchUnknownConversation['id']);
$searchHandle($searchUnknownStart,$searchAmbiguous);
repairCheck('unknown new-entry reply stays on its original attempted intent without replay',
    $searchUnknownAttempts === 1 && $searchUnknownIntent['state'] === 'unknown'
    && repairEntryIntent($searchPdo,(int)$searchUnknownConversation['id']) === $searchUnknownIntent);
repairCheck('search-intent fixtures never change the global project configuration', ProjectConfig::all() === $globalBefore);
// The neutral profile uses the same recorder and handoff, with no sales funnel.
require_once dirname(__DIR__).'/services/ClientBusinessProfile.php';
$neutralPdo=repairFixture();$neutralRegistry=repairFixtureRegistry();
foreach(['studio_north'=>4,'studio_south'=>5] as $project=>$id){
    $neutralRegistry[$project]=['project'=>ClientBusinessProfile::settings('manager_inbox',$project,'Компания '.$project),'max'=>['bot_key'=>$project.'_bot','token'=>'synthetic-token-'.$project,'webhook_secret'=>str_repeat((string)$id,32)]];
    $neutralPdo->prepare('INSERT INTO projects VALUES(?,?,?,1)')->execute([$id,$project,'Компания '.$project]);
    $neutralPdo->prepare("INSERT INTO conversation_sources VALUES(?,?,?,?,'max',1,'manager')")->execute([$id*10,$id,'max:'.$project.'-main','Own company source']);
}
ProjectMaxConnection::resetForTests($neutralRegistry);$neutralSent=[];$neutralNotices=[];
$neutralFactory=static function(ProjectMaxConnection $connection)use(&$neutralSent):MaxMessengerAdapter{
    return new MaxMessengerAdapter(static function($chat,string $text)use($connection,&$neutralSent):string{$neutralSent[]=['project'=>$connection->projectKey(),'text'=>$text];return 'neutral-'.count($neutralSent);},null,'system',null,false);
};
$neutralNotify=static function(int $id)use(&$neutralNotices):void{$neutralNotices[]=$id;};
$neutralHandle=static fn(string $project,string $secret,array $update):array=>RepairMaxWebhookService::handle($project,$secret,$update,$neutralFactory,$neutralNotify);
foreach(['studio_north'=>'4','studio_south'=>'5'] as $project=>$secret){
    repairCheck('neutral profile accepts its own authenticated start '.$project,$neutralHandle($project,str_repeat($secret,32),repairStart(7001,'as39_x_battery'))['status']===200);
    $row=repairConversation($neutralPdo,$project,7001);$metadata=json_decode($row['metadata_json'],true);
    repairCheck('neutral profile enters canonical queue without imported industry context '.$project,$row['status']==='waiting_manager'&&empty($metadata['repair_context'])&&!isset($metadata['sales_funnel']));
    repairCheck('neutral greeting belongs only to the chosen company '.$project,end($neutralSent)['text']==='Здравствуйте! Это Компания '.$project.'.' ."\nРасскажите, что вас интересует. Менеджер увидит ваше сообщение и ответит здесь.");
    $count=count($neutralSent);$neutralHandle($project,str_repeat($secret,32),repairMessage(7001,'shared_mid'));
    repairCheck('ordinary messages keep canonical quiet handoff '.$project,count($neutralSent)===$count);
}
$north=repairConversation($neutralPdo,'studio_north',7001);$south=repairConversation($neutralPdo,'studio_south',7001);
repairCheck('same provider identity creates separate customers as well as conversations',$north['id']!==$south['id']&&$north['customer_id']!==$south['customer_id']&&$north['customer_channel_id']!==$south['customer_channel_id']&&$north['customer_id']!==100);
$beforeCount=count($neutralNotices);$beforeMessages=(int)$neutralPdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
repairCheck('neutral replay cannot duplicate history or notifications',$neutralHandle('studio_north',str_repeat('4',32),repairMessage(7001,'shared_mid'))['result']==='duplicate'&&count($neutralNotices)===$beforeCount&&(int)$neutralPdo->query('SELECT COUNT(*) FROM messages')->fetchColumn()===$beforeMessages);
repairCheck('neutral credentials cannot authorize a second company',$neutralHandle('studio_south',str_repeat('4',32),repairStart(7100))['status']===403&&repairConversation($neutralPdo,'studio_south',7100)===[]);
$neutralRegistry['studio_north']['project']['business']['kind']='unreviewed_industry';ProjectMaxConnection::resetForTests($neutralRegistry);
repairCheck('unsupported profile without its own reviewed funnel stays closed',$neutralHandle('studio_north',str_repeat('4',32),repairStart(7200))['status']===403&&repairConversation($neutralPdo,'studio_north',7200)===[]);
repairCheck('neutral profile never changes the shared legacy configuration',ProjectConfig::all()===$globalBefore);
// Customer-path regression: selecting a service on a reviewed model card must
// not fall back to a generic service entry or ask for that model again.
$pairRevision = new AppleService39CoverageRevision();
$pairCount = 0;
foreach ($pairRevision->posts() as $post) {
    if ($post['kind'] !== 'model') continue;
    foreach (['service-battery-replacement'=>'Замена аккумулятора',
        'service-front-glass-replacement'=>'Замена стекла без дисплея',
        'service-display'=>'Замена дисплея'] as $servicePost=>$serviceName) {
        $payload = AppleService39PilotCatalog::modelServicePayload($post['id'], $servicePost);
        $entry = AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot',(string)$payload);
        $context = $entry ? AppleService39PilotCatalog::entryContext($entry,
            ['model'=>'iPhone X','service'=>'Old diagnosis','price'=>'999999'], $payload) : [];
        $reply = $entry ? AppleService39PilotCatalog::responseText($entry,'AppleService39') : '';
        repairCheck('model service pair preserves only the selected published context '.$post['id'].' '.$servicePost,
            is_string($payload) && strlen($payload) <= 64 && $entry !== null
            && $entry['post_id'] === $post['id'] && $entry['scope'] === 'model_service'
            && $context === ['model'=>$post['model'],'service'=>$serviceName,'entry_code'=>$payload]
            && str_contains($reply, $post['model']) && !str_contains($reply,'Какая у вас модель')
            && !str_contains($reply,'999999') && !isset($entry['price_text']));
        $pairCount++;
    }
}
repairCheck('exactly the existing twenty seven models own eighty one service choices', $pairCount === 81);
foreach ([['menu','service-display'],['model-iphone-15','service-display'],
    ['model-iphone-13','service-home-button-repair'],['model-iphone-99','service-display']] as [$model,$service]) {
    repairCheck('unreviewed model service choice stays unavailable '.$model.' '.$service,
        AppleService39PilotCatalog::modelServicePayload($model,$service) === null);
}
$pairPayload = AppleService39PilotCatalog::modelServicePayload('model-iphone-13','service-battery-replacement');
repairCheck('pair cannot cross company or bot boundaries',
    AppleService39PilotCatalog::resolve('repair_two','id390521129062_bot',$pairPayload) === null
    && AppleService39PilotCatalog::resolve('appleservice39','id999999999002_bot',$pairPayload) === null
    && AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot',$pairPayload.'_extra') === null);
$pairPdo = repairFixture();$pairSent=[];$pairNotices=[];
$pairFactory = static function () use (&$pairSent): MaxMessengerAdapter {
    return new MaxMessengerAdapter(static function ($chat,string $text) use (&$pairSent): string {
        $pairSent[]=$text;return 'isolated_pair_reply_'.count($pairSent);
    },null,'system',null,false);
};
$pairNotify = static function (int $id,string $text,string $mid) use (&$pairNotices): void { $pairNotices[]=$id; };
$pairStart = repairStart(7871,$pairPayload);
$result=RepairMaxWebhookService::handle('appleservice39',str_repeat('a',32),$pairStart,$pairFactory,$pairNotify);
$pairConversation=repairConversation($pairPdo,'appleservice39',7871);
$pairMetadata=json_decode($pairConversation['metadata_json']??'{}',true);
repairCheck('model service start records context and enters the real handoff owner once',
    $result['status']===200 && $pairConversation['status']==='waiting_manager'
    && ($pairMetadata['repair_context']['model']??'')==='iPhone 13'
    && ($pairMetadata['repair_context']['service']??'')==='Замена аккумулятора'
    && count($pairSent)===1 && count($pairNotices)===1
    && repairEntryIntent($pairPdo,(int)$pairConversation['id'])['state']==='delivered');
$result=RepairMaxWebhookService::handle('appleservice39',str_repeat('a',32),$pairStart,$pairFactory,$pairNotify);
repairCheck('same model service entry replay is quiet and retains its receipt',
    $result['result']==='duplicate' && count($pairSent)===1 && count($pairNotices)===1);
$result=RepairMaxWebhookService::handle('appleservice39',str_repeat('a',32),repairMessage(7871,'pair_customer_symptom'),$pairFactory,$pairNotify);
repairCheck('ordinary pair followup stays in the existing queue without another bot question',
    $result['status']===200 && count($pairSent)===1 && count($pairNotices)===2
    && repairConversation($pairPdo,'appleservice39',7871)['id']===$pairConversation['id']);

// The original iPhone 17 search card also owns explicit model/service choices.
// Its old broad family start remains byte-for-byte compatible.
$legacy17 = AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot','as39_model-iphone-17');
repairCheck('legacy iPhone17 family launch keeps the existing scope and no guessed version',
    $legacy17 === ['post_id'=>'model-iphone-17','model'=>'','service'=>'','scope'=>'model_family',
        'model_family'=>'iphone-17','start_payload'=>'as39_model-iphone-17']);
$pair17Pdo=repairFixture();$pair17Sent=[];$pair17Notices=[];
$pair17Factory=static function()use(&$pair17Sent):MaxMessengerAdapter{
    return new MaxMessengerAdapter(static function($chat,string $reply)use(&$pair17Sent):string{
        $pair17Sent[]=$reply;return 'isolated_17_pair_'.count($pair17Sent);
    },null,'system',null,false);
};
$pair17Notify=static function(int $id,string $reply,string $mid)use(&$pair17Notices):void{$pair17Notices[]=$id;};
$pair17User=7900;
foreach(['service-battery-replacement'=>'Замена аккумулятора',
    'service-front-glass-replacement'=>'Замена стекла без дисплея',
    'service-display'=>'Замена дисплея']as$servicePost=>$serviceName){
    $payload=AppleService39PilotCatalog::modelServicePayload('model-iphone-17',$servicePost);
    $entry=is_string($payload)?AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot',$payload):null;
    repairCheck('iPhone17 explicit service preserves only the selected base model '.$servicePost,
        is_string($payload)&&strlen($payload)<=64&&$entry!==null
        &&$entry['post_id']==='model-iphone-17'&&$entry['model']==='iPhone 17'
        &&$entry['scope']==='model_service'&&$entry['service']===$serviceName
        &&AppleService39PilotCatalog::entryContext($entry,['model'=>'iPhone 17 Pro Max','service'=>'old','price'=>'999999'],$payload)
          ===['model'=>'iPhone 17','service'=>$serviceName,'entry_code'=>$payload]
        &&!isset($entry['price_text']));
    repairCheck('iPhone17 explicit service cannot cross bot or project '.$servicePost,
        is_string($payload)&&AppleService39PilotCatalog::resolve('repair_two','id390521129062_bot',$payload)===null
        &&AppleService39PilotCatalog::resolve('appleservice39','id999999999002_bot',$payload)===null);
    $user=++$pair17User;$start=repairStart($user,(string)$payload);
    $sentBefore=count($pair17Sent);$noticeBefore=count($pair17Notices);
    $result=RepairMaxWebhookService::handle('appleservice39',str_repeat('a',32),$start,$pair17Factory,$pair17Notify);
    $conversation=repairConversation($pair17Pdo,'appleservice39',$user);
    $meta=json_decode($conversation['metadata_json']??'{}',true);
    repairCheck('iPhone17 service reaches the existing entry owner without asking for the model again '.$servicePost,
        $result['status']===200&&($meta['repair_context']['model']??'')==='iPhone 17'
        &&($meta['repair_context']['service']??'')===$serviceName
        &&count($pair17Sent)===$sentBefore+1&&count($pair17Notices)===$noticeBefore+1
        &&str_contains(end($pair17Sent),'iPhone 17')&&str_contains(end($pair17Sent),mb_strtolower($serviceName,'UTF-8'))
        &&!str_contains(end($pair17Sent),'Какая у вас модель')&&!str_contains(end($pair17Sent),'Какая у вас версия')
        &&!str_contains(end($pair17Sent),'999999'));
    $result=RepairMaxWebhookService::handle('appleservice39',str_repeat('a',32),$start,$pair17Factory,$pair17Notify);
    repairCheck('iPhone17 native entry replay stays quiet '.$servicePost,
        $result['result']==='duplicate'&&count($pair17Sent)===$sentBefore+1&&count($pair17Notices)===$noticeBefore+1);
}
repairCheck('unreviewed iPhone17 service remains unavailable',
    AppleService39PilotCatalog::modelServicePayload('model-iphone-17','service-home-button-repair')===null);

echo 'TOTAL '.$passed.' passed'.PHP_EOL;

