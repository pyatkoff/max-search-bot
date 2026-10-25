<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionDraft.php';

/** Manager-observed TO fields, never verified fare, hotel match or flight evidence. */
final class ManagerTourSelectionImportDraft
{
    public static function normalize($input): ?array
    {
        if (!is_array($input) || array_diff(array_keys($input),['adapter','url','observed_at','quote_id','keys','offer','raw','photos'])) return null;
        if (($input['adapter']??null)==='anex-agent-v1') {
            require_once __DIR__.'/ManagerTourSelectionAnexImportDraft.php';
            $normalized=ManagerTourSelectionAnexImportDraft::normalize($input);
            return $normalized===null?null:self::withPhotos($input,$normalized);
        }
        if (($input['adapter']??null)==='tourvisor-snapshot-v1') {
            require_once __DIR__.'/ManagerTourSelectionTourvisorImportDraft.php';
            $normalized=ManagerTourSelectionTourvisorImportDraft::normalize($input);
            return $normalized===null?null:self::withPhotos($input,$normalized);
        }
        if (($input['adapter']??null)!=='funsun-b2b-v1') return null;
        $url=$input['url']??null;
        if (!is_string($url) || strlen($url)>2048) return null;
        $p=parse_url($url);
        if (($p['scheme']??'')!=='https' || ($p['host']??'')!=='b2b.fstravel.com' || ($p['path']??'')!=='/search_tour' || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || isset($p['port'])) return null;
        parse_str($p['query']??'',$query);
        $allowed=['ADULT','CHILD','AGES','AGE1','AGE2','AGE3','CHECKIN_BEG','CHECKIN_END','NIGHTS_FROM','NIGHTS_TILL','STATEINC','TOWNFROMINC','CURRENCY','PROGRAMINC','TOURINC','HOTELS','HOTELS_ANY','MEALS_ANY','ROOMS_ANY','STARS_ANY','TOWNS_ANY','FREIGHT','FREIGHTTYPE','PARTITION_PRICE','DOLOAD','FILTER','PRICEPAGE','LANG'];
        foreach ($query as $key=>$value) if (!in_array($key,$allowed,true) || !is_string($value) || strlen($value)>200 || !preg_match('/\A[0-9a-zA-Z_,. -]*\z/D',$value)) return null;
        $quote=$input['quote_id']??null;
        if (!is_string($quote) || !preg_match('/\A0x[0-9a-fA-F]{32,1024}\z/D',$quote)) return null;
        $keys=$input['keys']??null;
        $required=['hotel','room','meal','placement','tour','program','spo','departure','country'];
        if (!is_array($keys) || array_diff(array_keys($keys),$required) || array_diff($required,array_keys($keys))) return null;
        foreach ($keys as $v) if (!is_string($v) || !preg_match('/\A[1-9][0-9]{0,11}\z/D',$v)) return null;
        ksort($keys);
        $offer=$input['offer']??null;
        if (!is_array($offer) || array_diff(array_keys($offer),['hotel','destination','departure','date_from','nights','adults','child_ages','room','meal','amount','currency','stars'])) return null;
        $draft=ManagerTourSelectionDraft::normalize(['title'=>'Импорт','intro'=>'','offers'=>[array_merge($offer,[
            'id'=>str_repeat('0',32),'selected'=>true,'source_label'=>'FUN&SUN · цена со страницы','source_url'=>$url,'observed_at'=>$input['observed_at']??null,
            'rationale'=>'','compromise'=>'','flights'=>'Выбранные рейсы, багаж и обратный перелёт не проверены.',
            'inclusions'=>'','constraints'=>'Полная цена, топливо и обязательные доплаты требуют проверки. Соответствие отеля каталогу не установлено.','photos'=>[],
        ])]]);
        if ($draft===null || $draft['offers'][0]['observed_at']==='') return null;
        $offer=$draft['offers'][0];
        foreach (['hotel','departure','destination','date_from','nights','adults','room','meal','amount'] as $k) if ($offer[$k]===null || $offer[$k]==='') return null;
        $raw=$input['raw']??null;
        if (!is_array($raw) || array_diff(array_keys($raw),['hotel','date','nights','room','meal','price','party','tour','flight_alternatives','tariff'])) return null;
        foreach (['hotel','date','nights','room','meal','price','party'] as $k) if (!isset($raw[$k]) || !is_string($raw[$k]) || trim($raw[$k])==='') return null;
        foreach ($raw as $v) if (!is_string($v) || strlen($v)>1200 || preg_match('//u',$v)!==1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v)) return null;
        ksort($raw);
        // The captured price is separately checked against the amount, never an old/CSS amount.
        $display=preg_replace('/[\s\x{00a0}\x{202f}]/u','',$raw['price']);
        if ($display!==$offer['amount'].$offer['currency']) return null;
        $ages=$offer['child_ages'];
        $party=$offer['adults'].'AD'.($ages?' + '.count($ages).'CH ('.implode(', ',$ages).')':'');
        $hotel=$offer['hotel'].($offer['stars']===null?'':' '.$offer['stars'].'*');
        if ($raw['hotel']!==$hotel || $raw['room']!==$offer['room'] || $raw['meal']!==$offer['meal'] || $raw['nights']!==(string)$offer['nights'] || $raw['party']!==$party) return null;
        $day=substr($offer['date_from'],8,2).'.'.substr($offer['date_from'],5,2).'.'.substr($offer['date_from'],0,4);
        if (!preg_match('/\A'.preg_quote($day,'/').'(?:, (?:пн|вт|ср|чт|пт|сб|вс))?\z/uD',$raw['date'])) return null;
        foreach (['ADULT'=>(string)$offer['adults'],'CHILD'=>(string)count($ages),'STATEINC'=>$keys['country'],'TOWNFROMINC'=>$keys['departure']] as $key=>$value) if (($query[$key]??null)!==$value) return null;
        if (!preg_match('/\A[1-9][0-9]{0,11}\z/D',$query['CURRENCY']??'') || !preg_match('/\A(?:0|[1-9][0-9]{0,11})\z/D',$query['PROGRAMINC']??'')) return null;
        foreach (['CHECKIN_BEG','CHECKIN_END'] as $key) {
            $value=$query[$key]??'';
            if (!preg_match('/\A([0-9]{4})([0-9]{2})([0-9]{2})\z/D',$value,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) return null;
        }
        $dateKey=str_replace('-','',$offer['date_from']);
        if ($dateKey<$query['CHECKIN_BEG'] || $dateKey>$query['CHECKIN_END']) return null;
        foreach (['NIGHTS_FROM','NIGHTS_TILL'] as $key) if (!preg_match('/\A[1-9][0-9]?\z/D',$query[$key]??'') || (int)$query[$key]>60) return null;
        if ($offer['nights']<(int)$query['NIGHTS_FROM'] || $offer['nights']>(int)$query['NIGHTS_TILL']) return null;
        foreach ($ages as $i=>$age) if (($query['AGE'.($i+1)]??null)!==(string)$age) return null;
        return self::withPhotos($input,['adapter'=>'funsun-b2b-v1','url'=>$url,'observed_at'=>$offer['observed_at'],'quote_id'=>strtolower($quote),'keys'=>$keys,'offer'=>$offer,'raw'=>$raw]);
    }

