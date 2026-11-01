<?php
declare(strict_types=1);
require_once __DIR__.'/HotelNativeRecoveryContext.php';
require_once __DIR__.'/HotelQuarantinedPacket.php';
require_once __DIR__.'/HotelLegacyEffectQuarantine.php';

/** Same-claim legacy intake only. No research, new claim, Library or native call.
 * Control stages the already accepted producer packet; one independent reviewer
 * decides; a transaction-bound DB certificate allows exactly one intake advance.
 */
final class HotelQuarantinedIntake
{
    private string $session;
    private static ?WeakMap $sessions=null;
    public function __construct(private HotelNativeRecoveryContext $c,private $fault=null)
    {
        self::$sessions??=new WeakMap();
        $this->session=$c->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?(string)$c->pdo->query('SELECT CONNECTION_ID()')->fetchColumn():(self::$sessions[$c->pdo]??=bin2hex(random_bytes(16)));
        if($c->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$c->pdo->sqliteCreateFunction('hotel_intake_session',fn():string=>$this->session,0);
    }
    public static function owns(PDO$p,int$a,string$c,string$q):bool
    {$s=$p->prepare('SELECT write_protocol FROM hotel_work_queues WHERE queue_id=? AND admin_id=? AND oauth_connection_id=?');$s->execute([$q,$a,$c]);return$s->fetchColumn()==='quarantine_v1';}
    public function stage(string$queue,string$stage,string$control,string$request,string$task,string$packet,array$members,array$protected):array
    {
        HotelNativeRecoveryContext::sha($control);HotelNativeRecoveryContext::sha($stage);
        if(preg_match('/\A[a-f0-9]{32}\z/D',$task)!==1||preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D',$request)!==1)self::deny('stage_identity');
        $hashes=[];foreach($members as$n=>$b){if(!is_string($n)||!is_string($b))self::deny('member_shape');$hashes[$n]=hash('sha256',$b);}ksort($hashes);
        $input=hash('sha256',self::json([$queue,$stage,hash('sha256',$control),$request,$task,hash('sha256',$packet),$hashes,$protected]));
        return$this->tx(function()use($queue,$stage,$control,$request,$task,$packet,$members,$protected,$input):array{
            $q=$this->c->owned($queue,true);$old=$this->row($queue,$stage);
            if($old){if($old['request_sha256']!==$input)self::deny('stage_replay_conflict');return json_decode($old['private_receipt'],true,32,JSON_THROW_ON_ERROR);}
            $f=$this->frozen($q,$stage);$p=HotelQuarantinedPacket::inspect($f['working_bytes'],$packet,$members,$q,$protected);
            if(hash('sha256',$packet)!==$f['packet_sha256'])self::deny('quarantined_packet_pin');
            $source=$this->c->one('SELECT * FROM hotel_work_receipts WHERE queue_id=? AND stage_index=?',[$queue,(int)$q['stage_index']-1]);
            $r=$source?json_decode($source['receipt_json'],true,32,JSON_THROW_ON_ERROR):null;
            if(!$source||hash('sha256',$source['receipt_json'])!==$source['receipt_sha256']||$source['stage_id']!==$p['packet']['stage_id']||($r['outcome']??null)!=='accepted_readback'||($r['packet_sha256']??null)!==$f['packet_sha256']||($r['run_id']??null)!==$p['packet']['actual_run_id'])self::deny('accepted_original_producer_required');
            if($task===$p['packet']['task_id'])self::deny('independent_reviewer_required');
            if($this->c->one('SELECT event_id FROM hotel_collection_outbox WHERE queue_id=? AND stage_id=?',[$queue,$stage]))self::deny('prior_dispatch_outbox');
            $key=bin2hex(random_bytes(32));$event=hash('sha256',self::json([$queue,$stage,'quarantined_review',$f['packet_sha256']]));
            $receipt=['queue_id'=>$queue,'stage_id'=>$stage,'generation'=>1,'operation_id'=>$p['packet']['operation_id'],'review_task_id'=>$task,
                'review_capability'=>$key,'candidate_sha256'=>$f['packet_sha256'],'working_sha256'=>$f['working_sha256'],'event_id'=>'hotel_evt_'.$event,'dispatch_authority'=>false,'business_accepted'=>false];
            $reads=HotelQuarantinedPacket::requiredReads($f['working_bytes'],$packet,$members);
            if(count($reads)>512||strlen(self::json($reads))>32768)self::deny('review_context_limit');
            $this->c->exec('INSERT INTO hotel_quarantined_intakes(queue_id,stage_id,request_sha256,control_sha256,review_task,capability_sha256,packet_sha256,packet_bytes,members_bytes,protected_bytes,producer_operation,producer_run,working_sha256,private_receipt,required_reads_bytes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$queue,$stage,$input,hash('sha256',$control),$task,hash('sha256',$key),$f['packet_sha256'],$packet,self::json($members),self::json($protected),$p['packet']['operation_id'],$p['packet']['actual_run_id'],$f['working_sha256'],self::json($receipt),self::json($reads)]);
            $this->c->exec('INSERT INTO hotel_collection_outbox(event_id,queue_id,stage_id,state) VALUES(?,?,?,?)',[$event,$queue,$stage,'pending']);
            return$receipt;
        });
    }
    public function identity(string$q,string$s,int$g,string$control,string$op,string$cap,string$task):array
    {return$this->tx(function()use($q,$s,$g,$control,$op,$cap,$task):array{$queue=$this->c->owned($q,true);$r=$this->required($q,$s);$this->frozen($queue,$s);$this->control($r,$control);$this->transportCheck($r,$g,$op,$cap,$task);return['role'=>'reviewer','task_id'=>$task];});}
    public function transport(array$a,bool$sending):array
    {
        return$this->tx(function()use($a,$sending):array{
            $q=$this->c->owned($a['queue_id'],true);$r=$this->required($a['queue_id'],$a['stage_id']);$this->frozen($q,$a['stage_id']);
            $this->transportCheck($r,$a['generation'],$a['operation_id'],$a['worker_capability'],$a['task_id']);if(($a['role']??null)!=='reviewer')self::deny('reviewer_only');
            if($sending&&!$this->c->one('SELECT event_id FROM hotel_collection_outbox WHERE queue_id=? AND stage_id=? AND state=?',[$q['queue_id'],$a['stage_id'],'dispatch_unknown']))self::deny('consumed_dispatch_required');
            return['role'=>'reviewer','task_id'=>$r['review_task'],'generation'=>1];
        });
    }
    public function consume(string$q,string$s,int$g,string$control,string$event):array
    {
        return$this->tx(function()use($q,$s,$g,$control,$event):array{
            $queue=$this->c->owned($q,true);$r=$this->required($q,$s);$this->frozen($queue,$s);$this->control($r,$control);if($g!==1)self::deny('generation');
            $id=substr($event,10);if($event!=='hotel_evt_'.$id||preg_match('/\A[a-f0-9]{64}\z/D',$id)!==1)self::deny('event_identity');
            $e=$this->c->one('SELECT state FROM hotel_collection_outbox WHERE event_id=? AND queue_id=? AND stage_id=?',[$id,$q,$s]);
            if(!$e)self::deny('event_missing');if($e['state']==='dispatch_unknown')return['state'=>'UNKNOWN','dispatch_authority'=>false];
            if($e['state']!=='delivered')self::deny('fresh_cron_delivery_required');$this->c->exec('UPDATE hotel_collection_outbox SET state=? WHERE event_id=?',['dispatch_unknown',$id]);
            return['state'=>'UNKNOWN','dispatch_authority'=>true];
        });
    }
    public function context(array$a):array
    {
        return$this->tx(function()use($a):array{[$q,$r]=$this->review($a);return['producer_operation'=>$r['producer_operation'],'candidate_sha256'=>$r['packet_sha256'],'working_sha256'=>$r['working_sha256'],
            'criteria_due'=>false,'required_reads'=>json_decode($r['required_reads_bytes'],true,32,JSON_THROW_ON_ERROR),'read_tool'=>'hotel_work_quarantine_read','candidate_format'=>'original_lossless_legacy_handoff','business_accepted'=>$r['acceptance_bytes']!==null,
            'acceptance'=>$r['acceptance_bytes']===null?null:json_decode($r['acceptance_bytes'],true,32,JSON_THROW_ON_ERROR),'dispatch_authority'=>false,'native_run_verified'=>false];});
    }
    public function read(array$a,string$kind,string$member,string$pointer,int$offset):array
    {
        return$this->tx(function()use($a,$kind,$member,$pointer,$offset):array{
            [$q,$r]=$this->review($a);$f=$this->c->one('SELECT working_bytes FROM hotel_legacy_effect_quarantine WHERE queue_id=?',[$q['queue_id']]);
            $all=json_decode($r['members_bytes'],true,128,JSON_THROW_ON_ERROR);
            $bytes=match($kind){'packet'=>$r['packet_bytes'],'working'=>$f['working_bytes'],'member'=>$all[$member]??throw new DomainException('quarantined_intake_member_missing'),
                'accepted_working'=>$r['accepted_working_bytes']??throw new DomainException('quarantined_intake_not_accepted'),default=>throw new InvalidArgumentException('quarantine_read_kind')};
            if($kind==='working'&&hash('sha256',$bytes)!==$r['working_sha256']||$kind==='accepted_working'&&hash('sha256',$bytes)!==$r['accepted_working_sha256'])self::deny('snapshot_corrupt');
            if($pointer!==''){
                if(!str_starts_with($pointer,'/')||preg_match('/~(?![01])/',$pointer))self::deny('pointer');$v=json_decode($bytes,false,128,JSON_THROW_ON_ERROR);
                foreach(explode('/',substr($pointer,1))as$k){$k=str_replace(['~1','~0'],['/','~'],$k);if(is_object($v)&&property_exists($v,$k))$v=$v->$k;elseif(is_array($v)&&array_key_exists($k,$v))$v=$v[$k];else self::deny('pointer_missing');}$bytes=self::json($v);
            }
            if($offset<0||$offset>strlen($bytes))self::deny('cursor');$chunk=substr($bytes,$offset,16384);$next=$offset+strlen($chunk);
            $key=hash('sha256',self::json([$kind,$member,$pointer]));$old=$this->c->one('SELECT * FROM hotel_quarantined_read_coverage WHERE queue_id=? AND stage_id=? AND read_key=?',[$q['queue_id'],$r['stage_id'],$key]);
            if(($old===null&&$offset!==0)||($old!==null&&($old['value_sha256']!==hash('sha256',$bytes)||$offset>(int)$old['next_offset'])))self::deny('read_cursor_gap');
            if($old===null)$this->c->exec('INSERT INTO hotel_quarantined_read_coverage(queue_id,stage_id,read_key,value_sha256,next_offset,total_bytes) VALUES(?,?,?,?,?,?)',[$q['queue_id'],$r['stage_id'],$key,hash('sha256',$bytes),$next,strlen($bytes)]);
            elseif($offset===(int)$old['next_offset'])$this->c->exec('UPDATE hotel_quarantined_read_coverage SET next_offset=? WHERE queue_id=? AND stage_id=? AND read_key=?',[$next,$q['queue_id'],$r['stage_id'],$key]);
            return['sha256'=>hash('sha256',$bytes),'total_bytes'=>strlen($bytes),'offset'=>$offset,'chunk_base64'=>base64_encode($chunk),'next_offset'=>$next<strlen($bytes)?$next:null,'dispatch_authority'=>false];
        });
    }
    public function verdict(array$a,array$v):array
    {
        if(array_diff(array_keys($v),['candidate_sha256','decision','criteria','review_run'])||count($v)!==4||!in_array($v['decision']??null,['accept','hold','reject'],true)||($v['criteria']??null)!==null||!is_string($v['review_run']??null)||preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$v['review_run'])!==1)self::deny('verdict_shape');
        return$this->tx(function()use($a,$v):array{
            [$q,$r]=$this->review($a);$f=$this->c->one('SELECT original_run_id FROM hotel_legacy_effect_quarantine WHERE queue_id=?',[$q['queue_id']]);
            if($v['candidate_sha256']!==$r['packet_sha256']||in_array($v['review_run'],[$r['producer_run'],$f['original_run_id']],true))self::deny('independent_exact_review_required');
            if($v['decision']==='accept')foreach(json_decode($r['required_reads_bytes'],true,32,JSON_THROW_ON_ERROR)as$read){
                $key=hash('sha256',self::json([$read['kind'],$read['member'],$read['pointer']]));$coverage=$this->c->one('SELECT next_offset,total_bytes FROM hotel_quarantined_read_coverage WHERE queue_id=? AND stage_id=? AND read_key=?',[$q['queue_id'],$r['stage_id'],$key]);
                if(!$coverage||(int)$coverage['next_offset']!==(int)$coverage['total_bytes'])self::deny('required_review_bytes_unread');
            }
            $bytes=self::json($v);if($r['verdict_bytes']!==null&&$r['verdict_bytes']!==$bytes)self::deny('immutable_verdict');
            if($r['verdict_bytes']===null)$this->c->exec('UPDATE hotel_quarantined_intakes SET verdict_bytes=? WHERE queue_id=? AND stage_id=?',[$bytes,$q['queue_id'],$r['stage_id']]);
            return['status'=>'review_recorded','verdict_sha256'=>hash('sha256',$bytes),'business_accepted'=>false];
        });
    }
    public function finalize(string$queue,string$stage,string$control,string$request,string$expectedWorking,string$expectedPacket,string$expectedVerdict):array
    {
        return$this->tx(function()use($queue,$stage,$control,$request,$expectedWorking,$expectedPacket,$expectedVerdict):array{
            $q=$this->c->owned($queue,true);$r=$this->required($queue,$stage);$this->control($r,$control);
            $input=hash('sha256',self::json([$request,$expectedWorking,$expectedPacket,$expectedVerdict]));
            if($r['acceptance_bytes']!==null){if($r['finalize_sha256']!==$input)self::deny('finalize_replay_conflict');return json_decode($r['acceptance_bytes'],true,32,JSON_THROW_ON_ERROR);}
            $f=$this->frozen($q,$stage);if($expectedWorking!==$f['working_sha256']||$expectedPacket!==$f['packet_sha256']||$expectedPacket!==$r['packet_sha256']||$r['verdict_bytes']===null||hash('sha256',$r['verdict_bytes'])!==$expectedVerdict)self::deny('finalize_CAS');
            $v=json_decode($r['verdict_bytes'],true,32,JSON_THROW_ON_ERROR);if($v['decision']!=='accept'||$r['instance_id']===null)self::deny('independent_acceptance_required');
            $p=HotelQuarantinedPacket::inspect($f['working_bytes'],$r['packet_bytes'],json_decode($r['members_bytes'],true,128,JSON_THROW_ON_ERROR),$q,json_decode($r['protected_bytes'],true,32,JSON_THROW_ON_ERROR));
            $record=['protocol'=>'quarantined_intake_v1','intake_stage_id'=>$stage,'operation_id'=>'QUARANTINED-INTAKE-'.$stage,'source_packet_sha256'=>$r['packet_sha256'],
                'producer_operation'=>$r['producer_operation'],'producer_run_id'=>$r['producer_run'],'review_run_id'=>$v['review_run'],'base_working_sha256'=>$f['working_sha256'],
                'verdict_sha256'=>$expectedVerdict,'producer_counts'=>$p['counts'],
                'counts'=>['hotel_count'=>$p['counts']['hotel_count'],'record_count'=>$p['counts']['record_count'],'source_count'=>$p['counts']['source_count'],'read_update_count'=>0,'accepted_packets'=>1],
                'business_accepted'=>true];
            $working=HotelQuarantinedPacket::merge($f['working_bytes'],$r['packet_bytes'],$record);$workingSha=hash('sha256',$working);
            $receipt=$record+['run_id'=>$v['review_run'],'outcome'=>'accepted_readback','packet_sha256'=>$r['packet_sha256'],'criteria'=>null,'working_sha256'=>$workingSha,
                'accepted_batches'=>(int)$q['accepted_batches']+1,'next_stage_index'=>(int)$q['stage_index']+1,'original_handler_status'=>'UNKNOWN','next_dispatch_allowed'=>false,'native_run_verified'=>false];
            $bytes=self::json($receipt);if(strlen($bytes)>4096)self::deny('receipt_limit');$sha=hash('sha256',$bytes);
            $this->c->exec('UPDATE hotel_quarantined_intakes SET acceptance_bytes=?,accepted_working_bytes=?,accepted_working_sha256=?,finalize_sha256=? WHERE queue_id=? AND stage_id=?',[$bytes,$working,$workingSha,$input,$queue,$stage]);
            if($this->fault!==null)($this->fault)('quarantine_data_before_queue');
            $this->c->exec('INSERT INTO hotel_quarantine_intake_permits(queue_id,session_id,stage_id,stage_index,claim_sha256,receipt_sha256,target_phase,target_accepted) VALUES(?,?,?,?,?,?,?,?)',[$queue,$this->session,$stage,$q['stage_index'],$q['claim_sha256'],$sha,1-(int)$q['phase_counter'],(int)$q['accepted_batches']+1]);
            $this->c->exec('INSERT INTO hotel_work_receipts(queue_id,stage_index,stage_id,receipt_sha256,receipt_json,created_at) VALUES(?,?,?,?,?,?)',[$queue,$q['stage_index'],$stage,$sha,$bytes,gmdate('Y-m-d H:i:s',$this->c->now())]);
            if($this->fault!==null)($this->fault)('quarantine_permit_before_queue');
            $this->c->exec("UPDATE hotel_work_queues SET stage_index=stage_index+1,state='ready',phase_counter=?,accepted_batches=accepted_batches+1,claim_sha256=NULL,current_run_id=NULL,event_ack=NULL,delivered_at=NULL,claim_deadline=NULL,attention_at=NULL,attention_ack=NULL,watchdog_due_at=NULL,watchdog_epoch=0,last_watch_ack=NULL,updated_at=? WHERE queue_id=?",[1-(int)$q['phase_counter'],gmdate('Y-m-d H:i:s',$this->c->now()),$queue]);
            $next=hash('sha256',self::json([$queue,$stage,'next_held']));$this->c->exec('INSERT INTO hotel_collection_outbox(event_id,queue_id,stage_id,state) VALUES(?,?,?,?)',[$next,$queue,hash('sha256',self::json([$queue,$q['plan_sha256'],(int)$q['stage_index']+1])),'held']);
            $this->c->exec('DELETE FROM hotel_quarantine_intake_permits WHERE queue_id=? AND session_id=?',[$queue,$this->session]);
            return$receipt;
        });
    }
    private function review(array$a):array
    {
        if(count($a)!==5||array_diff(array_keys($a),['queue_id','stage_id','generation','review_capability','instance_id'])||($a['generation']??null)!==1||!is_string($a['instance_id']??null)||preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D',$a['instance_id'])!==1)self::deny('review_identity');
        $q=$this->c->owned($a['queue_id'],true);$r=$this->required($a['queue_id'],$a['stage_id']);
        if(!is_string($a['review_capability'])||!hash_equals($r['capability_sha256'],hash('sha256',$a['review_capability'])))self::deny('review_capability');
        if($r['instance_id']!==null&&$r['instance_id']!==$a['instance_id'])self::deny('review_instance_conflict');
        if($r['acceptance_bytes']===null){$this->frozen($q,$a['stage_id']);if(!$this->c->one('SELECT event_id FROM hotel_collection_outbox WHERE queue_id=? AND stage_id=? AND state=?',[$q['queue_id'],$a['stage_id'],'dispatch_unknown']))self::deny('dispatch_not_consumed');}
        elseif($r['instance_id']===null)self::deny('accepted_without_instance');
        if($r['instance_id']===null)$this->c->exec('UPDATE hotel_quarantined_intakes SET instance_id=? WHERE queue_id=? AND stage_id=?',[$a['instance_id'],$q['queue_id'],$a['stage_id']]);
        return[$q,$r];
    }
    private function frozen(array$q,string$stage):array
    {
        (new HotelLegacyEffectQuarantine($this->c))->assertDatabaseBarrier();
        $f=$this->c->one('SELECT * FROM hotel_legacy_effect_quarantine WHERE queue_id=?',[$q['queue_id']]);
        if(!$f||$q['write_protocol']!=='quarantine_v1'||$q['state']!=='claimed'||$q['current_run_id']!==null||$q['mode']!=='continuous'||(int)$q['stage_index']%2!==1||(int)$q['phase_counter']!==0||$f['stage_id']!==$stage||hash('sha256',$f['working_bytes'])!==$f['working_sha256'])self::deny('current_quarantined_intake_required');
        $old=json_decode($f['queue_bytes'],true,64,JSON_THROW_ON_ERROR);
        foreach(['claim_sha256','stage_index','accepted_batches','phase_counter','baseline_sha256','plan_sha256','admin_id','oauth_connection_id']as$k)if((string)$q[$k]!== (string)$old[$k])self::deny('original_claim_CAS');
        return$f;
    }
    private function transportCheck(array$r,int$g,string$op,string$cap,string$task):void
    {if($g!==1||$r['producer_operation']!==$op||$r['review_task']!==$task||!hash_equals($r['capability_sha256'],hash('sha256',$cap))||$r['instance_id']!==null||$r['verdict_bytes']!==null||$r['acceptance_bytes']!==null)self::deny('transport_identity');}
    private function control(array$r,string$key):void{if(!hash_equals($r['control_sha256'],hash('sha256',$key)))self::deny('control_capability');}
    private function row(string$q,string$s):?array{return$this->c->one('SELECT * FROM hotel_quarantined_intakes WHERE queue_id=? AND stage_id=?',[$q,$s]);}
    private function required(string$q,string$s):array{$r=$this->row($q,$s)??throw new DomainException('quarantined_intake_not_staged');if(hash('sha256',$r['packet_bytes'])!==$r['packet_sha256'])self::deny('packet_corrupt');return$r;}
    private function tx(callable$fn):array{$this->c->autocommit();$p=$this->c->pdo;$p->beginTransaction();try{if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$this->c->exec('UPDATE hotel_work_lane SET queue_id=queue_id WHERE lane_key=?',['HOTEL']);$r=$fn();$p->commit();return$r;}catch(Throwable$e){if($p->inTransaction())$p->rollBack();throw$e;}}
    private static function json(mixed$v):string{return HotelQuarantinedPacket::json($v);}
    private static function deny(string$r):never{throw new DomainException('quarantined_intake_'.$r);}
}
