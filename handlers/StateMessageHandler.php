<?php
require_once dirname(__DIR__) . '/services/DialogueView.php';
require_once dirname(__DIR__) . '/services/WizardStepView.php';
require_once dirname(__DIR__) . '/services/EditFlowService.php';
require_once dirname(__DIR__) . '/services/IntegrationRegistry.php';
require_once dirname(__DIR__) . '/services/NeedValueResolver.php';
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
                        DialogueView::check($chat_id);
                }
                else
                    self::send($chat_id,"Не получилось распознать дату. Напишите, например: 8 ноября, 08.11 или выберите дату в календаре.");

            }
            elseif($status==MaxSearchApi::$statusPhone)
            {
                $phone = $message['text'];
                $error = false;
                if(strlen($phone)!=12 || strpos($phone,"+7")!==0)
                    $error = true;
                else
                {
                    $subPhone = substr($phone,2);
                    preg_match('/[^\d]{1,}/', $subPhone, $checkArray);
                    if(is_array($checkArray) && count($checkArray)>0)
                        $error = true;
                }
                if($error)
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
