<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerQuickReplyService.php';
$passed=0;
function qrCheck(string $name,bool $ok):void {global $passed;if (!$ok) throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}

$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE manager_quick_replies (id INTEGER PRIMARY KEY AUTOINCREMENT,owner_manager_id INTEGER NULL,scope TEXT,title TEXT,body TEXT,category TEXT,version INTEGER,created_by_manager_id INTEGER,updated_by_manager_id INTEGER,created_at TEXT,updated_at TEXT)');
$pdo->exec('CREATE TABLE manager_quick_reply_favorites (manager_id INTEGER,reply_id INTEGER,sort_order INTEGER NOT NULL DEFAULT 0,created_at TEXT,PRIMARY KEY(manager_id,reply_id))');
$pdo->exec('CREATE TABLE manager_quick_reply_toolbar_states (manager_id INTEGER PRIMARY KEY,revision INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE manager_quick_reply_operations (manager_id INTEGER,operation_id TEXT,request_hash TEXT,result_json TEXT,created_at TEXT,PRIMARY KEY(manager_id,operation_id))');
$pdo->exec('CREATE TABLE admin_audit_log (actor_manager_id INTEGER,action TEXT,entity_type TEXT,entity_id TEXT,project_key TEXT,before_json TEXT,after_json TEXT)');
$property=new ReflectionProperty(ConversationDb::class,'pdo');$property->setAccessible(true);$property->setValue(null,$pdo);
function qrMutate(int $actor,bool $admin,string $action,array $input):array {static $seq=0;$input+=['operation_id'=>'test-operation-'.str_pad((string)++$seq,8,'0',STR_PAD_LEFT)];return ManagerQuickReplyService::mutate($actor,$admin,$action,$input);}
function qrList(int $actor,array $input=[]):array {return ManagerQuickReplyService::list($actor,false,$input);}

$input=['title'=>'Личное приветствие','body'=>"Здравствуйте!\r\nМеня зовут Анна.",'category'=>'Приветствия','scope'=>'personal','operation_id'=>'stable-create-000001'];
$created=qrMutate(11,false,'create',$input);$id=$created['reply']['id'];
qrCheck('personal create stores normalized multiline text, editable own reply and version',!empty($created['ok'])&&$created['reply']['version']===1&&$created['reply']['can_edit']&&$created['reply']['body']==="Здравствуйте!\nМеня зовут Анна.");
qrCheck('retry after lost create response returns same receipt without duplicate',qrMutate(11,false,'create',$input)===$created&&(int)$pdo->query('SELECT COUNT(*) FROM manager_quick_replies')->fetchColumn()===1);
qrCheck('operation recovery is scoped to account',ManagerQuickReplyService::operation(11,$input['operation_id'])['result']===$created&&!ManagerQuickReplyService::operation(12,$input['operation_id'])['found']);
$changed=$input;$changed['body']='Другой текст';
qrCheck('operation key cannot be reused for a changed command',qrMutate(11,false,'create',$changed)['error']==='operation_mismatch');
qrCheck('another account cannot list or copy private replies',qrList(12)['replies']===[]&&qrMutate(12,false,'copy',['id'=>$id])['error']==='not_found');
qrCheck('admin has no implicit access to other personal replies',ManagerQuickReplyService::list(12,true)['replies']===[]&&qrMutate(12,true,'update',['id'=>$id,'version'=>1,'title'=>'x','body'=>'y'])['error']==='not_found');
qrCheck('private categories never leak',qrList(12)['categories']===[]);
qrCheck('non-admin cannot create shared reply',qrMutate(11,false,'create',['scope'=>'shared','title'=>'Общий','body'=>'Текст'])['error']==='forbidden');
$shared=qrMutate(90,true,'create',['scope'=>'shared','title'=>'Общий ответ','body'=>'Общий текст','category'=>'Уточнения']);$sid=$shared['reply']['id'];
qrCheck('shared reply available to managers without edit permission',count(qrList(12)['replies'])===1&&!qrList(12)['replies'][0]['can_edit']);
qrCheck('shared edit and delete require admin on server',qrMutate(12,false,'update',['id'=>$sid,'version'=>1,'title'=>'hack','body'=>'hack'])['error']==='forbidden'&&qrMutate(12,false,'delete',['id'=>$sid,'version'=>1])['error']==='forbidden');
$favorite=qrMutate(12,false,'favorite',['id'=>$sid,'is_favorite'=>true]);
qrCheck('favorites are per account and filter correctly',$favorite['reply']['is_favorite']&&count(qrList(12,['favorites_only'=>true])['replies'])===1&&qrList(11,['favorites_only'=>true])['replies']===[]);
$copy=qrMutate(12,false,'copy',['id'=>$sid]);$cid=$copy['reply']['id'];
qrCheck('copy shared reply creates editable personal copy, preserving original',$copy['reply']['scope']==='personal'&&$copy['reply']['can_edit']&&$cid!==$sid&&$copy['reply']['body']==='Общий текст');
$edit=qrMutate(12,false,'update',['id'=>$cid,'version'=>1,'title'=>'Моё уточнение','body'=>'Новый текст','category'=>'Запрос']);
qrCheck('update increments version and persists all fields',$edit['reply']['version']===2&&$edit['reply']['title']==='Моё уточнение'&&$edit['reply']['category']==='Запрос');
$stale=qrMutate(12,false,'update',['id'=>$cid,'version'=>1,'title'=>'Устарело','body'=>'Старая версия']);
qrCheck('stale editor cannot overwrite newer content',$stale['error']==='conflict'&&$stale['current']['body']==='Новый текст');
qrCheck('stale delete is rejected',qrMutate(12,false,'delete',['id'=>$cid,'version'=>1])['error']==='conflict');
$delete=['id'=>$cid,'version'=>2,'operation_id'=>'stable-delete-000001'];$deleted=qrMutate(12,false,'delete',$delete);
qrCheck('delete is replayable even after row is removed',qrMutate(12,false,'delete',$delete)===$deleted&&$deleted['deleted_id']===$cid);
$forOther=qrMutate(12,false,'create',$input);
qrCheck('operation keys are independent across accounts',$forOther['ok']&&$forOther['reply']['id']!==$id);
qrCheck('literal SQL LIKE metacharacters do not broaden search',qrList(12,['search'=>'%'])['replies']===[]&&qrList(12,['search'=>'_'])['replies']===[]);
qrCheck('body and title search include visible shared content',count(qrList(12,['search'=>'Общий текст'])['replies'])===1);
qrCheck('scope and category filters select exact visible rows',count(qrList(11,['scope'=>'personal','category'=>'Приветствия'])['replies'])===1&&count(qrList(11,['scope'=>'shared'])['replies'])===1);
qrCheck('input bounds count Unicode characters',!empty(ManagerQuickReplyService::normalize(['title'=>str_repeat('я',120),'body'=>str_repeat('я',8000)])['ok'])&&ManagerQuickReplyService::normalize(['title'=>str_repeat('я',121),'body'=>'text'])['error']==='invalid_title'&&ManagerQuickReplyService::normalize(['title'=>'x','body'=>str_repeat('я',8001)])['error']==='invalid_body');
qrCheck('blank and invalid controls rejected',ManagerQuickReplyService::normalize(['title'=>' ','body'=>'x'])['error']==='invalid_title'&&ManagerQuickReplyService::normalize(['title'=>'x','body'=>"a\0b"])['error']==='invalid_body');
qrCheck('invalid operation key is rejected before write',ManagerQuickReplyService::mutate(11,false,'delete',['id'=>$id,'version'=>1,'operation_id'=>'short'])['error']==='invalid_operation_id');

