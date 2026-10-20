<?php

declare(strict_types=1);

require_once __DIR__.'/../services/VirtualReplyStore.php';

$passed=0;$failed=0;
function storeCheck(string $name,bool $ok): void {
    global $passed,$failed;echo ($ok?'PASS ':'FAIL ').$name."\n";$ok?$passed++:$failed++;
}
function storeReject(callable $call,?string $code=null): bool {
    try {$call();return false;} catch (Throwable $error) {return $code===null || $error->getMessage()===$code;}
}
function storeDb(): PDO {
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    // Execute the actual migration, translating only MySQL DDL syntax.
    $sql=(string)file_get_contents(__DIR__.'/../migrations/049_virtual_reply_learning.sql');
    $sql=preg_replace('/--[^\n]*/','',$sql);
    $sql=preg_replace('/ CHARACTER SET \w+ COLLATE \w+/','',$sql);
    $sql=preg_replace('/ENUM\([^)]*\)/','TEXT',$sql);
    $sql=str_replace(['BIGINT UNSIGNED NOT NULL AUTO_INCREMENT','BIGINT UNSIGNED','SMALLINT UNSIGNED'],['INTEGER NOT NULL','INTEGER','INTEGER'],$sql);
    $sql=preg_replace('/UNIQUE KEY \w+ \(([^)]+)\)/','UNIQUE ($1)',$sql);
    $sql=preg_replace('/^\s*KEY \w+ \([^\n]+\),?\n/m','',$sql);
    $sql=str_replace(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4','',$sql);
    $sql=preg_replace('/,\s*\)/',"\n)",$sql);
    $pdo->exec($sql);
    return $pdo;
}
final class StoreFixtureReader {
    public array $values=[];
    public array $calls=[];
    public bool $fail=false;
    private PDO $pdo;
    public function __construct(PDO $pdo) {$this->pdo=$pdo;}
    public function put(string $source,int $id,string $text='Трое взрослых, Египет',bool $incomplete=false): void {
        $payload=['messages'=>[['id'=>$id,'sender_type'=>'tourist','text'=>$text,'created_at'=>'2026-10-20T10:00:00Z']],
            'history_incomplete'=>$incomplete,'cutoff'=>['source_id'=>$id,'reference_time'=>'2026-10-20T10:00:00Z'],
            'historical_context_reconstructed'=>false,'revised_question'=>false];
        $this->values[$source][$id]=['project_key'=>'anytour','source_kind'=>$source,'source_id'=>$id,
            'source_scope'=>'PRIVATE_ACCOUNT_IDENTITY','source_revision'=>hash('sha256',json_encode($payload)),
            'reference_time'=>'2026-10-20T10:00:00Z','mode'=>'live','evidence_message_ids'=>[$id],'payload'=>$payload];
    }
    public function snapshot(string $source,int $id): ?array {
        if ($this->pdo->inTransaction()) throw new RuntimeException('fixture_snapshot_inside_transaction');
        $this->calls[]=['snapshot',$source,$id];
        if ($this->fail) throw new RuntimeException('virtual_reply_source_unavailable');
        return $this->values[$source][$id] ?? null;
    }
    public function scan(string $source,int $after,int $limit): array {
        $this->calls[]=['scan',$source,$after,$limit];
        $ids=array_values(array_filter(array_keys($this->values[$source] ?? []),static fn($id)=>$id>$after));sort($ids);
        $selected=array_slice($ids,0,$limit);
        return ['source_ids'=>$selected,'last_id'=>$selected?end($selected):$after,'has_more'=>count($ids)>$limit];
    }
    public function recent(string $source,int $limit): array {
        $this->calls[]=['recent',$source,$limit];
        $ids=array_keys($this->values[$source] ?? []);sort($ids);$selected=array_slice($ids,-$limit);
        return ['source_ids'=>$selected,'last_id'=>$selected?end($selected):0,'has_more'=>count($ids)>$limit];
    }
}
function storeAuthor(int $id,bool $incomplete=false): array {
    return ['ok'=>true,'should_draft'=>true,'draft'=>'На какие даты планируете поездку?',
        'next_action'=>'clarify','reason'=>'Состав и направление указаны, даты неизвестны.',
        'checks'=>['Текущая цена не проверялась.'],'evidence_message_ids'=>[$id],'history_incomplete'=>$incomplete];
}
function storeReview(int $id): array {
    return ['ok'=>true,'verdict'=>'revise','issues'=>[['category'=>'usefulness','severity'=>'minor',
        'explanation'=>'Можно ответить точнее по уже известному периоду.','evidence_message_ids'=>[$id]]],
        'corrected_draft'=>'Какой общий бюджет рассматриваете?',
        'lesson_candidate'=>'Использовать названный период до уточнения новых условий.'];
}
function storeMetadata(string $role): array {
    $p=VirtualReplyProtocol::profile($role);return ['model'=>$p['model'],'reasoning_effort'=>$p['reasoning']['effort'],'service_tier'=>$p['service_tier']];
}
function storeFirst(PDO $pdo,string $source,int $id): array {
    $q=$pdo->prepare('SELECT * FROM virtual_reply_cases WHERE source_kind=? AND source_id=?');$q->execute([$source,$id]);return $q->fetch(PDO::FETCH_ASSOC);
}
function storeJob(PDO $pdo,string $caseId,string $role): array {
    $q=$pdo->prepare('SELECT * FROM virtual_reply_jobs WHERE case_id=? AND role=?');$q->execute([$caseId,$role]);return $q->fetch(PDO::FETCH_ASSOC);
}

$pdo=storeDb();$reader=new StoreFixtureReader($pdo);$time=1792490400;
$store=new VirtualReplyStore($pdo,$reader,static function() use (&$time): int {return $time;});
foreach ([1,2,3,4,5] as $id) $reader->put('conversation',$id);
$fresh=$store->capture('conversation','fresh',2);
storeCheck('fresh bootstrap captures recent rows without traversing historical backlog',$fresh['scanned']===2 && $fresh['captured']===2 && $fresh['cursor']===5
    && $reader->calls[0]===['recent','conversation',2]);
$history=$store->capture('conversation','history',2);
storeCheck('history lane starts at zero independently',$history['cursor']===2 && $history['captured']===2
    && in_array(['scan','conversation',0,2],$reader->calls,true));
$reader->put('conversation',6);$fresh2=$store->capture('conversation','fresh',2);
storeCheck('fresh lane advances from its own discovery cursor',$fresh2['cursor']===6 && $fresh2['captured']===1
    && in_array(['scan','conversation',5,2],$reader->calls,true));
$reader->put('telegram_archive',1);$archive=$store->capture('telegram_archive','history',5);
storeCheck('source namespaces and lane cursors do not collide',$archive['captured']===1 && $archive['cursor']===1
    && storeFirst($pdo,'telegram_archive',1)['case_id']!==storeFirst($pdo,'conversation',1)['case_id']);
$history2=$store->capture('conversation','history',10);
storeCheck('discovery replay deduplicates same frozen source',$history2['captured']===1 && $history2['unchanged']===3
    && (int)$pdo->query('SELECT COUNT(*) FROM virtual_reply_cases')->fetchColumn()===7);
$claim=$store->claim('author',9,1);$job=$claim['jobs'][0];$caseId=$job['case_id'];
$source=$pdo->query("SELECT source_id FROM virtual_reply_cases WHERE case_id='".$caseId."'")->fetchColumn();$source=(int)$source;
storeCheck('claims return only metadata and token, with bounded frozen role profile',count($claim['jobs'])===1 && $claim['daily_used']===1
    && $job['profile']===VirtualReplyProtocol::profile('author') && !str_contains(json_encode($claim),'Трое взрослых'));
storeCheck('only hashed lease token is stored',storeJob($pdo,$caseId,'author')['claim_token_sha256']===hash('sha256',$job['claim_token'])
    && !str_contains(json_encode(storeJob($pdo,$caseId,'author')),$job['claim_token']));
$context=$store->context($caseId,'author',$job['claim_token'],9);
storeCheck('context contains frozen instructions and source projection without raw identities',$context['payload']['messages'][0]['id']===$source
    && $context['instructions']===VirtualReplyProtocol::instructions('author') && !str_contains(json_encode($context),'PRIVATE_ACCOUNT'));
storeCheck('wrong claimant cannot access private context',storeReject(fn()=>$store->context($caseId,'author',$job['claim_token'],10),'virtual_reply_claim_unavailable'));
storeCheck('wrong token cannot access private context',storeReject(fn()=>$store->context($caseId,'author',str_repeat('a',64),9),'virtual_reply_claim_unavailable'));
storeCheck('reviewer cannot claim before a saved author',$store->claim('reviewer',9,20)['jobs']===[]);
$bad=storeAuthor($source);$bad['history_incomplete']=true;
storeCheck('frozen incomplete flag cannot be changed by author',storeReject(fn()=>$store->save($caseId,'author',$job['claim_token'],9,$bad),'virtual_reply_history_mismatch'));
$bad=storeAuthor($source);$bad['evidence_message_ids']=[9999];
storeCheck('author cannot cite evidence outside frozen source',storeReject(fn()=>$store->save($caseId,'author',$job['claim_token'],9,$bad),'invalid_virtual_reply_result'));
storeCheck('declared weaker or different model is rejected',storeReject(fn()=>$store->save($caseId,'author',$job['claim_token'],9,storeAuthor($source),['model'=>'other']),'virtual_reply_profile_mismatch'));
storeCheck('metadata cannot assert provider verification',storeReject(fn()=>$store->save($caseId,'author',$job['claim_token'],9,storeAuthor($source),['provider_verified'=>true]),'invalid_virtual_reply_metadata'));
$saved=$store->save($caseId,'author',$job['claim_token'],9,storeAuthor($source),storeMetadata('author'));
$duplicate=$store->save($caseId,'author',$job['claim_token'],9,storeAuthor($source),storeMetadata('author'));
storeCheck('save is exact and idempotent without another claim',$saved['duplicate']===false && $duplicate['duplicate']===true && $claim['daily_used']===1);
$changed=storeAuthor($source);$changed['draft']='Другой ответ';
storeCheck('an already saved answer cannot be overwritten',storeReject(fn()=>$store->save($caseId,'author',$job['claim_token'],9,$changed,storeMetadata('author')),'virtual_reply_already_saved'));
$technical=json_decode(storeJob($pdo,$caseId,'author')['metadata_json'],true);
storeCheck('worker declaration is never presented as provider proof',$technical['profile_source']==='worker_declared'
    && $technical['provider_verified']===false && $technical['requested_profile']===VirtualReplyProtocol::profile('author'));
$time+=59;storeCheck('reviewer has an actual delayed independent pass',$store->claim('reviewer',9,20)['jobs']===[]);
$time++;$reviewClaim=$store->claim('reviewer',11,20);$reviewJob=$reviewClaim['jobs'][0];
$reviewContext=$store->context($caseId,'reviewer',$reviewJob['claim_token'],11);
storeCheck('later reviewer gets the exact full saved author result',$reviewContext['payload']['author_result']===storeAuthor($source)
    && $reviewContext['profile']===VirtualReplyProtocol::profile('reviewer') && $reviewContext['instructions']===VirtualReplyProtocol::instructions('reviewer'));
storeCheck('review is unavailable until completed',storeReject(fn()=>$store->review($caseId),'virtual_reply_review_unavailable'));
$reviewMetadata=storeMetadata('reviewer');$reviewMetadata['service_tier']='unknown';
$store->save($caseId,'reviewer',$reviewJob['claim_token'],11,storeReview($source),$reviewMetadata);
$export=$store->review($caseId);$listed=$store->reviews(10);
storeCheck('unexposed ChatGPT tier stays unknown rather than becoming requested Standard',
    $export['review_metadata']['declared_profile']['service_tier']==='unknown'
    &&$export['review_metadata']['requested_profile']['service_tier']==='default'
    &&$export['review_metadata']['provider_verified']===false);
storeCheck('completed review exports exact frozen source and both passes',$export['author_result']===storeAuthor($source)
    && $export['review_result']===storeReview($source) && count($listed['reviews'])===1 && $listed['reviews'][0]['verdict']==='revise'
    && !str_contains(json_encode($listed),'Трое взрослых') && $export['disposition']===['status'=>'unresolved']);
$decision=['status'=>'applied','reason'=>'Уточнение даты учтено.','change_url'=>'https://github.com/pyatkoff/max-search-bot/pull/123',
    'validation'=>'Проверены отдельные случаи с известными и неизвестными датами.'];
$badDecision=$decision;$badDecision['change_url']=null;
storeCheck('applied disposition requires concrete repository PR',storeReject(fn()=>$store->disposition($caseId,$badDecision,9),'invalid_virtual_reply_disposition'));
$badDecision=$decision;$badDecision['change_url']='https://github.com/other/repo/pull/123';
storeCheck('disposition cannot point at an unrelated change',storeReject(fn()=>$store->disposition($caseId,$badDecision,9),'invalid_virtual_reply_disposition'));
$resolved=$store->disposition($caseId,$decision,9);$again=$store->disposition($caseId,$decision,9);
storeCheck('lesson resolution records admin and is idempotent',$resolved['disposition']['admin_id']===9 && !$resolved['duplicate'] && $again['duplicate']
    && $store->reviews(5)['reviews'][0]['disposition']['status']==='applied');
$badDecision=$decision;$badDecision['status']='rejected';$badDecision['change_url']=null;
storeCheck('a recorded disposition is not silently replaced',storeReject(fn()=>$store->disposition($caseId,$badDecision,9),'virtual_reply_already_disposed'));
$otherClaim=$store->claim('author',9,1)['jobs'][0];
$otherContext=$store->context($otherClaim['case_id'],'author',$otherClaim['claim_token'],9);
$otherSource=$otherContext['source']['id'];
$store->save($otherClaim['case_id'],'author',$otherClaim['claim_token'],9,storeAuthor($otherSource),storeMetadata('author'));
$time+=60;$otherReviewer=$store->claim('reviewer',11,1)['jobs'][0];
$store->save($otherClaim['case_id'],'reviewer',$otherReviewer['claim_token'],11,storeReview($otherSource),storeMetadata('reviewer'));
$pdo->prepare('UPDATE virtual_reply_jobs SET completed_at=? WHERE case_id=? AND role=?')->execute(['2099-01-01 00:00:00',$caseId,'reviewer']);
storeCheck('unresolved lessons stay ahead of newer resolved reviews',$store->reviews(1)['reviews'][0]['case_id']===$otherClaim['case_id']);
$otherKind=$otherContext['source']['kind'];$reader->values[$otherKind][$otherSource]=null;
storeReject(fn()=>$store->review($otherClaim['case_id']));

$reader->put('conversation',$source,'CHANGED SOURCE');
storeCheck('source edit blocks old exports and recaptures eligible current version',storeReject(fn()=>$store->review($caseId),'virtual_reply_source_changed')
    && storeFirst($pdo,'conversation',$source)['case_id']!==$caseId);
$oldCount=$pdo->prepare('SELECT COUNT(*) FROM virtual_reply_jobs WHERE case_id=?');$oldCount->execute([$caseId]);
storeCheck('edit purges former results, claims and disposition',(int)$oldCount->fetchColumn()===0
    && storeFirst($pdo,'conversation',$source)['lesson_decision_json']===null && $store->reviews(10)['reviews']===[]);
$newCase=storeFirst($pdo,'conversation',$source)['case_id'];
$reader->values['conversation'][$source]=null;
$store->reconcile(100);
storeCheck('permission revocation purges stored context and results',storeFirst($pdo,'conversation',$source)['state']==='invalidated'
    && storeFirst($pdo,'conversation',$source)['payload_json']===null && storeJob($pdo,$newCase,'author')['claim_token_sha256']===null);
$reader->put('conversation',$source,'RESTORED SOURCE');$store->reconcile(100);$store->reconcile(100);
storeCheck('bounded rotation can recapture restored permission',storeFirst($pdo,'conversation',$source)['state']==='active'
    && storeFirst($pdo,'conversation',$source)['case_id']!==$newCase);
$before=storeFirst($pdo,'conversation',$source);$reader->fail=true;
storeCheck('technical source failure propagates without destructive invalidation',storeReject(fn()=>$store->reconcile(100),'virtual_reply_source_unavailable')
    && storeFirst($pdo,'conversation',$source)['payload_json']===$before['payload_json']);$reader->fail=false;

// A second isolated fixture exercises expiry, protocol drift and quota boundaries.
$bounded=storeDb();$boundedReader=new StoreFixtureReader($bounded);$now=1792490400;
$boundedStore=new VirtualReplyStore($bounded,$boundedReader,static function() use (&$now): int {return $now;});
for ($id=1;$id<=230;$id++) $boundedReader->put('conversation',$id,'Synthetic '.$id);
$boundedStore->capture('conversation','history',100);$boundedStore->capture('conversation','history',100);$boundedStore->capture('conversation','history',100);
$leases=$boundedStore->claim('author',5,999);
storeCheck('claim call is capped at twenty',count($leases['jobs'])===20 && $leases['daily_used']===20);
$lease=$leases['jobs'][0];$now+=900;
storeCheck('late save marks unknown instead of retrying',storeReject(fn()=>$boundedStore->save($lease['case_id'],'author',$lease['claim_token'],5,storeAuthor(1)),'virtual_reply_claim_expired')
    && storeJob($bounded,$lease['case_id'],'author')['status']==='unknown');
for ($batch=0;$batch<9;$batch++) $boundedStore->claim('author',5,20);
$cap=$boundedStore->claim('author',5,20);
storeCheck('daily transactional quota stops at two hundred without budget reset',$cap['jobs']===[] && $cap['daily_used']===200
    && (int)$bounded->query("SELECT COUNT(*) FROM virtual_reply_jobs WHERE role='author' AND status='pending'")->fetchColumn()===30);
$boundedStore->reconcile(100);
storeCheck('expired leases never become pending again',storeJob($bounded,$lease['case_id'],'author')['status']==='unknown');
$now+=86400;$next=$boundedStore->claim('author',5,1);
storeCheck('new UTC day only opens a new quota row, never retries unknown jobs',count($next['jobs'])===1 && $next['daily_used']===1
    && $next['jobs'][0]['case_id']!==$lease['case_id']);
$drift=$next['jobs'][0];$driftCase=$bounded->query("SELECT source_id FROM virtual_reply_cases WHERE case_id='".$drift['case_id']."'")->fetchColumn();
$bounded->prepare('UPDATE virtual_reply_jobs SET instruction_revision=? WHERE case_id=? AND role=?')->execute([str_repeat('f',64),$drift['case_id'],'author']);
storeCheck('protocol drift invalidates and recaptures instead of mixing validation versions',storeReject(fn()=>$boundedStore->context($drift['case_id'],'author',$drift['claim_token'],5),'virtual_reply_source_changed')
    && storeFirst($bounded,'conversation',(int)$driftCase)['case_id']!==$drift['case_id']);
$outside=0;foreach ($boundedReader->calls as $call) if ($call[0]==='snapshot') $outside++;
storeCheck('all source checks are outside store transactions',$outside>200 && !$bounded->inTransaction());
storeCheck('invalid input cannot broaden source or claim scope',storeReject(fn()=>$store->capture('other','history',10),'invalid_virtual_reply_source')
    && storeReject(fn()=>$store->capture('conversation','other',10),'invalid_virtual_reply_lane')
    && storeReject(fn()=>$store->claim('author',0,10),'invalid_virtual_reply_admin')
    && storeReject(fn()=>$store->reviews(0),'invalid_virtual_reply_limit'));
$pdo->beginTransaction();
storeCheck('caller transaction cannot supply a stale source snapshot',storeReject(fn()=>$store->reconcile(1),'virtual_reply_source_recheck_in_transaction'));
$pdo->rollBack();

$priorityDb=storeDb();$priorityReader=new StoreFixtureReader($priorityDb);$priorityNow=1792490400;
$priorityStore=new VirtualReplyStore($priorityDb,$priorityReader,static function()use(&$priorityNow):int{return $priorityNow;});
foreach ([1,2] as $id) {
    $priorityReader->put('conversation',$id,'Старый вопрос');
    $old=&$priorityReader->values['conversation'][$id];
    $old['reference_time']='2020-01-01T10:00:00Z';$old['payload']['cutoff']['reference_time']=$old['reference_time'];
    $old['payload']['messages'][0]['created_at']=$old['reference_time'];$old['source_revision']=hash('sha256',json_encode($old['payload']));
    unset($old);
}
$priorityStore->capture('conversation','history',20);$priorityNow+=10;
$priorityReader->put('conversation',3,'Новый вопрос');$priorityStore->capture('conversation','fresh',20);
$prioritized=$priorityStore->claim('author',7,1)['jobs'][0];
storeCheck('recent real questions take priority over older captured archive backlog',
    $priorityStore->context($prioritized['case_id'],'author',$prioritized['claim_token'],7)['source']['id']===3);
$historyClaim=$priorityStore->claim('author',7,1)['jobs'][0];
storeCheck('historical backlog remains available after recent work',
    $priorityStore->context($historyClaim['case_id'],'author',$historyClaim['claim_token'],7)['source']['id']===1);

echo "Virtual reply store regression: {$passed} passed, {$failed} failed\n";
exit($failed?1:0);
