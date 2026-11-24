<?php
declare(strict_types=1);
require_once __DIR__.'/Max2ValueLookupProtocolV2.php';

/** Fixed signed read endpoint; never the old latest-identity endpoint or a goal sender. */
final class Max2ValueLookupHttpV2
{
    public const ENDPOINT = 'https://tour-max.ru/new/max2-value-attribution/lookup.php';
    public function __invoke(array $request): array
    {
        $p = Max2ValueLookupProtocolV2::class;
        $expected = ['body','signature','evidence_header','purpose','max_body_bytes','max_response_bytes',
            'connect_timeout_seconds','timeout_seconds','require_https','verify_tls_peer','verify_tls_host',
            'follow_redirects','request_count'];
        $umkSignature = $request['umk_signature'] ?? null;
        unset($request['umk_signature']);
        if (!$p::keys($request,$expected) || !is_string($request['body']) || $request['body'] === ''
            || strlen($request['body']) > $p::MAX_BODY || !is_string($request['signature'])
            || preg_match('/\A[A-Za-z0-9+\/]{1,1366}={0,2}\z/D',$request['signature']) !== 1
            || $request['evidence_header'] !== $p::EVIDENCE_HEADER || $request['purpose'] !== $p::PURPOSE
            || $request['max_body_bytes'] !== $p::MAX_BODY || $request['max_response_bytes'] !== $p::MAX_RESPONSE
            || $request['connect_timeout_seconds'] !== 5 || $request['timeout_seconds'] !== 12
            || $request['require_https'] !== true || $request['verify_tls_peer'] !== true
            || $request['verify_tls_host'] !== true || $request['follow_redirects'] !== false
            || $request['request_count'] !== 1) throw new InvalidArgumentException('V2_LOOKUP_WIRE_SCOPE');
        $decoded = $p::decodeBody($request['body'],time());
        $withUmk = $decoded['action'] === 'recheck' && is_string($umkSignature)
            && preg_match('/\A[A-Za-z0-9+\/]{1,1366}={0,2}\z/D', $umkSignature) === 1;
        $headers = ['Content-Type: application/json','Accept: application/json','Cache-Control: no-store',
            'X-Max-Attribution-Signature: '.$request['signature'],'X-Max-Attribution-Evidence: '.$p::EVIDENCE_HEADER];
        if ($withUmk) $headers[] = 'X-Max2-Umk-Proof: '.$umkSignature;
        $umkHeader = null;
        $umkSeen = false;
        $body = ''; $tooLarge = false;
        $ch = curl_init(self::ENDPOINT);
        if ($ch === false) throw new RuntimeException('V2_LOOKUP_HTTP_UNAVAILABLE');
        try {
            curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$request['body'],
                CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,
                CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_HEADERFUNCTION=>static function($curl,string $line) use ($withUmk,&$umkHeader,&$umkSeen): int {
                    if ($withUmk && strncasecmp($line, 'HTTP/', 5) === 0) {
                        $umkHeader = null;
                        $umkSeen = false;
                    } elseif ($withUmk && strncasecmp($line, 'X-Max2-Umk-Reserve:', 19) === 0) {
                        $umkHeader = !$umkSeen && strlen($line) <= 160 ? trim(substr($line, 19)) : null;
                        $umkSeen = true;
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$body,&$tooLarge,$p): int {
                    if (strlen($body)+strlen($chunk)>$p::MAX_RESPONSE) { $tooLarge=true; return 0; }
                    $body.=$chunk; return strlen($chunk);
                }]);
            $result = curl_exec($ch); $error = curl_errno($ch);
            $status = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            if ($result === false || $error !== 0 || $tooLarge
                || curl_getinfo($ch,CURLINFO_EFFECTIVE_URL) !== self::ENDPOINT
                || (int)curl_getinfo($ch,CURLINFO_REDIRECT_COUNT) !== 0) throw new RuntimeException('V2_LOOKUP_HTTP_UNKNOWN');
            $reply = ['status_code'=>$status,'body'=>$body,'request_count'=>1,'redirects_followed'=>0,'tls_verified'=>true];
            if ($withUmk && $umkHeader !== null) $reply['umk_reserve'] = $umkHeader;
            return $reply;
        } finally { curl_close($ch); }
    }
}
