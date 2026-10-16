<?php
// Included by the existing Workspace regression. Everything uses an isolated
// SQLite connection and local transport callbacks; no application config loads.
require_once $root.'/integrations/MaxMessengerAdapter.php';
require_once $root.'/integrations/TelegramMessengerAdapter.php';
require_once $root.'/integrations/WebsiteMessengerAdapter.php';

$tripPdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$tripPdo->sqliteCreateFunction('NOW',static fn()=>'2026-10-16 17:00:00');
$tripPdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn()=>'2026-10-16 17:00:00');
$tripPdo->exec("CREATE TABLE customers(id INTEGER PRIMARY KEY,phone TEXT,email TEXT);
 CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT,external_chat_id TEXT,status TEXT,last_message_at TEXT,lead_outcome TEXT DEFAULT 'open',lead_close_reason TEXT,lead_outcome_note TEXT,lead_sale_amount TEXT,lead_sale_date TEXT);
 CREATE TABLE messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,direction TEXT,sender_type TEXT,sender_id TEXT,channel TEXT,external_message_id TEXT,text TEXT,metadata_json TEXT);
 CREATE INDEX idx_messages_conversation ON messages(conversation_id,id);
 CREATE TABLE lead_close_reasons(reason_key TEXT,display_name TEXT,sort_order INTEGER,is_active INTEGER);
 CREATE TABLE lead_tasks(id INTEGER,conversation_id INTEGER,title TEXT,due_at_utc TEXT,is_pinned INTEGER,status TEXT);
 INSERT INTO customers(id) VALUES(1),(2),(3);");
$tripDbProperty=new ReflectionProperty(ConversationDb::class,'pdo');
$tripDbProperty->setValue(null,$tripPdo);
foreach(['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS'] as $tripConstant)if(!defined($tripConstant))define($tripConstant,'isolated-trip-fixture');
$tripProject=ProjectConfig::projectId();
$tripInsertConversation=$tripPdo->prepare('INSERT INTO conversations(id,customer_id,project_key,channel,external_chat_id,status) VALUES(?,?,?,?,?,?)');
foreach([[1,'max'],[2,'telegram'],[3,'website']] as [$tripId,$tripChannel])$tripInsertConversation->execute([$tripId,$tripId,$tripProject,$tripChannel,(string)$tripId,'ai']);
$tripInsert=$tripPdo->prepare('INSERT INTO messages(conversation_id,direction,sender_type,text,metadata_json) VALUES(?,?,?,?,?)');
$tripLegacy="✅ <b>Готово! Проверьте параметры</b>\n\n✈️ Москва → Турция\n👥 2 взрослых\n\nЧто удобнее дальше?";
$tripExpected='✈️ Москва → Турция 👥 2 взрослых';
$tripMeta=['trip_summary'=>['version'=>1,'text'=>'✈️ Казань → Египет 👥 3 взрослых']];
$tripInsert->execute([1,'outbound','ai',$tripLegacy,null]);
$tripInsert->execute([2,'outbound','ai',$tripLegacy,null]);
$tripRead=static function(array $ids=[1,2]) {return array_column(ManagerLeadInboxService::decorate(array_map(static fn($id)=>['id'=>$id],$ids)),'trip_summary','id');};
mw2Check('trip summary preserves historical literal body and whitespace', $tripRead()[1]===$tripExpected);
$tripInsert->execute([1,'outbound','ai','<strong>Ваш запрос</strong><br>Казань → Египет<br>Подобрать варианты?',json_encode($tripMeta,JSON_UNESCAPED_UNICODE)]);
mw2Check('trip summary uses recorded versioned snapshot despite changed copy and markup',$tripRead()[1]===$tripMeta['trip_summary']['text']);
mw2Check('another conversation retains its own historical summary',$tripRead()[2]===$tripExpected);
$tripInsert->execute([1,'inbound','customer','Готово! Проверьте параметры',json_encode(['trip_summary'=>['version'=>1,'text'=>'CUSTOMER SPOOF']])]);
$tripInsert->execute([1,'outbound','manager','Готово! Проверьте параметры',json_encode(['trip_summary'=>['version'=>1,'text'=>'MANAGER SPOOF']])]);
mw2Check('customer and manager text cannot become an AI summary',$tripRead()[1]===$tripMeta['trip_summary']['text']);
foreach([['version'=>2,'text'=>'FUTURE'],['version'=>'1','text'=>'WRONG TYPE'],['version'=>1,'text'=>['WRONG TYPE']],null] as $tripBad)$tripInsert->execute([1,'outbound','ai','Malformed future record',json_encode(['trip_summary'=>$tripBad])]);
$tripInsert->execute([1,'outbound','ai','Malformed JSON','{"trip_summary":']);
mw2Check('invalid and unsupported metadata cannot shadow the last valid summary',$tripRead()[1]===$tripMeta['trip_summary']['text']);
$tripInsert->execute([1,'outbound','ai',$tripLegacy,null]);
mw2Check('a newer legacy summary wins over an older typed snapshot',$tripRead()[1]===$tripExpected);
$tripInsert->execute([1,'outbound','ai','New empty confirmation',json_encode(['trip_summary'=>['version'=>1,'text'=>'']])]);
mw2Check('a valid empty snapshot does not revive an older trip',$tripRead()[1]==='');
for($tripI=0;$tripI<501;++$tripI)$tripInsert->execute([2,'inbound','customer','Historical padding',null]);
mw2Check('summary remains available outside the latest 200 transcript messages',$tripRead()[2]===$tripExpected);
$tripInsert->execute([999,'outbound','ai',$tripLegacy,json_encode(['trip_summary'=>['version'=>1,'text'=>'FOREIGN']])]);
mw2Check('decoration returns only its provided authorized conversation IDs',array_keys($tripRead([2]))===[2]);
$tripBefore=$tripPdo->query('SELECT * FROM messages ORDER BY id')->fetchAll();
$tripPdo->exec('PRAGMA query_only=ON');$tripRead();$tripPdo->exec('PRAGMA query_only=OFF');
mw2Check('summary decoration never mutates historical messages',$tripBefore===$tripPdo->query('SELECT * FROM messages ORDER BY id')->fetchAll());

