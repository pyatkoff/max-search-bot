<?php
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/maxsearchbaseclass.php');
require_once(__DIR__ . '/services/TrafficAttributionService.php');
require_once(__DIR__ . '/services/AnalyticsService.php');
require_once(__DIR__ . '/services/MaxTransport.php');
require_once(__DIR__ . '/services/FollowupQueueService.php');
require_once(__DIR__ . '/services/AiSearchContextService.php');

class MaxSearchApi extends MaxSearchBase
{
    static $TV_API_URL = 'https://platform-api2.max.ru';

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
    static $statusAi = 76;

    static $baseDomain = 'https://anytour.online';
    static $chanelUrl = 'https://max.ru/anytour';
    static $channelMiniappBotUrl = 'https://max.ru/id9704048781_2_bot';
    static $isAnyOnline = true;
    static $uonSourceId = 36;

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

    private static function aiStatusMap()
    {
        return [
            'city'=>static::$statusCityChoose,
            'country'=>static::$statusContryChoose,
            'adults'=>static::$statusAdults,
            'children'=>static::$statusChild,
            'child_ages'=>static::$statusAge,
            'stars'=>static::$statusStars,
            'meal'=>static::$statusMeal,
            'nights'=>static::$statusNights,
            'date'=>static::$statusDate,
        ];
    }

    public static function getAiSearchContext($chatID)
    {
        $saved = static::getSavedData($chatID);
        return AiSearchContextService::contextFromSaved(
            (array)$saved,
            static::aiStatusMap(),
            function ($id) { return static::getCityByID($id); },
            function ($id) { return static::getCountryByID($id); }
        );
    }

    public static function getAiMissingFields($chatID)
    {
        return AiSearchContextService::missingFromSaved(
            (array)static::getSavedData($chatID),
            static::aiStatusMap()
        );
    }

    public static function applyAiParameters($chatID, array $params)
    {
        $normalized = AiSearchContextService::normalizeParameters(
            $params,
            function ($name) {
                $row = static::getCityByName($name);
                return $row ? ($row['ID'] ?? null) : null;
            },
            function ($name) {
                $row = static::getCountryByName($name);
                return $row ? ($row['ID'] ?? null) : null;
            },
            function ($date) {
                try {
                    $obj = new \Bitrix\Main\Type\Date($date, 'd.m.Y');
                    return $obj->getTimestamp() >= strtotime('today');
                } catch (\Throwable $e) {
                    return false;
                }
            }
        );

        $storageMap = AiSearchContextService::storageMap(static::aiStatusMap());
        $applied = [];
        foreach ($normalized as $field => $value) {
            if (!isset($storageMap[$field])) continue;
            static::upsertStatusValue($chatID, $storageMap[$field], $value);
            $applied[$field] = true;
        }
        return $applied;
    }

    public static function trafficFile($chatID)
    {
        return TrafficAttributionService::file(__DIR__, $chatID);
    }

    public static function saveTrafficMeta($chatID, $yclid = '', $region = '', $campaign = '', $raw = '')
    {
        return TrafficAttributionService::save(__DIR__, $chatID, $yclid, $region, $campaign, $raw);
    }

    public static function getTrafficMeta($chatID)
    {
        return TrafficAttributionService::get(__DIR__, $chatID);
    }

    public static function buildChannelMiniappUrl($chatID)
    {
        return TrafficAttributionService::buildMiniappUrl(
            static::$channelMiniappBotUrl,
            static::getTrafficMeta($chatID),
            static::getLatestYclid($chatID)
        );
    }

    public static function funnelLog($chatID, $event, $details = [])
    {
        $meta = [];
        try { $meta = static::getTrafficMeta($chatID); } catch (\Throwable $e) {}
        if (!is_array($meta)) $meta = [];
        return AnalyticsService::funnel(__DIR__, $chatID, $event, (array)$details, $meta);
    }

