<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ManagerAiUsageService.php';
$passed=0;
function usageCheck(string $name,bool $ok): void{global$passed;if(!$ok)throw new RuntimeException('FAIL '.$name);++$passed;echo 'PASS '.$name."\n";}
require_once __DIR__.'/support/manager-ai-usage-fixture.php';
final class AiUsageReadStatement extends PDOStatement
{
    public static int $selects=0;
    public function execute(?array $params=null): bool
    {
        if(preg_match('/^\s*SELECT\b/i',$this->queryString))++self::$selects;
        return parent::execute($params);
    }
}
$pdo=ManagerAiUsageFixture::create();
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[AiUsageReadStatement::class]);
$now=new DateTimeImmutable('2026-10-25 12:00:00',new DateTimeZone('UTC'));
$business=static fn()=>[$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM manager_ai_drafts ORDER BY draft_id')->fetchAll(),$pdo->query('SELECT * FROM conversations ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM managers ORDER BY id')->fetchAll()];$beforeBusiness=$business();
$read=static fn(array $input=[],int $actor=1)=>ManagerAiUsageService::read($actor,$input,$now);
$r=$read();usageCheck('owner receives bounded real uses beyond old10sample cap',$r['ok']&&count($r['items'])===20&&$r['has_more']);
// Warm initialization once, then compare actual SELECT executions for1and20cases.
AiUsageReadStatement::$selects=0;$single=$read([],2);$singleReads=AiUsageReadStatement::$selects;
AiUsageReadStatement::$selects=0;$r=$read();$pageReads=AiUsageReadStatement::$selects;
$baselineClass=null;$baselinePage=null;$baselineReads=null;
$baselinePath=getenv('MANAGER_AI_USAGE_BASELINE_FILE');
if(is_string($baselinePath)&&$baselinePath!==''){
    $source=(string)file_get_contents($baselinePath);
    if(hash('sha256',$source)!=='ac57b3b4477697dd82a937f63e241b0f0494a1f3c1a40a775f67fae99bd1ebf4')throw new RuntimeException('baseline_identity_mismatch');
    $source=preg_replace('/^require_once .*;\s*$/m','',$source);
    $source=str_replace('final class ManagerAiUsageService','final class ManagerAiUsageBeforeQuestionBatch',$source,$replacements);
    if($replacements!==1)throw new RuntimeException('baseline_class_mismatch');
    eval(substr($source,5));$baselineClass=ManagerAiUsageBeforeQuestionBatch::class;
    AiUsageReadStatement::$selects=0;$baselinePage=$baselineClass::read(1,[],$now);$baselineReads=AiUsageReadStatement::$selects;
}
$ids=[];$first=$r;$pages=0;
do{usageCheck('each page retains current owner and project scope',$r['ok']&&$r['viewer_id']===1&&count($r['items'])<=20&&count(array_filter($r['items'],static fn($x)=>$x['project_key']!=='anytour'))===0);
    usageCheck('each selected case keeps its own source question',count(array_filter($r['items'],static fn($x)=>$x['question_text']!=='Question '.($x['message_id']-10000)))===0);
    if($baselinePage!==null)usageCheck('complete page equals the exact installed source',$r===$baselinePage);
    array_push($ids,...array_column($r['items'],'message_id'));if(!$r['has_more'])break;
    if($baselineClass!==null)$baselinePage=$baselineClass::read(1,['cursor'=>$r['next_cursor']],$now);
    $r=$read(['cursor'=>$r['next_cursor']]);if(++$pages>10)throw new RuntimeException('pagination_loop');}while(true);
usageCheck('all47valid applications traverse exactly once without foreign test malformed or internal rows',count($ids)===47&&count(array_unique($ids))===47&&$ids===range(10047,10001));
$case=$first['items'][0];usageCheck('historical author is separate from current assignee',$case['manager_id']===7&&$case['manager_name']==='Historical author'&&$case['display_name']==='Working name');
usageCheck('source question proposed text reason and real sent text are available',$case['question_text']==='Question 47'&&$case['proposed_text']==='AI proposal 47'&&$case['current_sent_text']==='AI proposal 47'&&$case['reason']==='Synthetic reason'&&$case['initial_text_available']);
usageCheck('prepared and generated sources are distinguished',count(array_filter($first['items'],static fn($x)=>$x['source']==='prepared'))>0&&count(array_filter($first['items'],static fn($x)=>$x['source']==='generated'))>0);
usageCheck('read does not create review marks or change business records',(int)$pdo->query('SELECT COUNT(*) FROM manager_ai_usage_reviews')->fetchColumn()===0&&$beforeBusiness===$business());
usageCheck('ordinary inactive and unknown accounts cannot read owner report',$read([],3)['error']==='forbidden'&&$read([],4)['error']==='forbidden'&&$read([],999)['error']==='forbidden');
$other=$read([],2);usageCheck('another owner receives only their current project',$other['ok']&&array_column($other['items'],'message_id')===[10051]);
usageCheck('project filters intersect rather than expand permissions',$read(['project_key'=>'other'])['items']===[]);
$changed=$read(['changed_only'=>true]);usageCheck('changed filter reflects recorded initial send rather than a quality score',$changed['ok']&&count($changed['items'])===20&&count(array_filter($changed['items'],static fn($x)=>$x['recorded_text_matches_proposal']))===0);
usageCheck('cursor is bound to viewer filters scope and expiry',$read(['cursor'=>$first['next_cursor']],2)['error']==='stale_cursor'&&$read(['cursor'=>$first['next_cursor'],'mode'=>'all'])['error']==='stale_cursor'&&$read(['cursor'=>'v1.invalid'])['error']==='stale_cursor'&&ManagerAiUsageService::read(1,['cursor'=>$first['next_cursor']],$now->modify('+2 hours'))['error']==='stale_cursor');
$ack=ManagerAiUsageService::review(1,$case['message_id'],$case['case_revision'],$now);
usageCheck('explicit review records only exact per-owner revision',$ack['ok']&&(int)$pdo->query('SELECT COUNT(*) FROM manager_ai_usage_reviews')->fetchColumn()===1&&$beforeBusiness===$business());
$reviewRows=$pdo->query('SELECT * FROM manager_ai_usage_reviews')->fetchAll();ManagerAiUsageService::review(1,$case['message_id'],$case['case_revision'],$now->modify('+10 seconds'));
usageCheck('repeating a confirmed review is idempotent and preserves first timestamp',$reviewRows===$pdo->query('SELECT * FROM manager_ai_usage_reviews')->fetchAll());
usageCheck('reviewed revision disappears from new and remains in all',!in_array($case['message_id'],array_column($read()['items'],'message_id'),true)&&$read(['mode'=>'all'])['items'][0]['reviewed']);
$pdo->exec('INSERT INTO manager_projects VALUES(2,1)');usageCheck('review marker is personal to its owner',in_array($case['message_id'],array_column($read([],2)['items'],'message_id'),true));
$q=$pdo->prepare('UPDATE messages SET text=?,metadata_json=? WHERE id=?');$meta=json_decode($pdo->query('SELECT metadata_json FROM messages WHERE id=10047')->fetchColumn(),true);$meta['edited_at']='2026-10-25 10:05:00';$q->execute(['Later manager edit',json_encode($meta),10047]);
$updated=$read()['items'][0];usageCheck('later message edit reappears as an updated comparison without inventing initial sent text',$updated['message_id']===10047&&$updated['updated_since_review']&&!$updated['reviewed']&&!$updated['initial_text_available']&&$updated['edited']&&$updated['current_sent_text']==='Later manager edit');
usageCheck('changed filter also retains later edits without rewriting original-send equality',$read(['changed_only'=>true])['items'][0]['message_id']===10047&&$updated['recorded_text_matches_proposal']);
usageCheck('late old-tab review cannot mark a newer comparison viewed',ManagerAiUsageService::review(1,10047,$case['case_revision'],$now)['error']==='case_changed'&&!$read()['items'][0]['reviewed']);
usageCheck('new current revision can be explicitly reviewed',ManagerAiUsageService::review(1,10047,$updated['case_revision'],$now)['ok']);
usageCheck('invalid references and inaccessible messages cannot be reviewed',ManagerAiUsageService::review(1,10055,str_repeat('a',64),$now)['error']==='not_found'&&ManagerAiUsageService::review(1,10051,str_repeat('a',64),$now)['error']==='forbidden'&&ManagerAiUsageService::review(3,10046,str_repeat('a',64),$now)['error']==='forbidden');
$pdo->exec('DELETE FROM manager_projects WHERE manager_id=1');usageCheck('fresh project revoke blocks reads review and saved cursor',$read()['error']==='forbidden'&&ManagerAiUsageService::review(1,10046,str_repeat('a',64),$now)['error']==='forbidden'&&$read(['cursor'=>$first['next_cursor']])['error']==='forbidden');$pdo->exec('INSERT INTO manager_projects VALUES(1,1)');
$pdo->beginTransaction();usageCheck('borrowed transaction is not consumed',$read()['error']==='unavailable'&&$pdo->inTransaction());$pdo->rollBack();
// Edited source text is labelled as current, never as a frozen original question.
$pdo->exec("UPDATE messages SET text='Later question',metadata_json='{".'"edited_at":"2026-10-25 10:06:00"'."}' WHERE id=1046");
$sourceCase=$read()['items'][0];usageCheck('question edited after generation is explicitly labelled',$sourceCase['message_id']===10046&&$sourceCase['question_edited']&&$sourceCase['question_text']==='Later question');
// Several applications in one dialogue need distinct historical cutoffs.
$pdo->exec('UPDATE messages SET conversation_id=146 WHERE id IN (1045,10045)');
$pdo->exec("UPDATE manager_ai_drafts SET conversation_id=146 WHERE draft_id='".str_pad(dechex(45),32,'0',STR_PAD_LEFT)."'");
$pdo->exec("UPDATE manager_ai_drafts SET latest_message_id=1100 WHERE draft_id='".str_pad(dechex(46),32,'0',STR_PAD_LEFT)."'");
$insertSource=$pdo->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?,?)');
foreach([[1097,'inbound','customer','Newer source'],[1098,'outbound','manager','Not a customer question'],[1099,'inbound','ai','Not a customer question'],[1100,'outbound','customer','Wrong direction'],[1101,'inbound','customer','After the draft cutoff']] as [$id,$direction,$sender,$text])$insertSource->execute([$id,146,$direction,$sender,'','telegram',$text,null,'2026-10-25 09:59:30']);
$pdo->exec('DELETE FROM messages WHERE id=1044');
$sourceUpdate=$pdo->prepare('UPDATE messages SET text=? WHERE id=?');
$sourceUpdate->execute([str_repeat('Ж',8000).'😀',1043]);
$literal='Один & <два> literal &lt;tag&gt;';$sourceUpdate->execute([htmlspecialchars($literal,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),1042]);
$edgePage=$read();$edge=array_column($edgePage['items'],null,'message_id');
usageCheck('shared dialogue retains a separate question at each draft cutoff',$edge[10045]['question_text']==='Question 45'&&$edge[10046]['question_text']==='Newer source');
usageCheck('missing source question retains explicit null fields',$edge[10044]['question_text']===null&&!$edge[10044]['question_edited']&&!$edge[10044]['question_truncated']);
usageCheck('Unicode question bound counts characters and marks truncation',$edge[10043]['question_text']===str_repeat('Ж',8000)&&$edge[10043]['question_truncated']);
usageCheck('HTML source is decoded once without interpreting literal entities',$edge[10042]['question_text']===$literal);
if($baselineClass!==null)usageCheck('shared cutoffs missing edited and bounded text equal installed output',$edgePage===$baselineClass::read(1,[],$now));
$pdo->exec("UPDATE manager_ai_drafts SET latest_message_id=1100,created_at='2026-10-25 09:59:00' WHERE draft_id='".str_pad(dechex(45),32,'0',STR_PAD_LEFT)."'");
$pdo->exec("UPDATE messages SET created_at='2026-10-25 09:58:30',metadata_json='".'{"edited_at":"2026-10-25 09:59:45"}'."' WHERE id=1097");
$editPage=$read();$editCases=array_column($editPage['items'],null,'message_id');
usageCheck('the same source edit is labelled against each proposal generation time',$editCases[10045]['question_text']==='Newer source'&&$editCases[10046]['question_text']==='Newer source'&&$editCases[10045]['question_edited']&&!$editCases[10046]['question_edited']);
if($baselineClass!==null)usageCheck('shared question edit labels equal exact installed output',$editPage===$baselineClass::read(1,[],$now));
// Cutoffs retain integer identity beyond a floating-point exact-number boundary.
$largeCutoff=9007199254740993;
foreach([[$largeCutoff-1,'Before large cutoff'],[$largeCutoff,'Exact large cutoff'],[$largeCutoff+1,'Future large cutoff']] as [$id,$text])$insertSource->execute([$id,146,'inbound','customer','','telegram',$text,null,'2026-10-25 09:58:30']);
$largeUpdate=$pdo->prepare('UPDATE manager_ai_drafts SET latest_message_id=? WHERE draft_id=?');$largeUpdate->execute([$largeCutoff,str_pad(dechex(45),32,'0',STR_PAD_LEFT)]);
$largePage=$read();$largeCases=array_column($largePage['items'],null,'message_id');
usageCheck('integer cutoff neither rounds into future history nor crosses another use',$largeCases[10045]['question_text']==='Exact large cutoff'&&$largeCases[10046]['question_text']==='Newer source');
if($baselineClass!==null)usageCheck('large integer cutoffs equal exact installed output',$largePage===$baselineClass::read(1,[],$now));
// A page of unusable provenance cannot hide lower valid sends or loop forever.
$pdo->beginTransaction();$insert=$pdo->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?,?)');for($i=0;$i<1005;$i++)$insert->execute([20000+$i,101,'outbound','manager','7','telegram','Synthetic','{"ai_draft":{"draft_id":"bad"}}','2026-10-25 11:00:00']);$pdo->commit();
$limited=$read();usageCheck('scan limit is explicit and keyset cursor advances over unusable records',$limited['ok']&&$limited['items']===[]&&$limited['scan_limited']&&$limited['has_more']&&is_string($limited['next_cursor']));
$afterLimit=$read(['cursor'=>$limited['next_cursor']]);usageCheck('continuation reaches lower valid applications after unusable page',$afterLimit['ok']&&count($afterLimit['items'])===20&&$afterLimit['items'][0]['message_id']===10046);
$pdo->exec('DROP TABLE manager_ai_usage_reviews');usageCheck('missing migration stays unavailable and is never repaired at runtime',$read()['error']==='unavailable');
echo 'READ_FIXTURE '.json_encode(['driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),'page_cases'=>count($first['items']),'one_case_selects'=>$singleReads,'page_selects'=>$pageReads,'installed_page_selects'=>$baselineReads,'production_latency'=>null])."\n";
usageCheck('twenty cases need no per-case SELECT growth over one case',$single['ok']&&count($single['items'])===1&&$pageReads<=$singleReads+1);
if($baselineReads!==null)usageCheck('paired page removes at least nineteen SELECT round trips',$baselineReads-$pageReads>=19);
echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
