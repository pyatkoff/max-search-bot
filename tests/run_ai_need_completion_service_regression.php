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
aiCompletionCheck('final AI branch no longer applies parameters directly', substr_count($handler, 'NeedApplicationService::applyParameters($chat_id, $params)') === 0);
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

IntegrationRegistry::resetForTests();
ProjectConfig::resetForTests(null);

echo "\n--------------------------\nTOTAL " . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
