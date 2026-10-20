<?php

declare(strict_types=1);
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/max-search-autopilot-'.bin2hex(random_bytes(4));mkdir($tmp,0777,true);
$fixtures=[
'production.json'=>['ok'=>true,'production'=>['sha'=>'abc123','branch'=>'main'],'migrations'=>[['version'=>'001','applied'=>true,'checksum_ok'=>true],['version'=>'002','applied'=>true,'checksum_ok'=>true]],'health'=>['manager_visibility_ok'=>true,'manager_response_ok'=>true],'manager_response_health'=>['pending'=>0,'overdue'=>0],'manager_push_health'=>[['manager_id'=>4,'status'=>'healthy']],'manager_visibility'=>[['manager_id'=>4,'waiting'=>0]],'handoff_integrity_health'=>['ok'=>true],'recent_messages'=>[['text'=>'SECRET CUSTOMER TEXT']]],
'live.json'=>['ok'=>true,'window_hours'=>1,'since_utc'=>'2026-08-27 16:00:00','summary'=>['sessions'=>2,'tours_opened'=>1,'flags'=>['repeated_same_input'=>1]],'sessions'=>[['conversation_id'=>101,'flags'=>['repeated_same_input'],'drop_point'=>'needs','message_tail'=>[['text'=>'SECRET LIVE TEXT']]],['conversation_id'=>102,'flags'=>[]]]],
'handoff.json'=>['ok'=>true,'summary'=>['pending'=>0]],
'website.json'=>['ok'=>true,'summary'=>['smoke'=>'ok']],
'ops.json'=>['sha'=>'abc123','migration'=>'success','snapshot'=>'success','live_sessions'=>'success','website_smoke'=>'success','health'=>'success'],
'daily.json'=>['ok'=>true,'window_hours'=>null,'since_utc'=>'2026-08-27 00:00:00','summary'=>['calendar_day'=>['conversations_started'=>2,'manager_requested'=>1]]],
'architecture.json'=>['ok'=>true,'schema_version'=>1,'generated_at'=>'2026-08-27T17:00:00Z','areas'=>['handlers'=>['files'=>10,'bytes'=>1000,'code_lines'=>200]],'hotspots'=>[['path'=>'handlers/Big.php','lines'=>900,'bytes'=>10000,'severity'=>'high']], 'signals'=>['runtime_ddl'=>[],'schema_infrastructure_ddl'=>['services/MigrationRunner.php'],'direct_sql_writes'=>['services/Example.php'],'authorization_mentions'=>[],'validation_mentions'=>[]]],
];
$fixtures['production.json']['paid_daily']=['ok'=>true,'days'=>[['date'=>'2026-08-27','paid_new'=>2,'tours_opened'=>1]]];
$fixtures['production.json']['channel_daily']=['ok'=>true,'days'=>[['date'=>'2026-08-27','channels'=>[['channel'=>'telegram','new_conversations'=>3]]]]];
$fixtures['production.json']['manager_ai_feedback']=['ok'=>true,'error'=>null,'generated_at'=>'2026-08-27T17:00:00Z','complete'=>false,'draft_limit_reached'=>true,'message_limit_reached'=>false,'counts'=>[],'window'=>['since'=>'2026-08-20 17:00:00','until'=>'2026-08-27 17:00:00','timezone'=>'UTC','days'=>7,'basis'=>'persisted_generation_cohort'],'limits'=>['draft_rows'=>1000,'message_rows'=>5000,'samples'=>10,'sample_characters'=>2000],'private_canary'=>'PRIVATE AI SUMMARY'];
$fixtures['production.json']['manager_ai_feedback_private']=[['proposed_text'=>'PRIVATE AI PROPOSAL','current_sent_text'=>'PRIVATE AI SENT','draft_id'=>str_repeat('a',32)]];
$fixtures['production.json']['manager_visibility'][0]['load_ms']=['all'=>12.3,'mine'=>4.5,'waiting'=>2.1];
$fixtures['production.json']['manager_mcp_discovery']=['sampled_count'=>1,'recent_attempts'=>[
    ['at'=>'2026-10-19T13:39:00Z','credential_header'=>'missing','http_status'=>401,'stage'=>'credentials','rpc_error'=>-32001]]];
