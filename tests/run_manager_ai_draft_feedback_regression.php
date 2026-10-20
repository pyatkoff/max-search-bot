<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerAiDraftFeedbackService.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
(new ReflectionProperty(ConversationDb::class, 'pdo'))->setValue(null, $pdo);
foreach ([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) {
    (new ReflectionProperty($class, 'schemaReady'))->setValue(null, false);
}
ProjectConfig::resetForTests(['id'=>'feedback-fixture','brand'=>['name'=>'Synthetic workspace']]);
$pdo->exec("CREATE TABLE manager_ai_drafts (
 draft_id TEXT PRIMARY KEY,conversation_id INTEGER NOT NULL,manager_id INTEGER NOT NULL,project_key TEXT NOT NULL,
 context_revision TEXT NOT NULL,instruction_revision TEXT NOT NULL,latest_message_id INTEGER NOT NULL,
 proposal_json BLOB NOT NULL CHECK(length(CAST(proposal_json AS BLOB))<=32768),created_at TEXT NOT NULL,insert_requested_at TEXT,preparation_job_id TEXT);
CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT);
CREATE TABLE manager_customer_names(customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER);
CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,external_chat_id TEXT);
CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,display_name TEXT,is_active INTEGER,primary_group_id INTEGER,fallback_mode TEXT,fallback_group_id INTEGER,fallback_after_minutes INTEGER);
CREATE TABLE manager_group_members(manager_id INTEGER,group_id INTEGER);
CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,text TEXT);
CREATE TABLE manager_conversation_reads(manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER);
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT);
INSERT INTO projects VALUES(1,'feedback-fixture','Synthetic workspace',1),(2,'other','Other workspace',1);
INSERT INTO managers VALUES(7,'owner','Synthetic owner','manager','',1,1),(8,'colleague','Synthetic colleague','manager','',1,1),(9,'outside','Synthetic outside','manager','',1,1),(10,'inactive','Synthetic inactive','manager','',0,1),(90,'admin','Synthetic admin','admin','',1,1);
INSERT INTO manager_projects VALUES(7,1),(8,1),(10,1),(90,1),(9,2);
INSERT INTO customers VALUES(1,'Synthetic customer','','');
INSERT INTO conversation_sources VALUES(1,'Allowed',1,0,'none',0,0),(2,'Restricted',1,101,'none',0,0);
INSERT INTO conversations(id,customer_id,project_key,source_id,channel,status,manager_id,started_at) VALUES
 (1,1,'feedback-fixture',1,'telegram','manager',7,'2026-10-19 00:00:00'),
 (2,1,'feedback-fixture',1,'telegram','manager',8,'2026-10-19 00:00:00'),
 (3,1,'other',1,'telegram','manager',7,'2026-10-19 00:00:00'),
 (4,1,'feedback-fixture',2,'telegram','manager',7,'2026-10-19 00:00:00'),
 (5,1,'feedback-fixture',1,'telegram','waiting_manager',7,'2026-10-19 00:00:00');
INSERT INTO messages VALUES(11,1,'Synthetic source message'),(12,1,'Synthetic latest message');
INSERT INTO manager_conversation_reads VALUES(7,1,10);
INSERT INTO conversation_events VALUES(1,1,'synthetic_original');");

