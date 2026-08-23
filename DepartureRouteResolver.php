<?php
/**
 * DepartureRouteResolver
 *
 * Работает ТОЛЬКО с локальными файлами:
 *   - tourvisor_routes.json
 *   - departure_fallbacks.json
 *
 * Никаких запросов к Tourvisor API в момент работы.
 */

declare(strict_types=1);

final class DepartureRouteResolver
{
    private array $routes;
    private array $fallbacks;

    public function __construct(
        string $routesFile = __DIR__ . '/tourvisor_routes.json',
        string $fallbacksFile = __DIR__ . '/departure_fallbacks.json'
    ) {
        $this->routes = $this->loadJson($routesFile);

        $this->fallbacks = is_file($fallbacksFile)
            ? $this->loadJson($fallbacksFile)
            : [];
    }

    private function loadJson(string $file): array
    {
        if (!is_file($file)) {
            throw new RuntimeException("Файл не найден: {$file}");
        }

        $json = file_get_contents($file);
        if ($json === false) {
            throw new RuntimeException("Не удалось прочитать файл: {$file}");
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException("Некорректный JSON: {$file}");
        }

        return $data;
    }

    public function getDirectDestinations(string $departure, ?string $period = null): array
    {
        $dep = $this->findDeparture($departure);

        if (!$dep) {
            return [
                'ok' => false,
                'reason' => 'departure_not_found',
                'departure' => $departure,
                'destinations' => [],
            ];
        }

        $result = [];

        foreach (($dep['countries'] ?? []) as $country) {
            $dates = $this->filterDates((array)($country['dates'] ?? []), $period);

            if ($period !== null && !$dates) {
                continue;
            }

            if ((int)($country['dates_count'] ?? 0) <= 0 && $period === null) {
                continue;
            }

            $result[] = [
                'country_id' => (int)($country['country_id'] ?? 0),
                'country' => (string)($country['country'] ?? ''),
                'direct' => (bool)($country['direct'] ?? false),
                'charter' => (bool)($country['charter'] ?? false),
                'dates_count' => $period !== null ? count($dates) : (int)($country['dates_count'] ?? 0),
                'first_date' => $period !== null ? ($dates[0] ?? null) : ($country['first_date'] ?? null),
                'last_date' => $period !== null ? ($dates ? $dates[count($dates)-1] : null) : ($country['last_date'] ?? null),
                'dates' => $period !== null ? $dates : [],
            ];
        }

        usort($result, static fn(array $a, array $b): int => $b['dates_count'] <=> $a['dates_count']);

        return [
            'ok' => true,
            'departure_id' => (int)($dep['id'] ?? 0),
            'departure' => (string)($dep['name'] ?? $departure),
            'period' => $period,
            'destinations' => $result,
        ];
    }

    public function checkRoute(string $departure, string $country, ?string $period = null): array
    {
        $dep = $this->findDeparture($departure);

        if (!$dep) {
            return [
                'ok' => false,
                'reason' => 'departure_not_found',
                'departure' => $departure,
                'country' => $country,
            ];
        }

        $route = $this->findCountryInDeparture($dep, $country);

        if (!$route) {
            return [
                'ok' => true,
                'departure' => (string)$dep['name'],
                'country' => $country,
                'direct_charter' => false,
                'available_in_period' => false,
                'dates' => [],
            ];
        }

        $dates = $this->filterDates((array)($route['dates'] ?? []), $period);

        return [
            'ok' => true,
            'departure' => (string)$dep['name'],
            'country' => (string)($route['country'] ?? $country),
            'direct_charter' => true,
            'available_in_period' => $period === null
                ? ((int)($route['dates_count'] ?? 0) > 0)
                : !empty($dates),
            'dates_count' => $period === null
                ? (int)($route['dates_count'] ?? 0)
                : count($dates),
            'dates' => $period === null ? [] : $dates,
        ];
    }

