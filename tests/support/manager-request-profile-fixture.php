<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&!defined('MANAGER_REQUEST_PROFILE_FIXTURE')){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/services/ManagerRequestProfileService.php';
require_once dirname(__DIR__,2).'/services/ManagerAiDraftContextService.php';

function requestProfileFixture(?string $path=null,string $mode='default'): PDO
{
    if(!in_array($mode,['default','hotel_focus'],true))throw new InvalidArgumentException('unknown_profile_fixture_mode');
    $p=new PDO('sqlite:'.($path??':memory:'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$p);
    foreach([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $c)(new ReflectionProperty($c,'schemaReady'))->setValue(null,false);
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'AnyTour']]);
    $p->exec("PRAGMA foreign_keys=ON;
CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT);
CREATE TABLE manager_customer_names(customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER);
CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,external_chat_id TEXT);
CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,display_name TEXT,is_active INTEGER,primary_group_id INTEGER,fallback_mode TEXT,fallback_group_id INTEGER,fallback_after_minutes INTEGER);
CREATE TABLE manager_group_members(manager_id INTEGER,group_id INTEGER);
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,actor_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,direction TEXT,sender_type TEXT,text TEXT,created_at TEXT,channel TEXT,metadata_json TEXT,sender_id TEXT,external_message_id TEXT);
CREATE INDEX profile_messages ON messages(conversation_id,id);
CREATE TABLE manager_conversation_reads(manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER,updated_at TEXT,PRIMARY KEY(manager_id,conversation_id));
CREATE TABLE manager_request_profiles(conversation_id INTEGER,version INTEGER,trip_id TEXT,boundary_message_id INTEGER,manager_id INTEGER,operation_id TEXT,request_sha256 TEXT,context_sha256 TEXT,profile_json TEXT,profile_sha256 TEXT,changed_fields_json TEXT,created_at TEXT,PRIMARY KEY(conversation_id,version),UNIQUE(manager_id,operation_id),FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE);
INSERT INTO projects VALUES(1,'anytour','AnyTour',1),(2,'other','Other',1);
INSERT INTO managers VALUES(1,'manager','Мария','manager','',1,1),(2,'owner','Павел','admin','',1,1),(3,'foreign','Foreign','admin','',1,1),(4,'inactive','Inactive','admin','',0,1),(5,'colleague','Colleague','manager','',1,1);
INSERT INTO manager_projects VALUES(1,1),(2,1),(3,2),(4,1),(5,1);
INSERT INTO customers VALUES(10,'Синтетический турист','','');
INSERT INTO conversations VALUES(1,10,'anytour',0,'telegram','','','','manager','new',1,'2026-10-30 10:00:00','2026-10-30 10:00:00',NULL,'synthetic-chat');
INSERT INTO manager_conversation_reads VALUES(1,1,10,'2026-10-30 10:00:00');");
    requestProfileMessage($p,10,'manager','Москва, 19 декабря, 7 ночей. Стоимость за двоих: 180 000 ₽.');
    requestProfileMessage($p,11,'customer','Даты подходят. Нужен песчаный пляж. Едем вдвоём, детей нет.');
    if($mode==='hotel_focus'){
        require_once dirname(__DIR__).'/hotel_current_trip_focus_checks.php';
        hotelCurrentTripFocusSeed($p);
    }
    return $p;
}
function requestProfileMessage(PDO $p,int $id,string $role,string $text,int $cid=1): void
{
    $p->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$id,$cid,$role==='customer'?'inbound':'outbound',$role,$text,'2026-10-30 10:00:00','telegram',null,$role==='customer'?'tourist':'1','synthetic-'.$id]);
}
function requestProfileCommand(array $view,array $changes=[]): array
{
    return array_replace(['version'=>$view['profile']['version'],'context_sha256'=>$view['context_sha256'],'operation_id'=>bin2hex(random_bytes(16)),
        'boundary_message_id'=>10,'new_trip'=>false,'label'=>'Декабрьский отдых','fields'=>[
            'dates'=>['value'=>'19 декабря','kind'=>'manager_interpretation','importance'=>'hard','note'=>'Год и точные рейсы нужно проверить','source_id'=>10,'quote'=>$view['sources'][0]['text']],
            'party'=>['value'=>['adults'=>2,'children'=>0,'child_ages'=>[]],'kind'=>'manager_interpretation','importance'=>'hard','note'=>'Фактические туристы','source_id'=>11,'quote'=>$view['sources'][1]['text']],
            'hotel'=>['value'=>'песчаный пляж','kind'=>'customer_statement','importance'=>'hard','note'=>'','source_id'=>11,'quote'=>$view['sources'][1]['text']],
        ]],$changes);
}

