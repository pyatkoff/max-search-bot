<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionStatusBudget.php';
require_once __DIR__.'/ModeledConversionValueContract.php';

/** Fixed derived-value aggregates only. No source tables, network, processing or mutation. */
final class ModeledConversionValueDeliveryReader
{
    public const GOAL='678187097';
    public const ROW_LIMIT=201;
    private const STATES=['pending','ready','uploading','accepted','processed','unknown','failed','expired','unmatched'];
    private const UPLOADS=['PREPARED','UPLOADED','EXPORTED','MATCHED','PROCESSED','LINKAGE_FAILURE'];
    private const ERRORS=['identity_not_found','identity_ambiguous','scope_unavailable','api_rejected','api_row_rejected',
        'upload_outcome_unknown','event_expired','transport_not_sent','rate_limited'];
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
                'activated_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$activation),'last_tick_at'=>null,'last_tick_errors'=>null,
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
                'states_are_disjoint'=>true,'accepted_receipts_include_later_rejected_or_unmatched_rows'=>true,
                'processed_means_validated_upload_rows_not_verified_visit_linkage'=>true,
                'matched_upload_status_means_user_ids_matched_not_per_upload_visit_proof'=>true,
                'goal_report_is_by_session_dates_including_pre_activation_attribution_window'=>true,
                'identity_counts_are_stored_resolution_not_a_new_scope_check'=>true,
                'conversations_are_not_verified_people_or_trips'=>true,'per_upload_visit_match_verified'=>false,'reporting_can_lag'=>true,'no_ids_or_text_returned'=>true];
            $out['price_continuity_audit']=$this->priceAudit($receipt['price_continuity_audit']??null,$now);
            $out['predictive_v1']=$this->predictiveV1($now);
            return $out;
        }catch(ModeledConversionStatusLimit $e){throw $e;}
        catch(PDOException $ignored){return $this->unavailable('storage_unavailable');}
        catch(UnexpectedValueException $ignored){return $this->unavailable('invalid_ledger');}
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
                ||!is_int($v['removed_rub']??null)||$v['removed_rub']<0||$v['removed_rub']>25025000)return ['available'=>false,'reason'=>'invalid_recorded_audit'];
            $out['actions'][$key]=['events'=>$v['events'],'removed_rub'=>$v['removed_rub']];
        }
        foreach($a['corrections']as$v){
            if(!is_array($v)||!in_array($v['action']??null,['voided','reduced','review_required'],true))return ['available'=>false,'reason'=>'invalid_recorded_audit'];
            foreach(['original_rub','applied_rub','prior_reserved_rub']as$key)if(!is_int($v[$key]??null)||$v[$key]<0||$v[$key]>25000)return ['available'=>false,'reason'=>'invalid_recorded_audit'];
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
                $v="JSON_EXTRACT(".$safe.",'$.".$path."')";return $mysql?'JSON_UNQUOTE('.$v.')':$v;
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
            $sql="SELECT JSON_ARRAY(".implode(',',$columns).") AS packet FROM"
                ." (SELECT * FROM max_channel_conversion_outbox WHERE project_key='anytour' AND counter_id='98615635'"
                ." AND transport_target=? AND value_contract=? ORDER BY id DESC LIMIT ".self::ROW_LIMIT.") o"
                ." LEFT JOIN modeled_conversion_profile_versions p ON p.id=o.value_profile_id AND p.project_key=o.project_key"
                ." AND p.platform=o.platform AND p.conversation_id=o.conversation_id"
                ." WHERE o.target LIKE 'anytour_value_pred_%' ORDER BY o.id DESC LIMIT 17";
            $marker=' AS packet';$end=strpos($sql,$marker);if($end===false)throw new LogicException('predictive_projection');
            $expression=substr($sql,7,$end-7);
            $packetBytes=$mysql?'OCTET_LENGTH(JSON_QUOTE('.$expression.'))':'LENGTH(CAST(JSON_QUOTE('.$expression.') AS BLOB))';
            $sizes=$this->query('SELECT '.$packetBytes.'+24 AS packet_bytes'.substr($sql,$end+strlen($marker)),[ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION]);
            $total=2;foreach($sizes as$size)$total+=(int)($size['packet_bytes']??PHP_INT_MAX);
            if($total+8192>$this->budget->remainingBytes())return ['available'=>false,'reason'=>'budget_headroom'];
            $rows=$this->query($sql,[ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION]);
            $out=['available'=>true,'basis'=>'saved_predictive_key_rows_and_frozen_scalar_calculations_not_reclassification',
                'window'=>['max_goal_rows'=>self::ROW_LIMIT,'max_predictive_rows'=>17,'inspected_predictive_rows'=>count($rows),
                    'complete_within_goal_suffix'=>count($rows)<17,'global_coverage_known'=>false],
                'events'=>0,'conversations'=>0,'value_rub'=>0,'api_accepted_events'=>0,'api_accepted_value_rub'=>0,
                'processed_events'=>0,'processed_value_rub'=>0,'by_state'=>[],'by_platform'=>[],
                'invalid_binding_rows'=>0,'voided_zero_value_rows'=>0,'calculation_unavailable_rows'=>0,'examples'=>[],
                'per_event_visit_linkage'=>'not_observed_by_this_cached_upload_reader','provider_requests'=>0];
            $cases=[];
            foreach($rows as$row){
                $v=json_decode($row['packet'],true,16,JSON_THROW_ON_ERROR);
                if(!is_array($v)||count($v)!==38)throw new UnexpectedValueException();
                if(preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$v[2])!==1||!is_string($v[3])||$v[3]!==ModeledConversionValueContract::predictionKey((string)$v[2])
                    ||!in_array($v[4],['max','telegram'],true)||!in_array($v[7],self::STATES,true)
                    ||!in_array($v[5],ModeledConversionValueContract::STAGES,true)){$out['invalid_binding_rows']++;continue;}
                $delta=(int)$this->number($v[6],true);
                if($delta===0&&$v[7]==='failed'){$out['voided_zero_value_rows']++;continue;}
                if($delta<=0||$delta>25000){$out['invalid_binding_rows']++;continue;}
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
                    'calculation'=>null,'visit_linkage'=>'unverified'];
                // These are fixed saved scalar fields, with independent arithmetic checks, not a signature attestation.
                $n=static fn(mixed $x):?float=>is_numeric($x)&&is_finite((float)$x)?(float)$x:null;
                $prob=$n($v[24]);$rate=$n($v[26]);$point=$n($v[27]);$base=$n($v[33]);$full=$n($v[34]);$prior=$n($v[37]);
                $commission=$n($v[32]);$kind=$v[31];
                $sources=['unknown','customer_budget','delivered_offer','native_hotel_inquiry','channel_post_range'];
                $bases=['unknown','customer_budget_ceiling_proxy','customer_budget_floor_proxy','customer_budget_range_proxy',
                    'delivered_offer_observation','native_hotel_inquiry_observation','advertised_floor_proxy','advertised_range_proxy'];
                $binding=preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$v[20])===1&&$v[16]==='allowed'&&$v[17]==='anytour'&&$v[18]===$v[4]&&(string)$v[19]===(string)$v[1]
                    &&$v[21]==='contextual_advertising_v1'&&$v[22]==='predicted'&&$v[25]==='early_deal_commission_7pct_v1'
                    &&$rate===0.07&&$prob!==null&&$prob>0&&$prob<=1&&$base!==null&&$base>0&&$base<=25000
                    &&$full!==null&&$full===round($prob*$base)&&$prior!==null&&$prior>=0&&$full-$prior===(float)$delta
                    &&in_array($kind,$sources,true)&&in_array($v[30],$bases,true)
                    &&($point===null?$commission===null&&$base===25000.0:
                        $point>=10000&&$point<=10000000&&$commission===round($point*0.07)&&$base===min(25000.0,$commission));
                if($binding){
                    $reasons=$v[36];if(is_string($reasons))$reasons=json_decode($reasons,true,8);
                    $allowed=['expert_dialogue_continued_meaningful_engagement','expert_dialogue_continued_offer_search',
                        'expert_dialogue_office_question','expert_dialogue_payment_terms_question','explicit_decline_guard'];
                    $factors=[];foreach(is_array($reasons)?$reasons:[]as$reason)if(is_string($reason)&&in_array($reason,$allowed,true))$factors[]=$reason;
                    $e['calculation']=['basis'=>'frozen_scalar_arithmetic_checked_not_new_model_validation',
                        'estimated_probability'=>$prob,'odds_multiplier'=>$n($v[35]),'expert_factors'=>$factors,
                        'estimated_deal_rub'=>$point===null?null:(int)$point,'deal_low_rub'=>$n($v[28]),'deal_high_rub'=>$n($v[29]),
                        'deal_source'=>$kind,'deal_basis'=>$v[30],'commission_rate'=>0.07,'estimated_commission_rub'=>$commission,
                        'full_value_base_rub'=>(int)$base,'estimated_cumulative_value_rub'=>(int)$full,
                        'prior_reserved_rub'=>(int)$prior];
                }else $out['calculation_unavailable_rows']++;
                if(count($out['examples'])<16)$out['examples'][]=$e;
            }
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
            'visit_linkage'=>'unknown'];
        if(!is_array($r)||($r['goal_id']??null)!==self::GOAL||($r['currency']??null)!=='RUB')return $unknown;
        $at=ModeledConversionValueContract::moment($r['fetched_at']??null);
        if($at===null||$at<$activation||$at>$now||!in_array($r['status']??null,['ok','sampled','forbidden','unavailable'],true))return $unknown;
        $out=$unknown;$out['status']=$r['status'];$out['fetched_at']=$r['fetched_at'];$out['fresh']=$now-$at<=900;
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
        $n=(float)$v;if(!is_finite($n)||$n<0||$n>self::ROW_LIMIT*25000||(!$money&&$n!==floor($n)))throw new UnexpectedValueException('invalid_ledger');
        return $money?round($n,2):(int)$n;
    }
    private function unavailable(string $reason):array{return ['available'=>false,'goal_id'=>self::GOAL,'reason'=>$reason];}
    private function query(string $sql,array $args):array
    {
        $this->budget->check();($this->authorize)();$this->budget->check();$q=$this->budget->prepare($this->pdo,$sql);
        foreach($args as$i=>$v)$q->bindValue($i+1,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
        $q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$this->budget->check();
        $this->budget->consumeBytes(strlen(json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)));
        return $rows;
    }
}
