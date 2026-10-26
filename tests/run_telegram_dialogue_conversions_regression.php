<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/max_channel_attribution.php';
require_once __DIR__.'/run_advertising_visits_regression.php';
require_once dirname(__DIR__).'/services/MaxChannelConversionWorker.php';
require_once dirname(__DIR__).'/services/TelegramDialogueConversions.php';
require_once dirname(__DIR__).'/services/AdvertisingSignalRepository.php';
$checks=0;
function tgCheck(bool $ok,string $name):void {global $checks;$checks++;if(!$ok)throw new RuntimeException('FAIL '.$name);}
class MaxSearchApi {public static bool $allowed=true;public static function metrikaGoalAllowed($chat):bool{return self::$allowed;}}
$p=maxAttributionFixture();ProjectConfig::resetForTests(['id'=>'anytour']);
foreach(['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS']as$key)define($key,'fixture');
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$p);
$mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
tgCheck(TelegramDialogueConversions::settings($p)===null,'unmigrated Telegram stays off');
$max=new MaxChannelConversionOutbox($p);$now=strtotime('2026-10-26 10:00:00 UTC');$max->activateSharedGoals($now-100);
$p->exec("UPDATE max_channel_attribution_settings SET mode='off'");
foreach(['advertising_signals','advertising_visit_cursors','advertising_visit_observations']as$t)$p->exec('DROP TABLE IF EXISTS '.$t);
advertisingVisitFixtureSchema($p);
if($mysql){$migration=file_get_contents(dirname(__DIR__).'/migrations/067_telegram_dialogue_conversions.sql');$p->exec($migration);$p->exec($migration);$p->exec(file_get_contents(dirname(__DIR__).'/migrations/064_advertising_signals.sql'));}
else{
    foreach(["telegram_mode TEXT DEFAULT 'off'",'telegram_activated_at_unix INTEGER','telegram_signal_floor INTEGER DEFAULT 0','telegram_signal_cursor INTEGER DEFAULT 0']as$c)$p->exec('ALTER TABLE max_channel_attribution_settings ADD COLUMN '.$c);
    foreach(["platform TEXT DEFAULT 'max'","identity_source_key TEXT DEFAULT ''",'source_signal_id INTEGER','identity_observation_id INTEGER']as$c)$p->exec('ALTER TABLE max_channel_conversion_outbox ADD COLUMN '.$c);
    $p->exec('CREATE TABLE advertising_signals (id INTEGER PRIMARY KEY AUTOINCREMENT,project_key TEXT,source_key TEXT,platform TEXT,counter_id TEXT,conversation_id TEXT,external_user_id TEXT,signal_key TEXT,source_type TEXT,source_id TEXT,handoff_event_id TEXT,manager_event_id TEXT,manager_message_id TEXT,occurred_at_unix INTEGER,captured_at_unix INTEGER,UNIQUE(project_key,source_key,platform,counter_id,conversation_id,signal_key))');
}
foreach(['customer_channels','conversations']as$t)$p->exec('ALTER TABLE '.$t.' ADD COLUMN customer_id INTEGER DEFAULT 0');
$p->exec("ALTER TABLE customer_channels ADD COLUMN external_chat_id VARCHAR(32) DEFAULT ''");
$p->exec('ALTER TABLE messages ADD COLUMN external_message_id VARCHAR(128)');
$scope=['project_key'=>'anytour','source_key'=>'tgmini','platform'=>'telegram','counter_id'=>'98615635'];
$signals=new AdvertisingSignalRepository($p,$scope);$bridge=new TelegramDialogueConversions($p);
function tgPerson(PDO $p,int $id,int $at):string{
    $user=(string)(8000000000+$id);
    $p->prepare("INSERT INTO customer_channels(id,project_key,channel,external_user_id,external_chat_id,customer_id) VALUES (?,'anytour','telegram',?,?,?)")->execute([$id,$user,$user,$id]);
    $p->prepare("INSERT INTO conversations(id,customer_channel_id,project_key,channel,external_chat_id,is_test,status,customer_id) VALUES (?,?,'anytour','telegram',?,0,'manager',?)")->execute([$id,$id,$user,$id]);
    foreach([[1,'inbound','customer','message'],[2,'outbound','manager','message'],[3,'inbound','customer','message'],[4,'inbound','customer','callback']]as[$n,$d,$s,$type])$p->prepare('INSERT INTO messages(id,conversation_id,channel,direction,sender_type,created_at,metadata_json,external_message_id) VALUES (?,?,\'telegram\',?,?,?,?,?)')->execute([$id*10+$n,$id,$d,$s,gmdate('Y-m-d H:i:s',$at+$n),json_encode(['type'=>$type]),'tg-fixture-'.$id.'-'.$n]);
    foreach([[1,'waiting_manager','customer'],[2,'manager_message','manager']]as[$n,$type,$actor])$p->prepare('INSERT INTO conversation_events(id,conversation_id,event_type,actor_type,created_at) VALUES (?,?,?,?,?)')->execute([$id*10+$n,$id,$type,$actor,gmdate('Y-m-d H:i:s',$at+$n)]);
    return $user;
}
$old=tgPerson($p,20,$now-20);$signals->incoming($old,'tg-fixture-20-1','message',$now-10);
$before=$p->query('SELECT mode,activated_at_unix,message_cursor,goal_set,shared_goals_activated_at_unix,shared_goals_event_id,request_event_cursor FROM max_channel_attribution_settings')->fetch();
tgCheck(TelegramDialogueConversions::settings($p)['telegram_mode']==='off','migration is opt-in');
$noIO=new class {public function __call($name,$args){throw new RuntimeException('unexpected_transport');}};
tgCheck((MaxChannelConversionWorker::tick($p,$noIO,$noIO,$now)['telegram']['mode']??'off')==='off','unactivated worker performs no Telegram work');
$activated=$bridge->activate('collect',$now);
tgCheck((int)$activated['telegram_signal_floor']===1,'first activation excludes already admitted signals');
tgCheck($p->query('SELECT mode,activated_at_unix,message_cursor,goal_set,shared_goals_activated_at_unix,shared_goals_event_id,request_event_cursor FROM max_channel_attribution_settings')->fetch()===$before,'Telegram activation preserves every MAX setting');
$user=tgPerson($p,21,$now);$signals->incoming($user,'tg-fixture-21-1','message',$now+10);$signals->request(21,$now+10);
$signals->incoming($user,'tg-fixture-21-4','callback',$now+10);$signals->incoming($user,'tg-fixture-21-3','message',$now+10);
$r=MaxChannelConversionWorker::tick($p,$noIO,$noIO,$now+20);
tgCheck(($r['telegram']['collected']??0)===3,'canonical activity, handoff and real reply reach the same worker');
tgCheck((int)$p->query('SELECT COUNT(*) FROM max_channel_conversion_outbox')->fetchColumn()===3,'one outbox, three stages, no old signals or callback reply');
tgCheck($r['telegram']['resolved']===0,'missing identities stay pending without a tracker request');
$rows=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll();
tgCheck(array_column($rows,'target')===array_keys(TelegramDialogueConversions::SIGNALS),'existing exact goals only');
tgCheck(array_map('intval',array_column($rows,'event_unix'))===[$now+1,$now+1,$now+3],'original occurrence is not capture/import/queue time');
tgCheck(array_unique(array_column($rows,'platform'))===['telegram'],'platform provenance remains explicit');
$r=MaxChannelConversionWorker::tick($p,$noIO,$noIO,$now+21);
tgCheck($r['telegram']['collected']===0&&(int)$p->query('SELECT COUNT(*) FROM max_channel_conversion_outbox')->fetchColumn()===3,'repeat tick is idempotent');
tgCheck(TelegramDialogueConversions::queueIfEligible($user,'max_bot_activity_start',['conversation_id'=>'21'])===true,'live goal hook consumes the same durable stage');
tgCheck(TelegramDialogueConversions::queueIfEligible($user,'max_bot_activity_start',['conversation_id'=>'22'])===false,'wrong dialogue cannot match by chat alone');
tgCheck(TelegramDialogueConversions::queueIfEligible('-'.$user,'max_bot_activity_start',null)===null,'MAX/group identities retain original routing');
tgCheck(TelegramDialogueConversions::queueIfEligible($user,'max_sale',null)===null,'unrelated goals retain original routing');
$import=new AdvertisingVisitImportService(new AdvertisingVisitRepository($p),$scope);
$entry=advertisingVisitRow('1',['visit_id'=>'synthetic-tg-21','visit_at_unix'=>$now-20,'observed_at_unix'=>$now+25,'external_user_id'=>$user,'client_id'=>'17929400001234567890']);
$page=static fn(array $entries,string $after)=>['schema_version'=>1]+$scope+['after_cursor'=>$after,'next_cursor'=>end($entries)['source_cursor'],'observations'=>$entries];
$import->import($page([$entry],'0'),$now+30);
$r=MaxChannelConversionWorker::tick($p,$noIO,$noIO,$now+1000);
tgCheck($r['telegram']['resolved']===3,'late import resolves retained stages using verified ClientID');
$frozen=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll();
tgCheck(array_unique(array_column($frozen,'client_id'))===[$entry['client_id']],'exact ClientID preserved as decimal string');
tgCheck((int)$frozen[0]['identity_observation_id']>0&&$frozen[0]['identity_source_key']==='tgmini','frozen imported observation is auditable');
tgCheck($max->pending($now+1000)===[]&&$max->rows('ready',$now+1000,5)===[],'MAX lookup and upload selectors never consume Telegram rows');
tgCheck($max->claim((string)$frozen[0]['id'],$now+1000)===null,'wrong platform cannot claim a ready Telegram event');
$conflict=$entry;$conflict['source_cursor']='2';$conflict['visit_id']='synthetic-other-browser';$conflict['client_id']='17929400009999999999';$import->import($page([$conflict],'1'),$now+1001);
tgCheck(!$bridge->resolve($frozen[0],$now+1002),'resolved identity cannot change on a later observation');
tgCheck($p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll()===$frozen,'ready event remains immutable');
$api=new class {public array $posts=[];public int $gets=0;public bool $uncertain=false;
    public function upload($row,$now){$this->posts[]=$row;if($this->uncertain)return ['outcome'=>'unknown'];return ['outcome'=>'accepted','upload_id'=>(string)(900+count($this->posts)),'status'=>'UPLOADED'];}
    public function poll($id){$this->gets++;return ['id'=>$id,'status'=>'PROCESSED','source_quantity'=>1,'line_quantity'=>1];}
    public function findUnknown($row){$this->gets++;return null;}
};
$bridge->activate('send',$now+1010);
$r=MaxChannelConversionWorker::tick($p,$noIO,$api,$now+1011);
tgCheck($r['telegram']['accepted']===3&&count($api->posts)===3,'send mode uploads exactly three ordinary Telegram stages while MAX is off');
foreach($api->posts as$event){$csv=MetrikaOfflineClient::csv($event,$now+1011);tgCheck(str_starts_with($csv,"ClientId,Target,DateTime\n")&&!str_contains($csv,'Price'),'ordinary ClientID conversion has no fabricated money');}
MaxChannelConversionWorker::tick($p,$noIO,$api,$now+1012);tgCheck(count($api->posts)===3,'accepted events never POST twice');
$r=MaxChannelConversionWorker::tick($p,$noIO,$api,$now+2812);tgCheck($r['telegram']['processed']===3,'later GET confirms validated rows');
$bridge->activate('off',$now+2813);$again=$bridge->activate('collect',$now+2814);
tgCheck($again['telegram_activated_at_unix']===$activated['telegram_activated_at_unix']&&$again['telegram_signal_floor']===$activated['telegram_signal_floor'],'resume preserves original activation/floor');
// New independent people exercise ambiguity, missing identity, scope and legacy-marker fences.
foreach([22,23,24,25,26]as$id){$u=tgPerson($p,$id,$now+2900);$signals->incoming($u,'tg-fixture-'.$id.'-1','message',$now+2910);}
$p->exec("UPDATE conversations SET is_test=1 WHERE id=25");$p->exec("UPDATE customer_channels SET customer_id=999 WHERE id=26");
$p->exec("INSERT INTO conversation_events(conversation_id,event_type,created_at) VALUES(24,'metrika_max_bot_activity_start','2026-10-26 10:00:00')");
$r=MaxChannelConversionWorker::tick($p,$noIO,$noIO,$now+2920);
tgCheck(count($p->query("SELECT id FROM max_channel_conversion_outbox WHERE conversation_id IN (22,23)")->fetchAll())===2,'new legitimate subjects retained without known budget or manager filter');
tgCheck((int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE conversation_id IN (24,25,26)")->fetchColumn()===0,'legacy marker, test and mismatched customer scope cannot duplicate/export');
$a=$entry;$a['external_user_id']='8000000022';$a['source_cursor']='3';$a['visit_id']='synthetic-amb-a';$a['visit_at_unix']=$now+2800;$a['observed_at_unix']=$now+2910;
$b=$a;$b['source_cursor']='4';$b['visit_id']='synthetic-amb-b';$b['client_id']='17929400008888888888';$import->import($page([$a,$b],'2'),$now+2930);
$r=MaxChannelConversionWorker::tick($p,$noIO,$noIO,$now+3900);
$aRow=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE conversation_id=22')->fetch();
tgCheck($aRow['state']==='pending'&&$aRow['last_error']==='identity_ambiguous','two verified browsers require no arbitrary winner');
$missing=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE conversation_id=23')->fetch();tgCheck($missing['state']==='pending'&&$missing['last_error']==='identity_not_found','missing ClientID is not organic or low interest');
// An unverified / foreign counter / future entry cannot fill the missing identity.
foreach([['identity_status'=>'unverified'],['counter_id'=>'98615636'],['visit_at_unix'=>$now+5000]]as$n=>$change){
    $e=array_replace($entry,['counter_id'=>'98615635','external_user_id'=>'8000000023','source_cursor'=>(string)(5+$n),'visit_id'=>'synthetic-excluded-'.$n,'observed_at_unix'=>$now+3910],$change);
    $p->prepare('INSERT INTO advertising_visit_observations(project_key,source_key,platform,counter_id,source_cursor,visit_id,visit_at_unix,observed_at_unix,external_user_id,identity_status,client_id,yclid,campaign_id,region_id,payload_sha256,received_at_unix) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(['anytour','tgmini','telegram',$e['counter_id'],$e['source_cursor'],$e['visit_id'],$e['visit_at_unix'],$e['observed_at_unix'],$e['external_user_id'],$e['identity_status'],$e['client_id'],'','','',str_repeat('a',64),$now+3920]);
}
tgCheck(!$bridge->resolve($missing,$now+4000),'wrong counter/unverified/future visit cannot explain an earlier signal');
// Crash/uncertain POST keeps the existing durable unknown state, not a retry.
$p->exec("UPDATE max_channel_conversion_outbox SET state='ready',client_id='17929400001234567890',next_attempt_unix=0 WHERE conversation_id=23");
$bridge->activate('send',$now+4100);$api->uncertain=true;MaxChannelConversionWorker::tick($p,$noIO,$api,$now+4101);$posts=count($api->posts);
MaxChannelConversionWorker::tick($p,$noIO,$api,$now+6100);
tgCheck(count($api->posts)===$posts&&$p->query('SELECT state FROM max_channel_conversion_outbox WHERE conversation_id=23')->fetchColumn()==='unknown','uncertain Telegram POST is reconciled GET-only');
$bridge->activate('off',$now+6200);$calls=count($api->posts)+$api->gets;MaxChannelConversionWorker::tick($p,$noIO,$api,$now+10000);tgCheck(count($api->posts)+$api->gets===$calls,'off stops Telegram delivery without resetting receipts');
tgCheck($p->query('SELECT mode,activated_at_unix,message_cursor,goal_set,shared_goals_activated_at_unix,shared_goals_event_id,request_event_cursor FROM max_channel_attribution_settings')->fetch()===$before,'all Telegram work leaves MAX activation and cursors unchanged');
// Revoke a ready row's scope before upload: its previous resolution is not permission.
$p->exec("UPDATE max_channel_conversion_outbox SET state='ready',client_id='17929400001234567890',next_attempt_unix=0 WHERE conversation_id=22");
$p->exec('UPDATE conversations SET is_test=1 WHERE id=22');$bridge->activate('send',$now+10001);$posts=count($api->posts);
MaxChannelConversionWorker::tick($p,$noIO,$api,$now+10002);
tgCheck(count($api->posts)===$posts&&$p->query('SELECT last_error FROM max_channel_conversion_outbox WHERE conversation_id=22')->fetchColumn()==='identity_scope_unavailable','scope is rechecked after claim and before POST');
// Same worker, independent platform selectors and lookup provenance.
$p->exec("UPDATE max_channel_attribution_settings SET mode='collect'");
$p->exec("INSERT INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix) VALUES ('anytour',7,'1234567890123456789','98615635','max_bot_activity_start','max_bot_activity_start',".($now+9900).')');
$lookup=new class {public array $users=[];public function lookup($counter,$users){$this->users=$users;return ['1234567890123456789'=>['external_user_id'=>'1234567890123456789','client_id'=>'999111222333','record_id'=>'9','observed_at_unix'=>null]];}};
$r=MaxChannelConversionWorker::tick($p,$lookup,$api,$now+10003);
tgCheck($lookup->users===['1234567890123456789'],'TG identities never go to the MAX tracker');
tgCheck($r['resolved']===1&&$p->query("SELECT platform FROM max_channel_conversion_outbox WHERE conversation_id=7")->fetchColumn()==='max','existing MAX resolution policy and default provenance preserved');
$p->exec('DELETE FROM conversations WHERE id=1');$p->exec('DELETE FROM customer_channels WHERE id=1');$owner=tgPerson($p,1,$now+10010);$signals->incoming($owner,'tg-fixture-1-1','message',$now+10020);
tgCheck(TelegramDialogueConversions::queueIfEligible($owner,'max_bot_activity_start',['conversation_id'=>'1'])===false,'owner/test dialogue cannot enter advertising delivery');
echo 'PASS '.$checks.' Telegram dialogue conversion checks ('.$p->getAttribute(PDO::ATTR_DRIVER_NAME).')'.PHP_EOL;