// Receipt failure after the reply write must roll back both; safe retry uses the same key.
$pdo->exec("CREATE TRIGGER reject_receipt BEFORE UPDATE ON manager_quick_reply_operations WHEN NEW.operation_id='rollback-receipt-0001' BEGIN SELECT RAISE(FAIL,'fixture receipt failure'); END");
$count=(int)$pdo->query('SELECT COUNT(*) FROM manager_quick_replies')->fetchColumn();$failed=false;
$rollback=['title'=>'Rollback fixture','body'=>'x','operation_id'=>'rollback-receipt-0001'];
try {qrMutate(11,false,'create',$rollback);} catch (PDOException $e) {$failed=true;}
qrCheck('failed receipt rolls back write and cannot be reported as success',$failed&&(int)$pdo->query('SELECT COUNT(*) FROM manager_quick_replies')->fetchColumn()===$count&&!ManagerQuickReplyService::operation(11,'rollback-receipt-0001')['found']);
$pdo->exec('DROP TRIGGER reject_receipt');
qrCheck('same key retry after rollback creates exactly one reply',qrMutate(11,false,'create',$rollback)['ok']&&(int)$pdo->query('SELECT COUNT(*) FROM manager_quick_replies')->fetchColumn()===$count+1);
$beforeAudit=(int)$pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn();
$pdo->exec("CREATE TRIGGER reject_audit BEFORE INSERT ON admin_audit_log BEGIN SELECT RAISE(FAIL,'fixture audit failure'); END");
$failed=false;try {qrMutate(90,true,'update',['id'=>$sid,'version'=>1,'title'=>'Should roll back','body'=>'x']);} catch (PDOException $e) {$failed=true;}
qrCheck('shared audit failure rolls back content and receipt',$failed&&qrList(12,['scope'=>'shared'])['replies'][0]['version']===1);
$pdo->exec('DROP TRIGGER reject_audit');
qrCheck('only shared changes appear in canonical audit and body omitted',$beforeAudit===1&&strpos((string)$pdo->query('SELECT after_json FROM admin_audit_log LIMIT 1')->fetchColumn(),'Общий текст')===false);
for ($i=0;$i<65;$i++) qrMutate(11,false,'create',['title'=>'Synthetic '.$i,'body'=>'text','category'=>'Pages']);
$page=qrList(11,['scope'=>'personal','category'=>'Pages','limit'=>50]);$next=qrList(11,['scope'=>'personal','category'=>'Pages','limit'=>50,'offset'=>$page['next_offset']]);
qrCheck('pagination retrieves entire library without duplicates',count($page['replies'])===50&&$page['has_more']&&count($next['replies'])===15&&!$next['has_more']&&count(array_unique(array_merge(array_column($page['replies'],'id'),array_column($next['replies'],'id'))))===65);
qrCheck('pagination bound is explicit rather than silently truncating',qrList(11,['offset'=>10001])['error']==='invalid_offset');
$unfavorite=qrMutate(12,false,'favorite',['id'=>$sid,'is_favorite'=>false]);
qrCheck('favorite can be removed',$unfavorite['ok']&&!$unfavorite['reply']['is_favorite']&&qrList(12,['favorites_only'=>true])['replies']===[]);
qrMutate(11,false,'favorite',['id'=>$sid,'is_favorite'=>true]);
qrMutate(90,true,'delete',['id'=>$sid,'version'=>1]);
qrCheck('shared delete removes all favorite references',(int)$pdo->query('SELECT COUNT(*) FROM manager_quick_reply_favorites WHERE reply_id='.$sid)->fetchColumn()===0&&qrList(12,['scope'=>'shared'])['replies']===[]);

