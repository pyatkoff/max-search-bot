<?php
require_once __DIR__ . '/DiagnosticLogger.php';
require_once __DIR__ . '/LegacyActionClassifier.php';
require_once __DIR__ . '/MaxTlsConfig.php';

class MaxTransport
{
    private static $lastError = null;

    /**
     * Low-level MAX API request.
     *
     * TLS verification is owned by MaxTlsConfig. Production preflight verifies
     * both the API and dynamic upload host before this transport is deployed.
     */
    public static function request($baseUrl, $token, $httpMethod, $path, array $query = [], $body = null, $logFile = null)
    {
        self::$lastError = null;
        $url = rtrim((string)$baseUrl, '/') . '/' . ltrim((string)$path, '/');
        if (!empty($query)) $url .= '?' . http_build_query($query);
        $ch = curl_init($url);
        curl_setopt_array($ch, MaxTlsConfig::curlOptions(false));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper((string)$httpMethod));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . (string)$token,'Content-Type: application/json']);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $response = curl_exec($ch);$errno = curl_errno($ch);$error = curl_error($ch);$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);curl_close($ch);
        if ($response === false || $errno) {
            self::$lastError = ['category'=>'temporary','http_code'=>0,'message'=>mb_substr(trim('cURL '.$errno.': '.$error),0,500)];
            self::log($logFile, 'API CURL ERROR ' . $errno . ': ' . $error);return false;
        }
        $data = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            self::$lastError = self::classifyFailure($httpCode, (string)$response);self::log($logFile, 'API HTTP ' . $httpCode . ': ' . $response);return false;
        }
        return is_array($data) ? $data : true;
    }

    public static function lastError(): ?array { return is_array(self::$lastError) ? self::$lastError : null; }

    public static function classifyFailure(int $httpCode, string $response): array
    {
        $message = self::failureMessage($response);
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($response.' '.$message, 'UTF-8') : strtolower($response.' '.$message);
        $category = 'unknown';
        if (strpos($haystack,'error.dialog.suspended')!==false || strpos($haystack,'dialog.suspended')!==false) $category='suspended';
        if ($category==='unknown') foreach (['blocked','bot was blocked','bot blocked','user blocked','заблок'] as $needle) if (strpos($haystack,$needle)!==false) { $category='blocked'; break; }
        if ($category==='unknown') foreach (['not found','user not found','chat not found','conversation not found','deactivated','unavailable','not a chat member','recipient'] as $needle) if (strpos($haystack,$needle)!==false) { $category='unavailable'; break; }
        if ($category==='unknown' && in_array($httpCode,[403,404,410],true)) $category='unavailable';
        if ($category==='unknown' && ($httpCode===408 || $httpCode===425 || $httpCode===429 || $httpCode>=500)) $category='temporary';
        return ['category'=>$category,'http_code'=>$httpCode,'message'=>mb_substr($message!==''?$message:('MAX API HTTP '.$httpCode),0,500)];
    }

    private static function failureMessage(string $response): string
    {
        $data=json_decode($response,true);
        if(is_array($data)){
            foreach (['message','error_description','description','error','code'] as $key) if(isset($data[$key]) && is_scalar($data[$key])) return trim((string)$data[$key]);
            if(isset($data['error']) && is_array($data['error'])) foreach (['message','description','code'] as $key) if(isset($data['error'][$key]) && is_scalar($data['error'][$key])) return trim((string)$data['error'][$key]);
        }
        return trim(preg_replace('/\s+/u',' ',strip_tags($response))??'');
    }

    public static function deleteMessage($baseUrl, $token, $messageId, $logFile = null)
    {
        $messageId = trim((string)$messageId);if ($messageId === '') return false;
        return self::request($baseUrl, $token, 'DELETE', '/messages', ['message_id'=>$messageId], null, $logFile);
    }

    public static function send($baseUrl, $token, $chatId, $text, $logFile = null)
    {
        $res = self::request($baseUrl,$token,'POST','/messages',['user_id'=>self::externalUserId($chatId)],['text'=>(string)$text, 'format'=>'html'],$logFile);
        $mid = self::extractMessageId($res);if ($mid) self::logLegacyOutcome($chatId, (string)$text, []);return $mid;
    }

    public static function sendWithButtons($baseUrl, $token, $chatId, $text, $buttons, $logFile = null)
    {
        $maxButtons = self::convertButtons($buttons);$body = ['text'=>(string)$text, 'format'=>'html'];
        if ($maxButtons) $body['attachments'] = [['type'=>'inline_keyboard','payload'=>['buttons'=>$maxButtons]]];
        $res = self::request($baseUrl,$token,'POST','/messages',['user_id'=>self::externalUserId($chatId)],$body,$logFile);
        $mid = self::extractMessageId($res);if ($mid) self::logLegacyOutcome($chatId, (string)$text, (array)$buttons);return $mid;
    }

    public static function uploadAndSend($baseUrl, $token, $chatId, string $type, string $filePath, string $fileName, string $mimeType, string $text = '', $logFile = null)
    {
        self::$lastError = null;
        $type = strtolower(trim($type));
        if (!in_array($type, ['image','video','audio','file'], true) || !is_file($filePath)) return false;
        $endpoint = self::request($baseUrl,$token,'POST','/uploads',['type'=>$type],null,$logFile);
        if (!is_array($endpoint)) return false;
        $uploadUrl = trim((string)($endpoint['url'] ?? $endpoint['upload_url'] ?? ''));
        if ($uploadUrl === '') { self::$lastError=['category'=>'unknown','http_code'=>0,'message'=>'MAX upload URL missing']; return false; }
        $prefetchedToken = trim((string)($endpoint['token'] ?? ''));
        $upload = self::multipartUpload($uploadUrl,$filePath,$fileName,$mimeType,$logFile);
        if ($upload === false) return false;
        $uploadData = json_decode((string)$upload, true);
        $uploadToken = is_array($uploadData) ? self::findToken($uploadData) : '';
        $attachmentToken = in_array($type,['video','audio'],true) ? $prefetchedToken : ($uploadToken !== '' ? $uploadToken : $prefetchedToken);
        if ($attachmentToken === '' && $type === 'image') {
            $query = parse_url($uploadUrl, PHP_URL_QUERY);parse_str((string)$query,$parts);$attachmentToken=trim((string)($parts['token']??''));
        }
        if ($attachmentToken === '') { self::$lastError=['category'=>'unknown','http_code'=>0,'message'=>'MAX upload token missing']; return false; }
        $attachment=['type'=>$type,'payload'=>['token'=>$attachmentToken]];
        $body=['attachments'=>[$attachment]];
        if (trim($text)!=='') {$body['text']=$text;$body['format']='html';}
        $res=self::request($baseUrl,$token,'POST','/messages',['user_id'=>self::externalUserId($chatId)],$body,$logFile);
        $mid=self::extractMessageId($res);if(!$mid)return false;
        return ['message_id'=>$mid,'attachment'=>$attachment];
    }

    private static function multipartUpload(string $url, string $filePath, string $fileName, string $mimeType, $logFile = null)
    {
        $ch=curl_init($url);
        curl_setopt_array($ch,MaxTlsConfig::curlOptions(false));curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);curl_setopt($ch,CURLOPT_TIMEOUT,300);curl_setopt($ch,CURLOPT_POST,true);
        curl_setopt($ch,CURLOPT_POSTFIELDS,['data'=>new CURLFile($filePath,$mimeType!==''?$mimeType:'application/octet-stream',$fileName)]);
        $response=curl_exec($ch);$errno=curl_errno($ch);$error=curl_error($ch);$httpCode=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($response===false||$errno){self::$lastError=['category'=>'temporary','http_code'=>0,'message'=>mb_substr(trim('Upload cURL '.$errno.': '.$error),0,500)];self::log($logFile,'UPLOAD CURL ERROR '.$errno.': '.$error);return false;}
        if($httpCode<200||$httpCode>=300){self::$lastError=self::classifyFailure($httpCode,(string)$response);self::log($logFile,'UPLOAD HTTP '.$httpCode.': '.$response);return false;}
        return (string)$response;
    }

    private static function findToken(array $data): string
    {
        if(isset($data['token']) && is_scalar($data['token']) && trim((string)$data['token'])!=='') return trim((string)$data['token']);
        foreach($data as $value) if(is_array($value)){ $token=self::findToken($value); if($token!=='')return $token; }
        return '';
    }

    public static function convertButtons($buttons)
    {
        $out = [];
        foreach ((array)$buttons as $row) {
            $newRow = [];
            foreach ((array)$row as $button) {
                if (!is_array($button) || empty($button['text'])) continue;
                if (!empty($button['request_contact'])) $newRow[] = ['type'=>'request_contact','text'=>(string)$button['text']];
                elseif (array_key_exists('callback_data', $button)) $newRow[] = ['type'=>'callback','text'=>(string)$button['text'],'payload'=>(string)$button['callback_data']];
                elseif (!empty($button['url'])) $newRow[] = ['type'=>'link','text'=>(string)$button['text'],'url'=>(string)$button['url']];
            }
            if ($newRow) $out[] = $newRow;
        }
        return $out;
    }

    public static function externalUserId($internalId){ return abs((int)$internalId); }
    public static function extractMessageId($res){if (!is_array($res)) return false;if (!empty($res['message']['body']['mid'])) return $res['message']['body']['mid'];if (!empty($res['body']['mid'])) return $res['body']['mid'];if (!empty($res['message']['mid'])) return $res['message']['mid'];return false;}

    private static function logLegacyOutcome($chatId, string $text, array $buttons): void
    {
        try {
            $classification = LegacyActionClassifier::classify($text, $buttons);$buttonSummary = [];
            foreach ($buttons as $row) foreach ((array)$row as $button) if (is_array($button)) $buttonSummary[] = ['text'=>(string)($button['text'] ?? ''),'url'=>isset($button['url']) ? (string)$button['url'] : null,'callback_data'=>isset($button['callback_data']) ? (string)$button['callback_data'] : null,'request_contact'=>!empty($button['request_contact'])];
            DiagnosticLogger::log('legacy_dialogue', 'outcome', ['action'=>$classification['action'],'confidence'=>$classification['confidence'],'reason'=>$classification['reason'],'text'=>$text,'buttons'=>$buttonSummary], $chatId);
        } catch (Throwable $e) {}
    }

    public static function log($logFile, $data)
    {
        if (!$logFile) return;
        @file_put_contents($logFile,date('d.m.Y H:i:s') . '--- ' . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\r\n",FILE_APPEND);
    }
}
