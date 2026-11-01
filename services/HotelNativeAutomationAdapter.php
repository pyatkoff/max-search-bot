<?php
declare(strict_types=1);
require_once __DIR__.'/HotelCollectionDispatchAdapter.php';
require_once __DIR__.'/HotelCollectionCanonicalService.php';
require_once __DIR__.'/HotelWorkEventService.php';

/** Concrete native transport: the existing MCP webhook -> Automation trigger.
 * No run_now HTTP endpoint, worker creation, timer, resume or automatic retry.
 * Installing a subscription proves callback possession, not its native task ID.
 * A separate trusted host binding is therefore required before sending.
 */
final class HotelNativeAutomationAdapter implements HotelCollectionDispatchAdapter
{
    private ManagerPreparationWebhook $webhook;
    public function __construct(private PDO $pdo,private int $admin,private string $connection,
        ?ManagerPreparationWebhook $webhook=null,private $clock=null)
    {$this->webhook=$webhook??new ManagerPreparationWebhook();}

    /** Server-only approval of exact host readback, never called by MCP/cron.
     * Evidence must establish trigger -> subscription -> existing task, not just
     * repeat desired filters. Secrets/URLs are deliberately absent from evidence.
     */
    public function bind(string $queue,string $role,string $task,string $subscription,array $evidence):array
    {
        $this->autocommit();self::roleEvent($role);self::task($task);
        $keys=['task_id','subscription_id','trigger_id','event','project_key','queue_id','connection_id','evidence_sha256'];
        if(count($evidence)!==count($keys)||array_diff(array_keys($evidence),$keys)
            ||$evidence['task_id']!==$task||$evidence['subscription_id']!==$subscription
            ||$evidence['event']!==self::roleEvent($role)||$evidence['project_key']!=='anytour'
            ||$evidence['queue_id']!==$queue||$evidence['connection_id']!==$this->connection
            ||!is_string($evidence['trigger_id'])||preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$evidence['trigger_id'])!==1
            ||!is_string($evidence['evidence_sha256'])||preg_match('/\A[a-f0-9]{64}\z/D',$evidence['evidence_sha256'])!==1)
            throw new InvalidArgumentException('native_host_binding_evidence_required');
        // Current owner/OAuth, without claiming or changing the business queue.
        HotelWorkQueueFactory::create($this->pdo,$this->clock)->status($this->admin,$this->connection,$queue);
        $this->authorize();$s=$this->subscription($queue,$role,$task);
        if($s['subscription_id']!==$subscription)throw new DomainException('native_subscription_mismatch');
        $values=[$queue,$role,$task,$this->admin,$this->connection,$subscription,self::bindingHash($s),$evidence['trigger_id'],$evidence['evidence_sha256']];
        $this->pdo->beginTransaction();
        try{
            $old=$this->one('SELECT * FROM hotel_native_bindings WHERE queue_id=? AND role=?',[$queue,$role]);
            if($old){
                foreach(array_combine(['queue_id','role','task_id','admin_id','connection_id','subscription_id','binding_sha256','host_trigger_id','host_evidence_sha256'],$values)as$key=>$value)
                    if((string)$old[$key]!== (string)$value)throw new DomainException('native_binding_replacement_requires_reconciliation');
            }else $this->exec('INSERT INTO hotel_native_bindings(queue_id,role,task_id,admin_id,connection_id,subscription_id,binding_sha256,host_trigger_id,host_evidence_sha256) VALUES(?,?,?,?,?,?,?,?,?)',$values);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return ['binding_recorded'=>true,'role'=>$role,'task_id'=>$task,'subscription_id'=>$subscription,'native_run_verified'=>false];
    }

    public function assertReady(array $request):void{$this->route($request);}

