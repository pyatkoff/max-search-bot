<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/repair_max_webhook_fixture.php';
require_once dirname(__DIR__).'/services/AppleService39TelegramIngress.php';
require_once dirname(__DIR__).'/services/ManagerConversationService.php';
define('PROJECT_FUNNEL_STATUS_FIXTURE_ONLY',true);
require_once __DIR__.'/run_project_funnel_conversions_regression.php';

function appleTgCheck(string $label,bool $ok):void{
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    echo "PASS $label\n";
}
$pdo=repairFixture();
$pdo->exec("INSERT INTO conversation_sources VALUES(23,2,'telegram:appleservice39-main','Apple Telegram','telegram',1,'manager')");
$binding=['project_key'=>'appleservice39','source_key'=>'telegram:appleservice39-main','source_id'=>23,
    'webhook_secret'=>'fixture-only-webhook','bot_id'=>'123456789','bot_username'=>'Appleservice39_bot'];
appleTgCheck('dedicated Telegram webhook rejects empty or cross-project bindings',
    AppleService39TelegramIngress::authenticated($binding,'fixture-only-webhook')
    &&!AppleService39TelegramIngress::authenticated($binding,'')
    &&!AppleService39TelegramIngress::authenticated(array_replace($binding,['project_key'=>'anytour']),'fixture-only-webhook')
    &&!AppleService39TelegramIngress::authenticated(array_replace($binding,['bot_username'=>'Any_tour_bot']),'fixture-only-webhook'));
$sent=[];$alerts=[];$fail=false;
$factory=static function(array $owner,string $sender)use(&$sent,&$fail):TelegramMessengerAdapter{
    if($owner['project_key']!=='appleservice39'||$owner['channel']!=='telegram'||$sender!=='system')throw new RuntimeException('wrong_fixture_route');
    return new TelegramMessengerAdapter(static function(string $method,array $payload)use(&$sent,&$fail){
        $sent[]=[$method,$payload];
        return $fail?false:['ok'=>true,'result'=>['message_id'=>7000+count($sent)]];
    },'system',false);
};
$notify=static function($id,$text,$mid)use(&$alerts){$alerts[]=[$id,$mid];};
$update=static function(int $user,int $mid,string $text,array $extra=[]):array{
    return ['update_id'=>1000+$mid,'message'=>array_replace(['message_id'=>$mid,'chat'=>['id'=>$user,'type'=>'private'],
        'from'=>['id'=>$user,'is_bot'=>false,'first_name'=>'Fixture'],'text'=>$text],$extra)];
};
$run=static fn(array $body):int=>AppleService39TelegramIngress::handle($binding,$body,$factory,$notify);
$one=static function(int $user)use($pdo):array{
    $q=$pdo->prepare("SELECT * FROM conversations WHERE project_key='appleservice39' AND channel='telegram' AND external_chat_id=? ORDER BY id DESC LIMIT 1");
    $q->execute([(string)$user]);return $q->fetch(PDO::FETCH_ASSOC)?:[];
};
$count=static function(string $sql)use($pdo):int{return (int)$pdo->query($sql)->fetchColumn();};
foreach(['channel_post'=>['chat'=>['type'=>'channel']],'callback_query'=>['id'=>'fixture']]as$type=>$data){
    appleTgCheck('unrelated Telegram update ignored '.$type,$run([$type=>$data])===200);
}
appleTgCheck('group message and mismatched private identity cannot enter this project',
    $run($update(101,1,'/start',['chat'=>['id'=>-901,'type'=>'group']]))===200
    &&$run($update(101,1,'/start',['chat'=>['id'=>102,'type'=>'private']]))===400&&!$one(101)&&!$sent);

