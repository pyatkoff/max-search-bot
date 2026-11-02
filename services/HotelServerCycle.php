<?php
declare(strict_types=1);
require_once __DIR__.'/HotelServerInputs.php';
require_once __DIR__.'/HotelServerLane.php';
require_once __DIR__.'/HotelServerProvider.php';

/** Dormant library. No endpoint, cron registration, config mutation or native task binding.
 * All canonical data/receipt/baseline/credit/outbox writes share the application PDO's
 * HOTEL lock and transaction. HotelDatabase is an asynchronous idempotent projection.
 */
final class HotelServerCycle
{
    private string $driver;
    public function __construct(private PDO $pdo, private HotelServerLane $lane, private ?HotelServerProvider $provider = null,
        private $clock = null, private $fault = null)
    {
        if ($pdo !== $lane->connection()) throw new InvalidArgumentException('single_operational_PDO_required');
        $this->driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver,['mysql','sqlite'],true)) throw new InvalidArgumentException('catalog_driver');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** Separate operator-authorized bootstrap. Import has zero new acceptance credit. */
    public function bootstrap(string $id, HotelServerInputs $inputs, array $plan, callable $authorized): array
    {
        self::id($id); $this->validatePlan($plan); $p = $inputs->package();
        $bytes = HotelServerInputs::json($p); $planBytes = HotelServerInputs::json($plan);
        $bootstrap = hash('sha256', HotelServerInputs::json([$p['inputs_sha256'],$plan]));
        $this->lane->reserve($id,$authorized); // durable lane first; never silently release on a catalog error
        return $this->tx($id,function(?array $c) use($id,$p,$bytes,$planBytes,$bootstrap,$plan): array {
            if ($c) {
                if ($c['bootstrap_sha256'] !== $bootstrap) throw new DomainException('campaign_bootstrap_conflict');
                return $this->projection($c);
            }
            $baseline = $p['pins']['baseline'];
            $this->exec('INSERT INTO hotel_server_campaigns(campaign_id,state,enabled,stage_cursor,new_accepted,historical_accepted,baseline_sha256,inputs_sha256,bootstrap_sha256,plan_bytes,reserved_calls,reserved_tokens,reserved_micros) VALUES(?,?,0,0,0,19,?,?,?,?,0,0,0)',
                [$id,'disabled',$baseline,$p['inputs_sha256'],$bootstrap,$planBytes]);
            $this->exec('INSERT INTO hotel_server_inputs(campaign_id,inputs_sha256,package_bytes) VALUES(?,?,?)',[$id,$p['inputs_sha256'],$bytes]);
            $this->exec('INSERT INTO hotel_server_baselines(campaign_id,baseline_sha256,parent_sha256,document_bytes) VALUES(?,?,NULL,?)',[$id,$baseline,$p['bytes']['baseline']]);
            foreach ($p['exclusions'] as $key=>$kind) $this->exec('INSERT INTO hotel_server_exclusions(campaign_id,semantic_key,kind) VALUES(?,?,?)',[$id,$key,$kind]);
            foreach($plan['stages'] as $scopes)$this->safeScopes($id,$scopes);
            $this->enqueue($id,0,'producer'); $this->fault('bootstrap_before_commit');
            return $this->projection($this->campaign($id));
        });
    }

    /** Merely calling tick on a disabled campaign does not activate it. */
    public function enable(string $id, string $bootstrapPin, callable $authorized): array
    {
        if ($authorized() !== true) throw new DomainException('campaign_enable_not_authorized');
        return $this->tx($id,function(?array $c) use($id,$bootstrapPin): array {
            $c = $this->required($c); $plan = $this->plan($c);
            if ($c['bootstrap_sha256'] !== $bootstrapPin || $c['state'] !== 'disabled') throw new DomainException('campaign_enable_CAS');
            $this->verifyInputs($c); $this->readyProvider($plan); $this->safeScopes($id,$plan['stages'][0]);
            $this->exec("UPDATE hotel_server_campaigns SET enabled=1,state='ready' WHERE campaign_id=?",[$id]);
            return $this->projection($this->campaign($id));
        });
    }

    /** One existing-server-cron call performs at most one send OR one atomic accept. */
    public function tick(string $id): array
    {
        $request = $this->tx($id,function(?array $c) use($id): array {
            $c = $this->required($c);
            if ((int)$c['enabled'] !== 1 || in_array($c['state'],['disabled','hold','complete'],true)) return $this->projection($c);
            if ($c['state'] === 'unknown') return $this->projection($c); // even after deadline/lease expiry
            if ($c['state'] === 'reviewed') return $this->acceptLocked($c);
            $plan = $this->plan($c); $this->verifyInputs($c); $this->readyProvider($plan);
            $stage = (int)$c['stage_cursor']; $scopes = $plan['stages'][$stage]; $this->safeScopes($id,$scopes);
            $out = $this->one("SELECT * FROM hotel_server_outbox WHERE campaign_id=? AND stage_index=? AND state='pending'".$this->lock(),[$id,$stage]);
            if (!$out) throw new DomainException('pending_outbox_required');
            $role = $out['role']; $limits = $plan['limits'];
            if ($this->now() >= $limits['deadline'] || (int)$c['reserved_calls'] + 1 > $limits['max_calls']
                || (int)$c['reserved_tokens'] + $limits['tokens_per_call'] > $limits['max_tokens']
                || (int)$c['reserved_micros'] + $limits['micros_per_call'] > $limits['max_micros']) {
                $this->exec("UPDATE hotel_server_campaigns SET state='hold' WHERE campaign_id=?",[$id]);
                return ['state'=>'hold','reason'=>'budget_or_deadline','new_accepted'=>(int)$c['new_accepted']];
            }
            $attempt = bin2hex(random_bytes(32)); $principal = $this->provider->principal($role);
            if ($principal === '' || strlen($principal)>256) throw new DomainException('provider_principal_required');
            $candidate = null;
            if ($role === 'reviewer') {
                $candidate = $this->candidate($id,$stage); $producer = $this->attempt($candidate['producer_attempt']);
                if ($principal === $producer['principal']) throw new DomainException('independent_reviewer_required');
            }
            $package = $this->package($id);
            $r = ['schema'=>'hotel-server-attempt-v1','campaign_id'=>$id,'stage_index'=>$stage,'attempt_id'=>$attempt,'role'=>$role,
                'principal'=>$principal,'model'=>$plan['models'][$role],'baseline_sha256'=>$c['baseline_sha256'],'inputs_sha256'=>$c['inputs_sha256'],
                'scopes'=>$scopes,'accepted_context'=>$this->acceptedView($c,$scopes),'dependencies'=>array_map(static fn(array $s):array=>$package['stage_contexts'][HotelServerInputs::key($s)],$scopes),'criteria_sha256'=>$package['pins']['criteria'],'candidate'=>$candidate ? json_decode($candidate['candidate_bytes'],true,64,JSON_THROW_ON_ERROR) : null,
                'candidate_sha256'=>$candidate['candidate_sha256'] ?? null, 'limits'=>$limits];
            $requestBytes = HotelServerInputs::json($r);
            if(strlen($requestBytes)>262144)throw new DomainException('bounded_assignment_projection_required');
            // Commit UNKNOWN and the full reservation BEFORE the caller can send anything.
            $this->exec('INSERT INTO hotel_server_attempts(attempt_id,campaign_id,stage_index,role,principal,state,baseline_sha256,request_sha256,request_bytes,result_sha256,result_bytes) VALUES(?,?,?,?,?,?,?,?,?,NULL,NULL)',
                [$attempt,$id,$stage,$role,$principal,'unknown',$c['baseline_sha256'],hash('sha256',$requestBytes),$requestBytes]);
            $this->exec("UPDATE hotel_server_outbox SET state='unknown',attempt_id=? WHERE campaign_id=? AND stage_index=? AND role=?",[$attempt,$id,$stage,$role]);
            $this->exec("UPDATE hotel_server_campaigns SET state='unknown',reserved_calls=reserved_calls+1,reserved_tokens=reserved_tokens+?,reserved_micros=reserved_micros+? WHERE campaign_id=?",[$limits['tokens_per_call'],$limits['micros_per_call'],$id]);
            $this->fault('dispatch_before_commit'); return ['send'=>$r];
        });
        if (!isset($request['send'])) return $request;
        $r = $request['send'];
        $this->fault('dispatch_after_commit'); // crash/lost ACK here must never redispatch
        try { $result = $this->provider->send($r); }
        catch (Throwable $e) { return ['state'=>'unknown','attempt_id'=>$r['attempt_id'],'reason'=>'provider_outcome_unknown']; }
        return $this->recordResult($id,$r['attempt_id'],$result);
    }

    /** Reconcile the SAME invocation only: never dispatch, refund or replace UNKNOWN. */
    public function recordResult(string $id, string $attemptId, array $result): array
    {
        return $this->tx($id,function(?array $c) use($id,$attemptId,$result): array {
            $c=$this->required($c); $a=$this->attempt($attemptId);
            if ($a['campaign_id']!==$id) throw new DomainException('foreign_attempt');
            $request=json_decode($a['request_bytes'],true,64,JSON_THROW_ON_ERROR);
            if (hash('sha256',$a['request_bytes'])!==$a['request_sha256']) throw new DomainException('request_hash_mismatch');
            $resultBytes=HotelServerInputs::json($result); $resultSha=hash('sha256',$resultBytes);
            if ($a['state']==='succeeded') {
                if ($a['result_sha256']!==$resultSha) throw new DomainException('immutable_result_conflict');
                return $this->projection($c);
            }
            if ($a['state']!=='unknown' || $c['state']!=='unknown' || (int)$c['stage_cursor']!==(int)$a['stage_index']
                || $c['baseline_sha256']!==$a['baseline_sha256']) throw new DomainException('attempt_CAS');
            if (($result['attempt_id']??null)!==$attemptId || ($result['principal']??null)!==$a['principal']
                || ($result['baseline_sha256']??null)!==$a['baseline_sha256'] || ($result['inputs_sha256']??null)!==$c['inputs_sha256']) throw new DomainException('result_lineage_mismatch');
            $usage=$result['usage']??null;
            foreach (['tokens'=>'tokens_per_call','micros'=>'micros_per_call'] as $field=>$limit) {
                if (!is_int($usage[$field]??null) || $usage[$field]<0 || $usage[$field]>$request['limits'][$limit]) throw new DomainException('provider_usage_invalid');
            }
            if ($a['role']==='producer') {
                $candidate=$result['candidate']??null; $this->validateCandidate($candidate,$request);
                $candidateBytes=HotelServerInputs::json($candidate); $hash=hash('sha256',$candidateBytes);
                $this->exec('INSERT INTO hotel_server_candidates(campaign_id,stage_index,candidate_sha256,producer_attempt,candidate_bytes) VALUES(?,?,?,?,?)',[$id,$a['stage_index'],$hash,$attemptId,$candidateBytes]);
                $next='candidate'; $this->enqueue($id,(int)$a['stage_index'],'reviewer');
            } else {
                $candidate=$this->candidate($id,(int)$a['stage_index']); $producer=$this->attempt($candidate['producer_attempt']);
                if ($a['principal']===$producer['principal'] || $attemptId===$producer['attempt_id']
                    || ($result['candidate_sha256']??null)!==$candidate['candidate_sha256']
                    || ($result['criteria_sha256']??null)!==$this->package($id)['pins']['criteria']
                    || !is_bool($result['approved']??null) || !is_string($result['evidence']??null) || trim($result['evidence'])==='') throw new DomainException('independent_review_lineage_required');
                $this->exec('INSERT INTO hotel_server_reviews(campaign_id,stage_index,review_attempt,candidate_sha256,approved,review_bytes) VALUES(?,?,?,?,?,?)',[$id,$a['stage_index'],$attemptId,$candidate['candidate_sha256'],$result['approved']?1:0,$resultBytes]);
                $next=$result['approved']?'reviewed':'hold';
            }
            $this->exec("UPDATE hotel_server_attempts SET state='succeeded',result_sha256=?,result_bytes=? WHERE attempt_id=?",[$resultSha,$resultBytes,$attemptId]);
            $this->exec("UPDATE hotel_server_outbox SET state='done' WHERE campaign_id=? AND stage_index=? AND role=?",[$id,$a['stage_index'],$a['role']]);
            $this->exec('UPDATE hotel_server_campaigns SET state=? WHERE campaign_id=?',[$next,$id]);
            $this->fault('result_before_commit'); return $this->projection($this->campaign($id));
        });
    }

    /** Reserve updates serialize against assignment AND acceptance on the same campaign lock. */
    public function protect(string $id, array $scope): void
    {
        $key=HotelServerInputs::key($scope);
        $this->tx($id,function(?array $c) use($id,$key): array {
            $this->required($c);
            if (!$this->one('SELECT * FROM hotel_server_exclusions WHERE campaign_id=? AND semantic_key=?',[$id,$key])) {
                $this->exec('INSERT INTO hotel_server_exclusions(campaign_id,semantic_key,kind) VALUES(?,?,?)',[$id,$key,'protected_reserve']);
            }
            return [];
        });
    }

    public function status(string $id): array
    { return $this->projection($this->required($this->one('SELECT * FROM hotel_server_campaigns WHERE campaign_id=?',[$id]))); }

    private function acceptLocked(array $c): array
    {
        $id=$c['campaign_id']; $stage=(int)$c['stage_cursor']; $plan=$this->plan($c); $this->verifyInputs($c);
        $candidate=$this->candidate($id,$stage); $producer=$this->attempt($candidate['producer_attempt']);
        $review=$this->one('SELECT * FROM hotel_server_reviews WHERE campaign_id=? AND stage_index=?'.$this->lock(),[$id,$stage]);
        if (!$review || (int)$review['approved']!==1 || $review['candidate_sha256']!==$candidate['candidate_sha256']) throw new DomainException('accepted_independent_review_required');
        $reviewer=$this->attempt($review['review_attempt']);
        if ($producer['state']!=='succeeded' || $reviewer['state']!=='succeeded' || $producer['principal']===$reviewer['principal']
            || $producer['attempt_id']===$reviewer['attempt_id'] || $producer['baseline_sha256']!==$c['baseline_sha256']
            || $reviewer['baseline_sha256']!==$c['baseline_sha256'] || hash('sha256',$review['review_bytes'])!==$reviewer['result_sha256']) throw new DomainException('accept_lineage_CAS');
        $document=json_decode($candidate['candidate_bytes'],true,64,JSON_THROW_ON_ERROR);
        $this->safeScopes($id,$plan['stages'][$stage]);
        foreach ($document['records'] as $record) {
            $key=HotelServerInputs::key($record['scope']);
            $this->exec('INSERT INTO hotel_server_records(semantic_key,campaign_id,candidate_sha256,record_bytes) VALUES(?,?,?,?)',[$key,$id,$candidate['candidate_sha256'],HotelServerInputs::json($record)]);
        }
        $baselineBytes=HotelServerInputs::json(['schema'=>'hotel-server-baseline-v1','parent_sha256'=>$c['baseline_sha256'],'candidate_sha256'=>$candidate['candidate_sha256'],'records'=>$document['records']]);
        $newBaseline=hash('sha256',$baselineBytes);
        $this->exec('INSERT INTO hotel_server_baselines(campaign_id,baseline_sha256,parent_sha256,document_bytes) VALUES(?,?,?,?)',[$id,$newBaseline,$c['baseline_sha256'],$baselineBytes]);
        $receipt=['schema'=>'hotel-server-acceptance-v1','campaign_id'=>$id,'stage_index'=>$stage,'candidate_sha256'=>$candidate['candidate_sha256'],
            'producer_attempt'=>$producer['attempt_id'],'review_attempt'=>$reviewer['attempt_id'],'previous_baseline_sha256'=>$c['baseline_sha256'],
            'baseline_sha256'=>$newBaseline,'inputs_sha256'=>$c['inputs_sha256'],'new_acceptance_credit'=>1,'historical_accepted'=>19];
        $receiptBytes=HotelServerInputs::json($receipt); $receiptSha=hash('sha256',$receiptBytes);
        $this->exec('INSERT INTO hotel_server_receipts(campaign_id,stage_index,receipt_sha256,receipt_bytes) VALUES(?,?,?,?)',[$id,$stage,$receiptSha,$receiptBytes]);
        $next=$stage+1; $state=$next===count($plan['stages'])?'complete':'ready';
        $s=$this->exec('UPDATE hotel_server_campaigns SET baseline_sha256=?,stage_cursor=?,new_accepted=new_accepted+1,state=? WHERE campaign_id=? AND baseline_sha256=? AND stage_cursor=? AND state=?',[$newBaseline,$next,$state,$id,$c['baseline_sha256'],$stage,'reviewed']);
        if ($s!==1) throw new DomainException('baseline_CAS');
        if ($state==='ready') $this->enqueue($id,$next,'producer');
        $this->exec('INSERT INTO hotel_server_catalog_outbox(receipt_sha256,campaign_id,payload_bytes,state) VALUES(?,?,?,?)',[$receiptSha,$id,$baselineBytes,'pending']);
        $this->fault('accept_before_commit');
        return $this->projection($this->campaign($id))+['receipt_sha256'=>$receiptSha];
    }


    /** Complete verified accepted view. Raw retained bytes are never model input. */
    public function readAccepted(string $id): array
    {
        return $this->tx($id,function(?array $c):array{
            $c=$this->required($c);$this->verifyInputs($c);return $this->acceptedView($c,null);
        });
    }

    private function acceptedView(array $c,?array $scopes): array
    {
        $p=$this->package($c['campaign_id']);$records=[];
        foreach($p['accepted_context'] as $r)$records[HotelServerInputs::key($r['scope'])]=$r;
        $hash=$c['baseline_sha256'];$deltas=[];$seen=[];
        while($hash!==$p['pins']['baseline']){
            if(isset($seen[$hash])||count($seen)>=10)throw new DomainException('baseline_chain_invalid');$seen[$hash]=true;
            $row=$this->required($this->one('SELECT * FROM hotel_server_baselines WHERE campaign_id=? AND baseline_sha256=?',[$c['campaign_id'],$hash]));
            if(!hash_equals($hash,hash('sha256',$row['document_bytes'])))throw new DomainException('baseline_bytes_mismatch');
            $d=json_decode($row['document_bytes'],true,64,JSON_THROW_ON_ERROR);
            if(($d['schema']??null)!=='hotel-server-baseline-v1'||($d['parent_sha256']??null)!==$row['parent_sha256']||!is_array($d['records']??null)||!array_is_list($d['records']))throw new DomainException('baseline_chain_invalid');
            HotelServerInputs::sha($row['parent_sha256']);$deltas[]=$d['records'];$hash=$row['parent_sha256'];
        }
        foreach(array_reverse($deltas) as $delta)foreach($delta as $r){
            $key=HotelServerInputs::key($r['scope']??[]);if(isset($records[$key]))throw new DomainException('duplicate_accepted_baseline_key');$records[$key]=$r;
        }
        ksort($records);
        if($scopes!==null){
            $hotels=[];foreach($scopes as $s)$hotels[$s['country']."\0".$s['hotel']]=true;
            $records=array_filter($records,static fn(array $r):bool=>isset($hotels[$r['scope']['country']."\0".$r['scope']['hotel']]));
        }
        $view=['schema'=>'hotel-server-accepted-view-v1','baseline_sha256'=>$c['baseline_sha256'],'retained_baseline_sha256'=>$p['pins']['baseline'],'records'=>array_values($records)];
        return $view+['view_sha256'=>hash('sha256',HotelServerInputs::json($view))];
    }

    private function validateCandidate(mixed $candidate,array $request): void
    {
        if (!is_array($candidate) || ($candidate['schema']??null)!=='hotel-server-candidate-v1' || !is_array($candidate['records']??null)
            || !array_is_list($candidate['records']) || !$candidate['records'] || strlen(HotelServerInputs::json($candidate))>1048576) throw new DomainException('candidate_document_required');
        $keys=array_keys($candidate);sort($keys);if($keys!==['records','schema'])throw new DomainException('strict_candidate_document_required');
        $expected=array_map([HotelServerInputs::class,'key'],$request['scopes']); $actual=[];
        foreach ($candidate['records'] as $r) {
            if(!is_array($r))throw new DomainException('strict_candidate_record_required');$keys=array_keys($r);sort($keys);if($keys!==['answer','evidence','scope'])throw new DomainException('strict_candidate_record_required');
            $actual[]=HotelServerInputs::key($r['scope']??[]);
            if (!is_string($r['answer']??null) || trim($r['answer'])==='' || strlen($r['answer'])>32768 || !is_array($r['evidence']??null) || !array_is_list($r['evidence']) || !$r['evidence']) throw new DomainException('candidate_answer_evidence_required');
            foreach($r['evidence'] as $e){if(!is_array($e))throw new DomainException('candidate_source_evidence_required');$keys=array_keys($e);sort($keys);if($keys!==['quote','url'])throw new DomainException('strict_candidate_source_required');}
            foreach ($r['evidence'] as $e) if (!is_array($e) || !is_string($e['url']??null) || !filter_var($e['url'],FILTER_VALIDATE_URL) || !str_starts_with($e['url'],'https://') || !is_string($e['quote']??null) || trim($e['quote'])==='') throw new DomainException('candidate_source_evidence_required');
        }
        sort($expected); sort($actual);
        if ($expected!==$actual || count(array_unique($actual))!==count($actual)) throw new DomainException('candidate_exact_scope_required');
    }

    private function verifyInputs(array $c): void
    {
        $p=$this->package($c['campaign_id']); $identity=$p; unset($identity['inputs_sha256'],$identity['bytes']);
        if (hash('sha256',HotelServerInputs::json($identity))!==$c['inputs_sha256'] || $p['inputs_sha256']!==$c['inputs_sha256'] || count($p['registry'])!==19) throw new DomainException('immutable_inputs_mismatch');
        foreach (HotelServerInputs::REQUIRED as $name) if (hash('sha256',$p['bytes'][$name])!==$p['pins'][$name]) throw new DomainException('immutable_input_bytes_mismatch');
        $b=$this->one('SELECT document_bytes FROM hotel_server_baselines WHERE campaign_id=? AND baseline_sha256=?',[$c['campaign_id'],$c['baseline_sha256']]);
        if (!$b || hash('sha256',$b['document_bytes'])!==$c['baseline_sha256']) throw new DomainException('baseline_bytes_mismatch');
    }

    private function safeScopes(string $id,array $scopes): void
    {
        $package=$this->package($id);
        foreach ($scopes as $scope) {
            $key=HotelServerInputs::key($scope);
            if(!isset($package['stage_contexts'][$key]))throw new DomainException('scoped_dependency_projection_required');
            if ($this->one('SELECT semantic_key FROM hotel_server_exclusions WHERE campaign_id=? AND semantic_key=?',[$id,$key])
                || $this->one('SELECT semantic_key FROM hotel_server_records WHERE semantic_key=?',[$key])) throw new DomainException('accepted_or_reserved_scope');
        }
    }

    private function validatePlan(array $p): void
    {
        if (!is_array($p['stages']??null) || !array_is_list($p['stages']) || count($p['stages'])<1 || count($p['stages'])>10) throw new DomainException('bounded_campaign_plan_required');
        $keys=[];
        foreach ($p['stages'] as $scopes) {
            if (!is_array($scopes) || !array_is_list($scopes) || !$scopes || count($scopes)>32) throw new DomainException('bounded_stage_scopes');
            foreach ($scopes as $scope) { $key=HotelServerInputs::key($scope); if (isset($keys[$key])) throw new DomainException('duplicate_plan_scope'); $keys[$key]=true; }
        }
        foreach (['producer','reviewer'] as $role) if (!is_string($p['models'][$role]??null) || !preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D',$p['models'][$role])) throw new DomainException('explicit_model_required');
        foreach (['deadline','max_calls','max_tokens','max_micros','tokens_per_call','micros_per_call'] as $key) if (!is_int($p['limits'][$key]??null) || $p['limits'][$key]<1 || $p['limits'][$key]>2000000000) throw new DomainException('explicit_budget_required');
    }
    private function readyProvider(array $p): void
    {
        if (!$this->provider) throw new DomainException('provider_not_configured_HOLD');
        foreach (['producer','reviewer'] as $role) if (!$this->provider->ready($role,$p['models'][$role])) throw new DomainException('provider_not_ready_HOLD');
        if ($this->provider->principal('producer')===$this->provider->principal('reviewer')) throw new DomainException('independent_reviewer_required');
    }
    private function tx(string $id,callable $f): array
    {
        self::id($id);
        return $this->lane->own($id,function() use($id,$f): array {
            $c=$this->one('SELECT * FROM hotel_server_campaigns WHERE campaign_id=?'.$this->lock(),[$id]);
            return $f($c);
        });
    }
    private function package(string $id): array { return json_decode($this->required($this->one('SELECT package_bytes FROM hotel_server_inputs WHERE campaign_id=?',[$id]))['package_bytes'],true,64,JSON_THROW_ON_ERROR); }
    private function plan(array $c): array { return json_decode($c['plan_bytes'],true,64,JSON_THROW_ON_ERROR); }
    private function candidate(string $id,int $stage): array {
        $c=$this->required($this->one('SELECT * FROM hotel_server_candidates WHERE campaign_id=? AND stage_index=?'.$this->lock(),[$id,$stage]));
        if (hash('sha256',$c['candidate_bytes'])!==$c['candidate_sha256']) throw new DomainException('candidate_bytes_mismatch'); return $c;
    }
    private function attempt(string $id): array {
        HotelServerInputs::sha($id); $a=$this->required($this->one('SELECT * FROM hotel_server_attempts WHERE attempt_id=?'.$this->lock(),[$id]));
        if(hash('sha256',$a['request_bytes'])!==$a['request_sha256'])throw new DomainException('request_hash_mismatch');
        $r=json_decode($a['request_bytes'],true,64,JSON_THROW_ON_ERROR);
        foreach(['attempt_id','campaign_id','role','principal','baseline_sha256'] as $field)if(($r[$field]??null)!==$a[$field])throw new DomainException('immutable_attempt_lineage');
        if(($r['stage_index']??null)!==(int)$a['stage_index'])throw new DomainException('immutable_attempt_stage');
        if($a['state']==='succeeded'&&hash('sha256',$a['result_bytes'])!==$a['result_sha256'])throw new DomainException('immutable_result_hash');
        return $a;
    }
    private function campaign(string $id): array { return $this->required($this->one('SELECT * FROM hotel_server_campaigns WHERE campaign_id=?',[$id])); }
    private function enqueue(string $id,int $stage,string $role): void { $this->exec('INSERT INTO hotel_server_outbox(campaign_id,stage_index,role,state,attempt_id) VALUES(?,?,?,?,NULL)',[$id,$stage,$role,'pending']); }
    private function projection(array $c): array { return ['campaign_id'=>$c['campaign_id'],'state'=>$c['state'],'enabled'=>(int)$c['enabled']===1,'historical_accepted'=>19,'new_accepted'=>(int)$c['new_accepted'],'stage_index'=>(int)$c['stage_cursor'],'baseline_sha256'=>$c['baseline_sha256'],'bootstrap_sha256'=>$c['bootstrap_sha256']]; }
    private function lock(): string { return $this->driver==='mysql'?' FOR UPDATE':''; }
    private function now(): int { return $this->clock===null?time():($this->clock)(); }
    private function fault(string $at): void { if ($this->fault!==null) ($this->fault)($at); }
    private function required(?array $r): array { if (!$r) throw new DomainException('server_row_required'); return $r; }
    private function one(string $sql,array $args): ?array { $s=$this->pdo->prepare($sql); $s->execute($args); return $s->fetch(PDO::FETCH_ASSOC)?:null; }
    private function exec(string $sql,array $args): int { $s=$this->pdo->prepare($sql); $s->execute($args); return $s->rowCount(); }
    private static function id(string $id): void { if ($id===HotelServerInputs::OLD_QUEUE || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D',$id)) throw new DomainException('new_campaign_id_required'); }
}
