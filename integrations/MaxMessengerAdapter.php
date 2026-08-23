<?php
require_once __DIR__ . '/../contracts/MessengerInterface.php';

class MaxMessengerAdapter implements MessengerInterface
{
    private $send;
    private $sendWithButtons;

    public function __construct(?callable $send = null, ?callable $sendWithButtons = null)
    {
        $this->send = $send ?: static function ($chatId, string $text): bool {
            return class_exists('MaxSearchApi') && (bool)MaxSearchApi::MaxSend($text, $chatId);
        };
        $this->sendWithButtons = $sendWithButtons ?: static function ($chatId, string $text, array $buttons): bool {
            return class_exists('MaxSearchApi') && (bool)MaxSearchApi::MaxSendWithButtons($text, $chatId, $buttons);
        };
    }

    public function send($chatId, string $text): bool
    {
        return (bool)call_user_func($this->send, $chatId, $text);
    }

    public function sendWithButtons($chatId, string $text, array $buttons): bool
    {
        return (bool)call_user_func($this->sendWithButtons, $chatId, $text, $buttons);
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
