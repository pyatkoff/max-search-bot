<?php

declare(strict_types=1);
require_once __DIR__.'/../services/NeedValueResolver.php';
require_once __DIR__.'/../services/TripBudgetPolicy.php';
$n=0;$failed=0;
function abCheck(string $label,$actual,$expected):void {
    global $n,$failed;$n++;
    if ($actual===$expected) {echo "PASS $label\n";return;}
    $failed++;echo "FAIL $label\nexpected ".json_encode($expected,JSON_UNESCAPED_UNICODE)."\nactual ".json_encode($actual,JSON_UNESCAPED_UNICODE)."\n";
}
$cases=[
    ['До 250 тысяч',250000,null,null,true],
    ['Бюджет 250 000 рублей на всех',250000,'RUB','total',true],
    ['до 90 тыс на человека',90000,null,'per_person',true],
    ['Москва, Турция, 2 взрослых, до 250 тысяч',250000,null,null,false],
    ['Бюджет: 250 тыс',250000,null,null,true],
    ['Общий бюджет 250 тыс',250000,null,'total',true],
    ['бюджет 250 т.р.',250000,'RUB',null,true],
    ['бюджет 250 тр',250000,'RUB',null,true],
    ['Бюджет 1,5 млн',1500000,null,null,true],
    ['До 250000.50 RUB',250000.5,'RUB',null,true],
    ["до 250\u{00A0}000 ₽",250000,'RUB',null,true],
    ['Бюджет 3000 евро',3000,'EUR',null,true],
    ['Бюджет 3000 USD',3000,'USD',null,true],
    ['Бюджет 250 000',250000,null,null,true],
    ['нет, бюджет 230 тысяч',230000,null,null,true],
    ['снимите ограничение по бюджету',null,null,null,true],
    ['нет, на человека',null,null,'per_person',true],
];
foreach($cases as [$text,$amount,$currency,$basis,$only]) {
    $r=NeedValueResolver::resolve('budget',$text);
    abCheck('recognized: '.$text,$r['recognized'],true);
    abCheck('value: '.$text,$r['value']['budget.max']??null,$amount);
    abCheck('currency: '.$text,$r['value']['budget.currency']??null,$currency);
    abCheck('basis: '.$text,$r['value']['budget.basis']??null,$basis);
    abCheck('only-budget: '.$text,$r['only_budget'],$only);
}
foreach(['7','12.10.2026','7 ночей','250','Ребёнку 7 лет','Стоимость 250 тысяч','Экскурсия до 10 тысяч','Доплата до 5000 рублей','Бюджет от 200 до 250 тыс','Бюджет 250-300 тыс','Не бюджет 250 тыс','Например, бюджет 250 тыс','Если бюджет 250 тысяч?','Бюджет 2500 за ночь','https://example.invalid/?бюджет=250000','«Бюджет 250 тысяч»','Бюджет 0','Бюджет -1000','Бюджет 100 тыс; бюджет 200 тыс','Бюджет 1e6',"\xFF"] as $text) {
    abCheck('not a definite budget: '.bin2hex(substr($text,0,12)),NeedValueResolver::resolve('budget',$text)['recognized'],false);
}
foreach(['legacy-start', '{"kind":"trip_budget_v0","budget":{}}','{"kind":"trip_budget_v1","budget":{"max":-1}}','{"kind":"trip_budget_v1","budget":{"max":250000,"private":"ignored?"}}'] as $raw) {
    abCheck('unknown start value rejected',TripBudgetPolicy::fromStartValue($raw),null);
}
// Additional review cases: observations about money are not confirmed ceilings.
$reviewCases = [
    ['Бюджет 250 тысяч?', null],
    ['Какой бюджет 250 тысяч подойдёт?', null],
    ['Бюджет 250 тысяч или 300 тысяч', null],
    ['Бюджет 250 тысяч, до 300 тысяч готовы', null],
    ['Бюджет 250000.500 RUB', null],
    ['Бюджет 250000,500 RUB', null],
    ['Бюджет 2.000.000 RUB', null],
    ['Бюджет 250 тысяч на взрослого', null],
    ['Бюджет 250 тысяч для ребёнка', null],
    ['Бюджет 90 тыс на каждого взрослого', null],
    ['Бюджет 250 тыс на человека за ночь', null],
    ['Бюджет 3000 CNY', null],
    ['Бюджет 3000 юаней', null],
    ['Бюджет 250 т.р. EUR', null],
    ['Бюджет 90 тыс на каждого', ['budget.max'=>90000,'budget.basis'=>'per_person']],
    ['Не больше 250 тысяч', ['budget.max'=>250000]],
    ['Бюджет не более 250 тысяч', ['budget.max'=>250000]],
    ['Хочу в Турцию на неделю до 250 тысяч', ['budget.max'=>250000]],
    ['Бюджет 250 тысяч. Можно прямой рейс?', ['budget.max'=>250000]],
    ['Бюджет не ограничен', ['budget.max'=>null]],
];
foreach ($reviewCases as [$text, $expected]) {
    $r = NeedValueResolver::resolve('budget', $text);
    abCheck('review budget interpretation: '.$text, $r['value'], $expected);
}

