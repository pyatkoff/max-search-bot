<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/services/LeadBridgeConfig.php';

$url = LeadBridgeConfig::receiverUrl();
$secret = LeadBridgeConfig::secret();
if ($url === '' || $secret === '') {
    fwrite(STDERR, "LEAD_BRIDGE_PROBE=FAIL config\n");
    exit(1);
}

$nonce = bin2hex(random_bytes(16));
$sig = hash_hmac('sha256', 'probe|' . $nonce, $secret);
$probeUrl = $url . (str_contains($url, '?') ? '&' : '?') . http_build_query(['probe' => $nonce, 'sig' => $sig], '', '&', PHP_QUERY_RFC3986);

$ch = curl_init($probeUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_USERAGENT => 'MaxSearchLeadBridgeProbe/1.0',
]);
$body = curl_exec($ch);
$errno = curl_errno($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno !== 0 || !is_string($body)) {
    fwrite(STDERR, "LEAD_BRIDGE_PROBE=FAIL transport status={$status} errno={$errno}\n");
    exit(1);
}
$data = json_decode($body, true);
if ($status !== 200 || !is_array($data) || empty($data['ok']) || empty($data['probe']) || !array_key_exists('writes', $data) || $data['writes'] !== false) {
    fwrite(STDERR, "LEAD_BRIDGE_PROBE=FAIL response status={$status}\n");
    exit(1);
}

fwrite(STDOUT, "LEAD_BRIDGE_PROBE=OK status=200 writes=false\n");
