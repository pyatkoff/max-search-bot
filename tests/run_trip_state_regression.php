<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/TripStateService.php';
require_once __DIR__ . '/../services/TripStateMerger.php';
require_once __DIR__ . '/../services/RulesEngine.php';

$passed = 0;
$failed = 0;

function tsCheck(string $name, $actual, $expected): void
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

echo "TripState regression suite\n";
echo "==========================\n\n";

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

$saved = [
    65=>17,
    66=>4,
    67=>2,
    68=>0,
    70=>4,
    71=>'7',
    72=>'7-10',
    73=>'10.09.2026',
];

$state = TripStateService::fromSaved(
    $saved,
    $status,
    static function ($id) { return (int)$id === 17 ? 'Калининград' : false; },
    static function ($id) { return (int)$id === 4 ? 'Турция' : false; }
);

tsCheck('departure city id', $state['departure']['city_id'], 17);
tsCheck('departure city name', $state['departure']['city'], 'Калининград');
tsCheck('destination country', $state['destination']['country'], 'Турция');
tsCheck('children zero remains known', $state['tourists']['children'], 0);
tsCheck('children ages empty for zero children', $state['tourists']['children_ages'], []);
tsCheck('night range min', $state['nights']['min'], 7);
tsCheck('night range max', $state['nights']['max'], 10);
tsCheck('date month normalized', $state['dates']['month'], '2026-09');
tsCheck('meal converted from storage', $state['hotel']['meal'], 'all_inclusive');
tsCheck('search is ready without stars/meal requirement', TripStateService::isSearchReady($state), true);
tsCheck('search missing is empty', TripStateService::searchMissing($state), []);

$legacy = TripStateService::toLegacyAiContext($state);
tsCheck('legacy context city', $legacy['city'] ?? null, 'Калининград');
tsCheck('legacy context children zero', $legacy['children'] ?? null, 0);
tsCheck('legacy context nights range', $legacy['nights'] ?? null, '7-10');

$noAges = $state;
$noAges['tourists']['children'] = 2;
$noAges['tourists']['children_ages'] = [6];
tsCheck('missing child ages detected', TripStateService::searchMissing($noAges), ['children_ages']);

$merged = TripStateMerger::merge($state, [
    'country'=>'Египет',
    'tourists.children'=>1,
    'child_ages'=>[8],
    'budget.max'=>180000,
    'preferences'=>['детский клуб','первая линия','детский клуб'],
    'unsupported.field'=>'ignore me',
]);
tsCheck('merger updates destination alias', $merged['destination']['country'], 'Египет');
tsCheck('merger updates children', $merged['tourists']['children'], 1);
tsCheck('merger updates child ages alias', $merged['tourists']['children_ages'], [8]);
tsCheck('merger stores budget', $merged['budget']['max'], 180000);
tsCheck('merger deduplicates preferences', $merged['preferences'], ['детский клуб','первая линия']);
tsCheck('merger ignores unknown path', isset($merged['unsupported']), false);

$decision = RulesEngine::decide('tour_search', $state);
tsCheck('ready search action', $decision['action'], RulesEngine::OPEN_SEARCH);

$incomplete = $state;
$incomplete['departure']['city_id'] = null;
$incomplete['departure']['city'] = null;
$decision = RulesEngine::decide('tour_search', $incomplete);
tsCheck('missing departure leads to ASK', $decision['action'], RulesEngine::ASK);
tsCheck('departure is next field', $decision['next_field'], 'departure_city');

tsCheck('question for departure deterministic', RulesEngine::questionFor('departure_city'), 'Из какого города планируете вылет?');

$advice = $state;
$advice['destination']['country_id'] = null;
$advice['destination']['country'] = null;
$decision = RulesEngine::decide('destination_advice', $advice);
tsCheck('destination advice does not require country', $decision['action'], RulesEngine::SHOW_OPTIONS);

