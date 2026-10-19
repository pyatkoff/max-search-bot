<?php

declare(strict_types=1);

$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/max-search-public-diag-'.bin2hex(random_bytes(4)).'.json';
require_once $root.'/services/ManagerPushSetupDiagnostics.php';
$fixture=[
    'ok'=>true,'production'=>['sha'=>'abc123'],'summary'=>['sessions'=>4,'flags'=>['repeat'=>2]],
    'sessions'=>[['conversation_id'=>91,'manager_id'=>5,'login'=>'Svetlana','message_tail'=>[['text'=>'PRIVATE TOURIST MESSAGE']]]],
    'manager_visibility'=>[['manager_id'=>5,'display_name'=>'Светлана','waiting'=>2]],
    'manager_push_health'=>[
        'working_manager_notification_path_ok'=>false,
        'working_manager_count'=>3,
        'usable_notification_path_count'=>1,
        'no_subscription_count'=>1,
        'unhealthy_subscription_count'=>1,
        'other_unusable_count'=>0,
        'working_managers'=>[['manager_id'=>5,'display_name'=>'Светлана','notification_path_reason'=>'subscription_unhealthy']],
        'unusable_notification_path_manager_ids'=>[5,8],
    ],
    'phone'=>'+70000000000','external_chat_id'=>'private-chat',
    'manager_ai_feedback'=>['ok'=>true,'complete'=>true,'counts'=>['persisted_proposals'=>3]],
    'manager_ai_feedback_private'=>[['proposed_text'=>'PRIVATE AI PROPOSAL','current_sent_text'=>'PRIVATE AI SENT','draft_id'=>str_repeat('a',32)]],
    'nested'=>['proposal_json'=>'PRIVATE AI JSON','proposed_text'=>'PRIVATE AI NESTED','current_sent_text'=>'PRIVATE AI CURRENT','sent_text_sha256'=>'PRIVATE AI HASH','context_revision'=>'PRIVATE AI CONTEXT','instruction_revision'=>'PRIVATE AI INSTRUCTIONS'],
];
$pushEvent=['ts'=>'PRIVATE PUSH TIMESTAMP','component'=>'manager_push','event'=>'setup_failed','data'=>[
    'schema'=>1,'manager_id'=>5,'browser_family'=>'Firefox','browser_major'=>145,'os_family'=>'macOS',
    'stage'=>'subscription_create','code'=>'AbortError','elapsed_ms'=>100,'permission'=>'granted',
    'secure_context'=>true,'worker_active'=>true,'worker_scope_matches'=>true,'web_locks'=>true,
    'key_length'=>65,'key_uncompressed'=>true,'trace'=>[['call'=>'subscribe','outcome'=>'AbortError','duration_ms'=>100]],
    'endpoint'=>'PRIVATE PUSH ENDPOINT','message'=>'PRIVATE PUSH ERROR','token'=>'PRIVATE PUSH TOKEN']];
$fixture['recent_manager_push_events']=[$pushEvent];
$fixture['manager']['push_setup']=ManagerPushSetupDiagnostics::summary([$pushEvent]);
file_put_contents($tmp,json_encode($fixture,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tools/sanitize_public_diagnostics.php').' '.escapeshellarg($tmp);
exec($cmd,$lines,$code);@unlink($tmp);$raw=implode("\n",$lines);$json=json_decode($raw,true);
$publish=(string)file_get_contents($root.'/.github/workflows/publish-conversation-diagnostics.yml');
$live=(string)file_get_contents($root.'/.github/workflows/live-session-diagnostics.yml');
$deploy=(string)file_get_contents($root.'/.github/workflows/deploy.yml');
$passed=0;$failed=0;
function publicDiagCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
publicDiagCheck('sanitizer preserves aggregate health data',$code===0&&is_array($json)&&!empty($json['public_redacted'])&&($json['summary']['sessions']??null)===4);
publicDiagCheck('sanitizer preserves manager push reason counts',($json['manager_push_health']['working_manager_count']??null)===3&&($json['manager_push_health']['usable_notification_path_count']??null)===1&&($json['manager_push_health']['no_subscription_count']??null)===1&&($json['manager_push_health']['unhealthy_subscription_count']??null)===1&&($json['manager_push_health']['other_unusable_count']??null)===0);
publicDiagCheck('sanitizer removes transcript and personal identifiers',strpos($raw,'PRIVATE TOURIST MESSAGE')===false&&strpos($raw,'Svetlana')===false&&strpos($raw,'Светлана')===false&&strpos($raw,'+70000000000')===false&&strpos($raw,'private-chat')===false&&strpos($raw,'conversation_id')===false&&strpos($raw,'manager_id')===false);
publicDiagCheck('raw push event collection is removed while safe sampled summary survives',!str_contains($raw,'recent_manager_push_events')&&!str_contains($raw,'PRIVATE PUSH')&&($json['manager']['push_setup']??null)===$fixture['manager']['push_setup']&&($json['manager']['push_setup']['failure_count']??null)===1&&!array_key_exists('ts',$json['manager']['push_setup']['latest']??[]));
publicDiagCheck('AI private samples and nested proposal fields never survive public sanitization',!str_contains($raw,'PRIVATE AI')&&!str_contains($raw,'manager_ai_feedback_private')&&!str_contains($raw,'draft_id')&&($json['manager_ai_feedback']['counts']['persisted_proposals']??null)===3);
$allowlist="find . -maxdepth 1 -type f -name '*.json' ! -name 'deploy_status.json' ! -name 'ops_status.json' ! -name 'autopilot_snapshot.json' ! -name 'architecture_inventory.json' ! -name 'public_live_status.json' ! -name 'public_daily_status.json' -delete";
publicDiagCheck('production publisher commits only public-safe aggregate artifacts',strpos($publish,'cp production_snapshot.json live_session_report.json')===false&&strpos($publish,$allowlist)!==false&&strpos($publish,'git add -A')!==false);
publicDiagCheck('AI private projection uses the existing encrypted production capture only',str_contains($publish,'--production production_snapshot.json')&&str_contains($publish,'--output "$RUNNER_TEMP/dialogue-evidence.age"')&&!str_contains($publish,'path: production_snapshot.json'));
publicDiagCheck('hourly live publisher removes every non-allowlisted json',strpos($live,'public_live_status.json')!==false&&strpos($live,$allowlist)!==false&&strpos($live,'git add live_sessions.json daily_sessions.json live_anomalies.json')===false);
publicDiagCheck('deploy telemetry does not republish downloaded raw json',strpos($deploy,"find ../production-diagnostics -maxdepth 1 -type f -name '*.json' -exec cp")===false&&strpos($deploy,'git add deploy_status.json *.json')===false&&strpos($deploy,$allowlist)!==false);
publicDiagCheck('raw deploy diagnostics stay outside production webroot',strpos($deploy,'MAX_SEARCH_DIAGNOSTICS_OUTPUT_DIR=\'/tmp/max-search-diagnostics-$EXPECTED_SHA\'')!==false&&strpos($deploy,'/app.anytoour.ru/diagnostics/*.json')===false&&strpos($deploy,'rm -f diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-*.json')!==false);
publicDiagCheck('every diagnostics writer removes the legacy raw artifact directory',substr_count($publish,'rm -rf production-diagnostics')>=1&&substr_count($live,'rm -rf production-diagnostics')>=1&&substr_count($deploy,'rm -rf production-diagnostics')>=1);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
