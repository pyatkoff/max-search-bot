<?php
declare(strict_types=1);

/** Pure bounded observations of already required receipt GETs. Never polls, authorizes money or schedules retries. */
final class ModeledConversionReceiptPollEvidence
{
    public const MAX_ROWS=2;
    public const MAX_BYTES=512;
    private const STATUSES=['PREPARED','UPLOADED','EXPORTED','MATCHED','PROCESSED','LINKAGE_FAILURE'];
    private const STAGES=[1=>'transport_failure',2=>'http_failure',3=>'invalid_json',4=>'invalid_envelope',
        5=>'invalid_receipt',6=>'receipt_binding_mismatch',7=>'valid_provider_receipt'];

    public static function begin(int $tick):array
    {
        if($tick<946684800)throw new InvalidArgumentException('poll_evidence_clock');
        return ['v'=>1,'t'=>$tick,'c'=>true,'r'=>[]];
    }
    public static function append(mixed $audit,mixed $proof,int $now):?array
    {
        $out=self::decode($audit,$now);if($out===null)return null;
        $keys=['v','counter_id','receipt_ref','checked_at_unix','stage','http_status','transport_error_code','provider_status'];
        if(!is_array($proof)||count($proof)!==count($keys)||array_diff(array_keys($proof),$keys)||($proof['v']??null)!==1
            ||($proof['counter_id']??null)!=='98615635'){$out['c']=false;return $out;}
        $status=array_search($proof['provider_status']??null,self::STATUSES,true);
        $row=[$proof['receipt_ref']??null,$proof['checked_at_unix']??null,$proof['stage']??null,
            $proof['http_status']??null,$proof['transport_error_code']??null,$status===false?0:$status+1];
        if(($proof['provider_status']??null)!==null&&$status===false){$out['c']=false;return $out;}
        $candidate=$out;$candidate['r']=[$row];
        if(self::decode($candidate,$now)===null){$out['c']=false;return $out;}
        foreach($out['r']as$seen)if($seen[0]===$row[0]){$out['c']=false;return $out;}
        if(count($out['r'])>=self::MAX_ROWS){$out['c']=false;return $out;}
        $out['r'][]=$row;return self::decode($out,$now);
    }
    public static function projection(mixed $audit,int $now):array
    {
        $fail=static fn(string $reason):array=>['available'=>false,'reason'=>$reason,'provider_requests_in_reader'=>0];
        if($audit===null)return $fail('not_recorded');
        $raw=self::decode($audit,$now);if($raw===null)return $fail('invalid_saved_poll_evidence');
        $out=['available'=>true,'basis'=>'saved_observations_of_existing_ordinary_receipt_polls',
            'tick_at'=>gmdate('Y-m-d\TH:i:s\Z',$raw['t']),'max_receipts'=>self::MAX_ROWS,'observed_receipts'=>count($raw['r']),
            'complete_within_capture_bound'=>$raw['c'],'not_a_poll_of_every_accepted_receipt'=>true,'rows'=>[],'provider_requests_in_reader'=>0];
        foreach($raw['r']as$row)$out['rows'][]=['receipt_ref'=>$row[0],'checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$row[1]),
            'stage'=>self::STAGES[$row[2]],'http_status'=>$row[3],'transport_error_code'=>$row[4],
            'provider_status'=>$row[5]===0?null:self::STATUSES[$row[5]-1]];
        return $out;
    }
    private static function decode(mixed $audit,int $now):?array
    {
        if(!is_array($audit)||count($audit)!==4||array_diff(array_keys($audit),['v','t','c','r'])||($audit['v']??null)!==1
            ||!is_int($audit['t']??null)||$audit['t']<946684800||$audit['t']>$now||!is_bool($audit['c']??null)
            ||!is_array($audit['r']??null)||!array_is_list($audit['r'])||count($audit['r'])>self::MAX_ROWS)return null;
        try{if(strlen(json_encode($audit,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>self::MAX_BYTES)return null;}
        catch(JsonException $ignored){return null;}
        $seen=[];
        foreach($audit['r']as$row){
            if(!is_array($row)||!array_is_list($row)||count($row)!==6||!is_string($row[0])
                ||preg_match('/\A[a-f0-9]{64}\z/D',$row[0])!==1||isset($seen[$row[0]])
                ||!is_int($row[1])||$row[1]<$audit['t']||$row[1]>$now||!is_int($row[2])||!isset(self::STAGES[$row[2]])
                ||($row[3]!==null&&(!is_int($row[3])||$row[3]<100||$row[3]>599))
                ||($row[4]!==null&&(!is_int($row[4])||$row[4]<0||$row[4]>100))
                ||!is_int($row[5])||$row[5]<0||$row[5]>6||($row[2]===7?($row[5]===0):($row[5]!==0)))return null;
            $seen[$row[0]]=true;
        }
        return $audit;
    }
}
