<?php
declare(strict_types=1);
require_once __DIR__.'/MaxTrackerLookupProtocol.php';
require_once __DIR__.'/ModeledConversionAttributionWindow.php';

/** Pure private receipts for one finite read-only check. Never supplies a financial identity or permission. */
final class ModeledConversionValueVisitEvidence
{
    public const VERSION='value_finite_visit_evidence_v1';
    public const MAX_ROWS=3;
    public const MAX_BYTES=2048;
    public const CHECKPOINT=1794395518;
    public static function candidateReference(string $ref,string $client,string $record,int $sourceAt,string $provenance):?string
    {
        if(preg_match('/\A[a-f0-9]{64}\z/D',$ref)!==1||!MaxTrackerLookupProtocol::id($client,40)
            ||!MaxTrackerLookupProtocol::id($record)||$sourceAt<946684800
            ||!in_array($provenance,['canonical_max_lookup','retained_accepted_processed_max_binding'],true))return null;
        return hash('sha256',json_encode([self::VERSION,'98615635',$ref,$client,$record,$sourceAt,$provenance],JSON_THROW_ON_ERROR));
    }
    /** Returns private validated compact data, never a public projection. */
    public static function decode(mixed $frame,string $namespace,int $now):?array
    {
        if(!is_array($frame)||count($frame)!==4||array_diff(array_keys($frame),['v','n','b','r'])
            ||($frame['v']??null)!==self::VERSION||($frame['n']??null)!==$namespace
            ||preg_match('/\A[a-f0-9]{64}\z/D',$namespace)!==1||($frame['b']??null)!==self::CHECKPOINT
            ||!is_array($frame['r']??null)||!array_is_list($frame['r'])||count($frame['r'])!==self::MAX_ROWS)return null;
        try{if(strlen(json_encode($frame,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>self::MAX_BYTES)return null;}
        catch(Throwable $ignored){return null;}
        $seen=[];$ids=[];
        $clock=static fn(mixed $v):bool=>is_int($v)&&$v>=946684800&&$v<=$now;
        foreach($frame['r']as$row){
            if(!is_array($row)||!array_is_list($row)||count($row)!==16
                ||!is_string($row[0])||preg_match('/\A[a-f0-9]{64}\z/D',$row[0])!==1||isset($seen[$row[0]])
                ||!is_string($row[1])||!MaxTrackerLookupProtocol::id($row[1])||isset($ids[$row[1]])
                ||!is_int($row[2])||!in_array($row[2],[0,1,2,3],true)
                ||!is_int($row[5])||$row[5]<0||$row[5]>8
                ||!is_int($row[10])||!in_array($row[10],[0,1,2],true)
                ||!is_int($row[12])||$row[12]<0||$row[12]>4
                ||!is_int($row[14])||!in_array($row[14],[0,1,2],true)
                ||!$clock($row[15])||$row[15]>self::CHECKPOINT)return null;
            foreach([3,4,8,13]as$i)if($row[$i]!==null&&!$clock($row[$i]))return null;
            if(($row[3]!==null&&$row[3]<$row[15])||($row[4]!==null&&$row[4]<$row[15])
                ||($row[3]!==null&&$row[4]!==null&&$row[4]<$row[3])
                ||($row[8]!==null&&$row[8]>$row[15])||($row[13]!==null&&$row[13]>$row[15]))return null;
            $candidate=in_array($row[5],[1,2],true);
            if($candidate){
                $kind=$row[5]===1?'canonical_max_lookup':'retained_accepted_processed_max_binding';
                if(!is_string($row[6])||!is_string($row[7])||!is_int($row[8])||!is_string($row[9])
                    ||self::candidateReference($row[0],$row[6],$row[7],$row[8],$kind)!==$row[9])return null;
            }elseif($row[6]!==null||$row[7]!==null||$row[8]!==null||$row[9]!==null)return null;
            if($row[2]===0){
                if($row[3]!==null||$row[4]!==null||$row[5]!==0||$row[10]!==0)return null;
            }elseif($row[2]===1){
                if(!$candidate||$row[3]===null||$row[4]!==null||$row[10]!==0)return null;
            }elseif($row[2]===3){
                if($row[5]!==7||$row[3]!==null||$row[4]===null||$row[10]!==0)return null;
            }else{
                if($row[4]===null||$row[5]===0||$row[5]===7)return null;
                if($candidate){
                    if($row[3]===null||!in_array($row[10],[1,2],true))return null;
                }elseif($row[3]!==null||$row[10]!==0)return null;
            }
            if($row[10]===0){if($row[11]!==null||$row[12]!==0||$row[13]!==null||$row[14]!==0)return null;}
            elseif($row[10]===2){if($row[11]!==false||$row[12]!==4||$row[13]!==null||$row[14]!==0)return null;}
            elseif(!is_bool($row[11])||!in_array($row[12],[1,2,3],true)
                ||($row[11]!==($row[12]===1))||($row[13]===null)!==($row[12]===3)
                ||!in_array($row[14],[1,2],true))return null;
            if($row[10]===1){
                $seconds=$row[14]===1?ModeledConversionAttributionWindow::MAX_SECONDS:ModeledConversionAttributionWindow::UNKNOWN_SECONDS;
                $allowed=$row[13]!==null&&$row[15]-$row[13]<=$seconds&&$row[4]-$row[13]<=$seconds;
                $reason=$row[13]===null?3:($allowed?1:2);
                if($row[11]!==$allowed||$row[12]!==$reason)return null;
            }
            $seen[$row[0]]=true;$ids[$row[1]]=true;
        }
        return $frame;
    }
    public static function projection(mixed $frame,string $namespace,int $now):array
    {
        $fail=static fn(string $reason):array=>['available'=>false,'reason'=>$reason,'provider_requests_in_reader'=>0];
        if($frame===false)return $fail('retained_budget_tombstone_no_automatic_retry');
        if($frame===null)return $fail('not_recorded');
        $value=self::decode($frame,$namespace,$now);if($value===null)return $fail('invalid_saved_frame');
        $sources=[0=>'not_observed',1=>'canonical_max_identity',2=>'trusted_retained_max_identity',
            3=>'no_valid_canonical_identity_and_no_admissible_retained_identity',4=>'retained_identity_ambiguous',
            5=>'source_check_unavailable',6=>'source_binding_invalid',7=>'already_uploaded_or_unknown_not_rechecked',8=>'current_scope_unavailable'];
        $states=['pending','consumed_unknown','observed','skipped_existing_receipt'];
        $reasons=[0=>null,1=>'within_current_window',2=>'outside_current_window',3=>'no_prior_visit',4=>'visit_report_unavailable'];
        $at=static fn(?int $v):?string=>$v===null?null:gmdate('Y-m-d\TH:i:s\Z',$v);
        $out=['available'=>true,'basis'=>'saved_once_only_readonly_source_and_visit_checks',
            'checkpoint'=>gmdate('Y-m-d\TH:i:s\Z',self::CHECKPOINT),'assessment_namespace'=>$namespace,
            'complete'=>true,'automatic_attempts_finished'=>true,'logical_check_budget_per_tick'=>2,
            'checks_consumed'=>0,'rows'=>[],'provider_requests_in_reader'=>0];
        foreach($value['r']as$row){
            $out['complete']=$out['complete']&&in_array($row[2],[2,3],true);
            $out['automatic_attempts_finished']=$out['automatic_attempts_finished']&&$row[2]!==0;
            if($row[3]!==null)$out['checks_consumed']++;
            $out['rows'][]=['event_ref'=>$row[0],'event_at'=>$at($row[15]),'attempt_status'=>$states[$row[2]],
                'source_status'=>$sources[$row[5]],'source_provenance'=>$row[5]===1?'canonical_max_lookup':($row[5]===2?'retained_accepted_processed_max_binding':null),
                'candidate_sha256'=>$row[9],'source_observed_at'=>$at($row[8]),'checked_at'=>$at($row[4]??$row[3]),
                'report_status'=>$row[2]===1?'unknown':([0=>'not_requested',1=>'ok',2=>'unavailable'][$row[10]]),
                'window_allowed'=>$row[11],'window_reason'=>$reasons[$row[12]],'selected_prior_visit_at'=>$at($row[13]),
                'source_class'=>$row[14]===1?'yandex_direct':($row[14]===2?'other_or_unknown':'unverified')];
        }
        return $out;
    }
}
