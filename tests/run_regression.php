<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/DateParser.php';
require_once __DIR__ . '/../services/DateNoiseGuard.php';
require_once __DIR__ . '/../services/DestinationAreaResolver.php';
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
        if ($knownIssue) {
            echo "XPASS {$name}\n";
        } else {
            echo "PASS  {$name}\n";
        }
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

echo "MAX Search regression suite\n";
echo "===========================\n\n";

// DateParser: explicit numeric dates and natural short periods.
$r = DateParser::resolveDate('туры 28-31.08.2026');
check('date range 28-31.08.2026 midpoint', $r['date'] ?? null, '30.08.2026');
check('date range from preserved', $r['range_from'] ?? null, '28.08.2026');
check('date range to preserved', $r['range_to'] ?? null, '31.08.2026');

$r = DateParser::resolveDate('вылет 28.08.2026');
check('single numeric date', $r['date'] ?? null, '28.08.2026');
check('short beginning', DateParser::parseShortDay('в начале'), 5);
check('short middle', DateParser::parseShortDay('середина'), 15);
check('short end', DateParser::parseShortDay('конец'), 25);

// Month words must never become destination-area tokens.
check('month noise апреля', DateNoiseGuard::isMonthWord('апреля'), true);
check('month noise августа', DateNoiseGuard::isMonthWord('августа'), true);
check('ordinary word is not month', DateNoiseGuard::isMonthWord('куда'), false);

// Departure-vs-destination regression cases without Bitrix access.
check(
    'departure-only Kaliningrad stripped from destination',
    privateStatic(DestinationAreaResolver::class, 'destinationPart', ['туры из Калининграда на неделю на двоих']),
    ''
);
check(
    'departure-only Moscow stripped from destination',
    privateStatic(DestinationAreaResolver::class, 'destinationPart', ['с вылетом из Москвы 28-31.08']),
    ''
);
check(
    'Piter to China keeps China as destination part',
    privateStatic(DestinationAreaResolver::class, 'destinationPart', ['из Питера в Китай']),
    'Китай'
);
check(
    'generic where-can-I-go has no area tokens',
    privateStatic(DestinationAreaResolver::class, 'tokens', ['куда можно?']),
    []
);
check(
    'date phrase has no area tokens',
    privateStatic(DestinationAreaResolver::class, 'tokens', ['15 апреля']),
    []
);
check(
    'meal phrase must not be hotel-area evidence',
    privateStatic(DestinationAreaResolver::class, 'tokens', ['завтрак и ужин']),
    []
);

// Route resolver uses isolated fixtures and must only expose direct charters.
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

$total = $passed + $failed + $xfail;
echo "\n---------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed} | XFAIL {$xfail}\n";

exit($failed > 0 ? 1 : 0);
