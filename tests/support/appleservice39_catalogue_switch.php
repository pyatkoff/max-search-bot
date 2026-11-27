<?php
declare(strict_types=1);

/** Run the installed catalogue CLI and fresh PHP consumers in an isolated checkout. */
function repairCatalogueSwitchChecks(string $approvedBytes, string $approvedDigest, string $candidateBytes, array $approvedEntries): void
{
    $repository = dirname(__DIR__, 2);
    $temp = realpath(sys_get_temp_dir());
    if ($temp === false) throw new RuntimeException('catalogue_rehearsal_temp_unavailable');
    $root = $temp.'/as39-catalogue-switch-'.bin2hex(random_bytes(10));
    if (!mkdir($root, 0700)) throw new RuntimeException('cannot_create_catalogue_rehearsal');
    $heldReader = null;
    try {
        $app = $root.'/app';
        $versions = [
            'reader'=>'services/AppleService39PilotCatalog.php',
            'anytour'=>'knowledge/hotels/catalogue.json',
            'manager2'=>'manager/assets/workspace-v2.js',
        ];
        foreach ($versions as $file) {
            $target = $app.'/'.$file;
            if (!mkdir(dirname($target), 0700, true) || !copy($repository.'/'.$file, $target)) {
                throw new RuntimeException('cannot_copy_catalogue_rehearsal_input');
            }
        }
        $versionReadback = static fn(): array => array_map(
            static fn(string $file): string => hash_file('sha256', $app.'/'.$file), $versions
        );
        $beforeVersions = $versionReadback();
        $private = $root.'/max-search-bot-private-logs';
        $releases = $private.'/appleservice39-catalogue-releases';
        mkdir($private.'/hotel-catalogue-releases', 0700, true);
        mkdir($app.'/resources', 0700);
        $bundled = $app.'/resources/appleservice39-pilot-entries.json';
        file_put_contents($bundled, $approvedBytes);
        $candidate = $root.'/approved-candidate.json';
        file_put_contents($candidate, $candidateBytes);
        $candidateDigest = hash('sha256', $candidateBytes);
        $run = static function (array $command, ?string $refusal = null): array {
            $p = proc_open($command, [['file','/dev/null','r'],['pipe','w'],['pipe','w']], $pipes);
            if (!is_resource($p)) throw new RuntimeException('catalogue_rehearsal_subprocess_failed');
            $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $err = trim((string)stream_get_contents($pipes[2])); fclose($pipes[2]);
            $code = proc_close($p);
            if ($code !== ($refusal === null ? 0 : 1) || $err !== ($refusal ?? '')) {
                throw new RuntimeException('catalogue_rehearsal_command_failed '.$code.' '.$err);
            }
            return $refusal === null ? json_decode($out, true, 32, JSON_THROW_ON_ERROR) : [];
        };
        $git = <<<'GIT'
git -C "$1" init -q --object-format=sha1 &&
git -C "$1" add . &&
git -C "$1" -c user.name=Fixture -c user.email=fixture@example.invalid commit -qm fixture &&
printf '{"sha":"%s"}\n' "$(git -C "$1" rev-parse HEAD)"
GIT;
        $sha = $run(['bash','-c',$git,'fixture',$app])['sha'];
        $cli = ['node',$repository.'/tools/appleservice39_catalogue_release.js'];
        $operation = 'apple-activation';
        $activate = [...$cli,'activate',$app,$sha,'bundled',$candidate,$candidateDigest,$operation];
        $rollback = [...$cli,'rollback',$app,$sha,$operation,'apple-rollback'];
        $read = static fn(): array => $run([PHP_BINARY, '-r',
            'require $argv[1]; echo json_encode(AppleService39PilotCatalog::entries(), JSON_THROW_ON_ERROR);',
            $app.'/services/AppleService39PilotCatalog.php']);
        repairCheck('fresh Apple reader starts on the fifteen exact approved entries', $read() === $approvedEntries);
        $wrongBase = $activate;
        $wrongBase[5] = str_repeat('a',64).'-'.$beforeVersions['reader'];
        $run($wrongBase, 'catalogue_baseline_changed');
        $wrongHash = $activate;
        $wrongHash[7] = $approvedDigest;
        $run($wrongHash, 'catalogue_digest_mismatch');
        $invalid = json_decode($candidateBytes, true, 16, JSON_THROW_ON_ERROR);
        $invalid['entries'][0]['price_text'] = 'Fixture refusal';
        $invalidBytes = json_encode($invalid, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $invalidFile = $root.'/forbidden-edit.json';
        file_put_contents($invalidFile, $invalidBytes);
        $forbidden = $activate;
        $forbidden[6] = $invalidFile;
        $forbidden[7] = hash('sha256', $invalidBytes);
        $run($forbidden, 'appleservice39_catalogue_validation_failed');
        repairCheck('invalid base, digest or price edit leaves the bundled Apple reader selected',
            !is_link($releases.'/current') && $read() === $approvedEntries);

        $heldReader = fopen($bundled, 'rb');
        if ($heldReader === false) throw new RuntimeException('cannot_hold_catalogue_rehearsal_reader');
        $receipt = $run($activate);
        $target = $releases.'/'.$receipt['to'];
        $expectedEntries = $approvedEntries;
        $expectedEntries[0]['question'] = json_decode($candidateBytes, true, 16, JSON_THROW_ON_ERROR)['entries'][0]['question'];
        repairCheck('old and new Apple readers see complete versions while other component files stay pinned',
            hash('sha256', stream_get_contents($heldReader)) === $approvedDigest
            && $read() === $expectedEntries && $versionReadback() === $beforeVersions
            && readlink($releases.'/current') === $target);
        fclose($heldReader);
        $heldReader = null;
        $persisted = $releases.'/operations/'.$operation.'.json';
        rename($persisted, $releases.'/pending.json');
        $foreign = $activate;
        $foreign[8] = 'apple-foreign-activation';
        $run($foreign, 'catalogue_release_pending');
        repairCheck('the same request recovers an already selected Apple version',
            $run($activate) === $receipt && readlink($releases.'/current') === $target);
        $foreignRollback = $rollback;
        $foreignRollback[5] = 'apple-foreign-activation';
        $run($foreignRollback, 'invalid_catalogue_activation_receipt');
        $rolledBack = $run($rollback);
        repairCheck('Apple rollback restores the bundled catalogue and retains the other component versions',
            $rolledBack['state'] === 'rolled_back' && $rolledBack['to'] === 'bundled'
            && $run($rollback) === $rolledBack && $read() === $approvedEntries
            && hash_file('sha256',$bundled) === $approvedDigest && $versionReadback() === $beforeVersions);
        $again = $activate;
        $again[8] = 'apple-new-activation';
        $run($again);
        $stale = $rollback;
        $stale[6] = 'apple-stale-rollback';
        $run($stale, 'catalogue_activation_already_rolled_back');
        repairCheck('stale Apple rollback cannot replace a later activation', $read() === $expectedEntries);
    } finally {
        if (is_resource($heldReader)) fclose($heldReader);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $entry) {
            if ($entry->isLink() || !$entry->isDir()) unlink($entry->getPathname());
            else rmdir($entry->getPathname());
        }
        rmdir($root);
    }
}
