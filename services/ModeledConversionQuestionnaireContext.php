<?php
declare(strict_types=1);

/** Exact recorded bot questions disambiguate an answer; they never supply customer facts. */
final class ModeledConversionQuestionnaireContext
{
    public const QUESTIONS=[
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
        $field=array_search($text,self::QUESTIONS,true);
        return is_string($field)?$field:null;
    }

    /** Narrow model inference, not a stored field or successful-write assertion. */
    public static function accepts(string $field,string $text):bool
    {
        if(strlen($text)>128||preg_match('//u',$text)!==1||preg_match('/[?？]/u',$text)===1)return false;
        $text=trim($text);
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
}
