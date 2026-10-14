<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Dry-run by default. --apply only adds event types to an exact existing target.
// After an unknown result, --reconcile performs reads only; never remove claims.
$args=array_slice($argv,1);$platform='';$apply=false;$reconcile=false;
foreach($args as $arg){
    if(in_array($arg,['max','telegram'],true)&&$platform==='')$platform=$arg;
    elseif($arg==='--apply'&&!$apply)$apply=true;
    elseif($arg==='--reconcile'&&!$reconcile)$reconcile=true;
    else{fwrite(STDERR,"Usage: php tools/channel_post_webhook_updates.php max|telegram [--apply|--reconcile]\n");exit(2);}
}
if($platform===''||($apply&&$reconcile)){fwrite(STDERR,"Usage: php tools/channel_post_webhook_updates.php max|telegram [--apply|--reconcile]\n");exit(2);}
try{
    require_once dirname(__DIR__).'/config.php';
    require_once dirname(__DIR__).'/services/ChannelPostRuntime.php';
    require_once dirname(__DIR__).'/services/ChannelPostWebhookConfigService.php';
    $identity=ChannelPostRuntime::identity($platform);
    if(!$identity)throw new RuntimeException('canonical identity unavailable');
    $result=(new ChannelPostWebhookConfigService())->run($platform,$identity,$apply);
    echo json_encode(['platform'=>$platform,'mode'=>$apply?'apply':($reconcile?'reconcile':'dry_run')]+$result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit(!empty($result['ok'])?0:1);
}catch(Throwable $e){echo json_encode(['ok'=>false,'state'=>'blocked','reason'=>'setup_configuration_unavailable'],JSON_UNESCAPED_SLASHES)."\n";exit(1);}
