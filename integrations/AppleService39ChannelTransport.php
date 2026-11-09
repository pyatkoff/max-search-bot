<?php
declare(strict_types=1);

require_once __DIR__.'/../services/ProjectMaxConnection.php';
require_once __DIR__.'/../services/MaxTlsConfig.php';

/** Fixed, project-bound channel operations. No global credentials or retry. */
final class AppleService39ChannelTransport
{
    private ProjectMaxConnection $connection;
    private string $channelId;
    private $wire;

    public function __construct(ProjectMaxConnection $connection, string $channelId, ?callable $wire = null)
    {
        if ($connection->projectKey() !== 'appleservice39' || $connection->botKey() !== 'id390521129062_bot'
            || $connection->token() === '' || !preg_match('/\A-[1-9][0-9]{0,18}\z/D', $channelId)
            || $connection->baseUrl() !== 'https://platform-api2.max.ru') throw new RuntimeException('pilot_connection_unavailable');
        $this->connection = $connection; $this->channelId = $channelId; $this->wire = $wire;
    }

    public function me(): array { return $this->api('GET', '/me'); }
    public function channel(): array { return $this->api('GET', '/chats/'.$this->channelId); }
    public function membership(): array { return $this->api('GET', '/chats/'.$this->channelId.'/members/me'); }
    public function message(string $mid): array { return $this->api('GET', '/messages/'.self::mid($mid)); }
    public function pin(): array { return $this->api('GET', '/chats/'.$this->channelId.'/pin'); }
    public function create(array $body): array { return $this->api('POST', '/messages', ['chat_id'=>$this->channelId], self::body($body)); }
    public function edit(string $mid, array $body): array { return $this->api('PUT', '/messages', ['message_id'=>self::mid($mid)], self::body($body)); }
    public function setPin(string $mid): array { return $this->api('PUT', '/chats/'.$this->channelId.'/pin', [], ['message_id'=>self::mid($mid)]); }

    /** A durable upload claim must precede this bounded two-request operation. */
    public function upload(string $path): array
    {
        if (!is_file($path) || is_link($path) || filesize($path) < 1 || filesize($path) > 5242880) return self::failure();
        $reservation = $this->api('POST', '/uploads', ['type'=>'image']);
        if (!self::accepted($reservation)) return $reservation;
        $url = $reservation['data']['url'] ?? null;
        if (!is_string($url) || !self::uploadUrl($url)) return self::failure();
        // The upload URL is an authenticated provider response, not operator input.
        // A signed multipart URL needs no bot token on a media host.
        return $this->request(['method'=>'POST','url'=>$url,'token'=>'','body'=>null,'file'=>$path]);
    }

    public static function uploadReceipt(array $response): ?array
    {
        if (!self::accepted($response) || !is_array($response['data']['photos'] ?? null) || count($response['data']['photos']) !== 1) return null;
        $photos = $response['data']['photos']; $id = (string)array_key_first($photos); $photo = reset($photos);
        if (!preg_match('/\A[0-9]+\z/D', $id) || !is_array($photo) || !is_string($photo['token'] ?? null)
            || $photo['token'] === '' || strlen($photo['token']) > 4096 || preg_match('/[\x00-\x20\x7f]/', $photo['token'])) return null;
        return ['photo_id'=>$id,'token'=>$photo['token']];
    }

    public static function accepted(array $response): bool
    {
        return empty($response['transport_error']) && (int)($response['http'] ?? 0) >= 200
            && (int)$response['http'] < 300 && is_array($response['data'] ?? null)
            && !isset($response['data']['code']) && ($response['data']['success'] ?? true) === true;
    }

    private function api(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->connection->baseUrl().$path;
        if ($query !== []) $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $this->request(['method'=>$method,'url'=>$url,'token'=>$this->connection->token(),'body'=>$body,'file'=>null]);
    }

    private function request(array $request): array
    {
        try {
            if ($this->wire !== null) {
                $result = ($this->wire)($request);
                return is_array($result) ? $result : self::failure();
            }
            if (!extension_loaded('curl')) return self::failure();
            $raw = ''; $curl = curl_init($request['url']);
            if ($curl === false) return self::failure();
            $headers = ['Accept: application/json'];
            if ($request['token'] !== '') $headers[] = 'Authorization: '.$request['token'];
            if ($request['file'] === null) $headers[] = 'Content-Type: application/json';
            $options = MaxTlsConfig::strictCurlOptions() + [
                CURLOPT_CUSTOMREQUEST=>$request['method'], CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_FOLLOWLOCATION=>false, CURLOPT_MAXREDIRS=>0, CURLOPT_CONNECTTIMEOUT=>2,
                CURLOPT_TIMEOUT=>$request['file'] === null ? 6 : 30, CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$raw): int {
                    if (strlen($raw)+strlen($chunk)>2097152) return 0;
                    $raw .= $chunk; return strlen($chunk);
                },
            ];
            if ($request['file'] !== null) $options[CURLOPT_POSTFIELDS] = ['data'=>new CURLFile($request['file'], 'image/png', basename($request['file']))];
            elseif ($request['body'] !== null) $options[CURLOPT_POSTFIELDS] = json_encode($request['body'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            curl_setopt_array($curl, $options);
            $done = curl_exec($curl); $failed = $done === false || curl_errno($curl) !== 0;
            $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
            $data = json_decode($raw, true, 32);
            return ['http'=>$http,'transport_error'=>$failed,'data'=>is_array($data) ? $data : null];
        } catch (Throwable $ignored) { return self::failure(); }
    }

    private static function uploadUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || strlen($url)>8192 || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
        $host = strtolower((string)($parts['host'] ?? ''));
        // MAX's documented image-only multipart host. No suffix matching or
        // arbitrary provider redirect may expand this fixed pilot capability.
        return $host === 'iu.oneme.ru' && ($parts['path'] ?? '') === '/uploadImage';
    }
    private static function mid(string $mid): string
    {
        if (!preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D', $mid)) throw new RuntimeException('invalid_provider_mid');
        return $mid;
    }
    private static function body(array $body): array
    {
        if (!is_string($body['text'] ?? null) || mb_strlen($body['text'], 'UTF-8') > 4000
            || array_diff(array_keys($body), ['text','attachments','format']) !== []
            || isset($body['format']) && $body['format'] !== 'html' || isset($body['attachments']) && !is_array($body['attachments'])) throw new RuntimeException('invalid_channel_body');
        return $body;
    }
    private static function failure(): array { return ['http'=>0,'transport_error'=>true,'data'=>null]; }
}
