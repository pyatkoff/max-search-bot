<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$production = (string)file_get_contents($root . '/.github/workflows/deploy.yml');
$diagnostics = (string)file_get_contents($root . '/.github/workflows/publish-conversation-diagnostics.yml');
$standby = (string)file_get_contents($root . '/.github/workflows/deploy-standby.yml');

function deploymentOwnershipAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

deploymentOwnershipAssert(substr_count($production, 'php tools/conversation_db.php migrate') === 1, 'production deploy must be the single automatic migration owner');
deploymentOwnershipAssert(str_contains($production, "push:\n    branches: [main]"), 'production deploy must remain automatic on main');
deploymentOwnershipAssert(str_contains($production, 'Ensure Metrika offline uploader cron'), 'production deploy must own the Metrika uploader cron');
deploymentOwnershipAssert(str_contains($production, 'cron_metrika.php'), 'production deploy must install the existing Metrika uploader');
deploymentOwnershipAssert(str_contains($production, 'METRIKA_CRON_OUTCOME'), 'deploy telemetry must report the Metrika uploader result');

deploymentOwnershipAssert(!str_contains($diagnostics, 'php tools/conversation_db.php migrate'), 'diagnostics must remain read-only');
deploymentOwnershipAssert(str_contains($diagnostics, 'workflow_run:'), 'production diagnostics must follow deploy completion');
deploymentOwnershipAssert(str_contains($diagnostics, 'workflows: [Deploy production]'), 'production diagnostics must follow the canonical deploy workflow');
deploymentOwnershipAssert(str_contains($diagnostics, "github.event.workflow_run.conclusion == 'success'"), 'production diagnostics must require successful deploy');
deploymentOwnershipAssert(str_contains($diagnostics, 'TARGET_SHA:'), 'production diagnostics must bind to the deployed SHA');

deploymentOwnershipAssert(str_contains($standby, 'workflow_dispatch:'), 'standby recovery must remain manually available');
deploymentOwnershipAssert(!str_contains($standby, "push:\n    branches: [main]"), 'retired standby deploy must not race every main push');

echo "deployment ownership contract OK\n";


