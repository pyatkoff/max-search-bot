<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionStatusBudget.php';
require_once __DIR__.'/ModeledConversionValueContract.php';
require_once __DIR__.'/ModeledConversionTickFailure.php';
require_once __DIR__.'/ModeledConversionProfiles.php';
if(is_file(__DIR__.'/ModeledConversionValueIdentityEvidence.php'))require_once __DIR__.'/ModeledConversionValueIdentityEvidence.php';
if(is_file(__DIR__.'/ModeledConversionValueVisitEvidence.php'))require_once __DIR__.'/ModeledConversionValueVisitEvidence.php';
if(is_file(__DIR__.'/ModeledConversionValueVisitRecovery.php'))require_once __DIR__.'/ModeledConversionValueVisitRecovery.php';

/** Fixed derived-value aggregates only. No source tables, network, processing or mutation. */
final class ModeledConversionValueDeliveryReader
{
    public const GOAL='678187097';
    public const ROW_LIMIT=201;
    private const STATES=['pending','ready','uploading','accepted','processed','unknown','failed','expired','unmatched'];
    private const UPLOADS=['PREPARED','UPLOADED','EXPORTED','MATCHED','PROCESSED','LINKAGE_FAILURE'];
    private const ERRORS=['identity_not_found','identity_ambiguous','metrika_visit_window','metrika_visit_unverified','wire_key_collision','scope_unavailable','api_rejected','api_row_rejected',
        'upload_outcome_unknown','event_expired','transport_not_sent','rate_limited'];
    private array $boundFiniteRows=[];
    public function __construct(private PDO $pdo,private $authorize,private ModeledConversionStatusBudget $budget){}
    public function read(int $now):array
    {
        try{
            $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $len=$mysql?'OCTET_LENGTH(last_receipt_json)':'LENGTH(CAST(last_receipt_json AS BLOB))';
            $rows=$this->query("SELECT mode,goal_id,goal_intent,activated_at_unix,last_tick_unix,contract_version,history_from_unix,history_until_unix,history_cursor,history_ceiling,history_fortnight_requested_at_unix,history_day_receipt_json,history_completed_at_unix,history_scanned,history_collected,history_stats_json,CASE WHEN ".$len."<=8192 THEN last_receipt_json ELSE NULL END AS receipt_json,last_report_success_unix,CASE WHEN ".($mysql?'OCTET_LENGTH(last_report_success_json)':'LENGTH(CAST(last_report_success_json AS BLOB))')."<=8192 THEN last_report_success_json ELSE NULL END AS last_success_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour' AND counter_id='98615635' LIMIT 1",[]);
            if(!$rows)return $this->unavailable('not_configured');
            $s=$rows[0];
            if(($s['goal_id']??null)!==self::GOAL||($s['contract_version']??null)!==ModeledConversionValueContract::VERSION
                ||!in_array($s['mode']??null,['off','send'],true)||($s['goal_intent']??null)!=='verified')return $this->unavailable('configuration_unverified');
            $activation=(int)($s['activated_at_unix']??0);if($activation<946684800||$activation>$now)return $this->unavailable('activation_unavailable');
            $columns=["COALESCE(SUM(CASE WHEN value_origin IN ('owner_day_review_v1','owner_fortnight_review_v1') THEN 1 ELSE 0 END),0) AS historical_events",'COUNT(*) AS inspected','COALESCE(SUM(value_rub),0) AS reserved_rub',
                "COALESCE(SUM(CASE WHEN upload_id IS NOT NULL AND upload_id<>'' THEN 1 ELSE 0 END),0) AS accepted_receipts",
                "COALESCE(SUM(CASE WHEN upload_id IS NOT NULL AND upload_id<>'' THEN value_rub ELSE 0 END),0) AS accepted_receipts_rub"];
            $columns[]='COUNT(DISTINCT conversation_id) AS conversations';
            foreach(['with_identity'=>"client_id IS NOT NULL AND client_id<>''",'without_identity'=>"(client_id IS NULL OR client_id='')"]as$key=>$condition){
                $columns[]="COALESCE(SUM(CASE WHEN ".$condition." THEN 1 ELSE 0 END),0) AS ".$key."_events";
                $columns[]="COALESCE(SUM(CASE WHEN ".$condition." THEN value_rub ELSE 0 END),0) AS ".$key."_rub";
                $columns[]="COUNT(DISTINCT CASE WHEN ".$condition." THEN conversation_id ELSE NULL END) AS ".$key."_conversations";
            }
            foreach(['max','telegram']as$platform){
                $columns[]="COALESCE(SUM(CASE WHEN platform='".$platform."' THEN 1 ELSE 0 END),0) AS ".$platform."_events";
                $columns[]="COALESCE(SUM(CASE WHEN platform='".$platform."' THEN value_rub ELSE 0 END),0) AS ".$platform."_rub";
                $columns[]="COUNT(DISTINCT CASE WHEN platform='".$platform."' THEN conversation_id ELSE NULL END) AS ".$platform."_conversations";
            }
            foreach(self::STATES as$i=>$state){
                $columns[]="COALESCE(SUM(CASE WHEN state='".$state."' THEN 1 ELSE 0 END),0) AS state_n".$i;
                $columns[]="COALESCE(SUM(CASE WHEN state='".$state."' THEN value_rub ELSE 0 END),0) AS state_v".$i;
            }
            foreach(ModeledConversionValueContract::STAGES as$i=>$stage){
                $columns[]="COALESCE(SUM(CASE WHEN value_stage='".$stage."' THEN 1 ELSE 0 END),0) AS stage_n".$i;
                $columns[]="COALESCE(SUM(CASE WHEN value_stage='".$stage."' THEN value_rub ELSE 0 END),0) AS stage_v".$i;
            }
            foreach(self::UPLOADS as$i=>$status)$columns[]="COALESCE(SUM(CASE WHEN upload_status='".$status."' THEN 1 ELSE 0 END),0) AS upload_n".$i;
            foreach(self::ERRORS as$i=>$error)$columns[]="COALESCE(SUM(CASE WHEN last_error='".$error."' THEN 1 ELSE 0 END),0) AS error_n".$i;
            $sql='SELECT '.implode(',',$columns)." FROM (SELECT state,value_stage,value_rub,upload_id,upload_status,last_error,value_origin,platform,conversation_id,client_id FROM max_channel_conversion_outbox WHERE project_key='anytour' AND counter_id='98615635' AND transport_target=? AND value_contract=? AND (event_unix>=? OR (value_origin IN ('owner_day_review_v1','owner_fortnight_review_v1') AND value_reviewed_at_unix IS NOT NULL AND event_unix>=1793570400 AND event_unix<1793656800)) ORDER BY id DESC LIMIT ".self::ROW_LIMIT.') bounded_values';
            $r=$this->query($sql,[ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,$activation])[0];
            $seen=$this->number($r['inspected'],false);
            if($seen>self::ROW_LIMIT)return $this->unavailable('invalid_ledger');
            $out=['available'=>true,'goal_id'=>self::GOAL,'counter_id'=>'98615635','target'=>ModeledConversionValueContract::TARGET,
                'contract'=>ModeledConversionValueContract::VERSION,'currency'=>'RUB','mode'=>$s['mode'],
                'activated_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$activation),'last_tick_at'=>null,'last_tick_errors'=>null,'last_recorded_failure'=>null,
                'window'=>['basis'=>'latest_live_and_owner_authorized_historical_goal_rows','max_rows'=>self::ROW_LIMIT,'inspected_rows'=>$seen,
                    'complete_within_bound'=>$seen<self::ROW_LIMIT],
                'reserved_value_rub'=>$this->number($r['reserved_rub'],true),
                'api_accepted_receipts'=>['events'=>$this->number($r['accepted_receipts'],false),'value_rub'=>$this->number($r['accepted_receipts_rub'],true)],
                'by_state'=>[],'by_stage'=>[],'by_upload_status'=>[],'error_counts'=>[]];
            foreach(self::STATES as$i=>$state)$out['by_state'][$state]=['events'=>$this->number($r['state_n'.$i],false),'value_rub'=>$this->number($r['state_v'.$i],true)];
            foreach(ModeledConversionValueContract::STAGES as$i=>$stage)$out['by_stage'][$stage]=['events'=>$this->number($r['stage_n'.$i],false),'value_rub'=>$this->number($r['stage_v'.$i],true)];
            foreach(self::UPLOADS as$i=>$status)$out['by_upload_status'][$status]=$this->number($r['upload_n'.$i],false);
            foreach(self::ERRORS as$i=>$error)$out['error_counts'][$error]=$this->number($r['error_n'.$i],false);
            if(array_sum(array_column($out['by_state'],'events'))!==$seen
                ||array_sum(array_column($out['by_stage'],'events'))!==$seen)return $this->unavailable('invalid_ledger');
            foreach(['with_identity','without_identity']as$key)$out['identity_resolution'][$key]=[
                'events'=>$this->number($r[$key.'_events'],false),'conversations'=>$this->number($r[$key.'_conversations'],false),'value_rub'=>$this->number($r[$key.'_rub'],true)];
            $out['conversations_in_ledger_window']=$this->number($r['conversations'],false);
            foreach(['max','telegram']as$platform)$out['by_platform'][$platform]=[
                'events'=>$this->number($r[$platform.'_events'],false),'conversations'=>$this->number($r[$platform.'_conversations'],false),'value_rub'=>$this->number($r[$platform.'_rub'],true)];
            $receipt=null;
            if(is_string($s['receipt_json']??null)){try{$receipt=json_decode($s['receipt_json'],true,32,JSON_THROW_ON_ERROR);}catch(JsonException $ignored){}}
            if(is_array($receipt)&&is_int($receipt['errors']??null)&&$receipt['errors']>=0&&$receipt['errors']<=100)$out['last_tick_errors']=$receipt['errors'];
            $tick=(int)($s['last_tick_unix']??0);if($tick>=$activation&&$tick<=$now)$out['last_tick_at']=gmdate('Y-m-d\\TH:i:s\\Z',$tick);
            if($out['last_tick_at']!==null){
                $failure=ModeledConversionTickFailure::normalize($receipt['last_recorded_failure']??null,$activation,$tick);
                if($failure!==null)$out['last_recorded_failure']=['tick_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$failure['tick_at_unix']),
                    'phase'=>$failure['phase'],'category'=>$failure['category']];
            }
            $out['metrika_goal_report']=$this->report($receipt['metrika_report']??null,$activation,$now);
            if(!in_array($out['metrika_goal_report']['status']??null,['ok','sampled'],true)){
                $lastGood=is_string($s['last_success_json']??null)?json_decode($s['last_success_json'],true):null;
                $out['last_successful_metrika_goal_report']=$this->report($lastGood,$activation,$now);
            }
            $out['historical_review']=['date'=>'2026-11-02','date_from'=>(int)($s['history_from_unix']??0)===1792447200?'2026-10-20':'2026-11-02','date_until_inclusive'=>'2026-11-02','timezone'=>'Europe/Kaliningrad',
                'configured'=>ModeledConversionValueContract::historyWindow((int)($s['history_from_unix']??0),(int)($s['history_until_unix']??0)),
                'fortnight_requested'=>is_numeric($s['history_fortnight_requested_at_unix']??null),
                'completed'=>is_numeric($s['history_completed_at_unix']??null)&&(int)$s['history_completed_at_unix']<=$now,
                'profiles_scanned'=>is_numeric($s['history_scanned']??null)?$this->number($s['history_scanned'],false):null,
                'events_collected'=>is_numeric($s['history_collected']??null)?$this->number($s['history_collected'],false):null,
                'historical_events_in_ledger_window'=>$this->number($r['historical_events'],false),
                'not_prospective_forecasts'=>true];
            $historyReasons=is_string($s['history_stats_json']??null)?json_decode($s['history_stats_json'],true):null;
            $out['historical_review']['reason_counts']=[];
            foreach(['customer_text_profiles','eligible_signal_profiles','invalid_packet','no_day_signal','binding_or_scope_held','already_reserved']as$key){
                $v=is_array($historyReasons)?($historyReasons[$key]??null):null;
                $out['historical_review']['reason_counts'][$key]=is_int($v)&&$v>=0&&$v<=1000000000?$v:null;
            }
            if($out['historical_review']['configured']){
                $out['historical_review']['saved_cohort']=$this->cohort($s);
                $first=is_string($s['history_day_receipt_json']??null)?json_decode($s['history_day_receipt_json'],true):null;
                if(is_array($first)&&($first['history_from_unix']??null)==1793570400&&($first['history_until_unix']??null)==1793656800
                    &&is_numeric($first['history_completed_at_unix']??null)&&(int)$first['history_completed_at_unix']<=$now)
                    $out['historical_review']['first_day_receipt']=['date'=>'2026-11-02','completed'=>true,
                        'profiles_scanned'=>$this->number($first['history_scanned'],false),'events_collected'=>$this->number($first['history_collected'],false)];
            }
            $out['interpretation']=['values'=>'owner_assigned_advertising_estimates_not_actual_income',
                'last_recorded_failure_is_historical'=>true,
                'states_are_disjoint'=>true,'accepted_receipts_include_later_rejected_or_unmatched_rows'=>true,
                'processed_means_validated_upload_rows_not_verified_visit_linkage'=>true,
                'matched_upload_status_means_user_ids_matched_not_per_upload_visit_proof'=>true,
                'goal_report_is_by_session_dates_including_pre_activation_attribution_window'=>true,
                'identity_counts_are_stored_resolution_not_a_new_scope_check'=>true,
                'conversations_are_not_verified_people_or_trips'=>true,'per_upload_visit_match_verified'=>false,'reporting_can_lag'=>true,'no_ids_or_text_returned'=>true];
            $out['price_continuity_audit']=$this->priceAudit($receipt['price_continuity_audit']??null,$now);
            $out['repair_assessments']=$this->repairAssessments($now,$receipt['identity_resolution_evidence']??null,$receipt['finite_visit_evidence']??null,$receipt['finite_visit_recovery']??null);
            $out['predictive_v1']=$this->predictiveV1($now);
            return $out;
        }catch(ModeledConversionStatusLimit $e){throw $e;}
        catch(PDOException $ignored){return $this->unavailable('storage_unavailable');}
        catch(UnexpectedValueException $ignored){return $this->unavailable('invalid_ledger');}
    }



