<?php
require_once(__DIR__ . '/../ai/AiRouter.php');
require_once(__DIR__ . '/AiDateHandler.php');
require_once(__DIR__ . '/../services/MissingFieldQuestionService.php');
require_once(__DIR__ . '/../services/DialogueView.php');
require_once(__DIR__ . '/../services/NeedApplicationService.php');
require_once(__DIR__ . '/../services/NeedProgressionService.php');
require_once(__DIR__ . '/../services/LocalAiFallbackService.php');

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

                // Сначала классифицируем сообщение. Полноценный запрос сразу отдаём AI;
                // только короткие сообщения и простые коррекции идут в local fallback.
                $route = LocalAiFallbackService::classify($userText);
                $richTourRequest = !empty($route['rich']);
                $ai = null;

                if ($richTourRequest) {
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
                    $localParams = LocalAiFallbackService::parameters($userText, $current);

                    // Дата остаётся отдельным stateful обработчиком: month_only и pending month
                    // не смешиваем с чистой классификацией local fallback.
                    $localDateResolved = AiDateHandler::rememberMonthFromText($chat_id, $userText);
                    $localMonthOnly = !empty($localDateResolved['month']) && empty($localDateResolved['date']);
                    if (!empty($localDateResolved['date'])) {
                        $localParams['date'] = $localDateResolved['date'];
                    }

                    $localParams = LocalAiFallbackService::applyDestinationDefaults($localParams, $current);
                    $hadCurrentBeforeLocal = !empty($current);

                    if (!empty($localParams)) {
                        $appliedLocal = MaxSearchApi::applyAiParameters($chat_id,$localParams);
                        $current=MaxSearchApi::getAiSearchContext($chat_id);
                    } else {
                        $appliedLocal = [];
                    }

                    $missingLocal=MaxSearchApi::getAiMissingFields($chat_id);
                    $simpleLocal = !empty($route['simple']);

                    if ($simpleLocal && empty($missingLocal) && $hadCurrentBeforeLocal && !empty($appliedLocal)) {
                        DialogueView::check($chat_id);
                        return;
                    }

                    // Короткие ответы с курортом/регионом (например, "Алания") должны один раз
                    // пройти через AI, если local parser не смог определить страну.
                    $unresolvedDestination = LocalAiFallbackService::unresolvedDestination($missingNow, $missingLocal);
                    if ($simpleLocal && !empty($missingLocal) && !$unresolvedDestination) {
                        MissingFieldQuestionService::sendForMissing(
                            $chat_id,
                            $missingLocal,
                            ['month_only'=>$localMonthOnly]
                        );
                        return;
                    }

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

                // $ai уже получен либо через RICH_AI, либо через SHORT_AI.
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
