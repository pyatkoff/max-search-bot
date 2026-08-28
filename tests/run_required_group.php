<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$manifest = require __DIR__ . '/required_checks_manifest.php';
if (!is_array($manifest) || $manifest === []) {
    fwrite(STDERR, "Required-check manifest is empty\n");
    exit(2);
}

$group = (string)($argv[1] ?? 'all');
if ($group === '--list') {
    foreach (array_keys($manifest) as $name) {
        echo $name . PHP_EOL;
    }
    exit(0);
}

if ($group === 'all') {
    $groups = array_keys($manifest);
} elseif (isset($manifest[$group]) && is_array($manifest[$group])) {
    $groups = [$group];
} else {
    fwrite(STDERR, 'Unknown required-check group: ' . $group . PHP_EOL);
    exit(2);
}

foreach ($groups as $name) {
    echo "\n== REQUIRED GROUP: {$name} ==\n";
    foreach ($manifest[$name] as $command) {
        if (!is_string($command) || trim($command) === '') {
            fwrite(STDERR, "Invalid command in group {$name}\n");
            exit(2);
        }
        echo "== {$command} ==\n";
        passthru('cd ' . escapeshellarg($root) . ' && ' . $command, $code);
        if ($code !== 0) {
            fwrite(STDERR, "FAILED [{$name}] {$command} (exit {$code})\n");
            exit($code ?: 1);
        }
    }
}

echo "\nREQUIRED CHECK GROUPS PASSED: " . implode(', ', $groups) . PHP_EOL;
