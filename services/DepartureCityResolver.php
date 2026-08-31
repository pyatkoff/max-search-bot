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
}
