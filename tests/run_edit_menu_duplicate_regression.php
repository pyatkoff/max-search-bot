<?php

declare(strict_types=1);
require_once __DIR__ . '/../actions/callbacks/EditCallbackAction.php';

$cases = [
    ['first callback is allowed', 0.0, 100.0, false],
    ['immediate duplicate is suppressed', 100.0, 100.0, true],
    ['one-second duplicate is suppressed', 100.0, 101.0, true],
    ['two-second later callback is allowed', 100.0, 102.0, false],
    ['older timestamp does not suppress', 101.0, 100.0, false],
];

$failed = 0;
foreach ($cases as [$name, $previousAt, $now, $expected]) {
    $actual = EditCallbackAction::isDuplicateEditMenu($previousAt, $now);
    if ($actual === $expected) {
        echo "PASS  {$name}\n";
    } else {
        echo "FAIL  {$name}: expected ".var_export($expected, true).", got ".var_export($actual, true)."\n";
        $failed++;
    }
}

exit($failed ? 1 : 0);
