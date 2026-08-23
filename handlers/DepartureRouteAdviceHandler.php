<?php

require_once(__DIR__ . '/../DepartureRouteResolver.php');
require_once(__DIR__ . '/../services/DateParser.php');

class DepartureRouteAdviceHandler
{
    public static function handle($chatId, string $text): bool
    {
        if (!is_file(__DIR__ . '/../tourvisor_routes.json')) return false;

        $current = MaxSearchApi::getAiSearchContext($chatId);
        $city = trim((string)($current['city'] ?? ''));
        if ($city === '') return false;

        $normalized = self::lower($text);
        $dateInfo = DateParser::resolveDate($text);
        $period = self::periodFromDateInfo($dateInfo);

        try {
            $resolver = new DepartureRouteResolver();
        } catch (\Throwable $e) {
            self::log($chatId, 'INIT_ERROR', ['error'=>$e->getMessage()]);
            return false;
        }

        // Явный запрос-рекомендация: "куда можно", "не знаю куда", "посоветуйте направление".
        $isDiscovery = (bool)preg_match(
            '/(?:куда\s+(?:можно|поехать|слетать)|не\s+знаю\s+куда|посовет(?:уй|уйте)\s+(?:куда|направлен)|предлож(?:и|ите)\s+(?:куда|направлен))/ui',
            $text
        );

        if ($isDiscovery) {
            $result = $resolver->getDirectDestinations($city, $period);
            $destinations = (array)($result['destinations'] ?? []);

            if ($destinations) {
                $names = array_values(array_filter(array_map(static function($row) {
                    return trim((string)($row['country'] ?? ''));
                }, array_slice($destinations, 0, 6))));

                $when = $period ? ' на выбранный период' : '';
                $message = "Из {$city}{$when} вижу прямые чартерные программы: " . implode(', ', $names) . ".\n\nНапишите страну — продолжим подбор.";
                MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusAi);
                MaxSearchApi::MaxSend($message, $chatId);
                self::log($chatId, 'DISCOVERY', ['city'=>$city,'period'=>$period,'countries'=>$names]);
                return true;
            }

            $message = "Из {$city}" . ($period ? ' на выбранный период' : '') . " прямых чартерных направлений сейчас не вижу. Могу подобрать с альтернативным городом вылета или передать запрос менеджеру.";
            MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusAi);
            MaxSearchApi::MaxSend($message, $chatId);
            self::log($chatId, 'DISCOVERY_EMPTY', ['city'=>$city,'period'=>$period]);
            return true;
        }

        // Проверку конкретного маршрута делаем только когда пользователь сам назвал
        // и страну, и месяц/дату. Справочник остаётся подсказкой, а не жёстким фильтром.
        $country = trim((string)($current['country'] ?? ''));
        if ($country === '' || $period === null) return false;

        // "Только из ..." означает осознанный отказ от fallback — не мешаем обычному поиску.
        if (preg_match('/\bтолько\s+из\b/ui', $text)) return false;

        if (!self::countryMentioned($country, $normalized)) return false;

        $route = $resolver->checkRoute($city, $country, $period);
        if (($route['direct_charter'] ?? false) && ($route['available_in_period'] ?? false)) {
            self::log($chatId, 'DIRECT_OK', ['city'=>$city,'country'=>$country,'period'=>$period]);
            return false;
        }

        $fallback = $resolver->getFallback($city, $country, $period);
        if ($fallback['found'] ?? false) {
            $fallbackCity = (string)$fallback['fallback_departure'];
            $message = "Прямого чартерного вылета {$city} → {$country} на выбранный период сейчас не вижу. Есть вариант с вылетом из {$fallbackCity}.\n\nЕсли подходит — напишите «из {$fallbackCity}». Если принципиально только {$city} — напишите «только из {$city}».";
            MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusAi);
            MaxSearchApi::MaxSend($message, $chatId);
            self::log($chatId, 'FALLBACK', ['city'=>$city,'country'=>$country,'period'=>$period,'fallback'=>$fallbackCity]);
            return true;
        }

        $message = "Прямого чартерного вылета {$city} → {$country} на выбранный период сейчас не вижу. Можем всё равно продолжить поиск только из {$city} или передать запрос менеджеру.";
        MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusAi);
        MaxSearchApi::MaxSend($message, $chatId);
        self::log($chatId, 'NO_ROUTE', ['city'=>$city,'country'=>$country,'period'=>$period]);
        return true;
    }

    private static function periodFromDateInfo(array $dateInfo): ?string
    {
        if (!empty($dateInfo['month']) && !empty($dateInfo['year'])) {
            return sprintf('%04d-%02d', (int)$dateInfo['year'], (int)$dateInfo['month']);
        }
        if (!empty($dateInfo['date']) && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', (string)$dateInfo['date'], $m)) {
            return $m[3] . '-' . $m[2];
        }
        return null;
    }

    private static function countryMentioned(string $country, string $normalizedText): bool
    {
        $name = self::lower($country);
        if ($name !== '' && mb_strpos($normalizedText, $name) !== false) return true;

        $stems = [
            'турция'=>'турц', 'египет'=>'егип', 'таиланд'=>'таиланд', 'оаэ'=>'оаэ',
            'мальдивы'=>'мальдив', 'шри-ланка'=>'шри-ланк', 'китай'=>'китай',
            'вьетнам'=>'вьетнам', 'россия'=>'росси', 'абхазия'=>'абхаз'
        ];
        $stem = $stems[$name] ?? '';
        return $stem !== '' && mb_strpos($normalizedText, $stem) !== false;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
    }

    private static function log($chatId, string $type, array $data): void
    {
        @file_put_contents(
            __DIR__ . '/ai_debug.log',
            'ROUTE_ADVICE ' . $type . ' chat=' . $chatId . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND|LOCK_EX
        );
    }
}
