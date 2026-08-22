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

    // Офлайн-цель должна уходить в Метрику один раз на один рекламный клик.
    // Повторные открытия результатов, двойные callback-и и повторный follow-up
    // раньше создавали несколько одинаковых строк Yclid+Target в очереди.
    public static function queueMetrikaGoal($chatID, $target)
    {
        $target = trim((string)$target);
        if ($target === '') return false;

        $yclid = static::getLatestYclid($chatID);
        if ($yclid === '') {
            $meta = static::getTrafficMeta($chatID);
            $yclid = trim((string)($meta['yclid'] ?? ''));
        }
        if ($yclid === '') return false;

        $dir = __DIR__ . '/metrika_dedupe';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $key = hash('sha256', $yclid . '|' . $target);
        $file = $dir . '/' . $key . '.lock';

        $fp = @fopen($file, 'c+');
        if (!$fp) return parent::queueMetrikaGoal($chatID, $target);

        $result = false;
        if (flock($fp, LOCK_EX)) {
            rewind($fp);
            $done = trim((string)stream_get_contents($fp));
            if ($done !== '') {
                $result = true;
            } else {
                $result = parent::queueMetrikaGoal($chatID, $target);
                if ($result) {
                    ftruncate($fp, 0);
                    rewind($fp);
                    fwrite($fp, date('c'));
                    fflush($fp);
                }
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        return $result;
    }
}
