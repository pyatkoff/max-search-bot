<?php
declare(strict_types=1);
define('MANAGER_AI_BULK_LIBRARY_ONLY',true);
require dirname(__DIR__).'/services/ManagerAiDraftService.php';
require dirname(__DIR__).'/tools/manager_ai_bulk.php';
function callBulk(string $method,...$args){return (new ReflectionMethod(ManagerAiBulkEval::class,$method))->invoke(null,...$args);}
function checkBulk(bool $yes,string $name):void{if(!$yes)throw new RuntimeException('bulk_synthetic_failed:'.$name);}
function contextBulk(int $id=1):array{return ['conversation_id'=>$id,'as_of_message_id'=>7,'messages'=>[['id'=>7,'sender_type'=>'customer','text'=>'Нужен тур в Турцию на двоих из Казани.']],'history_incomplete'=>false];}
function resultBulk():array{return ['ok'=>true,'should_draft'=>true,'draft'=>'На какие даты планируете поездку?','next_action'=>'clarify','reason'=>'Не названы даты.','checks'=>[],'evidence_message_ids'=>[7],'history_incomplete'=>false];}
$calls=0;$seen='';$r=callBulk('runCase',['case_id'=>'LH001_LEARNED','context'=>contextBulk()],1,'generate',static function($i,$p,$o)use(&$calls,&$seen){++$calls;$seen=$i;return resultBulk();});
checkBulk($calls===1&&$r['provider_calls']===1&&$seen===ManagerAiDraftService::instructions(),'canonical_prompt_no_id_routing');
$office=contextBulk();$office['messages'][0]['text']='Где у вас офис?';$n=0;
$r=callBulk('runCase',['case_id'=>'policy','context'=>$office],1,'generate',static function()use(&$n){++$n;return resultBulk();});
checkBulk($n===0&&$r['provider_calls']===0&&$r['result_origin']==='policy','zero_call_policy');
$grade=['ok'=>true,'grade'=>'pass','usable_draft'=>true,'abstention'=>'none','review_required'=>false,'reason'=>'Уточнение неизвестных дат.','suspected_issues'=>[],'evidence_message_ids'=>[7]];
checkBulk(callBulk('validGrade',$grade,contextBulk()),'valid_grade');$bad=$grade;$bad['evidence_message_ids']=[8];checkBulk(!callBulk('validGrade',$bad,contextBulk()),'future_evidence_rejected');
$bad=$grade;$bad['abstention']='excessive';checkBulk(!callBulk('validGrade',$bad,contextBulk()),'contradictory_grade_rejected');
$bad=$grade;$bad['extra']='SYNTHETIC';checkBulk(!callBulk('validGrade',$bad,contextBulk()),'extra_fields_rejected');
$d=sys_get_temp_dir().'/bulk-synthetic-'.bin2hex(random_bytes(10));mkdir($d,0700);
try{
 $cases=[];for($i=0;$i<12;++$i)$cases[]=['case_id'=>'SYNTHETIC_'.$i,'context'=>contextBulk($i+1)];
 file_put_contents($d.'/counter','0,0');$output=[];
 $records=callBulk('parallel',$cases,3,'generate',$d,static function($r)use(&$output){$output[]=$r;},static function()use($d){
  $h=fopen($d.'/counter','r+');flock($h,LOCK_EX);[$n,$peak]=array_map('intval',explode(',',stream_get_contents($h)));++$n;$peak=max($peak,$n);rewind($h);ftruncate($h,0);fwrite($h,$n.','.$peak);fflush($h);flock($h,LOCK_UN);fclose($h);
  usleep(100000);
  $h=fopen($d.'/counter','r+');flock($h,LOCK_EX);[$n,$peak]=array_map('intval',explode(',',stream_get_contents($h)));--$n;rewind($h);ftruncate($h,0);fwrite($h,$n.','.$peak);fflush($h);flock($h,LOCK_UN);fclose($h);
  return resultBulk();
 });
 checkBulk(count($records)===12&&count($output)===12&&count(glob($d.'/result-*.json'))===12,'durable_parallel_records');
 [$n,$peak]=array_map('intval',explode(',',file_get_contents($d.'/counter')));checkBulk($n===0&&$peak===3,'actual_parallel_cap');
 foreach($records as $r)checkBulk($r['provider_calls']===1&&$r['result']['ok']===true,'one_call_per_worker');
 $rejected=false;try{callBulk('parallel',$cases,3,'generate',$d,static function(){},static function(){throw new RuntimeException('must_not_replay');});}catch(Throwable $e){$rejected=true;}checkBulk($rejected,'started_ledger_prevents_replay');
}finally{foreach(glob($d.'/*')?:[] as $p)unlink($p);rmdir($d);}
checkBulk(!class_exists('ConversationDb',false),'no_database_bootstrap');
echo "bulk synthetic checks PASS; canonical service, policy, grading, fork concurrency, persistence and no replay\n";
