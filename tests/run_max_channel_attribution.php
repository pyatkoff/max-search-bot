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
$repo=new MaxChannelConversionOutbox($p);$now=strtotime('2026-10-15 11:00:00 UTC');
$repo->activateSharedGoals(strtotime('2026-10-15 10:00:00 UTC'));$s=MaxChannelConversionOutbox::settings($p);
same($s['counter_id'],'98615635','counter string');
$c=$repo->conversation('1','-1234567890123456789',$s);ok($c!==null,'exact MAX user and post');
foreach([2,3,4,5,6] as $id)same($repo->conversation((string)$id,'-1234567890123456789',$s),null,'reject scope '.$id);
same($repo->conversation('1 OR 1','-1234567890123456789',$s),null,'reject unsafe identity');
ok($repo->conversation('7','-1234567890123456789',$s)!==null,'unique manager-source link without post');
same($repo->event($c,'max_bot_activity_start',$s)['id'],2,'first callback after activation');
same($repo->event($c,'max_manager_request',$s),null,'manager answer does not synthesize a request');
same($repo->event($c,'max_customer_reply_after_manager',$s)['id'],7,'same-second ordering and callback exclusion');
$p->exec("INSERT INTO conversation_events(conversation_id,event_type,created_at) VALUES(1,'waiting_manager','2026-10-15 10:00:03'),(1,'manager_message','2026-10-15 10:00:04')");
same($repo->event($c,'max_manager_request',$s)['id'],1,'actual waiting-manager event owns request stage');
MetrikaConversionGoalService::reconcileChannelConversation(1);
same((int)$p->query('SELECT COUNT(*) FROM max_channel_conversion_outbox')->fetchColumn(),3,'canonical reconciler captures three stages');
MetrikaConversionGoalService::reconcileChannelConversation(1);
same((int)$p->query('SELECT COUNT(*) FROM max_channel_conversion_outbox')->fetchColumn(),3,'reentry deduplicates');
same((int)row($p)['event_unix'],strtotime('2026-10-15 10:00:01 UTC'),'occurrence time frozen');
same((int)row($p,2)['event_unix'],strtotime('2026-10-15 10:00:03 UTC'),'request uses actual transition time');
same(row($p,2)['target'],'max_manager_request','request is independent from manager answer');
same(row($p)['state'],'pending','no identity still durable');
same(MaxChannelConversionOutbox::queueIfEligible('-1234567890123456789','max_sale',['conversation_id'=>'1']),null,'CRM delegates unchanged');
same(MaxChannelConversionOutbox::queueIfEligible('-1234567890123456789','max_manager_reply',null),null,'unrelated legacy two-argument path unchanged');
$p->exec("UPDATE max_channel_attribution_settings SET mode='off'");
same(MaxChannelConversionOutbox::queueIfEligible('-1234567890123456789','max_manager_reply',['conversation_id'=>'1']),null,'off delegates old path');
$noIO=new class{public function __call($name,$args){throw new RuntimeException('unexpected_io');}};
same(MaxChannelConversionWorker::tick($p,$noIO,$noIO,$now)['mode'],'off','off performs no transport');
$p->exec("UPDATE max_channel_attribution_settings SET mode='collect'");
$repo->resolve('1',null,$now);same(count($repo->pending($now)),2,'missing identity delayed');
$identity=['external_user_id'=>'1234567890123456789','client_id'=>'1844674407370955161512345','record_id'=>'9007199254740993','observed_at_unix'=>$now+100];
$lookup=new class($identity){public array $identity;public array $users=[];public array $membership=[];public int $calls=0;function __construct($v){$this->identity=$v;}function lookup($counter,$users){$this->calls++;$this->users=$users;return ['1234567890123456789'=>$this->identity];}function lookupWithMembership($counter,$users,$notBefore,$cutoff){$this->calls++;$this->users=$users;return ['identities'=>['1234567890123456789'=>$this->identity]+array_fill_keys($users,null),'membership'=>$this->membership+array_fill_keys($users,[])];}};
$stats=MaxChannelConversionWorker::tick($p,$lookup,$noIO,$now+901);same($stats['resolved'],3,'later tracker identity enriches earlier events');
same($lookup->users,['1234567890123456789'],'long MAX identifier remains an exact wire string');
$lookup->membership=['1234567890123456789'=>[
    ['kind'=>'subscription','source_record_id'=>'701','state'=>'subscribed','occurred_at_unix'=>$now-899],
    ['kind'=>'retention_30m','source_record_id'=>'701','subscription_source_record_id'=>'701','state'=>'retained','occurred_at_unix'=>$now+901,'minimum_age_seconds'=>1800]
]];
$stats=MaxChannelConversionWorker::tick($p,$lookup,$noIO,$now+901);
same($stats['membership']['inserted'],2,'same signed lookup mirrors subscription and mature retention');
ok($lookup->calls>=2,'cyclic admitted-user pass discovers subscription after identity resolution');
same((int)$p->query("SELECT COUNT(*) FROM conversation_events WHERE event_type IN ('channel_subscription_observed','channel_retention_30m_observed')")->fetchColumn(),2,'two versioned receipts use existing journal');
$membershipPayloads=$p->query("SELECT event_type,payload_json FROM conversation_events WHERE event_type IN ('channel_subscription_observed','channel_retention_30m_observed') ORDER BY id")->fetchAll();
same(json_decode($membershipPayloads[0]['payload_json'],true)['origin'],'existing_membership_producer','subscription provenance exact');
same(json_decode($membershipPayloads[1]['payload_json'],true)['subscription_event_id'],(string)$p->query("SELECT id FROM conversation_events WHERE event_type='channel_subscription_observed'")->fetchColumn(),'retention binds local subscription period');
$journal=new MaxMembershipReceiptJournal($p);$sameReceipts=['1234567890123456789'=>[
    ['kind'=>'subscription','source_record_id'=>'701','state'=>'subscribed','occurred_at_unix'=>$now-899],
    ['kind'=>'retention_30m','source_record_id'=>'701','subscription_source_record_id'=>'701','state'=>'retained','occurred_at_unix'=>$now+901,'minimum_age_seconds'=>1800]
]];
$binding=[['external_user_id'=>'1234567890123456789','conversation_ids'=>['1']]];
$activation=strtotime('2026-10-15 10:00:00 UTC');
$repeat=$journal->record($binding,$sameReceipts,$now+901,$activation);same($repeat['inserted'],0,'repeat tracker receipt is idempotent');same($repeat['unchanged'],2,'repeat preserves both exact events');
$late=$sameReceipts;$late['1234567890123456789'][1]['state']='not_retained';$late['1234567890123456789'][1]['source_record_id']='702';
$lookup->membership=$late;$lateStats=MaxChannelConversionWorker::tick($p,$lookup,$noIO,$now+902);
same($lateStats['membership']['inserted'],1,'cyclic pass observes late contradictory source evidence after retention');
same((int)$p->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='channel_retention_30m_observed'")->fetchColumn(),2,'late retained contradiction is preserved as separate evidence');
$lateAgain=MaxChannelConversionWorker::tick($p,$lookup,$noIO,$now+903);
same($lateAgain['membership']['inserted'],0,'repeated late contradiction is idempotent');same($lateAgain['membership']['unchanged'],2,'repeated cyclic evidence preserves both facts');
$rejoin=['1234567890123456789'=>[
    ['kind'=>'subscription','source_record_id'=>'703','state'=>'subscribed','occurred_at_unix'=>$now+1000],
    ['kind'=>'retention_30m','source_record_id'=>'703','subscription_source_record_id'=>'703','state'=>'retained','occurred_at_unix'=>$now+2800,'minimum_age_seconds'=>1800]
]];
same($journal->record($binding,$rejoin,$now+2800,$activation)['inserted'],2,'rejoin creates a distinct subscription period and receipt');
same($journal->record([['external_user_id'=>'1234567890123456789','conversation_ids'=>['2']]],$rejoin,$now+2800,$activation)['inserted'],0,'cross-platform conversation binding is rejected');
$p->exec("INSERT INTO conversations(id,customer_channel_id,project_key,channel,external_chat_id,is_test,status,source_id,started_at) VALUES(9,1,'anytour','max','-1234567890123456789',0,'ai',2,'2026-10-15 10:30:00')");
$ambiguous=['1234567890123456789'=>[['kind'=>'subscription','source_record_id'=>'704','state'=>'subscribed','occurred_at_unix'=>$now+1100]]];
$lookup->membership=$ambiguous;$ambiguousStats=MaxChannelConversionWorker::tick($p,$lookup,$noIO,$now+1100);
same($ambiguousStats['membership']['inserted'],0,'normal worker keeps person membership unassigned when a second eligible episode exists');
same((int)$p->query("SELECT COUNT(*) FROM conversation_events WHERE actor_id='max-membership:704'")->fetchColumn(),0,'episode ambiguity cannot silently choose the transient outbox conversation');
$p->exec("INSERT INTO customer_channels VALUES(4,'anytour','max','777777777777777777')");
$p->exec("INSERT INTO conversations(id,customer_channel_id,project_key,channel,external_chat_id,is_test,status,source_id,started_at) VALUES(8,4,'anytour','max','-777777777777777777',0,'ai',2,'2026-10-15 11:00:00')");
$followBinding=[['external_user_id'=>'777777777777777777','conversation_ids'=>['8']]];
$followSubscription=['777777777777777777'=>[['kind'=>'subscription','source_record_id'=>'800','state'=>'subscribed','occurred_at_unix'=>$now+1000]]];
same($journal->record($followBinding,$followSubscription,$now+1000,$activation)['inserted'],1,'prospective subscription is journaled once');
$p->prepare("INSERT INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,state,created_at) VALUES('anytour',8,'777777777777777777','98615635','max_bot_activity_start','max_bot_activity_start',?,'processed',?)")->execute([$now+1000,gmdate('Y-m-d H:i:s',$now+1000)]);
$p->exec('UPDATE max_channel_attribution_settings SET membership_receipts_outbox_cursor=3');
$retentionLookup=new class{public array $users=[];function lookupWithMembership($counter,$users,$notBefore,$cutoff){$this->users=$users;return ['identities'=>['777777777777777777'=>null],'membership'=>['777777777777777777'=>[
    ['kind'=>'retention_30m','source_record_id'=>'800','subscription_source_record_id'=>'800','state'=>'retained','occurred_at_unix'=>$cutoff,'minimum_age_seconds'=>1800]
]]];}};
$follow=MaxChannelConversionWorker::tick($p,$retentionLookup,$noIO,$now+2800);
same($retentionLookup->users,['777777777777777777'],'1800 follow-up uses the exact admitted user without pending conversion');
same($follow['membership']['inserted'],1,'1800 follow-up stores the period-bound retention receipt');
$p->exec("UPDATE max_channel_conversion_outbox SET state='pending',client_id=NULL,tracker_record_id=NULL,tracker_observed_unix=NULL,next_attempt_unix=0 WHERE id IN (1,2,3)");
$cursorBeforeFailure=(int)MaxChannelConversionOutbox::settings($p)['membership_receipts_outbox_cursor'];
$fallback=new class($identity){public array $identity;public int $membershipCalls=0;public int $plainCalls=0;function __construct($v){$this->identity=$v;}function lookupWithMembership(){ $this->membershipCalls++;throw new RuntimeException('membership_unavailable');}function lookup($counter,$users){$this->plainCalls++;return ['1234567890123456789'=>$this->identity];}};
$fallbackStats=MaxChannelConversionWorker::tick($p,$fallback,$noIO,$now+2801);
same($fallback->membershipCalls,1,'optional membership observer attempted once');same($fallback->plainCalls,1,'membership failure falls back to identity-only lookup');
same($fallbackStats['resolved'],3,'membership failure cannot stall ordinary identity resolution');same($fallbackStats['membership']['errors'],1,'membership failure remains visible separately');
same((int)MaxChannelConversionOutbox::settings($p)['membership_receipts_outbox_cursor'],$cursorBeforeFailure,'failed membership observation cannot consume cyclic work');
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
// Shared readiness pins the owner's existing IDs and exact identifiers.
$exact=[];foreach(MetrikaOfflineClient::SHARED_GOALS as $stage=>$definition)$exact[]=['id'=>$definition['id'],'name'=>'Existing '.$stage,'type'=>'action','is_favorite'=>true,'conditions'=>[['type'=>'exact','url'=>$definition['target']]]];
$client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['goals'=>$exact])]);same($client->goalsReady(),true,'selected existing exact goals ready');
$contain=$exact;foreach($contain as &$g)$g['conditions'][0]['type']='contain';unset($g);
$client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['goals'=>$contain])]);same($client->goalsReady(),false,'contains cannot pass offline readiness');
$wrongIds=$exact;$wrongIds[0]['id']=999;
$client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['goals'=>$wrongIds])]);same($client->goalsReady(),false,'matching identifier under another ID cannot replace selected goal');
$multi=$exact;$multi[0]['conditions'][]=['type'=>'exact','url'=>'other_event'];
$client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['goals'=>$multi])]);same($client->goalsReady(),false,'additional condition cannot pass readiness');
$duplicates=$exact;$copy=$exact[0];$copy['id']=999;$duplicates[]=$copy;
$client=new MetrikaOfflineClient('98615635','fake',fn()=>['http'=>200,'body'=>json_encode(['goals'=>$duplicates])]);same($client->goalsReady(),false,'duplicate exact identifier cannot pass readiness');
foreach([MetrikaOfflineClient::CHANNEL_GOALS,MetrikaOfflineClient::SHARED_GOALS] as $definitions)foreach($definitions as $stage=>$definition){$e=$event;$e['target']=$stage;$e['transport_target']=$definition['target'];$lines=array_map('str_getcsv',explode("\n",trim(MetrikaOfflineClient::csv($e,$now+901))));same($lines[1][1],$definition['target'],'frozen historical or selected CSV target');}
$bad=$event;unset($bad['transport_target']);rejects(fn()=>MetrikaOfflineClient::csv($bad,$now+901),'missing frozen transport target cannot silently change goal');
$bad=$event;$bad['target']='max_manager_request';$bad['transport_target']='max_channel_manager_reply';rejects(fn()=>MetrikaOfflineClient::csv($bad,$now+901),'manager reply cannot be relabelled as a request');
$beforeComment=MetrikaOfflineClient::comment($event);$legacyEvent=$event;$legacyEvent['transport_target']='max_channel_customer_reply_after_manager';same(MetrikaOfflineClient::comment($legacyEvent),$beforeComment,'transport selection preserves recovery identity');
$legacyPosts=0;$client=new MetrikaOfflineClient('98615635','fake',function()use(&$legacyPosts){$legacyPosts++;return ['http'=>200,'body'=>'{}'];});
foreach(MetrikaOfflineClient::CHANNEL_GOALS as $stage=>$definition){$held=$event;$held['target']=$stage;$held['transport_target']=$definition['target'];rejects(fn()=>$client->upload($held,$now+901),'historical target cannot receive a new conversion upload '.$stage);}
same($legacyPosts,0,'all historical wire goals rejected before network IO');
// Explicit configuration updates only the three selected IDs in place.
$unrelated=['id'=>55,'name'=>'Existing unrelated dialog goal','type'=>'action','conditions'=>[['type'=>'contain','url'=>'g1_dialog']]];
$historical=['id'=>56,'name'=>'Historical channel goal','type'=>'action','conditions'=>[['type'=>'exact','url'=>'max_channel_activity_start']]];
$original=array_merge($contain,[$unrelated,$historical]);$live=$original;$writes=[];
$transport=function($method,$url,$body)use(&$live,&$writes){
    if($method==='GET')return ['http'=>200,'body'=>json_encode(['goals'=>$live])];
    same($method,'PUT','configuration updates existing goals only');
    $g=json_decode($body,true)['goal'];same(count($g['conditions']),1,'one existing condition retained');same($g['conditions'][0]['type'],'exact','exact offline condition');
    $found=false;foreach($live as &$existing)if(str_ends_with($url,'/goal/'.$existing['id'])){
        ok(in_array((string)$existing['id'],array_map('strval',array_column(MetrikaOfflineClient::SHARED_GOALS,'id')),true),'only owner-selected IDs updated');
        same($g['conditions'][0]['url'],$existing['conditions'][0]['url'],'existing identifier preserved');
        $existing=array_replace($existing,$g);$writes[]=$existing;$found=true;$receipt=$existing;break;
    }unset($existing);ok($found,'exact existing goal endpoint');return ['http'=>200,'body'=>json_encode(['goal'=>$receipt])];
};
$client=new MetrikaOfflineClient('98615635','fake',$transport);
$client->configureSharedGoals();same(count($writes),0,'configuration plan remains read-only');
$client->configureSharedGoals(true);same(count($writes),3,'three selected contain conditions updated in place');
foreach($original as $index=>$old){$expected=$old;if($index<3)$expected['conditions'][0]['type']='exact';same($live[$index],$expected,'all existing goal data retained '.$index);}
$client->configureSharedGoals(true);same(count($writes),3,'repeat configuration makes no writes');same($client->goalsReady(),true,'updated selected goals ready');
foreach([$wrongIds,$multi,$duplicates,array_slice($exact,0,2)] as $conflict){
    $conflictWrites=0;$client=new MetrikaOfflineClient('98615635','fake',function($method)use($conflict,&$conflictWrites){if($method!=='GET')$conflictWrites++;return ['http'=>200,'body'=>json_encode(['goals'=>$conflict])];});rejects(fn()=>$client->configureSharedGoals(true),'invalid selected goal set blocked');same($conflictWrites,0,'conflicting goal set makes no partial edits');
}
$wrongTarget=$contain;$wrongTarget[2]['conditions'][0]['url']='different_request';
$conflictWrites=0;$client=new MetrikaOfflineClient('98615635','fake',function($method)use($wrongTarget,&$conflictWrites){if($method!=='GET')$conflictWrites++;return ['http'=>200,'body'=>json_encode(['goals'=>$wrongTarget])];});rejects(fn()=>$client->configureSharedGoals(true),'later conflicting identifier blocked');same($conflictWrites,0,'all selected goals checked before first PUT');
$wrongCounterIO=0;$client=new MetrikaOfflineClient('2','fake',function()use(&$wrongCounterIO){$wrongCounterIO++;throw new RuntimeException('unexpected_io');});rejects(fn()=>$client->configureSharedGoals(true),'configuration wrong counter rejected');same($wrongCounterIO,0,'configuration counter pinned before IO');
$uncertainWrites=0;$client=new MetrikaOfflineClient('98615635','fake',function($method)use($contain,&$uncertainWrites){if($method==='GET')return ['http'=>200,'body'=>json_encode(['goals'=>$contain])];$uncertainWrites++;return ['error'=>28,'http'=>0];});rejects(fn()=>$client->configureSharedGoals(true),'uncertain update requires explicit readback');same($uncertainWrites,1,'uncertain update never blindly repeats');
$readCount=0;$writeCount=0;
$client=new MetrikaOfflineClient('98615635','fake',function($method,$url,$body)use(&$readCount,&$writeCount,$original,$exact){
    if($method==='GET'){$readCount++;$goals=$original;if($readCount>=5){$goals=array_merge($exact,array_slice($original,3));$goals[3]['name']='Concurrent unrelated change';}return ['http'=>200,'body'=>json_encode(['goals'=>$goals])];}
    same($method,'PUT','concurrent goal changes never rolled back');$goal=json_decode($body,true)['goal'];foreach($exact as $g)if(str_ends_with($url,'/goal/'.$g['id'])){$writeCount++;return ['http'=>200,'body'=>json_encode(['goal'=>array_replace($g,$goal)])];}throw new RuntimeException('wrong_goal_endpoint');
});rejects(fn()=>$client->configureSharedGoals(true),'concurrent unrelated goal change blocks activation');same($writeCount,3,'only selected goal conditions requested');
// Real signed protocol; wrong key, replay time and malformed identities all fail closed.
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);openssl_pkey_export($key,$private);$public=openssl_pkey_get_details($key)['key'];
$body=MaxTrackerLookupProtocol::body('98615635',['1234567890123456789'],$now);$signature=MaxTrackerLookupProtocol::signature($body,$private);
same(MaxTrackerLookupProtocol::authenticate($body,$signature,$public,$now,'98615635')['user_ids'],['1234567890123456789'],'RSA signature exact identity');
$membershipBody=MaxTrackerLookupProtocol::body('98615635',['1234567890123456789'],$now,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',['not_before_unix'=>$now-60,'cutoff_unix'=>$now]);
same(MaxTrackerLookupProtocol::authenticate($membershipBody,MaxTrackerLookupProtocol::signature($membershipBody,$private),$public,$now,'98615635')['membership']['not_before_unix'],$now-60,'signed prospective membership window exact');
rejects(fn()=>MaxTrackerLookupProtocol::authenticate($body.' ',$signature,$public,$now,'98615635'),'body tampering');
rejects(fn()=>MaxTrackerLookupProtocol::authenticate($body,$signature,$public,$now+91,'98615635'),'expired signature');
rejects(fn()=>MaxTrackerLookupProtocol::authenticate($body,$signature,$public,$now,'2'),'wrong signed counter');
foreach([[123],['01'],['-123'],['1','1'],array_map('strval',range(1,26))] as $ids)rejects(fn()=>MaxTrackerLookupProtocol::body('98615635',$ids,$now),'invalid user list');
foreach([["not_before_unix"=>$now+1,"cutoff_unix"=>$now],["not_before_unix"=>$now-1,"cutoff_unix"=>$now-91],["not_before_unix"=>"$now","cutoff_unix"=>$now]]as$window)rejects(fn()=>MaxTrackerLookupProtocol::body('98615635',['1'],$now,null,$window),'invalid membership window');
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
same($repo->collect(MaxChannelConversionOutbox::settings($p),fn()=>null),10,'message and handoff collectors advance');same((int)MaxChannelConversionOutbox::settings($p)['message_cursor'],9,'cursor saved after success');
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
// Cutover preserves every outcome that may already have reached Metrika.
$p=maxAttributionFixture();(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$p);$repo=new MaxChannelConversionOutbox($p);
$p->exec("UPDATE max_channel_attribution_settings SET mode='send',message_cursor=9");
$p->exec("INSERT INTO conversation_events(conversation_id,event_type,created_at) VALUES(1,'waiting_manager','2026-10-15 11:00:00'),(1,'manager_message','2026-10-15 10:00:04')");
$historicalRows=[
    [1,'max_bot_activity_start','max_channel_activity_start','pending',0,null,null,null],
    [2,'max_customer_reply_after_manager','max_channel_customer_reply_after_manager','ready',0,null,null,null],
    [3,'max_manager_reply','max_channel_manager_reply','pending',0,null,null,null],
    [4,'max_bot_activity_start','max_channel_activity_start','accepted',1,'440','UPLOADED',null],
    [5,'max_customer_reply_after_manager','max_channel_customer_reply_after_manager','unknown',1,null,null,null],
    [6,'max_bot_activity_start','max_channel_activity_start','ready',1,null,null,null],
    [7,'max_manager_reply','max_channel_manager_reply','uploading',1,null,null,'existing-claim'],
    [8,'max_customer_reply_after_manager','max_channel_customer_reply_after_manager','ready',0,null,null,'prior-claim'],
    [9,'max_bot_activity_start','max_channel_activity_start','processed',1,'449','PROCESSED',null],
    [10,'max_manager_reply','max_channel_manager_reply','ready',1,null,null,null],
];
foreach($historicalRows as [$id,$target,$wire,$state,$attempts,$uploadId,$uploadStatus,$claim]){
    $q=$p->prepare('INSERT INTO max_channel_conversion_outbox (id,project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,client_id,tracker_record_id,tracker_observed_unix,state,attempts,upload_id,upload_status,claim_token,claimed_at_unix) VALUES (?,\'anytour\',?,\'1234567890123456789\',\'98615635\',?,?,?,?,\'frozen-record\',?,?,?,?,?,?,?)');
    $q->execute([$id,100+$id,$target,$wire,$now-600,'1844674407370955161512345',$now-700,$state,$attempts,$uploadId,$uploadStatus,$claim,$claim!==null?$now:null]);
}
$readOnlyApi=new class{
    public int $posts=0;public int $gets=0;
    function upload($row,$now){$this->posts++;throw new RuntimeException('unexpected_historical_post');}
    function poll($id){$this->gets++;return null;}
    function findUnknown($row){$this->gets++;return null;}
};
$result=MaxChannelConversionWorker::tick($p,$noIO,$readOnlyApi,$now);
same($readOnlyApi->posts,0,'migration alone cannot activate new POSTs');same($readOnlyApi->gets,2,'historical accepted and unknown still get read-only checks');same($result['resolved'],0,'activation pending performs no new identity work');
$before=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll();
rejects(fn()=>$repo->activateSharedGoals($now),'activation requires collection pause before switching');
$p->exec("UPDATE max_channel_attribution_settings SET mode='collect'");
$oldSettings=MaxChannelConversionOutbox::settings($p);$cutover=$repo->activateSharedGoals($now);$selected=MaxChannelConversionOutbox::settings($p);
same($selected['goal_set'],'shared','explicit activation selects old shared goals');same((int)$selected['activated_at_unix'],(int)$oldSettings['activated_at_unix'],'initial channel activation unchanged');same((int)$selected['message_cursor'],9,'message cursor never reset');
same((int)$selected['request_event_cursor'],2,'request collector starts after prior recorded events');same((int)$selected['shared_goals_event_id'],2,'request cutoff freezes recorded event boundary');same((int)$selected['shared_goals_activated_at_unix'],$now,'request stage has fresh occurrence cutoff');
same($cutover['switched'],2,'only pristine common stages change wire goal');same($cutover['retired'],1,'unsent manager answer retired without becoming a request');
same(row($p,1)['transport_target'],'max_bot_activity_start','pristine activity now targets existing goal');same(row($p,2)['transport_target'],'max_customer_reply_after_manager','pristine customer response now targets existing goal');
same(row($p,3)['state'],'retired','unsent answer retired');same(row($p,3)['target'],'max_manager_reply','historical answer retains original meaning');
foreach([1,2,3] as $id)foreach(['event_unix','client_id','tracker_record_id','tracker_observed_unix','attempts','upload_id','upload_status'] as $field)same(row($p,$id)[$field],$before[$id-1][$field],'cutover keeps immutable '.$field.' row '.$id);
foreach([4,5,6,7,8,9,10] as $id)same(row($p,$id),$before[$id-1],'possibly transmitted or claimed row remains byte-for-byte '.$id);
same($repo->claim('10',$now),null,'previously attempted manager answer is held without new POST');
same($repo->claim('6',$now),null,'previously attempted common stage retains old target and is held');
same($repo->claim('8',$now),null,'previously claimed legacy common stage cannot be reposted to old goal');
$readyIds=array_map('intval',array_column($repo->rows('ready',$now,10),'id'));same($readyIds,[2],'only selected shared targets enter upload selection');
ok(!in_array(10,array_map('intval',array_column($repo->rows('ready',$now,10),'id')),true),'retired answer is absent from upload selection');
$again=$repo->activateSharedGoals($now+3600);$sameSettings=MaxChannelConversionOutbox::settings($p);same($again['switched'],0,'repeat activation never retargets rows');same($again['retired'],0,'repeat activation never retires again');same($sameSettings['shared_goals_activated_at_unix'],$selected['shared_goals_activated_at_unix'],'repeat activation cannot widen history');same($sameSettings['shared_goals_event_id'],$selected['shared_goals_event_id'],'repeat activation retains event boundary');
$c=$repo->conversation('1','-1234567890123456789',$selected);same($repo->event($c,'max_manager_request',$selected),null,'pre-cutover request in same second cannot be backfilled');
$p->exec("INSERT INTO conversation_events(conversation_id,event_type,created_at) VALUES(1,'manager_message','2026-10-15 11:00:01')");same($repo->event($c,'max_manager_request',$selected),null,'new manager answer cannot masquerade as request');
$p->exec("INSERT INTO conversation_events(conversation_id,event_type,created_at) VALUES(1,'waiting_manager','2026-10-15 10:59:59')");same($repo->event($c,'max_manager_request',$selected),null,'late inserted pre-cutoff handoff cannot backfill');
$p->exec("INSERT INTO conversation_events(conversation_id,event_type,created_at) VALUES(1,'waiting_manager','2026-10-15 11:00:01')");
$request=$repo->event($c,'max_manager_request',$selected);same((int)$request['id'],5,'first actual new handoff selected');same($request['created_at'],'2026-10-15 11:00:01','real request occurrence preserved');
$legacyRequest=MaxChannelConversionOutbox::queueIfEligible('-1234567890123456789','max_manager_request',['conversation_id'=>'1']);same($legacyRequest,true,'channel request intercepted before old YCLID fallback');
$requestRow=$p->query("SELECT * FROM max_channel_conversion_outbox WHERE conversation_id=1 AND target='max_manager_request'")->fetch();ok(is_array($requestRow),'request captured durably');same($requestRow['transport_target'],'max_manager_request','request uses existing selected goal');same((int)$requestRow['event_unix'],$now+1,'request event time not queue time');
MaxSearchApi::$allowed=false;same(MetrikaConversionGoalService::managerRequest(1),false,'existing conversion opt-out still gates requests');MaxSearchApi::$allowed=true;
$targets=[];$queue=function($chat,$target)use(&$targets){$targets[]=$target;return MaxChannelConversionOutbox::queueIfEligible($chat,$target,['conversation_id'=>'1'])??false;};
same(MetrikaConversionGoalService::managerRequest(1,$queue),true,'actual request flow uses dedicated stage');same(MetrikaConversionGoalService::managerReply(1,$queue),true,'manager reply recovery reuses already recorded actual request');same($targets,['max_manager_request'],'handoff and answer never generate separate YCLID or manager-reply duplicate');
same((int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE conversation_id=1 AND target='max_manager_request'")->fetchColumn(),1,'one durable request across callbacks and recovery');
same(MaxChannelConversionOutbox::queueIfEligible('-1234567890123456789','max_manager_reply',['conversation_id'=>'1']),true,'retired manager-answer path consumed instead of YCLID fallback');
// Request events recover independently even when there is no new message.
$seen=[];$collected=$repo->collect(MaxChannelConversionOutbox::settings($p),function($id)use(&$seen){$seen[]=$id;});same($collected,2,'only newly recorded request events traversed');same($seen,[1,1],'request recovery does not replay message cursor');
$afterRequests=MaxChannelConversionOutbox::settings($p);same((int)$afterRequests['message_cursor'],9,'handoff recovery leaves message cursor stable');same((int)$afterRequests['request_event_cursor'],5,'handoff cursor advances independently');
$token=$repo->claim('2',$now);ok(is_string($token),'retargeted pristine row can be claimed');
$fresh=$repo->claimedEvent('2',$token);same($before[1]['transport_target'],'max_channel_customer_reply_after_manager','worker selection before cutover keeps old snapshot');same($fresh['transport_target'],'max_customer_reply_after_manager','worker refreshes frozen target after claim across cutover');same($repo->claimedEvent('2',str_repeat('0',32)),null,'another worker cannot read claim by wrong token');
// New code loaded before migration 038 must hold managed MAX conversions,
// rather than silently delegating them into the older YCLID transport.
$p->exec('ALTER TABLE max_channel_attribution_settings RENAME TO max_channel_attribution_settings_snapshot');
$p->exec('CREATE TABLE max_channel_attribution_settings (project_key VARCHAR(64) PRIMARY KEY,mode VARCHAR(16),counter_id VARCHAR(20),activated_at_unix BIGINT,message_cursor BIGINT)');
$p->exec("INSERT INTO max_channel_attribution_settings VALUES('anytour','send','98615635',1792058400,9)");
$gap=MaxChannelConversionOutbox::settings($p);ok(is_array($gap),'pre-migration settings remain recognizable');same(MaxChannelConversionOutbox::sharedGoalsActive($gap),false,'pre-migration settings cannot activate shared sends');
same(MaxChannelConversionOutbox::queueIfEligible('-1234567890123456789','max_bot_activity_start',['conversation_id'=>'1']),false,'migration gap blocks managed activity without YCLID fallback');
same(MaxChannelConversionOutbox::queueIfEligible('-1234567890123456789','max_manager_request',null),false,'migration gap blocks legacy request without YCLID fallback');
$gapPosts=$readOnlyApi->posts;MaxChannelConversionWorker::tick($p,$noIO,$readOnlyApi,$now);same($readOnlyApi->posts,$gapPosts,'migration gap performs no conversion POST');
$p->exec('DROP TABLE max_channel_attribution_settings');$p->exec('ALTER TABLE max_channel_attribution_settings_snapshot RENAME TO max_channel_attribution_settings');
// The cyclic observer is MAX-only and must eventually visit every admitted
// MAX user even when one user's receipts remain unknown.
$p=maxAttributionFixture();(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$p);$cycleNow=time();
$cycleMysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
if($cycleMysql){$migration=file_get_contents(dirname(__DIR__).'/migrations/067_telegram_dialogue_conversions.sql');$p->exec($migration);}
else foreach(["platform TEXT DEFAULT 'max'","identity_source_key TEXT DEFAULT ''",'source_signal_id INTEGER','identity_observation_id INTEGER']as$column)$p->exec('ALTER TABLE max_channel_conversion_outbox ADD COLUMN '.$column);
$p->prepare("UPDATE max_channel_attribution_settings SET mode='collect',goal_set='shared',shared_goals_activated_at_unix=?,membership_receipts_activated_at_unix=?,membership_receipts_outbox_cursor=0 WHERE project_key='anytour'")->execute([$cycleNow-60,$cycleNow-60]);
$insert=$p->prepare("INSERT INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,state,created_at,platform) VALUES('anytour',?,?,'98615635','max_bot_activity_start','max_bot_activity_start',?,'processed',?,?)");
$cycleUsers=[];for($i=1;$i<=6;$i++){$user='880000000000000000'.$i;$cycleUsers[]=$user;$insert->execute([100+$i,$user,$cycleNow-30,gmdate('Y-m-d H:i:s',$cycleNow-30),'max']);}
$insert->execute([199,'9900000000000000001',$cycleNow-30,gmdate('Y-m-d H:i:s',$cycleNow-30),'telegram']);
$cycleLookup=new class{public array $batches=[];function lookupWithMembership($counter,$users,$notBefore,$cutoff){$this->batches[]=$users;return ['identities'=>array_fill_keys($users,null),'membership'=>array_fill_keys($users,[])];}function lookup(){throw new RuntimeException('unexpected_identity_lookup');}};
MaxChannelConversionWorker::tick($p,$cycleLookup,$noIO,$cycleNow);
$firstCursor=(int)MaxChannelConversionOutbox::settings($p)['membership_receipts_outbox_cursor'];
MaxChannelConversionWorker::tick($p,$cycleLookup,$noIO,$cycleNow+1);
$secondCursor=(int)MaxChannelConversionOutbox::settings($p)['membership_receipts_outbox_cursor'];
same($cycleLookup->batches[0],array_slice($cycleUsers,0,5),'bounded membership pass visits first five MAX users');
same($cycleLookup->batches[1],[$cycleUsers[5]],'per-user unknown cannot starve the next admitted MAX user');
ok($firstCursor>0&&$secondCursor>$firstCursor,'successful cyclic observations advance bounded work progress');
ok(!in_array('9900000000000000001',array_merge(...$cycleLookup->batches),true),'Telegram outbox identity cannot leak into MAX membership lookup');
echo 'PASS '.$checks.' MAX channel attribution checks ('.$p->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";

