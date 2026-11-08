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


function modeledPriceContextAssertions(PDO $p,callable $check):void
{
    $p->exec('CREATE TABLE projects(id BIGINT PRIMARY KEY,project_key VARCHAR(40))');
    $p->exec('CREATE TABLE post_channels(id BIGINT PRIMARY KEY,project_id BIGINT,platform VARCHAR(16))');
    $p->exec('CREATE TABLE post_publications(id BIGINT PRIMARY KEY,post_channel_id BIGINT)');
    $p->exec('CREATE TABLE post_versions(id BIGINT PRIMARY KEY,publication_id BIGINT,content_hash VARCHAR(64),snapshot_json TEXT)');
    $p->exec('CREATE TABLE post_entries(id BIGINT PRIMARY KEY,conversation_id BIGINT,publication_id BIGINT,version_id BIGINT)');
    try{
        $p->exec("INSERT INTO projects VALUES(1,'anytour');");$p->exec("INSERT INTO post_channels VALUES(1,1,'max')");
        $p->exec('INSERT INTO post_publications VALUES(1,1)');$p->exec('INSERT INTO post_entries VALUES(1,11,1,1)');
        $now=1793002500;$snapshot=json_encode(['text'=>'Тур от 150–200 тыс руб за 2 взрослых','published_at'=>gmdate('c',$now-60)],JSON_THROW_ON_ERROR);
        $p->prepare('INSERT INTO post_versions VALUES(1,1,?,?)')->execute([str_repeat('a',64),$snapshot]);
        $meta=['conversation_id'=>'11','platform'=>'max'];$payload=['event_type'=>'channel_post_entry','actor_type'=>'customer',
            'payload_json'=>json_encode(['entry_id'=>1,'publication_id'=>1,'version_id'=>1],JSON_THROW_ON_ERROR)];
        $r=ModeledConversionPriceContext::capture($p,$meta,$payload,$now);
        $check('pinned native publication price is read without changing the original snapshot',
            ($r['context']['range']['point']??null)===175000&&$r['rows']===1&&$r['bytes']>0
            &&$p->query('SELECT snapshot_json FROM post_versions WHERE id=1')->fetchColumn()===$snapshot);
        $bad=$meta;$bad['platform']='telegram';
        $check('a foreign platform cannot borrow a publication price',ModeledConversionPriceContext::capture($p,$bad,$payload,$now)['context']===null);
        $bad=$payload;$bad['payload_json']=json_encode(['entry_id'=>1,'publication_id'=>1,'version_id'=>2]);
        $check('a changed version pointer cannot select another publication price',ModeledConversionPriceContext::capture($p,$meta,$bad,$now)['context']===null);
        $p->exec("UPDATE projects SET project_key='anex'");
        $check('another project never supplies an AnyTour price',ModeledConversionPriceContext::capture($p,$meta,$payload,$now)['context']===null);
    }finally{
        foreach(['post_entries','post_versions','post_publications','post_channels','projects']as$table)$p->exec('DROP TABLE '.$table);
    }
}

