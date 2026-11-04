<?php
declare(strict_types=1);

// Real incoming/controller/V2/queue owners. Only model, unrelated services and
// external transports are replaced; durable handoff uses isolated SQLite.
$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir().'/dialogue-manager-interrupt-'.bin2hex(random_bytes(8));
mkdir($tmp, 0700);
foreach (['services', 'handlers', 'actions/callbacks', 'contracts'] as $directory) mkdir($tmp.'/'.$directory, 0700, true);
$bootstrap = <<<'PHP'
<?php
class ConversationDb { public static $pdo; public static function isConfigured(){return true;} public static function connection(){return self::$pdo;} }
class ProjectConfig { public static function projectId(){return 'fixture';} public static function get($key,$default=null){return $default;} }
class DiagnosticLogger { public static $events=[]; public static function log(...$args){self::$events[]=$args;} public static function error(...$args){self::$events[]=$args;} }
class ConversationRecorder { public static $incoming=[],$events=[]; public static function inbound($incoming){self::$incoming[]=$incoming;} public static function eventByChat(...$args){self::$events[]=$args;} }
class ConversationAttributionService { public static function syncByChat(...$args){} }
class MetrikaConversionGoalService { public static function managerRequest($id){return false;} public static function observeIncoming(...$args){} public static function customerActivity(...$args){} public static function customerReplyAfterManager(...$args){} }
class SourceHandlingService { public static function handle($incoming){return false;} }
class ManagerPushService { public static $calls=[]; public static function notifyConversation(...$args){self::$calls[]=$args;} }
class ManagerAvailabilityService { public static $hours=true; public static function withinWorkingHours($now=null){return self::$hours;} public static function anyWorkingForConversation($conversation){return true;} }
class ManagerRequestService { public static function prepare(...$args){return ['back_callback'=>'back_check','online_text'=>'manager day','working_wait_text'=>'manager waiting','outside_hours_text'=>'manager night'];} }
class IntegrationRegistry { public static $messenger,$destination; public static function messenger(){return self::$messenger;} public static function leadDestination(){return self::$destination;} }
class FixtureLeadDestination { public $plans=[]; public function plan($state,$context){$this->plans[]=[$state,$context];return ['summary'=>'saved trip','provider'=>'fixture'];} }
class FixtureMessenger {
    public $messages=[],$platform='max',$sendOk=true;
    public function send($chat,$text){$this->messages[]=['chat'=>$chat,'text'=>$text,'buttons'=>[]];return true;}
    public function sendWithButtons($chat,$text,$buttons){
        $conversation=ConversationControlService::statusByChat($this->platform,$chat);
        $events=(int)ConversationDb::$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=".(int)$conversation['id']." AND event_type='waiting_manager'")->fetchColumn();
        $this->messages[]=['chat'=>$chat,'text'=>$text,'buttons'=>$buttons,'queue'=>[$conversation['status'],$events,ConversationDb::$pdo->inTransaction()]];
        return $this->sendOk;
    }
}
class MaxSearchApi {
    public static $statusStart=64,$statusCityChoose=65,$statusContryChoose=66,$statusAdults=67,$statusChild=68,$statusAge=69,$statusStars=70,$statusMeal=71,$statusNights=72,$statusDate=73,$statusCheck=74,$statusPhone=75,$statusAi=76;
    public static $statuses=[],$needs=[];
    public static function getCurentStatus($chat){return self::$statuses[$chat]??0;}
    public static function getAiSearchContext($chat){return self::$needs[$chat]??[];}
    public static function getLastClaimForChat($chat){return null;}
    public static function deletePrevMessage($chat){}
    public static function funnelLog(...$args){}
    public static function queueMetrikaGoal(...$args){}
    public static function deleteAllStatus($chat){throw new RuntimeException('Manager request must not reset trip');}
}
class AiShadowObserver { public static $calls=[],$result,$before; public static function observe($chat,$text){self::$calls[]=[$chat,$text];$before=self::$before;self::$before=null;if($before)$before();return self::$result;} }
class AiBudgetHandler { public static function handle(...$args){return false;} public static function handleCheck(...$args){return false;} }
class AiShortAnswerHandler { public static function handle(...$args){return false;} }
class AiMessageHandler { public static $calls=[]; public static function handle(...$args){self::$calls[]=$args;} }
class DepartureCityResolver { public static function resolveAndStore(...$args){return [];} }
class DestinationAreaResolver { public static function resolveAndStore(...$args){return [];} }
class DestinationResolver { public static function resolveAndStore(...$args){return [];} }
class DepartureRouteAdviceHandler { public static function handle(...$args){return false;} }
class PostTourService { public static function isLinkHelpRequest($text){return false;} }
class NeedValueResolver { public static function resolve(...$args){return ['recognized'=>false];} }
class NeedApplicationService { public static $calls=[]; public static function resolveAndApplyExistingWizardStep(...$args){self::$calls[]=$args;return ['recognized'=>false,'applied'=>false];} }
class ButtonFactory { public static function rows(...$rows){return $rows;} public static function row(...$buttons){return $buttons;} public static function callback($text,$data){return ['text'=>$text,'callback_data'=>$data];} }
PHP;
file_put_contents($tmp.'/bootstrap.php', $bootstrap);
$real = [
    'services/IncomingUpdateDispatcher.php', 'services/DialogueApplication.php',
    'services/DialogueController.php', 'services/DialogueView.php',
    'services/V2FeatureGate.php', 'services/RulesEngine.php', 'services/V2ActionExecutor.php',
    'services/ManagerHandoffDispatchService.php', 'services/ConversationControlService.php',
    'services/ConversationRecordClock.php', 'handlers/V2EarlyActionHandler.php',
    'handlers/StateMessageHandler.php', 'actions/ManagerAction.php',
];
$stubs = [
    'services'=>['ConversationDb','ProjectConfig','DiagnosticLogger','ConversationRecorder','ConversationAttributionService',
        'MetrikaConversionGoalService','SourceHandlingService','ManagerPushService','ManagerAvailabilityService',
        'ManagerRequestService','IntegrationRegistry','CallbackController','PostTourService','EditFlowService',
        'DepartureCityResolver','DestinationAreaResolver','DestinationResolver','TripStateService','ActionRouter',
        'ButtonFactory','CallbackGeneration','CalendarViewModel','SearchDateSummary','ChannelOfferService','TripSummaryProjection',
        'WizardStepView','NeedValueResolver','NeedApplicationService','NeedProgressionService','ExistingWizardStepApplicationService',
        'ChildAgeValueContract','DialogueTransitionObserver','DepartureCityValueContract','CountryValueContract','DateParser'],
    'handlers'=>['AiDateHandler','AiMessageHandler','AiBudgetHandler','AiShortAnswerHandler','AiShadowObserver','DepartureRouteAdviceHandler'],
    'actions'=>['AskAction','SearchAction','DestinationAdviceAction','ChannelAction'],
    'actions/callbacks'=>['TourChoiceCallbackAction'],
    'contracts'=>['OutboundMetadataMessengerInterface'],
];
foreach ($stubs as $directory=>$names) foreach ($names as $name) {
    file_put_contents($tmp.'/'.$directory.'/'.$name.'.php', '<?php require_once '.var_export($tmp.'/bootstrap.php', true).';'.PHP_EOL);
}
foreach ($real as $file) copy($root.'/'.$file, $tmp.'/'.$file);

