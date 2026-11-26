<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$siblings = dirname(__DIR__, 2);
$policy = $siblings . '/policy/services/Max2ValueBindingPolicyV2.php';
$source = $siblings . '/source';
if (!is_file($policy)) {
    $policy = $siblings . '/max2-common-v2-policy-20261121/services/Max2ValueBindingPolicyV2.php';
    $source = $siblings . '/max2-common-v2-source-20261121';
}
require_once $policy;
require_once $source . '/services/Max2ModeledValueReaderV2.php';
require_once dirname(__DIR__) . '/services/Max2CommonIdentityReadV2.php';
$raw = file_get_contents($source . '/tests/reader.php');
if (!is_string($raw) || hash('sha256', $raw) !== 'f214b297cb09cd91f7fca0c137a42b535d0249cdb74f9b6dd76d65cb5111139e') throw new RuntimeException('EXACT_READER_FIXTURE_REQUIRED');
$a = strpos($raw, 'final class SavedValueReaderFixture'); $b = strpos($raw, '$tests = [];');
if (!is_int($a) || !is_int($b) || $b <= $a || file_get_contents(dirname(__DIR__) . '/reference/saved-reader-fixture.php') !==
    "<?php\ndeclare(strict_types=1);\n\n" . rtrim(substr($raw, $a, $b - $a)) . "\n") throw new RuntimeException('EXACT_SYNTHETIC_FIXTURE_EXCERPT');
