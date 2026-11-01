<?php
declare(strict_types=1);

/** Exact SQL-session permits inside a caller-owned lane/queue transaction.
 * A sticky queue flag survives protocol changes. Never a worker/MCP API.
 */
final class HotelQueueWriteFence
{
    public const FIELDS=['queue_id','project_key','admin_id','oauth_connection_id','mode','batch_limit','start_country','initial_phase_counter','phase_counter','baseline_sha256','plan_sha256','stage_index','state','claim_sha256','current_run_id','accepted_batches','write_protocol','data_snapshot_sha256','persistent_fence'];
    private static ?WeakMap $sessions=null;
    private string $session;
    public function __construct(private PDO $p)
    {
        self::$sessions??=new WeakMap();
        $this->session=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?(string)$p->query('SELECT CONNECTION_ID()')->fetchColumn():(self::$sessions[$p]??=bin2hex(random_bytes(16)));
        if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$p->sqliteCreateFunction('hotel_queue_session',fn():string=>$this->session,0);
    }
    public static function hash(array $q):string
    {
        $v=[];foreach(self::FIELDS as$k)$v[]=array_key_exists($k,$q)&&$q[$k]!==null?(string)$q[$k]:'~';
        return hash('sha256',implode(chr(31),$v));
    }
    public function update(array $old,array $changes):void
    {
        $this->transaction();
        foreach(array_keys($changes)as$k)if(!in_array($k,self::FIELDS,true))throw new LogicException('fence_update_field');
        $next=array_replace($old,$changes);
        $permit=(int)($old['persistent_fence']??0)===1||(int)($next['persistent_fence']??0)===1;
        if($permit)$this->exec('INSERT INTO hotel_queue_write_permits(queue_id,session_id,old_sha256,new_sha256) VALUES(?,?,?,?)',[$old['queue_id'],$this->session,self::hash($old),self::hash($next)]);
        try{
            $set=implode(',',array_map(static fn(string$k):string=>$k.'=?',array_keys($changes)));
            $this->exec('UPDATE hotel_work_queues SET '.$set.' WHERE queue_id=?',[...array_values($changes),$old['queue_id']]);
        }finally{if($permit)$this->exec('DELETE FROM hotel_queue_write_permits WHERE queue_id=? AND session_id=?',[$old['queue_id'],$this->session]);}
    }
    public function receipt(array $q,string $stage,string $bytes,string $at):void
    {
        $this->transaction();$sha=hash('sha256',$bytes);$permit=(int)($q['persistent_fence']??0)===1;
        if($permit)$this->exec('INSERT INTO hotel_queue_write_permits(queue_id,session_id,receipt_stage,receipt_index,receipt_sha256) VALUES(?,?,?,?,?)',[$q['queue_id'],$this->session,$stage,(int)$q['stage_index'],$sha]);
        try{$this->exec('INSERT INTO hotel_work_receipts(queue_id,stage_index,stage_id,receipt_sha256,receipt_json,created_at) VALUES(?,?,?,?,?,?)',[$q['queue_id'],$q['stage_index'],$stage,$sha,$bytes,$at]);}
        finally{if($permit)$this->exec('DELETE FROM hotel_queue_write_permits WHERE queue_id=? AND session_id=?',[$q['queue_id'],$this->session]);}
    }
    private function transaction():void{if(!$this->p->inTransaction())throw new LogicException('fence_requires_transaction');}
    private function exec(string$s,array$a):void{$q=$this->p->prepare($s);$q->execute($a);}
}