// Selection is the existing account favorite relation. New accounts and replies
// start empty; read-only toolbar requests do not create preference state.
$empty=ManagerQuickReplyService::toolbar(200);
qrCheck('new account toolbar is empty, bounded and read-only',$empty['ok']&&$empty['replies']===[]&&$empty['max_replies']===100&&strlen($empty['version'])===64&&(int)$pdo->query('SELECT COUNT(*) FROM manager_quick_reply_toolbar_states WHERE manager_id=200')->fetchColumn()===0);
$one=qrMutate(200,false,'create',['title'=>'First own','body'=>'Private first']);$oneId=$one['reply']['id'];
$two=qrMutate(200,false,'create',['title'=>'Second own','body'=>'Private second']);$twoId=$two['reply']['id'];
$foreign=qrMutate(201,false,'create',['title'=>'Foreign secret','body'=>'Never visible']);$foreignId=$foreign['reply']['id'];
$team=qrMutate(290,true,'create',['scope'=>'shared','title'=>'Team reply','body'=>'Shared body']);$teamId=$team['reply']['id'];
qrCheck('creating personal or shared replies does not implicitly select defaults',$one['toolbar']['replies']===[]&&$two['toolbar']['replies']===[]&&$team['toolbar']['replies']===[]&&ManagerQuickReplyService::toolbar(200)['version']===$empty['version']);
qrMutate(200,false,'favorite',['id'=>$oneId,'is_favorite'=>true,'toolbar_version'=>$empty['version']]);
qrMutate(200,false,'favorite',['id'=>$twoId,'is_favorite'=>true]);
$selected=qrMutate(200,false,'favorite',['id'=>$teamId,'is_favorite'=>true]);$toolbar=$selected['toolbar'];
qrCheck('selected personal and shared replies append in manager chosen order',array_column($toolbar['replies'],'id')===[$oneId,$twoId,$teamId]&&$selected['reply']['is_favorite']);
$same=qrMutate(200,false,'favorite',['id'=>$oneId,'is_favorite'=>true,'toolbar_version'=>$toolbar['version']]);
qrCheck('repeat selection keeps position and does not spuriously change version',$same['toolbar']===$toolbar);
$otherSelection=qrMutate(201,false,'favorite',['id'=>$teamId,'is_favorite'=>true]);
qrCheck('shared selection order remains independent for each account',array_column($otherSelection['toolbar']['replies'],'id')===[$teamId]&&ManagerQuickReplyService::toolbar(200)===$toolbar);
$reorder=['ids'=>[$teamId,$oneId,$twoId],'toolbar_version'=>$toolbar['version'],'operation_id'=>'toolbar-reorder-stable-0001'];
$ordered=qrMutate(200,false,'reorder',$reorder);
qrCheck('reorder persists full own membership and advances opaque version',$ordered['ok']&&array_column($ordered['toolbar']['replies'],'id')===$reorder['ids']&&$ordered['toolbar']['version']!==$toolbar['version']&&ManagerQuickReplyService::toolbar(200)===$ordered['toolbar']);
qrCheck('accepted reorder lost-response retry and receipt return exact committed result',qrMutate(200,false,'reorder',$reorder)===$ordered&&ManagerQuickReplyService::operation(200,$reorder['operation_id'])['result']===$ordered);
$changedReorder=$reorder;$changedReorder['ids']=[$oneId,$teamId,$twoId];
$changedVersion=$reorder;$changedVersion['toolbar_version']=$ordered['toolbar']['version'];
qrCheck('reorder receipts hash IDs and toolbar version',qrMutate(200,false,'reorder',$changedReorder)['error']==='operation_mismatch'&&qrMutate(200,false,'reorder',$changedVersion)['error']==='operation_mismatch');
$staleOrder=qrMutate(200,false,'reorder',['ids'=>[$oneId,$twoId,$teamId],'toolbar_version'=>$toolbar['version']]);
$staleSelection=qrMutate(200,false,'favorite',['id'=>$oneId,'is_favorite'=>false,'toolbar_version'=>$toolbar['version']]);
qrCheck('stale order and selection cannot overwrite a newer tab',$staleOrder['error']==='conflict'&&$staleOrder['toolbar']===$ordered['toolbar']&&$staleSelection['error']==='conflict'&&ManagerQuickReplyService::toolbar(200)===$ordered['toolbar']);
foreach ([[$teamId,$oneId],[$teamId,$oneId,$twoId,$foreignId],[$teamId,$oneId,$twoId,999999]] as $badMembership) {
    $rejected=qrMutate(200,false,'reorder',['ids'=>$badMembership,'toolbar_version'=>$ordered['toolbar']['version']]);
    qrCheck('non-exact membership fails without private reply leakage',$rejected['error']==='conflict'&&$rejected['toolbar']===$ordered['toolbar']&&strpos(json_encode($rejected,JSON_THROW_ON_ERROR),'Foreign secret')===false);
}
foreach ([null,'ids',[$oneId,$oneId],['1'],[0],[-1],[1.5],['nested'=>1],array_fill(0,101,$oneId)] as $invalidIds) {
    qrCheck('malformed or overbound toolbar IDs rejected',qrMutate(200,false,'reorder',['ids'=>$invalidIds,'toolbar_version'=>$ordered['toolbar']['version']])['error']==='invalid_ids');
}
qrCheck('foreign personal selection remains forbidden even for admin',qrMutate(200,true,'favorite',['id'=>$foreignId,'is_favorite'=>true])['error']==='not_found'&&strpos(json_encode(ManagerQuickReplyService::toolbar(200,true),JSON_THROW_ON_ERROR),'Foreign secret')===false);
$removed=qrMutate(200,false,'favorite',['id'=>$oneId,'is_favorite'=>false]);
$readded=qrMutate(200,false,'favorite',['id'=>$oneId,'is_favorite'=>true]);
qrCheck('remove and reselect append, preserve siblings and invalidate old version',array_column($removed['toolbar']['replies'],'id')===[$teamId,$twoId]&&array_column($readded['toolbar']['replies'],'id')===[$teamId,$twoId,$oneId]&&$readded['toolbar']['version']!==$ordered['toolbar']['version']);
$teamChanged=qrMutate(290,true,'update',['id'=>$teamId,'version'=>1,'title'=>'Renamed team','body'=>'Updated shared body']);
$renamed=ManagerQuickReplyService::toolbar(200);
qrCheck('shared rename updates selected buttons without changing manager order',array_column($renamed['replies'],'id')===[$teamId,$twoId,$oneId]&&$renamed['replies'][0]['title']==='Renamed team'&&$renamed['replies'][0]['body']==='Updated shared body'&&$renamed['version']!==$readded['toolbar']['version']);
qrCheck('shared content update invalidates stale toolbar reorder',qrMutate(200,false,'reorder',['ids'=>[$oneId,$teamId,$twoId],'toolbar_version'=>$readded['toolbar']['version']])['error']==='conflict');
qrMutate(290,true,'delete',['id'=>$teamId,'version'=>2]);$afterTeamDelete=ManagerQuickReplyService::toolbar(200);
qrCheck('shared deletion removes selected references for all managers',array_column($afterTeamDelete['replies'],'id')===[$twoId,$oneId]&&ManagerQuickReplyService::toolbar(201)['replies']===[]&&(int)$pdo->query('SELECT COUNT(*) FROM manager_quick_reply_favorites WHERE reply_id='.$teamId)->fetchColumn()===0);
qrCheck('removed shared selection cannot be restored by stale reorder',qrMutate(200,false,'reorder',['ids'=>[$teamId,$twoId,$oneId],'toolbar_version'=>$renamed['version']])['error']==='conflict');

