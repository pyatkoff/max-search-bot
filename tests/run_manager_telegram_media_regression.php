<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$adapter=(string)file_get_contents($root.'/integrations/TelegramMessengerAdapter.php');
$outbound=(string)file_get_contents($root.'/services/ManagerOutboundService.php');
$maxFactory=(string)file_get_contents($root.'/services/ProjectMaxMessengerFactory.php');
$upload=(string)file_get_contents($root.'/manager/media-upload.php');
$ui=(string)file_get_contents($root.'/manager/assets/workspace-v2-media.js');
$passed=0;$failed=0;
function tgMediaCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

tgMediaCheck('Telegram adapter exposes manager media send boundary',strpos($adapter,'public function sendMedia(')!==false);
tgMediaCheck('Telegram media maps all normalized attachment types to Bot API methods',strpos($adapter,"'image'=>'sendPhoto'")!==false&&strpos($adapter,"'video'=>'sendVideo'")!==false&&strpos($adapter,"'audio'=>'sendAudio'")!==false&&strpos($adapter,"'file'=>'sendDocument'")!==false);
tgMediaCheck('Telegram media uses correct multipart fields',strpos($adapter,"'image'=>'photo'")!==false&&strpos($adapter,"'video'=>'video'")!==false&&strpos($adapter,"'audio'=>'audio'")!==false&&strpos($adapter,"'file'=>'document'")!==false&&strpos($adapter,'new CURLFile(')!==false);
tgMediaCheck('Telegram multipart transport sends raw POST fields instead of urlencoding file data',strpos($adapter,'CURLOPT_POSTFIELDS, $multipart ? $payload : http_build_query($payload)')!==false);
tgMediaCheck('Telegram media preserves optional caption HTML mode',strpos($adapter,"\$payload['caption']=\$text")!==false&&strpos($adapter,"\$payload['parse_mode']='HTML'")!==false);
tgMediaCheck('Telegram media records attachment metadata for Workspace history',strpos($adapter,"ConversationRecorder::outbound('telegram'")!==false&&strpos($adapter,"['attachments'=>[\$attachment]]")!==false&&strpos($adapter,"\$attachment['url']=trim(\$previewUrl)")!==false);
tgMediaCheck('manager outbound media supports MAX and Telegram only',strpos($outbound,"\$channel!=='max'&&\$channel!=='telegram'")!==false);
tgMediaCheck('manager outbound media keeps ownership guard',strpos($outbound,"(int)\$c['manager_id']!==\$managerId")!==false&&strpos($outbound,"(string)\$c['status']!=='manager'")!==false);
tgMediaCheck('manager outbound media selects non-mirroring adapters before exact conversation persistence',
    strpos($outbound,"\$channel==='max'?ProjectMaxMessengerFactory::forConversation(\$c,'manager')")!==false
    &&strpos($maxFactory,'new MaxMessengerAdapter(null, null, $senderType, null, false)')!==false
    &&strpos($maxFactory,'new MaxMessengerAdapter($send, $buttons, $senderType, $media, false, $edit)')!==false
    &&substr_count($outbound,"ProjectTelegramMessengerFactory::forConversation(\$c,'manager')")===2
    &&strpos($outbound,'ConversationRecorder::outboundForConversation($conversationId,$channel,$preview')!==false
    &&strpos($outbound,"if(\$channel==='max')")!==false&&strpos($outbound,'unresolvedSuspendedFailure')!==false);
tgMediaCheck('manager media lifecycle and manager-reply conversion remain shared',strpos($outbound,"['channel'=>\$channel")!==false&&strpos($outbound,'MetrikaConversionGoalService::managerReply($conversationId)')!==false);
tgMediaCheck('existing Workspace composer sends the same upload request for Telegram and MAX',strpos($ui,"fetch('media-upload.php'")!==false&&strpos($ui,"data.append('conversation_id'")!==false&&strpos($upload,'ManagerOutboundService::sendMedia')!==false);
tgMediaCheck('Telegram media captions have exact edit transport',strpos($adapter,'editMessageCaption')!==false&&strpos($adapter,"'message_id'=>\$messageId")!==false&&strpos($adapter,"'caption'=>\$text")!==false);

