<?php
declare(strict_types=1);
foreach (['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS'] as $constant) if (!defined($constant)) define($constant,'synthetic-fixture');
require_once dirname(__DIR__,2).'/services/ManagerTourSelectionDeliveryService.php';
require_once dirname(__DIR__,2).'/integrations/TelegramIncomingAdapter.php';
require_once dirname(__DIR__,2).'/integrations/MaxIncomingAdapter.php';
$checks=0;
function deliveryCheck(string $name,bool $ok): void {global $checks;if (!$ok) throw new RuntimeException('FAIL '.$name);++$checks;echo 'PASS '.$name."\n";}
function deliveryDb(): PDO
{
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('NOW',static fn():string=>gmdate('Y-m-d H:i:s'));
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    foreach ([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) (new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
    ProjectConfig::resetForTests(['id'=>'anytour']);
    $pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT);
CREATE TABLE manager_customer_names(customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);
CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT);
CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,external_chat_id TEXT);
CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,display_name TEXT,primary_group_id INTEGER,fallback_mode TEXT,fallback_group_id INTEGER,fallback_after_minutes INTEGER,is_active INTEGER);
CREATE TABLE manager_group_members(manager_id INTEGER,group_id INTEGER);
CREATE TABLE messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,direction TEXT,sender_type TEXT,sender_id TEXT,channel TEXT,external_message_id TEXT,text TEXT,metadata_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE manager_conversation_reads(manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER);
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,event_type TEXT,actor_type TEXT,actor_id TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE manager_tour_selection_drafts(conversation_id INTEGER PRIMARY KEY,version INTEGER,draft_json TEXT,updated_by_manager_id INTEGER,updated_at TEXT);
CREATE TABLE manager_tour_selection_operations(manager_id INTEGER,operation_id TEXT,conversation_id INTEGER,request_hash TEXT,saved_version INTEGER,created_at TEXT,PRIMARY KEY(manager_id,operation_id));
CREATE TABLE manager_tour_selection_publications(publication_id TEXT PRIMARY KEY,conversation_id INTEGER,draft_version INTEGER,draft_hash TEXT,created_by_manager_id INTEGER,binding_json TEXT,selection_json TEXT,created_at TEXT,UNIQUE(conversation_id,draft_hash));
CREATE TABLE manager_tour_selection_cards(publication_id TEXT,card_index INTEGER,offer_id INTEGER,callback_data TEXT,created_at TEXT,PRIMARY KEY(publication_id,card_index));
CREATE TABLE manager_tour_selection_deliveries(offer_id INTEGER PRIMARY KEY,conversation_id INTEGER,offer_hash TEXT,callback_data TEXT,state TEXT,receipt_json TEXT,created_at TEXT,updated_at TEXT,UNIQUE(conversation_id,offer_hash));
INSERT INTO projects VALUES(1,'anytour','Synthetic workspace',1);
INSERT INTO manager_projects VALUES(7,1),(8,1),(90,1);
INSERT INTO managers VALUES(7,'author','Synthetic author','manager','',1,1),(8,'colleague','Synthetic colleague','manager','',1,1),(90,'admin','Synthetic admin','admin','',1,1);
INSERT INTO customers VALUES(1,'Synthetic client','',''),(2,'Synthetic MAX client','','');
INSERT INTO customer_channels VALUES(1,1,'anytour','telegram','101','101'),(2,2,'anytour','max','202','-202');
INSERT INTO conversation_sources VALUES(1,'Allowed',0,'none',0,0,1);
INSERT INTO conversations(id,customer_id,customer_channel_id,project_key,source_id,channel,status,manager_id,started_at,external_chat_id) VALUES(1,1,1,'anytour',1,'telegram','manager',7,'2026-10-20 00:00:00','101'),(2,2,2,'anytour',1,'max','manager',7,'2026-10-20 00:00:00','-202');
INSERT INTO messages(conversation_id,direction,sender_type,sender_id,channel,external_message_id,text,metadata_json) VALUES(2,'inbound','customer','202','max','mid.inbound','Synthetic request','{\"type\":\"message\"}');
INSERT INTO manager_conversation_reads VALUES(7,1,0);");
    return $pdo;
}
function deliveryDraft(string $hotel='Синтетический отель'): array
{
    return ['title'=>'К морю','intro'=>'','offers'=>[['id'=>str_repeat('a',32),'selected'=>true,'hotel'=>$hotel,'destination'=>'Турция, Лара','departure'=>'Москва','date_from'=>'2027-06-05','nights'=>7,'adults'=>2,'child_ages'=>[4,4],'room'=>'Семейный номер','meal'=>'Всё включено','amount'=>'240000','currency'=>'RUB','rationale'=>'Песчаный пляж','compromise'=>'Компактный номер','flights'=>'Время рейсов уточняется','inclusions'=>'Трансфер','constraints'=>'Топливный сбор необходимо проверить','source_label'=>'Синтетический источник','source_url'=>'https://catalog.example.org/offer/1','observed_at'=>'2026-10-22T10:00:00Z','photos'=>array_map(static fn(string $kind):array=>['kind'=>$kind,'url'=>'https://photos.example.org/'.$kind.'.jpg','illustrative'=>true],['cover','beach','room'])]]];
}
function deliverySave(array $draft,int $version=0,int $conversation=1): array {return ManagerTourSelectionService::save(7,$conversation,['version'=>$version,'draft'=>$draft,'operation_id'=>bin2hex(random_bytes(16))]);}
function deliveryImages(): array {return array_map(static fn(int $i):array=>['path'=>__FILE__,'mime'=>'image/jpeg','sha256'=>str_repeat((string)$i,64)],[1,2,3]);}
function deliveryTransport(array &$state): TourSelectionNativeTransport
{
    return new TourSelectionNativeTransport(static function(string $channel,string $method,string $path,array $query,array $body,array $images) use (&$state):array {
        $state['calls'][]=[$channel,$method,$path];
        if ($path==='getMe') return ['data'=>['id'=>999,'is_bot'=>true]];
        if ($path==='/me') return ['data'=>['user_id'=>999,'is_bot'=>true]];
        if ($path==='getChat') return ['data'=>['id'=>101,'type'=>'private']];
        if ($path==='/messages/mid.inbound') return ['data'=>['body'=>['mid'=>'mid.inbound'],'sender'=>['user_id'=>202],'recipient'=>['user_id'=>999,'chat_id'=>555,'chat_type'=>'dialog']]];
        if ($path==='/uploads') return ['data'=>['url'=>'https://iu.oneme.ru/uploadImage?token=synthetic']];
        if ($channel==='upload') return ['data'=>['photos'=>[['token'=>'synthetic-upload-token']]]];
        if ($path==='sendRichMessage') {
            ++$state['creates'];if (($state['mode']??'')==='unknown') return ['data'=>null];if (($state['mode']??'')==='rejected') return ['data'=>null,'rejected'=>true];
            $message=['message_id'=>7000+$state['creates'],'chat'=>['id'=>101,'type'=>'private'],'from'=>['id'=>999,'is_bot'=>true],'rich_message'=>$body['rich_message'],'reply_markup'=>$body['reply_markup']];
            foreach ($message['rich_message']['blocks'][0]['blocks'] as &$block) $block['photo']=[['file_id'=>'synthetic-photo','width'=>800,'height'=>600]];unset($block);
            return ['data'=>$message];
        }
        if ($path==='/messages'&&$method==='POST') {
            ++$state['creates'];$card=$state['card'];$state['native']=['body'=>['mid'=>'mid.sent.'.$state['creates'],'text'=>$card['plain_text'],'attachments'=>array_merge([['type'=>'image','payload'=>['photo'=>'a']],['type'=>'image','payload'=>['photo'=>'b']],['type'=>'image','payload'=>['photo'=>'c']]],$card['max']['attachments']),'markup'=>$card['max_markup']],'sender'=>['user_id'=>999],'recipient'=>['user_id'=>202,'chat_id'=>555,'chat_type'=>'dialog']];
            return ['data'=>['message'=>$state['native']]];
        }
        if (str_starts_with($path,'/messages/mid.sent.')) return ['data'=>!empty($state['readback'])?$state['native']:null];
        throw new RuntimeException('Unexpected fake exchange '.$channel.' '.$method.' '.$path);
    });
}

