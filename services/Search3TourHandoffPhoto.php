<?php
declare(strict_types=1);
require_once __DIR__.'/Search3TourHandoffSnapshot.php';
require_once __DIR__.'/TourSelectionPhotoStore.php';

/** Optional cover from the existing content-only LOCAL crosswalk, never suppliers. */
final class Search3TourHandoffPhoto
{
    private const URL='https://anytoour.ru/_preview/search3-next-candidate/data/local-tv-catalog-read-v1.php';
    private const MAX_BYTES=262144;

    public static function image(array $snapshot,?callable $read=null,?callable $download=null): ?array
    {
        $snapshot=Search3TourHandoffSnapshot::normalize($snapshot);
        if ($snapshot===null) return null;
        $id=$snapshot['observation']['hotel']['uiId'];
        try {
            $url=self::URL.'?'.http_build_query(['oldLocalHotelIds'=>[$id]],'','&',PHP_QUERY_RFC3986);
            $reply=($read??[self::class,'read'])($url);
            if (!is_array($reply)||($reply['status']??null)!==200||!is_string($reply['type']??null)
                ||!preg_match('~\Aapplication/json(?:;|\z)~i',$reply['type'])||!is_string($reply['body']??null)||strlen($reply['body'])>self::MAX_BYTES) return null;
            $data=json_decode($reply['body'],true,32,JSON_THROW_ON_ERROR);$image=self::source($id,$data);
            if ($image===null) return null;
            // A stable hotel namespace reuses the existing bounded private byte cache.
            $key=substr(hash('sha256','search3-ui-hotel:'.$id),0,32);
            TourSelectionPhotoStore::image($key,0,0,$image,$download);
            return TourSelectionPhotoStore::cached($key,0,0,$image);
        } catch (Throwable $ignored) {return null;}
    }

    public static function source(int $id,mixed $data): ?string
    {
        if (!is_array($data)||($data['ok']??null)!==true||($data['source']??null)!=='anytour-local-tv-catalog-v1'
            ||($data['catalog']??null)!=='local-tv'||($data['requestedIds']??null)!==[$id]||($data['missingIds']??null)!==[]
            ||!is_array($data['items']??null)||!array_is_list($data['items'])||count($data['items'])!==1
            ||!is_array($data['links']??null)||!array_is_list($data['links'])||count($data['links'])!==1) return null;
        $link=$data['links'][0];$item=$data['items'][0];
        if (!is_array($link)||!is_array($item)||count($link)!==2||($link['oldLocalId']??null)!==$id
            ||!is_int($link['tourvisorHotelId']??null)||$link['tourvisorHotelId']<1||($item['id']??null)!==$link['tourvisorHotelId']
            ||($item['catalog']??null)!=='local-tv'||!is_int($item['revision']??null)||$item['revision']<1) return null;
        $images=is_array($item['images']??null)&&array_is_list($item['images'])?array_slice($item['images'],0,3):[];
        foreach ([$item['primaryImage']??null,...$images] as $url) {
            if (is_string($url)&&!str_starts_with(strtolower($url),'https://app.anytoour.ru/manager/')&&TourSelectionPhotoStore::source($url)!==null) return $url;
        }
        return null;
    }

    private static function read(string $url): array
    {
        $bytes='';$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_CONNECTTIMEOUT_MS=>700,CURLOPT_TIMEOUT_MS=>2000,
            CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$bytes):int {
                if(strlen($bytes)+strlen($chunk)>self::MAX_BYTES)return 0;$bytes.=$chunk;return strlen($chunk);
            }]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$error=curl_errno($ch);curl_close($ch);
        if($ok===false||$error)throw new RuntimeException('Hotel image unavailable');
        return ['status'=>$status,'type'=>$type,'body'=>$bytes];
    }
}
