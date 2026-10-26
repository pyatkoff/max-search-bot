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

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    modeledStreamSchema($p);$passed=0;
    $check=static function(string $name,bool $ok)use(&$passed){if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;};
    modeledStreamAssertions($p,$check);modeledStreamSchedulerAssertions($check);
    echo 'MODELED CONVERSION STREAM PASSED '.$passed.PHP_EOL;
}
