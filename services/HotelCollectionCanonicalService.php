<?php
declare(strict_types=1);

require_once __DIR__.'/HotelCollectionCanonicalStore.php';
require_once __DIR__.'/ManagerPreparationOAuth.php';

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
            $this->scopeFree($keys,$country,$policy['inventory']);
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
            $this->scopeFree($document['hotel_keys'],$document['country'],$policy['inventory']);
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