if (in_array('--parser-only',$argv,true)) {echo "TOTAL $n | PASS ".($n-$failed)." | FAIL $failed\n";exit($failed?1:0);}
$useMysql=in_array('--mysql',$argv,true);
$driver=$useMysql?'mysql':'sqlite';
if (!in_array($driver,PDO::getAvailableDrivers(),true)) {fwrite(STDERR,"Required active-budget database tests need pdo_{$driver}; not skipped.\n");exit(1);}

// Load the REAL active API from a temporary source-only fixture; no production
// config is loaded, no network sender is used and no repository file is changed.
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/active-budget-'.bin2hex(random_bytes(6));
mkdir($tmp,0700);
file_put_contents($tmp.'/config.php',"<?php\ndefine('MAX_SEARCH_RUNTIME_STORAGE','mysql');\n");
copy($root.'/maxsearchclass.php',$tmp.'/maxsearchclass.php');
symlink($root.'/maxsearchbaseclass.php',$tmp.'/maxsearchbaseclass.php');
symlink($root.'/services',$tmp.'/services');
register_shutdown_function(static function()use($tmp):void{
    foreach(['services','maxsearchbaseclass.php','maxsearchclass.php','config.php'] as $f)@unlink($tmp.'/'.$f);
    @rmdir($tmp);
});
require_once $tmp.'/maxsearchclass.php';
require_once $root.'/services/ManagerHandoffContextService.php';
require_once $root.'/services/DialogueController.php';
require_once $root.'/services/IncomingMessage.php';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
if ($useMysql) {
    // Explicit isolated local fixture only: no host/production credentials or fallback.
    $socket=(string)getenv('MAX_BUDGET_TEST_MYSQL_SOCKET');
    if ($socket==='' || strpos($socket,';')!==false || !is_file(dirname($socket).'/QUALIFICATION_TEST_ONLY')) throw new RuntimeException('isolated_mysql_fixture_required');
    $testDb='qualification_budget_'.bin2hex(random_bytes(6));
    $admin=new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4','root','',$options);
    $admin->exec('CREATE DATABASE `'.$testDb.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static function()use($admin,$testDb):void{$admin->exec('DROP DATABASE `'.$testDb.'`');});
    $pdo=new PDO('mysql:unix_socket='.$socket.';dbname='.$testDb.';charset=utf8mb4','root','',$options);
    $migration=(string)file_get_contents($root.'/migrations/022_standalone_dialogue_state.sql');
    foreach(explode(';',$migration) as $statement) if(trim($statement)!=='') $pdo->exec($statement);
    echo 'Native MySQL fixture version: '.$pdo->getAttribute(PDO::ATTR_SERVER_VERSION)."\n";
} else {
    $pdo=new PDO('sqlite::memory:',null,null,$options);
    $pdo->exec('CREATE TABLE runtime_dialogue_state(id INTEGER PRIMARY KEY AUTOINCREMENT,project_key TEXT NOT NULL,chat_id TEXT NOT NULL,status_id INTEGER NOT NULL,value_text TEXT NULL,message_id TEXT DEFAULT "")');
}
$connection=new ReflectionProperty(ConversationDb::class,'pdo');$connection->setAccessible(true);$connection->setValue(null,$pdo);
ProjectConfig::resetForTests(['id'=>'budget-test','search'=>['base_domain'=>'https://example.invalid','search_path'=>'poisk-turov']]);
final class BudgetTestMessenger implements MessengerInterface {
    public array $sent=[];
    public function send($chatId,string $text):bool{$this->sent[]=[$chatId,$text];return true;}
    public function sendWithButtons($chatId,string $text,array $buttons):bool{$this->sent[]=[$chatId,$text];return true;}
    public function sendContactRequest($chatId,string $text,string $manualCallback,string $backCallback):bool{throw new RuntimeException('unexpected_contact');}
}
$messenger=new BudgetTestMessenger();IntegrationRegistry::useMessenger($messenger);
function abApply(int $chat,string $text):array {
    $r=NeedValueResolver::resolve('budget',$text);
    return NeedApplicationService::applyParameters($chat,['budget_update'=>['snapshot'=>ConversationStateRepository::budgetSnapshot($chat),'changes'=>$r['value']]]);
}
$chat=-42001;MaxSearchApi::setStatus($chat,64);MaxSearchApi::setStatus($chat,76);
$beforeRows=(int)$pdo->query('SELECT COUNT(*) FROM runtime_dialogue_state')->fetchColumn();
abCheck('real application reports budget persisted',abApply($chat,'До 250 тысяч'),['budget'=>true]);
abCheck('budget creates no status or extra row',(int)$pdo->query('SELECT COUNT(*) FROM runtime_dialogue_state')->fetchColumn(),$beforeRows);
abCheck('current AI step unchanged by metadata write',MaxSearchApi::getCurentStatus($chat),76);
$context=MaxSearchApi::getAiSearchContext($chat);
abCheck('real active context reads the sum',$context['budget']['max']??null,250000);
abCheck('total is the default',$context['budget']['basis']??null,'total');
$brief=ManagerHandoffContextService::build($context,[]);
abCheck('unchanged manager handoff caller receives budget',str_contains($brief,'Бюджет: до 250 000 RUB на всех'),true);
$beforeUrl=ProjectConfig::searchUrlFromSavedData(MaxSearchApi::getSavedData($chat),['city'=>65,'country'=>66,'adults'=>67,'children'=>68,'child_ages'=>69,'stars'=>70,'meal'=>71,'nights'=>72,'date'=>73],'test-attribution');
abApply($chat,'Бюджет 230 тысяч');
abCheck('sum correction survives round trip',MaxSearchApi::getAiSearchContext($chat)['budget']['max'],230000);
$afterUrl=ProjectConfig::searchUrlFromSavedData(MaxSearchApi::getSavedData($chat),['city'=>65,'country'=>66,'adults'=>67,'children'=>68,'child_ages'=>69,'stars'=>70,'meal'=>71,'nights'=>72,'date'=>73],'test-attribution');
abCheck('website query and attribution not silently changed',$afterUrl,$beforeUrl);
NeedApplicationService::applyParameters($chat,['nights'=>'7','date'=>'15.10.2027','adults'=>2,'children'=>0]);
abCheck('later date/night/party answer preserves budget',MaxSearchApi::getAiSearchContext($chat)['budget']['max'],230000);
abCheck('zero children preserved',MaxSearchApi::getAiSearchContext($chat)['children'],0);
abApply($chat,'до 90 тыс на человека');abApply($chat,'бюджет 95 тыс');
abCheck('personal basis retained after sum correction',MaxSearchApi::getAiSearchContext($chat)['budget']['basis'],'per_person');
abCheck('personal amount not multiplied',MaxSearchApi::getAiSearchContext($chat)['budget']['max'],95000);
abApply($chat,'на всех');
abCheck('explicit total correction',MaxSearchApi::getAiSearchContext($chat)['budget']['basis'],'total');
$old=ConversationStateRepository::budgetSnapshot($chat);
abApply($chat,'бюджет 210 тыс');
abCheck('concurrent old-value write rejected',ConversationStateRepository::applyBudget($chat,['snapshot'=>$old,'changes'=>['budget.max'=>220000]]),false);
abCheck('newer budget retained',MaxSearchApi::getAiSearchContext($chat)['budget']['max'],210000);
$raw=ConversationStateRepository::budgetSnapshot($chat);
abCheck('same amount idempotent',abApply($chat,'бюджет 210 тыс'),['budget'=>true]);
abCheck('unknown keys cannot enter start metadata',ConversationStateRepository::applyBudget($chat,['snapshot'=>$raw,'changes'=>['budget.max'=>1,'secret'=>'bad']]),false);
abApply($chat,'снимите ограничение по бюджету');
abCheck('clear removes displayed budget',isset(MaxSearchApi::getAiSearchContext($chat)['budget']),false);
abApply($chat,'До 250 тысяч');
abCheck('new budget after clear defaults to total',MaxSearchApi::getAiSearchContext($chat)['budget']['basis'],'total');
$old=ConversationStateRepository::budgetSnapshot($chat);
MaxSearchApi::setStatus($chat,64);MaxSearchApi::setStatus($chat,76);
abCheck('new start does not inherit budget',isset(MaxSearchApi::getAiSearchContext($chat)['budget']),false);
abCheck('stale start application rejected',ConversationStateRepository::applyBudget($chat,['snapshot'=>$old,'changes'=>['budget.max'=>1]]),false);
abCheck('stale raw CAS rejected',MysqlDialogueStateRepository::compareStartValue($chat,64,$old['start_id'],$old['raw'],TripBudgetPolicy::toStartValue(['max'=>1,'currency'=>'RUB'])),false);
$controller=new DialogueController();
$incoming=IncomingMessage::text('max',42001,$chat,'synthetic-budget','до 250 тысяч');
$sentBefore=count($messenger->sent);
abCheck('actual controller handles budget-only input',$controller->handleIncomingMessage($incoming),true);
abCheck('actual controller persists amount',MaxSearchApi::getAiSearchContext($chat)['budget']['max']??null,250000);
abCheck('one next-field response, no repeated budget question',count($messenger->sent)-$sentBefore,1);
abCheck('known basis is not questioned',str_contains(end($messenger->sent)[1],'на человека'),false);
abCheck('only existing start/AI statuses in fresh session',MaxSearchApi::getCurentStatus($chat),76);

