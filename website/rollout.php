<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/services/WebsiteRolloutService.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: private, no-store');

$percent = defined('WEBSITE_ROLLOUT_PERCENT') ? (int) WEBSITE_ROLLOUT_PERCENT : 0;
$salt = defined('WEBSITE_ROLLOUT_SALT') && trim((string) WEBSITE_ROLLOUT_SALT) !== ''
    ? (string) WEBSITE_ROLLOUT_SALT
    : 'anytour-website-rollout-v1';

$visitorId = isset($_COOKIE['anytour_webchat_rollout']) ? trim((string) $_COOKIE['anytour_webchat_rollout']) : '';
if ($visitorId === '') {
    try {
        $visitorId = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $visitorId = hash('sha256', microtime(true) . '|' . mt_rand());
    }
    setcookie('anytour_webchat_rollout', $visitorId, [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

$rollout = new WebsiteRolloutService($percent, $salt);
if (!$rollout->isEnabled($visitorId)) {
    echo "/* AnyTour website consultant rollout: not selected */\n";
    exit;
}

$widgetUrl = defined('WEBSITE_WIDGET_URL') && trim((string) WEBSITE_WIDGET_URL) !== ''
    ? (string) WEBSITE_WIDGET_URL
    : '/max-search/website/widget.js';

$encodedUrl = json_encode($widgetUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
echo "(function(){if(document.querySelector('script[data-anytour-webchat]'))return;var s=document.createElement('script');s.src=" . $encodedUrl . ";s.async=true;s.setAttribute('data-anytour-webchat','1');document.head.appendChild(s);}());\n";