$decision = RulesEngine::decide('manager_request', $state);
tsCheck('explicit manager request', $decision['action'], RulesEngine::MANAGER);
$decision = RulesEngine::decide('hot_tours', $state);
tsCheck('hot tours goes to channel', $decision['action'], RulesEngine::CHANNEL);
$decision = RulesEngine::decide('stop', $state);
tsCheck('stop intent stops', $decision['action'], RulesEngine::STOP);

// Owner rule #780: budgets are for the whole party unless explicitly qualified.
// These are synthetic model/summary cases, not a production dialogue sample.
require_once __DIR__ . '/../services/ManagerSummaryService.php';
$budgetBase = $state;
$budgetBase['tourists'] = ['adults'=>2, 'children'=>1, 'children_ages'=>[7]];
tsCheck(
    'missing budget is explicitly optional in manager summary',
    strpos(ManagerSummaryService::build($budgetBase), 'Не указано: бюджет (необязательно; уточнять только если нужен до первого предложения)') !== false,
    true
);
$totalBudget = TripStateMerger::merge($budgetBase, ['budget.max'=>250000]);
tsCheck('budget defaults to whole party', $totalBudget['budget']['basis'] ?? null, 'total');
tsCheck('budget default has product provenance', $totalBudget['budget']['basis_source'] ?? null, 'product_default');
tsCheck('budget amount is not multiplied by tourists', $totalBudget['budget']['max'], 250000);
tsCheck('whole party basis appears in manager summary', strpos(ManagerSummaryService::build($totalBudget), 'Бюджет: до 250 000 RUB на всех') !== false, true);
tsCheck('known budget suppresses unknown marker', strpos(ManagerSummaryService::build($totalBudget), 'Не указано: бюджет') === false, true);
$morePeople = TripStateMerger::merge($totalBudget, ['tourists.adults'=>3]);
tsCheck('changing party keeps total budget', $morePeople['budget'], $totalBudget['budget']);
$personBudget = TripStateMerger::merge($budgetBase, ['budget.max'=>90000, 'budget.basis'=>'per_person']);
tsCheck('explicit per-person basis is retained', $personBudget['budget']['basis'] ?? null, 'per_person');
tsCheck('extracted basis is not labelled product default', $personBudget['budget']['basis_source'] ?? null, 'extracted');
tsCheck('per-person summary never invents a party total', strpos(ManagerSummaryService::build($personBudget), 'Бюджет: до 90 000 RUB на человека') !== false, true);
$corrected = TripStateMerger::merge($personBudget, ['budget.max'=>95000]);
tsCheck('amount-only correction retains prior explicit basis', $corrected['budget']['basis'] ?? null, 'per_person');
$switched = TripStateMerger::merge($corrected, ['budget.basis'=>'total']);
tsCheck('explicit total overrides previous personal basis', $switched['budget']['basis'] ?? null, 'total');
tsCheck('basis-only correction does not change the amount', $switched['budget']['max'], 95000);
$aliasBudget = TripStateMerger::merge($budgetBase, ['budget'=>250000]);
tsCheck('budget alias uses the same policy', $aliasBudget['budget'], $totalBudget['budget']);
$invalidBasis = TripStateMerger::merge($totalBudget, ['budget.max'=>1, 'budget.basis'=>'per_room', 'hotel.meal'=>'breakfast']);
tsCheck('invalid basis rejects the entire budget update', $invalidBasis['budget'], $totalBudget['budget']);
tsCheck('invalid budget does not discard other trip changes', $invalidBasis['hotel']['meal'], 'breakfast');
foreach ([0, -1, true, false, '', '250 тысяч', '1e6', INF, NAN, [], new stdClass()] as $index=>$bad) {
    $rejectedBudget = TripStateMerger::merge($totalBudget, ['budget.max'=>$bad]);
    tsCheck('invalid budget value preserves known budget '.$index, $rejectedBudget['budget'], $totalBudget['budget']);
}
$numeric = TripStateMerger::merge($budgetBase, ['budget.max'=>'250000']);
tsCheck('canonical numeric budget is normalized', $numeric['budget']['max'], 250000);
$decimal = TripStateMerger::merge($budgetBase, ['budget.max'=>'250000.50']);
tsCheck('fractional budget is not truncated', $decimal['budget']['max'], 250000.5);
tsCheck('summary does not round a fractional ceiling up or down', strpos(ManagerSummaryService::build($decimal), '250 000.50 RUB на всех') !== false, true);
$badCurrency = TripStateMerger::merge($totalBudget, ['budget.currency'=>'<bad>', 'budget.max'=>1]);
tsCheck('invalid currency rejects the entire budget update', $badCurrency['budget'], $totalBudget['budget']);
$eur = TripStateMerger::merge($totalBudget, ['budget.currency'=>'EUR']);
tsCheck('explicit currency is preserved without conversion', $eur['budget']['currency'], 'EUR');
tsCheck('changing currency does not change amount', $eur['budget']['max'], 250000);
$cleared = TripStateMerger::merge($personBudget, ['budget.max'=>null]);
tsCheck('explicit budget clear removes amount', $cleared['budget']['max'], null);
tsCheck('clear removes previous personal basis', array_key_exists('basis', $cleared['budget']), false);
$freshBudget = TripStateMerger::merge($cleared, ['budget.max'=>250000]);
tsCheck('new budget after clear defaults to whole party', $freshBudget['budget']['basis'] ?? null, 'total');
tsCheck('missing budget does not add an obligatory question', TripStateService::searchMissing($budgetBase), []);
tsCheck('budget is not a manager handoff gate', RulesEngine::decide('manager_request', $budgetBase)['action'], RulesEngine::MANAGER);
tsCheck('search remains available without budget', RulesEngine::decide('tour_search', $budgetBase)['action'], RulesEngine::OPEN_SEARCH);
tsCheck('no budget is invented for new trip', TripStateMerger::merge($budgetBase, [])['budget']['max'], null);
$unqualified = $budgetBase;
$unqualified['budget'] = ['max'=>250000, 'currency'=>'RUB'];
tsCheck('old unqualified budget displays the product default', strpos(ManagerSummaryService::build($unqualified), '250 000 RUB на всех') !== false, true);
$unknownBasis = $totalBudget;
$unknownBasis['budget']['basis'] = 'per_room';
tsCheck('unsupported stored basis is not labelled whole party', strpos(ManagerSummaryService::build($unknownBasis), 'основание требует уточнения') !== false, true);
tsCheck('summary does not mutate its source state', $totalBudget['budget']['basis_source'] ?? null, 'product_default');
$forgedSource = TripStateMerger::merge($budgetBase, ['budget.max'=>250000, 'budget.basis_source'=>'explicit_customer_confirmation']);
tsCheck('extractor cannot forge provenance through a dot path', $forgedSource['budget']['basis_source'] ?? null, 'product_default');

// Exercise the existing extractor allowlist without calling an external model.
require_once __DIR__ . '/../ai/TouristExtractorV2.php';
$filterBudget = new ReflectionMethod(TouristExtractorV2::class, 'filterChanges');
$filterBudget->setAccessible(true);
$filteredBudget = $filterBudget->invoke(null, ['budget.max'=>90000, 'budget.basis'=>'per_person', 'budget.basis_source'=>'customer_confirmed']);
tsCheck('extractor allowlist retains budget qualifier', $filteredBudget['budget.basis'] ?? null, 'per_person');
tsCheck('extractor allowlist rejects fabricated source', array_key_exists('budget.basis_source', $filteredBudget), false);
$filteredState = TripStateMerger::merge($budgetBase, $filteredBudget);
tsCheck('extractor projection reaches the matching manager label', strpos(ManagerSummaryService::build($filteredState), '90 000 RUB на человека') !== false, true);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
