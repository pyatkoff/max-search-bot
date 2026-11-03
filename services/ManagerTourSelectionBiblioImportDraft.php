<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionDraft.php';

/** Frozen public Biblio quote. No booking, fare verification or room-photo claim. */
final class ManagerTourSelectionBiblioImportDraft
{
    public static function hotelUrl(string $hotel,string $country): string
    {
        return 'https://www.bgoperator.ru/price.shtml?action=shw&code='.$hotel.'&flt='.$country.'&tid=26';
    }

    public static function photoUrl($url): bool
    {
        return is_string($url) && preg_match('~\Ahttps://www\.bgoperator\.ru/pr_img/[1-9][0-9]{5,14}/[0-9]{8}/[1-9][0-9]{3,14}/[A-Za-z0-9_-]{1,100}\.(?:jpg|jpeg|png)\z~D',$url)===1;
    }

    public static function normalize(array $input): ?array
    {
        $keys=$input['keys']??null;
        $required=['hotel','country','departure','route','room','placement','group','components_hash'];
        if (!is_array($keys) || array_diff(array_keys($keys),$required) || array_diff($required,array_keys($keys))) return null;
        foreach ($keys as $key=>$value) {
            if (!is_string($value)) return null;
            if ($key==='components_hash') {if (!preg_match('/\A[a-f0-9]{64}\z/D',$value)) return null;}
            elseif ($key==='group') {if (!preg_match('/\A[0-4]\z/D',$value)) return null;}
            elseif ($key==='route' && $value==='-1') continue;
            elseif (!preg_match('/\A[1-9][0-9]{0,14}\z/D',$value)) return null;
        }
        if (($input['url']??null)!==self::hotelUrl($keys['hotel'],$keys['country']) || !is_string($input['quote_id']??null) || !preg_match('/\A[a-f0-9]{64}\z/D',$input['quote_id'])) return null;
        $raw=$input['raw']??null;
        $rawKeys=['hotel','date','end_date','stay_from','stay_until','nights','room','meal','price','party','transport','services','components','price_basis','price_note'];
        if (!is_array($raw) || array_diff(array_keys($raw),$rawKeys) || array_diff($rawKeys,array_keys($raw))) return null;
        foreach ($raw as $value) if (!is_string($value) || strlen($value)>1800 || preg_match('//u',$value)!==1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)) return null;
        if ($raw['price_basis']!=='party_confirmed') return null;
        $fields=$input['offer']??null;
        if (!is_array($fields) || array_diff(array_keys($fields),['hotel','destination','departure','date_from','nights','adults','child_ages','room','meal','amount','currency','stars'])) return null;
        $draft=ManagerTourSelectionDraft::normalize(['title'=>'Импорт','intro'=>'','offers'=>[array_merge($fields,[
            'id'=>str_repeat('0',32),'selected'=>true,'source_label'=>'Библио-Глобус · цена со страницы','source_url'=>$input['url'],'observed_at'=>$input['observed_at']??null,
            'rationale'=>'','compromise'=>'','flights'=>$raw['transport'],
            'inclusions'=>$raw['services'],
            'constraints'=>'Цена за указанный состав подтверждена менеджером при добавлении. Актуальность и наличие перед оформлением требуют проверки. '.$raw['price_note']."\n".'Проживание: '.$raw['stay_from'].' — '.$raw['stay_until'].'. Фото относятся к отелю; выбранная категория номера по ним не подтверждена.',
            'photos'=>[],
        ])]]);
        if ($draft===null) return null;
        $offer=$draft['offers'][0];
        foreach (['hotel','destination','departure','date_from','nights','adults','room','meal','amount','observed_at'] as $key) if ($offer[$key]===null || $offer[$key]==='') return null;
        if ($raw['date']!==$offer['date_from'] || $raw['nights']!==(string)$offer['nights'] || $raw['room']!==$offer['room'] || $raw['meal']!==$offer['meal'] || $raw['price']!==$offer['amount'].' '.$offer['currency']) return null;
        if ($raw['hotel']!==$offer['hotel'].($offer['stars']===null?'':' '.$offer['stars'].'*')) return null;
        $party=$offer['adults'].'AD'.($offer['child_ages']?' + '.count($offer['child_ages']).'CH ('.implode(', ',$offer['child_ages']).')':'');
        if ($raw['party']!==$party) return null;
        foreach (['end_date','stay_from','stay_until'] as $key) {
            if (!preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D',$raw[$key],$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) return null;
        }
        $from=new DateTimeImmutable($raw['stay_from'],new DateTimeZone('UTC'));
        $until=new DateTimeImmutable($raw['stay_until'],new DateTimeZone('UTC'));
        if ($from->diff($until)->format('%r%a')!==(string)$offer['nights'] || $raw['stay_from']<$offer['date_from'] || $raw['end_date']<$raw['stay_until']) return null;
        ksort($raw);ksort($keys);
        return ['adapter'=>'biblio-public-v1','url'=>$input['url'],'observed_at'=>$offer['observed_at'],'quote_id'=>$input['quote_id'],'keys'=>$keys,'offer'=>$offer,'raw'=>$raw];
    }
}
