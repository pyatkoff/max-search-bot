<?php
declare(strict_types=1);

require_once __DIR__.'/../services/ManagerMessageMediaService.php';
require_once __DIR__.'/../integrations/MaxIncomingAdapter.php';
require_once __DIR__.'/../integrations/TelegramIncomingAdapter.php';

foreach(['HOST','NAME','USER','PASS'] as $key)define('CONVERSATION_DB_'.$key,'local-fixture-only');
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
$pdo->exec('CREATE TABLE messages(id INTEGER PRIMARY KEY,channel TEXT,direction TEXT,sender_type TEXT,text TEXT,metadata_json TEXT)');
$failures=0;$checks=0;
function actionCheck(string $name,$actual,$expected):void{
    global $failures,$checks;$checks++;
    if($actual===$expected){echo "PASS {$name}\n";return;}
    $failures++;echo "FAIL {$name}\n";
    echo '  expected '.json_encode($expected,JSON_UNESCAPED_UNICODE).' actual '.json_encode($actual,JSON_UNESCAPED_UNICODE)."\n";
}
function actionRow(int $id,string $channel,string $text,$meta,string $direction='inbound',string $sender='customer'):array{
    global $pdo;
    $json=is_array($meta)?json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):$meta;
    $pdo->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?)')->execute([$id,$channel,$direction,$sender,$text,$json]);
    return ['id'=>$id,'direction'=>$direction,'sender_type'=>$sender,'text'=>$text,'created_at'=>'2026-08-24 14:20:00'];
}

$maxCallback=MaxIncomingAdapter::fromUpdate(['update_type'=>'message_callback','callback'=>['callback_id'=>'callback-one','payload'=>'manager_after_tours','user'=>['user_id'=>7]]]);
$maxLiteral=MaxIncomingAdapter::fromUpdate(['update_type'=>'message_created','message'=>['sender'=>['user_id'=>7],'body'=>['mid'=>'message-one','text'=>'manager_after_tours']]]);
$tgCallback=TelegramIncomingAdapter::fromUpdate(['callback_query'=>['id'=>'callback-two','data'=>'phone_manual','from'=>['id'=>7]]]);
$tgLiteral=TelegramIncomingAdapter::fromUpdate(['message'=>['message_id'=>8,'text'=>'phone_manual','from'=>['id'=>7]]]);
actionCheck('MAX adapter distinguishes callback from literal text',[$maxCallback['type'],$maxLiteral['type']],['callback','message']);
actionCheck('Telegram adapter distinguishes callback from literal text',[$tgCallback['type'],$tgLiteral['type']],['callback','message']);

$rows=[
    actionRow(1,'max','manager_after_tours',['type'=>$maxCallback['type'],'username'=>'private-name','token'=>'private-token']),
    actionRow(2,'max','manager_after_tours',['type'=>$maxLiteral['type']]),
    actionRow(3,'telegram','phone_manual',['type'=>$tgCallback['type']]),
    actionRow(4,'telegram','phone_manual',['type'=>$tgLiteral['type']]),
    actionRow(5,'website','g1_1234abcd_manager_request',['type'=>'callback']),
    actionRow(6,'max','g1_1234abcd_phone_manual',['type'=>'callback']),
    actionRow(7,'telegram','unknown_<img src=x onerror=alert(1)>',['type'=>'callback']),
    actionRow(8,'max','phone_manual',null),
    actionRow(9,'max','manager_after_tours','{broken'),
    actionRow(10,'max','phone_manual',['type'=>'Callback']),
    actionRow(11,'max','phone_manual',['type'=>'callback'],'outbound','customer'),
    actionRow(12,'max','phone_manual',['type'=>'callback'],'inbound','manager'),
    actionRow(13,'telegram','phone_manual',['type'=>'callback','linked_message'=>['type'=>'reply','mid'=>'99','text'=>'Цитата'],'attachments'=>[['type'=>'image','provider'=>'telegram','telegram_file_id'=>'private-file']]]),
    actionRow(14,'max','g1_bad_phone_manual',['type'=>'callback']),
];
$storedBefore=$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll();
$hydrated=ManagerMessageMediaService::hydrate($rows);
actionCheck('hydration preserves IDs, transcript order and exact original text',array_map(static fn($r)=>[$r['id'],$r['text'],$r['created_at']],$hydrated),array_map(static fn($r)=>[$r['id'],$r['text'],$r['created_at']],$rows));
actionCheck('hydration never writes stored messages',$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll(),$storedBefore);
actionCheck('confirmed manager-after-tours callback is projected',$hydrated[0]['service_action']??null,['type'=>'callback','label'=>'Клиент запросил менеджера после подбора туров','raw_payload'=>'manager_after_tours']);
actionCheck('manual phone is a choice, not a received phone',$hydrated[2]['service_action']??null,['type'=>'callback','label'=>'Клиент выбрал ручной ввод телефона','raw_payload'=>'phone_manual']);
actionCheck('generated request uses canonical callback codec',$hydrated[4]['service_action']??null,['type'=>'callback','label'=>'Клиент запросил менеджера','raw_payload'=>'g1_1234abcd_manager_request']);
actionCheck('generated manual phone keeps exact raw payload',$hydrated[5]['service_action']['raw_payload']??null,'g1_1234abcd_phone_manual');
actionCheck('unknown confirmed callback remains neutral',$hydrated[6]['service_action']??null,['type'=>'callback','label'=>'Клиент нажал кнопку','raw_payload'=>'unknown_<img src=x onerror=alert(1)>']);
foreach([1,3,7,8,9,10,11] as $index)actionCheck('unconfirmed/different sender text stays a message #'.($index+1),isset($hydrated[$index]['service_action']),false);
actionCheck('typed callback keeps reply context',$hydrated[12]['linked_message']??null,['type'=>'reply','mid'=>'99','text'=>'Цитата']);
actionCheck('typed callback keeps protected media URL',$hydrated[12]['attachments'][0]['url']??null,'media-file.php?message_id=13&attachment=0');
actionCheck('malformed generation is not guessed',$hydrated[13]['service_action']['label']??null,'Клиент нажал кнопку');
actionCheck('projection exposes no metadata credentials',strpos(json_encode($hydrated[0]),'private-')===false,true);
echo "TOTAL {$checks} | FAIL {$failures}\n";
exit($failures?1:0);
