<?php

class DepartureCityResolver
{
    public static function resolveAndStore($chatId, $text)
    {
        $text = trim((string)$text);
        if ($text === '') return false;

        $lower = function_exists('mb_strtolower')
            ? mb_strtolower($text, 'UTF-8')
            : strtolower($text);

        // Only act when the user explicitly talks about the departure point.
        if (!preg_match('/(?:^|\s)(?:с\s+вылетом\s+из|вылет(?:ом)?\s+из|из)\s+/ui', $text)) {
            return false;
        }

        try {
            \Bitrix\Main\Loader::includeModule('highloadblock');
            $hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById(MaxSearchApi::$depHL)->fetch();
            if (!$hlblock) return false;

            $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
            $eclass = $entity->getDataClass();
            $rows = $eclass::getList([
                'select' => ['UF_NAME','UF_NAME2','UF_DEPID'],
                'filter' => ['UF_ACTIVE' => true]
            ]);

            $best = null;
            $bestLen = 0;

            while ($row = $rows->fetch()) {
                $canonical = trim((string)($row['UF_NAME'] ?? ''));
                $fromName = trim((string)($row['UF_NAME2'] ?? ''));
                $depId = (int)($row['UF_DEPID'] ?? 0);
                if ($canonical === '' || !$depId) continue;

                $forms = array_values(array_unique(array_filter([$canonical, $fromName])));
                foreach ($forms as $form) {
                    $formLower = function_exists('mb_strtolower')
                        ? mb_strtolower($form, 'UTF-8')
                        : strtolower($form);
                    if ($formLower === '') continue;

                    // The city must appear after an explicit departure marker, not just anywhere in text.
                    $quoted = preg_quote($formLower, '/');
                    if (!preg_match('/(?:^|\s)(?:с\s+вылетом\s+из|вылет(?:ом)?\s+из|из)\s+'.$quoted.'(?=$|[\s,.;!?])/ui', $lower)) {
                        continue;
                    }

                    $len = function_exists('mb_strlen') ? mb_strlen($formLower, 'UTF-8') : strlen($formLower);
                    if ($len > $bestLen) {
                        $bestLen = $len;
                        $best = ['city' => $canonical, 'city_id' => $depId, 'matched' => $form];
                    }
                }
            }

            if (!$best) return false;

            $applied = MaxSearchApi::applyAiParameters($chatId, ['city' => $best['city']]);
            if (empty($applied['city'])) return false;

            MaxSearchApi::funnelLog($chatId, 'departure_city_resolved', [
                'city_id' => $best['city_id'],
                'city' => $best['city'],
                'matched' => $best['matched'],
                'source_text' => $text
            ]);

            return $best;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
