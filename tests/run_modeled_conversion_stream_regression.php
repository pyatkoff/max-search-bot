<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ModeledConversionStream.php';

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
    $check('runtime profile keeps unknown trip target and all monetary values null',$v['profile']['trip_status']==='unknown'&&!$v['profile']['target_admission']['eligible']&&$v['profile']['expected_value_rub']===null);
    $check('profile status exposes aggregate receipt without private text or native identities',!str_contains(json_encode($profiles->status()),'Бюджет')&&!str_contains(json_encode($profiles->status()),'conversation_id'));
    $check('profile retry and empty tick append no duplicate version',$profiles->tick($now+3)['profiles_written']===0&&(int)$p->query('SELECT COUNT(*) FROM modeled_conversion_profile_versions')->fetchColumn()===1);
    $p->exec("UPDATE messages SET text='Бюджет до 300 тыс на всех' WHERE id=11");modeledStreamMessage($p,12,11,'Стоимость 700000 рублей','manager');modeledStreamMessage($p,13,11,'Давайте бронировать');
    $p->exec("UPDATE messages SET metadata_json='{\"type\":\"callback\"}' WHERE id=13");$s->tick($now+4);$profiles->tick($now+5);$v=modeledProfileLatest($p);
    $check('edited customer facts update while manager quote and callback do not raise purchase intent',$v['profile']['fields']['budget']['value']['maximum']===300000&&$v['profile']['current_stage']['value']!=='booking_intent'&&count($v['profile']['manager_quotes'])===1);
    $p->exec("UPDATE messages SET text='Бюджет до 400 тыс на всех' WHERE id=11");$s->tick($now+6);
    $sourceCount=$p->query('SELECT COUNT(*) FROM modeled_conversion_stream_versions')->fetchColumn();$cursor=$p->query('SELECT journal_cursor FROM modeled_conversion_profiles_state')->fetchColumn();$count=$p->query('SELECT COUNT(*) FROM modeled_conversion_profile_versions')->fetchColumn();$index=$p->query("SELECT journal_id FROM modeled_conversion_profile_sources WHERE source_id=11 AND source_kind='message'")->fetchColumn();
    try{$profiles->tick($now+7,static function(){throw new RuntimeException('profile_rollback_fixture');});$ok=false;}catch(Throwable $e){$ok=$e->getMessage()==='profile_rollback_fixture';}
    $check('profile failure rolls back source references versions and checkpoint together',$ok&&$sourceCount===$p->query('SELECT COUNT(*) FROM modeled_conversion_stream_versions')->fetchColumn()&&$cursor===$p->query('SELECT journal_cursor FROM modeled_conversion_profiles_state')->fetchColumn()&&$count===$p->query('SELECT COUNT(*) FROM modeled_conversion_profile_versions')->fetchColumn()&&$index===$p->query("SELECT journal_id FROM modeled_conversion_profile_sources WHERE source_id=11 AND source_kind='message'")->fetchColumn());
    $check('retry after profile rollback extracts and commits exactly once',$profiles->tick($now+8)['profiles_written']===1&&modeledProfileLatest($p)['profile']['fields']['budget']['value']['maximum']===400000);
    $raw=$p->query("SELECT snapshot_json FROM modeled_conversion_stream_versions WHERE source_kind='message' AND source_id=11 ORDER BY id DESC LIMIT 1")->fetchColumn();$snap=json_decode($raw,true);
    $snap['payload']['text']='Бюджет до 450 тыс на всех';$legacy=$snap;unset($legacy['source_created_at_unix']);modeledProfileAppend($p,$legacy,$now+10);
    foreach([10,14,15,16]as$c){$other=$snap;$other['source_id']=(string)(100+$c);$other['conversation_id']=(string)$c;$other['customer_key']=(string)$c;modeledProfileAppend($p,$other,$now+10);}
    $snap['payload']['text']='Бюджет до 900 тыс на всех';modeledProfileAppend($p,$snap,$now+10);
    $future=$snap;$future['payload']['text']='Бюджет до 1000 тыс на всех';
    $r=$profiles->tick($now+11,static function()use($p,$future,$now){modeledProfileAppend($p,$future,$now+11);});
    $first=modeledProfileLatest($p);$frozen=$p->query("SELECT profile_json FROM modeled_conversion_profile_versions WHERE conversation_id='11' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $frozenId=$p->query("SELECT id FROM modeled_conversion_profile_versions WHERE conversation_id='11' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $check('legacy clock is resolved by a revision already available within the information fence',$r['profiles_written']===4&&$r['pending_revisions']===2&&$first['profile']['fields']['budget']['value']['maximum']===900000&&(int)$first['processing']['source_fence_journal_id']>(int)$first['processing']['journal_cursor']);
    $r=$profiles->tick($now+12);$check('a revision appended after the information fence enters only the next prediction',$r['profiles_written']===2&&$r['pending_revisions']===0&&modeledProfileLatest($p)['profile']['fields']['budget']['value']['maximum']===1000000);
    $check('later source revisions cannot rewrite a previously frozen profile',$p->query('SELECT profile_json FROM modeled_conversion_profile_versions WHERE id='.(int)$frozenId)->fetchColumn()===$frozen);
    $p->exec('UPDATE conversations SET is_test=1 WHERE id=11');$s->tick($now+13);$profiles->tick($now+14);$v=modeledProfileLatest($p);
    $check('revoked source scope invalidates the current profile without copying older text',$v['access_status']==='denied'&&$v['profile']['prediction_features']['facts']===[]);
    $p->exec('UPDATE conversations SET is_test=0 WHERE id=11');$s->tick($now+15);$profiles->tick($now+16);
    $check('scope restoration creates a new current profile with its actual new receipt',modeledProfileLatest($p)['access_status']==='allowed');
    // Isolate throughput from source-reconciliation tests: all following rows are already admitted fixtures.
    for($i=0;$i<100;$i++){$c=(string)(1000+$i);$x=$snap;$x['source_id']=(string)(2000+$i);$x['conversation_id']=$c;$x['customer_key']=$c;modeledProfileAppend($p,$x,$now+20);}
    $bounded=true;$written=0;
    for($i=0;$i<25;$i++){$r=$profiles->tick($now+21+$i);$written+=$r['profiles_written'];$bounded=$bounded&&$r['journal_rows_read']<=500&&$r['payload_bytes']<=2097152&&$r['profiles_written']<=4;}
    $check('mass journal processing keeps row byte and affected-scope limits',$bounded);
    $check('automatic bounded processing eventually profiles all100synthetic conversation scopes',$written===100&&$r['pending_revisions']===0);
    $profiles->stop();$check('stopping profile processing preserves source intake and private history',$profiles->tick($now+50)['status']==='off'&&(int)$p->query('SELECT enabled FROM modeled_conversion_stream_state')->fetchColumn()===1);
    $profiles->start();$check('resuming profiles preserves its cursor rather than replaying journal history',$profiles->tick($now+51)['profiles_written']===0);
    $p->beginTransaction();try{$profiles->tick($now+52);$ok=false;}catch(Throwable $e){$ok=$e->getMessage()==='modeled_profiles_outer_transaction';}finally{$p->rollBack();}
    $check('profile processor cannot commit a caller-owned transaction',$ok);
}


if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    modeledStreamSchema($p);$passed=0;
    $check=static function(string $name,bool $ok)use(&$passed){if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;};
    modeledStreamAssertions($p,$check);modeledStreamSchedulerAssertions($check);modeledProfileAssertions($p,$check);
    echo 'MODELED CONVERSION STREAM PASSED '.$passed.PHP_EOL;
}