// Optional clarification is additive: a known budget suppresses it, an unknown
// budget offers one skippable message and never creates a new dialogue status.
$sentBefore=count($messenger->sent);
abCheck('known budget suppresses optional clarification',OptionalBudgetPromptService::sendIfMissing($chat),false);
abCheck('known budget emits no extra message',count($messenger->sent),$sentBefore);
abApply($chat,'Бюджет без ограничений');
$statusBefore=MaxSearchApi::getCurentStatus($chat);
$sentBefore=count($messenger->sent);
abCheck('unknown budget gets one optional clarification',OptionalBudgetPromptService::sendIfMissing($chat),true);
abCheck('optional clarification sends exactly one message',count($messenger->sent),$sentBefore+1);
abCheck('optional clarification says it is not required',str_contains(end($messenger->sent)[1],'Бюджет — необязательно'),true);
abCheck('optional clarification shows personal wording without asking basis',str_contains(end($messenger->sent)[1],'до 90 тыс. на человека'),true);
abCheck('optional clarification does not change dialogue status',MaxSearchApi::getCurentStatus($chat),$statusBefore);
$progressionSource=(string)file_get_contents($root.'/services/NeedProgressionService.php');
abCheck('AI completion sends optional clarification only after successful check',
    str_contains($progressionSource,'$checkSent = DialogueView::check($chatId);')
    && str_contains($progressionSource,'if ($checkSent) OptionalBudgetPromptService::sendIfMissing($chatId);'),true);

