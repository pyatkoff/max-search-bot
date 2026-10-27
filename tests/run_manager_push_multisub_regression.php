<?php

declare(strict_types=1);

$passed = 0;
$failed = 0;

function mpCheck(string $name, bool $ok): void
{
    global $passed, $failed;
    if ($ok) {
        echo "PASS  {$name}\n";
        $passed++;
        return;
    }
    echo "FAIL  {$name}\n";
    $failed++;
}

$pushSource = (string)file_get_contents(__DIR__ . '/../services/ManagerPushService.php');
$pushMigration = (string)file_get_contents(__DIR__ . '/../migrations/018_manager_push_subscriptions.sql');
$swSource = (string)file_get_contents(__DIR__ . '/../manager/sw.js');
$notificationSource = (string)file_get_contents(__DIR__ . '/../manager/assets/workspace-v2-notifications.js');
$conversationSource = (string)file_get_contents(__DIR__ . '/../manager/assets/workspace-v2-conversation.js');

mpCheck(
    'subscription identity is unique per manager and endpoint',
    strpos($pushMigration, 'UNIQUE KEY uq_manager_endpoint (manager_id,endpoint_hash)') !== false
);
mpCheck(
    'both delivery callers delegate selection to the canonical active connection owner',
    substr_count($pushSource,'ManagerPushConnectionService::activeSubscriptions(')===2
);
mpCheck(
    'one dispatch id is created for one notify call',
    strpos($pushSource, '$dispatchId=self::dispatchId();') !== false
);
mpCheck(
    'priority selection is correlated to push dispatch',
    strpos($pushSource, "'push_selected',['dispatch_id'=>\$dispatchId") !== false
);
mpCheck(
    'push fanout sends once for every selected subscription row with same dispatch id',
    strpos($pushSource, 'foreach($subs as $sub)') !== false
        && strpos($pushSource, 'self::send($sub,(string)$payload,$conversationId,$dispatchId)') !== false
);
mpCheck(
    'push success and missing subscription logs carry dispatch id',
    strpos($pushSource, "'delivery_success',['dispatch_id'=>\$dispatchId") !== false
        && strpos($pushSource, "'no_subscription',['dispatch_id'=>\$dispatchId") !== false
);
mpCheck(
    'push failure paths carry dispatch id',
    strpos($pushSource, "'delivery_exception',['dispatch_id'=>\$dispatchId") !== false
        && strpos($pushSource, "'delivery_failed',['dispatch_id'=>\$dispatchId") !== false
        && strpos($pushSource, "'subscription_expired',['dispatch_id'=>\$dispatchId") !== false
        && strpos($pushSource, "'notify_failed',['dispatch_id'=>\$dispatchId") !== false
);
mpCheck(
    'push payload carries exact conversation id',
    strpos($pushSource, "'conversationId'=>\$conversationId") !== false
);
mpCheck(
    'service worker uses conversation-scoped notification tag',
    strpos($swSource, "'anytour-manager-'+Number(data.conversationId||0)") !== false
);
mpCheck(
    'notification click targets exact conversation id',
    strpos($swSource, 'Number.isSafeInteger(parsedId)&&parsedId>0') !== false
        && strpos($swSource, "targetUrl.hash='conversation='+conversationId") !== false
);
mpCheck(
    'existing manager window receives one open-conversation command then returns',
    strpos($swSource, "if(conversationId)client.postMessage({type:'OPEN_CONVERSATION',conversationId});\n        return;") !== false
        && strpos($swSource, 'workspaceUrl(client.url)') !== false
);
mpCheck(
    'notification click has openWindow fallback only when no window handled it',
    strpos($swSource, 'if(self.clients.openWindow)return self.clients.openWindow(target);') !== false
);
mpCheck(
    'Workspace V2 routes service-worker open command through canonical startup',
    strpos($notificationSource, "data.type==='OPEN_CONVERSATION'") !== false
        && strpos($notificationSource, 'openNotificationConversation?.(Number(data.conversationId))') !== false
);

