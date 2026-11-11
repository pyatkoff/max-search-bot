<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/max_channel_attribution.php';
require_once dirname(__DIR__).'/services/MaxTrackerLookupRepository.php';
if(!getenv('MAX_ATTRIBUTION_TEST_MYSQL_DSN'))throw new RuntimeException('native_mysql_fixture_required');
$p=maxAttributionFixture();$checks=0;
function same($a,$b,string $name):void{global $checks;$checks++;if($a!==$b)throw new RuntimeException('FAIL '.$name);}
function rejects(callable $f,string $name):void{global $checks;try{$f();}catch(Throwable $e){$checks++;return;}throw new RuntimeException('FAIL '.$name);}
function currentUnavailable(callable $f,string $name):void{try{$f();}catch(RuntimeException $e){same($e->getMessage(),'tracker_current_membership_unavailable',$name);return;}throw new RuntimeException('FAIL '.$name);}
$p->exec('CREATE TABLE max_sub_links (ID BIGINT PRIMARY KEY,UF_CHAT_ID VARCHAR(20),UF_CLIENT_ID VARCHAR(60),UF_DATE DATETIME NULL,UF_METRIC_SOURCE INT,UF_SOURCE INT,UF_PLATFORM VARCHAR(20),UF_SUB_DATE DATETIME NULL,UF_SUBDED INT NULL)');
$p->exec('CREATE TABLE b_iblock_property (ID BIGINT PRIMARY KEY,IBLOCK_ID BIGINT,CODE VARCHAR(64))');
$p->exec('CREATE TABLE b_iblock_element_property (IBLOCK_PROPERTY_ID BIGINT,IBLOCK_ELEMENT_ID BIGINT,VALUE VARCHAR(64))');
$p->exec('CREATE TABLE b_hlblock_entity (ID BIGINT PRIMARY KEY,TABLE_NAME VARCHAR(64))');
$p->exec('CREATE TABLE max_membership_events (ID BIGINT PRIMARY KEY,UF_CHAT_ID VARCHAR(20),UF_SOURCE VARCHAR(20),UF_TYPE INTEGER,UF_DATE DATETIME,KEY membership_user_date(UF_CHAT_ID,UF_SOURCE,UF_DATE,ID)) ENGINE=InnoDB');
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
    ['9007199254741000','456','1844674407370955161512345','2026-07-15 12:00:00',null,'10','ios','2026-07-15 12:00:00',1],
    ['9007199254741001','789','55555555555555555555','2026-01-15 12:00:00','10','11','android',null,null],
    ['9007199254741002','999','99999999999999999999','2026-10-25 01:30:00','10','11','android',null,null],
] as $r)$q->execute($r);
$before=(string)$p->query('SELECT COUNT(*) FROM max_sub_links')->fetchColumn();
$repo=new MaxTrackerLookupRepository($p);$rows=$repo->lookup('98615635',['123','456','789','999','404']);
same($rows[0]['record_id'],'9007199254740998','INT zero metric source uses fallback and latest numeric ID');same($rows[0]['client_id'],'777','INT zero fallback preserves the actual matching client');
$p->exec("UPDATE max_sub_links SET UF_CLIENT_ID='' WHERE ID=9007199254740998");
$overridden=$repo->lookup('98615635',['123']);
same($overridden[0]['record_id'],'9007199254740992','nonzero effective source overrides the raw source');
same($overridden[0]['client_id'],'22222222222222222222','wrong counter and property remain excluded');
$p->exec("UPDATE max_sub_links SET UF_CLIENT_ID='777' WHERE ID=9007199254740998");
same($rows[1]['client_id'],'1844674407370955161512345','large CID string');same($rows[1]['observed_at_unix'],strtotime('2026-07-15 11:00:00 UTC'),'summer London');
same($rows[2]['observed_at_unix'],strtotime('2026-01-15 12:00:00 UTC'),'winter London');same($rows[3]['observed_at_unix'],null,'ambiguous clock never guesses or loses CID');
same($rows[4],['external_user_id'=>'404','found'=>false],'exact unknown identity');same($p->inTransaction(),false,'readonly transaction rolled back');
same((string)$p->query('SELECT COUNT(*) FROM max_sub_links')->fetchColumn(),$before,'tracker rows unchanged');
// Real HL9 has a processing marker, not the fixture's former UF_TYPE state.
$memberNow=strtotime('2026-10-16 11:00:00 UTC');
currentUnavailable(fn()=>$repo->currentMemberPage('98615635','0',$memberNow),'processing marker cannot create a current-subscriber page');
same($p->inTransaction(),false,'unavailable legacy page never opens a transaction');
$p->exec("INSERT INTO max_no_unsub_goals VALUES(8801,9007199254741000,'2026-07-15 12:30:00',1)");
currentUnavailable(fn()=>$repo->currentMemberPage('98615635','0',$memberNow),'processing marker and old HL12 cannot create current membership');
same($p->inTransaction(),false,'unavailable legacy page with a queue row stays read only');
currentUnavailable(fn()=>$repo->currentMemberPage('98615635','9007199254741000',$memberNow),'a prior cursor cannot bypass the unavailable current-state contract');
rejects(fn()=>MaxTrackerLookupProtocol::body('98615635',['123'],$memberNow,null,null,'0'),'member-page scope cannot mix arbitrary user lookup');
$p->exec('DELETE FROM max_no_unsub_goals WHERE ID=8801');
$other=$repo->lookup('999',['123']);same($other[0]['record_id'],'9007199254740993','counter filtering precedes latest');
$p->exec("INSERT INTO max_membership_events VALUES
 (1001,'123','10',3,'2026-07-15 12:00:00'),
 (1002,'123','10',4,'2026-07-15 12:20:00'),
 (1003,'456','10',3,'2026-07-15 12:00:00'),
 (1004,'123','11',3,'2026-07-15 12:01:00'),
 (1005,'456','10',3,'2026-07-15 12:05:00'),
 (1006,'789','10',3,'2026-07-15 12:00:00'),
 (1007,'789','10',4,'2026-07-15 12:10:00'),
 (1008,'789','10',3,'2026-07-15 12:20:00')");
$p->exec("INSERT INTO max_no_unsub_goals VALUES(3001,9007199254741000,'2026-07-15 12:30:00',0)");
$p->exec("UPDATE max_sub_links SET UF_SUB_DATE='2026-07-15 12:19:59',UF_SUBDED=1 WHERE ID=9007199254741001");
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
$p->exec("UPDATE max_sub_links SET UF_SUBDED=0 WHERE ID=9007199254741000");
$pastCheck=$repo->lookup('98615635',['456'],['not_before_unix'=>$notBefore,'cutoff_unix'=>$at+1800]);
same($pastCheck[0]['membership_receipts'][1]['state'],'retained','current processing marker cannot rewrite an exact historical retention receipt');
$p->exec("UPDATE max_sub_links SET UF_SUBDED=1 WHERE ID=9007199254741000");
$p->exec("INSERT INTO max_membership_events VALUES(1009,'456','10',4,'2026-07-15 12:29:00')");
$conflictingCheck=$repo->lookup('98615635',['456'],['not_before_unix'=>$notBefore,'cutoff_unix'=>$at+1800]);
same(array_column($conflictingCheck[0]['membership_receipts'],'state'),['subscribed','not_retained'],'own early leave overrides even a linked positive HL12 queue record');
$p->exec('DELETE FROM max_membership_events WHERE ID=1009');

// HL10 history codes 1/2 are invalid; HL9 has no UF_TYPE column.
$p->exec("INSERT INTO max_membership_events VALUES(1010,'505','10',1,'2026-07-15 12:00:00'),(1011,'505','10',2,'2026-07-15 12:10:00')");
$wrongHistoryCodes=$repo->lookup('98615635',['505'],['not_before_unix'=>$notBefore,'cutoff_unix'=>$at+1800]);
same($wrongHistoryCodes[0]['membership_receipts'],[],'HL10 types 1/2 cannot create subscription or retention receipts');
$rejoined=$repo->lookup('98615635',['789'],['not_before_unix'=>$notBefore,'cutoff_unix'=>$at+3000]);
same(array_column($rejoined[0]['membership_receipts'],'state'),['subscribed','not_retained','subscribed'],'rejoin with a timestamp-mismatched 30-minute queue receipt stays unknown');
$q=$p->prepare('INSERT INTO max_membership_events VALUES(?,?,?,?,?)');
for($id=1100;$id<1121;$id++)$q->execute([(string)$id,'404','10',3,'2026-07-15 12:10:00']);
$oversized=$repo->lookup('98615635',['404','456'],['not_before_unix'=>$notBefore,'cutoff_unix'=>$at+1800]);
same($oversized[0]['membership_receipts'],[],'oversized exact user fails closed as unknown without poisoning the batch');
same($oversized[1]['membership_receipts'][1]['source_record_id'],'3001','next exact user remains observable after one oversized history');
$p->exec("UPDATE max_sub_links SET UF_SUB_DATE='2026-10-25 00:30:00',UF_SUBDED=1 WHERE ID=9007199254741002");
$p->exec("INSERT INTO max_membership_events VALUES(4001,'999','10',3,'2026-10-25 00:30:00'),(4002,'999','10',4,'2026-10-25 01:30:00')");
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
    $sourceInsert->execute([$source]);$historyInsert->execute([$historyId,'606',$source,3,$joined]);
    $linkInsert->execute([$linkId,'606',(string)(6060000+$i),$joined,$source,$source,'ios',$joined,1]);
    $checkInsert->execute([$checkId,$linkId,$checked]);
}
$many=$repo->lookup('98615635',['606','456'],['not_before_unix'=>$manyBase->getTimestamp()-3601,'cutoff_unix'=>$manyBase->getTimestamp()+1820]);
same($many[0]['membership_receipts'],[],'twenty periods projecting forty receipts fail closed at the per-user twenty-receipt boundary');
same($many[1]['membership_receipts'][1]['source_record_id'],'3001','projected per-user overflow cannot poison the next exact user');
$dynamicNow=time();$dynamicAt=(new DateTimeImmutable('@'.($dynamicNow-1800)))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d H:i:s');
$p->prepare('INSERT INTO max_membership_events VALUES(?,?,?,?,?)')->execute(['2001','123','10',3,$dynamicAt]);
$p->prepare('UPDATE max_sub_links SET UF_SUB_DATE=?,UF_SUBDED=1 WHERE ID=9007199254740991')->execute([$dynamicAt]);
$dynamicCheck=(new DateTimeImmutable('@'.$dynamicNow))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d H:i:s');
$p->prepare('INSERT INTO max_no_unsub_goals VALUES(?,?,?,0)')->execute(['3002','9007199254740991',$dynamicCheck]);
// Native MySQL JSON predicates must find a text/contact after arbitrary callback volume.
$p->prepare('INSERT INTO messages VALUES(10,1,\'max\',\'inbound\',\'customer\',?,?)')->execute(['2026-10-15 10:00:04','{bad']);
$p->exec('DELETE FROM messages WHERE id=7 OR id=9');$q=$p->prepare('INSERT INTO messages VALUES(?,1,\'max\',\'inbound\',\'customer\',?,?)');
for($id=11;$id<211;$id++)$q->execute([$id,'2026-10-15 10:00:04',json_encode(ConversationRecordClock::stamp(['type'=>'callback'],1792058404))]);
$q->execute([211,'2026-10-15 10:00:04',json_encode(ConversationRecordClock::stamp(['type'=>'contact'],1792058404))]);
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
maxAttributionStampKnownFixtureClocks($p);
$second->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$second->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);$second->exec("SET time_zone='+00:00'");
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$p);
$p->beginTransaction();same(ConversationControlService::markWaitingByChat('max','-1234567890123456789'),true,'real handoff succeeds within caller transaction');
same($p->inTransaction(),true,'handoff preserves caller transaction');
$waitingId=(int)$p->query("SELECT id FROM conversation_events WHERE conversation_id=7 AND event_type='waiting_manager'")->fetchColumn();same($waitingId,100,'outer transaction owns lower event ID');
same((int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE conversation_id=7 AND target='max_manager_request'")->fetchColumn(),1,'actual hook captures outbox before outer commit');
same((int)$p->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=7 AND event_type='metrika_max_manager_request'")->fetchColumn(),1,'actual hook records marker within same transaction');
same((int)$second->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE conversation_id=7 AND target='max_manager_request'")->fetchColumn(),0,'uncommitted capture remains invisible to worker');
$second->prepare("INSERT INTO conversation_events(conversation_id,event_type,created_at,payload_json) VALUES(8,'waiting_manager',UTC_TIMESTAMP(),?)")->execute([json_encode(ConversationRecordClock::stamp([]))]);
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

// History pages are a separate, observed-history contract. They never revive legacy member credit.
function historyExact(callable $f,string $expected,string $name):void{
    try{$f();}catch(RuntimeException|InvalidArgumentException $e){same($e->getMessage(),$expected,$name);return;}
    throw new RuntimeException('FAIL '.$name);
}
function historyPage(MaxTrackerLookupRepository $repo,string $after,int $now):array{
    $page=$repo->historyMemberPage('98615635',$after,$now);
    $keys=array_keys($page);sort($keys);
    $expected=['basis','rows','cutoff_unix','history_fence_id','next_cursor','has_more','scanned_event_count','omitted_unknown_count','coverage'];sort($expected);
    same($keys,$expected,'history page has an exact bounded nonfinancial shape');
    same($page['basis'],'retained_membership_history_v1','history page states its retained-history basis');
    same($page['coverage'],'bounded_retained_history_not_live_platform_verification','history page never claims live platform membership');
    same(is_array($page['rows'])&&array_is_list($page['rows'])&&count($page['rows'])<=5,true,'history page returns at most five rows');
    same(is_int($page['cutoff_unix'])&&$page['cutoff_unix']<=$now,true,'history snapshot has an explicit nonfuture cutoff');
    same(is_string($page['history_fence_id'])&&preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$page['history_fence_id'])===1,true,'history fence remains an exact decimal string');
    same(is_bool($page['has_more'])&&is_int($page['scanned_event_count'])&&$page['scanned_event_count']>=0&&$page['scanned_event_count']<=64,true,'history scan and continuation are bounded');
    same(is_int($page['omitted_unknown_count'])&&$page['omitted_unknown_count']>=0&&$page['omitted_unknown_count']<=64,true,'unknown history omissions are counted separately');
    same($page['has_more']?(is_string($page['next_cursor'])&&str_starts_with($page['next_cursor'],'mh1.')):$page['next_cursor']===null,true,'history continuation is opaque or explicitly terminal');
    foreach($page['rows']as$row){
        $keys=array_keys($row);sort($keys);
        $expected=['external_user_id','source_id','state','last_event_record_id','last_event_recorded_at_unix','episode_join_record_id','episode_join_recorded_at_unix','entry_basis'];sort($expected);
        same($keys,$expected,'history row contains evidence fields and no CID or money');
        foreach(['external_user_id','source_id','last_event_record_id','episode_join_record_id']as$key)
            same(MaxTrackerLookupProtocol::id($row[$key]),true,'history identity and evidence IDs stay exact strings');
        same($row['state'],'observed_subscribed','history state is observed rather than live verified');
        same(is_int($row['episode_join_recorded_at_unix'])&&is_int($row['last_event_recorded_at_unix'])
            &&$row['episode_join_recorded_at_unix']<=$row['last_event_recorded_at_unix']
            &&$row['last_event_recorded_at_unix']<=$page['cutoff_unix'],true,'history episode clocks are ordered inside the snapshot');
        same(in_array($row['entry_basis'],['after_observed_leave','first_retained_join'],true),true,'history entry basis is explicit');
    }
    return$page;
}
function historyAll(MaxTrackerLookupRepository $repo,int $now):array{
    $after='0';$rows=[];$fence=null;$cutoff=null;$omitted=0;
    for($i=0;$i<20;$i++){
        $page=historyPage($repo,$after,$now);
        if($fence===null){$fence=$page['history_fence_id'];$cutoff=$page['cutoff_unix'];}
        same([$page['history_fence_id'],$page['cutoff_unix']],[$fence,$cutoff],'history continuation keeps its original snapshot');
        foreach($page['rows']as$row){
            $key=$row['external_user_id'].':'.$row['source_id'];
            same(isset($rows[$key]),false,'history pages never repeat a person and physical channel');
            $rows[$key]=$row;
        }
        $omitted+=$page['omitted_unknown_count'];
        if(!$page['has_more'])return['rows'=>$rows,'omitted'=>$omitted,'fence'=>$fence];
        same($page['next_cursor']!==$after,true,'history cursor advances after every bounded scan');
        $after=$page['next_cursor'];
    }
    throw new RuntimeException('FAIL history continuation did not terminate');
}
$historyNow=strtotime('2026-11-05 12:00:00 UTC');
$historyOriginalTables=$p->query('SELECT ID,TABLE_NAME FROM b_hlblock_entity ORDER BY ID')->fetchAll(PDO::FETCH_ASSOC);
$historyOriginalMain=(string)$p->query('SELECT COUNT(*) FROM max_sub_links')->fetchColumn();
$historyOriginalEvents=(string)$p->query('SELECT COUNT(*) FROM max_membership_events')->fetchColumn();
$historyOriginalSources=$p->query('SELECT IBLOCK_PROPERTY_ID,IBLOCK_ELEMENT_ID,VALUE FROM b_iblock_element_property ORDER BY IBLOCK_PROPERTY_ID,IBLOCK_ELEMENT_ID,VALUE')->fetchAll(PDO::FETCH_ASSOC);
$p->exec('CREATE TABLE max_history_page_fixture (ID BIGINT PRIMARY KEY,UF_CHAT_ID VARCHAR(20),UF_SOURCE INT,UF_TYPE INT,UF_DATE DATETIME NULL,KEY history_user_date(UF_CHAT_ID,UF_SOURCE,UF_DATE,ID)) ENGINE=InnoDB');
$p->exec("UPDATE b_hlblock_entity SET TABLE_NAME='max_history_page_fixture' WHERE ID=10");
$p->exec("UPDATE b_hlblock_entity SET TABLE_NAME='max_history_page_no_main' WHERE ID=9");
$historyInsert=$p->prepare('INSERT INTO max_history_page_fixture VALUES(?,?,?,?,?)');
try{
    $empty=historyPage($repo,'0',$historyNow);
    same([$empty['rows'],$empty['history_fence_id'],$empty['next_cursor'],$empty['has_more'],$empty['scanned_event_count'],$empty['omitted_unknown_count']],[[],'0',null,false,0,0],'an empty saved history is explicit and not a missing-state guess');
    foreach([
        [1,'20001',10,3,'2026-11-05 11:00:00'],
        [2,'20002',10,3,'2026-11-05 11:00:00'],[3,'20002',10,3,'2026-11-05 11:05:00'],
        [4,'20003',10,3,'2026-11-05 10:00:00'],[5,'20003',10,4,'2026-11-05 10:30:00'],[6,'20003',10,3,'2026-11-05 11:00:00'],
        [7,'20004',10,3,'2026-11-05 11:00:00'],[8,'20004',10,4,'2026-11-05 11:30:00'],
        [9,'20005',10,3,'2026-11-05 11:00:00'],[10,'20005',10,4,'2026-11-05 11:00:00'],
        [11,'20006',10,4,'2026-11-05 11:00:00'],[12,'20006',10,3,'2026-11-05 11:00:00'],
        [13,'20001',20,3,'2026-11-05 11:10:00'],[14,'20007',11,3,'2026-11-05 11:00:00'],
        [15,'20008',10,3,'2026-10-25 01:30:00'],[16,'20009',10,3,null],
        [17,'20010',10,3,'2026-11-05 11:00:00'],[18,'20010',10,4,'2026-11-05 13:00:00'],
        [19,'20011',10,3,'2026-11-05 11:00:00'],[20,'20011',10,3,'2026-11-05 13:00:00'],
        [21,'20012',10,3,'2026-11-05 13:00:00']
    ]as$row)$historyInsert->execute($row);
    $basicCount=(string)$p->query('SELECT COUNT(*) FROM max_history_page_fixture')->fetchColumn();
    $basic=historyAll($repo,$historyNow);$observed=$basic['rows'];$keys=array_keys($observed);sort($keys);
    same($keys,['20001:10','20001:20','20002:10','20003:10','20006:10'],'history includes open episodes only, by exact person and physical channel');
    same([$observed['20002:10']['last_event_record_id'],$observed['20002:10']['episode_join_record_id']],['3','2'],'duplicate joins keep the first observed join of the open episode');
    same([$observed['20003:10']['episode_join_record_id'],$observed['20003:10']['entry_basis']],['6','after_observed_leave'],'leave then rejoin starts a new observed episode');
    same([$observed['20006:10']['episode_join_record_id'],$observed['20006:10']['entry_basis']],['12','after_observed_leave'],'same-clock event ID breaks a leave/join tie');
    same($observed['20001:10']['entry_basis'],'first_retained_join','a retained first join is not asserted to be first ever');
    same($basic['omitted']>=2,true,'ambiguous London and NULL clocks are omitted as unknown');
    same($basic['fence'],'21','the snapshot fence includes already retained events with future clocks');
    same(array_intersect(array_keys($observed),['20010:10','20011:10','20012:10']),[],'a retained future leave or join cannot revive an older subscribed episode');
    same($basic['omitted']>=5,true,'retained future clocks are reported as unknown alongside ambiguous and missing clocks');
    same((string)$p->query('SELECT COUNT(*) FROM max_history_page_fixture')->fetchColumn(),$basicCount,'history reads never mutate event rows');
    same($p->inTransaction(),false,'history page transaction is closed');

    $p->exec('DELETE FROM max_history_page_fixture');
    $historyInsert->execute([9000,'30001',10,3,'2026-09-01 11:00:00']);
    for($i=0;$i<70;$i++){
        $historyInsert->execute([10000+$i*2,(string)(31000+$i),10,3,'2026-11-05 10:00:00']);
        $historyInsert->execute([10001+$i*2,(string)(31000+$i),10,4,'2026-11-05 11:00:00']);
    }
    $first=historyPage($repo,'0',$historyNow);
    same([$first['rows'],$first['scanned_event_count'],$first['has_more']],[[],64,true],'sixty-four closed candidates yield an empty intermediate page with continuation');
    $cursor=$first['next_cursor'];
    same(is_array(MaxTrackerLookupProtocol::historyCursor($cursor,$historyNow,'98615635')),true,'a generated history cursor validates in its own snapshot');
    historyExact(fn()=>MaxTrackerLookupProtocol::historyCursor($cursor,$historyNow+901,'98615635'),'lookup_invalid_history_cursor','expired history cursor fails explicitly');
    historyExact(fn()=>MaxTrackerLookupProtocol::historyCursor($cursor,$historyNow,'999'),'lookup_invalid_history_cursor','history cursor is bound to its counter');
    historyExact(fn()=>MaxTrackerLookupProtocol::historyCursor('mh1.invalid',$historyNow,'98615635'),'lookup_invalid_history_cursor','malformed history cursor fails explicitly');
    $historyInsert->execute([20000,'39999',10,3,'2026-11-05 11:59:00']);
    $continued=historyPage($repo,$cursor,$historyNow);
    same(array_column($continued['rows'],'external_user_id'),['30001'],'an active person older than fifty newer events remains reachable');
    same([$continued['history_fence_id'],$continued['cutoff_unix']],[$first['history_fence_id'],$first['cutoff_unix']],'continuation freezes the history fence and cutoff');
    same($continued['next_cursor'],null,'the old snapshot terminates without the appended join');
    same($continued['has_more'],false,'post-fence append cannot create a new snapshot page');
    $fresh=historyAll($repo,$historyNow);
    same(isset($fresh['rows']['39999:10']),true,'a fresh snapshot observes the later appended event');
    $p->prepare("INSERT INTO b_iblock_element_property VALUES(1,?,'98615635')")->execute([700]);
    historyExact(fn()=>$repo->historyMemberPage('98615635',$cursor,$historyNow),'tracker_history_snapshot_changed','source-map changes invalidate an existing snapshot instead of mixing scope');
    $p->exec('DELETE FROM b_iblock_element_property WHERE IBLOCK_PROPERTY_ID=1 AND IBLOCK_ELEMENT_ID=700');

    $p->exec('DELETE FROM max_history_page_fixture');
    for($i=0;$i<8;$i++)$historyInsert->execute([30000+$i,(string)(40000+$i),10,3,'2026-11-05 11:00:00']);
    $limited=historyPage($repo,'0',$historyNow);
    same([count($limited['rows']),$limited['has_more']],[5,true],'five-row page cap retains a continuation');
    $all=historyAll($repo,$historyNow);
    same(count($all['rows']),8,'bounded history pages cover all active retained episodes without duplicates');
    $p->beginTransaction();
    historyExact(fn()=>$repo->historyMemberPage('98615635','0',$historyNow),'tracker_readonly_required','history reader refuses a caller-owned transaction');
    same($p->inTransaction(),true,'history refusal preserves the caller transaction');
    $p->rollBack();
}finally{
    if($p->inTransaction())$p->rollBack();
    $p->exec("UPDATE b_hlblock_entity SET TABLE_NAME='max_sub_links' WHERE ID=9");
    $p->exec("UPDATE b_hlblock_entity SET TABLE_NAME='max_membership_events' WHERE ID=10");
    $p->exec('DELETE FROM b_iblock_element_property WHERE IBLOCK_PROPERTY_ID=1 AND IBLOCK_ELEMENT_ID=700');
    $p->exec('DROP TABLE max_history_page_fixture');
}
same($p->query('SELECT ID,TABLE_NAME FROM b_hlblock_entity ORDER BY ID')->fetchAll(PDO::FETCH_ASSOC),$historyOriginalTables,'history test restores metadata exactly for existing lookup consumers');
same((string)$p->query('SELECT COUNT(*) FROM max_sub_links')->fetchColumn(),$historyOriginalMain,'history pages need no HL9 rows or processing-state writes');
same((string)$p->query('SELECT COUNT(*) FROM max_membership_events')->fetchColumn(),$historyOriginalEvents,'history pages preserve the existing native receipt fixture');
same($p->query('SELECT IBLOCK_PROPERTY_ID,IBLOCK_ELEMENT_ID,VALUE FROM b_iblock_element_property ORDER BY IBLOCK_PROPERTY_ID,IBLOCK_ELEMENT_ID,VALUE')->fetchAll(PDO::FETCH_ASSOC),$historyOriginalSources,'history reads and cursor refusals leave source mappings unchanged');

// Optional exact-counter evidence returns scalar buckets only and never falls back without a point index.
$scopeSources=$p->query('SELECT IBLOCK_PROPERTY_ID,IBLOCK_ELEMENT_ID,VALUE FROM b_iblock_element_property ORDER BY IBLOCK_PROPERTY_ID,IBLOCK_ELEMENT_ID,VALUE')->fetchAll(PDO::FETCH_ASSOC);
$p->exec('RENAME TABLE max_sub_links TO max_scope_saved_links');
try{
    $p->exec('CREATE TABLE max_sub_links (ID BIGINT PRIMARY KEY,UF_CHAT_ID VARCHAR(20),UF_CLIENT_ID VARCHAR(60),UF_DATE DATETIME NULL,UF_METRIC_SOURCE VARCHAR(32),UF_SOURCE VARCHAR(32),UF_PLATFORM VARCHAR(20),UF_SUB_DATE DATETIME NULL,UF_SUBDED INT NULL)');
    $p->exec("INSERT INTO b_iblock_element_property VALUES(1,910000,'98615635'),(1,910001,'999'),(1,910002,'98615635 '),(1,910003,'098615635'),(1,910004,'９８６１５６３５')");
    $scopeInsert=$p->prepare('INSERT INTO max_sub_links VALUES(?,?,?,?,?,?,?,?,?)');
    foreach([
        [1,'881001',null,'2026-11-11 11:00:00',null,'910000','ios',null,null],
        [2,'881001','','2026-11-11 11:00:00','','910000','ios',null,null],
        [3,'881001','0123','2026-11-11 11:00:00','910000','910001','ios',null,null],
        [4,'881001',"123\n",'2026-11-11 11:00:00','910000','910001','ios',null,null],
        [5,'881001','123456789012345678901234567890','2026-11-11 11:00:00','910000','910001','ios',null,null],
        [6,'881001','999','2026-11-11 11:00:00','910001','910000','ios',null,null],
        [7,'881001','888','2026-11-11 11:00:00','UNKNOWN','910000','ios',null,null],
        [8,'881001','777','2026-11-11 11:00:00',' ','910000','ios',null,null],
        [9,'881001','666','2026-11-11 11:00:00','０','910000','ios',null,null],
        [10,'881001','555','2026-11-11 11:00:00','0910000','910000','ios',null,null],
        [11,'881001','444','2026-11-11 11:00:00','910002','910000','ios',null,null],
        [12,'881001','333','2026-11-11 11:00:00','910003','910000','ios',null,null],
        [13,'881001','222','2026-11-11 11:00:00','910004','910000','ios',null,null],
        [14,'881001 ','111','2026-11-11 11:00:00','910000','910001','ios',null,null],
        [15,'881001','999','2026-11-11 11:00:00','0','910000','ios',null,null],
        [16,'881002','123','2026-11-11 11:00:00','910001','910000','ios',null,null],
        [17,'881001','１２３','2026-11-11 11:00:00','910000','910001','ios',null,null],
    ]as$fixture)$scopeInsert->execute($fixture);
    $scopeRepo=new MaxTrackerLookupRepository($p);$scopeAt=strtotime('2026-11-11 12:00:00 UTC');$scopeHash=str_repeat('a',64);
    $noIndex=$scopeRepo->counterScopeEvidence('98615635','881001',$scopeHash,$scopeAt);
    same([$noIndex['status'],$noIndex['reason'],$noIndex['scoped_rows']],['unavailable','point_index_unavailable',null],'missing point index is unknown without a scan');
    $p->exec('CREATE INDEX scope_prefix_only ON max_sub_links(UF_CHAT_ID(4))');
    same($scopeRepo->counterScopeEvidence('98615635','881001',$scopeHash,$scopeAt)['reason'],'point_index_unavailable','prefix index is not sufficient evidence of an exact user point bound');
    $p->exec('DROP INDEX scope_prefix_only ON max_sub_links');$p->exec('CREATE INDEX scope_user_point ON max_sub_links(UF_CHAT_ID,ID)');
    $normalBefore=$scopeRepo->lookup('98615635',['881001']);
    $point=$scopeRepo->counterScopeEvidence('98615635','881001',$scopeHash,$scopeAt);
    same([$point['status'],$point['complete_within_bound'],$point['scoped_rows'],$point['empty_client_id_rows'],
        $point['noncanonical_client_id_rows'],$point['canonical_client_id_rows']],['observed',true,6,2,3,1],
        'byte-empty fallback only and strict effective source/counter exclude whitespace nonascii leading-zero and other-counter aliases');
    same($scopeRepo->lookup('98615635',['881001']),$normalBefore,'optional counter evidence does not change ordinary legacy lookup semantics');
    same($p->inTransaction(),false,'optional evidence closes its read-only transaction');
    same($scopeRepo->counterScopeEvidence('98615635','881002',$scopeHash,$scopeAt)['scoped_rows'],0,'rows from another effective counter are not read as scoped presence');
    same($scopeRepo->counterScopeEvidence('98615635','881999',$scopeHash,$scopeAt)['scoped_rows'],0,'zero only concerns exact effective-counter scope');
    same($scopeRepo->counterScopeEvidence('999','881001',$scopeHash,$scopeAt)['reason'],'invalid_request','optional repository remains fixed to the authorized counter');
    for($i=0;$i<65;$i++)$scopeInsert->execute([100+$i,'881003','','2026-11-11 11:00:00','910000','910001','ios',null,null]);
    $overflow=$scopeRepo->counterScopeEvidence('98615635','881003',$scopeHash,$scopeAt);
    same([$overflow['status'],$overflow['reason'],$overflow['scoped_rows'],$overflow['empty_client_id_rows']],
        ['unavailable','row_limit',null,null],'65 sentinel never turns a partial scoped prefix into counts or zero');
    $p->beginTransaction();$busy=$scopeRepo->counterScopeEvidence('98615635','881001',$scopeHash,$scopeAt);
    same($busy['reason'],'storage_unavailable','caller transaction is not borrowed');same($p->inTransaction(),true,'caller transaction is preserved');$p->rollBack();
    same(str_contains(json_encode($point,JSON_THROW_ON_ERROR),'123456789012345678901234567890'),false,'raw ClientIDs never leave the scalar aggregate');
    same(array_keys($point),['v','counter_id','request_sha256','checked_at_unix','status','complete_within_bound',
        'scoped_rows','empty_client_id_rows','noncanonical_client_id_rows','canonical_client_id_rows'],'wire evidence has no row or native user identifiers');
}finally{
    if($p->inTransaction())$p->rollBack();
    $p->exec('DROP TABLE max_sub_links');$p->exec('RENAME TABLE max_scope_saved_links TO max_sub_links');
    $p->exec('DELETE FROM b_iblock_element_property WHERE IBLOCK_PROPERTY_ID=1 AND IBLOCK_ELEMENT_ID BETWEEN 910000 AND 910004');
}
same($p->query('SELECT IBLOCK_PROPERTY_ID,IBLOCK_ELEMENT_ID,VALUE FROM b_iblock_element_property ORDER BY IBLOCK_PROPERTY_ID,IBLOCK_ELEMENT_ID,VALUE')->fetchAll(PDO::FETCH_ASSOC),$scopeSources,'optional evidence restores only its isolated synthetic source mappings');

echo 'PASS '.$checks." native MySQL tracker/queue checks\n";