// The completed check accepts only a definite budget-only clarification. It uses
// the same canonical application owner and stays in check; mixed text cannot be
// partially applied and therefore keeps the old check guidance truthful.
MaxSearchApi::setStatus($chat,MaxSearchApi::$statusCheck);
$sentBefore=count($messenger->sent);
$checkBudget=IncomingMessage::text('max',42002,$chat,'check-budget-total','до 280 тысяч');
abCheck('check consumes definite budget-only input',$controller->handleIncomingMessage($checkBudget),true);
abCheck('check saves whole-party budget by default',MaxSearchApi::getAiSearchContext($chat)['budget']['max']??null,280000);
abCheck('check keeps default total basis',MaxSearchApi::getAiSearchContext($chat)['budget']['basis']??null,'total');
abCheck('check budget clarification keeps check status',MaxSearchApi::getCurentStatus($chat),MaxSearchApi::$statusCheck);
abCheck('check budget confirmation sends one message',count($messenger->sent),$sentBefore+1);
abCheck('check budget confirmation says whole party',str_contains(end($messenger->sent)[1],'280 000 RUB на всех'),true);
$checkPersonal=IncomingMessage::text('max',42003,$chat,'check-budget-personal','до 90 тыс на человека');
abCheck('check accepts explicit personal budget',$controller->handleIncomingMessage($checkPersonal),true);
abCheck('check stores personal amount without multiplication',MaxSearchApi::getAiSearchContext($chat)['budget']['max']??null,90000);
abCheck('check stores explicit personal basis',MaxSearchApi::getAiSearchContext($chat)['budget']['basis']??null,'per_person');
abCheck('personal confirmation is explicit',str_contains(end($messenger->sent)[1],'90 000 RUB на человека'),true);
$beforeMixed=MaxSearchApi::getAiSearchContext($chat)['budget'];
$sentBefore=count($messenger->sent);
$mixed=IncomingMessage::text('max',42004,$chat,'check-budget-mixed','Бюджет 300 тыс, первая линия');
abCheck('mixed check text remains handled by existing guidance',$controller->handleIncomingMessage($mixed),true);
abCheck('mixed check text does not partially mutate budget',MaxSearchApi::getAiSearchContext($chat)['budget'],$beforeMixed);
abCheck('mixed check text sends one guidance response',count($messenger->sent),$sentBefore+1);
abCheck('mixed check text keeps truthful unchanged copy',str_contains(end($messenger->sent)[1],'Параметры пока не изменены'),true);
$clear=IncomingMessage::text('max',42005,$chat,'check-budget-clear','Бюджет без ограничений');
abCheck('check accepts explicit budget clear',$controller->handleIncomingMessage($clear),true);
abCheck('check clear removes active budget',isset(MaxSearchApi::getAiSearchContext($chat)['budget']),false);
abCheck('check clear confirmation is explicit',str_contains(end($messenger->sent)[1],'Ограничение по бюджету снято'),true);
abApply($chat,'До 250 тысяч');
MaxSearchApi::setStatus($chat,MaxSearchApi::$statusAi);

