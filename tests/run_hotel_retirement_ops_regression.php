<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelRetirementServerOps.php';
require_once dirname(__DIR__).'/services/HotelWorkQueue.php';
require_once __DIR__.'/fixtures/hotel_work_queue.php';
require_once __DIR__.'/fixtures/hotel_work_retirement.php';
$hrsChecks=0;
function hrsAssert(bool$ok,string$name):void{global$hrsChecks;++$hrsChecks;if(!$ok)throw new RuntimeException('retirement_ops:'.$name);}
function hrsReject(callable$f,string$name):void{try{$f();}catch(Throwable$e){hrsAssert(true,$name);return;}hrsAssert(false,$name);}
final class HrsStatement extends PDOStatement
{
    public function __construct(private array$rows){}
    public function fetchAll(int$mode=PDO::FETCH_DEFAULT,mixed...$args):array{return$mode===PDO::FETCH_COLUMN?array_map(static fn($r)=>array_values($r)[0],$this->rows):$this->rows;}
}
final class HrsMetadataPdo extends PDO
{
    public array$calls=[];public int$triggers=0;private bool$active=false;
    public function __construct(public array$meta,public array$globals=[],public array$schema=[],private bool$fail=false){}
    public function getAttribute(int$attribute):mixed{return$attribute===PDO::ATTR_DRIVER_NAME?'mysql':null;}
    public function exec(string$statement):int|false{if($statement!=='SET TRANSACTION READ ONLY')throw new RuntimeException('unexpected_SQL_mutation');$this->calls[]=$statement;return 0;}
    public function beginTransaction():bool{$this->calls[]='BEGIN';return$this->active=true;}
    public function inTransaction():bool{return$this->active;}
    public function rollBack():bool{$this->calls[]='ROLLBACK';$this->active=false;return true;}
    public function query(string$query,?int$fetchMode=null,mixed...$args):PDOStatement|false{
        $this->calls[]=$query;
        if(str_contains($query,'information_schema.TRIGGERS'))return new HrsStatement(array_map(static fn($n)=>[$n],$this->triggers===37?HotelRetirementServerOps::triggerNames():[]));
        if(!$this->active)throw new RuntimeException('metadata_not_in_readonly_transaction');
        if($this->fail)throw new RuntimeException('PRIVATE_CREDENTIAL_CANARY');
        return new HrsStatement(match($query){
            HotelRetirementServerOps::META=>[$this->meta],
            HotelRetirementServerOps::GLOBALS=>array_map(static fn($x)=>['PRIVILEGE_TYPE'=>$x],$this->globals),
            HotelRetirementServerOps::SCHEMA=>array_map(static fn($x)=>['PRIVILEGE_TYPE'=>$x],$this->schema),
            default=>throw new RuntimeException('unexpected_metadata_SQL')});
    }
}
$meta=['db'=>'approved-fixture','host'=>'same-server','port'=>3306,'server_id'=>1,'socket'=>'/run/mysql.sock','binlog'=>1,'trust_creators'=>0,'version'=>'8.0'];
$appFake=new HrsMetadataPdo($meta,[],['TRIGGER']);$adminFake=new HrsMetadataPdo($meta,['SUPER','TRIGGER']);
$otherMeta=$meta;$otherMeta['server_id']=2;$otherFake=new HrsMetadataPdo($otherMeta,['SUPER','TRIGGER']);
$report=[];$chosen=HotelRetirementServerOps::existingAdmin($appFake,['wrong_target'=>static fn()=>$otherFake,'existing_maintenance'=>static fn()=>$adminFake],$report);
hrsAssert($chosen===$adminFake&&!$report['application']['eligible']&&!$report['wrong_target']['eligible']&&$report['existing_maintenance']['eligible'],'same_target_existing_admin_only');
foreach([$appFake,$adminFake,$otherFake]as$fake){hrsAssert($fake->calls[0]==='SET TRANSACTION READ ONLY'&&end($fake->calls)==='ROLLBACK','metadata_readonly_and_rollback');}
$report=[];try{HotelRetirementServerOps::existingAdmin(new HrsMetadataPdo($meta,[],[],true),[],$report);}catch(Throwable$e){hrsAssert(!str_contains(json_encode($report),'PRIVATE_CREDENTIAL_CANARY'),'metadata_error_not_exported');}
$report=[];try{HotelRetirementServerOps::existingAdmin($appFake,['failed_profile'=>static function(){throw new RuntimeException('PRIVATE_CREDENTIAL_CANARY');}],$report);}catch(HotelExistingAdminUnavailable$e){hrsAssert(!str_contains(json_encode($e->report),'PRIVATE_CREDENTIAL_CANARY')&&!$e->report['failed_profile']['authenticated'],'credential_error_not_exported');}
$profile=tempnam(sys_get_temp_dir(),'hrs-profile-');chmod($profile,0600);$opened=0;$ctx=['euid'=>fileowner($profile),'app_host'=>'localhost','profile_paths'=>[$profile]];
try{
    file_put_contents($profile,"[client]\nuser=fixture-maintenance\npassword=PRIVATE_CREDENTIAL_CANARY\nhost=localhost\n");
    $p=HotelRetirementServerOps::profileConnection($profile,$ctx,$meta,static function($u,$pass)use(&$opened,$adminFake){++$opened;hrsAssert($u==='fixture-maintenance'&&$pass==='PRIVATE_CREDENTIAL_CANARY','existing_profile_used_only_for_authentication');return$adminFake;});
    hrsAssert($p===$adminFake&&$opened===1,'trusted_existing_profile_provider');
    foreach(["host=foreign-server","port=3307","socket=/foreign/socket"]as$mismatch){
        file_put_contents($profile,"[client]\nuser=fixture-maintenance\npassword=PRIVATE_CREDENTIAL_CANARY\n".$mismatch."\n");
        hrsReject(fn()=>HotelRetirementServerOps::profileConnection($profile,$ctx,$meta,static function()use(&$opened,$adminFake){++$opened;return$adminFake;}),'foreign_profile_target_denied');
    }
    hrsAssert($opened===1,'foreign_targets_not_authenticated');
    chmod($profile,0644);hrsReject(fn()=>HotelRetirementServerOps::profileConnection($profile,$ctx,$meta,static fn()=>$adminFake),'nonprivate_profile_denied');
}finally{unlink($profile);}
$state=(object)['applied'=>false,'executions'=>0,'pendingExtra'=>false];
$factory=static function(PDO$p)use($state,$adminFake){
    return new class($p,$state,$adminFake){
        public function __construct(private PDO$p,private object$s,private PDO$expected){}
        public function status():array{$r=[['version'=>HotelRetirementServerOps::VERSION,'applied'=>$this->s->applied,'checksum_ok'=>true]];if($this->s->pendingExtra)$r[]=['version'=>'unapproved.sql','applied'=>false,'checksum_ok'=>true];return$r;}
        public function migrate():array{hrsAssert($this->p===$this->expected,'canonical_runner_receives_verified_maintenance_PDO');++$this->s->executions;$this->s->applied=true;$this->expected->triggers=37;return['executed'=>[['version'=>HotelRetirementServerOps::VERSION]],'pending'=>[]];}
    };
};
$sqlPath=dirname(__DIR__).'/migrations/'.HotelRetirementServerOps::VERSION;
$state->pendingExtra=true;hrsReject(fn()=>HotelRetirementServerOps::migrate083($appFake,[], $factory,$sqlPath),'other_pending_migration_not_elevated');hrsAssert($state->executions===0,'no_unapproved_DDL');$state->pendingExtra=false;
$adminFake->triggers=0;$r=HotelRetirementServerOps::migrate083($appFake,['maintenance'=>static fn()=>$adminFake],$factory,$sqlPath);
hrsAssert($r['migration083']==='applied'&&$state->executions===1&&!$r['new_privileges_granted']&&!$r['global_settings_changed'],'exact083_canonical_journal_no_grants');
$r=HotelRetirementServerOps::migrate083($appFake,[],$factory,$sqlPath);hrsAssert($r['migration083']==='already_applied'&&$state->executions===1,'migration_replay_no_admin_or_DDL');

