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
    false
);

$total = $passed + $failed;
echo "\n----------------------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
