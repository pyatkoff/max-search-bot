<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/DateParser.php';

$failed = 0;
function liveSeptemberCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) {
        echo "PASS  {$name}\n";
        return;
    }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

$r = DateParser::resolveDate('10 сентебря');
liveSeptemberCheck('live typo 10 сентебря resolves', $r['date'] ?? null, '10.09.2026');
$r = DateParser::resolveDate('9 сентебря');
liveSeptemberCheck('live typo 9 сентебря resolves', $r['date'] ?? null, '09.09.2026');
$r = DateParser::resolveDate('10 сентября');
liveSeptemberCheck('correct spelling remains supported', $r['date'] ?? null, '10.09.2026');

exit($failed ? 1 : 0);