$openConversation = '';
if (preg_match('/async function open\(id,options=\{\}\)\{(.*?)\}\nasync function refreshLeadData/s', $conversationSource, $m)) {
    $openConversation = (string)$m[1];
}
mpCheck('Workspace V2 conversation open function found for side-effect contract', $openConversation !== '');
mpCheck(
    'opening a conversation is read-only and does not auto-take it',
    $openConversation !== ''
        && strpos($openConversation, "api('detail'") !== false
        && strpos($openConversation, "change('take')") === false
        && strpos($openConversation, "api('take'") === false
);
mpCheck(
    'take remains an explicit manager action',
    strpos($conversationSource, "action('Взять','primary',()=>change('take'))") !== false
);

$total = $passed + $failed;
// Execute the real lifecycle SQL against isolated storage, including adversarial
// captured interleavings. No endpoint is contacted and no native API is invoked.
require_once __DIR__.'/fixtures/manager_push_subscription_fixture.php';
require_once __DIR__.'/../services/ManagerPushConnectionService.php';
require_once __DIR__.'/../services/ManagerPushHealth.php';
$pdo=new ManagerPushSubscriptionHttpFixturePDO('sqlite::memory:');
managerPushSubscriptionFixture($pdo);managerPushSubscriptionFixtureSeed($pdo);
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
$nonce=0;
$mutate=static function(string $action,array $input,int $manager=7) use (&$nonce): array {
    return ManagerPushConnectionService::mutate($manager,$action,['operation_id'=>str_pad(dechex(++$nonce),64,'0',STR_PAD_LEFT)]+$input,'Mozilla/5.0 (Windows NT 10.0) Firefox/141.0');
};
$sub=static fn(string $name,int $manager=7): array=>['endpoint'=>'https://push-fixture.invalid/subscription-'.$name,'keys'=>['p256dh'=>'private-p256dh-'.$manager.'-'.$name,'auth'=>'private-auth-'.$manager.'-'.$name]];
$tokenA=str_repeat('a',64);$tokenB=str_repeat('b',64);$tokenC=str_repeat('c',64);
$foreign=$pdo->query('SELECT * FROM manager_push_subscriptions WHERE manager_id=8 ORDER BY id')->fetchAll();
$managers=$pdo->query('SELECT * FROM managers ORDER BY id')->fetchAll();
$before=$pdo->query('SELECT * FROM manager_push_installations ORDER BY id')->fetchAll();
$pdo->exec('PRAGMA query_only=ON');$list=ManagerPushConnectionService::connections(7,'',$sub('a')['endpoint']);$pdo->exec('PRAGMA query_only=OFF');
mpCheck('own browser list is read-only and marks only the native legacy endpoint',count($list['connections'])===2&&count(array_filter($list['connections'],static fn(array $r): bool=>$r['current']))===1&&$pdo->query('SELECT * FROM manager_push_installations ORDER BY id')->fetchAll()===$before);
mpCheck('list exposes no endpoints keys raw agent hashes or other accounts',!preg_match('/private-|https:|token_hash|endpoint_hash|auth_secret|p256dh/',json_encode($list))&&array_column($list['connections'],'id')===[2,1]);
$a=$mutate('enable_intent',['installation_token'=>$tokenA,'expected_revision'=>1,'subscription'=>$sub('a')]);
$a=$mutate('subscribe',['installation_token'=>$tokenA,'expected_revision'=>2,'subscription'=>$sub('a')]);
$c=$mutate('enable_intent',['installation_token'=>$tokenC,'expected_revision'=>0]);$cid=$c['current_connection']['id'];
$c=$mutate('subscribe',['installation_token'=>$tokenC,'expected_revision'=>1,'subscription'=>$sub('c')]);
$b=$mutate('enable_intent',['installation_token'=>$tokenB,'expected_revision'=>1,'subscription'=>$sub('b')]);
$firstIntent=$b['operation'];
$b=$mutate('subscribe',['installation_token'=>$tokenB,'expected_revision'=>2,'subscription'=>$sub('b')]);
mpCheck('explicit intents and saves preserve A B C as independent connections',$a['ok']&&$b['ok']&&$c['ok']&&count(ManagerPushConnectionService::activeSubscriptions($pdo,[7]))===3);
$captured=ManagerPushConnectionService::activeSubscriptions($pdo,[7]);$capturedB=array_values(array_filter($captured,static fn(array $r): bool=>(int)$r['installation_id']===2))[0];
$repair=$mutate('enable_intent',['installation_token'=>$tokenB,'expected_revision'=>2,'subscription'=>$sub('b')]);
$revoke=$mutate('revoke',['connection_id'=>2,'expected_revision'=>3]);$receipt=$revoke['operation'];
$late=$mutate('subscribe',['installation_token'=>$tokenB,'expected_revision'=>3,'subscription'=>$sub('e2')]);
mpCheck('revoke survives a pending repair completing with a new endpoint',$repair['ok']&&$revoke['ok']&&!$late['ok']&&$late['error']==='subscription_revoked'&&ManagerPushConnectionService::current(7,$tokenB)['enabled']===false);
$active=ManagerPushConnectionService::activeSubscriptions($pdo,[7]);
mpCheck('subsequent fan-out selects A and C only',array_column($active,'endpoint')===[$sub('a')['endpoint'],$sub('c')['endpoint']]);
mpCheck('captured revoked provider work cannot start later',!ManagerPushConnectionService::isCurrent($pdo,$capturedB)&&ManagerPushConnectionService::isCurrent($pdo,$active[0]));
mpCheck('current endpoint and aggregate health exclude remote revocation',ManagerPushHealth::statusForSubscription($pdo,7,$sub('b')['endpoint'])['notification_path_reason']==='subscription_revoked'&&ManagerPushHealth::statusForManager($pdo,7)['subscription_count']===2);
$lastSeen=ManagerPushConnectionService::connections(7,$tokenB)['connections'];$bView=array_values(array_filter($lastSeen,static fn(array $r): bool=>$r['id']===2))[0];
mpCheck('safe row shows disabled current browser with its genuine last contact',$bView['current']&&$bView['status']==='revoked'&&$bView['last_activity_at']!==null&&$bView['browser']==='Firefox · Windows');
$repeat=$mutate('revoke',['connection_id'=>2,'expected_revision'=>3]);
mpCheck('idempotent repeat leaves disabled installation revision unchanged',$repeat['ok']&&$repeat['revision']===$revoke['revision']);
foreach ([['subscription'=>$sub('b')],['subscription'=>$sub('e2')],['installation_token'=>str_repeat('d',64),'expected_revision'=>4,'subscription'=>$sub('e2')]] as $input) {
    $bad=$mutate('subscribe',$input);mpCheck('legacy unversioned or forged save cannot revive B',!$bad['ok']&&ManagerPushConnectionService::current(7,$tokenB)['enabled']===false);
}
$recovered=ManagerPushConnectionService::operation(7,$receipt['id']);$foreignReceipt=ManagerPushConnectionService::operation(8,$receipt['id']);
mpCheck('lost response reconciles the immutable own receipt through a read-only operation',$recovered['found']&&$recovered['operation']===$receipt&&$recovered['current_connection']['enabled']===false&&!$foreignReceipt['found']);
$unknown=ManagerPushConnectionService::operation(7,str_repeat('f',64));mpCheck('unknown operation remains absent without a write or fabricated result',$unknown===['ok'=>true,'found'=>false]);
foreach ([3,99999] as $foreignId) {$bad=$mutate('revoke',['connection_id'=>$foreignId,'expected_revision'=>1]);mpCheck('foreign or missing connection ID returns the same safe refusal',$bad['error']==='not_found'&&$bad['http_status']===404);}
$renewed=$mutate('enable_intent',['installation_token'=>$tokenB,'expected_revision'=>4]);$newRevision=$renewed['revision'];
$renewed=$mutate('subscribe',['installation_token'=>$tokenB,'expected_revision'=>$newRevision,'subscription'=>$sub('e2')]);
mpCheck('a new explicit intent in originating installation enables only its new endpoint',$renewed['ok']&&count(ManagerPushConnectionService::activeSubscriptions($pdo,[7]))===3&&ManagerPushHealth::statusForSubscription($pdo,7,$sub('e2')['endpoint'])['notification_path_usable']);
$beforeLate=$pdo->query('SELECT * FROM manager_push_subscriptions ORDER BY id')->fetchAll();
foreach ([201,500,404,410] as $code) ManagerPushConnectionService::deliveryResult($pdo,$capturedB,$code,'private late provider error');
mpCheck('late old success error 404 and 410 cannot annotate or delete a renewed row',$pdo->query('SELECT * FROM manager_push_subscriptions ORDER BY id')->fetchAll()===$beforeLate);
$staleRevoke=$mutate('revoke',['connection_id'=>2,'expected_revision'=>3]);
mpCheck('stale revoke cannot override a newer explicit re-enable',!$staleRevoke['ok']&&$staleRevoke['error']==='revision_conflict'&&ManagerPushConnectionService::current(7,$tokenB)['enabled']);
$old=ManagerPushConnectionService::operation(7,$firstIntent['id']);
mpCheck('old successful receipt stays immutable and carries current newer state',$old['operation']===$firstIntent&&$old['revision']===2&&$old['current_connection']['revision']===$newRevision);
$changed=ManagerPushConnectionService::mutate(7,'enable_intent',['operation_id'=>$firstIntent['id'],'installation_token'=>$tokenB,'expected_revision'=>999]);
mpCheck('forged reuse of a nonce cannot turn an old receipt into a new command',$changed['error']==='operation_conflict');
$actual=array_values(array_filter(ManagerPushConnectionService::activeSubscriptions($pdo,[7]),static fn(array $r): bool=>(int)$r['installation_id']===2))[0];
ManagerPushConnectionService::deliveryResult($pdo,$actual,410);
mpCheck('a current provider expiry disables only its captured subscription',!ManagerPushConnectionService::isCurrent($pdo,$actual)&&count(ManagerPushConnectionService::activeSubscriptions($pdo,[7]))===2&&ManagerPushConnectionService::current(7,$tokenB)['enabled']);
mpCheck('shared native endpoint on the second manager remains byte-identical',$pdo->query('SELECT * FROM manager_push_subscriptions WHERE manager_id=8 ORDER BY id')->fetchAll()===$foreign&&count(ManagerPushConnectionService::activeSubscriptions($pdo,[8]))===2);
$before=$pdo->query('SELECT * FROM manager_push_installations ORDER BY id')->fetchAll();
$pdo->exec("CREATE TRIGGER fail_receipt BEFORE INSERT ON manager_push_operations BEGIN SELECT RAISE(ABORT,'synthetic receipt failure'); END");
try {$mutate('revoke',['connection_id'=>1,'expected_revision'=>2]);mpCheck('receipt failure raises rather than losing atomicity',false);}catch(PDOException $error){mpCheck('receipt failure rolls back the entire revoke',$pdo->query('SELECT * FROM manager_push_installations ORDER BY id')->fetchAll()===$before);}
$pdo->exec('DROP TRIGGER fail_receipt');
mpCheck('all lifecycle work leaves manager identities shift and access unchanged',$pdo->query('SELECT * FROM managers ORDER BY id')->fetchAll()===$managers);
require_once __DIR__.'/../services/ManagerPushPresentation.php';
$pushConversation=['id'=>42,'display_name'=>'Тестовый клиент','channel'=>'max','project_name'=>'AnyTour','project_key'=>'anytour','source_name'=>'max_anytour_spb','source_key'=>'max_anytour_spb'];
$incoming=static fn(string $text,array $metadata=[]):array=>['id'=>501,'text'=>$text,'metadata_json'=>json_encode($metadata,JSON_UNESCAPED_UNICODE)];
$present=static fn(?array $message=null,string $notice='Новое сообщение в заявке',array $patch=[]):array=>ManagerPushPresentation::conversation(array_replace($pushConversation,$patch),$notice,$message);
$preview=$present($incoming("Здравствуйте!\nПодскажите тур в Египет на двоих."));
mpCheck('customer push shows the actual recorded text instead of a generic message notice',$preview['body']==='Здравствуйте! Подскажите тур в Египет на двоих.');
mpCheck('customer push identifies client and human channel without technical source clutter',$preview['title']==='Тестовый клиент · MAX'&&!str_contains(json_encode($preview),'max_anytour_spb'));
mpCheck('display-only payload preserves the exact click conversation and relative workspace URL',$preview['conversationId']===42&&$preview['url']==='./');
mpCheck('different incoming messages remain distinguishable',$present($incoming('А есть отель с бассейном?'))['body']!==$preview['body']);
mpCheck('Telegram is a human channel label',$present($incoming('Текст'),'notice',['channel'=>'telegram'])['title']==='Тестовый клиент · Telegram');
mpCheck('website is a human channel label',$present($incoming('Текст'),'notice',['channel'=>'website'])['title']==='Тестовый клиент · Сайт');
mpCheck('another project keeps its human brand for the same client',$present($incoming('Текст'),'notice',['project_name'=>'ANEX'])['title']==='Тестовый клиент · MAX · ANEX');
mpCheck('unknown channel and project keys are never invented as display labels',$present(null,'notice',['channel'=>'internal_transport','project_name'=>''])['title']==='Тестовый клиент');
foreach(['Клиенту понравился тур','Не удалось доставить ответ']as$notice)mpCheck('non-message notice retains its meaning: '.$notice,$present(null,$notice)['body']===$notice);
mpCheck('unmatched recorded message keeps an honest notice',$present()['body']==='Новое сообщение в заявке');
mpCheck('empty message keeps an honest notice',$present($incoming("\n\t"))['body']==='Новое сообщение в заявке');
mpCheck('callback metadata cannot expose internal action text',$present($incoming('PRIVATE_ACTION',['type'=>'callback']))['body']==='Новое сообщение в заявке');
mpCheck('invalid metadata does not expose an unknown payload',$present(['text'=>'PRIVATE_UNKNOWN','metadata_json'=>'{broken'])['body']==='Новое сообщение в заявке');
mpCheck('contact has a useful label without publishing raw vcard data',$present($incoming('+70000000000',['type'=>'contact','contact'=>['vcard'=>'PRIVATE_VCARD']]))['body']==='Отправил контакт');
$media=$present($incoming('',['attachments'=>[['type'=>'image','url'=>'PRIVATE_URL'],['type'=>'image'],['type'=>'audio'],['type'=>'file','name'=>'PRIVATE_NAME']]]));
mpCheck('media-only push uses concise canonical attachment labels',$media['body']==='📎 Фото, Аудиосообщение, Файл');
mpCheck('media metadata URLs tokens and filenames never enter the preview',!str_contains(json_encode($media),'PRIVATE_'));
mpCheck('a caption takes priority over the generic attachment label',$present($incoming('Подскажите про этот отель',['attachments'=>[['type'=>'image']]]))['body']==='Подскажите про этот отель');
mpCheck('quoted or linked metadata never replaces the current message',$present($incoming('Мой вопрос',['linked_message'=>['text'=>'PRIVATE_QUOTE']]))['body']==='Мой вопрос');
$long=$present($incoming(str_repeat('🏖️Длинный текст ',80)));
mpCheck('long Unicode message is bounded without broken encoding',mb_strlen($long['body'],'UTF-8')===ManagerPushPresentation::PREVIEW_LENGTH&&str_ends_with($long['body'],'…')&&json_encode($long,JSON_UNESCAPED_UNICODE)!==false);
mpCheck('control characters collapse to a readable single line',$present($incoming("Один\0\t\r\nдва\u{00A0}три"))['body']==='Один два три');