$passed = 0;
function feedbackCheck(string $name, bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    ++$passed;
    echo 'PASS '.$name."\n";
}
function feedbackSnapshot(int $cid = 1): array
{
    return ['ok'=>true,'revision'=>str_repeat('a',64),'latest_message_id'=>12,
        'context'=>['conversation_id'=>$cid,'as_of_message_id'=>12,'history_incomplete'=>false,
            'messages'=>[['id'=>11,'text'=>'Source text must not be copied'],['id'=>12,'text'=>'Latest source text must not be copied']]]];
}
function feedbackProposal(array $changes = []): array
{
    return array_replace(['ok'=>true,'should_draft'=>true,'draft'=>' A < B & C ',
        'next_action'=>'clarify','reason'=>'Synthetic useful clarification','checks'=>['Check a synthetic condition'],
        'evidence_message_ids'=>[12],'history_incomplete'=>false], $changes);
}
function feedbackRow(string $id): array
{
    global $pdo;
    $q=$pdo->prepare('SELECT * FROM manager_ai_drafts WHERE draft_id=?');$q->execute([$id]);
    return $q->fetch() ?: [];
}
function feedbackCount(): int {global $pdo;return (int)$pdo->query('SELECT COUNT(*) FROM manager_ai_drafts')->fetchColumn();}
function feedbackBusiness(): array
{
    global $pdo;$out=[];
    foreach (['managers','projects','manager_projects','conversations','messages','manager_conversation_reads','conversation_events'] as $table) {
        $out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();
    }
    return $out;
}

$business = feedbackBusiness();
$snapshot = feedbackSnapshot();$proposal = feedbackProposal();
$id = ManagerAiDraftFeedbackService::record(1,7,$snapshot,$proposal);
feedbackCheck('owned visible generation returns a random32hex provenance ID',is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/D',$id)===1);
$row = feedbackRow($id);
feedbackCheck('server scope and immutable generation revision are stored',
    (int)$row['conversation_id']===1&&(int)$row['manager_id']===7&&$row['project_key']==='feedback-fixture'
    &&$row['context_revision']===$snapshot['revision']&&(int)$row['latest_message_id']===12
    &&$row['instruction_revision']===hash('sha256',ManagerAiDraftService::instructions())&&$row['insert_requested_at']===null);
feedbackCheck('only validated result fields are copied and source transcript is absent',
    json_decode($row['proposal_json'],true,16,JSON_THROW_ON_ERROR)===$proposal
    &&strlen($row['proposal_json'])<=32768&&!str_contains($row['proposal_json'],'Source text must not be copied'));
feedbackCheck('generated alone is not an insertion request or sent association',ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$id,'A < B & C')===null);
$second = ManagerAiDraftFeedbackService::record(1,7,$snapshot,$proposal);
feedbackCheck('two generations have distinct IDs without replacing the first',is_string($second)&&$second!==$id&&feedbackCount()===2&&feedbackRow($id)===$row);

