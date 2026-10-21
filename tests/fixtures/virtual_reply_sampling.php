<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/services/VirtualReplyStore.php';

/** Executes the real forward migrations; only dialect syntax is translated. */
function samplingSchema(PDO $pdo,bool $withSampling=true): void
{
    $files=['049_virtual_reply_learning.sql'];
    if ($withSampling) $files[]='051_virtual_reply_sampling.sql';
    foreach ($files as $file) {
        $sql=(string)file_get_contents(dirname(__DIR__,2).'/migrations/'.$file);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
            $sql=preg_replace('/--[^\n]*/','',$sql);
            $sql=preg_replace('/ CHARACTER SET \w+ COLLATE \w+/','',$sql);
            $sql=preg_replace('/ENUM\([^)]*\)/','TEXT',$sql);
            $sql=str_replace(['BIGINT UNSIGNED NOT NULL AUTO_INCREMENT','BIGINT UNSIGNED','SMALLINT UNSIGNED','TINYINT UNSIGNED'],['INTEGER NOT NULL','INTEGER','INTEGER','INTEGER'],$sql);
            $sql=preg_replace('/UNIQUE KEY \w+ \(([^)]+)\)/','UNIQUE ($1)',$sql);
            $sql=preg_replace('/^\s*KEY \w+ \([^\n]+\),?\n/m','',$sql);
            $sql=str_replace(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4','',$sql);
            $sql=preg_replace('/,\s*\)/',"\n)",$sql);
            $sql=str_replace('INSERT IGNORE','INSERT OR IGNORE',$sql);
        }
        $pdo->exec($sql);
    }
}

final class SamplingFixtureReader
{
    public array $rows=[];
    public function add(int $id,string $group,array $options=[]): void
    {
        $source=$options['source']??'conversation';
        $this->rows[$source.':'.$id]=array_replace(['id'=>$id,'group'=>$group,'source'=>$source,
            'aliases'=>[hash('sha256','synthetic:'.$group)],'complete'=>false,'text'=>'Египет на двоих',
            'kind'=>'substantive','at'=>'2026-10-21T19:00:00Z','mode'=>'live','incomplete'=>false,'reconstructed'=>false],$options);
    }
    public function recent(string $source,int $limit): array { return $this->scan($source,0,$limit); }
    public function scan(string $source,int $after,int $limit): array
    {
        $ids=[];foreach ($this->rows as $r) if ($r['source']===$source && $r['id']>$after) $ids[]=$r['id'];
        sort($ids,SORT_NUMERIC);$selected=array_slice($ids,0,$limit);
        return ['source_ids'=>$selected,'last_id'=>$selected?end($selected):$after,'has_more'=>count($ids)>$limit];
    }
    public function snapshot(string $source,int $id): ?array
    {
        $r=$this->rows[$source.':'.$id]??null;if (!$r) return null;
        $messages=[['id'=>$id,'sender_type'=>'tourist','text'=>$r['text'],'created_at'=>$r['at']]];
        if (isset($r['first_at'])) array_unshift($messages,['id'=>$id+100000,'sender_type'=>'tourist','text'=>'Предыдущий запрос','created_at'=>$r['first_at']]);
        $payload=['messages'=>$messages,'history_incomplete'=>$r['incomplete'],
            'historical_context_reconstructed'=>$r['reconstructed'],'revised_question'=>false,
            'cutoff'=>['source_id'=>$id,'reference_time'=>$r['at']]];
        return ['project_key'=>'anytour','source_kind'=>$source,'source_id'=>$id,'source_scope'=>'synthetic:'.$r['group'],
            'source_revision'=>hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR)),
            'reference_time'=>$r['at'],'mode'=>$r['mode'],'payload'=>$payload,
            'evidence_message_ids'=>array_column($messages,'id'),
            'sampling'=>['identity_keys'=>$r['aliases'],'identity_complete'=>$r['complete'],'kind'=>$r['kind']]];
    }
}