$fixtures['production.json']['manager_preparation']=['tick'=>['available'=>true,'state'=>'awaiting_connection','stale'=>false], 'connections'=>['available'=>true,'active'=>0,'expired'=>1]];
$pushDiagnostic=['schema'=>1,'manager_id'=>77,'browser_family'=>'Firefox','browser_major'=>145,'os_family'=>'macOS',
    'stage'=>'subscription_create','code'=>'AbortError','elapsed_ms'=>100,'permission'=>'granted',
    'secure_context'=>true,'worker_active'=>true,'worker_scope_matches'=>true,'web_locks'=>true,
    'key_length'=>65,'key_uncompressed'=>true,'trace'=>[['call'=>'subscribe','outcome'=>'AbortError','duration_ms'=>100,'stack'=>'PRIVATE PUSH STACK']],
    'endpoint'=>'PRIVATE PUSH ENDPOINT','token'=>'PRIVATE PUSH TOKEN','message'=>'PRIVATE PUSH ERROR'];
$pushEvent=['ts'=>'2026-10-17T15:21:09+03:00','component'=>'manager_push','event'=>'setup_failed','data'=>$pushDiagnostic,'actor'=>'PRIVATE PUSH ACTOR'];
$network=$pushEvent;$network['data']['code']='AbortError/PUSH_NETWORK';$network['data']['trace']=[
    ['call'=>'subscribe','outcome'=>'AbortError','duration_ms'=>10],['call'=>'recovery_read','outcome'=>'none','duration_ms'=>1],
    ['call'=>'retry_subscribe','outcome'=>'AbortError/PUSH_NETWORK','duration_ms'=>20]];
