<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerAiDraftContextService.php';

/** Real services/SQL on an ephemeral database; writes during capture are fatal. */
final class DraftContextStatement extends PDOStatement
{
    protected function __construct(private DraftContextPdo $owner) {}
    public function execute(?array $params = null): bool
    {
        $this->owner->observe($this->queryString);
        return parent::execute($params);
    }
}

final class DraftContextPdo extends PDO
{
    public array $executed = [];
    public int $writeAttempts = 0;
    public int $commits = 0;
    public bool $guard = false;
    public string $failQuery = '';

    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [DraftContextStatement::class, [$this]]);
    }
    public function observe(string $sql): void
    {
        if (!$this->guard) return;
        $this->executed[] = $sql;
        if (!preg_match('/\ASELECT\b/i', $sql)) {
            ++$this->writeAttempts;
            throw new RuntimeException('fixture_rejected_write');
        }
        if ($this->failQuery !== '' && str_contains($sql, $this->failQuery)) throw new RuntimeException('PRIVATE synthetic storage failure');
    }
    public function exec(string $statement): int|false
    {
        $this->observe($statement);
        return parent::exec($statement);
    }
    public function beginTransaction(): bool
    {
        $result = parent::beginTransaction();
        $this->guard = true;
        return $result;
    }
    public function commit(): bool
    {
        $result = parent::commit();
        $this->guard = false;
        ++$this->commits;
        return $result;
    }
    public function rollBack(): bool
    {
        $result = parent::rollBack();
        $this->guard = false;
        return $result;
    }
}

$passed = 0;
function draftContextCheck(string $name, bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    ++$passed;
    echo 'PASS '.$name."\n";
}

function draftContextFixture(int $count = 2): DraftContextPdo
{
    $pdo = new DraftContextPdo();
    (new ReflectionProperty(ConversationDb::class, 'pdo'))->setValue(null, $pdo);
    foreach ([ProjectAccessService::class, ManagerAuthService::class, RoutingAccessService::class] as $class) {
        (new ReflectionProperty($class, 'schemaReady'))->setValue(null, false);
    }
    ProjectConfig::resetForTests(['id'=>'anytour', 'brand'=>['name'=>'Synthetic project']]);
    $pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT);
CREATE TABLE manager_customer_names(customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER);
CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,external_chat_id TEXT);
CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,display_name TEXT,is_active INTEGER,primary_group_id INTEGER,fallback_mode TEXT,fallback_group_id INTEGER,fallback_after_minutes INTEGER);
CREATE TABLE manager_group_members(manager_id INTEGER,group_id INTEGER);
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,actor_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,direction TEXT,sender_type TEXT,text TEXT,created_at TEXT,channel TEXT,metadata_json TEXT,sender_id TEXT,external_message_id TEXT);
CREATE INDEX idx_messages_conversation ON messages(conversation_id,id);
CREATE TABLE manager_conversation_reads(manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER,updated_at TEXT,PRIMARY KEY(manager_id,conversation_id));
INSERT INTO projects VALUES(1,'anytour','Synthetic project',1),(2,'other','Other project',1);
INSERT INTO managers VALUES(1,'owner','PRIVATE manager name','manager','PRIVATE@example.test',1,1),(2,'admin','Other manager','admin','',1,1),(3,'outside','Outside project','admin','',1,1);
INSERT INTO manager_projects VALUES(1,1),(2,1),(3,2);
INSERT INTO customers VALUES(10,'PRIVATE customer name','79995554433','PRIVATE@example.test');
INSERT INTO manager_customer_names VALUES(10,'PRIVATE working label',1);
INSERT INTO conversations VALUES(1,10,'anytour',0,'telegram','','','','manager','new',1,'2026-10-17 12:00:00','2026-10-17 12:00:00',NULL,'PRIVATE external chat');
INSERT INTO manager_conversation_reads VALUES(1,1,1,'2026-10-17 12:00:00');");
    $insert = $pdo->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?,?,?)');
    for ($id=1; $id<=$count; ++$id) $insert->execute([$id, 1, 'inbound', 'customer', 'Сообщение '.$id, '2026-10-17 12:00:00', 'telegram', null, 'PRIVATE sender ID', 'PRIVATE message ID']);
    return $pdo;
}

function draftContextCapture(DraftContextPdo $pdo, int $actor = 1, int $id = 1): array
{
    $result = ManagerAiDraftContextService::snapshot($id, $actor);
    draftContextCheck('capture finishes its transaction without a database write', !$pdo->inTransaction() && $pdo->writeAttempts === 0);
    return $result;
}

