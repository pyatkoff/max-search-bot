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
tsCheck('date month normalized', $state['dates']['month'], '09-2026');
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

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
