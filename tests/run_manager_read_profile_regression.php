<?php
declare(strict_types=1);

// Synthetic in-memory SQLite / empty loopback MySQL only. The profiler never
// connects to production and this regression loads no application config.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/tools/manager-read-profile.php';

$passed = 0;
$readProfileStage = 'fixture_setup';
function readProfileCheck(string $name, bool $condition): void
{
    global $passed;
    if (!$condition) { throw new RuntimeException('FAIL ' . $name); }
    ++$passed;
    echo 'PASS ' . $name . "\n";
}

function readProfileShape(array $profile): bool
{
    if (($profile['available'] ?? false) !== true || ($profile['scope'] ?? '') !== 'instrumented_service_list' || ($profile['sample_count'] ?? 0) !== 1 || ($profile['cache_state'] ?? '') !== 'uncontrolled') { return false; }
    foreach (['load_ms','statement_count','timed_execute_count'] as $field) {
        if (!isset($profile[$field]) || !is_numeric($profile[$field]) || !is_finite((float)$profile[$field]) || $profile[$field] < 0) { return false; }
    }
    if (!is_bool($profile['records_truncated'] ?? null)) { return false; }
    if (!is_array($profile['statements'] ?? null) || count($profile['statements']) > 64) { return false; }
    foreach ($profile['statements'] as $statement) {
        if (count(array_diff(array_keys($statement), ['category','execute_count','execute_ms','fetch_ms','fetched_rows','value_bytes','failed'])) !== 0 || !is_bool($statement['failed'] ?? null)) { return false; }
        if (!in_array($statement['category'] ?? '', ['metadata','manager_list','permissions','delivery','other'], true)) { return false; }
        foreach (['execute_count','fetch_ms','fetched_rows','value_bytes'] as $field) {
            if (!isset($statement[$field]) || !is_numeric($statement[$field]) || !is_finite((float)$statement[$field]) || $statement[$field] < 0) { return false; }
        }
        if (!array_key_exists('execute_ms',$statement) || ($statement['execute_ms'] !== null && (!is_numeric($statement['execute_ms']) || !is_finite((float)$statement['execute_ms']) || $statement['execute_ms'] < 0))) { return false; }
    }
    return true;
}

class ReadProfileOriginalStatement extends PDOStatement
{
    public string $marker;
    protected function __construct(string $marker) { $this->marker = $marker; }
}

function readProfileFixture(PDO $pdo, string $driver): void
{
    $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $pdo->exec('CREATE TABLE conversations (id INTEGER NOT NULL PRIMARY KEY,project_key VARCHAR(64) NOT NULL)' . $suffix);
    $pdo->exec('CREATE TABLE messages (id INTEGER NOT NULL PRIMARY KEY,conversation_id INTEGER NOT NULL,text VARCHAR(191) NOT NULL)' . $suffix);
    $pdo->exec('CREATE INDEX idx_messages_conversation ON messages(conversation_id)');
    $pdo->exec('CREATE TABLE managers (id INTEGER NOT NULL PRIMARY KEY,display_name VARCHAR(191) NOT NULL)' . $suffix);
    $pdo->exec('CREATE TABLE profile_fixture_values (id INTEGER NOT NULL PRIMARY KEY,value VARCHAR(191) NOT NULL)' . $suffix);
    $pdo->exec("INSERT INTO conversations VALUES (81723491,'PRIVATE_project_token'),(81723492,'PRIVATE_project_token')");
    $pdo->exec("INSERT INTO messages VALUES (1,81723491,'PRIVATE_customer_message'),(2,81723491,'Синтетическое сообщение')");
    $pdo->exec("INSERT INTO managers VALUES (7,'PRIVATE_operator_name')");
    $pdo->exec("INSERT INTO profile_fixture_values VALUES (1,'PRIVATE_param_and_value'),(2,'Русский текст 😊')");
}

