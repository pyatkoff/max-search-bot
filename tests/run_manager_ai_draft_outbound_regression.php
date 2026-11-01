<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Execute byte-exact current outbound/recorder sources in an isolated app.
// Only their external dependencies are doubles: no config, real transport,
// provider or production DB is loaded. Feedback ownership/storage validation
// belongs to its own service suite; null below models its declined lookups.
final class DraftOutboundFixturePdo extends PDO
{
    public $afterCommit=null;
    public function commit(): bool {$ok=parent::commit();if($this->afterCommit){$hook=$this->afterCommit;$this->afterCommit=null;$hook($this);}return $ok;}
}

final class DraftOutboundFixture
{
    public static ?PDO $pdo = null;
    public static bool $configured = true;
    public static bool $visible = true;
    public static bool $delivered = true;
    public static bool $duplicate = false;
    public static bool $locked = true;
    public static string $channel = 'telegram';
    public static string $feedbackMode = 'available';
    public static string $proposal = '';
    public static string $recipient='synthetic-chat-42',$project='synthetic-project';
    public static int $assigned=7,$source=0;
    public static $afterAcquire=null;
    public static array $trace = [];
    public static int $connections = 0;

    public static function reset(string $channel = 'telegram'): PDO
    {
        self::$configured = self::$visible = self::$delivered = self::$locked = true;
        self::$duplicate = false;
        self::$channel = $channel;self::$recipient='synthetic-chat-42';self::$project='synthetic-project';self::$assigned=7;self::$source=0;self::$afterAcquire=null;
        self::$feedbackMode = 'available';
        self::$proposal = 'Вариант <b>море</b> & "номер"';
        self::$trace = [];
        self::$connections = 0;
        $pdo = new DraftOutboundFixturePdo('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        ]);
        $pdo->sqliteCreateFunction('NOW', static fn(): string=>'2026-10-19 12:00:00');
        $pdo->exec("CREATE TABLE conversations (id INTEGER PRIMARY KEY,last_message_at TEXT);
CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,direction TEXT,sender_type TEXT,sender_id TEXT,channel TEXT,external_message_id TEXT,text TEXT,metadata_json TEXT);
CREATE TABLE conversation_events (id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,created_at TEXT,payload_json TEXT);
CREATE TABLE manager_ai_drafts(draft_id TEXT PRIMARY KEY,conversation_id INTEGER,manager_id INTEGER,project_key TEXT);
CREATE TABLE manager_ai_sent_texts(message_id INTEGER PRIMARY KEY,draft_id TEXT,conversation_id INTEGER,manager_id INTEGER,project_key TEXT,text_value TEXT,text_sha256 TEXT);
INSERT INTO manager_ai_drafts VALUES('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',42,7,'synthetic-project');
INSERT INTO conversations VALUES (42,'2026-10-18 12:00:00');");
        self::$pdo = $pdo;
        return $pdo;
    }

    public static function events(string $name): array
    {
        return array_values(array_filter(self::$trace, static fn(array $item): bool=>$item['event'] === $name));
    }

    public static function rows(): array
    {
        return self::$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll();
    }
}

class ConversationDb
{
    public static function isConfigured(): bool { return DraftOutboundFixture::$configured; }
    public static function connection(): PDO
    {
        ++DraftOutboundFixture::$connections;
        return DraftOutboundFixture::$pdo;
    }
}
class ManagerConversationService
{
    public static function visibleConversation(int $conversationId, int $managerId): ?array
    {
        if (!DraftOutboundFixture::$visible || $conversationId !== 42 || $managerId !== 7) return null;
        return ['id'=>42,'manager_id'=>DraftOutboundFixture::$assigned,'status'=>'manager','project_key'=>DraftOutboundFixture::$project,
            'source_id'=>DraftOutboundFixture::$source,'channel'=>DraftOutboundFixture::$channel,'external_chat_id'=>DraftOutboundFixture::$recipient];
    }
}
class ManagerSendGuardService
{
    public static function acquire(int $conversationId, int $managerId): bool {if(DraftOutboundFixture::$afterAcquire){$hook=DraftOutboundFixture::$afterAcquire;DraftOutboundFixture::$afterAcquire=null;$hook();}return DraftOutboundFixture::$locked; }
    public static function isImmediateDuplicate(int $conversationId, string $text): bool { return DraftOutboundFixture::$duplicate; }
    public static function release(int $conversationId, int $managerId): void
    {
        DraftOutboundFixture::$trace[] = ['event'=>'release','args'=>[$conversationId,$managerId]];
    }
}
class ManagerAiDraftFeedbackService
{
    public static function forMessage(int $conversationId, int $managerId, string $projectKey, ?string $draftId, string $text): ?array
    {
        DraftOutboundFixture::$trace[] = ['event'=>'feedback','args'=>func_get_args()];
        if (DraftOutboundFixture::$feedbackMode !== 'available') return null;
        return ['draft_id'=>$draftId,
            'text_matches_proposal'=>trim($text) === trim(DraftOutboundFixture::$proposal),
            'sent_text_sha256'=>hash('sha256',trim($text))];
    }
}
class ConversationControlService
{
    public static function event(int $conversationId, string $eventType, string $actorType, int $actorId, array $payload): bool
    {
        DraftOutboundFixture::$trace[] = ['event'=>'control','type'=>$eventType,'payload'=>$payload];
        return true;
    }
}
class ManagerPushService
{
    public static function notifyConversation(int $conversationId, string $notice): void
    {
        DraftOutboundFixture::$trace[] = ['event'=>'push'];
    }
}
class MetrikaConversionGoalService
{
    public static function managerReply(int $conversationId): void
    {
        DraftOutboundFixture::$trace[] = ['event'=>'reply_goal'];
    }
}
class ManagerDeliveryStateService
{
    public static function maxRestartsAfter(array $events): array { return []; }
}
class DiagnosticLogger
{
    public static function log(...$args): void
    {
        DraftOutboundFixture::$trace[] = ['event'=>'recorder_failure','stage'=>$args[2]['stage'] ?? null];
    }
}
abstract class DraftOutboundAdapter
{
    public function __construct(...$args)
    {
        DraftOutboundFixture::$trace[] = ['event'=>'adapter_construct','class'=>static::class,'args'=>$args];
    }
    public function send($chatId, string $text): bool
    {
        DraftOutboundFixture::$trace[] = ['event'=>'adapter_send','args'=>func_get_args()];
        return DraftOutboundFixture::$delivered;
    }
    public function sendMedia($chatId, string $type, string $path, string $name, string $mime, string $caption, string $preview): bool
    {
        DraftOutboundFixture::$trace[] = ['event'=>'adapter_media','args'=>func_get_args()];
        return DraftOutboundFixture::$delivered;
    }
    public function lastExternalMessageId(): string { return 'synthetic-delivery-id'; }
}
class MaxMessengerAdapter extends DraftOutboundAdapter {}
class TelegramMessengerAdapter extends DraftOutboundAdapter {}
class WebsiteMessengerAdapter extends DraftOutboundAdapter {}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().'/manager-ai-outbound-'.bin2hex(random_bytes(6));
$passed = 0;
function draftOutboundCheck(string $name, bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    ++$passed;
    echo 'PASS '.$name."\n";
}

function draftOutboundMetadata(string $json): array
{
    $clock=ConversationRecordClock::read($json);
    draftOutboundCheck('canonical row carries a fresh server recording instant',is_int($clock)&&$clock>=time()-10&&$clock<=time());
    $metadata=json_decode($json,true);unset($metadata['_recorded_clock']);return $metadata;
}

try {
    foreach ([$tmp,$tmp.'/services',$tmp.'/integrations'] as $directory) mkdir($directory,0700);
    foreach (['ManagerConversationService','ManagerPushService','ManagerSendGuardService',
        'ManagerDeliveryStateService','ConversationDb','ManagerAiDraftFeedbackService',
        'MetrikaConversionGoalService','ProjectConfig','RoutingAccessService'] as $dependency) {
        file_put_contents($tmp.'/services/'.$dependency.'.php', "<?php\n// Dependency supplied by the isolated regression.\n");
    }
    foreach (['MaxMessengerAdapter','TelegramMessengerAdapter','WebsiteMessengerAdapter'] as $adapter) {
        file_put_contents($tmp.'/integrations/'.$adapter.'.php', "<?php\n// Synthetic adapter only.\n");
    }
    file_put_contents($tmp.'/config.php', "<?php\nthrow new LogicException('real_bootstrap_forbidden_in_outbound_fixture');\n");
    foreach (['ConversationRecordClock','ManagerOutboundService','ConversationRecorder','ManagerSendAttemptService','ManagerAiSentTextService'] as $service) {
        $source = $root.'/services/'.$service.'.php';
        $target = $tmp.'/services/'.$service.'.php';
        if (!copy($source,$target)) throw new RuntimeException('Cannot copy canonical service');
        draftOutboundCheck($service.' executes exact current canonical bytes',hash_file('sha256',$source) === hash_file('sha256',$target));
    }
    require $tmp.'/services/ManagerOutboundService.php';
    draftOutboundCheck('SQLite is required for real canonical recorder writes',in_array('sqlite',PDO::getAvailableDrivers(),true));

    $draftId = str_repeat('a',32);
    foreach (['telegram','max','website'] as $channel) {
        $pdo = DraftOutboundFixture::reset($channel);
        $raw = " \n".DraftOutboundFixture::$proposal."\t ";
        $trimmed = trim($raw);
        $escaped = htmlspecialchars($trimmed,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        draftOutboundCheck($channel.' delivered AI-associated text succeeds',ManagerOutboundService::send(42,7,$raw,$draftId));
        $feedback = DraftOutboundFixture::events('feedback');
        $rows = DraftOutboundFixture::rows();
        $meta = draftOutboundMetadata((string)($rows[0]['metadata_json'] ?? ''));
        $expectedFeedback = ['draft_id'=>$draftId,'text_matches_proposal'=>true,'sent_text_sha256'=>hash('sha256',$trimmed)];
        draftOutboundCheck($channel.' feedback gets authenticated scope and original trimmed text',
            count($feedback) === 1 && $feedback[0]['args'] === [42,7,'synthetic-project',$draftId,$trimmed]);
        draftOutboundCheck($channel.' adapter and canonical row receive the same escaped text',
            DraftOutboundFixture::events('adapter_send')[0]['args'] === ['synthetic-chat-42',$escaped]
            && count($rows) === 1 && $rows[0]['text'] === $escaped && $escaped !== $trimmed);
        draftOutboundCheck($channel.' minimal AI metadata is atomic with one canonical message row',
            $meta === ['project_key'=>'synthetic-project','ai_draft'=>$expectedFeedback]
            && (int)$rows[0]['id'] > 0 && (int)$rows[0]['id'] === (int)$pdo->lastInsertId()
            && (int)$rows[0]['conversation_id'] === 42 && $rows[0]['direction'] === 'outbound'
            && $rows[0]['sender_type'] === 'manager' && $rows[0]['sender_id'] === '7' && $rows[0]['channel'] === $channel);
        draftOutboundCheck($channel.' keeps original text hash distinct from escaped transport hash',
            $meta['ai_draft']['sent_text_sha256'] !== hash('sha256',$escaped)
            && $rows[0]['external_message_id'] === ($channel === 'website' ? null : 'synthetic-delivery-id'));
        $receipt=$pdo->query('SELECT * FROM manager_ai_sent_texts')->fetch();
        draftOutboundCheck($channel.' initial plaintext is privately bound to the exact canonical message',
            $receipt['message_id']===$rows[0]['id']&&$receipt['draft_id']===$draftId&&$receipt['conversation_id']===42
            &&$receipt['manager_id']===7&&$receipt['project_key']==='synthetic-project'
            &&$receipt['text_value']===$trimmed&&$receipt['text_sha256']===$expectedFeedback['sent_text_sha256']);
        $names = array_column(DraftOutboundFixture::$trace,'event');
        $constructed = DraftOutboundFixture::events('adapter_construct')[0]['args'];
        draftOutboundCheck($channel.' feedback follows successful adapter delivery and guard releases',
            array_search('adapter_send',$names,true) < array_search('feedback',$names,true)
            && count(DraftOutboundFixture::events('release')) === 1);
        draftOutboundCheck($channel.' AI association is not attached to lifecycle event payloads',
            DraftOutboundFixture::events('control') === [['event'=>'control','type'=>'manager_message',
                'payload'=>['channel'=>$channel,'project_key'=>'synthetic-project']]]);
        draftOutboundCheck($channel.' adapter remains explicitly non-mirroring',
            $constructed[count($constructed)-1] === false);
    }

    DraftOutboundFixture::reset();
    $edited = 'Другой текст <проверка> & дата';
    draftOutboundCheck('edited draft text still delivers',ManagerOutboundService::send(42,7,$edited,$draftId));
    $meta = draftOutboundMetadata(DraftOutboundFixture::rows()[0]['metadata_json']);
    draftOutboundCheck('edited text retains draft identity but hashes the sent original text',
        $meta['ai_draft'] === ['draft_id'=>$draftId,'text_matches_proposal'=>false,'sent_text_sha256'=>hash('sha256',$edited)]);

    $receipt=DraftOutboundFixture::$pdo->query('SELECT * FROM manager_ai_sent_texts')->fetch();
    DraftOutboundFixture::$pdo->exec("UPDATE messages SET text='Later provider edit' WHERE id=1");
    draftOutboundCheck('later message edits preserve the changed manager initial text',
        DraftOutboundFixture::$pdo->query('SELECT * FROM manager_ai_sent_texts')->fetch()===$receipt&&$receipt['text_value']===$edited);
    draftOutboundCheck('duplicate receipt cannot overwrite or relink initial text',
        !ManagerAiSentTextService::record(DraftOutboundFixture::$pdo,1,42,'manager','7',htmlspecialchars($edited,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),$meta,$edited)
        &&DraftOutboundFixture::$pdo->query('SELECT * FROM manager_ai_sent_texts')->fetch()===$receipt);
    foreach(['missing_store','receipt_write_failure','touch_failure'] as $failure){
        $pdo=DraftOutboundFixture::reset();
        if($failure==='missing_store')$pdo->exec('DROP TABLE manager_ai_sent_texts');
        elseif($failure==='receipt_write_failure')$pdo->exec("CREATE TRIGGER reject_receipt BEFORE INSERT ON manager_ai_sent_texts BEGIN SELECT RAISE(FAIL,'synthetic_capture_failure'); END");
        else $pdo->exec("CREATE TRIGGER reject_touch BEFORE UPDATE ON conversations BEGIN SELECT RAISE(FAIL,'synthetic_touch_failure'); END");
        draftOutboundCheck($failure.' cannot change successful transport or repeat message insertion',
            ManagerOutboundService::send(42,7,$edited,$draftId)&&count(DraftOutboundFixture::rows())===1
            &&count(DraftOutboundFixture::events('adapter_send'))===1);
        if($failure==='touch_failure')draftOutboundCheck('initial receipt survives a later recorder touch failure',
            $pdo->query('SELECT text_value FROM manager_ai_sent_texts')->fetchColumn()===$edited);
    }
    $pdo=DraftOutboundFixture::reset();$unicode=str_repeat('🧳',19980)."\nA &amp; <b> literal";
    draftOutboundCheck('near-limit Unicode and literal entities retain exact original plaintext',ManagerOutboundService::send(42,7,$unicode,$draftId)
        &&$pdo->query('SELECT text_value FROM manager_ai_sent_texts')->fetchColumn()===$unicode);
    $originalRow=DraftOutboundFixture::rows()[0];$originalMeta=json_decode($originalRow['metadata_json'],true);$before=$pdo->query('SELECT * FROM manager_ai_sent_texts')->fetchAll();
    foreach([[999,'manager','7'],[42,'ai','7'],[42,'manager','8']] as [$cid,$kind,$actor]){
        draftOutboundCheck('wrong message scope cannot attach or replace initial text '.implode(':',[$cid,$kind,$actor]),
            !ManagerAiSentTextService::record($pdo,1,$cid,$kind,$actor,$originalRow['text'],$originalMeta,$unicode)
            &&$pdo->query('SELECT * FROM manager_ai_sent_texts')->fetchAll()===$before);
    }
    $pdo=DraftOutboundFixture::reset();$tooLong=str_repeat('🧳',20001);
    draftOutboundCheck('oversized optional evidence does not clip text or change delivery',ManagerOutboundService::send(42,7,$tooLong,$draftId)
        &&count(DraftOutboundFixture::rows())===1&&(int)$pdo->query('SELECT COUNT(*) FROM manager_ai_sent_texts')->fetchColumn()===0);
    DraftOutboundFixture::reset();
    draftOutboundCheck('legacy three-argument text send still works',ManagerOutboundService::send(42,7,'Обычный ответ'));
    draftOutboundCheck('null draft ID performs no feedback lookup and stores no association',
        DraftOutboundFixture::events('feedback') === []
        && draftOutboundMetadata(DraftOutboundFixture::rows()[0]['metadata_json']) === ['project_key'=>'synthetic-project']);
    foreach (['missing_draft','invalid_draft','foreign_scope','unavailable_store'] as $mode) {
        DraftOutboundFixture::reset();
        DraftOutboundFixture::$feedbackMode = $mode;
        $id = $mode === 'invalid_draft' ? 'not-a-valid-draft-id' : $draftId;
        draftOutboundCheck($mode.' feedback cannot block delivered customer text',ManagerOutboundService::send(42,7,'Ответ без связи',$id));
        draftOutboundCheck($mode.' leaves the canonical row without AI metadata',
            count(DraftOutboundFixture::rows()) === 1
            && draftOutboundMetadata(DraftOutboundFixture::rows()[0]['metadata_json']) === ['project_key'=>'synthetic-project']);
    }

    DraftOutboundFixture::reset();
    draftOutboundCheck('initial send creates one associated row',ManagerOutboundService::send(42,7,'Повторяемый ответ',$draftId));
    DraftOutboundFixture::$trace = [];
    DraftOutboundFixture::$connections = 0;
    DraftOutboundFixture::$duplicate = true;
    draftOutboundCheck('suppressed duplicate remains legacy success',ManagerOutboundService::send(42,7,'Повторяемый ответ',$draftId));
    draftOutboundCheck('duplicate does not query feedback, redeliver, or call the real recorder',
        DraftOutboundFixture::events('feedback') === [] && DraftOutboundFixture::events('adapter_send') === []
        && DraftOutboundFixture::$connections === 0 && count(DraftOutboundFixture::rows()) === 1
        && (int)DraftOutboundFixture::$pdo->query('SELECT COUNT(*) FROM manager_ai_sent_texts')->fetchColumn()===1
        && array_column(DraftOutboundFixture::events('control'),'type') === ['manager_message_suppressed_duplicate']);

    DraftOutboundFixture::reset();
    DraftOutboundFixture::$delivered = false;
    draftOutboundCheck('failed delivery remains false',ManagerOutboundService::send(42,7,'Не доставлено',$draftId) === false);
    draftOutboundCheck('failed delivery cannot query feedback or create associated recorder row',
        DraftOutboundFixture::events('feedback') === [] && DraftOutboundFixture::rows() === []
        && (int)DraftOutboundFixture::$pdo->query('SELECT COUNT(*) FROM manager_ai_sent_texts')->fetchColumn()===0
        && DraftOutboundFixture::$connections === 0 && count(DraftOutboundFixture::events('push')) === 1
        && count(DraftOutboundFixture::events('release')) === 1 && ManagerOutboundService::lastFailure() !== null);

    foreach (['unconfigured','insert_failure'] as $failure) {
        $pdo = DraftOutboundFixture::reset();
        if ($failure === 'unconfigured') DraftOutboundFixture::$configured = false;
        else $pdo->exec("CREATE TRIGGER reject_message BEFORE INSERT ON messages BEGIN SELECT RAISE(FAIL,'synthetic_mirror_failure'); END;");
        draftOutboundCheck($failure.' recorder cannot undo successful transport delivery',ManagerOutboundService::send(42,7,'Доставлено, но не записано',$draftId));
        draftOutboundCheck($failure.' creates no canonical row or durable AI association',
            count(DraftOutboundFixture::events('adapter_send')) === 1 && DraftOutboundFixture::rows() === []);
        if ($failure === 'insert_failure') {
            draftOutboundCheck('actual recorder handles failed INSERT through its best-effort failure path',
                array_column(DraftOutboundFixture::events('recorder_failure'),'stage') === ['outbound_for_conversation']);
        }
    }

    foreach (['telegram','max'] as $channel) {
        DraftOutboundFixture::reset($channel);
        $file = $tmp.'/synthetic-media.txt';
        file_put_contents($file,'synthetic attachment bytes');
        draftOutboundCheck($channel.' media send keeps its existing behavior',
            ManagerOutboundService::sendMedia(42,7,$file,'synthetic.txt','text/plain',' <caption> '));
        $rows = DraftOutboundFixture::rows();
        $meta = draftOutboundMetadata($rows[0]['metadata_json']);
        draftOutboundCheck($channel.' media does not query or attach draft feedback',
            DraftOutboundFixture::events('feedback') === [] && count($rows) === 1
            && $meta === ['project_key'=>'synthetic-project','attachments'=>[['type'=>'file','name'=>'synthetic.txt','mime_type'=>'text/plain']]]
            && $rows[0]['text'] === '&lt;caption&gt;');
    }
    $pdo=DraftOutboundFixture::reset();
    $pdo->exec('CREATE TABLE managers(id INTEGER PRIMARY KEY,is_active INTEGER); INSERT INTO managers VALUES(7,1); CREATE TABLE manager_send_attempts(manager_id INTEGER,operation_id TEXT,conversation_id INTEGER,context_hash TEXT,request_hash TEXT,state TEXT,result_json TEXT,created_at TEXT,updated_at TEXT,PRIMARY KEY(manager_id,operation_id))');
    $attemptId=str_repeat('b',32);$original=DraftOutboundFixture::$proposal;
    $sent=ManagerOutboundService::sendAttempt(42,7,$attemptId,$original,$draftId);
    $repeated=ManagerOutboundService::sendAttempt(42,7,$attemptId,$original,$draftId);
    $readback=ManagerSendAttemptService::receipt(7,42,$attemptId);
    $rows=DraftOutboundFixture::rows();$meta=draftOutboundMetadata((string)($rows[0]['metadata_json']??''));
    draftOutboundCheck('receipt wrapper sends and records exactly once through canonical owners',($sent['attempt']['state']??'')==='confirmed'&&$repeated===$sent&&$readback===['ok'=>true,'attempt'=>['state'=>'confirmed']]&&count(DraftOutboundFixture::events('adapter_send'))===1&&count($rows)===1);
    draftOutboundCheck('receipt wrapper retains actual owner and canonical AI provenance',($rows[0]['sender_id']??'')==='7'&&($meta['ai_draft']['draft_id']??'')===$draftId&&($meta['ai_draft']['sent_text_sha256']??'')===hash('sha256',$original)&&count(DraftOutboundFixture::events('feedback'))===1);
    foreach(['recipient','project','source','channel','assignment','active','visibility'] as $change){
        $pdo=DraftOutboundFixture::reset();
        $pdo->exec('CREATE TABLE managers(id INTEGER PRIMARY KEY,is_active INTEGER); INSERT INTO managers VALUES(7,1); CREATE TABLE manager_send_attempts(manager_id INTEGER,operation_id TEXT,conversation_id INTEGER,context_hash TEXT,request_hash TEXT,state TEXT,result_json TEXT,created_at TEXT,updated_at TEXT,PRIMARY KEY(manager_id,operation_id))');
        $pdo->afterCommit=static function(PDO $db)use($change): void {
            if($change==='recipient')DraftOutboundFixture::$recipient='changed-recipient';
            if($change==='project')DraftOutboundFixture::$project='changed-project';
            if($change==='source')DraftOutboundFixture::$source=99;
            if($change==='channel')DraftOutboundFixture::$channel='max';
            if($change==='assignment')DraftOutboundFixture::$assigned=8;
            if($change==='active')$db->exec('UPDATE managers SET is_active=0 WHERE id=7');
            if($change==='visibility')DraftOutboundFixture::$visible=false;
        };
        $result=ManagerOutboundService::sendAttempt(42,7,str_repeat('c',32),'Synthetic scoped attempt',$draftId);
        draftOutboundCheck('post-reservation '.$change.' change cannot reach adapter or canonical message',($result['attempt']['state']??'')==='rejected'&&count(DraftOutboundFixture::events('adapter_send'))===0&&DraftOutboundFixture::rows()===[]);
        $freshReceipt=ManagerSendAttemptService::receipt(7,42,str_repeat('c',32));
        draftOutboundCheck('post-reservation '.$change.' immediate readback respects fresh owner/access',in_array($change,['assignment','active','visibility'],true)?($freshReceipt['error']??'')==='forbidden':($freshReceipt['attempt']['state']??'')==='rejected');
        DraftOutboundFixture::$recipient='synthetic-chat-42';DraftOutboundFixture::$project='synthetic-project';DraftOutboundFixture::$source=0;DraftOutboundFixture::$channel='telegram';DraftOutboundFixture::$assigned=7;DraftOutboundFixture::$visible=true;$pdo->exec('UPDATE managers SET is_active=1 WHERE id=7');
        draftOutboundCheck('post-reservation '.$change.' rejection remains reconcilable without replay',(ManagerSendAttemptService::receipt(7,42,str_repeat('c',32))['attempt']['state']??'')==='rejected'&&(ManagerOutboundService::sendAttempt(42,7,str_repeat('c',32),'Synthetic scoped attempt',$draftId)['attempt']['state']??'')==='rejected'&&count(DraftOutboundFixture::events('adapter_send'))===0);
    }
    foreach(['recipient','assignment','active'] as $change){
        $pdo=DraftOutboundFixture::reset();$pdo->exec('CREATE TABLE managers(id INTEGER PRIMARY KEY,is_active INTEGER); INSERT INTO managers VALUES(7,1); CREATE TABLE manager_send_attempts(manager_id INTEGER,operation_id TEXT,conversation_id INTEGER,context_hash TEXT,request_hash TEXT,state TEXT,result_json TEXT,created_at TEXT,updated_at TEXT,PRIMARY KEY(manager_id,operation_id))');
        DraftOutboundFixture::$afterAcquire=static function()use($change,$pdo): void {if($change==='recipient')DraftOutboundFixture::$recipient='changed-after-lock';if($change==='assignment')DraftOutboundFixture::$assigned=8;if($change==='active')$pdo->exec('UPDATE managers SET is_active=0 WHERE id=7');};
        $result=ManagerOutboundService::sendAttempt(42,7,str_repeat('d',32),'Synthetic lock boundary',$draftId);
        draftOutboundCheck('after-advisory-lock '.$change.' is checked on the actual dispatch row',($result['attempt']['state']??'')==='rejected'&&count(DraftOutboundFixture::events('adapter_send'))===0&&DraftOutboundFixture::rows()===[]);
    }
    $pdo=DraftOutboundFixture::reset('max');$pdo->exec('CREATE TABLE managers(id INTEGER PRIMARY KEY,is_active INTEGER); INSERT INTO managers VALUES(7,1); CREATE TABLE manager_send_attempts(manager_id INTEGER,operation_id TEXT,conversation_id INTEGER,context_hash TEXT,request_hash TEXT,state TEXT,result_json TEXT,created_at TEXT,updated_at TEXT,PRIMARY KEY(manager_id,operation_id)); ALTER TABLE messages ADD COLUMN created_at TEXT');
    $pdo->prepare('INSERT INTO conversation_events(id,conversation_id,event_type,created_at,payload_json) VALUES(1,42,?,?,?)')->execute(['manager_message_failed','2026-10-19 10:00:00',json_encode(['category'=>'suspended'])]);
    $pdo->afterCommit=static function(){DraftOutboundFixture::$recipient='changed-suspended-recipient';};
    $result=ManagerOutboundService::sendAttempt(42,7,str_repeat('e',32),'Synthetic suspended boundary',$draftId);
    draftOutboundCheck('changed MAX context is rejected before a different recipient suspension can mask its receipt',($result['failure']['category']??'')==='attempt_context_changed'&&(ManagerSendAttemptService::receipt(7,42,str_repeat('e',32))['attempt']['state']??'')==='rejected'&&count(DraftOutboundFixture::events('adapter_send'))===0);
    $result=ManagerOutboundService::sendAttempt(42,7,str_repeat('f',32),'Synthetic unchanged suspended context',$draftId);
    draftOutboundCheck('unchanged scoped MAX suspension still rejects before transport',($result['attempt']['state']??'')==='rejected'&&($result['failure']['category']??'')==='suspended'&&count(DraftOutboundFixture::events('adapter_send'))===0&&DraftOutboundFixture::rows()===[]);
    DraftOutboundFixture::reset();
    draftOutboundCheck('empty text is rejected before feedback or delivery',ManagerOutboundService::send(42,7," \n ",$draftId) === false
        && DraftOutboundFixture::events('feedback') === [] && DraftOutboundFixture::events('adapter_send') === []);
    DraftOutboundFixture::$visible = false;
    draftOutboundCheck('inaccessible conversation is rejected before feedback or delivery',ManagerOutboundService::send(42,7,'Ответ',$draftId) === false
        && DraftOutboundFixture::events('feedback') === [] && DraftOutboundFixture::events('adapter_send') === []);
} finally {
    DraftOutboundFixture::$pdo = null;
    $remove = static function(string $path) use (&$remove): void {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path.'/'.$entry);
            rmdir($path);
        }
    };
    $remove($tmp);
}
echo "TOTAL {$passed} passed\n";
