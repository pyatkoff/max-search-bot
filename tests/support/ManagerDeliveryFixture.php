<?php
/** CLI-only test sandbox. Real outbound/recorder/detail source + native PDO,
 * with all transports, permissions and side-effect collaborators replaced locally.
 * No production config, credential, endpoint or external request is loaded.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$fixtureRoot = sys_get_temp_dir().'/manager-delivery-'.bin2hex(random_bytes(8));
mkdir($fixtureRoot,0700);mkdir($fixtureRoot.'/services');mkdir($fixtureRoot.'/integrations');
register_shutdown_function(static function() use($fixtureRoot):void {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($fixtureRoot);
});
$source=dirname(__DIR__,2);
foreach(['ManagerOutboundService','ManagerConversationService','ConversationRecorder','ManagerMessageMediaService','ManagerMessageDeliveryService'] as $name){
    if(is_file($source.'/services/'.$name.'.php'))copy($source.'/services/'.$name.'.php',$fixtureRoot.'/services/'.$name.'.php');
}
// Stub dependencies are written only inside this random temporary test directory.
$stubs= <<<'STUB'
<?php
class ConversationDb {
    public static PDO $pdo;
    public static function connection():PDO{return self::$pdo;}
    public static function isConfigured():bool{return true;}
}
class ManagerConversationAccessPolicy {
    public static function canView(int $managerId,array $row):bool{return $managerId===7 && ($row['project_key']??'')==='fixture';}
}
class ManagerReadService {public static array $reads=[];public static function ensureSchema():void{} public static function markRead($m,$c):void{self::$reads[]=[$m,$c];}}
class RoutingAccessService {public static function ensureSchema():void{}}
class ProjectAccessService {}
class ManagerAuthService {}
class ManagerDeliveryStateService {}
class SalesPipelineService {}
class CallbackGeneration {}
class ProjectConfig {}
class ConversationControlService {public static array $events=[];public static function event(...$args):void{self::$events[]=$args;}}
class ManagerPushService {public static array $calls=[];public static function notifyConversation(...$args):void{self::$calls[]=$args;}}
class MetrikaConversionGoalService {public static int $calls=0;public static function managerReply(...$args):void{self::$calls++;}}
class ManagerSendGuardService {
    public static bool $duplicate=false;
    public static function acquire(...$args):bool{return true;}
    public static function release(...$args):void{}
    public static function isImmediateDuplicate(...$args):bool{return self::$duplicate;}
}
class SyntheticAdapter {
    public static bool $ok=true;public static bool $throw=false;public static array $calls=[];
    public function __construct(...$args){}
    public function send(...$args):bool{self::$calls[]=['text',...$args];if(self::$throw)throw new RuntimeException('Synthetic lost response');return self::$ok;}
    public function sendMedia(...$args):bool{self::$calls[]=['media',...$args];if(self::$throw)throw new RuntimeException('Synthetic lost response');return self::$ok;}
}
class MaxMessengerAdapter extends SyntheticAdapter {}
class TelegramMessengerAdapter extends SyntheticAdapter {}
class WebsiteMessengerAdapter extends SyntheticAdapter {}
STUB;
file_put_contents($fixtureRoot.'/stubs.php',$stubs);
foreach(['ConversationDb','ConversationControlService','ProjectAccessService','RoutingAccessService','ManagerConversationAccessPolicy','ManagerReadService','ManagerAuthService','ManagerDeliveryStateService','SalesPipelineService','CallbackGeneration','ProjectConfig','ManagerPushService','ManagerSendGuardService','MetrikaConversionGoalService'] as $name){
    file_put_contents($fixtureRoot.'/services/'.$name.'.php',"<?php require_once __DIR__.'/../stubs.php';\n");
}
foreach(['MaxMessengerAdapter','TelegramMessengerAdapter','WebsiteMessengerAdapter'] as $name)file_put_contents($fixtureRoot.'/integrations/'.$name.'.php',"<?php require_once __DIR__.'/../stubs.php';\n");
require_once $fixtureRoot.'/stubs.php';
require_once $fixtureRoot.'/services/ManagerOutboundService.php';
require_once $fixtureRoot.'/services/ManagerMessageMediaService.php';

if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('Native pdo_sqlite is required');
$fixtureDatabase=$fixtureRoot.'/messages.sqlite';
function deliveryFixtureReconnect():PDO {
    global $fixtureDatabase;
    $pdo=new PDO('sqlite:'.$fixtureDatabase,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('NOW',static fn()=>'2026-09-21 12:00:00');
    ConversationDb::$pdo=$pdo;return $pdo;
}
$pdo=deliveryFixtureReconnect();
$pdo->exec('CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT)');
$pdo->exec('CREATE TABLE managers(id INTEGER PRIMARY KEY,display_name TEXT)');
$pdo->exec('CREATE TABLE projects(project_key TEXT PRIMARY KEY,display_name TEXT)');
$pdo->exec('CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,display_name TEXT)');
$pdo->exec('CREATE TABLE conversations(id INTEGER PRIMARY KEY,project_key TEXT,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,external_chat_id TEXT,customer_id INTEGER)');
$pdo->exec('CREATE TABLE messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,direction TEXT,sender_type TEXT,sender_id TEXT,channel TEXT,text TEXT,metadata_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,created_at TEXT,payload_json TEXT)');
$pdo->exec("INSERT INTO customers VALUES(1,'Синтетический турист',NULL,NULL);INSERT INTO managers VALUES(7,'Тестовый менеджер');INSERT INTO projects VALUES('fixture','Test')");
foreach([11=>'max',12=>'telegram',13=>'website',14=>'other'] as $id=>$channel){
    $q=$pdo->prepare("INSERT INTO conversations(id,project_key,channel,status,manager_id,external_chat_id,customer_id) VALUES(?,'fixture',?,'manager',7,?,1)");$q->execute([$id,$channel,'synthetic-chat-'.$id]);
}
$fixtureFile=$fixtureRoot.'/synthetic.txt';file_put_contents($fixtureFile,'Synthetic content, never uploaded');
