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
aiCompletionCheck('handler routes child ages through resolved completion boundary', strpos($handler, "AiNeedCompletionService::resolveApplyAndAdvance(\n                        \$chat_id,\n                        'child_ages'") !== false);
aiCompletionCheck('handler no longer resolves child ages directly', strpos($handler, "NeedApplicationService::resolveAndApply(\n                        \$chat_id,\n                        'child_ages'") === false);
aiCompletionCheck('handler no longer directly advances child age branch', strpos($handler, "if (!empty(\$ageResult['recognized']) && !empty(\$ageResult['applied'])) {\n                        NeedProgressionService::advance(\$chat_id);") === false);
aiCompletionCheck('completion service resolves field through application boundary', strpos($service, 'NeedApplicationService::resolveAndApply($chatId, $field, $text, $context)') !== false);
aiCompletionCheck('resolved completion advances only after recognized applied value', strpos($service, "if (empty(\$resolution['recognized']) || empty(\$resolution['applied']))") !== false && strpos($service, "'advanced' => false") !== false && strpos($service, "['advanced' => true]") !== false);

echo "\n--------------------------\nTOTAL " . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
