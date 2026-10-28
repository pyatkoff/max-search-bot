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