// Execute the event-context lookup on real isolated SQL. Never contact push.
$contextFile=__DIR__.'/../services/ManagerPushContext.php';
if(is_file($contextFile))require_once $contextFile;
require_once __DIR__.'/../services/ConversationRecordClock.php';
$contextDb=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$contextDb->exec('CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,direction TEXT,sender_type TEXT,text TEXT,metadata_json TEXT,created_at TEXT);CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,payload_json TEXT,created_at TEXT)');
$record=static function(array $meta,string $text='Обращение из поиска: Synthetic selected hotel',int $conversation=42,string $at='2026-10-27 18:00:00')use($contextDb):void{
    if(!in_array($meta['type']??'', ['tour_search_inquiry','tour_web_inquiry'],true))$meta=ConversationRecordClock::stamp($meta,strtotime($at.' UTC'));
    $contextDb->prepare("INSERT INTO messages(conversation_id,direction,sender_type,text,metadata_json,created_at) VALUES(?,'inbound','customer',?,?,?)")->execute([$conversation,$text,json_encode($meta),$at]);
};
$event=static function(string $type,array $payload,int $conversation=42,string $at='2026-10-27 18:00:00')use($contextDb):void{
    if($type==='waiting_manager')$payload=ConversationRecordClock::stamp($payload,strtotime($at.' UTC'));
    $contextDb->prepare('INSERT INTO conversation_events(conversation_id,event_type,payload_json,created_at) VALUES(?,?,?,?)')->execute([$conversation,$type,json_encode($payload),$at]);
};
$eventBody=static function(string $notice)use($contextDb,$pushConversation):string{
    $context=class_exists('ManagerPushContext')?ManagerPushContext::forNotice($contextDb,42,$notice):null;
    return ManagerPushPresentation::conversation($pushConversation,$notice,null,$context)['body'];
};
$reset=static function()use($contextDb):void{$contextDb->exec('DELETE FROM messages;DELETE FROM conversation_events');};
$handoff='Новая заявка ждёт менеджера';$searchNotice='Обращение из поиска';$webNotice='Обращение по веб-подборке';
$record(['type'=>'message'],'Нужен отель с детским бассейном');$event('waiting_manager',[]);
mpCheck('first handoff includes the latest actual customer text with honest context',$eventBody($handoff)==='Просит менеджера · Последнее: Нужен отель с детским бассейном');
$contextDb->exec("UPDATE messages SET created_at='2026-10-27 21:00:00';UPDATE conversation_events SET created_at='2026-10-27 15:00:00'");
mpCheck('recording clocks keep handoff context independent of bare DB timezone labels',$eventBody($handoff)==='Просит менеджера · Последнее: Нужен отель с детским бассейном');
$contextDb->exec("UPDATE messages SET metadata_json='{}'");
mpCheck('an unmarked legacy row cannot invent a current handoff instant',$eventBody($handoff)==='Просит подключить менеджера');
mpCheck('a handoff without current usable context states the requested action',ManagerPushPresentation::conversation($pushConversation,$handoff)['body']==='Просит подключить менеджера');
$reset();$record(['type'=>'message'],'PRIVATE_OLD_REQUEST',42,'2026-10-26 18:00:00');$event('waiting_manager',[]);
mpCheck('an older trip message cannot become the new handoff preview',$eventBody($handoff)==='Просит подключить менеджера');
$reset();$record(['type'=>'message'],'PRIVATE_LATER_REQUEST',42,'2026-10-27 18:00:01');$event('waiting_manager',[]);
mpCheck('a concurrent later message cannot replace the handoff context',$eventBody($handoff)==='Просит подключить менеджера');
foreach([['type'=>'callback'],['type'=>'start'],['type'=>'message']]as$meta){$reset();$record($meta,'/start s3_PRIVATE_CAPABILITY');$event('waiting_manager',[]);mpCheck('handoff does not publish commands or callback payloads: '.$meta['type'],$eventBody($handoff)==='Просит подключить менеджера');}
$reset();$record(['type'=>'contact'],'+70000000000');$event('waiting_manager',[]);
mpCheck('contact-driven handoff uses a contact label without raw phone',$eventBody($handoff)==='Просит менеджера · Последнее: Отправил контакт');

