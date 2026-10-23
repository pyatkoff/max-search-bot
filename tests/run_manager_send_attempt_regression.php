<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/manager_send_attempt_fixture.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);sendAttemptBind($pdo);sendAttemptSchema($pdo);
$passed=0;$calls=0;
function checkSend(string $label,bool $ok): void {global$passed;if(!$ok)throw new RuntimeException('FAIL '.$label);$passed++;echo 'PASS '.$label."\n";}
function idSend(int $n): string {return str_pad(dechex($n),32,'0',STR_PAD_LEFT);}
function execSend(int $n,string $text='Synthetic private body',?string $ai=null,int $actor=7,int $cid=101,?callable $callback=null): array
{
    global$calls;$callback??=static function()use(&$calls){$calls++;return['ok'=>true];};return ManagerSendAttemptService::execute($actor,$cid,idSend($n),$text,$ai,$callback);
}
$protected=sendAttemptProtected($pdo);
checkSend('first canonical callback executes after committed reservation',execSend(1,callback:static function()use(&$calls,$pdo){$calls++;checkSend('no DB lock across transport and processing visible',$pdo->inTransaction()===false&&$pdo->query('SELECT state FROM manager_send_attempts')->fetchColumn()==='processing');return['ok'=>true];})['attempt']['state']==='confirmed');
$pdo->exec("UPDATE manager_send_attempts SET created_at='2020-01-01 00:00:00'");
checkSend('repeat after old dedup window never invokes callback',execSend(1)['attempt']['state']==='confirmed'&&$calls===1);
checkSend('body and AI provenance cannot reuse another request',execSend(1,'Changed')['error']==='send_attempt_mismatch'&&execSend(1,ai:idSend(42))['error']==='send_attempt_mismatch'&&$calls===1);
checkSend('same account cannot reuse operation against another conversation',execSend(1,cid:102)['error']==='send_attempt_mismatch');
$before=$pdo->query('SELECT * FROM manager_send_attempts')->fetchAll();$read=ManagerSendAttemptService::receipt(7,101,idSend(1));
checkSend('receipt read is write free and reports confirmed without another transport',$read===['ok'=>true,'attempt'=>['state'=>'confirmed']]&&$before===$pdo->query('SELECT * FROM manager_send_attempts')->fetchAll()&&$calls===1);
foreach([[8,101],[9,101],[7,104],[7,105],[10,101],[0,101]] as [$actor,$cid])checkSend('fresh actual owner project source and active account fence',execSend(2,actor:$actor,cid:$cid)['error']==='forbidden'&&ManagerSendAttemptService::receipt($actor,$cid,idSend(1))['error']==='forbidden');
checkSend('receipt lookup is account scoped',ManagerSendAttemptService::receipt(8,103,idSend(1))===['ok'=>true,'attempt'=>['state'=>'not_found']]);
$pdo->exec("UPDATE conversations SET external_chat_id='changed-destination' WHERE id=101");checkSend('recipient context change cannot reuse receipt',execSend(1)['error']==='send_attempt_mismatch');$pdo->exec("UPDATE conversations SET external_chat_id='synthetic101' WHERE id=101");
$pdo->exec('UPDATE conversations SET manager_id=8 WHERE id=101');checkSend('reassignment blocks even successful old readback',ManagerSendAttemptService::receipt(7,101,idSend(1))['error']==='forbidden');$pdo->exec('UPDATE conversations SET manager_id=7 WHERE id=101');
checkSend('missing receipt is not success',ManagerSendAttemptService::receipt(7,101,idSend(3))['attempt']['state']==='not_found');
checkSend('explicit cancellation writes a tombstone only before reservation',ManagerSendAttemptService::cancel(7,101,idSend(3))['attempt']['state']==='cancelled');
checkSend('late original cannot execute after cancellation',execSend(3)['attempt']['state']==='cancelled'&&$calls===1);
checkSend('cancellation cannot reverse a confirmed receipt',ManagerSendAttemptService::cancel(7,101,idSend(1))['attempt']['state']==='confirmed');
$pending=execSend(4,callback:static function(){throw new RuntimeException('synthetic secret payload');});
checkSend('exception remains unknown and cannot be replayed cancelled or leaked',$pending['attempt']['state']==='unknown'&&execSend(4)['attempt']['state']==='unknown'&&ManagerSendAttemptService::cancel(7,101,idSend(4))['attempt']['state']==='unknown'&&$calls===1);
foreach([['unknown',0,'unknown'],['temporary',503,'unknown'],['temporary',429,'unknown'],['unknown',408,'unknown'],['blocked',403,'rejected'],['suspended',0,'rejected'],['unsupported',0,'rejected'],['unavailable',404,'rejected']] as [$category,$code,$state]){
 static $n=10;$result=execSend(++$n,callback:static fn()=>['ok'=>false,'failure'=>['category'=>$category,'http_code'=>$code,'message'=>'private provider payload']]);checkSend('conservative provider result '.$category.'/'.$code,$result['attempt']['state']===$state&&!str_contains(json_encode($result),'private'));
}
$pdo->exec("CREATE TRIGGER reject_send_finish BEFORE UPDATE ON manager_send_attempts WHEN NEW.operation_id='".idSend(30)."' BEGIN SELECT RAISE(FAIL,'synthetic finish failure'); END");
checkSend('terminal receipt persistence failure returns unknown after one callback',execSend(30)['error']==='send_attempt_unavailable'&&$calls===2);
checkSend('processing receipt after persistence failure never repeats or cancels',execSend(30)['attempt']['state']==='processing'&&ManagerSendAttemptService::cancel(7,101,idSend(30))['attempt']['state']==='processing'&&$calls===2);
$pdo->exec('DROP TRIGGER reject_send_finish');
$pdo->exec("CREATE TRIGGER reject_send_reserve BEFORE INSERT ON manager_send_attempts BEGIN SELECT RAISE(FAIL,'synthetic reservation failure'); END");
checkSend('failed reservation never fails open into provider',execSend(31)['error']==='send_attempt_unavailable'&&$calls===2);$pdo->exec('DROP TRIGGER reject_send_reserve');
checkSend('receipt journal contains no raw body or exception',!str_contains(json_encode($pdo->query('SELECT * FROM manager_send_attempts')->fetchAll()),'Synthetic private body')&&!str_contains(json_encode($pdo->query('SELECT * FROM manager_send_attempts')->fetchAll()),'secret'));
checkSend('all protected business state unchanged',sendAttemptProtected($pdo)===$protected);
$api=(string)file_get_contents(dirname(__DIR__).'/manager/api.php');checkSend('actions stay behind existing auth and CSRF',strpos($api,'ManagerHttp::requireManager()')<strpos($api,"if(\$action==='send_receipt'")&&strpos($api,'ManagerHttp::requireCsrf($data)')<strpos($api,"if(\$action==='send_receipt'"));
echo 'TOTAL '.$passed.' | PASS '.$passed." | FAIL 0\n";
