<?php
require_once __DIR__ . '/IntegrationRegistry.php';
require_once __DIR__ . '/ButtonFactory.php';

class EditParamsView
{
    public static function menu($chatId): bool
    {
        $buttons = ButtonFactory::rows(
            ButtonFactory::row(ButtonFactory::callback('✈️ Город вылета','edit_city'), ButtonFactory::callback('🌍 Страна','edit_country')),
            ButtonFactory::row(ButtonFactory::callback('👥 Туристы','edit_tourists'), ButtonFactory::callback('🏨 Отель','edit_stars')),
            ButtonFactory::row(ButtonFactory::callback('🍽 Питание','edit_meal'), ButtonFactory::callback('🌙 Ночи','edit_nights')),
            ButtonFactory::row(ButtonFactory::callback('📅 Дата','edit_date')),
            ButtonFactory::row(ButtonFactory::callback('← К параметрам','back_check'))
        );

        $ok = IntegrationRegistry::messenger()->sendWithButtons(
            $chatId,
            "✏️ <b>Что хотите изменить?</b>\n\nНе нужно начинать подбор заново — выберите только нужный параметр.",
            $buttons
        );
        if ($ok) MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusCheck);
        return (bool)$ok;
    }
}
