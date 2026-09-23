<?php
/** Synthetic native-PDO roundtrip of real service methods. No customer sends. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/support/ManagerDeliveryFixture.php';
$passed=0;$failed=0;$json=in_array('--json',$argv,true);
function receiptCheck(string $name,$actual,$expected):void {
    global $passed,$failed,$json;
    if($actual===$expected){$passed++;if(!$json)echo "PASS $name\n";return;}
    $failed++;fwrite(STDERR,"FAIL $name expected=".json_encode($expected)." actual=".json_encode($actual)."\n");
}
function receiptLast(int $id):array {
    $rows=ManagerConversationService::detail($id,7)['messages'];return $rows?end($rows):[];
}
$project=static fn(array $m,string $c)=>class_exists('ManagerMessageDeliveryService')?ManagerMessageDeliveryService::project($m,$c):null;
$make=static fn(string $c)=>class_exists('ManagerMessageDeliveryService')?ManagerMessageDeliveryService::accepted($c):[];
foreach([11=>'max',12=>'telegram',13=>'website'] as $id=>$channel){
    $before=count(SyntheticAdapter::$calls);
    receiptCheck("$channel accepted text",ManagerOutboundService::send($id,7,'Текст <пример> & ответ'),true);
    receiptCheck("$channel one send",count(SyntheticAdapter::$calls),$before+1);
    receiptCheck("$channel transport unchanged",end(SyntheticAdapter::$calls),['text','synthetic-chat-'.$id,'Текст &lt;пример&gt; &amp; ответ']);
    $pdo=deliveryFixtureReconnect(); // no in-memory receipt cache can supply the result
    $m=receiptLast($id);
    receiptCheck("$channel stored result survives reconnect",$m['delivery']['state']??null,$channel==='website'?'stored':'accepted');
    receiptCheck("$channel reading is unavailable",$m['delivery']['read']??null,'unavailable');
    receiptCheck("$channel original text preserved",$m['text']??null,'Текст <пример> & ответ');
    receiptCheck("$channel no raw metadata",array_key_exists('metadata_json',$m),false);
    $q=$pdo->prepare('SELECT metadata_json FROM messages WHERE id=?');$q->execute([$m['id']]);$metadata=json_decode($q->fetchColumn(),true);$q->closeCursor();
    receiptCheck("$channel marker stored on same row",$metadata['manager_send_receipt']??null,$make($channel));
}
$browser=['max'=>ManagerConversationService::detail(11,7)['messages'],'telegram'=>ManagerConversationService::detail(12,7)['messages'],'website'=>ManagerConversationService::detail(13,7)['messages']];
foreach([11=>'max',12=>'telegram'] as $id=>$channel){
    receiptCheck("$channel media accepted",ManagerOutboundService::sendMedia($id,7,$fixtureFile,'fixture.png','image/png','Подпись','https://media.example.test/fixture.png'),true);
    $pdo=deliveryFixtureReconnect();$m=receiptLast($id);
    receiptCheck("$channel media status persists",$m['delivery']['state']??null,'accepted');
    $hydrated=ManagerMessageMediaService::hydrate([$m]);
    receiptCheck("$channel hydration retains delivery",$hydrated[0]['delivery']??null,$m['delivery']??null);
    receiptCheck("$channel caption preserved",$hydrated[0]['text']??null,'Подпись');
    receiptCheck("$channel file preserved",$hydrated[0]['attachments'][0]['name']??null,'fixture.png');
    if($channel==='max')$browser['media']=$hydrated;
}
foreach(['reject','exception'] as $case){
    SyntheticAdapter::$ok=false;SyntheticAdapter::$throw=$case==='exception';
    foreach(['text','media'] as $type){
        $before=(int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();$calls=count(SyntheticAdapter::$calls);
        try{$ok=$type==='text'?ManagerOutboundService::send(11,7,'Refused'):ManagerOutboundService::sendMedia(11,7,$fixtureFile,'fixture.txt','text/plain');}catch(RuntimeException $e){$ok=false;}
        receiptCheck("$case $type not accepted",$ok,false);
        receiptCheck("$case $type no new receipt/message",(int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn(),$before);
        receiptCheck("$case $type no retry",count(SyntheticAdapter::$calls),$calls+1);
        receiptCheck("$case previous status unaffected",receiptLast(11)['delivery']['state']??null,'accepted');
    }
}
SyntheticAdapter::$ok=true;SyntheticAdapter::$throw=false;
$before=(int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();$calls=count(SyntheticAdapter::$calls);
ManagerSendGuardService::$duplicate=true;
receiptCheck('duplicate returns existing success',ManagerOutboundService::send(11,7,'Duplicate'),true);
receiptCheck('duplicate creates no invented receipt',(int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn(),$before);
receiptCheck('duplicate does not send again',count(SyntheticAdapter::$calls),$calls);
ManagerSendGuardService::$duplicate=false;
receiptCheck('unauthorized detail returns nothing',ManagerConversationService::detail(11,8),null);
receiptCheck('unauthorized send refused',ManagerOutboundService::send(11,8,'Denied'),false);
receiptCheck('unsupported channel refused',ManagerOutboundService::send(14,7,'Denied'),false);
receiptCheck('unsupported website media refused',ManagerOutboundService::sendMedia(13,7,$fixtureFile,'fixture.txt','text/plain'),false);
receiptCheck('refused boundaries have no transport call',count(SyntheticAdapter::$calls),$calls);
receiptCheck('unsupported receipt cannot be generated',$make('other'),[]);
$valid=['id'=>500,'direction'=>'outbound','sender_type'=>'manager','channel'=>'max','metadata_json'=>json_encode(['manager_send_receipt'=>$make('max'),'token'=>'PRIVATE_FIXTURE'])];
receiptCheck('public projection is strictly bounded',array_keys($project($valid,'max')??[]),['state','channel','read']);
foreach(['null','invalid','false','[]','{"manager_send_receipt":"bad"}'] as $bad){$m=$valid;$m['metadata_json']=$bad;receiptCheck('malformed metadata stays unrecorded '.$bad,$project($m,'max')['state']??null,'unrecorded');}
foreach([['version'=>'1'],['version'=>2],['source'=>'client'],['state'=>'read'],['channel'=>'telegram']] as $bad){$m=$valid;$m['metadata_json']=json_encode(['manager_send_receipt'=>array_replace($make('max'),$bad)]);receiptCheck('invalid marker '.json_encode($bad),$project($m,'max')['state']??null,'unrecorded');}
foreach([['direction'=>'inbound'],['sender_type'=>'ai'],['id'=>0]] as $bad)receiptCheck('non-manager-original has no badge '.json_encode($bad),$project(array_replace($valid,$bad),'max'),null);
receiptCheck('cross-channel row is not confirmed',$project(array_replace($valid,['channel'=>'telegram']),'max')['state']??null,'unrecorded');
receiptCheck('cross-channel conversation is not confirmed',$project($valid,'telegram')['state']??null,'unrecorded');
receiptCheck('unsupported conversation has no capability claim',$project($valid,'other'),null);
ConversationRecorder::outboundForConversation(11,'max','Ранее сохранённый ответ','manager','7',['read_at'=>'2026-09-21','client_online'=>true]);
$old=receiptLast(11);receiptCheck('old read/online fields never imply reading',$old['delivery']??null,['state'=>'unrecorded','channel'=>'max','read'=>'unavailable']);
$browser['legacy']=[$old];$pdo=deliveryFixtureReconnect();receiptCheck('history remains unrecorded after detail markRead',receiptLast(11)['delivery']['state']??null,'unrecorded');
receiptCheck('no metadata in browser fixture',strpos(json_encode($browser),'metadata_json')===false,true);
receiptCheck('no private token in public projection',strpos(json_encode($project($valid,'max')),'PRIVATE_FIXTURE')===false,true);
// An existing best-effort mirror failure must not manufacture a persisted badge.
$before=(int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
$pdo->exec("CREATE TRIGGER fail_fixture_mirror BEFORE INSERT ON messages BEGIN SELECT RAISE(FAIL,'Synthetic mirror failure'); END");
receiptCheck('successful transport keeps existing best-effort result on mirror failure',ManagerOutboundService::send(12,7,'Accepted but not mirrored'),true);
receiptCheck('failed mirror has no invented persisted receipt',(int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn(),$before);
$pdo->exec('DROP TRIGGER fail_fixture_mirror');
$pdo->prepare("INSERT INTO conversation_events(conversation_id,event_type,created_at,payload_json) VALUES(11,'manager_message_failed','2026-09-22 00:00:00',?)")->execute([json_encode(['category'=>'suspended'])]);
$calls=count(SyntheticAdapter::$calls);
receiptCheck('suspended send remains blocked',ManagerOutboundService::send(11,7,'Must not send'),false);
receiptCheck('suspended reason retained',ManagerOutboundService::lastFailure()['category']??null,'suspended');
receiptCheck('suspended guard adds no transport call',count(SyntheticAdapter::$calls),$calls);
receiptCheck('suspended guard adds no receipt',(int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn(),$before);
if($failed)exit(1);
if($json)echo json_encode($browser,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
else echo "TOTAL ".($passed+$failed)." | PASS $passed | FAIL $failed\n";
