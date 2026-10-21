<?php

declare(strict_types=1);

require_once __DIR__.'/virtual_reply_sampling.php';
require_once dirname(__DIR__,2).'/services/VirtualReplyControlStore.php';

/** Actual forward SQL; only SQLite dialect syntax is translated. */
function controlStoreSchema(PDO $pdo): void
{
    $sql=(string)file_get_contents(dirname(__DIR__,2).'/migrations/052_virtual_reply_controls.sql');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
        $sql=preg_replace('/--[^\n]*/','',$sql);
        $sql=preg_replace('/ CHARACTER SET \w+ COLLATE \w+/','',$sql);
        $sql=preg_replace('/ENUM\([^)]*\)/','TEXT',$sql);
        $sql=str_replace(['BIGINT UNSIGNED','SMALLINT UNSIGNED','TINYINT UNSIGNED'],['INTEGER','INTEGER','INTEGER'],$sql);
        $sql=preg_replace('/UNIQUE KEY \w+ \(([^)]+)\)/','UNIQUE ($1)',$sql);
        $sql=preg_replace('/^\s*KEY \w+ \([^\n]+\),?\n/m','',$sql);
        $sql=str_replace(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4','',$sql);
        $sql=preg_replace('/,\s*\)/',"\n)",$sql);
    }
    $pdo->exec($sql);
}

final class ControlStoreFixtureReader
{
    public SamplingFixtureReader $data;
    public bool $fail=false;
    public function __construct() {$this->data=new SamplingFixtureReader();}
    public function recent(string $source,int $limit): array {return $this->data->recent($source,$limit);}
    public function scan(string $source,int $after,int $limit): array {return $this->data->scan($source,$after,$limit);}
    public function snapshot(string $source,int $id): ?array {
        if ($this->fail) throw new RuntimeException('virtual_reply_source_unavailable');
        return $this->data->snapshot($source,$id);
    }
}

function controlStoreClear(PDO $pdo): void
{
    foreach (['virtual_reply_control_jobs','virtual_reply_control_cases','virtual_reply_control_runs',
        'virtual_reply_case_sampling','virtual_reply_sampling_claims','virtual_reply_sampling_aliases','virtual_reply_sampling_groups',
        'virtual_reply_jobs','virtual_reply_cases','virtual_reply_cursors','virtual_reply_quotas'] as $table) $pdo->exec('DELETE FROM '.$table);
}

function controlStoreFixture(PDO $pdo,int &$now): array
{
    controlStoreClear($pdo);$now=strtotime('2026-10-21T19:00:00Z');$reader=new ControlStoreFixtureReader();
    for ($id=1;$id<=100;$id++) $reader->data->add($id,'control-group-'.$id,['complete'=>true]);
    $clock=static function() use (&$now):int {return $now;};
    $ordinary=new VirtualReplyStore($pdo,$reader,$clock);$ordinary->capture('conversation','history',100);
    $store=new VirtualReplyControlStore($pdo,$reader,$clock);
    $baseline=VirtualReplyControlProtocol::authorContract('FROZEN BASELINE: answer only from the frozen context.');
    $candidate=VirtualReplyControlProtocol::authorContract('FROZEN CANDIDATE: preserve known constraints and continue useful checks.');
    return [$store,$ordinary,$reader,$baseline,$candidate];
}

function controlStoreMetadata(string $role): array
{
    $profile=VirtualReplyControlProtocol::profile($role);
    return ['model'=>$profile['model'],'reasoning_effort'=>$profile['reasoning']['effort'],'service_tier'=>'unknown'];
}

function controlStoreAuthor(array $context,string $draft='На какие даты планируете поездку?'): array
{
    return ['history_incomplete'=>$context['payload']['history_incomplete'],'evidence_message_ids'=>$context['evidence_message_ids'],
        'checks'=>[],'reason'=>'Даты не названы.','next_action'=>'clarify','draft'=>$draft,'should_draft'=>true,'ok'=>true];
}

