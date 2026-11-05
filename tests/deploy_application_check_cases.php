<?php

declare(strict_types=1);

// Execute the real Bash entry point against a disposable Git checkout. Stub
// only the existing application probes: no network, real config or DB access.
$checkCount = 0;
$assertCheck = static function (bool $ok, string $name) use (&$checkCount): void {
    if (!$ok) throw new RuntimeException('application check: ' . $name);
    ++$checkCount;
};
$execute = static function (array $command, string $cwd, array $environment): array {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
    if (!is_resource($process)) throw new RuntimeException('application fixture process unavailable');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
};
$temp = sys_get_temp_dir() . '/deploy-application-' . bin2hex(random_bytes(12));
$repo = $temp . '/repo';
$bin = $temp . '/bin';
$trace = $temp . '/calls.jsonl';
mkdir($repo . '/tools', 0700, true);
mkdir($bin, 0700, true);
$source = dirname(__DIR__) . '/tools/deploy_application_check.sh';
copy($source, $repo . '/tools/deploy_application_check.sh');
$environment = [
    'PATH' => $bin . ':/usr/bin:/bin', 'HOME' => $temp, 'LC_ALL' => 'C',
    'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null',
    'CASE_RECORDER' => $temp . '/record.php', 'CASE_TRACE' => $trace,
    'CASE_ROOT' => $repo, 'CASE_FAIL' => '', 'CASE_DRIFT' => '',
];
$record = <<<'PHP'
<?php
$command = array_slice($argv, 1);
file_put_contents(getenv('CASE_TRACE'), json_encode($command, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
if (getenv('CASE_DRIFT') === '1' && $command === ['php', 'tools/virtual_reply_learning.php', 'control-status']) {
    file_put_contents(getenv('CASE_ROOT') . '/drift.txt', "changed source\n");
    $code = 0;
    passthru('git add drift.txt && git -c user.name=Fixture -c user.email=fixture@example.invalid commit -qm drift', $code);
    if ($code !== 0) exit(93);
}
if (getenv('CASE_FAIL') === json_encode($command, JSON_THROW_ON_ERROR)) exit(23);
PHP;
file_put_contents($temp . '/record.php', $record);
$php = escapeshellarg(PHP_BINARY);
file_put_contents($bin . '/php', "#!/bin/sh\nexec $php \"\$CASE_RECORDER\" php \"\$@\"\n");
chmod($bin . '/php', 0700);
file_put_contents($repo . '/tools/manager_http_smoke.sh', "#!/bin/sh\nexec $php \"\$CASE_RECORDER\" bash tools/manager_http_smoke.sh\n");
$expected = [
    ['php', 'tools/ai_profile_rollout.php', '--verify'],
    ['php', '-l', 'webhook.php'],
    ['php', '-l', 'telegram_webhook.php'],
    ['php', '-l', 'telegram_webhook_admin.php'],
    ['php', 'tools/telegram_smoke_test.php'],
    ['php', 'tools/search_destination_smoke.php', '--http'],
    ['bash', 'tools/manager_http_smoke.sh'],
    ['php', 'tools/manager_preparation_smoke.php'],
    ['php', 'tools/virtual_reply_learning.php', 'control-status'],
];
$git = static function (array $arguments) use ($execute, $repo, $environment): string {
    $result = $execute(array_merge(['git'], $arguments), $repo, $environment);
    if ($result['code'] !== 0) throw new RuntimeException('application fixture git failed');
    return trim($result['out']);
};
try {
    $git(['init', '-q']);
    $git(['add', '.']);
    $git(['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'fixture']);
    $head = $git(['rev-parse', 'HEAD']);
    $invoke = static function (array $args, array $overrides = []) use ($execute, $repo, $temp, $trace, $environment): array {
        file_put_contents($trace, '');
        $result = $execute(array_merge(['bash', $repo . '/tools/deploy_application_check.sh'], $args),
            $temp, array_replace($environment, $overrides));
        $result['calls'] = array_map(static fn (string $row): array => json_decode($row, true, 512, JSON_THROW_ON_ERROR),
            file($trace, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        return $result;
    };
    $pass = $invoke([$head]);
    $assertCheck($pass['code'] === 0 && $pass['err'] === '', 'valid checkout completes');
    $assertCheck($pass['calls'] === $expected, 'all nine original checks execute exactly once in order');
    $assertCheck($pass['out'] === "DEPLOY_APPLICATION_CHECK=OK\n", 'success is explicit and singular');
    $assertCheck($git(['status', '--porcelain']) === '', 'verification leaves tracked fixture source unchanged');
    foreach ([[], ['HEAD'], [str_repeat('0', 40)], [str_repeat('a', 39)], [str_repeat('a', 41)],
        [str_repeat('A', 40)], [str_repeat('a', 64)], ["$head\n"], ["$head; echo BAD"], [$head, 'extra']] as $args) {
        $result = $invoke($args);
        $assertCheck($result['code'] === 2 && $result['calls'] === [] && $result['out'] === ''
            && $result['err'] === "DEPLOY_APPLICATION_CHECK=FAILED (invalid_target)\n", 'invalid or stale target never enters a probe');
    }
    foreach ($expected as $index => $command) {
        $result = $invoke([$head], ['CASE_FAIL' => json_encode($command, JSON_THROW_ON_ERROR)]);
        $assertCheck($result['code'] === 23, 'original failure exit code is preserved: ' . $index);
        $assertCheck($result['calls'] === array_slice($expected, 0, $index + 1), 'failure stops subsequent checks: ' . $index);
        $assertCheck(!str_contains($result['out'], 'DEPLOY_APPLICATION_CHECK=OK'), 'failure never reports success: ' . $index);
    }
    $result = $invoke([$head], ['CASE_DRIFT' => '1']);
    $assertCheck($result['code'] === 2 && $result['calls'] === $expected && $result['out'] === '', 'source movement during checks cannot pass');
    $assertCheck($git(['rev-parse', 'HEAD']) !== $head, 'drift counterexample actually moved HEAD');
    mkdir($temp . '/outside/tools', 0700, true);
    copy($source, $temp . '/outside/tools/deploy_application_check.sh');
    file_put_contents($trace, '');
    $result = $execute(['bash', $temp . '/outside/tools/deploy_application_check.sh', $head], $temp, $environment);
    $assertCheck($result['code'] === 2 && file_get_contents($trace) === '', 'a non-checkout cannot run verification');
    // A nested copy must not borrow an ancestor repository's matching HEAD.
    mkdir($repo . '/nested/tools', 0700, true);
    copy($source, $repo . '/nested/tools/deploy_application_check.sh');
    $result = $execute(['bash', $repo . '/nested/tools/deploy_application_check.sh', $git(['rev-parse', 'HEAD'])], $temp, $environment);
    $assertCheck($result['code'] === 2 && file_get_contents($trace) === '', 'nested copy cannot certify its ancestor source');
} finally {
    // The only recursive cleanup is this unpredictable, test-owned directory.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($temp);
}
echo 'DEPLOY APPLICATION CHECK CASES: ' . $checkCount . " PASS\n";
