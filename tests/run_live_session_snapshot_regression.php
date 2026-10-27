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
lssCheck('post-tour manager CTA delivery is summarized from actual outbound evidence',strpos($analyzer,'isPostTourManagerCta')!==false&&strpos($analyzer,"'post_tour_manager_cta_shown'=>\$postTourManagerCtaAt!==null")!==false&&strpos($source,"'post_tour_manager_cta_shown'=>0")!==false&&strpos($source,"'post_tour_manager_cta_at'")!==false);
lssCheck('calendar-day summary counts CTA delivery by delivery timestamp',strpos($source,"\$postTourCtaAt=liveDiagnosticTs(\$session['post_tour_manager_cta_at']??null)")!==false&&strpos($source,"\$summary['calendar_day']['post_tour_manager_cta_shown']++")!==false);

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

// Exercise the real CLI and canonical Telegram status readers against an isolated,
// query-only SQLite store. No production config, worker, transport or HTTP is used.
$tgRoot=sys_get_temp_dir().'/live-tg-status-'.bin2hex(random_bytes(6));
mkdir($tgRoot.'/tools',0700,true);mkdir($tgRoot.'/services',0700,true);
copy($base.'/tools/live_session_snapshot.php',$tgRoot.'/tools/live_session_snapshot.php');
foreach(['ConversationRecordClock.php','TelegramDialogueConversions.php','MaxChannelConversionOutbox.php','MaxSubscriptionAttributionPolicy.php','InternalOwnerDialogueScope.php','LiveSessionAnalyzer.php','CallbackGeneration.php']as$file){
    copy($base.'/services/'.$file,$tgRoot.'/services/'.$file);
}
// The production config bootstrap does not preload ProjectConfig. Keep its stub
// on the canonical require path so the real CLI must load its own dependency.
file_put_contents($tgRoot.'/config.php',"<?php\n");
file_put_contents($tgRoot.'/services/ProjectConfig.php',"<?php\nclass ProjectConfig { public static function projectId(): string { return getenv('TG_STATUS_TEST_PROJECT') ?: 'anytour'; } }\n");
$tgDatabase=$tgRoot.'/fixture.sqlite';
file_put_contents($tgRoot.'/services/ConversationDb.php',"<?php\nclass ConversationDb { private static ?PDO \$connection=null; public static function isConfigured(): bool { return true; } public static function connection(): PDO { if(self::\$connection===null){ self::\$connection=new PDO(".var_export('sqlite:'.$tgDatabase,true)."); self::\$connection->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); self::\$connection->exec('PRAGMA query_only=ON'); } return self::\$connection; } }\n");
$tgDb=new PDO('sqlite:'.$tgDatabase);$tgDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$tgDb->exec('CREATE TABLE conversations (id INTEGER PRIMARY KEY,project_key TEXT,source_id INTEGER,channel TEXT,status TEXT,is_test INTEGER,test_source TEXT,test_reason TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT)');
$tgDb->exec('CREATE TABLE max_channel_attribution_settings (project_key TEXT,counter_id TEXT,goal_set TEXT,telegram_mode TEXT,telegram_activated_at_unix INTEGER,telegram_signal_floor INTEGER,telegram_signal_cursor INTEGER)');
$tgDb->exec("INSERT INTO max_channel_attribution_settings VALUES ('anytour','98615635','shared','send',1793040080,765432109,987654321)");
$tgDb->exec('CREATE TABLE max_channel_conversion_outbox (project_key TEXT,platform TEXT,state TEXT,external_user_id TEXT,client_id TEXT,upload_id TEXT)');
$tgInsert=$tgDb->prepare('INSERT INTO max_channel_conversion_outbox VALUES (?,?,?,?,?,?)');
foreach(['pending','ready','accepted','processed','unknown','unmatched','failed']as$state){$tgInsert->execute(['anytour','telegram',$state,'PRIVATE_TG_USER','PRIVATE_TG_CLIENT','PRIVATE_TG_UPLOAD']);}
$tgInsert->execute(['anytour','max','processed','PRIVATE_MAX_USER','PRIVATE_MAX_CLIENT','PRIVATE_MAX_UPLOAD']);
$tgInsert->execute(['foreign','telegram','processed','PRIVATE_FOREIGN_USER','PRIVATE_FOREIGN_CLIENT','PRIVATE_FOREIGN_UPLOAD']);
function lssTelegramRun(string $directory,string $project='anytour'):array
{
    $output=[];$exit=0;
    $command='TG_STATUS_TEST_PROJECT='.escapeshellarg($project).' '.escapeshellarg(PHP_BINARY)
        .' -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client '
        .escapeshellarg($directory.'/tools/live_session_snapshot.php').' 1';
    exec($command,$output,$exit);$raw=implode("\n",$output);
    return ['exit'=>$exit,'raw'=>$raw,'json'=>json_decode($raw,true)];
}
$tgBefore=hash_file('sha256',$tgDatabase);$tgStarted=time();$tgRun=lssTelegramRun($tgRoot);
$tgProjection=$tgRun['json']['telegram_dialogue_delivery']??null;
lssCheck('Telegram status is available through the real snapshot CLI without a worker or network',
    $tgRun['exit']===0&&($tgRun['json']['ok']??false)===true&&($tgProjection['available']??false)===true
    &&($tgProjection['mode']??null)==='send'&&($tgProjection['activated']??null)===true);
