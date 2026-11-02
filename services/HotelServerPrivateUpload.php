<?php
declare(strict_types=1);

/** One administrator-pinned encrypted file. No download, import, configuration or database access. */
final class HotelServerPrivateUpload
{
    public const MAX_BYTES=8388608;
    public const CHUNK_BYTES=65536;

    /** The administrator creates ticket.json through the existing private server channel. */
    public static function receive(string $appRoot,array $server,$input,?int $now=null): array
    {
        $deny=['code'=>404,'body'=>['ok'=>false,'state'=>'unavailable']];$lock=null;$output=null;$mask=null;
        try{
            $now??=time();$capability=self::capability($server);
            if(($server['REQUEST_METHOD']??null)!=='POST'||($server['HTTPS']??null)!=='on'
                ||strtolower((string)($server['HTTP_HOST']??''))!=='app.anytoour.ru'
                ||strtolower((string)($server['CONTENT_TYPE']??''))!=='application/octet-stream'
                ||!empty($server['HTTP_ORIGIN'])||!empty($server['HTTP_TRANSFER_ENCODING'])
                ||$capability===null
                ||!is_resource($input))return $deny;
            $uid=self::processUid();if($uid===null)return $deny;
            $app=realpath($appRoot);if(!$app||!is_dir($app))return $deny;
            $root=dirname($app).'/max-search-bot-hotel-server';$dir=$root.'/ingress-v1';
            foreach([$root,$dir] as $path){clearstatcache(true,$path);if(is_link($path)||realpath($path)!==$path||!is_dir($path)
                ||(fileperms($path)&0777)!==0700||fileowner($path)!==$uid)return $deny;}
            $ticketPath=$dir.'/ticket.json';
            if(!self::privateFile($ticketPath,$uid)||filesize($ticketPath)>4096)return $deny;
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
                ||!hash_equals($ticket['token_sha256'],hash('sha256',$capability)))return $deny;
            $chunk=null;$chunkSha=null;$expectedLength=$ticket['payload_bytes'];
            if(array_key_exists('HTTP_X_HOTEL_CHUNK_INDEX',$server)||array_key_exists('HTTP_X_HOTEL_CHUNK_SHA256',$server)){
                $index=$server['HTTP_X_HOTEL_CHUNK_INDEX']??null;$chunkSha=$server['HTTP_X_HOTEL_CHUNK_SHA256']??null;
                if(!is_string($index)||!preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D',$index)
                    ||!is_string($chunkSha)||!preg_match('/\A[a-f0-9]{64}\z/D',$chunkSha))return $deny;
                $chunk=(int)$index;
                if($chunk>=(int)ceil($ticket['payload_bytes']/self::CHUNK_BYTES))return $deny;
                $expectedLength=min(self::CHUNK_BYTES,$ticket['payload_bytes']-$chunk*self::CHUNK_BYTES);
            }
            $length=$server['CONTENT_LENGTH']??null;
            if(!is_string($length)||!preg_match('/\A[1-9][0-9]{0,7}\z/D',$length)
                ||(int)$length!==$expectedLength)return $deny;
            $lockPath=$dir.'/upload.lock';if(is_link($lockPath)||(file_exists($lockPath)&&!self::privateFile($lockPath,$uid)))return $deny;
            $mask=umask(0077);
            $lock=fopen($lockPath,'c');if(!$lock||!chmod($lockPath,0600)||!flock($lock,LOCK_EX|LOCK_NB))return $deny;
            if(!hash_equals(hash('sha256',(string)$raw),hash_file('sha256',$ticketPath)))return $deny;
            $final=$dir.'/sealed-inputs.bin';$partial=$dir.'/upload.part';
            $hold=['code'=>409,'body'=>['ok'=>false,'state'=>'payload_unconfirmed']];
            if(file_exists($final)||is_link($final)){
                if(!self::privateFile($final,$uid)||filesize($final)!==$ticket['payload_bytes']
                    ||!hash_equals($ticket['payload_sha256'],hash_file('sha256',$final)))return $hold;
                return self::stored($ticket);
            }
            // A partial upload has a known owner. It is never replaced/restarted by another HTTP call.
            if(file_exists($partial)||is_link($partial))return $hold;
            if($chunk!==null)return self::chunk($dir,$ticket,$input,$chunk,$chunkSha,$expectedLength,$uid);
            if(file_exists($dir.'/chunks')||is_link($dir.'/chunks'))return $hold;
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
            if(!rename($partial,$final)||!self::privateFile($final,$uid)||filesize($final)!==$ticket['payload_bytes']
                ||!hash_equals($ticket['payload_sha256'],hash_file('sha256',$final)))return $hold;
            return self::stored($ticket);
        }catch(Throwable $ignored){return $deny;}
        finally{
            if(is_resource($output))fclose($output);
            if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
            if($mask!==null)umask($mask);
        }
    }

    /** Fixed small request bodies also work behind the observed 128 KiB FastCGI boundary. */
    private static function chunk(string $dir,array $ticket,$input,int $index,string $sha,int $bytes,int $uid): array
    {
        $hold=['code'=>409,'body'=>['ok'=>false,'state'=>'payload_unconfirmed']];$chunks=$dir.'/chunks';
        if(!file_exists($chunks)&&!is_link($chunks)&&!mkdir($chunks,0700))return $hold;
        clearstatcache(true,$chunks);
        if(is_link($chunks)||realpath($chunks)!==$chunks||!is_dir($chunks)||(fileperms($chunks)&0777)!==0700||fileowner($chunks)!==$uid)return $hold;
        $path=$chunks.'/'.sprintf('%03d',$index).'.bin';
        if(!self::writeChunk($input,$path,$bytes,$sha,$uid))return $hold;
        $count=(int)ceil($ticket['payload_bytes']/self::CHUNK_BYTES);$paths=[];$missing=false;
        for($i=0;$i<$count;$i++){
            $p=$chunks.'/'.sprintf('%03d',$i).'.bin';
            if(file_exists($p.'.part')||is_link($p.'.part'))return $hold;
            if(!file_exists($p)&&!is_link($p)){$missing=true;continue;}
            $size=min(self::CHUNK_BYTES,$ticket['payload_bytes']-$i*self::CHUNK_BYTES);
            if(!self::privateFile($p,$uid)||filesize($p)!==$size)return $hold;$paths[]=$p;
        }
        if($missing)return ['code'=>200,'body'=>['ok'=>true,'state'=>'chunk_stored','index'=>$index,'bytes'=>$bytes,'sha256'=>$sha,'payload_sha256'=>$ticket['payload_sha256']]];
        $partial=$dir.'/upload.part';$final=$dir.'/sealed-inputs.bin';$out=fopen($partial,'x');
        if(!$out)return $hold;
        try{
            if(!chmod($partial,0600))return $hold;$hash=hash_init('sha256');$written=0;
            foreach($paths as $p){
                $raw=file_get_contents($p);if(!is_string($raw)||strlen($raw)>self::CHUNK_BYTES)return $hold;
                if(fwrite($out,$raw)!==strlen($raw))return $hold;$written+=strlen($raw);hash_update($hash,$raw);
            }
            if($written!==$ticket['payload_bytes']||!hash_equals($ticket['payload_sha256'],hash_final($hash))
                ||!fflush($out)||(function_exists('fsync')&&!fsync($out)))return $hold;
        }finally{fclose($out);}
        if(!rename($partial,$final)||!self::privateFile($final,$uid)||filesize($final)!==$ticket['payload_bytes']
            ||!hash_equals($ticket['payload_sha256'],hash_file('sha256',$final)))return $hold;
        return self::stored($ticket);
    }

    /** A failed fragment remains present. A completed fragment can only be read back exactly. */
    private static function writeChunk($input,string $path,int $bytes,string $sha,int $uid): bool
    {
        if(file_exists($path)||is_link($path))return self::privateFile($path,$uid)&&filesize($path)===$bytes&&hash_equals($sha,hash_file('sha256',$path));
        $partial=$path.'.part';if(file_exists($partial)||is_link($partial))return false;
        $out=fopen($partial,'x');if(!$out)return false;
        try{
            if(!chmod($partial,0600))return false;$hash=hash_init('sha256');$count=0;
            while(!feof($input)){
                $raw=fread($input,min(self::CHUNK_BYTES,$bytes-$count+1));
                if(!is_string($raw)||($raw===''&&!feof($input)))return false;$count+=strlen($raw);
                if($count>$bytes||($raw!==''&&fwrite($out,$raw)!==strlen($raw)))return false;hash_update($hash,$raw);
            }
            if($count!==$bytes||!hash_equals($sha,hash_final($hash))||!fflush($out)||(function_exists('fsync')&&!fsync($out)))return false;
        }finally{fclose($out);}
        return rename($partial,$path)&&self::privateFile($path,$uid)&&filesize($path)===$bytes&&hash_equals($sha,hash_file('sha256',$path));
    }

    /** Resolve process credentials once; never substitute the PHP script file's owner. */
    private static function processUid(): ?int
    {
        if(extension_loaded('posix')){
            // A deliberately disabled or failing POSIX function must not be bypassed.
            if(!function_exists('posix_geteuid'))return null;
            $uid=posix_geteuid();return is_int($uid)&&$uid>=0?$uid:null;
        }
        if(PHP_OS_FAMILY!=='Linux')return null;
        $status=@file_get_contents('/proc/self/status',false,null,0,65537);
        return is_string($status)?self::uidFromProcStatus($status):null;
    }

    /** Linux gives real, effective, saved-set and filesystem UIDs; require one unambiguous identity. */
    private static function uidFromProcStatus(string $status): ?int
    {
        if(strlen($status)>65536||preg_match_all('/^Uid:[^\n]*$/m',$status,$rows)!==1)return null;
        if(preg_match('/\AUid:[ \t]+([0-9]+)[ \t]+([0-9]+)[ \t]+([0-9]+)[ \t]+([0-9]+)[ \t]*\z/D',$rows[0][0],$m)!==1)return null;
        $uid=$m[1];$maximum=PHP_INT_SIZE>=8?'4294967295':(string)PHP_INT_MAX;
        if(!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$uid)||strlen($uid)>strlen($maximum)
            ||(strlen($uid)===strlen($maximum)&&strcmp($uid,$maximum)>0))return null;
        if($m[2]!==$uid||$m[3]!==$uid||$m[4]!==$uid)return null;
        return (int)$uid;
    }

    /** Ordinary CGI headers can carry this same capability when Authorization is omitted by the server. */
    private static function capability(array $server): ?string
    {
        $custom='HTTP_X_HOTEL_UPLOAD_CAPABILITY';
        if(!array_key_exists($custom,$server)){
            $legacy=$server['HTTP_AUTHORIZATION']??null;
            return is_string($legacy)&&preg_match('/\ABearer ([a-f0-9]{64})\z/D',$legacy,$match)===1?$match[1]:null;
        }
        $token=$server[$custom];
        // A present invalid custom header cannot fall back to a different credential source.
        if(!is_string($token)||!preg_match('/\A[a-f0-9]{64}\z/D',$token))return null;
        if(array_key_exists('HTTP_AUTHORIZATION',$server)){
            $legacy=$server['HTTP_AUTHORIZATION'];
            if(!is_string($legacy)||!preg_match('/\ABearer ([a-f0-9]{64})\z/D',$legacy,$match)
                ||!hash_equals($token,$match[1]))return null;
        }
        return $token;
    }

    private static function stored(array $ticket): array
    {return ['code'=>200,'body'=>['ok'=>true,'state'=>'stored','bytes'=>$ticket['payload_bytes'],'sha256'=>$ticket['payload_sha256']]];}
    private static function privateFile(string $path,int $uid): bool
    {clearstatcache(true,$path);return !is_link($path)&&is_file($path)&&(fileperms($path)&0777)===0600
        &&fileowner($path)===$uid;}
}
