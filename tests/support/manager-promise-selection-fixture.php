<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/manager-tour-selection.php';
require_once dirname(__DIR__,2).'/services/ManagerRequestProfileService.php';
require_once dirname(__DIR__,2).'/services/ManagerPromiseTaskService.php';
require_once dirname(__DIR__,2).'/services/LeadTaskService.php';

/** SQLite syntax adapter for the legacy reassign reader; it does not model row locks. */
final class PromiseSelectionSqliteLockPdo extends PDO
{
    private static function sql(string $sql): string
    {return preg_replace('/\s+FOR\s+UPDATE\s*;?\s*\z/i','',$sql)??$sql;}
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {return parent::prepare(self::sql($query),$options);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {return $fetchMode===null?parent::query(self::sql($query)):parent::query(self::sql($query),$fetchMode,...$fetchModeArgs);}
}

/** Real canonical services/SQLite. Native delivery is injected separately and never contacts a provider. */
function promiseSelectionFixture(?string $databasePath=null,string $channel='telegram'): PDO
{
    if(!in_array($channel,['telegram','max'],true))throw new InvalidArgumentException('invalid_fixture_channel');
    $pdo=deliveryDb();
    promiseSelectionSqlFunctions($pdo);
    $pdo->exec("PRAGMA foreign_keys=ON;
CREATE TABLE lead_tasks(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER NOT NULL,title TEXT NOT NULL,due_at_utc TEXT,status TEXT NOT NULL DEFAULT 'open',is_pinned INTEGER NOT NULL DEFAULT 0,assigned_manager_id INTEGER,created_by_manager_id INTEGER NOT NULL,completed_at_utc TEXT,reminder_attempted_at_utc TEXT,reminder_notified_at_utc TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,business_revision INTEGER NOT NULL DEFAULT 1);
CREATE TABLE manager_assignments(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER NOT NULL,manager_id INTEGER NOT NULL,assignment_type TEXT,assigned_at TEXT DEFAULT CURRENT_TIMESTAMP,released_at TEXT);
CREATE TABLE manager_request_profiles(conversation_id INTEGER,version INTEGER,trip_id TEXT,boundary_message_id INTEGER,manager_id INTEGER,operation_id TEXT,request_sha256 TEXT,context_sha256 TEXT,profile_json TEXT,profile_sha256 TEXT,changed_fields_json TEXT,created_at TEXT,PRIMARY KEY(conversation_id,version),UNIQUE(manager_id,operation_id),FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE);
CREATE TABLE manager_task_promises(promise_id TEXT NOT NULL PRIMARY KEY,conversation_id INTEGER NOT NULL,trip_id TEXT NOT NULL,kind TEXT NOT NULL,source_message_id INTEGER NOT NULL,source_message_revision TEXT NOT NULL,source_author_manager_id INTEGER NOT NULL,profile_version INTEGER NOT NULL,profile_sha256 TEXT NOT NULL,profile_context_sha256 TEXT NOT NULL,task_id INTEGER NOT NULL UNIQUE,deadline_json BLOB NOT NULL,scope_json TEXT NOT NULL,created_by_manager_id INTEGER NOT NULL,created_at TEXT NOT NULL,UNIQUE(conversation_id,trip_id,kind,source_message_id),FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE);
CREATE TABLE manager_task_promise_operations(manager_id INTEGER NOT NULL,operation_id TEXT NOT NULL,conversation_id INTEGER NOT NULL,promise_id TEXT NOT NULL,request_sha256 TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(manager_id,operation_id),FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,FOREIGN KEY(promise_id) REFERENCES manager_task_promises(promise_id) ON DELETE CASCADE);
CREATE TABLE manager_promise_selection_intents(intent_id TEXT NOT NULL PRIMARY KEY,conversation_id INTEGER NOT NULL,promise_id TEXT NOT NULL,task_id INTEGER NOT NULL,publication_id TEXT NOT NULL,publication_sha256 TEXT NOT NULL,manifest_sha256 TEXT NOT NULL,manifest_json TEXT NOT NULL,trip_id TEXT NOT NULL,profile_version INTEGER NOT NULL,profile_sha256 TEXT NOT NULL,profile_context_sha256 TEXT NOT NULL,source_message_id INTEGER NOT NULL,source_message_revision TEXT NOT NULL,source_author_manager_id INTEGER NOT NULL,task_revision INTEGER NOT NULL,scope_json TEXT NOT NULL,created_by_manager_id INTEGER NOT NULL,state TEXT NOT NULL,reason TEXT,active_promise_id TEXT UNIQUE,active_publication_id TEXT UNIQUE,evidence_json TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,completed_at TEXT,cancelled_at TEXT,FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE);
CREATE TABLE manager_promise_selection_operations(manager_id INTEGER NOT NULL,operation_id TEXT NOT NULL,conversation_id INTEGER NOT NULL,action TEXT NOT NULL,intent_id TEXT NOT NULL,request_sha256 TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(manager_id,operation_id),FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,FOREIGN KEY(intent_id) REFERENCES manager_promise_selection_intents(intent_id) ON DELETE CASCADE);
INSERT INTO manager_assignments(conversation_id,manager_id,assignment_type) VALUES(1,7,'synthetic');
INSERT INTO lead_tasks(id,conversation_id,title,due_at_utc,status,is_pinned,assigned_manager_id,created_by_manager_id,completed_at_utc,reminder_attempted_at_utc,reminder_notified_at_utc,created_at,updated_at) VALUES
(101,1,'Отправить согласованную подборку','2027-06-01 12:00:00','open',0,7,7,NULL,'2026-11-13 11:00:00','2026-11-13 11:00:01','2026-11-13 10:00:00','2026-11-13 10:00:00'),
(102,1,'Задача уже назначенного коллеги',NULL,'open',0,8,7,NULL,NULL,NULL,'2026-11-13 10:00:00','2026-11-13 10:00:00'),
(103,1,'Ранее завершённая задача',NULL,'done',1,7,7,'2026-11-13 10:30:00',NULL,NULL,'2026-11-13 10:00:00','2026-11-13 10:30:00');");
    if($channel==='max'){
        $pdo->exec("UPDATE conversations SET channel='max',external_chat_id='-202' WHERE id=1;
UPDATE customer_channels SET channel='max',external_chat_id='-202',external_user_id='202' WHERE id=1;");
    }
    promiseSelectionMessage($pdo,10,'manager','Москва, 5 июня 2027, 7 ночей. Подборка для семьи.',$channel);
    promiseSelectionMessage($pdo,11,'customer','Двое взрослых и двое детей по 4 года. Даты подходят.',$channel);
    promiseSelectionMessage($pdo,12,'manager','Пришлю подборку отелей по согласованным условиям.',$channel);
    $view=ManagerRequestProfileService::read(7,1);
    if(empty($view['ok']))throw new RuntimeException('promise_selection_profile_read: '.json_encode($view));
    $byId=array_column($view['sources'],null,'id');
    $saved=ManagerRequestProfileService::save(7,1,['version'=>$view['profile']['version'],'context_sha256'=>$view['context_sha256'],
        'operation_id'=>bin2hex(random_bytes(16)),'boundary_message_id'=>10,'new_trip'=>false,'label'=>'Июньский семейный отпуск','fields'=>[
            'dates'=>['value'=>'5 июня 2027, 7 ночей','kind'=>'manager_interpretation','importance'=>'hard','note'=>'','source_id'=>10,'quote'=>$byId[10]['text']],
            'party'=>['value'=>['adults'=>2,'children'=>2,'child_ages'=>[4,4]],'kind'=>'manager_interpretation','importance'=>'hard','note'=>'','source_id'=>11,'quote'=>$byId[11]['text']],
        ]]);
    if(empty($saved['ok']))throw new RuntimeException('promise_selection_profile_save: '.json_encode($saved));
    $context=ManagerPromiseTaskService::context(7,1,101,12,'send_selection');
    if(empty($context['ok']))throw new RuntimeException('promise_selection_bind_context: '.json_encode($context));
    $bound=ManagerPromiseTaskService::bind(7,1,['operation_id'=>bin2hex(random_bytes(16)),'task_id'=>101,'kind'=>'send_selection',
        'trip_id'=>$context['profile']['trip_id'],'profile_version'=>$context['profile']['version'],'profile_sha256'=>$context['profile']['sha256'],
        'profile_context_sha256'=>$context['profile']['context_sha256'],'source_message_id'=>12,'source_message_revision'=>$context['source']['revision'],'deadline'=>null]);
    if(empty($bound['ok']))throw new RuntimeException('promise_selection_bind: '.json_encode($bound));
    $draft=deliveryDraft();$second=$draft['offers'][0];$second['id']=str_repeat('b',32);$second['hotel']='Второй синтетический отель';$second['amount']='250000';$draft['offers'][]=$second;
    $save=deliverySave($draft);
    if(empty($save['ok']))throw new RuntimeException('promise_selection_draft: '.json_encode($save));
    $publication=ManagerTourSelectionDeliveryService::prepare(7,1,1);
    if(empty($publication['ok']))throw new RuntimeException('promise_selection_publication: '.json_encode($publication));
    if($databasePath!==null){
        if(is_file($databasePath))throw new RuntimeException('promise_selection_export_requires_new_path');
        $pdo->exec('VACUUM INTO '.$pdo->quote($databasePath));
        $pdo=new PDO('sqlite:'.$databasePath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys=ON');promiseSelectionSqlFunctions($pdo);
        (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    }
    return $pdo;
}

function promiseSelectionSqlFunctions(PDO $pdo): void
{
    // Fixed within a fixture so A→B→A really occurs inside one stored second.
    $pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn():string=>'2026-11-13 12:00:00');
}

function promiseSelectionMessage(PDO $pdo,int $id,string $role,string $text,string $channel='telegram'): void
{
    $metadata=ConversationRecordClock::stamp(['type'=>'message'],1794571200+$id);
    $native=$role==='customer'&&$channel==='max'?'mid.inbound':'synthetic-promise-'.$id;
    $pdo->prepare('INSERT INTO messages(id,conversation_id,direction,sender_type,sender_id,channel,external_message_id,text,metadata_json,created_at) VALUES(?,1,?,?,?,?,?,?,?,?)')
        ->execute([$id,$role==='customer'?'inbound':'outbound',$role,$role==='customer'?($channel==='max'?'202':'101'):'7',$channel,$native,$text,json_encode($metadata,JSON_THROW_ON_ERROR),'2026-11-13 11:00:00']);
}

function promiseSelectionIds(PDO $pdo): array
{
    return ['promise_id'=>$pdo->query('SELECT promise_id FROM manager_task_promises WHERE task_id=101')->fetchColumn(),
        'publication_id'=>$pdo->query('SELECT publication_id FROM manager_tour_selection_publications WHERE conversation_id=1 ORDER BY rowid LIMIT 1')->fetchColumn()];
}

function promiseSelectionTask(PDO $pdo,int $id=101): array
{
    $q=$pdo->prepare('SELECT * FROM lead_tasks WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:[];
}

function promiseSelectionAttach(PDO $pdo,?string $operation=null): array
{
    $ids=promiseSelectionIds($pdo);$preview=ManagerPromiseSelectionService::preview(7,1,$ids['promise_id'],$ids['publication_id']);
    if(empty($preview['ok'])||empty($preview['can_attach']))throw new RuntimeException('promise_selection_preview: '.json_encode($preview));
    return ManagerPromiseSelectionService::attach(7,1,['operation_id'=>$operation??bin2hex(random_bytes(16)),...$ids,'preview_sha256'=>$preview['preview_sha256']]);
}

function promiseSelectionSend(PDO $pdo,int $index,array &$state,?string $publicationId=null): array
{
    $id=$publicationId??promiseSelectionIds($pdo)['publication_id'];
    if($pdo->query('SELECT channel FROM conversations WHERE id=1')->fetchColumn()==='max'){
        $q=$pdo->prepare('SELECT p.selection_json,c.callback_data FROM manager_tour_selection_publications p JOIN manager_tour_selection_cards c ON c.publication_id=p.publication_id WHERE p.publication_id=? AND c.card_index=?');$q->execute([$id,$index]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $offer=json_decode($row['selection_json'],true,64,JSON_THROW_ON_ERROR)['offers'][$index];
        $state['card']=TourOfferCardRenderer::render($offer['snapshot'],$offer['presentation'],$row['callback_data']);
    }
    return ManagerTourSelectionDeliveryService::send(7,1,$id,$index,deliveryTransport($state),'deliveryImages');
}

function promiseSelectionComplete(PDO $pdo,array &$state,?string $publicationId=null): array
{
    promiseSelectionSend($pdo,0,$state,$publicationId);return promiseSelectionSend($pdo,1,$state,$publicationId);
}
