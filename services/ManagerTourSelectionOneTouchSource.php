<?php
declare(strict_types=1);

/** Pure decoder of the observed public OneTouch PRICES and linked hotel pages.
 * No HTTP, JavaScript execution, booking, credentials or selection mutations.
 * The authenticated intake owner must obtain fresh form/query bindings first.
 */
final class ManagerTourSelectionOneTouchSource
{
    public const MAX_BYTES=2097152;
    private const QUERY=['samo_action','TOWNFROMINC','STATEINC','TOURTYPE','TOURINC','PROGRAMGROUPINC','PROGRAMINC','CHECKIN_BEG','NIGHTS_FROM','CHECKIN_END','NIGHTS_TILL','ADULT','CURRENCY','CHILD','TOWNS_ANY','townssearch','TOWNS','STARS_ANY','STARS','HOTELS_ANY','hotelsearch','HOTELS','MEALS_ANY','MEALS','ROOMS_ANY','ROOMS','CHILD_IN_BED','FREIGHT','COMFORTABLE_SEATS','FILTER','MOMENT_CONFIRM','WITHOUT_PROMO','HOTELTYPES','PARTITION_PRICE','PRICEPAGE','DYN_SEPARATE','rev'];

    public static function query(string $url): array
    {
        $p=parse_url($url);
        if(strlen($url)>4096 || preg_match('/[\x00-\x20\x7f]/',$url) || !$p || ($p['scheme']??'')!=='https' || ($p['host']??'')!=='b2b.onetouch.travel' || ($p['path']??'')!=='/search_tour' || array_intersect(['user','pass','port','fragment'],array_keys($p))) self::fail();
        $q=[];
        foreach(explode('&',$p['query']??'') as $pair){$pair=explode('=',$pair,2);if(count($pair)!==2)self::fail();[$k,$v]=array_map('rawurldecode',$pair);if(array_key_exists($k,$q)||!in_array($k,self::QUERY,true)||!preg_match('/\A[0-9,]*\z/D',$v)&&!($k==='samo_action'&&$v==='PRICES'))self::fail();$q[$k]=$v;}
        if(array_diff(self::QUERY,array_keys($q)))self::fail();
        foreach(['TOURTYPE'=>'0','TOURINC'=>'0','PROGRAMGROUPINC'=>'0','PROGRAMINC'=>'0','TOWNS_ANY'=>'1','townssearch'=>'0','TOWNS'=>'','STARS_ANY'=>'1','STARS'=>'','HOTELS_ANY'=>'1','hotelsearch'=>'0','HOTELS'=>'','MEALS_ANY'=>'1','MEALS'=>'','ROOMS_ANY'=>'1','ROOMS'=>'','CHILD_IN_BED'=>'0','FREIGHT'=>'1','COMFORTABLE_SEATS'=>'0','FILTER'=>'1','MOMENT_CONFIRM'=>'0','WITHOUT_PROMO'=>'0','HOTELTYPES'=>'','PARTITION_PRICE'=>''] as $k=>$v)if($q[$k]!==$v)self::fail();
        // Only the captured synchronous, first-page, child-free contract is supported.
        foreach(['samo_action'=>'PRICES','TOWNFROMINC'=>'2','STATEINC'=>'9','ADULT'=>'2','CHILD'=>'0','CURRENCY'=>'1','PRICEPAGE'=>'1','DYN_SEPARATE'=>'1'] as $k=>$v)if(($q[$k]??null)!==$v)self::fail();
        foreach(['CHECKIN_BEG','CHECKIN_END'] as $k)self::date($q[$k]??'');
        if($q['CHECKIN_BEG']!==$q['CHECKIN_END']||($q['NIGHTS_FROM']??null)!==($q['NIGHTS_TILL']??null)||!preg_match('/\A[1-9][0-9]?\z/D',$q['NIGHTS_FROM']??'')||(int)$q['NIGHTS_FROM']>60||!preg_match('/\A[0-9]{1,12}\z/D',$q['rev']??''))self::fail();
        return $q;
    }

