<?php
declare(strict_types=1);

/** Hotel readiness metadata. Receipts are attestations, never independent file proof.
 * The caller must authenticate a current hotel-scoped OAuth grant before every call.
 * No schema creation, hosted execution, model/provider call or hotel-data write occurs here.
 */
final class HotelWorkQueue
{
    public const CLAIM_DEADLINE_SECONDS = 180;
    public const WATCHDOG_SECONDS = 300;
    public const DELIVERY_RECOVERY_BACKOFF_SECONDS = 60;
    private const PROJECT = 'anytour';
    private const COUNTRIES = ['EG','TH','VN','TR'];
    private const MAX_STAGE_INDEX = 1000000;
    private string $driver;

    public function __construct(private PDO $pdo, private $clock = null)
    {
        $this->driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver,['mysql','sqlite'],true) || ($clock!==null&&!is_callable($clock))) {
            throw new InvalidArgumentException('invalid_hotel_work_dependencies');
        }
    }

    public function create(int $admin,string $connection,array $args): array
    {
        self::keys($args,['queue_id','mode','batch_limit','start_country','phase_counter','baseline_sha256']);
        $id=self::queueId($args['queue_id']??null);self::actor($admin,$connection);
        $mode=array_key_exists('mode',$args)?$args['mode']:'finite';
        $country=array_key_exists('start_country',$args)?$args['start_country']:'EG';
        $counter=array_key_exists('phase_counter',$args)?$args['phase_counter']:0;
        if (!in_array($mode,['finite','continuous'],true)||!in_array($country,self::COUNTRIES,true)
            || !is_int($counter)||$counter<0||$counter>1) throw new InvalidArgumentException('invalid_hotel_work_plan');
        $limit=$mode==='finite'?($args['batch_limit']??3):null;
        if (($mode==='finite'&&(!is_int($limit)||$limit<1||$limit>10))
            || ($mode==='continuous'&&isset($args['batch_limit']))) throw new InvalidArgumentException('invalid_hotel_work_limit');
        $baseline=self::sha($args['baseline_sha256']??null);
        $plan=self::hash([$mode,$limit,$country,$counter,$baseline]);
        return $this->transaction(function() use($id,$admin,$connection,$mode,$limit,$country,$counter,$baseline,$plan): array {
            $lane=$this->lane(true);$row=$this->one('SELECT * FROM hotel_work_queues WHERE queue_id=?'.$this->lock(),[$id]);
            if ($row) {
                $this->authorize($row,$admin,$connection);
                if (!hash_equals($row['plan_sha256'],$plan)) throw new HotelWorkQueueConflict('plan_conflict');
                if (!in_array($row['state'],['complete','released'],true)) $this->ownsLane($row,$lane);
                return $this->project($row)+['created'=>false];
            }
            if ($lane['queue_id']!==null) throw new HotelWorkQueueConflict('hotel_lane_busy');
            $now=$this->now();
            $this->execute('INSERT INTO hotel_work_queues (queue_id,project_key,admin_id,oauth_connection_id,mode,batch_limit,start_country,initial_phase_counter,phase_counter,baseline_sha256,plan_sha256,stage_index,state,accepted_batches,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,0,?,?)',
                [$id,self::PROJECT,$admin,$connection,$mode,$limit,$country,$counter,$counter,$baseline,$plan,'ready',$now,$now]);
            $this->execute('UPDATE hotel_work_lane SET queue_id=? WHERE lane_key=? AND queue_id IS NULL',[$id,'HOTEL']);
            return $this->project($this->owned($id,$admin,$connection))+['created'=>true];
        });
    }

    public function status(int $admin,string $connection,string $queueId): array
    {
        $id=self::queueId($queueId);
        // Keep the queue row and delivery-resume audit in one consistent read.
        return $this->transaction(function() use($id,$admin,$connection): array {
            $this->lane(true);
            return $this->project($this->owned($id,$admin,$connection,true));
        });
    }

    /** Explicit owner decision, never a cron retry or permission to dispatch. */
    public function recover_delivery(int $admin,string $connection,array $args): array
    {
        self::keys($args,['queue_id','stage_id','baseline_sha256','expected_pending_event_id','decision_sha256','host_readback_sha256']);
        $id=self::queueId($args['queue_id']??null);$stage=self::sha($args['stage_id']??null);
        $baseline=self::sha($args['baseline_sha256']??null);$decision=self::sha($args['decision_sha256']??null);
        $readback=self::sha($args['host_readback_sha256']??null);$expected=$args['expected_pending_event_id']??null;
        if (!is_string($expected)||preg_match('/\Ahotel_evt_[a-f0-9]{64}\z/D',$expected)!==1) throw new InvalidArgumentException('invalid_hotel_work_pending_event');
        return $this->transaction(function() use($id,$stage,$baseline,$decision,$readback,$expected,$admin,$connection): array {
            $lane=$this->lane(true);$row=$this->owned($id,$admin,$connection,true);$this->ownsLane($row,$lane);
            $this->sameStage($row,$stage);
            if (!hash_equals($row['baseline_sha256'],$baseline)) throw new HotelWorkQueueConflict('baseline_mismatch');
            $old=$this->ownerDeliveryRecoveryRecord($row);
            if ($old!==null) {
                if ($old['oauth_connection_id']!==$connection||$old['decision_sha256']!==$decision
                    ||$old['host_readback_sha256']!==$readback||$old['pending_event_id']!==$expected) throw new HotelWorkQueueConflict('owner_delivery_recovery_already_used');
                return $this->project($row)+['recovery_status'=>'already_recorded_do_not_replay','recovery_sha256'=>$old['recovery_sha256'],'dispatch_allowed'=>false];
            }
            if ($row['state']!=='ready'||$row['claim_sha256']!==null||$row['current_run_id']!==null) throw new HotelWorkQueueConflict('not_known_idle');
            $automatic=$this->deliveryRecoveryToken($row);$resume=$this->deliveryResumeRecord($row);
            if ($automatic===null||$resume===null||$resume['old_connection_id']!==$connection) throw new HotelWorkQueueConflict('automatic_recovery_not_used');
            if (!hash_equals($this->eventId($row,false),$expected)) throw new HotelWorkQueueConflict('stale_pending_event');
            if ($row['event_ack']!==$expected||$row['attention_ack']!==$this->eventId($row,true)
                ||$row['attention_at']===null||$row['claim_deadline']===null||$row['claim_deadline']>$this->now()) throw new HotelWorkQueueConflict('delivery_not_exhausted');
            if ($this->one('SELECT stage_id FROM hotel_work_receipts WHERE queue_id=? AND stage_id=?',[$id,$stage])
                ||$this->one('SELECT queue_id FROM hotel_work_queues WHERE project_key=? AND (state IN (?,?) OR claim_sha256 IS NOT NULL OR current_run_id IS NOT NULL) LIMIT 1',[self::PROJECT,'claimed','unknown'])) throw new HotelWorkQueueConflict('hotel_work_not_known_idle');
            $now=$this->now();
            $audit=['queue_id'=>$id,'stage_id'=>$stage,'admin_id'=>$admin,'oauth_connection_id'=>$connection,'baseline_sha256'=>$baseline,
                'automatic_recovery_sha256'=>$automatic,'decision_sha256'=>$decision,'host_readback_sha256'=>$readback,
                'pending_event_id'=>$expected,'attention_event_id'=>$row['attention_ack'],'delivered_at'=>$row['delivered_at'],
                'claim_deadline'=>$row['claim_deadline'],'attention_at'=>$row['attention_at'],'previous_updated_at'=>$row['updated_at'],'created_at'=>$now];
            $hash=self::hash(['namespace'=>'hotel_work_owner_delivery_recovery_v1','audit'=>$audit]);
            $this->execute('INSERT INTO hotel_work_owner_delivery_recoveries (recovery_sha256,queue_id,stage_id,admin_id,oauth_connection_id,baseline_sha256,automatic_recovery_sha256,decision_sha256,host_readback_sha256,pending_event_id,attention_event_id,delivered_at,claim_deadline,attention_at,previous_updated_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$hash,...array_values($audit)]);
            $this->execute('UPDATE hotel_work_queues SET event_ack=NULL,delivered_at=NULL,claim_deadline=NULL,attention_at=NULL,attention_ack=NULL,updated_at=? WHERE queue_id=?',[$now,$id]);
            return $this->project($this->owned($id,$admin,$connection))+['recovery_status'=>'owner_delivery_recovered_once','recovery_sha256'=>$hash,'dispatch_allowed'=>false];
        });
    }

    public function claim(int $admin,string $connection,array $args): array
    {
        self::keys($args,['queue_id','stage_id','claim_key','expected_pending_event_id']);[$id,$stage,$claim]=$this->claimArgs($args);
        $expected=$args['expected_pending_event_id']??null;
        if (array_key_exists('expected_pending_event_id',$args)&&(!is_string($expected)||preg_match('/\Ahotel_evt_[a-f0-9]{64}\z/D',$expected)!==1)) throw new InvalidArgumentException('invalid_hotel_work_pending_event');
        return $this->transaction(function() use($id,$stage,$claim,$admin,$connection,$expected): array {
            $lane=$this->lane(true);$row=$this->owned($id,$admin,$connection,true);$this->ownsLane($row,$lane);
            $this->sameStage($row,$stage);
            if ($this->deliveryResumeToken($row)!==null||$this->deliveryRecoveryToken($row)!==null
                ||$this->one('SELECT skip_sha256 FROM hotel_work_research_skips WHERE queue_id=? AND next_stage_index=?',[$id,(int)$row['stage_index']])) {
                if ($expected===null) throw new HotelWorkQueueConflict('pending_event_required');
                if (!hash_equals($this->eventId($row,false),$expected)) throw new HotelWorkQueueConflict('stale_pending_event');
            }
            if ($row['state']==='claimed'&&hash_equals((string)$row['claim_sha256'],$claim)) {
                return $this->project($row)+['claim_status'=>'already_claimed_do_not_replay'];
            }
            if ($row['state']!=='ready') throw new HotelWorkQueueConflict('not_ready');
            $this->execute('UPDATE hotel_work_queues SET state=?,claim_sha256=?,watchdog_due_at=?,updated_at=? WHERE queue_id=?',['claimed',$claim,$this->date($this->timestamp()+self::WATCHDOG_SECONDS),$this->now(),$id]);
            return $this->project($this->owned($id,$admin,$connection))+['claim_status'=>'claimed'];
        });
    }

    public function started(int $admin,string $connection,array $args): array
    {
        self::keys($args,['queue_id','stage_id','claim_key','run_id']);[$id,$stage,$claim]=$this->claimArgs($args);
        $run=self::runId($args['run_id']??null);
        return $this->transaction(function() use($id,$stage,$claim,$run,$admin,$connection): array {
            $lane=$this->lane(true);$row=$this->owned($id,$admin,$connection,true);$this->ownsLane($row,$lane);
            $this->claimed($row,$stage,$claim);
            if ($row['current_run_id']!==null&&!hash_equals($row['current_run_id'],$run)) throw new HotelWorkQueueConflict('run_conflict');
            if ($row['current_run_id']===null) $this->execute('UPDATE hotel_work_queues SET current_run_id=?,watchdog_due_at=?,updated_at=? WHERE queue_id=?',[$run,$this->date($this->timestamp()+self::WATCHDOG_SECONDS),$this->now(),$id]);
            return $this->project($this->owned($id,$admin,$connection));
        });
    }

    public function complete(int $admin,string $connection,array $args): array
    {
        self::keys($args,['queue_id','stage_id','claim_key','receipt']);[$id,$stage,$claim]=$this->claimArgs($args);
        $receipt=self::receipt($args['receipt']??null);$json=self::canonical($receipt);$hash=hash('sha256',$json);
        return $this->transaction(function() use($id,$stage,$claim,$receipt,$json,$hash,$admin,$connection): array {
            $lane=$this->lane(true);$row=$this->owned($id,$admin,$connection,true);
            if ($this->one('SELECT skip_sha256 FROM hotel_work_research_skips WHERE queue_id=? AND stage_id=?',[$id,$stage])) throw new HotelWorkQueueConflict('research_batch_skipped');
            $old=$this->one('SELECT receipt_sha256 FROM hotel_work_receipts WHERE queue_id=? AND stage_id=?',[$id,$stage]);
            if ($old) {
                if (!hash_equals($old['receipt_sha256'],$hash)) throw new HotelWorkQueueConflict('completion_conflict');
                return $this->project($row)+['completion_status'=>'already_recorded','receipt_sha256'=>$hash];
            }
            $this->ownsLane($row,$lane);$this->claimed($row,$stage,$claim);
            if ($row['current_run_id']!==$receipt['run_id']
                || ($receipt['outcome']==='accepted_readback'&&$row['current_run_id']===null)) throw new HotelWorkQueueConflict('run_mismatch');
            $index=(int)$row['stage_index'];
            if ($receipt['outcome']==='accepted_readback') $this->validateAccepted($row,$receipt);
            $now=$this->now();
            $this->execute('INSERT INTO hotel_work_receipts (queue_id,stage_index,stage_id,receipt_sha256,receipt_json,created_at) VALUES (?,?,?,?,?,?)',[$id,$index,$stage,$hash,$json,$now]);
            if ($receipt['outcome']==='unknown') {
                $this->execute('UPDATE hotel_work_queues SET state=?,watchdog_due_at=?,updated_at=? WHERE queue_id=?',['unknown',$this->date($this->timestamp()+self::WATCHDOG_SECONDS),$now,$id]);
                return $this->project($this->owned($id,$admin,$connection))+['completion_status'=>'held_unknown_no_retry','receipt_sha256'=>$hash];
            }
            return $this->project($this->advance($row,$now))+['completion_status'=>'receipt_attested_not_independently_verified','receipt_sha256'=>$hash];
        });
    }

    /** Read-only same-admin recovery gate. No claim secret, event or execution authority. */
    public function handoff_preflight(int $admin,string $newConnection,array $args,callable $oldConnectionProof): array
    {
        self::keys($args,['queue_id','stage_id','baseline_sha256','old_connection_id','expected_run_id','expected_caller_connection_id']);
        $id=self::queueId($args['queue_id']??null);
        $stage=self::sha($args['stage_id']??null);
        $baseline=self::sha($args['baseline_sha256']??null);
        $run=self::runId($args['expected_run_id']??null);
        $old=$args['old_connection_id']??null;
        $expected=$args['expected_caller_connection_id']??null;
        self::actor($admin,$newConnection);
        if (!is_string($old)||!is_string($expected)) throw new InvalidArgumentException('invalid_hotel_work_connection');
        self::actor($admin,$old);self::actor($admin,$expected);
        if (!hash_equals($newConnection,$expected)) throw new HotelWorkQueueConflict('caller_connection_changed');
        if (hash_equals($old,$newConnection)) throw new InvalidArgumentException('same_hotel_work_connection');
        return $this->transaction(function() use($id,$stage,$baseline,$run,$old,$admin,$newConnection,$oldConnectionProof): array {
            $lane=$this->lane(true);
            $row=$this->one('SELECT * FROM hotel_work_queues WHERE queue_id=?'.$this->lock(),[$id]);
            if (!$row||$row['project_key']!==self::PROJECT||(int)$row['admin_id']!==$admin
                ||!hash_equals($row['oauth_connection_id'],$old)) throw new DomainException('hotel_work_access_denied');
            // Both connections are server-proven. A caller's proposed old ID is never authority.
            $verify=function() use($newConnection,$old,$admin,$oldConnectionProof): void {
                if ($oldConnectionProof($newConnection,$admin,'current')!==true
                    ||$oldConnectionProof($old,$admin,'ready')!==true)
                    throw new DomainException('hotel_work_access_denied');
            };
            $verify();
            $reason=null;
            // Match the authoritative handoff anti-reacquisition fence before reporting eligibility.
            if ($this->one('SELECT handoff_sha256 FROM hotel_work_connection_handoffs WHERE queue_id=? AND old_connection_id=? AND old_connection_id<>new_connection_id LIMIT 1',[$id,$newConnection]))
                $reason='binding_relinquished';
            elseif (!hash_equals($row['baseline_sha256'],$baseline)) $reason='baseline_mismatch';
            elseif (!hash_equals($this->stageId($row),$stage)) $reason='stale_stage';
            elseif ($lane['queue_id']!==$id) $reason='hotel_lane_not_owned';
            elseif (!in_array($row['state'],['claimed','unknown'],true)||$row['claim_sha256']===null
                ||$row['current_run_id']===null||!hash_equals($row['current_run_id'],$run))
                $reason='existing_run_proof_mismatch';
            elseif ($oldConnectionProof($old,$admin,'existing_run')!==true)
                $reason='old_connection_not_definitively_retired';
            // Recheck canonical grants after every read, including negative findings.
            $verify();
            return ['ok'=>true,'eligible'=>$reason===null,'reason'=>$reason,
                'queue_id'=>$id,'stage_id'=>$stage,'expected_run_id'=>$run,
                'dispatch_allowed'=>false,
                'preflight_semantics'=>'administrative_known_run_diagnostics_not_handoff_or_execution_proof'];
        });
    }

    /** Explicit authorization handoff. Proof is mode-aware and server-owned, never MCP input. */
    public function handoff_connection(int $admin,string $newConnection,array $args,callable $oldConnectionProof): array
    {
        self::keys($args,['queue_id','stage_id','baseline_sha256','old_connection_id','handoff_mode','claim_key','expected_run_id']);
        $id=self::queueId($args['queue_id']??null);$stage=self::sha($args['stage_id']??null);$baseline=self::sha($args['baseline_sha256']??null);
        $old=$args['old_connection_id']??null;self::actor($admin,$newConnection);
        if (!is_string($old)) throw new InvalidArgumentException('invalid_hotel_work_connection');self::actor($admin,$old);
        $sameConnection=hash_equals($old,$newConnection);
        $mode=array_key_exists('handoff_mode',$args)?$args['handoff_mode']:'ready';$claim=null;$run=null;
        if (!in_array($mode,['ready','existing_run','claim_authority'],true)) throw new InvalidArgumentException('invalid_hotel_work_handoff_mode');
        if ($sameConnection&&$mode!=='ready') throw new InvalidArgumentException('same_hotel_work_connection');
        if ($mode==='existing_run') {$claim=hash('sha256',self::sha($args['claim_key']??null));$run=self::runId($args['expected_run_id']??null);}
        elseif ($mode==='claim_authority') {
            $claim=hash('sha256',self::sha($args['claim_key']??null));
            if (array_key_exists('expected_run_id',$args)) throw new InvalidArgumentException('unexpected_hotel_work_handoff_proof');
        }
        elseif (array_key_exists('claim_key',$args)||array_key_exists('expected_run_id',$args)) throw new InvalidArgumentException('unexpected_hotel_work_handoff_proof');
        $handoff=self::hash([$id,$admin,$old,$newConnection,$stage,$baseline,$mode,$claim,$run]);
        return $this->transaction(function() use($id,$stage,$baseline,$old,$newConnection,$admin,$handoff,$oldConnectionProof,$mode,$claim,$run,$sameConnection): array {
            $lane=$this->lane(true);$row=$this->one('SELECT * FROM hotel_work_queues WHERE queue_id=?'.$this->lock(),[$id]);
            if (!$row||$row['project_key']!==self::PROJECT||(int)$row['admin_id']!==$admin) throw new DomainException('hotel_work_access_denied');
            $prior=$this->one('SELECT handoff_sha256 FROM hotel_work_connection_handoffs WHERE handoff_sha256=?',[$handoff]);
            if ($prior) {
                $this->authorize($row,$admin,$newConnection);
                return $this->project($row)+['handoff_status'=>'already_recorded']
                    +($mode==='claim_authority'?['authority_only'=>true,'dispatch_allowed'=>false]:[]);
            }
            if ($this->one('SELECT handoff_sha256 FROM hotel_work_connection_handoffs WHERE queue_id=? AND old_connection_id=? AND old_connection_id<>new_connection_id LIMIT 1',[$id,$newConnection])) {
                throw new HotelWorkQueueConflict('binding_relinquished');
            }
            if (!hash_equals($row['oauth_connection_id'],$old)) throw new DomainException('hotel_work_access_denied');
            $this->sameStage($row,$stage);$this->ownsLane($row,$lane);
            if (!hash_equals($row['baseline_sha256'],$baseline)) throw new HotelWorkQueueConflict('baseline_mismatch');
            if ($mode==='ready') {
                if ($row['state']!=='ready'||$row['claim_sha256']!==null||$row['current_run_id']!==null) throw new HotelWorkQueueConflict('not_known_idle');
            } elseif ($mode==='claim_authority') {
                if ($row['state']!=='claimed'||$row['current_run_id']!==null||$row['claim_sha256']===null
                    ||!hash_equals((string)$row['claim_sha256'],$claim)) throw new HotelWorkQueueConflict('claim_authority_proof_mismatch');
                if ($this->one('SELECT stage_id FROM hotel_work_receipts WHERE queue_id=? AND stage_id=?',[$id,$stage])) throw new HotelWorkQueueConflict('stage_receipt_exists');
            } elseif (!in_array($row['state'],['claimed','unknown'],true)||$row['current_run_id']===null
                ||!hash_equals($row['current_run_id'],$run)||!hash_equals((string)$row['claim_sha256'],$claim)) throw new HotelWorkQueueConflict('existing_run_proof_mismatch');
            // READY transfers control of unclaimed metadata under the lane lock.
            // Existing-run adoption still requires definitive old-grant retirement.
            try {$proven=$oldConnectionProof($old,$admin,$mode)===true;} catch (Throwable $ignored) {$proven=false;}
            if (!$proven) throw new HotelWorkQueueConflict($mode==='existing_run'?'old_connection_not_definitively_retired':'old_connection_not_known_hotel_owner');
            if ($sameConnection) {
                if ($this->deliveryResumeToken($row)!==null) throw new HotelWorkQueueConflict('delivery_resume_already_used');
                if ($this->one('SELECT stage_id FROM hotel_work_receipts WHERE queue_id=? AND stage_id=?',[$id,$stage])) throw new HotelWorkQueueConflict('stage_receipt_exists');
                if ($row['attention_at']===null||$row['event_ack']!==$this->eventId($row,false)) throw new HotelWorkQueueConflict('delivery_resume_not_attention');
            }
            $now=$this->now();
            $this->execute('INSERT INTO hotel_work_connection_handoffs (handoff_sha256,queue_id,admin_id,old_connection_id,new_connection_id,stage_id,baseline_sha256,handoff_mode,expected_run_id,claim_sha256,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$handoff,$id,$admin,$old,$newConnection,$stage,$baseline,$mode,$run,$claim,$now]);
            if ($mode==='claim_authority') {
                // A retained claim is not proof that no external dispatch happened.
                // Restore access only; keep all execution and delivery state held.
                $this->execute('UPDATE hotel_work_queues SET oauth_connection_id=?,updated_at=? WHERE queue_id=?',[$newConnection,$now,$id]);
                return $this->project($this->owned($id,$admin,$newConnection))
                    +['handoff_status'=>'claim_authority_handed_off','authority_only'=>true,'dispatch_allowed'=>false];
            }
            if ($sameConnection) {
                // One delivery resume, not a new claim, run, credential or stage.
                $this->execute('UPDATE hotel_work_queues SET event_ack=NULL,delivered_at=NULL,claim_deadline=NULL,attention_at=NULL,attention_ack=NULL,updated_at=? WHERE queue_id=?',[$now,$id]);
                return $this->project($this->owned($id,$admin,$newConnection))+['handoff_status'=>'delivery_resumed_once'];
            }
            $this->execute('UPDATE hotel_work_queues SET oauth_connection_id=?,event_ack=NULL,delivered_at=NULL,claim_deadline=NULL,attention_at=NULL,attention_ack=NULL,watchdog_due_at=?,watchdog_epoch=0,last_watch_ack=NULL,updated_at=? WHERE queue_id=?',[$newConnection,$mode==='existing_run'?$now:null,$now,$id]);
            return $this->project($this->owned($id,$admin,$newConnection))+['handoff_status'=>$mode==='existing_run'?'existing_run_authorization_handed_off':'connection_handed_off'];
        });
    }

    /** Reconcile an already bound run, never dispatch or retry it. Original UNKNOWN stays immutable. */
    public function resolve_existing_run(int $admin,string $connection,array $args): array
    {
        self::keys($args,['queue_id','stage_id','claim_key','unknown_receipt_sha256','expected_run_id','expected_packet_sha256','receipt']);
        [$id,$stage,$claim]=$this->claimArgs($args);$unknown=self::sha($args['unknown_receipt_sha256']??null);
        $run=self::runId($args['expected_run_id']??null);$packet=self::sha($args['expected_packet_sha256']??null);
        $receipt=self::receipt($args['receipt']??null);
        if ($receipt['outcome']!=='accepted_readback'||$receipt['run_id']!==$run||$receipt['packet_sha256']!==$packet) throw new InvalidArgumentException('invalid_hotel_work_resolution');
        $json=self::canonical($receipt);$receiptHash=hash('sha256',$json);$resolution=self::hash([$unknown,$run,$packet,$receiptHash,$claim]);
        return $this->transaction(function() use($id,$stage,$claim,$unknown,$run,$packet,$receipt,$json,$receiptHash,$resolution,$admin,$connection): array {
            $lane=$this->lane(true);$row=$this->owned($id,$admin,$connection,true);
            if ($this->one('SELECT skip_sha256 FROM hotel_work_research_skips WHERE queue_id=? AND stage_id=?',[$id,$stage])) throw new HotelWorkQueueConflict('research_batch_skipped');
            $prior=$this->one('SELECT resolution_sha256 FROM hotel_work_resolutions WHERE queue_id=? AND stage_id=?',[$id,$stage]);
            if ($prior) {
                if (!hash_equals($prior['resolution_sha256'],$resolution)) throw new HotelWorkQueueConflict('resolution_conflict');
                return $this->project($row)+['resolution_status'=>'already_resolved','receipt_sha256'=>$receiptHash];
            }
            $this->sameStage($row,$stage);$this->ownsLane($row,$lane);
            if ($row['state']!=='unknown'||!hash_equals((string)$row['claim_sha256'],$claim)) throw new HotelWorkQueueConflict('unknown_claim_mismatch');
            if ($row['current_run_id']===null||!hash_equals($row['current_run_id'],$run)) throw new HotelWorkQueueConflict('unknown_run_not_bound');
            $original=$this->one('SELECT receipt_sha256,receipt_json FROM hotel_work_receipts WHERE queue_id=? AND stage_id=?',[$id,$stage]);
            if (!$original||!hash_equals($original['receipt_sha256'],$unknown)) throw new HotelWorkQueueConflict('unknown_receipt_mismatch');
            $before=self::receipt(json_decode($original['receipt_json'],true,16,JSON_THROW_ON_ERROR));
            if ($before['outcome']!=='unknown'||$before['run_id']!==$run) throw new HotelWorkQueueConflict('unknown_run_not_bound');
            if ($before['packet_sha256']!==null&&!hash_equals($before['packet_sha256'],$packet)) throw new HotelWorkQueueConflict('source_packet_mismatch');
            $this->validateAccepted($row,$receipt);$now=$this->now();
            $this->execute('INSERT INTO hotel_work_resolutions (queue_id,stage_index,stage_id,unknown_receipt_sha256,resolution_sha256,receipt_sha256,receipt_json,created_at) VALUES (?,?,?,?,?,?,?,?)',[$id,(int)$row['stage_index'],$stage,$unknown,$resolution,$receiptHash,$json,$now]);
            return $this->project($this->advance($row,$now))+['resolution_status'=>'existing_run_receipt_attested','receipt_sha256'=>$receiptHash];
        });
    }

    /** Explicit owner abandonment, after independently verifying that external writes stopped or are isolated.
     * The evidence hashes attest that check; this metadata operation cannot cancel a hosted run or fence files.
     * Skip research AND its intake, preserve UNKNOWN/claims in the audit, and keep accepted counters unchanged.
     */
    public function skip_research(int $admin,string $connection,array $args,callable $connectionProof): array
    {
        $fields=['queue_id','stage_id','baseline_sha256','old_connection_id','expected_caller_connection_id',
            'expected_run_id','expected_unknown_receipt_sha256','decision_sha256','external_effects'];
        self::keys($args,$fields);
        if (count($args)!==count($fields)) throw new InvalidArgumentException('invalid_hotel_work_fields');
        $id=self::queueId($args['queue_id']);$stage=self::sha($args['stage_id']);$baseline=self::sha($args['baseline_sha256']);
        $run=self::runId($args['expected_run_id']);$decision=self::sha($args['decision_sha256']);
        $old=$args['old_connection_id'];$expected=$args['expected_caller_connection_id'];
        if (!is_string($old)||!is_string($expected)) throw new InvalidArgumentException('invalid_hotel_work_connection');
        self::actor($admin,$connection);self::actor($admin,$old);self::actor($admin,$expected);
        if (!hash_equals($connection,$expected)) throw new HotelWorkQueueConflict('caller_connection_changed');
        $unknown=$args['expected_unknown_receipt_sha256'];if ($unknown!==null) self::sha($unknown);
        $effects=$args['external_effects'];
        if (!is_array($effects)||count($effects)!==2||array_diff(array_keys($effects),['status','readback_sha256'])
            ||!in_array($effects['status']??null,['stopped','isolated'],true)) throw new InvalidArgumentException('invalid_external_effects_evidence');
        self::sha($effects['readback_sha256']);
        $skip=self::hash(['operation'=>'skip_research_v1','admin_id'=>$admin,'caller_connection_id'=>$connection,'arguments'=>$args]);
        return $this->transaction(function() use($id,$stage,$baseline,$run,$decision,$old,$unknown,$effects,$skip,$admin,$connection,$connectionProof): array {
            $lane=$this->lane(true);$row=$this->one('SELECT * FROM hotel_work_queues WHERE queue_id=?'.$this->lock(),[$id]);
            if (!$row||$row['project_key']!==self::PROJECT||(int)$row['admin_id']!==$admin) throw new DomainException('hotel_work_access_denied');
            $verify=static function() use($connectionProof,$connection,$old,$admin): void {
                if ($connectionProof($connection,$admin,'current')!==true||$connectionProof($old,$admin,'ready')!==true)
                    throw new DomainException('hotel_work_access_denied');
            };
            $verify();
            $prior=$this->one('SELECT skip_sha256 FROM hotel_work_research_skips WHERE queue_id=? AND stage_id=?',[$id,$stage]);
            if ($prior) {
                $this->authorize($row,$admin,$connection);
                if (!hash_equals($prior['skip_sha256'],$skip)) throw new HotelWorkQueueConflict('skip_conflict');
                $verify();
                return $this->project($row)+['skip_status'=>'already_recorded','skip_sha256'=>$skip,'dispatch_allowed'=>false,'external_run_cancelled'=>false];
            }
            if (!hash_equals($row['oauth_connection_id'],$old)) throw new DomainException('hotel_work_access_denied');
            if ($this->one('SELECT handoff_sha256 FROM hotel_work_connection_handoffs WHERE queue_id=? AND old_connection_id=? AND old_connection_id<>new_connection_id LIMIT 1',[$id,$connection]))
                throw new HotelWorkQueueConflict('binding_relinquished');
            $this->sameStage($row,$stage);$this->ownsLane($row,$lane);
            if (!hash_equals($row['baseline_sha256'],$baseline)) throw new HotelWorkQueueConflict('baseline_mismatch');
            $index=(int)$row['stage_index'];$next=$index+2;
            if ($index%2!==0) throw new HotelWorkQueueConflict('research_stage_required');
            if ($next>self::MAX_STAGE_INDEX) throw new HotelWorkQueueConflict('stage_limit_reached');
            if (!in_array($row['state'],['claimed','unknown'],true)||$row['claim_sha256']===null
                ||$row['current_run_id']===null||!hash_equals($row['current_run_id'],$run)) throw new HotelWorkQueueConflict('existing_run_proof_mismatch');
            $receipt=$this->one('SELECT receipt_sha256,receipt_json FROM hotel_work_receipts WHERE queue_id=? AND stage_id=?',[$id,$stage]);
            if ($row['state']==='unknown') {
                if (!$receipt||$unknown===null||!hash_equals($receipt['receipt_sha256'],$unknown)) throw new HotelWorkQueueConflict('unknown_receipt_mismatch');
                $saved=json_decode($receipt['receipt_json'],true,16,JSON_THROW_ON_ERROR);
                if (($saved['outcome']??null)!=='unknown'||($saved['run_id']??null)!==$run) throw new HotelWorkQueueConflict('unknown_run_not_bound');
            } elseif ($receipt!==null||$unknown!==null) throw new HotelWorkQueueConflict('stage_receipt_mismatch');
            if ($this->one('SELECT stage_id FROM hotel_work_resolutions WHERE queue_id=? AND stage_id=?',[$id,$stage])) throw new HotelWorkQueueConflict('stage_resolution_exists');
            // Recheck live grants under the same lane/queue transaction; OAuth itself is never changed.
            $verify();$now=$this->now();
            $this->execute('INSERT INTO hotel_work_research_skips (skip_sha256,queue_id,stage_index,stage_id,admin_id,old_connection_id,new_connection_id,baseline_sha256,run_id,claim_sha256,previous_state,unknown_receipt_sha256,decision_sha256,external_effects_status,external_effects_readback_sha256,next_stage_index,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$skip,$id,$index,$stage,$admin,$old,$connection,$baseline,$run,$row['claim_sha256'],$row['state'],$unknown,$decision,$effects['status'],$effects['readback_sha256'],$next,$now]);
            if (!hash_equals($old,$connection)) {
                // Retire this queue binding only. The old reply grant and its subscriptions remain usable.
                $this->execute('INSERT INTO hotel_work_connection_handoffs (handoff_sha256,queue_id,admin_id,old_connection_id,new_connection_id,stage_id,baseline_sha256,handoff_mode,expected_run_id,claim_sha256,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [$skip,$id,$admin,$old,$connection,$stage,$baseline,'skipped_research',$run,$row['claim_sha256'],$now]);
            }
            $this->execute('UPDATE hotel_work_queues SET stage_index=?,state=?,oauth_connection_id=?,claim_sha256=NULL,current_run_id=NULL,event_ack=NULL,delivered_at=NULL,claim_deadline=NULL,attention_at=NULL,attention_ack=NULL,watchdog_due_at=NULL,watchdog_epoch=0,last_watch_ack=NULL,updated_at=? WHERE queue_id=?',
                [$next,'ready',$connection,$now,$id]);
            $verify();
            return $this->project($this->owned($id,$admin,$connection))
                +['skip_status'=>'research_batch_skipped','skip_sha256'=>$skip,'dispatch_allowed'=>false,'external_run_cancelled'=>false];
        });
    }

    /** Explicitly withdraw only a known unclaimed stage. UNKNOWN never releases by age. */
    public function release(int $admin,string $connection,array $args): array
    {
        self::keys($args,['queue_id','stage_id']);$id=self::queueId($args['queue_id']??null);$stage=self::sha($args['stage_id']??null);
        return $this->transaction(function() use($id,$stage,$admin,$connection): array {
            $lane=$this->lane(true);$row=$this->owned($id,$admin,$connection,true);$this->sameStage($row,$stage);
            if ($row['state']==='released') return $this->project($row);
            $this->ownsLane($row,$lane);
            if ($row['state']!=='ready'||$row['claim_sha256']!==null||$row['current_run_id']!==null) throw new HotelWorkQueueConflict('not_known_idle');
            $this->execute('UPDATE hotel_work_queues SET state=?,updated_at=? WHERE queue_id=?',['released',$this->now(),$id]);
            $this->execute('UPDATE hotel_work_lane SET queue_id=NULL WHERE lane_key=? AND queue_id=?',['HOTEL',$id]);
            return $this->project($this->owned($id,$admin,$connection));
        });
    }

    /** Internal bounded inventory only. Event owner must recheck canonical OAuth. */
    public function eventCandidates(int $limit=20): array
    {
        $this->outsideTransaction();if ($limit<1||$limit>20) throw new InvalidArgumentException('invalid_hotel_work_limit');
        $q=$this->pdo->prepare('SELECT q.queue_id,q.admin_id,q.oauth_connection_id FROM hotel_work_lane l JOIN hotel_work_queues q ON q.queue_id=l.queue_id WHERE l.lane_key=? AND q.project_key=? AND q.state IN (?,?,?) LIMIT '.$limit);
        $q->execute(['HOTEL',self::PROJECT,'ready','claimed','unknown']);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Metadata event, never a hosted task run. Delivered but unclaimed gets one attention event. */
    public function pendingEvent(int $admin,string $connection,string $queueId): ?array
    {
        $id=self::queueId($queueId);
        return $this->transaction(function() use($id,$admin,$connection): ?array {
            $lane=$this->lane(true);$row=$this->owned($id,$admin,$connection,true);
            if (in_array($row['state'],['claimed','unknown'],true)) {
                $this->ownsLane($row,$lane);
                if ($row['watchdog_due_at']===null) {
                    $due=$this->date($this->timestamp()+self::WATCHDOG_SECONDS);
                    $this->execute('UPDATE hotel_work_queues SET watchdog_due_at=? WHERE queue_id=?',[$due,$id]);return null;
                }
                if ($row['watchdog_due_at']>$this->now()) return null;
                return ['event_id'=>$this->watchEventId($row),'name'=>'hotel_work.attention','data'=>$this->eventData($row,false)];
            }
            if ($row['state']!=='ready') return null;$this->ownsLane($row,$lane);
            $event=$this->eventId($row,false);
            if ($row['event_ack']===$event) {
                if ($row['claim_deadline']===null||$row['claim_deadline']>$this->now()) return null;
                if ($row['attention_at']===null) $this->execute('UPDATE hotel_work_queues SET attention_at=?,updated_at=? WHERE queue_id=?',[$this->now(),$this->now(),$id]);
                $attention=$this->eventId($row,true);
                if ($row['attention_ack']===$attention) {
                    $recovered=$this->recoverUnclaimedDelivery($row);
                    return $recovered===null?null:['event_id'=>$this->eventId($recovered,false),'name'=>'hotel_work.pending','data'=>$this->eventData($recovered,true)];
                }
                return ['event_id'=>$attention,'name'=>'hotel_work.attention','data'=>$this->eventData($row,false)];
            }
            return ['event_id'=>$event,'name'=>'hotel_work.pending','data'=>$this->eventData($row,true)];
        });
    }

    public function markEventDelivered(int $admin,string $connection,string $queueId,string $eventId): array
    {
        $id=self::queueId($queueId);
        return $this->transaction(function() use($id,$eventId,$admin,$connection): array {
            $lane=$this->lane(true);$row=$this->owned($id,$admin,$connection,true);$this->ownsLane($row,$lane);
            $now=$this->now();
            if (in_array($row['state'],['claimed','unknown'],true)) {
                if ($row['last_watch_ack']===$eventId) return $this->project($row);
                if ($eventId!==$this->watchEventId($row)||$row['watchdog_due_at']===null||$row['watchdog_due_at']>$now) throw new HotelWorkQueueConflict('stale_event');
                if ((int)$row['watchdog_epoch']>=self::MAX_STAGE_INDEX) throw new HotelWorkQueueConflict('watchdog_limit_reached');
                $this->execute('UPDATE hotel_work_queues SET last_watch_ack=?,watchdog_epoch=watchdog_epoch+1,watchdog_due_at=?,updated_at=? WHERE queue_id=?',[$eventId,$this->date($this->timestamp()+self::WATCHDOG_SECONDS),$now,$id]);
                return $this->project($this->owned($id,$admin,$connection));
            }
            if ($row['state']!=='ready') throw new HotelWorkQueueConflict('stale_event');
            if ($eventId===$this->eventId($row,false)) {
                if ($row['event_ack']===null) $this->execute('UPDATE hotel_work_queues SET event_ack=?,delivered_at=?,claim_deadline=?,updated_at=? WHERE queue_id=?',[$eventId,$now,$this->date($this->timestamp()+self::CLAIM_DEADLINE_SECONDS),$now,$id]);
            } elseif ($eventId===$this->eventId($row,true)&&$row['attention_at']!==null) {
                $this->execute('UPDATE hotel_work_queues SET attention_ack=?,updated_at=? WHERE queue_id=?',[$eventId,$now,$id]);
            } else throw new HotelWorkQueueConflict('stale_event');
            return $this->project($this->owned($id,$admin,$connection));
        });
    }

    private function project(array $row): array
    {
        $active=!in_array($row['state'],['complete','released'],true);$index=(int)$row['stage_index'];
        $start=array_search($row['start_country'],self::COUNTRIES,true);
        if ($start===false||$index<0||$index>self::MAX_STAGE_INDEX||!in_array($row['state'],['ready','claimed','unknown','complete','released'],true)) throw new RuntimeException('hotel_work_stored_state_invalid');
        $country=self::COUNTRIES[($start+intdiv($index,2))%4];
        $kind=$index%2===0?'research':'intake';
        $unknown=null;
        if ($row['state']==='unknown') {
            $receipt=$this->one('SELECT receipt_sha256 FROM hotel_work_receipts WHERE queue_id=? AND stage_index=?',[$row['queue_id'],$index]);
            if (!$receipt) throw new RuntimeException('hotel_work_unknown_receipt_unavailable');$unknown=$receipt['receipt_sha256'];
        }
        return ['ok'=>true,'project_key'=>self::PROJECT,'queue_id'=>$row['queue_id'],'mode'=>$row['mode'],
            'oauth_connection_id'=>$row['oauth_connection_id'],'unknown_receipt_sha256'=>$unknown,
            'expected_pending_event_id'=>$row['state']==='ready'&&$row['claim_sha256']===null&&$row['current_run_id']===null?$this->eventId($row,false):null,
            'batch_limit'=>$row['batch_limit']===null?null:(int)$row['batch_limit'],'state'=>$row['state'],
            'stage_id'=>$this->stageId($row),'stage_index'=>$index,'stage_kind'=>$active?$kind:null,'country'=>$active?$country:null,
            'criteria_due'=>$active&&$kind==='intake'&&(int)$row['phase_counter']===1,'phase_counter'=>(int)$row['phase_counter'],
            'accepted_batches'=>(int)$row['accepted_batches'],'baseline_sha256'=>$row['baseline_sha256'],
            'receipt_count'=>(int)$this->one('SELECT COUNT(*) AS n FROM hotel_work_receipts WHERE queue_id=?',[$row['queue_id']])['n'],
            'skipped_batches'=>(int)$this->one('SELECT COUNT(*) AS n FROM hotel_work_research_skips WHERE queue_id=?',[$row['queue_id']])['n'],
            'current_run_id'=>$row['current_run_id'],'receipt_semantics'=>'authenticated_attestation_not_independent_file_proof',
            'delivery_state'=>$row['state']!=='ready'?$row['state']:($row['attention_at']!==null?'attention_required':($row['event_ack']!==null?'awaiting_claim':'ready')),
            'claim_deadline'=>$row['claim_deadline'],'watchdog_due_at'=>$row['watchdog_due_at'],'watchdog_epoch'=>(int)$row['watchdog_epoch'],
            'delivery_recovery'=>['automatic_recorded'=>$this->deliveryRecoveryToken($row)!==null,
                'owner_recorded'=>$this->ownerDeliveryRecoveryRecord($row)!==null],
            'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']];
    }

    private function stageId(array $row): string { return self::hash([$row['queue_id'],$row['plan_sha256'],(int)$row['stage_index']]); }
    private function eventId(array $row,bool $attention): string
    {
        $parts=[$this->stageId($row),$row['oauth_connection_id'],$attention?'unclaimed':'ready'];
        $resume=$this->deliveryResumeToken($row);
        // Preserve every legacy and e19 delivery ID until recovery is recorded.
        if ($resume!==null) $parts[]=$resume;
        $recovery=$this->deliveryRecoveryToken($row);
        if ($recovery!==null) {$parts[]='delivery_recovery_v1';$parts[]=$recovery;}
        $owner=$this->ownerDeliveryRecoveryRecord($row);
        if ($owner!==null) {$parts[]='owner_delivery_recovery_v1';$parts[]=$owner['recovery_sha256'];}
        return ($attention?'hotel_attention_':'hotel_evt_').self::hash($parts);
    }
    private function ownerDeliveryRecoveryRecord(array $row): ?array
    {
        $q=$this->pdo->prepare('SELECT * FROM hotel_work_owner_delivery_recoveries WHERE queue_id=? AND stage_id=? LIMIT 2');
        $q->execute([$row['queue_id'],$this->stageId($row)]);$records=$q->fetchAll(PDO::FETCH_ASSOC);
        if (!$records) return null;
        if (count($records)!==1) throw new RuntimeException('hotel_work_owner_recovery_invalid');
        $record=$records[0];$audit=$record;unset($audit['recovery_sha256']);
        if ((string)(int)$audit['admin_id']!==(string)$audit['admin_id']) throw new RuntimeException('hotel_work_owner_recovery_invalid');
        $audit['admin_id']=(int)$audit['admin_id'];$resume=$this->deliveryResumeRecord($row);
        $automatic=$this->deliveryRecoveryToken($row);
        if ($resume===null||$automatic===null||$audit['queue_id']!==$row['queue_id']||$audit['stage_id']!==$this->stageId($row)
            ||$audit['admin_id']!==(int)$row['admin_id']||$audit['baseline_sha256']!==$row['baseline_sha256']
            ||$audit['oauth_connection_id']!==$resume['old_connection_id']||$audit['automatic_recovery_sha256']!==$automatic) throw new RuntimeException('hotel_work_owner_recovery_invalid');
        foreach (['decision_sha256','host_readback_sha256','recovery_sha256'] as $key) self::sha($record[$key]);
        $parts=[$audit['stage_id'],$audit['oauth_connection_id'],'ready',$resume['handoff_sha256'],'delivery_recovery_v1',$automatic];
        if ($audit['pending_event_id']!=='hotel_evt_'.self::hash($parts)) throw new RuntimeException('hotel_work_owner_recovery_invalid');
        $parts[2]='unclaimed';
        if ($audit['attention_event_id']!=='hotel_attention_'.self::hash($parts)) throw new RuntimeException('hotel_work_owner_recovery_invalid');
        $delivered=self::deliveryAuditTime($audit['delivered_at']);$deadline=self::deliveryAuditTime($audit['claim_deadline']);
        $attention=self::deliveryAuditTime($audit['attention_at']);$updated=self::deliveryAuditTime($audit['previous_updated_at']);
        if ($deadline!==$delivered+self::CLAIM_DEADLINE_SECONDS||$attention<$deadline||$updated<$attention
            ||self::deliveryAuditTime($audit['created_at'])<$updated
            ||!hash_equals(self::hash(['namespace'=>'hotel_work_owner_delivery_recovery_v1','audit'=>$audit]),$record['recovery_sha256'])) throw new RuntimeException('hotel_work_owner_recovery_invalid');
        return $record;
    }
    private function deliveryResumeToken(array $row): ?string
    {
        $record=$this->deliveryResumeRecord($row);
        return $record===null?null:$record['handoff_sha256'];
    }
    private function deliveryResumeRecord(array $row): ?array
    {
        $q=$this->pdo->prepare('SELECT handoff_sha256,admin_id,old_connection_id,new_connection_id,baseline_sha256,handoff_mode,expected_run_id,claim_sha256 FROM hotel_work_connection_handoffs WHERE queue_id=? AND stage_id=? AND old_connection_id=new_connection_id LIMIT 2');
        $q->execute([$row['queue_id'],$this->stageId($row)]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;
        if (count($rows)!==1||(int)$rows[0]['admin_id']!==(int)$row['admin_id']||$rows[0]['baseline_sha256']!==$row['baseline_sha256']
            ||$rows[0]['handoff_mode']!=='ready'||$rows[0]['expected_run_id']!==null||$rows[0]['claim_sha256']!==null
            ||!is_string($rows[0]['old_connection_id'])||preg_match('/\A[a-f0-9]{32}\z/D',$rows[0]['old_connection_id'])!==1
            ||$rows[0]['new_connection_id']!==$rows[0]['old_connection_id']) throw new RuntimeException('hotel_work_delivery_resume_invalid');
        $record=$rows[0];$hash=$record['handoff_sha256'];
        if ((string)(int)$record['admin_id']!==(string)$record['admin_id']||!is_string($hash)||preg_match('/\A[a-f0-9]{64}\z/D',$hash)!==1) throw new RuntimeException('hotel_work_delivery_resume_invalid');
        $expected=self::hash([$row['queue_id'],(int)$row['admin_id'],$record['old_connection_id'],$record['new_connection_id'],$this->stageId($row),$row['baseline_sha256'],'ready',null,null]);
        if (!hash_equals($expected,$hash)) throw new RuntimeException('hotel_work_delivery_resume_invalid');
        return $record;
    }
    private function deliveryRecoveryToken(array $row): ?string
    {
        $q=$this->pdo->prepare('SELECT * FROM hotel_work_delivery_recoveries WHERE queue_id=? AND stage_id=? LIMIT 2');
        $q->execute([$row['queue_id'],$this->stageId($row)]);$records=$q->fetchAll(PDO::FETCH_ASSOC);
        if (!$records) return null;
        if (count($records)!==1) throw new RuntimeException('hotel_work_delivery_recovery_invalid');
        $record=$records[0];$fields=['queue_id','stage_id','admin_id','oauth_connection_id','baseline_sha256','resume_sha256','pending_event_id','attention_event_id','delivered_at','claim_deadline','attention_at','previous_updated_at','created_at'];
        $audit=[];
        foreach ($fields as $field) {
            if (!array_key_exists($field,$record)) throw new RuntimeException('hotel_work_delivery_recovery_invalid');
            $audit[$field]=$record[$field];
        }
        if ((string)(int)$audit['admin_id']!==(string)$audit['admin_id']) throw new RuntimeException('hotel_work_delivery_recovery_invalid');
        $audit['admin_id']=(int)$audit['admin_id'];
        $resume=$this->deliveryResumeRecord($row);
        if ($resume===null||$audit['queue_id']!==$row['queue_id']||$audit['stage_id']!==$this->stageId($row)
            ||$audit['admin_id']!==(int)$row['admin_id']||$audit['baseline_sha256']!==$row['baseline_sha256']
            ||$audit['oauth_connection_id']!==$resume['old_connection_id']||$audit['resume_sha256']!==$resume['handoff_sha256']) throw new RuntimeException('hotel_work_delivery_recovery_invalid');
        // A real later authority transfer keeps this historical budget/audit valid.
        $pending='hotel_evt_'.self::hash([$audit['stage_id'],$audit['oauth_connection_id'],'ready',$audit['resume_sha256']]);
        $attention='hotel_attention_'.self::hash([$audit['stage_id'],$audit['oauth_connection_id'],'unclaimed',$audit['resume_sha256']]);
        if ($audit['pending_event_id']!==$pending||$audit['attention_event_id']!==$attention) throw new RuntimeException('hotel_work_delivery_recovery_invalid');
        $delivered=self::deliveryAuditTime($audit['delivered_at']);$deadline=self::deliveryAuditTime($audit['claim_deadline']);
        $attentionAt=self::deliveryAuditTime($audit['attention_at']);$updated=self::deliveryAuditTime($audit['previous_updated_at']);$created=self::deliveryAuditTime($audit['created_at']);
        if ($deadline!==$delivered+self::CLAIM_DEADLINE_SECONDS||$attentionAt<$deadline||$updated<$attentionAt
            ||$created<$updated+self::DELIVERY_RECOVERY_BACKOFF_SECONDS) throw new RuntimeException('hotel_work_delivery_recovery_invalid');
        $hash=$record['recovery_sha256']??null;
        if (!is_string($hash)||preg_match('/\A[a-f0-9]{64}\z/D',$hash)!==1
            ||!hash_equals(self::hash(['namespace'=>'hotel_work_delivery_recovery_v1','audit'=>$audit]),$hash)) throw new RuntimeException('hotel_work_delivery_recovery_invalid');
        return $hash;
    }
    private function recoverUnclaimedDelivery(array $row): ?array
    {
        // Called only while the existing HOTEL lane and exact queue row are locked.
        if ($row['state']!=='ready'||$row['claim_sha256']!==null||$row['current_run_id']!==null) return null;
        $resume=$this->deliveryResumeRecord($row);
        if ($resume===null||!hash_equals($resume['old_connection_id'],$row['oauth_connection_id'])||$this->deliveryRecoveryToken($row)!==null) return null;
        $pending=$this->eventId($row,false);$attention=$this->eventId($row,true);
        if ($row['event_ack']!==$pending||$row['attention_ack']!==$attention||$row['attention_at']===null
            ||$row['delivered_at']===null||$row['claim_deadline']===null) return null;
        $delivered=self::deliveryAuditTime($row['delivered_at']);$deadline=self::deliveryAuditTime($row['claim_deadline']);
        $attentionAt=self::deliveryAuditTime($row['attention_at']);$updated=self::deliveryAuditTime($row['updated_at']);$at=$this->timestamp();
        if ($deadline!==$delivered+self::CLAIM_DEADLINE_SECONDS||$attentionAt<$deadline||$updated<$attentionAt) throw new RuntimeException('hotel_work_delivery_recovery_invalid');
        if ($deadline>$at||$updated>$at-self::DELIVERY_RECOVERY_BACKOFF_SECONDS) return null;
        if ($this->one('SELECT stage_id FROM hotel_work_receipts WHERE queue_id=? AND stage_id=?',[$row['queue_id'],$this->stageId($row)])) return null;
        // No persisted active/UNKNOWN work anywhere in this shared HOTEL admission scope.
        if ($this->one('SELECT queue_id FROM hotel_work_queues WHERE project_key=? AND (state IN (?,?) OR claim_sha256 IS NOT NULL OR current_run_id IS NOT NULL) LIMIT 1',[self::PROJECT,'claimed','unknown'])) return null;
        $now=$this->date($at);
        $audit=['queue_id'=>$row['queue_id'],'stage_id'=>$this->stageId($row),'admin_id'=>(int)$row['admin_id'],
            'oauth_connection_id'=>$row['oauth_connection_id'],'baseline_sha256'=>$row['baseline_sha256'],'resume_sha256'=>$resume['handoff_sha256'],
            'pending_event_id'=>$pending,'attention_event_id'=>$attention,'delivered_at'=>$row['delivered_at'],'claim_deadline'=>$row['claim_deadline'],
            'attention_at'=>$row['attention_at'],'previous_updated_at'=>$row['updated_at'],'created_at'=>$now];
        $hash=self::hash(['namespace'=>'hotel_work_delivery_recovery_v1','audit'=>$audit]);
        $this->execute('INSERT INTO hotel_work_delivery_recoveries (recovery_sha256,queue_id,stage_id,admin_id,oauth_connection_id,baseline_sha256,resume_sha256,pending_event_id,attention_event_id,delivered_at,claim_deadline,attention_at,previous_updated_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$hash,...array_values($audit)]);
        $this->execute('UPDATE hotel_work_queues SET event_ack=NULL,delivered_at=NULL,claim_deadline=NULL,attention_at=NULL,attention_ack=NULL,updated_at=? WHERE queue_id=?',[$now,$row['queue_id']]);
        return $this->owned($row['queue_id'],(int)$row['admin_id'],$row['oauth_connection_id']);
    }
    private static function deliveryAuditTime(mixed $value): int
    {
        if (!is_string($value)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D',$value)!==1) throw new RuntimeException('hotel_work_delivery_recovery_invalid');
        $at=strtotime($value.' UTC');
        if ($at===false||$at<1||gmdate('Y-m-d H:i:s',$at)!==$value) throw new RuntimeException('hotel_work_delivery_recovery_invalid');
        return $at;
    }
    private function watchEventId(array $row): string
    {
        return 'hotel_attention_'.self::hash([$this->stageId($row),$row['oauth_connection_id'],'watchdog',(int)$row['watchdog_epoch']]);
    }
    private function validateAccepted(array $row,array $receipt): void
    {
        $index=(int)$row['stage_index'];$intake=$index%2===1;$due=$intake&&(int)$row['phase_counter']===1;
        if (($due&&$receipt['criteria']===null)||(!$due&&$receipt['criteria']!==null)) throw new HotelWorkQueueConflict('criteria_mismatch');
        if ($intake) {
            $prior=$this->one('SELECT receipt_sha256,receipt_json FROM hotel_work_receipts WHERE queue_id=? AND stage_index=?',[$row['queue_id'],$index-1]);
            $source=$prior?json_decode($prior['receipt_json'],true,16,JSON_THROW_ON_ERROR):null;
            if ($source&&$source['outcome']==='unknown') {
                $resolution=$this->one('SELECT unknown_receipt_sha256,receipt_json FROM hotel_work_resolutions WHERE queue_id=? AND stage_index=?',[$row['queue_id'],$index-1]);
                $source=$resolution&&hash_equals($prior['receipt_sha256'],$resolution['unknown_receipt_sha256'])?json_decode($resolution['receipt_json'],true,16,JSON_THROW_ON_ERROR):null;
            }
            if (!$source||$source['outcome']!=='accepted_readback'||!hash_equals($source['packet_sha256'],$receipt['packet_sha256'])) throw new HotelWorkQueueConflict('source_packet_mismatch');
        }
    }
    private function advance(array $row,string $now): array
    {
        $id=$row['queue_id'];$index=(int)$row['stage_index'];$intake=$index%2===1;$next=$index+1;
        if ($next>self::MAX_STAGE_INDEX) throw new HotelWorkQueueConflict('stage_limit_reached');
        $accepted=(int)$row['accepted_batches']+($intake?1:0);
        $counter=$intake?((int)$row['phase_counter']===1?0:1):(int)$row['phase_counter'];
        $state=$row['mode']==='finite'&&$accepted>=(int)$row['batch_limit']?'complete':'ready';
        $this->execute('UPDATE hotel_work_queues SET stage_index=?,state=?,phase_counter=?,accepted_batches=?,claim_sha256=NULL,current_run_id=NULL,event_ack=NULL,delivered_at=NULL,claim_deadline=NULL,attention_at=NULL,attention_ack=NULL,watchdog_due_at=NULL,watchdog_epoch=0,last_watch_ack=NULL,updated_at=? WHERE queue_id=?',[$next,$state,$counter,$accepted,$now,$id]);
        if ($state==='complete') $this->execute('UPDATE hotel_work_lane SET queue_id=NULL WHERE lane_key=? AND queue_id=?',['HOTEL',$id]);
        return $this->owned($id,(int)$row['admin_id'],$row['oauth_connection_id']);
    }
    private function eventData(array $row,bool $dispatch): array
    {
        $projection=$this->project($row);
        return ['queue_id'=>$row['queue_id'],'stage_id'=>$projection['stage_id'],'stage_kind'=>$projection['stage_kind'],
            'country'=>$projection['country'],'dispatch_allowed'=>$dispatch];
    }
    private function sameStage(array $row,string $stage): void { if (!hash_equals($this->stageId($row),$stage)) throw new HotelWorkQueueConflict('stale_stage'); }
    private function claimed(array $row,string $stage,string $claim): void
    {
        $this->sameStage($row,$stage);
        if ($row['state']!=='claimed'||!hash_equals((string)$row['claim_sha256'],$claim)) throw new HotelWorkQueueConflict('claim_mismatch');
    }
    private function claimArgs(array $args): array { return [self::queueId($args['queue_id']??null),self::sha($args['stage_id']??null),hash('sha256',self::sha($args['claim_key']??null))]; }
    private function owned(string $id,int $admin,string $connection,bool $lock=false): array
    {
        self::actor($admin,$connection);$row=$this->one('SELECT * FROM hotel_work_queues WHERE queue_id=?'.($lock?$this->lock():''),[$id]);
        if (!$row) throw new DomainException('hotel_work_access_denied');$this->authorize($row,$admin,$connection);return $row;
    }
    private function authorize(array $row,int $admin,string $connection): void
    {
        if ($row['project_key']!==self::PROJECT||(int)$row['admin_id']!==$admin||!hash_equals($row['oauth_connection_id'],$connection)) throw new DomainException('hotel_work_access_denied');
    }
    private function lane(bool $lock): array
    {
        $row=$this->one('SELECT * FROM hotel_work_lane WHERE lane_key=?'.($lock?$this->lock():''),['HOTEL']);
        if (!$row) throw new RuntimeException('hotel_work_lane_unavailable');return $row;
    }
    private function ownsLane(array $row,array $lane): void
    {
        if ($lane['queue_id']!==$row['queue_id']) throw new HotelWorkQueueConflict('hotel_lane_not_owned');
    }
    private function one(string $sql,array $values): ?array { $q=$this->pdo->prepare($sql);$q->execute($values);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null; }
    private function execute(string $sql,array $values): void { $q=$this->pdo->prepare($sql);$q->execute($values); }
    private function lock(): string { return $this->driver==='mysql'?' FOR UPDATE':''; }
    private function outsideTransaction(): void { if ($this->pdo->inTransaction()) throw new LogicException('hotel_work_foreign_transaction'); }
    private function transaction(callable $work): mixed
    {
        $this->outsideTransaction();
        try { if ($this->driver==='sqlite') $this->pdo->exec('BEGIN IMMEDIATE');else $this->pdo->beginTransaction(); }
        catch (Throwable $ignored) { throw new RuntimeException('hotel_work_storage_unavailable'); }
        try {
            $result=$work();
            if ($this->driver==='sqlite') $this->pdo->exec('COMMIT');else $this->pdo->commit();return $result;
        } catch (Throwable $error) {
            try { if ($this->driver==='sqlite') $this->pdo->exec('ROLLBACK');elseif ($this->pdo->inTransaction()) $this->pdo->rollBack(); }
            catch (Throwable $ignored) { throw new RuntimeException('hotel_work_rollback_failed'); }
            if ($error instanceof HotelWorkQueueConflict||$error instanceof DomainException||$error instanceof InvalidArgumentException) throw $error;
            throw new RuntimeException('hotel_work_storage_unavailable');
        }
    }
    private function timestamp(): int { $at=$this->clock===null?time():($this->clock)();if (!is_int($at)||$at<1) throw new RuntimeException('hotel_work_clock_unavailable');return $at; }
    private function date(int $at): string { return gmdate('Y-m-d H:i:s',$at); }
    private function now(): string { return $this->date($this->timestamp()); }
    private static function actor(int $admin,string $connection): void { if ($admin<1||preg_match('/\A[a-f0-9]{32}\z/D',$connection)!==1) throw new DomainException('hotel_work_access_denied'); }
    private static function queueId(mixed $id): string { if (!is_string($id)||preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D',$id)!==1) throw new InvalidArgumentException('invalid_hotel_work_id');return $id; }
    private static function sha(mixed $hash): string { if (!is_string($hash)||preg_match('/\A[a-f0-9]{64}\z/D',$hash)!==1) throw new InvalidArgumentException('invalid_hotel_work_digest');return $hash; }
    private static function runId(mixed $id): string { if (!is_string($id)||preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$id)!==1) throw new InvalidArgumentException('invalid_hotel_work_run');return $id; }
    private static function keys(array $args,array $allowed): void { if (array_is_list($args)&&$args!==[]||array_diff(array_keys($args),$allowed)) throw new InvalidArgumentException('invalid_hotel_work_fields'); }
    private static function canonical(array $value): string
    {
        $sort=static function(array $array) use (&$sort): array { ksort($array,SORT_STRING);foreach ($array as &$item) if (is_array($item)) $item=$sort($item);return $array; };
        return json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    private static function hash(array $value): string { return hash('sha256',self::canonical($value)); }
    private static function receipt(mixed $receipt): array
    {
        $fields=['run_id','outcome','packet_sha256','readback_sha256','ledger_sha256','hotel_count','record_count','source_count','read_update_count','criteria'];
        if (!is_array($receipt)||array_is_list($receipt)||count($receipt)!==count($fields)||array_diff($fields,array_keys($receipt))) throw new InvalidArgumentException('invalid_hotel_work_receipt');
        if (!in_array($receipt['outcome'],['accepted_readback','unknown'],true)) throw new InvalidArgumentException('invalid_hotel_work_outcome');
        $unknown=$receipt['outcome']==='unknown';
        if (!$unknown||$receipt['run_id']!==null) self::runId($receipt['run_id']);
        foreach (['packet_sha256','readback_sha256','ledger_sha256'] as $key) if (!$unknown||$receipt[$key]!==null) self::sha($receipt[$key]);
        foreach (['hotel_count'=>8,'record_count'=>200,'source_count'=>200,'read_update_count'=>200] as $key=>$max) {
            $value=$receipt[$key];if ($unknown&&$value===null) continue;
            if (!is_int($value)||$value<0||$value>$max||(!$unknown&&in_array($key,['hotel_count','record_count'],true)&&$value===0)) throw new InvalidArgumentException('invalid_hotel_work_counts');
        }
        if ($receipt['criteria']!==null) {
            $c=$receipt['criteria'];$keys=['readback_sha256','case_count','done_count','partial_count'];
            if (!is_array($c)||array_is_list($c)||count($c)!==4||array_diff($keys,array_keys($c))) throw new InvalidArgumentException('invalid_hotel_work_criteria');
            self::sha($c['readback_sha256']);
            foreach (['case_count','done_count','partial_count'] as $key) if (!is_int($c[$key])||$c[$key]<0||$c[$key]>20) throw new InvalidArgumentException('invalid_hotel_work_criteria');
            if ($c['case_count']<1||$c['done_count']+$c['partial_count']!==$c['case_count']) throw new InvalidArgumentException('invalid_hotel_work_criteria');
        }
        return $receipt;
    }
}

final class HotelWorkQueueConflict extends RuntimeException
{
    public function __construct(public readonly string $reason) { parent::__construct($reason); }
}