function samplingResult(int $source): array
{
    return ['ok'=>true,'should_draft'=>true,'draft'=>'На какие даты планируете поездку?',
        'next_action'=>'clarify','reason'=>'Даты не названы.','checks'=>[],
        'evidence_message_ids'=>[$source],'history_incomplete'=>false];
}

function samplingAssertions(PDO $pdo,callable $check): void
{
    samplingResetFixture($pdo);
    $now=strtotime('2026-10-21T19:00:00Z');$reader=new SamplingFixtureReader();
    for ($i=1;$i<=130;$i++) $reader->add($i,'one-dialogue');
    $reader->add(130,'one-dialogue',['kind'=>'social','text'=>'Спасибо']);
    $reader->add(131,'other-dialogue');$reader->add(132,'third-dialogue');
    $store=new VirtualReplyStore($pdo,$reader,static function()use(&$now):int{return $now;});
    $store->capture('conversation','history',100);$store->capture('conversation','history',100);
    $claim=$store->claim('author',7,10);$sources=[];
    foreach($claim['jobs'] as $j){$c=$store->context($j['case_id'],'author',$j['claim_token'],7);$sources[]=$c['source']['id'];}
    sort($sources,SORT_NUMERIC);
    $check('more than100 neighbor prefixes cannot hide other groups and a substantive representative wins',
        $sources===[129,131,132] && $claim['daily_used']===3);
    $check('one group/day is enforced after the first allocation without consuming another quota slot',
        $store->claim('author',8,10)['jobs']===[] && $store->claim('author',8,10)['daily_used']===3);
    $now+=901;
    $check('unknown attempts remain terminal and consume their group/day slot',
        $store->claim('author',8,10)['jobs']===[]
        && (int)$pdo->query("SELECT COUNT(*) FROM virtual_reply_jobs WHERE role='author' AND status='unknown'")->fetchColumn()===3);
    $now=strtotime('2026-10-22T19:00:00Z');
    $next=$store->claim('author',8,1);
    $check('a later UTC day can sample a new untouched prefix without reopening an unknown attempt',
        count($next['jobs'])===1 && $next['daily_used']===1
        && (int)$pdo->query("SELECT COUNT(*) FROM virtual_reply_jobs WHERE role='author' AND status='unknown'")->fetchColumn()===3);

    samplingReservationAssertions($pdo,$check);
    samplingMergeAssertions($pdo,$check);
    samplingPriorityAssertions($pdo,$check);
    samplingQuotaAssertions($pdo,$check);
    samplingSocialAssertions($pdo,$check);
    samplingUnresolvedExposureAssertions($pdo,$check);
    samplingResetFixture($pdo);
}

/** Only private tables created by this fixture; never change the native schema. */
function samplingResetFixture(PDO $pdo): void
{
    foreach (['virtual_reply_sampling_claims','virtual_reply_case_sampling','virtual_reply_sampling_aliases',
        'virtual_reply_jobs','virtual_reply_cases','virtual_reply_sampling_groups','virtual_reply_quotas','virtual_reply_cursors'] as $table) $pdo->exec('DELETE FROM '.$table);
}

function samplingQuery(PDO $pdo,string $sql,array $args=[]): array
{
    $query=$pdo->prepare($sql);$query->execute($args);return $query->fetchAll(PDO::FETCH_ASSOC);
}

function samplingExecute(PDO $pdo,string $sql,array $args=[]): void
{
    $query=$pdo->prepare($sql);$query->execute($args);
}

function samplingFails(callable $operation,string $message): bool
{
    try {$operation();return false;} catch (RuntimeException $error) {return $error->getMessage()===$message;}
}

function samplingCaptureAll(VirtualReplyStore $store,string $source='conversation'): void
{
    do {$result=$store->capture($source,'history',100);} while ($result['has_more']);
}

