<?php
require_once __DIR__ . '/IntegrationRegistry.php';
require_once __DIR__ . '/ButtonFactory.php';

class WizardStepView
{
    public static function meal($chatId): bool
    {
        $buttons = ButtonFactory::rows(
            ButtonFactory::row(ButtonFactory::callback('🍽 Всё включено','meal_7')),
            ButtonFactory::row(ButtonFactory::callback('🥐 Только завтраки','meal_3'), ButtonFactory::callback('🍴 Завтрак + ужин','meal_4')),
            ButtonFactory::row(ButtonFactory::callback('🍽 Полный пансион','meal_5')),
            ButtonFactory::row(ButtonFactory::callback('Не важно','meal_999')),
            ButtonFactory::row(ButtonFactory::back('back_stars'))
        );
        return self::sendAndStatus(
            $chatId,
            "🍽 <b>Какое питание предпочитаете?</b>\n\nШаг 5 из 7",
            $buttons,
            MaxSearchApi::$statusMeal
        );
    }

    public static function nights($chatId): bool
    {
        $buttons = ButtonFactory::rows(
            ButtonFactory::row(ButtonFactory::callback('6–8 ночей','nights_6_8'), ButtonFactory::callback('9–11 ночей','nights_9_11')),
            ButtonFactory::row(ButtonFactory::callback('12–14 ночей','nights_12_14')),
            ButtonFactory::row(ButtonFactory::callback('✏️ Свой вариант','nights_other')),
            ButtonFactory::row(ButtonFactory::back('back_meal'))
        );
        return self::sendAndStatus(
            $chatId,
            "🌙 <b>На сколько ночей хотите поехать?</b>\n\nШаг 6 из 7 · Можно выбрать диапазон или указать свой.",
            $buttons,
            MaxSearchApi::$statusNights
        );
    }

    private static function sendAndStatus($chatId, string $text, array $buttons, $status): bool
    {
        $ok = IntegrationRegistry::messenger()->sendWithButtons($chatId, $text, $buttons);
        if ($ok) MaxSearchApi::setStatus($chatId, $status);
        return (bool)$ok;
    }
}
