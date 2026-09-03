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

$classificationPath = $root . '/tests/checks_classification.php';
$classification = is_file($classificationPath) ? require $classificationPath : null;
if (!is_array($classification)) {
    fwrite(STDERR, "Check classification missing or invalid\n");
    exit(2);
}

$classifiedPaths = [];
$classificationCounts = [];
foreach (['optional','manual','compatibility','infrastructure'] as $class) {
    $items = $classification[$class] ?? null;
    if (!is_array($items)) {
        fwrite(STDERR, "Check classification is missing class {$class}\n");
        exit(2);
    }
    $classificationCounts[$class] = count($items);
    foreach ($items as $path => $reason) {
        if (!is_string($path) || !preg_match('#^tests/run_[^/]+\.php$#', $path) || !is_string($reason) || trim($reason) === '') {
            fwrite(STDERR, "Invalid {$class} check classification\n");
            exit(2);
        }
        if (isset($classifiedPaths[$path])) {
            fwrite(STDERR, "Check is classified more than once: {$path}\n");
            exit(2);
        }
        $classifiedPaths[$path] = ['class'=>$class,'reason'=>trim($reason)];
    }
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

$files = glob($root . '/tests/run_*.php') ?: [];
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
    if (preg_match('#^tests/run_[^/]+\.php$#', $path)) {
        $coverage[$path] = ($coverage[$path] ?? 0) + 1;
    }
}

$covered = [];
$orphans = [];
$required = [];
$classifiedNonRequired = [];
$duplicateRegressionAssignments = [];
foreach ($discovered as $relative) {
    $count = $coverage[$relative] ?? 0;
    if ($count > 0) {
        $required[] = $relative;
        $covered[] = $relative;
    } elseif (isset($classifiedPaths[$relative])) {
        $classifiedNonRequired[] = ['path'=>$relative] + $classifiedPaths[$relative];
        $covered[] = $relative;
    } else {
        $orphans[] = $relative;
    }
    if ($count > 1) $duplicateRegressionAssignments[$relative] = $count;
}

$missingClassified = [];
foreach (array_keys($classifiedPaths) as $relative) {
    if (!in_array($relative, $discovered, true)) {
        $missingClassified[] = $relative;
    }
    if (($coverage[$relative] ?? 0) > 0) {
        $duplicateRegressionAssignments[$relative] = (int)$coverage[$relative];
    }
}

$duplicateCommands = [];
foreach ($commandOccurrences as $command => $count) {
    if ($count > 1) $duplicateCommands[$command] = $count;
}

$missingReferenced = array_values(array_unique($missingReferenced));
$result = [
    'ok' => $orphans === [] && $missingReferenced === [] && $missingClassified === [] && $duplicateRegressionAssignments === [] && $duplicateCommands === [],
    'schema_version' => 3,
    'generated_at' => gmdate('c'),
    'groups' => $groups,
    'discovered_regressions' => $discovered,
    'covered_regressions' => $covered,
    'required_checks' => $required,
    'classified_nonrequired_checks' => $classifiedNonRequired,
    'classification_counts' => $classificationCounts,
    'orphan_regressions' => $orphans,
    'missing_referenced_checks' => $missingReferenced,
    'missing_classified_checks' => $missingClassified,
    'duplicate_regression_assignments' => $duplicateRegressionAssignments,
    'duplicate_commands' => $duplicateCommands,
    'counts' => [
        'groups' => count($groups),
        'commands' => count($commands),
        'discovered' => count($discovered),
        'covered' => count($covered),
        'required' => count($required),
        'classified_nonrequired' => count($classifiedNonRequired),
        'orphans' => count($orphans),
        'missing_referenced' => count($missingReferenced),
        'missing_classified' => count($missingClassified),
        'duplicate_regression_assignments' => count($duplicateRegressionAssignments),
        'duplicate_commands' => count($duplicateCommands),
    ],
];

echo json_encode(
    $result,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (in_array('--compact', $argv, true) ? 0 : JSON_PRETTY_PRINT)
) . "\n";

exit($result['ok'] ? 0 : 1);
