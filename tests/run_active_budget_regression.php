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
$usePreparedMysql=in_array('--prepared-mysql',$argv,true);
$useSocketMysql=in_array('--mysql',$argv,true);
if ($usePreparedMysql && $useSocketMysql) throw new RuntimeException('ambiguous_mysql_fixture_mode');
$useMysql=$usePreparedMysql || $useSocketMysql;
$driver=$useMysql?'mysql':'sqlite';
if (!in_array($driver,PDO::getAvailableDrivers(),true)) {fwrite(STDERR,"Required active-budget database tests need pdo_{$driver}; not skipped.\n");exit(1);}

// Load the REAL active API from a temporary source-only fixture; no production
// config is loaded, no network sender is used and no repository file is changed.
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/active-budget-'.bin2hex(random_bytes(6));
mkdir($tmp,0700);
file_put_contents($tmp.'/config.php',"<?php\ndefine('MAX_SEARCH_RUNTIME_STORAGE','mysql');\ndefine('MAX_SEARCH_TOKEN','');\n");
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
    // Both explicit modes create their own disposable database. No production
    // config or credentials are accepted, and neither mode falls back to another.
    $testDb='qualification_budget_'.bin2hex(random_bytes(6));
    if ($usePreparedMysql) {
        $adminDsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
        $testUser=(string)getenv('PREPARED_REPLY_MYSQL_USER');
        $testPass=(string)getenv('PREPARED_REPLY_MYSQL_PASS');
        if ($adminDsn!=='mysql:host=127.0.0.1;port=3306;dbname=maxbot_prepared_test;charset=utf8mb4'
            || $testUser!=='root' || $testPass!=='prepared-fixture-only') {
            throw new RuntimeException('isolated_prepared_mysql_fixture_required');
        }
        $testDsn=str_replace('dbname=maxbot_prepared_test;','dbname='.$testDb.';',$adminDsn);
    } else {
        $socket=(string)getenv('MAX_BUDGET_TEST_MYSQL_SOCKET');
        if ($socket==='' || strpos($socket,';')!==false || !is_file(dirname($socket).'/QUALIFICATION_TEST_ONLY')) throw new RuntimeException('isolated_mysql_fixture_required');
        $adminDsn='mysql:unix_socket='.$socket.';charset=utf8mb4';
        $testDsn='mysql:unix_socket='.$socket.';dbname='.$testDb.';charset=utf8mb4';
        $testUser='root';$testPass='';
    }
    $admin=new PDO($adminDsn,$testUser,$testPass,$options);
    $admin->exec('CREATE DATABASE `'.$testDb.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static function()use($admin,$testDb):void{$admin->exec('DROP DATABASE `'.$testDb.'`');});
    $pdo=new PDO($testDsn,$testUser,$testPass,$options);
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
$writer=$useMysql?new PDO($testDsn,$testUser,$testPass,$options):$pdo;
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

