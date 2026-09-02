<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__).'/services/CallbackGeneration.php';

$inputPath=$argv[1]??'';
if($inputPath===''||!is_file($inputPath)){
    fwrite(STDERR,"Usage: php tools/compose_live_anomalies.php <live_session_snapshot.json>\n");
    exit(2);
}
$data=json_decode((string)file_get_contents($inputPath),true);
if(!is_array($data)||empty($data['ok'])){
    fwrite(STDERR,"Invalid live session snapshot\n");
    exit(2);
}

function anomalyText(array $m):string{return mb_strtolower(trim((string)($m['text']??'')));}
function anomalyCustomer(array $m):bool{return ($m['direction']??'')==='inbound'&&($m['sender_type']??'customer')!=='manager';}
function anomalyBot(array $m):bool{return ($m['direction']??'')==='outbound'&&($m['sender_type']??'')!=='manager';}
function anomalyCallbackInput(string $text):bool{
    $text=CallbackGeneration::base($text);
    if($text==='')return false;
    if(in_array($text,['start_search','show_tours','restart','month_click','day_click'],true))return true;
    return (bool)preg_match('/^(?:pick_|month_change_|adult_|adults_|child_|star_|meal_|nights_|city_|country_|edit_|manager_|search_|back_)/',$text);
}
function anomalyNormalize(string $text):string{
    $text=mb_strtolower($text);
    $text=preg_replace('/[^\p{L}\p{N}]+/u',' ',trim($text))??'';
    return trim(preg_replace('/\s+/u',' ',$text)??$text);
}
function anomalyPromptKey(string $text):?string{
    $checks=[
        'country'=>'/(?:какую стран|страна|куда.*хотите)/u',
        'departure'=>'/(?:город.*вылет|откуда.*вылет|вылетаем)/u',
        'date'=>'/(?:дата|когда.*лет|вылет.*дат)/u',
        'nights'=>'/(?:сколько ноч|ночей)/u',
        'tourists'=>'/(?:сколько.*турист|взросл|детей|ребен)/u',
        'stars'=>'/(?:звезд|категори.*отел)/u',
        'meal'=>'/(?:питан|завтрак|все включено)/u',
    ];
    foreach($checks as $key=>$rx)if(preg_match($rx,$text))return $key;
    return null;
}

$out=[
    'ok'=>true,
    'schema_version'=>2,
    'generated_at'=>gmdate('c'),
    'source_generated_at'=>$data['generated_at']??null,
    'window_hours'=>$data['window_hours']??null,
    'summary'=>['sessions'=>count((array)($data['sessions']??[])),'ranked'=>0,'high'=>0,'medium'=>0],
    'anomalies'=>[],
];

foreach((array)($data['sessions']??[]) as $session){
    $messages=(array)($session['message_tail']??[]);
    if(!$messages)continue;
    $signals=[];$contextSignals=[];$score=0;$customerCounts=[];$promptCounts=[];
    foreach($messages as $m){
        $text=anomalyText($m); if($text==='')continue;
        if(anomalyCustomer($m)){
            if(!anomalyCallbackInput($text)){
                $norm=anomalyNormalize($text);
                if(mb_strlen($norm)>=2)$customerCounts[$norm]=($customerCounts[$norm]??0)+1;
            }
            if(preg_match('/(?:завис|заклини|не работает|сломал|по кругу|опять спраш|уже (?:писал|ответил|сказал)|повторя)/u',$text)){
                $signals['customer_reports_stuck']=true;$score=max($score,100);
            }
        }elseif(anomalyBot($m)){
            $key=anomalyPromptKey($text);
            if($key!==null)$promptCounts[$key]=($promptCounts[$key]??0)+1;
        }
    }
    foreach($customerCounts as $text=>$count){
        if($count>=2){$signals['customer_repeated_answer']=true;$score=max($score,90);break;}
    }
    if(!empty($session['needs_collected'])&&empty($session['tours_opened'])){
        $signals['needs_collected_without_tours']=true;$score=max($score,80);
    }
    foreach((array)($session['flags']??[]) as $flag){
        if($flag==='rapid_date_reselection'){$contextSignals[$flag]=true;}
        if($flag==='repeated_same_input'){$signals[$flag]=true;$score=max($score,90);}
        if($flag==='repeated_callback_input'){$contextSignals[$flag]=true;}
        if($flag==='manager_taken_no_reply'||$flag==='left_waiting_queue_without_manager_reply'){$signals[$flag]=true;$score=max($score,95);}
        // Calendar reselection, repeated callbacks, excessive turns and ordinary manager wait are context only without user-visible failure evidence.
    }
    if(!$signals)continue;
    foreach($contextSignals as $key=>$present)if($present)$signals[$key]=true;
    // Repeated bot prompts are useful context only after an independent signal makes the session actionable.
    foreach($promptCounts as $key=>$count)if($count>=2)$signals['bot_repeated_question_'.$key]=true;
    $severity=$score>=90?'high':'medium';
    $out['anomalies'][]=[
        'conversation_id'=>(int)($session['conversation_id']??0),
        'severity'=>$severity,
        'score'=>$score,
        'signals'=>array_keys($signals),
        'status'=>$session['status']??null,
        'drop_point'=>$session['drop_point']??null,
        'last_message_at'=>$session['last_message_at']??null,
    ];
}
usort($out['anomalies'],static fn(array $a,array $b):int=>($b['score']<=>$a['score'])?:strcmp((string)$b['last_message_at'],(string)$a['last_message_at']));
$out['anomalies']=array_slice($out['anomalies'],0,12);
$out['summary']['ranked']=count($out['anomalies']);
foreach($out['anomalies'] as $anomaly)$out['summary'][$anomaly['severity']]++;

echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
