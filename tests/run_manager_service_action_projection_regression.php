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
$labelCases = [
    'show_tours'=>'Нажата кнопка «Показать туры»',
    'g1_82fd3763_show_tours'=>'Нажата кнопка «Показать туры»',
    'generation_show_tours'=>'Клиент нажал кнопку',
    'back_check'=>'Нажата кнопка возврата к параметрам поездки',
    'back_pick_country'=>'Нажата кнопка возврата к выбору страны',
    'edit_date'=>'Нажата кнопка изменения даты поездки',
    'pick_city_other'=>'Нажата кнопка «Другой город»',
    'pick_city_1'=>'Нажата кнопка выбора города',
    'pick_city_99'=>'Нажата кнопка «Без перелёта»',
    'pick_city_01'=>'Клиент нажал кнопку',
    'pick_country_4'=>'Нажата кнопка выбора страны',
    'pick_country_other'=>'Нажата кнопка «Другая страна»',
    'pick_country_0'=>'Клиент нажал кнопку',
    'adults_2'=>'Нажата кнопка «2 взрослых»',
    'adults_6'=>'Нажата кнопка «6 взрослых»',
    'adults_7'=>'Клиент нажал кнопку',
    'adults_02'=>'Клиент нажал кнопку',
    'child_0'=>'Нажата кнопка «Без детей»',
    'child_3'=>'Нажата кнопка «3 ребёнка»',
    'star_4'=>'Нажата кнопка категории отеля «от 4★»',
    'star_1'=>'Нажата кнопка категории отеля «Не важно»',
    'star_0'=>'Клиент нажал кнопку',
    'meal_7'=>'Нажата кнопка питания «Всё включено»',
    'meal_3'=>'Нажата кнопка питания «Только завтраки»',
    'meal_4'=>'Нажата кнопка питания «Завтрак + ужин»',
    'meal_5'=>'Нажата кнопка питания «Полный пансион»',
    'meal_999'=>'Нажата кнопка питания «Не важно»',
    'meal_6'=>'Клиент нажал кнопку',
    'nights_6_8'=>'Нажата кнопка «6–8 ночей»',
    'nights_9_11'=>'Нажата кнопка «9–11 ночей»',
    'nights_12_14'=>'Нажата кнопка «12–14 ночей»',
    'nights_other'=>'Нажата кнопка ввода своего количества ночей',
    'nights_0_999'=>'Клиент нажал кнопку',
    'pick_date_04.11.2026'=>'Нажата кнопка выбора даты: 04.11.2026',
    'pick_date_29.02.2028'=>'Нажата кнопка выбора даты: 29.02.2028',
    'pick_date_29.02.2027'=>'Клиент нажал кнопку',
    'pick_date_31.11.2026'=>'Клиент нажал кнопку',
    'month_change_11.2026'=>'Нажата кнопка календаря: 11.2026',
    'month_change_13.2026'=>'Клиент нажал кнопку',
    'month_change_01.0000'=>'Клиент нажал кнопку',
    'tour_like_123_0123456789abcdef01234567'=>'Нажата кнопка «Нравится»',
    'g1_82fd3763_tour_like_123_0123456789abcdef01234567'=>'Нажата кнопка «Нравится»',
    'tour_like_synthetic'=>'Нажата кнопка «Нравится»',
    'tours_found'=>'Нажата кнопка «Нашёл подходящие варианты»',
    "adults_2\n"=>'Клиент нажал кнопку',
];
$privateLikePayloads=[
    'tour_like_123_0123456789abcdef01234567',
    'g1_82fd3763_tour_like_123_0123456789abcdef01234567',
    'tour_like_synthetic',
];
$caseRows=[];
foreach ($labelCases as $payload=>$label) {
    $id=count($rows)+1;
    $caseRows[]=[$id,$payload,$label];
    $rows[]=actionRow($id,'max',$payload,['type'=>'callback']);
    $rows[]=actionRow($id+1,'telegram',$payload,['type'=>'message']);
}
$storedBefore=$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll();
$hydrated=ManagerMessageMediaService::hydrate($rows);
$expectedPublicRows=$rows;
foreach ($caseRows as [$id,$payload,$label]) {
    if (in_array($payload,$privateLikePayloads,true)) $expectedPublicRows[$id-1]['text']=$label;
}
actionCheck('hydration preserves IDs order and text except private callback token redaction',array_map(static fn($r)=>[$r['id'],$r['text'],$r['created_at']],$hydrated),array_map(static fn($r)=>[$r['id'],$r['text'],$r['created_at']],$expectedPublicRows));
actionCheck('hydration never writes stored messages',$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll(),$storedBefore);
actionCheck('confirmed manager-after-tours callback describes the click',$hydrated[0]['service_action']??null,['type'=>'callback','label'=>'Нажата кнопка «Нужна помощь с подбором»','raw_payload'=>'manager_after_tours']);
actionCheck('manual phone is a click, not a received phone',$hydrated[2]['service_action']??null,['type'=>'callback','label'=>'Нажата кнопка ручного ввода телефона','raw_payload'=>'phone_manual']);
actionCheck('generated request uses canonical callback codec',$hydrated[4]['service_action']??null,['type'=>'callback','label'=>'Нажата кнопка «Подобрать с менеджером»','raw_payload'=>'g1_1234abcd_manager_request']);
actionCheck('generated manual phone keeps exact raw payload',$hydrated[5]['service_action']['raw_payload']??null,'g1_1234abcd_phone_manual');
actionCheck('unknown confirmed callback remains neutral',$hydrated[6]['service_action']??null,['type'=>'callback','label'=>'Клиент нажал кнопку','raw_payload'=>'unknown_<img src=x onerror=alert(1)>']);
foreach([1,3,7,8,9,10,11] as $index)actionCheck('unconfirmed/different sender text stays a message #'.($index+1),isset($hydrated[$index]['service_action']),false);
actionCheck('typed callback keeps reply context',$hydrated[12]['linked_message']??null,['type'=>'reply','mid'=>'99','text'=>'Цитата']);
actionCheck('typed callback keeps protected media URL',$hydrated[12]['attachments'][0]['url']??null,'media-file.php?message_id=13&attachment=0');
actionCheck('malformed generation is not guessed',$hydrated[13]['service_action']['label']??null,'Клиент нажал кнопку');
actionCheck('projection exposes no metadata credentials',strpos(json_encode($hydrated[0]),'private-')===false,true);
foreach ($caseRows as [$id,$payload,$label]) {
    $redacted=in_array($payload,$privateLikePayloads,true);
    actionCheck('recorded callback has a bounded display projection #'.$id,$hydrated[$id-1]['service_action']??null,['type'=>'callback','label'=>$label,'raw_payload'=>$redacted?'':$payload]);
    actionCheck('same payload typed by a tourist stays literal #'.($id+1),isset($hydrated[$id]['service_action']),false);
    if ($redacted) {
        $publicJson=json_encode($hydrated[$id-1],JSON_THROW_ON_ERROR);
        actionCheck('confirmed like JSON exposes no callback payload or token #'.$id,
            !str_contains($publicJson,'tour_like_') && !str_contains($publicJson,'0123456789abcdef01234567')
            && !str_contains($publicJson,'82fd3763'),true);
    }
}
$previewMessage=['direction'=>'inbound','sender_type'=>'customer','text'=>'g1_82fd3763_show_tours','metadata_json'=>'{"type":"callback"}'];
actionCheck('shared presenter can read recorded metadata without a database',ManagerCallbackPresentationService::fromMessage($previewMessage),['type'=>'callback','label'=>'Нажата кнопка «Показать туры»','raw_payload'=>'g1_82fd3763_show_tours']);
actionCheck('explicit metadata remains authoritative',ManagerCallbackPresentationService::fromMessage($previewMessage,['type'=>'message']),null);
actionCheck('missing metadata cannot turn a text match into a callback',ManagerCallbackPresentationService::fromMessage(['direction'=>'inbound','sender_type'=>'customer','text'=>'show_tours']),null);
echo "TOTAL {$checks} | FAIL {$failures}\n";
exit($failures?1:0);
