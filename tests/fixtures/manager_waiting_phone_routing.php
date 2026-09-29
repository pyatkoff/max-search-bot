<?php
declare(strict_types=1);
// Actual dispatcher/classifier, synthetic conversation and side-effect transports.
$root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/quiet-wait-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);mkdir($tmp.'/services',0700);mkdir($tmp.'/handlers',0700);
$bootstrap= <<<'CODE'
<?php
class DialogueApplication { public $calls=[]; public function dispatch($i){$this->calls[]=$i;return true;} }
class DiagnosticLogger { public static function log(...$args){} }
class ConversationRecorder { public static $incoming=[];public static function inbound($i){self::$incoming[]=$i;} }
class ConversationAttributionService { public static function syncByChat(...$args){} }
class MetrikaConversionGoalService {public static function customerActivity(...$args){} public static function customerReplyAfterManager(...$args){} }
class SourceHandlingService { public static function handle($i){return false;} }
class ConversationControlService {public static $status='waiting_manager'; public static function statusByChat(...$args){return ['id'=>1,'status'=>self::$status];} public static function resumeAiByChat(...$args){self::$status='ai';return true;} }
class ManagerPushService { public static $calls=[];public static function notifyConversation(...$args){self::$calls[]=$args;} }
class MaxSearchApi {public static $statusPhone=75;public static function getCurentStatus($chat){return self::$statusPhone;} }
CODE;
file_put_contents($tmp.'/bootstrap.php',$bootstrap);
foreach(['DialogueApplication','DiagnosticLogger','ConversationRecorder','ConversationAttributionService','ConversationControlService','ManagerPushService','MetrikaConversionGoalService','SourceHandlingService','DialogueView','WizardStepView','EditFlowService','IntegrationRegistry','NeedValueResolver','NeedApplicationService','NeedProgressionService','ExistingWizardStepApplicationService','ChildAgeValueContract','DialogueTransitionObserver','DepartureCityResolver','DepartureCityValueContract','CountryValueContract','DateParser'] as $name)file_put_contents($tmp.'/services/'.$name.'.php',"<?php require_once dirname(__DIR__).'/bootstrap.php';\n");
foreach(['AiDateHandler','AiMessageHandler'] as $name)file_put_contents($tmp.'/handlers/'.$name.'.php',"<?php require_once dirname(__DIR__).'/bootstrap.php';\n");
copy($root.'/services/IncomingUpdateDispatcher.php',$tmp.'/services/IncomingUpdateDispatcher.php');
copy($root.'/handlers/StateMessageHandler.php',$tmp.'/handlers/StateMessageHandler.php');
function quietCheck($a,$b,string $name):void{if($a!==$b)throw new RuntimeException($name.' expected='.json_encode($b).' actual='.json_encode($a));echo 'PASS '.$name.PHP_EOL;}
try{
 require $tmp.'/services/IncomingUpdateDispatcher.php';
 $app=new DialogueApplication();$dispatcher=new IncomingUpdateDispatcher($app);
 foreach(['max','telegram','website'] as $platform){
  ConversationControlService::$status='waiting_manager';
  $incoming=['platform'=>$platform,'type'=>'message','user'=>['chat_id'=>-900001],'message_id'=>'synthetic-1','text'=>''];
  foreach(['Здравствуйте, хочу тур в Турцию','Подожду','10.10.2026 на 7 ночей, 2 взрослых'] as $i=>$text){
   $incoming['text']=$text;$incoming['message_id']='synthetic-'.$platform.'-'.$i;
   $calls=count($app->calls);$pushes=count(ManagerPushService::$calls);
   quietCheck($dispatcher->dispatch($incoming),true,'ordinary waiting question handled');
   quietCheck(count($app->calls),$calls,'no phone-format or bot reply for ordinary text');
   quietCheck(count(ManagerPushService::$calls),$pushes+1,'manager notified of waiting question');
   quietCheck(end(ManagerPushService::$calls)[2],$incoming['message_id'],'notification retains message dedup key');
   quietCheck(end(ConversationRecorder::$incoming),$incoming,'question recorded unchanged');
   quietCheck(ConversationControlService::$status,'waiting_manager','question preserves queue');
  }
  foreach(['+71234567890','81234567890','+71234'] as $phone){
   $incoming['text']=$phone;$calls=count($app->calls);$dispatcher->dispatch($incoming);
   quietCheck(count($app->calls),$calls+1,'phone submission retains existing capture/validation');
  }
  foreach([['type'=>'contact'],['type'=>'callback','callback_data'=>'phone_manual']] as $fields){
   $calls=count($app->calls);$dispatcher->dispatch(array_merge($incoming,$fields));
   quietCheck(count($app->calls),$calls+1,'explicit contact capture remains available');
  }
  ConversationControlService::$status='manager';$calls=count($app->calls);$pushes=count(ManagerPushService::$calls);
  $incoming['text']='Уточнение к туру';$dispatcher->dispatch($incoming);
  quietCheck(count($app->calls),$calls,'assigned conversation stays out of AI');
  quietCheck(count(ManagerPushService::$calls),$pushes+1,'assigned manager still notified');
  ConversationControlService::$status='waiting_manager';$calls=count($app->calls);
  $dispatcher->dispatch(array_merge($incoming,['type'=>'callback','callback_data'=>'back_check']));
  quietCheck(ConversationControlService::$status,'ai','explicit return to AI preserved');
  quietCheck(count($app->calls),$calls+1,'explicit return continues existing application');
 }
 echo "QUIET MANAGER WAITING: OK\n";
}finally{
 foreach(glob($tmp.'/services/*') as $f)unlink($f);foreach(glob($tmp.'/handlers/*') as $f)unlink($f);unlink($tmp.'/bootstrap.php');rmdir($tmp.'/services');rmdir($tmp.'/handlers');rmdir($tmp);
}
