<?php

require_once __DIR__ . '/DestinationCatalogRepository.php';
require_once __DIR__ . '/NeedApplicationService.php';

class DestinationResolver
{
    private static $countryHL = 2;
    private static $regionHL = 3;
    private static $hotelHL = 6;

    public static function resolveAndStore($chatId, $text)
    {
        $text = trim((string)$text);
        if ($text === '') return [];

        // Для поиска destination используем только часть сообщения про место отдыха.
        // Город после «из» / «с вылетом из» не должен превращаться в страну/регион.
        $destinationText = self::destinationPart($text);

        $current = MaxSearchApi::getAiSearchContext($chatId);
        $old = self::getStored($chatId);

        $country = null;
        if (!empty($current['country'])) $country = self::findCountryByName($current['country']);
        if (!$country && $destinationText !== '') $country = self::findCountryInText($destinationText);

        $countryId = $country ? (int)$country['UF_CID'] : 0;
        $region = $destinationText !== '' ? self::findRegionInText($destinationText, $countryId) : null;
        if ($region && !$countryId) {
            $country = self::findCountryById((int)$region['UF_CID']);
            $countryId = $country ? (int)$country['UF_CID'] : (int)$region['UF_CID'];
        }

        $regionId = $region ? (int)$region['UF_TID'] : 0;

        // РФ и Абхазию подбираем как обычные направления, но справочник отелей HL6
        // для них намеренно не используем: названия дают слишком много ложных совпадений.
        $skipHotels = self::isHotelResolutionDisabledCountry($country);
        $hotelResult = ($skipHotels || $destinationText === '')
            ? ['hotel'=>null,'ambiguous'=>false,'matches'=>0]
            : self::findHotelInText($destinationText, $countryId, $regionId);
        $hotel = $hotelResult['hotel'] ?? null;
        if ($hotel) {
            $countryId = (int)$hotel['UF_CID'];
            if (!$country || (int)$country['UF_CID'] !== $countryId) $country = self::findCountryById($countryId);
            $hotelRegionId = (int)$hotel['UF_TID'];
            if ($hotelRegionId > 0 && (!$region || (int)$region['UF_TID'] !== $hotelRegionId)) {
                $region = self::findRegionByTid($hotelRegionId);
                $regionId = $region ? (int)$region['UF_TID'] : $hotelRegionId;
            }
        }

        if ($country) {
            $resolvedCountry = (string)$country['UF_NAME'];
            if (empty($current['country']) || self::norm($current['country']) !== self::norm($resolvedCountry)) {
                NeedApplicationService::applyParameters($chatId, ['country' => $resolvedCountry]);
            }
        }

        $data = [
            'country_id' => $country ? (int)$country['UF_CID'] : 0,
            'country' => $country ? (string)$country['UF_NAME'] : '',
            'region_id' => $region ? (int)$region['UF_TID'] : 0,
            'region' => $region ? (string)$region['UF_NAME'] : '',
            'hotel_id' => $hotel ? (int)$hotel['UF_HID'] : 0,
            'hotel' => $hotel ? (string)$hotel['UF_NAME'] : '',
            'hotel_ambiguous' => !empty($hotelResult['ambiguous']),
            'hotel_matches' => (int)($hotelResult['matches'] ?? 0),
            'hotel_resolution_skipped' => $skipHotels,
            'source_text' => $text,
            'updated_at' => date('c'),
        ];

        $countryChanged = !empty($data['country_id']) && !empty($old['country_id'])
            && (int)$data['country_id'] !== (int)$old['country_id'];
        $regionChanged = !empty($data['region_id']) && !empty($old['region_id'])
            && (int)$data['region_id'] !== (int)$old['region_id'];

        if (empty($data['country_id']) && !empty($old['country_id'])) {
            $data['country_id'] = (int)$old['country_id'];
            $data['country'] = (string)($old['country'] ?? '');
            $skipHotels = self::isHotelResolutionDisabledCountryName($data['country']);
            $data['hotel_resolution_skipped'] = $skipHotels;
        }
        if (empty($data['region_id']) && !empty($old['region_id']) && !$countryChanged) {
            $data['region_id'] = (int)$old['region_id'];
            $data['region'] = (string)($old['region'] ?? '');
        }
        // Для РФ/Абхазии старый hotel_id тоже не переносим: отель в этих направлениях
        // вообще не является частью нашей логики подбора.
        if (!$skipHotels && empty($data['hotel_id']) && !empty($old['hotel_id']) && !$countryChanged && !$regionChanged) {
            $data['hotel_id'] = (int)$old['hotel_id'];
            $data['hotel'] = (string)($old['hotel'] ?? '');
        }

        if ($data['country_id'] || $data['region_id'] || $data['hotel_id'] || $data['hotel_ambiguous']) {
            self::store($chatId,$data);
            MaxSearchApi::funnelLog($chatId,'destination_resolved',$data);
        }
        return $data;
    }

