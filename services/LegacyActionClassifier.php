<?php

class LegacyActionClassifier
{
    /**
     * Classifies the user-visible result of the legacy bot. This does not change
     * routing; it is used only for shadow-v2 comparison diagnostics.
     */
    public static function classify(string $text, array $buttons = []): array
    {
        $flat = self::lower(trim($text));
        $buttonText = [];
        $urls = [];
        $callbacks = [];

        foreach ($buttons as $row) {
            foreach ((array)$row as $button) {
                if (!is_array($button)) continue;
                if (!empty($button['text'])) $buttonText[] = self::lower((string)$button['text']);
                if (!empty($button['url'])) $urls[] = self::lower((string)$button['url']);
                if (isset($button['callback_data'])) $callbacks[] = self::lower((string)$button['callback_data']);
                if (isset($button['payload'])) $callbacks[] = self::lower((string)$button['payload']);
            }
        }

        $allButtons = implode(' | ', $buttonText);
        $allUrls = implode(' | ', $urls);
        $allCallbacks = implode(' | ', $callbacks);

        if (self::hasAny($flat . ' ' . $allButtons, ['менеджер','оператор','специалист','связаться с нами','помощь менеджера'])) {
            return self::result('MANAGER', 0.92, 'manager_text_or_button');
        }

        if (self::hasAny($allUrls . ' ' . $allButtons, ['горящ','канал','max.ru/anytour','подпис'])) {
            return self::result('CHANNEL', 0.88, 'channel_link_or_button');
        }

        if (self::hasAny($allUrls . ' ' . $allButtons, ['poisk-turov','поиск тур','посмотреть тур','смотреть тур','подобрать тур','открыть подбор'])) {
            return self::result('OPEN_SEARCH', 0.94, 'search_link_or_button');
        }

        $countryHits = 0;
        foreach (['турц','егип','таил','оаэ','эмират','вьетнам','китай','мальдив','шри-ланк','абхаз','росси'] as $stem) {
            if (strpos($allButtons, $stem) !== false) $countryHits++;
        }
        if ($countryHits >= 2 || self::hasAny($flat, ['куда можно','направлени с прям','варианты направлен'])) {
            return self::result('SHOW_OPTIONS', 0.88, 'multiple_destination_options');
        }

        if (self::looksLikeQuestion($text)) {
            return self::result('ASK', 0.86, 'question_text');
        }

        if (self::hasAny($flat, ['хорошо, не буду','не буду больше','останавливаю','обращайтесь, если'])) {
            return self::result('STOP', 0.78, 'stop_response');
        }

        return self::result('ANSWER', 0.60, 'fallback_answer');
    }

    private static function looksLikeQuestion(string $text): bool
    {
        $t = self::lower(trim($text));
        if ($t === '') return false;
        if (substr(rtrim($text), -1) === '?') return true;
        return self::hasAny($t, [
            'из какого города','куда хотите','когда примерно','какая ориентировочная дата',
            'на сколько ноч','сколько будет взрослых','будут дети','сколько лет детям',
            'подскажите ориентировочную дату','какое питание','какая минимальная категория'
        ]);
    }

    private static function result(string $action, float $confidence, string $reason): array
    {
        return ['action'=>$action,'confidence'=>$confidence,'reason'=>$reason];
    }

    private static function hasAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) if (strpos($haystack, $needle) !== false) return true;
        return false;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
