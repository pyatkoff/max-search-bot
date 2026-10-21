<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerPreparationMcpService.php';
require_once dirname(__DIR__).'/services/VirtualReplyControlProtocol.php';

$passed=0;
function controlMcpCheck(string $name,bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    ++$passed;echo 'PASS '.$name."\n";
}
function controlMcpReject(callable $call,string $class=InvalidArgumentException::class,?string $message=null): bool
{
    try {$call();return false;}
    catch (Throwable $error) {return $error instanceof $class&&($message===null||$error->getMessage()===$message);}
}
function controlMcpRpc(string $name,array $args): array
{
    return ['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]];
}
final class ControlMcpProbePDO extends PDO
{
    public int $controlProbes=0;
    public $controlHook=null;
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_contains($query,'virtual_reply_control_')) {
            $this->controlProbes++;
            if ($this->controlHook!==null) {$hook=$this->controlHook;$this->controlHook=null;$hook();}
        }
        return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);
    }
}

try {
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $ordinaryId=str_repeat('a',64);$authorId=str_repeat('c',64);$reviewerId=str_repeat('d',64);$token=str_repeat('b',64);$runId=str_repeat('e',64);
    $normalCalls=[];$controlCalls=[];$failure=null;
    $source=['messages'=>[['id'=>12,'sender_type'=>'tourist','text'=>'Египет, двое взрослых, на неделю.']],
        'history_incomplete'=>false,'cutoff'=>['source_id'=>12,'reference_time'=>'2026-10-21T19:00:00Z'],
        'historical_context_reconstructed'=>false,'revised_question'=>false];
    $answer=['ok'=>true,'should_draft'=>true,'draft'=>'Какой общий бюджет рассматриваете?',
        'next_action'=>'clarify','reason'=>'Бюджет не указан.','checks'=>[],
        'evidence_message_ids'=>[12],'history_incomplete'=>false];
    $alternative=$answer;$alternative['draft']='Подскажите бюджет на двоих.';
    $scores=['unnecessary_questions'=>0,'lost_constraints'=>0,'unsupported_claims'=>0,'useful_next_action'=>true];
    $blindReview=['ok'=>true,'assessable'=>true,'answers'=>['A'=>$scores,'B'=>$scores],
        'issues'=>[],'limitations'=>[],'preference'=>'tie'];
    $authorContract=VirtualReplyControlProtocol::authorContract('EXACT FROZEN SYNTHETIC AUTHOR INSTRUCTIONS');
    $candidateContract=VirtualReplyControlProtocol::authorContract('EXACT DISTINCT SYNTHETIC CANDIDATE INSTRUCTIONS');
    $reviewerContract=VirtualReplyControlProtocol::reviewerContract();
    $frozen=static function(string $role) use ($authorContract,$reviewerContract,$source,$answer,$alternative):array {
        $contract=$role==='author'?$authorContract:$reviewerContract;
        return ['instructions'=>$contract['instructions'],'result_schema'=>$contract['schema'],
            'payload'=>$role==='author'?$source:VirtualReplyControlProtocol::blindPayload($source,[12],$answer,$alternative),
            'evidence_message_ids'=>[12],'profile'=>$contract['profile']];
    };
    $normal=static function(string $operation,array $args) use (&$normalCalls,$ordinaryId,$token,$authorId,$reviewerId,$frozen):array {
        $normalCalls[]=[$operation,$args];
        if (in_array($operation,['review','disposition'],true)&&in_array($args[0],[$authorId,$reviewerId],true)) {
            throw new RuntimeException('virtual_reply_case_unavailable');
        }
        return match ($operation) {
            'capture'=>['captured'=>1],'reconcile'=>['checked'=>1],
            'claim'=>['jobs'=>[['case_id'=>$ordinaryId,'role'=>$args[0],'claim_token'=>$token]]],
            'reviews'=>['reviews'=>[],'has_more'=>false],
            'context'=>$frozen($args[1]),'save'=>['status'=>'completed'],
            'review'=>['case_id'=>$ordinaryId,'author_result'=>['ordinary'=>true]],
            'disposition'=>['case_id'=>$ordinaryId,'disposition'=>$args[1]],
            default=>throw new RuntimeException('unexpected_normal_operation'),
        };
    };
    $control=static function(string $operation,array $args) use (&$controlCalls,&$failure,$authorId,$reviewerId,$token,$frozen,$runId):mixed {
        $controlCalls[]=[$operation,$args];
        if ($failure===$operation) throw new RuntimeException('virtual_reply_control_claim_unavailable');
        return match ($operation) {
            'owns'=>in_array($args[0],[$authorId,$reviewerId],true),
            'register'=>['purpose'=>'control','run_id'=>$runId,'registered_cases'=>1,'requested_cases'=>$args[2]],
            'claim'=>['purpose'=>'control','jobs'=>[['case_id'=>$args[0]==='author'?$authorId:$reviewerId,
                'role'=>$args[0],'claim_token'=>$token]],'daily_limit'=>200,'daily_used'=>200],
            'context'=>$frozen($args[1]),'save'=>['purpose'=>'control','status'=>'completed','duplicate'=>false],
            'status'=>['purpose'=>'control','status'=>'enabled','results'=>['cases_total'=>2,
                'excluded'=>['unknown'=>1],'metrics'=>['useful_next_action'=>['paired_denominator'=>1]]]]
                +($args?['run_id'=>$args[0]]:[]),
            default=>throw new RuntimeException('unexpected_control_operation'),
        };
    };
    $adapter=new VirtualReplyMcpAdapter($pdo,$normal,null,$control);
    $tools=VirtualReplyMcpAdapter::tools();$byName=array_column($tools,null,'name');
    $previousNames=['capture_virtual_replies','claim_virtual_replies','get_virtual_reply_context','save_virtual_reply',
        'list_virtual_reply_reviews','get_virtual_reply_review','record_virtual_reply_lesson'];
    controlMcpCheck('explicit registration appends one tool while preserving the seven existing tool names',
        array_column($tools,'name')===VirtualReplyMcpAdapter::NAMES&&count($tools)===8
        &&array_slice(array_column($tools,'name'),0,7)===$previousNames
        &&$tools[7]['name']==='register_virtual_reply_control');
    foreach ($tools as $tool) {
        $schema=$tool['inputSchema'];$hasPurpose=in_array($tool['name'],['claim_virtual_replies','list_virtual_reply_reviews'],true);
        controlMcpCheck('strict control discovery boundary '.$tool['name'],$schema['additionalProperties']===false
            &&array_key_exists('purpose',$schema['properties'])===$hasPurpose
            &&!in_array('purpose',$schema['required'],true)&&!$tool['annotations']['openWorldHint']);
        if ($hasPurpose) controlMcpCheck('purpose is opt in '.$tool['name'],
            $schema['properties']['purpose']===['type'=>'string','enum'=>['learning','control'],'default'=>'learning']);
    }
    controlMcpCheck('claim credentials and opaque job identifier remain the existing API',
        $byName['get_virtual_reply_context']['inputSchema']['required']===['case_id','role','claim_token']
        &&array_keys($byName['get_virtual_reply_context']['inputSchema']['properties'])===['case_id','role','claim_token']
        &&array_keys($byName['save_virtual_reply']['inputSchema']['properties'])===['case_id','role','claim_token','result','metadata']);
    controlMcpCheck('aggregate run selection is optional and uses an opaque identifier',
        array_keys($byName['list_virtual_reply_reviews']['inputSchema']['properties'])===['limit','purpose','run_id']
        &&$byName['list_virtual_reply_reviews']['inputSchema']['properties']['run_id']===['type'=>'string','pattern'=>'^[a-f0-9]{64}$']
        &&!in_array('run_id',$byName['list_virtual_reply_reviews']['inputSchema']['required'],true));
    $registration=$byName['register_virtual_reply_control'];$registrationSchema=$registration['inputSchema'];
    controlMcpCheck('registration has a strict explicit two-contract input rather than an implicit model job',
        array_keys($registrationSchema['properties'])===['baseline_contract','candidate_contract','limit']
        &&$registrationSchema['required']===['baseline_contract','candidate_contract']
        &&$registrationSchema['additionalProperties']===false&&!$registration['annotations']['readOnlyHint']
        &&$registrationSchema['properties']['limit']===['type'=>'integer','minimum'=>1,'maximum'=>20]);
    foreach (['baseline_contract','candidate_contract'] as $key) {
        $schema=$registrationSchema['properties'][$key];
        controlMcpCheck('each frozen author contract has exactly five mandatory fields '.$key,
            $schema['type']==='object'&&$schema['additionalProperties']===false
            &&$schema['required']===['role','instructions','profile','schema','instruction_revision']
            &&array_keys($schema['properties'])===$schema['required']);
    }
    controlMcpCheck('registration does not add a token, private mapping or source-selection bypass',
        !str_contains(json_encode($tools),'order_json')&&!str_contains(json_encode($tools),'access_token')
        &&!array_key_exists('case_id',$registrationSchema['properties'])&&!array_key_exists('group_key',$registrationSchema['properties']));
    controlMcpCheck('discovery invokes neither private queue',$normalCalls===[]&&$controlCalls===[]);

    $adapter->call('capture_virtual_replies',['source'=>'telegram_archive','lane'=>'fresh','limit'=>2],17);
    controlMcpCheck('capture remains ordinary learning discovery and reconciliation',
        $normalCalls===[['capture',['telegram_archive','fresh',2]],['reconcile',[2]]]&&$controlCalls===[]);
    foreach ([[],['purpose'=>'learning']] as $optional) {
        $adapter->call('claim_virtual_replies',['role'=>'author','limit'=>2]+$optional,17);
        controlMcpCheck('normal claim does not consult or allocate control jobs '.json_encode($optional),
            end($normalCalls)===['claim',['author',17,2]]&&$controlCalls===[]);
        $adapter->call('list_virtual_reply_reviews',['limit'=>2]+$optional,17);
        controlMcpCheck('normal review metadata remains on its existing queue '.json_encode($optional),
            end($normalCalls)===['reviews',[2]]&&$controlCalls===[]);
    }
    $registrationArgs=['baseline_contract'=>$authorContract,'candidate_contract'=>$candidateContract,'limit'=>2];
    $before=count($normalCalls);
    $registered=$adapter->call('register_virtual_reply_control',$registrationArgs,17);
    controlMcpCheck('only explicit registration forwards both exact frozen contracts and authenticated admin',
        end($controlCalls)===['register',[$authorContract,$candidateContract,2,17]]&&count($normalCalls)===$before
        &&$registered['purpose']==='control'&&$registered['run_id']===$runId&&$registered['registered_cases']===1);
    controlMcpCheck('registration returns aggregate provenance without hidden source or arm content',
        !str_contains(json_encode($registered),'profile')&&!str_contains(json_encode($registered),'instructions')
        &&!str_contains(json_encode($registered),'payload')&&!str_contains(json_encode($registered),'claim_token')
        &&!str_contains(json_encode($registered),'order')&&!str_contains(json_encode($registered),'messages'));
    $adapter->call('register_virtual_reply_control',['baseline_contract'=>$authorContract,'candidate_contract'=>$candidateContract],17);
    controlMcpCheck('registration default stays bounded at twenty',end($controlCalls)===['register',[$authorContract,$candidateContract,20,17]]);
    $invalidRegistrations=[[],['baseline_contract'=>$authorContract],
        ['baseline_contract'=>null,'candidate_contract'=>$candidateContract],
        ['baseline_contract'=>[],'candidate_contract'=>$candidateContract],
        ['baseline_contract'=>'instructions','candidate_contract'=>$candidateContract],
        ['baseline_contract'=>$authorContract,'candidate_contract'=>null],
        ['baseline_contract'=>$authorContract,'candidate_contract'=>$authorContract],
        $registrationArgs+['project_key'=>'other'],$registrationArgs+['group_key'=>$ordinaryId],
        $registrationArgs+['claim_token'=>$token],$registrationArgs+['purpose'=>'control']];
    foreach (['baseline_contract','candidate_contract'] as $slot) {
        $original=$registrationArgs[$slot];
        $bad=$original;$bad['instruction_revision']=str_repeat('0',64);
        $invalidRegistrations[]=array_replace($registrationArgs,[$slot=>$bad]);
        $bad=$original;$bad['instructions'].=' Mutated after freezing.';
        $invalidRegistrations[]=array_replace($registrationArgs,[$slot=>$bad]);
        $bad=$original;$bad['role']='reviewer';
        $invalidRegistrations[]=array_replace($registrationArgs,[$slot=>$bad]);
        $bad=$original;$bad['profile']['model']='weaker-model';
        $bad['instruction_revision']=VirtualReplyControlProtocol::contractRevision('author',$bad['instructions'],$bad['schema'],$bad['profile']);
        $invalidRegistrations[]=array_replace($registrationArgs,[$slot=>$bad]);
        $bad=$original;$bad['schema']['additionalProperties']=true;
        $bad['instruction_revision']=VirtualReplyControlProtocol::contractRevision('author',$bad['instructions'],$bad['schema'],$bad['profile']);
        $invalidRegistrations[]=array_replace($registrationArgs,[$slot=>$bad]);
        $bad=$original;$bad['order']=['A'=>'baseline','B'=>'candidate'];
        $invalidRegistrations[]=array_replace($registrationArgs,[$slot=>$bad]);
        $bad=$original;unset($bad['schema']);
        $invalidRegistrations[]=array_replace($registrationArgs,[$slot=>$bad]);
    }
    foreach ([null,0,21,'1',true] as $badLimit) $invalidRegistrations[]=array_replace($registrationArgs,['limit'=>$badLimit]);
    $largeBaseline=VirtualReplyControlProtocol::authorContract(str_repeat('🌍',60000));
    $largeCandidate=VirtualReplyControlProtocol::authorContract(str_repeat('🌎',60000));
    controlMcpCheck('oversize registration fixture has individually valid frozen contracts',
        VirtualReplyControlProtocol::validateAuthorContract($largeBaseline)===$largeBaseline
        &&VirtualReplyControlProtocol::validateAuthorContract($largeCandidate)===$largeCandidate
        &&strlen(json_encode([$largeBaseline,$largeCandidate],JSON_THROW_ON_ERROR))>524288);
    $invalidRegistrations[]=['baseline_contract'=>$largeBaseline,'candidate_contract'=>$largeCandidate];
    foreach ($invalidRegistrations as $index=>$args) {
        $before=[count($normalCalls),count($controlCalls)];
        controlMcpCheck('invalid registration is rejected before source selection or persistence '.$index,
            controlMcpReject(fn()=>$adapter->call('register_virtual_reply_control',$args,17))
            &&[count($normalCalls),count($controlCalls)]===$before);
    }
    foreach (['author','reviewer'] as $role) {
        $before=count($normalCalls);
        $claimed=$adapter->call('claim_virtual_replies',['role'=>$role,'limit'=>2,'purpose'=>'control'],17);
        controlMcpCheck('explicit control claim preserves authenticated role and shared quota '.$role,
            end($controlCalls)===['claim',[$role,17,2]]&&count($normalCalls)===$before
            &&$claimed['purpose']==='control'&&$claimed['daily_used']===200&&$claimed['daily_limit']===200);
        $id=$role==='author'?$authorId:$reviewerId;$args=['case_id'=>$id,'role'=>$role,'claim_token'=>$token];
        $context=$adapter->call('get_virtual_reply_context',$args,17);
        controlMcpCheck('owned control context preserves all five frozen fields '.$role,
            array_slice($controlCalls,-2)===[['owns',[$id]],['context',[$id,$role,$token,17]]]
            &&count($normalCalls)===$before&&array_intersect_key($context,$frozen($role))===$frozen($role));
        if ($role==='reviewer') controlMcpCheck('review context exposes exact blind answers without a version mapping',
            $context['payload']===['context'=>$source,'answers'=>['A'=>$answer,'B'=>$alternative]]
            &&$context['result_schema']!==VirtualReplyProtocol::schema('reviewer')
            &&!isset($context['order'])&&!isset($context['instruction_revision'])&&!isset($context['baseline_revision']));
        $result=$role==='author'?array_reverse($answer,true):array_reverse($blindReview,true);
        $profile=VirtualReplyProtocol::profile($role);
        $declaration=['service_tier'=>'unknown','reasoning_effort'=>$profile['reasoning']['effort'],'model'=>$profile['model']];
        $saved=$adapter->call('save_virtual_reply',$args+['result'=>$result,'metadata'=>$declaration],17);
        controlMcpCheck('control save routes exact JSON and unknown tier without a single-answer reinterpretation '.$role,
            array_slice($controlCalls,-2)===[['owns',[$id]],['save',[$id,$role,$token,17,$result,$declaration]]]
            &&count($normalCalls)===$before&&$saved['status']==='completed');
    }
    $before=count($normalCalls);
    $status=$adapter->call('list_virtual_reply_reviews',['purpose'=>'control','limit'=>1],17);
    controlMcpCheck('control list returns aggregate status with unknown exclusions and paired denominators',
        end($controlCalls)===['status',[]]&&count($normalCalls)===$before
        &&$status['results']['excluded']['unknown']===1
        &&$status['results']['metrics']['useful_next_action']['paired_denominator']===1
        &&!str_contains(json_encode($status),'claim_token')&&!str_contains(json_encode($status),'payload')
        &&!str_contains(json_encode($status),'answers')&&!str_contains(json_encode($status),'order_json'));
    $report=$adapter->call('list_virtual_reply_reviews',['purpose'=>'control','run_id'=>$runId],17);
    controlMcpCheck('an explicit control run routes to only its aggregate report',
        end($controlCalls)===['status',[$runId]]&&count($normalCalls)===$before
        &&$report['run_id']===$runId&&$report['results']['metrics']['useful_next_action']['paired_denominator']===1);
    foreach ([[],['purpose'=>'learning']] as $optional) {
        $before=[count($normalCalls),count($controlCalls)];
        controlMcpCheck('run selection cannot read control data through normal learning '.json_encode($optional),
            controlMcpReject(fn()=>$adapter->call('list_virtual_reply_reviews',$optional+['run_id'=>$runId],17))
            &&[count($normalCalls),count($controlCalls)]===$before);
    }
    foreach ([null,'',str_repeat('e',63),str_repeat('E',64),12,[]] as $badRun) {
        $before=[count($normalCalls),count($controlCalls)];
        controlMcpCheck('malformed run identifier never reaches a store '.json_encode($badRun),
            controlMcpReject(fn()=>$adapter->call('list_virtual_reply_reviews',['purpose'=>'control','run_id'=>$badRun],17))
            &&[count($normalCalls),count($controlCalls)]===$before);
    }

    $ordinaryContext=$adapter->call('get_virtual_reply_context',['case_id'=>$ordinaryId,'role'=>'author','claim_token'=>$token],17);
    controlMcpCheck('an ordinary job still routes to its original frozen context after ownership lookup',
        end($controlCalls)===['owns',[$ordinaryId]]&&end($normalCalls)===['context',[$ordinaryId,'author',$token,17]]
        &&$ordinaryContext['payload']===$source);
    foreach (['owns','context','save'] as $operation) {
        $failure=$operation;$before=count($normalCalls);
        $args=['case_id'=>$authorId,'role'=>'author','claim_token'=>$token];
        $tool=$operation==='save'?'save_virtual_reply':'get_virtual_reply_context';
        if ($operation==='save') $args+=['result'=>$answer,'metadata'=>['model'=>'gpt-6.1-sol','reasoning_effort'=>'low','service_tier'=>'unknown']];
        controlMcpCheck('a failed or unknown owned control never falls back to ordinary work '.$operation,
            controlMcpReject(fn()=>$adapter->call($tool,$args,17),RuntimeException::class,'virtual_reply_control_claim_unavailable')
            &&count($normalCalls)===$before);
    }
    $failure=null;
    $decision=['status'=>'rejected','reason'=>'Синтетическая проверка границы.','change_url'=>null,'validation'=>'Контрольные пары не являются уроками обычного разбора.'];
    foreach (['get_virtual_reply_review'=>['case_id'=>$reviewerId],
        'record_virtual_reply_lesson'=>['case_id'=>$reviewerId,'decision'=>$decision]] as $name=>$args) {
        $before=count($controlCalls);
        controlMcpCheck('normal private exports and dispositions do not expose a control pair '.$name,
            controlMcpReject(fn()=>$adapter->call($name,$args,17),RuntimeException::class,'virtual_reply_case_unavailable')
            &&count($controlCalls)===$before);
    }
    foreach (['claim_virtual_replies'=>['role'=>'author'],'list_virtual_reply_reviews'=>[]] as $name=>$valid) {
        foreach ([null,'','CONTROL','review',0,true,[]] as $badPurpose) {
            $before=[count($normalCalls),count($controlCalls)];
            controlMcpCheck('malformed explicit purpose cannot select a default '.$name.'/'.json_encode($badPurpose),
                controlMcpReject(fn()=>$adapter->call($name,$valid+['purpose'=>$badPurpose],17))
                &&[count($normalCalls),count($controlCalls)]===$before);
        }
        foreach ([null,0,21,'1',true] as $badLimit) {
            $before=[count($normalCalls),count($controlCalls)];
            controlMcpCheck('control purpose retains the existing per-call bound '.$name.'/'.json_encode($badLimit),
                controlMcpReject(fn()=>$adapter->call($name,$valid+['purpose'=>'control','limit'=>$badLimit],17))
                &&[count($normalCalls),count($controlCalls)]===$before);
        }
    }
    $before=[count($normalCalls),count($controlCalls)];
    controlMcpCheck('an explicit null capture bound cannot become the default limit',
        controlMcpReject(fn()=>$adapter->call('capture_virtual_replies',['source'=>'conversation','lane'=>'fresh','limit'=>null],17))
        &&[count($normalCalls),count($controlCalls)]===$before);
    foreach (['get_virtual_reply_context'=>['case_id'=>$authorId,'role'=>'author','claim_token'=>$token],
        'get_virtual_reply_review'=>['case_id'=>$reviewerId],
        'record_virtual_reply_lesson'=>['case_id'=>$reviewerId,'decision'=>$decision]] as $name=>$valid) {
        $before=[count($normalCalls),count($controlCalls)];
        controlMcpCheck('purpose cannot widen an unrelated tool '.$name,
            controlMcpReject(fn()=>$adapter->call($name,$valid+['purpose'=>'control'],17))
            &&[count($normalCalls),count($controlCalls)]===$before);
    }
    $tooLarge=$blindReview;$tooLarge['limitations']=[str_repeat('x',32769)];
    $before=[count($normalCalls),count($controlCalls)];
    controlMcpCheck('oversized control JSON is rejected before either store',controlMcpReject(fn()=>$adapter->call('save_virtual_reply',[
        'case_id'=>$reviewerId,'role'=>'reviewer','claim_token'=>$token,'result'=>$tooLarge,
        'metadata'=>['model'=>'gpt-6-astra','reasoning_effort'=>'high','service_tier'=>'unknown']],17))
        &&[count($normalCalls),count($controlCalls)]===$before);

    // Exercise the real existing OAuth service rather than an adapter-only auth stub.
    $auth=new ControlMcpProbePDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$auth);
    (new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->setValue(null,false);
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic']]);
    $auth->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
        CREATE TABLE managers(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER);
        CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
        INSERT INTO projects VALUES(1,'anytour','Synthetic',1),(2,'other','Other',1);
        INSERT INTO managers VALUES(1,'admin',1),(2,'manager',1),(3,'admin',1),(4,'admin',1);
        INSERT INTO manager_projects VALUES(1,1),(2,1),(3,2),(4,1);
        CREATE TABLE messages(id INTEGER PRIMARY KEY,text TEXT);INSERT INTO messages VALUES(1,'Synthetic transcript');
        CREATE TABLE manager_prepared_replies(job_id TEXT PRIMARY KEY,status TEXT);INSERT INTO manager_prepared_replies VALUES('ordinary','pending');
        CREATE TABLE manager_preparation_events(subscription_id TEXT PRIMARY KEY,pending_body TEXT);INSERT INTO manager_preparation_events VALUES('subscription','ordinary event');");
    $connection=str_repeat('f',32);$connected=true;$serviceCalls=[];
    // Capture by reference: revocation must be visible to the service's final recheck.
    $active=static function(string $family,int $admin)use($connection,&$connected):bool{return $family===$connection&&$admin===1&&$connected;};
    $serviceStore=static function(string $op,array $args)use(&$serviceCalls):array{$serviceCalls[]=[$op,$args];return [];};
    $service=new ManagerPreparationMcpService($auth,$serviceStore,null,null,$active,$serviceStore);
    $call=static fn(string $name,array $args,int $admin=1,?string $family=null):array=>$service->handle(controlMcpRpc($name,$args),$admin,$family??$connection);
    $rowsBefore=[];foreach(['messages','manager_prepared_replies','manager_preparation_events']as$table)$rowsBefore[$table]=$auth->query('SELECT * FROM '.$table)->fetchAll();
    $changesBefore=(int)$auth->query('SELECT total_changes()')->fetchColumn();
    foreach (['claim_virtual_replies'=>['role'=>'author','purpose'=>'control'],
        'list_virtual_reply_reviews'=>['purpose'=>'control'],
        'register_virtual_reply_control'=>[]] as $name=>$args) {
        foreach ([0,2,3,4] as $admin) {
            $before=$auth->controlProbes;
            controlMcpCheck('control purpose requires the active AnyTour admin and bound OAuth '.$name.'/'.$admin,
                ($call($name,$args,$admin)['error']['code']??null)===-32001&&$auth->controlProbes===$before&&$serviceCalls===[]);
        }
        $before=$auth->controlProbes;
        $denied=$service->handle(controlMcpRpc($name,$args),1,null);
        controlMcpCheck('control purpose requires a live connection '.$name,($denied['error']['code']??null)===-32001&&$auth->controlProbes===$before);
        $connected=false;$denied=$call($name,$args);$connected=true;
        controlMcpCheck('revoked OAuth blocks control before its store '.$name,($denied['error']['code']??null)===-32001&&$auth->controlProbes===$before);
        ProjectConfig::resetForTests(['id'=>'other']);$denied=$call($name,$args);ProjectConfig::resetForTests(['id'=>'anytour']);
        controlMcpCheck('control purpose cannot cross the current project '.$name,($denied['error']['code']??null)===-32001&&$auth->controlProbes===$before);
    }
    $before=$auth->controlProbes;
    $available=$call('list_virtual_reply_reviews',['purpose'=>'control']);$data=$available['result']['structuredContent']??null;
    controlMcpCheck('authorized control status preserves explicit old-schema unavailability',
        $auth->controlProbes>$before&&$data===['ok'=>true,'project_key'=>'anytour','purpose'=>'control','status'=>'migration_unavailable']
        &&json_decode($available['result']['content'][0]['text'],true)===$data&&$serviceCalls===[]);
    $before=$auth->controlProbes;
    $invalid=$call('register_virtual_reply_control',[]);
    controlMcpCheck('authorized malformed registration is rejected before private storage',
        ($invalid['error']['code']??null)===-32602&&$auth->controlProbes===$before&&$serviceCalls===[]);
    $unavailable=$call('register_virtual_reply_control',$registrationArgs);
    controlMcpCheck('unavailable control registration never falls through to ordinary preparation',
        ($unavailable['error']['code']??null)===-32603&&!isset($unavailable['result'])
        &&!str_contains(json_encode($unavailable),'EXACT')&&!str_contains(json_encode($unavailable),'storage_unavailable')
        &&$auth->controlProbes>$before&&$serviceCalls===[]);
    $auth->controlHook=static function()use(&$connected):void{$connected=false;};
    $revoked=$call('list_virtual_reply_reviews',['purpose'=>'control']);$connected=true;
    controlMcpCheck('OAuth revoked during a control read withholds its complete result',
        ($revoked['error']['code']??null)===-32001&&!isset($revoked['result'])&&!str_contains(json_encode($revoked),'migration_unavailable'));
    foreach(['messages','manager_prepared_replies','manager_preparation_events']as$table)controlMcpCheck('control MCP paths preserve real state '.$table,
        $auth->query('SELECT * FROM '.$table)->fetchAll()===$rowsBefore[$table]);
    controlMcpCheck('control MCP does not write ordinary queues, transcripts or schema',
        (int)$auth->query('SELECT total_changes()')->fetchColumn()===$changesBefore&&$serviceCalls===[]);
    echo 'Virtual reply control MCP regression: '.$passed." checks passed\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage()."\n");exit(1);
}
