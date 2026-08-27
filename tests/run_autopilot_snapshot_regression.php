<?php

declare(strict_types=1);
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/max-search-autopilot-'.bin2hex(random_bytes(4));mkdir($tmp,0777,true);
$fixtures=[
'production.json'=>['ok'=>true,'production'=>['sha'=>'abc123','branch'=>'main'],'migrations'=>[['version'=>'001','applied'=>true,'checksum_ok'=>true],['version'=>'002','applied'=>true,'checksum_ok'=>true]],'health'=>['manager_visibility_ok'=>true,'manager_response_ok'=>true],'manager_response_health'=>['pending'=>0,'overdue'=>0],'manager_push_health'=>[['manager_id'=>4,'status'=>'healthy']],'manager_visibility'=>[['manager_id'=>4,'waiting'=>0]],'handoff_integrity_health'=>['ok'=>true],'recent_messages'=>[['text'=>'SECRET CUSTOMER TEXT']]],
'live.json'=>['ok'=>true,'window_hours'=>1,'since_utc'=>'2026-08-27 16:00:00','summary'=>['sessions'=>2,'tours_opened'=>1,'flags'=>['repeated_same_input'=>1]],'sessions'=>[['conversation_id'=>101,'flags'=>['repeated_same_input'],'drop_point'=>'needs','message_tail'=>[['text'=>'SECRET LIVE TEXT']]],['conversation_id'=>102,'flags'=>[]]]],
'handoff.json'=>['ok'=>true,'summary'=>['pending'=>0]],
'website.json'=>['ok'=>true,'summary'=>['smoke'=>'ok']],
'ops.json'=>['sha'=>'abc123','migration'=>'success','snapshot'=>'success','live_sessions'=>'success','website_smoke'=>'success','health'=>'success'],
];
foreach($fixtures as $name=>$data)file_put_contents($tmp.'/'.$name,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tools/compose_autopilot_snapshot.php').' '.implode(' ',array_map('escapeshellarg',array_map(fn($n)=>$tmp.'/'.$n,array_keys($fixtures))));
exec($cmd,$lines,$code);$raw=implode("\n",$lines);$json=json_decode($raw,true);$passed=0;$failed=0;
function aCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;}else{echo "FAIL  {$name}\n";$failed++;}}
aCheck('composer exits successfully',$code===0&&is_array($json)&&!empty($json['ok']));
aCheck('production sha is surfaced',($json['production']['sha']??null)==='abc123');
aCheck('migration summary is compact and healthy',($json['migrations']['total']??null)===2&&($json['migrations']['pending']??null)===0&&($json['migrations']['checksum_failures']??null)===0);
aCheck('manager health is surfaced',($json['manager']['response']['pending']??null)===0&&($json['manager']['push'][0]['status']??null)==='healthy');
aCheck('live funnel summary is surfaced',($json['live']['summary']['sessions']??null)===2&&($json['live']['summary']['tours_opened']??null)===1);
aCheck('flagged session metadata is surfaced',($json['live']['flagged_sessions'][0]['conversation_id']??null)===101&&in_array('repeated_same_input',$json['live']['flagged_sessions'][0]['flags']??[],true));
aCheck('compact snapshot excludes transcript text',strpos($raw,'SECRET CUSTOMER TEXT')===false&&strpos($raw,'SECRET LIVE TEXT')===false&&strpos($raw,'message_tail')===false&&strpos($raw,'recent_messages')===false);
aCheck('artifact pointers remain explicit',($json['artifacts']['production']??null)==='production_snapshot.json'&&($json['artifacts']['live']??null)==='live_session_report.json');
foreach(array_keys($fixtures) as $name)@unlink($tmp.'/'.$name);@rmdir($tmp);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
