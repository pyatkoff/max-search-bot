<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$catalog = require __DIR__ . '/required_checks_catalog.php';
if (!is_array($catalog) || $catalog === []) {
    fwrite(STDERR, "Required-check catalog is empty\n");
    exit(2);
}

$group = (string)($argv[1] ?? 'all');
$profile = (string)($argv[2] ?? 'full');
$appleLanes = [
    'ci-apple-coverage' => 'php tests/run_appleservice39_coverage_regression.php',
    'ci-apple-device' => 'php tests/run_appleservice39_device_publisher_regression.php',
];
$architectureProfiles = ['hotel-catalogue-data', 'telegram-public-observer', 'ci-architecture-core', ...array_keys($appleLanes)];
if ($profile !== 'full' && !($group === 'architecture' && in_array($profile, $architectureProfiles, true))) {
    fwrite(STDERR, "Invalid required-check profile for {$group}\n");
    exit(2);
}

// These tests exercise the AppleService39 publisher and its own data.
// All other architecture checks are required by both component profiles.
$applePublisherChecks = [
    'php tests/run_appleservice39_publisher_regression.php',
    'php tests/run_appleservice39_search_publisher_regression.php',
    'php tests/run_appleservice39_coverage_regression.php',
    'php tests/run_appleservice39_device_publisher_regression.php',
];

if ($group === 'architecture' && str_starts_with($profile, 'ci-')) {
    $commands = array_column($catalog['architecture'] ?? [], 'command');
    foreach ($appleLanes as $command) {
        if (count(array_keys($commands, $command, true)) !== 1) {
            fwrite(STDERR, "Architecture lane command missing or repeated: {$command}\n");
            exit(2);
        }
    }
}

if ($group === '--list') {
    foreach (array_keys($catalog) as $name) {
        echo $name . PHP_EOL;
    }
    exit(0);
}

if ($group === 'all') {
    $groups = array_keys($catalog);
} elseif (isset($catalog[$group]) && is_array($catalog[$group])) {
    $groups = [$group];
} else {
    fwrite(STDERR, 'Unknown required-check group: ' . $group . PHP_EOL);
    exit(2);
}

$report = [
    'schema_version' => 1,
    'generated_at' => gmdate('c'),
    'groups' => [],
    'summary' => ['checks' => 0, 'passed' => 0, 'failed' => 0, 'duration_ms' => 0],
];
$runStarted = hrtime(true);

foreach ($groups as $name) {
    echo "\n== REQUIRED GROUP: {$name} ==\n";
    $report['groups'][$name] = [];

    foreach ($catalog[$name] as $check) {
        if (!is_array($check) || empty($check['id']) || empty($check['command'])) {
            fwrite(STDERR, "Invalid check metadata in group {$name}\n");
            exit(2);
        }

        $command = (string)$check['command'];
        $id = (string)$check['id'];
        $excluded = match ($profile) {
            'full' => false,
            'ci-architecture-core' => in_array($command, $appleLanes, true),
            default => isset($appleLanes[$profile])
                ? $command !== $appleLanes[$profile]
                : in_array($command, $applePublisherChecks, true),
        };
        if ($excluded) {
            echo "CHECK_EXCLUDED {$id} ({$profile})\n";
            continue;
        }
        $type = (string)($check['type'] ?? 'regression');
        echo "== {$id} [{$type}] :: {$command} ==\n";

        $started = hrtime(true);
        passthru('cd ' . escapeshellarg($root) . ' && ' . $command, $code);
        $durationMs = (int)round((hrtime(true) - $started) / 1_000_000);

        $result = [
            'id' => $id,
            'type' => $type,
            'command' => $command,
            'status' => $code === 0 ? 'passed' : 'failed',
            'exit_code' => $code,
            'duration_ms' => $durationMs,
        ];
        $report['groups'][$name][] = $result;
        $report['summary']['checks']++;
        $report['summary'][$code === 0 ? 'passed' : 'failed']++;
        echo "CHECK_RESULT " . json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;

        if ($code !== 0) {
            $report['summary']['duration_ms'] = (int)round((hrtime(true) - $runStarted) / 1_000_000);
            writeRequiredCheckReport($report);
            fwrite(STDERR, "FAILED [{$name}/{$id}] {$command} (exit {$code}, {$durationMs} ms)\n");
            exit($code ?: 1);
        }
    }
}

$report['summary']['duration_ms'] = (int)round((hrtime(true) - $runStarted) / 1_000_000);
writeRequiredCheckReport($report);
echo "\nREQUIRED CHECK GROUPS PASSED: " . implode(', ', $groups) . " ({$report['summary']['checks']} checks, {$report['summary']['duration_ms']} ms)" . PHP_EOL;

function writeRequiredCheckReport(array $report): void
{
    $path = trim((string)getenv('REQUIRED_CHECK_REPORT'));
    if ($path === '') {
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "Unable to create required-check report directory: {$dir}\n");
        return;
    }
    $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($path, $json . PHP_EOL) === false) {
        fwrite(STDERR, "Unable to write required-check report: {$path}\n");
    }
}
