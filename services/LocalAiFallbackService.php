<?php

class LocalAiFallbackService
{
    public static function classify(string $userText): array
    {
        $routeLen = function_exists('mb_strlen')
            ? mb_strlen($userText, 'UTF-8')
            : strlen($userText);

        $rich =
            $routeLen > 55 ||
            substr_count($userText, ',') >= 2 ||
            preg_match('/\b\d+\s*(?:взросл|реб[её]н|дет)/ui', $userText) ||
            preg_match('/\b\d+\s*(?:-|–|—)\s*\d+\s*ноч/ui', $userText) ||
            preg_match('/(?:отел|зв[её]зд|пляж|понтон|бухт|курорт|район|шарм|хургада)/ui', $userText);

        return [
            'length' => $routeLen,
            'rich' => (bool)$rich,
            'simple' => $routeLen <= 55
                && !preg_match('/(?:где\s+дешевле|что\s+лучше|сравни|посоветуй|почему)/ui', $userText),
        ];
    }

    public static function parameters(string $userText, array $current): array
    {
        $params = [];
        $localText = self::lower($userText);

        if (empty($current['city'])) {
            $params['city'] = 'Москва';
        }

        // Country recognition must not depend on mbstring being loaded in the
        // production PHP binary. PCRE /ui gives us Unicode-aware case-insensitive
        // matching for explicit short answers such as "Египет".
        $countries = [
            'турц'=>'Турция',
            'егип'=>'Египет',
            'таиланд'=>'Таиланд',
            'тайланд'=>'Таиланд',
            'оаэ'=>'ОАЭ',
            'эмират'=>'ОАЭ',
            'мальдив'=>'Мальдивы',
            'шри-ланк'=>'Шри-Ланка',
            'китай'=>'Китай',
            'хайнан'=>'Китай',
        ];
        foreach ($countries as $stem => $name) {
            if (preg_match('/'.preg_quote($stem, '/').'/ui', $userText)) {
                $params['country'] = $name;
                break;
            }
        }

        if (
            strpos($localText, 'на двоих') !== false ||
            strpos($localText, 'вдвоем') !== false ||
            strpos($localText, 'вдвоём') !== false
        ) {
            $params['adults'] = 2;
            $params['children'] = 0;
        }

        if (
            strpos($localText, 'без детей') !== false ||
            strpos($localText, 'детей нет') !== false
        ) {
            $params['children'] = 0;
        }

        if (preg_match('/(?:на\s+)?недел(?:ю|ьку)/ui', $userText)) {
            $params['nights'] = '7';
        }

        return $params;
    }

    public static function applyDestinationDefaults(array $params, array $current): array
    {
        if (empty($params)) return $params;

        $country = (string)($params['country'] ?? ($current['country'] ?? ''));
        if (preg_match('/^(?:турция|египет)$/ui', trim($country))) {
            if (empty($current['meal'])) $params['meal'] = 'all_inclusive';
            if (empty($current['stars'])) $params['stars'] = 4;
        }

        return $params;
    }

    public static function unresolvedDestination(array $missingBefore, array $missingAfter): bool
    {
        return in_array('country', $missingBefore, true)
            && in_array('country', $missingAfter, true);
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
