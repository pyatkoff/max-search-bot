<?php
declare(strict_types=1);

// Real recorder/SQLite, invocation service, router and logger. Only external
// model/context/send boundaries are substituted; no production connection.
$sourceRoot = $argv[1] ?? dirname(__DIR__, 2);
$passed = 0; $failed = 0;
function entryCheck(string $name, bool $ok): void {
    global $passed, $failed;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . 'entry: ' . $name . PHP_EOL;
    $ok ? $passed++ : $failed++;
}
function entryRows(PDO $pdo): array {
    return $pdo->query("SELECT * FROM conversation_events WHERE event_type='ai_invocation_entered' ORDER BY id")->fetchAll();
}
function entryInput(string $text, string $native, string $channel = 'telegram'): array {
    return ['platform'=>$channel, 'type'=>'message', 'text'=>$text, 'message_id'=>$native,
        'user'=>['external_user_id'=>'fixture-user', 'chat_id'=>'42']];
}
function entryPayload(array $row): array {
    return json_decode($row['payload_json'], true, 16, JSON_THROW_ON_ERROR);
}
function entryFrameIds(string $path): array {
    $raw = is_file($path) ? (string)file_get_contents($path) : '';
    preg_match_all('/^AI INVOCATION: (.+)$/m', $raw, $matches);
    return array_map(static fn(string $line) => json_decode($line, true, 16, JSON_THROW_ON_ERROR)['invocation_id'], $matches[1]);
}
class EntryFixturePDO extends PDO {
    public bool $failTransactionInspection = false;
    public function inTransaction(): bool {
        if ($this->failTransactionInspection) throw new RuntimeException('fixture transaction inspection failure');
        return parent::inTransaction();
    }
}

