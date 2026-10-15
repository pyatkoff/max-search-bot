<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/max_channel_attribution.php';
require_once dirname(__DIR__).'/services/MaxChannelConversionWorker.php';
require_once dirname(__DIR__).'/services/MetrikaConversionGoalService.php';
$checks=0;
function ok(bool $value,string $name):void{global $checks;$checks++;if(!$value)throw new RuntimeException('FAIL '.$name);}
function same($actual,$expected,string $name):void{ok($actual===$expected,$name.' ('.json_encode($actual).')');}
function rejects(callable $f,string $name):void{try{$f();}catch(Throwable $e){ok(true,$name);return;}ok(false,$name);}
function row(PDO $p,int $id=1):array{return $p->query('SELECT * FROM max_channel_conversion_outbox WHERE id='.$id)->fetch();}
foreach(['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS'] as $name)define($name,'fixture');
class MaxSearchApi{
    public static bool $allowed=true;
    public static function metrikaGoalAllowed($chat):bool{return self::$allowed;}
    public static function queueMetrikaGoal($chat,$goal,?array $context=null):bool{return MaxChannelConversionOutbox::queueIfEligible($chat,$goal,$context)??false;}
}
$p=maxAttributionFixture();ProjectConfig::resetForTests(['id'=>'anytour']);
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$p);
$s=MaxChannelConversionOutbox::settings($p);$repo=new MaxChannelConversionOutbox($p);$now=strtotime('2026-10-15 11:00:00 UTC');
same($s['counter_id'],'98615635','counter string');
$c=$repo->conversation('1','-1234567890123456789',$s);ok($c!==null,'exact MAX user and post');
foreach([2,3,4,5,6] as $id)same($repo->conversation((string)$id,'-1234567890123456789',$s),null,'reject scope '.$id);
same($repo->conversation('1 OR 1','-1234567890123456789',$s),null,'reject unsafe identity');
ok($repo->conversation('7','-1234567890123456789',$s)!==null,'unique manager-source link without post');
same($repo->event($c,'max_bot_activity_start',$s)['id'],2,'first callback after activation');
same($repo->event($c,'max_manager_reply',$s)['id'],5,'first real manager reply');
same($repo->event($c,'max_customer_reply_after_manager',$s)['id'],7,'same-second ordering and callback exclusion');
$p->exec("INSERT INTO conversation_events(conversation_id,event_type) VALUES(1,'waiting_manager'),(1,'manager_message')");
MetrikaConversionGoalService::reconcileChannelConversation(1);
same((int)$p->query('SELECT COUNT(*) FROM max_channel_conversion_outbox')->fetchColumn(),3,'canonical reconciler captures three stages');
MetrikaConversionGoalService::reconcileChannelConversation(1);
same((int)$p->query('SELECT COUNT(*) FROM max_channel_conversion_outbox')->fetchColumn(),3,'reentry deduplicates');
same((int)row($p)['event_unix'],strtotime('2026-10-15 10:00:01 UTC'),'occurrence time frozen');
same(row($p)['state'],'pending','no identity still durable');
same(MaxChannelConversionOutbox::queueIfEligible('-1234567890123456789','max_sale',['conversation_id'=>'1']),null,'CRM delegates unchanged');
same(MaxChannelConversionOutbox::queueIfEligible('-1234567890123456789','max_manager_reply',null),null,'old two-argument path');
$p->exec("UPDATE max_channel_attribution_settings SET mode='off'");
same(MaxChannelConversionOutbox::queueIfEligible('-1234567890123456789','max_manager_reply',['conversation_id'=>'1']),null,'off delegates old path');
$noIO=new class{public function __call($name,$args){throw new RuntimeException('unexpected_io');}};
same(MaxChannelConversionWorker::tick($p,$noIO,$noIO,$now)['mode'],'off','off performs no transport');
$p->exec("UPDATE max_channel_attribution_settings SET mode='collect'");
$repo->resolve('1',null,$now);same(count($repo->pending($now)),2,'missing identity delayed');
$identity=['external_user_id'=>'1234567890123456789','client_id'=>'1844674407370955161512345','record_id'=>'9007199254740993','observed_at_unix'=>$now+100];
$lookup=new class($identity){public array $identity;public int $calls=0;function __construct($v){$this->identity=$v;}function lookup($counter,$users){$this->calls++;return ['1234567890123456789'=>$this->identity];}};
$stats=MaxChannelConversionWorker::tick($p,$lookup,$noIO,$now+901);same($stats['resolved'],3,'later tracker identity enriches earlier events');
same(row($p)['client_id'],$identity['client_id'],'large ClientID exact decimal string');
same((int)row($p)['event_unix'],strtotime('2026-10-15 10:00:01 UTC'),'enrichment preserves event time');
$changed=$identity;$changed['client_id']='999';$repo->resolve('1',$changed,$now+1000);same(row($p)['client_id'],$identity['client_id'],'frozen after ready');
$token=$repo->claim('1',$now+1000);ok(is_string($token),'first claim');same($repo->claim('1',$now+1000),null,'concurrent claim loses');
rejects(fn()=>$repo->finish('1',str_repeat('0',32),['outcome'=>'accepted','upload_id'=>'444'],$now+1000),'wrong receipt token');
$repo->finish('1',$token,['outcome'=>'unknown'],$now+1000);same(row($p)['state'],'unknown','ambiguous transport held');
same($repo->claim('1',$now+3000),null,'unknown never reposted');
$token=$repo->claim('2',$now+1000);$repo->recover($now+1301);same(row($p,2)['state'],'unknown','crash after claim held');
$token=$repo->claim('3',$now+1000);$repo->finish('3',$token,['outcome'=>'retry','reason'=>'rate_limited'],$now+1000);same(row($p,3)['state'],'ready','explicit rate limit retry');
same($repo->claim('3',$now+1001),null,'retry backoff');
$repo->status(row($p),['id'=>'444','status'=>'PREPARED','source_quantity'=>0,'line_quantity'=>0],$now+2000);same(row($p)['state'],'accepted','prepared zero counts not final failure');
$repo->status(row($p),['id'=>'444','status'=>'PROCESSED','source_quantity'=>1,'line_quantity'=>0],$now+2000);same(row($p)['state'],'failed','terminal validation failure');
$repo->status(row($p,2),['id'=>'445','status'=>'PROCESSED','source_quantity'=>1,'line_quantity'=>1],$now+2000);same(row($p,2)['state'],'processed','validated upload processed');
$event=row($p,3);$csv=MetrikaOfflineClient::csv($event,$now+901);
ok(str_starts_with($csv,"ClientId,Target,DateTime\n"),'documented CSV header');ok(str_contains($csv,$identity['client_id'].','),'CID not float');ok(str_contains($csv,','.$event['event_unix']."\n"),'original Unix time');
rejects(fn()=>MetrikaOfflineClient::csv($event,$now+22*86400),'expired event blocked');
$bad=$event;$bad['target']='anything';rejects(fn()=>MetrikaOfflineClient::csv($bad,$now+901),'unknown goal blocked');
$bad=$event;$bad['counter_id']='2';$client=new MetrikaOfflineClient('98615635','fake',fn()=>throw new RuntimeException('unexpected_io'));rejects(fn()=>$client->upload($bad,$now+901),'counter mismatch before network');
foreach([
    [['error'=>6,'http'=>0],'retry'],[['error'=>28,'http'=>0],'unknown'],[['error'=>0,'http'=>420],'retry'],[['error'=>0,'http'=>403],'rejected'],[['error'=>0,'http'=>500],'unknown'],[['error'=>0,'http'=>200,'body'=>'invalid'],'unknown'],
    [['error'=>0,'http'=>200,'body'=>json_encode(['uploading'=>['id'=>123,'client_id_type'=>'CLIENT_ID','status'=>'PREPARED']])],'accepted'],
    [['error'=>0,'http'=>200,'body'=>json_encode(['uploading'=>['id'=>123,'client_id_type'=>'Yclid']])],'unknown'],
] as [$response,$expected]){
    $client=new MetrikaOfflineClient('98615635','fake',function($method,$url,$data)use($response,$csv){same($method,'POST','upload method');ok(str_contains($url,'type=BASIC&comment=MaxChannel'),'deterministic recovery comment');same($data,$csv,'wire CSV');return $response;});
    same($client->upload($event,$now+901)['outcome'],$expected,'upload outcome '.$expected);
}
$gets=0;$client=new MetrikaOfflineClient('98615635','fake',function($method,$url,$csv)use(&$gets,$event){$gets++;same($method,'GET','recovery GET only');same($csv,null,'no recovery upload');return ['http'=>200,'body'=>json_encode(['uploadings'=>[['id'=>909,'comment'=>MetrikaOfflineClient::comment($event),'client_id_type'=>'CLIENT_ID','status'=>'PROCESSED','source_quantity'=>1,'line_quantity'=>1]]])];});
same($client->findUnknown($event)['id'],909,'lost acknowledgement recovered');same($gets,1,'bounded lookup');
$client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['uploadings'=>array_map(fn($id)=>['id'=>$id,'comment'=>MetrikaOfflineClient::comment($event),'client_id_type'=>'CLIENT_ID','status'=>'UPLOADED'],[909,910])])]);same($client->findUnknown($event),null,'ambiguous recovery remains held');
// Live Management API receipts omit client_id_type even for our ClientId CSV.
// Validate that omission on all three receipt paths without weakening identity,
// upload-id, status or exact-comment checks.
$receipt=['id'=>909,'type'=>'BASIC','status'=>'UPLOADED','source_quantity'=>1,'line_quantity'=>1];
foreach([
    ['omitted identity field',$receipt,true],
    ['explicit ClientID',$receipt+['client_id_type'=>'CLIENT_ID'],true],
    ['legacy receipt without type',array_diff_key($receipt,['type'=>true]),true],
    ['null identity',$receipt+['client_id_type'=>null],false],
    ['empty identity',$receipt+['client_id_type'=>''],false],
    ['other identity',$receipt+['client_id_type'=>'YCLID'],false],
    ['null upload type',array_replace($receipt,['type'=>null]),false],
    ['empty upload type',array_replace($receipt,['type'=>'']),false],
    ['other upload type',array_replace($receipt,['type'=>'CALLS']),false],
    ['missing id',array_diff_key($receipt,['id'=>true]),false],
    ['zero id',array_replace($receipt,['id'=>0]),false],
    ['negative id',array_replace($receipt,['id'=>-909]),false],
    ['invalid id',array_replace($receipt,['id'=>'909suffix']),false],
    ['boolean id',array_replace($receipt,['id'=>true]),false],
    ['floating id',array_replace($receipt,['id'=>909.5]),false],
    ['missing status',array_diff_key($receipt,['status'=>true]),false],
    ['null status',array_replace($receipt,['status'=>null]),false],
    ['unknown status',array_replace($receipt,['status'=>'NOT_A_STATUS']),false],
] as [$label,$upload,$valid]){
    $client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['uploading'=>$upload])]);
    same($client->upload($event,$now+901)['outcome'],$valid?'accepted':'unknown','POST receipt '.$label);
    same($client->poll('909')!==null,$valid,'poll receipt '.$label);
    $upload['comment']=MetrikaOfflineClient::comment($event);
    $client=new MetrikaOfflineClient('98615635','fake',function($method,$url,$body)use($upload){same($method,'GET','receipt recovery never posts');same($body,null,'receipt recovery has no CSV');return ['http'=>200,'body'=>json_encode(['uploadings'=>[$upload]])];});
    same($client->findUnknown($event)!==null,$valid,'recovery receipt '.$label);
}
foreach(['PREPARED','PROCESSED'] as $status){
    $upload=array_replace($receipt,['status'=>$status]);
    $client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['uploading'=>$upload])]);
    same($client->poll('909')['status']??null,$status,'valid poll lifecycle status '.$status);
}
foreach([MetrikaOfflineClient::comment($event),MetrikaOfflineClient::comment($event).'other',null] as $comment){
    $upload=$receipt+['comment'=>$comment];
    $client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['uploading'=>$upload])]);
    same($client->upload($event,$now+901)['outcome'],$comment===MetrikaOfflineClient::comment($event)?'accepted':'unknown','POST receipt comment must agree when present');
}
$client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['uploading'=>array_replace($receipt,['id'=>910])])]);
same($client->poll('909'),null,'poll cannot attach another upload id');
$wrongComment=$receipt+['comment'=>MetrikaOfflineClient::comment($event).'other'];
$client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['uploadings'=>[$wrongComment]])]);
same($client->findUnknown($event),null,'similar recovery comment never matches');
$duplicateComment=array_map(fn($id)=>array_replace($receipt,['id'=>$id,'comment'=>MetrikaOfflineClient::comment($event)]),[909,910]);
$client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['uploadings'=>$duplicateComment])]);
same($client->findUnknown($event),null,'two valid receipts without identity field remain ambiguous');
$goals=array_map(fn($goal)=>['type'=>'action','conditions'=>[['type'=>'exact','url'=>$goal['target']]]],array_values(MetrikaOfflineClient::CHANNEL_GOALS));
foreach($goals as $index=>&$g)$g['id']=$index+10;unset($g);
$client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['goals'=>$goals])]);same($client->goalsReady(),true,'existing exact goals');
$goals[0]['conditions'][0]['type']='contain';$client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['goals'=>$goals])]);same($client->goalsReady(),false,'similar goal insufficient');
// Existing shared goals cannot satisfy channel-only readiness.
$legacy=array_map(fn($target)=>['type'=>'action','conditions'=>[['type'=>'exact','url'=>$target]]],MaxChannelConversionOutbox::TARGETS);
$client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['goals'=>$legacy])]);same($client->goalsReady(),false,'legacy goals stay separate');
foreach(MetrikaOfflineClient::CHANNEL_GOALS as $stage=>$definition){$e=$event;$e['target']=$stage;$lines=array_map('str_getcsv',explode("\n",trim(MetrikaOfflineClient::csv($e,$now+901))));same($lines[1][1],$definition['target'],'dedicated channel CSV target');}
// Explicit provisioning creates only the three allowlisted exact goals.
$shared=[['id'=>1,'name'=>'Existing shared activity','type'=>'action','conditions'=>[['type'=>'contain','url'=>'max_bot_activity_start']]]];
$live=$shared;$writes=[];
$transport=function($method,$url,$body)use(&$live,&$writes){
    if($method==='GET')return ['http'=>200,'body'=>json_encode(['goals'=>$live])];
    same($method,'POST','provision never edits or deletes');ok(str_ends_with($url,'/goals'),'only goals endpoint');
    $g=json_decode($body,true)['goal'];same($g['type'],'action','JS goal');same(count($g['conditions']),1,'one exact condition');same($g['conditions'][0]['type'],'exact','exact required');
    ok(in_array($g['conditions'][0]['url'],array_column(MetrikaOfflineClient::CHANNEL_GOALS,'target'),true),'allowlisted new target');
    $g['id']=100+count($writes);$writes[]=$g;$live[]=$g;return ['http'=>200,'body'=>json_encode(['goal'=>$g])];
};
$client=new MetrikaOfflineClient('98615635','fake',$transport);
same(count($client->provisionChannelGoals()['goals']),3,'read-only goal plan');same(count($writes),0,'plan never writes');
$result=$client->provisionChannelGoals(true);same(count($writes),3,'creates three missing goals');same($result['existing_goals_checked'],1,'prior semantics checked');same($live[0],$shared[0],'old contain goal unchanged');
$client->provisionChannelGoals(true);same(count($writes),3,'repeat provisioning reuses exact goals');same($client->goalsReady(),true,'created goals ready');
$conflict=$shared;$conflict[]=['id'=>2,'name'=>'Conflict','type'=>'action','conditions'=>[['type'=>'contain','url'=>'max_channel_activity_start']]];
$client=new MetrikaOfflineClient('98615635','fake',function($m)use($conflict){same($m,'GET','conflict never mutates');return ['http'=>200,'body'=>json_encode(['goals'=>$conflict])];});rejects(fn()=>$client->provisionChannelGoals(true),'conflicting new target requires operator review');
$client=new MetrikaOfflineClient('2','fake',fn()=>throw new RuntimeException('unexpected_io'));rejects(fn()=>$client->provisionChannelGoals(true),'provision counter pinned before IO');
$client=new MetrikaOfflineClient('98615635','fake',fn($m)=>$m==='GET'?['http'=>200,'body'=>json_encode(['goals'=>$shared])]:['error'=>28,'http'=>0]);rejects(fn()=>$client->provisionChannelGoals(true),'unknown creation stops without resend');
$exact=[];foreach(MetrikaOfflineClient::CHANNEL_GOALS as $i=>$d)$exact[]=['id'=>200+count($exact),'name'=>$d['name'],'type'=>'action','conditions'=>[['type'=>'exact','url'=>$d['target']]]];
$multi=$exact;$multi[0]['conditions'][]=['type'=>'exact','url'=>'other_event'];
$client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['goals'=>$multi])]);same($client->goalsReady(),false,'additional goal matches cannot pass readiness');
$duplicates=$exact;$copy=$exact[0];$copy['id']=999;$duplicates[]=$copy;
$client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['goals'=>$duplicates])]);same($client->goalsReady(),false,'duplicate exact goals cannot pass readiness');
$lateConflict=$shared;$defs=array_values(MetrikaOfflineClient::CHANNEL_GOALS);$lateConflict[]=['id'=>9,'name'=>$defs[2]['name'],'type'=>'action','conditions'=>[['type'=>'contain','url'=>'other_value']]];
$client=new MetrikaOfflineClient('98615635','fake',function($m)use($lateConflict){same($m,'GET','all conflicts checked before first POST');return ['http'=>200,'body'=>json_encode(['goals'=>$lateConflict])];});rejects(fn()=>$client->provisionChannelGoals(true),'later conflicting name creates no partial goals');
$readCount=0;$writeCount=0;
$client=new MetrikaOfflineClient('98615635','fake',function($m,$url,$body)use(&$readCount,&$writeCount,$shared,$exact){
 if($m==='GET'){$readCount++;$goals=$shared;if($readCount>1){$goals[0]['conditions'][0]['type']='exact';$goals=array_merge($goals,$exact);}return ['http'=>200,'body'=>json_encode(['goals'=>$goals])];}
 same($m,'POST','concurrent mutation never rolled back by PUT/DELETE');$g=json_decode($body,true)['goal'];$g['id']=700+$writeCount++;return ['http'=>200,'body'=>json_encode(['goal'=>$g])];
});rejects(fn()=>$client->provisionChannelGoals(true),'concurrent old-goal semantic change blocks activation');same($writeCount,3,'only three allowlisted goals requested');
// Real signed protocol; wrong key, replay time and malformed identities all fail closed.
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);openssl_pkey_export($key,$private);$public=openssl_pkey_get_details($key)['key'];
$body=MaxTrackerLookupProtocol::body('98615635',['1234567890123456789'],$now);$signature=MaxTrackerLookupProtocol::signature($body,$private);
same(MaxTrackerLookupProtocol::authenticate($body,$signature,$public,$now,'98615635')['user_ids'],['1234567890123456789'],'RSA signature exact identity');
rejects(fn()=>MaxTrackerLookupProtocol::authenticate($body.' ',$signature,$public,$now,'98615635'),'body tampering');
rejects(fn()=>MaxTrackerLookupProtocol::authenticate($body,$signature,$public,$now+91,'98615635'),'expired signature');
rejects(fn()=>MaxTrackerLookupProtocol::authenticate($body,$signature,$public,$now,'2'),'wrong signed counter');
foreach([[123],['01'],['-123'],['1','1'],array_map('strval',range(1,26))] as $ids)rejects(fn()=>MaxTrackerLookupProtocol::body('98615635',$ids,$now),'invalid user list');
$response=['ok'=>true,'version'=>1,'counter_id'=>'98615635','tracker_timezone'=>'Europe/London','users'=>[['external_user_id'=>'123','found'=>false],['external_user_id'=>'456','found'=>false]]];
same(MaxTrackerLookupClient::validateResponse($response,'98615635',['123','456']),[123=>null,456=>null],'explicit misses accepted');
$response['users'][1]=$response['users'][0];rejects(fn()=>MaxTrackerLookupClient::validateResponse($response,'98615635',['123','456']),'duplicate miss cannot replace second user');
$response['users'][1]='bad';rejects(fn()=>MaxTrackerLookupClient::validateResponse($response,'98615635',['123','456']),'malformed response row');
// Worker transitions: one POST, then only GETs despite repeated ticks.
$p->exec("UPDATE max_channel_attribution_settings SET mode='send'");
$p->exec("UPDATE max_channel_conversion_outbox SET state='ready',next_attempt_unix=0 WHERE id=3");
$api=new class{public int $posts=0;public int $gets=0;function upload($row,$now){$this->posts++;return ['outcome'=>'accepted','upload_id'=>'990','status'=>'PREPARED'];}function poll($id){$this->gets++;return ['id'=>$id,'status'=>'PROCESSED','source_quantity'=>1,'line_quantity'=>1];}function findUnknown($row){$this->gets++;return null;}};
MaxChannelConversionWorker::tick($p,$lookup,$api,$now+3000);same($api->posts,1,'worker sends once');same(row($p,3)['state'],'accepted','awaits processing');
MaxChannelConversionWorker::tick($p,$lookup,$api,$now+5000);MaxChannelConversionWorker::tick($p,$lookup,$api,$now+8000);same($api->posts,1,'repeat tick never duplicates accepted');same(row($p,3)['state'],'processed','processing confirmed separately');
$p->exec("UPDATE max_channel_conversion_outbox SET state='pending' WHERE id=1");$repo->recover($now+22*86400);same(row($p)['state'],'expired','expired event leaves queue');
// Cursor commits only after successful capture, allowing safe background recovery.
$p->exec('UPDATE max_channel_attribution_settings SET message_cursor=0');
rejects(fn()=>$repo->collect(MaxChannelConversionOutbox::settings($p),fn()=>throw new RuntimeException('capture_failed')),'collector failure');same((int)MaxChannelConversionOutbox::settings($p)['message_cursor'],0,'cursor not lost after failure');
same($repo->collect(MaxChannelConversionOutbox::settings($p),fn()=>null),9,'bounded collector advances');same((int)MaxChannelConversionOutbox::settings($p)['message_cursor'],9,'cursor saved after success');
// A collector can capture an event one second after the tick's clock snapshot.
// Keep that row unclaimed until the next tick, with its real occurrence time.
$p->exec('DELETE FROM max_channel_conversion_outbox');$p->exec('DELETE FROM messages');
$p->exec("UPDATE max_channel_attribution_settings SET mode='send',message_cursor=0");
$clockEvent=$now+1;
$p->prepare("INSERT INTO messages VALUES(1,1,'max','inbound','customer',?,?)")->execute([gmdate('Y-m-d H:i:s',$clockEvent),json_encode(['type'=>'message'])]);
$clockRepo=new MaxChannelConversionOutbox($p);$clockSettings=MaxChannelConversionOutbox::settings($p);
$clockCollector=function(int $id)use($clockRepo,$clockSettings,$clockEvent):void{
    $conversation=$clockRepo->conversation((string)$id,'-1234567890123456789',$clockSettings);
    $clockRepo->capture($conversation,'max_bot_activity_start',$clockSettings,$clockEvent);
};
$clockApi=new class{
    public int $posts=0;
    function upload($row,$now){MetrikaOfflineClient::csv($row,$now);$this->posts++;return ['outcome'=>'accepted','upload_id'=>'991','status'=>'UPLOADED'];}
    function poll($id){throw new RuntimeException('unexpected_poll');}
    function findUnknown($row){throw new RuntimeException('unexpected_recovery');}
};
$result=MaxChannelConversionWorker::tick($p,$lookup,$clockApi,$now,$clockCollector);
$clockRow=$p->query('SELECT * FROM max_channel_conversion_outbox')->fetch();
same($result['collected'],1,'collector sees new event');same($result['resolved'],1,'new event identity resolved');
same($clockRow['state'],'ready','event newer than tick clock remains ready');same((int)$clockRow['attempts'],0,'future event not claimed');
same($clockApi->posts,0,'no premature upload');same($result['unknown'],0,'clock boundary cannot produce unknown');
same((int)$clockRow['event_unix'],$clockEvent,'capture preserves actual event instant');
$result=MaxChannelConversionWorker::tick($p,$lookup,$clockApi,$clockEvent);
$afterClockRow=$p->query('SELECT * FROM max_channel_conversion_outbox')->fetch();
same($afterClockRow['state'],'accepted','next tick uploads event');same($clockApi->posts,1,'one upload after event instant');
same((int)$afterClockRow['attempts'],1,'one claim only');same((int)$afterClockRow['event_unix'],$clockEvent,'upload preserves actual event instant');
same($afterClockRow['client_id'],$clockRow['client_id'],'clock defer preserves frozen ClientID');same($afterClockRow['tracker_record_id'],$clockRow['tracker_record_id'],'clock defer preserves tracker record');
echo 'PASS '.$checks.' MAX channel attribution checks ('.$p->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
