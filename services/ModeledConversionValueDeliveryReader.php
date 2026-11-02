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
            $rows=$this->query("SELECT mode,goal_id,goal_intent,activated_at_unix,last_tick_unix,contract_version,CASE WHEN ".$len."<=8192 THEN last_receipt_json ELSE NULL END AS receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour' AND counter_id='98615635' LIMIT 1",[]);
            if(!$rows)return $this->unavailable('not_configured');
            $s=$rows[0];
            if(($s['goal_id']??null)!==self::GOAL||($s['contract_version']??null)!==ModeledConversionValueContract::VERSION
                ||!in_array($s['mode']??null,['off','send'],true)||($s['goal_intent']??null)!=='verified')return $this->unavailable('configuration_unverified');
            $activation=(int)($s['activated_at_unix']??0);if($activation<946684800||$activation>$now)return $this->unavailable('activation_unavailable');
            $columns=['COUNT(*) AS inspected','COALESCE(SUM(value_rub),0) AS reserved_rub',
                "COALESCE(SUM(CASE WHEN upload_id IS NOT NULL AND upload_id<>'' THEN 1 ELSE 0 END),0) AS accepted_receipts",
                "COALESCE(SUM(CASE WHEN upload_id IS NOT NULL AND upload_id<>'' THEN value_rub ELSE 0 END),0) AS accepted_receipts_rub"];
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
            $sql='SELECT '.implode(',',$columns)." FROM (SELECT state,value_stage,value_rub,upload_id,upload_status,last_error FROM max_channel_conversion_outbox WHERE project_key='anytour' AND counter_id='98615635' AND transport_target=? AND value_contract=? AND event_unix>=? ORDER BY id DESC LIMIT ".self::ROW_LIMIT.') bounded_values';
            $r=$this->query($sql,[ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,$activation])[0];
            $seen=$this->number($r['inspected'],false);
            if($seen>self::ROW_LIMIT)return $this->unavailable('invalid_ledger');
            $out=['available'=>true,'goal_id'=>self::GOAL,'counter_id'=>'98615635','target'=>ModeledConversionValueContract::TARGET,
                'contract'=>ModeledConversionValueContract::VERSION,'currency'=>'RUB','mode'=>$s['mode'],
                'activated_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$activation),'last_tick_at'=>null,'last_tick_errors'=>null,
                'window'=>['basis'=>'latest_goal_ledger_rows_since_activation','max_rows'=>self::ROW_LIMIT,'inspected_rows'=>$seen,
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
            $receipt=null;
            if(is_string($s['receipt_json']??null)){try{$receipt=json_decode($s['receipt_json'],true,32,JSON_THROW_ON_ERROR);}catch(JsonException $ignored){}}
            if(is_array($receipt)&&is_int($receipt['errors']??null)&&$receipt['errors']>=0&&$receipt['errors']<=100)$out['last_tick_errors']=$receipt['errors'];
            $tick=(int)($s['last_tick_unix']??0);if($tick>=$activation&&$tick<=$now)$out['last_tick_at']=gmdate('Y-m-d\\TH:i:s\\Z',$tick);
            $out['metrika_goal_report']=$this->report($receipt['metrika_report']??null,$activation,$now);
            $out['interpretation']=['values'=>'owner_assigned_advertising_estimates_not_actual_income',
                'states_are_disjoint'=>true,'accepted_receipts_include_later_rejected_or_unmatched_rows'=>true,
                'processed_means_validated_upload_rows_not_verified_visit_linkage'=>true,
                'matched_upload_status_means_user_ids_matched_not_per_upload_visit_proof'=>true,
                'goal_report_is_by_session_dates_including_pre_activation_attribution_window'=>true,
                'per_upload_visit_match_verified'=>false,'reporting_can_lag'=>true,'no_ids_or_text_returned'=>true];
            return $out;
        }catch(ModeledConversionStatusLimit $e){throw $e;}
        catch(PDOException $ignored){return $this->unavailable('storage_unavailable');}
        catch(UnexpectedValueException $ignored){return $this->unavailable('invalid_ledger');}
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
