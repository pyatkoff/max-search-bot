<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionRetainedAppIdentityEvidence.php';
/** Distinct one-case retained-click observation; no identity promotion or financial authority. */
final class ModeledConversionRetainedTrackerIdentityEvidence
{
    public const VERSION='retained_tracker_candidate_v1';
    public const RECEIPT_KEY='finite_retained_tracker_candidate';
    public const MAX_BYTES=768;
    private const STATUS=['observed_no_candidate','candidate','ambiguous','unsupported_source','row_limit','packet_budget',
        'invalid_source','storage_unavailable','point_index_unavailable','time_headroom','unsupported_execution_limit'];
    public static function context(mixed $frame,array $row,string $ns,int $now):?array
    {
        $b=ModeledConversionRetainedAppIdentityEvidence::binding($frame,$row,$ns,$now);
        return $b===null?null:['v'=>1,'user_id'=>$row['external_user_id'],'event_ref'=>$b['ref'],'frame_sha256'=>$b['digest'],'event_at_unix'=>(int)$row['event_unix']];
    }
    public static function intent(mixed $frame,array $row,string $ns,string $hash,int $now):?array
    {
        $b=ModeledConversionRetainedAppIdentityEvidence::binding($frame,$row,$ns,$now);
        if($b===null||$now<$b['checked']||preg_match('/\A[a-f0-9]{64}\z/D',$hash)!==1)return null;
        $s=['v'=>self::VERSION,'n'=>$ns,'d'=>$b['digest'],'e'=>$b['ref'],'h'=>$hash,'t'=>$now,'s'=>1,'o'=>''];
        $s['o']=str_repeat('p',self::MAX_BYTES-strlen(json_encode($s,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)));
        return $s;
    }
    public static function complete(array $intent,mixed $frame,array $row,string $ns,mixed $response,int $now):?array
    {
        $s=self::decode($intent,$frame,$row,$ns,$now);
        if($s===null||$s['s']!==1||!is_array($response)||count($response)!==3
            ||array_diff(array_keys($response),['request_sha256','result','received_at_unix'])||($response['request_sha256']??null)!==$s['h']
            ||!is_int($response['received_at_unix']??null)||$response['received_at_unix']<$s['t']||$response['received_at_unix']>$now)return null;
        $wire=['v'=>1,'request_sha256'=>$s['h'],'result'=>$response['result']??null];
        $r=MaxTrackerLookupProtocol::retainedClickResult($wire,$s['h'],$response['received_at_unix'],(int)$row['event_unix']);
        if($r===null)return null;$s['s']=2;$s['o']=[...$r,$response['received_at_unix']];
        return self::decode($s,$frame,$row,$ns,$now);
    }
    public static function projection(mixed $saved,mixed $frame,array $row,string $ns,int $now):array
    {
        $fail=static fn(string $r):array=>['available'=>false,'reason'=>$r,'financial_permission'=>false,'provider_requests_in_reader'=>0];
        if($saved===null)return $fail('not_recorded');if($saved===false)return $fail('retained_tombstone_no_automatic_retry');
        $s=self::decode($saved,$frame,$row,$ns,$now);if($s===null)return $fail('invalid_saved_journal');
        $b=ModeledConversionRetainedAppIdentityEvidence::binding($frame,$row,$ns,$now);
        $out=['available'=>true,'case_index'=>$b['index'],'status'=>'consumed_unknown','financial_permission'=>false];
        if($s['s']===1)return $out;$r=$s['o'];$out['status']=self::STATUS[$r[0]];
        if($r[0]===1){$out['kind']=$r[4]===2?'yclid':'client_id';$out['candidate_ref']=$r[5];$out['recorded_click_at_unix']=$r[8];
            $out['first_saved_at_unix']=null;$out['first_enriched_at_unix']=null;}
        return $out;
    }
    private static function decode(mixed $s,mixed $frame,array $row,string $ns,int $now):?array
    {
        if(!is_array($s)||count($s)!==8||array_diff(array_keys($s),['v','n','d','e','h','t','s','o'])||($s['v']??null)!==self::VERSION
            ||($s['n']??null)!==$ns||!is_string($s['h']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$s['h'])!==1
            ||!is_int($s['t']??null)||$s['t']>$now||!in_array($s['s']??null,[1,2],true)||!array_key_exists('o',$s)
            ||strlen(json_encode($s,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>self::MAX_BYTES)return null;
        $e=self::intent($frame,$row,$ns,$s['h'],$s['t']);if($e===null||$s['d']!==$e['d']||$s['e']!==$e['e'])return null;
        if($s['s']===1)return $s['o']===$e['o']?$s:null;
        $r=$s['o'];if(!is_array($r)||!array_is_list($r)||count($r)!==10||!is_int($r[9])||$r[9]<$s['t']||$r[9]>$now)return null;
        return MaxTrackerLookupProtocol::retainedClickResult(['v'=>1,'request_sha256'=>$s['h'],'result'=>array_slice($r,0,9)],$s['h'],$r[9],(int)$row['event_unix'])===null?null:$s;
    }
}
