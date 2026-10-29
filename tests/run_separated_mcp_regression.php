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
$oauth['replies']->disconnect(['csrf'=>'csrf'],1,$r['connection_id'],'csrf');
checkSplit('reply revoke does not revoke hotels',$oauth['hotels']->contextForBearer('Bearer '.$tokens['hotels']['access_token'])!==null&&$oauth['legacy']->contextForBearer('Bearer '.$tokens['legacy']['access_token'])!==null);
checkSplit('revoked reply cannot call service',($services['replies']->handle(rpcSplit('tools/list'),1,$r['connection_id'],$r['scopes'])['error']['code']??0)===-32001);
checkSplit('reply issuer cannot revoke hotel grant',($oauth['replies']->revoke(['token'=>$tokens['hotels']['access_token'],'client_id'=>$clients['hotels']])===null)&&$oauth['hotels']->contextForBearer('Bearer '.$tokens['hotels']['access_token'])!==null);
echo 'SEPARATED MCP: '.$checks.' PASS'.PHP_EOL;
