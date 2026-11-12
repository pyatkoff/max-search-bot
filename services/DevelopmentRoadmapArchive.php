<?php
declare(strict_types=1);
require_once __DIR__.'/DevelopmentRoadmapSavedResult.php';

/** Read-only inspection of a completed archive for a fresh same-admin DEV grant. */
trait DevelopmentRoadmapArchive
{
    private function roadmapArchiveCandidate(string $id,int $admin,string $connection,bool $admission=false): ?array
    {
        if($id!==DevelopmentWorkServerContract::QUEUE)return null;
        self::actor($admin,$connection);
        $q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.$this->lock(),[$id]);
        if(!$q||$q['project_key']!==self::PROJECT||(int)$q['admin_id']!==$admin)return null;
        $c=$this->serverControl($q);
        // The historical native owner and exact server authorizer retain their old route.
        if(!$c||(!$admission&&(hash_equals($q['oauth_connection_id'],$connection)
            ||hash_equals((string)$c['execution_connection_id'],$connection))))return null;
        if($c['state']!=='complete'||$q['current_task_id']!==null||$q['state']!=='blocked'
            ||(int)$q['task_count']!==3||(int)$q['accepted_count']!==2
            ||(int)$q['blocked_count']!==1||(int)$q['retirement_count']!==1
            ||(int)$c['reserved_calls']!==4)return null;
        if($this->one('SELECT task_id FROM development_work_items WHERE queue_id=? AND (state IN (?,?) OR claim_sha256 IS NOT NULL OR current_run_id IS NOT NULL) LIMIT 1',
            [$id,'claimed','unknown']))return null;
        require_once __DIR__.'/DevelopmentWorkConnectionAuthority.php';
        $authority=new DevelopmentWorkConnectionAuthority($this->pdo,$this->clock);
        $authority->assertCurrent($admin,$connection);
        // Verify immutable archive/binding/legacy with the canonical implementation.
        // No old credential is selected, restored, refreshed or used for new execution.
        $this->serverBinding($q,$c);
        foreach([DevelopmentWorkServerContract::A,DevelopmentWorkServerContract::B]as$task){
            $item=$this->item($id,$task);
            if(!$item||$item['state']!=='accepted'||$item['claim_sha256']!==null||$item['current_run_id']!==null)
                throw new DomainException('development_roadmap_archive_unavailable');
            $this->storedSpec($item);$this->acceptedItemReceipt($q,$item);
        }
        $this->project($q);
        $proof=$this->serverProof($q,$c);
        if($proof['state']!=='complete'||$proof['reserved_calls']!==4||count($proof['attempts'])!==4)
            throw new DomainException('development_roadmap_archive_unavailable');
        $archiveHash=self::hash(['native_owner_sha256'=>hash('sha256',$q['oauth_connection_id']),
            'initial_plan_sha256'=>$q['initial_plan_sha256'],'plan_sha256'=>$q['plan_sha256'],
            'counts'=>[3,2,1,1],'control'=>$proof]);
        $result=['ok'=>true,'project_key'=>self::PROJECT,'queue_id'=>$id,'execution_kind'=>'roadmap_archive_preparation',
            'state'=>'blocked','task_count'=>3,'accepted_count'=>2,'blocked_count'=>1,'retirement_count'=>1,
            'task_id'=>null,'current_run_id'=>null,'plan_sha256'=>$q['plan_sha256'],
            'roadmap_preparation'=>['available'=>true,'scope'=>'closed_archive_metadata_only_not_admission',
                'archive_sha256'=>$archiveHash,'expected_caller_connection_id'=>$connection,
                'native_owner_sha256'=>hash('sha256',$q['oauth_connection_id']),
                'old_control_state'=>'complete','old_reserved_calls'=>4,'old_calls_remaining'=>0,
                'read_only'=>true,'admission_allowed'=>false,'dispatch_allowed'=>false,
                'native_host_route'=>'not_established','historical_unknown_reconciled'=>false,
                'supported_saved_result_kinds'=>array_map(
                    static fn(string $kind):array=>DevelopmentRoadmapSavedResult::capability($kind),
                    DevelopmentRoadmapSavedResult::KINDS)]];
        if($this->roadmapTablesReady()){
            $implementation=$this->roadmapImplementation();
            $result['roadmap_preparation']['implementation']=$implementation;
            $result['roadmap_preparation']['implementation_sha256']=self::hash($implementation);
            $result['roadmap_preparation']['policy']=DevelopmentRoadmapReview::policy();
            $result['roadmap_preparation']['admission_method']='development_work_roadmap_admit';
        }
        $authority->assertCurrent($admin,$connection);
        return $result;
    }
}
