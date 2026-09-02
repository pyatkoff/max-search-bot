<?php

require_once __DIR__ . '/TravelDirectoryRepository.php';

class DepartureCityResolver
{
    public static function resolveAndStore($chatId, $text)
    {
        $text = trim((string)$text);
        if ($text === '') return false;

        $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        if (!preg_match('/(?:^|\s)(?:с\s+вылетом\s+из|вылет(?:ом)?\s+из|из)\s+/ui', $text)) return false;

        try {
            $best = self::bestMatch($lower, TravelDirectoryRepository::activeDepartures());
            if (!$best) return false;

            $applied = MaxSearchApi::applyAiParameters($chatId, ['city' => $best['city']]);
            if (empty($applied['city'])) return false;

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

    public static function bestMatch(string $lowerText, array $rows)
    {
        $best = null;
        $bestLen = 0;
        foreach ($rows as $row) {
            $canonical = trim((string)($row['name'] ?? ''));
            $fromName = trim((string)($row['name_genitive'] ?? ''));
            $depId = (int)($row['id'] ?? 0);
            if ($canonical === '' || !$depId) continue;

            foreach (array_values(array_unique(array_filter([$canonical, $fromName]))) as $form) {
                $formLower = function_exists('mb_strtolower') ? mb_strtolower($form, 'UTF-8') : strtolower($form);
                if ($formLower === '') continue;
                $quoted = preg_quote($formLower, '/');
                if (!preg_match('/(?:^|\s)(?:с\s+вылетом\s+из|вылет(?:ом)?\s+из|из)\s+'.$quoted.'(?=$|[\s,.;!?])/ui', $lowerText)) continue;
                $len = function_exists('mb_strlen') ? mb_strlen($formLower, 'UTF-8') : strlen($formLower);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = ['city' => $canonical, 'city_id' => $depId, 'matched' => $form];
                }
            }
        }
        return $best ?: false;
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
