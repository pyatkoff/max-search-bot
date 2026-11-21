<?php
declare(strict_types=1);

// Native MySQL engine fixture, restricted to the disposable CI service below.
// It has no app configuration, private key, provider client instance or live DSN.
if (PHP_SAPI !== 'cli' || getenv('MAX2_V2_MYSQL_FIXTURE') !== 'ephemeral-ci-only'
    || getenv('GITHUB_ACTIONS') !== 'true') throw new RuntimeException('EPHEMERAL_CI_ONLY');
if (!in_array('mysql', PDO::getAvailableDrivers(), true) || !function_exists('pcntl_fork')) {
    throw new RuntimeException('MYSQL_PDO_PCNTL_REQUIRED');
}
$root = dirname(__DIR__, 2);
$ledger = $root . '/ledger';
require_once $root . '/policy/services/Max2CommonValueAccountingV2.php';
require_once $root . '/delivery/services/Max2CommonValueMetrikaClientV2.php';
require_once $ledger . '/services/Max2CommonValueRepositoryV2.php';
$raw = file_get_contents($ledger . '/tests/repository.php');
if (!is_string($raw) || hash('sha256', $raw) !== '3b0294c8cfb2538349e3718bb6b9226fb546b09cae48d7665266033d95899ab1') {
    throw new RuntimeException('CANONICAL_LEDGER_FIXTURE_REQUIRED');
}
$start = strpos($raw, 'function ledgerActivation('); $end = strpos($raw, 'function ledgerCount(');
if (!is_int($start) || !is_int($end) || $end <= $start
    || file_get_contents(dirname(__DIR__) . '/reference/ledger-input-fixtures.php') !==
        "<?php\ndeclare(strict_types=1);\n\n" . rtrim(substr($raw, $start, $end - $start)) . "\n") {
    throw new RuntimeException('CANONICAL_PURE_INPUT_EXCERPT_REQUIRED');
}
require_once dirname(__DIR__) . '/reference/ledger-input-fixtures.php';
$migration = file_get_contents($ledger . '/migrations/112_max2_common_value_v2.sql');
if (!is_string($migration) || hash('sha256', $migration) !== 'f090952953bed9f365ed26cc799748c3f93afcad93a4bd3b3512ab90837be70b') {
    throw new RuntimeException('EXACT_MIGRATION_112_REQUIRED');
}
$checks = 0; $sourceHashes = [];
foreach ([$ledger . '/migrations/112_max2_common_value_v2.sql', $ledger . '/services/Max2CommonValueRepositoryV2.php',
    $ledger . '/tests/repository.php', $root . '/policy/services/Max2ValueBindingPolicyV2.php',
    $root . '/policy/services/Max2CommonValueAccountingV2.php', $root . '/delivery/services/Max2CommonValueMetrikaClientV2.php'] as $path) {
    $sourceHashes[$path] = hash_file('sha256', $path);
}
function mysqlCheck(bool $ok, string $label): void
{
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException('ASSERTION_FAILED_' . $label);
}
class FixtureMysqlPDO extends PDO
{
    public bool $loseAfterCommit = false;
    public function __construct()
    {
        // Fixed synthetic credentials for one loopback-only, disposable service.
        parent::__construct('mysql:host=127.0.0.1;port=33306;dbname=max2_v2_ci;charset=utf8mb4',
            'max2_v2_ci', 'max2-v2-synthetic-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
        $meta = $this->query('SELECT DATABASE() AS db,CURRENT_USER() AS actor,VERSION() AS version')->fetch();
        if ($meta['db'] !== 'max2_v2_ci' || !str_starts_with($meta['actor'], 'max2_v2_ci@')
            || !str_starts_with($meta['version'], '8.0.')) throw new RuntimeException('DISPOSABLE_MYSQL80_SCOPE_REQUIRED');
        $this->exec('SET SESSION innodb_lock_wait_timeout=3');
        $this->exec("SET SESSION time_zone='+00:00'");
    }
    public function commit(): bool
    {
        $result = parent::commit();
        if ($this->loseAfterCommit) {
            $this->loseAfterCommit = false;
            throw new PDOException('synthetic lost commit acknowledgement');
        }
        return $result;
    }
}
function mysqlTables(PDO $db): array
{
    $names = $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
    sort($names); return $names;
}
function mysqlMarker(PDO $db): void
{
    mysqlCheck($db->query('SELECT purpose FROM max2_v2_ci_marker WHERE id=1')->fetchColumn() === 'ephemeral-ci-only', 'ci_marker');
    $allowed = ['max2_v2_ci_marker', 'max2_common_value_accounts_v2', 'max2_common_value_events_v2', 'max_channel_conversion_outbox'];
    foreach (mysqlTables($db) as $table) mysqlCheck(in_array($table, $allowed, true), 'no_foreign_tables');
}
function mysqlFresh(): FixtureMysqlPDO
{
    global $migration;
    $db = new FixtureMysqlPDO(); mysqlMarker($db);
    // These fixed names exist only in the initially empty, marked CI database.
    foreach (['max2_common_value_events_v2', 'max2_common_value_accounts_v2', 'max_channel_conversion_outbox'] as $name) {
        $db->exec('DROP TABLE IF EXISTS ' . $name);
    }
    $statements = array_values(array_filter(array_map('trim', explode(';', $migration)), static fn(string $s): bool => $s !== ''));
    mysqlCheck(count($statements) === 2, 'exact_two_ddl_statements');
    foreach ($statements as $statement) $db->exec($statement); // Exact SQL, no dialect rewriting.
    $db->exec('CREATE TABLE max_channel_conversion_outbox (id BIGINT PRIMARY KEY,value_rub INT NOT NULL,target VARCHAR(64) NOT NULL) ENGINE=InnoDB');
    $db->exec("INSERT INTO max_channel_conversion_outbox VALUES(1,300,'anytour_modeled_value_v1')");
    return $db;
}
function mysqlCount(PDO $db, string $kind): int
{
    $table = match ($kind) { 'accounts' => 'max2_common_value_accounts_v2', 'events' => 'max2_common_value_events_v2', default => throw new LogicException('OWN_TABLE_ONLY') };
    return (int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
function mysqlOldUnchanged(PDO $db): void
{
    $rows = $db->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll();
    mysqlCheck(count($rows) === 1 && (int)$rows[0]['id'] === 1 && (int)$rows[0]['value_rub'] === 300
        && $rows[0]['target'] === 'anytour_modeled_value_v1', 'v1_synthetic_sentinel_unchanged');
}
function mysqlMustReject(PDO $db, string $sql, string $label): void
{
    $failed = false; try { $db->exec($sql); } catch (PDOException $expected) { $failed = true; }
    mysqlCheck($failed, $label);
}

// No test DDL is allowed until this dedicated database is proved initially empty.
$db = new FixtureMysqlPDO();
mysqlCheck(mysqlTables($db) === [], 'test_database_initially_empty');
$serverVersion = $db->query('SELECT VERSION()')->fetchColumn();
$db->exec('CREATE TABLE max2_v2_ci_marker(id INT PRIMARY KEY,purpose VARCHAR(32) NOT NULL) ENGINE=InnoDB');
$db->exec("INSERT INTO max2_v2_ci_marker VALUES(1,'ephemeral-ci-only')");
unset($db);

// Exact MySQL DDL and native production constructor, then monetary/constraint behavior.
$db = mysqlFresh(); $repo = new Max2CommonValueRepositoryV2($db, ledgerActivation());
$engines = $db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('max2_common_value_accounts_v2','max2_common_value_events_v2')")->fetchAll();
mysqlCheck(count($engines) === 2 && count(array_filter($engines, static fn(array $row): bool => $row['ENGINE'] === 'InnoDB')) === 2, 'actual_innodb');
$enforced = $db->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ('max2_common_value_accounts_v2','max2_common_value_events_v2') AND CONSTRAINT_TYPE='CHECK' AND ENFORCED='YES'")->fetchColumn();
mysqlCheck((int)$enforced === 11, 'all_eleven_checks_enforced');
$args = ledgerCase(); $first = $repo->reserve(...$args);
mysqlCheck($first['status'] === 'RESERVED' && $first['delta_kopeks'] === 7364 && !$first['delivery_permitted'], 'native_linked_reserve');
mysqlCheck($repo->reserve(...$args)['status'] === 'ALREADY_RECORDED' && mysqlCount($db, 'events') === 1, 'native_idempotent_reserve');
mysqlMustReject($db, "UPDATE max2_common_value_accounts_v2 SET goal_id=678187097", 'old_goal_check_constraint');
mysqlMustReject($db, 'UPDATE max2_common_value_events_v2 SET attempt_count=2', 'single_attempt_check_constraint');
mysqlMustReject($db, 'DELETE FROM max2_common_value_accounts_v2', 'native_foreign_key_restrict');
$next = $repo->reserve(...ledgerNext($args));
mysqlCheck($next['status'] === 'RESERVED' && $next['delta_kopeks'] === 5000, 'native_positive_delta_only');
$otherInquiry = ledgerNext(ledgerCase(true, '30', '71', '104'), '100.00', '111');
mysqlCheck($repo->reserve(...$otherInquiry)['status'] === 'HOLD' && mysqlCount($db, 'accounts') === 1, 'receipt_unique_across_inquiries');
$full = $repo->reserve(...ledgerNext(ledgerCase(false, '30', '0', '104'), '100.00', '117'));
$full2 = $repo->reserve(...ledgerNext(ledgerCase(false, '31', '0', '105'), '100.00', '118'));
mysqlCheck($full['status'] === 'RESERVED' && $full['delta_kopeks'] === 10000 && $full2['status'] === 'RESERVED'
    && mysqlCount($db, 'accounts') === 3, 'mysql_null_receipts_allow_distinct_full_accounts');
mysqlCheck($repo->reserve(...ledgerCase(false))['reason'] === 'IMMUTABLE_INQUIRY_ACCOUNT_CONFLICT', 'native_account_cannot_rebind');
$send = new Max2CommonValueRepositoryV2($db, ledgerActivation('send'));
mysqlCheck($repo->beginAttempt($first['event_key'], 1795263661)['status'] === 'HOLD', 'collect_cannot_claim_attempt');
$intent = $send->beginAttempt($first['event_key'], 1795263661); $wire = $intent['event'] ?? [];
mysqlCheck($intent['status'] === 'ATTEMPT_CLAIMED' && $intent['delivery_permitted']
    && hash('sha256', Max2CommonValueMetrikaClientV2::csv($wire, 1795263661)) === $wire['csv_sha256'], 'native_intent_and_csv_parity');
$unknown = ['state' => 'unknown', 'csv_sha256' => $wire['csv_sha256'], 'response_sha256' => hash('sha256', 'synthetic unknown'),
    'upload_id' => null, 'http_status' => 0, 'code' => 'synthetic_unknown'];
mysqlCheck($send->recordAttemptResult($wire['event_key'], $wire['attempt_key'], $unknown, 1795263662)['delivery_state'] === 'unknown', 'native_unknown_preserved');
mysqlCheck($send->beginAttempt($wire['event_key'], 1795263663)['status'] === 'ALREADY_ATTEMPTED', 'native_unknown_no_reissue');
$accepted = ['state' => 'accepted', 'csv_sha256' => $wire['csv_sha256'], 'response_sha256' => hash('sha256', 'synthetic accepted'),
    'upload_id' => '555', 'http_status' => 202, 'code' => 'synthetic_accepted'];
mysqlCheck($send->recordAttemptResult($wire['event_key'], $wire['attempt_key'], $accepted, 1795263664)['delivery_state'] === 'accepted', 'native_acceptance_receipt');
$processed = ['upload_id' => '555', 'status' => 'PROCESSED', 'source_quantity' => 1, 'line_quantity' => 1, 'response_sha256' => hash('sha256', 'synthetic processed')];
$out = $send->recordProviderStatus($wire['event_key'], $wire['attempt_key'], $processed, 1795263665);
mysqlCheck($out['delivery_state'] === 'processed' && !$out['visit_linkage_verified'], 'native_processed_separate_from_visit_linkage');
mysqlOldUnchanged($db); unset($repo, $send, $db);

// A temporary CI-only CHECK rejects the event after the account INSERT.
// It needs no SUPER grant, trigger privilege or server configuration change.
$db = mysqlFresh(); $repo = new Max2CommonValueRepositoryV2($db, ledgerActivation());
$db->exec("ALTER TABLE max2_common_value_events_v2 ADD CONSTRAINT ck_ci_synthetic_event_failure CHECK (profile_id<>'107')");
$result = $repo->reserve(...ledgerCase());
mysqlCheck($result['status'] === 'HOLD' && mysqlCount($db, 'accounts') === 0 && mysqlCount($db, 'events') === 0
    && !$db->inTransaction(), 'mysql_event_failure_atomic_rollback');
mysqlOldUnchanged($db); unset($repo, $db);

// Acknowledgement loss AFTER actual MySQL commit must not make a retry/send permission.
$db = mysqlFresh(); $repo = new Max2CommonValueRepositoryV2($db, ledgerActivation());
$db->loseAfterCommit = true; $result = $repo->reserve(...ledgerCase());
mysqlCheck($result['status'] === 'UNKNOWN' && !$result['automatic_retry'] && !$result['delivery_permitted']
    && mysqlCount($db, 'accounts') === 1 && mysqlCount($db, 'events') === 1, 'actual_committed_reserve_unknown');
mysqlCheck($repo->readback($args[0]['inquiry_key'], $result['event_key'])['status'] === 'OBSERVED', 'unknown_native_reserve_exact_readback');
mysqlOldUnchanged($db); unset($repo, $db);

$db = mysqlFresh(); $repo = new Max2CommonValueRepositoryV2($db, ledgerActivation('send'));
$saved = $repo->reserve(...ledgerCase()); $db->loseAfterCommit = true;
$result = $repo->beginAttempt($saved['event_key'], 1795263661);
mysqlCheck($result['status'] === 'UNKNOWN' && !isset($result['event']) && !$result['delivery_permitted'], 'actual_committed_intent_no_sendable_result');
mysqlCheck($repo->beginAttempt($saved['event_key'], 1795263662)['status'] === 'ALREADY_ATTEMPTED'
    && $repo->pendingReservations(1795263662)['events'] === [], 'actual_unknown_intent_no_reissue');
mysqlOldUnchanged($db); unset($repo, $db);

$db = mysqlFresh(); $repo = new Max2CommonValueRepositoryV2($db, ledgerActivation());
$db->beginTransaction();
mysqlCheck($repo->reserve(...ledgerCase())['reason'] === 'OUTER_TRANSACTION_REJECTED'
    && $db->inTransaction() && mysqlCount($db, 'accounts') === 0, 'native_outer_transaction_preserved');
$db->rollBack(); mysqlOldUnchanged($db); unset($repo, $db);

// Independent processes open their own connections AFTER fork. No parent PDO is inherited.
function raceSend($channel, string $message): void
{
    if (strlen($message) > 16384 || fwrite($channel, $message . "\n") !== strlen($message) + 1) throw new RuntimeException('RACE_CHANNEL_WRITE');
}
function raceRelease($channel): void
{
    $line = fgets($channel, 64);
    if ($line !== "RELEASE\n") throw new RuntimeException('RACE_RELEASE_REQUIRED');
}
final class RaceMysqlStatement extends PDOStatement
{
    protected function __construct(private RaceMysqlPDO $owner) {}
    public function execute(?array $params = null): bool
    {
        $this->owner->beforeStatement($this->queryString);
        $result = parent::execute($params);
        $this->owner->afterStatement($this->queryString);
        return $result;
    }
}
final class RaceMysqlPDO extends FixtureMysqlPDO
{
    private bool $gated = false;
    public function __construct(private $channel, private string $race)
    {
        parent::__construct();
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RaceMysqlStatement::class, [$this]]);
    }
    private function selected(string $sql): bool
    {
        return !$this->gated && $sql === ($this->race === 'reserve'
            ? 'SELECT * FROM max2_common_value_accounts_v2 WHERE counter_id=? AND target=? AND project_key=? AND platform=? AND conversation_id=? FOR UPDATE'
            : 'SELECT * FROM max2_common_value_accounts_v2 WHERE id=? FOR UPDATE');
    }
    public function beforeStatement(string $sql): void
    {
        if ($this->selected($sql) && $this->race === 'attempt') raceSend($this->channel, 'LOCKING');
    }
    public function afterStatement(string $sql): void
    {
        if (!$this->selected($sql)) return;
        $this->gated = true; raceSend($this->channel, 'LOCKED'); raceRelease($this->channel);
    }
}
function mysqlRace(string $mode, ?string $eventKey = null): array
{
    if (!in_array($mode, ['reserve', 'attempt'], true)) throw new LogicException('RACE_MODE');
    $pairs = []; $pids = []; $parent = []; $states = [[], []]; $outcomes = [];
    for ($i = 0; $i < 2; $i++) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) throw new RuntimeException('LOCAL_RACE_CHANNEL_REQUIRED');
        foreach ($pair as $channel) stream_set_timeout($channel, 12);
        $pairs[$i] = $pair;
    }
    for ($i = 0; $i < 2; $i++) {
        $pid = pcntl_fork(); if ($pid === -1) throw new RuntimeException('RACE_FORK_FAILED');
        if ($pid === 0) {
            $channel = $pairs[$i][1];
            foreach ($pairs as $j => $pair) { fclose($pair[0]); if ($i !== $j) fclose($pair[1]); }
            try {
                $db = new RaceMysqlPDO($channel, $mode);
                raceSend($channel, 'CONNECTED ' . (string)$db->query('SELECT CONNECTION_ID()')->fetchColumn());
                $repo = new Max2CommonValueRepositoryV2($db, ledgerActivation($mode === 'attempt' ? 'send' : 'collect'));
                $result = $mode === 'reserve' ? $repo->reserve(...ledgerCase()) : $repo->beginAttempt((string)$eventKey, 1795263661);
                raceSend($channel, 'RESULT ' . json_encode($result, JSON_THROW_ON_ERROR));
                unset($repo, $db); fclose($channel); exit(0);
            } catch (Throwable $ignored) { raceSend($channel, 'ERROR'); fclose($channel); exit(2); }
        }
        $pids[] = $pid;
    }
    foreach ($pairs as $i => $pair) { fclose($pair[1]); $parent[$i] = $pair[0]; }
    $released = false; $deadline = hrtime(true) + 18000000000;
    try {
        while (count($outcomes) < 2) {
            if (hrtime(true) > $deadline) throw new RuntimeException('RACE_DEADLINE');
            $read = array_values(array_filter($parent, static fn($socket): bool => is_resource($socket)));
            if (!$read) throw new RuntimeException('RACE_NO_CHANNELS');
            $write = null; $except = null;
            if (stream_select($read, $write, $except, 1) === false) throw new RuntimeException('RACE_SELECT');
            foreach ($read as $socket) {
                $i = array_search($socket, $parent, true); $line = fgets($socket, 16386);
                if (!is_string($line) || !str_ends_with($line, "\n") || strlen($line) > 16385) throw new RuntimeException('RACE_RESPONSE_BOUND');
                $line = rtrim($line, "\n");
                if (preg_match('/\ACONNECTED ([1-9][0-9]*)\z/D', $line, $m) === 1) $states[$i]['connection'] = $m[1];
                elseif ($line === 'LOCKING') $states[$i]['locking'] = true;
                elseif ($line === 'LOCKED') $states[$i]['locked'] = true;
                elseif (str_starts_with($line, 'RESULT ')) {
                    $outcomes[$i] = json_decode(substr($line, 7), true, 32, JSON_THROW_ON_ERROR);
                    fclose($socket); $parent[$i] = null;
                } else throw new RuntimeException('RACE_CHILD_FAILED');
            }
            $bothLocked = !empty($states[0]['locked']) && !empty($states[1]['locked']);
            $attemptContending = !empty($states[0]['locking']) && !empty($states[1]['locking'])
                && (!empty($states[0]['locked']) || !empty($states[1]['locked']));
            if (!$released && ($mode === 'reserve' ? $bothLocked : $attemptContending)) {
                foreach ($parent as $socket) raceSend($socket, 'RELEASE');
                $released = true;
            }
        }
        mysqlCheck($released && isset($states[0]['connection'], $states[1]['connection'])
            && $states[0]['connection'] !== $states[1]['connection'], 'distinct_overlapping_mysql_connections_' . $mode);
    } finally {
        foreach ($parent as $socket) if (is_resource($socket)) fclose($socket);
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            mysqlCheck(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'race_child_exit');
        }
    }
    ksort($outcomes); return array_values($outcomes);
}
$db = mysqlFresh(); mysqlOldUnchanged($db); unset($db);
$race = mysqlRace('reserve'); $statuses = array_column($race, 'status');
mysqlCheck(count(array_filter($statuses, static fn(string $s): bool => $s === 'RESERVED')) === 1, 'one_concurrent_reservation_winner');
foreach ($race as $out) {
    mysqlCheck(in_array($out['status'], ['RESERVED', 'ALREADY_RECORDED', 'HOLD', 'UNKNOWN'], true)
        && empty($out['delivery_permitted']), 'reservation_contender_never_gets_send_permission');
    if ($out['status'] === 'UNKNOWN') mysqlCheck($out['automatic_retry'] === false, 'deadlock_unknown_no_retry');
}
$db = new FixtureMysqlPDO();
mysqlCheck(mysqlCount($db, 'accounts') === 1 && mysqlCount($db, 'events') === 1
    && (int)$db->query('SELECT SUM(delta_kopeks) FROM max2_common_value_events_v2')->fetchColumn() === 7364, 'real_gap_lock_race_single_ledger_reserve');
