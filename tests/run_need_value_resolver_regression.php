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

$adults = NeedValueResolver::resolve('adults', 'Двое');
nvrCheck('adults word recognized', $adults['recognized'], true);
nvrCheck('adults canonical value', $adults['value'], 2);
nvrCheck('adults source is deterministic', $adults['source'], 'deterministic:adults_parser');
nvrCheck('adults deterministic confidence', $adults['confidence'], 1.0);

$adultsSuffix = NeedValueResolver::resolve('adults', '3 взрослых');
nvrCheck('adults suffix form retained', $adultsSuffix['value'], 3);
$adultsUnknown = NeedValueResolver::resolve('adults', 'семеро');
nvrCheck('out of range adults stays unresolved', $adultsUnknown['recognized'], false);

$childAges = NeedValueResolver::resolve('child_ages', '5 и 12', ['children'=>2]);
nvrCheck('child ages recognized with expected count', $childAges['recognized'], true);
nvrCheck('child ages canonical value', $childAges['value'], [5,12]);
nvrCheck('child ages source is deterministic', $childAges['source'], 'deterministic:child_ages_parser');
nvrCheck('child ages deterministic confidence', $childAges['confidence'], 1.0);
$childAgesWrongCount = NeedValueResolver::resolve('child_ages', '5', ['children'=>2]);
nvrCheck('child ages wrong count stays unresolved', $childAgesWrongCount['recognized'], false);
$childAgesOutOfRange = NeedValueResolver::resolve('child_ages', '5 и 18', ['children'=>2]);
nvrCheck('child ages out of range stays unresolved', $childAgesOutOfRange['recognized'], false);
$childAgesNoContext = NeedValueResolver::resolve('child_ages', '5');
nvrCheck('child ages without child count stays unresolved', $childAgesNoContext['recognized'], false);

$stars = NeedValueResolver::resolve('stars', '4,5');
nvrCheck('star set recognized', $stars['recognized'], true);
nvrCheck('star set keeps minimum semantics', $stars['value'], 4);
nvrCheck('stars source is deterministic', $stars['source'], 'deterministic:stars_parser');
nvrCheck('stars deterministic confidence', $stars['confidence'], 1.0);
$starsAny = NeedValueResolver::resolve('stars', 'не важно');
nvrCheck('any stars retains existing minimum-one semantics', $starsAny['value'], 1);
$starsUnknown = NeedValueResolver::resolve('stars', 'шесть');
nvrCheck('out of range stars stays unresolved', $starsUnknown['recognized'], false);

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

$unsupported = NeedValueResolver::resolve('children', '2');
nvrCheck('unmigrated children field remains explicitly unsupported', $unsupported, [
    'recognized' => false,
    'value' => null,
    'source' => 'unsupported',
    'confidence' => 0.0,
]);

$handlerSource = (string)file_get_contents(__DIR__ . '/../handlers/AiShortAnswerHandler.php');
nvrCheck('AI child ages delegates to resolver', strpos($handlerSource, "NeedValueResolver::resolve('child_ages'") !== false, true);
nvrCheck('AI child ages no longer owns numeric extraction', strpos($handlerSource, "preg_match_all('/\\b(\\d{1,2})\\b/u', $lower") === false, true);

echo "\n--------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