$native=getenv('HOTEL_WORK_NATIVE_MYSQL')==='1';$file=$native?null:tempnam(sys_get_temp_dir(),'hrs-db-');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>false];
if($native){
    $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
    if(preg_match('/(?:\Amysql:|;)dbname=maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?(?:;|\z)/D',$dsn)!==1)throw new RuntimeException('unsafe_retirement_ops_fixture');
    $options[PDO::ATTR_EMULATE_PREPARES]=false;$p=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,$options);
    if(!preg_match('/\Amaxbot_prepared_test(?:_[a-zA-Z0-9_]+)?\z/D',(string)$p->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('unsafe_retirement_ops_fixture');
}else $p=new PDO('sqlite:'.$file,null,null,$options);
hrtCleanup($p);$init=new HotelWorkTestPdo($file);$init=null;hrtSchema($p);
$conn=str_repeat('a',32);$queue=new HotelWorkQueue($p);$id=HotelRetirementServerOps::QUEUE;
$queue->create(1,$conn,['queue_id'=>$id,'mode'=>'continuous','baseline_sha256'=>str_repeat('b',64)]);
$p->prepare("UPDATE hotel_work_queues SET state='claimed',stage_index=41,accepted_batches=19,claim_sha256=?,current_run_id=NULL WHERE queue_id=?")->execute([str_repeat('c',64),$id]);
foreach([39,40]as$index){$bytes=json_encode(['outcome'=>'accepted_readback','operation_id'=>'fixture-'.$index]);$p->prepare('INSERT INTO hotel_work_receipts(queue_id,stage_index,stage_id,receipt_sha256,receipt_json,created_at) VALUES(?,?,?,?,?,?)')->execute([$id,$index,hash('sha256','prior-'.$index),hash('sha256',$bytes),$bytes,'2026-11-01 00:00:00']);}
$original=$p->query('SELECT * FROM hotel_work_receipts ORDER BY stage_index')->fetchAll(PDO::FETCH_ASSOC);
if($native){
    hrtCleanup($p);
    $migrationDir=sys_get_temp_dir().'/hrs-migrations-'.bin2hex(random_bytes(5));mkdir($migrationDir,0700);
    copy($sqlPath,$migrationDir.'/'.HotelRetirementServerOps::VERSION);file_put_contents($migrationDir.'/000_hrs_fixture.sql',"-- native test sentinel\n");
    (new MigrationRunner($migrationDir,$p))->ensureTable();
    $p->prepare('DELETE FROM schema_migrations WHERE version=?')->execute([HotelRetirementServerOps::VERSION]);
    $p->prepare('INSERT INTO schema_migrations(version,checksum,baseline,execution_ms) VALUES(?,?,0,0)')->execute(['000_hrs_fixture.sql',hash_file('sha256',$migrationDir.'/000_hrs_fixture.sql')]);
    try{
        $r=HotelRetirementServerOps::migrate083($p,[],static fn(PDO$db)=>new MigrationRunner($migrationDir,$db),$migrationDir.'/'.HotelRetirementServerOps::VERSION);
        hrsAssert($r['migration083']==='applied'&&HotelRetirementServerOps::triggerCount($p)===37,'native_real083_canonical_migration_and_metadata');
        hrsAssert($original===$p->query('SELECT * FROM hotel_work_receipts ORDER BY stage_index')->fetchAll(PDO::FETCH_ASSOC),'native_migration_preserves_original_receipts');
    }finally{$p->prepare('DELETE FROM schema_migrations WHERE version=?')->execute(['000_hrs_fixture.sql']);unlink($migrationDir.'/000_hrs_fixture.sql');unlink($migrationDir.'/'.HotelRetirementServerOps::VERSION);rmdir($migrationDir);}
}
$dir=sys_get_temp_dir().'/hrs-intent-'.bin2hex(random_bytes(5));
$authorize=static fn(PDO$db,int$admin):bool=>$admin===1;
hrsReject(fn()=>HotelRetirementServerOps::retireOld($p,$dir,static fn()=>false,null,$id,1),'current_admin_check_retained');
hrsAssert(!is_file($dir.'/original-request.json'),'denied_admin_cannot_prepare_intent');
$crash=static function(string$at):void{if($at==='intent_persisted')throw new RuntimeException('fixture_crash_before_apply');};
hrsReject(fn()=>HotelRetirementServerOps::retireOld($p,$dir,$authorize,$crash,$id,1),'crash_after_intent_before_apply');
$intent=file_get_contents($dir.'/original-request.json');
hrsAssert((int)$p->query('SELECT COUNT(*) FROM hotel_work_retirements')->fetchColumn()===0&&json_decode($intent,true)['request']===HotelRetirementServerOps::REQUEST,'durable_once_intent_before_server_mutation');
$stamp=$p->query('SELECT updated_at FROM hotel_work_queues')->fetchColumn();$p->exec("UPDATE hotel_work_queues SET updated_at='2026-11-01 00:00:01'");
hrsReject(fn()=>HotelRetirementServerOps::retireOld($p,$dir,$authorize,null,$id,1),'stale_saved_CAS_held_without_new_intent');
hrsAssert(file_get_contents($dir.'/original-request.json')===$intent,'stale_CAS_does_not_replace_request');
$p->prepare('UPDATE hotel_work_queues SET updated_at=?')->execute([$stamp]);
$lost=static function(string$at):void{if($at==='retirement_committed')throw new RuntimeException('fixture_lost_ack');};
hrsReject(fn()=>HotelRetirementServerOps::retireOld($p,$dir,$authorize,$lost,$id,1),'lost_ack_after_commit');
$r=HotelRetirementServerOps::retireOld($p,$dir,$authorize,null,$id,1);
hrsAssert($r['state']==='retired'&&$r['CENTRAL41']==='UNKNOWN'&&$r['accepted_batches']===19&&$r['acceptance_credit']===0&&!$r['new_start_allowed'],'real_fence_readback_no_acceptance_or_dispatch');
hrsAssert($r['already_recorded']&&file_get_contents($dir.'/original-request.json')===$intent&&(int)$p->query('SELECT COUNT(*) FROM hotel_work_retirements')->fetchColumn()===1,'lost_ack_reconciled_without_second_apply');
hrsAssert($original===$p->query('SELECT * FROM hotel_work_receipts ORDER BY stage_index')->fetchAll(PDO::FETCH_ASSOC),'all_original_receipt_bytes_preserved');
$queue->create(1,$conn,['queue_id'=>'OTHER-FIXTURE-METADATA','mode'=>'finite','baseline_sha256'=>str_repeat('e',64)]);
$r=HotelRetirementServerOps::retireOld($p,$dir,$authorize,null,$id,1);
hrsAssert(!$r['lane_free']&&$p->query("SELECT queue_id FROM hotel_work_lane WHERE lane_key='HOTEL'")->fetchColumn()==='OTHER-FIXTURE-METADATA','recorded_replay_preserves_another_lane_owner');
$policy=HotelRetirementServerOps::policy();hrsAssert(!$policy['new_start_allowed']&&!$policy['native_terminal_asserted']&&!$policy['global_Library_revocation_asserted']&&$policy['future_authorized_queue_ids']===[],'scoped_input_fence_not_global_stop_assertion');
$workflow=(string)file_get_contents(dirname(__DIR__).'/.github/workflows/deploy.yml');
hrsAssert(substr_count($workflow,'php tools/conversation_db.php migrate')===1&&str_contains($workflow,'run_hotel_op migrate083')&&str_contains($workflow,'run_hotel_op retire-old'),'canonical_deployment_owner_only');
hrsAssert(str_contains($workflow,'sudo -n -- /usr/bin/php tools/hotel_retirement_ops.php "$1"')&&!str_contains($workflow,'SET GLOBAL'),'only_existing_noninteractive_sudo_no_global_switch');
hrtCleanup($p);$p=null;$queue=null;if($file!==null)unlink($file);
foreach(glob($dir.'/*')?:[]as$path)unlink($path);rmdir($dir);
echo 'HOTEL RETIREMENT SERVER OPS: '.$hrsChecks.' PASS (isolated fixtures; no production execution)'.PHP_EOL;
