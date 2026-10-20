<?php
declare(strict_types=1);

require_once __DIR__.'/ManagerAiDraftReferenceContext.php';

/** Explicit operations only. Never invoked by a customer request or cron. */
final class RouteCacheRestorer
{
    private const MAX_BYTES = 8388608;

    public static function installAbsent(string $source, string $target): array
    {
        clearstatcache(true,$target);
        if (@lstat($target)!==false) return ['ok'=>true,'result'=>'already_present'];
        $stat=@stat($source);
        if (!is_array($stat) || ($stat['mode'] & 0170000)!==0100000) return ['ok'=>false,'result'=>'source_unavailable'];
        $temporary=null;$input=null;$output=null;
        try {
            $input=@fopen($source,'rb');
            if (!$input) return ['ok'=>false,'result'=>'source_unavailable'];
            $stat=fstat($input);
            if (!is_array($stat) || $stat['size']>self::MAX_BYTES) return ['ok'=>false,'result'=>'source_too_large'];
            $temporary=@tempnam(dirname($target),'.route-cache-');
            if ($temporary===false) return ['ok'=>false,'result'=>'staging_failed'];
            $output=@fopen($temporary,'wb');
            if (!$output || @stream_copy_to_stream($input,$output,self::MAX_BYTES+1)===false || !@fflush($output)) return ['ok'=>false,'result'=>'staging_failed'];
            fclose($output);$output=null;fclose($input);$input=null;
            $health=ManagerAiDraftReferenceContext::routeCacheHealth($temporary);
            if ($health['status']!=='available' || ($health['departure_count']??0)<1) return ['ok'=>false,'result'=>'source_invalid','source_reason'=>$health['reason']];
            // Preserve provenance: copying an old cache must never make it fresh.
            if (!@touch($temporary,(int)$stat['mtime']) || !@chmod($temporary,0644)) return ['ok'=>false,'result'=>'staging_failed'];
            $hash=@hash_file('sha256',$temporary);
            if (!is_string($hash)) return ['ok'=>false,'result'=>'staging_failed'];
            // Hard-link creation is atomic and cannot replace a concurrent writer.
            if (!@link($temporary,$target)) {
                clearstatcache(true,$target);
                return @lstat($target)!==false ? ['ok'=>true,'result'=>'already_present'] : ['ok'=>false,'result'=>'install_failed'];
            }
            return ['ok'=>true,'result'=>'installed','sha256'=>$hash,
                'health'=>ManagerAiDraftReferenceContext::routeCacheHealth($target)];
        } catch (Throwable $ignored) {return ['ok'=>false,'result'=>'install_failed'];}
        finally {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
        }
    }
}
