<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ManagerAiUsageService.php';
$passed=0;
function usageCheck(string $name,bool $ok): void{global$passed;if(!$ok)throw new RuntimeException('FAIL '.$name);++$passed;echo 'PASS '.$name."\n";}
/** Keep every old output field exact; only the intentional new observation is additive. */
function usageHistoricalOutput(array $result): array{foreach($result['items']??[] as $i=>$unused)unset($result['items'][$i]['base_instruction_current']);return$result;}
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
    usageCheck('current common rules are observed from the validated stored hash without changing the page',count(array_filter($r['items'],static fn($x)=>!is_bool($x['base_instruction_current']??null)||$x['base_instruction_current']!==($x['message_id']%2===1)))===0);
    if($baselinePage!==null)usageCheck('all prior page fields equal the exact installed source',usageHistoricalOutput($r)===$baselinePage);
    array_push($ids,...array_column($r['items'],'message_id'));if(!$r['has_more'])break;
    if($baselineClass!==null)$baselinePage=$baselineClass::read(1,['cursor'=>$r['next_cursor']],$now);
    $r=$read(['cursor'=>$r['next_cursor']]);if(++$pages>10)throw new RuntimeException('pagination_loop');}while(true);
usageCheck('all47valid applications traverse exactly once without foreign test malformed or internal rows',count($ids)===47&&count(array_unique($ids))===47&&$ids===range(10047,10001));
$case=$first['items'][0];usageCheck('historical author is separate from current assignee',$case['manager_id']===7&&$case['manager_name']==='Historical author'&&$case['display_name']==='Working name');
usageCheck('source question proposed text reason and real sent text are available',$case['question_text']==='Question 47'&&$case['proposed_text']==='AI proposal 47'&&$case['current_sent_text']==='AI proposal 47'&&$case['reason']==='Synthetic reason'&&$case['initial_text_available']);
usageCheck('prepared and generated sources are distinguished',count(array_filter($first['items'],static fn($x)=>$x['source']==='prepared'))>0&&count(array_filter($first['items'],static fn($x)=>$x['source']==='generated'))>0);
usageCheck('stored draft time remains distinct from the later send time',$case['generated_at']==='2026-10-25 10:00:00'&&$case['sent_at']==='2026-10-25 10:02:00');
usageCheck('a request cannot relabel old common rules as current',array_column($read(['base_instruction_current'=>true])['items'],'base_instruction_current','message_id')[10046]===false);
$input=static fn(int $message,string $revision,int $actor=1)=>ManagerAiUsageService::input($actor,$message,$revision,$now);
$originalInput=$input($case['message_id'],$case['case_revision']);
usageCheck('current project owner reads exact manual input despite changed conversation assignee',
    $originalInput['ok']&&$originalInput['available']&&$originalInput['message_id']===10047
    &&$originalInput['case_revision']===$case['case_revision']
    &&$originalInput['input_evidence']['context']['messages'][0]['text']==='Question 47'
    &&$originalInput['input_evidence']['instructions']==='Synthetic context-specific instructions'
    &&$originalInput['input_evidence']['coverage']==='text_only');
usageCheck('input read cannot expand owner scope or bypass historical send validation',
    $input(10047,$case['case_revision'],2)['error']==='forbidden'
    &&$input(10047,$case['case_revision'],3)['error']==='forbidden'
    &&$input(10047,$case['case_revision'],4)['error']==='forbidden'
    &&$input(10055,$case['case_revision'])['error']==='not_found'
    &&$input(10052,$case['case_revision'])['error']==='forbidden');
usageCheck('input requires the exact compared send version',
    $input(10047,str_repeat('0',64))['error']==='case_changed'
    &&$input(0,$case['case_revision'])['error']==='invalid_request'
    &&$input(10047,'bad')['error']==='invalid_request');
$allCases=array_column($first['items'],null,'message_id');
usageCheck('old manual rows report missing input rather than reconstructing it',
    $input(10046,$allCases[10046]['case_revision'])===['ok'=>true,'viewer_id'=>1,'message_id'=>10046,
        'case_revision'=>$allCases[10046]['case_revision'],'available'=>false,'reason'=>'not_recorded']);
ManagerAiUsageFixture::captureInput($pdo,45);
usageCheck('a prepared preview never relabels a stored envelope as original worker input',
    $input(10045,$allCases[10045]['case_revision'])['reason']==='prepared_input_not_captured');
