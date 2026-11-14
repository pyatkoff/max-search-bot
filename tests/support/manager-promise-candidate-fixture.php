<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/manager-promise-task-fixture.php';
require_once __DIR__.'/prepared-input-fixture.php';
require_once dirname(__DIR__,2).'/services/ManagerAiDraftService.php';
require_once dirname(__DIR__,2).'/services/ManagerPreparedReplyService.php';
require_once dirname(__DIR__,2).'/services/ManagerAiDraftFeedbackService.php';
require_once dirname(__DIR__,2).'/services/ManagerAiDraftFeedbackReport.php';

final class PromiseCandidateStatement extends PDOStatement
{
    protected function __construct(private PromiseCandidatePdo $owner) {}
    public function execute(?array $params=null): bool
    {
        $this->owner->observe($this->queryString);return parent::execute($params);
    }
}

/** Real SQLite with a fatal write barrier for the authorized capture, not a SQL mock. */
final class PromiseCandidatePdo extends PDO
{
    public bool $readOnly=false;
    public int $writeAttempts=0;
    public array $executed=[];
    public function __construct(string $path)
    {
        parent::__construct('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PromiseCandidateStatement::class,[$this]]);
        parent::exec('PRAGMA foreign_keys=ON');
    }
    public function observe(string $sql): void
    {
        $this->executed[]=$sql;
        if($this->readOnly&&!preg_match('/\A\s*SELECT\b/i',$sql)){
            ++$this->writeAttempts;throw new RuntimeException('promise_candidate_capture_must_not_write');
        }
    }
    public function exec(string $statement): int|false
    {$this->observe($statement);return parent::exec($statement);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->observe($query);return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);
    }
}

