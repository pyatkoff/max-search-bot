<?php
require_once(__DIR__ . '/../ai/AiRouter.php');
require_once(__DIR__ . '/AiDateHandler.php');

class AiMessageHandler
{
    public static function handle($message, $chat_id)
    {
                $userText = trim((string)$message['text']);
                MaxSearchApi::funnelLog($chat_id,'ai_text',['text'=>function_exists('mb_substr')?mb_substr($userText,0,300,'UTF-8'):substr($userText,0,300)]);
                if($userText === '') {
                    MaxSearchApi::MaxSend("Напишите, какой тур вы ищете — можно обычными словами.", $chat_id);
                    return;
                }

                $current = MaxSearchApi::getAiSearchContext($chat_id);
                $missingNow = MaxSearchApi::getAiMissingFields($chat_id);

                // Короткий ответ на ранее названный месяц: "начало", "середина", "конец", "14".
                if (in_array('date', $missingNow, true)) {
                    $shortDateValue = AiDateHandler::resolvePendingShortDate($chat_id, $userText);

                    if ($shortDateValue !== '') {
                        MaxSearchApi::saveLastValue(
                            $chat_id,
                            MaxSearchApi::$statusDate,
                            $shortDateValue
                        );

                        $missingAfterDate = MaxSearchApi::getAiMissingFields($chat_id);

                        if (empty($missingAfterDate)) {
                            MaxSearchApi::showCheckButtons($chat_id);
                        } else {
                            $dateFallback = [
                                'city'=>'Из какого города планируете вылет?',
                                'country'=>'Куда хотите поехать?',
                                'adults'=>'Сколько будет взрослых туристов?',
                                'children'=>'Будут дети? Если да — сколько?',
                                'child_ages'=>'Сколько лет детям?',
                                'stars'=>'Какая минимальная категория отеля нужна — 3, 4 или 5 звёзд?',
                                'meal'=>'Какое питание предпочитаете?',
                                'nights'=>'На сколько ночей планируете поездку?',
                                'date'=>'Какая ориентировочная дата вылета?'
                            ];

                            MaxSearchApi::setStatus($chat_id, MaxSearchApi::$statusAi);
                            MaxSearchApi::MaxSend(
                                $dateFallback[$missingAfterDate[0]] ?? 'Уточните, пожалуйста, параметры поездки.',
                                $chat_id
                            );
                        }

                        return;
                    }
                }

                // Если сейчас не хватает только возраста детей, короткий ответ
                // разбираем детерминированно без AI: "5", "5 лет", "5, 8", "5 и 8".
                if (in_array('child_ages', $missingNow, true)) {
                    $childrenCount = (int)($current['children'] ?? 0);

                    $ageText = function_exists('mb_strtolower')
                        ? mb_strtolower($userText, 'UTF-8')
                        : strtolower($userText);

                    preg_match_all('/\b(\d{1,2})\b/u', $ageText, $ageMatches);
                    $ages = array_map('intval', $ageMatches[1] ?? []);

                    $ages = array_values(array_filter(
                        $ages,
                        static function($age) {
                            return $age >= 0 && $age <= 17;
                        }
                    ));

                    if ($childrenCount > 0 && count($ages) === $childrenCount) {
                        $ageValue = implode(', ', $ages);

                        MaxSearchApi::saveLastValue(
                            $chat_id,
                            MaxSearchApi::$statusAge,
                            $ageValue
                        );

                        $missingAfterAge = MaxSearchApi::getAiMissingFields($chat_id);

                        if (empty($missingAfterAge)) {
                            MaxSearchApi::showCheckButtons($chat_id);
                        } else {
                            $ageFallback = [
                                'city'=>'Из какого города планируете вылет?',
                                'country'=>'Куда хотите поехать?',
                                'adults'=>'Сколько будет взрослых туристов?',
                                'children'=>'Будут дети? Если да — сколько?',
                                'child_ages'=>'Сколько лет детям?',
                                'stars'=>'Какая минимальная категория отеля нужна — 3, 4 или 5 звёзд?',
                                'meal'=>'Какое питание предпочитаете?',
                                'nights'=>'На сколько ночей планируете поездку?',
                                'date'=>'Какая ориентировочная дата вылета?'
                            ];

                            MaxSearchApi::setStatus($chat_id, MaxSearchApi::$statusAi);
                            MaxSearchApi::MaxSend(
                                $ageFallback[$missingAfterAge[0]] ?? 'Уточните, пожалуйста, параметры поездки.',
                                $chat_id
                            );
                        }

                        return;
                    }
                }

                // FIX6: СНАЧАЛА классифицируем сообщение.
                // Большой полноценный запрос вообще не трогаем локальным парсером —
                // сразу отдаём его AiRouter целиком.
                $routeText = function_exists('mb_strtolower')
                    ? mb_strtolower($userText, 'UTF-8')
                    : strtolower($userText);
                $routeLen = function_exists('mb_strlen')
                    ? mb_strlen($userText, 'UTF-8')
                    : strlen($userText);

                $richTourRequest =
                    $routeLen > 55 ||
                    substr_count($userText, ',') >= 2 ||
                    preg_match('/\b\d+\s*(?:взросл|реб[её]н|дет)/ui',$userText) ||
                    preg_match('/\b\d+\s*(?:-|–|—)\s*\d+\s*ноч/ui',$userText) ||
                    preg_match('/(?:отел|зв[её]зд|пляж|понтон|бухт|курорт|район|шарм|хургада)/ui',$userText);

                $ai = null;

                if ($richTourRequest) {
                    // Полный запрос -> сразу AI, без local apply и без раннего return.
                    @file_put_contents(
                        __DIR__.'/ai_debug.log',
                        "\n".date('d.m.Y H:i:s')."--- chat=".$chat_id." ---\n".
                        "ROUTE: RICH_AI\n".
                        "AI INPUT: ".$userText."\n".
                        "AI CONTEXT BEFORE: ".json_encode($current,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",
                        FILE_APPEND|LOCK_EX
                    );

                    try {
                        $ai = AiRouter::parseTourRequest($userText, $current);
                        @file_put_contents(
                            __DIR__.'/ai_debug.log',
                            "AI RAW: ".json_encode($ai,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",
                            FILE_APPEND|LOCK_EX
                        );
                    } catch (\Throwable $e) {
                        @file_put_contents(
                            __DIR__.'/ai_errors.log',
                            date('d.m.Y H:i:s').'--- chat='.$chat_id.' --- '.$e->getMessage().PHP_EOL,
                            FILE_APPEND|LOCK_EX
                        );
                        $ai=['_error'=>true];
                    }
                } else {
                    // Только короткие сообщения и простые коррекции идут в local fallback.
                    $localParams = [];
                    $localText = $routeText;

                    if (empty($current['city'])) {
                        $localParams['city'] = 'Москва';
                    }

                    $localCountries = [
                        'турц'=>'Турция',
                        'егип'=>'Египет',
                        'таиланд'=>'Таиланд',
                        'тайланд'=>'Таиланд',
                        'оаэ'=>'ОАЭ',
                        'эмират'=>'ОАЭ',
                        'мальдив'=>'Мальдивы',
                        'шри-ланк'=>'Шри-Ланка',
                        'китай'=>'Китай',
                        'хайнан'=>'Китай'
                    ];
                    foreach ($localCountries as $stem=>$name) {
                        if (strpos($localText,$stem)!==false) {
                            $localParams['country']=$name;
                            break;
                        }
                    }

                    if (
                        strpos($localText,'на двоих')!==false ||
                        strpos($localText,'вдвоем')!==false ||
                        strpos($localText,'вдвоём')!==false
                    ) {
                        $localParams['adults']=2;
                        $localParams['children']=0;
                    }

                    if (
                        strpos($localText,'без детей')!==false ||
                        strpos($localText,'детей нет')!==false
                    ) {
                        $localParams['children']=0;
                    }

                    if (preg_match('/(?:на\s+)?недел(?:ю|ьку)/ui',$userText)) {
                        $localParams['nights']='7';
                    }

                    // Дата для коротких сообщений вынесена в отдельный обработчик.
                    $localDateResolved = AiDateHandler::rememberMonthFromText($chat_id, $userText);
                    $localMonthOnly = !empty($localDateResolved['month']) && empty($localDateResolved['date']);
                    if (!empty($localDateResolved['date'])) {
                        $localParams['date'] = $localDateResolved['date'];
                    }

                    if (!empty($localParams)) {
                        $tmpCountry=$localParams['country'] ?? ($current['country'] ?? '');
                        $tmpCountryKey=function_exists('mb_strtolower')
                            ? mb_strtolower((string)$tmpCountry,'UTF-8')
                            : strtolower((string)$tmpCountry);
                        if (in_array($tmpCountryKey,['турция','египет'],true)) {
                            if (empty($current['meal'])) $localParams['meal']='all_inclusive';
                            if (empty($current['stars'])) $localParams['stars']=4;
                        }

                        $hadCurrentBeforeLocal = !empty($current);
                        $appliedLocal = MaxSearchApi::applyAiParameters($chat_id,$localParams);
                        $current=MaxSearchApi::getAiSearchContext($chat_id);
                    } else {
                        $hadCurrentBeforeLocal = !empty($current);
                        $appliedLocal = [];
                    }

                    $missingLocal=MaxSearchApi::getAiMissingFields($chat_id);
                    $simpleLocal =
                        $routeLen <= 55 &&
                        !preg_match('/(?:где\s+дешевле|что\s+лучше|сравни|посоветуй|почему)/ui',$userText);

                    if ($simpleLocal && empty($missingLocal) && $hadCurrentBeforeLocal && !empty($appliedLocal)) {
                        MaxSearchApi::showCheckButtons($chat_id);
                        return;
                    }

                    if ($simpleLocal && !empty($missingLocal)) {
                        $localFallback=[
                            'city'=>'Из какого города планируете вылет?',
                            'country'=>'Куда хотите поехать?',
                            'adults'=>'Сколько будет взрослых туристов?',
                            'children'=>'Будут дети? Если да — сколько?',
                            'child_ages'=>'Сколько лет детям?',
                            'stars'=>'Какая минимальная категория отеля нужна — 3, 4 или 5 звёзд?',
                            'meal'=>'Какое питание предпочитаете?',
                            'nights'=>'На сколько ночей планируете поездку?',
                            'date'=>$localMonthOnly
                                ? 'Подскажите ориентировочную дату вылета в этом месяце — например, в начале, середине или конце.'
                                : 'Какая ориентировочная дата вылета?'
                        ];
                        MaxSearchApi::setStatus($chat_id,MaxSearchApi::$statusAi);
                        MaxSearchApi::MaxSend(
                            $localFallback[$missingLocal[0]] ?? 'Уточните, пожалуйста, параметры поездки.',
                            $chat_id
                        );
                        return;
                    }

                    // Сложный, но короткий текст — всё равно отдаём AI.
                    @file_put_contents(
                        __DIR__.'/ai_debug.log',
                        "\n".date('d.m.Y H:i:s')."--- chat=".$chat_id." ---\n".
                        "ROUTE: SHORT_AI\n".
                        "AI INPUT: ".$userText."\n".
                        "AI CONTEXT BEFORE: ".json_encode($current,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",
                        FILE_APPEND|LOCK_EX
                    );

                    try {
                        $ai = AiRouter::parseTourRequest($userText, $current);
                        @file_put_contents(
                            __DIR__.'/ai_debug.log',
                            "AI RAW: ".json_encode($ai,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",
                            FILE_APPEND|LOCK_EX
                        );
                    } catch (\Throwable $e) {
                        @file_put_contents(
                            __DIR__.'/ai_errors.log',
                            date('d.m.Y H:i:s').'--- chat='.$chat_id.' --- '.$e->getMessage().PHP_EOL,
                            FILE_APPEND|LOCK_EX
                        );
                        $ai=['_error'=>true];
                    }
                }

                // FIX7: старый второй routing-проход удалён.
                // $ai уже получен выше либо через RICH_AI, либо через SHORT_AI.
                // Ни local fallback, ни второй AiRouter здесь повторно не запускаются.

                // STEP 3: бизнес-дефолты. Стартовую ветку не трогаем.
                if (is_array($ai) && empty($ai['_error'])) {
                    if (!isset($ai['parameters']) || !is_array($ai['parameters'])) {
                        $ai['parameters'] = [];
                    }

                    $p =& $ai['parameters'];

                    // Если город вылета не указан — Москва.
                    if (empty($p['city']) && empty($current['city'])) {
                        $p['city'] = 'Москва';
                    }

                    // "вдвоём / вдвоем / на двоих" = 2 взрослых, без детей.
                    $lt = function_exists('mb_strtolower')
                        ? mb_strtolower((string)$userText, 'UTF-8')
                        : strtolower((string)$userText);

                    if (
                        strpos($lt, 'вдвоём') !== false ||
                        strpos($lt, 'вдвоем') !== false ||
                        strpos($lt, 'на двоих') !== false
                    ) {
                        // Если человек говорит "на двоих", без упоминания детей,
                        // считаем это двумя взрослыми без детей.
                        if (empty($p['adults']) && empty($current['adults'])) {
                            $p['adults'] = 2;
                        }
                        if (
                            (!isset($p['children']) || $p['children'] === null || $p['children'] === '') &&
                            !array_key_exists('children', $current)
                        ) {
                            $p['children'] = 0;
                        }
                    }

                    $country = trim((string)($p['country'] ?? ($current['country'] ?? '')));
                    $countryKey = function_exists('mb_strtolower')
                        ? mb_strtolower($country, 'UTF-8')
                        : strtolower($country);

                    // Для Турции и Египта — разумные дефолты, если пользователь не указал другое.
                    if (in_array($countryKey, ['турция','египет'], true)) {
                        if (empty($p['meal']) && empty($current['meal'])) {
                            $p['meal'] = 'all_inclusive';
                        }
                        if (empty($p['stars']) && empty($current['stars'])) {
                            $p['stars'] = 4;
                        }
                    }
                }

                if (!is_array($ai) || !empty($ai['_error'])) {
                    put_log_out('AI ERROR: '.json_encode($ai, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

                    $missingAfterError=MaxSearchApi::getAiMissingFields($chat_id);
                    $fallbackAfterError=[
                        'city'=>'Из какого города планируете вылет?',
                        'country'=>'Куда хотите поехать?',
                        'adults'=>'Сколько будет взрослых туристов?',
                        'children'=>'Будут дети? Если да — сколько?',
                        'child_ages'=>'Сколько лет детям?',
                        'stars'=>'Какая минимальная категория отеля нужна — 3, 4 или 5 звёзд?',
                        'meal'=>'Какое питание предпочитаете?',
                        'nights'=>'На сколько ночей планируете поездку?',
                        'date'=>'Какая ориентировочная дата вылета?'
                    ];

                    MaxSearchApi::setStatus($chat_id,MaxSearchApi::$statusAi);
                    MaxSearchApi::MaxSend(
                        $fallbackAfterError[$missingAfterError[0]] ?? 'Уточните, пожалуйста, параметры поездки.',
                        $chat_id
                    );
                    return;
                }

                $params = is_array($ai['parameters'] ?? null) ? $ai['parameters'] : [];

                // FULL FIX1: дата может быть частью первого длинного запроса.
                // Если AI её не выделил — достаём понятные формулировки прямо из userText.
                if (empty($params['date'])) {
                    $dateTextAll = function_exists('mb_strtolower')
                        ? mb_strtolower($userText, 'UTF-8')
                        : strtolower($userText);

                    $resolvedInlineDate = '';
                    $monthsInline = [
                        'январ'=>1, 'феврал'=>2, 'март'=>3, 'апрел'=>4,
                        'май'=>5, 'мая'=>5, 'мае'=>5, 'июн'=>6, 'июл'=>7, 'август'=>8,
                        'сентябр'=>9, 'октябр'=>10, 'ноябр'=>11, 'декабр'=>12
                    ];
                    $monthInline = 0;
                    $monthInlineStem = '';
                    foreach ($monthsInline as $stem=>$num) {
                        if (strpos($dateTextAll, $stem) !== false) {
                            $monthInline = $num;
                            $monthInlineStem = $stem;
                            break;
                        }
                    }

                    if (preg_match('/\bзавтра\b/ui', $dateTextAll)) {
                        $resolvedInlineDate = date('d.m.Y', strtotime('+1 day'));
                    } elseif (preg_match('/\bпослезавтра\b/ui', $dateTextAll)) {
                        $resolvedInlineDate = date('d.m.Y', strtotime('+2 days'));
                    } elseif (preg_match('/\b(?:ближайш(?:ая|ие|ую)|как\s+можно\s+скорее|поскорее)\b/ui', $dateTextAll)) {
                        $resolvedInlineDate = date('d.m.Y', strtotime('+1 day'));
                    } elseif ($monthInline > 0) {
                        $yearInline=(int)date('Y');
                        if($monthInline < (int)date('n')) $yearInline++;

                        $dayInline=0;
                        if (preg_match('/(?:в\s+)?начал(?:е|о)\s+[а-яё]+/ui', $dateTextAll)) {
                            $dayInline=5;
                        } elseif (preg_match('/(?:в\s+)?середин(?:е|у)\s+[а-яё]+/ui', $dateTextAll)) {
                            $dayInline=15;
                        } elseif (preg_match('/(?:в\s+)?конц(?:е|а)\s+[а-яё]+/ui', $dateTextAll)) {
                            $dayInline=25;
                        } elseif (
                            $monthInlineStem !== '' &&
                            preg_match('/после\s+(\d{1,2})\s+[а-яё]*'.preg_quote($monthInlineStem,'/').'[а-яё]*/ui', $dateTextAll, $dm)
                        ) {
                            $dayInline=min(28,((int)$dm[1])+1);
                        } elseif (
                            $monthInlineStem !== '' &&
                            preg_match('/\b(\d{1,2})\s+[а-яё]*'.preg_quote($monthInlineStem,'/').'[а-яё]*/ui', $dateTextAll, $dm)
                        ) {
                            $dayInline=(int)$dm[1];
                        }

                        if ($dayInline > 0 && checkdate($monthInline,$dayInline,$yearInline)) {
                            $resolvedInlineDate=sprintf('%02d.%02d.%04d',$dayInline,$monthInline,$yearInline);
                        }
                    }

                    if ($resolvedInlineDate !== '') {
                        $params['date']=$resolvedInlineDate;
                        AiDateHandler::clear($chat_id);
                    } elseif ($monthInline > 0) {
                        $pendingInlineYear = (int)date('Y');
                        if ($monthInline < (int)date('n')) $pendingInlineYear++;
                        maxSetPendingMonth($chat_id, $monthInline, $pendingInlineYear);
                    }
                }

                // DATE GUARD:
                // Если пользователь явно назвал месяц, не позволяем AI/fallback
                // сохранить дату из другого месяца.
                $dateGuardText = function_exists('mb_strtolower')
                    ? mb_strtolower($userText, 'UTF-8')
                    : strtolower($userText);

                $dateGuardMonths = [
                    'январ'=>1, 'феврал'=>2, 'март'=>3, 'апрел'=>4,
                    'май'=>5, 'мая'=>5, 'мае'=>5, 'июн'=>6, 'июл'=>7,
                    'август'=>8, 'сентябр'=>9, 'октябр'=>10,
                    'ноябр'=>11, 'декабр'=>12
                ];

                $dateGuardMonth = 0;
                $dateGuardStem = '';
                foreach ($dateGuardMonths as $stem=>$num) {
                    if (strpos($dateGuardText, $stem) !== false) {
                        $dateGuardMonth = $num;
                        $dateGuardStem = $stem;
                        break;
                    }
                }

                if ($dateGuardMonth > 0) {
                    $dateGuardYear = (int)date('Y');
                    if ($dateGuardMonth < (int)date('n')) {
                        $dateGuardYear++;
                    }

                    // Есть ли явно названное число именно рядом с названием месяца.
                    $dateGuardExplicitDay = 0;
                    if (
                        $dateGuardStem !== '' &&
                        preg_match(
                            '/\b(\d{1,2})\s+[а-яё]*'.preg_quote($dateGuardStem,'/').'[а-яё]*/ui',
                            $dateGuardText,
                            $dateGuardMatch
                        )
                    ) {
                        $dateGuardExplicitDay = (int)$dateGuardMatch[1];
                    }

                    $dateGuardExpectedDay = 0;

                    if ($dateGuardExplicitDay > 0) {
                        $dateGuardExpectedDay = $dateGuardExplicitDay;
                    } elseif (preg_match('/(?:в\s+)?начал(?:е|о)\s+[а-яё]+/ui', $dateGuardText)) {
                        $dateGuardExpectedDay = 5;
                    } elseif (preg_match('/(?:в\s+)?середин(?:е|у)\s+[а-яё]+/ui', $dateGuardText)) {
                        $dateGuardExpectedDay = 15;
                    } elseif (preg_match('/(?:в\s+)?конц(?:е|а)\s+[а-яё]+/ui', $dateGuardText)) {
                        $dateGuardExpectedDay = 25;
                    }

                    if ($dateGuardExpectedDay > 0 && checkdate($dateGuardMonth, $dateGuardExpectedDay, $dateGuardYear)) {
                        // Для точной даты / начала / середины / конца жёстко сохраняем
                        // дату именно в указанном пользователем месяце.
                        $params['date'] = sprintf(
                            '%02d.%02d.%04d',
                            $dateGuardExpectedDay,
                            $dateGuardMonth,
                            $dateGuardYear
                        );
                    } else {
                        // Назван только месяц. Никаких случайных дат из другого месяца.
                        // Оставляем date незаполненной, чтобы бот уточнил период.
                        $params['date'] = null;
                    }
                }

                if (!empty($params['date'])) {
                    AiDateHandler::clear($chat_id);
                }

                @file_put_contents(
                    __DIR__.'/ai_debug.log',
                    "ROUTE AFTER AI: APPLY_PARAMETERS\n",
                    FILE_APPEND|LOCK_EX
                );
                $appliedResult = MaxSearchApi::applyAiParameters($chat_id, $params);
                $missing = MaxSearchApi::getAiMissingFields($chat_id);

                @file_put_contents(
                    __DIR__.'/ai_debug.log',
                    "AI PARAMS: ".json_encode($params,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n".
                    "AI APPLIED: ".json_encode($appliedResult,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n".
                    "AI CONTEXT AFTER: ".json_encode(MaxSearchApi::getAiSearchContext($chat_id),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n".
                    "AI MISSING: ".json_encode($missing,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",
                    FILE_APPEND|LOCK_EX
                );

                if (empty($missing)) {
                    MaxSearchApi::showCheckButtons($chat_id);
                    return;
                }

                // Возвращаем AI-статус последним, чтобы следующее текстовое сообщение снова разбирал AI.
                MaxSearchApi::setStatus($chat_id, MaxSearchApi::$statusAi);

                // ВАЖНО: после применения бизнес-дефолтов AI-вопрос может быть уже устаревшим.
                // Поэтому следующий вопрос строим только по ФАКТИЧЕСКИ первому missing-полю.
                $fallback = [
                    'city'=>'Из какого города планируете вылет?',
                    'country'=>'В какую страну хотите поехать?',
                    'adults'=>'Сколько будет взрослых туристов?',
                    'children'=>'Будут дети? Если да — сколько?',
                    'child_ages'=>'Сколько лет детям?',
                    'stars'=>'Какая минимальная категория отеля нужна — 3, 4 или 5 звёзд?',
                    'meal'=>'Какое питание предпочитаете?',
                    'nights'=>'На сколько ночей планируете поездку?',
                    'date'=>'Какая ориентировочная дата вылета?'
                ];

                $question = $fallback[$missing[0]] ?? 'Уточните, пожалуйста, параметры поездки.';
                MaxSearchApi::MaxSend($question, $chat_id);

    }
}
