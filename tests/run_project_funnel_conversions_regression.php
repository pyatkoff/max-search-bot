<?php
declare(strict_types=1);
require_once __DIR__.'/run_project_funnel_regression.php';
require_once dirname(__DIR__).'/services/MaxChannelConversionWorker.php';
$pdo=repairFixture();funnelSchema($pdo);$now=time();
$pdo->exec("ALTER TABLE conversations ADD COLUMN is_test INTEGER DEFAULT 0;ALTER TABLE conversations ADD COLUMN lead_stage_key TEXT DEFAULT 'new';
CREATE TABLE lead_stage_history(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,to_stage_key TEXT);
CREATE TABLE max_channel_attribution_settings(project_key TEXT PRIMARY KEY,mode TEXT,counter_id TEXT,activated_at_unix INTEGER,message_cursor INTEGER DEFAULT 0);
CREATE TABLE max_channel_conversion_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,project_key TEXT,platform TEXT DEFAULT 'max',conversation_id INTEGER,external_user_id TEXT,counter_id TEXT,target TEXT,transport_target TEXT,event_unix INTEGER,client_id TEXT,tracker_record_id TEXT,tracker_observed_unix INTEGER,state TEXT DEFAULT 'pending',next_attempt_unix INTEGER DEFAULT 0,claim_token TEXT,claimed_at_unix INTEGER,attempts INTEGER DEFAULT 0,upload_id TEXT,upload_status TEXT,last_error TEXT DEFAULT '',funnel_context_json TEXT,funnel_policy_sha256 TEXT,value_rub TEXT,UNIQUE(project_key,conversation_id,target));");
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
funnelCheck('permission checks preserve collect policy and stored queue history',$selectedSettings['metrika']['mode']==='collect'&&$base['metrika']['mode']==='collect'
    &&$before===$pdo->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll());
require_once __DIR__.'/fixtures/project_funnel_advertising_assertions.php';
projectFunnelNativeAssertions($pdo,'funnelCheck');
echo 'PROJECT FUNNEL CONVERSIONS REGRESSION PASSED '.$passed.PHP_EOL;
