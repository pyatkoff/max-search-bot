<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$runnerPath = $root . '/tests/run_required_checks.sh';
$workflowPath = $root . '/.github/workflows/regression-tests.yml';

$runner = is_file($runnerPath) ? (string)file_get_contents($runnerPath) : '';
$workflow = is_file($workflowPath) ? (string)file_get_contents($workflowPath) : '';

$files = glob($root . '/tests/run_*_regression.php') ?: [];
sort($files);

$discovered = [];
$covered = [];
$orphans = [];
foreach ($files as $file) {
    $relative = 'tests/' . basename($file);
    $discovered[] = $relative;
    $isCovered = strpos($runner, $relative) !== false || strpos($workflow, $relative) !== false;
    if ($isCovered) {
        $covered[] = $relative;
    } else {
        $orphans[] = $relative;
    }
}

preg_match_all("/'([^']*tests\\/run_[^']+)'/", $runner, $matches);
$commands = array_values(array_unique($matches[1] ?? []));
$missingReferenced = [];
foreach ($commands as $command) {
    if (!preg_match('/(?:php\\s+)?(tests\\/run_[^\\s]+\\.php)/', $command, $m)) {
        continue;
    }
    if (!is_file($root . '/' . $m[1])) {
        $missingReferenced[] = $m[1];
    }
}

$result = [
    'ok' => $orphans === [] && $missingReferenced === [],
    'schema_version' => 1,
    'generated_at' => gmdate('c'),
    'discovered_regressions' => $discovered,
    'covered_regressions' => $covered,
    'orphan_regressions' => $orphans,
    'missing_referenced_regressions' => array_values(array_unique($missingReferenced)),
    'counts' => [
        'discovered' => count($discovered),
        'covered' => count($covered),
        'orphans' => count($orphans),
        'missing_referenced' => count(array_unique($missingReferenced)),
    ],
];

echo json_encode(
    $result,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (in_array('--compact', $argv, true) ? 0 : JSON_PRETTY_PRINT)
) . "\n";

exit($result['ok'] ? 0 : 1);
