<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionOneTouchSource.php';
require_once __DIR__.'/ManagerTourSelectionImportDraft.php';
require_once __DIR__.'/ManagerTourSelectionOneTouchCapture.php';

/** Existing editor context and stateless signed previews; not a second import store. */
final class ManagerTourSelectionOneTouchIntake
{
    public static function sessionKey(array &$state,string $sessionHash): string
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$sessionHash))throw new InvalidArgumentException('invalid_session');
        $key='tour_selection_onetouch_signing_secret';
        if(!is_string($state[$key]??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$state[$key]))$state[$key]=bin2hex(random_bytes(32));
        return hash_hmac('sha256','onetouch-source-session-v1|'.$sessionHash,$state[$key]);
    }

    public static function search(int $actor,int $conversation,string $session,array $data,?callable $transport=null): array
    {
        $context=ManagerTourSelectionService::sourceContext($actor,$conversation);if(empty($context['ok']))return $context;
        try{
            $expires=null;
            if(array_key_exists('capture',$data)){$capture=ManagerTourSelectionOneTouchCapture::read($data['capture']);$quotes=$capture['quotes'];$expires=$capture['expires'];}
            elseif($transport!==null){$url=$data['url']??'';if(!is_string($url))return self::error('onetouch_invalid_url');ManagerTourSelectionOneTouchSource::query($url);$result=$transport($url);$quotes=ManagerTourSelectionOneTouchSource::parse($result['response'],$url,$result['form'],gmdate('Y-m-d\TH:i:s\Z'));}
            else return self::error('onetouch_capture_required');
            if(!self::sameContext($context,ManagerTourSelectionService::sourceContext($actor,$conversation)))return self::error('draft_changed');
            $offers=[];
            foreach($quotes as $quote)$offers[]=['offer'=>$quote['offer'],'raw'=>$quote['raw'],'ticket'=>self::seal($session,$actor,$conversation,$context,$quote,'quote',$expires)];
            return ['ok'=>true,'version'=>$context['version'],'offers'=>$offers,'total'=>count($offers)];
        }catch(Throwable $e){return self::error($e instanceof RuntimeException&&in_array($e->getMessage(),['onetouch_source_changed','onetouch_capture_expired','onetouch_capture_invalid'],true)?$e->getMessage():'onetouch_source_unavailable');}
    }

    public static function review(int $actor,int $conversation,string $session,array $data,?callable $fetch=null): array
    {
        $context=ManagerTourSelectionService::sourceContext($actor,$conversation);if(empty($context['ok']))return $context;
        $ticket=self::open($data['ticket']??null,$session,$actor,$conversation,'quote');if($ticket===null)return self::error('onetouch_expired');
        if(!self::sameContext($context,$ticket))return self::error('draft_changed');
        try{
            $quote=$ticket['observation'];$url=$quote['raw']['hotel_url'];
            if(($quote['raw']['capture_mode']??null)==='manager-file-v1'){
                $html=ManagerTourSelectionOneTouchCapture::decode($data['gallery_gzip_base64']??null,$quote['raw']['capture_gallery_sha256']);
                $native=ManagerTourSelectionOneTouchSource::gallery($html,$quote);$hashes=json_decode($quote['raw']['capture_photo_sha256'],true,8,JSON_THROW_ON_ERROR);$photos=array_keys($hashes);
                if($photos!==array_slice($native,0,count($photos)))return self::error('onetouch_photos_unavailable');$quote['photos']=$photos;
            }elseif($fetch!==null)$quote['photos']=ManagerTourSelectionOneTouchSource::gallery($fetch($url),$quote);
            else return self::error('onetouch_capture_required');
            if(!$quote['photos']||ManagerTourSelectionImportDraft::normalize($quote)===null)return self::error('onetouch_photos_unavailable');
            if(!self::sameContext($context,ManagerTourSelectionService::sourceContext($actor,$conversation)))return self::error('draft_changed');
            return ['ok'=>true,'version'=>$context['version'],'offer'=>$quote['offer'],'raw'=>$quote['raw'],'photos'=>$quote['photos'],'ticket'=>self::seal($session,$actor,$conversation,$context,$quote,'ready',$ticket['expires'])];
        }catch(Throwable $e){return self::error('onetouch_photos_unavailable');}
    }

    private static function sameContext(array $a,array $b): bool{return ($a['version']??null)===($b['version']??null)&&is_string($a['context_hash']??null)&&is_string($b['context_hash']??null)&&hash_equals($a['context_hash'],$b['context_hash']);}
    private static function seal(string $key,int $actor,int $conversation,array $context,array $quote,string $stage,?int $expires=null): string
    {
        $body=json_encode(['schema'=>1,'actor'=>$actor,'conversation'=>$conversation,'version'=>$context['version'],'context_hash'=>$context['context_hash'],'expires'=>$expires??time()+600,'stage'=>$stage,'observation'=>$quote],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $encoded=rtrim(strtr(base64_encode($body),'+/','-_'),'=');return $encoded.'.'.hash_hmac('sha256',$encoded,'onetouch-preview-v1|'.$key);
    }
    public static function open($value,string $key,int $actor,int $conversation,string $stage,bool $allowExpired=false): ?array
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$key)||!is_string($value)||strlen($value)>32768||!preg_match('/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$value,$m)||!hash_equals(hash_hmac('sha256',$m[1],'onetouch-preview-v1|'.$key),$m[2]))return null;
        try{$payload=base64_decode(strtr($m[1],'-_','+/'),true);$d=is_string($payload)?json_decode($payload,true,24,JSON_THROW_ON_ERROR):null;}catch(JsonException $e){return null;}
        if(!is_array($d)||($d['schema']??null)!==1||($d['actor']??null)!==$actor||($d['conversation']??null)!==$conversation||($d['stage']??null)!==$stage||!is_int($d['version']??null)||$d['version']<0||!is_string($d['context_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$d['context_hash'])||!is_int($d['expires']??null)||(!$allowExpired&&$d['expires']<time())||$d['expires']>time()+600||!is_array($d['observation']??null))return null;
        return $d;
    }

    private static function error(string $code):array{return ['ok'=>false,'error'=>$code];}
}