$beforeCount=feedbackCount();
$job=str_repeat('c',64);
$preparedId=ManagerAiDraftFeedbackService::record(1,7,$snapshot,$proposal,$job);
feedbackCheck('prepared preview records its exact server job and proposal',is_string($preparedId)&&feedbackRow($preparedId)['preparation_job_id']===$job);
$preparedRow=feedbackRow($preparedId);$preparedCount=feedbackCount();
feedbackCheck('repeated prepared preview reuses provenance without changing its timestamps',ManagerAiDraftFeedbackService::record(1,7,$snapshot,$proposal,$job)===$preparedId&&feedbackCount()===$preparedCount&&feedbackRow($preparedId)===$preparedRow);
feedbackCheck('same prepared identity cannot replace its original text',ManagerAiDraftFeedbackService::record(1,7,$snapshot,feedbackProposal(['draft'=>'Changed source']),$job)===null&&feedbackRow($preparedId)===$preparedRow);
feedbackCheck('prepared preview alone is not use but explicit insertion links changed text',ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$preparedId,'Human changed')===null&&ManagerAiDraftFeedbackService::markInsertRequested(1,7,$preparedId,$snapshot['revision'],12)===$preparedId&&ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$preparedId,'Human changed')['text_matches_proposal']===false);
$beforeCount=feedbackCount();
foreach ([[0,7],[1,0],[1,8],[1,90],[1,10],[3,7],[4,7],[5,7],[999,7]] as [$cid,$actor]) {
    feedbackCheck('missing inactive foreign colleague admin and nonreply scopes fail closed',
        ManagerAiDraftFeedbackService::record($cid,$actor,feedbackSnapshot($cid),$proposal)===null);
}
feedbackCheck('denied scopes allocate no provenance',feedbackCount()===$beforeCount);
$badSnapshots = [];
$bad=$snapshot;$bad['revision']='bad';$badSnapshots[]=$bad;
$bad=$snapshot;$bad['latest_message_id']='12';$badSnapshots[]=$bad;
$bad=$snapshot;$bad['context']['conversation_id']=2;$badSnapshots[]=$bad;
$bad=$snapshot;$bad['context']['as_of_message_id']=11;$badSnapshots[]=$bad;
$bad=$snapshot;$bad['context']['messages']=[['id'=>11]];$badSnapshots[]=$bad;
$bad=$snapshot;$bad['context']['messages']=[['id'=>12],['id'=>12]];$badSnapshots[]=$bad;
foreach ($badSnapshots as $bad) feedbackCheck('malformed snapshot cannot create a misleading receipt',ManagerAiDraftFeedbackService::record(1,7,$bad,$proposal)===null);
foreach ([['ok'=>false],['draft'=>null],['reason'=>"\xff"],['draft'=>str_repeat('x',3001)],['reason'=>str_repeat('x',1001)],
    ['checks'=>array_fill(0,11,'x')],['checks'=>['']],['evidence_message_ids'=>[13]],['evidence_message_ids'=>[12,12]],
    ['history_incomplete'=>true],['next_action'=>'no_contact'],['context'=>['private'=>'must not copy']]] as $change) {
    feedbackCheck('malformed extra or ungrounded proposal fields are not persisted',ManagerAiDraftFeedbackService::record(1,7,$snapshot,feedbackProposal($change))===null);
}
$large=feedbackProposal(['draft'=>str_repeat("\u{2028}",3000),'reason'=>str_repeat("\u{2028}",1000),'checks'=>array_fill(0,10,str_repeat("\u{2028}",400))]);
feedbackCheck('encoded byte cap rejects otherwise individually bounded escaped Unicode output',
    strlen(json_encode($large,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>32768
    &&ManagerAiDraftFeedbackService::record(1,7,$snapshot,$large)===null&&feedbackCount()===$beforeCount);
$spoofed=$snapshot;$spoofed['project_key']='other';
$serverId=ManagerAiDraftFeedbackService::record(1,7,$spoofed,$proposal);
feedbackCheck('snapshot cannot choose the stored project',is_string($serverId)&&feedbackRow($serverId)['project_key']==='feedback-fixture');

feedbackCheck('marker rejects wrong actor conversation revision latest and malformed ID',
    ManagerAiDraftFeedbackService::markInsertRequested(1,8,$id,$snapshot['revision'],12)===null
    &&ManagerAiDraftFeedbackService::markInsertRequested(2,7,$id,$snapshot['revision'],12)===null
    &&ManagerAiDraftFeedbackService::markInsertRequested(1,7,$id,str_repeat('b',64),12)===null
    &&ManagerAiDraftFeedbackService::markInsertRequested(1,7,$id,$snapshot['revision'],11)===null
    &&ManagerAiDraftFeedbackService::markInsertRequested(1,7,'invalid',$snapshot['revision'],12)===null);
feedbackCheck('matching explicit insertion request returns the same ID',ManagerAiDraftFeedbackService::markInsertRequested(1,7,$id,$snapshot['revision'],12)===$id);
$marked=feedbackRow($id);$unchanged=$marked;$unchanged['insert_requested_at']=null;
feedbackCheck('insertion observation does not mutate generated content or generation revision',$unchanged===$row&&is_string($marked['insert_requested_at']));
$pdo->prepare('UPDATE manager_ai_drafts SET created_at=?,insert_requested_at=? WHERE draft_id=?')->execute([gmdate('Y-m-d H:i:s',time()-10),gmdate('Y-m-d H:i:s',time()-5),$id]);
$marked=feedbackRow($id);
feedbackCheck('request replay is idempotent and preserves the first observation timestamp',
    ManagerAiDraftFeedbackService::markInsertRequested(1,7,$id,$snapshot['revision'],12)===$id&&feedbackRow($id)===$marked);
$metadata=ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$id,"  A < B & C\n");
feedbackCheck('raw transport-trimmed text yields only minimal comparison metadata',
    $metadata===['draft_id'=>$id,'text_matches_proposal'=>true,'sent_text_sha256'=>hash('sha256','A < B & C')]);
$changed=ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$id,"Human prefix\nA < B & C");
feedbackCheck('manual prefix differs without claiming edit quality or acceptance',
    $changed===['draft_id'=>$id,'text_matches_proposal'=>false,'sent_text_sha256'=>hash('sha256',"Human prefix\nA < B & C")]);