function samplingCase(PDO $pdo,int $id,string $source='conversation'): array
{
    return samplingQuery($pdo,'SELECT c.*,s.group_key,g.cohort FROM virtual_reply_cases c JOIN virtual_reply_case_sampling s ON s.case_id=c.case_id LEFT JOIN virtual_reply_sampling_groups g ON g.group_key=s.group_key WHERE c.source_kind=? AND c.source_id=?',[$source,$id])[0];
}

/** Pick a deterministic synthetic identity that the one-in-five split reserves. */
function samplingReservedAlias(string $label): string
{
    for ($index=0;;$index++) {
        $alias=hash('sha256','synthetic-reserved:'.$label.':'.$index);
        if (hexdec(substr(hash('sha256','virtual_reply_sampling_group_v1|'.$alias),0,8))%5===0) return $alias;
    }
}

function samplingReservationAssertions(PDO $pdo,callable $check): void
{
    samplingResetFixture($pdo);$now=strtotime('2026-10-21T19:00:00Z');$reader=new SamplingFixtureReader();
    for ($i=1;$i<=80;$i++) $reader->add($i,'unseen-'.$i,['complete'=>true]);
    $store=new VirtualReplyStore($pdo,$reader,static function()use(&$now):int{return $now;});
    $capture=$store->capture('conversation','fresh',100);$reserved=(int)$capture['sampling']['groups']['reserved'];
    $check('new complete unseen groups actually enroll both reserved and development cohorts',
        $reserved>0 && $reserved<80 && array_sum($capture['sampling']['groups'])===80);
    $cases=samplingQuery($pdo,"SELECT c.case_id,c.source_id FROM virtual_reply_cases c JOIN virtual_reply_case_sampling s ON s.case_id=c.case_id JOIN virtual_reply_sampling_groups g ON g.group_key=s.group_key WHERE g.cohort='reserved' ORDER BY c.source_id");
    $claimed=[];
    do {$batch=$store->claim('author',7,20);foreach ($batch['jobs'] as $job) $claimed[]=$job['case_id'];} while ($batch['jobs']);
    $reservedIds=array_column($cases,'case_id');
    $check('ordinary author allocation never exposes or spends quota on reserved groups',
        count($claimed)===80-$reserved && array_intersect($claimed,$reservedIds)===[] && $batch['daily_used']===80-$reserved
        && (int)$pdo->query("SELECT COUNT(*) FROM virtual_reply_sampling_groups WHERE cohort='reserved' AND exposed_at IS NOT NULL")->fetchColumn()===0);
    $case=$cases[0];$caseId=$case['case_id'];$token=str_repeat('a',64);
    $private=new VirtualReplySampling($pdo,static fn():int=>$now);
    $check('reserved private context and review surfaces fail closed without an owned author claim',
        !$private->readable($caseId)
        && samplingFails(static fn()=>$store->context($caseId,'author',$token,7),'virtual_reply_claim_unavailable')
        && samplingFails(static fn()=>$store->save($caseId,'author',$token,7,samplingResult((int)$case['source_id'])),'virtual_reply_holdout_reserved')
        && samplingFails(static fn()=>$store->review($caseId),'virtual_reply_holdout_reserved')
        && samplingFails(static fn()=>$store->disposition($caseId,['status'=>'rejected','reason'=>'Synthetic check.','change_url'=>null,'validation'=>'Synthetic check.'],7),'virtual_reply_holdout_reserved'));
    $check('reserved prefixes remain unavailable to ordinary reviewer allocation and review metadata',
        $store->claim('reviewer',7,20)['jobs']===[] && $store->reviews(100)['reviews']===[]);

    samplingResetFixture($pdo);$reader=new SamplingFixtureReader();
    $reader->add(1,'enrollment-start');
    $store=new VirtualReplyStore($pdo,$reader,static fn():int=>$now);samplingCaptureAll($store);
    $options=[['first_at'=>'2026-10-20T19:00:00Z'],['incomplete'=>true],['mode'=>'historical_replay'],['reconstructed'=>true],['complete'=>false]];
    foreach ($options as $i=>$option) $reader->add($i+2,'ineligible-'.$i,array_replace(['complete'=>true,'aliases'=>[samplingReservedAlias('ineligible-'.$i)]],$option));
    samplingCaptureAll($store);
    $cohorts=[];foreach ($options as $i=>$option) $cohorts[]=samplingCase($pdo,$i+2)['cohort'];
    $check('old first messages, incomplete/reconstructed prefixes, historical replay and incomplete identities stay development',
        $cohorts===array_fill(0,count($options),'development'));

    $alias=samplingReservedAlias('later-old-prefix');$reader->add(20,'initial-new',['complete'=>true,'aliases'=>[$alias]]);samplingCaptureAll($store);
    $before=samplingCase($pdo,20)['cohort'];
    $reader->add(21,'old-prefix-found',['complete'=>true,'aliases'=>[$alias],'first_at'=>'2026-10-20T19:00:00Z']);samplingCaptureAll($store);
    $check('discovering an older prefix conservatively contaminates the whole previously reserved group',
        $before==='reserved' && samplingCase($pdo,20)['cohort']==='contaminated' && samplingCase($pdo,21)['cohort']==='contaminated');
}

