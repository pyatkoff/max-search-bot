<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$source=(string)file_get_contents($base.'/tools/live_session_snapshot.php');
$workflow=(string)file_get_contents($base.'/.github/workflows/live-session-diagnostics.yml');
$analyzer=(string)file_get_contents($base.'/services/LiveSessionAnalyzer.php');
$migration=(string)file_get_contents($base.'/migrations/024_test_conversation_provenance.sql');
$passed=0;$failed=0;
function lssCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

lssCheck('snapshot passes diagnostic window into anomaly analyzer',strpos($source,'LiveSessionAnalyzer::analyze($conversation,$messages,$events,$sinceTs)')!==false);
lssCheck('flagged sessions expose evidence from the same diagnostic window',strpos($source,'liveDiagnosticWindowMessages($messages,$sinceTs)')!==false&&strpos($source,"\$session['message_tail']=liveDiagnosticMessageTail(\$evidence?:\$messages)")!==false);
lssCheck('message evidence is capped to recent tail',strpos($source,'array_slice($messages,-max(1,$limit))')!==false && strpos($source,'int $limit=24')!==false);
lssCheck('message text is compacted and truncated',strpos($source,'mb_strlen($text)>280')!==false && strpos($source,"mb_substr(\$text,0,277).'...'")!==false);
lssCheck('unflagged sessions do not receive message evidence',strpos($source,"if(!empty(\$session['flags']))")!==false);
lssCheck('newer main push cancels stale diagnostics waiter',strpos($workflow,"group: production-live-session-diagnostics")!==false&&strpos($workflow,"cancel-in-progress: true")!==false);
lssCheck('test provenance migration is explicit and immutable',strpos($migration,'ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0')!==false&&strpos($migration,'ADD COLUMN test_source VARCHAR(64) NULL')!==false&&strpos($migration,'ADD COLUMN test_reason VARCHAR(255) NULL')!==false);
lssCheck('session analyzer carries explicit test provenance',strpos($analyzer,"'is_test'=>!empty(\$conversation['is_test'])")!==false&&strpos($analyzer,"'test_source'=>(string)(\$conversation['test_source']??'')")!==false);
lssCheck('session analyzer exposes source resolution without guessing',strpos($analyzer,"'source_id'=>isset(\$conversation['source_id'])?(int)\$conversation['source_id']:null")!==false&&strpos($analyzer,"'source_resolved'=>isset(\$conversation['source_id'])&&(int)\$conversation['source_id']>0")!==false);
lssCheck('snapshot reads source id from canonical conversation row',strpos($source,'SELECT id,project_key,source_id,channel,status')!==false);
lssCheck('business summary exposes unresolved source volume and bounded ids',strpos($source,"'source_resolution'=>[")!==false&&strpos($source,"'unresolved_conversation_ids'=>[]")!==false&&strpos($source,"count(\$summary['source_resolution']['unresolved_conversation_ids'])<20")!==false);
lssCheck('business summary excludes only explicit test sessions',strpos($source,"\$businessSessions=array_values(array_filter(\$sessions,static fn(array \$session):bool=>empty(\$session['is_test'])))")!==false&&strpos($source,"'explicit_test_sessions'=>count(\$sessions)-count(\$businessSessions)")!==false);
lssCheck('raw sessions remain visible for diagnostics',strpos($source,"'raw_sessions'=>count(\$sessions)")!==false&&strpos($source,"\$result['sessions']=\$sessions")!==false);