    /** Source gallery material is separate from quote identity and verified facts. */
    private static function withPhotos(array $input,array $normalized): ?array
    {
        if (!array_key_exists('photos',$input)) return $normalized;
        $photos=$input['photos'];
        if (!is_array($photos) || !array_is_list($photos) || count($photos)>3 || count(array_unique($photos,SORT_REGULAR))!==count($photos)) return null;
        $group=null;
        foreach ($photos as $url) {
            if (!is_string($url)) return null;
            $p=parse_url($url);
            if (!$p || ($p['scheme']??'')!=='https' || isset($p['port']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])) return null;
            if ($normalized['adapter']==='funsun-b2b-v1') {
                if (!preg_match('~\Ahttps://apigate-tui\.fstravel\.com/api/geocontent/static/Hotel/([a-f0-9-]{36})/(?:MainPhoto|Gallery|InsideOfTheRoom)-Source-[a-z0-9]+\.(?:jpg|jpeg|png)\z~iD',$url,$m)) return null;
                if ($group!==null && $group!==$m[1]) return null;
                $group=$m[1];
            } elseif ($normalized['adapter']==='anex-agent-v1') {
                if (($p['host']??'')!=='files.anextour.ru' || !preg_match('~\A/hotel/hotels/[a-z0-9_/-]+/o[1-9][0-9]*\z~D',$p['path']??'')) return null;
                // The observed CDN explicitly binds each image to its source hotel.
                if (!preg_match('/\A&?hotelCode='.preg_quote($normalized['keys']['hotel'],'/').'\z/D',$p['query']??'')) return null;
            } else {
                $path='~\A/hotel_pics/verybig/'.preg_quote($normalized['keys']['country'],'~').'/'.preg_quote($normalized['keys']['hotel'],'~').'-[1-9][0-9]*\.(?:jpg|jpeg|png)\z~iD';
                if (($p['host']??'')!=='static.tourvisor.ru' || !preg_match($path,$p['path']??'') || isset($p['query'])) return null;
            }
        }
        $offer=$normalized['offer'];$offer['photos']=[];
        foreach ($photos as $i=>$url) $offer['photos'][]=['url'=>$url,'kind'=>['cover','beach','room'][$i],'illustrative'=>true];
        $draft=ManagerTourSelectionDraft::normalize(['title'=>'Импорт','intro'=>'','offers'=>[$offer]]);
        if ($draft===null) return null;
        $normalized['offer']=$draft['offers'][0];$normalized['photos']=$photos;
        return $normalized;
    }

    public static function source(array $input): array
    {
        $fields=array_intersect_key($input['offer'],array_flip(['hotel','destination','departure','date_from','nights','adults','child_ages','room','meal','amount','currency','stars']));
        $source=['adapter'=>$input['adapter'],'url'=>$input['url'],'observed_at'=>$input['observed_at'],'quote_id'=>$input['quote_id'],'keys'=>$input['keys'],'fields'=>$fields,'raw'=>$input['raw']];
        if (isset($input['photos'])) $source['photos']=$input['photos'];
        return $source;
    }

    public static function fingerprint(array $source,bool $withPrice=true): string
    {
        $data=['keys'=>$source['keys'],'fields'=>$source['fields'],'raw_alternatives'=>$source['raw']['flight_alternatives']??'','tariff'=>$source['raw']['tariff']??''];
        if (($source['adapter']??null)==='tourvisor-snapshot-v1') {
            $data['price_basis']=$source['raw']['price_basis'];
            if ($withPrice) $data['shown_price_parts']=[$source['raw']['base_price'],$source['raw']['fuel_price']];
        }
        if (!$withPrice) unset($data['fields']['amount']);
        return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
}
