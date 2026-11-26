<?php
declare(strict_types=1);

/** Actual admitted app classes + new readers; only synthetic capsules and in-memory SQLite. */
if (PHP_SAPI !== 'cli') exit(1);
function defaultCheck(bool $yes, string $why): void { if (!$yes) throw new RuntimeException($why); }
function defaultJson(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
function defaultServices(string $directory): array
{
    $hashes = [];
    foreach (new DirectoryIterator($directory) as $file) {
        if ($file->isDot() || !str_ends_with($file->getFilename(), '.php')) continue;
        defaultCheck($file->isFile() && !$file->isLink(), 'canonical service must be a regular checkout source');
        $hashes[$file->getFilename()] = hash_file('sha256', $file->getPathname());
    }
    ksort($hashes); return $hashes;
}
function defaultRemove(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $item) if (!$item->isDot()) defaultRemove($item->getPathname());
    rmdir($path);
}
$app = dirname(__DIR__, 4);
$source = dirname(__DIR__, 2) . '/source/services';
$reference = dirname(__DIR__) . '/reference/canonical-price-fixtures.php';
$canonicalFixture = $app . '/tests/run_modeled_conversion_value_export_regression.php';
$raw = file_get_contents($canonicalFixture);
defaultCheck(is_string($raw) && hash('sha1', 'blob ' . strlen($raw) . "\0" . $raw) === 'e0d2616b275e5ecc331657cc4b47465c19ed11fa', 'exact canonical fixture blob required');
$first = strpos($raw, 'function modeledExpertWrapper('); $last = strpos($raw, 'function modeledValuePredictionAssertions(');
defaultCheck(is_int($first) && is_int($last) && $last > $first, 'canonical pure function boundaries');
$excerpt = "<?php\ndeclare(strict_types=1);\n\n" . rtrim(substr($raw, $first, $last - $first)) . "\n";
defaultCheck(file_get_contents($reference) === $excerpt, 'verbatim two-function canonical excerpt required');
$beforeServices = defaultServices($app . '/services');
defaultCheck(($beforeServices['ModeledConversionPriceContinuity.php'] ?? null) === '18f1101b3822bb02fd62b025c341a6fc5a298a1861fff04d8d668823848accbf', 'accepted original price source required');
$temp = sys_get_temp_dir() . '/max2-v2-default-parity-' . bin2hex(random_bytes(12));
defaultCheck(mkdir($temp, 0700) && mkdir($temp . '/services', 0700) && mkdir($temp . '/tests', 0700), 'private temporary assembly');
register_shutdown_function(static function() use ($temp): void { defaultRemove($temp); });
// Symlink views execute the exact checkout source with its original __DIR__ and dependency paths.
// New classes and the two pure fixture functions are copied only into the temporary tree.
foreach ($beforeServices as $name => $hash) defaultCheck(symlink($app . '/services/' . $name, $temp . '/services/' . $name), 'source view');
foreach (['Max2ModeledValueReaderV2.php', 'Max2CommonValuePriceReadV2.php'] as $name) {
    if (is_link($temp . '/services/' . $name)) unlink($temp . '/services/' . $name);
    defaultCheck(copy($source . '/' . $name, $temp . '/services/' . $name)
        && hash_file('sha256', $source . '/' . $name) === hash_file('sha256', $temp . '/services/' . $name), 'exact new source assembly');
}
defaultCheck(copy($reference, $temp . '/tests/canonical-price-fixtures.php'), 'fixture-only assembly');
require_once $temp . '/services/ModeledConversionValueContract.php';
require_once $temp . '/services/ModeledConversionPriceContinuity.php';
require_once $temp . '/services/Max2ModeledValueReaderV2.php';
require_once $temp . '/services/Max2CommonValuePriceReadV2.php';
require_once $temp . '/tests/canonical-price-fixtures.php';

