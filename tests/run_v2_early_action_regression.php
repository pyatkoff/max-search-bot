<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/RulesEngine.php';
require_once __DIR__ . '/../services/V2FeatureGate.php';
require_once __DIR__ . '/../handlers/V2EarlyActionHandler.php';

$passed = 0;
$failed = 0;

function v2aCheck(string $name, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "PASS  {$name}\n";
        $passed++;
        return;
    }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

$managerShadow = [
    'decision'=>['action'=>RulesEngine::MANAGER],
    'extracted'=>['intent'=>'manager_request'],
];
$adviceShadow = [
    'decision'=>['action'=>RulesEngine::SHOW_OPTIONS],
    'extracted'=>['intent'=>'destination_advice'],
];

V2FeatureGate::resetForTests(['ai_v2'=>[
    'shadow'=>true,
    'manager_request'=>true,
    'destination_advice'=>false,
]]);

v2aCheck(
    'explicit manager request is promoted',
    V2EarlyActionHandler::interceptAction($managerShadow, 'Соедините меня с менеджером'),
    RulesEngine::MANAGER
);
v2aCheck(
    'manager shadow without explicit human wording falls through',
    V2EarlyActionHandler::interceptAction($managerShadow, 'Хочу хороший семейный отель'),
    null
);
v2aCheck(
    'destination advice remains shadow while feature disabled',
    V2EarlyActionHandler::interceptAction($adviceShadow, 'Куда можно из Калининграда?'),
    null
);

V2FeatureGate::resetForTests(['ai_v2'=>[
    'shadow'=>true,
    'manager_request'=>true,
    'destination_advice'=>true,
]]);
v2aCheck(
    'destination advice can be promoted independently',
    V2EarlyActionHandler::interceptAction($adviceShadow, 'Куда можно из Калининграда?'),
    RulesEngine::SHOW_OPTIONS
);

V2FeatureGate::resetForTests(['ai_v2'=>[
    'shadow'=>true,
    'manager_request'=>false,
    'destination_advice'=>true,
]]);
v2aCheck(
    'manager can be rolled back independently',
    V2EarlyActionHandler::interceptAction($managerShadow, 'Позовите менеджера'),
    null
);

v2aCheck('explicit operator wording detected', V2EarlyActionHandler::isExplicitManagerRequest('Можно оператора?'), true);
v2aCheck('ordinary tour request not treated as manager request', V2EarlyActionHandler::isExplicitManagerRequest('Турция на 7 ночей'), false);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
