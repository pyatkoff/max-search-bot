<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

// Synthetic SQLite and injected provider adapters only; never load live config.
foreach(['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS'] as $key)define($key,'synthetic-project-manager');
define('MAX_SEARCH_TOKEN','synthetic-legacy-token');
require_once __DIR__.'/fixtures/manager_send_attempt_fixture.php';
require_once dirname(__DIR__).'/services/ManagerOutboundService.php';
require_once dirname(__DIR__).'/services/ManagerMessageEditService.php';
require_once dirname(__DIR__).'/services/ManagerMaxMediaService.php';

final class ProjectManagerFixturePdo extends PDO
{
    public array $locks=[];
    public function __construct()
    {
        parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
        $this->sqliteCreateFunction('GET_LOCK',function($key,$wait){$this->locks[]=['acquire',$key];return 1;});
        $this->sqliteCreateFunction('RELEASE_LOCK',function($key){$this->locks[]=['release',$key];return 1;});
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        $query=str_replace('DATE_SUB(NOW(), INTERVAL 3 SECOND)',"datetime('now','-3 seconds')",$query);
        return parent::prepare($query,$options);
    }
}

final class MaxSearchApi
{
    public static array $goals=[];
    public static function queueMetrikaGoal($chat,string $goal,array $context=[]): bool
    {
        self::$goals[]=[$chat,$goal,$context];return true;
    }
    public static function MaxSend($text,$chat) {throw new RuntimeException('Unexpected legacy transport');}
}