$tmp = sys_get_temp_dir() . '/ai-entry-fixture-' . bin2hex(random_bytes(8));
$logDir = $tmp . '-logs';
$oldLogDir = getenv('MAX_SEARCH_AI_LOG_DIR');
try {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) throw new RuntimeException('pdo_sqlite required');
    foreach (['HOST','NAME','USER','PASS'] as $name) define('CONVERSATION_DB_' . $name, 'fixture');
    require_once $sourceRoot . '/services/ConversationRecorder.php';
    ProjectConfig::resetForTests(['id'=>'anytour']);
    $pdo = new EntryFixturePDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => '2000-01-01 00:00:00');
    $pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,is_active INTEGER);
        INSERT INTO projects VALUES(1,'anytour',1);
        CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,project_id INTEGER,source_key TEXT,channel TEXT,is_active INTEGER);
        INSERT INTO conversation_sources VALUES(1,1,'telegram:default','telegram',1),(2,1,'max:default','max',1),(3,1,'website:default','website',1);
        CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT);
        INSERT INTO customers VALUES(1,'Synthetic');
        CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT,username TEXT);
        INSERT INTO customer_channels VALUES(2,1,'anytour','telegram','fixture-user','42',''),(3,1,'anytour','max','fixture-user','42',''),(4,1,'anytour','website','fixture-user','42','');
        CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,external_chat_id TEXT,status TEXT,last_message_at TEXT);
        INSERT INTO conversations VALUES(10,1,2,'anytour',1,'telegram','42','ai',NULL),(11,1,3,'anytour',2,'max','42','ai',NULL),(12,1,4,'anytour',3,'website','42','ai',NULL);
        CREATE TABLE messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,direction TEXT,sender_type TEXT,sender_id TEXT,channel TEXT,external_message_id TEXT,text TEXT CHECK(text<>'reject-insert-fixture'),metadata_json TEXT);
        CREATE TABLE conversation_events(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,event_type TEXT,actor_type TEXT,payload_json TEXT,created_at TEXT DEFAULT '2000-01-01 00:00:00');
        CREATE TABLE business_sentinel(value TEXT);");
    (new ReflectionProperty(ConversationDb::class, 'pdo'))->setValue(null, $pdo);
    foreach ([RoutingAccessService::class, ProjectAccessService::class] as $class) {
        (new ReflectionProperty($class, 'schemaReady'))->setValue(null, true);
    }
    mkdir($tmp . '/services', 0700, true); mkdir($tmp . '/ai', 0700); mkdir($tmp . '/handlers', 0700); mkdir($logDir, 0700);
    foreach (['services/AiInvocationService.php','services/AiRuntimeLogger.php','ai/AiRouter.php','handlers/AiMessageHandler.php'] as $path) {
        if (!copy($sourceRoot . '/' . $path, $tmp . '/' . $path)) throw new RuntimeException('fixture copy failed');
    }
    file_put_contents($tmp . '/ai/AiClient.php', <<<'PHP'
<?php
class AiClient {
    public static array $calls = [];
    public static bool $throws = false;
    public static function requestJson(string $instructions, array $payload): array {
        self::$calls[] = ['instructions'=>$instructions, 'payload'=>$payload];
        if (self::$throws) throw new RuntimeException('fixture model error');
        return ['intent'=>'question', 'parameters'=>[], 'reply'=>'fixture reply'];
    }
}
PHP);
    file_put_contents($tmp . '/services/SourceHandlingService.php', <<<'PHP'
<?php
class SourceHandlingService {
    public static bool $throws = false;
    public static function sourcePostContext($chat): array {
        if (self::$throws) throw new RuntimeException('fixture context error');
        return [];
    }
    public static function recentDialogueContext($chat): array { return []; }
}
PHP);
    file_put_contents($tmp . '/services/WebsitePageContextService.php', <<<'PHP'
<?php
class WebsitePageContextService {
    public static $onRead = null;
    public static function forChat(int $chat, ?string &$outcome = null): array {
        if (self::$onRead !== null) (self::$onRead)();
        $outcome = 'not_found'; return [];
    }
}
PHP);
    // Execute an unchanged deterministic handler branch, not a substitute invoke.
    $handler = (string)file_get_contents($tmp . '/handlers/AiMessageHandler.php');
    preg_match_all("/require_once\(__DIR__ \\. '([^']+)'\);/", $handler, $requires);
    foreach ($requires[1] as $relative) {
        $path = $tmp . '/handlers/' . $relative;
        if (!is_file($path)) file_put_contents($path, "<?php\n");
    }
    file_put_contents($tmp . '/services/MissingFieldQuestionService.php', <<<'PHP'
<?php
class MissingFieldQuestionService {
    public static array $sent = [];
    public static function send($chat, string $field, array $options): void { self::$sent[] = [$chat,$field,$options]; }
    public static function sendText($chat, string $text): void { self::$sent[] = [$chat,$text]; }
}
PHP);
    file_put_contents($tmp . '/services/DepartureSuggestionService.php', <<<'PHP'
<?php
class DepartureSuggestionService {
    public static function forChat($chat, array $current, string $platform): array { return []; }
}
PHP);
    class MaxSearchApi {
        public static int $handlerEntries = 0;
        public static function funnelLog($chat, $event, $data): void { self::$handlerEntries++; }
        public static function getAiSearchContext($chat): array { return []; }
        public static function getAiMissingFields($chat): array { return ['country']; }
    }
    putenv('MAX_SEARCH_AI_LOG_DIR=' . $logDir);
    require $tmp . '/handlers/AiMessageHandler.php';
    require $tmp . '/services/WebsitePageContextService.php';
    $expected = ['intent'=>'question','parameters'=>[],'reply'=>'fixture reply'];
    $noAi = entryInput('не знаю', 'native-no-ai');
    entryCheck('canonical inbound is persisted', ConversationRecorder::inbound($noAi));
    AiMessageHandler::handle(['text'=>'не знаю','_platform'=>'telegram'], 42);
    entryCheck('actual handler early return records no service entry or model call',
        MaxSearchApi::$handlerEntries === 1 && count(MissingFieldQuestionService::$sent) === 1
        && entryRows($pdo) === [] && AiClient::$calls === []);

    $first = entryInput('first synthetic input', 'native-first');
    entryCheck('first invoking input is persisted', ConversationRecorder::inbound($first));
    $firstId = (string)$pdo->lastInsertId();
    $entryBeforeContext = false;
    WebsitePageContextService::$onRead = static function() use ($pdo, &$entryBeforeContext): void { $entryBeforeContext = count(entryRows($pdo)) === 1; };
    $beforeClock = time();
    $current = ['country'=>'Россия'];
    $result = AiInvocationService::invoke('SHORT_AI', 42, $first['text'], $current);
    $rows = entryRows($pdo); $payload = $rows ? entryPayload($rows[0]) : [];
    entryCheck('entry is persisted before the first context read', $entryBeforeContext);
    entryCheck('entry joins exact inbound row and conversation', count($rows) === 1 && (int)$rows[0]['conversation_id'] === 10 && ($payload['input_message_id'] ?? null) === $firstId);
    entryCheck('entry has strict metadata-only shape and UTC recording receipt',
        array_keys($payload) === ['schema','invocation_id','input_message_id','input_kind','input_channel','_recorded_clock']
        && $payload['schema'] === 'anytour.ai_invocation_entry.v1' && $rows[0]['actor_type'] === 'system'
        && $payload['input_kind'] === 'message' && $payload['input_channel'] === 'telegram'
        && ConversationRecordClock::read($rows[0]['payload_json']) >= $beforeClock
        && ConversationRecordClock::read($rows[0]['payload_json']) <= time());
    $ids = entryFrameIds(AiRuntimeLogger::debugFile(false));
    entryCheck('entry and existing input/result frames share only this invocation ID', count($ids) === 2 && $ids === [($payload['invocation_id'] ?? ''), ($payload['invocation_id'] ?? '')]);
    entryCheck('observer preserves model payload, reply and canonical current',
        $result === $expected && count(AiClient::$calls) === 1 && AiClient::$calls[0]['payload'] === ['message'=>$first['text'],'current'=>$current]
        && $current === ['country'=>'Россия'] && !str_contains(json_encode(AiClient::$calls), 'input_message_id'));
    WebsitePageContextService::$onRead = null;

    $second = entryInput('second synthetic input', 'native-second'); ConversationRecorder::inbound($second);
    $secondId = (string)$pdo->lastInsertId();
    AiInvocationService::invoke('RICH_AI', 42, $second['text'], []);
    $rows = entryRows($pdo); $secondPayload = count($rows) > 1 ? entryPayload($rows[1]) : [];
    entryCheck('successive inputs in the same chat retain distinct exact links',
        count($rows) === 2 && $firstId !== $secondId && ($secondPayload['input_message_id'] ?? null) === $secondId
        && ($payload['invocation_id'] ?? null) !== ($secondPayload['invocation_id'] ?? null));
    $beforeDuplicate = count($rows);
    ConversationRecorder::inbound($second);
    AiInvocationService::invoke('SHORT_AI', 42, 'duplicate should remain unlinked', []);
    entryCheck('duplicate input cannot borrow the preceding context', count(entryRows($pdo)) === $beforeDuplicate);

    foreach (['invalid_input','invalid_project','wrong_chat','wrong_project','silent_insert_failure'] as $mode) {
        ConversationRecorder::inbound(entryInput('ready for ' . $mode, 'native-ready-' . $mode));
        $count = count(entryRows($pdo)); $chat = 42;
        if ($mode === 'invalid_input') ConversationRecorder::inbound([]);
        if ($mode === 'invalid_project') ConversationRecorder::inboundForProject('INVALID', $first);
        if ($mode === 'wrong_chat') $chat = 43;
        if ($mode === 'wrong_project') ProjectConfig::resetForTests(['id'=>'other']);
        if ($mode === 'silent_insert_failure') {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
            ConversationRecorder::inbound(entryInput('reject-insert-fixture', 'native-rejected'));
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        $calls = count(AiClient::$calls);
        $reply = AiInvocationService::invoke('SHORT_AI', $chat, 'diagnostic omission', []);
        entryCheck($mode . ' cannot create a false input link or change the reply', count(entryRows($pdo)) === $count && $reply === $expected && count(AiClient::$calls) === $calls + 1);
        ProjectConfig::resetForTests(['id'=>'anytour']);
    }
    ConversationRecorder::inbound(entryInput('ready before caller transaction', 'native-before-transaction'));
    $count = count(entryRows($pdo)); $calls = count(AiClient::$calls);
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO business_sentinel VALUES('caller-owned')");
    $reply = AiInvocationService::invoke('SHORT_AI', 42, 'active transaction fixture', []);
    entryCheck('active caller transaction skips the diagnostic write and preserves its work',
        count(entryRows($pdo)) === $count && $pdo->inTransaction()
        && $pdo->query('SELECT value FROM business_sentinel')->fetchColumn() === 'caller-owned'
        && $reply === $expected && count(AiClient::$calls) === $calls + 1);
    $pdo->rollBack();
    entryCheck('observer leaves transaction ownership and rollback with its caller', (int)$pdo->query('SELECT COUNT(*) FROM business_sentinel')->fetchColumn() === 0);

    $pdo->beginTransaction();
    $mirrored = ConversationRecorder::inbound(entryInput('uncommitted input fixture', 'native-uncommitted'));
    $uncommittedId = (string)$pdo->lastInsertId();
    $wizardReceipt = ConversationRecorder::wizardValueApplied(42, 'adults', '2');
    entryCheck('uncommitted inbound keeps existing mirror and wizard receipt semantics', $mirrored && $wizardReceipt && $pdo->inTransaction());
    $pdo->rollBack();
    $count = count(entryRows($pdo));
    $reply = AiInvocationService::invoke('SHORT_AI', 42, 'after inbound rollback', []);
    $row = $pdo->prepare('SELECT id FROM messages WHERE id=?'); $row->execute([$uncommittedId]);
    entryCheck('rolled-back inbound cannot become stale provenance after transaction ends',
        count(entryRows($pdo)) === $count && $row->fetchColumn() === false && $reply === $expected);

    $count = count(entryRows($pdo));
    $pdo->failTransactionInspection = true;
    $mirrored = ConversationRecorder::inbound(entryInput('inspection failure fixture', 'native-inspection-failure'));
    $wizardReceipt = ConversationRecorder::wizardValueApplied(42, 'adults', '3');
    $pdo->failTransactionInspection = false;
    $reply = AiInvocationService::invoke('SHORT_AI', 42, 'inspection failure fixture', []);
    entryCheck('failed readiness observation leaves mirror and wizard behavior unchanged',
        $mirrored && $wizardReceipt && count(entryRows($pdo)) === $count && $reply === $expected);

    foreach (['max','website'] as $channel) {
        ConversationRecorder::inbound(entryInput('channel fixture', 'native-' . $channel, $channel));
        AiInvocationService::invoke('SHORT_AI', 42, 'channel fixture', []);
        $rows = entryRows($pdo); $last = $rows ? entryPayload(end($rows)) : [];
        entryCheck($channel . ' input preserves its finite channel provenance', ($last['input_channel'] ?? null) === $channel);
    }
    $callback = entryInput('', 'native-callback'); $callback['type'] = 'callback'; $callback['callback_data'] = 'fixture_callback';
    ConversationRecorder::inbound($callback); AiInvocationService::invoke('SHORT_AI', 42, 'callback fixture', []);
    $rows = entryRows($pdo); $last = $rows ? entryPayload(end($rows)) : [];
    entryCheck('callback input is explicitly classified', ($last['input_kind'] ?? null) === 'callback');

    ConversationRecorder::inbound(entryInput('missing native id fixture', ''));
    $idWithoutNative = (string)$pdo->lastInsertId(); AiInvocationService::invoke('SHORT_AI', 42, 'missing native id fixture', []);
    $rows = entryRows($pdo); $last = $rows ? entryPayload(end($rows)) : [];
    entryCheck('missing native ID retains only the actual mirrored input identity',
        ($last['input_message_id'] ?? null) === $idWithoutNative
        && $pdo->query('SELECT external_message_id FROM messages ORDER BY id DESC LIMIT 1')->fetchColumn() === null);

    ConversationRecorder::inbound(entryInput('lost log fixture', 'native-lost-log'));
    $count = count(entryRows($pdo));
    putenv('MAX_SEARCH_AI_LOG_DIR=' . $tmp . '/unsafe-public-log');
    $reply = AiInvocationService::invoke('SHORT_AI', 42, 'lost log fixture', []);
    entryCheck('unavailable actual AI logger still leaves an independent database entry', $reply === $expected && AiRuntimeLogger::debugFile(false) === '' && count(entryRows($pdo)) === $count + 1 && !is_dir($tmp . '/unsafe-public-log'));
    putenv('MAX_SEARCH_AI_LOG_DIR=' . $logDir);
    ConversationRecorder::inbound(entryInput('context failure fixture', 'native-context-failure'));
    $count = count(entryRows($pdo)); $calls = count(AiClient::$calls); $frames = count(entryFrameIds(AiRuntimeLogger::debugFile(false)));
    SourceHandlingService::$throws = true; $contextThrew = false;
    try { AiInvocationService::invoke('SHORT_AI', 42, 'context failure fixture', []); }
    catch (RuntimeException $e) { $contextThrew = $e->getMessage() === 'fixture context error'; }
    SourceHandlingService::$throws = false;
    entryCheck('pre-input context failure preserves its original throw and entry witness',
        $contextThrew && count(entryRows($pdo)) === $count + 1 && count(AiClient::$calls) === $calls
        && count(entryFrameIds(AiRuntimeLogger::debugFile(false))) === $frames);

    ConversationRecorder::inbound(entryInput('null correlation fixture', 'native-null-id'));
    $entropySource = (string)file_get_contents($tmp . '/services/AiInvocationService.php');
    $entropyPreamble = <<<'PHP'
<?php
namespace AiEntryEntropyFixture;
use \AiRouter;
use \AiRuntimeLogger;
use \SourceHandlingService;
use \WebsitePageContextService;
function random_bytes(int $length): string { throw new \RuntimeException('fixture entropy failure'); }
PHP;
    file_put_contents($tmp . '/services/AiEntryEntropyFixture.php', $entropyPreamble . "\n" . substr($entropySource, 5));
    require $tmp . '/services/AiEntryEntropyFixture.php';
    $nullReply = \AiEntryEntropyFixture\AiInvocationService::invoke('SHORT_AI', 42, 'null correlation fixture', []);
    $rows = entryRows($pdo); $last = $rows ? entryPayload(end($rows)) : [];
    entryCheck('missing entropy can retain an explicitly unlinked entry', $nullReply === $expected && array_key_exists('invocation_id', $last) && $last['invocation_id'] === null);
    $count = count($rows);
    $invalid = method_exists(ConversationRecorder::class, 'aiInvocationEntered') && ConversationRecorder::aiInvocationEntered(42, 'invalid');
    entryCheck('malformed invocation identity is not persisted', !$invalid && count(entryRows($pdo)) === $count);
    // Use this candidate's real collector even for the optional old-producer
    // negative control. It must admit actual recorder bytes, not hand-built JSON.
    copy(dirname(__DIR__, 2) . '/services/AiInvocationEntrySnapshot.php', $tmp . '/services/AiInvocationEntrySnapshot.php');
    foreach (['ConversationDb.php','ConversationRecordClock.php'] as $dependency) {
        file_put_contents($tmp . '/services/' . $dependency, '<?php require_once ' . var_export($sourceRoot . '/services/' . $dependency, true) . ';');
    }
    require $tmp . '/services/AiInvocationEntrySnapshot.php';
    $collected = AiInvocationEntrySnapshot::collect(null, $pdo);
    $firstCollected = array_values(array_filter($collected['records'], static fn(array $record): bool => $record['input_message_id'] === $firstId));
    $withoutNative = array_values(array_filter($collected['records'], static fn(array $record): bool => $record['input_message_id'] === $idWithoutNative));
    entryCheck('real collector admits recorder payload and joins the same invocation/input',
        $collected['available'] === true && $collected['invalid_rows'] === 0 && count($firstCollected) === 1
        && $firstCollected[0]['input_link'] === 'retained_inbound' && $firstCollected[0]['native_id_present'] === true
        && $firstCollected[0]['invocation_id'] === ($payload['invocation_id'] ?? null));
    entryCheck('real collector keeps absent native ID explicit', count($withoutNative) === 1 && $withoutNative[0]['native_id_present'] === false);
    ConversationRecorder::inbound(entryInput('database failure fixture', 'native-db-failure'));
    $pdo->exec('DROP TABLE conversation_events');
    foreach ([false,true] as $throws) {
        AiClient::$throws = $throws; $calls = count(AiClient::$calls);
        $reply = AiInvocationService::invoke('SHORT_AI', 42, 'database failure fixture', []);
        entryCheck('failed event write preserves ' . ($throws ? 'model exception outcome' : 'successful model result'),
            $reply === ($throws ? ['_error'=>true] : $expected) && count(AiClient::$calls) === $calls + 1);
    }
} catch (Throwable $e) {
    entryCheck('fixture completed', false);
    echo 'Entry fixture error: ' . $e->getMessage() . PHP_EOL;
} finally {
    $oldLogDir === false ? putenv('MAX_SEARCH_AI_LOG_DIR') : putenv('MAX_SEARCH_AI_LOG_DIR=' . $oldLogDir);
    foreach ([$tmp,$logDir] as $directory) {
        if (!is_dir($directory)) continue;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($directory);
    }
}
echo 'Entry fixture total ' . ($passed + $failed) . ', passed ' . $passed . ', failed ' . $failed . PHP_EOL;
exit($failed ? 1 : 0);