function readProfileRun(PDO $pdo, string $driver): void
{
    global $readProfileStage;
    $readProfileStage = $driver . '_capture_contract';
    $prefix = $driver . ': ';
    $original = $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    $expectedObject = new stdClass();
    $profile = null;
    $value = ManagerReadProfile::capture($pdo, static fn() => $expectedObject, $profile, false);
    readProfileCheck($prefix . 'capture returns exact object identity and restores the original statement class', $value === $expectedObject && $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) === $original);

    $plain = $pdo->prepare('SELECT value FROM profile_fixture_values WHERE id=?');
    $plain->execute([1]); $expected = $plain->fetchColumn();
    $profile = null;
    $result = ManagerReadProfile::capture($pdo, static function () use ($pdo): mixed {
        $statement = $pdo->prepare('SELECT value FROM profile_fixture_values WHERE id=?');
        $statement->execute([1]); return $statement->fetchColumn();
    }, $profile, false);
    readProfileCheck($prefix . 'actual parameterized value and Unicode data are unchanged', $result === $expected);
    readProfileCheck($prefix . 'one actual execute and fetchColumn have truthful bounded counts and scalar bytes', readProfileShape($profile) && $profile['statement_count'] === 1 && $profile['timed_execute_count'] === 1 && $profile['statements'][0]['execute_count'] === 1 && $profile['statements'][0]['fetched_rows'] === 1 && $profile['statements'][0]['value_bytes'] === strlen((string)$expected) && is_numeric($profile['statements'][0]['execute_ms']));

    $hostile = 'PRIVATE_param_and_value';
    $profile = null;
    $values = ManagerReadProfile::capture($pdo, static function () use ($pdo, $hostile): array {
        $statement = $pdo->prepare('SELECT value FROM profile_fixture_values WHERE value=?');
        $statement->bindValue(1, $hostile, PDO::PARAM_STR); $statement->execute();
        $first = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->execute(['Русский текст 😊']);
        $remaining = $statement->fetchAll(PDO::FETCH_ASSOC);
        return [$first, $remaining];
    }, $profile, false);
    readProfileCheck($prefix . 'bound execute, repeated execute, fetch and fetchAll preserve values', $values === [['value'=>$hostile], [['value'=>'Русский текст 😊']]]);
    readProfileCheck($prefix . 'repeated executes and both fetch methods accumulate on one actual prepared statement', readProfileShape($profile) && $profile['statement_count'] === 1 && $profile['timed_execute_count'] === 2 && $profile['statements'][0]['execute_count'] === 2 && $profile['statements'][0]['fetched_rows'] === 2 && $profile['statements'][0]['value_bytes'] === strlen($hostile) + strlen('Русский текст 😊'));
    $json = json_encode($profile, JSON_THROW_ON_ERROR);
    readProfileCheck($prefix . 'statement profile does not contain SQL, bound parameters or fetched text', !str_contains($json, $hostile) && !str_contains($json, 'Русский') && !str_contains($json, 'SELECT') && !str_contains($json, 'WHERE'));

    $error = new RuntimeException('PRIVATE_callback_exception'); $caught = null; $profile = null;
    try { ManagerReadProfile::capture($pdo, static function () use ($pdo, $error): never { $pdo->prepare('SELECT id FROM managers')->execute(); throw $error; }, $profile, false); }
    catch (Throwable $actual) { $caught = $actual; }
    readProfileCheck($prefix . 'original callback exception propagates and statement class is restored', $caught === $error && $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) === $original);
    readProfileCheck($prefix . 'callback failure profile never exports the raw exception', !str_contains(json_encode($profile, JSON_THROW_ON_ERROR), 'PRIVATE_callback_exception'));

    $profile = null; $executeRejected = false;
    try { ManagerReadProfile::capture($pdo, static function () use ($pdo): void { $statement=$pdo->prepare('SELECT value FROM profile_fixture_values WHERE id=?'); $statement->execute([1,2]); }, $profile, false); }
    catch (PDOException $error) { $executeRejected = true; }
    readProfileCheck($prefix . 'an actual failed execute propagates and records failure without raw SQL or parameter details', $executeRejected && $profile['statement_count'] === 1 && $profile['timed_execute_count'] === 1 && $profile['statements'][0]['failed'] === true && $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) === $original && !str_contains(json_encode($profile, JSON_THROW_ON_ERROR),'SELECT'));

    $profile = null;
    ManagerReadProfile::capture($pdo, static fn() => $pdo->prepare('SELECT value FROM profile_fixture_values'), $profile, false);
    readProfileCheck($prefix . 'an unexecuted prepare is counted but never invented as a timed query', readProfileShape($profile) && $profile['statement_count'] === 1 && $profile['timed_execute_count'] === 0 && $profile['statements'][0]['execute_count'] === 0 && $profile['statements'][0]['execute_ms'] === null && $profile['statements'][0]['fetched_rows'] === 0);

    $profile = null;
    $queryResult = ManagerReadProfile::capture($pdo, static fn() => $pdo->query('SELECT value FROM profile_fixture_values ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), $profile, true);
    readProfileCheck($prefix . 'PDO query implicit execution remains explicitly untimed while returned values are identical', $queryResult === [$hostile, 'Русский текст 😊'] && readProfileShape($profile) && $profile['statement_count'] === 1 && $profile['timed_execute_count'] === 0 && $profile['statements'][0]['execute_ms'] === null && $profile['statements'][0]['fetched_rows'] === 2 && $profile['plan']['available'] === false && $profile['plan']['reason'] === 'no_canonical_list_query');

    $profile = null; $retained = null;
    ManagerReadProfile::capture($pdo, static function () use ($pdo, &$retained): void { $retained = $pdo->prepare('SELECT value FROM profile_fixture_values WHERE id=?'); $retained->execute([1]); $retained->fetchColumn(); }, $profile, false);
    $beforeStale = json_encode($profile, JSON_THROW_ON_ERROR); $retained->execute([2]); $staleValue = $retained->fetchColumn(); $nextProfile = null;
    ManagerReadProfile::capture($pdo, static function () use ($retained): void { $retained->execute([1]); $retained->fetchColumn(); }, $nextProfile, false);
    readProfileCheck($prefix . 'a retained old statement cannot mutate the completed profile or attach to a later capture', $staleValue === 'Русский текст 😊' && json_encode($profile, JSON_THROW_ON_ERROR) === $beforeStale && $nextProfile['statement_count'] === 0);

    $profile = null;
    ManagerReadProfile::capture($pdo, static function () use ($pdo): void { for ($i=0;$i<70;++$i) { $statement=$pdo->prepare('SELECT value FROM profile_fixture_values WHERE id=?'); $statement->execute([1]); $statement->fetchColumn(); } }, $profile, false);
    readProfileCheck($prefix . 'more than sixty-four prepares remain truthfully counted with bounded public records', readProfileShape($profile) && $profile['statement_count'] === 70 && $profile['timed_execute_count'] === 70 && count($profile['statements']) === 64 && $profile['records_truncated'] === true);

    $canonical = 'SELECT c.id,c.project_key,(SELECT COUNT(*) FROM messages um WHERE um.conversation_id=c.id) AS unread_count FROM conversations c WHERE c.project_key=? ORDER BY c.id LIMIT 200';
    $plain = $pdo->prepare($canonical); $plain->execute(['PRIVATE_project_token']); $expectedRows = $plain->fetchAll(PDO::FETCH_ASSOC); $plain->closeCursor(); $plain = null; $profile = null; $callbackCount = 0;
    $actualRows = ManagerReadProfile::capture($pdo, static function () use ($pdo, $canonical, &$callbackCount): array { ++$callbackCount; $statement=$pdo->prepare($canonical); $statement->execute(['PRIVATE_project_token']); return $statement->fetchAll(PDO::FETCH_ASSOC); }, $profile, true);
    readProfileCheck($prefix . 'canonical list and original callback execute exactly once without adding a second list measurement', $callbackCount === 1 && $actualRows === $expectedRows && $profile['statement_count'] === 1 && $profile['timed_execute_count'] === 1 && $profile['statements'][0]['category'] === 'manager_list');
    readProfileCheck($prefix . 'actual canonical query has a successful bounded sanitized explain plan', $profile['plan']['available'] === true && $profile['plan']['reason'] === null && is_numeric($profile['plan']['duration_ms']) && count($profile['plan']['tables']) > 0 && count($profile['plan']['tables']) <= 64);
    $allowedPlanFields = ['access','rows_examined_per_scan','rows_produced_per_join','filtered'];
    $allowedAccess = $driver === 'sqlite' ? ['scan','search','other'] : ['system','const','eq_ref','ref','fulltext','ref_or_null','index_merge','unique_subquery','index_subquery','range','index','all','other'];
    foreach ($profile['plan']['tables'] as $table) { readProfileCheck($prefix . 'plan node exposes only fixed access and numeric estimates', count(array_diff(array_keys($table), $allowedPlanFields)) === 0 && in_array($table['access'] ?? '',$allowedAccess,true) && !str_contains(json_encode($table, JSON_THROW_ON_ERROR), 'PRIVATE')); }
    $json = json_encode($profile, JSON_THROW_ON_ERROR);
    readProfileCheck($prefix . 'canonical explain discards literals, bound values, customer IDs, table aliases and conditions', !str_contains($json,'PRIVATE') && !str_contains($json,'81723491') && !str_contains($json,'81723492') && !str_contains($json,'SELECT') && !str_contains($json,'project_key') && !str_contains($json,'conversation_id'));

    $derived = 'SELECT c.id,c.project_key,(SELECT COUNT(*) FROM messages um WHERE um.conversation_id=c.id) AS unread_count FROM (SELECT c.id FROM conversations c WHERE c.project_key=? ORDER BY c.id LIMIT 200) inbox_page JOIN conversations c ON c.id=inbox_page.id ORDER BY c.id LIMIT 200';
    $profile = null; $callbackCount = 0;
    $derivedRows = ManagerReadProfile::capture($pdo, static function () use ($pdo,$derived,&$callbackCount): array { ++$callbackCount; $statement=$pdo->prepare($derived); $statement->execute(['PRIVATE_project_token']); return $statement->fetchAll(PDO::FETCH_ASSOC); }, $profile, true);
    readProfileCheck($prefix . 'actual derived All page preserves exact rows and receives one sanitized explain', $derivedRows === $expectedRows && $callbackCount === 1 && $profile['statement_count'] === 1 && $profile['timed_execute_count'] === 1 && $profile['statements'][0]['category'] === 'manager_list' && $profile['plan']['available'] === true && $profile['plan']['reason'] === null && count($profile['plan']['tables']) > 0 && count($profile['plan']['tables']) <= 64 && !str_contains(json_encode($profile,JSON_THROW_ON_ERROR),'PRIVATE'));

    $profile = null;
    ManagerReadProfile::capture($pdo, static function () use ($pdo, $canonical): void { $statement=$pdo->prepare($canonical); $statement->execute(['PRIVATE_project_token']); $statement->fetchAll(); }, $profile, false);
    readProfileCheck($prefix . 'explain opt-out is explicit and leaves the measured canonical list unchanged', $profile['plan']['available'] === false && $profile['plan']['reason'] === 'disabled' && $profile['statement_count'] === 1);

    $profile = null;
    ManagerReadProfile::capture($pdo, static function () use ($pdo, $canonical): void { $statement=$pdo->prepare($canonical); $statement->bindValue(1,'PRIVATE_project_token'); $statement->execute(); $statement->fetchAll(); }, $profile, true);
    readProfileCheck($prefix . 'uncaptured bound parameters never produce an invented plan', $profile['plan']['available'] === false && $profile['plan']['reason'] === 'parameters_unavailable');

    foreach ([$canonical . ' /* PRIVATE_comment */', $canonical . ';', str_replace(' FROM conversations', str_repeat(' ',65537) . ' FROM conversations',$canonical)] as $unsafeSql) {
        $profile = null;
        ManagerReadProfile::capture($pdo, static function () use ($pdo,$unsafeSql): void { $statement=$pdo->prepare($unsafeSql); $statement->execute(['PRIVATE_project_token']); $statement->fetchAll(); }, $profile, true);
        readProfileCheck($prefix . 'commented, delimited or oversized SQL is measured but never selected for explain', $profile['statement_count'] === 1 && $profile['timed_execute_count'] === 1 && $profile['plan']['available'] === false && $profile['plan']['reason'] === 'no_canonical_list_query' && !str_contains(json_encode($profile, JSON_THROW_ON_ERROR),'PRIVATE'));
    }

    // The retained statement intentionally stayed live for the stale observer
    // test. End its actual SQLite cursor before changing this synthetic schema:
    // an active read, even of another table, can hold a schema-level lock.
    $retained->closeCursor(); $retained = null;
    $readProfileStage = $driver . '_actual_explain_failure';
    $profile = null;
    $rowsBeforePlanError = ManagerReadProfile::capture($pdo, static function () use ($pdo, $canonical): array { $statement=$pdo->prepare($canonical); $statement->execute(['PRIVATE_project_token']); $rows=$statement->fetchAll(PDO::FETCH_ASSOC); $statement->closeCursor(); $pdo->exec('DROP TABLE messages'); return $rows; }, $profile, true);
    readProfileCheck($prefix . 'an actual explain failure reports a fixed safe reason without altering the callback result', $rowsBeforePlanError === $expectedRows && $profile['plan']['available'] === false && $profile['plan']['reason'] === 'plan_unavailable' && !str_contains(json_encode($profile, JSON_THROW_ON_ERROR), 'messages') && $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) === $original);

    $readProfileStage = $driver . '_nested_and_custom_connection';
    $nestedRejected = false; $nestedCallbackCalled = false; $profile = null;
    $outerResult = ManagerReadProfile::capture($pdo, static function () use ($pdo, &$nestedRejected, &$nestedCallbackCalled): int {
        $innerProfile = null;
        try { ManagerReadProfile::capture($pdo, static function () use (&$nestedCallbackCalled): void { $nestedCallbackCalled = true; }, $innerProfile, false); }
        catch (LogicException $error) { $nestedRejected = true; }
        return (int)$pdo->query('SELECT COUNT(*) FROM managers')->fetchColumn();
    }, $profile, false);
    readProfileCheck($prefix . 'nested capture is rejected before running its callback and outer capture stays usable', $nestedRejected && !$nestedCallbackCalled && $outerResult === 1 && $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) === $original);

    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ReadProfileOriginalStatement::class, ['fixture-custom-class']]);
    $customOriginal = $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    $profile = null;
    $callbackCount = 0;
    $customValue = ManagerReadProfile::capture($pdo, static function () use ($pdo, &$callbackCount): PDOStatement { ++$callbackCount; return $pdo->prepare('SELECT id FROM managers'); }, $profile, false);
    $after = $pdo->prepare('SELECT id FROM managers');
    readProfileCheck($prefix . 'custom statement class and constructor arguments are restored on the same non-persistent connection', $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) === $customOriginal && $after instanceof ReadProfileOriginalStatement && $after->marker === 'fixture-custom-class');
    readProfileCheck($prefix . 'custom connections are not replaced or falsely measured and their callback still runs once', $callbackCount === 1 && $customValue instanceof ReadProfileOriginalStatement && $customValue->marker === 'fixture-custom-class' && $profile['available'] === false && $profile['reason'] === 'unsupported_connection' && $profile['statements'] === [] && $profile['statement_count'] === 0 && $profile['timed_execute_count'] === 0 && is_numeric($profile['load_ms']));
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $original);
}