// Reorder and its revision must roll back together with a failed receipt.
$pdo->exec("CREATE TRIGGER reject_toolbar_receipt BEFORE UPDATE ON manager_quick_reply_operations WHEN NEW.operation_id='toolbar-rollback-000001' BEGIN SELECT RAISE(FAIL,'fixture receipt failure'); END");
$rollbackOrder=['ids'=>[$oneId,$twoId],'toolbar_version'=>$afterTeamDelete['version'],'operation_id'=>'toolbar-rollback-000001'];$failed=false;
try {qrMutate(200,false,'reorder',$rollbackOrder);} catch (PDOException $e) {$failed=true;}
qrCheck('reorder receipt failure rolls back positions and monotonic version',$failed&&ManagerQuickReplyService::toolbar(200)===$afterTeamDelete&&!ManagerQuickReplyService::operation(200,$rollbackOrder['operation_id'])['found']);
$pdo->exec('DROP TRIGGER reject_toolbar_receipt');
$rollbackRetry=qrMutate(200,false,'reorder',$rollbackOrder);
qrCheck('same reorder operation safely retries after rollback',$rollbackRetry['ok']&&array_column($rollbackRetry['toolbar']['replies'],'id')===[$oneId,$twoId]);
$ownDeleted=qrMutate(200,false,'delete',['id'=>$oneId,'version'=>1]);
qrCheck('own template deletion returns updated toolbar without reordering siblings',array_column($ownDeleted['toolbar']['replies'],'id')===[$twoId]);
$nothing=ManagerQuickReplyService::toolbar(222);
qrCheck('empty exact reorder is a safe no-op without selecting defaults',qrMutate(222,false,'reorder',['ids'=>[],'toolbar_version'=>$nothing['version']])['toolbar']===$nothing);

