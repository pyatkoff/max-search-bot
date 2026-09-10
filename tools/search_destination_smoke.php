<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Read actual deployment configuration; never create a claim or send a message.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/ProjectConfig.php';
require_once __DIR__ . '/../services/MaxTransport.php';
$claim = ['UF_CITY'=>1,'UF_COUNTRY'=>4,'UF_DATE_DEPART'=>'05.10.2026','UF_NIGHTS'=>'7','UF_ADULTS'=>2,'UF_STARS'=>4,'UF_MEAL'=>7];
$expected = 'https://anytoour.ru/poisk-turov/?from=1&country=4&dateFrom=2026-10-05&dateTo=2026-10-05&daysFrom=7&daysTill=7&count_people=2&stars=4&food=7&yclid=7654321';
$url = ProjectConfig::searchUrlFromClaim($claim, '7654321');
$buttons = MaxTransport::convertButtons([[['text'=>'Посмотреть на сайте','url'=>$url]]]);
$wire = json_decode(json_encode($buttons, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), true);
if ($url !== $expected || ($wire[0][0]['url'] ?? '') !== $expected) {
    fwrite(STDERR, "SEARCH_DESTINATION_SMOKE_FAILED: website origin or query mismatch\n");
    exit(1);
}
echo "SEARCH_DESTINATION_SMOKE_OK\n";
echo "fixture_url=" . $url . "\n";
