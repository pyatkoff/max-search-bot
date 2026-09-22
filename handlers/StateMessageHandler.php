<?php
require_once dirname(__DIR__) . '/services/DialogueView.php';
require_once dirname(__DIR__) . '/services/WizardStepView.php';
require_once dirname(__DIR__) . '/services/EditFlowService.php';
require_once dirname(__DIR__) . '/services/IntegrationRegistry.php';
require_once dirname(__DIR__) . '/services/NeedValueResolver.php';
require_once dirname(__DIR__) . '/services/NeedApplicationService.php';
require_once dirname(__DIR__) . '/services/NeedProgressionService.php';
require_once dirname(__DIR__) . '/services/ExistingWizardStepApplicationService.php';
require_once dirname(__DIR__) . '/services/ChildAgeValueContract.php';
require_once dirname(__DIR__) . '/services/DialogueTransitionObserver.php';
require_once dirname(__DIR__) . '/services/DepartureCityResolver.php';
require_once dirname(__DIR__) . '/services/DepartureCityValueContract.php';
require_once dirname(__DIR__) . '/services/CountryValueContract.php';
require_once dirname(__DIR__) . '/services/DateValueContract.php';
require_once __DIR__ . '/AiDateHandler.php';
require_once __DIR__ . '/AiMessageHandler.php';

