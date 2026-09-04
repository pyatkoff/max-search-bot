<?php

declare(strict_types=1);
require_once __DIR__ . '/../services/DepartureCityValueContract.php';

$passed = 0;
$failed = 0;

function departureCityValueCheck(string $label, $actual, $expected): void
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

$directoryIds = [
    ['Moscow integer', 1, '1'],
    ['Saint Petersburg PDO string', '5', '5'],
    ['Kazan integer', 10, '10'],
    ['Krasnoyarsk PDO string', '12', '12'],
    ['no-flight integer', 99, '99'],
    ['another positive directory integer', 347, '347'],
    ['another positive directory string', '347', '347'],
];
foreach ($directoryIds as [$label, $input, $expected]) {
    departureCityValueCheck("projects {$label}", DepartureCityValueContract::fromDirectoryId($input), $expected);
}

$callbackPayloads = [
    'pick_city_1' => '1',
    'pick_city_5' => '5',
    'pick_city_10' => '10',
    'pick_city_12' => '12',
    'pick_city_99' => '99',
    'pick_city_347' => '347',
];
foreach ($callbackPayloads as $payload => $expected) {
    departureCityValueCheck("parses {$payload}", DepartureCityValueContract::fromCallbackPayload($payload), $expected);
}

$invalidIds = [
    ['zero integer', 0],
    ['zero string', '0'],
    ['negative integer', -1],
    ['negative string', '-1'],
    ['fractional number', 1.5],
    ['fractional string', '1.5'],
    ['empty string', ''],
    ['non-numeric string', 'Moscow'],
    ['leading zero', '01'],
    ['explicit plus sign', '+1'],
    ['surrounding whitespace', ' 1 '],
    ['boolean', true],
    ['null', null],
    ['array', [1]],
];
foreach ($invalidIds as [$label, $input]) {
    departureCityValueCheck("rejects {$label}", DepartureCityValueContract::fromDirectoryId($input), null);
}

$invalidPayloads = [
    'pick_city_other',
    'pick_city_0',
    'pick_city_-1',
    'pick_city_1.5',
    'pick_city_01',
    'pick_city_',
    'city_1',
    'pick_city_1_extra',
    '',
];
foreach ($invalidPayloads as $payload) {
    departureCityValueCheck("rejects payload " . json_encode($payload), DepartureCityValueContract::fromCallbackPayload($payload), null);
}

departureCityValueCheck('keeps no-flight directory id explicit', DepartureCityValueContract::NO_FLIGHT_ID, 99);

$service = (string)file_get_contents(__DIR__ . '/../services/DepartureCityValueContract.php');
$callback = (string)file_get_contents(__DIR__ . '/../actions/callbacks/WizardCallbackAction.php');
$handler = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
$aiContext = (string)file_get_contents(__DIR__ . '/../services/AiSearchContextService.php');

departureCityValueCheck(
    'contract has no runtime mutation dependency',
    strpos($service, 'MaxSearchApi') === false
        && strpos($service, 'saveLastValue') === false
        && strpos($service, 'ExistingWizardStepApplicationService') === false
        && strpos($service, 'NeedApplicationService') === false,
    true
);
departureCityValueCheck(
    'contract is wired once to callback and once to free-text while AI remains disconnected',
    substr_count($callback, 'DepartureCityValueContract::fromCallbackPayload') === 1
        && substr_count($handler, 'DepartureCityValueContract::fromDirectoryId') === 1
        && strpos($aiContext, 'DepartureCityValueContract') === false,
    true
);

echo "\n--------------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
