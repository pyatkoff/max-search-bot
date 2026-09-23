<?php
declare(strict_types=1);

require_once __DIR__.'/../services/MaxTlsConfig.php';

/** Provider transport for resolving and downloading inbound MAX photos. */
final class MaxInboundMediaDownloadAdapter
{
    private const API = 'https://platform-api2.max.ru';

    public static function fetchMessage(string $externalMessageId): ?array
    {
        $token = defined('MAX_SEARCH_TOKEN') ? trim((string)MAX_SEARCH_TOKEN) : '';
        $externalMessageId = trim($externalMessageId);
        if ($token === '' || $externalMessageId === '' || strlen($externalMessageId) > 512) return null;
        $url = self::API.'/messages/'.rawurlencode($externalMessageId);
        $stream = tmpfile();
        if ($stream === false) return null;
        $bytes = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>false, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>['Authorization: '.$token,'Accept: application/json'],
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use ($stream, &$bytes): int {
                $bytes += strlen($chunk);
                if ($bytes > 1024 * 1024) return 0;
                return (int)fwrite($stream, $chunk);
            },
        ] + MaxTlsConfig::strictCurlOptions());
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($ok === false || $errno !== 0 || $status !== 200) { fclose($stream); return null; }
        rewind($stream);
        $data = json_decode((string)stream_get_contents($stream, 1024 * 1024 + 1), true);
        fclose($stream);
        return is_array($data) ? $data : null;
    }

    public static function fetchVideo(string $token): ?array
    {
        $token = trim($token);
        $auth = defined('MAX_SEARCH_TOKEN') ? trim((string)MAX_SEARCH_TOKEN) : '';
        if ($token === '' || $auth === '' || strlen($token) > 1024) return null;
        $url = self::API.'/videos/'.rawurlencode($token);
        $stream = tmpfile();
        if ($stream === false) return null;
        $bytes = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>false, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>['Authorization: '.$auth,'Accept: application/json'],
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use ($stream, &$bytes): int {
                $bytes += strlen($chunk); if ($bytes > 1024 * 1024) return 0;
                return (int)fwrite($stream, $chunk);
            },
        ] + MaxTlsConfig::strictCurlOptions());
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$errno=curl_errno($ch);curl_close($ch);
        if($ok===false||$errno!==0||$status!==200){fclose($stream);return null;}
        rewind($stream);$data=json_decode((string)stream_get_contents($stream,1024*1024+1),true);fclose($stream);
        return is_array($data)?$data:null;
    }

    public static function fetchMedia(string $url, int $limit)
    {
        if (!self::safeHttpsUrl($url) || $limit <= 0) return null;
        $stream = tmpfile();
        if ($stream === false) return null;
        $bytes = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>45,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use ($stream, $limit, &$bytes): int {
                $bytes += strlen($chunk);
                if ($bytes > $limit) return 0;
                return (int)fwrite($stream, $chunk);
            },
        ] + MaxTlsConfig::strictCurlOptions());
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($ok === false || $errno !== 0 || $status !== 200 || $bytes <= 0 || $bytes > $limit) { fclose($stream); return null; }
        rewind($stream);
        return $stream;
    }

    public static function safeHttpsUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host']) && empty($parts['user']) && empty($parts['pass']);
    }
}
