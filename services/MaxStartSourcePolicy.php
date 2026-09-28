<?php
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ConversationRecorder.php';
require_once __DIR__.'/ProjectConfig.php';
require_once __DIR__.'/ManagerHandoffDispatchService.php';

final class MaxStartSourcePolicy
{
    public static function apply(array $incoming,string $sourceKey): bool
    {
        try {
            if(!ConversationDb::isConfigured()||($incoming['platform']??'')!=='max'||$sourceKey==='')return false;
            $user=(array)($incoming['user']??[]);
            $chat=$user['chat_id']??0;$external=trim((string)($user['external_user_id']??''));
            if(!$chat||$external==='')return false;
            $pdo=ConversationDb::connection();
            $q=$pdo->prepare("SELECT s.id,s.handling_mode FROM conversation_sources s JOIN projects p ON p.id=s.project_id WHERE p.project_key=? AND s.source_key=? AND s.channel='max' AND s.is_active=1 LIMIT 1");
            $q->execute([ProjectConfig::projectId(),$sourceKey]);$source=$q->fetch();
            $mode=(string)($source['handling_mode']??'');
            if(!in_array($mode,['manager','ask'],true))return false;

            $q=$pdo->prepare("SELECT id,status,manager_id,source_id FROM conversations WHERE project_key=? AND channel='max' AND external_chat_id=? AND status<>? ORDER BY id DESC LIMIT 1");
            $q->execute([ProjectConfig::projectId(),(string)$chat,'closed']);$existing=$q->fetch();
            if($existing&&in_array((string)$existing['status'],['manager','waiting_manager'],true))return true;

            $seed=$incoming;$seed['type']='start';$seed['source_key']=$sourceKey;
            if(!ConversationRecorder::inbound($seed))return true;
            $q=$pdo->prepare("SELECT id FROM conversations WHERE project_key=? AND channel='max' AND external_chat_id=? AND status<>? ORDER BY id DESC LIMIT 1");
            $q->execute([ProjectConfig::projectId(),(string)$chat,'closed']);$conversationId=(int)$q->fetchColumn();
            if($conversationId<=0)return true;
            $pdo->prepare("DELETE FROM messages WHERE conversation_id=? AND direction='inbound' AND sender_type='customer' AND metadata_json LIKE ?")->execute([$conversationId,'%\"type\":\"start\"%']);
            $pdo->prepare('UPDATE conversations SET source_id=?,entry_channel=? WHERE id=?')->execute([(int)$source['id'],$sourceKey,$conversationId]);
            ConversationRecorder::eventByChat('max',$chat,'bot_started',['source_key'=>$sourceKey],'system');

            if($mode==='ask'){
                $sent=(bool)IntegrationRegistry::messenger()->sendWithButtons($chat,'Как вам удобнее продолжить?',[
                    [['text'=>'👤 Позвать менеджера','callback_data'=>'source_choice_manager']],
                    [['text'=>'🔎 Подобрать тур самостоятельно','callback_data'=>'source_choice_ai']],
                ]);
                if($sent)ConversationRecorder::eventByChat('max',$chat,'source_handling_prompted',['mode'=>'ask'],'system');
                return true;
            }

            ConversationRecorder::eventByChat('max',$chat,'source_handling_choice',['choice'=>'manager','reason'=>'source_policy'],'system');
            $name=trim(trim((string)($user['first_name']??'')).' '.trim((string)($user['last_name']??'')));
            $handoff=ManagerHandoffDispatchService::dispatch($chat,'max',$name,false);
            $meta=['source'=>'source_policy','manager_available'=>$handoff['manager_available'],'within_working_hours'=>$handoff['within_working_hours']];
            ConversationRecorder::eventByChat('max',$chat,'manager_request',$meta,'system');
            ManagerHandoffDispatchService::applyQueueDecision($handoff,'max',$chat,$meta);
            return true;
        } catch(Throwable $e) { return false; }
    }
}