class StateMessageHandler
{
    public static function handle($message, $chat_id, $status)
    {
            // Adults is an independent early-return owner so the established
            // source-bounded contracts for the other wizard steps remain intact.
            if($status==MaxSearchApi::$statusAdults)
            {
                $result = NeedApplicationService::resolveAndApplyExistingWizardStep(
                    $chat_id,
                    'adults',
                    (string)($message['text'] ?? ''),
                    (int)MaxSearchApi::$statusAdults
                );
                if(!empty($result['recognized']))
                {
                    if(empty($result['applied'])) return;
                    MaxSearchApi::showChildButtons($chat_id);
                }
                else
                    self::send($chat_id,"Не получилось определить количество взрослых. Напишите число от 1 до 6 — например: 2 или «двое».");
                return;
            }

            if($status==MaxSearchApi::$statusCityChoose)
            {
                $city = trim($message['text']);
                $cityRes =  MaxSearchApi::getCityByName($city);
                if(!$cityRes)
                {
                    $resolvedCity = DepartureCityResolver::resolveFieldValue($city);
                    if($resolvedCity)
                        $cityRes = ['ID' => $resolvedCity['city_id'], 'NAME' => $resolvedCity['city']];
                }
                if($cityRes)
                {
                    $cityId = DepartureCityValueContract::fromDirectoryId($cityRes["ID"] ?? null);
                    if($cityId === null) return;
                    if(!ExistingWizardStepApplicationService::apply(
                        $chat_id,
                        MaxSearchApi::$statusCityChoose,
                        $cityId
                    )) return;
                    if(!EditFlowService::finishIfNeeded($chat_id,'city'))
                        MaxSearchApi::showCountryButtons($chat_id);
                }
                elseif(self::shouldRouteFreeTextToAi($city))
                {
                    self::routeFreeTextToAi($message,$chat_id);
                }
                else
                    self::send($chat_id,"Не нашла такой город вылета. Проверьте название или выберите один из предложенных вариантов.");

            }
            elseif($status==MaxSearchApi::$statusContryChoose)
            {
                $country = trim($message['text']);
                $countryRes =  MaxSearchApi::getCountryByName($country);
                if($countryRes)
                {
                    $countryId = CountryValueContract::fromDirectoryId($countryRes["ID"] ?? null);
                    if($countryId === null) return;
                    if(!ExistingWizardStepApplicationService::apply(
                        $chat_id,
                        MaxSearchApi::$statusContryChoose,
                        $countryId
                    )) return;
                    if(!EditFlowService::finishIfNeeded($chat_id,'country'))
                        MaxSearchApi::showAdultsButtons($chat_id);
                }
                elseif(self::shouldRouteFreeTextToAi($country))
                {
                    self::routeFreeTextToAi($message,$chat_id);
                }
                else
                    self::send($chat_id,"Не нашла это направление в поиске. Проверьте название или выберите одну из популярных стран.");

            }
            elseif($status==MaxSearchApi::$statusChild)
            {
                $result = NeedApplicationService::resolveAndApplyExistingWizardStep(
                    $chat_id,
                    'children',
                    (string)($message['text'] ?? ''),
                    (int)MaxSearchApi::$statusChild
                );
                if(!empty($result['recognized']))
                {
                    if(empty($result['applied'])) return;
                    $children = (int)$result['value'];

                    if($children===0)
                    {
                        if(!EditFlowService::finishIfNeeded($chat_id,'tourists'))
                            MaxSearchApi::showStarsButtons($chat_id);
                    }
                    else
                        MaxSearchApi::showAgeButtons($chat_id,$children);
                }
                else
                    self::send($chat_id,"Не получилось определить количество детей. Напишите «нет», «без детей» или число от 1 до 3.");

            }
            elseif($status==MaxSearchApi::$statusAge)
            {
                $error = false;
                $childCount = MaxSearchApi::getLastValue($chat_id,MaxSearchApi::$statusChild);
                $ageOut = ChildAgeValueContract::parseLegacyInput(
                    (string)$message['text'],
                    (int)$childCount
                );
                if($ageOut===null)
                    $error = true;
                else
                {
                    $ageValue = ChildAgeValueContract::toStorage($ageOut, (int)$childCount);
                    if($ageValue===null)
                        $error = true;
                    elseif(ExistingWizardStepApplicationService::apply(
                        $chat_id,
                        MaxSearchApi::$statusAge,
                        $ageValue
                    ))
                    {
                        if(!EditFlowService::finishIfNeeded($chat_id,'tourists'))
                            MaxSearchApi::showStarsButtons($chat_id);
                    }
                }
                if($error)
                {
                    if(intval($childCount)==1)
                        self::send($chat_id,"К сожалению возраст ребенка указан неверно. Пожалуйста, введите 1 число в диапазоне от 0 до 17.");
                    else
                        self::send($chat_id,"К сожалению возраст детей указан неверно. Пожалуйста, введите ".$childCount." числа через разделитель (пробел или запятая) в диапазоне от 0 до 17.");
                }

            }
            elseif($status==MaxSearchApi::$statusStars)
            {
                $resolved = NeedValueResolver::resolve('stars', (string)($message['text'] ?? ''));
                if(!empty($resolved['recognized']))
                {
                    if(!ExistingWizardStepApplicationService::apply(
                        $chat_id,
                        MaxSearchApi::$statusStars,
                        (string)((int)$resolved['value'])
                    )) return;
                    if(!EditFlowService::finishIfNeeded($chat_id,'stars'))
                        WizardStepView::meal($chat_id);
                }
                else
                    self::send($chat_id,"Не получилось определить категорию отеля. Напишите от 1 до 5 звёзд — например: 4, «от 4★» или «не важно».");

            }
            elseif($status==MaxSearchApi::$statusMeal)
            {
                $result = NeedApplicationService::resolveAndApplyExistingWizardStep(
                    $chat_id,
                    'meal',
                    (string)($message['text'] ?? ''),
                    (int)MaxSearchApi::$statusMeal
                );
                if(!empty($result['recognized']))
                {
                    if(empty($result['applied'])) return;
                    if(!EditFlowService::finishIfNeeded($chat_id,'meal'))
                        WizardStepView::nights($chat_id);
                }
                else
                    self::send($chat_id,"Не получилось определить питание. Напишите, например: «всё включено», «завтрак», «полупансион», «полный пансион» или «не важно».");

            }
            elseif($status==MaxSearchApi::$statusNights)
            {
                $resolved = NeedValueResolver::resolve('nights', (string)$message['text']);
                if(!empty($resolved['recognized']))
                {
                    $applied = ExistingWizardStepApplicationService::apply(
                        $chat_id,
                        MaxSearchApi::$statusNights,
                        $resolved['value']
                    );
                    if($applied)
                    {
                        if(!EditFlowService::finishIfNeeded($chat_id,'nights')) {
                            DialogueTransitionObserver::observe(
                                $chat_id,
                                (int)MaxSearchApi::$statusNights,
                                (int)MaxSearchApi::$statusDate,
                                'forward',
                                'free_text_nights'
                            );
                            DialogueView::calendar($chat_id,date("m"),date("Y"));
                        }
                    }
                }
                else
                    self::send($chat_id,"К сожалению диапазон ночей указан неверно. Пожалуйста, укажите число или диапазон от 1 до 28 — например: 6, на 6 ночей или 7-10 ночей.");

            }
            elseif($status==MaxSearchApi::$statusDate)
            {
                $text = trim((string)($message['text'] ?? ''));
                $date = AiDateHandler::resolvePendingShortDate($chat_id, $text);
                if($date === '')
                {
                    $resolved = AiDateHandler::rememberMonthFromText($chat_id, $text);
                    $date = (string)($resolved['date'] ?? '');
                    if($date === '' && !empty($resolved['month']) && !empty($resolved['year']))
                    {
                        DialogueView::calendar($chat_id, (int)$resolved['month'], (int)$resolved['year']);
                        return;
                    }
                }

                if($date !== '')
                {
                    $dateValue = DateValueContract::fromStorageValue($date);
                    if($dateValue === null)
                    {
                        self::send($chat_id,"Не получилось распознать дату. Напишите, например: 8 ноября, 08.11 или выберите дату в календаре.");
                        return;
                    }
                    if(!ExistingWizardStepApplicationService::apply(
                        $chat_id,
                        MaxSearchApi::$statusDate,
                        $dateValue
                    )) return;
                    if(!EditFlowService::finishIfNeeded($chat_id,'date'))
                        NeedProgressionService::advance($chat_id);
                }
                else
                    self::send($chat_id,"Не получилось распознать дату. Напишите, например: 8 ноября, 08.11 или выберите дату в календаре.");

            }
            elseif($status==MaxSearchApi::$statusPhone)
            {
                $phone = trim((string)($message['text'] ?? ''));
                $phoneKind = self::phoneTextKind($phone);
                if($phoneKind === 'non_phone')
                {
                    self::send(
                        $chat_id,
                        "Хорошо, можно продолжить ждать ответ здесь — номер телефона необязателен. Если решите оставить номер, отправьте его в формате +71234567890."
                    );
                    return;
                }
                if($phoneKind === 'invalid_phone')
                    self::send($chat_id,"Не получилось распознать номер. Напишите его в формате +71234567890.");
                else
                {
                    $ok = MaxSearchApi::savePhone($chat_id, $phone);
                    MaxSearchApi::deletePrevMessage($chat_id,true);
                    MaxSearchApi::deleteAllStatus($chat_id);
                    if($ok)
                        DialogueView::channelOffer($chat_id,true);
                    else
                        self::send($chat_id,"Не получилось сохранить номер. Попробуйте ещё раз.");
                }
            }
    }

