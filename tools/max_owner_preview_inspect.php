<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }

/** Aux-branch stdin only. Fixed GETs; no production file or receipt writes. */
function previewInspectGet(string $kind,string $identifier,string $token): array
{
    $valid=$kind==='identity'
        ? preg_match('/\A(?:mid\.)?[A-Za-z0-9_-]{1,200}\z/D',$identifier)
        : ($kind==='history' && MaxPrivateAvatarProbe::chatId($identifier)!==null);
    if (!$valid) return ['status'=>0,'data'=>null];
    $url='https://platform-api2.max.ru'.($kind==='identity'
        ? '/messages/'.rawurlencode($identifier)
        // MAX documents from as the upper boundary and to as the lower boundary (Unix ms).
        : '/messages?'.http_build_query(['chat_id'=>$identifier,'from'=>1792539674000,'to'=>1792539612000,'count'=>100]));
    $bytes='';$ch=curl_init($url);
    curl_setopt_array($ch,MaxTlsConfig::strictCurlOptions()+[
        CURLOPT_HTTPGET=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>['Authorization: '.$token],
        CURLOPT_WRITEFUNCTION=>static function($ch,string $part) use (&$bytes): int {
            if (strlen($bytes)+strlen($part)>262144) return 0;
            $bytes.=$part;return strlen($part);
        },
    ]);
    curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $ok=curl_errno($ch)===0 && (int)curl_getinfo($ch,CURLINFO_SSL_VERIFYRESULT)===0 && $status>=200 && $status<300;
    curl_close($ch);
    $data=$ok?json_decode($bytes,true,64,JSON_BIGINT_AS_STRING):null;
    return ['status'=>$status,'data'=>is_array($data)?$data:null];
}

function previewInspectTimestamp($value): ?int
{
    if (is_string($value) && preg_match('/\A[0-9]{1,13}\z/D',$value)) $value=(int)$value;
    return is_int($value) && $value>0 && $value<=4102444800000?$value:null;
}

function previewInspectRun(array $row,string $token,callable $get): array
{
    $out=['inspection'=>'inconclusive','provider_requests'=>0,'identity_status'=>0,'history_status'=>0,
        'identity_verified'=>false,'owner_inbound_timestamp_ms'=>null,'owner_inbound_contains_empty'=>false,'owner_inbound_fresh'=>false,
        'requested_window_lower_ms'=>1792539612000,'requested_window_upper_ms'=>1792539674000,
        'window_messages'=>0,'matching_messages'=>0,'matching_cards'=>[],'window_may_be_truncated'=>false,
        'bot_messages_in_window'=>0,'demo_like_messages_in_window'=>0,'media_messages_in_window'=>0,
        'window_min_timestamp_ms'=>null,'window_max_timestamp_ms'=>null,'all_returned_within_window'=>false,'window_not_truncated'=>false];
    if (($row['id']??null)!==2 && ($row['id']??null)!=='2') return $out;
    if (($row['project_key']??null)!=='anytour' || ($row['channel']??null)!=='max' || ($row['original_display_name']??null)!=='Pavel'
        || !in_array($row['status']??null,['manager','waiting_manager'],true)
        || $token==='' || strlen($token)>4096 || preg_match('/[\x00-\x20\x7f]/',$token)) return $out;
    $user=MaxPrivateAvatarProbe::decimalId($row['external_user_id']??null);
    $alias=MaxPrivateAvatarProbe::chatId($row['conversation_chat_id']??null);
    $mid=$row['external_message_id']??null;
    if ($user===null || $alias===null || MaxPrivateAvatarProbe::chatId($row['channel_chat_id']??null)!==$alias
        || MaxPrivateAvatarProbe::decimalId($row['message_sender_id']??null)!==$user
        || !is_string($mid) || !preg_match('/\A(?:mid\.)?[A-Za-z0-9_-]{1,200}\z/D',$mid)) return $out;
    $out['provider_requests']++;
    $identity=$get('identity',$mid,$token);$out['identity_status']=(int)$identity['status'];$identity=$identity['data'];
    $chat=MaxPrivateAvatarProbe::chatId($identity['recipient']['chat_id']??null);
    $bot=MaxPrivateAvatarProbe::decimalId($identity['recipient']['user_id']??null);
    if (($identity['body']['mid']??null)!==$mid || MaxPrivateAvatarProbe::decimalId($identity['sender']['user_id']??null)!==$user
        || ($identity['recipient']['chat_type']??null)!=='dialog' || $chat===null || $bot===null) return $out;
    $out['identity_verified']=true;
    $out['owner_inbound_timestamp_ms']=previewInspectTimestamp($identity['timestamp']??null);
    $text=$identity['body']['text']??null;
    $out['owner_inbound_contains_empty']=is_string($text) && preg_match('/пуст/ui',$text)===1;
    $out['owner_inbound_fresh']=$out['owner_inbound_timestamp_ms']!==null && $out['owner_inbound_timestamp_ms']>=1792539644000;
    if (!$out['owner_inbound_contains_empty'] || !$out['owner_inbound_fresh']) return $out;
    $out['provider_requests']++;
    $history=$get('history',$chat,$token);$out['history_status']=(int)$history['status'];
    $messages=$history['data']['messages']??null;
    if (!is_array($messages) || !array_is_list($messages) || count($messages)>100) return $out;
    $out['window_messages']=count($messages);$out['window_may_be_truncated']=count($messages)===100;
    $timestamps=array_map(static fn($message)=>previewInspectTimestamp(is_array($message)?($message['timestamp']??null):null),$messages);
    $validTimestamps=array_values(array_filter($timestamps,static fn($time)=>$time!==null));
    $out['window_min_timestamp_ms']=$validTimestamps?min($validTimestamps):null;
    $out['window_max_timestamp_ms']=$validTimestamps?max($validTimestamps):null;
    $out['all_returned_within_window']=count($validTimestamps)===count($messages)
        && (!$validTimestamps || (min($validTimestamps)>=1792539612000 && max($validTimestamps)<=1792539674000));
    $out['window_not_truncated']=count($messages)<100 && $out['all_returned_within_window'];
    foreach ($messages as $message) {
        if (!is_array($message) || MaxPrivateAvatarProbe::decimalId($message['sender']['user_id']??null)!==$bot
            || MaxPrivateAvatarProbe::chatId($message['recipient']['chat_id']??null)!==$chat
            || ($message['recipient']['chat_type']??null)!=='dialog'
            || previewInspectTimestamp($message['timestamp']??null)===null
            || previewInspectTimestamp($message['timestamp'])<1792539612000 || previewInspectTimestamp($message['timestamp'])>1792539674000) continue;
        $out['bot_messages_in_window']++;
        $text=$message['body']['text']??null;
        if (is_string($text) && preg_match('/delphin\s+imperial/ui',$text)===1) $out['demo_like_messages_in_window']++;
        $attachments=$message['body']['attachments']??[];
        if (!is_array($attachments) || count($attachments)>12) $attachments=[];
        $images=0;$keyboards=[];$media=false;
        foreach ($attachments as $attachment) {
            if (($attachment['type']??null)==='image') $images++;
            elseif (($attachment['type']??null)==='inline_keyboard') $keyboards[]=$attachment['payload']['buttons']??null;
            if (in_array($attachment['type']??null,['image','video','audio','file','sticker'],true)) $media=true;
        }
        if ($media) $out['media_messages_in_window']++;
        // Optional/mismatched recipient.user_id must never hide same-chat bot evidence.
        if ($text!==ownerPreviewBody()['text'] || MaxPrivateAvatarProbe::decimalId($message['recipient']['user_id']??null)!==$user) continue;
        $out['matching_messages']++;
        $out['matching_cards'][]=['images'=>$images,'attachments'=>count($attachments),
            'like_message_button'=>$keyboards===[[[['type'=>'message','text'=>'Нравится']]]]];
    }
    $out['inspection']=$out['matching_messages']>0?'matching_demo_found'
        :($out['window_not_truncated'] && $out['bot_messages_in_window']===0?'no_bot_demo_evidence_in_send_window':'inconclusive');
    return $out;
}

