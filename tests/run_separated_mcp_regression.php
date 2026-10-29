<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/SeparatedMcpService.php';
$checks=0;
function checkSplit(string $name,bool $ok): void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);++$checks;echo 'PASS '.$name.PHP_EOL;}
function rejectSplit(string $name,callable $fn): void {try{$fn();}catch(InvalidArgumentException $e){checkSplit($name,true);return;}throw new RuntimeException('FAIL '.$name);}
function rpcSplit(string $method,array $params=[]): array {return ['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params];}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE managers(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE manager_preparation_oauth_clients(client_id TEXT PRIMARY KEY,redirect_uri TEXT,registration_bucket TEXT,created_at INTEGER);
CREATE TABLE manager_preparation_oauth_grants(grant_id TEXT PRIMARY KEY,client_id TEXT,admin_id INTEGER,project_key TEXT,resource TEXT,scopes TEXT,created_at INTEGER,expires_at INTEGER,revoked_at INTEGER);
CREATE TABLE manager_preparation_oauth_codes(code_hash TEXT PRIMARY KEY,grant_id TEXT,redirect_uri TEXT,code_challenge TEXT,expires_at INTEGER,consumed_at INTEGER);
CREATE TABLE manager_preparation_oauth_tokens(token_hash TEXT PRIMARY KEY,grant_id TEXT,token_kind TEXT,expires_at INTEGER,consumed_at INTEGER);
INSERT INTO managers VALUES(1,'admin',1,0),(2,'manager',1,1);
INSERT INTO projects VALUES(1,'anytour','Synthetic project',1);
INSERT INTO manager_projects VALUES(1,1),(2,1);");
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
(new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->setValue(null,false);
ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic project']]);
$now=time();$clock=static fn():int=>$now;$oauth=[];$tokens=[];$ctx=[];$clients=[];
foreach (['legacy','development','replies','hotels'] as $profile) {
    $oauth[$profile]=new ManagerPreparationOAuth($pdo,$clock,$profile);
    $def=ManagerPreparationOAuth::profileDefinition($profile);
    $registration=$oauth[$profile]->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'test-'.$profile);
    $clients[$profile]=$registration['client_id'];
    checkSplit('namespace '.$profile,strlen($clients[$profile])===48&&str_starts_with($clients[$profile],$def['prefix']));
    checkSplit('default scopes '.$profile,$registration['scope']===implode(' ',$def['defaults']));
    $verifier=str_repeat('v',43);$session=['csrf'=>'synthetic-csrf'];
    $request=['client_id'=>$clients[$profile],'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'response_type'=>'code',
        'resource'=>$def['resource'],'scope'=>$registration['scope'],'state'=>'synthetic-state',
        'code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')];
    $pending=$oauth[$profile]->beginAuthorization($session,$request);
    $url=$oauth[$profile]->completeAuthorization($session,$pending,1,$session['csrf'],true);
    parse_str((string)parse_url($url,PHP_URL_QUERY),$callback);
    checkSplit('issuer '.$profile,$callback['iss']===$def['issuer']);
    $tokens[$profile]=$oauth[$profile]->exchange(['grant_type'=>'authorization_code','client_id'=>$clients[$profile],
        'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'resource'=>$def['resource'],'code'=>$callback['code'],'code_verifier'=>$verifier]);
    $ctx[$profile]=$oauth[$profile]->contextForBearer('Bearer '.$tokens[$profile]['access_token']);
    checkSplit('consent '.$profile,is_array($ctx[$profile])&&$ctx[$profile]['scopes']===$def['defaults']);
}
foreach ($oauth as $profile=>$auth) foreach ($tokens as $other=>$token) {
    checkSplit('audience '.$profile.' vs '.$other,($auth->contextForBearer('Bearer '.$token['access_token'])!==null)===($profile===$other));
}
foreach (['replies','hotels'] as $profile) {
    $def=ManagerPreparationOAuth::profileDefinition($profile);
    checkSplit('metadata '.$profile,ManagerPreparationOAuth::resourceMetadata($profile)['scopes_supported']===$def['scopes']);
    rejectSplit('no broadened registration '.$profile,fn()=>$oauth[$profile]->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI],'scope'=>'queue:read prepared:write hotel_work:write'],'bad'));
    rejectSplit('no refresh escalation '.$profile,fn()=>$oauth[$profile]->exchange(['grant_type'=>'refresh_token','client_id'=>$clients[$profile],
        'resource'=>$def['resource'],'refresh_token'=>$tokens[$profile]['refresh_token'],'scope'=>'queue:read prepared:write hotel_work:write']));
}
$calls=[];
$store=static function(string $operation,array $args)use(&$calls):array {$calls[]=$operation;return ['ok'=>true,'synthetic'=>true];};
$services=['replies'=>new SeparatedMcpService('replies',$pdo,$store,$clock),'hotels'=>new SeparatedMcpService('hotels',$pdo,$store,$clock)];
foreach ($services as $profile=>$service) {
    $context=$ctx[$profile];
    $invoke=fn(array $rpc):array=>$service->handle($rpc,1,$context['connection_id'],$context['scopes']);
    $tools=$invoke(rpcSplit('tools/list'));
    $expected=$profile==='replies'?SeparatedMcpService::REPLY_TOOLS:HotelWorkMcpAdapter::NAMES;
    checkSplit('exact tools '.$profile,array_column($tools['result']['tools']??[],'name')===$expected);
    $events=$invoke(rpcSplit('events/list'));
    checkSplit('exact events '.$profile,array_column($events['result']['events']??[],'name')===($profile==='replies'?['manager_replies.pending']:HotelWorkEventService::EVENTS));
    foreach (['read_archive_history','read_tour_candidates','claim_virtual_replies','development_work_create',
        $profile==='replies'?'hotel_work_create':'get_reply_context'] as $forbidden) {
        $before=count($calls);$denied=$invoke(rpcSplit('tools/call',['name'=>$forbidden,'arguments'=>[]]));
        checkSplit('dispatch denied '.$profile.'/'.$forbidden,($denied['error']['code']??0)===-32601&&count($calls)===$before);
    }
    $wrongEvent=$profile==='replies'?'hotel_work.pending':'manager_replies.pending';
    checkSplit('subscription domain '.$profile,($invoke(rpcSplit('events/subscribe',['name'=>$wrongEvent]))['error']['code']??0)===-32601);
    foreach (['legacy','development',$profile==='replies'?'hotels':'replies'] as $foreign) {
        checkSplit('service refuses foreign audience '.$profile.'/'.$foreign,($service->handle(rpcSplit('tools/list'),1,$ctx[$foreign]['connection_id'],$context['scopes'])['error']['code']??0)===-32001);
    }
    checkSplit('service refuses different admin '.$profile,($service->handle(rpcSplit('tools/list'),2,$context['connection_id'],$context['scopes'])['error']['code']??0)===-32001);
}
$r=$ctx['replies'];$h=$ctx['hotels'];
$result=$services['replies']->handle(rpcSplit('tools/call',['name'=>'get_reply_context','arguments'=>['job_id'=>'SYNTHETIC']]),1,$r['connection_id'],$r['scopes']);
checkSplit('allowed reply command reaches existing store',!isset($result['error'])&&end($calls)==='context');
$result=$services['hotels']->handle(rpcSplit('tools/call',['name'=>'hotel_work_create','arguments'=>['queue_id'=>'SYNTHETIC','baseline_sha256'=>str_repeat('a',64)]]),1,$h['connection_id'],$h['scopes']);
checkSplit('allowed hotel command reaches existing store',!isset($result['error'])&&end($calls)==='create');
$background=new ManagerPreparationOAuth($pdo,$clock);
checkSplit('reply cron accepts separate resource',$background->eventConnectionAuthorizedAcrossProfiles('replies',$r['connection_id'],1));
checkSplit('hotel cron accepts separate resource',$background->eventConnectionAuthorizedAcrossProfiles('hotels',$h['connection_id'],1));
checkSplit('cron denies crossed domain',!$background->eventConnectionAuthorizedAcrossProfiles('replies',$h['connection_id'],1)&&!$background->eventConnectionAuthorizedAcrossProfiles('hotels',$r['connection_id'],1));
checkSplit('active hotel grant not retired',!$oauth['hotels']->connectionRetired($h['connection_id'],1));

