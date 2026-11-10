<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/DepartureSuggestionService.php';
require_once __DIR__ . '/../services/DialogueView.php';
require_once __DIR__ . '/../services/DepartureCityResolver.php';
require_once __DIR__ . '/../services/LocalAiFallbackService.php';
require_once __DIR__ . '/../services/AiBusinessDefaultsService.php';
require_once __DIR__ . '/../actions/callbacks/DepartureSuggestionCallbackAction.php';
require_once __DIR__ . '/../handlers/AiMessageHandler.php';
define('OPENAI_API_KEY',''); // Exercise the real error path before any provider transport is constructed.
function put_log_out($message): void {}

final class MaxSearchApi
{
    public static $statusStart=64,$statusCityChoose=65,$statusContryChoose=66,$statusAdults=67,
        $statusChild=68,$statusAge=69,$statusStars=70,$statusMeal=71,$statusNights=72,
        $statusDate=73,$statusCheck=74,$statusPhone=75,$statusAi=76;
    public static array $current=[],$applies=[];
    public static int $status=76;
    public static bool $failApply=false;
    public static function getAiSearchContext($chatId): array { return self::$current; }
    public static function getAiMissingFields($chatId): array { return empty(self::$current['city'])?['city','country']:['country']; }
    public static function getCurentStatus($chatId): int { return self::$status; }
    public static function setStatus($chatId,$status,$messageId=false): void { self::$status=(int)$status; }
    public static function deletePrevMessage($chatId,$full=false): void {}
    public static function funnelLog($chatId,$event,$data=[]): void {}
    public static function applyAiParameters($chatId,array $params): array
    {
        self::$applies[]=$params;
        if(self::$failApply)return [];
        self::$current=array_merge(self::$current,$params);
        return array_fill_keys(array_keys($params),true);
    }
}

$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE conversations (id INTEGER PRIMARY KEY,project_key TEXT,channel TEXT,external_chat_id TEXT,status TEXT,metadata_json TEXT)');
$pdo->exec('CREATE TABLE catalog_departures (id INTEGER PRIMARY KEY,name TEXT,name_genitive TEXT,is_active INTEGER)');
$pdo->exec("INSERT INTO catalog_departures VALUES (1,'Москва','Москвы',1),(5,'С.Петербург','С.Петербурга',1),(41,'Калининград','Калининграда',1),(99,'Без перелёта','Без перелёта',1),(88,'Неактивный','Неактивного',0)");
foreach(['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS']as$key)define($key,'isolated-fixture');
foreach([ConversationDb::class,HotelDatabase::class]as$class){$property=new ReflectionProperty($class,'pdo');$property->setAccessible(true);$property->setValue(null,$pdo);}
ProjectConfig::resetForTests(['id'=>'fixture-a','messenger'=>['provider'=>'max']]);
$receipt=['version'=>1,'yandex_region_id'=>'22','name'=>'Калининград','kind'=>'advertising','source'=>'max_start_payload','platform'=>'max','recorded_at_unix'=>1794300000,'receipt_sha256'=>str_repeat('a',64)];
$insert=$pdo->prepare('INSERT INTO conversations VALUES (?,?,?,?,?,?)');
$insert->execute([1,'fixture-a','max','501','ai',json_encode(['entry_region'=>$receipt])]);
$tgReceipt=$receipt;$tgReceipt['yandex_region_id']='2';$tgReceipt['platform']='telegram';$tgReceipt['source']='telegram_start_payload';
$insert->execute([2,'fixture-a','telegram','501','ai',json_encode(['entry_region'=>$tgReceipt])]);
$messages=[];
IntegrationRegistry::useMessenger(new MaxMessengerAdapter(
    static function($chatId,$text)use(&$messages):bool{$messages[]=['text'=>$text,'buttons'=>[]];return true;},
    static function($chatId,$text,$buttons)use(&$messages):bool{$messages[]=['text'=>$text,'buttons'=>$buttons];return true;},
    'ai',null,false));
