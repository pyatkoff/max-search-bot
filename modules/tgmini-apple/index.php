<?php
declare(strict_types=1);

// Install this entry point as /tgminiapple/index.php on tg.rhat.ru.
const APPLE_FUNNEL = 'https://app.anytoour.ru/project_funnel.php?project=appleservice39&platform=telegram';
const MAX_LAUNCH_BYTES = 32768;
const MAX_RECEIPT_BYTES = 8192;

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

function appleMiniAppError(int $status, string $error): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

if (strcasecmp($_SERVER['HTTP_HOST'] ?? '', 'tg.rhat.ru') !== 0) {
    appleMiniAppError(404, 'app_unavailable');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
    if ($contentType !== 'application/json') {
        appleMiniAppError(415, 'invalid_content_type');
    }
    $input = file_get_contents('php://input', false, null, 0, MAX_LAUNCH_BYTES + 1);
    if ($input === false || $input === '' || strlen($input) > MAX_LAUNCH_BYTES) {
        appleMiniAppError(400, 'invalid_launch');
    }
    if (!extension_loaded('curl') || ($request = curl_init(APPLE_FUNNEL)) === false) {
        appleMiniAppError(503, 'save_unavailable');
    }

    // The main server verifies Telegram's signed launch and owns the visit receipt.
    $response = '';
    $configured = curl_setopt_array($request, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $input,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($request, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > MAX_RECEIPT_BYTES) {
                return 0;
            }
            $response .= $chunk;
            return strlen($chunk);
        },
    ]);
    $completed = $configured && curl_exec($request) === true;
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    curl_close($request);
    if (!$completed || $status !== 200 || !is_array(json_decode($response, true, 8))) {
        appleMiniAppError($status === 400 ? 400 : 502, 'save_unconfirmed');
    }
    header('Content-Type: application/json; charset=utf-8');
    echo $response;
    exit;
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    appleMiniAppError(405, 'method_not_allowed');
}
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>AppleService39</title>
  <style>
    body { margin: 0; padding: 24px; font: 16px/1.5 system-ui, sans-serif; color: var(--tg-theme-text-color, #152238); background: var(--tg-theme-bg-color, #fff); }
    main { max-width: 420px; margin: 36px auto; }
    h1 { font-size: 24px; margin: 12px 0; }
    button, a { font: inherit; }
    [hidden] { display: none; }
  </style>
  <script src="https://telegram.org/js/telegram-web-app.js" defer></script>
  <script src="https://app.anytoour.ru/assets/project-funnel.js" defer></script>
</head>
<body>
  <main aria-busy="true">
    <strong>AppleService39</strong>
    <div role="status" aria-live="polite">
      <h1 id="status-title">Открываем предложение</h1>
      <p id="status-copy">Подготавливаем переход к публикации в Telegram…</p>
    </div>
    <a id="continue" hidden>Открыть публикацию</a>
    <button id="retry" type="button" hidden>Повторить переход</button>
    <noscript><p>Для перехода включите JavaScript и откройте ссылку в Telegram.</p></noscript>
  </main>
  <script id="funnel-config" type="application/json">{"channel_url":"https://t.me/appleservice39","counter_id":"42611469","platform":"telegram","catalogue_url":"https://t.me/appleservice39"}</script>
</body>
</html>
