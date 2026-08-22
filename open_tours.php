<?php
require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');
require_once(__DIR__.'/maxsearchclass.php');

$chatID = trim((string)($_GET['chat'] ?? ''));
$url = trim((string)($_GET['url'] ?? ''));

if($chatID!=='') {
    MaxSearchApi::scheduleToursFollowup($chatID,600);
    MaxSearchApi::queueMetrikaGoal($chatID,'max_show_tours');
    MaxSearchApi::funnelLog($chatID,'site_open');
}

if($url==='' || !preg_match('~^https://anytour\.online/~i',$url)) {
    http_response_code(400);
    exit('Bad URL');
}

header('Location: '.$url, true, 302);
exit;
