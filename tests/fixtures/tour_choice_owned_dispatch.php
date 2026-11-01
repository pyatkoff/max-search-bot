<?php
declare(strict_types=1);
// Run the real dispatcher and source policy. Application/store guards have
// separate tests; this fixture must not hide a source policy that consumes TG.
$root=dirname(__DIR__,2);
$tmp=sys_get_temp_dir().'/tour-choice-dispatch-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);
$bootstrap= <<<'PHP'
<?php
class DialogueApplication {public $calls=[]; public function dispatch(array $incoming):bool {$this->calls[]=$incoming;return true;}}
class DiagnosticLogger {public static $events=[];public static function log(...$args):void {self::$events[]=$args[1]??'';}}
class ConversationRecorder {public static function inbound(...$args):bool {return true;}}
class ConversationAttributionService {public static function syncByChat(...$args):void {}}
class MetrikaConversionGoalService {public static function observeIncoming(...$args):void {} public static function customerActivity(...$args):void {} public static function customerReplyAfterManager(...$args):void {}}
class ConversationDb {public static $pdo;public static function isConfigured():bool{return true;}public static function connection():PDO{return self::$pdo;}}
class ProjectConfig {public static function projectId():string{return 'anytour';}}
class IntegrationRegistry {public static $calls=0;public static function messenger(){++self::$calls;throw new RuntimeException('Unexpected provider send');}}
class ManagerHandoffDispatchService {public static $calls=0;public static function dispatch(...$args){++self::$calls;throw new RuntimeException('Unexpected handoff');}}
class ConversationControlService {public static $status='manager';public static function statusByChat(...$args):array {return ['id'=>17,'status'=>self::$status];}public static function resumeAiByChat(...$args):void {throw new RuntimeException('Unexpected ownership write');}}
class ManagerPushService {public static $calls=[];public static function notifyConversation(...$args):void {self::$calls[]=$args;}}
PHP;
file_put_contents($tmp.'/bootstrap.php',$bootstrap);
$dependencies=['DialogueApplication','DiagnosticLogger','ConversationRecorder','ConversationAttributionService','ConversationControlService','ManagerPushService','MetrikaConversionGoalService','ConversationDb','IntegrationRegistry','ManagerHandoffDispatchService'];
try {
    foreach($dependencies as $name)file_put_contents($tmp.'/'.$name.'.php',"<?php require_once __DIR__.'/bootstrap.php';");
    copy($root.'/services/SourceHandlingService.php',$tmp.'/SourceHandlingService.php');
    copy($root.'/services/IncomingUpdateDispatcher.php',$tmp.'/IncomingUpdateDispatcher.php');
    require $tmp.'/IncomingUpdateDispatcher.php';
    $app=new DialogueApplication();$dispatcher=new IncomingUpdateDispatcher($app);$checks=0;$missing=[];$ownershipFailures=[];
    foreach(['max','telegram'] as $channel)foreach(['manager','waiting_manager'] as $status)foreach([['ask',true],['manager',true],['ask',false]] as [$mode,$hasChoice]){
        $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $pdo->exec('CREATE TABLE conversations(id INTEGER PRIMARY KEY,project_key TEXT,channel TEXT,external_chat_id TEXT,status TEXT,source_id INTEGER)');
        $pdo->exec('CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,handling_mode TEXT)');
        $pdo->exec('CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,payload_json TEXT)');
        $q=$pdo->prepare('INSERT INTO conversations VALUES(17,?,?,?,?,1)');$q->execute(['anytour',$channel,'77',$status]);
        $q=$pdo->prepare('INSERT INTO conversation_sources VALUES(1,?)');$q->execute([$mode]);
        $q=$pdo->prepare('INSERT INTO conversation_events VALUES(1,17,?,?)');
        $q->execute($hasChoice?['source_handling_choice','{"choice":"manager"}']:['source_handling_prompted','{"mode":"ask"}']);
        ConversationDb::$pdo=$pdo;
        ConversationControlService::$status=$status;
        $incoming=['type'=>'callback','platform'=>$channel,'callback_data'=>'tour_like_17_'.str_repeat('a',24),'user'=>['chat_id'=>77]];
        $before=count($app->calls);$pushes=count(ManagerPushService::$calls);
        if(!$dispatcher->dispatch($incoming)||count($app->calls)!==$before+1||end($app->calls)!==$incoming)$missing[]=$channel.'/'.$status.'/'.$mode.'/'.($hasChoice?'manager-choice':'prompted');
        if(ConversationControlService::$status!==$status||count(ManagerPushService::$calls)!==$pushes)throw new RuntimeException('Choice changed ownership or emitted generic push');
        if($pdo->query('SELECT status FROM conversations')->fetchColumn()!==$status||(int)$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn()!==1||IntegrationRegistry::$calls||ManagerHandoffDispatchService::$calls)throw new RuntimeException('Choice changed source/routing state or called a provider');
        $cases=[
            ['type'=>'callback','callback_data'=>'owner_preview_like_20261021'],
            ['type'=>'message','text'=>'Нравится','message_id'=>'synthetic-like-text'],
            ['type'=>'message','text'=>'А на другие даты с ребёнком?','message_id'=>'synthetic-question'],
            ['type'=>'contact','contact_phone'=>'+70000000000','message_id'=>'synthetic-contact'],
            ['type'=>'callback','callback_data'=>'phone_manual'],
        ];
        foreach($cases as $case){
            $value=$case+['platform'=>$channel,'user'=>['chat_id'=>77]];
            $before=count($app->calls);$pushes=count(ManagerPushService::$calls);$events=count(DiagnosticLogger::$events);
            $allowed=$status==='waiting_manager'&&($case['type']==='contact'||($case['callback_data']??'')==='phone_manual');
            $dispatcher->dispatch($value);
            $expectedPush=$allowed?[]:[[17,$status==='manager'?'Клиент ответил в вашем диалоге':'Новое сообщение в заявке',in_array($case['type'],['message','contact'],true)?($case['message_id']??''):'']];
            if(count($app->calls)!==$before+($allowed?1:0)
                ||($allowed&&end($app->calls)!==$value)
                ||array_slice(ManagerPushService::$calls,$pushes)!==$expectedPush
                ||array_slice(DiagnosticLogger::$events,$events)!==[$allowed?'handled':'manager_owned']){
                $ownershipFailures[]=$channel.'/'.$status.'/'.$mode.'/'.($hasChoice?'manager-choice':'prompted').'/'.($case['callback_data']??$case['type'].':'.$case['message_id']);
            }
            if(ConversationControlService::$status!==$status||$pdo->query('SELECT status FROM conversations')->fetchColumn()!==$status
                ||(int)$pdo->query('SELECT source_id FROM conversations')->fetchColumn()!==1
                ||(int)$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn()!==1
                ||IntegrationRegistry::$calls||ManagerHandoffDispatchService::$calls)throw new RuntimeException('Owned input changed source/routing or called a provider');
        }
        ++$checks;
    }
    // Website source handling is outside this native-messenger change. Its
    // existing manager/ask policy must still consume an owned ordinary message.
    $websiteChecks=0;
    foreach(['manager','waiting_manager'] as $status)foreach([['ask',true],['manager',true],['ask',false]] as [$mode,$hasChoice]){
        $q=$pdo->prepare('UPDATE conversations SET channel=?,status=? WHERE id=17');$q->execute(['website',$status]);
        $q=$pdo->prepare('UPDATE conversation_sources SET handling_mode=? WHERE id=1');$q->execute([$mode]);
        $pdo->exec('DELETE FROM conversation_events');
        $q=$pdo->prepare('INSERT INTO conversation_events VALUES(1,17,?,?)');
        $q->execute($hasChoice?['source_handling_choice','{"choice":"manager"}']:['source_handling_prompted','{"mode":"ask"}']);
        ConversationControlService::$status=$status;
        $incoming=['type'=>'message','platform'=>'website','text'=>'А на другие даты с ребёнком?','message_id'=>'synthetic-website-question','user'=>['chat_id'=>77]];
        $before=count($app->calls);$pushes=count(ManagerPushService::$calls);$events=count(DiagnosticLogger::$events);
        $dispatcher->dispatch($incoming);
        if(count($app->calls)!==$before||count(ManagerPushService::$calls)!==$pushes
            ||array_slice(DiagnosticLogger::$events,$events)!==['source_handling']
            ||ConversationControlService::$status!==$status||$pdo->query('SELECT status FROM conversations')->fetchColumn()!==$status
            ||(int)$pdo->query('SELECT source_id FROM conversations')->fetchColumn()!==1
            ||(int)$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn()!==1
            ||IntegrationRegistry::$calls||ManagerHandoffDispatchService::$calls)throw new RuntimeException('Website source handling changed: '.$status.'/'.$mode.'/'.(int)$hasChoice);
        ++$websiteChecks;
    }
    if($missing)throw new RuntimeException('Choice did not reach application in '.count($missing).' real source scenarios: '.implode(', ',$missing));
    if($ownershipFailures)throw new RuntimeException('Canonical owned-input routing failed in '.count($ownershipFailures).' cases: '.implode(', ',$ownershipFailures));
    echo 'PASS '.$checks.' real source-policy dispatcher ownership scenarios and '.$websiteChecks." unchanged website controls\n";
} finally {
    foreach(glob($tmp.'/*') as $file)unlink($file);
    rmdir($tmp);
}
