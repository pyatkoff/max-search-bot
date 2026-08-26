<?php

declare(strict_types=1);

$failed = 0;
function pseCheck(string $name, $actual, $expected): void {
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

// Production diagnostics can contain legacy/free-form message bytes. A single
// invalid UTF-8 sequence must not make the whole operational snapshot empty.
$sample = ['ok'=>true, 'text'=>"valid \xB1 byte"];
$json = json_encode($sample, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE);
pseCheck('invalid UTF-8 is substituted instead of failing snapshot JSON', is_string($json), true);
$decoded = is_string($json) ? json_decode($json, true) : null;
pseCheck('substituted snapshot remains valid JSON', is_array($decoded) && ($decoded['ok'] ?? false) === true, true);

$source = (string)file_get_contents(__DIR__ . '/../tools/production_snapshot.php');
pseCheck('production snapshot enables invalid UTF-8 substitution', strpos($source, 'JSON_INVALID_UTF8_SUBSTITUTE') !== false, true);
pseCheck('production snapshot checks json_encode failure explicitly', strpos($source, 'production_snapshot_json_encode_failed') !== false, true);

exit($failed > 0 ? 1 : 0);
