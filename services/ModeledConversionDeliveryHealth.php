<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionStatusBudget.php';

/** Fixed-scope technical health; no capture, network, customer data or mutation. */
final class ModeledConversionDeliveryHealth
{
    private const TARGET='anytour_modeled_value_v1';
    private const CONTRACT='semantic_stage_value_25000_v1';
    public static function collect(PDO $pdo,int $now,?ModeledConversionStatusBudget $budget=null,?callable $authorize=null):array
    {
        $out=['schema_version'=>1,'project'=>'anytour','counter_id'=>'98615635','checked_at'=>null,'enabled'=>null,
            'status'=>'unavailable','flags'=>[],'last_tick_at'=>null,'profile_tick_at'=>null,
            'last_report_success_at'=>null,'last_report_failure_at'=>null,'report_failure_since_at'=>null];
        $guard=static function()use($budget,$authorize):void{$budget?->check();if($authorize!==null)$authorize();$budget?->check();};
        $guard();
        if($now<946684800||$now>253402300799){$out['flags']=['invalid_clock'];return$out;}
        $out['checked_at']=self::at($now);
        $query=static function(string $sql,array $args=[])use($pdo,$budget,$guard):array{
            $guard();$q=null;
            try{
                $q=$budget!==null?$budget->prepare($pdo,$sql):$pdo->prepare($sql);
                if(!$q||!$q->execute($args))throw new PDOException('health_storage');
                $rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$q=null;
                $budget?->consumeBytes(strlen(json_encode($rows,JSON_THROW_ON_ERROR)));return$rows;
            }finally{if($q!==null)$q->closeCursor();$guard();}
        };
        try{
            $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $length=static fn(string $c):string=>$mysql?'OCTET_LENGTH('.$c.')':'LENGTH(CAST('.$c.' AS BLOB))';
            $rows=$query("SELECT mode,goal_id,goal_intent,contract_version,activated_at_unix,last_tick_unix,last_report_success_unix,"
                ."report_failure_since_unix,".$length('last_receipt_json')." AS receipt_bytes,"
                ."CASE WHEN ".$length('last_receipt_json')."<=8192 THEN last_receipt_json ELSE NULL END AS receipt_json"
                ." FROM modeled_conversion_value_export_settings WHERE project_key='anytour' AND counter_id='98615635' LIMIT 1");
            if(count($rows)!==1){$out['flags']=['configuration_unavailable'];return$out;}$s=$rows[0];
            if(($s['mode']??null)==='off'){$out['enabled']=false;$out['status']='off';return$out;}
            if(($s['mode']??null)!=='send'||($s['goal_id']??null)!=='678187097'||($s['goal_intent']??null)!=='verified'
                ||($s['contract_version']??null)!==self::CONTRACT){$out['flags']=['configuration_unavailable'];return$out;}
            $out['enabled']=true;
            $activation=self::clock($s['activated_at_unix']??null,$now);
            if($activation===null){$out['flags']=['configuration_unavailable'];return$out;}
            $lastTick=self::clock($s['last_tick_unix']??null,$now);
            $lastSuccess=self::clock($s['last_report_success_unix']??null,$now);
            $failureSince=self::clock($s['report_failure_since_unix']??null,$now);
            $receipt=self::receipt($s['receipt_json']??null,$s['receipt_bytes']??null,8192);
            $out['last_tick_at']=self::at($lastTick);$out['report_failure_since_at']=self::at($failureSince);$flags=[];
            if($now-($lastTick??$activation)>900)$flags[]='delivery_tick_stale';
            if(array_key_exists('errors',$receipt)){
                if(!is_int($receipt['errors'])||$receipt['errors']<0||$receipt['errors']>1000000000)throw new UnexpectedValueException('invalid_metadata');
                if($receipt['errors']>0)$flags[]='delivery_tick_errors';
            }elseif($lastTick!==null)throw new UnexpectedValueException('invalid_metadata');
            $report=$receipt['metrika_report']??null;
            if(is_array($report)&&($report['goal_id']??null)==='678187097'&&($report['currency']??null)==='RUB'){
                $reportAt=self::isoClock($report['fetched_at']??null,$now);$status=$report['status']??null;
                if(in_array($status,['ok','sampled'],true)){if($lastSuccess===null)$lastSuccess=$reportAt;}
                elseif(in_array($status,['forbidden','unavailable'],true))$out['last_report_failure_at']=self::at($reportAt);
            }
            $out['last_report_success_at']=self::at($lastSuccess);
            if($failureSince!==null&&$failureSince<=$now-900)$flags[]='report_unavailable';
            $rows=$query("SELECT enabled,last_error_at_unix,".$length('last_receipt_json')." AS receipt_bytes,"
                ."CASE WHEN ".$length('last_receipt_json')."<=4096 THEN last_receipt_json ELSE NULL END AS receipt_json"
                ." FROM modeled_conversion_profiles_state WHERE project_key='anytour' LIMIT 1");
            if(count($rows)!==1)throw new UnexpectedValueException('invalid_metadata');$p=$rows[0];
            $profileEnabled=self::bit($p['enabled']??null);$profileError=self::clock($p['last_error_at_unix']??null,$now);
            $profileReceipt=self::receipt($p['receipt_json']??null,$p['receipt_bytes']??null,4096);
            $profileAt=self::clock($profileReceipt['observed_at_unix']??null,$now);
            if(!$profileEnabled)$flags[]='profile_disabled';
            if(!in_array($profileReceipt['status']??null,['profiled','idle'],true))$profileAt=null;
            $out['profile_tick_at']=self::at($profileAt);
            if($profileEnabled&&$now-($profileAt??$activation)>900)$flags[]='profile_tick_stale';
            if($profileEnabled&&$profileError!==null&&($profileAt===null||$profileError>$profileAt))$flags[]='profile_tick_errors';
            $scope="project_key='anytour' AND counter_id='98615635' AND transport_target=? AND value_contract=?";
            $exists=static fn(string $c):string=>'EXISTS(SELECT 1 FROM max_channel_conversion_outbox WHERE '.$scope.' AND '.$c.')';
            $sql='SELECT '.$exists("state='ready' AND value_ready_at_unix IS NOT NULL AND value_ready_at_unix<=? AND next_attempt_unix<=?").' AS ready_queue_stale,'
                .$exists("state IN ('uploading','unknown') AND claimed_at_unix IS NOT NULL AND claimed_at_unix<=?").' AS upload_outcome_unknown,'
                .$exists("state='failed'").' AS api_row_rejected,'
                .$exists('(value_ready_at_unix>? OR value_api_accepted_at_unix>? OR value_processed_at_unix>? OR claimed_at_unix>?)').' AS invalid_clock';
            $rows=$query($sql,[self::TARGET,self::CONTRACT,$now-600,$now,self::TARGET,self::CONTRACT,$now-900,
                self::TARGET,self::CONTRACT,self::TARGET,self::CONTRACT,$now+60,$now+60,$now+60,$now+60]);
            if(count($rows)!==1)throw new UnexpectedValueException('invalid_metadata');
            foreach(['ready_queue_stale','upload_outcome_unknown','api_row_rejected']as$key)if(self::bit($rows[0][$key]??null))$flags[]=$key;
            if(self::bit($rows[0]['invalid_clock']??null))throw new UnexpectedValueException('invalid_clock');
            $out['flags']=array_values(array_unique($flags));$out['status']=$out['flags']===[]?'ok':'degraded';$guard();return$out;
        }catch(PDOException|JsonException $ignored){$guard();$out['status']='unavailable';$out['flags']=['storage_unavailable'];return$out;}
        catch(UnexpectedValueException $error){$guard();$out['status']='unavailable';
            $out['flags']=[$error->getMessage()==='invalid_clock'?'invalid_clock':'health_metadata_unavailable'];return$out;}
    }
    /** Whitelisted receipt of the independent checker, never a credential route. */
    public static function externalStatus(ModeledConversionStatusBudget $budget,callable $authorize,int $now):array
    {
        $guard=static function()use($budget,$authorize):void{$budget->check();$authorize();$budget->check();};$guard();
        $path=dirname(__DIR__,2).'/max-search-bot-modeled-monitor/last-check.json';
        $out=['available'=>false,'reason'=>'not_recorded'];
        if(!is_link($path)&&is_file($path)&&is_readable($path)&&filesize($path)<=4096){
            $raw=file_get_contents($path);if(is_string($raw)){$budget->consumeBytes(strlen($raw));$r=json_decode($raw,true,16);
                if(is_array($r)&&($r['schema_version']??null)===1&&($r['project']??null)==='anytour'&&($r['counter_id']??null)==='98615635'
                    &&in_array($r['status']??null,['ok','off','degraded','unavailable'],true)
                    &&in_array($r['notification']??null,['sent','quiet','unknown'],true)){
                    try{$at=self::isoClock($r['checked_at']??null,$now);$last=self::isoClock($r['last_notification_at']??null,$now);
                        if($at!==null)$out=['available'=>true,'checked_at'=>self::at($at),'fresh'=>$now-$at<=900,
                            'status'=>$r['status'],'notification'=>$r['notification'],'last_notification_at'=>self::at($last)];
                    }catch(UnexpectedValueException $ignored){$out=['available'=>false,'reason'=>'invalid_metadata'];}
                }
            }
        }
        $guard();return$out;
    }
    private static function clock(mixed $v,int $now):?int
    {
        if($v===null||$v===''||$v===0||$v==='0')return null;
        if(!is_int($v)&&!(is_string($v)&&preg_match('/\A[0-9]{1,12}\z/D',$v)===1))throw new UnexpectedValueException('invalid_clock');
        $n=(int)$v;if($n<946684800||$n>$now+60)throw new UnexpectedValueException('invalid_clock');return$n;
    }
    private static function isoClock(mixed $v,int $now):?int
    {
        if($v===null||$v==='')return null;
        if(!is_string($v)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$v)!==1)
            throw new UnexpectedValueException('invalid_clock');
        $n=strtotime($v);if($n===false||gmdate('Y-m-d\TH:i:s\Z',$n)!==$v)throw new UnexpectedValueException('invalid_clock');
        return self::clock($n,$now);
    }
    private static function receipt(mixed $json,mixed $bytes,int $limit):array
    {
        if($bytes!==null&&(!is_numeric($bytes)||(int)$bytes<0||(int)$bytes>$limit))throw new UnexpectedValueException('invalid_metadata');
        if($json===null||$json==='')return[];
        if(!is_string($json)||strlen($json)>$limit)throw new UnexpectedValueException('invalid_metadata');
        $v=json_decode($json,true,32);if(json_last_error()!==JSON_ERROR_NONE||!is_array($v))throw new UnexpectedValueException('invalid_metadata');return$v;
    }
    private static function bit(mixed $v):bool
    {if($v===1||$v==='1'||$v===true)return true;if($v===0||$v==='0'||$v===false)return false;throw new UnexpectedValueException('invalid_metadata');}
    private static function at(?int $v):?string{return$v===null?null:gmdate('Y-m-d\TH:i:s\Z',$v);}
}
