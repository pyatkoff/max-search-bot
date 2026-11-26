<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/regional_access_schema.php';

require_once __DIR__.'/../integrations/TelegramIncomingAdapter.php';
$update=['update_id'=>90001,'message'=>[
    'message_id'=>123,'from'=>['id'=>777,'first_name'=>'Test'],'chat'=>['id'=>777],
    'forward_origin'=>['type'=>'hidden_user','sender_user_name'=>'Original sender','date'=>1700000000],
    'caption'=>'Этот вариант: Hotel Example, Beach Villa, всё включено',
    'photo'=>[
        ['file_id'=>'largest_photo','file_unique_id'=>'unique-large','width'=>1280,'height'=>960,'file_size'=>30000],
        ['file_id'=>'small_photo','width'=>90,'height'=>90,'file_size'=>1000],
    ],
]];
$incoming=TelegramIncomingAdapter::fromUpdate($update);
$failures=[];
if(($incoming['text']??'')!==$update['message']['caption'])$failures[]='forwarded photo caption lost';
if(($incoming['attachments'][0]['telegram_file_id']??'')!=='largest_photo')$failures[]='forwarded photo file lost';
if(($incoming['attachments'][0]['type']??'')!=='image')$failures[]='photo not renderable';
if(($incoming['user']['external_user_id']??null)!=777)$failures[]='forward origin replaced customer identity';
foreach($failures as $failure)echo "FAIL $failure\n";
if($failures)exit(1);
echo "PASS forwarded Telegram photo keeps content and customer identity\n";