    public static function getStored($chatId) { $f=self::storeFile($chatId); if(!is_file($f)) return []; $d=json_decode((string)@file_get_contents($f),true); return is_array($d)?$d:[]; }
    public static function clear($chatId) { $f=self::storeFile($chatId); if(is_file($f)) @unlink($f); }
    private static function store($chatId,array $data) { @file_put_contents(self::storeFile($chatId),json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX); }
    private static function storeFile($chatId) { $d=dirname(__DIR__).'/ai_destination'; if(!is_dir($d)) @mkdir($d,0755,true); return $d.'/'.preg_replace('/[^0-9\-]/','',(string)$chatId).'.json'; }

    private static function isHotelResolutionDisabledCountry($country)
    {
        return is_array($country) && self::isHotelResolutionDisabledCountryName((string)($country['UF_NAME'] ?? ''));
    }
    private static function isHotelResolutionDisabledCountryName($name)
    {
        $name=self::norm($name);
        return in_array($name,['россия','абхазия'],true);
    }

    /**
     * Возвращает только часть сообщения, описывающую destination.
     * «из Питера в Китай» -> «Китай»;
     * «с вылетом из Москвы» / «туры из Калининграда» -> пустая строка.
     */
    private static function destinationPart($text)
    {
        $text = trim((string)$text);
        if ($text === '') return '';

        if (preg_match('/(?:^|\s)из\s+.+?\s+в\s+(.+)$/ui', $text, $m)) {
            $part = trim((string)($m[1] ?? ''));
            if ($part !== '') return $part;
        }

        if (preg_match('/(?:с\s+)?вылет(?:ом)?\s+из\s+[\p{L}\-]+(?:\s+[\p{L}\-]+)*/ui', $text)) {
            return '';
        }
        if (preg_match('/(?:^|\s)туры?\s+из\s+[\p{L}\-]+(?:\s+[\p{L}\-]+)*/ui', $text)) {
            return '';
        }
        if (preg_match('/(?:^|\s)из\s+[\p{L}\-]+(?:\s+[\p{L}\-]+)*\s*$/ui', $text)) {
            return '';
        }

        return $text;
    }

    private static function findCountryInText($text) { $rows=self::allRows(self::$countryHL,['UF_CID','UF_NAME']); $norm=self::norm($text); $best=null;$bestLen=0; foreach($rows as $row){$name=self::norm($row['UF_NAME']??'');if($name!==''&&self::containsName($norm,$name)&&mb_strlen($name,'UTF-8')>$bestLen){$best=$row;$bestLen=mb_strlen($name,'UTF-8');}} return $best; }
    private static function findCountryByName($name) { $r=self::query(self::$countryHL,['=UF_NAME'=>trim((string)$name)],['UF_CID','UF_NAME'],1); return $r[0]??null; }
    private static function findCountryById($cid) { if((int)$cid<=0)return null;$r=self::query(self::$countryHL,['=UF_CID'=>(int)$cid],['UF_CID','UF_NAME'],1);return $r[0]??null; }
    private static function findRegionByTid($tid) { if((int)$tid<=0)return null;$r=self::query(self::$regionHL,['=UF_TID'=>(int)$tid],['UF_TID','UF_CID','UF_NAME','UF_PARENT_TID'],1);return $r[0]??null; }

    private static function findRegionInText($text,$countryId=0)
    {
        $tokens=self::tokens($text); $candidates=[];
        foreach($tokens as $token){
            $len=mb_strlen($token,'UTF-8'); if($len<4)continue;
            $prefixLen = $len <= 5 ? $len : $len-1;
            $prefix=mb_substr($token,0,$prefixLen,'UTF-8');
            $filter=['%UF_NAME'=>$prefix]; if($countryId>0)$filter['=UF_CID']=$countryId;
            foreach(self::query(self::$regionHL,$filter,['UF_TID','UF_CID','UF_NAME','UF_PARENT_TID'],30) as $row)$candidates[(int)$row['UF_TID']]=$row;
        }
        $best=null;$bestScore=0;
        foreach($candidates as $row){$score=self::regionNameScore((string)$row['UF_NAME'],$text);if($score>$bestScore){$best=$row;$bestScore=$score;}}
        return $bestScore>=8?$best:null;
    }

    private static function regionNameScore($name,$text)
    {
        $nameTokens=self::tokens($name);$textTokens=self::tokens($text);$best=0;
        foreach($nameTokens as $nt){foreach($textTokens as $tt){
            if($nt===$tt)$best=max($best,100+mb_strlen($nt,'UTF-8'));
            else {$nl=mb_strlen($nt,'UTF-8');$tl=mb_strlen($tt,'UTF-8');if($nl>=6&&$tl>=6){$prefix=min($nl,$tl)-1;if($prefix>=5&&mb_substr($nt,0,$prefix,'UTF-8')===mb_substr($tt,0,$prefix,'UTF-8'))$best=max($best,10+$prefix);}}
        }}
        return $best;
    }

