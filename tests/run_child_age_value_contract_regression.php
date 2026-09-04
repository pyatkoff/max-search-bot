<?php

declare(strict_types=1);
require_once __DIR__ . '/../services/ChildAgeValueContract.php';

$passed = 0;
$failed = 0;

function childAgeValueCheck(string $label, $actual, $expected): void
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

$accepted = [
    ['single age', '6', 1, [6]],
    ['zero age', '0', 1, [0]],
    ['space separator', '3 7', 2, [3, 7]],
    ['comma separator', '3,7', 2, [3, 7]],
    ['comma-space separator', '3, 7', 2, [3, 7]],
    ['legacy mixed prefix before comma', '3 4, 7', 2, [3, 7]],
    ['legacy mixed suffix after comma', '3, 7 8', 2, [3, 7]],
];
foreach ($accepted as [$label, $input, $count, $ages]) {
    childAgeValueCheck("accepts {$label}", ChildAgeValueContract::parseLegacyInput($input, $count), $ages);
}

$rejected = [
    ['word separator remains rejected', '3 и 7', 2],
    ['semicolon remains rejected', '3;7', 2],
    ['slash remains rejected', '3/7', 2],
    ['dash remains rejected', '3-7', 2],
    ['tab is not a legacy split point', "3\t7", 2],
    ['double space adds an empty legacy token', '3  7', 2],
    ['wrong count', '3', 2],
    ['out of range', '3, 18', 2],
    ['letters remain rejected', 'три, семь', 2],
];
foreach ($rejected as [$label, $input, $count]) {
    childAgeValueCheck($label, ChildAgeValueContract::parseLegacyInput($input, $count), null);
}

childAgeValueCheck('projects one age exactly', ChildAgeValueContract::toStorage([6], 1), '6');
childAgeValueCheck('projects integer array with comma-space', ChildAgeValueContract::toStorage([3, 7], 2), '3, 7');
childAgeValueCheck('projects zero age exactly', ChildAgeValueContract::toStorage([0], 1), '0');
childAgeValueCheck('rejects projection count mismatch', ChildAgeValueContract::toStorage([3], 2), null);
childAgeValueCheck('rejects projection without children', ChildAgeValueContract::toStorage([], 0), null);
childAgeValueCheck('rejects non-integer resolver value', ChildAgeValueContract::toStorage(['3'], 1), null);
childAgeValueCheck('rejects out-of-range resolver value', ChildAgeValueContract::toStorage([18], 1), null);

$service = (string)file_get_contents(__DIR__ . '/../services/ChildAgeValueContract.php');
$handler = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
childAgeValueCheck('contract has no runtime mutation dependency', strpos($service, 'MaxSearchApi') === false && strpos($service, 'saveLastValue') === false && strpos($service, 'ExistingWizardStepApplicationService') === false, true);
childAgeValueCheck('runtime uses only the executable parser and projector', substr_count($handler, 'ChildAgeValueContract::parseLegacyInput') === 1 && substr_count($handler, 'ChildAgeValueContract::toStorage') === 1, true);

echo "\n--------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
