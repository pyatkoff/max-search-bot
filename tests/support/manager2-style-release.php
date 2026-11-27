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
    try {
        foreach (Manager2WorkspaceStyle::CONTRACT_FILES as $file) {
            $target = $app.'/'.$file;
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
            if (!copy($repository.'/'.$file, $target)) throw new RuntimeException('cannot_copy_style_fixture');
        }
        mkdir(dirname($releaseRoot).'/hotel-catalogue-releases', 0700, true);
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
        $candidate = $temporary.'/approved.css';
        $bytes = $style."\n/* fixture only */\n";
        file_put_contents($candidate, $bytes);
        $hash = hash('sha256', $bytes);
        $cli = ['node', $repository.'/tools/manager2_style_release.js'];
        $apply = [...$cli,'activate',$app,$application,'bundled',$candidate,$hash,'manager2-first-style'];
        $rollback = [...$cli,'rollback',$app,$application,'manager2-first-style','manager2-style-rollback'];
        $receipt = static fn(array $command): array => json_decode($run($command)[0], true, 32, JSON_THROW_ON_ERROR);
        $asset = static fn(string $version): string => $run([PHP_BINARY, '-r',
            '$_GET["v"]=$argv[1]; require $argv[2];', $version, $app.'/manager2/workspace-style.php'])[0];
        $bundledLink = Manager2WorkspaceStyle::href($app);
        checkManager2('only the bundled stylesheet is selected before activation',
            str_starts_with($bundledLink, '/manager2/assets/manager2.css?v='));
        $badHash = $apply;
        $badHash[7] = str_repeat('0',64);
        checkManager2('wrong approved CSS digest refuses before changing the selection',
            $run($badHash, 1)[1] === 'manager2_style_digest_mismatch' && Manager2WorkspaceStyle::href($app) === $bundledLink);
        $activated = $receipt($apply);
        $version = $activated['to'];
        $pointer = $releaseRoot.'/current';
        checkManager2('the selected CSS serves exact approved bytes without changing the shell or the workspace JS',
            $activated['state'] === 'activated' && $activated['from'] === 'bundled'
            && Manager2WorkspaceStyle::href($app) === '/manager2/workspace-style.php?v='.$version
            && $asset($version) === $bytes && $asset('../manager/index.php') === ''
            && $git(['rev-parse','HEAD']) === $application && $git(['status','--porcelain']) === '');
        $record = $releaseRoot.'/operations/manager2-first-style.json';
        $pending = $releaseRoot.'/pending.json';
        rename($record, $pending);
        checkManager2('a lost operation response recovers the receipt without a second style switch',
            $receipt($apply) === $activated && readlink($pointer) === $releaseRoot.'/'.$version);
        $guard = static fn(string $candidate, int $expected): array => $run(['bash','-c',
            'cd "$1" || exit 1; source "$2"; deploy_hotel_catalogue_guard "$1" "$3"',
            'guard',$app,$repository.'/tools/deploy_hotel_catalogue_guard.sh',$candidate], $expected);
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
            && Manager2WorkspaceStyle::href($app) === $bundledLink && $asset($version) === $bytes
            && $git(['rev-parse','HEAD']) === $application && $git(['status','--porcelain']) === '');
    } finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $entry) {
            if ($entry->isLink() || !$entry->isDir()) unlink($entry->getPathname());
            else rmdir($entry->getPathname());
        }
        rmdir($temporary);
    }
}
