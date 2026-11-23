<?php
require_once __DIR__ . '/../contracts/OutboundMetadataMessengerInterface.php';
require_once __DIR__ . '/../services/DiagnosticLogger.php';
require_once __DIR__ . '/../services/ConversationRecorder.php';

class TelegramMessengerAdapter implements OutboundMetadataMessengerInterface
{
    private $sendCallable;
    private $senderType;
    private $recordOutbound;
    private ?string $botToken;
    private ?string $boundChatId;
    private $lastExternalMessageId = '';
    private ?array $lastFailure = null;

    public function __construct(?callable $sendCallable = null, string $senderType = 'ai', bool $recordOutbound = true, ?string $botToken = null, ?string $boundChatId = null)
    {
        $this->sendCallable = $sendCallable;
        $this->senderType = in_array($senderType, ['ai','manager','system'], true) ? $senderType : 'ai';
        $this->recordOutbound = $recordOutbound && $botToken === null;
        $this->botToken = $botToken;
        $this->boundChatId = $boundChatId;
    }

    public function __debugInfo(): array
    {
        return ['sender_type'=>$this->senderType, 'scoped'=>$this->botToken !== null];
    }

    public function send($chatId, string $text): bool
    {
        return $this->request('sendMessage', ['chat_id'=>$chatId,'text'=>$text,'parse_mode'=>'HTML']);
    }

    public function sendWithButtons($chatId, string $text, array $buttons): bool
    {
        return $this->sendWithButtonsAndMetadata($chatId,$text,$buttons,[]);
    }

    public function sendWithButtonsAndMetadata($chatId, string $text, array $buttons, array $metadata): bool
    {
        $payload = ['chat_id'=>$chatId,'text'=>$text,'parse_mode'=>'HTML'];
        $replyMarkup = self::convertButtons($buttons);
        if ($replyMarkup !== []) $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return $this->request('sendMessage', $payload, $metadata);
    }

    public function sendMedia($chatId, string $type, string $filePath, string $fileName, string $mimeType, string $text = '', string $previewUrl = ''): bool
    {
        $this->resetResult();
        if (!is_file($filePath)) return $this->failBeforeDispatch('not_dispatched','file_unavailable');
        $methodMap = ['image'=>'sendPhoto','video'=>'sendVideo','audio'=>'sendAudio','file'=>'sendDocument'];
        $fieldMap = ['image'=>'photo','video'=>'video','audio'=>'audio','file'=>'document'];
        if (!isset($methodMap[$type], $fieldMap[$type])) return $this->failBeforeDispatch('unsupported');
        $payload = ['chat_id'=>$chatId,$fieldMap[$type]=>new CURLFile($filePath,$mimeType,$fileName)];
        if (trim($text) !== '') {
            $payload['caption']=$text;
            $payload['parse_mode']='HTML';
        }
        if (!$this->multipartRequest($methodMap[$type], $payload)) return false;
        $preview = trim($text) !== '' ? $text : ConversationRecorder::attachmentPreview([['type'=>$type]]);
        $attachment=['type'=>$type,'name'=>$fileName,'mime_type'=>$mimeType];
        if(trim($previewUrl)!=='')$attachment['url']=trim($previewUrl);
        if ($this->recordOutbound) ConversationRecorder::outbound('telegram',$chatId,$preview,$this->senderType,['attachments'=>[$attachment]]);
        return true;
    }

    public function lastExternalMessageId(): string
    {
        return $this->lastExternalMessageId;
    }

    /** Typed transport evidence only: never expose token, recipient or provider text. */
    public function lastFailure(): ?array
    {
        return $this->lastFailure;
    }

    public function editText($chatId, $messageId, string $text): bool
    {
        $messageId=(int)$messageId;$text=trim($text);
        if($messageId<=0||$text==='')return false;
        return $this->request('editMessageText',['chat_id'=>$chatId,'message_id'=>$messageId,'text'=>$text,'parse_mode'=>'HTML']);
    }

