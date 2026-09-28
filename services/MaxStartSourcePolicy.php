<?php
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ConversationRecorder.php';
require_once __DIR__.'/ProjectConfig.php';
require_once __DIR__.'/SourceHandlingService.php';
require_once __DIR__.'/DiagnosticLogger.php';

/** MAX entry adapter only; SourceHandlingService remains the handoff owner. */
final class MaxStartSourcePolicy
{
    public static function apply(array $incoming,string $sourceKey): bool
    {
        $policyOwned=false;
        $locked=false;
        $lockKey='';
        $pdo=null;
        try {
            if(!ConversationDb::isConfigured()||($incoming['platform']??'')!=='max'||$sourceKey==='')return false;
            $user=(array)($incoming['user']??[]);
            $chat=$user['chat_id']??0;
            $external=trim((string)($user['external_user_id']??''));
            if(!$chat||$external==='')return false;
            $pdo=ConversationDb::connection();
            $project=ProjectConfig::projectId();
            $q=$pdo->prepare("SELECT s.id,s.handling_mode FROM conversation_sources s JOIN projects p ON p.id=s.project_id WHERE p.project_key=? AND s.source_key=? AND s.channel='max' AND s.is_active=1 LIMIT 1");
            $q->execute([$project,$sourceKey]);
            $source=$q->fetch();
            if(!$source||!in_array((string)$source['handling_mode'],['manager','ask'],true))return false;
            $policyOwned=true;

            // Serialize repeated MAX starts on the existing production connection.
            // Isolated SQLite regressions do not have MySQL advisory locks.
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
                $lockKey='max-start:'.substr(hash('sha256',$project.'|'.$external),0,48);
                $q=$pdo->prepare('SELECT GET_LOCK(?,3)');
                $q->execute([$lockKey]);
                $locked=(int)$q->fetchColumn()===1;
                if(!$locked)return true;
            }
            $q=$pdo->prepare("SELECT id,status,manager_id,source_id FROM conversations WHERE project_key=? AND channel='max' AND external_chat_id=? AND status<>? ORDER BY id DESC LIMIT 1");
            $q->execute([$project,(string)$chat,'closed']);
            $existing=$q->fetch();
            if($existing&&((int)($existing['manager_id']??0)>0||in_array((string)$existing['status'],['manager','waiting_manager'],true))){
                // Refresh entry attribution without changing the assigned source or manager.
                $q=$pdo->prepare("UPDATE conversations SET entry_channel=? WHERE id=? AND project_key=? AND channel='max' AND status<>'closed'");
                $q->execute([$sourceKey,(int)$existing['id'],$project]);
                // The authenticated webhook start is activity, not a customer message.
                // Keep evidence for delivery recovery without a reset or automatic send.
                if(($incoming['type']??'')==='bot_started'){
                    ConversationRecorder::eventByChat('max',$chat,'bot_started',['source_key'=>$sourceKey],'system');
                }
                return true;
            }

            // A platform start is an event, not text written by the tourist.
            $start=$incoming;
            $start['type']='bot_started';
            $start['source_key']=$sourceKey;
            if(!ConversationRecorder::inbound($start))return true;
            $q=$pdo->prepare("SELECT id,status,manager_id FROM conversations WHERE project_key=? AND channel='max' AND external_chat_id=? AND status<>? ORDER BY id DESC LIMIT 1");
            $q->execute([$project,(string)$chat,'closed']);
            $row=$q->fetch();
            if(!$row||(int)($row['manager_id']??0)>0||(string)$row['status']!=='ai')return true;
            $q=$pdo->prepare("UPDATE conversations SET source_id=?,entry_channel=? WHERE id=? AND project_key=? AND status='ai' AND (manager_id IS NULL OR manager_id=0)");
            $q->execute([(int)$source['id'],$sourceKey,(int)$row['id'],$project]);
            SourceHandlingService::handle($start);
            return true;
        } catch(Throwable $e) {
            try { DiagnosticLogger::log('max_start_policy','failed',['policy_resolved'=>$policyOwned],null,'error'); } catch(Throwable $ignored) {}
            // Never replace a resolved manager/ask start with a misleading AI greeting.
            return $policyOwned;
        } finally {
            if($locked&&$pdo){
                try {$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockKey]);} catch(Throwable $ignored) {}
            }
        }
    }
}
