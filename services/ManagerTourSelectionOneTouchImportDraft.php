<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionDraft.php';
require_once __DIR__.'/ManagerTourSelectionOneTouchSource.php';

/** Validated observation, not a firm fare, availability or exact-room claim. */
final class ManagerTourSelectionOneTouchImportDraft
{
    public static function normalize(array $input): ?array
    {
        try{$query=ManagerTourSelectionOneTouchSource::query($input['url']??'');}catch(Throwable $e){return null;}
        $keys=$input['keys']??null;$names=['hotel','room','meal','placement','tour','program','spo','departure','country'];
        if(!is_array($keys)||array_diff(array_keys($keys),$names)||array_diff($names,array_keys($keys)))return null;
        foreach($keys as $v)if(!is_string($v)||!preg_match('/\A[1-9][0-9]{0,11}\z/D',$v))return null;
        if($keys['country']!==$query['STATEINC']||$keys['departure']!==$query['TOWNFROMINC']||!is_string($input['quote_id']??null)||!preg_match('/\A0x[a-f0-9]{32,1024}\z/D',$input['quote_id']))return null;
        $fields=$input['offer']??null;$allowed=['hotel','destination','departure','date_from','nights','adults','child_ages','room','meal','amount','currency','stars','price_qualifier'];
        if(!is_array($fields)||array_diff(array_keys($fields),$allowed)||($fields['price_qualifier']??null)!=='from')return null;
        $raw=$input['raw']??null;$rawKeys=['hotel','date','nights','room','meal','price','party','tour','transport','tariff','base_price','base_currency','hotel_url','availability'];
        $captureKeys=['capture_mode','capture_reference','capture_price_declared_at','capture_price_received_at','capture_price_sha256','capture_gallery_observed_at','capture_gallery_sha256','capture_photo_sha256'];
        if(!is_array($raw)||array_diff(array_keys($raw),[...$rawKeys,...$captureKeys])||array_diff($rawKeys,array_keys($raw)))return null;
        foreach($raw as $s)if(!is_string($s)||strlen($s)>1200||preg_match('//u',$s)!==1||preg_match('/[\x00-\x1f\x7f]/',$s))return null;
        $captured=array_intersect($captureKeys,array_keys($raw));
        if($captured){
            if(count($captured)!==count($captureKeys)||$raw['capture_mode']!=='manager-file-v1'||!preg_match('/\A[A-Za-z0-9_-]{8,96}\z/D',$raw['capture_reference']))return null;
            foreach(['capture_price_declared_at','capture_price_received_at','capture_gallery_observed_at'] as $field)if(!is_string($raw[$field])||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$raw[$field])||gmdate('Y-m-d\TH:i:s\Z',strtotime($raw[$field]))!==$raw[$field])return null;
            foreach(['capture_price_sha256','capture_gallery_sha256'] as $field)if(!is_string($raw[$field])||!preg_match('/\A[a-f0-9]{64}\z/D',$raw[$field]))return null;
            $hashes=is_string($raw['capture_photo_sha256'])?json_decode($raw['capture_photo_sha256'],true):null;
            if(!is_array($hashes)||count($hashes)<1||count($hashes)>3)return null;
            foreach($hashes as $photo=>$hash)if(!self::photoUrl((string)$photo)||!is_string($hash)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash))return null;
            if($raw['capture_price_declared_at']!==($input['observed_at']??null))return null;
        }
        foreach($raw as $s)if(!is_string($s)||strlen($s)>1200||preg_match('//u',$s)!==1||preg_match('/[\x00-\x1f\x7f]/',$s))return null;
        if(ManagerTourSelectionOneTouchSource::hotelUrl($raw['hotel_url'],$keys['hotel'])!==$raw['hotel_url']||$raw['base_currency']!=='EUR'||!preg_match('/\A[1-9][0-9]{0,8}(?:\.[0-9]{1,2})?\z/D',$raw['base_price']))return null;
        $captureNote=$captured?' Данные из файла менеджера. Время цены в файле: '.gmdate('d.m.Y H:i:s',strtotime($raw['capture_price_declared_at'])).' UTC. Время галереи в файле: '.gmdate('d.m.Y H:i:s',strtotime($raw['capture_gallery_observed_at'])).' UTC. Актуальность цены и наличие требуют проверки.':'';
        $offer=$fields+['id'=>str_repeat('0',32),'selected'=>true,'source_label'=>$captured?'OneTouch · файл менеджера · цена от':'OneTouch · минимальная цена со страницы','source_url'=>$input['url'],'observed_at'=>$input['observed_at']??null,'rationale'=>'','compromise'=>'','flights'=>$raw['transport'].'; выбранные рейсы, багаж и обратный перелёт требуют проверки.','inclusions'=>'','constraints'=>'Исходная цена оператора — минимальная «от» за указанный состав, не окончательная стоимость бронирования. '.$raw['availability'].' Фото относятся к отелю; выбранная категория номера по ним не подтверждена.'.$captureNote,'photos'=>[]];
        $draft=ManagerTourSelectionDraft::normalize(['title'=>'Импорт','intro'=>'','offers'=>[$offer]]);if($draft===null)return null;$offer=$draft['offers'][0];
        foreach(['hotel','destination','departure','date_from','nights','adults','room','meal','amount','observed_at'] as $k)if($offer[$k]===null||$offer[$k]==='')return null;
        if($offer['adults']!==2||$offer['child_ages']!==[]||$offer['currency']!=='RUB'||str_replace('-','',$offer['date_from'])!==$query['CHECKIN_BEG']||(string)$offer['nights']!==$query['NIGHTS_FROM'])return null;
        foreach(['hotel'=>$offer['hotel'].' '.$offer['stars'].'*','date'=>$offer['date_from'],'nights'=>(string)$offer['nights'],'room'=>$offer['room'],'meal'=>$offer['meal'],'price'=>'от '.$offer['amount'].' RUB','party'=>'2AD'] as $k=>$v)if($raw[$k]!==$v)return null;
        ksort($keys);ksort($raw);
        return ['adapter'=>'onetouch-public-v1','url'=>$input['url'],'observed_at'=>$offer['observed_at'],'quote_id'=>$input['quote_id'],'keys'=>$keys,'offer'=>$offer,'raw'=>$raw];
    }

    public static function photoUrl(string $url): bool
    {
        return preg_match('~\Ahttps://image\.onetouch\.travel/images/hotels_hash/[1-9][0-9]{0,5}/[a-z0-9]{1,40}/[a-f0-9]{16,64}\.jpg\z~D',$url)===1;
    }
}
