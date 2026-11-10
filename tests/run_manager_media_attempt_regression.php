<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/manager_send_attempt_fixture.php';
final class MediaAttemptPdo extends PDO
{
    public $onReceiptRead=null;
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        if($this->onReceiptRead&&str_starts_with($query,'SELECT conversation_id,context_hash,request_hash,operation_kind')){$hook=$this->onReceiptRead;$this->onReceiptRead=null;$hook($this);}
        return parent::prepare($query,$options);
    }
}
$pdo=new MediaAttemptPdo('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);sendAttemptBind($pdo);sendAttemptSchema($pdo);
$passed=0;$calls=0;$file=tempnam(sys_get_temp_dir(),'manager-media-attempt-');file_put_contents($file,'Synthetic immutable media bytes');
function mediaAttemptCheck(string $name,bool $ok):void{global$passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
function mediaAttemptId(int $n):string{return str_pad(dechex($n),32,'0',STR_PAD_LEFT);}
function mediaExecute(int $n,string $caption='Private caption',?callable $callback=null,int $actor=7,int $cid=101,string $name='synthetic.txt',string $mime='text/plain'):array
{
    global$file,$calls;$callback??=static function()use(&$calls){$calls++;return['ok'=>true];};
    return ManagerSendAttemptService::executeMedia($actor,$cid,mediaAttemptId($n),$file,$name,$mime,$caption,$callback);
}
function mediaJournal(PDO $pdo):array{return $pdo->query('SELECT * FROM manager_send_attempts ORDER BY manager_id,operation_id')->fetchAll();}
try{
 $protected=sendAttemptProtected($pdo);
 $first=mediaExecute(1,callback:static function(string $context)use($pdo,&$calls){$calls++;$saved=$pdo->query('SELECT * FROM manager_send_attempts')->fetch();mediaAttemptCheck('reservation is committed with typed descriptor before any provider work',!$pdo->inTransaction()&&$saved['state']==='processing'&&$saved['operation_kind']==='media'&&json_decode($saved['result_json'],true)['caption_included']===true);$c=ManagerConversationService::visibleConversation(101,7);mediaAttemptCheck('dispatch binds the actual authorized row and actor role',ManagerSendAttemptService::dispatchAllowed($c,7,$context,'media'));return['ok'=>true];});
 mediaAttemptCheck('confirmed result includes bounded immutable media and caption facts',$first===['ok'=>true,'attempt'=>['kind'=>'media','state'=>'confirmed','media_type'=>'file','caption_included'=>true]]);
 mediaAttemptCheck('duplicate POST cannot call the provider twice',mediaExecute(1)===$first&&$calls===1);
 foreach([['Different caption','synthetic.txt','text/plain'],['Private caption','renamed.txt','text/plain'],['Private caption','synthetic.txt','image/png']]as[$caption,$name,$mime])mediaAttemptCheck('caption name and MIME are immutable within an operation',mediaExecute(1,$caption,name:$name,mime:$mime)['error']==='send_attempt_mismatch'&&$calls===1);
 file_put_contents($file,'Different file bytes, same client filename');mediaAttemptCheck('server file bytes prevent a different file reusing the operation',mediaExecute(1)['error']==='send_attempt_mismatch'&&$calls===1);file_put_contents($file,'Synthetic immutable media bytes');
 $journal=mediaJournal($pdo);$lookup=ManagerSendAttemptService::mediaReceipt(7,101,mediaAttemptId(1));mediaAttemptCheck('lost response is recovered read-only from the durable result',$lookup===$first&&mediaJournal($pdo)===$journal&&sendAttemptProtected($pdo)===$protected&&$calls===1);
 mediaAttemptCheck('text lookup and execution cannot reinterpret a media receipt',ManagerSendAttemptService::receipt(7,101,mediaAttemptId(1))['error']==='send_attempt_mismatch'&&ManagerSendAttemptService::execute(7,101,mediaAttemptId(1),'Text',null,static fn()=>['ok'=>true])['error']==='send_attempt_mismatch');
 ManagerSendAttemptService::execute(7,101,mediaAttemptId(2),'Legacy text',null,static fn()=>['ok'=>true]);mediaAttemptCheck('media lookup cannot reinterpret installed text receipts',ManagerSendAttemptService::mediaReceipt(7,101,mediaAttemptId(2))['error']==='send_attempt_mismatch'&&mediaExecute(2)['error']==='send_attempt_mismatch');
 mediaAttemptCheck('absence is an explicit not_found without delivery or cancellation proof',ManagerSendAttemptService::mediaReceipt(7,101,mediaAttemptId(3))===['ok'=>true,'attempt'=>['kind'=>'media','state'=>'not_found']]);
 mediaAttemptCheck('explicit cancellation acknowledges a typed tombstone without delivery success',ManagerSendAttemptService::mediaCancel(7,101,mediaAttemptId(3))===['ok'=>true,'attempt'=>['kind'=>'media','state'=>'cancelled']]);
 mediaAttemptCheck('a delayed original cannot dispatch after cancellation',mediaExecute(3)['attempt']['state']==='cancelled'&&$calls===1);
 mediaAttemptCheck('known confirmed result cannot be cancelled',ManagerSendAttemptService::mediaCancel(7,101,mediaAttemptId(1))['attempt']['state']==='confirmed');
 $unknown=mediaExecute(4,callback:static function()use(&$calls){$calls++;throw new RuntimeException('Private provider error');});mediaAttemptCheck('provider exception remains unknown without leaking its payload',$unknown['attempt']['state']==='unknown'&&!str_contains(json_encode($unknown),'Private'));
 mediaAttemptCheck('UNKNOWN lookup cancellation and duplicate never dispatch again',ManagerSendAttemptService::mediaReceipt(7,101,mediaAttemptId(4))['attempt']['state']==='unknown'&&ManagerSendAttemptService::mediaCancel(7,101,mediaAttemptId(4))['attempt']['state']==='unknown'&&mediaExecute(4)['attempt']['state']==='unknown'&&$calls===2);
 foreach([408,409,429,500,503]as$code){$result=mediaExecute(10+$code,callback:static fn()=>['ok'=>false,'failure'=>['category'=>'temporary','http_code'=>$code,'message'=>'Private provider payload']]);mediaAttemptCheck('ambiguous HTTP '.$code.' cannot authorize retry',$result['attempt']['state']==='unknown'&&!isset($result['failure']));}
 foreach([['not_dispatched',0,'file_unavailable'],['not_dispatched',0,'context_changed'],['unsupported',0,''],['suspended',403,''],['connection_unavailable',0,''],['blocked',403,'']]as$i=>[$category,$code,$reason]){$result=mediaExecute(600+$i,callback:static fn()=>['ok'=>false,'failure'=>['category'=>$category,'http_code'=>$code,'reason'=>$reason,'message'=>'Private provider payload']]);mediaAttemptCheck('definite '.$category.'/'.$reason.' result is retained without provider detail',$result['attempt']['state']==='rejected'&&!str_contains(json_encode($result),'Private'));}
 $role=mediaExecute(700,callback:static function(string $context)use($pdo){$pdo->exec("UPDATE managers SET role='admin' WHERE id=7");$c=ManagerConversationService::visibleConversation(101,7);return ['ok'=>ManagerSendAttemptService::dispatchAllowed($c,7,$context,'media'),'failure'=>['category'=>'not_dispatched','reason'=>'context_changed','http_code'=>0]];});mediaAttemptCheck('role change after reservation prevents actual dispatch',$role['attempt']['state']==='rejected');$pdo->exec("UPDATE managers SET role='manager' WHERE id=7");
 $pdo->exec("CREATE TRIGGER reject_media_finish BEFORE UPDATE ON manager_send_attempts WHEN NEW.operation_id='".mediaAttemptId(701)."' BEGIN SELECT RAISE(FAIL,'Synthetic receipt failure'); END");$before=$calls;
 mediaAttemptCheck('lost terminal persistence keeps the reserved operation fenced',mediaExecute(701)['error']==='send_attempt_unavailable'&&$calls===$before+1&&ManagerSendAttemptService::mediaReceipt(7,101,mediaAttemptId(701))['attempt']['state']==='processing'&&mediaExecute(701)['attempt']['state']==='processing'&&$calls===$before+1);$pdo->exec('DROP TRIGGER reject_media_finish');
 $reserved=mediaJournal($pdo);$pendingCancel=ManagerSendAttemptService::mediaCancel(7,101,mediaAttemptId(701));
 mediaAttemptCheck('cancellation acknowledges an already processing result without changing or replaying it',$pendingCancel['ok']===true&&$pendingCancel['attempt']['kind']==='media'&&$pendingCancel['attempt']['state']==='processing'&&mediaJournal($pdo)===$reserved&&$calls===$before+1);
 foreach([[8,101],[9,101],[7,104],[7,105],[10,101]]as[$actor,$cid])mediaAttemptCheck('mutation uses fresh actual ownership project source and active account',mediaExecute(800+$actor+$cid,actor:$actor,cid:$cid)['error']==='forbidden');
 $pdo->exec("UPDATE conversations SET manager_id=8,status='closed' WHERE id=101");mediaAttemptCheck('same actor can read a historical result after closure and reassignment without sending',ManagerSendAttemptService::mediaReceipt(7,101,mediaAttemptId(1))===$first&&mediaExecute(1)['error']==='forbidden');$pdo->exec("UPDATE conversations SET manager_id=7,status='manager' WHERE id=101");
 $pdo->exec("UPDATE conversations SET external_chat_id='Another recipient' WHERE id=101");mediaAttemptCheck('receipt never retargets a historical operation to another recipient',ManagerSendAttemptService::mediaReceipt(7,101,mediaAttemptId(1))['error']==='send_attempt_mismatch');$pdo->exec("UPDATE conversations SET external_chat_id='synthetic101' WHERE id=101");
 $pdo->onReceiptRead=static fn(PDO $p)=>$p->exec('DELETE FROM manager_projects WHERE manager_id=7');mediaAttemptCheck('revocation during receipt read is caught by the final fresh authorization fence',ManagerSendAttemptService::mediaReceipt(7,101,mediaAttemptId(1))['error']==='forbidden');$pdo->exec('INSERT INTO manager_projects VALUES(7,1)');
 mediaAttemptCheck('journal contains neither file bytes filename nor caption',!str_contains(json_encode(mediaJournal($pdo)),'Private caption')&&!str_contains(json_encode(mediaJournal($pdo)),'Synthetic immutable media bytes')&&!str_contains(json_encode(mediaJournal($pdo)),'synthetic.txt'));
 mediaAttemptCheck('all protected business state remains unchanged',sendAttemptProtected($pdo)===$protected);
}finally{if(is_file($file))unlink($file);}
echo 'TOTAL '.$passed.' | PASS '.$passed." | FAIL 0\n";
