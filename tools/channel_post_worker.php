<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Separate recovery runner. It never invokes tourist/manager messaging.
$root=dirname(__DIR__);
if (is_file($root.'/config.php')) require_once $root.'/config.php';
require_once $root.'/services/ChannelPostRuntime.php';
$platform='';
foreach (array_slice($argv,1) as $arg) {
    if (str_starts_with($arg,'--platform=')) $platform=substr($arg,11);
    elseif (!in_array($arg,['--run','--help'],true)) {fwrite(STDERR,"Unknown option\n");exit(2);}
}
if (in_array('--help',$argv,true)) {
    echo "php tools/channel_post_worker.php --platform=max|telegram [--run]\nRead-only queue preview by default; --run processes at most one durable job.\n";
    exit;
}
$identity=ChannelPostRuntime::identity($platform);
if (!$identity||!ConversationDb::isConfigured()) {
    echo json_encode(['ok'=>false,'reason'=>'channel_worker_not_configured']).PHP_EOL;exit(2);
}
try {
    if (in_array('--run',$argv,true)) $results=ChannelPostRuntime::finish($platform);
    else {
        $rows=(new ChannelPostRegistryService())->pendingPublications($identity['project_key'],$platform,$identity['bot_key'],1);
        $results=array_map(static fn(array $row):array=>['publication_id'=>$row['publication_id'],'version_id'=>$row['version_id'],'state'=>$row['state']],$rows);
    }
    $ok=count(array_filter($results,static fn(array $row):bool=>in_array($row['state']??'',['unknown','requires_attention'],true)))===0;
    echo json_encode(['ok'=>$ok,'platform'=>$platform,'apply'=>in_array('--run',$argv,true),'results'=>$results],JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($ok?0:1);
} catch (Throwable $ignored) {
    echo json_encode(['ok'=>false,'reason'=>'channel_worker_unavailable']).PHP_EOL;exit(2);
}
