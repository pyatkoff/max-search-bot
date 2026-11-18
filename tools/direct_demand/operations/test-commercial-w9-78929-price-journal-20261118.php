<?php
declare(strict_types=1);

require __DIR__ . '/commercial-w9-78929-price-journal-20261118.php';

$dir = sys_get_temp_dir() . '/caption-journal-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) {
    throw new RuntimeException('fixture_directory_failed');
}
$path = $dir . '/journal';
$goodState = ['pending' => null, 'nightly_cycle' => ['collector_run_id' => '44236272559', 'state' => 'preflight_blocked']];
$encode = static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR) . "\n";
$good = $encode(['pending' => ['historical']]) . $encode($goodState);
$pinFor = static fn(string $raw): array => [
    'bytes' => strlen($raw), 'lines' => substr_count($raw, "\n"), 'sha256' => hash('sha256', $raw),
    'collector_run_id' => '44236272559', 'cycle_state' => 'preflight_blocked',
];
$cases = [
    ['valid complete history', $good, [], true],
    ['valid exclusive lock path', $good, [], true],
    ['earlier bytes changed', str_replace('historical','Historical',$good), $pinFor($good), false],
    ['wrong byte count', $good, ['bytes' => strlen($good) + 1], false],
    ['wrong line count', $good, ['lines' => 3], false],
    ['wrong digest', $good, ['sha256' => str_repeat('a',64)], false],
    ['missing final newline', rtrim($good,"\n"), [], false],
    ['blank record', $good . "\n", [], false],
    ['invalid final JSON', $good . "not-json\n", [], false],
    ['missing pending key', $encode(['nightly_cycle' => $goodState['nightly_cycle']]), [], false],
    ['active pending operation', $encode(array_replace($goodState,['pending'=>['operation'=>'active']])), [], false],
    ['wrong cycle state', $good, ['cycle_state' => 'completed'], false],
    ['wrong collector run', $good, ['collector_run_id' => '100'], false],
    ['oversized record', str_repeat('x',8388608) . "\n" . $good, [], false],
    ['linked path', $good, [], false],
];
try {
    foreach ($cases as $index => [$name, $raw, $override, $accepted]) {
        file_put_contents($path, $raw);
        $open = $path;
        if ($name === 'linked path') {
            $open = $dir . '/link';
            if (!symlink($path, $open)) throw new RuntimeException('fixture_symlink_failed');
        }
        $handle = fopen($open, 'rb');
        if (!is_resource($handle) || !flock($handle,$index === 1 ? LOCK_EX : LOCK_SH)) {
            throw new RuntimeException('fixture_open_failed');
        }
        $pin = array_replace($pinFor($raw), $override);
        $ok = false;
        try {
            $observed = verifiedCaptionPriceJournal($open, $handle, $pin);
            $ok = $observed['sha256'] === $pin['sha256'];
        } catch (Throwable $error) {
            if ($accepted) throw $error;
        } finally {
            fclose($handle);
        }
        if ($ok !== $accepted) {
            throw new RuntimeException('unexpected journal result: ' . $name);
        }
    }
    echo "15 exact journal checks passed\n";
} finally {
    if (is_link($dir . '/link')) unlink($dir . '/link');
    if (is_file($path)) unlink($path);
    rmdir($dir);
}
