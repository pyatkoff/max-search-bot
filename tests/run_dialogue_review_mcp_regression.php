<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ManagerPreparationMcpService.php';
require_once dirname(__DIR__).'/services/SeparatedMcpService.php';

/** Synthetic race signal: marks an actual canonical message payload read. */
final class DialogueSelectionGrantStatement extends PDOStatement
{
    public static bool $payloadRead=false;
    public static $withdrawAfterSnapshot=null;
    protected function __construct(private PDO $fixturePdo){}
    public function execute(?array $params=null):bool
    {
        if(self::$payloadRead&&!$this->fixturePdo->inTransaction()&&is_callable(self::$withdrawAfterSnapshot)){
            $withdraw=self::$withdrawAfterSnapshot;self::$withdrawAfterSnapshot=null;$withdraw();
        }
        return parent::execute($params);
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array
    {
        $rows=parent::fetchAll($mode,...$args);
        if(str_contains($this->queryString,'AS metadata_json FROM messages m '))self::$payloadRead=true;
        return$rows;
    }
}

$passed=0;
function dialogueMcpCheck(string $name,bool $ok): void
{
    global $passed;
    if(!$ok)throw new RuntimeException('FAIL '.$name);
    ++$passed;echo 'PASS '.$name."\n";
}
function dialogueMcpRpc(string $name,array $args=[],int|string $id=1): array
{
    return ['jsonrpc'=>'2.0','id'=>$id,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]];
}
function dialogueMcpGrant(ManagerPreparationOAuth $oauth,string $client,string $scope,string $profile=ManagerPreparationOAuth::PROFILE_LEGACY): array
{
    $resource=ManagerPreparationOAuth::profileDefinition($profile)['resource'];
    $verifier=str_repeat('v',43);$session=['csrf'=>'synthetic-dialogue-csrf'];
    $pending=$oauth->beginAuthorization($session,['client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,
        'response_type'=>'code','resource'=>$resource,'scope'=>$scope,'state'=>'synthetic-dialogue-state',
        'code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')]);
    $redirect=$oauth->completeAuthorization($session,$pending,1,$session['csrf'],true);
    parse_str((string)parse_url($redirect,PHP_URL_QUERY),$query);
    $token=$oauth->exchange(['grant_type'=>'authorization_code','client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,
        'resource'=>$resource,'code'=>$query['code'],'code_verifier'=>$verifier]);
    $context=$oauth->contextForBearer('Bearer '.$token['access_token']);
    if(!is_array($context))throw new RuntimeException('FAIL real consent did not produce a bearer context');
    return [$token,$context];
}
function dialogueMcpChallenge(array $response): string
{
    return (string)($response['result']['_meta']['mcp/www_authenticate'][0]??'');
}
function dialogueMcpCredentialRows(PDO $pdo): array
{
    return [
        $pdo->query('SELECT * FROM manager_preparation_oauth_grants ORDER BY grant_id')->fetchAll(),
        $pdo->query('SELECT * FROM manager_preparation_oauth_codes ORDER BY code_hash')->fetchAll(),
        $pdo->query('SELECT * FROM manager_preparation_oauth_tokens ORDER BY token_hash')->fetchAll(),
    ];
}

