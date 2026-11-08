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
        repairCheck('provider write sees durable shared manager queue', ($row['status'] ?? '') === 'waiting_manager');
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
repairCheck('bot greeting uses Apple brand and stored repair question', count($sent) === 1 && str_contains($sent[0]['text'],'AppleService39') && str_contains($sent[0]['text'],'iPhone X') && str_contains($sent[0]['text'],'что случилось'));
repairCheck('successful bot reply is recorded against exact Apple conversation', (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE conversation_id=".(int)$apple['id']." AND direction='outbound' AND external_message_id='synthetic_reply_1'")->fetchColumn() === 1 && repairIntent($pdo,(int)$apple['id'])['state'] === 'delivered');
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
repairCheck('fresh reentry preserves assigned manager and routing source silently', $owned['status'] === 'manager' && (int)$owned['manager_id'] === 88 && $owned['source_id'] === $apple['source_id'] && count($sent) === $count);
$handle('appleservice39',repairStart(7001,'unknown_payload',1800000000002));
repairCheck('unknown start preserves known model and service', (json_decode((string)repairConversation($pdo,'appleservice39',7001)['metadata_json'],true)['repair_context'] ?? null) === $context['repair_context']);
$handle('appleservice39',repairMessage(7001,'photo_mid',[['type'=>'image','payload'=>['token'=>'synthetic-photo-token']]]));
repairCheck('media archive receives exact project and conversation', end($archives) === ['photo_mid','appleservice39',(int)$apple['id']] && count($sent) === $count);

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

$constructionFailure = static function (): MaxMessengerAdapter { throw new RuntimeException('synthetic pre-send failure'); };
$pendingStart = repairStart(7020);
repairCheck('failure after core commit before provider attempt returns retryable status', $handle('appleservice39',$pendingStart,$constructionFailure)['status'] === 503);
$pendingConversation = repairConversation($pdo,'appleservice39',7020);
repairCheck('pre-send failure leaves persisted pending greeting', repairIntent($pdo,(int)$pendingConversation['id'])['state'] === 'pending');
$before = count($sent);
$notifyBefore = count($notifications);
repairCheck('same delivery replay recovers only the unsent pending greeting', $handle('appleservice39',$pendingStart)['result'] === 'duplicate' && count($sent) === $before+1 && count($notifications) === $notifyBefore && repairIntent($pdo,(int)$pendingConversation['id'])['state'] === 'delivered');

$attempts = 0;
$ambiguous = static function () use (&$attempts): MaxMessengerAdapter {
    return new MaxMessengerAdapter(static function () use (&$attempts): bool { $attempts++; throw new RuntimeException('synthetic ambiguous provider outcome'); },null,'system',null,false);
};
$ambiguousStart = repairStart(7021);
repairCheck('ambiguous provider outcome retains durable inbound queue', $handle('appleservice39',$ambiguousStart,$ambiguous)['status'] === 200);
$ambiguousConversation = repairConversation($pdo,'appleservice39',7021);
repairCheck('ambiguous provider receipt is explicit and has no confirmed outbound mirror', repairIntent($pdo,(int)$ambiguousConversation['id'])['state'] === 'unknown' && (int)$pdo->query('SELECT COUNT(*) FROM messages WHERE conversation_id='.(int)$ambiguousConversation['id'])->fetchColumn() === 0);
$handle('appleservice39',$ambiguousStart,$ambiguous);
repairCheck('unknown provider write is never automatically repeated', $attempts === 1);

$pdo->exec("CREATE TRIGGER fail_repair_outbound BEFORE INSERT ON messages WHEN NEW.direction='outbound' BEGIN SELECT RAISE(ABORT,'synthetic mirror failure'); END;");
$mirrorStart = repairStart(7022);
repairCheck('successful provider send without history receipt returns truthful failure', $handle('appleservice39',$mirrorStart)['status'] === 503);
$mirrorConversation = repairConversation($pdo,'appleservice39',7022);
repairCheck('failed outbound mirror leaves unknown delivery and no false history row', repairIntent($pdo,(int)$mirrorConversation['id'])['state'] === 'unknown' && (int)$pdo->query('SELECT COUNT(*) FROM messages WHERE conversation_id='.(int)$mirrorConversation['id'])->fetchColumn() === 0);
$pdo->exec('DROP TRIGGER fail_repair_outbound');
$before = count($sent);
$handle('appleservice39',$mirrorStart);
repairCheck('history failure replay cannot repeat an already attempted provider send', count($sent) === $before);

$ownedPending = repairStart(7023);
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
$handle('appleservice39',repairStart(7024),$managerTakesBeforeClaim);
repairCheck('manager taking conversation between initial read and delivery claim suppresses send', $claimRaceSends === 0 && repairConversation($pdo,'appleservice39',7024)['status'] === 'manager');

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
