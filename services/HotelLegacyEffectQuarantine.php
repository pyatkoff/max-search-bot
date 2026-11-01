<?php
declare(strict_types=1);
require_once __DIR__.'/HotelNativeRecoveryContext.php';
require_once __DIR__.'/HotelCollectionCanonicalDocument.php';

/** Explicit internal preservation barrier. NEVER terminates a native run or
 * fences Library/host APIs. Keeps the same claim and freezes all server business
 * mutations; a later separate adoption is required before any new work.
 */
final class HotelLegacyEffectQuarantine
{
    public const PROTOCOL='quarantine_v1';
    public function __construct(private HotelNativeRecoveryContext $c,private $fault=null){}
    public function preflight(string $queue):array
    {
        return$this->tx(function()use($queue):array{
            $q=$this->c->owned($queue,true);$old=$this->c->one('SELECT stage_id,working_sha256,packet_sha256 FROM hotel_legacy_effect_quarantine WHERE queue_id=?',[$queue]);
            return ['queue_id'=>$queue,'stage_id'=>self::stage($q),'state'=>$q['state'],'accepted_batches'=>(int)$q['accepted_batches'],
                'eligible'=>$old===null&&self::eligible($q)&&$this->triggersReady(),'expected_queue_sha256'=>self::businessHash($q),
                'already_quarantined'=>$old!==null,'dispatch_authority'=>false,'native_terminal_verified'=>false,
                'fence_scope'=>'server_queue_mutations_only','host_and_Library_fenced'=>false];
        });
    }
    /** Not exposed through MCP or cron. Future apply requires separate release
     * and operational approval. No reset/release/claim/credit or native request.
     */
    public function apply(string $queue,string $expected,string $request,string $originalRun,string $working,string $workingSha,string $packetSha,string $evidenceSha):array
    {
        foreach([$expected,$workingSha,$packetSha,$evidenceSha]as$sha)HotelNativeRecoveryContext::sha($sha);
        if(preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D',$request)!==1||preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$originalRun)!==1)throw new InvalidArgumentException('quarantine_request_identity');
        if(strlen($working)>67108864||hash('sha256',$working)!==$workingSha)throw new DomainException('quarantine_snapshot_digest');
        $doc=HotelCollectionCanonicalDocument::decodeObject($working);
        foreach(['knowledge','hotel_index','working_acceptances','country_import_ledger','coordination','criteria_updates','scoped_question_results']as$key)if(!isset($doc[$key])||!is_array($doc[$key]))throw new InvalidArgumentException('quarantine_snapshot_shape');
        $input=hash('sha256',HotelNativeRecoveryContext::json([$queue,$expected,$request,$originalRun,$workingSha,$packetSha,$evidenceSha]));
        return$this->tx(function()use($queue,$expected,$request,$originalRun,$working,$workingSha,$packetSha,$evidenceSha,$input):array{
            $q=$this->c->owned($queue,true);$old=$this->c->one('SELECT * FROM hotel_legacy_effect_quarantine WHERE queue_id=?',[$queue]);
            if($old){
                if($q['write_protocol']!==self::PROTOCOL||!hash_equals($old['request_sha256'],$input)||hash('sha256',$old['working_bytes'])!==$old['working_sha256'])throw new DomainException('quarantine_replay_conflict');
                return json_decode($old['receipt_bytes'],true,32,JSON_THROW_ON_ERROR);
            }
            if(!$this->triggersReady())throw new DomainException('quarantine_database_barrier_required');
            if(!self::eligible($q)||!hash_equals(self::businessHash($q),$expected))throw new DomainException('quarantine_business_CAS_conflict');
            // Never coexist with an enrolled canonical writer or old receipt.
            if($this->c->one('SELECT stage_id FROM hotel_journal_heads WHERE queue_id=?',[$queue])
                ||$this->c->one('SELECT queue_id FROM hotel_collection_heads WHERE queue_id=?',[$queue])
                ||$this->c->one('SELECT stage_id FROM hotel_work_receipts WHERE queue_id=? AND stage_id=?',[$queue,self::stage($q)]))throw new DomainException('quarantine_existing_authority');
            $receipt=['queue_id'=>$queue,'stage_id'=>self::stage($q),'state'=>'server_effects_quarantined',
                'original_run_id'=>$originalRun,'original_run_status'=>'UNKNOWN','native_terminal_verified'=>false,
                'claim_preserved'=>true,'accepted_batches'=>(int)$q['accepted_batches'],'working_sha256'=>$workingSha,
                'packet_sha256'=>$packetSha,'dispatch_authority'=>false,'business_accepted'=>false,
                'host_and_Library_fenced'=>false,'continuation_allowed'=>false];
            $this->c->exec('INSERT INTO hotel_legacy_effect_quarantine(queue_id,stage_id,request_id,request_sha256,original_run_id,queue_bytes,working_sha256,working_bytes,packet_sha256,evidence_sha256,receipt_bytes) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                [$queue,self::stage($q),$request,$input,$originalRun,HotelNativeRecoveryContext::json($q),$workingSha,$working,$packetSha,$evidenceSha,HotelNativeRecoveryContext::json($receipt)]);
            $this->c->exec('UPDATE hotel_work_queues SET write_protocol=? WHERE queue_id=?',[self::PROTOCOL,$queue]);
            if($this->fault!==null)($this->fault)('quarantine_barrier_written');
            return$receipt;
        });
    }
    private static function eligible(array$q):bool
    {return($q['write_protocol']??null)==='legacy'&&$q['state']==='claimed'&&$q['claim_sha256']!==null&&$q['current_run_id']===null&&(int)$q['stage_index']%2===1;}
    private static function stage(array$q):string{return hash('sha256',HotelNativeRecoveryContext::json([$q['queue_id'],$q['plan_sha256'],(int)$q['stage_index']]));}
    private static function businessHash(array$q):string
    {
        $v=[];foreach(['queue_id','project_key','admin_id','oauth_connection_id','mode','batch_limit','start_country','initial_phase_counter','plan_sha256','baseline_sha256','stage_index','state','claim_sha256','current_run_id','accepted_batches','phase_counter','write_protocol','data_snapshot_sha256']as$k)$v[$k]=$q[$k]??null;
        foreach(['admin_id','stage_index','accepted_batches','phase_counter']as$k)$v[$k]=(int)$v[$k];return hash('sha256',HotelNativeRecoveryContext::json($v));
    }
    private function triggersReady():bool
    {
        $names=['hotel_quarantine_queue_update','hotel_quarantine_queue_delete','hotel_quarantine_receipt_insert','hotel_quarantine_receipt_update','hotel_quarantine_receipt_delete'];
        $sql=$this->c->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ?'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN (?,?,?,?,?)'
            :"SELECT name FROM sqlite_master WHERE type='trigger' AND name IN (?,?,?,?,?)";
        $s=$this->c->pdo->prepare($sql);$s->execute($names);
        return count($s->fetchAll(PDO::FETCH_COLUMN))===5
            &&(int)$this->c->one('SELECT COUNT(*) AS n FROM hotel_legacy_quarantine_gate WHERE guard_id IN (1,2)',[])['n']===2;
    }
    private function tx(callable$work):array
    {
        $this->c->autocommit();$p=$this->c->pdo;$p->beginTransaction();
        try{if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$this->c->exec('UPDATE hotel_work_lane SET queue_id=queue_id WHERE lane_key=?',['HOTEL']);$r=$work();$p->commit();return$r;}
        catch(Throwable$e){if($p->inTransaction())$p->rollBack();throw$e;}
    }
}
