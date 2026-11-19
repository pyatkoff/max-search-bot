<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/repair_max_webhook_fixture.php';
require_once dirname(__DIR__).'/services/AppleService39TelegramIngress.php';
require_once dirname(__DIR__).'/services/ManagerConversationService.php';

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
