<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionValueVisitRecovery.php';
require_once __DIR__.'/ModeledConversionAttributionWindow.php';

/** Pure saved observations from ordinary work. Never supplies identity, money or permission to send. */
final class ModeledConversionFixedPairDecisionEvidence
{
    public const RECEIPT_KEY='fixed_pair_operational_decisions';
    public const VERSION='fixed_pair_decisions_v1';
    public const MAX_BYTES=512;
    public const MAX_ROWS=2;
    private const REASONS=[1=>'scope_hold',2=>'price_hold',3=>'source_hold',4=>'collision_hold',
        5=>'within_current_window',6=>'outside_current_window',7=>'no_prior_visit',8=>'visit_report_unavailable'];

    /** Newer tick wins, then newer actual check. Equal clocks preserve the saved observation. */
    public static function merge(mixed $previous,mixed $original,string $namespace,array $incomingRows,int $now):?array
    {
        $binding=self::binding($original,$namespace,$now);
        if($binding===null||!array_is_list($incomingRows)||count($incomingRows)>self::MAX_ROWS)return null;
        $saved=self::normalize($previous,$binding,$namespace,$now);
        $rows=[];foreach($saved['r']??[]as$row)$rows[$row[0]]=$row;
        $seen=[];
        foreach($incomingRows as$row){
            if(!self::rowValid($row,$binding,$now)||isset($seen[$row[0]]))return null;
            $seen[$row[0]]=true;$prior=$rows[$row[0]]??null;
            if($prior===null||$row[1]>$prior[1]||($row[1]===$prior[1]&&$row[2]>$prior[2]))$rows[$row[0]]=$row;
        }
        if(!$rows)return null;
        $ordered=[];foreach($binding['events']as$ref=>$event)if(isset($rows[$ref]))$ordered[]=$rows[$ref];
        return self::normalize(['v'=>self::VERSION,'n'=>$namespace,'d'=>$binding['digest'],'r'=>$ordered],$binding,$namespace,$now);
    }

    /** Every bound case remains visible; an untouched or stale case has no current-tick decision. */
    public static function projection(mixed $frame,mixed $original,string $namespace,int $now,int $lastTick):array
    {
        $binding=self::binding($original,$namespace,$now);
        if($binding===null)return ['available'=>false,'reason'=>'original_pair_unavailable',
            'financial_permission'=>false,'provider_requests_in_reader'=>0];
        $saved=self::normalize($frame,$binding,$namespace,$now);$byRef=[];
        foreach($saved['r']??[]as$row)$byRef[$row[0]]=$row;
        $latest=$lastTick>=946684800&&$lastTick<=$now;$rows=[];$current=0;
        foreach($binding['events']as$ref=>$event){
            $row=$byRef[$ref]??null;$inLatest=$row!==null&&$latest&&$row[1]===$lastTick;
            if($inLatest)$current++;
            $hasVisit=$row!==null&&$row[4]!==null;
            $rows[]=['event_ref'=>$ref,
                'observation_status'=>$row===null?'not_observed':($inLatest?'observed':'stale'),
                'checked_in_latest_tick'=>$inLatest,
                'current_decision'=>$inLatest?self::REASONS[$row[3]]:'unknown',
                'saved_decision'=>$row===null?null:self::REASONS[$row[3]],
                'tick_started_at'=>$row===null?null:gmdate('Y-m-d\TH:i:s\Z',$row[1]),
                'checked_at'=>$row===null?null:gmdate('Y-m-d\TH:i:s\Z',$row[2]),
                'saved_visit_started_at'=>$hasVisit?gmdate('Y-m-d\TH:i:s\Z',$row[4]):null,
                'saved_source_class'=>$hasVisit?($row[5]===1?'yandex_direct':'other_or_unknown'):null,
                'saved_window_days'=>$hasVisit?($row[5]===1?10:3):null,
                'saved_visit_window_allowed'=>$row===null||!in_array($row[3],[5,6],true)?null:$row[3]===5];
        }
        return ['available'=>true,'version'=>self::VERSION,
            'basis'=>'original_pair_bound_saved_ordinary_phase_and_preclaim_observations',
            'assessment_namespace'=>$namespace,'original_frame_sha256'=>$binding['digest'],
            'journal_status'=>$saved!==null?'saved':($frame===null?'not_recorded':'invalid_or_omitted'),
            'last_tick_at'=>$latest?gmdate('Y-m-d\TH:i:s\Z',$lastTick):null,
            'bound_cases'=>self::MAX_ROWS,'saved_observations'=>count($byRef),'observed_in_latest_tick'=>$current,
            'current_tick_coverage_complete'=>$latest&&$current===self::MAX_ROWS,'rows'=>$rows,
            'unknown_does_not_prove_no_visit_or_failed_delivery'=>true,
            'no_prior_visit_is_limited_to_the_current_provider_query'=>true,
            'positive_gate_is_not_api_acceptance'=>true,'financial_permission'=>false,'provider_requests_in_reader'=>0];
    }