$pdo->exec("UPDATE messages SET text='Changed question and replacement offer',metadata_json='{\"edited_at\":\"2026-10-25 10:06:00\"}' WHERE id=1047");
usageCheck('later source edits leave the exact original generation evidence unchanged',
    $input(10047,$case['case_revision'])===$originalInput);
$pdo->exec("UPDATE messages SET text='Question 47',metadata_json=NULL WHERE id=1047");
$inputId=str_pad(dechex(47),32,'0',STR_PAD_LEFT);
$inputRow=$pdo->query("SELECT input_json,input_sha256 FROM manager_ai_draft_inputs WHERE draft_id='".$inputId."'")->fetch();
$changeInput=$pdo->prepare('UPDATE manager_ai_draft_inputs SET input_json=?,input_sha256=? WHERE draft_id=?');
$changeInput->execute([$inputRow['input_json'],str_repeat('0',64),$inputId]);
usageCheck('a mismatched digest cannot expose evidence',$input(10047,$case['case_revision'])['reason']==='invalid_evidence');
$changeInput->execute(['{}',hash('sha256','{}'),$inputId]);
usageCheck('a matching digest cannot authorize a malformed envelope',$input(10047,$case['case_revision'])['reason']==='invalid_evidence');
$wrongContext=$originalInput['input_evidence']['context'];$wrongContext['conversation_id']=146;
$wrongEvidence=ManagerAiDraftInputEvidence::capture($wrongContext,'Synthetic instructions');
$changeInput->execute([$wrongEvidence->json(),$wrongEvidence->hash(),$inputId]);
usageCheck('valid input for another conversation cannot attach to the send',$input(10047,$case['case_revision'])['reason']==='invalid_evidence');
$oversized=str_repeat('Ж',ManagerAiDraftInputEvidence::MAX_BYTES);
$changeInput->execute([$oversized,hash('sha256',$oversized),$inputId]);
usageCheck('oversized Unicode evidence is refused without returning a clipped context',
    $input(10047,$case['case_revision'])['reason']==='invalid_evidence'
    &&!isset($input(10047,$case['case_revision'])['input_evidence']));
$changeInput->execute([$inputRow['input_json'],$inputRow['input_sha256'],$inputId]);
$pdo->beginTransaction();usageCheck('input read does not consume a caller transaction',$input(10047,$case['case_revision'])['error']==='unavailable'&&$pdo->inTransaction());$pdo->rollBack();
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
$changeInstruction=$pdo->prepare('UPDATE manager_ai_drafts SET instruction_revision=? WHERE draft_id=?');
$caseDraft=str_pad(dechex(47),32,'0',STR_PAD_LEFT);$changeInstruction->execute([str_repeat('b',64),$caseDraft]);
$olderRules=$read(['mode'=>'all'])['items'][0];
usageCheck('a different common-rule observation never resurrects an already reviewed comparison',!$olderRules['base_instruction_current']&&$olderRules['reviewed']&&$olderRules['case_revision']===$case['case_revision']&&!in_array($case['message_id'],array_column($read()['items'],'message_id'),true));
$changeInstruction->execute([hash('sha256',ManagerAiDraftService::instructions()),$caseDraft]);
$pdo->exec('INSERT INTO manager_projects VALUES(2,1)');usageCheck('review marker is personal to its owner',in_array($case['message_id'],array_column($read([],2)['items'],'message_id'),true));
require_once dirname(__DIR__).'/services/ManagerAiSentTextService.php';
$initialMessage=$pdo->query('SELECT * FROM messages WHERE id=10047')->fetch();
$initialMetadata=json_decode($initialMessage['metadata_json'],true);
usageCheck('synthetic initial text capture uses historical author rather than current assignee',ManagerAiSentTextService::record($pdo,10047,147,'manager','7',$initialMessage['text'],$initialMetadata,'AI proposal 47'));
$q=$pdo->prepare('UPDATE messages SET text=?,metadata_json=? WHERE id=?');$meta=json_decode($pdo->query('SELECT metadata_json FROM messages WHERE id=10047')->fetchColumn(),true);$meta['edited_at']='2026-10-25 10:05:00';$q->execute(['Later manager edit',json_encode($meta),10047]);
$updated=$read()['items'][0];usageCheck('later message edit reappears as an updated comparison without inventing initial sent text',$updated['message_id']===10047&&$updated['updated_since_review']&&!$updated['reviewed']&&!$updated['initial_text_available']&&$updated['edited']&&$updated['current_sent_text']==='Later manager edit');
usageCheck('changed filter also retains later edits without rewriting original-send equality',$read(['changed_only'=>true])['items'][0]['message_id']===10047&&$updated['recorded_text_matches_proposal']);
usageCheck('late old-tab review cannot mark a newer comparison viewed',ManagerAiUsageService::review(1,10047,$case['case_revision'],$now)['error']==='case_changed'&&!$read()['items'][0]['reviewed']);
usageCheck('a later sent-message edit requires a fresh comparison before input is exposed',
    $input(10047,$case['case_revision'])['error']==='case_changed'
    &&$input(10047,$updated['case_revision'])['input_evidence']===$originalInput['input_evidence']);
