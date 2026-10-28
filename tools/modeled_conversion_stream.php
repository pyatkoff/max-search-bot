<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
try {
    $op=$argv[1]??'status';
    if(!in_array($op,['start','stop','status','tick','profiles-start','profiles-stop','profiles-status','profiles-tick','study-status'],true)||count($argv)>2)throw new RuntimeException('modeled_stream_command');
    require_once dirname(__DIR__).'/config.php';
    require_once dirname(__DIR__).'/services/ProjectAccessService.php';
    require_once dirname(__DIR__).'/services/ModeledConversionStream.php';
    if(ProjectConfig::projectId()!=='anytour')throw new RuntimeException('modeled_stream_project');
    ProjectAccessService::initializeReadOnly();
    $stream=new ModeledConversionStream(ConversationDb::connection());
    if($op==='study-status'){
        require_once dirname(__DIR__).'/services/ModeledConversionPurchaseStudy.php';
        $result=(new ModeledConversionPurchaseStudy(ConversationDb::connection()))->status();
    }elseif(str_starts_with($op,'profiles-')){
        require_once dirname(__DIR__).'/services/ModeledConversionProfiles.php';
        $profiles=new ModeledConversionProfiles(ConversationDb::connection());
        $result=match($op){'profiles-start'=>$profiles->start(),'profiles-stop'=>$profiles->stop(),'profiles-tick'=>$profiles->tick(time()),default=>$profiles->status()};
    }else $result=match($op){'start'=>$stream->start(time()),'stop'=>$stream->stop(),'tick'=>$stream->tick(time()),default=>$stream->status()};
    echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $e){fwrite(STDERR,json_encode(['ok'=>false,'error'=>'modeled_stream_operation_failed']).PHP_EOL);exit(1);}

