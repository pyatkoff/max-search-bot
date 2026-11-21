<?php
declare(strict_types=1);
if (!class_exists('Max2CommonValueAccountingV2', false)) require_once __DIR__ . '/Max2CommonValueAccountingV2.php';

/**
 * Own V2 reservations only. No provider, delivery call, DDL, v1 read/write or retry.
 * Source adapters must authenticate facts before calling this INTERNAL service.
 * Default OFF. The caller's actual durable activation is a future integration;
 * a synthetic digest in tests is not an operational permission or release.
 */
final class Max2CommonValueRepositoryV2
{
    private PDO $pdo;
    private array $activation;
    private string $forUpdate;
    public function __construct(PDO $pdo, array $activation = ['mode' => 'off'], bool $syntheticMemoryOnly = false)
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($syntheticMemoryOnly) {
            if ($driver !== 'sqlite') throw new InvalidArgumentException('SYNTHETIC_SQLITE_REQUIRED');
            foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $database) {
                if (($database['file'] ?? null) !== '') throw new InvalidArgumentException('SYNTHETIC_MEMORY_ONLY');
            }
        } elseif ($driver !== 'mysql') {
            throw new InvalidArgumentException('MYSQL_STORAGE_REQUIRED');
        }
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) throw new InvalidArgumentException('EXCEPTION_MODE_REQUIRED');
        $this->pdo = $pdo; $this->activation = $activation; $this->forUpdate = $driver === 'mysql' ? ' FOR UPDATE' : '';
    }
    private static function hold(string $reason): array {
        return ['status' => 'HOLD', 'reason' => $reason, 'provider_calls' => 0, 'reservations_added' => 0];
    }
    private static function cid($value): bool {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,63}\z/D', $value) === 1;
    }
    private static function unsigned($value): int {
        if (is_int($value) && $value >= 0) return $value;
        if (is_string($value) && preg_match('/\A(0|[1-9][0-9]{0,18})\z/D', $value) === 1
            && strlen($value) < 19) return (int)$value;
        throw new UnexpectedValueException('V2_STORED_INTEGER_INVALID');
    }
    private function query(string $sql, array $values = []): PDOStatement {
        $query = $this->pdo->prepare($sql); $query->execute($values); return $query;
    }
    private function rollback(): void {
        if ($this->pdo->rollBack() !== true) throw new RuntimeException('V2_ROLLBACK_UNCONFIRMED');
    }
    private function active(int $now): bool {
        $a = $this->activation;
        return in_array($a['mode'] ?? null,['collect','send'],true) && ($a['version'] ?? null) === 1
            && ($a['counter_id'] ?? null) === Max2ValueBindingPolicyV2::COUNTER
            && ($a['goal_id'] ?? null) === Max2ValueBindingPolicyV2::GOAL
            && ($a['target'] ?? null) === Max2ValueBindingPolicyV2::TARGET
            && ($a['producer_contract_sha256'] ?? null) === Max2ValueBindingPolicyV2::PRODUCER_CONTRACT_SHA256
            && Max2ValueBindingPolicyV2::digest($a['activation_sha256'] ?? null)
            && is_int($a['activated_at_unix'] ?? null)
            && $a['activated_at_unix'] > Max2ValueBindingPolicyV2::PRODUCER_CUTOVER_UNIX
            && $now >= $a['activated_at_unix'];
    }
    private function admission(array $binding, array $estimate, array $identity, array $recheck, int $now): ?string
    {
        if (!$this->active($now)) return 'V2_COLLECTION_OFF';
        if ($this->pdo->inTransaction()) return 'OUTER_TRANSACTION_REJECTED';
        if (!in_array($binding['status'] ?? null, ['PROVEN_NO_NEW_EARLY_RESERVE','UNIQUE_OBSERVED_MAX2'], true)
            || !Max2ValueBindingPolicyV2::subject($binding['subject'] ?? null)
            || !Max2ValueBindingPolicyV2::digest($binding['proof_sha256'] ?? null)
            || ($binding['producer_contract_sha256'] ?? null) !== Max2ValueBindingPolicyV2::PRODUCER_CONTRACT_SHA256
            || !Max2ValueBindingPolicyV2::positiveId($binding['inquiry_journal_id'] ?? null)
            || !Max2ValueBindingPolicyV2::positiveId($binding['inquiry_native_message_id'] ?? null)
            || !is_int($binding['inquiry_recorded_at_unix'] ?? null)
            || $binding['inquiry_recorded_at_unix'] < $this->activation['activated_at_unix']
            || !is_int($binding['proof_observed_at_unix'] ?? null)
            || $binding['proof_observed_at_unix'] < $binding['inquiry_recorded_at_unix']
            || $binding['proof_observed_at_unix'] > $now || $now - $binding['proof_observed_at_unix'] > 300
            || !is_int($estimate['event_unix'] ?? null) || !is_int($estimate['input_cutoff_unix'] ?? null)
            || !is_int($estimate['profile_observed_at_unix'] ?? null)
            || $estimate['input_cutoff_unix'] < $binding['inquiry_recorded_at_unix']
            || $estimate['input_cutoff_unix'] > $estimate['event_unix']
            || $estimate['event_unix'] > $estimate['profile_observed_at_unix']
            || $estimate['profile_observed_at_unix'] > $now) return 'PROSPECTIVE_SOURCE_UNPROVED';
        if (($binding['inquiry_key'] ?? null) !== Max2ValueBindingPolicyV2::inquiryKey($binding['subject'],$binding['inquiry_journal_id'])) return 'INQUIRY_KEY_CONFLICT';
        $expectedBinding = $binding['status']==='UNIQUE_OBSERVED_MAX2'
            ? hash('sha256',json_encode(['max2/common/binding/v2',$binding['inquiry_key'],$binding['tracker_record_id']??null,
                $binding['immutable_tuple_sha256']??null],JSON_THROW_ON_ERROR))
            : hash('sha256','max2/common/no-early-reserve/v2|'.$binding['inquiry_key']);
        if (($binding['binding_key']??null)!==$expectedBinding) return 'BINDING_KEY_CONFLICT';
        if (($identity['admitted'] ?? null) !== true || !self::cid($identity['client_id'] ?? null)
            || !Max2ValueBindingPolicyV2::sameSubject($identity['subject'] ?? null, $binding['subject'])
            || ($identity['inquiry_key'] ?? null) !== ($binding['inquiry_key'] ?? null)
            || !Max2ValueBindingPolicyV2::digest($identity['source_sha256'] ?? null)) return 'DELIVERY_IDENTITY_UNPROVED';
        if ($binding['status'] === 'UNIQUE_OBSERVED_MAX2') {
            if (!Max2ValueBindingPolicyV2::positiveId($binding['tracker_record_id'] ?? null)
                || !Max2ValueBindingPolicyV2::digest($binding['immutable_tuple_sha256'] ?? null)
                || !Max2ValueBindingPolicyV2::positiveId($binding['entry_id'] ?? null)
                || !Max2ValueBindingPolicyV2::positiveId($binding['publication_id'] ?? null)
                || !Max2ValueBindingPolicyV2::positiveId($binding['version_id'] ?? null)
                || !is_string($binding['offer_id'] ?? null) || preg_match('/\Amax_[1-9][0-9]{0,18}\z/D',$binding['offer_id']) !== 1
                || ($binding['max2_model_version'] ?? null) !== Max2ValueBindingPolicyV2::MAX2_MODEL
                || $identity['client_id'] !== ($binding['client_id'] ?? null)
                || ($identity['basis'] ?? null) !== 'bound_max2_receipt'
                || ($recheck['status'] ?? null) !== 'match' || ($recheck['action'] ?? null) !== 'recheck'
                || ($recheck['coverage'] ?? null) !== 'exact_selected_record_only'
                || ($recheck['tracker_record_id'] ?? null) !== ($binding['tracker_record_id'] ?? null)
                || ($recheck['immutable_tuple_sha256'] ?? null) !== ($binding['immutable_tuple_sha256'] ?? null)
                || !Max2ValueBindingPolicyV2::digest($recheck['source_sha256'] ?? null)
                || !is_int($recheck['checked_at_unix'] ?? null) || $recheck['checked_at_unix'] > $now
                || $recheck['checked_at_unix'] < $binding['proof_observed_at_unix'] || $now - $recheck['checked_at_unix'] > 60) return 'EXACT_RECEIPT_RECHECK_UNPROVED';
        } elseif (!in_array($identity['basis'] ?? null, ['saved_inquiry_identity','saved_v1_identity_read_only'], true) || $recheck !== []) {
            return 'NO_RESERVE_IDENTITY_CONFLICT';
        }
        return null;
    }
    private function newAccount(array $binding, array $identity, array $recheck, int $now): array
    {
        $s = $binding['subject']; $linked = $binding['status'] === 'UNIQUE_OBSERVED_MAX2';
        return ['version' => Max2CommonValueAccountingV2::VERSION, 'counter_id' => Max2ValueBindingPolicyV2::COUNTER,
            'goal_id' => Max2ValueBindingPolicyV2::GOAL, 'target' => Max2ValueBindingPolicyV2::TARGET,
            'project_key' => $s['project_key'], 'platform' => $s['platform'], 'customer_key' => $s['customer_key'],
            'conversation_id' => $s['conversation_id'], 'inquiry_key' => $binding['inquiry_key'],
            'inquiry_journal_id' => $binding['inquiry_journal_id'], 'inquiry_native_message_id' => $binding['inquiry_native_message_id'],
            'inquiry_recorded_at_unix' => $binding['inquiry_recorded_at_unix'], 'binding_key' => $binding['binding_key'],
            'binding_kind' => $binding['status'], 'tracker_record_id' => $linked ? $binding['tracker_record_id'] : null,
            'immutable_tuple_sha256' => $linked ? $binding['immutable_tuple_sha256'] : null,
            'entry_id' => $linked ? $binding['entry_id'] : null, 'publication_id' => $linked ? $binding['publication_id'] : null,
            'version_id' => $linked ? $binding['version_id'] : null, 'offer_id' => $linked ? $binding['offer_id'] : null,
            'client_id' => $identity['client_id'], 'identity_sha256' => $identity['source_sha256'],
            'initial_common_kopeks' => $binding['initial_common_kopeks'], 'max2_model_version' => $linked ? $binding['max2_model_version'] : null,
            'binding_proof_sha256' => $binding['proof_sha256'], 'producer_contract_sha256' => $binding['producer_contract_sha256'],
            'proof_observed_at_unix' => $binding['proof_observed_at_unix'], 'point_recheck_sha256' => $linked ? $recheck['source_sha256'] : null,
            'activation_sha256' => $this->activation['activation_sha256'], 'activated_at_unix' => $this->activation['activated_at_unix'],
            'created_at_unix' => $now];
    }
    private function insert(string $table, array $row): void {
        // Table and columns come only from this service's fixed literals.
        if (!in_array($table, ['max2_common_value_accounts_v2','max2_common_value_events_v2'], true)) throw new LogicException('OWN_V2_TABLE_REQUIRED');
        $this->query('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));
    }
    private function sameAccount(array $stored, array $incoming): bool {
        // Observation receipts may change on reinspection; the original private
        // evidence stays stored. Identity, accounting unit and reserve never change.
        $ignore = ['binding_proof_sha256','proof_observed_at_unix','point_recheck_sha256','created_at_unix','identity_sha256'];
        foreach ($incoming as $key => $value) {
            if (in_array($key,$ignore,true)) continue;
            if (is_int($value)) { if (self::unsigned($stored[$key] ?? null) !== $value) return false; }
            elseif (($stored[$key] ?? null) !== $value) return false;
        }
        return true;
    }
    private function events(string $accountId): array {
        $rows = $this->query('SELECT target,event_key,profile_id,profile_sha256,model_version,total_kopeks,delta_kopeks,delivery_state,event_at_unix FROM max2_common_value_events_v2 WHERE account_id=? ORDER BY id LIMIT 1001'.$this->forUpdate,[$accountId])->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)>1000) throw new UnexpectedValueException('V2_EVENT_BOUND');
        foreach ($rows as &$row) {
            $row['total_kopeks']=self::unsigned($row['total_kopeks']); $row['delta_kopeks']=self::unsigned($row['delta_kopeks']);
            $row['event_at_unix']=self::unsigned($row['event_at_unix']);
        }
        unset($row); return $rows;
    }
    public function reserve(array $binding, array $estimate, array $identity, array $recheck, int $now): array
    {
        $reason = $this->admission($binding,$estimate,$identity,$recheck,$now);
        if ($reason !== null) return self::hold($reason);
        // Pure validation before the first write, including the exact new goal.
        $empty = ['version'=>Max2CommonValueAccountingV2::VERSION,'counter_id'=>Max2ValueBindingPolicyV2::COUNTER,
            'goal_id'=>Max2ValueBindingPolicyV2::GOAL,'target'=>Max2ValueBindingPolicyV2::TARGET,
            'inquiry_key'=>$binding['inquiry_key']??null,'binding_key'=>$binding['binding_key']??null,
            'initial_common_kopeks'=>$binding['initial_common_kopeks']??null,'events'=>[]];
        $precheck = Max2CommonValueAccountingV2::plan($binding,$estimate,$empty);
        if (($precheck['status']??null)==='HOLD') return self::hold($precheck['reason']);
        $incoming = $this->newAccount($binding,$identity,$recheck,$now);
        $mutationAttempted = false; $commitAttempted = false;
        try {
            if ($this->pdo->beginTransaction() !== true) throw new RuntimeException('V2_TRANSACTION_NOT_STARTED');
            $stored = $this->query('SELECT * FROM max2_common_value_accounts_v2 WHERE counter_id=? AND target=? AND project_key=? AND platform=? AND conversation_id=?'.$this->forUpdate,
                [$incoming['counter_id'],$incoming['target'],$incoming['project_key'],$incoming['platform'],$incoming['conversation_id']])->fetch(PDO::FETCH_ASSOC);
            if ($stored === false) {
                $mutationAttempted = true; $this->insert('max2_common_value_accounts_v2',$incoming);
                $stored = $this->query('SELECT * FROM max2_common_value_accounts_v2 WHERE counter_id=? AND target=? AND inquiry_key=?'.$this->forUpdate,
                    [$incoming['counter_id'],$incoming['target'],$incoming['inquiry_key']])->fetch(PDO::FETCH_ASSOC);
            }
            if (!is_array($stored) || !$this->sameAccount($stored,$incoming)) {
                $this->rollback(); return self::hold('IMMUTABLE_INQUIRY_ACCOUNT_CONFLICT');
            }
            $accountId=(string)$stored['id'];
            if (!Max2ValueBindingPolicyV2::positiveId($accountId)) throw new UnexpectedValueException('V2_ACCOUNT_ID_INVALID');
            $events=$this->events($accountId); $snapshot=$empty; $snapshot['events']=$events;
            $plan=Max2CommonValueAccountingV2::plan($binding,$estimate,$snapshot);
            if (($plan['status']??null)==='HOLD') { $this->rollback(); return self::hold($plan['reason']); }
            $duplicate=false;
            foreach ($events as $event) if ($event['event_key']===$plan['event_key']) {
                if ($event['event_at_unix']!==$estimate['event_unix']) { $this->rollback(); return self::hold('IMMUTABLE_EVENT_CLOCK_CONFLICT'); }
                $duplicate=true;
            }
            if (!$duplicate) {
                $row=array_intersect_key($plan,array_flip(['counter_id','goal_id','target','event_key','profile_id','profile_sha256','model_version','total_kopeks','delta_kopeks']));
                $row+=['account_id'=>$accountId,'delivery_state'=>$plan['delta_kopeks']>0?'reserved':'not_needed',
                    'event_at_unix'=>$estimate['event_unix'],'created_at_unix'=>$now];
                $mutationAttempted=true; $this->insert('max2_common_value_events_v2',$row);
                // Re-read the unique stored event before committing its reservation.
                $saved=$this->query('SELECT event_key,profile_sha256,total_kopeks,delta_kopeks,delivery_state,event_at_unix FROM max2_common_value_events_v2 WHERE account_id=? AND profile_id=?'.$this->forUpdate,
                    [$accountId,$estimate['profile_id']])->fetch(PDO::FETCH_ASSOC);
                if (!is_array($saved)||$saved['event_key']!==$row['event_key']||$saved['profile_sha256']!==$row['profile_sha256']
                    ||self::unsigned($saved['total_kopeks'])!==$row['total_kopeks']||self::unsigned($saved['delta_kopeks'])!==$row['delta_kopeks']
                    ||$saved['delivery_state']!==$row['delivery_state']||self::unsigned($saved['event_at_unix'])!==$row['event_at_unix']) throw new UnexpectedValueException('V2_RESERVATION_READBACK_CONFLICT');
            }
            $commitAttempted=true;
            if ($this->pdo->commit() !== true) throw new RuntimeException('V2_COMMIT_UNCONFIRMED');
            return ['status'=>$duplicate?'ALREADY_RECORDED':($plan['delta_kopeks']>0?'RESERVED':'EVALUATED'),
                'account_id'=>$accountId,'event_key'=>$plan['event_key'],'delta_kopeks'=>$duplicate?0:$plan['delta_kopeks'],
                'already_reserved_kopeks'=>$plan['already_reserved_kopeks'],'profile_total_kopeks'=>$plan['total_kopeks'],
                'provider_calls'=>0,'reservations_added'=>(!$duplicate&&$plan['delta_kopeks']>0)?1:0,
                'delivery_permitted'=>false,'goal_id'=>Max2ValueBindingPolicyV2::GOAL,'target'=>Max2ValueBindingPolicyV2::TARGET];
        } catch (Throwable $error) {
            $rolledBack=false;
            try { if ($this->pdo->inTransaction()) $rolledBack=$this->pdo->rollBack(); } catch (Throwable $ignored) {}
            // A thrown commit can have succeeded. Never use a failed transaction
            // or transport outcome to free money or to perform an automatic retry.
            if ($commitAttempted || ($mutationAttempted&&!$rolledBack)) return [
                'status'=>'UNKNOWN','reason'=>'RESERVATION_OUTCOME_UNCONFIRMED','event_key'=>$precheck['event_key'],
                'provider_calls'=>0,'reservations_added'=>null,'delivery_permitted'=>false,'requires_readback'=>true,'automatic_retry'=>false];
            return self::hold($error instanceof PDOException?'V2_TRANSACTION_CONFLICT':'V2_STORAGE_READBACK_CONFLICT');
        }
    }
    /** Own-ledger claim check for the signed reader adapter; never a latest-row lookup. */
    public function receiptClaim(string $trackerId, string $inquiryKey): array
    {
        if (!Max2ValueBindingPolicyV2::positiveId($trackerId)||!Max2ValueBindingPolicyV2::digest($inquiryKey)) return self::hold('EXACT_RECEIPT_CLAIM_KEYS_REQUIRED');
        try {
            $rows=$this->query('SELECT inquiry_key,immutable_tuple_sha256 FROM max2_common_value_accounts_v2 WHERE counter_id=? AND target=? AND tracker_record_id=? LIMIT 2',
                [Max2ValueBindingPolicyV2::COUNTER,Max2ValueBindingPolicyV2::TARGET,$trackerId])->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows)>1) throw new UnexpectedValueException('V2_RECEIPT_UNIQUENESS_CONFLICT');
            if ($rows===[]) return ['status'=>'OBSERVED','binding_state'=>'unbound','provider_calls'=>0,'writes'=>0];
            if (!Max2ValueBindingPolicyV2::digest($rows[0]['inquiry_key'])||!Max2ValueBindingPolicyV2::digest($rows[0]['immutable_tuple_sha256'])) throw new UnexpectedValueException('V2_RECEIPT_IDENTITY_CONFLICT');
            return ['status'=>'OBSERVED','binding_state'=>$rows[0]['inquiry_key']===$inquiryKey?'same_inquiry':'other_inquiry',
                'bound_inquiry_key'=>$rows[0]['inquiry_key'],'immutable_tuple_sha256'=>$rows[0]['immutable_tuple_sha256'],
                'provider_calls'=>0,'writes'=>0];
        } catch (Throwable $ignored) { return ['status'=>'UNKNOWN','reason'=>'V2_RECEIPT_CLAIM_UNAVAILABLE','provider_calls'=>0,'writes'=>0]; }
    }
    public function pendingReservations(int $now, int $limit = 20): array
    {
        if (!$this->active($now)||$limit<1||$limit>20) return self::hold('V2_PENDING_SCOPE_REJECTED');
        try {
            $rows=$this->query('SELECT e.event_key,e.profile_id,e.delta_kopeks,e.event_at_unix,a.inquiry_key FROM max2_common_value_events_v2 e JOIN max2_common_value_accounts_v2 a ON a.id=e.account_id WHERE e.counter_id=? AND e.target=? AND e.delivery_state=? AND e.attempt_count=0 AND a.activation_sha256=? ORDER BY e.id LIMIT '.(int)$limit,
                [Max2ValueBindingPolicyV2::COUNTER,Max2ValueBindingPolicyV2::TARGET,'reserved',$this->activation['activation_sha256']])->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) { $row['delta_kopeks']=self::unsigned($row['delta_kopeks']); $row['event_unix']=self::unsigned($row['event_at_unix']); unset($row['event_at_unix']); }
            unset($row); return ['status'=>'OBSERVED','events'=>$rows,'provider_calls'=>0,'writes'=>0,'delivery_permitted'=>false];
        } catch (Throwable $ignored) { return ['status'=>'UNKNOWN','events'=>null,'reason'=>'V2_PENDING_UNAVAILABLE','provider_calls'=>0,'writes'=>0]; }
    }
    public function pendingProviderReceipts(int $now, int $limit = 20): array
    {
        if (!$this->active($now)||$limit<1||$limit>20) return self::hold('V2_PENDING_SCOPE_REJECTED');
        try {
            $rows=$this->query('SELECT e.event_key,e.attempt_key,e.upload_id,e.csv_sha256 FROM max2_common_value_events_v2 e JOIN max2_common_value_accounts_v2 a ON a.id=e.account_id WHERE e.counter_id=? AND e.target=? AND e.delivery_state=? AND e.attempt_count=1 AND e.upload_id IS NOT NULL AND a.activation_sha256=? ORDER BY e.id LIMIT '.(int)$limit,
                [Max2ValueBindingPolicyV2::COUNTER,Max2ValueBindingPolicyV2::TARGET,'accepted',$this->activation['activation_sha256']])->fetchAll(PDO::FETCH_ASSOC);
            return ['status'=>'OBSERVED','receipts'=>$rows,'provider_calls'=>0,'writes'=>0,'delivery_permitted'=>false];
        } catch (Throwable $ignored) { return ['status'=>'UNKNOWN','receipts'=>null,'reason'=>'V2_PENDING_RECEIPTS_UNAVAILABLE','provider_calls'=>0,'writes'=>0]; }
    }
    private function lockEvent(string $eventKey): ?array
    {
        $accountId=$this->query('SELECT account_id FROM max2_common_value_events_v2 WHERE event_key=? AND counter_id=? AND target=?',
            [$eventKey,Max2ValueBindingPolicyV2::COUNTER,Max2ValueBindingPolicyV2::TARGET])->fetchColumn();
        if ($accountId===false) return null;
        // Same lock order as reserve: account, then its event.
        $account=$this->query('SELECT * FROM max2_common_value_accounts_v2 WHERE id=?'.$this->forUpdate,[$accountId])->fetch(PDO::FETCH_ASSOC);
        $event=$this->query('SELECT * FROM max2_common_value_events_v2 WHERE account_id=? AND event_key=?'.$this->forUpdate,[$accountId,$eventKey])->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)||!is_array($event)
            ||(string)$account['counter_id']!=='98615635'||(string)$event['counter_id']!=='98615635'
            ||(string)$account['goal_id']!=='687941321'||(string)$event['goal_id']!=='687941321'
            ||$account['target']!==Max2ValueBindingPolicyV2::TARGET||$event['target']!==Max2ValueBindingPolicyV2::TARGET
            ||$account['version']!==Max2CommonValueAccountingV2::VERSION||$account['project_key']!=='anytour'
            ||$account['activation_sha256']!==$this->activation['activation_sha256']) throw new UnexpectedValueException('V2_EVENT_SCOPE_CONFLICT');
        return [$account,$event];
    }
    private static function eventProjection(array $account, array $event): array
    {
        return ['project_key'=>'anytour','counter_id'=>'98615635','goal_id'=>'687941321',
            'target'=>Max2ValueBindingPolicyV2::TARGET,'value_contract'=>Max2CommonValueAccountingV2::VERSION,
            'delivery_state'=>$event['delivery_state'],'attempt_count'=>self::unsigned($event['attempt_count']),
            'event_key'=>$event['event_key'],'attempt_key'=>$event['attempt_key'],'client_id'=>$account['client_id'],
            'event_unix'=>self::unsigned($event['event_at_unix']),'delta_kopeks'=>self::unsigned($event['delta_kopeks']),
            'csv_sha256'=>$event['csv_sha256']];
    }
    private static function csvForIntent(array $event, int $now): string
    {
        if (!self::cid($event['client_id']??null)||!is_int($event['event_unix']??null)
            ||$event['event_unix']<$now-21*86400||$event['event_unix']>$now
            ||!is_int($event['delta_kopeks']??null)||$event['delta_kopeks']<1||$event['delta_kopeks']>Max2ValueBindingPolicyV2::CAP_KOPEKS) throw new UnexpectedValueException('V2_WIRE_SOURCE_CONFLICT');
        // All fields are fixed ASCII or canonical digits, so the standard CSV
        // encoder emits these exact unquoted fields and a single LF per row.
        $price=(string)intdiv($event['delta_kopeks'],100).'.'.str_pad((string)($event['delta_kopeks']%100),2,'0',STR_PAD_LEFT);
        return "ClientId,Target,DateTime,Price,Currency\n".$event['client_id'].','.Max2ValueBindingPolicyV2::TARGET.','.$event['event_unix'].','.$price.",RUB\n";
    }
    private static function unknownAttempt(string $eventKey): array
    {
        return ['status'=>'UNKNOWN','reason'=>'V2_INTENT_OUTCOME_UNCONFIRMED','event_key'=>$eventKey,
            'provider_calls'=>0,'delivery_permitted'=>false,'requires_readback'=>true,'automatic_retry'=>false];
    }
    /** Commit an irreversible one-attempt intent BEFORE returning any sendable row. */
    public function beginAttempt(string $eventKey, int $now): array
    {
        if (!$this->active($now)||($this->activation['mode']??null)!=='send'
            ||!Max2ValueBindingPolicyV2::digest($eventKey)||$this->pdo->inTransaction()) return self::hold('V2_SEND_OFF_OR_INVALID');
        $mutated=false; $commitAttempted=false;
        try {
            if ($this->pdo->beginTransaction()!==true) throw new RuntimeException('V2_TRANSACTION_NOT_STARTED');
            $pair=$this->lockEvent($eventKey);
            if ($pair===null) { $this->rollback(); return self::hold('V2_EVENT_NOT_OBSERVED'); }
            [$account,$event]=$pair;
            if ($event['delivery_state']!=='reserved'||self::unsigned($event['attempt_count'])!==0
                ||$event['attempt_key']!==null||$event['csv_sha256']!==null) {
                $this->rollback(); return ['status'=>'ALREADY_ATTEMPTED','event_key'=>$eventKey,'provider_calls'=>0,'delivery_permitted'=>false];
            }
            $wire=self::eventProjection($account,$event);
            $csvSha=hash('sha256',self::csvForIntent($wire,$now));
            $attemptKey=hash('sha256','max2/common/attempt/v2|'.$eventKey.'|'.$csvSha);
            $mutated=true;
            $changed=$this->query('UPDATE max2_common_value_events_v2 SET delivery_state=?,attempt_count=1,attempt_key=?,csv_sha256=?,attempt_started_at_unix=? WHERE id=? AND delivery_state=? AND attempt_count=0',
                ['attempted',$attemptKey,$csvSha,$now,$event['id'],'reserved'])->rowCount();
            if ($changed!==1) throw new UnexpectedValueException('V2_ATTEMPT_CAS_CONFLICT');
            $saved=$this->query('SELECT * FROM max2_common_value_events_v2 WHERE id=?'.$this->forUpdate,[$event['id']])->fetch(PDO::FETCH_ASSOC);
            if (!is_array($saved)||$saved['attempt_key']!==$attemptKey||$saved['csv_sha256']!==$csvSha
                ||$saved['delivery_state']!=='attempted'||self::unsigned($saved['attempt_count'])!==1) throw new UnexpectedValueException('V2_INTENT_READBACK_CONFLICT');
            $wire=self::eventProjection($account,$saved);
            $commitAttempted=true; if ($this->pdo->commit()!==true) throw new RuntimeException('V2_COMMIT_UNCONFIRMED');
            return ['status'=>'ATTEMPT_CLAIMED','event'=>$wire,'provider_calls'=>0,'delivery_permitted'=>true];
        } catch (Throwable $ignored) {
            $rolledBack=false; try { if ($this->pdo->inTransaction()) $rolledBack=$this->pdo->rollBack(); } catch (Throwable $ignored2) {}
            return ($commitAttempted||($mutated&&!$rolledBack))?self::unknownAttempt($eventKey):self::hold('V2_INTENT_CONFLICT');
        }
    }
    /** Store a normalized result from the NEW client; this method never sends. */
    public function recordAttemptResult(string $eventKey, string $attemptKey, array $result, int $now): array
    {
        if (!$this->active($now)||!Max2ValueBindingPolicyV2::digest($eventKey)||!Max2ValueBindingPolicyV2::digest($attemptKey)
            ||!in_array($result['state']??null,['accepted','unknown','failed'],true)
            ||!Max2ValueBindingPolicyV2::digest($result['csv_sha256']??null)
            ||!Max2ValueBindingPolicyV2::digest($result['response_sha256']??null)
            ||!is_int($result['http_status']??null)||$result['http_status']<0||$result['http_status']>599
            ||!is_string($result['code']??null)||preg_match('/\A[A-Za-z0-9_:-]{1,64}\z/D',$result['code'])!==1
            ||$this->pdo->inTransaction()) return self::hold('V2_RESULT_NOT_ADMITTED');
        $upload=$result['upload_id']??null;
        if (($result['state']==='accepted'&&(!is_string($upload)||preg_match('/\A[1-9][0-9]{0,39}\z/D',$upload)!==1
                ||$result['http_status']<200||$result['http_status']>=300))
            ||($result['state']!=='accepted'&&$upload!==null)) return self::hold('V2_ACCEPTANCE_RECEIPT_CONFLICT');
        $mutated=false; $commitAttempted=false;
        try {
            if ($this->pdo->beginTransaction()!==true) throw new RuntimeException('V2_TRANSACTION_NOT_STARTED');
            $pair=$this->lockEvent($eventKey); if ($pair===null) throw new UnexpectedValueException('V2_EVENT_MISSING');
            [, $event]=$pair;
            if ($event['attempt_key']!==$attemptKey||$event['csv_sha256']!==$result['csv_sha256']
                ||self::unsigned($event['attempt_count'])!==1||self::unsigned($event['attempt_started_at_unix'])>$now) throw new UnexpectedValueException('V2_ATTEMPT_IDENTITY_CONFLICT');
            if ($event['upload_id']!==null || in_array($event['delivery_state'],['accepted','processed'],true)) {
                if ($result['state']!=='accepted'||$event['upload_id']!==$upload) throw new UnexpectedValueException('V2_ACCEPTANCE_IMMUTABLE');
                $this->rollback(); return ['status'=>'ALREADY_RECORDED','event_key'=>$eventKey,'provider_calls'=>0,'delivery_permitted'=>false];
            }
            if (!in_array($event['delivery_state'],['attempted','unknown','failed'],true)
                ||($event['delivery_state']==='failed'&&$result['state']!=='failed')) throw new UnexpectedValueException('V2_RESULT_STATE_CONFLICT');
            $mutated=true;
            $this->query('UPDATE max2_common_value_events_v2 SET delivery_state=?,upload_id=?,accepted_at_unix=?,transport_receipt_sha256=?,last_result_code=? WHERE id=?',
                [$result['state'],$upload,$result['state']==='accepted'?$now:null,$result['response_sha256'],$result['code'],$event['id']]);
            $saved=$this->query('SELECT delivery_state,upload_id,transport_receipt_sha256 FROM max2_common_value_events_v2 WHERE id=?'.$this->forUpdate,[$event['id']])->fetch(PDO::FETCH_ASSOC);
            if (!is_array($saved)||$saved['delivery_state']!==$result['state']||$saved['upload_id']!==$upload
                ||$saved['transport_receipt_sha256']!==$result['response_sha256']) throw new UnexpectedValueException('V2_RESULT_READBACK_CONFLICT');
            $commitAttempted=true; if ($this->pdo->commit()!==true) throw new RuntimeException('V2_COMMIT_UNCONFIRMED');
            return ['status'=>'RECORDED','delivery_state'=>$result['state'],'event_key'=>$eventKey,'provider_calls'=>0,'delivery_permitted'=>false];
        } catch (Throwable $ignored) {
            $rolledBack=false; try { if ($this->pdo->inTransaction()) $rolledBack=$this->pdo->rollBack(); } catch (Throwable $ignored2) {}
            return ($commitAttempted||($mutated&&!$rolledBack))?self::unknownAttempt($eventKey):self::hold('V2_RESULT_CONFLICT');
        }
    }
    /** PROCESSED is a separate exact provider receipt, never a JS callback. */
    public function recordProviderStatus(string $eventKey, string $attemptKey, array $receipt, int $now): array
    {
        if (!$this->active($now)||!Max2ValueBindingPolicyV2::digest($eventKey)||!Max2ValueBindingPolicyV2::digest($attemptKey)
            ||!is_string($receipt['upload_id']??null)||preg_match('/\A[1-9][0-9]{0,39}\z/D',$receipt['upload_id'])!==1
            ||!Max2ValueBindingPolicyV2::digest($receipt['response_sha256']??null)
            ||!in_array($receipt['status']??null,['PREPARED','UPLOADED','EXPORTED','MATCHED','PROCESSED','LINKAGE_FAILURE'],true)
            ||$this->pdo->inTransaction()) return self::hold('V2_PROVIDER_STATUS_UNPROVED');
        foreach (['source_quantity','line_quantity','processed_quantity'] as $key) if (array_key_exists($key,$receipt)
            &&(!is_int($receipt[$key])||$receipt[$key]<0||$receipt[$key]>1)) return self::hold('V2_PROVIDER_QUANTITY_CONFLICT');
        if ($receipt['status']==='PROCESSED'&&(($receipt['source_quantity']??null)!==1||($receipt['line_quantity']??null)!==1
            ||(array_key_exists('processed_quantity',$receipt)&&$receipt['processed_quantity']!==1))) return self::hold('V2_PROCESSED_NOT_EXACT_ONE');
        $mutated=false; $commitAttempted=false;
        try {
            if ($this->pdo->beginTransaction()!==true) throw new RuntimeException('V2_TRANSACTION_NOT_STARTED');
            $pair=$this->lockEvent($eventKey); if ($pair===null) throw new UnexpectedValueException('V2_EVENT_MISSING');
            [, $event]=$pair;
            if ($event['attempt_key']!==$attemptKey||$event['upload_id']!==$receipt['upload_id']
                ||!in_array($event['delivery_state'],['accepted','processed'],true)
                ||self::unsigned($event['accepted_at_unix'])>$now) throw new UnexpectedValueException('V2_PROVIDER_RECEIPT_IDENTITY_CONFLICT');
            if ($event['delivery_state']==='processed') {
                if ($receipt['status']!=='PROCESSED') throw new UnexpectedValueException('V2_PROCESSED_IMMUTABLE');
                $this->rollback(); return ['status'=>'ALREADY_RECORDED','event_key'=>$eventKey,'provider_calls'=>0,'delivery_permitted'=>false];
            }
            $state=$receipt['status']==='PROCESSED'?'processed':($receipt['status']==='LINKAGE_FAILURE'?'failed':'accepted');
            $mutated=true;
            $this->query('UPDATE max2_common_value_events_v2 SET delivery_state=?,provider_status=?,source_quantity=?,line_quantity=?,processed_at_unix=?,transport_receipt_sha256=? WHERE id=?',
                [$state,$receipt['status'],$receipt['source_quantity']??null,$receipt['line_quantity']??null,$state==='processed'?$now:null,$receipt['response_sha256'],$event['id']]);
            $saved=$this->query('SELECT delivery_state,provider_status,transport_receipt_sha256 FROM max2_common_value_events_v2 WHERE id=?'.$this->forUpdate,[$event['id']])->fetch(PDO::FETCH_ASSOC);
            if (!is_array($saved)||$saved['delivery_state']!==$state||$saved['provider_status']!==$receipt['status']
                ||$saved['transport_receipt_sha256']!==$receipt['response_sha256']) throw new UnexpectedValueException('V2_PROVIDER_READBACK_CONFLICT');
            $commitAttempted=true; if ($this->pdo->commit()!==true) throw new RuntimeException('V2_COMMIT_UNCONFIRMED');
            return ['status'=>'RECORDED','delivery_state'=>$state,'event_key'=>$eventKey,'provider_calls'=>0,
                'delivery_permitted'=>false,'visit_linkage_verified'=>false];
        } catch (Throwable $ignored) {
            $rolledBack=false; try { if ($this->pdo->inTransaction()) $rolledBack=$this->pdo->rollBack(); } catch (Throwable $ignored2) {}
            return ($commitAttempted||($mutated&&!$rolledBack))?self::unknownAttempt($eventKey):self::hold('V2_PROVIDER_STATUS_CONFLICT');
        }
    }
    /** Explicit readback for an uncertain reservation. Does not retry or mutate. */
    public function readback(string $inquiryKey, string $eventKey): array
    {
        if (!Max2ValueBindingPolicyV2::digest($inquiryKey)||!Max2ValueBindingPolicyV2::digest($eventKey)) return self::hold('EXACT_READBACK_KEYS_REQUIRED');
        try {
            $row=$this->query('SELECT a.binding_key,a.inquiry_key,a.initial_common_kopeks,e.event_key,e.profile_id,e.profile_sha256,e.model_version,e.total_kopeks,e.delta_kopeks,e.delivery_state,e.attempt_count,e.attempt_key,e.csv_sha256,e.upload_id,e.provider_status,e.accepted_at_unix,e.processed_at_unix FROM max2_common_value_accounts_v2 a JOIN max2_common_value_events_v2 e ON e.account_id=a.id WHERE a.counter_id=? AND a.target=? AND a.inquiry_key=? AND e.event_key=?',
                [Max2ValueBindingPolicyV2::COUNTER,Max2ValueBindingPolicyV2::TARGET,$inquiryKey,$eventKey])->fetch(PDO::FETCH_ASSOC);
            if ($row===false) return ['status'=>'NOT_OBSERVED','event_key'=>$eventKey,'absence_is_not_retry_permission'=>true,'provider_calls'=>0,'writes'=>0];
            foreach (['initial_common_kopeks','total_kopeks','delta_kopeks','attempt_count'] as $key) $row[$key]=self::unsigned($row[$key]);
            foreach (['accepted_at_unix','processed_at_unix'] as $key) if ($row[$key]!==null) $row[$key]=self::unsigned($row[$key]);
            return ['status'=>'OBSERVED','record'=>$row,'provider_calls'=>0,'writes'=>0,'delivery_permitted'=>false];
        } catch (Throwable $ignored) {
            return ['status'=>'UNKNOWN','reason'=>'V2_READBACK_UNAVAILABLE','provider_calls'=>0,'writes'=>0,'automatic_retry'=>false];
        }
    }
}
