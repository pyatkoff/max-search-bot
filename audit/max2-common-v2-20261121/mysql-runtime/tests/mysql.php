<?php
declare(strict_types=1);

// Fixed loopback-only disposable CI service. No app bootstrap, provider or key.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('MAX2_V2_MYSQL_RUNTIME_FIXTURE') !== 'ephemeral-ci-only') throw new RuntimeException('EPHEMERAL_CI_ONLY');
if (!in_array('mysql', PDO::getAvailableDrivers(), true) || !function_exists('pcntl_fork'))
    throw new RuntimeException('MYSQL_PDO_PCNTL_REQUIRED');
$root = dirname(__DIR__, 2);
$dependencyHashes = [
    'worker/migrations/113_max2_common_runtime_v2.sql'=>'092c123615aae8261c19b8aa7316b0631926698906491c03869f28373646ada9',
    'worker/services/Max2CommonValueStateV2.php'=>'a0569ed15dde28d52ce01fdd19e52e2cd6e44b03f93e0c7d3e7924a3ac8cf7c2',
    'policy/services/Max2ValueBindingPolicyV2.php'=>'1c3feae48181559a601d595e37a0f01bb42c55371669346ed38bcb5c25bc7db9',
    'binding/services/Max2CommonFrameV2.php'=>'67153e1a1fee15b941263ff5d7d243a772acdd8f32fa3f8da94eea01ece540e8',
    'binding/services/Max2CommonEntryEvidenceV2.php'=>'db02e55fd3cf074a6dbc34b5889364b3e6e68a20ff4bcea08221f78f7332dd60',
    'binding/services/Max2CommonEntryCaptureV2.php'=>'7a60c3b51b1c120bfe7ad66bf59149248786195e92fa791537c871481145738a',
    'binding/services/Max2CommonMessageCaptureV2.php'=>'561a53c38254f45ad7548d55b7ec23a630eb6e54af98c336578cf8eb62570828',
    'binding/tests/support.php'=>'d87b55797cf48d987a1e2ac97de8f6a2a735540d09e58a58530b33f04d5c0678',
    'map/canonical-map-v1055.json'=>'c5ee090912b5e20402a46b79294137ef3045a02b1dd37abf4a1f9cb5fae50172',
];
foreach ($dependencyHashes as $relative=>$wanted) {
    $path = $root.'/'.$relative;
    if (!is_file($path) || is_link($path) || hash_file('sha256',$path) !== $wanted) throw new RuntimeException('EXACT_RUNTIME_DEPENDENCY_REQUIRED');
}
require_once $root.'/binding/tests/support.php';
require_once $root.'/worker/services/Max2CommonValueStateV2.php';
$migration = file_get_contents($root.'/worker/migrations/113_max2_common_runtime_v2.sql');
$checks = 0;
function runtimeMysqlCheck(bool $ok, string $label): void
{
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException('ASSERTION_FAILED_'.$label);
}
class V2RuntimeMysqlPdo extends PDO
{
    public bool $loseAfterCommit = false;
    public function __construct()
    {
        parent::__construct('mysql:host=127.0.0.1;port=33307;dbname=max2_v2_runtime_ci;charset=utf8mb4',
            'max2_v2_runtime_ci','max2-v2-runtime-synthetic-only',[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false, PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false]);
        $meta = $this->query('SELECT DATABASE() AS db,CURRENT_USER() AS actor,VERSION() AS version')->fetch();
        if ($meta['db'] !== 'max2_v2_runtime_ci' || !str_starts_with($meta['actor'],'max2_v2_runtime_ci@')
            || !str_starts_with($meta['version'],'8.0.')) throw new RuntimeException('DISPOSABLE_MYSQL80_SCOPE_REQUIRED');
        $this->exec('SET SESSION innodb_lock_wait_timeout=3');
        $this->exec("SET SESSION time_zone='+00:00'");
    }
    public function commit(): bool
    {
        $ok = parent::commit();
        if ($this->loseAfterCommit) { $this->loseAfterCommit = false; throw new PDOException('synthetic lost commit acknowledgement'); }
        return $ok;
    }
}
function runtimeMysqlTables(PDO $db): array
{
    $names = $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
    sort($names); return $names;
}
function runtimeMysqlMarker(PDO $db): void
{
    runtimeMysqlCheck($db->query('SELECT purpose FROM max2_v2_runtime_ci_marker WHERE id=1')->fetchColumn() === 'ephemeral-ci-only','disposable_marker');
    $allowed = ['max2_v2_runtime_ci_marker','max2_common_settings_v2','max2_common_ticks_v2','max2_common_profile_work_v2',
        'max2_common_lookup_intents_v2','max2_common_entry_frames_v2','modeled_conversion_profile_versions','max_channel_conversion_outbox',
        'projects','post_channels','post_publications','post_versions','customer_channels','conversations','post_entries','messages'];
    foreach (runtimeMysqlTables($db) as $table) runtimeMysqlCheck(in_array($table,$allowed,true),'no_foreign_tables');
}
function runtimeMysqlFresh(): V2RuntimeMysqlPdo
{
    global $migration;
    $db = new V2RuntimeMysqlPdo(); runtimeMysqlMarker($db);
    // The initial empty-database guard and marker above cover every fixed name.
    foreach (['max2_common_profile_work_v2','max2_common_lookup_intents_v2','max2_common_ticks_v2',
        'max2_common_entry_frames_v2','max2_common_settings_v2','modeled_conversion_profile_versions','max_channel_conversion_outbox',
        'messages','post_entries','conversations','customer_channels','post_versions','post_publications','post_channels','projects'] as $name)
        $db->exec('DROP TABLE IF EXISTS '.$name);
    $statements = array_values(array_filter(array_map('trim',explode(';',$migration)),static fn(string $s): bool => $s !== ''));
    runtimeMysqlCheck(count($statements) === 6,'exact_six_113_statements');
    foreach ($statements as $statement) $db->exec($statement); // Exact original MySQL SQL.
    $db->exec('CREATE TABLE modeled_conversion_profile_versions (id BIGINT UNSIGNED PRIMARY KEY,project_key VARCHAR(32) NOT NULL,platform VARCHAR(16) NOT NULL) ENGINE=InnoDB');
    $db->exec("INSERT INTO modeled_conversion_profile_versions VALUES(10,'anytour','max'),(20,'anytour','telegram'),(999,'other','max')");
    $db->exec('CREATE TABLE max_channel_conversion_outbox (id BIGINT PRIMARY KEY,value_rub INT NOT NULL,target VARCHAR(64) NOT NULL) ENGINE=InnoDB');
    $db->exec("INSERT INTO max_channel_conversion_outbox VALUES(1,300,'anytour_modeled_value_v1')");
    // Synthetic native columns needed by actual capture SELECTs. No production
    // table is connected. Source 001/033 identities are retained as native types.
    $native = [
        'projects (id BIGINT UNSIGNED PRIMARY KEY,project_key VARCHAR(32) NOT NULL)',
        'post_channels (id BIGINT UNSIGNED PRIMARY KEY,project_id BIGINT UNSIGNED NOT NULL,platform VARCHAR(16) NOT NULL,bot_key VARCHAR(191) NOT NULL,canonical_bot_username VARCHAR(191) NOT NULL,channel_id VARCHAR(64) NOT NULL)',
        'post_publications (id BIGINT UNSIGNED PRIMARY KEY,post_channel_id BIGINT UNSIGNED NOT NULL)',
        'post_versions (id BIGINT UNSIGNED PRIMARY KEY,publication_id BIGINT UNSIGNED NOT NULL,snapshot_json MEDIUMTEXT NOT NULL)',
        'customer_channels (id BIGINT UNSIGNED PRIMARY KEY,customer_id BIGINT UNSIGNED NOT NULL,project_key VARCHAR(32) NOT NULL,channel VARCHAR(16) NOT NULL,external_user_id VARCHAR(191) NOT NULL,external_chat_id VARCHAR(191) NOT NULL)',
        'conversations (id BIGINT UNSIGNED PRIMARY KEY,project_key VARCHAR(32) NOT NULL,channel VARCHAR(16) NOT NULL,customer_id BIGINT UNSIGNED NOT NULL,customer_channel_id BIGINT UNSIGNED NOT NULL,external_chat_id VARCHAR(191) NOT NULL,is_test TINYINT NOT NULL)',
        "post_entries (id BIGINT UNSIGNED PRIMARY KEY,conversation_id BIGINT UNSIGNED NOT NULL,publication_id BIGINT UNSIGNED NULL,version_id BIGINT UNSIGNED NULL,entry_key CHAR(64) NOT NULL,availability_reason VARCHAR(32) NOT NULL DEFAULT '',created_at DATETIME NOT NULL,UNIQUE KEY uq_ci_post_entry(conversation_id,entry_key))",
        'messages (id BIGINT UNSIGNED PRIMARY KEY,conversation_id BIGINT UNSIGNED NOT NULL,channel VARCHAR(16) NOT NULL,direction VARCHAR(16) NOT NULL,sender_type VARCHAR(16) NOT NULL,sender_id VARCHAR(191) NULL,external_message_id VARCHAR(191) NULL,metadata_json JSON NULL,created_at DATETIME NOT NULL)',
    ];
    foreach ($native as $definition) $db->exec('CREATE TABLE '.$definition.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    return $db;
}
function runtimeMysqlOld(PDO $db): void
{
    $rows = $db->query('SELECT * FROM max_channel_conversion_outbox')->fetchAll();
    runtimeMysqlCheck(count($rows) === 1 && (int)$rows[0]['value_rub'] === 300 && $rows[0]['target'] === 'anytour_modeled_value_v1','v1_synthetic_sentinel_unchanged');
}
function runtimeMysqlReject(PDO $db, string $sql, array $parameters, string $label, ?int $errorCode = null): void
{
    $caught = null;
    try { $q = $db->prepare($sql); $q->execute($parameters); }
    catch (PDOException $e) { $caught = $e; }
    runtimeMysqlCheck($caught !== null && ($errorCode === null || (int)($caught->errorInfo[1] ?? 0) === $errorCode),$label);
}
function runtimeMysqlActivate(V2RuntimeMysqlPdo $db, int &$clock): Max2CommonValueStateV2
{
    $clock = 1795263000;
    $state = new Max2CommonValueStateV2($db,static function() use (&$clock): int { return $clock; });
    $made = $state->activate(sha1('synthetic source'),hash('sha256','synthetic activation; not live release permission'));
    runtimeMysqlCheck($made['status'] === 'ACTIVATED','synthetic_native_activation');
    $clock = 1795263660;
    return $state;
}
function runtimeMysqlSummary(): array
{
    return array_fill_keys(['profiles_read','reserved','evaluated','held','unknown','lookup_list','lookup_recheck','goal_reads','send_attempts','provider_polls'],0);
}

$db = new V2RuntimeMysqlPdo();
runtimeMysqlCheck(runtimeMysqlTables($db) === [],'dedicated_database_initially_empty');
$serverVersion = $db->query('SELECT VERSION()')->fetchColumn();
$db->exec('CREATE TABLE max2_v2_runtime_ci_marker(id INT PRIMARY KEY,purpose VARCHAR(32) NOT NULL) ENGINE=InnoDB');
$db->exec("INSERT INTO max2_v2_runtime_ci_marker VALUES(1,'ephemeral-ci-only')");
unset($db);

$db = runtimeMysqlFresh(); $clock = 1795263000;
$state = new Max2CommonValueStateV2($db,static function() use (&$clock): int { return $clock; });
$engines = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'max2_common_%'")->fetchAll(PDO::FETCH_COLUMN);
runtimeMysqlCheck(count($engines) === 5 && array_filter($engines,static fn(string $e): bool => $e !== 'InnoDB') === [],'five_actual_innodb_tables');
$enforced = $db->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'max2_common_%' AND CONSTRAINT_TYPE='CHECK' AND ENFORCED='YES'")->fetchColumn();
runtimeMysqlCheck((int)$enforced === 13,'thirteen_actual_checks_enforced');
$foreign = $db->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'max2_common_%'")->fetchColumn();
runtimeMysqlCheck((int)$foreign === 2,'two_actual_tick_foreign_keys');
runtimeMysqlCheck($state->settings()['mode'] === 'off' && $state->settings()['activation'] === null
    && $state->beginTick($clock)['status'] === 'OFF','migration_defaults_off_no_activation');
runtimeMysqlCheck($state->setMode('send',hash('sha256','mode request'))['status'] === 'HOLD','send_cannot_precede_activation');
$source = sha1('synthetic source'); $request = hash('sha256','synthetic activation; not live release permission');
$made = $state->activate($source,$request); $epoch = $made['settings']['activation']['activation_sha256'];
runtimeMysqlCheck($made['status'] === 'ACTIVATED' && $made['settings']['cursor'] === '20'
    && $made['settings']['activation']['activated_at_unix'] === $clock,'activation_reads_actual_global_anytour_max_including_telegram');
$db->exec("INSERT INTO modeled_conversion_profile_versions VALUES(107,'anytour','max'),(109,'anytour','telegram')");
$clock = 1795263660;
runtimeMysqlCheck($state->activate($source,$request)['status'] === 'ALREADY_ACTIVATED' && $state->settings()['cursor'] === '20','repeat_activation_does_not_rebase_history_floor');
runtimeMysqlCheck($state->activate($source,hash('sha256','other operation'))['status'] === 'HOLD','activation_provenance_immutable');
runtimeMysqlCheck($state->setMode('send',hash('sha256','explicit synthetic mode'))['settings']['activation']['activation_sha256'] === $epoch,'mode_change_preserves_activation_epoch');
$tick = $state->beginTick($clock);
runtimeMysqlCheck($tick['status'] === 'ACQUIRED' && $state->beginTick($clock)['status'] === 'BUSY','native_tick_lease');
runtimeMysqlCheck($state->setMode('off',hash('sha256','synthetic off'))['status'] === 'HOLD','active_tick_mode_immutable');
$requestHash = hash('sha256','synthetic exact signed request');
$intent = $state->consumeLookupIntent($tick['tick_key'],'107','list',$requestHash,$clock);
runtimeMysqlCheck($intent['status'] === 'CONSUMED' && $intent['call_permitted'] && !$db->inTransaction(),'native_intent_committed_before_call_permission');
runtimeMysqlCheck($state->consumeLookupIntent($tick['tick_key'],'107','list',hash('sha256','new nonce'),$clock)['status'] === 'ALREADY_CONSUMED','native_new_nonce_does_not_repeat_action');
runtimeMysqlCheck($state->finishLookupIntent($tick['tick_key'],'107','list',$requestHash,null,'unknown',$clock)['status'] === 'RECORDED','native_unknown_lookup_retained');
runtimeMysqlCheck($state->consumeLookupIntent($tick['tick_key'],'107','list',$requestHash,$clock)['call_permitted'] === false,'native_unknown_lookup_no_retry');
runtimeMysqlCheck($state->advanceProfile($tick['tick_key'],'107',['status'=>'HOLD','reason'=>'SYNTHETIC_HOLD'],$clock)['status'] === 'ADVANCED'
    && $state->settings()['cursor'] === '107','native_diagnostic_and_cursor_advance');
runtimeMysqlReject($db,'DELETE FROM max2_common_ticks_v2 WHERE tick_key=?',[$tick['tick_key']],'native_tick_fk_restrict',1451);
$summary = runtimeMysqlSummary(); $summary['held'] = 1; $summary['lookup_list'] = 1;
runtimeMysqlCheck($state->finishTick($tick['tick_key'],$summary,$clock)['status'] === 'COMPLETED'
    && $state->settings()['active_tick_key'] === null,'native_tick_summary_and_release');
$storedSummary = $db->query('SELECT last_tick_summary_json FROM max2_common_settings_v2')->fetchColumn();
runtimeMysqlCheck(json_decode($storedSummary,true,16,JSON_THROW_ON_ERROR) == $summary,'native_summary_json_reconstruction');
runtimeMysqlReject($db,'UPDATE max2_common_settings_v2 SET goal_id=678187097',[],'old_goal_cannot_enter_settings',3819);
$next = $state->beginTick($clock); $clock += 181; $replaced = $state->beginTick($clock);
runtimeMysqlCheck($replaced['status'] === 'ACQUIRED' && $replaced['tick_key'] !== $next['tick_key'],'native_expired_tick_replacement');
$q = $db->prepare('SELECT state FROM max2_common_ticks_v2 WHERE tick_key=?'); $q->execute([$next['tick_key']]);
runtimeMysqlCheck($q->fetchColumn() === 'unknown' && $state->advanceProfile($next['tick_key'],'109',['status'=>'HOLD'],$clock)['status'] === 'HOLD','native_stale_worker_cannot_advance');
$q->closeCursor(); unset($q);
runtimeMysqlOld($db); unset($state,$db);

// Force the SECOND write of advanceProfile to fail, after inserting its outcome.
$db = runtimeMysqlFresh(); $state = runtimeMysqlActivate($db,$clock); $tick = $state->beginTick($clock);
$db->exec("ALTER TABLE max2_common_settings_v2 ADD CONSTRAINT ck_ci_cursor_fail CHECK (profile_cursor<>'107')");
$out = $state->advanceProfile($tick['tick_key'],'107',['status'=>'HOLD'],$clock);
runtimeMysqlCheck($out['status'] === 'HOLD' && $state->settings()['cursor'] === '20'
    && (int)$db->query('SELECT COUNT(*) FROM max2_common_profile_work_v2')->fetchColumn() === 0,'native_outcome_and_cursor_atomic_rollback');
runtimeMysqlOld($db); unset($state,$db);

// Commit succeeded in the engine, but caller acknowledgement is lost.
$db = runtimeMysqlFresh(); $clock = 1795263000;
$state = new Max2CommonValueStateV2($db,static function() use (&$clock): int { return $clock; });
$db->loseAfterCommit = true; $out = $state->activate($source,$request);
runtimeMysqlCheck($out['status'] === 'UNKNOWN' && !$out['call_permitted'] && !$out['automatic_retry']
    && $state->settings()['activation'] !== null,'native_activation_commit_unknown_no_permission');
$clock = 1795263660; $tick = $state->beginTick($clock); $db->loseAfterCommit = true;
$out = $state->consumeLookupIntent($tick['tick_key'],'107','list',$requestHash,$clock);
runtimeMysqlCheck($out['status'] === 'UNKNOWN' && !$out['call_permitted'] && !$out['automatic_retry'],'native_lookup_commit_unknown_no_call');
runtimeMysqlCheck($state->consumeLookupIntent($tick['tick_key'],'107','list',hash('sha256','replacement nonce'),$clock)['status'] === 'ALREADY_CONSUMED'
    && (int)$db->query('SELECT COUNT(*) FROM max2_common_lookup_intents_v2')->fetchColumn() === 1,'native_unknown_intent_durable_and_not_reissued');
runtimeMysqlOld($db); unset($state,$db);

// Actual new capture services, actual 1055 map, synthetic native rows. Reflection
// reaches the private fixture seam only; successful real HTTP auth is NOT tested.
$db = runtimeMysqlFresh(); $state = runtimeMysqlActivate($db,$clock); $f = v2BindingFixture($db);
$f['activation'] = $state->settings()['activation'];
$out = v2BindingCaptureEntry($db,$f); $entryRow = v2BindingFrameRow($db,'41');
runtimeMysqlCheck($out['status'] === 'CAPTURED' && $entryRow !== null,'actual_capture_native_entry_insert');
$decoded = Max2CommonFrameV2::decoded($entryRow['frame_json'],$entryRow['frame_sha256']);
runtimeMysqlCheck(is_array($decoded) && $decoded['entry']['entry_id'] === '41'
    && $decoded['entry']['publication_id'] === $f['pair']['publication_id'],'actual_mysql_json_retains_string_ids');
runtimeMysqlCheck(hash('sha256',$entryRow['frame_json']) !== $entryRow['frame_sha256']
    && hash('sha256',Max2CommonFrameV2::canonical($decoded)) === $entryRow['frame_sha256'],'actual_mysql_json_normalization_preserves_canonical_hash');
runtimeMysqlCheck(v2BindingCaptureEntry($db,$f,$f['entry_observed_at']+10)['status'] === 'ALREADY_CAPTURED'
    && v2BindingFrameRow($db,'41') === $entryRow,'actual_mysql_duplicate_capture_no_overwrite');
$bad = $f; $bad['external_message_id'] = strtolower($f['external_message_id']);
runtimeMysqlCheck(v2BindingCaptureMessage($db,$bad)['status'] === 'HOLD','actual_ci_collation_does_not_accept_wrong_mid_case');
$db->exec("UPDATE messages SET sender_id='-91' WHERE id=97");
runtimeMysqlCheck(v2BindingCaptureMessage($db,$f)['status'] === 'HOLD','actual_mysql_sender_id_is_not_negative_chat');
$db->exec("UPDATE messages SET sender_id='91' WHERE id=97");
runtimeMysqlCheck(v2BindingCaptureMessage($db,$f)['status'] === 'CAPTURED','actual_capture_native_message_insert');
$messageRow = v2BindingFrameRow($db,null); $message = Max2CommonFrameV2::decoded($messageRow['frame_json'],$messageRow['frame_sha256']);
runtimeMysqlCheck(is_array($message) && $message['entry_frame_key'] === $entryRow['frame_key'] && $message['prospective_entry_count_bound'] === 1,'actual_mysql_exact_entry_frame_link');
runtimeMysqlCheck(v2BindingCaptureMessage($db,$f,$f['message_observed_at']+10)['status'] === 'ALREADY_CAPTURED'
    && v2BindingFrameRow($db,null) === $messageRow,'actual_mysql_duplicate_message_no_overwrite');
$columns = 'frame_key,project_key,platform,external_user_id,customer_channel_id,customer_key,conversation_id,entry_id,publication_id,version_id,entry_key,observed_at_unix,frame_json,frame_sha256,activation_sha256';
$select = '?,project_key,platform,external_user_id,customer_channel_id,customer_key,conversation_id,entry_id,publication_id,version_id,entry_key,observed_at_unix,frame_json,frame_sha256,activation_sha256';
runtimeMysqlReject($db,'INSERT INTO max2_common_entry_frames_v2 ('.$columns.') SELECT '.$select.' FROM max2_common_entry_frames_v2 WHERE entry_id=?',
    [hash('sha256','different frame same entry'),'41'],'native_unique_entry_id',1062);
runtimeMysqlReject($db,'UPDATE max2_common_entry_frames_v2 SET entry_id=NULL WHERE entry_id=?',['41'],'native_partial_entry_columns_rejected',3819);
runtimeMysqlReject($db,"UPDATE max2_common_entry_frames_v2 SET platform='telegram' WHERE entry_id=?",['41'],'native_frame_scope_rejected',3819);
$oversize = json_encode(['value'=>str_repeat('ю',8500)],JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
runtimeMysqlReject($db,'UPDATE max2_common_entry_frames_v2 SET frame_json=? WHERE entry_id=?',[$oversize,'41'],'native_frame_byte_bound_not_character_count',3819);
$db->exec("INSERT INTO messages SELECT 98,conversation_id,channel,direction,sender_type,sender_id,'mid.Second_98',metadata_json,created_at FROM messages WHERE id=97");
$other = $f; $other['message_id'] = '98'; $other['external_message_id'] = 'mid.Second_98';
runtimeMysqlCheck(v2BindingCaptureMessage($db,$other)['status'] === 'CAPTURED'
    && (int)$db->query('SELECT COUNT(*) FROM max2_common_entry_frames_v2 WHERE entry_id IS NULL')->fetchColumn() === 2,'mysql_null_unique_allows_distinct_message_frames');
runtimeMysqlCheck(v2BindingFrameRow($db,'41') === $entryRow,'constraint_failures_leave_original_entry_frame_unchanged');
runtimeMysqlOld($db); unset($state,$db);

$db = runtimeMysqlFresh(); $state = runtimeMysqlActivate($db,$clock); $f = v2BindingFixture($db);
$f['activation'] = $state->settings()['activation']; $db->loseAfterCommit = true;
$out = v2BindingCaptureEntry($db,$f); $row = v2BindingFrameRow($db,'41');
runtimeMysqlCheck($out['status'] === 'UNKNOWN' && $out['frames_added'] === null && $row !== null,'native_capture_commit_ack_lost_is_unknown');
runtimeMysqlCheck(v2BindingCaptureEntry($db,$f,$f['entry_observed_at']+10)['status'] === 'ALREADY_CAPTURED'
    && v2BindingFrameRow($db,'41') === $row,'native_capture_unknown_readback_does_not_rewrite');
runtimeMysqlOld($db); unset($state,$db);

// Two independent PDO connections contend for the actual settings row lock.
function runtimeRaceWrite($channel, string $message): void
{
    if (strlen($message)>16384 || fwrite($channel,$message."\n")!==strlen($message)+1) throw new RuntimeException('RACE_WRITE');
}
final class RuntimeRaceStatement extends PDOStatement
{
    protected function __construct(private RuntimeRacePdo $owner) {}
    public function execute(?array $params = null): bool
    {
        $this->owner->before($this->queryString); $ok = parent::execute($params);
        $this->owner->after($this->queryString); return $ok;
    }
}
final class RuntimeRacePdo extends V2RuntimeMysqlPdo
{
    private bool $gated = false;
    public function __construct(private $channel)
    {
        parent::__construct(); $this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[RuntimeRaceStatement::class,[$this]]);
    }
    private function selected(string $sql): bool { return !$this->gated && $sql === 'SELECT * FROM max2_common_settings_v2 WHERE id=1 FOR UPDATE'; }
    public function before(string $sql): void { if ($this->selected($sql)) runtimeRaceWrite($this->channel,'LOCKING'); }
    public function after(string $sql): void
    {
        if (!$this->selected($sql)) return;
        $this->gated = true; runtimeRaceWrite($this->channel,'LOCKED');
        if (fgets($this->channel,64) !== "RELEASE\n") throw new RuntimeException('RACE_RELEASE');
    }
}
function runtimeMysqlRace(string $mode, ?string $tickKey = null): array
{
    if (!in_array($mode,['tick','intent'],true)) throw new LogicException('RACE_MODE');
    $pairs=[]; $pids=[]; $parent=[]; $seen=[[],[]]; $results=[];
    for ($i=0;$i<2;$i++) {
        $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
        if ($pair===false) throw new RuntimeException('LOCAL_RACE_CHANNEL_REQUIRED');
        foreach ($pair as $socket) stream_set_timeout($socket,12); $pairs[$i]=$pair;
    }
    for ($i=0;$i<2;$i++) {
        $pid=pcntl_fork(); if ($pid===-1) throw new RuntimeException('RACE_FORK_FAILED');
        if ($pid===0) {
            $channel=$pairs[$i][1];
            foreach ($pairs as $j=>$pair) { fclose($pair[0]); if ($i!==$j) fclose($pair[1]); }
            try {
                $db=new RuntimeRacePdo($channel);
                runtimeRaceWrite($channel,'CONNECTED '.(string)$db->query('SELECT CONNECTION_ID()')->fetchColumn());
                $state=new Max2CommonValueStateV2($db,static fn(): int=>1795263660);
                $out=$mode==='tick'?$state->beginTick(1795263660)
                    :$state->consumeLookupIntent((string)$tickKey,'107','list',hash('sha256','different nonce '.$i),1795263660);
                runtimeRaceWrite($channel,'RESULT '.json_encode($out,JSON_THROW_ON_ERROR));
                unset($state,$db); fclose($channel); exit(0);
            } catch (Throwable $ignored) { runtimeRaceWrite($channel,'ERROR'); fclose($channel); exit(2); }
        }
        $pids[]=$pid;
    }
    foreach ($pairs as $i=>$pair) { fclose($pair[1]); $parent[$i]=$pair[0]; }
    $released=false; $deadline=hrtime(true)+18000000000;
    try {
        while (count($results)<2) {
            if (hrtime(true)>$deadline) throw new RuntimeException('RACE_DEADLINE');
            $read=array_values(array_filter($parent,static fn($s): bool=>is_resource($s)));
            if (!$read) throw new RuntimeException('RACE_CHANNELS_MISSING');
            $write=null; $except=null;
            if (stream_select($read,$write,$except,1)===false) throw new RuntimeException('RACE_SELECT');
            foreach ($read as $socket) {
                $i=array_search($socket,$parent,true); $line=fgets($socket,16386);
                if (!is_string($line)||!str_ends_with($line,"\n")||strlen($line)>16385) throw new RuntimeException('RACE_OUTPUT_BOUND');
                $line=rtrim($line,"\n");
                if (preg_match('/\ACONNECTED ([1-9][0-9]*)\z/D',$line,$m)===1) $seen[$i]['connection']=$m[1];
                elseif ($line==='LOCKING') $seen[$i]['locking']=true;
                elseif ($line==='LOCKED') $seen[$i]['locked']=true;
                elseif (str_starts_with($line,'RESULT ')) { $results[$i]=json_decode(substr($line,7),true,32,JSON_THROW_ON_ERROR); fclose($socket); $parent[$i]=null; }
                else throw new RuntimeException('RACE_CHILD_FAILED');
            }
            if (!$released && !empty($seen[0]['locking']) && !empty($seen[1]['locking'])
                && (!empty($seen[0]['locked']) || !empty($seen[1]['locked']))) {
                foreach ($parent as $socket) runtimeRaceWrite($socket,'RELEASE'); $released=true;
            }
        }
        runtimeMysqlCheck($released && isset($seen[0]['connection'],$seen[1]['connection'])
            && $seen[0]['connection']!==$seen[1]['connection'],'overlapping_native_connections_'.$mode);
    } finally {
        foreach ($parent as $socket) if (is_resource($socket)) fclose($socket);
        foreach ($pids as $pid) { pcntl_waitpid($pid,$status); runtimeMysqlCheck(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'race_child_exit'); }
    }
    ksort($results); return array_values($results);
}

// No live PDO survives into either fork.
$db=runtimeMysqlFresh(); $state=runtimeMysqlActivate($db,$clock); runtimeMysqlOld($db); unset($state,$db);
$race=runtimeMysqlRace('tick'); $status=array_column($race,'status');
runtimeMysqlCheck(count(array_filter($status,static fn(string $s): bool=>$s==='ACQUIRED'))===1
    && count(array_filter($status,static fn(string $s): bool=>$s==='BUSY'))===1,'only_one_concurrent_tick');
$db=new V2RuntimeMysqlPdo(); $tickKey=$db->query('SELECT active_tick_key FROM max2_common_settings_v2')->fetchColumn();
runtimeMysqlCheck((int)$db->query('SELECT COUNT(*) FROM max2_common_ticks_v2')->fetchColumn()===1,'one_native_running_tick');
runtimeMysqlOld($db); unset($db);
$race=runtimeMysqlRace('intent',$tickKey); $status=array_column($race,'status');
runtimeMysqlCheck(count(array_filter($status,static fn(string $s): bool=>$s==='CONSUMED'))===1
    && count(array_filter($status,static fn(string $s): bool=>$s==='ALREADY_CONSUMED'))===1,'only_one_concurrent_lookup_intent');
runtimeMysqlCheck(count(array_filter($race,static fn(array $r): bool=>($r['call_permitted']??false)===true))===1,'only_known_committed_intent_grants_call');
$db=new V2RuntimeMysqlPdo();
runtimeMysqlCheck((int)$db->query('SELECT COUNT(*) FROM max2_common_lookup_intents_v2')->fetchColumn()===1,'one_native_intent_after_race');
runtimeMysqlOld($db); unset($db);
foreach ($dependencyHashes as $relative=>$wanted) runtimeMysqlCheck(hash_file('sha256',$root.'/'.$relative)===$wanted,'dependency_source_unchanged');
echo json_encode(['status'=>'PASS','checks'=>$checks,'server_version'=>$serverVersion,'driver'=>'mysql','engine'=>'InnoDB',
    'exact_migration_113'=>true,'production_state_constructor'=>true,'actual_capture_services'=>true,
    'json_storage_normalization_verified'=>true,'parallel_tick_connections'=>2,'parallel_intent_connections'=>2,
    'successful_http_authentication_tested'=>false,'provider_calls'=>0,'live_database_connections'=>0,
    'database_scope'=>'disposable_ci_service_only','fixture_activation'=>true,'production_activation'=>false,'deployment'=>false],JSON_THROW_ON_ERROR)."\n";
