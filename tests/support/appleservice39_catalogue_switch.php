<?php
declare(strict_types=1);

/** Exercise the installed pointer and rollback primitives only under an isolated temporary root. */
function repairCatalogueSwitchChecks(string $approvedBytes, string $approvedDigest, string $candidateBytes, array $approvedEntries): void
{
    $repository = dirname(__DIR__, 2);
    require_once $repository.'/modules/tour-selections/deploy-editor.php';
    $temp = realpath(sys_get_temp_dir());
    if ($temp === false) throw new RuntimeException('catalogue_rehearsal_temp_unavailable');
    $root = $temp.'/as39-catalogue-switch-'.bin2hex(random_bytes(10));
    if (!mkdir($root, 0700)) throw new RuntimeException('cannot_create_catalogue_rehearsal');
    $heldReader = null;
    try {
        $versions = [
            'reader'=>'services/AppleService39PilotCatalog.php',
            'anytour'=>'knowledge/hotels/catalogue.json',
            'manager2'=>'manager/assets/workspace-v2.js',
        ];
        foreach ($versions as $file) {
            $target = $root.'/'.$file;
            if (!mkdir(dirname($target), 0700, true) || !copy($repository.'/'.$file, $target)) {
                throw new RuntimeException('cannot_copy_catalogue_rehearsal_input');
            }
        }
        $versionReadback = static fn(): array => array_map(
            static fn(string $file): string => hash_file('sha256', $root.'/'.$file), $versions
        );
        $beforeVersions = $versionReadback();
        $releases = $root.'/releases';
        $site = $root.'/site';
        foreach ([$site, $root.'/resources', $releases.'/operations'] as $directory) {
            if (!mkdir($directory, 0700, true)) throw new RuntimeException('cannot_create_catalogue_rehearsal_input');
        }

        $candidateDigest = hash('sha256', $candidateBytes);
        $leaf = 'appleservice39-pilot-entries.json';
        $base = $releases.'/'.$approvedDigest.'-'.$beforeVersions['reader'];
        $next = $releases.'/'.$candidateDigest.'-'.$beforeVersions['reader'];
        $stage = static function (string $path, string $bytes) use ($leaf): void {
            if (!mkdir($path, 0700) || file_put_contents($path.'/'.$leaf, $bytes) !== strlen($bytes)
                || !chmod($path.'/'.$leaf, 0400)) {
                throw new RuntimeException('cannot_stage_catalogue_rehearsal');
            }
        };
        $stage($base, $approvedBytes);
        $stage($next, $candidateBytes);
        $path = $root.'/resources/'.$leaf;
        if (!symlink($releases.'/current/'.$leaf, $path)) throw new RuntimeException('cannot_link_catalogue_rehearsal');

        $deployment = new TourSelectionEditorDeployment($root, $site, $releases);
        // This private access is confined to the fixture. Apple data do not enter
        // the editor package admission path or any production command.
        $exclusive = new ReflectionMethod($deployment, 'exclusive');
        $point = new ReflectionMethod($deployment, 'point');
        $current = new ReflectionMethod($deployment, 'current');
        $write = new ReflectionMethod($deployment, 'write');
        $exclusive->invoke($deployment, static function () use ($point, $deployment, $base): array {
            $point->invoke($deployment, $base);
            return [];
        });
        $read = static function () use ($root, $path): array {
            // Each process starts with an empty catalogue cache.
            $code = 'require $argv[1]; echo json_encode([hash_file("sha256",$argv[2]),'
                .'AppleService39PilotCatalog::entries()], JSON_THROW_ON_ERROR);';
            $p = proc_open([PHP_BINARY, '-r', $code, $root.'/services/AppleService39PilotCatalog.php', $path],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $streams);
            if (!is_resource($p)) throw new RuntimeException('cannot_run_catalogue_rehearsal_reader');
            fclose($streams[0]);
            $output = stream_get_contents($streams[1]); fclose($streams[1]);
            $error = stream_get_contents($streams[2]); fclose($streams[2]);
            if (proc_close($p) !== 0 || $error !== '') throw new RuntimeException('catalogue_rehearsal_reader_failed');
            return json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        };
        repairCheck('fresh Apple reader starts on all fifteen exact approved entries', $read() === [$approvedDigest, $approvedEntries]);
        $operation = 'as39-catalogue-rehearsal';
        $pending = $releases.'/pending.json';
        $switch = static function (string $expected, string $pin) use ($exclusive, $current, $point, $write,
            $deployment, $next, $leaf, $pending, $releases, $operation): void {
            $exclusive->invoke($deployment, static function () use ($expected, $pin, $current, $point, $write,
                $deployment, $next, $leaf, $pending, $releases, $operation): array {
                if ($current->invoke($deployment) !== $expected) {
                    throw new RuntimeException('catalogue_rehearsal_current_changed');
                }
                if (is_file($pending)) throw new RuntimeException('catalogue_rehearsal_pending');
                $bytes = file_get_contents($next.'/'.$leaf);
                AppleService39PilotCatalog::comparePackage($bytes, $pin);
                $write->invoke(null, $releases.'/operations/'.$operation.'.json', '{"state":"reserved"}');
                $write->invoke(null, $pending, json_encode([
                    'target'=>$next, 'previous'=>$expected, 'active'=>null, 'front'=>false,
                    'operation'=>$operation, 'release_kind'=>'independent',
                ], JSON_THROW_ON_ERROR));
                $point->invoke($deployment, $next);
                return [];
            });
        };
        foreach ([
            [$next, $candidateDigest, 'catalogue_rehearsal_current_changed'],
            [$base, $approvedDigest, 'appleservice39_pilot_package_digest_mismatch'],
        ] as [$expected, $pin, $reason]) {
            try { $switch($expected, $pin); $refused = false; }
            catch (RuntimeException $error) { $refused = $error->getMessage() === $reason; }
            repairCheck('refused catalogue rehearsal preserves the pointer and leaves no pending record '.$reason,
                $refused && !file_exists($pending) && $read() === [$approvedDigest, $approvedEntries]);
        }

        $heldReader = fopen($path, 'rb');
        if ($heldReader === false) throw new RuntimeException('cannot_hold_catalogue_rehearsal_reader');
        $switch($base, $candidateDigest);
        $expectedEntries = $approvedEntries;
        $candidate = json_decode($candidateBytes, true, 16, JSON_THROW_ON_ERROR);
        $expectedEntries[0]['question'] = $candidate['entries'][0]['question'];
        repairCheck('overlapping readers see the full old or full new Apple bytes across the atomic pointer change',
            hash('sha256', stream_get_contents($heldReader)) === $approvedDigest
            && $read() === [$candidateDigest, $expectedEntries] && $versionReadback() === $beforeVersions);
        fclose($heldReader);
        $heldReader = null;
        try { $deployment->rollbackIndependent('foreign-rehearsal'); $foreignRefused = false; }
        catch (RuntimeException $error) { $foreignRefused = $error->getMessage() === 'editor_operation_changed'; }
        repairCheck('existing rollback rejects a foreign operation and the staged reader keeps the new version',
            $foreignRefused && is_file($pending) && $read() === [$candidateDigest, $expectedEntries]);

        $receipt = $releases.'/operations/'.$operation.'.json';
        repairCheck('existing rollback restores the original Apple package and leaves core, AnyTour and Manager2 fixtures unchanged',
            $deployment->rollbackIndependent($operation) === ['ok'=>true, 'rolled_back'=>true]
            && !file_exists($pending) && $read() === [$approvedDigest, $approvedEntries]
            && $versionReadback() === $beforeVersions
            && ManagerTourSelectionEditorHost::json($receipt) === ['state'=>'rolled_back','package_id'=>basename($next)]);
        $receiptDigest = hash_file('sha256', $receipt);
        try { $deployment->rollbackIndependent($operation); $repeatRefused = false; }
        catch (RuntimeException $error) { $repeatRefused = $error->getMessage() === 'editor_operation_unconfirmed'; }
        repairCheck('the completed rollback operation cannot run again after a lost result',
            $repeatRefused && hash_file('sha256', $receipt) === $receiptDigest
            && $read() === [$approvedDigest, $approvedEntries]);
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