    private static function metrikaExcludedDestination($chatID)
    {
        $countryId = 0;
        try {
            $saved = static::getSavedData($chatID);
            if (!empty($saved[static::$statusContryChoose])) $countryId = (int)$saved[static::$statusContryChoose];
        } catch (\Throwable $e) {}
        if ($countryId <= 0) {
            try {
                $claim = static::getLastClaimForChat($chatID);
                if ($claim && !empty($claim['UF_COUNTRY'])) $countryId = (int)$claim['UF_COUNTRY'];
            } catch (\Throwable $e) {}
        }
        if ($countryId <= 0) return false;
        $country = '';
        try { $country = (string)static::getCountryByID($countryId); } catch (\Throwable $e) {}
        $countryNorm = function_exists('mb_strtolower') ? mb_strtolower(trim($country), 'UTF-8') : strtolower(trim($country));
        $countryNorm = str_replace('ё', 'е', $countryNorm);
        if (in_array($countryNorm, ['россия', 'абхазия'], true)) return ['country_id'=>$countryId, 'country'=>$country];
        return false;
    }

    public static function queueMetrikaGoal($chatID, $target)
    {
        $target = trim((string)$target);
        if ($target === '') return false;
        $excluded = static::metrikaExcludedDestination($chatID);
        if ($excluded) {
            static::funnelLog($chatID, 'metrika_skipped_destination', [
                'target'=>$target,
                'country_id'=>$excluded['country_id'],
                'country'=>$excluded['country']
            ]);
            return false;
        }
        $meta = static::getTrafficMeta($chatID);
        $yclid = static::getLatestYclid($chatID);
        if ($yclid === '') $yclid = trim((string)($meta['yclid'] ?? ''));
        if ($yclid === '') return false;

        $dir = __DIR__ . '/metrika_dedupe';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $key = hash('sha256', $yclid . '|' . $target);
        $file = $dir . '/' . $key . '.lock';
        $fp = @fopen($file, 'c+');
        if (!$fp) return AnalyticsService::queueMetrika(__DIR__, $chatID, $yclid, $target, $meta);

        $result = false;
        if (flock($fp, LOCK_EX)) {
            rewind($fp);
            $done = trim((string)stream_get_contents($fp));
            if ($done !== '') {
                $result = true;
            } else {
                $result = AnalyticsService::queueMetrika(__DIR__, $chatID, $yclid, $target, $meta);
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

    private static function maxTransportLogFile()
    {
        return __DIR__ . '/tmp_max_search.txt';
    }

    public static function MaxRequest($method, $parameters = [])
    {
        if ($method === 'deleteMessage') {
            $messageId = $parameters['message_id'] ?? '';
            return MaxTransport::deleteMessage(static::$TV_API_URL, MAX_SEARCH_TOKEN, $messageId, static::maxTransportLogFile());
        }
        return false;
    }

    public static function MaxRequestJson($method, $parameters = [])
    {
        return static::MaxRequest($method, $parameters);
    }

    public static function MaxSend($text, $chat_id)
    {
        $mid = MaxTransport::send(static::$TV_API_URL, MAX_SEARCH_TOKEN, $chat_id, $text, static::maxTransportLogFile());
        if ($mid) $_SESSION['last_message_id'] = $mid;
        return $mid;
    }

    public static function MaxSendWithButtons($text, $chat_id, $buttons, $unused = false)
    {
        $mid = MaxTransport::sendWithButtons(static::$TV_API_URL, MAX_SEARCH_TOKEN, $chat_id, $text, $buttons, static::maxTransportLogFile());
        if ($mid) $_SESSION['last_message_id'] = $mid;
        return $mid;
    }

    public static function MaxSendWithMenuButtons($text, $chat_id)
    {
        return static::MaxSend($text, $chat_id);
    }

    public static function answerCallback($callbackId)
    {
        return !empty($callbackId);
    }

    public static function maxLog($data)
    {
        MaxTransport::log(static::maxTransportLogFile(), $data);
    }

    public static function followupDir()
    {
        return FollowupQueueService::dir(__DIR__);
    }

    public static function scheduleToursFollowup($chatID, $delaySeconds = 180)
    {
        return FollowupQueueService::schedule(__DIR__, $chatID, (int)$delaySeconds);
    }

    public static function cancelToursFollowup($chatID)
    {
        return FollowupQueueService::cancel(__DIR__, $chatID);
    }
}