require_once __DIR__.'/../services/Search3TourHandoffSnapshot.php';
$snapshot=['binding'=>['record_id'=>'PRIVATE_RECORD','revision'=>3],'observation'=>['schema'=>1,'kind'=>'search3_browser_observation','capturedAt'=>1793124000000,'source'=>'anex',
    'hotel'=>['uiId'=>123,'namespace'=>'search3-ui','name'=>'Synthetic selected hotel','resort'=>'Лара'],
    'trip'=>['origin'=>'Москва','countryUiId'=>'4','departure'=>'2027-06-12','nights'=>7,'stayEnd'=>'2027-06-19','stayEndBasis'=>'shown-stay-end','adults'=>2,'childAges'=>[0,0,12]],
    'stay'=>['room'=>'Family','meal'=>'Всё включено','placement'=>'2+3','operator'=>'ANEX'],
    'flights'=>['state'=>'manager-deferred','outward'=>[],'inward'=>[]],
    'price'=>['amount'=>'231456.50','currency'=>'RUB','state'=>'estimated','description'=>'PRIVATE_PRICE_DETAILS','expiresAt'=>1,'basis'=>'shown-offer','finalPriceVerified'=>false,'authority'=>'browser-observation-only']]];
$searchPayload=['schema'=>1,'handoff_id'=>str_repeat('a',32),'entry_key'=>'PRIVATE_ENTRY_KEY','snapshot'=>$snapshot,'price_at_arrival'=>'expired'];
$searchMeta=['type'=>'tour_search_inquiry','handoff_id'=>str_repeat('a',32)];
$reset();$record($searchMeta);$event('tour_search_inquiry',$searchPayload);$event('waiting_manager',[]);
$expected='Из поиска: Synthetic selected hotel · 12.06.2027 · 7 ноч. · 2 взр. + 3 реб.';
mpCheck('search push shows exact chosen hotel date nights and whole party',$eventBody($searchNotice)===$expected);
mpCheck('first handoff for the same inquiry also shows the chosen tour',$eventBody($handoff)===$expected);
mpCheck('capabilities price provenance and expired amounts never leak into the short preview',!preg_match('/PRIVATE_|231456|handoff|record_id|entry_key/',$eventBody($searchNotice)));
$foreign=$searchPayload;$foreign['snapshot']['observation']['hotel']['name']='PRIVATE_FOREIGN_HOTEL';$event('tour_search_inquiry',$foreign,43);
mpCheck('another conversation cannot supply tour context',$eventBody($searchNotice)===$expected);
$other=$searchPayload;$other['handoff_id']=str_repeat('b',32);$other['snapshot']['observation']['hotel']['name']='PRIVATE_UNRELATED_HOTEL';$event('tour_search_inquiry',$other);
mpCheck('a newer unrelated inquiry cannot replace the exactly bound tour',$eventBody($searchNotice)===$expected);
$contextDb->exec('PRAGMA query_only=ON');$readOnly=$eventBody($searchNotice);$contextDb->exec('PRAGMA query_only=OFF');
mpCheck('event enrichment executes with database writes prohibited',$readOnly===$expected);
$reset();$record($searchMeta);$event('tour_search_inquiry',$other);
mpCheck('missing exact handoff keeps an honest generic notice',$eventBody($searchNotice)===$searchNotice);
$reset();$record($searchMeta);$bad=$searchPayload;$bad['snapshot']['observation']['trip']['departure']='2027-02-30';$event('tour_search_inquiry',$bad);
mpCheck('invalid immutable snapshot cannot invent tour conditions',$eventBody($searchNotice)===$searchNotice);
$reset();$record($searchMeta);$event('tour_search_inquiry',$searchPayload,42,'2026-10-26 18:00:00');
mpCheck('an old event cannot be joined to a new projection',$eventBody($searchNotice)===$searchNotice);
$reset();$record(['type'=>'message'],'Мой новый вопрос');$event('tour_search_inquiry',$searchPayload);
mpCheck('tour notices cannot borrow an ordinary message or historical tour',$eventBody($searchNotice)===$searchNotice);

