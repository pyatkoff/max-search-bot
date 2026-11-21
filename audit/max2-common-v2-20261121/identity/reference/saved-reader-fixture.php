<?php
declare(strict_types=1);

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
