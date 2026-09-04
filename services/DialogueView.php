<?php
require_once __DIR__ . '/IntegrationRegistry.php';
require_once __DIR__ . '/ButtonFactory.php';
require_once __DIR__ . '/CallbackGeneration.php';
require_once __DIR__ . '/CalendarViewModel.php';
require_once __DIR__ . '/ManagerRequestService.php';
require_once __DIR__ . '/PostTourService.php';
require_once __DIR__ . '/SearchDateSummary.php';

class DialogueView
{
    public static function start($chatId): bool
    {
        $buttons = ButtonFactory::rows(
            ButtonFactory::row(ButtonFactory::callback('✨ Подобрать с AI','ai_start')),
            ButtonFactory::row(ButtonFactory::callback('🧭 Подобрать по шагам','start_search'))
        );
        return self::sendAndStatus($chatId,
            "🌴 <b>Давайте найдём ваш отдых</b>\n\nМожно описать пожелания своими словами — или пройти короткий подбор по шагам.",
            $buttons,
            MaxSearchApi::$statusStart,
            false
        );
    }

    public static function aiStart($chatId): bool
    {
        MaxSearchApi::deletePrevMessage($chatId);
        $buttons = ButtonFactory::rows(ButtonFactory::row(ButtonFactory::callback('ПО ШАГАМ','start_search')));
        return self::sendAndStatus($chatId,
            "Опишите поездку одним сообщением или несколькими.\n\nНапример: «Хотим из Москвы в Турцию в конце сентября, 2 взрослых и ребёнок 6 лет, 9–11 ночей, отель от 4★, всё включено».\n\nЯ уточню только то, чего не хватает.",
            $buttons,
            MaxSearchApi::$statusAi,
            false
        );
    }

    public static function city($chatId): bool
    {
        MaxSearchApi::deletePrevMessage($chatId);
        $buttons = ButtonFactory::rows(
            ButtonFactory::row(ButtonFactory::callback('Москва','pick_city_1'), ButtonFactory::callback('Санкт-Петербург','pick_city_5')),
            ButtonFactory::row(ButtonFactory::callback('Казань','pick_city_10'), ButtonFactory::callback('Красноярск','pick_city_12')),
            ButtonFactory::row(ButtonFactory::callback('🔎 Другой город','pick_city_other')),
            ButtonFactory::row(ButtonFactory::callback('🚗 Без перелёта','pick_city_99'))
        );
        return self::sendAndStatus($chatId,"✈️ <b>Откуда вылетаете?</b>\n\nШаг 1 из 7 · Выберите город или введите его вручную.",$buttons,MaxSearchApi::$statusCityChoose,false);
    }

    public static function cityOther($chatId): bool
    {
        MaxSearchApi::deletePrevMessage($chatId);
        $buttons = ButtonFactory::rows(ButtonFactory::row(ButtonFactory::back('back_pick_city')));
        return self::sendAndStatus($chatId,"✈️ <b>Введите город вылета</b>\n\nНапример: Самара, Уфа или Новосибирск.",$buttons,MaxSearchApi::$statusCityChoose,false);
    }

    public static function country($chatId): bool
    {
        MaxSearchApi::deletePrevMessage($chatId);
        $buttons = ButtonFactory::rows(
            ButtonFactory::row(ButtonFactory::callback('🇹🇷 Турция','pick_country_4'), ButtonFactory::callback('🇪🇬 Египет','pick_country_1')),
            ButtonFactory::row(ButtonFactory::callback('🇹🇭 Таиланд','pick_country_2'), ButtonFactory::callback('🇦🇪 ОАЭ','pick_country_9')),
            ButtonFactory::row(ButtonFactory::callback('🏝 Мальдивы','pick_country_8'), ButtonFactory::callback('🇱🇰 Шри-Ланка','pick_country_12')),
            ButtonFactory::row(ButtonFactory::callback('🔎 Другая страна','pick_country_other')),
            ButtonFactory::row(ButtonFactory::back('back_pick_city'))
        );
        return self::sendAndStatus($chatId,"🌍 <b>Куда хотите поехать?</b>\n\nШаг 2 из 7 · Выберите популярное направление или введите своё.",$buttons,MaxSearchApi::$statusContryChoose,false);
    }

