<?php
declare(strict_types=1);

require_once __DIR__ . '/DepartureCityValueContract.php';

/** Match advertising geography to active departure names, without selecting a city. */
final class DepartureSuggestionResolver
{
    public static function resolve(array $region, array $activeDepartures, array $overrides = []): array
    {
        $regionId = (string)($region['id'] ?? '');
        if (!preg_match('/\A[1-9][0-9]{0,19}\z/D', $regionId)) return [];
        $names = array_key_exists($regionId, $overrides)
            ? $overrides[$regionId] : ($region['departure_names'] ?? []);
        if (!is_array($names)) return [];

        $directory = [];
        foreach ($activeDepartures as $row) {
            if (!is_array($row)) continue;
            $id = DepartureCityValueContract::fromDirectoryId($row['id'] ?? null);
            $name = self::label($row['name'] ?? null);
            if ($id === null || $id === (string)DepartureCityValueContract::NO_FLIGHT_ID || $name === '') continue;
            $directory[self::key($name)][$id] = ['id'=>$id, 'name'=>$name];
        }

        $candidates = [];
        foreach (array_slice($names, 0, 8) as $name) {
            $name = self::label($name);
            if ($name === '') continue;
            $matches = $directory[self::key($name)] ?? [];
            // Ambiguous names must not silently choose a different catalogue record.
            if (count($matches) !== 1) continue;
            $candidate = array_values($matches)[0];
            $candidates[$candidate['id']] = $candidate;
            if (count($candidates) >= 3) break;
        }
        if ($candidates === []) return [];
        return ['region_id'=>$regionId, 'region_name'=>self::label($region['name'] ?? null),
            'candidates'=>array_values($candidates)];
    }

    private static function label($value): string
    {
        if (!is_string($value) || strlen($value) > 400 || preg_match('/[\x00-\x1f\x7f]/', $value)) return '';
        return trim($value);
    }

    private static function key(string $name): string
    {
        $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $name = str_replace('ё', 'е', $name);
        $aliases = ['санкт-петербург'=>'с.петербург', 'санкт петербург'=>'с.петербург',
            'нижний новгород'=>'н.новгород', 'минеральные воды'=>'мин.воды',
            'набережные челны'=>'наб.челны', 'петропавловск-камчатский'=>'п.камчатский',
            'южно-сахалинск'=>'ю.сахалинск'];
        return preg_replace('/[\s.]+/u', '', $aliases[$name] ?? $name) ?? '';
    }
}
