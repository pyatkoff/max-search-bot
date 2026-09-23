<?php
require_once __DIR__.'/ManagerMessageEditPolicy.php';
require_once __DIR__.'/ManagerConversationService.php';
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ConversationControlService.php';
require_once __DIR__.'/../integrations/MaxMessengerAdapter.php';
require_once __DIR__.'/../integrations/TelegramMessengerAdapter.php';

class ManagerMessageEditService
{
    public static function editableForConversation(int $conversationId,int $managerId): array
    {
        if($conversationId<=0||$managerId<=0) return [];
        if(!ManagerConversationService::detail($conversationId,$managerId)) return [];
        try{
            $pdo=ConversationDb::connection();
            $q=$pdo->prepare("SELECT id,metadata_json FROM messages WHERE conversation_id=? AND direction='outbound' AND sender_type='manager' AND created_at>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE ORDER BY id DESC LIMIT 30");
            $q->execute([$conversationId]);$out=[];
            foreach($q->fetchAll() as $row){
                $policy=ManagerMessageEditPolicy::inspect((int)$row['id'],$managerId);
                if(empty($policy['allowed'])) continue;
                $meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))$meta=[];
                $attachments=is_array($meta['attachments']??null)?$meta['attachments']:[];
                if($attachments&&(string)$policy['channel']==='max') continue;
                $remaining=max(0,ManagerMessageEditPolicy::WINDOW_SECONDS-(int)($policy['age_seconds']??ManagerMessageEditPolicy::WINDOW_SECONDS));
                $out[]=['message_id'=>(int)$row['id'],'remaining_seconds'=>$remaining];
            }
            return $out;
        }catch(Throwable $e){return [];}
    }

    public static function edit(int $messageId,int $managerId,string $text): array
    {
        $text=trim($text);
        if($text==='') return ['ok'=>false,'error'=>'empty_text'];

        $policy=ManagerMessageEditPolicy::inspect($messageId,$managerId);
        if(empty($policy['allowed'])) return ['ok'=>false,'error'=>(string)($policy['reason']??'not_allowed')];

        try{
            $pdo=ConversationDb::connection();
            $q=$pdo->prepare('SELECT metadata_json FROM messages WHERE id=? LIMIT 1');
            $q->execute([$messageId]);
            $metadata=json_decode((string)$q->fetchColumn(),true);
            if(!is_array($metadata)) $metadata=[];
            $attachments=is_array($metadata['attachments']??null)?$metadata['attachments']:[];
            $channel=(string)$policy['channel'];
            if($attachments && $channel==='max') return ['ok'=>false,'error'=>'media_edit_not_supported'];

            $detail=ManagerConversationService::detail((int)$policy['conversation_id'],$managerId);
            if(!$detail) return ['ok'=>false,'error'=>'conversation_not_found'];
            $conversation=(array)$detail['conversation'];
            $safe=htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');

            if($channel==='max'){
                $adapter=new MaxMessengerAdapter(null,null,'manager',null,false);
                if(!$adapter->editText((string)$policy['external_message_id'],$safe)) return ['ok'=>false,'error'=>'provider_edit_failed'];
            } elseif($channel==='telegram'){
                $adapter=new TelegramMessengerAdapter(null,'manager',false);
                $edited=$attachments
                    ? $adapter->editCaption($conversation['external_chat_id'],(int)$policy['external_message_id'],$safe)
                    : $adapter->editText($conversation['external_chat_id'],(int)$policy['external_message_id'],$safe);
                if(!$edited) return ['ok'=>false,'error'=>'provider_edit_failed'];
            } elseif($channel!=='website') {
                return ['ok'=>false,'error'=>'unsupported_channel'];
            }

            $metadata['edited_at']=gmdate('Y-m-d H:i:s');
            $metadata['edited_by_manager_id']=$managerId;
            $json=json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if($json===false) return ['ok'=>false,'error'=>'metadata_encode_failed'];
            $u=$pdo->prepare('UPDATE messages SET text=?,metadata_json=? WHERE id=? AND conversation_id=?');
            $u->execute([$safe,$json,$messageId,(int)$policy['conversation_id']]);
            if($u->rowCount()<1) return ['ok'=>false,'error'=>'storage_update_failed'];
            ConversationControlService::event((int)$policy['conversation_id'],'manager_message_edited','manager',$managerId,['channel'=>$channel,'message_id'=>$messageId]);
            return ['ok'=>true,'message_id'=>$messageId,'text'=>$safe,'edited'=>true];
        }catch(Throwable $e){
            return ['ok'=>false,'error'=>'edit_failed'];
        }
    }
}