$failed=0;$passed=0;
function suggestionCheck(string$name,$actual,$expected):void{global$failed,$passed;if($actual===$expected){$passed++;echo"PASS  $name\n";}else{$failed++;echo"FAIL  $name expected=".json_encode($expected,JSON_UNESCAPED_UNICODE)." actual=".json_encode($actual,JSON_UNESCAPED_UNICODE)."\n";}}

$suggestion=DepartureSuggestionService::forChat(501);
suggestionCheck('Yandex region 22 resolves catalogue ID 41, never ID 22',array_column($suggestion['candidates']??[],'id'),['41']);
suggestionCheck('reading a suggestion does not choose a city',MaxSearchApi::$current,[]);
suggestionCheck('Telegram entry uses its own region for the same chat key',array_column(DepartureSuggestionService::forChat(501,[],'telegram')['candidates']??[],'id'),['5']);
suggestionCheck('unknown explicit transport cannot fall back to MAX',DepartureSuggestionService::forChat(501,[],'unknown'),[]);
$rows=TravelDirectoryRepository::activeDepartures();
suggestionCheck('inactive and no-flight targets cannot become airport suggestions',DepartureSuggestionResolver::resolve(['id'=>'22','name'=>'Калининград','departure_names'=>['Неактивный','Без перелёта']],$rows),[]);
suggestionCheck('ambiguous catalogue name fails closed',DepartureSuggestionResolver::resolve(['id'=>'22','departure_names'=>['Калининград']],array_merge($rows,[['id'=>42,'name'=>'Калининград']])),[]);
suggestionCheck('project overrides replace the shared candidate order',array_column(DepartureSuggestionResolver::resolve(['id'=>'22','departure_names'=>['Калининград']],$rows,['22'=>['Санкт-Петербург','Москва']])['candidates'],'id'),['5','1']);

MaxSearchApi::$current=['city'=>'Без перелёта'];
suggestionCheck('saved no-flight selection is primary',DepartureSuggestionService::forChat(501),[]);
MaxSearchApi::$current=['city'=>'Москва'];
suggestionCheck('saved departure is primary',DepartureSuggestionService::forChat(501),[]);
MaxSearchApi::$current=[];
ProjectConfig::resetForTests(['id'=>'fixture-b','messenger'=>['provider'=>'max']]);
suggestionCheck('a different project cannot reuse the entry receipt',DepartureSuggestionService::forChat(501),[]);
ProjectConfig::resetForTests(['id'=>'fixture-a','messenger'=>['provider'=>'max']]);

$local=LocalAiFallbackService::parameters('Турция',[]);
suggestionCheck('mapped region suppresses local Moscow default',array_key_exists('city',DepartureSuggestionService::withoutDefaultCity($local,[],$suggestion)),false);
$ai=AiBusinessDefaultsService::apply(['parameters'=>['country'=>'Турция']],'Турция',[]);
suggestionCheck('mapped region suppresses business Moscow default',array_key_exists('city',DepartureSuggestionService::withoutDefaultCity($ai['parameters'],[],$suggestion,['country'=>'Турция'])),false);
suggestionCheck('an explicit city extracted by AI stays primary',DepartureSuggestionService::withoutDefaultCity(['city'=>'Казань'],[],$suggestion,['city'=>'Казань']),['city'=>'Казань']);
suggestionCheck('unknown region keeps the previous Moscow policy',DepartureSuggestionService::withoutDefaultCity($local,[],[])['city']??null,'Москва');

$unknown=$receipt;$unknown['yandex_region_id']='999999';
$pdo->prepare('UPDATE conversations SET metadata_json=? WHERE id=1')->execute([json_encode(['entry_region'=>$unknown])]);
suggestionCheck('unknown region has no guessed suggestion',DepartureSuggestionService::forChat(501),[]);
ProjectConfig::resetForTests(['id'=>'fixture-a','regional_departures'=>['overrides'=>['999999'=>['Москва']]]]);
suggestionCheck('an explicit project override can map a region outside the bundled snapshot',array_column(DepartureSuggestionService::forChat(501)['candidates']??[],'id'),['1']);
ProjectConfig::resetForTests(['id'=>'fixture-a','messenger'=>['provider'=>'max']]);
$pdo->prepare('UPDATE conversations SET metadata_json=? WHERE id=1')->execute([json_encode(['entry_region'=>$receipt])]);

