<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerAiDraftFeedbackReport.php';

$passed=0;
function reportCheck(string $name,bool $value): void
{
    global $passed;if(!$value)throw new RuntimeException('FAIL '.$name);++$passed;echo 'PASS '.$name."\n";
}
function reportDb(): PDO
{
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE conversations(id INTEGER PRIMARY KEY,project_key TEXT COLLATE NOCASE,is_test INTEGER,channel TEXT,manager_id INTEGER);
CREATE TABLE manager_ai_drafts(draft_id TEXT PRIMARY KEY,conversation_id INTEGER,manager_id INTEGER,project_key TEXT COLLATE NOCASE,context_revision TEXT,instruction_revision TEXT,latest_message_id INTEGER,proposal_json BLOB,created_at TEXT,insert_requested_at TEXT);
CREATE INDEX draft_created ON manager_ai_drafts(created_at);
CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,direction TEXT,sender_type TEXT,sender_id TEXT,channel TEXT,text TEXT,metadata_json TEXT,created_at TEXT);
CREATE INDEX message_created ON messages(created_at);
INSERT INTO conversations VALUES(1,'anytour',0,'telegram',99),(2,'other',0,'telegram',7),(3,'anytour',1,'telegram',7),(4,'ANYTOUR',0,'telegram',7);");
    return $pdo;
}
function reportProposal(array $changes=[]): array
{
    return array_replace(['ok'=>true,'should_draft'=>true,'draft'=>' A < B & "C" &lt;literal ',
        'next_action'=>'clarify','reason'=>'Synthetic reason','checks'=>[],
        'evidence_message_ids'=>[12],'history_incomplete'=>false],$changes);
}
function reportId(int $id): string {return str_pad(dechex($id),32,'0',STR_PAD_LEFT);}
function reportDraft(PDO $pdo,int $id,array $changes=[]): void
{
    $v=array_replace(['draft_id'=>reportId($id),'conversation_id'=>1,'manager_id'=>7,'project_key'=>'anytour',
        'context_revision'=>str_repeat('a',64),'instruction_revision'=>str_repeat('b',64),'latest_message_id'=>12,
        'proposal_json'=>json_encode(reportProposal(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'created_at'=>'2026-10-17 10:00:00','insert_requested_at'=>'2026-10-17 10:01:00'],$changes);
    $q=$pdo->prepare('INSERT INTO manager_ai_drafts('.implode(',',array_keys($v)).') VALUES('.implode(',',array_fill(0,count($v),'?')).')');$q->execute(array_values($v));
}
function reportRef(int $id,string $text,bool $same=true): array
{
    return ['project_key'=>'anytour','ai_draft'=>['draft_id'=>reportId($id),'text_matches_proposal'=>$same,'sent_text_sha256'=>hash('sha256',trim($text))]];
}
function reportMessage(PDO $pdo,int $id,array $changes=[]): void
{
    $plain=trim(reportProposal()['draft']);
    $v=array_replace(['id'=>$id,'conversation_id'=>1,'direction'=>'outbound','sender_type'=>'manager','sender_id'=>'7','channel'=>'telegram',
        'text'=>htmlspecialchars($plain,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),'metadata_json'=>json_encode(reportRef(1,$plain)),
        'created_at'=>'2026-10-17 10:02:00'],$changes);
    $q=$pdo->prepare('INSERT INTO messages('.implode(',',array_keys($v)).') VALUES('.implode(',',array_fill(0,count($v),'?')).')');$q->execute(array_values($v));
}
function reportCollect(PDO $pdo): array {return ManagerAiDraftFeedbackReport::collect($pdo,'anytour',new DateTimeImmutable('2026-10-19 10:00:00+02:00'));}

$pdo=reportDb();$result=reportCollect($pdo);$s=$result['summary'];
reportCheck('empty migrated store is a complete zero observation',$s['ok']&&$s['complete']&&$result['samples']===[]&&array_sum($s['counts'])===0);
reportCheck('window is precisely seven rolling UTC days with explicit exclusive end',$s['generated_at']==='2026-10-19T08:00:00Z'&&$s['window']===['since'=>'2026-10-12 08:00:00','until'=>'2026-10-19 08:00:00','timezone'=>'UTC','days'=>7,'basis'=>'persisted_generation_cohort']);
reportCheck('observation imports no AI config or authorization bootstrap',!class_exists('ProjectConfig',false)&&!class_exists('ManagerAiDraftFeedbackService',false)&&!class_exists('ProjectAccessService',false));
$s['private']='must not escape';$s['window']['customer']='must not escape';$s['counts']['customer_text']='must not escape';$s['limits']['secret']='must not escape';
$public=ManagerAiDraftFeedbackReport::publicSummary($s);
reportCheck('public summary recursively drops arbitrary fields',!str_contains(json_encode($public),'must not escape')&&!isset($public['private'],$public['counts']['customer_text'],$public['limits']['secret']));
$failed=ManagerAiDraftFeedbackReport::collect($pdo,'',new DateTimeImmutable('2026-10-19T08:00:00Z'));
reportCheck('invalid project yields fixed error and unknown counts',$failed['summary']['error']==='invalid_project'&&!$failed['summary']['ok']&&$failed['samples']===[]&&count(array_filter($failed['summary']['counts'],fn($v)=>$v!==null))===0);
$pdo->beginTransaction();$failed=reportCollect($pdo);
reportCheck('borrowed transaction is refused and not consumed',$failed['summary']['error']==='borrowed_transaction'&&$pdo->inTransaction());$pdo->rollBack();

$plain=trim(reportProposal()['draft']);
reportDraft($pdo,1);
reportDraft($pdo,2,['proposal_json'=>json_encode(reportProposal(['should_draft'=>false,'draft'=>null,'next_action'=>'no_contact'])),'insert_requested_at'=>null]);
reportDraft($pdo,3,['proposal_json'=>'{broken']);
reportDraft($pdo,4,['insert_requested_at'=>null]);
reportDraft($pdo,5,['insert_requested_at'=>'2026-10-16 10:00:00']);
reportDraft($pdo,6,['conversation_id'=>2,'project_key'=>'other']);
reportDraft($pdo,7,['conversation_id'=>3]);
reportDraft($pdo,8,['conversation_id'=>4,'project_key'=>'ANYTOUR']);
reportMessage($pdo,1);
reportMessage($pdo,2,['created_at'=>'2026-10-17 10:03:00','text'=>'Edited after sending','metadata_json'=>json_encode(array_replace(reportRef(1,$plain),['edited_at'=>'2026-10-17 10:05:00']))]);
reportMessage($pdo,3,['created_at'=>'2026-10-17 10:04:00','text'=>'Added real detail','metadata_json'=>json_encode(reportRef(1,'Added real detail',false))]);
reportMessage($pdo,4,['metadata_json'=>null]);
reportMessage($pdo,5,['metadata_json'=>json_encode(reportRef(999,$plain))]);
reportMessage($pdo,6,['sender_id'=>'8']);
$bad=reportRef(1,$plain);$bad['ai_draft']['text_matches_proposal']='true';reportMessage($pdo,7,['metadata_json'=>json_encode($bad)]);
reportMessage($pdo,8,['metadata_json'=>'{broken']);
reportMessage($pdo,9,['metadata_json'=>json_encode(['attachments'=>[['type'=>'image']]])]);
$bad=reportRef(1,$plain);$bad['project_key']='other';reportMessage($pdo,10,['metadata_json'=>json_encode($bad)]);
reportMessage($pdo,11,['metadata_json'=>json_encode(reportRef(2,$plain))]);
reportMessage($pdo,12,['created_at'=>'2026-10-18 10:00:01']);
reportMessage($pdo,13,['metadata_json'=>json_encode(reportRef(1,'Different initial plaintext',true))]);
$bad=reportRef(1,$plain);$bad['attachments']=[['type'=>'image']];reportMessage($pdo,14,['metadata_json'=>json_encode($bad)]);
reportMessage($pdo,15,['conversation_id'=>2]);reportMessage($pdo,16,['conversation_id'=>3]);reportMessage($pdo,17,['conversation_id'=>4]);
reportMessage($pdo,18,['direction'=>'inbound']);reportMessage($pdo,19,['sender_type'=>'ai']);
$changes=(int)$pdo->query('SELECT total_changes()')->fetchColumn();$pdo->exec('PRAGMA query_only=ON');
$result=reportCollect($pdo);$s=$result['summary'];$c=$s['counts'];
reportCheck('historical collection works without current owner active manager or authorization tables',$s['ok']&&$s['complete']);
reportCheck('persisted generated cohort distinguishes reply no-reply and corrupt proposals',$c['persisted_proposals']===5&&$c['reply_proposals']===3&&$c['no_reply_proposals']===1&&$c['invalid_proposals']===1);
reportCheck('only valid unique insertion observations count',$c['insert_requested_drafts']===1&&$c['invalid_insert_request_drafts']===1);
reportCheck('reused draft has three canonical messages but one linked draft',$c['linked_messages']===3&&$c['linked_drafts']===1&&$c['recorded_same_messages']===2&&$c['recorded_changed_messages']===1);
reportCheck('media malformed metadata tests foreign projects and nonmanager directions are separated',$c['manager_message_rows']===14&&$c['manager_text_messages']===11&&$c['unclassifiable_message_rows']===1&&$c['media_messages_excluded']===2);
reportCheck('unlinked unresolved and provably invalid references remain distinct',$c['unlinked_manager_text_messages']===8&&$c['unresolved_reference_messages']===1&&$c['invalid_reference_messages']===6);
reportCheck('read-only SQLite fence is respected and transaction is closed',(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$changes&&!$pdo->inTransaction());
reportCheck('samples are latest verified linked rows without raw metadata or manager IDs',array_column($result['samples'],'message_id')===[3,2,1]&&!isset($result['samples'][0]['metadata_json'],$result['samples'][0]['manager_id']));
reportCheck('postsend edit does not overwrite initial recorded comparison',$c['edited_messages']===1&&$result['samples'][1]['edited']&&$result['samples'][1]['recorded_text_matches_proposal']&&!$result['samples'][1]['initial_text_available']&&$result['samples'][1]['current_sent_text']==='Edited after sending');
reportCheck('HTML escaping is decoded once preserving literal entities and original hash',$result['samples'][2]['current_sent_text']===$plain&&$result['samples'][2]['initial_text_available']&&$result['samples'][2]['proposed_text']===$plain);
reportCheck('edited current text still retains proposal for private review',$result['samples'][1]['proposed_text']===$plain);

$pdo=reportDb();
reportDraft($pdo,1,['created_at'=>'2026-10-12 08:00:00','insert_requested_at'=>'2026-10-12 08:00:00']);
reportDraft($pdo,2,['created_at'=>'2026-10-12 07:59:59','insert_requested_at'=>'2026-10-12 07:59:59']);
reportDraft($pdo,3,['created_at'=>'2026-10-19 08:00:00','insert_requested_at'=>'2026-10-19 08:00:00']);
reportMessage($pdo,1,['created_at'=>'2026-10-12 08:00:00']);
reportMessage($pdo,2,['created_at'=>'2026-10-12 08:00:01','metadata_json'=>json_encode(reportRef(2,$plain))]);
reportMessage($pdo,3,['created_at'=>'2026-10-19 08:00:00']);
reportMessage($pdo,4,['created_at'=>'2026-10-12 07:59:59']);
$s=reportCollect($pdo)['summary'];
reportCheck('inclusive lower and exclusive upper cohort boundaries are exact',$s['counts']['persisted_proposals']===1&&$s['counts']['manager_message_rows']===2&&$s['counts']['linked_messages']===1);
reportCheck('reference to earlier generated cohort is unresolved not invalid',$s['counts']['unresolved_reference_messages']===1&&$s['counts']['invalid_reference_messages']===0);

$pdo=reportDb();$unicode=str_repeat('я',3000);
reportDraft($pdo,1,['proposal_json'=>json_encode(reportProposal(['draft'=>$unicode]),JSON_UNESCAPED_UNICODE)]);
for($i=1;$i<=15;++$i)reportMessage($pdo,$i,['text'=>$unicode,'metadata_json'=>json_encode(reportRef(1,$unicode))]);
$r=reportCollect($pdo);$sample=$r['samples'][0];
reportCheck('private sample cardinality is bounded without changing aggregate counts',count($r['samples'])===10&&$r['summary']['counts']['linked_messages']===15&&array_column($r['samples'],'message_id')===[15,14,13,12,11,10,9,8,7,6]);
reportCheck('Unicode sample truncation counts characters and is explicit',preg_match_all('/./us',$sample['proposed_text'])===2000&&preg_match_all('/./us',$sample['current_sent_text'])===2000&&$sample['proposed_text_truncated']&&$sample['current_sent_text_truncated']&&$sample['initial_text_available']);
$pdo->exec("UPDATE messages SET text='".str_repeat('x',120001)."' WHERE id=15");
$r=reportCollect($pdo);$sample=$r['samples'][0];
reportCheck('oversized current stored text cannot masquerade as initial plaintext',!$sample['initial_text_available']&&$sample['current_sent_text_truncated']&&strlen($sample['current_sent_text'])===2000);

$pdo=reportDb();for($i=1;$i<=1001;++$i)reportDraft($pdo,$i);
reportMessage($pdo,1);
$r=reportCollect($pdo);$s=$r['summary'];
reportCheck('draft row cap detects one extra without counting it',$s['ok']&&!$s['complete']&&$s['draft_limit_reached']&&!$s['message_limit_reached']&&$s['counts']['persisted_proposals']===1000);
reportCheck('reference omitted by draft bound remains unresolved',$s['counts']['unresolved_reference_messages']===1&&$s['counts']['invalid_reference_messages']===0);
$pdo=reportDb();reportDraft($pdo,1);for($i=1;$i<=5001;++$i)reportMessage($pdo,$i,['metadata_json'=>null]);
$s=reportCollect($pdo)['summary'];
reportCheck('message row cap is explicit and counts bounded rows',$s['ok']&&!$s['complete']&&!$s['draft_limit_reached']&&$s['message_limit_reached']&&$s['counts']['manager_message_rows']===5000);

$pdo=reportDb();reportDraft($pdo,1);reportMessage($pdo,1,['metadata_json'=>str_repeat(' ',8192).'{"ai_draft":{}}']);
$r=reportCollect($pdo);
reportCheck('projected metadata overflow is unknown media coverage not an ordinary unlinked text',$r['summary']['ok']&&$r['summary']['counts']['unclassifiable_message_rows']===1&&$r['summary']['counts']['manager_text_messages']===0&&$r['samples']===[]);
$pdo->exec('DROP TABLE manager_ai_drafts');$r=reportCollect($pdo);
reportCheck('missing migration is fixed unavailable with no raw exception or data',$r['summary']['error']==='storage_unavailable'&&!$r['summary']['ok']&&!$r['summary']['complete']&&count(array_filter($r['summary']['counts'],fn($v)=>$v!==null))===0&&$r['samples']===[]&&!str_contains(json_encode($r),'no such table')&&!$pdo->inTransaction());
reportCheck('missing table is never recreated by report',(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='manager_ai_drafts'")->fetchColumn()===0);

echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
