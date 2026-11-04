<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionOneTouchSource.php';
require_once __DIR__.'/ManagerTourSelectionOneTouchImportDraft.php';

/** Manager-provided PUBLIC capture. Hash/signature bind admitted bytes and the
 * editor context; they do not certify the claimed RF origin or operator price.
 * Price time and previously obtained gallery/photo time are always separate.
 */
final class ManagerTourSelectionOneTouchCapture
{
    public static function decode($encoded,$expected): string
    {
        if(!is_string($encoded)||strlen($encoded)>80000||!preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/D',$encoded)||!is_string($expected)||!preg_match('/\A[a-f0-9]{64}\z/D',$expected))self::fail();
        $compressed=base64_decode($encoded,true);$bytes=is_string($compressed)?@gzdecode($compressed,ManagerTourSelectionOneTouchSource::MAX_BYTES):false;
        if(!is_string($bytes)||$bytes===''||strlen($bytes)>ManagerTourSelectionOneTouchSource::MAX_BYTES||!hash_equals($expected,hash('sha256',$bytes))||preg_match('//u',$bytes)!==1)self::fail();return $bytes;
    }
    public static function read($input,?int $now=null): array
    {
        $now??=time();
        if(!is_array($input)||array_diff(array_keys($input),['schema','reference','captured_at','url','public_controls','prices_gzip_base64','prices_sha256','galleries'])||($input['schema']??null)!=='onetouch-manager-capture-v1'||!is_string($input['reference']??null)||!preg_match('/\A[A-Za-z0-9_-]{8,96}\z/D',$input['reference']))self::fail();
        $captured=self::timestamp($input['captured_at']??null);
        // Application admission policy, not a fare-validity promise. Re-reading
        // or reviewing an old file never moves its source time forward.
        if($captured>$now+60||$now-$captured>600)throw new RuntimeException('onetouch_capture_expired');
        if(!is_string($input['url']??null))self::fail();$query=ManagerTourSelectionOneTouchSource::query($input['url']);
        $controlKeys=['TOWNFROMINC','STATEINC','CURRENCY'];$controls=$input['public_controls']??null;if(!is_array($controls)||array_diff(array_keys($controls),$controlKeys)||array_diff($controlKeys,array_keys($controls)))self::fail();$form='';
        foreach($controlKeys as $key){$value=$controls[$key];if(!is_array($value)||array_diff(array_keys($value),['value','text'])||($value['value']??null)!==$query[$key]||!is_string($value['text']??null)||$value['text']===''||strlen($value['text'])>240||preg_match('//u',$value['text'])!==1)self::fail();$form.='<select name="'.$key.'"><option selected value="'.$query[$key].'">'.htmlspecialchars($value['text'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</option></select>';}
        $response=self::decode($input['prices_gzip_base64']??null,$input['prices_sha256']??'');
        $quotes=ManagerTourSelectionOneTouchSource::parse($response,$input['url'],$form,$input['captured_at']);$galleries=$input['galleries']??null;
        if(!is_array($galleries)||!array_is_list($galleries)||count($galleries)<1||count($galleries)>20)self::fail();$byHotel=[];
        foreach($galleries as $g){
            if(!is_array($g)||array_diff(array_keys($g),['hotel','url','observed_at','html_sha256','images'])||!is_string($g['hotel']??null)||!is_string($g['url']??null)||isset($byHotel[$g['hotel']])||ManagerTourSelectionOneTouchSource::hotelUrl($g['url'],$g['hotel'])!==$g['url']||!is_string($g['html_sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$g['html_sha256'])||self::timestamp($g['observed_at']??null)>$now+60)self::fail();
            $images=$g['images']??null;if(!is_array($images)||!array_is_list($images)||count($images)<1||count($images)>3)self::fail();$map=[];
            foreach($images as $image){if(!is_array($image)||array_diff(array_keys($image),['url','sha256'])||!is_string($image['url']??null)||!ManagerTourSelectionOneTouchImportDraft::photoUrl($image['url'])||isset($map[$image['url']])||!is_string($image['sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$image['sha256']))self::fail();$map[$image['url']]=$image['sha256'];}
            $g['photo_hashes']=$map;$byHotel[$g['hotel']]=$g;
        }
        $offers=[];
        foreach($quotes as $quote){$g=$byHotel[$quote['keys']['hotel']]??null;if(!$g||$g['url']!==$quote['raw']['hotel_url'])continue;
            $quote['raw']+=['capture_mode'=>'manager-file-v1','capture_reference'=>$input['reference'],'capture_price_declared_at'=>$input['captured_at'],'capture_price_received_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'capture_price_sha256'=>$input['prices_sha256'],'capture_gallery_observed_at'=>$g['observed_at'],'capture_gallery_sha256'=>$g['html_sha256'],'capture_photo_sha256'=>json_encode($g['photo_hashes'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
            if(ManagerTourSelectionOneTouchImportDraft::normalize($quote)===null)self::fail();$offers[]=$quote;
        }
        if(!$offers)self::fail();return ['quotes'=>$offers,'expires'=>min($now+600,$captured+600)];
    }
    public static function timestamp($value): int
    {
        if(!is_string($value)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$value)||gmdate('Y-m-d\TH:i:s\Z',strtotime($value))!==$value)self::fail();return strtotime($value);
    }
    /** Binds automatic private byte upload to the already saved source. */
    public static function photoBinding(array $offer,array $input): bool
    {
        if(!array_key_exists('source_photo_url',$input))return true;
        $source=$offer['import_source']??[];$url=$input['source_photo_url'];
        if(($source['adapter']??null)!=='onetouch-public-v1'||($source['raw']['capture_mode']??null)!=='manager-file-v1'||!is_string($url)||!is_string($input['image_sha256']??null)||!is_string($source['raw']['capture_photo_sha256']??null))return false;
        $hashes=json_decode($source['raw']['capture_photo_sha256'],true);$slot=array_search($url,$source['photos']??[],true);
        return is_array($hashes)&&isset($hashes[$url])&&is_string($hashes[$url])&&$slot!==false&&($input['kind']??null)===(['cover','beach','room'][$slot]??null)&&hash_equals($hashes[$url],$input['image_sha256']);
    }
    private static function fail():never{throw new RuntimeException('onetouch_capture_invalid');}
}
