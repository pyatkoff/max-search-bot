<?php
declare(strict_types=1);
require_once __DIR__.'/HotelWorkQueueFactory.php';

/**
 * Dormant internal prototype. No MCP mapping, schema activation or launcher.
 * The constructor's admin/connection come from server authentication, not args.
 * Control capabilities belong to a server-owned path, never shared CP/status.
 * Canonical means the SQL snapshot below, NOT the existing Library catalogue.
 */
final class HotelWorkOperationJournalPrototype
{
    private string $driver;

    public function __construct(private PDO $pdo, private int $admin, private string $connection, private $fault = null)
    {
        $this->driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver, ['sqlite', 'mysql'], true) || $admin < 1
            || preg_match('/\A[a-f0-9]{32}\z/D', $connection) !== 1 || ($fault !== null && !is_callable($fault))) {
            throw new InvalidArgumentException('invalid_journal_context');
        }
    }

    /** Internal bootstrap only. Does not adopt/migrate any existing operation. */
    public function enroll(string $queue, string $stage, string $baseline, string $control, string $owner): array
    {
        self::id($queue); self::id($stage); self::hex($baseline); self::hex($control); self::id($owner);
        return $this->tx('enroll', function () use ($queue, $stage, $baseline, $control, $owner): array {
            $queueRow = $this->authorizeQueue($queue, $baseline);
            if (!array_key_exists('write_protocol', $queueRow)) $this->reject('journal_marker_unavailable');
            $head = $this->head($queue, $stage, true);
            if ($head !== null) {
                if ($queueRow['write_protocol'] !== 'journal_v1') $this->reject('journal_marker_mismatch');
                $this->control($head, 1, $control);
                if ($head['baseline_sha256'] !== $baseline || $head['owner_label'] !== $owner) $this->reject('enrollment_conflict');
                return ['generation' => 1, 'mode' => 'dormant_prototype'];
            }
            if (!in_array($queueRow['write_protocol'], ['legacy', 'journal_v1'], true)) $this->reject('journal_marker_mismatch');
            if ($queueRow['write_protocol'] === 'journal_v1' && $this->one('SELECT stage_id FROM hotel_journal_heads WHERE queue_id=? ORDER BY stage_id LIMIT 1'.($this->driver === 'mysql' ? ' FOR UPDATE' : ''), [$queue]) === null) $this->reject('journal_protection_unavailable');
            if ($queueRow['state'] !== 'ready' || $queueRow['claim_sha256'] !== null || $queueRow['current_run_id'] !== null) $this->reject('live_adoption_not_supported');
            if ($queueRow['_lane_queue'] !== $queue) $this->reject('journal_lane_not_owned');
            if (hash('sha256', self::json([$queue, $queueRow['plan_sha256'], (int)$queueRow['stage_index']])) !== $stage) $this->reject('stage_identity_mismatch');
            $this->execute('INSERT INTO hotel_journal_heads (queue_id,stage_id,baseline_sha256,generation,control_sha256,owner_label,active_operation_id,accepted_snapshot_id,hold_reason) VALUES (?,?,?,1,?,?,NULL,NULL,NULL)',
                [$queue, $stage, $baseline, hash('sha256', $control), $owner]);
            $this->execute('UPDATE hotel_work_queues SET write_protocol=? WHERE queue_id=?', ['journal_v1', $queue]);
            $this->hit('enrollment_written');
            return ['generation' => 1, 'mode' => 'dormant_prototype'];
        });
    }

    /** Stable event/request identity and capability are committed together. */
    public function intent(string $queue, string $stage, int $generation, string $control, string $request, string $event, string $task): array
    {
        self::id($request); self::id($event); self::id($task);
        return $this->tx('intent', function () use ($queue, $stage, $generation, $control, $request, $event, $task): array {
            $head = $this->controlledHead($queue, $stage, $generation, $control);
            $input = compact('event', 'task');
            if (($old = $this->replay($head, $request, 'intent', $input)) !== null) return $old;
            $this->currentStage($head);
            if ($head['hold_reason'] !== null) $this->reject('native_effect_unknown');
            if ($head['accepted_snapshot_id'] !== null) $this->reject('stage_already_committed');
            if ($head['active_operation_id'] !== null) $this->reject('event_already_admitted');
            $operation = hash('sha256', self::json([$queue, $stage, $generation, $event]));
            $capability = bin2hex(random_bytes(32));
            $this->execute('INSERT INTO hotel_journal_operations (operation_id,queue_id,stage_id,generation,event_id,task_id,state,capability_sha256,instance_id,reported_run_id,run_evidence_sha256,candidate_sha256,candidate_bytes,native_effect) VALUES (?,?,?,?,?,?,?, ?,NULL,NULL,NULL,NULL,NULL,?)',
                [$operation, $queue, $stage, $generation, $event, $task, 'prepared', hash('sha256', $capability), 'not_dispatched_by_journal']);
            $this->execute('UPDATE hotel_journal_heads SET active_operation_id=? WHERE queue_id=? AND stage_id=?', [$operation, $queue, $stage]);
            $this->hit('intent_written');
            return $this->receipt($head, $request, 'intent', $input,
                ['status' => 'intent_recorded', 'operation_id' => $operation, 'generation' => $generation, 'worker_capability' => $capability]);
        });
    }

    /** Persist BEFORE external send. No method here sends or retries native work. */
    public function dispatchIntent(string $queue, string $stage, int $generation, string $control, string $operation, string $request): array
    {
        return $this->tx('dispatch', function () use ($queue, $stage, $generation, $control, $operation, $request): array {
            $head = $this->controlledHead($queue, $stage, $generation, $control);
            $input = compact('operation');
            if (($old = $this->replay($head, $request, 'dispatch', $input)) !== null) return $old;
            $this->currentStage($head);
            $op = $this->operation($head, $operation);
            if ($op['state'] !== 'prepared') $this->reject('dispatch_effect_not_replayable');
            $this->execute('UPDATE hotel_journal_operations SET state=?,native_effect=? WHERE operation_id=?', ['native_unknown', 'unknown', $operation]);
            $this->execute('UPDATE hotel_journal_heads SET hold_reason=? WHERE queue_id=? AND stage_id=?', ['native_effect_unknown', $queue, $stage]);
            $this->hit('dispatch_intent_written');
            return $this->receipt($head, $request, 'dispatch', $input,
                ['status' => 'dispatch_intent_recorded', 'operation_id' => $operation, 'native_effect' => 'unknown', 'dispatch_authority' => false]);
        });
    }

    /** Workers receive only their private operation capability, not control. */
    public function admit(string $queue, string $stage, int $generation, string $operation, string $capability, string $request, string $instance): array
    {
        self::id($instance);
        return $this->tx('admit', function () use ($queue, $stage, $generation, $operation, $capability, $request, $instance): array {
            [$head, $op] = $this->worker($queue, $stage, $generation, $operation, $capability);
            $input = compact('operation', 'instance');
            if (($old = $this->replay($head, $request, 'admit', $input)) !== null) return $old;
            $this->currentStage($head);
            if ($op['state'] !== 'native_unknown' || $op['instance_id'] !== null) $this->reject('admission_already_spent');
            $this->execute('UPDATE hotel_journal_operations SET instance_id=?,state=? WHERE operation_id=?', [$instance, 'admitted', $operation]);
            $this->hit('admission_written');
            return $this->receipt($head, $request, 'admit', $input,
                ['status' => 'admission_recorded', 'operation_id' => $operation, 'generation' => $generation, 'instance_id' => $instance]);
        });
    }

    /** Store actual immutable bytes, not supplied hashes or Library pointers. */
    public function result(string $queue, string $stage, int $generation, string $operation, string $capability, string $request, string $instance, string $bytes, ?string $run, ?string $runEvidence): array
    {
        self::candidate($bytes); self::id($instance);
        if ($run !== null) self::id($run);
        if ($runEvidence !== null) self::hex($runEvidence);
        return $this->tx('result', function () use ($queue, $stage, $generation, $operation, $capability, $request, $instance, $bytes, $run, $runEvidence): array {
            [$head, $op] = $this->worker($queue, $stage, $generation, $operation, $capability);
            $packet = hash('sha256', $bytes);
            $input = ['operation' => $operation, 'instance' => $instance, 'packet' => $packet, 'run' => $run, 'run_evidence' => $runEvidence];
            if (($old = $this->replay($head, $request, 'result', $input)) !== null) return $old;
            $this->currentStage($head);
            if ($op['instance_id'] !== $instance) $this->reject('instance_mismatch');
            if ($op['candidate_sha256'] !== null) $this->reject('candidate_conflict');
            if ($op['state'] !== 'admitted') $this->reject('admission_required');
            $this->execute('UPDATE hotel_journal_operations SET state=?,reported_run_id=?,run_evidence_sha256=?,candidate_sha256=?,candidate_bytes=? WHERE operation_id=?',
                ['candidate_recorded', $run, $runEvidence, $packet, $bytes, $operation]);
            $this->hit('candidate_written');
            return $this->receipt($head, $request, 'result', $input,
                ['status' => 'candidate_recorded', 'operation_id' => $operation, 'packet_sha256' => $packet, 'native_run_verified' => false]);
        });
    }

    /** Control-plane reviewer can independently retrieve the exact stored bytes. */
    public function readCandidate(string $queue, string $stage, int $generation, string $control, string $operation): array
    {
        return $this->tx('read_candidate', function () use ($queue, $stage, $generation, $control, $operation): array {
            $head = $this->controlledHead($queue, $stage, $generation, $control);
            $op = $this->operation($head, $operation);
            if ($op['candidate_bytes'] === null || hash('sha256', $op['candidate_bytes']) !== $op['candidate_sha256']) $this->reject('candidate_bytes_unavailable');
            return ['packet_sha256' => $op['candidate_sha256'], 'bytes' => $op['candidate_bytes']];
        });
    }

    /** Snapshot + delta/ledger bytes + protected pointer + receipt: one transaction. */
    public function commit(string $queue, string $stage, int $generation, string $control, string $operation, string $request, string $reviewedPacket, ?string $expectedPointer = null): array
    {
        self::hex($reviewedPacket);
        if ($expectedPointer !== null) self::hex($expectedPointer);
        return $this->tx('commit', function () use ($queue, $stage, $generation, $control, $operation, $request, $reviewedPacket, $expectedPointer): array {
            $head = $this->controlledHead($queue, $stage, $generation, $control);
            $input = compact('operation', 'reviewedPacket', 'expectedPointer');
            if (($old = $this->replay($head, $request, 'commit', $input)) !== null) return $old;
            $this->currentStage($head);
            $op = $this->operation($head, $operation);
            if ($head['accepted_snapshot_id'] !== $expectedPointer || $head['accepted_snapshot_id'] !== null) $this->reject('canonical_pointer_conflict');
            if ($op['state'] !== 'candidate_recorded' || $op['candidate_bytes'] === null
                || $op['candidate_sha256'] !== $reviewedPacket || hash('sha256', $op['candidate_bytes']) !== $reviewedPacket) $this->reject('candidate_readback_mismatch');
            // Lock/unique constraint also protects the same packet across stages.
            if ($this->one('SELECT snapshot_id FROM hotel_journal_snapshots WHERE queue_id=? AND packet_sha256=?', [$queue, $reviewedPacket]) !== null) $this->reject('packet_already_committed');
            $snapshot = hash('sha256', self::json([$queue, $stage, $operation, $reviewedPacket]));
            $this->execute('INSERT INTO hotel_journal_snapshots (snapshot_id,queue_id,stage_id,operation_id,generation,event_id,packet_sha256,body_bytes) VALUES (?,?,?,?,?,?,?,?)',
                [$snapshot, $queue, $stage, $operation, $generation, $op['event_id'], $reviewedPacket, $op['candidate_bytes']]);
            $this->hit('snapshot_inserted');
            $this->execute('UPDATE hotel_journal_heads SET accepted_snapshot_id=?,active_operation_id=NULL,hold_reason=NULL WHERE queue_id=? AND stage_id=?', [$snapshot, $queue, $stage]);
            $this->execute('UPDATE hotel_journal_operations SET state=? WHERE operation_id=?', ['snapshot_committed', $operation]);
            $this->hit('canonical_pointer_updated');
            return $this->receipt($head, $request, 'commit', $input,
                ['status' => 'canonical_snapshot_committed', 'snapshot_id' => $snapshot, 'operation_id' => $operation,
                    'generation' => $generation, 'packet_sha256' => $reviewedPacket, 'business_accepted' => false]);
        });
    }

    /** Control capability rotates too. Generation itself is never a credential. */
    public function handover(string $queue, string $stage, int $generation, string $control, string $newControl, string $newOwner, string $request): array
    {
        self::hex($newControl); self::id($newOwner);
        if (hash_equals($control, $newControl)) throw new InvalidArgumentException('control_rotation_required');
        return $this->tx('handover', function () use ($queue, $stage, $generation, $control, $newControl, $newOwner, $request): array {
            $head = $this->requiredHead($queue, $stage);
            $input = ['new_control_sha256' => hash('sha256', $newControl), 'owner' => $newOwner];
            // A lost handover ACK can be read back only by possession of the
            // NEW server-control credential, never by knowing a generation.
            if ((int)$head['generation'] === $generation + 1 && $head['control_sha256'] === hash('sha256', $newControl)) {
                $priorHead = $head; $priorHead['generation'] = $generation;
                if (($old = $this->replay($priorHead, $request, 'handover', $input)) !== null) return $old;
                $this->reject('handover_request_required');
            }
            $this->control($head, $generation, $control);
            if (($old = $this->replay($head, $request, 'handover', $input)) !== null) return $old;
            $this->currentStage($head);
            $hold = $head['hold_reason']; $active = $head['active_operation_id'];
            if ($active !== null) {
                $op = $this->operation($head, $active);
                if ($op['state'] === 'prepared') {
                    $this->execute('UPDATE hotel_journal_operations SET state=? WHERE operation_id=?', ['retired_before_dispatch_intent', $active]);
                    $active = null;
                } else $hold = 'native_effect_unknown'; // Not cancellation, expiry, or permission to retry.
            }
            $this->execute('UPDATE hotel_journal_heads SET generation=?,control_sha256=?,owner_label=?,active_operation_id=?,hold_reason=? WHERE queue_id=? AND stage_id=?',
                [$generation + 1, hash('sha256', $newControl), $newOwner, $active, $hold, $queue, $stage]);
            $this->hit('generation_updated');
            return $this->receipt($head, $request, 'handover', $input,
                ['status' => 'generation_handed_over', 'generation' => $generation + 1, 'native_hold' => $hold !== null]);
        });
    }

    /** Metadata/read model has no capabilities, their hashes, or private ACKs. */
    public function status(string $queue, string $stage): array
    {
        return $this->tx('status', function () use ($queue, $stage): array {
            $head = $this->requiredHead($queue, $stage);
            $ops = $this->pdo->prepare('SELECT operation_id,generation,event_id,task_id,state,native_effect,instance_id,reported_run_id,run_evidence_sha256,candidate_sha256 FROM hotel_journal_operations WHERE queue_id=? AND stage_id=? ORDER BY generation DESC,operation_id LIMIT 50');
            $ops->execute([$queue, $stage]);
            $total = $this->one('SELECT COUNT(*) AS n FROM hotel_journal_operations WHERE queue_id=? AND stage_id=?', [$queue, $stage]);
            return ['mode' => 'dormant_prototype', 'queue_id' => $queue, 'stage_id' => $stage,
                'generation' => (int)$head['generation'], 'owner_label' => $head['owner_label'],
                'hold_reason' => $head['hold_reason'], 'accepted_snapshot_id' => $head['accepted_snapshot_id'],
                'canonical_commits' => $head['accepted_snapshot_id'] === null ? 0 : 1,
                'business_accepted' => false, 'native_run_verified' => false,
                'history_count' => (int)$total['n'], 'history_truncated' => (int)$total['n'] > 50,
                'operations' => $ops->fetchAll(PDO::FETCH_ASSOC)];
        });
    }

    public function canonicalSnapshot(string $queue, string $stage): ?array
    {
        return $this->tx('canonical_read', function () use ($queue, $stage): ?array {
            $head = $this->requiredHead($queue, $stage);
            if ($head['accepted_snapshot_id'] === null) return null;
            $row = $this->one('SELECT * FROM hotel_journal_snapshots WHERE snapshot_id=? AND queue_id=? AND stage_id=?', [$head['accepted_snapshot_id'], $queue, $stage]);
            if ($row === null || hash('sha256', $row['body_bytes']) !== $row['packet_sha256']) $this->reject('canonical_bytes_unavailable');
            return ['snapshot_id' => $row['snapshot_id'], 'packet_sha256' => $row['packet_sha256'], 'bytes' => $row['body_bytes']];
        });
    }

    /** Optional extra bridge; intrinsic Queue protection does not depend on it. */
    public function legacyGuard(PDO $pdo): callable
    {
        if ($pdo !== $this->pdo) throw new InvalidArgumentException('journal_pdo_mismatch');
        return HotelWorkQueueFactory::journalGuard($pdo);
    }

    private function requiredHead(string $queue, string $stage): array
    {
        self::id($queue); self::id($stage);
        // Same order as legacy Queue/trait transactions, including authenticated reads.
        $row = $this->authorizeQueue($queue);
        $head = $this->head($queue, $stage, true);
        if ($head === null) $this->reject('stage_not_enrolled');
        if (($row['write_protocol'] ?? null) !== 'journal_v1') $this->reject('journal_marker_mismatch');
        if ($row['baseline_sha256'] !== $head['baseline_sha256']) $this->reject('journal_access_denied');
        $head['_current_stage'] = hash('sha256', self::json([$queue, $row['plan_sha256'], (int)$row['stage_index']]));
        $head['_lane_queue'] = $row['_lane_queue'];
        return $head;
    }
    private function currentStage(array $head): void
    {
        if ($head['_current_stage'] !== $head['stage_id']) $this->reject('stale_stage');
        if ($head['_lane_queue'] !== $head['queue_id']) $this->reject('journal_lane_not_owned');
    }
    private function controlledHead(string $queue, string $stage, int $generation, string $control): array
    {
        $head = $this->requiredHead($queue, $stage); $this->control($head, $generation, $control); return $head;
    }
    private function control(array $head, int $generation, string $control): void
    {
        self::hex($control);
        if (!hash_equals($head['control_sha256'], hash('sha256', $control))) throw new DomainException('control_capability_required');
        if ((int)$head['generation'] !== $generation) $this->reject('stale_generation');
    }
    private function worker(string $queue, string $stage, int $generation, string $operation, string $capability): array
    {
        $head = $this->requiredHead($queue, $stage); self::hex($capability);
        if ((int)$head['generation'] !== $generation) $this->reject('stale_generation');
        $op = $this->operation($head, $operation);
        if (!hash_equals($op['capability_sha256'], hash('sha256', $capability))) throw new DomainException('worker_capability_required');
        return [$head, $op];
    }
    private function operation(array $head, string $operation): array
    {
        self::hex($operation);
        $op = $this->one('SELECT * FROM hotel_journal_operations WHERE operation_id=? AND queue_id=? AND stage_id=?', [$operation, $head['queue_id'], $head['stage_id']]);
        if ($op === null || (int)$op['generation'] !== (int)$head['generation']) $this->reject('operation_generation_mismatch');
        return $op;
    }
    private function head(string $queue, string $stage, bool $lock): ?array
    {
        return $this->one('SELECT * FROM hotel_journal_heads WHERE queue_id=? AND stage_id=?'.($lock && $this->driver === 'mysql' ? ' FOR UPDATE' : ''), [$queue, $stage]);
    }
    private function authorizeQueue(string $queue, ?string $baseline = null): array
    {
        $lock = $this->driver === 'mysql' ? ' FOR UPDATE' : '';
        $lane = $this->one('SELECT queue_id FROM hotel_work_lane WHERE lane_key=?'.$lock, ['HOTEL']);
        if ($lane === null) $this->reject('journal_lane_unavailable');
        $row = $this->one('SELECT * FROM hotel_work_queues WHERE queue_id=?'.$lock, [$queue]);
        if ($row === null || $row['project_key'] !== 'anytour' || (int)$row['admin_id'] !== $this->admin
            || $row['oauth_connection_id'] !== $this->connection || ($baseline !== null && $row['baseline_sha256'] !== $baseline)) throw new DomainException('journal_access_denied');
        $row['_lane_queue'] = $lane['queue_id'];
        $this->hit('queue_locked');
        return $row;
    }
    private function replay(array $head, string $request, string $method, array $input): ?array
    {
        self::id($request);
        $row = $this->one('SELECT * FROM hotel_journal_requests WHERE queue_id=? AND stage_id=? AND request_id=?', [$head['queue_id'], $head['stage_id'], $request]);
        if ($row === null) return null;
        if ((int)$row['generation'] !== (int)$head['generation'] || $row['method'] !== $method || $row['request_sha256'] !== hash('sha256', self::json($input))) $this->reject('request_conflict');
        return json_decode($row['receipt_bytes'], true, 32, JSON_THROW_ON_ERROR);
    }
    private function receipt(array $head, string $request, string $method, array $input, array $body): array
    {
        self::id($request); $bytes = self::json($body);
        $this->execute('INSERT INTO hotel_journal_requests (queue_id,stage_id,request_id,generation,method,request_sha256,receipt_bytes) VALUES (?,?,?,?,?,?,?)',
            [$head['queue_id'], $head['stage_id'], $request, (int)$head['generation'], $method, hash('sha256', self::json($input)), $bytes]);
        $this->hit('receipt_written:'.$method); return json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
    }
    private function tx(string $method, callable $work): mixed
    {
        if ($this->pdo->inTransaction()) throw new LogicException('journal_foreign_transaction');
        if ($this->driver === 'sqlite') $this->pdo->exec('BEGIN IMMEDIATE'); else $this->pdo->beginTransaction();
        try {
            $result = $work();
            if ($this->driver === 'sqlite') $this->pdo->exec('COMMIT'); else $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->driver === 'sqlite') $this->pdo->exec('ROLLBACK'); elseif ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($error instanceof PDOException && str_starts_with((string)$error->getCode(), '23')) $this->reject('journal_unique_conflict');
            throw $error;
        }
        $this->hit('after_commit:'.$method); // Lost ACK: deliberately outside rollback scope.
        return $result;
    }
    private function one(string $sql, array $args): ?array { $q = $this->pdo->prepare($sql); $q->execute($args); return $q->fetch(PDO::FETCH_ASSOC) ?: null; }
    private function execute(string $sql, array $args): void { $q = $this->pdo->prepare($sql); $q->execute($args); }
    private function hit(string $point): void { if ($this->fault !== null) ($this->fault)($point); }
    private function reject(string $reason): never { throw new DomainException($reason); }
    private static function id(string $id): void { if (preg_match('/\A[a-zA-Z0-9_.:-]{1,128}\z/D', $id) !== 1) throw new InvalidArgumentException('invalid_journal_id'); }
    private static function hex(string $value): void { if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) throw new InvalidArgumentException('invalid_journal_sha_or_capability'); }
    private static function json(array $value): string
    {
        $sort = static function (array $array) use (&$sort): array {
            if (!array_is_list($array)) ksort($array, SORT_STRING);
            foreach ($array as &$value) if (is_array($value)) $value = $sort($value);
            return $array;
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    private static function candidate(string $bytes): void
    {
        if (strlen($bytes) > 262144) throw new InvalidArgumentException('candidate_too_large');
        $body = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($body) || ($body['schema_version'] ?? null) !== 1 || count($body) !== 4) throw new InvalidArgumentException('candidate_bytes_required');
        foreach (['hotels', 'delta', 'ledger'] as $key) if (!isset($body[$key]) || !is_array($body[$key]) || !array_is_list($body[$key])) throw new InvalidArgumentException('candidate_bytes_required');
    }
}