function readProfilePersistent(PDO $pdo, string $driver): void
{
    $original = $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS); $profile = null; $calls = 0;
    $result = ManagerReadProfile::capture($pdo, static function () use ($pdo, &$calls): int { ++$calls; return (int)$pdo->query('SELECT 1')->fetchColumn(); }, $profile, true);
    readProfileCheck($driver . ': persistent connections remain untouched and explicitly unmeasured', $calls === 1 && $result === 1 && $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) === $original && $profile['available'] === false && $profile['reason'] === 'unsupported_connection' && $profile['statement_count'] === 0 && $profile['timed_execute_count'] === 0 && $profile['statements'] === []);
}

try {
    $snapshotSource = (string)file_get_contents(dirname(__DIR__) . '/tools/production_snapshot.php');
    $publisherSource = (string)file_get_contents(dirname(__DIR__) . '/.github/workflows/publish-conversation-diagnostics.yml');
    readProfileCheck('snapshot profiling is a strict opt-in CLI flag and helper loading stays conditional', str_contains($snapshotSource, '$profileManagerLists=in_array(\'--profile-manager-lists\',$argv??[],true);') && str_contains($snapshotSource, 'if($profileManagerLists)require_once $baseDir.\'/tools/manager-read-profile.php\';'));
    foreach (['all','mine','waiting'] as $queue) {
        $call = 'ManagerConversationService::list($id,\'' . $queue . '\',200,\'*\')';
        $profileCall = 'if($profileManagerLists){$' . $queue . '=ManagerReadProfile::capture($pdo,static fn()=>' . $call . ',$' . $queue . 'Profile);}';
        $plainCall = 'else{$' . $queue . '=' . $call . ';}';
        readProfileCheck('snapshot ' . $queue . ' chooses one bounded canonical loader in mutually exclusive branches', substr_count($snapshotSource,$call) === 2 && str_contains($snapshotSource,$profileCall . "\n        " . $plainCall));
        readProfileCheck('snapshot ' . $queue . ' excludes explain work from the exposed list duration', str_contains($snapshotSource,'if($profileManagerLists)$' . $queue . 'Ms=$' . $queue . 'Profile[\'load_ms\'];'));
    }
    readProfileCheck('publisher manual profile input is boolean and defaults off', preg_match('/profile_manager_lists:\s*\n\s*description:[^\n]*\n\s*type: boolean\s*\n\s*required: false\s*\n\s*default: false/',$publisherSource) === 1);
    readProfileCheck('automatic diagnostics cannot enable profiling and existing private capture command is not duplicated', str_contains($publisherSource,'PROFILE_MANAGER_LISTS: ${{ github.event_name == \'workflow_dispatch\' && inputs.profile_manager_lists == true && \'true\' || \'false\' }}') && str_contains($publisherSource,"profile_arg=''" ) && str_contains($publisherSource,'if [ "$PROFILE_MANAGER_LISTS" = \'true\' ]; then profile_arg=\' --profile-manager-lists\'; fi') && substr_count($publisherSource,'php tools/production_snapshot.php') === 1);

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { throw new RuntimeException('SQLite PDO driver is required'); }
    $sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    readProfileFixture($sqlite, 'sqlite'); readProfileRun($sqlite, 'sqlite');
    readProfilePersistent(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT=>true]), 'sqlite');

    $dsn = getenv('MANAGER_READ_PROFILE_TEST_MYSQL_DSN');
    if (is_string($dsn) && $dsn !== '') {
        if (!preg_match('/\Amysql:host=(127\.0\.0\.1|localhost|mysql);(?:port=([0-9]{1,5});)?dbname=manager_read_profile_test;charset=utf8mb4\z/D', $dsn)) { throw new RuntimeException('Refusing non-ephemeral read profile database'); }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) { throw new RuntimeException('Configured native MySQL PDO driver is required'); }
        $pdo = new PDO($dsn, (string)(getenv('MANAGER_READ_PROFILE_TEST_MYSQL_USER') ?: 'root'), (string)(getenv('MANAGER_READ_PROFILE_TEST_MYSQL_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_PERSISTENT=>false]);
        if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'manager_read_profile_test' || (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() !== 0) { throw new RuntimeException('Refusing non-empty native read profile fixture'); }
        readProfileFixture($pdo, 'mysql'); readProfileRun($pdo, 'mysql');
        readProfilePersistent(new PDO($dsn, (string)(getenv('MANAGER_READ_PROFILE_TEST_MYSQL_USER') ?: 'root'), (string)(getenv('MANAGER_READ_PROFILE_TEST_MYSQL_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_PERSISTENT=>true]), 'mysql');
    } else { echo "SKIP native MySQL fixture is not configured locally; dedicated CI must configure it\n"; }
    echo 'Manager read profile: ' . $passed . " checks passed\n";
} catch (Throwable $error) {
    // A configured native gate fails; it never falls back or prints SQL/params.
    $safeState = $error instanceof PDOException && preg_match('/\A[A-Z0-9]{5}\z/D',(string)$error->getCode()) ? (string)$error->getCode() : 'none';
    fwrite(STDERR, 'Manager read profile regression failed: ' . ($error instanceof RuntimeException && str_starts_with($error->getMessage(), 'FAIL ') ? $error->getMessage() : 'fixture_or_capture_failure') . ' stage=' . $readProfileStage . ' class=' . ($error instanceof PDOException ? 'PDOException' : ($error instanceof LogicException ? 'LogicException' : 'Throwable')) . ' sqlstate=' . $safeState . "\n");
    exit(1);
}
