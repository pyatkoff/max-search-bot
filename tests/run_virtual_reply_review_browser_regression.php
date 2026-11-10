<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/fixtures/virtual_reply_sampling.php';
require_once dirname(__DIR__).'/services/VirtualReplyReviewBrowser.php';
$passed=0;
function journalCheck(string $name,bool $ok): void {global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
function journalReject(callable $call): bool {try{$call();return false;}catch(InvalidArgumentException $e){return true;}}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
samplingSchema($pdo);$reader=new SamplingFixtureReader();$now=strtotime('2026-10-21T19:00:00Z');
for($i=1;$i<=47;$i++)$reader->add($i,'journal-'.$i);
$store=new VirtualReplyStore($pdo,$reader,static function()use(&$now):int{return $now;});
$store->capture('conversation','history',100);
$metadata=static function(string $role):array {$p=VirtualReplyProtocol::profile($role);return ['model'=>$p['model'],'reasoning_effort'=>$p['reasoning']['effort'],'service_tier'=>'unknown'];};
while($jobs=$store->claim('author',7,20)['jobs'])foreach($jobs as $j){$ctx=$store->context($j['case_id'],'author',$j['claim_token'],7);$store->save($j['case_id'],'author',$j['claim_token'],7,samplingResult($ctx['evidence_message_ids'][0]),$metadata('author'));}
$now+=61;
while($jobs=$store->claim('reviewer',7,20)['jobs'])foreach($jobs as $j){
    $ctx=$store->context($j['case_id'],'reviewer',$j['claim_token'],7);$id=$ctx['evidence_message_ids'][0];
    $review=['ok'=>true,'verdict'=>'pass','issues'=>[],'corrected_draft'=>null,'lesson_candidate'=>null];
    if($id%3===0)$review=['ok'=>true,'verdict'=>'revise','issues'=>[['category'=>'question','severity'=>'minor','explanation'=>'Синтетическое замечание к вопросу.','evidence_message_ids'=>[$id]]],'corrected_draft'=>'Какой бюджет?','lesson_candidate'=>null];
    $store->save($j['case_id'],'reviewer',$j['claim_token'],7,$review,$metadata('reviewer'));
}
$quota=$pdo->query('SELECT * FROM virtual_reply_quotas ORDER BY role')->fetchAll();
$jobCount=(int)$pdo->query('SELECT COUNT(*) FROM virtual_reply_jobs')->fetchColumn();
$all=[];$cursor=null;$sizes=[];
do{$page=$store->reviewPage($cursor);$sizes[]=count($page['reviews']);$all=array_merge($all,$page['reviews']);$cursor=$page['next_cursor'];}while($cursor!==null);
journalCheck('all completed reviews are reachable past twenty with stable timestamp ties',count($all)===47&&count(array_unique(array_column($all,'case_id')))===47&&$sizes===[20,20,7]);
journalCheck('page order is stable descending case identity within a timestamp',array_column($all,'number')===range(47,1));
$first=$store->reviewPage();$next=$store->reviewPage($first['next_cursor']);
journalCheck('repeating a readonly page does not consume or move its cursor',$next===$store->reviewPage($first['next_cursor']));
$filtered=$store->reviewPage(null,'revise');journalCheck('verdict filtering is applied before page limit',count($filtered['reviews'])===15&&!$filtered['has_more']&&count(array_unique(array_column($filtered['reviews'],'verdict')))===1&&$filtered['reviews'][0]['verdict']==='revise');
foreach(['','!','W10',str_repeat('a',257),rtrim(strtr(base64_encode('{"at":"2026-99-99 10:00:00","id":2}'),'+/','-_'),'='),rtrim(strtr(base64_encode('{"at":"2026-10-21 19:00:00","id":2,"extra":1}'),'+/','-_'),'=')] as $bad)journalCheck('malformed cursor rejected',journalReject(fn()=>$store->reviewPage($bad)));
journalCheck('invalid verdict rejected',journalReject(fn()=>$store->reviewPage(null,'arbitrary')));
foreach(range(28,47) as $i)$reader->rows['conversation:'.$i]['snapshot_allowed']=false;
$revoked=$store->reviewPage();journalCheck('revoked entire page is empty but retains forward progress',$revoked['reviews']===[]&&$revoked['has_more']&&is_string($revoked['next_cursor']));
$later=$store->reviewPage($revoked['next_cursor']);journalCheck('paging proceeds beyond revoked rows',count($later['reviews'])===20&&$later['reviews'][0]['number']===27);
$reader->rows['conversation:27']['text']='Changed synthetic question';
$changed=$store->reviewPage($revoked['next_cursor']);journalCheck('changed source is purged rather than showing old reply',count($changed['reviews'])===19&&!in_array(27,array_column($changed['reviews'],'number'),true));
$pdo->exec("UPDATE virtual_reply_sampling_groups SET cohort='reserved' WHERE group_key IN (SELECT group_key FROM virtual_reply_case_sampling WHERE case_id='".$all[21]['case_id']."')");
$hidden=$store->reviewPage($revoked['next_cursor']);journalCheck('reserved material is excluded from ordinary journal',!in_array($all[21]['case_id'],array_column($hidden['reviews'],'case_id'),true));
journalCheck('journal never creates claims or refunds consumed quota',$jobCount===(int)$pdo->query('SELECT COUNT(*) FROM virtual_reply_jobs')->fetchColumn()&&$quota===$pdo->query('SELECT * FROM virtual_reply_quotas ORDER BY role')->fetchAll());

final class JournalSpy {
    public int $calls=0;
    public function reviewPage($cursor,$filter):array {$this->calls++;return ['reviews'=>[],'has_more'=>false,'next_cursor'=>null];}
    public function review($id):array {$this->calls++;return ['case_id'=>$id,'payload'=>['messages'=>[]],'author_result'=>['draft'=>null],'review_result'=>['verdict'=>'pass'],'disposition'=>['status'=>'unresolved'],'contracts'=>['secret'=>'CONTRACT_CANARY'],'claim_token'=>'TOKEN_CANARY','source'=>['id'=>'SOURCE_CANARY'],'author_metadata'=>['private'=>'META_CANARY']];}
}
$spy=new JournalSpy();$browser=new VirtualReplyReviewBrowser($spy);$actor=['id'=>7,'role'=>'admin','is_active'=>1,'projects'=>[['project_key'=>'anytour']]];
foreach([[],array_replace($actor,['role'=>'manager']),array_replace($actor,['is_active'=>0]),array_replace($actor,['projects'=>[['project_key'=>'other']]])] as $bad)journalCheck('unauthorized actor cannot invoke store',$browser->read($bad,[])===['ok'=>false,'error'=>'forbidden']&&$spy->calls===0);
journalCheck('active assigned admin can read bounded list',$browser->read($actor,[])['ok']===true&&$spy->calls===1);
$detail=$browser->read($actor,['case_id'=>str_repeat('a',64)]);$json=json_encode($detail);
journalCheck('detail omits contracts tokens source identity and worker metadata',!str_contains($json,'CANARY')&&array_keys($detail)===['ok','case_id','payload','author_result','review_result','disposition']);
foreach([['case_id'=>'bad'],['case_id'=>str_repeat('a',64),'cursor'=>'bad'],['cursor'=>[]],['verdict'=>[]],['purpose'=>'control'],['limit'=>10000]] as $bad)journalCheck('invalid and control requests rejected',journalReject(fn()=>$browser->read($actor,$bad)));
require_once __DIR__.'/fixtures/virtual_reply_review_endpoint.php';
journalEndpointChecks('journalCheck');
echo "PRIVATE REVIEW BROWSER: $passed checks PASSED\n";