if (!defined('MAX_OWNER_INSPECT_OFFLINE_TEST')) {
    ini_set('display_errors','0');ini_set('log_errors','0');
    $level=ob_get_level();ob_start();$out=['inspection'=>'inconclusive','receipt_state'=>'unavailable'];
    try {
        $root=getcwd();
        if ($root!=='/var/www/anytoour/data/www/app.anytoour.ru') throw new RuntimeException('scope');
        $receipt='/var/www/anytoour/data/max-owner-tour-preview/delphin-imperial-20261021.json';
        if (is_link($receipt) || !is_file($receipt)) throw new RuntimeException('receipt');
        $before=file_get_contents($receipt,false,null,0,4097);
        $private=is_string($before)&&strlen($before)<=4096?json_decode($before,true):null;
        if (!is_array($private) || ($private['demo']??null)!=='delphin-imperial-20261021' || ($private['state']??null)!=='unknown') throw new RuntimeException('receipt');
        $out['receipt_state']='unknown';
        define('MAX_OWNER_PREVIEW_OFFLINE_TEST',true);
        require_once $root.'/tools/max_owner_tour_preview.php';
        require_once $root.'/config.php';
        require_once $root.'/services/ConversationDb.php';
        $project=require $root.'/project.php';
        if (!is_array($project) || ($project['id']??null)!=='anytour') throw new RuntimeException('project');
        $db=ConversationDb::connection();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('driver');
        $row=ownerPreviewTarget($db);
        $out=array_replace($out,previewInspectRun(is_array($row)?$row:[],defined('MAX_SEARCH_TOKEN')?(string)MAX_SEARCH_TOKEN:'','previewInspectGet'));
        $after=file_get_contents($receipt,false,null,0,4097);
        $out['receipt_unchanged']=is_string($after) && hash_equals(hash('sha256',$before),hash('sha256',$after));
        if (!$out['receipt_unchanged']) $out['inspection']='inconclusive';
    } catch (Throwable $e) { $out['inspection']='inconclusive'; }
    finally { while(ob_get_level()>$level)ob_end_clean(); }
    echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}
