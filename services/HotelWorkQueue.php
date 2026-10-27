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
        $this->outsideTransaction();
        return $this->project($this->owned(self::queueId($queueId),$admin,$connection));
    }

    public function claim(int $admin,string $connection,array $args): array
    {
        self::keys($args,['queue_id','stage_id','claim_key']);[$id,$stage,$claim]=$this->claimArgs($args);
        return $this->transaction(function() use($id,$stage,$claim,$admin,$connection): array {
            $lane=$this->lane(true);$row=$this->owned($id,$admin,$connection,true);$this->ownsLane($row,$lane);
            $this->sameStage($row,$stage);
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

    /** Explicit authorization handoff. Proof is mode-aware and server-owned, never MCP input. */
    public function handoff_connection(int $admin,string $newConnection,array $args,callable $oldConnectionProof): array
    {
        self::keys($args,['queue_id','stage_id','baseline_sha256','old_connection_id','handoff_mode','claim_key','expected_run_id']);
        $id=self::queueId($args['queue_id']??null);$stage=self::sha($args['stage_id']??null);$baseline=self::sha($args['baseline_sha256']??null);
        $old=$args['old_connection_id']??null;self::actor($admin,$newConnection);
        if (!is_string($old)) throw new InvalidArgumentException('invalid_hotel_work_connection');self::actor($admin,$old);
        if (hash_equals($old,$newConnection)) throw new InvalidArgumentException('same_hotel_work_connection');
        $mode=array_key_exists('handoff_mode',$args)?$args['handoff_mode']:'ready';$claim=null;$run=null;
        if (!in_array($mode,['ready','existing_run'],true)) throw new InvalidArgumentException('invalid_hotel_work_handoff_mode');
        if ($mode==='existing_run') {$claim=hash('sha256',self::sha($args['claim_key']??null));$run=self::runId($args['expected_run_id']??null);}
        elseif (array_key_exists('claim_key',$args)||array_key_exists('expected_run_id',$args)) throw new InvalidArgumentException('unexpected_hotel_work_handoff_proof');
        $handoff=self::hash([$id,$admin,$old,$newConnection,$stage,$baseline,$mode,$claim,$run]);
        return $this->transaction(function() use($id,$stage,$baseline,$old,$newConnection,$admin,$handoff,$oldConnectionProof,$mode,$claim,$run): array {
            $lane=$this->lane(true);$row=$this->one('SELECT * FROM hotel_work_queues WHERE queue_id=?'.$this->lock(),[$id]);
            if (!$row||$row['project_key']!==self::PROJECT||(int)$row['admin_id']!==$admin) throw new DomainException('hotel_work_access_denied');
            $prior=$this->one('SELECT handoff_sha256 FROM hotel_work_connection_handoffs WHERE handoff_sha256=?',[$handoff]);
            if ($prior) {
                $this->authorize($row,$admin,$newConnection);
                return $this->project($row)+['handoff_status'=>'already_recorded'];
            }
            if ($this->one('SELECT handoff_sha256 FROM hotel_work_connection_handoffs WHERE queue_id=? AND old_connection_id=? LIMIT 1',[$id,$newConnection])) {
                throw new HotelWorkQueueConflict('binding_relinquished');
            }
            if (!hash_equals($row['oauth_connection_id'],$old)) throw new DomainException('hotel_work_access_denied');
            $this->sameStage($row,$stage);$this->ownsLane($row,$lane);
            if (!hash_equals($row['baseline_sha256'],$baseline)) throw new HotelWorkQueueConflict('baseline_mismatch');
            if ($mode==='ready') {
                if ($row['state']!=='ready'||$row['claim_sha256']!==null||$row['current_run_id']!==null) throw new HotelWorkQueueConflict('not_known_idle');
            } elseif (!in_array($row['state'],['claimed','unknown'],true)||$row['current_run_id']===null
                ||!hash_equals($row['current_run_id'],$run)||!hash_equals((string)$row['claim_sha256'],$claim)) throw new HotelWorkQueueConflict('existing_run_proof_mismatch');
            // READY transfers control of unclaimed metadata under the lane lock.
            // Existing-run adoption still requires definitive old-grant retirement.
            try {$proven=$oldConnectionProof($old,$admin,$mode)===true;} catch (Throwable $ignored) {$proven=false;}
            if (!$proven) throw new HotelWorkQueueConflict($mode==='ready'?'old_connection_not_known_hotel_owner':'old_connection_not_definitively_retired');
            $now=$this->now();
            $this->execute('INSERT INTO hotel_work_connection_handoffs (handoff_sha256,queue_id,admin_id,old_connection_id,new_connection_id,stage_id,baseline_sha256,handoff_mode,expected_run_id,claim_sha256,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$handoff,$id,$admin,$old,$newConnection,$stage,$baseline,$mode,$run,$claim,$now]);
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
                if ($row['attention_ack']===$attention) return null;
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
            'batch_limit'=>$row['batch_limit']===null?null:(int)$row['batch_limit'],'state'=>$row['state'],
            'stage_id'=>$this->stageId($row),'stage_index'=>$index,'stage_kind'=>$active?$kind:null,'country'=>$active?$country:null,
            'criteria_due'=>$active&&$kind==='intake'&&(int)$row['phase_counter']===1,'phase_counter'=>(int)$row['phase_counter'],
            'accepted_batches'=>(int)$row['accepted_batches'],'baseline_sha256'=>$row['baseline_sha256'],
            'receipt_count'=>$index+($row['state']==='unknown'?1:0),
            'current_run_id'=>$row['current_run_id'],'receipt_semantics'=>'authenticated_attestation_not_independent_file_proof',
            'delivery_state'=>$row['state']!=='ready'?$row['state']:($row['attention_at']!==null?'attention_required':($row['event_ack']!==null?'awaiting_claim':'ready')),
            'claim_deadline'=>$row['claim_deadline'],'watchdog_due_at'=>$row['watchdog_due_at'],'watchdog_epoch'=>(int)$row['watchdog_epoch'],
            'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']];
    }

    private function stageId(array $row): string { return self::hash([$row['queue_id'],$row['plan_sha256'],(int)$row['stage_index']]); }
    private function eventId(array $row,bool $attention): string
    {
        return ($attention?'hotel_attention_':'hotel_evt_').self::hash([$this->stageId($row),$row['oauth_connection_id'],$attention?'unclaimed':'ready']);
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
