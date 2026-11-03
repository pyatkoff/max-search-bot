<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ModeledConversionStream.php';
require_once dirname(__DIR__).'/services/ModeledConversionForecastStatusReader.php';

function modeledStreamSchema(PDO $p): void
{
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    foreach(['modeled_conversion_stream_versions','modeled_conversion_stream_sources','modeled_conversion_stream_state','advertising_visit_observations','conversation_events','messages','conversations','customer_channels']as$t)$p->exec('DROP TABLE IF EXISTS '.$t);
    $p->exec('CREATE TABLE customer_channels (id BIGINT PRIMARY KEY,customer_id BIGINT,project_key VARCHAR(40),channel VARCHAR(16),external_user_id VARCHAR(40))');
    $p->exec('CREATE TABLE conversations (id BIGINT PRIMARY KEY,customer_id BIGINT,customer_channel_id BIGINT,project_key VARCHAR(40),channel VARCHAR(16),is_test INT DEFAULT 0)');
    $p->exec('CREATE TABLE messages (id BIGINT PRIMARY KEY,conversation_id BIGINT,channel VARCHAR(16),direction VARCHAR(16),sender_type VARCHAR(16),text MEDIUMTEXT,metadata_json MEDIUMTEXT,created_at DATETIME)');
    $p->exec('CREATE TABLE conversation_events (id BIGINT PRIMARY KEY,conversation_id BIGINT,event_type VARCHAR(64),actor_type VARCHAR(16),payload_json TEXT,created_at DATETIME)');
    $p->exec('CREATE TABLE advertising_visit_observations (id BIGINT PRIMARY KEY,project_key VARCHAR(40),source_key VARCHAR(80),platform VARCHAR(16),counter_id VARCHAR(20),source_cursor VARCHAR(20),visit_id VARCHAR(128),visit_at_unix BIGINT,observed_at_unix BIGINT,external_user_id VARCHAR(32),identity_status VARCHAR(16),client_id VARCHAR(40),yclid VARCHAR(40),campaign_id VARCHAR(20),region_id VARCHAR(20),payload_sha256 CHAR(64),received_at_unix BIGINT)');
    if($mysql){
        $sql=file_get_contents(dirname(__DIR__).'/migrations/065_modeled_conversion_stream.sql');
        foreach(explode(';',$sql)as$s)if(trim($s)!=='')$p->exec($s);
        foreach(explode(';',$sql)as$s)if(trim($s)!=='')$p->exec($s);
    }else{
        $p->exec('CREATE TABLE modeled_conversion_stream_state (project_key TEXT PRIMARY KEY,enabled INT,started_at_unix BIGINT,state_json TEXT,quota_day TEXT,quota_used INT,ticks BIGINT,last_receipt_json TEXT)');
        $p->exec('CREATE TABLE modeled_conversion_stream_sources (project_key TEXT,source_kind TEXT,source_id BIGINT,latest_hash TEXT,version_no BIGINT,observed_at_unix BIGINT,PRIMARY KEY(project_key,source_kind,source_id))');
        $p->exec('CREATE TABLE modeled_conversion_stream_versions (id INTEGER PRIMARY KEY AUTOINCREMENT,project_key TEXT,source_kind TEXT,source_id BIGINT,version_no BIGINT,revision_hash TEXT,observed_at_unix BIGINT,snapshot_json TEXT,UNIQUE(project_key,source_kind,source_id,version_no))');
    }
    foreach([[1,'telegram'],[2,'max'],[10,'telegram'],[11,'max'],[12,'telegram'],[13,'telegram']]as[$id,$channel]){
        $project=$id===12?'anex':'anytour';
        $p->prepare('INSERT INTO customer_channels VALUES (?,?,?,?,?)')->execute([$id,$id,$project,$channel,(string)(9000+$id)]);
        $p->prepare('INSERT INTO conversations VALUES (?,?,?,?,?,?)')->execute([$id,$id,$id,$project,$channel,$id===13?1:0]);
    }
}

