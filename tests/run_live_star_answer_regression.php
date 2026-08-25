<?php

declare(strict_types=1);
require_once __DIR__ . '/../handlers/AiShortAnswerHandler.php';

$cases = [
    ['live phrase 3,4', '3,4', 3],
    ['spaced comma list', '3, 4', 3],
    ['range-like answer', '4-5', 4],
    ['slash list', '3/5', 3],
    ['single star preserved', '4', 4],
    ['not important preserved', 'не важно', 1],
    ['invalid category rejected', '3,6', null],
    ['unrelated number list rejected', '3,4,10', null],
];

$failed = 0;
foreach ($cases as [$name, $input, $expected]) {
    $actual = AiShortAnswerHandler::starMinimumFromShortText($input);
    if ($actual === $expected) {
        echo "PASS  {$name}\n";
    } else {
        echo "FAIL  {$name}: expected ".var_export($expected,true).", got ".var_export($actual,true)."\n";
        $failed++;
    }
}

exit($failed ? 1 : 0);