    /** Decode one exact observed assignment as a JSON string; never execute JS. */
    public static function resultHtml(string $response): string
    {
        if(strlen($response)>self::MAX_BYTES||preg_match('//u',$response)!==1)self::fail();
        $needle='samo.jQuery(samo.controls.resultset).ehtml(';
        if(substr_count($response,$needle)!==1)self::fail();
        $tail=substr($response,strpos($response,$needle)+strlen($needle));
        if(($tail[0]??'')!=='"')self::fail();$escaped=false;$end=null;
        for($i=1,$n=strlen($tail);$i<$n;$i++){
            if($escaped){$escaped=false;continue;}
            if($tail[$i]==='\\'){$escaped=true;continue;}
            if($tail[$i]==='"'){$end=$i;break;}
        }
        if($end===null||substr($tail,$end+1,2)!==');')self::fail();
        try{$html=json_decode(substr($tail,0,$end+1),true,2,JSON_THROW_ON_ERROR);}catch(JsonException $e){self::fail();}
        if(!is_string($html)||$html===''||strlen($html)>self::MAX_BYTES)self::fail();
        return $html;
    }

    public static function parse(string $response,string $url,string $form,string $observed): array
    {
        $q=self::query($url);$fx=self::document($form);
        $labels=[];
        foreach(['TOWNFROMINC','STATEINC','CURRENCY'] as $key){
            $nodes=self::nodes($fx,'//select[@name="'.$key.'"]/option[@value="'.$q[$key].'" and @selected]');
            if(count($nodes)!==1)self::fail();$labels[$key]=self::text($nodes[0]->textContent);
        }
        if($labels['CURRENCY']!=='RUB'||$labels['TOWNFROMINC']===''||$labels['STATEINC']==='')self::fail();
        $x=self::document(self::resultHtml($response));$rows=self::nodes($x,'//tr['.self::cls('price_info').']');
        if(!$rows||count($rows)>250)self::fail();$out=[];$claims=[];
        foreach($rows as $row){
            foreach(['townfrom'=>'TOWNFROMINC','state'=>'STATEINC','checkin'=>'CHECKIN_BEG','nights'=>'NIGHTS_FROM','hnights'=>'NIGHTS_FROM'] as $attr=>$key)if($row->getAttribute('data-'.$attr)!==$q[$key])self::fail();
            foreach(['adult'=>'2','child'=>'0','checkIn'=>$q['CHECKIN_BEG'],'nights'=>$q['NIGHTS_FROM'],'hnights'=>$q['NIGHTS_FROM']] as $key=>$v)if(!in_array($key.'-'.$v,preg_split('/\s+/',trim($row->getAttribute('class'))),true))self::fail();
            // Rows without the observed native hotel detail link are not supported.
            $links=self::nodes($x,'./td['.self::cls('link-hotel').']/a[@href]',$row);
            if(!$links)continue;if(count($links)!==1)self::fail();$a=$links[0];$hotel=$row->getAttribute('data-hotel');
            // Some observed rows use a DIFFERENT catalog ID. Without an accepted
            // mapping those rows are unavailable; never guess the hotel identity.
            $hotelUrl=self::hotelUrl($a->getAttribute('href'),$hotel);if($hotelUrl===null)continue;
            if(!preg_match('/\A(.+?)\s+([1-5])\*\z/uD',self::text($a->textContent),$hm))continue;
            $keys=[];
            foreach(['hotel'=>'hotel','room'=>'room','meal'=>'meal','placement'=>'htplace','tour'=>'tour','program'=>'ptype'] as $key=>$attr){$v=$row->getAttribute('data-'.$attr);if(!preg_match('/\A[1-9][0-9]{0,11}\z/D',$v))self::fail();$keys[$key]=$v;}
            foreach(['hotelKey'=>'hotel','roomKey'=>'room','mealKey'=>'meal','htPlaceKey'=>'placement','tourKey'=>'tour','programTypeKey'=>'program'] as $class=>$key)if(!in_array($class.'-'.$keys[$key],preg_split('/\s+/',trim($row->getAttribute('class'))),true))self::fail();
            if(!preg_match('/(?:\A|\s)spoKey-([1-9][0-9]{0,11})(?:\s|\z)/',$row->getAttribute('class'),$spo))self::fail();
            $keys+=['spo'=>$spo[1],'departure'=>$q['TOWNFROMINC'],'country'=>$q['STATEINC']];
            $claim=strtolower($row->getAttribute('data-cat-claim'));
            if(!preg_match('/\A0x[a-f0-9]{32,1024}\z/D',$claim)||isset($claims[$claim]))self::fail();$claims[$claim]=true;
            $price=self::one($x,'./td['.self::cls('td_price').']/span['.self::cls('price').' and '.self::cls('bron').']',$row);
            $amount=$price->getAttribute('data-converted-price-number');$base=$price->getAttribute('data-cat-price');
            if(!self::amount($amount)||!self::amount($base)||$price->getAttribute('data-head')!=='от'||$price->getAttribute('data-currency')!=='1'||$price->getAttribute('data-currency_title')!=='RUB'||$price->getAttribute('data-cat-currency')!=='3'||preg_replace('/\s/u','',self::text($price->textContent))!==$amount.'RUB')self::fail();
            $room=self::text(self::one($x,'./td['.self::cls('hotel-room').']',$row)->textContent);$meal=self::text(self::one($x,'./td['.self::cls('hotel-meals').']',$row)->textContent);
            $date=self::date($q['CHECKIN_BEG']);$shownDate=self::one($x,'./td['.self::cls('arrival').']//div[@data-date]',$row)->getAttribute('data-date');
            if($shownDate!==substr($date,8,2).'.'.substr($date,5,2).'.'.substr($date,0,4)||self::text(self::one($x,'./td['.self::cls('tour-nights').']',$row)->textContent)!==$q['NIGHTS_FROM'])self::fail();
            $tour=self::text(self::one($x,'./td['.self::cls('tour').']/a',$row)->textContent);
            $resort=self::text(self::one($x,'./td['.self::cls('link-hotel').']/span['.self::cls('text-kurort').']',$row)->textContent);
            if(str_starts_with($resort,'(')&&str_ends_with($resort,')'))$resort=substr($resort,1,-1);
            $transport=self::text(self::one($x,'./td['.self::cls('transport').']//span['.self::cls('name').']',$row)->textContent);
            $tariff=self::text(self::one($x,'./td['.self::cls('type_price').']/span',$row)->textContent);
            $seats=self::nodes($x,'./td['.self::cls('hotel-seats').']//span['.self::cls('hotel_availability').' and @title]',$row);
            if(count($seats)!==4)self::fail();$availability=[];
            foreach($seats as $seat){$label=self::text($seat->getAttribute('title'));if($label==='')self::fail();$availability[]=$label;}
            $availability='Отель у источника: '.implode(', ',array_values(array_unique($availability))).'. Наличие и окончательная стоимость требуют проверки.';
            $offer=['hotel'=>$hm[1],'stars'=>(int)$hm[2],'destination'=>$labels['STATEINC'].' · '.$resort,'departure'=>$labels['TOWNFROMINC'],'date_from'=>$date,'nights'=>(int)$q['NIGHTS_FROM'],'adults'=>2,'child_ages'=>[],'room'=>$room,'meal'=>$meal,'amount'=>$amount,'currency'=>'RUB','price_qualifier'=>'from'];
            $raw=['hotel'=>$hm[1].' '.$hm[2].'*','date'=>$date,'nights'=>$q['NIGHTS_FROM'],'room'=>$room,'meal'=>$meal,'price'=>'от '.$amount.' RUB','party'=>'2AD','tour'=>$tour,'transport'=>$transport,'tariff'=>$tariff,'base_price'=>$base,'base_currency'=>'EUR','hotel_url'=>$hotelUrl,'availability'=>$availability];
            $observation=['adapter'=>'onetouch-public-v1','url'=>$url,'observed_at'=>$observed,'quote_id'=>$claim,'keys'=>$keys,'offer'=>$offer,'raw'=>$raw];
            require_once __DIR__.'/ManagerTourSelectionOneTouchImportDraft.php';
            if(ManagerTourSelectionOneTouchImportDraft::normalize($observation)===null)self::fail();
            $out[]=$observation;
        }
        if(!$out)self::fail();return $out;
    }

