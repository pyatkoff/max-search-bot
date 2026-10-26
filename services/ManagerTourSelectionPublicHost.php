<?php
declare(strict_types=1);
require_once __DIR__.'/DiagnosticLogger.php';

/** Deployment readiness only; publications, permissions and identity stay canonical. */
final class ManagerTourSelectionPublicHost
{
    public const ORIGIN='https://tours.anytoour.ru';
    public const CONTRACT='selection-public-host-v1';

    public static function fingerprint(?string $root=null): string
    {
        $root??=dirname(__DIR__);
        $spec=json_decode((string)file_get_contents($root.'/modules/tour-selections/module.json'),true,16,JSON_THROW_ON_ERROR);
        $paths=array_unique(['modules/tour-selections/module.json',...array_values($spec['files']),...$spec['backend_inputs']]);sort($paths,SORT_STRING);
        $hash=hash_init('sha256');
        foreach ($paths as $path) {
            $file=$root.'/'.$path;
            if (!is_string($path) || str_contains($path,'..') || is_link($file) || !is_file($file)) throw new RuntimeException('host_input_unavailable');
            hash_update($hash,$path."\0".hash_file('sha256',$file)."\n");
        }
        return hash_final($hash);
    }

    public static function markerPath(): string
    {
        $log=DiagnosticLogger::filePath();
        if ($log==='' || $log[0]!=='/') throw new RuntimeException('host_marker_unavailable');
        return dirname($log).'/selection-public-host.json';
    }

    public static function url(string $path): ?string
    {
        if (!preg_match('~\A/selection/\?t=[a-f0-9]{64}(?:&preview=[0-9]{10}\.[a-f0-9]{64})?\z~D',$path)) return null;
        try {
            $file=self::markerPath();
            if (is_link($file) || !is_file($file) || filesize($file)>4096) return null;
            $receipt=json_decode((string)file_get_contents($file),true,8,JSON_THROW_ON_ERROR);
            if (($receipt['contract']??null)!==self::CONTRACT || ($receipt['origin']??null)!==self::ORIGIN
                || ($receipt['fingerprint']??null)!==self::fingerprint()) return null;
            return self::ORIGIN.'/?'.substr($path,strlen('/selection/?'));
        } catch (Throwable $ignored) {return null;}
    }
}
