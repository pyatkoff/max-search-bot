<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/fixtures/manager_lifecycle_fixture.php';
$pdo=new LifecycleFixturePdo('sqlite::memory:');lifecycleFixtureBind($pdo);lifecycleFixtureSchema($pdo);
$passed=0;
function lifecycleCheck(string $label,bool $ok): void {global $passed;if(!$ok)throw new RuntimeException('FAIL '.$label);$passed++;echo 'PASS '.$label."\n";}
function lifecycleResult(string $action,int $id,int $actor=1): array {return ManagerConversationService::{$action.'Result'}($id,$actor);}

foreach(['take','release','close','reopen'] as $action){
    foreach([999,17,18,0,-1] as $id){
        lifecycleFixtureSeed($pdo);$before=lifecycleFixtureSnapshot($pdo);$result=lifecycleResult($action,$id);
        lifecycleCheck($action.' missing/hidden context is indistinguishable and write-free '.$id,$result['ok']===false&&$result['error']==='unavailable'&&is_string($result['error_message'])&&lifecycleFixtureSnapshot($pdo)===$before&&MaxSearchApi::$calls===[]);
        lifecycleCheck($action.' refusal uses one canonical conversation read '.$id,count(array_filter($pdo->statements,static fn($sql)=>str_starts_with($sql,'SELECT id,project_key,source_id,status,manager_id,external_chat_id')))==1);
    }
}
foreach([
    ['take',13,1,'closed'],['take',12,1,'owned_elsewhere'],['take',12,3,'owned_elsewhere'],
    ['release',12,1,'not_owned'],['release',15,1,'not_owned'],['release',12,3,'not_owned'],['release',14,1,'state_changed'],
    ['close',13,1,'closed'],['close',12,1,'owned_elsewhere'],['close',12,3,'owned_elsewhere'],
    ['reopen',16,1,'state_changed'],
] as [$action,$id,$actor,$reason]){
    lifecycleFixtureSeed($pdo);$before=lifecycleFixtureSnapshot($pdo);$result=lifecycleResult($action,$id,$actor);
    lifecycleCheck($action.' known refusal '.$reason.' preserves business state actor'.$actor,$result['ok']===false&&$result['error']===$reason&&count($result)===3&&lifecycleFixtureSnapshot($pdo)===$before&&!$pdo->inTransaction()&&MaxSearchApi::$calls===[]);
}
lifecycleFixtureSeed($pdo);$before=lifecycleFixtureSnapshot($pdo);$result=lifecycleResult('take',16);
lifecycleCheck('same-owner take remains successful without duplicate assignment or event',$result===['ok'=>true]&&lifecycleFixtureSnapshot($pdo)===$before&&!$pdo->inTransaction());
foreach([['take',11,1,'manager','manual','manager_taken'],['take',19,1,'manager','manual','manager_taken'],['take',15,4,'manager','manual','manager_taken'],['reopen',13,1,'manager','reopen','conversation_reopened'],['reopen',13,4,'manager','reopen','conversation_reopened']] as [$action,$id,$actor,$status,$assignment,$event]){
    lifecycleFixtureSeed($pdo);$result=lifecycleResult($action,$id,$actor);$row=$pdo->query('SELECT * FROM conversations WHERE id='.$id)->fetch();
    lifecycleCheck($action.' preserves transition and assignment policy actor'.$actor.' id'.$id,$result===['ok'=>true]&&$row['status']===$status&&(int)$row['manager_id']===$actor&&$row['closed_at']===null&&$pdo->query('SELECT assignment_type FROM manager_assignments WHERE conversation_id='.$id.' ORDER BY id DESC LIMIT 1')->fetchColumn()===$assignment&&$pdo->query('SELECT event_type FROM conversation_events ORDER BY id DESC LIMIT 1')->fetchColumn()===$event);
}
foreach([['release',16,'ai','manager_released'],['close',16,'closed','conversation_closed'],['close',15,'closed','conversation_closed']] as [$action,$id,$status,$event]){
    lifecycleFixtureSeed($pdo);MaxSearchApi::$fail=true;$result=lifecycleResult($action,$id);$row=$pdo->query('SELECT * FROM conversations WHERE id='.$id)->fetch();
    lifecycleCheck($action.' preserves existing provider-failure tolerance and event',$result===['ok'=>true]&&$row['status']===$status&&count(MaxSearchApi::$calls)===1&&$pdo->query('SELECT event_type FROM conversation_events ORDER BY id DESC LIMIT 1')->fetchColumn()===$event);
    if($id===16)lifecycleCheck($action.' releases active assignment',$pdo->query('SELECT released_at FROM manager_assignments WHERE conversation_id=16')->fetchColumn()!==null);
    lifecycleCheck($action.' preserves historical manager-id semantics',$action==='release'?$row['manager_id']===null:(int)($row['manager_id']??0)===($id===16?1:0));
}
foreach(['take'=>11,'release'=>16,'close'=>16,'reopen'=>13] as $action=>$id){
    lifecycleFixtureSeed($pdo);lifecycleCheck($action.' legacy caller still receives strict true',ManagerConversationService::{$action}($id,1)===true);
    lifecycleCheck($action.' legacy caller still receives strict false',ManagerConversationService::{$action}(999,1)===false);
}
foreach(['take'=>11,'reopen'=>13] as $action=>$id){
    lifecycleFixtureSeed($pdo);$before=lifecycleFixtureSnapshot($pdo);
    $pdo->exec("CREATE TRIGGER reject_lifecycle_event BEFORE INSERT ON conversation_events BEGIN SELECT RAISE(FAIL,'Synthetic event failure'); END");
    $thrown=false;try{lifecycleResult($action,$id);}catch(PDOException $error){$thrown=true;}
    $pdo->exec('DROP TRIGGER reject_lifecycle_event');
    lifecycleCheck($action.' event failure rolls back transaction and propagates UNKNOWN',$thrown&&!$pdo->inTransaction()&&lifecycleFixtureSnapshot($pdo)===$before);
}
foreach(['release'=>'ai','close'=>'closed'] as $action=>$status){
    lifecycleFixtureSeed($pdo);$protected=lifecycleFixtureSnapshot($pdo,['managers','manager_projects','messages','manager_conversation_reads']);
    $pdo->exec("CREATE TRIGGER reject_lifecycle_event BEFORE INSERT ON conversation_events BEGIN SELECT RAISE(FAIL,'Synthetic late event failure'); END");
    $thrown=false;try{lifecycleResult($action,16);}catch(PDOException $error){$thrown=true;}
    $pdo->exec('DROP TRIGGER reject_lifecycle_event');
    lifecycleCheck($action.' existing nontransactional partial-write failure stays UNKNOWN not a refusal',$thrown&&$pdo->query('SELECT status FROM conversations WHERE id=16')->fetchColumn()===$status&&count(MaxSearchApi::$calls)===1&&lifecycleFixtureSnapshot($pdo,['managers','manager_projects','messages','manager_conversation_reads'])===$protected);
}
lifecycleFixtureSeed($pdo);$before=lifecycleFixtureSnapshot($pdo);$pdo->statements=[];
$state=ManagerConversationService::lifecycleState(16,1);
lifecycleCheck('explicit state projection is exact and cannot advance read markers',$state===['id'=>16,'status'=>'manager','manager_id'=>1]&&lifecycleFixtureSnapshot($pdo)===$before&&MaxSearchApi::$calls===[]);
lifecycleCheck('explicit state check reads the canonical row once',count(array_filter($pdo->statements,static fn($sql)=>str_starts_with($sql,'SELECT id,project_key,source_id,status,manager_id,external_chat_id')))==1);
foreach([999,17,18,0,-1] as $id)lifecycleCheck('explicit hidden/missing/invalid state is unavailable '.$id,ManagerConversationService::lifecycleState($id,1)===null);

