<?php
require_once __DIR__ . '/../contracts/MessengerInterface.php';
require_once __DIR__ . '/../services/DiagnosticLogger.php';
require_once __DIR__ . '/../services/ConversationRecorder.php';

class TelegramMessengerAdapter implements MessengerInterface
{
    private $sendCallable;
    private $senderType;

    public function __construct(?callable $sendCallable = null, string $senderType = 'ai')
    {
        $this->sendCallable = $sendCallable;
        $this->senderType = in_array($senderType, ['ai','manager','system'], true) ? $senderType : 'ai';
    }

    public function send($chatId, string $text): bool
    {
        return $this->request('sendMessage', ['chat_id'=>$chatId,'text'=>$text,'parse_mode'=>'HTML']);
    }

    public function sendWithButtons($chatId, string $text, array $buttons): bool
    {
        $payload = ['chat_id'=>$chatId,'text'=>$text,'parse_mode'=>'HTML'];
        $replyMarkup = self::convertButtons($buttons);
        if ($replyMarkup !== []) $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return $this->request('sendMessage', $payload);
    }

    public function sendMedia($chatId, string $type, string $filePath, string $fileName, string $mimeType, string $text = '', string $previewUrl = ''): bool
    {
        if (!is_file($filePath)) return false;
        $methodMap = ['image'=>'sendPhoto','video'=>'sendVideo','audio'=>'sendAudio','file'=>'sendDocument'];
        $fieldMap = ['image'=>'photo','video'=>'video','audio'=>'audio','file'=>'document'];
        if (!isset($methodMap[$type], $fieldMap[$type])) return false;
        $payload = ['chat_id'=>$chatId,$fieldMap[$type]=>new CURLFile($filePath,$mimeType,$fileName)];
        if (trim($text) !== '') {
            $payload['caption']=$text;
            $payload['parse_mode']='HTML';
        }
        if (!$this->multipartRequest($methodMap[$type], $payload)) return false;
        $preview = trim($text) !== '' ? $text : ConversationRecorder::attachmentPreview([['type'=>$type]]);
        $attachment=['type'=>$type,'name'=>$fileName,'mime_type'=>$mimeType];
        if(trim($previewUrl)!=='')$attachment['url']=trim($previewUrl);
        ConversationRecorder::outbound('telegram',$chatId,$preview,$this->senderType,['attachments'=>[$attachment]]);
        return true;
    }

    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool
    {
        $contactPayload = [
            'chat_id'=>$chatId,'text'=>$text,'parse_mode'=>'HTML',
            'reply_markup'=>json_encode(['keyboard'=>[[['text'=>'📱 Отправить мой номер','request_contact'=>true]]],'resize_keyboard'=>true,'one_time_keyboard'=>true], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ];
        if (!$this->request('sendMessage', $contactPayload)) return false;
        return $this->request('sendMessage', [
            'chat_id'=>$chatId,'text'=>'Или выберите другой вариант:',
            'reply_markup'=>json_encode(['inline_keyboard'=>[
                [['text'=>'⌨️ Ввести номер вручную','callback_data'=>$manualCallback]],
                [['text'=>'← Назад','callback_data'=>$backCallback]],
            ]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function answerCallback(string $callbackId): bool
    {
        return $callbackId !== '' && $this->request('answerCallbackQuery', ['callback_query_id'=>$callbackId]);
    }

    public static function convertButtons(array $buttons): array
    {
        $hasContact = false;
        foreach ($buttons as $row) foreach ((array)$row as $button) if (!empty($button['request_contact'])) $hasContact = true;
        $keyboard = [];
        foreach ($buttons as $row) {
            $outRow = [];
            foreach ((array)$row as $button) {
                if (!is_array($button) || empty($button['text'])) continue;
                if ($hasContact) {
                    if (!empty($button['request_contact'])) $outRow[] = ['text'=>(string)$button['text'],'request_contact'=>true];
                } elseif (array_key_exists('callback_data', $button)) {
                    $outRow[] = ['text'=>(string)$button['text'],'callback_data'=>(string)$button['callback_data']];
                } elseif (!empty($button['url'])) {
                    $outRow[] = ['text'=>(string)$button['text'],'url'=>(string)$button['url']];
                }
            }
            if ($outRow) $keyboard[] = $outRow;
        }
        if (!$keyboard) return [];
        return $hasContact ? ['keyboard'=>$keyboard,'resize_keyboard'=>true,'one_time_keyboard'=>true] : ['inline_keyboard'=>$keyboard];
    }

    private function request(string $method, array $payload): bool
    {
        if ($this->sendCallable) {
            $ok = (bool)call_user_func($this->sendCallable, $method, $payload);
            if ($ok && $method === 'sendMessage' && isset($payload['chat_id'])) {
                ConversationRecorder::outbound('telegram', $payload['chat_id'], (string)($payload['text'] ?? ''), $this->senderType, ['has_buttons'=>isset($payload['reply_markup'])]);
            }
            return $ok;
        }
        return $this->curlRequest($method,$payload,false);
    }

    private function multipartRequest(string $method,array $payload): bool
    {
        if ($this->sendCallable) return (bool)call_user_func($this->sendCallable,$method,$payload);
        return $this->curlRequest($method,$payload,true);
    }

    private function curlRequest(string $method,array $payload,bool $multipart): bool
    {
        $chatId = $payload['chat_id'] ?? null;
        if (!defined('TELEGRAM_BOT_TOKEN') || TELEGRAM_BOT_TOKEN === '') {
            DiagnosticLogger::error('telegram_transport','missing_token',['method'=>$method],$chatId);
            return false;
        }
        $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $payload : http_build_query($payload));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $errno = curl_errno($ch); $error = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        $ok = $response !== false && !$errno && $http >= 200 && $http < 300 && is_array($decoded) && !empty($decoded['ok']);
        $details = ['method'=>$method,'http'=>$http,'ok'=>$ok];
        if ($errno) $details['curl_errno']=$errno;
        if ($error !== '') $details['curl_error']=$error;
        if (is_array($decoded) && !empty($decoded['description'])) $details['description']=(string)$decoded['description'];
        DiagnosticLogger::log('telegram_transport',$ok?'success':'failure',$details,$chatId,$ok?'info':'error');
        if ($ok && !$multipart && $method === 'sendMessage' && $chatId !== null) {
            ConversationRecorder::outbound('telegram', $chatId, (string)($payload['text'] ?? ''), $this->senderType, ['has_buttons'=>isset($payload['reply_markup'])]);
        }
        return $ok;
    }
}
