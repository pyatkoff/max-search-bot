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
    }elseif($operation==='availability'){
        $availability=ModeledConversionPipelineMonitorStatus::availability(time());
        if($availability===null){
            // No cutover marker: preserve the complete legacy SQL health check.
            ProjectAccessService::initializeReadOnly();$p=ConversationDb::connection();
            if($p->inTransaction())throw new RuntimeException('monitor_outer_transaction');
            if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$p->exec('SET SESSION TRANSACTION READ ONLY');
            $availability=ModeledConversionDeliveryHealth::collect($p,time(),new ModeledConversionStatusBudget());
            $availability['monitor_scope']='legacy';
        }
        echo json_encode($availability,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
    }elseif($operation==='health'){
        ProjectAccessService::initializeReadOnly();$p=ConversationDb::connection();
        if($p->inTransaction())throw new RuntimeException('monitor_outer_transaction');
        if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
            $p->exec('SET SESSION TRANSACTION READ ONLY');
        }
        echo json_encode(ModeledConversionDeliveryHealth::collect($p,time(),new ModeledConversionStatusBudget()),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
    }elseif($operation==='record'){
        $raw=stream_get_contents(STDIN,8193);
        if(!is_string($raw)||strlen($raw)>8192)throw new RuntimeException('monitor_record');
        $r=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($r))throw new RuntimeException('monitor_record');
        $source=null;$options=[];
        foreach(array_slice($argv,2)as$arg){
            if(!is_string($arg)||preg_match('/\A--(event|run-id|run-attempt|source-sha)=(.+)\z/D',$arg,$m)!==1
                ||array_key_exists($m[1],$options))throw new RuntimeException('monitor_record_source');
            $options[$m[1]]=$m[2];
        }
        if($options!==[]){
            if(count($options)!==4||preg_match('/\A[1-9][0-9]{0,5}\z/D',$options['run-attempt']??'')!==1)
                throw new RuntimeException('monitor_record_source');
            $source=['event'=>$options['event']??null,'run_id'=>$options['run-id']??null,
                'run_attempt'=>(int)$options['run-attempt'],'source_sha'=>$options['source-sha']??null];
        }
        $dir=dirname(__DIR__,2).'/max-search-bot-modeled-monitor';
        if(is_link($dir)||(!is_dir($dir)&&!mkdir($dir,0700,true)))throw new RuntimeException('monitor_storage');
        if((fileperms($dir)&0777)!==0700)throw new RuntimeException('monitor_storage');
        $path=$dir.'/last-check.json';if(is_link($path))throw new RuntimeException('monitor_storage');
        $old=null;
        if(is_file($path)){
            if(filesize($path)>4096)throw new RuntimeException('monitor_storage');
            $old=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
            if(!is_array($old))throw new RuntimeException('monitor_storage');
        }
        $out=ModeledConversionDeliveryHealth::composeExternalReceipt($r,$old,time(),$source);
        $encoded=json_encode($out,JSON_THROW_ON_ERROR);
        if(strlen($encoded)>4096)throw new RuntimeException('monitor_storage');
        $tmp=tempnam($dir,'.check-');if($tmp===false)throw new RuntimeException('monitor_storage');
        try{
            if(!chmod($tmp,0600)||file_put_contents($tmp,$encoded,LOCK_EX)===false||!rename($tmp,$path))
                throw new RuntimeException('monitor_storage');
        }finally{if(is_file($tmp))unlink($tmp);}
        echo '{"schema_version":1,"recorded":true}'.PHP_EOL;
    }else throw new RuntimeException('monitor_operation');
}catch(Throwable $ignored){
    echo '{"schema_version":1,"project":"anytour","counter_id":"98615635","status":"unavailable","flags":["configuration_unavailable"]}'.PHP_EOL;
    exit(1);
}
