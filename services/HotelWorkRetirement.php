<?php
declare(strict_types=1);

/** Server-administrator retirement only. No MCP, timer, worker or data acceptance.
 * The trusted caller authenticates the current project administrator on this PDO.
 * External/native/Library isolation and backup are explicit operator attestations.
 */
final class HotelWorkRetirement
{
    public const TABLES=['hotel_work_queues','hotel_work_receipts','hotel_work_events','hotel_work_connection_handoffs','hotel_work_resolutions','hotel_work_delivery_recoveries','hotel_work_research_skips','hotel_work_consumer_delivery_repairs','hotel_work_execution_attempts','hotel_work_execution_attempt_entries','hotel_work_execution_attempt_associations'];
    public function __construct(private PDO $p,private int $admin,private $authorize,private $clock=null,private $fault=null)
    {if($admin<1||!is_callable($authorize)||!in_array($p->getAttribute(PDO::ATTR_DRIVER_NAME),['mysql','sqlite'],true))throw new InvalidArgumentException('retirement_context');}
    public function preflight(string $queue,string $owner):array
    {
        return$this->tx(function()use($queue,$owner):array{
            [$q,$lane]=$this->owned($queue,$owner);$this->barrier();$old=$this->one('SELECT receipt_bytes FROM hotel_work_retirements WHERE queue_id=?'.$this->lock(),[$queue]);
            $snapshot=$this->snapshot($q);return['queue_id'=>$queue,'owner_connection_id'=>$owner,'state'=>$q['state'],'stage_index'=>(int)$q['stage_index'],'accepted_batches'=>(int)$q['accepted_batches'],
                'expected_state_sha256'=>hash('sha256',self::json($snapshot)),'eligible'=>$old===null&&$lane['queue_id']===$queue&&in_array($q['state'],['ready','claimed','unknown'],true),
                'already_retired'=>$old!==null,'receipt_count'=>count($snapshot['hotel_work_receipts']),'backup_or_external_isolation_verified'=>false,'dispatch_authority'=>false];
        });
    }
    public function retire(string $queue,string $owner,string $expected,string $request,array $backup,array $isolation):array
    {
        foreach([$expected,$backup['sha256']??null,$isolation['evidence_sha256']??null]as$sha)self::sha($sha);
        if(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D',$request)||($backup['verified_by_operator']??null)!==true||!is_string($backup['reference']??null)||strlen($backup['reference'])<1||strlen($backup['reference'])>2048)throw new InvalidArgumentException('retirement_backup_required');
        foreach(['native_writers_stopped_or_fenced','library_writers_stopped_or_fenced','pending_callbacks_fenced']as$k)if(($isolation[$k]??null)!==true)throw new DomainException('retirement_external_isolation_required');
        $input=hash('sha256',self::json([$queue,$owner,$expected,$request,$backup,$isolation]));
        return$this->tx(function()use($queue,$owner,$expected,$request,$backup,$isolation,$input):array{
            [$q,$lane]=$this->owned($queue,$owner);$this->barrier();$old=$this->one('SELECT * FROM hotel_work_retirements WHERE queue_id=?'.$this->lock(),[$queue]);
            if($old){if($old['request_sha256']!==$input||$q['state']!=='retired')throw new DomainException('retirement_replay_conflict');return json_decode($old['receipt_bytes'],true,32,JSON_THROW_ON_ERROR);}
            if($lane['queue_id']!==$queue||!in_array($q['state'],['ready','claimed','unknown'],true))throw new DomainException('retirement_current_lane_required');
            $snapshot=$this->snapshot($q);
            if(hash('sha256',self::json($snapshot))!==$expected)throw new DomainException('retirement_CAS_conflict');
            $now=gmdate('Y-m-d H:i:s',$this->clock===null?time():($this->clock)());
            // No stage/claim/run/receipt/counter rewrite; UNKNOWN gets no credit.
            $this->exec("UPDATE hotel_work_queues SET state='retired',updated_at=? WHERE queue_id=?",[$now,$queue]);
            $this->exec('UPDATE hotel_work_events SET is_active=0,single_consumer_key=NULL,pending_event_id=NULL,pending_body=NULL,lease_token=NULL,lease_until=NULL,updated_at=? WHERE project_key=? AND queue_id=?',[$now,'anytour',$queue]);
            $this->exec('UPDATE hotel_work_lane SET queue_id=NULL WHERE lane_key=? AND queue_id=?',['HOTEL',$queue]);
            if($this->fault!==null)($this->fault)('retirement_before_fence');
            $receipt=['queue_id'=>$queue,'state'=>'retired','original_state'=>$q['state'],'original_stage_index'=>(int)$q['stage_index'],'original_operation_outcome'=>in_array($q['state'],['claimed','unknown'],true)?'UNKNOWN':'not_started',
                'accepted_batches'=>(int)$q['accepted_batches'],'acceptance_credit'=>0,'claim_preserved'=>$q['claim_sha256']!==null,'original_run_id'=>$q['current_run_id'],'snapshot_sha256'=>$expected,
                'backup_sha256'=>$backup['sha256'],'isolation_sha256'=>$isolation['evidence_sha256'],'evidence_kind'=>'administrator_attested_external_backup_and_writer_isolation',
                'backup_bytes_verified_by_service'=>false,'native_terminal_verified_by_service'=>false,'lane_released'=>true,'new_queue_created'=>false,'dispatch_authority'=>false];
            $bytes=self::json($receipt);if(strlen($bytes)>4096)throw new DomainException('retirement_receipt_limit');
            $this->exec('INSERT INTO hotel_work_retirements(queue_id,admin_id,owner_connection_id,request_sha256,original_state_sha256,backup_sha256,isolation_sha256,snapshot_bytes,receipt_bytes,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
                [$queue,$this->admin,$owner,$input,$expected,$backup['sha256'],$isolation['evidence_sha256'],self::json($snapshot),$bytes,$now]);
            if($this->fault!==null)($this->fault)('retirement_fence_written');
            return$receipt;
        });
    }
    private function owned(string $queue,string $owner):array
    {
        if(!preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D',$queue)||!preg_match('/\A[a-f0-9]{32}\z/D',$owner))throw new DomainException('retirement_admin_required');
        $lane=$this->one('SELECT * FROM hotel_work_lane WHERE lane_key=?'.$this->lock(),['HOTEL']);
        $q=$this->one('SELECT * FROM hotel_work_queues WHERE queue_id=?'.$this->lock(),[$queue]);
        if(($this->authorize)($this->p,$this->admin)!==true)throw new DomainException('retirement_admin_required');
        if(!$q||!$lane||$q['project_key']!=='anytour'||(int)$q['admin_id']!==$this->admin||$q['oauth_connection_id']!==$owner)throw new DomainException('retirement_owner_CAS');
        return[$q,$lane];
    }
    private function snapshot(array $q):array
    {
        $out=['hotel_work_queues'=>[$q]];
        foreach(array_slice(self::TABLES,1)as$table){
            $columns=$table==='hotel_work_events'?'subscription_id,admin_id,oauth_connection_id,project_key,queue_id,event_name,arguments_json,is_active,verified_at,expires_at':'*';
            $s=$this->p->prepare('SELECT '.$columns.' FROM '.$table.' WHERE queue_id=?'.$this->lock());$s->execute([$q['queue_id']]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
            usort($rows,static fn(array$a,array$b):int=>strcmp(self::json($a),self::json($b)));$out[$table]=$rows;
        }
        if(strlen(self::json($out))>4194304)throw new DomainException('retirement_snapshot_limit');return$out;
    }
    private function barrier():void
    {
        $names=[];foreach(self::TABLES as$i=>$t)foreach(['insert','update','delete']as$op)$names[]='hotel_retired_'.$i.'_'.$op;
        $names=[...$names,'hotel_retired_lane_insert','hotel_retired_lane_update','hotel_retirement_immutable_update','hotel_retirement_immutable_delete'];
        $sql=$this->p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'SELECT TRIGGER_NAME,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()':'SELECT name,sql FROM sqlite_master WHERE type=\'trigger\'';
        $definitions=$this->p->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach($names as$name)if(!is_string($definitions[$name]??null)||!str_contains(strtolower($definitions[$name]),str_starts_with($name,'hotel_retirement_immutable')?'hotel_retirement_gate':'hotel_work_retirements'))throw new DomainException('retirement_database_barrier_required');
        if((int)$this->p->query('SELECT COUNT(*) FROM hotel_retirement_gate WHERE guard_id IN(1,2)')->fetchColumn()!==2)throw new DomainException('retirement_database_barrier_required');
    }
    private function tx(callable $f):array
    {
        if($this->p->inTransaction())throw new LogicException('retirement_foreign_transaction');$this->p->beginTransaction();
        try{if($this->p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$this->exec('UPDATE hotel_work_lane SET queue_id=queue_id WHERE lane_key=?',['HOTEL']);$r=$f();$this->p->commit();return$r;}
        catch(Throwable$e){if($this->p->inTransaction())$this->p->rollBack();throw$e;}
    }
    private function lock():string{return$this->p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    private function one(string$s,array$a):?array{$q=$this->p->prepare($s);$q->execute($a);return$q->fetch(PDO::FETCH_ASSOC)?:null;}
    private function exec(string$s,array$a):void{$q=$this->p->prepare($s);$q->execute($a);}
    private static function sha(mixed$s):void{if(!is_string($s)||!preg_match('/\A[a-f0-9]{64}\z/D',$s))throw new InvalidArgumentException('retirement_sha');}
    private static function json(mixed$v):string{$sort=function(mixed$x)use(&$sort):mixed{if(!is_array($x))return$x;if(!array_is_list($x))ksort($x,SORT_STRING);foreach($x as$k=>$z)$x[$k]=$sort($z);return$x;};return json_encode($sort($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}

