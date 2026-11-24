<?php
declare(strict_types=1);
require_once __DIR__ . '/Max2ValueLookupProtocolV2.php';

/** Pure client seam for a NEW guarded sidecar. No endpoint, key or network adapter is installed here. */
final class Max2ValueLookupClientV2
{
    private Closure $transport;
    private Closure $privateKey;
    private Closure $clock;
    public function __construct(callable $transport, callable $privateKey, ?callable $clock = null, private bool $includeUmk = false)
    {
        $this->transport = Closure::fromCallable($transport);
        $this->privateKey = Closure::fromCallable($privateKey);
        $this->clock = $clock === null ? static fn(): int => time() : Closure::fromCallable($clock);
    }
    public function lookup(string $actor, array $context, callable $beforeRequest): ?array
    {
        try {
            return $this->request(Max2ValueLookupProtocolV2::listBody($actor, $context, ($this->clock)()), $beforeRequest);
        } catch (Throwable $ignored) { return null; }
    }
    public function recheck(array $privateLookup, string $recordId, callable $beforeRequest): ?array
    {
        try {
            $p = Max2ValueLookupProtocolV2::class;
            $p::need($p::keys($privateLookup, ['receipt', 'source_receipt_sha256', 'received_at_unix'])
                && is_array($privateLookup['receipt']) && is_int($privateLookup['received_at_unix'])
                && $privateLookup['source_receipt_sha256'] === hash('sha256', $p::json($privateLookup['receipt'])), 'invalid_selection');
            $body = $p::recheckBody($privateLookup['receipt'], $privateLookup['source_receipt_sha256'], $recordId, ($this->clock)());
            return $this->request($body, $beforeRequest, $this->includeUmk);
        } catch (Throwable $ignored) { return null; }
    }
    /** A consumed durable intent is required before the single read transport invocation. */
    private function request(string $body, callable $beforeRequest, bool $withUmk = false): ?array
    {
        $p = Max2ValueLookupProtocolV2::class;
        $p::decodeBody($body, ($this->clock)());
        $key = ($this->privateKey)();
        $optionalHeaders = [];
        try {
            $signature = $p::signature($body, $key);
            if ($withUmk) {
                $signed = null;
                try {
                    if (openssl_sign("anytour-max2-umk-point-reserve-v1\n" . $body, $signed, $key, OPENSSL_ALGO_SHA256)) {
                        $optionalHeaders['umk_signature'] = base64_encode($signed);
                    }
                } catch (Throwable $ignored) { /* The original signed recheck is still usable. */ }
            }
        }
        finally { unset($key); }
        $requestHash = hash('sha256', $body);
        if ($beforeRequest($requestHash) !== true) return null;
        // The future HTTPS adapter must enforce these bounds while streaming, before accumulating a body.
        $reply = ($this->transport)(['body' => $body, 'signature' => $signature,
            'evidence_header' => $p::EVIDENCE_HEADER, 'purpose' => $p::PURPOSE,
            'max_body_bytes' => $p::MAX_BODY, 'max_response_bytes' => $p::MAX_RESPONSE,
            'connect_timeout_seconds' => 5, 'timeout_seconds' => 12, 'require_https' => true,
            'verify_tls_peer' => true, 'verify_tls_host' => true, 'follow_redirects' => false, 'request_count' => 1] + $optionalHeaders);
        $received = ($this->clock)();
        $umkHeader = null;
        if ($optionalHeaders !== [] && is_array($reply)) {
            $umkHeader = $reply['umk_reserve'] ?? null;
            unset($reply['umk_reserve']);
        }
        if (!$p::keys($reply, ['status_code', 'body', 'request_count', 'redirects_followed', 'tls_verified'])
            || $reply['status_code'] !== 200 || $reply['request_count'] !== 1 || $reply['redirects_followed'] !== 0
            || $reply['tls_verified'] !== true || !is_string($reply['body']) || strlen($reply['body']) > $p::MAX_RESPONSE) return null;
        $receipt = $p::validateResult($reply['body'], $body, $received);
        $responseHash = hash('sha256', $reply['body']);
        $result = ['receipt' => $receipt, 'source_receipt_sha256' => $responseHash, 'received_at_unix' => $received];
        if (($receipt['status'] ?? null) === 'match' && is_string($umkHeader) && strlen($umkHeader) <= 138
            && preg_match('/\A(0|[1-9][0-9]{0,7}):([0-9a-f]{64}):([0-9a-f]{64})\z/D', $umkHeader, $match)
            && (int)$match[1] <= min(10000000, (int)$receipt['candidates'][0]['common_kopeks'])
            && hash_equals($requestHash, $match[2]) && hash_equals($responseHash, $match[3])) {
            $result['umk_point_proof'] = [
                'amount_kopeks' => (int)$match[1],
                'source_sha256' => hash('sha256', "anytour-max2-umk-point-reserve-v1\n" . $umkHeader),
            ];
        }
        return $result;
    }
}