require_once dirname(__DIR__) . '/reference/saved-reader-fixture.php';
function identityCheck(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('IDENTITY_ASSERT_' . $label); }
function identityFixture(string $platform = 'max'): SavedValueReaderFixture
{
    $f = new SavedValueReaderFixture();
    $f->db->exec('DROP TABLE max_channel_conversion_outbox');
    $f->db->exec('CREATE TABLE max_channel_conversion_outbox(id INTEGER PRIMARY KEY,project_key TEXT,platform TEXT,conversation_id INTEGER,external_user_id TEXT,counter_id TEXT,target TEXT,transport_target TEXT,value_contract TEXT,value_profile_id INTEGER,event_unix INTEGER,state TEXT,identity_source_key TEXT,tracker_record_id TEXT,tracker_observed_unix INTEGER,client_id TEXT,value_rub INTEGER)');
    $f->db->exec('CREATE TABLE max2_common_value_accounts_v2(id INTEGER PRIMARY KEY,version TEXT,counter_id INTEGER,goal_id INTEGER,target TEXT,project_key TEXT,platform TEXT,customer_key TEXT,conversation_id TEXT,inquiry_key TEXT,inquiry_journal_id TEXT,inquiry_native_message_id TEXT,inquiry_recorded_at_unix INTEGER,binding_kind TEXT,binding_key TEXT,tracker_record_id TEXT,immutable_tuple_sha256 TEXT,initial_common_kopeks INTEGER,max2_model_version TEXT,producer_contract_sha256 TEXT,identity_sha256 TEXT,created_at_unix INTEGER,client_id TEXT)');
    $f->db->exec('CREATE TABLE advertising_visit_observations(id INTEGER PRIMARY KEY,project_key TEXT,source_key TEXT,platform TEXT,counter_id TEXT,external_user_id TEXT,identity_status TEXT,source_cursor TEXT,visit_id TEXT,visit_at_unix INTEGER,observed_at_unix INTEGER,received_at_unix INTEGER,payload_sha256 TEXT,client_id TEXT,yclid TEXT)');
    $f->db->exec('CREATE INDEX identity_test_profile ON max_channel_conversion_outbox(value_profile_id)');
    $f->db->exec('CREATE INDEX identity_test_visit ON advertising_visit_observations(project_key,source_key,platform,counter_id,external_user_id,identity_status)');
    if ($platform === 'telegram') {
        $f->subject['platform'] = 'telegram'; $f->profile['subject'] = $f->subject;
        $f->profile['profile']['observation_input']['subject'] = $f->subject;
        foreach ($f->profile['profile']['observation_input']['evidence'] as &$ev) $ev['subject'] = $f->subject;
        unset($ev);
        $f->db->exec("UPDATE conversations SET channel='telegram',external_chat_id='12345'");
        $f->db->exec("UPDATE customer_channels SET channel='telegram',external_chat_id='12345'");
        $f->db->exec("UPDATE messages SET channel='telegram'");
        $snap = json_decode($f->db->query('SELECT snapshot_json FROM modeled_conversion_stream_versions')->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
        $snap['platform'] = 'telegram';
        $f->db->prepare('UPDATE modeled_conversion_stream_versions SET snapshot_json=?')->execute([json_encode($snap, JSON_THROW_ON_ERROR)]);
        $f->save();
    }
    identityV1($f);
    return $f;
}
function identityV1(SavedValueReaderFixture $f, array $replace = []): void
{
    $row = ['id' => 1, 'project_key' => 'anytour', 'platform' => $f->subject['platform'], 'conversation_id' => 30,
        'external_user_id' => '12345', 'counter_id' => '98615635', 'target' => 'anytour_value_pred_1_v1',
        'transport_target' => 'anytour_modeled_value_v1', 'value_contract' => 'semantic_stage_value_25000_v1',
        'value_profile_id' => 1, 'event_unix' => $f->floor + 500, 'state' => 'accepted', 'identity_source_key' => 'max_tracker',
        'tracker_record_id' => '71', 'tracker_observed_unix' => $f->floor + 10, 'client_id' => '1234567890123456789', 'value_rub' => 300];
    $row = array_replace($row, $replace);
    $f->db->prepare('INSERT OR REPLACE INTO max_channel_conversion_outbox(' . implode(',', array_keys($row)) . ') VALUES(' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
}
function identitySaved(SavedValueReaderFixture $f, string $profile = '1'): array
{
    $reader = $f->reader(null, null, static fn(string $chat): bool => in_array($chat, ['-12345', '12345'], true));
    $saved = $reader->read($profile, $f->floor, $f->now);
    identityCheck($saved['status'] === 'ADMITTED_SAVED_VALUE', 'synthetic_saved_reader_admission'); return $saved;
}
function identityKey(array $saved): string { return Max2ValueBindingPolicyV2::inquiryKey($saved['subject'], $saved['inquiry']['journal_id']); }
function identityRead(SavedValueReaderFixture $f, ?array $saved = null, ?string $key = null): ?array
{
    $saved ??= identitySaved($f); $before = $f->image();
    $reader = new Max2CommonIdentityReadV2($f->db); $result = $reader->read($saved, $key ?? identityKey($saved), $f->now);
    identityCheck($f->image() === $before && !$f->db->inTransaction(), 'read_only_and_rollback');
    if ($result === null) identityCheck($reader->privateReceipt() === null, 'unknown_has_no_receipt');
    else {
        identityCheck(array_keys($result) === ['admitted', 'subject', 'inquiry_key', 'client_id', 'source_sha256', 'basis']
            && $result['admitted'] === true && $result['subject'] === $f->subject && $result['inquiry_key'] === identityKey($saved), 'exact_private_identity_shape');
        $receipt = $reader->privateReceipt();
        identityCheck(is_array($receipt) && $receipt['writes'] === 0 && $receipt['provider_calls'] === 0 && !$receipt['financial_permission']
            && $receipt['point_readback_matched'] && hash('sha256', json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === $result['source_sha256'], 'private_receipt_is_bound');
    }
    return $result;
}
function identityAccount(SavedValueReaderFixture $f, array $saved, array $replace = []): void
{
    $key = identityKey($saved);
    $row = ['id' => 1, 'version' => 'max2_common_observed_inquiry_value_v2', 'counter_id' => 98615635, 'goal_id' => 687941321,
        'target' => Max2ValueBindingPolicyV2::TARGET, 'project_key' => 'anytour', 'platform' => $f->subject['platform'],
        'customer_key' => '10', 'conversation_id' => '30', 'inquiry_key' => $key, 'inquiry_journal_id' => '100',
        'inquiry_native_message_id' => '500', 'inquiry_recorded_at_unix' => $f->floor + 60,
        'binding_kind' => 'PROVEN_NO_NEW_EARLY_RESERVE', 'binding_key' => hash('sha256', 'max2/common/no-early-reserve/v2|' . $key),
        'tracker_record_id' => null, 'immutable_tuple_sha256' => null, 'initial_common_kopeks' => 0, 'max2_model_version' => null,
        'producer_contract_sha256' => Max2ValueBindingPolicyV2::PRODUCER_CONTRACT_SHA256, 'identity_sha256' => str_repeat('d', 64),
        'created_at_unix' => $f->floor + 700, 'client_id' => str_repeat('7', 64)];
    $row = array_replace($row, $replace);
    $f->db->prepare('INSERT OR REPLACE INTO max2_common_value_accounts_v2(' . implode(',', array_keys($row)) . ') VALUES(' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
}
function identityVisit(SavedValueReaderFixture $f, array $replace = []): void
{
    $row = ['id' => 1, 'project_key' => 'anytour', 'source_key' => 'tgmini', 'platform' => 'telegram', 'counter_id' => '98615635',
        'external_user_id' => '12345', 'identity_status' => 'verified', 'source_cursor' => '1', 'visit_id' => 'synthetic:1',
        'visit_at_unix' => $f->floor + 10, 'observed_at_unix' => $f->floor + 15, 'received_at_unix' => $f->floor + 16,
        'payload_sha256' => str_repeat('e', 64), 'client_id' => '1234567890123456789', 'yclid' => '987654321'];
    $row = array_replace($row, $replace);
    $f->db->prepare('INSERT OR REPLACE INTO advertising_visit_observations(' . implode(',', array_keys($row)) . ') VALUES(' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
}
final class IdentityFaultStatement extends PDOStatement
{
    protected function __construct(private IdentityFaultPDO $owner) {}
    public function execute(?array $params = null): bool
    {
        if ($this->owner->armed) {
            identityCheck(str_starts_with($this->queryString, 'SELECT '), 'no_source_mutation_sql');
            if ($this->owner->missingPoint !== '' && str_contains($this->queryString, $this->owner->missingPoint)) {
                $params = ['999999']; $this->owner->pointCalls++;
            }
            if (str_contains($this->queryString, ' ORDER BY v.id LIMIT 65')) {
                $this->owner->historyReads++;
                if ($this->owner->historyDrift && $this->owner->historyReads === 2) $params = ['999', '999'];
            }
        }
        return parent::execute($params);
    }
}
final class IdentityFaultPDO extends PDO
{
    public bool $armed = false; public string $missingPoint = ''; public int $pointCalls = 0;
    public bool $historyDrift = false; public int $historyReads = 0;
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [IdentityFaultStatement::class, [$this]]);
    }
    public function exec(string $statement): int|false { identityCheck(!$this->armed, 'no_exec_source_mutation'); return parent::exec($statement); }
}
function identityFault(SavedValueReaderFixture $f, string $missingPoint): IdentityFaultPDO
{
    $db = new IdentityFaultPDO();
    foreach ($f->db->query("SELECT sql FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $sql) $db->exec($sql);
    foreach ($f->image() as $table => $rows) foreach ($rows as $row) {
        $db->prepare('INSERT INTO ' . $table . '(' . implode(',', array_keys($row)) . ') VALUES(' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    }
    $f->db = $db; $db->missingPoint = $missingPoint; $db->armed = true; return $db;
}
$tests = [];
$tests['exact_max_v1_profile_identity_without_hotel_scope'] = static function(): void {
    $f = identityFixture(); $result = identityRead($f);
    identityCheck($result['client_id'] === '1234567890123456789' && $result['basis'] === 'saved_v1_identity_read_only', 'exact_v1_identity');
};
$tests['max_canonical_states_and_pending_failed_refusal'] = static function(): void {
    foreach (['ready', 'uploading', 'accepted', 'unknown', 'pending', 'failed'] as $state) {
        $f = identityFixture(); identityV1($f, ['state' => $state]);
        identityCheck((identityRead($f) !== null) === in_array($state, ['ready', 'uploading', 'accepted', 'unknown'], true), 'state_gate');
    }
};
$tests['no_other_profile_or_latest_identity_fallback'] = static function(): void {
    $f = identityFixture(); identityV1($f, ['value_profile_id' => 2, 'target' => 'anytour_value_pred_2_v1']);
    identityCheck(identityRead($f) === null, 'another_profile_not_identity');
};
$tests['v1_scope_actor_contract_event_and_record_required'] = static function(): void {
    foreach (['project_key' => 'other', 'platform' => 'telegram', 'external_user_id' => '999', 'counter_id' => '1',
        'transport_target' => Max2ValueBindingPolicyV2::TARGET, 'value_contract' => 'other', 'target' => 'anytour_value_1_v1',
        'event_unix' => 1795262676, 'identity_source_key' => 'tgmini', 'tracker_record_id' => '', 'tracker_observed_unix' => 0] as $key => $value) {
        $f = identityFixture(); identityV1($f, [$key => $value]); identityCheck(identityRead($f) === null, 'v1_bound_' . $key);
    }
};
$tests['canonical_cid_only_no_raw_yclid_shortcut'] = static function(): void {
    foreach (['', '0', '00123', '1e3', '12 34', str_repeat('5', 41)] as $cid) {
        $f = identityFixture(); identityV1($f, ['client_id' => $cid]); $f->profile['yclid'] = '987654321'; $f->save();
        identityCheck(identityRead($f) === null, 'canonical_cid_required');
    }
};
$tests['ambiguous_profile_rows_even_same_cid_refused'] = static function(): void {
    $f = identityFixture(); identityV1($f, ['id' => 2]); identityCheck(identityRead($f) === null, 'no_duplicate_profile_selection');
};
$tests['v1_immediate_primary_readback_required'] = static function(): void {
    $f = identityFixture(); $saved = identitySaved($f); $fault = identityFault($f, 'FROM max_channel_conversion_outbox WHERE id=?');
    identityCheck(identityRead($f, $saved) === null && $fault->pointCalls === 1, 'missing_exact_v1_pk');
};
$tests['saved_hash_and_inquiry_key_required'] = static function(): void {
    $f = identityFixture(); $saved = identitySaved($f); $saved['estimate']['profile_sha256'] = str_repeat('f', 64);
    identityCheck(identityRead($f, $saved) === null && identityRead($f, null, str_repeat('f', 64)) === null, 'saved_capsule_and_inquiry_binding');
};
$tests['current_native_scope_and_profile_changes_hold'] = static function(): void {
    foreach (["UPDATE customer_channels SET external_user_id='999'", 'UPDATE customer_channels SET customer_id=999',
        'UPDATE conversations SET is_test=1', "UPDATE messages SET direction='outbound'",
        "UPDATE modeled_conversion_profile_versions SET profile_json='{}'"] as $sql) {
        $f = identityFixture(); $saved = identitySaved($f); $f->db->exec($sql); identityCheck(identityRead($f, $saved) === null, 'changed_native_anchor');
    }
};
$tests['revised_native_message_cannot_reuse_identity'] = static function(): void {
    $f = identityFixture(); $saved = identitySaved($f);
    $f->db->exec('INSERT INTO modeled_conversion_stream_versions SELECT 101,project_key,source_kind,source_id,2,observed_at_unix,snapshot_json FROM modeled_conversion_stream_versions WHERE id=100');
    identityCheck(identityRead($f, $saved) === null, 'revised_original_message');
};
$tests['own_zero_reserve_account_reuses_same_inquiry_on_later_profile'] = static function(): void {
    $f = identityFixture(); $saved = identitySaved($f); identityAccount($f, $saved);
    $f->db->exec('INSERT INTO modeled_conversion_profile_versions SELECT 2,project_key,platform,customer_key,conversation_id,observed_at_unix,input_sha256,profile_json FROM modeled_conversion_profile_versions WHERE id=1');
    $saved = identitySaved($f, '2'); $result = identityRead($f, $saved);
    identityCheck($result['client_id'] === str_repeat('7', 64) && $result['basis'] === 'saved_inquiry_identity', 'own_account_not_latest_v1');
};
$tests['own_account_cannot_rebind_or_fall_back_to_v1'] = static function(): void {
    foreach (['binding_kind' => 'UNIQUE_OBSERVED_MAX2', 'initial_common_kopeks' => 2636, 'tracker_record_id' => '71',
        'inquiry_key' => str_repeat('f', 64), 'customer_key' => '11', 'inquiry_journal_id' => '101', 'goal_id' => 678187097,
        'producer_contract_sha256' => str_repeat('f', 64), 'client_id' => str_repeat('7', 65)] as $key => $value) {
        $f = identityFixture(); $saved = identitySaved($f); identityAccount($f, $saved, [$key => $value]);
        identityCheck(identityRead($f, $saved) === null, 'conflicting_own_account_' . $key);
    }
};
$tests['own_account_primary_readback_required'] = static function(): void {
    $f = identityFixture(); $saved = identitySaved($f); identityAccount($f, $saved);
    $fault = identityFault($f, 'FROM max2_common_value_accounts_v2 WHERE id=?');
    identityCheck(identityRead($f, $saved) === null && $fault->pointCalls === 1, 'missing_own_pk');
};
$tests['telegram_complete_verified_history_without_post_requirements'] = static function(): void {
    $f = identityFixture('telegram'); identityVisit($f); $result = identityRead($f);
    identityCheck($result['client_id'] === '1234567890123456789' && $result['basis'] === 'saved_inquiry_identity', 'verified_tgmini_identity');
    identityVisit($f, ['id' => 2, 'source_cursor' => '2', 'visit_id' => 'synthetic:2']);
    identityCheck(identityRead($f)['client_id'] === $result['client_id'], 'complete_same_cid_history_allowed');
};
$tests['telegram_two_cids_and_same_visit_other_owner_hold'] = static function(): void {
    $f = identityFixture('telegram'); identityVisit($f); identityVisit($f, ['id' => 2, 'visit_id' => 'synthetic:2', 'client_id' => '777']);
    identityCheck(identityRead($f) === null, 'two_tg_cids');
    $f = identityFixture('telegram'); identityVisit($f); identityVisit($f, ['id' => 2, 'external_user_id' => '999']);
    identityCheck(identityRead($f) === null, 'same_visit_two_actors');
};
$tests['telegram_only_verified_exact_source_scope'] = static function(): void {
    foreach (['identity_status' => 'unverified', 'project_key' => 'other', 'source_key' => 'other', 'platform' => 'max',
        'counter_id' => '1', 'external_user_id' => '999'] as $key => $value) {
        $f = identityFixture('telegram'); identityVisit($f, [$key => $value]); identityCheck(identityRead($f) === null, 'tg_scope_' . $key);
    }
};
$tests['telegram_late_only_and_yclid_only_are_unknown'] = static function(): void {
    $f = identityFixture('telegram'); identityCheck(identityRead($f) === null, 'no_tg_identity_not_zero_proof');
    identityVisit($f, ['client_id' => '']); identityCheck(identityRead($f) === null, 'yclid_not_client_id');
    identityVisit($f, ['visit_at_unix' => $f->floor + 501, 'observed_at_unix' => $f->floor + 502, 'received_at_unix' => $f->floor + 503]);
    identityCheck(identityRead($f) === null, 'entry_after_model_event');
};
$tests['telegram_future_identity_cannot_replace_prior_cid'] = static function(): void {
    $f = identityFixture('telegram'); identityVisit($f);
    identityVisit($f, ['id' => 2, 'visit_id' => 'synthetic:2', 'client_id' => '777', 'visit_at_unix' => $f->floor + 501,
        'observed_at_unix' => $f->floor + 502, 'received_at_unix' => $f->floor + 503]);
    identityCheck(identityRead($f)['client_id'] === '1234567890123456789', 'prior_not_latest_cid');
};
$tests['telegram_malformed_relevant_row_blocks_entire_history'] = static function(): void {
    foreach (['client_id' => '0012', 'yclid' => 'bad', 'payload_sha256' => 'bad', 'source_cursor' => '0',
        'visit_at_unix' => 1795262676 + 1000, 'observed_at_unix' => 1795262676 + 1000, 'received_at_unix' => 1795262676 + 1000] as $key => $value) {
        $f = identityFixture('telegram'); identityVisit($f); identityVisit($f, ['id' => 2, 'visit_id' => 'synthetic:2', $key => $value]);
        identityCheck(identityRead($f) === null, 'malformed_tg_' . $key);
    }
};
$tests['telegram_bound_inclusive_and_truncated_unknown'] = static function(): void {
    $f = identityFixture('telegram');
    for ($n = 1; $n <= 64; $n++) identityVisit($f, ['id' => $n, 'source_cursor' => (string)$n, 'visit_id' => 'synthetic:' . $n]);
    identityCheck(identityRead($f) !== null, 'exact_64_complete');
    identityVisit($f, ['id' => 65, 'source_cursor' => '65', 'visit_id' => 'synthetic:65']); identityCheck(identityRead($f) === null, '65_is_unknown');
};
$tests['telegram_primary_readback_required'] = static function(): void {
    $f = identityFixture('telegram'); identityVisit($f); $saved = identitySaved($f);
    $fault = identityFault($f, 'FROM advertising_visit_observations v WHERE v.id=?');
    identityCheck(identityRead($f, $saved) === null && $fault->pointCalls === 1, 'missing_tg_pk');
};
$tests['telegram_complete_history_drift_before_point_read_holds'] = static function(): void {
    $f = identityFixture('telegram'); identityVisit($f); $saved = identitySaved($f);
    $fault = identityFault($f, ''); $fault->historyDrift = true;
    identityCheck(identityRead($f, $saved) === null && $fault->historyReads === 2, 'changed_complete_history_not_selected_pk_only');
};
$tests['storage_failure_and_outer_transaction_preserved'] = static function(): void {
    $f = identityFixture(); $saved = identitySaved($f); $f->db->exec('DROP TABLE max2_common_value_accounts_v2');
    identityCheck(identityRead($f, $saved) === null, 'missing_schema_no_v1_fallback');
    $f = identityFixture(); $saved = identitySaved($f); $reader = new Max2CommonIdentityReadV2($f->db); $before = $f->image();
    $f->db->beginTransaction(); identityCheck($reader->read($saved, identityKey($saved), $f->now) === null
        && $f->db->inTransaction() && $f->image() === $before, 'caller_transaction_untouched'); $f->db->rollBack();
};
foreach ($tests as $name => $test) { $test(); }
echo json_encode(['status' => 'PASS', 'checks' => count($tests), 'database_kind' => 'sqlite_memory_only',
    'model_adapter' => 'explicit_synthetic_saved_reader_seams', 'identity_reader' => 'actual_new_class',
    'provider_calls' => 0, 'live_database_connections' => 0, 'old_source_changes' => 0, 'activation' => false], JSON_THROW_ON_ERROR) . "\n";
