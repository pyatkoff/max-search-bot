<?php
declare(strict_types=1);
define('MAX_OWNER_PREVIEW_OFFLINE_TEST',true);
define('MAX_OWNER_INSPECT_OFFLINE_TEST',true);
require dirname(__DIR__).'/tools/max_owner_tour_preview.php';
require dirname(__DIR__).'/tools/max_owner_preview_inspect.php';
$checks=0;
function check($value): void { global $checks; $checks++;if (!$value) throw new RuntimeException('assertion '.$checks); }
$row=['id'=>'2','project_key'=>'anytour','channel'=>'max','original_display_name'=>'Pavel','status'=>'manager',
    'external_user_id'=>'123','conversation_chat_id'=>'-123','channel_chat_id'=>'-123','message_sender_id'=>'123','external_message_id'=>'mid.owner'];
$identity=['timestamp'=>1792540200000,'body'=>['mid'=>'mid.owner','text'=>'Пусто у меня'],'sender'=>['user_id'=>'123'],'recipient'=>['chat_type'=>'dialog','chat_id'=>'456','user_id'=>'789']];
$message=['timestamp'=>1792539644000,'sender'=>['user_id'=>'789'],'recipient'=>['user_id'=>'123','chat_id'=>'456','chat_type'=>'dialog'],
    'body'=>['text'=>ownerPreviewBody()['text'],'attachments'=>ownerPreviewBody()['attachments']]];
function inspect(array $row,array $identity,array $history,int $status=200): array {
    $calls=[];
    $result=previewInspectRun($row,'offline-token',static function($kind,$id,$token) use (&$calls,$identity,$history,$status) {
        $calls[]=[$kind,$id];
        return $kind==='identity'?['status'=>200,'data'=>$identity]:['status'=>$status,'data'=>$history];
    });
    return [$result,$calls];
}
[$r,$calls]=inspect($row,$identity,['messages'=>[$message]]);
check($r['inspection']==='matching_demo_found' && $r['matching_messages']===1);
check($calls===[['identity','mid.owner'],['history','456']] && $r['provider_requests']===2);
check($r['matching_cards']===[['images'=>3,'attachments'=>4,'like_message_button'=>true]]);
check(!str_contains(json_encode($r),'offline-token') && !str_contains(json_encode($r),'Delphin') && !str_contains(json_encode($r),'mid.owner'));
[$r,$calls]=inspect(array_replace($row,['external_user_id'=>'999']),$identity,['messages'=>[$message]]);
check($calls===[] && $r['inspection']==='inconclusive');
[$r,$calls]=inspect($row,array_replace_recursive($identity,['recipient'=>['chat_type'=>'chat']]),['messages'=>[$message]]);
check(count($calls)===1 && $r['inspection']==='inconclusive');
[$r,$calls]=inspect($row,$identity,[],403);
check(count($calls)===2 && $r['history_status']===403 && $r['inspection']==='inconclusive');
[$r,$calls]=inspect($row,$identity,['messages'=>[]]);
check(count($calls)===2 && $r['inspection']==='no_bot_demo_evidence_in_send_window' && $r['matching_messages']===0);
foreach ([['sender'=>['user_id'=>'123']],['recipient'=>['user_id'=>'999']],['recipient'=>['chat_id'=>'999']],['body'=>['text'=>'different']],['timestamp'=>1792539611999]] as $change) {
    [$r]=inspect($row,$identity,['messages'=>[array_replace_recursive($message,$change)]]);
    check($r['matching_messages']===0);
}
[$r]=inspect($row,$identity,['messages'=>array_fill(0,101,$message)]);
check($r['inspection']==='inconclusive' && $r['matching_messages']===0);
[$r]=inspect($row,$identity,['messages'=>array_fill(0,100,$message)]);
check($r['window_may_be_truncated'] && $r['matching_messages']===100);
[$r,$calls]=inspect($row,array_replace_recursive($identity,['body'=>['text'=>'Другая фраза']]),['messages'=>[$message]]);
check(count($calls)===1 && !$r['owner_inbound_contains_empty'] && $r['inspection']==='inconclusive');
[$r,$calls]=inspect($row,array_replace($identity,['timestamp'=>1792540058999]),['messages'=>[$message]]);
check(count($calls)===1 && !$r['owner_inbound_fresh']);
[$r]=inspect($row,$identity,['messages'=>[array_replace($message,['timestamp'=>1792539674001])]]);
check(!$r['all_returned_within_window'] && !$r['window_not_truncated'] && $r['inspection']==='inconclusive');
[$r]=inspect($row,$identity,['messages'=>[]]);
check($r['window_not_truncated'] && $r['all_returned_within_window'] && $r['window_min_timestamp_ms']===null);
[$r]=inspect($row,$identity,['messages'=>[array_replace_recursive($message,['body'=>['text'=>'Delphin  Imperial — оформление']])]]);
check($r['matching_messages']===0 && $r['bot_messages_in_window']===1 && $r['demo_like_messages_in_window']===1 && $r['media_messages_in_window']===1 && $r['inspection']==='inconclusive');
[$r]=inspect($row,$identity,['messages'=>[array_replace_recursive($message,['body'=>['text'=>'Обычное сообщение','attachments'=>[]]])]]);
check($r['bot_messages_in_window']===1 && $r['inspection']==='inconclusive');
$missingRecipient=$message;unset($missingRecipient['recipient']['user_id']);
[$r]=inspect($row,$identity,['messages'=>[$missingRecipient]]);
check($r['bot_messages_in_window']===1 && $r['matching_messages']===0 && $r['inspection']==='inconclusive');
[$r]=inspect($row,$identity,['messages'=>[array_replace_recursive($message,['recipient'=>['user_id'=>'999']])]]);
check($r['bot_messages_in_window']===1 && $r['matching_messages']===0 && $r['inspection']==='inconclusive');
$source=file_get_contents(dirname(__DIR__).'/tools/max_owner_preview_inspect.php');
check(!preg_match('/CURLOPT_(?:POST|POSTFIELDS|CUSTOMREQUEST)|ownerPreviewRun\(|ownerPreviewWrite\(|fwrite\(|ftruncate\(|unlink\(/',$source));
check(str_contains($source,'CURLOPT_HTTPGET=>true') && str_contains($source,'strictCurlOptions()') && str_contains($source,'CURLOPT_FOLLOWLOCATION=>false'));
check(str_contains($source,"'from'=>1792539674000,'to'=>1792539612000,'count'=>100"));
echo "OK {$checks} focused inspection checks\n";
