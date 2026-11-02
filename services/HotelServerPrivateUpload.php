<?php
declare(strict_types=1);

/** One administrator-pinned encrypted file. No download, import, configuration or database access. */
final class HotelServerPrivateUpload
{
    public const MAX_BYTES=8388608;

    /** The administrator creates ticket.json through the existing private server channel. */
    public static function receive(string $appRoot,array $server,$input,?int $now=null): array
    {
        $deny=['code'=>404,'body'=>['ok'=>false,'state'=>'unavailable']];$lock=null;$output=null;$mask=null;
        try{
            $now??=time();
            if(($server['REQUEST_METHOD']??null)!=='POST'||($server['HTTPS']??null)!=='on'
                ||strtolower((string)($server['HTTP_HOST']??''))!=='app.anytoour.ru'
                ||strtolower((string)($server['CONTENT_TYPE']??''))!=='application/octet-stream'
                ||!empty($server['HTTP_ORIGIN'])||!empty($server['HTTP_TRANSFER_ENCODING'])
                ||!is_string($server['HTTP_AUTHORIZATION']??null)
                ||!preg_match('/\ABearer ([a-f0-9]{64})\z/D',$server['HTTP_AUTHORIZATION'],$auth)
                ||!is_resource($input)||!function_exists('posix_geteuid'))return $deny;
            $app=realpath($appRoot);if(!$app||!is_dir($app))return $deny;
            $root=dirname($app).'/max-search-bot-hotel-server';$dir=$root.'/ingress-v1';
            foreach([$root,$dir] as $path){clearstatcache(true,$path);if(is_link($path)||realpath($path)!==$path||!is_dir($path)
                ||(fileperms($path)&0777)!==0700||fileowner($path)!==posix_geteuid())return $deny;}
            $ticketPath=$dir.'/ticket.json';
            if(!self::privateFile($ticketPath)||filesize($ticketPath)>4096)return $deny;
            $raw=file_get_contents($ticketPath);$ticket=json_decode((string)$raw,true,8,JSON_THROW_ON_ERROR);
            if(!is_array($ticket))return $deny;
            $keys=array_keys($ticket);sort($keys);
            if($keys!==['created_at','expires_at','operation_id','payload_bytes','payload_sha256','schema','token_sha256']
                ||$ticket['schema']!=='hotel-server-private-upload-v1')return $deny;
            foreach(['operation_id','payload_sha256','token_sha256'] as $key)
                if(!is_string($ticket[$key])||!preg_match('/\A[a-f0-9]{64}\z/D',$ticket[$key]))return $deny;
            if(!is_int($ticket['created_at'])||!is_int($ticket['expires_at'])||$ticket['created_at']>$now
                ||$ticket['expires_at']<=$now||$ticket['expires_at']-$ticket['created_at']>3600
                ||!is_int($ticket['payload_bytes'])||$ticket['payload_bytes']<1||$ticket['payload_bytes']>self::MAX_BYTES
                ||!hash_equals($ticket['token_sha256'],hash('sha256',$auth[1])))return $deny;
            $length=$server['CONTENT_LENGTH']??null;
            if(!is_string($length)||!preg_match('/\A[1-9][0-9]{0,7}\z/D',$length)
                ||(int)$length!==$ticket['payload_bytes'])return $deny;
            $lockPath=$dir.'/upload.lock';if(is_link($lockPath)||(file_exists($lockPath)&&!self::privateFile($lockPath)))return $deny;
            $mask=umask(0077);
            $lock=fopen($lockPath,'c');if(!$lock||!chmod($lockPath,0600)||!flock($lock,LOCK_EX|LOCK_NB))return $deny;
            if(!hash_equals(hash('sha256',(string)$raw),hash_file('sha256',$ticketPath)))return $deny;
            $final=$dir.'/sealed-inputs.bin';$partial=$dir.'/upload.part';
            $hold=['code'=>409,'body'=>['ok'=>false,'state'=>'payload_unconfirmed']];
            if(file_exists($final)||is_link($final)){
                if(!self::privateFile($final)||filesize($final)!==$ticket['payload_bytes']
                    ||!hash_equals($ticket['payload_sha256'],hash_file('sha256',$final)))return $hold;
                return self::stored($ticket);
            }
            // A partial upload has a known owner. It is never replaced/restarted by another HTTP call.
            if(file_exists($partial)||is_link($partial))return $hold;
            $output=fopen($partial,'x');if(!$output||!chmod($partial,0600))return $hold;
            $hash=hash_init('sha256');$count=0;
            while(!feof($input)){
                $chunk=fread($input,min(65536,$ticket['payload_bytes']-$count+1));
                if(!is_string($chunk)||($chunk===''&&!feof($input)))return $hold;
                $count+=strlen($chunk);if($count>$ticket['payload_bytes'])return $hold;
                if($chunk!==''&&fwrite($output,$chunk)!==strlen($chunk))return $hold;
                hash_update($hash,$chunk);
            }
            if($count!==$ticket['payload_bytes']||!hash_equals($ticket['payload_sha256'],hash_final($hash))
                ||!fflush($output)||(function_exists('fsync')&&!fsync($output)))return $hold;
            fclose($output);$output=null;
            if(!rename($partial,$final)||!self::privateFile($final)||filesize($final)!==$ticket['payload_bytes']
                ||!hash_equals($ticket['payload_sha256'],hash_file('sha256',$final)))return $hold;
            return self::stored($ticket);
        }catch(Throwable $ignored){return $deny;}
        finally{
            if(is_resource($output))fclose($output);
            if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
            if($mask!==null)umask($mask);
        }
    }

    private static function stored(array $ticket): array
    {return ['code'=>200,'body'=>['ok'=>true,'state'=>'stored','bytes'=>$ticket['payload_bytes'],'sha256'=>$ticket['payload_sha256']]];}
    private static function privateFile(string $path): bool
    {clearstatcache(true,$path);return !is_link($path)&&is_file($path)&&(fileperms($path)&0777)===0600
        &&(!function_exists('posix_geteuid')||fileowner($path)===posix_geteuid());}
}
