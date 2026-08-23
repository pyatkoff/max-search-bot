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
            'city' => 'Из какого города планируете вылет?',
            'country' => $countryQuestion,
            'adults' => 'Сколько будет взрослых туристов?',
            'children' => 'Будут дети? Если да — сколько?',
            'child_ages' => 'Сколько лет детям?',
            'stars' => 'Какая минимальная категория отеля нужна — 3, 4 или 5 звёзд?',
            'meal' => 'Какое питание предпочитаете?',
            'nights' => 'На сколько ночей планируете поездку?',
            'date' => !empty($options['month_only'])
                ? 'Подскажите ориентировочную дату вылета в этом месяце — например, в начале, середине или конце.'
                : 'Какая ориентировочная дата вылета?',
        ];

        return $questions[$field] ?? 'Уточните, пожалуйста, параметры поездки.';
    }

    public static function send($chatId, string $field, array $options = []): bool
    {
        if (class_exists('MaxSearchApi')) {
            MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusAi);
        }
        return IntegrationRegistry::messenger()->send($chatId, self::question($field, $options));
    }

    public static function sendForMissing($chatId, array $missing, array $options = []): bool
    {
        $field = (string)($missing[0] ?? '');
        return self::send($chatId, $field, $options);
    }

    public static function sendText($chatId, string $text): bool
    {
        return IntegrationRegistry::messenger()->send($chatId, $text);
    }
}
