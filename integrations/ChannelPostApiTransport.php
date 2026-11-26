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

    /** Pure shape check for the separately authorized, URL-only keyboard pilot. */
    public static function publisherKeyboardPilotPayload(array $body): ?array
    {
        if (array_keys($body) !== ['chat_id', 'message_id', 'reply_markup']
            || $body['chat_id'] !== '-1001845835645' || !in_array($body['message_id'], [1047, '1047'], true)
            || !is_array($body['reply_markup']) || array_keys($body['reply_markup']) !== ['inline_keyboard']) return null;
        $rows = $body['reply_markup']['inline_keyboard'];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > 3) return null;
        $buttons = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || !array_is_list($row) || count($row) < 1 || ($buttons += count($row)) > 3) return null;
            foreach ($row as $button) {
                if (!is_array($button) || array_keys($button) !== ['text', 'url']
                    || !is_string($button['text']) || trim($button['text']) === ''
                    || !mb_check_encoding($button['text'], 'UTF-8') || mb_strlen($button['text'], 'UTF-8') > 128
                    || preg_match('/[\x00-\x1f\x7f-\x{009f}]/u', $button['text']) !== 0
                    || !is_string($button['url']) || strlen($button['url']) > 2048
                    || !str_starts_with($button['url'], 'https://')
                    || preg_match('/[\x00-\x20\x7f]/', $button['url']) !== 0
                    || filter_var($button['url'], FILTER_VALIDATE_URL) === false) return null;
                $url = parse_url($button['url']);
                if (!is_array($url) || ($url['scheme'] ?? null) !== 'https' || empty($url['host'])
                    || isset($url['user']) || isset($url['pass'])) return null;
            }
        }
        return $body;
    }

    /** The pilot never admits the legacy target, media, captions or a new message. */
    public static function publisherKeyboardPilotRequest(#[\SensitiveParameter] string $token, string $method, string $path, array $query, ?array $body): array
    {
        $denied = ['http' => 0, 'data' => null, 'transport_error' => true];
        if ($path !== 'editMessageReplyMarkup') {
            return in_array($path, ['getMe', 'getChat', 'getChatMember'], true)
                ? self::publisherButtonRequest($token, $method, $path, $query, $body) : $denied;
        }
        if (!preg_match('/\A[1-9][0-9]{4,18}:[A-Za-z0-9_-]{20,200}\z/D', $token)
            || $method !== 'POST' || $query !== [] || !is_array($body)
            || self::publisherKeyboardPilotPayload($body) === null) return $denied;
        return self::postRequest('telegram', $method, $path, $query, $body, false, $token);
    }

    /** Exact saved-publisher media capability. File IDs and remote upload URLs are excluded. */
    public static function publisherMediaPilotPayload(array $body): ?array
    {
        if (array_keys($body) !== ['chat_id', 'message_id', 'media', 'reply_markup', 'photo']
            || $body['chat_id'] !== '-1001845835645' || $body['message_id'] !== '1058') return null;
        $media = $body['media'];
        if (!is_array($media) || array_keys($media) !== ['type', 'media', 'caption', 'caption_entities']
            || $media['type'] !== 'photo' || $media['media'] !== 'attach://hotel_photo'
            || !is_string($media['caption']) || trim($media['caption']) === ''
            || !mb_check_encoding($media['caption'], 'UTF-8')
            || strlen(mb_convert_encoding($media['caption'], 'UTF-16LE', 'UTF-8')) > 2048
            || preg_match('/[\x00-\x09\x0b-\x1f\x7f-\x{009f}]/u', $media['caption']) !== 0
            || $media['caption_entities'] !== []) return null;
        $markup = $body['reply_markup'];
        $rows = is_array($markup) ? ($markup['inline_keyboard'] ?? null) : null;
        $button = is_array($rows) ? ($rows[0][0] ?? null) : null;
        if (!is_array($markup) || array_keys($markup) !== ['inline_keyboard']
            || !is_array($rows) || array_keys($rows) !== [0]
            || !is_array($rows[0]) || array_keys($rows[0]) !== [0]
            || !is_array($button) || array_keys($button) !== ['text', 'url']
            || $button['text'] !== '✈️ Рассчитать на мои даты'
            || !is_string($button['url'])
            || preg_match('~\Ahttps://t\.me/anyru_bot\?start=p_[A-Za-z0-9_-]{24}\z~D', $button['url']) !== 1) return null;
        $photo = $body['photo'];
        if (!is_array($photo) || array_keys($photo) !== ['base64', 'sha256']
            || !is_string($photo['base64']) || strlen($photo['base64']) > 13981016
            || !is_string($photo['sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $photo['sha256']) !== 1) return null;
        $bytes = base64_decode($photo['base64'], true);
        if (!is_string($bytes) || strlen($bytes) < 256 || strlen($bytes) > 10485760
            || base64_encode($bytes) !== $photo['base64'] || !hash_equals($photo['sha256'], hash('sha256', $bytes))) return null;
        $size = @getimagesizefromstring($bytes);
        if (!$size || ($size[2] ?? null) !== IMAGETYPE_JPEG || $size[0] < 1 || $size[1] < 1
            || $size[0] + $size[1] > 10000 || max($size[0], $size[1]) > 20 * min($size[0], $size[1])) return null;
        return $body;
    }

    /** One multipart edit through the same fixed-host transport and saved credential lease. */
    public static function publisherMediaPilotRequest(#[\SensitiveParameter] string $token, string $method, string $path, array $query, ?array $body): array
    {
        $denied = ['http' => 0, 'data' => null, 'transport_error' => true];
        if (in_array($path, ['getMe', 'getChat', 'getChatMember'], true))
            return self::publisherButtonRequest($token, $method, $path, $query, $body);
        if ($path !== 'editMessageMedia' || $method !== 'POST' || $query !== []
            || !preg_match('/\A[1-9][0-9]{4,18}:[A-Za-z0-9_-]{20,200}\z/D', $token)
            || !is_array($body) || self::publisherMediaPilotPayload($body) === null) return $denied;
        $bytes = base64_decode($body['photo']['base64'], true);
        unset($body['photo']);
        return self::postRequest('telegram', $method, $path, $query, $body, false, $token, true, $bytes);
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

    /** The approved historical MAX card is replaced in one provider request. */
    public static function maxHotelTransferRequest(string $channelId, string $messageId, string $text,
        #[\SensitiveParameter] string $photoToken, string $label, string $buttonUrl): array
    {
        if ($channelId !== '-68765999669267' || $messageId !== 'mid.ffffc1752b045fed019edec4de843c85'
            || !str_starts_with($text, "Sugar Marina Resort Art Karon Beach\n")
            || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text, 'UTF-8') > 600
            || preg_match('/[0-9₽\x00-\x09\x0b-\x1f\x7f]/u', $text) !== 0
            || !preg_match('/\A[\x21-\x7e]{1,2048}\z/D', $photoToken)
            || !in_array($label, ['Рассчитать на мои даты', '✈️ Рассчитать на мои даты'], true)
            || !preg_match('~\Ahttps://max\.ru/[A-Za-z0-9_]{2,80}\?start=p_[A-Za-z0-9_-]{24}\z~D', $buttonUrl)) {
            return ['http' => 0, 'data' => null, 'transport_error' => true];
        }
        $body = ['text' => $text, 'attachments' => [
            ['type'=>'image', 'payload'=>['token'=>$photoToken]],
            ['type'=>'inline_keyboard', 'payload'=>['buttons'=>[[['type'=>'link', 'text'=>$label, 'url'=>$buttonUrl]]]]],
        ], 'notify'=>false];
        return self::postRequest('max', 'PUT', '/messages', ['message_id'=>$messageId], $body, true);
    }

    /** Pure preflight before the editor consumes its once-only write marker. */
    public static function hotelMediaPayload(string $channelId, string $messageId, array $body, bool|string $next = false): ?array
    {
        $rankedRow = null;
        if (is_string($next)) {
            require_once __DIR__ . '/../config/anytour_ranked_transfer_scope_20261123.php';
            $rows = AnyTourRankedHotelTransfer46::profile($next)['pairs'] ?? [];
            $byTarget = array_column($rows, null, 'target');
            $rankedRow = $byTarget[$messageId] ?? null;
            if ($rankedRow === null) return null;
            $approvedMessages = [$rankedRow['target']];
        } else {
            $approvedMessages = $next ? ['1588', '997', '1101'] : ['862', '328', '1083', '288'];
        }
        if ($channelId !== '-1001845835645' || !in_array($messageId, $approvedMessages, true)
            || array_keys($body) !== ['media', 'reply_markup']) return null;
        $media = $body['media'];
        $markup = $body['reply_markup'];
        if (!is_array($media) || array_keys($media) !== ['type', 'media', 'caption', 'caption_entities']
            || $media['type'] !== 'photo' || !is_string($media['media'])
            || preg_match('/\A[A-Za-z0-9_-]{1,1024}\z/D', $media['media']) !== 1
            || !is_string($media['caption']) || trim($media['caption']) === ''
            || !mb_check_encoding($media['caption'], 'UTF-8')
            || preg_match('/[\x00-\x09\x0b-\x1f\x7f-\x{009f}]/u', $media['caption']) !== 0
            || strlen(mb_convert_encoding($media['caption'], 'UTF-16LE', 'UTF-8')) > 2048
            || $media['caption_entities'] !== []
            || !is_array($markup) || array_keys($markup) !== ['inline_keyboard']) return null;
        $rows = $markup['inline_keyboard'];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== 1
            || !is_array($rows[0]) || !array_is_list($rows[0]) || count($rows[0]) !== 1) return null;
        $button = $rows[0][0];
        if (!is_array($button) || array_keys($button) !== ['text', 'url']
            || !is_string($button['text']) || trim($button['text']) === ''
            || !mb_check_encoding($button['text'], 'UTF-8') || mb_strlen($button['text'], 'UTF-8') > 128
            || preg_match('/[\x00-\x1f\x7f-\x{009f}]/u', $button['text']) !== 0
            || !is_string($button['url'])
            || preg_match('~\Ahttps://t\.me/anyru_bot\?start=p_[A-Za-z0-9_-]{24}\z~D', $button['url']) !== 1) return null;
        if ($rankedRow !== null && ($button['text'] !== '✈️ Рассчитать на мои даты'
            || !hash_equals($rankedRow['caption_sha256'], hash('sha256', $media['caption']))
            || !hash_equals($rankedRow['file_id_sha256'], hash('sha256', $media['media'])))) return null;
        return ['chat_id' => $channelId, 'message_id' => (int)$messageId] + $body;
    }

    /** Fixed historical photo edits use deployment credentials. */
    public static function hotelMediaRequest(string $channelId, string $messageId, array $body, bool|string $next = false): array
    {
        $payload = self::hotelMediaPayload($channelId, $messageId, $body, $next);
        return $payload === null ? ['http' => 0, 'data' => null, 'transport_error' => true]
            : self::postRequest('telegram', 'POST', 'editMessageMedia', [], $payload, false, null, true);
    }

    private static function postRequest(string $platform, string $method, string $path, array $query, ?array $body, bool $caption, #[\SensitiveParameter] ?string $publisherToken = null, bool $hotelMedia = false, ?string $uploadBytes = null): array
    {
        $publisherUpload = $hotelMedia && $platform === 'telegram' && !$caption
            && $publisherToken !== null && $uploadBytes !== null && $path === 'editMessageMedia';
        if (($uploadBytes !== null && !$publisherUpload)
            || ($hotelMedia && ($platform !== 'telegram' || $caption || ($publisherToken !== null && !$publisherUpload)))) {
            return ['http' => 0, 'data' => null, 'transport_error' => true];
        }
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
            && ($hotelMedia ? $path === 'editMessageMedia'
                : ($caption ? $path === 'editMessageCaption' : in_array($path, ['getMe', 'getChat', 'getChatMember', 'editMessageReplyMarkup'], true)))) {
            $url = 'https://api.telegram.org/bot' . $token . '/' . $path;
            $headers = $publisherUpload ? ['Accept: application/json'] : ['Accept: application/json', 'Content-Type: application/json'];
            $tls = [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
        } else return ['http' => 0, 'data' => null, 'transport_error' => true];
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $encoded = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body !== null && (!is_string($encoded) || strlen($encoded) > 1048576)) return ['http' => 0, 'data' => null, 'transport_error' => true];
        if ($publisherUpload) {
            if (!class_exists('CURLStringFile')) return ['http' => 0, 'data' => null, 'transport_error' => true];
            $encoded = ['chat_id'=>$body['chat_id'], 'message_id'=>$body['message_id'],
                'media'=>json_encode($body['media'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'reply_markup'=>json_encode($body['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'hotel_photo'=>new CURLStringFile($uploadBytes, 'hotel.jpg', 'image/jpeg')];
        }
        $raw = ''; $ch = curl_init($url);
        $options = $tls + [
            CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => $hotelMedia ? 15 : 6,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$raw): int {
                if (strlen($raw) + strlen($chunk) > 2097152) return 0;
                $raw .= $chunk; return strlen($chunk);
            },
        ];
        if ($encoded !== null) $options[CURLOPT_POSTFIELDS] = $encoded;
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = $errno !== 0 || $ok === false;
        $diagnostics = $hotelMedia && $error
            ? ['curl_errno' => $errno, 'elapsed_ms' => (int)round(1000 * curl_getinfo($ch, CURLINFO_TOTAL_TIME))]
            : [];
        curl_close($ch);
        $decoded = !$error ? json_decode($raw, true, 64) : null;
        return ['http' => $http, 'data' => is_array($decoded) ? $decoded : null, 'transport_error' => $error] + $diagnostics;
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