try{
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    (new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->setValue(null,false);
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic dialogue review']]);
    $pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
        CREATE TABLE managers(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER,is_working INTEGER);
        CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
        CREATE TABLE manager_preparation_oauth_clients(client_id TEXT PRIMARY KEY,redirect_uri TEXT,registration_bucket TEXT,created_at INTEGER);
        CREATE TABLE manager_preparation_oauth_grants(grant_id TEXT PRIMARY KEY,client_id TEXT,admin_id INTEGER,project_key TEXT,resource TEXT,scopes TEXT CHECK(length(scopes)<=160),created_at INTEGER,expires_at INTEGER,revoked_at INTEGER);
        CREATE TABLE manager_preparation_oauth_codes(code_hash TEXT PRIMARY KEY,grant_id TEXT,redirect_uri TEXT,code_challenge TEXT,expires_at INTEGER,consumed_at INTEGER);
        CREATE TABLE manager_preparation_oauth_tokens(token_hash TEXT PRIMARY KEY,grant_id TEXT,token_kind TEXT,expires_at INTEGER,consumed_at INTEGER);
        INSERT INTO projects VALUES(1,'anytour','Synthetic dialogue review',1),(2,'other','Other',1);
        INSERT INTO managers VALUES(1,'admin',1,0),(2,'manager',1,1),(3,'admin',1,0),(4,'admin',0,0);
        INSERT INTO manager_projects VALUES(1,1),(2,1),(3,2),(4,1);
        CREATE TABLE messages(id INTEGER PRIMARY KEY,text TEXT);
        INSERT INTO messages VALUES(1,'Synthetic stored customer text');
        CREATE TABLE manager_conversation_reads(manager_id INTEGER,conversation_id INTEGER,last_read_id INTEGER);
        INSERT INTO manager_conversation_reads VALUES(1,5,1);
        CREATE TABLE manager_preparation_events(subscription_id TEXT PRIMARY KEY,pending_body TEXT);
        INSERT INTO manager_preparation_events VALUES('existing-event','unchanged');
        CREATE TABLE manager_prepared_replies(job_id TEXT PRIMARY KEY,status TEXT);
        INSERT INTO manager_prepared_replies VALUES('existing-job','pending');");
    $now=1793790000;$clock=static function()use(&$now):int{return $now;};
    $oauth=new ManagerPreparationOAuth($pdo,$clock);
    $registration=$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'synthetic-dialogue-client');
    $client=$registration['client_id'];
    $legacyScope=implode(' ',ManagerPreparationOAuth::SCOPES);
    $archiveScope=ManagerPreparationOAuth::ARCHIVE_SCOPE;
    $forecastScope=ManagerPreparationOAuth::FORECAST_SCOPE;
    $dialogueScope=ManagerPreparationOAuth::DIALOGUE_SCOPE;
    $qualityScope=ManagerPreparationOAuth::QUALITY_SCOPE;
    $allScope=implode(' ',ManagerPreparationOAuth::SUPPORTED_SCOPES);
    $profiles=[$legacyScope,$archiveScope,$forecastScope,$dialogueScope,
        $legacyScope.' '.$dialogueScope,$archiveScope.' '.$dialogueScope,$forecastScope.' '.$dialogueScope,$qualityScope,$dialogueScope.' '.$qualityScope,$allScope];
    $tokens=[];$contexts=[];
    foreach($profiles as $scope){
        [$tokens[$scope],$contexts[$scope]]=dialogueMcpGrant($oauth,$client,$scope);
        dialogueMcpCheck('explicit consent stores exactly its requested canonical scopes '.$scope,
            $tokens[$scope]['scope']===$scope&&$contexts[$scope]['scopes']===explode(' ',$scope));
    }
    $connection=$contexts[$dialogueScope]['connection_id'];$legacy=$contexts[$legacyScope]['connection_id'];
    dialogueMcpCheck('ordinary registration and defaults grant no live transcript access',
        $registration['scope']===$legacyScope&&ManagerPreparationOAuth::SCOPES===['queue:read','prepared:write']
        &&!$oauth->connectionAuthorized($legacy,1,[$dialogueScope]));
    $previousAllScope=implode(' ',array_values(array_filter(ManagerPreparationOAuth::SUPPORTED_SCOPES,static fn(string $scope):bool=>$scope!==$qualityScope&&$scope!==ManagerPreparationOAuth::BOOKING_SCOPE)));
    dialogueMcpCheck('existing explicit rights remain the same125-byte sequence',strlen($previousAllScope)===125&&strlen($previousAllScope)<=128);
    dialogueMcpCheck('optional history and booking rights fit forward160-byte storage',strlen($allScope)===152&&strlen($allScope)<=160);
    $historyBefore=dialogueMcpCredentialRows($pdo);
    try{
        $oauth->exchange(['grant_type'=>'refresh_token','client_id'=>$client,'resource'=>ManagerPreparationOAuth::RESOURCE,
            'refresh_token'=>$tokens[$dialogueScope]['refresh_token'],'scope'=>$dialogueScope.' '.$qualityScope]);
        throw new RuntimeException('FAIL refresh added historical scope');
    }catch(InvalidArgumentException $error){
        dialogueMcpCheck('existing transcript refresh cannot silently add closed dialogue or proposal history',
            $error->getMessage()==='invalid_scope'&&dialogueMcpCredentialRows($pdo)===$historyBefore);
    }
    $oversizedScopeBefore=dialogueMcpCredentialRows($pdo);
    try{$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI],'scope'=>str_repeat('x',161)],'synthetic-history-overflow');throw new RuntimeException('FAIL oversized scope accepted');}
    catch(InvalidArgumentException $error){dialogueMcpCheck('forward160-byte scope parser still rejects oversized input without credential mutation',$error->getMessage()==='invalid_scope'&&dialogueMcpCredentialRows($pdo)===$oversizedScopeBefore);}
    $historyConnection=$contexts[$dialogueScope.' '.$qualityScope]['connection_id'];
    $historyKey=$oauth->qualityCursorKey($historyConnection,1);
    dialogueMcpCheck('history cursor key is separately consented and domain-separated',
        strlen($historyKey)===32&&!hash_equals($historyKey,$oauth->dialogueCursorKey($historyConnection,1)));
    try{$oauth->qualityCursorKey($contexts[$dialogueScope]['connection_id'],1);throw new RuntimeException('FAIL old read grant obtained historical key');}
    catch(DomainException $error){dialogueMcpCheck('old read grant is not silently upgraded to history',true);}
    $before=dialogueMcpCredentialRows($pdo);
    try{
        $oauth->exchange(['grant_type'=>'refresh_token','client_id'=>$client,'resource'=>ManagerPreparationOAuth::RESOURCE,
            'refresh_token'=>$tokens[$legacyScope]['refresh_token'],'scope'=>$legacyScope.' '.$dialogueScope]);
        throw new RuntimeException('FAIL refresh added dialogue scope');
    }catch(InvalidArgumentException $error){
        dialogueMcpCheck('old refresh cannot silently add dialogue access',
            $error->getMessage()==='invalid_scope'&&dialogueMcpCredentialRows($pdo)===$before
            &&!$oauth->connectionAuthorized($legacy,1,[$dialogueScope]));
    }
    $changes=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
    $key=$oauth->dialogueCursorKey($connection,1);
    $both=$contexts[$archiveScope.' '.$dialogueScope]['connection_id'];
    $bothToken=$tokens[$archiveScope.' '.$dialogueScope]['access_token'];
    $archiveKey=$oauth->archiveCursorKey($both,1);
    $expectedArchive=hash_hmac('sha256',"anytour.archive-research.cursor.v1\0".'1'."\0".$both,hash('sha256',$bothToken,true),true);
    dialogueMcpCheck('live cursor keys are private fixed-width projections tied to their family',
        strlen($key)===32&&hash_equals($key,$oauth->dialogueCursorKey($connection,1))
        &&!hash_equals($key,hash('sha256',$tokens[$dialogueScope]['access_token'],true))
        &&!hash_equals($key,$oauth->dialogueCursorKey($both,1)));
    dialogueMcpCheck('archive key bytes remain unchanged and cannot authorize a live cursor',
        hash_equals($archiveKey,$expectedArchive)&&!hash_equals($archiveKey,$oauth->dialogueCursorKey($both,1)));
    foreach([[$legacy,1],[$contexts[$archiveScope]['connection_id'],1],[$contexts[$forecastScope]['connection_id'],1],[$connection,2],[$connection,3],[str_repeat('9',32),1]]as [$family,$actor]){
        try{$oauth->dialogueCursorKey($family,$actor);throw new RuntimeException('FAIL foreign cursor key accepted');}
        catch(DomainException $error){dialogueMcpCheck('cursor key requires its exact active administrator and dialogue grant',true);}
    }
    dialogueMcpCheck('cursor authorization and derivation perform no writes',(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$changes);
    $pdo->beginTransaction();
    try{$oauth->dialogueCursorKey($connection,1);throw new RuntimeException('FAIL borrowed transaction authorized a cursor');}
    catch(DomainException $error){dialogueMcpCheck('cursor authorization refuses a borrowed transaction without ending it',$pdo->inTransaction());}
    finally{$pdo->rollBack();}

    $calls=[];$ordinaryCalls=0;$hook=null;$override=null;
    $ordinary=static function()use(&$ordinaryCalls):array{++$ordinaryCalls;return [];};
    $store=static function(string $operation,array $arguments)use(&$calls,&$hook,&$override):mixed{
        $calls[]=[$operation,$arguments];
        if($hook!==null){$run=$hook;$hook=null;$run();}
        if($override!==null)return $override;
        return ['ok'=>true,'project_key'=>'anytour','schema_version'=>1,'operation'=>$operation,
            'dialogues'=>$operation==='inventory'?[['dialogue_ref'=>'synthetic.ref','channel'=>'max','status'=>'ai']]:[],
            'messages'=>$operation==='context'?[['sender_type'=>'customer','text'=>'PRIVATE synthetic dialogue text']]:[],
            'has_more'=>false,'next_cursor'=>null];
    };
    $service=new ManagerPreparationMcpService($pdo,$ordinary,clock:$clock,dialogueStore:$store);
    $invoke=static fn(string $name,array $args=[],int $actor=1,?string $family=null,?array $scopes=null):array=>
        $service->handle(dialogueMcpRpc($name,$args),$actor,$family??$connection,$scopes??[$dialogueScope]);
    $list=static fn(string $family,array $scopes):array=>
        $service->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'],1,$family,$scopes)['result']['tools'];
    $before=dialogueMcpCredentialRows($pdo);$changes=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
    $dialogueTools=$list($connection,[$dialogueScope]);
    dialogueMcpCheck('dialogue-only discovery exposes two readers and separate aggregate forecast consent',
        array_column($dialogueTools,'name')===array_merge([ModeledConversionStatusMcpAdapter::NAME],DialogueReviewMcpAdapter::NAMES,ManagerTourSelectionMcpAdapter::NAMES)
        &&$calls===[]&&$ordinaryCalls===0);
    foreach($dialogueTools as $tool){
        if(!in_array($tool['name'],DialogueReviewMcpAdapter::NAMES,true))continue;
        dialogueMcpCheck('review tool declares bounded readonly explicit dialogue permission '.$tool['name'],
            $tool['inputSchema']['additionalProperties']===false&&$tool['annotations']['readOnlyHint']===true
            &&$tool['annotations']['destructiveHint']===false&&$tool['annotations']['idempotentHint']===true
            &&$tool['annotations']['openWorldHint']===false
            &&$tool['securitySchemes']===[['type'=>'oauth2','scopes'=>[$dialogueScope]]]
            &&$tool['_meta']['securitySchemes']===$tool['securitySchemes']);
    }
    $byName=array_column($dialogueTools,null,'name');
    dialogueMcpCheck('metadata and transcript have independent bounded pages and opaque references',
        $byName['list_current_dialogues']['inputSchema']['properties']['limit']['maximum']===50
        &&$byName['get_dialogue_review_context']['inputSchema']['properties']['limit']['maximum']===100
        &&$byName['get_dialogue_review_context']['inputSchema']['required']===['dialogue_ref']);
    dialogueMcpCheck('newest traversal uses only the existing bounded inventory inputs',
        array_keys($byName['list_current_dialogues']['inputSchema']['properties'])===['limit','cursor']
        &&$byName['list_current_dialogues']['inputSchema']['required']===[]);
    foreach([$legacyScope,$forecastScope]as $scope){
        $family=$contexts[$scope]['connection_id'];$scopes=explode(' ',$scope);
        dialogueMcpCheck('existing capability discovers live review without gaining it '.$scope,
            array_values(array_intersect(array_column($list($family,$scopes),'name'),DialogueReviewMcpAdapter::NAMES))===DialogueReviewMcpAdapter::NAMES
            &&!$oauth->connectionAuthorized($family,1,[$dialogueScope]));
        $response=$invoke('get_dialogue_review_context',['dialogue_ref'=>'PRIVATE.synthetic.ref'],1,$family,$scopes);
        $challenge=dialogueMcpChallenge($response);
        dialogueMcpCheck('explicit step-up precedes every transcript read '.$scope,
            ($response['result']['isError']??false)===true&&str_contains($challenge,'error="insufficient_scope"')
            &&str_contains($challenge,'scope="'.$scope.' '.$dialogueScope.'"')
            &&$calls===[]&&!str_contains(json_encode($response),'PRIVATE.synthetic.ref'));
    }
    dialogueMcpCheck('advertising and requesting live review do not mutate existing credentials',
        dialogueMcpCredentialRows($pdo)===$before&&(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$changes);
    $historyCalls=count($calls);
    $historyStepUp=$invoke('list_current_dialogues',['cursor'=>'qh.manual.start']);
    dialogueMcpCheck('history descriptor requires explicit separate consent before any source read',
        str_contains(dialogueMcpChallenge($historyStepUp),'scope="'.$dialogueScope.' '.$qualityScope.'"')
        &&count($calls)===$historyCalls&&dialogueMcpCredentialRows($pdo)===$before);
    $historyTools=$list($contexts[$qualityScope]['connection_id'],[$qualityScope]);
    dialogueMcpCheck('explicit history-only grant discovers existing readers without adding other rights',
        array_values(array_intersect(array_column($historyTools,'name'),DialogueReviewMcpAdapter::NAMES))===DialogueReviewMcpAdapter::NAMES);
    $inventory=$invoke('list_current_dialogues',['limit'=>3]);
    dialogueMcpCheck('inventory dispatch uses trusted actor and connection with no arbitrary account selector',
        ($inventory['result']['structuredContent']['operation']??null)==='inventory'
        &&end($calls)===['inventory',[1,$connection,['limit'=>3]]]);
    dialogueMcpCheck('ordinary inventory advertises separately consented monthly lanes without payload',
        ($inventory['result']['structuredContent']['quality_history']['scope']??null)===$qualityScope
        &&array_keys($inventory['result']['structuredContent']['quality_history']['lanes'])===['native','archive','manual','prepared','virtual']);
    $contextArgs=['dialogue_ref'=>'synthetic.ref','cursor'=>'synthetic.cursor','limit'=>10];
    $context=$invoke('get_dialogue_review_context',$contextArgs);
    dialogueMcpCheck('context dispatch preserves exact arguments and both returned JSON representations',
        ($context['result']['structuredContent']['operation']??null)==='context'
        &&end($calls)===['context',[1,$connection,$contextArgs]]
        &&json_decode($context['result']['content'][0]['text'],true)===$context['result']['structuredContent']);
    $beforeCalls=count($calls);
    $badArgs=[
        ['list_current_dialogues',['limit'=>0]],['list_current_dialogues',['limit'=>51]],
        ['list_current_dialogues',['limit'=>'3']],['list_current_dialogues',['cursor'=>null]],
        ['list_current_dialogues',['cursor'=>'']],['list_current_dialogues',['project_key'=>'other']],
        ['list_current_dialogues',['account_id'=>1]],['list_current_dialogues',['after_id'=>1]],
        ['list_current_dialogues',['order'=>'conversation_id_desc']],['list_current_dialogues',['since'=>'2026-11-04']],
        ['get_dialogue_review_context',[]],['get_dialogue_review_context',['dialogue_ref'=>[]]],
        ['get_dialogue_review_context',['dialogue_ref'=>'bad/route']],['get_dialogue_review_context',['dialogue_ref'=>'synthetic.ref','limit'=>101]],
        ['get_dialogue_review_context',['dialogue_ref'=>'synthetic.ref','limit'=>0]],['get_dialogue_review_context',['dialogue_ref'=>'synthetic.ref','limit'=>'3']],
        ['get_dialogue_review_context',['dialogue_ref'=>'synthetic.ref','cursor'=>str_repeat('a',2049)]],
        ['get_dialogue_review_context',['dialogue_ref'=>'synthetic.ref','conversation_id'=>9]],
        ['get_dialogue_review_context',['dialogue_ref'=>'synthetic.ref','admin_id'=>2]],
        ['get_dialogue_review_context',['dialogue_ref'=>'synthetic.ref','connection_id'=>$legacy]],
        ['get_dialogue_review_context',['dialogue_ref'=>'synthetic.ref','mark_read'=>true]],
        ['get_dialogue_review_context',['dialogue_ref'=>str_repeat('a',2049)]],
    ];
    foreach($badArgs as [$name,$args]){
        $denied=$invoke($name,$args);
        dialogueMcpCheck('invalid or caller-selected source argument is refused before the reader '.$name,
            ($denied['error']['code']??null)===-32602&&count($calls)===$beforeCalls);
    }
    foreach([0,2,3,4]as $actor){
        $denied=$invoke('list_current_dialogues',[],$actor);
        dialogueMcpCheck('current active project administrator is required '.$actor,
            ($denied['error']['code']??null)===-32001&&count($calls)===$beforeCalls);
    }
    foreach([null,$legacy,str_repeat('9',32)]as $family){
        $denied=$service->handle(dialogueMcpRpc('list_current_dialogues'),1,$family,[$dialogueScope]);
        dialogueMcpCheck('missing foreign and caller-forged dialogue grants cannot reach the reader',
            ($denied['error']['code']??null)===-32001&&count($calls)===$beforeCalls);
    }
    $oversized=$service->handle(dialogueMcpRpc('list_current_dialogues',[],str_repeat('x',129)),1,$connection,[$dialogueScope]);
    dialogueMcpCheck('oversized direct RPC identifier is rejected without reflection or a source read',
        ($oversized['error']['code']??null)===-32600&&array_key_exists('id',$oversized)&&$oversized['id']===null
        &&count($calls)===$beforeCalls&&strlen(json_encode($oversized))<512);
    foreach(['list_pending_replies','get_reply_context','save_prepared_reply','capture_virtual_replies','customers/send']as $name){
        $denied=$invoke($name);
        dialogueMcpCheck('dialogue-only grant cannot prepare learn or send '.$name,
            ($denied['error']['code']??null)===-32001&&$ordinaryCalls===0&&count($calls)===$beforeCalls);
    }
    dialogueMcpCheck('successful reads and rejected calls do not write source jobs read markers or credentials',
        (int)$pdo->query('SELECT total_changes()')->fetchColumn()===$changes&&dialogueMcpCredentialRows($pdo)===$before
        &&$pdo->query('SELECT text FROM messages WHERE id=1')->fetchColumn()==='Synthetic stored customer text'
        &&(int)$pdo->query('SELECT last_read_id FROM manager_conversation_reads')->fetchColumn()===1
        &&$pdo->query('SELECT status FROM manager_prepared_replies')->fetchColumn()==='pending'
        &&$pdo->query('SELECT pending_body FROM manager_preparation_events')->fetchColumn()==='unchanged'
        &&(int)$pdo->query('SELECT is_working FROM managers WHERE id=1')->fetchColumn()===0);
    $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$legacy]);
    $revokedStepUp=$invoke('list_current_dialogues',[],1,$legacy,ManagerPreparationOAuth::SCOPES);
    dialogueMcpCheck('revoked old connection is denied instead of receiving dialogue step-up',
        ($revokedStepUp['error']['code']??null)===-32001&&dialogueMcpChallenge($revokedStepUp)===''
        &&count($calls)===$beforeCalls);
    $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=NULL WHERE grant_id=?')->execute([$legacy]);

    // Use actual audience-bound grants, not caller-provided scope stand-ins.
    foreach([ManagerPreparationOAuth::PROFILE_REPLIES,ManagerPreparationOAuth::PROFILE_HOTELS]as $profile){
        $profileOauth=new ManagerPreparationOAuth($pdo,$clock,$profile);
        $registered=$profileOauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'synthetic-dialogue-'.$profile);
        [$profileToken,$profileContext]=dialogueMcpGrant($profileOauth,$registered['client_id'],$registered['scope'],$profile);
        $surface=new SeparatedMcpService($profile,$pdo,$ordinary,$clock);
        $listed=$surface->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'],1,$profileContext['connection_id'],$profileContext['scopes']);
        dialogueMcpCheck('dedicated audience never advertises live-dialogue readers '.$profile,
            isset($listed['result']['tools'])&&array_intersect(array_column($listed['result']['tools'],'name'),DialogueReviewMcpAdapter::NAMES)===[]);
        foreach(DialogueReviewMcpAdapter::NAMES as $name){
            $denied=$surface->handle(dialogueMcpRpc($name,['dialogue_ref'=>'PRIVATE.synthetic.ref']),1,$profileContext['connection_id'],$profileContext['scopes']);
            dialogueMcpCheck('dedicated audience refuses live-dialogue dispatch and step-up '.$profile.'/'.$name,
                ($denied['error']['code']??null)===-32601&&!isset($denied['result'])
                &&$ordinaryCalls===0&&count($calls)===$beforeCalls);
        }
        $denied=$surface->handle(dialogueMcpRpc('list_current_dialogues'),1,$connection,[$dialogueScope]);
        dialogueMcpCheck('legacy dialogue grant cannot cross a dedicated audience '.$profile,
            ($denied['error']['code']??null)===-32001&&count($calls)===$beforeCalls);
        try{
            $profileOauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI],'scope'=>$dialogueScope],'synthetic-dialogue-rejected');
            throw new RuntimeException('FAIL dedicated issuer accepted dialogue scope');
        }catch(InvalidArgumentException $error){dialogueMcpCheck('dedicated issuer cannot register live access '.$profile,$error->getMessage()==='invalid_scope');}
    }

    $withdrawals=[
        ["UPDATE managers SET is_active=0 WHERE id=1","UPDATE managers SET is_active=1 WHERE id=1"],
        ["UPDATE managers SET role='manager' WHERE id=1","UPDATE managers SET role='admin' WHERE id=1"],
        ["DELETE FROM manager_projects WHERE manager_id=1 AND project_id=1","INSERT INTO manager_projects VALUES(1,1)"],
        ["UPDATE projects SET is_active=0 WHERE id=1","UPDATE projects SET is_active=1 WHERE id=1"],
    ];
    foreach($withdrawals as [$withdraw,$restore]){
        $hook=static function()use($pdo,$withdraw):void{$pdo->exec($withdraw);};
        $denied=$invoke('get_dialogue_review_context',['dialogue_ref'=>'synthetic.ref']);
        dialogueMcpCheck('authorization withdrawn during context reading withholds the complete result',
            ($denied['error']['code']??null)===-32001&&!isset($denied['result'])&&!str_contains(json_encode($denied),'PRIVATE'));
        $pdo->exec($restore);
    }
    foreach(['revoked_at','scopes']as $withdraw){
        $hook=static function()use($pdo,$connection,$now,$legacyScope,$withdraw):void{
            $pdo->prepare('UPDATE manager_preparation_oauth_grants SET '.$withdraw.'=? WHERE grant_id=?')
                ->execute([$withdraw==='revoked_at'?$now:$legacyScope,$connection]);
        };
        $denied=$invoke('get_dialogue_review_context',['dialogue_ref'=>'synthetic.ref']);
        dialogueMcpCheck('withdrawn connection or capability cannot disclose a completed context '.$withdraw,
            ($denied['error']['code']??null)===-32001&&!isset($denied['result'])&&!str_contains(json_encode($denied),'PRIVATE'));
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=NULL,scopes=? WHERE grant_id=?')->execute([$dialogueScope,$connection]);
    }
    $hook=static function():void{throw new RuntimeException('PRIVATE SQL and token details');};
    $failure=$invoke('list_current_dialogues');
    dialogueMcpCheck('unexpected reader errors never expose source or credential details',
        ($failure['error']['code']??null)===-32603&&!str_contains(json_encode($failure),'PRIVATE'));
    $bounded=['ok'=>true,'payload'=>''];
    $payloadBytes=65536-strlen(json_encode($bounded,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $bounded['payload']=str_repeat('"',intdiv($payloadBytes,2)).str_repeat('x',$payloadBytes%2);
    $override=$bounded;
    $boundedResponse=$service->handle(dialogueMcpRpc('list_current_dialogues',[],str_repeat('i',128)),1,$connection,[$dialogueScope]);
    dialogueMcpCheck('exact64KiB structured data and128-byte identifier remain within complete256KiB wire bound',
        strlen(json_encode($bounded,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===65536
        &&($boundedResponse['result']['structuredContent']??null)===$bounded
        &&json_decode($boundedResponse['result']['content'][0]['text'],true)===$bounded
        &&strlen(json_encode($boundedResponse,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=262144);
    $oneByteOver=$bounded;$oneByteOver['payload'].='x';
    foreach([[],$oneByteOver,['ok'=>true,'payload'=>"\xff"]]as $invalid){
        $override=$invalid;$failure=$invoke('list_current_dialogues');
        dialogueMcpCheck('invalid oversized and non-UTF8 reader responses are wholly refused',
            ($failure['error']['code']??null)===-32603&&!isset($failure['result']));
    }
    $override=null;
    $beforeRefreshKey=$oauth->dialogueCursorKey($connection,1);$now+=60;
    $rotated=$oauth->exchange(['grant_type'=>'refresh_token','client_id'=>$client,'resource'=>ManagerPreparationOAuth::RESOURCE,
        'refresh_token'=>$tokens[$dialogueScope]['refresh_token']]);
    dialogueMcpCheck('ordinary token refresh retains exact rights and stales previous cursor key',
        $rotated['scope']===$dialogueScope&&$oauth->contextForBearer('Bearer '.$rotated['access_token'])['connection_id']===$connection
        &&!hash_equals($beforeRefreshKey,$oauth->dialogueCursorKey($connection,1)));
    // Exercise the production adapter/store factory with actual grants and native
    // source rows, rather than supplying a caller-controlled projection flag.
    $pdo->exec("DROP TABLE messages;
        CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT);
        CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,channel TEXT,external_chat_id TEXT,status TEXT,manager_id INTEGER,source_id INTEGER,is_test INTEGER,started_at TEXT,last_message_at TEXT,updated_at TEXT);
        CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,direction TEXT,sender_type TEXT,channel TEXT,text TEXT,metadata_json TEXT,created_at TEXT);
        CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT);
        INSERT INTO customer_channels VALUES(10,1010,'anytour','max','synthetic-source-user','synthetic-source-chat');
        INSERT INTO customer_channels VALUES(11,1011,'anytour','telegram','synthetic-new-user','synthetic-new-chat');
        INSERT INTO conversations VALUES(10,1010,10,'anytour','max','synthetic-source-chat','manager',1,1,0,'2026-11-04 09:00:00','2026-11-04 09:00:00','2026-11-04 09:00:00');
        INSERT INTO conversations VALUES(11,1011,11,'anytour','telegram','synthetic-new-chat','ai',NULL,1,0,'2026-11-04 10:00:00','2026-11-04 10:00:00','2026-11-04 10:00:00');");
    $sourceText='Варианты https://qui-quo.ru/AB12-CD34 и https://example.test/PRIVATE_SOURCE';
    $pdo->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?)')
        ->execute([501,10,'outbound','manager','max',$sourceText,'{"type":"message"}','2026-11-04 09:00:00']);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[DialogueSelectionGrantStatement::class,[$pdo]]);
    $pairScope=$dialogueScope.' '.ManagerPreparationOAuth::SELECTION_SCOPE;
    [, $pairContext]=dialogueMcpGrant($oauth,$client,$pairScope);
    [, $selectionContext]=dialogueMcpGrant($oauth,$client,ManagerPreparationOAuth::SELECTION_SCOPE);
    $native=new ManagerPreparationMcpService($pdo,clock:$clock);
    $nativeCall=static fn(ManagerPreparationMcpService $target,array $context,string $name,array $arguments=[]):array=>
        $target->handle(dialogueMcpRpc($name,$arguments),1,$context['connection_id'],$context['scopes']);
    $nativeInventory=$nativeCall($native,$pairContext,'list_current_dialogues');
    $sourceRef=$nativeInventory['result']['structuredContent']['dialogues'][0]['dialogue_ref']??null;
    dialogueMcpCheck('actual dual grant reaches a canonical current source reference',is_string($sourceRef));
    DialogueSelectionGrantStatement::$payloadRead=false;
    $nativePacket=$nativeCall($native,$pairContext,'get_dialogue_review_context',['dialogue_ref'=>$sourceRef]);
    $nativeRow=$nativePacket['result']['structuredContent']['messages'][0]??[];
    $nativeSources=$nativeRow['selection_sources']['references']??[];
    dialogueMcpCheck('actual dual OAuth grant and production factory expose only the canonical public source',
        DialogueSelectionGrantStatement::$payloadRead&&count($nativeSources)===1
        &&$nativeSources[0]['url']==='https://qui-quo.ru/AB12-CD34'
        &&$nativeSources[0]['message_id']==='501'&&$nativeSources[0]['source_ref']==='message:501'
        &&$nativeSources[0]['message_revision']===$nativeRow['revision']
        &&!str_contains($nativeRow['text'],'https://')&&!str_contains(json_encode($nativePacket),'PRIVATE_SOURCE'));
    $dialogueContext=$oauth->contextForBearer('Bearer '.$rotated['access_token']);
    $ordinaryInventory=$nativeCall($native,$dialogueContext,'list_current_dialogues');
    $newestStart=$ordinaryInventory['result']['structuredContent']['newest_open']['start_cursor']??null;
    dialogueMcpCheck('real dialogue-only inventory supplies newest start without historical consent',
        is_string($newestStart)&&!DialogueReviewMcpAdapter::isHistory(['cursor'=>$newestStart])
        &&$ordinaryInventory['result']['structuredContent']['newest_open']['order']==='conversation_id_desc'
        &&$ordinaryInventory['result']['structuredContent']['newest_open']['latest_activity_inventory']===false
        &&$ordinaryInventory['result']['structuredContent']['newest_open']['all_today_coverage']===false);
    $newestChanges=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
    $newestCredentials=dialogueMcpCredentialRows($pdo);
    $newestPacket=$nativeCall($native,$dialogueContext,'list_current_dialogues',['cursor'=>$newestStart,'limit'=>1]);
    $newestData=$newestPacket['result']['structuredContent']??[];
    $newestTail=$nativeCall($native,$dialogueContext,'list_current_dialogues',['cursor'=>$newestData['next_cursor'],'limit'=>1]);
    $newestTailData=$newestTail['result']['structuredContent']??[];
    dialogueMcpCheck('actual adapter returns descending pages and ordinary scoped references',
        array_column($newestData['dialogues'],'dialogue_id')===['11']&&$newestData['has_more']
        &&array_column($newestTailData['dialogues'],'dialogue_id')===['10']&&!$newestTailData['has_more']
        &&$newestTailData['next_cursor']===null&&$newestData['coverage']['order']==='conversation_id_desc'
        &&json_decode($newestPacket['result']['content'][0]['text'],true)===$newestData);
    $newestContext=$nativeCall($native,$dialogueContext,'get_dialogue_review_context',[
        'dialogue_ref'=>$newestData['dialogues'][0]['dialogue_ref']]);
    dialogueMcpCheck('newest native reference reads its empty stored dialogue without writes',
        ($newestContext['result']['structuredContent']['ok']??false)===true
        &&$newestContext['result']['structuredContent']['messages']===[]
        &&(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$newestChanges
        &&dialogueMcpCredentialRows($pdo)===$newestCredentials);
    foreach([$contexts[$legacyScope],$contexts[$qualityScope]]as$withoutDialogue){
        $denied=$nativeCall($native,$withoutDialogue,'list_current_dialogues',['cursor'=>$newestStart]);
        dialogueMcpCheck('newest cursor requires dialogue consent and is never a history lane',
            str_contains(dialogueMcpChallenge($denied),'error="insufficient_scope"')
            &&str_contains(dialogueMcpChallenge($denied),$dialogueScope)
            &&!isset($denied['result']['structuredContent']['dialogues']));
    }
    foreach([$pairContext,$dialogueContext]as$cursorContext){
        $cursor=$cursorContext===$pairContext?$newestStart:substr($newestStart,0,-1).(str_ends_with($newestStart,'0')?'1':'0');
        $denied=$nativeCall($native,$cursorContext,'list_current_dialogues',['cursor'=>$cursor]);
        dialogueMcpCheck('newest native cursor rejects another connection and signature tampering',
            ($denied['result']['structuredContent']['reason']??null)==='stale_cursor'
            &&($denied['result']['structuredContent']['recovery']??null)==='restart_inventory'
            &&!isset($denied['result']['structuredContent']['dialogues']));
    }
    $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$dialogueContext['connection_id']]);
    $newestRevoked=$nativeCall($native,$dialogueContext,'list_current_dialogues',['cursor'=>$newestStart]);
    dialogueMcpCheck('newest native cursor is unusable after real grant revocation',
        ($newestRevoked['error']['code']??null)===-32001&&!isset($newestRevoked['result']));
    $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=NULL WHERE grant_id=?')->execute([$dialogueContext['connection_id']]);
    $ordinaryRef=$ordinaryInventory['result']['structuredContent']['dialogues'][0]['dialogue_ref'];
    $ordinaryPacket=$nativeCall($native,$dialogueContext,'get_dialogue_review_context',['dialogue_ref'=>$ordinaryRef]);
    dialogueMcpCheck('real dialogue-only grant keeps the previous fully redacted transcript',
        ($ordinaryPacket['result']['structuredContent']['ok']??false)===true
        &&!isset($ordinaryPacket['result']['structuredContent']['messages'][0]['selection_sources'])
        &&!str_contains(json_encode($ordinaryPacket),'qui-quo.ru'));
    $forgedContext=$dialogueContext;$forgedContext['scopes']=[$dialogueScope,ManagerPreparationOAuth::SELECTION_SCOPE];
    DialogueSelectionGrantStatement::$payloadRead=false;
    $forgedGrant=$nativeCall($native,$forgedContext,'get_dialogue_review_context',['dialogue_ref'=>$ordinaryRef]);
    dialogueMcpCheck('claimed paired flags without an actual stored grant cannot read a source row',
        ($forgedGrant['error']['code']??null)===-32001&&!DialogueSelectionGrantStatement::$payloadRead
        &&!str_contains(json_encode($forgedGrant),'qui-quo.ru'));
    foreach([$contexts[$legacyScope],$selectionContext]as$withoutDialogue){
        $denied=$nativeCall($native,$withoutDialogue,'get_dialogue_review_context',['dialogue_ref'=>$sourceRef]);
        dialogueMcpCheck('real grant without dialogue read cannot reveal a selection reference',
            (($denied['error']['code']??null)===-32001||($denied['result']['isError']??false)===true)
            &&!str_contains(json_encode($denied),'qui-quo.ru'));
    }
    $foreignReference=$nativeCall($native,$dialogueContext,'get_dialogue_review_context',['dialogue_ref'=>$sourceRef]);
    dialogueMcpCheck('public source reference cannot cross the consented connection cursor family',
        ($foreignReference['result']['isError']??false)===true&&!str_contains(json_encode($foreignReference),'qui-quo.ru'));
    DialogueSelectionGrantStatement::$payloadRead=false;
    $forgedOption=$nativeCall($native,$pairContext,'get_dialogue_review_context',
        ['dialogue_ref'=>$sourceRef,'include_selection_sources'=>true]);
    dialogueMcpCheck('model cannot select the extra capability or alter the unchanged tool input',
        ($forgedOption['error']['code']??null)===-32602&&!DialogueSelectionGrantStatement::$payloadRead);
    $pairFamily=$pairContext['connection_id'];
    $sourceWithdrawals=[
        ['selection_scope',static function()use($pdo,$pairFamily,$dialogueScope):void{
            $pdo->prepare('UPDATE manager_preparation_oauth_grants SET scopes=? WHERE grant_id=?')->execute([$dialogueScope,$pairFamily]);
        },static function()use($pdo,$pairFamily,$pairScope):void{
            $pdo->prepare('UPDATE manager_preparation_oauth_grants SET scopes=? WHERE grant_id=?')->execute([$pairScope,$pairFamily]);
        }],
        ['connection',static function()use($pdo,$pairFamily,$now):void{
            $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$pairFamily]);
        },static function()use($pdo,$pairFamily):void{
            $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=NULL WHERE grant_id=?')->execute([$pairFamily]);
        }],
        ['administrator',static function()use($pdo):void{$pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');},
            static function()use($pdo):void{$pdo->exec('UPDATE managers SET is_active=1 WHERE id=1');}],
        ['project',static function()use($pdo):void{$pdo->exec('UPDATE projects SET is_active=0 WHERE id=1');},
            static function()use($pdo):void{$pdo->exec('UPDATE projects SET is_active=1 WHERE id=1');}],
        ['membership',static function()use($pdo):void{$pdo->exec('DELETE FROM manager_projects WHERE manager_id=1 AND project_id=1');},
            static function()use($pdo):void{$pdo->exec('INSERT INTO manager_projects VALUES(1,1)');}],
        ['conversation',static function()use($pdo):void{$pdo->exec('UPDATE customer_channels SET customer_id=9999 WHERE id=10');},
            static function()use($pdo):void{$pdo->exec('UPDATE customer_channels SET customer_id=1010 WHERE id=10');}],
    ];
    foreach($sourceWithdrawals as[$label,$withdraw,$restore]){
        DialogueSelectionGrantStatement::$payloadRead=false;$withdrawn=false;
        DialogueSelectionGrantStatement::$withdrawAfterSnapshot=static function()use($withdraw,&$withdrawn):void{
            $withdraw();$withdrawn=true;
        };
        $denied=$nativeCall($native,$pairContext,'get_dialogue_review_context',['dialogue_ref'=>$sourceRef]);
        dialogueMcpCheck('late real authorization loss suppresses the entire source projection '.$label,
            $withdrawn&&DialogueSelectionGrantStatement::$payloadRead
            &&(($denied['error']['code']??null)===-32001||($denied['result']['isError']??false)===true)
            &&!str_contains(json_encode($denied),'qui-quo.ru')&&!$pdo->inTransaction());
        DialogueSelectionGrantStatement::$withdrawAfterSnapshot=null;
        $restore();
    }
    $nativeChanges=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
    $nativeCall($native,$pairContext,'get_dialogue_review_context',['dialogue_ref'=>$sourceRef]);
    dialogueMcpCheck('canonical paired-scope source read changes no source draft event or read marker rows',
        (int)$pdo->query('SELECT total_changes()')->fetchColumn()===$nativeChanges);
    echo 'Dialogue review MCP regression: '.$passed." checks passed\n";
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
