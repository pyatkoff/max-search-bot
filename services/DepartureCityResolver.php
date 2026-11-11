<?php

require_once __DIR__ . '/TravelDirectoryRepository.php';
require_once __DIR__ . '/NeedApplicationService.php';

class DepartureCityResolver
{
    public static function resolveAndStore($chatId, $text, bool $allowFieldAnswer = false)
    {
        $text = trim((string)$text);
        if ($text === '') return false;

        $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $hasDepartureMarker = (bool)preg_match('/(?:^|\s)(?:с\s+вылетом\s+из|вылет(?:ом)?\s+из|из)\s+/ui', $text);
        if (!$hasDepartureMarker && (!$allowFieldAnswer || preg_match('/[?？]/u', $text))) return false;

        try {
            $departures = TravelDirectoryRepository::activeDepartures();
            $best = $hasDepartureMarker
                ? self::bestMatch($lower, $departures)
                : self::bestFieldMatch($text, $departures);
            if (!$best && !$hasDepartureMarker) {
                $best = self::bestCompoundFieldMatch($text, $departures);
            }
            if (!$best) return false;

            $applied = NeedApplicationService::applyParameters($chatId, ['city' => $best['city']]);
            if (empty($applied['city'])) return false;

            // Only a confirmed scalar answer may consume the whole turn. A
            // compound answer retains its remaining trip facts for collection.
            if (!$hasDepartureMarker) {
                $readback = MaxSearchApi::getAiSearchContext($chatId);
                if (trim((string)($readback['city'] ?? '')) !== $best['city']) return false;
                $best['field_answer'] = !isset($best['remaining_text']);
            }

            MaxSearchApi::funnelLog($chatId, 'departure_city_resolved', [
                'city_id' => $best['city_id'],
                'city' => $best['city'],
                'matched' => $best['matched'],
                'source_text' => $text,
            ]);
            return $best;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Resolve a city when the wizard is already explicitly asking for departure. */
    public static function resolveFieldValue($text)
    {
        $text = trim((string)$text);
        if ($text === '') return false;

        try {
            return self::bestFieldMatch($text, TravelDirectoryRepository::activeDepartures());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Read-only clarification candidates; alternatives never select a city. */
    public static function resolveFieldAlternatives($text): array
    {
        $text = trim((string)$text);
        if (!preg_match('/\s+(?:или|либо)\s+/ui', $text)
            || preg_match('/[?？\r\n]/u', $text)) return [];
        try {
            return self::bestFieldAlternatives($text, TravelDirectoryRepository::activeDepartures());
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Only two whole, distinct active-departure answers qualify. */
    public static function bestFieldAlternatives(string $text, array $rows): array
    {
        if (preg_match('/[?？\r\n]/u', $text)) return [];
        $parts = preg_split('/\s+(?:или|либо)\s+/ui', trim($text));
        if (count($parts) !== 2) return [];
        $matches = [];
        foreach ($parts as $part) {
            $part = preg_replace('/\A(?:с\s+вылетом\s+из|вылет(?:ом)?\s+из|из)\s+/ui', '', trim($part));
            $match = self::bestFieldMatch($part, $rows);
            if (!$match || isset($matches[$match['city_id']])) return [];
            $matches[$match['city_id']] = $match;
        }
        return array_values($matches);
    }

    public static function bestMatch(string $lowerText, array $rows)
    {
        // Availability questions and alternatives do not choose a departure.
        if (preg_match('/[?？]|(?:^|[^\p{L}])(?:если|или|ли|например)(?!\p{L})/ui', $lowerText)) return false;

        $matches = [];
        foreach ($rows as $row) {
            $canonical = trim((string)($row['name'] ?? ''));
            $fromName = trim((string)($row['name_genitive'] ?? ''));
            $depId = (int)($row['id'] ?? 0);
            if ($canonical === '' || !$depId) continue;

            foreach (array_values(array_unique(array_filter([$canonical, $fromName]))) as $form) {
                $formLower = function_exists('mb_strtolower') ? mb_strtolower($form, 'UTF-8') : strtolower($form);
                if ($formLower === '') continue;
                $quoted = preg_quote($formLower, '/');
                if (!preg_match_all('/(?:^|\s)(?:с\s+вылетом\s+из|вылет(?:ом)?\s+из|из)\s+'.$quoted.'(?=$|[\s,.;!?])/ui', $lowerText, $occurrences, PREG_OFFSET_CAPTURE)) continue;
                foreach ($occurrences[0] as [$match, $offset]) {
                    // Bind negation to this exact departure occurrence, rather
                    // than rejecting the positive half of "не из X, а из Y".
                    $prefix = substr($lowerText, 0, $offset);
                    if (preg_match('/(?:^|[^\p{L}])не\s*$/ui', $prefix)) continue;
                    $matches[$depId] = ['city' => $canonical, 'city_id' => $depId, 'matched' => $form];
                }
            }
        }
        return count($matches) === 1 ? reset($matches) : false;
    }

    /**
     * Field-scoped matching is intentionally stricter than fuzzy search:
     * - exact canonical/genitive name ignoring case/punctuation;
     * - an abbreviated token may prefix the corresponding canonical token
     *   only when the whole city remains an unambiguous active-departure match.
     * This covers live input like "Мин. Воды" without guessing unrelated cities.
     */
    public static function bestFieldMatch(string $text, array $rows)
    {
        $inputTokens = self::tokens($text);
        if (!$inputTokens) return false;

        $matches = [];
        foreach ($rows as $row) {
            $canonical = trim((string)($row['name'] ?? ''));
            $fromName = trim((string)($row['name_genitive'] ?? ''));
            $depId = (int)($row['id'] ?? 0);
            if ($canonical === '' || !$depId) continue;

            foreach (array_values(array_unique(array_filter([$canonical, $fromName]))) as $form) {
                $formTokens = self::tokens($form);
                if (count($formTokens) !== count($inputTokens)) continue;

                $ok = true;
                foreach ($inputTokens as $i => $inputToken) {
                    $formToken = $formTokens[$i];
                    if ($inputToken['value'] === $formToken['value']) continue;
                    if (!$inputToken['abbreviated'] || self::textLength($inputToken['value']) < 3 || strpos($formToken['value'], $inputToken['value']) !== 0) {
                        $ok = false;
                        break;
                    }
                }
                if (!$ok) continue;

                $matches[$depId] = ['city' => $canonical, 'city_id' => $depId, 'matched' => $form];
            }
        }

        return count($matches) === 1 ? reset($matches) : false;
    }

    /**
     * A city answer may also contain explicit trip facts, but never an inferred
     * destination or an arbitrary request. Keep the scalar matcher unchanged
     * and require the entire remaining text to use supported fact syntax.
     * The caller must already be asking for the departure city.
     */
    public static function bestCompoundFieldMatch(string $text, array $rows)
    {
        $text = trim($text);
        if ($text === ''
            || preg_match('/[?？]|(?:^|[^\p{L}])(?:не|или|либо|ли|если|например|возможно|может|наверное|предположим|допустим)(?!\p{L})/ui', $text)
            || preg_match('/(?:^|\s)(?:с\s+вылетом\s+из|вылет(?:ом)?\s+из|из)\s+/ui', $text)) return false;

        $maxCityTokens = 0;
        foreach ($rows as $row) {
            $maxCityTokens = max($maxCityTokens,
                count(self::tokens((string)($row['name'] ?? ''))),
                count(self::tokens((string)($row['name_genitive'] ?? ''))));
        }
        $parts = preg_split('/[\s,;:]+/u', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE);
        if (!$parts || count($parts) < 2) return false;

        $matches = [];
        $lastCityToken = min($maxCityTokens, count($parts) - 1);
        for ($i = 1; $i <= $lastCityToken; $i++) {
            $offset = $parts[$i][1];
            // The existing rich-text party parsers require a whitespace word
            // boundary. Unspaced punctuation stays on the ordinary route.
            if (!preg_match('/\s$/u', substr($text, 0, $offset))) continue;
            $cityText = trim(substr($text, 0, $offset));
            $remainingText = trim(substr($text, $offset));
            $best = self::bestFieldMatch($cityText, $rows);
            if (!$best || !self::hasSupportedTripFacts($remainingText)) continue;

            $best['remaining_text'] = $remainingText;
            $matches[$best['city_id']] = $best;
        }
        return count($matches) === 1 ? reset($matches) : false;
    }

    /** Validate syntax only; canonical value owners still validate/apply facts. */
    private static function hasSupportedTripFacts(string $text): bool
    {
        $day = '(?:0?[1-9]|[12][0-9]|3[01])';
        $month = '(?:января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря)';
        $date = '(?:'.$day.'\s+'.$month.'(?:\s+20[0-9]{2})?'
            .'|'.$day.'\.(?:0?[1-9]|1[0-2])(?:\.20[0-9]{2})?)';
        $age = '(?:[0-9]|1[0-7])\s+(?:лет|года?)';
        $nights = '(?:[1-9]|1[0-9]|2[0-8])';
        $patterns = [
            'date' => '(?:с\s+)?'.$date,
            'nights' => '(?:(?:на\s+)?'.$nights.'(?:\s*[-–—]\s*'.$nights.')?\s*ноч(?:ь|и|ей)|на\s+неделю)',
            // AdultsParser's rich-text form requires spaces around the full
            // noun. Abbreviations and comma-bound nouns are not supported.
            'adults' => '[1-6]\s+взросл(?:ый|ых|ого)(?=\s|[.!]?\z)',
            // An optional age belongs to one explicitly named child only.
            // Multi-child ages retain their existing ordinary interpretation.
            'children' => '(?:(?:1\s*)?реб[её]нок(?:\s+(?:\(\s*'.$age.'\s*\)|'.$age.'))?'
                .'|[0-3]\s*(?:реб[её]нок|реб[её]нка|реб[её]нков|дет(?:ей|и)))',
        ];

        $remaining = trim($text);
        $seen = [];
        while ($remaining !== '') {
            $matched = false;
            foreach ($patterns as $field => $pattern) {
                if (!preg_match('/\A'.$pattern.'(?=$|[\s,;.!])/ui', $remaining, $match)) continue;
                // Repeated fields may contain conflicting choices. Leave their
                // interpretation to the ordinary route instead of choosing one.
                if (isset($seen[$field])) return false;
                // A bare duration after another numeric fact can be ambiguous
                // to the local extractor; require its explicit "на" prefix.
                if ($field === 'nights' && $seen !== [] && !preg_match('/^на\s+/ui', $match[0])) return false;
                if ($field === 'nights'
                    && preg_match('/([0-9]+)\s*[-–—]\s*([0-9]+)/u', $match[0], $range)
                    && (int)$range[1] > (int)$range[2]) return false;
                $seen[$field] = true;
                $remaining = substr($remaining, strlen($match[0]));
                $matched = true;
                break;
            }
            if (!$matched) return false;
            if (preg_match('/\A\s*[.!]?\s*\z/u', $remaining)) return true;
            if (!preg_match('/\A(?:\s*[,;]\s+|\s+)(?:и\s+)?/ui', $remaining, $separator)) return false;
            $remaining = substr($remaining, strlen($separator[0]));
            if ($remaining === '') return false;
        }
        return false;
    }

    private static function tokens(string $text): array
    {
        $text = trim($text);
        if ($text === '') return [];
        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = [];
        foreach ($parts as $part) {
            $abbreviated = (bool)preg_match('/\.$/u', $part);
            $clean = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $part);
            $clean = function_exists('mb_strtolower') ? mb_strtolower((string)$clean, 'UTF-8') : strtolower((string)$clean);
            if ($clean === '') continue;
            $tokens[] = ['value' => $clean, 'abbreviated' => $abbreviated];
        }
        return $tokens;
    }

    private static function textLength(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }
}