    public static function adults($chatId): bool
    {
        MaxSearchApi::deletePrevMessage($chatId);
        $buttons = ButtonFactory::rows(
            ButtonFactory::row(ButtonFactory::callback('1 взрослый','adults_1'), ButtonFactory::callback('2 взрослых','adults_2')),
            ButtonFactory::row(ButtonFactory::callback('3 взрослых','adults_3'), ButtonFactory::callback('4 взрослых','adults_4')),
            ButtonFactory::row(ButtonFactory::callback('5 взрослых','adults_5'), ButtonFactory::callback('6 взрослых','adults_6')),
            ButtonFactory::row(ButtonFactory::back('back_pick_country'))
        );
        return self::sendAndStatus($chatId,"👥 <b>Кто едет?</b>\n\nШаг 3 из 7 · Сколько будет взрослых туристов?",$buttons,MaxSearchApi::$statusAdults,false);
    }

    public static function children($chatId): bool
    {
        MaxSearchApi::deletePrevMessage($chatId);
        $buttons = ButtonFactory::rows(
            ButtonFactory::row(ButtonFactory::callback('Без детей','child_0')),
            ButtonFactory::row(ButtonFactory::callback('1 ребёнок','child_1'), ButtonFactory::callback('2 ребёнка','child_2'), ButtonFactory::callback('3 ребёнка','child_3')),
            ButtonFactory::row(ButtonFactory::back('back_adults'))
        );
        return self::sendAndStatus($chatId,"👨‍👩‍👧 <b>Будут дети?</b>\n\nЕсли да — укажите количество. Возраст спросим следующим сообщением.",$buttons,MaxSearchApi::$statusChild,false);
    }

    public static function childAges($chatId, int $children = 1): bool
    {
        MaxSearchApi::deletePrevMessage($chatId);
        $buttons = ButtonFactory::rows(ButtonFactory::row(ButtonFactory::back('back_child')));
        $text = $children === 1
            ? "🧒 <b>Сколько лет ребёнку?</b>\n\nВведите возраст числом, например: 6"
            : "🧒 <b>Сколько лет детям?</b>\n\nВведите {$children} возраста через пробел или запятую, например: 3, 7";
        return self::sendAndStatus($chatId,$text,$buttons,MaxSearchApi::$statusAge,false);
    }

    public static function stars($chatId): bool
    {
        MaxSearchApi::deletePrevMessage($chatId);
        $buttons = ButtonFactory::rows(
            ButtonFactory::row(ButtonFactory::callback('5★','star_5'), ButtonFactory::callback('от 4★','star_4')),
            ButtonFactory::row(ButtonFactory::callback('от 3★','star_3'), ButtonFactory::callback('от 2★','star_2')),
            ButtonFactory::row(ButtonFactory::callback('Не важно','star_1')),
            ButtonFactory::row(ButtonFactory::back('back_child'))
        );
        return self::sendAndStatus($chatId,"🏨 <b>Какой уровень отеля рассматриваете?</b>\n\nШаг 4 из 7 · Укажите минимальную категорию.",$buttons,MaxSearchApi::$statusStars,false);
    }

    public static function calendar($chatId, $month, $year): bool
    {
        MaxSearchApi::deletePrevMessage($chatId);
        $model = CalendarViewModel::build($month, $year);
        $buttons = CalendarViewModel::buttons($model);
        return self::sendAndStatus(
            $chatId,
            "📅 <b>Когда хотите вылететь?</b>\n\nШаг 7 из 7 · Выберите ориентировочную дату. В поиске посмотрим даты рядом с ней.",
            $buttons,
            MaxSearchApi::$statusDate,
            false
        );
    }

