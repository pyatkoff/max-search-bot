<?php
declare(strict_types=1);

// Actual native MySQL only, in an explicitly empty loopback fixture schema.
// No application configuration, credentials, production connections or sends.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dsn = getenv('MANAGER_INBOX_QUERY_TEST_MYSQL_DSN');
if (!is_string($dsn) || !preg_match('/\Amysql:host=(127\.0\.0\.1|localhost|mysql);(?:port=([0-9]{1,5});)?dbname=manager_inbox_query_page_test;charset=utf8mb4\z/D', $dsn)) {
    fwrite(STDERR, "Explicit ephemeral native inbox fixture configuration is required\n"); exit(1);
}
$user = getenv('MANAGER_INBOX_QUERY_TEST_MYSQL_USER');
$password = getenv('MANAGER_INBOX_QUERY_TEST_MYSQL_PASSWORD');
if (!is_string($user) || $user === '' || !is_string($password)) { fwrite(STDERR, "Explicit native fixture credentials are required\n"); exit(1); }

require_once dirname(__DIR__, 2) . '/services/ManagerConversationService.php';
require_once dirname(__DIR__, 2) . '/services/MigrationRunner.php';

final class InboxPageNativePdo extends PDO
{
    public int $executeCount = 0;
    public int $queryCount = 0;
    public ?string $listSql = null;
    public ?array $listArgs = null;
    public float $listExecuteMs = 0.0;

    public function resetEvidence(): void
    {
        $this->executeCount = $this->queryCount = 0;
        $this->listSql = null; $this->listArgs = null; $this->listExecuteMs = 0.0;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        ++$this->queryCount;
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

final class InboxPageNativeStatement extends PDOStatement
{
    protected function __construct(private InboxPageNativePdo $database) {}

    public function execute(?array $params = null): bool
    {
        ++$this->database->executeCount;
        $isList = str_starts_with($this->queryString, 'SELECT c.id,c.project_key');
        if ($isList) { $this->database->listSql = $this->queryString; $this->database->listArgs = $params; }
        $started = hrtime(true);
        try { return parent::execute($params); }
        finally { if ($isList) $this->database->listExecuteMs += (hrtime(true) - $started) / 1e6; }
    }
}

$passed = 0;
function inboxNativeCheck(string $label, bool $condition): void
{
    global $passed;
    if (!$condition) throw new RuntimeException('FAIL ' . $label);
    ++$passed; echo 'PASS ' . $label . "\n";
}

/** Load only the pinned, repository-owned production source under a test name. */
function inboxNativeBaseline(): void
{
    $path = getenv('MANAGER_INBOX_QUERY_BASELINE_FILE');
    if (!is_string($path) || !is_file($path) || !is_readable($path)) throw new RuntimeException('baseline_missing');
    $source = (string)file_get_contents($path);
    if (hash('sha256', $source) !== 'ecbd74848d10d0c4c3d91fcd8a38af63b490ee3793c6e9b1e5e987f23140e636') throw new RuntimeException('baseline_identity_mismatch');
    $source = str_replace('class ManagerConversationService', 'class BaselineManagerConversationService', $source, $replacements);
    if ($replacements !== 1) throw new RuntimeException('baseline_class_mismatch');
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) { $code .= $token; continue; }
        if ($token[0] === T_OPEN_TAG) continue;
        $code .= $token[0] === T_DIR ? var_export(dirname(__DIR__, 2) . '/services', true) : $token[1];
    }
    eval($code);
}

function inboxNativeRun(InboxPageNativePdo $pdo, string $class, array $arguments): array
{
    $pdo->resetEvidence(); $metadata = null;
    $started = hrtime(true);
    $callArguments = $arguments; $callArguments[] =& $metadata;
    $rows = $class::list(...$callArguments);
    $elapsed = (hrtime(true) - $started) / 1e6;
    return ['rows'=>$rows,'metadata'=>$metadata,'ms'=>$elapsed,'sql_count'=>$pdo->executeCount + $pdo->queryCount,
        'list_execute_ms'=>$pdo->listExecuteMs,'sql'=>$pdo->listSql,'args'=>$pdo->listArgs];
}

