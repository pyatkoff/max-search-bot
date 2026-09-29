<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(2);}
$root='/var/www/anytoour/data/www/app.anytoour.ru';
require_once $root.'/config.php';
require_once $root.'/services/ConversationDb.php';
require_once $root.'/services/ProjectConfig.php';
require_once $root.'/services/LiveSessionAnalyzer.php';

$tz=new DateTimeZone('Europe/Kaliningrad');
$utc=new DateTimeZone('UTC');
$from=(new DateTimeImmutable('2026-09-23 00:00:00',$tz))->setTimezone($utc);
$now=new DateTimeImmutable('now',$utc);
$localNow=$now->setTimezone($tz);
$until=$localNow->setTime(0,0)->modify('+1 day')->setTimezone($utc);
$selected=['714260445','714320748'];

$out=['ok'=>false,'generated_at'=>$now->format('c'),'timezone'=>'Europe/Kaliningrad',
 'period'=>['from'=>$from->format('c'),'until'=>$until->format('c'),'current_day_partial'=>true],
 'campaigns'=>[],'all'=>[],'quality'=>[],'server_writes'=>false,'advertising_writes'=>false,
 'database_opened'=>true,'token_exported'=>false];

try{
 $pdo=ConversationDb::connection();
 $pdo->exec('SET TRANSACTION READ ONLY');
 $pdo->beginTransaction();
 $project=ProjectConfig::projectId();
 $q=$pdo->prepare("SELECT id,project_key,source_id,channel,status,is_test,test_source,test_reason,external_chat_id,attribution_campaign,started_at,last_message_at FROM conversations WHERE project_key=? AND channel='max' AND is_test=0 AND started_at>=? AND started_at<? ORDER BY started_at,id LIMIT 5001");
 $q->execute([$project,$from->format('Y-m-d H:i:s'),$until->format('Y-m-d H:i:s')]);
 $conversations=$q->fetchAll(PDO::FETCH_ASSOC);
 if(count($conversations)>5000)throw new RuntimeException('conversation_limit');

 $mq=$pdo->prepare('SELECT direction,sender_type,text,created_at FROM messages WHERE conversation_id=? AND created_at>=? AND created_at<? ORDER BY created_at,id LIMIT 1001');
 $eq=$pdo->prepare('SELECT event_type,created_at FROM conversation_events WHERE conversation_id=? AND created_at>=? AND created_at<? ORDER BY created_at,id LIMIT 1001');
 $startq=$pdo->prepare("SELECT COUNT(*) AS events,COUNT(DISTINCT conversation_id) AS conversations FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id WHERE c.project_key=? AND c.channel='max' AND c.is_test=0 AND e.event_type='bot_started' AND e.created_at>=? AND e.created_at<? AND COALESCE(NULLIF(c.attribution_campaign,''),'not_recorded')=?");

 $fields=['new_dialogs','started_conversations','needs_collected','show_tours','bot_started_events','bot_started_conversations'];
 $blank=function()use($fields){$r=[];foreach($fields as $f)$r[$f]=0;return $r;};
 $all=$blank();$by=[];$days=[];

 foreach($conversations as $c){
   $began=new DateTimeImmutable((string)$c['started_at'],$utc);
   $day=$began->setTimezone($tz)->format('Y-m-d');
   $cid=trim((string)($c['attribution_campaign']??'')); if($cid==='')$cid='not_recorded';
   if(!isset($by[$cid]))$by[$cid]=$blank();
   if(!isset($days[$day]))$days[$day]=[];
   if(!isset($days[$day][$cid]))$days[$day][$cid]=$blank();
   foreach([&$all,&$by[$cid],&$days[$day][$cid]] as &$bucket)$bucket['new_dialogs']++;
   unset($bucket);

   $dayEnd=$began->setTimezone($tz)->setTime(0,0)->modify('+1 day')->setTimezone($utc);
   $end=$dayEnd<$until?$dayEnd:$until;
   $args=[(int)$c['id'],$began->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')];
   $mq->execute($args);$messages=$mq->fetchAll(PDO::FETCH_ASSOC);
   $eq->execute($args);$events=$eq->fetchAll(PDO::FETCH_ASSOC);
   if(count($messages)>1000||count($events)>1000)throw new RuntimeException('evidence_limit');
   foreach($messages as &$m)$m['created_at'].=' UTC';unset($m);
   foreach($events as &$e)$e['created_at'].=' UTC';unset($e);
   $c['status']='';
   $s=LiveSessionAnalyzer::analyze($c,$messages,$events);
   foreach(['started'=>'started_conversations','needs_collected'=>'needs_collected','tours_opened'=>'show_tours'] as $src=>$dst){
     if(!empty($s[$src])){ $all[$dst]++;$by[$cid][$dst]++;$days[$day][$cid][$dst]++; }
   }
 }
 $campaignKeys=array_values(array_unique(array_merge(array_keys($by),$selected,['not_recorded'])));
 foreach($campaignKeys as $cid){
   if(!isset($by[$cid]))$by[$cid]=$blank();
   $startq->execute([$project,$from->format('Y-m-d H:i:s'),$until->format('Y-m-d H:i:s'),$cid]);
   $r=$startq->fetch(PDO::FETCH_ASSOC)?:[];
   $by[$cid]['bot_started_events']=(int)($r['events']??0);
   $by[$cid]['bot_started_conversations']=(int)($r['conversations']??0);
 }
 $q2=$pdo->prepare("SELECT COUNT(*) AS events,COUNT(DISTINCT e.conversation_id) AS conversations FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id WHERE c.project_key=? AND c.channel='max' AND c.is_test=0 AND e.event_type='bot_started' AND e.created_at>=? AND e.created_at<?");
 $q2->execute([$project,$from->format('Y-m-d H:i:s'),$until->format('Y-m-d H:i:s')]);
 $r=$q2->fetch(PDO::FETCH_ASSOC)?:[];$all['bot_started_events']=(int)($r['events']??0);$all['bot_started_conversations']=(int)($r['conversations']??0);

 $pdo->rollBack();
 ksort($by);ksort($days);
 $out['project']=$project;$out['all']=$all;$out['campaigns']=$by;$out['days']=$days;

 // Cross-check actual bot_started webhook events from the existing append-only funnel journal.
 // This journal historically predates the conversation_events mirror, so it is the authoritative start-event source for this window.
 $journal=['all'=>['events'=>0,'users'=>0],'campaigns'=>[]];$journalUsers=[];$campaignUsers=[];
 $path=$root.'/funnel.csv';
 if(is_file($path)&&is_readable($path)){
   $fh=fopen($path,'rb');$header=fgetcsv($fh);
   $idx=is_array($header)?array_flip($header):[];
   foreach(['DateTime','ChatID','CampaignID','Event'] as $need)if(!isset($idx[$need]))throw new RuntimeException('funnel_header');
   while(($row=fgetcsv($fh))!==false){
     if(count($row)<=max($idx))continue;
     if((string)$row[$idx['Event']]!=='bot_started')continue;
     $stamp=(string)$row[$idx['DateTime']];$date=substr($stamp,0,10);
     if($date<'2026-09-23'||$date>'2026-09-29')continue;
     $cid=trim((string)$row[$idx['CampaignID']]);if($cid==='')$cid='not_recorded';
     $chat=(string)$row[$idx['ChatID']];
     if(!isset($journal['campaigns'][$cid]))$journal['campaigns'][$cid]=['events'=>0,'users'=>0];
     $journal['campaigns'][$cid]['events']++;$journal['all']['events']++;
     if($chat!==''){$journalUsers[$chat]=true;$campaignUsers[$cid][$chat]=true;}
   }
   fclose($fh);
   $journal['all']['users']=count($journalUsers);
   foreach($campaignUsers as $cid=>$users)$journal['campaigns'][$cid]['users']=count($users);
   ksort($journal['campaigns']);
 }
 $out['bot_started_journal']=$journal;
 $out['quality']=['conversation_count'=>count($conversations),'selected_campaigns'=>$selected,
   'attribution_basis'=>'current conversations.attribution_campaign',
   'outcome_basis'=>'same local day as conversation start until capture',
   'bot_start_basis'=>'conversation_events.event_type=bot_started',
   'parameters_basis'=>'LiveSessionAnalyzer.needs_collected',
   'show_tours_basis'=>'LiveSessionAnalyzer.tours_opened'];
 $out['ok']=true;
}catch(Throwable $e){
 if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
 $out['error_class']=get_class($e);
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
exit($out['ok']?0:1);
