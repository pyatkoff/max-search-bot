<?php
declare(strict_types=1);

/** Reserves the UMK destination of existing admitted source events. No transport or model calls. */
final class AnyTourUmkLateLedger
{
    public const COUNTER = '98615635';
    public const GOAL = '696194739';
    public const TARGET = 'anytour_umk_funnel_value';
    private const SOURCE_GOAL = '687941321';
    private const SOURCE_TARGET = 'anytour_modeled_value_v2_max2';
    private const CAP = 10000000;
    private string $lock;

    public function __construct(private PDO $db)
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['sqlite', 'mysql'], true) || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('Transactional UMK storage required');
        }
        $this->lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function run(string $sql, array $args = []): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        return $statement;
    }

    private static function integer($raw): int
    {
        if (!is_string($raw) && !is_int($raw)) throw new UnexpectedValueException('Invalid unsigned number');
        $text = (string)$raw;
        if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $text) || (string)(int)$text !== $text) {
            throw new UnexpectedValueException('Invalid unsigned number');
        }
        return (int)$text;
    }

    /** The operation owner must finish both producers' installation before selecting this floor. */
    public function activate(): array
    {
        if ($this->db->inTransaction()) throw new LogicException('UMK needs its own transaction');
        $this->db->beginTransaction();
        try {
            $setting = $this->run('SELECT * FROM anytour_umk_late_settings WHERE id=1'.$this->lock)->fetch(PDO::FETCH_ASSOC);
            if ($setting === false) throw new RuntimeException('Missing UMK setting');
            if ($setting['activated_at_unix'] === null) {
                $clockSql = $this->lock === '' ? "CAST(strftime('%s','now') AS INTEGER)" : 'UNIX_TIMESTAMP()';
                $floor = $this->run("SELECT $clockSql AS epoch,
                    (SELECT COALESCE(MAX(id),0) FROM max2_common_value_events_v2) AS source_id,
                    (SELECT COALESCE(MAX(id),0) FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND platform IN ('max','telegram')) AS profile_id")->fetch(PDO::FETCH_ASSOC);
                if (!$floor || self::integer($floor['epoch']) < 1) throw new RuntimeException('Missing UMK floor');
                $this->run('UPDATE anytour_umk_late_settings SET activated_at_unix=?,initial_source_id=?,initial_profile_id=? WHERE id=1 AND activated_at_unix IS NULL',
                    [self::integer($floor['epoch']),self::integer($floor['source_id']),self::integer($floor['profile_id'])]);
            }
            $result = $this->run('SELECT activated_at_unix,initial_source_id,initial_profile_id FROM anytour_umk_late_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
            if (!$result || $result['activated_at_unix'] === null) throw new RuntimeException('Unconfirmed UMK activation');
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    /**
     * $proof is a private projection from the authenticated UMK tracker reader, never an HTTP input.
     * For positive results it binds the exact same record and the saved UF_DATE / UF_M2_UMK_KOP.
     * For a negative result its verified bounds must cover the entire relevant tracker history.
     */
    private static function initial(array $source, ?array $proof, int $epoch): ?array
    {
        if ($source['platform'] === 'telegram' && $source['binding_kind'] === 'PROVEN_NO_NEW_EARLY_RESERVE' && $source['tracker_record_id'] === null) {
            return [0, hash('sha256', 'umk:telegram:max-only:'.$source['inquiry_key'])];
        }
        if ($source['platform'] !== 'max' || !is_array($proof)
            || ($proof['counter_id'] ?? null) !== self::COUNTER || ($proof['goal_id'] ?? null) !== self::GOAL
            || ($proof['target'] ?? null) !== self::TARGET || ($proof['inquiry_key'] ?? null) !== $source['inquiry_key']
            || ($proof['verified'] ?? null) !== true || !is_string($proof['source_sha256'] ?? null)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $proof['source_sha256'])) return null;
        $kind = $source['binding_kind'];
        if ($kind === 'PROVEN_NO_NEW_EARLY_RESERVE' && $source['tracker_record_id'] === null
            && ($proof['state'] ?? null) === 'no_reserve' && ($proof['complete'] ?? null) === true
            && array_key_exists('record_id', $proof) && $proof['record_id'] === null && ($proof['amount_kopeks'] ?? null) === 0) {
            return [0, $proof['source_sha256']];
        }
        if ($kind !== 'UNIQUE_OBSERVED_MAX2' || $source['tracker_record_id'] === null
            || ($proof['record_id'] ?? null) !== (string)$source['tracker_record_id']
            || !is_int($proof['issued_at_unix'] ?? null) || $proof['issued_at_unix'] <= $epoch
            || $proof['issued_at_unix'] > self::integer($source['inquiry_recorded_at_unix'])) return null;
        $amount = $proof['amount_kopeks'] ?? null;
        if (($proof['state'] ?? null) === 'issued_unconfirmed' && is_int($amount) && $amount > 0 && $amount <= self::CAP) {
            return [$amount, $proof['source_sha256']];
        }
        if (($proof['state'] ?? null) === 'no_reserve' && ($proof['complete'] ?? null) === true && $amount === 0) {
            return [0, $proof['source_sha256']];
        }
        return null;
    }

    public function reserve(int $sourceEventId, ?array $verifiedEarly = null): array
    {
        if ($sourceEventId < 1 || $this->db->inTransaction()) throw new InvalidArgumentException('Invalid UMK reservation');
        $this->db->beginTransaction();
        try {
            // One destination row serializes reservations, without locking V2's accounts or cursors.
            $setting = $this->run('SELECT * FROM anytour_umk_late_settings WHERE id=1'.$this->lock)->fetch(PDO::FETCH_ASSOC);
            if (!$setting) throw new RuntimeException('Missing UMK setting');
            if ($setting['activated_at_unix'] === null) return $this->withoutWrite('OFF');
            $epoch = self::integer($setting['activated_at_unix']);
            $sourceFloor = self::integer($setting['initial_source_id']);
            $profileFloor = self::integer($setting['initial_profile_id']);
            if ($sourceEventId <= $sourceFloor) return $this->withoutWrite('BEFORE_ACTIVATION');
            $source = $this->run('SELECT e.account_id,e.profile_id,e.event_key AS source_key,e.event_at_unix,e.total_kopeks,
                a.platform,a.inquiry_key,a.inquiry_recorded_at_unix,a.binding_kind,a.tracker_record_id
                FROM max2_common_value_events_v2 e JOIN max2_common_value_accounts_v2 a ON a.id=e.account_id
                WHERE e.id=? AND e.counter_id=? AND e.goal_id=? AND e.target=? AND a.counter_id=e.counter_id AND a.goal_id=e.goal_id
                AND a.target=e.target AND a.project_key=?', [$sourceEventId,self::COUNTER,self::SOURCE_GOAL,self::SOURCE_TARGET,'anytour'])->fetch(PDO::FETCH_ASSOC);
            if (!$source) return $this->withoutWrite('SOURCE_UNAVAILABLE');
            if (self::integer($source['profile_id']) <= $profileFloor || self::integer($source['inquiry_recorded_at_unix']) <= $epoch
                || self::integer($source['event_at_unix']) <= $epoch) return $this->withoutWrite('BEFORE_ACTIVATION');
            if (self::integer($source['event_at_unix']) < self::integer($source['inquiry_recorded_at_unix'])
                || !preg_match('/\A[a-f0-9]{64}\z/D', $source['source_key']) || !preg_match('/\A[a-f0-9]{64}\z/D', $source['inquiry_key'])) {
                return $this->withoutWrite('SOURCE_CONFLICT');
            }
            $total = self::integer($source['total_kopeks']);
            if ($total > self::CAP) return $this->withoutWrite('SOURCE_CONFLICT');
            $eventKey = hash('sha256', self::TARGET.'|'.$source['source_key']);
            $existing = $this->run('SELECT event_key,source_account_id,profile_id,total_kopeks,event_at_unix,delta_kopeks
                FROM anytour_umk_late_events WHERE source_event_id=?', [$sourceEventId])->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['event_key'] !== $eventKey || (string)$existing['source_account_id'] !== (string)$source['account_id']
                    || (string)$existing['profile_id'] !== (string)$source['profile_id'] || self::integer($existing['total_kopeks']) !== $total
                    || self::integer($existing['event_at_unix']) !== self::integer($source['event_at_unix'])) return $this->withoutWrite('SOURCE_CONFLICT');
                return $this->withoutWrite('ALREADY_RECORDED');
            }
            // A later observation must not consume a previous profile's increase on the same inquiry.
            $prior = $this->run('SELECT e.id FROM max2_common_value_events_v2 e LEFT JOIN anytour_umk_late_events u ON u.source_event_id=e.id
                WHERE e.account_id=? AND e.id>? AND e.id<? AND e.event_at_unix>? AND CAST(e.profile_id AS UNSIGNED)>?
                AND u.source_event_id IS NULL LIMIT 1', [$source['account_id'],$sourceFloor,$sourceEventId,$epoch,$profileFloor])->fetchColumn();
            if ($prior !== false) return $this->withoutWrite('EARLIER_PROFILE_PENDING');
            $account = $this->run('SELECT early_kopeks FROM anytour_umk_late_accounts WHERE source_account_id=?', [$source['account_id']])->fetch(PDO::FETCH_ASSOC);
            if (!$account) {
                $early = self::initial($source,$verifiedEarly,$epoch);
                if ($early === null) return $this->withoutWrite('EARLY_RESERVE_UNPROVED');
                [$baseline,$proofSha] = $early;
                $this->run('INSERT INTO anytour_umk_late_accounts (source_account_id,early_kopeks,early_proof_sha256) VALUES (?,?,?)',
                    [$source['account_id'],$baseline,$proofSha]);
            } else {
                $baseline = self::integer($account['early_kopeks']);
            }
            $reserved = self::integer($this->run('SELECT COALESCE(SUM(delta_kopeks),0) FROM anytour_umk_late_events WHERE source_account_id=?',
                [$source['account_id']])->fetchColumn());
            if ($baseline + $reserved > self::CAP) throw new UnexpectedValueException('Invalid cumulative UMK reserve');
            $delta = max(0, $total - $baseline - $reserved);
            $this->run('INSERT INTO anytour_umk_late_events (source_event_id,source_account_id,event_key,profile_id,total_kopeks,delta_kopeks,event_at_unix,delivery_state) VALUES (?,?,?,?,?,?,?,?)',
                [$sourceEventId,$source['account_id'],$eventKey,$source['profile_id'],$total,$delta,$source['event_at_unix'],$delta > 0 ? 'reserved' : 'not_needed']);
            $this->db->commit();
            return ['status'=>$delta > 0 ? 'RESERVED' : 'NOT_NEEDED','event_key'=>$eventKey,'delta_kopeks'=>$delta];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function withoutWrite(string $status): array
    {
        $this->db->rollBack();
        return ['status'=>$status];
    }
}
