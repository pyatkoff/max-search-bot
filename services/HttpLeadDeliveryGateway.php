<?php

declare(strict_types=1);

require_once __DIR__ . '/LeadBridgeConfig.php';

/**
 * Standalone-safe lead transport.
 * Sends the already-built canonical Bitrix element to the legacy receiver over
 * authenticated server-to-server HMAC. Business fields stay owned by the
 * existing LeadPayloadService; this class only transports them.
 */
final class HttpLeadDeliveryGateway
{
    public static function create(array $element)
    {
        $url = LeadBridgeConfig::receiverUrl();
        $secret = LeadBridgeConfig::secret();
        if ($url === '' || $secret === '') {
            throw new RuntimeException('Standalone lead bridge is not configured');
        }

        $body = json_encode(['element' => $element], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) throw new RuntimeException('Lead payload encoding failed');
        $signature = hash_hmac('sha256', $body, $secret);

        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Lead bridge initialization failed');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Max-Search-Signature: ' . $signature,
            ],
            CURLOPT_USERAGENT => 'AnyTourMaxSearchLeadBridge/1.0',
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) throw new RuntimeException('Lead bridge connection failed: curl ' . $errno);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['ok']) || empty($decoded['leadId'])) {
            throw new RuntimeException('Lead bridge rejected delivery (HTTP ' . $status . ')');
        }
        return (int) $decoded['leadId'];
    }
}
