<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionValueContract.php';
require_once __DIR__.'/ModeledConversionValueVisitEvidence.php';
require_once __DIR__.'/ModeledConversionValueIdentityEvidence.php';

/** Pure finite receipt templates. The worker must persist an absent-key CAS before opt-in transport. */
final class ModeledConversionMaxCounterScopeEvidence
{
    public const VERSION='max_counter_scope_evidence_v1';
    public const MAX_BYTES=512;
    private const REASONS=['unsupported','invalid_response','invalid_request','storage_unavailable','point_index_unavailable','row_limit','intent_not_persisted'];

    public static function intent(mixed $original,array $ledger,string $namespace,string $requestHash,int $now):?array
    {
        $binding=self::binding($original,$ledger,$namespace,$now);
        if($binding===null||preg_match('/\A[a-f0-9]{64}\z/D',$requestHash)!==1||$now<$binding['checked'])return null;
        return ['v'=>self::VERSION,'n'=>$namespace,'d'=>$binding['digest'],'e'=>$binding['ref'],'h'=>$requestHash,'t'=>$now,'s'=>1,'o'=>null];
    }

    /** Only the matching ordinary response can finish a durable intent; unknown is never a retry permit. */
    public static function complete(mixed $journal,mixed $original,array $ledger,string $namespace,mixed $evidence,int $now):?array
    {
        $saved=self::decode($journal,$original,$ledger,$namespace,$now);
        if($saved===null||$saved['s']!==1||!is_array($evidence)||($evidence['request_sha256']??null)!==$saved['h'])return null;
        if(($evidence['available']??null)===true){
            $keys=['available','basis','request_sha256','checked_at_unix','complete_within_bound','local_scope_zero_is_not_global_tracker_absence',
                'financial_permission','scoped_rows','empty_client_id_rows','noncanonical_client_id_rows','canonical_client_id_rows'];
            $actual=array_keys($evidence);sort($actual);sort($keys);
            if($actual!==$keys||$evidence['basis']!=='exact_effective_counter_scope_only'||$evidence['complete_within_bound']!==true
                ||$evidence['local_scope_zero_is_not_global_tracker_absence']!==true||$evidence['financial_permission']!==false
                ||!is_int($evidence['checked_at_unix'])||$evidence['checked_at_unix']<$saved['t']||$evidence['checked_at_unix']>$now)return null;
            $counts=[];foreach(['scoped_rows','empty_client_id_rows','noncanonical_client_id_rows','canonical_client_id_rows']as$key){
                $v=$evidence[$key];if(!is_int($v)||$v<0||$v>64)return null;$counts[]=$v;
            }
            if($counts[1]+$counts[2]+$counts[3]!==$counts[0])return null;
            $saved['o']=array_merge([1,$evidence['checked_at_unix']],$counts);
        }elseif(($evidence['available']??null)===false){
            $reason=array_search($evidence['reason']??null,self::REASONS,true);if($reason===false)return null;
            $saved['o']=[0,$now,$reason];
        }else return null;
        $saved['s']=2;
        return self::decode($saved,$original,$ledger,$namespace,$now);
    }

    public static function projection(mixed $journal,mixed $original,array $ledger,string $namespace,int $now):array
    {
        $fail=static fn(string $reason):array=>['available'=>false,'reason'=>$reason,'financial_permission'=>false,'provider_requests_in_reader'=>0];
        if($journal===false)return array_merge($fail('retained_tombstone_no_automatic_retry'),['attempt_status'=>'consumed_unknown']);
        if($journal===null)return $fail('not_recorded');
        $saved=self::decode($journal,$original,$ledger,$namespace,$now);if($saved===null)return $fail('invalid_saved_journal');
        $out=['available'=>true,'basis'=>'finite_same_request_exact_counter_scope_evidence','event_ref'=>$saved['e'],
            'original_frame_sha256'=>$saved['d'],'request_sha256'=>$saved['h'],'intent_at'=>gmdate('Y-m-d\TH:i:s\Z',$saved['t']),
            'attempt_status'=>'consumed_unknown','checked_at'=>null,'complete_within_bound'=>false,
            'scoped_rows'=>null,'empty_client_id_rows'=>null,'noncanonical_client_id_rows'=>null,'canonical_client_id_rows'=>null,
            'local_scope_zero_is_not_global_tracker_absence'=>true,'financial_permission'=>false,'provider_requests_in_reader'=>0];
        if($saved['s']===2){
            $result=$saved['o'];$out['checked_at']=gmdate('Y-m-d\TH:i:s\Z',$result[1]);
            if($result[0]===1){
                $out['attempt_status']='observed';$out['complete_within_bound']=true;
                foreach(['scoped_rows','empty_client_id_rows','noncanonical_client_id_rows','canonical_client_id_rows']as$i=>$key)$out[$key]=$result[$i+2];
            }else{$out['attempt_status']='unavailable';$out['reason']=self::REASONS[$result[2]];}
        }
        return $out;
    }

    private static function decode(mixed $saved,mixed $original,array $ledger,string $namespace,int $now):?array
    {
        if(!is_array($saved)||count($saved)!==8||($saved['v']??null)!==self::VERSION||($saved['n']??null)!==$namespace
            ||!is_string($saved['h']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$saved['h'])!==1
            ||!is_int($saved['t']??null)||$saved['t']>$now||!in_array($saved['s']??null,[1,2],true)||!array_key_exists('o',$saved))return null;
        try{if(strlen(json_encode($saved,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>self::MAX_BYTES)return null;}
        catch(JsonException $ignored){return null;}
        $binding=self::binding($original,$ledger,$namespace,$now);
        if($binding===null||($saved['d']??null)!==$binding['digest']||($saved['e']??null)!==$binding['ref']||$saved['t']<$binding['checked'])return null;
        if($saved['s']===1)return $saved['o']===null?$saved:null;
        $result=$saved['o'];
        if(!is_array($result)||!array_is_list($result)||!is_int($result[1]??null)||$result[1]<$saved['t']||$result[1]>$now)return null;
        if(($result[0]??null)===0)return count($result)===3&&is_int($result[2])&&isset(self::REASONS[$result[2]])?$saved:null;
        if(($result[0]??null)!==1||count($result)!==6)return null;
        foreach(array_slice($result,2)as$count)if(!is_int($count)||$count<0||$count>64)return null;
        return $result[3]+$result[4]+$result[5]===$result[2]?$saved:null;
    }

    private static function binding(mixed $original,array $ledger,string $namespace,int $now):?array
    {
        if(($ledger['project_key']??null)!=='anytour'||($ledger['platform']??null)!=='max'||(string)($ledger['counter_id']??'')!=='98615635'
            ||!is_string($ledger['external_user_id']??null)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$ledger['external_user_id'])!==1)return null;
        $raw=ModeledConversionValueVisitEvidence::decode($original,$namespace,$now);
        $ref=ModeledConversionValueIdentityEvidence::reference($ledger,$namespace);
        if($raw===null||$ref===null)return null;$missing=[];
        foreach($raw['r']as$row)if($row[2]===2&&$row[5]===3)$missing[]=$row;
        if(count($missing)!==1||$missing[0][0]!==$ref||(string)$missing[0][1]!==(string)$ledger['id']
            ||$missing[0][15]!==($event=(int)($ledger['event_unix']??0))||$event<946684800||!is_int($missing[0][4]))return null;
        return ['ref'=>$ref,'checked'=>$missing[0][4],'digest'=>hash('sha256',json_encode([$raw['v'],$raw['n'],$raw['b'],$raw['r']],
            JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];
    }
}
