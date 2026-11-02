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
    private ?array $inputCache = null;
    private ?string $inputCacheCampaign = null;
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

    /** Forward-only handoff. The caller supplies new tasks, never inherited accepted records.
     * No provider or runtime configuration is touched; enable remains a separate action.
     */
    public function bootstrapNext(string $predecessorId, string $expectedBootstrapSha256, string $expectedBaselineSha256,
        string $successorId, HotelServerInputs $inputs, array $plan, callable $authorized): array
    {
        self::id($predecessorId); self::id($successorId); $this->validatePlan($plan);
        HotelServerInputs::sha($expectedBootstrapSha256); HotelServerInputs::sha($expectedBaselineSha256);
        $p=$inputs->package();
        try {
            return $this->lane->handoff($predecessorId,$successorId,$authorized,function() use($predecessorId,$expectedBootstrapSha256,$expectedBaselineSha256,$successorId,$p,$plan): array {
                $previous=$this->required($this->one('SELECT * FROM hotel_server_campaigns WHERE campaign_id=?'.$this->lock(),[$predecessorId]));
                if ($previous['bootstrap_sha256']!==$expectedBootstrapSha256 || $previous['baseline_sha256']!==$expectedBaselineSha256
                    || (int)$previous['enabled']!==1) throw new DomainException('predecessor_CAS');
                if ($this->one('SELECT campaign_id FROM hotel_server_campaigns WHERE campaign_id=?',[$successorId])) throw new DomainException('successor_campaign_exists');
                if ((int)$this->one('SELECT COUNT(*) AS n FROM hotel_server_campaigns WHERE enabled=1 AND campaign_id<>?',[$predecessorId])['n']!==0)
                    throw new DomainException('one_enabled_campaign_required');
                $this->verifyInputs($previous); $old=$this->package($predecessorId);
                $this->sameHistory($old,$p); $evidence=$this->completedEvidence($previous);
                $seed=['schema'=>'hotel-server-continuation-seed-v1','retained_baseline_sha256'=>$p['pins']['baseline'],
                    'predecessor'=>['campaign_id'=>$predecessorId,'bootstrap_sha256'=>$expectedBootstrapSha256,
                        'baseline_sha256'=>$expectedBaselineSha256,'inputs_sha256'=>$previous['inputs_sha256'],'receipts'=>$evidence['receipts']],
                    'records'=>$evidence['records']];
                $seedBytes=HotelServerInputs::json($seed);
                if (strlen($seedBytes)>1048576) throw new DomainException('continuation_seed_bound');
                $seedSha=hash('sha256',$seedBytes);
                $bootstrap=hash('sha256',HotelServerInputs::json([$p['inputs_sha256'],$plan,['continuation_seed_sha256'=>$seedSha]]));
                $this->exec('INSERT INTO hotel_server_campaigns(campaign_id,state,enabled,stage_cursor,new_accepted,historical_accepted,baseline_sha256,inputs_sha256,bootstrap_sha256,plan_bytes,reserved_calls,reserved_tokens,reserved_micros) VALUES(?,?,0,0,0,19,?,?,?,?,0,0,0)',
                    [$successorId,'disabled',$seedSha,$p['inputs_sha256'],$bootstrap,HotelServerInputs::json($plan)]);
                $this->exec('INSERT INTO hotel_server_inputs(campaign_id,inputs_sha256,package_bytes) VALUES(?,?,?)',[$successorId,$p['inputs_sha256'],HotelServerInputs::json($p)]);
                $this->exec('INSERT INTO hotel_server_baselines(campaign_id,baseline_sha256,parent_sha256,document_bytes) VALUES(?,?,NULL,?)',[$successorId,$seedSha,$seedBytes]);
                // Includes dynamic reserves that were added after the original immutable import.
                $s=$this->pdo->prepare('SELECT semantic_key,kind FROM hotel_server_exclusions WHERE campaign_id=?');$s->execute([$predecessorId]);
                $excluded=$p['exclusions'];foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row)$excluded[$row['semantic_key']]=$row['kind'];$s->closeCursor();
                foreach($excluded as $key=>$kind)$this->exec('INSERT INTO hotel_server_exclusions(campaign_id,semantic_key,kind) VALUES(?,?,?)',[$successorId,$key,$kind]);
                foreach($plan['stages'] as $scopes)$this->safeScopes($successorId,$scopes);
                $changed=$this->exec("UPDATE hotel_server_campaigns SET enabled=0 WHERE campaign_id=? AND enabled=1 AND state='complete' AND bootstrap_sha256=? AND baseline_sha256=?",[$predecessorId,$expectedBootstrapSha256,$expectedBaselineSha256]);
                if($changed!==1)throw new DomainException('predecessor_CAS');
                $next=$this->campaign($successorId);$this->verifyInputs($next);$this->enqueue($successorId,0,'producer');
                $this->fault('handoff_before_commit');return $this->projection($next);
            });
        } finally { $this->inputCache=null; $this->inputCacheCampaign=null; }
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
            $r = ['research_mode'=>$plan['research_mode']??null,'schema'=>'hotel-server-attempt-v1','campaign_id'=>$id,'stage_index'=>$stage,'attempt_id'=>$attempt,'role'=>$role,
                'principal'=>$principal,'model'=>$plan['models'][$role],'baseline_sha256'=>$c['baseline_sha256'],'inputs_sha256'=>$c['inputs_sha256'],
                'scopes'=>$scopes,'accepted_context'=>$this->acceptedView($c,$scopes),'dependencies'=>array_map(static fn(array $s):array=>$package['stage_contexts'][HotelServerInputs::key($s)],$scopes),'criteria_sha256'=>$package['pins']['criteria'],'candidate'=>$candidate ? json_decode($candidate['candidate_bytes'],true,64,JSON_THROW_ON_ERROR) : null,
                'candidate_sha256'=>$candidate['candidate_sha256'] ?? null, 'limits'=>$limits];
            $requestBytes = HotelServerInputs::json($r);
            if(strlen($requestBytes)>262144)throw new DomainException('bounded_assignment_projection_required');
            if($this->provider instanceof HotelServerPreparedProvider)$this->provider->preflight($r);
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
            if(($result['provider_outcome']??null)==='rejected'){
                if(!is_string($result['provider_response_id']??null)||!preg_match('/\\Aresp_[A-Za-z0-9_-]{1,251}\\z/D',$result['provider_response_id'])
                    ||!in_array($result['rejection_code']??null,['insufficient_evidence','invalid_candidate','invalid_review','invalid_response','provider_refused','provider_incomplete'],true))
                    throw new DomainException('provider_rejection_evidence_required');
                $this->exec("UPDATE hotel_server_attempts SET state='succeeded',result_sha256=?,result_bytes=? WHERE attempt_id=?",[$resultSha,$resultBytes,$attemptId]);
                $this->exec("UPDATE hotel_server_outbox SET state='done' WHERE campaign_id=? AND stage_index=? AND role=?",[$id,$a['stage_index'],$a['role']]);
                $this->exec("UPDATE hotel_server_campaigns SET state='hold' WHERE campaign_id=?",[$id]);
                $this->fault('result_before_commit');return $this->projection($this->campaign($id));
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
        $admission=$this->package($id)['retained_admission']??null;
        if ($admission!==null) $receipt['retained_admission']=$admission;
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


    /** Verified receipt facts plus bounded explicit earlier facts with their original qualifiers. */
    public function readAccepted(string $id): array
    {
        return $this->tx($id,function(?array $c):array{
            $c=$this->required($c);$this->verifyInputs($c);return $this->acceptedView($c,null);
        });
    }

    /** Private, bounded access to all original variants, including unadmitted facts and sources. */
    public function readRetained(string $id,string $family,int $offset=0,int $limit=50): array
    {
        return $this->tx($id,function(?array $c)use($family,$offset,$limit):array{
            $c=$this->required($c);$this->verifyInputs($c);$p=$this->package($c['campaign_id']);
            return ['baseline_sha256'=>$c['baseline_sha256'],'inputs_sha256'=>$c['inputs_sha256']]
                +HotelRetainedBaseline::page($p['retained'],$p['bytes']['retained_parts'],$family,$offset,$limit);
        });
    }

    public function readRetainedDocument(string $id,string $file): string
    {
        $result=$this->tx($id,function(?array $c)use($file):array{
            $c=$this->required($c);$this->verifyInputs($c);$p=$this->package($c['campaign_id']);
            return ['bytes'=>HotelRetainedBaseline::document($p['retained'],$p['bytes']['retained_parts'],$file)];
        });
        return $result['bytes'];
    }

    private function sameHistory(array $old,array $next): void
    {
        foreach(['baseline','accepted_mapping','quarantine_mapping','accepted_context','legacy_availability','reserves'] as $name)
            if(($old['pins'][$name]??null)!==($next['pins'][$name]??null)
                ||($old['bytes'][$name]??null)!==($next['bytes'][$name]??null))throw new DomainException('continuation_history_changed');
        foreach(['retirement_sha256','registry','quarantine','accepted_context','retained','retained_admission','exclusions'] as $name)
            if(HotelServerInputs::json($old[$name]??null)!==HotelServerInputs::json($next[$name]??null))throw new DomainException('continuation_history_changed');
        $a=$old['bytes']['retained_parts'];$b=$next['bytes']['retained_parts'];ksort($a);ksort($b);
        if($a!==$b)throw new DomainException('continuation_history_changed');
    }

    /** One root per campaign. A continuation root is bound into its bootstrap pin. */
    private function baselineRoot(array $c,array $p): array
    {
        $s=$this->pdo->prepare('SELECT * FROM hotel_server_baselines WHERE campaign_id=? AND parent_sha256 IS NULL');$s->execute([$c['campaign_id']]);
        $rows=$s->fetchAll(PDO::FETCH_ASSOC);$s->closeCursor();
        if(count($rows)!==1||hash('sha256',$rows[0]['document_bytes'])!==$rows[0]['baseline_sha256'])throw new DomainException('baseline_root_invalid');
        $root=$rows[0];$parts=[$c['inputs_sha256'],$this->plan($c)];$seed=null;
        if($root['baseline_sha256']===$p['pins']['baseline']){
            if($root['document_bytes']!==$p['bytes']['baseline'])throw new DomainException('baseline_root_invalid');
        }else{
            if(strlen($root['document_bytes'])>1048576)throw new DomainException('continuation_seed_bound');
            $seed=json_decode($root['document_bytes'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($seed)||HotelServerInputs::json($seed)!==$root['document_bytes']
                ||($seed['schema']??null)!=='hotel-server-continuation-seed-v1'
                ||($seed['retained_baseline_sha256']??null)!==$p['pins']['baseline']
                ||!is_array($seed['predecessor']??null)||!is_array($seed['records']??null)||!array_is_list($seed['records']))throw new DomainException('continuation_seed_invalid');
            $parts[]=['continuation_seed_sha256'=>$root['baseline_sha256']];
        }
        if(hash('sha256',HotelServerInputs::json($parts))!==$c['bootstrap_sha256'])throw new DomainException('campaign_bootstrap_conflict');
        return $root+['seed'=>$seed];
    }

    /** Recheck canonical acceptance, not merely a complete flag or exported records. */
    private function acceptedStage(array $c,int $stage,string $parent): array
    {
        $id=$c['campaign_id'];$plan=$this->plan($c);$p=$this->package($id);$candidate=$this->candidate($id,$stage);
        $review=$this->required($this->one('SELECT * FROM hotel_server_reviews WHERE campaign_id=? AND stage_index=?',[$id,$stage]));
        $attempts=['producer'=>$this->attempt($candidate['producer_attempt']),'reviewer'=>$this->attempt($review['review_attempt'])];$requests=[];$results=[];
        foreach($attempts as $role=>$a){
            $r=json_decode($a['request_bytes'],true,64,JSON_THROW_ON_ERROR);$result=json_decode($a['result_bytes']??'null',true,64,JSON_THROW_ON_ERROR);
            if($a['campaign_id']!==$id||(int)$a['stage_index']!==$stage||$a['role']!==$role||$a['state']!=='succeeded'||$a['baseline_sha256']!==$parent
                ||($r['inputs_sha256']??null)!==$c['inputs_sha256']||($r['criteria_sha256']??null)!==$p['pins']['criteria']||($r['model']??null)!==$plan['models'][$role]
                ||HotelServerInputs::json($r['scopes']??null)!==HotelServerInputs::json($plan['stages'][$stage])
                ||HotelServerInputs::json($r['limits']??null)!==HotelServerInputs::json($plan['limits'])||!is_array($result)||isset($result['provider_outcome']))throw new DomainException('predecessor_attempt_unconfirmed');
            foreach(['attempt_id','principal','baseline_sha256'] as $field)if(($result[$field]??null)!==$a[$field])throw new DomainException('predecessor_attempt_unconfirmed');
            if(($result['inputs_sha256']??null)!==$c['inputs_sha256'])throw new DomainException('predecessor_attempt_unconfirmed');
            $requests[$role]=$r;$results[$role]=$result;
        }
        if($attempts['producer']['attempt_id']===$attempts['reviewer']['attempt_id']||$attempts['producer']['principal']===$attempts['reviewer']['principal']
            ||($requests['producer']['candidate']??null)!==null||($requests['producer']['candidate_sha256']??null)!==null
            ||HotelServerInputs::json($results['producer']['candidate']??null)!==$candidate['candidate_bytes']
            ||($requests['reviewer']['candidate_sha256']??null)!==$candidate['candidate_sha256']
            ||HotelServerInputs::json($requests['reviewer']['candidate']??null)!==$candidate['candidate_bytes']
            ||(int)$review['approved']!==1||$review['candidate_sha256']!==$candidate['candidate_sha256']||$review['review_bytes']!==$attempts['reviewer']['result_bytes']
            ||($results['reviewer']['approved']??null)!==true||($results['reviewer']['candidate_sha256']??null)!==$candidate['candidate_sha256']
            ||($results['reviewer']['criteria_sha256']??null)!==$p['pins']['criteria']
            ||!is_string($results['reviewer']['evidence']??null)||trim($results['reviewer']['evidence'])==='')throw new DomainException('predecessor_review_unconfirmed');
        $document=json_decode($candidate['candidate_bytes'],true,64,JSON_THROW_ON_ERROR);$this->validateCandidate($document,$requests['producer']);
        $deltaBytes=HotelServerInputs::json(['schema'=>'hotel-server-baseline-v1','parent_sha256'=>$parent,'candidate_sha256'=>$candidate['candidate_sha256'],'records'=>$document['records']]);
        $baseline=hash('sha256',$deltaBytes);$delta=$this->required($this->one('SELECT * FROM hotel_server_baselines WHERE campaign_id=? AND baseline_sha256=?',[$id,$baseline]));
        if($delta['parent_sha256']!==$parent||$delta['document_bytes']!==$deltaBytes)throw new DomainException('predecessor_baseline_unconfirmed');
        $receipt=['schema'=>'hotel-server-acceptance-v1','campaign_id'=>$id,'stage_index'=>$stage,'candidate_sha256'=>$candidate['candidate_sha256'],
            'producer_attempt'=>$attempts['producer']['attempt_id'],'review_attempt'=>$attempts['reviewer']['attempt_id'],
            'previous_baseline_sha256'=>$parent,'baseline_sha256'=>$baseline,'inputs_sha256'=>$c['inputs_sha256'],'new_acceptance_credit'=>1,'historical_accepted'=>19];
        if(isset($p['retained_admission']))$receipt['retained_admission']=$p['retained_admission'];
        $row=$this->required($this->one('SELECT * FROM hotel_server_receipts WHERE campaign_id=? AND stage_index=?',[$id,$stage]));
        if($row['receipt_bytes']!==HotelServerInputs::json($receipt)||hash('sha256',$row['receipt_bytes'])!==$row['receipt_sha256'])throw new DomainException('predecessor_receipt_unconfirmed');
        $records=[];$values=[];
        foreach($document['records'] as $record){
            $key=HotelServerInputs::key($record['scope']);$bytes=HotelServerInputs::json($record);
            $saved=$this->required($this->one('SELECT * FROM hotel_server_records WHERE semantic_key=?',[$key]));
            if($saved['campaign_id']!==$id||$saved['candidate_sha256']!==$candidate['candidate_sha256']||$saved['record_bytes']!==$bytes)throw new DomainException('predecessor_record_unconfirmed');
            $records[$key]=['semantic_key'=>$key,'campaign_id'=>$id,'stage_index'=>$stage,'candidate_sha256'=>$candidate['candidate_sha256'],
                'receipt_sha256'=>$row['receipt_sha256'],'record_sha256'=>hash('sha256',$bytes)];$values[$key]=$record;
        }
        foreach($attempts as $role=>$a){$out=$this->required($this->one('SELECT * FROM hotel_server_outbox WHERE campaign_id=? AND stage_index=? AND role=?',[$id,$stage,$role]));
            if($out['state']!=='done'||$out['attempt_id']!==$a['attempt_id'])throw new DomainException('predecessor_outbox_unconfirmed');}
        $out=$this->required($this->one('SELECT * FROM hotel_server_catalog_outbox WHERE receipt_sha256=?',[$row['receipt_sha256']]));
        if($out['campaign_id']!==$id||$out['payload_bytes']!==$deltaBytes||!in_array($out['state'],['pending','done'],true))throw new DomainException('predecessor_projection_unconfirmed');
        return ['baseline_sha256'=>$baseline,'receipt'=>['stage_index'=>$stage,'receipt_sha256'=>$row['receipt_sha256']],'records'=>$records,'values'=>$values];
    }

    /** The flattened references stay compact; only this campaign's at most ten deltas are walked. */
    private function completedEvidence(array $c): array
    {
        $plan=$this->plan($c);$this->validatePlan($plan);$n=count($plan['stages']);
        if($c['state']!=='complete'||(int)$c['stage_cursor']!==$n||(int)$c['new_accepted']!==$n||(int)$c['historical_accepted']!==19
            ||(int)$c['reserved_calls']!==2*$n||(int)$c['reserved_tokens']!==2*$n*$plan['limits']['tokens_per_call']
            ||(int)$c['reserved_micros']!==2*$n*$plan['limits']['micros_per_call'])throw new DomainException('predecessor_not_complete');
        $this->verifyInputs($c,false);$root=$this->baselineRoot($c,$this->package($c['campaign_id']));$parent=$root['baseline_sha256'];$records=[];$receipts=[];$stages=[];
        foreach($root['seed']['records']??[] as $record){$key=HotelServerInputs::sha($record['semantic_key']??null);
            if(isset($records[$key]))throw new DomainException('continuation_duplicate_record');$records[$key]=$record;}
        foreach($plan['stages'] as $stage=>$scopes){
            $e=$this->acceptedStage($c,$stage,$parent);$stages[$stage]=$e;$receipts[]=$e['receipt'];$parent=$e['baseline_sha256'];
            foreach($e['records'] as $key=>$record){if(isset($records[$key]))throw new DomainException('continuation_duplicate_record');$records[$key]=$record;}
        }
        if($parent!==$c['baseline_sha256'])throw new DomainException('predecessor_baseline_unconfirmed');
        foreach(['attempts'=>2*$n,'outbox'=>2*$n,'candidates'=>$n,'reviews'=>$n,'receipts'=>$n,'baselines'=>$n+1,
            'catalog_outbox'=>$n,'records'=>array_sum(array_map('count',$plan['stages']))] as $table=>$expected)
            if((int)$this->one('SELECT COUNT(*) AS n FROM hotel_server_'.$table.' WHERE campaign_id=?',[$c['campaign_id']])['n']!==$expected)throw new DomainException('predecessor_counts_unconfirmed');
        ksort($records);return ['records'=>array_values($records),'receipts'=>$receipts,'stages'=>$stages];
    }

    private function continuationRecords(array $c,array $seed): array
    {
        $expected=['schema','retained_baseline_sha256','predecessor','records'];$keys=array_keys($seed);sort($keys);sort($expected);
        if($keys!==$expected)throw new DomainException('continuation_seed_invalid');
        $pin=$seed['predecessor'];$keys=array_keys($pin);sort($keys);$expected=['baseline_sha256','bootstrap_sha256','campaign_id','inputs_sha256','receipts'];
        if($keys!==$expected||!is_string($pin['campaign_id'])||$pin['campaign_id']===$c['campaign_id'])throw new DomainException('continuation_predecessor_invalid');
        self::id($pin['campaign_id']);$previous=$this->campaign($pin['campaign_id']);
        foreach(['baseline_sha256','bootstrap_sha256','inputs_sha256'] as $field)
            if(HotelServerInputs::sha($pin[$field])!==$previous[$field])throw new DomainException('continuation_predecessor_CAS');
        if((int)$previous['enabled']!==0)throw new DomainException('continuation_predecessor_CAS');
        $this->sameHistory($this->package($previous['campaign_id']),$this->package($c['campaign_id']));
        $e=$this->completedEvidence($previous);
        if(HotelServerInputs::json($pin['receipts'])!==HotelServerInputs::json($e['receipts'])
            ||HotelServerInputs::json($seed['records'])!==HotelServerInputs::json($e['records']))throw new DomainException('continuation_seed_lineage');
        $cache=[];foreach($e['stages'] as $stage=>$proof)$cache[$previous['campaign_id']."\0".$stage]=$proof;
        $values=[];
        foreach($seed['records'] as $record){
            $key=HotelServerInputs::sha($record['semantic_key']??null);$owner=$record['campaign_id']??null;$stage=$record['stage_index']??null;
            if(!is_string($owner)||!is_int($stage)||$stage<0||$stage>=10)throw new DomainException('continuation_record_invalid');
            self::id($owner);$cacheKey=$owner."\0".$stage;
            if(!isset($cache[$cacheKey])){
                $origin=$this->campaign($owner);$this->verifyInputs($origin,false);
                $receipt=$this->required($this->one('SELECT * FROM hotel_server_receipts WHERE campaign_id=? AND stage_index=?',[$owner,$stage]));
                $body=json_decode($receipt['receipt_bytes'],true,64,JSON_THROW_ON_ERROR);
                $cache[$cacheKey]=$this->acceptedStage($origin,$stage,HotelServerInputs::sha($body['previous_baseline_sha256']??null));
            }
            $proof=$cache[$cacheKey];
            if(!isset($proof['records'][$key])||HotelServerInputs::json($record)!==HotelServerInputs::json($proof['records'][$key])||isset($values[$key]))throw new DomainException('continuation_record_unconfirmed');
            $values[$key]=$proof['values'][$key];
        }
        return $values;
    }

    private function acceptedView(array $c,?array $scopes): array
    {
        $p=$this->package($c['campaign_id']);$records=[];
        foreach($p['retained']['model_records'] as $r)$records[HotelServerInputs::key($r['scope'])]=$r;
        foreach($p['accepted_context'] as $r)$records[HotelServerInputs::key($r['scope'])]=$r;
        $root=$this->baselineRoot($c,$p);
        if($root['seed']!==null)foreach($this->continuationRecords($c,$root['seed']) as $key=>$record){
            if(isset($records[$key]))throw new DomainException('duplicate_accepted_baseline_key');$records[$key]=$record;
        }
        $hash=$c['baseline_sha256'];$deltas=[];$seen=[];
        while($hash!==$root['baseline_sha256']){
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

    private function verifyInputs(array $c,bool $verifySeed=true): void
    {
        $p=$this->package($c['campaign_id']); $identity=$p; unset($identity['inputs_sha256'],$identity['bytes']);
        if (hash('sha256',HotelServerInputs::json($identity))!==$c['inputs_sha256'] || $p['inputs_sha256']!==$c['inputs_sha256'] || count($p['registry'])!==19) throw new DomainException('immutable_inputs_mismatch');
        foreach ($p['pins'] as $name=>$pin) if (!is_string($p['bytes'][$name]??null)||hash('sha256',$p['bytes'][$name])!==$pin) throw new DomainException('immutable_input_bytes_mismatch');
        HotelRetainedBaseline::verify($p['retained']??[],$p['bytes']['retained_parts']??[]);
        $b=$this->one('SELECT document_bytes FROM hotel_server_baselines WHERE campaign_id=? AND baseline_sha256=?',[$c['campaign_id'],$c['baseline_sha256']]);
        if (!$b || hash('sha256',$b['document_bytes'])!==$c['baseline_sha256']) throw new DomainException('baseline_bytes_mismatch');
        $root=$this->baselineRoot($c,$p);
        if($verifySeed&&$root['seed']!==null)$this->continuationRecords($c,$root['seed']);
    }

    private function safeScopes(string $id,array $scopes): void
    {
        $package=$this->package($id);
        foreach ($scopes as $scope) {
            $key=HotelServerInputs::key($scope);
            HotelRetainedBaseline::assertScope($package['retained'],$scope);
            if(!isset($package['stage_contexts'][$key]))throw new DomainException('scoped_dependency_projection_required');
            if ($this->one('SELECT semantic_key FROM hotel_server_exclusions WHERE campaign_id=? AND semantic_key=?',[$id,$key])
                || $this->one('SELECT semantic_key FROM hotel_server_records WHERE semantic_key=?',[$key])) throw new DomainException('accepted_or_reserved_scope');
        }
    }

    private function validatePlan(array $p): void
    {
        if(isset($p['research_mode'])&&$p['research_mode']!=='assigned_retained_excerpts')throw new DomainException('hotel_research_mode_not_supported');
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
        try {
            return $this->lane->own($id,function() use($id,$f): array {
                $c=$this->one('SELECT * FROM hotel_server_campaigns WHERE campaign_id=?'.$this->lock(),[$id]);
                return $f($c);
            });
        } finally { $this->inputCache=null; $this->inputCacheCampaign=null; }
    }
    private function package(string $id): array {
        if($this->inputCache!==null&&$this->inputCacheCampaign===$id)return $this->inputCache;
        $this->inputCache=json_decode($this->required($this->one('SELECT package_bytes FROM hotel_server_inputs WHERE campaign_id=?',[$id]))['package_bytes'],true,64,JSON_THROW_ON_ERROR);
        $this->inputCacheCampaign=$id;return $this->inputCache;
    }
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
    private function projection(array $c): array {
        $view=['campaign_id'=>$c['campaign_id'],'state'=>$c['state'],'enabled'=>(int)$c['enabled']===1,'historical_accepted'=>19,'new_accepted'=>(int)$c['new_accepted'],'stage_index'=>(int)$c['stage_cursor'],'baseline_sha256'=>$c['baseline_sha256'],'bootstrap_sha256'=>$c['bootstrap_sha256']];
        $admission=$this->package($c['campaign_id'])['retained_admission']??null;
        return $admission===null?$view:$view+['retained_admission'=>$admission];
    }
    private function lock(): string { return $this->driver==='mysql'?' FOR UPDATE':''; }
    private function now(): int { return $this->clock===null?time():($this->clock)(); }
    private function fault(string $at): void { if ($this->fault!==null) ($this->fault)($at); }
    private function required(?array $r): array { if (!$r) throw new DomainException('server_row_required'); return $r; }
    private function one(string $sql,array $args): ?array { $s=$this->pdo->prepare($sql); $s->execute($args); return $s->fetch(PDO::FETCH_ASSOC)?:null; }
    private function exec(string $sql,array $args): int { $s=$this->pdo->prepare($sql); $s->execute($args); return $s->rowCount(); }
    private static function id(string $id): void { if ($id===HotelServerInputs::OLD_QUEUE || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D',$id)) throw new DomainException('new_campaign_id_required'); }
}

