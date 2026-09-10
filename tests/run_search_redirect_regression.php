<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Cannot reserve local HTTP port');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$log = tmpfile();
$process = proc_open([PHP_BINARY, '-S', $address, '-t', $root], [
    0 => ['pipe', 'r'], 1 => $log, 2 => $log,
], $pipes, $root);
if (!is_resource($process)) throw new RuntimeException('Cannot start local PHP server');
fclose($pipes[0]);

function redirectCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}
function redirectRequest(string $address, string $query, string $method = 'GET'): array {
    $context = stream_context_create(['http' => [
        'method' => $method, 'follow_location' => 0, 'ignore_errors' => true,
        'timeout' => 3, 'header' => "Host: untrusted.invalid\r\nConnection: close\r\n",
    ]]);
    $body = file_get_contents('http://' . $address . '/poisk-turov/' . ($query === '' ? '' : '?' . $query), false, $context);
    $headers = [];
    foreach ($http_response_header ?? [] as $line) {
        if (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $headers[strtolower($key)] = trim($value);
        }
    }
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $match);
    return ['status' => (int)($match[1] ?? 0), 'headers' => $headers, 'body' => $body];
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(100000);
    }
    redirectCheck($ready, 'local HTTP endpoint starts');
    $owner = 'from=1&country=4&dateFrom=2026-10-05&dateTo=2026-10-05&daysFrom=7&daysTill=7&count_people=2&stars=4&food=7&yclid=7654321';
    $queries = [
        'owner fixture' => $owner,
        'raw arrays repeated keys and encoding' => 'child_age%5B%5D=5&child_age%5B%5D=8&x=a+b&x=a%20b&empty=&yclid=0001234567890',
        'untrusted redirect parameters' => 'url=https%3A%2F%2Funtrusted.invalid&next=%2F%2Funtrusted.invalid&yclid=7654321',
        'empty query' => '',
    ];
    foreach ($queries as $label => $query) {
        foreach (['GET', 'HEAD'] as $method) {
            $response = redirectRequest($address, $query, $method);
            redirectCheck($response['status'] === 302, "$label $method returns temporary redirect");
            redirectCheck(($response['headers']['location'] ?? '') === 'https://anytoour.ru/poisk-turov/' . ($query === '' ? '' : '?' . $query), "$label $method preserves exact query and canonical website");
            redirectCheck(($response['headers']['cache-control'] ?? '') === 'no-store', "$label $method is not cached");
            redirectCheck($response['body'] === '', "$label $method emits no page or tracking body");
        }
    }
    $post = redirectRequest($address, $owner, 'POST');
    redirectCheck($post['status'] === 405 && !isset($post['headers']['location']), 'POST does not forward a body to the website');
    redirectCheck(($post['headers']['allow'] ?? '') === 'GET, HEAD', 'supported methods are explicit');
    $large = redirectRequest($address, 'q=' . str_repeat('a', 8193));
    redirectCheck($large['status'] === 400 && !isset($large['headers']['location']), 'oversized query is rejected without redirect');
} finally {
    proc_terminate($process);
    proc_close($process);
    fclose($log);
}
echo "SEARCH_REDIRECT_HTTP_REGRESSION_OK\n";
