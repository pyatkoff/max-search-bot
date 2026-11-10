<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/services/RepairMaxWebhookService.php';
require_once __DIR__.'/channel_post_registry_fixture.php';

function repairFixture(): PDO
{
    foreach (['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS'] as $name) {
        if (!defined($name)) define($name, 'isolated_repair_fixture');
    }
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => gmdate('Y-m-d H:i:s'));
    $pdo->exec("CREATE TABLE customers(id INTEGER PRIMARY KEY AUTOINCREMENT,display_name TEXT);
        CREATE TABLE customer_channels(id INTEGER PRIMARY KEY AUTOINCREMENT,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT,username TEXT,metadata_json TEXT,UNIQUE(project_key,channel,external_user_id));
        CREATE TABLE conversations(id INTEGER PRIMARY KEY AUTOINCREMENT,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,external_chat_id TEXT,status TEXT,manager_id INTEGER,entry_channel TEXT,attribution_region TEXT,last_message_at TEXT,metadata_json TEXT);
        CREATE TABLE messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,direction TEXT,sender_type TEXT,sender_id TEXT,channel TEXT,external_message_id TEXT,text TEXT,metadata_json TEXT);
        CREATE TABLE conversation_events(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,event_type TEXT,actor_type TEXT,actor_id TEXT,payload_json TEXT,created_at TEXT);");
    createChannelPostRegistryFixture($pdo);
    $pdo->exec("INSERT INTO projects VALUES(1,'anytour','AnyTour',1),(2,'appleservice39','AppleService39',1),(3,'repair_two','Repair Two',1);
        INSERT INTO conversation_sources VALUES(11,1,'max:anytour-main','AnyTour','max',1,'manager'),(21,2,'max:appleservice39-main','AppleService39','max',1,'manager'),(22,2,'max:appleservice39-posts','Apple publications','max',1,'manager'),(31,3,'max:repair_two-main','Repair Two','max',1,'manager');
        INSERT INTO customers(id,display_name) VALUES(100,'Synthetic legacy customer');
        INSERT INTO customer_channels(id,customer_id,project_key,channel,external_user_id,external_chat_id,username,metadata_json) VALUES(100,100,'anytour','max','7001','-7001','synthetic','{}');
        INSERT INTO conversations(id,customer_id,customer_channel_id,project_key,source_id,channel,external_chat_id,status,manager_id,entry_channel,metadata_json) VALUES(100,100,100,'anytour',11,'max','-7001','manager',77,'max:anytour-main','{\"legacy\":true}');
        INSERT INTO messages(conversation_id,direction,sender_type,sender_id,channel,external_message_id,text,metadata_json) VALUES(100,'inbound','customer','7001','max','shared_mid','Synthetic legacy text','{}');");
    $property = new ReflectionProperty(ConversationDb::class, 'pdo');
    $property->setAccessible(true);
    $property->setValue(null, $pdo);
    ProjectMaxConnection::resetForTests([
        'appleservice39'=>['project'=>['id'=>'appleservice39','business'=>['kind'=>'device_repair'],'brand'=>['name'=>'AppleService39'],'routing'=>['source_key'=>'max:appleservice39-main']],
            'max'=>['bot_key'=>'id390521129062_bot','token'=>'synthetic-apple-token','webhook_secret'=>str_repeat('a',32)]],
        'repair_two'=>['project'=>['id'=>'repair_two','business'=>['kind'=>'device_repair'],'brand'=>['name'=>'Repair Two'],'routing'=>['source_key'=>'max:repair_two-main']],
            'max'=>['bot_key'=>'id999999999002_bot','token'=>'synthetic-second-token','webhook_secret'=>str_repeat('b',32)]],
    ]);
    return $pdo;
}

function repairStart(int $user, string $payload = 'as39_x_battery', int $timestamp = 1800000000000): array
{
    return ['update_type'=>'bot_started','timestamp'=>$timestamp,'payload'=>$payload,'user'=>['user_id'=>$user,'is_bot'=>false,'name'=>'Synthetic Customer']];
}

function repairMessage(int $user, string $mid, array $attachments = []): array
{
    return ['update_type'=>'message_created','timestamp'=>1800000001000,'message'=>[
        'sender'=>['user_id'=>$user,'is_bot'=>false,'name'=>'Synthetic Customer'],
        'recipient'=>['chat_type'=>'dialog','chat_id'=>123456],
        'body'=>['mid'=>$mid,'text'=>'Synthetic repair symptom','attachments'=>$attachments]]];
}

function repairConversation(PDO $pdo, string $project, int $user): array
{
    $q = $pdo->prepare('SELECT * FROM conversations WHERE project_key=? AND external_chat_id=? ORDER BY id DESC LIMIT 1');
    $q->execute([$project,(string)-$user]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
}

function repairIntent(PDO $pdo, int $conversationId): array
{
    $q = $pdo->prepare("SELECT payload_json FROM conversation_events WHERE conversation_id=? AND event_type='waiting_manager' ORDER BY id DESC LIMIT 1");
    $q->execute([$conversationId]);
    return (json_decode((string)$q->fetchColumn(), true) ?: [])['repair_greeting'] ?? [];
}