function draftContextIds(array $result): array
{
    return array_column($result['context']['messages'], 'id');
}

function draftContextMessageReads(DraftContextPdo $pdo): array
{
    return array_values(array_filter($pdo->executed, static fn(string $sql): bool => str_contains($sql, 'FROM messages')));
}

try {
    $pdo = draftContextFixture();
    $before = $pdo->query('SELECT * FROM projects')->fetchAll();
    $first = draftContextCapture($pdo);
    draftContextCheck('owned visible conversation returns a chronological complete snapshot', $first['ok'] && draftContextIds($first) === [1,2] && $first['latest_message_id'] === 2 && !$first['history_incomplete'] && $first['context']['as_of_message_id'] === 2);
    draftContextCheck('read-only initialization preserves exact project metadata', $pdo->query('SELECT * FROM projects')->fetchAll() === $before);
    draftContextCheck('authorized snapshot carries server-owned project knowledge outside transcript input',
        $first['company_knowledge'] instanceof ManagerCompanyKnowledge
        && $first['company_knowledge']->revision() === ManagerCompanyKnowledge::forProject('anytour')->revision()
        && !isset($first['context']['verified_company_facts']) && !isset($first['context']['company_knowledge']));
    draftContextCheck('read marker remains unchanged and no synthetic message is added', (int)$pdo->query('SELECT last_read_message_id FROM manager_conversation_reads')->fetchColumn() === 1 && !in_array(0, draftContextIds($first), true));
    $again = draftContextCapture($pdo);
    draftContextCheck('unchanged fresh captures have the same revision', $first['revision'] === $again['revision'] && preg_match('/\A[a-f0-9]{64}\z/D', $first['revision']) === 1 && $pdo->commits === 2);
    $pdo->exec("UPDATE messages SET text='Исправленный ответ',metadata_json='{\"edited_at\":\"2026-10-17 12:01:00\"}' WHERE id=1");
    $edited = draftContextCapture($pdo);
    draftContextCheck('edit in place invalidates revision without a new ID or last-message timestamp', $edited['revision'] !== $first['revision'] && $edited['latest_message_id'] === 2 && $pdo->query('SELECT last_message_at FROM conversations')->fetchColumn() === '2026-10-17 12:00:00');
    $pdo->exec("UPDATE messages SET metadata_json='{\"edited_at\":\"2026-10-17 12:02:00\"}' WHERE id=1");
    $metadataEdit = draftContextCapture($pdo);
    draftContextCheck('metadata-only edit also changes the source fingerprint', $metadataEdit['revision'] !== $edited['revision']);
    $pdo->exec("INSERT INTO messages VALUES(3,1,'inbound','customer','Новая версия запроса','2026-10-17 12:03:00','telegram',NULL,'PRIVATE','PRIVATE')");
    $arrival = draftContextCapture($pdo);
    draftContextCheck('later capture sees a new arrival after the previous committed snapshot', $arrival['latest_message_id'] === 3 && $arrival['revision'] !== $metadataEdit['revision'] && end($arrival['context']['messages'])['text'] === 'Новая версия запроса');

    $pdo = draftContextFixture();
    draftContextCheck('invalid ID is refused', draftContextCapture($pdo, 1, 0) === ['ok'=>false,'error'=>'invalid_conversation_id']);
    draftContextCheck('missing identity is refused', draftContextCapture($pdo, 0) === ['ok'=>false,'error'=>'unauthorized']);
    draftContextCheck('outside project is refused before any message read', draftContextCapture($pdo, 3) === ['ok'=>false,'error'=>'not_found'] && draftContextMessageReads($pdo) === []);
    draftContextCheck('admin edit privilege does not confer reply ownership', ManagerConversationAccessPolicy::canEditVisibleConversation(['manager_id'=>1], ['id'=>2,'role'=>'admin']) && draftContextCapture($pdo, 2) === ['ok'=>false,'error'=>'reply_not_allowed'] && draftContextMessageReads($pdo) === []);
    $pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');
    draftContextCheck('fresh inactive account is refused before messages', draftContextCapture($pdo) === ['ok'=>false,'error'=>'unauthorized'] && draftContextMessageReads($pdo) === []);

    foreach (['waiting_manager','ai','closed'] as $status) {
        $pdo = draftContextFixture();
        $pdo->prepare('UPDATE conversations SET status=?')->execute([$status]);
        draftContextCheck('non-reply status '.$status.' is refused without message reads', draftContextCapture($pdo) === ['ok'=>false,'error'=>'reply_not_allowed'] && draftContextMessageReads($pdo) === []);
    }
    $pdo = draftContextFixture();
    $pdo->exec('UPDATE conversations SET manager_id=2');
    draftContextCheck('reassignment invalidates the original owner immediately', draftContextCapture($pdo) === ['ok'=>false,'error'=>'reply_not_allowed']);
    $pdo = draftContextFixture();
    $pdo->exec("INSERT INTO conversation_sources VALUES(10,'Restricted source',1,20,'none',0,0); UPDATE conversations SET source_id=10; INSERT INTO manager_group_members VALUES(1,20)");
    draftContextCheck('current source-group member may read own context', draftContextCapture($pdo)['ok']);
    $pdo->executed = [];
    $pdo->exec('DELETE FROM manager_group_members');
    draftContextCheck('revoked source-group access is checked on the next snapshot', draftContextCapture($pdo) === ['ok'=>false,'error'=>'not_found'] && draftContextMessageReads($pdo) === []);
    $pdo = draftContextFixture();
    $pdo->exec('UPDATE managers SET is_working=0 WHERE id=1');
    draftContextCheck('draft generation never switches or invents an operator shift', draftContextCapture($pdo)['ok'] && (int)$pdo->query('SELECT is_working FROM managers WHERE id=1')->fetchColumn() === 0);

    foreach (['missing','inactive','renamed'] as $state) {
        $pdo = draftContextFixture();
        if ($state === 'missing') $pdo->exec('DELETE FROM projects WHERE id=1');
        elseif ($state === 'inactive') $pdo->exec('UPDATE projects SET is_active=0 WHERE id=1');
        else $pdo->exec("UPDATE projects SET display_name='Operator value' WHERE id=1");
        $before = $pdo->query('SELECT * FROM projects')->fetchAll();
        draftContextCheck('unready '.$state.' project is not repaired', draftContextCapture($pdo) === ['ok'=>false,'error'=>'context_unavailable'] && $pdo->query('SELECT * FROM projects')->fetchAll() === $before && draftContextMessageReads($pdo) === []);
    }

    $pdo = draftContextFixture();
    $pdo->exec("UPDATE conversations SET channel='max'; INSERT INTO conversation_events VALUES(1,1,'manager_message_failed','manager','{\"category\":\"suspended\",\"message\":\"PRIVATE provider detail\"}','2026-10-17 12:01:00')");
    draftContextCheck('suspended MAX delivery refuses draft context', draftContextCapture($pdo) === ['ok'=>false,'error'=>'suspended']);
    $pdo->exec("INSERT INTO conversation_events VALUES(2,1,'bot_started','system','{}','2026-10-17 12:01:00')");
    draftContextCheck('recorded newer MAX system restart reuses canonical eligibility', draftContextCapture($pdo)['ok']);

    $pdo = draftContextFixture(120);
    $complete = draftContextCapture($pdo);
    draftContextCheck('exact message bound has no phantom omission', draftContextIds($complete) === range(1,120) && !$complete['history_incomplete']);
    $pdo = draftContextFixture(121);
    $bounded = draftContextCapture($pdo);
    draftContextCheck('long history returns the latest chronological suffix and explicit incompleteness', draftContextIds($bounded) === range(2,121) && $bounded['history_incomplete'] && $bounded['context']['history_incomplete']);
    $pdo = draftContextFixture();
    $pdo->prepare('UPDATE messages SET text=? WHERE id=1')->execute([str_repeat('а',25000)]);
    $pdo->prepare('UPDATE messages SET text=? WHERE id=2')->execute([str_repeat('б',25000)]);
    $bounded = draftContextCapture($pdo);
    draftContextCheck('character bound keeps complete newest messages and labels omitted older content', draftContextIds($bounded) === [2] && mb_strlen($bounded['context']['messages'][0]['text'],'UTF-8') === 25000 && $bounded['history_incomplete']);
    $pdo = draftContextFixture(1);
    $pdo->prepare('UPDATE messages SET text=?')->execute([str_repeat('😀',40001).'Последняя правка']);
    $bounded = draftContextCapture($pdo);
    draftContextCheck('one oversized message is an explicit valid UTF-8 suffix rather than silent complete history', mb_strlen($bounded['context']['messages'][0]['text'],'UTF-8') === 40000 && str_ends_with($bounded['context']['messages'][0]['text'],'Последняя правка') && $bounded['context']['messages'][0]['text_truncated'] && $bounded['history_incomplete']);

    $pdo = draftContextFixture();
    $text = 'Бюджет 200000 RUB, вылет 24.10.2026. Цена 70000000000 RUB. До 80000000000. +7 (999) 555-44-33; 79995554433; test@example.test; https://qui-quo.ru/AB12-CD34?secret=PRIVATE';
    $pdo->prepare('UPDATE messages SET text=?,metadata_json=? WHERE id=2')->execute([$text, json_encode(['attachments'=>[['type'=>'image','name'=>'PRIVATE filename','url'=>'https://private.test/secret','token'=>'PRIVATE token']], 'linked_message'=>['sender_name'=>'PRIVATE forwarded name','text'=>'PRIVATE forwarded text']], JSON_THROW_ON_ERROR)]);
    $safe = draftContextCapture($pdo);
    $json = json_encode($safe['context'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    draftContextCheck('provider projection excludes contact details, URLs, identities and raw attachment metadata', !str_contains($json,'PRIVATE') && !str_contains($json,'79995554433') && !str_contains($json,'test@example.test') && !str_contains($json,'https://') && !str_contains($json,'external_chat_id') && !str_contains($json,'metadata_json') && !str_contains($json,'sender_id'));
    draftContextCheck('historical prices and dates survive contact minimization', str_contains($json,'200000 RUB') && str_contains($json,'24.10.2026') && str_contains($json,'70000000000 RUB') && str_contains($json,'80000000000'));
    draftContextCheck('unread selection and media are explicit rather than hallucinated contents', $safe['context']['media_unread'] && $safe['context']['selections_link_unread'] && str_contains($json,'ссылка на подборку не прочитана') && !str_contains($json,'PRIVATE forwarded text'));
    $pdo->prepare('UPDATE messages SET text=? WHERE id=2')->execute(['Телефон 79995554433 200000 RUB; даты 7 10 2026 31 10 2026']);
    $adjacent = draftContextCapture($pdo);
    $adjacentText = $adjacent['context']['messages'][1]['text'];
    draftContextCheck('phone next to a price is redacted without consuming that price or serial dates', !str_contains($adjacentText,'79995554433') && str_contains($adjacentText,'200000 RUB') && str_contains($adjacentText,'7 10 2026 31 10 2026'));
    $pdo->exec("UPDATE messages SET text='g1_deadbeef_adults_2',metadata_json='{\"type\":\"callback\"}' WHERE id=1");
    $callback = draftContextCapture($pdo);
    draftContextCheck('recorded callback uses bounded known action without its generation token', $callback['context']['messages'][0]['event_type'] === 'callback' && $callback['context']['messages'][0]['text'] === 'Нажата кнопка: adults_2');
    $pdo->exec("UPDATE messages SET text='g1_deadbeef_PRIVATE_action',metadata_json='{\"type\":\"callback\"}' WHERE id=1");
    $unknown = draftContextCapture($pdo);
    draftContextCheck('unknown callback cannot leak its raw payload', !str_contains(json_encode($unknown['context']), 'PRIVATE_action'));
    $pdo->exec("UPDATE messages SET text='adults_2',metadata_json=NULL WHERE id=1");
    $typed = draftContextCapture($pdo);
    draftContextCheck('typed callback-looking text remains an ordinary customer message', $typed['context']['messages'][0]['event_type'] === 'message' && $typed['context']['messages'][0]['text'] === 'adults_2');
    $pdo->exec("UPDATE messages SET metadata_json='invalid json' WHERE id=1");
    $invalidMetadata = draftContextCapture($pdo);
    draftContextCheck('unreadable metadata makes the history limitation explicit', $invalidMetadata['history_incomplete'] && $invalidMetadata['context']['messages'][0]['event_type'] === 'unknown');

    $pdo = draftContextFixture(0);
    $empty = draftContextCapture($pdo);
    draftContextCheck('empty conversation remains empty and does not invent a latest ID', $empty['ok'] && $empty['latest_message_id'] === 0 && $empty['context']['messages'] === []);
    $pdo = draftContextFixture();
    $pdo->failQuery = 'SELECT id,metadata_json FROM messages';
    draftContextCheck('failed metadata read has a safe refusal and releases the snapshot', draftContextCapture($pdo) === ['ok'=>false,'error'=>'context_unavailable']);
    $pdo = draftContextFixture();
    $pdo->beginTransaction();
    draftContextCheck('caller transaction is rejected without commit or rollback ownership theft', ManagerAiDraftContextService::snapshot(1,1) === ['ok'=>false,'error'=>'context_unavailable'] && $pdo->inTransaction() && $pdo->executed === []);
    $pdo->rollBack();
    echo 'Manager AI draft context: '.$passed." checks passed\n";
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof RuntimeException && str_starts_with($error->getMessage(),'FAIL ') ? $error->getMessage() : 'FAIL synthetic context fixture')."\n");
    exit(1);
}
