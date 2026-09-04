<?php

declare(strict_types=1);

class MaxSearchApi
{
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

require_once __DIR__ . '/../services/DialogueTransitionObserver.php';

$failed = 0;
function transitionObserverCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

$logFile = sys_get_temp_dir() . '/max-search-transition-observer-' . getmypid() . '.log';
@unlink($logFile);
DiagnosticLogger::setFile($logFile);

$returnType = (new ReflectionMethod(DialogueTransitionObserver::class, 'observe'))->getReturnType();
DialogueTransitionObserver::observe(800, 72, 73, 'forward', 'nights_callback');
$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$allowed = json_decode((string)($lines[0] ?? ''), true);

transitionObserverCheck('observer has a non-blocking void API', $returnType ? $returnType->getName() : null, 'void');
transitionObserverCheck('allowed transition emits one structured event', count($lines), 1);
transitionObserverCheck('allowed event uses stable component', $allowed['component'] ?? null, 'dialogue_transition');
transitionObserverCheck('allowed event keeps exact transition states', [$allowed['data']['from_state'] ?? null, $allowed['data']['to_state'] ?? null], ['nights', 'date']);
transitionObserverCheck('allowed event reports canonical decision', $allowed['data']['allowed'] ?? null, true);
transitionObserverCheck('allowed event keeps caller scope', $allowed['data']['scope'] ?? null, 'nights_callback');

DialogueTransitionObserver::observe(801, 70, 73, 'forward', 'invalid_test');
$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$invalid = json_decode((string)($lines[1] ?? ''), true);

transitionObserverCheck('invalid transition is only observed', count($lines), 2);
transitionObserverCheck('invalid event is a warning', $invalid['level'] ?? null, 'warning');
transitionObserverCheck('invalid event has stable name', $invalid['event'] ?? null, 'transition_violation_observed');
transitionObserverCheck('invalid event reports false decision', $invalid['data']['allowed'] ?? null, false);

$observer = (string)file_get_contents(__DIR__ . '/../services/DialogueTransitionObserver.php');
$callback = (string)file_get_contents(__DIR__ . '/../actions/callbacks/WizardCallbackAction.php');
$freeText = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
transitionObserverCheck('observer contains no dialogue mutation', strpos($observer, 'setStatus(') === false && strpos($observer, 'saveLastValue(') === false, true);
transitionObserverCheck('nights callback is an exact observer caller', substr_count($callback, 'DialogueTransitionObserver::observe('), 1);
transitionObserverCheck('free-text nights is an exact observer caller', substr_count($freeText, 'DialogueTransitionObserver::observe('), 1);
transitionObserverCheck('runtime callers do not branch on observer result', strpos($callback, 'if (DialogueTransitionObserver::observe(') === false && strpos($freeText, 'if (DialogueTransitionObserver::observe(') === false, true);

@unlink($logFile);
echo "\n--------------------------\n";
echo $failed === 0 ? "DIALOGUE TRANSITION OBSERVER: OK\n" : "DIALOGUE TRANSITION OBSERVER: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
