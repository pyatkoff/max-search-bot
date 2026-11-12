<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionRetainedAppIdentityEvidence.php';
require_once __DIR__.'/ModeledConversionFixedPairDecisionEvidence.php';
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
        if($r[0]===0){$out['prefix_rows']=$r[2];$out['postevent_rows']=$r[3];$out['source_checked_at_unix']=$r[1];$out['app_received_at_unix']=$r[9];}
        if($r[0]===1){$out['kind']=$r[4]===2?'yclid':'client_id';$out['candidate_ref']=$r[5];$out['recorded_click_at_unix']=$r[8];
            $out['first_saved_at_unix']=null;$out['first_enriched_at_unix']=null;}
        return $out;
    }

    public const KNOWN_KEYS=['finite_retained_tracker_known_a','finite_retained_tracker_known_b'];
    private const KNOWN_VERSION='retained_tracker_known_cid_v1';
    /** The original two canonical candidates are fixed by the existing frame, independently of the missing case. */
    public static function knownBinding(mixed $frame,array $row,string $ns,int $now):?array
    {
        if(($row['project_key']??null)!=='anytour'||($row['platform']??null)!=='max'||(string)($row['counter_id']??'')!=='98615635'
            ||!MaxTrackerLookupProtocol::id($row['external_user_id']??null))return null;
        $cases=ModeledConversionValueVisitRecovery::originals($frame,$ns,$now);
        $ref=ModeledConversionValueIdentityEvidence::reference($row,$ns);
        if($cases===null||$ref===null||!isset($cases[$ref]))return null;
        foreach($cases as$case)if($case[5]!==1)return null;
        $old=$cases[$ref];$slot=array_search($ref,array_keys($cases),true);
        if((string)$row['id']!==$old[1]||(int)$row['event_unix']!==$old[15]||$old[5]!==1)return null;
        $f=ModeledConversionValueVisitEvidence::decode($frame,$ns,$now);
        return ['ref'=>$ref,'key'=>self::KNOWN_KEYS[$slot],'index'=>array_search($ref,array_column($f['r'],0),true),
            'digest'=>ModeledConversionValueVisitRecovery::originalDigest($frame,$ns,$now),'held_client_id'=>$old[6],'checked'=>$old[4]];
    }
    public static function knownContext(mixed $frame,array $row,string $ns,int $now):?array
    {
        $b=self::knownBinding($frame,$row,$ns,$now);
        return $b===null?null:['v'=>1,'user_id'=>$row['external_user_id'],'event_ref'=>$b['ref'],'frame_sha256'=>$b['digest'],'event_at_unix'=>(int)$row['event_unix']];
    }
    public static function knownIntent(mixed $frame,array $row,string $ns,string $hash,int $now,mixed $pair):?array
    {
        $b=self::knownBinding($frame,$row,$ns,$now);if($b===null)return null;
        $saved=ModeledConversionFixedPairDecisionEvidence::merge($pair,$frame,$ns,[],$now);if($saved===null)return null;
        foreach($saved['r']as$r)if($r[0]===$b['ref']&&$r[3]===7)return self::knownTemplate($b,$ns,$hash,$now,[$r[1],$r[2]]);
        return null;
    }
    private static function knownTemplate(array $b,string $ns,string $hash,int $now,mixed $proof):?array
    {
        if(preg_match('/\A[a-f0-9]{64}\z/D',$hash)!==1||!is_array($proof)||!array_is_list($proof)||count($proof)!==2
            ||!is_int($proof[0])||!is_int($proof[1])||$proof[0]<$b['checked']||$proof[1]<$proof[0]||$proof[1]>$now)return null;
        $s=['v'=>self::KNOWN_VERSION,'n'=>$ns,'d'=>$b['digest'],'e'=>$b['ref'],'h'=>$hash,'t'=>$now,'p'=>$proof,'s'=>1,'o'=>''];
        $s['o']=str_repeat('p',self::MAX_BYTES-strlen(json_encode($s,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)));return $s;
    }
    public static function knownComplete(array $intent,mixed $frame,array $row,string $ns,mixed $response,int $now):?array
    {
        $s=self::knownDecode($intent,$frame,$row,$ns,$now);
        if($s===null||$s['s']!==1||!is_array($response)||count($response)!==3
            ||array_diff(array_keys($response),['request_sha256','result','received_at_unix'])||($response['request_sha256']??null)!==$s['h']
            ||!is_int($response['received_at_unix']??null)||$response['received_at_unix']<$s['t']||$response['received_at_unix']>$now)return null;
        $r=MaxTrackerLookupProtocol::retainedClickResult(['v'=>1,'request_sha256'=>$s['h'],'result'=>$response['result']??null],$s['h'],$response['received_at_unix'],(int)$row['event_unix']);
        if($r===null)return null;$s['s']=2;$s['o']=[...$r,$response['received_at_unix']];return self::knownDecode($s,$frame,$row,$ns,$now);
    }
    public static function knownProjection(mixed $saved,mixed $frame,array $row,string $ns,int $now):array
    {
        $fail=static fn(string $r):array=>['available'=>false,'reason'=>$r,'financial_permission'=>false];
        $b=self::knownBinding($frame,$row,$ns,$now);if($b===null)return $fail('original_known_binding_unavailable');
        if($saved===null)return ['case_index'=>$b['index']]+$fail('not_recorded');
        if($saved===false)return ['case_index'=>$b['index']]+$fail('retained_tombstone_no_automatic_retry');
        $s=self::knownDecode($saved,$frame,$row,$ns,$now);if($s===null)return $fail('invalid_saved_journal');
        $out=['available'=>true,'case_index'=>$b['index'],'status'=>'consumed_unknown','financial_permission'=>false];
        if($s['s']===1)return $out;$r=$s['o'];$out['status']=self::STATUS[$r[0]];
        if($r[0]<=2){$out['prefix_rows']=$r[2];$out['postevent_rows']=$r[3];$out['source_checked_at_unix']=$r[1];}
        if($r[0]===1){
            $out['kind']=$r[4]===2?'yclid':'client_id';$out['candidate_ref']=$r[5];$out['recorded_click_at_unix']=$r[8];
            // Exactly one already held local value is tested, using the returned type; no alternate guesses.
            $expected=hash('sha256',json_encode([MaxTrackerLookupProtocol::RETAINED_CLICK_HEADER,$s['h'],self::knownContext($frame,$row,$ns,$now),
                $r[4],$b['held_client_id'],$r[6],$r[7],$r[8]],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            $out['held_value_match']=hash_equals($expected,$r[5]);
            $out['first_saved_at_unix']=null;$out['first_enriched_at_unix']=null;
        }return $out;
    }
    private static function knownDecode(mixed $s,mixed $frame,array $row,string $ns,int $now):?array
    {
        if(!is_array($s)||count($s)!==9||array_diff(array_keys($s),['v','n','d','e','h','t','p','s','o'])||($s['v']??null)!==self::KNOWN_VERSION
            ||($s['n']??null)!==$ns||!is_int($s['t']??null)||$s['t']>$now||!in_array($s['s']??null,[1,2],true)
            ||!is_string($s['h']??null)||!array_key_exists('p',$s)||!array_key_exists('o',$s)
            ||strlen(json_encode($s,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>self::MAX_BYTES)return null;
        $b=self::knownBinding($frame,$row,$ns,$now);if($b===null)return null;
        $e=self::knownTemplate($b,$ns,$s['h'],$s['t'],$s['p']);if($e===null||$s['d']!==$e['d']||$s['e']!==$e['e'])return null;
        if($s['s']===1)return $s['o']===$e['o']?$s:null;
        $r=$s['o'];if(!is_array($r)||!array_is_list($r)||count($r)!==10||!is_int($r[9])||$r[9]<$s['t']||$r[9]>$now)return null;
        return MaxTrackerLookupProtocol::retainedClickResult(['v'=>1,'request_sha256'=>$s['h'],'result'=>array_slice($r,0,9)],$s['h'],$r[9],(int)$row['event_unix'])===null?null:$s;
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
