<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
$args=['expected-sha'=>'','channel-id'=>'','published-ms'=>'','content-hash'=>'','expected-version-id'=>'','expected-config-version'=>''];
$apply=false;$recoverUnsupportedVideo=false;
foreach(array_slice($argv,1)as $arg){
    if($arg==='--apply'){$apply=true;continue;}
    if($arg==='--recover-unsupported-video'){$recoverUnsupportedVideo=true;continue;}
    if(!preg_match('/\A--(expected-sha|channel-id|published-ms|content-hash|expected-version-id|expected-config-version)=(.*)\z/',$arg,$m)){fwrite(STDERR,"Unknown option\n");exit(2);}
    $args[$m[1]]=$m[2];
}
if(!preg_match('/\A[a-f0-9]{40}\z/',$args['expected-sha'])||!preg_match('/\A-[1-9][0-9]{0,19}\z/',$args['channel-id'])||!preg_match('/\A[1-9][0-9]{0,15}\z/',$args['published-ms'])){
    echo json_encode(['ok'=>false,'reason'=>'exact_target_and_installed_sha_required']).PHP_EOL;exit(2);
}
$versionId=$args['expected-version-id']===''?0:filter_var($args['expected-version-id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$configVersion=$args['expected-config-version']===''?0:filter_var($args['expected-config-version'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if($versionId===false||$configVersion===false||(!$recoverUnsupportedVideo&&($versionId!==0||$configVersion!==0))
    ||($recoverUnsupportedVideo&&$apply&&($versionId<=0||$configVersion<=0))){
    echo json_encode(['ok'=>false,'reason'=>'exact_video_recovery_target_required']).PHP_EOL;exit(2);
}
$sha=trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
$dirty=trim((string)shell_exec('git -C '.escapeshellarg($root).' status --porcelain --untracked-files=no 2>/dev/null'));
if($sha!==$args['expected-sha']||$dirty!==''){echo json_encode(['ok'=>false,'reason'=>'installed_checkout_changed']).PHP_EOL;exit(2);}
if(is_file($root.'/config.php'))require_once $root.'/config.php';
require_once $root.'/services/ChannelPostRuntime.php';
require_once $root.'/services/ChannelPostReconciliationService.php';
$identity=ChannelPostRuntime::identity('max');
if(!$identity||!ConversationDb::isConfigured()){echo json_encode(['ok'=>false,'reason'=>'channel_worker_not_configured']).PHP_EOL;exit(2);}
$result=(new ChannelPostReconciliationService())->recover($identity,$args['channel-id'],(int)$args['published-ms'],$args['content-hash'],$apply,$recoverUnsupportedVideo,$versionId,$configVersion);
$result['installed_sha']=$sha;
echo json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(!empty($result['ok'])?0:1);