function inboxNativePair(InboxPageNativePdo $pdo, string $label, array $arguments): array
{
    $before = inboxNativeRun($pdo, BaselineManagerConversationService::class, $arguments);
    $after = inboxNativeRun($pdo, ManagerConversationService::class, $arguments);
    inboxNativeCheck($label . ' preserves every returned field and candidate metadata', $before['rows'] === $after['rows'] && $before['metadata'] === $after['metadata']);
    inboxNativeCheck($label . ' preserves the bounded number of SQL executions', $before['sql_count'] === $after['sql_count']);
    return [$before, $after];
}

function inboxNativeReadState(PDO $pdo): array
{
    return $pdo->query('SELECT manager_id,conversation_id,last_read_message_id FROM manager_conversation_reads ORDER BY manager_id,conversation_id')->fetchAll();
}

/** Ordinary EXPLAIN only; expose counts/estimates, never SQL or plan conditions. */
function inboxNativePlan(PDO $pdo, string $sql, array $args): array
{
    $q = $pdo->prepare('EXPLAIN FORMAT=JSON ' . $sql); $q->execute($args);
    $raw = json_decode((string)$q->fetchColumn(), true, 64, JSON_THROW_ON_ERROR); $q->closeCursor();
    $summary = ['nodes'=>0,'access'=>[],'max_estimated_rows'=>0.0,'latest_message_lookups'=>0,'latest_row_joins'=>0];
    $walk = function (array $node) use (&$walk, &$summary): void {
        if (isset($node['table']) && is_array($node['table'])) {
            ++$summary['nodes'];
            if (($node['table']['table_name'] ?? '') === 'mm') ++$summary['latest_message_lookups'];
            if (($node['table']['table_name'] ?? '') === 'last_message') ++$summary['latest_row_joins'];
            $access = strtolower((string)($node['table']['access_type'] ?? 'other'));
            if (!in_array($access, ['system','const','eq_ref','ref','fulltext','ref_or_null','index_merge','unique_subquery','index_subquery','range','index','all'], true)) $access = 'other';
            $summary['access'][$access] = ($summary['access'][$access] ?? 0) + 1;
            $summary['max_estimated_rows'] = max($summary['max_estimated_rows'], (float)($node['table']['rows_examined_per_scan'] ?? 0));
        }
        foreach ($node as $child) if (is_array($child)) $walk($child);
    };
    $walk($raw); ksort($summary['access']); return $summary;
}

/** Actual logical key reads for only the captured native list statement. */
function inboxNativeKeyReads(PDO $pdo,string $sql,array $args): array
{
    $snapshot=static fn():int=>(int)$pdo->query("SHOW SESSION STATUS LIKE 'Handler_read_key'")->fetch(PDO::FETCH_NUM)[1];
    $before=$snapshot();$q=$pdo->prepare($sql);$q->execute($args);$rows=$q->fetchAll();$q->closeCursor();
    return ['handler_read_key'=>$snapshot()-$before,'rows'=>$rows];
}

