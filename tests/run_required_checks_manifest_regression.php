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
manifestCheck('PR workflow preserves all final regression gates including native MySQL', strpos($workflow, 'regression:') !== false && strpos($workflow, 'name: regression') !== false && strpos($workflow, 'needs: [change-scope, inventory, php-syntax, required, prepared-mysql, oauth]') !== false && strpos($workflow, 'test "$PREPARED_MYSQL_RESULT" = success') !== false);
manifestCheck('PR workflow persists required-check timing artifacts', strpos($workflow, 'REQUIRED_CHECK_REPORT:') !== false && strpos($workflow, 'actions/upload-artifact@v4') !== false && strpos($workflow, 'required-check-report-') !== false);

// Read the actual deployment graph and execute its aggregate. A missing lane
// must not turn a successful subset into permission to synchronize production.
$deployment = str_replace("\r\n", "\n", (string)file_get_contents($root . '/.github/workflows/deploy.yml'));
$deployJobs = [];
$jobRows = [];
$jobsSource = explode("\njobs:\n", $deployment, 2)[1] ?? '';
preg_match_all('/^  ([a-z][a-z0-9-]*):\n(.*?)(?=^  [a-z][a-z0-9-]*:|\z)/ms', $jobsSource, $jobRows, PREG_SET_ORDER);
$uniqueJobs = true;
foreach ($jobRows as $row) {
    if (isset($deployJobs[$row[1]])) $uniqueJobs = false;
    $deployJobs[$row[1]] = $row[2];
}
$inventoryJob = $deployJobs['verify-inventory'] ?? '';
$syntaxJob = $deployJobs['verify-syntax'] ?? '';
$groupsJob = $deployJobs['verify-groups'] ?? '';
$verifyJob = $deployJobs['verify'] ?? '';
$deployJob = $deployJobs['deploy'] ?? '';
manifestCheck('deployment retains distinct source checks, aggregate and install jobs',
    $uniqueJobs && $inventoryJob !== '' && $syntaxJob !== '' && $groupsJob !== '' && $verifyJob !== '' && $deployJob !== '');
manifestCheck('deployment preflight runs architecture once with inventory first',
    preg_match('/^        run: php tests\/run_required_group\.php architecture$/m', $inventoryJob) === 1
    && ($manifest['architecture'][0] ?? null) === 'php tests/run_required_checks_inventory_regression.php');

$include = [];
$laneRows = [];
preg_match('/^      matrix:\n        include:\n((?: {10,}[^\n]*\n)+)/m', $groupsJob, $include);
$lanePattern = '/^          - lane: ([a-z][a-z0-9-]*)\n            groups: ([a-z][a-z ]*)\n/m';
preg_match_all($lanePattern, $include[1] ?? '', $laneRows, PREG_SET_ORDER);
$lanes = [];
$selectedGroups = ['architecture'];
foreach ($laneRows as $row) {
    $lanes[] = $row[1];
    array_push($selectedGroups, ...preg_split('/\s+/', trim($row[2])));
}
$expectedGroups = array_keys($catalog);
sort($expectedGroups);
sort($selectedGroups);
manifestCheck('two deployment lanes plus preflight cover every catalog group exactly once',
    count($laneRows) === 2 && count(array_unique($lanes)) === 2
    && trim(preg_replace($lanePattern, '', $include[1] ?? '')) === ''
    && $selectedGroups === $expectedGroups);
manifestCheck('deployment lanes require inventory and limit concurrent runners',
    preg_match('/^    needs: verify-inventory$/m', $groupsJob) === 1
    && preg_match('/^      fail-fast: false$/m', $groupsJob) === 1
    && preg_match('/^      max-parallel: 2$/m', $groupsJob) === 1
    && str_contains($groupsJob, 'DEPLOY_CHECK_GROUPS: ${{ matrix.groups }}')
    && str_contains($groupsJob, 'for check_group in $DEPLOY_CHECK_GROUPS; do')
    && str_contains($groupsJob, 'php tests/run_required_group.php "$check_group"'));
manifestCheck('deployment syntax still covers every non-vendor PHP file',
    str_contains($syntaxJob, "run: find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l"));

