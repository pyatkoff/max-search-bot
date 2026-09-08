<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/PaidDailyReport.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE conversations (id INTEGER PRIMARY KEY, project_key TEXT, channel TEXT, is_test INTEGER, status TEXT, external_chat_id TEXT, started_at TEXT)');
$pdo->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, conversation_id INTEGER, direction TEXT, sender_type TEXT, text TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE conversation_events (id INTEGER PRIMARY KEY, conversation_id INTEGER, event_type TEXT, created_at TEXT)');
$tmp=sys_get_temp_dir().'/paid-report-'.bin2hex(random_bytes(5));mkdir($tmp,0700);mkdir($tmp.'/traffic',0700);
$now=new DateTimeImmutable('2026-09-08 12:00:00',new DateTimeZone('UTC'));
function ck(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name."\n";}
$insert=$pdo->prepare('INSERT INTO conversations VALUES (?,?,?,?,?,?,?)');
foreach([
 [1,'search','max',0,'manager','-111','2026-09-03 21:59:59'], // Sep3 local
 [2,'search','max',0,'manager','-111','2026-09-03 22:00:00'], // repeat same chat Sep4
 [3,'search','max',0,'ai','-333','2026-09-04 10:00:00'], // no metadata
 [4,'search','max',1,'ai','-111','2026-09-04 10:00:00'], // test
 [5,'search','telegram',0,'ai','-111','2026-09-04 10:00:00'],
 [6,'other','max',0,'ai','-111','2026-09-04 10:00:00'],
 [7,'search','max',0,'ai','-111','2026-09-01 21:59:59'], // before 7 days
 [8,'search','max',0,'ai','-111','2026-09-08 11:00:00'],
 [9,'search','max',0,'ai','-111','2026-09-08 12:00:00'], // capture exclusive
 [10,'search','max',0,'ai','-444','2026-09-04 12:00:00'], // empty yclid
] as $c)$insert->execute($c);
file_put_contents($tmp.'/traffic/-111.json',json_encode(['yclid'=>'1234567890123456','raw'=>'SECRET_PAYLOAD','chat_id'=>'-111']));
file_put_contents($tmp.'/traffic/-444.json',json_encode(['yclid'=>'']));
$m=$pdo->prepare('INSERT INTO messages VALUES (?,?,?,?,?,?)');
foreach([
 [1,2,'outbound','ai','Готово! Проверьте параметры','2026-09-04 10:01:00'],
 [2,2,'inbound','customer','show_tours','2026-09-04 10:02:00'],
 [3,2,'inbound','customer','show_tours','2026-09-04 10:02:01'], // duplicates count once
 [4,2,'outbound','manager','PRIVATE_REPLY','2026-09-04 10:04:00'],
 [5,1,'inbound','customer','show_tours','2026-09-03 22:01:00'], // next day excluded
 [6,8,'inbound','customer','show_tours','2026-09-08 12:01:00'], // future excluded
] as $r)$m->execute($r);
$e=$pdo->prepare('INSERT INTO conversation_events VALUES (?,?,?,?)');
foreach([[1,2,'site_open','2026-09-04 10:03:00'],[2,2,'waiting_manager','2026-09-04 10:03:20'],[3,1,'waiting_manager','2026-09-03 22:01:00']] as $r)$e->execute($r);
$before=file_get_contents($tmp.'/traffic/-111.json');
date_default_timezone_set('Asia/Tokyo');
$r=PaidDailyReport::collect($pdo,$tmp,'search',$now);$days=array_column($r['days'],null,'date');
ck(count($days)===7 && isset($days['2026-09-02']),'seven local days including empty days');
ck($days['2026-09-03']['paid_new']===1 && $days['2026-09-04']['paid_new']===1,'UTC midnight and repeated chat cohorts');
ck($days['2026-09-04']['all_new']===3 && $days['2026-09-04']['without_saved_yclid']===2,'test channel project exclusions and missing attribution');
foreach(['needs_collected','tours_opened','site_opened','manager_requested','manager_replied'] as $f)ck($days['2026-09-04'][$f]===1,'same-day distinct '.$f);
ck($days['2026-09-03']['tours_opened']===0 && $days['2026-09-03']['manager_requested']===0,'later events and current manager status do not rewrite old day');
ck($days['2026-09-08']['paid_new']===1 && $days['2026-09-08']['tours_opened']===0 && $days['2026-09-08']['partial']===true,'capture bound and current partial day');
ck($days['2026-09-04']['partial']===false,'past day complete');
$encoded=json_encode($r);foreach(['1234567890123456','SECRET_PAYLOAD','PRIVATE_REPLY','external_chat_id','conversation_id','message_tail'] as $secret)ck(strpos($encoded,$secret)===false,'aggregate privacy '.$secret);
ck(file_get_contents($tmp.'/traffic/-111.json')===$before && !file_exists($tmp.'/traffic/-333.json'),'traffic reads never create or update records');
$resolvedChats=[];
$legacy=PaidDailyReport::collect($pdo,$tmp,'search',$now,static function(array $chatKeys)use(&$resolvedChats):array{
    $resolvedChats=$chatKeys;
    return ['-111'=>true,'-333'=>true,'-999'=>true];
},'current_saved_bitrix_yclid');
$legacyDays=array_column($legacy['days'],null,'date');
sort($resolvedChats);
ck($resolvedChats===['-111','-333','-444'],'batch resolver receives unique in-scope chat keys');
ck($legacy['attribution_basis']==='current_saved_bitrix_yclid','selected attribution basis is public');
ck($legacyDays['2026-09-04']['paid_new']===2 && $legacyDays['2026-09-04']['without_saved_yclid']===1,'external resolver drives paid cohort without leaking extra keys');
file_put_contents($tmp.'/traffic/-111.json','broken');
try{PaidDailyReport::collect($pdo,$tmp,'search',$now);throw new RuntimeException('expected invalid file failure');}catch(RuntimeException $e){ck($e->getMessage()==='paid_report_invalid_traffic_file','corrupt evidence is not counted as organic');}
file_put_contents($tmp.'/traffic/-111.json',$before);
for($i=11;$i<2011;$i++)$insert->execute([$i,'search','max',0,'ai','-111','2026-09-04 10:00:00']);
try{PaidDailyReport::collect($pdo,$tmp,'search',$now);throw new RuntimeException('expected bounded failure');}catch(RuntimeException $e){ck($e->getMessage()==='paid_report_conversation_limit','bounded collection never returns partial totals');}
foreach(glob($tmp.'/traffic/*') as $f)unlink($f);rmdir($tmp.'/traffic');rmdir($tmp);
echo "PAID DAILY REPORT OK\n";
