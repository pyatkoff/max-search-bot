<?php

declare(strict_types=1);
require_once __DIR__.'/../tools/manager_question_census.php';
$n=0; $fail=0;
function check(string $label, bool $ok):void {global $n,$fail;$n++;if(!$ok)$fail++;echo ($ok?'PASS ':'FAIL ').$label."\n";}
function row(int $id,string $sender,string $text,string $type=''):array{return ['id'=>$id,'direction'=>$sender==='customer'?'inbound':'outbound','sender_type'=>$sender,'text'=>$text,'event_type'=>$type];}
$messages=[row(1,'customer','Бюджет до 250 тысяч на всех. Нужен тихий отель.'),row(2,'ai','Уточните даты поездки?'),row(3,'manager','Какой бюджет рассматриваете?'),row(4,'customer','250 тысяч.'),row(5,'manager','Какие даты поездки?'),row(6,'manager','Какие пожелания к отелю?'),row(7,'manager','Подскажите, какой город вылета?')];
$r=ManagerQuestionCensus::add(ManagerQuestionCensus::blank(),$messages);
check('one full history',$r['conversations']===1);
check('recorded manager messages',$r['manager_messages']===4);
check('all question messages',$r['question_messages']===4);
check('budget early question',$r['topics']['budget']['early_question_conversations']===1);
check('prior mention is only a candidate signal',$r['topics']['budget']['early_with_prior_customer_topic_mention']===1);
check('bot date topic independently counted',$r['topics']['dates']['early_with_prior_bot_topic_mention']===1);
check('fourth manager reply is not early',$r['topics']['departure']['question_conversations']===1&&$r['topics']['departure']['early_question_conversations']===0);
check('customer continuation is recorded not delivery',$r['conversations_with_customer_message_after_manager']===1);
$twice=ManagerQuestionCensus::add(ManagerQuestionCensus::blank(),[row(1,'manager','Какой бюджет?'),row(2,'manager','Подскажите бюджет'),row(3,'manager','Какой бюджет?')]);
check('repeated questions counted once per conversation',$twice['topics']['budget']['question_conversations']===1&&$twice['question_messages']===3);
check('plain offer is not a question',ManagerQuestionCensus::questions('Стоимость отеля 250 тысяч, питание всё включено.')['question']===false);
check('offered hotel not attached to unrelated question',ManagerQuestionCensus::questions('Отель на первой линии. Какой бюджет?')['topics']===['budget'=>true]);
check('polite request without question mark detected',isset(ManagerQuestionCensus::questions('Подскажите бюджет на поездку')['topics']['budget']));
check('question outside categories exposed',ManagerQuestionCensus::add(ManagerQuestionCensus::blank(),[row(1,'manager','Вы меня слышите?')])['unclassified_question_messages']===1);
check('URL question and words ignored',ManagerQuestionCensus::questions('https://example.invalid/?бюджет=тест')['question']===false);
foreach(['callback','start','bot_started'] as $type){$x=ManagerQuestionCensus::add(ManagerQuestionCensus::blank(),[row(1,'customer','бюджет', $type),row(2,'manager','Какой бюджет?')]);check('exclude '.$type,$x['topics']['budget']['early_with_prior_customer_topic_mention']===0);}
$x=ManagerQuestionCensus::add(ManagerQuestionCensus::blank(),[row(1,'manager','Какой бюджет?'),row(2,'customer','Бюджет 250 тысяч'),row(3,'manager','Какой бюджет?')]);
check('post-handoff text is never pre-handoff evidence',$x['topics']['budget']['early_with_prior_customer_topic_mention']===0);
$payload='PrivatePerson +79991234567 secret@example.invalid https://private.invalid/secret';
$out=json_encode(ManagerQuestionCensus::add(ManagerQuestionCensus::blank(),[row(10,'customer',$payload),row(20,'manager','Какой бюджет?')]));
check('no raw text leaves reducer',!str_contains($out,'PrivatePerson')&&!str_contains($out,'79991234567')&&!str_contains($out,'private.invalid'));
check('no input identifiers leave reducer',!str_contains($out,'"id"')&&!str_contains($out,'sender_type'));
foreach([[row(1,'customer','test')],[row(2,'manager','test'),row(1,'customer','test')],[row(1,'manager',str_repeat('a',16001))],[row(1,'manager','test'),row(1,'customer','test')]] as $i=>$invalid){$thrown=false;try{ManagerQuestionCensus::add(ManagerQuestionCensus::blank(),$invalid);}catch(RuntimeException $e){$thrown=true;}check('invalid history fails '.$i,$thrown);}
$sql=ManagerQuestionCensus::conversationSql();
check('project-scoped selection',str_contains($sql,'c.project_key=?'));
check('explicit tests excluded',str_contains($sql,'COALESCE(c.is_test,0)=0'));
check('cohort has both start bounds',str_contains($sql,'c.started_at>=? AND c.started_at<?'));
check('cohort request tied to same conversation',str_contains($sql,'m.conversation_id=c.id'));
check('bounded deterministic selection',str_ends_with($sql,'ORDER BY c.id ASC LIMIT 301'));
$from=new DateTimeImmutable('2026-09-07 00:00:00',new DateTimeZone('Europe/Kaliningrad'));
check('window converted to UTC correctly',$from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')===ManagerQuestionCensus::SINCE);
foreach(ManagerQuestionCensus::blank()['topics'] as $key=>$metric){foreach($metric as $value){check('integer-only aggregate '.$key,is_int($value));}}
echo "TOTAL $n | PASS ".($n-$fail)." | FAIL $fail\n";exit($fail?1:0);
