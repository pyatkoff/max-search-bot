<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/AiSearchContextService.php';

$passed = 0;
$failed = 0;

function aiCheck(string $name, $actual, $expected): void
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

$status = [
    'city'=>65,
    'country'=>66,
    'adults'=>67,
    'children'=>68,
    'child_ages'=>69,
    'stars'=>70,
    'meal'=>71,
    'nights'=>72,
    'date'=>73,
];

$saved = [
    65=>17,
    66=>4,
    67=>2,
    68=>0,
    70=>4,
    71=>7,
    72=>'7-9',
    73=>'28.08.2026',
];
$context = AiSearchContextService::contextFromSaved(
    $saved,
    $status,
    static fn($id) => $id === 17 ? 'Калининград' : false,
    static fn($id) => $id === 4 ? 'Турция' : false
);
aiCheck('context city', $context['city'] ?? null, 'Калининград');
aiCheck('context country', $context['country'] ?? null, 'Турция');
aiCheck('context adults', $context['adults'] ?? null, 2);
aiCheck('context zero children preserved', $context['children'] ?? null, 0);
aiCheck('context meal mapping', $context['meal'] ?? null, 'all_inclusive');
aiCheck('complete context has no missing fields', AiSearchContextService::missingFromSaved($saved, $status), []);

$withChild = $saved;
$withChild[68] = 1;
unset($withChild[69]);
aiCheck('child age required only when children > 0', AiSearchContextService::missingFromSaved($withChild, $status), ['child_ages']);

$normalized = AiSearchContextService::normalizeParameters(
    [
        'city'=>'Калининград',
        'country'=>'Турция',
        'adults'=>2,
        'children'=>1,
        'child_ages'=>[6],
        'stars'=>4,
        'meal'=>'all_inclusive',
        'nights'=>'9-11',
        'date'=>'15.10.2026',
    ],
    static fn($name) => $name === 'Калининград' ? 17 : null,
    static fn($name) => null,
    static fn($date) => true
);
aiCheck('normalize city resolver', $normalized['city'] ?? null, 17);
aiCheck('normalize country alias', $normalized['country'] ?? null, 4);
aiCheck('normalize child ages', $normalized['child_ages'] ?? null, '6');
aiCheck('normalize meal', $normalized['meal'] ?? null, '7');
aiCheck('normalize nights', $normalized['nights'] ?? null, '9-11');
aiCheck('normalize date', $normalized['date'] ?? null, '15.10.2026');

$invalid = AiSearchContextService::normalizeParameters(
    [
        'adults'=>9,
        'children'=>5,
        'child_ages'=>[19],
        'stars'=>7,
        'meal'=>'ultra_magic',
        'nights'=>'30-40',
        'date'=>'01.01.2020',
    ],
    static fn($name) => null,
    static fn($name) => null,
    static fn($date) => false
);
aiCheck('invalid AI values are rejected', $invalid, []);

$storage = AiSearchContextService::storageMap($status);
aiCheck('storage map country', $storage['country'] ?? null, 66);
aiCheck('storage map date', $storage['date'] ?? null, 73);

$total = $passed + $failed;
echo "\n---------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