    public static function hotelUrl(string $url,string $hotel): ?string
    {
        if(!preg_match('/\A[1-9][0-9]{0,11}\z/D',$hotel)||!preg_match('~\Ahttps://onetouch\.travel/hotels/[a-z0-9]+(?:-[a-z0-9]+)*-'.preg_quote($hotel,'~').'/?\z~D',$url))return null;
        return rtrim($url,'/').'/';
    }

    /** Quote-to-hotel identity is checked before native gallery literals are read. */
    public static function gallery(string $html,array $quote): array
    {
        $url=self::hotelUrl($quote['raw']['hotel_url']??'',$quote['keys']['hotel']??'');if($url===null)self::fail();
        $x=self::document($html);$h1=self::one($x,'//h1');$star=self::one($x,'.//span['.self::cls('cmn-star-after').']',$h1);
        $stars=self::text($star->textContent);$copy=$h1->cloneNode(true);foreach(iterator_to_array($copy->childNodes) as $child)if($child instanceof DOMElement)$copy->removeChild($child);
        if(self::text($copy->textContent)!==($quote['offer']['hotel']??null)||$stars!==(string)($quote['offer']['stars']??''))self::fail();
        $jsonld=self::one($x,'//script[@type="application/ld+json"]');
        try{$hotel=json_decode($jsonld->textContent,true,16,JSON_THROW_ON_ERROR);}catch(JsonException $e){self::fail();}
        if(($hotel['@type']??null)!=='Hotel'||($hotel['name']??null)!==$quote['offer']['hotel']||(string)($hotel['starRating']['ratingValue']??'')!==$stars)self::fail();
        $component=self::one($x,'//photo-block');$params=$component->getAttribute(':params');
        if(!preg_match('/\bcount:\s*"([1-9][0-9]{0,2})"/',$params,$count)||!preg_match_all('~\{\s*image:"([a-f0-9]{16,64}\.jpg)",\s*image_url:"(https://image\.onetouch\.travel/images/hotels_hash/[1-9][0-9]{0,5}/[a-z0-9]{1,40}/)",\s*\}~',$params,$pairs,PREG_SET_ORDER))self::fail();
        $photos=[];$group=null;
        foreach($pairs as $pair){if($group!==null&&$group!==$pair[2])self::fail();$group=$pair[2];$photos[]=$pair[2].$pair[1];}
        $photos=array_values(array_unique($photos));
        if(count($photos)!==(int)$count[1]||($hotel['image']??null)!==$photos[0])self::fail();
        return array_slice($photos,0,3);
    }