function interruptCheck($actual, $expected, string $name): void
{
    if ($actual !== $expected) throw new RuntimeException($name.' expected='.json_encode($expected, JSON_UNESCAPED_UNICODE).' actual='.json_encode($actual, JSON_UNESCAPED_UNICODE));
    echo 'PASS '.$name.PHP_EOL;
}

try {
    require $tmp.'/services/IncomingUpdateDispatcher.php';
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    ConversationDb::$pdo = $pdo;
    $pdo->exec("CREATE TABLE conversations(id INTEGER PRIMARY KEY,project_key TEXT,source_id INTEGER,channel TEXT,external_chat_id TEXT,status TEXT,manager_id INTEGER); CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,actor_type TEXT,actor_id TEXT,payload_json TEXT);");
    IntegrationRegistry::$messenger = new FixtureMessenger();
    IntegrationRegistry::$destination = new FixtureLeadDestination();
    $dispatcher = new IncomingUpdateDispatcher();
    $features = ['ai_v2'=>['manager_request'=>true,'destination_advice'=>true,'ask'=>true,'open_search'=>true]];
    V2FeatureGate::resetForTests($features);
    $trip = ['departure'=>['city'=>'Казань','city_id'=>7], 'destination'=>['country'=>'Египет','country_id'=>5], 'tourists'=>['adults'=>2,'children'=>0,'children_ages'=>[]]];
    $manager = ['decision'=>['action'=>RulesEngine::MANAGER], 'extracted'=>['intent'=>'manager_request'], 'new_state'=>$trip];
    $id = 0;
    $seed = static function (int $status, string $platform='max') use (&$id, $pdo): array {
        $id++; $chat = ($platform === 'max' ? -1 : 1) * (900000 + $id);
        $pdo->prepare("INSERT INTO conversations VALUES(?,'fixture',1,?,?,'ai',NULL)")->execute([$id,$platform,(string)$chat]);
        MaxSearchApi::$statuses[$chat] = $status;
        MaxSearchApi::$needs[$chat] = ['city'=>'Казань','country'=>'Египет','adults'=>2,'children'=>0,'nights'=>'7','date'=>'15.12.2030'];
        IntegrationRegistry::$messenger->platform = $platform;
        IntegrationRegistry::$messenger->sendOk = true;
        return ['platform'=>$platform,'type'=>'message','user'=>['chat_id'=>$chat,'first_name'=>'Synthetic'],'message_id'=>'fixture-'.$id,'text'=>'Позовите менеджера, я ему расскажу'];
    };
    $cases = [
        ['check MAX',MaxSearchApi::$statusCheck,'max',true,true],
        ['stars MAX',MaxSearchApi::$statusStars,'max',true,true],
        ['check Telegram at night',MaxSearchApi::$statusCheck,'telegram',false,true],
        ['stars Telegram failed confirmation',MaxSearchApi::$statusStars,'telegram',false,false],
        ['check website failed confirmation',MaxSearchApi::$statusCheck,'website',false,false],
        ['existing AI route',MaxSearchApi::$statusAi,'max',true,true],
    ];
    foreach (['city'=>MaxSearchApi::$statusCityChoose,'country'=>MaxSearchApi::$statusContryChoose,'adults'=>MaxSearchApi::$statusAdults,
        'children'=>MaxSearchApi::$statusChild,'ages'=>MaxSearchApi::$statusAge,'meal'=>MaxSearchApi::$statusMeal,
        'nights'=>MaxSearchApi::$statusNights,'date'=>MaxSearchApi::$statusDate] as $label=>$status) $cases[]=[$label,$status,'max',true,true];
    foreach ($cases as [$label,$status,$platform,$hours,$sendOk]) {
        $incoming = $seed($status, $platform); $chat = $incoming['user']['chat_id'];
        $needs = MaxSearchApi::$needs[$chat]; $messages = count(IntegrationRegistry::$messenger->messages); $calls = count(AiShadowObserver::$calls);
        AiShadowObserver::$result = $manager; ManagerAvailabilityService::$hours = $hours; IntegrationRegistry::$messenger->sendOk = $sendOk;
        interruptCheck($dispatcher->dispatch($incoming), true, $label.' request handled');
        interruptCheck(ConversationControlService::statusByChat($platform,$chat)['status'], 'waiting_manager', $label.' reaches canonical queue');
        interruptCheck(count(AiShadowObserver::$calls)-$calls, 1, $label.' uses existing classifier once');
        interruptCheck(count(IntegrationRegistry::$messenger->messages)-$messages, 1, $label.' no edit/field prompt after handoff');
        interruptCheck(end(IntegrationRegistry::$messenger->messages)['queue'], ['waiting_manager',1,false], $label.' durable queue precedes confirmation attempt');
        interruptCheck(MaxSearchApi::$needs[$chat], $needs, $label.' keeps saved trip');
        interruptCheck(end(IntegrationRegistry::$destination->plans), [$trip,['chat_id'=>$chat,'platform'=>$platform]], $label.' executor receives trip and native platform');
        if ($status === MaxSearchApi::$statusCheck && $platform === 'max') {
            $messages=count(IntegrationRegistry::$messenger->messages); $calls=count(AiShadowObserver::$calls); $pushes=count(ManagerPushService::$calls);
            $incoming['message_id'].='-repeat'; $dispatcher->dispatch($incoming);
            interruptCheck(count(IntegrationRegistry::$messenger->messages), $messages, 'waiting repeat remains quiet');
            interruptCheck(count(AiShadowObserver::$calls), $calls, 'waiting repeat never reclassifies');
            interruptCheck(count(ManagerPushService::$calls)-$pushes, 1, 'waiting repeat only notifies existing queue');
            interruptCheck((int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=$id AND event_type='waiting_manager'")->fetchColumn(), 1, 'repeat does not duplicate waiting event');
            $pdo->prepare("UPDATE conversations SET status='manager',manager_id=7 WHERE id=?")->execute([$id]);
            $dispatcher->dispatch($incoming);
            $owned=ConversationControlService::statusByChat($platform,$chat);
            interruptCheck([$owned['status'],(int)$owned['manager_id']], ['manager',7], 'assigned repeat keeps owner');
            interruptCheck(count(IntegrationRegistry::$messenger->messages), $messages, 'assigned repeat remains quiet');
            interruptCheck(count(AiShadowObserver::$calls), $calls, 'assigned repeat stays outside AI');
        }
    }
    foreach (['менеджер, не нужен','менеджер не нужен','Не зовите менеджера'] as $text) {
        $incoming=$seed(MaxSearchApi::$statusCheck); $incoming['text']=$text; $chat=$incoming['user']['chat_id'];
        AiShadowObserver::$result=['decision'=>['action'=>RulesEngine::STOP],'extracted'=>['intent'=>'stop']];
        $dispatcher->dispatch($incoming);
        interruptCheck(ConversationControlService::statusByChat('max',$chat)['status'], 'ai', 'refusal does not request a manager: '.$text);
        interruptCheck(end(IntegrationRegistry::$messenger->messages)['buttons'][0][0]['callback_data'], 'edit_params', 'refusal retains existing check fallback');
    }
    foreach ([['decision'=>['action'=>RulesEngine::ASK]],['decision'=>['action'=>RulesEngine::ANSWER]],null] as $result) {
        $incoming=$seed(MaxSearchApi::$statusStars); AiShadowObserver::$result=$result; $chat=$incoming['user']['chat_id'];
        $dispatcher->dispatch($incoming);
        interruptCheck(ConversationControlService::statusByChat('max',$chat)['status'], 'ai', 'only a recognized manager decision interrupts wizard');
        interruptCheck(end(NeedApplicationService::$calls)[1], 'stars', 'non-manager/failed interpretation retains field owner');
    }
    // Admission can change during interpretation. Consuming this turn is not a
    // claim of queue success; the existing dispatch must not confirm a rejection.
    $incoming=$seed(MaxSearchApi::$statusStars); $chat=$incoming['user']['chat_id']; $needs=MaxSearchApi::$needs[$chat];
    AiShadowObserver::$result=$manager; $messages=count(IntegrationRegistry::$messenger->messages);
    AiShadowObserver::$before=static function() use ($pdo,$id): void { $pdo->prepare("UPDATE conversations SET status='closed' WHERE id=?")->execute([$id]); };
    $dispatcher->dispatch($incoming);
    interruptCheck(ConversationControlService::statusByChat('max',$chat), null, 'closed during classification is not queued');
    interruptCheck((int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=$id AND event_type='waiting_manager'")->fetchColumn(), 0, 'rejected admission creates no waiting event');
    interruptCheck(count(IntegrationRegistry::$messenger->messages), $messages, 'rejected admission creates no false confirmation or field prompt');
    interruptCheck(MaxSearchApi::$needs[$chat], $needs, 'rejected admission preserves saved trip');
    interruptCheck(array_slice(end(DiagnosticLogger::$events),0,2), ['incoming_dispatch','handled'], 'dispatcher keeps its existing routing diagnostic');
    $incoming=$seed(MaxSearchApi::$statusStars); $incoming['text']='4'; AiShadowObserver::$result=$manager; $calls=count(AiShadowObserver::$calls);
    $dispatcher->dispatch($incoming);
    interruptCheck(count(AiShadowObserver::$calls), $calls, 'ordinary wizard answer makes no new AI call');
    interruptCheck(end(NeedApplicationService::$calls)[2], '4', 'ordinary answer reaches existing field application unchanged');
    V2FeatureGate::resetForTests(['ai_v2'=>['manager_request'=>false]]);
    $incoming=$seed(MaxSearchApi::$statusStars); $calls=count(AiShadowObserver::$calls); $dispatcher->dispatch($incoming);
    interruptCheck(count(AiShadowObserver::$calls), $calls, 'disabled manager feature makes no new AI call');
    interruptCheck(ConversationControlService::statusByChat('max',$incoming['user']['chat_id'])['status'], 'ai', 'feature rollback preserves previous route');
    echo "DIALOGUE MANAGER INTERRUPTION: OK\n";
} finally {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($tmp);
}