// Specialized hotel entries use the REAL source, view and state owners. The
// preceding no-start budget guard remains unchanged: lifecycle must create the
// boundary before collecting needs, not manufacture one inside a budget write.
foreach (['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS'] as $constant) {
    if (!defined($constant)) define($constant,'isolated-source-fixture');
}
require_once $root.'/services/SourceHandlingService.php';
require_once $root.'/services/ConversationControlService.php';
require_once $root.'/actions/callbacks/WizardCallbackAction.php';
$eventId=$useMysql?'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
$pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key VARCHAR(64),is_active INTEGER);
    CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,project_id INTEGER,source_key VARCHAR(96),display_name VARCHAR(100),channel VARCHAR(20),is_active INTEGER,handling_mode VARCHAR(20));
    CREATE TABLE conversations(id INTEGER PRIMARY KEY,project_key VARCHAR(64),source_id INTEGER,channel VARCHAR(20),external_chat_id VARCHAR(64),status VARCHAR(32),manager_id INTEGER);
    CREATE TABLE conversation_events(id $eventId,conversation_id INTEGER,event_type VARCHAR(64),actor_type VARCHAR(32),actor_id VARCHAR(64),payload_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE post_channels(id INTEGER PRIMARY KEY,project_id INTEGER,source_id INTEGER,platform VARCHAR(20),bot_key VARCHAR(191),canonical_bot_username VARCHAR(191),channel_id VARCHAR(64),display_name VARCHAR(100),is_enabled INTEGER);
    CREATE TABLE post_publications(id INTEGER PRIMARY KEY,post_channel_id INTEGER,source_id INTEGER,external_message_id VARCHAR(191));
    CREATE TABLE post_versions(id INTEGER PRIMARY KEY,publication_id INTEGER,version_number INTEGER,snapshot_json TEXT);
    CREATE TABLE post_entries(id INTEGER PRIMARY KEY,conversation_id INTEGER,publication_id INTEGER,version_id INTEGER,availability_reason VARCHAR(32),created_at DATETIME);
    CREATE TABLE catalog_departures(id INTEGER PRIMARY KEY,name VARCHAR(100),name_genitive VARCHAR(100),is_active INTEGER);
    CREATE TABLE catalog_countries(id INTEGER PRIMARY KEY,name VARCHAR(100),is_active INTEGER);
    INSERT INTO projects VALUES(1,'anytour',1);
    INSERT INTO catalog_departures VALUES(15,'Самара','Самары',1);
    INSERT INTO catalog_countries VALUES(1,'Египет',1);");
$catalogConnection=new ReflectionProperty(HotelDatabase::class,'pdo');
$catalogConnection->setAccessible(true);
$catalogConnection->setValue(null,$pdo);
ProjectConfig::resetForTests([
    'id'=>'anytour',
    'channel_posts'=>[
        'max'=>['bot_key'=>'id9704048781_1_bot','bot_url'=>'https://max.ru/id9704048781_1_bot'],
        'telegram'=>['bot_key'=>'Any_tour_bot','bot_url'=>'https://t.me/Any_tour_bot'],
    ],
]);
$sourceDefinitions=[
    ['max',11,'fixture_max_hotel','id9704048781_1_bot','-80095447388179','https://max.ru/c/-80095447388179/fixture'],
    ['telegram',12,'fixture_tg_hotel','Any_tour_bot','-1001234567890','https://t.me/c/1234567890/31'],
];
foreach($sourceDefinitions as [$platform,$id,$sourceKey,$botKey,$channelId,$postUrl]) {
    $sourceChat=-43000-$id;
    $pdo->prepare('INSERT INTO conversation_sources VALUES(?,1,?,?,?,1,?)')->execute([$id,$sourceKey,'Fixture hotel',$platform,'manager']);
    $pdo->prepare('INSERT INTO conversations VALUES(?,?,?,?,?,?,NULL)')->execute([$id,'anytour',$id,$platform,(string)$sourceChat,'ai']);
    $pdo->prepare('INSERT INTO post_channels VALUES(?,1,?,?,?,?,?,?,1)')->execute([$id,$id,$platform,$botKey,$botKey,$channelId,'Fixture hotel channel']);
    $pdo->prepare('INSERT INTO post_publications VALUES(?,?,?,?)')->execute([$id,$id,$id,'fixture-post']);
    $snapshot=['title'=>'Siva Grand Beach','text'=>'Siva Grand Beach. Египет.','format'=>'native','post_url'=>$postUrl,'published_at'=>'2026-11-06 12:00:00'];
    $pdo->prepare('INSERT INTO post_versions VALUES(?,?,1,?)')->execute([$id,$id,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $pdo->prepare('INSERT INTO post_entries VALUES(?,?,?,?,?,?)')->execute([$id,$id,$id,$id,'','2026-11-07 10:00:00']);
    $sourceStart=['platform'=>$platform,'type'=>$platform==='max'?'bot_started':'message',
        'source_key'=>$sourceKey,'entry_kind'=>'channel_post','text'=>'/start p_fixture',
        'user'=>['chat_id'=>$sourceChat,'external_user_id'=>abs($sourceChat)]];
    abCheck('hotel entry begins without a hidden fixture start: '.$platform,MaxSearchApi::getCurentStatus($sourceChat),false);
    abCheck('real source owner handles hotel entry: '.$platform,SourceHandlingService::handle($sourceStart),true);
    $startSnapshot=ConversationStateRepository::budgetSnapshot($sourceChat);
    abCheck('hotel entry creates the canonical budget boundary: '.$platform,!empty($startSnapshot['start_id']),true);
    abCheck('hotel entry remains in AI collection: '.$platform,MaxSearchApi::getCurentStatus($sourceChat),MaxSearchApi::$statusAi);
    NeedApplicationService::applyParameters($sourceChat,['city'=>'Самара','country'=>'Египет','adults'=>2,'children'=>0,'nights'=>'6','date'=>'27.12.2030']);
    MaxSearchApi::setStatus($sourceChat,MaxSearchApi::$statusCheck);
    abCheck('hotel source check accepts the real budget handler: '.$platform,$controller->handleIncomingMessage(
        IncomingMessage::text($platform,abs($sourceChat),$sourceChat,'source-budget-'.$platform,'До 200 тысяч')),true);
    abCheck('hotel source budget is saved: '.$platform,MaxSearchApi::getAiSearchContext($sourceChat)['budget']['max']??null,200000);
    $savedSource=MaxSearchApi::getSavedData($sourceChat);
    $rowCount=(int)$pdo->query("SELECT COUNT(*) FROM runtime_dialogue_state WHERE project_key='anytour' AND chat_id='".(int)$sourceChat."'")->fetchColumn();
    $sentCount=count($messenger->sent);
    abCheck('same source reentry is handled: '.$platform,SourceHandlingService::handle($sourceStart),true);
    abCheck('same source reentry remains silent: '.$platform,count($messenger->sent),$sentCount);
    abCheck('same source reentry preserves every saved value: '.$platform,MaxSearchApi::getSavedData($sourceChat),$savedSource);
    abCheck('same source reentry appends no start or mode: '.$platform,
        (int)$pdo->query("SELECT COUNT(*) FROM runtime_dialogue_state WHERE project_key='anytour' AND chat_id='".(int)$sourceChat."'")->fetchColumn(),$rowCount);
    abCheck('same source reentry retains the original start: '.$platform,ConversationStateRepository::budgetSnapshot($sourceChat)['start_id']??null,$startSnapshot['start_id']??null);

    // The existing cancellation owner resumes AI. Source handling only records
    // the explicit choice, and the real callback renders the preserved trip.
    $pdo->prepare("UPDATE conversations SET status='waiting_manager' WHERE id=?")->execute([$id]);
    $back=['platform'=>$platform,'type'=>'callback','callback_data'=>'back_check','user'=>$sourceStart['user']];
    abCheck('source back delegates cancellation: '.$platform,SourceHandlingService::handle($back),false);
    abCheck('source back does not take manager ownership itself: '.$platform,ConversationControlService::statusByChat($platform,$sourceChat)['status']??null,'waiting_manager');
    abCheck('canonical cancellation resumes AI: '.$platform,ConversationControlService::resumeAiByChat($platform,$sourceChat,'handoff_cancelled'),true);
    abCheck('real back callback renders current conditions: '.$platform,WizardCallbackAction::handle($sourceChat,'back_check'),true);
    abCheck('back summary retains departure: '.$platform,str_contains(end($messenger->sent)[1],'Самара'),true);
    abCheck('back summary retains destination: '.$platform,str_contains(end($messenger->sent)[1],'Египет'),true);
    abCheck('back retains the budget: '.$platform,MaxSearchApi::getAiSearchContext($sourceChat)['budget']['max']??null,200000);
    abCheck('back retains explicit zero children: '.$platform,MaxSearchApi::getAiSearchContext($sourceChat)['children']??null,0);
    $reply=['platform'=>$platform,'type'=>'message','text'=>'Есть ли бассейн?','user'=>$sourceStart['user']];
    abCheck('self-service reply reaches the ordinary collector: '.$platform,SourceHandlingService::handle($reply),false);
    $post=SourceHandlingService::sourcePostContext($sourceChat);
    abCheck('self-service back retains the immutable hotel text: '.$platform,$post['text']??null,$snapshot['text']);
    abCheck('self-service back retains the exact publication: '.$platform,$post['publication_id']??null,$id);
    abCheck('self-service context is not qualifying: '.$platform,SourceHandlingService::qualifyingTurn($sourceChat),false);
    abCheck('self-service completion cannot hand off automatically: '.$platform,SourceHandlingService::completeQualification($sourceChat,['source_need_applied'=>true]),null);
    abCheck('post context cannot cross chat IDs: '.$platform,SourceHandlingService::sourcePostContext($sourceChat-1000),[]);
    $pdo->prepare("UPDATE conversations SET status='manager',manager_id=9 WHERE id=?")->execute([$id]);
    abCheck('assignment also fences an already cached post: '.$platform,SourceHandlingService::sourcePostContext($sourceChat),[]);
    $sentCount=count($messenger->sent);
    abCheck('assigned source reentry stays owned: '.$platform,SourceHandlingService::handle($sourceStart),true);
    abCheck('assigned source reentry stays silent: '.$platform,count($messenger->sent),$sentCount);
    abCheck('assigned source reentry retains current needs: '.$platform,MaxSearchApi::getAiSearchContext($sourceChat)['budget']['max']??null,200000);
    $pdo->prepare("UPDATE conversations SET status='ai',manager_id=NULL,source_id=999 WHERE id=?")->execute([$id]);
    SourceHandlingService::handle($reply);
    abCheck('changed source cannot inherit the previous hotel: '.$platform,SourceHandlingService::sourcePostContext($sourceChat),[]);
}
abCheck('source lifecycle never queued another manager request',
    (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type IN ('manager_request','waiting_manager')")->fetchColumn(),0);

// A genuine first message can reach the specialized collector without bot_started.
$firstChat=-43999;
$pdo->prepare('INSERT INTO conversations VALUES(?,?,?,?,?,?,NULL)')->execute([99,'anytour',11,'max',(string)$firstChat,'ai']);
$first=['platform'=>'max','type'=>'message','text'=>'Самара','user'=>['chat_id'=>$firstChat,'external_user_id'=>abs($firstChat)]];
abCheck('first source message delegates to the collector',SourceHandlingService::handle($first),false);
abCheck('first source message receives the same canonical start',!empty(ConversationStateRepository::budgetSnapshot($firstChat)['start_id']),true);
abCheck('first source message still qualifies normally',SourceHandlingService::qualifyingTurn($firstChat),true);

// Recover a real pre-fix origin on the next self-service message. Its historical
// back receipt deliberately has no source_id; it can repair its own boundary,
// while publication context remains unavailable without a confirmed binding.
function abSourceStateRows(PDO $pdo,int $chatId,string $project='anytour'):array {
    $q=$pdo->prepare('SELECT id,status_id,value_text,message_id FROM runtime_dialogue_state WHERE project_key=? AND chat_id=? ORDER BY id');
    $q->execute([$project,(string)$chatId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function abRestoreSource(int $chatId):bool {
    return ConversationStateRepository::restoreSourceStartBoundary(
        $chatId,(int)MaxSearchApi::$statusStart,(int)MaxSearchApi::$statusAi,(int)MaxSearchApi::$statusCheck,
        [(int)MaxSearchApi::$statusCityChoose,(int)MaxSearchApi::$statusContryChoose,
            (int)MaxSearchApi::$statusAdults,(int)MaxSearchApi::$statusChild,(int)MaxSearchApi::$statusAge,
            (int)MaxSearchApi::$statusStars,(int)MaxSearchApi::$statusMeal,(int)MaxSearchApi::$statusNights,
            (int)MaxSearchApi::$statusDate,(int)MaxSearchApi::$statusPhone]
    );
}
$orphanChat=-44001;
$pdo->prepare('INSERT INTO conversations VALUES(?,?,?,?,?,?,NULL)')->execute([101,'anytour',11,'max',(string)$orphanChat,'ai']);
MaxSearchApi::setStatus($orphanChat,MaxSearchApi::$statusAi);
NeedApplicationService::applyParameters($orphanChat,['city'=>'Самара','country'=>'Египет','adults'=>2,'children'=>2,'child_ages'=>[6,12],'nights'=>'7','date'=>'25.08.2030']);
MaxSearchApi::setStatus($orphanChat,MaxSearchApi::$statusCheck);
ConversationRecorder::eventByChat('max',$orphanChat,'source_handling_choice',['choice'=>'ai','reason'=>'handoff_back'],'system');
$orphanRows=abSourceStateRows($pdo,$orphanChat);
$orphanContext=MaxSearchApi::getAiSearchContext($orphanChat);
$oldCityId=0;
foreach($orphanRows as $row) if((int)$row['status_id']===(int)MaxSearchApi::$statusCityChoose) $oldCityId=(int)$row['id'];
abCheck('pre-fix source origin has no budget boundary',ConversationStateRepository::budgetSnapshot($orphanChat),[]);
$naturalBudget=IncomingMessage::text('max',abs($orphanChat),$orphanChat,'orphan-budget','До 200 тысяч');
abCheck('historical back message reaches self-service',SourceHandlingService::handle($naturalBudget),false);
$recoveredRows=abSourceStateRows($pdo,$orphanChat);
abCheck('recovery promotes the original first row ID',
    ConversationStateRepository::budgetSnapshot($orphanChat)['start_id']??null,(int)$orphanRows[0]['id']);
abCheck('recovery neither copies nor deletes any existing value row',array_slice($recoveredRows,1),array_slice($orphanRows,1));
abCheck('recovery preserves all canonical needs',MaxSearchApi::getAiSearchContext($orphanChat),$orphanContext);
abCheck('recovery retains the completed check mode',MaxSearchApi::getCurentStatus($orphanChat),MaxSearchApi::$statusCheck);
abCheck('historical back without source binding does not guess a post',SourceHandlingService::sourcePostContext($orphanChat),[]);
abCheck('historical back remains ordinary self-service',SourceHandlingService::qualifyingTurn($orphanChat),false);
abCheck('recovered source accepts the real budget handler',$controller->handleIncomingMessage($naturalBudget),true);
abCheck('recovered budget is persisted',MaxSearchApi::getAiSearchContext($orphanChat)['budget']['max']??null,200000);
$afterRecovery=abSourceStateRows($pdo,$orphanChat);
abCheck('existing start makes recovery idempotent',abRestoreSource($orphanChat),false);
abCheck('idempotent recovery changes no row',abSourceStateRows($pdo,$orphanChat),$afterRecovery);

// Reproduce a competing ordinary writer that had already selected the old need
// row before recovery. A native second connection writes that same ID afterward;
// retaining the earliest start ID must keep this new answer visible.
$writer->prepare('UPDATE runtime_dialogue_state SET value_text=? WHERE project_key=? AND chat_id=? AND id=?')
    ->execute(['19','anytour',(string)$orphanChat,$oldCityId]);
abCheck('a previously selected need row remains visible after recovery',
    MaxSearchApi::getSavedData($orphanChat)[MaxSearchApi::$statusCityChoose]??null,'19');
abCheck('concurrent need update retains the recovered start',
    ConversationStateRepository::budgetSnapshot($orphanChat)['start_id']??null,(int)$orphanRows[0]['id']);

$singleChat=-44002;
MaxSearchApi::setStatus($singleChat,MaxSearchApi::$statusAi);
$singleFirst=abSourceStateRows($pdo,$singleChat)[0];
abCheck('single empty source AI row is repairable',abRestoreSource($singleChat),true);
abCheck('single-row repair keeps the original boundary ID',
    ConversationStateRepository::budgetSnapshot($singleChat)['start_id']??null,(int)$singleFirst['id']);
abCheck('single-row repair restores AI mode',MaxSearchApi::getCurentStatus($singleChat),MaxSearchApi::$statusAi);
abCheck('single-row repair only appends its lost mode',count(abSourceStateRows($pdo,$singleChat)),2);

$zeroChat=-44003;
MaxSearchApi::setStatus($zeroChat,MaxSearchApi::$statusAi);
NeedApplicationService::applyParameters($zeroChat,['children'=>0,'nights'=>'6','date'=>'27.12.2030']);
$zeroBefore=MaxSearchApi::getAiSearchContext($zeroChat);
abCheck('source recovery accepts explicit zero children',abRestoreSource($zeroChat),true);
abCheck('source recovery preserves zero and date/night values',MaxSearchApi::getAiSearchContext($zeroChat),$zeroBefore);
abCheck('zero children is still present after recovery',MaxSearchApi::getAiSearchContext($zeroChat)['children']??null,0);

$rejectedOrigins=[
    -44101=>['first_value'=>'unowned AI metadata'],
    -44102=>['first_message'=>'owned-message'],
    -44103=>['unknown_status'=>999],
    -44104=>['existing_start'=>true],
    -44105=>['wrong_first'=>true],
    -44106=>['too_many'=>true],
];
foreach($rejectedOrigins as $rejectedChat=>$case) {
    MaxSearchApi::setStatus($rejectedChat,!empty($case['wrong_first'])?MaxSearchApi::$statusAdults:MaxSearchApi::$statusAi);
    $first=abSourceStateRows($pdo,$rejectedChat)[0];
    if(isset($case['first_value'])) $pdo->prepare('UPDATE runtime_dialogue_state SET value_text=? WHERE id=?')->execute([$case['first_value'],$first['id']]);
    if(isset($case['first_message'])) $pdo->prepare('UPDATE runtime_dialogue_state SET message_id=? WHERE id=?')->execute([$case['first_message'],$first['id']]);
    if(isset($case['unknown_status'])) MaxSearchApi::setStatus($rejectedChat,$case['unknown_status']);
    if(!empty($case['existing_start'])) MaxSearchApi::setStatus($rejectedChat,MaxSearchApi::$statusStart);
    if(!empty($case['too_many'])) for($i=0;$i<256;$i++) MaxSearchApi::setStatus($rejectedChat,MaxSearchApi::$statusAi);
    $beforeRejected=abSourceStateRows($pdo,$rejectedChat);
    abCheck('unknown source origin is rejected: '.$rejectedChat,abRestoreSource($rejectedChat),false);
    abCheck('rejected source origin is unchanged: '.$rejectedChat,abSourceStateRows($pdo,$rejectedChat),$beforeRejected);
}
$nestedChat=-44107;
MaxSearchApi::setStatus($nestedChat,MaxSearchApi::$statusAi);
$nestedBefore=abSourceStateRows($pdo,$nestedChat);
$pdo->beginTransaction();
abCheck('source recovery does not join a caller transaction',abRestoreSource($nestedChat),false);
abCheck('source recovery does not commit the caller transaction',$pdo->inTransaction(),true);
abCheck('nested transaction retains exact rows',abSourceStateRows($pdo,$nestedChat),$nestedBefore);
$pdo->rollBack();
abCheck('missing other chat is not initialized by recovery',abRestoreSource(-44999),false);
ProjectConfig::resetForTests(['id'=>'other-project']);
abCheck('source recovery cannot cross project scope',abRestoreSource($nestedChat),false);
abCheck('other project has no inserted start',abSourceStateRows($pdo,$nestedChat,'other-project'),[]);
ProjectConfig::resetForTests(['id'=>'anytour']);
abCheck('cross-project rejection retains original rows',abSourceStateRows($pdo,$nestedChat),$nestedBefore);

// Actual persistence for the multi-month guard: no model invocation is needed
// to retain the other explicit conditions while asking for one departure date.
$compoundChat=-44998;
DialogueView::prepareSourceTrip($compoundChat);
NeedApplicationService::applyParameters($compoundChat,['city'=>'Самара','country'=>'Египет']);
$sentBefore=count($messenger->sent);
AiMessageHandler::handle(['text'=>'24 июля по 2 августа 2030, 2 взрослых 2 ребёнка 6,12 лет'],$compoundChat);
$compound=MaxSearchApi::getAiSearchContext($compoundChat);
abCheck('multi-month guard durably saves explicit adults',$compound['adults']??null,2);
abCheck('multi-month guard durably saves explicit children',$compound['children']??null,2);
abCheck('multi-month guard durably saves both child ages',$compound['child_ages']??null,'6, 12');
abCheck('multi-month guard leaves only the date unresolved',isset($compound['date']),false);
abCheck('multi-month guard sends exactly one clarification',count($messenger->sent)-$sentBefore,1);
abCheck('multi-month guard asks the canonical date question',end($messenger->sent)[1],
    MissingFieldQuestionService::question('date',['ambiguous_months'=>true]));

echo "TOTAL $n | PASS ".($n-$failed)." | FAIL $failed\n";exit($failed?1:0);
