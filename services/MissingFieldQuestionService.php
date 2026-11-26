<?php
require_once __DIR__ . '/IntegrationRegistry.php';

/**
 * Single source of truth for deterministic follow-up questions.
 * Keeps wording and transport out of AI handlers.
 */
class MissingFieldQuestionService
{
    public static function question(string $field, array $options = []): string
    {
        $countryQuestion = !empty($options['country_explicit'])
            ? 'В какую страну хотите поехать?'
            : 'Куда хотите поехать?';

        $questions = [
            'city' => !empty($options['departure_alternatives'])
                ? 'Вы указали два города вылета. Какой из них взять для первого подбора?'
                : (!empty($options['unresolved_city'])
                ? 'Не получилось принять этот город для онлайн-подбора. Проверьте название. Если оно верное, напишите «Позвать менеджера» — он поможет проверить вылет из нужного вам города. Можно также указать другой удобный город вылета.'
                : 'Из какого города планируете вылет?'),
            'country' => !empty($options['country_help'])
                ? 'Если пока не выбрали страну, можем обсудить варианты. Можно рассказать, какой отдых вам хочется, или написать «Позвать менеджера» — он поможет с выбором направления.'
                : $countryQuestion,
            'adults' => !empty($options['unsupported_format'])
                ? 'Не получилось прочитать количество взрослых в таком формате. Подтвердите число взрослых цифрой, без сокращений.'
                : 'Сколько будет взрослых туристов?',
            'children' => 'Будут дети? Если да — сколько?',
            'child_ages' => 'Сколько лет детям?',
            'stars' => 'Какая минимальная категория отеля нужна — 3, 4 или 5 звёзд?',
            'meal' => 'Какое питание предпочитаете?',
            'nights' => !empty($options['unsupported_format'])
                ? 'Не получилось прочитать диапазон ночей: в нём есть буква. Подтвердите количество ночей цифрами, например «7» или «7-9».'
                : 'На сколько ночей планируете поездку?',
            'date' => !empty($options['ambiguous_months'])
                ? 'Поняла, подходят разные месяцы. Для первого поиска на сайте напишите, с какого месяца начать, или «Позвать менеджера», чтобы сравнить варианты.'
                : (!empty($options['ambiguous_range'])
                ? 'Поняла, подходят даты в указанном диапазоне. Не буду выбирать день за вас. Для первого просмотра на сайте напишите удобное число из диапазона; для сравнения дат — «Позвать менеджера».'
                : (!empty($options['flexible_month'])
                ? 'Поняла, подойдёт любой день указанного месяца. Для первого просмотра на сайте нужна ориентировочная дата, а не окончательное ограничение поездки. Напишите «начало», «середина» или «конец» месяца. Для сравнения цен за весь месяц — «Позвать менеджера».'
                : (!empty($options['month_only'])
                ? 'Подскажите ориентировочную дату вылета в этом месяце — например, в начале, середине или конце.'
                : 'Какая ориентировочная дата вылета?'))),
        ];

        return $questions[$field] ?? 'Уточните, пожалуйста, параметры поездки.';
    }

    public static function send($chatId, string $field, array $options = []): bool
    {
        if (class_exists('SourceHandlingService', false)
            && SourceHandlingService::askHotelIntentIfPending($chatId)) return true;
        if (class_exists('MaxSearchApi')) {
            MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusAi);
        }
        if ($field === 'city' && empty($options['suppress_departure_suggestion']) && empty($options['unresolved_city']) && empty($options['departure_alternatives'])) {
            require_once __DIR__ . '/DepartureSuggestionService.php';
            require_once __DIR__ . '/DialogueView.php';
            $suggestion = DepartureSuggestionService::forChat($chatId);
            if ($suggestion !== []) return DialogueView::departureSuggestion($chatId, $suggestion);
        }
        return IntegrationRegistry::messenger()->send($chatId, self::question($field, $options));
    }

    public static function sendForMissing($chatId, array $missing, array $options = []): bool
    {
        $field = (string)($missing[0] ?? '');
        return self::send($chatId, $field, $options);
    }

    /** Explain a known count without changing needs, status or manager ownership. */
    public static function sendUnsupportedChildren($chatId, int $count): bool
    {
        return self::sendText($chatId,
            "Вы указали {$count} детей. Онлайн-подбор поддерживает до 3 детей, поэтому для такого состава нужен подбор с менеджером.\n\nЧтобы подключить менеджера, напишите «Позвать менеджера»."
        );
    }

    public static function sendText($chatId, string $text): bool
    {
        return IntegrationRegistry::messenger()->send($chatId, $text);
    }
}
