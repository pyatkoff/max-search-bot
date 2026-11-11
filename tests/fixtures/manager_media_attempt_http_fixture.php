<?php
declare(strict_types=1);

/** Only the disposable loopback app calls this bootstrap. Never load config.php. */
function mediaAttemptHttpBootstrap(string $directory): void
{
    final class MediaAttemptHttpPdo extends PDO
    {
        public function prepare(string $query,array $options=[]): PDOStatement|false
        {
            file_put_contents($GLOBALS['media_http_dir'].'/queries.jsonl',json_encode($query)."\n",FILE_APPEND|LOCK_EX);
            return parent::prepare($query,$options);
        }
    }
    // Fixed synthetic configuration; the real project/auth/routing policies run.
    final class ProjectConfig
    {
        public static function projectId():string{return 'anytour';}
        public static function get(string $key,$default=null){return $key==='brand.name'?'Synthetic send fixture':$default;}
    }
    final class ManagerReplyQueueService
    {
        public const SEND_BOUNDARY='_manager_reply_boundary';
        public static function captureSendBoundary(int $id):array{return ['version'=>1,'customer_message_id'=>0,'request_event_id'=>0];}
    }
    final class ProjectMaxMessengerFactory
    {
        public static function forConversation(array $conversation,string $sender):object
        {
            return new class {
                public function sendMedia($chat,string $type,string $path,string $name,string $mime,string $caption,string $preview):bool
                {
                    $dir=$GLOBALS['media_http_dir'];$pdo=ConversationDb::connection();
                    $other=new PDO('sqlite:'.$dir.'/fixture.sqlite');
                    $states=$other->query("SELECT state FROM manager_send_attempts WHERE operation_kind='media' ORDER BY created_at,operation_id")->fetchAll(PDO::FETCH_COLUMN);
                    file_put_contents($dir.'/effects.jsonl',json_encode(['event'=>'provider','chat'=>$chat,'type'=>$type,'name'=>$name,'mime'=>$mime,'caption'=>$caption,'digest'=>hash_file('sha256',$path),'committed_processing'=>in_array('processing',$states,true),'transaction'=>$pdo->inTransaction(),'session_active'=>session_status()===PHP_SESSION_ACTIVE,'preview'=>$preview])."\n",FILE_APPEND|LOCK_EX);
                    return true;
                }
                public function lastExternalMessageId():string{return 'synthetic-provider-mid';}
            };
        }
    }
    final class ConversationRecorder
    {
        public static function attachmentPreview(array $attachments):string{return 'Synthetic file';}
        public static function outboundForConversation(...$arguments):void
        {
            file_put_contents($GLOBALS['media_http_dir'].'/effects.jsonl',json_encode(['event'=>'mirror'])."\n",FILE_APPEND|LOCK_EX);
        }
    }
    final class ConversationControlService
    {
        public static function event(...$arguments):void{}
    }
    final class MetrikaConversionGoalService
    {
        public static function managerReply(int $id):void{}
    }
    final class ManagerPushService
    {
        public static function notifyConversation(...$arguments):void{throw new RuntimeException('unexpected_fixture_push');}
    }
    $GLOBALS['media_http_dir']=$directory;
    require_once $directory.'/services/ConversationDb.php';
    $pdo=new MediaAttemptHttpPdo('sqlite:'.$directory.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    if(is_file($directory.'/read-only'))$pdo->exec('PRAGMA query_only=ON');
}

function mediaAttemptHttpApp(string $directory):array
{
    $root=dirname(__DIR__,2);
    foreach(['manager/lib','services','integrations','sessions'] as $path)mkdir($directory.'/'.$path,0700,true);
    $actual=['manager/api.php','manager/media-upload.php','manager/lib/ManagerHttp.php','services/ManagerRequestContext.php','services/ManagerSendAttemptService.php','services/ManagerOutboundService.php','services/ManagerMediaCache.php','services/ConversationDb.php','services/ManagerConversationService.php','services/ManagerConversationAccessPolicy.php','services/ManagerAuthService.php','services/ProjectAccessService.php','services/RoutingAccessService.php','services/ManagerAvailabilityService.php','services/RegionalRoutingScopeService.php','services/RegionalTerritoryCatalog.php','services/ConversationEntryRegionService.php','services/ConversationRecordClock.php','services/TrafficAttributionService.php','services/YandexRegionCatalog.php'];
    foreach($actual as $path)copy($root.'/'.$path,$directory.'/'.$path);
    // Unused modules required by the actual endpoint are inert, not alternative
    // API/ACL implementations. All selected production source stays byte-exact.
    foreach($actual as $path){
        $source=(string)file_get_contents($root.'/'.$path);
        preg_match_all("~require_once[^;]+/((?:services|integrations)/)?([A-Za-z0-9]+\\.php)'~",$source,$matches,PREG_SET_ORDER);
        foreach($matches as $match){
            $target=$directory.'/'.($match[1]!==''?$match[1]:'services/').$match[2];
            if(!is_file($target))file_put_contents($target,"<?php\n");
        }
    }
    foreach(['maxsearchclass.php','integrations/TelegramMessengerAdapter.php','integrations/WebsiteMessengerAdapter.php'] as $path)if(!is_file($directory.'/'.$path))file_put_contents($directory.'/'.$path,"<?php\n");
    file_put_contents($directory.'/config.php',"<?php\nrequire_once ".var_export(__FILE__,true).";mediaAttemptHttpBootstrap(__DIR__);\n");
    return $actual;
}
