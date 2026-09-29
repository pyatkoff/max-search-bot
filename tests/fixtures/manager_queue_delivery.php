<?php
declare(strict_types=1);
// Real canonical queue/dispatch owners, isolated SQLite and fake external transports.
$root=dirname(__DIR__,2);
$tmp=sys_get_temp_dir().'/manager-queue-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);
$bootstrap= <<<'CODE'
<?php
class ConversationDb { public static $pdo; public static function isConfigured(){return true;} public static function connection(){return self::$pdo;} }
class ProjectConfig { public static function projectId(){return 'fixture';} }
class ManagerPushService { public static $calls=0,$fail=false; public static function notifyConversation(...$args){self::$calls++;if(self::$fail)throw new RuntimeException('synthetic push failure');} }
class ManagerAvailabilityService { public static $hours=true,$available=true; public static function withinWorkingHours($now=null){return self::$hours;} public static function anyWorkingForConversation($c){return self::$available;} }
class ManagerRequestService { public static $fail=false; public static function prepare(...$args){if(self::$fail)throw new RuntimeException('synthetic prepare failure');return ['back_callback'=>'back_check','online_text'=>'online','working_wait_text'=>'waiting','outside_hours_text'=>'next working period'];} }
class DialogueView {}
class MaxSearchApi { public static function deletePrevMessage($chat){} }
class IntegrationRegistry { public static $messenger; public static function messenger(){return self::$messenger;} }
class FixtureMessenger {
 public $mode='ok',$calls=0,$observed=[];
 public function sendWithButtons($chat,$text,$buttons){
  $this->calls++;$c=ConversationControlService::statusByChat('max',$chat);
  $this->observed[]=[$c['status'],(int)ConversationDb::$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=".(int)$c['id']." AND event_type='waiting_manager'")->fetchColumn(),ConversationDb::$pdo->inTransaction(),$text];
  if($this->mode==='throw')throw new RuntimeException('synthetic transport failure');
  return $this->mode!=='false';
 }
}
CODE;
file_put_contents($tmp.'/bootstrap.php',$bootstrap);
foreach(['ConversationDb','ProjectConfig','ManagerPushService','ManagerAvailabilityService','ManagerRequestService','DialogueView','IntegrationRegistry'] as $name)file_put_contents($tmp.'/'.$name.'.php',"<?php require_once __DIR__.'/bootstrap.php';\n");
foreach(['ConversationControlService','ManagerHandoffDispatchService'] as $name)copy($root.'/services/'.$name.'.php',$tmp.'/'.$name.'.php');
function queueCheck($actual,$expected,string $name):void{if($actual!==$expected)throw new RuntimeException($name.' expected='.json_encode($expected).' actual='.json_encode($actual));echo 'PASS '.$name.PHP_EOL;}
try {
 require $tmp.'/ManagerHandoffDispatchService.php';
 $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);ConversationDb::$pdo=$pdo;IntegrationRegistry::$messenger=new FixtureMessenger();
 $pdo->exec("CREATE TABLE conversations(id INTEGER PRIMARY KEY,project_key TEXT,source_id INTEGER,channel TEXT,external_chat_id TEXT,status TEXT,manager_id INTEGER); CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,actor_type TEXT,actor_id TEXT,payload_json TEXT);");
 $id=0;
 foreach([true,false] as $hours)foreach(['ok','false','throw','prepare_failure','push_failure'] as $mode){
  $id++;$pdo->prepare("INSERT INTO conversations VALUES(?,'fixture',1,'max',?,'ai',NULL)")->execute([$id,(string)$id]);
  ManagerAvailabilityService::$hours=$hours;ManagerRequestService::$fail=$mode==='prepare_failure';ManagerPushService::$fail=$mode==='push_failure';IntegrationRegistry::$messenger->mode=$mode;
  $result=ManagerHandoffDispatchService::dispatch($id,'max');
  queueCheck($result['queue_applied'],true,'request durable: '.json_encode([$hours,$mode]));
  queueCheck(ConversationControlService::statusByChat('max',$id)['status'],'waiting_manager','queue independent of transport and hours');
  queueCheck($result['sent'],!in_array($mode,['false','throw','prepare_failure'],true),'presentation result is truthful');
  $pushes=ManagerPushService::$calls;$sends=IntegrationRegistry::$messenger->calls;
  ManagerHandoffDispatchService::applyQueueDecision($result,'max',$id);
  ManagerHandoffDispatchService::dispatch($id,'max');
  queueCheck(ManagerPushService::$calls,$pushes,'repeat does not repush');
  queueCheck(IntegrationRegistry::$messenger->calls,$sends,'repeat does not resend');
  queueCheck((int)$pdo->query('SELECT COUNT(*) FROM conversation_events WHERE conversation_id='.$id)->fetchColumn(),1,'one durable waiting event');
 }
 foreach(IntegrationRegistry::$messenger->observed as $observation){queueCheck(array_slice($observation,0,3),['waiting_manager',1,false],'queue and event committed before customer send');}
 $pdo->exec("INSERT INTO conversations VALUES(100,'fixture',1,'max','owned','manager',7)");
 ManagerHandoffDispatchService::dispatch('owned','max');
 $owned=ConversationControlService::statusByChat('max','owned');queueCheck([$owned['status'],(int)$owned['manager_id']],['manager',7],'repeat request cannot unassign manager');
 queueCheck(ManagerHandoffDispatchService::dispatch('missing','max')['queue_applied'],false,'missing conversation cannot claim successful queue');
 $pdo->exec("INSERT INTO conversations VALUES(101,'fixture',1,'max','atomic','ai',NULL); CREATE TRIGGER fail_event BEFORE INSERT ON conversation_events BEGIN SELECT RAISE(ABORT,'synthetic event failure'); END;");
 $thrown=false;try{ConversationControlService::markWaitingByChat('max','atomic');}catch(Throwable $e){$thrown=true;}
 queueCheck($thrown,true,'event failure reported');queueCheck(ConversationControlService::statusByChat('max','atomic')['status'],'ai','queue and event rollback together');queueCheck($pdo->inTransaction(),false,'failed mutation releases transaction');
 echo "MANAGER QUEUE DELIVERY: OK\n";
} finally {
 foreach(glob($tmp.'/*') as $file)unlink($file);rmdir($tmp);
}
