<?php

declare(strict_types=1);

require_once __DIR__ . '/../handlers/AiDateHandler.php';
require_once __DIR__ . '/../services/DestinationAreaResolver.php';
require_once __DIR__ . '/../DepartureRouteResolver.php';
require_once __DIR__ . '/../DepartureRouteAdvisor.php';

$passed = 0;
$failed = 0;

function convValue($value): string
{
    if (is_bool($value)) return $value ? 'true' : 'false';
    if ($value === null) return 'null';
    if (is_scalar($value)) return (string)$value;
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function convCheck(string $scenario, string $name, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "PASS  [{$scenario}] {$name}\n";
        $passed++;
        return;
    }

    echo "FAIL  [{$scenario}] {$name}\n";
    echo '      expected: ' . convValue($expected) . "\n";
    echo '      actual:   ' . convValue($actual) . "\n";
    $failed++;
}

function convPrivateStatic(string $class, string $method, array $args = [])
{
    $ref = new ReflectionMethod($class, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
}

function cleanupPending($chatId): void
{
    AiDateHandler::clear($chatId);
}

echo "MAX Search conversation regression suite\n";
echo "========================================\n\n";

$routesFile = __DIR__ . '/fixtures/tourvisor_routes.json';
$fallbacksFile = __DIR__ . '/fixtures/departure_fallbacks.json';
$resolver = new DepartureRouteResolver($routesFile, $fallbacksFile);
$advisor = new DepartureRouteAdvisor($resolver, $fallbacksFile);

// 1. Real regression shape: departure-only request, then "куда можно?".
$scenario = 'Kaliningrad -> where can I go';
$first = 'туры из Калининграда на неделю на двоих в августе';
convCheck(
    $scenario,
    'departure-only text creates no destination part',
    convPrivateStatic(DestinationAreaResolver::class, 'destinationPart', [$first]),
    ''
);
convCheck(
    $scenario,
    'where-can-I-go creates no area tokens',
    convPrivateStatic(DestinationAreaResolver::class, 'tokens', ['куда можно?']),
    []
);
$advice = $advisor->getDestinations('Калининград', '2026-08');
convCheck($scenario, 'keeps Kaliningrad when direct route exists', $advice['fallback_used'] ?? null, false);
convCheck($scenario, 'offers direct destinations', $advice['status'] ?? null, 'direct_destinations');
convCheck($scenario, 'first offered destination', $advice['destinations'][0]['country'] ?? null, 'Турция');

// 2. Regional city with no direct programmes must suggest fallback, not silently replace departure.
$scenario = 'Yaroslavl -> where can I go';
$advice = $advisor->getDestinations('Ярославль', '2026-10');
convCheck($scenario, 'fallback is explicit', $advice['fallback_used'] ?? null, true);
convCheck($scenario, 'requested departure preserved', $advice['requested_departure'] ?? null, 'Ярославль');
convCheck($scenario, 'fallback departure is Moscow', $advice['fallback_departure'] ?? null, 'Москва');
convCheck($scenario, 'fallback has destinations', count($advice['destinations'] ?? []) > 0, true);

// 3. Pending month dialogue: month -> short numeric range.
$scenario = 'August -> 28-31';
$chat = -990001;
cleanupPending($chat);
$month = AiDateHandler::rememberMonthFromText($chat, 'в августе');
convCheck($scenario, 'month remembered', $month['month'] ?? null, 8);
convCheck($scenario, 'month-only has no exact date', $month['date'] ?? null, null);
$date = AiDateHandler::resolvePendingShortDate($chat, '28-31');
convCheck($scenario, 'range resolves without asking month again', $date, '30.08.2026');
convCheck($scenario, 'pending month cleared after range', PendingMonthStore::get($chat), []);
cleanupPending($chat);

// 4. Pending month dialogue: month -> natural end-of-month answer.
$scenario = 'September -> end of month';
$chat = -990002;
cleanupPending($chat);
$month = AiDateHandler::rememberMonthFromText($chat, 'в сентябре');
convCheck($scenario, 'month remembered', $month['month'] ?? null, 9);
$date = AiDateHandler::resolvePendingShortDate($chat, 'в конце месяца');
convCheck($scenario, 'end of month resolves', $date, '27.09.2026');
convCheck($scenario, 'pending month cleared', PendingMonthStore::get($chat), []);
cleanupPending($chat);

// 5. Existing destination followed by a date phrase must not create a new destination area.
$scenario = 'Vietnam -> 15 April';
convCheck(
    $scenario,
    'date follow-up has no destination tokens',
    convPrivateStatic(DestinationAreaResolver::class, 'tokens', ['15 апреля']),
    []
);
$date = DateParser::resolveDate('15 апреля');
convCheck($scenario, 'date itself is parsed', !empty($date['date']), true);

// 6. Meal follow-up must never be treated as hotel/area evidence.
$scenario = 'Meal follow-up';
convCheck(
    $scenario,
    'breakfast and dinner have no area tokens',
    convPrivateStatic(DestinationAreaResolver::class, 'tokens', ['завтрак и ужин']),
    []
);
convCheck(
    $scenario,
    'all inclusive has no area tokens',
    convPrivateStatic(DestinationAreaResolver::class, 'tokens', ['все включено']),
    []
);

// 7. Explicit country with no direct charter from regional city must use configured fallback.
$scenario = 'Kaliningrad -> Thailand in October';
$route = $resolver->resolve('Калининград', 'Таиланд', '2026-10');
convCheck($scenario, 'status is fallback_available', $route['status'] ?? null, 'fallback_available');
convCheck(
    $scenario,
    'fallback departure is Moscow',
    $route['fallback']['fallback_departure'] ?? null,
    'Москва'
);
convCheck(
    $scenario,
    'requested departure is preserved',
    $route['fallback']['requested_departure'] ?? null,
    'Калининград'
);

// 8. A route existing in another season must not be treated as available in requested month.
$scenario = 'Kaliningrad -> Turkey in October';
$route = $resolver->resolve('Калининград', 'Турция', '2026-10');
convCheck($scenario, 'direct route exists as a programme', $route['route']['direct_charter'] ?? null, true);
convCheck($scenario, 'but has no dates in October', $route['route']['available_in_period'] ?? null, false);
convCheck($scenario, 'no invented fallback', $route['status'] ?? null, 'not_found');

// 9. City with no programmes can still resolve a concrete destination through fallback.
$scenario = 'Yaroslavl -> Thailand in October';
$route = $resolver->resolve('Ярославль', 'Таиланд', '2026-10');
convCheck($scenario, 'status is fallback_available', $route['status'] ?? null, 'fallback_available');
convCheck(
    $scenario,
    'fallback departure is Moscow',
    $route['fallback']['fallback_departure'] ?? null,
    'Москва'
);

$total = $passed + $failed;
echo "\n----------------------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";

exit($failed > 0 ? 1 : 0);
