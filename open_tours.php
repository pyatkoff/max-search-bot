<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/services/RuntimeBootstrap.php';
RuntimeBootstrap::boot();
require_once __DIR__ . '/services/ProjectConfig.php';
require_once __DIR__ . '/maxsearchclass.php';

$chatID = trim((string)($_GET['chat'] ?? ''));
$url = trim((string)($_GET['url'] ?? ''));

if ($chatID !== '') {
    MaxSearchApi::scheduleToursFollowup($chatID, 600);
    MaxSearchApi::queueMetrikaGoal($chatID, 'max_show_tours');
    MaxSearchApi::funnelLog($chatID, 'site_open');
}

$publicBase = rtrim(ProjectConfig::baseDomain(), '/') . '/';
if ($url === '' || $publicBase === '/' || !str_starts_with(strtolower($url), strtolower($publicBase))) {
    http_response_code(400);
    exit('Bad URL');
}

header('Location: ' . $url, true, 302);
exit;
