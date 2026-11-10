<?php
require_once __DIR__ . '/AdultsParser.php';
require_once __DIR__ . '/NeedValueResolver.php';

class LocalAiFallbackService
{
    public static function classify(string $userText): array
    {
        $routeLen = function_exists('mb_strlen')
            ? mb_strlen($userText, 'UTF-8')
            : strlen($userText);

        $rich =
            $routeLen > 55 ||
            substr_count($userText, ',') >= 2 ||
            preg_match('/\b\d+\s*(?:взросл|реб[её]н|дет)/ui', $userText) ||
            preg_match('/\b\d+\s*(?:-|–|—)\s*\d+\s*ноч/ui', $userText) ||
            preg_match('/(?:отел|зв[её]зд|пляж|понтон|бухт|курорт|район|шарм|хургада)/ui', $userText);

        return [
            'length' => $routeLen,
            'rich' => (bool)$rich,
            'simple' => $routeLen <= 55
                && !preg_match('/(?:где\s+дешевле|что\s+лучше|сравни|посоветуй|почему)/ui', $userText),
        ];
    }

    public static function parameters(string $userText, array $current): array
    {
        $params = [];
        $localText = self::lower($userText);

        if (empty($current['city'])) {
            $params['city'] = 'Москва';
        }

        // Country recognition must not depend on mbstring being loaded in the
        // production PHP binary. PCRE /ui gives us Unicode-aware case-insensitive
        // matching for explicit short answers such as "Египет".
        $countries = [
            'турц'=>'Турция',
            'егип'=>'Египет',
            'таиланд'=>'Таиланд',
            'тайланд'=>'Таиланд',
            'оаэ'=>'ОАЭ',
            'эмират'=>'ОАЭ',
            'мальдив'=>'Мальдивы',
            'шри-ланк'=>'Шри-Ланка',
            'китай'=>'Китай',
            'хайнан'=>'Китай',
        ];
        // Country matching uses the positive replacement clause. Party below
        // still reads the complete original tourist message.
        $countryText = $userText;
        $explicitReplacement = false;
        if (preg_match('/^\s*не\s+.+?\s*,?\s+а\s+(.+?)\s*$/ui', $countryText, $correction)) {
            $countryText = trim($correction[1]);
            $explicitReplacement = true;
        }
        $countryMatches = [];
        if (!preg_match('/[?？]|(?:^|[^\p{L}])(?:не|нет|или|ли|если|например|возможно|сравни[а-яё]*|почему|узнать|расскажите|были|раньше)(?!\p{L})/ui', $countryText)) {
            foreach ($countries as $stem => $name) {
                if (preg_match('/'.preg_quote($stem, '/').'/ui', $countryText)) $countryMatches[$name] = $name;
            }
        }
        if (count($countryMatches) === 1) {
            $name = reset($countryMatches);
            $bareChoice = false;
            foreach ($countries as $stem => $canonicalName) {
                if ($canonicalName === $name
                    && preg_match('/^\s*(?:(?:в|во|на)\s+)?'.preg_quote($stem, '/').'[а-яё-]*[.!]?\s*$/ui', $countryText)) $bareChoice = true;
            }
            $choiceIntent = (bool)preg_match('/(?:^|\s)(?:хочу|хотим|нужен|нужна|нужно|едем|летим|поедем|полетим|давайте|меняем|замените|выбираем|направление|страна)(?=\s|:|,)/ui', $countryText);
            if (empty($current['country']) || $current['country'] === $name || $explicitReplacement || $bareChoice || $choiceIntent) {
                $params['country'] = $name;
            }
        }

        // Rich free-text requests must not depend solely on the external AI call
        // for explicit party composition. Preserve the same canonical bounds as
        // the deterministic adults/children pipeline.
        $explicitAdults = AdultsParser::parse($userText);
        if ($explicitAdults !== null) {
            $params['adults'] = $explicitAdults;
        }

        if (
            strpos($localText, 'на двоих') !== false ||
            strpos($localText, 'вдвоем') !== false ||
            strpos($localText, 'вдвоём') !== false
        ) {
            $params['adults'] = 2;
            $params['children'] = 0;
        }

        if (
            strpos($localText, 'без детей') !== false ||
            strpos($localText, 'детей нет') !== false
        ) {
            $params['children'] = 0;
        } elseif (preg_match('/(?:^|[\s,+])([0-3])\s*(?:реб[её]нок|реб[её]нка|реб[её]нков|дет(?:ей|и))\b/ui', $userText, $m)) {
            $params['children'] = (int)$m[1];
        } elseif (preg_match('/\b(?:реб[её]нок|реб[её]нка)\b/ui', $userText)) {
            $params['children'] = 1;
        }

        $children = array_key_exists('children', $params)
            ? (int)$params['children']
            : (int)($current['children'] ?? 0);
        $childAges = self::explicitChildAges($userText, $children);
        if ($childAges !== null) $params['child_ages'] = $childAges;

        // A confirmed country-only replacement does not negate an independent
        // duration. Reuse the existing country decision; never strip a numeric
        // or duration correction from the text passed to the nights guard.
        $nightsText = $userText;
        if ($explicitReplacement && isset($params['country'])) {
            foreach ($countries as $stem => $name) {
                if (preg_match('/^\s*не\s+(?:(?:в|во|на)\s+)?'.preg_quote($stem, '/').'[а-яё-]*\s*,?\s+а\s+/ui', $userText)) {
                    $nightsText = $countryText;
                    break;
                }
            }
        }
        $nights = self::explicitNights($nightsText);
        if ($nights !== null) $params['nights'] = $nights;

        return $params;
    }

