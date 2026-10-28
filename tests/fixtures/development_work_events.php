<?php
declare(strict_types=1);

require_once __DIR__.'/development_work_queue.php';

/** Real isolated SQL state; only callback transport/DNS and grant authority are fakes. */
final class DevelopmentEventsTestStatement extends PDOStatement
{
    protected function __construct(private DevelopmentEventsTestPdo $owner) {}
    public function execute(?array $params=null): bool
    {
        $this->owner->observe($this->queryString);$ok=parent::execute($params);
        if ($ok&&preg_match('/\A\s*SELECT\b.*\bFROM\s+development_work_events\b/is',$this->queryString)===1&&$this->owner->afterEventRead!==null) {
            $hook=$this->owner->afterEventRead;$this->owner->afterEventRead=null;$hook();
        }
        if ($ok&&$this->rowCount()===1&&str_contains($this->queryString,'UPDATE development_work_events SET lease_token=')
            &&$this->owner->afterLease!==null) {
            $hook=$this->owner->afterLease;$this->owner->afterLease=null;$hook();
        }
        return $ok;
    }
}

final class DevelopmentEventsTestPdo extends PDO
{
    public array $writes=[];
    public $afterLease=null;
    public array $eventReads=[];
    public $afterEventRead=null;
    public bool $failEventRead=false;
    public function __construct()
    {
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>false];
        if (getenv('DEVELOPMENT_WORK_EVENTS_NATIVE_MYSQL')==='1') {
            $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
            if (!str_starts_with($dsn,'mysql:')||preg_match('/(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$dsn,$m)!==1) throw new RuntimeException('unsafe_development_event_fixture_database');
            $options[PDO::ATTR_EMULATE_PREPARES]=false;
            parent::__construct($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,$options);
            if (parent::query('SELECT DATABASE()')->fetchColumn()!==$m[1]) throw new RuntimeException('unsafe_development_event_fixture_database');
        } else {
            parent::__construct('sqlite::memory:',null,null,$options);parent::exec('PRAGMA busy_timeout=5000');
        }
        DevelopmentWorkTestSchema::create($this);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[DevelopmentEventsTestStatement::class,[$this]]);
    }
    public function observe(string $sql): void
    {
        if (preg_match('/\A\s*SELECT\b.*\bFROM\s+development_work_events\b/is',$sql)===1) {
            $this->eventReads[]=$sql;if ($this->failEventRead) throw new PDOException('PRIVATE_SETUP_SQL_FAILURE');
        }
        if (preg_match('/\A\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+([a-z_]+)/i',$sql,$m)) {
            $table=strtolower($m[1]);
            if (!in_array($table,DevelopmentWorkTestSchema::tables(),true)&&$table!=='development_work_events') throw new RuntimeException('development_event_fixture_rejected_business_write');
            $this->writes[]=$table;
        }
    }
}

final class DevelopmentEventsFixture
{
    public DevelopmentEventsTestPdo $pdo;
    public DevelopmentWorkQueue $queue;
    public DevelopmentWorkEventService $events;
    public ManagerPreparationWebhook $webhook;
    public int $at=1793100000;
    public array $requests=[];
    public array $statuses=[];
    public array $grants=[];
    public int $authCalls=0;
    public $verifyHook=null;
    public $authorizeHook=null;
    public bool $challengeGood=true;
    public string $connection;
    public string $secret;

