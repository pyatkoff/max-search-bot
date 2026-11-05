<?php
declare(strict_types=1);

/** Shared text transforms only. Caller owns actor, clocks, context and outcome admission. */
final class ModeledConversionTextSignals
{
    public const VERSION='modeled_text_signals_v1';
    public static function scan(string $text):array
    {
        if(strlen($text)>32768||preg_match('//u',$text)!==1)throw new InvalidArgumentException('modeled_text_signals_input');
        return [
            'criteria_families'=>self::criteriaFamilies($text),
            'explicit_comparison'=>self::hit('сравн\pL*|чем\s+отлича\pL*|какой\s+лучше|другие\s+вариант\pL*|ещ[её]\s+вариант\pL*',$text),
            'explicit_modification'=>self::hit('поменя\pL*|замен\pL*|измен\pL*|давайте\s+(?:другой|другую|другие)|подберите\s+(?:нам\s+)?друг',$text),
            'price_alternative_continued_search'=>self::hit('дорого\pL*|дороже|дорог(?:ой|ая|ие|ие|оват\pL*)',$text)
                &&self::hit('(?:найдите|подберите|поищите|давайте)(?s:.{0,80})(?:дешевле|бюджетн\pL*)',$text),
            'near_term_urgency'=>self::hit('срочно|как\s+можно\s+скорее|в\s+ближайшие\s+дни|на\s+этой\s+неделе|(?:вылет|улететь|решить|купить|бронировать)(?s:.{0,35})(?:сегодня|завтра)',$text),
            'long_range_planning'=>self::hit('на\s+следующ\pL*\s+год|в\s+следующ\pL*\s+году|через\s+(?:полгода|год|[3-9]\s+месяц\pL*|1[0-2]\s+месяц\pL*)|за\s+год\s+заранее',$text),
            'explicit_decline'=>self::hit('не\s+будем\s+(?:покупать|бронировать|ехать)|отказываемся\s+от\s+(?:тура|поездки)|поездка\s+отменяется|тур\s+(?:нам\s+)?не\s+нужен',$text),
        ];
    }
    public static function criteriaFamilies(string $text):array
    {
        $rules=[
            'destination'=>'Турци\pL*|Египет\pL*|Таиланд\pL*|Мальдив\pL*|ОАЭ|Дуба[йи]\pL*|Тунис\pL*|Кипр\pL*|Греци\pL*|Испани\pL*|Итали\pL*|Вьетнам\pL*|Индонези\pL*|Бали|Куб[ауе]|Шри[- ]Ланк\pL*|Гоа|Сочи|Абхази\pL*',
            'dates'=>'январ\pL*|феврал\pL*|март\pL*|апрел\pL*|(?<!\pL)ма[йяе](?!\pL)|июн\pL*|июл\pL*|август\pL*|сентябр\pL*|октябр\pL*|ноябр\pL*|декабр\pL*|[0-3]?[0-9][./][01]?[0-9](?:[./][0-9]{2,4})?|\d+\s*(?:ноч(?:ь|и|ей)|дн(?:я|ей))|сегодня|завтра|на\s+этой\s+неделе|следующ\pL*\s+год|через\s+(?:полгода|год)',
            'party'=>'\d+\s*(?:взросл\pL*|дет\pL*|реб[её]н\pL*|чел\pL*|турист\pL*)|двое|трое|семь[её]й|с\s+(?:реб[её]нком|детьми)',
            'budget'=>'бюджет\pL*|\d[\d ,.]*\s*(?:тыс(?:яч\pL*)?|руб\pL*|₽|доллар\pL*|евро)|до\s+\d+\s*[$€]',
            'requirements'=>'вс[её]\s+включено|ультра\s+вс[её]|первая\s+лини\pL*|первой\s+лини\pL*|песчан\pL*\s+пляж|подогрева\pL*\s+бассейн|аквапарк|детск\pL*\s+(?:клуб|меню)|[345]\s*(?:зв[её]зд\pL*|\*)|без\s+пересад\pL*',
        ];
        $out=[];foreach($rules as$family=>$pattern)if(self::hit($pattern,$text))$out[]=$family;return$out;
    }
    private static function hit(string $pattern,string $text):bool{return preg_match('~'.$pattern.'~iu',$text)===1;}
}