function inboxNativeMedian(array $values): float
{
    sort($values, SORT_NUMERIC); $n = count($values);
    return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

try {
    $pdo = new InboxPageNativePdo($dsn, $user, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'manager_inbox_query_page_test'
        || (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() !== 0
        || (int)$pdo->query('SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema=DATABASE()')->fetchColumn() !== 0
        || (int)$pdo->query('SELECT COUNT(*) FROM information_schema.events WHERE event_schema=DATABASE()')->fetchColumn() !== 0) throw new RuntimeException('nonempty_fixture_refused');
    // This exact-name/empty-schema guard precedes every DDL/data fixture write.
    inboxNativeBaseline();
    $pdo->exec("SET time_zone='+00:00'");
    $pdo->exec("SET timestamp=UNIX_TIMESTAMP('2026-10-15 12:00:00')");
    $runner = new MigrationRunner(dirname(__DIR__, 2) . '/migrations', $pdo);
    $splitter = new ReflectionMethod(MigrationRunner::class, 'splitStatements');
    foreach ($splitter->invoke($runner, (string)file_get_contents(dirname(__DIR__, 2) . '/migrations/001_conversation_core.sql')) as $sql) $pdo->exec($sql);
    // Retain the production core tables/indexes above; only later read-model
    // columns and minimal supporting tables are added for actual service.list.
    $pdo->exec("ALTER TABLE managers ADD role VARCHAR(16) NOT NULL DEFAULT 'manager',ADD is_working TINYINT NOT NULL DEFAULT 1");
    $pdo->exec("ALTER TABLE conversations ADD source_id BIGINT UNSIGNED NULL,ADD entry_channel VARCHAR(64) NULL,ADD attribution_region VARCHAR(32) NULL,ADD attribution_campaign VARCHAR(32) NULL,ADD lead_stage_key VARCHAR(32) NOT NULL DEFAULT 'new',ADD is_test TINYINT NOT NULL DEFAULT 0,ADD KEY idx_conversations_lead_stage(lead_stage_key,last_message_at),ADD KEY idx_conversations_is_test(is_test)");
    foreach ([
        'projects(id INT PRIMARY KEY,project_key VARCHAR(64) COLLATE utf8mb4_unicode_ci UNIQUE,display_name VARCHAR(64),is_active TINYINT)',
        'manager_projects(manager_id BIGINT UNSIGNED,project_id INT,PRIMARY KEY(manager_id,project_id))',
        'manager_customer_names(customer_id BIGINT UNSIGNED PRIMARY KEY,working_name VARCHAR(64),version INT)',
        'conversation_sources(id BIGINT UNSIGNED PRIMARY KEY,display_name VARCHAR(64),primary_group_id INT,fallback_mode VARCHAR(16),fallback_group_id INT,fallback_after_minutes INT,is_active TINYINT)',
        'manager_group_members(manager_id BIGINT UNSIGNED,group_id INT,PRIMARY KEY(manager_id,group_id))',
        'manager_conversation_reads(manager_id BIGINT UNSIGNED,conversation_id BIGINT UNSIGNED,last_read_message_id BIGINT UNSIGNED,PRIMARY KEY(manager_id,conversation_id),KEY idx_manager_reads_conversation(conversation_id,manager_id))',
        'lead_stages(stage_key VARCHAR(32) PRIMARY KEY,display_name VARCHAR(64),color VARCHAR(16),sort_order INT,is_active TINYINT,is_terminal TINYINT,is_won TINYINT)',
        'lead_tags(id BIGINT UNSIGNED PRIMARY KEY,tag_key VARCHAR(32),display_name VARCHAR(64),color VARCHAR(16),sort_order INT,is_active TINYINT)',
        'conversation_lead_tags(conversation_id BIGINT UNSIGNED,tag_id BIGINT UNSIGNED,PRIMARY KEY(conversation_id,tag_id),KEY idx_conversation_lead_tags_tag(tag_id,conversation_id))',
        'lead_stage_history(id BIGINT UNSIGNED PRIMARY KEY,conversation_id BIGINT UNSIGNED,created_at DATETIME,KEY idx_lead_stage_history_conversation(conversation_id,id))',
    ] as $table) $pdo->exec('CREATE TABLE ' . $table . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec("INSERT INTO managers(id,login,display_name,email,is_active,is_working,role) VALUES(7,'native7','Synthetic manager','',1,1,'manager'),(8,'native8','Other synthetic manager','',1,1,'manager'),(90,'native90','Synthetic admin','',1,1,'admin');
        INSERT INTO projects VALUES(1,'native-inbox','Synthetic inbox',1),(2,'denied-project','Denied project',1),(3,'inactive-project','Inactive project',0);
        INSERT INTO manager_projects VALUES(7,1),(7,3),(90,1);
        INSERT INTO customers(id,display_name) VALUES(1,'Synthetic customer');
        INSERT INTO manager_customer_names VALUES(1,'Working synthetic customer',3);
        INSERT INTO conversation_sources VALUES(1,'Unrestricted',0,'none',0,0,1),(2,'Group restricted',101,'none',0,0,1);
        INSERT INTO manager_group_members VALUES(7,101);
        INSERT INTO lead_stages VALUES('new','New','blue',1,1,0,0),('quote','Quote','green',2,1,0,0);
        INSERT INTO lead_tags VALUES(1,'tag1','Synthetic tag','blue',1,1)");
    $conversation = $pdo->prepare('INSERT INTO conversations(id,customer_id,project_key,source_id,channel,status,lead_stage_key,manager_id,started_at,last_message_at,is_test) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $message = $pdo->prepare('INSERT INTO messages(id,conversation_id,direction,sender_type,channel,text,metadata_json,created_at) VALUES(?,?,?,?,?,?,?,?)');
    $event = $pdo->prepare("INSERT INTO conversation_events(conversation_id,event_type,created_at) VALUES(?,'waiting_manager',?)");
    $read = $pdo->prepare('INSERT INTO manager_conversation_reads VALUES(7,?,?)');
    $tag = $pdo->prepare('INSERT INTO conversation_lead_tags VALUES(?,1)');
    $fixtures = []; $base = strtotime('2026-10-12 00:00:00 UTC');
    $pdo->beginTransaction();
    for ($id = 1; $id <= 660; ++$id) {
        $at = gmdate('Y-m-d H:i:s', $base + $id * 60);
        $project = $id >= 601 && $id <= 620 ? 'denied-project' : 'native-inbox';
        if ($id === 659) $project = 'inactive-project';
        $owner = $id >= 621 && $id <= 640 ? 8 : 7;
        $status = $id >= 641 && $id <= 650 ? 'closed' : ($id >= 581 && $id <= 600 ? 'ai' : 'manager');
        $isTest = $id === 658 ? 1 : 0;
        $source = $id % 13 === 0 ? 2 : 1;
        $channel = ['max','telegram','website'][$id % 3];
        $stage = $id % 2 ? 'new' : 'quote';
        $last = $id % 17 === 0 ? null : gmdate('Y-m-d H:i:s', $base + $id * 60 + 30);
        $conversation->execute([$id,1,$project,$source,$channel,$status,$stage,$owner,$at,$last,$isTest]);
        $mode = $id <= 360 ? $id % 10 : 2;
        $pending = $status === 'manager' && in_array($mode, [0,1,3,4,5,7], true);
        $fixtures[$id] = compact('id','project','owner','status','isTest','source','channel','stage','at','last','pending');
        for ($part = 1; $part <= 30; ++$part) {
            $direction = 'outbound'; $sender = 'ai'; $meta = '{}'; $created = gmdate('Y-m-d H:i:s', $base + $id * 60 + $part); $text = 'Synthetic message ' . $part;
            if ($part === 1) { $direction = 'inbound'; $sender = 'customer'; $meta = '{"type":"message"}'; }
            if ($part === 28) $sender = 'manager';
            if ($part === 29) {
                $direction = 'inbound'; $sender = 'customer';
                $meta = match ($mode) { 0,7,8=>' {"type":"message"}',3=>null,4=>'malformed historical metadata',5=>'{"type":"message","attachments":[{"type":"photo"}]}',default=>'{"type":"callback"}' };
                if ($mode === 5) $text = '';
                if ($mode === 7) $created = gmdate('Y-m-d H:i:s', $base + $id * 60 - 20);
                if ($mode === 8) { $direction = 'outbound'; $sender = 'manager'; }
            }
            if ($part === 30) {
                if ($mode === 6) { $sender = 'manager'; $meta = '{"attachments":[{"type":"photo"}]}'; $text = ''; }
                else { $direction = 'inbound'; $sender = 'customer'; $meta = '{"type":"bot_started"}'; }
            }
            $message->execute([$id * 100 + $part,$id,$direction,$sender,$channel,$text,$meta,$created]);
        }
        $event->execute([$id,$mode === 1 ? gmdate('Y-m-d H:i:s', $base + $id * 60 + 31) : $at]);
        if ($id % 5 === 0) $read->execute([$id,$id * 100 + 29]);
        if ($id % 4 === 0) $tag->execute([$id]);
    }
    $pdo->commit();
    // A missing customer must be excluded by the original JOIN before the cap.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec("INSERT INTO conversations(id,customer_id,project_key,source_id,channel,status,manager_id,started_at,last_message_at) VALUES(999,99999,'native-inbox',1,'max','manager',7,'2026-10-14 00:00:00','2026-10-14 00:00:01')");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    (new ReflectionProperty(ConversationDb::class, 'pdo'))->setValue(null, $pdo);
    ProjectConfig::resetForTests(['id'=>'native-inbox','brand'=>['name'=>'Synthetic inbox']]);
    ProjectAccessService::initializeReadOnly();
    foreach ([ManagerAuthService::class, RoutingAccessService::class] as $class) (new ReflectionProperty($class, 'schemaReady'))->setValue(null, true);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [InboxPageNativeStatement::class, [$pdo]]);
    $initialReads = inboxNativeReadState($pdo);
    $businessBefore = $pdo->query('SELECT id,status,manager_id FROM conversations ORDER BY id')->fetchAll();
    $arguments = [7,'mine',200,'*','','',0,[]];
    // Keep one-time schema/read-owner initialization out of paired query counts.
    foreach ([BaselineManagerConversationService::class,ManagerConversationService::class] as $class) inboxNativeRun($pdo,$class,$arguments);
    [$before, $after] = inboxNativePair($pdo, 'ordinary Mine', $arguments);
    $expected = array_values(array_filter($fixtures, static fn(array $f): bool => $f['project'] === 'native-inbox' && $f['owner'] === 7 && $f['status'] === 'manager' && !$f['isTest']));
    usort($expected, static fn(array $a, array $b): int => ($b['pending'] <=> $a['pending']) ?: strcmp($b['last'] ?? $b['at'], $a['last'] ?? $a['at']));
    $expected = array_column(array_slice($expected, 0, 200), 'id');
    inboxNativeCheck('Mine uses the exact reply-ranked customer-backed 200-candidate window', array_map('intval',array_column($after['rows'],'id')) === $expected && $after['metadata'] === ['candidate_count'=>200,'limit_reached'=>true]);
    inboxNativeCheck('more than200 old unanswered candidates outrank more than200 newer answered candidates', count(array_filter($fixtures,static fn(array $f):bool=>$f['id']<=360&&$f['pending']))>200 && count(array_filter($fixtures,static fn(array $f):bool=>$f['id']>360&&$f['id']<=580&&!$f['pending']))>200 && count(array_filter($after['rows'],static fn(array $r):bool=>!empty($r['awaiting_manager_reply'])))===200 && min(array_map('intval',array_column($after['rows'],'id')))<360);
    inboxNativeCheck('native rows retain latest-message metadata and unread projection', count(array_filter($after['rows'], static fn(array $r): bool => (int)$r['unread_count'] > 0 && (int)$r['customer_name_version'] === 3)) > 0);
    foreach ([
        ['limit37',[7,'mine',37,'*','','',0,[]]],
        ['explicit project and ordinary ignored other-manager filter',[7,'mine',200,'native-inbox','8','',0,[]]],
        ['stage and tag',[7,'mine',200,'*','','quote',1,[]]],
        ['saved singleton Mine',[7,'all',200,'*','','',0,['queues'=>['mine']]]],
        ['saved singleton channel/source/reply-needed',[7,'all',200,'*','','',0,['queues'=>['mine'],'channels'=>['max','telegram'],'source_ids'=>[1],'reply_needed'=>true]]],
        ['selected membership IDs before cap',[7,'all',200,'*','','',0,['queues'=>['mine'],'ids'=>[1,2,3,4,5,6,7,8,9,10,100,200,300,400,500,600]]]],
        ['Mine plus Closed newest-first union',[7,'all',200,'*','','',0,['queues'=>['mine','closed']]]],
        ['plural queue/channel/source intersections',[7,'all',200,'*','','',0,['queues'=>['mine','closed'],'channels'=>['max','telegram'],'source_ids'=>[1,2]]]],
        ['empty selected membership',[7,'all',200,'*','','',0,['queues'=>['mine'],'ids'=>[]]]],
    ] as [$label, $case]) inboxNativePair($pdo, $label, $case);
    $pdo->exec('DELETE FROM manager_group_members WHERE manager_id=7');
    [, $restricted] = inboxNativePair($pdo, 'revoked source group after candidate cap', $arguments);
    $visibleExpected = array_values(array_filter($expected, static fn(int $id): bool => $fixtures[$id]['source'] !== 2));
    inboxNativeCheck('source ACL removes forbidden selected rows without backfilling past200', array_map('intval',array_column($restricted['rows'],'id')) === $visibleExpected && count($restricted['rows']) < 200 && $restricted['metadata']['candidate_count'] === 200);
    $pdo->exec('INSERT INTO manager_group_members VALUES(7,101)');
    $pdo->exec("UPDATE managers SET role='admin' WHERE id=7");
    inboxNativePair($pdo, 'admin Mine retains ownership', $arguments);
    inboxNativePair($pdo, 'saved admin Mine intersects external manager', [7,'all',200,'*','8','',0,['queues'=>['mine']]]);
    inboxNativePair($pdo, 'saved admin Mine intersects unassigned', [7,'all',200,'*','unassigned','',0,['queues'=>['mine']]]);
    $pdo->exec("UPDATE managers SET role='manager',is_working=0 WHERE id=7");
    inboxNativePair($pdo, 'off-shift assigned Mine', $arguments);
    $pdo->exec('UPDATE managers SET is_working=1 WHERE id=7');
    inboxNativeCheck('all read-only comparisons preserve read cursors and conversation business state', inboxNativeReadState($pdo) === $initialReads && $pdo->query('SELECT id,status,manager_id FROM conversations ORDER BY id')->fetchAll() === $businessBefore);

    // Both plans run outside all measured list intervals; no EXPLAIN ANALYZE.
    $beforePlan=inboxNativePlan($pdo,$before['sql'],$before['args']);$afterPlan=inboxNativePlan($pdo,$after['sql'],$after['args']);
    echo 'PLAN before ' . json_encode($beforePlan,JSON_THROW_ON_ERROR) . "\n";
    echo 'PLAN after ' . json_encode($afterPlan,JSON_THROW_ON_ERROR) . "\n";
    inboxNativeCheck('native plan resolves the latest message once and joins one exact row',$beforePlan['latest_message_lookups']===4&&$afterPlan['latest_message_lookups']===1&&$afterPlan['latest_row_joins']===1);
    $beforeReads=inboxNativeKeyReads($pdo,$before['sql'],$before['args']);$afterReads=inboxNativeKeyReads($pdo,$after['sql'],$after['args']);
    inboxNativeCheck('actual native logical key reads decrease with identical captured SQL rows',$beforeReads['rows']===$afterReads['rows']&&$afterReads['handler_read_key']<$beforeReads['handler_read_key']);
    echo 'PREVIEW_READS '.json_encode(['driver'=>'mysql','candidate_rows'=>count($afterReads['rows']),'before'=>$beforeReads['handler_read_key'],'after'=>$afterReads['handler_read_key'],'production_latency'=>null],JSON_THROW_ON_ERROR)."\n";
    // Two warmups each, then alternating order against exactly the same rows.
    for ($warm = 0; $warm < 2; ++$warm) foreach ([BaselineManagerConversationService::class,ManagerConversationService::class] as $class) inboxNativeRun($pdo,$class,$arguments);
    $samples = ['before'=>[],'after'=>[]]; $execSamples = $samples; $counts = $samples;
    for ($sample = 0; $sample < 20; ++$sample) {
        foreach ($sample % 2 ? ['after','before'] : ['before','after'] as $variant) {
            $result = inboxNativeRun($pdo,$variant === 'before' ? BaselineManagerConversationService::class : ManagerConversationService::class,$arguments);
            if ($result['rows'] !== $before['rows'] || $result['metadata'] !== $before['metadata']) throw new RuntimeException('FAIL timed sample preserves the exact immutable fixture result');
            $samples[$variant][] = round($result['ms'],3); $execSamples[$variant][] = round($result['list_execute_ms'],3); $counts[$variant][] = $result['sql_count'];
        }
    }
    inboxNativeCheck('all40 timed native samples preserve values, metadata and equal SQL counts', count($samples['before']) === 20 && count($samples['after']) === 20 && array_unique([...$counts['before'],...$counts['after']]) === [$before['sql_count']]);
    inboxNativeCheck('benchmark never advances a read marker or mutates conversation state', inboxNativeReadState($pdo) === $initialReads && $pdo->query('SELECT id,status,manager_id FROM conversations ORDER BY id')->fetchAll() === $businessBefore);
    foreach(['all','closed','waiting','attention','requested'] as $queue)inboxNativePair($pdo,'latest-message projection in '.$queue,[7,$queue,200,'*','','',0,[]]);
    // New isolated preview cases do not enter the previously measured Mine page.
    $previewIds=range(701,709);
    foreach($previewIds as $id)$conversation->execute([$id,1,'native-inbox',1,'max','ai','new',null,'2026-10-15 10:00:00','2026-10-15 10:00:'.str_pad((string)($id-700),2,'0',STR_PAD_LEFT),0]);
    $previewMessages=[
        [702,'inbound','customer','show_tours','{"type":"callback"}'],
        [703,'inbound','customer','tour_like_synthetic','{"type":"callback"}'],
        [704,'outbound','ai','show_tours','{"type":"callback"}'],
        [705,'outbound','manager','Ответ &amp; привет 👋','{}'],
        [706,'inbound','customer','','{"attachments":[{"type":"photo"}]}'],
        [707,'inbound','customer','Malformed metadata','{invalid'],
        [708,'inbound','customer','Higher ID with older time',null],
        [709,'outbound','manager','Identical timestamps','{}'],
    ];
    foreach($previewMessages as [$id,$direction,$sender,$text,$meta]){
        $message->execute([$id*100,$id,'outbound','ai','max','Old unrelated fields','{"type":"callback"}','2026-10-16 10:00:00']);
        $message->execute([$id*100+3,$id,$direction,$sender,'max',$text,$meta,$id===709?'2026-10-16 10:00:00':'2026-10-15 10:00:00']);
    }
    $previewSnapshot=static fn():array=>[inboxNativeReadState($pdo),$pdo->query('SELECT * FROM conversations WHERE id BETWEEN 701 AND 709 ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM messages WHERE conversation_id BETWEEN 701 AND 709 ORDER BY id')->fetchAll()];
    $previewBusinessBefore=$previewSnapshot();
    [, $preview]=inboxNativePair($pdo,'empty callback attachment Unicode malformed and reversed-time previews',[7,'all',200,'native-inbox','','',0,['ids'=>$previewIds]]);
    $previewById=array_column($preview['rows'],null,'id');
    inboxNativeCheck('native latest-row join retains empty conversations and nullable fields',count($previewById)===9&&$previewById[701]['last_text']===null&&$previewById[701]['last_direction']===null&&$previewById[701]['last_sender_type']===null&&$previewById[701]['last_preview_text']==='');
    inboxNativeCheck('native callback labels depend on the same row sender direction and metadata',$previewById[702]['last_preview_text']==='Нажата кнопка «Показать туры»'&&$previewById[703]['last_preview_text']==='Нажата кнопка «Нравится»'&&$previewById[704]['last_preview_text']==='show_tours');
    inboxNativeCheck('native higher IDs retain empty Unicode malformed null and equal-time values',$previewById[705]['last_text']==='Ответ &amp; привет 👋'&&$previewById[706]['last_text']===''&&$previewById[707]['last_preview_text']==='Malformed metadata'&&$previewById[708]['last_text']==='Higher ID with older time'&&$previewById[709]['last_text']==='Identical timestamps');
    inboxNativeCheck('native preview comparisons preserve complete fixture business and read state',$previewSnapshot()===$previewBusinessBefore);
    echo 'EVIDENCE ' . json_encode(['scope'=>'synthetic_native_service_list','base_sha'=>'bc9d104b1f9619d5d6cbb0a7ee05dced06376abf',
        'owned_fixture_rows'=>count(array_filter($fixtures,static fn(array $f):bool=>$f['project']==='native-inbox'&&$f['owner']===7&&$f['status']==='manager'&&!$f['isTest'])),
        'sample_count_each'=>20,'order'=>'alternating','cache_state'=>'two_warmups_each_uncontrolled_server_cache','result_sha256'=>hash('sha256',json_encode([$before['rows'],$before['metadata']],JSON_THROW_ON_ERROR)),
        'full_list_ms'=>$samples,'list_execute_ms'=>$execSamples,'sql_counts'=>$counts,
        'median_full_list_ms'=>array_map('inboxNativeMedian',$samples),'median_list_execute_ms'=>array_map('inboxNativeMedian',$execSamples),
        'production_or_browser_speedup_claimed'=>false],JSON_THROW_ON_ERROR) . "\n";
    echo 'Native inbox query page: ' . $passed . " checks passed\n";
} catch (Throwable $error) {
    $reason = $error instanceof RuntimeException && str_starts_with($error->getMessage(),'FAIL ') ? $error->getMessage() : 'fixture_or_native_comparison_failed';
    $sqlstate = $error instanceof PDOException && preg_match('/\A[A-Z0-9]{5}\z/D',(string)$error->getCode()) ? (string)$error->getCode() : 'none';
    fwrite(STDERR,$reason . ' class=' . ($error instanceof PDOException ? 'PDOException' : 'Throwable') . ' sqlstate=' . $sqlstate . "\n"); exit(1);
}
