<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = require $root . '/tests/required_checks_manifest.php';
$catalog = require $root . '/tests/required_checks_catalog.php';
$runner = (string)file_get_contents($root . '/tests/run_required_checks.sh');
$groupRunner = (string)file_get_contents($root . '/tests/run_required_group.php');
$workflow = (string)file_get_contents($root . '/.github/workflows/regression-tests.yml');

$passed = 0;
$failed = 0;
function manifestCheck(string $name, bool $ok): void {
    global $passed, $failed;
    if ($ok) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n"; $failed++;
}

manifestCheck('manifest exposes five stable required groups', is_array($manifest) && array_keys($manifest) === ['architecture','dialogue','website','manager','diagnostics']);
manifestCheck('every manifest group has commands', count(array_filter($manifest, static fn($items) => is_array($items) && count($items) > 0)) === 5);
manifestCheck('catalog preserves five stable groups', is_array($catalog) && array_keys($catalog) === array_keys($manifest));

$ids = [];
$metadataValid = true;
$commandCount = 0;
foreach ($catalog as $group => $checks) {
    if (count($checks) !== count($manifest[$group] ?? [])) { $metadataValid = false; break; }
    foreach ($checks as $check) {
        $commandCount++;
        if (!is_array($check) || ($check['group'] ?? null) !== $group || empty($check['id']) || empty($check['type']) || ($check['required'] ?? null) !== true || empty($check['command'])) {
            $metadataValid = false;
            break 2;
        }
        if (isset($ids[$check['id']])) {
            $metadataValid = false;
            break 2;
        }
        $ids[$check['id']] = true;
    }
}
manifestCheck('catalog gives every required command stable unique metadata', $metadataValid && $commandCount > 0);
manifestCheck('canonical full runner delegates to grouped runner', strpos($runner, 'php tests/run_required_group.php all') !== false);
manifestCheck('canonical full runner still performs PHP syntax validation', strpos($runner, "find . -type f -name '*.php'") !== false && strpos($runner, 'php -l') !== false);
manifestCheck('group runner loads metadata catalog', strpos($groupRunner, "required_checks_catalog.php") !== false && strpos($groupRunner, "if (\$group === 'all')") !== false);
manifestCheck('group runner records per-check duration and machine-readable result', strpos($groupRunner, 'duration_ms') !== false && strpos($groupRunner, 'CHECK_RESULT ') !== false && strpos($groupRunner, 'REQUIRED_CHECK_REPORT') !== false);
manifestCheck('PR workflow has matrix groups', strpos($workflow, 'matrix:') !== false && strpos($workflow, 'group: [architecture, dialogue, website, manager, diagnostics]') !== false);
manifestCheck('PR workflow executes group runner', strpos($workflow, 'php tests/run_required_group.php "${{ matrix.group }}"') !== false);
manifestCheck('PR workflow preserves final regression gate', strpos($workflow, 'regression:') !== false && strpos($workflow, 'name: regression') !== false && strpos($workflow, 'needs: [inventory, php-syntax, required]') !== false);
manifestCheck('PR workflow persists required-check timing artifacts', strpos($workflow, 'REQUIRED_CHECK_REPORT:') !== false && strpos($workflow, 'actions/upload-artifact@v4') !== false && strpos($workflow, 'required-check-report-') !== false);

$total = $passed + $failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
