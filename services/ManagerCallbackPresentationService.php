<?php
declare(strict_types=1);

require_once __DIR__.'/CallbackGeneration.php';
require_once __DIR__.'/DepartureCityValueContract.php';
require_once __DIR__.'/CountryValueContract.php';
require_once __DIR__.'/DateValueContract.php';

/** Read-only display of a recorded click; never proof that a handler applied it. */
final class ManagerCallbackPresentationService
{
    private const LABELS = [
        'show_tours'=>'Нажата кнопка «Показать туры»',
        'manager_request'=>'Нажата кнопка «Подобрать с менеджером»',
        'manager_after_tours'=>'Нажата кнопка «Нужна помощь с подбором»',
        'phone_manual'=>'Нажата кнопка ручного ввода телефона',
        'tours_checked'=>'Нажата кнопка возврата после просмотра туров',
        'tours_found'=>'Нажата кнопка «Нашёл подходящие варианты»',
        'search_options'=>'Нажата кнопка «Подобрать тур»',
        'ai_start'=>'Нажата кнопка «Подобрать с AI»',
        'start_search'=>'Нажата кнопка «Подобрать по шагам»',
        'restart'=>'Нажата кнопка начала нового подбора',
        'edit_params'=>'Нажата кнопка «Изменить параметры»',
        'edit_city'=>'Нажата кнопка изменения города вылета',
        'edit_country'=>'Нажата кнопка изменения страны',
        'edit_tourists'=>'Нажата кнопка изменения состава туристов',
        'edit_stars'=>'Нажата кнопка изменения категории отеля',
        'edit_meal'=>'Нажата кнопка изменения питания',
        'edit_nights'=>'Нажата кнопка изменения количества ночей',
        'edit_date'=>'Нажата кнопка изменения даты поездки',
        'back_pick_city'=>'Нажата кнопка возврата к выбору города',
        'back_pick_country'=>'Нажата кнопка возврата к выбору страны',
        'back_adults'=>'Нажата кнопка возврата к выбору числа взрослых',
        'back_child'=>'Нажата кнопка возврата к выбору числа детей',
        'back_stars'=>'Нажата кнопка возврата к выбору категории отеля',
        'back_meal'=>'Нажата кнопка возврата к выбору питания',
        'back_nights'=>'Нажата кнопка возврата к выбору количества ночей',
        'back_calendar'=>'Нажата кнопка возврата к календарю',
        'back_check'=>'Нажата кнопка возврата к параметрам поездки',
        'back_phone'=>'Нажата кнопка «Назад»',
        'pick_city_other'=>'Нажата кнопка «Другой город»',
        'pick_country_other'=>'Нажата кнопка «Другая страна»',
        'nights_other'=>'Нажата кнопка ввода своего количества ночей',
        'nights_6_8'=>'Нажата кнопка «6–8 ночей»',
        'nights_9_11'=>'Нажата кнопка «9–11 ночей»',
        'nights_12_14'=>'Нажата кнопка «12–14 ночей»',
        'star_1'=>'Нажата кнопка категории отеля «Не важно»',
        'star_2'=>'Нажата кнопка категории отеля «от 2★»',
        'star_3'=>'Нажата кнопка категории отеля «от 3★»',
        'star_4'=>'Нажата кнопка категории отеля «от 4★»',
        'star_5'=>'Нажата кнопка категории отеля «5★»',
        'meal_3'=>'Нажата кнопка питания «Только завтраки»',
        'meal_4'=>'Нажата кнопка питания «Завтрак + ужин»',
        'meal_5'=>'Нажата кнопка питания «Полный пансион»',
        'meal_7'=>'Нажата кнопка питания «Всё включено»',
        'meal_999'=>'Нажата кнопка питания «Не важно»',
    ];

    public static function fromMessage(array $message, ?array $metadata = null): ?array
    {
        if (($message['direction']??null)!=='inbound' || ($message['sender_type']??null)!=='customer') return null;
        if ($metadata === null) {
            $json=$message['metadata_json']??null;
            $metadata=is_string($json)?json_decode($json,true):null;
        }
        if (!is_array($metadata) || ($metadata['type']??null)!=='callback') return null;

        $raw=(string)($message['text']??'');
        $payload=CallbackGeneration::base($raw);
        // Like callbacks contain a private offer token, including generation-wrapped historical rows.
        $publicRaw=str_starts_with($payload,'tour_like_')?'':$raw;
        return ['type'=>'callback','label'=>self::label($payload),'raw_payload'=>$publicRaw];
    }

    private static function label(string $payload): string
    {
        if (isset(self::LABELS[$payload])) return self::LABELS[$payload];
        // Keep the existing historical like-button presentation without exposing its opaque offer/token.
        if (str_starts_with($payload,'tour_like_')) return 'Нажата кнопка «Нравится»';
        $city=DepartureCityValueContract::fromCallbackPayload($payload);
        if ($city !== null) return $city===(string)DepartureCityValueContract::NO_FLIGHT_ID
            ? 'Нажата кнопка «Без перелёта»' : 'Нажата кнопка выбора города';
        if (CountryValueContract::fromCallbackPayload($payload) !== null) return 'Нажата кнопка выбора страны';
        if (preg_match('/\Aadults_([1-6])\z/D',$payload,$match)===1) {
            return 'Нажата кнопка «'.$match[1].($match[1]==='1'?' взрослый':' взрослых').'»';
        }
        if (preg_match('/\Achild_([0-3])\z/D',$payload,$match)===1) {
            if ($match[1]==='0') return 'Нажата кнопка «Без детей»';
            return 'Нажата кнопка «'.$match[1].($match[1]==='1'?' ребёнок':' ребёнка').'»';
        }
        $date=DateValueContract::fromCallbackPayload($payload);
        if ($date !== null) return 'Нажата кнопка выбора даты: '.$date;
        if (preg_match('/\Amonth_change_(\d{2}\.\d{4})\z/D',$payload,$match)===1
            && DateValueContract::fromStorageValue('01.'.$match[1]) !== null) {
            return 'Нажата кнопка календаря: '.$match[1];
        }
        return 'Клиент нажал кнопку';
    }
}
