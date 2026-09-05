<?php

declare(strict_types=1);
require_once __DIR__ . '/../services/DateValueContract.php';

$passed = 0;
$failed = 0;

function dateValueCheck(string $label, $actual, $expected): void
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

$validDates = [
    ['present example', '05.09.2026'],
    ['past example', '03.09.2026'],
    ['future example', '31.12.2030'],
    ['valid leap day', '29.02.2028'],
    ['first calendar day', '01.01.2026'],
];
foreach ($validDates as [$label, $date]) {
    dateValueCheck("projects {$label}", DateValueContract::fromStorageValue($date), $date);
    dateValueCheck("parses {$label} callback", DateValueContract::fromCallbackPayload('pick_date_' . $date), $date);
}

$invalidDates = [
    ['invalid day', '32.01.2026'],
    ['invalid month', '01.13.2026'],
    ['invalid non-leap day', '29.02.2027'],
    ['zero day', '00.09.2026'],
    ['zero month', '05.00.2026'],
    ['missing day leading zero', '5.09.2026'],
    ['missing month leading zero', '05.9.2026'],
    ['two-digit year', '05.09.26'],
    ['ISO form', '2026-09-05'],
    ['surrounding whitespace', ' 05.09.2026 '],
    ['time suffix', '05.09.2026 10:00:00'],
    ['extra suffix', '05.09.2026_extra'],
    ['empty string', ''],
    ['integer', 20260905],
    ['boolean', true],
    ['null', null],
    ['array', ['05.09.2026']],
];
foreach ($invalidDates as [$label, $value]) {
    dateValueCheck("rejects {$label}", DateValueContract::fromStorageValue($value), null);
}

$invalidPayloads = [
    'pick_date_32.01.2026',
    'pick_date_29.02.2027',
    'pick_date_5.09.2026',
    'pick_date_05.9.2026',
    'pick_date_2026-09-05',
    'pick_date_05.09.2026 10:00:00',
    'pick_date_05.09.2026_extra',
    'pick_date_05.09.2026 ',
    ' pick_date_05.09.2026',
    'pick_date_',
    'date_05.09.2026',
    '',
    true,
    null,
    ['pick_date_05.09.2026'],
];
foreach ($invalidPayloads as $payload) {
    dateValueCheck('rejects payload ' . json_encode($payload), DateValueContract::fromCallbackPayload($payload), null);
}

$service = (string)file_get_contents(__DIR__ . '/../services/DateValueContract.php');
$callback = (string)file_get_contents(__DIR__ . '/../actions/callbacks/WizardCallbackAction.php');
$handler = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
$aiPolicy = (string)file_get_contents(__DIR__ . '/../services/AiDateContextService.php');
$application = (string)file_get_contents(__DIR__ . '/../services/NeedApplicationService.php');
$nativeDate = (string)file_get_contents(__DIR__ . '/../services/NativeDateService.php');

dateValueCheck(
    'contract has no runtime parser calendar search or protected-business dependency',
    strpos($service, 'MaxSearchApi') === false
        && strpos($service, 'DateParser') === false
        && strpos($service, 'NativeDateService') === false
        && strpos($service, 'CalendarViewModel') === false
        && strpos($service, 'NeedApplicationService') === false
        && strpos($service, 'TourSearchHandoffService') === false
        && strpos($service, 'Metrika') === false
        && strpos($service, 'Manager') === false
        && strpos($service, 'Routing') === false,
    true
);
dateValueCheck(
    'contract is connected only at the date callback and wizard free-text boundaries',
    substr_count($callback, 'DateValueContract::fromCallbackPayload($q)') === 1
        && substr_count($handler, 'DateValueContract::fromStorageValue($date)') === 1
        && strpos($aiPolicy, 'DateValueContract') === false
        && strpos($application, 'DateValueContract') === false
        && strpos($nativeDate, 'DateValueContract') === false,
    true
);

echo "\n--------------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
