<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ConversationStateRepository.php';

$passed = 0;
$failed = 0;

function stateCheck(string $name, $actual, $expected): void
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

echo "Conversation state repository regression\n";
echo "========================================\n\n";

$rows = [
    ['UF_STATUS'=>73, 'UF_VALUE'=>'30.08.2026'],
    ['UF_STATUS'=>72, 'UF_VALUE'=>'7'],
    ['UF_STATUS'=>74, 'UF_VALUE'=>'ignored-check-row'],
    ['UF_STATUS'=>68, 'UF_VALUE'=>'0'],
    ['UF_STATUS'=>67, 'UF_VALUE'=>'2'],
    ['UF_STATUS'=>64, 'UF_VALUE'=>'start'],
    ['UF_STATUS'=>66, 'UF_VALUE'=>'must-not-cross-start-boundary'],
];

$saved = ConversationStateRepository::savedDataFromRows($rows, 64, 74);
stateCheck('date preserved', $saved[73] ?? null, '30.08.2026');
stateCheck('nights preserved', $saved[72] ?? null, '7');
stateCheck('check status excluded', array_key_exists(74, $saved), false);
stateCheck('adults preserved', $saved[67] ?? null, '2');
stateCheck('start boundary stops older rows', array_key_exists(66, $saved), false);
stateCheck('legacy zero child value preserved when no older duplicate', array_key_exists(68, $saved), true);
stateCheck('legacy zero child value equals zero string', $saved[68] ?? null, '0');

$duplicates = [
    ['UF_STATUS'=>67, 'UF_VALUE'=>'2'],
    ['UF_STATUS'=>67, 'UF_VALUE'=>'3'],
    ['UF_STATUS'=>64, 'UF_VALUE'=>'start'],
];
$latest = ConversationStateRepository::savedDataFromRows($duplicates, 64, 74);
stateCheck('newest non-empty status wins', $latest[67] ?? null, '2');

$zeroCorrection = ConversationStateRepository::savedDataFromRows([
    ['UF_STATUS'=>68, 'UF_VALUE'=>'0'],
    ['UF_STATUS'=>68, 'UF_VALUE'=>'2'],
    ['UF_STATUS'=>64, 'UF_VALUE'=>'start'],
], 64, 74);
stateCheck('newest explicit zero child correction beats older nonzero value', $zeroCorrection[68] ?? null, '0');

$blankPrompt = ConversationStateRepository::savedDataFromRows([
    ['UF_STATUS'=>68, 'UF_VALUE'=>''],
    ['UF_STATUS'=>68, 'UF_VALUE'=>'2'],
    ['UF_STATUS'=>64, 'UF_VALUE'=>'start'],
], 64, 74);
stateCheck('newest blank prompt still falls back to older answered value', $blankPrompt[68] ?? null, '2');

$budget = ['max'=>250000,'currency'=>'RUB','basis'=>'total','basis_source'=>'product_default'];
$legacyBudgetRaw = TripBudgetPolicy::toStartValue($budget);
$legacyBudget = ConversationStateRepository::savedDataFromRows([
    ['UF_STATUS'=>64,'UF_VALUE'=>$legacyBudgetRaw],
], 64, 74);
stateCheck('legacy budget envelope remains readable', $legacyBudget['_budget'] ?? null, $budget);
stateCheck('legacy budget envelope has no invented wishes', $legacyBudget['_preferences'] ?? [], []);

$context = TripContextMetadataPolicy::applyPreferences(
    ['budget'=>$budget,'preferences'=>[],'negative_preferences'=>[]],
    ['preferences'=>['тихий отель','первая линия'],'negative_preferences'=>['шумный отель']]
);
$contextRaw = TripContextMetadataPolicy::toStartValue($context ?? []);
$contextSaved = ConversationStateRepository::savedDataFromRows([
    ['UF_STATUS'=>64,'UF_VALUE'=>$contextRaw],
], 64, 74);
stateCheck('context envelope preserves budget', $contextSaved['_budget'] ?? null, $budget);
stateCheck('context envelope exposes wishes', $contextSaved['_preferences'] ?? null, ['тихий отель','первая линия']);
stateCheck('context envelope exposes exclusions', $contextSaved['_negative_preferences'] ?? null, ['шумный отель']);

$extended = TripContextMetadataPolicy::applyPreferences($context ?? [], ['preferences'=>['детский клуб','тихий отель']]);
stateCheck('preference changes append instead of replacing earlier wishes', $extended['preferences'] ?? null, ['тихий отель','первая линия','детский клуб']);
$neutralWish = TripContextMetadataPolicy::applyPreferences($extended ?? [], ['preferences_remove'=>['первая линия']]);
stateCheck('neutral correction removes an active wish', $neutralWish['preferences'] ?? null, ['тихий отель','детский клуб']);
stateCheck('neutral wish correction does not invent an exclusion', $neutralWish['negative_preferences'] ?? null, ['шумный отель']);
$neutralNegative = TripContextMetadataPolicy::applyPreferences($neutralWish ?? [], ['negative_preferences_remove'=>['шумный отель']]);
stateCheck('neutral correction can remove an active exclusion', $neutralNegative['negative_preferences'] ?? null, []);
stateCheck('neutral exclusion correction does not invent a wish', $neutralNegative['preferences'] ?? null, ['тихий отель','детский клуб']);
stateCheck('removing an absent item is idempotent', TripContextMetadataPolicy::applyPreferences($neutralNegative ?? [], ['preferences_remove'=>['первая линия']]), $neutralNegative);
stateCheck('same-message add and neutral removal fail closed', TripContextMetadataPolicy::applyPreferences($context ?? [], [
    'preferences'=>['первая линия'],'preferences_remove'=>['первая линия'],
]), null);