$originalSent=ManagerAiUsageService::sentText(1,10047,$updated['case_revision'],$now);
usageCheck('owner reads immutable original after message edit despite current reassignment',
    $originalSent['ok']&&$originalSent['available']&&$originalSent['text']==='AI proposal 47'&&$originalSent['text_sha256']===hash('sha256','AI proposal 47')
    &&$originalSent['case_revision']===$updated['case_revision']&&$originalSent['message_id']===10047);
usageCheck('original text shares exact case and current owner access gates',
    ManagerAiUsageService::sentText(1,10047,$case['case_revision'],$now)['error']==='case_changed'
    &&ManagerAiUsageService::sentText(2,10047,$updated['case_revision'],$now)['available']===true
    &&ManagerAiUsageService::sentText(3,10047,$updated['case_revision'],$now)['error']==='forbidden');
usageCheck('old text is not reconstructed from a proposal or current message',!ManagerAiUsageService::sentText(1,10046,$allCases[10046]['case_revision'],$now)['available']);
$receipt=$pdo->query('SELECT * FROM manager_ai_sent_texts WHERE message_id=10047')->fetch();
$pdo->exec("UPDATE manager_ai_sent_texts SET text_value='Wrong original' WHERE message_id=10047");
usageCheck('tampered original text cannot become an authoritative correction target',ManagerAiUsageService::sentText(1,10047,$updated['case_revision'],$now)['reason']==='invalid_evidence');
$pdo->prepare('UPDATE manager_ai_sent_texts SET text_value=?,conversation_id=146 WHERE message_id=10047')->execute([$receipt['text_value']]);
usageCheck('same-digest receipt cannot cross a conversation binding',ManagerAiUsageService::sentText(1,10047,$updated['case_revision'],$now)['reason']==='invalid_evidence');
$pdo->exec('UPDATE manager_ai_sent_texts SET conversation_id=147 WHERE message_id=10047');
foreach(['manager_id'=>8,'project_key'=>'ANYTOUR','draft_id'=>str_repeat('f',32)] as $column=>$wrong){$pdo->prepare('UPDATE manager_ai_sent_texts SET '.$column.'=? WHERE message_id=10047')->execute([$wrong]);usageCheck('initial text verifies immutable '.$column.' binding',ManagerAiUsageService::sentText(1,10047,$updated['case_revision'],$now)['reason']==='invalid_evidence');$pdo->prepare('UPDATE manager_ai_sent_texts SET '.$column.'=? WHERE message_id=10047')->execute([$receipt[$column]]);}
$pdo->beginTransaction();usageCheck('original text read cannot consume a caller transaction',ManagerAiUsageService::sentText(1,10047,$updated['case_revision'],$now)['error']==='unavailable'&&$pdo->inTransaction());$pdo->rollBack();
usageCheck('original text reads preserve personal review state',!$read()['items'][0]['reviewed']);
usageCheck('new current revision can be explicitly reviewed',ManagerAiUsageService::review(1,10047,$updated['case_revision'],$now)['ok']);
usageCheck('invalid references and inaccessible messages cannot be reviewed',ManagerAiUsageService::review(1,10055,str_repeat('a',64),$now)['error']==='not_found'&&ManagerAiUsageService::review(1,10051,str_repeat('a',64),$now)['error']==='forbidden'&&ManagerAiUsageService::review(3,10046,str_repeat('a',64),$now)['error']==='forbidden');
$pdo->exec('DELETE FROM manager_projects WHERE manager_id=1');usageCheck('fresh project revoke blocks reads review input and saved cursor',$read()['error']==='forbidden'&&ManagerAiUsageService::review(1,10046,str_repeat('a',64),$now)['error']==='forbidden'&&$input(10047,$updated['case_revision'])['error']==='forbidden'&&$read(['cursor'=>$first['next_cursor']])['error']==='forbidden');$pdo->exec('INSERT INTO manager_projects VALUES(1,1)');
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
if($baselineClass!==null)usageCheck('shared cutoffs missing edited and bounded text equal installed output',usageHistoricalOutput($edgePage)===$baselineClass::read(1,[],$now));
$pdo->exec("UPDATE manager_ai_drafts SET latest_message_id=1100,created_at='2026-10-25 09:59:00' WHERE draft_id='".str_pad(dechex(45),32,'0',STR_PAD_LEFT)."'");
$pdo->exec("UPDATE messages SET created_at='2026-10-25 09:58:30',metadata_json='".'{"edited_at":"2026-10-25 09:59:45"}'."' WHERE id=1097");
$editPage=$read();$editCases=array_column($editPage['items'],null,'message_id');
usageCheck('the same source edit is labelled against each proposal generation time',$editCases[10045]['question_text']==='Newer source'&&$editCases[10046]['question_text']==='Newer source'&&$editCases[10045]['question_edited']&&!$editCases[10046]['question_edited']);
if($baselineClass!==null)usageCheck('shared question edit labels equal exact installed output',usageHistoricalOutput($editPage)===$baselineClass::read(1,[],$now));
// Cutoffs retain integer identity beyond a floating-point exact-number boundary.
$largeCutoff=9007199254740993;
foreach([[$largeCutoff-1,'Before large cutoff'],[$largeCutoff,'Exact large cutoff'],[$largeCutoff+1,'Future large cutoff']] as [$id,$text])$insertSource->execute([$id,146,'inbound','customer','','telegram',$text,null,'2026-10-25 09:58:30']);
$largeUpdate=$pdo->prepare('UPDATE manager_ai_drafts SET latest_message_id=? WHERE draft_id=?');$largeUpdate->execute([$largeCutoff,str_pad(dechex(45),32,'0',STR_PAD_LEFT)]);
$largePage=$read();$largeCases=array_column($largePage['items'],null,'message_id');
usageCheck('integer cutoff neither rounds into future history nor crosses another use',$largeCases[10045]['question_text']==='Exact large cutoff'&&$largeCases[10046]['question_text']==='Newer source');
if($baselineClass!==null)usageCheck('large integer cutoffs equal exact installed output',usageHistoricalOutput($largePage)===$baselineClass::read(1,[],$now));
// A page of unusable provenance cannot hide lower valid sends or loop forever.
$pdo->beginTransaction();$insert=$pdo->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?,?)');for($i=0;$i<1005;$i++)$insert->execute([20000+$i,101,'outbound','manager','7','telegram','Synthetic','{"ai_draft":{"draft_id":"bad"}}','2026-10-25 11:00:00']);$pdo->commit();
$limited=$read();usageCheck('scan limit is explicit and keyset cursor advances over unusable records',$limited['ok']&&$limited['items']===[]&&$limited['scan_limited']&&$limited['has_more']&&is_string($limited['next_cursor']));
$afterLimit=$read(['cursor'=>$limited['next_cursor']]);usageCheck('continuation reaches lower valid applications after unusable page',$afterLimit['ok']&&count($afterLimit['items'])===20&&$afterLimit['items'][0]['message_id']===10046);
// A bounded semantic review of the initial actual send, separate from viewed marks.
$pdo->exec('DELETE FROM manager_projects WHERE manager_id=2 AND project_id=1');
ManagerAiUsageFixture::correctionCase($pdo);
function assessmentCase(PDO $pdo,DateTimeImmutable $now): array{
    $m=$pdo->query("SELECT m.*,c.project_key,c.channel AS conversation_channel FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE m.id=10040")->fetch();
    $d=$pdo->query("SELECT * FROM manager_ai_drafts WHERE conversation_id=140")->fetch();
    return ManagerAiDraftFeedbackReport::inspectUsage($m,$d,'anytour',$now);
}
$ac=assessmentCase($pdo,$now);
$assess=static fn(array $input=[],int $actor=1,bool $write=false)=>ManagerAiUsageService::assessment($actor,['message_id'=>10040,'case_revision'=>$ac['case_revision']]+$input,$write,$now);
$a=$assess();$ab=$business();$reviewCount=(int)$pdo->query('SELECT COUNT(*) FROM manager_ai_usage_reviews')->fetchColumn();
usageCheck('assessment reads frozen initial pair and full request without grading it',$a['ok']&&$a['version']===0&&$a['candidate']===null&&!$a['case_improvement_confirmed']&&$a['snapshot']['can_judge_effect']&&$a['snapshot']['pair_changed']&&str_contains($a['snapshot']['request_messages'][0]['text'],'без перелёта'));
$command=static fn(array $state,array $changes=[])=>array_replace(['version'=>$state['version'],'comparison_sha256'=>$state['snapshot']['comparison_sha256'],'operation_id'=>bin2hex(random_bytes(16)),'phase'=>'proposal','target_version'=>0,
    'data'=>['kind'=>'meaning','dimension'=>'last_question','effect'=>'better','reason'=>'Ответ добавляет реакцию на вопрос о варианте без перелёта, сохраняя неизвестные условия.','citation'=>['message_id'=>1040,'quote'=>'А без перелёта можно?']]],$changes);