function samplingMergeAssertions(PDO $pdo,callable $check): void
{
    foreach ([false,true] as $bothReserved) {
        samplingResetFixture($pdo);$now=strtotime('2026-10-21T19:00:00Z');$reader=new SamplingFixtureReader();
        $left=samplingReservedAlias('merge-left');$right=$bothReserved?samplingReservedAlias('merge-right'):hash('sha256','synthetic-development');
        $reader->add(1,'left',['complete'=>true,'aliases'=>[$left]]);
        $reader->add(2,'right',['complete'=>$bothReserved,'aliases'=>[$right]]);
        $store=new VirtualReplyStore($pdo,$reader,static fn():int=>$now);samplingCaptureAll($store);
        $before=[samplingCase($pdo,1)['cohort'],samplingCase($pdo,2)['cohort']];
        $reader->add(3,'joined-account',['source'=>'telegram_archive','complete'=>true,'aliases'=>[$left,$right]]);samplingCaptureAll($store,'telegram_archive');
        $rows=samplingQuery($pdo,'SELECT DISTINCT s.group_key,g.cohort FROM virtual_reply_case_sampling s JOIN virtual_reply_sampling_groups g ON g.group_key=s.group_key');
        $check($bothReserved?'merging independently reserved aliases contaminates the whole group':'cross-source reserved/development alias merging contaminates every prefix',
            $before===['reserved',$bothReserved?'reserved':'development'] && count($rows)===1 && $rows[0]['cohort']==='contaminated'
            && (int)$pdo->query('SELECT COUNT(*) FROM virtual_reply_sampling_aliases')->fetchColumn()===2);
    }
}

function samplingPriorityAssertions(PDO $pdo,callable $check): void
{
    samplingResetFixture($pdo);$now=strtotime('2026-10-21T19:00:00Z');$reader=new SamplingFixtureReader();
    for ($i=1;$i<=140;$i++) $reader->add($i,'old-'.$i,['at'=>'2026-09-01T19:00:00Z']);
    $reader->add(141,'fresh-social',['kind'=>'social','text'=>'Спасибо']);
    $reader->add(142,'same-dialogue',['at'=>'2026-09-01T19:00:00Z']);
    $reader->add(143,'same-dialogue',['kind'=>'social','text'=>'Здравствуйте']);
    $store=new VirtualReplyStore($pdo,$reader,static fn():int=>$now);samplingCaptureAll($store);
    $claim=$store->claim('author',7,2);$ids=[];
    foreach ($claim['jobs'] as $job) $ids[]=$store->context($job['case_id'],'author',$job['claim_token'],7)['source']['id'];
    sort($ids,SORT_NUMERIC);
    $check('fresh groups and fresh representatives precede more than100 historical substantive questions',
        $ids===[141,143] && $claim['daily_used']===2);
}

