<?php
require_once __DIR__ . '/ManagerConversationService.php';
require_once __DIR__ . '/ManagerPushService.php';
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

    public static function send(int $conversationId, int $managerId, string $text): bool
    {
        self::$lastFailure = null;
        $text = trim($text); if ($text === '') return false;
        $detail = ManagerConversationService::detail($conversationId,$managerId);
        if (!$detail) return false;
        $c = $detail['conversation'];
        if ((string)$c['status'] !== 'manager' || (int)$c['manager_id'] !== $managerId) return false;
        $chatId = $c['external_chat_id']; $channel = strtolower((string)$c['channel']);
        if ($channel === 'max') $adapter = new MaxMessengerAdapter(null, null, 'manager');
        elseif ($channel === 'telegram') $adapter = new TelegramMessengerAdapter(null, 'manager');
        elseif ($channel === 'website') $adapter = new WebsiteMessengerAdapter('manager');
        else return false;
        $ok = $adapter->send($chatId, htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        if ($ok) {
            ConversationControlService::event($conversationId,'manager_message','manager',$managerId,['channel'=>$channel,'project_key'=>(string)$c['project_key']]);
            return true;
        }

        $failure=['category'=>'unknown','http_code'=>0,'message'=>'Сообщение не доставлено'];
        if($channel==='max' && class_exists('MaxTransport')){
            $transportFailure=MaxTransport::lastError();
            if(is_array($transportFailure)) $failure=array_merge($failure,$transportFailure);
        }
        $failure['channel']=$channel;
        $failure['project_key']=(string)$c['project_key'];
        self::$lastFailure=$failure;
        ConversationControlService::event($conversationId,'manager_message_failed','manager',$managerId,$failure);
        ManagerPushService::notifyConversation($conversationId,self::failureNotice($failure));
        return false;
    }

    private static function failureNotice(array $failure): string
    {
        switch((string)($failure['category']??'unknown')){
            case 'blocked': return '🔴 Сообщение не доставлено: пользователь заблокировал бота';
            case 'unavailable': return '🔴 Сообщение не доставлено: пользователь недоступен в MAX';
            case 'temporary': return '⚠️ Сообщение не доставлено: временная ошибка MAX, попробуйте ещё раз';
            default: return '⚠️ Сообщение не доставлено: ошибка MAX';
        }
    }
}
