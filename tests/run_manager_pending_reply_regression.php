<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerConversationService.php';
require_once dirname(__DIR__).'/services/ManagerLeadInboxService.php';

function replyCheck(string $name,bool $ok):void {
    if(!$ok)throw new RuntimeException($name);
    echo "PASS {$name}\n";
}
$method=new ReflectionMethod(ManagerConversationService::class,'awaitingManagerReplySql');
$method->setAccessible(true);
$condition=$method->invoke(null,'c');
// Execute the production SQL predicate against isolated message histories.
// SQLite JSON_EXTRACT already unquotes scalar strings.
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->sqliteCreateFunction('JSON_UNQUOTE',static fn($v)=>$v,1);
$db->exec('CREATE TABLE conversations(id INTEGER PRIMARY KEY,status TEXT,manager_id INTEGER)');
$db->exec('CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,direction TEXT,sender_type TEXT,text TEXT,metadata_json TEXT,created_at TEXT)');
$db->exec('CREATE TABLE conversation_events(conversation_id INTEGER,event_type TEXT,created_at TEXT)');
$db->exec('CREATE TABLE manager_conversation_reads(manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER)');
$db->exec("INSERT INTO conversations VALUES(1,'manager',7),(2,'manager',8),(3,'closed',7)");
function message(PDO $db,int $id,string $direction,string $sender,?string $meta=null,int $cid=1):void {
    $db->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?)')->execute([$id,$cid,$direction,$sender,'Message',$meta,'2026-09-12 10:00:00']);
}
$pending=static function(int $id=1)use($db,$condition):bool {
    return (bool)$db->query("SELECT CASE WHEN {$condition} THEN 1 ELSE 0 END FROM conversations c WHERE c.id=".(int)$id)->fetchColumn();
};
replyCheck('empty conversation has no pending reply',!$pending());
message($db,1,'inbound','customer','{"type":"callback"}');
message($db,2,'inbound','customer','{"type":"start"}');
replyCheck('navigation and start callbacks do not create reply work',!$pending());
message($db,3,'inbound','customer','{"type":"message"}');
replyCheck('customer message awaits manager', $pending());
$db->exec('INSERT INTO manager_conversation_reads VALUES(7,1,3)');
replyCheck('opening and marking the conversation read does not answer it',$pending());
message($db,4,'outbound','ai');
message($db,5,'outbound','system');
replyCheck('bot and system output do not answer for manager',$pending());
message($db,6,'outbound','manager');
replyCheck('recorded manager reply clears pending even with old unread cursor',!$pending());
message($db,7,'inbound','customer','{"type":"callback"}');
replyCheck('show-tours callback after reply does not reopen pending',!$pending());
message($db,8,'inbound','customer','{"type":"message","attachments":[{"type":"photo"}]}');
$db->exec("UPDATE messages SET text='' WHERE id=8");
replyCheck('customer attachment without caption requires reply',$pending());
message($db,9,'outbound','manager','{"attachments":[{"type":"photo"}]}');
replyCheck('manager media reply clears pending',!$pending());
message($db,10,'inbound','customer','malformed historical metadata');
replyCheck('historical malformed metadata does not hide a customer message',$pending());
message($db,11,'inbound','customer',null,2);
message($db,12,'inbound','customer',null,3);
replyCheck('closed technical conversation is not pending',!$pending(3));
$ids=$db->query("SELECT c.id FROM conversations c WHERE c.status='manager' AND c.manager_id=7 ORDER BY CASE WHEN {$condition} THEN 1 ELSE 0 END DESC")->fetchAll(PDO::FETCH_COLUMN);
replyCheck('mine remains current manager only',array_map('intval',$ids)===[1]);
message($db,13,'outbound','manager');
$db->exec("INSERT INTO conversation_events VALUES(1,'waiting_manager','2026-09-12 10:01:00')");
replyCheck('fresh handoff still needs its first reply even without customer text',$pending());
$db->exec("UPDATE messages SET created_at='2026-09-12 10:02:00' WHERE id=13");
replyCheck('reply after latest handoff clears first-reply pending',!$pending());
$rows=[
    ['id'=>1,'awaiting_manager_reply'=>0,'operational_task_rank'=>0],
    ['id'=>2,'awaiting_manager_reply'=>1,'unread_count'=>0,'operational_task_rank'=>3],
    ['id'=>3,'awaiting_manager_reply'=>1,'unread_count'=>2,'operational_task_rank'=>0],
    ['id'=>4,'awaiting_manager_reply'=>0,'unread_count'=>5,'operational_task_rank'=>1],
];
replyCheck('unanswered first, preserving task urgency within groups',array_column(ManagerLeadInboxService::sortMine($rows),'id')===[3,2,1,4]);
replyCheck('task owner ordering remains unchanged',array_column(ManagerLeadInboxService::sortOperational($rows),'id')===[1,3,4,2]);
$source=file_get_contents(dirname(__DIR__).'/services/ManagerConversationService.php');
replyCheck('mine reply priority applies before database limit',strpos($source,"awaiting_manager_reply DESC,COALESCE(c.last_message_at,c.started_at) DESC")!==false);
echo "Pending-reply regression passed\n";