$tgAt=is_array($tgProjection)?($tgProjection['sampled_at']??''):'';
lssCheck('Telegram aggregate has a fresh explicit UTC sample time',
    is_string($tgAt)&&preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$tgAt)===1
    &&(int)strtotime($tgAt)>=$tgStarted&&(int)strtotime($tgAt)<=time());
lssCheck('Telegram stored states distinguish accepted processed unknown and failures without MAX or foreign rows',
    ($tgProjection['queue_total']??null)===7&&($tgProjection['queue_counts']??null)===['pending'=>1,'ready'=>1,'uploading'=>0,'accepted'=>1,'processed'=>1,'unknown'=>1,'unmatched'=>1,'failed'=>1,'expired'=>0,'retired'=>0]);
lssCheck('Telegram observation is read only and publishes only the fixed public schema',
    hash_file('sha256',$tgDatabase)===$tgBefore&&is_array($tgProjection)
    &&array_keys($tgProjection)===['sampled_at','available','error','mode','activated','queue_counts','queue_total']
    &&!str_contains($tgRun['raw'],'PRIVATE_')&&!str_contains($tgRun['raw'],'telegram_signal')
    &&!str_contains($tgRun['raw'],'765432109')&&!str_contains($tgRun['raw'],'987654321'));
$tgPublicInput=$tgRoot.'/public-input.json';file_put_contents($tgPublicInput,$tgRun['raw']);
$tgPublicLines=[];$tgPublicExit=0;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($base.'/tools/sanitize_public_diagnostics.php').' '.escapeshellarg($tgPublicInput),$tgPublicLines,$tgPublicExit);
$tgPublic=json_decode(implode("\n",$tgPublicLines),true);
lssCheck('the existing public sanitizer retains the safe Telegram projection',
    $tgPublicExit===0&&($tgPublic['public_redacted']??false)===true&&($tgPublic['telegram_dialogue_delivery']??null)===$tgProjection);
$tgForeign=lssTelegramRun($tgRoot,'foreign')['json']['telegram_dialogue_delivery']??[];
lssCheck('a foreign project is unavailable rather than an invented empty Telegram queue',
    ($tgForeign['available']??true)===false&&($tgForeign['error']??null)==='project_not_applicable'
    &&array_key_exists('queue_total',$tgForeign)&&$tgForeign['queue_total']===null&&$tgForeign['mode']===null);
$tgDb->exec("DELETE FROM max_channel_conversion_outbox; UPDATE max_channel_attribution_settings SET telegram_mode='off',telegram_activated_at_unix=NULL");
$tgEmpty=lssTelegramRun($tgRoot)['json']['telegram_dialogue_delivery']??[];
lssCheck('a successfully observed empty inactive transport remains a known empty status',
    ($tgEmpty['available']??false)===true&&($tgEmpty['mode']??null)==='off'&&($tgEmpty['activated']??null)===false
    &&($tgEmpty['queue_total']??null)===0&&array_sum($tgEmpty['queue_counts']??[-1])===0);
$tgDb->exec('DELETE FROM max_channel_attribution_settings');
$tgMissing=lssTelegramRun($tgRoot)['json']['telegram_dialogue_delivery']??[];
lssCheck('missing activation settings do not become zero counts or off mode',
    ($tgMissing['available']??true)===false&&($tgMissing['error']??null)==='status_unavailable'
    &&array_key_exists('queue_counts',$tgMissing)&&$tgMissing['queue_counts']===null&&$tgMissing['mode']===null&&$tgMissing['activated']===null);
$tgDb->exec("INSERT INTO max_channel_attribution_settings VALUES ('anytour','98615635','shared','send',1793040080,765432109,987654321)");
$tgDb->exec('DROP TABLE max_channel_conversion_outbox');
$tgBroken=lssTelegramRun($tgRoot)['json']['telegram_dialogue_delivery']??[];
lssCheck('failed queue reads remain unavailable without exposing database errors',
    ($tgBroken['available']??true)===false&&($tgBroken['error']??null)==='status_unavailable'
    &&$tgBroken['queue_counts']===null&&$tgBroken['queue_total']===null&&$tgBroken['mode']===null);
$tgDb->exec('CREATE TABLE max_channel_conversion_outbox (project_key TEXT,platform TEXT,state TEXT)');
$tgDb->exec("INSERT INTO max_channel_conversion_outbox VALUES ('anytour','telegram','PRIVATE_TG_INVALID_STATE')");
$tgInvalidRun=lssTelegramRun($tgRoot);$tgInvalid=$tgInvalidRun['json']['telegram_dialogue_delivery']??[];
lssCheck('unknown queue states fail closed without leaking their value',
    ($tgInvalid['available']??true)===false&&($tgInvalid['error']??null)==='status_invalid'
    &&$tgInvalid['queue_counts']===null&&!str_contains($tgInvalidRun['raw'],'PRIVATE_TG_INVALID_STATE'));
$tgDb=null;
$tgFiles=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tgRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($tgFiles as$file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($tgRoot);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
