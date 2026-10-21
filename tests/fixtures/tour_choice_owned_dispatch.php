<?php
declare(strict_types=1);
// Run the actual dispatcher with isolated leaf collaborators, as in the
// existing quiet-manager fixture. The application and store have separate tests.
$root=dirname(__DIR__,2);
$tmp=sys_get_temp_dir().'/tour-choice-dispatch-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);
$bootstrap= <<<'PHP'
<?php
class DialogueApplication {public $calls=[]; public function dispatch(array $incoming):bool {$this->calls[]=$incoming;return true;}}
class DiagnosticLogger {public static function log(...$args):void {}}
class ConversationRecorder {public static function inbound(...$args):bool {return true;}}
class ConversationAttributionService {public static function syncByChat(...$args):void {}}
class MetrikaConversionGoalService {public static function customerActivity(...$args):void {} public static function customerReplyAfterManager(...$args):void {}}
class SourceHandlingService {public static function handle(...$args):bool {return false;}}
class ConversationControlService {public static $status='manager';public static function statusByChat(...$args):array {return ['id'=>17,'status'=>self::$status];}public static function resumeAiByChat(...$args):void {throw new RuntimeException('Unexpected ownership write');}}
class ManagerPushService {public static $calls=[];public static function notifyConversation(...$args):void {self::$calls[]=$args;}}
PHP;
file_put_contents($tmp.'/bootstrap.php',$bootstrap);
$dependencies=['DialogueApplication','DiagnosticLogger','ConversationRecorder','ConversationAttributionService','ConversationControlService','ManagerPushService','MetrikaConversionGoalService','SourceHandlingService'];
try {
    foreach($dependencies as $name)file_put_contents($tmp.'/'.$name.'.php',"<?php require_once __DIR__.'/bootstrap.php';");
    copy($root.'/services/IncomingUpdateDispatcher.php',$tmp.'/IncomingUpdateDispatcher.php');
    require $tmp.'/IncomingUpdateDispatcher.php';
    $app=new DialogueApplication();$dispatcher=new IncomingUpdateDispatcher($app);$checks=0;
    foreach(['max','telegram'] as $channel)foreach(['manager','waiting_manager'] as $status){
        ConversationControlService::$status=$status;
        $incoming=['type'=>'callback','platform'=>$channel,'callback_data'=>'tour_like_17_'.str_repeat('a',24),'user'=>['chat_id'=>77]];
        $before=count($app->calls);$pushes=count(ManagerPushService::$calls);
        if(!$dispatcher->dispatch($incoming)||count($app->calls)!==$before+1||end($app->calls)!==$incoming)throw new RuntimeException('Choice did not reach application');
        if(ConversationControlService::$status!==$status||count(ManagerPushService::$calls)!==$pushes)throw new RuntimeException('Choice changed ownership or emitted generic push');
        $incoming['callback_data']='owner_preview_like_20261021';$before=count($app->calls);
        $dispatcher->dispatch($incoming);
        if(count($app->calls)!==$before||ConversationControlService::$status!==$status)throw new RuntimeException('Preview callback escaped manager ownership');
        $incoming['type']='message';$incoming['text']='Нравится';$before=count($app->calls);
        $dispatcher->dispatch($incoming);
        if(count($app->calls)!==$before||ConversationControlService::$status!==$status)throw new RuntimeException('Ordinary text became a specific choice');
        ++$checks;
    }
    echo 'PASS '.$checks." real dispatcher ownership scenarios\n";
} finally {
    foreach(glob($tmp.'/*') as $file)unlink($file);
    rmdir($tmp);
}