$runtimePinned = true;
$noIgnoredChecks = true;
foreach ([$inventoryJob, $syntaxJob, $groupsJob] as $job) {
    $runtimePinned = $runtimePinned
        && preg_match('/^    runs-on: ubuntu-latest$/m', $job) === 1
        && str_contains($job, "        uses: actions/checkout@v4\n        with:\n          ref: \${{ github.sha }}")
        && str_contains($job, "        uses: shivammathur/setup-php@v2\n        with:\n          php-version: '8.3'\n          extensions: mbstring\n          coverage: none");
    $noIgnoredChecks = $noIgnoredChecks && preg_match('/^\s+(?:if|continue-on-error|shell|defaults):/m', $job) === 0;
}
manifestCheck('every deployment source check uses the exact SHA and original PHP 8.3 environment', $runtimePinned);
manifestCheck('deployment source checks cannot be conditionally skipped or ignore failures',
    $noIgnoredChecks && preg_match('/^defaults:/m', $deployment) === 0);
manifestCheck('deployment aggregate always requires all three source-check results',
    preg_match('/^    if: always\(\)$/m', $verifyJob) === 1
    && preg_match('/^    needs: \[verify-inventory, verify-syntax, verify-groups\]$/m', $verifyJob) === 1
    && preg_match_all('/^\s+if:/m', $verifyJob) === 1
    && preg_match('/^\s+(?:continue-on-error|shell|defaults):/m', $verifyJob) === 0);
manifestCheck('production synchronization still requires successful verification',
    preg_match('/^    needs: verify$/m', $deployJob) === 1
    && preg_match('/^    (?:if|continue-on-error):/m', $deployJob) === 0
    && str_contains($deployment, "concurrency:\n  group: production-deploy\n  cancel-in-progress: false"));

$gateInputs = [
    'INVENTORY_RESULT' => 'verify-inventory',
    'SYNTAX_RESULT' => 'verify-syntax',
    'CHECK_GROUPS_RESULT' => 'verify-groups',
];
$environmentRows = [];
preg_match_all('/^          ([A-Z_]+): ([^\n]+)$/m', $verifyJob, $environmentRows, PREG_SET_ORDER);
$boundInputs = count($environmentRows) === count($gateInputs);
$seenInputs = [];
foreach ($environmentRows as $row) {
    $input = $row[1];
    $boundInputs = $boundInputs && !isset($seenInputs[$input]) && isset($gateInputs[$input])
        && $row[2] === '${{ needs.' . ($gateInputs[$input] ?? '') . '.result }}';
    $seenInputs[$input] = true;
}
manifestCheck('deployment aggregate inputs bind to actual dependency results', $boundInputs);

$gateBlocks = [];
preg_match_all('/^        run: \|\n((?: {10,}[^\n]*\n)+)/m', $verifyJob, $gateBlocks, PREG_SET_ORDER);
$gate = count($gateBlocks) === 1 ? (string)preg_replace('/^ {10}/m', '', $gateBlocks[0][1]) : '';
// Only the finite status-test body is executable in this fixture. Keep the
// source text itself; the positive/negative checks below do not reimplement it.
$gateLines = array_values(array_filter(explode("\n", trim($gate)), static fn($line) => trim($line) !== ''));
$safeGate = count($gateLines) === 3;
foreach ($gateLines as $line) {
    $safeGate = $safeGate && preg_match('/^test "\$(?:INVENTORY_RESULT|SYNTAX_RESULT|CHECK_GROUPS_RESULT)" = success$/D', $line) === 1;
}
manifestCheck('deployment aggregate exposes one bounded executable status gate', $safeGate);

function deployManifestGateExit(string $script, array $results): int
{
    // The workflow uses the standard Actions bash -e shell. A small environment
    // prevents local BASH_ENV or unrelated settings from changing this fixture.
    $environment = array_merge(['PATH' => getenv('PATH') ?: '/usr/bin:/bin'], $results);
    $process = proc_open(['bash', '--noprofile', '--norc', '-e', '-c', $script],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) return -1;
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($process);
}

if ($safeGate) {
    $success = array_fill_keys(array_keys($gateInputs), 'success');
    manifestCheck('actual deployment gate accepts all successful checks', deployManifestGateExit($gate, $success) === 0);
    foreach ($gateInputs as $input => $_job) {
        foreach (['failure', 'skipped', 'cancelled', ''] as $result) {
            $states = array_replace($success, [$input => $result]);
            $code = deployManifestGateExit($gate, $states);
            manifestCheck('actual deployment gate rejects ' . $input . '=' . ($result === '' ? 'missing' : $result), $code > 0);
        }
    }
}

