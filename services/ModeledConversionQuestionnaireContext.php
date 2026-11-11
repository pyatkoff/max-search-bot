<?php
declare(strict_types=1);

/** Exact recorded bot questions disambiguate an answer; they never supply customer facts. */
final class ModeledConversionQuestionnaireContext
{
    public const QUESTIONS=[
        'adults'=>'Сколько будет взрослых туристов?',
        'children'=>'Будут дети? Если да — сколько?',
        'nights'=>'На сколько ночей планируете поездку?',
    ];

    public static function field(string $text):?string
    {
        if(strlen($text)>1024||preg_match('//u',$text)!==1)return null;
        $field=array_search(trim($text),self::QUESTIONS,true);
        return is_string($field)?$field:null;
    }

    /** Narrow model inference, not a stored field or successful-write assertion. */
    public static function accepts(string $field,string $text):bool
    {
        if(strlen($text)>128||preg_match('//u',$text)!==1||preg_match('/[?？]/u',$text)===1)return false;
        $text=trim($text);
        if($field==='adults')return preg_match('/\A[1-6][.!]*\z/D',$text)===1;
        if($field==='children')return preg_match('/\A(?:[0-3]|нет|без детей)[.!]*\z/iuD',$text)===1;
        if($field!=='nights'||preg_match('/\A([1-9][0-9]?)(?:-([1-9][0-9]?))?[.!]*\z/D',$text,$m)!==1)return false;
        $low=(int)$m[1];$high=isset($m[2])?(int)$m[2]:$low;
        return $low<= $high&&$high<=28;
    }
}
