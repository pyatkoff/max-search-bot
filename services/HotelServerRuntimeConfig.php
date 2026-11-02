<?php
declare(strict_types=1);

/** Hotel-only private configuration. Reading never imports, enables or sends. */
final class HotelServerRuntimeConfig
{
    public static function load(string $applicationRoot): ?array
    {
        $app=realpath($applicationRoot);
        if($app===false||!is_dir($app))throw new DomainException('hotel_runtime_application_required');
        $dir=dirname($app).'/max-search-bot-hotel-server';$file=$dir.'/runtime.json';
        clearstatcache(true,$file);
        if(!file_exists($file)&&!is_link($file))return null;
        if(is_link($dir)||realpath($dir)!==$dir||!is_dir($dir)||(fileperms($dir)&0777)!==0700
            ||is_link($file)||!is_file($file)||(fileperms($file)&0777)!==0600)
            throw new DomainException('hotel_runtime_private_file_required');
        $before=lstat($file);
        if(!is_array($before)||$before['size']<1||$before['size']>65536
            ||(function_exists('posix_geteuid')&&$before['uid']!==posix_geteuid()))
            throw new DomainException('hotel_runtime_private_file_required');
        $handle=fopen($file,'rb');
        if($handle===false)throw new DomainException('hotel_runtime_private_file_required');
        try{
            $opened=fstat($handle);
            if(!is_array($opened)||$opened['dev']!==$before['dev']||$opened['ino']!==$before['ino']
                ||$opened['uid']!==$before['uid']||($opened['mode']&0777)!==0600)
                throw new DomainException('hotel_runtime_private_file_required');
            $raw=stream_get_contents($handle,65537);
        }finally{fclose($handle);}
        if(!is_string($raw)||strlen($raw)!==$before['size']||strlen($raw)>65536)
            throw new DomainException('hotel_runtime_configuration_changed');
        try{$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $ignored){
            throw new DomainException('hotel_runtime_configuration_invalid');
        }
        if(!is_array($v)||!self::keys($v,['bootstrap_sha256','campaign_id','flags','policy','schema'])
            ||($v['schema']??null)!=='hotel-server-runtime-v1'
            ||!is_string($v['campaign_id']??null)
            ||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D',$v['campaign_id'])
            ||$v['campaign_id']==='HOTEL-CONTINUOUS-20261027'
            ||!is_string($v['bootstrap_sha256']??null)
            ||!preg_match('/\A[a-f0-9]{64}\z/D',$v['bootstrap_sha256'])
            ||!is_array($v['flags']??null)
            ||!self::keys($v['flags'],['dispatch_allowed','enabled','replacement_dispatch_allowed'])
            ||!is_array($v['policy']??null)||array_is_list($v['policy']))
            throw new DomainException('hotel_runtime_configuration_invalid');
        foreach($v['flags'] as $flag)if(!is_bool($flag))throw new DomainException('hotel_runtime_configuration_invalid');
        return $v+['configuration_sha256'=>hash('sha256',$raw)];
    }

    private static function keys(array $value,array $expected): bool
    { $keys=array_keys($value);sort($keys);sort($expected);return $keys===$expected; }
}
