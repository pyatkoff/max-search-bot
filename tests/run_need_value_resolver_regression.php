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

$childrenNone = NeedValueResolver::resolve('children', 'без детей');
nvrCheck('children none phrase recognized', $childrenNone['recognized'], true);
nvrCheck('children none canonical value', $childrenNone['value'], 0);
nvrCheck('children source is deterministic', $childrenNone['source'], 'deterministic:children_parser');
nvrCheck('children deterministic confidence', $childrenNone['confidence'], 1.0);
$childrenWord = NeedValueResolver::resolve('children', 'двое');
nvrCheck('children word value retained', $childrenWord['value'], 2);
$childrenSuffix = NeedValueResolver::resolve('children', '3 детей');
nvrCheck('children suffix value retained', $childrenSuffix['value'], 3);
$childrenAffirmativeNumeric = NeedValueResolver::resolve('children', 'Да, 3');
nvrCheck('live affirmative numeric child count recognized', $childrenAffirmativeNumeric['recognized'], true);
nvrCheck('live affirmative numeric child count value', $childrenAffirmativeNumeric['value'], 3);
$childrenAffirmativeWord = NeedValueResolver::resolve('children', 'Да, трое');
nvrCheck('live affirmative word child count recognized', $childrenAffirmativeWord['recognized'], true);
nvrCheck('live affirmative word child count value', $childrenAffirmativeWord['value'], 3);
$childrenLabeledPerson = NeedValueResolver::resolve('children', 'Дети, 1 человек');
nvrCheck('live 734 labeled child count recognized', $childrenLabeledPerson['recognized'], true);
nvrCheck('live 734 labeled child count value', $childrenLabeledPerson['value'], 1);
$childrenLabeledAge = NeedValueResolver::resolve('children', 'Дети, 2 года');
nvrCheck('labeled age phrase is not consumed as child count', $childrenLabeledAge['recognized'], false);
$childrenBareYes = NeedValueResolver::resolve('children', 'Да');
nvrCheck('bare affirmative does not invent child count', $childrenBareYes['recognized'], false);
$childrenTooMany = NeedValueResolver::resolve('children', '4');
nvrCheck('children above current maximum stays unresolved', $childrenTooMany['recognized'], false);
$childrenAmbiguous = NeedValueResolver::resolve('children', '6 лет, один ребенок');
nvrCheck('multi-field child age clarification is not consumed as simple children value', $childrenAmbiguous['recognized'], false);

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
$starsNaturalChoice = NeedValueResolver::resolve('stars', '4 или 5');
nvrCheck('live natural star alternative recognized', $starsNaturalChoice['recognized'], true);
nvrCheck('live natural star alternative keeps minimum semantics', $starsNaturalChoice['value'], 4);
$starsNaturalChoiceSuffix = NeedValueResolver::resolve('stars', '4 или 5 звёзд');
nvrCheck('live natural star alternative with suffix recognized', $starsNaturalChoiceSuffix['recognized'], true);
nvrCheck('live natural star alternative with suffix keeps minimum semantics', $starsNaturalChoiceSuffix['value'], 4);
$starsAny = NeedValueResolver::resolve('stars', 'не важно');
nvrCheck('any stars retains existing minimum-one semantics', $starsAny['value'], 1);
$starsUnknown = NeedValueResolver::resolve('stars', 'шесть');
nvrCheck('out of range stars stays unresolved', $starsUnknown['recognized'], false);

$meal = NeedValueResolver::resolve('meal', 'Всё включено');
nvrCheck('meal recognized', $meal['recognized'], true);
nvrCheck('meal canonical value', $meal['value'], 'all_inclusive');
nvrCheck('meal source is deterministic', $meal['source'], 'deterministic:meal_parser');
nvrCheck('meal deterministic confidence', $meal['confidence'], 1.0);

$liveMeal = NeedValueResolver::resolve('meal', '3х разовое');
nvrCheck('live 3х meal shorthand recognized', $liveMeal['recognized'], true);
nvrCheck('live 3х meal shorthand maps to full board', $liveMeal['value'], 'full_board');
nvrCheck('live 3х meal shorthand stays deterministic', $liveMeal['source'], 'deterministic:meal_parser');

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

$unsupported = NeedValueResolver::resolve('date', '12 октября');
nvrCheck('unmigrated field remains explicitly unsupported', $unsupported, [
    'recognized' => false,
    'value' => null,
    'source' => 'unsupported',
    'confidence' => 0.0,
]);

$handlerSource = (string)file_get_contents(__DIR__ . '/../handlers/AiShortAnswerHandler.php');
nvrCheck('AI children delegates simple values through application service', strpos($handlerSource, "NeedApplicationService::resolveAndApply(\$chat_id, 'children'") !== false, true);
nvrCheck('AI children keeps multi-field party clarification before simple resolver', strpos($handlerSource, 'if ($partyClarification !== null)') !== false && strpos($handlerSource, 'elseif ($ageCountClarification !== null)') !== false, true);
nvrCheck('AI children no longer owns generic short number parsing', strpos($handlerSource, 'numberFromShortText') === false, true);
nvrCheck('AI child ages delegates through application service', strpos($handlerSource, "NeedApplicationService::resolveAndApply(\$chat_id, 'child_ages'") !== false, true);
nvrCheck('AI child ages no longer owns numeric extraction', strpos($handlerSource, 'preg_match_all(\'/\\b(\\d{1,2})\\b/u\', $lower') === false, true);

echo "\n--------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
