<?php
require_once __DIR__ . '/../contracts/MessengerInterface.php';
require_once __DIR__ . '/../services/ConversationRecorder.php';
require_once __DIR__ . '/../services/MaxTransport.php';

class MaxMessengerAdapter implements MessengerInterface
{
    private $send;
    private $sendWithButtons;
    private $sendMedia;
    private $senderType;
    private $recordOutbound;

    public function __construct(?callable $send = null, ?callable $sendWithButtons = null, string $senderType = 'ai', ?callable $sendMedia = null, bool $recordOutbound = true)
    {
        $this->send = $send ?: static function ($chatId, string $text): bool {
            return class_exists('MaxSearchApi') && (bool)MaxSearchApi::MaxSend($text, $chatId);
        };
        $this->sendWithButtons = $sendWithButtons ?: static function ($chatId, string $text, array $buttons): bool {
            return class_exists('MaxSearchApi') && (bool)MaxSearchApi::MaxSendWithButtons($text, $chatId, $buttons);
        };
        $this->sendMedia = $sendMedia ?: static function ($chatId, string $type, string $filePath, string $fileName, string $mimeType, string $text) {
            if (!class_exists('MaxSearchApi') || !defined('MAX_SEARCH_TOKEN')) return false;
            return MaxTransport::uploadAndSend(MaxSearchApi::$TV_API_URL, MAX_SEARCH_TOKEN, $chatId, $type, $filePath, $fileName, $mimeType, $text, dirname(__DIR__) . '/tmp_max_search.txt');
        };
        $this->senderType = in_array($senderType, ['ai','manager','system'], true) ? $senderType : 'ai';
        $this->recordOutbound = $recordOutbound;
    }

    public function send($chatId, string $text): bool
    {
        $ok = (bool)call_user_func($this->send, $chatId, $text);
        if ($ok && $this->recordOutbound) ConversationRecorder::outbound('max', $chatId, $text, $this->senderType);
        return $ok;
    }

    public function sendWithButtons($chatId, string $text, array $buttons): bool
    {
        $ok = (bool)call_user_func($this->sendWithButtons, $chatId, $text, $buttons);
        if ($ok && $this->recordOutbound) ConversationRecorder::outbound('max', $chatId, $text, $this->senderType, ['has_buttons'=>true]);
        return $ok;
    }

    public function sendMedia($chatId, string $type, string $filePath, string $fileName, string $mimeType, string $text = '', string $previewUrl = ''): bool
    {
        $result = call_user_func($this->sendMedia, $chatId, $type, $filePath, $fileName, $mimeType, $text);
        if (!$result) return false;
        $preview = trim($text) !== '' ? $text : ConversationRecorder::attachmentPreview([['type'=>$type]]);
        $metadataAttachment = ['type'=>$type,'name'=>$fileName,'mime_type'=>$mimeType];
        if(trim($previewUrl)!=='')$metadataAttachment['url']=trim($previewUrl);
        if (is_array($result) && !empty($result['attachment']['payload']['token'])) $metadataAttachment['token']=(string)$result['attachment']['payload']['token'];
        if ($this->recordOutbound) ConversationRecorder::outbound('max', $chatId, $preview, $this->senderType, ['attachments'=>[$metadataAttachment]]);
        return true;
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
