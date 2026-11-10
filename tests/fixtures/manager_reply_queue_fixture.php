<?php
declare(strict_types=1);
require_once __DIR__.'/manager_send_attempt_fixture.php';
require_once dirname(__DIR__,2).'/services/ManagerReplyQueueService.php';

function replyQueueFixture(PDO $pdo,bool $mysql=false): void
{
    sendAttemptBind($pdo);sendAttemptSchema($pdo,$mysql);
    $pdo->exec('ALTER TABLE conversations ADD COLUMN is_test INTEGER DEFAULT 0');
    foreach(['direction VARCHAR(16)','sender_type VARCHAR(16)','metadata_json TEXT','created_at DATETIME'] as $column)$pdo->exec('ALTER TABLE messages ADD COLUMN '.$column);
    $id=$mysql?'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec('CREATE TABLE conversation_events (id '.$id.',conversation_id INTEGER,event_type VARCHAR(64),actor_type VARCHAR(32),actor_id VARCHAR(64),payload_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)'.($mysql?' ENGINE=InnoDB':''));
    $pdo->exec('CREATE INDEX idx_reply_fixture_events ON conversation_events(conversation_id,id)');
    $pdo->exec('CREATE INDEX idx_reply_fixture_messages ON messages(conversation_id,id)');
    $pdo->exec('DELETE FROM messages');
    if($mysql)$pdo->exec((string)file_get_contents(dirname(__DIR__,2).'/migrations/098_manager_reply_queue.sql'));
    else{
        $pdo->sqliteCreateFunction('JSON_UNQUOTE',static fn($value)=>$value,1);
        $pdo->exec('CREATE TABLE manager_reply_queue_operations(manager_id INTEGER,operation_id TEXT,conversation_id INTEGER,request_hash TEXT,result_json TEXT,created_at TEXT,PRIMARY KEY(manager_id,operation_id))');
    }
}
function replyQueueMessage(PDO $pdo,int $id,int $cid,string $direction,string $sender,int $clock,?array $extra=[],string $text='Synthetic question'): void
{
    $meta=$extra===null?null:ConversationRecordClock::stamp($extra,$clock);
    $q=$pdo->prepare('INSERT INTO messages(id,conversation_id,text,direction,sender_type,metadata_json,created_at) VALUES (?,?,?,?,?,?,?)');
    $q->execute([$id,$cid,$text,$direction,$sender,$meta===null?null:json_encode($meta,JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s',$clock)]);
}
function replyQueueRequest(PDO $pdo,int $cid,int $clock): int
{
    $q=$pdo->prepare("INSERT INTO conversation_events(conversation_id,event_type,actor_type,payload_json,created_at) VALUES (?,'waiting_manager','customer',?,?)");
    $q->execute([$cid,json_encode(ConversationRecordClock::stamp([],$clock),JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s',$clock)]);return(int)$pdo->lastInsertId();
}
function replyQueueInput(array $view,string $operation): array
{
    return ['operation_id'=>$operation,'context'=>$view['context'],'expected_version'=>$view['version'],'through_message_id'=>$view['customer_message_id'],'through_request_event_id'=>$view['request_event_id']];
}
