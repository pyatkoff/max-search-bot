<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerPreparationOAuth.php';
require_once dirname(__DIR__).'/services/ManagerPreparationMcpService.php';
require_once dirname(__DIR__).'/services/DevelopmentWorkMcpAdapter.php';
require_once dirname(__DIR__).'/services/DevelopmentWorkEventService.php';

$passed=0;
function devOauthCheck(string $name,bool $ok): void
{
    global $passed;if (!$ok) throw new RuntimeException('FAIL '.$name);++$passed;
}
function devOauthRpc(string $method,array $params=[]): array { return ['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params]; }
function devOauthCode(ManagerPreparationOAuth $oauth,string $client,string $scope): array
{
    $verifier=str_repeat('v',43);$session=['csrf'=>'synthetic-development-csrf'];
    $request=['client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'response_type'=>'code',
        'resource'=>ManagerPreparationOAuth::RESOURCE,'scope'=>$scope,'state'=>'synthetic-development-state',
        'code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')];
    $pending=$oauth->beginAuthorization($session,$request);
    $redirect=$oauth->completeAuthorization($session,$pending,1,$session['csrf'],true);
    parse_str((string)parse_url($redirect,PHP_URL_QUERY),$callback);
    return ['grant_type'=>'authorization_code','client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,
        'resource'=>ManagerPreparationOAuth::RESOURCE,'code'=>$callback['code'],'code_verifier'=>$verifier];
}
function devOauthChallengeScopes(array $reply): ?array
{
    $headers=$reply['result']['_meta']['mcp/www_authenticate']??null;
    if (($reply['result']['isError']??null)!==true||!is_array($headers)||count($headers)!==1
        ||preg_match('/(?:^|, )scope="([^"]+)"/',$headers[0],$match)!==1) return null;
    return explode(' ',$match[1]);
}
final class DevelopmentOauthEventsFixture
{
    public int $subscriptions=0;
    public bool $failChallenge=false;
    public function eventDefinitions(): array { return [['name'=>'development_work.pending'],['name'=>'development_work.attention']]; }
    public function subscribe(array $params,int $admin,?string $connection): array
    {
        if ($this->failChallenge) throw new DevelopmentWorkCallbackError('challenge_failed');
        ++$this->subscriptions;return ['id'=>'synthetic_development_subscription'];
    }
    public function unsubscribe(array $params,int $admin,?string $connection): object { ++$this->subscriptions;return (object)[]; }
}
final class DevelopmentOauthHotelEventsFixture
{
    public int $subscriptions=0;
    public function eventDefinitions(): array { return [['name'=>'hotel_work.pending'],['name'=>'hotel_work.attention']]; }
    public function subscribe(array $params,int $admin,?string $connection): array { ++$this->subscriptions;return ['id'=>'synthetic_hotel_subscription']; }
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
    $now=time();$clock=static fn():int=>$now;$oauth=new ManagerPreparationOAuth($pdo,$clock);
    $legacyScopes=ManagerPreparationOAuth::SCOPES;$hotelScope=ManagerPreparationOAuth::HOTEL_SCOPE;$devScope=ManagerPreparationOAuth::DEVELOPMENT_SCOPE;
    $archiveScope=ManagerPreparationOAuth::ARCHIVE_SCOPE;
    devOauthCheck('development scope is separate from hotel and reply scopes',$devScope==='development_work:write'
        &&!in_array($devScope,$legacyScopes,true)&&$devScope!==$hotelScope);
    $registration=$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'synthetic-development');
    devOauthCheck('default registration retains only original reply rights',$registration['scope']===implode(' ',$legacyScopes));
    $client=$registration['client_id'];$profiles=['legacy'=>$legacyScopes,'hotel'=>[$hotelScope],'dev'=>[$devScope],
        'reply_dev'=>array_merge($legacyScopes,[$devScope]),'hotel_dev'=>[$hotelScope,$devScope],
        'archive'=>[$archiveScope],'archive_dev'=>[$archiveScope,$devScope]];
    $tokens=[];$connections=[];
    foreach ($profiles as $name=>$scopes) {
        $tokens[$name]=$oauth->exchange(devOauthCode($oauth,$client,implode(' ',$scopes)));
        $context=$oauth->contextForBearer('Bearer '.$tokens[$name]['access_token']);
        $connections[$name]=$oauth->connectionForBearer('Bearer '.$tokens[$name]['access_token']);
        devOauthCheck('explicit consent creates exactly requested capability profile '.$name,$context['scopes']===$scopes
            &&$oauth->connectionAuthorized($connections[$name],1,$scopes));
    }
    $refresh=['grant_type'=>'refresh_token','client_id'=>$client,'resource'=>ManagerPreparationOAuth::RESOURCE,'refresh_token'=>$tokens['dev']['refresh_token']];
    try {$oauth->exchange($refresh+['scope'=>implode(' ',ManagerPreparationOAuth::SUPPORTED_SCOPES)]);devOauthCheck('refresh escalation is rejected',false);}
    catch (InvalidArgumentException $expected) {devOauthCheck('refresh cannot widen development-only consent',$expected->getMessage()==='invalid_scope');}
    $refreshed=$oauth->exchange($refresh);
    devOauthCheck('ordinary refresh preserves exact development capability and family',$refreshed['scope']===$devScope
        &&$oauth->connectionForBearer('Bearer '.$refreshed['access_token'])===$connections['dev']);
    $legacyCalls=0;$hotelCalls=0;$developmentCalls=0;$developmentArguments=[];
    $legacyStore=static function(string $operation,array $args) use (&$legacyCalls):array {++$legacyCalls;return ['jobs'=>[]];};
    $hotelStore=static function(string $operation,array $args) use (&$hotelCalls):array {++$hotelCalls;return ['ok'=>true,'operation'=>$operation];};
    $developmentStore=static function(string $operation,array $args) use (&$developmentCalls,&$developmentArguments):array {
        ++$developmentCalls;$developmentArguments=[$operation,$args];return ['ok'=>true,'operation'=>$operation];
    };
    $hotelEvents=new DevelopmentOauthHotelEventsFixture();$events=new DevelopmentOauthEventsFixture();
    $service=new ManagerPreparationMcpService($pdo,$legacyStore,null,$clock,null,null,null,$hotelStore,$hotelEvents,null,$developmentStore,$events);
    $call=static fn(string $method,array $params,string $profile):array=>$service->handle(devOauthRpc($method,$params),1,$connections[$profile],$profiles[$profile]);
    foreach (['legacy','hotel','dev'] as $profile) {
        $tools=$call('tools/list',[],$profile)['result']['tools'];$byName=array_column($tools,null,'name');
        foreach (DevelopmentWorkMcpAdapter::NAMES as $name) {
            devOauthCheck('discovery advertises explicit development consent without granting it '.$profile.' '.$name,
                isset($byName[$name])&&$byName[$name]['securitySchemes']===[['type'=>'oauth2','scopes'=>[$devScope]]]
                &&$byName[$name]['_meta']['securitySchemes']===$byName[$name]['securitySchemes']
                &&$byName[$name]['inputSchema']['additionalProperties']===false);
        }
    }
    devOauthCheck('discovery performs no queue or worker operations',$legacyCalls===0&&$hotelCalls===0&&$developmentCalls===0);
    $tools=array_column($call('tools/list',[],'dev')['result']['tools'],null,'name');
    $claimSchema=$tools['development_work_claim']['inputSchema'];
    devOauthCheck('development claim requires received current event identity in declared schema',
        in_array('expected_pending_event_id',$claimSchema['required'],true)
        &&($claimSchema['properties']['expected_pending_event_id']['pattern']??null)==='^dev_evt_[a-f0-9]{64}$'
        &&!isset($tools['development_work_started']['inputSchema']['properties']['expected_pending_event_id'])
        &&!isset($tools['development_work_complete']['inputSchema']['properties']['expected_pending_event_id']));
    $resolutionSchema=$tools['development_work_resolve_existing_run']['inputSchema']['properties']['receipt'];
    $resolutionProperties=$resolutionSchema['properties'];
    devOauthCheck('resolution advertises accepted or terminal blocked evidence while unknown remains held',
        ($resolutionProperties['outcome']['enum']??[])===['accepted_readback','terminal_blocked']
        &&($resolutionProperties['run_id']['type']??null)==='string'
        &&($resolutionProperties['result_sha256']['type']??null)==='string'
        &&($resolutionProperties['readback_sha256']['type']??null)==='string'
        &&($resolutionProperties['owned_claims_released']['const']??null)===true);
    devOauthCheck('resolution keeps conditional acceptance fields nullable with all evidence fields explicit',
        in_array('null',(array)($resolutionProperties['acceptance_sha256']['type']??[]),true)
        &&in_array('null',(array)($resolutionProperties['reviewer_run_id']['type']??[]),true)
        &&$resolutionSchema['required']===['run_id','outcome','result_sha256','readback_sha256','acceptance_sha256','reviewer_run_id','owned_claims_released']
        &&$resolutionSchema['additionalProperties']===false);
    $retirementSchema=$tools['development_work_retire_unclaimed']['inputSchema'];
    $retirementRequired=$retirementSchema['required'];sort($retirementRequired);
    $retirementFields=['queue_id','task_id','spec_sha256','expected_plan_sha256','expected_pending_event_id','decision'];sort($retirementFields);
    $decisionSchema=$retirementSchema['properties']['decision'];$decisionRequired=$decisionSchema['required'];sort($decisionRequired);
    devOauthCheck('unclaimed retirement declares a separate strict metadata decision without worker run or claim input',
        $retirementRequired===$retirementFields&&$retirementSchema['additionalProperties']===false
        &&!isset($retirementSchema['properties']['claim_key'])&&!isset($retirementSchema['properties']['run_id'])
        &&!isset($retirementSchema['properties']['receipt'])
        &&($retirementSchema['properties']['expected_pending_event_id']['pattern']??null)==='^dev_evt_[a-f0-9]{64}$'
        &&$decisionRequired===['decision_sha256','readback_sha256','reviewer_run_id']&&$decisionSchema['additionalProperties']===false);
    $retirementCalls=0;$seenRetirement=null;
    $retirementStore=static function(string $operation,array $args) use (&$retirementCalls,&$seenRetirement):array {
        ++$retirementCalls;$seenRetirement=[$operation,$args];return ['ok'=>true,'operation'=>$operation];
    };
    $retirementService=new ManagerPreparationMcpService($pdo,$legacyStore,null,$clock,null,null,null,$hotelStore,$hotelEvents,null,$retirementStore,$events);
    $retirementArgs=['queue_id'=>'SYNTHETIC_DEV','task_id'=>'A','spec_sha256'=>str_repeat('5',64),
        'expected_plan_sha256'=>str_repeat('6',64),'expected_pending_event_id'=>'dev_evt_'.str_repeat('7',64),
        'decision'=>['decision_sha256'=>str_repeat('8',64),'readback_sha256'=>str_repeat('9',64),'reviewer_run_id'=>'SYNTHETIC-REVIEW']];
    $retirementRpc=static fn(array $args,string $profile):array=>$retirementService->handle(devOauthRpc('tools/call',
        ['name'=>'development_work_retire_unclaimed','arguments'=>$args]),1,$connections[$profile],$profiles[$profile]);
    $retirementReply=$retirementRpc($retirementArgs,'dev');
    devOauthCheck('authorized retirement forwards only the exact metadata intent and canonical owner',
        ($retirementReply['result']['structuredContent']['operation']??null)==='retire_unclaimed'
        &&$seenRetirement===['retire_unclaimed',[1,$connections['dev'],$retirementArgs]]&&$retirementCalls===1);
    foreach ([
        $retirementArgs+['claim_key'=>str_repeat('b',64)],$retirementArgs+['run_id'=>'INVENTED'],
        $retirementArgs+['owned_claims_released'=>true],
        array_replace($retirementArgs,['expected_pending_event_id'=>'hotel_evt_'.str_repeat('7',64)]),
        array_replace($retirementArgs,['expected_plan_sha256'=>'not-a-sha']),
        array_replace($retirementArgs,['decision'=>array_replace($retirementArgs['decision'],['reviewer_run_id'=>null])]),
        array_replace($retirementArgs,['decision'=>$retirementArgs['decision']+['dispatch_allowed'=>true]])
    ] as $badRetirement) {
        $denied=$retirementRpc($badRetirement,'dev');
        devOauthCheck('retirement rejects forged worker proof foreign event or malformed decision before queue callback',
            ($denied['error']['code']??null)===-32602&&$retirementCalls===1);
    }
    $legacyRetirement=$retirementRpc($retirementArgs,'legacy');
    devOauthCheck('retirement never upgrades a legacy grant or routes to hotel or reply stores',
        devOauthChallengeScopes($legacyRetirement)===array_merge($legacyScopes,[$devScope])
        &&$retirementCalls===1&&$hotelCalls===0&&$legacyCalls===0&&$developmentCalls===0);
    $grantBefore=$pdo->query('SELECT * FROM manager_preparation_oauth_grants ORDER BY grant_id')->fetchAll(PDO::FETCH_ASSOC);
    $tokenBefore=$pdo->query('SELECT * FROM manager_preparation_oauth_tokens ORDER BY token_hash')->fetchAll(PDO::FETCH_ASSOC);
    $archiveStep=$call('tools/call',['name'=>'archive_research_inventory','arguments'=>[]],'dev');
    devOauthCheck('development-only archive step-up requests exactly explicit archive addition without reading data',
        devOauthChallengeScopes($archiveStep)===[$archiveScope,$devScope]&&$legacyCalls===0&&$hotelCalls===0&&$developmentCalls===0);
    $archiveDevDenied=$call('tools/call',['name'=>'development_work_status','arguments'=>['queue_id'=>'SYNTHETIC_DEV']],'archive');
    devOauthCheck('archive-only consent cannot call or implicitly acquire development work',
        ($archiveDevDenied['error']['code']??null)===-32001&&$developmentCalls===0);
    $archiveHotelStep=$call('tools/call',['name'=>'hotel_work_status','arguments'=>['queue_id'=>'SYNTHETIC_HOTEL']],'archive_dev');
    devOauthCheck('hotel step-up preserves both already-consented archive and development rights',
        devOauthChallengeScopes($archiveHotelStep)===[$hotelScope,$archiveScope,$devScope]&&$hotelCalls===0&&$developmentCalls===0);
    foreach (['legacy','hotel'] as $profile) {
        $challenge=$call('tools/call',['name'=>'development_work_status','arguments'=>['queue_id'=>'SYNTHETIC_DEV']],$profile);
        $wanted=array_values(array_intersect(ManagerPreparationOAuth::SUPPORTED_SCOPES,array_merge($profiles[$profile],[$devScope])));
        devOauthCheck('step-up preserves existing rights and requests only development addition '.$profile,
            devOauthChallengeScopes($challenge)===$wanted&&!isset($challenge['result']['structuredContent'])
            &&!str_contains(json_encode($challenge,JSON_THROW_ON_ERROR),'SYNTHETIC_DEV')&&$developmentCalls===0);
    }
    $hotelChallenge=$call('tools/call',['name'=>'hotel_work_status','arguments'=>['queue_id'=>'SYNTHETIC_HOTEL']],'reply_dev');
    devOauthCheck('adding hotel consent preserves already consented development without changing existing grant',
        devOauthChallengeScopes($hotelChallenge)===array_merge($legacyScopes,[$hotelScope,$devScope])&&$hotelCalls===0);
    devOauthCheck('scope challenges never mint or widen grants or tokens',
        $grantBefore===$pdo->query('SELECT * FROM manager_preparation_oauth_grants ORDER BY grant_id')->fetchAll(PDO::FETCH_ASSOC)
        &&$tokenBefore===$pdo->query('SELECT * FROM manager_preparation_oauth_tokens ORDER BY token_hash')->fetchAll(PDO::FETCH_ASSOC));
    foreach (['list_pending_replies','get_reply_context','save_prepared_reply','capture_virtual_replies'] as $name) {
        $denied=$call('tools/call',['name'=>$name,'arguments'=>[]],'dev');
        devOauthCheck('development-only consent cannot call reply learning or customer context '.$name,
            ($denied['error']['code']??null)===-32001&&$legacyCalls===0);
    }
    $hotelChallenge=$call('tools/call',['name'=>'hotel_work_status','arguments'=>['queue_id'=>'SYNTHETIC_HOTEL']],'dev');
    devOauthCheck('development consent does not grant hotel access or reply rights',
        ($hotelChallenge['error']['code']??null)===-32001&&$hotelCalls===0);
    $ok=$call('tools/call',['name'=>'development_work_status','arguments'=>['queue_id'=>'SYNTHETIC_DEV']],'dev');
    devOauthCheck('development call reaches only its own adapter',($ok['result']['structuredContent']['operation']??null)==='status'
        &&$developmentCalls===1&&$hotelCalls===0&&$legacyCalls===0);
    $args=['queue_id'=>'SYNTHETIC_DEV','task_id'=>'A','spec_sha256'=>str_repeat('5',64),'claim_key'=>str_repeat('6',64),
        'expected_pending_event_id'=>'dev_evt_'.str_repeat('7',64)];
    $call('tools/call',['name'=>'development_work_claim','arguments'=>$args],'dev');
    devOauthCheck('claim adapter forwards exact received identity and authenticated owner only',
        $developmentArguments===['claim',[1,$connections['dev'],$args]]&&$developmentCalls===2);
    foreach (['admin_id'=>2,'oauth_connection_id'=>$connections['hotel'],'dispatch_allowed'=>true,'hotel_queue_id'=>'HOTEL'] as $field=>$value) {
        $bad=$call('tools/call',['name'=>'development_work_claim','arguments'=>$args+[$field=>$value]],'dev');
        devOauthCheck('client identity or cross-domain overrides never reach adapter '.$field,
            ($bad['error']['code']??null)===-32602&&$developmentCalls===2);
    }
    $missing=$args;unset($missing['expected_pending_event_id']);
    $bad=$call('tools/call',['name'=>'development_work_claim','arguments'=>$missing],'dev');
    devOauthCheck('missing event fence is rejected before queue callback',($bad['error']['code']??null)===-32602&&$developmentCalls===2);
    $bad=$call('tools/call',['name'=>'development_work_claim','arguments'=>array_replace($args,['expected_pending_event_id'=>'hotel_evt_'.str_repeat('7',64)])],'dev');
    devOauthCheck('hotel event namespace cannot admit development work',($bad['error']['code']??null)===-32602&&$developmentCalls===2);
    $badStarted=$call('tools/call',['name'=>'development_work_started','arguments'=>$args+['run_id'=>'SYNTHETIC_RUN']],'dev');
    $badReceipt=['run_id'=>null,'outcome'=>'unknown','result_sha256'=>null,'readback_sha256'=>null,
        'acceptance_sha256'=>null,'reviewer_run_id'=>null,'owned_claims_released'=>false];
    $badComplete=$call('tools/call',['name'=>'development_work_complete','arguments'=>$args+['receipt'=>$badReceipt]],'dev');
    devOauthCheck('claim-only delivery field is never accepted at started or complete boundaries',
        ($badStarted['error']['code']??null)===-32602&&($badComplete['error']['code']??null)===-32602&&$developmentCalls===2);
    $forged=$service->handle(devOauthRpc('tools/call',['name'=>'development_work_status','arguments'=>['queue_id'=>'SYNTHETIC_DEV']]),
        1,$connections['hotel'],ManagerPreparationOAuth::SUPPORTED_SCOPES);
    devOauthCheck('caller-supplied scopes cannot widen canonical live grant',($forged['error']['code']??null)===-32001&&$developmentCalls===2);
    $noConnection=$service->handle(devOauthRpc('tools/call',['name'=>'development_work_status','arguments'=>['queue_id'=>'SYNTHETIC_DEV']]),1,null,[$devScope]);
    devOauthCheck('development has no unauthenticated legacy direct-call allowance',($noConnection['error']['code']??null)===-32001&&$developmentCalls===2);
    $manager=$service->handle(devOauthRpc('tools/call',['name'=>'development_work_status','arguments'=>['queue_id'=>'SYNTHETIC_DEV']]),2,$connections['dev'],[$devScope]);
    devOauthCheck('ordinary manager cannot use admin development queue',($manager['error']['code']??null)===-32001&&$developmentCalls===2);
    devOauthCheck('development event discovery is isolated',array_column($call('events/list',[],'dev')['result']['events'],'name')
        ===['development_work.pending','development_work.attention']);
    $subscription=['name'=>'development_work.pending','arguments'=>['project_key'=>'anytour','queue_id'=>'SYNTHETIC_DEV']];
    foreach (['legacy','hotel','archive'] as $profile) {
        $denied=$call('events/subscribe',$subscription,$profile);
        devOauthCheck('nondevelopment grant cannot subscribe to development delivery '.$profile,
            ($denied['error']['code']??null)===-32001&&$events->subscriptions===0&&$hotelEvents->subscriptions===0);
    }
    $subscribed=$call('events/subscribe',$subscription,'dev');
    devOauthCheck('development subscription reaches only its separate event service',
        ($subscribed['result']['id']??null)==='synthetic_development_subscription'&&$events->subscriptions===1&&$hotelEvents->subscriptions===0);
    foreach (['hotel_work.pending','manager_replies.pending'] as $foreignEvent) {
        $denied=$call('events/subscribe',['name'=>$foreignEvent,'arguments'=>$subscription['arguments']],'dev');
        devOauthCheck('development-only grant cannot subscribe to foreign event '.$foreignEvent,($denied['error']['code']??null)===-32001
            &&$events->subscriptions===1&&$hotelEvents->subscriptions===0);
    }
    $events->failChallenge=true;$failed=$call('events/subscribe',$subscription,'dev');
    devOauthCheck('development callback failure uses the bounded canonical diagnostic',
        ($failed['error']['code']??null)===-32015&&($failed['error']['data']['reason']??null)==='challenge_failed'&&$events->subscriptions===1);
    // Use real queue rows and fresh canonical OAuth grants at the owned-status boundary.
    require_once __DIR__.'/fixtures/development_work_queue.php';
    DevelopmentWorkTestSchema::create($pdo);$statusQueue=new DevelopmentWorkQueue($pdo,$clock);
    $selfTokens=$oauth->exchange(devOauthCode($oauth,$client,$devScope));
    $selfContext=$oauth->contextForBearer('Bearer '.$selfTokens['access_token']);$selfConnection=$selfContext['connection_id'];
    $wideTokens=$oauth->exchange(devOauthCode($oauth,$client,implode(' ',ManagerPreparationOAuth::SUPPORTED_SCOPES)));
    $wideContext=$oauth->contextForBearer('Bearer '.$wideTokens['access_token']);
    $otherClient=$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI],'scope'=>$devScope],'synthetic-status-foreign')['client_id'];
    $otherTokens=$oauth->exchange(devOauthCode($oauth,$otherClient,$devScope));
    $otherContext=$oauth->contextForBearer('Bearer '.$otherTokens['access_token']);$otherConnection=$otherContext['connection_id'];
    $statusQueue->create(1,$otherConnection,['queue_id'=>'PRIVATE_FOREIGN_QUEUE','plan_sha256'=>str_repeat('a',64),'tasks'=>[
        ['task_id'=>'PRIVATE_FOREIGN_TASK','owner_task_id'=>'PRIVATE_FOREIGN_OWNER','reviewer_task_id'=>'PRIVATE_FOREIGN_REVIEWER',
            'input_sha256'=>str_repeat('b',64),'acceptance_contract_sha256'=>str_repeat('c',64),'deps'=>[],'priority'=>50]]]);
    $selfService=new ManagerPreparationMcpService($pdo,null,null,$clock);
    $statusRpc=static fn(string $queue):array=>devOauthRpc('tools/call',['name'=>'development_work_status','arguments'=>['queue_id'=>$queue]]);
    $canonicalStatus=static function(array $value):string {
        $sort=static function(array $a)use(&$sort):array {if (!array_is_list($a)) ksort($a,SORT_STRING);foreach ($a as &$v) if (is_array($v)) $v=$sort($v);return $a;};
        return json_encode($sort($value),JSON_THROW_ON_ERROR);
    };
    $queueSnapshot=static function()use($pdo):array {$out=[];foreach (DevelopmentWorkTestSchema::tables() as $table) $out[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);return $out;};
    $authDenied=static function(array $reply,?string $phase='AUTH_CONTEXT_UNAVAILABLE'):bool {
        $wire=json_encode($reply,JSON_THROW_ON_ERROR);
        return ($reply['error']['code']??null)===-32001&&($phase===null||str_contains((string)($reply['error']['message']??''),$phase))
            &&!isset($reply['result'])&&!str_contains($wire,'caller_self')&&!str_contains($wire,'OWNED_QUEUE_UNAVAILABLE');
    };
    $beforeQueues=$queueSnapshot();$beforeChanges=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
    $beforeScopes=$pdo->query('SELECT grant_id,scopes FROM manager_preparation_oauth_grants ORDER BY grant_id')->fetchAll(PDO::FETCH_ASSOC);
    foreach (['dev'=>[$selfConnection,false],'all'=>[$wideContext['connection_id'],true]] as $label=>[$connection,$all]) {
        // Pass only DEV to the HTTP-owner seam: caller_self must use the actual wider grant.
        $missing=$selfService->handle($statusRpc('PRIVATE_MISSING_QUEUE'),1,$connection,[$devScope]);
        $foreign=$selfService->handle($statusRpc('PRIVATE_FOREIGN_QUEUE'),1,$connection,[$devScope]);
        $expectedSelf=['connection_id'=>$connection,'client_id'=>$client,'active_project_admin'=>true,'capabilities'=>[
            'reply_preparation'=>$all,'hotel_work'=>$all,'archive_research'=>$all,'development_work'=>true]];
        $expected=['ok'=>false,'reason'=>'OWNED_QUEUE_UNAVAILABLE','queue_available'=>false,'caller_self'=>$expectedSelf];
        $result=$missing['result']??[];
        devOauthCheck('missing and real foreign queue have identical canonical unavailable response '.$label,$missing===$foreign
            &&($result['isError']??null)===true&&$canonicalStatus($result['structuredContent']??[])===$canonicalStatus($expected)
            &&count($result['content']??[])===1&&($result['content'][0]['type']??null)==='text'
            &&json_decode((string)($result['content'][0]['text']??''),true,16,JSON_THROW_ON_ERROR)===($result['structuredContent']??null));
        $wire=json_encode($missing,JSON_THROW_ON_ERROR);
        foreach (['PRIVATE_MISSING_QUEUE','PRIVATE_FOREIGN_QUEUE','PRIVATE_FOREIGN_TASK','PRIVATE_FOREIGN_OWNER','PRIVATE_FOREIGN_REVIEWER',$otherConnection,$otherClient] as $private) {
            devOauthCheck('unavailable status exports no requested or foreign metadata '.$label,!str_contains($wire,$private));
        }
    }
    $owned=$selfService->handle($statusRpc('PRIVATE_FOREIGN_QUEUE'),1,$otherConnection,[$devScope]);
    devOauthCheck('actual owner still reads unchanged successful status without caller self',
        ($owned['result']['structuredContent']['ok']??null)===true&&($owned['result']['structuredContent']['task_id']??null)==='PRIVATE_FOREIGN_TASK'
        &&($owned['result']['isError']??null)===false&&!str_contains(json_encode($owned,JSON_THROW_ON_ERROR),'caller_self'));
    devOauthCheck('owned boundary reads do not mutate queues grants or scope profiles',$beforeQueues===$queueSnapshot()
        &&$beforeChanges===(int)$pdo->query('SELECT total_changes()')->fetchColumn()
        &&$beforeScopes===$pdo->query('SELECT grant_id,scopes FROM manager_preparation_oauth_grants ORDER BY grant_id')->fetchAll(PDO::FETCH_ASSOC));
    devOauthCheck('canonical metadata requires a fresh grant and its required scope',
        $oauth->currentConnectionMetadata($selfConnection,1,[$devScope])!==null
        &&$oauth->currentConnectionMetadata($selfConnection,1,[$hotelScope])===null);
    foreach ([['queue_id'=>false],['queue_id'=>'PRIVATE_MISSING_QUEUE','caller_self'=>[]]] as $badStatusArgs) {
        $badStatus=$selfService->handle(devOauthRpc('tools/call',['name'=>'development_work_status','arguments'=>$badStatusArgs]),1,$selfConnection,[$devScope]);
        devOauthCheck('malformed status stays invalid params without self',($badStatus['error']['code']??null)===-32602
            &&!str_contains(json_encode($badStatus,JSON_THROW_ON_ERROR),'caller_self'));
    }
    foreach ([[1,null],[1,str_repeat('f',32)],[2,$selfConnection]] as [$badAdmin,$badConnection]) {
        devOauthCheck('invalid canonical grant admin or null connection has typed context error without self',
            $authDenied($selfService->handle($statusRpc('PRIVATE_MISSING_QUEUE'),$badAdmin,$badConnection,[$devScope])));
    }
    $scopeDenied=$selfService->handle($statusRpc('PRIVATE_MISSING_QUEUE'),1,$connections['archive'],[$archiveScope]);
    devOauthCheck('valid noneligible missing scope is typed without caller self',$authDenied($scopeDenied,'AUTH_SCOPE_UNAVAILABLE'));
    $eligible=$selfService->handle($statusRpc('PRIVATE_MISSING_QUEUE'),1,$connections['legacy'],$legacyScopes);
    devOauthCheck('eligible missing scope keeps the original step up challenge',
        devOauthChallengeScopes($eligible)===array_merge($legacyScopes,[$devScope])&&!str_contains(json_encode($eligible,JSON_THROW_ON_ERROR),'caller_self'));

    foreach (['revoked','expired'] as $invalidState) {
        $invalidTokens=$oauth->exchange(devOauthCode($oauth,$client,$devScope));
        $invalidContext=$oauth->contextForBearer('Bearer '.$invalidTokens['access_token']);$invalidConnection=$invalidContext['connection_id'];
        $column=$invalidState==='revoked'?'revoked_at':'expires_at';
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET '.$column.'=? WHERE grant_id=?')->execute([$now-1,$invalidConnection]);
        devOauthCheck('invalid grant metadata and status are denied without self '.$invalidState,
            $oauth->currentConnectionMetadata($invalidConnection,1,[$devScope])===null
            &&$authDenied($selfService->handle($statusRpc('PRIVATE_FOREIGN_QUEUE'),1,$invalidConnection,[$devScope])));
    }
    foreach (['revoked','admin_inactive','project_inactive'] as $change) {
        $raceTokens=$oauth->exchange(devOauthCode($oauth,$client,$devScope));
        $raceContext=$oauth->contextForBearer('Bearer '.$raceTokens['access_token']);$raceConnection=$raceContext['connection_id'];$realDenied=false;
        $raceStore=static function(string $operation,array $arguments)use($statusQueue,$pdo,$now,$raceConnection,$change,&$realDenied):array {
            try {return $statusQueue->$operation(...$arguments);} catch (DomainException $original) {
                $realDenied=$operation==='status'&&$original->getMessage()==='development_work_access_denied';
                if ($change==='revoked') $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$raceConnection]);
                elseif ($change==='admin_inactive') $pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');
                else $pdo->exec("UPDATE projects SET is_active=0 WHERE project_key='anytour'");
                throw $original;
            }
        };
        $raceService=new ManagerPreparationMcpService($pdo,null,null,$clock,null,null,null,null,null,null,$raceStore);
        try {
            $raceReply=$raceService->handle($statusRpc('PRIVATE_FOREIGN_QUEUE'),1,$raceConnection,[$devScope]);
            devOauthCheck('fresh authority wins over real owned boundary exception '.$change,$realDenied&&$authDenied($raceReply)&&$beforeQueues===$queueSnapshot());
        } finally {$pdo->exec('UPDATE managers SET is_active=1 WHERE id=1');$pdo->exec("UPDATE projects SET is_active=1 WHERE project_key='anytour'");}
    }
    $otherReadStore=static function(string $operation,array $arguments)use($statusQueue,$pdo,$now,$otherConnection):array {
        $result=$statusQueue->$operation(...$arguments);
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$otherConnection]);return $result;
    };
    $otherReadService=new ManagerPreparationMcpService($pdo,null,null,$clock,null,null,null,null,null,null,$otherReadStore);
    devOauthCheck('successful real owned read is rechecked after revocation without leaking metadata',
        $authDenied($otherReadService->handle($statusRpc('PRIVATE_FOREIGN_QUEUE'),1,$otherConnection,[$devScope])));
    $busyStore=static function(string $operation,array $arguments)use($pdo):array {$pdo->beginTransaction();throw new DomainException('development_work_access_denied');};
    $busyService=new ManagerPreparationMcpService($pdo,null,null,$clock,null,null,null,null,null,null,$busyStore);
    try {
        $busyReply=$busyService->handle($statusRpc('PRIVATE_FOREIGN_QUEUE'),1,$selfConnection,[$devScope]);
        devOauthCheck('foreign active transaction cannot expose canonical self',$pdo->inTransaction()
            &&$oauth->currentConnectionMetadata($selfConnection,1,[$devScope])===null&&$authDenied($busyReply));
    } finally {if ($pdo->inTransaction()) $pdo->rollBack();}
    $unrelatedStore=static function(string $operation,array $arguments):array {throw new DomainException('PRIVATE_UNRELATED_DOMAIN');};
    $unrelatedService=new ManagerPreparationMcpService($pdo,null,null,$clock,null,null,null,null,null,null,$unrelatedStore);
    $unrelated=$unrelatedService->handle($statusRpc('PRIVATE_FOREIGN_QUEUE'),1,$selfConnection,[$devScope]);
    devOauthCheck('unmatched domain stays a hard denial without self or exception text',$authDenied($unrelated,null)
        &&!str_contains(json_encode($unrelated,JSON_THROW_ON_ERROR),'PRIVATE_UNRELATED_DOMAIN'));

    $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$connections['dev']]);
    $denied=$call('tools/call',['name'=>'development_work_status','arguments'=>['queue_id'=>'SYNTHETIC_DEV']],'dev');
    $unsubscribed=$call('events/unsubscribe',$subscription,'dev');
    devOauthCheck('revocation stops calls and unsubscribe before queue and event stores',
        ($denied['error']['code']??null)===-32001&&($unsubscribed['error']['code']??null)===-32001
        &&$developmentCalls===2&&$events->subscriptions===1);
    $guardTokens=$oauth->exchange(devOauthCode($oauth,$client,$devScope));$guardConnection=$oauth->connectionForBearer('Bearer '.$guardTokens['access_token']);
    $guardStore=static function(string $operation,array $args) use($pdo,$now,$guardConnection):array {
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$guardConnection]);
        return ['ok'=>true,'private_result_marker'=>'must-not-escape-after-revocation'];
    };
    $guardService=new ManagerPreparationMcpService($pdo,$legacyStore,null,$clock,null,null,null,$hotelStore,$hotelEvents,null,$guardStore,$events);
    $revokedDuringRead=$guardService->handle(devOauthRpc('tools/call',['name'=>'development_work_status','arguments'=>['queue_id'=>'SYNTHETIC_DEV']]),
        1,$guardConnection,[$devScope]);
    devOauthCheck('canonical authority is rechecked after development result is read',
        ($revokedDuringRead['error']['code']??null)===-32001
        &&!str_contains(json_encode($revokedDuringRead,JSON_THROW_ON_ERROR),'must-not-escape-after-revocation'));
    echo 'DEVELOPMENT WORK OAUTH: '.$passed." PASS\n";
} finally {
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,null);
    (new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->setValue(null,false);
}
