<?php
declare(strict_types=1);
require_once __DIR__ . '/Max2ValueLookupProtocolV2.php';

/** Separate read-only HTTP boundary. Its injected functions are INTERNAL seams, never request capabilities. */
final class Max2ValueLookupHttpV2
{
    public const PATH = '/new/max2-value-attribution/lookup.php';
    private static function reply(int $status, array $body): array
    {
        return ['status_code' => $status, 'headers' => [
            'Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff'],
            'body' => Max2ValueLookupProtocolV2::json($body)];
    }
    private static function error(int $status, string $code): array
    {
        return self::reply($status, ['ok' => false, 'error' => $code]);
    }
    /** Authentication and all request limits precede the single repository call. */
    public static function handle(array $server, string $body, int $now, callable $publicKey, callable $read): array
    {
        $p = Max2ValueLookupProtocolV2::class;
        if (($server['REQUEST_URI'] ?? null) !== self::PATH || ($server['QUERY_STRING'] ?? '') !== '') {
            return self::error(404, 'not_found');
        }
        if (($server['REQUEST_METHOD'] ?? null) !== 'POST') {
            $reply = self::error(405, 'method_not_allowed'); $reply['headers']['Allow'] = 'POST'; return $reply;
        }
        $type = $server['CONTENT_TYPE'] ?? '';
        if (!is_string($type) || strlen($type) > 128
            || strtolower(trim(explode(';', $type, 2)[0])) !== 'application/json') {
            return self::error(415, 'json_required');
        }
        if (strlen($body) > $p::MAX_BODY) return self::error(413, 'body_too_large');
        if (array_key_exists('CONTENT_LENGTH', $server)) {
            $length = $server['CONTENT_LENGTH'];
            if (!is_string($length) || preg_match('/\A(0|[1-9][0-9]{0,9})\z/D', $length) !== 1) {
                return self::error(400, 'invalid_body_length');
            }
            if ((int)$length > $p::MAX_BODY) return self::error(413, 'body_too_large');
            if ((int)$length !== strlen($body)) return self::error(400, 'invalid_body_length');
        }
        $signature = $server['HTTP_X_MAX_ATTRIBUTION_SIGNATURE'] ?? null;
        $header = $server['HTTP_X_MAX_ATTRIBUTION_EVIDENCE'] ?? null;
        if ($body === '' || !is_string($signature) || strlen($signature) > 1368 || !is_string($header)
            || $header !== $p::EVIDENCE_HEADER) return self::error(401, 'unauthorized');
        try {
            $key = $publicKey();
            if (!is_string($key) || $key === '' || strlen($key) > 16384) throw new RuntimeException('key_unavailable');
        } catch (Throwable $ignored) { return self::error(503, 'lookup_unavailable'); }
        try { $p::authenticate($body, $signature, $key, $now, $header); }
        catch (Throwable $ignored) { return self::error(401, 'unauthorized'); }
        finally { unset($key); }
        try {
            // Exactly one read, no fallback to the old endpoint, no retry and no writer.
            $result = $read($body, $now);
            if (!is_array($result)) throw new UnexpectedValueException('invalid_result');
            $encoded = $p::json($result);
            if (strlen($encoded) > $p::MAX_RESPONSE) throw new UnexpectedValueException('response_bound');
            $p::validateResult($encoded, $body, $now);
            return self::reply(200, $result);
        } catch (Throwable $ignored) { return self::error(503, 'lookup_unavailable'); }
    }
}
