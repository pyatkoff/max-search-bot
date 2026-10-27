<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/max_channel_attribution.php';
require_once dirname(__DIR__).'/services/MaxTrackerLookupRepository.php';
if(!getenv('MAX_ATTRIBUTION_TEST_MYSQL_DSN'))throw new RuntimeException('native_mysql_fixture_required');
$p=maxAttributionFixture();$checks=0;
function same($a,$b,string $name):void{global $checks;$checks++;if($a!==$b)throw new RuntimeException('FAIL '.$name);}
function rejects(callable $f,string $name):void{global $checks;try{$f();}catch(Throwable $e){$checks++;return;}throw new RuntimeException('FAIL '.$name);}
$p->exec('CREATE TABLE max_sub_links (ID BIGINT PRIMARY KEY,UF_CHAT_ID VARCHAR(20),UF_CLIENT_ID VARCHAR(60),UF_DATE DATETIME NULL,UF_METRIC_SOURCE VARCHAR(20),UF_SOURCE VARCHAR(20),UF_PLATFORM VARCHAR(20),UF_SUB_DATE DATETIME NULL,UF_TYPE INTEGER NULL)');
$p->exec('CREATE TABLE b_iblock_property (ID BIGINT PRIMARY KEY,IBLOCK_ID BIGINT,CODE VARCHAR(64))');
$p->exec('CREATE TABLE b_iblock_element_property (IBLOCK_PROPERTY_ID BIGINT,IBLOCK_ELEMENT_ID BIGINT,VALUE VARCHAR(64))');
$p->exec('CREATE TABLE b_hlblock_entity (ID BIGINT PRIMARY KEY,TABLE_NAME VARCHAR(64))');
$p->exec('CREATE TABLE max_membership_events (ID BIGINT PRIMARY KEY,UF_CHAT_ID VARCHAR(20),UF_SOURCE VARCHAR(20),UF_TYPE INTEGER,UF_DATE DATETIME)');
$p->exec('CREATE TABLE max_no_unsub_goals (ID BIGINT PRIMARY KEY,UF_SUB_ID BIGINT,UF_DATE DATETIME,UF_EXPORTED INTEGER)');
$p->exec("INSERT INTO b_hlblock_entity VALUES(9,'max_sub_links'),(10,'max_membership_events'),(12,'max_no_unsub_goals')");
$p->exec("INSERT INTO b_iblock_property VALUES(1,8,'YA_METRIK_ID'),(2,8,'CHANEL_ID'),(3,7,'YA_METRIK_ID')");
$p->exec("INSERT INTO b_iblock_element_property VALUES(1,10,'98615635'),(1,11,'999'),(2,12,'98615635'),(3,13,'98615635')");
$q=$p->prepare('INSERT INTO max_sub_links VALUES(?,?,?,?,?,?,?,?,?)');
foreach([
    ['9007199254740991','123','11111111111111111111','2026-10-15 11:00:00',null,'10','ios',null,null],
    ['9007199254740992','123','22222222222222222222','2026-10-14 11:00:00','10','11','android',null,null],
    ['9007199254740993','123','33333333333333333333','2026-10-15 13:00:00','11','10','desktop',null,null],
    ['9007199254740994','123','','2026-10-15 13:01:00','10','10','ios',null,null],
    ['9007199254740995','123','01','2026-10-15 13:02:00','10','10','ios',null,null],
    ['9007199254740996','123','999','2026-10-15 13:03:00','12','10','ios',null,null],
    ['9007199254740997','123','888','2026-10-15 13:04:00','13','10','ios',null,null],
    ['9007199254740998','123','777','2026-10-15 13:05:00','0','10','ios',null,null],
    ['9007199254740999','123',"666\n",'2026-10-15 13:06:00','10','10','ios',null,null],
    ['9007199254741000','456','1844674407370955161512345','2026-07-15 12:00:00','','10','ios','2026-07-15 12:00:00',1],
    ['9007199254741001','789','55555555555555555555','2026-01-15 12:00:00','10','11','android',null,null],
    ['9007199254741002','999','99999999999999999999','2026-10-25 01:30:00','10','11','android',null,null],
] as $r)$q->execute($r);
$before=(string)$p->query('SELECT COUNT(*) FROM max_sub_links')->fetchColumn();
$repo=new MaxTrackerLookupRepository($p);$rows=$repo->lookup('98615635',['123','456','789','999','404']);
same($rows[0]['record_id'],'9007199254740992','numeric ID DESC before wall clock');same($rows[0]['client_id'],'22222222222222222222','effective source overrides fallback');
same($rows[1]['client_id'],'1844674407370955161512345','large CID string');same($rows[1]['observed_at_unix'],strtotime('2026-07-15 11:00:00 UTC'),'summer London');
same($rows[2]['observed_at_unix'],strtotime('2026-01-15 12:00:00 UTC'),'winter London');same($rows[3]['observed_at_unix'],null,'ambiguous clock never guesses or loses CID');
same($rows[4],['external_user_id'=>'404','found'=>false],'exact unknown identity');same($p->inTransaction(),false,'readonly transaction rolled back');
same((string)$p->query('SELECT COUNT(*) FROM max_sub_links')->fetchColumn(),$before,'tracker rows unchanged');
$other=$repo->lookup('999',['123']);same($other[0]['record_id'],'9007199254740993','counter filtering precedes latest');
$p->exec("INSERT INTO max_membership_events VALUES
 (1001,'123','10',1,'2026-07-15 12:00:00'),
 (1002,'123','10',2,'2026-07-15 12:20:00'),
 (1003,'456','10',1,'2026-07-15 12:00:00'),
 (1004,'123','11',1,'2026-07-15 12:01:00'),
 (1005,'456','10',1,'2026-07-15 12:05:00'),
 (1006,'789','10',1,'2026-07-15 12:00:00'),
 (1007,'789','10',2,'2026-07-15 12:10:00'),
 (1008,'789','10',1,'2026-07-15 12:20:00')");
$p->exec("INSERT INTO max_no_unsub_goals VALUES(3001,9007199254741000,'2026-07-15 12:30:00',0)");
$p->exec("UPDATE max_sub_links SET UF_SUB_DATE='2026-07-15 12:19:59',UF_TYPE=1 WHERE ID=9007199254741001");
$p->exec("INSERT INTO max_no_unsub_goals VALUES(3003,9007199254741001,'2026-07-15 12:50:00',0)");
$notBefore=strtotime('2026-07-15 10:59:00 UTC');$at=strtotime('2026-07-15 11:00:00 UTC');
$before=$repo->lookup('98615635',['123','456'],['not_before_unix'=>$notBefore,'cutoff_unix'=>$at+1799]);
same($before[0]['membership_receipts'],[['kind'=>'subscription','source_record_id'=>'1001','state'=>'subscribed','occurred_at_unix'=>$at]],'1799 keeps exact subscription without retention');
$mature=$repo->lookup('98615635',['123','456'],['not_before_unix'=>$notBefore,'cutoff_unix'=>$at+1800]);
same($mature[0]['membership_receipts'][1],['kind'=>'retention_30m','source_record_id'=>'1002','subscription_source_record_id'=>'1001','state'=>'not_retained','occurred_at_unix'=>$at+1800,'minimum_age_seconds'=>1800],'leave before 1800 is exact not-retained receipt');
same($mature[1]['membership_receipts'][1]['state'],'retained','exact per-user 30-minute queue receipt is retained');
same($mature[1]['membership_receipts'][1]['source_record_id'],'3001','retained receipt is owned by actual queue row');
same(count($mature[1]['membership_receipts']),2,'duplicate join does not invent a rejoin period');
same(count($mature[0]['membership_receipts']),2,'wrong-source membership row excluded');
$rejoined=$repo->lookup('98615635',['789'],['not_before_unix'=>$notBefore,'cutoff_unix'=>$at+3000]);
same(array_column($rejoined[0]['membership_receipts'],'state'),['subscribed','not_retained','subscribed'],'rejoin with a timestamp-mismatched 30-minute queue receipt stays unknown');
$q=$p->prepare('INSERT INTO max_membership_events VALUES(?,?,?,?,?)');
for($id=1100;$id<1121;$id++)$q->execute([(string)$id,'404','10',1,'2026-07-15 12:10:00']);
$oversized=$repo->lookup('98615635',['404','456'],['not_before_unix'=>$notBefore,'cutoff_unix'=>$at+1800]);
same($oversized[0]['membership_receipts'],[],'oversized exact user fails closed as unknown without poisoning the batch');
same($oversized[1]['membership_receipts'][1]['source_record_id'],'3001','next exact user remains observable after one oversized history');
$p->exec("UPDATE max_sub_links SET UF_SUB_DATE='2026-10-25 00:30:00',UF_TYPE=1 WHERE ID=9007199254741002");
$p->exec("INSERT INTO max_membership_events VALUES(4001,'999','10',1,'2026-10-25 00:30:00'),(4002,'999','10',2,'2026-10-25 01:30:00')");
$p->exec("INSERT INTO max_no_unsub_goals VALUES(4003,9007199254741002,'2026-10-25 02:30:00',0)");
$ambiguous=$repo->lookup('98615635',['999'],['not_before_unix'=>strtotime('2026-10-24 00:00:00 UTC'),'cutoff_unix'=>strtotime('2026-10-25 03:00:00 UTC')]);
same($ambiguous[0]['membership_receipts'],[],'ambiguous London leave clock keeps the whole user unknown instead of inventing retained');
$historyInsert=$p->prepare('INSERT INTO max_membership_events VALUES(?,?,?,?,?)');
$linkInsert=$p->prepare('INSERT INTO max_sub_links VALUES(?,?,?,?,?,?,?,?,?)');
$checkInsert=$p->prepare('INSERT INTO max_no_unsub_goals VALUES(?,?,?,0)');
$sourceInsert=$p->prepare("INSERT INTO b_iblock_element_property VALUES(1,?,'98615635')");
$manyBase=new DateTimeImmutable('2026-07-15 13:00:00',new DateTimeZone('Europe/London'));
for($i=0;$i<20;$i++){
    $source=(string)(20+$i);$historyId=(string)(5000+$i);$linkId=(string)(7000+$i);$checkId=(string)(9000+$i);
    $joined=$manyBase->modify('+'.$i.' seconds')->format('Y-m-d H:i:s');$checked=$manyBase->modify('+'.(1800+$i).' seconds')->format('Y-m-d H:i:s');
    $sourceInsert->execute([$source]);$historyInsert->execute([$historyId,'606',$source,1,$joined]);
    $linkInsert->execute([$linkId,'606',(string)(6060000+$i),$joined,$source,$source,'ios',$joined,1]);
    $checkInsert->execute([$checkId,$linkId,$checked]);
}
$many=$repo->lookup('98615635',['606','456'],['not_before_unix'=>$manyBase->getTimestamp()-3601,'cutoff_unix'=>$manyBase->getTimestamp()+1820]);
same($many[0]['membership_receipts'],[],'twenty periods projecting forty receipts fail closed at the per-user twenty-receipt boundary');
same($many[1]['membership_receipts'][1]['source_record_id'],'3001','projected per-user overflow cannot poison the next exact user');
$dynamicNow=time();$dynamicAt=(new DateTimeImmutable('@'.($dynamicNow-1800)))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d H:i:s');
$p->prepare('INSERT INTO max_membership_events VALUES(?,?,?,?,?)')->execute(['2001','123','10',1,$dynamicAt]);
$p->prepare('UPDATE max_sub_links SET UF_SUB_DATE=?,UF_TYPE=1 WHERE ID=9007199254740991')->execute([$dynamicAt]);
$dynamicCheck=(new DateTimeImmutable('@'.$dynamicNow))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d H:i:s');
$p->prepare('INSERT INTO max_no_unsub_goals VALUES(?,?,?,0)')->execute(['3002','9007199254740991',$dynamicCheck]);
// Native MySQL JSON predicates must find a text/contact after arbitrary callback volume.
$p->prepare('INSERT INTO messages VALUES(10,1,\'max\',\'inbound\',\'customer\',?,?)')->execute(['2026-10-15 10:00:04','{bad']);
$p->exec('DELETE FROM messages WHERE id=7 OR id=9');$q=$p->prepare('INSERT INTO messages VALUES(?,1,\'max\',\'inbound\',\'customer\',?,?)');
for($id=11;$id<211;$id++)$q->execute([$id,'2026-10-15 10:00:04','{"type":"callback"}']);
$q->execute([211,'2026-10-15 10:00:04','{"type":"contact"}']);
require_once dirname(__DIR__).'/services/MaxChannelConversionOutbox.php';ProjectConfig::resetForTests(['id'=>'anytour']);$s=['project_key'=>'anytour','counter_id'=>'98615635','activated_at_unix'=>strtotime('2026-10-15 10:00:00 UTC')];
$o=new MaxChannelConversionOutbox($p);$o->activateSharedGoals(strtotime('2026-10-15 10:00:00 UTC'));$s=MaxChannelConversionOutbox::settings($p);$c=$o->conversation('1','-1234567890123456789',$s);same((int)$o->event($c,'max_customer_reply_after_manager',$s)['id'],211,'native JSON before limit and same-second order');
// InnoDB's conditional claim is shared between independent worker connections.
$o->capture($c,'max_bot_activity_start',$s,strtotime('2026-10-15 11:00:00 UTC'));
$o->resolve('1',['external_user_id'=>'1234567890123456789','client_id'=>'555','record_id'=>'1'],1792062000);
$second=new PDO(getenv('MAX_ATTRIBUTION_TEST_MYSQL_DSN'),getenv('MAX_ATTRIBUTION_TEST_MYSQL_USER')?:'root',getenv('MAX_ATTRIBUTION_TEST_MYSQL_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$t=$o->claim('1',1792062000);same(is_string($t),true,'first independent worker claims');same((new MaxChannelConversionOutbox($second))->claim('1',1792062000),null,'second independent worker cannot POST');
// The real handoff hook captures inside an outer transaction. A later committed
// event may advance the cursor before this transaction commits its lower ID.
foreach(['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS'] as $name)if(!defined($name))define($name,'fixture');
ProjectConfig::resetForTests(['id'=>'anytour']);
class MaxSearchApi{
    public static function metrikaGoalAllowed($chat):bool{return true;}
    public static function queueMetrikaGoal($chat,$target,?array $context=null):bool{return MaxChannelConversionOutbox::queueIfEligible($chat,$target,$context)??false;}
}
require_once dirname(__DIR__).'/services/ConversationControlService.php';
$p->exec('DELETE FROM conversation_events');$p->exec('ALTER TABLE conversation_events AUTO_INCREMENT=100');
$p->exec("UPDATE max_channel_attribution_settings SET mode='collect',message_cursor=211,request_event_cursor=0");
$p->exec("UPDATE conversations SET status='ai' WHERE id=7");
$p->exec("INSERT INTO customer_channels VALUES(4,'anytour','max','456')");
$p->exec("INSERT INTO conversations(id,customer_channel_id,project_key,channel,external_chat_id,is_test,status,source_id,started_at) VALUES(8,4,'anytour','max','-456',0,'waiting_manager',2,'2026-10-15 10:00:00')");
$second->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$second->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);$second->exec("SET time_zone='+00:00'");
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$p);
$p->beginTransaction();same(ConversationControlService::markWaitingByChat('max','-1234567890123456789'),true,'real handoff succeeds within caller transaction');
same($p->inTransaction(),true,'handoff preserves caller transaction');
$waitingId=(int)$p->query("SELECT id FROM conversation_events WHERE conversation_id=7 AND event_type='waiting_manager'")->fetchColumn();same($waitingId,100,'outer transaction owns lower event ID');
same((int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE conversation_id=7 AND target='max_manager_request'")->fetchColumn(),1,'actual hook captures outbox before outer commit');
same((int)$p->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=7 AND event_type='metrika_max_manager_request'")->fetchColumn(),1,'actual hook records marker within same transaction');
same((int)$second->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE conversation_id=7 AND target='max_manager_request'")->fetchColumn(),0,'uncommitted capture remains invisible to worker');
$second->exec("INSERT INTO conversation_events(conversation_id,event_type,created_at) VALUES(8,'waiting_manager',UTC_TIMESTAMP())");
$laterId=(int)$second->lastInsertId();same($laterId>$waitingId,true,'independent handoff commits greater event ID first');
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$second);
$independent=new MaxChannelConversionOutbox($second);$seen=[];$independent->collect(MaxChannelConversionOutbox::settings($second),function(int $id)use(&$seen){$seen[]=$id;MetrikaConversionGoalService::reconcileChannelConversation($id);});
same($seen,[8],'collector sees later committed handoff only');same((int)MaxChannelConversionOutbox::settings($second)['request_event_cursor'],$laterId,'collector advances past uncommitted lower ID');
$p->commit();
same((int)$second->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE conversation_id=7 AND target='max_manager_request'")->fetchColumn(),1,'late commit retains real request without relying on cursor replay');
same((int)$second->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=7 AND event_type='metrika_max_manager_request'")->fetchColumn(),1,'late marker commits atomically with request');
// A caller rollback also removes its handoff, conversion and deduplication marker.
$p->exec("DELETE FROM max_channel_conversion_outbox WHERE conversation_id=7");$p->exec('DELETE FROM conversation_events WHERE conversation_id=7');$p->exec("UPDATE conversations SET status='ai' WHERE id=7");
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$p);$p->beginTransaction();
same(ConversationControlService::markWaitingByChat('max','-1234567890123456789'),true,'real handoff captures before caller rollback');
same((int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE conversation_id=7 AND target='max_manager_request'")->fetchColumn(),1,'rollback fixture captured real request');$p->rollBack();
same((int)$second->query('SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE conversation_id=7')->fetchColumn(),0,'caller rollback removes conversion');same((int)$second->query('SELECT COUNT(*) FROM conversation_events WHERE conversation_id=7')->fetchColumn(),0,'caller rollback removes waiting event and marker');same($second->query('SELECT status FROM conversations WHERE id=7')->fetchColumn(),'ai','caller rollback restores conversation state');
// Reapplying only this uninstalled test migration retains the operator's mode.
$p->exec("UPDATE max_channel_attribution_settings SET mode='collect'");$p->exec(file_get_contents(dirname(__DIR__).'/migrations/037_max_channel_conversion_outbox.sql'));same($p->query('SELECT mode FROM max_channel_attribution_settings')->fetchColumn(),'collect','migration does not overwrite mode');
$p->exec("UPDATE max_channel_attribution_settings SET membership_receipts_outbox_cursor=9 WHERE project_key='anytour'");
$membershipActivation=(string)$p->query("SELECT membership_receipts_activated_at_unix FROM max_channel_attribution_settings WHERE project_key='anytour'")->fetchColumn();
$p->exec(file_get_contents(dirname(__DIR__).'/migrations/068_max_membership_receipt_bridge.sql'));
same((string)$p->query("SELECT membership_receipts_activated_at_unix FROM max_channel_attribution_settings WHERE project_key='anytour'")->fetchColumn(),$membershipActivation,'membership migration is idempotent and preserves prospective fence');
same((string)$p->query("SELECT membership_receipts_outbox_cursor FROM max_channel_attribution_settings WHERE project_key='anytour'")->fetchColumn(),'9','membership migration is idempotent and preserves work cursor');
echo 'PASS '.$checks." native MySQL tracker/queue checks\n";