// Distinguish a source failure from mutable release admission in the existing
// job summary. The summary must never certify a skipped/failed mandatory step.
$sourceStep = [];
$admissionStep = [];
$summaryStep = [];
preg_match('/^      - name: Require every gate\n(.*?)(?=^      - name:|\z)/ms', $workflow, $sourceStep);
preg_match('/^      - name: Respect ready release priority\n(.*?)(?=^      - name:|\z)/ms', $workflow, $admissionStep);
preg_match('/^      - name: Summarize source checks and release admission\n(.*?)(?=^      - name:|\z)/ms', $workflow, $summaryStep);
manifestCheck('summary observes the original fatal source and admission steps',
    str_contains($sourceStep[1] ?? '', "        id: source_checks\n")
    && str_contains($admissionStep[1] ?? '', "        id: release_admission\n")
    && !str_contains($sourceStep[1] ?? '', 'continue-on-error:')
    && !str_contains($admissionStep[1] ?? '', 'continue-on-error:')
    && !str_contains($sourceStep[1] ?? '', '        if:')
    && str_contains($admissionStep[1] ?? '', "        if: github.event_name == 'pull_request'\n")
    && str_contains($admissionStep[1] ?? '', '        run: node tools/release_priority.js'));
$summarySource = $summaryStep[1] ?? '';
manifestCheck('informational summary binds actual step outcomes without a token',
    str_contains($summarySource, "        if: always()\n")
    && str_contains($summarySource, "        continue-on-error: true\n")
    && str_contains($summarySource, 'SOURCE_CHECK_RESULT: ${{ steps.source_checks.outcome }}')
    && str_contains($summarySource, 'RELEASE_ADMISSION_RESULT: ${{ steps.release_admission.outcome }}')
    && str_contains($summarySource, 'CI_EVENT_NAME: ${{ github.event_name }}')
    && !str_contains($summarySource, 'GITHUB_TOKEN'));
$summaryBlocks = [];
preg_match_all('/^        run: \|\n((?: {10,}[^\n]*\n)+)/m', $summarySource, $summaryBlocks, PREG_SET_ORDER);
$summaryScript = count($summaryBlocks) === 1 ? (string)preg_replace('/^ {10}/m', '', $summaryBlocks[0][1]) : '';
manifestCheck('summary exposes one executable shell body', $summaryScript !== '');
if ($summaryScript !== '') {
    $summaryCases = 0;
    $summaryCasesPassed = true;
    foreach (['success', 'failure', 'skipped', 'cancelled', '', 'unknown'] as $sourceResult) {
        foreach (['success', 'failure', 'skipped', 'cancelled', '', 'unknown'] as $admissionResult) {
            foreach (['pull_request', 'workflow_dispatch', 'push'] as $eventName) {
                $summaryPath = tempnam(sys_get_temp_dir(), 'ci-summary-');
                if ($summaryPath === false) throw new RuntimeException('summary_fixture_unavailable');
                try {
                    $code = deployManifestGateExit($summaryScript, [
                        'SOURCE_CHECK_RESULT' => $sourceResult,
                        'RELEASE_ADMISSION_RESULT' => $admissionResult,
                        'CI_EVENT_NAME' => $eventName,
                        'GITHUB_STEP_SUMMARY' => $summaryPath,
                    ]);
                    $text = (string)file_get_contents($summaryPath);
                    $sourceLabel = $sourceResult === 'success' ? 'PASSED' : ($sourceResult === 'failure' ? 'FAILED' : 'NOT CONFIRMED');
                    $releaseLabel = 'NOT CONFIRMED';
                    if ($sourceResult === 'success' && $eventName === 'workflow_dispatch') $releaseLabel = 'NOT REQUESTED';
                    if ($sourceResult === 'success' && $eventName === 'pull_request') {
                        if ($admissionResult === 'success') $releaseLabel = 'PASSED';
                        if ($admissionResult === 'failure') $releaseLabel = 'REFUSED';
                    }
                    $summaryCasesPassed = $summaryCasesPassed && $code === 0
                        && str_contains($text, 'Regression source checks: ' . $sourceLabel . '.')
                        && str_contains($text, 'Release priority: ' . $releaseLabel . '.')
                        && substr_count($text, 'Regression source checks: ') === 1
                        && substr_count($text, 'Release priority: ') === 1
                        && str_contains($text, 'This summary is informational');
                    $summaryCases++;
                } finally { unlink($summaryPath); }
            }
        }
    }
    manifestCheck('actual summary preserves all ' . $summaryCases . ' source/admission/event outcomes', $summaryCasesPassed);
}

$total = $passed + $failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
