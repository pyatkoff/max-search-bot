<?php

require_once(__DIR__ . '/../DepartureRouteResolver.php');
require_once(__DIR__ . '/../DepartureRouteAdvisor.php');
require_once(__DIR__ . '/../services/DateParser.php');
require_once(__DIR__ . '/../services/DestinationPreferenceResolver.php');
require_once(__DIR__ . '/../services/DiagnosticLogger.php');
require_once(__DIR__ . '/../services/AiRuntimeLogger.php');
require_once(__DIR__ . '/../services/IntegrationRegistry.php');

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
        $preferenceIntent = DestinationPreferenceResolver::detectIntent($text);

        try {
            $resolver = new DepartureRouteResolver();
            $advisor = new DepartureRouteAdvisor($resolver);
        } catch (\Throwable $e) {
            self::log($chatId, 'INIT_ERROR', ['error'=>$e->getMessage()], 'error');
            return false;
        }

        $isDiscovery = self::isDiscoveryIntent($text);

        if ($isDiscovery || $preferenceIntent !== null) {
            $result = $advisor->getDestinations($city, $period);
            $allDestinations = (array)($result['destinations'] ?? []);
            $destinations = DestinationPreferenceResolver::filterAndRank($allDestinations, $preferenceIntent, $period);
            $preferenceMatched = !empty($destinations);
            if (!$preferenceMatched && $preferenceIntent !== null) $destinations = $allDestinations;

            $destinations = array_slice($destinations, 0, 4);
            $names = array_values(array_filter(array_map(
                static fn($row) => trim((string)($row['country'] ?? '')),
                $destinations
            )));
            $when = $period ? ' на выбранный период' : '';
            $preferenceLabel = self::preferenceLabel($preferenceIntent);

            if ($names && !empty($result['fallback_used'])) {
                $fallbackCity = (string)($result['fallback_departure'] ?? '');
                if ($preferenceIntent !== null && !$preferenceMatched) {
                    $message = "Из {$city}{$when} прямых чартерных вариантов под запрос «{$preferenceLabel}» сейчас не вижу. Если рассмотреть вылет из {$fallbackCity}, из реально доступных программ можно посмотреть: " . implode(', ', $names) . ".\n\nНапишите страну или «только из {$city}».";
                } else {
                    $message = "Из {$city}{$when} прямых чартерных программ сейчас не вижу. Если рассмотреть вылет из {$fallbackCity}, под ваш запрос подходят: " . implode(', ', $names) . ".\n\nНапишите страну или «только из {$city}».";
                }
                self::send($chatId, $message);
                self::log($chatId, 'DISCOVERY_FALLBACK', [
                    'city'=>$city,'period'=>$period,'preference'=>$preferenceIntent,
                    'matched'=>$preferenceMatched,'fallback'=>$fallbackCity,'countries'=>$names
                ]);
                return true;
            }

            if ($names) {
                if ($preferenceIntent !== null && !$preferenceMatched) {
                    $message = "Из {$city}{$when} прямых чартерных вариантов под запрос «{$preferenceLabel}» сейчас не вижу. Из реально доступных направлений можно рассмотреть: " . implode(', ', $names) . ".\n\nНапишите страну — продолжим подбор.";
                } elseif ($preferenceIntent !== null) {
                    $message = "Из {$city}{$when} под запрос «{$preferenceLabel}» я бы сначала посмотрел: " . implode(', ', $names) . ". Все эти направления сейчас есть в прямой чартерной программе.\n\nНапишите страну — продолжим подбор.";
                } else {
                    $message = "Из {$city}{$when} я бы сначала посмотрел: " . implode(', ', $names) . ". Это прямые чартерные программы, которые сейчас есть в расписании.\n\nНапишите страну — продолжим подбор.";
                }
                self::send($chatId, $message);
                self::log($chatId, 'DISCOVERY', [
                    'city'=>$city,'period'=>$period,'preference'=>$preferenceIntent,
                    'matched'=>$preferenceMatched,'countries'=>$names
                ]);
                return true;
            }

            $message = "Из {$city}{$when} прямых чартерных направлений сейчас не вижу. Могу продолжить поиск только из {$city} или передать запрос менеджеру.";
            self::send($chatId, $message);
            self::log($chatId, 'DISCOVERY_EMPTY', ['city'=>$city,'period'=>$period,'preference'=>$preferenceIntent]);
            return true;
        }

        $country = trim((string)($current['country'] ?? ''));
        if ($country === '' || $period === null) return false;
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
            $message = "Прямого чартерного вылета {$city} → {$country} на выбранный период сейчас не вижу. Из {$fallbackCity} такая программа есть.\n\nНапишите «из {$fallbackCity}», чтобы продолжить, или «только из {$city}», если город вылета менять нельзя.";
            self::send($chatId, $message);
            self::log($chatId, 'FALLBACK', ['city'=>$city,'country'=>$country,'period'=>$period,'fallback'=>$fallbackCity]);
            return true;
        }

        $message = "Прямого чартерного вылета {$city} → {$country} на выбранный период сейчас не вижу. Можем всё равно попробовать поиск только из {$city} или передать запрос менеджеру.";
        self::send($chatId, $message);
        self::log($chatId, 'NO_ROUTE', ['city'=>$city,'country'=>$country,'period'=>$period]);
        return true;
    }

    public static function isDiscoveryIntent(string $text): bool
    {
        $normalized = self::lower($text);
        $normalized = str_replace('ё', 'е', $normalized);
        // Common real-user typos seen in production. Keep this deliberately narrow:
        // normalize only the discovery words rather than applying fuzzy matching to
        // arbitrary destination text.
        $normalized = preg_replace('/\bкда\b/u', 'куда', $normalized);
        $normalized = preg_replace('/\b(?:небуть|нибуть|небудь)\b/u', 'нибудь', $normalized);

        return (bool)preg_match(
            '/(?:куда(?:\s*-\s*|\s+)нибудь|куда\s+(?:можно|поехать|слетать)|не\s+знаю\s+куда|посовет(?:уй|уйте)\s+(?:куда|направлен)|предлож(?:и|ите)\s+(?:куда|направлен))/ui',
            (string)$normalized
        );
    }

    private static function preferenceLabel(?string $intent): string
    {
        if ($intent === 'warm') return 'куда потеплее';
        if ($intent === 'sea') return 'море / пляжный отдых';
        return 'подходящее направление';
    }

    private static function send($chatId, string $message): void
    {
        MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusAi);
        IntegrationRegistry::messenger()->send($chatId, $message);
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
            'турция'=>'турц','египет'=>'егип','таиланд'=>'таиланд','оаэ'=>'оаэ',
            'мальдивы'=>'мальдив','шри-ланка'=>'шри-ланк','китай'=>'китай',
            'вьетнам'=>'вьетнам','россия'=>'росси','абхазия'=>'абхаз'
        ];
        $stem = $stems[$name] ?? '';
        return $stem !== '' && mb_strpos($normalizedText, $stem) !== false;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
    }

    private static function log($chatId, string $type, array $data, string $level = 'info'): void
    {
        DiagnosticLogger::log('route_advice', strtolower($type), $data, $chatId, $level);
        AiRuntimeLogger::debug(
            'ROUTE_ADVICE ' . $type . ' chat=' . $chatId . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL
        );
    }
}
