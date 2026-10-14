<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
$dsn=getenv('INTERNAL_NOTES_TEST_MYSQL_DSN');
if (!is_string($dsn)||$dsn==='') {echo "SKIP native internal notes MySQL fixture is not configured\n";exit(0);}
if (!preg_match('/\Amysql:host=(127\.0\.0\.1|localhost|mysql);(?:port=([0-9]{1,5});)?dbname=manager_internal_notes_test;charset=utf8mb4\z/D',$dsn)) {fwrite(STDERR,"Refusing non-ephemeral internal notes MySQL configuration\n");exit(1);}
$user=getenv('INTERNAL_NOTES_TEST_MYSQL_USER');$password=getenv('INTERNAL_NOTES_TEST_MYSQL_PASSWORD');
if (!is_string($user)||$user===''||!is_string($password)) {fwrite(STDERR,"Explicit internal notes fixture credentials required\n");exit(1);}
require_once dirname(__DIR__,2).'/services/ManagerInternalNoteService.php';require_once dirname(__DIR__,2).'/services/MigrationRunner.php';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
try {$pdo=new PDO($dsn,$user,$password,$options);} catch(Throwable $e) {fwrite(STDERR,"Native internal notes fixture connection failed\n");exit(1);}
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!=='manager_internal_notes_test') {fwrite(STDERR,"Refusing unexpected native internal notes schema\n");exit(1);}
foreach ([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) (new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
if (($argv[1]??'')==='--worker') {
    if ((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==9||$pdo->query('SELECT login FROM managers WHERE id=1')->fetchColumn()!=='internal-native-author') {fwrite(STDERR,"Refusing nonfixture internal notes worker\n");exit(1);}
    $command=json_decode((string)($argv[2]??''),true,32,JSON_THROW_ON_ERROR);
    if (!is_array($command)||!in_array($command['actor']??null,[1,90],true)||!in_array($command['action']??null,['create','update'],true)||!is_array($command['input']??null)) {fwrite(STDERR,"Refusing invalid native note worker\n");exit(1);}
    echo "READY\n";fflush(STDOUT);if (fgets(STDIN)!=="start\n") exit(1);
    echo json_encode(ManagerInternalNoteService::mutate($command['actor'],1,$command['action'],$command['input']),JSON_THROW_ON_ERROR);exit;
}
// Refuse all existing objects BEFORE fixture/migration DDL.
if ((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0||(int)$pdo->query('SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema=DATABASE()')->fetchColumn()!==0||(int)$pdo->query('SELECT COUNT(*) FROM information_schema.events WHERE event_schema=DATABASE()')->fetchColumn()!==0) {fwrite(STDERR,"Refusing nonempty native internal notes schema\n");exit(1);}
$pdo->exec("CREATE TABLE managers(id BIGINT UNSIGNED PRIMARY KEY,login VARCHAR(64),display_name VARCHAR(191),role VARCHAR(16),email VARCHAR(191),is_active TINYINT,is_working TINYINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE projects(id BIGINT UNSIGNED PRIMARY KEY,project_key VARCHAR(64),display_name VARCHAR(191),is_active TINYINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE manager_projects(manager_id BIGINT UNSIGNED,project_id BIGINT UNSIGNED) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE customers(id BIGINT UNSIGNED PRIMARY KEY,display_name VARCHAR(191),phone VARCHAR(64),email VARCHAR(191)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE manager_customer_names(customer_id BIGINT UNSIGNED PRIMARY KEY,working_name VARCHAR(191),version BIGINT UNSIGNED) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE conversation_sources(id BIGINT UNSIGNED PRIMARY KEY,display_name VARCHAR(191)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE conversations(id BIGINT UNSIGNED PRIMARY KEY,customer_id BIGINT UNSIGNED,project_key VARCHAR(64),source_id BIGINT UNSIGNED,channel VARCHAR(32),entry_channel VARCHAR(64),attribution_region VARCHAR(64),attribution_campaign VARCHAR(64),status VARCHAR(32),lead_stage_key VARCHAR(32),manager_id BIGINT UNSIGNED,started_at DATETIME,last_message_at DATETIME,closed_at DATETIME,external_chat_id VARCHAR(191)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("INSERT INTO managers VALUES(1,'internal-native-author','Native author','manager','',1,1),(2,'internal-native-colleague','Native colleague','manager','',1,1),(90,'internal-native-admin','Native admin','admin','',1,1),(3,'inactive','Inactive','manager','',0,1)");
$pdo->exec("INSERT INTO projects VALUES(1,'internal-native','Native fixture',1),(2,'foreign','Foreign fixture',1)");
$pdo->exec("INSERT INTO manager_projects VALUES(1,1),(2,1),(90,1)");$pdo->exec("INSERT INTO customers VALUES(1,'Synthetic customer','','')");
$pdo->exec("INSERT INTO conversations(id,customer_id,project_key,source_id,channel,status,manager_id,started_at) VALUES(1,1,'internal-native',0,'max','manager',2,'2026-10-14 00:00:00'),(2,1,'foreign',0,'max','manager',2,'2026-10-14 00:00:00')");
$runner=new MigrationRunner(dirname(__DIR__,2).'/migrations',$pdo);$split=new ReflectionMethod(MigrationRunner::class,'splitStatements');
foreach ($split->invoke($runner,(string)file_get_contents(dirname(__DIR__,2).'/migrations/035_manager_internal_notes.sql')) as $sql) $pdo->exec($sql);
$passed=0;
function nnCheck(string $name,bool $ok):void {global $passed;if (!$ok) throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
function nnMut(string $action,array $input=[],int $actor=1):array {static $seq=0;$input+=['operation_id'=>'native-internal-note-'.str_pad((string)++$seq,6,'0',STR_PAD_LEFT)];return ManagerInternalNoteService::mutate($actor,1,$action,$input);}
function nnRace(array $commands):array {
    $workers=[];$pipes=[];$out=[];
    try {
        foreach ($commands as $i=>$command) {$workers[$i]=proc_open([PHP_BINARY,__FILE__,'--worker',json_encode($command,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes[$i]);if (!is_resource($workers[$i])) throw new RuntimeException('Native notes worker unavailable');stream_set_timeout($pipes[$i][1],15);}
        foreach ($pipes as $p) if (fgets($p[1])!=="READY\n") throw new RuntimeException('Native notes worker not ready');
        foreach ($pipes as $p) {fwrite($p[0],"start\n");fclose($p[0]);}
        foreach ($pipes as $i=>$p) {$body=stream_get_contents($p[1]);$error=stream_get_contents($p[2]);fclose($p[1]);fclose($p[2]);$exit=proc_close($workers[$i]);$workers[$i]=null;if ($exit!==0||$error!=='') throw new RuntimeException('Native notes worker failed');$out[]=json_decode($body,true,32,JSON_THROW_ON_ERROR);}
    } finally {foreach ($workers as $i=>$worker) if (is_resource($worker)) {proc_terminate($worker);foreach ($pipes[$i]??[] as $p) if (is_resource($p)) fclose($p);proc_close($worker);}}
    return $out;
}
nnCheck('actual migration035 creates two indexed InnoDB staff tables',(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('manager_internal_notes','manager_internal_note_operations') AND engine='InnoDB'")->fetchColumn()===2&&(int)$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='manager_internal_notes' AND index_name='idx_internal_notes_conversation_page'")->fetchColumn()===2);
$before=$pdo->query('SELECT * FROM conversations ORDER BY id')->fetchAll();
nnCheck('native authorization-only empty read never requires or changes transcript',ManagerInternalNoteService::list(1,1)['notes']===[]);
$create=['body'=>'Native staff body','operation_id'=>'native-note-stable-create-001'];$note=nnMut('create',$create);$id=$note['id'];
nnCheck('native same-key create allocates one note and receipt',nnMut('create',$create)===$note&&(int)$pdo->query('SELECT COUNT(*) FROM manager_internal_notes')->fetchColumn()===1);
nnCheck('native shared-visible colleague cannot edit another author',!ManagerInternalNoteService::list(2,1)['notes'][0]['can_edit']&&nnMut('update',['id'=>$id,'version'=>1,'body'=>'Forbidden'],2)['error']==='forbidden');
$edit=nnMut('update',['id'=>$id,'version'=>1,'body'=>'Updated native staff']);
nnCheck('native current receipt projection never restores old body',ManagerInternalNoteService::operation(1,1,$create['operation_id'])['result']['note']===$edit['note']&&nnMut('create',$create)['note']===$edit['note']);
nnCheck('native immutable saved version stays original while current note advances',$note['saved_version']===1&&$edit['saved_version']===2&&ManagerInternalNoteService::operation(1,1,$create['operation_id'])['result']['saved_version']===1&&ManagerInternalNoteService::operation(1,1,$create['operation_id'])['result']['note']['version']===2);
nnCheck('native stale version returns current note conflict',nnMut('update',['id'=>$id,'version'=>1,'body'=>'Stale'])['note']===$edit['note']);
nnCheck('native fresh project access protects notes and receipt',ManagerInternalNoteService::list(1,2)['error']==='forbidden'&&ManagerInternalNoteService::operation(2,1,$create['operation_id'])['found']===false);
$raceNote=nnMut('create',['body'=>'Edit race']);$race=nnRace([
    ['actor'=>1,'action'=>'update','input'=>['id'=>$raceNote['id'],'version'=>1,'body'=>'Author race edit','operation_id'=>'native-note-edit-race-author-01']],
    ['actor'=>90,'action'=>'update','input'=>['id'=>$raceNote['id'],'version'=>1,'body'=>'Admin race edit','operation_id'=>'native-note-edit-race-admin-01']],
]);
$success=array_values(array_filter($race,static fn($r)=>!empty($r['ok'])));$conflict=array_values(array_filter($race,static fn($r)=>($r['error']??'')==='conflict'));
nnCheck('two native editors serialize one version commit and current conflict',count($success)===1&&count($conflict)===1&&$success[0]['note']['version']===2&&$conflict[0]['note']['version']===2&&$success[0]['note']['body']===$conflict[0]['note']['body']);
$same=['actor'=>1,'action'=>'create','input'=>['body'=>'Same parallel create','operation_id'=>'native-note-parallel-same-create-01']];$duplicate=nnRace([$same,$same]);
nnCheck('two native same-key creators allocate exactly one note and receipt',$duplicate[0]['ok']&&$duplicate[1]['ok']&&$duplicate[0]['id']===$duplicate[1]['id']&&(int)$pdo->query("SELECT COUNT(*) FROM manager_internal_note_operations WHERE manager_id=1 AND operation_id='native-note-parallel-same-create-01'")->fetchColumn()===1);

// A second connection commits AFTER the reader's canonical permission SELECT
// establishes a REPEATABLE READ snapshot but BEFORE its note locking read.
final class NoteSnapshotRacePdo extends PDO {
    public $beforeNoteLock=null;
    public function prepare(string $sql,array $options=[]):PDOStatement|false {if ($this->beforeNoteLock!==null&&str_starts_with($sql,'SELECT * FROM manager_internal_notes')&&str_ends_with($sql,' FOR UPDATE')) {$callback=$this->beforeNoteLock;$this->beforeNoteLock=null;$callback();}return parent::prepare($sql,$options);}
}
$reader=new NoteSnapshotRacePdo($dsn,$user,$password,$options);$reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$writer=new PDO($dsn,$user,$password,$options);
$controlled=nnMut('create',['body'=>'Before captured snapshot']);
$reader->beforeNoteLock=static function() use($writer,$reader,$controlled):void {(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$writer);try {nnMut('update',['id'=>$controlled['id'],'version'=>1,'body'=>'After independent commit'],90);}finally{(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$reader);}};
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$reader);$snapshotConflict=nnMut('update',['id'=>$controlled['id'],'version'=>1,'body'=>'Stale captured editor']);
nnCheck('native RR current conflict uses locked body not precommit MVCC snapshot',$snapshotConflict['error']==='conflict'&&$snapshotConflict['note']['version']===2&&$snapshotConflict['note']['body']==='After independent commit');
$reader->beforeNoteLock=static function() use($writer,$reader,$controlled):void {(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$writer);try {nnMut('delete',['id'=>$controlled['id'],'version'=>2],90);}finally{(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$reader);}};
$snapshotDeleted=nnMut('update',['id'=>$controlled['id'],'version'=>2,'body'=>'Predelete old body']);
nnCheck('native RR deletion projection never discloses predelete body',$snapshotDeleted['error']==='deleted'&&$snapshotDeleted['note']['version']===3&&$snapshotDeleted['note']['deleted']&&$snapshotDeleted['note']['body']==='');
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
$pdo->exec("CREATE TRIGGER internal_note_reject_receipt BEFORE UPDATE ON manager_internal_note_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic receipt failure'");
$count=(int)$pdo->query('SELECT COUNT(*) FROM manager_internal_notes')->fetchColumn();$rollback=['body'=>'Native rollback','operation_id'=>'native-note-rollback-receipt-01'];$failed=false;
try {nnMut('create',$rollback);} catch(PDOException $e) {$failed=true;}
nnCheck('native receipt failure atomically rolls back note and unknown outcome',$failed&&(int)$pdo->query('SELECT COUNT(*) FROM manager_internal_notes')->fetchColumn()===$count&&!ManagerInternalNoteService::operation(1,1,$rollback['operation_id'])['found']);
$pdo->exec('DROP TRIGGER internal_note_reject_receipt');$retry=nnMut('create',$rollback);
nnCheck('native same-key retry after proved rollback creates once',nnMut('create',$rollback)['id']===$retry['id']);
$deleted=nnMut('delete',['id'=>$id,'version'=>2]);
nnCheck('native soft delete and receipt keep blank tombstone',ManagerInternalNoteService::operation(1,1,$create['operation_id'])['result']['note']['body']===''&&$deleted['note']['deleted']);
nnCheck('native delete receipt records deletion version not original create version',$deleted['saved_version']===3&&ManagerInternalNoteService::operation(1,1,$create['operation_id'])['result']['saved_version']===1);
nnCheck('native annotations preserve assignment source and business status',$pdo->query('SELECT * FROM conversations ORDER BY id')->fetchAll()===$before);
echo 'NATIVE INTERNAL NOTES MYSQL OK ('.$passed." checks)\n";
