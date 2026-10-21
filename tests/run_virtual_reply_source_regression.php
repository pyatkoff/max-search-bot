<?php

declare(strict_types=1);

require_once __DIR__.'/../services/ProjectConfig.php';
require_once __DIR__.'/../services/VirtualReplySourceReader.php';

$passed = 0; $failed = 0;
function sourceCheck(string $name, bool $ok): void {
    global $passed, $failed;
    echo ($ok ? 'PASS ' : 'FAIL ').$name."\n";
    $ok ? $passed++ : $failed++;
}
function sourceDb(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE projects(project_key TEXT PRIMARY KEY,is_active INTEGER);
        CREATE TABLE conversations(id INTEGER PRIMARY KEY,project_key TEXT,is_test INTEGER,channel TEXT,customer_id INTEGER,customer_channel_id INTEGER);
        CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT);
        CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,direction TEXT,sender_type TEXT,text TEXT,metadata_json TEXT,created_at TEXT);
        CREATE TABLE telegram_dialogue_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,project_key TEXT,account_id INTEGER,chat_id INTEGER,message_id INTEGER,version_at INTEGER,source_kind TEXT,is_deleted INTEGER,record_json TEXT);
        CREATE TABLE telegram_archive_accounts(project_key TEXT,bot_key TEXT,account_id INTEGER,connection_id TEXT,approved INTEGER,provider_enabled INTEGER,manager_id INTEGER);
        CREATE TABLE telegram_dialogue_connections(project_key TEXT,bot_key TEXT,connection_id TEXT,account_id INTEGER,is_enabled INTEGER);
        INSERT INTO projects VALUES ("anytour",1),("other",1);
        INSERT INTO conversations(id,project_key,is_test,channel) VALUES (101,"anytour",0,"telegram"),(2,"other",0,"telegram"),(3,"anytour",1,"max");
        INSERT INTO telegram_archive_accounts VALUES ("anytour","archive",100,"current",1,1,7);
        INSERT INTO telegram_dialogue_connections VALUES ("anytour","archive","current",100,1);');
    return $pdo;
}
function sourceMessage(PDO $pdo, int $id, string $sender, string $text, array $meta=[], string $at='2026-10-20 10:00:00', int $conversation=101): void {
    $pdo->prepare('INSERT INTO messages VALUES (?,?,?,?,?,?,?)')->execute([$id,$conversation,$sender==='customer'?'inbound':'outbound',$sender,$text,json_encode($meta,JSON_THROW_ON_ERROR),$at]);
}
function sourceArchive(PDO $pdo, int $native, string $sender, string $text, int $created=1792490400, array $extra=[], string $project='anytour', int $account=100, int $chat=200): int {
    $record = array_replace(['account_id'=>(string)$account,'chat_id'=>(string)$chat,'message_id'=>(string)$native,
        'sender_type'=>$sender,'sender_id'=>(string)($sender==='customer'?$chat:$account), 'direction'=>$sender==='customer'?'inbound':'outbound',
        'text'=>$text,'created_at'=>$created,'version_at'=>$created,'source_kind'=>'business_message','is_deleted'=>false,
        'bot_key'=>'archive','connection_id'=>'old-connection','attachments'=>[],'links'=>[]],$extra);
    $pdo->prepare('INSERT INTO telegram_dialogue_messages(project_key,account_id,chat_id,message_id,version_at,source_kind,is_deleted,record_json) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$project,$account,$chat,$native,$record['version_at'],$record['source_kind'],(int)$record['is_deleted'],json_encode($record,JSON_THROW_ON_ERROR)]);
    return (int)$pdo->lastInsertId();
}
function sourceArchiveEdit(PDO $pdo, int $id, array $patch): void {
    $q=$pdo->prepare('SELECT record_json FROM telegram_dialogue_messages WHERE id=?'); $q->execute([$id]);
    $record=array_replace(json_decode((string)$q->fetchColumn(),true,64,JSON_THROW_ON_ERROR),$patch);
    $pdo->prepare('UPDATE telegram_dialogue_messages SET record_json=?,version_at=?,is_deleted=? WHERE id=?')
        ->execute([json_encode($record,JSON_THROW_ON_ERROR),$record['version_at'],(int)$record['is_deleted'],$id]);
}

/** Fail if the identity lookup materializes content instead of scalar permission metadata. */
final class SourceIdentityOnlyStatement extends PDOStatement {
    protected function __construct() {}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed {
        $row=parent::fetch($mode,$orientation,$offset);
        if (is_array($row) && array_intersect(['record_json','text','metadata_json','payload_json'],array_keys($row))) {
            throw new RuntimeException('source_payload_materialized');
        }
        return $row;
    }
}

ProjectConfig::resetForTests(['id'=>'anytour']);
$pdo=sourceDb(); $reader=new VirtualReplySourceReader($pdo);
sourceMessage($pdo,1,'customer','Египет, трое взрослых, до 250 тысяч за всех',[], '2026-10-20 09:58:00');
sourceMessage($pdo,2,'manager','Вариант https://qui-quo.ru/synthetic, связь +7 999 123 45 67',[], '2026-10-20 09:59:00');
sourceMessage($pdo,3,'customer','А на 25 октября?',[], '2026-10-20 10:00:00');
sourceMessage($pdo,4,'manager','FUTURE ANSWER',[], '2026-10-20 10:01:00');
sourceMessage($pdo,5,'customer','FUTURE REQUIREMENTS',[], '2026-10-20 10:02:00');
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
$snapshot=$reader->snapshot('conversation',3);
sourceCheck('ordinary prefix ends at the real tourist question', $snapshot!==null && $snapshot['evidence_message_ids']===[1,2,3]
    && $snapshot['payload']['messages'][0]['sender_type']==='tourist' && !str_contains(json_encode($snapshot),'FUTURE'));
sourceCheck('reference time is UTC and ordinary source stays live', $snapshot['reference_time']==='2026-10-20T10:00:00Z' && $snapshot['mode']==='live');
sourceCheck('projection hides contact and selection contents', !str_contains(json_encode($snapshot['payload']),'999 123')
    && $snapshot['payload']['messages'][1]['selections_link_unread']);
sourceCheck('capture does not write source rows or leave a transaction', (int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before && !$pdo->inTransaction());
sourceMessage($pdo,6,'customer','ANOTHER FUTURE',[], '2026-10-20 10:03:00');
sourceCheck('later appended messages cannot change the frozen prefix', $reader->snapshot('conversation',3)['source_revision']===$snapshot['source_revision']);
$pdo->prepare('UPDATE messages SET text=? WHERE id=2')->execute(['Вариант https://qui-quo.ru/other, связь +7 999 123 45 67']);
sourceCheck('raw source edits invalidate even identical redacted text', $reader->snapshot('conversation',3)['source_revision']!==$snapshot['source_revision']);
$pdo->prepare('UPDATE messages SET text=?,metadata_json=? WHERE id=1')->execute(['LATER EDITED REQUIREMENTS',json_encode(['edited_at'=>'2026-10-20 10:04:00'])]);
$edited=$reader->snapshot('conversation',3);
sourceCheck('historical prefix never passes a later edited text as original', $edited['payload']['history_incomplete']
    && $edited['payload']['historical_context_reconstructed'] && $edited['payload']['messages'][0]['source_text_unavailable']
    && !str_contains(json_encode($edited['payload']),'LATER EDITED'));
$pdo->prepare('UPDATE messages SET text=?,metadata_json=? WHERE id=3')->execute(['А на 26 октября?',json_encode(['edited_at'=>'2026-10-20 10:05:00'])]);
$revised=$reader->snapshot('conversation',3);
sourceCheck('edited question uses its known latest version time without later IDs', $revised['reference_time']==='2026-10-20T10:05:00Z'
    && $revised['payload']['revised_question'] && $revised['evidence_message_ids']===[1,2,3] && !str_contains(json_encode($revised),'FUTURE'));
$pdo->prepare('UPDATE messages SET metadata_json=? WHERE id=3')->execute([json_encode(['edited_at'=>'ambiguous'])]);
sourceCheck('ambiguous question edit time is unavailable', $reader->snapshot('conversation',3)===null);
$pdo->prepare('UPDATE messages SET metadata_json=? WHERE id=3')->execute(['{}']);
sourceMessage($pdo,7,'customer','callback payload',['type'=>'callback']);
sourceMessage($pdo,8,'customer','+7 999 123 45 67',['type'=>'contact']);
sourceMessage($pdo,9,'customer','/start link',['type'=>'start']);
sourceMessage($pdo,10,'customer','📎 Фото',['type'=>'message','attachments'=>[['type'=>'image']]]);
sourceMessage($pdo,11,'customer'," \n\t");
sourceMessage($pdo,12,'customer','Other tenant',[], '2026-10-20 10:00:00',2);
sourceMessage($pdo,13,'customer','Test dialogue',[], '2026-10-20 10:00:00',3);
sourceCheck('nonquestion, service, media-only, test and other-project rows are refused',
    array_filter(array_map(fn(int $id)=>$reader->snapshot('conversation',$id),[4,7,8,9,10,11,12,13]))===[]);
$scan=$reader->scan('conversation',0,2); $scan2=$reader->scan('conversation',$scan['last_id'],2);
sourceCheck('keyset scan is bounded and advances over noncandidate rows', $scan===['source_ids'=>[1,2],'last_id'=>2,'has_more'=>true] && $scan2['source_ids']===[3,4]);
$recent=$reader->recent('conversation',2);
sourceCheck('fresh bootstrap selects latest scoped IDs in ascending order', $recent===['source_ids'=>[10,11],'last_id'=>11,'has_more'=>true]);
$pdo->beginTransaction();
sourceCheck('source recheck refuses a caller transaction without closing it', $reader->snapshot('conversation',3)===null && $pdo->inTransaction());
$pdo->rollBack();
$pdo->exec('UPDATE projects SET is_active=0 WHERE project_key="anytour"');
sourceCheck('inactive project is unavailable for discovery and capture', $reader->snapshot('conversation',3)===null && $reader->scan('conversation',0,10)['source_ids']===[]);
$pdo->exec('UPDATE projects SET is_active=1 WHERE project_key="anytour"');
ProjectConfig::resetForTests(['id'=>'other']);
sourceCheck('wrong current project cannot capture AnyTour sources', $reader->snapshot('conversation',3)===null && $reader->recent('conversation',2)['source_ids']===[]);
ProjectConfig::resetForTests(['id'=>'anytour']);

$prior=sourceArchive($pdo,9,'manager','Ранее выбран Египет',1792490340);
$question=sourceArchive($pdo,10,'customer','Можно на троих?',1792490400);
$future=sourceArchive($pdo,11,'manager','ARCHIVE FUTURE',1792490460);
$import=sourceArchive($pdo,8,'customer','Трое взрослых',1792490280,['source_kind'=>'desktop_import','bot_key'=>null,'connection_id'=>null]);
$archive=$reader->snapshot('telegram_archive',$question);
sourceCheck('archive cutoff follows native IDs despite out-of-order import row IDs', $archive!==null && $archive['evidence_message_ids']===[$import,$prior,$question]
    && $archive['mode']==='live' && !str_contains(json_encode($archive),'ARCHIVE FUTURE'));
sourceCheck('historical import remains explicit historical replay', $reader->snapshot('telegram_archive',$import)['mode']==='historical_replay');
sourceCheck('archive payload excludes account, chat and connection identities', !str_contains(json_encode($archive['payload']),'account_id')
    && !str_contains(json_encode($archive['payload']),'old-connection') && !str_contains(json_encode($archive['payload']),'manager_id'));
sourceArchive($pdo,12,'customer','MORE ARCHIVE FUTURE',1792490520);
sourceCheck('archive append does not invalidate an earlier frozen question', $reader->snapshot('telegram_archive',$question)['source_revision']===$archive['source_revision']);
sourceArchiveEdit($pdo,$prior,['text'=>'LATER ARCHIVE EDIT','version_at'=>1792491000]);
$archiveEdited=$reader->snapshot('telegram_archive',$question);
sourceCheck('archive later edits are marked unavailable and revision changes', $archiveEdited['source_revision']!==$archive['source_revision']
    && $archiveEdited['payload']['messages'][1]['source_text_unavailable'] && !str_contains(json_encode($archiveEdited['payload']),'LATER ARCHIVE EDIT'));
sourceArchiveEdit($pdo,$question,['text'=>'Можно на четверых?','version_at'=>1792491060]);
$archiveRevised=$reader->snapshot('telegram_archive',$question);
sourceCheck('archive question latest edit has explicit reconstructed cutoff', $archiveRevised['payload']['revised_question']
    && $archiveRevised['reference_time']===gmdate('Y-m-d\TH:i:s\Z',1792491060) && $archiveRevised['evidence_message_ids']===[$import,$prior,$question]);
foreach (['approved','provider_enabled'] as $flag) {
    $pdo->exec('UPDATE telegram_archive_accounts SET '.$flag.'=0');
    sourceCheck('archive requires current account '.$flag, $reader->snapshot('telegram_archive',$question)===null);
    $pdo->exec('UPDATE telegram_archive_accounts SET '.$flag.'=1');
}
$pdo->exec('UPDATE telegram_dialogue_connections SET is_enabled=0');
sourceCheck('archive requires enabled current connection', $reader->snapshot('telegram_archive',$question)===null);
$pdo->exec('UPDATE telegram_dialogue_connections SET is_enabled=1,account_id=999');
sourceCheck('connection must belong to the approved account', $reader->snapshot('telegram_archive',$question)===null);
$pdo->exec('UPDATE telegram_dialogue_connections SET account_id=100');
$pdo->exec('UPDATE telegram_archive_accounts SET manager_id=8');
sourceCheck('permission scope changes invalidate captured cases', $reader->snapshot('telegram_archive',$question)['source_revision']!==$archiveRevised['source_revision']);
$otherBot=sourceArchive($pdo,14,'customer','Другой бот',1792490400,['bot_key'=>'unapproved']);
sourceCheck('one approved bot cannot authorize a different bot source', $reader->snapshot('telegram_archive',$otherBot)===null);
$unknown=sourceArchive($pdo,15,'unknown','Автор неизвестен');
$media=sourceArchive($pdo,16,'customer','',1792490400,['attachments'=>[['type'=>'image']]]);
sourceCheck('unknown author and archive media-only row are not questions', $reader->snapshot('telegram_archive',$unknown)===null && $reader->snapshot('telegram_archive',$media)===null);
sourceArchiveEdit($pdo,$import,['is_deleted'=>true,'text'=>null,'sender_type'=>'unknown']);
$deletedPrior=$reader->snapshot('telegram_archive',$question);
sourceCheck('in-place deletion of earlier archive evidence invalidates prefix', $deletedPrior['source_revision']!==$archiveRevised['source_revision']
    && $deletedPrior['payload']['messages'][0]['source_text_unavailable']);
sourceArchiveEdit($pdo,$question,['is_deleted'=>true,'text'=>null,'sender_type'=>'unknown']);
sourceCheck('deleted question is unavailable even without a newer ID or timestamp', $reader->snapshot('telegram_archive',$question)===null);
$mismatch=sourceArchive($pdo,17,'customer','Inconsistent source');
$pdo->prepare('UPDATE telegram_dialogue_messages SET chat_id=201 WHERE id=?')->execute([$mismatch]);
sourceCheck('archive JSON and relational scope must agree', $reader->snapshot('telegram_archive',$mismatch)===null);

$eligibilityDb=sourceDb(); $eligibilityReader=new VirtualReplySourceReader($eligibilityDb);
// Synthetic account-service templates and technical probes, never live exports.
$excludedTexts=[
    'Тестирую', 'ТЕСТ АРХИВА 42!', 'Тест связи 77',
    "Код для входа в Ваш аккаунт Telegram: 12345.\nНе давайте код никому, даже если его требуют от имени Telegram!",
    'Login code: 54321. Do not give this code to anyone, even if they say they are from Telegram!',
    "  Код\u{00A0}для входа в ваш аккаунт Telegram: 65432.\nНе давайте код никому!",
];
$excludedArchive=[];
foreach($excludedTexts as $i=>$text) {
    sourceMessage($eligibilityDb,$i+1,'customer',$text);
    $excludedArchive[]=sourceArchive($eligibilityDb,$i+1,'customer',$text);
}
sourceCheck('technical tests and account-login notices are not ordinary learning questions',
    array_filter(array_map(fn(int $id)=>$eligibilityReader->snapshot('conversation',$id),range(1,count($excludedTexts))))===[]);
sourceCheck('technical tests and account-login notices are not archive learning questions',
    array_filter(array_map(fn(int $id)=>$eligibilityReader->snapshot('telegram_archive',$id),$excludedArchive))===[]);
sourceMessage($eligibilityDb,20,'customer','Нужен тур в Египет на троих');
$cleanConversation=$eligibilityReader->snapshot('conversation',20);
sourceCheck('non-tourist service text is also absent from a later ordinary prefix',
    $cleanConversation!==null && $cleanConversation['evidence_message_ids']===[20]);
$cleanArchiveId=sourceArchive($eligibilityDb,20,'customer','Нужен тур в Египет на троих');
$cleanArchive=$eligibilityReader->snapshot('telegram_archive',$cleanArchiveId);
sourceCheck('non-tourist service text is also absent from a later archive prefix',
    $cleanArchive!==null && $cleanArchive['evidence_message_ids']===[$cleanArchiveId]);
$servicePeer=sourceArchive($eligibilityDb,25,'customer','Synthetic service announcement',1792490400,[],'anytour',100,777000);
$serviceSender=sourceArchive($eligibilityDb,26,'customer','Synthetic service announcement',1792490400,['sender_id'=>'777000']);
sourceCheck('canonical Telegram service peer is excluded independently of text or normalized role',
    $eligibilityReader->snapshot('telegram_archive',$servicePeer)===null && $eligibilityReader->snapshot('telegram_archive',$serviceSender)===null);
$ordinaryPeer=sourceArchive($eligibilityDb,27,'customer','Нужен тур в Египет',1792490400,[],'anytour',100,777001);
sourceCheck('ordinary private peer is not rejected by a partial identity match',
    $eligibilityReader->snapshot('telegram_archive',$ordinaryPeer)!==null);
$travelCounterexamples=[
    'Нужен ли тест на ковид для Турции?',
    'Пришлите код бронирования 12345',
    'Тестирую разные варианты отдыха: Турция или Египет?',
    'Код для входа в личный кабинет отеля не приходит, что делать?',
    'Подскажите статус бронирования 777000',
];
foreach($travelCounterexamples as $i=>$text) {
    $id=30+$i;sourceMessage($eligibilityDb,$id,'customer',$text);
    $archiveId=sourceArchive($eligibilityDb,$id,'customer',$text);
    sourceCheck('travel and booking counterexample remains eligible '.($i+1),
        $eligibilityReader->snapshot('conversation',$id)!==null && $eligibilityReader->snapshot('telegram_archive',$archiveId)!==null);
}

$bounded=sourceDb(); $boundedReader=new VirtualReplySourceReader($bounded);
for($i=1;$i<=125;$i++) sourceMessage($bounded,$i,'customer','Условие '.$i);
$cap=$boundedReader->snapshot('conversation',125);
sourceCheck('message bound preserves latest question and reports missing history', count($cap['payload']['messages'])===120
    && $cap['evidence_message_ids'][0]===6 && $cap['evidence_message_ids'][119]===125 && $cap['payload']['history_incomplete']);
sourceMessage($bounded,126,'customer',str_repeat('Я',40001));
$textCap=$boundedReader->snapshot('conversation',126);
sourceCheck('character bound is Unicode-safe and explicit for oversized question', count($textCap['payload']['messages'])===1
    && mb_strlen($textCap['payload']['messages'][0]['text'],'UTF-8')===40000 && $textCap['payload']['messages'][0]['text_truncated'] && $textCap['payload']['history_incomplete']);
sourceCheck('invalid input cannot broaden source scope', $reader->snapshot('invalid',3)===null && $reader->snapshot('conversation',0)===null
    && $reader->scan('invalid',0,10)['source_ids']===[] && $reader->scan('conversation',-1,10)['source_ids']===[] && $reader->recent('conversation',0)['source_ids']===[]);

$rawDb=sourceDb();$rawReader=new VirtualReplySourceReader($rawDb);
for ($i=1;$i<=8;$i++) sourceMessage($rawDb,$i,'manager','Предыдущее условие',['opaque'=>str_repeat('x',70000)]);
sourceMessage($rawDb,9,'customer','Египет на троих');
$rawCap=$rawReader->snapshot('conversation',9);
sourceCheck('large raw metadata is bounded before materialization with explicit missing history',
    $rawCap!==null&&$rawCap['evidence_message_ids']===[6,7,8,9]&&$rawCap['payload']['history_incomplete']);
$rawDb->prepare('UPDATE messages SET metadata_json=? WHERE id=8')->execute([json_encode(['opaque'=>str_repeat('y',70000)])]);
sourceCheck('bounded raw prefix still detects edits hidden by projection',
    $rawReader->snapshot('conversation',9)['source_revision']!==$rawCap['source_revision']);
$missing=sourceDb();$missingReader=new VirtualReplySourceReader($missing);$missing->exec('DROP TABLE messages');
foreach (['snapshot'=>static fn()=>$missingReader->snapshot('conversation',1),
    'scan'=>static fn()=>$missingReader->scan('conversation',0,1),
    'recent'=>static fn()=>$missingReader->recent('conversation',1)] as $operation=>$call) {
    $safeFailure=false;
    try {$call();} catch (RuntimeException $error) {$safeFailure=$error->getMessage()==='virtual_reply_source_unavailable'&&$error->getPrevious()===null;}
    sourceCheck('technical '.$operation.' failure cannot masquerade as deletion or empty success',$safeFailure&&!$missing->inTransaction());
}

$internalDb=sourceDb();$internalReader=new VirtualReplySourceReader($internalDb);
$internalDb->exec('INSERT INTO conversations VALUES(1,"anytour",0,"telegram",11,71),(4,"anytour",0,"max",12,72);
    INSERT INTO customer_channels VALUES(71,11,"anytour","telegram","91001","91001"),(72,12,"anytour","max","91002","91002")');
// The MAX internal coordinate is2; keep the pre-existing other-project
// counterexample distinct before creating that exact synthetic scope.
$internalDb->exec('UPDATE conversations SET id=202 WHERE id=2; UPDATE conversations SET id=2 WHERE id=4');
sourceMessage($internalDb,200,'customer','Synthetic internal demo',[], '2026-10-20 10:00:00',1);
sourceMessage($internalDb,201,'customer','Synthetic internal demo',[], '2026-10-20 10:00:00',2);
sourceMessage($internalDb,202,'customer','Alex asks about a holiday gift card');
sourceMessage($internalDb,203,'customer','Later synthetic internal demo',[], '2026-10-20 10:01:00',1);
$internalBefore=(int)$internalDb->query('SELECT total_changes()')->fetchColumn();
sourceCheck('ordinary owner questions are excluded before both discovery limits and private snapshots',
    $internalReader->snapshot('conversation',200)===null && $internalReader->snapshot('conversation',201)===null
    && $internalReader->snapshot('conversation',202)!==null
    && $internalReader->scan('conversation',199,1)===['source_ids'=>[202],'last_id'=>202,'has_more'=>false]
    && $internalReader->recent('conversation',1)===['source_ids'=>[202],'last_id'=>202,'has_more'=>false]
    && (int)$internalDb->query('SELECT total_changes()')->fetchColumn()===$internalBefore);
$internalDb->exec("UPDATE conversations SET channel='website' WHERE id=1; UPDATE conversations SET channel='telegram' WHERE id=2");
sourceCheck('the same numeric IDs in other channels are not classified as owner dialogues',
    $internalReader->snapshot('conversation',200)!==null && $internalReader->snapshot('conversation',201)!==null);
$internalDb->exec("UPDATE conversations SET channel='telegram' WHERE id=1");
$ownerArchive=sourceArchive($internalDb,1,'customer','Synthetic internal demo',1792490400,[],'anytour',100,91001);
$touristArchive=sourceArchive($internalDb,2,'customer','Alex asks about a holiday gift card',1792490400,[],'anytour',100,92002);
$ownerArchiveLate=sourceArchive($internalDb,3,'customer','Later synthetic internal demo',1792490460,[],'anytour',100,91001);
sourceCheck('only the canonically bound owner archive peer is excluded within an approved account',
    $internalReader->snapshot('telegram_archive',$ownerArchive)===null
    && $internalReader->snapshot('telegram_archive',$touristArchive)!==null
    && $internalReader->scan('telegram_archive',0,1)===['source_ids'=>[$touristArchive],'last_id'=>$touristArchive,'has_more'=>false]
    && $internalReader->recent('telegram_archive',1)===['source_ids'=>[$touristArchive],'last_id'=>$touristArchive,'has_more'=>false]);
$internalDb->exec("UPDATE customer_channels SET external_user_id='091001',external_chat_id='091001' WHERE id=71");
sourceCheck('archive peer equality preserves leading zeros instead of numeric coercion',
    $internalReader->snapshot('telegram_archive',$ownerArchive)!==null);
$internalDb->exec("UPDATE customer_channels SET external_user_id='91001',external_chat_id='91001' WHERE id=71");
$internalDb->exec('UPDATE customer_channels SET customer_id=99 WHERE id=71');
sourceCheck('an unbound peer identity cannot blacklist an unrelated archive chat',
    $internalReader->snapshot('telegram_archive',$ownerArchive)!==null);
$badAlias=false;try{InternalOwnerDialogueScope::conversationSql('c;invalid');}catch(InvalidArgumentException $error){$badAlias=true;}
sourceCheck('internal SQL helpers refuse caller-controlled alias syntax',$badAlias);

// Synthetic whole-dialogue identities are private metadata, not source evidence.
$groupDb=sourceDb();$groupReader=new VirtualReplySourceReader($groupDb);
sourceMessage($groupDb,901,'customer','Египет на двоих');
$unboundGroup=$groupReader->snapshot('conversation',901);
$groupDb->exec('INSERT INTO customer_channels VALUES(81,22,"anytour","telegram","300","300");
    UPDATE conversations SET customer_id=22,customer_channel_id=81 WHERE id=101');
$boundGroup=$groupReader->snapshot('conversation',901);
$archiveGroupId=sourceArchive($groupDb,31,'customer','А на десять ночей?',1792490400,[], 'anytour',100,300);
$archiveGroup=$groupReader->snapshot('telegram_archive',$archiveGroupId);
sourceCheck('validated live and archive peers share a whole-dialogue alias',
    count(array_intersect($boundGroup['sampling']['identity_keys'],$archiveGroup['sampling']['identity_keys']))===1
    && $boundGroup['sampling']['identity_complete'] && $archiveGroup['sampling']['identity_complete']);
sourceCheck('group backfill leaves frozen payload and raw source revision untouched',
    $unboundGroup['source_revision']===$boundGroup['source_revision'] && $unboundGroup['payload']===$boundGroup['payload']
    && $unboundGroup['sampling']['identity_keys']!==$boundGroup['sampling']['identity_keys']);
sourceMessage($groupDb,902,'customer','Спасибо!');
$neighborGroup=$groupReader->snapshot('conversation',902);
sourceCheck('neighboring questions retain their dialogue aliases with narrow social classification',
    $neighborGroup['sampling']['identity_keys']===$boundGroup['sampling']['identity_keys']
    && $neighborGroup['sampling']['kind']==='social' && $boundGroup['sampling']['kind']==='substantive');
$groupDb->exec('INSERT INTO telegram_archive_accounts VALUES("anytour","archive",101,"second",1,1,7);
    INSERT INTO telegram_dialogue_connections VALUES("anytour","archive","second",101,1)');
$otherAccountId=sourceArchive($groupDb,31,'customer','А на десять ночей?',1792490400,[], 'anytour',101,300);
sourceCheck('the same private archive peer is stable across approved accounts and import order',
    $groupReader->snapshot('telegram_archive',$otherAccountId)['sampling']['identity_keys']===$archiveGroup['sampling']['identity_keys']);
$groupDb->exec("UPDATE customer_channels SET external_user_id='0300' WHERE id=81");
$ambiguousGroup=$groupReader->snapshot('conversation',901);
sourceCheck('ambiguous or noncanonical bindings cannot link or reserve a private peer',
    !$ambiguousGroup['sampling']['identity_complete']
    && array_intersect($ambiguousGroup['sampling']['identity_keys'],$archiveGroup['sampling']['identity_keys'])===[]);
sourceCheck('group identity values are hashes outside the model payload',
    !array_key_exists('sampling',$boundGroup['payload'])
    && array_filter($boundGroup['sampling']['identity_keys'],static fn(string $v):bool=>preg_match('/\A[a-f0-9]{64}\z/D',$v)!==1)===[]);
sourceCheck('a substantive request is not a social-only message because it contains a greeting or thanks',
    VirtualReplySamplingIdentity::kind('Спасибо, а сколько стоит?')==='substantive'
    && VirtualReplySamplingIdentity::kind('Здравствуйте, нужен тур на море')==='substantive'
    && VirtualReplySamplingIdentity::kind('Спасибо! 🙏')==='social');
sourceCheck('negative archive chats cannot claim private-person identity completeness',
    !VirtualReplySamplingIdentity::archive('-300','Египет')['identity_complete']);

// Exposure repair reads entity identity only, including when all content is
// inaccessible. A minimal schema deliberately has no message text/payload.
$identityDb=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$identityDb->exec('CREATE TABLE projects(project_key TEXT PRIMARY KEY,is_active INTEGER);
    CREATE TABLE conversations(id INTEGER PRIMARY KEY,project_key TEXT,is_test INTEGER,channel TEXT,customer_id INTEGER,customer_channel_id INTEGER);
    CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT);
    CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER);
    INSERT INTO projects VALUES ("anytour",1),("other",1);
    INSERT INTO conversations VALUES (1,"anytour",0,"telegram",11,71),(2,"anytour",1,"max",12,72),(3,"other",0,"telegram",13,73);
    INSERT INTO customer_channels VALUES (71,11,"anytour","telegram","91001","91001"),(72,12,"anytour","max","91002","91002"),(73,13,"other","telegram","91003","91003");
    INSERT INTO messages VALUES (1,1),(2,2),(3,3),(4,99);');
$identityReader=new VirtualReplySourceReader($identityDb);$identityWrites=(int)$identityDb->query('SELECT total_changes()')->fetchColumn();
$ownerIdentity=$identityReader->exposureIdentity('conversation',1);
$testIdentity=$identityReader->exposureIdentity('conversation',2);
sourceCheck('identity-only exposure repair maps previously captured internal/test scope without reading source text or payload',
    $ownerIdentity===VirtualReplySamplingIdentity::conversation(1,11,'telegram',['91001','91001'],'')
    && $testIdentity===VirtualReplySamplingIdentity::conversation(2,12,'max',['91002','91002'],'')
    && $ownerIdentity['kind']==='substantive' && (int)$identityDb->query('SELECT total_changes()')->fetchColumn()===$identityWrites
    && !$identityDb->inTransaction());
sourceCheck('identity-only exposure values contain only hashes, completeness and unknown substantive classification',
    array_keys($ownerIdentity)===['identity_keys','identity_complete','kind']
    && array_filter($ownerIdentity['identity_keys'],static fn($key):bool=>!is_string($key)||preg_match('/\A[a-f0-9]{64}\z/D',$key)!==1)===[]
    && !str_contains(json_encode($ownerIdentity,JSON_THROW_ON_ERROR),'91001'));
sourceCheck('identity-only scope excludes foreign, missing and orphaned rows without source discovery',
    $identityReader->exposureIdentity('conversation',3)===null && $identityReader->exposureIdentity('conversation',4)===null
    && $identityReader->exposureIdentity('conversation',99)===null && $identityReader->exposureIdentity('invalid',1)===null
    && $identityReader->exposureIdentity('conversation',0)===null);
$identityDb->exec('UPDATE customer_channels SET customer_id=99 WHERE id=71');
$unboundIdentity=$identityReader->exposureIdentity('conversation',1);
sourceCheck('identity-only repair cannot invent a peer alias from a mismatched channel/customer binding',
    !$unboundIdentity['identity_complete'] && count($unboundIdentity['identity_keys'])===2
    && array_intersect($unboundIdentity['identity_keys'],VirtualReplySamplingIdentity::archive('91001','')['identity_keys'])===[]);
$identityDb->exec('UPDATE customer_channels SET customer_id=11,external_user_id="091001" WHERE id=71');
$ambiguousIdentity=$identityReader->exposureIdentity('conversation',1);
sourceCheck('identity-only repair preserves canonical positive Telegram peer matching',
    !$ambiguousIdentity['identity_complete']
    && array_intersect($ambiguousIdentity['identity_keys'],VirtualReplySamplingIdentity::archive('91001','')['identity_keys'])===[]);
$identityDb->exec('UPDATE projects SET is_active=0 WHERE project_key="anytour"');
sourceCheck('inactive projects deny identity-only exposure repair', $identityReader->exposureIdentity('conversation',1)===null);
$identityDb->exec('UPDATE projects SET is_active=1 WHERE project_key="anytour"');
ProjectConfig::resetForTests(['id'=>'other']);
sourceCheck('wrong current project cannot inspect AnyTour exposure identities',$identityReader->exposureIdentity('conversation',1)===null);
ProjectConfig::resetForTests(['id'=>'anytour']);
$identityDb->beginTransaction();
sourceCheck('identity-only recheck refuses a caller snapshot without closing it',
    $identityReader->exposureIdentity('conversation',1)===null && $identityDb->inTransaction());$identityDb->rollBack();

$excludedDb=sourceDb();$excludedReader=new VirtualReplySourceReader($excludedDb);
$excludedDb->exec('UPDATE conversations SET customer_id=22,customer_channel_id=81 WHERE id=101;
    INSERT INTO customer_channels VALUES (81,22,"anytour","telegram","300","300")');
sourceMessage($excludedDb,1,'customer','Already deleted',['is_deleted'=>true]);
sourceMessage($excludedDb,2,'manager','Nonquestion');
sourceMessage($excludedDb,3,'customer','/start link',['type'=>'start']);
$excludedIdentity=$excludedReader->exposureIdentity('conversation',1);
sourceCheck('permitted deleted/filtered/nonquestion identities map without making their snapshots eligible',
    $excludedIdentity!==null && $excludedReader->exposureIdentity('conversation',2)===$excludedIdentity
    && $excludedReader->exposureIdentity('conversation',3)===$excludedIdentity
    && $excludedReader->snapshot('conversation',1)===null && $excludedReader->snapshot('conversation',2)===null
    && $excludedReader->snapshot('conversation',3)===null);
$excludedDb->exec('DELETE FROM messages WHERE id=1');
sourceCheck('hard-deleted exposure sources cannot synthesize an identity from a former source ID',
    $excludedReader->exposureIdentity('conversation',1)===null);
$identityDb->exec('DROP TABLE customer_channels');$identityFailure=false;
try {$identityReader->exposureIdentity('conversation',1);} catch (RuntimeException $error) {
    $identityFailure=$error->getMessage()==='virtual_reply_source_unavailable' && $error->getPrevious()===null;
}
sourceCheck('technical identity lookup failures are sanitized and never masquerade as missing sources',
    $identityFailure && !$identityDb->inTransaction());
$archiveIdentityFailure=false;
try {$identityReader->exposureIdentity('telegram_archive',1);} catch (RuntimeException $error) {
    $archiveIdentityFailure=$error->getMessage()==='virtual_reply_source_unavailable' && $error->getPrevious()===null;
}
sourceCheck('archive identity entity-query failures are sanitized rather than treated as a missing exposure',
    $archiveIdentityFailure && !$identityDb->inTransaction());

$archiveIdentityDb=sourceDb();$archiveIdentityReader=new VirtualReplySourceReader($archiveIdentityDb);
$archiveIdentityDb->exec('INSERT INTO conversations VALUES (1,"anytour",0,"telegram",11,71);
    INSERT INTO customer_channels VALUES (71,11,"anytour","telegram","91001","91001")');
$deletedArchive=sourceArchive($archiveIdentityDb,1,'customer','Deleted synthetic content',1792490400,['is_deleted'=>true]);
$managerArchive=sourceArchive($archiveIdentityDb,2,'manager','Manager synthetic content');
$startArchive=sourceArchive($archiveIdentityDb,3,'customer','/start synthetic');
$ownerArchive=sourceArchive($archiveIdentityDb,4,'customer','Internal synthetic content',1792490400,[],'anytour',100,91001);
$serviceArchive=sourceArchive($archiveIdentityDb,5,'customer','Service synthetic content',1792490400,[],'anytour',100,777000);
$otherProjectArchive=sourceArchive($archiveIdentityDb,6,'customer','Other project synthetic content',1792490400,[],'other');
$otherBotArchive=sourceArchive($archiveIdentityDb,7,'customer','Other bot synthetic content',1792490400,['bot_key'=>'unapproved']);
$desktopArchive=sourceArchive($archiveIdentityDb,8,'customer','Desktop synthetic content',1792490400,['source_kind'=>'desktop_import','bot_key'=>null]);
$withoutBotArchive=sourceArchive($archiveIdentityDb,9,'customer','No bot synthetic content',1792490400,['bot_key'=>null]);
sourceCheck('identity repair does not make deleted, nonquestion, internal or service archive content eligible',
    $archiveIdentityReader->snapshot('telegram_archive',$deletedArchive)===null
    && $archiveIdentityReader->snapshot('telegram_archive',$managerArchive)===null
    && $archiveIdentityReader->snapshot('telegram_archive',$startArchive)===null
    && $archiveIdentityReader->snapshot('telegram_archive',$ownerArchive)===null
    && $archiveIdentityReader->snapshot('telegram_archive',$serviceArchive)===null);
$archiveIdentityDb->setAttribute(PDO::ATTR_STATEMENT_CLASS,[SourceIdentityOnlyStatement::class]);
$archiveWrites=(int)$archiveIdentityDb->query('SELECT total_changes()')->fetchColumn();
$archiveKnown=VirtualReplySamplingIdentity::archive('200','');
sourceCheck('approved archive exposure identity reads scalar permission metadata and returns only stable hashes',
    $archiveIdentityReader->exposureIdentity('telegram_archive',$deletedArchive)===$archiveKnown
    && $archiveIdentityReader->exposureIdentity('telegram_archive',$managerArchive)===$archiveKnown
    && $archiveIdentityReader->exposureIdentity('telegram_archive',$startArchive)===$archiveKnown
    && $archiveIdentityReader->exposureIdentity('telegram_archive',$ownerArchive)===VirtualReplySamplingIdentity::archive('91001','')
    && $archiveIdentityReader->exposureIdentity('telegram_archive',$serviceArchive)===VirtualReplySamplingIdentity::archive('777000','')
    && (int)$archiveIdentityDb->query('SELECT total_changes()')->fetchColumn()===$archiveWrites && !$archiveIdentityDb->inTransaction());
sourceCheck('archive identity permission uses the exact row bot and cannot cross projects or synthesize missing rows',
    $archiveIdentityReader->exposureIdentity('telegram_archive',$otherBotArchive)===null
    && $archiveIdentityReader->exposureIdentity('telegram_archive',$otherProjectArchive)===null
    && $archiveIdentityReader->exposureIdentity('telegram_archive',99999)===null);
sourceCheck('desktop archive identity can use approved account history while business identity requires an exact bot',
    $archiveIdentityReader->exposureIdentity('telegram_archive',$desktopArchive)===$archiveKnown
    && $archiveIdentityReader->exposureIdentity('telegram_archive',$withoutBotArchive)===null);
foreach (['approved','provider_enabled'] as $flag) {
    $archiveIdentityDb->exec('UPDATE telegram_archive_accounts SET '.$flag.'=0');
    sourceCheck('archive exposure identity requires current account '.$flag,
        $archiveIdentityReader->exposureIdentity('telegram_archive',$deletedArchive)===null
        && $archiveIdentityReader->exposureIdentity('telegram_archive',$desktopArchive)===null);
    $archiveIdentityDb->exec('UPDATE telegram_archive_accounts SET '.$flag.'=1');
}
$archiveIdentityDb->exec('UPDATE telegram_dialogue_connections SET is_enabled=0');
sourceCheck('archive exposure identity requires the enabled approved current connection',
    $archiveIdentityReader->exposureIdentity('telegram_archive',$deletedArchive)===null);
$archiveIdentityDb->exec('UPDATE telegram_dialogue_connections SET is_enabled=1,account_id=999');
sourceCheck('archive exposure identity rejects a current connection bound to a different account',
    $archiveIdentityReader->exposureIdentity('telegram_archive',$deletedArchive)===null);
$archiveIdentityDb->exec('UPDATE telegram_dialogue_connections SET account_id=100');
$archiveIdentityDb->exec('UPDATE projects SET is_active=0 WHERE project_key="anytour"');
sourceCheck('inactive archive project cannot expose repair identity',
    $archiveIdentityReader->exposureIdentity('telegram_archive',$deletedArchive)===null);
$archiveIdentityDb->exec('UPDATE projects SET is_active=1 WHERE project_key="anytour"');
$invalidBotRecords=['{','null','[]','{"bot_key":null}','{"bot_key":12}','{"bot_key":true}',
    '{"bot_key":{}}','{"bot_key":[]}','{"bot_key":""}',json_encode(['bot_key'=>str_repeat('a',97)],JSON_THROW_ON_ERROR)];
$invalidResults=[];
foreach ($invalidBotRecords as $record) {
    $archiveIdentityDb->prepare('UPDATE telegram_dialogue_messages SET record_json=? WHERE id=?')->execute([$record,$deletedArchive]);
    $invalidResults[]=$archiveIdentityReader->exposureIdentity('telegram_archive',$deletedArchive);
}
sourceCheck('invalid JSON, nonobject records and invalid/nonstring/oversized required bot metadata fail closed',
    $invalidResults===array_fill(0,count($invalidBotRecords),null));
$duplicates=['{"bot_key":"archive","bot_key":"unapproved"}','{"bot_key":"unapproved","bot_key":"archive"}',
    '{"bot_key":"archive","nested":{"bot_key":"unapproved"}}',
    '{"bot_key":"archive","bot_\\u006bey":"unapproved"}',
    '{"bot_\\u006bey":"unapproved","bot_key":"archive"}'];
$duplicateResults=[];
foreach ($duplicates as $record) {
    $archiveIdentityDb->prepare('UPDATE telegram_dialogue_messages SET record_json=? WHERE id=?')->execute([$record,$deletedArchive]);
    $duplicateResults[]=$archiveIdentityReader->exposureIdentity('telegram_archive',$deletedArchive);
}
sourceCheck('duplicate bot keys in either order and escaped/nested aliases cannot widen archive permissions',
    $duplicateResults===array_fill(0,count($duplicates),null));
$archiveIdentityDb->prepare('UPDATE telegram_dialogue_messages SET record_json=? WHERE id=?')->execute(['{"bot_key":"archive","text":"unfetched"}',$deletedArchive]);
sourceCheck('exact approved bot permission survives unrelated private text fields without returning them',
    $archiveIdentityReader->exposureIdentity('telegram_archive',$deletedArchive)===$archiveKnown);
$archiveIdentityDb->exec('DELETE FROM telegram_dialogue_messages WHERE id='.$deletedArchive);
sourceCheck('hard-deleted archive exposure rows remain unresolved',
    $archiveIdentityReader->exposureIdentity('telegram_archive',$deletedArchive)===null);
$archiveIdentityDb->exec('DROP TABLE telegram_archive_accounts');$permissionFailure=false;
try {$archiveIdentityReader->exposureIdentity('telegram_archive',$managerArchive);} catch (RuntimeException $error) {
    $permissionFailure=$error->getMessage()==='virtual_reply_source_unavailable' && $error->getPrevious()===null;
}
sourceCheck('technical archive permission failures stay sanitized and close the fresh identity transaction',
    $permissionFailure && !$archiveIdentityDb->inTransaction());

require_once __DIR__.'/fixtures/virtual_reply_exposure.php';
$sharedExposure=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
virtualReplyExposureAssertions($sharedExposure,'sourceCheck');

echo "Virtual reply source regression: {$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
