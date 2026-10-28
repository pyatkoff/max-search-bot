<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerPreparationOAuth.php';
require_once dirname(__DIR__).'/services/ManagerPreparationMcpService.php';
require_once dirname(__DIR__).'/services/DevelopmentWorkMcpAdapter.php';
require_once dirname(__DIR__).'/services/DevelopmentWorkEventService.php';
require_once dirname(__DIR__).'/services/DevelopmentWorkMcpService.php';

$passed=0;
function devOauthCheck(string $name,bool $ok): void
{
    global $passed;if (!$ok) throw new RuntimeException('FAIL '.$name);++$passed;
}
function devOauthRpc(string $method,array $params=[]): array { return ['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params]; }
function devOauthCode(ManagerPreparationOAuth $oauth,string $client,string $scope,string $resource=ManagerPreparationOAuth::RESOURCE): array
{
    $verifier=str_repeat('v',43);$session=['csrf'=>'synthetic-development-csrf'];
    $request=['client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'response_type'=>'code',
        'resource'=>$resource,'scope'=>$scope,'state'=>'synthetic-development-state',
        'code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')];
    $pending=$oauth->beginAuthorization($session,$request);
    $redirect=$oauth->completeAuthorization($session,$pending,1,$session['csrf'],true);
    parse_str((string)parse_url($redirect,PHP_URL_QUERY),$callback);
    return ['grant_type'=>'authorization_code','client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,
        'resource'=>$resource,'code'=>$callback['code'],'code_verifier'=>$verifier];
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

final class DevelopmentOauthSubscriptionStatement extends PDOStatement
{
    protected function __construct(private Closure $afterExecute) {}
    public function execute(?array $params=null): bool
    {
        $ok=parent::execute($params);($this->afterExecute)($this->queryString);return $ok;
    }
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

    // Exercise subscription diagnostics through the actual MCP owner, queue and live OAuth grant.
    DevelopmentWorkTestSchema::create($pdo);
    $setupTokens=$oauth->exchange(devOauthCode($oauth,$client,$devScope));
    $setupContext=$oauth->contextForBearer('Bearer '.$setupTokens['access_token']);$setupConnection=$setupContext['connection_id'];
    $setupQueue=new DevelopmentWorkQueue($pdo,$clock);
    $setupQueue->create(1,$setupConnection,['queue_id'=>'SETUP_OWNED_QUEUE','plan_sha256'=>str_repeat('d',64),'tasks'=>[
        ['task_id'=>'SETUP_PRIVATE_TASK','owner_task_id'=>'SETUP_PRIVATE_OWNER','reviewer_task_id'=>'SETUP_PRIVATE_REVIEWER',
            'input_sha256'=>str_repeat('e',64),'acceptance_contract_sha256'=>str_repeat('f',64),'deps'=>[],'priority'=>50]]]);
    $setupService=new ManagerPreparationMcpService($pdo,null,null,$clock,null,null,null,null,null,null,$setupQueue);
    $setupRpc=static fn(string $queue='SETUP_OWNED_QUEUE'):array=>devOauthRpc('tools/call',
        ['name'=>'development_work_status','arguments'=>['queue_id'=>$queue]]);
    $setupDate=gmdate('Y-m-d H:i:s',$now-10);$setupExpires=gmdate('Y-m-d H:i:s',$now+600);
    $setupPrivate='SETUP_PRIVATE_CALLBACK_HMAC_AND_CONSUMER_MARKER';
    $insertSetupRow=static function(int $admin,string $connection,string $queue,string $event,string $tag,string $project='anytour')
        use($pdo,$setupDate,$setupExpires,$setupPrivate):void {
        $url='https://private-subscription.invalid/'.$setupPrivate.'/'.$tag;
        $arguments=json_encode(['project_key'=>$project,'queue_id'=>$queue],JSON_THROW_ON_ERROR);
        $id='dev_sub_'.hash('sha256',json_encode([$admin,$connection,$event,$arguments,$url],JSON_THROW_ON_ERROR));
        $consumer=hash('sha256',json_encode([$admin,$connection,$queue,$event],JSON_THROW_ON_ERROR));
        $pdo->prepare('INSERT INTO development_work_events (subscription_id,single_consumer_key,admin_id,oauth_connection_id,project_key,queue_id,event_name,arguments_json,callback_url,signing_secret,previous_secret,previous_secret_until,verified_at,expires_at,is_active,attempts,last_status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$consumer,$admin,$connection,$project,$queue,$event,$arguments,$url,$setupPrivate,'OLD_'.$setupPrivate,
                $setupExpires,$setupDate,$setupExpires,1,0,204,$setupDate,$setupDate]);
    };
    $insertSetupRow(1,$setupConnection,'SETUP_OWNED_QUEUE','development_work.pending','own-pending');
    $setupHook=(object)['reads'=>0,'revoke'=>false,'revoked'=>false,'ownerFlip'=>false,'ownerFlipped'=>false,'failRead'=>false,'readFailed'=>false];
    $priorStatementClass=$pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    $afterSetupRead=static function(string $sql)use($pdo,$now,$setupConnection,$selfConnection,$setupHook):void {
        if (preg_match('/\A\s*SELECT\b[\s\S]*\bFROM\s+[`"]?development_work_events\b/i',$sql)!==1) return;
        ++$setupHook->reads;
        if ($setupHook->revoke) {
            $setupHook->revoke=false;$setupHook->revoked=true;
            $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$setupConnection]);
        } elseif ($setupHook->ownerFlip) {
            $setupHook->ownerFlip=false;$setupHook->ownerFlipped=true;
            $pdo->prepare('UPDATE development_work_queues SET oauth_connection_id=? WHERE queue_id=?')->execute([$selfConnection,'SETUP_OWNED_QUEUE']);
        }
        if ($setupHook->failRead) {
            $setupHook->failRead=false;$setupHook->readFailed=true;
            throw new PDOException('SETUP_PRIVATE_METADATA_READ_FAILURE');
        }
    };
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[DevelopmentOauthSubscriptionStatement::class,[$afterSetupRead]]);
    try {
        $setupBefore=$queueSnapshot();$setupChanges=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
        $setupReply=$setupService->handle($setupRpc(),1,$setupConnection,[$devScope]);
        $setupData=$setupReply['result']['structuredContent']??[];$setupDto=$setupData['subscription_setup']??[];
        $setupRows=array_column($setupDto['events']??[],null,'event_name');
        devOauthCheck('real owned status exposes bounded subscription metadata and its explicit proof limit',
            ($setupDto['available']??null)===true&&is_string($setupDto['observed_at']??null)
            &&($setupDto['semantics']??null)==='server_subscription_metadata_not_native_commit_proof'
            &&array_keys($setupRows)===['development_work.pending','development_work.attention']&&$setupHook->reads>0);
        $pending=$setupRows['development_work.pending']??[];$attention=$setupRows['development_work.attention']??[];
        devOauthCheck('actual current owned verified pending row is eligible without pretending to prove native commit',
            ($pending['state']??null)==='active_verified'&&($pending['matching_rows']??null)===1
            &&($pending['eligible']??null)===true&&($pending['verified']??null)===true
            &&($pending['retrying']??null)===false&&($pending['last_http_status']??null)===204&&is_string($pending['expires_at']??null));
        devOauthCheck('actual absent attention is explicit and preserves nullable unknown technical fields',
            ($attention['state']??null)==='absent'&&($attention['matching_rows']??null)===0&&($attention['eligible']??null)===false
            &&array_key_exists('verified',$attention)&&$attention['verified']===null
            &&array_key_exists('retrying',$attention)&&$attention['retrying']===null
            &&array_key_exists('last_http_status',$attention)&&$attention['last_http_status']===null
            &&array_key_exists('expires_at',$attention)&&$attention['expires_at']===null);
        $setupBase=$setupData;unset($setupBase['subscription_setup'],$setupBase['cron_health']);
        devOauthCheck('subscription diagnostics preserve every existing successful owned queue field',
            $canonicalStatus($setupBase)===$canonicalStatus($setupQueue->status(1,$setupConnection,'SETUP_OWNED_QUEUE')));
        $fields=['event_name','state','matching_rows','eligible','verified','retrying','last_http_status','expires_at'];sort($fields);
        foreach ($setupRows as $row) {$keys=array_keys($row);sort($keys);devOauthCheck('subscription DTO uses only the technical event allowlist',$keys===$fields);}
        devOauthCheck('successful subscription diagnostics perform no queue subscription grant or token writes',
            $setupBefore===$queueSnapshot()&&$setupChanges===(int)$pdo->query('SELECT total_changes()')->fetchColumn());
        $insertSetupRow(2,$setupConnection,'SETUP_OWNED_QUEUE','development_work.pending','foreign-admin');
        $insertSetupRow(1,$selfConnection,'SETUP_OWNED_QUEUE','development_work.pending','foreign-connection');
        $insertSetupRow(1,$setupConnection,'SETUP_OTHER_QUEUE','development_work.pending','foreign-queue');
        $insertSetupRow(1,$setupConnection,'SETUP_OWNED_QUEUE','development_work.attention','foreign-project','foreign-project');
        $insertSetupRow(1,$setupConnection,'SETUP_OWNED_QUEUE','hotel_work.pending','foreign-event');
        $setupForeignBefore=$queueSnapshot();$setupForeignChanges=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
        $foreignRowsReply=$setupService->handle($setupRpc(),1,$setupConnection,[$devScope]);
        devOauthCheck('foreign owner connection queue project and event rows do not affect owned subscription metadata',
            ($foreignRowsReply['result']['structuredContent']['subscription_setup']??null)===$setupDto);
        $setupWire=json_encode($foreignRowsReply,JSON_THROW_ON_ERROR);
        foreach ([$setupPrivate,'private-subscription.invalid','dev_sub_','single_consumer_key','signing_secret','previous_secret',
            $selfConnection,'SETUP_OTHER_QUEUE','foreign-project','foreign-admin','foreign-connection'] as $secret) {
            devOauthCheck('subscription status wire excludes callback secret consumer and foreign metadata',!str_contains($setupWire,$secret));
        }
        devOauthCheck('foreign row filtering remains read only',
            $setupForeignBefore===$queueSnapshot()&&$setupForeignChanges===(int)$pdo->query('SELECT total_changes()')->fetchColumn());
        $setupReadCount=$setupHook->reads;
        $sameWireMissing=$setupService->handle($setupRpc('SETUP_MISSING_QUEUE'),1,$selfConnection,[$devScope]);
        $sameWireForeign=$setupService->handle($setupRpc(),1,$selfConnection,[$devScope]);
        devOauthCheck('missing and foreign queues still have identical private unavailable wire without subscription DTO',
            $sameWireMissing===$sameWireForeign&&!str_contains(json_encode($sameWireForeign,JSON_THROW_ON_ERROR),'subscription_setup')
            &&$setupHook->reads===$setupReadCount);
        foreach (['legacy','hotel','archive'] as $profile) {
            $noSetup=$setupService->handle($setupRpc(),1,$connections[$profile],$profiles[$profile]);
            devOauthCheck('unconsented profile cannot query or receive subscription diagnostics '.$profile,
                !isset($noSetup['result']['structuredContent']['subscription_setup'])
                &&!str_contains(json_encode($noSetup,JSON_THROW_ON_ERROR),$setupPrivate)&&$setupHook->reads===$setupReadCount);
        }
        foreach ([[2,$setupConnection],[1,null]] as [$admin,$connection]) {
            $noSetup=$setupService->handle($setupRpc(),$admin,$connection,[$devScope]);
            devOauthCheck('wrong admin or missing connection cannot read subscription diagnostics',
                ($noSetup['error']['code']??null)===-32001&&!isset($noSetup['result'])&&$setupHook->reads===$setupReadCount);
        }
        $customSetupStore=static fn(string $operation,array $arguments):array=>['ok'=>true,'synthetic_custom_queue_field'=>'preserved'];
        $customSetupService=new ManagerPreparationMcpService($pdo,null,null,$clock,null,null,null,null,null,null,$customSetupStore);
        $customSetup=$customSetupService->handle($setupRpc(),1,$setupConnection,[$devScope])['result']['structuredContent']??[];
        devOauthCheck('callable queue store cannot acquire authoritative subscription metadata implicitly',
            ($customSetup['synthetic_custom_queue_field']??null)==='preserved'&&($customSetup['subscription_setup']['available']??null)===false
            &&$setupHook->reads===$setupReadCount);
        $customEvents=$customSetup['subscription_setup']['events']??[];
        devOauthCheck('callable subscription availability is explicitly unknown',
            count($customEvents)===2&&array_column($customEvents,'state')===['unknown','unknown']);
        foreach ([['revoked',false],['owner_loss',false],['revoked',true],['owner_loss',true]] as [$setupChange,$setupReadFailure]) {
            $setupRaceBefore=$queueSnapshot();$setupBeforeRaceReads=$setupHook->reads;
            $setupGrantQuery=$pdo->prepare('SELECT revoked_at FROM manager_preparation_oauth_grants WHERE grant_id=?');
            $setupGrantQuery->execute([$setupConnection]);$setupGrantBefore=$setupGrantQuery->fetch(PDO::FETCH_ASSOC);
            $setupHook->revoked=false;$setupHook->ownerFlipped=false;$setupHook->readFailed=false;
            $setupHook->revoke=$setupChange==='revoked';$setupHook->ownerFlip=$setupChange==='owner_loss';$setupHook->failRead=$setupReadFailure;
            try {
                $setupRaceReply=$setupService->handle($setupRpc(),1,$setupConnection,[$devScope]);
                $setupRaceWire=json_encode($setupRaceReply,JSON_THROW_ON_ERROR);
                devOauthCheck('actual diagnostic SELECT performs the requested one-shot authority change '.$setupChange.' '.(int)$setupReadFailure,
                    $setupHook->reads>$setupBeforeRaceReads&&($setupChange==='revoked'?$setupHook->revoked:$setupHook->ownerFlipped)
                    &&$setupHook->readFailed===$setupReadFailure);
                if ($setupChange==='revoked') {
                    devOauthCheck('post diagnostic revocation retains typed AUTH_CONTEXT even when metadata read fails '.(int)$setupReadFailure,
                        $authDenied($setupRaceReply)&&$oauth->currentConnectionMetadata($setupConnection,1,[$devScope])===null);
                } else {
                    $setupStillAuthorized=$oauth->currentConnectionMetadata($setupConnection,1,[$devScope]);
                    $setupUnavailable=['ok'=>false,'reason'=>'OWNED_QUEUE_UNAVAILABLE','queue_available'=>false,'caller_self'=>$setupStillAuthorized];
                    devOauthCheck('post diagnostic owner loss uses the private owned boundary while grant stays active '.(int)$setupReadFailure,
                        $setupStillAuthorized!==null&&($setupRaceReply['result']['isError']??null)===true
                        &&$canonicalStatus($setupRaceReply['result']['structuredContent']??[])===$canonicalStatus($setupUnavailable));
                }
                devOauthCheck('post diagnostic authority loss exposes neither primary queue nor diagnostic fallback '.$setupChange.' '.(int)$setupReadFailure,
                    !str_contains($setupRaceWire,'subscription_setup')&&!str_contains($setupRaceWire,'SETUP_PRIVATE_TASK')
                    &&!str_contains($setupRaceWire,'SETUP_PRIVATE_OWNER')&&!str_contains($setupRaceWire,'SETUP_PRIVATE_REVIEWER')
                    &&!str_contains($setupRaceWire,'SETUP_PRIVATE_METADATA_READ_FAILURE')&&!str_contains($setupRaceWire,$setupPrivate));
            } finally {
                $setupHook->revoke=false;$setupHook->ownerFlip=false;$setupHook->failRead=false;
                $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')
                    ->execute([$setupGrantBefore['revoked_at'],$setupConnection]);
                $pdo->prepare('UPDATE development_work_queues SET oauth_connection_id=? WHERE queue_id=?')
                    ->execute([$setupConnection,'SETUP_OWNED_QUEUE']);
            }
            devOauthCheck('diagnostic race restores exact queue subscriptions and canonical owner grant '.$setupChange.' '.(int)$setupReadFailure,
                $setupRaceBefore===$queueSnapshot()&&$oauth->currentConnectionMetadata($setupConnection,1,[$devScope])!==null);
        }
    } finally {$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,$priorStatementClass);}

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

    // The separate resource exposes only DEV and reuses the canonical queue and transport.
    DevelopmentWorkTestSchema::create($pdo);
    $isolatedOauth=new ManagerPreparationOAuth($pdo,$clock,ManagerPreparationOAuth::PROFILE_DEVELOPMENT);
    $isolatedClient=$isolatedOauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI],'scope'=>$devScope],'synthetic-isolated-dev')['client_id'];
    $isolatedToken=$isolatedOauth->exchange(devOauthCode($isolatedOauth,$isolatedClient,$devScope,ManagerPreparationOAuth::DEVELOPMENT_RESOURCE));
    $isolatedContext=$isolatedOauth->contextForBearer('Bearer '.$isolatedToken['access_token']);
    $isolatedConnection=$isolatedContext['connection_id'];
    devOauthCheck('new resource bearer is accepted only by its fixed OAuth profile',
        $isolatedContext['scopes']===[$devScope]&&$oauth->contextForBearer('Bearer '.$isolatedToken['access_token'])===null
        &&$isolatedOauth->contextForBearer('Bearer '.$selfTokens['access_token'])===null);
    $isolatedQueue=new DevelopmentWorkQueue($pdo,$clock);$isolatedRequests=[];$isolatedVerifyHook=null;
    $isolatedTransport=static function(string $url,array $headers,string $body,array $destination)use(&$isolatedRequests,&$isolatedVerifyHook):array {
        $decoded=json_decode($body,true,16,JSON_THROW_ON_ERROR);$isolatedRequests[]=$decoded;
        if (($decoded['type']??null)==='verification') {
            if ($isolatedVerifyHook!==null) {$hook=$isolatedVerifyHook;$isolatedVerifyHook=null;$hook();}
            return ['status'=>200,'body'=>json_encode(['challenge'=>$decoded['challenge']],JSON_THROW_ON_ERROR)];
        }
        return ['status'=>204,'body'=>''];
    };
    $isolatedWebhook=new ManagerPreparationWebhook($isolatedTransport,static fn(string $host):array=>['8.8.8.8'],$clock);
    $isolatedAuthorize=static fn(string $family,int $admin,array $required):bool=>$isolatedOauth->connectionAuthorized($family,$admin,$required);
    $isolatedEvents=new DevelopmentWorkEventService($pdo,$isolatedWebhook,$clock,$isolatedAuthorize,$isolatedQueue);
    $isolatedService=new DevelopmentWorkMcpService($pdo,$isolatedQueue,$clock,$isolatedEvents);
    $isolatedCall=static fn(string $method,array $params=[]):array=>$isolatedService->handle(devOauthRpc($method,$params),1,$isolatedConnection,[$devScope]);
    $isolatedTools=['development_work_create','development_work_append','development_work_status','development_work_claim',
        'development_work_started','development_work_complete','development_work_resolve_existing_run','development_work_retire_unclaimed'];
    $isolatedChanges=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
    devOauthCheck('isolated discovery exports exactly frozen eight tools and two development events',
        array_column($isolatedCall('tools/list')['result']['tools'],'name')===$isolatedTools
        &&array_column($isolatedCall('events/list')['result']['events'],'name')===['development_work.pending','development_work.attention']
        &&($isolatedCall('server/discover')['result']['serverInfo']['name']??null)==='anytour-development-work'
        &&$isolatedChanges===(int)$pdo->query('SELECT total_changes()')->fetchColumn()&&$isolatedRequests===[]);
    foreach (['list_pending_replies','hotel_work_status','archive_research_inventory','future_development_tool'] as $foreignTool) {
        $denied=$isolatedCall('tools/call',['name'=>$foreignTool,'arguments'=>[]]);
        devOauthCheck('isolated surface rejects foreign tool before dispatch '.$foreignTool,($denied['error']['code']??null)===-32601);
    }
    foreach (['manager_replies.pending','hotel_work.pending','future.event'] as $foreignEvent) {
        $denied=$isolatedCall('events/subscribe',['name'=>$foreignEvent]);
        devOauthCheck('isolated surface rejects foreign event before transport '.$foreignEvent,($denied['error']['code']??null)===-32601);
    }
    devOauthCheck('foreign names create no queue callback or subscription writes',
        $isolatedChanges===(int)$pdo->query('SELECT total_changes()')->fetchColumn()&&$isolatedRequests===[]);
    $legacyIsolated=(new ManagerPreparationMcpService($pdo,null,null,$clock))->handle(devOauthRpc('tools/list'),1,$isolatedConnection,[$devScope]);
    $newLegacy=$isolatedService->handle(devOauthRpc('tools/list'),1,$selfConnection,[$devScope]);
    devOauthCheck('services enforce resource identity even with forged granted scope arguments',
        ($legacyIsolated['error']['code']??null)===-32001&&($newLegacy['error']['code']??null)===-32001);
    $isolatedTask=['task_id'=>'ISOLATED_A','owner_task_id'=>'ISOLATED_OWNER','reviewer_task_id'=>'ISOLATED_REVIEWER',
        'input_sha256'=>str_repeat('1',64),'acceptance_contract_sha256'=>str_repeat('2',64),'deps'=>[],'priority'=>50];
    $isolatedCreated=$isolatedCall('tools/call',['name'=>'development_work_create','arguments'=>[
        'queue_id'=>'ISOLATED_QUEUE','plan_sha256'=>str_repeat('3',64),'tasks'=>[$isolatedTask]]]);
    devOauthCheck('new resource creates only its canonical metadata queue',
        ($isolatedCreated['result']['structuredContent']['ok']??null)===true&&$isolatedRequests===[]);
    $isolatedStatusArgs=['name'=>'development_work_status','arguments'=>['queue_id'=>'ISOLATED_QUEUE']];
    $isolatedStatus=$isolatedCall('tools/call',$isolatedStatusArgs)['result']['structuredContent']??[];
    devOauthCheck('new resource real adapter propagates authority into nested subscription setup',
        ($isolatedStatus['ok']??null)===true&&($isolatedStatus['task_id']??null)==='ISOLATED_A'
        &&($isolatedStatus['subscription_setup']['available']??null)===true
        &&array_column($isolatedStatus['subscription_setup']['events'],'state')===['absent','absent']);
    $isolatedBefore=$queueSnapshot();
    $isolatedForeign=(new ManagerPreparationMcpService($pdo,null,null,$clock))->handle(devOauthRpc('tools/call',$isolatedStatusArgs),1,$selfConnection,[$devScope]);
    devOauthCheck('old resource cannot read or adopt new queue despite same current administrator',
        ($isolatedForeign['result']['structuredContent']['reason']??null)==='OWNED_QUEUE_UNAVAILABLE'&&$isolatedBefore===$queueSnapshot());
    $pdo->prepare('UPDATE development_work_queues SET oauth_connection_id=? WHERE queue_id=?')->execute([$selfConnection,'ISOLATED_QUEUE']);
    try {
        $denied=$isolatedCall('tools/call',$isolatedStatusArgs);
        $missing=$isolatedCall('tools/call',['name'=>'development_work_status','arguments'=>['queue_id'=>'MISSING_ISOLATED']]);
        devOauthCheck('new resource cannot read or adopt old-owned metadata and missing has identical private wire',$denied===$missing
            &&($denied['result']['structuredContent']['reason']??null)==='OWNED_QUEUE_UNAVAILABLE'
            &&($denied['result']['structuredContent']['caller_self']['capabilities']??null)===[
                'reply_preparation'=>false,'hotel_work'=>false,'archive_research'=>false,'development_work'=>true]);
    } finally {$pdo->prepare('UPDATE development_work_queues SET oauth_connection_id=? WHERE queue_id=?')->execute([$isolatedConnection,'ISOLATED_QUEUE']);}
    $isolatedSubscription=['name'=>'development_work.pending','arguments'=>['project_key'=>'anytour','queue_id'=>'ISOLATED_QUEUE'],
        'delivery'=>['mode'=>'webhook','url'=>'https://callbacks.example/isolated-development','secret'=>str_repeat('ab',32)],'ttlMs'=>600000];
    $isolatedSubscribed=$isolatedCall('events/subscribe',$isolatedSubscription);
    devOauthCheck('new resource real event subscription verifies callback and persists canonical ownership',
        str_starts_with($isolatedSubscribed['result']['id']??'','dev_sub_')&&count($isolatedRequests)===1);
    $isolatedSetup=$isolatedCall('tools/call',$isolatedStatusArgs)['result']['structuredContent']['subscription_setup']??[];
    devOauthCheck('injected nested reader sees new resource verified subscription without another callback',
        ($isolatedSetup['events'][0]['state']??null)==='active_verified'&&($isolatedSetup['events'][0]['eligible']??null)===true
        &&count($isolatedRequests)===1);
    $scheduledAuthorize=static fn(string $family,int $admin,array $required):bool=>$oauth->developmentConnectionAuthorizedAcrossProfiles($family,$admin);
    $scheduledEvents=new DevelopmentWorkEventService($pdo,$isolatedWebhook,$clock,$scheduledAuthorize,$isolatedQueue);
    devOauthCheck('scheduled authorization accepts both exact existing resource families',
        $oauth->developmentConnectionAuthorizedAcrossProfiles($isolatedConnection,1)
        &&$oauth->developmentConnectionAuthorizedAcrossProfiles($selfConnection,1));
    $isolatedRequests=[];$delivered=$scheduledEvents->deliverPending();
    devOauthCheck('ordinary shared delivery uses new resource grant without new worker or schema',
        $delivered['accepted']===1&&$delivered['stopped']===0&&count($isolatedRequests)===1
        &&($isolatedRequests[0]['name']??null)==='development_work.pending'
        &&($isolatedRequests[0]['data']['task_id']??null)==='ISOLATED_A');
    $isolatedGrantQuery=$pdo->prepare('SELECT * FROM manager_preparation_oauth_grants WHERE grant_id=?');$isolatedGrantQuery->execute([$isolatedConnection]);$isolatedGrant=$isolatedGrantQuery->fetch(PDO::FETCH_ASSOC);
    foreach (['unknown_resource','missing_grant','sql_failure'] as $technical) {
        $isolatedRequests=[];$oldStatementClass=$pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        if ($technical==='unknown_resource') $pdo->prepare('UPDATE manager_preparation_oauth_grants SET resource=? WHERE grant_id=?')->execute(['https://unknown.invalid/resource',$isolatedConnection]);
        elseif ($technical==='missing_grant') $pdo->prepare('DELETE FROM manager_preparation_oauth_grants WHERE grant_id=?')->execute([$isolatedConnection]);
        else $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[DevelopmentOauthSubscriptionStatement::class,[static function(string $sql):void {
            if (preg_match('/\\A\\s*SELECT\\b[\\s\\S]*\\bFROM\\s+manager_preparation_oauth_grants\\b/i',$sql)===1) throw new PDOException('PRIVATE_ISOLATED_OAUTH_LOOKUP_FAILURE');
        }]]);
        try {
            $deferred=false;try {$oauth->developmentConnectionAuthorizedAcrossProfiles($isolatedConnection,1);} catch (RuntimeException $expected) {$deferred=true;}
            $delivery=$scheduledEvents->deliverPending();
            $active=(int)$pdo->query('SELECT is_active FROM development_work_events')->fetchColumn();
            devOauthCheck('uncertain grant storage defers without false revocation or callback '.$technical,
                $deferred&&$delivery['stopped']===0&&$delivery['retried']===1&&$active===1&&$isolatedRequests===[]);
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldStatementClass);
            if ($technical==='missing_grant') $pdo->prepare('INSERT INTO manager_preparation_oauth_grants ('.implode(',',array_keys($isolatedGrant)).') VALUES ('.implode(',',array_fill(0,count($isolatedGrant),'?')).')')->execute(array_values($isolatedGrant));
            else $pdo->prepare('UPDATE manager_preparation_oauth_grants SET resource=? WHERE grant_id=?')->execute([$isolatedGrant['resource'],$isolatedConnection]);
        }
    }
    $isolatedVerifyHook=static function()use($pdo,$now,$isolatedConnection):void {$pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$isolatedConnection]);};
    $attentionSubscription=array_replace($isolatedSubscription,['name'=>'development_work.attention']);
    try {
        $raced=$isolatedCall('events/subscribe',$attentionSubscription);
        devOauthCheck('new resource rechecks canonical authority after actual callback verification',
            ($raced['error']['code']??null)===-32001
            &&(int)$pdo->query("SELECT COUNT(*) FROM development_work_events WHERE event_name='development_work.attention'")->fetchColumn()===0);
    } finally {$pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=NULL WHERE grant_id=?')->execute([$isolatedConnection]);}
    $afterIsolatedRead=static function(string $sql)use($pdo,$now,$isolatedConnection):void {
        if (preg_match('/\\A\\s*SELECT\\b[\\s\\S]*\\bFROM\\s+development_work_events\\b/i',$sql)===1)
            $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$isolatedConnection]);
    };
    $oldStatementClass=$pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[DevelopmentOauthSubscriptionStatement::class,[$afterIsolatedRead]]);
    try {
        $raced=$isolatedCall('tools/call',$isolatedStatusArgs);
        devOauthCheck('new resource nested read withholds complete queue and DTO after live revocation',
            $authDenied($raced)&&!str_contains(json_encode($raced,JSON_THROW_ON_ERROR),'ISOLATED_OWNER'));
    } finally {
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldStatementClass);
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=NULL WHERE grant_id=?')->execute([$isolatedConnection]);
    }

    echo 'DEVELOPMENT WORK OAUTH: '.$passed." PASS\n";
} finally {
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,null);
    (new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->setValue(null,false);
}