final class DefaultParityPDO extends PDO
{
    public bool $readOnlyAssertions = false;
    public int $sourceReads = 0;
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        foreach ($this->query('PRAGMA database_list')->fetchAll() as $row) defaultCheck($row['file'] === '', 'memory-only fixture database');
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->readOnlyAssertions) { defaultCheck(str_starts_with($query, 'SELECT '), 'default path attempted non-SELECT'); $this->sourceReads++; }
        return parent::prepare($query, $options);
    }
    public function exec(string $statement): int|false
    {
        if ($this->readOnlyAssertions) throw new RuntimeException('default path attempted exec');
        return parent::exec($statement);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($this->readOnlyAssertions) defaultCheck(str_starts_with($query, 'SELECT '), 'default path attempted non-SELECT query');
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}
function parityDb(): DefaultParityPDO
{
    $db = new DefaultParityPDO();
    foreach ([
        'CREATE TABLE modeled_conversion_profile_versions(id INTEGER PRIMARY KEY,project_key TEXT,platform TEXT,customer_key INTEGER,conversation_id INTEGER,observed_at_unix INTEGER,input_sha256 TEXT,profile_json TEXT)',
        'CREATE TABLE max_channel_conversion_outbox(id INTEGER PRIMARY KEY,project_key TEXT,counter_id TEXT,transport_target TEXT,value_contract TEXT,conversation_id INTEGER,platform TEXT,value_rub INTEGER,value_profile_id INTEGER,state TEXT)',
        'CREATE TABLE modeled_conversion_value_export_settings(project_key TEXT PRIMARY KEY,goal_id TEXT,paid_conversion_rub INTEGER,mode TEXT)',
        'CREATE TABLE modeled_conversion_price_audit(outbox_id INTEGER PRIMARY KEY,action TEXT)',
        'CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,channel TEXT,external_chat_id TEXT,is_test INTEGER)',
        'CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT)',
        'CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,channel TEXT,direction TEXT,sender_type TEXT)',
        'CREATE TABLE modeled_conversion_stream_versions(id INTEGER PRIMARY KEY,project_key TEXT,source_kind TEXT,source_id INTEGER,version_no INTEGER,observed_at_unix INTEGER,snapshot_json TEXT)'] as $sql) $db->exec($sql);
    $db->exec("INSERT INTO modeled_conversion_value_export_settings VALUES('anytour','678187097',300,'send')");
    $db->exec("INSERT INTO modeled_conversion_price_audit VALUES(1,'unchanged')");
    $db->exec("INSERT INTO customer_channels VALUES(20,10,'anytour','max','12345','-12345')");
    $db->exec("INSERT INTO conversations VALUES(30,10,20,'anytour','max','-12345',0)");
    return $db;
}
function parityImage(PDO $db): array
{
    $out = [];
    foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll() as $row) {
        $out[$row['name']] = $db->query('SELECT * FROM ' . $row['name'] . ' ORDER BY rowid')->fetchAll();
    }
    return $out;
}
function parityWrapper(array $w): array
{
    // These are ordinary immutable envelope fields added by the real profile producer.
    // Do not alter the canonical semantic or deal capsules and their digests.
    $w['processing']['assessment_kind'] = 'live';
    $w['contextual_signals']['extractor_version'] = ArchiveResearchSemanticExtractor::VERSION;
    $w['contextual_signals']['prediction_at'] = $w['profile']['prediction_at'];
    $w['profile']['observation_input']['prediction_at'] = $w['profile']['prediction_at'];
    $w['source_fence_journal_id'] = '1000'; $w['source_references'] = [];
    foreach ($w['profile']['observation_input']['evidence'] as $e) {
        $ref = $e['references'][0]['id']; defaultCheck(preg_match('/\Ajournal:([1-9][0-9]*)\z/D', $ref, $m) === 1, 'canonical source ref');
        $w['source_references'][] = ['journal_id' => $m[1], 'source_kind' => 'message', 'source_id' => $m[1],
            'source_version_no' => 1, 'source_status' => 'present', 'observed_at' => $e['available_at']];
    }
    return $w;
}
function paritySave(PDO $db, string $id, array $w, int $observed): void
{
    $raw = defaultJson($w); $s = $w['subject'];
    $q = $db->prepare('INSERT INTO modeled_conversion_profile_versions VALUES(?,?,?,?,?,?,?,?)');
    $q->execute([$id, $s['project_key'], $s['platform'], $s['customer_key'], $s['conversation_id'], $observed, hash('sha256', $raw), $raw]);
}
function parityPrior(PDO $db, string $profile, int $outbox = 1): void
{
    $q = $db->prepare('INSERT INTO max_channel_conversion_outbox VALUES(?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$outbox, 'anytour', '98615635', ModeledConversionValueContract::TARGET, ModeledConversionValueContract::VERSION,
        30, 'max', 583, $profile, 'accepted']);
}
function parityNative(PDO $db, array $w): void
{
    foreach ($w['profile']['observation_input']['evidence'] as $e) {
        $id = substr($e['references'][0]['id'], 8); $role = $e['kind'] === 'customer_message' ? 'customer' : 'manager';
        $direction = $role === 'customer' ? 'inbound' : 'outbound';
        $db->prepare('INSERT INTO messages VALUES(?,30,?,?,?)')->execute([$id, 'max', $direction, $role]);
        $snapshot = $w['subject'] + ['source_kind' => 'message', 'source_id' => $id, 'source_status' => 'present',
            'source_timestamp_basis' => 'canonical_recording_unix_v1', 'source_created_at_unix' => strtotime($e['at']),
            'payload' => ['direction' => $direction, 'sender_type' => $role]];
        $db->prepare('INSERT INTO modeled_conversion_stream_versions VALUES(?,?,?,?,?,?,?)')->execute([
            $id, 'anytour', 'message', $id, 1, strtotime($e['available_at']), defaultJson($snapshot)]);
    }
}
$floor = 1795262676; $frozen = $floor + 500; $now = $floor + 900;
$subject = ['project_key' => 'anytour', 'platform' => 'max', 'customer_key' => '10', 'conversation_id' => '30'];
$known = parityWrapper(modeledExpertWrapper($subject, $frozen, 100000));
$unknown = parityWrapper(modeledUnknownPriceWrapper(modeledExpertWrapper($subject, $frozen, 100000)));
$fallback = parityWrapper(modeledUnknownPriceWrapper(modeledExpertWrapper($subject, $frozen, 100000), true));
$stronger = parityWrapper(modeledUnknownPriceWrapper(modeledExpertWrapper($subject, $frozen, 100000, true)));
$checks = 0;
foreach (['known' => $known, 'unpriced_probability' => $unknown, 'unpriced_stage' => $fallback, 'stronger_probability' => $stronger] as $case => $current) {
    $db = parityDb(); paritySave($db, '1', $known, $frozen); parityPrior($db, '1'); paritySave($db, '2', $current, $frozen + 10);
    $before = parityImage($db); $db->readOnlyAssertions = true;
    $candidate = ModeledConversionValueContract::candidate($current, $subject, $floor, $now);
    defaultCheck(is_array($candidate) && is_int($candidate['value']) && !empty($candidate['prediction_value']), 'actual saved candidate required: ' . $case);
    $old = (new ModeledConversionPriceContinuity($db))->adjust($current, $candidate);
    $new = (new Max2CommonValuePriceReadV2($db))->adjust($current, $candidate, '2');
    defaultCheck($old['candidate'] === $new['candidate'], 'old/new monetary candidate parity: ' . $case);
    if ($old['basis'] !== null) {
        $a = $old['basis']; $b = $new['basis']; unset($a['version'], $b['version'], $b['basis_source_sha256']);
        defaultCheck($a === $b, 'old/new retained-price basis parity: ' . $case);
    } else defaultCheck($new['basis'] === null, 'known-price shortcut parity');
    defaultCheck(parityImage($db) === $before, 'pure old/new adjust preserved all old records'); $checks++;
}
// Real new reader constructor/default methods; no injected candidate, adjust or producer hash.
$db = parityDb(); paritySave($db, '1', $known, $frozen); parityPrior($db, '1'); paritySave($db, '2', $unknown, $frozen + 10); parityNative($db, $unknown);
$expected = (new Max2CommonValuePriceReadV2($db))->adjust($unknown,
    ModeledConversionValueContract::candidate($unknown, $subject, $floor, $now), '2')['candidate']['value'];
