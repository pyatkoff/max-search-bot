<?php
declare(strict_types=1);

/** Development metadata only. Claims do not replace source or publisher leases. */
final class DevelopmentWorkQueue
{
    public const CLAIM_DEADLINE_SECONDS=180;
    public const WATCHDOG_SECONDS=300;
    private const PROJECT='anytour';
    private const MAX_ITEMS=2000;
    private string $driver;
    public function __construct(private PDO $pdo,private $clock=null)
    {
        $this->driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver,['sqlite','mysql'],true)||($clock!==null&&!is_callable($clock))) throw new InvalidArgumentException('invalid_development_work_runtime');
    }
    public function create(int $admin,string $connection,array $args): array
    {
        self::keys($args,['queue_id','plan_sha256','tasks']);
        $id=self::id($args['queue_id']??null);$plan=self::sha($args['plan_sha256']??null);$tasks=self::specs($args['tasks']??null);
        self::actor($admin,$connection);$tasksHash=self::hash($tasks);
        return $this->transaction(function() use($admin,$connection,$id,$plan,$tasks,$tasksHash): array {
            $lane=$this->lane();$old=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.$this->lock(),[$id]);
            if ($old) {
                $this->authorize($old,$admin,$connection);
                if ($old['initial_plan_sha256']!==$plan||$old['initial_tasks_sha256']!==$tasksHash) throw new DevelopmentWorkQueueConflict('queue_exists');
                return $this->project($old)+['create_status'=>'already_recorded'];
            }
            if ($lane['queue_id']!==null) throw new DevelopmentWorkQueueConflict('development_lane_busy');
            $now=$this->now();
            $this->execute('INSERT INTO development_work_queues (queue_id,project_key,admin_id,oauth_connection_id,initial_plan_sha256,initial_tasks_sha256,plan_sha256,current_task_id,state,task_count,accepted_count,blocked_count,created_at,updated_at) VALUES (?,?,?,?,?,?,?,NULL,?,0,0,0,?,?)',
                [$id,self::PROJECT,$admin,$connection,$plan,$tasksHash,$plan,'ready',$now,$now]);
            $this->insertTasks($id,$plan,$tasks,0,$now);
            $this->execute('UPDATE development_work_queues SET task_count=? WHERE queue_id=?',[count($tasks),$id]);
            return $this->project($this->selectNext($this->owned($id,$admin,$connection),$lane,$now))+['create_status'=>'created'];
        });
    }
    public function append(int $admin,string $connection,array $args): array
    {
        self::keys($args,['queue_id','expected_plan_sha256','plan_sha256','tasks']);
        $id=self::id($args['queue_id']??null);$expected=self::sha($args['expected_plan_sha256']??null);
        $plan=self::sha($args['plan_sha256']??null);$tasks=self::specs($args['tasks']??null);
        return $this->transaction(function() use($id,$expected,$plan,$tasks,$admin,$connection): array {
            $lane=$this->lane();$q=$this->owned($id,$admin,$connection,true);
            $tasksHash=self::hash($tasks);
            if ($q['plan_sha256']===$plan) {
                $audit=$this->appendMetadata($q);
                if ($audit===null||$audit['expected_plan_sha256']!==$expected||$audit['tasks_sha256']!==$tasksHash||$audit['task_count']!==count($tasks)) throw new DevelopmentWorkQueueConflict('plan_conflict');
                return $this->project($q)+['append_status'=>'already_recorded'];
            }
            if ($q['plan_sha256']!==$expected||$expected===$plan||$plan===$q['initial_plan_sha256']
                ||$this->one('SELECT plan_sha256 FROM development_work_appends WHERE queue_id=? AND plan_sha256=?',[$id,$plan])) throw new DevelopmentWorkQueueConflict('plan_conflict');
            $count=(int)$q['task_count'];
            if ($count+count($tasks)>self::MAX_ITEMS) throw new DevelopmentWorkQueueConflict('task_limit_reached');
            if ($q['current_task_id']!==null) $this->ownsLane($q,$lane);
            elseif ($lane['queue_id']!==null&&$lane['queue_id']!==$id) throw new DevelopmentWorkQueueConflict('development_lane_busy');
            $now=$this->now();$this->insertTasks($id,$plan,$tasks,$count,$now);
            $this->execute('INSERT INTO development_work_appends (queue_id,plan_sha256,expected_plan_sha256,tasks_sha256,task_count,created_at) VALUES (?,?,?,?,?,?)',[$id,$plan,$expected,$tasksHash,count($tasks),$now]);
            $this->execute('UPDATE development_work_queues SET plan_sha256=?,task_count=?,updated_at=? WHERE queue_id=?',[$plan,$count+count($tasks),$now,$id]);
            $q=$this->owned($id,$admin,$connection);
            if ($q['current_task_id']===null) $q=$this->selectNext($q,$lane,$now);
            return $this->project($q)+['append_status'=>'appended'];
        });
    }
    public function status(int $admin,string $connection,string $queueId): array
    {
        $id=self::id($queueId);
        return $this->transaction(function() use($id,$admin,$connection): array {$this->lane();return $this->project($this->owned($id,$admin,$connection,true));});
    }
    public function claim(int $admin,string $connection,array $args): array
    {
        self::keys($args,['queue_id','task_id','spec_sha256','claim_key','expected_pending_event_id']);
        [$id,$task,$spec,$claim]=$this->claimArgs($args);
        $event=$args['expected_pending_event_id']??null;
        if (!is_string($event)||preg_match('/\Adev_evt_[a-f0-9]{64}\z/D',$event)!==1) throw new InvalidArgumentException('invalid_development_work_pending_event');
        return $this->transaction(function() use($id,$task,$spec,$claim,$event,$admin,$connection): array {
            $lane=$this->lane();$q=$this->owned($id,$admin,$connection,true);$this->ownsLane($q,$lane);
            $item=$this->current($q,$task,$spec);
            if (!hash_equals($this->eventId($q,$item,false),$event)) throw new DevelopmentWorkQueueConflict('stale_pending_event');
            if ($item['state']==='claimed'&&hash_equals((string)$item['claim_sha256'],$claim)) return $this->project($q)+['claim_status'=>'already_claimed_do_not_replay'];
            if ($q['state']!=='ready'||$item['state']!=='ready') throw new DevelopmentWorkQueueConflict('not_ready');
            $this->assertDependenciesAccepted($q,$item);
            $now=$this->now();$due=$this->date($this->timestamp()+self::WATCHDOG_SECONDS);
            $this->execute('UPDATE development_work_items SET state=?,claim_sha256=?,watchdog_due_at=?,updated_at=? WHERE queue_id=? AND task_id=?',['claimed',$claim,$due,$now,$id,$task]);
            $this->execute('UPDATE development_work_queues SET state=?,updated_at=? WHERE queue_id=?',['claimed',$now,$id]);
            return $this->project($this->owned($id,$admin,$connection))+['claim_status'=>'claimed'];
        });
    }
    public function started(int $admin,string $connection,array $args): array
    {
        self::keys($args,['queue_id','task_id','spec_sha256','claim_key','run_id']);
        [$id,$task,$spec,$claim]=$this->claimArgs($args);$run=self::run($args['run_id']??null);
        return $this->transaction(function() use($id,$task,$spec,$claim,$run,$admin,$connection): array {
            $lane=$this->lane();$q=$this->owned($id,$admin,$connection,true);$this->ownsLane($q,$lane);
            $item=$this->current($q,$task,$spec);$this->claimed($item,$claim,false);
            if ($item['current_run_id']!==null&&$item['current_run_id']!==$run) throw new DevelopmentWorkQueueConflict('run_conflict');
            if ($item['current_run_id']===null) $this->execute('UPDATE development_work_items SET current_run_id=?,watchdog_due_at=?,updated_at=? WHERE queue_id=? AND task_id=?',[$run,$this->date($this->timestamp()+self::WATCHDOG_SECONDS),$this->now(),$id,$task]);
            return $this->project($q);
        });
    }
    public function complete(int $admin,string $connection,array $args): array
    {
        self::keys($args,['queue_id','task_id','spec_sha256','claim_key','receipt']);
        [$id,$task,$spec,$claim]=$this->claimArgs($args);$receipt=self::receipt($args['receipt']??null);$json=self::canonical($receipt);$hash=hash('sha256',$json);
        return $this->transaction(function() use($id,$task,$spec,$claim,$receipt,$json,$hash,$admin,$connection): array {
            $lane=$this->lane();$q=$this->owned($id,$admin,$connection,true);$item=$this->exactItem($id,$task,$spec);
            $old=$this->one('SELECT receipt_sha256 FROM development_work_receipts WHERE queue_id=? AND task_id=?',[$id,$task]);
            if ($old) {
                if ($old['receipt_sha256']!==$hash) throw new DevelopmentWorkQueueConflict('completion_conflict');
                return $this->project($q)+['completion_status'=>'already_recorded','receipt_sha256'=>$hash,'recorded_receipt'=>$this->receiptMetadata($q,$task,false)];
            }
            $this->ownsLane($q,$lane);$item=$this->current($q,$task,$spec);$this->claimed($item,$claim,false);
            if ($item['current_run_id']!==$receipt['run_id']) throw new DevelopmentWorkQueueConflict('run_mismatch');
            $now=$this->now();
            $this->execute('INSERT INTO development_work_receipts (queue_id,task_id,spec_sha256,receipt_sha256,receipt_json,created_at) VALUES (?,?,?,?,?,?)',[$id,$task,$spec,$hash,$json,$now]);
            $this->execute('UPDATE development_work_queues SET last_receipt_task_id=? WHERE queue_id=?',[$task,$id]);
            if ($receipt['outcome']==='unknown') {
                $this->execute('UPDATE development_work_items SET state=?,watchdog_due_at=?,updated_at=? WHERE queue_id=? AND task_id=?',['unknown',$this->date($this->timestamp()+self::WATCHDOG_SECONDS),$now,$id,$task]);
                $this->execute('UPDATE development_work_queues SET state=?,updated_at=? WHERE queue_id=?',['unknown',$now,$id]);
                return $this->project($this->owned($id,$admin,$connection))+['completion_status'=>'held_unknown_no_retry','receipt_sha256'=>$hash];
            }
            return $this->project($this->finish($q,$item,$receipt,$lane,$now))+['completion_status'=>'receipt_attested_not_independently_verified','receipt_sha256'=>$hash];
        });
    }
    public function resolve_existing_run(int $admin,string $connection,array $args): array
    {
        self::keys($args,['queue_id','task_id','spec_sha256','claim_key','unknown_receipt_sha256','expected_run_id','receipt']);
        [$id,$task,$spec,$claim]=$this->claimArgs($args);$unknown=self::sha($args['unknown_receipt_sha256']??null);$run=self::run($args['expected_run_id']??null);
        $receipt=self::receipt($args['receipt']??null);
        if (!in_array($receipt['outcome'],['accepted_readback','terminal_blocked'],true)||$receipt['run_id']!==$run) throw new InvalidArgumentException('invalid_development_work_resolution');
        $json=self::canonical($receipt);$hash=hash('sha256',$json);$resolution=self::hash([$id,$task,$spec,$unknown,$run,$hash]);
        return $this->transaction(function() use($id,$task,$spec,$claim,$unknown,$run,$receipt,$json,$hash,$resolution,$admin,$connection): array {
            $lane=$this->lane();$q=$this->owned($id,$admin,$connection,true);$item=$this->exactItem($id,$task,$spec);
            $old=$this->one('SELECT resolution_sha256 FROM development_work_resolutions WHERE queue_id=? AND task_id=?',[$id,$task]);
            if ($old) {
                if ($old['resolution_sha256']!==$resolution) throw new DevelopmentWorkQueueConflict('resolution_conflict');
                return $this->project($q)+['resolution_status'=>'already_recorded','receipt_sha256'=>$hash,'recorded_resolution'=>$this->receiptMetadata($q,$task,true)];
            }
            $this->ownsLane($q,$lane);$item=$this->current($q,$task,$spec);$this->claimed($item,$claim,true);
            if ($q['state']!=='unknown'||$item['state']!=='unknown'||$item['current_run_id']!==$run) throw new DevelopmentWorkQueueConflict('unknown_run_not_bound');
            $prior=$this->one('SELECT receipt_sha256,receipt_json FROM development_work_receipts WHERE queue_id=? AND task_id=?',[$id,$task]);
            if (!$prior||$prior['receipt_sha256']!==$unknown) throw new DevelopmentWorkQueueConflict('unknown_receipt_mismatch');
            $before=self::receipt(json_decode($prior['receipt_json'],true,16,JSON_THROW_ON_ERROR));
            if ($before['outcome']!=='unknown'||$before['run_id']!==$run) throw new DevelopmentWorkQueueConflict('unknown_run_not_bound');
            if ($before['result_sha256']!==null&&$before['result_sha256']!==$receipt['result_sha256']) throw new DevelopmentWorkQueueConflict('result_mismatch');
            $now=$this->now();
            $this->execute('INSERT INTO development_work_resolutions (queue_id,task_id,unknown_receipt_sha256,resolution_sha256,receipt_sha256,receipt_json,created_at) VALUES (?,?,?,?,?,?,?)',[$id,$task,$unknown,$resolution,$hash,$json,$now]);
            $this->execute('UPDATE development_work_queues SET last_resolution_task_id=? WHERE queue_id=?',[$task,$id]);
            return $this->project($this->finish($q,$item,$receipt,$lane,$now))+['resolution_status'=>'existing_run_receipt_attested','receipt_sha256'=>$hash];
        });
    }
    public function retire_unclaimed(int $admin,string $connection,array $args): array
    {
        $intent=self::retirementIntent($args);$id=$intent['queue_id'];$task=$intent['task_id'];$spec=$intent['spec_sha256'];
        $json=self::canonical($intent);$hash=self::hash(['namespace'=>'development_work_retirement_v1','intent'=>$intent]);
        return $this->transaction(function() use($id,$task,$spec,$intent,$json,$hash,$admin,$connection): array {
            $lane=$this->lane();$q=$this->owned($id,$admin,$connection,true);
            $prior=$this->one('SELECT retirement_sha256 FROM development_work_retirements WHERE queue_id=? AND task_id=?',[$id,$task]);
            if ($prior) {
                $record=$this->retirementMetadata($q,$task);
                if (!hash_equals($record['retirement_sha256'],$hash)) throw new DevelopmentWorkQueueConflict('retirement_conflict');
                return $this->project($q)+['retirement_status'=>'already_recorded','retirement_sha256'=>$hash,'recorded_retirement'=>$record];
            }
            $this->ownsLane($q,$lane);$item=$this->current($q,$task,$spec);
            if ($q['plan_sha256']!==$intent['expected_plan_sha256']) throw new DevelopmentWorkQueueConflict('plan_conflict');
            if (!hash_equals($this->eventId($q,$item,false),$intent['expected_pending_event_id'])) throw new DevelopmentWorkQueueConflict('stale_pending_event');
            if ($q['state']!=='ready'||$item['state']!=='ready'||$item['claim_sha256']!==null||$item['current_run_id']!==null) throw new DevelopmentWorkQueueConflict('not_known_idle');
            if ($this->one('SELECT task_id FROM development_work_receipts WHERE queue_id=? AND task_id=?',[$id,$task])
                ||$this->one('SELECT task_id FROM development_work_resolutions WHERE queue_id=? AND task_id=?',[$id,$task])) throw new DevelopmentWorkQueueConflict('current_receipt_exists');
            if ($this->one('SELECT queue_id FROM development_work_items WHERE state IN (?,?) OR claim_sha256 IS NOT NULL OR current_run_id IS NOT NULL LIMIT 1',['claimed','unknown'])
                ||$this->one('SELECT queue_id FROM development_work_queues WHERE state IN (?,?) LIMIT 1',['claimed','unknown'])) throw new DevelopmentWorkQueueConflict('development_work_unresolved');
            $now=$this->now();$decision=$intent['decision'];
            $this->execute('INSERT INTO development_work_retirements (queue_id,task_id,spec_sha256,expected_plan_sha256,expected_pending_event_id,decision_sha256,readback_sha256,reviewer_run_id,retirement_sha256,retirement_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$id,$task,$spec,$intent['expected_plan_sha256'],$intent['expected_pending_event_id'],$decision['decision_sha256'],$decision['readback_sha256'],$decision['reviewer_run_id'],$hash,$json,$now]);
            $this->execute('UPDATE development_work_items SET state=?,event_ack=NULL,delivered_at=NULL,claim_deadline=NULL,attention_at=NULL,attention_ack=NULL,watchdog_due_at=NULL,watchdog_epoch=0,last_watch_ack=NULL,updated_at=? WHERE queue_id=? AND task_id=?',['blocked',$now,$id,$task]);
            $this->execute('UPDATE development_work_queues SET current_task_id=NULL,state=?,blocked_count=blocked_count+1,retirement_count=retirement_count+1,last_retirement_task_id=?,updated_at=? WHERE queue_id=?',['ready',$task,$now,$id]);
            $next=$this->selectNext($this->owned($id,$admin,$connection),$lane,$now);
            return $this->project($next)+['retirement_status'=>'retired_unclaimed_not_worker_receipt','retirement_sha256'=>$hash,'recorded_retirement'=>$this->retirementMetadata($next,$task)];
        });
    }

    /** Bounded administrative recovery metadata, never a signed pending event. */
    public function handoff_preflight(int $admin,string $connection,array $args,callable $authority): array
    {
        self::keys($args,['queue_id','old_connection_id','expected_caller_connection_id']);
        [$id,$old]=$this->handoffCoordinates($admin,$connection,$args);
        return $this->transaction(function() use($id,$old,$admin,$connection,$authority): array {
            $lane=$this->lane();$q=$this->handoffQueue($id,$admin);
            $record=$this->handoffRecord($q);
            if ($record!==null) {
                $this->authorize($q,$admin,$connection);
                if ($record['old_connection_id']!==$old) throw new DevelopmentWorkQueueConflict('handoff_conflict');
                $this->handoffAuthority($authority,$old,$admin,true);
                $result=$this->project($q)+['handoff_status'=>'already_recorded','eligible'=>false,
                    'dispatch_allowed'=>false,'preflight_semantics'=>'administrative_recovery_metadata_not_dispatch_evidence'];
                $this->handoffAuthority($authority,$old,$admin,true);
                return $result;
            }
            if (!hash_equals($q['oauth_connection_id'],$old)) throw new DomainException('development_work_access_denied');
            $this->handoffAuthority($authority,$old,$admin,false);
            try {
                $item=$this->initialHandoffItem($q,$lane);
                $result=['ok'=>true,'eligible'=>true,'queue_id'=>$id,'task_id'=>$item['task_id'],
                    'spec_sha256'=>$item['spec_sha256'],'expected_plan_sha256'=>$q['plan_sha256'],
                    'expected_pending_event_id'=>$this->eventId($q,$item,false),
                    'old_connection_id'=>$old,'expected_caller_connection_id'=>$connection,
                    'initial_plan_sha256'=>$q['initial_plan_sha256'],'task_count'=>1,'accepted_count'=>0,
                    'blocked_count'=>0,'worker_receipt_count'=>0,'retirement_count'=>0,
                    'owner_task_id'=>$item['owner_task_id'],'reviewer_task_id'=>$item['reviewer_task_id'],
                    'input_sha256'=>$item['input_sha256'],'acceptance_contract_sha256'=>$item['acceptance_contract_sha256'],
                    'dispatch_allowed'=>false,'preflight_semantics'=>'administrative_recovery_metadata_not_dispatch_evidence'];
            } catch (DevelopmentWorkQueueConflict $ineligible) {
                $result=['ok'=>true,'eligible'=>false,'reason'=>$ineligible->reason,'queue_id'=>$id,
                    'dispatch_allowed'=>false,'preflight_semantics'=>'administrative_recovery_metadata_not_dispatch_evidence'];
            }
            $this->handoffAuthority($authority,$old,$admin,false);
            return $result;
        });
    }

    /** Retire one untouched initial task and change only its metadata authority. */
    public function handoff_connection(int $admin,string $connection,array $args,callable $authority): array
    {
        self::keys($args,['queue_id','task_id','spec_sha256','expected_plan_sha256','expected_pending_event_id',
            'old_connection_id','expected_caller_connection_id','decision']);
        [$id,$old]=$this->handoffCoordinates($admin,$connection,$args);
        $retirementArgs=$args;unset($retirementArgs['old_connection_id'],$retirementArgs['expected_caller_connection_id']);
        $retirement=self::retirementIntent($retirementArgs);
        $intent=['project_key'=>self::PROJECT,'admin_id'=>$admin,'old_connection_id'=>$old,
            'new_connection_id'=>$connection,'retirement'=>$retirement];
        $json=self::canonical($intent);
        if (strlen($json)>4096) throw new InvalidArgumentException('invalid_development_work_handoff');
        $hash=self::hash(['namespace'=>'development_work_idle_connection_handoff_v1','intent'=>$intent]);
        $retirementHash=self::hash(['namespace'=>'development_work_retirement_v1','intent'=>$retirement]);
        return $this->transaction(function() use($id,$old,$admin,$connection,$authority,$retirement,$retirementHash,$intent,$json,$hash): array {
            $lane=$this->lane();$q=$this->handoffQueue($id,$admin);
            // The digest is not authority: fresh current ownership comes first.
            $record=$this->handoffRecord($q);
            if ($record!==null) {
                $this->authorize($q,$admin,$connection);$this->handoffAuthority($authority,$old,$admin,true);
                if (!hash_equals($record['handoff_sha256'],$hash)) throw new DevelopmentWorkQueueConflict('handoff_conflict');
                $result=$this->project($q)+['handoff_status'=>'already_recorded','dispatch_allowed'=>false];
                $this->handoffAuthority($authority,$old,$admin,true);return $result;
            }
            if (!hash_equals($q['oauth_connection_id'],$old)) throw new DomainException('development_work_access_denied');
            $this->handoffAuthority($authority,$old,$admin,false);
            $item=$this->initialHandoffItem($q,$lane);
            if ($q['plan_sha256']!==$retirement['expected_plan_sha256']) throw new DevelopmentWorkQueueConflict('plan_conflict');
            if ($item['task_id']!==$retirement['task_id']||$item['spec_sha256']!==$retirement['spec_sha256'])
                throw new DevelopmentWorkQueueConflict('stale_task');
            if (!hash_equals($this->eventId($q,$item,false),$retirement['expected_pending_event_id']))
                throw new DevelopmentWorkQueueConflict('stale_pending_event');
            $now=$this->now();$decision=$retirement['decision'];
            $this->execute('INSERT INTO development_work_retirements (queue_id,task_id,spec_sha256,expected_plan_sha256,expected_pending_event_id,decision_sha256,readback_sha256,reviewer_run_id,retirement_sha256,retirement_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$id,$item['task_id'],$item['spec_sha256'],$retirement['expected_plan_sha256'],$retirement['expected_pending_event_id'],
                    $decision['decision_sha256'],$decision['readback_sha256'],$decision['reviewer_run_id'],$retirementHash,self::canonical($retirement),$now]);
            $this->execute('UPDATE development_work_items SET state=?,event_ack=NULL,delivered_at=NULL,claim_deadline=NULL,attention_at=NULL,attention_ack=NULL,watchdog_due_at=NULL,watchdog_epoch=0,last_watch_ack=NULL,updated_at=? WHERE queue_id=? AND task_id=?',
                ['blocked',$now,$id,$item['task_id']]);
            $this->execute('UPDATE development_work_queues SET current_task_id=NULL,state=?,blocked_count=blocked_count+1,retirement_count=retirement_count+1,last_retirement_task_id=?,updated_at=? WHERE queue_id=?',
                ['blocked',$item['task_id'],$now,$id]);
            // Terminal release only. Never select or clear another queue's lane.
            $this->execute('UPDATE development_work_lane SET queue_id=NULL WHERE lane_key=? AND queue_id=?',['DEVELOPMENT',$id]);
            $this->execute('INSERT INTO development_work_connection_handoffs (queue_id,handoff_sha256,admin_id,old_connection_id,new_connection_id,task_id,spec_sha256,expected_plan_sha256,expected_pending_event_id,retirement_sha256,intent_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$id,$hash,$admin,$old,$connection,$item['task_id'],$item['spec_sha256'],$retirement['expected_plan_sha256'],
                    $retirement['expected_pending_event_id'],$retirementHash,$json,$now]);
            // A point-in-time cleanup, not proof that a queued callback vanished.
            $this->execute('UPDATE development_work_events SET is_active=0,single_consumer_key=NULL,lease_token=NULL,lease_until=NULL,updated_at=? WHERE project_key=? AND admin_id=? AND oauth_connection_id=? AND queue_id=?',
                [$now,self::PROJECT,$admin,$old,$id]);
            $this->execute('UPDATE development_work_queues SET oauth_connection_id=?,updated_at=? WHERE queue_id=?',[$connection,$now,$id]);
            $this->handoffAuthority($authority,$old,$admin,false);
            $next=$this->owned($id,$admin,$connection);
            return $this->project($next)+['handoff_status'=>'retired_unclaimed_connection_handed_off','handoff_sha256'=>$hash,
                'retirement_sha256'=>$retirementHash,'retired_task_id'=>$item['task_id'],'dispatch_allowed'=>false,
                'handoff_semantics'=>'administrative_retirement_not_worker_acceptance'];
        });
    }

    private function handoffCoordinates(int $admin,string $connection,array $args): array
    {
        self::actor($admin,$connection);$id=self::id($args['queue_id']??null);
        foreach (['old_connection_id','expected_caller_connection_id'] as $key)
            if (!is_string($args[$key]??null)||preg_match('/\A[a-f0-9]{32}\z/D',$args[$key])!==1)
                throw new InvalidArgumentException('invalid_development_work_connection');
        if (!hash_equals($connection,$args['expected_caller_connection_id']))
            throw new DevelopmentWorkQueueConflict('caller_connection_changed');
        if (hash_equals($connection,$args['old_connection_id'])) throw new InvalidArgumentException('same_development_work_connection');
        return [$id,$args['old_connection_id']];
    }

    private function handoffQueue(string $id,int $admin): array
    {
        $q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=? AND project_key=? AND admin_id=?'.$this->lock(),[$id,self::PROJECT,$admin]);
        if ($q===null) throw new DomainException('development_work_access_denied');
        return $q;
    }

    private function handoffAuthority(callable $authority,string $old,int $admin,bool $recorded): void
    {
        if ($authority($old,$admin,$recorded)!==true) throw new DomainException('development_work_access_denied');
    }

    private function initialHandoffItem(array $q,array $lane): array
    {
        $this->ownsLane($q,$lane);
        if ($q['state']!=='ready'||$q['current_task_id']===null||(int)$q['task_count']!==1
            ||(int)$q['accepted_count']!==0||(int)$q['blocked_count']!==0||(int)$q['retirement_count']!==0
            ||$q['plan_sha256']!==$q['initial_plan_sha256']||$q['last_receipt_task_id']!==null
            ||$q['last_resolution_task_id']!==null||$q['last_retirement_task_id']!==null)
            throw new DevelopmentWorkQueueConflict('unsupported_initial_queue');
        $qItems=$this->pdo->prepare('SELECT * FROM development_work_items WHERE queue_id=? ORDER BY ordinal LIMIT 2');
        if (!$qItems||!$qItems->execute([$q['queue_id']])) throw new RuntimeException('development_work_storage_unavailable');
        $items=$qItems->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($items)) throw new RuntimeException('development_work_storage_unavailable');
        if (count($items)!==1) throw new DevelopmentWorkQueueConflict('unsupported_initial_queue');
        $item=$this->current($q);
        if ($item['state']!=='ready'||$item['claim_sha256']!==null||$item['current_run_id']!==null)
            throw new DevelopmentWorkQueueConflict('not_known_idle');
        $spec=$this->storedSpec($item);
        if ((int)$item['ordinal']!==0||$item['plan_sha256']!==$q['initial_plan_sha256']
            ||!hash_equals(self::hash([$spec]),$q['initial_tasks_sha256']))
            throw new DevelopmentWorkQueueConflict('unsupported_initial_queue');
        foreach (['development_work_appends','development_work_retirements','development_work_receipts','development_work_resolutions'] as $table)
            if ($this->one('SELECT queue_id FROM '.$table.' WHERE queue_id=? LIMIT 1',[$q['queue_id']]))
                throw new DevelopmentWorkQueueConflict('initial_queue_history_exists');
        if ($this->one('SELECT queue_id FROM development_work_items WHERE state IN (?,?) OR claim_sha256 IS NOT NULL OR current_run_id IS NOT NULL LIMIT 1',['claimed','unknown'])
            ||$this->one('SELECT queue_id FROM development_work_queues WHERE state IN (?,?) LIMIT 1',['claimed','unknown']))
            throw new DevelopmentWorkQueueConflict('development_work_unresolved');
        return $item;
    }

    /** Validated immutable proof for only this queue's one permitted handoff. */
    private function handoffRecord(array $q): ?array
    {
        $query=$this->pdo->prepare('SELECT * FROM development_work_connection_handoffs WHERE queue_id=? LIMIT 2');
        if (!$query||!$query->execute([$q['queue_id']])) throw new RuntimeException('development_work_storage_unavailable');
        $records=$query->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($records)) throw new RuntimeException('development_work_storage_unavailable');
        if ($records===[]) return null;
        if (count($records)!==1) throw new RuntimeException('development_work_stored_handoff_invalid');
        $r=$records[0];
        try {
            if (!is_string($r['intent_json']??null)||strlen($r['intent_json'])>4096
                ||!is_string($r['created_at']??null)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D',$r['created_at'])!==1)
                throw new RuntimeException();
            $intent=json_decode($r['intent_json'],true,16,JSON_THROW_ON_ERROR);
            if (!is_array($intent)||count($intent)!==5||array_diff(['project_key','admin_id','old_connection_id','new_connection_id','retirement'],array_keys($intent))
                ||$intent['project_key']!==self::PROJECT||!is_int($intent['admin_id'])||$intent['admin_id']!==(int)$q['admin_id'])
                throw new RuntimeException();
            foreach (['old_connection_id','new_connection_id'] as $field)
                if (!is_string($intent[$field])||preg_match('/\A[a-f0-9]{32}\z/D',$intent[$field])!==1) throw new RuntimeException();
            if ($intent['old_connection_id']===$intent['new_connection_id']||$q['oauth_connection_id']!==$intent['new_connection_id'])
                throw new RuntimeException();
            $retirement=self::retirementIntent($intent['retirement']);
            $hash=self::hash(['namespace'=>'development_work_idle_connection_handoff_v1','intent'=>$intent]);
            $retirementHash=self::hash(['namespace'=>'development_work_retirement_v1','intent'=>$retirement]);
            if (self::canonical($intent)!==$r['intent_json']||$retirement['queue_id']!==$q['queue_id']
                ||$r['handoff_sha256']!==$hash||$r['retirement_sha256']!==$retirementHash||(string)$r['admin_id']!==(string)$intent['admin_id']
                ||$r['old_connection_id']!==$intent['old_connection_id']||$r['new_connection_id']!==$intent['new_connection_id']
                ||$r['task_id']!==$retirement['task_id']||$r['spec_sha256']!==$retirement['spec_sha256']
                ||$r['expected_plan_sha256']!==$retirement['expected_plan_sha256']
                ||$r['expected_pending_event_id']!==$retirement['expected_pending_event_id'])
                throw new RuntimeException();
            $stored=$this->one('SELECT retirement_sha256,retirement_json FROM development_work_retirements WHERE queue_id=? AND task_id=?',[$q['queue_id'],$r['task_id']]);
            if (!$stored||$stored['retirement_sha256']!==$retirementHash||$stored['retirement_json']!==self::canonical($retirement))
                throw new RuntimeException();
        } catch (Throwable $invalid) {throw new RuntimeException('development_work_stored_handoff_invalid');}
        return $r;
    }

    private function handoffMetadata(array $q): ?array
    {
        $r=$this->handoffRecord($q);
        if ($r===null) return null;
        return ['handoff_sha256'=>$r['handoff_sha256'],'task_id'=>$r['task_id'],'spec_sha256'=>$r['spec_sha256'],
            'expected_plan_sha256'=>$r['expected_plan_sha256'],'retirement_sha256'=>$r['retirement_sha256'],
            'created_at'=>$r['created_at'],'record_type'=>'administrative_retirement_not_worker_acceptance','dispatch_allowed'=>false];
    }

    public function eventCandidates(int $limit=20): array
    {
        $this->outsideTransaction();if ($limit<1||$limit>20) throw new InvalidArgumentException('invalid_development_work_limit');
        $q=$this->pdo->prepare('SELECT q.queue_id,q.admin_id,q.oauth_connection_id FROM development_work_lane l JOIN development_work_queues q ON q.queue_id=l.queue_id WHERE l.lane_key=? AND q.project_key=? AND q.state IN (?,?,?) LIMIT '.$limit);
        $q->execute(['DEVELOPMENT',self::PROJECT,'ready','claimed','unknown']);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function pendingEvent(int $admin,string $connection,string $queueId): ?array
    {
        $id=self::id($queueId);
        return $this->transaction(function() use($id,$admin,$connection): ?array {
            $lane=$this->lane();$q=$this->owned($id,$admin,$connection,true);
            if (!in_array($q['state'],['ready','claimed','unknown'],true)) return null;
            $this->ownsLane($q,$lane);$item=$this->current($q);
            if (in_array($item['state'],['claimed','unknown'],true)) {
                if ($item['watchdog_due_at']===null) {
                    $this->execute('UPDATE development_work_items SET watchdog_due_at=? WHERE queue_id=? AND task_id=?',[$this->date($this->timestamp()+self::WATCHDOG_SECONDS),$id,$item['task_id']]);return null;
                }
                if ($item['watchdog_due_at']>$this->now()) return null;
                return ['event_id'=>$this->watchEventId($q,$item),'name'=>'development_work.attention','data'=>$this->eventData($q,$item,false)];
            }
            $this->assertDependenciesAccepted($q,$item);
            $event=$this->eventId($q,$item,false);
            if ($item['event_ack']===$event) {
                if ($item['claim_deadline']===null||$item['claim_deadline']>$this->now()) return null;
                if ($item['attention_at']===null) {
                    $at=$this->now();$this->execute('UPDATE development_work_items SET attention_at=?,updated_at=? WHERE queue_id=? AND task_id=?',[$at,$at,$id,$item['task_id']]);
                }
                $attention=$this->eventId($q,$item,true);
                if ($item['attention_ack']===$attention) return null;
                return ['event_id'=>$attention,'name'=>'development_work.attention','data'=>$this->eventData($q,$item,false)];
            }
            return ['event_id'=>$event,'name'=>'development_work.pending','data'=>$this->eventData($q,$item,true)];
        });
    }
    public function markEventDelivered(int $admin,string $connection,string $queueId,string $eventId): array
    {
        $id=self::id($queueId);
        return $this->transaction(function() use($id,$eventId,$admin,$connection): array {
            $lane=$this->lane();$q=$this->owned($id,$admin,$connection,true);
            if ($q['current_task_id']===null||!in_array($q['state'],['ready','claimed','unknown'],true)) throw new DevelopmentWorkQueueConflict('stale_event');
            $this->ownsLane($q,$lane);$item=$this->current($q);$now=$this->now();
            if (in_array($item['state'],['claimed','unknown'],true)) {
                if ($item['last_watch_ack']===$eventId) return $this->project($q);
                if ($eventId!==$this->watchEventId($q,$item)||$item['watchdog_due_at']===null||$item['watchdog_due_at']>$now) throw new DevelopmentWorkQueueConflict('stale_event');
                if ((int)$item['watchdog_epoch']>=1000000) throw new DevelopmentWorkQueueConflict('watchdog_limit_reached');
                $this->execute('UPDATE development_work_items SET last_watch_ack=?,watchdog_epoch=watchdog_epoch+1,watchdog_due_at=?,updated_at=? WHERE queue_id=? AND task_id=?',[$eventId,$this->date($this->timestamp()+self::WATCHDOG_SECONDS),$now,$id,$item['task_id']]);
            } elseif ($item['state']==='ready') {
                if ($eventId===$this->eventId($q,$item,false)) {
                    if ($item['event_ack']===null) $this->execute('UPDATE development_work_items SET event_ack=?,delivered_at=?,claim_deadline=?,updated_at=? WHERE queue_id=? AND task_id=?',[$eventId,$now,$this->date($this->timestamp()+self::CLAIM_DEADLINE_SECONDS),$now,$id,$item['task_id']]);
                } elseif ($eventId===$this->eventId($q,$item,true)&&$item['attention_at']!==null) {
                    $this->execute('UPDATE development_work_items SET attention_ack=?,updated_at=? WHERE queue_id=? AND task_id=?',[$eventId,$now,$id,$item['task_id']]);
                } else throw new DevelopmentWorkQueueConflict('stale_event');
            } else throw new DevelopmentWorkQueueConflict('stale_event');
            return $this->project($q);
        });
    }
    private function insertTasks(string $id,string $plan,array $tasks,int $ordinal,string $now): void
    {
        $known=[];$q=$this->pdo->prepare('SELECT task_id FROM development_work_items WHERE queue_id=? LIMIT 2001');$q->execute([$id]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $task) $known[$task]=true;
        if (count($known)!==$ordinal) throw new RuntimeException('development_work_stored_state_invalid');
        foreach ($tasks as $spec) {
            if (isset($known[$spec['task_id']])) throw new DevelopmentWorkQueueConflict('immutable_task_exists');
            foreach ($spec['deps'] as $dep) if (!isset($known[$dep])) throw new InvalidArgumentException('invalid_development_work_dependency');
            $this->execute('INSERT INTO development_work_items (queue_id,task_id,ordinal,plan_sha256,spec_sha256,owner_task_id,reviewer_task_id,input_sha256,acceptance_contract_sha256,deps_json,priority,state,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$id,$spec['task_id'],$ordinal++,$plan,self::specHash($plan,$spec),$spec['owner_task_id'],$spec['reviewer_task_id'],$spec['input_sha256'],$spec['acceptance_contract_sha256'],self::canonical($spec['deps']),$spec['priority'],'pending',$now,$now]);
            $known[$spec['task_id']]=true;
        }
    }
    private function selectNext(array $q,array $lane,string $now): array
    {
        if ($q['current_task_id']!==null) return $q;
        $items=$this->allItems($q['queue_id']);$accepted=[];$blocked=0;
        if (count($items)!==(int)$q['task_count']) throw new RuntimeException('development_work_stored_state_invalid');
        foreach ($items as $item) {
            if (in_array($item['state'],['ready','claimed','unknown'],true)||$item['claim_sha256']!==null||$item['current_run_id']!==null) throw new RuntimeException('development_work_stored_state_invalid');
            if ($item['state']==='accepted') {$this->acceptedItemReceipt($q,$item);$accepted[$item['task_id']]=true;}
            elseif ($item['state']==='blocked') ++$blocked;
        }
        if (count($accepted)!==(int)$q['accepted_count']||$blocked!==(int)$q['blocked_count']) throw new RuntimeException('development_work_stored_state_invalid');
        $next=null;
        foreach ($items as $item) {
            if ($item['state']!=='pending') continue;$ready=true;
            foreach ($this->storedSpec($item)['deps'] as $dep) if (!isset($accepted[$dep])) {$ready=false;break;}
            if ($ready) {$next=$item;break;}
        }
        if ($next!==null) {
            $this->assertDependenciesAccepted($q,$next);
            if ($lane['queue_id']!==null&&$lane['queue_id']!==$q['queue_id']) throw new DevelopmentWorkQueueConflict('development_lane_busy');
            if ($this->one('SELECT queue_id FROM development_work_items WHERE queue_id<>? AND (state IN (?,?) OR claim_sha256 IS NOT NULL OR current_run_id IS NOT NULL) LIMIT 1',[$q['queue_id'],'claimed','unknown'])) throw new DevelopmentWorkQueueConflict('development_lane_busy');
            $this->execute('UPDATE development_work_lane SET queue_id=? WHERE lane_key=?',[$q['queue_id'],'DEVELOPMENT']);
            $this->execute('UPDATE development_work_items SET state=?,updated_at=? WHERE queue_id=? AND task_id=?',['ready',$now,$q['queue_id'],$next['task_id']]);
            $this->execute('UPDATE development_work_queues SET current_task_id=?,state=?,updated_at=? WHERE queue_id=?',[$next['task_id'],'ready',$now,$q['queue_id']]);
        } else {
            $state=(int)$q['accepted_count']===(int)$q['task_count']?'complete':'blocked';
            $this->execute('UPDATE development_work_queues SET current_task_id=NULL,state=?,updated_at=? WHERE queue_id=?',[$state,$now,$q['queue_id']]);
            $this->execute('UPDATE development_work_lane SET queue_id=NULL WHERE lane_key=? AND queue_id=?',['DEVELOPMENT',$q['queue_id']]);
        }
        return $this->owned($q['queue_id'],(int)$q['admin_id'],$q['oauth_connection_id']);
    }
    private function finish(array $q,array $item,array $receipt,array $lane,string $now): array
    {
        $accepted=$receipt['outcome']==='accepted_readback';$state=$accepted?'accepted':'blocked';
        $this->execute('UPDATE development_work_items SET state=?,claim_sha256=NULL,current_run_id=NULL,event_ack=NULL,delivered_at=NULL,claim_deadline=NULL,attention_at=NULL,attention_ack=NULL,watchdog_due_at=NULL,watchdog_epoch=0,last_watch_ack=NULL,updated_at=? WHERE queue_id=? AND task_id=?',[$state,$now,$q['queue_id'],$item['task_id']]);
        $this->execute('UPDATE development_work_queues SET current_task_id=NULL,state=?,accepted_count=accepted_count+?,blocked_count=blocked_count+?,updated_at=? WHERE queue_id=?',['ready',$accepted?1:0,$accepted?0:1,$now,$q['queue_id']]);
        return $this->selectNext($this->owned($q['queue_id'],(int)$q['admin_id'],$q['oauth_connection_id']),$lane,$now);
    }
    private function project(array $q): array
    {
        $state=$q['state'];
        if (!in_array($state,['ready','claimed','unknown','complete','blocked'],true)||(int)$q['task_count']<1||(int)$q['task_count']>self::MAX_ITEMS
            ||(int)$q['accepted_count']<0||(int)$q['blocked_count']<0||(int)$q['accepted_count']+(int)$q['blocked_count']>(int)$q['task_count']) throw new RuntimeException('development_work_stored_state_invalid');
        if (in_array($state,['ready','claimed','unknown'],true)!==($q['current_task_id']!==null)) throw new RuntimeException('development_work_stored_state_invalid');
        $item=$q['current_task_id']===null?null:$this->current($q);$unknown=null;
        if ($state==='unknown') {
            $r=$this->one('SELECT receipt_sha256 FROM development_work_receipts WHERE queue_id=? AND task_id=?',[$q['queue_id'],$q['current_task_id']]);
            if (!$r) throw new RuntimeException('development_work_unknown_receipt_unavailable');$unknown=$r['receipt_sha256'];
        }
        $workerReceipts=(int)$this->one('SELECT COUNT(*) AS n FROM development_work_receipts WHERE queue_id=?',[$q['queue_id']])['n'];
        $retirements=(int)$this->one('SELECT COUNT(*) AS n FROM development_work_retirements WHERE queue_id=?',[$q['queue_id']])['n'];
        if ($retirements!==(int)$q['retirement_count']||$retirements>(int)$q['blocked_count']
            ||$workerReceipts!==(int)$q['accepted_count']+(int)$q['blocked_count']-$retirements+($state==='unknown'?1:0)) throw new RuntimeException('development_work_stored_state_invalid');
        $out=['ok'=>true,'project_key'=>self::PROJECT,'queue_id'=>$q['queue_id'],'oauth_connection_id'=>$q['oauth_connection_id'],
            'plan_sha256'=>$q['plan_sha256'],'state'=>$state,'task_count'=>(int)$q['task_count'],'accepted_count'=>(int)$q['accepted_count'],'blocked_count'=>(int)$q['blocked_count'],
            'receipt_count'=>$workerReceipts,'worker_receipt_count'=>$workerReceipts,'retirement_count'=>$retirements,
            'unknown_receipt_sha256'=>$unknown,'receipt_semantics'=>'authenticated_attestation_not_independent_result_proof',
            'created_at'=>$q['created_at'],'updated_at'=>$q['updated_at']];
        foreach (['task_id','spec_sha256','owner_task_id','reviewer_task_id','input_sha256','acceptance_contract_sha256','current_run_id','claim_deadline','watchdog_due_at'] as $field) $out[$field]=$item[$field]??null;
        $out['deps']=$item===null?[]:$this->storedSpec($item)['deps'];$out['priority']=$item===null?null:(int)$item['priority'];
        $out['watchdog_epoch']=$item===null?0:(int)$item['watchdog_epoch'];
        $out['expected_pending_event_id']=$item!==null&&$state==='ready'&&$item['claim_sha256']===null&&$item['current_run_id']===null?$this->eventId($q,$item,false):null;
        $out['delivery_state']=$item===null?$state:($state!=='ready'?$state:($item['attention_at']!==null?'attention_required':($item['event_ack']!==null?'awaiting_claim':'ready')));
        $out['last_retirement']=$q['last_retirement_task_id']===null?null:$this->retirementMetadata($q,$q['last_retirement_task_id']);
        $out['last_connection_handoff']=$this->handoffMetadata($q);
        $out['last_append']=$this->appendMetadata($q);
        $out['last_receipt']=$q['last_receipt_task_id']===null?null:$this->receiptMetadata($q,$q['last_receipt_task_id'],false);
        $out['last_resolution']=$q['last_resolution_task_id']===null?null:$this->receiptMetadata($q,$q['last_resolution_task_id'],true);
        return $out;
    }
    private function retirementMetadata(array $q,string $task): array
    {
        $record=$this->one('SELECT * FROM development_work_retirements WHERE queue_id=? AND task_id=?',[$q['queue_id'],$task]);
        if (!$record||!is_string($record['retirement_json'])||!is_string($record['retirement_sha256'])) throw new RuntimeException('development_work_stored_retirement_invalid');
        $intent=self::retirementIntent(json_decode($record['retirement_json'],true,16,JSON_THROW_ON_ERROR));
        $hash=self::hash(['namespace'=>'development_work_retirement_v1','intent'=>$intent]);$item=$this->exactItem($q['queue_id'],$task,$intent['spec_sha256']);
        $eventQueue=$q;$handoff=$this->handoffRecord($q);
        if ($handoff!==null&&$handoff['task_id']===$task) {
            if ($handoff['retirement_sha256']!==$hash) throw new RuntimeException('development_work_stored_retirement_invalid');
            $eventQueue['oauth_connection_id']=$handoff['old_connection_id'];
        }
        if ($intent['queue_id']!==$q['queue_id']||$intent['task_id']!==$task||self::canonical($intent)!==$record['retirement_json']
            ||!hash_equals($hash,$record['retirement_sha256'])||$item['state']!=='blocked'||$item['claim_sha256']!==null||$item['current_run_id']!==null
            ||$record['spec_sha256']!==$intent['spec_sha256']||$record['expected_plan_sha256']!==$intent['expected_plan_sha256']
            ||$record['expected_pending_event_id']!==$intent['expected_pending_event_id']||$intent['expected_pending_event_id']!==$this->eventId($eventQueue,$item,false)
            ||$record['decision_sha256']!==$intent['decision']['decision_sha256']||$record['readback_sha256']!==$intent['decision']['readback_sha256']
            ||$record['reviewer_run_id']!==$intent['decision']['reviewer_run_id']
            ||$this->one('SELECT task_id FROM development_work_receipts WHERE queue_id=? AND task_id=?',[$q['queue_id'],$task])
            ||$this->one('SELECT task_id FROM development_work_resolutions WHERE queue_id=? AND task_id=?',[$q['queue_id'],$task])) throw new RuntimeException('development_work_stored_retirement_invalid');
        return $intent+['retirement_sha256'=>$hash,'created_at'=>$record['created_at'],'record_type'=>'metadata_retirement_not_worker_receipt'];
    }
    private static function retirementIntent(array $args): array
    {
        self::keys($args,['queue_id','task_id','spec_sha256','expected_plan_sha256','expected_pending_event_id','decision']);
        $event=$args['expected_pending_event_id']??null;$decision=$args['decision']??null;
        if (!is_string($event)||preg_match('/\Adev_evt_[a-f0-9]{64}\z/D',$event)!==1||!is_array($decision)||array_is_list($decision)
            ||count($decision)!==3||array_diff(['decision_sha256','readback_sha256','reviewer_run_id'],array_keys($decision))) throw new InvalidArgumentException('invalid_development_work_retirement');
        return ['queue_id'=>self::id($args['queue_id']??null),'task_id'=>self::id($args['task_id']??null),'spec_sha256'=>self::sha($args['spec_sha256']??null),
            'expected_plan_sha256'=>self::sha($args['expected_plan_sha256']??null),'expected_pending_event_id'=>$event,
            'decision'=>['decision_sha256'=>self::sha($decision['decision_sha256']),'readback_sha256'=>self::sha($decision['readback_sha256']),'reviewer_run_id'=>self::run($decision['reviewer_run_id'])]];
    }
    private function appendMetadata(array $q): ?array
    {
        $audit=$this->one('SELECT * FROM development_work_appends WHERE queue_id=? AND plan_sha256=?',[$q['queue_id'],$q['plan_sha256']]);
        if ($q['plan_sha256']===$q['initial_plan_sha256']) {
            if ($audit!==null) throw new RuntimeException('development_work_stored_append_invalid');
            return null;
        }
        if (!$audit||!is_string($audit['expected_plan_sha256'])||preg_match('/\A[a-f0-9]{64}\z/D',$audit['expected_plan_sha256'])!==1
            ||$audit['expected_plan_sha256']===$q['plan_sha256']||!is_string($audit['tasks_sha256'])||preg_match('/\A[a-f0-9]{64}\z/D',$audit['tasks_sha256'])!==1
            ||(string)(int)$audit['task_count']!==(string)$audit['task_count']||(int)$audit['task_count']<1||(int)$audit['task_count']>20) throw new RuntimeException('development_work_stored_append_invalid');
        $query=$this->pdo->prepare('SELECT * FROM development_work_items WHERE queue_id=? AND plan_sha256=? ORDER BY ordinal ASC LIMIT 21');
        $query->execute([$q['queue_id'],$q['plan_sha256']]);$items=$query->fetchAll(PDO::FETCH_ASSOC);$tasks=[];
        foreach ($items as $item) $tasks[]=$this->storedSpec($item);
        if (count($items)!==(int)$audit['task_count']||!hash_equals($audit['tasks_sha256'],self::hash($tasks))) throw new RuntimeException('development_work_stored_append_invalid');
        return ['queue_id'=>$q['queue_id'],'plan_sha256'=>$q['plan_sha256'],'expected_plan_sha256'=>$audit['expected_plan_sha256'],
            'tasks_sha256'=>$audit['tasks_sha256'],'task_count'=>(int)$audit['task_count'],'created_at'=>$audit['created_at']];
    }
    private function receiptMetadata(array $q,string $task,bool $resolution): array
    {
        $item=$this->exactItem($q['queue_id'],$task);$verified=$this->verifiedReceipt($q,$item,$resolution);
        $record=$verified['record'];$receipt=$verified['receipt'];
        $out=['task_id'=>$task,'spec_sha256'=>$item['spec_sha256'],'receipt_sha256'=>$record['receipt_sha256'],'created_at'=>$record['created_at'],'record_type'=>$resolution?'resolution':'receipt']+$receipt;
        if ($resolution) {$out['unknown_receipt_sha256']=$record['unknown_receipt_sha256'];$out['resolution_sha256']=$record['resolution_sha256'];}
        return $out;
    }
    private function assertDependenciesAccepted(array $q,array $item): void
    {
        foreach ($this->storedSpec($item)['deps'] as $task) {
            $dependency=$this->exactItem($q['queue_id'],$task);
            if ($dependency['state']!=='accepted') throw new DevelopmentWorkQueueConflict('dependency_not_accepted');
            $this->acceptedItemReceipt($q,$dependency);
        }
    }
    private function acceptedItemReceipt(array $q,array $item): void
    {
        $original=$this->verifiedReceipt($q,$item,false);
        if ($original['receipt']['outcome']==='accepted_readback') {
            if ($this->one('SELECT task_id FROM development_work_resolutions WHERE queue_id=? AND task_id=?',[$q['queue_id'],$item['task_id']])) throw new RuntimeException('development_work_stored_receipt_invalid');
            return;
        }
        if ($original['receipt']['outcome']!=='unknown') throw new RuntimeException('development_work_stored_receipt_invalid');
        $resolved=$this->verifiedReceipt($q,$item,true);
        if ($resolved['receipt']['outcome']!=='accepted_readback') throw new RuntimeException('development_work_stored_receipt_invalid');
    }
    private function verifiedReceipt(array $q,array $item,bool $resolution): array
    {
        $table=$resolution?'development_work_resolutions':'development_work_receipts';
        $record=$this->one('SELECT * FROM '.$table.' WHERE queue_id=? AND task_id=?',[$q['queue_id'],$item['task_id']]);
        if (!$record||!is_string($record['receipt_json'])||!is_string($record['receipt_sha256'])
            ||preg_match('/\A[a-f0-9]{64}\z/D',$record['receipt_sha256'])!==1
            ||(!$resolution&&$record['spec_sha256']!==$item['spec_sha256'])) throw new RuntimeException('development_work_stored_receipt_invalid');
        $receipt=self::receipt(json_decode($record['receipt_json'],true,16,JSON_THROW_ON_ERROR));$json=self::canonical($receipt);
        if ($json!==$record['receipt_json']||!hash_equals($record['receipt_sha256'],hash('sha256',$json))) throw new RuntimeException('development_work_stored_receipt_invalid');
        if ($resolution) {
            $original=$this->verifiedReceipt($q,$item,false);$before=$original['receipt'];
            if ($before['outcome']!=='unknown'||$before['run_id']===null||!in_array($receipt['outcome'],['accepted_readback','terminal_blocked'],true)
                ||$receipt['run_id']!==$before['run_id']||$record['unknown_receipt_sha256']!==$original['record']['receipt_sha256']
                ||($before['result_sha256']!==null&&$before['result_sha256']!==$receipt['result_sha256'])) throw new RuntimeException('development_work_stored_receipt_invalid');
            $expected=self::hash([$q['queue_id'],$item['task_id'],$item['spec_sha256'],$original['record']['receipt_sha256'],$before['run_id'],$record['receipt_sha256']]);
            if (!is_string($record['resolution_sha256'])||!hash_equals($expected,$record['resolution_sha256'])) throw new RuntimeException('development_work_stored_receipt_invalid');
        }
        return ['record'=>$record,'receipt'=>$receipt];
    }
    private function eventData(array $q,array $item,bool $dispatch): array
    {
        return ['queue_id'=>$q['queue_id'],'task_id'=>$item['task_id'],'spec_sha256'=>$item['spec_sha256'],'owner_task_id'=>$item['owner_task_id'],'dispatch_allowed'=>$dispatch];
    }
    private function eventId(array $q,array $item,bool $attention): string
    {
        return ($attention?'dev_attention_':'dev_evt_').self::hash([$q['queue_id'],$item['task_id'],$item['spec_sha256'],$q['oauth_connection_id'],$attention?'unclaimed':'ready']);
    }
    private function watchEventId(array $q,array $item): string
    {
        return 'dev_attention_'.self::hash([$q['queue_id'],$item['task_id'],$item['spec_sha256'],$q['oauth_connection_id'],'watchdog',(int)$item['watchdog_epoch']]);
    }
    private function allItems(string $id): array
    {
        $q=$this->pdo->prepare('SELECT * FROM development_work_items WHERE queue_id=? ORDER BY priority DESC,ordinal ASC LIMIT 2001');$q->execute([$id]);$items=$q->fetchAll(PDO::FETCH_ASSOC);
        if (count($items)>self::MAX_ITEMS) throw new RuntimeException('development_work_stored_state_invalid');
        foreach ($items as $item) {
            if (!in_array($item['state'],['pending','ready','claimed','unknown','accepted','blocked'],true)) throw new RuntimeException('development_work_stored_state_invalid');
            $this->storedSpec($item);
        }
        return $items;
    }
    private function storedSpec(array $item): array
    {
        $spec=['task_id'=>$item['task_id'],'owner_task_id'=>$item['owner_task_id'],'reviewer_task_id'=>$item['reviewer_task_id'],'input_sha256'=>$item['input_sha256'],
            'acceptance_contract_sha256'=>$item['acceptance_contract_sha256'],'deps'=>json_decode($item['deps_json'],true,8,JSON_THROW_ON_ERROR),'priority'=>(int)$item['priority']];
        $spec=self::specs([$spec])[0];
        if (!hash_equals(self::specHash(self::sha($item['plan_sha256']),$spec),self::sha($item['spec_sha256']))) throw new RuntimeException('development_work_stored_spec_invalid');
        return $spec;
    }
    private function current(array $q,?string $task=null,?string $spec=null): array
    {
        if ($q['current_task_id']===null||($task!==null&&$q['current_task_id']!==$task)) throw new DevelopmentWorkQueueConflict('stale_task');
        $item=$this->exactItem($q['queue_id'],$q['current_task_id'],$spec);
        if ($q['state']!==$item['state']||!in_array($item['state'],['ready','claimed','unknown'],true)) throw new RuntimeException('development_work_stored_state_invalid');
        return $item;
    }
    private function exactItem(string $id,string $task,?string $spec=null): array
    {
        $item=$this->item($id,$task);if (!$item||($spec!==null&&!hash_equals($item['spec_sha256'],$spec))) throw new DevelopmentWorkQueueConflict('stale_task');
        $this->storedSpec($item);return $item;
    }
    private function item(string $id,string $task): ?array {return $this->one('SELECT * FROM development_work_items WHERE queue_id=? AND task_id=?',[$id,$task]);}
    private function claimed(array $item,string $claim,bool $unknown): void
    {
        if (!in_array($item['state'],$unknown?['claimed','unknown']:['claimed'],true)||!hash_equals((string)$item['claim_sha256'],$claim)) throw new DevelopmentWorkQueueConflict('claim_mismatch');
    }
    private function claimArgs(array $args): array {return [self::id($args['queue_id']??null),self::id($args['task_id']??null),self::sha($args['spec_sha256']??null),hash('sha256',self::sha($args['claim_key']??null))];}
    private function owned(string $id,int $admin,string $connection,bool $lock=false): array
    {
        self::actor($admin,$connection);$q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.($lock?$this->lock():''),[$id]);
        if (!$q) throw new DomainException('development_work_access_denied');$this->authorize($q,$admin,$connection);return $q;
    }
    private function authorize(array $q,int $admin,string $connection): void
    {
        if ($q['project_key']!==self::PROJECT||(int)$q['admin_id']!==$admin||!hash_equals($q['oauth_connection_id'],$connection)) throw new DomainException('development_work_access_denied');
    }
    private function lane(): array
    {
        $lane=$this->one('SELECT * FROM development_work_lane WHERE lane_key=?'.$this->lock(),['DEVELOPMENT']);
        if (!$lane) throw new RuntimeException('development_work_lane_unavailable');return $lane;
    }
    private function ownsLane(array $q,array $lane): void {if ($lane['queue_id']!==$q['queue_id']) throw new DevelopmentWorkQueueConflict('development_lane_not_owned');}
    private function one(string $sql,array $values): ?array {$q=$this->pdo->prepare($sql);$q->execute($values);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;}
    private function execute(string $sql,array $values): void {$q=$this->pdo->prepare($sql);$q->execute($values);}
    private function lock(): string {return $this->driver==='mysql'?' FOR UPDATE':'';}
    private function outsideTransaction(): void {if ($this->pdo->inTransaction()) throw new LogicException('development_work_foreign_transaction');}
    private function transaction(callable $work): mixed
    {
        $this->outsideTransaction();
        try {if ($this->driver==='sqlite') $this->pdo->exec('BEGIN IMMEDIATE');else $this->pdo->beginTransaction();}
        catch (Throwable $ignored) {throw new RuntimeException('development_work_storage_unavailable');}
        try {$result=$work();if ($this->driver==='sqlite') $this->pdo->exec('COMMIT');else $this->pdo->commit();return $result;}
        catch (Throwable $error) {
            try {if ($this->driver==='sqlite') $this->pdo->exec('ROLLBACK');elseif ($this->pdo->inTransaction()) $this->pdo->rollBack();}
            catch (Throwable $ignored) {throw new RuntimeException('development_work_rollback_failed');}
            if ($error instanceof DevelopmentWorkQueueConflict||$error instanceof DomainException||$error instanceof InvalidArgumentException) throw $error;
            throw new RuntimeException('development_work_storage_unavailable');
        }
    }
    private function timestamp(): int {$at=$this->clock===null?time():($this->clock)();if (!is_int($at)||$at<1) throw new RuntimeException('development_work_clock_unavailable');return $at;}
    private function date(int $at): string {return gmdate('Y-m-d H:i:s',$at);}
    private function now(): string {return $this->date($this->timestamp());}
    private static function actor(int $admin,string $connection): void {if ($admin<1||preg_match('/\A[a-f0-9]{32}\z/D',$connection)!==1) throw new DomainException('development_work_access_denied');}
    private static function id(mixed $id): string {if (!is_string($id)||preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D',$id)!==1) throw new InvalidArgumentException('invalid_development_work_id');return $id;}
    private static function run(mixed $id): string {if (!is_string($id)||preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$id)!==1) throw new InvalidArgumentException('invalid_development_work_run');return $id;}
    private static function sha(mixed $hash): string {if (!is_string($hash)||preg_match('/\A[a-f0-9]{64}\z/D',$hash)!==1) throw new InvalidArgumentException('invalid_development_work_digest');return $hash;}
    private static function keys(array $args,array $allowed): void {if ((array_is_list($args)&&$args!==[])||array_diff(array_keys($args),$allowed)) throw new InvalidArgumentException('invalid_development_work_fields');}
    private static function specs(mixed $tasks): array
    {
        if (!is_array($tasks)||!array_is_list($tasks)||count($tasks)<1||count($tasks)>20) throw new InvalidArgumentException('invalid_development_work_tasks');
        $out=[];$seen=[];
        foreach ($tasks as $spec) {
            $fields=['task_id','owner_task_id','reviewer_task_id','input_sha256','acceptance_contract_sha256','deps','priority'];
            if (!is_array($spec)||array_is_list($spec)||count($spec)!==count($fields)||array_diff($fields,array_keys($spec))) throw new InvalidArgumentException('invalid_development_work_task');
            $spec['task_id']=self::id($spec['task_id']);$spec['owner_task_id']=self::run($spec['owner_task_id']);$spec['reviewer_task_id']=self::run($spec['reviewer_task_id']);
            if ($spec['owner_task_id']===$spec['reviewer_task_id']||isset($seen[$spec['task_id']])) throw new InvalidArgumentException('invalid_development_work_task');
            $spec['input_sha256']=self::sha($spec['input_sha256']);$spec['acceptance_contract_sha256']=self::sha($spec['acceptance_contract_sha256']);
            if (!is_int($spec['priority'])||$spec['priority']<0||$spec['priority']>100||!is_array($spec['deps'])||!array_is_list($spec['deps'])||count($spec['deps'])>20) throw new InvalidArgumentException('invalid_development_work_task');
            $deps=[];$depSeen=[];foreach ($spec['deps'] as $dep) {$dep=self::id($dep);if ($dep===$spec['task_id']||isset($depSeen[$dep])) throw new InvalidArgumentException('invalid_development_work_dependency');$depSeen[$dep]=true;$deps[]=$dep;}
            $spec['deps']=$deps;sort($spec['deps'],SORT_STRING);$seen[$spec['task_id']]=true;$out[]=$spec;
        }
        return $out;
    }
    private static function specHash(string $plan,array $spec): string {return self::hash(['plan_sha256'=>$plan,'task'=>$spec]);}
    private static function canonical(array $value): string
    {
        $sort=static function(array $v) use (&$sort): array {if (!array_is_list($v)) ksort($v,SORT_STRING);foreach ($v as &$x) if (is_array($x)) $x=$sort($x);return $v;};
        return json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    private static function hash(array $value): string {return hash('sha256',self::canonical($value));}
    private static function receipt(mixed $r): array
    {
        $fields=['run_id','outcome','result_sha256','readback_sha256','acceptance_sha256','reviewer_run_id','owned_claims_released'];
        if (!is_array($r)||array_is_list($r)||count($r)!==count($fields)||array_diff($fields,array_keys($r))
            ||!in_array($r['outcome'],['accepted_readback','terminal_blocked','unknown'],true)||!is_bool($r['owned_claims_released'])) throw new InvalidArgumentException('invalid_development_work_receipt');
        if ($r['run_id']!==null) self::run($r['run_id']);
        if ($r['reviewer_run_id']!==null) self::run($r['reviewer_run_id']);
        foreach (['result_sha256','readback_sha256','acceptance_sha256'] as $key) if ($r[$key]!==null) self::sha($r[$key]);
        if ($r['outcome']==='unknown') return $r;
        if ($r['run_id']===null||$r['result_sha256']===null||$r['readback_sha256']===null||!$r['owned_claims_released']) throw new InvalidArgumentException('invalid_development_work_receipt');
        if ($r['outcome']==='accepted_readback') {
            if ($r['acceptance_sha256']===null||$r['reviewer_run_id']===null||$r['reviewer_run_id']===$r['run_id']) throw new InvalidArgumentException('invalid_development_work_acceptance');
        } elseif ($r['acceptance_sha256']!==null||$r['reviewer_run_id']!==null) throw new InvalidArgumentException('invalid_development_work_blocked_receipt');
        return $r;
    }
}
final class DevelopmentWorkQueueConflict extends RuntimeException
{
    public function __construct(public readonly string $reason) {parent::__construct($reason);}
}
