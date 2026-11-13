<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/manager-request-profile-fixture.php';
require_once dirname(__DIR__,2).'/services/ConversationRecordClock.php';

/** Existing profile/auth fixture plus SQLite equivalents of migration108 and current lead_tasks. */
function promiseTaskFixture(?string $path=null): PDO
{
    $pdo=requestProfileFixture($path);promiseTaskExtendFixture($pdo);return $pdo;
}
function promiseTaskExtendFixture(PDO $pdo): void
{
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    $pdo->exec("PRAGMA foreign_keys=ON;
CREATE TABLE lead_tasks(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER NOT NULL,title TEXT NOT NULL,due_at_utc TEXT,status TEXT NOT NULL DEFAULT 'open',is_pinned INTEGER NOT NULL DEFAULT 0,assigned_manager_id INTEGER,created_by_manager_id INTEGER NOT NULL,completed_at_utc TEXT,reminder_attempted_at_utc TEXT,reminder_notified_at_utc TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);
CREATE TABLE manager_task_promises(
 promise_id TEXT NOT NULL PRIMARY KEY,conversation_id INTEGER NOT NULL,trip_id TEXT NOT NULL,kind TEXT NOT NULL,
 source_message_id INTEGER NOT NULL,source_message_revision TEXT NOT NULL,source_author_manager_id INTEGER NOT NULL,
 profile_version INTEGER NOT NULL,profile_sha256 TEXT NOT NULL,profile_context_sha256 TEXT NOT NULL,task_id INTEGER NOT NULL UNIQUE,
 deadline_json BLOB NOT NULL,scope_json TEXT NOT NULL,created_by_manager_id INTEGER NOT NULL,created_at TEXT NOT NULL,
 UNIQUE(conversation_id,trip_id,kind,source_message_id),
 FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE ON UPDATE CASCADE);
CREATE TABLE manager_task_promise_operations(
 manager_id INTEGER NOT NULL,operation_id TEXT NOT NULL,conversation_id INTEGER NOT NULL,promise_id TEXT NOT NULL,
 request_sha256 TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(manager_id,operation_id),
 FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE ON UPDATE CASCADE,
 FOREIGN KEY(promise_id) REFERENCES manager_task_promises(promise_id) ON DELETE CASCADE ON UPDATE CASCADE);
CREATE INDEX promise_fixture_operations_conversation ON manager_task_promise_operations(conversation_id);
CREATE INDEX promise_fixture_operations_promise ON manager_task_promise_operations(promise_id);
");
    promiseTaskSeed($pdo);
}
/** One synthetic journey/task population shared by SQLite and native MySQL. */
function promiseTaskSeed(PDO $pdo): void
{
    $pdo->exec("
INSERT INTO conversations(id,customer_id,project_key,source_id,channel,status,lead_stage_key,manager_id,external_chat_id) VALUES(2,10,'anytour',0,'telegram','manager','new',1,'second-synthetic-chat');
INSERT INTO lead_tasks VALUES(101,1,'Подготовить согласованный расчёт','2030-01-01 12:00:00','open',1,1,1,NULL,NULL,NULL,'2026-10-30 10:01:00','2026-10-30 10:01:00'),(102,1,'Подготовить подборку',NULL,'open',0,1,1,NULL,NULL,NULL,'2026-10-30 10:01:00','2026-10-30 10:01:00'),(201,2,'Задача другого диалога',NULL,'open',0,1,1,NULL,NULL,NULL,'2026-10-30 10:01:00','2026-10-30 10:01:00');");
    requestProfileMessage($pdo,12,'manager','Пришлю расчёт на двоих на согласованные даты.');
    requestProfileMessage($pdo,13,'manager','Подготовлю и пришлю подборку отелей.');
    requestProfileMessage($pdo,20,'manager','Обещание из другого диалога.',2);
    $metadata=json_encode(ConversationRecordClock::stamp([],1793354520),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $pdo->prepare('UPDATE messages SET metadata_json=? WHERE id IN (12,13,20)')->execute([$metadata]);
    $view=ManagerRequestProfileService::read(1,1);
    $saved=ManagerRequestProfileService::save(1,1,requestProfileCommand($view));
    if(empty($saved['ok']))throw new RuntimeException('promise_fixture_profile_save_failed: '.json_encode($saved));
    promiseTaskGuardWrites($pdo,true);
}
/** A successful promise command must not even attempt a task INSERT/UPDATE/DELETE. */
function promiseTaskGuardWrites(PDO $pdo,bool $enabled): void
{
    foreach(['INSERT','UPDATE','DELETE'] as $verb){
        $name='promise_fixture_forbid_task_'.strtolower($verb);$pdo->exec('DROP TRIGGER IF EXISTS '.$name);
        if($enabled)$pdo->exec($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ?"CREATE TRIGGER $name BEFORE $verb ON lead_tasks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='promise_must_not_write_tasks'"
            :"CREATE TRIGGER $name BEFORE $verb ON lead_tasks BEGIN SELECT RAISE(ABORT,'promise_must_not_write_tasks'); END");
    }
}
function promiseTaskManualEdit(PDO $pdo,string $sql,array $args=[]): void
{
    promiseTaskGuardWrites($pdo,false);
    try{$pdo->prepare($sql)->execute($args);}finally{promiseTaskGuardWrites($pdo,true);}
}
function promiseTaskCommand(array $context,array $changes=[]): array
{
    if(empty($context['ok']))throw new RuntimeException('promise_fixture_context_failed: '.json_encode($context));
    return array_replace(['operation_id'=>bin2hex(random_bytes(16)),'task_id'=>(int)$context['task']['id'],'kind'=>'send_calculation',
        'trip_id'=>$context['profile']['trip_id'],'profile_version'=>$context['profile']['version'],
        'profile_sha256'=>$context['profile']['sha256'],'profile_context_sha256'=>$context['profile']['context_sha256'],
        'source_message_id'=>$context['source']['id'],'source_message_revision'=>$context['source']['revision'],'deadline'=>null],$changes);
}
function promiseTaskCanonicalSnapshot(PDO $pdo): array
{
    $snapshot=[];foreach(['lead_tasks'=>'id','messages'=>'id','conversations'=>'id','manager_request_profiles'=>'conversation_id,version','conversation_events'=>'id','manager_conversation_reads'=>'manager_id,conversation_id'] as $table=>$order)$snapshot[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll(PDO::FETCH_ASSOC);return $snapshot;
}
function promiseTaskCounts(PDO $pdo): array
{
    return [(int)$pdo->query('SELECT COUNT(*) FROM manager_task_promises')->fetchColumn(),(int)$pdo->query('SELECT COUNT(*) FROM manager_task_promise_operations')->fetchColumn()];
}
/** Reuse the unchanged HTTP fixture and route through the byte-exact real pipeline API. */
function promiseTaskHttpFixture(string $directory): array
{
    require_once __DIR__.'/manager-request-profile-http.php';
    $fixture=managerRequestProfileHttpFixture($directory);
    $pdo=new PDO('sqlite:'.$fixture['database'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);promiseTaskExtendFixture($pdo);
    if(!copy($fixture['root'].'/manager/pipeline-api.php',$fixture['webroot'].'/manager/pipeline-api.php'))throw new RuntimeException('promise_api_copy_failed');
    file_put_contents($fixture['webroot'].'/maxsearchclass.php',"<?php\nclass MaxSearchApi {public static function __callStatic(string \$name,array \$args): never {throw new LogicException('unexpected_external_promise_fixture_call');}}\n");
    file_put_contents($fixture['webroot'].'/config.php',"\nConversationDb::connection()->exec('PRAGMA foreign_keys=ON');\nProjectAccessService::initializeReadOnly();\n",FILE_APPEND);
    return $fixture;
}