    private static function binding(mixed $original,string $namespace,int $now):?array
    {
        if($now<946684800)return null;
        try{
            $frame=ModeledConversionValueVisitEvidence::decode($original,$namespace,$now);
            $cases=ModeledConversionValueVisitRecovery::originals($original,$namespace,$now);
            $digest=ModeledConversionValueVisitRecovery::originalDigest($original,$namespace,$now);
        }catch(Throwable $ignored){return null;}
        if($frame===null||count($frame['r'])!==3||$cases===null||count($cases)!==self::MAX_ROWS||$digest===null)return null;
        $events=[];
        foreach($cases as$ref=>$row){
            if($row[2]!==2||$row[5]!==1||$row[10]!==2)return null;
            $events[$ref]=$row[15];
        }
        return ['digest'=>$digest,'events'=>$events];
    }

    private static function rowValid(mixed $row,array $binding,int $now):bool
    {
        if(!is_array($row)||!array_is_list($row)||count($row)!==6||!is_string($row[0])||!isset($binding['events'][$row[0]])
            ||!is_int($row[1])||!is_int($row[2])||$row[1]<$binding['events'][$row[0]]||$row[2]<$row[1]||$row[2]>$now
            ||!is_int($row[3])||!isset(self::REASONS[$row[3]])||!is_int($row[5]))return false;
        if(!in_array($row[3],[5,6],true))return $row[4]===null&&$row[5]===0;
        if(!is_int($row[4])||$row[4]<946684800||$row[4]>$binding['events'][$row[0]]||!in_array($row[5],[1,2],true))return false;
        $seconds=$row[5]===1?ModeledConversionAttributionWindow::MAX_SECONDS:ModeledConversionAttributionWindow::UNKNOWN_SECONDS;
        $allowed=$binding['events'][$row[0]]-$row[4]<=$seconds&&$row[2]-$row[4]<=$seconds;
        return ($row[3]===5)===$allowed;
    }

    private static function normalize(mixed $frame,array $binding,string $namespace,int $now):?array
    {
        if(!is_array($frame)||count($frame)!==4||array_diff(array_keys($frame),['v','n','d','r'])
            ||($frame['v']??null)!==self::VERSION||($frame['n']??null)!==$namespace||($frame['d']??null)!==$binding['digest']
            ||!is_array($frame['r']??null)||!array_is_list($frame['r'])||count($frame['r'])>self::MAX_ROWS)return null;
        $seen=[];
        foreach($frame['r']as$row){
            if(!self::rowValid($row,$binding,$now)||isset($seen[$row[0]]))return null;
            $seen[$row[0]]=true;
        }
        try{if(strlen(json_encode($frame,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>self::MAX_BYTES)return null;}
        catch(JsonException $ignored){return null;}
        return $frame;
    }
}