    public static function check($chatId): bool
    {
        MaxSearchApi::funnelLog($chatId,'search_ready');
        MaxSearchApi::deletePrevMessage($chatId);
        $savedData = MaxSearchApi::getSavedData($chatId);
        $selectedDate = $savedData[MaxSearchApi::$statusDate] ?? null;
        $summaryData = $savedData;
        $summaryData[MaxSearchApi::$statusDate] = null;
        $summary = MaxSearchApi::formatSavedData($summaryData);
        $summary = SearchDateSummary::replaceDateLine($summary, $selectedDate);
        $generation = CallbackGeneration::token();
        $buttons = ButtonFactory::rows(
            ButtonFactory::row(ButtonFactory::callback('🔥 Показать туры',CallbackGeneration::wrap('show_tours',$generation))),
            ButtonFactory::row(ButtonFactory::callback('👩‍💼 Подобрать с менеджером',CallbackGeneration::wrap('manager_request',$generation))),
            ButtonFactory::row(ButtonFactory::callback('✏️ Изменить параметры',CallbackGeneration::wrap('edit_params',$generation)))
        );
        $text = "✅ <b>Готово! Проверьте параметры</b>\n\n" . implode("\n", $summary) . "\n\nЧто удобнее дальше?";
        $ok = self::sendAndStatus($chatId,$text,$buttons,MaxSearchApi::$statusCheck,false);
        if ($ok) MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusCheck, $generation);
        return $ok;
    }

    public static function tourResults($chatId, array $model): bool
    {
        MaxSearchApi::deletePrevMessage($chatId);
        return (bool)IntegrationRegistry::messenger()->sendWithButtons(
            $chatId,
            (string)($model['text'] ?? ''),
            (array)($model['buttons'] ?? [])
        );
    }

    public static function managerRequest($chatId, string $name = '', bool $fromTours = false, bool $outsideHours = false): bool
    {
        return self::sendManagerContactRequest(
            $chatId,
            $name,
            $fromTours,
            $outsideHours ? 'outside_hours_text' : 'text',
            true
        );
    }

    public static function managerPhoneFallback($chatId, bool $fromTours = false): bool
    {
        return self::sendManagerContactRequest($chatId, '', $fromTours, 'fallback_text', false);
    }

    private static function sendManagerContactRequest(
        $chatId,
        string $name,
        bool $fromTours,
        string $textKey,
        bool $deletePrevious
    ): bool {
        $model = ManagerRequestService::prepare($chatId, $name, $fromTours);
        if ($deletePrevious) MaxSearchApi::deletePrevMessage($chatId);
        $ok = IntegrationRegistry::messenger()->sendContactRequest(
            $chatId,
            (string)$model[$textKey],
            (string)$model['manual_callback'],
            (string)$model['back_callback']
        );
        if ($ok) MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusPhone);
        return (bool)$ok;
    }

    public static function toursFollowup($chatId): bool
    {
        $model = PostTourService::followupModel();
        return (bool)IntegrationRegistry::messenger()->sendWithButtons($chatId, $model['text'], $model['buttons']);
    }

    public static function afterToursQuestion($chatId): bool
    {
        MaxSearchApi::deletePrevMessage($chatId);
        $model = PostTourService::afterToursModel();
        return (bool)IntegrationRegistry::messenger()->sendWithButtons($chatId, $model['text'], $model['buttons']);
    }

    public static function channelOffer($chatId, bool $afterLead = false): bool
    {
        $model = PostTourService::channelOfferModel($chatId, $afterLead);
        return (bool)IntegrationRegistry::messenger()->sendWithButtons($chatId, $model['text'], $model['buttons']);
    }

    public static function manualCountry($chatId): bool
    {
        MaxSearchApi::deletePrevMessage($chatId);
        $buttons = ButtonFactory::rows(ButtonFactory::row(ButtonFactory::back('back_pick_country')));
        return self::sendAndStatus($chatId,"🌍 <b>Введите страну</b>\n\nНапишите название направления, которое хотите рассмотреть.",$buttons,MaxSearchApi::$statusContryChoose,false);
    }

    public static function manualNights($chatId): bool
    {
        MaxSearchApi::deletePrevMessage($chatId);
        $buttons = ButtonFactory::rows(ButtonFactory::row(ButtonFactory::back('back_nights')));
        return self::sendAndStatus($chatId,"🌙 <b>Введите количество ночей</b>\n\nНапример: 7 или диапазон 7-10.",$buttons,MaxSearchApi::$statusNights,false);
    }

    public static function manualPhone($chatId): bool
    {
        MaxSearchApi::deletePrevMessage($chatId);
        $buttons = ButtonFactory::rows(ButtonFactory::row(ButtonFactory::back('tours_checked')));
        return self::sendAndStatus($chatId,"📱 <b>Введите номер телефона</b>\n\nНапример: +71234567890",$buttons,MaxSearchApi::$statusPhone,false);
    }

    private static function sendAndStatus($chatId, string $text, array $buttons, $status, bool $deletePrevious = false): bool
    {
        if ($deletePrevious) MaxSearchApi::deletePrevMessage($chatId);
        $ok = IntegrationRegistry::messenger()->sendWithButtons($chatId,$text,$buttons);
        if ($ok) MaxSearchApi::setStatus($chatId,$status);
        return (bool)$ok;
    }
}
