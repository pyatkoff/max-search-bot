<?php
declare(strict_types=1);
require_once __DIR__.'/../tools/ModeledConversionCandidateSelection.php';
use ModeledConversionCandidateSelection as S;
$checks=0;
function ok(string $name,bool $v): void { global $checks;if (!$v) throw new RuntimeException($name);$checks++;echo "PASS $name\n"; }
function row(string $person='person:1',string $trip='trip:2',int $count=3): array {
    return ['subject'=>['project_key'=>'anytour','platform'=>'max','customer_key'=>$person,'conversation_id'=>'conversation:7','episode_key'=>$trip],
        'anchor_id'=>'message:10','context_from'=>'2026-10-20T09:00:00Z','last_activity_at'=>'2026-10-26T09:00:00Z',
        'identity_status'=>'bound','trip_status'=>'accepted','access_status'=>'allowed','source_message_count'=>$count];
}
function ledger(array $rows,string $person='unseen'): array {
    $l=['episodes'=>[],'people'=>[]];foreach ($rows as $r) {
        $l['episodes'][S::episodeKey($r['subject'])]='unseen';$l['people'][S::groupKey($r['subject'])]=$person;
    }return $l;
}
function plan(array $rows,?array $l=null,array $excluded=[]): array {
    return S::plan($rows,$l??ledger($rows),$excluded,'2026-10-19T12:00:00Z','2026-10-26T12:00:00Z');
}
function rejects(string $name,callable $f,string $reason): void {
    try {$f();}catch(InvalidArgumentException $e){ok($name,$e->getMessage()===$reason);return;}
    throw new RuntimeException($name);
}

// Reproduce the harmful registration/assignment filter on synthetic SQL only.
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE conversations(id INTEGER,customer_id INTEGER,project_key TEXT,channel TEXT,is_test INTEGER,started_at TEXT,manager_id INTEGER)');
$pdo->exec('CREATE TABLE messages(conversation_id INTEGER,direction TEXT,sender_type TEXT,text TEXT,created_at TEXT)');
$pdo->exec("INSERT INTO conversations VALUES(10,100,'anytour','max',0,'2026-01-01 00:00:00',NULL),(11,101,'anytour','max',0,'2026-02-01 00:00:00',NULL),(12,102,'anytour','max',1,'2026-01-01 00:00:00',NULL),(13,103,'pegas','max',0,'2026-01-01 00:00:00',NULL),(2,104,'anytour','max',0,'2026-01-01 00:00:00',NULL)");
foreach ([10,11,12,13,2] as $id) {
    $q=$pdo->prepare("INSERT INTO messages VALUES(?,'inbound','customer','В Египет','2026-10-26 09:00:00')");$q->execute([$id]);
}
$old=$pdo->query("SELECT id FROM conversations WHERE started_at>'2026-10-25 00:00:00' AND manager_id IS NOT NULL")->fetchAll();
ok('original new-person/assigned-manager mechanism produces empty cohort',$old===[]);
$query=S::candidateQuery('2026-10-19 12:00:00','2026-10-26 12:00:00');
$q=$pdo->prepare($query['sql']);$q->execute($query['bindings']);$before=$q->fetchAll(PDO::FETCH_ASSOC);
ok('old conversations with fresh activity and no manager now qualify',array_column($before,'conversation_id')===[11,10]);
ok('owner tests and foreign project stay outside candidate scope',count($before)===2);
$pdo->exec("INSERT INTO messages VALUES(10,'inbound','customer','future','2026-10-27 09:00:00')");
$q->execute($query['bindings']);ok('future message cannot reorder or enter metadata cutoff',$q->fetchAll(PDO::FETCH_ASSOC)===$before);
$pdo->exec('DELETE FROM messages');
for ($i=20;$i<125;$i++) {
    $pdo->exec("INSERT INTO conversations VALUES($i,$i,'anytour','max',0,'2020-01-01',NULL)");
    $pdo->exec("INSERT INTO messages VALUES($i,'inbound','customer','text','2026-10-26 09:00:00')");
}
$q->execute($query['bindings']);ok('metadata query is bounded at100',count($q->fetchAll())===100);

