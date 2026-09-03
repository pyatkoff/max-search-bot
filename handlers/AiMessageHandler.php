<?php
require_once(__DIR__ . '/AiDateHandler.php');
require_once(__DIR__ . '/../services/MissingFieldQuestionService.php');
require_once(__DIR__ . '/../services/DialogueView.php');
require_once(__DIR__ . '/../services/NeedApplicationService.php');
require_once(__DIR__ . '/../services/NeedProgressionService.php');
require_once(__DIR__ . '/../services/LocalAiFallbackService.php');
require_once(__DIR__ . '/../services/AiBusinessDefaultsService.php');
require_once(__DIR__ . '/../services/AiInvocationService.php');
require_once(__DIR__ . '/../services/AiRuntimeLogger.php');
require_once(__DIR__ . '/../services/AiDateContextService.php');
require_once(__DIR__ . '/../services/AiNeedCompletionService.php');
require_once(__DIR__ . '/../services/DepartureCityResolver.php');

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
                        $appliedPendingDate = NeedApplicationService::applyParameters(
                            $chat_id,
                            ['date'=>$shortDateValue]
                        );

                        if (!empty($appliedPendingDate['date'])) {
                            NeedProgressionService::advance($chat_id);
                            return;
                        }
                    }
                }

                // Если сейчас не хватает возраста детей, короткий ответ проходит через
                // общий deterministic resolver/application/progression pipeline.
                if (in_array('child_ages', $missingNow, true)) {
                    $ageResult = AiNeedCompletionService::resolveApplyAndAdvance(
                        $chat_id,
                        'child_ages',
                        $userText,
                        ['children'=>(int)($current['children'] ?? 0)]
                    );

                    if (!empty($ageResult['advanced'])) {
                        return;
                    }
                }

                // Сначала классифицируем сообщение. Полноценные запросы по-прежнему
                // проходят через AI, но явные факты из текста применяем до внешнего вызова.
                // Это сохраняет прогресс и позволяет задать следующий вопрос даже при timeout/error AI.
                $route = LocalAiFallbackService::classify($userText);
                $richTourRequest = !empty($route['rich']);
                $ai = null;

                if ($richTourRequest) {
                    $departure = DepartureCityResolver::resolveAndStore($chat_id, $userText);
                    if ($departure) {
                        $current = MaxSearchApi::getAiSearchContext($chat_id);
                    }

                    $richLocalParams = LocalAiFallbackService::parameters($userText, $current);
                    // The local helper historically defaults an absent departure to Moscow.
                    // For rich pre-seeding only explicit departure is allowed; AI/business defaults
                    // may still choose Moscow later under the existing policy.
                    if (empty($current['city']) && !$departure) {
                        unset($richLocalParams['city']);
                    }
                    $richLocalDate = AiDateContextService::resolveLocal($chat_id, $userText);
                    if (!empty($richLocalDate['date'])) {
                        $richLocalParams['date'] = $richLocalDate['date'];
                    }
                    $richLocalParams = LocalAiFallbackService::applyDestinationDefaults($richLocalParams, $current);
                    if (!empty($richLocalParams)) {
                        NeedApplicationService::applyParameters($chat_id, $richLocalParams);
                        $current = MaxSearchApi::getAiSearchContext($chat_id);
                    }

                    $ai = AiInvocationService::invoke('RICH_AI', $chat_id, $userText, $current);
                } else {
                    $localParams = LocalAiFallbackService::parameters($userText, $current);

                    // Stateful month-only/date clarification policy lives behind one boundary.
                    $localDate = AiDateContextService::resolveLocal($chat_id, $userText);
                    $localMonthOnly = !empty($localDate['month_only']);
                    if (!empty($localDate['date'])) {
                        $localParams['date'] = $localDate['date'];
                    }

                    $localParams = LocalAiFallbackService::applyDestinationDefaults($localParams, $current);
                    $hadCurrentBeforeLocal = !empty($current);

                    if (!empty($localParams)) {
                        $appliedLocal = NeedApplicationService::applyParameters($chat_id, $localParams);
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

                    $ai = AiInvocationService::invoke('SHORT_AI', $chat_id, $userText, $current);
                }

                // $ai уже получен либо через RICH_AI, либо через SHORT_AI.
                // Ни local fallback, ни второй AiRouter здесь повторно не запускаются.

                if (is_array($ai)) {
                    $ai = AiBusinessDefaultsService::apply($ai, $userText, $current);
                }

                if (!is_array($ai) || !empty($ai['_error'])) {
                    put_log_out('AI ERROR: '.json_encode($ai, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

                    $missingAfterError=MaxSearchApi::getAiMissingFields($chat_id);
                    MissingFieldQuestionService::sendForMissing($chat_id, $missingAfterError);
                    return;
                }

                $params = is_array($ai['parameters'] ?? null) ? $ai['parameters'] : [];
                $params = AiDateContextService::applyAiGuard($chat_id, $userText, $params);

                AiRuntimeLogger::debug("ROUTE AFTER AI: APPLY_PARAMETERS\n");
                $completion = AiNeedCompletionService::applyAndAdvance(
                    $chat_id,
                    $params,
                    ['country_explicit'=>true]
                );
                $appliedResult = $completion['applied'];
                $missing = $completion['missing'];

                AiRuntimeLogger::debug(
                    "AI PARAMS: ".json_encode($params,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n".
                    "AI APPLIED: ".json_encode($appliedResult,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n".
                    "AI CONTEXT AFTER: ".json_encode(MaxSearchApi::getAiSearchContext($chat_id),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n".
                    "AI MISSING: ".json_encode($missing,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n"
                );

    }
}
