<?php

declare(strict_types=1);

class MaxSearchApi
{
    public static array $applyCalls = [];

    public static function applyAiParameters($chatId, array $params): array
    {
        self::$applyCalls[] = ['chat_id'=>$chatId, 'params'=>$params];
        return $params;
    }
}

require_once __DIR__ . '/../services/NeedApplicationService.php';

$passed = 0;
$failed = 0;
function nasCheck(string $label, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "PASS  {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL  {$label}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

MaxSearchApi::$applyCalls = [];
$adult = NeedApplicationService::resolveAndApply(42, 'adults', 'Двое');
nasCheck('recognized deterministic value is applied', $adult['applied'], true);
nasCheck('canonical resolved value is returned', $adult['value'], 2);
nasCheck('resolver source survives application boundary', $adult['source'], 'deterministic:adults_parser');
nasCheck('single-field application occurs once', count(MaxSearchApi::$applyCalls), 1);
nasCheck('single-field application preserves chat and params', MaxSearchApi::$applyCalls[0], ['chat_id'=>42,'params'=>['adults'=>2]]);

MaxSearchApi::$applyCalls = [];
$unknown = NeedApplicationService::resolveAndApply(42, 'nights', 'от 7');
nasCheck('unrecognized value is not applied', $unknown['applied'], false);
nasCheck('unrecognized value causes zero mutations', count(MaxSearchApi::$applyCalls), 0);

MaxSearchApi::$applyCalls = [];
$multi = NeedApplicationService::applyParameters(77, ['children'=>1,'child_ages'=>[6]]);
nasCheck('multi-field clarification remains supported', $multi, ['children'=>1,'child_ages'=>[6]]);
nasCheck('multi-field clarification is one atomic application call', MaxSearchApi::$applyCalls[0], ['chat_id'=>77,'params'=>['children'=>1,'child_ages'=>[6]]]);

$handlerSource = (string)file_get_contents(__DIR__ . '/../handlers/AiShortAnswerHandler.php');
nasCheck('short-answer handler uses application service', strpos($handlerSource, 'NeedApplicationService::resolveAndApply') !== false, true);
nasCheck('specialized multi-field children path uses application service', strpos($handlerSource, 'NeedApplicationService::applyParameters') !== false, true);
nasCheck('short-answer handler no longer mutates through MaxSearchApi directly', strpos($handlerSource, 'MaxSearchApi::applyAiParameters') === false, true);

$aiMessageSource = (string)file_get_contents(__DIR__ . '/../handlers/AiMessageHandler.php');
$completionSource = (string)file_get_contents(__DIR__ . '/../services/AiNeedCompletionService.php');
nasCheck('AI message final parameter application uses completion boundary', strpos($aiMessageSource, 'AiNeedCompletionService::applyAndAdvance') !== false, true);
nasCheck('AI completion boundary applies through application service', strpos($completionSource, 'NeedApplicationService::applyParameters($chatId, $params)') !== false, true);
nasCheck('AI message local parameter application uses application service', strpos($aiMessageSource, '$appliedLocal = NeedApplicationService::applyParameters($chat_id, $localParams);') !== false, true);
nasCheck('AI completion boundary advances through progression service', strpos($completionSource, 'NeedProgressionService::advance($chatId, $questionOptions)') !== false, true);
nasCheck('AI message no longer applies parameters through MaxSearchApi directly', strpos($aiMessageSource, 'MaxSearchApi::applyAiParameters') === false, true);

$resolverPaths = [
    'departure city' => __DIR__ . '/../services/DepartureCityResolver.php',
    'destination' => __DIR__ . '/../services/DestinationResolver.php',
    'destination area' => __DIR__ . '/../services/DestinationAreaResolver.php',
];
foreach ($resolverPaths as $label => $path) {
    $resolverSource = (string)file_get_contents($path);
    nasCheck("{$label} resolver applies through canonical boundary", strpos($resolverSource, 'NeedApplicationService::applyParameters') !== false, true);
    nasCheck("{$label} resolver has no direct application bypass", strpos($resolverSource, 'MaxSearchApi::applyAiParameters') === false, true);
}

$inventory = json_decode((string)file_get_contents(__DIR__ . '/../docs/dialogue-mutation-inventory.json'), true);
$directApplicationCallers = array_values(array_filter(
    $inventory['callers'] ?? [],
    static fn(array $caller): bool => ($caller['method'] ?? '') === 'applyAiParameters'
));
nasCheck('mutation inventory keeps one canonical direct application caller', $directApplicationCallers, [[
    'path'=>'services/NeedApplicationService.php',
    'receiver'=>'MaxSearchApi',
    'method'=>'applyAiParameters',
    'occurrences'=>1,
    'classification'=>'trip_value',
    'rationale'=>'Canonical application boundary applies normalized trip values.',
]]);

echo "\n--------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