// Use direct synthetic fixture writes for library sizes, exercising actual bounded
// SQL reads and runtime selection/reorder rules rather than inspecting source text.
$fixtureInsert=$pdo->prepare("INSERT INTO manager_quick_replies(owner_manager_id,scope,title,body,category,version,created_by_manager_id,updated_by_manager_id,created_at,updated_at) VALUES (300,'personal',?,'Fixture body','',1,300,300,'2026-10-14 00:00:00','2026-10-14 00:00:00')");
$fixtureFavorite=$pdo->prepare("INSERT INTO manager_quick_reply_favorites(manager_id,reply_id,sort_order,created_at) VALUES (300,?,?,'2026-10-14 00:00:00')");
$fullIds=[];
for ($i=1;$i<=100;$i++) {$fixtureInsert->execute(['Bound reply '.$i]);$fixtureId=(int)$pdo->lastInsertId();$fullIds[]=$fixtureId;$fixtureFavorite->execute([$fixtureId,$i]);}
$fixtureInsert->execute(['Over bound']);$overId=(int)$pdo->lastInsertId();$fullToolbar=ManagerQuickReplyService::toolbar(300);
qrCheck('toolbar fetch returns entire exact maximum selection',$fullToolbar['ok']&&count($fullToolbar['replies'])===100&&array_column($fullToolbar['replies'],'id')===$fullIds);
$overRejected=qrMutate(300,false,'favorite',['id'=>$overId,'is_favorite'=>true]);
qrCheck('new selection beyond cap is explicit and non-destructive',$overRejected['error']==='toolbar_limit_exceeded'&&$overRejected['toolbar']===$fullToolbar&&(int)$pdo->query('SELECT COUNT(*) FROM manager_quick_reply_favorites WHERE manager_id=300')->fetchColumn()===100);
qrCheck('existing selection remains valid at cap',qrMutate(300,false,'favorite',['id'=>$fullIds[0],'is_favorite'=>true])['toolbar']===$fullToolbar);
$fixtureFavorite->execute([$overId,101]);$overflow=ManagerQuickReplyService::toolbar(300);
qrCheck('legacy overflow is explicit, bounded and does not discard selections',!$overflow['ok']&&$overflow['error']==='toolbar_limit_exceeded'&&$overflow['has_more']&&$overflow['count_at_least']===101&&count($overflow['replies'])===100&&(int)$pdo->query('SELECT COUNT(*) FROM manager_quick_reply_favorites WHERE manager_id=300')->fetchColumn()===101);
qrCheck('legacy overflow forbids incomplete reorder',qrMutate(300,false,'reorder',['ids'=>$fullIds,'toolbar_version'=>$overflow['version']])['error']==='toolbar_limit_exceeded');
$overflowRemoved=qrMutate(300,false,'favorite',['id'=>$overId,'is_favorite'=>false,'toolbar_version'=>$overflow['version']]);
qrCheck('library unselection can recover legacy overflow without lost selections',$overflowRemoved['ok']&&$overflowRemoved['toolbar']['ok']&&array_column($overflowRemoved['toolbar']['replies'],'id')===$fullIds);

// Count executed SQL at the real PDO boundary. Library size cannot create N+1.
final class QuickReplyQueryCounter {public int $queries=0;}
final class QuickReplyCountedStatement extends PDOStatement
{
    protected function __construct(private QuickReplyQueryCounter $counter) {}
    public function execute(?array $params=null):bool {$this->counter->queries++;return parent::execute($params);}
}
$counter=new QuickReplyQueryCounter();
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[QuickReplyCountedStatement::class,[$counter]]);
qrList(11,['limit'=>1]);$smallQueries=$counter->queries;$counter->queries=0;
qrList(11,['limit'=>100]);
qrCheck('list and categories use two bounded queries at every library size',$smallQueries===2&&$counter->queries===2);
$counter->queries=0;ManagerQuickReplyService::toolbar(200);$smallToolbarQueries=$counter->queries;$counter->queries=0;
ManagerQuickReplyService::toolbar(300);
qrCheck('small and maximum toolbars use exactly two SQL queries',$smallToolbarQueries===2&&$counter->queries===2);
qrMutate(200,false,'favorite',['id'=>$foreignId,'is_favorite'=>false]); // Fails closed, never adds foreign state.
$extra=qrMutate(200,false,'create',['title'=>'Counter second','body'=>'Synthetic']);qrMutate(200,false,'favorite',['id'=>$extra['reply']['id'],'is_favorite'=>true]);
$smallToolbar=ManagerQuickReplyService::toolbar(200);$counter->queries=0;
qrMutate(200,false,'reorder',['ids'=>array_reverse(array_column($smallToolbar['replies'],'id')),'toolbar_version'=>$smallToolbar['version']]);$smallReorderQueries=$counter->queries;
$largeToolbar=ManagerQuickReplyService::toolbar(300);$counter->queries=0;
qrMutate(300,false,'reorder',['ids'=>array_reverse($fullIds),'toolbar_version'=>$largeToolbar['version']]);
qrCheck('two and one hundred selected replies reorder in a fixed number of SQL queries',$smallReorderQueries===11&&$counter->queries===11);