function samplingQuotaAssertions(PDO $pdo,callable $check): void
{
    samplingResetFixture($pdo);$now=strtotime('2026-10-21T19:00:00Z');$reader=new SamplingFixtureReader();
    for ($i=1;$i<=210;$i++) $reader->add($i,'quota-'.$i);
    $store=new VirtualReplyStore($pdo,$reader,static function()use(&$now):int{return $now;});samplingCaptureAll($store);
    $count=0;for ($i=0;$i<10;$i++) $count+=count($store->claim('author',7,20)['jobs']);
    $blocked=$store->claim('author',8,20);
    $check('the real shared author ledger stops allocation at exactly200 groups per UTC day',
        $count===200 && $blocked['jobs']===[] && $blocked['daily_used']===200 && $blocked['daily_limit']===200
        && (int)$pdo->query('SELECT COUNT(*) FROM virtual_reply_sampling_claims')->fetchColumn()===200
        && (int)$pdo->query("SELECT COUNT(*) FROM virtual_reply_jobs WHERE role='author' AND status='pending'")->fetchColumn()===10);
    $now+=901;$again=$store->claim('author',8,20);
    $check('expired unknown results do not release any of the200 daily quota or group slots',
        $again['jobs']===[] && $again['daily_used']===200
        && (int)$pdo->query("SELECT COUNT(*) FROM virtual_reply_jobs WHERE role='author' AND status='unknown'")->fetchColumn()===200
        && (int)$pdo->query('SELECT COUNT(*) FROM virtual_reply_sampling_claims')->fetchColumn()===200);
}

function samplingSocialAssertions(PDO $pdo,callable $check): void
{
    samplingResetFixture($pdo);$now=strtotime('2026-10-21T19:00:00Z');$reader=new SamplingFixtureReader();
    for ($i=1;$i<=30;$i++) {
        $reader->add($i,'social-'.$i,['kind'=>'social','text'=>'Спасибо']);
        $reader->add($i+30,'substantive-'.$i);
    }
    $store=new VirtualReplyStore($pdo,$reader,static fn():int=>$now);samplingCaptureAll($store);
    $claimed=[];do {$batch=$store->claim('author',7,20);foreach ($batch['jobs'] as $job) $claimed[]=$job['case_id'];} while ($batch['jobs']);
    $kinds=samplingQuery($pdo,"SELECT s.kind,COUNT(*) AS total FROM virtual_reply_jobs j JOIN virtual_reply_case_sampling s ON s.case_id=j.case_id WHERE j.role='author' AND j.status='running' GROUP BY s.kind");
    $counts=[];foreach ($kinds as $row) $counts[$row['kind']]=(int)$row['total'];
    $check('social traffic has a real20-group/day cap while substantive requests keep their budget',
        count($claimed)===50 && ($counts['social']??0)===20 && ($counts['substantive']??0)===30 && $batch['daily_used']===50
        && $batch['sampling']['social_groups_claimed_today']===20 && $batch['sampling']['social_daily_limit']===20);
}

function samplingUnresolvedExposureAssertions(PDO $pdo,callable $check): void
{
    samplingResetFixture($pdo);$now=strtotime('2026-10-21T19:00:00Z');$reader=new SamplingFixtureReader();
    $reader->add(1,'deleted-legacy');$store=new VirtualReplyStore($pdo,$reader,static fn():int=>$now);samplingCaptureAll($store);
    $case=samplingCase($pdo,1);
    samplingExecute($pdo,'UPDATE virtual_reply_case_sampling SET legacy=1,group_key=NULL WHERE case_id=?',[$case['case_id']]);
    unset($reader->rows['conversation:1']);$store->reconcile(100);
    $reader->add(2,'fresh-with-unresolved-exposure',['complete'=>true,'aliases'=>[samplingReservedAlias('unresolved-exposure')]]);samplingCaptureAll($store);
    $fresh=samplingCase($pdo,2);$claim=$store->claim('author',7,1);
    $check('unresolved invalidated legacy identities block new holdout enrollment but do not block ordinary fresh learning',
        $fresh['cohort']==='development' && count($claim['jobs'])===1 && $claim['jobs'][0]['case_id']===$fresh['case_id']
        && ($claim['sampling']['legacy_unresolved_exposure']??0)===1
        && ($claim['sampling']['reservation_blocked_reason']??null)==='legacy_unresolved_exposure'
        && $claim['sampling']['author_blocked_reason']===null && $claim['daily_used']===1);
}

