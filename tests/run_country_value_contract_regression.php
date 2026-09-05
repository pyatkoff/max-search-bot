<?php

declare(strict_types=1);
require_once __DIR__ . '/../services/CountryValueContract.php';

$passed = 0;
$failed = 0;

function countryValueCheck(string $label, $actual, $expected): void
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
    ['Egypt integer', 1, '1'],
    ['Thailand PDO string', '2', '2'],
    ['Turkey integer', 4, '4'],
    ['Maldives PDO string', '8', '8'],
    ['UAE integer', 9, '9'],
    ['Sri Lanka PDO string', '12', '12'],
    ['another positive directory integer', 347, '347'],
    ['another positive directory string', '347', '347'],
];
foreach ($directoryIds as [$label, $input, $expected]) {
    countryValueCheck("projects {$label}", CountryValueContract::fromDirectoryId($input), $expected);
}

$callbackPayloads = [
    'pick_country_1' => '1',
    'pick_country_2' => '2',
    'pick_country_4' => '4',
    'pick_country_8' => '8',
    'pick_country_9' => '9',
    'pick_country_12' => '12',
    'pick_country_347' => '347',
];
foreach ($callbackPayloads as $payload => $expected) {
    countryValueCheck("parses {$payload}", CountryValueContract::fromCallbackPayload($payload), $expected);
}

$invalidIds = [
    ['zero integer', 0],
    ['zero string', '0'],
    ['negative integer', -1],
    ['negative string', '-1'],
    ['fractional number', 1.5],
    ['fractional string', '1.5'],
    ['empty string', ''],
    ['non-numeric string', 'Turkey'],
    ['leading zero', '01'],
    ['explicit plus sign', '+1'],
    ['surrounding whitespace', ' 1 '],
    ['boolean', true],
    ['null', null],
    ['array', [1]],
];
foreach ($invalidIds as [$label, $input]) {
    countryValueCheck("rejects {$label}", CountryValueContract::fromDirectoryId($input), null);
}

$invalidPayloads = [
    'pick_country_other',
    'pick_country_0',
    'pick_country_-1',
    'pick_country_1.5',
    'pick_country_01',
    'pick_country_',
    'country_1',
    'pick_country_1_extra',
    'pick_country_1 ',
    '',
];
foreach ($invalidPayloads as $payload) {
    countryValueCheck('rejects payload ' . json_encode($payload), CountryValueContract::fromCallbackPayload($payload), null);
}

$service = (string)file_get_contents(__DIR__ . '/../services/CountryValueContract.php');
$callback = (string)file_get_contents(__DIR__ . '/../actions/callbacks/WizardCallbackAction.php');
$handler = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
$aiContext = (string)file_get_contents(__DIR__ . '/../services/AiSearchContextService.php');

countryValueCheck(
    'contract has no runtime or protected-business dependency',
    strpos($service, 'MaxSearchApi') === false
        && strpos($service, 'saveLastValue') === false
        && strpos($service, 'ExistingWizardStepApplicationService') === false
        && strpos($service, 'NeedApplicationService') === false
        && strpos($service, 'TravelDirectoryRepository') === false
        && strpos($service, 'Tourvisor') === false
        && strpos($service, 'Metrika') === false
        && strpos($service, 'Manager') === false
        && strpos($service, 'Routing') === false,
    true
);
countryValueCheck(
    'contract remains disconnected from callback free-text and AI runtime',
    strpos($callback, 'CountryValueContract') === false
        && strpos($handler, 'CountryValueContract') === false
        && strpos($aiContext, 'CountryValueContract') === false,
    true
);

echo "\n--------------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
