<?php

declare(strict_types=1);

$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/max-search-public-diag-'.bin2hex(random_bytes(4)).'.json';
$fixture=[
    'ok'=>true,'production'=>['sha'=>'abc123'],'summary'=>['sessions'=>4,'flags'=>['repeat'=>2]],
    'sessions'=>[['conversation_id'=>91,'manager_id'=>5,'login'=>'Svetlana','message_tail'=>[['text'=>'PRIVATE TOURIST MESSAGE']]]],
    'manager_visibility'=>[['manager_id'=>5,'display_name'=>'Светлана','waiting'=>2]],
    'phone'=>'+70000000000','external_chat_id'=>'private-chat',
];
file_put_contents($tmp,json_encode($fixture,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tools/sanitize_public_diagnostics.php').' '.escapeshellarg($tmp);
exec($cmd,$lines,$code);@unlink($tmp);$raw=implode("\n",$lines);$json=json_decode($raw,true);
$publish=(string)file_get_contents($root.'/.github/workflows/publish-conversation-diagnostics.yml');
$live=(string)file_get_contents($root.'/.github/workflows/live-session-diagnostics.yml');
$deploy=(string)file_get_contents($root.'/.github/workflows/deploy.yml');
$passed=0;$failed=0;
function publicDiagCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
publicDiagCheck('sanitizer preserves aggregate health data',$code===0&&is_array($json)&&!empty($json['public_redacted'])&&($json['summary']['sessions']??null)===4);
publicDiagCheck('sanitizer removes transcript and personal identifiers',strpos($raw,'PRIVATE TOURIST MESSAGE')===false&&strpos($raw,'Svetlana')===false&&strpos($raw,'Светлана')===false&&strpos($raw,'+70000000000')===false&&strpos($raw,'private-chat')===false&&strpos($raw,'conversation_id')===false&&strpos($raw,'manager_id')===false);
$allowlist="find . -maxdepth 1 -type f -name '*.json' ! -name 'deploy_status.json' ! -name 'ops_status.json' ! -name 'autopilot_snapshot.json' ! -name 'architecture_inventory.json' ! -name 'public_live_status.json' ! -name 'public_daily_status.json' -delete";
publicDiagCheck('production publisher commits only public-safe aggregate artifacts',strpos($publish,'cp production_snapshot.json live_session_report.json')===false&&strpos($publish,$allowlist)!==false&&strpos($publish,'git add -A')!==false);
publicDiagCheck('hourly live publisher removes every non-allowlisted json',strpos($live,'public_live_status.json')!==false&&strpos($live,$allowlist)!==false&&strpos($live,'git add live_sessions.json daily_sessions.json live_anomalies.json')===false);
publicDiagCheck('deploy telemetry does not republish downloaded raw json',strpos($deploy,"find ../production-diagnostics -maxdepth 1 -type f -name '*.json' -exec cp")===false&&strpos($deploy,'git add deploy_status.json *.json')===false&&strpos($deploy,$allowlist)!==false);
publicDiagCheck('raw deploy diagnostics stay outside production webroot',strpos($deploy,'MAX_SEARCH_DIAGNOSTICS_OUTPUT_DIR=\'/tmp/max-search-diagnostics-$EXPECTED_SHA\'')!==false&&strpos($deploy,'/app.anytoour.ru/diagnostics/*.json')===false&&strpos($deploy,'rm -f diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-*.json')!==false);
publicDiagCheck('every diagnostics writer removes the legacy raw artifact directory',substr_count($publish,'rm -rf production-diagnostics')>=1&&substr_count($live,'rm -rf production-diagnostics')>=1&&substr_count($deploy,'rm -rf production-diagnostics')>=1);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