$webMeta=['type'=>'tour_web_inquiry','publication_id'=>'pub123','link_version'=>4,'variant'=>2];
$webPayload=['schema'=>1,'publication_id'=>'pub123','link_version'=>4,'variant'=>2,'entry_key'=>'PRIVATE_ENTRY','public_offer'=>['hotel'=>'Другой отель','date_from'=>'2027-07-14','nights'=>9,'adults'=>1,'children'=>0,'amount'=>'PRIVATE_AMOUNT','currency'=>'RUB','rationale'=>'PRIVATE_RATIONALE']];
$reset();$record($webMeta,'Обращение из веб-подборки · вариант 2: Другой отель');$event('tour_web_inquiry',$webPayload);
mpCheck('web selection push uses its own selected variant',$eventBody($webNotice)==='Из подборки: Другой отель · 14.07.2027 · 9 ноч. · 1 взр.');
$longWeb=$webPayload;$longWeb['public_offer']['hotel']=str_repeat('Очень длинное название отеля ',15);$reset();$record($webMeta);$event('tour_web_inquiry',$longWeb);
mpCheck('long tour names remain bounded while retaining useful dates and party',mb_strlen($eventBody($webNotice),'UTF-8')<=180&&str_contains($eventBody($webNotice),'14.07.2027')&&str_contains($eventBody($webNotice),'1 взр.'));
foreach(['publication_id'=>'other','link_version'=>5,'variant'=>3]as$key=>$value){$reset();$record($webMeta);$wrong=$webPayload;$wrong[$key]=$value;$event('tour_web_inquiry',$wrong);mpCheck('web inquiry requires exact '.$key,$eventBody($webNotice)===$webNotice);}
$reset();$record($searchMeta);$event('tour_search_inquiry',$searchPayload);
mpCheck('failed delivery and liked-tour notices retain their own semantics',$eventBody('Не удалось доставить ответ')==='Не удалось доставить ответ'&&$eventBody('Клиенту понравился тур')==='Клиенту понравился тур');
$contextDb->exec("UPDATE messages SET metadata_json='{broken'");
mpCheck('malformed recorded metadata preserves the original notice',$eventBody($searchNotice)===$searchNotice);
$reset();$record($searchMeta);$event('tour_search_inquiry',$searchPayload);$contextDb->exec("UPDATE conversation_events SET payload_json='{broken'");
mpCheck('malformed inquiry JSON preserves the original notice',$eventBody($searchNotice)===$searchNotice);
$contextDb->exec('DROP TABLE messages');
mpCheck('context storage failure cannot block the original notification',$eventBody($searchNotice)===$searchNotice);
mpCheck('ordinary exact-message arrivals do not invoke latest-event enrichment',str_contains($pushSource,"if(\$externalMessageId==='')")&&str_contains($pushSource,'ManagerPushContext::forNotice('));
$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
