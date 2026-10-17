<?php

declare(strict_types=1);

require_once __DIR__.'/DiagnosticLogger.php';

/** Observations only: never create an installation, receipt or subscription. */
final class ManagerPushSetupDiagnostics
{
    public const MAX_BODY_BYTES = 4096;
    private const CODES = [
        'UNSUPPORTED','PERMISSION_NOT_GRANTED','TIMEOUT','KEY_RESPONSE','SAVE_RESPONSE','UNHEALTHY','FAILED',
        'NotAllowedError','AbortError','SecurityError','InvalidStateError','NotSupportedError',
        'InvalidAccessError','NetworkError','TypeError','OperationError','DataError',
        'AbortError/PUSH_SERVICE','AbortError/PUSH_NETWORK','AbortError/PUSH_UNAVAILABLE',
        'AbortError/PUSH_STORAGE','AbortError/PUSH_KEY',
    ];

    public static function project($input, int $managerId, string $agent): ?array
    {
        if (!is_array($input) || ($input['stage'] ?? null) !== 'subscription_create'
            || !in_array($input['code'] ?? null,self::CODES,true)
            || !self::integer($input['elapsed_ms'] ?? null,600000)
            || !in_array($input['permission'] ?? null,['granted','denied','default','unknown'],true)
            || !self::integer($input['key_length'] ?? null,512)) return null;
        foreach (['secure_context','worker_active','worker_scope_matches','web_locks','key_uncompressed'] as $field) {
            if (!is_bool($input[$field] ?? null)) return null;
        }
        $trace=$input['trace'] ?? null;
        if (!is_array($trace) || !array_is_list($trace) || count($trace)>3) return null;
        $safeTrace=[];
        foreach ($trace as $entry) {
            if (!is_array($entry) || !in_array($entry['call'] ?? null,['subscribe','recovery_read','retry_subscribe'],true)
                || !in_array($entry['outcome'] ?? null,array_merge(['subscription','none'],self::CODES),true)
                || !self::integer($entry['duration_ms'] ?? null,600000)) return null;
            $safeTrace[]=['call'=>$entry['call'],'outcome'=>$entry['outcome'],'duration_ms'=>$entry['duration_ms']];
        }
        return ['schema'=>1,'manager_id'=>$managerId] + self::browser($agent) + [
            'stage'=>'subscription_create','code'=>$input['code'],'elapsed_ms'=>$input['elapsed_ms'],
            'permission'=>$input['permission'],'secure_context'=>$input['secure_context'],
            'worker_active'=>$input['worker_active'],'worker_scope_matches'=>$input['worker_scope_matches'],
            'web_locks'=>$input['web_locks'],'key_length'=>$input['key_length'],
            'key_uncompressed'=>$input['key_uncompressed'],'trace'=>$safeTrace,
        ];
    }

    /** Sampled observations, not provider causes, delivery proof or health gates. */
    public static function summary(array $events): array
    {
        $summary=['source'=>'client_reported','sample_limit'=>50,'failure_count'=>0,
            'by_code'=>[],'by_call'=>[],'latest'=>null,'native_cause_available'=>false];
        foreach (array_slice($events,0,50) as $event) {
            if (!is_array($event) || ($event['component'] ?? null)!=='manager_push'
                || ($event['event'] ?? null)!=='setup_failed') continue;
            $data=$event['data'] ?? null;
            if (!is_array($data) || ($data['schema'] ?? null)!==1
                || !in_array($data['browser_family'] ?? null,['Edge','Firefox','Chrome','Safari','Other'],true)
                || !self::integer($data['browser_major'] ?? null,999)
                || !in_array($data['os_family'] ?? null,['iOS','Android','Windows','macOS','Linux','Other'],true)) continue;
            $safe=self::project($data,0,'');
            if ($safe===null || !$safe['trace']) continue;
            $terminal=$safe['trace'][count($safe['trace'])-1];
            if ($terminal['outcome']!==$safe['code']) continue;
            $summary['failure_count']++;
            $summary['by_code'][$safe['code']]=($summary['by_code'][$safe['code']] ?? 0)+1;
            $summary['by_call'][$terminal['call']]=($summary['by_call'][$terminal['call']] ?? 0)+1;
            if ($summary['latest']===null) {
                unset($safe['manager_id']);
                foreach (['browser_family','browser_major','os_family'] as $field) $safe[$field]=$data[$field];
                $stamp=self::timestamp($event['ts'] ?? null);
                if ($stamp!==null) $safe['ts']=$stamp;
                $summary['latest']=$safe;
            }
        }
        return $summary;
    }

    /** Claim while the authenticated PHP session lock is still held. */
    public static function claim(array &$session, int $now): bool
    {
        $last=$session['manager_push_setup_diagnostic_at'] ?? null;
        if (is_int($last) && $now-$last<60) return false;
        $session['manager_push_setup_diagnostic_at']=$now;
        return true;
    }

    public static function write(array $event): void
    {
        try { DiagnosticLogger::warning('manager_push','setup_failed',$event); }
        catch (Throwable $ignored) { /* Reporting can never change setup's result. */ }
    }

    private static function integer($value, int $maximum): bool
    {
        return is_int($value) && $value>=0 && $value<=$maximum;
    }

    private static function timestamp($value): ?string
    {
        if (!is_string($value) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D',$value)) return null;
        $normalized=str_ends_with($value,'Z')?substr($value,0,-1).'+00:00':$value;
        $stamp=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$normalized);
        if ($stamp===false || $stamp->format('Y-m-d\TH:i:sP')!==$normalized) return null;
        return $stamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function browser(string $agent): array
    {
        $agent=substr($agent,0,1024);$family='Other';$major=0;
        foreach (['Edge'=>'/Edg[A-Z]*\/([0-9]{1,6})/i','Firefox'=>'/(?:Firefox|FxiOS)\/([0-9]{1,6})/i',
            'Chrome'=>'/(?:Chrome|CriOS)\/([0-9]{1,6})/i','Safari'=>'/Version\/([0-9]{1,6}).*Safari\//i'] as $candidate=>$pattern) {
            if (preg_match($pattern,$agent,$match)) {$family=$candidate;$major=min(999,(int)$match[1]);break;}
        }
        $os=preg_match('/iPhone|iPad|iPod/i',$agent)?'iOS':(str_contains($agent,'Android')?'Android':
            (str_contains($agent,'Windows')?'Windows':(str_contains($agent,'Mac OS')?'macOS':
            (str_contains($agent,'Linux')?'Linux':'Other'))));
        return ['browser_family'=>$family,'browser_major'=>$major,'os_family'=>$os];
    }
}
