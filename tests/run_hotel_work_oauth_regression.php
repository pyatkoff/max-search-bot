<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerPreparationOAuth.php';
require_once dirname(__DIR__).'/services/ManagerPreparationMcpService.php';
require_once dirname(__DIR__).'/services/HotelWorkEventService.php';

$passed=0;
function hotelOauthCheck(string $name,bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    ++$passed;echo 'PASS '.$name."\n";
}
function hotelOauthReject(string $name,callable $operation,string $reason='invalid_scope'): void
{
    try { $operation(); }
    catch (InvalidArgumentException $error) { hotelOauthCheck($name,$error->getMessage()===$reason);return; }
    throw new RuntimeException('FAIL '.$name.' accepted');
}
function hotelOauthRpc(string $method,array $params=[]): array
{
    return ['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params];
}
function hotelOauthCode(ManagerPreparationOAuth $oauth,string $client,string $scope): array
{
    $verifier=str_repeat('v',43);$session=['csrf'=>'synthetic-hotel-csrf'];
    $request=['client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'response_type'=>'code',
        'resource'=>ManagerPreparationOAuth::RESOURCE,'scope'=>$scope,'state'=>'synthetic-hotel-state',
        'code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')];
    $pending=$oauth->beginAuthorization($session,$request);
    $redirect=$oauth->completeAuthorization($session,$pending,1,$session['csrf'],true);
    parse_str((string)parse_url($redirect,PHP_URL_QUERY),$callback);
    return ['grant_type'=>'authorization_code','client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,
        'resource'=>ManagerPreparationOAuth::RESOURCE,'code'=>$callback['code'],'code_verifier'=>$verifier];
}
final class HotelOauthEventsFixture
{
    public int $subscriptions=0;
    public bool $failChallenge=false;
    public function eventDefinitions(): array { return [['name'=>'hotel_work.pending'],['name'=>'hotel_work.attention']]; }
    public function subscribe(array $params,int $admin,?string $connection): array { if ($this->failChallenge) throw new HotelWorkCallbackError('challenge_failed');++$this->subscriptions;return ['id'=>'synthetic_hotel_subscription']; }
    public function unsubscribe(array $params,int $admin,?string $connection): object { ++$this->subscriptions;return (object)[]; }
}

