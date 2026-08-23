<?php

require_once(__DIR__ . '/../DepartureRouteResolver.php');

final class RouteResolverSelfTest
{
    public static function run(): array
    {
        $startedAt = microtime(true);
        $tests = [];
        $passed = 0;
        $failed = 0;

        try {
            $resolver = new DepartureRouteResolver();
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'generated_at' => date('c'),
                'passed' => 0,
                'failed' => 1,
                'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
                'tests' => [[
                    'name' => 'resolver_init',
                    'ok' => false,
                    'error' => $e->getMessage(),
                ]],
            ];
        }

        $cases = [
            [
                'name' => 'kaliningrad_september_discovery',
                'run' => static function() use ($resolver) {
                    $result = $resolver->getDirectDestinations('Калининград', '2026-09');
                    $countries = array_values(array_map(static function($row) {
                        return (string)($row['country'] ?? '');
                    }, (array)($result['destinations'] ?? [])));

                    $ok = ($result['ok'] ?? false)
                        && in_array('Турция', $countries, true)
                        && in_array('Египет', $countries, true);

                    return [
                        'ok' => $ok,
                        'result' => [
                            'departure' => $result['departure'] ?? null,
                            'period' => $result['period'] ?? null,
                            'countries' => $countries,
                        ],
                        'expect' => 'В сентябре из Калининграда есть как минимум Турция и Египет',
                    ];
                },
            ],
            [
                'name' => 'kaliningrad_thailand_december_no_direct',
                'run' => static function() use ($resolver) {
                    $result = $resolver->checkRoute('Калининград', 'Таиланд', '2026-12');
                    $ok = ($result['ok'] ?? false)
                        && !($result['available_in_period'] ?? false);

                    return [
                        'ok' => $ok,
                        'result' => $result,
                        'expect' => 'Прямого доступного чартера Калининград → Таиланд в декабре нет',
                    ];
                },
            ],
            [
                'name' => 'kaliningrad_thailand_december_fallback',
                'run' => static function() use ($resolver) {
                    $result = $resolver->getFallback('Калининград', 'Таиланд', '2026-12');
                    $ok = ($result['found'] ?? false)
                        && (($result['fallback_departure'] ?? '') === 'Москва');

                    return [
                        'ok' => $ok,
                        'result' => [
                            'found' => $result['found'] ?? false,
                            'fallback_departure' => $result['fallback_departure'] ?? null,
                            'dates_count' => $result['dates_count'] ?? 0,
                        ],
                        'expect' => 'Для Таиланда из Калининграда в декабре fallback = Москва',
                    ];
                },
            ],
            [
                'name' => 'kaliningrad_turkey_december_not_available',
                'run' => static function() use ($resolver) {
                    $result = $resolver->checkRoute('Калининград', 'Турция', '2026-12');
                    $ok = ($result['ok'] ?? false)
                        && ($result['direct_charter'] ?? false)
                        && !($result['available_in_period'] ?? false);

                    return [
                        'ok' => $ok,
                        'result' => $result,
                        'expect' => 'Маршрут прямого чартера существует, но на декабрь дат нет',
                    ];
                },
            ],
            [
                'name' => 'yaroslavl_egypt_december_fallback',
                'run' => static function() use ($resolver) {
                    $route = $resolver->checkRoute('Ярославль', 'Египет', '2026-12');
                    $fallback = $resolver->getFallback('Ярославль', 'Египет', '2026-12');
                    $ok = ($route['ok'] ?? false)
                        && !($route['available_in_period'] ?? false)
                        && ($fallback['found'] ?? false)
                        && (($fallback['fallback_departure'] ?? '') === 'Москва');

                    return [
                        'ok' => $ok,
                        'result' => [
                            'direct_available' => $route['available_in_period'] ?? false,
                            'fallback_found' => $fallback['found'] ?? false,
                            'fallback_departure' => $fallback['fallback_departure'] ?? null,
                        ],
                        'expect' => 'Ярославль → Египет в декабре предлагает Москву',
                    ];
                },
            ],
        ];

        foreach ($cases as $case) {
            $caseStartedAt = microtime(true);
            try {
                $data = $case['run']();
                $ok = (bool)($data['ok'] ?? false);
                if ($ok) $passed++; else $failed++;

                $tests[] = [
                    'name' => $case['name'],
                    'ok' => $ok,
                    'duration_ms' => (int)round((microtime(true) - $caseStartedAt) * 1000),
                    'expect' => $data['expect'] ?? '',
                    'result' => $data['result'] ?? null,
                ];
            } catch (\Throwable $e) {
                $failed++;
                $tests[] = [
                    'name' => $case['name'],
                    'ok' => false,
                    'duration_ms' => (int)round((microtime(true) - $caseStartedAt) * 1000),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'ok' => $failed === 0,
            'generated_at' => date('c'),
            'passed' => $passed,
            'failed' => $failed,
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'tests' => $tests,
        ];
    }
}
