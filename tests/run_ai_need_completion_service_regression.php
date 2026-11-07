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
    public static function getAiMissingFields($chat){
        $missing=[];
        foreach(['city','country','adults','children','child_ages','nights','date'] as $key){
            if($key==='child_ages'){
                $children=(int)(self::$context['children']??0);
                if($children>0&&ChildAgeValueContract::fromStorage(self::$context['child_ages']??null,$children)===null)$missing[]=$key;
            }elseif(!array_key_exists($key,self::$context))$missing[]=$key;
        }
        return $missing;
    }
    public static function funnelLog(...$args){} public static function setStatus(...$args){}
}
class SourceHandlingService {
    public static function qualifyingTurn($chat){return true;}
    public static function qualificationNeedsChanged($chat){return MaxSearchApi::$context!==MaxSearchApi::$before;}
}
class NeedApplicationService {
    public static array $resolutions=[];
    public static function applyParameters($chat,array $params){MaxSearchApi::$context=array_merge(MaxSearchApi::$context,$params);return array_fill_keys(array_keys($params),true);}
    public static function resolveAndApply($chat,$field,$text,$context=[]){
        $result=NeedValueResolver::resolve($field,$text,$context);$result['applied']=false;
        if(!empty($result['recognized'])){$applied=self::applyParameters($chat,[$field=>$result['value']]);$result['applied']=!empty($applied[$field]);}
        self::$resolutions[]=['field'=>$field,'recognized'=>$result['recognized'],'applied'=>$result['applied']];
        return $result;
    }
}
class NeedProgressionService {
    public static array $calls=[];
    public static function advance($chat,array $options=[]){self::$calls[]=$options;return ['complete'=>!MaxSearchApi::getAiMissingFields($chat),'missing'=>MaxSearchApi::getAiMissingFields($chat),'next_field'=>null];}
}
class AiInvocationService {
    public static array $answer=[], $calls=[], $contexts=[];
    public static function invoke($route,$chat,$text,$current){self::$calls[]=$route;self::$contexts[]=$current;return self::$answer;}
}
class MissingFieldQuestionService {
    public static array $sent=[];
    public static function send($chat,$field,$options=[]){self::$sent[]=['field'=>$field,'options'=>$options];return true;}
    public static function sendText($chat,$text){self::$sent[]=$text;return true;}
    public static function sendForMissing($chat,$missing,$options=[]){self::$sent[]='UNEXPECTED_MISSING_QUESTION';return true;}
}
class AiDateHandler {public static function resolvePendingShortDate(...$args){return '';}}
class AiDateContextService {
    public static array $local=[];
    public static function resolveLocal($chat,$text){return self::$local?:['date'=>$text==='15 января'?'15.01.2027':''];}
    public static function applyAiGuard($chat,$text,$params){return $params;}
}
class AiBusinessDefaultsService {public static function apply($ai,...$args){return $ai;}}
class AiRuntimeLogger {public static function debug(...$args){} public static function error(...$args){}}
class DepartureCityResolver {
    public static ?array $resolved=null;
    public static function resolveAndStore($chat,$text){if(self::$resolved===null)return null;NeedApplicationService::applyParameters($chat,['city'=>self::$resolved['city']]);return self::$resolved;}
}
class DialogueView {}
function put_log_out(...$args){}
PHP;
file_put_contents($sourceTmp.'/bootstrap.php',$sourceBootstrap);
// Load the real parser graph from the checkout, not a partial copied dependency
// list. Child-age completeness and early resolution use their canonical owners.
foreach(['ChildAgeValueContract','NeedValueResolver'] as $name){
    file_put_contents($sourceTmp.'/bootstrap.php',"\nrequire_once ".var_export(dirname(__DIR__).'/services/'.$name.'.php',true).";\n",FILE_APPEND);
}
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
$ambiguous=['date'=>'','month_only'=>false,'ambiguous_months'=>true];
$compound='24 июля по 2 августа 2030, 2 взрослых 2 ребёнка 6,12 лет';
$cases=[
 ['faq','Есть ли бассейн?',$base,['intent'=>'question','parameters'=>[],'reply'=>'В публикации указан бассейн.'],[],null],
 ['mixed','На двоих, бассейн есть?',$base+['nights'=>'7','date'=>'15.01.2027'],['intent'=>'question','parameters'=>['adults'=>2,'children'=>0],'reply'=>'В публикации указан бассейн.'],[],null],
 ['stop','хватит',$base,['intent'=>'stop','parameters'=>[],'reply'=>'Хорошо.'],[],null],
 ['date_last','15 января',$base+['adults'=>2,'children'=>0,'nights'=>'7'],['intent'=>'search','parameters'=>[],'reply'=>''],[],null],
 ['ambiguous_party',$compound,$base+['nights'=>'7'],[],$ambiguous,null],
 ['ambiguous_empty','июль или август',[],[],$ambiguous,null],
 ['ambiguous_city_missing',$compound,['country'=>'Турция','nights'=>'7'],[],$ambiguous,null],
 ['ambiguous_existing','24 июля по 2 августа 2030',$base+['adults'=>3,'children'=>0,'nights'=>'9','date'=>'20.08.2030'],[],$ambiguous,null],
 ['ambiguous_departure','Хотим из Самары в Египет, '.$compound,$base+['nights'=>'7'],[],$ambiguous,['city'=>'Самара']],
 ['ambiguous_shared','24 июля по 2 августа 2030. Детям по 7 лет. Вы уже спрашивали',$base+['adults'=>2,'children'=>2,'nights'=>'7'],[],$ambiguous,null],
 ['shared_age_only','Детям по 7 лет',$base+['adults'=>2,'children'=>2,'nights'=>'7'],[],[],null],
 ['shared_age_tail','Детям по 7 лет. Вы уже спрашивали',$base+['adults'=>2,'children'=>2,'nights'=>'7'],['intent'=>'search','parameters'=>[],'reply'=>''],[],null],
 ['shared_age_correction_tail','Детям по 7 лет. Не 7, а 8',$base+['adults'=>2,'children'=>2,'nights'=>'7'],['intent'=>'question','parameters'=>[],'reply'=>'Уточните возраст детей.'],[],null],
];
foreach($cases as [$key,$text,$context,$answer,$localDate,$departure]){
 MaxSearchApi::$context=MaxSearchApi::$before=$context;AiInvocationService::$answer=$answer;AiInvocationService::$calls=[];AiInvocationService::$contexts=[];NeedApplicationService::$resolutions=[];NeedProgressionService::$calls=[];MissingFieldQuestionService::$sent=[];AiDateContextService::$local=$localDate;DepartureCityResolver::$resolved=$departure;
 AiMessageHandler::handle(['text'=>$text],42);
 $out[$key]=['router'=>AiInvocationService::$calls,'seen_contexts'=>AiInvocationService::$contexts,'resolutions'=>NeedApplicationService::$resolutions,'progress'=>NeedProgressionService::$calls,'sent'=>MissingFieldQuestionService::$sent,'context'=>MaxSearchApi::$context,'missing'=>MaxSearchApi::getAiMissingFields(42)];
}
// The next ordinary date answer must reuse the party saved before clarification.
MaxSearchApi::$context=MaxSearchApi::$before=$out['ambiguous_party']['context'];AiInvocationService::$answer=['intent'=>'search','parameters'=>[],'reply'=>''];AiInvocationService::$calls=[];NeedProgressionService::$calls=[];MissingFieldQuestionService::$sent=[];AiDateContextService::$local=['date'=>'24.07.2030'];DepartureCityResolver::$resolved=null;
AiMessageHandler::handle(['text'=>'24.07.2030'],42);
$out['after_date']=['progress'=>NeedProgressionService::$calls,'context'=>MaxSearchApi::$context,'missing'=>MaxSearchApi::getAiMissingFields(42)];
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
    foreach(['ambiguous_party','ambiguous_empty','ambiguous_city_missing','ambiguous_existing','ambiguous_departure','ambiguous_shared'] as $case){
        aiCompletionCheck($case.' asks only the unresolved date',($sourceResults[$case]['sent']??null)===[['field'=>'date','options'=>['ambiguous_months'=>true]]]);
        aiCompletionCheck($case.' does not infer remotely or progress to handoff',($sourceResults[$case]['router']??null)===[]&&($sourceResults[$case]['progress']??null)===[]);
    }
    $savedParty=$sourceResults['ambiguous_party']['context']??[];
    aiCompletionCheck('date clarification keeps all explicit party values',[$savedParty['adults']??null,$savedParty['children']??null,$savedParty['child_ages']??null]===[2,2,[6,12]]);
    aiCompletionCheck('date clarification preserves the known departure and no date is guessed',($savedParty['city']??null)==='Казань'&&!array_key_exists('date',$savedParty));
    aiCompletionCheck('date clarification adds no destination defaults',!array_key_exists('meal',$savedParty)&&!array_key_exists('stars',$savedParty));
    aiCompletionCheck('pure ambiguous date does not seed any needs',($sourceResults['ambiguous_empty']['context']??null)===[]);
    aiCompletionCheck('date clarification never substitutes Moscow for a missing departure',!array_key_exists('city',$sourceResults['ambiguous_city_missing']['context']??[]));
    aiCompletionCheck('ambiguous replacement preserves the previous date and independent needs',($sourceResults['ambiguous_existing']['context']??null)===['city'=>'Казань','country'=>'Турция','adults'=>3,'children'=>0,'nights'=>'9','date'=>'20.08.2030']);
    aiCompletionCheck('explicit city uses its existing resolver during date clarification',($sourceResults['ambiguous_departure']['context']['city']??null)==='Самара');
    aiCompletionCheck('explicit country is retained without applying its defaults',($sourceResults['ambiguous_departure']['context']['country']??null)==='Египет'&&!array_key_exists('meal',$sourceResults['ambiguous_departure']['context'])&&!array_key_exists('stars',$sourceResults['ambiguous_departure']['context']));
    aiCompletionCheck('complete shared age clause survives date clarification',($sourceResults['ambiguous_shared']['context']['child_ages']??null)===[7,7]);
    aiCompletionCheck('shared age plus comment is saved before the existing router',($sourceResults['shared_age_tail']['context']['child_ages']??null)===[7,7]&&($sourceResults['shared_age_tail']['seen_contexts'][0]['child_ages']??null)===[7,7]);
    foreach(['ambiguous_shared','shared_age_tail','shared_age_correction_tail'] as $case){
        aiCompletionCheck($case.' executes the real early age resolver before fallback',($sourceResults[$case]['resolutions'][0]??null)===['field'=>'child_ages','recognized'=>false,'applied'=>false]);
    }
    aiCompletionCheck('a complete shared-age answer succeeds in the existing early branch',($sourceResults['shared_age_only']['resolutions'][0]??null)===['field'=>'child_ages','recognized'=>true,'applied'=>true]&&($sourceResults['shared_age_only']['context']['child_ages']??null)===[7,7]&&($sourceResults['shared_age_only']['router']??null)===[]&&count($sourceResults['shared_age_only']['progress']??[])===1);
    aiCompletionCheck('numeric correction tail never stores the contradicted shared age',!array_key_exists('child_ages',$sourceResults['shared_age_correction_tail']['context']??[])&&in_array('child_ages',$sourceResults['shared_age_correction_tail']['missing']??[],true));
    aiCompletionCheck('later exact date reuses the full saved party',($sourceResults['after_date']['missing']??null)===[]&&($sourceResults['after_date']['context']['child_ages']??null)===[6,12]&&($sourceResults['after_date']['context']['date']??null)==='24.07.2030'&&count($sourceResults['after_date']['progress']??[])===1);
}catch(Throwable $error){aiCompletionCheck('source handler fixture completed',false);echo $error->getMessage()."\n";}
finally{
    foreach(['handlers','services'] as $dir){foreach(glob($sourceTmp.'/'.$dir.'/*')?:[] as $file)unlink($file);rmdir($sourceTmp.'/'.$dir);}
    foreach(glob($sourceTmp.'/*')?:[] as $file)unlink($file);rmdir($sourceTmp);
}

IntegrationRegistry::resetForTests();
ProjectConfig::resetForTests(null);

echo "\n--------------------------\nTOTAL " . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
