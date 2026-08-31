<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/services/InteractionGuard.php';

$passed = 0;
$failed = 0;
function liveBackCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

// Production conversation 627 delivered back_meal twice in the same second and
// rendered the same meal prompt twice. Exact repeated back deliveries should be
// consumed, while a different intentional back action must remain available.
$chatId = random_int(1000000, 9999999);
$scope = 'wizard_back';
$lockFile = InteractionGuard::lockPath($chatId, 'dedupe.' . $scope);
@unlink($lockFile);

$diagnosticFile = sys_get_temp_dir() . '/max-search-live-back-' . bin2hex(random_bytes(4)) . '.log';
DiagnosticLogger::setFile($diagnosticFile);
try {
    liveBackCheck('first back_meal delivery is allowed', InteractionGuard::suppressDuplicateCallback($chatId, 'back_meal', $scope), false);
    liveBackCheck('same back_meal delivery is suppressed', InteractionGuard::suppressDuplicateCallback($chatId, 'back_meal', $scope), true);
    liveBackCheck('different intentional back action remains allowed', InteractionGuard::suppressDuplicateCallback($chatId, 'back_nights', $scope), false);

    $lines = is_file($diagnosticFile) ? array_values(array_filter(array_map('trim', file($diagnosticFile) ?: []))) : [];
    $last = $lines ? json_decode((string)end($lines), true) : null;
    liveBackCheck('duplicate is observable through interaction guard', $last['component'] ?? null, 'interaction_guard');
    liveBackCheck('duplicate diagnostic reason is explicit', $last['data']['reason'] ?? null, 'duplicate');
    liveBackCheck('duplicate diagnostic scope is wizard back', $last['data']['scope'] ?? null, $scope);
    liveBackCheck('duplicate diagnostic keeps payload', $last['data']['payload'] ?? null, 'back_meal');

    $controllerSource = (string)file_get_contents(dirname(__DIR__) . '/services/CallbackController.php');
    liveBackCheck('callback controller guards wizard back callbacks before dispatch',
        strpos($controllerSource, "strpos(\$q, 'back_') === 0") !== false
        && strpos($controllerSource, "InteractionGuard::suppressDuplicateCallback(\$chatId, \$q, 'wizard_back')") !== false,
        true
    );
} finally {
    @unlink($lockFile);
    @unlink($diagnosticFile);
    DiagnosticLogger::setFile('');
}

echo "\n--------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
