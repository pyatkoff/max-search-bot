<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once $baseDir.'/services/ConversationDb.php';
require_once $baseDir.'/services/LiveSessionAnalyzer.php';

function liveDiagnosticMessageTail(array $messages,int $limit=24):array
{
    $tail=array_slice($messages,-max(1,$limit));
    return array_map(static function(array $message):array{
        $text=preg_replace('/\s+/u',' ',trim((string)($message['text']??'')))??'';
        if(mb_strlen($text)>280)$text=mb_substr($text,0,277).'...';
        return [
            'direction'=>(string)($message['direction']??''),
            'sender_type'=>(string)($message['sender_type']??''),
            'text'=>$text,
            'created_at'=>$message['created_at']??null,
        ];
    },$tail);
}

function liveDiagnosticWindowMessages(array $messages,int $sinceTs):array
{
    return array_values(array_filter($messages,static function(array $message)use($sinceTs):bool{
        $created=trim((string)($message['created_at']??''));
        if($created==='')return true;
        $ts=strtotime($created);
        return $ts===false||$ts>=$sinceTs;
    }));
}

function liveDiagnosticBefore(array $items,int $untilTs):array
{
    return array_values(array_filter($items,static function(array $item)use($untilTs):bool{
        $created=trim((string)($item['created_at']??''));
        if($created==='')return true;
        $ts=strtotime($created);
        return $ts===false||$ts<$untilTs;
    }));
}

function liveDiagnosticTs($value):?int
{
    $value=trim((string)$value);
    if($value==='')return null;
    $ts=strtotime($value);
    return $ts===false?null:$ts;
}

$windowArg=(string)($argv[1]??'1');
$calendarDay=in_array($windowArg,['today','yesterday'],true);
$hours=$calendarDay?null:max(1,min(24,(int)$windowArg));
$result=[
    'ok'=>false,
    'generated_at'=>gmdate('c'),
    'window_type'=>$calendarDay?'calendar_day':'rolling_hours',
    'window_hours'=>$hours,
    'timezone'=>$calendarDay?'Europe/Kaliningrad':'UTC',
    'channel'=>'max',
    'summary'=>[],
    'sessions'=>[],
];

try{
    if(!ConversationDb::isConfigured()) throw new RuntimeException('conversation_db_not_configured');
    $pdo=ConversationDb::connection();
    $until=null;
    if($calendarDay){
        $tz=new DateTimeZone('Europe/Kaliningrad');
        $localNow=new DateTimeImmutable('now',$tz);
        $targetDay=$windowArg==='yesterday'?$localNow->modify('-1 day'):$localNow;
        $localStart=$targetDay->setTime(0,0,0);
        $localEnd=$localStart->modify('+1 day');
        $since=$localStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $until=$localEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $result['local_date']=$targetDay->format('Y-m-d');
        $result['local_day_started_at']=$localStart->format(DateTimeInterface::ATOM);
        $result['local_day_ended_at']=$localEnd->format(DateTimeInterface::ATOM);
    } else {
        $since=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('-'.$hours.' hours')->format('Y-m-d H:i:s');
    }
    $sinceTs=strtotime($since.' UTC');
    $untilTs=$until!==null?strtotime($until.' UTC'):null;
    if($sinceTs===false)throw new RuntimeException('invalid_diagnostic_window');

    $sql="SELECT id,project_key,channel,status,is_test,test_source,test_reason,manager_id,started_at,last_message_at FROM conversations WHERE channel='max' AND COALESCE(last_message_at,started_at)>=?";
    $params=[$since];
    if($until!==null){$sql.=' AND started_at<?';$params[]=$until;}
    $sql.=' ORDER BY COALESCE(last_message_at,started_at) ASC';
    $q=$pdo->prepare($sql);
    $q->execute($params);
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
        if($untilTs!==null){
            $messages=liveDiagnosticBefore($messages,$untilTs);
            $events=liveDiagnosticBefore($events,$untilTs);
        }
        $session=LiveSessionAnalyzer::analyze($conversation,$messages,$events,$sinceTs);
        if(!empty($session['flags'])){
            $evidence=liveDiagnosticWindowMessages($messages,$sinceTs);
            $session['message_tail']=liveDiagnosticMessageTail($evidence?:$messages);
        }
        $sessions[]=$session;
    }

    $businessSessions=array_values(array_filter($sessions,static fn(array $session):bool=>empty($session['is_test'])));
    $summary=[
        'sessions'=>count($businessSessions),
        'raw_sessions'=>count($sessions),
        'explicit_test_sessions'=>count($sessions)-count($businessSessions),
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
    if($calendarDay){
        $summary['calendar_day']=[
            'conversations_started'=>0,
            'needs_collected_from_started'=>0,
            'tours_opened_from_started'=>0,
            'manager_requested'=>0,
            'manager_replied'=>0,
        ];
    }
    $responseSeconds=[];
    foreach($businessSessions as $session){
        foreach(['started','needs_collected','tours_opened','manager_requested','manager_replied','phone_received'] as $key){if(!empty($session[$key]))$summary[$key]++;}
        if(!empty($session['flags']))$summary['flagged_sessions']++;
        $bucket=(string)($session['manager_response_bucket']??'');
        if($bucket!==''&&array_key_exists($bucket,$summary['manager_response']))$summary['manager_response'][$bucket]++;
        if(isset($session['manager_response_seconds'])&&$session['manager_response_seconds']!==null)$responseSeconds[]=(int)$session['manager_response_seconds'];
        $drop=(string)($session['drop_point']??'unknown');
        $summary['drop_points'][$drop]=($summary['drop_points'][$drop]??0)+1;
        foreach((array)($session['flags']??[]) as $flag)$summary['flags'][$flag]=($summary['flags'][$flag]??0)+1;

        if($calendarDay){
            $startedAt=liveDiagnosticTs($session['started_at']??null);
            $startedInDay=$startedAt!==null&&$startedAt>=$sinceTs&&($untilTs===null||$startedAt<$untilTs);
            if($startedInDay){
                $summary['calendar_day']['conversations_started']++;
                if(!empty($session['needs_collected']))$summary['calendar_day']['needs_collected_from_started']++;
                if(!empty($session['tours_opened']))$summary['calendar_day']['tours_opened_from_started']++;
            }
            $requestAt=liveDiagnosticTs($session['manager_request_at']??null);
            if($requestAt!==null&&$requestAt>=$sinceTs&&($untilTs===null||$requestAt<$untilTs))$summary['calendar_day']['manager_requested']++;
            $replyAt=liveDiagnosticTs($session['manager_first_reply_at']??null);
            if($replyAt!==null&&$replyAt>=$sinceTs&&($untilTs===null||$replyAt<$untilTs))$summary['calendar_day']['manager_replied']++;
        }
    }
    if($responseSeconds){
        $summary['manager_response']['measured_responses']=count($responseSeconds);
        $summary['manager_response']['avg_seconds']=(int)round(array_sum($responseSeconds)/count($responseSeconds));
        $summary['manager_response']['max_seconds']=max($responseSeconds);
    }
    arsort($summary['drop_points']);
    arsort($summary['flags']);

    $result['since_utc']=$since;
    if($until!==null)$result['until_utc']=$until;
    $result['summary']=$summary;
    $result['sessions']=$sessions;
    $result['ok']=true;
}catch(Throwable $e){
    $result['error']=get_class($e).': '.$e->getMessage();
}

echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
exit($result['ok']?0:1);