function modeledStreamAssertions(PDO $p,callable $check): void
{
    modeledPriceContextAssertions($p,$check);
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
    $check('actual live interpreter stores expert dialogue policy and seven percent monetary forecast together',
        ($initialPrediction['mode']??null)==='contextual_advertising_v1'
        &&($v['deal_value_prediction']['commission_rate']??null)===0.07
        &&ModeledConversionDealValue::saved($v)!==null
        &&($v['deal_value_prediction']['deal_amount_rub']['point']??null)===200000);
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
/** Exercise the scheduler's bounded continuation with the real atomic PHP/Python writer. */
function modeledProfileCatchUpAssertions(PDO $p,callable $check):void
{
    modeledStreamSchema($p);modeledProfileSchema($p);$now=1793002000;
    $source=new ModeledConversionStream($p);$profiles=new ModeledConversionProfiles($p);
    $source->start($now);
    modeledStreamMessage($p,11,11,'Подберите тур в Турцию на 7 ночей, 2 взрослых. Бюджет до 200 тыс на всех');
    $source->tick($now+1);$profiles->start();
    $snap=json_decode($p->query("SELECT snapshot_json FROM modeled_conversion_stream_versions WHERE source_kind='message' AND source_id=11 ORDER BY id DESC LIMIT 1")->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
    $append=static function(int $count,int $base)use($p,$snap,$now):void{
        for($i=0;$i<$count;$i++){
            $x=$snap;$x['source_id']=(string)(100000+$base+$i);$x['customer_key']=$x['conversation_id']=(string)($base+$i);
            modeledProfileAppend($p,$x,$now+1);
        }
    };
    $versions=static fn():int=>(int)$p->query('SELECT COUNT(*) FROM modeled_conversion_profile_versions')->fetchColumn();
    $rows=static fn():array=>$p->query('SELECT id,profile_json FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $clockCalls=0;$clock=static function()use(&$clockCalls,$now):int{$clockCalls++;return $now+2;};
    $monotonic=static fn():int=>0;
    $append(39,7000);
    $journal=$p->query('SELECT id,revision_hash,snapshot_json FROM modeled_conversion_stream_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $receipts=[];$remember=static function(array $receipt)use(&$receipts):void{$receipts[]=$receipt;};
    $tables=['messages','conversation_events','conversations','customer_channels','advertising_visit_observations'];
    foreach($tables as$table)$p->exec('ALTER TABLE '.$table.' RENAME TO unavailable_'.$table);
    try{$r=ModeledConversionStream::profileCatchUp($profiles,$clock,$monotonic,$remember);}
    finally{foreach(array_reverse($tables)as$table)$p->exec('ALTER TABLE unavailable_'.$table.' RENAME TO '.$table);}
    $check('one scheduled profile phase commits thirty two of forty scopes without rereading native sources',
        $versions()===32&&$clockCalls===4&&$r['profiles_written']===8&&$r['pending_revisions']===8);
    $bounded=count($receipts)===4;
    foreach($receipts as$receipt)$bounded=$bounded&&$receipt['profiles_written']===8
        &&$receipt['processed_revisions']<=40&&$receipt['journal_rows_read']<=500&&$receipt['payload_bytes']<=1048576;
    $check('each continued profile batch retains the original scope row and byte budgets',$bounded);
    $first=$rows();$clockCalls=0;
    $r=ModeledConversionStream::profileCatchUp($profiles,$clock,$monotonic);
    $check('the next scheduled phase resumes at the saved cursor and stops as soon as the tail is empty',
        $versions()===40&&$clockCalls===1&&$r['profiles_written']===8&&$r['pending_revisions']===0
        &&$first===array_slice($rows(),0,32));
    $complete=$rows();$clockCalls=0;
    $r=ModeledConversionStream::profileCatchUp($profiles,$clock,$monotonic);
    $check('an empty scheduled continuation performs one idle check and never rewrites frozen versions',
        $r['status']==='idle'&&$clockCalls===1&&$rows()===$complete
        &&$journal===$p->query('SELECT id,revision_hash,snapshot_json FROM modeled_conversion_stream_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
        &&$source->status()['ticks']===1);
    $profiles->stop();$clockCalls=0;$ticks=$profiles->status()['ticks'];
    $r=ModeledConversionStream::profileCatchUp($profiles,$clock,$monotonic);
    $check('disabled profile processing is not retried or reenabled by catch up',
        $r['status']==='off'&&$clockCalls===1&&$profiles->status()['ticks']===$ticks&&$rows()===$complete);
    $profiles->start();

    $append(24,8000);$attempts=0;$clockCalls=0;
    try{
        ModeledConversionStream::profileCatchUp($profiles,$clock,$monotonic,static function()use(&$attempts):void{
            if(++$attempts===2)throw new RuntimeException('catch_up_second_batch_rollback');
        });
        $failed=false;
    }catch(Throwable $e){$failed=$e->getMessage()==='catch_up_second_batch_rollback';}
    $check('failure in a later profile batch preserves the first commit and does not retry or start a third batch',
        $failed&&$attempts===2&&$clockCalls===2&&$versions()===48
        &&(int)$p->query('SELECT COUNT(*) FROM modeled_conversion_profile_sources')->fetchColumn()===48
        &&$profiles->status()['last_receipt']['pending_revisions']===16
        &&$complete===array_slice($rows(),0,40));
    $committed=$rows();$clockCalls=0;
    $r=ModeledConversionStream::profileCatchUp($profiles,$clock,$monotonic);
    $check('a subsequent scheduled phase resumes the rolled back batch once without replaying the earlier commit',
        $versions()===64&&$clockCalls===2&&$r['pending_revisions']===0&&$committed===array_slice($rows(),0,48));

    $append(24,9000);$clockCalls=0;$monotonicCalls=0;
    $deadlineClock=static function()use(&$monotonicCalls):int{
        return [0,4000000000,5000000000][min($monotonicCalls++,2)];
    };
    $r=ModeledConversionStream::profileCatchUp($profiles,$clock,$deadlineClock);
    $check('the five second soft start deadline is shared by the whole phase and stops before another transaction',
        $versions()===80&&$clockCalls===2&&$monotonicCalls===3&&$r['pending_revisions']===8);
    $clockCalls=0;ModeledConversionStream::profileCatchUp($profiles,$clock,$monotonic);
    $check('a deadline leaves the remaining journal work for the next ordinary phase',$versions()===88&&$clockCalls===1);

    $append(16,10000);$clockCalls=0;$commits=0;
    $r=ModeledConversionStream::profileCatchUp($profiles,$clock,$monotonic,static function()use($p,&$commits):void{
        $commits++;$p->exec("UPDATE modeled_conversion_profiles_state SET enabled=0 WHERE project_key='anytour'");
    });
    $check('a stop committed between batches is honored without repeating the first batch',
        $r['status']==='off'&&$versions()===96&&$clockCalls===2&&$commits===1&&!$profiles->status()['enabled']);
    $profiles->start();$clockCalls=0;
    $r=ModeledConversionStream::profileCatchUp($profiles,$clock,$monotonic);
    $check('resuming after an intervening stop retains chronological progress',$versions()===104&&$clockCalls===1&&$r['pending_revisions']===0);

    $large=$snap;$large['source_id']='200001';$large['customer_key']=$large['conversation_id']='11000';
    $large['payload']['text']=str_repeat('x',1048577);modeledProfileAppend($p,$large,$now+1);
    $cursor=$p->query("SELECT journal_cursor FROM modeled_conversion_profiles_state WHERE project_key='anytour'")->fetchColumn();
    $clockCalls=0;$r=ModeledConversionStream::profileCatchUp($profiles,$clock,$monotonic);
    $check('pending work with zero progress cannot cause an immediate catch up retry loop',
        $r['processed_revisions']===0&&$r['pending_revisions']===1&&$clockCalls===1&&$versions()===104
        &&$cursor===$p->query("SELECT journal_cursor FROM modeled_conversion_profiles_state WHERE project_key='anytour'")->fetchColumn());

    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
        if(!preg_match('/\Amysql:host=127\.0\.0\.1;port=[0-9]+;dbname=maxbot_prepared_test;charset=utf8mb4\z/D',$dsn)
            ||$p->query('SELECT DATABASE()')->fetchColumn()!=='maxbot_modeled_stream_test')throw new RuntimeException('unsafe_catch_up_fixture_database');
        $other=new PDO(str_replace('dbname=maxbot_prepared_test;','dbname=maxbot_modeled_stream_test;',$dsn),
            getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        $competing=new ModeledConversionProfiles($other);$busyCalls=0;
        $profiles->tick($now+2,static function()use($competing,$now,$monotonic,$check,&$busyCalls):void{
            $r=ModeledConversionStream::profileCatchUp($competing,static function()use(&$busyCalls,$now):int{$busyCalls++;return $now+2;},$monotonic);
            $check('a competing real MySQL writer returns busy once without waiting or retrying',$r['status']==='busy'&&$busyCalls===1);
        });
    }
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
    // This existing entry point is executed by both the SQLite suite and native MySQL runner.
    modeledProfileCatchUpAssertions($p,$check);
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
    modeledObservationalProfileAssertions($p,$check);
    modeledWizardProfileAssertions($p,$check);
}

/** Real emitOnce envelopes pass through the existing source journal and immutable profile writer. */
function modeledObservationalProfileAssertions(PDO $p,callable $check):void
{
    require_once dirname(__DIR__).'/services/MetrikaConversionGoalService.php';
    modeledStreamSchema($p);modeledProfileSchema($p);$now=1793002000;
    $p->exec('ALTER TABLE conversations ADD external_chat_id VARCHAR(40)');
    $p->exec("UPDATE conversations SET external_chat_id='9011' WHERE id=11");
    $p->exec('DROP TABLE conversation_events');
    $id=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BIGINT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $p->exec('CREATE TABLE conversation_events (id '.$id.',conversation_id BIGINT,event_type VARCHAR(64),actor_type VARCHAR(16),payload_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $source=new ModeledConversionStream($p);$profiles=new ModeledConversionProfiles($p);
    modeledStreamMessage($p,10,11);$source->start($now);
    modeledStreamMessage($p,11,11,'Подберите тур в Турцию на 7 ночей, 2 взрослых.');
    $source->tick($now+1);$profiles->start();$profiles->tick($now+2);
    $baseline=modeledProfileLatest($p);$prediction=$baseline['semantic_prediction'];$previous=$prediction;
    $firstId=(int)$p->query('SELECT id FROM modeled_conversion_profile_versions ORDER BY id LIMIT 1')->fetchColumn();
    $firstJson=$p->query('SELECT profile_json FROM modeled_conversion_profile_versions WHERE id='.$firstId)->fetchColumn();
    $check('producer fixture starts with a numeric saved customer request',
        $prediction['status']==='predicted'&&$prediction['estimated_probability']===0.083333);
    // Exercise the producer's actual persistence owner, with its queue dependency confined to this fixture.
    $connection=new ReflectionProperty(ConversationDb::class,'pdo');$previousConnection=$connection->getValue();
    $emit=new ReflectionMethod(MetrikaConversionGoalService::class,'emitOnce');$queued=[];
    $queue=static function(string $chat,string $target)use(&$queued):bool{$queued[]=[$chat,$target];return true;};
    $connection->setValue(null,$p);
    try{
        $targets=['max_bot_activity_start','max_manager_request','max_manager_reply','max_customer_reply_after_manager','max_sale'];
        foreach($targets as$i=>$target){
            $at=$now+3+$i*3;
            $check('actual producer accepts only the injected queue in this fixture '.$target,$emit->invoke(null,11,$target,$queue)===true);
            // Fixture-only clock control; the producer's plain JSON bytes are unchanged.
            $p->prepare('UPDATE conversation_events SET created_at=? WHERE event_type=?')->execute([gmdate('Y-m-d H:i:s',$at),'metrika_'.$target]);
            $source->tick($at+1);
            $snapshot=json_decode($p->query("SELECT snapshot_json FROM modeled_conversion_stream_versions WHERE source_kind='event' ORDER BY id DESC LIMIT 1")->fetchColumn(),true,128,JSON_THROW_ON_ERROR);
            $check('actual stream retains the producer envelope and normalized recording basis '.$target,
                $snapshot['payload']===['event_type'=>'metrika_'.$target,'actor_type'=>'system','payload_json'=>json_encode(['target'=>$target])]
                &&$snapshot['source_timestamp_basis']==='database_session_timezone_to_unix'
                &&$snapshot['source_clock']==='database_persistence_not_provider_occurrence'
                &&$snapshot['source_created_at_unix']===$at);
            $tables=['messages','conversation_events','conversations','customer_channels','advertising_visit_observations'];
            foreach($tables as$table)$p->exec('ALTER TABLE '.$table.' RENAME TO unavailable_'.$table);
            try{
                $receipt=$profiles->tick($at+2);
                $read=(new ModeledConversionForecastStatusReader($p,static function():void{},new ModeledConversionStatusBudget()))->read();
            }finally{foreach(array_reverse($tables)as$table)$p->exec('ALTER TABLE unavailable_'.$table.' RENAME TO '.$table);}
            $v=modeledProfileLatest($p);$current=$v['semantic_prediction'];
            if($target==='max_sale'){
                $check('actual sale marker remains a barrier and never supplies verified payment',
                    $current['status']==='insufficient_context'&&$current['estimated_probability']===null
                    &&$current['outcome_observations']===[]);
            }else{
                $check('actual producer-to-writer path preserves only the saved request '.$target,
                    $receipt['profiles_written']===1&&$current['status']==='predicted'
                    &&$current['current_request_anchor']===$prediction['current_request_anchor']
                    &&$current['estimated_probability']===$prediction['estimated_probability']
                    &&$current['current_customer_turn_count']===1&&$current['features']['meaningful_customer_turns']===1
                    &&$current['outcome_observations']===[]
                    &&$v['contextual_signals']['goal_counts']['agency_purchase_intent']===0
                    &&$v['contextual_signals']['coverage']['prediction_source_continuity']['preserved_handoff_receipts']===0);
                // Followup is anchored to the earliest saved prediction for this request, not a rolling baseline.
                $check('followup keeps the first saved prediction despite intervening receipts '.$target,
                    $v['prediction_followup']['prior_prediction_digest']===$prediction['prediction_digest']
                    &&$v['prediction_followup']['prior_prediction_at']===$prediction['prediction_at']
                    &&$v['prediction_followup']['prior_estimated_probability']===$prediction['estimated_probability']
                    &&$v['prediction_followup']['horizon_end_at']===$prediction['horizon_end_at']
                    &&($i===0||$v['prediction_followup']['prior_prediction_digest']!==$previous['prediction_digest']));
                $check('private reader validates the numeric receipt without native tables '.$target,
                    $read['window']['invalid_blocks']===0&&$read['saved']['prospective_estimates']>0
                    &&$read['saved']['estimated_probability_range']['minimum']===0.083333);
            }
            $check('observational followup never rewrites the earlier saved profile '.$target,
                $p->query('SELECT profile_json FROM modeled_conversion_profile_versions WHERE id='.$firstId)->fetchColumn()===$firstJson);
            $previous=$current;
        }
        $check('fixture queue contains only the explicit local calls',
            $queued===array_map(static fn(string $target):array=>['9011',$target],$targets));
    }finally{$connection->setValue(null,$previousConnection);}
}

/** Both SQLite and the existing native MySQL helper execute the actual wizard producer chain. */
function modeledWizardProfileAssertions(PDO $p,callable $check):void
{
    if(!defined('MAX_SEARCH_RUNTIME_STORAGE'))define('MAX_SEARCH_RUNTIME_STORAGE','mysql');
    foreach(['HOST','NAME','USER','PASS']as$name)if(!defined('CONVERSATION_DB_'.$name))define('CONVERSATION_DB_'.$name,'synthetic');
    // Only the legacy status configuration is a fixture; persistence and receipt owners are real.
    if(!class_exists('MaxSearchApi',false)){
        class MaxSearchApi {public static $HL=1;public static $statusStart=64;public static $statusAdults=67;public static $statusChild=68;}
    }
    require_once dirname(__DIR__).'/services/ExistingWizardStepApplicationService.php';
    require_once dirname(__DIR__).'/services/ConversationRecorder.php';
    require_once dirname(__DIR__).'/services/SourceHandlingService.php';
    require_once dirname(__DIR__).'/services/ModeledConversionValueContract.php';
    $check('wizard continuity actual fixture uses the canonical PDO storage',RuntimeStorage::usesMysql());
    modeledStreamSchema($p);modeledProfileSchema($p);
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $id=$mysql?'BIGINT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $p->exec('ALTER TABLE customer_channels ADD external_chat_id VARCHAR(40)');
    $p->exec('ALTER TABLE customer_channels ADD username VARCHAR(80)');
    foreach(['source_id BIGINT','status VARCHAR(32) DEFAULT \'ai\'','external_chat_id VARCHAR(40)','last_message_at DATETIME']as$column)
        $p->exec('ALTER TABLE conversations ADD '.$column);
    $p->exec("UPDATE conversations SET source_id=1,external_chat_id='9011' WHERE id=11");
    $p->exec("UPDATE conversations SET source_id=2,external_chat_id='9010' WHERE id=10");
    $p->exec('DROP TABLE messages');$p->exec('DROP TABLE conversation_events');
    $p->exec('CREATE TABLE messages(id '.$id.',conversation_id BIGINT,direction VARCHAR(16),sender_type VARCHAR(16),sender_id VARCHAR(40),channel VARCHAR(16),external_message_id VARCHAR(80),text MEDIUMTEXT,metadata_json MEDIUMTEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $p->exec('CREATE TABLE conversation_events(id '.$id.',conversation_id BIGINT,event_type VARCHAR(64),actor_type VARCHAR(16),payload_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $p->exec('CREATE TABLE projects(id BIGINT PRIMARY KEY,project_key VARCHAR(40),is_active INT)');
    $p->exec("INSERT INTO projects VALUES(1,'anytour',1)");
    $p->exec('CREATE TABLE conversation_sources(id BIGINT PRIMARY KEY,project_id BIGINT,source_key VARCHAR(80),is_active INT)');
    $p->exec("INSERT INTO conversation_sources VALUES(1,1,'max:default',1),(2,1,'telegram:default',1)");
    $p->exec('CREATE TABLE runtime_dialogue_state(id '.$id.',project_key VARCHAR(40),chat_id VARCHAR(40),status_id INT,value_text TEXT,message_id VARCHAR(80))');
    $insert=$p->prepare('INSERT INTO runtime_dialogue_state(project_key,chat_id,status_id,value_text,message_id) VALUES(?,?,?,?,?)');
    foreach(['9011','9010']as$chat)foreach([MaxSearchApi::$statusStart,MaxSearchApi::$statusAdults,MaxSearchApi::$statusChild]as$status)
        $insert->execute(['anytour',$chat,$status,'','']);
    $restore=[];
    foreach([[ConversationDb::class,'pdo',$p],[ProjectConfig::class,'config',['id'=>'anytour']],
        [RoutingAccessService::class,'schemaReady',true],[ProjectAccessService::class,'schemaReady',true],
        [ConversationRecorder::class,'wizardInput',null],[ConversationRecorder::class,'wizardReceipts',[]],
        [ConversationRecorder::class,'wizardReceiptReason','input_unavailable']]as[$class,$property,$value]){
        $reflection=new ReflectionProperty($class,$property);$restore[]=[$reflection,$reflection->getValue()];$reflection->setValue(null,$value);
    }
    $fetch=$p->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);$p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    if(!$mysql)$p->sqliteCreateFunction('NOW',static fn():string=>gmdate('Y-m-d H:i:s'));
    try{
        $started=time()-2;$source=new ModeledConversionStream($p);$profiles=new ModeledConversionProfiles($p);$source->start($started);$profiles->start();
        $incoming=['platform'=>'max','type'=>'message','source_key'=>'max:default',
            'user'=>['external_user_id'=>'9011','chat_id'=>'9011']];
        $check('wizard continuity actual recorder accepts the request and short field answer',
            ConversationRecorder::inbound($incoming+['message_id'=>'fixture-request','text'=>'Подберите тур в Турцию на 7 ночей.'])
            &&ConversationRecorder::inbound($incoming+['message_id'=>'fixture-adults','text'=>'2']));
        $inputId=(string)$p->query("SELECT id FROM messages WHERE external_message_id='fixture-adults'")->fetchColumn();
        $source->tick(time());$profiles->tick(time());$baseline=modeledProfileLatest($p);
        $prediction=$baseline['semantic_prediction'];
        $firstId=(int)$p->query("SELECT id FROM modeled_conversion_profile_versions WHERE conversation_id='11' ORDER BY id LIMIT 1")->fetchColumn();
        $firstJson=$p->query('SELECT profile_json FROM modeled_conversion_profile_versions WHERE id='.$firstId)->fetchColumn();
        $check('wizard continuity actual baseline retains a meaningful request before application',
            $prediction['status']==='predicted'&&$prediction['stage']==='concrete_request'
            &&$prediction['current_customer_turn_count']===2&&$prediction['features']['meaningful_customer_turns']===1
            &&$baseline['wizard_applications']['receipts']===[]);
        $check('wizard continuity actual existing step writes one canonical receipt',
            ExistingWizardStepApplicationService::apply('9011',MaxSearchApi::$statusAdults,'2',true)
            &&ConversationRecorder::wizardReceiptReason()==='recorded'
            &&$p->query("SELECT value_text FROM runtime_dialogue_state WHERE chat_id='9011' AND status_id=".(int)MaxSearchApi::$statusAdults)->fetchColumn()==='2'
            &&(int)$p->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn()===1);
        $raw=$p->query('SELECT id,payload_json FROM conversation_events')->fetch();
        $payload=json_decode($raw['payload_json'],true,32,JSON_THROW_ON_ERROR);
        $check('wizard continuity actual receipt binds the exact persisted short input',
            $payload['input_message_id']===$inputId&&$payload['input_kind']==='message'
            &&$payload['input_text_sha256']===hash('sha256','2')&&$payload['field']==='adults'&&$payload['storage_value']==='2'
            &&ConversationRecordClock::read($raw['payload_json'])!==null);
        $source->tick(time());
        $check('wizard continuity actual followup uses the canonical AI recorder',
            ConversationRecorder::outboundForConversation(11,'max','Уточните даты поездки.'));
        $source->tick(time());
        // Invoke only the existing receipt producer; no handoff, delivery or manager action is run.
        $recordChoice=new ReflectionMethod(SourceHandlingService::class,'recordChoice');
        $recordChoice->invoke(null,'max','9011','manager','source_needs_complete');
        $completion=$p->query("SELECT payload_json FROM conversation_events WHERE event_type='source_handling_choice'")->fetchColumn();
        $completionPayload=is_string($completion)?json_decode($completion,true,32,JSON_THROW_ON_ERROR):[];
        $completionClock=is_string($completion)?ConversationRecordClock::read($completion):null;unset($completionPayload['_recorded_clock']);
        $check('wizard continuity actual source completion uses the canonical producer',
            $completionPayload===['choice'=>'manager','reason'=>'source_needs_complete']&&$completionClock!==null);
        $source->tick(time());$receipt=$profiles->tick(time());$later=modeledProfileLatest($p);$current=$later['semantic_prediction'];
        $lookup=$p->prepare('SELECT id FROM modeled_conversion_stream_versions WHERE source_kind=? AND source_id=? AND version_no=1');
        $lookup->execute(['message',$inputId]);$inputRef='journal:'.$lookup->fetchColumn();
        $lookup->execute(['event',(string)$raw['id']]);$receiptRef='journal:'.$lookup->fetchColumn();
        $lookup->closeCursor();
        $check('wizard continuity actual journal and Python expose only the admitted binding',
            $receipt['profiles_written']===1&&$later['wizard_applications']===['version'=>'admitted_wizard_applications_v1',
                'receipts'=>[['receipt_ref'=>$receiptRef,'input_ref'=>$inputRef]]]);
        $check('wizard continuity actual profile preserves anchor stage probability and criteria',
            $current['status']==='predicted'&&$current['current_request_anchor']===$prediction['current_request_anchor']
            &&$current['stage']===$prediction['stage']&&$current['estimated_probability']===$prediction['estimated_probability']
            &&$current['base_probability']===$prediction['base_probability']&&$current['features']===$prediction['features']
            &&$current['current_customer_turn_count']===2&&$current['outcome_observations']===[]
            &&$later['contextual_signals']['goal_counts']['agency_purchase_intent']===0
            &&$later['contextual_signals']['goal_counts']['payment_self_report']===0
            &&$later['contextual_signals']['coverage']['prediction_source_continuity']['preserved_handoff_receipts']===1);
        $beforeMoney=ModeledConversionDealValue::saved($baseline);$afterMoney=ModeledConversionDealValue::saved($later);
        $beforeCandidate=ModeledConversionValueContract::candidate($baseline,$baseline['subject'],$started,time());
        $afterCandidate=ModeledConversionValueContract::candidate($later,$later['subject'],$started,time());
        $bothCandidates=$beforeCandidate!==null&&$afterCandidate!==null;
        if($bothCandidates){unset($beforeCandidate['event_unix'],$afterCandidate['event_unix']);}
        $check('wizard continuity actual monetary candidate matches the receipt-free request',
            $beforeMoney!==null&&$afterMoney!==null&&$beforeMoney['status']==='estimated'&&$afterMoney['status']==='estimated'
            &&$afterMoney['version']===$beforeMoney['version']&&$afterMoney['commission_rate']===$beforeMoney['commission_rate']
            &&$afterMoney['full_value_base_rub']===$beforeMoney['full_value_base_rub']&&$afterMoney['value_rub']===$beforeMoney['value_rub']
            &&$afterMoney['value_rub']>0&&$bothCandidates&&$beforeCandidate===$afterCandidate);
        $check('wizard continuity actual missing step emits no successful receipt',
            ExistingWizardStepApplicationService::apply('9011',999,'2',true)===false
            &&(int)$p->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn()===2);
        $short=['platform'=>'telegram','type'=>'message','source_key'=>'telegram:default','message_id'=>'fixture-short-only',
            'user'=>['external_user_id'=>'9010','chat_id'=>'9010'],'text'=>'2'];
        $check('wizard continuity actual isolated short answer is saved by the same owners',
            ConversationRecorder::inbound($short)&&ExistingWizardStepApplicationService::apply('9010',MaxSearchApi::$statusAdults,'2',true));
        $source->tick(time());$profiles->tick(time());$shortProfile=modeledProfileLatest($p,'10');
        $check('wizard continuity actual short answer alone cannot create a request or monetary candidate',
            $shortProfile['semantic_prediction']['status']==='insufficient_context'
            &&$shortProfile['semantic_prediction']['current_request_anchor']===null
            &&ModeledConversionValueContract::candidate($shortProfile,$shortProfile['subject'],$started,time())===null);
        $callback=$incoming;$callback['type']='callback';$callback['callback_id']='fixture-children';$callback['callback_data']='child_0';
        $check('wizard continuity actual button keeps its saved field but has no admitted text',
            ConversationRecorder::inbound($callback)&&ExistingWizardStepApplicationService::apply('9011',MaxSearchApi::$statusChild,'0',true));
        $source->tick(time());$profiles->tick(time());$buttonProfile=modeledProfileLatest($p);
        $check('wizard continuity actual callback cannot bridge customer meaning',
            $buttonProfile['profile']['party']['children']['value']===0
            &&$buttonProfile['semantic_prediction']['status']==='insufficient_context'
            &&$buttonProfile['semantic_prediction']['current_request_anchor']===null);
        $check('wizard continuity actual profile bytes remain immutable',
            $p->query('SELECT profile_json FROM modeled_conversion_profile_versions WHERE id='.$firstId)->fetchColumn()===$firstJson);
    }finally{
        foreach(array_reverse($restore)as[$reflection,$value])$reflection->setValue(null,$value);
        $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,$fetch);
        foreach(['runtime_dialogue_state','conversation_sources','projects']as$table)$p->exec('DROP TABLE '.$table);
    }
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    modeledStreamSchema($p);$passed=0;
    $check=static function(string $name,bool $ok)use(&$passed){if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;};
    modeledStreamAssertions($p,$check);modeledStreamSchedulerAssertions($check);modeledProfileAssertions($p,$check);modeledDenseProfileAssertions($p,$check);modeledServiceProfileAssertions($p,$check);modeledHandoffProfileAssertions($p,$check);
    echo 'MODELED CONVERSION STREAM PASSED '.$passed.PHP_EOL;
}