try {
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
    $now=time();$clock=static fn():int=>$now;
    $oauth=new ManagerPreparationOAuth($pdo,$clock);
    $registration=$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'synthetic-hotel');
    $client=$registration['client_id'];$legacyScope='queue:read prepared:write';$hotelScope='hotel_work:write';
    $allScopes=[$legacyScope,$hotelScope,$legacyScope.' '.$hotelScope];$tokens=[];$contexts=[];
    $hotelCombinedScopes=array_merge(ManagerPreparationOAuth::SCOPES,[ManagerPreparationOAuth::HOTEL_SCOPE]);
    hotelOauthCheck('registration without requested scopes retains original reply capability',$registration['scope']===$legacyScope);
    hotelOauthCheck('metadata advertises hotel capability on the same canonical resource',
        ManagerPreparationOAuth::resourceMetadata()['resource']==='https://app.anytoour.ru/manager/ai-mcp.php'
        && ManagerPreparationOAuth::authorizationMetadata()['scopes_supported']===['queue:read','prepared:write','hotel_work:write','development_work:write']);
    $hotelRegistration=$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI],'scope'=>$hotelScope],'synthetic-hotel');
    hotelOauthCheck('explicit hotel-only registration does not request reply rights',$hotelRegistration['scope']===$hotelScope);
    foreach ($allScopes as $scope) {
        $tokens[$scope]=$oauth->exchange(hotelOauthCode($oauth,$client,$scope));
        $contexts[$scope]=$oauth->contextForBearer('Bearer '.$tokens[$scope]['access_token']);
        hotelOauthCheck('explicit consent binds exact profile '.$scope,$tokens[$scope]['scope']===$scope
            && $contexts[$scope]['scopes']===explode(' ',$scope) && $contexts[$scope]['admin_id']===1);
    }
    foreach (['queue:read','prepared:write','queue:read hotel_work:write','hotel_work:write hotel_work:write','hotel_work:write customer:send',''] as $bad) {
        hotelOauthReject('partial duplicated or unknown capabilities cannot create a grant',fn()=>$oauth->exchange(hotelOauthCode($oauth,$client,$bad)));
    }
    $legacy=$contexts[$legacyScope]['connection_id'];$hotel=$contexts[$hotelScope]['connection_id'];$combined=$contexts[$allScopes[2]]['connection_id'];
    hotelOauthCheck('canonical live grant check separates both capability domains',
        $oauth->connectionAuthorized($legacy,1,ManagerPreparationOAuth::SCOPES)
        && !$oauth->connectionAuthorized($legacy,1,[ManagerPreparationOAuth::HOTEL_SCOPE])
        && $oauth->connectionAuthorized($hotel,1,[ManagerPreparationOAuth::HOTEL_SCOPE])
        && !$oauth->connectionAuthorized($hotel,1,ManagerPreparationOAuth::SCOPES)
        && $oauth->connectionAuthorized($combined,1,$hotelCombinedScopes));
    hotelOauthCheck('static canonical bearer boundary returns actual stored scopes',
        ManagerPreparationOAuth::bearerContext('Bearer '.$tokens[$hotelScope]['access_token'])===$contexts[$hotelScope]
        && ManagerPreparationOAuth::isConnectionAuthorized($hotel,1,[ManagerPreparationOAuth::HOTEL_SCOPE])
        && !ManagerPreparationOAuth::isConnectionAuthorized($legacy,1,[ManagerPreparationOAuth::HOTEL_SCOPE]));
    $refresh=static fn(array $token):array=>['grant_type'=>'refresh_token','client_id'=>$client,
        'resource'=>ManagerPreparationOAuth::RESOURCE,'refresh_token'=>$token['refresh_token']];
    hotelOauthReject('legacy refresh cannot add hotel rights',fn()=>$oauth->exchange($refresh($tokens[$legacyScope])+['scope'=>$allScopes[2]]));
    $legacyRefreshed=$oauth->exchange($refresh($tokens[$legacyScope]));
    hotelOauthCheck('refused escalation does not consume legacy refresh and ordinary rotation stays legacy',
        $legacyRefreshed['scope']===$legacyScope && $oauth->connectionForBearer('Bearer '.$legacyRefreshed['access_token'])===$legacy);
    hotelOauthReject('hotel refresh cannot gain reply rights',fn()=>$oauth->exchange($refresh($tokens[$hotelScope])+['scope'=>$allScopes[2]]));
    $hotelRefreshed=$oauth->exchange($refresh($tokens[$hotelScope])+['scope'=>$hotelScope]);
    hotelOauthCheck('hotel refresh preserves exact hotel-only scope and family',
        $hotelRefreshed['scope']===$hotelScope && $oauth->connectionForBearer('Bearer '.$hotelRefreshed['access_token'])===$hotel);
    $code=hotelOauthCode($oauth,$client,$hotelScope);
    hotelOauthReject('PKCE exchange cannot widen the consented code',fn()=>$oauth->exchange($code+['scope'=>$allScopes[2]]));
    hotelOauthCheck('a rejected code-scope change leaves the original code usable',$oauth->exchange($code)['scope']===$hotelScope);

    $oldTokens=$oauth->exchange(hotelOauthCode($oauth,$client,$hotelScope));
    $old=$oauth->connectionForBearer('Bearer '.$oldTokens['access_token']);
    $oldExpiryQuery=$pdo->prepare('SELECT expires_at FROM manager_preparation_oauth_grants WHERE grant_id=?');
    $oldExpiryQuery->execute([$old]);$oldExpiry=(int)$oldExpiryQuery->fetchColumn();
    $oldUpdate=static function(string $field,$value)use($pdo,$old):void {
        if (!in_array($field,['expires_at','revoked_at','scopes','resource','project_key'],true)) throw new LogicException();
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET '.$field.'=? WHERE grant_id=?')->execute([$value,$old]);
    };
    hotelOauthCheck('active old hotel grant is not retired',!$oauth->connectionRetired($old,1));
    hotelOauthCheck('active same-admin hotel grant has positive identity evidence for ready handoff',
        $oauth->hotelConnectionKnown($old,1)&&!$oauth->hotelConnectionKnown($old,2)
        &&!$oauth->hotelConnectionKnown(str_repeat('0',32),1));
    // Expiring an access token is not expiry of the separately renewable grant.
    $pdo->prepare('UPDATE manager_preparation_oauth_tokens SET expires_at=? WHERE token_hash=?')
        ->execute([$now-1,hash('sha256',$oldTokens['access_token'])]);
    hotelOauthCheck('expired access token alone never authorizes queue handoff',!$oauth->connectionRetired($old,1));
    $oldUpdate('expires_at',$now);
    hotelOauthCheck('exact expired hotel grant has positive canonical retirement proof',$oauth->connectionRetired($old,1));
    $oldUpdate('expires_at',$oldExpiry);$oldUpdate('revoked_at',$now+1);
    hotelOauthCheck('inconsistent future revocation does not become retirement evidence',!$oauth->connectionRetired($old,1));
    $oldUpdate('revoked_at',$now);
    hotelOauthCheck('exact revoked hotel grant has positive same-admin retirement proof',
        $oauth->connectionRetired($old,1)&&!$oauth->connectionRetired($old,2)&&!$oauth->connectionRetired(str_repeat('0',32),1));
    foreach (['scopes'=>$legacyScope,'resource'=>'https://other.invalid/mcp','project_key'=>'other'] as $field=>$value) {
        $oldUpdate($field,$value);
        hotelOauthCheck('wrong old grant binding cannot prove retirement '.$field,!$oauth->connectionRetired($old,1));
        hotelOauthCheck('wrong old grant binding cannot prove ready-handoff identity '.$field,!$oauth->hotelConnectionKnown($old,1));
        $oldUpdate($field,match($field){'scopes'=>$hotelScope,'resource'=>ManagerPreparationOAuth::RESOURCE,'project_key'=>'anytour'});
    }
    $oldUpdate('scopes','hotel_work:write queue:read prepared:write');
    hotelOauthCheck('noncanonical stored scope profile is not old hotel identity evidence',!$oauth->hotelConnectionKnown($old,1));
    $oldUpdate('scopes',$hotelScope);
    $pdo->prepare('UPDATE manager_preparation_oauth_clients SET redirect_uri=? WHERE client_id=?')->execute(['https://other.invalid/callback',$client]);
    hotelOauthCheck('old grant client must retain the pinned callback identity',!$oauth->hotelConnectionKnown($old,1));
    $pdo->prepare('UPDATE manager_preparation_oauth_clients SET redirect_uri=? WHERE client_id=?')->execute([ManagerPreparationOAuth::REDIRECT_URI,$client]);
    $unavailable=new PDO('sqlite::memory:');
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$unavailable);
    hotelOauthCheck('storage absence is never a positive old-grant retirement proof',!ManagerPreparationOAuth::isConnectionRetired($old,1));
    hotelOauthCheck('storage absence is never positive ready-handoff ownership evidence',!(new ManagerPreparationOAuth($unavailable,$clock))->hotelConnectionKnown($old,1));
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);

    $legacyCalls=0;$hotelCalls=0;$events=new HotelOauthEventsFixture();
    $legacyStore=static function(string $operation,array $args) use (&$legacyCalls):array { ++$legacyCalls;return ['jobs'=>[]]; };
    $hotelStore=static function(string $operation,array $args) use (&$hotelCalls):array {
        ++$hotelCalls;$result=['ok'=>true,'operation'=>$operation,'queue_id'=>'SYNTHETIC_QUEUE'];
        if ($operation==='handoff_connection') $result['server_connection_proof']=$args[3]($args[2]['old_connection_id'],$args[0],$args[2]['handoff_mode']??'ready');
        return $result;
    };
    $service=new ManagerPreparationMcpService($pdo,$legacyStore,null,$clock,null,null,null,$hotelStore,$events);
    $call=static fn(string $method,array $params,string $connection,array $scopes):array=>$service->handle(hotelOauthRpc($method,$params),1,$connection,$scopes);
    $legacyTools=$call('tools/list',[],$legacy,ManagerPreparationOAuth::SCOPES)['result']['tools'];
    $hotelTools=$call('tools/list',[],$hotel,[ManagerPreparationOAuth::HOTEL_SCOPE])['result']['tools'];
    $combinedTools=$call('tools/list',[],$combined,$hotelCombinedScopes)['result']['tools'];
    hotelOauthCheck('legacy discovery advertises hotel OAuth metadata without granting hotel access',
        array_values(array_intersect(array_column($legacyTools,'name'),HotelWorkMcpAdapter::NAMES))===HotelWorkMcpAdapter::NAMES
        && in_array('get_reply_context',array_column($legacyTools,'name'),true)
        &&$hotelCalls===0&&$legacyCalls===0);
    hotelOauthCheck('hotel-only discovery keeps hotel tools and advertises separate development consent',array_column($hotelTools,'name')===array_merge(HotelWorkMcpAdapter::NAMES,DevelopmentWorkMcpAdapter::NAMES));
    hotelOauthCheck('explicit combined grant discovers the same declared domains once',array_column($combinedTools,'name')===array_column($legacyTools,'name'));
    foreach ($hotelTools as $tool) {
        if (!in_array($tool['name'],HotelWorkMcpAdapter::NAMES,true)) continue;
        hotelOauthCheck('hotel tool advertises its exact additional OAuth scope',
            $tool['securitySchemes']===[['type'=>'oauth2','scopes'=>['hotel_work:write']]]
            && $tool['_meta']['securitySchemes']===$tool['securitySchemes'] && $tool['inputSchema']['additionalProperties']===false);
    }
    $deliveryTools=array_column($hotelTools,null,'name');
    $deliverySchema=$deliveryTools['hotel_work_claim']['inputSchema'];
    hotelOauthCheck('delivery event proof is declared optional only at the claim boundary',
        ($deliverySchema['properties']['expected_pending_event_id']['pattern']??null)==='^hotel_evt_[a-f0-9]{64}$'
        &&!in_array('expected_pending_event_id',$deliverySchema['required'],true)
        &&!isset($deliveryTools['hotel_work_started']['inputSchema']['properties']['expected_pending_event_id'])
        &&!isset($deliveryTools['hotel_work_complete']['inputSchema']['properties']['expected_pending_event_id']));
    $receivedClaim=null;
    $deliveryAdapter=new HotelWorkMcpAdapter($pdo,static function(string $operation,array $args) use (&$receivedClaim):array {
        $receivedClaim=[$operation,$args];return ['ok'=>true];
    },$clock);
    $deliveryArgs=['queue_id'=>'SYNTHETIC_QUEUE','stage_id'=>str_repeat('5',64),'claim_key'=>str_repeat('6',64),
        'expected_pending_event_id'=>'hotel_evt_'.str_repeat('7',64)];
    $deliveryAdapter->call('hotel_work_claim',$deliveryArgs,1,$hotel);
    hotelOauthCheck('claim adapter preserves the received delivery identity rather than manufacturing proof',
        $receivedClaim===['claim',[1,$hotel,$deliveryArgs]]);
    $selfProofAdapter=new HotelWorkMcpAdapter($pdo,static function(string $operation,array $args):array {
        return ['current_live'=>$args[3]($args[2]['old_connection_id'],$args[0],'ready')];
    },$clock);
    $selfProofArgs=['queue_id'=>'SYNTHETIC_QUEUE','stage_id'=>str_repeat('5',64),
        'baseline_sha256'=>str_repeat('0',64),'old_connection_id'=>$hotel];
    hotelOauthCheck('same-binding ready recovery requires canonical current hotel authorization',
        $selfProofAdapter->call('hotel_work_handoff_connection',$selfProofArgs,1,$hotel)['current_live']===true);
    $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$hotel]);
    hotelOauthCheck('known revoked grant is insufficient for same-binding delivery recovery',
        $oauth->hotelConnectionKnown($hotel,1)
        &&$selfProofAdapter->call('hotel_work_handoff_connection',$selfProofArgs,1,$hotel)['current_live']===false);
    $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=NULL WHERE grant_id=?')->execute([$hotel]);
    hotelOauthCheck('same-binding recovery proof returns only after current access is restored',
        $selfProofAdapter->call('hotel_work_handoff_connection',$selfProofArgs,1,$hotel)['current_live']===true);
    $challengeExpected='Bearer resource_metadata="https://app.anytoour.ru/manager/ai-oauth.php?action=resource", '
        .'error="insufficient_scope", error_description="Explicit hotel queue consent is required", '
        .'scope="queue:read prepared:write hotel_work:write"';
    $grantCount=(int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_grants')->fetchColumn();
    $tokenState=$pdo->query('SELECT token_hash,grant_id,token_kind,expires_at,consumed_at FROM manager_preparation_oauth_tokens ORDER BY token_hash')->fetchAll(PDO::FETCH_ASSOC);
    $challenged=$call('tools/call',['name'=>'hotel_work_create','arguments'=>['queue_id'=>'SYNTHETIC_QUEUE','baseline_sha256'=>str_repeat('1',64)]],$legacy,ManagerPreparationOAuth::SCOPES);
    hotelOauthCheck('legacy hotel invocation returns the standard tool-level step-up challenge before queue access',
        !isset($challenged['error'])&&($challenged['result']['isError']??null)===true
        &&($challenged['result']['_meta']['mcp/www_authenticate']??null)===[$challengeExpected]&&$hotelCalls===0&&$legacyCalls===0);
    hotelOauthCheck('step-up challenge contains no hotel status or supplied arguments',
        !isset($challenged['result']['structuredContent'])&&!str_contains(json_encode($challenged,JSON_THROW_ON_ERROR),'SYNTHETIC_QUEUE'));
    hotelOauthCheck('step-up advertises new consent while retaining the exact old legacy grant and token family',
        $oauth->contextForBearer('Bearer '.$legacyRefreshed['access_token'])['scopes']===ManagerPreparationOAuth::SCOPES
        &&!$oauth->connectionAuthorized($legacy,1,[ManagerPreparationOAuth::HOTEL_SCOPE])
        &&(int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_grants')->fetchColumn()===$grantCount
        &&$pdo->query('SELECT token_hash,grant_id,token_kind,expires_at,consumed_at FROM manager_preparation_oauth_tokens ORDER BY token_hash')->fetchAll(PDO::FETCH_ASSOC)===$tokenState);
    foreach (['hotel_work_handoff_connection','hotel_work_resolve_existing_run'] as $recoveryTool) {
        $challenged=$call('tools/call',['name'=>$recoveryTool,'arguments'=>[]],$legacy,ManagerPreparationOAuth::SCOPES);
        hotelOauthCheck('legacy recovery invocation requires explicit step-up before store '.$recoveryTool,
            ($challenged['result']['isError']??null)===true&&($challenged['result']['_meta']['mcp/www_authenticate']??null)===[$challengeExpected]&&$hotelCalls===0);
    }
    $unknownTool=$call('tools/call',['name'=>'hotel_work_send','arguments'=>[]],$legacy,ManagerPreparationOAuth::SCOPES);
    hotelOauthCheck('unrecognized hotel names cannot fabricate a linking challenge',($unknownTool['error']['code']??null)===-32602&&!isset($unknownTool['result'])&&$hotelCalls===0);
    foreach (['revoked_at','expires_at'] as $terminalField) {
        $invalidTokens=$oauth->exchange(hotelOauthCode($oauth,$client,$legacyScope));
        $invalidConnection=$oauth->connectionForBearer('Bearer '.$invalidTokens['access_token']);
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET '.$terminalField.'=? WHERE grant_id=?')->execute([$now,$invalidConnection]);
        $invalid=$call('tools/call',['name'=>'hotel_work_status','arguments'=>[]],$invalidConnection,ManagerPreparationOAuth::SCOPES);
        hotelOauthCheck('terminal legacy grant gets access denied without step-up '.$terminalField,
            ($invalid['error']['code']??null)===-32001&&!isset($invalid['result'])&&$hotelCalls===0);
    }
    $missing=$call('tools/call',['name'=>'hotel_work_status','arguments'=>[]],str_repeat('9',32),ManagerPreparationOAuth::SCOPES);
    hotelOauthCheck('missing connection gets access denied without a linking challenge',($missing['error']['code']??null)===-32001&&!isset($missing['result'])&&$hotelCalls===0);
    foreach (['list_pending_replies','get_reply_context','save_prepared_reply','capture_virtual_replies'] as $name) {
        $denied=$call('tools/call',['name'=>$name,'arguments'=>[]],$hotel,[ManagerPreparationOAuth::HOTEL_SCOPE]);
        hotelOauthCheck('hotel-only grant cannot call reply or learning tool '.$name,($denied['error']['code']??null)===-32001 && $legacyCalls===0);
    }
    $forged=$call('tools/call',['name'=>'hotel_work_status','arguments'=>['queue_id'=>'SYNTHETIC_QUEUE']],$legacy,ManagerPreparationOAuth::SUPPORTED_SCOPES);
    hotelOauthCheck('caller-supplied widening cannot replace canonical live grant scopes',($forged['error']['code']??null)===-32001 && $hotelCalls===0);
    $ok=$call('tools/call',['name'=>'hotel_work_status','arguments'=>['queue_id'=>'SYNTHETIC_QUEUE']],$hotel,[ManagerPreparationOAuth::HOTEL_SCOPE]);
    hotelOauthCheck('authorized hotel metadata call reaches only hotel adapter',($ok['result']['structuredContent']['operation']??null)==='status' && $hotelCalls===1 && $legacyCalls===0);
    $noConnection=$service->handle(hotelOauthRpc('tools/call',['name'=>'hotel_work_status','arguments'=>['queue_id'=>'SYNTHETIC_QUEUE']]),1,null,[ManagerPreparationOAuth::HOTEL_SCOPE]);
    hotelOauthCheck('hotel work never uses the legacy no-connection direct-call allowance',($noConnection['error']['code']??null)===-32001 && $hotelCalls===1);
    $default=$service->handle(hotelOauthRpc('tools/list'),1,$hotel);
    hotelOauthCheck('omitted explicit scope context never implicitly upgrades direct callers',($default['error']['code']??null)===-32001);
    hotelOauthCheck('legacy event discovery stays unchanged',array_column($call('events/list',[],$legacy,ManagerPreparationOAuth::SCOPES)['result']['events'],'name')===['manager_replies.pending']);
    hotelOauthCheck('hotel-only event discovery never exposes the reply event',array_column($call('events/list',[],$hotel,[ManagerPreparationOAuth::HOTEL_SCOPE])['result']['events'],'name')===['hotel_work.pending','hotel_work.attention']);
    $subscription=['name'=>'hotel_work.pending','arguments'=>['project_key'=>'anytour','queue_id'=>'SYNTHETIC_QUEUE']];
    $denied=$call('events/subscribe',$subscription,$legacy,ManagerPreparationOAuth::SCOPES);
    hotelOauthCheck('legacy grant cannot subscribe to hotel delivery',($denied['error']['code']??null)===-32001 && $events->subscriptions===0);
    $denied=$call('events/subscribe',['name'=>'manager_replies.pending'],$hotel,[ManagerPreparationOAuth::HOTEL_SCOPE]);
    hotelOauthCheck('hotel-only grant cannot subscribe to reply delivery',($denied['error']['code']??null)===-32001 && $events->subscriptions===0);
    $subscribed=$call('events/subscribe',$subscription,$hotel,[ManagerPreparationOAuth::HOTEL_SCOPE]);
    hotelOauthCheck('hotel subscription is routed to its separate event owner',($subscribed['result']['id']??null)==='synthetic_hotel_subscription' && $events->subscriptions===1);
    $events->failChallenge=true;
    $challenge=$call('events/subscribe',$subscription,$hotel,[ManagerPreparationOAuth::HOTEL_SCOPE]);
    hotelOauthCheck('hotel callback failure retains the fixed canonical RPC diagnostic',
        ($challenge['error']['code']??null)===-32015 && ($challenge['error']['data']['reason']??null)==='challenge_failed' && $events->subscriptions===1);
    $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$hotel]);
    $denied=$call('tools/call',['name'=>'hotel_work_status','arguments'=>['queue_id'=>'SYNTHETIC_QUEUE']],$hotel,[ManagerPreparationOAuth::HOTEL_SCOPE]);
    hotelOauthCheck('revocation stops hotel calls before adapter invocation',($denied['error']['code']??null)===-32001 && $hotelCalls===1 && !$oauth->connectionActive($hotel,1));
    $denied=$call('events/unsubscribe',$subscription,$hotel,[ManagerPreparationOAuth::HOTEL_SCOPE]);
    hotelOauthCheck('revoked hotel connection cannot mutate subscription state',($denied['error']['code']??null)===-32001 && $events->subscriptions===1);
    $handoff=['queue_id'=>'SYNTHETIC_QUEUE','stage_id'=>str_repeat('5',64),'baseline_sha256'=>str_repeat('0',64),'old_connection_id'=>$old];
    foreach (['old_connection_retired'=>true,'old_expired'=>true,'retirement_proof'=>['revoked_at'=>$now],'new_connection_id'=>$combined] as $field=>$value) {
        $forged=$call('tools/call',['name'=>'hotel_work_handoff_connection','arguments'=>$handoff+[$field=>$value]],$combined,$hotelCombinedScopes);
        hotelOauthCheck('client handoff proof or identity override cannot reach queue '.$field,($forged['error']['code']??null)===-32602&&$hotelCalls===1);
    }
    $handed=$call('tools/call',['name'=>'hotel_work_handoff_connection','arguments'=>$handoff],$combined,$hotelCombinedScopes);
    hotelOauthCheck('handoff adapter appends canonical proof capability rather than client flags',
        ($handed['result']['structuredContent']['server_connection_proof']??null)===true&&$hotelCalls===2);
    $oldUpdate('revoked_at',null);
    $activeOld=$call('tools/call',['name'=>'hotel_work_handoff_connection','arguments'=>$handoff],$combined,$hotelCombinedScopes);
    hotelOauthCheck('ready handoff accepts positive known old identity while its grant remains active',
        ($activeOld['result']['structuredContent']['server_connection_proof']??null)===true&&$hotelCalls===3
        &&$oauth->connectionAuthorized($old,1,[ManagerPreparationOAuth::HOTEL_SCOPE])&&!$oauth->connectionRetired($old,1));
    $existing=$handoff+['handoff_mode'=>'existing_run','claim_key'=>str_repeat('6',64),'expected_run_id'=>'SYNTHETIC_EXISTING_RUN'];
    $existingActive=$call('tools/call',['name'=>'hotel_work_handoff_connection','arguments'=>$existing],$combined,$hotelCombinedScopes);
    hotelOauthCheck('existing-run adoption still refuses an active old grant',
        ($existingActive['result']['structuredContent']['server_connection_proof']??null)===false&&$hotelCalls===4);
    $oldUpdate('revoked_at',$now);
    $existingRetired=$call('tools/call',['name'=>'hotel_work_handoff_connection','arguments'=>$existing],$combined,$hotelCombinedScopes);
    hotelOauthCheck('existing-run adoption still uses positive canonical retirement proof',
        ($existingRetired['result']['structuredContent']['server_connection_proof']??null)===true&&$hotelCalls===5);
    $recoveryCalls=$hotelCalls;
    foreach ([['handoff_mode'=>'existing_run'],['handoff_mode'=>'existing_run','claim_key'=>str_repeat('6',64)],
        ['handoff_mode'=>'ready','claim_key'=>str_repeat('6',64),'expected_run_id'=>'SYNTHETIC_EXISTING_RUN'],
        ['claim_key'=>str_repeat('6',64),'expected_run_id'=>'SYNTHETIC_EXISTING_RUN'],['handoff_mode'=>'force']] as $forgedMode) {
        $forged=$call('tools/call',['name'=>'hotel_work_handoff_connection','arguments'=>$handoff+$forgedMode],$combined,$hotelCombinedScopes);
        hotelOauthCheck('existing-run adoption requires exact explicit paired mode fields',($forged['error']['code']??null)===-32602&&$hotelCalls===$recoveryCalls);
    }
    $resolution=['queue_id'=>'SYNTHETIC_QUEUE','stage_id'=>str_repeat('5',64),'claim_key'=>str_repeat('6',64),
        'unknown_receipt_sha256'=>str_repeat('7',64),'expected_run_id'=>'SYNTHETIC_EXISTING_RUN','expected_packet_sha256'=>str_repeat('8',64),'receipt'=>[]];
    foreach (['dispatch_allowed'=>true,'old_connection_retired'=>true,'replacement_run_id'=>'SYNTHETIC_NEW_RUN'] as $field=>$value) {
        $forged=$call('tools/call',['name'=>'hotel_work_resolve_existing_run','arguments'=>$resolution+[$field=>$value]],$combined,$hotelCombinedScopes);
        hotelOauthCheck('client resolution cannot inject dispatch proof or alternate run '.$field,($forged['error']['code']??null)===-32602&&$hotelCalls===$recoveryCalls);
    }
    $oldUpdate('revoked_at',null);
    $pdo->exec("UPDATE managers SET role='manager' WHERE id=1");
    hotelOauthCheck('current admin demotion revokes both live capability domains',!$oauth->connectionActive($legacy,1) && !$oauth->connectionActive($combined,1));
    hotelOauthCheck('admin demotion alone is never a positive old-grant retirement proof',!$oauth->connectionActive($old,1)&&!$oauth->connectionRetired($old,1));
    $demoted=$call('tools/call',['name'=>'hotel_work_status','arguments'=>[]],$legacy,ManagerPreparationOAuth::SCOPES);
    hotelOauthCheck('missing administrator role cannot trigger step-up or reach hotel work',
        ($demoted['error']['code']??null)===-32001&&!isset($demoted['result'])&&$hotelCalls===$recoveryCalls);
    echo 'TOTAL '.$passed." hotel OAuth/MCP checks passed\n";
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n");exit(1); }
