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
        $log=DiagnosticLogger::filePath();
        if ($log==='' || $log[0]!=='/') throw new RuntimeException('photo_storage_unavailable');
        $root=dirname($log).'/tour-selection-photos';
        if (is_link($root) || (!is_dir($root) && !mkdir($root,0700)) || !chmod($root,0700)) throw new RuntimeException('photo_storage_unavailable');
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
            if (count(glob($root.'/*.bin')?:[])>=1024) throw new RuntimeException('photo_capacity');
            $bytes=$download?$download($url):self::download($url);
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