$messages=[];
MissingFieldQuestionService::send(501,'city');
suggestionCheck('AI presents a confirmation button',$messages[0]['buttons'][0][0]['callback_data']??null,'departure_suggest_41');
suggestionCheck('presenting confirmation still does not apply a need',MaxSearchApi::$applies,[]);
$messages=[];
DepartureSuggestionCallbackAction::handle(501,'departure_suggest_other','max');
suggestionCheck('another-city action asks an ordinary city question',$messages[0]['text']??null,MissingFieldQuestionService::question('city'));
suggestionCheck('another-city action does not apply a need',MaxSearchApi::$applies,[]);
$messages=[];
DepartureSuggestionCallbackAction::handle(501,'departure_suggest_99','max');
suggestionCheck('a forged non-candidate callback cannot choose a city',MaxSearchApi::$applies,[]);
MaxSearchApi::$status=MaxSearchApi::$statusStart;
DepartureSuggestionCallbackAction::handle(501,'departure_suggest_41','max');
suggestionCheck('stale callback cannot write a city',MaxSearchApi::$applies,[]);
MaxSearchApi::$status=MaxSearchApi::$statusAi;
MaxSearchApi::$failApply=true;
suggestionCheck('failed canonical application remains retryable',DepartureSuggestionCallbackAction::handle(501,'departure_suggest_41','max'),false);
suggestionCheck('failed application does not advance to another field',$messages,[]);
MaxSearchApi::$failApply=false;MaxSearchApi::$applies=[];
DepartureSuggestionCallbackAction::handle(501,'departure_suggest_41','max');
suggestionCheck('confirmed candidate uses canonical need application',MaxSearchApi::$applies,[['city'=>'Калининград']]);
suggestionCheck('confirmed candidate becomes the saved city',MaxSearchApi::$current['city']??null,'Калининград');
suggestionCheck('confirmation advances exactly once',count($messages),1);
DepartureSuggestionCallbackAction::handle(501,'departure_suggest_41','max');
suggestionCheck('repeat callback cannot replace a saved city',count(MaxSearchApi::$applies),1);

MaxSearchApi::$current=[];MaxSearchApi::$applies=[];$messages=[];
DepartureCityResolver::resolveAndStore(501,'Туры из Калининграда в Турцию');
suggestionCheck('explicit city in the first rich message is saved before suggestion',MaxSearchApi::$current['city']??null,'Калининград');
suggestionCheck('explicit first message does not receive a regional question',DepartureSuggestionService::forChat(501),[]);
MaxSearchApi::$current=[];MaxSearchApi::$applies=[];$messages=[];
AiMessageHandler::handle(['text'=>'Хотим в Турцию, двое взрослых на 7 ночей','_platform'=>'max'],501);
suggestionCheck('AI error with a known region does not install a Moscow default',array_key_exists('city',MaxSearchApi::$current),false);
suggestionCheck('AI error reaches the regional confirmation question',$messages[count($messages)-1]['buttons'][0][0]['callback_data']??null,'departure_suggest_41');
MaxSearchApi::$current=[];MaxSearchApi::$applies=[];$messages=[];
DialogueView::city(501);
suggestionCheck('wizard reuses its guarded city callback',$messages[0]['buttons'][0][0]['callback_data']??null,'pick_city_41');
suggestionCheck('wizard still offers manual input and no-flight',array_column(array_merge(...$messages[0]['buttons']),'callback_data'),['pick_city_41','pick_city_other','pick_city_99']);
suggestionCheck('wizard suggestion does not select its default',MaxSearchApi::$applies,[]);
echo "Departure suggestions: $passed passed, $failed failed\n";
exit($failed?1:0);
