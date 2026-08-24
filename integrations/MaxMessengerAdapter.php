<?php
require_once __DIR__ . '/../contracts/MessengerInterface.php';
require_once __DIR__ . '/../services/ConversationRecorder.php';

class MaxMessengerAdapter implements MessengerInterface
{
    private $send;
    private $sendWithButtons;
    private $senderType;

    public function __construct(?callable $send = null, ?callable $sendWithButtons = null, string $senderType = 'ai')
    {
        $this->send = $send ?: static function ($chatId, string $text): bool {
            return class_exists('MaxSearchApi') && (bool)MaxSearchApi::MaxSend($text, $chatId);
        };
        $this->sendWithButtons = $sendWithButtons ?: static function ($chatId, string $text, array $buttons): bool {
            return class_exists('MaxSearchApi') && (bool)MaxSearchApi::MaxSendWithButtons($text, $chatId, $buttons);
        };
        $this->senderType = in_array($senderType, ['ai','manager','system'], true) ? $senderType : 'ai';
    }

    public function send($chatId, string $text): bool
    {
        $ok = (bool)call_user_func($this->send, $chatId, $text);
        if ($ok) ConversationRecorder::outbound('max', $chatId, $text, $this->senderType);
        return $ok;
    }

    public function sendWithButtons($chatId, string $text, array $buttons): bool
    {
        $ok = (bool)call_user_func($this->sendWithButtons, $chatId, $text, $buttons);
        if ($ok) ConversationRecorder::outbound('max', $chatId, $text, $this->senderType, ['has_buttons'=>true]);
        return $ok;
    }

    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool
    {
        $buttons = [
            [['text'=>'📱 Отправить мой номер','request_contact'=>true]],
            [['text'=>'⌨️ Ввести номер вручную','callback_data'=>$manualCallback]],
            [['text'=>'← Назад','callback_data'=>$backCallback]],
        ];
        return $this->sendWithButtons($chatId, $text, $buttons);
    }
}
