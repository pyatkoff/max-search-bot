<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionDraft.php';

/** One observed TV card snapshot, never a stable provider quote or verified fare. */
final class ManagerTourSelectionTourvisorImportDraft
{
    public static function normalize(array $input): ?array
    {
        $url=$input['url']??null;
        if (!is_string($url) || strlen($url)>2048) return null;
        $p=parse_url($url);
        if (($p['scheme']??'')!=='https' || ($p['host']??'')!=='pro.tourvisor.ru' || ($p['path']??'')!=='/search' || isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['fragment'])) return null;
        $required=['ts_dosearch','s_form_mode','s_nights_from','s_nights_to','s_directflight','s_regular','x_hotel_codes','s_j_date_from','s_j_date_to','s_adults','s_meal','s_flyfrom','s_country'];$query=[];
        foreach (explode('&',$p['query']??'') as $pair) {
            $parts=explode('=',$pair,2);
            if (count($parts)!==2) return null;
            [$key,$value]=array_map('rawurldecode',$parts);
            if (!in_array($key,$required,true) || isset($query[$key]) || !preg_match('/\A[0-9.]{1,12}\z/D',$value)) return null;
            $query[$key]=$value;
        }
        if (array_diff($required,array_keys($query))) return null;
        foreach (['ts_dosearch'=>'1','s_form_mode'=>'0','s_directflight'=>'0','s_regular'=>'1','s_adults'=>'2','s_meal'=>'7','s_flyfrom'=>'1','s_country'=>'1'] as $key=>$value) if ($query[$key]!==$value) return null;
        $quote=$input['quote_id']??null;
        if (!is_string($quote) || !preg_match('/\Atv-snapshot-[1-9][0-9]{5,15}\z/D',$quote)) return null;
        $keys=$input['keys']??null;
        if (!is_array($keys) || count($keys)!==5 || array_diff(array_keys($keys),['hotel','meal','departure','country','operator'])) return null;
        foreach (['hotel'=>$query['x_hotel_codes'],'meal'=>'7','departure'=>'1','country'=>'1','operator'=>'13'] as $key=>$value) if (($keys[$key]??null)!==$value || !preg_match('/\A[1-9][0-9]{0,11}\z/D',$value)) return null;
        ksort($keys);
        $raw=$input['raw']??null;
        $rawKeys=['hotel','destination','date','nights','room','meal','party','price','price_basis','base_price','fuel_price','tariff','flight_alternatives','party_price_confirmation'];
        if (!is_array($raw) || array_diff(array_keys($raw),$rawKeys) || array_diff($rawKeys,array_keys($raw))) return null;
        foreach ($raw as $value) if (!is_string($value) || trim($value)==='' || strlen($value)>1200 || preg_match('//u',$value)!==1 || preg_match('/[\x00-\x1f\x7f]/',$value)) return null;
        if ($raw['price_basis']!=='за номер' || $raw['party_price_confirmation']!=='one_room_two_adults') return null;
        $fields=$input['offer']??null;
        if (!is_array($fields) || array_diff(array_keys($fields),['hotel','destination','departure','date_from','nights','adults','child_ages','room','meal','amount','currency','stars'])) return null;
        $draft=ManagerTourSelectionDraft::normalize(['title'=>'Импорт','intro'=>'','offers'=>[array_merge($fields,[
            'id'=>str_repeat('0',32),'selected'=>true,'source_label'=>'Tourvisor · снимок ANEX','source_url'=>$url,'observed_at'=>$input['observed_at']??null,
            'rationale'=>'','compromise'=>'','flights'=>'На странице: '.$raw['flight_alternatives'].'. Выбранные рейсы, багаж и обратный перелёт требуют проверки.',
            'inclusions'=>'','constraints'=>'Цена источника за номер. Менеджер подтвердил один номер на двух взрослых. Показанное топливо уже включено. Полная цена и обязательные доплаты не проверены. Соответствие отеля каталогу не установлено.','photos'=>[],
        ])]]);
        if ($draft===null) return null;
        $offer=$draft['offers'][0];
        if ($offer['observed_at']==='' || $offer['adults']!==2 || $offer['child_ages']!==[] || $offer['currency']!=='RUB' || $offer['departure']!=='Москва' || $offer['stars']===null) return null;
        foreach (['hotel','destination','date_from','nights','room','meal','amount'] as $key) if ($offer[$key]===null || $offer[$key]==='') return null;
        if ($raw['hotel']!==$offer['hotel'].' '.$offer['stars'].'*' || $raw['destination']!==$offer['destination'] || !str_starts_with($raw['destination'],'Египет, ') || $raw['room']!==$offer['room'] || $raw['meal']!==$offer['meal'] || $raw['meal']!=='AI - Все Включено' || $raw['party']!=='2 взрослых' || $raw['nights']!==$offer['nights'].' ночей') return null;
        $months=['января'=>'01','февраля'=>'02','марта'=>'03','апреля'=>'04','мая'=>'05','июня'=>'06','июля'=>'07','августа'=>'08','сентября'=>'09','октября'=>'10','ноября'=>'11','декабря'=>'12'];
        if (!preg_match('/\A([1-9]|[12][0-9]|3[01]) ([а-я]+)\z/uD',$raw['date'],$m) || !isset($months[$m[2]])) return null;
        foreach (['s_j_date_from','s_j_date_to'] as $key) if (!preg_match('/\A([0-9]{2})\.([0-9]{2})\.([0-9]{4})\z/D',$query[$key],$d) || !checkdate((int)$d[2],(int)$d[1],(int)$d[3])) return null;
        $iso=$d[3].'-'.$d[2].'-'.$d[1];
        if ($query['s_j_date_from']!==$query['s_j_date_to'] || $iso!==$offer['date_from'] || $d[2]!==$months[$m[2]] || (int)$d[1]!== (int)$m[1]) return null;
        if ($query['s_nights_from']!==(string)$offer['nights'] || $query['s_nights_to']!==(string)$offer['nights']) return null;
        foreach (['price','base_price','fuel_price'] as $key) {
            $value=preg_replace('/[\s\x{00a0}\x{202f}]/u','',$raw[$key]);
            if (!preg_match('/\A(0|[1-9][0-9]{0,8})РУБ\z/uD',$value,$price)) return null;
            $amounts[$key]=(int)$price[1];
        }
        if ((string)$amounts['price']!==$offer['amount'] || $amounts['price']<=0 || $amounts['base_price']<=0 || $amounts['base_price']+$amounts['fuel_price']!==$amounts['price']) return null;
        ksort($raw);
        return ['adapter'=>'tourvisor-snapshot-v1','url'=>$url,'observed_at'=>$offer['observed_at'],'quote_id'=>$quote,'keys'=>$keys,'offer'=>$offer,'raw'=>$raw];
    }
}
