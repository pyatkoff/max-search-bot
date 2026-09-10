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


// Public compatibility probe: no redirect follow, no website visit, no bot message.
if (in_array('--http', $argv, true)) {
    $cases = [
        ['HEAD', (string)parse_url($expected, PHP_URL_QUERY)],
        ['GET', 'child_age%5B%5D=5&child_age%5B%5D=8&x=a+b&x=a%20b&yclid=7654321'],
    ];
    foreach ($cases as [$method, $query]) {
        $headers = [];
        $curl = curl_init('https://app.anytoour.ru/poisk-turov/?' . $query);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($key))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $errno = curl_errno($curl);
        curl_close($curl);
        if ($errno !== 0 || $status !== 302
            || ($headers['location'] ?? '') !== 'https://anytoour.ru/poisk-turov/?' . $query
            || ($headers['cache-control'] ?? '') !== 'no-store' || $body !== '') {
            fwrite(STDERR, "SEARCH_REDIRECT_HTTP_SMOKE_FAILED: method=$method status=$status errno=$errno\n");
            exit(1);
        }
    }
    echo "SEARCH_REDIRECT_HTTP_SMOKE_OK\n";
}
