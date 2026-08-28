<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$manifestPath = $root . '/tests/required_checks_manifest.php';
$manifest = is_file($manifestPath) ? require $manifestPath : null;
if (!is_array($manifest)) {
    fwrite(STDERR, "Required-check manifest missing or invalid\n");
    exit(2);
}

$commands = [];
$groups = [];
foreach ($manifest as $group => $items) {
    if (!is_string($group) || $group === '' || !is_array($items)) {
        fwrite(STDERR, "Required-check manifest has invalid group\n");
        exit(2);
    }
    $groups[$group] = count($items);
    foreach ($items as $command) {
        if (!is_string($command) || trim($command) === '') {
            fwrite(STDERR, "Required-check manifest has invalid command in {$group}\n");
            exit(2);
        }
        $commands[] = ['group'=>$group, 'command'=>trim($command)];
    }
}

$files = glob($root . '/tests/run_*_regression.php') ?: [];
sort($files);
$discovered = array_map(static fn(string $file): string => 'tests/' . basename($file), $files);

$coverage = [];
$missingReferenced = [];
$commandOccurrences = [];
foreach ($commands as $entry) {
    $command = $entry['command'];
    $commandOccurrences[$command] = ($commandOccurrences[$command] ?? 0) + 1;
    if (!preg_match('/^php\s+([^\s]+\.php)(?:\s|$)/', $command, $m)) {
        continue;
    }
    $path = ltrim($m[1], './');
    if (!is_file($root . '/' . $path)) {
        $missingReferenced[] = $path;
        continue;
    }
    if (preg_match('#^tests/run_.*_regression\.php$#', $path)) {
        $coverage[$path] = ($coverage[$path] ?? 0) + 1;
    }
}

$covered = [];
$orphans = [];
$duplicateRegressionAssignments = [];
foreach ($discovered as $relative) {
    $count = $coverage[$relative] ?? 0;
    if ($count > 0) $covered[] = $relative; else $orphans[] = $relative;
    if ($count > 1) $duplicateRegressionAssignments[$relative] = $count;
}

$duplicateCommands = [];
foreach ($commandOccurrences as $command => $count) {
    if ($count > 1) $duplicateCommands[$command] = $count;
}

$missingReferenced = array_values(array_unique($missingReferenced));
$result = [
    'ok' => $orphans === [] && $missingReferenced === [] && $duplicateRegressionAssignments === [] && $duplicateCommands === [],
    'schema_version' => 2,
    'generated_at' => gmdate('c'),
    'groups' => $groups,
    'discovered_regressions' => $discovered,
    'covered_regressions' => $covered,
    'orphan_regressions' => $orphans,
    'missing_referenced_checks' => $missingReferenced,
    'duplicate_regression_assignments' => $duplicateRegressionAssignments,
    'duplicate_commands' => $duplicateCommands,
    'counts' => [
        'groups' => count($groups),
        'commands' => count($commands),
        'discovered' => count($discovered),
        'covered' => count($covered),
        'orphans' => count($orphans),
        'missing_referenced' => count($missingReferenced),
        'duplicate_regression_assignments' => count($duplicateRegressionAssignments),
        'duplicate_commands' => count($duplicateCommands),
    ],
];

echo json_encode(
    $result,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (in_array('--compact', $argv, true) ? 0 : JSON_PRETTY_PRINT)
) . "\n";

exit($result['ok'] ? 0 : 1);
