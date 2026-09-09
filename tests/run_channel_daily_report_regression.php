<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/ChannelDailyReport.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE conversations (id INTEGER PRIMARY KEY, project_key TEXT, channel TEXT, is_test INTEGER, source_id INTEGER, started_at TEXT)');
$pdo->exec('CREATE TABLE messages (conversation_id INTEGER, direction TEXT, sender_type TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE conversation_events (conversation_id INTEGER, event_type TEXT, created_at TEXT)');
function checkChannel(bool $ok,string $name):void { if(!$ok)throw new RuntimeException($name);echo "PASS $name\n"; }
$q=$pdo->prepare('INSERT INTO conversations VALUES (?,?,?,?,?,?)');
foreach([
    [1,'search','max',0,1,'2026-09-08 21:59:59'],
    [2,'search','telegram',0,2,'2026-09-08 22:00:00'],
    [3,'search','website',0,null,'2026-09-01 10:00:00'],
    [4,'search','private-channel-label',0,4,'2026-09-09 09:00:00'],
    [5,'search','max',1,1,'2026-09-09 09:00:00'],
    [6,'other-project','telegram',0,2,'2026-09-09 09:00:00'],
    [7,'search','max',0,1,'2026-09-09 10:00:00'], // capture exclusive
    [8,'search','website',0,3,'2026-09-01 09:00:00'], // inactive old
] as $row)$q->execute($row);
$q=$pdo->prepare('INSERT INTO messages VALUES (?,?,?,?)');
foreach([
    [1,'inbound','customer','2026-09-08 22:00:00'],
    [1,'inbound','customer','2026-09-09 09:01:00'],
    [1,'outbound','manager','2026-09-09 09:02:00'],
    [1,'outbound','manager','2026-09-09 09:03:00'],
    [2,'inbound','customer','2026-09-09 09:00:00'],
    [2,'outbound','ai','2026-09-09 09:00:01'],
    [3,'outbound','manager','2026-09-09 08:00:00'], // reply-only continued dialogue
    [5,'inbound','customer','2026-09-09 09:00:00'],
    [6,'outbound','manager','2026-09-09 09:00:00'],
    [8,'inbound','customer','2026-09-09 10:00:00'], // future activity excluded
] as $row)$q->execute($row);
$q=$pdo->prepare('INSERT INTO conversation_events VALUES (?,?,?)');
foreach([
    [1,'waiting_manager','2026-09-08 21:59:59'], // historical request must not count today
    [2,'manager_request','2026-09-09 09:01:00'],
    [2,'waiting_manager','2026-09-09 09:01:01'], // same request, count conversation once
    [2,'request_manager','2026-09-09 09:02:00'],
    [3,'page_context','2026-09-09 08:00:00'],
    [5,'manager_request','2026-09-09 09:01:00'],
] as $row)$q->execute($row);
$now=new DateTimeImmutable('2026-09-09 10:00:00',new DateTimeZone('UTC'));
date_default_timezone_set('Asia/Tokyo');
$before=$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
$r=ChannelDailyReport::collect($pdo,'search',$now);
$days=array_column($r['days'],null,'date');$today=array_column($days['2026-09-09']['channels'],null,'channel');
checkChannel(count($days)===7 && isset($days['2026-09-03']),'seven local days, including zeros');
checkChannel($days['2026-09-09']['since_utc']==='2026-09-08 22:00:00' && $days['2026-09-09']['until_utc']==='2026-09-09 10:00:00','local midnight and capture bounds independent of server timezone');
checkChannel($today['max']['active_conversations']===1 && $today['max']['new_conversations']===0 && $today['max']['continued_conversations']===1,'continued MAX conversation and excluded test/future activity');
checkChannel($today['max']['manager_requested_conversations']===0 && $today['max']['manager_replied_conversations']===1,'historical request excluded; duplicate replies count once');
checkChannel($today['telegram']['new_conversations']===1 && $today['telegram']['inbound_conversations']===1 && $today['telegram']['manager_requested_conversations']===1 && $today['telegram']['manager_replied_conversations']===0,'Telegram included; project isolated; AI is not manager; requests deduplicated');
checkChannel($today['website']['continued_conversations']===1 && $today['website']['inbound_conversations']===0 && $today['website']['manager_replied_conversations']===1 && $today['website']['missing_source_conversations']===1,'website reply-only activity and missing source are explicit');
checkChannel($today['other']['new_conversations']===1,'unknown transports counted without publishing labels');
foreach($r['days'] as $day)foreach($day['channels'] as $c)checkChannel($c['active_conversations']===$c['new_conversations']+$c['continued_conversations'],'active equals new plus continued');
$json=json_encode($r);foreach(['private-channel-label','other-project','source_id','conversation_id','text','yclid'] as $private)checkChannel(strpos($json,$private)===false,'no private field '.$private);
checkChannel($pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn()===$before,'read-only collector');
$pdo->exec('DROP TABLE messages');
try{ChannelDailyReport::collect($pdo,'search',$now);throw new RuntimeException('missing table should fail');}catch(PDOException $e){checkChannel(true,'database failure cannot produce zero counts');}
echo "CHANNEL DAILY REPORT OK\n";
