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

    /** Fixed MAX webhook operations, strict TLS and bounded bodies; no legacy logs. */
    public static function webhookRequest(string $token, string $method, string $path, array $query = [], ?array $body = null, ?callable $wire = null): array
    {
        $failure = ['http'=>0,'transport_error'=>true,'data'=>null];
        if ($token === '' || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7f]/', $token)) return $failure;
        $validUrl = static function ($url): bool {
            if (!is_string($url) || $url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
            $parts = parse_url($url);
            return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
                && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
        };
        $read = $method === 'GET' && in_array($path, ['/me','/subscriptions'], true) && $query === [] && $body === null;
        $remove = $method === 'DELETE' && $path === '/subscriptions' && array_keys($query) === ['url'] && $body === null && $validUrl($query['url']);
        $register = false;
        if ($method === 'POST' && $path === '/subscriptions' && $query === [] && $body !== null) {
            $keys = array_keys($body); sort($keys);
            $types = $body['update_types'] ?? null;
            $secret = $body['secret'] ?? null;
            $register = $keys === ['secret','update_types','url'] && $validUrl($body['url'] ?? null)
                && is_string($secret) && preg_match('/\A[A-Za-z0-9_-]{5,256}\z/D', $secret) === 1
                && is_array($types) && array_is_list($types) && count($types) >= 1 && count($types) <= 128;
            if ($register) foreach ($types as $type) {
                if (!is_string($type) || preg_match('/\A[a-z_]{1,64}\z/D', $type) !== 1) { $register = false; break; }
            }
        }
        if (!$read && !$remove && !$register) return $failure;
        try {
            $encoded = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if ($encoded !== null && strlen($encoded) > 16384) return $failure;
            $url = 'https://platform-api2.max.ru'.$path;
            if ($query) $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $wire = $wire ?? static function (string $url, string $method, string $token, ?string $encoded): array {
                if (!extension_loaded('curl')) return ['http'=>0,'errno'=>1,'body'=>''];
                $raw = ''; $ch = curl_init($url);
                if ($ch === false) return ['http'=>0,'errno'=>1,'body'=>''];
                $options = MaxTlsConfig::strictCurlOptions() + [
                    CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                    CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>6,CURLOPT_CUSTOMREQUEST=>$method,
                    CURLOPT_HTTPHEADER=>['Authorization: '.$token,'Accept: application/json','Content-Type: application/json'],
                    CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$raw): int {
                        if (strlen($raw)+strlen($chunk) > 262144) return 0;
                        $raw .= $chunk; return strlen($chunk);
                    },
                ];
                if ($encoded !== null) $options[CURLOPT_POSTFIELDS] = $encoded;
                curl_setopt_array($ch, $options);
                $ok = curl_exec($ch); $http = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $errno = curl_errno($ch);
                curl_close($ch);
                return ['http'=>$http,'errno'=>$ok === false && $errno === 0 ? 1 : $errno,'body'=>$raw];
            };
            $response = $wire($url, $method, $token, $encoded);
            if (!is_array($response) || !is_string($response['body'] ?? null) || strlen($response['body']) > 262144) return $failure;
            $failed = (int)($response['errno'] ?? 1) !== 0;
            $data = $failed ? null : json_decode($response['body'], true, 32);
            return ['http'=>(int)($response['http'] ?? 0),'transport_error'=>$failed,'data'=>is_array($data) ? $data : null];
        } catch (Throwable $ignored) { return $failure; }
    }

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

    public static function editMessage($baseUrl, $token, $messageId, string $text, $logFile = null): bool
    {
        $messageId=trim((string)$messageId);$text=trim($text);
        if($messageId===''||$text==='') return false;
        $res=self::request($baseUrl,$token,'PUT','/messages',['message_id'=>$messageId],['text'=>$text,'format'=>'html'],$logFile);
        return $res!==false;
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
