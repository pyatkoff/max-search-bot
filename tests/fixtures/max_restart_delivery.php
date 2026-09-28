<?php
declare(strict_types=1);
// Actual start-policy, delivery projection and outbound guard; synthetic DB and transport only.
$root=dirname(__DIR__,2);
$tmp=sys_get_temp_dir().'/max-restart-delivery-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);mkdir($tmp.'/services',0700);mkdir($tmp.'/integrations',0700);
$bootstrap= <<<'PHP'
<?php
class ConversationDb { public static $pdo; public static function isConfigured(){return true;} public static function connection(){return self::$pdo;} }
class ProjectConfig { public static function projectId(){return 'anytour';} }
class DiagnosticLogger { public static function log(...$args){} }
class SourceHandlingService { public static function handle($incoming){throw new RuntimeException('owned conversation must not redispatch');} }
class ConversationRecorder {
    public static function inbound($incoming){throw new RuntimeException('owned restart must not seed a conversation');}
    public static function eventByChat($channel,$chat,$type,$payload=[],$actor='system'){
        $pdo=ConversationDb::connection();$q=$pdo->prepare("SELECT id FROM conversations WHERE project_key=? AND channel=? AND external_chat_id=? AND status<>'closed' ORDER BY id DESC LIMIT 1");$q->execute([ProjectConfig::projectId(),$channel,(string)$chat]);$id=(int)$q->fetchColumn();if(!$id)return false;
        $q=$pdo->prepare('INSERT INTO conversation_events(conversation_id,event_type,actor_type,payload_json) VALUES(?,?,?,?)');return $q->execute([$id,$type,$actor,json_encode($payload)]);
    }
    public static function outboundForConversation($id,$channel,$text,$sender,$senderId,$meta,$external=''){
        $q=ConversationDb::connection()->prepare("INSERT INTO messages(conversation_id,direction,sender_type,text) VALUES(?,'outbound',?,?)");return $q->execute([$id,$sender,$text]);
    }
    public static function attachmentPreview($attachments){return 'Synthetic media';}
}
class ConversationControlService {
    public static function event($id,$type,$actor,$actorId,$payload){$q=ConversationDb::connection()->prepare('INSERT INTO conversation_events(conversation_id,event_type,actor_type,payload_json) VALUES(?,?,?,?)');$q->execute([$id,$type,$actor,json_encode($payload)]);}
}
class ManagerConversationService {
    public static function detail($id,$manager){$q=ConversationDb::connection()->prepare('SELECT * FROM conversations WHERE id=?');$q->execute([$id]);$r=$q->fetch();return $r?['conversation'=>$r]:null;}
}
class ManagerPushService { public static function notifyConversation(...$args){} }
class ManagerSendGuardService { public static function acquire(...$args){return false;} }
class MetrikaConversionGoalService { public static function managerReply(...$args){} }
class MaxMessengerAdapter {
    public static $calls=0,$ok=true;
    public function __construct(...$args){}
    public function send(...$args){self::$calls++;return self::$ok;}
    public function sendMedia(...$args){self::$calls++;return self::$ok;}
    public function lastExternalMessageId(){return 'synthetic-'.self::$calls;}
}
class TelegramMessengerAdapter extends MaxMessengerAdapter {}
class WebsiteMessengerAdapter extends MaxMessengerAdapter {}
class MaxTransport { public static function lastError(){return ['category'=>'suspended','http_code'=>403,'message'=>'Synthetic suspended'];} }
PHP;
file_put_contents($tmp.'/bootstrap.php',$bootstrap);
foreach(['ConversationDb','ProjectConfig','DiagnosticLogger','SourceHandlingService','ConversationRecorder','ManagerConversationService','ManagerPushService','ManagerSendGuardService','MetrikaConversionGoalService'] as $name)file_put_contents($tmp.'/services/'.$name.'.php',"<?php require_once dirname(__DIR__).'/bootstrap.php';\n");
foreach(['MaxMessengerAdapter','TelegramMessengerAdapter','WebsiteMessengerAdapter'] as $name)file_put_contents($tmp.'/integrations/'.$name.'.php',"<?php require_once dirname(__DIR__).'/bootstrap.php';\n");
foreach(['MaxStartSourcePolicy','ManagerDeliveryStateService','ManagerOutboundService'] as $name)copy($root.'/services/'.$name.'.php',$tmp.'/services/'.$name.'.php');
$pass=0;$fail=0;
function restartCheck(string $name,$actual,$expected):void{global $pass,$fail;if($actual===$expected){$pass++;echo 'PASS '.$name.PHP_EOL;}else{$fail++;echo 'FAIL '.$name.' expected='.json_encode($expected).' actual='.json_encode($actual).PHP_EOL;}}
function restartEvent(int $id,string $type,array $payload=[],string $actor='system'):void{ConversationControlService::event($id,$type,$actor,7,$payload);}
function restartRow(int $id):array{$q=ConversationDb::connection()->prepare('SELECT * FROM conversations WHERE id=?');$q->execute([$id]);return $q->fetch();}
function restartSeed(int $id,string $status='manager',string $channel='max'):void{
    $pdo=ConversationDb::connection();$q=$pdo->prepare('INSERT INTO conversations(id,project_key,external_chat_id,channel,source_id,entry_channel,status,manager_id) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$id,'anytour',(string)-$id,$channel,1,'main',$status,$status==='manager'?7:null]);
    $q=$pdo->prepare("INSERT INTO messages(conversation_id,direction,sender_type,text,created_at) VALUES(?,'inbound','customer','Synthetic earlier text','2026-09-29 07:59:59')");$q->execute([$id]);
    restartEvent($id,'bot_started');restartEvent($id,'manager_message_failed',['category'=>'suspended','http_code'=>403]);
}
try{
    require $tmp.'/services/MaxStartSourcePolicy.php';require $tmp.'/services/ManagerDeliveryStateService.php';require $tmp.'/services/ManagerOutboundService.php';
    $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);ConversationDb::$pdo=$pdo;
    $pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT);INSERT INTO projects VALUES(1,'anytour');
    CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,project_id INTEGER,source_key TEXT,channel TEXT,handling_mode TEXT,is_active INTEGER);
    INSERT INTO conversation_sources VALUES(1,1,'max:anytour-main','max','ai',1),(2,1,'max_anytour_msk1','max','manager',1),(3,1,'max_anytour_msk','max','manager',1);
    CREATE TABLE conversations(id INTEGER PRIMARY KEY,project_key TEXT,external_chat_id TEXT,channel TEXT,source_id INTEGER,entry_channel TEXT,status TEXT,manager_id INTEGER);
    CREATE TABLE messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,direction TEXT,sender_type TEXT,text TEXT,created_at TEXT DEFAULT '2026-09-29 08:00:00');
    CREATE TABLE conversation_events(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,event_type TEXT,actor_type TEXT,payload_json TEXT,created_at TEXT DEFAULT '2026-09-29 08:00:00');");
    $media=$tmp.'/synthetic.txt';file_put_contents($media,'synthetic fixture');$id=100;
    foreach(['max_anytour_msk1','max_anytour_msk'] as $source){
        foreach(['manager','waiting_manager'] as $status){
            $id++;restartSeed($id,$status);$label=$source.' / '.$status;$before=restartRow($id);
            restartCheck('old start cannot clear a newer suspension: '.$label,ManagerDeliveryStateService::activeFailure($id)['category']??null,'suspended');
            $calls=MaxMessengerAdapter::$calls;
            restartCheck('pre-restart send stays blocked: '.$label,ManagerOutboundService::send($id,7,'Synthetic retry'),false);
            restartCheck('blocked send never reaches provider: '.$label,MaxMessengerAdapter::$calls,$calls);
            $messages=$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll();
            $incoming=['platform'=>'max','type'=>'bot_started','source_key'=>$source,'user'=>['chat_id'=>-$id,'external_user_id'=>$id]];
            restartCheck('source restart is handled: '.$label,MaxStartSourcePolicy::apply($incoming,$source),true);
            MaxStartSourcePolicy::apply($incoming,$source);
            $after=restartRow($id);
            restartCheck('restart preserves routing and ownership: '.$label,[$after['source_id'],$after['manager_id'],$after['status']],[$before['source_id'],$before['manager_id'],$before['status']]);
            restartCheck('restart retains exact entry: '.$label,$after['entry_channel'],$source);
            restartCheck('restart never auto-sends: '.$label,MaxMessengerAdapter::$calls,$calls);
            restartCheck('restart leaves transcript immutable: '.$label,$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll(),$messages);
            restartCheck('same-second fresh restart clears workspace restriction: '.$label,ManagerDeliveryStateService::activeFailure($id),null);
            if($status==='waiting_manager'){
                restartCheck('waiting restart does not grant reply ownership: '.$label,ManagerOutboundService::send($id,7,'Synthetic retry'),false);
                $pdo->prepare("UPDATE conversations SET status='manager',manager_id=7 WHERE id=?")->execute([$id]);
            }
            restartCheck('other manager still cannot send: '.$label,ManagerOutboundService::send($id,8,'Synthetic retry'),false);
            restartCheck('explicit owned text send works after restart: '.$label,ManagerOutboundService::send($id,7,'Synthetic reply'),true);
            restartCheck('one explicit text send reaches provider: '.$label,MaxMessengerAdapter::$calls,$calls+1);
            restartCheck('explicit owned media send works after restart: '.$label,ManagerOutboundService::sendMedia($id,7,$media,'synthetic.txt','text/plain'),true);
            restartCheck('one explicit media send reaches provider: '.$label,MaxMessengerAdapter::$calls,$calls+2);
            MaxMessengerAdapter::$ok=false;
            restartCheck('a new provider suspension remains a failure: '.$label,ManagerOutboundService::send($id,7,'Synthetic later send'),false);
            $calls=MaxMessengerAdapter::$calls;
            restartCheck('new suspension overrides previous restart: '.$label,ManagerDeliveryStateService::activeFailure($id)['category']??null,'suspended');
            restartCheck('new suspension blocks another send: '.$label,ManagerOutboundService::send($id,7,'Synthetic retry'),false);
            restartCheck('new suspension causes no automatic retry: '.$label,MaxMessengerAdapter::$calls,$calls);
            MaxMessengerAdapter::$ok=true;
        }
    }
    restartSeed(200);restartSeed(201);restartEvent(201,'bot_started');
    restartCheck('another conversation cannot clear suspension',ManagerDeliveryStateService::activeFailure(200)['category']??null,'suspended');
    restartEvent(200,'manager_request');restartEvent(200,'source_handling_choice',['choice'=>'manager']);
    restartCheck('manager lifecycle events cannot pretend to be a restart',ManagerDeliveryStateService::activeFailure(200)['category']??null,'suspended');
    restartEvent(200,'bot_started',[],'customer');
    restartCheck('non-system start cannot clear restriction',ManagerDeliveryStateService::activeFailure(200)['category']??null,'suspended');
    restartSeed(202,'manager','telegram');restartEvent(202,'bot_started');
    restartCheck('MAX restart recovery does not apply to another channel',ManagerDeliveryStateService::activeFailure(202)['category']??null,'suspended');
    restartCheck('invalid source does not create restart evidence',MaxStartSourcePolicy::apply(['platform'=>'max','user'=>['chat_id'=>-200,'external_user_id'=>200]],'not_configured'),false);
    restartCheck('invalid source cannot clear suspension',ManagerDeliveryStateService::activeFailure(200)['category']??null,'suspended');
    $pdo->prepare("INSERT INTO messages(conversation_id,direction,sender_type,text,created_at) VALUES(?,'inbound','customer','Synthetic new text','2026-09-29 08:00:01')")->execute([200]);
    restartCheck('existing inbound-message recovery is retained',ManagerDeliveryStateService::activeFailure(200),null);
    restartCheck('existing inbound-message send recovery is retained',ManagerOutboundService::send(200,7,'Synthetic response'),true);
}catch(Throwable $e){$fail++;echo 'FAIL restart-delivery: '.$e->getMessage().PHP_EOL;}
finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($tmp);}
echo "MAX RESTART DELIVERY: PASS {$pass} FAIL {$fail}\n";exit($fail?1:0);
