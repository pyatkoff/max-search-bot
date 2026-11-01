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
            if(!is_array($p)||count($p)!==count($keys)||array_diff(array_keys($p),$keys)
                ||!is_int($p['admin_id'])||!is_string($p['connection_id'])||!is_array($p['setup'])||!is_array($p['replacement_worker_setup']))throw new InvalidArgumentException('quarantined_cron_plan_invalid');
            // Both explicit switches govern staging, dispatch AND finalization.
            if(($p['setup']['dispatch_allowed']??null)!==true||($p['replacement_worker_setup']['dispatch_allowed']??null)!==true)continue;
            foreach(['queue_id','stage_id','control_capability','stage_request_id','finalize_request_id','review_task_id','packet_sha256','packet_bytes']as$key)if(!is_string($p[$key]))throw new InvalidArgumentException('quarantined_cron_plan_invalid');
            if(!is_array($p['members'])||!is_array($p['protected_hotel_keys']))throw new InvalidArgumentException('quarantined_cron_plan_invalid');
            $c=new HotelNativeRecoveryContext($this->pdo,$p['admin_id'],$p['connection_id'],$this->clock);$c->autocommit();$c->owned($p['queue_id']);
            $service=new HotelQuarantinedIntake($c,$this->fault);
            $r=$c->one('SELECT * FROM hotel_quarantined_intakes WHERE queue_id=? AND stage_id=?',[$p['queue_id'],$p['stage_id']]);
            if(!$r){
                if(hash('sha256',$p['packet_bytes'])!==$p['packet_sha256'])throw new DomainException('quarantined_cron_packet_pin');
                $service->stage($p['queue_id'],$p['stage_id'],$p['control_capability'],$p['stage_request_id'],$p['review_task_id'],$p['packet_bytes'],$p['members'],$p['protected_hotel_keys']);
                $r=$c->one('SELECT * FROM hotel_quarantined_intakes WHERE queue_id=? AND stage_id=?',[$p['queue_id'],$p['stage_id']]);
            }
            // Persisted sealed input wins: waiting ticks do not rehash archives.
            if(!$r||!hash_equals($r['control_sha256'],hash('sha256',$p['control_capability']))||$r['review_task']!==$p['review_task_id']||$r['packet_sha256']!==$p['packet_sha256'])throw new DomainException('quarantined_cron_plan_conflict');
            if($r['acceptance_bytes']!==null)continue;
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
}
