<?php
require_once __DIR__ . '/ManagerConversationService.php';
require_once __DIR__ . '/ManagerPushService.php';
require_once __DIR__ . '/ManagerSendGuardService.php';
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/MetrikaConversionGoalService.php';
require_once __DIR__ . '/../integrations/MaxMessengerAdapter.php';
require_once __DIR__ . '/../integrations/TelegramMessengerAdapter.php';
require_once __DIR__ . '/../integrations/WebsiteMessengerAdapter.php';

class ManagerOutboundService
{
    private static $lastFailure = null;

    public static function lastFailure(): ?array
    {
        return is_array(self::$lastFailure) ? self::$lastFailure : null;
    }

    public static function attachmentTypeForMime(string $mime): string
    {
        $mime=strtolower(trim($mime));
        if(strpos($mime,'image/')===0)return'image';
        if(strpos($mime,'video/')===0)return'video';
        if(strpos($mime,'audio/')===0)return'audio';
        return'file';
    }

    public static function send(int $conversationId, int $managerId, string $text): bool
    {
        self::$lastFailure = null;
        $text = trim($text); if ($text === '') return false;
        $detail = ManagerConversationService::detail($conversationId,$managerId);
        if (!$detail) return false;
        $c = $detail['conversation'];
        if ((string)$c['status'] !== 'manager' || (int)$c['manager_id'] !== $managerId) return false;
        $chatId = $c['external_chat_id']; $channel = strtolower((string)$c['channel']);

        if ($channel === 'max') {
            $suspended=self::unresolvedSuspendedFailure($conversationId,(string)$c['project_key']);
            if ($suspended) { self::$lastFailure=$suspended; return false; }
        }

        $locked=ManagerSendGuardService::acquire($conversationId,$managerId);
        try {
            if($locked && ManagerSendGuardService::isImmediateDuplicate($conversationId,$text)){
                ConversationControlService::event($conversationId,'manager_message_suppressed_duplicate','manager',$managerId,['channel'=>$channel,'project_key'=>(string)$c['project_key']]);
                return true;
            }

            if ($channel === 'max') $adapter = new MaxMessengerAdapter(null, null, 'manager');
            elseif ($channel === 'telegram') $adapter = new TelegramMessengerAdapter(null, 'manager');
            elseif ($channel === 'website') $adapter = new WebsiteMessengerAdapter('manager');
            else return false;

            $ok = $adapter->send($chatId, htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            if ($ok) {
                ConversationControlService::event($conversationId,'manager_message','manager',$managerId,['channel'=>$channel,'project_key'=>(string)$c['project_key']]);
                MetrikaConversionGoalService::managerReply($conversationId);
                return true;
            }
            return self::recordFailure($conversationId,$managerId,$channel,(string)$c['project_key']);
        } finally {
            if($locked) ManagerSendGuardService::release($conversationId,$managerId);
        }
    }

    public static function sendMedia(int $conversationId,int $managerId,string $filePath,string $fileName,string $mimeType,string $caption='',string $previewUrl=''): bool
    {
        self::$lastFailure=null;
        if(!is_file($filePath))return false;
        $detail=ManagerConversationService::detail($conversationId,$managerId);if(!$detail)return false;
        $c=$detail['conversation'];
        if((string)$c['status']!=='manager'||(int)$c['manager_id']!==$managerId)return false;
        $channel=strtolower((string)$c['channel']);
        if($channel!=='max'){
            self::$lastFailure=['category'=>'unsupported','http_code'=>0,'message'=>'Медиа сейчас поддерживается только для MAX','channel'=>$channel,'project_key'=>(string)$c['project_key']];
            return false;
        }
        $suspended=self::unresolvedSuspendedFailure($conversationId,(string)$c['project_key']);
        if($suspended){self::$lastFailure=$suspended;return false;}
        $type=self::attachmentTypeForMime($mimeType);
        $adapter=new MaxMessengerAdapter(null,null,'manager');
        $safeCaption=trim($caption)!==''?htmlspecialchars(trim($caption),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'):'';
        $ok=$adapter->sendMedia($c['external_chat_id'],$type,$filePath,$fileName,$mimeType,$safeCaption,$previewUrl);
        if($ok){
            ConversationControlService::event($conversationId,'manager_message','manager',$managerId,['channel'=>'max','project_key'=>(string)$c['project_key'],'media_type'=>$type]);
            MetrikaConversionGoalService::managerReply($conversationId);
            return true;
        }
        return self::recordFailure($conversationId,$managerId,'max',(string)$c['project_key'],['media_type'=>$type]);
    }

    private static function recordFailure(int $conversationId,int $managerId,string $channel,string $projectKey,array $extra=[]): bool
    {
        $failure=['category'=>'unknown','http_code'=>0,'message'=>'Сообщение не доставлено'];
        if($channel==='max' && class_exists('MaxTransport')){
            $transportFailure=MaxTransport::lastError();
            if(is_array($transportFailure))$failure=array_merge($failure,$transportFailure);
        }
        $failure=array_merge($failure,$extra,['channel'=>$channel,'project_key'=>$projectKey]);
        self::$lastFailure=$failure;
        ConversationControlService::event($conversationId,'manager_message_failed','manager',$managerId,$failure);
        ManagerPushService::notifyConversation($conversationId,self::failureNotice($failure));
        return false;
    }

    private static function unresolvedSuspendedFailure(int $conversationId,string $projectKey): ?array
    {
        try {
            $pdo=ConversationDb::connection();
            $q=$pdo->prepare("SELECT created_at,payload_json FROM conversation_events WHERE conversation_id=? AND event_type='manager_message_failed' ORDER BY id DESC LIMIT 20");
            $q->execute([$conversationId]);$suspendedAt=null;
            foreach($q->fetchAll() as $row){$payload=json_decode((string)($row['payload_json']??''),true);if(is_array($payload)&&(string)($payload['category']??'')==='suspended'){$suspendedAt=(string)($row['created_at']??'');break;}}
            if(!$suspendedAt)return null;
            $q=$pdo->prepare("SELECT created_at FROM messages WHERE conversation_id=? AND direction='inbound' AND sender_type='customer' AND created_at>? ORDER BY id DESC LIMIT 1");$q->execute([$conversationId,$suspendedAt]);if($q->fetchColumn())return null;
            return ['category'=>'suspended','http_code'=>403,'message'=>'Диалог MAX приостановлен: пользователь остановил или заблокировал бота. Повторная отправка доступна после нового сообщения или запуска бота пользователем.','channel'=>'max','project_key'=>$projectKey,'suppressed_retry'=>true];
        } catch(Throwable $e) { return null; }
    }

    public static function failureNotice(array $failure): string
    {
        switch((string)($failure['category']??'unknown')){
            case 'suspended': return '🔴 Сообщение не доставлено: пользователь остановил или заблокировал бота MAX. Написать снова можно только после запуска/разблокировки бота пользователем.';
            case 'blocked': return '🔴 Сообщение не доставлено: пользователь заблокировал бота';
            case 'unavailable': return '🔴 Сообщение не доставлено: пользователь недоступен в MAX';
            case 'temporary': return '⚠️ Сообщение не доставлено: временная ошибка MAX, попробуйте ещё раз';
            case 'unsupported': return '⚠️ Отправка медиа для этого канала пока не поддерживается';
            default: return '⚠️ Сообщение не доставлено: ошибка MAX';
        }
    }
}
