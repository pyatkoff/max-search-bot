<?php

declare(strict_types=1);

$handler = (string)file_get_contents(dirname(__DIR__) . '/handlers/AiMessageHandler.php');
$service = (string)file_get_contents(dirname(__DIR__) . '/services/AiNeedCompletionService.php');
$passed = 0;
$failed = 0;

function aiCompletionCheck(string $name, bool $ok): void
{
    global $passed, $failed;
    if ($ok) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n"; $failed++;
}

aiCompletionCheck('handler uses canonical AI completion boundary', strpos($handler, 'AiNeedCompletionService::applyAndAdvance') !== false);
aiCompletionCheck('final AI branch no longer applies parameters directly', substr_count($handler, 'NeedApplicationService::applyParameters($chat_id, $params)') === 0);
aiCompletionCheck('final AI branch no longer advances directly after AI application', strpos($handler, "NeedProgressionService::advance(\n                    \$chat_id,\n                    ['country_explicit'=>true]") === false);
aiCompletionCheck('completion service owns application', strpos($service, 'NeedApplicationService::applyParameters($chatId, $params)') !== false);
aiCompletionCheck('completion service owns progression', strpos($service, 'NeedProgressionService::advance($chatId, $questionOptions)') !== false);
aiCompletionCheck('completion service returns applied and missing diagnostics', strpos($service, "'applied' => \$applied") !== false && strpos($service, "'missing' =>") !== false);
aiCompletionCheck('handler preserves country explicit progression policy', strpos($handler, "['country_explicit'=>true]") !== false);

echo "\n--------------------------\nTOTAL " . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