// Exercise the real shadow observer without an external AI call. A stale
// shadow budget must not override the standalone source, including its clear.
$shadowPath='tests/.budget-shadow-'.bin2hex(random_bytes(6));
ProjectConfig::resetForTests(['id'=>'budget-test','state'=>['v2_store_dir'=>$shadowPath]]);
DiagnosticLogger::setFile($tmp.'/shadow-events.log');
register_shutdown_function(static function()use($root,$shadowPath,$tmp):void{
    foreach(glob($root.'/'.$shadowPath.'/*')?:[] as $f) @unlink($f);
    @rmdir($root.'/'.$shadowPath);@unlink($tmp.'/shadow-events.log');@rmdir($tmp);
});
if (defined('OPENAI_API_KEY') && OPENAI_API_KEY!=='') throw new RuntimeException('network_ai_not_allowed_in_fixture');
NeedApplicationService::applyParameters($chat,['adults'=>2]);
$staleShadow=TripStateService::fromLegacyAiContext(MaxSearchApi::getAiSearchContext($chat));
$staleShadow['budget']=['max'=>999000,'currency'=>'RUB','basis'=>'per_person'];
TripStateRepository::save($chat,$staleShadow,$root);
$observed=AiShadowObserver::observe($chat,'На восемь ночей');
abCheck('shadow input uses canonical saved budget',$observed['old_state']['budget']??null,MaxSearchApi::getAiSearchContext($chat)['budget']);
abCheck('shadow result cannot restore an older budget',$observed['new_state']['budget']??null,MaxSearchApi::getAiSearchContext($chat)['budget']);
$beforeClear=MaxSearchApi::getAiSearchContext($chat)['budget'];
abApply($chat,'Бюджет без ограничений');
TripStateRepository::save($chat,$staleShadow,$root);
$observed=AiShadowObserver::observe($chat,'На восемь ночей');
abCheck('shadow input respects explicitly cleared budget',$observed['old_state']['budget']['max']??null,null);
abCheck('shadow result respects explicitly cleared budget',$observed['new_state']['budget']['max']??null,null);
abApply($chat,'До 250 тысяч');
TripStateRepository::delete($chat,$root);
ProjectConfig::resetForTests(['id'=>'budget-test']);
DiagnosticLogger::setFile($tmp.'/shadow-events.log');