/** A second actual native inquiry for the same hotel/room, with distinct quoted terms. */
function requestProfileSecondHotelInquiry(PDO $p,int $messageId=15): void
{
    require_once dirname(__DIR__,2).'/services/ManagerTourSelectionPublicationDraft.php';
    require_once dirname(__DIR__,2).'/services/ManagerTourSelectionImportDraft.php';
    $q=$p->prepare('SELECT binding_json,selection_json FROM manager_tour_selection_publications WHERE publication_id=?');$q->execute([str_repeat('a',32)]);$previous=$q->fetch(PDO::FETCH_ASSOC);
    if(!$previous)throw new RuntimeException('hotel_focus_source_fixture_required');
    $first=json_decode($previous['selection_json'],true,48,JSON_THROW_ON_ERROR);$source=$first['offers'][0]['import_source'];
    $import=ManagerTourSelectionImportDraft::normalize(['adapter'=>$source['adapter'],'url'=>$source['url'],'observed_at'=>$source['observed_at'],
        'quote_id'=>$source['quote_id'],'keys'=>$source['keys'],'offer'=>$source['fields'],'raw'=>$source['raw'],'photos'=>$source['photos']]);
    if($import===null)throw new RuntimeException('second_hotel_source_invalid');
    $offer=$import['offer'];$offer['id']=str_repeat('e',32);$offer['import_source']=ManagerTourSelectionImportDraft::source($import);
    $offer['amount']='185000';$offer['flights']='Другой перелёт — время и багаж нужно проверить';
    $selection=ManagerTourSelectionPublicationDraft::build(['title'=>'Другой тариф того же отеля','intro'=>'','offers'=>[$offer]]);
    if(empty($selection['ok']))throw new RuntimeException('second_hotel_publication_invalid');unset($selection['ok']);
    $id=str_repeat('b',32);$json=json_encode($selection,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $p->prepare('INSERT INTO manager_tour_selection_publications VALUES(?,?,?,?,?,?,?,?)')->execute([$id,2,2,hash('sha256',$previous['binding_json'].$json),1,$previous['binding_json'],$json,'2026-10-30 09:59:30']);
    $s=$selection['offers'][0]['snapshot'];$d=$s['quoted_details'];
    $public=['index'=>0,'hotel'=>$s['hotel'],'departure'=>$s['departure'],'date_from'=>$s['date_from'],'nights'=>$s['nights'],
        'adults'=>$s['adults'],'children'=>count($s['child_ages']),'room'=>$s['room'],'meal'=>$s['meal'],'amount'=>$s['amount'],'currency'=>$s['currency'],
        'rationale'=>$s['rationale'],'compromise'=>$s['compromise'],'destination'=>$d['destination'],'flights'=>$d['flights'],
        'inclusions'=>$d['inclusions'],'constraints'=>$d['constraints'],'observed_at'=>$d['observed_at'],'price_verified'=>false];
    $p->prepare('INSERT INTO conversation_events VALUES(?,?,?,?,?,?)')->execute([1000+$messageId,1,'tour_web_inquiry','customer',
        json_encode(['schema'=>1,'entry_key'=>str_repeat('d',64),'publication_id'=>$id,'link_version'=>2,'variant'=>1,'public_offer'=>$public],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'2026-10-30 10:00:00']);
    requestProfileMessage($p,$messageId,'customer','Обращение по другому тарифу того же отеля');
    $p->prepare('UPDATE messages SET metadata_json=? WHERE id=?')->execute([json_encode(['type'=>'tour_web_inquiry','native_event_type'=>'bot_started','publication_id'=>$id,'variant'=>1,'link_version'=>2]),$messageId]);
}
