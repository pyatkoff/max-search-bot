<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/NativeDateService.php';

$passed = 0;
$failed = 0;
function ndcheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

$today = new DateTimeImmutable('2026-08-31 00:00:00');
ndcheck('today accepted', NativeDateService::isTodayOrFuture('31.08.2026', $today), true);
ndcheck('future accepted', NativeDateService::isTodayOrFuture('01.09.2026', $today), true);
ndcheck('past rejected', NativeDateService::isTodayOrFuture('30.08.2026', $today), false);
ndcheck('invalid date rejected', NativeDateService::isTodayOrFuture('31.02.2026', $today), false);
ndcheck('invalid format rejected', NativeDateService::isTodayOrFuture('2026-08-31', $today), false);

ndcheck(
    'lead window keeps plus minus three days',
    NativeDateService::leadWindow('10.09.2026', $today),
    ['from'=>'07.09.2026','to'=>'13.09.2026']
);
ndcheck(
    'lead window lower bound clamps to today',
    NativeDateService::leadWindow('01.09.2026', $today),
    ['from'=>'31.08.2026','to'=>'04.09.2026']
);

$threw = false;
try { NativeDateService::leadWindow('bad', $today); } catch (InvalidArgumentException $e) { $threw = true; }
ndcheck('invalid lead date fails explicitly', $threw, true);

$total = $passed + $failed;
echo "\n---------------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
