<?php
declare(strict_types=1);

/** Exact recorded bot questions disambiguate an answer; they never supply customer facts. */
final class ModeledConversionQuestionnaireContext
{
    public const QUESTIONS=[
        'date'=>'Какая ориентировочная дата вылета?',
        'adults'=>'Сколько будет взрослых туристов?',
        'children'=>'Будут дети? Если да — сколько?',
        'nights'=>'На сколько ночей планируете поездку?',
        'city'=>'Из какого города планируете вылет?',
        'country'=>'Куда хотите поехать?',
    ];

    public static function field(string $text):?string
    {
        if(strlen($text)>1024||preg_match('//u',$text)!==1)return null;
        $text=trim($text);
        if($text==='В какую страну хотите поехать?')return 'country';
        // Only the owned source-qualification presentation may surround this question.
        if(preg_match('/\A(?:Здравствуйте! 👋\n)?(?:Помогу подобрать тур в [^\r\n]{1,240}\.\n\n)?Когда планируете отдых\? Можно указать примерные даты\.\z/uD',$text)===1)return 'date';
        $field=array_search($text,self::QUESTIONS,true);
        return is_string($field)?$field:null;
    }

    /** Narrow model inference, not a stored field or successful-write assertion. */
    public static function accepts(string $field,string $text,?int $recordedAt=null):bool
    {
        if(strlen($text)>128||preg_match('//u',$text)!==1||preg_match('/[?？]/u',$text)===1)return false;
        $text=trim($text);
        if($field==='date')return self::dateLiteral($text,$recordedAt);
        if(in_array($field,['city','country'],true)){
            // A literal answer to this recorded question supplies context only.
            if(preg_match('/\A[\pL][\pL\pM\s,\-–—]+[\pL]\z/uD',$text)!==1)return false;
            return preg_match('/\A(?:да|нет|не знаю|пока не знаю|любой|любая|не важно|без разницы|рассматриваю варианты)\z/iuD',$text)!==1;
        }
        if($field==='adults')return preg_match('/\A[1-6][.!]*\z/D',$text)===1;
        if($field==='children')return preg_match('/\A(?:[0-3]|нет|без|без детей)[.!]*\z/iuD',$text)===1;
        if($field!=='nights'||preg_match('/\A(?:на\s+)?([1-9][0-9]?)\s*(?:[-–—]\s*([1-9][0-9]?))?\s*(?:ноч(?:ь|и|ей))?[.!]*\z/iuD',$text,$m)!==1)return false;
        $low=(int)$m[1];$high=isset($m[2])?(int)$m[2]:$low;
        return $low<= $high&&$high<=28;
    }

    private static function dateLiteral(string $text,?int $recordedAt):bool
    {
        // The recorded customer clock, never wall time, rules out an explicit past date.
        if($recordedAt===null||$recordedAt<946684800||$recordedAt>4102444800)return false;
        if(preg_match('/\A(.+?)\s+на\s+([1-9][0-9]?)\s*(?:день|дня|дней|ночь|ночи|ночей)[.!]*\z/iuD',$text,$duration)===1){
            if((int)$duration[2]>28)return false;
            $text=$duration[1];
        }
        $text=preg_replace('/[.!]+\z/u','',$text)??'';
        $text=preg_replace('/\Aс\s+/iu','',$text)??'';
        $parts=preg_split('/(?:\s+по\s+|\s*[-–—]\s*)/iu',$text);
        if(!is_array($parts)||count($parts)<1||count($parts)>2)return false;
        $today=(int)(new DateTimeImmutable('@'.$recordedAt))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Ymd');
        $dates=[];
        foreach($parts as$part){
            $date=self::calendarLiteral(trim($part));
            if($date===null)return false;
            [$day,$month,$year]=$date;
            if($year!==null&&$year*10000+$month*100+$day<$today)return false;
            $dates[]=$date;
        }
        if(count($dates)===2){
            [$fd,$fm,$fy]=$dates[0];[$td,$tm,$ty]=$dates[1];
            if(($fy===null)!==($ty===null))return false;
            if(($fy??0)*10000+$fm*100+$fd>($ty??0)*10000+$tm*100+$td)return false;
        }
        return true;
    }

    /** Calendar possibility only: an absent year is never filled into the forecast. */
    private static function calendarLiteral(string $text):?array
    {
        $day=0;$month=0;$rawYear='';
        if(preg_match('/\A([0-3]?[0-9])([.\/])([01]?[0-9])(?:\2([0-9]{2}|[0-9]{4}))?\z/D',$text,$m)===1){
            $day=(int)$m[1];$month=(int)$m[3];$rawYear=$m[4]??'';
        }elseif(preg_match('/\A([0-3]?[0-9])\s+(января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря)(?:\s+([0-9]{2}|[0-9]{4}))?\z/iuD',$text,$m)===1){
            $day=(int)$m[1];$rawYear=$m[3]??'';
            foreach(['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря']as$i=>$name)
                if(preg_match('/\A'.$name.'\z/iuD',$m[2])===1){$month=$i+1;break;}
        }else return null;
        $year=$rawYear===''?null:(strlen($rawYear)===2?2000+(int)$rawYear:(int)$rawYear);
        if(($year!==null&&($year<2000||$year>2100))||!checkdate($month,$day,$year??2000))return null;
        return [$day,$month,$year];
    }
}
