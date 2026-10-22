<?php
declare(strict_types=1);

/** Editable input only. It neither verifies a fare nor prepares a customer send. */
final class ManagerTourSelectionDraft
{
    public const MAX_OFFERS = 20;

    public static function empty(): array
    {
        return ['title'=>'Подборка туров', 'intro'=>'', 'offers'=>[]];
    }

    public static function normalize($input): ?array
    {
        if (!is_array($input) || array_diff(array_keys($input), ['title','intro','offers'])) return null;
        $title=self::text($input['title']??'',120); $intro=self::text($input['intro']??'',1200,true);
        $offers=$input['offers']??null;
        if ($title===null || $intro===null || !is_array($offers) || !array_is_list($offers) || count($offers)>self::MAX_OFFERS) return null;
        $result=['title'=>$title,'intro'=>$intro,'offers'=>[]]; $ids=[];
        foreach ($offers as $offer) {
            $item=self::offer($offer);
            if ($item===null || isset($ids[$item['id']])) return null;
            $ids[$item['id']]=true; $result['offers'][]=$item;
        }
        return $result;
    }

    private static function offer($input): ?array
    {
        if (!is_array($input) || !is_string($input['id']??null) || !preg_match('/\A[a-f0-9]{32}\z/D',$input['id']) || !is_bool($input['selected']??null)) return null;
        $out=['id'=>$input['id'],'selected'=>$input['selected']];
        foreach (['hotel'=>191,'destination'=>160,'departure'=>160,'room'=>300,'meal'=>160,'source_label'=>160] as $key=>$length) {
            $text=self::text($input[$key]??'', $length); if ($text===null) return null; $out[$key]=$text;
        }
        foreach (['rationale'=>600,'compromise'=>600,'flights'=>1200,'inclusions'=>600,'constraints'=>1200] as $key=>$length) {
            $text=self::text($input[$key]??'', $length,true); if ($text===null) return null; $out[$key]=$text;
        }
        foreach (['stars'=>5,'nights'=>60,'adults'=>20] as $key=>$max) {
            $value=$input[$key]??null;
            if ($value!==null && (!is_int($value) || $value<($key==='stars'?0:1) || $value>$max)) return null;
            $out[$key]=$value;
        }
        $date=$input['date_from']??'';
        if (!is_string($date) || ($date!=='' && (!preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D',$date,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])))) return null;
        $out['date_from']=$date;
        $ages=$input['child_ages']??[];
        if (!is_array($ages) || !array_is_list($ages) || count($ages)>12) return null;
        foreach ($ages as $age) if (!is_int($age) || $age<0 || $age>17) return null;
        $out['child_ages']=$ages;
        $amount=$input['amount']??'';
        if (!is_string($amount) || ($amount!=='' && (!preg_match('/\A(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?\z/D',$amount) || (float)$amount<=0))) return null;
        if (!in_array($input['currency']??'RUB',['RUB','EUR','USD'],true)) return null;
        $out['amount']=$amount; $out['currency']=$input['currency']??'RUB';
        $out['source_url']=self::url($input['source_url']??'');
        if ($out['source_url']===null) return null;
        $observed=$input['observed_at']??'';
        if (!is_string($observed) || ($observed!=='' && (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$observed) || gmdate('Y-m-d\TH:i:s\Z',strtotime($observed))!==$observed))) return null;
        $out['observed_at']=$observed;
        $photos=$input['photos']??[];
        if (!is_array($photos) || !array_is_list($photos) || count($photos)>3) return null;
        $out['photos']=[];
        foreach ($photos as $photo) {
            if (!is_array($photo) || array_diff(array_keys($photo),['url','kind','illustrative'])) return null;
            $url=self::url($photo['url']??null);
            if ($url===null || $url==='' || !in_array($photo['kind']??null,['cover','beach','room'],true) || !is_bool($photo['illustrative']??null)) return null;
            $out['photos'][]=['url'=>$url,'kind'=>$photo['kind'],'illustrative'=>$photo['illustrative']];
        }
        // Do not silently accept a forged verification/identity/publication flag.
        if (array_diff(array_keys($input),array_keys($out))) return null;
        return $out;
    }

    private static function text($value,int $max,bool $multiline=false): ?string
    {
        if (!is_string($value) || preg_match('//u',$value)!==1) return null;
        $value=trim(str_replace(["\r\n","\r"],"\n",$value));
        if (preg_match($multiline?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u':'/[\x00-\x1f\x7f]/u',$value) || preg_match_all('/./us',$value)>$max) return null;
        return $value;
    }

    private static function url($value): ?string
    {
        if ($value==='') return '';
        if (!is_string($value) || strlen($value)>2048 || preg_match('/[\x00-\x20\x7f]/',$value)) return null;
        $p=parse_url($value);
        if (!$p || ($p['scheme']??'')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) return null;
        $host=strtolower($p['host']);
        if (!str_contains($host,'.') || filter_var($host,FILTER_VALIDATE_IP) || !preg_match('/\A[a-z0-9.-]+\z/D',$host) || str_ends_with($host,'.local') || str_ends_with($host,'.localhost')) return null;
        if (isset($p['query']) && preg_match('/(?:\A|&)(?:[^=&]*(?:token|password|secret|api[_-]?key|authorization|session|jwt)[^=&]*)=/i',$p['query'])) return null;
        return $value;
    }
}
