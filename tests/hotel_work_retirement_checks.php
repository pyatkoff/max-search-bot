<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelWorkRetirement.php';
require_once dirname(__DIR__).'/services/HotelWorkQueue.php';
require_once __DIR__.'/fixtures/hotel_work_queue.php';
require_once __DIR__.'/fixtures/hotel_work_retirement.php';

if(($argv[1]??'')==='--retirement-old-sql-child'){
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];$out=$argv[3];
    if($argv[2]==='MYSQL'){
        $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');if(preg_match('/(?:\Amysql:|;)dbname=maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?(?:;|\z)/D',$dsn)!==1)throw new RuntimeException('unsafe_retirement_child');
        $p=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,$options);
    }else{if(!str_starts_with((string)realpath($argv[2]),realpath(sys_get_temp_dir()).'/hotel-retire-fixture-'))throw new RuntimeException('unsafe_retirement_child');$p=new PDO('sqlite:'.$argv[2],null,null,$options);$p->exec('PRAGMA busy_timeout=15000');}
    file_put_contents($out.'.started','ready');
    try{$p->exec("UPDATE hotel_work_queues SET state='ready',accepted_batches=accepted_batches+1 WHERE queue_id='RETIRE-FIXTURE'");$r=['denied'=>false];}
    catch(PDOException$e){$r=['denied'=>true,'driver_code'=>$e->errorInfo[1]??null];}
    file_put_contents($out.'.done',json_encode($r));exit;
}
$hrtChecks=0;
function hrtCheck(bool$ok,string$name):void{global$hrtChecks;++$hrtChecks;if(!$ok)throw new RuntimeException('retirement_assertion:'.$name);}
function hrtReject(callable$f,string$name):void{try{$f();}catch(Throwable$e){hrtCheck(true,$name);return;}hrtCheck(false,$name);}
$mysql=getenv('HOTEL_WORK_NATIVE_MYSQL')==='1';$file=$mysql?null:tempnam(sys_get_temp_dir(),'hotel-retire-fixture-');
$init=new HotelWorkTestPdo($file);$init=null;
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>false];
if($mysql){$options[PDO::ATTR_EMULATE_PREPARES]=false;$p=new PDO((string)getenv('PREPARED_REPLY_MYSQL_DSN'),getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,$options);}
else{$p=new PDO('sqlite:'.$file,null,null,$options);$p->exec('PRAGMA busy_timeout=15000');}
hrtSchema($p);$conn=str_repeat('a',32);$clock=static fn():int=>1793500000;$q=new HotelWorkQueue($p,$clock);
$q->create(1,$conn,['queue_id'=>'RETIRE-FIXTURE','mode'=>'continuous','start_country'=>'TR','phase_counter'=>0,'baseline_sha256'=>str_repeat('b',64)]);
$p->exec("UPDATE hotel_work_queues SET stage_index=41,state='claimed',accepted_batches=19,claim_sha256='".str_repeat('c',64)."',current_run_id='original-unresolved-run'");
$oldQ=$p->query("SELECT * FROM hotel_work_queues WHERE queue_id='RETIRE-FIXTURE'")->fetch(PDO::FETCH_ASSOC);$other=$oldQ;$other['queue_id']='OTHER-PRESERVED';$other['state']='released';
$s=$p->prepare('INSERT INTO hotel_work_queues('.implode(',',array_keys($other)).') VALUES('.implode(',',array_fill(0,count($other),'?')).')');$s->execute(array_values($other));
foreach([39,40]as$index){$bytes=json_encode(['outcome'=>'accepted_readback','operation_id'=>'prior-original-'.$index]);$p->prepare('INSERT INTO hotel_work_receipts(queue_id,stage_index,stage_id,receipt_sha256,receipt_json,created_at) VALUES(?,?,?,?,?,?)')->execute(['RETIRE-FIXTURE',$index,hash('sha256','original-'.$index),hash('sha256',$bytes),$bytes,'2026-11-01 00:00:00']);}
foreach(['RETIRE-FIXTURE','RETIRE-FIXTURE','OTHER-PRESERVED']as$i=>$id){
    $p->prepare('INSERT INTO hotel_work_events(subscription_id,single_consumer_key,admin_id,oauth_connection_id,project_key,queue_id,event_name,arguments_json,callback_url,signing_secret,verified_at,expires_at,is_active,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute(['sub-fixture-'.$i,hash('sha256','single-'.$i),1,$i===1?str_repeat('d',32):$conn,'anytour',$id,'hotel_work.pending','{}','https://retire-private.invalid/callback','whsec_'.base64_encode(str_repeat('s',32)),'2026-11-01 00:00:00','2027-11-01 00:00:00',1,'2026-11-01 00:00:00','2026-11-01 00:00:00']);
}
$authorize=static fn(PDO$same,int$admin):bool=>$same===$p&&$admin===1;$service=new HotelWorkRetirement($p,1,$authorize,$clock);
$pre=$service->preflight('RETIRE-FIXTURE',$conn);$receipts=$p->query('SELECT * FROM hotel_work_receipts ORDER BY stage_index')->fetchAll(PDO::FETCH_ASSOC);$otherBefore=$p->query("SELECT * FROM hotel_work_queues WHERE queue_id='OTHER-PRESERVED'")->fetch(PDO::FETCH_ASSOC);
$backup=['reference'=>'private-fixture-backup','sha256'=>hash('sha256','verified synthetic backup'),'verified_by_operator'=>true];
$isolation=['native_writers_stopped_or_fenced'=>true,'library_writers_stopped_or_fenced'=>true,'pending_callbacks_fenced'=>true,'evidence_sha256'=>hash('sha256','synthetic external isolation')];
$args=['RETIRE-FIXTURE',$conn,$pre['expected_state_sha256'],'owner-retirement-one',$backup,$isolation];
$bad=$args;$bad[2]=str_repeat('f',64);hrtReject(fn()=>$service->retire(...$bad),'stale_CAS_denied');
$bad=$args;$bad[1]=str_repeat('d',32);hrtReject(fn()=>$service->retire(...$bad),'wrong_owner_denied');
$bad=$args;$bad[4]['verified_by_operator']=false;hrtReject(fn()=>$service->retire(...$bad),'missing_verified_backup_denied');
foreach(['native_writers_stopped_or_fenced','library_writers_stopped_or_fenced','pending_callbacks_fenced']as$key){$bad=$args;$bad[5][$key]=false;hrtReject(fn()=>$service->retire(...$bad),'missing_isolation_'.$key);}
$denied=new HotelWorkRetirement($p,1,static fn():bool=>false,$clock);hrtReject(fn()=>$denied->retire(...$args),'revoked_admin_denied');
foreach(['retirement_before_fence','retirement_fence_written']as$point){
    $fault=new HotelWorkRetirement($p,1,$authorize,$clock,static function(string$at)use($point):void{if($at===$point)throw new RuntimeException('synthetic_retirement_fault');});
    hrtReject(fn()=>$fault->retire(...$args),'rollback_'.$point);
    hrtCheck($p->query("SELECT queue_id FROM hotel_work_lane WHERE lane_key='HOTEL'")->fetchColumn()==='RETIRE-FIXTURE'&&$p->query("SELECT state FROM hotel_work_queues WHERE queue_id='RETIRE-FIXTURE'")->fetchColumn()==='claimed'&&(int)$p->query('SELECT COUNT(*) FROM hotel_work_retirements')->fetchColumn()===0&&(int)$p->query("SELECT COUNT(*) FROM hotel_work_events WHERE queue_id='RETIRE-FIXTURE' AND is_active=1")->fetchColumn()===2,'state_events_lane_and_audit_rollback_together_'.$point);
}
$dir=sys_get_temp_dir().'/hotel-retirement-race-'.bin2hex(random_bytes(6));mkdir($dir,0700);$out=$dir.'/result';$process=null;
$race=static function(string$at)use($p,$file,$mysql,$dir,$out,&$process):void{
    if($at!=='retirement_before_fence')return;
    $process=proc_open([PHP_BINARY,__FILE__,'--retirement-old-sql-child',$mysql?'MYSQL':$file,$out],[0=>['pipe','r'],1=>['file',$dir.'/stdout','w'],2=>['file',$dir.'/stderr','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('retirement_race_spawn');fclose($pipes[0]);
    $until=microtime(true)+10;while(!is_file($out.'.started')&&microtime(true)<$until)usleep(1000);if(!is_file($out.'.started'))throw new RuntimeException('retirement_race_start');usleep(100000);
    hrtCheck(!is_file($out.'.done'),'old_raw_SQL_waits_during_administrative_retirement');
};
try{
    $racing=new HotelWorkRetirement($p,1,$authorize,$clock,$race);$result=$racing->retire(...$args);
    $until=microtime(true)+15;while(!is_file($out.'.done')&&microtime(true)<$until)usleep(1000);
    $child=is_file($out.'.done')?json_decode(file_get_contents($out.'.done'),true):[];
    hrtCheck(($child['denied']??false)===true&&in_array((int)($child['driver_code']??0),[19,1242],true),'old_SQL_denied_by_actual_database_fence_after_retirement');
}finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}foreach(glob($dir.'/*')?:[]as$path)unlink($path);rmdir($dir);}
hrtCheck($result['original_operation_outcome']==='UNKNOWN'&&$result['acceptance_credit']===0&&$result['accepted_batches']===19&&!$result['native_terminal_verified_by_service'],'unresolved_operation_stays_UNKNOWN_without_acceptance');
hrtCheck($receipts===$p->query('SELECT * FROM hotel_work_receipts ORDER BY stage_index')->fetchAll(PDO::FETCH_ASSOC),'original_accepted_receipts_never_rewritten_or_replayed');
hrtCheck($p->query("SELECT queue_id FROM hotel_work_lane WHERE lane_key='HOTEL'")->fetchColumn()===null,'shared_lane_freed_atomically');
hrtCheck((int)$p->query("SELECT COUNT(*) FROM hotel_work_events WHERE queue_id='RETIRE-FIXTURE' AND is_active=1")->fetchColumn()===0&&(int)$p->query("SELECT is_active FROM hotel_work_events WHERE queue_id='OTHER-PRESERVED'")->fetchColumn()===1,'only_old_queue_subscriptions_disabled_across_connections');
$archive=$p->query('SELECT snapshot_bytes FROM hotel_work_retirements')->fetchColumn();
hrtCheck(!str_contains($archive,'whsec_')&&!str_contains($archive,'retire-private.invalid'),'archive_does_not_duplicate_callback_credentials');
$stored=json_decode($archive,true);hrtCheck($stored['hotel_work_queues'][0]==$oldQ,'original_claim_run_and_queue_image_preserved');
hrtCheck($otherBefore===$p->query("SELECT * FROM hotel_work_queues WHERE queue_id='OTHER-PRESERVED'")->fetch(PDO::FETCH_ASSOC),'other_queue_unchanged');
foreach(["UPDATE hotel_work_queues SET state='ready' WHERE queue_id='RETIRE-FIXTURE'","DELETE FROM hotel_work_queues WHERE queue_id='RETIRE-FIXTURE'","UPDATE hotel_work_receipts SET receipt_json='{}' WHERE queue_id='RETIRE-FIXTURE'","DELETE FROM hotel_work_receipts WHERE queue_id='RETIRE-FIXTURE'","UPDATE hotel_work_events SET is_active=1 WHERE queue_id='RETIRE-FIXTURE'","UPDATE hotel_work_lane SET queue_id='RETIRE-FIXTURE' WHERE lane_key='HOTEL'","UPDATE hotel_work_retirements SET original_state_sha256='".str_repeat('f',64)."'","DELETE FROM hotel_work_retirements"]as$sql)hrtReject(fn()=>$p->exec($sql),'retired_old_writers_and_resurrection_denied');
$bytes=json_encode(['outcome'=>'accepted_readback']);hrtReject(fn()=>$p->prepare('INSERT INTO hotel_work_receipts(queue_id,stage_index,stage_id,receipt_sha256,receipt_json,created_at) VALUES(?,?,?,?,?,?)')->execute(['RETIRE-FIXTURE',41,hash('sha256','fake-completion'),hash('sha256',$bytes),$bytes,'2026-11-01 00:00:00']),'late_fake_completion_denied');
$status=$q->status(1,$conn,'RETIRE-FIXTURE');hrtCheck($status['state']==='retired'&&$status['stage_kind']===null&&$status['expected_pending_event_id']===null&&$status['accepted_batches']===19,'retired_status_is_terminal_not_failed_or_accepted');
hrtCheck($q->pendingEvent(1,$conn,'RETIRE-FIXTURE')===null,'retired_queue_emits_no_pending');
hrtReject(fn()=>$q->claim(1,$conn,['queue_id'=>'RETIRE-FIXTURE','stage_id'=>$pre['expected_state_sha256'],'claim_key'=>str_repeat('a',64)]),'cached_old_claim_cannot_restart');
$new=$q->create(1,$conn,['queue_id'=>'NEW-FIXTURE-METADATA-ONLY','mode'=>'continuous','baseline_sha256'=>str_repeat('e',64)]);
hrtCheck($new['created']===true&&$p->query("SELECT queue_id FROM hotel_work_lane WHERE lane_key='HOTEL'")->fetchColumn()==='NEW-FIXTURE-METADATA-ONLY','future_different_queue_can_use_the_freed_lane_in_fixture');
hrtCheck($service->retire(...$args)===$result&&$p->query("SELECT queue_id FROM hotel_work_lane WHERE lane_key='HOTEL'")->fetchColumn()==='NEW-FIXTURE-METADATA-ONLY','lost_ACK_replay_never_releases_a_new_owner_lane');
$oldReplay=$q->create(1,$conn,['queue_id'=>'RETIRE-FIXTURE','mode'=>'continuous','start_country'=>'TR','phase_counter'=>0,'baseline_sha256'=>str_repeat('b',64)]);
hrtCheck($oldReplay['state']==='retired'&&!$oldReplay['created'],'stale_create_cannot_recreate_retired_queue');
hrtCleanup($p);$p=null;$q=null;if($file!==null)@unlink($file);
echo 'HOTEL RETIREMENT: '.$hrtChecks.' PASS (isolated fixture; no native stop or production retirement proof)'.PHP_EOL;

