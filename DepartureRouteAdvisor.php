<?php
/**
 * Adds recommendation/fallback behaviour on top of DepartureRouteResolver.
 *
 * If the requested departure city has no direct charter destinations for the
 * requested period, the advisor checks configured fallback departure cities
 * in order and returns the first one that has direct charter programmes.
 */
declare(strict_types=1);

require_once __DIR__ . '/DepartureRouteResolver.php';

final class DepartureRouteAdvisor
{
    private DepartureRouteResolver $resolver;
    private array $fallbacks = [];

    public function __construct(
        ?DepartureRouteResolver $resolver = null,
        string $fallbacksFile = __DIR__ . '/departure_fallbacks.json'
    ) {
        $this->resolver = $resolver ?? new DepartureRouteResolver();

        if (is_file($fallbacksFile)) {
            $json = file_get_contents($fallbacksFile);
            $data = $json !== false ? json_decode($json, true) : null;
            if (is_array($data)) {
                foreach ($data as $departure => $fallbackList) {
                    $this->fallbacks[$this->normalize((string)$departure)] = is_array($fallbackList)
                        ? array_values($fallbackList)
                        : [$fallbackList];
                }
            }
        }
    }

    public function getDestinations(string $departure, ?string $period = null): array
    {
        $direct = $this->resolver->getDirectDestinations($departure, $period);

        if (!empty($direct['ok']) && !empty($direct['destinations'])) {
            $direct['status'] = 'direct_destinations';
            $direct['fallback_used'] = false;
            $direct['requested_departure'] = $departure;
            return $direct;
        }

        // If even the departure city itself is unknown, do not hide that error
        // behind fallback logic.
        if (empty($direct['ok']) && (($direct['reason'] ?? '') === 'departure_not_found')) {
            $direct['status'] = 'departure_not_found';
            $direct['fallback_used'] = false;
            return $direct;
        }

        $checked = [];
        $fallbackList = $this->fallbacks[$this->normalize($departure)] ?? [];

        foreach ($fallbackList as $fallbackDeparture) {
            $fallbackDeparture = trim((string)$fallbackDeparture);
            if ($fallbackDeparture === '') continue;

            $candidate = $this->resolver->getDirectDestinations($fallbackDeparture, $period);
            $checked[] = [
                'departure' => $fallbackDeparture,
                'ok' => (bool)($candidate['ok'] ?? false),
                'destinations_count' => count((array)($candidate['destinations'] ?? [])),
            ];

            if (!empty($candidate['ok']) && !empty($candidate['destinations'])) {
                return [
                    'ok' => true,
                    'status' => 'fallback_destinations',
                    'requested_departure' => $departure,
                    'departure_id' => $direct['departure_id'] ?? 0,
                    'departure' => $direct['departure'] ?? $departure,
                    'period' => $period,
                    'fallback_used' => true,
                    'fallback_departure_id' => (int)($candidate['departure_id'] ?? 0),
                    'fallback_departure' => (string)($candidate['departure'] ?? $fallbackDeparture),
                    'destinations' => (array)$candidate['destinations'],
                    'checked_fallbacks' => $checked,
                ];
            }
        }

        return [
            'ok' => (bool)($direct['ok'] ?? false),
            'status' => 'no_destinations',
            'requested_departure' => $departure,
            'departure_id' => $direct['departure_id'] ?? 0,
            'departure' => $direct['departure'] ?? $departure,
            'period' => $period,
            'fallback_used' => false,
            'destinations' => [],
            'checked_fallbacks' => $checked,
        ];
    }

    public function resolve(string $departure, ?string $country = null, ?string $period = null): array
    {
        if ($country === null || trim($country) === '') {
            return $this->getDestinations($departure, $period);
        }

        return $this->resolver->resolve($departure, $country, $period);
    }

    private function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower')
            ? mb_strtolower(trim($value), 'UTF-8')
            : strtolower(trim($value));

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
