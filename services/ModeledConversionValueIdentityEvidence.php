<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionValueContract.php';
require_once __DIR__.'/ModeledConversionAttributionWindow.php';
require_once __DIR__.'/MaxTrackerLookupProtocol.php';

/** Pure bounded private evidence, never an identity source or a delivery decision. */
final class ModeledConversionValueIdentityEvidence
{
    public const VERSION='value_identity_evidence_v1';
    public const MAX_ROWS=16;
    public const MAX_BYTES=2048;
    public static function reference(array $row,string $namespace):?string
    {
        if(preg_match('/\A[a-f0-9]{64}\z/D',$namespace)!==1)return null;
        foreach(['id','value_profile_id','conversation_id','external_user_id','event_unix']as$key){
            $v=$row[$key]??null;
            if(!is_int($v)&&!is_string($v))return null;
            if(preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$v)!==1)return null;
        }
        if(($row['project_key']??null)!=='anytour'||!in_array($row['platform']??null,['max','telegram'],true)
            ||(string)($row['counter_id']??'')!=='98615635'
            ||($row['transport_target']??null)!==ModeledConversionValueContract::TARGET
            ||($row['value_contract']??null)!==ModeledConversionValueContract::VERSION
            ||($row['target']??null)!==ModeledConversionValueContract::predictionKey((string)$row['value_profile_id']))return null;
        $money=$row['value_rub']??null;
        if(!is_int($money)&&!is_float($money)&&!is_string($money))return null;
        if(!preg_match('/\A[0-9]+(?:\.0{1,2})?\z/D',(string)$money))return null;
        $value=(float)$money;
        if(!is_finite($value)||$value<1||$value>ModeledConversionValueContract::MAX_VALUE_RUB||$value!==floor($value))return null;
        $scope=[$namespace,'anytour',(string)$row['id'],(string)$row['value_profile_id'],$row['platform'],
            (string)$row['conversation_id'],(string)$row['external_user_id'],'98615635',$row['target'],
            ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,(string)$row['event_unix'],(string)(int)$value];
        return hash('sha256',json_encode([self::VERSION,$scope],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    public static function reason(int $code):?string
    {
        return [1=>'confirmed_not_found',2=>'timestamp_missing',3=>'source_after_signal',
            4=>'source_too_old_at_signal',5=>'source_expired_now',6=>'invalid_binding',7=>'resolved'][$code]??null;
    }
    /** Called only after an actual successful lookup and successful pending-row UPDATE. */
    public static function classifyMax(array $row,mixed $record,bool $keyPresent,int $now):array
    {
        if(!$keyPresent)return [6,null];
        if($record===null)return [1,null];
        if(!is_array($record)||($record['external_user_id']??null)!==($row['external_user_id']??null)
            ||!MaxTrackerLookupProtocol::id($record['client_id']??null,40)
            ||!MaxTrackerLookupProtocol::id($record['record_id']??null))return [6,null];
        $at=$record['observed_at_unix']??null;
        if($at===null)return [2,null];
        if(!is_int($at)||$at<946684800||$at>$now)return [6,null];
        $signal=(int)($row['event_unix']??0);
        if($at>$signal)return [3,$at];
        if($signal-$at>ModeledConversionAttributionWindow::MAX_SECONDS)return [4,$at];
        if($now-$at>ModeledConversionAttributionWindow::MAX_SECONDS)return [5,$at];
        return [7,$at];
    }
    /** Validate compact saved metadata without interpreting any source or querying a provider. */
    public static function normalize(mixed $audit,string $namespace,int $now):?array
    {
        if(!is_array($audit)||count($audit)!==4||array_diff(array_keys($audit),['v','n','c','r'])
            ||$audit['v']!==self::VERSION||$audit['n']!==$namespace
            ||preg_match('/\A[a-f0-9]{64}\z/D',$namespace)!==1
            ||!is_bool($audit['c'])||!is_array($audit['r'])||!array_is_list($audit['r'])
            ||count($audit['r'])>self::MAX_ROWS
            ||strlen(json_encode($audit,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>self::MAX_BYTES)return null;
        $rows=[];$seen=[];
        foreach($audit['r']as$row){
            if(!is_array($row)||!array_is_list($row)||count($row)!==4||!is_string($row[0])
                ||preg_match('/\A[a-f0-9]{64}\z/D',$row[0])!==1||isset($seen[$row[0]])
                ||!is_int($row[1])||$row[1]<946684800||$row[1]>$now
                ||!is_int($row[2])||self::reason($row[2])===null
                ||($row[3]!==null&&(!is_int($row[3])||$row[3]<946684800||$row[3]>$row[1])))return null;
            if(in_array($row[2],[1,2,6],true)!==($row[3]===null))return null;
            $seen[$row[0]]=true;
            $rows[]=['event_ref'=>$row[0],'checked_at_unix'=>$row[1],'reason_code'=>$row[2],
                'reason'=>self::reason($row[2]),'source_observed_unix'=>$row[3]];
        }
        return ['version'=>self::VERSION,'assessment_namespace'=>$namespace,'complete'=>$audit['c'],'rows'=>$rows];
    }
}
