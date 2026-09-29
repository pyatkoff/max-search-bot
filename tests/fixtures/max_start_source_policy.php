<?php
declare(strict_types=1);
// Real start handler, traffic parser, recorder and source policy; no production DB or sends.
$root=dirname(__DIR__,2);
$tmp=sys_get_temp_dir().'/max-start-policy-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);mkdir($tmp.'/services',0700);mkdir($tmp.'/integrations',0700);mkdir($tmp.'/handlers',0700);
$bootstrap= <<<'PHP'
<?php
class ConversationDb { public static $pdo; public static $configured=true; public static function isConfigured(){return self::$configured;} public static function connection(){return self::$pdo;} }
class ProjectConfig { public static function projectId(){return 'anytour';} public static function get($key,$default=null){return $key==='routing.source_key'?'max:anytour-main':$default;} }
class RoutingAccessService { public static function sourceId($project,$key,$channel=''){ $q=ConversationDb::connection()->prepare('SELECT s.id FROM conversation_sources s JOIN projects p ON p.id=s.project_id WHERE p.project_key=? AND s.source_key=? AND s.channel=? AND s.is_active=1');$q->execute([$project,$key,$channel]);return (int)$q->fetchColumn(); } }
class DiagnosticLogger { public static $events=[]; public static function log(...$args){self::$events[]=$args;} }
class MaxIncomingAdapter { public static function user($update){return $update['user']??[];} }
class IncomingUpdateDispatcher {}
class IncomingUpdateDeduplicator {}
class MaxInboundMediaArchiveService {}
class FixtureMessenger { public $sent=[]; public $ok=true; public function sendWithButtons($chat,$text,$buttons){$this->sent[]=compact('chat','text','buttons');return $this->ok;} }
class IntegrationRegistry { public static $m; public static function messenger(){return self::$m;} }
class MaxSearchApi {
    public static $greetings=[],$resets=[],$events=[],$cancelled=[],$statuses=[];
    public static $statusPhone=75;
    public static function getCurentStatus($chat){return self::$statuses[$chat]??0;}
    public static function getTrafficMeta($chat){return TrafficAttributionService::get(__DIR__,$chat);}
    public static function addYclid(...$args){throw new RuntimeException('legacy mirror must not run');}
    public static function funnelLog($chat,$event,$meta=[]){self::$events[]=$event;}
    public static function cancelToursFollowup($chat){self::$cancelled[]=$chat;}
    public static function deleteAllStatus($chat){self::$resets[]=$chat;}
    public static function setEditMode(...$args){}
    public static function showStart($chat,$meta=[]){self::$greetings[]=compact('chat','meta');return true;}
}
class ManagerAvailabilityService { public static function withinWorkingHours(){return ManagerHandoffDispatchService::$workingHours;} }
class ManagerHandoffDispatchService {
    public static $calls=[],$applied=0,$available=true,$workingHours=true;
    public static function sourceEntryText(){return ManagerRequestService::sourceEntryMessageText(self::$workingHours);}
    public static function dispatch($chat,$platform,$name,$fromTours){$q=ConversationDb::connection()->prepare("SELECT source_id,entry_channel FROM conversations WHERE project_key=? AND channel=? AND external_chat_id=? AND status<>'closed' ORDER BY id DESC LIMIT 1");$q->execute([ProjectConfig::projectId(),$platform,(string)$chat]);self::$calls[]=$q->fetch();return ['sent'=>true,'manager_available'=>self::$available,'within_working_hours'=>self::$workingHours,'queue_waiting'=>true];}
    public static function applyQueueDecision($decision,$platform,$chat,$metadata){self::$applied++;if(!empty($decision['queue_waiting'])){$q=ConversationDb::connection()->prepare("UPDATE conversations SET status='waiting_manager' WHERE project_key=? AND channel=? AND external_chat_id=?");$q->execute([ProjectConfig::projectId(),$platform,(string)$chat]);}}
}
PHP;
file_put_contents($tmp.'/bootstrap.php',$bootstrap);
foreach(['ConversationDb','ProjectConfig','RoutingAccessService','DiagnosticLogger','IntegrationRegistry','ManagerHandoffDispatchService','IncomingUpdateDispatcher','IncomingUpdateDeduplicator','MaxInboundMediaArchiveService'] as $name)file_put_contents($tmp.'/services/'.$name.'.php',"<?php require_once dirname(__DIR__).'/bootstrap.php';\n");
file_put_contents($tmp.'/integrations/MaxIncomingAdapter.php',"<?php require_once dirname(__DIR__).'/bootstrap.php';\n");
foreach(['services/ManagerRequestService.php','services/TrafficAttributionService.php','services/ConversationRecorder.php','services/SourceHandlingService.php','services/MaxStartSourcePolicy.php','handlers/MaxUpdateHandler.php'] as $path)copy($root.'/'.$path,$tmp.'/'.$path);
$passed=0;$failed=0;
function startCheck(string $name,$actual,$expected):void{global $passed,$failed;if($actual===$expected){echo 'PASS '.$name.PHP_EOL;$passed++;}else{echo 'FAIL '.$name.' expected='.json_encode($expected).' actual='.json_encode($actual).PHP_EOL;$failed++;}}
function fixtureIncoming(int $user,string $source='max_anytour_msk1',string $type='bot_started'):array{return ['platform'=>'max','type'=>$type,'source_key'=>$source,'text'=>'fixture','user'=>['chat_id'=>-$user,'external_user_id'=>$user,'first_name'=>'Synthetic','last_name'=>'Tester','username'=>'fixture']];}
function fixtureStart(int $user,string $source='max_anytour_msk1'):void{MaxUpdateHandler::handleStarted(['update_type'=>'bot_started','timestamp'=>1700000000000+$user,'payload'=>$source,'user'=>['user_id'=>$user,'name'=>'Synthetic Tester']]);}
function fixtureConversation(int $user):array{$q=ConversationDb::connection()->prepare("SELECT * FROM conversations WHERE project_key='anytour' AND channel='max' AND external_chat_id=? AND status<>'closed' ORDER BY id DESC LIMIT 1");$q->execute([(string)-$user]);return $q->fetch()?:[];}
try{
    require $tmp.'/services/ManagerRequestService.php';
    require $tmp.'/handlers/MaxUpdateHandler.php';
    $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));ConversationDb::$pdo=$pdo;IntegrationRegistry::$m=new FixtureMessenger();
    $pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT);INSERT INTO projects VALUES(1,'anytour'),(2,'other');
    CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,project_id INTEGER,source_key TEXT,channel TEXT,handling_mode TEXT,is_active INTEGER);
    INSERT INTO conversation_sources VALUES(1,1,'max:anytour-main','max','ai',1),(2,1,'max_anytour_msk1','max','manager',1),(3,1,'max_fixture_ask','max','ask',1),(4,1,'max_fixture_inactive','max','manager',0),(5,1,'max_fixture_wrong_channel','telegram','manager',1),(6,2,'max_fixture_other_project','max','manager',1),(7,1,'max_fixture_ai','max','ai',1),(8,1,'max_anytour_msk','max','manager',1);
    CREATE TABLE customers(id INTEGER PRIMARY KEY AUTOINCREMENT,display_name TEXT);
    CREATE TABLE customer_channels(id INTEGER PRIMARY KEY AUTOINCREMENT,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT,username TEXT,metadata_json TEXT);
    CREATE TABLE conversations(id INTEGER PRIMARY KEY AUTOINCREMENT,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,external_chat_id TEXT,status TEXT,manager_id INTEGER,entry_channel TEXT,last_message_at TEXT);
    CREATE TABLE messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,direction TEXT,sender_type TEXT,sender_id TEXT,channel TEXT,external_message_id TEXT,text TEXT,metadata_json TEXT);
    CREATE TABLE conversation_events(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,event_type TEXT,actor_type TEXT,payload_json TEXT);");

    fixtureStart(990001);
    startCheck('exact deep link reaches configured manager policy',count(ManagerHandoffDispatchService::$calls),1);
    startCheck('exact source is bound before handoff',(int)ManagerHandoffDispatchService::$calls[0]['source_id'],2);
    startCheck('entry attribution is synced before handoff',ManagerHandoffDispatchService::$calls[0]['entry_channel'],'max_anytour_msk1');
    startCheck('canonical queue decision applied',ManagerHandoffDispatchService::$applied,1);
    startCheck('conversation is waiting for manager',fixtureConversation(990001)['status'],'waiting_manager');
    startCheck('manager start does not show AI chooser',count(MaxSearchApi::$greetings),0);
    startCheck('manager start does not reset questionnaire',count(MaxSearchApi::$resets),0);
    startCheck('start is not a fake customer message',(int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn(),0);
    startCheck('start event is recorded',(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='bot_started'")->fetchColumn(),1);
    startCheck('MAX payload is preserved in existing traffic store',MaxSearchApi::getTrafficMeta(-990001)['raw'],'max_anytour_msk1');
    $ackBefore=count(IntegrationRegistry::$m->sent);
    fixtureStart(990001);fixtureStart(990001);
    startCheck('repeated waiting starts stay silent',count(IntegrationRegistry::$m->sent),$ackBefore);
    startCheck('repeat starts do not request manager again',count(ManagerHandoffDispatchService::$calls),1);
    startCheck('repeat starts do not send AI greetings',count(MaxSearchApi::$greetings),0);
    startCheck('repeat starts keep existing waiting state',fixtureConversation(990001)['status'],'waiting_manager');
    startCheck('follow-up customer text reaches dispatcher ownership',SourceHandlingService::handle(fixtureIncoming(990001,'max_anytour_msk1','message')),false);
    startCheck('follow-up contact reaches dispatcher ownership',SourceHandlingService::handle(fixtureIncoming(990001,'max_anytour_msk1','contact')),false);
    $pdo->exec("UPDATE conversations SET status='manager',manager_id=7 WHERE external_chat_id='-990001'");
    fixtureStart(990001,'entry_max_fixture_ask');
    startCheck('new deep link cannot steal current manager',(int)fixtureConversation(990001)['manager_id'],7);
    startCheck('owned source is preserved',(int)fixtureConversation(990001)['source_id'],2);
    startCheck('owned conversation is not reset',count(MaxSearchApi::$resets),0);

    ConversationRecorder::inbound(fixtureIncoming(990002,'max:anytour-main','message'));
    ConversationRecorder::eventByChat('max',-990002,'source_handling_choice',['choice'=>'ai']);
    $messagesBefore=$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll();
    fixtureStart(990002);
    startCheck('legacy main conversation receives exact source',(int)fixtureConversation(990002)['source_id'],2);
    startCheck('fresh explicit link applies manager policy despite old self-service choice',fixtureConversation(990002)['status'],'waiting_manager');
    startCheck('existing customer messages remain byte-for-byte intact',$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll(),$messagesBefore);

    $choiceBefore=count(IntegrationRegistry::$m->sent);
    fixtureStart(990003,'entry_max_fixture_ask');fixtureStart(990003,'entry_max_fixture_ask');
    startCheck('configured ask source creates one choice prompt',count(IntegrationRegistry::$m->sent),$choiceBefore+1);
    startCheck('ask prompt contains manager choice',IntegrationRegistry::$m->sent[$choiceBefore]['buttons'][0][0]['callback_data'],'source_choice_manager');
    startCheck('ask start does not fall back to AI chooser',count(MaxSearchApi::$greetings),0);
    $choice=fixtureIncoming(990003,'max_fixture_ask','callback');$choice['callback_data']='source_choice_manager';
    SourceHandlingService::handle($choice);
    startCheck('ask manager choice applies existing handoff',fixtureConversation(990003)['status'],'waiting_manager');
    $back=fixtureIncoming(990003,'max_fixture_ask','callback');$back['callback_data']='back_check';
    startCheck('Back remains delegated to existing dispatcher',SourceHandlingService::handle($back),false);
    $pdo->exec("UPDATE conversations SET status='ai' WHERE external_chat_id='-990003'");
    startCheck('Back restores self-service for the following message',SourceHandlingService::handle(fixtureIncoming(990003,'max_fixture_ask','message')),false);

    ManagerHandoffDispatchService::$available=false;ManagerHandoffDispatchService::$workingHours=false;
    fixtureStart(990004);$count=count(ManagerHandoffDispatchService::$calls);fixtureStart(990004);
    startCheck('outside-hours policy does not trigger repeat handoff',count(ManagerHandoffDispatchService::$calls),$count);
    startCheck('outside-hours start never falls back to AI chooser',count(MaxSearchApi::$greetings),0);
    startCheck('outside-hours handoff enters queue',fixtureConversation(990004)['status'],'waiting_manager');
    startCheck('phone response is not swallowed after source handoff',SourceHandlingService::handle(fixtureIncoming(990004,'max_anytour_msk1','contact')),false);
    startCheck('optional phone text reaches existing application',SourceHandlingService::handle(fixtureIncoming(990004,'max_anytour_msk1','message')),false);
    ManagerHandoffDispatchService::$available=true;ManagerHandoffDispatchService::$workingHours=true;

    $before=count(ManagerHandoffDispatchService::$calls);fixtureStart(990008,'max_anytour_msk');fixtureStart(990008,'max_anytour_msk');
    startCheck('published msk without 1 reaches manager once',count(ManagerHandoffDispatchService::$calls),$before+1);
    startCheck('published msk without 1 keeps its own source',(int)fixtureConversation(990008)['source_id'],8);
    startCheck('published msk without 1 keeps exact attribution',fixtureConversation(990008)['entry_channel'],'max_anytour_msk');
    startCheck('published msk without 1 reaches existing manager queue',fixtureConversation(990008)['status'],'waiting_manager');

    foreach(['max_unknown','max_fixture_inactive','max_fixture_wrong_channel','max_fixture_other_project','max_fixture_ai',''] as $key){
        $before=(int)$pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
        startCheck('non-manager or invalid source leaves legacy start unchanged: '.$key,MaxStartSourcePolicy::apply(fixtureIncoming(990100,$key),$key),false);
        startCheck('invalid/AI source creates no conversation: '.$key,(int)$pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn(),$before);
    }
    $before=count(MaxSearchApi::$greetings);
    fixtureStart(990005,'123456789_213_campaign_42');
    startCheck('paid entry still uses original start renderer',count(MaxSearchApi::$greetings),$before+1);
    startCheck('paid campaign remains unchanged',MaxSearchApi::$greetings[$before]['meta']['campaign_id'],'42');
    startCheck('paid region remains unchanged',MaxSearchApi::$greetings[$before]['meta']['region_id'],'213');
    $tg=fixtureIncoming(990006);$tg['platform']='telegram';
    startCheck('MAX start hook cannot change Telegram source behavior',MaxStartSourcePolicy::apply($tg,'max_anytour_msk1'),false);
    startCheck('no source configuration rows created',(int)$pdo->query('SELECT COUNT(*) FROM conversation_sources')->fetchColumn(),8);

    // Both owner-reported links must refresh an already-owned legacy-main dialogue.
    $fixtureUser=991100;
    foreach(['max_anytour_msk1','max_anytour_msk'] as $key){
        foreach(['manager','waiting_manager'] as $status){
            $id=$fixtureUser++;
            ConversationRecorder::inbound(fixtureIncoming($id,'max:anytour-main','message'));
            $q=$pdo->prepare("UPDATE conversations SET status=?,manager_id=?,entry_channel='' WHERE external_chat_id=?");
            $q->execute([$status,$status==='manager'?7:null,(string)-$id]);
            $ackBefore=count(IntegrationRegistry::$m->sent);
            $owned=fixtureConversation($id);$calls=count(ManagerHandoffDispatchService::$calls);$resets=count(MaxSearchApi::$resets);
            $messages=$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll();
            fixtureStart($id,$key);fixtureStart($id,$key);
            $after=fixtureConversation($id);$label=$key.' / '.$status;
            startCheck('owned reentry refreshes exact entry: '.$label,$after['entry_channel'],$key);
            startCheck('owned reentry preserves routing source: '.$label,$after['source_id'],$owned['source_id']);
            startCheck('owned reentry preserves manager: '.$label,$after['manager_id'],$owned['manager_id']);
            startCheck('owned reentry preserves status: '.$label,$after['status'],$status);
            startCheck('owned reentry stays silent: '.$label,count(IntegrationRegistry::$m->sent),$ackBefore);
            startCheck('owned reentry sends no new handoff: '.$label,count(ManagerHandoffDispatchService::$calls),$calls);
            startCheck('owned reentry never resets dialogue: '.$label,count(MaxSearchApi::$resets),$resets);
            startCheck('owned reentry preserves transcript: '.$label,$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll(),$messages);
        }
    }
    foreach(['max_anytour_msk1','max_anytour_msk'] as $key){
        $id=$fixtureUser++;ManagerHandoffDispatchService::$workingHours=false;
        fixtureStart($id,$key);$calls=count(ManagerHandoffDispatchService::$calls);fixtureStart($id,$key);
        startCheck('night repeat sends no second offer: '.$key,count(ManagerHandoffDispatchService::$calls),$calls);
        startCheck('night request enters queue: '.$key,fixtureConversation($id)['status'],'waiting_manager');
        ManagerHandoffDispatchService::$workingHours=true;ManagerHandoffDispatchService::$available=false;
        SourceHandlingService::handle(fixtureIncoming($id,$key,'contact'));
        startCheck('contact is not a new start: '.$key,count(ManagerHandoffDispatchService::$calls),$calls);
        fixtureStart($id,$key);fixtureStart($id,$key);
        startCheck('day reentry preserves existing night queue: '.$key,count(ManagerHandoffDispatchService::$calls),$calls);
        startCheck('day reentry queues even with no online manager: '.$key,fixtureConversation($id)['status'],'waiting_manager');
        startCheck('day reentry retains exact attribution: '.$key,fixtureConversation($id)['entry_channel'],$key);
        ManagerHandoffDispatchService::$available=true;
    }
    foreach(['max_anytour_msk1','max_anytour_msk'] as $key){
        $id=$fixtureUser++;ManagerHandoffDispatchService::$workingHours=false;
        ConversationRecorder::inbound(fixtureIncoming($id,$key,'message'));
        ConversationRecorder::eventByChat('max',-$id,'source_handling_choice',['choice'=>'manager']);
        ConversationRecorder::eventByChat('max',-$id,'manager_request',['within_working_hours'=>false]);
        fixtureStart($id,$key);
        startCheck('documented legacy night request resumes even at night: '.$key,fixtureConversation($id)['status'],'waiting_manager');
    }
    ManagerHandoffDispatchService::$workingHours=true;
    ManagerHandoffDispatchService::$workingHours=false;
    $ackBefore=count(IntegrationRegistry::$m->sent);fixtureStart(990001,'max_anytour_msk');
    startCheck('assigned manager night reentry stays silent',count(IntegrationRegistry::$m->sent),$ackBefore);
    ManagerHandoffDispatchService::$workingHours=true;
    // Reentry must not depend on messenger availability or send any message.
    IntegrationRegistry::$m->ok=false;
    $ownedBefore=fixtureConversation(990001);$calls=count(ManagerHandoffDispatchService::$calls);
    fixtureStart(990001,'max_anytour_msk');
    $ownedAfter=fixtureConversation(990001);
    startCheck('unavailable messenger reentry preserves manager',$ownedAfter['manager_id'],$ownedBefore['manager_id']);
    startCheck('unavailable messenger reentry preserves status',$ownedAfter['status'],$ownedBefore['status']);
    startCheck('unavailable messenger reentry cannot repeat handoff',count(ManagerHandoffDispatchService::$calls),$calls);
    startCheck('unavailable messenger reentry attempts no send',count(IntegrationRegistry::$m->sent),$ackBefore);
    IntegrationRegistry::$m->ok=true;

    // A fresh explicit manager-source start itself expresses current manager intent.
    foreach([null,[],['within_working_hours'=>true],['within_working_hours'=>0],['within_working_hours'=>'false']] as $latest){
        $id=$fixtureUser++;ConversationRecorder::inbound(fixtureIncoming($id,'max_anytour_msk1','message'));
        ConversationRecorder::eventByChat('max',-$id,'source_handling_choice',['choice'=>'manager']);
        if($latest!==null){
            ConversationRecorder::eventByChat('max',-$id,'manager_request',['within_working_hours'=>false]);
            ConversationRecorder::eventByChat('max',-$id,'manager_request',$latest);
        }
        $calls=count(ManagerHandoffDispatchService::$calls);fixtureStart($id);
        startCheck('fresh explicit start queues regardless of old delivery evidence: '.json_encode($latest),count(ManagerHandoffDispatchService::$calls),$calls+1);
    }

    $before=count(MaxSearchApi::$greetings);$pdo->exec('DROP TABLE customers');
    fixtureStart(990007);
    startCheck('known manager source fails closed on recorder failure',count(MaxSearchApi::$greetings),$before);
    $handler=(string)file_get_contents($root.'/handlers/MaxUpdateHandler.php');
    startCheck('webhook dispatches the tested start method',str_contains($handler,'self::handleStarted($update);'),true);
    startCheck('secret validation and deduplication remain before start dispatch',strpos($handler,'IncomingUpdateDeduplicator::claim($update)')<strpos($handler,'self::handleStarted($update);'),true);
    $policy=(string)file_get_contents($root.'/services/MaxStartSourcePolicy.php');
    startCheck('start adapter delegates to canonical source owner',str_contains($policy,'SourceHandlingService::handle($start)'),true);
    startCheck('start adapter has no parallel manager dispatch',str_contains($policy,'ManagerHandoffDispatchService::'),false);
    startCheck('start adapter never deletes conversation messages',str_contains($policy,'DELETE FROM messages'),false);
    $source=(string)file_get_contents($root.'/services/SourceHandlingService.php');
    startCheck('start policy emits no new Metrika goal',str_contains($source,'MetrikaConversionGoalService::')||str_contains($source,'queueMetrikaGoal('),false);
}catch(Throwable $e){echo 'FAIL max-start-policy: '.$e->getMessage().PHP_EOL;$failed++;}
finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($tmp);}
echo "MAX START POLICY: PASS {$passed} FAIL {$failed}\n";
exit($failed?1:0);