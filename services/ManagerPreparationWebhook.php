<?php

declare(strict_types=1);

/** Outbound MCP callbacks only. No redirects, proxies, cookies or private addresses. */
final class ManagerPreparationWebhook
{
    public function __construct(private $transport = null, private $resolver = null, private $clock = null) {}

    public static function secretBytes(string $secret): ?string
    {
        if (!str_starts_with($secret, 'whsec_')) return null;
        $bytes = base64_decode(substr($secret, 6), true);
        return is_string($bytes) && strlen($bytes) >= 24 && strlen($bytes) <= 64 ? $bytes : null;
    }

    /** Validate before every connection; the selected IPv4 is pinned in cURL. */
    public function destination(string $url): ?array
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) return null;
        $p = parse_url($url);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass'])
            || isset($p['fragment']) || (isset($p['port']) && $p['port'] !== 443)) return null;
        $host = strtolower((string)($p['host'] ?? ''));
        if (strlen($host) > 253 || !preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/D', $host)
            || !str_contains($host, '.') || str_contains($host, '..')
            || preg_match('/\.(?:local|internal|localhost|test|invalid)\z/D', $host)) return null;
        $addresses = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host]
            : ($this->resolver !== null ? ($this->resolver)($host) : @gethostbynamel($host));
        if (!is_array($addresses) || !$addresses) return null;
        foreach ($addresses as $ip) if (!is_string($ip) || !self::publicAddress($ip)) return null;
        return ['host'=>$host, 'ip'=>$addresses[0]];
    }

    public function verify(string $url, string $secret, string $subscription): array
    {
        $challenge = bin2hex(random_bytes(24));
        $body = json_encode(['type'=>'verification','challenge'=>$challenge], JSON_THROW_ON_ERROR);
        $response = $this->request($url, $secret, 'msg_verification_'.bin2hex(random_bytes(16)), $subscription, $body);
        $decoded = json_decode($response['body'], true);
        return ['ok'=>$response['status'] >= 200 && $response['status'] < 300 && is_array($decoded)
            && is_string($decoded['challenge'] ?? null) && hash_equals($challenge, $decoded['challenge']),
            'reason'=>$response['reason'] !== '' ? $response['reason'] : 'challenge_failed'];
    }

    public function request(string $url, string $secret, string $id, string $subscription, string $body, ?string $previousSecret = null): array
    {
        $destination = $this->destination($url);
        $key = self::secretBytes($secret);
        if ($destination === null || $key === null || strlen($body) > 262144) return ['status'=>0,'body'=>'','reason'=>'invalid_destination'];
        $timestamp = (string)($this->clock !== null ? ($this->clock)() : time());
        $signed = $id.'.'.$timestamp.'.'.$body;
        $signature = 'v1,'.base64_encode(hash_hmac('sha256', $signed, $key, true));
        $old = $previousSecret !== null ? self::secretBytes($previousSecret) : null;
        if ($old !== null && !hash_equals($old, $key)) $signature .= ' v1,'.base64_encode(hash_hmac('sha256', $signed, $old, true));
        $headers = ['Content-Type: application/json', 'webhook-id: '.$id, 'webhook-timestamp: '.$timestamp,
            'webhook-signature: '.$signature, 'X-MCP-Subscription-Id: '.$subscription];
        try {
            $response = $this->transport !== null ? ($this->transport)($url, $headers, $body, $destination) : self::fetch($url, $headers, $body, $destination);
            $status = (int)($response['status'] ?? 0);
            $received = $response['body'] ?? '';
            if (!is_string($received) || strlen($received) > 8192) return ['status'=>0,'body'=>'','reason'=>'response_too_large'];
            return ['status'=>$status >= 100 && $status <= 599 ? $status : 0, 'body'=>$received,
                'reason'=>$status === 0 ? 'timeout' : ''];
        } catch (Throwable $ignored) { return ['status'=>0,'body'=>'','reason'=>'transport_failed']; }
    }

    private static function fetch(string $url, array $headers, string $body, array $destination): array
    {
        if (!function_exists('curl_init')) return ['status'=>0,'body'=>''];
        $h = curl_init($url); if ($h === false) return ['status'=>0,'body'=>''];
        $received = ''; $headerBytes = 0;
        try {
            curl_setopt_array($h, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_FOLLOWLOCATION=>false, CURLOPT_MAXREDIRS=>0, CURLOPT_PROXY=>'', CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_RESOLVE=>[$destination['host'].':443:'.$destination['ip']],
                CURLOPT_CONNECTTIMEOUT_MS=>1500, CURLOPT_TIMEOUT_MS=>5000, CURLOPT_MAXFILESIZE=>8192,
                CURLOPT_USERAGENT=>'AnyTour-Preparation-MCP/1.0',
                CURLOPT_WRITEFUNCTION=>static function($handle, string $chunk) use (&$received): int {
                    if (strlen($received)+strlen($chunk)>8192) return 0;
                    $received .= $chunk; return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION=>static function($handle, string $line) use (&$headerBytes): int {
                    $headerBytes += strlen($line); return $headerBytes > 8192 ? 0 : strlen($line);
                }]);
            $ok = curl_exec($h);
            return ['status'=>$ok !== false ? (int)curl_getinfo($h, CURLINFO_HTTP_CODE) : 0,'body'=>$ok !== false ? $received : ''];
        } finally { curl_close($h); }
    }

    private static function publicAddress(string $ip): bool
    {
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false;
        $n = ip2long($ip);
        foreach ([[0x64400000,0xffc00000],[0xc0000000,0xffffff00],[0xc0000200,0xffffff00],
            [0xc6120000,0xfffe0000],[0xc6336400,0xffffff00],[0xcb007100,0xffffff00],[0xe0000000,0xe0000000]] as [$prefix,$mask]) {
            if (($n & $mask)===$prefix) return false;
        }
        return true;
    }
}
