<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelCollectionCanonicalStore.php';
require_once __DIR__.'/fixtures/hotel_collection_canonical.php';

// Real PDO/SQL tests. The injected auth/lock fixture is deliberately synthetic;
// these tests do not attest production journal wiring or any native run ID.
$hcsChecks = 0;
function hcsCheck(bool $ok, string $name): void { global $hcsChecks; ++$hcsChecks; if (!$ok) throw new RuntimeException('collection_store_assertion:'.$name); }
function hcsReject(callable $work, string $name, ?string $reason = null): void {
    try { $work(); } catch (Throwable $e) { hcsCheck($reason === null || $e->getMessage() === $reason, $name.':'.$e->getMessage()); return; }
    hcsCheck(false, $name);
}
function hcsJson(array $value, bool $pretty = false): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0)); }
function hcsPdo(?string $file = null): PDO {
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_STRINGIFY_FETCHES => false];
    if (getenv('HOTEL_WORK_NATIVE_MYSQL') === '1') {
        $dsn = (string)getenv('PREPARED_REPLY_MYSQL_DSN');
        if (!str_starts_with($dsn, 'mysql:') || preg_match('/(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D', $dsn, $match) !== 1) throw new RuntimeException('unsafe_collection_test_database');
        $options[PDO::ATTR_EMULATE_PREPARES] = false;
        $pdo = new PDO($dsn, getenv('PREPARED_REPLY_MYSQL_USER') ?: null, getenv('PREPARED_REPLY_MYSQL_PASS') ?: null, $options);
        if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $match[1]) throw new RuntimeException('unsafe_collection_test_database');
        return $pdo;
    }
    $pdo = new PDO($file === null ? 'sqlite::memory:' : 'sqlite:'.$file, null, null, $options);
    $pdo->exec('PRAGMA busy_timeout=10000');
    return $pdo;
}
function hcsTx(PDO $pdo, callable $work): mixed {
    $pdo->beginTransaction();
    try { $result = $work(); $pdo->commit(); return $result; }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function hcsState(): array {
    $bytes = "opaque legacy baseline fixture\n\0unaltered bytes"; $sha = hash('sha256', $bytes);
    return ['baseline_bytes' => $bytes, 'baseline_sha256' => $sha,
        'baseline_index' => ['baseline_sha256' => $sha, 'facts' => [], 'sources' => [], 'read_update_ids' => [], 'question_ids' => []],
        'baseline_index_attestation' => 'synthetic control fixture; no legacy parser assertion', 'operations' => [], 'calls' => [], 'deny' => false];
}
function hcsOperation(array &$state, string $operation, string $stage, ?string $packet = null): void {
    $state['operations'][$operation] = ['queue_id' => 'COLLECTION', 'operation_id' => $operation, 'stage_id' => $stage,
        'generation' => 1, 'owner_admin_id' => 1, 'owner_connection_id' => 'fixture-connection', 'reserve_sha256' => hash('sha256', 'reserved:Melissa/Ibis/legacy162'),
        'instance_id' => 'fixture-instance-'.$operation,
        'document_context' => ['queue_id' => 'COLLECTION', 'operation_id' => $operation, 'stage_id' => $stage, 'country' => 'TH',
            'scope_id' => 'scope-courtyard', 'source_packet_id' => $packet ?? 'packet-'.$operation, 'hotel_keys' => ['courtyard-fixture'],
            'base_revision' => 0, 'baseline_sha256' => $state['baseline_sha256']]];
}
function hcsStore(PDO $pdo, array &$state, ?callable $fault = null): HotelCollectionCanonicalStore {
    $provider = static function (string $action, string $queue, ?string $operation, PDO $samePdo) use ($pdo, &$state): array {
        if ($samePdo !== $pdo || !$pdo->inTransaction() || $state['deny'] || $queue !== 'COLLECTION') throw new DomainException('fixture_auth_denied');
        // A test queue authority lock only. Production must lock the existing
        // lane -> queue -> journal and verify real owner/admission/reserves.
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql) { $s = $pdo->prepare('SELECT queue_id FROM hotel_collection_test_locks WHERE queue_id=? FOR UPDATE'); $s->execute([$queue]); if ($s->fetchColumn() !== $queue) throw new DomainException('fixture_queue_missing'); }
        else { $s = $pdo->prepare('UPDATE hotel_collection_test_locks SET touched=touched WHERE queue_id=?'); $s->execute([$queue]); if ($s->rowCount() !== 1) throw new DomainException('fixture_queue_missing'); }
        $state['calls'][] = $action;
        $role = $action === 'submit_candidate' || $action === 'read_assignment' ? 'worker' : 'control';
        $base = ['authority' => $state['role_override'] ?? $role, 'queue_id' => $queue, 'owner_admin_id' => 1, 'owner_connection_id' => 'fixture-connection'];
        if ($operation === null) return $base + $state;
        if (!isset($state['operations'][$operation])) throw new DomainException('fixture_operation_missing');
        return array_replace($state['operations'][$operation], $base);
    };
    return new HotelCollectionCanonicalStore($pdo, $provider, static fn(): int => 1793500000, $fault);
}
function hcsFixture(?string $file = null): array {
    $pdo = hcsPdo($file); hotelCollectionCanonicalFixtureSchema($pdo);
    $pdo->exec("INSERT INTO hotel_collection_test_locks(queue_id,touched) VALUES('COLLECTION',0)");
    return [$pdo, hcsState()];
}
function hcsSnapshot(PDO $pdo): string {
    $out = [];
    foreach (['hotel_collection_baselines','hotel_collection_heads','hotel_collection_snapshots','hotel_collection_assignments','hotel_collection_candidates','hotel_collection_ledger','hotel_collection_requests'] as $table) $out[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256', hcsJson($out));
}
function hcsNoWrite(PDO $pdo, callable $call, string $name, ?string $reason = null): void {
    $before = hcsSnapshot($pdo); hcsReject(fn() => hcsTx($pdo, $call), $name, $reason); hcsCheck(hcsSnapshot($pdo) === $before, $name.'_transaction_unchanged');
}
function hcsPacket(array $assignment, string $suffix = 'one'): array {
    $packet = ['schema_version' => 1];
    foreach ($assignment['context'] as $key => $value) if ($key !== 'hotel_keys') $packet[$key] = $value;
    $packet['sources'] = [['id' => 'source-'.$suffix, 'hotel_key' => 'courtyard-fixture', 'url' => 'https://example.test/rooms', 'kind' => 'official',
        'publication_date' => null, 'retrieved_at' => '2026-11-01T00:00:00Z', 'evidence_sha256' => hash('sha256', 'source-'.$suffix), 'read_scope' => 'fixture room paragraph', 'limitations' => 'Future operation unverified']];
    $packet['facts'] = [['id' => 'fact-'.$suffix, 'hotel_key' => 'courtyard-fixture', 'criterion_id' => 'room_layout', 'text' => 'Synthetic category has two rooms.',
        'kind' => 'official_declared', 'scope' => ['category' => 'Family', 'age' => null, 'season' => null, 'product' => null],
        'source_ids' => ['source-'.$suffix], 'material_limits' => ['Party eligibility unknown'], 'valid_at_travel' => 'unverified']];
    $packet['read_updates'] = []; $packet['questions'] = [];
    return $packet;
}
function hcsPrepared(PDO $pdo, HotelCollectionCanonicalStore $store, array &$state, string $op, string $stage, string $suffix = 'one', ?string $packet = null): array {
    hcsOperation($state, $op, $stage, $packet);
    $assignment = hcsTx($pdo, fn() => $store->prepareAssignment('COLLECTION', $op));
    $raw = hcsJson(hcsPacket($assignment, $suffix));
    $candidate = hcsTx($pdo, fn() => $store->submitCandidate('COLLECTION', $op, 'candidate-'.$op, $raw));
    return [$assignment, $candidate, $raw];
}
function hcsCommit(PDO $pdo, HotelCollectionCanonicalStore $store, string $op, array $assignment, array $candidate, string $request = 'commit-one'): array {
    return hcsTx($pdo, fn() => $store->commit('COLLECTION', $op, $request, $candidate['raw_sha256'], $assignment['base_revision'], $assignment['base_snapshot_sha256']));
}
function hcsWait(string $path): void { $until = microtime(true) + 15; while (!is_file($path) && microtime(true) < $until) usleep(1000); if (!is_file($path)) throw new RuntimeException('collection_child_barrier_timeout'); }
function hcsSpawn(string $file, array $payload, string $prefix): array {
    $in = $prefix.'.input'; $out = $prefix.'.output'; file_put_contents($in, hcsJson($payload));
    $command = [PHP_BINARY]; if (php_ini_loaded_file()) { $command[] = '-c'; $command[] = php_ini_loaded_file(); }
    array_push($command, __FILE__, '--collection-child', $file, $in, $out);
    $process = proc_open($command, [0 => ['pipe','r'], 1 => ['file',$out.'.stdout','w'], 2 => ['file',$out.'.stderr','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('collection_child_start_failed'); fclose($pipes[0]);
    return [$process, $in, $out];
}

if (($argv[1] ?? '') === '--collection-child') {
    [, , $file, $input, $output] = $argv;
    $payload = json_decode((string)file_get_contents($input), true, 64, JSON_THROW_ON_ERROR);
    $pdo = hcsPdo($file === 'MYSQL' ? null : $file); $state = $payload['state'];
    $fault = ($payload['mode'] ?? '') === 'death-before' ? static function (string $point): void { if ($point === 'head_written') exit(73); } : null;
    $store = hcsStore($pdo, $state, $fault);
    file_put_contents($output.'.ready', 'ready'); hcsWait($input.'.go');
    try {
        $receipt = hcsCommit($pdo, $store, $payload['operation'], $payload['assignment'], $payload['candidate'], $payload['request']);
        if (($payload['mode'] ?? '') === 'death-after') exit(74);
        $result = ['ok' => true, 'receipt' => $receipt];
    } catch (Throwable $e) { $result = ['ok' => false, 'reason' => $e->getMessage()]; }
    file_put_contents($output, hcsJson($result)); exit(0);
}

[$pdo, $state] = hcsFixture(); $store = hcsStore($pdo, $state);
hcsReject(fn() => $store->bootstrap('COLLECTION', $state['baseline_bytes']), 'requires_caller_transaction', 'canonical_store_caller_transaction_required');
$base = hcsTx($pdo, fn() => $store->bootstrap('COLLECTION', $state['baseline_bytes']));
hcsCheck($base['revision'] === 0 && $base['baseline_bytes'] === $state['baseline_bytes'] && $base['overlay']['facts'] === [], 'actual_opaque_bytes_immutable_baseline');
hcsCheck($base['baseline_mapping'] === 'control_attested_index_not_legacy_decoder' && !$base['business_accepted'] && !$base['native_run_verified'], 'projection_limitations_explicit');
hcsCheck($base === hcsTx($pdo, fn() => $store->bootstrap('COLLECTION', $state['baseline_bytes'])), 'bootstrap_lost_ack_readback');
hcsNoWrite($pdo, fn() => $store->bootstrap('COLLECTION', 'changed Library bytes'), 'reject_newer_or_wrong_library_bytes', 'canonical_store_baseline_bytes_mismatch');
$externalLibraryCopy = 'external stale writer changed WORKING';
hcsCheck(hcsTx($pdo, fn() => $store->readCanonical('COLLECTION'))['baseline_bytes'] !== $externalLibraryCopy, 'external_library_changes_not_authoritative');
$state['role_override'] = 'worker';
hcsNoWrite($pdo, fn() => $store->bootstrap('COLLECTION', $state['baseline_bytes']), 'worker_cannot_bootstrap', 'canonical_store_trusted_context_required'); unset($state['role_override']);

[$a, $candidate, $raw] = hcsPrepared($pdo, $store, $state, 'operation-a', 'stage-a');
hcsCheck(hcsTx($pdo, fn() => $store->readAssignment('COLLECTION', 'operation-a')) === $a, 'assignment_pinned_readback');
hcsCheck($a['opaque_baseline_content_included'] === false && isset($a['scope_overlay']) && !isset($a['baseline_bytes']), 'assignment_does_not_invent_legacy_scope_decoder');
$reformatted = hcsJson(array_reverse(hcsPacket($a), true), true);
$sameCandidate = hcsTx($pdo, fn() => $store->submitCandidate('COLLECTION', 'operation-a', 'reformatted-request', $reformatted));
hcsCheck($sameCandidate === $candidate, 'reformatted_candidate_has_one_stored_packet');
hcsNoWrite($pdo, fn() => $store->submitCandidate('COLLECTION', 'operation-a', 'candidate-operation-a', $reformatted), 'same_request_changed_bytes_conflicts', 'canonical_store_request_conflict');
foreach (['ledger','reserves','library_file_id','run_id'] as $forbidden) {
    $bad = hcsPacket($a); $bad[$forbidden] = [];
    hcsNoWrite($pdo, fn() => $store->submitCandidate('COLLECTION', 'operation-a', 'forbidden-'.$forbidden, hcsJson($bad)), 'candidate_rejects_'.$forbidden, 'canonical_document_object_keys');
}
$bad = hcsPacket($a); $bad['facts'][0]['hotel_key'] = 'Melissa';
hcsNoWrite($pdo, fn() => $store->submitCandidate('COLLECTION', 'operation-a', 'out-of-scope', hcsJson($bad)), 'protected_hotel_scope_rejected', 'canonical_document_scope_mismatch');
$reserve = $state['operations']['operation-a']['reserve_sha256']; $state['operations']['operation-a']['reserve_sha256'] = hash('sha256', 'changed reserve');
hcsNoWrite($pdo, fn() => $store->commit('COLLECTION', 'operation-a', 'commit-one', $candidate['raw_sha256'], 0, $a['base_snapshot_sha256']), 'fresh_reserves_required', 'canonical_store_assignment_authority_or_reserves_changed');
$state['operations']['operation-a']['reserve_sha256'] = $reserve;
$state['operations']['operation-a']['generation'] = 2;
hcsNoWrite($pdo, fn() => $store->commit('COLLECTION', 'operation-a', 'commit-one', $candidate['raw_sha256'], 0, $a['base_snapshot_sha256']), 'generation_pinned', 'canonical_store_assignment_authority_or_reserves_changed');
$state['operations']['operation-a']['generation'] = 1;
$state['operations']['operation-a']['document_context']['hotel_keys'] = ['Ibis'];
hcsNoWrite($pdo, fn() => $store->readAssignment('COLLECTION', 'operation-a'), 'scope_context_immutable', 'canonical_store_assignment_context_changed');
$state['operations']['operation-a']['document_context']['hotel_keys'] = ['courtyard-fixture'];
$state['role_override'] = 'worker';
hcsNoWrite($pdo, fn() => $store->commit('COLLECTION', 'operation-a', 'commit-one', $candidate['raw_sha256'], 0, $a['base_snapshot_sha256']), 'worker_cannot_commit', 'canonical_store_trusted_context_required'); unset($state['role_override']);

[$b, $cb] = hcsPrepared($pdo, $store, $state, 'operation-b', 'stage-b', 'two');
[$duplicate, $duplicateCandidate] = hcsPrepared($pdo, $store, $state, 'operation-duplicate', 'stage-duplicate', 'one');
hcsCheck($candidate['semantic_sha256'] === $duplicateCandidate['semantic_sha256'], 'content_hash_excludes_new_operation_stage_request');
$receipt = hcsCommit($pdo, $store, 'operation-a', $a, $candidate);
hcsCheck($receipt['revision'] === 1 && !$receipt['business_accepted'] && !$receipt['native_run_verified'], 'derived_commit_is_not_native_or_domain_proof');
hcsCheck(hcsCommit($pdo, $store, 'operation-a', $a, $candidate) === $receipt, 'lost_ack_exact_receipt');
hcsNoWrite($pdo, fn() => $store->commit('COLLECTION', 'operation-b', 'stale-commit', $cb['raw_sha256'], 0, $b['base_snapshot_sha256']), 'cross_stage_stale_base', 'canonical_store_stale_base');
hcsNoWrite($pdo, fn() => $store->commit('COLLECTION', 'operation-b', 'changed-expected-only', $cb['raw_sha256'], 1, $receipt['snapshot_sha256']), 'changing_expected_revision_does_not_rebase_assignment', 'canonical_store_stale_base');
hcsNoWrite($pdo, fn() => $store->commit('COLLECTION', 'operation-duplicate', 'duplicate-commit', $duplicateCandidate['raw_sha256'], 1, $receipt['snapshot_sha256']), 'duplicate_content_cannot_rebase_around_cas', 'canonical_store_stale_base');
$state['operations']['operation-a']['reserve_sha256'] = hash('sha256', 'later hold'); $state['operations']['operation-a']['generation'] = 2;
hcsCheck(hcsTx($pdo, fn() => $store->readCommitReceipt('COLLECTION', 'operation-a', 'commit-one')) === $receipt, 'committed_receipt_read_after_hold');
hcsCheck(hcsCommit($pdo, $store, 'operation-a', $a, $candidate) === $receipt, 'exact_commit_replay_is_read_only_after_hold');
$state['deny'] = true;
hcsReject(fn() => hcsTx($pdo, fn() => $store->readCommitReceipt('COLLECTION', 'operation-a', 'commit-one')), 'receipt_still_requires_current_auth', 'fixture_auth_denied'); $state['deny'] = false;

[$c, $cc] = hcsPrepared($pdo, $store, $state, 'operation-c', 'stage-c', 'three');
$receipt2 = hcsCommit($pdo, $store, 'operation-c', $c, $cc, 'commit-three');
$view = hcsTx($pdo, fn() => $store->exportCanonical('COLLECTION'));
hcsCheck($view['revision'] === 2 && count($view['overlay']['facts']) === 2 && count($view['overlay']['ledger']) === 2 && $view['baseline_bytes'] === $base['baseline_bytes'], 'cumulative_append_preserves_prior_stage_and_base');
$ledger = json_decode((string)$pdo->query("SELECT ledger_bytes FROM hotel_collection_ledger WHERE operation_id='operation-c'")->fetchColumn(), true, 64, JSON_THROW_ON_ERROR);
hcsCheck($ledger['generation'] === 1 && $ledger['commit_kind'] === 'derived_overlay_only' && $ledger['owner_admin_id'] === 1 && !$ledger['business_accepted'] && !isset($ledger['run_id']), 'server_ledger_derived_from_trusted_context');
// Direct constraint test, separate from business-path CAS/ID rejection above.
// Only semantic_sha256 collides; operation/source-packet/revision are new.
$beforeUnique = hcsSnapshot($pdo); $uniqueRejected = false;
try {
    hcsTx($pdo, function () use ($pdo, $candidate): void {
        $stmt = $pdo->prepare('INSERT INTO hotel_collection_ledger(queue_id,operation_id,source_packet_id,semantic_sha256,revision,ledger_sha256,ledger_bytes) VALUES(?,?,?,?,?,?,?)');
        $stmt->execute(['COLLECTION','constraint-only-op','constraint-only-packet',$candidate['semantic_sha256'],999,hash('sha256','{}'),'{}']);
    });
} catch (PDOException $e) { $uniqueRejected = str_starts_with((string)$e->getCode(), '23'); }
hcsCheck($uniqueRejected && hcsSnapshot($pdo) === $beforeUnique, 'sql_semantic_unique_constraint_with_new_authority_keys');
[$d, $cd] = hcsPrepared($pdo, $store, $state, 'operation-d', 'stage-d', 'four', 'packet-operation-a');
hcsNoWrite($pdo, fn() => $store->commit('COLLECTION', 'operation-d', 'reuse-source-packet', $cd['raw_sha256'], $d['base_revision'], $d['base_snapshot_sha256']), 'source_packet_identity_cannot_be_reused', 'canonical_store_already_committed');

foreach (['baseline','snapshot','missing_snapshot','index','ledger','candidate','receipt'] as $corruption) {
    hcsNoWrite($pdo, function () use ($pdo, $store, $corruption): void {
        if ($corruption === 'baseline') $pdo->exec("UPDATE hotel_collection_baselines SET baseline_bytes='tampered'");
        elseif ($corruption === 'index') $pdo->exec("UPDATE hotel_collection_baselines SET index_bytes='{}'");
        elseif ($corruption === 'snapshot') $pdo->exec("UPDATE hotel_collection_snapshots SET overlay_bytes='{}' WHERE revision=2");
        elseif ($corruption === 'missing_snapshot') $pdo->exec('DELETE FROM hotel_collection_snapshots WHERE revision=2');
        elseif ($corruption === 'ledger') $pdo->exec("UPDATE hotel_collection_ledger SET ledger_bytes='{}' WHERE revision=2");
        elseif ($corruption === 'candidate') $pdo->exec("UPDATE hotel_collection_candidates SET raw_bytes='{}' WHERE operation_id='operation-c'");
        else $pdo->exec("UPDATE hotel_collection_requests SET receipt_bytes='{}' WHERE operation_id='operation-c' AND method='commit'");
        if ($corruption === 'receipt') $store->readCommitReceipt('COLLECTION', 'operation-c', 'commit-three'); else $store->readCanonical('COLLECTION');
    }, 'corrupt_'.$corruption.'_holds_without_fallback');
}

// Each failure point is inside the OWNER's transaction; the service never
// commits someone else's transaction and callers must roll it back on failure.
foreach (['snapshot_written','ledger_written','head_written','receipt_written'] as $point) {
    [$p, $s] = hcsFixture(); $j = hcsStore($p, $s); hcsTx($p, fn() => $j->bootstrap('COLLECTION', $s['baseline_bytes']));
    [$aa, $ca] = hcsPrepared($p, $j, $s, 'fault-operation', 'fault-stage');
    $faulty = hcsStore($p, $s, static function (string $at) use ($point): void { if ($at === $point) throw new RuntimeException('synthetic_death:'.$point); });
    hcsNoWrite($p, fn() => $faulty->commit('COLLECTION', 'fault-operation', 'fault-commit', $ca['raw_sha256'], 0, $aa['base_snapshot_sha256']), 'rollback_'.$point, 'synthetic_death:'.$point);
    hcsCheck(hcsCommit($p, $j, 'fault-operation', $aa, $ca, 'fault-commit')['revision'] === 1, 'fresh_retry_after_rollback_'.$point);
}

// Real concurrent SQL processes: one queue-wide winner across stages, and
// exact lost-ACK retry returns the same receipt without a second derived commit.
foreach (['different-stages','same-request'] as $race) {
    $dir = sys_get_temp_dir().'/hotel-collection-'.bin2hex(random_bytes(6)); mkdir($dir); $children = [];
    $file = getenv('HOTEL_WORK_NATIVE_MYSQL') === '1' ? 'MYSQL' : $dir.'/state.sqlite';
    try {
        [$p, $s] = hcsFixture($file === 'MYSQL' ? null : $file); $j = hcsStore($p, $s); hcsTx($p, fn() => $j->bootstrap('COLLECTION', $s['baseline_bytes']));
        [$aa, $ca] = hcsPrepared($p, $j, $s, 'race-a', 'race-stage-a');
        [$bb, $cb2] = $race === 'same-request' ? [$aa, $ca] : hcsPrepared($p, $j, $s, 'race-b', 'race-stage-b', 'two');
        foreach ([['race-a',$aa,$ca],[$race === 'same-request' ? 'race-a' : 'race-b',$bb,$cb2]] as $i => [$op,$as,$can])
            $children[] = hcsSpawn($file, ['mode' => 'race', 'state' => $s, 'operation' => $op, 'assignment' => $as, 'candidate' => $can, 'request' => 'race-commit'], $dir.'/child-'.$i);
        foreach ($children as [$process,$in,$out]) hcsWait($out.'.ready');
        foreach ($children as [$process,$in,$out]) file_put_contents($in.'.go', 'go');
        $results = [];
        foreach ($children as [$process,$in,$out]) { hcsWait($out); hcsCheck(proc_close($process) === 0, $race.'_child_exit'); $results[] = json_decode((string)file_get_contents($out), true, 64, JSON_THROW_ON_ERROR); }
        $children = [];
        if ($race === 'same-request') hcsCheck($results[0]['ok'] && $results[1]['ok'] && $results[0]['receipt'] === $results[1]['receipt'], 'concurrent_same_request_exact_receipt');
        else { $wins = array_filter($results, static fn(array $r): bool => $r['ok']); $loss = array_values(array_filter($results, static fn(array $r): bool => !$r['ok'])); hcsCheck(count($wins) === 1 && $loss[0]['reason'] === 'canonical_store_stale_base', 'cross_stage_race_exactly_one_cas'); }
        hcsCheck((int)$p->query('SELECT COUNT(*) FROM hotel_collection_ledger')->fetchColumn() === 1 && (int)$p->query('SELECT revision FROM hotel_collection_heads')->fetchColumn() === 1, $race.'_one_ledger_and_head_advance');
    } finally {
        foreach ($children as [$process,$in,$out]) if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        $j = null; $p = null; gc_collect_cycles(); foreach (glob($dir.'/*') ?: [] as $path) unlink($path); rmdir($dir);
    }
}

foreach (['death-before','death-after'] as $mode) {
    $dir = sys_get_temp_dir().'/hotel-collection-death-'.bin2hex(random_bytes(6)); mkdir($dir); $process = null;
    $file = getenv('HOTEL_WORK_NATIVE_MYSQL') === '1' ? 'MYSQL' : $dir.'/state.sqlite';
    try {
        [$p, $s] = hcsFixture($file === 'MYSQL' ? null : $file); $j = hcsStore($p, $s); hcsTx($p, fn() => $j->bootstrap('COLLECTION', $s['baseline_bytes']));
        [$aa, $ca] = hcsPrepared($p, $j, $s, 'death-operation', 'death-stage');
        [$process,$in,$out] = hcsSpawn($file, ['mode' => $mode, 'state' => $s, 'operation' => 'death-operation', 'assignment' => $aa, 'candidate' => $ca, 'request' => 'death-commit'], $dir.'/child');
        hcsWait($out.'.ready'); file_put_contents($in.'.go', 'go');
        hcsCheck(proc_close($process) === ($mode === 'death-before' ? 73 : 74), $mode.'_real_process_exit'); $process = null;
        hcsCheck((int)$p->query('SELECT revision FROM hotel_collection_heads')->fetchColumn() === ($mode === 'death-before' ? 0 : 1), $mode.'_atomic_durability');
        $rr = hcsCommit($p, $j, 'death-operation', $aa, $ca, 'death-commit');
        hcsCheck($rr['revision'] === 1 && (int)$p->query('SELECT COUNT(*) FROM hotel_collection_ledger')->fetchColumn() === 1, $mode.'_recover_one_receipt');
    } finally {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        $j = null; $p = null; gc_collect_cycles(); foreach (glob($dir.'/*') ?: [] as $path) unlink($path); rmdir($dir);
    }
}

echo 'HOTEL COLLECTION CANONICAL STORE: '.$hcsChecks.' PASS ('.(getenv('HOTEL_WORK_NATIVE_MYSQL') === '1' ? 'MySQL' : 'SQLite')."; 2 real process races + 2 process deaths; synthetic auth adapter, no native/domain acceptance proof)\n";
