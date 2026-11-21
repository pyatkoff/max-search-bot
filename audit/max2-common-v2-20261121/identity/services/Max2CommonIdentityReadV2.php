<?php
declare(strict_types=1);
if (!class_exists('Max2ValueBindingPolicyV2', false)) require_once __DIR__ . '/Max2ValueBindingPolicyV2.php';

/**
 * Private identity projection over an already admitted saved-value result.
 * No hotel/post requirement. No authentication, zero-reserve proof or delivery
 * permission is manufactured here: the binder independently owns those gates.
 * Every missing, ambiguous, malformed or over-budget source returns null.
 */
final class Max2CommonIdentityReadV2
{
    public const VERSION = 'max2_common_saved_identity_v2';
    private const V1_TARGET = 'anytour_modeled_value_v1';
    private const V1_CONTRACT = 'semantic_stage_value_25000_v1';
    private PDO $pdo;
    private bool $mysql;
    private ?array $receipt = null;
    public function __construct(PDO $pdo)
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) throw new InvalidArgumentException('IDENTITY_STORAGE_REQUIRED');
        if ($driver === 'sqlite') foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $database) {
            if (($database['file'] ?? null) !== '') throw new InvalidArgumentException('IDENTITY_SYNTHETIC_MEMORY_ONLY');
        }
        $this->pdo = $pdo; $this->mysql = $driver === 'mysql';
    }
    private static function need(bool $ok): void { if (!$ok) throw new RuntimeException('IDENTITY_UNPROVED'); }
    private static function id($v): bool { return Max2ValueBindingPolicyV2::positiveId($v); }
    private static function hash($v): bool { return Max2ValueBindingPolicyV2::digest($v); }
    private static function cid($v, int $max = 64): bool {
        return is_string($v) && strlen($v) <= $max && preg_match('/\A[1-9][0-9]*\z/D', $v) === 1;
    }
    private static function uint($v): ?int {
        if (is_int($v)) return $v >= 0 ? $v : null;
        return is_string($v) && preg_match('/\A(?:0|[1-9][0-9]{0,11})\z/D', $v) === 1 ? (int)$v : null;
    }
    private static function digest(array $value): string {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    private static function same($a, array $b): bool { return Max2ValueBindingPolicyV2::sameSubject($a, $b); }
    private static function at($v): ?int {
        if (!is_string($v) || preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/D', $v) !== 1) return null;
        $n = strtotime($v); return $n !== false && gmdate('Y-m-d\TH:i:s\Z', $n) === $v ? $n : null;
    }
    private function rows(string $sql, array $params): array {
        if ($this->mysql) $sql = preg_replace('/\ASELECT /', 'SELECT /*+ MAX_EXECUTION_TIME(250) */ ', $sql, 1);
        $q = $this->pdo->prepare($sql); $q->execute($params);
        try { $rows = $q->fetchAll(PDO::FETCH_ASSOC); }
        finally { $q->closeCursor(); }
        self::need(count($rows) <= 65 && strlen(json_encode($rows, JSON_THROW_ON_ERROR)) <= 262144);
        return $rows;
    }
    private function small(string $column, int $limit): string {
        $length = $this->mysql ? 'OCTET_LENGTH(' . $column . ')' : 'LENGTH(CAST(' . $column . ' AS BLOB))';
        return 'CASE WHEN ' . $length . '<=' . $limit . ' THEN ' . $column . ' ELSE NULL END';
    }
    private static function input(array $saved, string $key, int $now): array {
        $s = $saved['subject'] ?? null; $i = $saved['inquiry'] ?? null; $e = $saved['estimate'] ?? null; $a = $saved['actor_context'] ?? null;
        self::need(($saved['status'] ?? null) === 'ADMITTED_SAVED_VALUE' && ($saved['version'] ?? null) === 'max2_saved_inquiry_projection_v2'
            && ($saved['writes'] ?? null) === 0 && ($saved['provider_calls'] ?? null) === 0
            && ($saved['financial_delivery_permission'] ?? null) === false && Max2ValueBindingPolicyV2::subject($s)
            && is_array($i) && is_array($e) && is_array($a) && self::same($i['subject'] ?? null, $s)
            && self::same($e['subject'] ?? null, $s) && self::same($a['subject'] ?? null, $s)
            && self::id($i['journal_id'] ?? null) && self::id($i['native_message_id'] ?? null)
            && ($i['kind'] ?? null) === 'substantive_interest' && ($i['source_version'] ?? null) === 1
            && ($i['verified_customer_message'] ?? null) === true && is_int($i['recorded_at_unix'] ?? null)
            && $i['recorded_at_unix'] >= Max2ValueBindingPolicyV2::PRODUCER_CUTOVER_UNIX
            && self::hash($key) && $key === Max2ValueBindingPolicyV2::inquiryKey($s, $i['journal_id'])
            && ($e['admitted_saved_profile'] ?? null) === true && ($e['inquiry_journal_id'] ?? null) === $i['journal_id']
            && self::id($e['profile_id'] ?? null) && self::hash($e['profile_sha256'] ?? null)
            && ($e['model_version'] ?? null) === 'saved_v1_model_readonly_in_common_v2' && ($e['currency'] ?? null) === 'RUB'
            && is_int($e['event_unix'] ?? null) && is_int($e['profile_observed_at_unix'] ?? null)
            && is_int($e['input_cutoff_unix'] ?? null) && $i['recorded_at_unix'] <= $e['input_cutoff_unix']
            && $e['input_cutoff_unix'] <= $e['event_unix'] && $e['event_unix'] <= $e['profile_observed_at_unix']
            && $e['profile_observed_at_unix'] <= $now && self::id($a['external_user_id'] ?? null)
            && self::id($a['customer_channel_id'] ?? null) && self::hash($a['subject_actor_binding_sha256'] ?? null)
            && ($a['external_chat_id'] ?? null) === ($s['platform'] === 'max' ? '-' : '') . $a['external_user_id']);
        return [$s, $i, $e, $a];
    }
    private function anchors(array $s, array $i, array $e, array $a): array {
        $profiles = $this->rows('SELECT id,project_key,platform,customer_key,conversation_id,observed_at_unix,input_sha256,'
            . $this->small('profile_json', 131072) . ' AS profile_json FROM modeled_conversion_profile_versions WHERE id=? LIMIT 1', [$e['profile_id']]);
        self::need(count($profiles) === 1); $p = $profiles[0];
        self::need((string)$p['id'] === $e['profile_id'] && $p['project_key'] === 'anytour' && $p['platform'] === $s['platform']
            && (string)$p['customer_key'] === $s['customer_key'] && (string)$p['conversation_id'] === $s['conversation_id']
            && self::uint($p['observed_at_unix']) === $e['profile_observed_at_unix'] && self::hash($p['input_sha256'])
            && is_string($p['profile_json']) && hash_equals($e['profile_sha256'], hash('sha256', $p['profile_json'])));
        $w = json_decode($p['profile_json'], true, 64, JSON_THROW_ON_ERROR);
        self::need(is_array($w) && ($w['access_status'] ?? null) === 'allowed' && self::same($w['subject'] ?? null, $s)
            && ($w['processing']['assessment_kind'] ?? null) === 'live' && self::hash($w['processing']['source_sha256'] ?? null)
            && self::at($w['contextual_signals']['input_cutoff_at'] ?? null) === $e['input_cutoff_unix']);
        $ref = 'journal:' . $i['journal_id'];
        $events = $w['contextual_signals']['events'] ?? null; $evidence = $w['profile']['observation_input']['evidence'] ?? null; $refs = $w['source_references'] ?? null;
        foreach ([$events, $evidence, $refs] as $list) self::need(is_array($list) && array_is_list($list) && count($list) <= 1000);
        $events = array_values(array_filter($events, static fn($v): bool => is_array($v) && ($v['source_ref'] ?? null) === $ref
            && ($v['goal'] ?? null) === 'substantive_interest' && in_array($v['product'] ?? null, ['tour', 'unknown'], true)));
        $evidence = array_values(array_filter($evidence, static fn($v): bool => is_array($v) && ($v['id'] ?? null) === 'message:' . $ref));
        $refs = array_values(array_filter($refs, static fn($v): bool => is_array($v) && ($v['journal_id'] ?? null) === $i['journal_id']));
        self::need(count($events) === 1 && count($evidence) === 1 && count($refs) === 1);
        $ev = $evidence[0]; $rf = $refs[0]; $available = self::at($ev['available_at'] ?? null);
        self::need(($ev['kind'] ?? null) === 'customer_message' && self::same($ev['subject'] ?? null, $s)
            && self::at($ev['at'] ?? null) === $i['recorded_at_unix'] && $available !== null
            && $available >= $i['recorded_at_unix'] && $available <= $e['input_cutoff_unix']
            && ($rf['source_kind'] ?? null) === 'message' && ($rf['source_id'] ?? null) === $i['native_message_id']
            && ($rf['source_version_no'] ?? null) === 1 && ($rf['source_status'] ?? null) === 'present'
            && ($rf['observed_at'] ?? null) === $ev['available_at']);
        $bindings = $this->rows('SELECT c.id,c.customer_id,c.customer_channel_id,c.project_key,c.channel,c.external_chat_id,c.is_test,'
            . 'cc.customer_id AS channel_customer,cc.project_key AS channel_project,cc.channel AS channel_platform,cc.external_user_id,cc.external_chat_id AS channel_chat'
            . ' FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id WHERE c.id=? LIMIT 1', [$s['conversation_id']]);
        self::need(count($bindings) === 1); $b = $bindings[0];
        self::need((string)$b['id'] === $s['conversation_id'] && (string)$b['customer_id'] === $s['customer_key']
            && (string)$b['channel_customer'] === $s['customer_key'] && (string)$b['customer_channel_id'] === $a['customer_channel_id']
            && $b['project_key'] === 'anytour' && $b['channel_project'] === 'anytour' && $b['channel'] === $s['platform']
            && $b['channel_platform'] === $s['platform'] && (string)$b['is_test'] === '0'
            && $b['external_user_id'] === $a['external_user_id'] && $b['external_chat_id'] === $a['external_chat_id']
            && $b['channel_chat'] === $a['external_chat_id'] && self::digest($b) === $a['subject_actor_binding_sha256']);
        $native = $this->rows('SELECT j.id,j.project_key,j.source_kind,j.source_id,j.version_no,j.observed_at_unix,'
            . $this->small('j.snapshot_json', 65536) . ' AS snapshot_json,m.id AS native_id,m.conversation_id,m.channel,m.direction,m.sender_type,'
            . "EXISTS(SELECT 1 FROM modeled_conversion_stream_versions x WHERE x.project_key='anytour' AND x.source_kind='message' AND x.source_id=j.source_id AND x.version_no>1 LIMIT 1) AS revised"
            . ' FROM modeled_conversion_stream_versions j LEFT JOIN messages m ON m.id=j.source_id WHERE j.id=? LIMIT 1', [$i['journal_id']]);
        self::need(count($native) === 1); $n = $native[0];
        self::need((string)$n['id'] === $i['journal_id'] && $n['project_key'] === 'anytour' && $n['source_kind'] === 'message'
            && (string)$n['source_id'] === $i['native_message_id'] && (string)$n['native_id'] === $i['native_message_id']
            && (string)$n['version_no'] === '1' && self::uint($n['observed_at_unix']) === $available
            && (string)$n['conversation_id'] === $s['conversation_id'] && $n['channel'] === $s['platform']
            && $n['direction'] === 'inbound' && $n['sender_type'] === 'customer' && (string)$n['revised'] === '0'
            && is_string($n['snapshot_json']));
        $snap = json_decode($n['snapshot_json'], true, 32, JSON_THROW_ON_ERROR);
        self::need(is_array($snap) && self::same(array_intersect_key($snap, $s), $s)
            && ($snap['source_kind'] ?? null) === 'message' && (string)($snap['source_id'] ?? '') === $i['native_message_id']
            && ($snap['source_status'] ?? null) === 'present' && self::uint($snap['source_created_at_unix'] ?? null) === $i['recorded_at_unix']
            && in_array($snap['source_timestamp_basis'] ?? null, ['canonical_recording_unix_v1', 'database_session_timezone_to_unix'], true)
            && ($snap['payload']['direction'] ?? null) === 'inbound' && ($snap['payload']['sender_type'] ?? null) === 'customer');
        unset($p['profile_json'], $n['snapshot_json']);
        return ['profile' => $p, 'profile_sha256' => $e['profile_sha256'], 'binding' => $b, 'native' => $n, 'native_snapshot_sha256' => self::digest($snap)];
    }
    private function own(array $s, array $i, string $key, int $now): ?array {
        $columns = 'id,version,counter_id,goal_id,target,project_key,platform,customer_key,conversation_id,inquiry_key,inquiry_journal_id,'
            . 'inquiry_native_message_id,inquiry_recorded_at_unix,binding_kind,binding_key,tracker_record_id,immutable_tuple_sha256,'
            . 'initial_common_kopeks,max2_model_version,producer_contract_sha256,identity_sha256,created_at_unix,' . $this->small('client_id', 64) . ' AS client_id';
        $rows = $this->rows('SELECT ' . $columns . ' FROM max2_common_value_accounts_v2 WHERE counter_id=98615635 AND target=? AND project_key=? AND platform=? AND conversation_id=? LIMIT 2',
            [Max2ValueBindingPolicyV2::TARGET, 'anytour', $s['platform'], $s['conversation_id']]);
        if (!$rows) return null;
        self::need(count($rows) === 1); $r = $rows[0];
        self::need(self::id((string)$r['id']) && $r['version'] === 'max2_common_observed_inquiry_value_v2'
            && (string)$r['counter_id'] === '98615635' && (string)$r['goal_id'] === '687941321' && $r['target'] === Max2ValueBindingPolicyV2::TARGET
            && $r['project_key'] === 'anytour' && $r['platform'] === $s['platform'] && $r['customer_key'] === $s['customer_key']
            && $r['conversation_id'] === $s['conversation_id'] && $r['inquiry_key'] === $key && $r['inquiry_journal_id'] === $i['journal_id']
            && $r['inquiry_native_message_id'] === $i['native_message_id'] && self::uint($r['inquiry_recorded_at_unix']) === $i['recorded_at_unix']
            && $r['binding_kind'] === 'PROVEN_NO_NEW_EARLY_RESERVE' && $r['binding_key'] === hash('sha256', 'max2/common/no-early-reserve/v2|' . $key)
            && $r['tracker_record_id'] === null && $r['immutable_tuple_sha256'] === null && self::uint($r['initial_common_kopeks']) === 0
            && $r['max2_model_version'] === null && $r['producer_contract_sha256'] === Max2ValueBindingPolicyV2::PRODUCER_CONTRACT_SHA256
            && self::hash($r['identity_sha256']) && self::cid($r['client_id']) && self::uint($r['created_at_unix']) !== null
            && self::uint($r['created_at_unix']) >= $i['recorded_at_unix'] && self::uint($r['created_at_unix']) <= $now);
        $again = $this->rows('SELECT ' . $columns . ' FROM max2_common_value_accounts_v2 WHERE id=? LIMIT 1', [(string)$r['id']]);
        self::need($again === [$r]);
        return ['client_id' => $r['client_id'], 'basis' => 'saved_inquiry_identity', 'source_kind' => 'own_zero_reserve_account', 'tuple' => $r];
    }
    private function max(array $s, array $e, array $a): ?array {
        $columns = 'id,project_key,platform,conversation_id,external_user_id,counter_id,target,transport_target,value_contract,value_profile_id,'
            . 'event_unix,state,identity_source_key,tracker_record_id,tracker_observed_unix,' . $this->small('client_id', 40) . ' AS client_id';
        $rows = $this->rows('SELECT ' . $columns . ' FROM max_channel_conversion_outbox WHERE value_profile_id=? LIMIT 3', [$e['profile_id']]);
        if (!$rows) return null;
        self::need(count($rows) === 1); $r = $rows[0];
        self::need(self::id((string)$r['id']) && $r['project_key'] === 'anytour' && $r['platform'] === 'max'
            && (string)$r['conversation_id'] === $s['conversation_id'] && $r['external_user_id'] === $a['external_user_id']
            && (string)$r['counter_id'] === '98615635' && $r['transport_target'] === self::V1_TARGET && $r['value_contract'] === self::V1_CONTRACT
            && (string)$r['value_profile_id'] === $e['profile_id'] && $r['target'] === 'anytour_value_pred_' . $e['profile_id'] . '_v1'
            && self::uint($r['event_unix']) === $e['event_unix'] && $r['identity_source_key'] === 'max_tracker'
            && in_array($r['state'], ['ready', 'uploading', 'accepted', 'unknown'], true) && self::cid($r['client_id'], 40)
            && self::id(is_int($r['tracker_record_id']) ? (string)$r['tracker_record_id'] : $r['tracker_record_id'])
            && self::uint($r['tracker_observed_unix']) !== null && self::uint($r['tracker_observed_unix']) >= 946684800
            && self::uint($r['tracker_observed_unix']) <= $e['event_unix']);
        $again = $this->rows('SELECT ' . $columns . ' FROM max_channel_conversion_outbox WHERE id=? LIMIT 1', [(string)$r['id']]);
        self::need($again === [$r]);
        return ['client_id' => $r['client_id'], 'basis' => 'saved_v1_identity_read_only', 'source_kind' => 'exact_v1_profile', 'tuple' => $r];
    }
    private function telegram(array $e, array $a, int $now): ?array {
        $where = "v.project_key='anytour' AND HEX(v.project_key)=HEX('anytour') AND v.source_key='tgmini' AND HEX(v.source_key)=HEX('tgmini')"
            . " AND v.platform='telegram' AND HEX(v.platform)=HEX('telegram') AND v.counter_id='98615635' AND HEX(v.counter_id)=HEX('98615635')"
            . " AND v.external_user_id=? AND HEX(v.external_user_id)=HEX(?) AND v.identity_status='verified' AND HEX(v.identity_status)=HEX('verified')";
        $params = [$a['external_user_id'], $a['external_user_id']];
        $count = $this->rows('SELECT COUNT(*) AS n FROM (SELECT 1 FROM advertising_visit_observations v WHERE ' . $where . ' LIMIT 65) bounded_identity', $params);
        self::need(count($count) === 1 && self::uint($count[0]['n']) !== null && self::uint($count[0]['n']) < 65);
        $conflict = "EXISTS(SELECT 1 FROM advertising_visit_observations x WHERE x.project_key=v.project_key AND HEX(x.project_key)=HEX(v.project_key)"
            . " AND x.source_key=v.source_key AND HEX(x.source_key)=HEX(v.source_key) AND x.platform=v.platform AND HEX(x.platform)=HEX(v.platform)"
            . " AND x.counter_id=v.counter_id AND HEX(x.counter_id)=HEX(v.counter_id) AND x.visit_id=v.visit_id AND HEX(x.visit_id)=HEX(v.visit_id)"
            . " AND x.identity_status='verified' AND HEX(x.identity_status)=HEX('verified') AND HEX(x.external_user_id)<>HEX(v.external_user_id) LIMIT 1)";
        $columns = 'v.id,v.project_key,v.source_key,v.platform,v.counter_id,v.external_user_id,v.identity_status,v.source_cursor,'
            . $this->small('v.visit_id', 128) . ' AS visit_id,v.visit_at_unix,v.observed_at_unix,v.received_at_unix,v.payload_sha256,'
            . $this->small('v.client_id', 40) . ' AS client_id,' . $this->small('v.yclid', 40) . ' AS yclid,' . $conflict . ' AS identity_conflict';
        $rows = $this->rows('SELECT ' . $columns . ' FROM advertising_visit_observations v WHERE ' . $where . ' ORDER BY v.id LIMIT 65', $params);
        self::need(count($rows) === self::uint($count[0]['n'])); $ids = []; $eligible = [];
        foreach ($rows as $r) {
            $visit = self::uint($r['visit_at_unix']); $observed = self::uint($r['observed_at_unix']); $received = self::uint($r['received_at_unix']);
            self::need(self::id((string)$r['id']) && $r['project_key'] === 'anytour' && $r['source_key'] === 'tgmini'
                && $r['platform'] === 'telegram' && (string)$r['counter_id'] === '98615635' && $r['external_user_id'] === $a['external_user_id']
                && $r['identity_status'] === 'verified' && self::id((string)$r['source_cursor']) && is_string($r['visit_id'])
                && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $r['visit_id']) === 1 && self::hash($r['payload_sha256'])
                && $visit !== null && $observed !== null && $received !== null && $visit >= 946684800 && $visit <= $observed
                && $observed <= $now && $received <= $now && $observed <= $received + 300 && (string)$r['identity_conflict'] === '0'
                && is_string($r['client_id']) && ($r['client_id'] === '' || self::cid($r['client_id'], 40))
                && is_string($r['yclid']) && ($r['yclid'] === '' || self::cid($r['yclid'], 40)));
            if ($visit > $e['event_unix'] || $r['client_id'] === '') continue;
            $ids[$r['client_id']] = true; $eligible[] = $r;
        }
        if (!$eligible) return null;
        self::need(count($ids) === 1); $r = $eligible[0]; // All complete eligible rows agree; no latest-ID selection.
        // The current read is READ COMMITTED. Refuse a changed complete history,
        // including a concurrent second identity, before its exact point read.
        $countAgain = $this->rows('SELECT COUNT(*) AS n FROM (SELECT 1 FROM advertising_visit_observations v WHERE ' . $where . ' LIMIT 65) bounded_identity', $params);
        self::need($countAgain === $count);
        $historyAgain = $this->rows('SELECT ' . $columns . ' FROM advertising_visit_observations v WHERE ' . $where . ' ORDER BY v.id LIMIT 65', $params);
        self::need($historyAgain === $rows);
        $again = $this->rows('SELECT ' . $columns . ' FROM advertising_visit_observations v WHERE v.id=? LIMIT 1', [(string)$r['id']]);
        self::need($again === [$r]);
        return ['client_id' => $r['client_id'], 'basis' => 'saved_inquiry_identity', 'source_kind' => 'verified_tgmini_history',
            'tuple' => $r, 'complete_history_sha256' => self::digest($rows), 'complete_history_readback_matched' => true,
            'rows_checked' => count($rows)];
    }
    /** Exact private projection; null means HOLD, never zero value or no MAX2. */
    public function read(array $saved, string $inquiryKey, int $now): ?array
    {
        $this->receipt = null; $started = false; $began = hrtime(true);
        if ($this->pdo->inTransaction()) return null;
        try {
            [$s, $i, $e, $a] = self::input($saved, $inquiryKey, $now);
            if ($this->mysql) $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED, READ ONLY');
            self::need($this->pdo->beginTransaction()); $started = true;
            $anchors = $this->anchors($s, $i, $e, $a);
            $source = $s['platform'] === 'max' ? $this->own($s, $i, $inquiryKey, $now) : null;
            if ($source === null) $source = $s['platform'] === 'max' ? $this->max($s, $e, $a) : $this->telegram($e, $a, $now);
            self::need($source !== null && $this->anchors($s, $i, $e, $a) === $anchors && hrtime(true) - $began <= 2000000000);
            $receipt = ['version' => self::VERSION, 'subject' => $s, 'inquiry_key' => $inquiryKey, 'checked_at_unix' => $now,
                'source_kind' => $source['source_kind'], 'immutable_tuple_sha256' => self::digest($source['tuple']),
                'source' => $source, 'anchors_sha256' => self::digest($anchors), 'point_readback_matched' => true,
                'provider_calls' => 0, 'writes' => 0, 'financial_permission' => false];
            self::need($this->pdo->rollBack()); $started = false;
            $this->receipt = $receipt;
            return ['admitted' => true, 'subject' => $s, 'inquiry_key' => $inquiryKey, 'client_id' => $source['client_id'],
                'source_sha256' => self::digest($receipt), 'basis' => $source['basis']];
        } catch (Throwable $ignored) {
            if ($started && $this->pdo->inTransaction()) try { $this->pdo->rollBack(); } catch (Throwable $ignoredAgain) {}
            $this->receipt = null; return null;
        }
    }
    /** Private evidence for the binder's durable state only. Never log or publicly serialize. */
    public function privateReceipt(): ?array { return $this->receipt; }
}