foreach(['iphone-18','ipad','macbook','apple-watch']as$n=>$device){
    $user=210+$n;$code='as39_device-'.$device;$start=$update($user,10,'/start@Appleservice39_bot '.$code);
    $entry=AppleService39PilotCatalog::landingEntryForPost('device-'.$device);
    $before=count($sent);
    appleTgCheck('real Telegram /start recognized and handed off '.$device,$entry!==null&&$run($start)===200);
    $c=$one($user);$meta=json_decode($c['metadata_json'],true);$context=ManagerConversationService::repairEntryContext($c);
    appleTgCheck('visible exact repair and durably recorded contextual reply '.$device,
        $c['status']==='waiting_manager'&&(int)$c['source_id']===23&&$meta['telegram_bot_id']===$binding['bot_id']
        &&$meta['repair_context']['entry_code']===$code&&$context['model']===$entry['model']&&$context['service']===$entry['service']
        &&$context['reply']['state']==='delivered'&&$context['reply']['text']===AppleService39PilotCatalog::responseText($entry,'AppleService39')
        &&$context['post_url']===''&&count($sent)===$before+1&&ManagerConversationService::requestKind($c)==='device_repair');
    $history=$count('SELECT COUNT(*) FROM messages');$push=count($alerts);
    appleTgCheck('replay leaves one inbound, one answer and one handoff '.$device,
        $run($start)===200&&$count('SELECT COUNT(*) FROM messages')===$history&&count($sent)===$before+1&&count($alerts)===$push);
}
$c=$one(211);$id=(int)$c['id'];$before=count($sent);
appleTgCheck('repair receipt remains valid with native databases rewriting JSON whitespace',
    (function()use($pdo,$id,$run,$update,&$sent,$before){
        $q=$pdo->prepare("SELECT id,payload_json FROM conversation_events WHERE event_type='repair_telegram_reply' AND conversation_id=?");
        $q->execute([$id]);$e=$q->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare('UPDATE conversation_events SET payload_json=? WHERE id=?')->execute([json_encode(json_decode($e['payload_json'],true),JSON_PRETTY_PRINT),$e['id']]);
        return $run($update(211,10,'/start as39_device-ipad'))===200&&count($sent)===$before;
    })());
appleTgCheck('unrecognized start keeps known context and never stores its opaque argument',
    $run($update(211,11,'/start private-canary'))===200&&count($sent)===$before
    &&json_decode($one(211)['metadata_json'],true)['repair_context']['model']==='iPad'
    &&$count("SELECT COUNT(*) FROM messages WHERE text LIKE '%private-canary%'")===0);
appleTgCheck('reopening the same device in a waiting conversation keeps one answer and context',
    $run($update(211,13,'/start as39_device-ipad'))===200&&count($sent)===$before
    &&ManagerConversationService::repairEntryContext($one(211))['event_id']===ManagerConversationService::repairEntryContext($c)['event_id']);
$pdo->exec("UPDATE conversations SET status='manager',manager_id=55 WHERE id=$id");
appleTgCheck('ordinary customer reply stays with assigned manager without another automated answer',
    $run($update(211,12,'Не заряжается'))===200&&(int)$one(211)['manager_id']===55&&count($sent)===$before);
appleTgCheck('private repair projection rejects another project and another source',
    ManagerConversationService::repairEntryContext(array_replace($c,['project_key'=>'repair_two']))===null
    &&ManagerConversationService::repairEntryContext(array_replace($c,['source_id'=>21]))===null);
