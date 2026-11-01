<?php
declare(strict_types=1);
require_once __DIR__.'/HotelQuarantinedIntake.php';
require_once __DIR__.'/HotelNativeAutomationAdapter.php';
require_once __DIR__.'/HotelCollectionCoordinator.php';

/** Trusted existing-cron continuation. No default plan, claim, resume or enable.
 * The private plan is server configuration, never worker/webhook input.
 */
final class HotelQuarantinedIntakeRuntime
{
    public function __construct(private PDO $pdo,private $clock=null,private $adapterFactory=null,private $fault=null){}
    public function tick(array $plans):int
    {
        if(!array_is_list($plans)||count($plans)>20)throw new InvalidArgumentException('quarantined_cron_plan_invalid');
        $processed=0;
        foreach($plans as$p){
            $keys=['admin_id','connection_id','queue_id','stage_id','control_capability','stage_request_id','finalize_request_id','review_task_id','packet_sha256','packet_bytes','members','protected_hotel_keys','setup','replacement_worker_setup'];
            if(!is_array($p)||array_diff($keys,array_keys($p))||array_diff(array_keys($p),[...$keys,'continuation'])
                ||!is_int($p['admin_id'])||!is_string($p['connection_id'])||!is_array($p['setup'])||!is_array($p['replacement_worker_setup']))throw new InvalidArgumentException('quarantined_cron_plan_invalid');
            // Both explicit switches govern staging, dispatch AND finalization.
            if(($p['setup']['dispatch_allowed']??null)!==true||($p['replacement_worker_setup']['dispatch_allowed']??null)!==true)continue;
            foreach(['queue_id','stage_id','control_capability','stage_request_id','finalize_request_id','review_task_id','packet_sha256','packet_bytes']as$key)if(!is_string($p[$key]))throw new InvalidArgumentException('quarantined_cron_plan_invalid');
            if(!is_array($p['members'])||!is_array($p['protected_hotel_keys']))throw new InvalidArgumentException('quarantined_cron_plan_invalid');
            $c=new HotelNativeRecoveryContext($this->pdo,$p['admin_id'],$p['connection_id'],$this->clock);$c->autocommit();$c->owned($p['queue_id']);
            $service=new HotelQuarantinedIntake($c,$this->fault);
            $columns='control_sha256,review_task,packet_sha256,working_sha256,acceptance_bytes,verdict_bytes,instance_id,private_receipt,producer_operation';
            $r=$c->one('SELECT '.$columns.' FROM hotel_quarantined_intakes WHERE queue_id=? AND stage_id=?',[$p['queue_id'],$p['stage_id']]);
            if(!$r){
                if(hash('sha256',$p['packet_bytes'])!==$p['packet_sha256'])throw new DomainException('quarantined_cron_packet_pin');
                $service->stage($p['queue_id'],$p['stage_id'],$p['control_capability'],$p['stage_request_id'],$p['review_task_id'],$p['packet_bytes'],$p['members'],$p['protected_hotel_keys']);
                $r=$c->one('SELECT '.$columns.' FROM hotel_quarantined_intakes WHERE queue_id=? AND stage_id=?',[$p['queue_id'],$p['stage_id']]);
            }
            // Persisted sealed input wins: waiting ticks do not rehash archives.
            if(!$r||!hash_equals($r['control_sha256'],hash('sha256',$p['control_capability']))||$r['review_task']!==$p['review_task_id']||$r['packet_sha256']!==$p['packet_sha256'])throw new DomainException('quarantined_cron_plan_conflict');
            if($r['acceptance_bytes']!==null){$processed+=$this->continueAccepted($p,$r);continue;}
            if($r['verdict_bytes']!==null){
                $v=json_decode($r['verdict_bytes'],true,32,JSON_THROW_ON_ERROR);
                if($v['decision']!=='accept')continue;
                $service->finalize($p['queue_id'],$p['stage_id'],$p['control_capability'],$p['finalize_request_id'],$r['working_sha256'],$r['packet_sha256'],hash('sha256',$r['verdict_bytes']));
                ++$processed;continue;
            }
            if($r['instance_id']!==null)continue;
            $e=$c->one('SELECT event_id,state FROM hotel_collection_outbox WHERE queue_id=? AND stage_id=?',[$p['queue_id'],$p['stage_id']]);
            if(!$e||$e['state']!=='delivered')continue;
            $receipt=json_decode($r['private_receipt'],true,32,JSON_THROW_ON_ERROR);
            $adapter=$this->adapterFactory===null?new HotelNativeAutomationAdapter($this->pdo,$p['admin_id'],$p['connection_id'],null,$this->clock):($this->adapterFactory)($p);
            $coordinator=new HotelCollectionCoordinator($this->pdo,$p['admin_id'],$p['connection_id'],$adapter,$this->clock);
            $coordinator->dispatch($p['queue_id'],$p['stage_id'],1,$p['control_capability'],'hotel_evt_'.$e['event_id'],[
                'operation_id'=>$r['producer_operation'],'task_id'=>$r['review_task'],'request_id'=>$p['stage_request_id'],'worker_capability'=>$receipt['review_capability']]);
            ++$processed;
        }
        return$processed;
    }
    /** Optional exact server-owned onward plan. Missing plan is still HOLD. */
    private function continueAccepted(array $p,array $r):int
    {
        if(!array_key_exists('continuation',$p))return 0;$n=$p['continuation'];
        $keys=['request_id','acceptance_sha256','working_sha256','inventory','reserve_attestation','producer_task_id','scope_id','source_packet_id','hotel_keys'];
        if(!is_array($n)||count($n)!==count($keys)||array_diff(array_keys($n),$keys))throw new InvalidArgumentException('quarantined_onward_plan_invalid');
        $service=new HotelCollectionCanonicalService($this->pdo,$p['admin_id'],$p['connection_id'],$this->clock,$this->fault);
        $before=$this->pdo->prepare('SELECT write_protocol FROM hotel_work_queues WHERE queue_id=?');$before->execute([$p['queue_id']]);$wasAdopted=$before->fetchColumn()==='journal_v1';
        $receipt=$service->adoptQuarantined($p['queue_id'],$p['stage_id'],$p['control_capability'],$n['request_id'],$n['acceptance_sha256'],$n['working_sha256'],$n['inventory'],$n['reserve_attestation']);
        $q=$this->pdo->prepare('SELECT stage_index,plan_sha256 FROM hotel_work_queues WHERE queue_id=?');$q->execute([$p['queue_id']]);$queue=$q->fetch(PDO::FETCH_ASSOC);
        $original=json_decode($r['acceptance_bytes'],true,32,JSON_THROW_ON_ERROR);$index=$original['next_stage_index'];
        if(!$queue||!in_array((int)$queue['stage_index'],[$index,$index+1],true))return 0;
        $id=hash('sha256',HotelNativeRecoveryContext::json([$n['request_id'],'onward-intent']));
        $journal=new HotelWorkOperationJournalPrototype($this->pdo,$p['admin_id'],$p['connection_id']);
        $intent=$journal->intent($p['queue_id'],$receipt['next_stage_id'],1,$p['control_capability'],$id,$receipt['event_id'],$n['producer_task_id']);
        if((int)$queue['stage_index']===$index+1)return$this->continueReview($p,$n,$intent,$service,$receipt['next_stage_id']);
        $s=$this->pdo->prepare('SELECT event_id,state FROM hotel_collection_outbox WHERE queue_id=? AND stage_id=?');$s->execute([$p['queue_id'],$receipt['next_stage_id']]);$event=$s->fetch(PDO::FETCH_ASSOC);
        if(!$event)return 0;
        if($event['state']==='dispatch_unknown'){
            $s=$this->pdo->prepare('SELECT o.reported_run_id FROM hotel_journal_operations o JOIN hotel_collection_candidates c ON c.queue_id=o.queue_id AND c.operation_id=o.operation_id WHERE o.queue_id=? AND o.operation_id=? AND o.instance_id IS NOT NULL');$s->execute([$p['queue_id'],$intent['operation_id']]);$run=$s->fetchColumn();
            if(!is_string($run)||$run==='')return 0;
            $service->sealResearch($p['queue_id'],$receipt['next_stage_id'],1,$p['control_capability'],$intent['operation_id'],$run,$p['review_task_id']);return 1;
        }
        if(!in_array($event['state'],['pending','delivered'],true))return 0;
        $service->prepare($p['queue_id'],$receipt['next_stage_id'],1,$p['control_capability'],$intent['operation_id'],$n['scope_id'],$n['source_packet_id'],$n['hotel_keys'],0);
        if($event['state']!=='delivered')return $wasAdopted?0:1;
        $adapter=$this->adapterFactory===null?new HotelNativeAutomationAdapter($this->pdo,$p['admin_id'],$p['connection_id'],null,$this->clock):($this->adapterFactory)($p);
        (new HotelCollectionCoordinator($this->pdo,$p['admin_id'],$p['connection_id'],$adapter,$this->clock))->dispatch($p['queue_id'],$receipt['next_stage_id'],1,$p['control_capability'],'hotel_evt_'.$event['event_id'],[
            'operation_id'=>$intent['operation_id'],'task_id'=>$n['producer_task_id'],'request_id'=>hash('sha256',HotelNativeRecoveryContext::json([$n['request_id'],'onward-dispatch'])),'worker_capability'=>$intent['worker_capability']]);
        return 1;
    }
    private function continueReview(array $p,array $n,array $intent,HotelCollectionCanonicalService $service,string $producerStage):int
    {
        $s=$this->pdo->prepare('SELECT * FROM hotel_collection_reviews WHERE queue_id=? AND producer_operation=?');$s->execute([$p['queue_id'],$intent['operation_id']]);$review=$s->fetch(PDO::FETCH_ASSOC);
        if(!$review||$review['producer_stage']!==$producerStage||$review['review_task']!==$p['review_task_id'])throw new DomainException('quarantined_onward_review_conflict');
        if($review['acceptance_bytes']!==null)return 0;
        if($review['verdict_bytes']!==null){
            $v=json_decode($review['verdict_bytes'],true,32,JSON_THROW_ON_ERROR);if($v['decision']!=='accept')return 0;
            $s=$this->pdo->prepare('SELECT base_revision,base_snapshot_sha256 FROM hotel_collection_assignments WHERE queue_id=? AND operation_id=?');$s->execute([$p['queue_id'],$intent['operation_id']]);$a=$s->fetch(PDO::FETCH_ASSOC);
            if(!$a)throw new DomainException('quarantined_onward_assignment_missing');
            $service->finalizeIntake($p['queue_id'],$review['intake_stage'],1,$p['control_capability'],hash('sha256',HotelNativeRecoveryContext::json([$n['request_id'],'onward-finalize'])),hash('sha256',$review['verdict_bytes']),(int)$a['base_revision'],$a['base_snapshot_sha256']);return 1;
        }
        if($review['instance_id']!==null)return 0;
        $s=$this->pdo->prepare('SELECT event_id,state FROM hotel_collection_outbox WHERE queue_id=? AND stage_id=?');$s->execute([$p['queue_id'],$review['intake_stage']]);$event=$s->fetch(PDO::FETCH_ASSOC);
        if(!$event||$event['state']!=='delivered')return 0;
        $receipt=json_decode($review['private_receipt'],true,32,JSON_THROW_ON_ERROR);
        $adapter=$this->adapterFactory===null?new HotelNativeAutomationAdapter($this->pdo,$p['admin_id'],$p['connection_id'],null,$this->clock):($this->adapterFactory)($p);
        (new HotelCollectionCoordinator($this->pdo,$p['admin_id'],$p['connection_id'],$adapter,$this->clock))->dispatch($p['queue_id'],$review['intake_stage'],1,$p['control_capability'],'hotel_evt_'.$event['event_id'],[
            'operation_id'=>$intent['operation_id'],'task_id'=>$p['review_task_id'],'request_id'=>hash('sha256',HotelNativeRecoveryContext::json([$n['request_id'],'onward-review-dispatch'])),'worker_capability'=>$receipt['review_capability']]);return 1;
    }
}
