<?php
declare(strict_types=1);
require_once __DIR__.'/DiagnosticLogger.php';

/** Private bounded immutable image bytes; never follows source redirects. */
final class TourSelectionPhotoStore
{
    public const MAX_BYTES=2097152;

    public static function offerKey(int $offer): string
    {
        if ($offer<=0) throw new InvalidArgumentException('invalid_offer');
        return substr(hash('sha256','tour-offer:'.$offer),0,32);
    }

    /** Public readers may read reviewed bytes, never initiate source downloads. */
    public static function cached(string $publication,int $card,int $photo,string $url): ?array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$publication) || $card<0 || $card>=20 || $photo<0 || $photo>=3 || self::source($url)===null) return null;
        $log=DiagnosticLogger::filePath();
        if ($log==='' || $log[0]!=='/') return null;
        $root=dirname($log).'/tour-selection-photos';
        $base=$root.'/'.hash('sha256',$publication.':'.$card.':'.$photo.':'.$url);
        if (is_link($root) || is_link($base.'.json') || is_link($base.'.bin') || !is_file($base.'.json') || !is_file($base.'.bin') || filesize($base.'.json')>4096) return null;
        $meta=json_decode((string)file_get_contents($base.'.json'),true);
        if (!is_array($meta) || ($meta['source_hash']??null)!==hash('sha256',$url) || !in_array($meta['mime']??null,['image/jpeg','image/png'],true)
            || !is_int($meta['bytes']??null) || $meta['bytes']<=0 || $meta['bytes']>self::MAX_BYTES || filesize($base.'.bin')!==$meta['bytes']
            || ($meta['sha256']??null)!==hash_file('sha256',$base.'.bin')) return null;
        return $meta+['path'=>$base.'.bin'];
    }

    private const UPLOAD_URL='https://app.anytoour.ru/manager/tour-selection-photo.php?';

    /** Same private byte owner and quota as reviewed images; no public asset URL. */
    public static function upload(int $actor,int $conversation,string $offer,string $kind,string $operation,string $bytes): array
    {
        if ($actor<=0 || $conversation<=0 || !preg_match('/\A[a-f0-9]{32}\z/D',$offer) || !preg_match('/\A[a-f0-9]{32}\z/D',$operation) || !in_array($kind,['cover','beach','room'],true)) throw new RuntimeException('invalid_photo');
        $key=substr(hash('sha256','draft-upload:'.$actor.':'.$conversation.':'.$offer.':'.$kind.':'.$operation),0,32);
        $url=self::uploadUrl($conversation,$key);
        $image=self::image($key,0,0,$url,static fn(string $unused):string=>$bytes);
        // Same operation is immutable, including a concurrent different upload.
        if (!hash_equals($image['sha256'],hash('sha256',$bytes))) throw new RuntimeException('operation_mismatch');
        return ['url'=>$url,'sha256'=>$image['sha256'],'bytes'=>$image['bytes'],'mime'=>$image['mime']];
    }

    public static function uploadUrl(int $conversation,string $key): string
    {
        return self::UPLOAD_URL.'conversation_id='.$conversation.'&draft_photo='.$key;
    }

    /** Bounded resumable intake for servers with a small request-body limit. */
    public static function uploadChunk(int $actor,int $conversation,string $offer,string $kind,string $operation,string $chunk,int $index,int $count,int $total,string $sha): array
    {
        if ($actor<=0 || $conversation<=0 || !preg_match('/\A[a-f0-9]{32}\z/D',$offer) || !preg_match('/\A[a-f0-9]{32}\z/D',$operation) || !in_array($kind,['cover','beach','room'],true)
            || $total<1 || $total>self::MAX_BYTES || $count!==intdiv($total+71999,72000) || $index<0 || $index>=$count || !preg_match('/\A[a-f0-9]{64}\z/D',$sha) || strlen($chunk)!==min(72000,$total-$index*72000)) throw new RuntimeException('invalid_photo');
        $key=substr(hash('sha256','draft-upload:'.$actor.':'.$conversation.':'.$offer.':'.$kind.':'.$operation),0,32);$url=self::uploadUrl($conversation,$key);
        $root=self::root();$base=$root.'/'.hash('sha256',$key.':0:0:'.$url);$part=$base.'.part';$manifest=$base.'.upload.json';$lockPath=$base.'.upload.lock';
        foreach ([$part,$manifest,$lockPath] as $path) if (is_link($path)) throw new RuntimeException('photo_storage_unavailable');
        if (!is_file($part) && !is_file($base.'.json') && self::retainedCount($root)>=1024) throw new RuntimeException('photo_capacity');
        $mask=umask(0077);$lock=fopen($lockPath,'c+');umask($mask);
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) {if($lock)fclose($lock);throw new RuntimeException('photo_busy');}
        try {
            $existing=self::uploaded($conversation,$key);
            if ($existing) {
                $slice=file_get_contents($existing['path'],false,null,$index*72000,strlen($chunk));
                if (!hash_equals($existing['sha256'],$sha) || !is_string($slice) || !hash_equals(hash('sha256',$slice),hash('sha256',$chunk))) throw new RuntimeException('operation_mismatch');
                if(is_file($part))unlink($part);if(is_file($manifest))unlink($manifest);
                return array_intersect_key($existing,array_flip(['sha256','bytes','mime']))+['url'=>$url];
            }
            $meta=['bytes'=>$total,'sha256'=>$sha,'count'=>$count,'parts'=>[]];
            if (is_file($manifest)) {
                if (filesize($manifest)>16384) throw new RuntimeException('photo_storage_unavailable');
                $stored=json_decode((string)file_get_contents($manifest),true);
                if (!is_array($stored) || ($stored['bytes']??null)!==$total || ($stored['sha256']??null)!==$sha || ($stored['count']??null)!==$count || !is_array($stored['parts']??null) || count($stored['parts'])>$count) throw new RuntimeException('operation_mismatch');
                $meta=$stored;
            } else self::write($manifest,json_encode($meta,JSON_THROW_ON_ERROR));
            $chunkHash=hash('sha256',$chunk);
            if (isset($meta['parts'][$index]) && !hash_equals($meta['parts'][$index],$chunkHash)) throw new RuntimeException('operation_mismatch');
            if (!is_file($part) && $meta['parts']!==[]) throw new RuntimeException('photo_storage_unavailable');
            if (!is_file($part) && self::retainedCount($root)>=1024) throw new RuntimeException('photo_capacity');
            $mask=umask(0077);$file=fopen($part,'c+b');umask($mask);
            if (!$file) throw new RuntimeException('photo_storage_unavailable');
            try {
                if (!ftruncate($file,$total) || fseek($file,$index*72000)!==0 || fwrite($file,$chunk)!==strlen($chunk) || !fflush($file) || !fsync($file)) throw new RuntimeException('photo_storage_unavailable');
            } finally {fclose($file);}
            $meta['parts'][$index]=$chunkHash;self::write($manifest,json_encode($meta,JSON_THROW_ON_ERROR));
            if (count($meta['parts'])<$count) return ['state'=>'receiving','part_index'=>$index,'received'=>count($meta['parts'])];
            $bytes=file_get_contents($part);
            if (!is_string($bytes) || strlen($bytes)!==$total || !hash_equals($sha,hash('sha256',$bytes))) {unlink($part);unlink($manifest);throw new RuntimeException('invalid_photo');}
            try {$image=self::upload($actor,$conversation,$offer,$kind,$operation,$bytes);}
            catch (RuntimeException $e) {if($e->getMessage()==='invalid_photo'){unlink($part);unlink($manifest);}throw $e;}
            unlink($part);unlink($manifest);return $image;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }

    private static function root(): string
    {
        $log=DiagnosticLogger::filePath();
        if ($log==='' || $log[0]!=='/') throw new RuntimeException('photo_storage_unavailable');
        $root=dirname($log).'/tour-selection-photos';
        if (is_link($root) || (!is_dir($root) && !mkdir($root,0700)) || !chmod($root,0700)) throw new RuntimeException('photo_storage_unavailable');
        return $root;
    }

    private static function retainedCount(string $root): int {return count(glob($root.'/*.bin')?:[])+count(glob($root.'/*.part')?:[]);}

    private static function uploadReference(string $url): ?array
    {
        if (!preg_match('~\A'.preg_quote(self::UPLOAD_URL,'~').'conversation_id=([1-9][0-9]*)&draft_photo=([a-f0-9]{32})\z~D',$url,$m) || (string)(int)$m[1]!==$m[1]) return null;
        return ['conversation'=>(int)$m[1],'key'=>$m[2]];
    }

    public static function uploaded(int $conversation,string $key): ?array
    {
        if ($conversation<=0 || !preg_match('/\A[a-f0-9]{32}\z/D',$key)) return null;
        return self::cached($key,0,0,self::uploadUrl($conversation,$key));
    }

    /** Uploaded references cannot be carried to another client, even by an admin. */
    public static function forConversation(string $url,int $conversation): bool
    {
        if (!str_starts_with($url,self::UPLOAD_URL)) return true;
        $ref=self::uploadReference($url);
        return $ref!==null && $ref['conversation']===$conversation && self::uploaded($conversation,$ref['key'])!==null;
    }

    private static function sourceBytes(string $url): string
    {
        if (!str_starts_with($url,self::UPLOAD_URL)) return self::download($url);
        $ref=self::uploadReference($url);$image=$ref?self::uploaded($ref['conversation'],$ref['key']):null;
        if (!$image) throw new RuntimeException('invalid_photo');
        return (string)file_get_contents($image['path']);
    }

    public static function publicAddress(string $ip): bool
    {
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false;
        $value=unpack('N',inet_pton($ip))[1];
        foreach ([['0.0.0.0',8],['100.64.0.0',10],['192.0.0.0',24],['192.0.2.0',24],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',4],['240.0.0.0',4]] as [$base,$bits]) {
            $mask=(0xffffffff << (32-$bits)) & 0xffffffff;
            if (($value & $mask)===(unpack('N',inet_pton($base))[1] & $mask)) return false;
        }
        return true;
    }

    public static function source(string $url): ?array
    {
        if (strlen($url)>2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$url) || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i',$url)) return null;
        $p=parse_url($url);
        if (!is_array($p) || ($p['scheme']??'')!=='https' || isset($p['port']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) return null;
        $host=$p['host']??'';
        if (!preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/iD',$host)
            || preg_match('/\.(?:localhost|local|internal|test|invalid|example|onion)\z/iD',$host)) return null;
        return ['url'=>$url,'host'=>strtolower($host)];
    }

    public static function image(string $publication,int $card,int $photo,string $url,?callable $download=null): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$publication) || $card<0 || $card>=20 || $photo<0 || $photo>=3 || self::source($url)===null) throw new RuntimeException('invalid_photo');
        $root=self::root();
        $key=hash('sha256',$publication.':'.$card.':'.$photo.':'.$url);$base=$root.'/'.$key;
        if (is_link($base.'.lock')) throw new RuntimeException('photo_storage_unavailable');
        $mask=umask(0077);$lock=fopen($base.'.lock','c+');umask($mask);
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) {if ($lock) fclose($lock);throw new RuntimeException('photo_busy');}
        try {
            if (is_link($base.'.json') || is_link($base.'.bin')) throw new RuntimeException('photo_storage_unavailable');
            if (is_file($base.'.json')) {
                $meta=json_decode((string)file_get_contents($base.'.json'),true);
                if (!is_array($meta) || ($meta['source_hash']??null)!==hash('sha256',$url) || !is_file($base.'.bin') || ($meta['sha256']??null)!==hash_file('sha256',$base.'.bin')) throw new RuntimeException('photo_storage_unavailable');
                return $meta+['path'=>$base.'.bin'];
            }
            // Hard bound on retained private material; no opportunistic removal of sent photos.
            if (self::retainedCount($root)-(is_file($base.'.part')?1:0)>=1024) throw new RuntimeException('photo_capacity');
            $bytes=$download?$download($url):self::sourceBytes($url);
            if (!is_string($bytes) || $bytes==='' || strlen($bytes)>self::MAX_BYTES) throw new RuntimeException('invalid_photo');
            $size=getimagesizefromstring($bytes);$mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (!$size || !in_array($mime,['image/jpeg','image/png'],true) || ($size['mime']??'')!==$mime || $size[0]<100 || $size[1]<100 || $size[0]>12000 || $size[1]>12000 || $size[0]*$size[1]>24000000) throw new RuntimeException('invalid_photo');
            $meta=['source_hash'=>hash('sha256',$url),'sha256'=>hash('sha256',$bytes),'mime'=>$mime,'bytes'=>strlen($bytes),'width'=>$size[0],'height'=>$size[1]];
            self::write($base.'.bin',$bytes);self::write($base.'.json',json_encode($meta,JSON_THROW_ON_ERROR));
            return $meta+['path'=>$base.'.bin'];
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }

    private static function download(string $url): string
    {
        $source=self::source($url);$records=dns_get_record($source['host'],DNS_A);
        if (!is_array($records) || !$records) throw new RuntimeException('photo_unavailable');
        $addresses=array_values(array_unique(array_column($records,'ip')));
        if (!$addresses) throw new RuntimeException('photo_unavailable');
        foreach ($addresses as $ip) if (!self::publicAddress($ip)) throw new RuntimeException('invalid_photo');
        $bytes='';$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_PROXY=>'',
            CURLOPT_RESOLVE=>[$source['host'].':443:'.$addresses[0]],CURLOPT_HTTPHEADER=>['Accept: image/jpeg,image/png'],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$bytes):int {if (strlen($bytes)+strlen($chunk)>self::MAX_BYTES) return 0;$bytes.=$chunk;return strlen($chunk);},
        ]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_errno($ch);curl_close($ch);
        if ($ok===false || $error || $status!==200 || $bytes==='') throw new RuntimeException('photo_unavailable');
        return $bytes;
    }

    private static function write(string $path,string $bytes): void
    {
        $tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';$mask=umask(0077);$f=fopen($tmp,'xb');umask($mask);
        if (!$f) throw new RuntimeException('photo_storage_unavailable');
        try {if (fwrite($f,$bytes)!==strlen($bytes) || !fflush($f) || !fsync($f)) throw new RuntimeException('photo_storage_unavailable');}
        finally {fclose($f);}
        if (!rename($tmp,$path)) {unlink($tmp);throw new RuntimeException('photo_storage_unavailable');}
        $dir=fopen(dirname($path),'r');if (!$dir) throw new RuntimeException('photo_storage_unavailable');
        try {if (!fsync($dir)) throw new RuntimeException('photo_storage_unavailable');} finally {fclose($dir);}
    }
}
