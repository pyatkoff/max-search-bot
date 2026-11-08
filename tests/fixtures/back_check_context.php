<?php
declare(strict_types=1);

// Synthetic storage and captured messenger only. Exercise the real callback,
// summary, current-readiness and state owners without production bootstrap.
define('MAX_SEARCH_RUNTIME_STORAGE', 'mysql');
$root = dirname(__DIR__, 2);
require_once $root.'/services/ConversationStateRepository.php';
require_once $root.'/services/AiSearchContextService.php';
require_once $root.'/services/PendingMonthStore.php';

function backCheckMethods(string $source, string $first, string $next): string
{
    $start = strpos($source, $first);
    $end = $start === false ? false : strpos($source, $next, $start);
    if ($start === false || $end === false) throw new RuntimeException('Canonical API boundary changed');
    return substr($source, $start, $end-$start);
}
$api = (string)file_get_contents($root.'/maxsearchclass.php');
$methods = backCheckMethods($api, 'private static function statusMap()', 'public static function showStart(')
    . backCheckMethods($api, 'public static function getCurentStatus(', 'public static function applyAiParameters(');
eval('class MaxSearchApi {
    public static $HL=0, $statusStart=64, $statusCityChoose=65, $statusContryChoose=66,
        $statusAdults=67, $statusChild=68, $statusAge=69, $statusStars=70,
        $statusMeal=71, $statusNights=72, $statusDate=73, $statusCheck=74,
        $statusPhone=75, $statusAi=76;
    public static array $requests=[];
    public static function MaxRequest($method,$payload){ self::$requests[]=[$method,$payload]; return true; }
    public static function getCityByID($id){ return "Synthetic departure"; }
    public static function getCountryByID($id){ return "Synthetic destination"; }
    public static function formatSavedData($data){ return ["Synthetic saved trip"]; }
    public static function funnelLog(...$args){}
    '.$methods.'
}');
require_once $root.'/services/CallbackController.php';

final class BackCheckMessenger implements MessengerInterface
{
    public array $messages=[];
    public bool $succeed=true;
    public function send($chatId,string $text): bool
    {
        $this->messages[]=['text'=>$text,'buttons'=>[]];
        return $this->succeed;
    }
    public function sendWithButtons($chatId,string $text,array $buttons): bool
    {
        $this->messages[]=['text'=>$text,'buttons'=>$buttons];
        return $this->succeed;
    }
    public function sendContactRequest($chatId,string $text,string $manualCallback,string $backCallback): bool
    {
        throw new RuntimeException('Summary return must not request a phone');
    }
}
function backCheckAssert(string $label, bool $ok): void
{
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
}
$pdo = new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE runtime_dialogue_state (
    id INTEGER PRIMARY KEY AUTOINCREMENT, project_key TEXT NOT NULL,
    chat_id TEXT NOT NULL, status_id INTEGER NOT NULL, value_text TEXT, message_id TEXT)');
ProjectConfig::resetForTests(['id'=>'back-check-context-test']);
$property = new ReflectionProperty(ConversationDb::class, 'pdo');
$property->setAccessible(true);
$property->setValue(null,$pdo);

function backCheckSeed(int $chatId, int $lastField, bool $partial=false): array
{
    ConversationStateRepository::addStatus(0,$chatId,64);
    ConversationStateRepository::upsertValue(0,$chatId,72,'3');
    ConversationStateRepository::addStatus(0,$chatId,64);
    if ($lastField === 64) return [];
    $needs=[65=>1,66=>4,67=>2,68=>2,69=>'5, 10',70=>5,71=>7,
        72=>'8-12',73=>date('d.m.Y',strtotime('+300 days'))];
    if ($partial) unset($needs[72]);
    $last=$needs[$lastField];
    unset($needs[$lastField]);
    $needs[$lastField]=$last;
    foreach ($needs as $field=>$value) ConversationStateRepository::upsertValue(0,$chatId,$field,$value);
    return array_map(static fn($value): string => (string)$value,$needs);
}
function backCheckNeedRows(PDO $pdo,int $chatId): array
{
    $q=$pdo->prepare('SELECT id,status_id,value_text,message_id FROM runtime_dialogue_state
        WHERE project_key=? AND chat_id=? AND status_id BETWEEN 64 AND 73 ORDER BY id');
    $q->execute(['back-check-context-test',(string)$chatId]);
    return $q->fetchAll();
}
$firstChat=random_int(100000000,900000000);
$chats=[];
try {
    // The real cleanup API can delete a need: establish the former failure
    // precondition independently of the callback and the captured summary text.
    $control=$firstChat;
    $chats[]=$control;
    backCheckSeed($control,72);
    backCheckAssert('control starts from a complete current trip',MaxSearchApi::getAiMissingFields($control)===[]);
    MaxSearchApi::deletePrevMessage($control,true);
    backCheckAssert('destructive cleanup of the last need explains the repeated nights question',
        MaxSearchApi::getAiMissingFields($control)===['nights']);

    $cases=[
        ['nights_last',72,false,true],
        ['ages_last',69,false,true],
        ['date_last',73,false,true],
        ['actually_missing_nights',69,true,true],
        ['empty_current_session',64,true,true],
        ['summary_send_failure',72,false,false],
    ];
    foreach ($cases as $index=>[$label,$lastField,$partial,$succeed]) {
        $chatId=$firstChat+$index+1;
        $chats[]=$chatId;
        $expected=backCheckSeed($chatId,$lastField,$partial);
        $before=backCheckNeedRows($pdo,$chatId);
        $missingBefore=MaxSearchApi::getAiMissingFields($chatId);
        $messenger=new BackCheckMessenger();
        $messenger->succeed=$succeed;
        IntegrationRegistry::resetForTests($messenger);
        $controller=new CallbackController();
        backCheckAssert($label.' callback is consumed',
            $controller->handle(['from'=>['id'=>$chatId],'data'=>'back_check']));
        backCheckAssert($label.' retains every need row and session boundary',
            backCheckNeedRows($pdo,$chatId)===$before);
        $active=array_intersect_key(MaxSearchApi::getSavedData($chatId),$expected);
        ksort($active);
        $expectedValues=$expected;
        ksort($expectedValues);
        backCheckAssert($label.' retains exact active values',$active===$expectedValues);
        backCheckAssert($label.' preserves current search readiness',
            MaxSearchApi::getAiMissingFields($chatId)===$missingBefore);
        backCheckAssert($label.' makes exactly one presentation attempt',count($messenger->messages)===1);
        $text=$messenger->messages[0]['text']??'';
        backCheckAssert($label.' presents the summary or only the genuinely missing need',
            $partial ? $text===MissingFieldQuestionService::question($missingBefore[0]) : str_contains($text,'Готово! Проверьте параметры'));

        // Later intentional returns still use the action owner; no artificial
        // sleep, guard-window change, live message or source replacement.
        $messenger->succeed=true;
        backCheckAssert($label.' later return remains handled',WizardCallbackAction::handle($chatId,'back_check'));
        backCheckAssert($label.' later return retains saved needs',backCheckNeedRows($pdo,$chatId)===$before);
    }
    echo 'BACK CHECK CONTEXT: OK'.PHP_EOL;
} finally {
    foreach ($chats as $chatId) {
        @unlink(InteractionGuard::lockPath($chatId,'dedupe.wizard_back'));
        PendingMonthStore::clear($chatId);
    }
    IntegrationRegistry::resetForTests();
    ProjectConfig::resetForTests(null);
}
