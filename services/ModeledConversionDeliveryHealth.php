<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionStatusBudget.php';
require_once __DIR__.'/ModeledConversionPipelineMonitorStatus.php';

/** Fixed-scope technical health; no capture, network, customer data or mutation. */
final class ModeledConversionDeliveryHealth
{
    private const TARGET='anytour_modeled_value_v1';
    private const CONTRACT='semantic_stage_value_25000_v1';
    public static function collect(PDO $pdo,int $now,?ModeledConversionStatusBudget $budget=null,?callable $authorize=null):array
    {
        $out=['schema_version'=>1,'project'=>'anytour','counter_id'=>'98615635','checked_at'=>null,'enabled'=>null,
            'status'=>'unavailable','flags'=>[],'last_tick_at'=>null,'profile_tick_at'=>null,
            'last_report_success_at'=>null,'last_report_failure_at'=>null,'report_failure_since_at'=>null,'linkage_failures'=>null];
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
                ." FROM modeled_conversion_value_export_settings WHERE project_key='anytour' AND counter_id='98615635' AND HEX(project_key)=HEX('anytour') AND HEX(counter_id)=HEX('98615635') LIMIT 1");
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
            $scope="o.project_key='anytour' AND o.counter_id='98615635' AND o.transport_target=? AND o.value_contract=?";
            $exists=static fn(string $c):string=>'EXISTS(SELECT 1 FROM max_channel_conversion_outbox o WHERE '.$scope.' AND '.$c.')';
            // A price-continuity void is retained as failed for idempotency, not rejected by the API.
            // Require its matching audit and absence of every upload trace; incomplete evidence still alerts.
            $failed="state='failed' AND NOT EXISTS(SELECT 1 FROM modeled_conversion_price_audit a"
                ." WHERE a.outbox_id=o.id AND HEX(a.action)=HEX('voided')"
                ." AND a.original_value_rub>0 AND a.applied_value_rub=0"
                ." AND HEX(o.state)=HEX('failed') AND HEX(o.last_error)=HEX('price_loss_reconciled')"
                ." AND o.value_rub=0 AND o.attempts=0 AND o.upload_id IS NULL"
                ." AND COALESCE(HEX(o.upload_status),'')='' AND COALESCE(HEX(o.claim_token),'')=''"
                ." AND o.claimed_at_unix IS NULL AND o.value_api_accepted_at_unix IS NULL"
                ." AND o.value_processed_at_unix IS NULL AND o.value_receipt_basis IS NULL)";
            // Cumulative complete saved scope: the monitor, not this reader, owns the first-observed baseline.
            $linkage="SELECT COUNT(*) FROM max_channel_conversion_outbox o WHERE o.project_key='anytour'"
                ." AND HEX(o.project_key)=HEX('anytour') AND o.counter_id='98615635' AND HEX(o.counter_id)=HEX('98615635')"
                ." AND o.transport_target=? AND HEX(o.transport_target)=HEX(?) AND o.value_contract=? AND HEX(o.value_contract)=HEX(?)"
                ." AND HEX(o.state)=HEX('unmatched') AND HEX(o.upload_status)=HEX('LINKAGE_FAILURE')";
            $sql='SELECT '.$exists("state='ready' AND value_ready_at_unix IS NOT NULL AND value_ready_at_unix<=? AND next_attempt_unix<=?").' AS ready_queue_stale,'
                .$exists("state IN ('uploading','unknown') AND claimed_at_unix IS NOT NULL AND claimed_at_unix<=?").' AS upload_outcome_unknown,'
                .$exists($failed).' AS api_row_rejected,'
                .'('.$linkage.') AS linkage_failure_events,'
                .$exists('(value_ready_at_unix>? OR value_api_accepted_at_unix>? OR value_processed_at_unix>? OR claimed_at_unix>?)').' AS invalid_clock';
            $rows=$query($sql,[self::TARGET,self::CONTRACT,$now-600,$now-600,self::TARGET,self::CONTRACT,$now-900,
                self::TARGET,self::CONTRACT,self::TARGET,self::TARGET,self::CONTRACT,self::CONTRACT,self::TARGET,self::CONTRACT,$now+60,$now+60,$now+60,$now+60]);
            if(count($rows)!==1)throw new UnexpectedValueException('invalid_metadata');
            $out['linkage_failures']=['basis'=>'complete_saved_goal_linkage_failures_v1','goal_id'=>'678187097',
                'transport_target'=>self::TARGET,'value_contract'=>self::CONTRACT,'complete'=>true,
                'events'=>self::boundedCount($rows[0]['linkage_failure_events']??null)];
            foreach(['ready_queue_stale','upload_outcome_unknown','api_row_rejected']as$key)if(self::bit($rows[0][$key]??null))$flags[]=$key;
            if(self::bit($rows[0]['invalid_clock']??null))throw new UnexpectedValueException('invalid_clock');
            require_once __DIR__.'/ModeledConversionVisitProof.php';
            $automatic=ModeledConversionVisitProof::automatic($now);
            if(($automatic['available']??false)===true){
                if($automatic['status']==='failed'||($automatic['status']==='running'&&$now-$automatic['attempt_unix']>120))$flags[]='visit_proof_refresh_failed';
                if($now-($automatic['success_unix']?:$automatic['attempt_unix'])>900)$flags[]='visit_proof_stale';
            }
            $out['flags']=array_values(array_unique($flags));$out['status']=$out['flags']===[]?'ok':'degraded';$guard();return$out;
        }catch(PDOException|JsonException $ignored){$guard();$out['status']='unavailable';$out['flags']=['storage_unavailable'];return$out;}
        catch(UnexpectedValueException $error){$guard();$out['status']='unavailable';
            $out['flags']=[$error->getMessage()==='invalid_clock'?'invalid_clock':'health_metadata_unavailable'];return$out;}
    }
    /** Whitelisted technical history, independent of business and notification data. */
    public static function composeExternalReceipt(array $result,?array $previous,int $now,?array $source=null):array
    {
        if($now<946684800||$now>253402300799)throw new UnexpectedValueException('invalid_clock');
        if(($result['schema_version']??null)!==1||($result['project']??null)!=='anytour'
            ||($result['counter_id']??null)!=='98615635'
            ||!in_array($result['status']??null,['ok','off','degraded','unavailable'],true)
            ||!in_array($result['notification']??null,['quiet','sent','unknown'],true))
            throw new UnexpectedValueException('invalid_metadata');
        $flags=self::externalFlags($result['flags']??null);
        $old=$previous===null?null:self::storedExternalReceipt($previous,$now);
        if($old!==null&&self::isoClock($old['checked_at'],$now)>$now)throw new UnexpectedValueException('invalid_clock');
        $origin=$source===null?null:self::externalSource($source);
        $checks=$old['scheduled_checks']??[];
        if($origin!==null&&$origin['event']==='schedule'&&$origin['run_attempt']===1){
            $duplicate=false;
            foreach($checks as$check)if($check['run_id']===$origin['run_id'])$duplicate=true;
            if(!$duplicate){
                if($checks!==[]&&self::isoClock($checks[count($checks)-1]['checked_at'],$now)>$now)
                    throw new UnexpectedValueException('invalid_clock');
                $checks[]=['checked_at'=>self::at($now),'status'=>$result['status'],
                    'ok'=>($result['ok']??false)===true&&in_array($result['status'],['ok','off'],true)
                        &&$flags===[]&&$result['notification']!=='unknown',
                    'run_id'=>$origin['run_id'],'run_attempt'=>1,'source_sha'=>$origin['source_sha']];
                $checks=array_slice($checks,-3);
            }
        }
        return['schema_version'=>1,'project'=>'anytour','counter_id'=>'98615635','checked_at'=>self::at($now),
            'status'=>$result['status'],'flags'=>$flags,'notification'=>$result['notification'],
            'last_notification_at'=>$result['notification']==='sent'?self::at($now):($old['last_notification_at']??null),
            'source_event'=>$origin['event']??null,'source_run_id'=>$origin['run_id']??null,
            'source_run_attempt'=>$origin['run_attempt']??null,'source_sha'=>$origin['source_sha']??null,
            'scheduled_checks'=>$checks];
    }
    /** Pure projection shared by the private reader and chronology regression. */
    public static function externalReceiptStatus(array $receipt,int $now):array
    {
        $r=self::storedExternalReceipt($receipt,$now);
        $at=self::isoClock($r['checked_at'],$now);$checks=$r['scheduled_checks'];$count=count($checks);$streak=0;
        for($i=$count-1;$i>=0;$i--){
            if($checks[$i]['ok']!==true)break;
            if($i<$count-1&&self::isoClock($checks[$i+1]['checked_at'],$now)-self::isoClock($checks[$i]['checked_at'],$now)>900)break;
            $streak++;
        }
        $last=$count===0?null:$checks[$count-1]['checked_at'];
        $scheduledFresh=$last!==null&&$now-self::isoClock($last,$now)<=900;
        return['available'=>true,'checked_at'=>$r['checked_at'],'fresh'=>$now-$at<=900,
            'status'=>$r['status'],'notification'=>$r['notification'],'last_notification_at'=>$r['last_notification_at'],
            'source_event'=>$r['source_event'],'source_run_id'=>$r['source_run_id'],
            'source_run_attempt'=>$r['source_run_attempt'],'source_sha'=>$r['source_sha'],
            'scheduled'=>['available'=>$count>0,'last_checked_at'=>$last,'fresh'=>$last===null?null:$scheduledFresh,
                'success_streak'=>$streak,'regular'=>$streak===3&&$scheduledFresh,'checks'=>$checks]];
    }
    /** Whitelisted receipt of the independent checker, never a credential route. */
    public static function externalStatus(ModeledConversionStatusBudget $budget,callable $authorize,int $now):array
    {
        $guard=static function()use($budget,$authorize):void{$budget->check();$authorize();$budget->check();};$guard();
        $path=dirname(__DIR__,2).'/max-search-bot-modeled-monitor/last-check.json';
        $out=['available'=>false,'reason'=>'not_recorded'];
        if(!is_link($path)&&is_file($path)&&is_readable($path)&&filesize($path)<=4096){
            $raw=file_get_contents($path);if(is_string($raw)){$budget->consumeBytes(strlen($raw));$r=json_decode($raw,true,16);
                if(is_array($r)){
                    try{$out=self::externalReceiptStatus($r,$now);}
                    catch(UnexpectedValueException $ignored){$out=['available'=>false,'reason'=>'invalid_metadata'];}
                }
            }
        }
        $out['pipeline']=$budget->remainingBytes()<12288
            ?['available'=>false,'active'=>null,'origin'=>null,'fresh'=>false,'reason'=>'budget_headroom']
            :ModeledConversionPipelineMonitorStatus::read($budget,$authorize,$now);
        $guard();return$out;
    }
    private static function storedExternalReceipt(array $r,int $now):array
    {
        if(($r['schema_version']??null)!==1||($r['project']??null)!=='anytour'||($r['counter_id']??null)!=='98615635'
            ||!in_array($r['status']??null,['ok','off','degraded','unavailable'],true)
            ||!in_array($r['notification']??null,['quiet','sent','unknown'],true))
            throw new UnexpectedValueException('invalid_metadata');
        $at=self::isoClock($r['checked_at']??null,$now);
        if($at===null)throw new UnexpectedValueException('invalid_clock');
        $last=self::isoClock($r['last_notification_at']??null,$now);
        if($last!==null&&$last>$at)throw new UnexpectedValueException('invalid_clock');
        $flags=self::externalFlags($r['flags']??[]);
        $origin=null;
        if(($r['source_event']??null)!==null){
            $origin=self::externalSource(['event'=>$r['source_event'],'run_id'=>$r['source_run_id']??null,
                'run_attempt'=>$r['source_run_attempt']??null,'source_sha'=>$r['source_sha']??null]);
        }elseif(($r['source_run_id']??null)!==null||($r['source_run_attempt']??null)!==null||($r['source_sha']??null)!==null){
            throw new UnexpectedValueException('invalid_metadata');
        }
        $checks=$r['scheduled_checks']??[];
        if(!is_array($checks)||!array_is_list($checks)||count($checks)>3)throw new UnexpectedValueException('invalid_metadata');
        $normalized=[];$ids=[];$prior=null;
        foreach($checks as$check){
            if(!is_array($check)||!is_bool($check['ok']??null)
                ||!in_array($check['status']??null,['ok','off','degraded','unavailable'],true)
                ||($check['ok']===true&&!in_array($check['status'],['ok','off'],true)))
                throw new UnexpectedValueException('invalid_metadata');
            $when=self::isoClock($check['checked_at']??null,$now);
            if($when===null||$when>$at||($prior!==null&&$when<$prior))throw new UnexpectedValueException('invalid_clock');
            $s=self::externalSource(['event'=>'schedule','run_id'=>$check['run_id']??null,
                'run_attempt'=>$check['run_attempt']??null,'source_sha'=>$check['source_sha']??null]);
            if($s['run_attempt']!==1||isset($ids[$s['run_id']]))throw new UnexpectedValueException('invalid_metadata');
            $ids[$s['run_id']]=true;$prior=$when;
            $normalized[]=['checked_at'=>self::at($when),'status'=>$check['status'],'ok'=>$check['ok'],
                'run_id'=>$s['run_id'],'run_attempt'=>1,'source_sha'=>$s['source_sha']];
        }
        return['checked_at'=>self::at($at),'status'=>$r['status'],'flags'=>$flags,'notification'=>$r['notification'],
            'last_notification_at'=>self::at($last),'source_event'=>$origin['event']??null,
            'source_run_id'=>$origin['run_id']??null,'source_run_attempt'=>$origin['run_attempt']??null,
            'source_sha'=>$origin['source_sha']??null,'scheduled_checks'=>$normalized];
    }
    private static function externalSource(array $s):array
    {
        if(!in_array($s['event']??null,['schedule','workflow_run','workflow_dispatch'],true)
            ||!is_string($s['run_id']??null)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$s['run_id'])!==1
            ||!is_int($s['run_attempt']??null)||$s['run_attempt']<1||$s['run_attempt']>999999
            ||!is_string($s['source_sha']??null)||preg_match('/\A[0-9a-f]{40}\z/D',$s['source_sha'])!==1)
            throw new UnexpectedValueException('invalid_metadata');
        return['event'=>$s['event'],'run_id'=>$s['run_id'],'run_attempt'=>$s['run_attempt'],'source_sha'=>$s['source_sha']];
    }
    private static function externalFlags(mixed $flags):array
    {
        $allowed=['delivery_tick_stale','profile_tick_stale','delivery_tick_errors','profile_tick_errors','profile_disabled',
            'ready_queue_stale','upload_outcome_unknown','api_row_rejected','report_unavailable','storage_unavailable',
            'configuration_unavailable','invalid_clock','health_metadata_unavailable','monitor_transport_unavailable','monitor_source_mismatch','pipeline_checker_stale','pipeline_checker_notification_unknown'];
        if(!is_array($flags)||!array_is_list($flags)||count($flags)>20)throw new UnexpectedValueException('invalid_metadata');
        foreach($flags as$flag)if(!is_string($flag)||!in_array($flag,$allowed,true))throw new UnexpectedValueException('invalid_metadata');
        return array_values(array_unique($flags));
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
    private static function boundedCount(mixed $v):int
    {
        if((!is_int($v)&&!is_string($v))||preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',(string)$v)!==1
            ||(int)$v>2147483647)throw new UnexpectedValueException('invalid_metadata');
        return (int)$v;
    }
    private static function bit(mixed $v):bool
    {if($v===1||$v==='1'||$v===true)return true;if($v===0||$v==='0'||$v===false)return false;throw new UnexpectedValueException('invalid_metadata');}
    private static function at(?int $v):?string{return$v===null?null:gmdate('Y-m-d\TH:i:s\Z',$v);}
}
