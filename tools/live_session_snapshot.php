<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once $baseDir.'/services/ConversationDb.php';
require_once $baseDir.'/services/LiveSessionAnalyzer.php';

$hours=isset($argv[1])?max(1,min(24,(int)$argv[1])):1;
$result=[
    'ok'=>false,
    'generated_at'=>gmdate('c'),
    'window_hours'=>$hours,
    'channel'=>'max',
    'summary'=>[],
    'sessions'=>[],
];

try{
    if(!ConversationDb::isConfigured()) throw new RuntimeException('conversation_db_not_configured');
    $pdo=ConversationDb::connection();
    $since=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('-'.$hours.' hours')->format('Y-m-d H:i:s');

    $q=$pdo->prepare("SELECT id,project_key,channel,status,manager_id,started_at,last_message_at FROM conversations WHERE channel='max' AND COALESCE(last_message_at,started_at)>=? ORDER BY COALESCE(last_message_at,started_at) ASC");
    $q->execute([$since]);
    $conversations=$q->fetchAll(PDO::FETCH_ASSOC);

    $sessions=[];
    foreach($conversations as $conversation){
        $id=(int)$conversation['id'];
        $mq=$pdo->prepare('SELECT direction,sender_type,text,created_at FROM messages WHERE conversation_id=? ORDER BY id ASC');
        $mq->execute([$id]);
        $messages=$mq->fetchAll(PDO::FETCH_ASSOC);
        $eq=$pdo->prepare('SELECT event_type,actor_type,actor_id,created_at FROM conversation_events WHERE conversation_id=? ORDER BY id ASC');
        $eq->execute([$id]);
        $events=$eq->fetchAll(PDO::FETCH_ASSOC);
        $sessions[]=LiveSessionAnalyzer::analyze($conversation,$messages,$events);
    }

    $summary=[
        'sessions'=>count($sessions),
        'started'=>0,
        'needs_collected'=>0,
        'tours_opened'=>0,
        'manager_requested'=>0,
        'manager_replied'=>0,
        'phone_received'=>0,
        'flagged_sessions'=>0,
        'manager_response'=>[
            'answered_in_90s'=>0,
            'answered_after_90s'=>0,
            'still_unanswered'=>0,
            'measured_responses'=>0,
            'avg_seconds'=>null,
            'max_seconds'=>null,
        ],
        'drop_points'=>[],
        'flags'=>[],
    ];
    $responseSeconds=[];
    foreach($sessions as $session){
        foreach(['started','needs_collected','tours_opened','manager_requested','manager_replied','phone_received'] as $key){if(!empty($session[$key]))$summary[$key]++;}
        if(!empty($session['flags']))$summary['flagged_sessions']++;
        $bucket=(string)($session['manager_response_bucket']??'');
        if($bucket!==''&&array_key_exists($bucket,$summary['manager_response']))$summary['manager_response'][$bucket]++;
        if(isset($session['manager_response_seconds'])&&$session['manager_response_seconds']!==null)$responseSeconds[]=(int)$session['manager_response_seconds'];
        $drop=(string)($session['drop_point']??'unknown');
        $summary['drop_points'][$drop]=($summary['drop_points'][$drop]??0)+1;
        foreach((array)($session['flags']??[]) as $flag)$summary['flags'][$flag]=($summary['flags'][$flag]??0)+1;
    }
    if($responseSeconds){
        $summary['manager_response']['measured_responses']=count($responseSeconds);
        $summary['manager_response']['avg_seconds']=(int)round(array_sum($responseSeconds)/count($responseSeconds));
        $summary['manager_response']['max_seconds']=max($responseSeconds);
    }
    arsort($summary['drop_points']);
    arsort($summary['flags']);

    $result['since_utc']=$since;
    $result['summary']=$summary;
    $result['sessions']=$sessions;
    $result['ok']=true;
}catch(Throwable $e){
    $result['error']=get_class($e).': '.$e->getMessage();
}

echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
exit($result['ok']?0:1);
