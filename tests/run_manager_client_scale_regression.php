<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__);
require_once $root.'/tests/fixtures/client_onboarding_fixture.php';
$private=sys_get_temp_dir().'/m2-company-scale-'.bin2hex(random_bytes(8));
$pdo=new ClientFixturePdo('sqlite::memory:');$registry=clientFixtureBind($pdo,$private);clientFixtureSchema($pdo);
$subscriptions=[];$writes=[];$unknownProject='';$checks=0;$cases=[];$times=[];$started=hrtime(true);
$provider=static function(ProjectMaxConnection $owner,string $method,string $path,array $query,?array $body)use(&$subscriptions,&$writes,&$unknownProject):array{
    $key=$owner->projectKey();
    if($method==='GET'&&$path==='/me')return ['http'=>200,'data'=>['is_bot'=>true,'username'=>$owner->botKey(),'user_id'=>12345]];
    if($method==='GET'&&$path==='/subscriptions')return ['http'=>200,'data'=>['subscriptions'=>$subscriptions[$key]??[]]];
    if($method==='POST'&&$path==='/subscriptions'){
        $writes[$key]=($writes[$key]??0)+1;$subscriptions[$key]=[['url'=>$body['url'],'update_types'=>$body['update_types']]];
        return $key===$unknownProject?['http'=>0,'transport_error'=>true,'data'=>null]:['http'=>200,'data'=>['success'=>true]];
    }
    throw new RuntimeException('Unexpected synthetic provider request');
};
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;};
try{
    $service=new ManagerClientOnboardingService($registry,$provider,'clientFixtureProbe');
    for($i=0;$i<50;$i++){
        $t=hrtime(true);$operation=md5('m2-company-scale-create-'.$i);$key='client_'.substr($operation,0,24);
        $input=['operation_id'=>$operation,'display_name'=>'Synthetic company '.$i,'business_kind'=>$i%2?'manager_inbox':'device_repair','bot_key'=>'scale_company_'.$i.'_bot','token'=>'synthetic-scale-token-'.$i,'materials'=>'Own materials marker '.$i,'staff'=>[['login'=>'scale_staff_'.$i,'name'=>'Synthetic employee '.$i,'password'=>'synthetic-password-'.$i]]];
        $created=$service->create(1,$input);$check(($created['state']??'')==='created','create '.$i);$id=$created['project_id'];$employee=$created['manager_ids'][0];$connection=ProjectMaxConnection::resolve($key);
        $check($connection!==null&&$connection->token()===$input['token']&&$connection->settings()->get('brand.name')===$input['display_name'],'own registry '.$i);
        $check($service->detail($employee,$id)['client']['materials']===$input['materials'],'own materials '.$i);
        $list=$service->listing($employee);$check(count($list['clients'])===1&&$list['clients'][0]['id']===$id,'own listing '.$i);
        $check(!ProjectAccessService::canAccess($employee,'anytour')&&count(ProjectAccessService::projectsForManager($employee))===1,'own membership '.$i);
        $check($service->create(1,$input)===$created,'immutable receipt '.$i);
        $cases[]=['input'=>$input,'result'=>$created,'key'=>$key];$times[]=(hrtime(true)-$t)/1e6;
    }
    $check(!$writes,'creation never activates provider');
    for($i=0;$i<50;$i++){
        $own=$cases[$i];$other=$cases[($i+1)%50];
        $check($service->detail($own['result']['manager_ids'][0],$other['result']['project_id'])['error']==='not_found','foreign card '.$i);
        $ownConnection=ProjectMaxConnection::resolve($own['key']);$otherConnection=ProjectMaxConnection::resolve($other['key']);
        $check(ProjectMaxConnection::authenticate($other['key'],$ownConnection->webhookSecret())===null,'foreign secret '.$i);
        $check($ownConnection->token()!==$otherConnection->token(),'independent keys '.$i);
    }
    $before=(int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();$duplicate=$cases[0]['input'];$duplicate['operation_id']=md5('m2-scale-duplicate');$duplicate['staff'][0]['login']='new_duplicate_staff';
    $check($service->create(1,$duplicate)['error']==='duplicate_client'&&(int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn()===$before,'duplicate bot rolls back complete directory');
    $first=$cases[1];$second=$cases[3];$unknownProject=$first['key'];
    $check($service->verify(1,$first['result']['project_id'])['state']==='planned','unknown setup verified');$op=md5('m2-scale-unknown');$unknown=$service->activate(1,$first['result']['project_id'],$op);
    $check(($unknown['state']??'')==='unknown'&&$writes[$first['key']]===1,'one simulated UNKNOWN');
    $check($service->activate(1,$first['result']['project_id'],md5('m2-scale-no-replay'))['error']==='activation_pending'&&$writes[$first['key']]===1,'UNKNOWN never repeats');
    $check($service->verify(1,$second['result']['project_id'])['state']==='planned','other company can verify');
    $active=$service->activate(1,$second['result']['project_id'],md5('m2-scale-other-active'));
    $check(($active['state']??'')==='active'&&$writes[$second['key']]===1&&$service->receipt(1,$op)===$unknown,'other activation independent of UNKNOWN');
    // Extend only this disposable SQLite schema for the actual incoming/read/template path.
    require_once $root.'/services/RepairMaxWebhookService.php';require_once $root.'/services/ManagerConversationService.php';require_once $root.'/services/ManagerQuickReplyService.php';
    require_once $root.'/tests/fixtures/channel_post_registry_fixture.php';
    $pdo->sqliteCreateFunction('NOW',static fn():string=>gmdate('Y-m-d H:i:s'));
    foreach(['customer_id INTEGER','customer_channel_id INTEGER','source_id INTEGER','channel TEXT','external_chat_id TEXT','status TEXT','manager_id INTEGER','entry_channel TEXT','attribution_region TEXT','attribution_campaign TEXT','last_message_at TEXT','metadata_json TEXT','lead_stage_key TEXT','started_at TEXT','closed_at TEXT']as$column)$pdo->exec('ALTER TABLE conversations ADD COLUMN '.$column);
    foreach(['primary_group_id INTEGER','fallback_group_id INTEGER','fallback_after_minutes INTEGER']as$column)$pdo->exec('ALTER TABLE conversation_sources ADD COLUMN '.$column);
    $pdo->exec('CREATE TABLE customers(id INTEGER PRIMARY KEY AUTOINCREMENT,display_name TEXT,phone TEXT,email TEXT);
        CREATE TABLE customer_channels(id INTEGER PRIMARY KEY AUTOINCREMENT,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT,username TEXT,metadata_json TEXT,UNIQUE(project_key,channel,external_user_id));
        CREATE TABLE messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,direction TEXT,sender_type TEXT,sender_id TEXT,channel TEXT,external_message_id TEXT,text TEXT,metadata_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE conversation_events(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,event_type TEXT,actor_type TEXT,actor_id TEXT,payload_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE manager_customer_names(customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER);
        CREATE TABLE manager_quick_replies(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_manager_id INTEGER NULL,scope TEXT,title TEXT,body TEXT,category TEXT,version INTEGER,created_by_manager_id INTEGER,updated_by_manager_id INTEGER,created_at TEXT,updated_at TEXT,project_id INTEGER NULL,source_label TEXT NOT NULL DEFAULT "",reviewed_at TEXT NULL,materials_json TEXT NULL);
        CREATE TABLE manager_quick_reply_favorites(manager_id INTEGER,reply_id INTEGER,sort_order INTEGER NOT NULL DEFAULT 0,created_at TEXT,PRIMARY KEY(manager_id,reply_id));
        CREATE TABLE manager_quick_reply_toolbar_states(manager_id INTEGER PRIMARY KEY,revision INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE manager_quick_reply_operations(manager_id INTEGER,operation_id TEXT,request_hash TEXT,result_json TEXT,created_at TEXT,project_id INTEGER NULL,PRIMARY KEY(manager_id,operation_id));
        CREATE TABLE admin_audit_log(actor_manager_id INTEGER,action TEXT,entity_type TEXT,entity_id TEXT,project_key TEXT,before_json TEXT,after_json TEXT)');
    createChannelPostRegistryFixture($pdo);$simulatedMessages=0;$conversationIds=[];$customerIds=[];$journeyStarted=hrtime(true);
    $messenger=static function(ProjectMaxConnection $connection)use(&$simulatedMessages):MaxMessengerAdapter{return new MaxMessengerAdapter(static function()use(&$simulatedMessages):string{return 'synthetic-scale-message-'.++$simulatedMessages;},null,'system',null,false);};
    foreach($cases as $i=>$case){
        $connection=ProjectMaxConnection::resolve($case['key']);$employee=$case['result']['manager_ids'][0];
        // Explicit synthetic employee working state; provisioning itself kept it off.
        $check(!ManagerAuthService::isWorking(ManagerAuthService::byId($employee)),'provisioning keeps employee off shift '.$i);
        $pdo->prepare('UPDATE managers SET is_working=1 WHERE id=?')->execute([$employee]);
        $start=['update_type'=>'bot_started','timestamp'=>1800000000000,'payload'=>'','user'=>['user_id'=>7001,'is_bot'=>false,'name'=>'Synthetic common identity']];
        $check(RepairMaxWebhookService::handle($case['key'],$connection->webhookSecret(),$start,$messenger,static fn()=>null)['status']===200,'actual start '.$i);
        $message=['update_type'=>'message_created','timestamp'=>1800000001000,'message'=>['sender'=>['user_id'=>7001,'is_bot'=>false,'name'=>'Synthetic common identity'],'recipient'=>['chat_type'=>'dialog','chat_id'=>123456],'body'=>['mid'=>'scale-shared-provider-mid','text'=>'Own company question '.$i,'attachments'=>[]]]];
        $check(RepairMaxWebhookService::handle($case['key'],$connection->webhookSecret(),$message,$messenger,static fn()=>null)['status']===200,'actual inbound '.$i);
        $q=$pdo->prepare('SELECT id,customer_id FROM conversations WHERE project_key=?');$q->execute([$case['key']]);$conversation=$q->fetch();$conversationIds[]=$conversation['id'];$customerIds[]=$conversation['customer_id'];
        $own=ManagerConversationService::visibleConversation((int)$conversation['id'],$employee);
        $check($own!==null&&$own['project_key']===$case['key']&&$own['status']==='waiting_manager','own employee cabinet '.$i);
        $check(ManagerConversationService::visibleConversation((int)$conversation['id'],$cases[($i+1)%50]['result']['manager_ids'][0])===null,'foreign employee cabinet '.$i);
        $prepared='Prepared answer '.$i.' — '.$service->detail($employee,$case['result']['project_id'])['client']['materials'];
        $reply=ManagerQuickReplyService::mutate($employee,false,'create',['operation_id'=>md5('scale-prepared-'.$i),'project_key'=>$case['key'],'scope'=>'personal','title'=>'Own prepared response','body'=>$prepared]);
        $check(!empty($reply['ok'])&&$reply['reply']['body']===$prepared,'prepare own response '.$i);
        $list=ManagerQuickReplyService::list($employee,false,['project_key'=>$case['key']]);$check(count($list['replies'])===1&&$list['replies'][0]['body']===$prepared,'only own response '.$i);
        $check(RepairMaxWebhookService::handle($case['key'],$connection->webhookSecret(),$message,$messenger,static fn()=>null)['result']==='duplicate','inbound replay '.$i);
    }
    $check(count(array_unique($conversationIds))===50&&count(array_unique($customerIds))===50,'50 independent identities and conversations');
    $check((int)$pdo->query("SELECT COUNT(*) FROM messages WHERE direction='inbound' AND external_message_id='scale-shared-provider-mid'")->fetchColumn()===50&&$simulatedMessages===50,'one own inbound and greeting per company');
    $journeySeconds=(hrtime(true)-$journeyStarted)/1e9;
    $employee=$cases[0]['result']['manager_ids'][0];$pdo->prepare('DELETE FROM manager_projects WHERE manager_id=?')->execute([$employee]);
    $check($service->detail($employee,$cases[0]['result']['project_id'])['error']==='not_found'&&count($service->listing($cases[2]['result']['manager_ids'][0])['clients'])===1,'revocation isolated');
    $stored=(int)$pdo->query('SELECT COUNT(*) FROM project_client_connections')->fetchColumn();rename($private.'/master.key',$private.'/saved.key');
    $check(ProjectMaxConnection::resolve($cases[2]['key'])===null&&!file_exists($private.'/master.key')&&(int)$pdo->query('SELECT COUNT(*) FROM project_client_connections')->fetchColumn()===$stored,'missing encryption key never regenerated');rename($private.'/saved.key',$private.'/master.key');
    $check(ProjectMaxConnection::resolve($cases[2]['key'])->token()===$cases[2]['input']['token'],'original key restores original company');
    $sorted=$times;sort($sorted);
    echo json_encode(['ok'=>true,'companies'=>50,'checks'=>$checks,'profiles'=>['device_repair'=>25,'manager_inbox'=>25],'elapsed_seconds'=>(hrtime(true)-$started)/1e9,'create_and_own_read_ms'=>['median'=>$sorted[24],'p95'=>$sorted[47],'max'=>max($sorted)],'journey_seconds'=>$journeySeconds,'independent_conversations'=>count(array_unique($conversationIds)),'independent_customers'=>count(array_unique($customerIds)),'own_prepared_responses'=>50,'simulated_greetings'=>$simulatedMessages,'simulated_provider_writes'=>array_sum($writes),'live_provider_requests'=>0,'production_db_writes'=>0,'scope'=>'Actual create → incoming recorder/handoff → authorized employee conversation projection → own quick-reply preparation. Times exclude human entry and provider latency. Native wizard/tab/reload evidence is separate. Synthetic employee working state is an explicit fixture action, never provisioning or production change.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
}finally{
    ProjectClientRegistry::resetForTests();foreach(['master.key','saved.key']as$file)if(is_file($private.'/'.$file))unlink($private.'/'.$file);if(is_dir($private))rmdir($private);
}