    public function __construct(bool $create=true,array $tasks=[])
    {
        $this->connection=str_repeat('a',32);$this->secret='whsec_'.base64_encode(str_repeat('k',32));
        ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic project']]);
        $this->pdo=new DevelopmentEventsTestPdo();$clock=fn():int=>$this->at;
        $this->queue=new DevelopmentWorkQueue($this->pdo,$clock);
        if ($create) $this->queue->create(1,$this->connection,['queue_id'=>'PILOT','plan_sha256'=>str_repeat('0',64),
            'tasks'=>$tasks?:[self::task('TASK1')]]);
        $this->grants=[$this->connection=>['admin'=>1,'active'=>true,'scopes'=>['development_work:write']],
            str_repeat('b',32)=>['admin'=>1,'active'=>true,'scopes'=>['queue:read','prepared:write']],
            str_repeat('c',32)=>['admin'=>1,'active'=>true,'scopes'=>['hotel_work:write']],
            str_repeat('f',32)=>['admin'=>2,'active'=>true,'scopes'=>['development_work:write']]];
        $transport=function(string $url,array $headers,string $body,array $destination):array {
            $decoded=json_decode($body,true,16,JSON_THROW_ON_ERROR);$map=[];
            foreach ($headers as $header) {[$key,$value]=explode(': ',$header,2);$map[strtolower($key)]=$value;}
            $this->requests[]=['url'=>$url,'headers'=>$map,'body'=>$body,'data'=>$decoded,'destination'=>$destination];
            if (($decoded['type']??null)==='verification') {
                if ($this->verifyHook!==null) {$hook=$this->verifyHook;$this->verifyHook=null;$hook();}
                return ['status'=>200,'body'=>json_encode(['challenge'=>$this->challengeGood?$decoded['challenge']:'wrong'],JSON_THROW_ON_ERROR)];
            }
            return ['status'=>array_shift($this->statuses)??204,'body'=>''];
        };
        $this->webhook=new ManagerPreparationWebhook($transport,static fn(string $host):array=>['8.8.8.8'],$clock);
        $authorized=function(string $connection,int $admin,array $required):bool {
            ++$this->authCalls;if ($this->authorizeHook!==null) ($this->authorizeHook)();
            $grant=$this->grants[$connection]??null;
            return $required===['development_work:write']&&$grant!==null&&$grant['active']&&$grant['admin']===$admin&&!array_diff($required,$grant['scopes']);
        };
        $this->events=new DevelopmentWorkEventService($this->pdo,$this->webhook,$clock,$authorized,$this->queue);
    }
    public static function task(string $id,array $deps=[],int $priority=50): array
    {
        return ['task_id'=>$id,'owner_task_id'=>'OWNER_'.$id,'reviewer_task_id'=>'REVIEW_'.$id,
            'input_sha256'=>str_repeat('1',64),'acceptance_contract_sha256'=>str_repeat('2',64),'deps'=>$deps,'priority'=>$priority];
    }
    public function params(string $name='development_work.pending',string $url='https://callbacks.example/development',?string $secret=null): array
    {
        return ['name'=>$name,'arguments'=>['project_key'=>'anytour','queue_id'=>'PILOT'],
            'delivery'=>['mode'=>'webhook','url'=>$url,'secret'=>$secret??$this->secret],'ttlMs'=>86400000];
    }
    public function subscribe(bool $attention=true): void
    {
        $this->events->subscribe($this->params(),1,$this->connection);
        if ($attention) $this->events->subscribe($this->params('development_work.attention'),1,$this->connection);
        $this->requests=[];$this->authCalls=0;
    }
    public function row(string $name='development_work.pending'): array
    {
        $q=$this->pdo->prepare('SELECT * FROM development_work_events WHERE event_name=? AND oauth_connection_id=?');
        $q->execute([$name,$this->connection]);return $q->fetch(PDO::FETCH_ASSOC);
    }
    public function status(): array {return $this->queue->status(1,$this->connection,'PILOT');}
    public function claim(array $request): array
    {
        $d=$request['data']['data'];
        $args=['queue_id'=>$d['queue_id'],'task_id'=>$d['task_id'],'spec_sha256'=>$d['spec_sha256'],
            'claim_key'=>str_repeat('d',64),'expected_pending_event_id'=>$request['data']['eventId']];
        $this->queue->claim(1,$this->connection,$args);unset($args['expected_pending_event_id']);return $args;
    }
    public function accept(array $args): void
    {
        $this->queue->started(1,$this->connection,$args+['run_id'=>'RUN1']);
        $this->queue->complete(1,$this->connection,$args+['receipt'=>['run_id'=>'RUN1','outcome'=>'accepted_readback',
            'result_sha256'=>str_repeat('3',64),'readback_sha256'=>str_repeat('4',64),'acceptance_sha256'=>str_repeat('5',64),
            'reviewer_run_id'=>'REVIEW_RUN1','owned_claims_released'=>true]]);
    }
}

