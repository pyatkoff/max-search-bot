<?php
declare(strict_types=1);

require_once __DIR__.'/DiagnosticLogger.php';

/** Bounded, private operational checkpoint; never contains jobs, payloads or credentials. */
final class ManagerPreparationHealth
{
    private const STAGES = ['bootstrap','subscription','collection','enqueue','delivery','complete'];
    private const COUNTS = ['candidates','captured','inserted','existing','skipped','subscriptions','accepted','retried','stopped','unchanged'];

    public static function record(string $state, string $stage, array $counts = []): void
    {
        if (!in_array($state,['running','ok','awaiting_connection','failed'],true) || !in_array($stage,self::STAGES,true)) return;
        try {
            $file=self::path();
            if ($file==='') return;
            $mask=umask(0077);
            try {$handle=@fopen($file,'c+b');} finally {umask($mask);}
            if (!$handle) return;
            try {
                if (!self::regular($file,$handle) || !@chmod($file,0600) || !@flock($handle,LOCK_EX|LOCK_NB)) return;
                $prior=self::read($handle);$now=gmdate('Y-m-d\TH:i:s\Z');
                $row=['state'=>$state,'stage'=>$stage,'last_started_at'=>$state==='running'?$now:($prior['last_started_at']??null),
                    'last_finished_at'=>$state==='running'?($prior['last_finished_at']??null):$now,
                    'last_success_at'=>$state==='ok'?$now:($prior['last_success_at']??null),
                    'last_error_at'=>$state==='failed'?$now:($prior['last_error_at']??null),
                    'last_error_stage'=>$state==='failed'?$stage:($prior['last_error_stage']??null),
                    'counts'=>self::counts($counts)];
                $json=json_encode($row,JSON_THROW_ON_ERROR);
                rewind($handle);
                if (@fwrite($handle,$json)!==strlen($json)) return;
                @ftruncate($handle,strlen($json));@fflush($handle);
            } finally { @flock($handle,LOCK_UN);fclose($handle); }
        } catch (Throwable $ignored) { /* Observability never blocks preparation. */ }
    }

    public static function snapshot(?int $now = null): array
    {
        $missing=['available'=>false,'state'=>'unknown','stale'=>true];
        try {
            $file=self::path();
            if ($file==='' || !is_file($file)) return $missing;
            $handle=@fopen($file,'rb');if (!$handle) return $missing;
            try {
                if (!self::regular($file,$handle) || ((int)fstat($handle)['mode'] & 0077)!==0 || !@flock($handle,LOCK_SH|LOCK_NB)) return $missing;
                $row=self::read($handle);
            } finally {@flock($handle,LOCK_UN);fclose($handle);}
            if (!$row) return $missing;
            $at=strtotime((string)($row['state']==='running'?$row['last_started_at']:$row['last_finished_at']));
            $age=($now??time())-($at===false?0:$at);
            return ['available'=>true,'stale'=>$at===false || $age<0 || $age>180,'age_seconds'=>max(0,$age)]+$row;
        } catch (Throwable $ignored) {return $missing;}
    }

    private static function read($handle): array
    {
        rewind($handle);
        if ((int)(fstat($handle)['size']??0)>8192) return [];
        $raw=json_decode((string)stream_get_contents($handle,8192),true);
        if (!is_array($raw) || !in_array($raw['state']??null,['running','ok','awaiting_connection','failed'],true)
            || !in_array($raw['stage']??null,self::STAGES,true)) return [];
        $row=['state'=>$raw['state'],'stage'=>$raw['stage']];
        foreach (['last_started_at','last_finished_at','last_success_at','last_error_at'] as $key) {
            $value=$raw[$key]??null;
            $row[$key]=is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$value)?$value:null;
        }
        $row['last_error_stage']=in_array($raw['last_error_stage']??null,self::STAGES,true)?$raw['last_error_stage']:null;
        $row['counts']=self::counts(is_array($raw['counts']??null)?$raw['counts']:[]);
        if ($row['last_started_at']===null || ($row['state']!=='running' && $row['last_finished_at']===null)) return [];
        return $row;
    }

    private static function counts(array $values): array
    {
        $out=[];
        foreach (self::COUNTS as $key) if (is_int($values[$key]??null) && $values[$key]>=0 && $values[$key]<=1000000) $out[$key]=$values[$key];
        return $out;
    }

    private static function path(): string
    {
        $log=DiagnosticLogger::filePath();if ($log==='') return '';
        $file=dirname($log).'/manager_preparation_health.json';
        clearstatcache(true,$file);$stat=@lstat($file);
        return $stat!==false && ($stat['mode'] & 0170000)!==0100000?'':$file;
    }

    private static function regular(string $file, $handle): bool
    {
        clearstatcache(true,$file);$named=@lstat($file);$opened=@fstat($handle);
        return is_array($named) && is_array($opened) && ($named['mode'] & 0170000)===0100000
            && $named['dev']===$opened['dev'] && $named['ino']===$opened['ino'];
    }
}
