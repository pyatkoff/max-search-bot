<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/TripStateService.php';
require_once __DIR__ . '/../services/TripStateMerger.php';
require_once __DIR__ . '/../services/RulesEngine.php';
require_once __DIR__ . '/../services/ShadowDialogueService.php';
require_once __DIR__ . '/../services/ManagerSummaryService.php';

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
    'preferences'=>['тихий отель'],
    'negative_preferences'=>['шумный отель'],
],
    static function($name){ return ['ID'=>1]; },
    static function($name){ return ['ID'=>4]; }
);

sdCheck('legacy city resolved', $state['departure']['city_id'], 1);
sdCheck('legacy country resolved', $state['destination']['country_id'], 4);
sdCheck('legacy children zero kept', $state['tourists']['children'], 0);
sdCheck('legacy active wishes reach trip state', $state['preferences'], ['тихий отель']);
sdCheck('legacy active exclusions reach trip state', $state['negative_preferences'], ['шумный отель']);
sdCheck('legacy state search ready', TripStateService::isSearchReady($state), true);
$summary = ManagerSummaryService::build($state);
sdCheck('manager summary labels wishes separately', str_contains($summary, 'Пожелания: тихий отель'), true);
sdCheck('manager summary labels exclusions separately', str_contains($summary, 'Не подходит: шумный отель'), true);
sdCheck('manager brief separates optional unknown hotel refinements', str_contains($summary, 'Не указано (необязательно): категория отеля, питание.'), true);
sdCheck('optional unknown refinements do not become required search fields', str_contains($summary, 'Не указано для поиска: категория отеля'), false);
$anyStars = $state;
$anyStars['hotel']['stars_min'] = 1;
$anyStars['hotel']['meal'] = 'any';
$anyStarsSummary = ManagerSummaryService::build($anyStars);
sdCheck('manager brief preserves explicit no-star-preference semantics', str_contains($anyStarsSummary, 'Категория отеля: не важна'), true);
sdCheck('manager brief preserves explicit any-meal semantics', str_contains($anyStarsSummary, 'Питание: Любое'), true);
sdCheck('explicit any refinements suppress optional unknown marker', str_contains($anyStarsSummary, 'Не указано (необязательно): категория отеля, питание.'), false);
sdCheck('manager brief does not turn no-star-preference sentinel into a 1-star wish', str_contains($anyStarsSummary, 'Отель: от 1★'), false);
$fourStars = $state;
$fourStars['hotel']['stars_min'] = 4;
sdCheck('manager brief preserves real minimum-star preference', str_contains(ManagerSummaryService::build($fourStars), 'Отель: от 4★'), true);

$status = [
    'city'=>65,
    'country'=>66,
    'adults'=>67,
    'children'=>68,
    'child_ages'=>69,
    'stars'=>70,
    'meal'=>71,
    'nights'=>72,
    'date'=>73,
];
$zeroChildrenWithStoredAges = TripStateService::fromSaved([
    65=>'1',
    66=>'4',
    67=>'2',
    68=>'0',
    69=>'8, 11',
    72=>'7',
    73=>'10.09.2026',
], $status,
    static function($id){ return (int)$id === 1 ? 'Москва' : false; },
    static function($id){ return (int)$id === 4 ? 'Турция' : false; }
);
sdCheck('zero children suppress preserved stored ages in trip projection', $zeroChildrenWithStoredAges['tourists']['children_ages'], []);
sdCheck('zero children manager brief does not revive preserved ages', str_contains(ManagerSummaryService::build($zeroChildrenWithStoredAges), 'возраст:'), false);
sdCheck('zero children legacy AI projection does not revive preserved ages', array_key_exists('child_ages', TripStateService::toLegacyAiContext($zeroChildrenWithStoredAges)), false);

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
sdCheck('unrelated change preserves earlier wish', $result['new_state']['preferences'], ['тихий отель']);
// No MaxSearchApi is loaded in this deterministic test, so a new country name has no resolved id.
sdCheck('shadow missing destination without directory resolver', in_array('destination', $result['decision']['missing'], true), true);

$withNewWish = ShadowDialogueService::evaluate(123, 'И ещё первая линия', $state, [
    'intent'=>'change_parameters',
    'changes'=>['preferences'=>['первая линия']],
    'confidence'=>['preferences'=>0.98],
], false);
sdCheck('new extracted wish appends instead of replacing', $withNewWish['new_state']['preferences'], ['тихий отель','первая линия']);
$withDuplicate = ShadowDialogueService::evaluate(123, 'Тихий отель тоже важен', $withNewWish['new_state'], [
    'intent'=>'change_parameters',
    'changes'=>['preferences'=>['тихий отель']],
    'confidence'=>['preferences'=>0.99],
], false);
sdCheck('duplicate wish stays deduplicated', $withDuplicate['new_state']['preferences'], ['тихий отель','первая линия']);

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