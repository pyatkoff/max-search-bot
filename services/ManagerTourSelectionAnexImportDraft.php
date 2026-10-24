<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionDraft.php';

/** The observed agency result-row template only. No TO request or fare verification. */
final class ManagerTourSelectionAnexImportDraft
{
    public static function normalize(array $input): ?array
    {
        $url=$input['url']??null;
        if (!is_string($url) || strlen($url)>2048) return null;
        $p=parse_url($url);
        if (($p['scheme']??'')!=='https' || ($p['host']??'')!=='agent.anextour.ru' || ($p['path']??'')!=='/search/tour' || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || isset($p['port'])) return null;
        $required=['ADULT','CHILD','CHECKIN_BEG','CHECKIN_END','NIGHTMIN','NIGHTMAX','CURRENCY','STATEINC','TOWNFROM'];
        $query=[];
        foreach (explode('&',$p['query']??'') as $pair) {
            $parts=explode('=',$pair,2);
            if (count($parts)!==2) return null;
            [$key,$value]=array_map('rawurldecode',$parts);
            if (!in_array($key,$required,true) || isset($query[$key]) || !preg_match('/\A[a-z0-9]{1,12}\z/D',$value)) return null;
            $query[$key]=$value;
        }
        if (array_diff($required,array_keys($query)) || $query['ADULT']!=='2' || $query['CHILD']!=='0' || $query['CURRENCY']!=='1' || $query['STATEINC']!=='3' || $query['TOWNFROM']!=='moskva') return null;
        $quote=$input['quote_id']??null;
        if (!is_string($quote) || !preg_match('/\A0x[0-9a-fA-F]{268}\z/D',$quote)) return null;
        $keys=$input['keys']??null;
        if (!is_array($keys)) return null;
        foreach ($keys as $value) if (!is_string($value)) return null;
        if (array_diff(array_keys($keys),['hotel','country','departure','spo']) || count($keys)!==4 || ($keys['country']??null)!=='3' || ($keys['departure']??null)!=='moskva' || !preg_match('/\A[1-9][0-9]{0,11}\z/D',$keys['hotel']??'') || !preg_match('/\AAYT-[1-9][0-9]{0,9}\z/D',$keys['spo']??'')) return null;
        ksort($keys);
        $fields=$input['offer']??null;
        if (!is_array($fields) || array_diff(array_keys($fields),['hotel','destination','departure','date_from','nights','adults','child_ages','room','meal','amount','currency','stars'])) return null;
        $draft=ManagerTourSelectionDraft::normalize(['title'=>'Импорт','intro'=>'','offers'=>[array_merge($fields,[
            'id'=>str_repeat('0',32),'selected'=>true,'source_label'=>'ANEX · цена со страницы','source_url'=>$url,'observed_at'=>$input['observed_at']??null,
            'rationale'=>'','compromise'=>'','flights'=>'Рейсы, багаж и обратный перелёт не проверены.',
            'inclusions'=>'','constraints'=>'Цена выдачи. Полная стоимость, топливо и обязательные доплаты требуют проверки. Соответствие отеля каталогу не установлено.','photos'=>[],
        ])]]);
        if ($draft===null) return null;
        $offer=$draft['offers'][0];
        if ($offer['observed_at']==='' || $offer['adults']!==2 || $offer['child_ages']!==[] || $offer['currency']!=='RUB' || $offer['departure']!=='Москва' || $offer['stars']===null) return null;
        foreach (['hotel','destination','date_from','nights','room','meal','amount'] as $key) if ($offer[$key]===null || $offer[$key]==='') return null;
        $raw=$input['raw']??null;
        $rawKeys=['hotel','resort','date','nights','room','placement','meal','price','party','tariff','departure','country'];
        if (!is_array($raw) || array_diff(array_keys($raw),$rawKeys) || array_diff($rawKeys,array_keys($raw))) return null;
        foreach ($raw as $value) if (!is_string($value) || trim($value)==='' || strlen($value)>1200 || preg_match('//u',$value)!==1 || preg_match('/[\x00-\x1f\x7f]/',$value)) return null;
        $months=['янв.'=>'01','февр.'=>'02','мар.'=>'03','апр.'=>'04','мая'=>'05','июн.'=>'06','июл.'=>'07','авг.'=>'08','сент.'=>'09','окт.'=>'10','нояб.'=>'11','дек.'=>'12'];
        if (!preg_match('/\A([1-9]|[12][0-9]|3[01]) ([а-я.]+) ([0-9]{4}) (пн|вт|ср|чт|пт|сб|вс)\z/uD',$raw['date'],$m) || !isset($months[$m[2]])) return null;
        $iso=$m[3].'-'.$months[$m[2]].'-'.str_pad($m[1],2,'0',STR_PAD_LEFT);
        if ($iso!==$offer['date_from'] || $raw['hotel']!==$offer['hotel'].$offer['stars'].' *' || $raw['country']!=='Турция' || $raw['departure']!=='Москва' || $offer['destination']!=='Турция, '.$raw['resort'] || $raw['nights']!==(string)$offer['nights'] || $raw['placement']!=='DBL' || $raw['party']!=='2 взрослых' || $offer['room']!==$raw['room'].' / DBL' || $offer['meal']!==$raw['meal'] || !in_array($raw['meal'],['RO','BB','HB','FB','AI','UAI'],true)) return null;
        if (preg_replace('/[\s\x{00a0}\x{202f}]/u','',$raw['price'])!==$offer['amount'].'₽') return null;
        foreach (['CHECKIN_BEG','CHECKIN_END'] as $key) if (!preg_match('/\A([0-9]{4})([0-9]{2})([0-9]{2})\z/D',$query[$key],$d) || !checkdate((int)$d[2],(int)$d[3],(int)$d[1])) return null;
        if ($query['CHECKIN_BEG']!==$query['CHECKIN_END']) return null;
        $date=str_replace('-','',$iso);
        if ($date<$query['CHECKIN_BEG'] || $date>$query['CHECKIN_END']) return null;
        foreach (['NIGHTMIN','NIGHTMAX'] as $key) if (!preg_match('/\A[1-9][0-9]?\z/D',$query[$key]) || (int)$query[$key]>60) return null;
        if ($offer['nights']<(int)$query['NIGHTMIN'] || $offer['nights']>(int)$query['NIGHTMAX']) return null;
        ksort($raw);
        return ['adapter'=>'anex-agent-v1','url'=>$url,'observed_at'=>$offer['observed_at'],'quote_id'=>strtolower($quote),'keys'=>$keys,'offer'=>$offer,'raw'=>$raw];
    }
}