$other=-42002;MaxSearchApi::setStatus($other,64);MaxSearchApi::setStatus($other,76);
abCheck('other chat isolated',isset(MaxSearchApi::getAiSearchContext($other)['budget']),false);
abCheck('cross-chat snapshot rejected',ConversationStateRepository::applyBudget($other,['snapshot'=>ConversationStateRepository::budgetSnapshot($chat),'changes'=>['budget.max'=>1]]),false);
ProjectConfig::resetForTests(['id'=>'other-project']);
abCheck('other project isolated',ConversationStateRepository::budgetSnapshot($chat),[]);
ProjectConfig::resetForTests(['id'=>'budget-test']);
$start=ConversationStateRepository::budgetSnapshot($chat);
// Deliberate two-connection interleaving in MySQL: current start was read,
// then another connection altered its raw envelope before the guarded UPDATE.
$writer=$useMysql?new PDO('mysql:unix_socket='.$socket.';dbname='.$testDb.';charset=utf8mb4','root','',$options):$pdo;
foreach ([str_replace('trip_budget_v1','TRIP_BUDGET_V1',$start['raw']),$start['raw'].' '] as $changedRaw) {
    $writer->prepare('UPDATE runtime_dialogue_state SET value_text=? WHERE id=?')->execute([$changedRaw,$start['start_id']]);
    abCheck('CAS rejects raw change even under case-insensitive or space-padding collation',MysqlDialogueStateRepository::compareStartValue($chat,64,$start['start_id'],$start['raw'],TripBudgetPolicy::toStartValue(['max'=>1,'currency'=>'RUB'])),false);
    abCheck('CAS preserves the concurrently changed envelope',MysqlDialogueStateRepository::startValue($chat,64)['UF_VALUE'],$changedRaw);
    $writer->prepare('UPDATE runtime_dialogue_state SET value_text=? WHERE id=?')->execute([$start['raw'],$start['start_id']]);
}

$pdo->prepare('UPDATE runtime_dialogue_state SET value_text=? WHERE id=?')->execute(['unowned legacy value',$start['start_id']]);
abCheck('unowned start payload is not parsed',ConversationStateRepository::budgetSnapshot($chat),[]);
abCheck('unowned start payload not overwritten',abApply($chat,'до 250 тыс'),[]);
MaxSearchApi::deleteAllStatus($chat);
abCheck('explicit reset clears current budget',ConversationStateRepository::budgetSnapshot($chat),[]);
abCheck('no start does not silently insert one',abApply($chat,'до 250 тысяч'),[]);
$all=(int)$pdo->query('SELECT COUNT(*) FROM runtime_dialogue_state WHERE chat_id="-42001"')->fetchColumn();
abCheck('missing start has no side effects',$all,0);

echo "TOTAL $n | PASS ".($n-$failed)." | FAIL $failed\n";exit($failed?1:0);