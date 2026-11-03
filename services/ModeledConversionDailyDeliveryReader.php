<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionStatusBudget.php';

/** Fixed-scope goal-row aggregates; observation clocks are not provider event times. */
final class ModeledConversionDailyDeliveryReader
{
    private const MAX_COUNT=1000000000;
    private const MAX_RUB=1000000000000;
    private const MAX_CENTS=100000000000000;
    public function __construct(private PDO $pdo,private $authorize,private ModeledConversionStatusBudget $budget){}
    public function read(int $now):array
    {
        $this->auth();
        if($now<946684800)return $this->done(['available'=>false,'reason'=>'invalid_clock']);
        if($this->budget->remainingBytes()<8192)return $this->done(['available'=>false,'reason'=>'budget_headroom']);
        try{
            $day=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Kaliningrad'))->setTime(0,0,0);
            $from=$day->getTimestamp();$until=$day->modify('+1 day')->getTimestamp();
            $scope="project_key='anytour' AND counter_id='98615635' AND transport_target='anytour_modeled_value_v1' AND value_contract='semantic_stage_value_25000_v1'";
            $receipt="upload_id IS NOT NULL AND upload_id<>''";
            $history="value_origin IN ('owner_day_review_v1','owner_fortnight_review_v1')";
            $bad="platform IS NULL OR platform NOT IN ('max','telegram') OR conversation_id IS NULL OR conversation_id<=0"
                ." OR value_rub IS NULL OR value_rub<=0 OR value_rub>25000"
                ." OR (value_api_accepted_at_unix IS NOT NULL AND (value_api_accepted_at_unix<946684800 OR NOT (".$receipt.")"
                ." OR value_receipt_basis IS NULL OR value_receipt_basis NOT IN ('post_response','get_reconciliation')))"
                ." OR (value_processed_at_unix IS NOT NULL AND (value_processed_at_unix<946684800 OR NOT (".$receipt.")"
                ." OR value_receipt_basis IS NULL OR value_receipt_basis NOT IN ('post_response','get_reconciliation')))";
            $columns=["COUNT(*) AS n","COUNT(DISTINCT conversation_id) AS c","COALESCE(SUM(value_rub),0) AS v",
                "COALESCE(SUM(CASE WHEN ".$bad." THEN 1 ELSE 0 END),0) AS bad"];
            foreach(['m'=>"platform='max'",'t'=>"platform='telegram'",'h'=>$history]as$prefix=>$condition){
                $columns[]="COALESCE(SUM(CASE WHEN ".$condition." THEN 1 ELSE 0 END),0) AS ".$prefix."n";
                $columns[]="COUNT(DISTINCT CASE WHEN ".$condition." THEN conversation_id ELSE NULL END) AS ".$prefix."c";
                $columns[]="COALESCE(SUM(CASE WHEN ".$condition." THEN value_rub ELSE 0 END),0) AS ".$prefix."v";
            }
            $cohorts=['claims'=>['claimed_at_unix','1=1'],'receipts'=>['value_api_accepted_at_unix',$receipt],
                'processed'=>['value_processed_at_unix',$receipt],
                'legacy_claims'=>['claimed_at_unix',$receipt.' AND value_api_accepted_at_unix IS NULL'],
                'missing_receipt_clock'=>[null,$receipt.' AND value_api_accepted_at_unix IS NULL']];
            $queries=[];$args=[];
            foreach($cohorts as$key=>[$clock,$condition]){
                $where=$scope.' AND ('.$condition.')';
                if($clock!==null){$where.=' AND '.$clock.'>=? AND '.$clock.'<? AND '.$clock.'<=?';array_push($args,$from,$until,$now);}
                $queries[]="SELECT '".$key."' AS cohort,".implode(',',$columns).' FROM max_channel_conversion_outbox WHERE '.$where;
            }
            $this->auth();$q=$this->budget->prepare($this->pdo,implode(' UNION ALL ',$queries));
            foreach($args as$i=>$v)$q->bindValue($i+1,$v,PDO::PARAM_INT);
            $q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
            $this->budget->consumeBytes(strlen(json_encode($rows,JSON_THROW_ON_ERROR)));$this->auth();
            if(count($rows)!==count($cohorts))throw new UnexpectedValueException();
            $values=[];
            foreach($rows as$r){
                $key=$r['cohort']??null;
                if(!is_string($key)||!array_key_exists($key,$cohorts)||isset($values[$key])||self::countValue($r['bad']??null)!==0)
                    throw new UnexpectedValueException();
                $values[$key]=self::summary($r);
            }
            return $this->done(['available'=>true,'date'=>$day->format('Y-m-d'),'timezone'=>'Europe/Kaliningrad',
                'as_of'=>gmdate('Y-m-d\TH:i:s\Z',$now),
                'window'=>['from'=>gmdate('Y-m-d\TH:i:s\Z',$from),'until_exclusive'=>gmdate('Y-m-d\TH:i:s\Z',$until)],'currency'=>'RUB',
                'upload_claims_today'=>['basis'=>'last_current_persisted_upload_claim_not_api_response_time']+$values['claims'],
                'api_receipts_observed_today'=>['basis'=>'first_server_observation_at_value_api_accepted_at_unix']+$values['receipts'],
                'processed_observed_today'=>['basis'=>'first_server_observation_at_value_processed_at_unix']+$values['processed'],
                'legacy_receipts_with_claim_today'=>['basis'=>'current_claim_day_only_api_receipt_observation_day_unknown']+$values['legacy_claims'],
                'coverage'=>['api_receipts_without_observed_clock'=>[
                    'basis'=>'all_scoped_receipts_without_observed_clock_not_assigned_to_a_day']+$values['missing_receipt_clock']],
                'interpretation'=>['counts_are_goal_rows_not_http_requests_or_purchases'=>true,
                    'observed_clock_is_not_provider_event_time'=>true,'processed_is_not_per_event_visit_match'=>true,
                    'historical_review_is_origin_not_delivery_day'=>true,'cohorts_overlap_do_not_sum'=>true,'no_ids_or_text_returned'=>true]]);
        }catch(ModeledConversionStatusLimit $error){throw $error;}
        catch(PDOException $ignored){return $this->done(['available'=>false,'reason'=>'storage_unavailable']);}
        catch(UnexpectedValueException $ignored){return $this->done(['available'=>false,'reason'=>'invalid_aggregate']);}
    }
    private static function summary(array $r):array
    {
        $all=self::numbers($r,'');$max=self::numbers($r,'m');$telegram=self::numbers($r,'t');$history=self::numbers($r,'h');
        if($max[0]+$telegram[0]!==$all[0]||$max[1]+$telegram[1]!==$all[1]||$max[2]+$telegram[2]!==$all[2]
            ||$history[0]>$all[0]||$history[1]>$all[1]||$history[2]>$all[2])throw new UnexpectedValueException();
        return self::display($all)+['by_platform'=>['max'=>self::display($max),'telegram'=>self::display($telegram)],
            'historical_review'=>self::display($history)];
    }
    private static function numbers(array $r,string $prefix):array
    {
        $n=self::countValue($r[$prefix.'n']??null);$c=self::countValue($r[$prefix.'c']??null);$v=self::cents($r[$prefix.'v']??null);
        if($c>$n||($n===0&&($c!==0||$v!==0))||($n>0&&($c===0||$v<$n||$v>$n*2500000)))throw new UnexpectedValueException();
        return [$n,$c,$v];
    }
    private static function display(array $v):array
    {return ['events'=>$v[0],'conversations'=>$v[1],'value_rub'=>$v[2]%100===0?intdiv($v[2],100):$v[2]/100];}
    private static function countValue(mixed $v):int
    {
        if(is_int($v)){if($v<0||$v>self::MAX_COUNT)throw new UnexpectedValueException();return $v;}
        if(!is_string($v)||preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$v)!==1||(int)$v>self::MAX_COUNT)throw new UnexpectedValueException();
        return(int)$v;
    }
    private static function cents(mixed $v):int
    {
        if(is_int($v)){if($v<0||$v>self::MAX_RUB)throw new UnexpectedValueException();return $v*100;}
        if(is_float($v)){
            if(!is_finite($v)||$v<0||$v>self::MAX_RUB)throw new UnexpectedValueException();
            $scaled=$v*100;$rounded=round($scaled);if(abs($scaled-$rounded)>0.01)throw new UnexpectedValueException();return(int)$rounded;
        }
        if(!is_string($v)||preg_match('/\A([0-9]{1,13})(?:\.([0-9]{1,2}))?\z/D',$v,$m)!==1)throw new UnexpectedValueException();
        $cents=(int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');if($cents>self::MAX_CENTS)throw new UnexpectedValueException();return$cents;
    }
    private function done(array $out):array{$this->auth();return$out;}
    private function auth():void{$this->budget->check();($this->authorize)();$this->budget->check();}
}
