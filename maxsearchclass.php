<?php
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/maxsearchbaseclass.php');

class MaxSearchApi extends MaxSearchBase
{
    static $TV_API_URL = 'https://platform-api2.max.ru';

    // anytour.online: отдельные HL внутри общей Bitrix-базы.
    static $HL = 32;
    static $claimHL = 33;
    static $yclidHL = 34;
    static $chanelSendHL = 35;

    static $statusStart = 64;
    static $statusCityChoose = 65;
    static $statusContryChoose = 66;
    static $statusAdults = 67;
    static $statusChild = 68;
    static $statusAge = 69;
    static $statusStars = 70;
    static $statusMeal = 71;
    static $statusNights = 72;
    static $statusDate = 73;
    static $statusCheck = 74;
    static $statusPhone = 75;

    // AI-статус вынесен за существующий диапазон online-проекта.
    static $statusAi = 76;

    static $baseDomain = 'https://anytour.online';
    static $chanelUrl = 'https://max.ru/anytour';
    static $channelMiniappBotUrl = 'https://max.ru/id9704048781_2_bot';
    static $isAnyOnline = true;

    // Источник в U-ON: «MAX бот».
    static $uonSourceId = 36;

    // В AI-сценарии строки для возраста ребёнка и даты могут ещё не существовать.
    // Создаём нужный статус перед сохранением значения, иначе базовый saveLastValue
    // обновляет только существующую строку и молча ничего не сохраняет.
    public static function saveLastValue($chatID, $status, $value)
    {
        if (
            in_array($status, [static::$statusAge, static::$statusDate], true) &&
            static::getLastValue($chatID, $status) === false
        ) {
            static::setStatus($chatID, $status);
        }

        parent::saveLastValue($chatID, $status, $value);
    }
}
