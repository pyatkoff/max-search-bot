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
require_once __DIR__ . '/../services/InteractionGuard.php';

$passed = 0;
$failed = 0;

function lrCheck(string $name, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "PASS  {$name}\n";
        $passed++;
        return;
    }

    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

$files = glob(__DIR__ . '/live_regressions/*.json') ?: [];
sort($files);

lrCheck('live regression corpus is non-empty', count($files) > 0, true);

foreach ($files as $file) {
    $raw = file_get_contents($file);
    $scenario = is_string($raw) ? json_decode($raw, true) : null;
    $name = basename($file);

    lrCheck("{$name} parses", is_array($scenario), true);
    if (!is_array($scenario)) continue;

    lrCheck("{$name} has id", trim((string)($scenario['id'] ?? '')) !== '', true);
    lrCheck("{$name} has source", trim((string)($scenario['source'] ?? '')) !== '', true);
    lrCheck("{$name} has steps", !empty($scenario['steps']) && is_array($scenario['steps']), true);
    if (empty($scenario['steps']) || !is_array($scenario['steps'])) continue;

    $previousPayload = '';
    $previousAt = 0.0;

    foreach ($scenario['steps'] as $index => $step) {
        $label = ($scenario['id'] ?? $name) . ' step ' . ($index + 1);
        if (!is_array($step)) {
            lrCheck("{$label} is object", false, true);
            continue;
        }

        $type = (string)($step['type'] ?? '');
        if ($type === 'duplicate') {
            $payload = (string)($step['payload'] ?? '');
            $at = (float)($step['at'] ?? 0);
            $window = (float)($step['window_seconds'] ?? 0);
            $expected = (bool)($step['expect_duplicate'] ?? false);
            $actual = InteractionGuard::isDuplicate($previousPayload, $previousAt, $payload, $at, $window);
            lrCheck("{$label} duplicate decision", $actual, $expected);

            if (!$actual) {
                $previousPayload = $payload;
                $previousAt = $at;
            }
            continue;
        }

        if ($type === 'transition') {
            $from = (string)($step['from'] ?? '');
            $to = (string)($step['to'] ?? '');
            $mode = (string)($step['mode'] ?? 'forward');
            $expected = (bool)($step['expect_allowed'] ?? false);
            $actual = DialogueStateMachine::canTransition($from, $to, $mode);
            lrCheck("{$label} transition decision", $actual, $expected);
            continue;
        }

        lrCheck("{$label} known type", $type, 'duplicate|transition');
    }
}

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