function controlStoreReview(): array
{
    $metrics=['unnecessary_questions'=>0,'lost_constraints'=>0,'unsupported_claims'=>0,'useful_next_action'=>true];
    return ['ok'=>true,'assessable'=>true,'answers'=>['A'=>$metrics,'B'=>$metrics],'issues'=>[],'limitations'=>[],'preference'=>'tie'];
}

function controlStoreReject(callable $fn,?string $message=null): bool
{
    try {$fn();return false;}catch (RuntimeException|InvalidArgumentException $error) {return $message===null||$error->getMessage()===$message;}
}

/** These cases also run against the real MySQL migration and locking dialect. */
function controlStoreAssertions(PDO $pdo,callable $check): void
{
    $now=0;[$store,$ordinary,$reader,$baseline,$candidate]=controlStoreFixture($pdo,$now);
    $check('capture has no automatic control runs or author claims',(int)$pdo->query('SELECT COUNT(*) FROM virtual_reply_control_runs')->fetchColumn()===0
        &&(int)$pdo->query("SELECT COUNT(*) FROM virtual_reply_jobs WHERE status<>'pending'")->fetchColumn()===0);
    $run=$store->register($baseline,$candidate,1,7);
    $check('registration freezes two distinct supported contracts and one previously unseen group',$run['registered_cases']===1&&$run['purpose']==='control'
        &&(int)$pdo->query('SELECT COUNT(*) FROM virtual_reply_control_jobs')->fetchColumn()===3
        &&$pdo->query('SELECT baseline_contract_json FROM virtual_reply_control_runs')->fetchColumn()===json_encode($baseline,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $check('identical versions and unsupported model contracts cannot register work',controlStoreReject(fn()=>$store->register($baseline,$baseline,1,7))
        &&controlStoreReject(function() use ($store,$baseline,$candidate):void {$bad=$baseline;$bad['profile']['model']='weaker';$store->register($bad,$candidate,1,7);}));
    $a=$store->claim('author',7,1);$jA=$a['jobs'][0];$contextA=$store->context($jA['case_id'],'author',$jA['claim_token'],7);
    $check('author gets only five frozen fields with no private arm/order labels',array_keys($contextA)===['instructions','result_schema','payload','evidence_message_ids','profile']
        &&!isset($contextA['payload']['order'],$contextA['payload']['arm'],$contextA['payload']['version'])
        &&in_array($contextA['instructions'],[$baseline['instructions'],$candidate['instructions']],true));
    $check('claim ownership prevents another admin, role or token from reading the control',
        controlStoreReject(fn()=>$store->context($jA['case_id'],'author',$jA['claim_token'],8))
        &&controlStoreReject(fn()=>$store->context($jA['case_id'],'reviewer',$jA['claim_token'],7))
        &&controlStoreReject(fn()=>$store->context($jA['case_id'],'author',str_repeat('f',64),7)));
    $answerA=controlStoreAuthor($contextA,'Укажите даты поездки.');
    $savedA=$store->save($jA['case_id'],'author',$jA['claim_token'],7,$answerA,controlStoreMetadata('author'));
    $check('first arm result stays exact, including object ordering',$savedA['duplicate']===false
        &&$pdo->query('SELECT result_json FROM virtual_reply_control_jobs WHERE job_id='.$pdo->quote($jA['case_id']))->fetchColumn()===json_encode($answerA,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $check('one author result cannot release a reviewer',$store->claim('reviewer',7,1)['jobs']===[]);
    $b=$store->claim('author',8,1);$jB=$b['jobs'][0];$contextB=$store->context($jB['case_id'],'author',$jB['claim_token'],8);
    $check('second arm receives the exact same bounded context and the other frozen version',
        $contextA['payload']===$contextB['payload']&&$contextA['evidence_message_ids']===$contextB['evidence_message_ids']
        &&$contextA['instructions']!==$contextB['instructions']&&$a['daily_used']===1&&$b['daily_used']===2);
    $answerB=controlStoreAuthor($contextB,'На какие даты рассматриваете тур?');
    $store->save($jB['case_id'],'author',$jB['claim_token'],8,$answerB,controlStoreMetadata('author'));
    $check('saved author idempotency cannot overwrite exact text',$store->save($jA['case_id'],'author',$jA['claim_token'],7,$answerA,controlStoreMetadata('author'))['duplicate']===true
        &&controlStoreReject(fn()=>$store->save($jA['case_id'],'author',$jA['claim_token'],7,$answerB,controlStoreMetadata('author'))));
    $check('both completed arms still require the independent review delay',$store->claim('reviewer',7,1)['jobs']===[]);
    $now+=59;$check('review is unavailable one second before its boundary',$store->claim('reviewer',7,1)['jobs']===[]);
    $now++;$r=$store->claim('reviewer',9,1);$jR=$r['jobs'][0];$contextR=$store->context($jR['case_id'],'reviewer',$jR['claim_token'],9);
    $pair=$contextR['payload']['answers'];$pairValues=[$pair['A'],$pair['B']];
    $check('reviewer receives exact blinded pair and original context without authors metadata',
        $contextR['payload']['context']===$contextA['payload']&&count($contextR['payload'])===2
        &&in_array($answerA,$pairValues,true)&&in_array($answerB,$pairValues,true)
        &&!isset($contextR['payload']['order'],$contextR['payload']['baseline'],$contextR['payload']['candidate'],$contextR['payload']['metadata'])
        &&$contextR['profile']['model']==='gpt-6-astra'&&$contextR['profile']['reasoning']['effort']==='high');
    $check('ordinary review schema cannot be saved as a paired control result',controlStoreReject(fn()=>$store->save($jR['case_id'],'reviewer',$jR['claim_token'],9,
        ['ok'=>true,'verdict'=>'pass','issues'=>[],'corrected_draft'=>null,'lesson_candidate'=>null],controlStoreMetadata('reviewer'))));
    $store->save($jR['case_id'],'reviewer',$jR['claim_token'],9,controlStoreReview(),controlStoreMetadata('reviewer'));
    $status=$store->status($run['run_id']);
    $check('run reporting counts paired denominators and whole groups rather than passes',
        $status['results']['unique_reviewed_groups']===1&&$status['results']['metrics']['unnecessary_questions']['paired_denominator']===1
        &&$status['results']['metrics']['useful_next_action']['baseline']['useful_case_rate']===1
        &&$status['quotas']['author_used']===2&&$status['quotas']['reviewer_used']===1);
    $wire=json_encode($status,JSON_THROW_ON_ERROR);$allStatus=json_encode($store->status(),JSON_THROW_ON_ERROR);
    $check('aggregate status contains no transcript, answer, token or private order map',!str_contains($wire,$answerA['draft'])&&!str_contains($allStatus,$jA['claim_token'])
        &&!str_contains($wire,'order_json')&&!str_contains($wire,'payload_json'));
    $metadata=json_decode((string)$pdo->query('SELECT metadata_json FROM virtual_reply_control_jobs WHERE job_id='.$pdo->quote($jR['case_id']))->fetchColumn(),true);
    $check('ChatGPT metadata preserves unknown service tier and does not become provider proof',$metadata['provider_verified']===false
        &&$metadata['profile_source']==='worker_declared'&&$metadata['declared_profile']['service_tier']==='unknown');
    controlStoreSchema($pdo);$check('reapplying forward DDL cannot erase frozen jobs or consumed quotas',
        $store->status($run['run_id'])['results']['reviews_completed']===1&&$store->status($run['run_id'])['quotas']['author_used']===2);
    $ordinaryClaim=$ordinary->claim('author',10,1);
    $check('ordinary and control claims share the same role/day quota',$ordinaryClaim['daily_used']===3&&count($ordinaryClaim['jobs'])===1
        &&(int)$pdo->query("SELECT COUNT(*) FROM virtual_reply_jobs WHERE status='running'")->fetchColumn()===1);
    $check('control-owned ids are distinct from ordinary case routing',$store->owns($jA['case_id'])&&!$store->owns($ordinaryClaim['jobs'][0]['case_id']));

    // An untouched ordinary instruction refresh cannot rewrite the paired run's frozen contracts.
    [$store,$ordinary,$reader,$baseline,$candidate]=controlStoreFixture($pdo,$now);$run=$store->register($baseline,$candidate,1,7);
    $sourceId=(int)$pdo->query('SELECT source_id FROM virtual_reply_control_cases')->fetchColumn();
    $oldCase=(string)$pdo->query('SELECT case_id FROM virtual_reply_cases WHERE source_id='.$sourceId)->fetchColumn();
    $statement=$pdo->prepare("UPDATE virtual_reply_jobs SET instruction_revision=? WHERE case_id=? AND role='author'");$statement->execute([str_repeat('0',64),$oldCase]);
    $ordinary->reconcile(100);
    $newCase=(string)$pdo->query('SELECT case_id FROM virtual_reply_cases WHERE source_id='.$sourceId)->fetchColumn();
    $claim=$store->claim('author',7,2);$contracts=[];
    foreach ($claim['jobs'] as $job) $contracts[]=$store->context($job['case_id'],'author',$job['claim_token'],7)['instructions'];
    sort($contracts,SORT_STRING);$expected=[$baseline['instructions'],$candidate['instructions']];sort($expected,SORT_STRING);
    $check('ordinary untouched instruction refresh preserves paired contracts and source membership',
        $oldCase!==$newCase&&$contracts===$expected&&$pdo->query('SELECT state FROM virtual_reply_control_cases')->fetchColumn()==='active');

    // A new canonical source revision must purge controls immediately, before any control read.
    [$store,$ordinary,$reader,$baseline,$candidate]=controlStoreFixture($pdo,$now);$run=$store->register($baseline,$candidate,1,7);$store->claim('author',7,2);
    $sourceId=(int)$pdo->query('SELECT source_id FROM virtual_reply_control_cases')->fetchColumn();$reader->data->rows['conversation:'.$sourceId]['text']='Новый текст после регистрации';
    $ordinary->reconcile(100);
    $check('ordinary source reconciliation purges paired private data in its canonical transaction',
        $pdo->query('SELECT state FROM virtual_reply_control_cases')->fetchColumn()==='invalidated'
        &&(int)$pdo->query('SELECT COUNT(*) FROM virtual_reply_control_jobs WHERE claim_token_sha256 IS NOT NULL OR result_json IS NOT NULL')->fetchColumn()===0
        &&(int)$pdo->query("SELECT used_count FROM virtual_reply_quotas WHERE role='author'")->fetchColumn()===2);

    [$store,$ordinary,$reader,$baseline,$candidate]=controlStoreFixture($pdo,$now);$store->register($baseline,$candidate,1,7);$store->claim('author',7,1);
    $sourceId=(int)$pdo->query('SELECT source_id FROM virtual_reply_control_cases')->fetchColumn();unset($reader->data->rows['conversation:'.$sourceId]);
    $ordinary->reconcile(100);
    $check('ordinary source deletion purges pair before a control read and keeps spent quota',
        $pdo->query('SELECT state FROM virtual_reply_control_cases')->fetchColumn()==='invalidated'
        &&(int)$pdo->query('SELECT COUNT(*) FROM virtual_reply_control_jobs WHERE claim_token_sha256 IS NOT NULL OR result_json IS NOT NULL')->fetchColumn()===0
        &&(int)$pdo->query("SELECT used_count FROM virtual_reply_quotas WHERE role='author'")->fetchColumn()===1);

    [$store,$ordinary,$reader,$baseline,$candidate]=controlStoreFixture($pdo,$now);$run=$store->register($baseline,$candidate,2,7);$store->claim('author',7,4);
    $linked=$pdo->query('SELECT group_key,source_id FROM virtual_reply_control_cases ORDER BY group_key')->fetchAll(PDO::FETCH_ASSOC);
    $firstId=(int)$linked[0]['source_id'];$secondId=(int)$linked[1]['source_id'];
    $reader->data->rows['conversation:'.$secondId]['aliases']=array_values(array_unique([
        ...$reader->data->rows['conversation:'.$firstId]['aliases'],...$reader->data->rows['conversation:'.$secondId]['aliases']]));
    $ordinary->reconcile(100);
    $check('ordinary alias merge purges both registered target and deleted donor groups',
        $run['registered_cases']===2&&(int)$pdo->query("SELECT COUNT(*) FROM virtual_reply_control_cases WHERE state='invalidated'")->fetchColumn()===2
        &&(int)$pdo->query('SELECT COUNT(*) FROM virtual_reply_control_cases WHERE payload_json IS NOT NULL OR order_json IS NOT NULL')->fetchColumn()===0
        &&(int)$pdo->query('SELECT COUNT(*) FROM virtual_reply_control_jobs WHERE claim_token_sha256 IS NOT NULL OR result_json IS NOT NULL')->fetchColumn()===0
        &&(int)$pdo->query("SELECT used_count FROM virtual_reply_quotas WHERE role='author'")->fetchColumn()===4
        &&(int)$pdo->query("SELECT COUNT(*) FROM virtual_reply_sampling_groups WHERE group_key IN (SELECT group_key FROM virtual_reply_control_cases)")->fetchColumn()===1);

    // A pair with an unknown arm is permanently excluded; the untouched sibling is not generated.
    [$store,$ordinary,$reader,$baseline,$candidate]=controlStoreFixture($pdo,$now);$run=$store->register($baseline,$candidate,1,7);
    $claim=$store->claim('author',7,1);$lost=$claim['jobs'][0];$now+=901;
    $check('expired control claim becomes terminal unknown without releasing a sibling or quota',$store->claim('author',7,2)['jobs']===[]
        &&$store->status($run['run_id'])['results']['excluded']['unknown']===1&&$store->status($run['run_id'])['quotas']['author_used']===1);
    $check('expired result cannot be saved or context reread',controlStoreReject(fn()=>$store->context($lost['case_id'],'author',$lost['claim_token'],7))
        &&controlStoreReject(fn()=>$store->save($lost['case_id'],'author',$lost['claim_token'],7,['ok'=>true],controlStoreMetadata('author'))));
    $now=strtotime('2026-10-22T19:00:00Z');
    $check('a new UTC day cannot repeat an unknown pair or register its group again',$store->claim('author',7,2)['jobs']===[]
        &&$store->status($run['run_id'])['quotas']['author_used']===0);
    $pdo->exec("UPDATE virtual_reply_sampling_groups SET cohort='development' WHERE group_key NOT IN (SELECT group_key FROM virtual_reply_control_cases)");
    $check('a consumed control group is not another unseen control in a second run',$store->register($baseline,$candidate,2,7)['registered_cases']===0);

    // Source mutation must purge both arms, reviewer, mapping and tokens, without quota refund.
    [$store,$ordinary,$reader,$baseline,$candidate]=controlStoreFixture($pdo,$now);$run=$store->register($baseline,$candidate,1,7);$claim=$store->claim('author',7,2);
    foreach ($claim['jobs'] as $job) {$ctx=$store->context($job['case_id'],'author',$job['claim_token'],7);$store->save($job['case_id'],'author',$job['claim_token'],7,controlStoreAuthor($ctx),controlStoreMetadata('author'));}
    $source=(int)$pdo->query('SELECT source_id FROM virtual_reply_control_cases')->fetchColumn();$reader->data->rows['conversation:'.$source]['text']='Изменённый запрос о Турции';
    $status=$store->status($run['run_id']);
    $check('edited source purges the entire paired case before reporting scores',$status['results']['excluded']['invalidated']===1
        &&$status['results']['reviews_completed']===0&&$status['quotas']['author_used']===2
        &&(int)$pdo->query('SELECT COUNT(*) FROM virtual_reply_control_jobs WHERE result_json IS NOT NULL OR claim_token_sha256 IS NOT NULL OR metadata_json IS NOT NULL')->fetchColumn()===0
        &&(int)$pdo->query('SELECT COUNT(*) FROM virtual_reply_control_cases WHERE payload_json IS NOT NULL OR evidence_ids_json IS NOT NULL OR order_json IS NOT NULL')->fetchColumn()===0);
    $check('purged control ids cannot expose context even with their original claim',controlStoreReject(fn()=>$store->context($claim['jobs'][0]['case_id'],'author',$claim['jobs'][0]['claim_token'],7)));

    [$store,$ordinary,$reader,$baseline,$candidate]=controlStoreFixture($pdo,$now);$run=$store->register($baseline,$candidate,1,7);$claim=$store->claim('author',7,1);$job=$claim['jobs'][0];
    $reader->fail=true;
    $check('technical source failure is not interpreted as deletion or private purge',controlStoreReject(fn()=>$store->context($job['case_id'],'author',$job['claim_token'],7),'virtual_reply_source_unavailable')
        &&$pdo->query('SELECT state FROM virtual_reply_control_cases')->fetchColumn()==='active'
        &&(int)$pdo->query('SELECT COUNT(*) FROM virtual_reply_control_jobs WHERE claim_token_sha256 IS NOT NULL')->fetchColumn()===1);
    $reader->fail=false;$source=(int)$pdo->query('SELECT source_id FROM virtual_reply_control_cases')->fetchColumn();unset($reader->data->rows['conversation:'.$source]);
    $check('missing or revoked source purges pair on the next bounded read',$store->status($run['run_id'])['results']['excluded']['invalidated']===1
        &&$store->status($run['run_id'])['quotas']['author_used']===1);

    [$store,$ordinary,$reader,$baseline,$candidate]=controlStoreFixture($pdo,$now);$run=$store->register($baseline,$candidate,1,7);$claim=$store->claim('author',7,1);
    $source=(int)$pdo->query('SELECT source_id FROM virtual_reply_control_cases')->fetchColumn();$reader->data->rows['conversation:'.$source]['complete']=false;
    $check('newly ambiguous identity contaminates the reserved pair without changing its source revision',
        $store->status($run['run_id'])['results']['excluded']['invalidated']===1
        &&$pdo->query("SELECT cohort FROM virtual_reply_sampling_groups WHERE group_key=(SELECT group_key FROM virtual_reply_control_cases)")->fetchColumn()==='contaminated');

    [$store,$ordinary,$reader,$baseline,$candidate]=controlStoreFixture($pdo,$now);$run=$store->register($baseline,$candidate,1,7);$claim=$store->claim('author',7,2);
    $group=(string)$pdo->query('SELECT group_key FROM virtual_reply_control_cases')->fetchColumn();$sourceId=(int)$pdo->query('SELECT source_id FROM virtual_reply_control_cases')->fetchColumn();
    $ordinaryCase=(string)$pdo->query('SELECT case_id FROM virtual_reply_cases WHERE source_id='.$sourceId)->fetchColumn();
    $pdo->beginTransaction();VirtualReplyControlStore::invalidateSource($pdo,$ordinaryCase);$check('source invalidation hook joins the existing canonical transaction',$pdo->inTransaction());$pdo->commit();
    $check('canonical source hook purges all dependent arms and retains quota',$store->status($run['run_id'])['results']['excluded']['invalidated']===1
        &&$store->status($run['run_id'])['quotas']['author_used']===2);
    [$store,$ordinary,$reader,$baseline,$candidate]=controlStoreFixture($pdo,$now);$run=$store->register($baseline,$candidate,1,7);$store->claim('author',7,1);
    $group=(string)$pdo->query('SELECT group_key FROM virtual_reply_control_cases')->fetchColumn();$pdo->beginTransaction();VirtualReplyControlStore::invalidateGroup($pdo,$group);$pdo->commit();
    $check('alias-merge hook can purge donor or target group after registration',$store->status($run['run_id'])['results']['excluded']['invalidated']===1);

    [$store,$ordinary,$reader,$baseline,$candidate]=controlStoreFixture($pdo,$now);$run=$store->register($baseline,$candidate,1,7);
    $pdo->exec("INSERT INTO virtual_reply_quotas(project_key,day_utc,role,used_count,updated_at) VALUES ('anytour','2026-10-21','author',199,'2026-10-21 19:00:00')");
    $claim=$store->claim('author',7,2);
    $check('control author allocation stops at the existing shared200 limit',count($claim['jobs'])===1&&$claim['daily_used']===200&&$store->claim('author',8,1)['jobs']===[]);
    $check('ordinary worker cannot bypass quota already consumed by a control',$ordinary->claim('author',8,1)['jobs']===[]
        &&$ordinary->claim('author',8,1)['daily_used']===200);
    controlStoreClear($pdo);
}
