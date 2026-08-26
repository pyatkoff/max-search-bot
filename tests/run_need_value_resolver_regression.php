<?php

declare(strict_types=1);
require_once __DIR__ . '/../services/NeedValueResolver.php';

$passed = 0;
$failed = 0;

function nvrCheck(string $label, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "PASS  {$label}\n";
        $passed++;
        return;
    }

    echo "FAIL  {$label}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

$meal = NeedValueResolver::resolve('meal', 'Всё включено');
nvrCheck('meal recognized', $meal['recognized'], true);
nvrCheck('meal canonical value', $meal['value'], 'all_inclusive');
nvrCheck('meal source is deterministic', $meal['source'], 'deterministic:meal_parser');
nvrCheck('meal deterministic confidence', $meal['confidence'], 1.0);

$mealUnknown = NeedValueResolver::resolve('meal', 'Вкусное');
nvrCheck('unknown meal not recognized', $mealUnknown['recognized'], false);
nvrCheck('unknown meal has no value', $mealUnknown['value'], null);
nvrCheck('unknown meal confidence is zero', $mealUnknown['confidence'], 0.0);

$nights = NeedValueResolver::resolve('nights', 'От 7-9');
nvrCheck('live nights range recognized', $nights['recognized'], true);
nvrCheck('live nights range canonical value', $nights['value'], '7-9');
nvrCheck('nights source is deterministic', $nights['source'], 'deterministic:nights_parser');
nvrCheck('nights deterministic confidence', $nights['confidence'], 1.0);

$nightsUnknown = NeedValueResolver::resolve('nights', 'от 7');
nvrCheck('minimum-only nights stays unresolved', $nightsUnknown['recognized'], false);
nvrCheck('minimum-only nights has no invented value', $nightsUnknown['value'], null);

$unsupported = NeedValueResolver::resolve('adults', '2');
nvrCheck('unmigrated field remains explicitly unsupported', $unsupported, [
    'recognized' => false,
    'value' => null,
    'source' => 'unsupported',
    'confidence' => 0.0,
]);

echo "\n--------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
