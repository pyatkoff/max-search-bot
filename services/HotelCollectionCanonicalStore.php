<?php
declare(strict_types=1);

require_once __DIR__.'/HotelCollectionCanonicalDocument.php';

/**
 * Dormant INTERNAL baseline + append-only overlay store, not a WORKING converter.
 * No network, Library loader, runtime DDL, transaction ownership or independent
 * authentication. The injected server-only provider MUST authenticate each call
 * and lock lane -> existing queue -> journal on THIS PDO, in the caller's open
 * transaction. It must recheck current owner/generation/admission and reserves.
 * Never map this class directly to arbitrary MCP arguments or share its provider
 * with a worker. A boolean/array supplied by a worker is not an auth provider.
 * The caller must roll back the ENTIRE transaction on any exception.
 */
final class HotelCollectionCanonicalStore
{
    private string $driver;
    private const CONTRACT = 'immutable_baseline_plus_append_only_overlay_v1';
    private const MAX_BASELINE_BYTES = 67108864;

    public function __construct(private PDO $pdo, private $lockedContextProvider, private $clock = null, private $fault = null)
    {
        $this->driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver, ['sqlite', 'mysql'], true) || !is_callable($lockedContextProvider)
            || ($clock !== null && !is_callable($clock)) || ($fault !== null && !is_callable($fault))) {
            throw new InvalidArgumentException('canonical_store_invalid_configuration');
        }
    }

    /** Trusted control import: the provider supplies approved SHA/index/attestation. */
    public function bootstrap(string $queue, string $baselineBytes): array
    {
        $ctx = $this->context('bootstrap', $queue, null, ['control']);
        $sha = $ctx['baseline_sha256'] ?? null; self::sha($sha);
        if ($baselineBytes === '' || strlen($baselineBytes) > self::MAX_BASELINE_BYTES || !hash_equals($sha, hash('sha256', $baselineBytes))) $this->reject('baseline_bytes_mismatch');
        $index = $ctx['baseline_index'] ?? null;
        $attestation = $ctx['baseline_index_attestation'] ?? null;
        if (!is_array($index) || !is_string($attestation) || trim($attestation) === '' || strlen($attestation) > 2048) $this->reject('baseline_index_attestation_required');
        HotelCollectionCanonicalDocument::validateBaselineIndex($index, $sha);
        $indexBytes = self::json($index); $indexSha = hash('sha256', $indexBytes);
        $old = $this->head($queue);
        if ($old !== null) {
            $baseline = $this->baseline($queue);
            if ($baseline['baseline_sha256'] !== $sha || $baseline['index_sha256'] !== $indexSha || $baseline['index_attestation'] !== $attestation) $this->reject('bootstrap_conflict');
            return $this->projection($queue, $old, $baseline);
        }
        // Existing queue lock is required even before the first head exists.
        $this->execute('INSERT INTO hotel_collection_baselines(queue_id,baseline_sha256,baseline_bytes,index_sha256,index_bytes,index_attestation,created_at) VALUES(?,?,?,?,?,?,?)',
            [$queue, $sha, $baselineBytes, $indexSha, $indexBytes, $attestation, $this->now()]);
        $snapshot = $this->writeSnapshot($queue, 0, null, $sha, HotelCollectionCanonicalDocument::emptyOverlay($sha), null);
        $this->execute('INSERT INTO hotel_collection_heads(queue_id,revision,snapshot_sha256) VALUES(?,0,?)', [$queue, $snapshot]);
        $this->hit('bootstrap_written');
        return $this->projection($queue, $this->requiredHead($queue), $this->baseline($queue));
    }

    /** Prepared context is server-owned, immutable and bound to exact base/reserves. */
    public function prepareAssignment(string $queue, string $operation): array
    {
        $ctx = $this->context('prepare_assignment', $queue, $operation, ['control']);
        $head = $this->requiredHead($queue); $baseline = $this->baseline($queue);
        $old = $this->one('SELECT * FROM hotel_collection_assignments WHERE queue_id=? AND operation_id=?', [$queue, $operation]);
        if ($old !== null) {
            $this->checkAssignment($ctx, $old, $baseline);
            return $this->assignmentProjection($old, $baseline);
        }
        $document = $this->documentContext($ctx, (int)$head['revision'], $baseline['baseline_sha256']);
        $bytes = self::json($document);
        $this->execute('INSERT INTO hotel_collection_assignments(queue_id,operation_id,stage_id,generation,owner_admin_id,owner_connection_id,reserve_sha256,context_bytes,context_sha256,base_revision,base_snapshot_sha256) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
            [$queue, $operation, $ctx['stage_id'], $ctx['generation'], $ctx['owner_admin_id'], $ctx['owner_connection_id'], $ctx['reserve_sha256'], $bytes, hash('sha256', $bytes), $head['revision'], $head['snapshot_sha256']]);
        $this->hit('assignment_written');
        return $this->assignmentProjection($this->assignment($queue, $operation), $baseline);
    }

    public function readAssignment(string $queue, string $operation): array
    {
        $ctx = $this->context('read_assignment', $queue, $operation, ['control', 'worker']);
        $this->requiredHead($queue); $baseline = $this->baseline($queue); $assignment = $this->assignment($queue, $operation);
        $this->checkAssignment($ctx, $assignment, $baseline);
        return $this->assignmentProjection($assignment, $baseline);
    }

    /** Worker supplies only a bounded typed candidate, never a ledger or base. */
    public function submitCandidate(string $queue, string $operation, string $request, string $raw): array
    {
        self::id($request);
        if (strlen($raw) > HotelCollectionCanonicalDocument::MAX_PACKET_BYTES) throw new InvalidArgumentException('canonical_document_packet_too_large');
        $ctx = $this->context('submit_candidate', $queue, $operation, ['worker']);
        self::id($ctx['instance_id'] ?? null);
        $this->requiredHead($queue); $baseline = $this->baseline($queue); $assignment = $this->assignment($queue, $operation);
        $document = $this->checkAssignment($ctx, $assignment, $baseline);
        $input = ['raw_sha256' => hash('sha256', $raw), 'instance_id' => $ctx['instance_id']];
        $old = $this->replay($queue, $operation, $request, 'candidate', $input);
        if ($old !== null) { $this->candidate($queue, $operation); return $old; }
        $snapshot = $this->snapshot($queue, $assignment['base_snapshot_sha256']);
        $validated = HotelCollectionCanonicalDocument::validate($raw, $document, $snapshot['overlay'], $baseline['index']);
        $candidate = $this->one('SELECT * FROM hotel_collection_candidates WHERE queue_id=? AND operation_id=?', [$queue, $operation]);
        if ($candidate !== null) {
            $candidate = $this->candidate($queue, $operation);
            if ($candidate['instance_id'] !== $ctx['instance_id'] || $candidate['semantic_sha256'] !== $validated['semantic_sha256']) $this->reject('candidate_conflict');
        } else {
            $this->execute('INSERT INTO hotel_collection_candidates(queue_id,operation_id,instance_id,raw_sha256,semantic_sha256,raw_bytes) VALUES(?,?,?,?,?,?)',
                [$queue, $operation, $ctx['instance_id'], $validated['raw_sha256'], $validated['semantic_sha256'], $raw]);
            $candidate = $this->candidate($queue, $operation);
        }
        $this->hit('candidate_written');
        return $this->receipt($queue, $operation, $request, 'candidate', $input,
            ['status' => 'derived_candidate_recorded', 'operation_id' => $operation, 'raw_sha256' => $candidate['raw_sha256'], 'semantic_sha256' => $candidate['semantic_sha256'], 'business_accepted' => false, 'native_run_verified' => false]);
    }

    /** Control review is distinct from worker submission; no queue-stage advance. */
    public function readCandidate(string $queue, string $operation): array
    {
        $ctx = $this->context('read_candidate', $queue, $operation, ['control']);
        $this->requiredHead($queue); $this->checkAssignment($ctx, $this->assignment($queue, $operation), $this->baseline($queue));
        $row = $this->candidate($queue, $operation);
        return ['raw_bytes' => $row['raw_bytes'], 'raw_sha256' => $row['raw_sha256'], 'semantic_sha256' => $row['semantic_sha256'], 'business_accepted' => false, 'native_run_verified' => false];
    }

    public function commit(string $queue, string $operation, string $request, string $reviewedHash, int $expectedRevision, string $expectedHeadHash): array
    {
        self::id($request); self::sha($reviewedHash); self::sha($expectedHeadHash);
        if ($expectedRevision < 0) $this->reject('invalid_revision');
        $ctx = $this->context('commit', $queue, $operation, ['control']);
        $head = $this->requiredHead($queue); $baseline = $this->baseline($queue); $assignment = $this->assignment($queue, $operation);
        $input = ['reviewed_hash' => $reviewedHash, 'expected_revision' => $expectedRevision, 'expected_head_hash' => $expectedHeadHash];
        $old = $this->replay($queue, $operation, $request, 'commit', $input);
        if ($old !== null) { $this->verifyCommitReceipt($queue, $operation, $old); return $old; }
        $document = $this->checkAssignment($ctx, $assignment, $baseline);
        if ((int)$head['revision'] !== $expectedRevision || $head['snapshot_sha256'] !== $expectedHeadHash
            || (int)$assignment['base_revision'] !== $expectedRevision || $assignment['base_snapshot_sha256'] !== $expectedHeadHash) $this->reject('stale_base');
        $candidate = $this->candidate($queue, $operation);
        if ($candidate['raw_sha256'] !== $reviewedHash) $this->reject('reviewed_candidate_mismatch');
        if ($this->one('SELECT operation_id FROM hotel_collection_ledger WHERE queue_id=? AND (operation_id=? OR semantic_sha256=? OR source_packet_id=?)',
            [$queue, $operation, $candidate['semantic_sha256'], $document['source_packet_id']]) !== null) $this->reject('already_committed');
        $current = $this->snapshot($queue, $head['snapshot_sha256']);
        $applied = HotelCollectionCanonicalDocument::apply($candidate['raw_bytes'], $document, $current['overlay'], $baseline['index']);
        if ($applied['semantic_sha256'] !== $candidate['semantic_sha256']) $this->reject('candidate_semantics_mismatch');
        $revision = $expectedRevision + 1;
        $snapshot = $this->writeSnapshot($queue, $revision, $expectedHeadHash, $baseline['baseline_sha256'], $applied['overlay'], $operation);
        $this->hit('snapshot_written');
        $ledger = $applied['ledger_entry'] + ['output_revision' => $revision, 'input_snapshot_sha256' => $expectedHeadHash, 'output_snapshot_sha256' => $snapshot,
            'generation' => $ctx['generation'], 'owner_admin_id' => $ctx['owner_admin_id'], 'owner_connection_id' => $ctx['owner_connection_id'],
            'reserve_sha256' => $ctx['reserve_sha256'], 'committed_at' => $this->now(), 'commit_kind' => 'derived_overlay_only'];
        $ledgerBytes = self::json($ledger);
        $this->execute('INSERT INTO hotel_collection_ledger(queue_id,operation_id,source_packet_id,semantic_sha256,revision,ledger_sha256,ledger_bytes) VALUES(?,?,?,?,?,?,?)',
            [$queue, $operation, $document['source_packet_id'], $candidate['semantic_sha256'], $revision, hash('sha256', $ledgerBytes), $ledgerBytes]);
        $this->hit('ledger_written');
        $changed = $this->execute('UPDATE hotel_collection_heads SET revision=?,snapshot_sha256=? WHERE queue_id=? AND revision=? AND snapshot_sha256=?',
            [$revision, $snapshot, $queue, $expectedRevision, $expectedHeadHash]);
        if ($changed !== 1) $this->reject('stale_base');
        $this->hit('head_written');
        $out = $this->receipt($queue, $operation, $request, 'commit', $input,
            ['status' => 'derived_overlay_committed', 'operation_id' => $operation, 'revision' => $revision, 'snapshot_sha256' => $snapshot,
                'baseline_sha256' => $baseline['baseline_sha256'], 'semantic_sha256' => $candidate['semantic_sha256'], 'ledger_sha256' => hash('sha256', $ledgerBytes),
                'projection_contract' => self::CONTRACT, 'business_accepted' => false, 'native_run_verified' => false]);
        $this->hit('receipt_written');
        return $out;
    }

    /** Read/export is always pinned server bytes. Never consults Library. */
    public function readCanonical(string $queue): array
    {
        $this->context('read_canonical', $queue, null, ['control', 'reader']);
        return $this->projection($queue, $this->requiredHead($queue), $this->baseline($queue));
    }

    public function exportCanonical(string $queue): array
    {
        $this->context('export', $queue, null, ['control', 'reader']);
        return $this->projection($queue, $this->requiredHead($queue), $this->baseline($queue));
    }

    /** Safe receipt recovery even when current generation/reserves are held.
     * Provider authorizes READ of this queue/operation, not a new mutation.
     */
    public function readCommitReceipt(string $queue, string $operation, string $request): array
    {
        self::id($operation); self::id($request);
        $this->context('read_commit_receipt', $queue, $operation, ['control']);
        $row = $this->one('SELECT * FROM hotel_collection_requests WHERE queue_id=? AND operation_id=? AND request_id=?', [$queue, $operation, $request]);
        if ($row === null || $row['method'] !== 'commit' || !hash_equals($row['receipt_sha256'], hash('sha256', $row['receipt_bytes']))) $this->reject('commit_receipt_unavailable');
        $receipt = self::decode($row['receipt_bytes']);
        $this->baseline($queue); $this->verifyCommitReceipt($queue, $operation, $receipt);
        return $receipt;
    }

    private function context(string $action, string $queue, ?string $operation, array $roles): array
    {
        self::id($queue); if ($operation !== null) self::id($operation);
        if (!$this->pdo->inTransaction()) throw new LogicException('canonical_store_caller_transaction_required');
        $ctx = ($this->lockedContextProvider)($action, $queue, $operation, $this->pdo);
        if (!is_array($ctx) || ($ctx['queue_id'] ?? null) !== $queue || !in_array($ctx['authority'] ?? null, $roles, true)
            || !is_int($ctx['owner_admin_id'] ?? null) || $ctx['owner_admin_id'] < 1) $this->reject('trusted_context_required');
        self::id($ctx['owner_connection_id'] ?? null);
        if ($operation !== null && $action !== 'read_commit_receipt') {
            if (($ctx['operation_id'] ?? null) !== $operation || !is_int($ctx['generation'] ?? null) || $ctx['generation'] < 1) $this->reject('operation_context_mismatch');
            self::id($ctx['stage_id'] ?? null); self::sha($ctx['reserve_sha256'] ?? null);
        }
        return $ctx;
    }

    private function documentContext(array $ctx, int $revision, string $baselineSha): array
    {
        $doc = $ctx['document_context'] ?? null;
        if (!is_array($doc) || ($doc['queue_id'] ?? null) !== $ctx['queue_id'] || ($doc['operation_id'] ?? null) !== $ctx['operation_id'] || ($doc['stage_id'] ?? null) !== $ctx['stage_id']) $this->reject('document_context_mismatch');
        $doc['base_revision'] = $revision; $doc['baseline_sha256'] = $baselineSha;
        $keys = array_keys($doc); sort($keys, SORT_STRING);
        $expected = ['queue_id','operation_id','stage_id','country','scope_id','source_packet_id','hotel_keys','base_revision','baseline_sha256']; sort($expected, SORT_STRING);
        if ($keys !== $expected || !is_string($doc['country']) || preg_match('/\A[A-Z]{2}\z/D', $doc['country']) !== 1
            || !is_array($doc['hotel_keys']) || !array_is_list($doc['hotel_keys']) || count($doc['hotel_keys']) < 1 || count($doc['hotel_keys']) > 32) $this->reject('document_context_invalid');
        self::id($doc['scope_id']); self::id($doc['source_packet_id']);
        foreach ($doc['hotel_keys'] as $hotel) self::id($hotel);
        sort($doc['hotel_keys'], SORT_STRING);
        if (count(array_unique($doc['hotel_keys'])) !== count($doc['hotel_keys'])) $this->reject('document_scope_duplicate');
        return $doc;
    }

    private function checkAssignment(array $ctx, array $row, array $baseline): array
    {
        if ((int)$row['generation'] !== $ctx['generation'] || $row['stage_id'] !== $ctx['stage_id'] || (int)$row['owner_admin_id'] !== $ctx['owner_admin_id']
            || $row['owner_connection_id'] !== $ctx['owner_connection_id'] || $row['reserve_sha256'] !== $ctx['reserve_sha256']) $this->reject('assignment_authority_or_reserves_changed');
        if (!hash_equals($row['context_sha256'], hash('sha256', $row['context_bytes']))) $this->reject('assignment_bytes_unavailable');
        $document = $this->documentContext($ctx, (int)$row['base_revision'], $baseline['baseline_sha256']);
        if (self::json($document) !== $row['context_bytes']) $this->reject('assignment_context_changed');
        return $document;
    }

    private function assignmentProjection(array $row, array $baseline): array
    {
        if (!hash_equals($row['context_sha256'], hash('sha256', $row['context_bytes']))) $this->reject('assignment_bytes_unavailable');
        $snapshot = $this->snapshot($row['queue_id'], $row['base_snapshot_sha256']);
        if ($snapshot['revision'] !== (int)$row['base_revision'] || $snapshot['baseline_sha256'] !== $baseline['baseline_sha256']) $this->reject('assignment_snapshot_mismatch');
        $context = self::decode($row['context_bytes']);
        $inScope = static fn(array $item): bool => in_array($item['hotel_key'], $context['hotel_keys'], true) && $item['country'] === $context['country'];
        $overlay = ['schema_version' => 1, 'baseline_sha256' => $baseline['baseline_sha256']];
        foreach (['facts','sources','read_updates','questions'] as $group) $overlay[$group] = array_values(array_filter($snapshot['overlay'][$group], $inScope));
        $index = ['facts' => array_values(array_filter($baseline['index']['facts'], $inScope)), 'sources' => array_values(array_filter($baseline['index']['sources'], $inScope))];
        return ['operation_id' => $row['operation_id'], 'generation' => (int)$row['generation'], 'context' => $context,
            'base_revision' => (int)$row['base_revision'], 'base_snapshot_sha256' => $row['base_snapshot_sha256'],
            'baseline_sha256' => $baseline['baseline_sha256'], 'baseline_scope_index' => $index, 'scope_overlay' => $overlay,
            'opaque_baseline_content_included' => false,
            'projection_contract' => self::CONTRACT, 'baseline_mapping' => 'control_attested_index_not_legacy_decoder', 'business_accepted' => false, 'native_run_verified' => false];
    }

    private function projection(string $queue, array $head, array $baseline): array
    {
        $snapshot = $this->snapshot($queue, $head['snapshot_sha256']);
        if ($snapshot['revision'] !== (int)$head['revision'] || $snapshot['baseline_sha256'] !== $baseline['baseline_sha256']) $this->reject('head_snapshot_mismatch');
        return ['queue_id' => $queue, 'revision' => (int)$head['revision'], 'snapshot_sha256' => $head['snapshot_sha256'],
            'baseline_sha256' => $baseline['baseline_sha256'], 'baseline_bytes' => $baseline['baseline_bytes'],
            'baseline_index' => $baseline['index'], 'baseline_index_sha256' => $baseline['index_sha256'], 'baseline_index_attestation' => $baseline['index_attestation'],
            'overlay' => $snapshot['overlay'], 'projection_contract' => self::CONTRACT,
            'baseline_mapping' => 'control_attested_index_not_legacy_decoder', 'business_accepted' => false, 'native_run_verified' => false];
    }

    private function baseline(string $queue): array
    {
        $row = $this->one('SELECT * FROM hotel_collection_baselines WHERE queue_id=?', [$queue]);
        if ($row === null || !hash_equals($row['baseline_sha256'], hash('sha256', $row['baseline_bytes'])) || !hash_equals($row['index_sha256'], hash('sha256', $row['index_bytes']))) $this->reject('baseline_bytes_unavailable');
        $row['index'] = self::decode($row['index_bytes']);
        HotelCollectionCanonicalDocument::validateBaselineIndex($row['index'], $row['baseline_sha256']);
        return $row;
    }

    private function writeSnapshot(string $queue, int $revision, ?string $previous, string $baseline, array $overlay, ?string $operation): string
    {
        $bytes = self::json($overlay); $overlaySha = hash('sha256', $bytes);
        $indexSha = $this->baseline($queue)['index_sha256'];
        $sha = hash('sha256', self::json([$queue, $revision, $previous, $baseline, $indexSha, $overlaySha, $operation]));
        $this->execute('INSERT INTO hotel_collection_snapshots(snapshot_sha256,queue_id,revision,previous_sha256,baseline_sha256,baseline_index_sha256,overlay_sha256,overlay_bytes,operation_id) VALUES(?,?,?,?,?,?,?,?,?)',
            [$sha, $queue, $revision, $previous, $baseline, $indexSha, $overlaySha, $bytes, $operation]);
        return $sha;
    }

    private function snapshot(string $queue, string $sha): array
    {
        self::sha($sha);
        $row = $this->one('SELECT * FROM hotel_collection_snapshots WHERE queue_id=? AND snapshot_sha256=?', [$queue, $sha]);
        if ($row === null || !hash_equals($row['overlay_sha256'], hash('sha256', $row['overlay_bytes']))
            || !hash_equals($sha, hash('sha256', self::json([$queue, (int)$row['revision'], $row['previous_sha256'], $row['baseline_sha256'], $row['baseline_index_sha256'], $row['overlay_sha256'], $row['operation_id']])))) $this->reject('snapshot_bytes_unavailable');
        if ($this->baseline($queue)['index_sha256'] !== $row['baseline_index_sha256']) $this->reject('snapshot_index_mismatch');
        $row['revision'] = (int)$row['revision']; $row['overlay'] = self::decode($row['overlay_bytes']);
        if ($row['revision'] > 0) {
            $ledger = $this->one('SELECT * FROM hotel_collection_ledger WHERE queue_id=? AND revision=? AND operation_id=?', [$queue, $row['revision'], $row['operation_id']]);
            if ($ledger === null || !hash_equals($ledger['ledger_sha256'], hash('sha256', $ledger['ledger_bytes']))) $this->reject('ledger_bytes_unavailable');
            $body = self::decode($ledger['ledger_bytes']);
            if (($body['output_snapshot_sha256'] ?? null) !== $sha || ($body['output_revision'] ?? null) !== $row['revision']) $this->reject('ledger_snapshot_mismatch');
            $candidate = $this->candidate($queue, $row['operation_id']);
            if (($body['packet_raw_sha256'] ?? null) !== $candidate['raw_sha256']
                || ($body['packet_semantic_sha256'] ?? null) !== $candidate['semantic_sha256']
                || $ledger['semantic_sha256'] !== $candidate['semantic_sha256']) $this->reject('ledger_candidate_mismatch');
        }
        return $row;
    }

    private function candidate(string $queue, string $operation): array
    {
        $row = $this->one('SELECT * FROM hotel_collection_candidates WHERE queue_id=? AND operation_id=?', [$queue, $operation]);
        if ($row === null || !hash_equals($row['raw_sha256'], hash('sha256', $row['raw_bytes']))) $this->reject('candidate_bytes_unavailable');
        return $row;
    }

    private function assignment(string $queue, string $operation): array
    {
        return $this->one('SELECT * FROM hotel_collection_assignments WHERE queue_id=? AND operation_id=?', [$queue, $operation]) ?? throw new DomainException('canonical_store_assignment_missing');
    }

    private function head(string $queue): ?array
    {
        return $this->one('SELECT * FROM hotel_collection_heads WHERE queue_id=?'.($this->driver === 'mysql' ? ' FOR UPDATE' : ''), [$queue]);
    }

    private function requiredHead(string $queue): array
    {
        return $this->head($queue) ?? throw new DomainException('canonical_store_not_bootstrapped');
    }

    private function replay(string $queue, string $operation, string $request, string $method, array $input): ?array
    {
        $row = $this->one('SELECT * FROM hotel_collection_requests WHERE queue_id=? AND operation_id=? AND request_id=?', [$queue, $operation, $request]);
        if ($row === null) return null;
        if ($row['method'] !== $method || !hash_equals($row['input_sha256'], hash('sha256', self::json($input)))) $this->reject('request_conflict');
        if (!hash_equals($row['receipt_sha256'], hash('sha256', $row['receipt_bytes']))) $this->reject('receipt_bytes_unavailable');
        return self::decode($row['receipt_bytes']);
    }

    private function verifyCommitReceipt(string $queue, string $operation, array $receipt): void
    {
        if (($receipt['operation_id'] ?? null) !== $operation || !is_string($receipt['snapshot_sha256'] ?? null)) $this->reject('receipt_operation_mismatch');
        $snapshot = $this->snapshot($queue, $receipt['snapshot_sha256']);
        if ($snapshot['operation_id'] !== $operation || ($receipt['revision'] ?? null) !== $snapshot['revision']
            || ($receipt['baseline_sha256'] ?? null) !== $snapshot['baseline_sha256']) $this->reject('receipt_snapshot_mismatch');
    }

    private function receipt(string $queue, string $operation, string $request, string $method, array $input, array $out): array
    {
        $bytes = self::json($out);
        $this->execute('INSERT INTO hotel_collection_requests(queue_id,operation_id,request_id,method,input_sha256,receipt_sha256,receipt_bytes) VALUES(?,?,?,?,?,?,?)',
            [$queue, $operation, $request, $method, hash('sha256', self::json($input)), hash('sha256', $bytes), $bytes]);
        return self::decode($bytes);
    }

    private function now(): string
    {
        $timestamp = $this->clock === null ? time() : ($this->clock)();
        if (!is_int($timestamp) || $timestamp < 0) throw new LogicException('canonical_store_invalid_clock');
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function one(string $sql, array $args): ?array
    {
        $stmt = $this->pdo->prepare($sql); $stmt->execute($args); $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function execute(string $sql, array $args): int
    {
        $stmt = $this->pdo->prepare($sql); $stmt->execute($args); return $stmt->rowCount();
    }

    private static function json(mixed $value): string
    {
        $normalize = static function (mixed $v) use (&$normalize): mixed {
            if (!is_array($v)) return $v;
            if (!array_is_list($v)) ksort($v, SORT_STRING);
            foreach ($v as $key => $child) $v[$key] = $normalize($child);
            return $v;
        };
        return json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function decode(string $bytes): array
    {
        try { $out = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new DomainException('canonical_store_stored_json_unavailable', 0, $e); }
        if (!is_array($out) || array_is_list($out)) throw new DomainException('canonical_store_stored_json_unavailable');
        return $out;
    }

    private static function id(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/D', $value) !== 1) throw new InvalidArgumentException('canonical_store_invalid_id');
    }

    private static function sha(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) throw new InvalidArgumentException('canonical_store_invalid_sha256');
    }

    private function reject(string $reason): never { throw new DomainException('canonical_store_'.$reason); }
    private function hit(string $point): void { if ($this->fault !== null) ($this->fault)($point); }
}
