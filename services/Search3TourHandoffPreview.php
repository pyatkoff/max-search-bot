<?php
declare(strict_types=1);
require_once __DIR__.'/Search3TourHandoffSnapshot.php';

/** Customer presentation of the same saved observation; never a price calculator. */
final class Search3TourHandoffPreview
{
    public static function text(array $snapshot,?int $now=null): string
    {
        $snapshot=Search3TourHandoffSnapshot::normalize($snapshot);
        if ($snapshot===null) throw new InvalidArgumentException('Invalid saved tour');
        $o=$snapshot['observation'];$t=$o['trip'];$s=$o['stay'];$p=$o['price'];$f=$o['flights'];
        $lines=['<b>'.self::field($o['hotel']['name'],340).'</b>'];
        if ($o['hotel']['resort']!=='') $lines[]=self::field($o['hotel']['resort'],160);
        $lines[]='Вылет из '.self::field($t['origin'],200);
        $dates=self::day($t['departure']).($t['stayEnd']===null?'':' — '.self::day($t['stayEnd']));
        $lines[]=$dates.' · '.$t['nights'].' '.self::plural($t['nights'],['ночь','ночи','ночей']);
        $party=$t['adults'].' '.self::plural($t['adults'],['взрослый','взрослых','взрослых']);
        if ($t['childAges']!==[]) $party.=' · '.count($t['childAges']).' '.self::plural(count($t['childAges']),['ребёнок','ребёнка','детей']).' (возраст: '.implode(', ',$t['childAges']).' лет)';
        $lines[]=$party;
        $lines[]='Номер: '.self::field($s['room'],320);
        $lines[]='Питание: '.self::field($s['meal'],180);
        if ($s['placement']!=='') $lines[]='Размещение: '.self::field($s['placement'],120);
        if ($s['operator']!=='') $lines[]='Туроператор: '.self::field($s['operator'],160);
        if ($f['state']==='selected') {
            $lines[]='Туда: '.self::direction($f['outward']);$lines[]='Обратно: '.self::direction($f['inward']);
        } else $lines[]=$f['state']==='manager-deferred'?'Перелёт уточнит менеджер.':'Рейсы ещё не выбраны.';
        if ($p['amount']===null) $lines[]="\n<b>Стоимость уточнит менеджер.</b>";
        else {
            [$whole,$fraction]=array_pad(explode('.',$p['amount'],2),2,'');
            $amount=preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/',' ',$whole).($fraction===''?'':','.$fraction);
            $currency=['RUB'=>'₽','EUR'=>'€','USD'=>'$'][$p['currency']];
            $lines[]="\n<b>".$amount.' '.$currency.' за всех туристов</b>';
            $expired=$p['state']==='expired'||($p['expiresAt']!==null&&$p['expiresAt']<=($now??time()));
            $lines[]=$expired?'Сохранённая цена устарела. Нужно уточнить стоимость.':($p['state']==='estimated'?'Предварительная стоимость по выбранным условиям.':'Цена предложения из поиска.');
        }
        $lines[]='Итоговую стоимость и наличие подтвердит менеджер.';
        $lines[]="\nВыбранный тур сохранён. Можно написать вопрос в этом диалоге.";
        return implode("\n",$lines);
    }

    public static function photoCaption(array $snapshot): string
    {
        return '<b>'.self::field($snapshot['observation']['hotel']['name'],340)."</b>\nФото отеля. Интерьер выбранного номера может отличаться.";
    }

    public static function fitsPhotoCaption(string $text): bool
    {
        $plain=html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return intdiv(strlen(mb_convert_encoding($plain,'UTF-16LE','UTF-8')),2)<=900;
    }

    private static function field(string $value,int $bytes): string
    {
        $cut=mb_strcut($value,0,$bytes,'UTF-8');
        while (strlen(htmlspecialchars($cut,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'))>$bytes-3) $cut=mb_substr($cut,0,-1,'UTF-8');
        return htmlspecialchars($cut,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').($cut===$value?'':'…');
    }

    private static function day(string $value): string
    {
        [$year,$month,$day]=explode('-',$value);return $day.'.'.$month.'.'.$year;
    }

    private static function direction(array $legs): string
    {
        $labels=[];
        foreach ($legs as $leg) {
            if (isset($leg['label'])) {$labels[]=$leg['label'];continue;}
            $parts=array_filter([$leg['number'],$leg['carrier'],$leg['departure']['date'],$leg['departure']['time'],$leg['departure']['town'],$leg['departure']['portCode']??'']);
            $arrival=array_filter([$leg['arrival']['date'],$leg['arrival']['time'],$leg['arrival']['town'],$leg['arrival']['portCode']??'']);
            $labels[]=implode(' ',$parts).($arrival===[]?'':' → '.implode(' ',$arrival));
        }
        return self::field(implode(' · ',$labels),250);
    }

    private static function plural(int $n,array $forms): string
    {
        return $n%100>=11&&$n%100<=14?$forms[2]:($n%10===1?$forms[0]:($n%10>=2&&$n%10<=4?$forms[1]:$forms[2]));
    }
}
