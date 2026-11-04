<?php

declare(strict_types=1);

require_once __DIR__ . '/../contracts/MessengerInterface.php';
require_once __DIR__ . '/../services/ProjectConfig.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';

class AiCompletionTestMessenger implements MessengerInterface
{
    public array $sent = [];

    public function send($chatId, string $text): bool
    {
        $this->sent[] = ['chat_id'=>$chatId, 'text'=>$text];
        return true;
    }

    public function sendWithButtons($chatId, string $text, array $buttons): bool { return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { return true; }
}

class MaxSearchApi
{
    public static $statusAi = 76;
    public static array $applyCalls = [];
    public static array $missingCalls = [];
    public static array $statusCalls = [];
    public static array $applyResult = [];
    public static array $missingResult = ['nights'];

    public static function applyAiParameters($chatId, array $params): array
    {
        self::$applyCalls[] = ['chat_id'=>$chatId, 'params'=>$params];
        return self::$applyResult;
    }

    public static function getAiMissingFields($chatId): array
    {
        self::$missingCalls[] = $chatId;
        return self::$missingResult;
    }

    public static function setStatus($chatId, $status, $mess = false): void
    {
        self::$statusCalls[] = ['chat_id'=>$chatId, 'status'=>$status];
    }
}

require_once __DIR__ . '/../services/AiNeedCompletionService.php';

$handler = (string)file_get_contents(dirname(__DIR__) . '/handlers/AiMessageHandler.php');
$service = (string)file_get_contents(dirname(__DIR__) . '/services/AiNeedCompletionService.php');
$passed = 0;
$failed = 0;

function aiCompletionCheck(string $name, $actual, $expected = true): void
{
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n"; $failed++;
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

function resetAiCompletionTestState(AiCompletionTestMessenger $messenger): void
{
    MaxSearchApi::$applyCalls = [];
    MaxSearchApi::$missingCalls = [];
    MaxSearchApi::$statusCalls = [];
    MaxSearchApi::$applyResult = [];
    MaxSearchApi::$missingResult = ['nights'];
    $messenger->sent = [];
}

$messenger = new AiCompletionTestMessenger();
IntegrationRegistry::resetForTests($messenger, null, null);

resetAiCompletionTestState($messenger);
MaxSearchApi::$applyResult = ['adults'=>true];
$accepted = AiNeedCompletionService::resolveApplyAndAdvance(42, 'adults', 'Двое');
aiCompletionCheck('recognized value remains visible to caller', $accepted['recognized'], true);
aiCompletionCheck('recognized value returns stable applied field map', $accepted['applied'], ['adults'=>true]);
aiCompletionCheck('recognized value advances', $accepted['advanced'], true);
aiCompletionCheck('recognized value exposes next field', $accepted['next_field'], 'nights');
aiCompletionCheck('recognized value is applied exactly once', count(MaxSearchApi::$applyCalls), 1);
aiCompletionCheck('progression reads missing fields exactly once', count(MaxSearchApi::$missingCalls), 1);
aiCompletionCheck('progression asks next question exactly once', count($messenger->sent), 1);

resetAiCompletionTestState($messenger);
$rejected = AiNeedCompletionService::resolveApplyAndAdvance(43, 'adults', 'не знаю');
aiCompletionCheck('rejected input returns stable empty applied map', $rejected['applied'], []);
aiCompletionCheck('rejected input does not advance', $rejected['advanced'], false);
aiCompletionCheck('rejected input performs no application', count(MaxSearchApi::$applyCalls), 0);
aiCompletionCheck('rejected input performs no progression', count(MaxSearchApi::$missingCalls), 0);
aiCompletionCheck('rejected input sends no next question', count($messenger->sent), 0);

resetAiCompletionTestState($messenger);
$notApplied = AiNeedCompletionService::resolveApplyAndAdvance(44, 'adults', '2');
aiCompletionCheck('application rejection keeps recognition result', $notApplied['recognized'], true);
aiCompletionCheck('application rejection returns stable empty applied map', $notApplied['applied'], []);
aiCompletionCheck('application rejection does not advance', $notApplied['advanced'], false);
aiCompletionCheck('application rejection performs no progression', count(MaxSearchApi::$missingCalls), 0);

resetAiCompletionTestState($messenger);
MaxSearchApi::$applyResult = ['children'=>true];
$direct = AiNeedCompletionService::applyAndAdvance(45, ['children'=>1]);
aiCompletionCheck('multi-field entry point shares applied map contract', $direct['applied'], ['children'=>true]);
aiCompletionCheck('multi-field entry point advances exactly once', count(MaxSearchApi::$missingCalls), 1);

aiCompletionCheck('handler uses canonical AI completion boundary', strpos($handler, 'AiNeedCompletionService::applyAndAdvance') !== false);
$finalBranch=substr($handler,(int)strpos($handler,'AiRuntimeLogger::debug("ROUTE AFTER AI: APPLY_PARAMETERS'));
aiCompletionCheck('final progressing AI branch uses only the completion boundary', strpos($finalBranch, 'NeedApplicationService::applyParameters') === false);
aiCompletionCheck('final AI branch no longer advances directly after AI application', strpos($handler, "NeedProgressionService::advance(\n                    \$chat_id,\n                    ['country_explicit'=>true]") === false);
aiCompletionCheck('completion service owns application', strpos($service, 'NeedApplicationService::applyParameters($chatId, $params)') !== false);
aiCompletionCheck('completion service owns progression', strpos($service, 'NeedProgressionService::advance($chatId, $questionOptions)') !== false);
aiCompletionCheck('completion service returns applied and missing diagnostics', strpos($service, "'applied' => \$applied") !== false && strpos($service, "'missing' =>") !== false);
aiCompletionCheck('handler preserves country explicit progression policy', strpos($handler, "['country_explicit'=>true]") !== false);
aiCompletionCheck('handler routes child ages through resolved completion boundary', strpos($handler, "AiNeedCompletionService::resolveApplyAndAdvance(\n                        \$chat_id,\n                        'child_ages'") !== false);
aiCompletionCheck('handler no longer resolves child ages directly', strpos($handler, "NeedApplicationService::resolveAndApply(\n                        \$chat_id,\n                        'child_ages'") === false);
aiCompletionCheck('handler no longer directly advances child age branch', strpos($handler, "if (!empty(\$ageResult['recognized']) && !empty(\$ageResult['applied'])) {\n                        NeedProgressionService::advance(\$chat_id);") === false);
aiCompletionCheck('completion service resolves field through application boundary', strpos($service, 'NeedApplicationService::resolveAndApply($chatId, $field, $text, $context)') !== false);
aiCompletionCheck('resolved completion advances only after recognized applied value', strpos($service, "if (empty(\$resolution['recognized']) || empty(\$resolution['applied']))") !== false && strpos($service, "'advanced' => false") !== false && strpos($service, "['advanced' => true]") !== false);
aiCompletionCheck('child-age caller remains compatible through advanced flag', strpos($handler, "if (!empty(\$ageResult['advanced']))") !== false);
$localCompletionBranch = "if (!\$sourceQualification && \$simpleLocal && empty(\$missingLocal) && \$hadCurrentBeforeLocal && !empty(\$appliedLocal)) {";
$localCompletionOffset = strpos($handler, $localCompletionBranch);
aiCompletionCheck('simple local completion branch still exists', $localCompletionOffset !== false);
$localCompletionSlice = $localCompletionOffset === false ? '' : substr($handler, $localCompletionOffset, 600);
aiCompletionCheck('simple local completion uses canonical progression', strpos($localCompletionSlice, 'NeedProgressionService::advance($chat_id);') !== false);
aiCompletionCheck('simple local completion no longer bypasses progression to check view', strpos($localCompletionSlice, 'DialogueView::check($chat_id);') === false);

// Execute the real handler with a deterministic router response. Only external
// storage, transport and model boundaries are replaced; no provider call occurs.
$sourceTmp=sys_get_temp_dir().'/source-qualification-ai-'.bin2hex(random_bytes(6));
mkdir($sourceTmp,0700);mkdir($sourceTmp.'/handlers',0700);mkdir($sourceTmp.'/services',0700);
$sourceBootstrap= <<<'PHP'
<?php
class MaxSearchApi {
    public static array $context=[], $before=[]; public static int $statusAi=79;
    public static function getAiSearchContext($chat){return self::$context;}
    public static function getAiMissingFields($chat){$missing=[];foreach(['city','country','adults','children','nights','date'] as $key)if(!array_key_exists($key,self::$context))$missing[]=$key;return $missing;}
    public static function funnelLog(...$args){} public static function setStatus(...$args){}
}
class SourceHandlingService {
    public static function qualifyingTurn($chat){return true;}
    public static function qualificationNeedsChanged($chat){return MaxSearchApi::$context!==MaxSearchApi::$before;}
}
class NeedApplicationService {
    public static function applyParameters($chat,array $params){MaxSearchApi::$context=array_merge(MaxSearchApi::$context,$params);return array_fill_keys(array_keys($params),true);}
}
class NeedProgressionService {
    public static array $calls=[];
    public static function advance($chat,array $options=[]){self::$calls[]=$options;return ['complete'=>!MaxSearchApi::getAiMissingFields($chat),'missing'=>MaxSearchApi::getAiMissingFields($chat),'next_field'=>null];}
}
class AiInvocationService {
    public static array $answer=[], $calls=[];
    public static function invoke($route,$chat,$text,$current){self::$calls[]=$route;return self::$answer;}
}
class MissingFieldQuestionService {
    public static array $sent=[];
    public static function sendText($chat,$text){self::$sent[]=$text;return true;}
    public static function sendForMissing($chat,$missing,$options=[]){self::$sent[]='UNEXPECTED_MISSING_QUESTION';return true;}
}
class AiDateHandler {public static function resolvePendingShortDate(...$args){return '';}}
class AiDateContextService {
    public static function resolveLocal($chat,$text){return ['date'=>$text==='15 января'?'15.01.2027':''];}
    public static function applyAiGuard($chat,$text,$params){return $params;}
}
class AiBusinessDefaultsService {public static function apply($ai,...$args){return $ai;}}
class AiRuntimeLogger {public static function debug(...$args){} public static function error(...$args){}}
class DepartureCityResolver {public static function resolveAndStore(...$args){return null;}}
class DialogueView {}
function put_log_out(...$args){}
PHP;
file_put_contents($sourceTmp.'/bootstrap.php',$sourceBootstrap);
foreach(['MissingFieldQuestionService','DialogueView','NeedApplicationService','NeedProgressionService','AiBusinessDefaultsService','AiInvocationService','AiRuntimeLogger','AiDateContextService','DepartureCityResolver','SourceHandlingService'] as $name){
    file_put_contents($sourceTmp.'/services/'.$name.'.php',"<?php require_once dirname(__DIR__).'/bootstrap.php';\n");
}
file_put_contents($sourceTmp.'/handlers/AiDateHandler.php',"<?php require_once dirname(__DIR__).'/bootstrap.php';\n");
file_put_contents($sourceTmp.'/services/LocalAiFallbackService.php','<?php require_once '.var_export(dirname(__DIR__).'/services/LocalAiFallbackService.php',true).';');
copy(dirname(__DIR__).'/handlers/AiMessageHandler.php',$sourceTmp.'/handlers/AiMessageHandler.php');
copy(dirname(__DIR__).'/services/AiNeedCompletionService.php',$sourceTmp.'/services/AiNeedCompletionService.php');
$sourceCases= <<<'PHP'
<?php
require __DIR__.'/handlers/AiMessageHandler.php';
$base=['city'=>'Казань','country'=>'Турция'];$out=[];
$cases=[
 ['faq','Есть ли бассейн?',$base,['intent'=>'question','parameters'=>[],'reply'=>'В публикации указан бассейн.']],
 ['mixed','На двоих, бассейн есть?',$base+['nights'=>'7','date'=>'15.01.2027'],['intent'=>'question','parameters'=>['adults'=>2,'children'=>0],'reply'=>'В публикации указан бассейн.']],
 ['stop','хватит',$base,['intent'=>'stop','parameters'=>[],'reply'=>'Хорошо.']],
 ['date_last','15 января',$base+['adults'=>2,'children'=>0,'nights'=>'7'],['intent'=>'search','parameters'=>[],'reply'=>'']],
];
foreach($cases as [$key,$text,$context,$answer]){
 MaxSearchApi::$context=MaxSearchApi::$before=$context;AiInvocationService::$answer=$answer;AiInvocationService::$calls=[];NeedProgressionService::$calls=[];MissingFieldQuestionService::$sent=[];
 AiMessageHandler::handle(['text'=>$text],42);
 $out[$key]=['router'=>AiInvocationService::$calls,'progress'=>NeedProgressionService::$calls,'sent'=>MissingFieldQuestionService::$sent,'context'=>MaxSearchApi::$context];
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
PHP;
file_put_contents($sourceTmp.'/run.php',$sourceCases);
try{
    $pipes=[];$process=proc_open([PHP_BINARY,$sourceTmp.'/run.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('source_fixture_process_unavailable');
    $sourceOutput=stream_get_contents($pipes[1]);$sourceError=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$sourceExit=proc_close($process);
    $sourceResults=json_decode($sourceOutput,true);
    aiCompletionCheck('source handler fixture runs without external model calls',$sourceExit===0&&is_array($sourceResults));
    if($sourceExit!==0)echo $sourceError."\n";
    foreach(['faq','mixed','stop'] as $case){
        aiCompletionCheck('source '.$case.' reaches existing router',count($sourceResults[$case]['router']??[])===1);
        aiCompletionCheck('source '.$case.' never completes from stale needs',($sourceResults[$case]['progress']??null)===[]);
    }
    aiCompletionCheck('short hotel FAQ answers before asking more needs',($sourceResults['faq']['sent']??null)===['В публикации указан бассейн.']);
    aiCompletionCheck('mixed party and hotel question retains the party',[$sourceResults['mixed']['context']['adults']??null,$sourceResults['mixed']['context']['children']??null]===[2,0]);
    aiCompletionCheck('short stop receives only its acknowledgement',($sourceResults['stop']['sent']??null)===['Хорошо.']);
    aiCompletionCheck('final local date survives empty model parameters',($sourceResults['date_last']['context']['date']??null)==='15.01.2027');
    aiCompletionCheck('final local date qualifies through canonical completion',($sourceResults['date_last']['progress'][0]['source_need_applied']??false)===true);
}catch(Throwable $error){aiCompletionCheck('source handler fixture completed',false);echo $error->getMessage()."\n";}
finally{
    foreach(['handlers','services'] as $dir){foreach(glob($sourceTmp.'/'.$dir.'/*')?:[] as $file)unlink($file);rmdir($sourceTmp.'/'.$dir);}
    foreach(glob($sourceTmp.'/*')?:[] as $file)unlink($file);rmdir($sourceTmp);
}

IntegrationRegistry::resetForTests();
ProjectConfig::resetForTests(null);

echo "\n--------------------------\nTOTAL " . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