    public static function applyDestinationDefaults(array $params, array $current): array
    {
        if (empty($params)) return $params;

        $country = (string)($params['country'] ?? ($current['country'] ?? ''));
        if (preg_match('/^(?:турция|египет)$/ui', trim($country))) {
            if (empty($current['meal'])) $params['meal'] = 'all_inclusive';
            if (empty($current['stars'])) $params['stars'] = 4;
        }

        return $params;
    }

    public static function unresolvedDestination(array $missingBefore, array $missingAfter): bool
    {
        return in_array('country', $missingBefore, true)
            && in_array('country', $missingAfter, true);
    }

    private static function explicitNights(string $text): ?string
    {
        // Negative child composition is independent of the requested duration.
        // Other negations, questions and alternatives remain for the ordinary
        // interpretation path instead of becoming a partly accepted trip.
        $guardText = preg_replace('/(?<!\p{L})детей\s+нет(?!\p{L})/ui', ' ', $text);
        if ($guardText === null || preg_match('/[?？]|(?<!\p{L})(?:не|нет|или|либо|ли|если|бы|возможно|может|наверное|например|допустим|предположим|можно|сколько|почему|зачем|когда|как|какой|какая|какие|какую|узнать|спросить|расскажите|подскажите|вместо|лучше|больше|меньше|дольше|короче|более|менее|минимум|максимум|почти|около|примерно|приблизительно|плюс(?:ом)?|минус(?:ом)?|раньше|назад|были|ездили|летали)(?!\p{L})/ui', $guardText)) return null;

        // Only a labelled duration is extracted from a compound request. A
        // bare number/date/age/budget is not a nights answer on this surface.
        // Contextual comma/space ranges and day abbreviations stay with the
        // canonical scalar parser when its actual nights question owns input.
        $number = '[0-9]{1,3}(?:\s*[-–—]\s*[0-9]{1,3})?';
        $pattern = '/(?<![\p{L}0-9.,\/+\-–—])(?:(?:на\s+)?'.$number.'\s*ноч(?:ь|и|ей)(?![\p{L}0-9])'
            .'|на\s+недел(?:ю|ьку)(?![\p{L}0-9])|\A\s*недел(?:я|ю|ьку)[.!]?\s*\z)/ui';
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) !== 1) return null;

        [$clause, $offset] = $matches[0][0];
        $prefix = substr($text, 0, $offset);
        $suffix = substr($text, $offset + strlen($clause));
        $remaining = $prefix.' '.$suffix;
        $otherUnit = '(?:день|дня|дней|недел(?:я|ю|и|ь|ьку)|месяц(?:а|ев)?)(?!\p{L})';
        if (preg_match('/(?<!\p{L})(?:от|до|свыше|по|после|через|спустя|за|без|порядка)\s*$/ui', $prefix)
            || preg_match('/(?<!\p{L})на\s+[0-9]{1,3}\s*[,;]\s*$/ui', $prefix)
            || (!preg_match('/^на\s+/ui', $clause) && preg_match('/(?:[0-9]\s*[,\.\/]|[+\-–—]|[0-9]\s+(?:и\s+)?)\s*$/ui', $prefix))
            || preg_match('/^\s*(?:\+|[-–—]\s*[0-9]|позже(?!\p{L}))/ui', $suffix)
            || preg_match('/^\s*[,;]?\s*(?:(?:а|но|и)\s+)?[0-9]{1,3}\s*[.!]?\s*$/ui', $suffix)
            || preg_match('/(?<!\p{L})ноч(?:ь|и|ей)(?!\p{L})/ui', $remaining)
            || preg_match('/(?<!\p{L})на\s+(?:[0-9]{1,3}\s*)?'.$otherUnit.'/ui', $remaining)
            || preg_match('/^\s*[,;]?\s*(?:(?:а|но|и)\s+)?(?:[0-9]{1,3}\s*)?'.$otherUnit.'/ui', $suffix)
            || preg_match('/^\s*(?:[0-9]{1,3}\s*)?'.$otherUnit.'\s*[,;]?\s*(?:и\s*)?$/ui', $prefix)) return null;

        // Bounds and canonical storage (including a week and dash ranges)
        // belong to the same resolver used by the existing wizard owner.
        $resolved = NeedValueResolver::resolve('nights', $clause);
        return !empty($resolved['recognized']) && is_string($resolved['value'] ?? null)
            ? $resolved['value']
            : null;
    }

    private static function explicitChildAges(string $text, int $children): ?array
    {
        if ($children < 1 || $children > 3) return null;

        // Isolate an age clause tied to children before using the canonical
        // scalar parser. Dates, adult/child counts and nights elsewhere in a
        // rich request must never become child ages.
        $year = '(?:лет|года?)';
        $month = '(?:месяц(?:а|ев)?|мес\.?)(?!\p{L})';
        $separator = '(?:\s*[,;]\s*|\s+и\s+|\s+)';
        $yearAge = '[0-9]{1,2}\s*'.$year.'(?!\p{L})(?:\s*(?:и\s+)?[0-9]{1,3}\s*'.$month.')?';
        $explicitAge = '(?:'.$yearAge.'|[0-9]{1,3}\s*'.$month.')';
        $ages = '(?:(?:'.$explicitAge.'|[0-9]{1,2})'.$separator.')*'.$explicitAge;
        $plain = '(?:реб[её]н(?:ок|ка|ку|ков)|дет(?:и|ей|ям))(?!\p{L})'
            .'\s*(?:[,:(]\s*)?(?:(?:в\s+возрасте|возраст(?:ом)?)\s*[:—-]?\s*)?'.$ages;
        $shared = '(?:(?:обоим|обеим|детям)\s+по|каждому(?:\s+реб[её]нку)?)'
            .'\s+[0-9]{1,2}\s*'.$year;
        $pattern = '/(?<!\p{L})(?:'.$shared.'|'.$plain.')(?![\p{L}0-9])'
            .'(?!\s*(?:или|либо)(?!\p{L}))'
            .'(?!\s*(?:[,;]|и(?!\p{L}))\s*[0-9]{1,2}\s*(?:'.$year.'|[,;]|$))/ui';
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) !== 1) return null;

        [$clause, $offset] = $matches[0][0];
        $prefix = substr($text, 0, $offset);
        $suffix = substr($text, $offset + strlen($clause));
        $remaining = $prefix.' '.$suffix;
        if (preg_match('/(?:^|[^\p{L}])(?:не|нет|почти|около|примерно|обоим|обеим|каждому)(?:\s+[0-3])?\s*$/ui', $prefix)
            || preg_match('/(?<![0-9])[0-9]{1,3}\s*(?:'.$year.'|'.$month.')(?!\p{L})/ui', $remaining)
            || preg_match('/(?<!\p{L})не\s*[0-9]{1,2}(?![0-9])(?:\s*'.$year.')?\s*[,;]?\s*а\s*(?:по\s+)?[0-9]{1,2}(?![0-9])/ui', $suffix)) return null;

        // Preserve "по/обоим/каждому" for the shared-age policy. A trailing
        // question or comment is not part of that complete scalar answer.
        $resolved = NeedValueResolver::resolve('child_ages', $clause, ['children'=>$children]);
        return !empty($resolved['recognized']) && is_array($resolved['value'] ?? null)
            ? $resolved['value']
            : null;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
