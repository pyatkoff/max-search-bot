<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
$expected='';$apply=false;$result=[];
try{
    foreach(array_slice($argv,1) as $arg){
        if($arg==='--apply'&&!$apply)$apply=true;
        elseif(str_starts_with($arg,'--expected-sha=')&&$expected==='')$expected=substr($arg,15);
        else throw new RuntimeException();
    }
    if(!preg_match('/\A[a-f0-9]{40}\z/D',$expected))throw new RuntimeException();
    $root=dirname(__DIR__);
    $sha=trim((string)@shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
    if(!hash_equals($expected,$sha))throw new RuntimeException();
    foreach(['diff --quiet','diff --cached --quiet'] as $operation){
        $status=1;@exec('git -C '.escapeshellarg($root).' '.$operation.' 2>/dev/null',$ignored,$status);
        if($status!==0)throw new RuntimeException();
    }
    require_once $root.'/services/ChannelPostMaxAlbumRecovery.php';
    if(ChannelPostIncomingAdapter::maxPhotoLimit()!==11||!method_exists(ChannelPostAutomationService::class,'processPublication'))throw new RuntimeException();
    $level=ob_get_level();ob_start();
    try{require_once $root.'/config.php';}finally{while(ob_get_level()>$level)ob_end_clean();}
    require_once $root.'/services/ChannelPostRuntime.php';
    $identity=ChannelPostRuntime::identity('max');
    if(!is_array($identity)||$identity['project_key']!=='anytour'||$identity['bot_key']!=='id9704048781_1_bot')throw new RuntimeException();
    $pdo=ConversationDb::connection();
    $q=$pdo->prepare('SELECT pp.id FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id WHERE p.project_key=? AND pc.platform=? AND pc.channel_id=? ORDER BY pp.id LIMIT 2');
    $q->execute(['anytour','max','-77309177991187']);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
    if(count($ids)!==1)throw new RuntimeException();
    $registry=new ChannelPostRegistryService($pdo);$automation=new ChannelPostAutomationService($registry);
    $service=new ChannelPostMaxAlbumRecovery($registry,
        static fn(string $mid):array=>ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.rawurlencode($mid),[],null),
        static fn(int $id,int $version):array=>$automation->processPublication('anytour','max','id9704048781_1_bot',$id,$version)
    );
    $result=$service->run((int)$ids[0],$apply);$result['installed_sha']=$sha;
}catch(Throwable $e){$result=['ok'=>false,'state'=>'blocked','reason'=>'recovery_preflight_failed','provider_requests'=>0,'write_attempts'=>0];}
echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
exit(!empty($result['ok'])?0:1);
