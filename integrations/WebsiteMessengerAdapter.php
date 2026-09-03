<?php
require_once __DIR__ . '/../contracts/MessengerInterface.php';
require_once __DIR__ . '/../services/ConversationRecorder.php';

class WebsiteMessengerAdapter implements MessengerInterface
{
    private $messages = [];
    private $senderType;
    private $recordOutbound;

    public function __construct(string $senderType = 'ai', bool $recordOutbound = true)
    {
        $this->senderType = in_array($senderType, ['ai','manager','system'], true) ? $senderType : 'ai';
        $this->recordOutbound = $recordOutbound;
    }

    public function send($chatId, string $text): bool
    {
        $this->messages[] = ['type'=>'message','text'=>$text,'buttons'=>[]];
        if ($this->recordOutbound) ConversationRecorder::outbound('website', $chatId, $text, $this->senderType);
        return true;
    }

    public function sendWithButtons($chatId, string $text, array $buttons): bool
    {
        $this->messages[] = ['type'=>'message','text'=>$text,'buttons'=>$buttons];
        if ($this->recordOutbound) ConversationRecorder::outbound('website', $chatId, $text, $this->senderType, ['has_buttons'=>true,'buttons'=>$buttons]);
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
        $messages = $this->messages; $this->messages = []; return $messages;
    }

}
