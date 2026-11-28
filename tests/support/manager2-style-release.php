<?php
declare(strict_types=1);

/** The real publisher, URL reader and HTTP asset entrypoint on an isolated checkout. */
function manager2StyleReleaseChecks(string $repository): void
{
    require_once $repository.'/services/Manager2WorkspaceStyle.php';
    $temporary = realpath(sys_get_temp_dir()).'/manager2-style-'.bin2hex(random_bytes(8));
    if (!mkdir($temporary, 0700)) throw new RuntimeException('cannot_create_style_fixture');
    $app = $temporary.'/app';
    $releaseRoot = Manager2WorkspaceStyle::releaseRoot($app);
    $server = null;
    $serverPipes = [];
    try {
        foreach (Manager2WorkspaceStyle::CONTRACT_FILES as $file) {
            $target = $app.'/'.$file;
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
            if (!copy($repository.'/'.$file, $target)) throw new RuntimeException('cannot_copy_style_fixture');
        }
        mkdir(dirname($releaseRoot).'/hotel-catalogue-releases', 0700, true);
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:0', '-t', $app],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['pipe', 'w']], $serverPipes);
        if (!is_resource($server)) throw new RuntimeException('cannot_start_style_http_fixture');
        stream_set_timeout($serverPipes[2], 5);
        $startup = (string)fgets($serverPipes[2]);
        if (preg_match('~\(http://127\.0\.0\.1:([1-9][0-9]*)\) started~', $startup, $address) !== 1) {
            throw new RuntimeException('cannot_read_style_http_fixture_port');
        }
        $http = static function (string $path) use ($address): array {
            $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
            $body = file_get_contents('http://127.0.0.1:'.$address[1].$path, false, $context);
            $headers = array_map('strtolower', $http_response_header ?? []);
            $status = explode(' ', $headers[0] ?? '')[1] ?? '';
            return [$body, $headers, $status];
        };
        $run = static function (array $command, int $expect = 0): array {
            $process = proc_open($command, [['file','/dev/null','r'],['pipe','w'],['pipe','w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('cannot_run_style_fixture');
            $value = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $error = trim((string)stream_get_contents($pipes[2])); fclose($pipes[2]);
            $exit = proc_close($process);
            if ($exit !== $expect) {
                throw new RuntimeException('style_fixture_command_failed '.$exit.' '.$error);
            }
            return [$value, $error];
        };
        $git = static fn(array $args): string => trim($run(['git', '-C', $app, ...$args])[0]);
        $git(['init','-q','--object-format=sha1']);
        $git(['add','.']);
        $commit = ['-c','user.name=Fixture','-c','user.email=fixture@example.invalid','commit','-qm'];
        $git([...$commit, 'baseline']);
        $application = $git(['rev-parse','HEAD']);
        $style = file_get_contents($app.'/'.Manager2WorkspaceStyle::BUNDLED);
        if (!is_string($style)) throw new RuntimeException('cannot_read_bundled_style_fixture');
        $candidate = $temporary.'/approved.css';
        file_put_contents($candidate, $style);
        $hash = hash('sha256', $style);
        $cli = ['node', $repository.'/tools/manager2_style_release.js'];
        $preflight = [...$cli,'preflight-bundled',$app,$hash];
        $apply = [...$cli,'activate',$app,$application,'bundled',$candidate,$hash,'manager2-first-style'];
        $rollback = [...$cli,'rollback',$app,$application,'manager2-first-style','manager2-style-rollback'];
        $receipt = static fn(array $command): array => json_decode($run($command)[0], true, 32, JSON_THROW_ON_ERROR);
        $guard = static fn(string $candidate, int $expected): array => $run(['bash','-c',
            'cd "$1" || exit 1; source "$2"; deploy_hotel_catalogue_guard "$1" "$3"',
            'guard',$app,$repository.'/tools/deploy_hotel_catalogue_guard.sh',$candidate], $expected);
        $bundledLink = Manager2WorkspaceStyle::href($app);
        [$bundledBody, , $bundledStatus] = $http($bundledLink);
        checkManager2('only the bundled stylesheet is selected before activation',
            str_starts_with($bundledLink, '/manager2/assets/manager2.css?v=')
            && $bundledStatus === '200' && $bundledBody === $style);
        $lock = dirname($releaseRoot).'/hotel-catalogue-releases/operation.lock';
        checkManager2('style preflight requires the existing deployment lock without creating it',
            $run($preflight, 1)[1] === 'ENOENT' && !file_exists($lock) && !file_exists($releaseRoot));
        $guard($application, 0);
        $first = $receipt($preflight);
        $git([...array_slice($commit, 0, -1), '--allow-empty', '-qm', 'unrelated application release']);
        $next = $git(['rev-parse','HEAD']);
        $second = $receipt($preflight);
        checkManager2('same approved CSS can prepare against unrelated deployment without writing release state',
            $first['contract'] === 'manager2-style-preflight-v1' && $first['application_sha'] === $application
            && $second === array_replace($first, ['application_sha' => $next])
            && $second['candidate_version'] === $hash.'-'.Manager2WorkspaceStyle::contractPin($app)
            && $second['previous'] === 'bundled' && $second['css'] === $app.'/'.Manager2WorkspaceStyle::BUNDLED
            && !file_exists($releaseRoot) && Manager2WorkspaceStyle::href($app) === $bundledLink);
        $lockHandle = fopen($lock, 'r');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) throw new RuntimeException('style_fixture_lock_failed');
        try {
            checkManager2('style preflight refuses during the existing exclusive writer lock',
                $run($preflight, 1)[1] === 'catalogue_operation_busy' && !file_exists($releaseRoot));
        } finally {
            fclose($lockHandle);
        }
        checkManager2('style preflight refuses an unapproved stylesheet digest',
            $run([...$cli,'preflight-bundled',$app,str_repeat('0',64)], 1)[1] === 'manager2_style_digest_mismatch'
            && !file_exists($releaseRoot));
        $git(['reset','--hard',$application]);
        $badHash = $apply;
        $badHash[7] = str_repeat('0',64);
        checkManager2('wrong approved CSS digest refuses before changing the selection',
            $run($badHash, 1)[1] === 'manager2_style_digest_mismatch' && Manager2WorkspaceStyle::href($app) === $bundledLink);
        $activated = $receipt($apply);
        checkManager2('bundled preflight refuses once an independent version is selected',
            $run($preflight, 1)[1] === 'catalogue_baseline_changed');
        $version = $activated['to'];
        $pointer = $releaseRoot.'/current';
        $assetUrl = '/manager2/workspace-style.php?v='.$version;
        [$assetBody, $assetHeaders, $assetStatus] = $http($assetUrl);
        [$rejectedBody, $rejectedHeaders, $rejectedStatus] = $http('/manager2/workspace-style.php?v=../manager/index.php');
        checkManager2('the selected CSS serves exact approved bytes over HTTP without changing application HEAD',
            $activated['state'] === 'activated' && $activated['from'] === 'bundled'
            && Manager2WorkspaceStyle::href($app) === $assetUrl
            && $assetBody === $bundledBody && $assetStatus === '200'
            && in_array('content-type: text/css; charset=utf-8', $assetHeaders, true)
            && in_array('cache-control: public, max-age=31536000, immutable', $assetHeaders, true)
            && in_array('x-content-type-options: nosniff', $assetHeaders, true)
            && $rejectedBody === '' && $rejectedStatus === '404'
            && in_array('cache-control: no-store', $rejectedHeaders, true)
            && $git(['rev-parse','HEAD']) === $application && $git(['status','--porcelain']) === '');
        $record = $releaseRoot.'/operations/manager2-first-style.json';
        $pending = $releaseRoot.'/pending.json';
        rename($record, $pending);
        checkManager2('bundled preflight refuses an unsettled transaction',
            $run($preflight, 1)[1] === 'catalogue_release_pending' && is_file($pending) && !file_exists($record));
        checkManager2('a lost operation response recovers the receipt without a second style switch',
            $receipt($apply) === $activated && readlink($pointer) === $releaseRoot.'/'.$version);
        checkManager2('the installation lock accepts an unchanged Manager2 style contract',
            str_contains($guard($application, 0)[0], 'manager2_style_guard=compatible'));
        file_put_contents($app.'/manager2/workspace-chrome.php', "\n", FILE_APPEND);
        $git(['add','manager2/workspace-chrome.php']);
        $git([...$commit, 'changed shell']);
        $incompatible = $git(['rev-parse','HEAD']);
        $git(['reset','--hard',$application]);
        checkManager2('the installation lock refuses an incompatible incoming Manager2 shell',
            str_contains($guard($incompatible, 1)[1], 'manager2_style_guard=incompatible_release'));
        $rolledBack = $receipt($rollback);
        checkManager2('style rollback restores the original URL, preserves old immutable reads and keeps core files',
            $rolledBack['state'] === 'rolled_back' && $receipt($rollback) === $rolledBack
            && Manager2WorkspaceStyle::href($app) === $bundledLink
            && $http($bundledLink)[0] === $style && $http($assetUrl)[0] === $style
            && $git(['rev-parse','HEAD']) === $application && $git(['status','--porcelain']) === '');

        $approvedPin = Manager2WorkspaceStyle::contractPin($app);
        $contractOption = '--contract-sha256='.$approvedPin;
        $contractApply = [...$cli,'activate',$app,$application,'bundled',$candidate,$hash,
            'manager2-contract-style',$contractOption];
        $git(['reset','--hard',$incompatible]);
        $driftedApply = $contractApply;
        $driftedApply[4] = $incompatible;
        checkManager2('an exact application SHA does not override a changed approved style contract',
            $run($driftedApply, 1)[1] === 'manager2_style_contract_changed'
            && !is_link($pointer) && !file_exists($pending));
        $git(['reset','--hard',$application]);
        file_put_contents($app.'/unrelated-release.txt', "independent release\n");
        $git(['add','unrelated-release.txt']);
        $git([...$commit, 'unrelated release']);
        $compatible = $git(['rev-parse','HEAD']);
        checkManager2('an approved contract still requires the actual installed application SHA for a new activation',
            $run($contractApply, 1)[1] === 'catalogue_application_changed'
            && !is_link($pointer) && !file_exists($pending));
        $prepared = $receipt($preflight);
        $contractApply[4] = $prepared['application_sha'];
        $contractRelease = $receipt($contractApply);
        checkManager2('an unrelated application release preserves stylesheet approval and records the actual application',
            $prepared['contract_sha256'] === $approvedPin && $contractRelease['application_sha'] === $compatible
            && $contractRelease['request']['contract_sha256'] === $approvedPin
            && $contractRelease['to'] === $version && Manager2WorkspaceStyle::href($app) === $assetUrl);

        $git([...$commit, 'later compatible release', '--allow-empty']);
        $later = $git(['rev-parse','HEAD']);
        $contractApply[4] = $later;
        $contractRollback = [...$cli,'rollback',$app,$later,'manager2-contract-style',
            'manager2-contract-rollback',$contractOption];
        $contractRolledBack = $receipt($contractRollback);
        checkManager2('a compatible release preserves original activation evidence and permits independent rollback',
            $receipt($contractApply) === $contractRelease && $contractRolledBack['application_sha'] === $later
            && $contractRolledBack['state'] === 'rolled_back'
            && !is_link($pointer) && $http($assetUrl)[0] === $style && $git(['rev-parse','HEAD']) === $later);
    } finally {
        if (is_resource($server)) {
            proc_terminate($server);
            fclose($serverPipes[2]);
            proc_close($server);
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $entry) {
            if ($entry->isLink() || !$entry->isDir()) unlink($entry->getPathname());
            else rmdir($entry->getPathname());
        }
        rmdir($temporary);
    }
}