// Execute the actual API and auth/CSRF boundary with fixture-only config/provider.
$httpRoot=sys_get_temp_dir().'/manager-lifecycle-http-'.bin2hex(random_bytes(8));$process=null;
mkdir($httpRoot,0700);mkdir($httpRoot.'/manager',0700);mkdir($httpRoot.'/sessions',0700);
try{
    $httpDb=new LifecycleFixturePdo('sqlite:'.$httpRoot.'/fixture.sqlite');lifecycleFixtureSchema($httpDb);lifecycleFixtureSeed($httpDb);
    $source=dirname(__DIR__);
    file_put_contents($httpRoot.'/config.php','<?php require '.var_export(__DIR__.'/fixtures/manager_lifecycle_http.php',true).'; lifecycleFixtureHttpBootstrap('.var_export($httpRoot.'/fixture.sqlite',true).');');
    file_put_contents($httpRoot.'/maxsearchclass.php','<?php // Synthetic provider is already defined; never load production transport.');
    copy($source.'/manager/api.php',$httpRoot.'/manager/api.php');symlink($source.'/manager/lib',$httpRoot.'/manager/lib');symlink($source.'/services',$httpRoot.'/services');
    file_put_contents($httpRoot.'/manager/fixture-session.php','<?php require dirname(__DIR__)."/config.php";require __DIR__."/lib/ManagerHttp.php";ManagerHttp::startJson();$_SESSION["manager_id"]=(int)($_GET["id"]??1);$_SESSION["csrf"]="synthetic-lifecycle-csrf";ManagerHttp::respond(["ok"=>true]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket)throw new RuntimeException('No loopback fixture port');$address=stream_socket_get_name($socket,false);fclose($socket);
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$httpRoot.'/sessions','-S',$address,'-t',$httpRoot],[0=>['pipe','r'],1=>['file',$httpRoot.'/server.log','a'],2=>['file',$httpRoot.'/server.log','a']],$pipes);if(!is_resource($process))throw new RuntimeException('No loopback PHP process');fclose($pipes[0]);
    $ready=false;for($i=0;$i<200;$i++){$probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05);if($probe){fclose($probe);$ready=true;break;}usleep(10000);}if(!$ready)throw new RuntimeException('Loopback PHP unavailable');
    $request=static function(array $data=[],string $cookie='',?int $login=null)use($address):array{
        $headers=['Content-Type: application/json'];if($cookie!=='')$headers[]='Cookie: '.$cookie;
        $context=stream_context_create(['http'=>['method'=>$login===null?'POST':'GET','header'=>implode("\r\n",$headers),'content'=>$login===null?json_encode($data,JSON_THROW_ON_ERROR):'','ignore_errors'=>true,'timeout'=>5]]);
        $body=file_get_contents('http://'.$address.($login===null?'/manager/api.php':'/manager/fixture-session.php?id='.$login),false,$context);$response=$http_response_header??[];
        preg_match('/^HTTP\/[^ ]+ ([0-9]+)/',(string)($response[0]??''),$status);$session='';foreach($response as $h)if(preg_match('/^Set-Cookie: (anytour_manager_panel=[^;]+)/i',$h,$m))$session=$m[1];
        return ['status'=>(int)($status[1]??0),'body'=>json_decode((string)$body,true),'cookie'=>$session];
    };
    $base=['conversation_id'=>16,'csrf'=>'synthetic-lifecycle-csrf'];
    $authBefore=lifecycleFixtureSnapshot($httpDb);
    foreach(['take','release','close','reopen','lifecycle_state'] as $action)lifecycleCheck('HTTP '.$action.' anonymous401',$request(['action'=>$action]+$base)['status']===401);
    $cookie=$request([],'',1)['cookie'];
    foreach(['take','release','close','reopen','lifecycle_state'] as $action)lifecycleCheck('HTTP '.$action.' CSRF403',$request(['action'=>$action,'conversation_id'=>16],$cookie)['status']===403);
    $inactive=$request([],'',6)['cookie'];lifecycleCheck('HTTP inactive session401 precedes ordinary action',$request(['action'=>'close']+$base,$inactive)['status']===401);
    lifecycleCheck('HTTP authentication and CSRF failures have no lifecycle or operational writes',lifecycleFixtureSnapshot($httpDb)===$authBefore);
    $before=lifecycleFixtureSnapshot($httpDb);$read=$request(['action'=>'lifecycle_state']+$base,$cookie);
    lifecycleCheck('HTTP explicit current-state check is bounded and completely write-free',$read['status']===200&&$read['body']===['ok'=>true,'conversation_state'=>['id'=>16,'status'=>'manager','manager_id'=>1]]&&lifecycleFixtureSnapshot($httpDb)===$before);
    foreach([999,17,18] as $id){$r=$request(['action'=>'lifecycle_state','conversation_id'=>$id,'csrf'=>$base['csrf']],$cookie);lifecycleCheck('HTTP unavailable state404 keeps privacy '.$id,$r['status']===404&&$r['body']['error']==='unavailable'&&!isset($r['body']['conversation_state']));}
    foreach([0,-1,true,1.5,[],'16extra'] as $id){$r=$request(['action'=>'lifecycle_state','conversation_id'=>$id,'csrf'=>$base['csrf']],$cookie);lifecycleCheck('HTTP malformed state identity cannot select a different conversation '.json_encode($id),$r['status']===404&&$r['body']['error']==='unavailable');}
    $refusalBefore=lifecycleFixtureSnapshot($httpDb);
    foreach([['take',12,'owned_elsewhere'],['release',12,'not_owned'],['close',13,'closed'],['reopen',16,'state_changed']] as [$action,$id,$reason]){$r=$request(['action'=>$action,'conversation_id'=>$id,'csrf'=>$base['csrf']],$cookie);lifecycleCheck('HTTP '.$action.' preserves200 and adds exact refusal',$r['status']===200&&$r['body']['ok']===false&&$r['body']['error']===$reason&&is_string($r['body']['error_message'])&&lifecycleFixtureSnapshot($httpDb)===$refusalBefore);}
    $off=$request([],'',4)['cookie'];$r=$request(['action'=>'take','conversation_id'=>11,'csrf'=>$base['csrf']],$off);
    lifecycleCheck('HTTP off-shift take keeps409 and does not write or start shift',$r['status']===409&&$r['body']['error']==='not_working'&&$r['body']['error_message']!==''&&lifecycleFixtureSnapshot($httpDb)===$refusalBefore);
    $r=$request(['action'=>'take','conversation_id'=>11,'manager_id'=>2,'csrf'=>$base['csrf']],$cookie);
    lifecycleCheck('HTTP success ignores injected actor and owns exact requested conversation',$r['status']===200&&$r['body']===['ok'=>true]&&(int)$httpDb->query('SELECT manager_id FROM conversations WHERE id=11')->fetchColumn()===1);
    $r=$request(['action'=>'take','conversation_id'=>11,'csrf'=>$base['csrf']],$cookie);
    lifecycleCheck('HTTP repeated successful take inserts assignment and event once',$r['body']===['ok'=>true]&&(int)$httpDb->query('SELECT COUNT(*) FROM manager_assignments WHERE conversation_id=11')->fetchColumn()===1&&(int)$httpDb->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=11 AND event_type='manager_taken'")->fetchColumn()===1);
    $admin=$request([],'',3)['cookie'];$r=$request(['action'=>'take','conversation_id'=>15,'csrf'=>$base['csrf']],$admin);
    lifecycleCheck('HTTP administrator keeps existing off-shift take exception',$r['status']===200&&$r['body']===['ok'=>true]&&(int)$httpDb->query('SELECT manager_id FROM conversations WHERE id=15')->fetchColumn()===3&&(int)$httpDb->query('SELECT is_working FROM managers WHERE id=3')->fetchColumn()===0);
    $protected=lifecycleFixtureSnapshot($httpDb,['managers','manager_projects','messages','manager_conversation_reads']);
    $eventCount=(int)$httpDb->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn();
    $httpDb->exec("CREATE TRIGGER reject_http_lifecycle_event BEFORE INSERT ON conversation_events BEGIN SELECT RAISE(FAIL,'Synthetic HTTP late event failure'); END");
    $r=$request(['action'=>'release']+$base,$cookie);$httpDb->exec('DROP TRIGGER reject_http_lifecycle_event');
    lifecycleCheck('actual HTTP late event failure is500 UNKNOWN not a confirmed refusal',$r['status']===500&&$r['body']===null&&$httpDb->query('SELECT status FROM conversations WHERE id=16')->fetchColumn()==='ai'&&$httpDb->query('SELECT manager_id FROM conversations WHERE id=16')->fetchColumn()===null&&(int)$httpDb->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn()===$eventCount&&lifecycleFixtureSnapshot($httpDb,['managers','manager_projects','messages','manager_conversation_reads'])===$protected);
    $stateAfterUnknown=$request(['action'=>'lifecycle_state']+$base,$cookie);
    lifecycleCheck('HTTP UNKNOWN current-state read reports only state and never replays mutation',$stateAfterUnknown['status']===200&&$stateAfterUnknown['body']===['ok'=>true,'conversation_state'=>['id'=>16,'status'=>'ai','manager_id'=>null]]&&(int)$httpDb->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn()===$eventCount&&lifecycleFixtureSnapshot($httpDb,['managers','manager_projects','messages','manager_conversation_reads'])===$protected);
}finally{
    if(is_resource($process)){proc_terminate($process);proc_close($process);}$httpDb=null;
    $remove=static function(string $file)use(&$remove):void{if(is_link($file)||is_file($file)){unlink($file);return;}foreach(scandir($file)?:[] as $child)if($child!=='.'&&$child!=='..')$remove($file.'/'.$child);rmdir($file);};$remove($httpRoot);
}
echo 'TOTAL '.$passed.' | PASS '.$passed." | FAIL 0\n";
