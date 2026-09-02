<?php
require_once __DIR__ . '/config.php';
require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');
require_once __DIR__ . '/services/MetrikaRedirectPage.php';
require_once(__DIR__.'/maxsearchclass.php');

$chatID = trim((string)($_GET['chat'] ?? ''));
$url = trim((string)($_GET['url'] ?? ''));

if($chatID!=='') {
    MaxSearchApi::funnelLog($chatID,'channel_click');
}

if($url==='' || !preg_match('~^https://max\.ru/~i',$url)) {
    http_response_code(400);
    exit('Bad URL');
}

MetrikaRedirectPage::send($url, 'Открываем канал…');
exit;
