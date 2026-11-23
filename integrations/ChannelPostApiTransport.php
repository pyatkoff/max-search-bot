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

    /** Exact owner-approved originals for two already registered hotel posts. */
    public static function existingHotelPhotoScope(string $messageId): ?array
    {
        return match ($messageId) {
            '1083' => [
                'hotel' => 393,
                'path' => 'resources/anytour/editorial-four-20261124/royal-grand-pool-01-16.jpg',
                'sha256' => 'd0d4b7a38c056478125f20e6ebe60af68da8e230b664c1f80a8a2527436c8d94',
                'bytes' => 333821, 'width' => 1920, 'height' => 1280,
                'caption_sha256' => '1279c19a3f2cdfe292299f5854cfde0b29a36c1ca4ed5462d52d076f527704d4',
                'button_sha256' => '75f66dbd0f8af0222d1dff120147547ba08717393695d14bc8da031cfa42633b',
            ],
            '288' => [
                'hotel' => 1260,
                'path' => 'resources/anytour/editorial-four-20261124/santai-beach.jpg',
                'sha256' => '27ff38d3afbb88af13e6f76a391d8023f9aaadc092b3cc99972d009adf58e0c5',
                'bytes' => 196025, 'width' => 1005, 'height' => 705,
                'caption_sha256' => '4340546097c7b34b0a236c03a2d6e933cf0a15e5bb367689a4055fb77dedab4c',
                'button_sha256' => '0959a7ea94353f818e994174166f04b62f84983645f6684c294750f1c4bb52d0',
            ],
            default => null,
        };
    }

    /** Pure preparation: no file reads, curl objects, provider calls or write marker. */
    public static function existingHotelMediaPayload(string $channelId, string $messageId, array $body, string $jpeg): ?array
    {
        $scope = self::existingHotelPhotoScope($messageId);
        if ($scope === null || $channelId !== '-1001845835645'
            || array_keys($body) !== ['caption', 'caption_entities', 'reply_markup']
            || !is_string($body['caption']) || !mb_check_encoding($body['caption'], 'UTF-8')
            || strlen(mb_convert_encoding($body['caption'], 'UTF-16LE', 'UTF-8')) > 2048
            || !hash_equals($scope['caption_sha256'], hash('sha256', $body['caption']))
            || $body['caption_entities'] !== [] || !is_array($body['reply_markup'])
            || array_keys($body['reply_markup']) !== ['inline_keyboard']
            || strlen($jpeg) !== $scope['bytes'] || !hash_equals($scope['sha256'], hash('sha256', $jpeg))) return null;
        $rows = $body['reply_markup']['inline_keyboard'];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== 1
            || !is_array($rows[0]) || !array_is_list($rows[0]) || count($rows[0]) !== 1) return null;
        $button = $rows[0][0];
        if (!is_array($button) || array_keys($button) !== ['text', 'url']
            || $button['text'] !== '✈️ Рассчитать на мои даты' || !is_string($button['url'])
            || !hash_equals($scope['button_sha256'], hash('sha256', $button['url']))) return null;
        $image = @getimagesizefromstring($jpeg);
        if (!is_array($image) || ($image[0] ?? null) !== $scope['width']
            || ($image[1] ?? null) !== $scope['height'] || ($image[2] ?? null) !== IMAGETYPE_JPEG
            || ($image['mime'] ?? null) !== 'image/jpeg') return null;
        return [
            'chat_id' => $channelId, 'message_id' => (int)$messageId,
            'media' => ['type' => 'photo', 'media' => 'attach://approved_photo',
                'caption' => $body['caption'], 'caption_entities' => []],
            'reply_markup' => $body['reply_markup'],
        ];
    }

    /** One direct edit from the same approved bytes; no upload or new-message call. */
    public static function existingHotelMediaRequest(string $channelId, string $messageId, array $body, string $jpeg): array
    {
        $payload = self::existingHotelMediaPayload($channelId, $messageId, $body, $jpeg);
        return $payload === null ? ['http' => 0, 'data' => null, 'transport_error' => true]
            : self::postRequest('telegram', 'POST', 'editMessageMedia', [], $payload, false, null, true, $jpeg);
    }

    /** Pure preflight before the editor consumes its once-only write marker. */
    public static function hotelMediaPayload(string $channelId, string $messageId, array $body, bool|string $next = false): ?array
    {
        $rankedRow = null;
        if (is_string($next)) {
            require_once __DIR__ . '/../config/anytour_ranked_transfer_scope_20261123.php';
            $rows = AnyTourRankedHotelTransfer46::PLANS[$next]['pairs'] ?? [];
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

    private static function postRequest(string $platform, string $method, string $path, array $query, ?array $body, bool $caption, #[\SensitiveParameter] ?string $publisherToken = null, bool $hotelMedia = false, ?string $jpeg = null): array
    {
        if ($hotelMedia && ($platform !== 'telegram' || $caption || $publisherToken !== null)) {
            return ['http' => 0, 'data' => null, 'transport_error' => true];
        }
        if ($jpeg !== null && (!$hotelMedia || $method !== 'POST' || $path !== 'editMessageMedia'
            || $query !== [] || !is_array($body) || ($body['media']['media'] ?? null) !== 'attach://approved_photo'
            || !class_exists(\CURLStringFile::class))) {
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
            $headers = ['Accept: application/json', 'Content-Type: application/json'];
            $tls = [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
        } else return ['http' => 0, 'data' => null, 'transport_error' => true];
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $encoded = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body !== null && (!is_string($encoded) || strlen($encoded) > 1048576)) return ['http' => 0, 'data' => null, 'transport_error' => true];
        if ($jpeg !== null) {
            if (strlen($encoded) + strlen($jpeg) > 1048576) return ['http' => 0, 'data' => null, 'transport_error' => true];
            $encoded = [
                'chat_id' => $body['chat_id'], 'message_id' => $body['message_id'],
                'media' => json_encode($body['media'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'reply_markup' => json_encode($body['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'approved_photo' => new \CURLStringFile($jpeg, 'approved-photo.jpg', 'image/jpeg'),
            ];
            // libcurl supplies the multipart boundary; never label these bytes JSON.
            $headers = ['Accept: application/json'];
        }
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
