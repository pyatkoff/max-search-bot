<?php
declare(strict_types=1);
require_once __DIR__.'/DevelopmentWorkServerProvider.php';
require_once __DIR__.'/DevelopmentWorkServerAmendment.php';

/** Part of the canonical queue: same PDO transaction/lane and native audit. */
trait DevelopmentWorkServerControl
{
    use DevelopmentWorkServerAmendment;
    private function serverControl(array $q): ?array
    {
        return $this->one('SELECT * FROM development_work_server_controls WHERE queue_id=?',[$q['queue_id']]);
    }
    private function serverNativeOnly(array $q): void
    {
        if($this->serverControl($q)!==null)throw new DevelopmentWorkQueueConflict('server_model_only');
    }
    /** Fixed new-pair overlay only; native owned()/authorize() and old bytes stay unchanged. */
    private function serverAdmissionOwned(string $id,int $admin,string $connection): array
    {
        self::actor($admin,$connection);
        $q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.$this->lock(),[$id]);
        if(!$q||$q['project_key']!==self::PROJECT||(int)$q['admin_id']!==$admin)
            throw new DomainException('development_work_access_denied');
        if(hash_equals($q['oauth_connection_id'],$connection))return $q;
        if($id!==DevelopmentWorkServerContract::QUEUE)throw new DomainException('development_work_access_denied');
        $control=$this->serverControl($q);
        if($control===null) {
            if(!$this->serverEligible($q))throw new DomainException('development_work_access_denied');
        } else {
            $this->serverBinding($q,$control);
            if(!hash_equals($control['execution_connection_id'],$connection))throw new DomainException('development_work_access_denied');
        }
        $this->serverPairAuthority($q,$connection);
        return $q;
    }
    private function serverPairAuthority(array $q,string $connection): void
    {
        if(hash_equals($q['oauth_connection_id'],$connection))return;
        require_once __DIR__.'/DevelopmentWorkConnectionAuthority.php';
        (new DevelopmentWorkConnectionAuthority($this->pdo,$this->clock))
            ->assertPair((int)$q['admin_id'],$q['oauth_connection_id'],$connection);
    }
    /** New server execution always needs a fresh active authority, including native-owner mode. */
    private function serverExecutionAuthority(array $q,string $connection): void
    {
        require_once __DIR__.'/DevelopmentWorkConnectionAuthority.php';
        $authority=new DevelopmentWorkConnectionAuthority($this->pdo,$this->clock);
        if(hash_equals($q['oauth_connection_id'],$connection))$authority->assertCurrent((int)$q['admin_id'],$connection);
        else $authority->assertPair((int)$q['admin_id'],$q['oauth_connection_id'],$connection);
    }
    private function serverAdmissionFinish(array $q,string $connection): void
    {
        $this->serverPairAuthority($q,$connection);$control=$this->serverControl($q);
        if($control!==null&&hash_equals($control['execution_connection_id'],$connection))
            $this->serverExecutionAuthority($q,$connection);
    }
    private function serverAuthority(array $q,string $connection): array
    {
        return ['schema'=>'development-server-authority-overlay-v1','admin_id'=>(int)$q['admin_id'],
            'native_owner_connection_id'=>$q['oauth_connection_id'],'execution_connection_id'=>$connection,
            'kind'=>hash_equals($q['oauth_connection_id'],$connection)?'native_owner':'same_admin_overlay'];
    }
    private function serverLegacy(array $q): string
    {
        $old='DEV-CONTROL-COMMISSION-20261028-01';
        $data=['initial_plan'=>$q['initial_plan_sha256'],'initial_tasks'=>$q['initial_tasks_sha256'],
            'admin'=>(string)$q['admin_id'],'connection'=>$q['oauth_connection_id'],
            'retirement_count'=>(string)$q['retirement_count'],'created_at'=>$q['created_at']];
        foreach(['items','receipts','resolutions','retirements'] as $family) {
            $s=$this->pdo->prepare('SELECT * FROM development_work_'.$family.' WHERE queue_id=? AND task_id=?');
            $s->execute([$q['queue_id'],$old]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as &$row)foreach($row as &$value)if($value!==null)$value=(string)$value;
            unset($row,$value);$data[$family]=$rows;
        }
        $s=$this->pdo->prepare('SELECT * FROM development_work_connection_handoffs WHERE queue_id=?');
        $s->execute([$q['queue_id']]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row)foreach($row as &$value)if($value!==null)$value=(string)$value;
        unset($row,$value);$data['handoffs']=$rows;
        return self::hash($data);
    }
    private function serverEligible(array $q): bool
    {
        if($q['queue_id']!==DevelopmentWorkServerContract::QUEUE||$q['current_task_id']!==null
            ||$q['state']!=='blocked'||(int)$q['task_count']!==1||(int)$q['accepted_count']!==0
            ||(int)$q['blocked_count']!==1||(int)$q['retirement_count']!==1
            ||$q['plan_sha256']!==$q['initial_plan_sha256'])return false;
        $old=$this->item($q['queue_id'],'DEV-CONTROL-COMMISSION-20261028-01');
        if(!$old||$old['state']!=='blocked'||$old['claim_sha256']!==null||$old['current_run_id']!==null
            ||$old['owner_task_id']!=='Automation_76dda46db37c8191815e12bde323b6aa')return false;
        $this->storedSpec($old);
        if($this->one('SELECT queue_id FROM development_work_receipts WHERE queue_id=? LIMIT 1',[$q['queue_id']])
            ||$this->one('SELECT queue_id FROM development_work_appends WHERE queue_id=? LIMIT 1',[$q['queue_id']]))return false;
        return true;
    }
    private function serverImplementation(): array
    {
        $files=[];
        foreach(['DevelopmentWorkServerContract.php','DevelopmentWorkServerControl.php','DevelopmentWorkServerProvider.php',
            'DevelopmentWorkServerRuntime.php','DevelopmentWorkQueue.php','../ai/ManagerDraftClient.php',
            'DevelopmentWorkEventService.php','DevelopmentWorkMcpAdapter.php','DevelopmentWorkMcpService.php',
            'ManagerPreparationMcpService.php','ManagerPreparationOAuth.php','DevelopmentWorkConnectionAuthority.php','../tools/development_work.php','../migrations/096_development_work_server_control.sql'] as $file) {
            $hash=hash_file('sha256',__DIR__.'/'.$file);
            if(!is_string($hash))throw new DomainException('development_server_implementation_unavailable');
            $files[$file]=$hash;
        }
        foreach(['development_work_server_controls'=>'execution_connection_id,binding_sha256,binding_bytes,state',
            'development_work_server_events'=>'event_id,queue_id,task_id,created_cron_id',
            'development_work_server_attempts'=>'attempt_id,queue_id,task_id,role,cron_slot,response_id,request_bytes,response_bytes'] as $table=>$columns) {
            $s=$this->pdo->query('SELECT '.$columns.' FROM '.$table.' WHERE 1=0');$s->closeCursor();
        }
        $indexes=[];
        if($this->driver==='mysql') {
            foreach($this->pdo->query('SHOW INDEX FROM development_work_server_attempts')->fetchAll(PDO::FETCH_ASSOC) as $row)
                if((int)$row['Non_unique']===0)$indexes[$row['Key_name']][(int)$row['Seq_in_index']]=$row['Column_name'];
        } else {
            foreach($this->pdo->query('PRAGMA index_list(development_work_server_attempts)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if((int)$row['unique']!==1||!preg_match('/\A[A-Za-z0-9_]+\z/D',$row['name']))continue;
                foreach($this->pdo->query('PRAGMA index_info('.$row['name'].')')->fetchAll(PDO::FETCH_ASSOC) as $col)
                    $indexes[$row['name']][(int)$col['seqno']]=$col['name'];
            }
        }
        $sets=[];
        foreach($indexes as $columns){ksort($columns);$sets[]=implode(',',array_values($columns));}
        foreach(['response_id','queue_id,task_id,role','queue_id,cron_slot'] as $needed)
            if(!in_array($needed,$sets,true))throw new DomainException('development_server_unique_schema_required');
        return ['execution_kind'=>'server_model','files'=>$files,'schema'=>'096_development_work_server_control',
            'response_task_role_slot_uniqueness'=>true];
    }
    private function serverProposal(array $q,string $connection): array
    {
        if(!$this->serverEligible($q))throw new DevelopmentWorkQueueConflict('development_server_baseline_not_eligible');
        $p=DevelopmentWorkServerContract::proposal($q['plan_sha256'],$this->serverLegacy($q));
        $this->serverPairAuthority($q,$connection);
        $p['authority']=$this->serverAuthority($q,$connection);
        $p['implementation']=$this->serverImplementation();
        // Pin the actual installed code/schema along with the frozen pair policy.
        $p['plan_sha256']=self::hash(array_diff_key($p,['plan_sha256'=>true]));
        return $p;
    }
    private function serverBeforeAppend(array $q,array $lane,string $plan,array $tasks,string $connection): void
    {
        $this->serverNativeOnly($q);
        $reserved=array_intersect(array_column($tasks,'task_id'),[DevelopmentWorkServerContract::A,DevelopmentWorkServerContract::B]);
        if(!$reserved&&hash_equals($q['oauth_connection_id'],$connection))return;
        $p=$this->serverProposal($q,$connection);
        if(self::hash($tasks)!==self::hash($p['tasks'])||$plan!==$p['plan_sha256'])
            throw new DevelopmentWorkQueueConflict('development_server_exact_candidate_required');
        if($lane['queue_id']!==null&&$lane['queue_id']!==$q['queue_id'])
            throw new DevelopmentWorkQueueConflict('development_lane_busy');
        if($this->one('SELECT queue_id FROM development_work_items WHERE state IN (?,?) OR claim_sha256 IS NOT NULL OR current_run_id IS NOT NULL LIMIT 1',['claimed','unknown']))
            throw new DevelopmentWorkQueueConflict('development_work_unresolved');
        if($this->one('SELECT subscription_id FROM development_work_events WHERE queue_id=? AND is_active=1 AND expires_at>? LIMIT 1',[$q['queue_id'],$this->now()]))
            throw new DevelopmentWorkQueueConflict('development_server_native_subscription_active');
        $this->serverExecutionAuthority($q,$connection);
        $at=$this->timestamp();
        $binding=['schema'=>'development-server-binding-v1','execution_kind'=>'server_model','proposal'=>$p,
            'admitted_at'=>$at,'deadline_at'=>$at+$p['policy']['duration_seconds'],
            'admin_id'=>(int)$q['admin_id'],'connection_sha256'=>hash('sha256',$q['oauth_connection_id'])];
        $bytes=self::canonical($binding);$hash=hash('sha256',$bytes);
        $this->execute('INSERT INTO development_work_server_controls(queue_id,execution_connection_id,binding_sha256,binding_bytes,legacy_sha256,state,reserved_calls,reserved_tokens,reserved_micros,admitted_at,deadline_at,last_dispatch_slot,attention_reason) VALUES(?,?,?,?,?,?,0,0,0,?,?,NULL,NULL)',
            [$q['queue_id'],$connection,$hash,$bytes,$p['legacy_sha256'],'ready',$at,$binding['deadline_at']]);
    }
    private function serverBinding(array $q,array $c,bool $current=false): array
    {
        $b=json_decode($c['binding_bytes'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($b)||hash('sha256',$c['binding_bytes'])!==$c['binding_sha256']
            ||self::canonical($b)!==$c['binding_bytes']||($b['execution_kind']??null)!=='server_model'
            ||($b['admin_id']??null)!==(int)$q['admin_id']
            ||($b['connection_sha256']??null)!==hash('sha256',$q['oauth_connection_id'])
            ||($b['proposal']['queue_id']??null)!==$q['queue_id']||$c['legacy_sha256']!==$this->serverLegacy($q))
            throw new DomainException('development_server_binding_or_legacy_conflict');
        if(!is_string($c['execution_connection_id'])||preg_match('/\A[a-f0-9]{32}\z/D',$c['execution_connection_id'])!==1
            ||self::hash($b['proposal']['authority']??[])!==self::hash($this->serverAuthority($q,$c['execution_connection_id'])))
            throw new DomainException('development_server_authority_binding_conflict');
        if($current)$this->serverExecutionAuthority($q,$c['execution_connection_id']);
        $policy=$b['proposal']['policy'];
        if((int)$c['reserved_calls']<0||(int)$c['reserved_calls']>4
            ||(int)$c['reserved_tokens']!==(int)$c['reserved_calls']*$policy['tokens_per_call']
            ||(int)$c['reserved_micros']!==(int)$c['reserved_calls']*$policy['reserved_micros_per_call'])
            throw new DomainException('development_server_reservation_conflict');
        $effective=$this->serverAmendmentEffective($q,$c,$b,$current);
        if($current&&!isset($effective['amendment_sha256'])&&(self::hash(DevelopmentWorkServerContract::policy())!==self::hash($policy)
            ||self::hash($this->serverImplementation())!==self::hash($b['proposal']['implementation'])))
            throw new DomainException('development_server_policy_changed');
        return $effective;
    }
    /** Old TEXT bytes remain untouched; only newly captured non-UTF8 bytes need the BLOB sidecar. */
    private function serverHydrateRaw(array $row): array
    {
        $blob=$this->one('SELECT * FROM development_work_server_raw_bytes WHERE attempt_id=?',[$row['attempt_id']]);
        if($blob)foreach(['response','actual_request'] as $part)if($blob[$part.'_blob']!==null){
            $bytes=$blob[$part.'_blob'];
            if(is_resource($bytes))$bytes=stream_get_contents($bytes,16385);
            if(!is_string($bytes)||$row[$part.'_bytes']!==null||strlen($bytes)>($part==='response'?16384:8192)
                ||hash('sha256',$bytes)!==$row[$part.'_sha256'])throw new DomainException('development_server_raw_blob_conflict');
            $row[$part.'_bytes']=$bytes;$row[$part.'_storage']='byte_exact_blob';
        }
        return $row;
    }
    private function serverRetainRaw(string $attempt,string $part,string $bytes): void
    {
        if(!in_array($part,['response','actual_request'],true))throw new DomainException('development_server_raw_part');
        $digest=hash('sha256',$bytes);
        if(preg_match('//u',$bytes)===1){
            $this->execute('UPDATE development_work_server_attempts SET '.$part.'_bytes=?,'.$part.'_sha256=? WHERE attempt_id=?',
                [$bytes,$digest,$attempt]);return;
        }
        $existing=$this->one('SELECT attempt_id FROM development_work_server_raw_bytes WHERE attempt_id=?',[$attempt]);
        $sql=$existing?'UPDATE development_work_server_raw_bytes SET '.$part.'_blob=? WHERE attempt_id=?'
            :'INSERT INTO development_work_server_raw_bytes('.$part.'_blob,attempt_id) VALUES(?,?)';
        $s=$this->pdo->prepare($sql);$s->bindValue(1,$bytes,PDO::PARAM_LOB);$s->bindValue(2,$attempt,PDO::PARAM_STR);$s->execute();
        $this->execute('UPDATE development_work_server_attempts SET '.$part.'_bytes=NULL,'.$part.'_sha256=? WHERE attempt_id=?',[$digest,$attempt]);
    }
    private function serverAssertFullReadback(array $q): void
    {
        $c=$this->serverControl($q);
        $this->serverExtendStatus($q,$this->project($q),$c['execution_connection_id']);
    }
    private function serverProof(array $q,array $c): array
    {
        $b=$this->serverBinding($q,$c);$events=[];$attempts=[];$receipts=[];
        $s=$this->pdo->prepare('SELECT * FROM development_work_server_events WHERE queue_id=? ORDER BY created_at,event_id');
        $s->execute([$q['queue_id']]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row)$events[]=$row;
        $s=$this->pdo->prepare('SELECT * FROM development_work_server_attempts WHERE queue_id=? ORDER BY cron_at,attempt_id');
        $s->execute([$q['queue_id']]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row=$this->serverHydrateRaw($row);
            foreach(['job','request','actual_request','response','report'] as $part) {
                $key=$part.'_bytes';$bytes=$row[$key];
                if($bytes!==null) {
                    $sha=$part==='job'?$row['job_sha256']:$row[$part.'_sha256'];
                    if(hash('sha256',$bytes)!==$sha)throw new DomainException('development_server_original_readback_failed');
                }
                $row[$part.'_base64']=$bytes===null?null:base64_encode($bytes);unset($row[$key]);
            }
            $attempts[]=$row;
        }
        $s=$this->pdo->prepare('SELECT task_id,receipt_sha256,receipt_json FROM development_work_receipts WHERE queue_id=? AND task_id IN (?,?) ORDER BY task_id');
        $s->execute([$q['queue_id'],DevelopmentWorkServerContract::A,DevelopmentWorkServerContract::B]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if(hash('sha256',$row['receipt_json'])!==$row['receipt_sha256'])throw new DomainException('development_server_receipt_readback');
            $receipts[]=$row;
        }
        if(count($attempts)>4||count($events)>2||count($receipts)>2)throw new DomainException('development_server_finite_bound');
        return ['execution_kind'=>'server_model','binding_sha256'=>$c['binding_sha256'],'binding_base64'=>base64_encode($c['binding_bytes']),
            'readback_contract'=>'full_structured_v2','amendment'=>$this->serverAmendmentProof($q),
            'acceptance_semantics'=>'original_failed_review_retained_A_requires_explicit_supplementary_resolution_B_requires_completed_stateless_pair',
            'state'=>$c['state'],'attention_reason'=>$c['attention_reason'],'reserved_calls'=>(int)$c['reserved_calls'],
            'reserved_tokens'=>(int)$c['reserved_tokens'],'reserved_micros'=>(int)$c['reserved_micros'],
            'events'=>$events,'attempts'=>$attempts,'receipts'=>$receipts,
            'completeness'=>'all_retained_bytes_base64_transport_completeness_in_original_observation',
            'scope'=>'technical_control_pair_not_coding_or_deploy'];
    }
    private function serverExtendStatus(array $q,array $out,string $connection): array
    {
        $c=$this->serverControl($q);
        if($c===null) {
            if($this->serverEligible($q)) {
                try{$out['server_model_candidate']=$this->serverProposal($q,$connection)+[
                    'transport_configured'=>ManagerDraftClient::developmentTransportAvailable()];
                    $out['execution_kind']='server_model_candidate';}
                catch(DomainException $error){$out['server_model_candidate']=['available'=>false,'reason'=>'profile_or_schema_unavailable'];}
            }
            return $out;
        }
        $out['execution_kind']='server_model';$out['expected_pending_event_id']=null;
        $out['server_control']=$this->serverProof($q,$c);
        $inspectionGate=new DevelopmentWorkServerAmendmentGate();
        $localConfiguration=$inspectionGate->configurationReadback();
        $out['server_amendment_preparation']=['operative'=>$localConfiguration['operative']??null,
            'local_configuration'=>$localConfiguration,'scope'=>'fixed_original_A02_only',
            'native_root_terminal_required'=>true,'no_mcp_mutation_or_claim_key'=>true,
            'actual_local_source_sha'=>$inspectionGate->sourceSha()];
        try{$out['server_amendment_preparation']+=['available'=>true,'future_policy'=>DevelopmentWorkServerContract::futurePolicy(),
            'future_implementation'=>$this->serverFutureImplementation()];}
        catch(DomainException|PDOException $error){$out['server_amendment_preparation']+=['available'=>false,'reason'=>'future_profile_or_schema_unavailable'];}
        DevelopmentWorkServerContract::assertReadback($out);
        return $out;
    }
    /** Subscription persistence uses the same lane/queue lock as server admission. */
    public function nativeEventWrite(int $admin,string $connection,string $id,callable $write): mixed
    {
        return $this->transaction(function()use($admin,$connection,$id,$write):mixed {
            $this->lane();$q=$this->owned($id,$admin,$connection,true);$this->serverNativeOnly($q);return $write();
        });
    }

    /** Operational proof only: no dispatch, acceptance, claim release or mutation. */
    private function serverHeldObservation(array $q,array $c,array $lane): array
    {
        $unproven=['handled'=>true,'state'=>'unknown','provider_calls'=>0];
        try {
            $this->serverExecutionAuthority($q,$c['execution_connection_id']);
            $this->ownsLane($q,$lane);$this->project($q);
            $item=$this->current($q);
            if($q['state']!=='unknown'||$item['state']!=='unknown'
                ||!in_array($item['task_id'],[DevelopmentWorkServerContract::A,DevelopmentWorkServerContract::B],true)
                ||$q['last_receipt_task_id']!==$item['task_id']
                ||!is_string($item['claim_sha256'])||!preg_match('/\\A[a-f0-9]{64}\\z/D',$item['claim_sha256']))
                return $unproven;
            $saved=$this->one('SELECT receipt_sha256,receipt_json FROM development_work_receipts WHERE queue_id=? AND task_id=?',
                [$q['queue_id'],$item['task_id']]);
            if(!$saved||hash('sha256',$saved['receipt_json'])!==$saved['receipt_sha256'])return $unproven;
            $receipt=self::receipt(json_decode($saved['receipt_json'],true,16,JSON_THROW_ON_ERROR));
            if($receipt['outcome']!=='unknown'||$receipt['run_id']!==$item['current_run_id']
                ||$receipt['owned_claims_released']!==false)return $unproven;
            $proof=$this->serverProof($q,$c);$attempts=$proof['attempts'];
            if(count($attempts)!==(int)$c['reserved_calls']||count($attempts)<1
                ||!array_filter($attempts,static fn(array $a):bool=>$a['state']==='unknown'&&$a['task_id']===$item['task_id']))return $unproven;
            foreach($attempts as $a)if(!in_array($a['state'],['unknown','succeeded'],true))return $unproven;
            $source=$this->serverImplementation()['files'];
            foreach(['DevelopmentWorkHealth.php','DevelopmentWorkCronVerifier.php'] as $file){
                $hash=hash_file('sha256',__DIR__.'/'.$file);
                if(!is_string($hash))return $unproven;$source[$file]=$hash;
            }
            // Only the aggregate is exported; the retained claim hash is never revealed.
            $held=self::hash(['binding'=>$c['binding_sha256'],'source'=>$source,
                'receipt'=>$saved['receipt_sha256'],'claim'=>$item['claim_sha256'],
                'current_run'=>$item['current_run_id'],'attempts'=>$attempts,
                'calls'=>(int)$c['reserved_calls'],'tokens'=>(int)$c['reserved_tokens'],'micros'=>(int)$c['reserved_micros']]);
            return $unproven+['execution_status'=>'held_existing_unknown','work_accepted'=>false,'held_evidence_sha256'=>$held];
        }catch(DomainException|InvalidArgumentException|JsonException $error){return $unproven;}
    }

    /** Backend-only entry, never exposed by MCP. Only the installed CLI supplies cron context. */
    public function serverTick(DevelopmentWorkServerProvider $provider,array $origin,?DevelopmentWorkServerAmendmentGate $gate=null): array
    {
        if(($origin['trigger']??null)!=='cron'||!preg_match('/\A[a-f0-9]{64}\z/D',$origin['cron_id']??'')
            ||!is_int($origin['started_at']??null))return ['handled'=>true,'state'=>'hold','reason'=>'ordinary_cron_required','provider_calls'=>0];
        $this->serverAmendmentGate=$gate;
        $amended=$this->serverApplyReviewedAmendment($origin);if($amended!==null)return $amended;
        $prepared=$this->transaction(function()use($provider,$origin):array {
            $lane=$this->lane();
            $q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.$this->lock(),[DevelopmentWorkServerContract::QUEUE]);
            if(!$q||!($c=$this->serverControl($q)))return ['handled'=>false,'provider_calls'=>0];
            $b=$this->serverBinding($q,$c);
            if($c['state']==='unknown')return $this->serverHeldObservation($q,$c,$lane);
            if(in_array($c['state'],['hold','complete'],true))
                return ['handled'=>true,'state'=>$c['state'],'provider_calls'=>0];
            $this->ownsLane($q,$lane);
            try{$b=$this->serverBinding($q,$c,true);}catch(DomainException $error){
                $this->execute('UPDATE development_work_server_controls SET state=?,attention_reason=? WHERE queue_id=?',['hold','admission_authority_or_policy_changed',$q['queue_id']]);
                return ['handled'=>true,'state'=>'hold','reason'=>'admission_authority_or_policy_changed','provider_calls'=>0];
            }
            $now=$this->timestamp();$slot=intdiv($now,60);
            if($now<(int)$c['admitted_at']||$now>=(int)$b['deadline_at']||(int)$c['reserved_calls']>=4) {
                $this->execute('UPDATE development_work_server_controls SET state=?,attention_reason=? WHERE queue_id=?',['hold','budget_or_deadline',$q['queue_id']]);
                return ['handled'=>true,'state'=>'hold','reason'=>'budget_or_deadline','provider_calls'=>0];
            }
            if(($origin['started_at']>$now||$now-$origin['started_at']>30)
                ||(isset($b['amendment_applied_at'])&&$slot<=intdiv((int)$b['amendment_applied_at'],60))
                ||($c['last_dispatch_slot']!==null&&$slot<=(int)$c['last_dispatch_slot']))
                return ['handled'=>true,'state'=>'waiting_next_cron','provider_calls'=>0];
            if(!$provider->ready())return ['handled'=>true,'state'=>'hold','reason'=>'provider_not_configured','provider_calls'=>0];
            $item=$this->current($q);$kind=$item['task_id']===DevelopmentWorkServerContract::A?'commissioning':'readiness';
            if(!in_array($item['task_id'],[DevelopmentWorkServerContract::A,DevelopmentWorkServerContract::B],true))
                throw new DomainException('development_server_foreign_task');
            $this->assertDependenciesAccepted($q,$item);
            $producer=$this->one('SELECT * FROM development_work_server_attempts WHERE queue_id=? AND task_id=? AND role=?',[$q['queue_id'],$item['task_id'],'producer']);
            if($producer!==null&&$producer['state']!=='succeeded')return ['handled'=>true,'state'=>'unknown','provider_calls'=>0];
            $role=$producer===null?'producer':'reviewer';
            if($this->one('SELECT attempt_id FROM development_work_server_attempts WHERE queue_id=? AND task_id=? AND role=?',[$q['queue_id'],$item['task_id'],$role]))
                return ['handled'=>true,'state'=>'unknown','provider_calls'=>0];
            $event=$this->one('SELECT * FROM development_work_server_events WHERE queue_id=? AND task_id=?',[$q['queue_id'],$item['task_id']]);
            if($event===null) {
                if($kind!=='commissioning'||$role!=='producer')throw new DomainException('development_server_pending_required');
                $event=['event_id'=>self::hash([$c['binding_sha256'],$item['task_id'],$item['spec_sha256']]),
                    'queue_id'=>$q['queue_id'],'task_id'=>$item['task_id'],'spec_sha256'=>$item['spec_sha256'],
                    'created_cron_id'=>$origin['cron_id'],'created_at'=>$now,'state'=>'pending'];
                $this->execute('INSERT INTO development_work_server_events(event_id,queue_id,task_id,spec_sha256,created_cron_id,created_at,state) VALUES(?,?,?,?,?,?,?)',
                    array_values($event));
            }
            if($event['spec_sha256']!==$item['spec_sha256']
                ||$event['event_id']!==self::hash([$c['binding_sha256'],$item['task_id'],$item['spec_sha256']])
                ||$event['queue_id']!==$q['queue_id']||$event['task_id']!==$item['task_id']
                ||$event['state']!==($role==='producer'?'pending':'claimed'))
                throw new DomainException('development_server_pending_pin');
            if($this->one('SELECT attempt_id FROM development_work_server_attempts WHERE queue_id=? AND cron_id=? LIMIT 1',[$q['queue_id'],$origin['cron_id']]))
                return ['handled'=>true,'state'=>'waiting_next_cron','provider_calls'=>0];
            $parent=null;
            if($kind==='readiness') {
                $parent=$this->serverParent($q);
                if($event['created_cron_id']===$origin['cron_id']||$event['created_at']>=$now)
                    return ['handled'=>true,'state'=>'waiting_next_cron','provider_calls'=>0];
            }
            $input=$kind==='commissioning'?$b['proposal']['inputs'][DevelopmentWorkServerContract::A]:$this->serverBInput($b,$parent);
            if(self::hash($input)!==$item['input_sha256'])throw new DomainException('development_server_input_pin');
            $freshLegacy=$this->serverLegacy($q);$policy=$b['proposal']['policy'];
            $installed=$b['proposal']['implementation'];
            $evidence=[
                'input'=>['sha256'=>$item['input_sha256'],'kind'=>$kind,'static_bytes_in'=>'input'],
                'execution'=>['execution_kind'=>'server_model','origin'=>'ordinary_server_cron','cron_id'=>$origin['cron_id'],
                    'started_at'=>$now,'event_id'=>$event['event_id'],'event_created_at'=>(int)$event['created_at'],
                    'event_created_cron_id'=>$event['created_cron_id'],'spec_sha256'=>$item['spec_sha256'],
                    'binding_sha256'=>$c['binding_sha256'],'plan_sha256'=>$q['plan_sha256'],
                    'implementation'=>['sha256'=>self::hash($installed),'files_sha256'=>self::hash($installed['files']),
                        'file_count'=>count($installed['files']),'schema'=>$installed['schema'],
                        'response_task_role_slot_uniqueness'=>$installed['response_task_role_slot_uniqueness']],
                    'claim_commit'=>'committed_before_transport_send','observed_accepted_count'=>(int)$q['accepted_count']],
                'limits'=>['policy_sha256'=>self::hash($policy),'profile'=>$policy['profile'],
                    'max_calls'=>$policy['max_calls'],'reserved_calls_before'=>(int)$c['reserved_calls'],
                    'tokens_per_call'=>$policy['tokens_per_call'],'reserved_micros_per_call'=>$policy['reserved_micros_per_call'],
                    'money_semantics'=>$policy['money_semantics'],'admitted_at'=>(int)$c['admitted_at'],'deadline_at'=>(int)$b['deadline_at']],
                'legacy'=>['baseline_sha256'=>$c['legacy_sha256'],'observed_sha256'=>$freshLegacy,
                    'retired_task'=>'DEV-CONTROL-COMMISSION-20261028-01','retired_count'=>(int)$q['retirement_count'],
                    'coverage'=>'old_queue_item_receipt_retirement_handoff_bytes_only',
                    'native_unknowns'=>'unreconciled_not_inspected_or_replayed_by_this_control'],
            ];
            if($parent!==null)$evidence['parent']=['resolved_in'=>'input.accepted_parent','sha256'=>self::hash($parent),
                'outcome'=>$parent['outcome'],'producer_cron_id'=>$parent['producer_cron_id'],'reviewer_cron_id'=>$parent['reviewer_cron_id']];
            $facts=['input'=>self::hash($input)===$item['input_sha256'],'legacy_unknown'=>'unreconciled'];
            if($kind==='commissioning')$facts+=['server_mode'=>$installed['execution_kind']==='server_model',
                'ordinary_cron'=>$origin['trigger']==='cron',
                'first_claim'=>$role==='producer'?($q['state']==='ready'&&$item['claim_sha256']===null):
                    ($q['state']==='claimed'&&$item['current_run_id']===$producer['response_id']),
                'legacy_integrity'=>hash_equals($c['legacy_sha256'],$freshLegacy)];
            else $facts+=['accepted_parent'=>$parent['outcome']==='accepted_readback',
                'new_pending'=>$event['spec_sha256']===$item['spec_sha256'],
                'different_cron'=>$event['created_cron_id']!==$origin['cron_id']&&(int)$event['created_at']<$now,
                'handover'=>$q['current_task_id']===DevelopmentWorkServerContract::B&&$now<(int)$b['deadline_at']];
            foreach($facts as $key=>$value)if($key!=='legacy_unknown'&&$value!==true)
                throw new DomainException('development_server_evidence_not_supported');
            $candidate=$producer===null?null:json_decode($producer['report_bytes'],true,32,JSON_THROW_ON_ERROR);
            $producerEvidence=$producer===null?null:array_intersect_key(
                json_decode($producer['metadata_bytes'],true,32,JSON_THROW_ON_ERROR),
                array_flip(['response_id','status','model','usage','wire_request_sha256','wire_response_sha256','report_sha256']));
            $payload=['execution_kind'=>'server_model','role'=>$role,'kind'=>$kind,'input'=>$input,
                'execution'=>$evidence['execution'],'evidence'=>$evidence,
                'facts'=>$facts,'candidate'=>$candidate,'producer_evidence'=>$producerEvidence,
                'transport_contract'=>isset($b['amendment_sha256'])?'full_structured_v2':'full_duplicate_v1'];
            $wire=$provider->prepare($payload);
            $attempt=bin2hex(random_bytes(32));
            $job=['schema'=>'development-server-job-v1','attempt_id'=>$attempt,'execution_kind'=>'server_model',
                'queue_id'=>$q['queue_id'],'task_id'=>$item['task_id'],'role'=>$role,'kind'=>$kind,
                'logical_owner_task_id'=>$role==='producer'?$item['owner_task_id']:$item['reviewer_task_id'],
                'binding_sha256'=>$c['binding_sha256'],'spec_sha256'=>$item['spec_sha256'],
                'input_sha256'=>$item['input_sha256'],'cron_id'=>$origin['cron_id'],'event_id'=>$event['event_id'],
                'wire_sha256'=>hash('sha256',$wire),'candidate_sha256'=>$producer['report_sha256']??null]
                +(isset($b['amendment_sha256'])?['amendment_sha256'=>$b['amendment_sha256']]:[]);
            $jobBytes=self::canonical($job);
            if($role==='producer') {
                if($q['state']!=='ready'||$item['claim_sha256']!==null||$item['current_run_id']!==null)
                    throw new DevelopmentWorkQueueConflict('development_server_not_idle');
                $this->execute('UPDATE development_work_items SET state=?,claim_sha256=?,watchdog_due_at=?,updated_at=? WHERE queue_id=? AND task_id=?',
                    ['claimed',hash('sha256',random_bytes(32)),$this->date($now+self::WATCHDOG_SECONDS),$this->now(),$q['queue_id'],$item['task_id']]);
                $this->execute('UPDATE development_work_queues SET state=?,updated_at=? WHERE queue_id=?',['claimed',$this->now(),$q['queue_id']]);
                $this->execute('UPDATE development_work_server_events SET state=? WHERE event_id=?',['claimed',$event['event_id']]);
            }
            // All unknown/reservation/request bytes commit before any network call.
            $this->execute('INSERT INTO development_work_server_attempts(attempt_id,queue_id,task_id,role,state,event_id,cron_id,cron_at,cron_slot,job_sha256,job_bytes,request_sha256,request_bytes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$attempt,$q['queue_id'],$item['task_id'],$role,'unknown',$event['event_id'],$origin['cron_id'],$now,$slot,
                    hash('sha256',$jobBytes),$jobBytes,hash('sha256',$wire),$wire]);
            $policy=$b['proposal']['policy'];
            $this->execute('UPDATE development_work_server_controls SET state=?,reserved_calls=reserved_calls+1,reserved_tokens=reserved_tokens+?,reserved_micros=reserved_micros+?,last_dispatch_slot=? WHERE queue_id=?',
                ['unknown',$policy['tokens_per_call'],$policy['reserved_micros_per_call'],$slot,$q['queue_id']]);
            return ['send'=>true,'attempt_id'=>$attempt,'wire'=>$wire];
        });
        if(!isset($prepared['send']))return $prepared;
        // No SQL transaction/row locks survive this boundary.
        try{$result=$provider->send($prepared['wire']);}
        catch(Throwable $error){$result=['report'=>['_error'=>'provider_outcome_unknown'],'transport'=>null];}
        return $this->serverRecord($prepared['attempt_id'],$result);
    }

    private function serverParent(array $q): array
    {
        $a=$this->item($q['queue_id'],DevelopmentWorkServerContract::A);
        if(!$a||$a['state']!=='accepted')throw new DomainException('development_server_parent_not_accepted');
        $amended=$this->serverAmendmentRow($q);
        if($amended&&$amended['state']==='applied')return $this->serverAmendmentParent($q,$amended);
        $this->acceptedItemReceipt($q,$a);
        $r=$this->one('SELECT * FROM development_work_receipts WHERE queue_id=? AND task_id=?',[$q['queue_id'],$a['task_id']]);
        $p=$this->one('SELECT * FROM development_work_server_attempts WHERE queue_id=? AND task_id=? AND role=?',[$q['queue_id'],$a['task_id'],'producer']);
        $v=$this->one('SELECT * FROM development_work_server_attempts WHERE queue_id=? AND task_id=? AND role=?',[$q['queue_id'],$a['task_id'],'reviewer']);
        if(!$r||!$p||!$v||$p['state']!=='succeeded'||$v['state']!=='succeeded'
            ||hash('sha256',$r['receipt_json'])!==$r['receipt_sha256'])
            throw new DomainException('development_server_parent_evidence');
        $receipt=self::receipt(json_decode($r['receipt_json'],true,16,JSON_THROW_ON_ERROR));
        if($receipt['outcome']!=='accepted_readback'||$receipt['run_id']!==$p['response_id']
            ||$receipt['reviewer_run_id']!==$v['response_id']||$receipt['result_sha256']!==$p['report_sha256'])
            throw new DomainException('development_server_parent_lineage');
        return ['task_id'=>$a['task_id'],'receipt_sha256'=>$r['receipt_sha256'],
            'result_sha256'=>$p['report_sha256'],'review_sha256'=>$v['report_sha256'],
            'acceptance_sha256'=>$receipt['acceptance_sha256'],'readback_sha256'=>$receipt['readback_sha256'],
            'producer_run_id'=>$p['response_id'],'reviewer_run_id'=>$v['response_id'],
            'producer_cron_id'=>$p['cron_id'],'reviewer_cron_id'=>$v['cron_id'],'outcome'=>'accepted_readback'];
    }
    private function serverBInput(array $binding,array $parent): array
    {
        return $binding['proposal']['dependent_template']+['accepted_parent'=>$parent];
    }
    private function serverUnknown(array $q,string $reason): array
    {
        $item=$this->current($q);
        $old=$this->one('SELECT receipt_sha256 FROM development_work_receipts WHERE queue_id=? AND task_id=?',[$q['queue_id'],$item['task_id']]);
        if(!$old) {
            $receipt=['run_id'=>$item['current_run_id'],'outcome'=>'unknown','result_sha256'=>null,'readback_sha256'=>null,
                'acceptance_sha256'=>null,'reviewer_run_id'=>null,'owned_claims_released'=>false];
            $bytes=self::canonical($receipt);
            $this->execute('INSERT INTO development_work_receipts(queue_id,task_id,spec_sha256,receipt_sha256,receipt_json,created_at) VALUES(?,?,?,?,?,?)',
                [$q['queue_id'],$item['task_id'],$item['spec_sha256'],hash('sha256',$bytes),$bytes,$this->now()]);
            $this->execute('UPDATE development_work_queues SET last_receipt_task_id=? WHERE queue_id=?',[$item['task_id'],$q['queue_id']]);
        }
        $this->execute('UPDATE development_work_items SET state=?,updated_at=? WHERE queue_id=? AND task_id=?',['unknown',$this->now(),$q['queue_id'],$item['task_id']]);
        $this->execute('UPDATE development_work_queues SET state=?,updated_at=? WHERE queue_id=?',['unknown',$this->now(),$q['queue_id']]);
        $this->execute('UPDATE development_work_server_controls SET state=?,attention_reason=? WHERE queue_id=?',['unknown',$reason,$q['queue_id']]);
        return ['handled'=>true,'state'=>'unknown','reason'=>$reason,'provider_calls'=>null,'retry_allowed'=>false];
    }
    /** Retain one original result; this method never calls a provider or creates an attempt. */
    public function serverRecord(string $attemptId,array $result): array
    {
        return $this->transaction(function()use($attemptId,$result):array {
            $lane=$this->lane();
            $a=$this->one('SELECT * FROM development_work_server_attempts WHERE attempt_id=?',[$attemptId]);
            if(!$a)throw new DomainException('development_server_original_attempt_required');
            $a=$this->serverHydrateRaw($a);
            $q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.$this->lock(),[$a['queue_id']]);
            if(!$q||!($control=$this->serverControl($q)))throw new DomainException('development_server_binding_required');
            $binding=$this->serverBinding($q,$control);$transport=$result['transport']??null;
            $raw=is_array($transport)&&is_string($transport['wire_response_bytes']??null)?$transport['wire_response_bytes']:null;
            if($a['state']==='succeeded') {
                if($raw===null||$raw!==$a['response_bytes'])throw new DevelopmentWorkQueueConflict('development_server_immutable_result');
                return ['handled'=>true,'state'=>$control['state'],'result_status'=>'already_recorded_no_replay','provider_calls'=>0];
            }
            if($a['metadata_bytes']!==null||$a['response_sha256']!==null||$a['actual_request_sha256']!==null){
                $incoming=is_array($transport)?($transport['wire_request_bytes']??null):null;
                $savedRequest=$a['actual_request_bytes']??$a['request_bytes'];
                if($raw!==$a['response_bytes']||$incoming!==$savedRequest)
                    throw new DevelopmentWorkQueueConflict('development_server_immutable_original_capture');
                return ['handled'=>true,'state'=>$control['state'],'result_status'=>'already_captured_no_replay','provider_calls'=>0];
            }
            $this->ownsLane($q,$lane);
            if($a['state']!=='unknown'||$control['state']!=='unknown'||$q['state']!=='claimed'||$q['current_task_id']!==$a['task_id'])
                throw new DevelopmentWorkQueueConflict('development_server_original_state');
            $actual=is_array($transport)&&is_string($transport['wire_request_bytes']??null)?$transport['wire_request_bytes']:null;
            $rawLimit=isset($binding['amendment_sha256'])?16384:DevelopmentWorkServerContract::MAX_RESPONSE_BYTES;$wireLimit=DevelopmentWorkServerContract::MAX_REQUEST_BYTES;
            // The real DEV transport is bounded at capture. A malformed backend result also cannot
            // poison the sole supported getter: retain a labelled prefix, never claim it is full.
            $retained=$raw===null?null:substr($raw,0,$rawLimit);
            if($retained!==null)$this->serverRetainRaw($attemptId,'response',$retained);
            if($actual!==null&&$actual!==$a['request_bytes']) {
                $prefix=substr($actual,0,$wireLimit);
                $this->serverRetainRaw($attemptId,'actual_request',$prefix);
            }
            $meta=null;$report=null;$http=is_int($transport['http_status']??null)?$transport['http_status']:null;
            if($raw!==null&&$http!==null&&strlen($raw)<=ManagerDraftClient::MAX_RESPONSE_BYTES)
                $report=ManagerDraftClient::parseHotelResponse($raw,$http,static function(array $m)use(&$meta):void{$meta=$m;});
            $observation=['schema'=>'development-server-original-observation-v1','execution_kind'=>'server_model',
                'http_status'=>$http,'wire_complete'=>($transport['wire_complete']??null)===true,
                'response_capture'=>$transport['response_capture']??'backend_supplied',
                'retained_response_is_complete'=>$raw!==null&&strlen($raw)<=$rawLimit&&($transport['wire_complete']??null)===true,
                'observed_response_bytes'=>$raw===null?null:strlen($raw),'retained_response_bytes'=>$retained===null?null:strlen($retained),
                'retained_actual_request_is_complete'=>$actual!==null&&strlen($actual)<=$wireLimit,
                'observed_actual_request_bytes'=>$actual===null?null:strlen($actual),'provider'=>$meta];
            $duplicate=false;$knownId=is_array($meta)?($meta['response_id']??null):null;
            if(is_string($knownId)&&preg_match('/\Aresp_[A-Za-z0-9_-]{1,123}\z/D',$knownId)) {
                $duplicate=$this->one('SELECT attempt_id FROM development_work_server_attempts WHERE response_id=? AND attempt_id<>?',[$knownId,$attemptId])!==null;
                $observation['duplicate_response_id']=$duplicate;
                if(!$duplicate) {
                    // Known original terminal facts survive report refusal, invalid JSON or late result.
                    $this->execute('UPDATE development_work_server_attempts SET response_id=? WHERE attempt_id=?',[$knownId,$attemptId]);
                    if($a['role']==='producer')$this->execute('UPDATE development_work_items SET current_run_id=? WHERE queue_id=? AND task_id=?',
                        [$knownId,$q['queue_id'],$a['task_id']]);
                }
            }
            $this->execute('UPDATE development_work_server_attempts SET metadata_bytes=? WHERE attempt_id=?',[self::canonical($observation),$attemptId]);
            try {
                $binding=$this->serverBinding($q,$control,true);
                if($this->timestamp()>=(int)$binding['deadline_at'])throw new DomainException('development_server_late_original_result');
                if(hash('sha256',$a['job_bytes'])!==$a['job_sha256']||hash('sha256',$a['request_bytes'])!==$a['request_sha256'])
                    throw new DomainException('development_server_original_request_corrupt');
                $job=json_decode($a['job_bytes'],true,32,JSON_THROW_ON_ERROR);
                if($job['attempt_id']!==$attemptId||$job['task_id']!==$a['task_id']||$job['role']!==$a['role']
                    ||$job['wire_sha256']!==$a['request_sha256']||$job['binding_sha256']!==$control['binding_sha256']
                    ||($job['amendment_sha256']??null)!==($binding['amendment_sha256']??null))
                    throw new DomainException('development_server_job_lineage');
                if(!is_array($transport)||($transport['wire_complete']??null)!==true
                    ||($transport['wire_request_bytes']??null)!==$a['request_bytes']
                    ||!is_int($transport['http_status']??null)||$transport['http_status']<200||$transport['http_status']>=300
                    ||$raw===null||strlen($raw)>$rawLimit)
                    throw new DomainException('development_server_original_transport_unconfirmed');
                if(!is_array($meta)||($meta['status']??null)!=='completed'
                    ||($meta['model']??null)!==$binding['proposal']['policy']['profile']['model']
                    ||!is_string($meta['response_id']??null)||strlen($meta['response_id'])>128)
                    throw new DomainException('development_server_provider_terminal_unconfirmed');
                $usage=$meta['usage']??[];
                foreach(['input_tokens','output_tokens','total_tokens'] as $key)
                    if(!is_int($usage[$key]??null)||$usage[$key]<0)throw new DomainException('development_server_usage_unconfirmed');
                if($usage['total_tokens']!==$usage['input_tokens']+$usage['output_tokens']
                    ||$usage['total_tokens']>$binding['proposal']['policy']['tokens_per_call']
                    ||$usage['output_tokens']>$binding['proposal']['policy']['profile']['max_output_tokens'])
                    throw new DomainException('development_server_usage_exceeded');
                if(!is_array($result['report']??null)||self::canonical($report)!==self::canonical($result['report']))
                    throw new DomainException('development_server_result_not_original_wire');
                $wire=json_decode($a['request_bytes'],true,32,JSON_THROW_ON_ERROR);
                $payload=json_decode($wire['input'][1]['content'][0]['text'],true,32,JSON_THROW_ON_ERROR);
                $item=$this->current($q,$a['task_id'],$job['spec_sha256']);
                if($job['input_sha256']!==$item['input_sha256']||self::hash($payload['input'])!==$item['input_sha256']
                    ||$payload['evidence']['execution']['cron_id']!==$a['cron_id']||$payload['evidence']['execution']['event_id']!==$a['event_id'])
                    throw new DomainException('development_server_actual_input_pin');
                DevelopmentWorkServerContract::validateReport($report,$a['role'],$job['kind'],$payload['facts'],$job['candidate_sha256'],$payload['evidence']);
                if(isset($binding['amendment_sha256']))foreach($report['checks'] as $check)
                    if(preg_match_all('/./us',$check['note'])>64)throw new DomainException('development_server_v2_note_budget');
                if($duplicate)
                    throw new DomainException('development_server_duplicate_response');
                $reportBytes=self::canonical($report);
                $metadata=['response_id'=>$meta['response_id'],'status'=>$meta['status'],'model'=>$meta['model'],'usage'=>$usage,
                    'execution_kind'=>'server_model','stateless'=>true,'wire_request_sha256'=>$a['request_sha256'],
                    'wire_response_sha256'=>hash('sha256',$raw),'report_sha256'=>hash('sha256',$reportBytes),'original_observation'=>$observation];
                $this->execute('UPDATE development_work_server_attempts SET response_id=?,report_bytes=?,report_sha256=?,metadata_bytes=? WHERE attempt_id=?',
                    [$meta['response_id'],$reportBytes,hash('sha256',$reportBytes),self::canonical($metadata),$attemptId]);
                $this->serverAssertFullReadback($q);
            } catch(DomainException|InvalidArgumentException|JsonException $error) {
                return $this->serverUnknown($q,'original_result_not_admitted');
            }
            // Keep the original capture even when the actual post-credit getter fails.
            $this->pdo->exec('SAVEPOINT development_server_completion');
            try{
                $this->execute('UPDATE development_work_server_attempts SET state=? WHERE attempt_id=?',['succeeded',$attemptId]);
                if($a['role']==='producer'){
                    $this->execute('UPDATE development_work_items SET current_run_id=? WHERE queue_id=? AND task_id=?',[$meta['response_id'],$q['queue_id'],$a['task_id']]);
                    $this->execute('UPDATE development_work_server_controls SET state=? WHERE queue_id=?',['candidate',$q['queue_id']]);
                    $out=['handled'=>true,'state'=>'candidate','provider_calls'=>1];
                }else $out=$this->serverAccept($q,$lane,$a,$binding);
                $fresh=$this->owned($q['queue_id'],(int)$q['admin_id'],$q['oauth_connection_id']);
                $this->serverBinding($fresh,$this->serverControl($fresh),true);
                $this->serverAssertFullReadback($fresh);
                $this->pdo->exec('RELEASE SAVEPOINT development_server_completion');return $out;
            }catch(DomainException|InvalidArgumentException|JsonException $error){
                $this->pdo->exec('ROLLBACK TO SAVEPOINT development_server_completion');
                $this->pdo->exec('RELEASE SAVEPOINT development_server_completion');
                return $this->serverUnknown($q,'saved_readback_not_admitted');
            }
        });
    }
    private function serverAccept(array $q,array $lane,array $reviewer,array $binding): array
    {
        $item=$this->current($q);$producer=$this->one('SELECT * FROM development_work_server_attempts WHERE queue_id=? AND task_id=? AND role=?',
            [$q['queue_id'],$item['task_id'],'producer']);
        $reviewer=$this->one('SELECT * FROM development_work_server_attempts WHERE attempt_id=?',[$reviewer['attempt_id']]);
        if(!$producer||!$reviewer||$producer['state']!=='succeeded'||$reviewer['state']!=='succeeded'
            ||$producer['response_id']===$reviewer['response_id']||$producer['cron_id']===$reviewer['cron_id']
            ||$producer['response_id']!==$item['current_run_id'])throw new DomainException('development_server_distinct_review_required');
        $reviewJob=json_decode($reviewer['job_bytes'],true,32,JSON_THROW_ON_ERROR);
        $review=json_decode($reviewer['report_bytes'],true,32,JSON_THROW_ON_ERROR);
        if($reviewJob['candidate_sha256']!==$producer['report_sha256']||$review['candidate_sha256']!==$producer['report_sha256'])
            throw new DomainException('development_server_exact_review_required');
        $readback=self::hash(['producer_job'=>$producer['job_sha256'],'producer_request'=>$producer['request_sha256'],
            'producer_response'=>$producer['response_sha256'],'producer_report'=>$producer['report_sha256'],
            'review_job'=>$reviewer['job_sha256'],'review_request'=>$reviewer['request_sha256'],
            'review_response'=>$reviewer['response_sha256'],'review_report'=>$reviewer['report_sha256']]);
        $acceptance=self::hash(['schema'=>'development-server-accepted-v1','binding'=>$this->serverControl($q)['binding_sha256'],
            'task'=>$item['task_id'],'spec'=>$item['spec_sha256'],'readback'=>$readback,'legacy'=>$this->serverLegacy($q)]);
        $receipt=['run_id'=>$producer['response_id'],'outcome'=>'accepted_readback','result_sha256'=>$producer['report_sha256'],
            'readback_sha256'=>$readback,'acceptance_sha256'=>$acceptance,'reviewer_run_id'=>$reviewer['response_id'],'owned_claims_released'=>true];
        self::receipt($receipt);$bytes=self::canonical($receipt);
        $this->execute('INSERT INTO development_work_receipts(queue_id,task_id,spec_sha256,receipt_sha256,receipt_json,created_at) VALUES(?,?,?,?,?,?)',
            [$q['queue_id'],$item['task_id'],$item['spec_sha256'],hash('sha256',$bytes),$bytes,$this->now()]);
        $this->execute('UPDATE development_work_queues SET last_receipt_task_id=? WHERE queue_id=?',[$item['task_id'],$q['queue_id']]);
        $next=$this->finish($q,$item,$receipt,$lane,$this->now());
        $this->execute('UPDATE development_work_server_events SET state=? WHERE queue_id=? AND task_id=?',['consumed',$q['queue_id'],$item['task_id']]);
        if($item['task_id']===DevelopmentWorkServerContract::A) {
            $this->serverMaterializeB($next,$reviewer['cron_id'],$this->timestamp());
        } else {
            if($next['current_task_id']!==null||(int)$next['task_count']!==3||(int)$next['accepted_count']!==2
                ||(int)$next['blocked_count']!==1||(int)$next['retirement_count']!==1||$next['state']!=='blocked')
                throw new DomainException('development_server_final_count');
            $this->execute('UPDATE development_work_server_controls SET state=? WHERE queue_id=?',['complete',$q['queue_id']]);
        }
        return ['handled'=>true,'state'=>$item['task_id']===DevelopmentWorkServerContract::A?'ready':'complete',
            'accepted_task'=>$item['task_id'],'provider_calls'=>1,'receipt_sha256'=>hash('sha256',$bytes)];
    }
    private function serverMaterializeB(array $next,string $createdCron,int $createdAt): void
    {
        $q=$next;$parent=$this->serverParent($q);
        $binding=$this->serverBinding($q,$this->serverControl($q));$input=$this->serverBInput($binding,$parent);
        $spec=['task_id'=>DevelopmentWorkServerContract::B,'owner_task_id'=>DevelopmentWorkServerContract::SOURCE,
            'reviewer_task_id'=>DevelopmentWorkServerContract::REVIEWER,'input_sha256'=>self::hash($input),
            'acceptance_contract_sha256'=>self::hash($binding['proposal']['dependent_acceptance']+['accepted_parent'=>$parent]),
            'deps'=>[DevelopmentWorkServerContract::A],'priority'=>10];
        $plan=self::hash(['previous_plan'=>$q['plan_sha256'],'accepted_A'=>$parent,'task'=>$spec]);
        $this->insertTasks($q['queue_id'],$plan,[$spec],(int)$next['task_count'],$this->now());
        $this->execute('INSERT INTO development_work_appends(queue_id,plan_sha256,expected_plan_sha256,tasks_sha256,task_count,created_at) VALUES(?,?,?,?,1,?)',
            [$q['queue_id'],$plan,$q['plan_sha256'],self::hash([$spec]),$this->now()]);
        $this->execute('UPDATE development_work_queues SET plan_sha256=?,task_count=task_count+1,updated_at=? WHERE queue_id=?',
            [$plan,$this->now(),$q['queue_id']]);
        $next=$this->selectNext($this->owned($q['queue_id'],(int)$q['admin_id'],$q['oauth_connection_id']),$this->lane(),$this->now());
        $b=$this->current($next);$event=self::hash([$this->serverControl($q)['binding_sha256'],$b['task_id'],$b['spec_sha256']]);
        $this->execute('INSERT INTO development_work_server_events(event_id,queue_id,task_id,spec_sha256,created_cron_id,created_at,state) VALUES(?,?,?,?,?,?,?)',
            [$event,$q['queue_id'],$b['task_id'],$b['spec_sha256'],$createdCron,$createdAt,'pending']);
        $this->execute('UPDATE development_work_server_controls SET state=? WHERE queue_id=?',['ready',$q['queue_id']]);
    }

}
