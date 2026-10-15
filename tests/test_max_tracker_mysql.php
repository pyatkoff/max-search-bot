<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/max_channel_attribution.php';
require_once dirname(__DIR__).'/services/MaxTrackerLookupRepository.php';
if(!getenv('MAX_ATTRIBUTION_TEST_MYSQL_DSN'))throw new RuntimeException('native_mysql_fixture_required');
$p=maxAttributionFixture();$checks=0;
function same($a,$b,string $name):void{global $checks;$checks++;if($a!==$b)throw new RuntimeException('FAIL '.$name);}
$p->exec('CREATE TABLE max_sub_links (ID BIGINT PRIMARY KEY,UF_CHAT_ID VARCHAR(20),UF_CLIENT_ID VARCHAR(60),UF_DATE DATETIME NULL,UF_METRIC_SOURCE VARCHAR(20),UF_SOURCE VARCHAR(20),UF_PLATFORM VARCHAR(20))');
$p->exec('CREATE TABLE b_iblock_property (ID BIGINT PRIMARY KEY,IBLOCK_ID BIGINT,CODE VARCHAR(64))');
$p->exec('CREATE TABLE b_iblock_element_property (IBLOCK_PROPERTY_ID BIGINT,IBLOCK_ELEMENT_ID BIGINT,VALUE VARCHAR(64))');
$p->exec("INSERT INTO b_iblock_property VALUES(1,8,'YA_METRIK_ID'),(2,8,'CHANEL_ID'),(3,7,'YA_METRIK_ID')");
$p->exec("INSERT INTO b_iblock_element_property VALUES(1,10,'98615635'),(1,11,'999'),(2,12,'98615635'),(3,13,'98615635')");
$q=$p->prepare('INSERT INTO max_sub_links VALUES(?,?,?,?,?,?,?)');
foreach([
    ['9007199254740991','123','11111111111111111111','2026-10-15 11:00:00',null,'10','ios'],
    ['9007199254740992','123','22222222222222222222','2026-10-14 11:00:00','10','11','android'],
    ['9007199254740993','123','33333333333333333333','2026-10-15 13:00:00','11','10','desktop'],
    ['9007199254740994','123','','2026-10-15 13:01:00','10','10','ios'],
    ['9007199254740995','123','01','2026-10-15 13:02:00','10','10','ios'],
    ['9007199254740996','123','999','2026-10-15 13:03:00','12','10','ios'],
    ['9007199254740997','123','888','2026-10-15 13:04:00','13','10','ios'],
    ['9007199254740998','123','777','2026-10-15 13:05:00','0','10','ios'],
    ['9007199254740999','123',"666\n",'2026-10-15 13:06:00','10','10','ios'],
    ['9007199254741000','456','1844674407370955161512345','2026-07-15 12:00:00','','10','ios'],
    ['9007199254741001','789','55555555555555555555','2026-01-15 12:00:00','10','11','android'],
    ['9007199254741002','999','99999999999999999999','2026-10-25 01:30:00','10','11','android'],
] as $r)$q->execute($r);
$before=(string)$p->query('SELECT COUNT(*) FROM max_sub_links')->fetchColumn();
$repo=new MaxTrackerLookupRepository($p);$rows=$repo->lookup('98615635',['123','456','789','999','404']);
same($rows[0]['record_id'],'9007199254740992','numeric ID DESC before wall clock');same($rows[0]['client_id'],'22222222222222222222','effective source overrides fallback');
same($rows[1]['client_id'],'1844674407370955161512345','large CID string');same($rows[1]['observed_at_unix'],strtotime('2026-07-15 11:00:00 UTC'),'summer London');
same($rows[2]['observed_at_unix'],strtotime('2026-01-15 12:00:00 UTC'),'winter London');same($rows[3]['observed_at_unix'],null,'ambiguous clock never guesses or loses CID');
same($rows[4],['external_user_id'=>'404','found'=>false],'exact unknown identity');same($p->inTransaction(),false,'readonly transaction rolled back');
same((string)$p->query('SELECT COUNT(*) FROM max_sub_links')->fetchColumn(),$before,'tracker rows unchanged');
$other=$repo->lookup('999',['123']);same($other[0]['record_id'],'9007199254740993','counter filtering precedes latest');
// Native MySQL JSON predicates must find a text/contact after arbitrary callback volume.
$p->prepare('INSERT INTO messages VALUES(10,1,\'max\',\'inbound\',\'customer\',?,?)')->execute(['2026-10-15 10:00:04','{bad']);
$p->exec('DELETE FROM messages WHERE id=7 OR id=9');$q=$p->prepare('INSERT INTO messages VALUES(?,1,\'max\',\'inbound\',\'customer\',?,?)');
for($id=11;$id<211;$id++)$q->execute([$id,'2026-10-15 10:00:04','{"type":"callback"}']);
$q->execute([211,'2026-10-15 10:00:04','{"type":"contact"}']);
require_once dirname(__DIR__).'/services/MaxChannelConversionOutbox.php';$s=['project_key'=>'anytour','counter_id'=>'98615635','activated_at_unix'=>strtotime('2026-10-15 10:00:00 UTC')];
$o=new MaxChannelConversionOutbox($p);$c=$o->conversation('1','-1234567890123456789',$s);same((int)$o->event($c,'max_customer_reply_after_manager',$s)['id'],211,'native JSON before limit and same-second order');
// InnoDB's conditional claim is shared between independent worker connections.
$o->capture($c,'max_manager_reply',$s,strtotime('2026-10-15 11:00:00 UTC'));
$o->resolve('1',['external_user_id'=>'1234567890123456789','client_id'=>'555','record_id'=>'1'],1792062000);
$second=new PDO(getenv('MAX_ATTRIBUTION_TEST_MYSQL_DSN'),getenv('MAX_ATTRIBUTION_TEST_MYSQL_USER')?:'root',getenv('MAX_ATTRIBUTION_TEST_MYSQL_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$t=$o->claim('1',1792062000);same(is_string($t),true,'first independent worker claims');same((new MaxChannelConversionOutbox($second))->claim('1',1792062000),null,'second independent worker cannot POST');
// Reapplying only this uninstalled test migration retains the operator's mode.
$p->exec("UPDATE max_channel_attribution_settings SET mode='collect'");$p->exec(file_get_contents(dirname(__DIR__).'/migrations/037_max_channel_conversion_outbox.sql'));same($p->query('SELECT mode FROM max_channel_attribution_settings')->fetchColumn(),'collect','migration does not overwrite mode');
echo 'PASS '.$checks." native MySQL tracker/queue checks\n";
