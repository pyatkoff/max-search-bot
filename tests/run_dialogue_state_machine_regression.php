<?php

declare(strict_types=1);

if (!class_exists('MaxSearchApi')) {
    class MaxSearchApi {
        public static $statusStart = 64;
        public static $statusCityChoose = 65;
        public static $statusContryChoose = 66;
        public static $statusAdults = 67;
        public static $statusChild = 68;
        public static $statusAge = 69;
        public static $statusStars = 70;
        public static $statusMeal = 71;
        public static $statusNights = 72;
        public static $statusDate = 73;
        public static $statusCheck = 74;
        public static $statusPhone = 75;
        public static $statusAi = 76;
    }
}

require_once __DIR__ . '/../services/DialogueStateMachine.php';

$passed = 0;
$failed = 0;
function dsmCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) {
        echo "PASS  {$name}\n";
        $passed++;
        return;
    }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

$map = DialogueStateMachine::statusMap();
dsmCheck('canonical status map includes start', $map['start'] ?? null, 64);
dsmCheck('canonical status map includes date', $map['date'] ?? null, 73);
dsmCheck('status resolves to canonical state', DialogueStateMachine::stateForStatus(71), 'meal');
dsmCheck('unknown status stays unknown', DialogueStateMachine::stateForStatus(999), null);
dsmCheck('canonical state resolves to status', DialogueStateMachine::statusForState('children'), 68);
dsmCheck('unknown state stays unknown', DialogueStateMachine::statusForState('bogus'), null);

dsmCheck('start can enter classic wizard', DialogueStateMachine::canTransition('start', 'city'), true);
dsmCheck('start can enter AI flow', DialogueStateMachine::canTransition('start', 'ai'), true);
dsmCheck('city forwards to country', DialogueStateMachine::canTransition('city', 'country'), true);
dsmCheck('country cannot skip to meal', DialogueStateMachine::canTransition('country', 'meal'), false);
dsmCheck('children can ask ages', DialogueStateMachine::canTransition('children', 'child_ages'), true);
dsmCheck('children can skip ages when no children', DialogueStateMachine::canTransition('children', 'stars'), true);
dsmCheck('date completes to check', DialogueStateMachine::canTransition('date', 'check'), true);
dsmCheck('back country to city allowed', DialogueStateMachine::canTransition('country', 'city', 'back'), true);
dsmCheck('invalid back jump rejected', DialogueStateMachine::canTransition('meal', 'city', 'back'), false);
dsmCheck('check can open meal edit', DialogueStateMachine::canTransition('check', 'meal', 'edit'), true);
dsmCheck('edited meal can return to check', DialogueStateMachine::canTransition('meal', 'check', 'edit'), true);
dsmCheck('edit cannot jump meal to city', DialogueStateMachine::canTransition('meal', 'city', 'edit'), false);
dsmCheck('reset can return any state to start', DialogueStateMachine::canTransition('date', 'start', 'reset'), true);
dsmCheck('AI can ask any missing wizard field', DialogueStateMachine::canTransition('ai', 'nights'), true);
dsmCheck('AI can finish directly to check', DialogueStateMachine::canTransition('ai', 'check'), true);

dsmCheck('city callback expects city state', DialogueStateMachine::expectedStateForForwardCallback('pick_city_1'), 'city');
dsmCheck('meal callback expects meal state', DialogueStateMachine::expectedStateForForwardCallback('meal_7'), 'meal');
dsmCheck('date callback remains dedicated', DialogueStateMachine::expectedStateForForwardCallback('pick_date_05.09.2026'), null);
dsmCheck('back callback is not forward-owned', DialogueStateMachine::expectedStateForForwardCallback('back_nights'), null);
dsmCheck('meal callback status comes from canonical state map', DialogueStateMachine::expectedStatusForForwardCallback('meal_7'), 71);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
