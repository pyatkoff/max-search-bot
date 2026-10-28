<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerPreparationMcpService.php';
require_once dirname(__DIR__).'/services/ArchiveResearchReadService.php';

$passed=0;
function archiveMcpCheck(string $name,bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    ++$passed; echo 'PASS '.$name."\n";
}
function archiveMcpRpc(string $name,array $args=[]): array
{
    return ['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]];
}
function archiveMcpCode(ManagerPreparationOAuth $oauth,string $client,string $scope): array
{
    $verifier=str_repeat('v',43); $session=['csrf'=>'synthetic-archive-csrf'];
    $request=['client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'response_type'=>'code',
        'resource'=>ManagerPreparationOAuth::RESOURCE,'scope'=>$scope,'state'=>'synthetic-archive-state',
        'code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')];
    $pending=$oauth->beginAuthorization($session,$request);
    $redirect=$oauth->completeAuthorization($session,$pending,1,$session['csrf'],true);
    parse_str((string)parse_url($redirect,PHP_URL_QUERY),$callback);
    return ['grant_type'=>'authorization_code','client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,
        'resource'=>ManagerPreparationOAuth::RESOURCE,'code'=>$callback['code'],'code_verifier'=>$verifier];
}
function archiveMcpScopeChallenge(array $response): string
{
    return (string)($response['result']['_meta']['mcp/www_authenticate'][0]??'');
}

