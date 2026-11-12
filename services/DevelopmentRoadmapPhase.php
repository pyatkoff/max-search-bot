<?php
declare(strict_types=1);
require_once __DIR__.'/DevelopmentRoadmapReview.php';

/** Same DEVELOPMENT lane, separate finite permission; never changes the old control. */
trait DevelopmentRoadmapPhase
{
    private function roadmapTablesReady(): bool
    {
        $tables=['development_work_roadmap_phases','development_work_roadmap_steps','development_work_roadmap_reads','development_work_roadmap_reviews'];
        $sql=$this->driver==='mysql'?'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?,?)'
            :"SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN (?,?,?,?)";
        $s=$this->pdo->prepare($sql);$s->execute($tables);return (int)$s->fetchColumn()===4;
    }
    private function roadmapAuthority(array $q,string $connection): void
    {
        require_once __DIR__.'/DevelopmentWorkConnectionAuthority.php';
        (new DevelopmentWorkConnectionAuthority($this->pdo,$this->clock))->assertCurrent((int)$q['admin_id'],$connection);
        if(!(new ManagerPreparationOAuth($this->pdo,$this->clock))->connectionAuthorized($connection,(int)$q['admin_id'],
            [ManagerPreparationOAuth::DEVELOPMENT_SCOPE,ManagerPreparationOAuth::FORECAST_SCOPE]))
            throw new DomainException('development_roadmap_financial_scope_required');
    }
    private function roadmapPhase(string $id): ?array
    {return $this->one('SELECT * FROM development_work_roadmap_phases WHERE queue_id=?'.$this->lock(),[$id]);}
    private function roadmapBlob(string $sql,array $args,array $blobs): void
    {
        $s=$this->pdo->prepare($sql);
        foreach($args as$i=>$v)$s->bindValue($i+1,$v,$v===null?PDO::PARAM_NULL:(in_array($i,$blobs,true)?PDO::PARAM_LOB:(is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR)));
        if(!$s->execute())throw new RuntimeException('development_roadmap_storage');
    }
    private function roadmapImplementation(): array
    {
        if(!$this->roadmapTablesReady())throw new DomainException('development_roadmap_schema');
        $files=[];
        foreach(['DevelopmentRoadmapSavedResult.php','DevelopmentRoadmapExactReceipt.php','DevelopmentRoadmapArchive.php','DevelopmentRoadmapReview.php','DevelopmentRoadmapPhase.php',
            'DevelopmentWorkQueue.php','DevelopmentWorkServerControl.php','DevelopmentWorkServerRuntime.php','DevelopmentWorkServerContract.php',
            'DevelopmentWorkMcpAdapter.php','ManagerPreparationMcpService.php','DevelopmentWorkConnectionAuthority.php','ManagerPreparationOAuth.php',
            'ModeledConversionValueDeliveryReader.php','ModeledConversionStatusBudget.php','ModeledConversionValueContract.php',
            'ModeledConversionProfiles.php','ModeledConversionValueExport.php','../ai/ManagerDraftClient.php',
            '../tools/development_work.php','../migrations/106_development_roadmap_saved_results.sql']as$file){
            $h=hash_file('sha256',__DIR__.'/'.$file);if(!is_string($h))throw new DomainException('development_roadmap_source');$files[$file]=$h;
        }
        $indexes=['development_work_roadmap_phases'=>[['queue_id'],['manifest_sha256']],
            'development_work_roadmap_steps'=>[['queue_id','task_id'],['queue_id','ordinal']],
            'development_work_roadmap_reads'=>[['read_id'],['queue_id','cron_slot'],['run_id']],
            'development_work_roadmap_reviews'=>[['attempt_id'],['queue_id','task_id'],['queue_id','cron_slot'],['response_id']]];
        $binary=['development_work_roadmap_phases'=>['manifest_bytes','binding_bytes'],
            'development_work_roadmap_steps'=>['input_bytes','candidate_bytes','snapshot_bytes'],
            'development_work_roadmap_reads'=>['result_bytes','snapshot_bytes'],
            'development_work_roadmap_reviews'=>['request_bytes','response_bytes','actual_request_bytes','report_bytes']];
        foreach($indexes as$table=>$sets){
            $unique=[];$types=[];
            if($this->driver==='mysql'){
                $s=$this->pdo->prepare('SELECT INDEX_NAME,COLUMN_NAME FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX');
                $s->execute([$table]);foreach($s->fetchAll(PDO::FETCH_ASSOC)as$r)$unique[$r['INDEX_NAME']][]=$r['COLUMN_NAME'];
                $s=$this->pdo->prepare('SELECT COLUMN_NAME,DATA_TYPE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?');
                $s->execute([$table]);foreach($s->fetchAll(PDO::FETCH_ASSOC)as$r)$types[$r['COLUMN_NAME']]=strtolower($r['DATA_TYPE']);
            }else{
                foreach($this->pdo->query("PRAGMA index_list('".$table."')")->fetchAll(PDO::FETCH_ASSOC)as$idx)if((int)$idx['unique']===1){
                    if(preg_match('/\\A[a-zA-Z0-9_]+\\z/D',$idx['name'])!==1)throw new DomainException('development_roadmap_index');
                    foreach($this->pdo->query("PRAGMA index_info('".$idx['name']."')")->fetchAll(PDO::FETCH_ASSOC)as$r)$unique[$idx['name']][]=$r['name'];
                }
                foreach($this->pdo->query("PRAGMA table_info('".$table."')")->fetchAll(PDO::FETCH_ASSOC)as$r)$types[$r['name']]=strtolower($r['type']);
            }
            foreach($sets as$set)if(!in_array($set,array_values($unique),true))throw new DomainException('development_roadmap_unique');
            foreach($binary[$table]as$column)if(($types[$column]??null)!=='mediumblob')throw new DomainException('development_roadmap_binary');
        }
        return ['schema'=>'106_development_roadmap_saved_results','files'=>$files,'unique_once_and_slots'=>true,
            'execution_kind'=>'server_saved_finance','native_host_dispatch'=>false];
    }
    private function roadmapBinding(array $q,array $p): array
    {
        $this->roadmapAuthority($q,$p['execution_connection_id']);
        foreach(['manifest','binding']as$key)if(hash('sha256',$p[$key.'_bytes'])!==$p[$key.'_sha256'])throw new DomainException('development_roadmap_binding');
        $m=json_decode($p['manifest_bytes'],true,32,JSON_THROW_ON_ERROR);$b=json_decode($p['binding_bytes'],true,32,JSON_THROW_ON_ERROR);
        if(self::canonical($m)!==$p['manifest_bytes']||self::canonical($b)!==$p['binding_bytes']
            ||$b['manifest_sha256']!==$p['manifest_sha256']||$b['execution_connection_id']!==$p['execution_connection_id']
            ||$b['admin_id']!==(int)$q['admin_id']||$b['deadline_at']!==(int)$p['deadline_at']
            ||$b['admitted_at']!==(int)$p['admitted_at']||$b['deadline_at']!==$b['admitted_at']+3600
            ||$b['old_control_calls']!==4||$b['new_review_limit']!==2||$b['old_unknown_reconciled']!==false
            ||self::hash($m['policy'])!==self::hash(DevelopmentRoadmapReview::policy())
            ||self::hash($this->roadmapImplementation())!==$p['implementation_sha256'])throw new DomainException('development_roadmap_policy');
        return $b;
    }
    private function roadmapInput(array $p,array $step): array
    {
        if(hash('sha256',$step['input_bytes'])!==$step['input_sha256'])throw new DomainException('development_roadmap_input');
        $v=json_decode($step['input_bytes'],true,32,JSON_THROW_ON_ERROR);
        if(self::canonical($v)!==$step['input_bytes']||$v['kind']!==$step['kind']
            ||$v['manifest_sha256']!==$p['manifest_sha256']||$v['binding_sha256']!==$p['binding_sha256'])throw new DomainException('development_roadmap_input');
        return $v;
    }
    private function roadmapParent(array $q,array $step,array $input): ?array
    {
        if((int)$step['ordinal']===1)return null;
        $a=$this->one('SELECT * FROM development_work_roadmap_steps WHERE queue_id=? AND ordinal=1',[$q['queue_id']]);
        $item=$a===null?null:$this->item($q['queue_id'],$a['task_id']);
        if(!$a||!$item||$a['state']!=='accepted'||$item['state']!=='accepted'||($input['parent_task_id']??null)!==$a['task_id'])
            throw new DomainException('development_roadmap_parent_unaccepted');
        $this->acceptedItemReceipt($q,$item);$r=$this->verifiedReceipt($q,$item,false);
        if($r['record']['receipt_sha256']!==($input['parent_receipt_sha256']??null)
            ||$r['receipt']['result_sha256']!==$a['candidate_sha256']||$a['candidate_sha256']!==($input['parent_result_sha256']??null)
            ||hash('sha256',$a['candidate_bytes'])!==$a['candidate_sha256'])throw new DomainException('development_roadmap_parent_changed');
        $this->roadmapVerifyAccepted($q,$this->roadmapPhase($q['queue_id']),$a);
        return json_decode($a['candidate_bytes'],true,32,JSON_THROW_ON_ERROR);
    }
    private function roadmapResult(array $q,array $p,array $step,array $snapshot,int $at): array
    {
        $input=$this->roadmapInput($p,$step);$parent=$this->roadmapParent($q,$step,$input);
        return DevelopmentRoadmapSavedResult::evaluate($step['kind'],$input['capsule'],$snapshot,$at,$parent,$input['parent_result_sha256']??null);
    }
    private function roadmapPayload(array $q,array $p,array $step,array $snapshot,array $candidate): array
    {
        $input=$this->roadmapInput($p,$step);
        return ['scope'=>'saved_finance_verification_only','task_id'=>$step['task_id'],'input'=>$input,
            'snapshot'=>$snapshot,'candidate'=>$candidate,'accepted_parent'=>$this->roadmapParent($q,$step,$input),
            'producer_run_id'=>$step['producer_run_id'],'producer_cron_id'=>$step['producer_cron_id']];
    }
    private function roadmapVerifyAccepted(array $q,array $p,array $step): void
    {
        $item=$this->item($q['queue_id'],$step['task_id']);$this->acceptedItemReceipt($q,$item);$receipt=$this->verifiedReceipt($q,$item,false)['receipt'];
        $review=$this->one('SELECT * FROM development_work_roadmap_reviews WHERE queue_id=? AND task_id=?',[$q['queue_id'],$step['task_id']]);
        $snapshot=json_decode($step['snapshot_bytes'],true,32,JSON_THROW_ON_ERROR);$candidate=$this->roadmapResult($q,$p,$step,$snapshot,(int)$step['snapshot_at']);
        if(!$review||$review['state']!=='succeeded'||self::hash($candidate)!==$step['candidate_sha256']
            ||hash('sha256',$step['candidate_bytes'])!==$step['candidate_sha256']||hash('sha256',$step['snapshot_bytes'])!==$step['snapshot_sha256']
            ||hash('sha256',$review['request_bytes'])!==$review['request_sha256']||$review['cron_id']===$step['producer_cron_id']
            ||(int)$review['cron_slot']<=intdiv((int)$step['producer_at'],60)
            ||DevelopmentRoadmapReview::prepare($this->roadmapPayload($q,$p,$step,$snapshot,$candidate))!==$review['request_bytes'])
            throw new DomainException('development_roadmap_accepted_evidence');
        $meta=json_decode($review['metadata_json'],true,16,JSON_THROW_ON_ERROR);
        $v=DevelopmentRoadmapReview::verify($review['request_bytes'],['report'=>json_decode($review['report_bytes'],true,16,JSON_THROW_ON_ERROR),
            'transport'=>['wire_request_bytes'=>$review['actual_request_bytes'],'wire_response_bytes'=>$review['response_bytes'],
                'wire_complete'=>true,'http_status'=>$meta['http_status']??0]],$candidate);
        $readback=$this->roadmapReadback($step,$p,$v);
        if($v['response_id']!==$receipt['reviewer_run_id']||$receipt['run_id']!==$step['producer_run_id']
            ||$receipt['result_sha256']!==$step['candidate_sha256']||$receipt['readback_sha256']!==$readback
            ||$receipt['acceptance_sha256']!==self::hash([$p['manifest_sha256'],$step['task_id'],$readback])
            ||$v['response_sha256']!==$review['response_sha256']||$v['report_sha256']!==$review['report_sha256'])
            throw new DomainException('development_roadmap_accepted_receipt');
    }
    private function roadmapReadback(array $step,array $p,array $v): string
    {return self::hash(['input'=>$step['input_sha256'],'snapshot'=>$step['snapshot_sha256'],'candidate'=>$step['candidate_sha256'],
        'original_response'=>$v['response_sha256'],'report'=>$v['report_sha256'],'binding'=>$p['binding_sha256']]);}
    private function roadmapOwnedStatus(string $id,int $admin,string $connection): ?array
    {
        if($id!==DevelopmentWorkServerContract::QUEUE||!$this->roadmapTablesReady()||!($p=$this->roadmapPhase($id)))return null;
        $q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.$this->lock(),[$id]);
        if(!$q||(int)$q['admin_id']!==$admin||$q['project_key']!==self::PROJECT||!hash_equals($p['execution_connection_id'],$connection))return null;
        $this->roadmapAuthority($q,$connection);$out=$this->roadmapProof($q,$p);$this->roadmapAuthority($q,$connection);return $out;
    }
    private function roadmapProof(array $q,array $p): array
    {
        $out=$this->project($q);unset($out['oauth_connection_id'],$out['expected_pending_event_id']);$out['execution_kind']='server_saved_finance';
        $proof=['readback_contract'=>'full_structured_v2','scope'=>'two_saved_result_verifications_not_coding_deploy_or_native_host',
            'state'=>$p['state'],'archive_sha256'=>$p['archive_sha256'],'manifest_sha256'=>$p['manifest_sha256'],
            'manifest_base64'=>base64_encode($p['manifest_bytes']),'binding_sha256'=>$p['binding_sha256'],'binding_base64'=>base64_encode($p['binding_bytes']),
            'implementation_sha256'=>$p['implementation_sha256'],'old_control_calls_unchanged'=>4,'last_reason'=>$p['last_reason'],'steps'=>[]];
        foreach(['admitted_at','deadline_at','read_count','review_calls','reserved_tokens','reserved_micros']as$key)$proof[$key]=(int)$p[$key];
        $s=$this->pdo->prepare('SELECT * FROM development_work_roadmap_steps WHERE queue_id=? ORDER BY ordinal');$s->execute([$q['queue_id']]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC)as$step){
            if($step['state']==='accepted')$this->roadmapVerifyAccepted($q,$p,$step);
            $row=array_intersect_key($step,array_flip(['task_id','ordinal','kind','state','input_sha256','producer_run_id','producer_cron_id','producer_at','candidate_sha256','snapshot_sha256','snapshot_at']));
            foreach(['input','candidate','snapshot']as$key)$row[$key.'_base64']=$step[$key.'_bytes']===null?null:base64_encode($step[$key.'_bytes']);
            $review=$this->one('SELECT * FROM development_work_roadmap_reviews WHERE queue_id=? AND task_id=?',[$q['queue_id'],$step['task_id']]);$row['review']=null;
            if($review){
                $r=array_intersect_key($review,array_flip(['attempt_id','cron_id','cron_slot','started_at','state','request_sha256','response_id','response_sha256','report_sha256']));
                foreach(['request','response','report']as$key)$r[$key.'_base64']=$review[$key.'_bytes']===null?null:base64_encode($review[$key.'_bytes']);
                $r['actual_request_equals_prepared']=$review['actual_request_bytes']!==null&&$review['actual_request_bytes']===$review['request_bytes'];
                $r['actual_request_base64']=$r['actual_request_equals_prepared']||$review['actual_request_bytes']===null?null:base64_encode($review['actual_request_bytes']);
                $r['metadata']=$review['metadata_json']===null?null:json_decode($review['metadata_json'],true,16,JSON_THROW_ON_ERROR);$row['review']=$r;
            }
            $reads=$this->pdo->prepare('SELECT read_id,run_id,cron_id,cron_slot,started_at,state,result_sha256 FROM development_work_roadmap_reads WHERE queue_id=? AND task_id=? ORDER BY started_at,read_id');
            $reads->execute([$q['queue_id'],$step['task_id']]);$row['read_history']=$reads->fetchAll(PDO::FETCH_ASSOC);
            $row['read_history_scope']='metadata_all_observations_full_original_candidate_capture_above';$proof['steps'][]=$row;
        }
        $out['server_roadmap']=$proof;$out['subscription_setup']=['semantics'=>'server_owned_cron_no_native_subscription'];
        if(strlen(self::canonical($out))>DevelopmentRoadmapReview::MAX_PROOF)throw new DomainException('development_roadmap_proof_budget');return $out;
    }
    private function roadmapSpec(string $task,string $kind,array $input,array $deps): array
    {
        return self::specs([['task_id'=>$task,'owner_task_id'=>DevelopmentRoadmapReview::OWNER,'reviewer_task_id'=>DevelopmentRoadmapReview::REVIEWER,
            'input_sha256'=>self::hash($input),'acceptance_contract_sha256'=>self::hash(['schema'=>DevelopmentRoadmapReview::VERSION,
                'kind'=>$kind,'checks'=>DevelopmentRoadmapReview::CHECKS,'source'=>'saved_reader_and_distinct_completed_review']),'deps'=>$deps,'priority'=>50]])[0];
    }
    public function roadmap_admit(int $admin,string $connection,array $args): array
    {
        self::keys($args,['queue_id','expected_archive_sha256','expected_plan_sha256','expected_caller_connection_id',
            'implementation_sha256','financial_installation','financial_input','review']);
        $id=self::id($args['queue_id']??null);self::actor($admin,$connection);
        foreach(['expected_archive_sha256','expected_plan_sha256','implementation_sha256']as$key)self::sha($args[$key]??null);
        if($id!==DevelopmentWorkServerContract::QUEUE||($args['expected_caller_connection_id']??null)!==$connection
            ||!is_array($args['financial_installation']??null)||!is_array($args['financial_input']??null)||!is_array($args['review']??null))
            throw new InvalidArgumentException('development_roadmap_manifest');
        $request=self::hash($args);
        return $this->transaction(function()use($id,$admin,$connection,$args,$request):array{
            $lane=$this->lane();if(!$this->roadmapTablesReady())throw new DomainException('development_roadmap_schema');
            $q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.$this->lock(),[$id]);
            if(!$q||(int)$q['admin_id']!==$admin||$q['project_key']!==self::PROJECT)throw new DomainException('development_work_access_denied');
            $this->roadmapAuthority($q,$connection);$old=$this->roadmapPhase($id);
            if($old){
                if(!hash_equals($old['execution_connection_id'],$connection)||$old['request_sha256']!==$request)throw new DevelopmentWorkQueueConflict('roadmap_admission_conflict');
                return $this->roadmapProof($q,$old)+['roadmap_admission_status'=>'already_recorded_do_not_replay'];
            }
            $archive=$this->roadmapArchiveCandidate($id,$admin,$connection,true);
            if(!$archive||$archive['roadmap_preparation']['archive_sha256']!==$args['expected_archive_sha256']||$q['plan_sha256']!==$args['expected_plan_sha256'])
                throw new DevelopmentWorkQueueConflict('roadmap_archive_cas');
            if($lane['queue_id']!==null||$this->one('SELECT queue_id FROM development_work_items WHERE state IN (?,?) OR claim_sha256 IS NOT NULL OR current_run_id IS NOT NULL LIMIT 1',['claimed','unknown']))
                throw new DevelopmentWorkQueueConflict('development_work_unresolved');
            if(self::hash($this->roadmapImplementation())!==$args['implementation_sha256'])throw new DevelopmentWorkQueueConflict('roadmap_implementation_cas');
            $at=$this->timestamp();$install=$args['financial_installation'];
            self::keys($install,['application_sha','original_deploy_run_id','verified_at_unix','technical_terminal_at_unix','receipt_sha256']);
            if(preg_match('/\\A[a-f0-9]{40}\\z/D',$install['application_sha']??'')!==1
                ||!is_int($install['original_deploy_run_id']??null)||$install['original_deploy_run_id']<1
                ||!is_int($install['verified_at_unix']??null)||$install['verified_at_unix']<946684800
                ||!is_int($install['technical_terminal_at_unix']??null)||$install['technical_terminal_at_unix']<$install['verified_at_unix']
                ||$install['technical_terminal_at_unix']>$at)throw new InvalidArgumentException('roadmap_installation');
            self::sha($install['receipt_sha256']??null);$base=$args['financial_input'];
            if(array_key_exists('verification_not_before_unix',$base))throw new InvalidArgumentException('roadmap_backend_activation_clock');
            $capsule=DevelopmentRoadmapSavedResult::capsule($base+['verification_not_before_unix'=>$at]);
            if($capsule['api_observed_not_before_unix']!==$install['verified_at_unix'])throw new InvalidArgumentException('roadmap_install_cutoff');
            $manifest=['schema'=>DevelopmentRoadmapReview::VERSION,'queue_id'=>$id,'archive_sha256'=>$args['expected_archive_sha256'],
                'implementation_sha256'=>$args['implementation_sha256'],'financial_installation'=>$install,'financial_input'=>$base,
                'policy'=>DevelopmentRoadmapReview::policy(),'external_release_evidence'=>'independently_checked_owner_attestation_not_server_workflow_proof'];
            $review=$args['review'];self::keys($review,['manifest_sha256','decision_sha256','readback_sha256']);foreach($review as$v)self::sha($v);
            if(($review['manifest_sha256']??null)!==self::hash($manifest))throw new DevelopmentWorkQueueConflict('roadmap_review_manifest');
            $mh=self::hash($manifest);$binding=['schema'=>'development-roadmap-binding-v1','manifest_sha256'=>$mh,'review'=>$review,
                'execution_connection_id'=>$connection,'admin_id'=>$admin,'admitted_at'=>$at,'deadline_at'=>$at+3600,
                'effective_capsule'=>$capsule,'old_control_calls'=>4,'new_review_limit'=>2,'old_unknown_reconciled'=>false];
            $bh=self::hash($binding);$task='DEV-ROADMAP-'.substr($mh,0,24).'-NEW';
            $input=['kind'=>'saved_finance_new_receipts','manifest_sha256'=>$mh,'binding_sha256'=>$bh,'capsule'=>$capsule];
            $spec=$this->roadmapSpec($task,$input['kind'],$input,[]);$plan=self::hash(['schema'=>'development-roadmap-append-v1','previous_plan'=>$q['plan_sha256'],'manifest'=>$mh,'tasks'=>[$spec]]);
            $this->roadmapBlob('INSERT INTO development_work_roadmap_phases(queue_id,execution_connection_id,request_sha256,archive_sha256,manifest_sha256,manifest_bytes,binding_sha256,binding_bytes,implementation_sha256,state,admitted_at,deadline_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
                [$id,$connection,$request,$args['expected_archive_sha256'],$mh,self::canonical($manifest),$bh,self::canonical($binding),$args['implementation_sha256'],'ready',$at,$at+3600],[5,7]);
            $this->roadmapAppend($q,$plan,$spec);
            $this->roadmapBlob('INSERT INTO development_work_roadmap_steps(queue_id,task_id,ordinal,kind,input_sha256,input_bytes,state) VALUES(?,?,?,?,?,?,?)',[$id,$task,1,$input['kind'],self::hash($input),self::canonical($input),'ready'],[5]);
            $fresh=$this->selectNext($this->one('SELECT * FROM development_work_queues WHERE queue_id=?',[$id]),$lane,$this->now());
            $this->roadmapAuthority($fresh,$connection);
            return $this->roadmapProof($fresh,$this->roadmapPhase($id))+['roadmap_admission_status'=>'admitted_no_send'];
        });
    }
    private function roadmapAppend(array $q,string $plan,array $spec): void
    {
        $this->insertTasks($q['queue_id'],$plan,[$spec],(int)$q['task_count'],$this->now());
        $this->execute('INSERT INTO development_work_appends(queue_id,plan_sha256,expected_plan_sha256,tasks_sha256,task_count,created_at) VALUES(?,?,?,?,?,?)',
            [$q['queue_id'],$plan,$q['plan_sha256'],self::hash([$spec]),1,$this->now()]);
        $this->execute('UPDATE development_work_queues SET plan_sha256=?,task_count=task_count+1,updated_at=? WHERE queue_id=?',[$plan,$this->now(),$q['queue_id']]);
    }

    /** Worst complete next proof, before reserving any model call. Equal wire bytes have an explicit alias. */
    private function roadmapReviewFits(array $q,array $p,array $step,string $wire): bool
    {
        $size=strlen(self::canonical($this->roadmapProof($q,$p)));
        $b64=static fn(int $n):int=>4*intdiv($n+2,3);
        $extra=$b64(strlen($wire))+$b64(DevelopmentRoadmapReview::MAX_REQUEST)+$b64(DevelopmentRoadmapReview::MAX_RESPONSE)+$b64(DevelopmentRoadmapReview::MAX_REPORT)+8192;
        if((int)$step['ordinal']===1){
            $input=$this->roadmapInput($p,$step);
            // Exact future B input has the same capsule and fixed-length parent identifiers/digests.
            $next=['kind'=>'saved_finance_processing','manifest_sha256'=>$p['manifest_sha256'],'binding_sha256'=>$p['binding_sha256'],
                'capsule'=>$input['capsule'],'parent_task_id'=>$step['task_id'],'parent_receipt_sha256'=>str_repeat('0',64),
                'parent_result_sha256'=>$step['candidate_sha256']];
            $extra+=$b64(strlen(self::canonical($next)))+2048;
        }
        return $size+$extra<=DevelopmentRoadmapReview::MAX_PROOF;
    }
    private function roadmapOut(string $state,string $reason,int $calls=0): array
    {return ['handled'=>true,'execution_kind'=>'server_saved_finance','state'=>$state,'reason'=>$reason,'provider_calls'=>$calls];}
    private function roadmapHold(array $q,string $reason,bool $unknown): array
    {
        if($unknown&&$q['current_task_id']!==null){
            $item=$this->current($q);
            if(!$this->one('SELECT task_id FROM development_work_receipts WHERE queue_id=? AND task_id=?',[$q['queue_id'],$item['task_id']])){
                $r=['run_id'=>$item['current_run_id'],'outcome'=>'unknown','result_sha256'=>null,'readback_sha256'=>null,
                    'acceptance_sha256'=>null,'reviewer_run_id'=>null,'owned_claims_released'=>false];$bytes=self::canonical($r);
                $this->execute('INSERT INTO development_work_receipts(queue_id,task_id,spec_sha256,receipt_sha256,receipt_json,created_at) VALUES(?,?,?,?,?,?)',
                    [$q['queue_id'],$item['task_id'],$item['spec_sha256'],hash('sha256',$bytes),$bytes,$this->now()]);
                $this->execute('UPDATE development_work_queues SET last_receipt_task_id=?,state=? WHERE queue_id=?',[$item['task_id'],'unknown',$q['queue_id']]);
                $this->execute('UPDATE development_work_items SET state=? WHERE queue_id=? AND task_id=?',['unknown',$q['queue_id'],$item['task_id']]);
            }
        }
        $this->execute('UPDATE development_work_roadmap_phases SET state=?,last_reason=? WHERE queue_id=?',[$unknown?'unknown':'hold',$reason,$q['queue_id']]);
        return $this->roadmapOut($unknown?'unknown':'hold',$reason);
    }
    /** Only the installed ordinary CLI supplies origin; durable reservation precedes I/O. */
    public function roadmapTick(array $origin,?callable $sender=null): array
    {
        if(!$this->roadmapTablesReady())return ['handled'=>false,'provider_calls'=>0];
        $job=$this->transaction(function()use($origin,$sender):array{
            $lane=$this->lane();$id=DevelopmentWorkServerContract::QUEUE;$p=$this->roadmapPhase($id);
            if(!$p)return ['handled'=>false,'provider_calls'=>0];
            $q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.$this->lock(),[$id]);
            if(($origin['trigger']??null)!=='cron'||preg_match('/\\A[a-f0-9]{64}\\z/D',$origin['cron_id']??'')!==1
                ||!is_int($origin['started_at']??null))return $this->roadmapOut('hold','ordinary_cron_required');
            if(in_array($p['state'],['complete','unknown','hold','blocked'],true))return $this->roadmapOut($p['state'],$p['last_reason']??$p['state']);
            try{$this->roadmapBinding($q,$p);}catch(Throwable $error){return $this->roadmapHold($q,'authority_or_policy_changed',false);}
            $now=$this->timestamp();$slot=intdiv($now,60);
            if($origin['started_at']>$now||$now-$origin['started_at']>30)return $this->roadmapOut('hold','stale_cron');
            if($p['last_slot']!==null&&$slot<=(int)$p['last_slot'])return $this->roadmapOut($p['state'],'same_or_older_minute');
            $this->ownsLane($q,$lane);$item=$this->current($q);
            $step=$this->one('SELECT * FROM development_work_roadmap_steps WHERE queue_id=? AND task_id=?'.$this->lock(),[$id,$item['task_id']]);
            if(!$step)return $this->roadmapHold($q,'unsupported_current_task',false);
            if(in_array($step['state'],['reading','reviewing'],true))return $this->roadmapHold($q,'original_operation_unknown_no_replay',true);
            if($now>=(int)$p['deadline_at'])return $this->roadmapHold($q,'finite_deadline_reached',false);
            $this->roadmapInput($p,$step);
            if($step['created_cron_id']===$origin['cron_id'])return $this->roadmapOut('ready','later_cron_required');
            if($step['state']==='candidate'){
                if($step['producer_cron_id']===$origin['cron_id']||$now<=(int)$step['producer_at'])return $this->roadmapOut('candidate','distinct_cron_required');
                if((int)$p['review_calls']>=2)return $this->roadmapHold($q,'review_budget_exhausted',false);
                if($sender===null&&!ManagerDraftClient::developmentTransportAvailable())return $this->roadmapHold($q,'review_transport_unavailable',false);
                $snapshot=json_decode($step['snapshot_bytes'],true,32,JSON_THROW_ON_ERROR);
                $candidate=$this->roadmapResult($q,$p,$step,$snapshot,(int)$step['snapshot_at']);
                if($candidate['state']!=='candidate'||self::hash($candidate)!==$step['candidate_sha256']
                    ||hash('sha256',$step['candidate_bytes'])!==$step['candidate_sha256'])return $this->roadmapHold($q,'saved_candidate_changed',true);
                try{$wire=DevelopmentRoadmapReview::prepare($this->roadmapPayload($q,$p,$step,$snapshot,$candidate));}
                catch(DomainException|InvalidArgumentException|JsonException $error){return $this->roadmapHold($q,'review_request_budget_before_send',false);}
                if(!$this->roadmapReviewFits($q,$p,$step,$wire))return $this->roadmapHold($q,'full_readback_budget_before_send',false);
                $attempt=bin2hex(random_bytes(32));
                $this->roadmapBlob('INSERT INTO development_work_roadmap_reviews(attempt_id,queue_id,task_id,cron_id,cron_slot,started_at,state,request_sha256,request_bytes) VALUES(?,?,?,?,?,?,?,?,?)',
                    [$attempt,$id,$step['task_id'],$origin['cron_id'],$slot,$now,'unknown',hash('sha256',$wire),$wire],[8]);
                $this->execute('UPDATE development_work_roadmap_steps SET state=? WHERE queue_id=? AND task_id=?',['reviewing',$id,$step['task_id']]);
                $this->execute('UPDATE development_work_roadmap_phases SET state=?,last_slot=?,review_calls=review_calls+1,reserved_tokens=reserved_tokens+16384,reserved_micros=reserved_micros+1000000 WHERE queue_id=?',['reviewing',$slot,$id]);
                return ['operation'=>'review','attempt_id'=>$attempt,'queue'=>$q,'phase'=>$p,'step'=>$step,'wire'=>$wire];
            }
            if($step['state']!=='ready'||$q['state']!=='ready'||$item['claim_sha256']!==null||$item['current_run_id']!==null)
                return $this->roadmapHold($q,'unresolved_claim_no_replay',true);
            if((int)$p['read_count']>=60)return $this->roadmapHold($q,'read_budget_exhausted',false);
            $read=bin2hex(random_bytes(32));$run='roadmap_read_'.$read;
            $this->execute('INSERT INTO development_work_roadmap_reads(read_id,queue_id,task_id,cron_id,cron_slot,run_id,started_at,state) VALUES(?,?,?,?,?,?,?,?)',
                [$read,$id,$step['task_id'],$origin['cron_id'],$slot,$run,$now,'unknown']);
            $this->execute('UPDATE development_work_items SET state=?,claim_sha256=?,current_run_id=?,updated_at=? WHERE queue_id=? AND task_id=?',
                ['claimed',hash('sha256',random_bytes(32)),$run,$this->now(),$id,$step['task_id']]);
            $this->execute('UPDATE development_work_queues SET state=? WHERE queue_id=?',['claimed',$id]);
            $this->execute('UPDATE development_work_roadmap_steps SET state=? WHERE queue_id=? AND task_id=?',['reading',$id,$step['task_id']]);
            $this->execute('UPDATE development_work_roadmap_phases SET state=?,last_slot=?,read_count=read_count+1 WHERE queue_id=?',['reading',$slot,$id]);
            return ['operation'=>'read','read_id'=>$read,'run_id'=>$run,'queue'=>$q,'phase'=>$p,'step'=>$step,'at'=>$now,'cron_id'=>$origin['cron_id']];
        });
        if(!isset($job['operation']))return $job;
        if($job['operation']==='read'){
            try{$snapshot=DevelopmentRoadmapSavedResult::read($this->pdo,function()use($job):void{$this->roadmapAuthority($job['queue'],$job['phase']['execution_connection_id']);},$job['at'],
                function()use($job):void{(new DevelopmentWorkConnectionAuthority($this->pdo,$this->clock))->assertCurrentSnapshot(
                    (int)$job['queue']['admin_id'],$job['phase']['execution_connection_id']);},$this->roadmapInput($job['phase'],$job['step'])['capsule']);}
            catch(Throwable $error){return $this->transaction(function():array{$this->lane();return $this->roadmapHold($this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.$this->lock(),[DevelopmentWorkServerContract::QUEUE]),'original_read_unknown',true);});}
            return $this->roadmapSaveRead($job,$snapshot);
        }
        return $this->roadmapSaveReview($job,DevelopmentRoadmapReview::send($job['wire'],$sender));
    }
    private function roadmapFinish(array $q,array $receipt,array $lane): array
    {
        $item=$this->current($q);self::receipt($receipt);$bytes=self::canonical($receipt);
        $this->execute('INSERT INTO development_work_receipts(queue_id,task_id,spec_sha256,receipt_sha256,receipt_json,created_at) VALUES(?,?,?,?,?,?)',
            [$q['queue_id'],$item['task_id'],$item['spec_sha256'],hash('sha256',$bytes),$bytes,$this->now()]);
        $this->execute('UPDATE development_work_queues SET last_receipt_task_id=? WHERE queue_id=?',[$item['task_id'],$q['queue_id']]);
        return $this->finish($q,$item,$receipt,$lane,$this->now());
    }
    private function roadmapSaveRead(array $job,array $snapshot): array
    {
        return $this->transaction(function()use($job,$snapshot):array{
            $lane=$this->lane();$q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.$this->lock(),[$job['queue']['queue_id']]);$p=$this->roadmapPhase($q['queue_id']);
            $step=$this->one('SELECT * FROM development_work_roadmap_steps WHERE queue_id=? AND task_id=?'.$this->lock(),[$q['queue_id'],$job['step']['task_id']]);
            $read=$this->one('SELECT * FROM development_work_roadmap_reads WHERE read_id=?'.$this->lock(),[$job['read_id']]);
            if(!$read||$read['state']!=='unknown'||$q['current_task_id']!==$step['task_id']||$this->current($q)['current_run_id']!==$job['run_id'])
                return $this->roadmapHold($q,'original_read_state_changed',true);
            $bytes=self::canonical($snapshot);
            $this->roadmapBlob('UPDATE development_work_roadmap_reads SET snapshot_bytes=? WHERE read_id=?',[$bytes,$job['read_id']],[0]);
            try{$result=$this->roadmapResult($q,$p,$step,$snapshot,$job['at']);}
            catch(Throwable $error){return $this->roadmapHold($q,'original_saved_evidence_unverifiable',true);}
            $rb=self::canonical($result);
            $this->roadmapBlob('UPDATE development_work_roadmap_reads SET state=?,result_sha256=?,result_bytes=?,snapshot_bytes=? WHERE read_id=?',
                ['completed',hash('sha256',$rb),$rb,$bytes,$job['read_id']],[2,3]);
            if($step['state']!=='reading'||$p['state']!=='reading')return $this->roadmapHold($q,'original_read_state_changed',true);
            try{$this->roadmapBinding($q,$p);}catch(Throwable $error){return $this->roadmapHold($q,'authority_or_policy_changed_after_read',true);}
            if($this->timestamp()>=(int)$p['deadline_at'])return $this->roadmapHold($q,'finite_deadline_after_read',true);
            if($result['state']==='waiting_evidence'){
                $this->execute('UPDATE development_work_items SET state=?,claim_sha256=NULL,current_run_id=NULL,updated_at=? WHERE queue_id=? AND task_id=?',['ready',$this->now(),$q['queue_id'],$step['task_id']]);
                $this->execute('UPDATE development_work_queues SET state=? WHERE queue_id=?',['ready',$q['queue_id']]);
                $this->execute('UPDATE development_work_roadmap_steps SET state=? WHERE queue_id=? AND task_id=?',['ready',$q['queue_id'],$step['task_id']]);
                $this->execute('UPDATE development_work_roadmap_phases SET state=?,last_reason=? WHERE queue_id=?',['ready',$result['reason'],$q['queue_id']]);
                return $this->roadmapOut('waiting_evidence',$result['reason']);
            }
            $this->roadmapBlob('UPDATE development_work_roadmap_steps SET state=?,producer_run_id=?,producer_cron_id=?,producer_at=?,candidate_sha256=?,candidate_bytes=?,snapshot_sha256=?,snapshot_bytes=?,snapshot_at=? WHERE queue_id=? AND task_id=?',
                [$result['state']==='candidate'?'candidate':'blocked',$job['run_id'],$job['cron_id'],$job['at'],hash('sha256',$rb),$rb,hash('sha256',$bytes),$bytes,$job['at'],$q['queue_id'],$step['task_id']],[5,7]);
            if($result['state']==='blocked'){
                $this->roadmapFinish($q,['run_id'=>$job['run_id'],'outcome'=>'terminal_blocked','result_sha256'=>hash('sha256',$rb),
                    'readback_sha256'=>hash('sha256',$bytes),'acceptance_sha256'=>null,'reviewer_run_id'=>null,'owned_claims_released'=>true],$lane);
                $this->execute('UPDATE development_work_roadmap_phases SET state=?,last_reason=? WHERE queue_id=?',['blocked',$result['reason'],$q['queue_id']]);return $this->roadmapOut('blocked',$result['reason']);
            }
            $this->execute('UPDATE development_work_roadmap_phases SET state=?,last_reason=NULL WHERE queue_id=?',['candidate',$q['queue_id']]);return $this->roadmapOut('candidate','distinct_later_review_required');
        });
    }
    private function roadmapMaterializeSecond(array $q,array $p,array $first,string $receiptHash,string $cron): void
    {
        if((int)$first['ordinal']!==1||$this->one('SELECT task_id FROM development_work_roadmap_steps WHERE queue_id=? AND ordinal=2',[$q['queue_id']]))
            throw new DomainException('development_roadmap_second_exists');
        $input=$this->roadmapInput($p,$first);$next=['kind'=>'saved_finance_processing','manifest_sha256'=>$p['manifest_sha256'],
            'binding_sha256'=>$p['binding_sha256'],'capsule'=>$input['capsule'],'parent_task_id'=>$first['task_id'],
            'parent_receipt_sha256'=>$receiptHash,'parent_result_sha256'=>$first['candidate_sha256']];
        $task='DEV-ROADMAP-'.substr($p['manifest_sha256'],0,24).'-PROCESS';$spec=$this->roadmapSpec($task,$next['kind'],$next,[$first['task_id']]);
        $plan=self::hash(['schema'=>'development-roadmap-append-v1','previous_plan'=>$q['plan_sha256'],'manifest'=>$p['manifest_sha256'],'tasks'=>[$spec]]);
        $this->roadmapAppend($q,$plan,$spec);
        $this->roadmapBlob('INSERT INTO development_work_roadmap_steps(queue_id,task_id,ordinal,kind,input_sha256,input_bytes,created_cron_id,state) VALUES(?,?,?,?,?,?,?,?)',
            [$q['queue_id'],$task,2,$next['kind'],self::hash($next),self::canonical($next),$cron,'ready'],[5]);
    }
    private function roadmapSaveReview(array $job,array $value): array
    {
        return $this->transaction(function()use($job,$value):array{
            $lane=$this->lane();$id=$job['queue']['queue_id'];$q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.$this->lock(),[$id]);$p=$this->roadmapPhase($id);
            $step=$this->one('SELECT * FROM development_work_roadmap_steps WHERE queue_id=? AND task_id=?'.$this->lock(),[$id,$job['step']['task_id']]);
            $review=$this->one('SELECT * FROM development_work_roadmap_reviews WHERE attempt_id=?'.$this->lock(),[$job['attempt_id']]);
            if(!$review||$review['state']!=='unknown'||$review['response_bytes']!==null||$q['current_task_id']!==$step['task_id'])
                return $this->roadmapHold($q,'original_review_state_changed',true);
            $t=is_array($value['transport']??null)?$value['transport']:[];$raw=$t['wire_response_bytes']??null;$actual=$t['wire_request_bytes']??null;
            if(!is_string($raw))$raw=null;if(!is_string($actual))$actual=null;
            $complete=($t['wire_complete']??null)===true&&is_string($raw)&&is_string($actual)&&strlen($raw)<=16384&&strlen($actual)<=32768;
            if($raw!==null)$raw=substr($raw,0,16384);if($actual!==null)$actual=substr($actual,0,32768);
            $error=$value['report']['_error']??null;
            $safe=['http_status'=>is_int($t['http_status']??null)&&$t['http_status']>=0&&$t['http_status']<=599?$t['http_status']:null,
                'wire_complete'=>($t['wire_complete']??null)===true,'capture_complete'=>$complete,
                'response_capture'=>in_array($t['response_capture']??null,['complete','incomplete_transport','prefix_truncated_at_budget'],true)?$t['response_capture']:null,
                'capture_semantics'=>$complete?'original_complete':'bounded_prefix_or_unavailable',
                'error_category'=>in_array($error,['provider_timeout','provider_unavailable','provider_not_configured','provider_metadata_unavailable',
                    'provider_response_invalid','provider_response_too_large','transport_exception'],true)?$error:($error===null?null:'unclassified_provider_error')];
            $this->roadmapBlob('UPDATE development_work_roadmap_reviews SET response_sha256=?,response_bytes=?,actual_request_bytes=?,metadata_json=? WHERE attempt_id=?',
                [$raw===null?null:hash('sha256',$raw),$raw,$actual,self::canonical($safe),$review['attempt_id']],[1,2]);
            if($step['state']!=='reviewing'||$p['state']!=='reviewing'){
                $out=$this->roadmapHold($q,'original_review_state_changed',true);$out['provider_calls']=($safe['http_status']??0)>0?1:null;return $out;
            }
            $this->pdo->exec('SAVEPOINT development_roadmap_credit');
            try{
                $this->roadmapBinding($q,$p);if($this->timestamp()>=(int)$p['deadline_at'])throw new DomainException('deadline');
                $snapshot=json_decode($step['snapshot_bytes'],true,32,JSON_THROW_ON_ERROR);$candidate=$this->roadmapResult($q,$p,$step,$snapshot,(int)$step['snapshot_at']);
                if(self::hash($candidate)!==$step['candidate_sha256']||$this->current($q)['current_run_id']!==$step['producer_run_id']
                    ||$review['request_bytes']!==$job['wire']||$review['cron_id']===$step['producer_cron_id']
                    ||(int)$review['cron_slot']<=intdiv((int)$step['producer_at'],60))throw new DomainException('candidate');
                $v=DevelopmentRoadmapReview::verify($review['request_bytes'],$value,$candidate,false);
                if($this->one('SELECT attempt_id FROM development_work_roadmap_reviews WHERE response_id=?',[$v['response_id']]))throw new DomainException('duplicate_response');
                $this->roadmapBlob('UPDATE development_work_roadmap_reviews SET state=?,response_id=?,report_sha256=?,report_bytes=?,metadata_json=? WHERE attempt_id=?',
                    [$v['approved']?'succeeded':'rejected',$v['response_id'],$v['report_sha256'],self::canonical($v['report']),self::canonical($safe+$v['metadata']),$review['attempt_id']],[3]);
                $readback=$this->roadmapReadback($step,$p,$v);
                if(!$v['approved']){
                    $this->execute('UPDATE development_work_roadmap_steps SET state=? WHERE queue_id=? AND task_id=?',['blocked',$id,$step['task_id']]);
                    $this->roadmapFinish($q,['run_id'=>$step['producer_run_id'],'outcome'=>'terminal_blocked',
                        'result_sha256'=>$step['candidate_sha256'],'readback_sha256'=>$readback,'acceptance_sha256'=>null,
                        'reviewer_run_id'=>null,'owned_claims_released'=>true],$lane);
                    $this->execute('UPDATE development_work_roadmap_phases SET state=?,last_reason=? WHERE queue_id=?',['blocked','independent_review_rejected',$id]);
                    $this->pdo->exec('RELEASE SAVEPOINT development_roadmap_credit');return $this->roadmapOut('blocked','independent_review_rejected',1);
                }
                $receipt=['run_id'=>$step['producer_run_id'],'outcome'=>'accepted_readback','result_sha256'=>$step['candidate_sha256'],
                    'readback_sha256'=>$readback,'acceptance_sha256'=>self::hash([$p['manifest_sha256'],$step['task_id'],$readback]),
                    'reviewer_run_id'=>$v['response_id'],'owned_claims_released'=>true];
                if((int)$step['ordinal']===1){
                    $this->roadmapMaterializeSecond($q,$p,$step,self::hash($receipt),$review['cron_id']);$q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?',[$id]);
                }
                $this->execute('UPDATE development_work_roadmap_steps SET state=? WHERE queue_id=? AND task_id=?',['accepted',$id,$step['task_id']]);
                $fresh=$this->roadmapFinish($q,$receipt,$lane);
                $this->execute('UPDATE development_work_roadmap_phases SET state=?,last_reason=? WHERE queue_id=?',[(int)$step['ordinal']===2?'complete':'ready','accepted_original_saved_result',$id]);
                $this->roadmapProof($fresh,$this->roadmapPhase($id));$this->pdo->exec('RELEASE SAVEPOINT development_roadmap_credit');
                return $this->roadmapOut((int)$step['ordinal']===2?'complete':'ready','accepted_original_saved_result',1);
            }catch(Throwable $error){
                $this->pdo->exec('ROLLBACK TO SAVEPOINT development_roadmap_credit');$this->pdo->exec('RELEASE SAVEPOINT development_roadmap_credit');
                $out=$this->roadmapHold($q,'original_review_not_accepted_no_replay',true);$out['provider_calls']=($safe['http_status']??0)>0?1:null;return $out;
            }
        });
    }
}