    public function getFallback(string $departure, string $country, ?string $period = null): array
    {
        $fallbackList = $this->fallbacks[$departure] ?? $this->fallbacks[$this->normalize($departure)] ?? [];

        if (!is_array($fallbackList)) {
            $fallbackList = [$fallbackList];
        }

        $checked = [];

        foreach ($fallbackList as $fallbackDeparture) {
            $fallbackDeparture = trim((string)$fallbackDeparture);
            if ($fallbackDeparture === '') {
                continue;
            }

            $check = $this->checkRoute($fallbackDeparture, $country, $period);
            $checked[] = $check;

            if (
                ($check['ok'] ?? false) &&
                ($check['direct_charter'] ?? false) &&
                ($check['available_in_period'] ?? false)
            ) {
                return [
                    'ok' => true,
                    'found' => true,
                    'requested_departure' => $departure,
                    'country' => $country,
                    'period' => $period,
                    'fallback_departure' => $check['departure'],
                    'dates_count' => $check['dates_count'] ?? 0,
                    'dates' => $check['dates'] ?? [],
                    'checked' => $checked,
                ];
            }
        }

        return [
            'ok' => true,
            'found' => false,
            'requested_departure' => $departure,
            'country' => $country,
            'period' => $period,
            'checked' => $checked,
        ];
    }

    public function resolve(string $departure, ?string $country = null, ?string $period = null): array
    {
        if ($country === null || trim($country) === '') {
            return $this->getDirectDestinations($departure, $period);
        }

        $route = $this->checkRoute($departure, $country, $period);

        if (
            ($route['direct_charter'] ?? false) &&
            ($route['available_in_period'] ?? false)
        ) {
            return [
                'status' => 'direct_available',
                'route' => $route,
                'fallback' => null,
            ];
        }

        $fallback = $this->getFallback($departure, $country, $period);

        return [
            'status' => ($fallback['found'] ?? false)
                ? 'fallback_available'
                : 'not_found',
            'route' => $route,
            'fallback' => $fallback,
        ];
    }

    private function findDeparture(string $nameOrId): ?array
    {
        $departures = $this->routes['departures'] ?? [];

        if (ctype_digit((string)$nameOrId) && isset($departures[(string)$nameOrId])) {
            return $departures[(string)$nameOrId];
        }

        $needle = $this->normalize($nameOrId);

        foreach ($departures as $dep) {
            $name = $this->normalize((string)($dep['name'] ?? ''));

            if ($name === $needle) {
                return $dep;
            }
        }

        return null;
    }

    private function findCountryInDeparture(array $dep, string $country): ?array
    {
        $needle = $this->normalize($country);

        foreach (($dep['countries'] ?? []) as $route) {
            $name = $this->normalize((string)($route['country'] ?? ''));

            if ($name === $needle) {
                return $route;
            }
        }

        return null;
    }

    private function filterDates(array $dates, ?string $period): array
    {
        if ($period === null || trim($period) === '') {
            return array_values($dates);
        }

        $period = trim($period);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $period)) {
            return in_array($period, $dates, true) ? [$period] : [];
        }

        if (preg_match('/^\d{4}-\d{2}$/', $period)) {
            return array_values(array_filter(
                $dates,
                static fn($d) => is_string($d) && str_starts_with($d, $period . '-')
            ));
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2}):(\d{4}-\d{2}-\d{2})$/', $period, $m)) {
            return array_values(array_filter(
                $dates,
                static fn($d) => is_string($d) && $d >= $m[1] && $d <= $m[2]
            ));
        }

        throw new InvalidArgumentException(
            'Период должен быть YYYY-MM, YYYY-MM-DD или YYYY-MM-DD:YYYY-MM-DD'
        );
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        $aliases = [
            'санкт-петербург' => 'с.петербург',
            'петербург' => 'с.петербург',
            'спб' => 'с.петербург',
            'нижний новгород' => 'н.новгород',
            'минеральные воды' => 'мин.воды',
            'набережные челны' => 'наб.челны',
            'петропавловск-камчатский' => 'п.камчатский',
            'южно-сахалинск' => 'ю.сахалинск',
        ];

        return $aliases[$value] ?? $value;
    }
}
