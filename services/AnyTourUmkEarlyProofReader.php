<?php
declare(strict_types=1);
require_once __DIR__.'/Max2ValueLookupProtocolV2.php';

/** Private evidence for the early part of one admitted, future MAX inquiry. */
final class AnyTourUmkEarlyProofReader
{
    public static function activeSince(PDO $db): ?int
    {
        try {
            $value = $db->query('SELECT activated_at_unix FROM anytour_umk_late_settings WHERE id=1')->fetchColumn();
            return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
        } catch (PDOException $ignored) { return null; }
    }

    /** Called only after the V2 binder matched the signed point recheck to the native inquiry. */
    public static function capture(PDO $db, array $projection, int $epoch): void
    {
        $point = $projection['umk_point_proof'] ?? null;
        $binding = $projection['binding'] ?? [];
        if (($projection['status'] ?? null) !== 'READY' || ($binding['status'] ?? null) !== 'UNIQUE_OBSERVED_MAX2'
            || $db->inTransaction() || !is_array($point) || !is_int($point['issued_at_unix'] ?? null)
            || $point['issued_at_unix'] <= $epoch || $point['issued_at_unix'] > ($binding['inquiry_recorded_at_unix'] ?? 0)
            || !in_array($point['amount_kopeks'] ?? null, [1799,2636], true)
            || !Max2ValueLookupProtocolV2::hash($point['source_sha256'] ?? null)
            || !Max2ValueLookupProtocolV2::hash($binding['inquiry_key'] ?? null)
            || !Max2ValueLookupProtocolV2::hash($binding['immutable_tuple_sha256'] ?? null)
            || !Max2ValueLookupProtocolV2::id($binding['tracker_record_id'] ?? null)) return;
        $statement = $db->prepare('INSERT INTO anytour_umk_point_proofs
            (inquiry_key,tracker_record_id,tuple_sha256,issued_at_unix,amount_kopeks,source_sha256,activated_at_unix)
            SELECT ?,?,?,?,?,?,? FROM anytour_umk_late_settings WHERE id=1 AND activated_at_unix=?');
        try {
            $statement->execute([$binding['inquiry_key'],$binding['tracker_record_id'],$binding['immutable_tuple_sha256'],
                $point['issued_at_unix'],$point['amount_kopeks'],$point['source_sha256'],$epoch,$epoch]);
        } catch (PDOException $error) {
            if ((string)$error->getCode() !== '23000') throw $error;
            // A repeated observation of the same immutable amount needs no second row.
        }
    }

    public static function read(PDO $db, int $sourceEventId): ?array
    {
        if ($sourceEventId < 1) return null;
        $q = $db->prepare("SELECT a.inquiry_key,a.binding_kind,a.tracker_record_id,a.immutable_tuple_sha256,
                a.binding_proof_sha256,a.producer_contract_sha256,s.activated_at_unix
            FROM max2_common_value_events_v2 e JOIN max2_common_value_accounts_v2 a ON a.id=e.account_id
            JOIN anytour_umk_late_settings s ON s.id=1
            WHERE e.id=? AND e.counter_id=98615635 AND e.goal_id=687941321 AND e.target='anytour_modeled_value_v2_max2'
              AND a.counter_id=e.counter_id AND a.goal_id=e.goal_id AND a.target=e.target
              AND a.project_key='anytour' AND a.platform='max'
              AND a.inquiry_recorded_at_unix>s.activated_at_unix AND e.event_at_unix>s.activated_at_unix");
        $q->execute([$sourceEventId]);
        $source = $q->fetch(PDO::FETCH_ASSOC);
        $q->closeCursor();
        if (!$source || !Max2ValueLookupProtocolV2::hash($source['inquiry_key'])
            || $source['producer_contract_sha256'] !== Max2ValueLookupProtocolV2::PRODUCER_CONTRACT_SHA256) return null;
        $result = ['counter_id'=>'98615635','goal_id'=>'696194739','target'=>'anytour_umk_funnel_value',
            'verified'=>true,'inquiry_key'=>$source['inquiry_key']];
        // The deployed producer adds the optional UMK reserve only alongside a full V2 reserve.
        // A complete canonical lookup proving no V2 reserve therefore also covers these future entries.
        if ($source['binding_kind'] === 'PROVEN_NO_NEW_EARLY_RESERVE' && $source['tracker_record_id'] === null
            && Max2ValueLookupProtocolV2::hash($source['binding_proof_sha256'])) {
            return $result + ['state'=>'no_reserve','complete'=>true,'record_id'=>null,'amount_kopeks'=>0,
                'source_sha256'=>hash('sha256', "anytour-umk-complete-empty-v1\n".$source['binding_proof_sha256'])];
        }
        if ($source['binding_kind'] !== 'UNIQUE_OBSERVED_MAX2' || !Max2ValueLookupProtocolV2::id((string)$source['tracker_record_id'])
            || !Max2ValueLookupProtocolV2::hash($source['immutable_tuple_sha256'])) return null;
        $q = $db->prepare('SELECT amount_kopeks,issued_at_unix,source_sha256 FROM anytour_umk_point_proofs
            WHERE inquiry_key=? AND tracker_record_id=? AND tuple_sha256=? AND activated_at_unix=? LIMIT 2');
        $q->execute([$source['inquiry_key'],$source['tracker_record_id'],$source['immutable_tuple_sha256'],$source['activated_at_unix']]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || !in_array((string)$rows[0]['amount_kopeks'],['1799','2636'],true)
            || !Max2ValueLookupProtocolV2::hash($rows[0]['source_sha256'])) return null;
        return $result + ['state'=>'issued_unconfirmed','complete'=>true,'record_id'=>(string)$source['tracker_record_id'],
            'amount_kopeks'=>(int)$rows[0]['amount_kopeks'],'issued_at_unix'=>(int)$rows[0]['issued_at_unix'],
            'source_sha256'=>$rows[0]['source_sha256']];
    }
}