// Run the actual remote cron body with an isolated crontab and synthetic
// read-only observers. A barrier fails if the observers start serially.
function deploymentCronAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function deploymentCronRun(array $command, array $environment, ?callable $during = null): array
{
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, $environment);
    deploymentCronAssert(is_resource($process), 'fixture process must start');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $deadline = microtime(true) + 12;
    $exit = null;
    try {
        do {
            $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $state = proc_get_status($process);
            if (!$state['running']) {
                $exit = $state['exitcode'];
                break;
            }
            if ($during !== null) {
                $during($process);
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if ($exit === null) {
            proc_terminate($process, 15);
            throw new RuntimeException('fixture process did not finish: '.$output);
        }
        $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
    }
    return [$exit >= 0 ? $exit : $closed, $output];
}
function deploymentCronRemove(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($directory);
}

$cronStart = strpos($production, '      - name: Ensure Metrika offline uploader cron');
$cronEnd = strpos($production, '      - name: Production smoke checks', $cronStart ?: 0);
deploymentCronAssert($cronStart !== false && $cronEnd !== false, 'canonical cron step must exist');
$cronSection = substr($production, $cronStart, $cronEnd - $cronStart);
$remoteStart = strpos($cronSection, " '\n");
$remoteEnd = strrpos($cronSection, "\n          '");
deploymentCronAssert($remoteStart !== false && $remoteEnd !== false, 'remote cron body must be bounded');
$cronBody = preg_replace('/^ {12}/m', '', substr($cronSection, $remoteStart + 3, $remoteEnd - $remoteStart - 3));
$pairSource = (string)file_get_contents($root.'/tools/verify_deploy_crons.sh');
$monitorReconcileSource = (string)file_get_contents($root.'/tools/reconcile_modeled_conversion_monitor_cron.sh');
$fixture = sys_get_temp_dir().'/deploy-cron-pair-'.bin2hex(random_bytes(8));
mkdir($fixture, 0700, true);

$observerSource = <<<'PHP_FIXTURE'
<?php
if ($argc !== 2 || $argv[1] !== 'verify-cron') exit(90);
$name = basename(__FILE__, '_work.php');
$peer = $name === 'hotel' ? 'development' : 'hotel';
$directory = getenv('CRON_FIXTURE_OBSERVED');
$mode = getenv('CRON_FIXTURE_MODE');
file_put_contents($directory.'/'.$name, (string)getmypid());
$deadline = microtime(true) + 5;
while (!is_file($directory.'/'.$peer) && microtime(true) < $deadline) usleep(10000);
if (!is_file($directory.'/'.$peer)) {
    fwrite(STDERR, "FAIL observers started serially\n");
    exit(91);
}
if ($mode === 'signal') {
    while (true) usleep(100000);
}
$failed = $mode === 'both-fail' || $mode === $name.'-fail';
echo json_encode(['observer'=>$name,'ok'=>!$failed,'provider_calls'=>0])."\n";
exit($failed ? 1 : 0);
PHP_FIXTURE;

$monitorStatusSource = <<<'PYTHON_FIXTURE'
import sys
if sys.argv[1:] != ['--status']:
    raise SystemExit(90)
print('{"schema_version":1,"project":"anytour","counter_id":"98615635","role":"pipeline","configured":false}')
PYTHON_FIXTURE;

$crontabSource = <<<'PHP_FIXTURE'
#!/usr/bin/env php
<?php
$state = getenv('CRON_FIXTURE_STATE');
if ($argc === 2 && $argv[1] === '-l') {
    echo file_get_contents($state);
    exit;
}
if ($argc !== 2 || !is_file($argv[1])) exit(92);
$number = (int)@file_get_contents($state.'.count') + 1;
file_put_contents($state.'.count', (string)$number);
if (getenv('CRON_FIXTURE_MODE') === 'install-fail' && $number === 5) exit(73);
$content = file_get_contents($argv[1]);
file_put_contents($state.'.write-'.$number, $content);
file_put_contents($state, $content);
PHP_FIXTURE;

try {
    foreach (['success', 'hotel-fail', 'development-fail', 'both-fail', 'install-fail', 'signal'] as $mode) {
        $case = $fixture.'/'.$mode;
        foreach (['tools','bin','tmp','observed'] as $directory) mkdir($case.'/'.$directory, 0700, true);
        file_put_contents($case.'/tools/verify_deploy_crons.sh', $pairSource);
        file_put_contents($case.'/tools/reconcile_modeled_conversion_monitor_cron.sh', $monitorReconcileSource);
        file_put_contents($case.'/tools/modeled_conversion_monitor_local.py', $monitorStatusSource);
        deploymentCronAssert(file_get_contents($case.'/tools/reconcile_modeled_conversion_monitor_cron.sh') === $monitorReconcileSource,
            'fixture executes the byte-exact pipeline reconciler');
        file_put_contents($case.'/tools/hotel_work.php', $observerSource);
        file_put_contents($case.'/tools/development_work.php', $observerSource);
        file_put_contents($case.'/bin/crontab', $crontabSource);
        chmod($case.'/bin/crontab', 0700);
        $state = $case.'/crontab';
        $operatorLine = "17 * * * * /usr/bin/php /unrelated/operator-task.php";
        $initial = $operatorLine."\n"
            ."7 * * * * /usr/bin/php ".$case."/tools/hotel_work.php tick\n"
            ."9 * * * * /usr/bin/php ".$case."/tools/development_work.php tick\n";
        file_put_contents($state, $initial);
        $environment = array_merge(getenv(), [
            'PATH'=>$case.'/bin:'.getenv('PATH'),
            'TMPDIR'=>$case.'/tmp',
            'CRON_FIXTURE_MODE'=>$mode,
            'CRON_FIXTURE_STATE'=>$state,
            'CRON_FIXTURE_OBSERVED'=>$case.'/observed',
        ]);
        if ($mode === 'signal') {
            $terminated = false;
            [$code, $output] = deploymentCronRun(['bash', $case.'/tools/verify_deploy_crons.sh'], $environment,
                static function ($process) use ($case, &$terminated): void {
                    if (!$terminated && is_file($case.'/observed/hotel') && is_file($case.'/observed/development')) {
                        $terminated = true;
                        proc_terminate($process, 15);
                    }
                });
            deploymentCronAssert($terminated && $code !== 0, 'termination must fail the observer pair');
            foreach (['hotel', 'development'] as $name) {
                $pid = (int)file_get_contents($case.'/observed/'.$name);
                $command = @file_get_contents('/proc/'.$pid.'/cmdline');
                deploymentCronAssert($command === false || !str_contains($command, $case), 'termination must reap '.$name);
            }
            deploymentCronAssert(glob($case.'/tmp/*') === [], 'termination must remove temporary observer logs');
            continue;
        }
        $script = str_replace(
            'root=/var/www/anytoour/data/www/app.anytoour.ru',
            'root='.escapeshellarg($case),
            $cronBody,
            $replaced
        );
        deploymentCronAssert($replaced === 1, 'fixture changes only the canonical checkout path');
        [$code, $output] = deploymentCronRun(['bash','-c',$script], $environment);
        deploymentCronAssert(!str_contains($output, 'started serially'), 'observers must overlap: '.$mode);
        $expectedCode = match ($mode) {
            'success'=>0, 'development-fail'=>2, 'install-fail'=>73, default=>1,
        };
        deploymentCronAssert($code === $expectedCode, $mode.' exit: '.$code.' '.$output);
        $actual = (string)file_get_contents($state);
        deploymentCronAssert(str_contains($actual, $operatorLine), 'unrelated cron entry must survive '.$mode);
        if ($mode === 'success') {
            deploymentCronAssert((int)file_get_contents($state.'.count') === 5, 'only the existing five serial writes are performed');
            deploymentCronAssert(str_contains($output, 'MODELED_PIPELINE_MONITOR=inactive'), 'new canonical monitor reconciliation is inactive without a cutover marker');
            deploymentCronAssert(substr_count($actual, 'HOTEL_WORK_TRIGGER=cron') === 1, 'hotel schedule remains present once');
            deploymentCronAssert(substr_count($actual, 'DEVELOPMENT_WORK_TRIGGER=cron') === 1, 'development schedule remains present once');
            deploymentCronAssert(str_contains($output, '"observer":"hotel","ok":true'), 'hotel result is required');
            deploymentCronAssert(str_contains($output, '"observer":"development","ok":true'), 'development result is required');
        } else {
            $boundary = $mode === 'development-fail' ? 4 : 3;
            deploymentCronAssert($actual === file_get_contents($state.'.write-'.$boundary), 'correct rollback boundary for '.$mode);
        }
        if ($mode === 'install-fail') {
            deploymentCronAssert(glob($case.'/observed/*') === [], 'failed configuration must not start observers');
        } else {
            deploymentCronAssert(count(glob($case.'/observed/*')) === 2, 'exactly the two existing observers run');
        }
        deploymentCronAssert(glob($case.'/tmp/*') === [], 'temporary snapshots and logs removed for '.$mode);
    }
    echo "parallel deploy cron observation and rollback OK\n";
} finally {
    deploymentCronRemove($fixture);
}