$before = parityImage($db); $db->readOnlyAssertions = true;
$result = (new Max2ModeledValueReaderV2($db, static fn($chat): bool => $chat === '-12345'))->read('2', $floor, $now);
defaultCheck($result['status'] === 'ADMITTED_SAVED_VALUE' && $result['estimate']['value_rub'] === $expected
    && $result['estimate']['profile_observed_at_unix'] === $frozen + 10 && $result['inquiry']['journal_id'] === '101'
    && $result['writes'] === 0 && $result['provider_calls'] === 0 && $result['financial_delivery_permission'] === false,
    'actual default saved reader did not admit the exact canonical capsule');
defaultCheck($db->sourceReads >= 4 && !$db->inTransaction() && parityImage($db) === $before, 'actual default used read-only source path and rollback'); $checks++;
// Future stored price is intentionally excluded by V2; the frozen old rule remains unchanged.
$db = parityDb(); paritySave($db, '1', $known, $frozen); parityPrior($db, '1'); paritySave($db, '2', $unknown, $frozen + 10);
$candidate = ModeledConversionValueContract::candidate($unknown, $subject, $floor, $now);
$baseline = (new Max2CommonValuePriceReadV2($db))->adjust($unknown, $candidate, '2');
$future = parityWrapper(modeledExpertWrapper($subject, $frozen, 70000)); paritySave($db, '3', $future, $frozen + 20); parityPrior($db, '3', 2);
$before = parityImage($db); $db->readOnlyAssertions = true;
defaultCheck((new Max2CommonValuePriceReadV2($db))->adjust($unknown, $candidate, '2') === $baseline, 'later stored price cannot affect earlier current profile');
defaultCheck(parityImage($db) === $before, 'future exclusion is not a mutation'); $checks++;
// Oversized selected prior stays an explicit failure, never an unpriced higher fallback.
$db = parityDb(); $oversize = $known; $oversize['synthetic_padding'] = str_repeat('x', 131072);
paritySave($db, '1', $oversize, $frozen); parityPrior($db, '1'); paritySave($db, '2', $unknown, $frozen + 10); parityNative($db, $unknown);
$before = parityImage($db); $db->readOnlyAssertions = true;
$result = (new Max2ModeledValueReaderV2($db, static fn($chat): bool => $chat === '-12345'))->read('2', $floor, $now);
defaultCheck($result['status'] === 'HOLD' && $result['reason'] === 'SAVED_PROFILE_UNAVAILABLE'
    && !$db->inTransaction() && parityImage($db) === $before, 'oversize prior blocks default reader without rewriting anything'); $checks++;