$api=(string)file_get_contents(dirname(__DIR__).'/manager/quick-replies-api.php');
qrCheck('endpoint uses canonical auth and CSRF and accepts POST only',str_contains($api,'ManagerHttp::requireManager()')&&str_contains($api,'ManagerHttp::requireCsrf($data)')&&str_contains($api,"!=='POST'")&&str_contains($api,'ManagerHttp::isAdmin($manager)'));
// Real loopback HTTP through the production endpoint and unmodified ManagerHttp,
// ManagerRequestContext and ManagerAuthService. Configuration/database/sessions are
// isolated synthetic files; no live config, login, transport or customer is used.
$httpRoot=sys_get_temp_dir().'/manager-quick-http-'.bin2hex(random_bytes(8));
mkdir($httpRoot,0700);mkdir($httpRoot.'/manager',0700);mkdir($httpRoot.'/sessions',0700);
$process=null;$httpDb=null;
try {
    $httpDb=new PDO('sqlite:'.$httpRoot.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach ($pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $schema) $httpDb->exec($schema);
    $httpDb->exec("CREATE TABLE projects (id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
        CREATE TABLE manager_projects (manager_id INTEGER,project_id INTEGER);
        CREATE TABLE managers (id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
        INSERT INTO projects VALUES (1,'quick-http-fixture','Synthetic workspace',1);
        INSERT INTO managers VALUES (101,'fixture-manager','Synthetic manager','manager','',1,0),(102,'fixture-admin','Synthetic admin','admin','',1,0),(103,'disabled','Synthetic inactive','manager','',0,0);
        INSERT INTO manager_projects VALUES (101,1),(102,1);");
    $sourceRoot=dirname(__DIR__);
    file_put_contents($httpRoot.'/config.php',str_replace('\\n',"\n",'<?php\nrequire_once '.var_export($sourceRoot.'/services/ManagerRequestContext.php',true).';\n'.
        '$fixturePdo=new PDO('.var_export('sqlite:'.$httpRoot.'/fixture.sqlite',true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);\n'.
        '(new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$fixturePdo);\n'.
        'ProjectConfig::resetForTests(["id"=>"quick-http-fixture","brand"=>["name"=>"Synthetic workspace"]]);\nProjectAccessService::initializeReadOnly();\n'));
    copy($sourceRoot.'/manager/quick-replies-api.php',$httpRoot.'/manager/quick-replies-api.php');
    symlink($sourceRoot.'/manager/lib',$httpRoot.'/manager/lib');symlink($sourceRoot.'/services',$httpRoot.'/services');
    file_put_contents($httpRoot.'/manager/fixture-session.php','<?php require dirname(__DIR__)."/config.php";require __DIR__."/lib/ManagerHttp.php";ManagerHttp::startJson();$_SESSION["manager_id"]=(int)($_GET["id"]??101);$_SESSION["csrf"]="fixture-csrf-only";ManagerHttp::respond(["ok"=>true]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if (!$socket) throw new RuntimeException('Cannot allocate loopback fixture');
    $address=(string)stream_socket_get_name($socket,false);fclose($socket);
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$httpRoot.'/sessions','-S',$address,'-t',$httpRoot],[0=>['pipe','r'],1=>['file',$httpRoot.'/server.log','a'],2=>['file',$httpRoot.'/server.log','a']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start loopback PHP');fclose($pipes[0]);
    $ready=false;for ($wait=0;$wait<200;$wait++) {$probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05);if ($probe) {fclose($probe);$ready=true;break;}usleep(10000);}
    if (!$ready) throw new RuntimeException('Loopback PHP not ready');
    $request=static function(string $path,array $data=[],string $cookie='',string $method='POST') use ($address):array {
        $headers=['Content-Type: application/json'];if ($cookie!=='') $headers[]='Cookie: '.$cookie;
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='POST'?json_encode($data,JSON_THROW_ON_ERROR):'','ignore_errors'=>true,'timeout'=>3]]);
        $body=file_get_contents('http://'.$address.$path,false,$context);$responseHeaders=$http_response_header??[];
        preg_match('/^HTTP\/[^ ]+ ([0-9]+)/',(string)($responseHeaders[0]??''),$status);$responseCookie='';
        foreach ($responseHeaders as $header) if (preg_match('/^Set-Cookie: (anytour_manager_panel=[^;]+)/i',$header,$match)) $responseCookie=$match[1];
        return ['status'=>(int)($status[1]??0),'body'=>json_decode((string)$body,true),'cookie'=>$responseCookie,'headers'=>$responseHeaders];
    };
    $anonymous=$request('/manager/quick-replies-api.php',['action'=>'list']);
    qrCheck('real endpoint rejects anonymous request with 401', $anonymous['status']===401&&$anonymous['body']['error']==='unauthorized');
    $login=$request('/manager/fixture-session.php?id=101',[],'','GET');$cookie=$login['cookie'];
    qrCheck('synthetic fixture session uses the real Manager cookie', $cookie!=='');
    $csrfFailure=$request('/manager/quick-replies-api.php',['action'=>'list'],$cookie);
    qrCheck('real endpoint rejects absent CSRF with 403',$csrfFailure['status']===403&&$csrfFailure['body']['error']==='csrf');
    $valid=$request('/manager/quick-replies-api.php',['action'=>'list','csrf'=>'fixture-csrf-only'],$cookie);
    qrCheck('real authenticated endpoint lists through canonical Manager auth',$valid['status']===200&&$valid['body']['ok']&&$valid['body']['replies']===[]);
    $emptyHttpToolbar=$request('/manager/quick-replies-api.php',['action'=>'toolbar','csrf'=>'fixture-csrf-only'],$cookie);
    qrCheck('real toolbar read returns empty account without any preference write',$emptyHttpToolbar['status']===200&&$emptyHttpToolbar['body']['replies']===[]&&(int)$httpDb->query('SELECT COUNT(*) FROM manager_quick_reply_toolbar_states')->fetchColumn()===0);
    qrCheck('real endpoint applies no-store JSON headers',count(array_filter($valid['headers'],static fn(string $h):bool=>str_starts_with(strtolower($h),'cache-control: no-store')))===1);
    $forbidden=$request('/manager/quick-replies-api.php',['action'=>'create','csrf'=>'fixture-csrf-only','scope'=>'shared','title'=>'Synthetic shared','body'=>'text','operation_id'=>'http-shared-operation-0001'],$cookie);
    qrCheck('real valid-CSRF manager cannot create shared replies',$forbidden['status']===403&&$forbidden['body']['error']==='forbidden');
    $adminSession=$request('/manager/fixture-session.php?id=102',[],$cookie,'GET');$adminCookie=$adminSession['cookie']?:$cookie;
    $adminShared=$request('/manager/quick-replies-api.php',['action'=>'create','csrf'=>'fixture-csrf-only','scope'=>'shared','title'=>'Synthetic authorized shared','body'=>'text','operation_id'=>'http-admin-operation-00001'],$adminCookie);
    qrCheck('real ManagerAuth admin role permits shared create',$adminShared['status']===200&&$adminShared['body']['reply']['scope']==='shared');
    $managerSession=$request('/manager/fixture-session.php?id=101',[],$cookie,'GET');$cookie=$managerSession['cookie']?:$cookie;
    $sharedUpdate=$request('/manager/quick-replies-api.php',['action'=>'update','csrf'=>'fixture-csrf-only','id'=>$adminShared['body']['reply']['id'],'version'=>1,'title'=>'Unauthorized change','body'=>'x','operation_id'=>'http-manager-update-00001'],$cookie);
    qrCheck('real endpoint denies manager editing existing shared reply',$sharedUpdate['status']===403&&$sharedUpdate['body']['error']==='forbidden');
    $own=$request('/manager/quick-replies-api.php',['action'=>'create','csrf'=>'fixture-csrf-only','scope'=>'personal','title'=>'Synthetic personal','body'=>'text','operation_id'=>'http-personal-operation-001'],$cookie);
    qrCheck('real valid-CSRF personal create succeeds',$own['status']===200&&$own['body']['ok']&&$own['body']['reply']['can_edit']);
    $recovered=$request('/manager/quick-replies-api.php',['action'=>'operation','csrf'=>'fixture-csrf-only','operation_id'=>'http-personal-operation-001'],$cookie);
    qrCheck('real HTTP receipt recovers the committed personal write',$recovered['status']===200&&$recovered['body']['found']&&$recovered['body']['result']===$own['body']);
    $httpFirst=$request('/manager/quick-replies-api.php',['action'=>'favorite','csrf'=>'fixture-csrf-only','id'=>$own['body']['reply']['id'],'is_favorite'=>true,'toolbar_version'=>$own['body']['toolbar']['version'],'operation_id'=>'http-toolbar-first-000001'],$cookie);
    $httpSecond=$request('/manager/quick-replies-api.php',['action'=>'favorite','csrf'=>'fixture-csrf-only','id'=>$adminShared['body']['reply']['id'],'is_favorite'=>true,'toolbar_version'=>$httpFirst['body']['toolbar']['version'],'operation_id'=>'http-toolbar-second-00001'],$cookie);
    $httpToolbar=$request('/manager/quick-replies-api.php',['action'=>'toolbar','csrf'=>'fixture-csrf-only'],$cookie);
    qrCheck('real endpoint toolbar shows own selected personal and shared replies',array_column($httpToolbar['body']['replies'],'id')===[$own['body']['reply']['id'],$adminShared['body']['reply']['id']]&&$httpToolbar['body']===$httpSecond['body']['toolbar']);
    $httpReorderInput=['action'=>'reorder','csrf'=>'fixture-csrf-only','ids'=>array_reverse(array_column($httpToolbar['body']['replies'],'id')),'toolbar_version'=>$httpToolbar['body']['version'],'operation_id'=>'http-toolbar-reorder-00001'];
    $httpReorder=$request('/manager/quick-replies-api.php',$httpReorderInput,$cookie);$httpReplay=$request('/manager/quick-replies-api.php',$httpReorderInput,$cookie);
    qrCheck('real endpoint persists and exactly replays accepted reorder',$httpReorder['status']===200&&$httpReorder['body']['ok']&&array_column($httpReorder['body']['toolbar']['replies'],'id')===$httpReorderInput['ids']&&$httpReplay['body']===$httpReorder['body']);
    $httpStale=$request('/manager/quick-replies-api.php',array_replace($httpReorderInput,['ids'=>array_column($httpToolbar['body']['replies'],'id'),'operation_id'=>'http-toolbar-stale-000001']),$cookie);
    qrCheck('real endpoint stale reorder returns 409 and confirmed current toolbar',$httpStale['status']===409&&$httpStale['body']['error']==='conflict'&&$httpStale['body']['toolbar']===$httpReorder['body']['toolbar']);
    $httpInvalid=$request('/manager/quick-replies-api.php',array_replace($httpReorderInput,['ids'=>[1,1],'operation_id'=>'http-toolbar-invalid-0001']),$cookie);
    qrCheck('real endpoint duplicate IDs are rejected with 422',$httpInvalid['status']===422&&$httpInvalid['body']['error']==='invalid_ids');

    // Two independent PHP/PDO writers start from the same confirmed account
    // version. Exactly one order can commit; the other returns a conflict.
    $thirdHttp=$request('/manager/quick-replies-api.php',['action'=>'create','csrf'=>'fixture-csrf-only','title'=>'Third race reply','body'=>'Synthetic','operation_id'=>'http-race-third-create-001'],$cookie);
    $thirdSelected=$request('/manager/quick-replies-api.php',['action'=>'favorite','csrf'=>'fixture-csrf-only','id'=>$thirdHttp['body']['reply']['id'],'is_favorite'=>true,'operation_id'=>'http-race-third-select-001'],$cookie);
    $raceToolbar=$thirdSelected['body']['toolbar'];$raceIds=array_column($raceToolbar['replies'],'id');
    $workerCode='require '.var_export($httpRoot.'/config.php',true).';require '.var_export($sourceRoot.'/services/ManagerQuickReplyService.php',true).';fgets(STDIN);$command=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);echo json_encode(ManagerQuickReplyService::mutate(101,false,"reorder",$command),JSON_THROW_ON_ERROR);';
    $raceCommands=[['ids'=>array_reverse($raceIds),'toolbar_version'=>$raceToolbar['version'],'operation_id'=>'parallel-toolbar-race-001'],['ids'=>[$raceIds[1],$raceIds[2],$raceIds[0]],'toolbar_version'=>$raceToolbar['version'],'operation_id'=>'parallel-toolbar-race-002']];
    $raceProcesses=[];$racePipes=[];$raceResults=[];
    try {
        foreach ($raceCommands as $index=>$command) {
            $raceProcesses[$index]=proc_open([PHP_BINARY,'-r',$workerCode,json_encode($command,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$racePipes[$index]);
            if (!is_resource($raceProcesses[$index])) throw new RuntimeException('Cannot create isolated PDO race writer');
        }
        foreach ($racePipes as $pipes) {fwrite($pipes[0],"start\n");fclose($pipes[0]);}
        foreach ($racePipes as $index=>$pipes) {
            $workerBody=stream_get_contents($pipes[1]);$workerError=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            $exit=proc_close($raceProcesses[$index]);$raceProcesses[$index]=null;
            if ($exit!==0) throw new RuntimeException('Isolated PDO race writer failed: '.$workerError);
            $raceResults[]=json_decode($workerBody,true,512,JSON_THROW_ON_ERROR);
        }
    } finally {foreach ($raceProcesses as $worker) if (is_resource($worker)) {proc_terminate($worker);proc_close($worker);}}
    $raceSuccesses=array_values(array_filter($raceResults,static fn(array $r):bool=>!empty($r['ok'])));
    $raceConflicts=array_values(array_filter($raceResults,static fn(array $r):bool=>($r['error']??'')==='conflict'));
    $raceConfirmed=$request('/manager/quick-replies-api.php',['action'=>'toolbar','csrf'=>'fixture-csrf-only'],$cookie);
    qrCheck('independent simultaneous PDO reorder commands serialize without lost updates',count($raceSuccesses)===1&&count($raceConflicts)===1&&$raceConflicts[0]['toolbar']===$raceSuccesses[0]['toolbar']&&$raceConfirmed['body']===$raceSuccesses[0]['toolbar']);
    $get=$request('/manager/quick-replies-api.php',[],$cookie,'GET');
    qrCheck('real authenticated endpoint rejects non-POST method',$get['status']===405&&$get['body']['error']==='method_not_allowed');
    $inactive=$request('/manager/fixture-session.php?id=103',[],$cookie,'GET');
    $denied=$request('/manager/quick-replies-api.php',['action'=>'list','csrf'=>'fixture-csrf-only'],$inactive['cookie']?:$cookie);
    qrCheck('real ManagerAuth rejects disabled fixture account',$denied['status']===401&&$denied['body']['error']==='unauthorized');
    $restored=$request('/manager/fixture-session.php?id=101',[],$cookie,'GET');$cookie=$restored['cookie']?:$cookie;
    $httpDb->exec('DROP TABLE manager_quick_replies');
    $unavailable=$request('/manager/quick-replies-api.php',['action'=>'list','csrf'=>'fixture-csrf-only'],$cookie);
    qrCheck('real endpoint reports storage failure as 503, never empty success',$unavailable['status']===503&&!$unavailable['body']['ok']&&$unavailable['body']['error']==='storage_unavailable');
    $toolbarUnavailable=$request('/manager/quick-replies-api.php',['action'=>'toolbar','csrf'=>'fixture-csrf-only'],$cookie);
    qrCheck('real toolbar storage failure is 503 rather than empty defaults',$toolbarUnavailable['status']===503&&$toolbarUnavailable['body']['error']==='storage_unavailable');
} finally {
    if (is_resource($process)) {proc_terminate($process);proc_close($process);}
    $httpDb=null;
    $remove=static function(string $path) use (&$remove):void {if (is_link($path)||is_file($path)) {unlink($path);return;}if (is_dir($path)) {foreach (scandir($path)?:[] as $entry) if ($entry!=='.'&&$entry!=='..') $remove($path.'/'.$entry);rmdir($path);}};
    $remove($httpRoot);
}
echo "TOTAL {$passed} passed\n";