    /** All saved current-namespace repairs and their own new increments; no reclassification or provider call. */
    public function repairAssessments(int $now,mixed $identityAudit=null,mixed $visitFrame=null,mixed $recoveryJournal=null):array
    {
        $this->budget->check();
        if($this->budget->remainingBytes()<16384)return ['available'=>false,'reason'=>'budget_headroom'];
        if(!is_callable([ModeledConversionProfiles::class,'assessmentNamespace']))return ['available'=>false,'reason'=>'namespace_unavailable'];
        try{$namespace=ModeledConversionProfiles::assessmentNamespace();}
        catch(RuntimeException $ignored){return ['available'=>false,'reason'=>'namespace_unavailable'];}
        if(preg_match('/\A[a-f0-9]{64}\z/D',$namespace)!==1)return ['available'=>false,'reason'=>'namespace_unavailable'];
        try{
            $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $equal=static fn(string $a,string $b):string=>$mysql?'BINARY '.$a.'=BINARY '.$b:'('.$a.') COLLATE BINARY=('.$b.') COLLATE BINARY';
            $columns=['COUNT(DISTINCT p.id) AS profiles',
                'COUNT(DISTINCT CASE WHEN o.id IS NOT NULL THEN p.id ELSE NULL END) AS increment_profiles',
                'COUNT(o.id) AS increment_events','COALESCE(SUM(o.value_rub),0) AS increment_rub',
                "COALESCE(SUM(CASE WHEN o.upload_id IS NOT NULL AND o.upload_id<>'' THEN 1 ELSE 0 END),0) AS api_events",
                "COALESCE(SUM(CASE WHEN o.upload_id IS NOT NULL AND o.upload_id<>'' THEN o.value_rub ELSE 0 END),0) AS api_rub",
                "COALESCE(SUM(CASE WHEN o.state='processed' AND o.upload_status='PROCESSED' THEN 1 ELSE 0 END),0) AS processed_events",
                "COALESCE(SUM(CASE WHEN o.state='processed' AND o.upload_status='PROCESSED' THEN o.value_rub ELSE 0 END),0) AS processed_rub",
                'MIN(p.observed_at_unix) AS first_at','MAX(p.observed_at_unix) AS last_at'];
            foreach(self::STATES as$i=>$state){
                $columns[]="COALESCE(SUM(CASE WHEN o.state='".$state."' THEN 1 ELSE 0 END),0) AS state_n".$i;
                $columns[]="COALESCE(SUM(CASE WHEN o.state='".$state."' THEN o.value_rub ELSE 0 END),0) AS state_v".$i;
            }
            foreach(['max','telegram']as$platform){
                $columns[]="COUNT(DISTINCT CASE WHEN p.platform='".$platform."' THEN p.id ELSE NULL END) AS ".$platform."_profiles";
                $columns[]="COALESCE(SUM(CASE WHEN o.platform='".$platform."' THEN 1 ELSE 0 END),0) AS ".$platform."_events";
                $columns[]="COALESCE(SUM(CASE WHEN o.platform='".$platform."' THEN o.value_rub ELSE 0 END),0) AS ".$platform."_rub";
            }
            $key=$mysql?"CONCAT('anytour_value_pred_',p.id,'_v1')":"'anytour_value_pred_'||p.id||'_v1'";
            $sql='SELECT '.implode(',',$columns)." FROM modeled_conversion_profile_versions p"
                ." LEFT JOIN max_channel_conversion_outbox o ON o.value_profile_id=p.id"
                ." AND o.project_key=p.project_key AND o.conversation_id=p.conversation_id AND o.target=".$key
                .' AND '.$equal('o.project_key','p.project_key').' AND '.$equal('o.platform','p.platform')
                .' AND '.$equal('o.conversation_id','p.conversation_id')." AND o.counter_id='98615635'"
                .' AND '.$equal('o.transport_target','?').' AND '.$equal('o.value_contract','?')
                .' AND '.$equal('o.target',$key).' AND o.value_rub>0 AND o.value_rub<='.ModeledConversionValueContract::MAX_VALUE_RUB
                ." WHERE p.project_key='anytour' AND p.assessment_namespace=? AND p.assessment_kind='repair'"
                ." AND p.platform IN ('max','telegram')";
            $r=$this->query($sql,[ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,$namespace])[0];
            // Whole-cohort scalar bounds are independent of the older 201-row delivery suffix.
            $number=static function(mixed $v,bool $money):int|float{
                if(!is_int($v)&&!is_float($v)&&!(is_string($v)&&preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/D',$v)===1))throw new UnexpectedValueException();
                $n=(float)$v;if(!is_finite($n)||$n<0||$n>($money?1000000000000:1000000000)||(!$money&&$n!==floor($n)))throw new UnexpectedValueException();
                return $money?round($n,2):(int)$n;
            };
            $profiles=$number($r['profiles'],false);$withIncrement=$number($r['increment_profiles'],false);$events=$number($r['increment_events'],false);
            if($withIncrement>$profiles||$events!==$withIncrement)throw new UnexpectedValueException();
            $at=static fn(mixed $v):?string=>is_numeric($v)&&(int)$v>=946684800&&(int)$v<=$now?gmdate('Y-m-d\TH:i:s\Z',(int)$v):null;
            $out=['available'=>true,'basis'=>'saved_current_namespace_repair_metadata_and_own_new_increment_rows',
                'assessment_namespace'=>$namespace,
                'coverage'=>['whole_current_namespace_repair_cohort'=>true,'snapshot_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),
                    'first_assessed_at'=>$at($r['first_at']),'last_assessed_at'=>$at($r['last_at'])],
                'reassessed_profiles'=>$profiles,'profiles_without_new_increment'=>$profiles-$withIncrement,
                'new_increment_events'=>$events,'new_increment_value_rub'=>$number($r['increment_rub'],true),
                'api_receipts'=>['events'=>$number($r['api_events'],false),'value_rub'=>$number($r['api_rub'],true)],
                'processed_upload_rows'=>['events'=>$number($r['processed_events'],false),'value_rub'=>$number($r['processed_rub'],true)],
                'by_state'=>[],'by_platform'=>[],
                'interpretation'=>['no_new_increment_is_not_evidence_of_loss'=>true,'api_receipts_include_later_rejected_rows'=>true,
                    'processed_rows_do_not_prove_visit_linkage'=>true,'saved_metadata_not_new_model_validation'=>true],
                'per_event_visit_linkage'=>'not_checked','provider_requests'=>0];
            foreach(self::STATES as$i=>$state)$out['by_state'][$state]=[
                'events'=>$number($r['state_n'.$i],false),'value_rub'=>$number($r['state_v'.$i],true)];
            if(array_sum(array_column($out['by_state'],'events'))!==$events)throw new UnexpectedValueException();
            foreach(['max','telegram']as$platform)$out['by_platform'][$platform]=[
                'reassessed_profiles'=>$number($r[$platform.'_profiles'],false),
                'events'=>$number($r[$platform.'_events'],false),'value_rub'=>$number($r[$platform.'_rub'],true)];
            $out['finite_visit_evidence']=$this->finiteVisitEvidence($visitFrame,$namespace,$now);
            $out['finite_visit_recovery']=$this->finiteVisitRecovery($recoveryJournal,$visitFrame,$out['finite_visit_evidence'],$namespace,$now);
            $out['retained_native_identity_evidence']=$this->retainedNativeIdentity($out['finite_visit_evidence'],$namespace,$now);
            $out['exact_cases']=$this->repairCases($namespace,$now,$identityAudit);
            $this->budget->check();($this->authorize)();$this->budget->check();return $out;
        }catch(ModeledConversionStatusLimit $e){throw $e;}
        catch(PDOException $ignored){return ['available'=>false,'reason'=>'storage_unavailable'];}
        catch(UnexpectedValueException $ignored){return ['available'=>false,'reason'=>'invalid_saved_projection'];}
    }




    /** A separate forward observation may only refer to the already bound original three-case proof. */
    private function finiteVisitRecovery(mixed $journal,mixed $original,array $boundOriginal,string $namespace,int $now):array
    {
        $fail=static fn(string $reason):array=>['available'=>false,'reason'=>$reason,'provider_requests_in_reader'=>0];
        if(($boundOriginal['available']??false)!==true)return $fail('original_current_binding_unavailable');
        if(!class_exists(ModeledConversionValueVisitRecovery::class))return $fail('recovery_helper_unavailable');
        try{
            $out=ModeledConversionValueVisitRecovery::projection($journal,$original,$namespace,$now);
            if(($out['available']??false)!==true)return $out;
            $old=[];foreach($boundOriginal['rows']??[]as$row)$old[$row['event_ref']]=$row;
            if(count($old)!==3||count($out['rows']??[])!==2)return $fail('original_or_recovery_binding_unavailable');
            foreach($out['rows']as&$row){
                $prior=$old[$row['event_ref']]??null;
                if($prior===null||($row['candidate_sha256']??null)!==($prior['candidate_sha256']??null)
                    ||($prior['report_status']??null)!=='unavailable')return $fail('original_or_recovery_binding_unavailable');
                foreach(['event_at','source_provenance','source_observed_at','financial_state','first_ready_at',
                    'api_accepted_at','processed_at','api_receipt_ref']as$key)$row[$key]=$prior[$key]??null;
                $row['original_window_reason']=$prior['window_reason']??null;
            }unset($row);
            $out['eligible_original_cases']=2;$out['excluded_original_cases']=count($old)-2;
            return $out;
        }catch(UnexpectedValueException|JsonException $ignored){return $fail('original_or_recovery_binding_unavailable');}
    }

    /** Existing local native receipts are candidate evidence only; no provider call or identity assignment. */
    private function retainedNativeIdentity(array $original,string $namespace,int $now):array
    {
        $base=['available'=>false,'basis'=>'bounded_local_native_processed_receipt_metadata',
            'scope'=>'same_project_platform_external_user_and_counter_across_conversations',
            'provider_requests_in_reader'=>0,'financial_permission'=>false,'visit_linkage'=>'not_checked',
            'acknowledgement_time'=>'not_persisted','max_rows'=>64,'complete_within_bound'=>false];
        $fail=static fn(string $reason):array=>array_merge($base,['reason'=>$reason]);
        if(($original['available']??false)!==true)return $fail('original_current_binding_unavailable');
        $missing=[];
        foreach($original['rows']??[]as$public)if(($public['source_status']??null)==='no_valid_canonical_identity_and_no_admissible_retained_identity'
            &&($public['candidate_sha256']??null)===null&&($public['report_status']??null)==='not_requested')$missing[]=$public['event_ref'];
        if(count($missing)!==1)return $fail('single_missing_original_identity_not_verified');
        $ref=$missing[0];$bound=$this->boundFiniteRows[$ref]??null;
        if(!is_array($bound)||($bound['project_key']??null)!=='anytour'||($bound['platform']??null)!=='max'
            ||(string)($bound['counter_id']??'')!=='98615635')return $fail('original_scope_unavailable');
        $digits=static function(mixed $value,int $length):?string{
            if(is_int($value)&&$value>0)$value=(string)$value;
            return is_string($value)&&preg_match('/\A[1-9][0-9]{0,'.($length-1).'}\z/D',$value)===1?$value:null;
        };
        $user=$digits($bound['external_user_id']??null,20);
        if($user===null)return $fail('original_scope_unavailable');
        $this->budget->check();
        if($this->budget->remainingBytes()<16384)return $fail('budget_headroom');
        try{
            $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            if($mysql){
                $index=$this->query("SELECT COLUMN_NAME AS column_name,SEQ_IN_INDEX AS column_position,SUB_PART AS prefix_length"
                    ." FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='max_channel_conversion_outbox'"
                    ." AND INDEX_NAME='ix_max_identity_point' ORDER BY SEQ_IN_INDEX LIMIT 6",[],null,250);
            }else{
                $index=$this->query("SELECT name AS column_name,seqno+1 AS column_position,NULL AS prefix_length"
                    ." FROM pragma_index_info('ix_max_identity_point') ORDER BY seqno LIMIT 6",[],null,250);
                $definition=$this->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='max_channel_conversion_outbox'"
                    ." AND name='ix_max_identity_point' LIMIT 1",[],null,250);
                if(count($definition)!==1||!is_string($definition[0]['sql']??null)
                    ||preg_match('/\bWHERE\b/i',$definition[0]['sql'])===1)return $fail('point_index_unavailable');
            }
            $expected=['project_key','platform','external_user_id','counter_id','id'];
            if(count($index)!==count($expected))return $fail('point_index_unavailable');
            foreach($index as$i=>$part)if(($part['column_name']??null)!==$expected[$i]
                ||(string)($part['column_position']??'')!==(string)($i+1)||($part['prefix_length']??null)!==null)
                return $fail('point_index_unavailable');
            $names=['id','project_key','platform','external_user_id','counter_id','conversation_id','target','transport_target',
                'identity_source_key','source_signal_id','identity_observation_id','value_profile_id','value_rub','value_stage','value_contract',
                'state','upload_status','upload_id','last_error','attempts','claimed_at_unix','client_id','tracker_record_id','tracker_observed_unix',
                'value_receipt_basis','value_ready_at_unix','value_api_accepted_at_unix','value_processed_at_unix'];
            $point='SELECT id AS point_order,JSON_ARRAY('.implode(',',$names).') AS packet FROM max_channel_conversion_outbox '
                .($mysql?'FORCE INDEX (ix_max_identity_point)':'INDEXED BY ix_max_identity_point')
                .' WHERE project_key=? AND platform=? AND external_user_id=? AND counter_id=?'
                .' AND HEX(project_key)=HEX(?) AND HEX(platform)=HEX(?) AND HEX(external_user_id)=HEX(?) AND HEX(counter_id)=HEX(?)'
                .' ORDER BY id LIMIT 65 OFFSET 0';
            $packetBytes=$mysql?'OCTET_LENGTH(JSON_QUOTE(CAST(packet AS CHAR)))':'LENGTH(CAST(JSON_QUOTE(CAST(packet AS TEXT)) AS BLOB))';
            $scope=['anytour','max',$user,'98615635'];
            $packets=$this->query('SELECT CASE WHEN SUM('.$packetBytes.'+24) OVER ()+2<=? THEN packet ELSE NULL END AS packet'
                .' FROM ('.$point.') native_identity_point_packets ORDER BY point_order',array_merge($scope,$scope),8192,250);
            if($packets&&$packets[0]['packet']===null)return $fail('budget_headroom');
            if(count($packets)>64)return $fail('row_limit');
            $pairs=[
                'max_bot_activity_start'=>['max_channel_activity_start','max_bot_activity_start'],
                'max_manager_reply'=>['max_channel_manager_reply'],
                'max_manager_request'=>['max_manager_request'],
                'max_customer_reply_after_manager'=>['max_channel_customer_reply_after_manager','max_customer_reply_after_manager']];
            $candidates=[];$admitted=0;
            foreach($packets as$packet){
                $values=json_decode($packet['packet'],true,8,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
                if(!is_array($values)||count($values)!==count($names))throw new UnexpectedValueException();
                $row=array_combine($names,$values);
                if($row['project_key']!=='anytour'||$row['platform']!=='max'||(string)$row['external_user_id']!==$user
                    ||(string)$row['counter_id']!=='98615635')throw new UnexpectedValueException();
                if(!is_string($row['target'])||!in_array($row['transport_target'],$pairs[$row['target']]??[],true)
                    ||$row['identity_source_key']!==''||$row['state']!=='processed'||$row['upload_status']!=='PROCESSED'||$row['last_error']!=='')continue;
                $native=true;
                foreach(['source_signal_id','identity_observation_id','value_profile_id','value_rub','value_stage','value_contract',
                    'value_receipt_basis','value_ready_at_unix','value_api_accepted_at_unix','value_processed_at_unix']as$name)
                    if($row[$name]!==null)$native=false;
                $id=$digits($row['id'],20);$conversation=$digits($row['conversation_id'],20);$upload=$digits($row['upload_id'],20);
                $client=$digits($row['client_id'],40);$record=$digits($row['tracker_record_id'],20);
                $attempts=$digits($row['attempts'],10);$claim=$digits($row['claimed_at_unix'],10);$source=$digits($row['tracker_observed_unix'],10);
                if(!$native||$id===null||$conversation===null||$upload===null||$client===null||$record===null||$attempts===null
                    ||$claim===null||(int)$claim<946684800||(int)$claim>$now||$source===null||(int)$source<946684800
                    ||(int)$source>(int)$bound['event_unix']||(int)$source>(int)$claim)continue;
                $candidate=hash('sha256',json_encode(['retained_native_processed_tracker_source_v1',$namespace,$ref,$id,
                    $row['target'],$row['transport_target'],$upload,$client,$record,(int)$source,'anytour','max',$user,'98615635'],
                    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
                $admitted++;
                // Rows are in canonical id order; duplicate witnesses for the same ClientID do not imply ambiguity.
                $candidates[$client]??=['candidate_sha256'=>$candidate,'source_observed_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$source)];
            }
            $out=array_merge($base,['available'=>true,'event_ref'=>$ref,'snapshot_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),
                'complete_within_bound'=>true,'inspected_rows'=>count($packets),'admitted_native_witnesses'=>$admitted,
                'unique_candidate_count'=>count($candidates),'candidate_sha256'=>null,'source_observed_at'=>null,
                'source_provenance'=>null,'local_absence_does_not_prove_tracker_or_visit_absence'=>true]);
            if(count($candidates)===1)$out=array_merge($out,reset($candidates),['status'=>'candidate_observed',
                'source_provenance'=>'retained_native_processed_tracker_source']);
            else$out['status']=$candidates?'ambiguous_native_candidates':'no_admissible_native_witness';
            $this->budget->check();($this->authorize)();$this->budget->check();return $out;
        }catch(ModeledConversionStatusLimit $error){throw $error;}
        catch(PDOException $ignored){return $fail('point_storage_unavailable');}
        catch(UnexpectedValueException|JsonException $ignored){return $fail('invalid_point_projection');}
    }

    /** Saved finite GET proof, bound again to the exact three original ledger rows. Never calls a provider. */
    private function finiteVisitEvidence(mixed $frame,string $namespace,int $now):array
    {
        $this->boundFiniteRows=[];
        if(!class_exists(ModeledConversionValueVisitEvidence::class))return ['available'=>false,'reason'=>'evidence_helper_unavailable','provider_requests_in_reader'=>0];
        try{$projection=ModeledConversionValueVisitEvidence::projection($frame,$namespace,$now);}
        catch(UnexpectedValueException|JsonException $ignored){return ['available'=>false,'reason'=>'invalid_saved_frame','provider_requests_in_reader'=>0];}
        if(($projection['available']??false)!==true)return $projection;
        $this->budget->check();
        if($this->budget->remainingBytes()<16384)return ['available'=>false,'reason'=>'budget_headroom','provider_requests_in_reader'=>0];
        try{
            $checkpoint=ModeledConversionValueVisitEvidence::CHECKPOINT;
            $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $eq=static fn(string $a,string $b):string=>$mysql?'BINARY '.$a.'=BINARY '.$b:'('.$a.') COLLATE BINARY=('.$b.') COLLATE BINARY';
            $key=$mysql?"CONCAT('anytour_value_pred_',p.id,'_v1')":"'anytour_value_pred_'||p.id||'_v1'";
            $names=['id','value_profile_id','project_key','platform','conversation_id','external_user_id','counter_id','target',
                'transport_target','value_contract','event_unix','value_rub','state','profile_saved_unix',
                'value_ready_at_unix','value_api_accepted_at_unix','value_processed_at_unix','upload_id'];
            $fields=array_map(static fn(string $name):string=>$name==='profile_saved_unix'?'p.observed_at_unix':'o.'.$name,$names);
            $sql='SELECT o.id AS case_order,JSON_ARRAY('.implode(',',$fields).') AS packet'
                ." FROM modeled_conversion_profile_versions p JOIN max_channel_conversion_outbox o ON o.value_profile_id=p.id"
                ." AND o.project_key=p.project_key AND o.conversation_id=p.conversation_id AND o.target=".$key
                .' AND '.$eq('o.project_key','p.project_key').' AND '.$eq('o.platform','p.platform')
                .' AND '.$eq('o.conversation_id','p.conversation_id')." AND o.counter_id='98615635'"
                .' AND '.$eq('o.transport_target','?').' AND '.$eq('o.value_contract','?').' AND '.$eq('o.target',$key)
                .' AND o.value_rub>0 AND o.value_rub<='.ModeledConversionValueContract::MAX_VALUE_RUB
                ." WHERE p.project_key='anytour' AND p.assessment_namespace=? AND p.assessment_kind='repair'"
                ." AND p.platform IN ('max','telegram') AND p.observed_at_unix<=? AND o.event_unix<=?"
                ." AND (o.value_ready_at_unix IS NULL OR o.value_ready_at_unix>?)"
                ." AND (o.value_api_accepted_at_unix IS NULL OR o.value_api_accepted_at_unix>?) ORDER BY o.id LIMIT 4 OFFSET 0";
            $packetBytes=$mysql?'OCTET_LENGTH(JSON_QUOTE(CAST(packet AS CHAR)))':'LENGTH(CAST(JSON_QUOTE(CAST(packet AS TEXT)) AS BLOB))';
            $packets=$this->query('SELECT CASE WHEN SUM('.$packetBytes.'+24) OVER ()+2<=? THEN packet ELSE NULL END AS packet'
                .' FROM ('.$sql.') finite_bound_packets ORDER BY case_order',
                [ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,$namespace,$checkpoint,$checkpoint,$checkpoint,$checkpoint],8192);
            if($packets&&$packets[0]['packet']===null)return ['available'=>false,'reason'=>'budget_headroom','provider_requests_in_reader'=>0];
            if(count($packets)!==3)return ['available'=>false,'reason'=>'current_cohort_cardinality_unverified','provider_requests_in_reader'=>0];
            $bound=[];
            foreach($packets as$packet){
                $values=json_decode($packet['packet'],true,8,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
                if(!is_array($values)||count($values)!==count($names))throw new UnexpectedValueException();
                $row=array_combine($names,$values);$ref=ModeledConversionValueIdentityEvidence::reference($row,$namespace);
                if($ref===null||isset($bound[$ref])||!in_array($row['state'],self::STATES,true))throw new UnexpectedValueException();
                $bound[$ref]=$row;
            }
            $raw=ModeledConversionValueVisitEvidence::decode($frame,$namespace,$now);
            if(!is_array($raw)||count($raw['r']??[])!==3||count($projection['rows']??[])!==3)throw new UnexpectedValueException();
            $seen=[];
            foreach($raw['r']as$tuple){
                $row=$bound[$tuple[0]]??null;
                if(isset($seen[$tuple[0]])||$row===null||(string)$row['id']!==(string)$tuple[1]||(string)$row['event_unix']!==(string)$tuple[15])
                    return ['available'=>false,'reason'=>'saved_frame_binding_mismatch','provider_requests_in_reader'=>0];
                $seen[$tuple[0]]=true;
            }
            $at=static fn(mixed $v):?string=>is_numeric($v)&&(int)$v>=946684800&&(int)$v<=$now?gmdate('Y-m-d\TH:i:s\Z',(int)$v):null;
            foreach($projection['rows']as&$public){
                $row=$bound[$public['event_ref']]??null;if($row===null)throw new UnexpectedValueException();
                $upload=$row['upload_id'];$receipt=is_string($upload)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$upload)===1;
                $public['financial_state']=$row['state'];$public['profile_saved_at']=$at($row['profile_saved_unix']);
                $public['first_ready_at']=$at($row['value_ready_at_unix']);$public['api_accepted_at']=$at($row['value_api_accepted_at_unix']);
                $public['processed_at']=$at($row['value_processed_at_unix']);
                $public['api_receipt_ref']=$receipt?hash('sha256','modeled_value_upload_receipt_v1|'.$upload):null;
            }unset($public);
            $projection['binding_basis']='three_current_immutable_repair_rows_born_before_checkpoint_with_no_prior_ready_or_api_receipt';
            $this->budget->check();($this->authorize)();$this->budget->check();$this->boundFiniteRows=$bound;return $projection;
        }catch(ModeledConversionStatusLimit $e){throw $e;}
        catch(PDOException $ignored){return ['available'=>false,'reason'=>'storage_unavailable','provider_requests_in_reader'=>0];}
        catch(UnexpectedValueException|JsonException $ignored){return ['available'=>false,'reason'=>'invalid_saved_projection','provider_requests_in_reader'=>0];}
    }

    /** Earliest exact repair events remain visible after resolution and upload; no source/provider reads. */
    private function repairCases(string $namespace,int $now,mixed $identityAudit):array
    {
        $this->budget->check();
        if($this->budget->remainingBytes()<16384)return ['available'=>false,'reason'=>'budget_headroom'];
        if(!class_exists(ModeledConversionValueIdentityEvidence::class))return ['available'=>false,'reason'=>'identity_evidence_helper_unavailable'];
        try{
            $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $equal=static fn(string $a,string $b):string=>$mysql?'BINARY '.$a.'=BINARY '.$b:'('.$a.') COLLATE BINARY=('.$b.') COLLATE BINARY';
            $key=$mysql?"CONCAT('anytour_value_pred_',p.id,'_v1')":"'anytour_value_pred_'||p.id||'_v1'";
            $fields=['o.id','o.value_profile_id','o.project_key','o.platform','o.conversation_id','o.external_user_id',
                'o.counter_id','o.target','o.transport_target','o.value_contract','o.event_unix','o.value_rub',
                'o.value_stage','o.state','o.last_error','o.value_ready_at_unix','o.value_api_accepted_at_unix',
                'o.value_processed_at_unix','o.upload_id','p.observed_at_unix'];
            $sql='SELECT o.id AS event_order,JSON_ARRAY('.implode(',',$fields).') AS packet'
                ." FROM modeled_conversion_profile_versions p JOIN max_channel_conversion_outbox o ON o.value_profile_id=p.id"
                ." AND o.project_key=p.project_key AND o.conversation_id=p.conversation_id AND o.target=".$key
                .' AND '.$equal('o.project_key','p.project_key').' AND '.$equal('o.platform','p.platform')
                .' AND '.$equal('o.conversation_id','p.conversation_id')." AND o.counter_id='98615635'"
                .' AND '.$equal('o.transport_target','?').' AND '.$equal('o.value_contract','?').' AND '.$equal('o.target',$key)
                .' AND o.value_rub>0 AND o.value_rub<='.ModeledConversionValueContract::MAX_VALUE_RUB
                ." WHERE p.project_key='anytour' AND p.assessment_namespace=? AND p.assessment_kind='repair'"
                ." AND p.platform IN ('max','telegram') ORDER BY o.id LIMIT 17 OFFSET 0";
            $packetBytes=$mysql?'OCTET_LENGTH(JSON_QUOTE(CAST(packet AS CHAR)))':'LENGTH(CAST(JSON_QUOTE(CAST(packet AS TEXT)) AS BLOB))';
            $rows=$this->query('SELECT CASE WHEN SUM('.$packetBytes.'+24) OVER ()+2<=? THEN packet ELSE NULL END AS packet'
                .' FROM ('.$sql.') exact_repair_packets ORDER BY event_order',
                [ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,$namespace],8192);
            if($rows&&$rows[0]['packet']===null)return ['available'=>false,'reason'=>'budget_headroom'];
            $audit=ModeledConversionValueIdentityEvidence::normalize($identityAudit,$namespace,$now);$probes=[];
            if($audit!==null)foreach($audit['rows']as$probe)$probes[$probe['event_ref']]=$probe;
            $out=['available'=>true,'basis'=>'earliest_saved_current_namespace_repair_increment_events_all_states',
                'window'=>['max_events'=>16,'inspected_events'=>count($rows),'returned_events'=>min(16,count($rows)),
                    'complete_within_bound'=>count($rows)<=16,'omitted_due_to_row_limit'=>count($rows)>16],
                'identity_evidence'=>['available'=>$audit!==null,'cohort_complete_within_bound'=>$audit['complete']??null,'observed_case_count'=>0,
                    'reason'=>$audit===null?'not_recorded_or_unavailable':null],
                'rows'=>[],'provider_requests'=>0];
            $names=['id','value_profile_id','project_key','platform','conversation_id','external_user_id','counter_id','target',
                'transport_target','value_contract','event_unix','value_rub','value_stage','state','last_error',
                'value_ready_at_unix','value_api_accepted_at_unix','value_processed_at_unix','upload_id','profile_saved_unix'];
            $at=static fn(mixed $v):?string=>is_numeric($v)&&(int)$v>=946684800&&(int)$v<=$now?gmdate('Y-m-d\TH:i:s\Z',(int)$v):null;
            foreach(array_slice($rows,0,16)as$packet){
                $values=json_decode($packet['packet'],true,8,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
                if(!is_array($values)||count($values)!==count($names))throw new UnexpectedValueException();
                $r=array_combine($names,$values);$ref=ModeledConversionValueIdentityEvidence::reference($r,$namespace);
                if($ref===null||!in_array($r['state'],self::STATES,true)||!in_array($r['value_stage'],ModeledConversionValueContract::STAGES,true))
                    throw new UnexpectedValueException();
                $probe=$probes[$ref]??null;if($probe!==null)$out['identity_evidence']['observed_case_count']++;
                $error=$r['last_error'];$emptyError=$error===null||$error==='';
                $knownError=is_string($error)&&in_array($error,[...self::ERRORS,'price_loss_reconciled'],true);
                $upload=$r['upload_id'];$receipt=is_string($upload)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$upload)===1;
                $out['rows'][]=['event_ref'=>$ref,'platform'=>$r['platform'],'increment_rub'=>$this->number($r['value_rub'],true),
                    'stage'=>$r['value_stage'],'state'=>$r['state'],
                    'last_error'=>$knownError?$error:null,'last_error_status'=>$emptyError?'none':($knownError?'known':'unrecognized'),
                    'profile_saved_at'=>$at($r['profile_saved_unix']),'event_at'=>$at($r['event_unix']),
                    'first_ready_at'=>$at($r['value_ready_at_unix']),'api_accepted_at'=>$at($r['value_api_accepted_at_unix']),
                    'processed_at'=>$at($r['value_processed_at_unix']),
                    'api_receipt_ref'=>$receipt?hash('sha256','modeled_value_upload_receipt_v1|'.$upload):null,
                    'api_receipt_status'=>$upload===null||$upload===''?'not_recorded':($receipt?'observed':'invalid_saved_reference'),
                    'identity_resolution'=>['status'=>$probe!==null?'observed':($audit===null?'audit_unavailable':'not_observed'),
                        'reason'=>$probe['reason']??null,'checked_at'=>$probe!==null?$at($probe['checked_at_unix']):null,
                        'source_observed_at'=>$probe!==null?$at($probe['source_observed_unix']):null]];
            }
            $this->budget->check();($this->authorize)();$this->budget->check();return $out;
        }catch(ModeledConversionStatusLimit $e){throw $e;}
        catch(PDOException $ignored){return ['available'=>false,'reason'=>'storage_unavailable'];}
        catch(UnexpectedValueException|JsonException $ignored){return ['available'=>false,'reason'=>'invalid_saved_projection'];}
    }

    private function priceAudit(mixed $a,int $now):array
    {
        if(!is_array($a)||($a['version']??null)!=='retained_priced_base_v1')return ['available'=>false,'reason'=>'not_recorded'];
        $at=ModeledConversionValueContract::moment($a['checked_at']??null);
        if($at===null||$at>$now||!is_int($a['goal_rows_scanned']??null)||$a['goal_rows_scanned']<0||$a['goal_rows_scanned']>1001
            ||!is_bool($a['complete']??null)||!is_int($a['unprocessed_candidates']??null)||$a['unprocessed_candidates']<0||$a['unprocessed_candidates']>1001
            ||!is_array($a['actions']??null)||!is_array($a['corrections']??null)||count($a['corrections'])>16)return ['available'=>false,'reason'=>'invalid_recorded_audit'];
        $out=['available'=>true,'version'=>$a['version'],'checked_at'=>$a['checked_at'],'goal_rows_scanned'=>$a['goal_rows_scanned'],
            'complete'=>$a['complete'],'unprocessed_candidates'=>$a['unprocessed_candidates'],'actions'=>[],'corrections'=>[]];
        $actions=['unchanged','rebased','voided','reduced','review_required','unverified'];
        foreach($a['actions']as$key=>$v){
            if(!in_array($key,$actions,true)||!is_array($v)||!is_int($v['events']??null)||$v['events']<0||$v['events']>1001
                ||!is_int($v['removed_rub']??null)||$v['removed_rub']<0||$v['removed_rub']>1001*ModeledConversionValueContract::MAX_VALUE_RUB)return ['available'=>false,'reason'=>'invalid_recorded_audit'];
            $out['actions'][$key]=['events'=>$v['events'],'removed_rub'=>$v['removed_rub']];
        }
        foreach($a['corrections']as$v){
            if(!is_array($v)||!in_array($v['action']??null,['voided','reduced','review_required'],true))return ['available'=>false,'reason'=>'invalid_recorded_audit'];
            foreach(['original_rub','applied_rub','prior_reserved_rub']as$key)if(!is_int($v[$key]??null)||$v[$key]<0||$v[$key]>ModeledConversionValueContract::MAX_VALUE_RUB)return ['available'=>false,'reason'=>'invalid_recorded_audit'];
            $t=ModeledConversionValueContract::moment($v['assessed_at']??null);if($t===null||$t>$now)return ['available'=>false,'reason'=>'invalid_recorded_audit'];
            $out['corrections'][]=['action'=>$v['action'],'original_rub'=>$v['original_rub'],'applied_rub'=>$v['applied_rub'],
                'prior_reserved_rub'=>$v['prior_reserved_rub'],'assessed_at'=>$v['assessed_at']];
        }
        return $out;
    }

    /** Frozen scalar arithmetic and saved receipts for predictive keys only; no source text or provider request. */
    public function predictiveV1(int $now):array
    {
        $this->budget->check();
        if($this->budget->remainingBytes()<16384)return ['available'=>false,'reason'=>'budget_headroom'];
        try{
            $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $safe="CASE WHEN JSON_VALID(p.profile_json) THEN p.profile_json ELSE '{}' END";
            $scalar=static function(string $path)use($safe,$mysql):string{
                $v="JSON_EXTRACT(".$safe.",'$.".$path."')";
                // Preserve JSON null versus the string "null" in the four nullable numeric fields.
                $typed=in_array($path,['semantic_prediction.estimated_probability','deal_value_prediction.deal_amount_rub.point',
                    'deal_value_prediction.commission_estimate_rub','deal_value_prediction.value_rub'],true);
                return $mysql&&!$typed?'JSON_UNQUOTE('.$v.')':$v;
            };
            $columns=['o.id','o.conversation_id','o.value_profile_id','o.target','o.platform','o.value_stage','o.value_rub',
                'o.state','o.upload_status','o.event_unix','o.claimed_at_unix','o.value_api_accepted_at_unix','o.value_processed_at_unix',
                "CASE WHEN o.upload_id IS NOT NULL AND o.upload_id<>'' THEN 1 ELSE 0 END",
                "CASE WHEN o.client_id IS NOT NULL AND o.client_id<>'' THEN 1 ELSE 0 END",'p.observed_at_unix'];
            $fields=['access_status','subject.project_key','subject.platform','subject.conversation_id','subject.customer_key',
                'semantic_prediction.mode','semantic_prediction.status','semantic_prediction.stage','semantic_prediction.estimated_probability',
                'deal_value_prediction.version','deal_value_prediction.commission_rate','deal_value_prediction.deal_amount_rub.point',
                'deal_value_prediction.deal_amount_rub.low','deal_value_prediction.deal_amount_rub.high',
                'deal_value_prediction.deal_amount_rub.basis','deal_value_prediction.deal_amount_rub.source_kind',
                'deal_value_prediction.commission_estimate_rub','deal_value_prediction.full_value_base_rub',
                'deal_value_prediction.value_rub','semantic_prediction.hypothesis_odds_multiplier'];
            foreach($fields as$field)$columns[]=$field==='subject.customer_key'?'CASE WHEN '.$scalar($field).'=p.customer_key THEN '.$scalar($field).' ELSE NULL END':$scalar($field);
            $reasons="JSON_EXTRACT(".$safe.",'$.semantic_prediction.reasons')";
            $length=$mysql?'OCTET_LENGTH('.$reasons.')':'LENGTH(CAST('.$reasons.' AS BLOB))';
            $columns[]='CASE WHEN '.$length.'<=2048 THEN '.$reasons.' ELSE NULL END';
            $columns[]="(SELECT COALESCE(SUM(prior.value_rub),0) FROM max_channel_conversion_outbox prior"
                ." WHERE prior.project_key=o.project_key AND prior.conversation_id=o.conversation_id"
                ." AND prior.transport_target=o.transport_target AND prior.value_contract=o.value_contract AND prior.id<o.id)";
            $sql="SELECT o.id AS packet_id,JSON_ARRAY(".implode(',',$columns).") AS packet FROM"
                ." (SELECT * FROM max_channel_conversion_outbox WHERE project_key='anytour' AND counter_id='98615635'"
                ." AND transport_target=? AND value_contract=? ORDER BY id DESC LIMIT ".self::ROW_LIMIT.") o"
                ." LEFT JOIN modeled_conversion_profile_versions p ON p.id=o.value_profile_id AND p.project_key=o.project_key"
                ." AND p.platform=o.platform AND p.conversation_id=o.conversation_id"
                ." WHERE o.target LIKE 'anytour_value_pred_%' ORDER BY o.id DESC LIMIT 17 OFFSET 0";
            $packetBytes=$mysql?'OCTET_LENGTH(JSON_QUOTE(CAST(packet AS CHAR)))':'LENGTH(CAST(JSON_QUOTE(CAST(packet AS TEXT)) AS BLOB))';
            // LIMIT/OFFSET keeps the projection materialized once, including SQLite window rewriting.
            // Gate the whole escaped result inside SQL: an oversized set copies only bounded NULL sentinels.
            $rows=$this->query('SELECT CASE WHEN SUM('.$packetBytes.'+24) OVER ()+2<=? THEN packet ELSE NULL END AS packet'
                .' FROM ('.$sql.') bounded_packets ORDER BY packet_id DESC',
                [ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION],8192);
            if($rows&&$rows[0]['packet']===null)return ['available'=>false,'reason'=>'budget_headroom'];
            $out=['available'=>true,'basis'=>'saved_predictive_key_rows_and_frozen_scalar_calculations_not_reclassification',
                'window'=>['max_goal_rows'=>self::ROW_LIMIT,'max_predictive_rows'=>17,'inspected_predictive_rows'=>count($rows),
                    'complete_within_goal_suffix'=>count($rows)<17,'global_coverage_known'=>false],
                'events'=>0,'conversations'=>0,'value_rub'=>0,'api_accepted_events'=>0,'api_accepted_value_rub'=>0,
                'processed_events'=>0,'processed_value_rub'=>0,'by_state'=>[],'by_platform'=>[],
                'invalid_binding_rows'=>0,'voided_zero_value_rows'=>0,'calculation_unavailable_rows'=>0,'examples'=>[],
                'examples_selection'=>'comparison_differences_first_then_newest',
                'calculation_basis_counts'=>['probability_prediction'=>0,'stage_fraction'=>0,'unavailable'=>0],
                'stage_comparison_counts'=>['above_stage'=>0,'equal_stage'=>0,'below_stage'=>0,'stage_only'=>0,'unavailable'=>0],
                'calculation_unavailable_reason_counts'=>['profile_binding_unverified'=>0,'probability_prediction_unavailable'=>0,
                    'monetary_policy_unavailable'=>0,'scalar_contract_unverified'=>0,'price_base_unverified'=>0,
                    'frozen_value_unverified'=>0,'increment_not_explained'=>0],
                'per_event_visit_linkage'=>'not_observed_by_this_cached_upload_reader','provider_requests'=>0];
            $cases=[];$differenceExamples=[];$otherExamples=[];
            foreach($rows as$row){
                $v=json_decode($row['packet'],true,16,JSON_THROW_ON_ERROR);
                if(!is_array($v)||count($v)!==38)throw new UnexpectedValueException();
                if(preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$v[2])!==1||!is_string($v[3])||$v[3]!==ModeledConversionValueContract::predictionKey((string)$v[2])
                    ||!in_array($v[4],['max','telegram'],true)||!in_array($v[7],self::STATES,true)
                    ||!in_array($v[5],ModeledConversionValueContract::STAGES,true)){$out['invalid_binding_rows']++;continue;}
                $delta=(int)$this->number($v[6],true);
                if($delta===0&&$v[7]==='failed'){$out['voided_zero_value_rows']++;continue;}
                if($delta<=0||$delta>ModeledConversionValueContract::MAX_VALUE_RUB){$out['invalid_binding_rows']++;continue;}
                $scope=$v[4].'|'.$v[1];
                if(!isset($cases[$scope]))$cases[$scope]='dialogue_'.(count($cases)+1);
                $out['events']++;$out['value_rub']+=$delta;
                foreach(['by_state'=>$v[7],'by_platform'=>$v[4]]as$group=>$key){
                    if(!isset($out[$group][$key]))$out[$group][$key]=['events'=>0,'value_rub'=>0];
                    $out[$group][$key]['events']++;$out[$group][$key]['value_rub']+=$delta;
                }
                if((string)$v[13]==='1'){$out['api_accepted_events']++;$out['api_accepted_value_rub']+=$delta;}
                if($v[7]==='processed'){$out['processed_events']++;$out['processed_value_rub']+=$delta;}
                $at=static fn(mixed $x):?string=>is_numeric($x)&&(int)$x>=946684800&&(int)$x<=$now?gmdate('Y-m-d\TH:i:s\Z',(int)$x):null;
                $e=['case'=>$cases[$scope],'platform'=>$v[4],'stage'=>$v[5],'increment_rub'=>$delta,'state'=>$v[7],
                    'upload_status'=>in_array($v[8],self::UPLOADS,true)?$v[8]:null,
                    'api_receipt_present'=>(string)$v[13]==='1','identity_resolved'=>(string)$v[14]==='1',
                    'signal_at'=>$at($v[9]),'profile_saved_at'=>$at($v[15]),'upload_claim_at'=>$at($v[10]),
                    'api_accepted_observed_at'=>$at($v[11]),'processed_observed_at'=>$at($v[12]),
                    'calculation'=>null,'calculation_unavailable_reason'=>null,'visit_linkage'=>'unverified'];
                // These are fixed saved scalar fields, with independent arithmetic checks, not a signature attestation.
                $n=static fn(mixed $x):?float=>is_numeric($x)&&is_finite((float)$x)?(float)$x:null;
                $prob=$n($v[24]);$rate=$n($v[26]);$point=$n($v[27]);$base=$n($v[33]);$full=$n($v[34]);$prior=$n($v[37]);
                $commission=$n($v[32]);$kind=$v[31];
                $policy=is_string($v[25])?ModeledConversionDealValue::policy($v[25]):null;
                $sources=['unknown','customer_budget','delivered_offer','native_hotel_inquiry','channel_post_range'];
                $bases=['unknown','customer_budget_ceiling_proxy','customer_budget_floor_proxy','customer_budget_range_proxy',
                    'delivered_offer_observation','native_hotel_inquiry_observation','advertised_floor_proxy','advertised_range_proxy'];
                // Explain the first failed check only; this does not validate the remaining fields or infer a fallback.
                $calculationReason=null;
                if(!(preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$v[20])===1&&$v[16]==='allowed'&&$v[17]==='anytour'&&$v[18]===$v[4]&&(string)$v[19]===(string)$v[1]))
                    $calculationReason='profile_binding_unverified';
                elseif($v[21]!=='contextual_advertising_v1'||$v[22]!=='predicted')$calculationReason='probability_prediction_unavailable';
                elseif($policy===null)$calculationReason='monetary_policy_unavailable';
                elseif(!($rate===0.07&&$prob!==null&&$prob>0&&$prob<=1&&$base!==null&&$base>0&&$base<=$policy['cap_rub']
                    &&$full!==null&&$prior!==null&&$prior>=0&&in_array($kind,$sources,true)&&in_array($v[30],$bases,true)))
                    $calculationReason='scalar_contract_unverified';
                elseif(!($point===null?$commission===null&&$base===(float)$policy['unknown_base_rub']:
                    $point>=10000&&$point<=10000000&&$commission===round($point*0.07)&&$base===min((float)$policy['cap_rub'],$commission)))
                    $calculationReason='price_base_unverified';
                elseif($full!==round($prob*$base))$calculationReason='frozen_value_unverified';
                elseif($full-$prior!==(float)$delta)$calculationReason='increment_not_explained';
                // Only verify the existing non-payment stage formula; never infer a probability or a price-continuity adjustment.
                $stageValue=null;$stageRank=array_search($v[5],ModeledConversionValueContract::STAGES,true);
                if($calculationReason==='probability_prediction_unavailable'&&$v[21]==='contextual_advertising_v1'
                    &&$v[22]==='insufficient_context'&&in_array($stageRank,[1,4,5],true)&&$v[24]===null&&$v[34]===null
                    &&$policy!==null&&$rate===0.07&&$base!==null&&$base>0&&$base<=$policy['cap_rub']
                    &&$prior!==null&&$prior>=0&&$prior===floor($prior)&&$n($v[6])===(float)$delta
                    &&in_array($kind,$sources,true)&&in_array($v[30],$bases,true)
                    &&($v[27]===null?$v[32]===null&&$base===(float)$policy['unknown_base_rub']:
                        $point!==null&&$point>=10000&&$point<=10000000&&$point===floor($point)
                        &&$commission===round($point*0.07)&&$base===min((float)$policy['cap_rub'],$commission))){
                    $target=(int)round(ModeledConversionValueContract::VALUES[$stageRank]/ModeledConversionValueContract::BASE*$base);
                    if($target-$prior===(float)$delta)$stageValue=$target;
                }
                if($calculationReason===null){
                    $out['calculation_basis_counts']['probability_prediction']++;
                    $reasons=$v[36];if(is_string($reasons))$reasons=json_decode($reasons,true,8);
                    $allowed=['expert_dialogue_continued_meaningful_engagement','expert_dialogue_continued_offer_search',
                        'expert_dialogue_office_question','expert_dialogue_payment_terms_question','explicit_decline_guard'];
                    $factors=[];foreach(is_array($reasons)?$reasons:[]as$reason)if(is_string($reason)&&in_array($reason,$allowed,true))$factors[]=$reason;
                    $e['calculation']=['basis'=>'frozen_scalar_arithmetic_checked_not_new_model_validation',
                        'monetary_policy'=>$v[25],'value_cap_rub'=>$policy['cap_rub'],
                        'estimated_probability'=>$prob,'odds_multiplier'=>$n($v[35]),'expert_factors'=>$factors,
                        'estimated_deal_rub'=>$point===null?null:(int)$point,'deal_low_rub'=>$n($v[28]),'deal_high_rub'=>$n($v[29]),
                        'deal_source'=>$kind,'deal_basis'=>$v[30],'commission_rate'=>0.07,'estimated_commission_rub'=>$commission,
                        'full_value_base_rub'=>(int)$base,'estimated_cumulative_value_rub'=>(int)$full,
                        'prior_reserved_rub'=>(int)$prior];
                }elseif($stageValue!==null){
                    $out['calculation_basis_counts']['stage_fraction']++;
                    $e['calculation']=['basis'=>'stage_fraction_arithmetic_checked_not_probability_prediction',
                        'monetary_policy'=>$v[25],'value_cap_rub'=>$policy['cap_rub'],'estimated_probability'=>null,
                        'stage_fraction'=>['numerator'=>ModeledConversionValueContract::VALUES[$stageRank],'denominator'=>ModeledConversionValueContract::BASE],
                        'estimated_deal_rub'=>$point===null?null:(int)$point,'deal_low_rub'=>$n($v[28]),'deal_high_rub'=>$n($v[29]),
                        'deal_source'=>$kind,'deal_basis'=>$v[30],'commission_rate'=>0.07,'estimated_commission_rub'=>$commission,
                        'full_value_base_rub'=>(int)$base,'estimated_cumulative_value_rub'=>null,
                        'stage_cumulative_value_rub'=>$stageValue,'prior_reserved_rub'=>(int)$prior];
                }else{
                    $out['calculation_basis_counts']['unavailable']++;
                    $out['calculation_unavailable_rows']++;
                    $out['calculation_unavailable_reason_counts'][$calculationReason]++;
                    $e['calculation_unavailable_reason']=$calculationReason;
                }
                // Compare cumulative values at this frozen profile only. Neither value is a new
                // reservation, an alternative export history, or evidence of calibrated accuracy.
                $comparison=['version'=>'frozen_context_vs_stage_v1','status'=>'unavailable',
                    'basis'=>'same_frozen_base_existing_stage_fraction_not_export_history',
                    'reason'=>$calculationReason,'monetary_policy'=>null,'stage_contract'=>ModeledConversionValueContract::VERSION,
                    'frozen_base_rub'=>null,'stage_fraction'=>null,'contextual_cumulative_value_rub'=>null,
                    'stage_cumulative_value_rub'=>null,'contextual_minus_stage_rub'=>null];
                $predictionStages=[1=>'substantive_dialogue',2=>'concrete_request',3=>'exact_offer_discussion',
                    4=>'general_purchase_intent',5=>'agency_purchase_intent'];
                if($calculationReason===null&&(!isset($predictionStages[$stageRank])||$predictionStages[$stageRank]!==$v[23])){
                    $comparison['reason']='prediction_stage_unverified';
                }elseif($calculationReason===null||$stageValue!==null){
                    $baseline=$stageValue??(int)round(ModeledConversionValueContract::VALUES[$stageRank]/ModeledConversionValueContract::BASE*$base);
                    $comparison['status']=$calculationReason===null?'available':'stage_only';
                    $comparison['reason']=$calculationReason===null?null:'contextual_prediction_unavailable';
                    $comparison['monetary_policy']=$v[25];$comparison['frozen_base_rub']=(int)$base;
                    $comparison['stage_fraction']=['numerator'=>ModeledConversionValueContract::VALUES[$stageRank],
                        'denominator'=>ModeledConversionValueContract::BASE];
                    $comparison['stage_cumulative_value_rub']=$baseline;
                    if($calculationReason===null){
                        $comparison['contextual_cumulative_value_rub']=(int)$full;
                        $comparison['contextual_minus_stage_rub']=(int)$full-$baseline;
                    }
                }
                $e['stage_comparison']=$comparison;
                $comparisonBucket=$comparison['status'];
                if($comparisonBucket==='available')$comparisonBucket=$comparison['contextual_minus_stage_rub']>0?'above_stage':
                    ($comparison['contextual_minus_stage_rub']<0?'below_stage':'equal_stage');
                $out['stage_comparison_counts'][$comparisonBucket]++;
                if($comparison['status']==='available'&&$comparison['contextual_minus_stage_rub']!==0)$differenceExamples[]=$e;
                else $otherExamples[]=$e;
            }
            // Stable selection within the same inspected suffix, before the existing example/response caps.
            $out['examples']=array_slice(array_merge($differenceExamples,$otherExamples),0,16);
            $out['conversations']=count($cases);
            $this->budget->check();($this->authorize)();$this->budget->check();return $out;
        }catch(ModeledConversionStatusLimit $e){throw $e;}
        catch(PDOException $ignored){return ['available'=>false,'reason'=>'storage_unavailable'];}
        catch(UnexpectedValueException|JsonException $ignored){return ['available'=>false,'reason'=>'invalid_saved_projection'];}
    }

    /** Metadata only, with an explicit finite bound; never loads transcript/profile JSON. */
    private function cohort(array $settings):array
    {
        $limit=20001;
        $q="SELECT COUNT(*) AS profiles,COUNT(DISTINCT conversation_id) AS conversations,"
            ."COUNT(DISTINCT CASE WHEN reviewed=1 THEN conversation_id ELSE NULL END) AS reviewed_conversations,"
            ."COALESCE(SUM(reviewed),0) AS reviewed_profiles";
        foreach(['max','telegram']as$platform)$q.=",COUNT(DISTINCT CASE WHEN platform='".$platform."' THEN conversation_id ELSE NULL END) AS ".$platform."_conversations,"
            ."COUNT(DISTINCT CASE WHEN platform='".$platform."' AND reviewed=1 THEN conversation_id ELSE NULL END) AS ".$platform."_reviewed_conversations";
        $q.=" FROM (SELECT id,platform,conversation_id,CASE WHEN id<=? THEN 1 ELSE 0 END AS reviewed FROM modeled_conversion_profile_versions"
            ." WHERE project_key='anytour' AND id<=? AND observed_at_unix>=? AND observed_at_unix<? ORDER BY id LIMIT ".$limit.") cohort_metadata";
        $r=$this->query($q,[(int)$settings['history_cursor'],(int)$settings['history_ceiling'],(int)$settings['history_from_unix'],(int)$settings['history_until_unix']])[0];
        $out=['basis'=>'saved_profile_metadata_not_the_full_archive','max_versions'=>$limit,
            'versions_within_bound'=>$this->number($r['profiles'],false),'complete_within_bound'=>(int)$r['profiles']<$limit,
            'conversations_within_bound'=>$this->number($r['conversations'],false),
            'reviewed_conversations_within_bound'=>$this->number($r['reviewed_conversations'],false),
            'reviewed_versions_within_bound'=>$this->number($r['reviewed_profiles'],false),'by_platform'=>[]];
        foreach(['max','telegram']as$platform)$out['by_platform'][$platform]=[
            'conversations'=>$this->number($r[$platform.'_conversations'],false),
            'reviewed_conversations'=>$this->number($r[$platform.'_reviewed_conversations'],false)];
        return $out;
    }
    private function report(mixed $r,int $activation,int $now):array
    {
        $unknown=['status'=>'not_recorded','fresh'=>false,'goal_reaches'=>null,'goal_visits'=>null,'revenue_rub'=>null,
            'visit_linkage'=>'unknown','http_status'=>null,'transport_error_code'=>null,'failure_category'=>null];
        if(!is_array($r)||($r['goal_id']??null)!==self::GOAL||($r['currency']??null)!=='RUB')return $unknown;
        $at=ModeledConversionValueContract::moment($r['fetched_at']??null);
        if($at===null||$at<$activation||$at>$now||!in_array($r['status']??null,['ok','sampled','forbidden','unavailable'],true))return $unknown;
        $out=$unknown;$out['status']=$r['status'];$out['fetched_at']=$r['fetched_at'];$out['fresh']=$now-$at<=900;
        foreach(['http_status'=>[100,599],'transport_error_code'=>[0,100]]as$key=>[$min,$max]){
            $v=$r[$key]??null;$out[$key]=is_int($v)&&$v>=$min&&$v<=$max?$v:null;
        }
        $category=$r['failure_category']??null;
        if(!in_array($r['status'],['ok','sampled'],true)&&is_string($category)
            &&in_array($category,['rate_limited','provider_unavailable','invalid_request','access_denied','transport_error',
                'unexpected_http_status','invalid_response','unknown'],true))$out['failure_category']=$category;
        if(in_array($r['reason']??null,['invalid_scope','transport_unavailable','report_scope_or_counter_access','report_api_unavailable',
            'invalid_report','invalid_totals','invalid_counts','report_unavailable'],true))$out['reason']=$r['reason'];
        if(!in_array($r['status'],['ok','sampled'],true))return $out;
        foreach(['goal_reaches','goal_visits','revenue_rub']as$key){
            $v=$r[$key]??null;
            if((!is_int($v)&&!is_float($v))||!is_finite((float)$v)||$v<0||$v>1000000000000)return $unknown;
            if($key!=='revenue_rub'&&((float)$v)!==floor((float)$v))return $unknown;
            $out[$key]=$v;
        }
        if(($r['sampled']??null)!==($r['status']==='sampled'))return $unknown;
        $out['sampled']=$r['sampled'];$out['currency']='RUB';
        $out['date1']=is_string($r['date1']??null)&&preg_match('/\\A\\d{4}-\\d{2}-\\d{2}\\z/D',$r['date1'])===1?$r['date1']:null;
        $out['date2']=is_string($r['date2']??null)&&preg_match('/\\A\\d{4}-\\d{2}-\\d{2}\\z/D',$r['date2'])===1?$r['date2']:null;
        $out['visit_linkage']=$out['fresh']&&$r['status']==='ok'?($out['goal_visits']>0?'observed_in_goal_session_report':'not_observed_in_cached_report'):'unknown';
        return $out;
    }
    private function number(mixed $v,bool $money):int|float
    {
        if(!is_int($v)&&!is_float($v)&&!(is_string($v)&&preg_match('/\\A[0-9]+(?:\\.[0-9]{1,2})?\\z/D',$v)===1))throw new UnexpectedValueException('invalid_ledger');
        $limit=self::ROW_LIMIT*($money?ModeledConversionValueContract::MAX_VALUE_RUB:25000);
        $n=(float)$v;if(!is_finite($n)||$n<0||$n>$limit||(!$money&&$n!==floor($n)))throw new UnexpectedValueException('invalid_ledger');
        return $money?round($n,2):(int)$n;
    }
    private function unavailable(string $reason):array{return ['available'=>false,'goal_id'=>self::GOAL,'reason'=>$reason];}
    private function query(string $sql,array $args,?int $packetReserve=null,?int $maxMilliseconds=null):array
    {
        $this->budget->check();($this->authorize)();$this->budget->check();
        // Authorization consumes the same shared budget before the predictive copy limit is bound.
        if($packetReserve!==null)array_unshift($args,$this->budget->remainingBytes()-$packetReserve);
        if($maxMilliseconds===null)$q=$this->budget->prepare($this->pdo,$sql);
        else{
            if($maxMilliseconds<1||preg_match('/\ASELECT\s/i',$sql)!==1)throw new InvalidArgumentException('forecast_status_select_only');
            if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')
                $sql=preg_replace('/\ASELECT\s/i','SELECT /*+ MAX_EXECUTION_TIME('.min($maxMilliseconds,$this->budget->remainingMilliseconds()).') */ ',$sql,1);
            $q=$this->pdo->prepare($sql);$this->budget->check();
        }
        foreach($args as$i=>$v)$q->bindValue($i+1,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
        $q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$this->budget->check();
        $this->budget->consumeBytes(strlen(json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)));
        return $rows;
    }
}
