<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/TripStateService.php';
require_once __DIR__ . '/../services/TripStateMerger.php';
require_once __DIR__ . '/../services/RulesEngine.php';
require_once __DIR__ . '/../services/ShadowDialogueService.php';

$passed = 0;
$failed = 0;

function sdCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

$state = TripStateService::fromLegacyAiContext([
    'city'=>'Москва',
    'country'=>'Турция',
    'adults'=>2,
    'children'=>0,
    'nights'=>'7-10',
    'date'=>'10.09.2026',
],
    static function($name){ return ['ID'=>1]; },
    static function($name){ return ['ID'=>4]; }
);

sdCheck('legacy city resolved', $state['departure']['city_id'], 1);
sdCheck('legacy country resolved', $state['destination']['country_id'], 4);
sdCheck('legacy children zero kept', $state['tourists']['children'], 0);
sdCheck('legacy state search ready', TripStateService::isSearchReady($state), true);

$result = ShadowDialogueService::evaluate(123, 'А давайте Египет и с ребёнком 8 лет', $state, [
    'intent'=>'change_parameters',
    'changes'=>[
        'destination.country'=>'Египет',
        'tourists.children'=>1,
        'tourists.children_ages'=>[8],
    ],
    'confidence'=>['destination.country'=>0.99],
], false);

sdCheck('shadow changes destination', $result['new_state']['destination']['country'], 'Египет');
sdCheck('shadow changes children', $result['new_state']['tourists']['children'], 1);
sdCheck('shadow keeps child age', $result['new_state']['tourists']['children_ages'], [8]);
// No MaxSearchApi is loaded in this deterministic test, so a new country name has no resolved id.
sdCheck('shadow missing destination without directory resolver', in_array('destination', $result['decision']['missing'], true), true);

$result = ShadowDialogueService::evaluate(123, 'Соедините с менеджером', $state, [
    'intent'=>'manager_request','changes'=>[],'confidence'=>[]
], false);
sdCheck('manager intent routes to manager', $result['decision']['action'], RulesEngine::MANAGER);

$result = ShadowDialogueService::evaluate(123, 'Куда можно?', $state, [
    'intent'=>'destination_advice','changes'=>[],'confidence'=>[]
], false);
sdCheck('destination advice routes to options', $result['decision']['action'], RulesEngine::SHOW_OPTIONS);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