$eventKey = $db->query('SELECT event_key FROM max2_common_value_events_v2')->fetchColumn();
mysqlOldUnchanged($db); unset($db);

$race = mysqlRace('attempt', $eventKey); $claimed = array_values(array_filter($race, static fn(array $o): bool => $o['status'] === 'ATTEMPT_CLAIMED'));
mysqlCheck(count($claimed) === 1 && count(array_filter($race, static fn(array $o): bool => $o['status'] === 'ALREADY_ATTEMPTED')) === 1, 'one_concurrent_attempt_winner');
foreach ($race as $out) mysqlCheck($out['status'] === 'ATTEMPT_CLAIMED'
    ? $out['delivery_permitted'] === true && isset($out['event'])
    : empty($out['delivery_permitted']) && !isset($out['event']), 'only_committed_winner_has_sendable_row');
$db = new FixtureMysqlPDO(); $row = $db->query('SELECT delivery_state,attempt_count,csv_sha256 FROM max2_common_value_events_v2')->fetch();
mysqlCheck(mysqlCount($db, 'events') === 1 && $row['delivery_state'] === 'attempted' && (int)$row['attempt_count'] === 1
    && hash('sha256', Max2CommonValueMetrikaClientV2::csv($claimed[0]['event'], 1795263661)) === $row['csv_sha256'], 'real_row_lock_single_durable_attempt');
mysqlOldUnchanged($db); unset($db);
foreach ($sourceHashes as $path => $hash) mysqlCheck(hash_file('sha256', $path) === $hash, 'original_source_unchanged');
echo json_encode(['status' => 'PASS', 'checks' => $checks, 'server_version' => $serverVersion,
    'driver' => 'mysql', 'engine' => 'InnoDB', 'exact_migration_112' => true, 'production_constructor' => true,
    'parallel_reserve_connections' => 2, 'parallel_attempt_connections' => 2, 'provider_calls' => 0,
    'live_database_connections' => 0, 'database_scope' => 'disposable_ci_service_only',
    'activation' => false, 'deployment' => false], JSON_THROW_ON_ERROR) . "\n";
