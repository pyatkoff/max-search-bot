<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&!defined('MANAGER_AI_USAGE_HTTP_FIXTURE')){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/services/ManagerAiUsageService.php';
require_once dirname(__DIR__,2).'/services/ManagerAiDraftService.php';

final class ManagerAiUsageFixture
{
    public static function create(?string $sqlitePath=null): PDO
    {
$dsn=$sqlitePath===null?getenv('MANAGER_AI_USAGE_MYSQL_DSN'):false;$native=is_string($dsn)&&$dsn!=='';
if($native&&!preg_match('/\Amysql:host=(127\.0\.0\.1|localhost|mysql);(?:port=[0-9]{1,5};)?dbname=manager_ai_usage_test;charset=utf8mb4\z/D',$dsn))throw new RuntimeException('isolated_native_fixture_required');
$pdo=new PDO($native?$dsn:'sqlite:'.($sqlitePath??':memory:'),$native?getenv('MANAGER_AI_USAGE_MYSQL_USER'):null,$native?getenv('MANAGER_AI_USAGE_MYSQL_PASSWORD'):null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($native){if($pdo->query('SHOW TABLES')->fetchAll())throw new RuntimeException('empty_native_fixture_required');$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);$pdo->exec("SET time_zone='+00:00'");}
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'AnyTour']]);
$idType=$native?'BIGINT UNSIGNED':'INTEGER';$projectType=$native?'VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci':'TEXT COLLATE NOCASE';
$pdo->exec("CREATE TABLE projects(id {$idType} PRIMARY KEY,project_key VARCHAR(64),display_name VARCHAR(100),is_active INTEGER);
CREATE TABLE managers(id {$idType} PRIMARY KEY,role VARCHAR(20),is_active INTEGER,login VARCHAR(100),display_name VARCHAR(100),email VARCHAR(100),is_working INTEGER DEFAULT 0);
CREATE TABLE manager_projects(manager_id {$idType},project_id {$idType});
CREATE TABLE conversation_sources(id {$idType},project_id {$idType},display_name VARCHAR(100),channel VARCHAR(20),is_active INTEGER);
CREATE TABLE customers(id {$idType} PRIMARY KEY,display_name VARCHAR(100));
CREATE TABLE manager_customer_names(customer_id {$idType} PRIMARY KEY,working_name VARCHAR(100));
CREATE TABLE conversations(id {$idType} PRIMARY KEY,customer_id {$idType},project_key {$projectType},source_id {$idType},channel VARCHAR(20),manager_id {$idType},is_test INTEGER);
CREATE TABLE messages(id {$idType} PRIMARY KEY,conversation_id {$idType},direction VARCHAR(20),sender_type VARCHAR(20),sender_id VARCHAR(50),channel VARCHAR(20),text TEXT,metadata_json TEXT,created_at DATETIME);
CREATE INDEX usage_messages ON messages(conversation_id,id);
CREATE TABLE manager_ai_drafts(draft_id CHAR(32) PRIMARY KEY,conversation_id {$idType},manager_id {$idType},project_key {$projectType},context_revision CHAR(64),instruction_revision CHAR(64),latest_message_id {$idType},proposal_json TEXT,created_at DATETIME,insert_requested_at DATETIME,preparation_job_id CHAR(64));
CREATE TABLE manager_ai_draft_inputs(draft_id CHAR(32) PRIMARY KEY,input_json MEDIUMTEXT NOT NULL,input_sha256 CHAR(64) NOT NULL)");
$pdo->exec("CREATE TABLE manager_ai_sent_texts(message_id {$idType} PRIMARY KEY,draft_id CHAR(32),conversation_id {$idType},manager_id {$idType},project_key VARCHAR(64),text_value MEDIUMTEXT,text_sha256 CHAR(64))");
if($native)$pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/061_manager_ai_usage_reviews.sql'));
else$pdo->exec('CREATE TABLE manager_ai_usage_reviews(manager_id INTEGER,message_id INTEGER,case_revision TEXT,reviewed_at TEXT,PRIMARY KEY(manager_id,message_id))');
$pdo->exec("INSERT INTO projects VALUES(1,'anytour','AnyTour',1),(2,'other','Other',1);
INSERT INTO managers (id,role,is_active,login,display_name) VALUES(1,'admin',1,'owner','Owner'),(2,'admin',1,'other-owner','Other owner'),(3,'manager',1,'employee','Employee'),(4,'admin',0,'inactive','Inactive'),(7,'manager',1,'author','Historical author'),(9,'manager',1,'new-owner','Current assignee');
INSERT INTO manager_projects VALUES(1,1),(2,2),(3,1),(4,1);
INSERT INTO conversation_sources VALUES(11,1,'Allowed','telegram',1),(21,2,'Other','telegram',1);
INSERT INTO customers VALUES(10,'Synthetic tourist');INSERT INTO manager_customer_names VALUES(10,'Working name');");
$pdo->beginTransaction();for($i=1;$i<=47;$i++)self::seed($pdo,$i);
self::seed($pdo,51,['project'=>'other']);self::seed($pdo,52,['test'=>1]);self::seed($pdo,53,['cid'=>1]);self::seed($pdo,54,['project'=>'ANYTOUR']);
for($i=55;$i<=60;$i++)self::seed($pdo,$i);
$pdo->exec("UPDATE messages SET sender_id='9' WHERE id=10055;
UPDATE manager_ai_drafts SET project_key='other' WHERE conversation_id=156;
UPDATE manager_ai_drafts SET insert_requested_at=NULL WHERE conversation_id=157;
UPDATE manager_ai_drafts SET proposal_json='{broken' WHERE conversation_id=158;
UPDATE messages SET metadata_json='{}' WHERE id=10059;
UPDATE messages SET metadata_json='{".'"project_key":"anytour","attachments":[{"type":"image"}],"ai_draft":{"draft_id":"'.str_pad(dechex(60),32,'0',STR_PAD_LEFT).'","text_matches_proposal":false,"sent_text_sha256":"'.hash('sha256','Manager detail 60').'"}}'."' WHERE id=10060");$pdo->commit();
self::captureInput($pdo,47);
return $pdo;
    }
public static function captureInput(PDO $pdo,int $n): ManagerAiDraftInputEvidence
{
    $context=['conversation_id'=>100+$n,'as_of_message_id'=>1000+$n,'history_incomplete'=>false,
        'media_unread'=>false,'selections_link_unread'=>false,'verified_company_facts'=>[],
        'messages'=>[['id'=>1000+$n,'sender_type'=>'customer','text'=>'Question '.$n]]];
    $evidence=ManagerAiDraftInputEvidence::capture($context,'Synthetic context-specific instructions');
    if($evidence===null)throw new RuntimeException('fixture_input_invalid');
    $pdo->prepare('INSERT INTO manager_ai_draft_inputs (draft_id,input_json,input_sha256) VALUES (?,?,?)')
        ->execute([str_pad(dechex($n),32,'0',STR_PAD_LEFT),$evidence->json(),$evidence->hash()]);
    return $evidence;
}
private static function seed(PDO $p,int $n,array $changes=[]): void
{
    $cid=$changes['cid']??100+$n;$project=$changes['project']??'anytour';$message=10000+$n;$question=1000+$n;$draft=str_pad(dechex($n),32,'0',STR_PAD_LEFT);
    $q=$p->prepare('INSERT INTO conversations VALUES(?,?,?,?,?,?,?)');$q->execute([$cid,10,$project,$project==='other'?21:11,'telegram',9,$changes['test']??0]);
    $proposal=['ok'=>true,'should_draft'=>true,'draft'=>'AI proposal '.$n,'next_action'=>'answer_question','reason'=>'Synthetic reason','checks'=>[],'evidence_message_ids'=>[$question],'history_incomplete'=>false];
    $sent=$n%2?'AI proposal '.$n:'Manager detail '.$n;
    $metadata=['project_key'=>$project,'ai_draft'=>['draft_id'=>$draft,'text_matches_proposal'=>$n%2===1,'sent_text_sha256'=>hash('sha256',$sent)]];
    $q=$p->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?,?)');$q->execute([$question,$cid,'inbound','customer','', 'telegram','Question '.$n,null,'2026-10-25 09:59:00']);
    $q->execute([$message,$cid,'outbound','manager','7','telegram',htmlspecialchars($sent,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),json_encode($metadata),'2026-10-25 10:02:00']);
    $instruction=$n%2?hash('sha256',ManagerAiDraftService::instructions()):str_repeat('b',64);
    $q=$p->prepare('INSERT INTO manager_ai_drafts VALUES(?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$draft,$cid,7,$project,str_repeat('a',64),$instruction,$question,json_encode($proposal),'2026-10-25 10:00:00','2026-10-25 10:01:00',$n%3===0?str_repeat('c',64):null]);
}

}
