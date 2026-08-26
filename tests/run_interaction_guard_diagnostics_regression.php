<?php

declare(strict_types=1);

if (!class_exists('MaxSearchApi')) {
    class MaxSearchApi {
        public static $statusStart = 10;
        public static $statusAi = 11;
        public static $statusCityChoose = 12;
        public static $statusContryChoose = 13;
        public static $statusAdults = 14;
        public static $statusChild = 15;
        public static $statusAge = 16;
        public static $statusStars = 17;
        public static $statusMeal = 18;
        public static $statusNights = 19;
        public static $statusDate = 20;
        public static $statusCheck = 21;
        public static $statusPhone = 22;
        public static $currentStatus = 12;
        public static function getCurentStatus($chatId) { return self::$currentStatus; }
    }
}

require_once __DIR__ . '/../services/DiagnosticLogger.php';
require_once __DIR__ . '/../services/DialogueStateMachine.php';
require_once __DIR__ . '/../services/InteractionGuard.php';

$passed = 0;
$failed = 0;
function igdCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

$tmp = sys_get_temp_dir() . '/max-search-interaction-diagnostics-' . bin2hex(random_bytes(4)) . '.log';
DiagnosticLogger::setFile($tmp);

InteractionGuard::reportSuppressed(123, 'meal_7', 'duplicate', 18, 18, 'month_change');
$lines = file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$record = $lines ? json_decode((string)end($lines), true) : [];
igdCheck('structured event component', $record['component'] ?? null, 'interaction_guard');
igdCheck('structured event name', $record['event'] ?? null, 'callback_suppressed');
igdCheck('structured event level', $record['level'] ?? null, 'warning');
igdCheck('structured event chat id', $record['chat_id'] ?? null, 123);
igdCheck('structured reason', $record['data']['reason'] ?? null, 'duplicate');
igdCheck('structured current state', $record['data']['current_state'] ?? null, 'meal');
igdCheck('structured expected state', $record['data']['expected_state'] ?? null, 'meal');

MaxSearchApi::$currentStatus = MaxSearchApi::$statusCountryChoose ?? 13;
MaxSearchApi::$currentStatus = 13;
igdCheck('stale forward callback is consumed', InteractionGuard::isStaleWizardForward(456, 'adults_2'), true);
$lines = file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$record = $lines ? json_decode((string)end($lines), true) : [];
igdCheck('stale reason is structured', $record['data']['reason'] ?? null, 'stale_state');
igdCheck('stale scope is wizard forward', $record['data']['scope'] ?? null, 'wizard_forward');
igdCheck('stale current state is country', $record['data']['current_state'] ?? null, 'country');
igdCheck('stale expected state is adults', $record['data']['expected_state'] ?? null, 'adults');

MaxSearchApi::$currentStatus = 14;
igdCheck('valid forward callback is not suppressed', InteractionGuard::isStaleWizardForward(456, 'adults_2'), false);
$afterValid = file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
igdCheck('valid callback adds no diagnostic', count($afterValid), count($lines));

@unlink($tmp);
$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
