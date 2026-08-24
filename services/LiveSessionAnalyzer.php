<?php

declare(strict_types=1);

final class LiveSessionAnalyzer
{
    public static function analyze(array $conversation,array $messages,array $events=[]):array
    {
        $inbound=[];$outbound=[];$managerReplies=0;$datePicks=0;$showTours=false;$phone=false;$repeated=[];$flags=[];
        foreach($messages as $m){
            $text=trim((string)($m['text']??''));
            if(($m['direction']??'')==='inbound'){
                $inbound[]=$text;
                if(strpos($text,'pick_date_')===0)$datePicks++;
                if($text==='show_tours')$showTours=true;
                if(preg_match('/(?<!\d)(?:\+7|8)[\s\-\(\)]*(?:\d[\s\-\(\)]*){10}(?!\d)/u',$text))$phone=true;
                if($text!=='')$repeated[$text]=($repeated[$text]??0)+1;
            } else {
                $outbound[]=$text;
                if(($m['sender_type']??'')==='manager')$managerReplies++;
            }
        }
        $eventTypes=array_map(static fn($e)=>(string)($e['event_type']??''),$events);
        $status=(string)($conversation['status']??'');
        $managerRequested=in_array($status,['waiting_manager','manager'],true);
        foreach($eventTypes as $type){if(stripos($type,'manager')!==false&&stripos($type,'request')!==false)$managerRequested=true;}
        $needsCollected=$showTours;
        foreach($outbound as $text){if(stripos($text,'Готово! Проверьте параметры')!==false)$needsCollected=true;}
        $started=count($inbound)>0;
        $managerReplied=$managerReplies>0;
        if($datePicks>=3)$flags[]='rapid_date_reselection';
        foreach($repeated as $text=>$count){if($count>=3&&$text!==''){$flags[]='repeated_same_input';break;}}
        if(count($messages)>=24)$flags[]='excessive_turns';
        if($managerRequested&&!$managerReplied)$flags[]='manager_requested_no_reply';
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
            'started'=>$started,
            'needs_collected'=>$needsCollected,
            'tours_opened'=>$showTours,
            'manager_requested'=>$managerRequested,
            'manager_replied'=>$managerReplied,
            'phone_received'=>$phone,
            'inbound_messages'=>count($inbound),
            'outbound_messages'=>count($outbound),
            'date_picks'=>$datePicks,
            'drop_point'=>$drop,
            'flags'=>array_values(array_unique($flags)),
            'started_at'=>$conversation['started_at']??null,
            'last_message_at'=>$conversation['last_message_at']??null,
        ];
    }
}
