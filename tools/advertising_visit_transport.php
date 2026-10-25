<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/AdvertisingVisitTransport.php';
try{
    if(count($argv)!==2||!in_array($argv[1],['key-init','public-keys','status','once','enable','disable'],true))throw new InvalidArgumentException('advertising_transport_command');
    require_once dirname(__DIR__).'/config.php';
    require_once dirname(__DIR__).'/services/ProjectConfig.php';
    if(ProjectConfig::projectId()!=='anytour')throw new InvalidArgumentException('advertising_transport_project');
    $t=new AdvertisingVisitTransport();$command=$argv[1];
    if($command==='key-init')$result=['status'=>'initialized']+$t->initialize();
    elseif($command==='public-keys')$result=['status'=>'public']+$t->publicKeys();
    elseif($command==='status')$result=$t->status();
    elseif($command==='disable'){$t->disable();$result=['status'=>'disabled'];}
    else{
        require_once dirname(__DIR__).'/services/ConversationDb.php';
        $result=$command==='enable'?$t->enable(ConversationDb::connection()):$t->pull(ConversationDb::connection(),null,null,true);
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){
    $message=preg_match('/\Aadvertising_(?:transport|visit)_[a-z_]+\z/D',$e->getMessage())?$e->getMessage():'advertising_transport_failed';
    fwrite(STDERR,json_encode(['status'=>'failed','error'=>$message]).PHP_EOL);exit(1);
}
