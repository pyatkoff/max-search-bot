<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/MaxTlsConfig.php';

/** Provider credentials and HTTP details stay inside the integration boundary. */
final class ChannelPostApiTransport
{
    /** Fixed API hosts, deployment credentials, strict TLS, bounded time and bodies. */
    public static function buttonRequest(string $platform, string $method, string $path, array $query, ?array $body): array
    {
        if (!extension_loaded('curl')) return ['http' => 0, 'data' => null, 'transport_error' => true];
        $token = $platform === 'max' ? (defined('MAX_SEARCH_TOKEN') ? (string)MAX_SEARCH_TOKEN : '') : (defined('TELEGRAM_BOT_TOKEN') ? (string)TELEGRAM_BOT_TOKEN : '');
        if ($token === '') return ['http' => 0, 'data' => null, 'transport_error' => true];
        if ($platform === 'max') {
            if (!(($method === 'GET' && $body === null && $query === []) || ($method === 'PUT' && $path === '/messages' && array_keys($query) === ['message_id'] && is_string($query['message_id']) && is_array($body) && array_keys($body) === ['attachments']))) return ['http' => 0, 'data' => null, 'transport_error' => true];
            if (!preg_match('/\A\/(?:me|chats\/-[1-9][0-9]*(?:\/members\/me)?|messages(?:\/[A-Za-z0-9_.-]{1,200})?)\z/', $path)) return ['http' => 0, 'data' => null, 'transport_error' => true];
            $url = 'https://platform-api2.max.ru' . $path;
            $headers = ['Authorization: ' . $token, 'Accept: application/json', 'Content-Type: application/json'];
            $tls = MaxTlsConfig::strictCurlOptions();
        } elseif ($platform === 'telegram' && $method === 'POST' && $query === [] && in_array($path, ['getMe', 'getChat', 'getChatMember', 'editMessageReplyMarkup'], true)) {
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
