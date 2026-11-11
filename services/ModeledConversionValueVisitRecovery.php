<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionValueVisitEvidence.php';
require_once __DIR__.'/ModeledConversionValueContract.php';

/** One additional finite observation, separate from every immutable original visit result and financial decision. */
final class ModeledConversionValueVisitRecovery
{
    public const VERSION='value_finite_visit_recovery_v1';
    public const MAX_ROWS=2;
    public const MAX_BYTES=2048;
    public static function originalDigest(mixed $original,string $namespace,int $now):?string
    {
        $frame=ModeledConversionValueVisitEvidence::decode($original,$namespace,$now);if($frame===null)return null;
        return hash('sha256',json_encode([$frame['v'],$frame['n'],$frame['b'],$frame['r']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    public static function originals(mixed $original,string $namespace,int $now):?array
    {
        $frame=ModeledConversionValueVisitEvidence::decode($original,$namespace,$now);if($frame===null)return null;
        $cases=[];
        foreach($frame['r']as$row)if($row[2]===2&&in_array($row[5],[1,2],true)&&$row[10]===2)$cases[$row[0]]=$row;
        return count($cases)===self::MAX_ROWS?$cases:null;
    }
    public static function create(mixed $original,string $namespace,int $now):?array
    {
        $cases=self::originals($original,$namespace,$now);if($cases===null)return null;
        $journal=['v'=>self::VERSION,'n'=>$namespace,'d'=>self::originalDigest($original,$namespace,$now),'r'=>[]];
        foreach($cases as$row)$journal['r'][]=[$row[0],$row[9],0,null,null,0,null,0,null,0,0];
        return $journal;
    }
    /** The previous report is only a reservation hint. maxAge=0 admits this exact ordinary response tick. */
    public static function healthyOrdinary(mixed $report,int $tick,int $maxAge=0):bool
    {
        if(!is_array($report)||($report['goal_id']??null)!=='678187097'||($report['currency']??null)!=='RUB'
            ||($report['status']??null)!=='ok'||($report['sampled']??null)!==false
            ||($report['http_status']??null)!==200||($report['transport_error_code']??null)!==0
            ||($report['failure_category']??null)!==null||$maxAge<0||$maxAge>600)return false;
        $at=ModeledConversionValueContract::moment($report['fetched_at']??null);
        return $at!==null&&$at<=$tick&&$tick-$at<=$maxAge;
    }
    public static function decode(mixed $journal,mixed $original,string $namespace,int $now):?array
    {
        $cases=self::originals($original,$namespace,$now);
        if($cases===null||!is_array($journal)||count($journal)!==4||array_diff(array_keys($journal),['v','n','d','r'])
            ||($journal['v']??null)!==self::VERSION||($journal['n']??null)!==$namespace
            ||($journal['d']??null)!==self::originalDigest($original,$namespace,$now)
            ||!is_array($journal['r']??null)||!array_is_list($journal['r'])||count($journal['r'])!==self::MAX_ROWS)return null;
        try{if(strlen(json_encode($journal,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>self::MAX_BYTES)return null;}
        catch(Throwable $ignored){return null;}
        $seen=[];$clock=static fn(mixed $v):bool=>is_int($v)&&$v>=946684800&&$v<=$now;
        foreach($journal['r']as$row){
            if(!is_array($row)||!array_is_list($row)||count($row)!==11||!is_string($row[0])
                ||isset($seen[$row[0]])||!isset($cases[$row[0]])||$row[1]!==$cases[$row[0]][9]
                ||!is_int($row[2])||!in_array($row[2],[0,1,2,3],true)
                ||!is_int($row[5])||!in_array($row[5],[0,1,2],true)
                ||!is_int($row[7])||$row[7]<0||$row[7]>4
                ||!is_int($row[9])||!in_array($row[9],[0,1,2],true)
                ||!is_int($row[10])||$row[10]<0||$row[10]>3)return null;
            $old=$cases[$row[0]];
            foreach([3,4,8]as$i)if($row[$i]!==null&&!$clock($row[$i]))return null;
            if(($row[3]!==null&&$row[3]<=$old[4])||($row[4]!==null&&$row[4]<=$old[4])
                ||($row[3]!==null&&$row[4]!==null&&$row[4]<$row[3])
                ||($row[8]!==null&&$row[8]>$old[15]))return null;
            if($row[2]===0){if($row[3]!==null||$row[4]!==null||$row[5]!==0||$row[10]!==0)return null;}
            elseif($row[2]===1){if($row[3]===null||$row[4]!==null||$row[5]!==0||$row[10]!==1)return null;}
            elseif($row[2]===3){if($row[3]!==null||$row[4]===null||$row[5]!==0||!in_array($row[10],[2,3],true))return null;}
            elseif($row[3]===null||$row[4]===null||!in_array($row[5],[1,2],true)||$row[10]!==1)return null;
            if($row[5]===0){if($row[6]!==null||$row[7]!==0||$row[8]!==null||$row[9]!==0)return null;}
            elseif($row[5]===2){if($row[6]!==false||$row[7]!==4||$row[8]!==null||$row[9]!==0)return null;}
            else{
                if(!is_bool($row[6])||!in_array($row[7],[1,2,3],true)||!in_array($row[9],[1,2],true))return null;
                $seconds=$row[9]===1?ModeledConversionAttributionWindow::MAX_SECONDS:ModeledConversionAttributionWindow::UNKNOWN_SECONDS;
                $allowed=$row[8]!==null&&$old[15]-$row[8]<=$seconds&&$row[4]-$row[8]<=$seconds;
                $reason=$row[8]===null?3:($allowed?1:2);
                if($row[6]!==$allowed||$row[7]!==$reason)return null;
            }
            $seen[$row[0]]=true;
        }
        return $journal;
    }
    public static function projection(mixed $journal,mixed $original,string $namespace,int $now):array
    {
        $fail=static fn(string $reason):array=>['available'=>false,'reason'=>$reason,'provider_requests_in_reader'=>0];
        if($journal===false)return $fail('retained_recovery_tombstone_no_automatic_retry');
        if($journal===null){
            $pending=self::create($original,$namespace,$now);if($pending===null)return $fail('eligible_original_cases_unavailable');
            $out=self::projection($pending,$original,$namespace,$now);$out['recorded']=false;
            $out['basis']='eligible_forward_attempts_not_yet_recorded';return $out;
        }
        $value=self::decode($journal,$original,$namespace,$now);if($value===null)return $fail('original_or_recovery_binding_unavailable');
        $cases=self::originals($original,$namespace,$now);$at=static fn(?int $v):?string=>$v===null?null:gmdate('Y-m-d\TH:i:s\Z',$v);
        $out=['available'=>true,'recorded'=>true,'basis'=>'one_forward_attempt_after_current_ordinary_health_and_same_canonical_candidate',
            'assessment_namespace'=>$namespace,'original_frame_sha256'=>$value['d'],'additional_attempt_limit_per_case'=>1,
            'logical_check_budget_per_tick'=>2,'complete'=>true,'automatic_attempts_finished'=>true,'checks_consumed'=>0,
            'rows'=>[],'provider_requests_in_reader'=>0];
        foreach($value['r']as$row){
            $old=$cases[$row[0]];$out['complete']=$out['complete']&&in_array($row[2],[2,3],true);
            $out['automatic_attempts_finished']=$out['automatic_attempts_finished']&&$row[2]!==0;
            if($row[3]!==null)$out['checks_consumed']++;
            $out['rows'][]=['event_ref'=>$row[0],'candidate_sha256'=>$row[1],
                'original_checked_at'=>$at($old[4]),'original_report_status'=>'unavailable',
                'attempt_status'=>[0=>'pending',1=>'consumed_unknown',2=>'observed',3=>'source_binding_unavailable'][$row[2]],
                'source_binding'=>[0=>'not_checked',1=>'canonical_lookup_matches_original_candidate',2=>'current_canonical_identity_unavailable',3=>'current_binding_changed'][$row[10]],
                'checked_at'=>$at($row[4]??$row[3]),'report_status'=>$row[2]===1?'unknown':([0=>'not_requested',1=>'ok',2=>'unavailable'][$row[5]]),
                'window_allowed'=>$row[6],'window_reason'=>[0=>null,1=>'within_current_window',2=>'outside_current_window',3=>'no_prior_visit',4=>'visit_report_unavailable'][$row[7]],
                'selected_prior_visit_at'=>$at($row[8]),'source_class'=>$row[9]===1?'yandex_direct':($row[9]===2?'other_or_unknown':'unverified')];
        }
        return $out;
    }
}
