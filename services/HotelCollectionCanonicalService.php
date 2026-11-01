<?php
declare(strict_types=1);

require_once __DIR__.'/HotelCollectionCanonicalStore.php';
require_once __DIR__.'/ManagerPreparationOAuth.php';
require_once __DIR__.'/HotelWorkingSnapshot.php';

/** Internal control APIs + two capability-bound worker APIs; no native launch,
 * queue advancement, runtime migration or Library loader. Constructor identity
 * comes from the authenticated MCP boundary and is rechecked in SQL each call.
 */
final class HotelCollectionCanonicalService
{
    private string $driver;
    private const MAX_READ_BYTES = 65536;

    public function __construct(private PDO $pdo, private int $admin, private string $connection, private $clock = null, private $fault = null)
    {
        $this->driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver, ['sqlite','mysql'], true) || $admin < 1 || preg_match('/\A[a-f0-9]{32}\z/D', $connection) !== 1
            || ($clock !== null && !is_callable($clock)) || ($fault !== null && !is_callable($fault))) throw new InvalidArgumentException('canonical_service_context_invalid');
    }

    /** CONTROL ONLY. Approved import, not a worker-selected Library pointer. */
    public function bootstrap(string $queue, string $stage, int $generation, string $control, string $bytes, string $approvedSha, array $index, string $indexAttestation, array $reserveInventory, string $reserveAttestation): array
    {
        return $this->tx('bootstrap', function () use ($queue,$stage,$generation,$control,$bytes,$approvedSha,$index,$indexAttestation,$reserveInventory,$reserveAttestation): array {
            [$q,$h] = $this->locked($queue,$stage,true); $this->control($h,$control,$generation);
            self::sha($approvedSha);
            if ($approvedSha !== $q['baseline_sha256'] || $approvedSha !== $h['baseline_sha256']) $this->deny('baseline_binding_mismatch');
            $inventory = self::inventory($reserveInventory); self::attestation($reserveAttestation);
            $policy = $this->one('SELECT * FROM hotel_collection_policy WHERE queue_id=?'.$this->lock(),[$queue]);
            if ($policy === null) $this->writePolicy($queue,0,$inventory,$reserveAttestation);
            else { $policy = $this->policy($queue); if (self::json($inventory) !== self::json($policy['inventory']) || $policy['attestation'] !== $reserveAttestation) $this->deny('bootstrap_policy_conflict'); }
            $store = $this->store($q,$h,null,'control',['baseline_sha256'=>$approvedSha,'baseline_index'=>$index,'baseline_index_attestation'=>$indexAttestation]);
            return $store->bootstrap($queue,$bytes);
        });
    }

    /** Current WORKING has a separate data pin; never rewrite queue baseline. */
    public function importWorking(string $queue,string $stage,int $generation,string $control,string $request,string $bytes,string $sha):array
    {
        return $this->tx('import_working',function()use($queue,$stage,$generation,$control,$request,$bytes,$sha):array{
            [$q,$h]=$this->locked($queue,$stage,true);$this->control($h,$control,$generation);self::id($request);self::sha($sha);
            $result=(new HotelWorkingSnapshot($this->pdo))->import($q,$bytes,$sha,$request);
            $this->execute('UPDATE hotel_work_queues SET data_snapshot_sha256=? WHERE queue_id=?',[$sha,$queue]);return $result;
        });
    }
    /** Control-only full byte/pointer reader, including preserved variants/history. */
    public function readWorking(string $queue,string $stage,int $generation,string $control,string $sha,string $pointer,int $offset=0):array
    {
        return $this->tx('read_working',function()use($queue,$stage,$generation,$control,$sha,$pointer,$offset):array{
            [$q,$h]=$this->locked($queue,$stage,false);$this->control($h,$control,$generation);self::sha($sha);
            return(new HotelWorkingSnapshot($this->pdo))->read($queue,$sha,$pointer,$offset);
        });
    }

    /** Complete immutable source bytes for CONTROL-authorized scope projections.
     * A worker may read only a hotel-index row or an exact assigned knowledge ID.
     */
    public function workerWorkingRead(array $args,string $sha,string $pointer,int $offset=0):array
    {
        self::sha($sha);
        return $this->workerCall('worker_working_read',$args,function(HotelCollectionCanonicalStore $store,array $a)use($sha,$pointer,$offset):array{
            $assignment=$store->readAssignment($a['queue_id'],$a['operation_id']);$keys=$assignment['context']['hotel_keys'];
            $parts=explode('/',ltrim($pointer,'/'));
            if(count($parts)===2&&$parts[0]==='hotel_index'&&in_array($parts[1],$keys,true)){}
            elseif(count($parts)===3&&$parts[0]==='knowledge'&&in_array($parts[1],['claims','sources_including_unclaimed','source_read_updates'],true)){
                $row=$this->one('SELECT body_bytes,data_sha256 FROM hotel_working_imports WHERE queue_id=?',[$a['queue_id']]);
                if(!$row||$row['data_sha256']!==$sha||hash('sha256',$row['body_bytes'])!==$sha)$this->deny('working_snapshot_unavailable');
                $doc=HotelCollectionCanonicalDocument::decodeObject($row['body_bytes']);$field=$parts[1]==='claims'?'claim_ids':($parts[1]==='sources_including_unclaimed'?'source_ids':'read_update_ids');
                $allowed=[];foreach($keys as$key)$allowed=array_merge($allowed,$doc['hotel_index'][$key][$field]??[]);
                if(!in_array($parts[2],$allowed,true))$this->deny('working_scope_required');
            }else $this->deny('working_scope_required');
            return(new HotelWorkingSnapshot($this->pdo))->read($a['queue_id'],$sha,$pointer,$offset);
        });
    }

    /** CONTROL arms delivery only for the exact ready protected stage. */
    public function queueCycle(string $queue,string $stage,int $generation,string $control):array
    {
        return $this->tx('queue_cycle',function()use($queue,$stage,$generation,$control):array{
            [$q,$h]=$this->locked($queue,$stage,true);$this->control($h,$control,$generation);
            if($q['state']!=='ready'||$q['claim_sha256']!==null||$q['current_run_id']!==null)$this->deny('unresolved_work_cannot_arm');
            $import=$this->one('SELECT data_sha256 FROM hotel_working_imports WHERE queue_id=?',[$queue]);if(!$import)$this->deny('working_import_required');
            $id=hash('sha256',self::json([$queue,$stage,'protected_pending']));
            $old=$this->one('SELECT event_id FROM hotel_collection_outbox WHERE event_id=?',[$id]);
            if(!$old)$this->execute('INSERT INTO hotel_collection_outbox(event_id,queue_id,stage_id,state) VALUES(?,?,?,?)',[$id,$queue,$stage,'pending']);
            return ['event_id'=>'hotel_evt_'.$id,'dispatch_authority'=>false];
        });
    }
    /** Server adapter resolves task/capability from stored authority, not caller routing. */
    public function dispatchIdentity(string $queue,string $stage,int $generation,string $control,string $operation,string $capability,string $task):array
    {
        return $this->tx('dispatch_identity',function()use($queue,$stage,$generation,$control,$operation,$capability,$task):array{
            [$q,$h]=$this->locked($queue,$stage,true);$this->control($h,$control,$generation);
            if((int)$q['stage_index']%2===1){
                $r=$this->one('SELECT * FROM hotel_collection_reviews WHERE queue_id=? AND intake_stage=?',[$queue,$stage]);
                if(!$r||$r['review_task']!==$task||!hash_equals($r['capability_sha256'],hash('sha256',$capability)))$this->deny('review_dispatch_identity');
                return ['task_id'=>$task,'role'=>'reviewer'];
            }
            $op=$this->operation($q,$h,$operation,['prepared']);
            if($op['task_id']!==$task||!hash_equals($op['capability_sha256'],hash('sha256',$capability)))$this->deny('producer_dispatch_identity');
            $this->store($q,$h,$op,'control')->readAssignment($queue,$operation);
            return ['task_id'=>$task,'role'=>'producer'];
        });
    }
    /** CONTROL: once-only transport permit. Lost ACK remains UNKNOWN, never retry. */
    public function consumeDispatch(string $queue,string $stage,int $generation,string $control,string $eventId):array
    {
        return $this->tx('consume_dispatch',function()use($queue,$stage,$generation,$control,$eventId):array{
            [$q,$h]=$this->locked($queue,$stage,true);$this->control($h,$control,$generation);
            $id=substr($eventId,10);if($eventId!=='hotel_evt_'.$id||!preg_match('/\\A[a-f0-9]{64}\\z/D',$id))$this->deny('event_identity_invalid');
            $row=$this->one('SELECT * FROM hotel_collection_outbox WHERE event_id=? AND queue_id=? AND stage_id=?',[$id,$queue,$stage]);
            if(!$row)$this->deny('pending_event_missing');
            if($row['state']==='dispatch_unknown')return ['dispatch_authority'=>false,'state'=>'UNKNOWN'];
            if($row['state']!=='delivered')$this->deny('cron_delivery_required');
            $this->execute('UPDATE hotel_collection_outbox SET state=? WHERE event_id=?',['dispatch_unknown',$id]);
            return ['dispatch_authority'=>true,'state'=>'UNKNOWN','event_id'=>$eventId];
        });
    }
    /** Authenticated worker entry; exact receipt replay grants no fresh work. */
    public function enterProducer(array $args,string $request):array
    {
        self::keys($args,['queue_id','stage_id','generation','operation_id','worker_capability','instance_id']);self::id($request);
        // Recheck OAuth before the journal's own transaction, which rechecks queue identity.
        $this->tx('entry_auth',function()use($args):array{$this->locked($args['queue_id'],$args['stage_id'],true);return [];});
        require_once __DIR__.'/HotelWorkOperationJournalPrototype.php';
        return(new HotelWorkOperationJournalPrototype($this->pdo,$this->admin,$this->connection))->admit($args['queue_id'],$args['stage_id'],$args['generation'],$args['operation_id'],$args['worker_capability'],$request,$args['instance_id']);
    }

    /** CONTROL: seal producer bytes, advance only research, issue private reviewer key. */
    public function sealResearch(string $queue,string $stage,int $generation,string $control,string $operation,string $run,string $reviewTask):array
    {
        return $this->tx('seal_research',function()use($queue,$stage,$generation,$control,$operation,$run,$reviewTask):array{
            [$q,$h]=$this->locked($queue,$stage,false);$this->control($h,$control,$generation);self::id($run);self::id($reviewTask);
            $prior=$this->one('SELECT * FROM hotel_collection_reviews WHERE queue_id=? AND producer_operation=?',[$queue,$operation]);
            if($prior){if($prior['producer_run']!==$run||$prior['review_task']!==$reviewTask)$this->deny('review_request_conflict');return self::decode($prior['private_receipt']);}
            $this->current($q,$stage);if((int)$q['stage_index']%2!==0)$this->deny('research_stage_required');
            $op=$this->operation($q,$h,$operation,['admitted','candidate_recorded']);
            if($reviewTask===$op['task_id'])$this->deny('independent_reviewer_required');
            $candidate=$this->store($q,$h,$op,'control')->readCandidate($queue,$operation);
            $raw=self::decode($candidate['raw_bytes']);
            $import=$this->one('SELECT * FROM hotel_working_imports WHERE queue_id=?',[$queue]);
            if(!$import||hash('sha256',$import['body_bytes'])!==$import['data_sha256'])$this->deny('working_import_required');
            if(str_contains($import['body_bytes'],$candidate['raw_sha256'])||str_contains($import['body_bytes'],$raw['source_packet_id']))$this->deny('historical_packet_replay');
            $this->cycleReceipt($q,$stage,$run,$candidate['raw_sha256'],null);
            $next=$this->stage($this->cycleAdvance($q,false));$key=bin2hex(random_bytes(32));
            $this->execute('INSERT INTO hotel_journal_heads(queue_id,stage_id,baseline_sha256,generation,control_sha256,owner_label) VALUES(?,?,?,?,?,?)',[$queue,$next,$h['baseline_sha256'],1,$h['control_sha256'],$h['owner_label']]);
            $receipt=['intake_stage_id'=>$next,'review_capability'=>$key,'candidate_sha256'=>$candidate['raw_sha256'],'dispatch_authority'=>false];
            $this->execute('INSERT INTO hotel_collection_reviews(queue_id,intake_stage,producer_stage,producer_operation,producer_run,review_task,capability_sha256,private_receipt) VALUES(?,?,?,?,?,?,?,?)',[$queue,$next,$stage,$operation,$run,$reviewTask,hash('sha256',$key),self::json($receipt)]);
            return self::decode(self::json($receipt));
        });
    }
    /** Separate reviewer authority: never use the producer capability. */
    public function reviewContext(array $args):array
    {
        return $this->tx('review_context',function()use($args):array{
            [$q,$h,$r]=$this->reviewAuthority($args);
            $candidate=$this->one('SELECT raw_bytes,raw_sha256 FROM hotel_collection_candidates WHERE queue_id=? AND operation_id=?',[$q['queue_id'],$r['producer_operation']]);
            if(!$candidate||hash('sha256',$candidate['raw_bytes'])!==$candidate['raw_sha256'])$this->deny('candidate_unavailable');
            return $this->bounded(['producer_operation'=>$r['producer_operation'],'candidate'=>$candidate,'criteria_due'=>(int)$q['phase_counter']===1,'business_accepted'=>false]);
        });
    }
    public function submitReview(array $args,array $verdict):array
    {
        self::keys($verdict,['candidate_sha256','decision','criteria','review_run']);self::sha($verdict['candidate_sha256']);self::id($verdict['review_run']);
        if(!in_array($verdict['decision'],['accept','hold','reject'],true))throw new InvalidArgumentException('review_decision');
        return $this->tx('submit_review',function()use($args,$verdict):array{
            [$q,$h,$r]=$this->reviewAuthority($args);if($verdict['review_run']===$r['producer_run'])$this->deny('independent_review_run_required');
            $candidate=$this->one('SELECT raw_sha256 FROM hotel_collection_candidates WHERE queue_id=? AND operation_id=?',[$q['queue_id'],$r['producer_operation']]);
            if(!$candidate||$candidate['raw_sha256']!==$verdict['candidate_sha256'])$this->deny('review_candidate_mismatch');
            $this->cycleCriteria($q,$verdict['criteria']);$bytes=self::json($verdict);
            if($r['verdict_bytes']!==null&&$r['verdict_bytes']!==$bytes)$this->deny('immutable_review_conflict');
            $this->execute('UPDATE hotel_collection_reviews SET verdict_bytes=? WHERE queue_id=? AND intake_stage=?',[$bytes,$q['queue_id'],$r['intake_stage']]);
            return ['status'=>'review_recorded','verdict_sha256'=>hash('sha256',$bytes),'business_accepted'=>false];
        });
    }
    /** CONTROL: canonical data + domain receipt + queue advance + outbox atomically. */
    public function finalizeIntake(string $queue,string $stage,int $generation,string $control,string $request,string $expectedVerdict,int $revision,string $headHash):array
    {
        return $this->tx('finalize_intake',function()use($queue,$stage,$generation,$control,$request,$expectedVerdict,$revision,$headHash):array{
            [$q,$h]=$this->locked($queue,$stage,false);$this->control($h,$control,$generation);self::id($request);self::sha($expectedVerdict);
            $r=$this->one('SELECT * FROM hotel_collection_reviews WHERE queue_id=? AND intake_stage=?',[$queue,$stage]);
            if(!$r||$r['verdict_bytes']===null||hash('sha256',$r['verdict_bytes'])!==$expectedVerdict)$this->deny('review_required');
            $input=hash('sha256',self::json([$request,$expectedVerdict,$revision,$headHash]));
            if($r['acceptance_bytes']!==null){if($r['finalize_sha256']!==$input)$this->deny('finalize_conflict');return self::decode($r['acceptance_bytes']);}
            $this->current($q,$stage);$v=self::decode($r['verdict_bytes']);if($v['decision']!=='accept')$this->deny('review_not_accepted');$this->cycleCriteria($q,$v['criteria']);
            $producer=$this->one('SELECT * FROM hotel_journal_heads WHERE queue_id=? AND stage_id=?'.$this->lock(),[$queue,$r['producer_stage']]);
            if(!$producer)$this->deny('producer_head_missing');
            $op=$this->operation($q,$producer,$r['producer_operation'],['admitted','candidate_recorded']);
            $commit=$this->store($q,$producer,$op,'control')->commit($queue,$op['operation_id'],$request,$v['candidate_sha256'],$revision,$headHash);
            if($this->fault!==null)($this->fault)('cycle_data_committed_before_queue');
            $this->cycleReceipt($q,$stage,$v['review_run'],$v['candidate_sha256'],$v['criteria']);
            $next=$this->cycleAdvance($q,true);
            if($next['state']==='ready')$this->execute('INSERT INTO hotel_journal_heads(queue_id,stage_id,baseline_sha256,generation,control_sha256,owner_label) VALUES(?,?,?,?,?,?)',[$queue,$this->stage($next),$h['baseline_sha256'],1,$h['control_sha256'],$h['owner_label']]);
            $accept=$commit+['intake_stage_id'=>$stage,'next_stage_id'=>$this->stage($next),'accepted_batches'=>(int)$next['accepted_batches']];
            $accept['business_accepted']=true;$bytes=self::json($accept);
            $this->execute('UPDATE hotel_collection_reviews SET acceptance_bytes=?,finalize_sha256=? WHERE queue_id=? AND intake_stage=?',[$bytes,$input,$queue,$stage]);
            $this->execute("UPDATE hotel_journal_operations SET state='snapshot_committed' WHERE operation_id=?",[$op['operation_id']]);
            return self::decode($bytes);
        });
    }
    private function reviewAuthority(array $a):array
    {
        self::keys($a,['queue_id','stage_id','generation','review_capability','instance_id']);self::sha($a['review_capability']);self::id($a['instance_id']);
        [$q,$h]=$this->locked($a['queue_id'],$a['stage_id'],true);
        if(!is_int($a['generation'])||(int)$h['generation']!==$a['generation'])$this->deny('stale_generation');
        $r=$this->one('SELECT * FROM hotel_collection_reviews WHERE queue_id=? AND intake_stage=?',[$a['queue_id'],$a['stage_id']]);
        if(!$r||!hash_equals($r['capability_sha256'],hash('sha256',$a['review_capability'])))$this->deny('review_capability_required');
        if($r['instance_id']!==null&&$r['instance_id']!==$a['instance_id'])$this->deny('review_instance_conflict');
        if($r['instance_id']===null)$this->execute('UPDATE hotel_collection_reviews SET instance_id=? WHERE queue_id=? AND intake_stage=?',[$a['instance_id'],$a['queue_id'],$a['stage_id']]);
        return[$q,$h,$r];
    }
    private function cycleCriteria(array $q,mixed $c):void
    {
        if((int)$q['phase_counter']===0){if($c!==null)$this->deny('criteria_not_due');return;}
        if(!is_array($c))$this->deny('criteria_due');self::keys($c,['readback_sha256','case_count','done_count','partial_count']);self::sha($c['readback_sha256']);
        foreach(['case_count','done_count','partial_count']as$k)if(!is_int($c[$k])||$c[$k]<0||$c[$k]>20)$this->deny('criteria_counts');
        if($c['case_count']<1||$c['done_count']+$c['partial_count']!==$c['case_count'])$this->deny('criteria_counts');
    }
    private function cycleReceipt(array $q,string $stage,string $run,string $packet,mixed $criteria):void
    {
        $body=['run_id'=>$run,'outcome'=>'accepted_readback','packet_sha256'=>$packet,'criteria'=>$criteria,'provenance'=>'server_candidate_and_reviewer_attestation','native_run_verified'=>false];
        $bytes=self::json($body);$this->execute('INSERT INTO hotel_work_receipts(queue_id,stage_index,stage_id,receipt_sha256,receipt_json,created_at) VALUES(?,?,?,?,?,?)',[$q['queue_id'],$q['stage_index'],$stage,hash('sha256',$bytes),$bytes,gmdate('Y-m-d H:i:s')]);
    }
    private function cycleAdvance(array $q,bool $intake):array
    {
        $index=(int)$q['stage_index'];if(($index%2===1)!==$intake||$index>=1000000)$this->deny('cycle_stage_invalid');
        $accepted=(int)$q['accepted_batches']+($intake?1:0);$counter=$intake?1-(int)$q['phase_counter']:(int)$q['phase_counter'];
        $state=$q['mode']==='finite'&&$accepted>=(int)$q['batch_limit']?'complete':'ready';
        $this->execute('UPDATE hotel_work_queues SET stage_index=?,state=?,phase_counter=?,accepted_batches=?,claim_sha256=NULL,current_run_id=NULL,event_ack=NULL,delivered_at=NULL,claim_deadline=NULL,attention_at=NULL,attention_ack=NULL,watchdog_due_at=NULL,watchdog_epoch=0,last_watch_ack=NULL,updated_at=? WHERE queue_id=?',[$index+1,$state,$counter,$accepted,gmdate('Y-m-d H:i:s'),$q['queue_id']]);
        if($state==='complete')$this->execute('UPDATE hotel_work_lane SET queue_id=NULL WHERE lane_key=? AND queue_id=?',['HOTEL',$q['queue_id']]);
        $next=$this->one('SELECT * FROM hotel_work_queues WHERE queue_id=?',[$q['queue_id']]);
        $event=hash('sha256',self::json([$q['queue_id'],$this->stage($next),'protected_pending']));
        $this->execute('INSERT INTO hotel_collection_outbox(event_id,queue_id,stage_id,state) VALUES(?,?,?,?)',[$event,$q['queue_id'],$this->stage($next),$state==='ready'?'pending':'closed']);
        return$next;
    }

    /** CONTROL ONLY. Generic protected inventory, monotonic; no TTL/release. */
    public function refreshReserves(string $queue, string $stage, int $generation, string $control, int $expectedRevision, array $inventory, string $attestation): array
    {
        return $this->tx('refresh_reserves', function () use ($queue,$stage,$generation,$control,$expectedRevision,$inventory,$attestation): array {
            [, $h] = $this->locked($queue,$stage,true); $this->control($h,$control,$generation);
            $old = $this->policy($queue); $next = self::inventory($inventory); self::attestation($attestation);
            if ($old['revision'] !== $expectedRevision) $this->deny('reserve_revision_conflict');
            $byId = []; foreach ($next['entries'] as $entry) $byId[$entry['reserve_id']] = $entry;
            foreach ($old['inventory']['entries'] as $entry) {
                $replacement = $byId[$entry['reserve_id']] ?? null;
                if ($replacement === null || $replacement['country'] !== $entry['country'] || array_diff($entry['hotel_keys'],$replacement['hotel_keys'])) $this->deny('protected_reserve_removal_forbidden');
            }
            if (self::json($next) !== self::json($old['inventory']) || $attestation !== $old['attestation']) $this->writePolicy($queue,$expectedRevision+1,$next,$attestation);
            $policy = $this->policy($queue);
            return ['reserve_revision'=>$policy['revision'],'reserve_sha256'=>$policy['policy_sha256'],'provenance'=>'control_attested_inventory','business_accepted'=>false,'native_run_verified'=>false];
        });
    }

    /** CONTROL ONLY. Authority and scope are persisted before worker exposure. */
    public function prepare(string $queue, string $stage, int $generation, string $control, string $operation, string $scope, string $sourcePacket, array $hotelKeys, int $reserveRevision): array
    {
        return $this->tx('prepare', function () use ($queue,$stage,$generation,$control,$operation,$scope,$sourcePacket,$hotelKeys,$reserveRevision): array {
            [$q,$h] = $this->locked($queue,$stage,true); $this->control($h,$control,$generation);
            $op = $this->operation($q,$h,$operation,['prepared','native_unknown','admitted','candidate_recorded']);
            self::id($scope); self::id($sourcePacket); $keys = self::hotelKeys($hotelKeys,32); $country = $this->country($q);
            $policy = $this->policy($queue);
            if ($policy['revision'] !== $reserveRevision) $this->deny('reserve_revision_conflict');
            $this->scopeFree($keys,$country,$policy['inventory']);$this->workingScopeFree($queue,$keys);
            $document = ['queue_id'=>$queue,'operation_id'=>$operation,'stage_id'=>$stage,'country'=>$country,'scope_id'=>$scope,
                'source_packet_id'=>$sourcePacket,'hotel_keys'=>$keys,'base_revision'=>0,'baseline_sha256'=>$q['baseline_sha256']];
            $bytes = self::json($document);
            $old = $this->one('SELECT * FROM hotel_collection_authorizations WHERE queue_id=? AND operation_id=?',[$queue,$operation]);
            if ($old === null) {
                if ($op['state'] !== 'prepared') $this->deny('prepare_before_dispatch_required');
                $this->execute('INSERT INTO hotel_collection_authorizations(queue_id,operation_id,stage_id,generation,owner_admin_id,owner_connection_id,policy_sha256,context_sha256,context_bytes) VALUES(?,?,?,?,?,?,?,?,?)',
                    [$queue,$operation,$stage,$generation,$this->admin,$this->connection,$policy['policy_sha256'],hash('sha256',$bytes),$bytes]);
            } elseif ($old['context_bytes'] !== $bytes || $old['policy_sha256'] !== $policy['policy_sha256'] || (int)$old['generation'] !== $generation
                || (int)$old['owner_admin_id'] !== $this->admin || $old['owner_connection_id'] !== $this->connection) $this->deny('immutable_scope_conflict');
            return $this->bounded($this->store($q,$h,$op,'control')->prepareAssignment($queue,$operation));
        });
    }

    /** The only public worker read; no caller-provided scope/index/reserves. */
    public function workerRead(array $args): array
    {
        self::keys($args,['queue_id','stage_id','generation','operation_id','worker_capability','instance_id']);
        return $this->workerCall('worker_read',$args,static fn(HotelCollectionCanonicalStore $s,array $a):array=>$s->readAssignment($a['queue_id'],$a['operation_id']));
    }

    /** The only public worker write; stores a candidate, never business acceptance. */
    public function workerSubmit(array $args): array
    {
        self::keys($args,['queue_id','stage_id','generation','operation_id','worker_capability','instance_id','request_id','packet_json']);
        self::id($args['request_id'] ?? null);
        if (!is_string($args['packet_json'] ?? null) || strlen($args['packet_json']) > HotelCollectionCanonicalDocument::MAX_PACKET_BYTES) throw new InvalidArgumentException('canonical_service_packet_too_large');
        return $this->workerCall('worker_submit',$args,static fn(HotelCollectionCanonicalStore $s,array $a):array=>$s->submitCandidate($a['queue_id'],$a['operation_id'],$a['request_id'],$a['packet_json']));
    }

    /** CONTROL ONLY; actual bytes for review, bounded for transport. */
    public function review(string $queue,string $stage,int $generation,string $control,string $operation): array
    {
        return $this->tx('review',function()use($queue,$stage,$generation,$control,$operation):array {
            [$q,$h]=$this->locked($queue,$stage,true);$this->control($h,$control,$generation);
            $op=$this->operation($q,$h,$operation,['admitted','candidate_recorded']);
            // Candidate is already bounded to 256 KiB, not the worker context budget.
            return $this->store($q,$h,$op,'control')->readCandidate($queue,$operation);
        });
    }

    /** CONTROL ONLY. Derived-data commit only; queue/journal stage never advances. */
    public function commit(string $queue,string $stage,int $generation,string $control,string $operation,string $request,string $reviewedHash,int $expectedRevision,string $expectedHeadHash): array
    {
        return $this->tx('commit',function()use($queue,$stage,$generation,$control,$operation,$request,$reviewedHash,$expectedRevision,$expectedHeadHash):array {
            [$q,$h]=$this->locked($queue,$stage,false);$this->control($h,$control,null);
            self::id($operation);self::id($request);self::sha($reviewedHash);self::sha($expectedHeadHash);
            $prior=$this->one('SELECT method,input_sha256 FROM hotel_collection_requests WHERE queue_id=? AND operation_id=? AND request_id=?',[$queue,$operation,$request]);
            if($prior!==null){
                $fingerprint=hash('sha256',self::json(['reviewed_hash'=>$reviewedHash,'expected_revision'=>$expectedRevision,'expected_head_hash'=>$expectedHeadHash]));
                if($prior['method']!=='commit'||!hash_equals($prior['input_sha256'],$fingerprint))$this->deny('request_conflict');
                return $this->store($q,$h,null,'control')->readCommitReceipt($queue,$operation,$request);
            }
            $this->current($q,$stage);$this->control($h,$control,$generation);
            $op=$this->operation($q,$h,$operation,['admitted','candidate_recorded']);
            return $this->store($q,$h,$op,'control')->commit($queue,$operation,$request,$reviewedHash,$expectedRevision,$expectedHeadHash);
        });
    }

    /** CONTROL read after HOLD/rotation; no renewed operation authority. */
    public function receipt(string $queue,string $stage,string $control,string $operation,string $request): array
    {
        return $this->tx('receipt',function()use($queue,$stage,$control,$operation,$request):array {
            [$q,$h]=$this->locked($queue,$stage,false);$this->control($h,$control,null);
            return $this->store($q,$h,null,'control')->readCommitReceipt($queue,$operation,$request);
        });
    }

    /** Current authenticated owner can read bounded metadata, never capabilities. */
    public function status(string $queue): array
    {
        return $this->tx('status',function()use($queue):array {
            [$q,$h]=$this->locked($queue,null,false);
            $view=$this->store($q,$h,null,'reader')->readCanonical($queue);
            $counts=[];foreach(['facts','sources','read_updates','questions','ledger']as$kind)$counts[$kind]=count($view['overlay'][$kind]);
            return ['queue_id'=>$queue,'revision'=>$view['revision'],'snapshot_sha256'=>$view['snapshot_sha256'],'baseline_sha256'=>$view['baseline_sha256'],
                'counts'=>$counts,'projection_contract'=>$view['projection_contract'],'baseline_mapping'=>$view['baseline_mapping'],
                'complete_legacy_reader'=>false,'business_accepted'=>false,'native_run_verified'=>false];
        });
    }

    private function workerCall(string $method,array $args,callable $call): array
    {
        foreach(['queue_id','stage_id','operation_id','instance_id']as$key)self::id($args[$key]??null);
        self::sha($args['worker_capability']??null);
        if(!is_int($args['generation']??null)||$args['generation']<1)throw new InvalidArgumentException('canonical_service_generation_invalid');
        return $this->tx($method,function()use($args,$call):array {
            [$q,$h]=$this->locked($args['queue_id'],$args['stage_id'],true);
            if((int)$h['generation']!==$args['generation'])$this->deny('stale_generation');
            $op=$this->operation($q,$h,$args['operation_id'],['admitted','candidate_recorded']);
            if(!hash_equals($op['capability_sha256'],hash('sha256',$args['worker_capability']))||$op['instance_id']!==$args['instance_id'])$this->deny('worker_capability_or_instance_required');
            return $this->bounded($call($this->store($q,$h,$op,'worker'),$args));
        });
    }

    /** Lock in journal order, then reauthenticate current SQL grant/admin. */
    private function locked(string $queue,?string $stage,bool $current): array
    {
        self::id($queue);if($stage!==null)self::sha($stage);
        if(ProjectConfig::projectId()!=='anytour')$this->deny('access_denied');
        // SQLite obtains its write lock before the first read, matching the
        // serialization of SELECT FOR UPDATE on the existing MySQL lane.
        $lane=$this->one('SELECT * FROM hotel_work_lane WHERE lane_key=?'.$this->lock(),['HOTEL']);
        if($lane===null)$this->deny('lane_unavailable');
        $q=$this->one('SELECT * FROM hotel_work_queues WHERE queue_id=?'.$this->lock(),[$queue]);
        if($q===null||$q['project_key']!=='anytour'||(int)$q['admin_id']!==$this->admin||!hash_equals($q['oauth_connection_id'],$this->connection))$this->deny('access_denied');
        if(($q['write_protocol']??null)!=='journal_v1')$this->deny('protected_protocol_required');
        if(($q['data_snapshot_sha256']??null)!==null){
            $import=$this->one('SELECT data_sha256 FROM hotel_working_imports WHERE queue_id=?',[$queue]);
            if(!$import||$import['data_sha256']!==$q['data_snapshot_sha256'])$this->deny('working_snapshot_unavailable');
        }
        $stage??=$this->stage($q);
        $h=$this->one('SELECT * FROM hotel_journal_heads WHERE queue_id=? AND stage_id=?'.$this->lock(),[$queue,$stage]);
        if($h===null||$h['baseline_sha256']!==$q['baseline_sha256'])$this->deny('journal_head_unavailable');
        // Lock the grant only after the shared lane/queue/journal order. No
        // earlier nonlocking SELECT establishes a stale MySQL RR auth snapshot.
        $grant=$this->one('SELECT resource FROM manager_preparation_oauth_grants WHERE grant_id=? AND admin_id=?'.$this->lock(),[$this->connection,$this->admin]);
        $profile=match($grant['resource']??null){
            ManagerPreparationOAuth::RESOURCE=>ManagerPreparationOAuth::PROFILE_LEGACY,
            ManagerPreparationOAuth::ORIGIN.'/manager/hotels-mcp.php'=>ManagerPreparationOAuth::PROFILE_HOTELS,
            default=>null,
        };
        if($profile===null||!(new ManagerPreparationOAuth($this->pdo,$this->clock,$profile))->connectionAuthorized($this->connection,$this->admin,[ManagerPreparationOAuth::HOTEL_SCOPE]))$this->deny('access_denied');
        $q['_lane_queue_id']=$lane['queue_id'];
        if($current)$this->current($q,$stage);
        return [$q,$h];
    }

    private function current(array $q,string $stage): void
    {
        if($q['_lane_queue_id']!==$q['queue_id']||$this->stage($q)!==$stage||!in_array($q['state'],['ready','claimed'],true))$this->deny('current_stage_required');
    }

    private function control(array $head,string $key,?int $generation): void
    {
        self::sha($key);
        if(!hash_equals($head['control_sha256'],hash('sha256',$key)))$this->deny('control_capability_required');
        if($generation!==null&&($generation<1||(int)$head['generation']!==$generation))$this->deny('stale_generation');
    }

    private function operation(array $queue,array $head,string $operation,array $states): array
    {
        self::sha($operation);
        $op=$this->one('SELECT * FROM hotel_journal_operations WHERE queue_id=? AND stage_id=? AND operation_id=?'.$this->lock(),[$queue['queue_id'],$head['stage_id'],$operation]);
        if($op===null||(int)$op['generation']!==(int)$head['generation']||$head['active_operation_id']!==$operation||!in_array($op['state'],$states,true))$this->deny('current_operation_required');
        return $op;
    }

    /** Private bridge: no caller-provided provider or scope context is exposed. */
    private function store(array $queue,array $head,?array $operation,string $role,array $bootstrap=[]): HotelCollectionCanonicalStore
    {
        $provider=function(string $action,string $queueId,?string $opId,PDO $pdo)use($queue,$head,$operation,$role,$bootstrap):array {
            if($pdo!==$this->pdo||!$pdo->inTransaction()||$queueId!==$queue['queue_id'])$this->deny('internal_context_mismatch');
            $context=['authority'=>$role,'queue_id'=>$queueId,'owner_admin_id'=>$this->admin,'owner_connection_id'=>$this->connection]+$bootstrap;
            if($opId===null||$action==='read_commit_receipt')return $context;
            if($operation===null||$operation['operation_id']!==$opId)$this->deny('internal_operation_mismatch');
            $authorization=$this->one('SELECT * FROM hotel_collection_authorizations WHERE queue_id=? AND operation_id=?',[$queueId,$opId]);
            if($authorization===null||!hash_equals($authorization['context_sha256'],hash('sha256',$authorization['context_bytes']))
                ||(int)$authorization['generation']!==(int)$head['generation']||$authorization['stage_id']!==$head['stage_id']
                ||(int)$authorization['owner_admin_id']!==$this->admin||$authorization['owner_connection_id']!==$this->connection)$this->deny('server_scope_unavailable');
            $policy=$this->policy($queueId);
            if($authorization['policy_sha256']!==$policy['policy_sha256'])$this->deny('reservation_snapshot_changed');
            $document=self::decode($authorization['context_bytes']);
            if($document['country']!==$this->country($queue))$this->deny('country_binding_changed');
            $this->scopeFree($document['hotel_keys'],$document['country'],$policy['inventory']);$this->workingScopeFree($queueId,$document['hotel_keys']);
            return $context+['operation_id'=>$opId,'stage_id'=>$head['stage_id'],'generation'=>(int)$head['generation'],'instance_id'=>$operation['instance_id'],
                'reserve_sha256'=>$policy['policy_sha256'],'document_context'=>$document];
        };
        return new HotelCollectionCanonicalStore($this->pdo,$provider,$this->clock,$this->fault);
    }

    private function policy(string $queue): array
    {
        $row=$this->one('SELECT * FROM hotel_collection_policy WHERE queue_id=?'.$this->lock(),[$queue]);
        if($row===null||!hash_equals($row['policy_sha256'],hash('sha256',$row['policy_bytes'])))$this->deny('reserve_inventory_unavailable');
        $version=$this->one('SELECT policy_sha256,policy_bytes FROM hotel_collection_policy_versions WHERE queue_id=? AND revision=?',[$queue,$row['revision']]);
        if($version===null||$version['policy_sha256']!==$row['policy_sha256']||$version['policy_bytes']!==$row['policy_bytes'])$this->deny('reserve_version_unavailable');
        $body=self::decode($row['policy_bytes']);
        if(($body['queue_id']??null)!==$queue||($body['revision']??null)!==(int)$row['revision'])$this->deny('reserve_inventory_mismatch');
        $body['inventory']=self::inventory($body['inventory']??[]);self::attestation($body['attestation']??null);
        return ['revision'=>(int)$row['revision'],'policy_sha256'=>$row['policy_sha256'],'inventory'=>$body['inventory'],'attestation'=>$body['attestation']];
    }

    private function writePolicy(string $queue,int $revision,array $inventory,string $attestation): void
    {
        $bytes=self::json(['queue_id'=>$queue,'revision'=>$revision,'inventory'=>$inventory,'attestation'=>$attestation,'provenance'=>'control_attested_protected_inventory']);$sha=hash('sha256',$bytes);
        $this->execute('INSERT INTO hotel_collection_policy_versions(queue_id,revision,policy_sha256,policy_bytes) VALUES(?,?,?,?)',[$queue,$revision,$sha,$bytes]);
        if($revision===0)$this->execute('INSERT INTO hotel_collection_policy(queue_id,revision,policy_sha256,policy_bytes) VALUES(?,?,?,?)',[$queue,$revision,$sha,$bytes]);
        else $this->execute('UPDATE hotel_collection_policy SET revision=?,policy_sha256=?,policy_bytes=? WHERE queue_id=?',[$revision,$sha,$bytes,$queue]);
    }

    private function workingScopeFree(string $queue,array $keys):void
    {
        try{$row=$this->one('SELECT body_bytes,data_sha256 FROM hotel_working_imports WHERE queue_id=?',[$queue]);}catch(PDOException $missing){
            // Old, non-imported prototype schema retains its pre-existing contract.
            if($this->driver==='mysql'&&($missing->errorInfo[1]??null)!==1146)throw $missing;
            if($this->driver==='sqlite'&&!str_contains($missing->getMessage(),'no such table'))throw $missing;
            return;
        }
        if(!$row)return;
        if(hash('sha256',$row['body_bytes'])!==$row['data_sha256'])$this->deny('working_snapshot_unavailable');
        $doc=HotelCollectionCanonicalDocument::decodeObject($row['body_bytes']);
        $visit=function(mixed $v)use(&$visit,$keys):void{
            if(is_string($v)&&in_array($v,$keys,true))$this->deny('imported_reserve_overlap');
            if(is_array($v))foreach($v as$item)$visit($item);
        };
        foreach(['active_claims','legacy_reserved_scopes']as$group)$visit($doc['coordination'][$group]??[]);
    }
    private function scopeFree(array $keys,string $country,array $inventory): void
    {
        foreach($inventory['entries']as$reserve)if($reserve['country']===$country&&array_intersect($keys,$reserve['hotel_keys']))$this->deny('protected_reserve_overlap');
    }

    private static function inventory(array $value): array
    {
        self::keys($value,['schema_version','entries']);
        if(($value['schema_version']??null)!==1||!is_array($value['entries']??null)||!array_is_list($value['entries'])||count($value['entries'])>256)throw new InvalidArgumentException('canonical_service_reserve_inventory_invalid');
        $entries=[];$count=0;
        foreach($value['entries']as$row){
            if(!is_array($row))throw new InvalidArgumentException('canonical_service_reserve_inventory_invalid');
            self::keys($row,['reserve_id','country','hotel_keys','protected']);self::id($row['reserve_id']??null);
            if(($row['protected']??null)!==true||!in_array($row['country']??null,['TH','VN','TR','EG'],true)||isset($entries[$row['reserve_id']]))throw new InvalidArgumentException('canonical_service_reserve_inventory_invalid');
            $row['hotel_keys']=self::hotelKeys($row['hotel_keys']??[],256);$count+=count($row['hotel_keys']);
            if($count>4096)throw new InvalidArgumentException('canonical_service_reserve_inventory_too_large');$entries[$row['reserve_id']]=$row;
        }
        ksort($entries,SORT_STRING);return ['schema_version'=>1,'entries'=>array_values($entries)];
    }

    private function country(array $queue): string
    {
        $countries=['EG','TH','VN','TR'];$start=array_search($queue['start_country'],$countries,true);$index=(int)$queue['stage_index'];
        if($start===false||$index<0)$this->deny('queue_country_unavailable');return $countries[($start+intdiv($index,2))%4];
    }
    private function stage(array $queue): string { return hash('sha256',self::json([$queue['queue_id'],$queue['plan_sha256'],(int)$queue['stage_index']])); }
    private function bounded(array $value): array
    {
        $value+=['complete_legacy_reader'=>false,'evidence_provenance'=>'digest_references_and_control_attestation_not_independent_source_bytes_proof'];
        if(strlen(self::json($value))>self::MAX_READ_BYTES)$this->deny('projection_too_large');return $value;
    }
    private function tx(string $name,callable $work): array
    {
        if($this->pdo->inTransaction())throw new LogicException('canonical_service_foreign_transaction');
        $this->pdo->beginTransaction();
        try{if($this->driver==='sqlite')$this->execute('UPDATE hotel_work_lane SET queue_id=queue_id WHERE lane_key=?',['HOTEL']);$result=$work();$this->pdo->commit();}
        catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if($e instanceof PDOException)throw new RuntimeException('canonical_service_storage_unavailable',0,$e);throw $e;}
        if($this->fault!==null)($this->fault)('after_commit:'.$name);return $result;
    }
    private function lock(): string { return $this->driver==='mysql'?' FOR UPDATE':''; }
    private function one(string $sql,array $args): ?array { $q=$this->pdo->prepare($sql);$q->execute($args);return $q->fetch(PDO::FETCH_ASSOC)?:null; }
    private function execute(string $sql,array $args): void { $q=$this->pdo->prepare($sql);$q->execute($args); }
    private function deny(string $reason): never { throw new DomainException('canonical_service_'.$reason); }
    private static function keys(array $value,array $allowed): void { if(array_diff(array_keys($value),$allowed)||array_diff($allowed,array_keys($value)))throw new InvalidArgumentException('canonical_service_argument_keys'); }
    private static function id(mixed $value): void { if(!is_string($value)||preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/D',$value)!==1)throw new InvalidArgumentException('canonical_service_id_invalid'); }
    private static function sha(mixed $value): void { if(!is_string($value)||preg_match('/\A[a-f0-9]{64}\z/D',$value)!==1)throw new InvalidArgumentException('canonical_service_sha_invalid'); }
    private static function attestation(mixed $value): void { if(!is_string($value)||trim($value)===''||strlen($value)>2048)throw new InvalidArgumentException('canonical_service_attestation_required'); }
    private static function hotelKeys(array $keys,int $max): array { if(!array_is_list($keys)||count($keys)<1||count($keys)>$max)throw new InvalidArgumentException('canonical_service_hotel_keys_invalid');foreach($keys as$key)self::id($key);sort($keys,SORT_STRING);if(count(array_unique($keys))!==count($keys))throw new InvalidArgumentException('canonical_service_hotel_keys_invalid');return $keys; }
    private static function decode(string $bytes): array { try{$v=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new DomainException('canonical_service_stored_bytes_invalid',0,$e);}if(!is_array($v)||array_is_list($v))throw new DomainException('canonical_service_stored_bytes_invalid');return $v; }
    private static function json(mixed $value): string { $sort=static function(mixed $v)use(&$sort):mixed{if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as$key=>$item)$v[$key]=$sort($item);return $v;};return json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
}