    public function editCaption($chatId, $messageId, string $text): bool
    {
        $messageId=(int)$messageId;$text=trim($text);
        if($messageId<=0||$text==='')return false;
        return $this->request('editMessageCaption',['chat_id'=>$chatId,'message_id'=>$messageId,'caption'=>$text,'parse_mode'=>'HTML']);
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

    private function allowsRecipient(array $payload): bool
    {
        return $this->botToken === null || ($this->boundChatId !== null && $this->boundChatId === (string)($payload['chat_id'] ?? ''));
    }

    private function request(string $method, array $payload, array $metadata = []): bool
    {
        $this->resetResult();
        if (!$this->allowsRecipient($payload)) return $this->failBeforeDispatch('attempt_context_changed');
        if ($this->sendCallable) {
            $result=call_user_func($this->sendCallable,$method,$payload);
            $this->lastExternalMessageId=is_array($result)?trim((string)($result['result']['message_id']??$result['message_id']??'')):'';
            $ok=(bool)$result;
            if (!$ok) $this->lastFailure=['category'=>'unknown','http_code'=>0];
            if ($ok && $this->recordOutbound && $method === 'sendMessage' && isset($payload['chat_id'])) {
                ConversationRecorder::outbound('telegram', $payload['chat_id'], (string)($payload['text'] ?? ''), $this->senderType, array_merge($metadata,['has_buttons'=>isset($payload['reply_markup'])]));
            }
            return $ok;
        }
        return $this->curlRequest($method,$payload,false,$metadata);
    }

    private function multipartRequest(string $method,array $payload): bool
    {
        $this->resetResult();
        if (!$this->allowsRecipient($payload)) return $this->failBeforeDispatch('attempt_context_changed');
        if ($this->sendCallable) {
            $ok=(bool)call_user_func($this->sendCallable,$method,$payload);
            if (!$ok) $this->lastFailure=['category'=>'unknown','http_code'=>0];
            return $ok;
        }
        return $this->curlRequest($method,$payload,true);
    }

    private function curlRequest(string $method,array $payload,bool $multipart,array $metadata = []): bool
    {
        $chatId = $payload['chat_id'] ?? null;
        $scoped = $this->botToken !== null;
        $token = $this->botToken ?? (defined('TELEGRAM_BOT_TOKEN') ? (string)TELEGRAM_BOT_TOKEN : '');
        if ($token === '') {
            DiagnosticLogger::error('telegram_transport','missing_token',['method'=>$method],$scoped ? null : $chatId);
            return $this->failBeforeDispatch('connection_unavailable');
        }
        $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $payload : http_build_query($payload));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $errno = curl_errno($ch); $error = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        $this->lastExternalMessageId=is_array($decoded)?trim((string)($decoded['result']['message_id']??'')):'';
        $ok = $response !== false && !$errno && $http >= 200 && $http < 300 && is_array($decoded) && !empty($decoded['ok']);
        if (!$ok) $this->lastFailure=self::transportFailure($response,$errno,$http,$decoded);
        $details = ['method'=>$method,'http'=>$http,'ok'=>$ok];
        if ($errno) $details['curl_errno']=$errno;
        if (!$scoped && $error !== '') $details['curl_error']=$error;
        if (!$scoped && is_array($decoded) && !empty($decoded['description'])) $details['description']=(string)$decoded['description'];
        DiagnosticLogger::log('telegram_transport',$ok?'success':'failure',$details,$scoped ? null : $chatId,$ok?'info':'error');
        if ($ok && $this->recordOutbound && !$multipart && $method === 'sendMessage' && $chatId !== null) {
            ConversationRecorder::outbound('telegram', $chatId, (string)($payload['text'] ?? ''), $this->senderType, array_merge($metadata,['has_buttons'=>isset($payload['reply_markup'])]));
        }
        return $ok;
    }

    private function resetResult(): void
    {
        $this->lastFailure=null;
        $this->lastExternalMessageId='';
    }

    private function failBeforeDispatch(string $category,?string $reason=null): bool
    {
        $this->lastFailure=['category'=>$category,'http_code'=>0];
        if ($reason!==null) $this->lastFailure['reason']=$reason;
        return false;
    }

    private static function transportFailure($response,int $errno,int $http,$decoded): array
    {
        // Only an intact, explicit provider refusal may release the send fence.
        // A network/proxy/JSON failure or ambiguous code still has unknown delivery.
        if ($response===false || $errno!==0 || !is_array($decoded)
            || ($decoded['ok']??null)!==false || !is_int($decoded['error_code']??null)
            || $decoded['error_code']!==$http || $http<400 || $http>=500
            || in_array($http,[408,409,429],true)) return ['category'=>'unknown','http_code'=>0];
        $category='provider_rejected';
        if ($http===401) $category='connection_unavailable';
        elseif ($http===403 && is_string($decoded['description']??null)
            && stripos($decoded['description'],'bot was blocked by the user')!==false) $category='blocked';
        return ['category'=>$category,'http_code'=>$http];
    }
}