// Alter only the temporary source view, never the actual old file.
$oldView = $temp . '/services/ModeledConversionPriceContinuity.php'; unlink($oldView); file_put_contents($oldView, "<?php // synthetic changed source\n");
clearstatcache(true, $oldView);
$rejected = false;
try { (new Max2CommonValuePriceReadV2(parityDb()))->adjust($unknown, $candidate, '2'); }
catch (RuntimeException $error) { $rejected = $error->getMessage() === 'V2_PRICE_RULE_SOURCE_UNPROVED'; }
defaultCheck($rejected, 'changed accepted old source hash stops the derived adapter'); $checks++;
defaultCheck(defaultServices($app . '/services') === $beforeServices, 'every original application service source unchanged');
defaultRemove($temp);
echo json_encode(['status' => 'PASS', 'checks' => $checks, 'actual_default_candidate_executed' => true,
    'actual_new_reader_defaults_executed' => true, 'actual_old_adjust_executed' => true, 'actual_new_price_read_executed' => true,
    'source_services_unchanged' => count($beforeServices), 'model_ticks' => 0, 'old_sender_calls' => 0,
    'database_kind' => 'sqlite_memory_only', 'live_database_connections' => 0, 'provider_calls' => 0], JSON_THROW_ON_ERROR) . "\n";