$unknown=$pushEvent;$unknown['data']['code']='PRIVATE PUSH CODE';
$invalid=$pushEvent;$invalid['data']['elapsed_ms']='PRIVATE PUSH DURATION';
$browser=$pushEvent;$browser['data']['browser_family']='PRIVATE PUSH BROWSER';
$successful=$pushEvent;$successful['data']['trace'][0]['outcome']='subscription';
$events=[$pushEvent,$network,$unknown,$invalid,$browser,$successful,['component'=>'other','event'=>'setup_failed','data'=>$pushDiagnostic],null];
while(count($events)<50)$events[]=['component'=>'manager_push','event'=>'delivery_failed','data'=>['error'=>'PRIVATE PUSH DELIVERY']];
$outsideSample=$pushEvent;$outsideSample['data']['code']='SecurityError';$outsideSample['data']['trace'][0]['outcome']='SecurityError';$events[]=$outsideSample;
$fixtures['production.json']['recent_manager_push_events']=$events;
$fixtures['production.json']['health']['manager_push_ok']=false;
foreach($fixtures as $name=>$data)file_put_contents($tmp.'/'.$name,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tools/compose_autopilot_snapshot.php').' '.implode(' ',array_map('escapeshellarg',array_map(fn($n)=>$tmp.'/'.$n,array_keys($fixtures))));
exec($cmd,$lines,$code);$raw=implode("\n",$lines);$json=json_decode($raw,true);$passed=0;$failed=0;
function aCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;}else{echo "FAIL  {$name}\n";$failed++;}}
aCheck('composer exits successfully',$code===0&&is_array($json)&&!empty($json['ok']));
aCheck('paid cohort aggregate survives composition',($json['paid_daily']['days'][0]['paid_new']??null)===2);
aCheck('channel aggregate survives composition',($json['channel_daily']['days'][0]['channels'][0]['new_conversations']??null)===3);
aCheck('AI aggregate preserves incomplete coverage while dropping private and unknown fields',($json['manager_ai_feedback']['ok']??null)===true&&($json['manager_ai_feedback']['complete']??null)===false&&($json['manager_ai_feedback']['draft_limit_reached']??null)===true&&!str_contains($raw,'PRIVATE AI')&&!array_key_exists('manager_ai_feedback_private',$json));
aCheck('production sha is surfaced',($json['production']['sha']??null)==='abc123');
aCheck('MCP connection evidence survives composition',($json['manager_mcp_discovery']??null)===$fixtures['production.json']['manager_mcp_discovery']);
aCheck('preparation heartbeat and connection state survive composition',($json['manager_preparation']??null)===$fixtures['production.json']['manager_preparation']);
aCheck('migration summary is compact and healthy',($json['migrations']['total']??null)===2&&($json['migrations']['pending']??null)===0&&($json['migrations']['checksum_failures']??null)===0);
aCheck('manager health is surfaced',($json['manager']['response']['pending']??null)===0&&($json['manager']['push'][0]['status']??null)==='healthy');
aCheck('bounded real-database loading durations survive composition',($json['manager']['visibility'][0]['load_ms']??null)===['all'=>12.3,'mine'=>4.5,'waiting'=>2.1]);
aCheck('live funnel summary is surfaced',($json['live']['summary']['sessions']??null)===2&&($json['live']['summary']['tours_opened']??null)===1);
aCheck('flagged session metadata is surfaced',($json['live']['flagged_sessions'][0]['conversation_id']??null)===101&&in_array('repeated_same_input',$json['live']['flagged_sessions'][0]['flags']??[],true));
aCheck('architecture control-plane summary is surfaced',($json['architecture']['areas']['handlers']['files']??null)===10&&($json['architecture']['hotspots'][0]['path']??null)==='handlers/Big.php'&&($json['architecture']['signal_counts']['direct_sql_writes']??null)===1&&($json['architecture']['signal_counts']['runtime_ddl']??null)===0);
aCheck('compact snapshot excludes transcript text',strpos($raw,'SECRET CUSTOMER TEXT')===false&&strpos($raw,'SECRET LIVE TEXT')===false&&strpos($raw,'message_tail')===false&&strpos($raw,'recent_messages')===false);
aCheck('artifact pointers expose public-safe files only',array_keys((array)($json['artifacts']??[]))===['ops','architecture']&&($json['artifacts']['architecture']??null)==='architecture_inventory.json');
$pushSetup=$json['manager']['push_setup']??[];
aCheck('setup observations are bounded client reports and never change push health',($pushSetup['source']??null)==='client_reported'&&($pushSetup['sample_limit']??null)===50&&($pushSetup['failure_count']??null)===2&&($pushSetup['native_cause_available']??null)===false&&($json['health']['manager_push_ok']??null)===false);
aCheck('only valid native failures inside first fifty events affect code and terminal-call counts',($pushSetup['by_code']??null)===['AbortError'=>1,'AbortError/PUSH_NETWORK'=>1]&&($pushSetup['by_call']??null)===['subscribe'=>1,'retry_subscribe'=>1]);
$latest=$pushSetup['latest']??[];
aCheck('latest observation reprojects native data and validates coarse metadata and timestamp',($latest['code']??null)==='AbortError'&&($latest['browser_family']??null)==='Firefox'&&($latest['browser_major']??null)===145&&($latest['os_family']??null)==='macOS'&&($latest['ts']??null)==='2026-10-17T12:21:09Z'&&!array_key_exists('manager_id',$latest));
aCheck('raw and invalid setup observations never enter composed snapshot',!str_contains($raw,'PRIVATE PUSH')&&!str_contains($raw,'recent_manager_push_events')&&!str_contains(json_encode($pushSetup),'manager_id'));
file_put_contents($tmp.'/composed.json',$raw);
$publicLines=[];exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tools/sanitize_public_diagnostics.php').' '.escapeshellarg($tmp.'/composed.json'),$publicLines,$publicCode);
$publicRaw=implode("\n",$publicLines);$public=json_decode($publicRaw,true);
aCheck('public diagnostics retain fixed-category MCP failure evidence',($public['manager_mcp_discovery']??null)===$fixtures['production.json']['manager_mcp_discovery']);
aCheck('public diagnostics retain aggregate preparation health',($public['manager_preparation']??null)===$fixtures['production.json']['manager_preparation']);
aCheck('actual public sanitizer preserves only the safe composed setup summary',$publicCode===0&&!empty($public['public_redacted'])&&($public['manager']['push_setup']??null)===$pushSetup&&!str_contains($publicRaw,'PRIVATE PUSH')&&!str_contains($publicRaw,'manager_id'));
@unlink($tmp.'/composed.json');
foreach(array_keys($fixtures) as $name)@unlink($tmp.'/'.$name);@rmdir($tmp);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