/** Compatibility and schema failure checks need their own databases, never reset native DDL. */
function samplingSqliteCompatibilityAssertions(callable $check): void
{
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA foreign_keys=ON');samplingSchema($db,false);
    $now=strtotime('2026-10-21T19:00:00Z');$reader=new SamplingFixtureReader();
    for ($i=1;$i<=3;$i++) $reader->add($i,'legacy-'.$i,['complete'=>true]);
    $store=new VirtualReplyStore($db,$reader,static function()use(&$now):int{return $now;});
    $capture=$store->capture('conversation','history',10);$jobs=$store->claim('author',7,2)['jobs'];
    $store->save($jobs[0]['case_id'],'author',$jobs[0]['claim_token'],7,samplingResult(1),['model'=>'gpt-6.1-sol','reasoning_effort'=>'low','service_tier'=>'unknown']);
    $before=samplingQuery($db,'SELECT * FROM virtual_reply_jobs ORDER BY case_id,role');
    $quotas=samplingQuery($db,'SELECT * FROM virtual_reply_quotas ORDER BY day_utc,role');
    $check('a wholly absent051 schema preserves compatible virtual capture and author operation',
        $capture['sampling']['status']==='migration_unavailable' && count($jobs)===2 && (int)$quotas[0]['used_count']===2);
    samplingSchema($db);$afterMigration=samplingQuery($db,'SELECT * FROM virtual_reply_jobs ORDER BY case_id,role');
    $store=new VirtualReplyStore($db,$reader,static function()use(&$now):int{return $now;});$status=$store->capture('conversation','history',10)['sampling'];
    $afterCapture=samplingQuery($db,'SELECT * FROM virtual_reply_jobs ORDER BY case_id,role');
    $check('forward051 metadata backfill preserves exact active/completed contracts, results, tokens and quota ledger',
        $before===$afterMigration && $before===$afterCapture && $quotas===samplingQuery($db,'SELECT * FROM virtual_reply_quotas ORDER BY day_utc,role')
        && $status['groups']['reserved']===0);
    $next=$store->claim('author',8,10);$claims=samplingQuery($db,'SELECT * FROM virtual_reply_sampling_claims ORDER BY group_key,day_utc');
    $check('legacy groups remain development and imported claimed days cannot be allocated twice',
        count($next['jobs'])===1 && $next['daily_used']===3 && count($claims)===3
        && (int)$db->query("SELECT COUNT(*) FROM virtual_reply_sampling_groups WHERE cohort='development'")->fetchColumn()===3
        && (int)$db->query("SELECT COUNT(*) FROM virtual_reply_jobs WHERE role='author' AND status='completed'")->fetchColumn()===1
        && (int)$db->query("SELECT COUNT(*) FROM virtual_reply_jobs WHERE role='author' AND status='running'")->fetchColumn()===2);
    $partial=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);samplingSchema($partial,false);
    $partial->exec('CREATE TABLE virtual_reply_sampling_groups (group_key TEXT PRIMARY KEY)');
    $check('a partially installed051 fails closed rather than silently reverting to unsampled allocation',
        samplingFails(static fn()=>new VirtualReplyStore($partial,$reader,static fn():int=>$now),'virtual_reply_sampling_unavailable'));
}