require_once $root.'/services/ProjectTelegramMessengerFactory.php';
ProjectConfig::resetForTests(['id'=>'anytour']);
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
$pdo->exec("CREATE TABLE projects (id INTEGER, project_key TEXT, is_active INTEGER);
    CREATE TABLE conversation_sources (id INTEGER, project_id INTEGER, channel TEXT, source_key TEXT, is_active INTEGER);
    CREATE TABLE customer_channels (id INTEGER, customer_id INTEGER, project_key TEXT, channel TEXT, external_chat_id TEXT, external_user_id TEXT);
    CREATE TABLE conversations (id INTEGER, project_key TEXT, source_id INTEGER, customer_id INTEGER, customer_channel_id INTEGER, channel TEXT, external_chat_id TEXT, metadata_json TEXT);
    INSERT INTO projects VALUES (1,'appleservice39',1),(2,'anytour',1);
    INSERT INTO conversation_sources VALUES (3,1,'telegram','telegram:appleservice39-main',1);
    INSERT INTO customer_channels VALUES (4,5,'appleservice39','telegram','101','101');
    INSERT INTO conversations VALUES (6,'appleservice39',3,5,4,'telegram','101','{\"telegram_bot_id\":\"202\"}')");
$row = ['id'=>6,'project_key'=>'appleservice39','source_id'=>3,'customer_id'=>5,'channel'=>'telegram','external_chat_id'=>'101'];
$binding = ['project_key'=>'appleservice39','source_key'=>'telegram:appleservice39-main','bot_id'=>'202','bot_token'=>'fixture-only'];
$resolve = new ReflectionMethod(ProjectTelegramMessengerFactory::class,'appleMessenger');
$resolve->setAccessible(true);
$scoped = $resolve->invoke(null,$row,$binding,'system');
$token = new ReflectionProperty(TelegramMessengerAdapter::class,'botToken');
$token->setAccessible(true);
$mirror = new ReflectionProperty(TelegramMessengerAdapter::class,'recordOutbound');
$mirror->setAccessible(true);
tgMediaCheck('verified private identity selects the saved bot and disables chat-only mirroring',
    $scoped instanceof TelegramMessengerAdapter && $token->getValue($scoped)===$binding['bot_token'] && !$mirror->getValue($scoped));
$calls = [];
$transport = new ReflectionProperty(TelegramMessengerAdapter::class,'sendCallable');
$transport->setAccessible(true);
if ($scoped !== null) $transport->setValue($scoped,static function($method,$payload) use (&$calls) {
    $calls[] = [$method,(string)$payload['chat_id']];
    return ['result'=>['message_id'=>41]];
});
tgMediaCheck('scoped adapter refuses a different recipient before network access',
    $scoped !== null && !$scoped->send('303','Synthetic text') && !$scoped->editCaption('303',1,'Synthetic caption'));
tgMediaCheck('scoped sends and edits address only the saved chat through a synthetic transport',
    $scoped !== null && $scoped->send('101','Synthetic text') && $scoped->editText('101',41,'Changed')
    && $scoped->editCaption('101',41,'Changed') && !$scoped->send('303','Other chat')
    && $calls===[['sendMessage','101'],['editMessageText','101'],['editMessageCaption','101']]);
if (class_exists('CURLFile')) {
    $calls = [];
    tgMediaCheck('multipart upload uses the same bound recipient',
        $scoped !== null && $scoped->sendMedia('101','file',__FILE__,'synthetic.txt','text/plain','Synthetic caption')
        && !$scoped->sendMedia('303','file',__FILE__,'synthetic.txt','text/plain','Other chat')
        && $calls===[['sendDocument','101']]);
}
tgMediaCheck('unknown projects and non-Telegram rows have no Telegram adapter',
    ProjectTelegramMessengerFactory::forConversation(array_replace($row,['project_key'=>'unknown']))===null
    && ProjectTelegramMessengerFactory::forConversation(array_replace($row,['channel'=>'max']))===null);
$legacy = ProjectTelegramMessengerFactory::forConversation(array_replace($row,['project_key'=>'anytour']));
tgMediaCheck('configured legacy project retains its default adapter without chat-only mirroring',
    $legacy instanceof TelegramMessengerAdapter && $token->getValue($legacy)===null && !$mirror->getValue($legacy));
foreach ([
    'inactive project'=>"UPDATE projects SET is_active=0 WHERE id=1",
    'foreign source'=>"UPDATE conversation_sources SET project_id=2",
    'inactive source'=>"UPDATE conversation_sources SET is_active=0",
    'wrong source'=>"UPDATE conversation_sources SET source_key='telegram:other'",
    'foreign identity'=>"UPDATE customer_channels SET project_key='anytour'",
    'other private user'=>"UPDATE customer_channels SET external_user_id='303'",
    'reassigned customer'=>"UPDATE conversations SET customer_id=9",
    'missing saved bot'=>"UPDATE conversations SET metadata_json='{}'",
    'other saved bot'=>"UPDATE conversations SET metadata_json='{\"telegram_bot_id\":\"303\"}'",
] as $name=>$mutation) {
    $pdo->beginTransaction();
    $pdo->exec($mutation);
    tgMediaCheck($name.' refuses delivery even with candidate metadata', $resolve->invoke(null,$row+['metadata_json'=>'{"telegram_bot_id":"202"}'],$binding,'manager')===null);
    $pdo->rollBack();
}
tgMediaCheck('numeric group chat or empty project credential refuses scoped delivery',
    $resolve->invoke(null,array_replace($row,['external_chat_id'=>'-101']),$binding,'manager')===null
    && $resolve->invoke(null,$row,array_replace($binding,['bot_token'=>'']),'manager')===null);
$edit=(string)file_get_contents($root.'/services/ManagerMessageEditService.php');
tgMediaCheck('Telegram text and caption edits resolve their conversation before provider editing',
    strpos($edit,"ProjectTelegramMessengerFactory::forConversation(\$conversation,'manager')")!==false
    && strpos($edit,'$adapter->editCaption(')!==false && strpos($edit,'$adapter->editText(')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
