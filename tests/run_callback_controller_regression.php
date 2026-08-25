<?php

declare(strict_types=1);

if (!class_exists('MaxSearchApi')) {
    class MaxSearchApi {
        public static $statusCityChoose = 11;
        public static $statusContryChoose = 12;
        public static $statusAdults = 13;
        public static $statusChild = 14;
        public static $statusStars = 15;
        public static $statusMeal = 16;
        public static $statusNights = 17;
    }
}

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
    'pick_date_17.10.2026'=>'wizard',
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
ccCheck('wizard owns date', WizardCallbackAction::handles('pick_date_17.10.2026'), true);
ccCheck('wizard excludes back phone', WizardCallbackAction::handles('back_phone'), false);
ccCheck('edit owns edit date', EditCallbackAction::handles('edit_date'), true);
ccCheck('manager owns manual phone', ManagerCallbackAction::handles('phone_manual'), true);
ccCheck('tours owns finish', ToursCallbackAction::handles('finish_from_ai'), true);
ccCheck('manager excludes tours', ManagerCallbackAction::handles('show_tours'), false);

$wizardSource = (string)file_get_contents(__DIR__ . '/../actions/callbacks/WizardCallbackAction.php');
ccCheck('date callback has per-chat serialization lock', strpos($wizardSource, 'max-search-date-callback-locks') !== false && strpos($wizardSource, 'flock($fp, LOCK_EX)') !== false, true);
ccCheck('stale date callback requires active date step', strpos($wizardSource, 'getCurentStatus($chatId)') !== false && strpos($wizardSource, '$statusDate') !== false && strpos($wizardSource, 'STALE_DATE_CALLBACK_SKIPPED') !== false, true);
ccCheck('date callback routes through guarded handler', strpos($wizardSource, "strpos(\$q, 'pick_date_') === 0) return self::handleDateSelection") !== false, true);
ccCheck('month change routes through guarded handler', strpos($wizardSource, "strpos(\$q, 'month_change_') === 0) return self::handleMonthChange") !== false, true);
ccCheck('stale month change requires active date step', strpos($wizardSource, 'STALE_MONTH_CHANGE_CALLBACK_SKIPPED') !== false, true);
ccCheck('duplicate month change is explicitly suppressed', strpos($wizardSource, 'DUPLICATE_MONTH_CHANGE_CALLBACK_SKIPPED') !== false, true);
ccCheck('same month callback inside debounce window is duplicate', WizardCallbackAction::isDuplicateMonthChange('month_change_09.2026', 100.0, 'month_change_09.2026', 101.0), true);
ccCheck('same month callback after debounce window is allowed', WizardCallbackAction::isDuplicateMonthChange('month_change_09.2026', 100.0, 'month_change_09.2026', 102.1), false);
ccCheck('different month callback remains allowed immediately', WizardCallbackAction::isDuplicateMonthChange('month_change_09.2026', 100.0, 'month_change_10.2026', 100.1), false);

ccCheck('city choice is valid only on city step', WizardCallbackAction::expectedStatusForForwardCallback('pick_city_1'), (int)MaxSearchApi::$statusCityChoose);
ccCheck('country choice is valid only on country step', WizardCallbackAction::expectedStatusForForwardCallback('pick_country_4'), (int)MaxSearchApi::$statusContryChoose);
ccCheck('adult choice is valid only on adults step', WizardCallbackAction::expectedStatusForForwardCallback('adults_2'), (int)MaxSearchApi::$statusAdults);
ccCheck('children choice is valid only on children step', WizardCallbackAction::expectedStatusForForwardCallback('child_0'), (int)MaxSearchApi::$statusChild);
ccCheck('stars choice is valid only on stars step', WizardCallbackAction::expectedStatusForForwardCallback('star_4'), (int)MaxSearchApi::$statusStars);
ccCheck('meal choice is valid only on meal step', WizardCallbackAction::expectedStatusForForwardCallback('meal_7'), (int)MaxSearchApi::$statusMeal);
ccCheck('nights choice is valid only on nights step', WizardCallbackAction::expectedStatusForForwardCallback('nights_9_11'), (int)MaxSearchApi::$statusNights);
ccCheck('date choice remains under dedicated guarded handler', WizardCallbackAction::expectedStatusForForwardCallback('pick_date_17.10.2026'), null);
ccCheck('back navigation is not blocked by forward-step guard', WizardCallbackAction::expectedStatusForForwardCallback('back_nights'), null);
ccCheck('forward wizard callbacks have stale-step guard', strpos($wizardSource, 'STALE_WIZARD_CALLBACK_SKIPPED') !== false && strpos($wizardSource, 'self::staleForwardCallback($chatId, $q)') !== false, true);

$controller = new CallbackController();
ccCheck('empty callback rejected', $controller->handle(['from'=>['id'=>1],'data'=>'']), false);
ccCheck('missing chat rejected', $controller->handle(['from'=>[],'data'=>'ai_start']), false);
ccCheck('unknown callback rejected', $controller->handle(['from'=>['id'=>1],'data'=>'something_new']), false);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
