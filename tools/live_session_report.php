<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$base=dirname(__DIR__);
require_once $base.'/config.php';
require_once $base.'/services/ConversationDb.php';
require_once $base.'/services/LiveSessionAnalyzer.php';

$minutes=max(15,min(1440,(int)($argv[1]??60)));
$pdo=ConversationDb::connection();
$q=$pdo->prepare("SELECT id,project_key,channel,status,started_at,last_message_at FROM conversations WHERE COALESCE(last_message_at,started_at)>=DATE_SUB(NOW(),INTERVAL ? MINUTE) ORDER BY COALESCE(last_message_at,started_at) DESC");
$q->execute([$minutes]);
$conversations=$q->fetchAll(PDO::FETCH_ASSOC);
$sessions=[];$funnel=['sessions'=>0,'started'=>0,'needs_collected'=>0,'tours_opened'=>0,'manager_requested'=>0,'manager_replied'=>0,'phone_received'=>0];$dropPoints=[];$flags=[];
foreach($conversations as $c){
    $mq=$pdo->prepare('SELECT direction,sender_type,text,created_at FROM messages WHERE conversation_id=? ORDER BY id');$mq->execute([(int)$c['id']]);$messages=$mq->fetchAll(PDO::FETCH_ASSOC);
    $eq=$pdo->prepare('SELECT event_type,actor_type,actor_id,created_at FROM conversation_events WHERE conversation_id=? ORDER BY id');$eq->execute([(int)$c['id']]);$events=$eq->fetchAll(PDO::FETCH_ASSOC);
    $s=LiveSessionAnalyzer::analyze($c,$messages,$events);$sessions[]=$s;$funnel['sessions']++;
    foreach(['started','needs_collected','tours_opened','manager_requested','manager_replied','phone_received'] as $k){if(!empty($s[$k]))$funnel[$k]++;}
    $dropPoints[$s['drop_point']]=($dropPoints[$s['drop_point']]??0)+1;
    foreach($s['flags'] as $flag)$flags[$flag]=($flags[$flag]??0)+1;
}
arsort($dropPoints);arsort($flags);
echo json_encode(['ok'=>true,'generated_at'=>gmdate('c'),'window_minutes'=>$minutes,'funnel'=>$funnel,'drop_points'=>$dropPoints,'flag_counts'=>$flags,'sessions'=>$sessions],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
