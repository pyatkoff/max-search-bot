<?php

declare(strict_types=1);

require_once __DIR__ . '/../DepartureRouteResolver.php';
require_once __DIR__ . '/../DepartureRouteAdvisor.php';
require_once __DIR__ . '/../services/DestinationPreferenceResolver.php';

$routesFile = __DIR__ . '/fixtures/tourvisor_routes.json';
$fallbacksFile = __DIR__ . '/fixtures/departure_fallbacks.json';
$catalogFile = __DIR__ . '/conversations/recommendations.json';

$catalog = json_decode((string)file_get_contents($catalogFile), true);
if (!is_array($catalog)) {
    fwrite(STDERR, "Invalid conversation catalog JSON\n");
    exit(2);
}

$resolver = new DepartureRouteResolver($routesFile, $fallbacksFile);
$advisor = new DepartureRouteAdvisor($resolver, $fallbacksFile);
$passed = 0;
$failed = 0;

function catCheck(string $scenario, string $field, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "PASS  [{$scenario}] {$field}\n";
        $passed++;
        return;
    }
    echo "FAIL  [{$scenario}] {$field}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

foreach ($catalog as $case) {
    $name = (string)($case['name'] ?? 'unnamed');
    $type = (string)($case['type'] ?? '');
    $expect = (array)($case['expect'] ?? []);

    if ($type === 'route_discovery') {
        $result = $advisor->getDestinations((string)$case['departure'], $case['period'] ?? null);
        if (array_key_exists('fallback_used', $expect)) catCheck($name, 'fallback_used', $result['fallback_used'] ?? null, $expect['fallback_used']);
        if (array_key_exists('status', $expect)) catCheck($name, 'status', $result['status'] ?? null, $expect['status']);
        if (array_key_exists('fallback_departure', $expect)) catCheck($name, 'fallback_departure', $result['fallback_departure'] ?? null, $expect['fallback_departure']);
        if (array_key_exists('first_country', $expect)) catCheck($name, 'first_country', $result['destinations'][0]['country'] ?? null, $expect['first_country']);
        continue;
    }

    if ($type === 'route_check') {
        $result = $resolver->resolve((string)$case['departure'], (string)$case['country'], $case['period'] ?? null);
        if (array_key_exists('status', $expect)) catCheck($name, 'status', $result['status'] ?? null, $expect['status']);
        if (array_key_exists('fallback_departure', $expect)) catCheck($name, 'fallback_departure', $result['fallback']['fallback_departure'] ?? null, $expect['fallback_departure']);
        continue;
    }

    if ($type === 'preference_intent') {
        $intent = DestinationPreferenceResolver::detectIntent((string)($case['text'] ?? ''));
        catCheck($name, 'intent', $intent, $expect['intent'] ?? null);
        continue;
    }

    echo "FAIL  [{$name}] unsupported type {$type}\n";
    $failed++;
}

$total = $passed + $failed;
echo "\n----------------------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
