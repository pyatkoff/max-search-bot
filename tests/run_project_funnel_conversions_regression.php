<?php
declare(strict_types=1);
require_once __DIR__.'/run_project_funnel_regression.php';
require_once dirname(__DIR__).'/services/MaxChannelConversionWorker.php';
function funnelConversionSchema(PDO $pdo):void{
    $pdo->exec("ALTER TABLE conversations ADD COLUMN is_test INTEGER DEFAULT 0;ALTER TABLE conversations ADD COLUMN lead_stage_key TEXT DEFAULT 'new';
CREATE TABLE lead_stage_history(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,to_stage_key TEXT);
CREATE TABLE max_channel_attribution_settings(project_key TEXT PRIMARY KEY,mode TEXT,counter_id TEXT,activated_at_unix INTEGER,message_cursor INTEGER DEFAULT 0);
CREATE TABLE max_channel_conversion_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,project_key TEXT,platform TEXT DEFAULT 'max',conversation_id INTEGER,external_user_id TEXT,counter_id TEXT,target TEXT,transport_target TEXT,event_unix INTEGER,client_id TEXT,tracker_record_id TEXT,tracker_observed_unix INTEGER,state TEXT DEFAULT 'pending',next_attempt_unix INTEGER DEFAULT 0,claim_token TEXT,claimed_at_unix INTEGER,attempts INTEGER DEFAULT 0,upload_id TEXT,upload_status TEXT,last_error TEXT DEFAULT '',funnel_context_json TEXT,funnel_policy_sha256 TEXT,value_rub TEXT,UNIQUE(project_key,conversation_id,target));");
}
if(defined('PROJECT_FUNNEL_STATUS_FIXTURE_ONLY'))return;
$pdo=repairFixture();funnelSchema($pdo);funnelConversionSchema($pdo);$now=time();
$base=ProjectFunnelSettings::load(ProjectMaxConnection::resolve('appleservice39'));
$s=$base;$s['metrika']=['counter_id'=>'99900011','mode'=>'collect','goals'=>['customer_activity'=>'repair_activity','manager_request'=>'repair_request','customer_reply'=>'repair_reply','working'=>'repair_working','offer_sent'=>'repair_offer','booking'=>'repair_booking','won'=>'repair_won','predictive_value'=>'repair_expected_value']];
$s['prediction']=['version'=>1,'model_id'=>'fixture-calibration-v1','evidence_sha256'=>str_repeat('c',64),'validated_at_unix'=>$now-60,'valid_until_unix'=>$now+86400,'base_value_rub'=>'4000.00','stage_probability'=>['manager_request'=>0.1,'working'=>0.25,'offer_sent'=>0.4]];
$s=ProjectFunnelSettings::validate($s,'appleservice39','id390521129062_bot');
$set=static function(array $settings):void{$r=repairFixtureRegistry();$r['appleservice39']['project']['sales_funnel']=$settings;ProjectMaxConnection::resetForTests($r);};$set($s);
$connection=ProjectMaxConnection::resolve('appleservice39');$entry=new ProjectFunnelEntry($pdo,$connection,$s);$policy=ProjectFunnelConversions::policy($s);
funnelCheck('new company has its own opt-in counter and distinct goals',$policy['counter_id']==='99900011'&&$policy['goals']['won']!==$policy['goals']['predictive_value']);
$post='service-display';$offer=$s['posts'][$post]['offer_id'];$start='123456789_region_213_campaign_123_ctx1_'.ProjectFunnelLaunch::context($post,$offer);$input=['init_data'=>funnelSigned($start,$now),'start_param'=>$start,'client_id'=>''];$entry->capture($input,$now);
$factory=static fn(ProjectMaxConnection $c)=>new MaxMessengerAdapter(static fn($chat,$text)=>'synthetic-conversion-reply',null,'system',null,false);
$r=RepairMaxWebhookService::handle('appleservice39',str_repeat('a',32),repairStart(7001,'as39_service-display'),$factory,static function(){});$c=repairConversation($pdo,'appleservice39',7001);
$never=static function(){throw new RuntimeException('network_must_not_run');};
$worker=MaxChannelConversionWorker::tick($pdo,$never,$never,$now+1,null,$never);$stats=$worker['projects'];
funnelCheck('configured project runs when legacy MAX and Telegram are off',$worker['mode']==='off'&&$worker['telegram']['mode']==='off'&&ProjectFunnelConversions::hasActiveProjects($pdo));
funnelCheck('collect mode uses the existing outbox without provider calls',$stats['errors']===0&&$stats['collected']===2&&$stats['accepted']===0);
$rows=$pdo->query("SELECT * FROM max_channel_conversion_outbox ORDER BY id")->fetchAll();
funnelCheck('real handoff and separate configured prediction remain pending for CID',count($rows)===2&&array_column($rows,'state')===['pending','pending']&&$rows[0]['target']==='funnel_manager_request'&&$rows[1]['value_rub']==='400.00');
$firstEvent=$rows[0]['event_unix'];$entry->capture(array_replace($input,['client_id'=>'12345678900012345678']),$now+2);
$stats=ProjectFunnelConversions::tick($pdo,$now+901,$never);
funnelCheck('late CID resolves only the original saved visit',$stats['resolved']===2&&(int)$pdo->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE state='ready' AND client_id='12345678900012345678'")->fetchColumn()===2);
funnelCheck('repeated collection keeps original event time and one stage row',$stats['collected']===0&&$pdo->query('SELECT event_unix FROM max_channel_conversion_outbox ORDER BY id LIMIT 1')->fetchColumn()===$firstEvent);
$pdo->prepare("INSERT INTO messages(conversation_id,direction,sender_type,channel,metadata_json) VALUES(?,?,?,?,?)")->execute([$c['id'],'outbound','manager','max',json_encode(ConversationRecordClock::stamp(['type'=>'message'],$now+2))]);
$pdo->prepare("INSERT INTO messages(conversation_id,direction,sender_type,channel,metadata_json) VALUES(?,?,?,?,?)")->execute([$c['id'],'inbound','customer','max',json_encode(ConversationRecordClock::stamp(['type'=>'message'],$now+3))]);
$pdo->exec("UPDATE conversations SET lead_stage_key='working' WHERE id=".$c['id']);$pdo->prepare('INSERT INTO lead_stage_history(conversation_id,to_stage_key) VALUES(?,?)')->execute([$c['id'],'working']);ProjectFunnelConversions::observeStage((int)$c['id'],'working');ProjectFunnelConversions::observeStage((int)$c['id'],'working');
funnelCheck('committed manager stage has one recording receipt',(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='project_funnel_stage'")->fetchColumn()===1);
$stats=ProjectFunnelConversions::tick($pdo,$now+902,$never);
funnelCheck('customer activity reply and CRM progress use the same queue',$stats['collected']===3&&(int)$pdo->query('SELECT COUNT(*) FROM max_channel_conversion_outbox')->fetchColumn()===5);
$s['metrika']['mode']='send';$set($s);$posts=[];$reads=[];
$transport=static function(string $method,string $url,$body)use(&$posts,&$reads,$s):array{
    if($method==='GET'){$reads[]=$url;if(str_ends_with($url,'/counter/99900011'))return ['http'=>200,'error'=>0,'body'=>json_encode(['counter'=>['id'=>'99900011','permission'=>'edit']])];if(str_ends_with($url,'/goals')){$goals=[];foreach($s['metrika']['goals']as$i=>$target)$goals[]=['id'=>(string)(count($goals)+1),'type'=>'action','conditions'=>[['type'=>'exact','url'=>$target]]];return ['http'=>200,'error'=>0,'body'=>json_encode(['goals'=>$goals])];}return ['http'=>200,'error'=>0,'body'=>json_encode(['uploading'=>['id'=>basename(parse_url($url,PHP_URL_PATH)),'status'=>'PROCESSED','source_quantity'=>1,'line_quantity'=>1]])];}
    $posts[]=[$url,$body];return ['http'=>200,'error'=>0,'body'=>json_encode(['uploading'=>['id'=>(string)(1000+count($posts)),'status'=>'UPLOADED']])];
};
$apiFactory=static fn(array $p)=>new MetrikaOfflineClient($p['counter_id'],'synthetic-project-oauth',$transport,null,$p);
$stats=ProjectFunnelConversions::tick($pdo,$now+903,$apiFactory);
funnelCheck('configured send mode uploads five different stages once',$stats['accepted']===5&&count($posts)===5&&(int)$pdo->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE state='accepted'")->fetchColumn()===5);
funnelCheck('every provider write is scoped to the company counter',count(array_filter($posts,static fn($p)=>str_contains($p[0],'/counter/99900011/')))===5&&!str_contains(json_encode($posts),'98615635'));
$valueCsv=array_values(array_filter($posts,static fn($p)=>str_contains($p[1],'repair_expected_value')))[0][1];
funnelCheck('prediction exports expected value with a separate target and explicit currency',str_contains($valueCsv,'ClientId,Target,DateTime,Price,Currency')&&str_contains($valueCsv,',400.00,RUB'));
$stats=ProjectFunnelConversions::tick($pdo,$now+3000,$apiFactory);$stats=ProjectFunnelConversions::tick($pdo,$now+6000,$apiFactory);
funnelCheck('accepted receipts reconcile without a second upload',count($posts)===5&&(int)$pdo->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE state='processed'")->fetchColumn()===5);
$row=$pdo->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id LIMIT 1')->fetch();$bad=$row;$bad['external_user_id']='7002';funnelCheck('same chat in another scope cannot borrow this identity',ProjectFunnelConversions::identity($pdo,$bad,$s,$now+5)===null);
$bad=$row;$bad['funnel_context_json']=str_replace('service-display','menu',$bad['funnel_context_json']);funnelCheck('another offer cannot borrow this click identity',ProjectFunnelConversions::identity($pdo,$bad,$s,$now+5)===null);
$pdo->exec("UPDATE max_channel_conversion_outbox SET state='uploading',claimed_at_unix=".($now-600).",claim_token='lost' WHERE id=1");(new MaxChannelConversionOutbox($pdo))->recoverProject($policy,$now);
funnelCheck('crash after upload is unknown rather than automatically resent',$pdo->query('SELECT state FROM max_channel_conversion_outbox WHERE id=1')->fetchColumn()==='unknown');
$oldPosts=count($posts);ProjectFunnelConversions::tick($pdo,$now+1,$apiFactory);funnelCheck('unknown receipt recovery never repeats POST',count($posts)===$oldPosts);
$bad=$s;$bad['metrika']['counter_id']='98615635';funnelCheck('new company never falls back to the AnyTour counter',ProjectFunnelConversions::policy($bad)===null);
$bad=$s;$bad['metrika']['goals']['predictive_value']=$bad['metrika']['goals']['won'];funnelCheck('prediction and sale cannot share a goal',funnelReject(fn()=>ProjectFunnelSettings::validate($bad,'appleservice39','id390521129062_bot')));
$bad=$s;$bad['prediction']['stage_probability']['working']=1.2;funnelCheck('uncalibrated probability is rejected',funnelReject(fn()=>ProjectFunnelSettings::validate($bad,'appleservice39','id390521129062_bot')));
funnelCheck('missing expired or sold-case model produces no prediction',ProjectFunnelConversions::estimate(array_replace($policy,['prediction'=>null]),['working'=>$now],$now)===null&&ProjectFunnelConversions::estimate($policy,['working'=>$now,'won'=>$now],$now)===null&&ProjectFunnelConversions::estimate($policy,['working'=>$now],$now+86401)===null);
$s['metrika']['mode']='off';$set($s);$before=$pdo->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll();$stats=ProjectFunnelConversions::tick($pdo,$now+10,$never);
funnelCheck('off mode changes no queued history or provider state',$before===$pdo->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll()&&$stats['projects']===0);
funnelCheck('scheduler fallback is inactive for an explicitly disabled company',!ProjectFunnelConversions::hasActiveProjects($pdo));
$selectedPolicy=['project_key'=>'appleservice39','bot_key'=>'id390521129062_bot','counter_id'=>'42611469','mode'=>'collect','goals'=>['predictive_value'=>'predict'],'goal_ids'=>['predictive_value'=>'680570232'],'goal_match'=>['predictive_value'=>'exact'],'credential_source'=>'platform'];
$goalResponse=['id'=>'680570232','type'=>'action','conditions'=>[['type'=>'contain','url'=>'predict']]];$goalCalls=[];$goalPermission='edit';
$goalFactory=static function(array $policy)use(&$goalResponse,&$goalCalls,&$goalPermission){return new MetrikaOfflineClient($policy['counter_id'],'synthetic-private-oauth',static function($method,$url,$body)use(&$goalResponse,&$goalCalls,&$goalPermission){$goalCalls[]=[$method,$url];return ['http'=>200,'error'=>0,'body'=>json_encode(str_ends_with($url,'/goals')?['goals'=>[$goalResponse]]:['counter'=>['id'=>'42611469','permission'=>$goalPermission]])];},null,$policy);};
$selectedSettings=$base;$selectedSettings['metrika']=$selectedPolicy;
$probe=ProjectFunnelConversions::probe($selectedSettings,$goalFactory);
funnelCheck('pinned contains goal honestly blocks offline admission without rewriting it',$probe===['ok'=>false,'status'=>'goal_condition_unsupported','conversion_requests'=>0]&&$goalCalls===[['GET','https://api-metrika.yandex.net/management/v1/counter/42611469'],['GET','https://api-metrika.yandex.net/management/v1/counter/42611469/goals']]);
$goalResponse['conditions'][0]['type']='exact';funnelCheck('same pinned owner goal with exact condition passes read-only admission',ProjectFunnelConversions::probe($selectedSettings,$goalFactory)===['ok'=>true,'status'=>'ready','conversion_requests'=>0]);
$unsupported=$selectedPolicy;$unsupported['goal_match']['predictive_value']='contain';$callsBefore=count($goalCalls);funnelCheck('explicit contains policy cannot upload even through a direct client call',!$goalFactory($unsupported)->projectGoalsReady()&&funnelReject(fn()=>$goalFactory($unsupported)->upload(['counter_id'=>'42611469','project_key'=>'appleservice39','conversation_id'=>'11','target'=>'funnel_predictive_value','transport_target'=>'predict','client_id'=>'123456789','event_unix'=>$now,'value_rub'=>'1.00'],$now))&&array_filter(array_slice($goalCalls,$callsBefore),static fn($call)=>$call[0]!=='GET')===[]);
$goalPermission='view';funnelCheck('view-only counter access cannot pass upload admission',!ProjectFunnelConversions::probe($selectedSettings,$goalFactory)['ok']);$goalPermission='edit';
$goalResponse['id']='680570233';funnelCheck('same identifier on another goal cannot satisfy pinned admission',!ProjectFunnelConversions::probe($selectedSettings,$goalFactory)['ok']);
$goalResponse['id']='680570232';$goalResponse['conditions'][0]['type']='contain';funnelCheck('changing the live goal back to contains blocks delivery again',ProjectFunnelConversions::probe($selectedSettings,$goalFactory)['status']==='goal_condition_unsupported');
$bad=$base;$bad['metrika']['goal_match']['predictive_value']='contain';$bad['metrika']['goal_ids']=[];funnelCheck('contains requires an explicit pinned owner goal ID',funnelReject(fn()=>ProjectFunnelSettings::validate($bad,'appleservice39','id390521129062_bot')));

// A real CounterFull response may omit permission. Only exact positive collection evidence may fill it.
$accessWire=static fn($data,int $http=200,int $error=0):array=>['http'=>$http,'error'=>$error,'body'=>json_encode($data,JSON_THROW_ON_ERROR)];
$accessProbe=static function(array $counterRead,array $collectionRead,?array $settings=null,?array $goalsRead=null,?float $deadline=null)use($selectedSettings,$accessWire):array{
    $settings=$settings??$selectedSettings;$calls=[];
    $result=ProjectFunnelConversions::probe($settings,static function(array $policy)use($counterRead,$collectionRead,$goalsRead,$deadline,&$calls,$accessWire){
        return new MetrikaOfflineClient($policy['counter_id'],'synthetic-private-permission',static function($method,$url,$body)use($policy,$counterRead,$collectionRead,$goalsRead,&$calls,$accessWire):array{
            $calls[]=[$method,$url,$body];
            if($method!=='GET'||$body!==null)throw new RuntimeException('permission_probe_must_not_write');
            $counterUrl='https://api-metrika.yandex.net/management/v1/counter/'.$policy['counter_id'];
            if($url===$counterUrl)return $counterRead;
            if($url==='https://api-metrika.yandex.net/management/v1/counters?counter_ids='.$policy['counter_id'].'&permission=own%2Cedit&offset=1&per_page=1')return $collectionRead;
            if($url===$counterUrl.'/goals'){
                if($goalsRead!==null)return $goalsRead;
                $goals=[];foreach($policy['goals']as$stage=>$target)$goals[]=['id'=>$policy['goal_ids'][$stage],'type'=>'action','conditions'=>[['type'=>'exact','url'=>$target]]];
                return $accessWire(['goals'=>$goals]);
            }
            throw new RuntimeException('unexpected_permission_probe_request');
        },$deadline,$policy);
    });
    return ['result'=>$result,'calls'=>$calls];
};
$counterGet=['GET','https://api-metrika.yandex.net/management/v1/counter/42611469',null];
$collectionGet=['GET','https://api-metrika.yandex.net/management/v1/counters?counter_ids=42611469&permission=own%2Cedit&offset=1&per_page=1',null];
$goalsGet=['GET','https://api-metrika.yandex.net/management/v1/counter/42611469/goals',null];
$accessReady=['ok'=>true,'status'=>'ready','conversion_requests'=>0];
$accessDenied=['ok'=>false,'status'=>'goal_access_unavailable','conversion_requests'=>0];
$counterWithoutPermission=$accessWire(['counter'=>['id'=>42611469]]);
$collectionData=['rows'=>1,'counters'=>[['id'=>42611469,'permission'=>'edit']]];
foreach(['own','edit']as$permission){
    $normal=$accessProbe($accessWire(['counter'=>['id'=>42611469,'permission'=>$permission]]),$accessWire([]));
    funnelCheck('explicit '.$permission.' keeps the original two GET admission',$normal['result']===$accessReady&&$normal['calls']===[$counterGet,$goalsGet]);
    $list=$collectionData;$list['counters'][0]['permission']=$permission;
    $missing=$accessProbe($counterWithoutPermission,$accessWire($list));
    funnelCheck('missing counter permission requires exact filtered '.$permission.' evidence',$missing['result']===$accessReady&&$missing['calls']===[$counterGet,$collectionGet,$goalsGet]);
}
foreach(['view','analyst','view_access_filter','analyst_access_filter',null,'','owner',7,['edit']]as$i=>$permission){
    $denied=$accessProbe($accessWire(['counter'=>['id'=>42611469,'permission'=>$permission]]),$accessWire($collectionData));
    funnelCheck('explicit denied or malformed permission never falls back '.$i,$denied['result']===$accessDenied&&$denied['calls']===[$counterGet]);
}
$counterFailures=[
    'wrong identity'=>$accessWire(['counter'=>['id'=>42611470]]),
    'HTTP error'=>$accessWire(['counter'=>['id'=>42611469]],403),
    'transport error'=>$accessWire(['counter'=>['id'=>42611469]],200,60),
    'invalid JSON'=>['http'=>200,'error'=>0,'body'=>'PRIVATE-CANARY'],
    'missing counter'=>$accessWire([]),
];
foreach($counterFailures as$name=>$wire){
    $denied=$accessProbe($wire,$accessWire($collectionData));
    funnelCheck('counter '.$name.' is rejected before collection lookup',$denied['result']===$accessDenied&&$denied['calls']===[$counterGet]);
}
$collectionFailures=[
    'empty result'=>['rows'=>0,'counters'=>[]],
    'string count'=>['rows'=>'1','counters'=>$collectionData['counters']],
    'multiple matching rows'=>['rows'=>2,'counters'=>$collectionData['counters']],
    'duplicate entries'=>['rows'=>1,'counters'=>[$collectionData['counters'][0],$collectionData['counters'][0]]],
    'non-list'=>['rows'=>1,'counters'=>['named'=>$collectionData['counters'][0]]],
    'numeric-key object'=>['rows'=>1,'counters'=>(object)$collectionData['counters']],
    'missing entries'=>['rows'=>1],
    'wrong identity'=>['rows'=>1,'counters'=>[['id'=>42611470,'permission'=>'edit']]],
    'float identity'=>['rows'=>1,'counters'=>[['id'=>42611469.5,'permission'=>'edit']]],
    'missing permission'=>['rows'=>1,'counters'=>[['id'=>42611469]]],
];
foreach(['view','analyst',null,'PRIVATE-CANARY',true]as$i=>$permission)$collectionFailures['denied permission '.$i]=['rows'=>1,'counters'=>[['id'=>42611469,'permission'=>$permission]]];
foreach($collectionFailures as$name=>$data){
    $denied=$accessProbe($counterWithoutPermission,$accessWire($data));
    funnelCheck('collection '.$name.' cannot authorize goals or upload',$denied['result']===$accessDenied&&$denied['calls']===[$counterGet,$collectionGet]);
}
foreach(['HTTP error'=>$accessWire($collectionData,403),'transport error'=>$accessWire($collectionData,200,60),
    'float count'=>['http'=>200,'error'=>0,'body'=>'{"rows":1.0,"counters":[{"id":42611469,"permission":"edit"}]}'],
    'invalid JSON'=>['http'=>200,'error'=>0,'body'=>'PRIVATE-CANARY']]as$name=>$wire){
    $denied=$accessProbe($counterWithoutPermission,$wire);
    funnelCheck('collection '.$name.' fails closed without retry',$denied['result']===$accessDenied&&$denied['calls']===[$counterGet,$collectionGet]);
}
$exactGoal=['id'=>'680570232','type'=>'action','conditions'=>[['type'=>'exact','url'=>'predict']]];
$otherGoal=$exactGoal;$otherGoal['id']='680570233';
$conflict=$accessProbe($counterWithoutPermission,$accessWire($collectionData),null,$accessWire(['goals'=>[$exactGoal,$otherGoal]]));
funnelCheck('permission fallback preserves conflicting goal rejection',$conflict['result']===$accessDenied&&$conflict['calls']===[$counterGet,$collectionGet,$goalsGet]);
$contains=$exactGoal;$contains['conditions'][0]['type']='contain';
$unsupported=$accessProbe($counterWithoutPermission,$accessWire($collectionData),null,$accessWire(['goals'=>[$contains]]));
funnelCheck('permission fallback never admits a non-exact pinned goal',$unsupported['result']===['ok'=>false,'status'=>'goal_condition_unsupported','conversion_requests'=>0]&&$unsupported['calls']===[$counterGet,$collectionGet,$goalsGet]);
$otherSettings=$selectedSettings;$otherSettings['project_key']='repair_two';$otherSettings['bot_key']='id999999999002_bot';
$otherSettings['metrika']=array_replace($selectedPolicy,['counter_id'=>'99900012','goals'=>['manager_request'=>'clinic_request'],'goal_ids'=>['manager_request'=>'70701'],'goal_match'=>['manager_request'=>'exact']]);
$other=$accessProbe($accessWire(['counter'=>['id'=>'99900012']]),$accessWire(['rows'=>1,'counters'=>[['id'=>'99900012','permission'=>'own']]]),$otherSettings);
funnelCheck('another company resolves only its own counter and goal through the shared fallback',$other['result']===$accessReady&&$other['calls']===[
    ['GET','https://api-metrika.yandex.net/management/v1/counter/99900012',null],
    ['GET','https://api-metrika.yandex.net/management/v1/counters?counter_ids=99900012&permission=own%2Cedit&offset=1&per_page=1',null],
    ['GET','https://api-metrika.yandex.net/management/v1/counter/99900012/goals',null]]);
$expired=$accessProbe($counterWithoutPermission,$accessWire($collectionData),null,null,microtime(true)-1);
funnelCheck('the shared request executor preserves an expired read deadline',$expired['result']===$accessDenied&&$expired['calls']===[]);
$legacyCalls=0;$legacy=new MetrikaOfflineClient('98615635','synthetic-private-permission',static function()use(&$legacyCalls):array{$legacyCalls++;return [];});
funnelCheck('legacy admission never uses the project permission fallback',$legacy->projectGoalAdmission()==='goal_access_unavailable'&&$legacyCalls===0);
funnelCheck('permission checks preserve fixture collect policy and stored queue history',$selectedSettings['metrika']['mode']==='collect'&&$base['metrika']['mode']==='send'
    &&$before===$pdo->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll());
require_once __DIR__.'/fixtures/project_funnel_advertising_assertions.php';
projectFunnelNativeAssertions($pdo,'funnelCheck');

// Exercise the actual signed launch, saved bot entry, shared outbox and provider receipt path.
function funnelOwnerFixture(string $payload='as39_pair_model-iphone-13_battery',string $clientId='17929400001234567890'):array{
    $pdo=repairFixture();funnelSchema($pdo);funnelConversionSchema($pdo);$now=time();
    $settings=ProjectFunnelSettings::load(ProjectMaxConnection::resolve('appleservice39'));
    $settings['metrika']['mode']='collect';
    $evidence=['decision'=>'Synthetic owner fixture: listed service price times twenty percent.','source'=>'Synthetic exact price fixture.'];
    $settings['prediction']=['basis'=>'owner_assumption','model_id'=>'fixture-owner-value','version'=>1,'approved_at_unix'=>$now-60,
        'evidence_sha256'=>hash('sha256',json_encode($evidence,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),'evidence'=>$evidence,
        'probability'=>0.2,'price_basis'=>'listed_service_price','prices'=>[
            ['model'=>'iPhone 13','service'=>'Замена аккумулятора','amount_rub'=>'5000.00','source_id'=>'fixture-battery'],
            ['model'=>'iPhone 13','service'=>'Замена дисплея','amount_rub'=>'15000.00','source_id'=>'fixture-display']]];
    $settings=ProjectFunnelSettings::validate($settings,'appleservice39','id390521129062_bot');
    $registry=repairFixtureRegistry();$registry['appleservice39']['project']['sales_funnel']=$settings;ProjectMaxConnection::resetForTests($registry);
    $pdo->exec("INSERT INTO appleservice39_pilot_operations VALUES('model-iphone-13','appleservice39-pilot-v1','create','verified',1,'fixture_model_iphone_13','https://max.ru/channel_appleservice39ru/fixture_model_iphone_13')");
    $connection=ProjectMaxConnection::resolve('appleservice39');$repairEntry=RepairProjectSettings::entry($connection,$payload);$post=$repairEntry['post_id'];
    $start='123456789_region_213_campaign_123_ctx1_'.ProjectFunnelLaunch::context($post,$settings['posts'][$post]['offer_id']);
    (new ProjectFunnelEntry($pdo,$connection,$settings))->capture(['init_data'=>funnelSigned($start,$now-5,'synthetic-apple-token','7101'),
        'start_param'=>$start,'client_id'=>$clientId],$now-5);
    $factory=static fn(ProjectMaxConnection $c)=>new MaxMessengerAdapter(static fn($chat,$text)=>'synthetic-owner-reply',null,'system',null,false);
    RepairMaxWebhookService::handle('appleservice39',str_repeat('a',32),repairStart(7101,$payload),$factory,static function(){});
    $conversation=repairConversation($pdo,'appleservice39',7101);
    return [$pdo,$settings,$conversation,time()+1,$factory];
}

[$ownerDb,$ownerSettings,$ownerConversation,$ownerNow,$ownerMessenger]=funnelOwnerFixture();
$ownerPolicy=ProjectFunnelConversions::policy($ownerSettings);
$source=$ownerDb->query("SELECT id,payload_json FROM conversation_events WHERE event_type='bot_started' ORDER BY id LIMIT 1")->fetch();
$sourcePayload=json_decode($source['payload_json'],true);$sourceClock=ConversationRecordClock::read($source['payload_json']);
// Later real re-entry selects another service; the first attributed entry still owns this forecast.
RepairMaxWebhookService::handle('appleservice39',str_repeat('a',32),repairStart(7101,'as39_pair_model-iphone-13_display',1800000000001),$ownerMessenger,static function(){});
$metadata=json_decode(repairConversation($ownerDb,'appleservice39',7101)['metadata_json'],true);
$metadata['repair_context']=['model'=>'iPhone 13','service'=>'Замена дисплея','entry_code'=>'as39_pair_model-iphone-13_display'];
$ownerDb->prepare('UPDATE conversations SET metadata_json=? WHERE id=?')->execute([json_encode($metadata),$ownerConversation['id']]);
$stats=ProjectFunnelConversions::tick($ownerDb,$ownerNow,$never);
$ownerRow=$ownerDb->query('SELECT * FROM max_channel_conversion_outbox')->fetch();
$frozen=json_decode($ownerRow['funnel_context_json'],true)['estimate'];
funnelCheck('owner estimate uses original exact service price times twenty percent without AnyTour commission',
    $stats['errors']===0&&$stats['collected']===1&&$stats['resolved']===1&&$ownerRow['state']==='ready'&&$ownerRow['value_rub']==='1000.00'
    &&$ownerRow['counter_id']==='42611469'&&$ownerRow['transport_target']==='predict'&&$frozen['price']['amount_rub']==='5000.00');
funnelCheck('original entry freezes selected price source probability and record receipt despite later context',
    $frozen['basis']==='owner_assumed_probability_not_payment'&&$frozen['probability']===0.2&&$frozen['model_version']===1
    &&$frozen['price']['source_id']==='fixture-battery'&&$frozen['price_context']['source_event_id']===(string)$source['id']
    &&$frozen['price_context']['entry_key']===$sourcePayload['delivery_key']&&$frozen['price_context']['recorded_at_unix']===$sourceClock
    &&$frozen['event_unix']>=$sourceClock&&!isset($frozen['prices'])&&strlen($ownerRow['funnel_context_json'])<16384);
funnelCheck('owner collection does not create AnyTour conversions',(int)$ownerDb->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE project_key='anytour'")->fetchColumn()===0);

$ownerContext=$frozen['price_context'];
foreach(['customer_activity','manager_request','customer_reply','working','offer_sent','booking']as$stage){
    $estimate=ProjectFunnelConversions::estimate($ownerPolicy,[$stage=>$ownerNow-2],$ownerNow,$ownerContext);
    funnelCheck('owner twenty percent remains explicit at observed '.$stage,$estimate['value_rub']==='1000.00'&&$estimate['stage']===$stage&&$estimate['event_unix']>=$sourceClock);
}
$oldAssumption=$ownerPolicy;$oldAssumption['prediction']['approved_at_unix']=$ownerNow-120*86400;
funnelCheck('owner assumption does not acquire a fabricated ninety day calibration expiry',ProjectFunnelConversions::estimate($oldAssumption,['working'=>$ownerNow],$ownerNow,$ownerContext)['value_rub']==='1000.00');
$futureAssumption=$ownerPolicy;$futureAssumption['prediction']['approved_at_unix']=$ownerNow+1;
$ambiguousPrices=$ownerPolicy;$ambiguousPrices['prediction']['prices'][]=$ownerPolicy['prediction']['prices'][0];
funnelCheck('unapproved future model won missing context or ambiguous price creates no estimate',
    ProjectFunnelConversions::estimate($futureAssumption,['working'=>$ownerNow],$ownerNow,$ownerContext)===null
    &&ProjectFunnelConversions::estimate($ownerPolicy,['working'=>$ownerNow,'won'=>$ownerNow],$ownerNow,$ownerContext)===null
    &&ProjectFunnelConversions::estimate($ownerPolicy,['working'=>$ownerNow],$ownerNow)===null
    &&ProjectFunnelConversions::estimate($ambiguousPrices,['working'=>$ownerNow],$ownerNow,$ownerContext)===null);
funnelCheck('visit alone future activity and stale price context create no estimate',
    ProjectFunnelConversions::estimate($ownerPolicy,[],$ownerNow,$ownerContext)===null
    &&ProjectFunnelConversions::estimate($ownerPolicy,['working'=>$ownerNow+1],$ownerNow,$ownerContext)===null
    &&ProjectFunnelConversions::estimate($ownerPolicy,['working'=>$ownerNow],$ownerNow,array_replace($ownerContext,['recorded_at_unix'=>$ownerNow-21*86400-1]))===null);

$invalidModels=[
    'string probability'=>static function(array &$p):void{$p['probability']='0.2';},
    'probability one'=>static function(array &$p):void{$p['probability']=1;},
    'nonfinite probability'=>static function(array &$p):void{$p['probability']=NAN;},
    'mismatched evidence'=>static function(array &$p):void{$p['evidence']['decision']='Different assumption';},
    'duplicate exact pair'=>static function(array &$p):void{$p['prices'][]=$p['prices'][0];},
    'unconfirmed price basis'=>static function(array &$p):void{$p['price_basis']='from_or_inquiry';},
    'noncanonical amount'=>static function(array &$p):void{$p['prices'][0]['amount_rub']='5000';},
    'nonpositive amount'=>static function(array &$p):void{$p['prices'][0]['amount_rub']='0.00';},
    'arbitrary source path'=>static function(array &$p):void{$p['prices'][0]['source_id']='../../private';},
    'fake calibration field'=>static function(array &$p):void{$p['validated_at_unix']=time();},
];
foreach($invalidModels as$name=>$mutate){$invalid=$ownerSettings;$mutate($invalid['prediction']);funnelCheck('owner model rejects '.$name,funnelReject(fn()=>ProjectFunnelSettings::validate($invalid,'appleservice39','id390521129062_bot')));}
$sharedSource=$ownerSettings;$sharedSource['prediction']['prices'][1]['source_id']='fixture-battery';
funnelCheck('one explicit price source may support distinct model service pairs',ProjectFunnelSettings::validate($sharedSource,'appleservice39','id390521129062_bot')===$sharedSource);

$ownerPosts=[];$ownerReads=[];$unknownOwnerUpload=false;
$ownerTransport=static function(string $method,string $url,$body)use(&$ownerPosts,&$ownerReads,&$unknownOwnerUpload):array{
    if($method==='POST'){$ownerPosts[]=[$url,$body];if($unknownOwnerUpload)throw new RuntimeException('synthetic_response_lost');$data=['uploading'=>['id'=>'74001','status'=>'UPLOADED']];}
    else{
        $ownerReads[]=$url;
        if(str_ends_with($url,'/counter/42611469'))$data=['counter'=>['id'=>'42611469','permission'=>'edit']];
        elseif(str_ends_with($url,'/goals'))$data=['goals'=>[['id'=>'680570232','type'=>'action','conditions'=>[['type'=>'exact','url'=>'predict']]]]];
        elseif(str_ends_with($url,'/74001'))$data=['uploading'=>['id'=>'74001','status'=>'PROCESSED','source_quantity'=>1,'line_quantity'=>1]];
        else $data=['uploadings'=>[]];
    }
    return ['http'=>200,'error'=>0,'body'=>json_encode($data)];
};
$ownerApi=static fn(array $p)=>new MetrikaOfflineClient($p['counter_id'],'synthetic-owner-token',$ownerTransport,null,$p);
$ownerSettings['metrika']['mode']='send';$set($ownerSettings);
funnelCheck('mode change alone preserves the frozen owner policy hash',ProjectFunnelConversions::policy($ownerSettings)['sha256']===$ownerPolicy['sha256']);
$stats=ProjectFunnelConversions::tick($ownerDb,$ownerNow+1,$ownerApi);
funnelCheck('owner forecast sends once through pinned exact predict goal and existing queue',
    $stats['accepted']===1&&$stats['errors']===0&&count($ownerPosts)===1&&str_contains($ownerPosts[0][0],'/counter/42611469/')
    &&str_contains($ownerPosts[0][1],',predict,')&&str_contains($ownerPosts[0][1],',1000.00,RUB')
    &&$ownerDb->query('SELECT state FROM max_channel_conversion_outbox')->fetchColumn()==='accepted');
ProjectFunnelConversions::tick($ownerDb,$ownerNow+1901,$ownerApi);ProjectFunnelConversions::tick($ownerDb,$ownerNow+3901,$ownerApi);
funnelCheck('processed owner receipt never sends a second conversion',count($ownerPosts)===1&&$ownerDb->query('SELECT state FROM max_channel_conversion_outbox')->fetchColumn()==='processed');

[$heldDb,$heldSettings,$heldConversation,$heldNow]=funnelOwnerFixture();ProjectFunnelConversions::tick($heldDb,$heldNow,$never);
$heldBefore=$heldDb->query('SELECT * FROM max_channel_conversion_outbox')->fetchAll();$postsBefore=count($ownerPosts);
$heldSettings['prediction']['version']=2;$heldSettings['prediction']['probability']=0.25;$heldSettings['prediction']['prices'][0]['amount_rub']='6000.00';$heldSettings['metrika']['mode']='send';$set($heldSettings);
$stats=ProjectFunnelConversions::tick($heldDb,$heldNow+1,$ownerApi);
funnelCheck('new assumption version cannot reprice or replace the original queued forecast',
    $stats['held']===1&&$stats['accepted']===0&&$stats['collected']===0&&count($ownerPosts)===$postsBefore
    &&$heldBefore===$heldDb->query('SELECT * FROM max_channel_conversion_outbox')->fetchAll());

[$unknownDb,$unknownSettings,$unknownConversation,$unknownNow]=funnelOwnerFixture();
$unknownSettings['metrika']['mode']='send';$set($unknownSettings);$unknownOwnerUpload=true;$postsBefore=count($ownerPosts);
$stats=ProjectFunnelConversions::tick($unknownDb,$unknownNow,$ownerApi);
funnelCheck('lost owner upload response is retained as unknown',$stats['unknown']===1&&count($ownerPosts)===$postsBefore+1&&$unknownDb->query('SELECT state FROM max_channel_conversion_outbox')->fetchColumn()==='unknown');
ProjectFunnelConversions::tick($unknownDb,$unknownNow+1900,$ownerApi);
funnelCheck('unknown owner outcome uses receipt reads without another POST',count($ownerPosts)===$postsBefore+1&&$unknownDb->query('SELECT state FROM max_channel_conversion_outbox')->fetchColumn()==='unknown');

foreach(['as39_model-iphone-13','as39_pair_model-iphone-13_glass']as$payload){
    [$missingDb,$missingSettings,$missingConversation,$missingNow]=funnelOwnerFixture($payload);
    $stats=ProjectFunnelConversions::tick($missingDb,$missingNow,$never);
    funnelCheck('unknown or unpriced precise service has no substitute value '.$payload,$stats['errors']===0&&$stats['collected']===0&&(int)$missingDb->query('SELECT COUNT(*) FROM max_channel_conversion_outbox')->fetchColumn()===0);
}
$invalidContexts=[
    'foreign customer'=>static function(array &$p):void{$p['repair_entry_reply']['customer_id']++;},
    'foreign customer channel'=>static function(array &$p):void{$p['repair_entry_reply']['customer_channel_id']++;},
    'foreign user'=>static function(array &$p):void{$p['repair_entry_reply']['external_user_id']='7102';},
    'foreign project'=>static function(array &$p):void{$p['repair_entry_reply']['project_key']='repair_two';},
    'foreign source'=>static function(array &$p):void{$p['source_key']='max:repair_two';},
    'foreign conversation'=>static function(array &$p):void{$p['repair_entry_reply']['conversation_id']++;},
    'foreign publication'=>static function(array &$p):void{$p['repair_entry_reply']['post_id']='menu';},
    'changed entry code'=>static function(array &$p):void{$p['repair_entry_reply']['entry_code']='as39_pair_model-iphone-13_display';},
    'noncanonical model'=>static function(array &$p):void{$p['repair_context']['model']='iPhone 14';},
    'missing clock'=>static function(array &$p):void{unset($p['_recorded_clock']);},
    'string clock'=>static function(array &$p):void{$p['_recorded_clock']['unix']=(string)$p['_recorded_clock']['unix'];},
    'post-anchor clock'=>static function(array &$p,int $anchorAt):void{$p['_recorded_clock']['unix']=$anchorAt+1;},
    'future clock'=>static function(array &$p):void{$p['_recorded_clock']['unix']=time()+3600;},
    'stale clock'=>static function(array &$p):void{$p['_recorded_clock']['unix']=time()-22*86400;},
];
foreach($invalidContexts as$name=>$mutate){
    [$invalidDb,$invalidSettings,$invalidConversation,$invalidNow]=funnelOwnerFixture();
    $record=$invalidDb->query("SELECT id,payload_json FROM conversation_events WHERE event_type='bot_started' ORDER BY id LIMIT 1")->fetch();
    $anchorAt=ConversationRecordClock::read($invalidDb->query("SELECT payload_json FROM conversation_events WHERE event_type='project_funnel_entry' ORDER BY id LIMIT 1")->fetchColumn());
    $payload=json_decode($record['payload_json'],true);$mutate($payload,$anchorAt);$invalidNow+=5;
    $invalidDb->prepare('UPDATE conversation_events SET payload_json=? WHERE id=?')->execute([json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$record['id']]);
    $stats=ProjectFunnelConversions::tick($invalidDb,$invalidNow,$never);
    funnelCheck('saved price context rejects '.$name,$stats['errors']===0&&$stats['collected']===0&&(int)$invalidDb->query('SELECT COUNT(*) FROM max_channel_conversion_outbox')->fetchColumn()===0);
}
[$duplicateDb,$duplicateSettings,$duplicateConversation,$duplicateNow]=funnelOwnerFixture();
$duplicateDb->exec("INSERT INTO conversation_events(conversation_id,event_type,actor_type,actor_id,payload_json,created_at) SELECT conversation_id,event_type,actor_type,actor_id,payload_json,created_at FROM conversation_events WHERE event_type='bot_started'");
$stats=ProjectFunnelConversions::tick($duplicateDb,$duplicateNow,$never);
funnelCheck('ambiguous original entry receipts never choose an arbitrary service price',$stats['errors']===0&&$stats['collected']===0&&(int)$duplicateDb->query('SELECT COUNT(*) FROM max_channel_conversion_outbox')->fetchColumn()===0);

function funnelStoredEntry(PDO $pdo,string $type,bool $latest=true):array{
    $q=$pdo->prepare('SELECT id,payload_json FROM conversation_events WHERE event_type=? ORDER BY id '.($latest?'DESC':'ASC').' LIMIT 1');
    $q->execute([$type]);$row=$q->fetch();$row['data']=json_decode($row['payload_json'],true);return $row;
}
function funnelRefine(PDO $pdo,callable $messenger,string $payload='as39_pair_model-iphone-13_battery',int $timestamp=1800000000001):array{
    RepairMaxWebhookService::handle('appleservice39',str_repeat('a',32),repairStart(7101,$payload,$timestamp),$messenger,static function(){});
    $at=time();foreach($pdo->query("SELECT payload_json FROM conversation_events WHERE event_type='project_funnel_entry'")->fetchAll(PDO::FETCH_COLUMN)as$json)$at=max($at,ConversationRecordClock::read($json)??0);
    $at+=10;$rows=[];
    foreach(['bot_started'=>$at,'project_funnel_entry'=>$at+1]as$type=>$clock){
        $row=funnelStoredEntry($pdo,$type);$row['data']=ConversationRecordClock::stamp($row['data'],$clock);
        $row['payload_json']=json_encode($row['data'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $pdo->prepare('UPDATE conversation_events SET payload_json=? WHERE id=?')->execute([$row['payload_json'],$row['id']]);$rows[$type]=$row;
    }
    return $rows+['now'=>$at+2];
}
function funnelReplaceEntry(PDO $pdo,array $row,array $data):void{
    $pdo->prepare('UPDATE conversation_events SET payload_json=? WHERE id=?')->execute([json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$row['id']]);
}
function funnelRefinementHeld(PDO $pdo,int $now):bool{
    $stats=ProjectFunnelConversions::tick($pdo,$now,static function(){throw new RuntimeException('refinement_must_not_call_provider');});
    return $stats['errors']===0&&$stats['collected']===0&&(int)$pdo->query('SELECT COUNT(*) FROM max_channel_conversion_outbox')->fetchColumn()===0;
}
function funnelEnrichOriginalVisit(PDO $pdo,array $settings):void{
    $visit=$pdo->query('SELECT * FROM advertising_visit_observations ORDER BY id LIMIT 1')->fetch();
    $start='123456789_region_213_campaign_123_ctx1_'.ProjectFunnelLaunch::context($visit['post_ref'],$visit['offer_ref']);
    (new ProjectFunnelEntry($pdo,ProjectMaxConnection::resolve('appleservice39'),$settings))->capture([
        'init_data'=>funnelSigned($start,(int)$visit['visit_at_unix'],'synthetic-apple-token','7101'),
        'start_param'=>$start,'client_id'=>'17929400001234567890'],time());
}

[$refineDb,$refineSettings,$refineConversation,$refineNow,$refineMessenger]=funnelOwnerFixture('as39_model-iphone-13');
$originalEntry=funnelStoredEntry($refineDb,'project_funnel_entry');$originalBot=funnelStoredEntry($refineDb,'bot_started');
$stats=ProjectFunnelConversions::tick($refineDb,$refineNow,$never);
funnelCheck('generic model has no fabricated value before explicit refinement',$stats['collected']===0&&$stats['errors']===0);
$refined=funnelRefine($refineDb,$refineMessenger);$refineNow=$refined['now'];
$stats=ProjectFunnelConversions::tick($refineDb,$refineNow,$never);$refineRow=$refineDb->query('SELECT * FROM max_channel_conversion_outbox')->fetch();
funnelCheck('same original model post and visit admit the first explicit service refinement',
    $stats['collected']===1&&$stats['resolved']===1&&$stats['errors']===0&&$refineRow['state']==='ready'&&$refineRow['value_rub']==='1000.00');
$refineContext=json_decode($refineRow['funnel_context_json'],true);$refineEstimate=$refineContext['estimate'];unset($refineContext['estimate']);
$receipt=$refineEstimate['price_context']['refinement'];
funnelCheck('refinement freezes both recording receipts while preserving the original acquisition exactly',
    $refineContext===$originalEntry['data']&&$receipt['version']===1&&$receipt['basis']==='same_original_visit_explicit_model_service'
    &&$receipt['original']===['funnel_event_id'=>(string)$originalEntry['id'],'entry_key'=>$originalEntry['data']['entry_key'],
        'funnel_recorded_at_unix'=>ConversationRecordClock::read($originalEntry['payload_json']),'bot_event_id'=>(string)$originalBot['id'],
        'bot_recorded_at_unix'=>ConversationRecordClock::read($originalBot['payload_json'])]
    &&$receipt['selected']===['funnel_event_id'=>(string)$refined['project_funnel_entry']['id'],'entry_key'=>$refined['project_funnel_entry']['data']['entry_key'],
        'funnel_recorded_at_unix'=>ConversationRecordClock::read($refined['project_funnel_entry']['payload_json']),'bot_event_id'=>(string)$refined['bot_started']['id'],
        'bot_recorded_at_unix'=>ConversationRecordClock::read($refined['bot_started']['payload_json'])]
    &&$refineEstimate['event_unix']===ConversationRecordClock::read($refined['bot_started']['payload_json'])
    &&strlen($refineRow['funnel_context_json'])<16384
    &&funnelStoredEntry($refineDb,'project_funnel_entry',false)['payload_json']===$originalEntry['payload_json']
    &&funnelStoredEntry($refineDb,'bot_started',false)['payload_json']===$originalBot['payload_json']);

$later=funnelRefine($refineDb,$refineMessenger,'as39_pair_model-iphone-13_display',1800000000002);$refineNow=$later['now'];$allFrozen=true;
foreach(['pending','ready','uploading','accepted','unknown','processed','failed','expired']as$state){
    $refineDb->prepare('UPDATE max_channel_conversion_outbox SET state=?')->execute([$state]);
    $beforeRow=$refineDb->query('SELECT * FROM max_channel_conversion_outbox')->fetch();
    $collected=ProjectFunnelConversions::collect($refineDb,ProjectMaxConnection::resolve('appleservice39'),$refineSettings,ProjectFunnelConversions::policy($refineSettings),$refineNow);
    $allFrozen=$allFrozen&&$collected===0&&$beforeRow===$refineDb->query('SELECT * FROM max_channel_conversion_outbox')->fetch();
}
funnelCheck('later service cannot replace an existing refinement in any forecast state',$allFrozen);
$refineDb->exec("UPDATE max_channel_conversion_outbox SET state='ready'");$refineSettings['metrika']['mode']='send';$set($refineSettings);
$ownerPosts=[];$ownerReads=[];$unknownOwnerUpload=false;
$stats=ProjectFunnelConversions::tick($refineDb,$refineNow,$ownerApi);
ProjectFunnelConversions::tick($refineDb,$refineNow+1900,$ownerApi);ProjectFunnelConversions::tick($refineDb,$refineNow+3900,$ownerApi);
funnelCheck('refined forecast uses the same single upload and processed receipt path',
    $stats['accepted']===1&&$stats['errors']===0&&count($ownerPosts)===1&&str_contains($ownerPosts[0][1],',predict,')
    &&str_contains($ownerPosts[0][1],',1000.00,RUB')&&$refineDb->query('SELECT state FROM max_channel_conversion_outbox')->fetchColumn()==='processed'
    &&$refineDb->query('SELECT funnel_context_json FROM max_channel_conversion_outbox')->fetchColumn()===$refineRow['funnel_context_json']);

[$noCidDb,$noCidSettings,$noCidConversation,$noCidNow,$noCidMessenger]=funnelOwnerFixture('as39_model-iphone-13','');
$noCidSelection=funnelRefine($noCidDb,$noCidMessenger);
funnelCheck('incomplete acquisition cannot create a refinement before original visit identity resolves',funnelRefinementHeld($noCidDb,$noCidSelection['now']));
funnelEnrichOriginalVisit($noCidDb,$noCidSettings);$stats=ProjectFunnelConversions::tick($noCidDb,$noCidSelection['now']+1,$never);
funnelCheck('late CID on that original visit admits the saved first refinement',$stats['collected']===1&&$stats['resolved']===1&&$stats['errors']===0);
[$cursorDb,$cursorSettings,$cursorConversation,$cursorNow,$cursorMessenger]=funnelOwnerFixture('as39_model-iphone-13','');
$cursorOriginal=funnelStoredEntry($cursorDb,'project_funnel_entry');funnelEnrichOriginalVisit($cursorDb,$cursorSettings);$cursorSelected=funnelRefine($cursorDb,$cursorMessenger);
$stats=ProjectFunnelConversions::tick($cursorDb,$cursorSelected['now'],$never);
$cursorSaved=json_decode($cursorDb->query('SELECT funnel_context_json FROM max_channel_conversion_outbox')->fetchColumn(),true);
funnelCheck('new observation cursor on the same visit does not change the original anchor',
    $stats['collected']===1&&$stats['errors']===0&&$cursorOriginal['data']['observations'][0]['source_cursor']==='1'
    &&$cursorSelected['project_funnel_entry']['data']['observations'][0]['source_cursor']==='2'
    &&$cursorSaved['observations']===$cursorOriginal['data']['observations']);

foreach(['as39_model-iphone-13','as39_pair_model-iphone-13_glass']as$first){
    [$unpricedDb,$unpricedSettings,$unpricedConversation,$unpricedNow,$unpricedMessenger]=funnelOwnerFixture($first);
    if($first==='as39_model-iphone-13')funnelRefine($unpricedDb,$unpricedMessenger,'as39_pair_model-iphone-13_glass');
    $pricedLater=funnelRefine($unpricedDb,$unpricedMessenger,'as39_pair_model-iphone-13_battery',1800000000002);
    funnelCheck('first unpriced exact choice cannot fall through to a later priced repair '.$first,funnelRefinementHeld($unpricedDb,$pricedLater['now']));
}
[$orphanDb,$orphanSettings,$orphanConversation,$orphanNow,$orphanMessenger]=funnelOwnerFixture('as39_model-iphone-13');
$orphanDb->exec("CREATE TRIGGER fixture_optional_receipt_failure BEFORE INSERT ON conversation_events WHEN NEW.event_type='project_funnel_entry' BEGIN SELECT RAISE(ABORT,'synthetic_optional_receipt_failure'); END");
RepairMaxWebhookService::handle('appleservice39',str_repeat('a',32),repairStart(7101,'as39_pair_model-iphone-13_glass',1800000000001),$orphanMessenger,static function(){});
$orphanDb->exec('DROP TRIGGER fixture_optional_receipt_failure');$pricedLater=funnelRefine($orphanDb,$orphanMessenger,'as39_pair_model-iphone-13_battery',1800000000002);
funnelCheck('first explicit native entry without optional attribution receipt holds later pricing',
    (int)$orphanDb->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='bot_started'")->fetchColumn()===3
    &&(int)$orphanDb->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='project_funnel_entry'")->fetchColumn()===2
    &&funnelRefinementHeld($orphanDb,$pricedLater['now']));

[$menuDb,$menuSettings,$menuConversation,$menuNow,$menuMessenger]=funnelOwnerFixture('as39_model-iphone-13');
$neutral=funnelRefine($menuDb,$menuMessenger,'as39_menu');$menuSelected=funnelRefine($menuDb,$menuMessenger,'as39_pair_model-iphone-13_battery',1800000000002);
$stats=ProjectFunnelConversions::tick($menuDb,$menuSelected['now'],$never);
funnelCheck('canonical unattributed menu remains a neutral step on the original visit',
    $neutral['project_funnel_entry']['data']['attribution_state']==='unattributed'&&$stats['collected']===1&&$stats['errors']===0);

[$boundaryDb,$boundarySettings,$boundaryConversation,$boundaryNow,$boundaryMessenger]=funnelOwnerFixture('as39_model-iphone-13');
$foreignPost='model-iphone-17';$foreignStart='123456790_region_213_campaign_123_ctx1_'.ProjectFunnelLaunch::context($foreignPost,$boundarySettings['posts'][$foreignPost]['offer_id']);
(new ProjectFunnelEntry($boundaryDb,ProjectMaxConnection::resolve('appleservice39'),$boundarySettings))->capture([
    'init_data'=>funnelSigned($foreignStart,time()-1,'synthetic-apple-token','7101'),'start_param'=>$foreignStart,'client_id'=>'17929400001234567890'],time());
$foreign=funnelRefine($boundaryDb,$boundaryMessenger,'as39_model-iphone-17');$returned=funnelRefine($boundaryDb,$boundaryMessenger,'as39_pair_model-iphone-13_battery',1800000000002);
funnelCheck('intervening matched advertising visit prevents return to an earlier acquisition',
    $foreign['project_funnel_entry']['data']['attribution_state']==='matched'&&funnelRefinementHeld($boundaryDb,$returned['now']));

$invalidRefinements=[
    'invalid original customer'=>['bot_started',false,static function(array &$p):void{$p['repair_entry_reply']['customer_id']++;}],
    'missing original funnel clock'=>['project_funnel_entry',false,static function(array &$p):void{unset($p['_recorded_clock']);}],
    'missing selected bot clock'=>['bot_started',true,static function(array &$p):void{unset($p['_recorded_clock']);}],
    'string selected bot clock'=>['bot_started',true,static function(array &$p):void{$p['_recorded_clock']['unix']=(string)$p['_recorded_clock']['unix'];}],
    'selected clock before original anchor'=>['bot_started',true,static function(array &$p,int $anchorAt):void{$p['_recorded_clock']['unix']=$anchorAt-1;}],
    'selected clock after its funnel receipt'=>['bot_started',true,static function(array &$p,int $anchorAt,int $selectedAt):void{$p['_recorded_clock']['unix']=$selectedAt+1;}],
    'future selected funnel clock'=>['project_funnel_entry',true,static function(array &$p,int $anchorAt,int $selectedAt,int $now):void{$p['_recorded_clock']['unix']=$now+1;}],
    'selected foreign visit'=>['project_funnel_entry',true,static function(array &$p):void{$p['observations'][0]['visit_id']='another-visit';}],
    'selected missing visit'=>['project_funnel_entry',true,static function(array &$p):void{unset($p['observations'][0]['visit_id']);}],
    'selected ambiguous attribution'=>['project_funnel_entry',true,static function(array &$p):void{$p['attribution_state']='ambiguous';}],
];
foreach($invalidRefinements as$name=>[$type,$latest,$mutate]){
    [$badDb,$badSettings,$badConversation,$badNow,$badMessenger]=funnelOwnerFixture('as39_model-iphone-13');
    $badOriginal=funnelStoredEntry($badDb,'project_funnel_entry');$badSelected=funnelRefine($badDb,$badMessenger);$row=funnelStoredEntry($badDb,$type,$latest);$data=$row['data'];
    $mutate($data,ConversationRecordClock::read($badOriginal['payload_json']),ConversationRecordClock::read($badSelected['project_funnel_entry']['payload_json']),$badSelected['now']);
    funnelReplaceEntry($badDb,$row,$data);funnelCheck('refinement holds '.$name,funnelRefinementHeld($badDb,$badSelected['now']));
}
foreach(['bot_started','project_funnel_entry']as$type){
    [$dupDb,$dupSettings,$dupConversation,$dupNow,$dupMessenger]=funnelOwnerFixture('as39_model-iphone-13');$dupSelected=funnelRefine($dupDb,$dupMessenger);
    $dupDb->prepare('INSERT INTO conversation_events(conversation_id,event_type,actor_type,actor_id,payload_json,created_at) SELECT conversation_id,event_type,actor_type,actor_id,payload_json,created_at FROM conversation_events WHERE id=?')->execute([$dupSelected[$type]['id']]);
    funnelCheck('refinement rejects duplicate selected '.$type.' receipt',funnelRefinementHeld($dupDb,$dupSelected['now']));
}
[$orderDb,$orderSettings,$orderConversation,$orderNow,$orderMessenger]=funnelOwnerFixture('as39_model-iphone-13');$orderSelected=funnelRefine($orderDb,$orderMessenger);
$orderDb->prepare('UPDATE conversation_events SET id=100000 WHERE id=?')->execute([$orderSelected['bot_started']['id']]);
funnelCheck('refinement rejects native event ordered after its funnel receipt',funnelRefinementHeld($orderDb,$orderSelected['now']));
[$secondDb,$secondSettings,$secondConversation,$secondNow,$secondMessenger]=funnelOwnerFixture('as39_model-iphone-13');
$sameSecond=funnelStoredEntry($secondDb,'project_funnel_entry');$secondSelected=funnelRefine($secondDb,$secondMessenger);$sameClock=ConversationRecordClock::read($sameSecond['payload_json']);
foreach(['bot_started','project_funnel_entry']as$type)funnelReplaceEntry($secondDb,$secondSelected[$type],ConversationRecordClock::stamp($secondSelected[$type]['data'],$sameClock));
$stats=ProjectFunnelConversions::tick($secondDb,$secondSelected['now'],$never);
funnelCheck('same-second refinement uses strict event IDs without inventing a later clock',$stats['collected']===1&&$stats['errors']===0);
[$wonDb,$wonSettings,$wonConversation,$wonNow,$wonMessenger]=funnelOwnerFixture('as39_model-iphone-13');$wonSelected=funnelRefine($wonDb,$wonMessenger);
$wonDb->prepare('INSERT INTO conversation_events(conversation_id,event_type,actor_type,payload_json) VALUES(?,?,?,?)')->execute([
    $wonConversation['id'],'project_funnel_stage','manager',json_encode(ConversationRecordClock::stamp([
        'project_key'=>'appleservice39','bot_key'=>'id390521129062_bot','stage'=>'won'],$wonSelected['now']))]);
funnelCheck('observed won stage suppresses an otherwise valid refinement',funnelRefinementHeld($wonDb,$wonSelected['now']));

function funnelStatusCli(string $action,string $project='appleservice39',bool $broken=false):array{
    $root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/funnel-status-'.bin2hex(random_bytes(6));
    mkdir($tmp,0700);mkdir($tmp.'/tools',0700);
    try{
        symlink($root.'/services',$tmp.'/services');copy($root.'/tools/max_channel_attribution.php',$tmp.'/tools/max_channel_attribution.php');
        file_put_contents($tmp.'/config.php','<?php define("PROJECT_FUNNEL_STATUS_FIXTURE_ONLY",true);require '.var_export(__FILE__,true).';'
            .'$fixture=funnelOwnerFixture("as39_model-iphone-13");ProjectMaxConnection::resetForTests(repairFixtureRegistry());'
            .($broken?'$fixture[0]->exec("DROP TABLE max_channel_conversion_outbox");':'')
            .($action==='project-status'?'define("METRIKA_OAUTH_TOKEN","PRIVATE_PROVIDER_CANARY");function curl_init($url=null){file_put_contents('.var_export($tmp.'/provider-called',true).',"called");throw new RuntimeException("provider_forbidden");}':''));
        $process=proc_open([PHP_BINARY,'-d','allow_url_fopen=0','-d','disable_functions=curl_init,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client',
            $tmp.'/tools/max_channel_attribution.php',$action,$project],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('status_fixture_process');fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($process);
        return ['code'=>$code,'data'=>json_decode($out,true),'output'=>$out,'errors'=>$errors,'provider_called'=>is_file($tmp.'/provider-called')];
    }finally{foreach(['/tools/max_channel_attribution.php','/config.php','/services','/provider-called']as$file)if(is_file($tmp.$file)||is_link($tmp.$file))unlink($tmp.$file);rmdir($tmp.'/tools');rmdir($tmp);}
}
$statusCli=funnelStatusCli('project-status');
funnelCheck('actual provider-disabled CLI reads the Apple stored funnel without running delivery',
    $statusCli['code']===0&&($statusCli['data']['scope']??null)==='appleservice39_stored_funnel'
    &&($statusCli['data']['approved_public_policy_matches']??null)===true&&($statusCli['data']['outbox']['examined']??null)===0&&!$statusCli['provider_called']);

require_once dirname(__DIR__).'/services/ProjectFunnelStatusReader.php';
function funnelStatusFixture(string $entry='as39_pair_model-iphone-13_battery',?callable $beforeCollect=null):array{
    [$pdo,$ignored,$conversation,$now,$messenger]=funnelOwnerFixture($entry);
    ProjectMaxConnection::resetForTests(repairFixtureRegistry());$connection=ProjectMaxConnection::resolve('appleservice39');$settings=ProjectFunnelSettings::load($connection);
    if($entry==='as39_model-iphone-13')$now=funnelRefine($pdo,$messenger)['now'];
    if($beforeCollect!==null)$beforeCollect($pdo,$conversation,$now);
    $collect=$settings;$collect['metrika']['mode']='collect';$registry=repairFixtureRegistry();$registry['appleservice39']['project']['sales_funnel']=$collect;ProjectMaxConnection::resetForTests($registry);
    ProjectFunnelConversions::tick($pdo,$now,static function(){throw new RuntimeException('fixture_provider_forbidden');});
    ProjectMaxConnection::resetForTests(repairFixtureRegistry());
    $row=$pdo->query('SELECT * FROM max_channel_conversion_outbox')->fetch();$repo=new MaxChannelConversionOutbox($pdo);$token=$repo->claimProject($row,$now);
    $repo->finish((string)$row['id'],$token,['outcome'=>'accepted','upload_id'=>'987654321987654321','status'=>'UPLOADED'],$now);
    $repo->status($row,['id'=>'987654321987654321','status'=>'PROCESSED','source_quantity'=>1,'line_quantity'=>1],$now+1);
    return [$pdo,$settings,$conversation,$now+2];
}
function funnelStatusRead(PDO $pdo,array $settings,int $now,?float $deadline=null):array{
    return (new ProjectFunnelStatusReader($pdo,$deadline))->read(ProjectMaxConnection::resolve('appleservice39'),$settings,$now);
}
function funnelStatusDatabase(PDO $pdo):string{
    $snapshot=[];foreach($pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN)as$table)
        $snapshot[$table]=$pdo->query('SELECT * FROM "'.str_replace('"','""',$table).'" ORDER BY rowid')->fetchAll();
    return hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR));
}
[$statusDb,$statusSettings,$statusConversation,$statusNow]=funnelStatusFixture();
$statusBefore=funnelStatusDatabase($statusDb);$read=funnelStatusRead($statusDb,$statusSettings,$statusNow);
funnelCheck('stored reader confirms original visit frozen price and retained processed worker receipt',
    $read['available']&&$read['complete']&&$read['approved_public_policy_matches']&&$read['outbox']['approved_policy_states']['processed']===1
    &&$read['first_saved_forecast']['verified']&&$read['first_saved_forecast']['retained_api_receipt']===true
    &&$read['first_saved_forecast']['retained_processed_receipt']===true&&$read['first_saved_forecast']['manager_path']['request_recorded']===true
    &&$read['observations']['counts']['verified_with_client_rows']===1);
foreach($statusDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN)as$table){
    foreach(['INSERT','UPDATE','DELETE']as$operation)$statusDb->exec('CREATE TRIGGER "reader_guard_'.$table.'_'.$operation.'" BEFORE '.$operation.' ON "'.$table.'" BEGIN SELECT RAISE(ABORT,\'reader_write_forbidden\'); END');
}
$again=funnelStatusRead($statusDb,$statusSettings,$statusNow);
funnelCheck('repeated status is side effect free under write traps and restores caller query mode',
    $again===$read&&funnelStatusDatabase($statusDb)===$statusBefore&&!$statusDb->inTransaction()&&(int)$statusDb->query('PRAGMA query_only')->fetchColumn()===0);
$public=json_encode($read,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$canaries=['17929400001234567890','987654321987654321','1000.00','5000.00','Замена аккумулятора','iPhone 13','fixture-battery','PRIVATE_PROVIDER_CANARY'];
$privateKeys=['client_id','external_user_id','conversation_id','customer_id','customer_channel_id','upload_id','visit_id','entry_key','funnel_context_json','value_rub','amount_rub','text','model_id','source_id','policy_sha256'];
$scan=static function(array $data)use(&$scan,$privateKeys):bool{foreach($data as$key=>$value){if(is_string($key)&&in_array($key,$privateKeys,true))return false;if(is_array($value)&&!$scan($value))return false;}return true;};
funnelCheck('public stored evidence has no native identifiers money texts or arbitrary settings',
    strlen($public)<8192&&$scan($read)&&!array_filter($canaries,static fn(string $value):bool=>str_contains($public,$value))
    &&$read['basis']['processing_time']==='not_observed'&&str_contains($read['basis']['population'],'not_organic'));
$aged=funnelStatusRead($statusDb,$statusSettings,$statusNow+40*86400);
funnelCheck('historical processed evidence does not expire with the current sending window',$aged['first_saved_forecast']['verified']&&$aged['first_saved_forecast']['retained_processed_receipt']===true);
$changed=$statusSettings;$changed['metrika']['mode']='collect';$changed['prediction']['probability']=0.25;$changed['prediction']['model_id']='PRIVATE_MODEL_CANARY';
$changedRead=funnelStatusRead($statusDb,$changed,$statusNow);
funnelCheck('effective private changes produce only an approved policy mismatch boolean',
    $changedRead['approved_public_policy_matches']===false&&$changedRead['reason']==='approved_policy_mismatch'
    &&$changedRead['first_saved_forecast']['verified']&&!str_contains(json_encode($changedRead),'PRIVATE_MODEL_CANARY'));
$statusDb->beginTransaction();$outer=funnelStatusRead($statusDb,$statusSettings,$statusNow);
funnelCheck('reader refuses but never rolls back a caller transaction',!$outer['available']&&$outer['reason']==='outer_transaction'&&$statusDb->inTransaction());$statusDb->rollBack();
$expiredRead=funnelStatusRead($statusDb,$statusSettings,$statusNow,microtime(true)-1);
funnelCheck('expired read budget is unavailable rather than an empty successful funnel',!$expiredRead['available']&&$expiredRead['reason']==='read_deadline'&&$expiredRead['outbox']===null);

[$statusRefineDb,$statusRefineSettings,$statusRefineConversation,$statusRefineNow]=funnelStatusFixture('as39_model-iphone-13');
$refineRead=funnelStatusRead($statusRefineDb,$statusRefineSettings,$statusRefineNow);
funnelCheck('stored reader verifies both frozen refinement receipts without collecting again',$refineRead['first_saved_forecast']['verified']&&$refineRead['first_saved_forecast']['retained_processed_receipt']===true);
foreach(['callback'=>false,'contact'=>false,'contact_reply'=>true]as$activity=>$reply){
    [$activityDb,$activitySettings,$activityConversation,$activityNow]=funnelStatusFixture('as39_pair_model-iphone-13_battery',static function(PDO $pdo,array $conversation,int $at)use($activity,$reply):void{
        $pdo->prepare("DELETE FROM conversation_events WHERE conversation_id=? AND event_type IN ('waiting_manager','project_funnel_stage')")->execute([$conversation['id']]);
        $pdo->prepare('DELETE FROM messages WHERE conversation_id=?')->execute([$conversation['id']]);
        $insert=$pdo->prepare('INSERT INTO messages(conversation_id,direction,sender_type,channel,metadata_json) VALUES(?,?,?,?,?)');
        if($reply)$insert->execute([$conversation['id'],'outbound','manager','max',json_encode(ConversationRecordClock::stamp(['type'=>'message'],$at))]);
        $insert->execute([$conversation['id'],'inbound','customer','max',json_encode(ConversationRecordClock::stamp(['type'=>$reply?'contact':$activity],$at))]);
    });
    $activityRow=$activityDb->query('SELECT funnel_context_json FROM max_channel_conversion_outbox')->fetchColumn();
    $activityRead=funnelStatusRead($activityDb,$activitySettings,$activityNow);
    funnelCheck('stored proof accepts canonical '.$activity.' stage including same-second reply order',
        json_decode($activityRow,true)['estimate']['stage']===($reply?'customer_reply':'customer_activity')&&$activityRead['first_saved_forecast']['verified']);
}

$statusMutations=[
    'foreign counter'=>["UPDATE max_channel_conversion_outbox SET counter_id='98615635'",'scope_unconfirmed'],
    'foreign platform'=>["UPDATE max_channel_conversion_outbox SET platform='telegram'",'scope_unconfirmed'],
    'foreign goal'=>["UPDATE max_channel_conversion_outbox SET transport_target='private_other_goal'",'scope_unconfirmed'],
    'foreign conversation user'=>["UPDATE customer_channels SET external_user_id='7102' WHERE project_key='appleservice39'",'scope_unconfirmed'],
    'marked test'=>["UPDATE conversations SET is_test=1 WHERE project_key='appleservice39'",'marked_test'],
    'historical policy'=>["UPDATE max_channel_conversion_outbox SET funnel_policy_sha256='old_policy'",'historical_policy'],
    'missing original visit'=>["DELETE FROM advertising_visit_observations",'identity_unconfirmed'],
    'conflicting original CID'=>["UPDATE advertising_visit_observations SET client_id='123456789123456789'",'identity_unconfirmed'],
    'inconsistent processed status'=>["UPDATE max_channel_conversion_outbox SET upload_status='UPLOADED'",'api_receipt_inconsistent'],
    'missing retained upload'=>["UPDATE max_channel_conversion_outbox SET upload_id=NULL",'api_receipt_inconsistent'],
    'oversized saved context'=>["UPDATE max_channel_conversion_outbox SET funnel_context_json='PRIVATE_CONTEXT_CANARY'||hex(zeroblob(10000))",'record_invalid'],
];
foreach($statusMutations as$name=>[$sql,$reason]){
    [$badStatusDb,$badStatusSettings,$badStatusConversation,$badStatusNow]=funnelStatusFixture();$badStatusDb->exec($sql);
    $beforeStatus=funnelStatusDatabase($badStatusDb);$badRead=funnelStatusRead($badStatusDb,$badStatusSettings,$badStatusNow);
    funnelCheck('stored proof holds '.$name,!$badRead['first_saved_forecast']['verified']&&$badRead['first_saved_forecast']['reason']===$reason
        &&$badRead['first_saved_forecast']['retained_processed_receipt']===null&&funnelStatusDatabase($badStatusDb)===$beforeStatus
        &&!str_contains(json_encode($badRead),'PRIVATE_CONTEXT_CANARY'));
}
[$equationDb,$equationSettings,$equationConversation,$equationNow]=funnelStatusFixture();
$equationRow=$equationDb->query('SELECT * FROM max_channel_conversion_outbox')->fetch();$equationContext=json_decode($equationRow['funnel_context_json'],true);$equationContext['estimate']['value_rub']='1001.00';
$equationDb->prepare('UPDATE max_channel_conversion_outbox SET value_rub=?,funnel_context_json=?')->execute(['1001.00',json_encode($equationContext)]);
$equationRead=funnelStatusRead($equationDb,$equationSettings,$equationNow);
funnelCheck('matching corrupted ledger values do not satisfy the frozen twenty percent equation',$equationRead['first_saved_forecast']['reason']==='estimate_unconfirmed');
[$clockDb,$clockSettings,$clockConversation,$clockNow]=funnelStatusFixture();
$clockRow=$clockDb->query('SELECT * FROM max_channel_conversion_outbox')->fetch();$clockContext=json_decode($clockRow['funnel_context_json'],true);$clockContext['estimate']['event_unix']+=5;
$clockDb->prepare('UPDATE max_channel_conversion_outbox SET event_unix=?,funnel_context_json=?')->execute([$clockContext['estimate']['event_unix'],json_encode($clockContext)]);
$clockRead=funnelStatusRead($clockDb,$clockSettings,$clockNow+10);
funnelCheck('matching shifted ledger clocks cannot replace the saved stage and selected bot clock',
    $clockRead['first_saved_forecast']['reason']==='stage_unconfirmed'&&$clockRead['first_saved_forecast']['retained_processed_receipt']===null);
[$receiptDb,$receiptSettings,$receiptConversation,$receiptNow]=funnelStatusFixture();
foreach(['PROCESSED','LINKAGE_FAILURE']as$acceptedStatus){
    $receiptDb->prepare("UPDATE max_channel_conversion_outbox SET state='accepted',upload_status=?,last_error=''")->execute([$acceptedStatus]);
    $acceptedRead=funnelStatusRead($receiptDb,$receiptSettings,$receiptNow);
    funnelCheck('direct accepted '.$acceptedStatus.' receipt does not claim final worker processing',
        $acceptedRead['first_saved_forecast']['verified']&&$acceptedRead['first_saved_forecast']['retained_api_receipt']===true&&$acceptedRead['first_saved_forecast']['retained_processed_receipt']===false);
}
$receiptDb->exec("UPDATE max_channel_conversion_outbox SET state='failed',upload_status='PROCESSED',last_error='api_row_rejected'");$receiptRead=funnelStatusRead($receiptDb,$receiptSettings,$receiptNow);
funnelCheck('PROCESSED API status with rejected row quantities is retained failure not processed success',
    $receiptRead['outbox']['approved_policy_states']['failed']===1&&$receiptRead['first_saved_forecast']['retained_api_receipt']===true&&$receiptRead['first_saved_forecast']['retained_processed_receipt']===false);
$receiptDb->exec("UPDATE max_channel_conversion_outbox SET state='unknown',upload_id=NULL,upload_status=NULL,last_error='upload_outcome_unknown'");$unknownRead=funnelStatusRead($receiptDb,$receiptSettings,$receiptNow);
funnelCheck('unknown upload remains unknown with no invented retained receipt',$unknownRead['outbox']['approved_policy_states']['unknown']===1
    &&$unknownRead['first_saved_forecast']['retained_api_receipt']===false&&$unknownRead['first_saved_forecast']['retained_processed_receipt']===false);
[$cidDb,$cidSettings,$cidConversation,$cidNow]=funnelStatusFixture();
$longCid='1234567890123456789012345678901234567890';$cidDb->prepare('UPDATE max_channel_conversion_outbox SET client_id=?')->execute([$longCid]);$cidDb->prepare('UPDATE advertising_visit_observations SET client_id=?')->execute([$longCid]);
funnelCheck('canonical forty digit ClientID is checked privately without truncation',funnelStatusRead($cidDb,$cidSettings,$cidNow)['first_saved_forecast']['verified']);
[$firstDb,$firstSettings,$firstConversation,$firstNow]=funnelStatusFixture();$firstRow=$firstDb->query('SELECT * FROM max_channel_conversion_outbox')->fetch();
$firstDb->exec('UPDATE max_channel_conversion_outbox SET conversation_id=999999');unset($firstRow['id']);
$firstDb->prepare('INSERT INTO max_channel_conversion_outbox('.implode(',',array_keys($firstRow)).') VALUES('.implode(',',array_fill(0,count($firstRow),'?')).')')->execute(array_values($firstRow));
$firstRead=funnelStatusRead($firstDb,$firstSettings,$firstNow);
funnelCheck('orphan earliest forecast is not hidden by a later valid processed row',$firstRead['first_saved_forecast']['reason']==='scope_unconfirmed'&&$firstRead['first_saved_forecast']['retained_processed_receipt']===null);
$firstDb->exec('DELETE FROM max_channel_conversion_outbox WHERE id=1');
funnelCheck('later receipt in the preceding fixture is independently valid',funnelStatusRead($firstDb,$firstSettings,$firstNow)['first_saved_forecast']['retained_processed_receipt']===true);
[$limitDb,$limitSettings,$limitConversation,$limitNow]=funnelStatusFixture();
for($i=0;$i<100;$i++)$limitDb->exec("INSERT INTO max_channel_conversion_outbox(project_key,platform,conversation_id,counter_id,target,transport_target,state,funnel_policy_sha256) VALUES('appleservice39','max',".(900000+$i).",'42611469','funnel_predictive_value','predict','pending','historical')");
$limitRead=funnelStatusRead($limitDb,$limitSettings,$limitNow);
funnelCheck('bounded outbox sentinel reports incomplete coverage without fabricated zero totals',!$limitRead['complete']&&!$limitRead['outbox']['complete']
    &&$limitRead['outbox']['reason']==='row_limit'&&$limitRead['outbox']['approved_policy_states']===null&&$limitRead['outbox']['other_policy_states']===null);
[$bytesDb,$bytesSettings,$bytesConversation,$bytesNow]=funnelStatusFixture();
$bytesInsert=$bytesDb->prepare("INSERT INTO conversation_events(conversation_id,event_type,actor_type,payload_json) VALUES(?,'waiting_manager','system',?)");
for($i=0;$i<4;$i++)$bytesInsert->execute([$bytesConversation['id'],json_encode(ConversationRecordClock::stamp(['private_note'=>'PRIVATE_BYTES_CANARY'.str_repeat('x',40000)],$bytesNow))]);
$bytesRead=funnelStatusRead($bytesDb,$bytesSettings,$bytesNow);
funnelCheck('processed row byte budget holds private oversized history without leaking its payload',
    !$bytesRead['complete']&&$bytesRead['first_saved_forecast']['reason']==='byte_limit'&&$bytesRead['first_saved_forecast']['retained_processed_receipt']===null
    &&!str_contains(json_encode($bytesRead),'PRIVATE_BYTES_CANARY'));
[$missingDb,$missingSettings,$missingConversation,$missingNow]=funnelStatusFixture();$missingDb->exec('DROP TABLE advertising_visit_observations');
$missingRead=funnelStatusRead($missingDb,$missingSettings,$missingNow);
funnelCheck('missing slice storage is explicit unknown while independent counts remain qualified',!$missingRead['complete']&&!$missingRead['observations']['available']
    &&$missingRead['observations']['counts']===null&&$missingRead['outbox']['approved_policy_states']['processed']===1
    &&$missingRead['first_saved_forecast']['retained_processed_receipt']===null);
$probeCli=funnelStatusCli('project-probe');$probeBroken=funnelStatusCli('project-probe','appleservice39',true);$otherProbe=funnelStatusCli('project-probe','repair_two');
foreach([$probeCli,$probeBroken]as$probe){$goal=$probe['data'];unset($goal['stored_funnel']);funnelCheck('CLI preserves original goal result and exit independently of stored status',
    $probe['code']===1&&$goal===['ok'=>false,'status'=>'credential_unavailable','conversion_requests'=>0]&&isset($probe['data']['stored_funnel']));}
funnelCheck('other project probe keeps its exact original three-field contract',$otherProbe['code']===1&&$otherProbe['data']===['ok'=>false,'status'=>'project_unavailable','conversion_requests'=>0]);
echo 'PROJECT FUNNEL CONVERSIONS REGRESSION PASSED '.$passed.PHP_EOL;
