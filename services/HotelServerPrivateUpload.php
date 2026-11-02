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
            if($chunk!==null)return self::chunk($dir,$ticket,$input,$chunk,$chunkSha,$expectedLength);
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

    /** Fixed small request bodies also work behind the observed 128 KiB FastCGI boundary. */
    private static function chunk(string $dir,array $ticket,$input,int $index,string $sha,int $bytes): array
    {
        $hold=['code'=>409,'body'=>['ok'=>false,'state'=>'payload_unconfirmed']];$chunks=$dir.'/chunks';
        if(!file_exists($chunks)&&!is_link($chunks)&&!mkdir($chunks,0700))return $hold;
        clearstatcache(true,$chunks);
        if(is_link($chunks)||realpath($chunks)!==$chunks||!is_dir($chunks)||(fileperms($chunks)&0777)!==0700||fileowner($chunks)!==posix_geteuid())return $hold;
        $path=$chunks.'/'.sprintf('%03d',$index).'.bin';
        if(!self::writeChunk($input,$path,$bytes,$sha))return $hold;
        $count=(int)ceil($ticket['payload_bytes']/self::CHUNK_BYTES);$paths=[];$missing=false;
        for($i=0;$i<$count;$i++){
            $p=$chunks.'/'.sprintf('%03d',$i).'.bin';
            if(file_exists($p.'.part')||is_link($p.'.part'))return $hold;
            if(!file_exists($p)&&!is_link($p)){$missing=true;continue;}
            $size=min(self::CHUNK_BYTES,$ticket['payload_bytes']-$i*self::CHUNK_BYTES);
            if(!self::privateFile($p)||filesize($p)!==$size)return $hold;$paths[]=$p;
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
        if(!rename($partial,$final)||!self::privateFile($final)||filesize($final)!==$ticket['payload_bytes']
            ||!hash_equals($ticket['payload_sha256'],hash_file('sha256',$final)))return $hold;
        return self::stored($ticket);
    }

    /** A failed fragment remains present. A completed fragment can only be read back exactly. */
    private static function writeChunk($input,string $path,int $bytes,string $sha): bool
    {
        if(file_exists($path)||is_link($path))return self::privateFile($path)&&filesize($path)===$bytes&&hash_equals($sha,hash_file('sha256',$path));
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
        return rename($partial,$path)&&self::privateFile($path)&&filesize($path)===$bytes&&hash_equals($sha,hash_file('sha256',$path));
    }

    private static function stored(array $ticket): array
    {return ['code'=>200,'body'=>['ok'=>true,'state'=>'stored','bytes'=>$ticket['payload_bytes'],'sha256'=>$ticket['payload_sha256']]];}
    private static function privateFile(string $path): bool
    {clearstatcache(true,$path);return !is_link($path)&&is_file($path)&&(fileperms($path)&0777)===0600
        &&(!function_exists('posix_geteuid')||fileowner($path)===posix_geteuid());}
}
