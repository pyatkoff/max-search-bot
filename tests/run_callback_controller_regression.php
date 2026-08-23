<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/CallbackController.php';

$passed = 0;
$failed = 0;
function ccCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

ccCheck('name first + last', CallbackController::userName([
    'from'=>['first_name'=>'Pavel','last_name'=>'Test','username'=>'pt'],
]), 'Pavel Test');
ccCheck('name username fallback', CallbackController::userName([
    'from'=>['username'=>'tourist'],
]), 'tourist');

$families = [
    'ai_start'=>'ai',
    'start_search'=>'wizard',
    'pick_city_1'=>'wizard',
    'pick_country_4'=>'wizard',
    'adults_2'=>'wizard',
    'child_0'=>'wizard',
    'star_5'=>'wizard',
    'meal_3'=>'wizard',
    'nights_7_10'=>'wizard',
    'month_change_09.2026'=>'wizard',
    'back_pick_country'=>'wizard',
    'edit_country'=>'edit',
    'manager_request'=>'manager',
    'manager_after_tours'=>'manager',
    'phone_manual'=>'manager',
    'show_tours'=>'tours',
    'tours_checked'=>'tours',
    'tours_found'=>'tours',
    'finish'=>'tours',
    'restart'=>'restart',
    'back_phone'=>'phone',
    'something_new'=>'unknown',
];
foreach ($families as $payload=>$expected) {
    ccCheck('family '.$payload, CallbackController::family($payload), $expected);
}

ccCheck('wizard owns city', WizardCallbackAction::handles('pick_city_1'), true);
ccCheck('wizard excludes back phone', WizardCallbackAction::handles('back_phone'), false);
ccCheck('edit owns edit date', EditCallbackAction::handles('edit_date'), true);
ccCheck('manager owns manual phone', ManagerCallbackAction::handles('phone_manual'), true);
ccCheck('tours owns finish', ToursCallbackAction::handles('finish_from_ai'), true);
ccCheck('manager excludes tours', ManagerCallbackAction::handles('show_tours'), false);

$controller = new CallbackController();
ccCheck('empty callback rejected', $controller->handle(['from'=>['id'=>1],'data'=>'']), false);
ccCheck('missing chat rejected', $controller->handle(['from'=>[],'data'=>'ai_start']), false);
ccCheck('unknown callback rejected', $controller->handle(['from'=>['id'=>1],'data'=>'something_new']), false);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
