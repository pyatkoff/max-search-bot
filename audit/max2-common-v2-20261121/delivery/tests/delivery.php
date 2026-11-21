<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/services/Max2CommonValueMetrikaClientV2.php';

$checks = 0;
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
function refuses(callable $fn, string $name): void { $caught = false; try { $fn(); } catch (InvalidArgumentException | RuntimeException $error) { $caught = true; } check($caught, $name); }
$now = 1795260000;
$event = ['project_key' => 'anytour', 'counter_id' => '98615635', 'goal_id' => '687941321',
    'target' => 'anytour_modeled_value_v2_max2', 'value_contract' => Max2CommonValueMetrikaClientV2::CONTRACT,
    'delivery_state' => 'attempted', 'attempt_count' => 1, 'event_key' => str_repeat('a', 64),
    'attempt_key' => str_repeat('b', 64), 'client_id' => '1234567890123456789', 'delta_kopeks' => 7364, 'event_unix' => $now - 30];
$csv = Max2CommonValueMetrikaClientV2::csv($event, $now);
$event['csv_sha256'] = hash('sha256', $csv);
check($csv === "ClientId,Target,DateTime,Price,Currency\n1234567890123456789,anytour_modeled_value_v2_max2," . ($now - 30) . ",73.64,RUB\n", 'exact decimal wire');
foreach ([41, 64] as $digits) {
    $e = $event; $e['client_id'] = str_repeat('9', $digits);
    check(str_contains(Max2CommonValueMetrikaClientV2::csv($e, $now), "\n" . $e['client_id'] . ','), 'preserve admitted long CID exactly');
}
$e = $event; $e['client_id'] = str_repeat('9', 65);
refuses(static fn() => Max2CommonValueMetrikaClientV2::csv($e, $now), 'CID beyond producer bound refused');
foreach ([1 => '0.01', 1799 => '17.99', 10000000 => '100000.00'] as $kopeks => $rub) {
    $e = $event; $e['delta_kopeks'] = $kopeks;
    check(str_contains(Max2CommonValueMetrikaClientV2::csv($e, $now), ',' . $rub . ',RUB'), 'exact integer conversion');
}
foreach (['goal_id' => '678187097', 'target' => 'anytour_modeled_value_v1', 'counter_id' => '3606190',
    'project_key' => 'appleservice39', 'value_contract' => 'modeled_value_v1', 'delivery_state' => 'reserved',
    'attempt_count' => 2, 'client_id' => '01', 'event_key' => 'bad', 'attempt_key' => 'bad',
    'delta_kopeks' => 0, 'event_unix' => $now + 1] as $field => $bad) {
    $e = $event; $e[$field] = $bad;
    refuses(static fn() => Max2CommonValueMetrikaClientV2::csv($e, $now), 'refuse ' . $field);
}
foreach ([-1, 10000001, '7364', 73.64] as $bad) {
    $e = $event; $e['delta_kopeks'] = $bad;
    refuses(static fn() => Max2CommonValueMetrikaClientV2::csv($e, $now), 'refuse monetary type/range');
}
$e = $event; $e['event_unix'] = $now - 21 * 86400 - 1;
refuses(static fn() => Max2CommonValueMetrikaClientV2::csv($e, $now), 'expired event');
$admittedGoal = ['http' => 200, 'error' => 0, 'body' => json_encode(['goals' => [['id' => 687941321,
    'name' => Max2CommonValueMetrikaClientV2::TARGET, 'type' => 'action',
    'conditions' => [['type' => 'exact', 'url' => Max2CommonValueMetrikaClientV2::TARGET]]]]])];