    /**
     * A wizard prompt may receive a complete natural-language request instead of
     * the single value it asked for. Do not reject that as an unknown city/country;
     * hand the whole message back to the AI collector so already supplied fields
     * (month, tourists, nights, etc.) are not lost.
     */
    public static function shouldRouteFreeTextToAi($text): bool
    {
        $text = trim((string)$text);
        if ($text === '') return false;

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 2) return false;

        return (bool)preg_match(
            '/(?:\bхоч(?:у|ем)\b|\bпоед(?:у|ем|ет)\b|\bвылет\w*\b|\bтур\w*\b|\bноч\w*\b|\bвзросл\w*\b|\bреб[её]н\w*\b|\bдет\w*\b|\bянвар\w*\b|\bфеврал\w*\b|\bмарт\w*\b|\bапрел\w*\b|\bма[йя]\w*\b|\bиюн\w*\b|\bиюл\w*\b|\bавгуст\w*\b|\bсентябр\w*\b|\bоктябр\w*\b|\bноябр\w*\b|\bдекабр\w*\b|\b\d{1,2}[.\/-]\d{1,2}\b)/ui',
            $text
        );
    }

    /**
     * The fallback explicitly allows the tourist to keep waiting in chat. Only
     * phone-looking input should therefore receive a phone-format error.
     */
    private static function phoneTextKind(string $text): string
    {
        $text = trim($text);
        if(preg_match('/^\+7\d{10}$/D', $text) === 1)
            return 'valid_phone';

        $digits = preg_replace('/\D/', '', $text);
        if(is_string($digits) && strlen($digits) >= 9)
            return 'invalid_phone';
        if(strpos($text, '+7') === 0)
            return 'invalid_phone';

        return 'non_phone';
    }

    private static function routeFreeTextToAi($message, $chatId): void
    {
        MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusAi);
        AiMessageHandler::handle($message, $chatId);
    }

    private static function send($chatId, string $text): bool
    {
        return (bool)IntegrationRegistry::messenger()->send($chatId, $text);
    }
}