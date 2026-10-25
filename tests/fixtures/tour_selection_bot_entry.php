<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/TourSelectionBotEntryService.php';

/** Synthetic native entry acceptance. Called by the existing share regression. */
function tourSelectionBotEntryChecks(): void
{
    global $pdo;
    $original=$pdo;$config=ProjectConfig::all();
    $messengerProperty=new ReflectionProperty(IntegrationRegistry::class,'messenger');$originalMessenger=$messengerProperty->getValue();
    $transportGuard=new class implements MessengerInterface {
        public int $calls=0;
        public function send($chatId,string $text): bool {++$this->calls;throw new RuntimeException('Unexpected provider send in bot entry fixture');}
        public function sendWithButtons($chatId,string $text,array $buttons): bool {return $this->send($chatId,$text);}
        public function sendContactRequest($chatId,string $text,string $manualCallback,string $backCallback): bool {return $this->send($chatId,$text);}
    };
    IntegrationRegistry::useMessenger($transportGuard);
    $botConfig=['id'=>'anytour','routing'=>['source_key'=>'max:synthetic-main'],'messenger'=>['telegram'=>['source_key'=>'telegram:synthetic-main']],'channel_posts'=>[
        'telegram'=>['bot_key'=>'synthetic_bot','bot_url'=>'https://t.me/synthetic_bot'],
        'max'=>['bot_key'=>'synthetic_max','bot_url'=>'https://max.ru/synthetic_max'],
    ]];
    $payload=static function(string $url): string {parse_str((string)parse_url($url,PHP_URL_QUERY),$query);return (string)($query['start']??'');};
    $telegram=static function(string $value,int $user=101,int $mid=500): array {
        return TelegramIncomingAdapter::fromUpdate(['update_id'=>$mid,'message'=>[
            'message_id'=>$mid,'chat'=>['id'=>$user,'type'=>'private'],
            'from'=>['id'=>$user,'is_bot'=>false,'first_name'=>'Synthetic visitor'],
            'text'=>'/start '.$value,
        ]])??[];
    };
    $max=static fn(string $value,int $user=202): array=>['platform'=>'max','type'=>'bot_started','start_payload'=>$value,
        'user'=>['chat_id'=>-$user,'external_user_id'=>$user],
        'raw'=>['update_type'=>'bot_started','user'=>['user_id'=>$user],'payload'=>$value]];
    $events=static function(int $conversation): int {global $pdo;$q=$pdo->prepare("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=? AND event_type='tour_web_inquiry'");$q->execute([$conversation]);return (int)$q->fetchColumn();};
    $business=static function(): array {global $pdo;$out=[];foreach(['conversations','managers','messages','manager_conversation_reads','customers','customer_channels']as$table)$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);return $out;};
    $messages=static function(int $conversation): array {global $pdo;$q=$pdo->prepare('SELECT * FROM messages WHERE conversation_id=? ORDER BY id');$q->execute([$conversation]);return $q->fetchAll(PDO::FETCH_ASSOC);};
    try {
        $pdo=deliveryDb();ProjectConfig::resetForTests($botConfig);
        $pdo->exec('CREATE TABLE manager_assignments(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,manager_id INTEGER,assignment_type TEXT,assigned_at TEXT DEFAULT CURRENT_TIMESTAMP,released_at TEXT,metadata_json TEXT);
CREATE TABLE manager_tour_selection_links(conversation_id INTEGER PRIMARY KEY,version INTEGER,token TEXT UNIQUE,publication_id TEXT,is_active INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);
CREATE TABLE manager_tour_selection_link_history(conversation_id INTEGER,version INTEGER,publication_id TEXT,is_active INTEGER,manager_id INTEGER,created_at TEXT,PRIMARY KEY(conversation_id,version));
INSERT INTO customers VALUES(3,"Synthetic forwarded visitor","","");
INSERT INTO customer_channels VALUES(3,3,"anytour","telegram","303","303");
INSERT INTO conversations(id,customer_id,customer_channel_id,project_key,source_id,channel,status,manager_id,started_at,external_chat_id) VALUES(3,3,3,"anytour",1,"telegram","manager",8,"2026-10-21 00:00:00","303");');
        $draft=deliveryDraft('Синтетический отель для точного выбора');
        $other=$draft['offers'][0];$other['id']=str_repeat('b',32);$other['room']='Другой номер';$other['date_from']='2027-06-12';$other['amount']='260000';$draft['offers'][]=$other;
        deliveryCheck('bot entry synthetic draft saves',!empty(deliverySave($draft)['ok']));
        $prepared=ManagerTourSelectionDeliveryService::prepare(7,1,1);$id=$prepared['publication']['id'];cachePublication($id);
        $share=ManagerTourSelectionShareService::change(7,1,0,$id,true);$token=substr($share['share']['path'],strlen('/selection/?t='));
        $before=shareRows();$page=ManagerTourSelectionShareService::read($token);
        deliveryCheck('bot links read does not create conversations events sends or visitor sessions',shareRows()===$before);
        $first=$page['offers'][0]['bot_links'];$second=$page['offers'][1]['bot_links'];
        $tg=$payload($first['telegram']);$tgOther=$payload($second['telegram']);$mx=$payload($second['max']);
        deliveryCheck('configured canonical bots receive compact distinct per-platform exact-variant payloads',str_starts_with($first['telegram'],'https://t.me/synthetic_bot?start=')&&str_starts_with($second['max'],'https://max.ru/synthetic_max?start=')&&strlen($tg)<=64&&strlen($mx)<=64&&$tg!==$tgOther&&$tgOther!==$mx);
        $entry=ManagerTourSelectionShareService::botEntry('telegram',$tgOther);
        deliveryCheck('bot capability resolves the exact second immutable variant',is_array($entry)&&$entry['public_offer']['amount']==='260000'&&$entry['public_offer']['room']==='Другой номер'&&$entry['public_offer']['date_from']==='2027-06-12');
        $private=json_encode($entry['public_offer'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        deliveryCheck('public bot offer projection excludes source callback exact child ages and personal history',!str_contains($private,'child_ages')&&!str_contains($private,'source_url')&&!str_contains($private,'source_label')&&!str_contains($private,'catalog.example.org')&&!str_contains($private,'callback_data')&&!str_contains($private,'conversation_id')&&($entry['public_offer']['children']??null)===2);
        $businessBefore=$business();$nativeBefore=TourChoiceService::forConversation($pdo,1)['offers'];
        $accepted=TourSelectionBotEntryService::accept($telegram($tgOther));
        deliveryCheck('original Telegram client inquiry belongs to its actual native conversation',!empty($accepted['ok'])&&!empty($accepted['changed'])&&$accepted['conversation_id']===1&&$events(1)===1);
        $onceRows=shareRows();
        $again=TourSelectionBotEntryService::accept($telegram($tgOther,101,501));
        deliveryCheck('repeated native entry preserves inquiry message and activity without a duplicate',!empty($again['ok'])&&empty($again['changed'])&&$events(1)===1&&shareRows()===$onceRows);
        $forwarded=TourSelectionBotEntryService::accept($telegram($tgOther,303,502));
        deliveryCheck('forwarded page visitor saves in own existing dialogue without original-client attribution',!empty($forwarded['ok'])&&$forwarded['conversation_id']===3&&$events(3)===1&&$events(1)===1);
        $maxSaved=TourSelectionBotEntryService::accept($max($mx));
        deliveryCheck('verified MAX sender saves in its own MAX dialogue without cross-channel identity guessing',!empty($maxSaved['ok'])&&$maxSaved['conversation_id']===2&&$events(2)===1);
        $projection=TourChoiceService::forConversation($pdo,3);
        deliveryCheck('Manager2 projection retains exact public quote and independently proven recipient',($projection['web_inquiries'][0]['public_offer']['amount']??null)==='260000'&&($projection['web_inquiries'][0]['public_offer']['room']??null)==='Другой номер');
        $safe=json_encode($projection['web_inquiries'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        deliveryCheck('forwarded visitor manager receives no private original-customer fields',!str_contains($safe,'child_ages')&&!str_contains($safe,'source_url')&&!str_contains($safe,'source_label')&&!str_contains($safe,'catalog.example.org')&&!str_contains($safe,'callback_data'));
        deliveryCheck('web entry does not forge native liked or delivered receipts',TourChoiceService::forConversation($pdo,1)['offers']===$nativeBefore&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type IN ('tour_offer_liked','tour_offer_delivered')")->fetchColumn()===0);
        $after=$business();$actionMessages=[];
        foreach ($after['messages'] as $message) if (!in_array($message,$businessBefore['messages'],true)) $actionMessages[]=$message;
        $stableAfter=$after;$stableBefore=$businessBefore;unset($stableAfter['messages'],$stableBefore['messages']);
        foreach ($stableAfter['conversations'] as &$conversation) unset($conversation['last_message_at']);unset($conversation);
        foreach ($stableBefore['conversations'] as &$conversation) unset($conversation['last_message_at']);unset($conversation);
        deliveryCheck('accept adds one labelled inbound projection per inquiry without reads profiles assignment shifts or traffic mutation',count($actionMessages)===3&&$stableAfter===$stableBefore);
        $projectionSafe=true;
        foreach ($actionMessages as $message) {
            $metadata=json_decode($message['metadata_json'],true);
            $projectionSafe=$projectionSafe&&$message['direction']==='inbound'&&$message['sender_type']==='customer'&&$message['external_message_id']===null&&($metadata['type']??null)==='tour_web_inquiry'&&!empty($metadata['native_event_type']);
            $serialized=json_encode($message,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            foreach (['child_ages','source_url','source_label','catalog.example.org','callback_data'] as $secret) $projectionSafe=$projectionSafe&&!str_contains($serialized,$secret);
        }
        deliveryCheck('inbox action projection has verified native sender no invented provider MID and no original private fields',$projectionSafe);
        deliveryCheck('new inquiry advances each actual recipient conversation activity',count(array_filter($after['conversations'],static fn(array $row):bool=>!empty($row['last_message_at'])))===3);
        $handoffCalls=0;$feedbackCalls=0;$crashed=false;
        try {TourSelectionBotEntryService::handle($telegram($tg,303,510),static function()use(&$handoffCalls):void {++$handoffCalls;throw new RuntimeException('synthetic handoff crash');},static function()use(&$feedbackCalls):void {++$feedbackCalls;});}
        catch (RuntimeException $error) {$crashed=$error->getMessage()==='synthetic handoff crash';}
        $savedMessages=$messages(3);
        deliveryCheck('handoff crash occurs after committed inquiry without acknowledgement or lost intent',$crashed&&!$pdo->inTransaction()&&$events(3)===2&&$feedbackCalls===0);
        $resumed=TourSelectionBotEntryService::handle($telegram($tg,303,510),static function()use(&$handoffCalls):void {++$handoffCalls;},static function()use(&$feedbackCalls):void {++$feedbackCalls;});
        deliveryCheck('retry resumes handoff without a second inquiry message or unknown acknowledgement',$resumed&&$handoffCalls===2&&$feedbackCalls===0&&$events(3)===2&&$messages(3)===$savedMessages);
        $unknownFeedback=0;$unknownHandoff=0;
        $unknown=static function()use(&$unknownFeedback):void {++$unknownFeedback;throw new RuntimeException('synthetic unknown feedback');};
        $handedOff=static function()use(&$unknownHandoff):void {++$unknownHandoff;};
        $unknownSaved=TourSelectionBotEntryService::handle($telegram($tg,101,511),$handedOff,$unknown);
        $unknownRows=shareRows();$unknownRetry=TourSelectionBotEntryService::handle($telegram($tg,101,511),$handedOff,$unknown);
        deliveryCheck('unknown success feedback is not sent again and saved quote remains available',$unknownSaved&&$unknownRetry&&$unknownFeedback===1&&$unknownHandoff===2&&$events(1)===2&&shareRows()===$unknownRows);
        $unrecognized=$telegram('ordinary-entry',101,512);$unchanged=shareRows();
        deliveryCheck('unrecognized entry remains with ordinary canonical start owner',!TourSelectionBotEntryService::handle($unrecognized,$handedOff,$unknown)&&shareRows()===$unchanged);
        $before=shareRows();
        $forged=$telegram($tg);$forged['user']['external_user_id']=303;$forged['user']['chat_id']=303;
        $group=$telegram($tg);$group['raw']['message']['chat']=['id'=>-100,'type'=>'supergroup'];
        $foreignBot=$telegram($tg);$foreignBot['text']='/start@wrong_bot '.$tg;$foreignBot['raw']['message']['text']=$foreignBot['text'];
        $badMax=$max($mx);$badMax['raw']['user']['user_id']=999;
        $notStart=$max($mx);$notStart['raw']['update_type']='message_created';
        foreach(['forged normalized Telegram identity'=>$forged,'Telegram group envelope'=>$group,'foreign Telegram bot suffix'=>$foreignBot,'forged MAX identity'=>$badMax,'non-start MAX envelope'=>$notStart]as$name=>$incoming)deliveryCheck('bot entry rejects '.$name,empty(TourSelectionBotEntryService::accept($incoming)['ok']));
        deliveryCheck('rejected native envelopes create no events or business mutation',shareRows()===$before);
        $changedMac=substr($tgOther,0,-1).(substr($tgOther,-1)==='0'?'1':'0');
        deliveryCheck('capability tamper platform mismatch and malformed payload fail closed',ManagerTourSelectionShareService::botEntry('telegram',$changedMac)===null&&ManagerTourSelectionShareService::botEntry('max',$tgOther)===null&&ManagerTourSelectionShareService::botEntry('telegram','ts_bad')===null);
        $pdo->exec('UPDATE conversation_sources SET is_active=0 WHERE id=1');
        deliveryCheck('inactive source cannot accept an exact web inquiry',ManagerTourSelectionShareService::botEntry('telegram',$tg)===null&&empty(TourSelectionBotEntryService::accept($telegram($tg))['ok']));
        $pdo->exec('UPDATE conversation_sources SET is_active=1 WHERE id=1');
        ProjectConfig::resetForTests(array_replace($botConfig,['id'=>'other-project']));
        deliveryCheck('web entry is isolated to server-selected project',ManagerTourSelectionShareService::botEntry('telegram',$tg)===null);
        $wrong=$botConfig;$wrong['channel_posts']['telegram']=['bot_key'=>'changed_bot','bot_url'=>'https://t.me/changed_bot'];ProjectConfig::resetForTests($wrong);
        deliveryCheck('canonical bot identity change invalidates old capability',ManagerTourSelectionShareService::botEntry('telegram',$tg)===null);
        ProjectConfig::resetForTests($botConfig);
        $revoked=ManagerTourSelectionShareService::change(7,1,1,$id,false);
        deliveryCheck('explicit revoke closes bot entry as well as page',!empty($revoked['ok'])&&ManagerTourSelectionShareService::botEntry('telegram',$tg)===null&&empty(TourSelectionBotEntryService::accept($telegram($tg))['ok']));
        $rejectFeedback=[];$rejectHandoff=0;$rejectBusiness=$business();$rejectEvents=$events(1);
        $rejectedAck=static function(array $incoming,string $text)use(&$rejectFeedback):void {$rejectFeedback[]=$text;};
        $rejectedHandoff=static function()use(&$rejectHandoff):void {++$rejectHandoff;};
        TourSelectionBotEntryService::handle($telegram($tgOther,101,513),$rejectedHandoff,$rejectedAck);
        TourSelectionBotEntryService::handle($telegram($tgOther,101,513),$rejectedHandoff,$rejectedAck);
        deliveryCheck('revoked entry gives one truthful rejection without inquiry handoff or trip source reset',count($rejectFeedback)===1&&str_contains($rejectFeedback[0],'не сохранён')&&$rejectHandoff===0&&$events(1)===$rejectEvents&&$business()===$rejectBusiness);
        $reactivated=ManagerTourSelectionShareService::change(7,1,2,$id,true);$newToken=substr($reactivated['share']['path'],strlen('/selection/?t='));
        $activePage=ManagerTourSelectionShareService::read($newToken);$activePayload=$payload($activePage['offers'][1]['bot_links']['telegram']);
        deliveryCheck('reactivation rotates URL capability and old token remains closed',$newToken!==$token&&$activePayload!==$tgOther&&ManagerTourSelectionShareService::botEntry('telegram',$tgOther)===null);
        $changed=$draft;$changed['offers'][1]['amount']='310000';deliverySave($changed,1);$next=ManagerTourSelectionDeliveryService::prepare(7,1,2);$nextId=$next['publication']['id'];cachePublication($nextId);
        ManagerTourSelectionShareService::change(7,1,3,$nextId,true);
        $savedSecond=array_values(array_filter(TourChoiceService::forConversation($pdo,3)['web_inquiries'],static fn(array $item):bool=>$item['variant']===2));
        deliveryCheck('publication update rejects stale variant capability without rewriting past accepted terms',ManagerTourSelectionShareService::botEntry('telegram',$activePayload)===null&&count($savedSecond)===1&&$savedSecond[0]['public_offer']['amount']==='260000');
        $staleRows=$business();$staleEvents=$events(1);$staleFeedback=0;
        $staleAck=static function(array $incoming,string $text)use(&$staleFeedback):void {++$staleFeedback;if (!str_contains($text,'не сохранён'))throw new LogicException('False stale success');throw new RuntimeException('synthetic unknown rejection');};
        TourSelectionBotEntryService::handle($telegram($activePayload,101,514),$rejectedHandoff,$staleAck);
        TourSelectionBotEntryService::handle($telegram($activePayload,101,514),$rejectedHandoff,$staleAck);
        deliveryCheck('stale version rejection is durable once even when acknowledgement outcome is unknown',$staleFeedback===1&&$rejectHandoff===0&&$events(1)===$staleEvents&&$business()===$staleRows);
        // Extend only this fixture to exercise the canonical Recorder, not a stub.
        $pdo->exec('ALTER TABLE conversation_sources ADD COLUMN project_id INTEGER;ALTER TABLE conversation_sources ADD COLUMN source_key TEXT;ALTER TABLE conversation_sources ADD COLUMN channel TEXT;
ALTER TABLE customer_channels ADD COLUMN username TEXT;ALTER TABLE customer_channels ADD COLUMN metadata_json TEXT;
UPDATE conversation_sources SET project_id=1,source_key="telegram:synthetic-main",channel="telegram" WHERE id=1;
INSERT INTO conversation_sources(id,display_name,fallback_mode,is_active,project_id,source_key,channel) VALUES(2,"Synthetic MAX default","none",1,1,"max:synthetic-main","max");');
        $currentPage=ManagerTourSelectionShareService::read($newToken);$newTg=$payload($currentPage['offers'][0]['bot_links']['telegram']);$newMax=$payload($currentPage['offers'][0]['bot_links']['max']);
        $newTargets=[];$newHandoff=static function(array $incoming,array $result)use(&$newTargets):void {$newTargets[$incoming['platform']]=$result['conversation_id'];};
        $newFeedback=[];$newAck=static function(array $incoming,string $text)use(&$newFeedback):void {$newFeedback[]=$text;};
        TourSelectionBotEntryService::handle($telegram($newTg,404,515),$newHandoff,$newAck);
        TourSelectionBotEntryService::handle($max($newMax,505),$newHandoff,$newAck);
        $newConversations=$pdo->query('SELECT c.id,c.channel,c.status,c.manager_id,c.source_id,cc.external_user_id,s.source_key,s.channel AS source_channel FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id JOIN conversation_sources s ON s.id=c.source_id WHERE cc.external_user_id IN ("404","505") ORDER BY c.channel')->fetchAll(PDO::FETCH_ASSOC);
        deliveryCheck('new native visitors create distinct Recorder dialogues using their own canonical channel defaults',count($newConversations)===2&&count($newTargets)===2&&$newConversations[0]['channel']==='max'&&$newConversations[0]['source_key']==='max:synthetic-main'&&$newConversations[0]['source_channel']==='max'&&$newConversations[1]['channel']==='telegram'&&$newConversations[1]['source_key']==='telegram:synthetic-main'&&$newConversations[1]['source_channel']==='telegram');
        deliveryCheck('new forwarded native visitors get separate dialogues assigned to the SAME selection manager',$events($newTargets['telegram'])===1&&$events($newTargets['max'])===1&&(int)$newConversations[0]['manager_id']===7&&(int)$newConversations[1]['manager_id']===7&&$newConversations[0]['status']==='manager'&&$newConversations[1]['status']==='manager');
        deliveryCheck('private bot entry binds the original selection manager without a public manager selector',($entry['contact_manager_id']??null)===7&&!str_contains(json_encode($page,JSON_THROW_ON_ERROR),'contact_manager_id'));
        deliveryCheck('assignment and exact inquiry commit together with truthful per-variant acknowledgements',(int)$pdo->query('SELECT COUNT(*) FROM manager_assignments WHERE manager_id=7 AND assignment_type="selection_referral"')->fetchColumn()===2&&count($newFeedback)===2&&str_contains($newFeedback[0],'сохранено')&&str_contains($newFeedback[1],'сохранено'));
        $reentryBefore=$business();$assignmentsBefore=$pdo->query('SELECT * FROM manager_assignments ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$feedbackCount=count($newFeedback);
        TourSelectionBotEntryService::handle($telegram($newTg,404,515),$newHandoff,$newAck);
        TourSelectionBotEntryService::handle($max($newMax,505),$newHandoff,$newAck);
        deliveryCheck('reentry preserves dialogues inquiries assignment history and unknown acknowledgement without a duplicate',$business()===$reentryBefore&&$pdo->query('SELECT * FROM manager_assignments ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$assignmentsBefore&&count($newFeedback)===$feedbackCount);
        $pdo->exec('UPDATE managers SET is_working=0 WHERE id=7');
        TourSelectionBotEntryService::handle($telegram($newTg,606,516),$newHandoff,$newAck);
        deliveryCheck('dedicated same-manager referral survives an off-shift account without enabling the shift',(int)$pdo->query('SELECT manager_id FROM conversations WHERE external_chat_id="606"')->fetchColumn()===7&&(int)$pdo->query('SELECT is_working FROM managers WHERE id=7')->fetchColumn()===0);
        $pdo->exec('UPDATE managers SET is_working=1 WHERE id=7');
        $beforeForeign=(int)$pdo->query('SELECT manager_id FROM conversations WHERE id=3')->fetchColumn();
        $foreign=TourSelectionBotEntryService::accept($telegram($newTg,303,517));
        deliveryCheck('an existing differently owned visitor is never silently reassigned',$beforeForeign===8&&(int)$pdo->query('SELECT manager_id FROM conversations WHERE id=3')->fetchColumn()===8&&($foreign['selection_manager']['state']??'')==='existing_owner');
        $pdo->exec('UPDATE managers SET is_active=0 WHERE id=7');
        TourSelectionBotEntryService::handle($telegram($newTg,707,518),$newHandoff,$newAck);
        $inactive=TourSelectionBotEntryService::accept($telegram($newTg,707,518));
        deliveryCheck('inactive original manager cannot acquire a visitor but the exact inquiry remains durable',($inactive['selection_manager']['state']??'')==='unavailable'&&$pdo->query('SELECT manager_id FROM conversations WHERE external_chat_id="707"')->fetchColumn()===null&&$events((int)$inactive['conversation_id'])===1);
        $pdo->exec('UPDATE managers SET is_active=1 WHERE id=7');
        $retry=TourSelectionBotEntryService::accept($telegram($newTg,707,518));
        deliveryCheck('a saved unassigned inquiry can recover the same manager once without another inquiry',empty($retry['changed'])&&!empty($retry['selection_manager']['assigned'])&&$events((int)$retry['conversation_id'])===1&&(int)$pdo->query('SELECT COUNT(*) FROM manager_assignments WHERE conversation_id='.(int)$retry['conversation_id'])->fetchColumn()===1);
        $pdo->exec('DELETE FROM manager_projects WHERE manager_id=7');
        TourSelectionBotEntryService::handle($telegram($newTg,808,519),$newHandoff,$newAck);
        $denied=TourSelectionBotEntryService::accept($telegram($newTg,808,519));
        $deniedMessages=$messages((int)$denied['conversation_id']);
        deliveryCheck('revoked manager project access leaves a durable inquiry with an explicit assignment notice',($denied['selection_manager']['state']??'')==='unavailable'&&$pdo->query('SELECT manager_id FROM conversations WHERE external_chat_id="808"')->fetchColumn()===null&&str_contains($deniedMessages[count($deniedMessages)-1]['text'],'Назначение менеджеру подборки требует проверки'));
        $pdo->exec('INSERT INTO manager_projects VALUES(7,1);UPDATE conversation_sources SET primary_group_id=91 WHERE id=2');
        TourSelectionBotEntryService::handle($max($newMax,909),$newHandoff,$newAck);
        $deniedSource=TourSelectionBotEntryService::accept($max($newMax,909));
        deliveryCheck('same-manager referral never bypasses target source group eligibility',($deniedSource['selection_manager']['state']??'')==='unavailable'&&$pdo->query('SELECT manager_id FROM conversations WHERE external_chat_id="-909"')->fetchColumn()===null);
        $pdo->exec('UPDATE conversation_sources SET primary_group_id=0 WHERE id=2;UPDATE conversations SET manager_id=8 WHERE id=1');
        TourSelectionBotEntryService::handle($telegram($newTg,1111,520),$newHandoff,$newAck);
        deliveryCheck('forwarded entry follows the current responsible selection manager after reassignment',(int)$pdo->query('SELECT manager_id FROM conversations WHERE external_chat_id="1111"')->fetchColumn()===8);
        $pdo->exec('UPDATE conversations SET manager_id=7 WHERE id=1');
        $pdo->exec("CREATE TEMP TRIGGER refuse_referral_assignment BEFORE INSERT ON manager_assignments BEGIN SELECT RAISE(ABORT,'synthetic assignment failure'); END");
        $failed=false;
        try{TourSelectionBotEntryService::handle($telegram($newTg,1212,521),$newHandoff,$newAck);}catch(PDOException $error){$failed=str_contains($error->getMessage(),'synthetic assignment failure');}
        $failedId=(int)$pdo->query('SELECT id FROM conversations WHERE external_chat_id="1212"')->fetchColumn();
        deliveryCheck('failed assignment rolls back inquiry projection owner and event together',$failed&&$failedId>0&&$events($failedId)===0&&$pdo->query('SELECT manager_id FROM conversations WHERE id='.$failedId)->fetchColumn()===null&&count($messages($failedId))===1&&(int)$pdo->query('SELECT COUNT(*) FROM manager_assignments WHERE conversation_id='.$failedId)->fetchColumn()===0);
        $pdo->exec('DROP TRIGGER refuse_referral_assignment');
        TourSelectionBotEntryService::handle($telegram($newTg,1212,521),$newHandoff,$newAck);
        deliveryCheck('a known failed transactional assignment retries into one complete same-manager inquiry',$events($failedId)===1&&(int)$pdo->query('SELECT manager_id FROM conversations WHERE id='.$failedId)->fetchColumn()===7&&(int)$pdo->query('SELECT COUNT(*) FROM manager_assignments WHERE conversation_id='.$failedId)->fetchColumn()===1);
        $transactionGuard=false;try {ManagerConversationService::assignSelectionReferralInTransaction(1,2,7);}catch(LogicException $error){$transactionGuard=true;}
        deliveryCheck('referral assignment cannot escape the owning inquiry transaction',$transactionGuard);
        deliveryCheck('bot entry fixture uses injected handoff and feedback without real messenger provider calls',$transportGuard->calls===0);
    } finally {
        $pdo=$original;(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);ProjectConfig::resetForTests($config);$messengerProperty->setValue(null,$originalMessenger);
    }
}