// Exercise the main skip operation through the isolated hotel adapter's canonical proof.
$combinedScope=implode(' ',[...ManagerPreparationOAuth::SCOPES,ManagerPreparationOAuth::HOTEL_SCOPE]);
$skipVerifier=str_repeat('s',43);$skipSession=['csrf'=>'synthetic-skip-csrf'];
$skipRequest=['client_id'=>$clients['legacy'],'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,
    'response_type'=>'code','resource'=>ManagerPreparationOAuth::RESOURCE,'scope'=>$combinedScope,
    'state'=>'synthetic-skip-state','code_challenge_method'=>'S256',
    'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$skipVerifier,true)),'+/','-_'),'=')];
$skipPending=$oauth['legacy']->beginAuthorization($skipSession,$skipRequest);
$skipUrl=$oauth['legacy']->completeAuthorization($skipSession,$skipPending,1,$skipSession['csrf'],true);
parse_str((string)parse_url($skipUrl,PHP_URL_QUERY),$skipCallback);
$skipTokens=$oauth['legacy']->exchange(['grant_type'=>'authorization_code','client_id'=>$clients['legacy'],
    'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'resource'=>ManagerPreparationOAuth::RESOURCE,
    'code'=>$skipCallback['code'],'code_verifier'=>$skipVerifier]);
$combinedContext=$oauth['legacy']->contextForBearer('Bearer '.$skipTokens['access_token']);
checkSplit('skip fixture explicitly consents a legacy combined hotel grant',is_array($combinedContext)
    &&!array_diff([...ManagerPreparationOAuth::SCOPES,ManagerPreparationOAuth::HOTEL_SCOPE],$combinedContext['scopes']));
$skipGrantsBefore=$pdo->query('SELECT * FROM manager_preparation_oauth_grants ORDER BY grant_id')->fetchAll(PDO::FETCH_ASSOC);
$skipTokensBefore=$pdo->query('SELECT * FROM manager_preparation_oauth_tokens ORDER BY token_hash')->fetchAll(PDO::FETCH_ASSOC);
$skipCalls=0;$skipProofs=[];
$skipStore=static function(string $operation,array $arguments)use(&$skipCalls,&$skipProofs):array {
    if ($operation!=='skip_research'||count($arguments)!==4||!is_callable($arguments[3])) throw new LogicException('invalid_skip_adapter_dispatch');
    ++$skipCalls;[$admin,$connection,$args,$proof]=$arguments;
    $current=$proof($connection,$admin,'current');$old=$proof($args['old_connection_id'],$admin,'ready');
    $skipProofs[]=['current'=>$current,'old'=>$old];
    // Match the queue's proof contract without claiming or changing any queue.
    if ($current!==true||$old!==true) throw new DomainException('hotel_work_access_denied');
    return ['ok'=>true,'current'=>$current,'old'=>$old];
};
$skipService=new SeparatedMcpService('hotels',$pdo,$skipStore,$clock);
$skipArgs=['queue_id'=>'SYNTHETIC','stage_id'=>str_repeat('a',64),'baseline_sha256'=>str_repeat('b',64),
    'old_connection_id'=>$combinedContext['connection_id'],'expected_caller_connection_id'=>$h['connection_id'],
    'expected_run_id'=>'SYNTHETIC_SKIP_PROOF','expected_unknown_receipt_sha256'=>null,
    'decision_sha256'=>str_repeat('c',64),'external_effects'=>['status'=>'isolated','readback_sha256'=>str_repeat('d',64)]];
$skipRpc=fn(array $args):array=>rpcSplit('tools/call',['name'=>'hotel_work_skip_research','arguments'=>$args]);
$skipResponse=$skipService->handle($skipRpc($skipArgs),1,$h['connection_id'],$h['scopes']);
checkSplit('isolated hotel skip proves actual hot caller and canonical legacy hotel binding',
    !isset($skipResponse['error'])&&($skipResponse['result']['structuredContent']??null)===['ok'=>true,'current'=>true,'old'=>true]
    &&$skipCalls===1&&$skipProofs===[['current'=>true,'old'=>true]]);
foreach (['replies','development','legacy'] as $invalidOld) {
    $badSkip=$skipArgs;$badSkip['old_connection_id']=$ctx[$invalidOld]['connection_id'];$beforeSkip=$skipCalls;
    $skipResponse=$skipService->handle($skipRpc($badSkip),1,$h['connection_id'],$h['scopes']);
    checkSplit('isolated skip refuses non-hotel old binding '.$invalidOld,
        ($skipResponse['error']['code']??0)===-32001&&$skipCalls===$beforeSkip+1
        &&end($skipProofs)===['current'=>true,'old'=>false]);
}
$skipReplyService=new SeparatedMcpService('replies',$pdo,$skipStore,$clock);$beforeSkip=$skipCalls;
$skipResponse=$skipReplyService->handle($skipRpc($skipArgs),1,$r['connection_id'],$r['scopes']);
checkSplit('reply profile refuses research skip before store',
    ($skipResponse['error']['code']??0)===-32601&&$skipCalls===$beforeSkip);
checkSplit('isolated skip proofs preserve all canonical grants and token families',
    $pdo->query('SELECT * FROM manager_preparation_oauth_grants ORDER BY grant_id')->fetchAll(PDO::FETCH_ASSOC)===$skipGrantsBefore
    &&$pdo->query('SELECT * FROM manager_preparation_oauth_tokens ORDER BY token_hash')->fetchAll(PDO::FETCH_ASSOC)===$skipTokensBefore);

// Reproduce the two independent review findings using real canonical grants.
function unavailableSplit(string $name,callable $call): void
{
    try {$call();} catch (RuntimeException $e) {
        checkSplit($name,$e->getMessage()==='event_oauth_authority_unavailable');return;
    }
    throw new RuntimeException('FAIL '.$name.' converted unknown authority to a boolean');
}
$pdo->exec("CREATE TABLE manager_preparation_events (
 subscription_id TEXT PRIMARY KEY,admin_id INTEGER,oauth_connection_id TEXT,project_key TEXT,event_name TEXT,
 arguments_json TEXT,callback_url TEXT,signing_secret TEXT,previous_secret TEXT,previous_secret_until TEXT,
 verified_at TEXT,expires_at TEXT,is_active INTEGER,observed_jobs_json TEXT,pending_event_id TEXT,pending_body TEXT,
 pending_jobs_json TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,lease_token TEXT,lease_until TEXT,
 last_batch_hash TEXT,last_status INTEGER,created_at TEXT,updated_at TEXT)");
$callback='https://callbacks.example/reply-shared';$arguments=json_encode(['project_key'=>'anytour'],JSON_THROW_ON_ERROR);
$subscription='sub_'.hash('sha256',json_encode([1,$callback,ManagerPreparationMcpService::EVENT,$arguments],JSON_THROW_ON_ERROR));
$seedReply=function(string $connection)use($pdo,$now,$subscription,$arguments,$callback):void {
    $pdo->exec('DELETE FROM manager_preparation_events');
    $date=gmdate('Y-m-d H:i:s',$now);
    $row=['subscription_id'=>$subscription,'admin_id'=>1,'oauth_connection_id'=>$connection,'project_key'=>'anytour',
        'event_name'=>ManagerPreparationMcpService::EVENT,'arguments_json'=>$arguments,'callback_url'=>$callback,
        'signing_secret'=>'whsec_'.base64_encode(str_repeat('k',32)),'verified_at'=>$date,
        'expires_at'=>gmdate('Y-m-d H:i:s',$now+3600),'is_active'=>1,'observed_jobs_json'=>'[]',
        'pending_event_id'=>'synthetic-pending','pending_body'=>'synthetic-body','pending_jobs_json'=>'["synthetic"]',
        'attempts'=>0,'created_at'=>$date,'updated_at'=>$date];
    $pdo->prepare('INSERT INTO manager_preparation_events ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
};
$replyRow=fn():array=>$pdo->query('SELECT * FROM manager_preparation_events')->fetch(PDO::FETCH_ASSOC);
$unsubscribe=rpcSplit('events/unsubscribe',['name'=>ManagerPreparationMcpService::EVENT,
    'arguments'=>['project_key'=>'anytour'],'delivery'=>['mode'=>'webhook','url'=>$callback]]);
$legacyService=new ManagerPreparationMcpService($pdo,$store,clock:$clock);
foreach (['legacy','replies'] as $owner) {
    $seedReply($ctx[$owner]['connection_id']);$before=$replyRow();
    $other=$owner==='legacy'?'replies':'legacy';
    $service=$other==='legacy'?$legacyService:$services['replies'];
    $response=$service->handle($unsubscribe,1,$ctx[$other]['connection_id'],$ctx[$other]['scopes']);
    checkSplit('foreign unsubscribe preserves full row '.$owner,!isset($response['error'])&&$replyRow()===$before);
    $service=$owner==='legacy'?$legacyService:$services['replies'];
    $response=$service->handle($unsubscribe,1,$ctx[$owner]['connection_id'],$ctx[$owner]['scopes']);
    $after=$replyRow();
    checkSplit('owner unsubscribe succeeds '.$owner,!isset($response['error'])&&(int)$after['is_active']===0&&$after['pending_event_id']===null);
    $service->handle($unsubscribe,1,$ctx[$owner]['connection_id'],$ctx[$owner]['scopes']);
    checkSplit('owner unsubscribe remains idempotent '.$owner,$replyRow()===$after);
}
$pdo->exec('DELETE FROM manager_preparation_events');
foreach (['replies','hotels'] as $domain) {
    $grantId=$ctx[$domain]['connection_id'];
    $original=$pdo->query("SELECT * FROM manager_preparation_oauth_grants WHERE grant_id='$grantId'")->fetch(PDO::FETCH_ASSOC);
    foreach (['missing_grant','missing_client','unknown_audience','invalid_scope'] as $failure) {
        $field=match($failure){'missing_grant'=>'grant_id','missing_client'=>'client_id','unknown_audience'=>'resource','invalid_scope'=>'scopes'};
        $bad=$failure==='missing_grant'?str_repeat('e',32):'synthetic-unavailable';
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET '.$field.'=? WHERE grant_id=?')->execute([$bad,$grantId]);
        unavailableSplit('cron unavailable '.$domain.'/'.$failure,fn()=>$background->eventConnectionAuthorizedAcrossProfiles($domain,$grantId,1));
        if ($domain==='replies') {
            $seedReply($grantId);$before=$replyRow();$beforeCalls=count($calls);
            $delivery=new ManagerPreparationMcpService($pdo,$store,clock:$clock,
                connectionActive:fn(string $connection,int $admin):bool=>$background->eventConnectionAuthorizedAcrossProfiles('replies',$connection,$admin));
            $counts=$delivery->deliverPending();$after=$replyRow();
            $mutable=array_flip(['next_attempt_at','lease_token','lease_until','updated_at']);
            checkSplit('reply delivery defers without stopping '.$failure,$counts['stopped']===0&&$counts['retried']===1
                &&array_diff_key($before,$mutable)===array_diff_key($after,$mutable)&&count($calls)===$beforeCalls);
        }
        $where=$field==='grant_id'?$bad:$grantId;
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET '.$field.'=? WHERE grant_id=?')->execute([$original[$field],$where]);
        checkSplit('authority resumes after repair '.$domain.'/'.$failure,$background->eventConnectionAuthorizedAcrossProfiles($domain,$grantId,1));
    }
    $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$grantId]);
    checkSplit('known revocation remains denial '.$domain,!$background->eventConnectionAuthorizedAcrossProfiles($domain,$grantId,1));
    $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=NULL WHERE grant_id=?')->execute([$grantId]);
}
$pdo->exec('DELETE FROM manager_preparation_events');
$pdo->beginTransaction();
unavailableSplit('cron refuses transaction snapshot',fn()=>$background->eventConnectionAuthorizedAcrossProfiles('replies',$r['connection_id'],1));
$pdo->rollBack();
// A missing canonical row must not permanently stop a hotel delivery either.
require_once __DIR__.'/fixtures/hotel_work_events.php';
$f=new HotelEventsFixture();$f->subscribe(false);
$f->pdo->exec('CREATE TABLE manager_preparation_oauth_grants(grant_id TEXT,admin_id INTEGER,client_id TEXT);
 CREATE TABLE manager_preparation_oauth_clients(client_id TEXT,redirect_uri TEXT)');
$hotelBackground=new ManagerPreparationOAuth($f->pdo,fn():int=>$f->at);
$hotelDelivery=new HotelWorkEventService($f->pdo,$f->webhook,fn():int=>$f->at,
    fn(string $connection,int $admin,array $required):bool=>$hotelBackground->eventConnectionAuthorizedAcrossProfiles('hotels',$connection,$admin),$f->queue);
$before=$f->row();$queueBefore=$f->status();$result=$hotelDelivery->deliverPending();$after=$f->row();
$mutable=array_flip(['next_attempt_at','lease_token','lease_until','updated_at']);
checkSplit('hotel delivery defers unknown grant preserving work',$result['stopped']===0
    &&array_diff_key($before,$mutable)===array_diff_key($after,$mutable)&&$f->requests===[]&&$f->status()===$queueBefore);
// Restore the original synthetic service context for the remaining revocation tests.
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);

$oauth['replies']->disconnect(['csrf'=>'csrf'],1,$r['connection_id'],'csrf');
checkSplit('reply revoke does not revoke hotels',$oauth['hotels']->contextForBearer('Bearer '.$tokens['hotels']['access_token'])!==null&&$oauth['legacy']->contextForBearer('Bearer '.$tokens['legacy']['access_token'])!==null);
checkSplit('revoked reply cannot call service',($services['replies']->handle(rpcSplit('tools/list'),1,$r['connection_id'],$r['scopes'])['error']['code']??0)===-32001);
checkSplit('reply issuer cannot revoke hotel grant',($oauth['replies']->revoke(['token'=>$tokens['hotels']['access_token'],'client_id'=>$clients['hotels']])===null)&&$oauth['hotels']->contextForBearer('Bearer '.$tokens['hotels']['access_token'])!==null);
echo 'SEPARATED MCP: '.$checks.' PASS'.PHP_EOL;

