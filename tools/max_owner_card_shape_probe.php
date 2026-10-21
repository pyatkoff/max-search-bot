<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start();
$out=['state'=>'blocked','requests'=>0];
try {
    if(count($argv)!==1||getcwd()!=='/var/www/anytoour/data/www/app.anytoour.ru')throw new RuntimeException('scope');
    $root=getcwd();define('MAX_OWNER_PREVIEW_OFFLINE_TEST',true);
    require_once $root.'/tools/max_owner_tour_preview.php';
    require_once $root.'/config.php';$project=require $root.'/project.php';
    $token=defined('MAX_SEARCH_TOKEN')?(string)MAX_SEARCH_TOKEN:'';
    if(!is_array($project)||($project['id']??null)!=='anytour'||$token===''||strlen($token)>4096||preg_match('/[\x00-\x20\x7f]/',$token))throw new RuntimeException('project');
    $directory='/var/www/anytoour/data/max-owner-tour-preview';$path=$directory.'/delphin-imperial-20261021-recovery.json';
    if(is_link($directory)||!is_dir($directory)||(fileperms($directory)&0777)!==0700||is_link($path)||!is_file($path)||(fileperms($path)&0777)!==0600)throw new RuntimeException('receipt');
    $bytes=@file_get_contents($path,false,null,0,65537);$record=is_string($bytes)&&strlen($bytes)<=65536?json_decode($bytes,true,32,JSON_BIGINT_AS_STRING):null;
    if(!is_array($record)||($record['demo']??null)!=='delphin-imperial-20261021'||($record['repair_revision']??null)!=='payload-repair-v1')throw new RuntimeException('record');
    $mid=ownerPreviewMid(['mid'=>$record['message_id']??null]);
    $user=MaxPrivateAvatarProbe::decimalId($record['verified_owner_id']??null);$bot=MaxPrivateAvatarProbe::decimalId($record['verified_bot_id']??null);$chat=MaxPrivateAvatarProbe::chatId($record['verified_chat_id']??null);
    if($mid===null||$user===null||$bot===null||$chat===null)throw new RuntimeException('identity');
    $out['requests']=1;$response=ownerPreviewRequest('GET','/messages/'.rawurlencode($mid),[],null,$token);
    $out['request_diagnostic']=ownerPreviewDiagnostic($response);$message=ownerPreviewData($response);
    $recipient=is_array($message['recipient']??null)?$message['recipient']:[];
    $out['mid_matches']=ownerPreviewMid($message)===$mid;
    $out['bot_matches']=MaxPrivateAvatarProbe::decimalId($message['sender']['user_id']??null)===$bot;
    $out['chat_matches']=MaxPrivateAvatarProbe::chatId($recipient['chat_id']??null)===$chat&&($recipient['chat_type']??null)==='dialog';
    $out['recipient_user_id_present']=array_key_exists('user_id',$recipient);
    $out['recipient_user_id_matches']=$out['recipient_user_id_present']&&MaxPrivateAvatarProbe::decimalId($recipient['user_id'])===$user;
    $out['caption_matches']=($message['body']['text']??null)===ownerPreviewBody()['text'];
    $out['strict_card_matches']=ownerPreviewMatches($message,$mid,$user,$bot,$chat);
    $out['identity_matches']=$out['mid_matches']&&$out['bot_matches']&&$out['chat_matches']&&(!$out['recipient_user_id_present']||$out['recipient_user_id_matches']);
    if(!$out['identity_matches']){$out['state']='identity_mismatch';}
    else {
        $attachments=$message['body']['attachments']??[];
        if(!is_array($attachments))throw new RuntimeException('attachments');
        $types=['image','video','audio','file','inline_keyboard','sticker','contact','location','share'];
        $buttonTypes=['message','callback','link','request_contact','request_geo_location','open_app','chatbot'];
        $fields=['type','text','intent','payload','url','user_id','contact_id','mini_app','web_app','request_id'];
        $out['state']='complete';$out['attachments_count']=count($attachments);$out['attachment_types']=[];$out['image_count']=0;$out['keyboard_count']=0;$out['keyboards']=[];
        $out['shape_truncated']=count($attachments)>20;
        foreach(array_slice($attachments,0,20) as $attachment){
            $type=$attachment['type']??null;$out['attachment_types'][]=in_array($type,$types,true)?$type:'other';
            if($type==='image')$out['image_count']++;
            if($type!=='inline_keyboard')continue;
            $out['keyboard_count']++;$rows=$attachment['payload']['buttons']??[];
            if(!is_array($rows))throw new RuntimeException('rows');
            $keyboard=['rows_count'=>count($rows),'buttons_count'=>0,'buttons'=>[]];
            if(count($rows)>12)$out['shape_truncated']=true;
            foreach(array_slice(array_values($rows),0,12)as $rowIndex=>$row){
                if(!is_array($row))throw new RuntimeException('row');
                $keyboard['buttons_count']+=count($row);if(count($row)>8)$out['shape_truncated']=true;
                foreach(array_slice(array_values($row),0,8)as $buttonIndex=>$button){
                    if(!is_array($button))throw new RuntimeException('button');
                    $keys=array_keys($button);$type=$button['type']??null;$intent=$button['intent']??null;
                    $keyboard['buttons'][]=['row_index'=>$rowIndex,'button_index'=>$buttonIndex,'fields'=>array_values(array_intersect($fields,$keys)),
                        'has_other_fields'=>count(array_diff($keys,$fields))>0,'type'=>in_array($type,$buttonTypes,true)?$type:'other',
                        'label_equals_like'=>($button['text']??null)==='Нравится','intent'=>in_array($intent,['default','positive','negative'],true)?$intent:($intent===null?null:'other')];
                }
            }
            $out['keyboards'][]=$keyboard;
        }
    }
}catch(Throwable $e){$out['state']='inconclusive';}
finally{while(ob_get_level()>$level)ob_end_clean();}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