    private static function findHotelInText($text,$countryId=0,$regionId=0)
    {
        $tokens=self::tokens($text);
        $maxN=min(5,count($tokens));
        for($n=$maxN;$n>=2;$n--){
            for($i=0;$i+$n<=count($tokens);$i++){
                $term=trim(implode(' ',array_slice($tokens,$i,$n)));
                if(mb_strlen($term,'UTF-8')<5)continue;
                $filter=['%UF_NAME'=>$term];
                if($countryId>0)$filter['=UF_CID']=$countryId;
                if($regionId>0)$filter['=UF_TID']=$regionId;
                $rows=self::query(self::$hotelHL,$filter,['UF_HID','UF_NAME','UF_CID','UF_TID','UF_RATE'],30);
                foreach($rows as $row){
                    if(self::norm((string)$row['UF_NAME'])===self::norm($term)) return ['hotel'=>$row,'ambiguous'=>false,'matches'=>1];
                }
            }
        }
        if (!self::hasHotelIntent($text)) return ['hotel'=>null,'ambiguous'=>false,'matches'=>0];
        $terms=[];
        for($n=$maxN;$n>=1;$n--)for($i=0;$i+$n<=count($tokens);$i++){ $term=trim(implode(' ',array_slice($tokens,$i,$n)));if(mb_strlen($term,'UTF-8')>=4)$terms[$term]=true; }
        $candidates=[];
        foreach(array_keys($terms) as $term){$filter=['%UF_NAME'=>$term];if($countryId>0)$filter['=UF_CID']=$countryId;if($regionId>0)$filter['=UF_TID']=$regionId;foreach(self::query(self::$hotelHL,$filter,['UF_HID','UF_NAME','UF_CID','UF_TID','UF_RATE'],20) as $row)$candidates[(int)$row['UF_HID']]=$row;}
        if(empty($candidates))return ['hotel'=>null,'ambiguous'=>false,'matches'=>0];
        $scored=[];foreach($candidates as $row){$score=self::nameScore((string)$row['UF_NAME'],$text);if($regionId>0&&(int)$row['UF_TID']===$regionId)$score+=5;if($countryId>0&&(int)$row['UF_CID']===$countryId)$score+=2;if($score>0)$scored[]=['score'=>$score,'row'=>$row];}
        usort($scored,static function($a,$b){return $b['score']<=>$a['score'];});if(empty($scored))return ['hotel'=>null,'ambiguous'=>false,'matches'=>0];
        $top=$scored[0];$sameTop=array_values(array_filter($scored,static function($x)use($top){return $x['score']===$top['score'];}));
        $meaningful=array_values(array_filter($tokens,static function($t){return mb_strlen($t,'UTF-8')>=4;}));
        $ambiguous=count($sameTop)>1||(count($meaningful)<=1&&count($scored)>1&&$regionId<=0);
        return ['hotel'=>$ambiguous?null:$top['row'],'ambiguous'=>$ambiguous,'matches'=>count($scored)];
    }

    private static function hasHotelIntent($text)
    {
        $norm = self::norm($text);
        return (bool)preg_match('/(?:^|\s)(?:отел(?:ь|я|е|ем|ю)?|гостиниц[а-я]*|hotel|resort)(?:\s|$)/ui', $norm);
    }

    private static function nameScore($name,$text){$a=self::tokens($name);$b=self::tokens($text);if(!$a||!$b)return 0;$score=0;foreach($a as $nt){if(mb_strlen($nt,'UTF-8')<3)continue;foreach($b as $tt){if(mb_strlen($tt,'UTF-8')<3)continue;$len=min(mb_strlen($nt,'UTF-8'),mb_strlen($tt,'UTF-8'));$prefix=min(5,max(3,$len-1));if(mb_substr($nt,0,$prefix,'UTF-8')===mb_substr($tt,0,$prefix,'UTF-8')){$score+=$prefix;break;}}}return $score;}
    private static function containsName($normText,$normName){if(strpos($normText,$normName)!==false)return true;return self::nameScore($normName,$normText)>=4;}
    private static function tokens($text){$norm=self::norm($text);$parts=preg_split('/\s+/u',$norm,-1,PREG_SPLIT_NO_EMPTY);$stop=['хочу','хотим','нужен','нужна','нужно','отель','гостиница','тур','путевка','путёвка','поехать','ехать','лететь','вылет','на','в','из','и','или','для','с','со','около','район','курорт'];return array_values(array_filter((array)$parts,static function($p)use($stop){return !in_array($p,$stop,true)&&mb_strlen($p,'UTF-8')>=2;}));}
    private static function norm($text){$s=function_exists('mb_strtolower')?mb_strtolower((string)$text,'UTF-8'):strtolower((string)$text);$s=str_replace('ё','е',$s);$s=preg_replace('/[^a-zа-я0-9]+/ui',' ',$s);return trim(preg_replace('/\s+/u',' ',$s));}
    private static function allRows($hlId,array $select){return self::query($hlId,[],$select,500);}
    private static function query($hlId,array $filter,array $select,$limit=20){try{return DestinationCatalogRepository::query((int)$hlId,$filter,$select,(int)$limit);}catch(\Throwable $e){@file_put_contents(dirname(__DIR__).'/destination_errors.log',date('d.m.Y H:i:s').'--- '.$e->getMessage().PHP_EOL,FILE_APPEND|LOCK_EX);return [];}}
}
