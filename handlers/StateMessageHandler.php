<?php
require_once dirname(__DIR__) . '/services/DialogueView.php';
require_once dirname(__DIR__) . '/services/IntegrationRegistry.php';

class StateMessageHandler
{
    public static function handle($message, $chat_id, $status)
    {
            if($status==MaxSearchApi::$statusCityChoose)
            {
                $city = trim($message['text']);
                $cityRes =  MaxSearchApi::getCityByName($city);
                if($cityRes)
                {
                    MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusCityChoose,$cityRes["ID"]);
                    if(!MaxSearchApi::finishEditIfNeeded($chat_id,'city'))
                        MaxSearchApi::showCountryButtons($chat_id);
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
                    MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusContryChoose,$countryRes["ID"]);
                    if(!MaxSearchApi::finishEditIfNeeded($chat_id,'country'))
                        MaxSearchApi::showAdultsButtons($chat_id);
                }
                else
                    self::send($chat_id,"Не нашла это направление в поиске. Проверьте название или выберите одну из популярных стран.");

            }
            elseif($status==MaxSearchApi::$statusAge)
            {
                $age = $message['text'];
                $error = false;
                $childCount = MaxSearchApi::getLastValue($chat_id,MaxSearchApi::$statusChild);
                preg_match('/[^\d\s,]{1,}/', $age, $checkArray);
                if(is_array($checkArray) && count($checkArray)>0)
                    $error = true;
                else
                {
                    $sep = " ";
                    if(strpos($age,",")!==false)
                        $sep = ",";
                    $ageArr = explode($sep,$age);
                    $ageOut = [];
                    foreach($ageArr as $ageItem)
                    {
                        $ageItem = intval(trim($ageItem));
                        if($ageItem<0 || $ageItem>17)
                        {
                            $error = true;
                            break;
                        }
                        else
                        $ageOut[] = $ageItem;
                    }
                    if(!$error && count($ageOut)!=$childCount)
                        $error = true;
                    if(!$error)
                    {
                        $ageOut = implode(", ",$ageOut);
                        MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusAge,$ageOut);
                        if(!MaxSearchApi::finishEditIfNeeded($chat_id,'tourists'))
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
                $nights = $message['text'];
                $error = false;
                preg_match('/[^\d\s\-]{1,}/', $nights, $checkArray);
                if(is_array($checkArray) && count($checkArray)>0)
                    $error = true;
                else
                {
                    $sep = " ";
                    if(strpos($nights,"-")!==false)
                        $sep = "-";
                    $nightsArr = explode($sep,$nights);
                    $nightsOut = [];
                    foreach($nightsArr as $nightItem)
                    {
                        $nightItem = intval(trim($nightItem));
                        if($nightItem<=0 || $nightItem>28)
                        {
                            $error = true;
                            break;
                        }
                        else
                            $nightsOut[] = $nightItem;
                    }
                    if(!$error && (count($nightsOut)<=0 ||  count($nightsOut)>2))
                        $error = true;
                    if(!$error)
                    {
                        $nightsOut = implode("-",$nightsOut);
                        MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusNights,$nightsOut);
                        if(!MaxSearchApi::finishEditIfNeeded($chat_id,'nights'))
                            MaxSearchApi::showCalendarButtons($chat_id,date("m"),date("Y"));
                    }
                }
                if($error)
                    self::send($chat_id,"К сожалению диапазон ночей указан неверно. Пожалуйста, укажите одно число или два числа через разделитель (пробел или дефис) в диапазоне от 1 до 28.");

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

    private static function send($chatId, string $text): bool
    {
        return (bool)IntegrationRegistry::messenger()->send($chatId, $text);
    }
}