function promiseCandidateFixture(): PromiseCandidatePdo
{
    $path=sys_get_temp_dir().'/manager-promise-candidate-'.bin2hex(random_bytes(8)).'.sqlite';
    register_shutdown_function(static function()use($path):void{if(is_file($path))unlink($path);});
    $seed=promiseTaskFixture($path);
    $seed->exec("ALTER TABLE conversations ADD COLUMN is_test INTEGER NOT NULL DEFAULT 0;
CREATE TABLE manager_preparation_cursors(project_key TEXT,lane TEXT,after_id INTEGER NOT NULL,version INTEGER NOT NULL,PRIMARY KEY(project_key,lane));
CREATE TABLE manager_prepared_replies(job_id TEXT PRIMARY KEY,conversation_id INTEGER NOT NULL,manager_id INTEGER NOT NULL,project_key TEXT NOT NULL,context_revision TEXT NOT NULL,instruction_revision TEXT NOT NULL,latest_message_id INTEGER NOT NULL,status TEXT NOT NULL,proposal_json TEXT,proposal_sha256 TEXT,created_at TEXT NOT NULL,prepared_at TEXT,expires_at TEXT,customer_input_revision TEXT,purpose TEXT NOT NULL DEFAULT 'background',requested_by_manager_id INTEGER,viewer_access_revision TEXT);
CREATE TABLE manager_ai_drafts(draft_id TEXT PRIMARY KEY,conversation_id INTEGER NOT NULL,manager_id INTEGER NOT NULL,project_key TEXT NOT NULL,context_revision TEXT NOT NULL,instruction_revision TEXT NOT NULL,latest_message_id INTEGER NOT NULL,proposal_json BLOB NOT NULL,created_at TEXT NOT NULL,insert_requested_at TEXT,preparation_job_id TEXT);
CREATE TABLE manager_ai_draft_inputs(draft_id TEXT PRIMARY KEY,input_json TEXT NOT NULL,input_sha256 TEXT NOT NULL);");
    preparedInputFixtureSchema($seed);
    $pdo=new PromiseCandidatePdo($path);
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    return $pdo;
}

function promiseCandidateSnapshot(PromiseCandidatePdo $pdo,int $actor=1): array
{
    $before=$pdo->readOnly;$pdo->readOnly=true;
    try{return ManagerAiDraftContextService::viewerSnapshot(1,$actor);}
    finally{$pdo->readOnly=$before;}
}

function promiseCandidateProposal(?array $candidates=null): array
{
    $proposal=['ok'=>true,'should_draft'=>false,'draft'=>null,'next_action'=>'no_contact',
        'reason'=>'Менеджер обещал расчёт, исходящее сообщение сейчас не нужно.',
        'checks'=>['Подготовить обещанный расчёт.'],'evidence_message_ids'=>[12],'history_incomplete'=>false];
    if($candidates!==null)$proposal['manager_promises']=$candidates;
    return $proposal;
}

function promiseCandidateItem(array $changes=[]): array
{
    return array_replace(['kind'=>'send_calculation','source_message_id'=>12,
        'quote'=>'Пришлю расчёт на двоих на согласованные даты.','deadline_text'=>null],$changes);
}

function promiseCandidateGenerate(array $snapshot,array $proposal,?array &$observed=null,?ManagerAiDraftInputEvidence &$evidence=null): array
{
    return ManagerAiDraftService::generate($snapshot['context'],static function(string $instructions,array $context)use($proposal,&$observed):array{
        $observed=['instructions'=>$instructions,'context'=>$context];return $proposal;
    },$snapshot['company_knowledge'],null,$snapshot['reference_context'],null,
        static function(ManagerAiDraftInputEvidence $input)use(&$evidence):void{$evidence=$input;});
}

function promiseCandidateGroup(array $snapshot): ?array
{
    return $snapshot['reference_context']->forContext($snapshot['context'])['promise_sources']??null;
}

function promiseCandidateSource(array $snapshot,int $id=12): ?array
{
    foreach(promiseCandidateGroup($snapshot)['sources']??[] as $source)if($source['source_message_id']===$id)return $source;
    return null;
}

function promiseCandidateMessage(PDO $pdo,int $id,string $text,?string $recordedAt='2026-11-13T20:59:00Z'): void
{
    $metadata=$recordedAt===null?null:json_encode(ConversationRecordClock::stamp([],strtotime($recordedAt)),JSON_THROW_ON_ERROR);
    $pdo->prepare('UPDATE messages SET text=?,metadata_json=? WHERE id=?')->execute([$text,$metadata,$id]);
}

function promiseCandidateJob(PromiseCandidatePdo $pdo,array $snapshot): string
{
    $queued=ManagerPreparedReplyService::requestOnOpen(1,1,$snapshot);
    if(($queued['ok']??null)!==true||empty($queued['requested']))throw new RuntimeException('candidate_fixture_open_queue_failed: '.json_encode($queued));
    $job=$pdo->query("SELECT job_id FROM manager_prepared_replies WHERE purpose='open_chat' ORDER BY rowid DESC LIMIT 1")->fetchColumn();
    if(!is_string($job))throw new RuntimeException('candidate_fixture_missing_job');
    return $job;
}

function promiseCandidateIssueInput(string $job,bool $omitPromiseSources=false): string
{
    $packet=ManagerPreparedReplyService::context($job,static function():never{throw new LogicException('unexpected_candidate_fixture_external_read');});
    if(($packet['ok']??null)!==true)throw new RuntimeException('candidate_fixture_context_failed: '.json_encode($packet));
    $images=$packet['image_inputs'];unset($packet['image_inputs']);
    if($omitPromiseSources){
        unset($packet['context']['promise_sources']);
        $packet['instructions']=ManagerAiDraftService::instructions($packet['context']);
    }
    $issued=ManagerPreparedReplyService::issueInput($packet,$images,2,str_repeat('c',32),static function():void{
        $manager=ManagerAuthService::byId(2);
        if(!$manager||!ManagerAuthService::isAdmin($manager))throw new RuntimeException('candidate_fixture_admin_not_authorized');
    });
    if(($issued['ok']??null)!==true||empty($issued['capture']['captured']))throw new RuntimeException('candidate_fixture_input_not_issued: '.json_encode($issued));
    return $issued['capture']['receipt_id'];
}

function promiseCandidatePrepared(PromiseCandidatePdo $pdo,array $snapshot,array $proposal): array
{
    $job=promiseCandidateJob($pdo,$snapshot);
    $receipt=empty($proposal['manager_promises'])?null:promiseCandidateIssueInput($job);
    $completed=ManagerPreparedReplyService::complete($job,$snapshot['revision'],$proposal,$receipt,2,str_repeat('c',32));
    if(($completed['ok']??null)!==true)throw new RuntimeException('candidate_fixture_completion_failed: '.json_encode($completed));
    return ManagerPreparedReplyService::prepared(1,1,$snapshot);
}
