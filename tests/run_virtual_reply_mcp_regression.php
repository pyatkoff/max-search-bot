<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerPreparationMcpService.php';

$passed=0;
function virtualMcpCheck(string $name,bool $ok): void {
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    ++$passed; echo 'PASS '.$name."\n";
}
function virtualMcpRpc(string $name,array $args=[]): array {
    return ['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]];
}

try {
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    (new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->setValue(null,false);
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic']]);
    $pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
        CREATE TABLE managers(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER);
        CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
        INSERT INTO projects VALUES(1,'anytour','Synthetic',1),(2,'other','Other',1);
        INSERT INTO managers VALUES(1,'admin',1),(2,'manager',1),(3,'admin',1),(4,'admin',1);
        INSERT INTO manager_projects VALUES(1,1),(2,1),(3,2),(4,1);
        CREATE TABLE messages(id INTEGER PRIMARY KEY,text TEXT);
        INSERT INTO messages VALUES(1,'Synthetic real transcript');
        CREATE TABLE manager_prepared_replies(job_id TEXT PRIMARY KEY,status TEXT);
        INSERT INTO manager_prepared_replies VALUES('ordinary-job','pending');
        CREATE TABLE manager_preparation_events(subscription_id TEXT PRIMARY KEY,pending_body TEXT);
        INSERT INTO manager_preparation_events VALUES('ordinary-subscription','ordinary-event');");
    $case=str_repeat('a',64); $token=str_repeat('b',64); $connection=str_repeat('c',32);
    $connectionValid=true; $calls=[]; $ordinaryCalls=[]; $hook=null; $responseOverride=null;
    $author=['ok'=>true,'should_draft'=>true,'draft'=>'Какой возраст ребёнка на дату поездки?',
        'next_action'=>'clarify','reason'=>'Для возрастного тарифа','checks'=>[],
        'evidence_message_ids'=>[1],'history_incomplete'=>false];
    $review=['ok'=>true,'verdict'=>'pass','issues'=>[],'corrected_draft'=>null,'lesson_candidate'=>null];
    $store=static function(string $op,array $args) use (&$calls,&$hook,&$responseOverride,$case,$token,$author,$review) {
        $calls[]=[$op,$args];
        if ($hook!==null) { $run=$hook; $hook=null; $run(); }
        if ($responseOverride!==null) return $responseOverride;
        return match ($op) {
            'capture'=>['captured'=>2,'last_id'=>20],
            'reconcile'=>['checked'=>2],
            'claim'=>['jobs'=>[['case_id'=>$case,'claim_token'=>$token,'role'=>$args[0]]]],
            'context'=>['case_id'=>$case,'payload'=>['messages'=>[['id'=>1,'sender_type'=>'tourist','text'=>'Synthetic question']]],
                'instructions'=>VirtualReplyProtocol::instructions($args[1]),'profile'=>VirtualReplyProtocol::profile($args[1]),
                'evidence_message_ids'=>[1],'author_result'=>$args[1]==='reviewer'?$author:null],
            'save'=>['case_id'=>$case,'saved'=>true],
            'reviews'=>['reviews'=>[['case_id'=>$case,'source_kind'=>'conversation','source_id'=>1,'verdict'=>'pass']],'has_more'=>false],
            'review'=>['case_id'=>$case,'payload'=>['messages'=>[['id'=>1,'sender_type'=>'tourist','text'=>'Synthetic question']]],
                'author_result'=>$author,'review_result'=>$review],
            'disposition'=>['case_id'=>$case,'disposition'=>$args[1]],
            default=>throw new RuntimeException('PRIVATE unexpected operation'),
        };
    };
    $ordinary=static function(string $op,array $args) use (&$ordinaryCalls): array { $ordinaryCalls[]=[$op,$args]; return []; };
    $active=static function(string $family,int $admin) use (&$connectionValid,$connection): bool { return $connectionValid && $family===$connection && $admin===1; };
    $service=new ManagerPreparationMcpService($pdo,$ordinary,null,null,$active,$store);
    $invoke=static fn(string $name,array $args=[],int $admin=1,?string $family=null): array => $service->handle(virtualMcpRpc($name,$args),$admin,$family??$connection);
    $claimArgs=['case_id'=>$case,'role'=>'author','claim_token'=>$token];
    $metadata=static function(string $role): array {
        $profile=VirtualReplyProtocol::profile($role);
        return ['model'=>$profile['model'],'reasoning_effort'=>$profile['reasoning']['effort'],'service_tier'=>$profile['service_tier']];
    };

    $listed=$service->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'],1,$connection)['result']['tools'];
    $virtual=array_values(array_filter($listed,static fn(array $tool): bool=>in_array($tool['name'],VirtualReplyMcpAdapter::NAMES,true)));
    virtualMcpCheck('seven learning tools and explicit control registration remain in the exact discovery inventory',
        array_column($virtual,'name')===VirtualReplyMcpAdapter::NAMES
        &&array_column($listed,'name')===array_merge(['list_pending_replies','get_reply_context','save_prepared_reply',ManagerSearch3CandidateReader::TOOL,ManagerSearch3CandidateReader::MEAL_TOOL],VirtualReplyMcpAdapter::NAMES,HotelWorkMcpAdapter::NAMES,DevelopmentWorkMcpAdapter::NAMES));
    foreach ($virtual as $tool) {
        virtualMcpCheck('strict scoped schema '.$tool['name'],$tool['inputSchema']['additionalProperties']===false
            && $tool['securitySchemes']===[['type'=>'oauth2','scopes'=>['queue:read','prepared:write']]]
            && $tool['_meta']['securitySchemes']===$tool['securitySchemes'] && !$tool['annotations']['openWorldHint']);
    }
    virtualMcpCheck('save metadata schema requires only the actual profile declarations',
        $virtual[3]['inputSchema']['properties']['metadata']['required']===['model','reasoning_effort','service_tier']
        && $virtual[3]['inputSchema']['properties']['metadata']['additionalProperties']===false);
    virtualMcpCheck('lesson disposition has a strict role-independent evidence schema',
        $virtual[6]['inputSchema']['required']===['case_id','decision']
        && $virtual[6]['inputSchema']['properties']['decision']['required']===['status','reason','change_url','validation']
        && $virtual[6]['inputSchema']['properties']['decision']['properties']['status']['enum']===['rejected','duplicate','applied']
        && $virtual[6]['inputSchema']['properties']['decision']['additionalProperties']===false
        && !$virtual[6]['annotations']['readOnlyHint']);
    virtualMcpCheck('discovery never touches either job store',$calls===[] && $ordinaryCalls===[]);
    $realBefore=$pdo->query('SELECT * FROM messages')->fetchAll();
    $preparedBefore=$pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll();
    $eventBefore=$pdo->query('SELECT * FROM manager_preparation_events')->fetchAll();
    $changesBefore=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
    $capture=$invoke('capture_virtual_replies',['source'=>'telegram_archive','lane'=>'history','limit'=>2]);
    virtualMcpCheck('capture uses separate source and reconciliation operations',($capture['result']['structuredContent']['capture']['captured']??null)===2
        && array_slice($calls,-2)===[['capture',['telegram_archive','history',2]],['reconcile',[2]]]);
    $invoke('capture_virtual_replies',['source'=>'conversation','lane'=>'fresh']);
    virtualMcpCheck('capture default stays bounded to twenty',array_slice($calls,-2)===[['capture',['conversation','fresh',20]],['reconcile',[20]]]);
    foreach (['author','reviewer'] as $role) {
        $claimed=$invoke('claim_virtual_replies',['role'=>$role,'limit'=>1]);
        virtualMcpCheck('claim is bound to authenticated admin '.$role,($claimed['result']['structuredContent']['jobs'][0]['role']??null)===$role && end($calls)===['claim',[$role,1,1]]);
        $args=['case_id'=>$case,'role'=>$role,'claim_token'=>$token];
        $context=$invoke('get_virtual_reply_context',$args)['result'];
        virtualMcpCheck('claimed context uses exact role token and admin '.$role,end($calls)===['context',[$case,$role,$token,1]]
            && $context['structuredContent']['profile']===VirtualReplyProtocol::profile($role)
            && count($context['content'])===1 && $context['content'][0]['type']==='text');
        if ($role==='reviewer') virtualMcpCheck('reviewer context contains exact saved author answer',$context['structuredContent']['author_result']===$author);
        $result=$role==='author'?$author:$review;
        $declaration=$metadata($role);
        $saved=$invoke('save_virtual_reply',$args+['result'=>$result,'metadata'=>$declaration]);
        virtualMcpCheck('save preserves exact output and actual profile '.$role,($saved['result']['structuredContent']['saved']??false)
            && end($calls)===['save',[$case,$role,$token,1,$result,$declaration]]);
    }
    $reordered=['service_tier'=>'default','model'=>'gpt-6-astra','reasoning_effort'=>'high'];
    $saved=$invoke('save_virtual_reply',['case_id'=>$case,'role'=>'reviewer','claim_token'=>$token,'result'=>$review,'metadata'=>$reordered]);
    virtualMcpCheck('JSON metadata key order does not alter declared reviewer profile',($saved['result']['structuredContent']['saved']??false)===true);
    $unexposed=['model'=>'gpt-6-astra','reasoning_effort'=>'high','service_tier'=>'unknown'];
    $saved=$invoke('save_virtual_reply',['case_id'=>$case,'role'=>'reviewer','claim_token'=>$token,'result'=>$review,'metadata'=>$unexposed]);
    virtualMcpCheck('ChatGPT can honestly record an unexposed service tier',($saved['result']['structuredContent']['saved']??false)===true
        &&end($calls)===['save',[$case,'reviewer',$token,1,$review,$unexposed]]);
    $reviews=$invoke('list_virtual_reply_reviews',['limit'=>2])['result'];
    virtualMcpCheck('review list contains metadata without transcript or claim material',end($calls)===['reviews',[2]]
        && !str_contains(json_encode($reviews),'Synthetic question') && !str_contains(json_encode($reviews),'claim_token') && !str_contains(json_encode($reviews),'source_scope'));
    $export=$invoke('get_virtual_reply_review',['case_id'=>$case])['result'];
    virtualMcpCheck('review export preserves exact saved pair in both JSON representations',end($calls)===['review',[$case]]
        && $export['structuredContent']['author_result']===$author && $export['structuredContent']['review_result']===$review
        && json_decode($export['content'][0]['text'],true)===$export['structuredContent']);
    $decision=['status'=>'rejected','reason'=>'Проверенный контекст не подтверждает вывод проверяющего.',
        'change_url'=>null,'validation'=>'Исходный вопрос и сохранённый ответ сверены с замечанием.'];
    foreach (['rejected','duplicate','applied'] as $status) {
        $evidence=array_replace($decision,['status'=>$status,
            'change_url'=>$status==='applied'?'https://github.com/pyatkoff/max-search-bot/pull/123':null,
            'validation'=>$status==='applied'?'Исправление проверено на отдельных, ранее не разобранных случаях с другими датами и составами.':$decision['validation']]);
        $recorded=$invoke('record_virtual_reply_lesson',['case_id'=>$case,'decision'=>$evidence]);
        virtualMcpCheck('lesson decision preserves evidence and authenticated admin '.$status,
            ($recorded['result']['structuredContent']['disposition']??null)===$evidence
            && end($calls)===['disposition',[$case,$evidence,1]]);
    }
    virtualMcpCheck('virtual tools never call ordinary preparation or modify real state',$ordinaryCalls===[]
        && $pdo->query('SELECT * FROM messages')->fetchAll()===$realBefore
        && $pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll()===$preparedBefore
        && $pdo->query('SELECT * FROM manager_preparation_events')->fetchAll()===$eventBefore
        && (int)$pdo->query('SELECT total_changes()')->fetchColumn()===$changesBefore);

    $valid=['capture_virtual_replies'=>['source'=>'conversation','lane'=>'fresh'],
        'claim_virtual_replies'=>['role'=>'author'],'get_virtual_reply_context'=>$claimArgs,
        'save_virtual_reply'=>$claimArgs+['result'=>$author,'metadata'=>$metadata('author')],
        'list_virtual_reply_reviews'=>[],'get_virtual_reply_review'=>['case_id'=>$case],
        'record_virtual_reply_lesson'=>['case_id'=>$case,'decision'=>$decision],
        'register_virtual_reply_control'=>[]]; // Access is checked before even malformed registration input.
    foreach ($valid as $name=>$args) {
        foreach ([0,2,3] as $admin) {
            $before=count($calls); $denied=$invoke($name,$args,$admin);
            virtualMcpCheck('active project admin required '.$name.'/'.$admin,($denied['error']['code']??null)===-32001 && count($calls)===$before);
        }
        $before=count($calls); $denied=$service->handle(virtualMcpRpc($name,$args),1,null);
        virtualMcpCheck('live OAuth connection required '.$name,($denied['error']['code']??null)===-32001 && count($calls)===$before);
        $before=count($calls); $connectionValid=false; $denied=$invoke($name,$args); $connectionValid=true;
        virtualMcpCheck('revoked connection blocks before store '.$name,($denied['error']['code']??null)===-32001 && count($calls)===$before);
    }
    ProjectConfig::resetForTests(['id'=>'other']); $before=count($calls);
    virtualMcpCheck('wrong current project cannot invoke virtual store',($invoke('get_virtual_reply_review',['case_id'=>$case])['error']['code']??null)===-32001 && count($calls)===$before);
    ProjectConfig::resetForTests(['id'=>'anytour']);
    $before=count($calls);
    virtualMcpCheck('another valid admin cannot borrow this OAuth connection',($invoke('get_virtual_reply_review',['case_id'=>$case],4)['error']['code']??null)===-32001 && count($calls)===$before);

    $invalid=[
        ['capture_virtual_replies',[]],['capture_virtual_replies',['source'=>'other','lane'=>'fresh']],
        ['capture_virtual_replies',['source'=>'conversation','lane'=>'other']],
        ['claim_virtual_replies',[]],['claim_virtual_replies',['role'=>'system']],
        ['get_virtual_reply_context',['case_id'=>$case,'role'=>'author']],
        ['get_virtual_reply_context',$claimArgs+['source_scope'=>'PRIVATE']],
        ['get_virtual_reply_review',['case_id'=>strtoupper($case)]],['get_virtual_reply_review',['case_id'=>str_repeat('a',63)]],
        ['get_virtual_reply_review',['case_id'=>1]],['get_virtual_reply_review',['case_id'=>$case,'project_key'=>'other']],
        ['save_virtual_reply',$claimArgs+['result'=>[],'metadata'=>$metadata('author')]],
        ['save_virtual_reply',$claimArgs+['result'=>['text'=>str_repeat('x',32769)],'metadata'=>$metadata('author')]],
        ['record_virtual_reply_lesson',['case_id'=>$case]],
        ['record_virtual_reply_lesson',['case_id'=>str_repeat('a',63),'decision'=>$decision]],
        ['record_virtual_reply_lesson',['case_id'=>$case,'decision'=>$decision,'role'=>'author']],
    ];
    foreach (['capture_virtual_replies','claim_virtual_replies','list_virtual_reply_reviews'] as $name) {
        foreach ([0,21,'1',true] as $limit) $invalid[]=[$name,array_replace($valid[$name],['limit'=>$limit])];
    }
    foreach ([[],['model'=>'gpt-6-astra','reasoning_effort'=>'low','service_tier'=>'default'],
        ['model'=>'gpt-6.1-sol','reasoning_effort'=>'high','service_tier'=>'default'],
        ['model'=>'gpt-6.1-sol','reasoning_effort'=>'low','service_tier'=>'priority'],
        ['model'=>'gpt-6.1-sol','reasoning_effort'=>'low','service_tier'=>'default','verified'=>true]] as $badMetadata) {
        $invalid[]=['save_virtual_reply',$claimArgs+['result'=>$author,'metadata'=>$badMetadata]];
    }
    $badDecisions=[null,[],['status'=>'rejected'],array_replace($decision,['status'=>'candidate']),
        array_replace($decision,['status'=>true]),array_replace($decision,['reason'=>' ']),
        array_replace($decision,['reason'=>str_repeat('Я',1001)]),array_replace($decision,['reason'=>"bad\x00text"]),
        array_replace($decision,['validation'=>'']),array_replace($decision,['validation'=>str_repeat('Я',2001)]),
        array_replace($decision,['validation'=>false]),$decision+['approved'=>true],
        array_replace($decision,['status'=>'applied','change_url'=>null]),
        array_replace($decision,['change_url'=>'https://github.com/pyatkoff/max-search-bot/pull/123']),
    ];
    $missingChange=$decision; unset($missingChange['change_url']); $badDecisions[]=$missingChange;
    foreach (['http://github.com/pyatkoff/max-search-bot/pull/123',
        'https://github.com/pyatkoff/other/pull/123','https://github.com/pyatkoff/max-search-bot/issues/123',
        'https://github.com/pyatkoff/max-search-bot/pull/0','https://github.com/pyatkoff/max-search-bot/pull/123#comment',
        'https://github.com/pyatkoff/max-search-bot/pull/123?approved=true',123] as $url) {
        $badDecisions[]=array_replace($decision,['status'=>'applied','change_url'=>$url]);
    }
    foreach ($badDecisions as $badDecision) $invalid[]=['record_virtual_reply_lesson',['case_id'=>$case,'decision'=>$badDecision]];
    foreach ($invalid as $index=>[$name,$args]) {
        $before=count($calls); $error=$invoke($name,$args);
        virtualMcpCheck('invalid input rejected before adapter '.$index,($error['error']['code']??null)===-32602 && count($calls)===$before);
    }

    foreach (['admin','project','connection','current_project'] as $revocation) {
        $hook=static function() use ($revocation,$pdo,&$connectionValid): void {
            if ($revocation==='admin') $pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');
            if ($revocation==='project') $pdo->exec('DELETE FROM manager_projects WHERE manager_id=1');
            if ($revocation==='connection') $connectionValid=false;
            if ($revocation==='current_project') ProjectConfig::resetForTests(['id'=>'other']);
        };
        $denied=$invoke('get_virtual_reply_review',['case_id'=>$case]);
        virtualMcpCheck('authorization revoked during private read is withheld '.$revocation,($denied['error']['code']??null)===-32001
            && !isset($denied['result']) && !str_contains(json_encode($denied),'Synthetic question'));
        $pdo->exec('UPDATE managers SET is_active=1 WHERE id=1');
        $pdo->exec('INSERT INTO manager_projects SELECT 1,1 WHERE NOT EXISTS (SELECT 1 FROM manager_projects WHERE manager_id=1 AND project_id=1)');
        $connectionValid=true; ProjectConfig::resetForTests(['id'=>'anytour']);
    }
    $hook=static function(): void { throw new DomainException('PRIVATE unavailable source'); };
    $unavailable=$invoke('get_virtual_reply_review',['case_id'=>$case]);
    virtualMcpCheck('source permission failure cannot expose context or error detail',($unavailable['error']['code']??null)===-32001 && !str_contains(json_encode($unavailable),'PRIVATE'));
    $responseOverride='PRIVATE invalid response';
    $badResponse=$invoke('get_virtual_reply_review',['case_id'=>$case]);
    virtualMcpCheck('malformed adapter output stays a bounded internal error',($badResponse['error']['code']??null)===-32603 && !str_contains(json_encode($badResponse),'PRIVATE'));
    $responseOverride=null;
    virtualMcpCheck('all isolated learning paths preserve ordinary event data',$pdo->query('SELECT * FROM manager_preparation_events')->fetchAll()===$eventBefore && $ordinaryCalls===[]);
    echo 'Virtual reply MCP regression: '.$passed." checks passed\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage()."\n"); exit(1);
}
