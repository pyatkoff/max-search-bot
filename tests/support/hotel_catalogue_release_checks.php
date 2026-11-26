<?php
declare(strict_types=1);

/** Exercise the installed CLI and both readers in an isolated checkout. */
function hotelCatalogueReleaseChecks(array $manifest, int $now): void
{
    $repo = dirname(__DIR__, 2);
    $temp = realpath(sys_get_temp_dir());
    if ($temp === false) throw new RuntimeException('hotel_release_fixture_temp_unavailable');
    $root = $temp.'/hotel-release-'.bin2hex(random_bytes(10));
    if (!mkdir($root, 0700)) throw new RuntimeException('hotel_release_fixture_unavailable');
    $held = null;
    $heldIo = [];
    try {
        $app = $root.'/app';
        $reader = $app.'/services/ManagerHotelKnowledge.php';
        $site = $root.'/site';
        $releases = $root.'/max-search-bot-private-logs/hotel-catalogue-releases';
        foreach ([dirname($reader), $site, $releases.'/operations'] as $dir) mkdir($dir, 0700, true);
        if (!copy($repo.'/services/ManagerHotelKnowledge.php', $reader)) throw new RuntimeException('hotel_reader_fixture_copy_failed');
        $readerSha = hash_file('sha256', $reader);
        $node = $repo.'/tools/hotel_catalogue_package.js';
        $run = static function (array $args, int $expectedExit = 0, string $expectedError = ''): array {
            $p = proc_open($args, [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $io);
            if (!is_resource($p)) throw new RuntimeException('FAIL hotel release fixture process creation');
            $value = stream_get_contents($io[1]); fclose($io[1]);
            $error = stream_get_contents($io[2]); fclose($io[2]);
            $exit = proc_close($p);
            if ($exit !== $expectedExit || trim($error) !== $expectedError) {
                throw new RuntimeException('FAIL hotel release fixture command: '.basename($args[0])
                    .' exit '.$exit.' (expected '.$expectedExit.'): '.trim($error));
            }
            return $value === '' ? [] : json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        };
        $entries = array_slice($manifest['bindings'], 0, 2);
        $writeShard = static function (string $dir, array $updated): void {
            $shard = json_encode($updated, JSON_THROW_ON_ERROR);
            file_put_contents($dir.'/manifest.json', $shard);
            file_put_contents($dir.'/catalogue.json', json_encode([
                'schema_version'=>'hotel_catalogue_v1', 'project_key'=>'anytour',
                'shards'=>[['file'=>'manifest.json', 'git_blob_sha'=>hash('sha1', 'blob '.strlen($shard)."\0".$shard)]],
            ], JSON_THROW_ON_ERROR));
        };
        $snapshot = static function (string $dir, array $bindings) use ($manifest, $repo, $writeShard): void {
            mkdir($dir, 0700, true);
            $writeShard($dir, array_replace($manifest, ['bindings'=>$bindings]));
            foreach ($bindings as $entry) {
                $file = $entry['bundle_file'];
                if (!copy($repo.'/knowledge/hotels/'.$file, $dir.'/'.$file)) throw new RuntimeException('hotel_release_bundle_copy_failed');
            }
        };
        $snapshot($app.'/knowledge/hotels', [$entries[0]]);
        $snapshot($root.'/next', $entries);
        $git = <<<'GIT'
git -C "$1" init -q &&
git -C "$1" add services knowledge &&
git -C "$1" -c user.name=Fixture -c user.email=fixture@example.test commit -qm baseline &&
printf '{"sha":"%s"}\n' "$(git -C "$1" rev-parse HEAD)"
GIT;
        $sha = $run(['bash', '-c', $git, 'fixture', $app])['sha'];
        $archive = $root.'/candidate.json';
        $built = $run(['node', $node, 'build', $app.'/knowledge/hotels', $reader, $root.'/next', $archive]);
        $nextVersion = $built['package_sha256'].'-'.$built['reader_sha256'];
        $next = $releases.'/'.$nextVersion;
        $operation = 'hotel-release-rehearsal';
        $cli = ['node', $repo.'/tools/hotel_catalogue_release.js'];
        $activate = [...$cli, 'activate', $app, $sha, 'bundled', $archive, $built['package_sha256'], $operation];
        $rollback = [...$cli, 'rollback', $app, $sha, $operation, 'hotel-fixture-rollback'];
        $selectors = array_map(static fn(array $entry): array => [
            'project_key'=>'anytour', 'source_identity'=>$entry['source_identity'],
            'selected_hotel'=>$entry['accepted_source_names'][0], 'selected_room'=>'Fixture room',
            'provenance'=>['message_id'=>1,'event_id'=>1,'publication_sha256'=>str_repeat('a',64),'source_sha256'=>str_repeat('b',64)],
        ], $entries);
        $timestamp = static fn(int $instant): string => gmdate('Y-m-d\TH:i:s\Z', $instant);
        $candidates = array_map(static fn(array $entry): array => [
            'hotel'=>['anytour_hotel_id'=>$entry['accepted_binding']['hotel_ref']['id'],
                'name'=>$entry['accepted_source_names'][0], 'category'=>null, 'country'=>'Egypt', 'region'=>null],
            'provider'=>'tourvisor', 'operator_raw'=>null, 'offer_digest'=>str_repeat('b', 64), 'source_scope_digest'=>str_repeat('c', 64),
            'checkin'=>gmdate('Y-m-d', $now+86400), 'nights'=>7, 'party'=>['adults'=>2, 'children'=>0, 'child_ages'=>[]],
            'room_raw'=>null, 'meal_raw'=>null, 'placement_raw'=>null,
            'price'=>['amount'=>'100000', 'currency'=>'RUB', 'basis'=>'whole_party',
                'source_listing_state'=>'final_ready_estimate', 'source_final_price_verified'=>false,
                'current_price_verified'=>false, 'refresh_required'=>true],
            'observed_at'=>$timestamp($now-120), 'last_seen_at'=>$timestamp($now-60), 'expires_at'=>$timestamp($now+3600),
            'availability'=>'unverified', 'booking_enabled'=>false, 'photos_read'=>false,
        ], $entries);
        $code = <<<'READER'
require $argv[1];
[$selectors, $candidates] = json_decode($argv[2], true, 16, JSON_THROW_ON_ERROR);
$now = (int)$argv[3];
$captureRetained = ManagerHotelKnowledge::retainedCandidateCapture(str_repeat('d', 64),
    gmdate('Y-m-d\TH:i:s\Z', $now), 'Egypt', $now);
if (($argv[4] ?? '') === 'hold') {
    $snapshot = (new ReflectionMethod(ManagerHotelKnowledge::class, 'catalogueSnapshot'))->invoke(null);
    if ($snapshot === null) exit(2);
    $retained = [$captureRetained($candidates[0])];
    echo "ready\n"; fflush(STDOUT);
    if (fgets(STDIN) !== "continue\n") exit(3);
    $capture = new ReflectionMethod(ManagerHotelKnowledge::class, 'captureFromSnapshot');
    $results = array_map(static fn($selector) => $capture->invoke(null, $snapshot, $selector, $now), $selectors);
    $retained[] = $captureRetained($candidates[1]);
} else {
    $results = array_map(static fn($selector) => ManagerHotelKnowledge::capture($selector, $now), $selectors);
    $retained = array_map($captureRetained, $candidates);
}
echo json_encode(['native'=>$results, 'retained'=>$retained], JSON_THROW_ON_ERROR);
READER;
        $args = [PHP_BINARY, '-r', $code, $reader, json_encode([$selectors, $candidates], JSON_THROW_ON_ERROR), (string)$now];
        $fallback = $run($args);
        $guard = [PHP_BINARY, $repo.'/tools/hotel_catalogue_compatibility.php', $app];
        $changedReader = $root.'/candidate-reader.php';
        if (file_put_contents($changedReader, file_get_contents($reader)."\n") === false) {
            throw new RuntimeException('hotel_candidate_reader_fixture_failed');
        }
        draftContextCheck('core preflight refuses a wrong application root',
            $run([PHP_BINARY, $repo.'/tools/hotel_catalogue_compatibility.php', $site, $reader], 1)
                === ['ok'=>false, 'status'=>'invalid_input']);
        draftContextCheck('core preflight admits a checked reader when the catalogue is bundled',
            $run([...$guard, $changedReader]) === ['ok'=>true, 'status'=>'bundled']);
        draftContextCheck('bundled hotel baseline matches ordinary approved capture',
            $fallback['native'][0]['status'] === 'available'
            && $fallback['native'][1]['reason'] === 'binding_unresolved'
            && $fallback['retained'][0]['status'] === 'available' && $fallback['retained'][1]['reason'] === 'binding_unresolved');
        $invalid = $root.'/invalid';
        $snapshot($invalid, $entries);
        $badMember = $invalid.'/'.$entries[1]['bundle_file'];
        $badBytes = str_replace('"price_authority":"none"', '"price_authority":"fake"',
            file_get_contents($badMember), $replacements);
        if ($replacements !== 1) throw new RuntimeException('FAIL hotel release fixture price authority');
        file_put_contents($badMember, $badBytes);
        $badRows = $entries;
        $badRows[1]['bundle_sha256'] = hash('sha256', $badBytes);
        $writeShard($invalid, array_replace($manifest, ['bindings'=>$badRows]));
        $badArchive = $root.'/invalid.json';
        $badPackage = $run(['node', $node, 'build', $app.'/knowledge/hotels', $reader, $invalid, $badArchive]);
        draftContextCheck('correct archive hashes cannot authorize a bundle rejected by the real reader',
            $run([...$cli, 'activate', $app, $sha, 'bundled', $badArchive, $badPackage['package_sha256'],
                'hotel-invalid-package'], 1, 'catalogue_typed_validation_failed') === []
            && !is_link($releases.'/current'));
        $lock = fopen($releases.'/operation.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('hotel_fixture_lock_failed');
        try {
            draftContextCheck('publisher refuses the core installer lock without switching',
                $run($activate, 1, 'catalogue_operation_busy') === [] && !is_link($releases.'/current'));
        } finally {
            fclose($lock);
        }
        $held = proc_open([...$args, 'hold'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $heldIo);
        if (!is_resource($held)) throw new RuntimeException('hotel_held_reader_unavailable');
        stream_set_timeout($heldIo[1], 10);
        if (fgets($heldIo[1]) !== "ready\n") throw new RuntimeException('hotel_held_reader_not_ready');
        $pending = $releases.'/pending.json';
        $activated = $run($activate);
        $activationReceipt = $releases.'/operations/'.$operation.'.json';
        draftContextCheck('publisher activates the admitted bytes and leaves one immutable receipt',
            $activated['state'] === 'activated' && $activated['from'] === 'bundled' && $activated['to'] === $nextVersion
            && readlink($releases.'/current') === $next && !file_exists($pending)
            && json_decode(file_get_contents($activationReceipt), true, 16, JSON_THROW_ON_ERROR) === $activated);
        // Reconstruct the exact state after the pointer rename and before the receipt link.
        copy($activationReceipt, $pending);
        unlink($activationReceipt);
        $foreign = [...array_slice($activate, 0, -1), 'foreign-hotel-operation'];
        draftContextCheck('another request cannot consume a pending catalogue activation',
            $run($foreign, 1, 'catalogue_release_pending') === [] && readlink($releases.'/current') === $next);
        draftContextCheck('recovery records an already selected catalogue without replaying the switch',
            $run($activate) === $activated && !file_exists($pending) && readlink($releases.'/current') === $next);
        $updated = $run($args);
        draftContextCheck('core preflight admits the exact active reader bytes',
            $run([...$guard, $reader]) === ['ok'=>true, 'status'=>'compatible']);
        draftContextCheck('core preflight refuses different reader bytes and leaves the active package selected',
            $run([...$guard, $changedReader], 1) === ['ok'=>false, 'status'=>'incompatible_reader']
            && readlink($releases.'/current') === $next && $run($args) === $updated);
        fwrite($heldIo[0], "continue\n"); fclose($heldIo[0]);
        $old = stream_get_contents($heldIo[1]); fclose($heldIo[1]);
        $err = stream_get_contents($heldIo[2]); fclose($heldIo[2]);
        $heldStatus = proc_close($held); $held = null;
        $old = json_decode($old, true, 32, JSON_THROW_ON_ERROR);
        draftContextCheck('in-flight native hotel capture uses all members of the old immutable version',
            $heldStatus === 0 && $err === '' && $old['native'] === $fallback['native']);
        draftContextCheck('one retained Search3 response keeps the same version across two candidate reads',
            $heldStatus === 0 && $err === '' && $old['retained'] === $fallback['retained']
            && $updated['retained'][0] === $fallback['retained'][0] && $updated['retained'][1]['status'] === 'available');
        draftContextCheck('new hotel capture reads the added dossier with the same reader and unchanged old reference',
            $updated['native'][0] === $fallback['native'][0] && $updated['native'][1]['status'] === 'available'
            && hash_file('sha256', $reader) === $readerSha);

        $index = $next.'/hotels/catalogue.json';
        $indexBytes = file_get_contents($index);
        file_put_contents($index, $indexBytes."\n");
        $rejected = $run($args);
        draftContextCheck('core preflight treats a corrupt active index as a refusal',
            $run([...$guard, $reader], 1) === ['ok'=>false, 'status'=>'invalid_active_catalogue']);
        draftContextCheck('active hotel index checksum failure holds instead of reading another version',
            $rejected['native'][0]['reason'] === 'knowledge_unavailable' && $rejected['native'][1]['reason'] === 'knowledge_unavailable'
            && $rejected['retained'][0]['reason'] === 'knowledge_unavailable' && $rejected['retained'][1]['reason'] === 'knowledge_unavailable');
        file_put_contents($index, $indexBytes);

        $foreignRollback = [...$cli, 'rollback', $app, $sha, 'foreign-activation', 'foreign-rollback'];
        draftContextCheck('foreign operation cannot roll back the hotel packet',
            $run($foreignRollback, 1, 'invalid_catalogue_activation_receipt') === [] && $run($args) === $updated);
        $result = $run($rollback);
        $receipt = $releases.'/operations/hotel-fixture-rollback.json';
        draftContextCheck('core preflight accepts the bundled catalogue after a verified rollback',
            $run([...$guard, $reader]) === ['ok'=>true, 'status'=>'bundled']);
        draftContextCheck('rollback restores the bundled version and emits its own receipt',
            $result['state'] === 'rolled_back' && $result['to'] === 'bundled' && $result['from'] === $nextVersion
            && $run($args) === $fallback && hash_file('sha256', $reader) === $readerSha);
        $receiptSha = hash_file('sha256', $receipt);
        draftContextCheck('completed hotel rollback cannot be replayed after a lost result',
            $run($rollback) === $result && hash_file('sha256', $receipt) === $receiptSha && $run($args) === $fallback);
        $run([...array_slice($activate, 0, -1), 'hotel-release-second']);
        draftContextCheck('an older rollback cannot affect a later activation of the same catalogue',
            $run([...array_slice($rollback, 0, -1), 'hotel-stale-rollback'], 1,
                'catalogue_activation_already_rolled_back') === []
            && readlink($releases.'/current') === $next && $run($args) === $updated);
    } finally {
        if (is_resource($held)) {
            foreach ($heldIo as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_terminate($held);
            proc_close($held);
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isLink() || !$file->isDir()) unlink($file->getPathname());
            else rmdir($file->getPathname());
        }
        rmdir($root);
    }
}
