<?php
declare(strict_types=1);

/** Rehearsal only. Uses the existing publisher's lock, pointer swap and rollback in a private fixture. */
function hotelCatalogueReleaseChecks(array $manifest, int $now): void
{
    $repo = dirname(__DIR__, 2);
    require_once $repo.'/modules/tour-selections/deploy-editor.php';
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
        $run = static function (array $args): array {
            $p = proc_open($args, [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $io);
            if (!is_resource($p)) throw new RuntimeException('hotel_release_fixture_process_failed');
            $value = stream_get_contents($io[1]); fclose($io[1]);
            $error = stream_get_contents($io[2]); fclose($io[2]);
            if (proc_close($p) !== 0 || $error !== '') throw new RuntimeException('hotel_release_fixture_command_failed: '.$error);
            return json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        };
        $entries = array_slice($manifest['bindings'], 0, 2);
        $snapshot = static function (string $dir, array $bindings) use ($manifest, $repo): void {
            mkdir($dir, 0700, true);
            $shard = json_encode(array_replace($manifest, ['bindings'=>$bindings]), JSON_THROW_ON_ERROR);
            file_put_contents($dir.'/manifest.json', $shard);
            file_put_contents($dir.'/catalogue.json', json_encode([
                'schema_version'=>'hotel_catalogue_v1', 'project_key'=>'anytour',
                'shards'=>[['file'=>'manifest.json', 'git_blob_sha'=>hash('sha1', 'blob '.strlen($shard)."\0".$shard)]],
            ], JSON_THROW_ON_ERROR));
            foreach ($bindings as $entry) {
                $file = $entry['bundle_file'];
                if (!copy($repo.'/knowledge/hotels/'.$file, $dir.'/'.$file)) throw new RuntimeException('hotel_release_bundle_copy_failed');
            }
        };
        $snapshot($root.'/empty', []);
        $snapshot($app.'/knowledge/hotels', [$entries[0]]);
        $snapshot($root.'/next', $entries);
        $stage = static function (string $name, string $current, string $candidate) use ($run, $node, $reader, $root, $releases): string {
            $archive = $root.'/'.$name.'.json';
            $built = $run(['node', $node, 'build', $current, $reader, $candidate, $archive]);
            $verified = $run(['node', $node, 'verify', $current, $reader, $archive, $built['package_sha256']]);
            if ($built !== $verified) throw new RuntimeException('hotel_release_verified_receipt_changed');
            $target = $releases.'/'.$verified['package_sha256'].'-'.$verified['reader_sha256'];
            mkdir($target.'/hotels', 0700, true);
            $payload = json_decode(file_get_contents($archive), true, 32, JSON_THROW_ON_ERROR);
            foreach ($payload['files'] as [$file, $bytes]) {
                if ($file !== basename($file) || in_array($file, ['.', '..'], true)
                    || file_put_contents($target.'/hotels/'.$file, base64_decode($bytes, true)) === false) {
                    throw new RuntimeException('hotel_release_fixture_materialization_failed');
                }
            }
            $seal = array_intersect_key($verified, array_flip(['contract','package_sha256','reader_sha256']));
            $seal['catalogue_index_sha256'] = hash_file('sha256', $target.'/hotels/catalogue.json');
            file_put_contents($target.'/release.json', json_encode($seal, JSON_THROW_ON_ERROR));
            return $target;
        };
        $base = $stage('base', $root.'/empty', $app.'/knowledge/hotels');
        $next = $stage('next', $app.'/knowledge/hotels', $root.'/next');
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
        $deployment = new TourSelectionEditorDeployment($app, $site, $releases);
        $exclusive = new ReflectionMethod($deployment, 'exclusive');
        $point = new ReflectionMethod($deployment, 'point');
        $current = new ReflectionMethod($deployment, 'current');
        $write = new ReflectionMethod($deployment, 'write');
        $exclusive->invoke($deployment, static function () use ($point, $deployment, $base): array {
            $point->invoke($deployment, $base);
            return [];
        });
        draftContextCheck('sealed hotel baseline matches ordinary approved capture',
            $run($args) === $fallback && $fallback['native'][0]['status'] === 'available'
            && $fallback['native'][1]['reason'] === 'binding_unresolved'
            && $fallback['retained'][0]['status'] === 'available' && $fallback['retained'][1]['reason'] === 'binding_unresolved');
        $held = proc_open([...$args, 'hold'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $heldIo);
        if (!is_resource($held)) throw new RuntimeException('hotel_held_reader_unavailable');
        stream_set_timeout($heldIo[1], 10);
        if (fgets($heldIo[1]) !== "ready\n") throw new RuntimeException('hotel_held_reader_not_ready');
        $operation = 'hotel-release-rehearsal';
        $pending = $releases.'/pending.json';
        $exclusive->invoke($deployment, static function () use ($point, $current, $write, $deployment, $base, $next, $pending, $operation): array {
            if ($current->invoke($deployment) !== $base || file_exists($pending)) throw new RuntimeException('hotel_rehearsal_baseline_changed');
            $write->invoke(null, $pending, json_encode([
                'target'=>$next, 'previous'=>$base, 'active'=>null, 'front'=>false,
                'operation'=>$operation, 'release_kind'=>'independent',
            ], JSON_THROW_ON_ERROR));
            $point->invoke($deployment, $next);
            return [];
        });
        $updated = $run($args);
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
        draftContextCheck('active hotel index checksum failure holds instead of reading another version',
            $rejected['native'][0]['reason'] === 'knowledge_unavailable' && $rejected['native'][1]['reason'] === 'knowledge_unavailable'
            && $rejected['retained'][0]['reason'] === 'knowledge_unavailable' && $rejected['retained'][1]['reason'] === 'knowledge_unavailable');
        file_put_contents($index, $indexBytes);

        try { $deployment->rollbackIndependent('foreign-hotel-operation'); $foreign = false; }
        catch (RuntimeException $e) { $foreign = $e->getMessage() === 'editor_operation_changed'; }
        draftContextCheck('foreign operation cannot roll back the hotel packet', $foreign && $run($args) === $updated);
        $result = $deployment->rollbackIndependent($operation);
        $receipt = $releases.'/operations/'.$operation.'.json';
        draftContextCheck('existing rollback restores the prior hotel version and emits a receipt',
            $result === ['ok'=>true,'rolled_back'=>true] && $run($args) === $fallback
            && ManagerTourSelectionEditorHost::json($receipt) === ['state'=>'rolled_back','package_id'=>basename($next)]
            && hash_file('sha256', $reader) === $readerSha);
        $receiptSha = hash_file('sha256', $receipt);
        try { $deployment->rollbackIndependent($operation); $replay = false; }
        catch (RuntimeException $e) { $replay = $e->getMessage() === 'editor_operation_unconfirmed'; }
        draftContextCheck('completed hotel rollback cannot be replayed after a lost result',
            $replay && hash_file('sha256', $receipt) === $receiptSha && $run($args) === $fallback);
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
