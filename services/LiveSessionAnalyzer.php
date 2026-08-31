<?php

declare(strict_types=1);

final class LiveSessionAnalyzer
{
    private const EXCESSIVE_INBOUND_TURNS = 18;

    private static function ts($value): ?int
    {
        $value=trim((string)$value);
        if($value==='')return null;
        $ts=strtotime($value);
        return $ts===false?null:$ts;
    }

    private static function isCallbackInput(string $text): bool
    {
        if($text==='')return false;
        if(in_array($text,['start_search','show_tours','restart','month_click','day_click'],true))return true;
        return (bool)preg_match('/^(?:pick_|month_change_|adult_|adults_|child_|star_|meal_|nights_|city_|country_|edit_|manager_|search_|back_)/',$text);
    }

    private static function isPassiveCallback(string $text): bool
    {
        return $text==='month_click'||$text==='day_click'||strpos($text,'month_change_')===0;
    }

    private static function containsPhone(string $text): bool
    {
        return (bool)preg_match('/(?<!\d)(?:\+7|7|8)[\s\-\(\)]*(?:\d[\s\-\(\)]*){10}(?!\d)/u',$text);
    }

    public static function analyze(array $conversation,array $messages,array $events=[],?int $anomalySinceTs=null):array
    {
        $inbound=[];$outbound=[];$datePicks=0;$anomalyDatePickTimes=[];$showTours=false;$phone=false;$repeatedFreeText=[];$repeatedCallbacks=[];$flags=[];
        $managerMessageTimes=[];$anomalyInboundTurns=0;
        foreach($messages as $m){
            $text=trim((string)($m['text']??''));
            $messageTs=self::ts($m['created_at']??null);
            $inAnomalyWindow=$anomalySinceTs===null||$messageTs===null||$messageTs>=$anomalySinceTs;
            if(($m['direction']??'')==='inbound'){
                $inbound[]=$text;
                if(strpos($text,'pick_date_')===0){
                    $datePicks++;
                    if($inAnomalyWindow&&$messageTs!==null)$anomalyDatePickTimes[]=$messageTs;
                }
                if($text==='show_tours')$showTours=true;
                if(self::containsPhone($text))$phone=true;
                if($inAnomalyWindow){
                    $anomalyInboundTurns++;
                    if($text!==''){
                        if(self::isCallbackInput($text)){
                            if(!self::isPassiveCallback($text))$repeatedCallbacks[$text]=($repeatedCallbacks[$text]??0)+1;
                        } else {
                            $repeatedFreeText[$text]=($repeatedFreeText[$text]??0)+1;
                        }
                    }
                }
            } else {
                $outbound[]=$text;
                if(($m['sender_type']??'')==='manager'&&$messageTs!==null)$managerMessageTimes[]=$messageTs;
            }
        }

        $eventTypes=[];$requestTimes=[];$managerTaken=false;
        foreach($events as $e){
            $type=(string)($e['event_type']??'');
            $eventTypes[]=$type;
            $typeNorm=strtolower($type);
            if($typeNorm==='waiting_manager' || (strpos($typeNorm,'manager')!==false&&strpos($typeNorm,'request')!==false)){
                $ts=self::ts($e['created_at']??null);
                if($ts!==null)$requestTimes[]=$ts;
            }
            if($typeNorm==='manager_taken')$managerTaken=true;
        }

        $status=(string)($conversation['status']??'');
        $managerRequested=in_array($status,['waiting_manager','manager'],true) || count($requestTimes)>0;
        if(!$managerRequested){
            foreach($eventTypes as $type){if(stripos($type,'manager')!==false&&stripos($type,'request')!==false){$managerRequested=true;break;}}
        }
        $managerRequestActive=$managerRequested&&in_array($status,['waiting_manager','manager'],true);

        $managerRequestAt=$requestTimes?max($requestTimes):null;
        $managerFirstReplyAt=null;
        if($managerRequestAt!==null){
            foreach($managerMessageTimes as $ts){if($ts >= $managerRequestAt){$managerFirstReplyAt=$ts;break;}}
        } elseif($managerMessageTimes){
            $managerFirstReplyAt=$managerMessageTimes[0];
        }
        $managerReplied=$managerRequested && $managerFirstReplyAt!==null;
        $managerResponseSeconds=($managerRequestAt!==null&&$managerFirstReplyAt!==null)?max(0,$managerFirstReplyAt-$managerRequestAt):null;
        $managerResponseBucket=null;
        if($managerResponseSeconds!==null)$managerResponseBucket=$managerResponseSeconds<=90?'answered_in_90s':'answered_after_90s';
        elseif($managerRequestActive)$managerResponseBucket='still_unanswered';

        $needsCollected=$showTours;
        foreach($outbound as $text){if(stripos($text,'Готово! Проверьте параметры')!==false)$needsCollected=true;}
        $started=count($inbound)>0;
        if(count($anomalyDatePickTimes)>=3){
            sort($anomalyDatePickTimes);
            for($i=2,$n=count($anomalyDatePickTimes);$i<$n;$i++){
                if(($anomalyDatePickTimes[$i]-$anomalyDatePickTimes[$i-2])<=10){$flags[]='rapid_date_reselection';break;}
            }
        }
        foreach($repeatedCallbacks as $text=>$count){if($count>=3){$flags[]='repeated_callback_input';break;}}
        foreach($repeatedFreeText as $text=>$count){if($count>=3){$flags[]='repeated_same_input';break;}}
        if($anomalyInboundTurns>=self::EXCESSIVE_INBOUND_TURNS)$flags[]='excessive_turns';
        if($managerRequestActive&&!$managerReplied)$flags[]='manager_requested_no_reply';
        if($managerRequestActive&&!$managerReplied&&$status!=='waiting_manager')$flags[]='left_waiting_queue_without_manager_reply';
        if($managerTaken&&$managerRequestActive&&!$managerReplied)$flags[]='manager_taken_no_reply';

        $drop='started_only';
        if($started)$drop='collecting_needs';
        if($needsCollected)$drop='needs_collected';
        if($showTours)$drop='tours_opened';
        if($managerRequested)$drop='manager_requested';
        if($managerReplied)$drop='manager_replied';
        return [
            'conversation_id'=>(int)($conversation['id']??0),
            'project_key'=>(string)($conversation['project_key']??''),
            'channel'=>(string)($conversation['channel']??''),
            'status'=>$status,
            'is_test'=>!empty($conversation['is_test']),
            'test_source'=>(string)($conversation['test_source']??''),
            'test_reason'=>(string)($conversation['test_reason']??''),
            'started'=>$started,
            'needs_collected'=>$needsCollected,
            'tours_opened'=>$showTours,
            'manager_requested'=>$managerRequested,
            'manager_request_active'=>$managerRequestActive,
            'manager_replied'=>$managerReplied,
            'manager_request_at'=>$managerRequestAt!==null?gmdate('Y-m-d H:i:s',$managerRequestAt):null,
            'manager_first_reply_at'=>$managerFirstReplyAt!==null?gmdate('Y-m-d H:i:s',$managerFirstReplyAt):null,
            'manager_response_seconds'=>$managerResponseSeconds,
            'manager_response_bucket'=>$managerResponseBucket,
            'phone_received'=>$phone,
            'inbound_messages'=>count($inbound),
            'outbound_messages'=>count($outbound),
            'date_picks'=>$datePicks,
            'anomaly_inbound_messages'=>$anomalyInboundTurns,
            'drop_point'=>$drop,
            'flags'=>array_values(array_unique($flags)),
            'started_at'=>$conversation['started_at']??null,
            'last_message_at'=>$conversation['last_message_at']??null,
        ];
    }
}