$calls = [];
$transport = static function ($method, $url, $wire) use (&$calls, $event, $admittedGoal) {
    if ($method === 'GET') return $admittedGoal;
    $calls[] = [$method, $url, $wire];
    return ['http' => 200, 'error' => 0, 'body' => json_encode(['uploading' => ['id' => 321, 'status' => 'UPLOADED',
        'comment' => Max2CommonValueMetrikaClientV2::comment($event), 'type' => 'BASIC']])];
};
$client = new Max2CommonValueMetrikaClientV2('synthetic-token', $transport);
refuses(static fn() => $client->upload($event, $now), 'goal admission required before post');
check($calls === [], 'missing admission sends nothing');
check($client->goalReady(), 'wire fixture exact goal admitted');
$mismatched = $event; $mismatched['csv_sha256'] = str_repeat('f', 64);
refuses(static fn() => $client->upload($mismatched, $now), 'persisted attempt CSV hash required');
check($calls === [], 'mismatched durable attempt sends no POST');
$out = $client->upload($event, $now);
check($out['outcome'] === 'accepted' && $out['upload_id'] === '321' && $out['visit_linkage_verified'] === false, 'accept is not visit linkage');
check(count($calls) === 1 && $calls[0][0] === 'POST' && $calls[0][2] === $csv, 'one exact wire');
check(str_starts_with($calls[0][1], 'https://api-metrika.yandex.net/management/v1/counter/98615635/offline_conversions/upload?type=BASIC&comment=AnyTourV2_'), 'fixed target endpoint');
refuses(static fn() => $client->upload($event, $now), 'same adapter never replays');
check(count($calls) === 1, 'replay adds no POST');
foreach ([['http' => 200, 'error' => 28, 'body' => '{}'], ['http' => 500, 'error' => 0, 'body' => '{}'],
    ['http' => 200, 'error' => 0, 'body' => '{'], ['http' => 200, 'error' => 0, 'body' => '{"uploading":{"id":321,"status":"UPLOADED","comment":"foreign"}}']] as $response) {
    $c = new Max2CommonValueMetrikaClientV2('synthetic-token', static fn($method) => $method === 'GET' ? $admittedGoal : $response);
    check($c->goalReady(), 'uncertainty fixture goal admitted');
    $out = $c->upload($event, $now);
    check($out['outcome'] === 'unknown' && $out['automatic_retry_permitted'] === false, 'uncertainty never retries');
}
foreach ([400, 403, 429] as $http) {
    $c = new Max2CommonValueMetrikaClientV2('synthetic-token', static fn($method) => $method === 'GET' ? $admittedGoal : ['http' => $http, 'error' => 0, 'body' => '{}']);
    check($c->goalReady(), 'rejection fixture goal admitted');
    check($c->upload($event, $now)['outcome'] === 'rejected', 'rejection retains no-retry contract');
}
$goals = [['id' => 687941321, 'name' => Max2CommonValueMetrikaClientV2::TARGET, 'type' => 'action',
    'conditions' => [['type' => 'exact', 'url' => Max2CommonValueMetrikaClientV2::TARGET]]],
    ['id' => 678187097, 'name' => 'AnyTour: оценочная ценность обращения v1', 'type' => 'action',
    'conditions' => [['type' => 'exact', 'url' => 'anytour_modeled_value_v1']]]];
$reads = [];
$client = new Max2CommonValueMetrikaClientV2('synthetic-token', static function ($method, $url, $wire) use (&$reads, &$goals) {
    $reads[] = [$method, $url, $wire]; return ['http' => 200, 'error' => 0, 'body' => json_encode(['goals' => $goals])];
});
check($client->goalReady(), 'exact new goal read ready');
check($reads === [['GET', 'https://api-metrika.yandex.net/management/v1/counter/98615635/goals', null]], 'admission read only');
$goals[1]['conditions'][0]['url'] = Max2CommonValueMetrikaClientV2::TARGET;
check(!$client->goalReady(), 'overlapping goal refuses');
$goals[1]['conditions'][0] = ['type' => 'regexp', 'url' => '.*'];
check(!$client->goalReady(), 'unknown overlap semantics refuses');
$goals = [];
check(!$client->goalReady(), 'missing goal refuses');
$poll = new Max2CommonValueMetrikaClientV2('synthetic-token', static fn() => ['http' => 200, 'error' => 0,
    'body' => '{"uploading":{"id":321,"status":"PROCESSED","processed_quantity":1,"line_quantity":1}}']);
check($poll->poll('321')['status'] === 'PROCESSED' && $poll->poll('321')['visit_linkage_verified'] === false, 'exact processed receipt without false linkage');
check($poll->poll('322') === null && $poll->poll('../321') === null, 'foreign receipt or path refused');

// Exercise real tempnam/file permissions locally, including its documented fallback shape.
$base = sys_get_temp_dir() . '/max2-v2-csv-fixture-' . bin2hex(random_bytes(12));
check(mkdir($base, 0700), 'fixture root created');
$private = $base . '/private'; $outside = $base . '/outside';
check(mkdir($private, 0700) && mkdir($outside, 0700), 'fixture directories created');
$write = new ReflectionMethod(Max2CommonValueMetrikaClientV2::class, 'privateCsv');
$saved = null; $fallback = null;
try {
    $saved = $write->invoke(null, $private, $csv);
    check(dirname($saved) === realpath($private) && file_get_contents($saved) === $csv
        && (fileperms($saved) & 0777) === 0600, 'CSV written only in configured private directory');
    $allocateOutside = static function ($directory, $prefix) use ($outside, &$fallback) {
        $fallback = tempnam($outside, $prefix);
        return $fallback;
    };
    refuses(static fn() => $write->invoke(null, $private, $csv, $allocateOutside), 'tempnam fallback rejected before CSV write');
    check(is_string($fallback) && !file_exists($fallback) && glob($outside . '/*') === [], 'empty fallback allocation removed');
    refuses(static fn() => $write->invoke(null, $base . '/missing', $csv), 'missing private directory refused');
} finally {
    if (is_string($saved) && is_file($saved)) unlink($saved);
    if (is_string($fallback) && is_file($fallback)) unlink($fallback);
    rmdir($private); rmdir($outside); rmdir($base);
}
print('PASS ' . $checks . " V2 transport synthetic assertions; no database/provider calls\n");
