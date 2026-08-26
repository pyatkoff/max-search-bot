<?php

declare(strict_types=1);
require_once __DIR__ . '/../handlers/AiShortAnswerHandler.php';

$cases = [
    ['live phrase', '14 лет один', ['children'=>1,'child_ages'=>[14]]],
    ['numeric one child', '14 лет 1', ['children'=>1,'child_ages'=>[14]]],
    ['explicit child noun', '9 лет один ребенок', ['children'=>1,'child_ages'=>[9]]],
    ['comma variant', '7 лет, один ребёнок', ['children'=>1,'child_ages'=>[7]]],
    ['adult age rejected', '18 лет один', null],
    ['ambiguous age only rejected', '14 лет', null],
    ['ambiguous count first rejected', 'один 14 лет', null],
    ['multiple children not guessed', '14 лет двое', null],
];

$failed = 0;
foreach ($cases as [$name, $input, $expected]) {
    $actual = AiShortAnswerHandler::childAgeCountClarificationWhileAskingChildren($input);
    if ($actual === $expected) {
        echo "PASS  {$name}\n";
        continue;
    }
    echo "FAIL  {$name}: expected ".var_export($expected,true).", got ".var_export($actual,true)."\n";
    $failed++;
}

exit($failed ? 1 : 0);