feedbackCheck('comparison uses plain text not transport HTML escaping',ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$id,'A &lt; B &amp; C')['text_matches_proposal']===false);
feedbackCheck('metadata lookup is read-only and is not a sent or inserted flag',feedbackRow($id)===$marked);
feedbackCheck('foreign or missing identifiers and project cannot acquire attribution',
    ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',null,'x')===null
    &&ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',str_repeat('f',32),'x')===null
    &&ManagerAiDraftFeedbackService::forMessage(1,7,'other',$id,'x')===null
    &&ManagerAiDraftFeedbackService::forMessage(1,8,'feedback-fixture',$id,'x')===null
    &&ManagerAiDraftFeedbackService::forMessage(2,8,'feedback-fixture',$id,'x')===null
    &&ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$id,"\xff")===null);
$noDraft=ManagerAiDraftFeedbackService::record(1,7,$snapshot,feedbackProposal(['should_draft'=>false,'draft'=>null,'next_action'=>'check_offer']));
feedbackCheck('valid nondraft recommendation is recorded but cannot be requested for insertion',
    is_string($noDraft)&&ManagerAiDraftFeedbackService::markInsertRequested(1,7,$noDraft,$snapshot['revision'],12)===null);
$pdo->prepare('UPDATE manager_ai_drafts SET insert_requested_at=? WHERE draft_id=?')->execute([gmdate('Y-m-d H:i:s'),$noDraft]);
feedbackCheck('even a corrupt insertion marker cannot attribute a nondraft recommendation',ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$noDraft,'x')===null);

foreach ([gmdate('Y-m-d H:i:s',time()-86401),gmdate('Y-m-d H:i:s',time()+60),'invalid date'] as $created) {
    $pdo->prepare('UPDATE manager_ai_drafts SET created_at=? WHERE draft_id=?')->execute([$created,$id]);
    feedbackCheck('expired future or malformed generation time cannot be attributed or marked',
        ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$id,'x')===null
        &&ManagerAiDraftFeedbackService::markInsertRequested(1,7,$id,$snapshot['revision'],12)===null);
}
$pdo->prepare('UPDATE manager_ai_drafts SET created_at=? WHERE draft_id=?')->execute([$marked['created_at'],$id]);
foreach ([gmdate('Y-m-d H:i:s',time()+60),'invalid marker',gmdate('Y-m-d H:i:s',time()-60)] as $requested) {
    $pdo->prepare('UPDATE manager_ai_drafts SET insert_requested_at=? WHERE draft_id=?')->execute([$requested,$id]);
    feedbackCheck('future malformed or pre-generation request does not become insertion evidence',ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$id,'x')===null);
}
$pdo->prepare('UPDATE manager_ai_drafts SET insert_requested_at=? WHERE draft_id=?')->execute([$marked['insert_requested_at'],$id]);
$pdo->prepare('UPDATE manager_ai_drafts SET project_key=? WHERE draft_id=?')->execute(['FEEDBACK-fixture',$id]);
feedbackCheck('stored project namespace must match exactly without SQL case folding',
    ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$id,'x')===null
    &&ManagerAiDraftFeedbackService::markInsertRequested(1,7,$id,$snapshot['revision'],12)===null);
