<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/DateParser.php';
require_once __DIR__ . '/../services/DateNoiseGuard.php';
require_once __DIR__ . '/../services/DestinationAreaResolver.php';
require_once __DIR__ . '/../services/TrafficAttributionService.php';
require_once __DIR__ . '/../services/AnalyticsService.php';
require_once __DIR__ . '/../services/MaxTransport.php';
require_once __DIR__ . '/../services/FollowupQueueService.php';
require_once __DIR__ . '/../DepartureRouteResolver.php';
require_once __DIR__ . '/../DepartureRouteAdvisor.php';

$passed = 0;
$failed = 0;
$xfail = 0;

function valueToString($value): string
{
    if (is_bool($value)) return $value ? 'true' : 'false';
    if ($value === null) return 'null';
    if (is_scalar($value)) return (string)$value;
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function check(string $name, $actual, $expected, bool $knownIssue = false): void
{
    global $passed, $failed, $xfail;
    if ($actual === $expected) {
        echo ($knownIssue ? 'XPASS ' : 'PASS  ') . $name . "\n";
        $passed++;
        return;
    }
    if ($knownIssue) {
        echo "XFAIL {$name}\n";
        echo '      expected: ' . valueToString($expected) . "\n";
        echo '      actual:   ' . valueToString($actual) . "\n";
        $xfail++;
        return;
    }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . valueToString($expected) . "\n";
    echo '      actual:   ' . valueToString($actual) . "\n";
    $failed++;
}

function privateStatic(string $class, string $method, array $args = [])
{
    $ref = new ReflectionMethod($class, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (array_diff(scandir($dir) ?: [], ['.','..']) as $name) {
        $path = $dir . '/' . $name;
        if (is_dir($path)) rrmdir($path); else @unlink($path);
    }
    @rmdir($dir);
}

echo "MAX Search regression suite\n";
echo "===========================\n\n";

$r = DateParser::resolveDate('туры 28-31.08.2026');
check('date range 28-31.08.2026 midpoint', $r['date'] ?? null, '30.08.2026');
check('date range from preserved', $r['range_from'] ?? null, '28.08.2026');
check('date range to preserved', $r['range_to'] ?? null, '31.08.2026');
$r = DateParser::resolveDate('вылет 28.08.2026');
check('single numeric date', $r['date'] ?? null, '28.08.2026');
check('short beginning', DateParser::parseShortDay('в начале'), 5);
check('short middle', DateParser::parseShortDay('середина'), 15);
check('short end', DateParser::parseShortDay('конец'), 25);

check('month noise апреля', DateNoiseGuard::isMonthWord('апреля'), true);
check('month noise августа', DateNoiseGuard::isMonthWord('августа'), true);
check('ordinary word is not month', DateNoiseGuard::isMonthWord('куда'), false);

check('departure-only Kaliningrad stripped from destination', privateStatic(DestinationAreaResolver::class, 'destinationPart', ['туры из Калининграда на неделю на двоих']), '');
check('departure-only Moscow stripped from destination', privateStatic(DestinationAreaResolver::class, 'destinationPart', ['с вылетом из Москвы 28-31.08']), '');
check('Piter to China keeps China as destination part', privateStatic(DestinationAreaResolver::class, 'destinationPart', ['из Питера в Китай']), 'Китай');
check('generic where-can-I-go has no area tokens', privateStatic(DestinationAreaResolver::class, 'tokens', ['куда можно?']), []);
check('date phrase has no area tokens', privateStatic(DestinationAreaResolver::class, 'tokens', ['15 апреля']), []);
check('meal phrase must not be hotel-area evidence', privateStatic(DestinationAreaResolver::class, 'tokens', ['завтрак и ужин']), []);

$routesFile = __DIR__ . '/fixtures/tourvisor_routes.json';
$fallbacksFile = __DIR__ . '/fixtures/departure_fallbacks.json';
$resolver = new DepartureRouteResolver($routesFile, $fallbacksFile);
$advisor = new DepartureRouteAdvisor($resolver, $fallbacksFile);
$r = $resolver->getDirectDestinations('Калининград', '2026-08');
check('Kaliningrad direct list count', count($r['destinations'] ?? []), 1);
check('Kaliningrad direct country', $r['destinations'][0]['country'] ?? null, 'Турция');
check('Kaliningrad direct flag', $r['destinations'][0]['direct'] ?? null, true);
check('Kaliningrad charter flag', $r['destinations'][0]['charter'] ?? null, true);
$r = $resolver->checkRoute('Калининград', 'Египет', '2026-08');
check('regular/non-direct Egypt is not direct charter', $r['direct_charter'] ?? null, false);
check('regular/non-direct Egypt unavailable for direct-charter flow', $r['available_in_period'] ?? null, false);
$r = $advisor->getDestinations('Калининград', '2026-08');
check('advisor keeps direct city when routes exist', $r['status'] ?? null, 'direct_destinations');
check('advisor does not fallback when direct exists', $r['fallback_used'] ?? null, false);
$r = $advisor->getDestinations('Ярославль', '2026-10');
check('advisor fallback status', $r['status'] ?? null, 'fallback_destinations');
check('advisor fallback city', $r['fallback_departure'] ?? null, 'Москва');
check('advisor fallback destinations count', count($r['destinations'] ?? []), 2);
$r = $resolver->resolve('Калининград', 'Таиланд', '2026-10');
check('specific unavailable route finds configured fallback', $r['status'] ?? null, 'fallback_available');
check('specific route fallback city', $r['fallback']['fallback_departure'] ?? null, 'Москва');
$r = $advisor->getDestinations('Неизвестный город', '2026-10');
check('unknown departure stays explicit error', $r['status'] ?? null, 'departure_not_found');

$tmp = sys_get_temp_dir() . '/max-search-regression-' . bin2hex(random_bytes(4));
@mkdir($tmp, 0755, true);
try {
    $saved = TrafficAttributionService::save($tmp, -123, '999888777', '54', '710891647', 'raw-payload');
    check('traffic save succeeds', is_array($saved), true);
    $meta = TrafficAttributionService::get($tmp, -123);
    check('traffic yclid roundtrip', $meta['yclid'] ?? null, '999888777');
    check('traffic region roundtrip', $meta['region_id'] ?? null, '54');
    check('traffic campaign roundtrip', $meta['campaign_id'] ?? null, '710891647');
    check('miniapp payload keeps attribution', TrafficAttributionService::buildMiniappUrl('https://max.ru/test_bot', $meta, ''), 'https://max.ru/test_bot?startapp=999888777_region_54_campaign_710891647');
    check('latest yclid overrides cached value', TrafficAttributionService::buildMiniappUrl('https://max.ru/test_bot', $meta, '111222333'), 'https://max.ru/test_bot?startapp=111222333_region_54_campaign_710891647');

    $funnelOk = AnalyticsService::funnel($tmp, -123, 'test_event', ['x'=>1], $meta);
    check('analytics funnel write succeeds', $funnelOk, true);
    check('analytics funnel file created', is_file($tmp . '/funnel.csv'), true);

    $metricOk = AnalyticsService::queueMetrika($tmp, -123, '999888777', 'test_goal', $meta);
    check('metrika queue write succeeds', $metricOk, true);
    check('metrika queue file created', is_file($tmp . '/metrika_offline_queue.csv'), true);

    check('followup schedule succeeds', FollowupQueueService::schedule($tmp, -123, 180, 1000), true);
    $followupFile = FollowupQueueService::file($tmp, -123);
    check('followup file created', is_file($followupFile), true);
    $entry = FollowupQueueService::readFile($followupFile);
    check('followup file parses', $entry['ok'] ?? false, true);
    check('followup send_at preserved', $entry['data']['send_at'] ?? null, 1180);
    check('followup waiting classification', FollowupQueueService::classify($entry['data'] ?? [], 1100)['status'] ?? null, 'waiting');
    check('followup waiting seconds', FollowupQueueService::classify($entry['data'] ?? [], 1100)['seconds'] ?? null, 80);
    check('followup due classification', FollowupQueueService::classify($entry['data'] ?? [], 1200)['status'] ?? null, 'due');
    check('followup list contains queue file', count(FollowupQueueService::list($tmp)), 1);
    check('followup cancel succeeds', FollowupQueueService::cancel($tmp, -123), true);
    check('followup file removed', is_file($followupFile), false);
} finally {
    rrmdir($tmp);
}

check('MAX transport converts internal negative chat id', MaxTransport::externalUserId(-123456), 123456);
check('MAX transport keeps positive chat id', MaxTransport::externalUserId(123456), 123456);
$buttons = MaxTransport::convertButtons([
    [
        ['text'=>'Callback','callback_data'=>'pick_1'],
        ['text'=>'Link','url'=>'https://example.com'],
    ],
    [
        ['text'=>'Phone','request_contact'=>true],
    ],
]);
check('MAX callback button type', $buttons[0][0]['type'] ?? null, 'callback');
check('MAX callback payload preserved', $buttons[0][0]['payload'] ?? null, 'pick_1');
check('MAX link button type', $buttons[0][1]['type'] ?? null, 'link');
check('MAX request contact type', $buttons[1][0]['type'] ?? null, 'request_contact');
check('MAX message id from message.body.mid', MaxTransport::extractMessageId(['message'=>['body'=>['mid'=>'m1']]]), 'm1');
check('MAX message id from body.mid fallback', MaxTransport::extractMessageId(['body'=>['mid'=>'m2']]), 'm2');
check('MAX message id false for invalid response', MaxTransport::extractMessageId(false), false);

$total = $passed + $failed + $xfail;
echo "\n---------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed} | XFAIL {$xfail}\n";
exit($failed > 0 ? 1 : 0);
