<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/services/ProjectAccessService.php';
require_once dirname(__DIR__).'/services/ModeledConversionDeliveryHealth.php';
$operation=$argv[1]??'health';
try{
    if(ProjectConfig::projectId()!=='anytour'||!defined('METRIKA_COUNTER_ID')||(string)METRIKA_COUNTER_ID!=='98615635')
        throw new RuntimeException('monitor_project');
    if($operation==='route'){
        // Called only over the existing authenticated server channel; caller
        // redirects stdout to private ephemeral storage. Never a public artifact.
        $token=defined('TELEGRAM_BOT_TOKEN')?(string)TELEGRAM_BOT_TOKEN:'';
        if(preg_match('/\A[1-9][0-9]{4,19}:[A-Za-z0-9_-]{30,100}\z/D',$token)!==1)throw new RuntimeException('monitor_route');
        $receipt=dirname(__DIR__,2).'/max-search-bot-modeled-monitor/last-check.json';
        $old=!is_link($receipt)&&is_file($receipt)&&filesize($receipt)<=4096?json_decode((string)file_get_contents($receipt),true):null;
        $prior=is_array($old)&&(($old['last_notification_at']??null)!==null||($old['notification']??null)==='unknown');
        echo json_encode(['schema_version'=>1,'project'=>'anytour','counter_id'=>'98615635','token'=>$token,
            'previous_state_recorded'=>$prior],JSON_THROW_ON_ERROR).PHP_EOL;
    }elseif($operation==='health'){
        ProjectAccessService::initializeReadOnly();$p=ConversationDb::connection();
        if($p->inTransaction())throw new RuntimeException('monitor_outer_transaction');
        if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
            $p->exec('SET SESSION TRANSACTION READ ONLY');
            $p->exec('SET SESSION MAX_EXECUTION_TIME=4000');
        }
        echo json_encode(ModeledConversionDeliveryHealth::collect($p,time()),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
    }elseif($operation==='record'){
        $raw=stream_get_contents(STDIN,8193);
        if(!is_string($raw)||strlen($raw)>8192)throw new RuntimeException('monitor_record');
        $r=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($r)||($r['schema_version']??null)!==1||($r['project']??null)!=='anytour'
            ||!in_array($r['status']??null,['ok','off','degraded','unavailable'],true)
            ||!in_array($r['notification']??null,['quiet','sent','unknown'],true)||!is_array($r['flags']??null)||count($r['flags'])>20)
            throw new RuntimeException('monitor_record');
        $allowed=['delivery_tick_stale','profile_tick_stale','delivery_tick_errors','profile_tick_errors','profile_disabled',
            'ready_queue_stale','upload_outcome_unknown','api_row_rejected','report_unavailable','storage_unavailable',
            'configuration_unavailable','invalid_clock','health_metadata_unavailable','monitor_transport_unavailable','monitor_source_mismatch'];
        foreach($r['flags']as$flag)if(!is_string($flag)||!in_array($flag,$allowed,true))throw new RuntimeException('monitor_record');
        $dir=dirname(__DIR__,2).'/max-search-bot-modeled-monitor';
        if(is_link($dir)||(!is_dir($dir)&&!mkdir($dir,0700,true)))throw new RuntimeException('monitor_storage');
        if((fileperms($dir)&0777)!==0700)throw new RuntimeException('monitor_storage');
        $path=$dir.'/last-check.json';if(is_link($path))throw new RuntimeException('monitor_storage');
        $old=is_file($path)&&filesize($path)<=4096?json_decode((string)file_get_contents($path),true):null;
        $out=['schema_version'=>1,'project'=>'anytour','counter_id'=>'98615635','checked_at'=>gmdate('Y-m-d\TH:i:s\Z'),
            'status'=>$r['status'],'flags'=>array_values(array_unique($r['flags'])),'notification'=>$r['notification'],
            'last_notification_at'=>$r['notification']==='sent'?gmdate('Y-m-d\TH:i:s\Z'):($old['last_notification_at']??null)];
        $tmp=tempnam($dir,'.check-');if($tmp===false)throw new RuntimeException('monitor_storage');
        try{
            if(!chmod($tmp,0600)||file_put_contents($tmp,json_encode($out,JSON_THROW_ON_ERROR),LOCK_EX)===false||!rename($tmp,$path))
                throw new RuntimeException('monitor_storage');
        }finally{if(is_file($tmp))unlink($tmp);}
        echo '{"schema_version":1,"recorded":true}'.PHP_EOL;
    }else throw new RuntimeException('monitor_operation');
}catch(Throwable $ignored){
    echo '{"schema_version":1,"project":"anytour","counter_id":"98615635","status":"unavailable","flags":["configuration_unavailable"]}'.PHP_EOL;
    exit(1);
}