$c=$command($a);$saved=$assess($c,1,true);
usageCheck('substantive correction is a candidate rather than a confirmed improvement',$saved['ok']&&$saved['saved_version']===1&&!$assess()['case_improvement_confirmed']);
usageCheck('same immutable command is idempotent after a lost response',$assess($c,1,true)['replayed']&&(int)$pdo->query('SELECT COUNT(*) FROM manager_ai_usage_assessments')->fetchColumn()===1);
$changed=$c;$changed['data']['reason']='Another judgement';usageCheck('operation identity cannot be reused for different content',$assess($changed,1,true)['error']==='operation_mismatch');
usageCheck('operation receipt is readable only within fresh owner scope',$assess(['operation_id'=>$c['operation_id']])['operation']===['found'=>true,'saved_version'=>1]&&$assess(['operation_id'=>$c['operation_id']],5)['operation']['found']===false&&$assess([],2)['error']==='forbidden');
$a=$assess();$decision=$command($a,['phase'=>'adjudication','target_version'=>1,'data'=>['verdict'=>'confirm','reason'=>'Оба вопроса видны в сохранённом вводе, ответ различает проверку условий и поиск дат.','citation'=>['message_id'=>1040,'quote'=>'после 25 октября.']]]);
usageCheck('a classifier cannot independently confirm their own judgement',$assess($decision,1,true)['error']==='independent_owner_required');
$pdo->exec("UPDATE managers SET role='admin' WHERE id=7;INSERT INTO manager_projects VALUES(7,1)");
usageCheck('the original sending manager cannot independently confirm the correction',$assess($decision,7,true)['error']==='independent_owner_required');
$pdo->exec("UPDATE managers SET role='manager' WHERE id=7;DELETE FROM manager_projects WHERE manager_id=7");
$foreign=$decision;$foreign['data']['citation']['quote']='Цена уже подтверждена';
usageCheck('independent judgement requires a literal frozen customer citation',$assess($foreign,5,true)['error']==='invalid_citation');
$confirmed=$assess($decision,5,true);$a=$assess();
usageCheck('different current owner confirms only the exact candidate and frozen case',$confirmed['ok']&&$a['decision']['manager_id']===5&&$a['candidate']['manager_id']===1&&$a['case_improvement_confirmed']&&$a['version']===2);
$race=$decision;$race['operation_id']=bin2hex(random_bytes(16));$race['data']['verdict']='reject';
usageCheck('a second stale writer cannot overwrite an intervening judgement',$assess($race,5,true)['error']==='conflict'&&(int)$pdo->query('SELECT COUNT(*) FROM manager_ai_usage_assessments')->fetchColumn()===2);
$style=$command($a,['data'=>['kind'=>'style','dimension'=>'none','effect'=>'better','reason'=>'Стало вежливее','citation'=>null]]);
usageCheck('style alone cannot be counted as an answer-quality improvement',$assess($style,1,true)['error']==='invalid_assessment');
$style['data']['effect']='unknown';usageCheck('style judgement saves independently of substantive improvement',$assess($style,1,true)['ok']&&!$assess()['case_improvement_confirmed']);
$a=$assess();$bad=$command($a);$bad['data']['citation']['message_id']=1041;
usageCheck('a current or foreign source cannot replace the frozen request citation',$assess($bad,1,true)['error']==='invalid_citation');
$c=$command($a);usageCheck('a fresh substantive candidate supersedes earlier judgements',$assess($c,1,true)['ok']&&$assess()['decision']===null);
$a=$assess();$oldHash=$a['snapshot']['comparison_sha256'];$oldRev=$ac['case_revision'];
$meta=json_decode($pdo->query('SELECT metadata_json FROM messages WHERE id=10040')->fetchColumn(),true);$meta['edited_at']='2026-10-25 11:00:00';
$pdo->prepare('UPDATE messages SET text=?,metadata_json=? WHERE id=10040')->execute(['Later unrelated edit',json_encode($meta)]);$ac=assessmentCase($pdo,$now);
usageCheck('stale visible case revision cannot assess a later displayed message',ManagerAiUsageService::assessment(1,['message_id'=>10040,'case_revision'=>$oldRev],false,$now)['error']==='case_changed');
$assess=static fn(array $input=[],int $actor=1,bool $write=false)=>ManagerAiUsageService::assessment($actor,['message_id'=>10040,'case_revision'=>$ac['case_revision']]+$input,$write,$now);
$a=$assess();usageCheck('later edits retain the independent immutable comparison and its candidate',$a['ok']&&$a['snapshot']['comparison_sha256']===$oldHash&&$a['candidate']['version']===4&&!str_contains($a['snapshot']['initial_sent_text'],'Later'));
$pdo->prepare('UPDATE messages SET text=? WHERE id=1040')->execute(['A changed source question']);
usageCheck('today source question cannot replace the original model input',$assess()['snapshot']['request_messages'][0]['text']==='А без перелёта можно? И ещё посмотрите даты после 25 октября.');
usageCheck('assessment commands change neither business rows nor viewed markers',array_slice($ab,1)===array_slice($business(),1)&&(int)$pdo->query('SELECT COUNT(*) FROM manager_ai_usage_reviews')->fetchColumn()===$reviewCount);
$draft40=str_pad(dechex(40),32,'0',STR_PAD_LEFT);$pdo->prepare('UPDATE manager_ai_drafts SET preparation_job_id=? WHERE draft_id=?')->execute([str_repeat('e',64),$draft40]);
$a=$assess();usageCheck('prepared worker input remains unknown and invalidates the prior candidate',$a['ok']&&!$a['snapshot']['can_judge_effect']&&$a['snapshot']['input_reason']==='prepared_input_not_captured'&&$a['candidate']===null&&$a['history'][0]['stale']);
$c=$command($a);usageCheck('unknown prepared input cannot support a positive or negative effect judgement',$assess($c,1,true)['error']==='effect_unassessable');
$c['data']['effect']='unknown';$c['data']['citation']=null;usageCheck('textual meaning change can be described without claiming improvement',$assess($c,1,true)['ok']);
$a=$assess();$c=$command($a,['phase'=>'adjudication','target_version'=>$a['candidate']['version'],'data'=>['verdict'=>'confirm','reason'=>'Второе мнение','citation'=>['message_id'=>1040,'quote'=>'А без перелёта можно?']]]);
usageCheck('another owner cannot turn missing input into a confirmed improvement',$assess($c,5,true)['error']==='effect_unassessable');
$c['data']['verdict']='insufficient_context';$c['data']['citation']=null;usageCheck('independent reviewer can explicitly retain insufficient context',$assess($c,5,true)['ok']&&!$assess()['case_improvement_confirmed']);
$pdo->prepare('UPDATE manager_ai_drafts SET preparation_job_id=NULL WHERE draft_id=?')->execute([$draft40]);
$iq=$pdo->prepare('SELECT input_json,input_sha256 FROM manager_ai_draft_inputs WHERE draft_id=?');$iq->execute([$draft40]);$full=$iq->fetch();$iq->closeCursor();$iv=json_decode($full['input_json'],true);
foreach(['history_incomplete','media_unread','selections_link_unread','message_media','message_selection','images'] as $gap){
    $ctx=$iv['context'];$images=[];if(str_starts_with($gap,'message_'))$ctx['messages'][0][$gap==='message_media'?'media_unread':'selections_link_unread']=true;
    elseif($gap==='images')$images=[['type'=>'input_image','image_url'=>'data:image/png;base64,YWJj','detail'=>'high']];else$ctx[$gap]=true;
    $partial=ManagerAiDraftInputEvidence::capture($ctx,$iv['instructions'],$images);if(!$partial)throw new RuntimeException('partial_fixture_invalid');
    $pdo->prepare('UPDATE manager_ai_draft_inputs SET input_json=?,input_sha256=? WHERE draft_id=?')->execute([$partial->json(),$partial->hash(),$draft40]);
    $pa=$assess();$pc=$command($pa);usageCheck('incomplete frozen coverage cannot support effect: '.$gap,$pa['ok']&&$pa['snapshot']['input_available']&&!$pa['snapshot']['can_judge_effect']&&$assess($pc,1,true)['error']==='effect_unassessable');
}
$pdo->prepare('UPDATE manager_ai_draft_inputs SET input_json=?,input_sha256=? WHERE draft_id=?')->execute([$full['input_json'],$full['input_sha256'],$draft40]);
$pdo->prepare('UPDATE manager_ai_draft_inputs SET input_sha256=? WHERE draft_id=?')->execute([str_repeat('0',64),$draft40]);
$a=$assess();usageCheck('corrupt input is unavailable rather than reconstructed',$a['ok']&&!$a['snapshot']['input_available']&&$a['snapshot']['input_reason']==='invalid_evidence');
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
    // Two actual PHP processes contend on the same canonical message lock.
    $state=$assess();$rc=$command($state);$rc['data']['effect']='unknown';$rc['data']['citation']=null;
    $child= <<<'PHP'
