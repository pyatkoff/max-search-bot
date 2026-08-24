<?php
require_once __DIR__ . '/../contracts/MessengerInterface.php';
require_once __DIR__ . '/../services/ConversationRecorder.php';

class WebsiteMessengerAdapter implements MessengerInterface
{
    private $messages = [];

    public function send($chatId, string $text): bool
    {
        $this->messages[] = ['type'=>'message','text'=>$text,'buttons'=>[]];
        ConversationRecorder::outbound('website', $chatId, $text, 'ai');
        return true;
    }

    public function sendWithButtons($chatId, string $text, array $buttons): bool
    {
        $this->messages[] = ['type'=>'message','text'=>$text,'buttons'=>$buttons];
        ConversationRecorder::outbound('website', $chatId, $text, 'ai', ['has_buttons'=>true]);
        return true;
    }

    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool
    {
        return $this->sendWithButtons($chatId, $text, [
            [['text'=>'⌨️ Ввести номер','callback_data'=>$manualCallback]],
            [['text'=>'← Назад','callback_data'=>$backCallback]],
        ]);
    }

    public function drain(): array
    {
        $messages = $this->messages;
        $this->messages = [];
        return $messages;
    }
}