$pdo->exec("CREATE TABLE appleservice39_pilot_campaigns(campaign_key TEXT,project_key TEXT,bot_key TEXT,source_id INTEGER,channel_id TEXT,connection_sha256 TEXT);
    CREATE TABLE appleservice39_pilot_operations(campaign_key TEXT,post_key TEXT,operation TEXT,state TEXT,external_message_id TEXT,public_url TEXT)");
$campaign='appleservice39-telegram-device-v1';$channel='-100900001';$url='https://t.me/appleservice39/37';
$pdo->prepare('INSERT INTO appleservice39_pilot_campaigns VALUES(?,?,?,?,?,?)')
    ->execute([$campaign,'appleservice39','Appleservice39_bot',23,$channel,hash('sha256',$binding['bot_id'].'|'.$channel.'|23')]);
$pdo->prepare('INSERT INTO appleservice39_pilot_operations VALUES(?,?,?,?,?,?)')->execute([$campaign,'device-ipad','create','unknown','37',$url]);
appleTgCheck('uncertain publication cannot create a canonical post in Manager2',ManagerConversationService::repairEntryContext($c)['post_url']==='');
$pdo->exec("UPDATE appleservice39_pilot_operations SET state='verified'");
appleTgCheck('the exact verified project and bot post becomes visible in Manager2',ManagerConversationService::repairEntryContext($c)['post_url']===$url);
$pdo->exec("UPDATE appleservice39_pilot_campaigns SET connection_sha256='different_bot'");
appleTgCheck('a verified post from another bot is ignored',ManagerConversationService::repairEntryContext($c)['post_url']==='');
$catalogueCampaign = AppleService39TelegramCatalogue::CAMPAIGN;
$catalogueKey = 'repair-iphone-x-battery-replacement';
$catalogueUrl = 'https://t.me/appleservice39/38';
$pdo->prepare('INSERT INTO appleservice39_pilot_campaigns VALUES(?,?,?,?,?,?)')
    ->execute([$catalogueCampaign,'appleservice39','Appleservice39_bot',23,$channel,hash('sha256',$binding['bot_id'].'|'.$channel.'|23')]);
$pdo->prepare('INSERT INTO appleservice39_pilot_operations VALUES(?,?,?,?,?,?)')
    ->execute([$catalogueCampaign,$catalogueKey,'create','unknown','38',$catalogueUrl]);
$catalogueBefore = count($sent);
appleTgCheck('unconfirmed catalogue entry remains a general inquiry',
    $run($update(980,1,'/start as39_'.$catalogueKey))===200
    &&ManagerConversationService::repairEntryContext($one(980))===null);
$pdo->prepare("UPDATE appleservice39_pilot_operations SET state='verified' WHERE campaign_key=?")->execute([$catalogueCampaign]);
appleTgCheck('verified catalogue post hands off exact model, repair and post URL',
    $run($update(981,1,'/start as39_'.$catalogueKey))===200);
$catalogueContext = ManagerConversationService::repairEntryContext($one(981));
appleTgCheck('Manager2 shows the verified native URL and battery replacement for iPhone X',
    $catalogueContext['post_url']===$catalogueUrl && $catalogueContext['model']==='iPhone X'
    &&$catalogueContext['service']==='Замена аккумулятора' &&$catalogueContext['reply']['state']==='delivered'
    &&$one(981)['status']==='waiting_manager' &&count($sent)===$catalogueBefore+2);

$pdo->prepare("UPDATE appleservice39_pilot_operations SET external_message_id='12',public_url='https://t.me/appleservice39/12' WHERE campaign_key=?")->execute([$catalogueCampaign]);
    $pdo->exec("CREATE TABLE advertising_visit_cursors(project_key TEXT,source_key TEXT,platform TEXT,counter_id TEXT,last_cursor TEXT DEFAULT '0',PRIMARY KEY(project_key,source_key,platform,counter_id));
    CREATE TABLE advertising_visit_observations(id INTEGER PRIMARY KEY AUTOINCREMENT,project_key TEXT,source_key TEXT,platform TEXT,counter_id TEXT,source_cursor TEXT,visit_id TEXT,visit_at_unix INTEGER,observed_at_unix INTEGER,external_user_id TEXT,identity_status TEXT,client_id TEXT,yclid TEXT,campaign_id TEXT,region_id TEXT,payload_sha256 TEXT,received_at_unix INTEGER,post_ref TEXT DEFAULT '',offer_ref TEXT DEFAULT '',context_json TEXT,UNIQUE(project_key,source_key,platform,counter_id,source_cursor))");

$paidBinding=$binding+['bot_token'=>'fixture-only-bot-token'];
$paidAt=time();
$paidOffer='as39-tg-'.$catalogueKey;
$paidStart='18446744073709551614_region_213_campaign_123_ctx1_'.ProjectFunnelLaunch::context($catalogueKey,$paidOffer);
$sign=static function(int $user,string $start,int $at,string $token):array{
    $data=['auth_date'=>(string)$at,'query_id'=>'fixture-'.$user,'start_param'=>$start,'user'=>json_encode(['id'=>$user],JSON_THROW_ON_ERROR)];
    $lines=[];
    foreach($data as$key=>$value)$lines[]=$key.'='.$value;
    $data['hash']=hash_hmac('sha256',implode("\n",$lines),hash_hmac('sha256',$token,'WebAppData',true));
    return ['init_data'=>http_build_query($data,'','&',PHP_QUERY_RFC3986),'start_param'=>$start,'client_id'=>'12345678900012345678'];
};
$paidUser=983;
$paidInput=$sign($paidUser,$paidStart,$paidAt,$paidBinding['bot_token']);
$receipt=AppleService39TelegramFunnel::capture($pdo,$paidBinding,$paidInput,$paidAt);
$duplicateReceipt=AppleService39TelegramFunnel::capture($pdo,$paidBinding,$paidInput,$paidAt);
appleTgCheck('paid Telegram capture durably saves the exact post and is idempotent',
    $receipt['redirect_url']==='https://t.me/appleservice39/12'&&$receipt['offer_id']===$paidOffer
    &&str_starts_with($receipt['receipt']['visit_id'],'tg1_')&&$duplicateReceipt['receipt']['reused']
    &&$count("SELECT COUNT(*) FROM advertising_visit_observations WHERE platform='telegram' AND yclid='18446744073709551614'")===1);
$paidFeed=AppleService39TelegramFunnel::feed($pdo,$paidBinding);
appleTgCheck('Telegram feed includes only verified destinations and keeps original Direct macros',
    str_contains($paidFeed,$paidOffer.',https://t.me/Appleservice39_bot?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_')
    &&!str_contains($paidFeed,'https://t.me/appleservice39/12')&&!str_contains($paidFeed,'as39-tg-device-ipad'));
$paidBotStart=$update($paidUser,1,'/start as39_'.$catalogueKey,['date'=>$paidAt]);
$adCount=static fn()=> $count("SELECT COUNT(*) FROM conversation_events WHERE event_type='apple_telegram_ad_entry'");
appleTgCheck('matching verified user and post bind once to the existing Telegram conversation',
    $run($paidBotStart)===200&&$adCount()===1&&$one($paidUser)['status']==='waiting_manager'
    &&$run($paidBotStart)===200&&$adCount()===1);
$event=$pdo->query("SELECT payload_json FROM conversation_events WHERE event_type='apple_telegram_ad_entry'")->fetchColumn();
appleTgCheck('bound visit is the exact durably saved click',json_decode($event,true)['visit_id']===$receipt['receipt']['visit_id']);
appleTgCheck('another Telegram user cannot inherit the paid visit',
    $run($update(984,1,'/start as39_'.$catalogueKey,['date'=>$paidAt]))===200&&$adCount()===1);

funnelConversionSchema($pdo);
$pdo->exec("CREATE TABLE project_telegram_bot_connections(project_id INTEGER,bot_id TEXT,webhook_state TEXT);
    INSERT INTO project_telegram_bot_connections VALUES(2,'123456789','verified')");
$forecastBinding=AppleService39TelegramConnection::forecastBinding($pdo);
appleTgCheck('forecast binding uses the verified Telegram bot and source without credentials',
    $forecastBinding==['project_key'=>'appleservice39','bot_id'=>'123456789','source_key'=>'telegram:appleservice39-main','source_id'=>23]);
$now=time();
$connection=ProjectMaxConnection::resolve('appleservice39');
$settings=ProjectFunnelSettings::load($connection);
$policy=ProjectFunnelConversions::policy($settings);
$collect=static fn()=>ProjectFunnelConversions::collect($pdo,$connection,$settings,$policy,$now,$forecastBinding);
$storedEvent=json_decode($event,true);
$replaceEvent=static function(array $payload)use($pdo):void{
    $pdo->prepare("UPDATE conversation_events SET payload_json=? WHERE event_type='apple_telegram_ad_entry'")
        ->execute([json_encode($payload,JSON_THROW_ON_ERROR)]);
};
$replaceEvent(array_diff_key($storedEvent,['message_id'=>true]));
appleTgCheck('an older Telegram attribution without a native start receipt is held',$collect()===0);
$replaceEvent(array_replace($storedEvent,['message_id'=>'999']));
appleTgCheck('a Telegram attribution cannot borrow another native message',$collect()===0);
$replaceEvent($storedEvent);
$pdo->exec("UPDATE conversations SET is_test=1 WHERE external_chat_id='983'");
appleTgCheck('marked Telegram test conversations cannot enter the outbox',$collect()===0);
$pdo->exec("UPDATE conversations SET is_test=0,lead_stage_key='won' WHERE external_chat_id='983'");
appleTgCheck('a recorded sale is not entered as a Telegram forecast',$collect()===0);
$pdo->exec("UPDATE conversations SET lead_stage_key='new' WHERE external_chat_id='983'");
appleTgCheck('exact paid Telegram repair collects once without creating a second queue',$collect()===1&&$collect()===0);
$outbox=new MaxChannelConversionOutbox($pdo);
$row=$outbox->projectRows($policy,'pending',$now)[0];
$identity=ProjectFunnelConversions::identity($pdo,$row,$settings,$now,$binding);
appleTgCheck('Telegram forecast uses the verified 3500 RUB repair and the approved 20 percent',
    $row['platform']==='telegram'&&$row['transport_target']==='predict'&&$row['value_rub']==='700.00'
    &&$identity['visit_id']===$receipt['receipt']['visit_id']);
appleTgCheck('an unbound bot or altered forecast cannot resolve the Telegram row',
    ProjectFunnelConversions::identity($pdo,$row,$settings,$now,array_replace($binding,['bot_id'=>'7777777']))===null
    &&ProjectFunnelConversions::identity($pdo,array_replace($row,['value_rub'=>'701.00']),$settings,$now,$binding)===null);
$outbox->resolveProject($row,$identity,$now);
$ready=$outbox->projectRows($policy,'ready',$now)[0];
$token=$outbox->claimProject($ready,$now);
appleTgCheck('Telegram row claims the existing outbox exactly once',
    $token!==null&&$outbox->claimProject($ready,$now)===null&&$ready['client_id']===$paidInput['client_id']);
$requests=[];
$api=new MetrikaOfflineClient($policy['counter_id'],'isolated-test-token',
    static function(string $method,string $url,?string $body)use(&$requests):array{
        $requests[]=[$method,$url,$body];
        return ['http'=>200,'body'=>json_encode(['uploading'=>['id'=>101,'status'=>'UPLOADED']])];
    },null,$policy);
$reply=$api->upload($ready,$now);
$outbox->finish((string)$ready['id'],$token,$reply,$now);
appleTgCheck('only the existing goal and price reach the isolated Telegram upload',
    $reply['outcome']==='accepted'&&count($requests)===1&&$requests[0][0]==='POST'
    &&str_contains($requests[0][2],$paidInput['client_id'].',predict,')
    &&str_contains($requests[0][2],',700.00,RUB')&&$collect()===0);
$outbox->status($ready,['id'=>'101','status'=>'PROCESSED','source_quantity'=>1,'line_quantity'=>1],$now);
appleTgCheck('the original Telegram row retains the processed receipt without another send',
    $pdo->query("SELECT state FROM max_channel_conversion_outbox")->fetchColumn()==='processed'
    &&count($requests)===1&&$collect()===0);
require_once dirname(__DIR__).'/services/ProjectFunnelStatusReader.php';
$report=(new ProjectFunnelStatusReader($pdo))->read($connection,$settings,$now);
appleTgCheck('saved Telegram attribution and processing are visible without exposing identifiers',
    $report['outbox']['telegram_approved_policy_states']['processed']===1
    &&$report['observations']['telegram_click_tags']['all_history']['distinct_yclids']===1
    &&$report['first_saved_forecast']['platform']==='telegram'
    &&$report['first_saved_forecast']['retained_processed_receipt']===true
    &&!str_contains(json_encode($report),$paidInput['client_id']));
$pdo->exec("UPDATE conversations SET status='closed' WHERE external_chat_id='983'");
appleTgCheck('a new conversation cannot charge the same paid visit again',
    $run($update($paidUser,2,'/start as39_'.$catalogueKey,['date'=>$paidAt]))===200&&$adCount()===1&&$collect()===0);

$before = count($sent);
$pdo->exec("UPDATE conversations SET status='closed' WHERE id=$id");
appleTgCheck('replay after close cannot create a new lead',$run($update(211,10,'/start@Appleservice39_bot as39_device-ipad'))===200
    &&(int)$one(211)['id']===$id&&$one(211)['status']==='closed'&&count($sent)===$before);
$start=$update(901,1,'/start as39_device-ipad');$fail=true;
appleTgCheck('uncertain provider reply stays unknown and is not resent on repeat',
    $run($start)===200&&ManagerConversationService::repairEntryContext($one(901))['reply']['state']==='unknown'
    &&$run($start)===200&&count($sent)===$before+1);
$fail=false;
appleTgCheck('first ordinary incoming message triggers a single neutral acknowledgement',
    $run($update(902,1,'Здравствуйте'))===200&&$run($update(902,2,'Есть вопрос'))===200
    &&count($sent)===$before+2&&ManagerConversationService::repairEntryContext($one(902))===null);
$rotated=array_replace($binding,['bot_id'=>'456789123']);
appleTgCheck('a changed bot cannot retarget the existing private conversation',
    AppleService39TelegramIngress::handle($rotated,$update(902,3,'private'),$factory,$notify)===503
    &&$count("SELECT COUNT(*) FROM messages WHERE conversation_id=".(int)$one(902)['id']." AND external_message_id='3'")===0);
appleTgCheck('inactive dedicated source prevents all recorded effects',
    AppleService39TelegramIngress::handle(array_replace($binding,['source_id'=>21]),$update(904,1,'hello'),$factory,$notify)===503&&!$one(904));