require $argv[1];
$p=new PDO(getenv('MANAGER_AI_USAGE_MYSQL_DSN'),getenv('MANAGER_AI_USAGE_MYSQL_USER'),getenv('MANAGER_AI_USAGE_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$p);ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'AnyTour']]);
$in=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);echo "READY\n";flush();
$r=ManagerAiUsageService::assessment($in['actor'],$in['command'],true,new DateTimeImmutable('2026-10-25 12:00:00',new DateTimeZone('UTC')));echo json_encode($r,JSON_THROW_ON_ERROR)."\n";
PHP;
    $pdo->beginTransaction();$pdo->query('SELECT id FROM messages WHERE id=10040 FOR UPDATE')->fetch();$children=[];
    try{
        foreach([1,5] as $raceActor){$cmd=$rc;$cmd['operation_id']=bin2hex(random_bytes(16));$cmd['message_id']=10040;$cmd['case_revision']=$ac['case_revision'];
            $proc=proc_open([PHP_BINARY,'-r',$child,dirname(__DIR__).'/services/ManagerAiUsageService.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($proc))throw new RuntimeException('race_child_failed');stream_set_timeout($pipes[1],10);stream_set_timeout($pipes[2],10);
            fwrite($pipes[0],json_encode(['actor'=>$raceActor,'command'=>$cmd],JSON_THROW_ON_ERROR));fclose($pipes[0]);$children[]=[$proc,$pipes];
        }
        foreach($children as [$proc,$pipes])if(trim((string)fgets($pipes[1]))!=='READY')throw new RuntimeException('race_child_not_ready');
        $pdo->commit();$results=[];foreach($children as [$proc,$pipes]){$raw=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);if($exit!==0||$err!=='')throw new RuntimeException('race_child_execution_failed');$results[]=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}$children=[];
        usageCheck('native concurrent owners produce one immutable winner and one CAS conflict',count(array_filter($results,static fn($r)=>!empty($r['ok'])))===1&&count(array_filter($results,static fn($r)=>($r['error']??null)==='conflict'))===1&&$assess()['version']===$state['version']+1);
    }finally{if($pdo->inTransaction())$pdo->rollBack();foreach($children as [$proc,$pipes]){proc_terminate($proc);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($proc);}}
}
$pdo->exec('DELETE FROM manager_projects WHERE manager_id=5');usageCheck('revoked current owner cannot read or adjudicate private assessments',$assess([],5)['error']==='forbidden'&&$assess($c,5,true)['error']==='forbidden');
$corruptVersion=(int)$pdo->query('SELECT MAX(version) FROM manager_ai_usage_assessments WHERE message_id=10040')->fetchColumn();
$pdo->prepare("UPDATE manager_ai_usage_assessments SET assessment_sha256='broken' WHERE message_id=10040 AND version=?")->execute([$corruptVersion]);
usageCheck('corrupt immutable assessment fails closed',$assess()['error']==='unavailable');
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
    $count=(int)$pdo->query('SELECT COUNT(*) FROM manager_ai_usage_assessments')->fetchColumn();
    $pdo->exec(file_get_contents(dirname(__DIR__).'/migrations/081_manager_ai_usage_assessments.sql'));
    usageCheck('native081 migration retry preserves existing private assessments',(int)$pdo->query('SELECT COUNT(*) FROM manager_ai_usage_assessments')->fetchColumn()===$count);
    $duplicate=false;try{$pdo->exec('INSERT INTO manager_ai_usage_assessments SELECT message_id,version+100,draft_id,manager_id,operation_id,request_sha256,comparison_sha256,phase,target_version,assessment_json,assessment_sha256,created_at FROM manager_ai_usage_assessments WHERE message_id=10040 AND version=1');}catch(PDOException $e){$duplicate=($e->errorInfo[0]??null)==='23000'&&(int)($e->errorInfo[1]??0)===1062;}
    usageCheck('native081 actor-operation uniqueness prevents a second immutable command',$duplicate&&(int)$pdo->query('SELECT COUNT(*) FROM manager_ai_usage_assessments')->fetchColumn()===$count);
    $m38=$pdo->query('SELECT m.*,c.project_key,c.channel AS conversation_channel FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE m.id=10038')->fetch();
    $d38=$pdo->query('SELECT * FROM manager_ai_drafts WHERE conversation_id=138')->fetch();$c38=ManagerAiDraftFeedbackReport::inspectUsage($m38,$d38,'anytour',$now);
    $base38=['message_id'=>10038,'case_revision'=>$c38['case_revision']];$v38=ManagerAiUsageService::assessment(1,$base38,false,$now);
    $s38=ManagerAiUsageService::assessment(1,$base38+['version'=>$v38['version'],'comparison_sha256'=>$v38['snapshot']['comparison_sha256'],'operation_id'=>bin2hex(random_bytes(16)),'phase'=>'proposal','target_version'=>0,
        'data'=>['kind'=>'unassessable','dimension'=>'none','effect'=>'unknown','reason'=>'Исходный ввод недоступен; синтетическая проверка хранения.','citation'=>null]],true,$now);
    usageCheck('native081 separate canonical fixture can retain unknown judgement',$s38['ok']&&$s38['saved_version']===1);
    $pdo->exec('DELETE FROM messages WHERE id=10040');
    usageCheck('native081 initial message retention cascades to all its assessment versions',(int)$pdo->query('SELECT COUNT(*) FROM manager_ai_usage_assessments WHERE message_id=10040')->fetchColumn()===0);
    $pdo->prepare('DELETE FROM manager_ai_drafts WHERE draft_id=?')->execute([$d38['draft_id']]);
    usageCheck('native081 draft retention cascades without deleting another message',(int)$pdo->query('SELECT COUNT(*) FROM manager_ai_usage_assessments WHERE message_id=10038')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM messages WHERE id=10038')->fetchColumn()===1);
}
$pdo->exec('DROP TABLE manager_ai_usage_assessments');usageCheck('missing assessment migration never creates tables or changes old lists',ManagerAiUsageService::assessment(1,['message_id'=>10047,'case_revision'=>$updated['case_revision']],false,$now)['error']==='unavailable'&&$read()['ok']);

$pdo->exec('DROP TABLE manager_ai_sent_texts');usageCheck('missing original text storage is explicit without changing lists',ManagerAiUsageService::sentText(1,10047,$updated['case_revision'],$now)['reason']==='storage_unavailable'&&$read()['ok']);
$pdo->exec('DROP TABLE manager_ai_draft_inputs');
usageCheck('missing input storage is explicit and never reconstructed or created at runtime',
    $input(10047,$updated['case_revision'])['reason']==='storage_unavailable'&&$read()['ok']);
$pdo->exec('DROP TABLE manager_ai_usage_reviews');usageCheck('missing migration stays unavailable and is never repaired at runtime',$read()['error']==='unavailable');
echo 'READ_FIXTURE '.json_encode(['driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),'page_cases'=>count($first['items']),'one_case_selects'=>$singleReads,'page_selects'=>$pageReads,'installed_page_selects'=>$baselineReads,'production_latency'=>null])."\n";
usageCheck('twenty cases need no per-case SELECT growth over one case',$single['ok']&&count($single['items'])===1&&$pageReads<=$singleReads+1);
if($baselineReads!==null)usageCheck('paired page removes at least nineteen SELECT round trips',$baselineReads-$pageReads>=19);
echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
