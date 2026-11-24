<?php
declare(strict_types=1);
require_once __DIR__.'/AnyTourUmkLateLedger.php';
require_once __DIR__.'/Max2CommonValueMetrikaClientV2.php';

/** Bounded destination work on the existing Metrika cron. A committed attempt can never be claimed twice. */
final class AnyTourUmkLateDelivery
{
    private AnyTourUmkLateLedger $ledger;
    private Closure $proof;
    private Closure $client;
    private Closure $clock;
    private string $lock;

    public function __construct(private PDO $db, callable $proof, callable $client, ?callable $clock = null)
    {
        $this->ledger = new AnyTourUmkLateLedger($db);
        $this->proof = Closure::fromCallable($proof);
        $this->client = Closure::fromCallable($client);
        $this->clock = $clock === null ? static fn(): int => time() : Closure::fromCallable($clock);
        $this->lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function run(string $sql, array $arguments = []): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($arguments);
        return $statement;
    }

    public function settings(): array
    {
        $settings = $this->run('SELECT * FROM anytour_umk_late_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if (!$settings) throw new RuntimeException('UMK settings unavailable');
        return $settings;
    }

    /** A previously observed activation is required. No change to another goal or epoch. */
    public function setDelivery(int $epoch, bool $enabled): array
    {
        if ($epoch < 1) throw new InvalidArgumentException('UMK activation required');
        $this->run('UPDATE anytour_umk_late_settings SET delivery_enabled=? WHERE id=1 AND activated_at_unix=?', [(int)$enabled,$epoch]);
        $state = $this->settings();
        if ((int)$state['activated_at_unix'] !== $epoch || (int)$state['delivery_enabled'] !== (int)$enabled) {
            throw new RuntimeException('UMK configuration changed');
        }
        return $state;
    }

    private function collect(array $setting): string
    {
        $floor = (int)$setting['initial_source_id'];
        $after = (int)$setting['scan_after_source_id'];
        $row = $this->run("SELECT e.id FROM max2_common_value_events_v2 e
            JOIN max2_common_value_accounts_v2 a ON a.id=e.account_id
            LEFT JOIN anytour_umk_late_events u ON u.source_event_id=e.id
            WHERE e.id>? AND e.counter_id=98615635 AND e.goal_id=687941321 AND e.target='anytour_modeled_value_v2_max2'
                AND a.project_key='anytour' AND e.event_at_unix>? AND a.inquiry_recorded_at_unix>?
                AND CAST(e.profile_id AS UNSIGNED)>? AND u.source_event_id IS NULL
            ORDER BY e.id LIMIT 1", [max($floor,$after),$setting['activated_at_unix'],$setting['activated_at_unix'],$setting['initial_profile_id']])->fetchColumn();
        // Advance after one row so an unresolved proof does not starve another inquiry.
        // Wrap to the prospective floor so that an unresolved row remains discoverable.
        $next = $row === false ? $floor : (int)$row;
        $status = 'IDLE';
        if ($row !== false) {
            $result = $this->ledger->reserve($next);
            if ($result['status'] === 'EARLY_RESERVE_UNPROVED') {
                try {
                    $proof = ($this->proof)($this->db, $next);
                } catch (Throwable $ignored) {
                    $proof = null;
                }
                if (is_array($proof)) $result = $this->ledger->reserve($next, $proof);
            }
            $status = $result['status'];
        }
        if ($after !== $next) {
            $this->run('UPDATE anytour_umk_late_settings SET scan_after_source_id=? WHERE id=1 AND activated_at_unix=? AND scan_after_source_id=?',
                [$next,$setting['activated_at_unix'],$after]);
        }
        return $status;
    }

    private function claim(int $id, int $epoch, int $now): ?array
    {
        $this->db->beginTransaction();
        try {
            $gate = $this->run('SELECT activated_at_unix,delivery_enabled FROM anytour_umk_late_settings WHERE id=1'.$this->lock)->fetch(PDO::FETCH_ASSOC);
            if (!$gate || (int)$gate['delivery_enabled'] !== 1 || (int)$gate['activated_at_unix'] !== $epoch) {
                $this->db->rollBack();
                return null;
            }
            $row = $this->run("SELECT u.*,a.client_id FROM anytour_umk_late_events u JOIN max2_common_value_accounts_v2 a ON a.id=u.source_account_id
                WHERE u.source_event_id=? AND u.delivery_state='reserved' AND u.attempt_key IS NULL AND a.counter_id=98615635
                    AND a.project_key='anytour' AND a.goal_id=687941321 AND a.target='anytour_modeled_value_v2_max2'", [$id])->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->db->rollBack();
                return null;
            }
            $event = ['project_key'=>'anytour', 'counter_id'=>AnyTourUmkLateLedger::COUNTER, 'goal_id'=>AnyTourUmkLateLedger::GOAL,
                'target'=>AnyTourUmkLateLedger::TARGET, 'value_contract'=>Max2CommonValueMetrikaClientV2::UMK_CONTRACT,
                'event_key'=>$row['event_key'], 'attempt_key'=>hash('sha256','umk:attempt:'.$row['event_key']),
                'client_id'=>$row['client_id'], 'delta_kopeks'=>(int)$row['delta_kopeks'], 'event_unix'=>(int)$row['event_at_unix'],
                'delivery_state'=>'attempted','attempt_count'=>1];
            $event['csv_sha256'] = hash('sha256', Max2CommonValueMetrikaClientV2::umkCsv($event, $now));
            $claimed = $this->run("UPDATE anytour_umk_late_events SET delivery_state='attempted',attempt_key=?,csv_sha256=?,attempted_at_unix=?
                WHERE source_event_id=? AND delivery_state='reserved' AND attempt_key IS NULL",
                [$event['attempt_key'],$event['csv_sha256'],$now,$id])->rowCount();
            if ($claimed !== 1) {
                $this->db->rollBack();
                return null;
            }
            if (!$this->db->commit()) throw new RuntimeException('Unconfirmed UMK attempt');
            return $event;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function send(Max2CommonValueMetrikaClientV2 $client, int $id, int $epoch): string
    {
        if (!$client->goalReady()) return 'CATALOGUE_HOLD';
        $event = $this->claim($id,$epoch,($this->clock)());
        if ($event === null) return 'NOT_CLAIMED';
        try {
            $response = $client->upload($event,($this->clock)());
        } catch (Throwable $ignored) {
            $response = ['outcome'=>'unknown'];
        }
        $status = match ($response['outcome'] ?? null) {
            'accepted' => 'accepted', 'rejected' => 'failed', default => 'unknown',
        };
        $updated = $this->run("UPDATE anytour_umk_late_events SET delivery_state=?,upload_id=?,provider_status=?
            WHERE source_event_id=? AND event_key=? AND attempt_key=? AND csv_sha256=? AND delivery_state='attempted'",
            [$status,$response['upload_id'] ?? null,$response['status'] ?? null,$id,$event['event_key'],$event['attempt_key'],$event['csv_sha256']])->rowCount();
        return $updated === 1 ? strtoupper($status) : 'OUTCOME_UNCONFIRMED';
    }

    private function poll(Max2CommonValueMetrikaClientV2 $client, array $row): string
    {
        $receipt = $client->poll((string)$row['upload_id'],$row['event_key']);
        $state = 'accepted';
        $status = $receipt['status'] ?? null;
        if ($status === 'PROCESSED') {
            if (($receipt['source_quantity'] ?? null) === 1 && ($receipt['line_quantity'] ?? null) === 1
                && (!isset($receipt['processed_quantity']) || $receipt['processed_quantity'] === 1)) $state = 'processed';
            else $status = null;
        } elseif ($status === 'LINKAGE_FAILURE') {
            $state = 'failed';
        }
        $now = ($this->clock)();
        $updated = $this->run("UPDATE anytour_umk_late_events SET delivery_state=?,provider_status=COALESCE(?,provider_status),
            provider_checked_at_unix=?,processed_at_unix=? WHERE source_event_id=? AND delivery_state='accepted' AND upload_id=? AND attempt_key=?",
            [$state,$status,$now,$state === 'processed' ? $now : null,$row['source_event_id'],$row['upload_id'],$row['attempt_key']])->rowCount();
        return $updated > 0 && $status !== null ? strtoupper($state) : 'UNCONFIRMED';
    }

    public function tick(): array
    {
        if ($this->db->inTransaction()) throw new LogicException('UMK requires a separate transaction');
        $setting = $this->settings();
        if ($setting['activated_at_unix'] === null) return ['status'=>'OFF'];
        try {
            $collection = $this->collect($setting);
        } catch (Throwable $ignored) {
            $collection = 'UNAVAILABLE';
        }
        $result = ['status'=>(int)$setting['delivery_enabled'] === 1 ? 'SEND' : 'COLLECT', 'collection'=>$collection, 'send'=>'IDLE','poll'=>'IDLE'];
        if ($result['status'] === 'COLLECT') return $result;
        $now = ($this->clock)();
        $id = $this->run("SELECT source_event_id FROM anytour_umk_late_events WHERE delivery_state='reserved'
            AND event_at_unix BETWEEN ? AND ? ORDER BY source_event_id LIMIT 1", [$now-21*86400,$now])->fetchColumn();
        $receipt = $this->run("SELECT source_event_id,event_key,attempt_key,upload_id FROM anytour_umk_late_events WHERE delivery_state='accepted'
            ORDER BY CASE WHEN provider_checked_at_unix IS NULL THEN 0 ELSE 1 END,provider_checked_at_unix,source_event_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($id === false && !$receipt) return $result;
        $client = ($this->client)();
        if ($receipt) {
            try { $result['poll'] = $this->poll($client, $receipt); }
            catch (Throwable $ignored) { $result['poll'] = 'UNCONFIRMED'; }
        }
        if ($id !== false) {
            try { $result['send'] = $this->send($client, (int)$id, (int)$setting['activated_at_unix']); }
            catch (Throwable $ignored) { $result['send'] = 'OUTCOME_UNCONFIRMED'; }
        }
        return $result;
    }

    public static function runtime(): array
    {
        if (PHP_SAPI !== 'cli' || !class_exists('ProjectConfig') || ProjectConfig::projectId() !== 'anytour'
            || !class_exists('ConversationDb') || !ConversationDb::isConfigured()) return ['status'=>'OFF'];
        $db = ConversationDb::connection();
        $proof = static function (PDO $db, int $sourceEventId): ?array {
            if (!is_file(__DIR__.'/AnyTourUmkEarlyProofReader.php')) return null;
            require_once __DIR__.'/AnyTourUmkEarlyProofReader.php';
            return AnyTourUmkEarlyProofReader::read($db,$sourceEventId);
        };
        $client = static function (): Max2CommonValueMetrikaClientV2 {
            if (!defined('METRIKA_COUNTER_ID') || (string)METRIKA_COUNTER_ID !== AnyTourUmkLateLedger::COUNTER
                || !defined('METRIKA_OAUTH_TOKEN')) throw new RuntimeException('UMK counter unavailable');
            return Max2CommonValueMetrikaClientV2::forUmk(METRIKA_OAUTH_TOKEN);
        };
        return (new self($db,$proof,$client))->tick();
    }
}
