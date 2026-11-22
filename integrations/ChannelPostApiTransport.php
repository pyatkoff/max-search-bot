<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/MaxTlsConfig.php';

/** Provider credentials and HTTP details stay inside the integration boundary. */
final class ChannelPostApiTransport
{
    /** Fixed API hosts, deployment credentials, strict TLS, bounded time and bodies. */
    public static function buttonRequest(string $platform, string $method, string $path, array $query, ?array $body): array
    {
        return self::postRequest($platform, $method, $path, $query, $body, false);
    }

    /** The saved publisher may only inspect its own rights and edit this one keyboard. */
    public static function publisherButtonRequest(#[\SensitiveParameter] string $token, string $method, string $path, array $query, ?array $body): array
    {
        $denied = ['http' => 0, 'data' => null, 'transport_error' => true];
        if (!preg_match('/\A([1-9][0-9]{4,18}):[A-Za-z0-9_-]{20,200}\z/D', $token, $match)
            || $method !== 'POST' || $query !== [] || !is_array($body)) return $denied;
        $allowed = $path === 'getMe' && $body === [];
        if ($path === 'getChat') {
            $allowed = array_keys($body) === ['chat_id'] && $body['chat_id'] === '-1001845835645';
        } elseif ($path === 'getChatMember') {
            $allowed = array_keys($body) === ['chat_id', 'user_id'] && $body['chat_id'] === '-1001845835645'
                && (is_int($body['user_id']) || is_string($body['user_id'])) && (string)$body['user_id'] === $match[1];
        } elseif ($path === 'editMessageReplyMarkup') {
            $rows = $body['reply_markup']['inline_keyboard'] ?? null;
            $button = is_array($rows) ? ($rows[0][0] ?? null) : null;
            $allowed = array_keys($body) === ['chat_id', 'message_id', 'reply_markup']
                && $body['chat_id'] === '-1001845835645' && in_array($body['message_id'], [1678, '1678'], true)
                && is_array($body['reply_markup']) && array_keys($body['reply_markup']) === ['inline_keyboard']
                && is_array($rows) && array_is_list($rows) && count($rows) === 1
                && is_array($rows[0]) && array_is_list($rows[0]) && count($rows[0]) === 1
                && is_array($button) && array_keys($button) === ['text', 'url']
                && is_string($button['text']) && $button['text'] !== '' && mb_check_encoding($button['text'], 'UTF-8')
                && mb_strlen($button['text'], 'UTF-8') <= 128
                && !preg_match('/[\x00-\x1f\x7f]/u', $button['text'])
                && is_string($button['url']) && preg_match('~\Ahttps://t\.me/anyru_bot\?start=p_[A-Za-z0-9_-]{24}\z~D', $button['url']) === 1;
        }
        return $allowed ? self::postRequest('telegram', $method, $path, $query, $body, false, $token) : $denied;
    }

    /** Caption edits of already bound posts. Attachments and publish calls are excluded. */
    public static function captionRequest(string $platform, string $channelId, string $messageId, array $body): array
    {
        if ($platform === 'max' && $channelId === '-68765999669267'
            && preg_match('/\Amid\.[0-9a-f]{32}\z/D', $messageId)
            && array_keys($body) === ['text', 'format'] && $body['format'] === 'html'
            && is_string($body['text']) && $body['text'] !== '') {
            return self::postRequest('max', 'PUT', '/messages', ['message_id' => $messageId], $body, true);
        }
        if ($platform === 'telegram' && $channelId === '-1001845835645'
            && preg_match('/\A[1-9][0-9]{0,9}\z/D', $messageId)
            && array_keys($body) === ['caption', 'caption_entities', 'reply_markup']
            && is_string($body['caption']) && $body['caption'] !== ''
            && is_array($body['caption_entities']) && is_array($body['reply_markup'])) {
            return self::postRequest('telegram', 'POST', 'editMessageCaption', [], [
                'chat_id' => $channelId, 'message_id' => (int)$messageId,
            ] + $body, true);
        }
        return ['http' => 0, 'data' => null, 'transport_error' => true];
    }

    private static function postRequest(string $platform, string $method, string $path, array $query, ?array $body, bool $caption, #[\SensitiveParameter] ?string $publisherToken = null): array
    {
        if (!extension_loaded('curl')) return ['http' => 0, 'data' => null, 'transport_error' => true];
        $token = $publisherToken ?? ($platform === 'max' ? (defined('MAX_SEARCH_TOKEN') ? (string)MAX_SEARCH_TOKEN : '') : (defined('TELEGRAM_BOT_TOKEN') ? (string)TELEGRAM_BOT_TOKEN : ''));
        if ($token === '') return ['http' => 0, 'data' => null, 'transport_error' => true];
        if ($platform === 'max') {
            $page = $method === 'GET' && $path === '/messages' && $body === null && array_keys($query) === ['chat_id','from','to','count']
                && is_string($query['chat_id']) && preg_match('/\A-[1-9][0-9]{0,19}\z/',$query['chat_id'])
                && is_int($query['from']) && is_int($query['to']) && $query['to'] > 0 && $query['from'] >= $query['to']
                && is_int($query['count']) && $query['count'] >= 1 && $query['count'] <= 100;
            if (!$caption && !(($page || ($method === 'GET' && $body === null && $query === [])) || ($method === 'PUT' && $path === '/messages' && array_keys($query) === ['message_id'] && is_string($query['message_id']) && is_array($body) && array_keys($body) === ['attachments']))) return ['http' => 0, 'data' => null, 'transport_error' => true];
            if (!preg_match('/\A\/(?:me|chats\/-[1-9][0-9]*(?:\/members\/me)?|messages(?:\/[A-Za-z0-9_.-]{1,200})?)\z/', $path)) return ['http' => 0, 'data' => null, 'transport_error' => true];
            $url = 'https://platform-api2.max.ru' . $path;
            $headers = ['Authorization: ' . $token, 'Accept: application/json', 'Content-Type: application/json'];
            $tls = MaxTlsConfig::strictCurlOptions();
        } elseif ($platform === 'telegram' && $method === 'POST' && $query === []
            && ($caption ? $path === 'editMessageCaption' : in_array($path, ['getMe', 'getChat', 'getChatMember', 'editMessageReplyMarkup'], true))) {
            $url = 'https://api.telegram.org/bot' . $token . '/' . $path;
            $headers = ['Accept: application/json', 'Content-Type: application/json'];
            $tls = [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
        } else return ['http' => 0, 'data' => null, 'transport_error' => true];
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $encoded = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body !== null && (!is_string($encoded) || strlen($encoded) > 1048576)) return ['http' => 0, 'data' => null, 'transport_error' => true];
        $raw = ''; $ch = curl_init($url);
        $options = $tls + [
            CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 6,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$raw): int {
                if (strlen($raw) + strlen($chunk) > 2097152) return 0;
                $raw .= $chunk; return strlen($chunk);
            },
        ];
        if ($encoded !== null) $options[CURLOPT_POSTFIELDS] = $encoded;
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_errno($ch) !== 0 || $ok === false;
        curl_close($ch);
        $decoded = !$error ? json_decode($raw, true, 64) : null;
        return ['http' => $http, 'data' => is_array($decoded) ? $decoded : null, 'transport_error' => $error];
    }

    /** Fixed hosts, strict TLS, bounded bodies/deadlines; no raw provider logging. */
    public static function webhookRequest(string $platform,string $method,string $path,array $query,?array $body): array
    {
        if(!extension_loaded('curl')||$query!==[])return ['http'=>0,'transport_error'=>true];
        $token=$platform==='max'?(defined('MAX_SEARCH_TOKEN')?(string)MAX_SEARCH_TOKEN:''):(defined('TELEGRAM_BOT_TOKEN')?(string)TELEGRAM_BOT_TOKEN:'');
        if($token==='')return ['http'=>0,'transport_error'=>true];
        if($platform==='max'&&(($method==='GET'&&in_array($path,['/me','/subscriptions'],true))||($method==='POST'&&$path==='/subscriptions'))){
            $url='https://platform-api2.max.ru'.$path;$headers=['Authorization: '.$token,'Content-Type: application/json'];$tls=MaxTlsConfig::strictCurlOptions();
        }elseif($platform==='telegram'&&$method==='POST'&&in_array($path,['getMe','getWebhookInfo','setWebhook'],true)){
            $url='https://api.telegram.org/bot'.$token.'/'.$path;$headers=['Content-Type: application/json'];$tls=[CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
        }else return ['http'=>0,'transport_error'=>true];
        $encoded=$body===null?null:json_encode($body,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if($encoded!==null&&strlen($encoded)>65536)return ['http'=>0,'transport_error'=>true];
        $raw='';$ch=curl_init($url);$options=$tls+[
            CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>6,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>262144)return 0;$raw.=$chunk;return strlen($chunk);},
        ];if($encoded!==null)$options[CURLOPT_POSTFIELDS]=$encoded;curl_setopt_array($ch,$options);$ok=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$failed=$ok===false||curl_errno($ch)!==0;curl_close($ch);
        $data=$failed?null:json_decode($raw,true,32);return ['http'=>$http,'transport_error'=>$failed,'data'=>is_array($data)?$data:null];
    }
}