try {
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    (new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->setValue(null,false);
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic']]);
    $pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
        CREATE TABLE managers(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER,is_working INTEGER);
        CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
        CREATE TABLE manager_preparation_oauth_clients(client_id TEXT PRIMARY KEY,redirect_uri TEXT,registration_bucket TEXT,created_at INTEGER);
        CREATE TABLE manager_preparation_oauth_grants(grant_id TEXT PRIMARY KEY,client_id TEXT,admin_id INTEGER,project_key TEXT,resource TEXT,scopes TEXT,created_at INTEGER,expires_at INTEGER,revoked_at INTEGER);
        CREATE TABLE manager_preparation_oauth_codes(code_hash TEXT PRIMARY KEY,grant_id TEXT,redirect_uri TEXT,code_challenge TEXT,expires_at INTEGER,consumed_at INTEGER);
        CREATE TABLE manager_preparation_oauth_tokens(token_hash TEXT PRIMARY KEY,grant_id TEXT,token_kind TEXT,expires_at INTEGER,consumed_at INTEGER);
        INSERT INTO managers VALUES(1,'admin',1,0),(2,'manager',1,1),(3,'admin',1,0),(4,'admin',0,0);
        INSERT INTO projects VALUES(1,'anytour','Synthetic',1),(2,'other','Other',1);
        INSERT INTO manager_projects VALUES(1,1),(2,1),(3,2),(4,1);
        CREATE TABLE messages(id INTEGER PRIMARY KEY,text TEXT);
        INSERT INTO messages VALUES(1,'Synthetic unrelated source');
        CREATE TABLE manager_preparation_events(subscription_id TEXT PRIMARY KEY,pending_body TEXT);
        INSERT INTO manager_preparation_events VALUES('ordinary-event','unchanged');
        CREATE TABLE manager_prepared_replies(job_id TEXT PRIMARY KEY,status TEXT);
        INSERT INTO manager_prepared_replies VALUES('ordinary-job','pending');");
    $now=1793160000; $clock=static function() use (&$now):int {return $now;};
    $oauth=new ManagerPreparationOAuth($pdo,$clock);
    $registration=$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'synthetic-archive');
    $client=$registration['client_id'];
    $legacyScope=implode(' ',ManagerPreparationOAuth::SCOPES);
    $archiveScope=ManagerPreparationOAuth::ARCHIVE_SCOPE;
    $hotelScope=ManagerPreparationOAuth::HOTEL_SCOPE;
    $profiles=[$legacyScope,$archiveScope,$hotelScope,$legacyScope.' '.$archiveScope,$hotelScope.' '.$archiveScope,
        implode(' ',ManagerPreparationOAuth::SUPPORTED_SCOPES)];
    $tokens=[]; $contexts=[];
    foreach ($profiles as $scope) {
        $tokens[$scope]=$oauth->exchange(archiveMcpCode($oauth,$client,$scope));
        $contexts[$scope]=$oauth->contextForBearer('Bearer '.$tokens[$scope]['access_token']);
        archiveMcpCheck('explicit consent preserves exact scope profile '.$scope,
            $tokens[$scope]['scope']===$scope && $contexts[$scope]['scopes']===explode(' ',$scope));
    }
    $connection=$contexts[$archiveScope]['connection_id'];
    $legacy=$contexts[$legacyScope]['connection_id'];
    $hotel=$contexts[$hotelScope]['connection_id'];
    archiveMcpCheck('default registration retains only the legacy capability',$registration['scope']===$legacyScope);
    archiveMcpCheck('public metadata declares a separate archive capability',
        ManagerPreparationOAuth::resourceMetadata()['scopes_supported']===['queue:read','prepared:write','hotel_work:write','archive:read','development_work:write','forecasts:read']);
    try {
        $oauth->exchange(['grant_type'=>'refresh_token','client_id'=>$client,'resource'=>ManagerPreparationOAuth::RESOURCE,
            'refresh_token'=>$tokens[$legacyScope]['refresh_token'],'scope'=>$legacyScope.' '.$archiveScope]);
        throw new RuntimeException('FAIL old refresh widened');
    } catch (InvalidArgumentException $error) {
        archiveMcpCheck('old refresh cannot add archive access',$error->getMessage()==='invalid_scope'
            &&$oauth->connectionAuthorized($legacy,1,ManagerPreparationOAuth::SCOPES)
            &&!$oauth->connectionAuthorized($legacy,1,[$archiveScope]));
    }
    $changes=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
    $key=$oauth->archiveCursorKey($connection,1);
    archiveMcpCheck('cursor key is a private fixed-width domain projection',strlen($key)===32
        &&hash_equals($key,$oauth->archiveCursorKey($connection,1))
        &&!hash_equals($key,hash('sha256',$tokens[$archiveScope]['access_token'],true)));
    $second=$contexts[$legacyScope.' '.$archiveScope]['connection_id'];
    archiveMcpCheck('cursor keys are isolated by consent family',!hash_equals($key,$oauth->archiveCursorKey($second,1)));
    foreach ([[$legacy,1],[$hotel,1],[$connection,2],[str_repeat('9',32),1]] as [$family,$actor]) {
        try {$oauth->archiveCursorKey($family,$actor); throw new RuntimeException('FAIL cursor key access accepted');}
        catch (DomainException $error) {archiveMcpCheck('cursor key requires its exact fresh archive grant',true);}
    }
    archiveMcpCheck('cursor derivation writes no OAuth or source state',(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$changes);
    $pdo->beginTransaction();
    try {$oauth->archiveCursorKey($connection,1);throw new RuntimeException('FAIL stale transaction accepted');}
    catch (DomainException $error) {archiveMcpCheck('cursor authorization cannot use an old source transaction snapshot',$pdo->inTransaction());}
    finally {$pdo->rollBack();}

    $calls=[]; $ordinaryCalls=0; $hook=null; $override=null;
    $ordinary=static function() use (&$ordinaryCalls):array {++$ordinaryCalls;return [];};
    $store=static function(string $operation,array $arguments) use (&$calls,&$hook,&$override):mixed {
        $calls[]=[$operation,$arguments];
        if ($hook!==null) {$run=$hook;$hook=null;$run();}
        if ($override!==null) return $override;
        return ['ok'=>true,'project_key'=>'anytour','schema_version'=>1,'operation'=>$operation,
            'messages'=>$operation==='history'?[['role'=>'tourist','text'=>'Synthetic private archive text']]:[],
            'next_cursor'=>null];
    };
    $service=new ManagerPreparationMcpService($pdo,$ordinary,null,$clock,null,null,null,null,null,$store);
    $invoke=static fn(string $name,array $args=[],int $admin=1,?string $family=null,?array $scopes=null):array =>
        $service->handle(archiveMcpRpc($name,$args),$admin,$family??$connection,$scopes??[$archiveScope]);
    $list=static fn(string $family,array $scopes):array =>
        $service->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'],1,$family,$scopes)['result']['tools'];
    $archiveTools=$list($connection,[$archiveScope]);
    archiveMcpCheck('archive-only connection discovers archive readers and separate forecast consent',
        array_column($archiveTools,'name')===array_merge(ArchiveResearchMcpAdapter::NAMES,[ModeledConversionStatusMcpAdapter::NAME]) &&$calls===[]&&$ordinaryCalls===0);
    foreach ($archiveTools as $tool) {
        if(!in_array($tool['name'],ArchiveResearchMcpAdapter::NAMES,true))continue;
        archiveMcpCheck('archive schema is bounded read-only with explicit OAuth metadata '.$tool['name'],
            $tool['inputSchema']['additionalProperties']===false&&$tool['annotations']['readOnlyHint']===true
            &&$tool['annotations']['destructiveHint']===false&&$tool['annotations']['openWorldHint']===false
            &&$tool['securitySchemes']===[['type'=>'oauth2','scopes'=>[$archiveScope]]]
            &&$tool['_meta']['securitySchemes']===$tool['securitySchemes']);
    }
    archiveMcpCheck('inventory and history enforce distinct maximum pages',
        $archiveTools[0]['inputSchema']['properties']['limit']['maximum']===50
        &&$archiveTools[1]['inputSchema']['properties']['limit']['maximum']===100
        &&$archiveTools[1]['inputSchema']['required']===['chat_ref']);
    $legacyTools=$list($legacy,ManagerPreparationOAuth::SCOPES);
    archiveMcpCheck('legacy discovery offers archive consent without widening its grant',
        array_values(array_intersect(array_column($legacyTools,'name'),ArchiveResearchMcpAdapter::NAMES))===ArchiveResearchMcpAdapter::NAMES
        &&!$oauth->connectionAuthorized($legacy,1,[$archiveScope])&&$calls===[]);
    $hotelTools=$list($hotel,[$hotelScope]);
    archiveMcpCheck('hotel-only discovery preserves hotel tools and advertises separate development consent',
        array_column($hotelTools,'name')===array_merge(HotelWorkMcpAdapter::NAMES,DevelopmentWorkMcpAdapter::NAMES,[ModeledConversionStatusMcpAdapter::NAME]));

    $legacyChallenge=$invoke('read_archive_history',['chat_ref'=>'synthetic.ref'],1,$legacy,ManagerPreparationOAuth::SCOPES);
    $challenge=archiveMcpScopeChallenge($legacyChallenge);
    archiveMcpCheck('legacy archive call challenges before accessing history',
        ($legacyChallenge['result']['isError']??false)===true&&$calls===[]
        &&str_contains($challenge,'scope="queue:read prepared:write archive:read"')
        &&!str_contains($challenge,$hotelScope)&&!str_contains(json_encode($legacyChallenge),'synthetic.ref'));
    $hotelChallenge=$invoke('archive_research_inventory',[],1,$hotel,[$hotelScope]);
    archiveMcpCheck('archive step-up retains an existing hotel grant without adding replies',
        str_contains(archiveMcpScopeChallenge($hotelChallenge),'scope="hotel_work:write archive:read"')&&$calls===[]);
    $hotelStep=$invoke('hotel_work_status',['queue_id'=>'SYNTHETIC_QUEUE'],1,$legacy,ManagerPreparationOAuth::SCOPES);
    archiveMcpCheck('hotel step-up does not incidentally request historical archives',
        str_contains(archiveMcpScopeChallenge($hotelStep),'scope="queue:read prepared:write hotel_work:write"')
        &&!str_contains(archiveMcpScopeChallenge($hotelStep),$archiveScope)&&$calls===[]);
    $bothFamily=$contexts[$legacyScope.' '.$archiveScope]['connection_id'];
    $bothStep=$invoke('hotel_work_status',['queue_id'=>'SYNTHETIC_QUEUE'],1,$bothFamily,[...ManagerPreparationOAuth::SCOPES,$archiveScope]);
    archiveMcpCheck('hotel step-up preserves already-consented archive rights',
        str_contains(archiveMcpScopeChallenge($bothStep),'scope="queue:read prepared:write hotel_work:write archive:read"')&&$calls===[]);

    $inventory=$invoke('archive_research_inventory',['limit'=>3]);
    archiveMcpCheck('inventory dispatch binds the trusted administrator and family',
        ($inventory['result']['structuredContent']['operation']??null)==='inventory'
        &&end($calls)===['inventory',[1,$connection,['limit'=>3]]]);
    $history=$invoke('read_archive_history',['chat_ref'=>'synthetic.ref','cursor'=>'synthetic.cursor','limit'=>10]);
    archiveMcpCheck('history preserves exact bounded arguments and matching JSON representations',
        ($history['result']['structuredContent']['operation']??null)==='history'
        &&end($calls)===['history',[1,$connection,['chat_ref'=>'synthetic.ref','cursor'=>'synthetic.cursor','limit'=>10]]]
        &&json_decode($history['result']['content'][0]['text'],true)===$history['result']['structuredContent']);
    $beforeCalls=count($calls);
    $badArgs=[
        ['archive_research_inventory',['limit'=>0]],['archive_research_inventory',['limit'=>51]],
        ['archive_research_inventory',['limit'=>'3']],['archive_research_inventory',['cursor'=>null]],
        ['archive_research_inventory',['project_key'=>'other']],['archive_research_inventory',['account_id'=>'123']],
        ['read_archive_history',[]],['read_archive_history',['chat_ref'=>[]]],
        ['read_archive_history',['chat_ref'=>'bad/route']],['read_archive_history',['chat_ref'=>'synthetic.ref','limit'=>101]],
        ['read_archive_history',['chat_ref'=>'synthetic.ref','before_id'=>1]],
        ['read_archive_history',['chat_ref'=>'synthetic.ref','admin_id'=>2]],
        ['read_archive_history',['chat_ref'=>'synthetic.ref','connection_id'=>$legacy]],
        ['read_archive_history',['chat_ref'=>str_repeat('a',2049)]],
    ];
    foreach ($badArgs as [$name,$arguments]) {
        $bad=$invoke($name,$arguments);
        archiveMcpCheck('invalid or caller-supplied scope argument is rejected before reader '.$name,
            ($bad['error']['code']??null)===-32602&&count($calls)===$beforeCalls);
    }
    $unknown=$invoke('archive_research_send',[]);
    archiveMcpCheck('unknown archive name cannot manufacture consent or invoke a reader',
        ($unknown['error']['code']??null)===-32001&&count($calls)===$beforeCalls&&!isset($unknown['result']));
    foreach ([0,2,3,4] as $actor) {
        $denied=$invoke('archive_research_inventory',[],$actor);
        archiveMcpCheck('reader requires a current administrator in the current active project '.$actor,
            ($denied['error']['code']??null)===-32001&&count($calls)===$beforeCalls);
    }
    $nullConnection=$service->handle(archiveMcpRpc('archive_research_inventory'),1,null,[$archiveScope]);
    archiveMcpCheck('archive has no legacy no-connection allowance',($nullConnection['error']['code']??null)===-32001&&count($calls)===$beforeCalls);
    $forged=$invoke('archive_research_inventory',[],1,$legacy,[$archiveScope]);
    archiveMcpCheck('caller-declared archive rights cannot replace the stored grant',($forged['error']['code']??null)===-32001&&count($calls)===$beforeCalls);
    foreach (['list_pending_replies','get_reply_context','save_prepared_reply','capture_virtual_replies'] as $name) {
        $denied=$invoke($name,[]);
        archiveMcpCheck('archive-only grant cannot enter an ordinary or learning path '.$name,
            ($denied['error']['code']??null)===-32001&&$ordinaryCalls===0);
    }

    $revocations=[
        ["UPDATE managers SET is_active=0 WHERE id=1","UPDATE managers SET is_active=1 WHERE id=1"],
        ["UPDATE managers SET role='manager' WHERE id=1","UPDATE managers SET role='admin' WHERE id=1"],
        ["DELETE FROM manager_projects WHERE manager_id=1 AND project_id=1","INSERT INTO manager_projects VALUES(1,1)"],
        ["UPDATE projects SET is_active=0 WHERE id=1","UPDATE projects SET is_active=1 WHERE id=1"],
    ];
    foreach ($revocations as [$withdraw,$restore]) {
        $hook=static function() use($pdo,$withdraw):void {$pdo->exec($withdraw);};
        $denied=$invoke('read_archive_history',['chat_ref'=>'synthetic.ref']);
        archiveMcpCheck('authorization withdrawn during history is withheld',
            ($denied['error']['code']??null)===-32001&&!isset($denied['result'])
            &&!str_contains(json_encode($denied),'Synthetic private'));
        $pdo->exec($restore);
    }
    $hook=static function() use($pdo,$connection,$now):void {
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$connection]);
    };
    $denied=$invoke('read_archive_history',['chat_ref'=>'synthetic.ref']);
    archiveMcpCheck('connection revoked during history is withheld',($denied['error']['code']??null)===-32001&&!isset($denied['result']));
    $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=NULL WHERE grant_id=?')->execute([$connection]);
    $hook=static function() use($pdo,$connection,$legacyScope):void {
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET scopes=? WHERE grant_id=?')->execute([$legacyScope,$connection]);
    };
    $denied=$invoke('read_archive_history',['chat_ref'=>'synthetic.ref']);
    archiveMcpCheck('archive scope removed during history is withheld',($denied['error']['code']??null)===-32001&&!isset($denied['result']));
    $pdo->prepare('UPDATE manager_preparation_oauth_grants SET scopes=? WHERE grant_id=?')->execute([$archiveScope,$connection]);
    $hook=static function():void {throw new RuntimeException('PRIVATE database detail');};
    $failed=$invoke('archive_research_inventory');
    archiveMcpCheck('source exceptions remain a fixed error without private detail',
        ($failed['error']['code']??null)===-32603&&!str_contains(json_encode($failed),'PRIVATE'));
    foreach (['stale_cursor','chat_unavailable','source_changed','time_limit','source_limit','response_limit','transaction_active','unavailable'] as $reason) {
        $hook=static function() use($reason):void {throw new ArchiveResearchReadException($reason);};
        $bounded=$invoke('read_archive_history',['chat_ref'=>'synthetic.ref']);
        $data=$bounded['result']['structuredContent']??[];
        archiveMcpCheck('actual archive source failure has only its fixed safe recovery category '.$reason,
            ($bounded['result']['isError']??null)===true&&($data['ok']??null)===false
            &&($data['reason']??null)===$reason&&($data['status']??null)==='unavailable'
            &&!isset($data['messages'])&&!str_contains(json_encode($bounded),'Synthetic private'));
        if (in_array($reason,['stale_cursor','chat_unavailable','source_changed'],true)) {
            archiveMcpCheck('expired or changed reference requires an explicit new inventory '.$reason,
                ($data['recovery']??null)==='restart_inventory');
        }
    }
    foreach ([['unavailable','summary','42',1146],['time_limit','inventory_page','HY',3024],
        ['time_limit','history_metadata','HY',1969],['unavailable','key_before','08',65535]] as [$reason,$stage,$state,$driver]) {
        $exception=new ArchiveResearchReadException($reason,$stage,$state,$driver);
        (new ReflectionProperty(Exception::class,'message'))->setValue($exception,'PRIVATE SQL SELECT customer_text WHERE token=secret');
        $hook=static function() use($exception):void {throw $exception;};
        $response=$invoke('archive_research_inventory');
        $data=$response['result']['structuredContent']??[];
        archiveMcpCheck('bounded failure exposes exact fixed stage and numeric driver categories',
            ($response['result']['isError']??null)===true&&($data['reason']??null)===$reason
            &&($data['diagnostic']??null)===['stage'=>$stage,'sqlstate_class'=>$state,'driver_code'=>$driver]
            &&json_decode($response['result']['content'][0]['text'],true)===$data
            &&!str_contains(json_encode($response),'PRIVATE')&&!str_contains(json_encode($response),'customer_text')
            &&!str_contains(json_encode($response),'secret')&&!isset($data['messages']));
    }
    foreach ([['PRIVATE SELECT source','42000',-1],['summary','XX',65536]] as [$stage,$state,$driver]) {
        $exception=new ArchiveResearchReadException('unavailable',$stage,$state,$driver);
        (new ReflectionProperty(Exception::class,'message'))->setValue($exception,'PRIVATE password binding SQLSTATE[42000]');
        $hook=static function() use($exception):void {throw $exception;};
        $response=$invoke('archive_research_inventory');
        $diagnostic=$response['result']['structuredContent']['diagnostic']??[];
        archiveMcpCheck('unknown diagnostic values and exception text are not reflected',
            $diagnostic===['stage'=>($stage==='summary'?'summary':'unknown'),'sqlstate_class'=>null,'driver_code'=>null]
            &&!str_contains(json_encode($response),'PRIVATE')&&!str_contains(json_encode($response),'42000')
            &&!str_contains(json_encode($response),'password')&&!str_contains(json_encode($response),'65536'));
    }
    foreach (['forbidden','permission_changed'] as $reason) {
        $hook=static function() use($reason):void {throw new ArchiveResearchReadException($reason,'key_after','28',1045);};
        $denied=$invoke('archive_research_inventory');
        archiveMcpCheck('source authorization denial withholds all source diagnostic metadata',
            ($denied['error']['code']??null)===-32001&&!isset($denied['result'])
            &&!str_contains(json_encode($denied),'key_after')&&!str_contains(json_encode($denied),'1045'));
    }
    foreach (['forbidden','permission_changed','invalid_arguments','PRIVATE unexpected reason'] as $reason) {
        $hook=static function() use($reason):void {throw new ArchiveResearchReadException($reason);};
        $failed=$invoke('archive_research_inventory');
        $expected=in_array($reason,['forbidden','permission_changed'],true)?-32001:($reason==='invalid_arguments'?-32602:-32603);
        archiveMcpCheck('source authorization and unrecognized reason cannot expose private failure text',
            ($failed['error']['code']??null)===$expected&&!isset($failed['result'])
            &&!str_contains(json_encode($failed),'PRIVATE'));
    }
    foreach (['PRIVATE invalid response',['ok'=>true,'text'=>str_repeat('x',65537)]] as $invalid) {
        $override=$invalid;
        $failed=$invoke('archive_research_inventory');
        archiveMcpCheck('malformed or oversized result fails closed without payload',
            ($failed['error']['code']??null)===-32603&&!isset($failed['result'])&&!str_contains(json_encode($failed),'PRIVATE'));
    }
    $unit=chr(34).chr(92)."\n".'Ж';
    $override=['ok'=>true,'project_key'=>'anytour','text'=>str_repeat($unit,8100)];
    $dataBytes=strlen(json_encode($override,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $heavyRpc=archiveMcpRpc('read_archive_history',['chat_ref'=>'synthetic.ref']);
    $heavyRpc['id']=str_repeat(chr(34).chr(92),64);
    $heavy=$service->handle($heavyRpc,1,$connection,[$archiveScope]);
    $wire=json_encode($heavy,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    archiveMcpCheck('near-budget escaped UTF8 data stays bounded in the complete duplicated MCP wire',
        $dataBytes>64000&&$dataBytes<=65536&&strlen($wire)<=262144
        &&!isset($heavy['error'])&&$heavy['result']['structuredContent']===$override
        &&json_decode($heavy['result']['content'][0]['text'],true)===$override);
    $override=['ok'=>true,'text'=>str_repeat(chr(34),33000)];
    $tooMuch=$invoke('archive_research_inventory');
    archiveMcpCheck('escaped data exceeding the data envelope fails without a partial private result',
        ($tooMuch['error']['code']??null)===-32603&&!isset($tooMuch['result']));
    $override=null;
    $now+=30;
    $rotated=$oauth->exchange(['grant_type'=>'refresh_token','client_id'=>$client,'resource'=>ManagerPreparationOAuth::RESOURCE,
        'refresh_token'=>$tokens[$archiveScope]['refresh_token']]);
    archiveMcpCheck('new active credential selection can stale prior cursor keys',
        !hash_equals($key,$oauth->archiveCursorKey($connection,1))&&$oauth->contextForBearer('Bearer '.$rotated['access_token'])['connection_id']===$connection);
    $sourceBefore=$pdo->query('SELECT * FROM messages')->fetchAll();
    $eventsBefore=$pdo->query('SELECT * FROM manager_preparation_events')->fetchAll();
    $jobsBefore=$pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll();
    $changes=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
    $actual=new ManagerPreparationMcpService($pdo,$ordinary,null,$clock);
    $unavailable=$actual->handle(archiveMcpRpc('archive_research_inventory',['limit'=>1]),1,$connection,[$archiveScope]);
    archiveMcpCheck('actual unavailable archive source exposes no exception or unrelated transcript',
        (($unavailable['error']['code']??null)===-32603||($unavailable['result']['isError']??false)===true)
        &&!str_contains(json_encode($unavailable),'Synthetic unrelated source')
        &&!str_contains(json_encode($unavailable),'SQLSTATE')
        &&!str_contains(json_encode($unavailable),'token_hash'));
    archiveMcpCheck('all readers leave ordinary source, jobs, events and credentials unchanged',
        $pdo->query('SELECT * FROM messages')->fetchAll()===$sourceBefore
        &&$pdo->query('SELECT * FROM manager_preparation_events')->fetchAll()===$eventsBefore
        &&$pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll()===$jobsBefore
        &&(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$changes&&$ordinaryCalls===0);
    echo 'Archive research MCP regression: '.$passed." checks passed\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage()."\n");exit(1);
}