function modeledStreamMessage(PDO $p,int $id,int $conversation=10,string $text='Новый запрос',string $role='customer'): void
{
    $channel=$conversation===2||$conversation===11?'max':'telegram';
    $p->prepare('INSERT INTO messages VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$conversation,$channel,$role==='customer'?'inbound':'outbound',$role,$text,'{"type":"message"}','2026-10-26 08:00:00']);
}

function modeledStreamAssertions(PDO $p,callable $check): void
{
    $now=1793002000;$s=new ModeledConversionStream($p);
    $check('uninitialized tick is off and does not create state',$s->tick($now)['status']==='off'&&(int)$p->query('SELECT COUNT(*) FROM modeled_conversion_stream_state')->fetchColumn()===0);
    modeledStreamMessage($p,10);
    $s->start($now);
    $check('explicit start is prospective and copies no historical messages',$s->tick($now+1)['new_revisions']===0);
    foreach([[11,1],[12,2],[13,12],[14,13]]as[$id,$c])modeledStreamMessage($p,$id,$c,'excluded-private');
    modeledStreamMessage($p,15,11,'Есть туры?', 'customer');
    modeledStreamMessage($p,17,10,'Уточнение менеджера','manager');
    $p->exec("INSERT INTO conversation_events VALUES (1,11,'waiting_manager','customer','{}','2026-10-26 08:00:00')");
    $p->prepare('INSERT INTO advertising_visit_observations VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([1,'anytour','tgmini','telegram','98615635','1','visit-fixture',$now,$now,'9010','verified','client-fixture','click-fixture','','',str_repeat('a',64),$now]);
    $r=$s->tick($now+2);
    $check('both channels and manager/customer/event/visit sources enter one journal',$r['new_revisions']===4);
    $check('owner test and foreign projects never copy their private text',!str_contains((string)$p->query('SELECT GROUP_CONCAT(snapshot_json) FROM modeled_conversion_stream_versions')->fetchColumn(),'excluded-private'));
    $status=$s->status();
    $check('public status has counts but no native identities or source payload',!str_contains(json_encode($status),'client-fixture')&&!str_contains(json_encode($status),'Уточнение'));
    $check('retry is idempotent',$s->tick($now+3)['new_revisions']===0);
    // A transaction may reserve a smaller ID and commit after the forward cursor passed it.
    modeledStreamMessage($p,16,10,'Поздний commit');
    $check('bounded sweep recovers a late committed source behind forward cursor',$s->tick($now+4)['new_revisions']===1);
    $p->exec("UPDATE messages SET text='Изменённый запрос' WHERE id=15");
    $check('reconciliation captures edited text under the same native ID',$s->tick($now+5)['new_revisions']===1);
    $p->exec("UPDATE messages SET text='Есть туры?' WHERE id=15");
    $check('A to B to A keeps a third revision rather than deleting history',$s->tick($now+6)['new_revisions']===1&&(int)$p->query("SELECT version_no FROM modeled_conversion_stream_sources WHERE source_kind='message' AND source_id=15")->fetchColumn()===3);
    $p->exec('DELETE FROM messages WHERE id=15');
    $r=$s->tick($now+7);$check('physical deletion produces a distinct observed tombstone',$r['deleted']===1&&$r['new_revisions']===1);
    $p->exec('UPDATE conversations SET is_test=1 WHERE id=10');
    $r=$s->tick($now+8);$check('access scope change covers known messages and linked visit without reporting deletion',$r['scope_excluded']===3&&$r['deleted']===0);
    $p->exec('UPDATE conversations SET is_test=0 WHERE id=10');
    $check('restored scope resumes from preserved floors without a checkpoint reset',$s->tick($now+9)['new_revisions']===3);
    modeledStreamMessage($p,18,11,'Rollback must preserve me');
    $before=$p->query('SELECT state_json FROM modeled_conversion_stream_state')->fetchColumn();
    try{$s->tick($now+10,static function(){throw new RuntimeException('fixture_rollback');});$failed=false;}catch(Throwable $e){$failed=$e->getMessage()==='fixture_rollback';}
    $check('failed batch rolls back both journal and checkpoint',$failed&&$before===$p->query('SELECT state_json FROM modeled_conversion_stream_state')->fetchColumn()&&(int)$p->query("SELECT COUNT(*) FROM modeled_conversion_stream_sources WHERE source_kind='message' AND source_id=18")->fetchColumn()===0);
    $check('retry after rollback delivers the same source once',$s->tick($now+11)['new_revisions']===1);
    $s->stop();modeledStreamMessage($p,19,11);
    $check('stopped stream reads no native rows',$s->tick($now+12)['status']==='off');
    $s->start($now+13);$check('resume preserves earlier floors and processes pending continuation',$s->tick($now+14)['new_revisions']===1);
    try{$s->tick($now+1);$failed=false;}catch(Throwable $e){$failed=$e->getMessage()==='modeled_stream_clock_regressed';}
    $check('clock regression cannot backdate receipt or reset quota',$failed);
    $p->exec('UPDATE modeled_conversion_stream_state SET quota_used=10000');modeledStreamMessage($p,20,11);
    $r=$s->tick($now+15);$check('daily budget stops before source reads',$r['status']==='daily_limit'&&$r['native_rows_read']===0);
    $p->exec('UPDATE modeled_conversion_stream_state SET quota_used=9999');
    $r=$s->tick($now+16);$check('last daily slot is consumed exactly once',$r['new_revisions']===1&&$s->status()['quota_used']===10000);
    $p->exec('UPDATE modeled_conversion_stream_state SET quota_used=0');
    for($id=21;$id<721;$id++)modeledStreamMessage($p,$id,11,'массовый поток '.$id);
    $all=0;$bounded=true;
    for($i=0;$i<30;$i++){
        $r=$s->tick($now+20+$i);$all+=$r['new_revisions'];
        $bounded=$bounded&&$r['native_rows_read']<=500&&$r['payload_bytes']<=2097152;
    }
    $check('mass batches preserve bounded rows and bytes',$bounded);
    $check('multiple pages eventually deliver all700new sources',$all===700);
    modeledStreamMessage($p,721,11,str_repeat('sensitive-large',10000));
    $r=$s->tick($now+51);
    $row=$p->query("SELECT snapshot_json FROM modeled_conversion_stream_versions WHERE source_kind='message' AND source_id=721")->fetchColumn();
    $check('oversize source is retained as explicitly omitted metadata not silently truncated',$r['omitted_payloads']===1&&!str_contains($row,'sensitive-large')&&str_contains($row,'oversize_not_copied'));
    $check('source evidence never implies a trip model or export',$r['source_history_complete']===false&&$r['trip_status']==='unknown'&&$r['model_calls']===0&&$r['conversion_uploads']===0);
    $p->beginTransaction();try{$s->tick($now+52);$failed=false;}catch(Throwable $e){$failed=$e->getMessage()==='modeled_stream_outer_transaction';}finally{$p->rollBack();}
    $check('caller transaction cannot be committed by the observer',$failed);
    foreach([0,10800]as$offset){
        modeledStreamSchema($p);modeledProfileSchema($p);$s=new ModeledConversionStream($p);
        $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        if($mysql)$p->exec("SET time_zone='".($offset?'+03:00':'+00:00')."'");
        $s->start($now+100);
        modeledStreamMessage($p,1,11,'Бюджет до 200 тыс на всех');
        $p->prepare('UPDATE messages SET metadata_json=?,created_at=? WHERE id=1')->execute([
            json_encode(ConversationRecordClock::stamp(['type'=>'message'],$now+101)),gmdate('Y-m-d H:i:s',$now+101+$offset)]);
        $s->tick($now+102);
        $snap=json_decode($p->query('SELECT snapshot_json FROM modeled_conversion_stream_versions ORDER BY id DESC LIMIT 1')->fetchColumn(),true);
        $check('source journal uses explicit recording instant at session offset '.$offset,$snap['source_created_at_unix']===$now+101&&$snap['source_timestamp_basis']==='canonical_recording_unix_v1');
        $check('actual observation remains distinct from recording time at session offset '.$offset,(int)$p->query('SELECT observed_at_unix FROM modeled_conversion_stream_versions ORDER BY id DESC LIMIT 1')->fetchColumn()===$now+102);
        $profiles=new ModeledConversionProfiles($p);$profiles->start();$profiles->tick($now+103);$profile=modeledProfileLatest($p);
        $check('actual profile consumes the stamped journal at session offset '.$offset,$profile['profile']['fields']['budget']['value']['maximum']===200000&&$profile['coverage']['errors']===[]);
        if($mysql)$p->exec("SET time_zone='+00:00'");
    }

}

function modeledStreamSchedulerAssertions(callable $check): void
{
    foreach(['failure','success']as$case){
        $dir=sys_get_temp_dir().'/modeled-stream-fixture-'.bin2hex(random_bytes(8));mkdir($dir,0700);mkdir($dir.'/services',0700);
        copy(dirname(__DIR__).'/cron_metrika.php',$dir.'/cron_metrika.php');
        file_put_contents($dir.'/config.php',"<?php define('METRIKA_COUNTER_ID','123');define('METRIKA_OAUTH_TOKEN','fixture-no-http');");
        file_put_contents($dir.'/services/AdvertisingVisitTransport.php',"<?php class AdvertisingVisitTransport {static function tick(){return ['status'=>'off'];}}");
        file_put_contents($dir.'/services/MaxChannelConversionWorker.php',"<?php class MaxChannelConversionOutbox {static function settings(){return ['mode'=>'off'];}}");
        file_put_contents($dir.'/services/DiagnosticLogger.php',"<?php class DiagnosticLogger {static function log(...\$a){throw new RuntimeException('fixture-log-failure');}static function warning(...\$a){throw new RuntimeException('fixture-log-failure');}}");
        $action=$case==='failure'?"throw new RuntimeException('fixture-observer-failure');":"return ['status'=>'observed'];";
        file_put_contents($dir.'/services/ModeledConversionStream.php',"<?php class ModeledConversionStream {static function runtimeTick(){file_put_contents(__DIR__.'/ran','1');".$action."}}");
        try{
            $process=proc_open([PHP_BINARY,$dir.'/cron_metrika.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('fixture_process');
            fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
            $check('actual scheduler '.$case.' and telemetry failure preserve uploader exit/output',$code===0&&$out==="OK queue empty\n"&&$error===''&&is_file($dir.'/services/ran'));
        }finally{foreach(glob($dir.'/services/*')as$f)unlink($f);rmdir($dir.'/services');foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
    }
}


require_once dirname(__DIR__).'/services/ModeledConversionProfiles.php';

function modeledProfileSchema(PDO $p): void
{
    foreach(['modeled_conversion_profile_versions','modeled_conversion_profile_sources','modeled_conversion_profiles_state']as$t)$p->exec('DROP TABLE IF EXISTS '.$t);
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $sql=file_get_contents(dirname(__DIR__).'/migrations/066_modeled_conversion_profiles.sql');
        for($i=0;$i<2;$i++)foreach(explode(';',$sql)as$s)if(trim($s)!=='')$p->exec($s);
    }else{
        $p->exec('CREATE TABLE modeled_conversion_profiles_state(project_key TEXT PRIMARY KEY,enabled INT,journal_cursor BIGINT DEFAULT 0,ticks INT DEFAULT 0,last_receipt_json TEXT,last_error_at_unix BIGINT,last_error_category TEXT)');
        $p->exec('CREATE TABLE modeled_conversion_profile_sources(project_key TEXT,source_kind TEXT,source_id BIGINT,platform TEXT,customer_key TEXT,conversation_id TEXT,journal_id BIGINT,source_status TEXT,PRIMARY KEY(project_key,source_kind,source_id))');
        $p->exec('CREATE TABLE modeled_conversion_profile_versions(id INTEGER PRIMARY KEY AUTOINCREMENT,project_key TEXT,platform TEXT,customer_key TEXT,conversation_id TEXT,journal_cursor BIGINT,observed_at_unix BIGINT,input_sha256 TEXT,profile_json TEXT,UNIQUE(project_key,platform,customer_key,conversation_id,journal_cursor))');
    }
}
function modeledProfileLatest(PDO $p,string $conversation='11'): array
{
    $s=$p->prepare('SELECT profile_json FROM modeled_conversion_profile_versions WHERE conversation_id=? ORDER BY id DESC LIMIT 1');$s->execute([$conversation]);return json_decode($s->fetchColumn(),true,128,JSON_THROW_ON_ERROR);
}
function modeledProfileAppend(PDO $p,array $snap,int $now): void
{
    $id=(string)$snap['source_id'];$kind=$snap['source_kind'];$json=json_encode($snap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$hash=hash('sha256',$json);
    $q=$p->prepare('SELECT version_no FROM modeled_conversion_stream_sources WHERE project_key=? AND source_kind=? AND source_id=?');$q->execute(['anytour',$kind,$id]);$old=$q->fetchColumn();$v=$old===false?1:(int)$old+1;
    $p->prepare('INSERT INTO modeled_conversion_stream_versions(project_key,source_kind,source_id,version_no,revision_hash,observed_at_unix,snapshot_json) VALUES (?,?,?,?,?,?,?)')->execute(['anytour',$kind,$id,$v,$hash,$now,$json]);
    if($old===false)$p->prepare('INSERT INTO modeled_conversion_stream_sources VALUES (?,?,?,?,?,?)')->execute(['anytour',$kind,$id,$hash,$v,$now]);
    else$p->prepare('UPDATE modeled_conversion_stream_sources SET latest_hash=?,version_no=?,observed_at_unix=? WHERE project_key=? AND source_kind=? AND source_id=?')->execute([$hash,$v,$now,'anytour',$kind,$id]);
}
function modeledProfileAssertions(PDO $p,callable $check): void
{
    modeledStreamSchema($p);modeledProfileSchema($p);$now=1793002000;
    $s=new ModeledConversionStream($p);$profiles=new ModeledConversionProfiles($p);
    $check('profile processing defaults off without creating state',$profiles->tick($now)['status']==='off');
    try{$profiles->start();$ok=false;}catch(Throwable $e){$ok=$e->getMessage()==='modeled_profiles_intake_not_enabled';}
    $check('profile activation cannot bypass the admitted intake scope',$ok);
    modeledStreamMessage($p,10,11);$s->start($now);
    modeledStreamMessage($p,11,11,'Бюджет до 200 тыс на всех, 2 взрослых');$s->tick($now+1);$profiles->start();
    $tables=['messages','conversation_events','conversations','customer_channels','advertising_visit_observations'];
    foreach($tables as$t)$p->exec('ALTER TABLE '.$t.' RENAME TO unavailable_'.$t);
    try{$r=$profiles->tick($now+2);}finally{foreach(array_reverse($tables)as$t)$p->exec('ALTER TABLE unavailable_'.$t.' RENAME TO '.$t);}
    $v=modeledProfileLatest($p);
    $check('actual interpreter profiles the admitted journal with all native source tables unavailable',$r['profiles_written']===1&&$v['profile']['fields']['budget']['value']['maximum']===200000);
    $check('same immutable profile stores early signals and an initial ordinal forecast',
        $v['early_forecast']['snapshot_kind']==='initial_observation'&&$v['early_forecast']['status']==='early_assessment'
        &&$v['early_forecast']['purchase_anywhere']['probability']===null&&$v['contextual_signals']['goal_counts']['substantive_interest']>0);
    $privateReceipt=$profiles->status()['last_receipt']['early_forecast']??[];
    $check('private state retains bounded forecast counters without exposing them to cron telemetry',
        ($privateReceipt['snapshots']??null)===1&&($privateReceipt['initial_observations']??null)===1
        &&!isset($r['early_forecast'])&&($r['early_forecast_version']??null)===ModeledConversionEarlyForecast::VERSION);
    $firstForecastCursor=$v['early_forecast']['first_snapshot_journal_cursor'];
    $initialPrediction=$v['semantic_prediction']??[];
    $initialFollowup=$v['prediction_followup']??[];
    $initialProfileId=(int)$p->query('SELECT id FROM modeled_conversion_profile_versions ORDER BY id LIMIT 1')->fetchColumn();
    $initialProfileSql='SELECT profile_json FROM modeled_conversion_profile_versions WHERE id='.$initialProfileId;
    $initialProfileJson=$p->query($initialProfileSql)->fetchColumn();
    $check('the committed profile stores a private heuristic prediction and followup together',
        ($initialPrediction['version']??null)==='semantic_purchase_heuristic_v1'
        &&($initialPrediction['status']??null)==='predicted'
        &&is_string($initialPrediction['prediction_at']??null)
        &&($initialPrediction['prediction_at']??null)===($initialPrediction['frozen_at']??null)
        &&$initialPrediction['prediction_at']>=$v['profile']['prediction_at']
        &&preg_match('/\\A[a-f0-9]{64}\\z/D',(string)($initialPrediction['prediction_digest']??''))===1
        &&in_array($initialFollowup['status']??null,['waiting','unknown'],true)
        &&array_key_exists('prior_prediction_digest',$initialFollowup)
        &&$initialFollowup['prior_prediction_digest']===null);
    $check('heuristic prediction preserves legacy null probabilities and assigned business value',
        $v['profile']['purchase_anywhere_probability']===null&&$v['profile']['purchase_with_us_probability']===null
        &&$v['profile']['expected_commission_rub']===null&&$v['profile']['expected_value_rub']===null
        &&$v['early_forecast']['purchase_with_us']['probability']===null
        &&$v['early_forecast']['expected_value_rub']===null
        &&$v['assigned_value']['known_subtotal_rub']===100
        &&$v['assigned_value']['stages']['dialogue']['status']==='awarded'
        &&$v['assigned_value']['stages']['specific_request']['status']==='awarded'
        &&$v['assigned_value']['stages']['offer_reply']['status']==='unknown'
        &&!$v['assigned_value']['export_allowed']&&$v['assigned_value']['conversion_uploads']===0);
    $check('runtime telemetry and status omit the saved private prediction and followup',
        !str_contains(json_encode([$r,$profiles->status()]),'"semantic_prediction"')
        &&!str_contains(json_encode([$r,$profiles->status()]),'"prediction_followup"')
        &&!str_contains(json_encode([$r,$profiles->status()]),$initialPrediction['prediction_digest']));
    $check('runtime profile keeps unknown trip target and all monetary values null',$v['profile']['trip_status']==='unknown'&&!$v['profile']['target_admission']['eligible']&&$v['profile']['expected_value_rub']===null);
    $check('profile status exposes aggregate receipt without private text or native identities',!str_contains(json_encode($profiles->status()),'Бюджет')&&!str_contains(json_encode($profiles->status()),'conversation_id'));
    $check('profile retry and empty tick append no duplicate version',$profiles->tick($now+3)['profiles_written']===0&&(int)$p->query('SELECT COUNT(*) FROM modeled_conversion_profile_versions')->fetchColumn()===1);
    $check('an idle tick leaves the original prediction and followup bytes unchanged',$p->query($initialProfileSql)->fetchColumn()===$initialProfileJson);
    // Reuse the later manager quote before editing the request, retaining its original anchor.
    modeledStreamMessage($p,12,11,'Стоимость 700000 рублей','manager');$s->tick($now+3);$managerUpdateReceipt=$profiles->tick($now+3);
    $sameRequest=modeledProfileLatest($p);$sameFollowup=$sameRequest['prediction_followup']??[];
    $check('a later profile compares against the earliest saved prediction for the same request',
        ($sameRequest['semantic_prediction']['current_request_anchor']??null)===($initialPrediction['current_request_anchor']??null)
        &&($sameFollowup['prior_prediction_digest']??null)===$initialPrediction['prediction_digest']
        &&($sameFollowup['prior_prediction_at']??null)===$initialPrediction['prediction_at']
        &&array_key_exists('prior_estimated_probability',$sameFollowup)
        &&$sameFollowup['prior_estimated_probability']===$initialPrediction['estimated_probability']
        &&in_array($sameFollowup['status']??null,['waiting','unknown'],true)
        &&$sameRequest['assigned_value']['known_subtotal_rub']===100
        &&$p->query($initialProfileSql)->fetchColumn()===$initialProfileJson);
    $p->exec("UPDATE messages SET text='Бюджет до 300 тыс на всех' WHERE id=11");modeledStreamMessage($p,13,11,'Давайте бронировать');
    $p->exec("UPDATE messages SET metadata_json='{\"type\":\"callback\"}' WHERE id=13");$s->tick($now+4);$profiles->tick($now+5);$v=modeledProfileLatest($p);
    $check('later profiles preserve first forecast identity and exclude callback raw intent',
        $v['early_forecast']['snapshot_kind']==='updated_observation'
        &&$v['early_forecast']['first_snapshot_journal_cursor']===$firstForecastCursor
        &&$v['contextual_signals']['goal_counts']['agency_purchase_intent']===0);
    $check('edited customer facts update while manager quote and callback do not raise purchase intent',$v['profile']['fields']['budget']['value']['maximum']===300000&&$v['profile']['current_stage']['value']!=='booking_intent'&&count($v['profile']['manager_quotes'])===1);
    $check('later source edits preserve the original frozen prediction digest and profile',
        $p->query($initialProfileSql)->fetchColumn()===$initialProfileJson
        &&($v['semantic_prediction']['version']??null)==='semantic_purchase_heuristic_v1'
        &&is_array($v['prediction_followup']??null)
        &&$v['assigned_value']['known_subtotal_rub']===100);
    $p->exec("UPDATE messages SET text='Бюджет до 400 тыс на всех' WHERE id=11");$s->tick($now+6);
    $sourceCount=$p->query('SELECT COUNT(*) FROM modeled_conversion_stream_versions')->fetchColumn();$cursor=$p->query('SELECT journal_cursor FROM modeled_conversion_profiles_state')->fetchColumn();$count=$p->query('SELECT COUNT(*) FROM modeled_conversion_profile_versions')->fetchColumn();$index=$p->query("SELECT journal_id FROM modeled_conversion_profile_sources WHERE source_id=11 AND source_kind='message'")->fetchColumn();
    $beforeRollbackProfile=modeledProfileLatest($p);
    try{$profiles->tick($now+7,static function(){throw new RuntimeException('profile_rollback_fixture');});$ok=false;}catch(Throwable $e){$ok=$e->getMessage()==='profile_rollback_fixture';}
    $check('profile failure rolls back source references versions and checkpoint together',$ok&&$sourceCount===$p->query('SELECT COUNT(*) FROM modeled_conversion_stream_versions')->fetchColumn()&&$cursor===$p->query('SELECT journal_cursor FROM modeled_conversion_profiles_state')->fetchColumn()&&$count===$p->query('SELECT COUNT(*) FROM modeled_conversion_profile_versions')->fetchColumn()&&$index===$p->query("SELECT journal_id FROM modeled_conversion_profile_sources WHERE source_id=11 AND source_kind='message'")->fetchColumn());
    $afterRollbackProfile=modeledProfileLatest($p);
    $check('rollback preserves the prior prediction and followup as one committed profile',
        ($afterRollbackProfile['semantic_prediction']??null)===($beforeRollbackProfile['semantic_prediction']??null)
        &&($afterRollbackProfile['prediction_followup']??null)===($beforeRollbackProfile['prediction_followup']??null)
        &&$p->query($initialProfileSql)->fetchColumn()===$initialProfileJson);
    $retryReceipt=$profiles->tick($now+8);$retryProfile=modeledProfileLatest($p);
    $check('retry after profile rollback extracts and commits exactly once',$retryReceipt['profiles_written']===1&&$retryProfile['profile']['fields']['budget']['value']['maximum']===400000);
    $check('retry commits prediction followup and legacy values together without private telemetry',
        ($retryProfile['semantic_prediction']['version']??null)==='semantic_purchase_heuristic_v1'
        &&is_array($retryProfile['prediction_followup']??null)
        &&$retryProfile['profile']['purchase_anywhere_probability']===null
        &&$retryProfile['profile']['purchase_with_us_probability']===null
        &&$retryProfile['profile']['expected_value_rub']===null
        &&$retryProfile['early_forecast']['purchase_with_us']['probability']===null
        &&$retryProfile['early_forecast']['expected_value_rub']===null
        &&$retryProfile['assigned_value']['known_subtotal_rub']===100
        &&$p->query($initialProfileSql)->fetchColumn()===$initialProfileJson
        &&!str_contains(json_encode([$managerUpdateReceipt,$retryReceipt,$profiles->status()]),'"semantic_prediction"')
        &&!str_contains(json_encode([$managerUpdateReceipt,$retryReceipt,$profiles->status()]),'"prediction_followup"')
        &&!str_contains(json_encode([$managerUpdateReceipt,$retryReceipt,$profiles->status()]),$initialPrediction['prediction_digest']));
    $raw=$p->query("SELECT snapshot_json FROM modeled_conversion_stream_versions WHERE source_kind='message' AND source_id=11 ORDER BY id DESC LIMIT 1")->fetchColumn();$snap=json_decode($raw,true);
    $snap['payload']['text']='Бюджет до 450 тыс на всех';$legacy=$snap;unset($legacy['source_created_at_unix']);modeledProfileAppend($p,$legacy,$now+10);
    foreach([10,14,15,16,17,18,19,20]as$c){$other=$snap;$other['source_id']=(string)(100+$c);$other['conversation_id']=(string)$c;$other['customer_key']=(string)$c;modeledProfileAppend($p,$other,$now+10);}
    $snap['payload']['text']='Бюджет до 900 тыс на всех';modeledProfileAppend($p,$snap,$now+10);
    $future=$snap;$future['payload']['text']='Бюджет до 1000 тыс на всех';
    $r=$profiles->tick($now+11,static function()use($p,$future,$now){modeledProfileAppend($p,$future,$now+11);});
    $first=modeledProfileLatest($p);$frozen=$p->query("SELECT profile_json FROM modeled_conversion_profile_versions WHERE conversation_id='11' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $frozenId=$p->query("SELECT id FROM modeled_conversion_profile_versions WHERE conversation_id='11' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $check('legacy clock is resolved by a revision already available within the information fence',$r['profiles_written']===8&&$r['pending_revisions']===2&&$first['profile']['fields']['budget']['value']['maximum']===900000&&(int)$first['processing']['source_fence_journal_id']>(int)$first['processing']['journal_cursor']);
    $r=$profiles->tick($now+12);$check('a revision appended after the information fence enters only the next prediction',$r['profiles_written']===2&&$r['pending_revisions']===0&&modeledProfileLatest($p)['profile']['fields']['budget']['value']['maximum']===1000000);
    $check('later source revisions cannot rewrite a previously frozen profile',$p->query('SELECT profile_json FROM modeled_conversion_profile_versions WHERE id='.(int)$frozenId)->fetchColumn()===$frozen);
    $p->exec('UPDATE conversations SET is_test=1 WHERE id=11');$s->tick($now+13);$profiles->tick($now+14);$v=modeledProfileLatest($p);
    $check('revoked scope also removes live contextual candidates',
        $v['early_forecast']['status']==='access_denied'&&$v['contextual_signals']['events']===[]);
    $check('revoked source scope invalidates the current profile without copying older text',$v['access_status']==='denied'&&$v['profile']['prediction_features']['facts']===[]);
    $p->exec('UPDATE conversations SET is_test=0 WHERE id=11');$s->tick($now+15);$profiles->tick($now+16);
    $check('scope restoration creates a new current profile with its actual new receipt',modeledProfileLatest($p)['access_status']==='allowed');
    $beforeLarge=(int)$p->query('SELECT journal_cursor FROM modeled_conversion_profiles_state')->fetchColumn();
    // Exercise the admitted-journal bridge independently of native intake's stricter byte estimate.
    $largeSnapshot=$snap;$largeSnapshot['source_id']='99';$largeSnapshot['payload']['text']=str_repeat('😀',9000);
    modeledProfileAppend($p,$largeSnapshot,$now+17);$profiles->tick($now+18);$large=modeledProfileLatest($p);
    $check('large admitted Unicode message does not poison factual profile persistence or progress',
        (int)$p->query('SELECT journal_cursor FROM modeled_conversion_profiles_state')->fetchColumn()>$beforeLarge
        &&($large['contextual_signals']['coverage']['semantic_omissions']['text_too_large']??0)===1
        &&isset($large['profile']['fields']));
    // Isolate throughput from source-reconciliation tests: all following rows are already admitted fixtures.
    for($i=0;$i<100;$i++){$c=(string)(1000+$i);$x=$snap;$x['source_id']=(string)(2000+$i);$x['conversation_id']=$c;$x['customer_key']=$c;modeledProfileAppend($p,$x,$now+20);}
    $bounded=true;$written=0;
    for($i=0;$i<25;$i++){$r=$profiles->tick($now+21+$i);$written+=$r['profiles_written'];$bounded=$bounded&&$r['journal_rows_read']<=500&&$r['payload_bytes']<=1048576&&$r['profiles_written']<=8;}
    $check('mass journal processing keeps row byte and affected-scope limits',$bounded);
    $check('automatic bounded processing eventually profiles all100synthetic conversation scopes',$written===100&&$r['pending_revisions']===0);
    $profiles->stop();$check('stopping profile processing preserves source intake and private history',$profiles->tick($now+50)['status']==='off'&&(int)$p->query('SELECT enabled FROM modeled_conversion_stream_state')->fetchColumn()===1);
    $profiles->start();$check('resuming profiles preserves its cursor rather than replaying journal history',$profiles->tick($now+51)['profiles_written']===0);
    $p->beginTransaction();try{$profiles->tick($now+52);$ok=false;}catch(Throwable $e){$ok=$e->getMessage()==='modeled_profiles_outer_transaction';}finally{$p->rollBack();}
    $check('profile processor cannot commit a caller-owned transaction',$ok);
}



/** Dense synthetic admitted context verifies useful throughput, not only the raised counter. */
function modeledDenseProfileAssertions(PDO $p,callable $check):void
{
    modeledStreamSchema($p);modeledProfileSchema($p);$now=1793002000;
    $source=new ModeledConversionStream($p);$profiles=new ModeledConversionProfiles($p);
    modeledStreamMessage($p,10,11);$source->start($now);
    modeledStreamMessage($p,11,11,'Подберите тур в Турцию на 7 ночей, 2 взрослых. Бюджет до 200 тыс на всех');
    $source->tick($now+1);$profiles->start();
    $snap=json_decode($p->query("SELECT snapshot_json FROM modeled_conversion_stream_versions WHERE source_kind='message' AND source_id=11 ORDER BY id DESC LIMIT 1")->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
    $contexts=[];$originals=[];
    for($i=0;$i<8;$i++){
        $scope=(string)(5000+$i);
        for($j=0;$j<50;$j++){
            $x=$snap;$x['source_id']=(string)(10000+$i*50+$j);$x['conversation_id']=$scope;$x['customer_key']=$scope;
            modeledProfileAppend($p,$x,$now+2);$journal=(string)$p->query("SELECT MAX(id) FROM modeled_conversion_stream_versions WHERE project_key='anytour'")->fetchColumn();
            $p->prepare('INSERT INTO modeled_conversion_profile_sources(project_key,source_kind,source_id,platform,customer_key,conversation_id,journal_id,source_status) VALUES(?,?,?,?,?,?,?,?)')
                ->execute(['anytour','message',$x['source_id'],$x['platform'],$scope,$scope,$journal,'present']);
            if($j===0)$contexts[]=$x;
        }
    }
    $base=(int)$p->query('SELECT MAX(id) FROM modeled_conversion_stream_versions')->fetchColumn();
    $p->prepare("UPDATE modeled_conversion_profiles_state SET journal_cursor=? WHERE project_key='anytour'")->execute([$base]);
    foreach($contexts as$x){$x['payload']['text']='Бюджет до 300 тыс на всех';modeledProfileAppend($p,$x,$now+3);}
    $ninth=$snap;$ninth['source_id']='20000';$ninth['customer_key']=$ninth['conversation_id']='6000';
    modeledProfileAppend($p,$ninth,$now+3);
    $originals=$p->query('SELECT id,revision_hash,snapshot_json FROM modeled_conversion_stream_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $r=$profiles->tick($now+4);$receipt=$profiles->status()['last_receipt'];
    $check('eight dense scopes with fifty references each commit in one bounded tick',
        $r['profiles_written']===8&&$r['pending_revisions']===1&&$r['processed_revisions']===8
        &&$r['journal_rows_read']<=500&&$r['payload_bytes']<=1048576
        &&$receipt['early_forecast']['snapshots']===8);
    $full=true;
    foreach($contexts as$x){
        $v=modeledProfileLatest($p,$x['conversation_id']);
        $full=$full&&count($v['source_references']??[])===50&&($v['coverage']['window_limited']??true)===false
            &&isset($v['contextual_signals'],$v['early_forecast'],$v['semantic_prediction']);
    }
    $check('all eight dense profiles retain the complete admitted context and frozen packets',$full);
    if(function_exists('forecastV1Read')){
        $read=forecastV1Read($p);
        $check('private forecast view accepts an actual eight-profile producer receipt',
            ($read['state']['last_batch_profiles_written']??null)===8);
    }
    $first=$p->query('SELECT id,profile_json FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $next=$profiles->tick($now+5);
    $check('ninth scope waits at the original chronological boundary then commits once',
        $next['profiles_written']===1&&$next['pending_revisions']===0&&$profiles->tick($now+6)['profiles_written']===0);
    $unchanged=$p->query('SELECT id,profile_json FROM modeled_conversion_profile_versions ORDER BY id LIMIT 8')->fetchAll(PDO::FETCH_ASSOC);
    $check('bounded throughput leaves original journal and prior profile bytes immutable',
        $originals===$p->query('SELECT id,revision_hash,snapshot_json FROM modeled_conversion_stream_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
        &&$first===$unchanged);
    // An old admitted reference larger than the remaining raw-copy budget must be omitted in SQL.
    $huge=$ninth;$huge['source_id']='20001';$huge['payload']['text']=str_repeat('x',1048577);
    modeledProfileAppend($p,$huge,$now+7);$hugeId=(string)$p->query("SELECT MAX(id) FROM modeled_conversion_stream_versions WHERE project_key='anytour'")->fetchColumn();
    $p->prepare('INSERT INTO modeled_conversion_profile_sources(project_key,source_kind,source_id,platform,customer_key,conversation_id,journal_id,source_status) VALUES(?,?,?,?,?,?,?,?)')
        ->execute(['anytour','message',$huge['source_id'],$huge['platform'],'6000','6000',$hugeId,'present']);
    $p->prepare("UPDATE modeled_conversion_profiles_state SET journal_cursor=? WHERE project_key='anytour'")->execute([$hugeId]);
    modeledProfileAppend($p,$ninth,$now+8);$guarded=$profiles->tick($now+9);$limited=modeledProfileLatest($p,'6000');
    $check('latest-reference SQL never copies an oversized raw snapshot on either database',
        $guarded['profiles_written']===1&&$guarded['payload_bytes']<1048576
        &&$limited['coverage']['window_limited']===true&&count($limited['source_references'])===1);
}

/** The actual admitted PHP/Python writer must retain a genuine request across canonical AI output. */
function modeledServiceProfileAssertions(PDO $p,callable $check):void
{
    modeledStreamSchema($p);modeledProfileSchema($p);$now=1793002000;
    $source=new ModeledConversionStream($p);$profiles=new ModeledConversionProfiles($p);
    modeledStreamMessage($p,10,11);$source->start($now);
    modeledStreamMessage($p,11,11,'Подберите тур в Турцию на 7 ночей, 2 взрослых.');
    modeledStreamMessage($p,12,11,'Уточните даты поездки','ai');
    $p->prepare('UPDATE messages SET metadata_json=? WHERE id=12')->execute([
        json_encode(ConversationRecordClock::stamp([],$now),JSON_THROW_ON_ERROR)]);
    $source->tick($now+1);$profiles->start();
    $tables=['messages','conversation_events','conversations','customer_channels','advertising_visit_observations'];
    foreach($tables as$table)$p->exec('ALTER TABLE '.$table.' RENAME TO unavailable_'.$table);
    try{$receipt=$profiles->tick($now+2);}finally{foreach(array_reverse($tables)as$table)$p->exec('ALTER TABLE unavailable_'.$table.' RENAME TO '.$table);}
    $v=modeledProfileLatest($p);$prediction=$v['semantic_prediction'];
    $firstId=(int)$p->query('SELECT id FROM modeled_conversion_profile_versions ORDER BY id LIMIT 1')->fetchColumn();
    $firstJson=$p->query('SELECT profile_json FROM modeled_conversion_profile_versions WHERE id='.$firstId)->fetchColumn();
    $check('actual writer saves a numeric customer prediction after a canonical AI response',
        $receipt['profiles_written']===1&&$prediction['status']==='predicted'
        &&$prediction['stage']==='concrete_request'&&$prediction['estimated_probability']===0.083333
        &&$prediction['current_customer_turn_count']===1
        &&$v['contextual_signals']['goal_counts']['agency_purchase_intent']===0
        &&$v['early_forecast']['purchase_with_us']['probability']===null);
    modeledStreamMessage($p,13,11,'Варианты будут в подборке','ai');
    $p->prepare('UPDATE messages SET metadata_json=? WHERE id=13')->execute([
        json_encode(ConversationRecordClock::stamp([],$now+3),JSON_THROW_ON_ERROR)]);
    $source->tick($now+4);$profiles->tick($now+5);$later=modeledProfileLatest($p);
    $check('later AI output preserves the immutable early percentage and same-request comparison',
        $later['semantic_prediction']['current_request_anchor']===$prediction['current_request_anchor']
        &&$later['semantic_prediction']['estimated_probability']===$prediction['estimated_probability']
        &&$later['prediction_followup']['prior_prediction_digest']===$prediction['prediction_digest']
        &&$later['prediction_followup']['prior_estimated_probability']===$prediction['estimated_probability']
        &&$p->query('SELECT profile_json FROM modeled_conversion_profile_versions WHERE id='.$firstId)->fetchColumn()===$firstJson);
}

/** Actual request/reply/receipt writes and the real private reader share the same immutable packet. */
function modeledHandoffProfileAssertions(PDO $p,callable $check):void
{
    modeledStreamSchema($p);modeledProfileSchema($p);$now=1793002000;
    $source=new ModeledConversionStream($p);$profiles=new ModeledConversionProfiles($p);
    modeledStreamMessage($p,10,11);$source->start($now);
    modeledStreamMessage($p,11,11,'Подберите тур в Турцию на 7 ночей, 2 взрослых.');
    modeledStreamMessage($p,12,11,'Уточните даты поездки','manager');
    $event=static function(int $id,string $type,array $payload,int $at)use($p):void{
        $p->prepare('INSERT INTO conversation_events VALUES (?,?,?,?,?,?)')->execute([$id,11,$type,'manager',
            json_encode(ConversationRecordClock::stamp($payload,$at),JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s',$at)]);
    };
    $event(1,'manager_message',['channel'=>'max','project_key'=>'anytour'],$now);
    $source->tick($now+1);$profiles->start();
    $tables=['messages','conversation_events','conversations','customer_channels','advertising_visit_observations'];
    foreach($tables as$table)$p->exec('ALTER TABLE '.$table.' RENAME TO unavailable_'.$table);
    try{
        $receipt=$profiles->tick($now+2);
        $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        if($mysql)$p->exec('SET SESSION TRANSACTION READ ONLY');
        try{$read=(new ModeledConversionForecastStatusReader($p,static function():void{},new ModeledConversionStatusBudget()))->read();}
        finally{if($mysql)$p->exec('SET SESSION TRANSACTION READ WRITE');}
    }finally{foreach(array_reverse($tables)as$table)$p->exec('ALTER TABLE unavailable_'.$table.' RENAME TO '.$table);}
    $v=modeledProfileLatest($p);$prediction=$v['semantic_prediction'];
    $firstId=(int)$p->query('SELECT id FROM modeled_conversion_profile_versions ORDER BY id LIMIT 1')->fetchColumn();
    $firstJson=$p->query('SELECT profile_json FROM modeled_conversion_profile_versions WHERE id='.$firstId)->fetchColumn();
    $check('ordinary request manager reply and canonical receipt save a numeric immutable prediction',
        $receipt['profiles_written']===1&&$prediction['stage']==='concrete_request'
        &&$prediction['estimated_probability']===0.083333&&$prediction['current_customer_turn_count']===1
        &&$v['contextual_signals']['engagement']['manager_turns']===1
        &&$v['contextual_signals']['coverage']['prediction_source_continuity']['preserved_handoff_receipts']===1);
    $check('actual private reader confirms the ordinary writer packet with no native source tables',
        $read['saved']['prospective_estimates']===1&&$read['window']['invalid_blocks']===0
        &&$read['saved']['estimated_probability_range']['minimum']===0.083333
        &&$read['input_coverage']['profiles_with_request_anchor']===1
        &&$read['input_coverage']['source_continuity']['preserved_handoff_receipts']['recorded_total']===1);
    $event(2,'manager_taken',[],$now+3);$source->tick($now+4);$profiles->tick($now+5);
    $later=modeledProfileLatest($p);
    $check('assignment receipt keeps the early anchor percentage and prior digest without rewriting it',
        $later['semantic_prediction']['current_request_anchor']===$prediction['current_request_anchor']
        &&$later['semantic_prediction']['estimated_probability']===$prediction['estimated_probability']
        &&$later['prediction_followup']['prior_prediction_digest']===$prediction['prediction_digest']
        &&$p->query('SELECT profile_json FROM modeled_conversion_profile_versions WHERE id='.$firstId)->fetchColumn()===$firstJson);
    modeledStreamMessage($p,13,11,'Оплату вижу','manager');
    $event(3,'manager_message',['channel'=>'max','project_key'=>'anytour'],$now+6);
    $source->tick($now+7);$profiles->tick($now+8);$paid=modeledProfileLatest($p);
    $check('service receipt cannot erase actual payment wording or make old wording a future outcome',
        $paid['semantic_prediction']['status']==='known_outcome'&&$paid['semantic_prediction']['estimated_probability']===null
        &&$paid['contextual_signals']['goal_counts']['manager_payment_ack']>0
        &&$paid['prediction_followup']['status']!=='observed'
        &&$p->query('SELECT profile_json FROM modeled_conversion_profile_versions WHERE id='.$firstId)->fetchColumn()===$firstJson);
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    modeledStreamSchema($p);$passed=0;
    $check=static function(string $name,bool $ok)use(&$passed){if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;};
    modeledStreamAssertions($p,$check);modeledStreamSchedulerAssertions($check);modeledProfileAssertions($p,$check);modeledDenseProfileAssertions($p,$check);modeledServiceProfileAssertions($p,$check);modeledHandoffProfileAssertions($p,$check);
    echo 'MODELED CONVERSION STREAM PASSED '.$passed.PHP_EOL;
}