    public function __invoke(array $request):array
    {
        $s=$this->route($request,true);
        // One irreversible attempt per stage/generation/role, even if somebody
        // changes operation/request ID. The database unique key wins the race.
        $id=hash('sha256',json_encode([$request['queue_id'],$request['stage_id'],$request['generation'],$request['role']],JSON_THROW_ON_ERROR));
        $body=json_encode(['eventId'=>'hotel_native_'.$id,'name'=>self::roleEvent($request['role']),
            'timestamp'=>gmdate('Y-m-d\TH:i:s\Z',$this->now()),'data'=>['project_key'=>'anytour']+$request,'cursor'=>null],JSON_THROW_ON_ERROR);
        if(strlen($body)>2048)throw new InvalidArgumentException('native_event_too_large');
        $input=$request;ksort($input);$sha=hash('sha256',json_encode($input,JSON_THROW_ON_ERROR));
        try{
            $this->exec('INSERT INTO hotel_native_deliveries(event_id,queue_id,stage_id,generation,role,task_id,subscription_id,binding_sha256,request_sha256,state,http_status) VALUES(?,?,?,?,?,?,?,?,?,?,NULL)',
                [$id,$request['queue_id'],$request['stage_id'],$request['generation'],$request['role'],$request['task_id'],$s['subscription_id'],self::bindingHash($s),$sha,'UNKNOWN']);
        }catch(PDOException $e){
            $old=$this->one('SELECT * FROM hotel_native_deliveries WHERE event_id=?',[$id]);
            if(!$old)throw $e;
            if(!hash_equals($old['request_sha256'],$sha))throw new DomainException('native_attempt_identity_conflict');
            return self::receipt($old['state'],$old['http_status'],true);
        }
        // Crash or timeout after the durable UNKNOWN never refunds authority.
        // A final fresh route/auth check happens outside SQL, before the socket.
        $fresh=$this->route($request,true);
        if($fresh['subscription_id']!==$s['subscription_id']||!hash_equals(self::bindingHash($s),self::bindingHash($fresh)))throw new DomainException('native_binding_changed');
        $response=$this->webhook->request($s['callback_url'],$s['signing_secret'],'hotel_native_'.$id,$s['subscription_id'],$body);
        $status=$response['status'];$state=$status>=200&&$status<300?'callback_acknowledged':'UNKNOWN';
        $this->exec('UPDATE hotel_native_deliveries SET state=?,http_status=? WHERE event_id=? AND state=?',[$state,$status,$id,'UNKNOWN']);
        return self::receipt($state,$status,false);
    }

    private function route(array $a,bool $sending=false):array
    {
        $this->autocommit();
        (new HotelCollectionCanonicalService($this->pdo,$this->admin,$this->connection,$this->clock))->transportIdentity($a,$sending);
        self::task($a['task_id']);$s=$this->subscription($a['queue_id'],$a['role'],$a['task_id']);
        $b=$this->one('SELECT * FROM hotel_native_bindings WHERE queue_id=? AND role=?',[$a['queue_id'],$a['role']]);
        if(!$b||(int)$b['admin_id']!==$this->admin||$b['connection_id']!==$this->connection||$b['task_id']!==$a['task_id']
            ||$b['subscription_id']!==$s['subscription_id']||!hash_equals($b['binding_sha256'],self::bindingHash($s)))
            throw new DomainException('native_host_binding_required');
        return $s;
    }

    private function subscription(string $queue,string $role,string $task):array
    {
        $q=$this->pdo->prepare('SELECT * FROM hotel_work_events WHERE project_key=? AND queue_id=? AND admin_id=? AND oauth_connection_id=? AND event_name=? AND is_active=1 AND expires_at>? LIMIT 2');
        $q->execute(['anytour',$queue,$this->admin,$this->connection,self::roleEvent($role),gmdate('Y-m-d H:i:s',$this->now())]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==1)throw new DomainException('native_subscription_absent_or_ambiguous');
        $s=$rows[0];$args=json_decode($s['arguments_json'],true,8,JSON_THROW_ON_ERROR);
        if($args!==['project_key'=>'anytour','queue_id'=>$queue,'task_id'=>$task]
            ||!is_string($s['verified_at'])||strtotime($s['verified_at'].' UTC')===false||strtotime($s['verified_at'].' UTC')>$this->now()
            ||ManagerPreparationWebhook::secretBytes($s['signing_secret'])===null||$this->webhook->destination($s['callback_url'])===null)
            throw new DomainException('native_subscription_binding_invalid');
        return $s;
    }

    private function authorize():void
    {
        if(!(new ManagerPreparationOAuth($this->pdo,$this->clock))->eventConnectionAuthorizedAcrossProfiles('hotels',$this->connection,$this->admin))throw new DomainException('native_access_denied');
    }
    private static function bindingHash(array $s):string
    {return hash('sha256',json_encode([$s['subscription_id'],$s['admin_id'],$s['oauth_connection_id'],$s['event_name'],$s['arguments_json'],$s['callback_url'],$s['signing_secret']],JSON_THROW_ON_ERROR));}
    private static function roleEvent(string $role):string
    {return match($role){'producer'=>HotelWorkEventService::WORKER_EVENTS[0],'reviewer'=>HotelWorkEventService::WORKER_EVENTS[1],default=>throw new InvalidArgumentException('native_role_invalid')};}
    private static function task(string $task):void
    {if(preg_match('/\A[a-f0-9]{32}\z/D',$task)!==1)throw new InvalidArgumentException('native_existing_task_id_required');}
    private static function receipt(string $state,mixed $status,bool $replay):array
    {return ['state'=>$state,'http_status'=>$status===null?null:(int)$status,'replay'=>$replay,'dispatch_authority'=>false,'native_run_verified'=>false];}
    private function autocommit():void{if($this->pdo->inTransaction())throw new LogicException('native_foreign_transaction');}
    private function now():int{return $this->clock!==null?(int)($this->clock)():time();}
    private function one(string $sql,array $a):?array{$q=$this->pdo->prepare($sql);$q->execute($a);return $q->fetch(PDO::FETCH_ASSOC)?:null;}
    private function exec(string $sql,array $a):void{$q=$this->pdo->prepare($sql);$q->execute($a);}
}