$pdo->prepare('UPDATE manager_ai_drafts SET project_key=? WHERE draft_id=?')->execute(['feedback-fixture',$id]);
$pdo->exec('DELETE FROM manager_projects WHERE manager_id=7');
feedbackCheck('current project revocation blocks stored attribution and marker',
    ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$id,'x')===null
    &&ManagerAiDraftFeedbackService::markInsertRequested(1,7,$id,$snapshot['revision'],12)===null);
$pdo->exec('INSERT INTO manager_projects VALUES(7,1)');
$pdo->exec('UPDATE managers SET is_active=0 WHERE id=7');
feedbackCheck('inactive actor cannot reuse persisted provenance',ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$id,'x')===null);
$pdo->exec('UPDATE managers SET is_active=1 WHERE id=7');
$pdo->exec('UPDATE conversations SET manager_id=8 WHERE id=1');
feedbackCheck('reassignment blocks both former and new actor from reusing old provenance',
    ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$id,'x')===null
    &&ManagerAiDraftFeedbackService::forMessage(1,8,'feedback-fixture',$id,'x')===null);
$pdo->exec('UPDATE conversations SET manager_id=7 WHERE id=1');

$beforeCount=feedbackCount();$pdo->beginTransaction();
feedbackCheck('borrowed transaction is untouched and feedback stays unavailable',
    ManagerAiDraftFeedbackService::record(1,7,$snapshot,$proposal)===null
    &&ManagerAiDraftFeedbackService::markInsertRequested(1,7,$id,$snapshot['revision'],12)===null
    &&ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$id,'x')===null
    &&$pdo->inTransaction()&&feedbackCount()===$beforeCount);
$pdo->rollBack();
$pdo->exec("CREATE TRIGGER reject_feedback_insert BEFORE INSERT ON manager_ai_drafts BEGIN SELECT RAISE(FAIL,'synthetic write failure'); END");
feedbackCheck('insert storage failure returns null with no misleading success or open transaction',
    ManagerAiDraftFeedbackService::record(1,7,$snapshot,$proposal)===null&&feedbackCount()===$beforeCount&&!$pdo->inTransaction());
$pdo->exec('DROP TRIGGER reject_feedback_insert');
$pdo->exec("CREATE TRIGGER reject_feedback_marker BEFORE UPDATE ON manager_ai_drafts BEGIN SELECT RAISE(FAIL,'synthetic marker failure'); END");
feedbackCheck('marker storage failure remains unknown and does not prevent ordinary workflow',
    ManagerAiDraftFeedbackService::markInsertRequested(1,7,$second,$snapshot['revision'],12)===null&&feedbackRow($second)['insert_requested_at']===null&&!$pdo->inTransaction());
$pdo->exec('DROP TRIGGER reject_feedback_marker');
$pdo->prepare('UPDATE manager_ai_drafts SET proposal_json=? WHERE draft_id=?')->execute(['{broken',$id]);
feedbackCheck('corrupt stored proposal fails open as unavailable attribution',ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$id,'x')===null);
feedbackCheck('feedback workflow preserves actual transcript ownership access markers events and shifts',feedbackBusiness()===$business);
$pdo->exec('DROP TABLE manager_ai_drafts');
feedbackCheck('missing migration returns null for all operations without runtime DDL',
    ManagerAiDraftFeedbackService::record(1,7,$snapshot,$proposal)===null
    &&ManagerAiDraftFeedbackService::markInsertRequested(1,7,$second,$snapshot['revision'],12)===null
    &&ManagerAiDraftFeedbackService::forMessage(1,7,'feedback-fixture',$second,'x')===null
    &&(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='manager_ai_drafts'")->fetchColumn()===0
    &&!$pdo->inTransaction());

echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