$caseNeutral = TripContextMetadataPolicy::applyPreferences($extended ?? [], ['preferences_remove'=>['ПЕРВАЯ ЛИНИЯ']]);
stateCheck('neutral correction matches active wish across letter case', $caseNeutral['preferences'] ?? null, ['тихий отель','детский клуб']);
stateCheck('case-insensitive neutral correction leaves a different property untouched', $caseNeutral['preferences'] ?? null, ['тихий отель','детский клуб']);
$caseDuplicate = TripContextMetadataPolicy::applyPreferences($extended ?? [], ['preferences'=>['ТИХИЙ ОТЕЛЬ']]);
stateCheck('case-variant repeat does not duplicate an active wish', $caseDuplicate['preferences'] ?? null, ['тихий отель','первая линия','детский клуб']);
stateCheck('case-variant same-message add and neutral removal fail closed', TripContextMetadataPolicy::applyPreferences($context ?? [], [
    'preferences'=>['Первая линия'],'preferences_remove'=>['первая линия'],
]), null);

$polarity = TripContextMetadataPolicy::applyPreferences($extended ?? [], ['negative_preferences'=>['первая линия']]);
stateCheck('explicit negative correction removes same positive wish', $polarity['preferences'] ?? null, ['тихий отель','детский клуб']);
stateCheck('explicit negative correction becomes exclusion', $polarity['negative_preferences'] ?? null, ['шумный отель','первая линия']);
$restored = TripContextMetadataPolicy::applyPreferences($polarity ?? [], ['preferences'=>['первая линия']]);
stateCheck('explicit positive correction removes same exclusion', $restored['negative_preferences'] ?? null, ['шумный отель']);
stateCheck('same-message contradictory polarity fails closed', TripContextMetadataPolicy::applyPreferences($context ?? [], [
    'preferences'=>['первая линия'],'negative_preferences'=>['первая линия'],
]), null);

$casePolarity = TripContextMetadataPolicy::applyPreferences($extended ?? [], ['negative_preferences'=>['ПЕРВАЯ ЛИНИЯ']]);
stateCheck('case-variant negative correction removes semantic positive wish', $casePolarity['preferences'] ?? null, ['тихий отель','детский клуб']);
stateCheck('case-variant negative correction keeps incoming display spelling', $casePolarity['negative_preferences'] ?? null, ['шумный отель','ПЕРВАЯ ЛИНИЯ']);
stateCheck('case-variant contradictory polarity fails closed', TripContextMetadataPolicy::applyPreferences($context ?? [], [
    'preferences'=>['Первая линия'],'negative_preferences'=>['первая линия'],
]), null);

stateCheck('invalid preference value fails closed', TripContextMetadataPolicy::applyPreferences($context ?? [], ['preferences'=>['ok', str_repeat('x', 121)]]), null);
stateCheck('unknown start payload still stays unowned', TripContextMetadataPolicy::fromStartValue('legacy-start'), null);
stateCheck('budget-only serialization remains legacy-compatible', TripContextMetadataPolicy::toStartValue(['budget'=>$budget,'preferences'=>[],'negative_preferences'=>[]]), $legacyBudgetRaw);

stateCheck(
    'pre-start row is not reused by a new dialogue',
    ConversationStateRepository::shouldReuseValueRow(10, 20),
    false
);
stateCheck(
    'current-session row can still be updated',
    ConversationStateRepository::shouldReuseValueRow(30, 20),
    true
);
stateCheck(
    'legacy state without a start marker remains reusable',
    ConversationStateRepository::shouldReuseValueRow(10, 0),
    true
);
stateCheck(
    'missing value row is never reusable',
    ConversationStateRepository::shouldReuseValueRow(0, 20),
    true
);

$source = (string)file_get_contents(__DIR__ . '/../services/ConversationStateRepository.php');
$saveMethod = '';
$lastMethod = '';
$preferenceMethod = '';
if (preg_match('/public static function saveLastValue\(.*?\n    \}/s', $source, $m)) $saveMethod = $m[0];
if (preg_match('/public static function lastValue\(.*?\n    \}/s', $source, $m)) $lastMethod = $m[0];
if (preg_match('/public static function applyPreferences\(.*?\n    \}/s', $source, $m)) $preferenceMethod = $m[0];
stateCheck(
    'saveLastValue enforces current-session boundary',
    strpos($saveMethod, 'shouldReuseValueRow') !== false,
    true
);
stateCheck(
    'lastValue enforces current-session boundary',
    strpos($lastMethod, 'shouldReuseValueRow') !== false,
    true
);
stateCheck(
    'lastValue selects row ID for boundary validation',
    strpos($lastMethod, "'ID','UF_VALUE'") !== false,
    true
);
stateCheck('preference write uses byte-exact current-start CAS', strpos($preferenceMethod, 'compareStartValue') !== false, true);
stateCheck('preference write never inserts a new start row', strpos($preferenceMethod, 'addStatus') === false && strpos($preferenceMethod, 'upsertValue') === false, true);
stateCheck('preference write accepts neutral removals without a second store', strpos($preferenceMethod, "'preferences_remove','negative_preferences_remove'") !== false, true);

$total = $passed + $failed;
echo "\n----------------------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