$tripWire=[];
$tripMax=new MaxMessengerAdapter(null,static function($chat,string $text,array $buttons)use(&$tripWire){$tripWire[]=['max',$chat,$text,$buttons];return true;});
$tripTelegram=new TelegramMessengerAdapter(static function($method,array $payload)use(&$tripWire){$tripWire[]=['telegram',$method,$payload];return ['ok'=>true,'result'=>['message_id'=>42]];});
$tripWebsite=new WebsiteMessengerAdapter();
$tripButtons=[[['text'=>'Туры','callback_data'=>'show_tours']]];
foreach([1=>$tripMax,2=>$tripTelegram,3=>$tripWebsite] as $tripId=>$tripAdapter){
 $tripHasCapability=method_exists($tripAdapter,'sendWithButtonsAndMetadata');
 mw2Check('trip metadata capability is available for channel '.$tripId,$tripHasCapability);
 if(!$tripHasCapability)continue;
 $tripCount=(int)$tripPdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
 $tripOk=$tripAdapter->sendWithButtonsAndMetadata((string)$tripId,'Новая формулировка без старой фразы',$tripButtons,$tripMeta);
 $tripRow=$tripPdo->query('SELECT * FROM messages ORDER BY id DESC LIMIT 1')->fetch();$tripSaved=json_decode($tripRow['metadata_json'],true);
 mw2Check('one successful send creates one summary-bearing original message channel '.$tripId,$tripOk&&(int)$tripPdo->query('SELECT COUNT(*) FROM messages')->fetchColumn()===$tripCount+1&&$tripSaved['trip_summary']===$tripMeta['trip_summary']&&!empty($tripSaved['has_buttons'])&&$tripRow['text']==='Новая формулировка без старой фразы');
 mw2Check('mirrored summary reaches canonical list projection channel '.$tripId,$tripRead([$tripId])[$tripId]===$tripMeta['trip_summary']['text']);
 $tripAdapter->sendWithButtons((string)$tripId,'Ordinary reply',$tripButtons);
 $tripOrdinary=$tripPdo->query('SELECT metadata_json FROM messages ORDER BY id DESC LIMIT 1')->fetchColumn();
 mw2Check('summary metadata cannot leak to the next ordinary reply channel '.$tripId,!array_key_exists('trip_summary',json_decode($tripOrdinary,true)));
}
mw2Check('MAX and Telegram wire payloads contain no internal summary metadata',!str_contains(json_encode($tripWire),'trip_summary'));
mw2Check('website response contains no internal summary metadata',!str_contains(json_encode($tripWebsite->drain()),'trip_summary'));
foreach([new MaxMessengerAdapter(null,static fn()=>false),new TelegramMessengerAdapter(static fn()=>false)] as $tripAdapter){
 if(!method_exists($tripAdapter,'sendWithButtonsAndMetadata'))continue;
 $tripCount=(int)$tripPdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
 $tripOk=$tripAdapter->sendWithButtonsAndMetadata($tripAdapter instanceof MaxMessengerAdapter?'1':'2','Failed',$tripButtons,$tripMeta);
 mw2Check('failed transport produces no recorded summary '.get_class($tripAdapter),!$tripOk&&(int)$tripPdo->query('SELECT COUNT(*) FROM messages')->fetchColumn()===$tripCount);
}
// The real confirmation producer passes the exact rendered body to the optional
// capability. A legacy implementation of the original interface still works.
require_once $root.'/services/DialogueView.php';
class MaxSearchApi
{
 public static $statusDate='date',$statusCheck='check';
 public static array $saved=['date'=>'20.10.2030','adults'=>'2'],$statuses=[],$generations=[],$events=[];
 public static function getSavedData($chat): array{return self::$saved;}
 public static function formatSavedData(array $saved): array{return ['✈️ <b>Москва</b> → Турция','👥 '.($saved['adults']??'').' взрослых'];}
 public static function funnelLog($chat,$event,array $data=[]): void{self::$events[]=$event;}
 public static function deletePrevMessage($chat): void{}
 public static function setStatus($chat,$status): void{self::$statuses[]=[$chat,$status];}
 public static function saveLastValue($chat,$status,$value): void{self::$generations[]=[$chat,$status,$value];}
}
class TripSummaryLegacyMessenger implements MessengerInterface
{
 public array $sent=[];
 public function send($chat,string $text): bool{return true;}
 public function sendWithButtons($chat,string $text,array $buttons): bool{$this->sent[]=[$chat,$text,$buttons];return true;}
 public function sendContactRequest($chat,string $text,string $manual,string $back): bool{return false;}
}
$tripWire=[];IntegrationRegistry::useMessenger($tripMax);
$tripCount=(int)$tripPdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
$tripOk=DialogueView::check('1');
$tripRow=$tripPdo->query('SELECT * FROM messages ORDER BY id DESC LIMIT 1')->fetch();
$tripProduced=json_decode($tripRow['metadata_json'],true)['trip_summary']??null;
$tripDate='📅 Вылет 20.10.2030 · поиск на эту дату';
mw2Check('actual check producer records exact displayed body and selected-date policy',$tripOk&&$tripProduced===['version'=>1,'text'=>$tripExpected.' '.$tripDate]&&(int)$tripPdo->query('SELECT COUNT(*) FROM messages')->fetchColumn()===$tripCount+1);
mw2Check('actual check keeps original visible copy and callback buttons',$tripWire[0][2]==="✅ <b>Готово! Проверьте параметры</b>\n\n✈️ <b>Москва</b> → Турция\n👥 2 взрослых\n".$tripDate."\n\nЧто удобнее дальше?"&&count($tripWire[0][3])===3&&MaxSearchApi::$saved===['date'=>'20.10.2030','adults'=>'2']);
mw2Check('actual check keeps successful status and one generation write',MaxSearchApi::$statuses===[['1','check']]&&count(MaxSearchApi::$generations)===1&&MaxSearchApi::$events===['search_ready']);
MaxSearchApi::$saved['adults']='3';
mw2Check('editing current trip values without a confirmation preserves the recorded snapshot',$tripRead([1])[1]===$tripExpected.' '.$tripDate);
MaxSearchApi::$saved['adults']='2';
$tripLegacyMessenger=new TripSummaryLegacyMessenger();IntegrationRegistry::useMessenger($tripLegacyMessenger);DialogueView::check('1');
$tripBaseButtons=static function(array $rows): array {foreach($rows as &$row)foreach($row as &$button)if(isset($button['callback_data']))$button['callback_data']=CallbackGeneration::base($button['callback_data']);return $rows;};
mw2Check('original three-method messenger remains compatible without capability',count($tripLegacyMessenger->sent)===1&&$tripLegacyMessenger->sent[0][1]===$tripWire[0][2]&&$tripBaseButtons($tripLegacyMessenger->sent[0][2])===$tripBaseButtons($tripWire[0][3]));
IntegrationRegistry::useMessenger(new MaxMessengerAdapter(null,static fn()=>false));MaxSearchApi::$statuses=[];MaxSearchApi::$generations=[];
$tripCount=(int)$tripPdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();$tripOk=DialogueView::check('1');
mw2Check('failed confirmation changes neither recorded summary nor status or generation',!$tripOk&&MaxSearchApi::$statuses===[]&&MaxSearchApi::$generations===[]&&(int)$tripPdo->query('SELECT COUNT(*) FROM messages')->fetchColumn()===$tripCount);
$tripEscaped=TripSummaryProjection::metadata(['Отель <b>A &amp; B</b> · &lt;Не уточнён&gt;']);
mw2Check('snapshot entities and literal angle-bracket text are decoded exactly once',TripSummaryProjection::fromMessage(['metadata_json'=>json_encode($tripEscaped)])==='Отель A & B · <Не уточнён>');
mw2Check('legacy entities and literal angle-bracket text retain the old projection',TripSummaryProjection::fromMessage(['text'=>"✅ Готово! Проверьте параметры Отель A &amp; B · &lt;Не уточнён&gt; Что удобнее дальше?"])==='Отель A & B · <Не уточнён>');
ConversationDb::resetForTests();