require_once __DIR__.'/../services/ManagerMessageMediaService.php';
require_once __DIR__.'/../services/ManagerTelegramMediaService.php';
function mediaEqual($actual, $expected, string $name): void {
    if ($actual !== $expected) throw new RuntimeException($name);
    echo "PASS $name\n";
}
$plain=$update; unset($plain['message']['caption'],$plain['message']['forward_origin']);
$plain['message']['message_id']=124;
$photoOnly=TelegramIncomingAdapter::fromUpdate($plain);
mediaEqual($photoOnly['text'],'','photo-only input preserves empty actual caption');
mediaEqual(count($photoOnly['attachments']),1,'album item retains its own photo');
foreach(['document'=>'file','video'=>'video','animation'=>'video','voice'=>'audio','audio'=>'audio','video_note'=>'video'] as $field=>$type){
    $m=['message'=>['message_id'=>125,'from'=>['id'=>777],$field=>['file_id'=>'saved-'.$field,'file_name'=>'offer.pdf'],'caption'=>'Подпись']];
    if($field==='animation')$m['message']['document']=['file_id'=>'same-animation'];
    $a=TelegramIncomingAdapter::fromUpdate($m);
    mediaEqual($a['text'],'Подпись',"$field caption");
    mediaEqual(count($a['attachments']),1,"$field is not duplicated");
    mediaEqual($a['attachments'][0]['type'],$type,"$field type");
}
$text=TelegramIncomingAdapter::fromUpdate(['message'=>['message_id'=>126,'from'=>['id'=>777],'text'=>'Обычный текст']]);
mediaEqual($text['attachments'],[],'plain text remains plain text');
// Exercise actual recorder serialization, hydration and access policy against an isolated DB.
foreach(['HOST','NAME','USER','PASS'] as $key)define('CONVERSATION_DB_'.$key,'test-only');
define('TELEGRAM_BOT_TOKEN','test-token');
ProjectConfig::resetForTests(['id'=>'anytour']);
$pdo=new PDO('sqlite::memory:');regionalAccessFixtureSchema($pdo);
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
foreach([ProjectAccessService::class,RoutingAccessService::class,ManagerAuthService::class] as $class)(new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
$pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT);
CREATE TABLE manager_customer_names(customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);
CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT,username TEXT);
CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,project_id INTEGER,source_key TEXT,is_active INTEGER,display_name TEXT);
CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,external_chat_id TEXT);
CREATE TABLE messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,direction TEXT,sender_type TEXT,sender_id TEXT,channel TEXT,external_message_id TEXT,text TEXT,metadata_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
INSERT INTO projects VALUES(1,'anytour','Test',1);
INSERT INTO managers VALUES(7,'test','Test','admin','',1,1);
INSERT INTO manager_projects VALUES(7,1);
INSERT INTO customers VALUES(2,'Test','','');
INSERT INTO customer_channels VALUES(3,2,'anytour','telegram','777','777','');
INSERT INTO conversation_sources VALUES(1,1,'telegram:test',1,'Test');
INSERT INTO conversations(id,customer_id,customer_channel_id,project_key,source_id,channel,status,manager_id) VALUES(9,2,3,'anytour',1,'telegram','manager',7);");
$incoming['source_key']='telegram:test';
mediaEqual(ConversationRecorder::inbound($incoming),true,'forwarded photo persisted');
mediaEqual(ConversationRecorder::inbound($incoming),true,'repeated update is idempotent');
mediaEqual((int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn(),1,'no duplicate message');
$stored=$pdo->query('SELECT * FROM messages')->fetch();
mediaEqual($stored['text'],$update['message']['caption'],'caption survives DB round trip');
$meta=json_decode($stored['metadata_json'],true);
mediaEqual($meta['attachments'][0]['telegram_file_id'],'largest_photo','file reference survives DB round trip');
mediaEqual(isset($meta['raw']),false,'raw update is not persisted');
$hydrated=ManagerMessageMediaService::hydrate([$stored])[0];
mediaEqual($hydrated['attachments'][0]['url'],'media-file.php?message_id=1&attachment=0','saved message gets protected attachment URL');
mediaEqual(str_contains(json_encode($hydrated['attachments']),'largest_photo'),false,'UI does not receive Telegram file id');
$attachment=ManagerTelegramMediaService::attachment(1,0,7);
mediaEqual($attachment['telegram_file_id'],'largest_photo','authorized manager resolves saved attachment');
mediaEqual(ManagerTelegramMediaService::attachment(1,0,8),null,'manager without project access cannot resolve file');
mediaEqual(ManagerTelegramMediaService::attachment(1,1,7),null,'unknown attachment fails closed');
mediaEqual(ManagerTelegramMediaService::attachment(1,-1,7),null,'negative index fails closed');
mediaEqual(ManagerTelegramMediaService::attachment(99,0,7),null,'unknown message fails closed');
$pdo->exec("UPDATE messages SET channel='max'");
mediaEqual(ManagerTelegramMediaService::attachment(1,0,7),null,'MAX messages cannot select Telegram files');
$pdo->exec("UPDATE messages SET channel='telegram'");
$pdo->exec("UPDATE conversations SET project_key='another'");
mediaEqual(ManagerTelegramMediaService::attachment(1,0,7),null,'cross-project file fails closed');
$pdo->exec("UPDATE conversations SET project_key='anytour'");
function mediaStream(string $bytes){$s=tmpfile();fwrite($s,$bytes);rewind($s);return $s;}
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a/2kAAAAASUVORK5CYII=');
$calls=[];
$fetch=static function($url,$post,$limit)use(&$calls,$png){$calls[]=[$url,$post,$limit];return mediaStream($post!==null?json_encode(['ok'=>true,'result'=>['file_path'=>'photos/file_1.jpg','file_size'=>strlen($png)]]):$png);};
$file=ManagerTelegramMediaService::open($attachment,$fetch);
mediaEqual($file['mime'],'image/png','download uses detected image MIME');
mediaEqual(stream_get_contents($file['stream']),$png,'download serves exact bytes');
fclose($file['stream']);
mediaEqual($calls[0][1],['file_id'=>'largest_photo'],'getFile uses persisted identifier');
mediaEqual($calls[1][0],'https://api.telegram.org/file/bottest-token/photos/file_1.jpg','download stays on fixed Telegram host');
mediaEqual(ManagerTelegramMediaService::open($attachment,static fn()=>null),null,'provider outage is an explicit failure');
foreach(['../secrets','photos/../config','https://example.invalid/a','photos/%2e%2e/a'] as $badPath){
    $count=0;
    $bad=static function()use(&$count,$badPath){$count++;return mediaStream(json_encode(['ok'=>true,'result'=>['file_path'=>$badPath]]));};
    mediaEqual(ManagerTelegramMediaService::open($attachment,$bad),null,'unsafe remote path rejected');
    mediaEqual($count,1,'unsafe path is never downloaded');
}
$html=static fn($url,$post)=>mediaStream($post!==null?json_encode(['ok'=>true,'result'=>['file_path'=>'documents/file.html']]):'<html><script>alert(1)</script></html>');
$file=ManagerTelegramMediaService::open($attachment,$html);
mediaEqual($file['inline'],false,'active content is download only');
mediaEqual($file['mime'],'application/octet-stream','active content MIME is not reflected');
fclose($file['stream']);
$count=0;
try {ManagerTelegramMediaService::open(array_replace($attachment,['size'=>ManagerTelegramMediaService::MAX_BYTES+1]),static function()use(&$count){$count++;return null;});throw new RuntimeException('oversized file accepted');}
catch(RuntimeException $e){mediaEqual($e->getCode(),413,'large file has explicit limit error');}
mediaEqual($count,0,'known oversized file never calls Telegram');

require_once __DIR__.'/../services/ProjectClientCredentialStore.php';
$tmp=realpath(sys_get_temp_dir()).'/apple-private-media-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);
DiagnosticLogger::setFile($tmp.'/fixture.log');
try {
    $pdo->exec("ALTER TABLE conversation_sources ADD COLUMN channel TEXT;
        ALTER TABLE conversation_sources ADD COLUMN handling_mode TEXT;
        ALTER TABLE conversations ADD COLUMN metadata_json TEXT;
        CREATE TABLE project_telegram_bot_connections(project_id INTEGER PRIMARY KEY,bot_id TEXT,credentials_json TEXT,version INTEGER,webhook_state TEXT,webhook_started_at INTEGER);
        INSERT INTO projects VALUES (5,'appleservice39','AppleService39',1);
        INSERT INTO manager_projects VALUES (7,5);
        INSERT INTO customers VALUES (12,'Apple test','','');
        INSERT INTO customer_channels VALUES (13,12,'appleservice39','telegram','881','881','');
        INSERT INTO conversation_sources(id,project_id,source_key,is_active,display_name,channel,handling_mode)
          VALUES(5,5,'telegram:appleservice39-main',1,'Apple Telegram','telegram','manager');
        INSERT INTO conversations(id,customer_id,customer_channel_id,project_key,source_id,channel,status,manager_id,external_chat_id,metadata_json)
          VALUES(19,12,13,'appleservice39',5,'telegram','manager',7,'881','{\"telegram_bot_id\":\"654321\"}')");
    $testToken='654321:'.str_repeat('C',30);
    $vault=new ProjectClientCredentialStore($tmp.'/apple-telegram-credentials');
    $vault->initialize(true);
    $secret=$vault->seal('appleservice39','telegram:654321',['token'=>$testToken,'webhook_secret'=>str_repeat('a',64)]);
    $pdo->prepare('INSERT INTO project_telegram_bot_connections(project_id,bot_id,credentials_json,version) VALUES(?,?,?,1)')->execute([5,'654321',$secret]);
    $photo=$meta['attachments'][0];
    $photo['telegram_file_id']='apple-fixture-photo';
    $saved=['bot_key'=>'654321','source_key'=>'telegram:appleservice39-main','attachments'=>[$photo]];
    $pdo->prepare("INSERT INTO messages(id,conversation_id,channel,direction,metadata_json) VALUES(21,19,'telegram','inbound',?)")
        ->execute([json_encode($saved,JSON_THROW_ON_ERROR)]);
    mediaEqual(ManagerTelegramMediaService::attachment(21,0,7),null,'generic AI attachment reader keeps its previous access');
    mediaEqual(ManagerTelegramMediaService::attachment(21,0,8,true),null,'an unauthorized manager cannot select an Apple image');
    $lookup=ManagerTelegramMediaService::attachment(21,0,7,true);
    mediaEqual($lookup,['apple_manager_lookup'=>[21,0,7]],'private viewer receives only a server lookup');
    $calls=[];
    $file=ManagerTelegramMediaService::open($lookup+['telegram_file_id'=>'foreign-supplied'],$fetch);
    mediaEqual($file['mime'],'image/png','Apple photo opens via the protected file viewer');
    fclose($file['stream']);
    mediaEqual($calls[0],['https://api.telegram.org/bot'.$testToken.'/getFile',['file_id'=>'apple-fixture-photo'],65536],'photo uses the stored Apple bot and saved file reference');
    mediaEqual($calls[1][0],'https://api.telegram.org/file/bot'.$testToken.'/photos/file_1.jpg','Apple download uses its own bot');
    foreach ([
        'access revoked'=>"DELETE FROM manager_projects WHERE manager_id=7 AND project_id=5",
        'private identity changed'=>"UPDATE customer_channels SET external_user_id='777' WHERE id=13",
        'source changed'=>"UPDATE conversation_sources SET source_key='telegram:other' WHERE id=5",
        'message moved'=>"UPDATE messages SET conversation_id=9 WHERE id=21",
        'message bot changed'=>"UPDATE messages SET metadata_json=REPLACE(metadata_json,'654321','654322') WHERE id=21",
        'message source changed'=>"UPDATE messages SET metadata_json=REPLACE(metadata_json,'appleservice39-main','other') WHERE id=21",
    ] as $case=>$mutation) {
        $pdo->beginTransaction();
        $pdo->exec($mutation);
        $calls=[];
        mediaEqual(ManagerTelegramMediaService::open($lookup,$fetch),null,$case.' denies the saved lookup');
        mediaEqual(count($calls),0,$case.' never calls Telegram');
        $pdo->rollBack();
    }
} finally {
    DiagnosticLogger::setFile('');
    @unlink($tmp.'/apple-telegram-credentials/master.key');
    @rmdir($tmp.'/apple-telegram-credentials');
    @unlink($tmp.'/fixture.log');
    @rmdir($tmp);
}
$endpoint=file_get_contents(__DIR__.'/../manager/media-file.php');
mediaEqual(strpos($endpoint,"'/config.php'")<strpos($endpoint,'ManagerHttp::requireManager()'),true,'endpoint configures DB before authenticated lookup');
mediaEqual(strpos($endpoint,'ManagerHttp::requireManager()')<strpos($endpoint,'ManagerTelegramMediaService::attachment'),true,'endpoint authenticates before media lookup');
mediaEqual(str_contains($endpoint,"session_write_close()"),true,'download releases session lock');
echo "Telegram incoming media regression passed\n";
