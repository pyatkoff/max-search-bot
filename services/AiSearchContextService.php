<?php

class AiSearchContextService
{
    public static function contextFromSaved(array $saved, array $status, callable $cityById, callable $countryById): array
    {
        $out = [];

        if (!empty($saved[$status['city']])) {
            $city = $cityById($saved[$status['city']]);
            if ($city !== false && $city !== '') $out['city'] = $city;
        }
        if (!empty($saved[$status['country']])) {
            $country = $countryById($saved[$status['country']]);
            if ($country !== false && $country !== '') $out['country'] = $country;
        }
        if (!empty($saved[$status['adults']])) $out['adults'] = (int)$saved[$status['adults']];
        if (array_key_exists($status['children'], $saved)) $out['children'] = (int)$saved[$status['children']];
        if (!empty($saved[$status['child_ages']])) $out['child_ages'] = (string)$saved[$status['child_ages']];
        if (!empty($saved[$status['stars']])) $out['stars'] = (int)$saved[$status['stars']];
        if (!empty($saved[$status['meal']])) {
            $mealMap = ['999'=>'any','7'=>'all_inclusive','3'=>'breakfast','4'=>'half_board','5'=>'full_board'];
            $out['meal'] = $mealMap[(string)$saved[$status['meal']]] ?? null;
        }
        if (!empty($saved[$status['nights']])) $out['nights'] = (string)$saved[$status['nights']];
        if (!empty($saved[$status['date']])) $out['date'] = (string)$saved[$status['date']];

        return $out;
    }

    public static function missingFromSaved(array $saved, array $status): array
    {
        $missing = [];
        if (empty($saved[$status['city']])) $missing[] = 'city';
        if (empty($saved[$status['country']])) $missing[] = 'country';
        if (empty($saved[$status['adults']])) $missing[] = 'adults';
        if (!array_key_exists($status['children'], $saved)) {
            $missing[] = 'children';
        } elseif ((int)$saved[$status['children']] > 0 && empty($saved[$status['child_ages']])) {
            $missing[] = 'child_ages';
        }
        if (empty($saved[$status['stars']])) $missing[] = 'stars';
        if (empty($saved[$status['meal']])) $missing[] = 'meal';
        if (empty($saved[$status['nights']])) $missing[] = 'nights';
        if (empty($saved[$status['date']])) $missing[] = 'date';
        return $missing;
    }

    /**
     * Convert AI/router parameters to validated storage-ready values.
     * City/country resolution is injected so this service stays independent of Bitrix.
     */
    public static function normalizeParameters(array $p, callable $resolveCity, callable $resolveCountry, ?callable $dateValidator = null): array
    {
        $out = [];

        if (!empty($p['city'])) {
            $cityName = trim((string)$p['city']);
            $aliases = [
                'москва'=>1, 'санкт-петербург'=>5, 'с.петербург'=>5, 'спб'=>5,
                'казань'=>10, 'красноярск'=>12, 'без перелета'=>99, 'без перелёта'=>99
            ];
            $key = self::lower($cityName);
            $cityId = $aliases[$key] ?? null;
            if (!$cityId) $cityId = $resolveCity($cityName);
            if ($cityId) $out['city'] = (int)$cityId;
        }

        if (!empty($p['country'])) {
            $countryName = trim((string)$p['country']);
            $aliases = [
                'турция'=>4,'египет'=>1,'таиланд'=>2,'оаэ'=>9,
                'объединенные арабские эмираты'=>9,'объединённые арабские эмираты'=>9,
                'мальдивы'=>8,'шри-ланка'=>12
            ];
            $key = self::lower($countryName);
            $countryId = $aliases[$key] ?? null;
            if (!$countryId) $countryId = $resolveCountry($countryName);
            if ($countryId) $out['country'] = (int)$countryId;
        }

        if (isset($p['adults'])) {
            $value = (int)$p['adults'];
            if ($value >= 1 && $value <= 6) $out['adults'] = $value;
        }
        if (isset($p['children'])) {
            $value = (int)$p['children'];
            if ($value >= 0 && $value <= 3) $out['children'] = $value;
        }

        if (!empty($p['child_ages'])) {
            $ages = is_array($p['child_ages']) ? $p['child_ages'] : preg_split('/[\s,;]+/', (string)$p['child_ages']);
            $clean = [];
            foreach ((array)$ages as $age) {
                if ($age === '' || $age === null) continue;
                $age = (int)$age;
                if ($age >= 0 && $age <= 17) $clean[] = $age;
            }
            if ($clean) $out['child_ages'] = implode(', ', $clean);
        }

        if (isset($p['stars'])) {
            $value = (int)$p['stars'];
            if ($value >= 1 && $value <= 5) $out['stars'] = $value;
        }

        if (!empty($p['meal'])) {
            $mealMap = ['any'=>'999','all_inclusive'=>'7','breakfast'=>'3','half_board'=>'4','full_board'=>'5'];
            if (isset($mealMap[$p['meal']])) $out['meal'] = $mealMap[$p['meal']];
        }

        if (!empty($p['nights']) && preg_match('/^(\d{1,2})(?:-(\d{1,2}))?$/', trim((string)$p['nights']), $m)) {
            $a = (int)$m[1];
            $b = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : $a;
            if ($a >= 1 && $a <= 28 && $b >= 1 && $b <= 28 && $a <= $b) {
                $out['nights'] = $a === $b ? (string)$a : ($a . '-' . $b);
            }
        }

        if (!empty($p['date'])) {
            $date = trim((string)$p['date']);
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $date)) {
                $valid = $dateValidator ? (bool)$dateValidator($date) : true;
                if ($valid) $out['date'] = $date;
            }
        }

        return $out;
    }

    public static function storageMap(array $status): array
    {
        return [
            'city'=>$status['city'],
            'country'=>$status['country'],
            'adults'=>$status['adults'],
            'children'=>$status['children'],
            'child_ages'=>$status['child_ages'],
            'stars'=>$status['stars'],
            'meal'=>$status['meal'],
            'nights'=>$status['nights'],
            'date'=>$status['date'],
        ];
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
    }
}