    private static function amount(string $value): bool{return preg_match('/\A[1-9][0-9]{0,8}(?:\.[0-9]{1,2})?\z/D',$value)===1;}
    private static function date(string $s): string{if(!preg_match('/\A([0-9]{4})([0-9]{2})([0-9]{2})\z/D',$s,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))self::fail();return $m[1].'-'.$m[2].'-'.$m[3];}
    private static function document(string $html): DOMXPath{if($html===''||strlen($html)>self::MAX_BYTES||preg_match('//u',$html)!==1||stripos($html,'<!ENTITY')!==false)self::fail();$old=libxml_use_internal_errors(true);try{$d=new DOMDocument();$d->resolveExternals=false;$d->substituteEntities=false;if(!$d->loadHTML('<?xml encoding="utf-8"?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))self::fail();return new DOMXPath($d);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}}
    private static function cls(string $name): string{return 'contains(concat(" ",normalize-space(@class)," ")," '.$name.' ")';}
    private static function nodes(DOMXPath $x,string $path,?DOMNode $n=null): array{return iterator_to_array($x->query($path,$n));}
    private static function one(DOMXPath $x,string $path,?DOMNode $n=null): DOMElement{$a=self::nodes($x,$path,$n);if(count($a)!==1||!$a[0] instanceof DOMElement)self::fail();return $a[0];}
    private static function text(string $s): string{return trim(preg_replace('/\s+/u',' ',$s)??'');}
    private static function fail(): never{throw new RuntimeException('onetouch_source_changed');}
}
