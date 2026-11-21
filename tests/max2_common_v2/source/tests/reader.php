<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/Max2ModeledValueReaderV2.php';

/** Real in-memory SQLite reads/transactions; model and price adapters are explicit synthetic seams. */
final class SavedValueReaderFixture
{
    public PDO $db;
    public array $profile;
    public array $subject;
    public int $floor = 1795262676;
    public int $observed;
    public int $now;
    public int $candidateCalls = 0;
    public int $adjustCalls = 0;
    public array $allowedCalls = [];
    public const PRODUCER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public function __construct()
    {
        $this->db = new PDO('sqlite::memory:', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        foreach ($this->db->query('PRAGMA database_list')->fetchAll() as $database) {
            if ($database['file'] !== '') throw new RuntimeException('fixture must be memory only');
        }
        $ddl = [
            'CREATE TABLE modeled_conversion_profile_versions(id INTEGER PRIMARY KEY,project_key TEXT,platform TEXT,customer_key INTEGER,conversation_id INTEGER,observed_at_unix INTEGER,input_sha256 TEXT,profile_json TEXT)',
            'CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,channel TEXT,external_chat_id TEXT,is_test INTEGER)',
            'CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT)',
            'CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,channel TEXT,direction TEXT,sender_type TEXT)',
            'CREATE TABLE modeled_conversion_stream_versions(id INTEGER PRIMARY KEY,project_key TEXT,source_kind TEXT,source_id INTEGER,version_no INTEGER,observed_at_unix INTEGER,snapshot_json TEXT)',
            'CREATE TABLE max_channel_conversion_outbox(id INTEGER PRIMARY KEY,goal_id TEXT,transport_target TEXT,value_rub INTEGER,state TEXT)',
            'CREATE TABLE modeled_conversion_value_export_settings(project_key TEXT PRIMARY KEY,goal_id TEXT,mode TEXT,paid_conversion_rub INTEGER)',
            'CREATE TABLE modeled_conversion_price_audit(outbox_id INTEGER PRIMARY KEY,action TEXT,applied_value_rub INTEGER)',
            'CREATE TABLE modeled_conversion_profiles_state(project_key TEXT PRIMARY KEY,enabled INTEGER,cursor INTEGER)'];
        foreach ($ddl as $sql) $this->db->exec($sql);
        $this->db->exec("INSERT INTO customer_channels VALUES(20,10,'anytour','max','12345','-12345')");
        $this->db->exec("INSERT INTO conversations VALUES(30,10,20,'anytour','max','-12345',0)");
        $this->db->exec("INSERT INTO max_channel_conversion_outbox VALUES(1,'678187097','anytour_modeled_value_v1',500,'accepted')");
        $this->db->exec("INSERT INTO modeled_conversion_value_export_settings VALUES('anytour','678187097','send',300)");
        $this->db->exec("INSERT INTO modeled_conversion_price_audit VALUES(1,'unchanged',500)");
        $this->db->exec("INSERT INTO modeled_conversion_profiles_state VALUES('anytour',1,998)");
        $this->observed = $this->floor + 600; $this->now = $this->floor + 900;
        $this->subject = ['project_key' => 'anytour', 'platform' => 'max', 'customer_key' => '10', 'conversation_id' => '30'];
        $cutoff = self::time($this->floor + 480); $prediction = self::time($this->floor + 500);
        $this->profile = ['access_status' => 'allowed', 'subject' => $this->subject,
            'processing' => ['source_sha256' => self::PRODUCER, 'assessment_kind' => 'live'],
            'contextual_signals' => ['version' => 'live_contextual_ordinal_v1', 'extractor_version' => 'ru_archive_contextual_v2',
                'input_cutoff_at' => $cutoff, 'prediction_at' => $prediction, 'events' => []],
            'profile' => ['observation_input' => ['subject' => $this->subject, 'prediction_at' => $prediction, 'evidence' => []]],
            'source_references' => [], 'source_fence_journal_id' => '150',
            'semantic_prediction' => ['model_sha256' => str_repeat('b', 64)]];
        $this->addInquiry('100', '500', $this->floor + 60);
        $this->save();
    }
    public static function time(int $unix): string { return gmdate('Y-m-d\TH:i:s\Z', $unix); }
    public function addInquiry(string $journal, string $message, int $at, string $product = 'tour'): void
    {
        $ref = 'journal:' . $journal; $when = self::time($at); $available = self::time($at + 5);
        $this->profile['contextual_signals']['events'][] = ['goal' => 'substantive_interest', 'source_ref' => $ref, 'product' => $product];
        $this->profile['profile']['observation_input']['evidence'][] = ['id' => 'message:' . $ref, 'kind' => 'customer_message',
            'subject' => $this->subject, 'at' => $when, 'available_at' => $available,
            'references' => [['source' => 'message', 'id' => $ref, 'at' => $when, 'available_at' => $available]]];
        $this->profile['source_references'][] = ['journal_id' => $journal, 'source_kind' => 'message', 'source_id' => $message,
            'source_version_no' => 1, 'source_status' => 'present', 'observed_at' => $available];
        $snapshot = ['source_kind' => 'message', 'source_id' => $message, 'source_status' => 'present', 'project_key' => 'anytour',
            'platform' => $this->subject['platform'], 'customer_key' => '10', 'conversation_id' => '30',
            'source_timestamp_basis' => 'canonical_recording_unix_v1', 'source_created_at_unix' => $at,
            'payload' => ['direction' => 'inbound', 'sender_type' => 'customer']];
        $q = $this->db->prepare('INSERT INTO messages VALUES(?,30,?,?,?)');
        $q->execute([$message, $this->subject['platform'], 'inbound', 'customer']);
        $q = $this->db->prepare('INSERT INTO modeled_conversion_stream_versions VALUES(?,?,?,?,?,?,?)');
        $q->execute([$journal, 'anytour', 'message', $message, 1, $at + 5, json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    }
    public function save(): void
    {
        $q = $this->db->prepare('INSERT OR REPLACE INTO modeled_conversion_profile_versions VALUES(?,?,?,?,?,?,?,?)');
        $q->execute([1, 'anytour', $this->subject['platform'], 10, 30, $this->observed, str_repeat('c', 64),
            json_encode($this->profile, JSON_THROW_ON_ERROR)]);
    }
    public function reader(?callable $candidate = null, ?callable $adjust = null, ?callable $allowed = null): Max2ModeledValueReaderV2
    {
        $candidate ??= function(array $wrapper, array $subject, int $floor, int $now): array {
            $this->candidateCalls++;
            requireReader($subject === $this->subject && $floor === $this->floor && $now === $this->now, 'exact adapter inputs');
            return ['value' => 100, 'event_unix' => $this->floor + 500];
        };
        $adjust ??= function(array $wrapper, array $candidate): array {
            $this->adjustCalls++; return ['candidate' => $candidate, 'basis' => null];
        };
        $allowed ??= function(string $chat): bool { $this->allowedCalls[] = $chat; return $chat === '-12345'; };
        return new Max2ModeledValueReaderV2($this->db, $allowed, $candidate, $adjust, self::PRODUCER);
    }
    public function image(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll() as $row) {
            $name = $row['name']; $out[$name] = $this->db->query('SELECT * FROM ' . $name . ' ORDER BY rowid')->fetchAll();
        }
        return $out;
    }
    public function observe(?Max2ModeledValueReaderV2 $reader = null, ?string $fixed = null): array
    {
        $before = $this->image();
        $out = ($reader ?? $this->reader())->read('1', $this->floor, $this->now, $fixed);
        requireReader($before === $this->image(), 'all source/v1 rows remain byte-equivalent');
        requireReader(!$this->db->inTransaction(), 'reader closes only its own transaction');
        requireReader(($out['writes'] ?? null) === 0 && ($out['provider_calls'] ?? null) === 0, 'zero write/provider result');
        return $out;
    }
}
function requireReader(bool $yes, string $reason): void { if (!$yes) throw new RuntimeException($reason); }
function held(array $value, string $reason): void
{
    requireReader($value['status'] === 'HOLD' && $value['reason'] === $reason, 'expected ' . $reason . ', got ' . json_encode($value));
}
$tests = [];
$tests['exact_profile_full_value_without_old_state_changes'] = static function(): void {
    $f = new SavedValueReaderFixture(); $r = $f->observe();
    requireReader($r['status'] === 'ADMITTED_SAVED_VALUE' && $r['estimate']['value_rub'] === 100
        && $r['estimate']['profile_id'] === '1' && $r['estimate']['currency'] === 'RUB'
        && $r['inquiry']['journal_id'] === '100' && $r['inquiry']['native_message_id'] === '500'
        && $r['inquiry']['recorded_at_unix'] === $f->floor + 60, 'exact saved amount and anchor');
    requireReader($r['actor_context']['external_user_id'] === '12345'
        && !array_key_exists('authenticated_actor', $r['actor_context']) && $r['financial_delivery_permission'] === false,
        'saved channel identity is not actor authentication or delivery permission');
    requireReader($f->candidateCalls === 1 && $f->adjustCalls === 1 && $f->allowedCalls === ['-12345'], 'one admitted read chain');
};
$tests['adjusted_saved_value_and_cap_types'] = static function(): void {
    foreach ([0, 100000] as $amount) {
        $f = new SavedValueReaderFixture();
        $reader = $f->reader(null, static fn($w, $c): array => ['candidate' => array_replace($c, ['value' => $amount]), 'basis' => ['kind' => 'synthetic']]);
        requireReader($f->observe($reader)['estimate']['value_rub'] === $amount, 'bounded integer amount admitted');
    }
    foreach ([-1, 100001, '100', 100.0] as $amount) {
        $f = new SavedValueReaderFixture(); $reader = $f->reader(static fn(): array => ['value' => $amount, 'event_unix' => $f->floor + 500]);
        held($f->observe($reader), 'SAVED_VALUE_UNPROVED');
    }
};
$tests['prospective_floor_scope_profile_event_and_inquiry'] = static function(): void {
    $f = new SavedValueReaderFixture(); $reader = $f->reader(); $before = $f->image();
    held($reader->read('1', $f->floor - 1, $f->now), 'READ_SCOPE_UNPROVED');
    held($reader->read('01', $f->floor, $f->now), 'READ_SCOPE_UNPROVED');
    held($reader->read('1', $f->now + 1, $f->now), 'READ_SCOPE_UNPROVED');
    requireReader($f->image() === $before && $f->candidateCalls === 0, 'scope stop before candidate');
    $f = new SavedValueReaderFixture(); $f->db->exec('UPDATE modeled_conversion_profile_versions SET observed_at_unix=' . ($f->floor - 1));
    held($f->observe(), 'PROFILE_SCOPE_OR_BOUND');
    foreach ([$f->floor - 1, $f->observed + 1] as $event) {
        $f = new SavedValueReaderFixture();
        held($f->observe($f->reader(static fn(): array => ['value' => 100, 'event_unix' => $event])), 'SAVED_VALUE_UNPROVED');
    }
    $f = new SavedValueReaderFixture();
    $e = &$f->profile['profile']['observation_input']['evidence'][0];
    $e['at'] = SavedValueReaderFixture::time($f->floor - 1); $e['references'][0]['at'] = $e['at']; unset($e); $f->save();
    held($f->observe(), 'INQUIRY_ANCHOR_UNPROVED');
};
$tests['profile_missing_wrong_subject_producer_and_repair'] = static function(): void {
    $f = new SavedValueReaderFixture(); $f->db->exec('DELETE FROM modeled_conversion_profile_versions'); held($f->observe(), 'PROFILE_MISSING');
    foreach (['producer', 'repair', 'subject', 'denied'] as $mode) {
        $f = new SavedValueReaderFixture();
        if ($mode === 'producer') $f->profile['processing']['source_sha256'] = str_repeat('d', 64);
        if ($mode === 'repair') $f->profile['processing']['assessment_kind'] = 'repair';
        if ($mode === 'subject') $f->profile['subject']['conversation_id'] = '31';
        if ($mode === 'denied') $f->profile['access_status'] = 'denied';
        $f->save(); held($f->observe(), 'PROFILE_NOT_CURRENT_ADMITTED_SOURCE');
        requireReader($f->candidateCalls === 0 && $f->adjustCalls === 0, 'unadmitted saved producer never valued');
    }
};
$tests['large_profile_and_snapshot_stop_without_unbounded_payload'] = static function(): void {
    $f = new SavedValueReaderFixture(); $f->profile['padding'] = str_repeat('x', 131072); $f->save();
    held($f->observe(), 'PROFILE_SCOPE_OR_BOUND'); requireReader($f->candidateCalls === 0, 'oversize profile never decoded/valued');
    $f = new SavedValueReaderFixture();
    $q = $f->db->prepare('UPDATE modeled_conversion_stream_versions SET snapshot_json=?'); $q->execute([str_repeat('x', 65537)]);
    held($f->observe(), 'INQUIRY_NATIVE_SOURCE_UNPROVED');
};
$tests['denied_chat_and_channel_binding_mismatch'] = static function(): void {
    $f = new SavedValueReaderFixture(); held($f->observe($f->reader(null, null, static fn(): bool => false)), 'SUBJECT_NOT_ADMITTED');
    requireReader($f->candidateCalls === 0 && $f->adjustCalls === 0, 'denied before value callbacks');
    foreach (["UPDATE conversations SET is_test=1", "UPDATE customer_channels SET customer_id=11",
        "UPDATE customer_channels SET project_key='other'", "UPDATE conversations SET external_chat_id='12345'",
        "UPDATE customer_channels SET external_user_id='012345'", "UPDATE conversations SET customer_channel_id=99"] as $sql) {
        $f = new SavedValueReaderFixture(); $f->db->exec($sql); $r = $f->observe();
        requireReader($r['status'] === 'HOLD' && in_array($r['reason'], ['SUBJECT_NOT_ADMITTED','SUBJECT_BINDING_MISSING'], true), 'channel mismatch held');
    }
};
$tests['native_message_and_snapshot_mismatch'] = static function(): void {
    foreach (["DELETE FROM messages", "UPDATE messages SET conversation_id=31", "UPDATE messages SET direction='outbound'",
        "UPDATE messages SET sender_type='manager'", "UPDATE messages SET channel='telegram'",
        "UPDATE modeled_conversion_stream_versions SET source_id=501", "UPDATE modeled_conversion_stream_versions SET project_key='other'",
        "UPDATE modeled_conversion_stream_versions SET observed_at_unix=observed_at_unix+1"] as $sql) {
        $f = new SavedValueReaderFixture(); $f->db->exec($sql); held($f->observe(), 'INQUIRY_NATIVE_SOURCE_UNPROVED');
    }
    $f = new SavedValueReaderFixture(); $s = json_decode($f->db->query('SELECT snapshot_json FROM modeled_conversion_stream_versions')->fetchColumn(), true);
    $s['payload']['sender_type'] = 'manager'; $q = $f->db->prepare('UPDATE modeled_conversion_stream_versions SET snapshot_json=?'); $q->execute([json_encode($s)]);
    held($f->observe(), 'INQUIRY_NATIVE_SOURCE_UNPROVED');
};
$tests['revised_source_not_relabelled_original'] = static function(): void {
    $f = new SavedValueReaderFixture(); $f->profile['source_references'][0]['source_version_no'] = 2; $f->save();
    held($f->observe(), 'INQUIRY_ANCHOR_UNPROVED');
    $f = new SavedValueReaderFixture(); $f->db->exec('UPDATE modeled_conversion_stream_versions SET version_no=2');
    held($f->observe(), 'INQUIRY_NATIVE_SOURCE_UNPROVED');
};
$tests['ambiguous_ref_evidence_and_duplicate_events_hold'] = static function(): void {
    foreach (['evidence', 'source_ref', 'event'] as $duplicate) {
        $f = new SavedValueReaderFixture();
        if ($duplicate === 'evidence') $f->profile['profile']['observation_input']['evidence'][] = $f->profile['profile']['observation_input']['evidence'][0];
        if ($duplicate === 'source_ref') $f->profile['source_references'][] = $f->profile['source_references'][0];
        if ($duplicate === 'event') $f->profile['contextual_signals']['events'][] = $f->profile['contextual_signals']['events'][0];
        $f->save(); held($f->observe(), 'INQUIRY_ANCHOR_UNPROVED');
    }
};
$tests['fixed_inquiry_is_retained_and_never_falls_back'] = static function(): void {
    $f = new SavedValueReaderFixture(); $f->addInquiry('99', '499', $f->floor + 30); $f->save();
    requireReader($f->observe()['inquiry']['journal_id'] === '99', 'first observed in this profile');
    requireReader($f->observe(null, '100')['inquiry']['journal_id'] === '100', 'fixed own account anchor retained');
    held($f->observe(null, '101'), 'INQUIRY_ANCHOR_UNPROVED');
};
$tests['native_source_fence_and_cutoff_are_exact'] = static function(): void {
    $f = new SavedValueReaderFixture(); $f->profile['source_fence_journal_id'] = '99'; $f->save(); held($f->observe(), 'INQUIRY_ANCHOR_UNPROVED');
    $f = new SavedValueReaderFixture(); $f->profile['contextual_signals']['input_cutoff_at'] = SavedValueReaderFixture::time($f->floor + 61);
    $f->save(); held($f->observe(), 'INQUIRY_ANCHOR_UNPROVED');
};
$tests['non_tour_interest_cannot_anchor_a_tour_value'] = static function(): void {
    $f = new SavedValueReaderFixture(); $f->addInquiry('99', '499', $f->floor + 30, 'unrelated_product'); $f->save();
    requireReader($f->observe()['inquiry']['journal_id'] === '100', 'same tour/unknown product gate as admitted value candidate');
    held($f->observe(null, '99'), 'INQUIRY_ANCHOR_UNPROVED');
};
$tests['unavailable_value_and_internal_error_rollback_without_old_writes'] = static function(): void {
    $f = new SavedValueReaderFixture(); held($f->observe($f->reader(static fn() => null)), 'SAVED_VALUE_UNAVAILABLE');
    requireReader($f->adjustCalls === 0, 'no price adjust after unavailable candidate');
    $f = new SavedValueReaderFixture();
    $adjust = static function() use ($f): array {
        $f->db->exec('UPDATE max_channel_conversion_outbox SET value_rub=99999');
        throw new RuntimeException('PRIVATE_MODEL_DETAIL_MUST_NOT_LEAK');
    };
    $result = $f->observe($f->reader(null, $adjust)); held($result, 'SAVED_PROFILE_UNAVAILABLE');
    requireReader(!str_contains(json_encode($result), 'PRIVATE_MODEL_DETAIL'), 'sanitized failure');
    $f = new SavedValueReaderFixture();
    $adjust = static function($w, $c) use ($f): array {
        $f->db->exec('UPDATE modeled_conversion_value_export_settings SET paid_conversion_rub=999');
        return ['candidate' => $c, 'basis' => null];
    };
    requireReader($f->observe($f->reader(null, $adjust))['status'] === 'ADMITTED_SAVED_VALUE', 'even synthetic side effects rolled back');
};
$tests['foreign_outer_transaction_is_not_rolled_back'] = static function(): void {
    $f = new SavedValueReaderFixture(); $f->db->beginTransaction();
    try {
        $f->db->exec('UPDATE modeled_conversion_profiles_state SET cursor=999');
        held($f->reader()->read('1', $f->floor, $f->now), 'READ_SCOPE_UNPROVED');
        requireReader($f->db->inTransaction() && (int)$f->db->query('SELECT cursor FROM modeled_conversion_profiles_state')->fetchColumn() === 999,
            'reader leaves caller transaction untouched');
    } finally { $f->db->rollBack(); }
};
$tests['exact_telegram_subject_still_not_authenticated_proof'] = static function(): void {
    $f = new SavedValueReaderFixture(); $f->subject['platform'] = 'telegram';
    $f->profile['subject'] = $f->subject; $f->profile['profile']['observation_input']['subject'] = $f->subject;
    $f->profile['profile']['observation_input']['evidence'][0]['subject'] = $f->subject;
    $f->db->exec("UPDATE conversations SET channel='telegram',external_chat_id='12345'");
    $f->db->exec("UPDATE customer_channels SET channel='telegram',external_chat_id='12345'");
    $f->db->exec("UPDATE messages SET channel='telegram'");
    $s = json_decode($f->db->query('SELECT snapshot_json FROM modeled_conversion_stream_versions')->fetchColumn(), true); $s['platform'] = 'telegram';
    $q = $f->db->prepare('UPDATE modeled_conversion_stream_versions SET snapshot_json=?'); $q->execute([json_encode($s)]); $f->save();
    $r = $f->observe($f->reader(null, null, static fn($chat): bool => $chat === '12345'));
    requireReader($r['status'] === 'ADMITTED_SAVED_VALUE' && $r['subject']['platform'] === 'telegram'
        && !array_key_exists('authenticated_actor', $r['actor_context']), 'identity reader does not create MAX actor proof');
};
$passed = 0;
foreach ($tests as $name => $test) {
    try { $test(); $passed++; }
    catch (Throwable $error) { fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . "\n"); exit(1); }
}
echo json_encode(['status' => 'PASS', 'tests' => $passed, 'database_kind' => 'sqlite_memory_only',
    'native_default_model_executed' => false, 'native_price_adjust_executed' => false,
    'live_database_connections' => 0, 'provider_calls' => 0, 'existing_sources_modified' => false], JSON_THROW_ON_ERROR) . "\n";