$tmp=tempnam(sys_get_temp_dir(),'live-anomaly-');
$fixture=[
    'ok'=>true,'generated_at'=>'2026-08-27T18:01:24+00:00','window_hours'=>1,
    'sessions'=>[
        ['conversation_id'=>390,'status'=>'ai','needs_collected'=>true,'tours_opened'=>true,'drop_point'=>'tours_opened','flags'=>['repeated_callback_input','excessive_turns'],'last_message_at'=>'2026-08-27 17:31:00','message_tail'=>[
            ['direction'=>'inbound','sender_type'=>'customer','text'=>'nights_6_8'],
            ['direction'=>'outbound','sender_type'=>'ai','text'=>'🌙 На сколько ночей хотите поехать?'],
            ['direction'=>'inbound','sender_type'=>'customer','text'=>'month_change_09.2026'],
            ['direction'=>'outbound','sender_type'=>'ai','text'=>'📅 Когда хотите вылететь?'],
            ['direction'=>'inbound','sender_type'=>'customer','text'=>'month_change_09.2026'],
            ['direction'=>'outbound','sender_type'=>'ai','text'=>'📅 Когда хотите вылететь?'],
        ]],
        ['conversation_id'=>998,'status'=>'ai','needs_collected'=>false,'tours_opened'=>false,'drop_point'=>'collecting_needs','flags'=>['repeated_callback_input'],'last_message_at'=>'2026-08-27 17:59:00','message_tail'=>[
            ['direction'=>'inbound','sender_type'=>'customer','text'=>'nights_9_11'],
            ['direction'=>'inbound','sender_type'=>'customer','text'=>'nights_9_11'],
            ['direction'=>'inbound','sender_type'=>'customer','text'=>'nights_9_11'],
            ['direction'=>'inbound','sender_type'=>'customer','text'=>'Опять спрашивает по кругу'],
        ]],
        ['conversation_id'=>999,'status'=>'ai','needs_collected'=>false,'tours_opened'=>false,'drop_point'=>'collecting_needs','flags'=>['repeated_same_input'],'last_message_at'=>'2026-08-27 18:00:00','message_tail'=>[
            ['direction'=>'inbound','sender_type'=>'customer','text'=>'7 ночей'],
            ['direction'=>'outbound','sender_type'=>'ai','text'=>'🌙 На сколько ночей хотите поехать?'],
            ['direction'=>'inbound','sender_type'=>'customer','text'=>'7 ночей'],
            ['direction'=>'outbound','sender_type'=>'ai','text'=>'🌙 На сколько ночей хотите поехать?'],
        ]],
    ],
];
file_put_contents($tmp,json_encode($fixture,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$lines=[];$code=0;exec('php '.escapeshellarg($base.'/tools/compose_live_anomalies.php').' '.escapeshellarg($tmp),$lines,$code);@unlink($tmp);
$composed=json_decode(implode("\n",$lines),true);$anomalies=(array)($composed['anomalies']??[]);$ids=array_map(static fn($a)=>(int)($a['conversation_id']??0),$anomalies);
lssCheck('live anomaly composer executes',$code===0&&is_array($composed)&&!empty($composed['ok']));
lssCheck('callback-only noise is not promoted without user-visible failure evidence',!in_array(390,$ids,true));
lssCheck('repeated free-text answers remain actionable',in_array(999,$ids,true));
$callbackContext=null;foreach($anomalies as $a)if((int)($a['conversation_id']??0)===998)$callbackContext=$a;
lssCheck('repeated callback remains context when customer reports a stuck flow',is_array($callbackContext)&&in_array('customer_reports_stuck',(array)($callbackContext['signals']??[]),true)&&in_array('repeated_callback_input',(array)($callbackContext['signals']??[]),true));
$target=null;foreach($anomalies as $a)if((int)($a['conversation_id']??0)===999)$target=$a;
lssCheck('repeated bot question is context after independent signal',is_array($target)&&in_array('customer_repeated_answer',(array)($target['signals']??[]),true)&&in_array('bot_repeated_question_nights',(array)($target['signals']??[]),true));
lssCheck('bounded anomaly summary remains internally consistent',(int)($composed['summary']['ranked']??-1)===count($anomalies)&&((int)($composed['summary']['high']??0)+(int)($composed['summary']['medium']??0))===count($anomalies));

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
