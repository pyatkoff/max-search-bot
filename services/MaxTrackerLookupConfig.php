<?php
declare(strict_types=1);
final class MaxTrackerLookupConfig
{
    public static function endpoint(): string { return 'https://tour-max.ru/new/max-attribution/lookup.php'; }
    public static function privateKeyPath(): string { return dirname(__DIR__,3).'/private/max-attribution/signing.pem'; }
    public static function csvDirectory(): string
    {
        $path=dirname(self::privateKeyPath()).'/csv';
        if(!is_dir($path)&&!mkdir($path,0700,true))throw new RuntimeException('private_csv_unavailable');
        if(is_link($path)||(fileperms($path)&0077)!==0)throw new RuntimeException('private_csv_unavailable');
        return $path;
    }
    public static function privateKey(): string
    {
        $path=self::privateKeyPath();
        if(!is_file($path)||!is_readable($path)||filesize($path)>16384||(fileperms($path)&0077)!==0)throw new RuntimeException('tracker_signing_key_unavailable');
        $key=file_get_contents($path);if(!is_string($key)||$key==='')throw new RuntimeException('tracker_signing_key_unavailable');
        return $key;
    }
}