/** Run once from the shared events runner on SQLite and native MySQL. */
function developmentSubscriptionSetupCases(): void
{
    $pending='development_work.pending';$attention='development_work.attention';$sequence=0;
    $epoch=static function($value) {return is_string($value)?strtotime(preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D',$value)?$value:$value.' UTC'):false;};
    $insert=static function(DevelopmentEventsFixture $f,string $event,array $changes=[])use(&$sequence):string {
        $id='dev_setup_'.hash('sha256',(string)++$sequence);$now=gmdate('Y-m-d H:i:s',$f->at);
        $row=['subscription_id'=>$id,'single_consumer_key'=>null,'admin_id'=>1,'oauth_connection_id'=>$f->connection,'project_key'=>'anytour','queue_id'=>'PILOT',
            'event_name'=>$event,'arguments_json'=>'{"project_key":"anytour","queue_id":"PILOT"}','callback_url'=>'https://private.example/PRIVATE_SETUP_URL',
            'signing_secret'=>'PRIVATE_SETUP_SECRET','previous_secret'=>'PRIVATE_SETUP_PREVIOUS','verified_at'=>gmdate('Y-m-d H:i:s',$f->at-60),
            'expires_at'=>gmdate('Y-m-d H:i:s',$f->at+3600),'is_active'=>1,'pending_body'=>'PRIVATE_SETUP_BODY','last_status'=>204,'next_attempt_at'=>null,
            'created_at'=>$now,'updated_at'=>$now];
        $row=array_replace($row,$changes);
        $f->pdo->prepare('INSERT INTO development_work_events ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
        return $id;
    };
    $read=static function(DevelopmentEventsFixture $f)use($pending,$attention,$epoch):array {
        $writes=$f->pdo->writes;$f->pdo->eventReads=[];
        $dto=$f->events->subscriptionSetup(1,$f->connection,'PILOT');
        developmentEventAssert($writes===$f->pdo->writes&&$f->requests===[],'setup_is_read_only_and_makes_zero_callback_requests');
        developmentEventAssert(($dto['semantics']??null)==='server_subscription_metadata_not_native_commit_proof'
            &&$epoch($dto['observed_at']??null)===$f->at&&array_column($dto['events']??[],'event_name')===[$pending,$attention],
            'setup_reports_observation_time_semantics_and_canonical_event_order');
        developmentEventAssert(count($f->pdo->eventReads)===1,'setup_uses_one_bounded_subscription_select');
        $sql=$f->pdo->eventReads[0];$projection=[];preg_match('/\ASELECT\s+(.+?)\s+FROM\s+development_work_events\b/is',trim($sql),$projection);
        developmentEventAssert(isset($projection[1])&&!str_contains($projection[1],'*')
            &&preg_match('/\b(callback_url|signing_secret|previous_secret|subscription_id|admin_id|oauth_connection_id|project_key|queue_id|arguments_json|pending_body|pending_event_id|lease_token)\b/i',$projection[1])===0
            &&preg_match('/\bLIMIT\s+21\s*;?\s*\z/i',trim($sql))===1,'setup_selects_no_private_columns_and_has_hard_row_cap');
        $wire=json_encode($dto,JSON_THROW_ON_ERROR);
        foreach (['PRIVATE_SETUP','dev_setup_','private.example',$f->connection,'PILOT'] as $private) {
            developmentEventAssert(!str_contains($wire,$private),'setup_exports_no_callback_secret_id_or_body');
        }
        return $dto;
    };
    $unknown=static function(array $dto,string $reason)use($pending,$attention):void {
        developmentEventAssert(($dto['available']??null)===false&&($dto['reason']??null)===$reason,'setup_technical_failure_is_bounded_and_explicit');
        foreach ($dto['events'] as $event) developmentEventAssert($event['state']==='unknown'&&$event['matching_rows']===null
            &&$event['eligible']===null&&$event['verified']===null&&$event['retrying']===null&&$event['last_http_status']===null&&$event['expires_at']===null,
            'setup_unknown_never_invents_subscription_flags');
    };
    $f=new DevelopmentEventsFixture();$dto=$read($f);
    developmentEventAssert($dto['available']===true,'setup_empty_inventory_is_available');
    foreach ($dto['events'] as $event) developmentEventAssert($event['state']==='absent'&&$event['matching_rows']===0&&$event['eligible']===false
        &&$event['verified']===null&&$event['retrying']===null&&$event['last_http_status']===null&&$event['expires_at']===null,'setup_absence_is_not_verification_or_retry_proof');

    $f=new DevelopmentEventsFixture();
    foreach ([['oauth_connection_id'=>str_repeat('e',32)],['admin_id'=>2],['project_key'=>'foreign'],['queue_id'=>'OTHER'],['event_name'=>'other.event']] as $foreign) {
        for ($i=0;$i<21;$i++) $insert($f,$pending,$foreign);
    }
    $insert($f,$pending);$insert($f,$attention);$dto=$read($f);
    developmentEventAssert($dto['available']===true,'setup_filters_all_foreign_rows_before_the_limit');
    foreach ($dto['events'] as $event) developmentEventAssert($event['state']==='active_verified'&&$event['matching_rows']===1&&$event['eligible']===true
        &&$event['verified']===true&&$event['retrying']===false&&$event['last_http_status']===204&&$epoch($event['expires_at'])===$f->at+3600,
        'setup_keeps_pending_and_attention_verified_rows_separate');
    foreach ([
        ['inactive',['is_active'=>0,'next_attempt_at'=>gmdate('Y-m-d H:i:s',$f->at+60)],'inactive',false,true,false,204],
        ['expired',['expires_at'=>gmdate('Y-m-d H:i:s',$f->at-1)],'expired',false,true,false,204],
        ['retry_zero',['last_status'=>0,'next_attempt_at'=>gmdate('Y-m-d H:i:s',$f->at+60)],'active_verified',true,true,true,null],
        ['future_verification',['verified_at'=>gmdate('Y-m-d H:i:s',$f->at+60)],'unknown',null,null,null,null],
        ['invalid_http',['last_status'=>999],'unknown',null,null,null,null],
        ['malformed',['is_active'=>2],'unknown',null,null,null,null]
    ] as [$name,$changes,$state,$eligible,$verified,$retrying,$last]) {
        $f=new DevelopmentEventsFixture();$insert($f,$pending,$changes);$dto=$read($f);$event=$dto['events'][0];
        developmentEventAssert($dto['available']===true&&$event['state']===$state&&$event['matching_rows']===1
            &&$event['eligible']===$eligible&&$event['verified']===$verified&&$event['retrying']===$retrying&&$event['last_http_status']===$last,
            'setup_normalizes_one_row_'.$name);
        if (in_array($name,['malformed','future_verification','invalid_http'],true)) developmentEventAssert($event['expires_at']===null,'setup_malformed_row_has_no_technical_expiry_proof');
    }
    $f=new DevelopmentEventsFixture();$insert($f,$pending);$insert($f,$pending,['is_active'=>0,'last_status'=>503]);$insert($f,$attention);$dto=$read($f);
    $event=$dto['events'][0];
    developmentEventAssert($dto['available']===true&&$event['state']==='ambiguous'&&$event['matching_rows']===2&&$event['eligible']===null
        &&$event['verified']===null&&$event['retrying']===null&&$event['last_http_status']===null&&$event['expires_at']===null,
        'setup_duplicate_rows_are_ambiguous_without_mixing_flags');
    developmentEventAssert($dto['events'][1]['state']==='active_verified'&&$dto['events'][1]['matching_rows']===1,'setup_ambiguity_does_not_mix_event_names');
    $f=new DevelopmentEventsFixture();for ($i=0;$i<21;$i++) $insert($f,$pending);$unknown($read($f),'row_limit');
    $f=new DevelopmentEventsFixture();$insert($f,$pending);$f->pdo->failEventRead=true;$unknown($read($f),'unavailable');

    $f=new DevelopmentEventsFixture();$foreign=str_repeat('e',32);$f->grants[$foreign]=['admin'=>1,'active'=>true,'scopes'=>['development_work:write']];
    $f->pdo->eventReads=[];$writes=$f->pdo->writes;
    developmentEventReject(fn()=>$f->events->subscriptionSetup(1,$foreign,'PILOT'),DomainException::class,'setup_rejects_real_foreign_owned_queue');
    developmentEventAssert($f->pdo->eventReads===[]&&$writes===$f->pdo->writes&&$f->requests===[],'setup_owned_queue_guard_precedes_subscription_read');
    $f=new DevelopmentEventsFixture();$f->authorizeHook=function()use($f):void {$f->grants[$f->connection]['active']=false;};
    developmentEventReject(fn()=>$f->events->subscriptionSetup(1,$f->connection,'PILOT'),DomainException::class,'setup_checks_live_scope_before_read');
    developmentEventAssert($f->pdo->eventReads===[]&&$f->requests===[],'setup_pre_read_revocation_has_no_subscription_read_or_http');
    $f=new DevelopmentEventsFixture();$insert($f,$pending);$writes=$f->pdo->writes;
    $f->pdo->afterEventRead=function()use($f):void {$f->grants[$f->connection]['active']=false;};
    developmentEventReject(fn()=>$f->events->subscriptionSetup(1,$f->connection,'PILOT'),DomainException::class,'setup_rechecks_scope_after_actual_subscription_read');
    developmentEventAssert(count($f->pdo->eventReads)===1&&$writes===$f->pdo->writes&&$f->requests===[],'setup_post_read_revocation_exposes_no_dto_or_side_effect');
    $f=new DevelopmentEventsFixture();$f->pdo->eventReads=[];$writes=$f->pdo->writes;
    $f->pdo->beginTransaction();
    try {
        developmentEventReject(fn()=>$f->events->subscriptionSetup(1,$f->connection,'PILOT'),LogicException::class,'setup_rejects_foreign_transaction_snapshot');
        developmentEventAssert($f->pdo->eventReads===[]&&$writes===$f->pdo->writes&&$f->requests===[],'setup_foreign_transaction_reads_no_subscription_or_callback');
    } finally {if ($f->pdo->inTransaction()) $f->pdo->rollBack();}
}
