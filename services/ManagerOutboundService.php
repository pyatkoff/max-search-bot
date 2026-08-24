<?php
require_once __DIR__ . '/ManagerConversationService.php';
require_once __DIR__ . '/../integrations/MaxMessengerAdapter.php';
require_once __DIR__ . '/../integrations/TelegramMessengerAdapter.php';
require_once __DIR__ . '/../integrations/WebsiteMessengerAdapter.php';

class ManagerOutboundService
{
    public static function send(int $conversationId, int $managerId, string $text): bool
    {
        $text = trim($text); if ($text === '') return false;
        $detail = ManagerConversationService::detail($conversationId);
        if (!$detail) return false;
        $c = $detail['conversation'];
        if ((string)$c['status'] !== 'manager' || (int)$c['manager_id'] !== $managerId) return false;
        $chatId = $c['external_chat_id']; $channel = strtolower((string)$c['channel']);
        if ($channel === 'max') $adapter = new MaxMessengerAdapter(null, null, 'manager');
        elseif ($channel === 'telegram') $adapter = new TelegramMessengerAdapter(null, 'manager');
        elseif ($channel === 'website') $adapter = new WebsiteMessengerAdapter('manager');
        else return false;
        $ok = $adapter->send($chatId, htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        if ($ok) ConversationControlService::event($conversationId,'manager_message','manager',$managerId,['channel'=>$channel]);
        return $ok;
    }
}