$passed=0;$calls=[];$created=[];
function projectManagerCheck(string $name,bool $ok): void
{
    global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";
}
function projectManagerStream(string $bytes)
{
    $stream=tmpfile();if($stream===false)throw new RuntimeException('Synthetic media unavailable');
    fwrite($stream,$bytes);rewind($stream);return $stream;
}

ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic legacy']]);
ProjectMaxConnection::resetForTests(['appleservice39'=>[
    'project'=>['id'=>'appleservice39','brand'=>['name'=>'Synthetic repair'],'business'=>['kind'=>'device_repair']],
    'max'=>['bot_key'=>'synthetic_repair_bot','token'=>'synthetic-repair-token','webhook_secret'=>str_repeat('a',32)],
]]);
ProjectMaxMessengerFactory::resetForTests(static function(ProjectMaxConnection $connection,string $sender)use(&$calls,&$created):MaxMessengerAdapter{
    $project=$connection->projectKey();$token=$connection->token();$created[]=[$project,$sender];
    $send=static function($chat,string $text)use(&$calls,$project,$token){$calls[]=['send',$project,$token,(string)$chat,$text];return 'fixture-'.$project.'-'.count($calls);};
    $media=static function($chat,string $type,string $path,string $name,string $mime,string $caption)use(&$calls,$project,$token){$calls[]=['media',$project,$token,(string)$chat,$type,$caption];return ['message_id'=>'fixture-media-'.count($calls)];};
    $edit=static function(string $mid,string $text)use(&$calls,$project,$token):bool{$calls[]=['edit',$project,$token,$mid,$text];return true;};
    return new MaxMessengerAdapter($send,null,$sender,$media,false,$edit);
});
$pdo=new ProjectManagerFixturePdo();sendAttemptSchema($pdo);sendAttemptBind($pdo);
$pdo->exec("DROP TABLE messages;
CREATE TABLE messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,direction TEXT,sender_type TEXT,sender_id TEXT,channel TEXT,external_message_id TEXT,text TEXT,metadata_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,event_type TEXT,actor_type TEXT,actor_id TEXT,payload_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
ALTER TABLE conversations ADD COLUMN metadata_json TEXT;
INSERT INTO projects VALUES(3,'appleservice39','Synthetic repair',1),(4,'unknown_project','Synthetic unknown',1);
INSERT INTO manager_projects VALUES(7,3),(7,4);
INSERT INTO conversations(id,customer_id,project_key,source_id,channel,status,manager_id,external_chat_id) VALUES
(201,1,'appleservice39',1,'max','manager',7,'same-max-user'),
(202,1,'appleservice39',1,'max','manager',7,'same-max-user'),
(301,1,'unknown_project',1,'max','manager',7,'same-max-user');
UPDATE conversations SET external_chat_id='same-max-user' WHERE id=101;
INSERT INTO conversation_events(conversation_id,event_type,actor_type) VALUES(101,'waiting_manager','customer'),(201,'waiting_manager','customer')");
$repairMetadata=['max_bot_key'=>'synthetic_repair_bot','repair_context'=>['model'=>'iPhone X','service'=>'Замена аккумулятора','entry_code'=>'as39_x_battery','private_token'=>'must-not-project'],'other_private'=>'must-not-project'];
$pdo->prepare('UPDATE conversations SET metadata_json=? WHERE id=201')->execute([json_encode($repairMetadata)]);
$pdo->prepare('UPDATE conversations SET metadata_json=? WHERE id=202')->execute([json_encode(['max_bot_key'=>'synthetic_repair_bot'])]);
$operationalBefore=$pdo->query('SELECT * FROM managers ORDER BY id')->fetchAll();
$membershipBefore=$pdo->query('SELECT * FROM manager_projects ORDER BY manager_id,project_id')->fetchAll();

try{
    $detail=ManagerConversationService::detail(201,7);
    projectManagerCheck('authorized repair detail projects only its model service and entry code',($detail['conversation']['repair_context']??null)===['model'=>'iPhone X','service'=>'Замена аккумулятора','entry_code'=>'as39_x_battery']&&!str_contains(json_encode($detail),'must-not-project'));
    projectManagerCheck('manager without project membership cannot read repair detail',ManagerConversationService::detail(201,8)===null);
    projectManagerCheck('legacy detail has no repair context',!isset(ManagerConversationService::detail(101,7)['conversation']['repair_context']));

    projectManagerCheck('legacy text still succeeds through the shared outbound owner',ManagerOutboundService::send(101,7,'Legacy reply'));
    projectManagerCheck('repair text succeeds through its own connection',ManagerOutboundService::send(201,7,'Repair <reply>'));
    projectManagerCheck('same MAX user is dispatched through different resolved bot credentials',$calls[0]===['send','anytour','synthetic-legacy-token','same-max-user','Legacy reply']&&$calls[1]===['send','appleservice39','synthetic-repair-token','same-max-user','Repair &lt;reply&gt;']);
    $legacyMessages=$pdo->query('SELECT * FROM messages WHERE conversation_id=101 ORDER BY id')->fetchAll();
    $repairMessages=$pdo->query('SELECT * FROM messages WHERE conversation_id=201 ORDER BY id')->fetchAll();
    projectManagerCheck('each successful reply is stored once in its exact conversation',count($legacyMessages)===1&&count($repairMessages)===1&&$legacyMessages[0]['text']==='Legacy reply'&&$repairMessages[0]['text']==='Repair &lt;reply&gt;'&&$repairMessages[0]['external_message_id']==='fixture-appleservice39-2');
    projectManagerCheck('new project reply never inherits AnyTour conversion goals',count(MaxSearchApi::$goals)===1&&MaxSearchApi::$goals[0][2]===['conversation_id'=>'101']);
    $callCount=count($calls);
    projectManagerCheck('duplicate guard remains idempotent within the same repair conversation',ManagerOutboundService::send(201,7,'Repair <reply>')&&count($calls)===$callCount&&(int)$pdo->query('SELECT COUNT(*) FROM messages WHERE conversation_id=201')->fetchColumn()===1);
    projectManagerCheck('every manager text lock is released',$pdo->locks===[['acquire','manager-send:101:7'],['release','manager-send:101:7'],['acquire','manager-send:201:7'],['release','manager-send:201:7'],['acquire','manager-send:201:7'],['release','manager-send:201:7']]);

    $edit=ManagerMessageEditService::edit((int)$repairMessages[0]['id'],7,'Repair corrected');
    projectManagerCheck('editing uses the same repair connection and exact provider message',!empty($edit['ok'])&&end($calls)===['edit','appleservice39','synthetic-repair-token','fixture-appleservice39-2','Repair corrected']);
    projectManagerCheck('repair edit leaves the other company transcript unchanged',$pdo->query('SELECT * FROM messages WHERE conversation_id=101 ORDER BY id')->fetchAll()===$legacyMessages);

    $tmp=sys_get_temp_dir().'/project-manager-media-'.bin2hex(random_bytes(5));mkdir($tmp,0700);putenv('MAX_SEARCH_INCOMING_MEDIA_DIR='.$tmp);
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a/2kAAAAASUVORK5CYII=');
    $file=$tmp.'/synthetic.png';file_put_contents($file,$png);
    projectManagerCheck('repair media uses its project bot',ManagerOutboundService::sendMedia(201,7,$file,'synthetic.png','image/png','Photo <caption>')&&end($calls)===['media','appleservice39','synthetic-repair-token','same-max-user','image','Photo &lt;caption&gt;']);
    projectManagerCheck('repair media also leaves AnyTour goals unchanged',count(MaxSearchApi::$goals)===1);

    $beforeUnknown=$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll();$callCount=count($calls);$factoryCount=count($created);
    $unknown=ManagerOutboundService::sendAttempt(301,7,str_repeat('d',32),'Unknown project reply');
    projectManagerCheck('unknown connection is a definite rejected attempt before factory dispatch',($unknown['attempt']['state']??'')==='rejected'&&($unknown['failure']['category']??'')==='connection_unavailable'&&count($calls)===$callCount&&count($created)===$factoryCount);
    projectManagerCheck('unknown attempt replay never falls back to the legacy bot',ManagerOutboundService::sendAttempt(301,7,str_repeat('d',32),'Unknown project reply')['attempt']['state']==='rejected'&&count($calls)===$callCount);
    projectManagerCheck('unknown connection media is refused before dispatch',!ManagerOutboundService::sendMedia(301,7,$file,'synthetic.png','image/png')&&(ManagerOutboundService::lastFailure()['category']??'')==='connection_unavailable'&&count($calls)===$callCount);
    projectManagerCheck('unknown connection writes no transcript',$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll()===$beforeUnknown);
    $pdo->prepare("INSERT INTO messages(conversation_id,direction,sender_type,sender_id,channel,external_message_id,text,metadata_json) VALUES(301,'outbound','manager','7','max','same-provider-mid','Unknown original','{}')")->execute();
    $unknownId=(int)$pdo->lastInsertId();
    projectManagerCheck('unknown connection edit fails without changing provider or original text',ManagerMessageEditService::edit($unknownId,7,'Should not edit')===['ok'=>false,'error'=>'connection_unavailable']&&count($calls)===$callCount&&$pdo->query('SELECT text FROM messages WHERE id='.$unknownId)->fetchColumn()==='Unknown original');
    projectManagerCheck('project permission fence precedes any provider factory',!ManagerOutboundService::send(201,8,'Forbidden')&&count($calls)===$callCount&&count($created)===$factoryCount);

    $attachment=['attachments'=>[['type'=>'image','provider'=>'max','token'=>'saved-image-token']]];
    $insert=$pdo->prepare("INSERT INTO messages(conversation_id,direction,sender_type,channel,external_message_id,text,metadata_json) VALUES(?,'inbound','customer','max','shared-provider-mid','Synthetic photo',?)");
    $ids=[];foreach([101,201,202,301] as $cid){$insert->execute([$cid,json_encode($attachment)]);$ids[$cid]=(int)$pdo->lastInsertId();}
    $metadataBefore=$pdo->query('SELECT id,metadata_json FROM messages ORDER BY id')->fetchAll();
    $fetchMessage=static fn(string $mid):array=>['body'=>['attachments'=>[['type'=>'image','payload'=>['token'=>'saved-image-token','url'=>'https://cdn.max.test/synthetic.png']]]]];
    $fetchMedia=static fn(string $url,int $limit)=>projectManagerStream($png);
    projectManagerCheck('new project archive requires exact canonical conversation',MaxInboundMediaArchiveService::archiveRecordedMessage('shared-provider-mid',$fetchMessage,$fetchMedia,null,'appleservice39')===0&&$pdo->query('SELECT id,metadata_json FROM messages ORDER BY id')->fetchAll()===$metadataBefore);
    projectManagerCheck('scoped archive rejects mismatched conversation and project',MaxInboundMediaArchiveService::archiveRecordedMessage('shared-provider-mid',$fetchMessage,$fetchMedia,null,'appleservice39',101)===0);
    projectManagerCheck('scoped archive resolves the exact repair message even with colliding provider IDs',MaxInboundMediaArchiveService::archiveRecordedMessage('shared-provider-mid',$fetchMessage,$fetchMedia,null,'appleservice39',201)===1);
    foreach([101,202,301] as $cid)projectManagerCheck('repair archive preserves other conversation '.$cid,json_decode((string)$pdo->query('SELECT metadata_json FROM messages WHERE id='.$ids[$cid])->fetchColumn(),true)===$attachment);
    $descriptor=ManagerMaxMediaService::attachment($ids[201],0,7);
    projectManagerCheck('authorized repair media gets a credential-free descriptor',$descriptor===['message_id'=>$ids[201],'attachment'=>0]);
    $opened=ManagerMaxMediaService::open($descriptor);
    projectManagerCheck('repair photo is served from the same protected local archive',is_array($opened)&&stream_get_contents($opened['stream'])===$png);
    if(is_array($opened)&&is_resource($opened['stream']??null))fclose($opened['stream']);
    projectManagerCheck('other-project manager cannot obtain the repair attachment',ManagerMaxMediaService::attachment($ids[201],0,8)===null);
    projectManagerCheck('unknown bot attachment cannot borrow default credentials',ManagerMaxMediaService::attachment($ids[301],0,7)===null&&ManagerMaxMediaService::open(['message_id'=>$ids[301],'attachment'=>0])===null);
    $callCount=count($calls);
    $pdo->prepare('UPDATE conversations SET metadata_json=? WHERE id=201')->execute([json_encode(['max_bot_key'=>'different_bot'])]);
    projectManagerCheck('changed bot identity cannot send through the replacement connection',!ManagerOutboundService::send(201,7,'Wrong bot must not send')&&(ManagerOutboundService::lastFailure()['category']??'')==='connection_unavailable'&&count($calls)===$callCount);
    projectManagerCheck('changed bot identity cannot edit an old provider message',ManagerMessageEditService::edit((int)$repairMessages[0]['id'],7,'Wrong bot must not edit')===['ok'=>false,'error'=>'connection_unavailable']&&count($calls)===$callCount);
    projectManagerCheck('changed bot identity cannot recover or serve another bot attachment',ManagerMaxMediaService::attachment($ids[201],0,7)===null&&ManagerMaxMediaService::open(['message_id'=>$ids[201],'attachment'=>0])===null&&MaxInboundMediaArchiveService::archiveRecordedMessage('shared-provider-mid',$fetchMessage,$fetchMedia,null,'appleservice39',201)===0);
    $pdo->prepare('UPDATE conversations SET metadata_json=? WHERE id=201')->execute([json_encode($repairMetadata)]);
    projectManagerCheck('legacy archive default cannot select the higher-ID repair message',MaxInboundMediaArchiveService::archiveRecordedMessage('shared-provider-mid',$fetchMessage,$fetchMedia)===1&&isset(json_decode((string)$pdo->query('SELECT metadata_json FROM messages WHERE id='.$ids[101])->fetchColumn(),true)['attachments'][0]['local_media']));

    $videoCalls=[];
    $video=MaxInboundMediaArchiveService::archiveMedia(900,0,'video-mid',['type'=>'video','token'=>'repair-video-token'],static fn()=>null,static fn()=>projectManagerStream('synthetic-video-bytes'),static function(string $token)use(&$videoCalls){$videoCalls[]=$token;return ['url'=>'https://cdn.max.test/synthetic-video'];});
    projectManagerCheck('video recovery uses its caller-scoped token resolver and keeps MIME validation',$videoCalls===['repair-video-token']&&$video===null);
    projectManagerCheck('explicit empty recovery credentials never fall back to a configured legacy token',MaxInboundMediaDownloadAdapter::fetchMessage('synthetic-mid','')===null&&MaxInboundMediaDownloadAdapter::fetchVideo('synthetic-token','')===null);
    projectManagerCheck('manager integration never switches the global project',ProjectConfig::projectId()==='anytour');
    projectManagerCheck('manager integration preserves operator shifts and memberships',$pdo->query('SELECT * FROM managers ORDER BY id')->fetchAll()===$operationalBefore&&$pdo->query('SELECT * FROM manager_projects ORDER BY manager_id,project_id')->fetchAll()===$membershipBefore);
}finally{
    ProjectMaxMessengerFactory::resetForTests();ProjectMaxConnection::resetForTests();ProjectConfig::resetForTests();
    putenv('MAX_SEARCH_INCOMING_MEDIA_DIR');
    if(isset($tmp)&&is_dir($tmp)){foreach(glob($tmp.'/*')?:[] as $path)unlink($path);rmdir($tmp);}
}
echo 'TOTAL '.$passed.' | PASS '.$passed." | FAIL 0\n";
