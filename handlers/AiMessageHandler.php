<?php
require_once(__DIR__ . '/../ai/AiRouter.php');
require_once(__DIR__ . '/AiDateHandler.php');
require_once(__DIR__ . '/../services/MissingFieldQuestionService.php');
require_once(__DIR__ . '/../services/DialogueView.php');
require_once(__DIR__ . '/../services/NeedApplicationService.php');
require_once(__DIR__ . '/../services/NeedProgressionService.php');

class AiMessageHandler
{
    public static function handle($message, $chat_id)
    {
                $userText = trim((string)$message['text']);
                MaxSearchApi::funnelLog($chat_id,'ai_text',['text'=>function_exists('mb_substr')?mb_substr($userText,0,300,'UTF-8'):substr($userText,0,300)]);
                if($userText === '') {
                    MissingFieldQuestionService::sendText($chat_id, "Напишите, какой тур вы ищете — можно обычными словами.");
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
                            DialogueView::check($chat_id);
                        } else {
                            MissingFieldQuestionService::sendForMissing($chat_id, $missingAfterDate);
                        }

                        return;
                    }
                }

                // Если сейчас не хватает возраста детей, короткий ответ проходит через
                // общий deterministic resolver/application/progression pipeline.
                if (in_array('child_ages', $missingNow, true)) {
                    $ageResult = NeedApplicationService::resolveAndApply(
                        $chat_id,
                        'child_ages',
                        $userText,
                        ['children'=>(int)($current['children'] ?? 0)]
                    );

                    if (!empty($ageResult['recognized']) && !empty($ageResult['applied'])) {
                        NeedProgressionService::advance($chat_id);
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
                        DialogueView::check($chat_id);
                        return;
                    }

                    // Live conversations showed short resort/region answers such as "Алания"
                    // being rejected locally and followed by the same country question again.
                    // If country was missing before and is still missing after the deterministic
                    // parser, let AI interpret the destination once instead of repeating the prompt.
                    $unresolvedDestination = in_array('country',$missingNow,true)
                        && in_array('country',$missingLocal,true);
                    if ($simpleLocal && !empty($missingLocal) && !$unresolvedDestination) {
                        MissingFieldQuestionService::sendForMissing(
                            $chat_id,
                            $missingLocal,
                            ['month_only'=>$localMonthOnly]
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
                    MissingFieldQuestionService::sendForMissing($chat_id, $missingAfterError);
                    return;
                }

                $params = is_array($ai['parameters'] ?? null) ? $ai['parameters'] : [];

                // Даты из текста пользователя разбираются единым DateParser через AiDateHandler.
                // Это одновременно служит DATE GUARD: если пользователь явно назвал месяц,
                // не принимаем случайную AI-дату из другого месяца.
                $resolvedUserDate = AiDateHandler::rememberMonthFromText($chat_id, $userText);

                if (!empty($resolvedUserDate['date'])) {
                    $params['date'] = $resolvedUserDate['date'];
                } elseif (!empty($resolvedUserDate['month'])) {
                    // Назван месяц, но точный день/период не определён — спрашиваем уточнение.
                    $params['date'] = null;
                } elseif (!empty($params['date'])) {
                    // AI-дата допустима, если пользователь не назвал противоречащий ей месяц.
                    AiDateHandler::clear($chat_id);
                }

                @file_put_contents(
                    __DIR__.'/ai_debug.log',
                    "ROUTE AFTER AI: APPLY_PARAMETERS\n",
                    FILE_APPEND|LOCK_EX
                );
                $appliedResult = NeedApplicationService::applyParameters($chat_id, $params);
                $missing = MaxSearchApi::getAiMissingFields($chat_id);

                @file_put_contents(
                    __DIR__.'/ai_debug.log',
                    "AI PARAMS: ".json_encode($params,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n".
                    "AI APPLIED: ".json_encode($appliedResult,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n".
                    "AI CONTEXT AFTER: ".json_encode(MaxSearchApi::getAiSearchContext($chat_id),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n".
                    "AI MISSING: ".json_encode($missing,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",
                    FILE_APPEND|LOCK_EX
                );

                // ВАЖНО: после применения бизнес-дефолтов AI-вопрос может быть уже устаревшим.
                // Поэтому progression заново читает ФАКТИЧЕСКИ missing-поля после применения.
                NeedProgressionService::advance(
                    $chat_id,
                    ['country_explicit'=>true]
                );

    }
}