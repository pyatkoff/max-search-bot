<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/services/WebhookTargetConfig.php';

header('Content-Type: text/plain; charset=utf-8');

function tgApi(string $method, array $payload = []): array
{
    if (!defined('TELEGRAM_BOT_TOKEN') || TELEGRAM_BOT_TOKEN === '') {
        return ['ok'=>false,'description'=>'TELEGRAM_BOT_TOKEN is empty'];
    }

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $errno) {
        return ['ok'=>false,'description'=>'cURL error: ' . $error,'http_code'=>$http];
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return ['ok'=>false,'description'=>'Invalid Telegram response','http_code'=>$http,'raw'=>$raw];
    }
    $decoded['http_code'] = $http;
    return $decoded;
}

$action = strtolower(trim((string)($_GET['action'] ?? 'status')));
$webhookUrl = WebhookTargetConfig::telegram();

if ($action === 'set') {
    $payload = [
        'url' => $webhookUrl,
        'allowed_updates' => json_encode(['message','callback_query'], JSON_UNESCAPED_SLASHES),
        'drop_pending_updates' => false,
    ];
    if (defined('TELEGRAM_WEBHOOK_SECRET') && TELEGRAM_WEBHOOK_SECRET !== '') {
        $payload['secret_token'] = TELEGRAM_WEBHOOK_SECRET;
    }
    echo "SET WEBHOOK\n";
    echo "URL: {$webhookUrl}\n";
    echo json_encode(tgApi('setWebhook', $payload), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n\n";
}

if ($action === 'delete') {
    echo "DELETE WEBHOOK\n";
    echo json_encode(tgApi('deleteWebhook', ['drop_pending_updates'=>false]), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n\n";
}

echo "WEBHOOK INFO\n";
$info = tgApi('getWebhookInfo');
if (isset($info['result']['last_error_message'])) {
    $info['result']['last_error_message'] = (string)$info['result']['last_error_message'];
}
echo json_encode($info, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
