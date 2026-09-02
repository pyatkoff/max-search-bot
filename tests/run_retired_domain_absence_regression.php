<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$retired = 'anytour' . '.online';
$raw = shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files -z');
if (!is_string($raw)) {
    fwrite(STDERR, "FAIL  cannot list tracked files\n");
    exit(1);
}

$matches = [];
foreach (explode("\0", $raw) as $relative) {
    if ($relative === '') continue;
    $path = $root . '/' . $relative;
    if (!is_file($path)) continue;
    $contents = @file_get_contents($path);
    if (!is_string($contents)) continue;
    if (strpos($contents, $retired) !== false) $matches[] = $relative;
}

if ($matches !== []) {
    fwrite(STDERR, "FAIL  retired domain remains in tracked files:\n" . implode("\n", $matches) . "\n");
    exit(1);
}

echo "PASS  retired domain absent from all tracked files\n";