$a=row();$p=plan([$a]);ok('no budget or manager is required for accepted episode',count($p['selected'])===1);
$l=ledger([$a],'reviewed');$p=plan([$a],$l);
ok('new trip of reviewed person remains development candidate',count($p['selected'])===1&&!$p['selected'][0]['unseen_person_candidate']);
$p=plan([$a],$l,[S::groupKey($a['subject'])]);ok('explicit old-person exclusion overrides new trip',$p['reason_counts']===['explicit_person_exclusion'=>1]);
$l=ledger([$a]);$l['episodes'][S::episodeKey($a['subject'])]='reviewed';
ok('exact reviewed episode stays excluded',plan([$a],$l)['reason_counts']===['episode_already_reviewed'=>1]);
ok('missing ledger is unknown not unseen',plan([$a],['episodes'=>[],'people'=>[]])['reason_counts']===['episode_exposure_unknown'=>1]);
$l=ledger([$a]);$l['people']=[];$p=plan([$a],$l);
ok('explicitly unseen episode with unknown person is never untouched-person proof',count($p['selected'])===1&&!$p['selected'][0]['unseen_person_candidate']);
foreach (['identity_status'=>['unknown','identity_unresolved'],'trip_status'=>['unknown','trip_boundary_unresolved'],'access_status'=>['denied','access_not_established']] as $field=>[$value,$reason]) {
    $r=$a;$r[$field]=$value;ok($reason,plan([$r])['reason_counts']===[$reason=>1]);
}
$r=$a;$r['last_activity_at']='2026-10-27T09:00:00Z';ok('future metadata episode excluded',plan([$r])['reason_counts']===['after_cutoff'=>1]);
$r=$a;$r['context_from']='2026-01-01T00:00:00Z';$r['last_activity_at']='2026-10-01T00:00:00Z';
ok('old activity is outside window',plan([$r])['reason_counts']===['outside_window'=>1]);
ok('exact repeated metadata does not multiply read budget',plan([$a,$a])['planned_source_message_rows']===3);
$r=$a;$r['subject']=array_reverse($r['subject'],true);ok('subject field order cannot supply a new identity',plan([$a,$r])['unique_episodes']===1);
$r=$a;$r['source_message_count']=4;rejects('conflicting episode fails atomically',fn()=>plan([$a,$r]),'conflicting_episode');
$rows=[];for($i=1;$i<=6;$i++)$rows[]=row('person:'.$i,'trip:'.$i,50);
$p=plan($rows);ok('all message row budget is bounded at5episodes250rows',$p['planned_episode_reads']===5&&$p['planned_source_message_rows']===250&&$p['reason_counts']['episode_cap']===1);
ok('selection order is stable',plan(array_reverse($rows))===$p);
$r=row(count:51);ok('overlimit episode is rejected not silently truncated',plan([$r])['reason_counts']===['episode_message_cap'=>1]);
$r=row(count:0);ok('no message rows means no semantic review plan',plan([$r])['reason_counts']===['no_source_message_rows'=>1]);
$r=$a;$r['subject']['platform']='telegram';ok('linked platforms share person group not episode',S::groupKey($r['subject'])===S::groupKey($a['subject'])&&S::episodeKey($r['subject'])!==S::episodeKey($a['subject']));
$r=$a;$r['subject']['project_key']='pegas';ok('brands/projects cannot silently merge person groups',S::groupKey($r['subject'])!==S::groupKey($a['subject']));
rejects('metadata cap validated before planning',fn()=>plan(array_fill(0,101,$a)),'candidate_limit_or_interval');
$r=$a;$r['private']='PRIVATE SENTINEL';rejects('unknown source fields fail with fixed reason',fn()=>plan([$r]),'invalid_fields');
$r=$a;$r['last_activity_at']='2026-02-31T00:00:00Z';rejects('invalid clock rejected before exclusion',fn()=>plan([$r]),'invalid_time');
ok('a plan is never a capture or export authorization',$p['actual_source_operations']===0&&$p['actual_message_rows_read']===0&&!$p['capture_authorized']&&!$p['export_allowed']);
echo "CONVERSION CANDIDATE SELECTION: $checks PASS\n";
