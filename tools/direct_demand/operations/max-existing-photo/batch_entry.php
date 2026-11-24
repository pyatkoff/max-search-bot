<?php
declare(strict_types=1);

const MAX_BATCH_SCOPE_SHA256 = '0cff4a47fc29fba92eef0eea02bba7d88de334b66d590ef85af852c76d8e30ee';

function maxBatchOriginalSourceApproved(?array $binding, string $digest, string $repo, string $app): bool
{
    return $binding !== null && is_int($binding['run_id'] ?? null) && $binding['run_id'] > 0
        && is_int($binding['artifact_id'] ?? null) && $binding['artifact_id'] > 0
        && ($binding['json_sha256'] ?? null) === $digest && ($binding['repository_sha'] ?? null) === $repo
        && ($binding['application_sha'] ?? null) === $app;
}

function maxBatchLoad(string $archivePath, string $nativePath, string $trustedNativeDigest, string $nativeRef, string $app): array
{
    $bytes = static function(string $file): string {
        maxEditNeed(!is_link($file) && is_file($file) && filesize($file) < 2000000, 'invalid_input');
        $contents = file_get_contents($file);
        maxEditNeed(is_string($contents), 'invalid_input');
        return $contents;
    };
    $scopeBytes=$bytes(__DIR__.'/scope.json');
    maxEditNeed(hash_equals(MAX_BATCH_SCOPE_SHA256,hash('sha256',$scopeBytes)), 'approval_content_changed');
    $scope=json_decode($scopeBytes,true,32,JSON_THROW_ON_ERROR);
    maxEditNeed(maxBatchOriginalSourceApproved($scope['native_source'] ?? null,$trustedNativeDigest,$nativeRef,$app),'original_native_source_not_admitted');
    return maxBatchInput($scopeBytes,MAX_BATCH_SCOPE_SHA256,
        $bytes($archivePath),$bytes($nativePath),$trustedNativeDigest,$nativeRef,$app);
}

/** Called exclusively by the existing editor entry point; it performs the same checks and writes. */
function maxBatchEntry(array $argv): int
{
    $path = null;
    try {
        $mode = $argv[1];
        if ($mode === '--batch-plan') {
            maxEditNeed(count($argv)===7, 'exact_batch_arguments_required');
            $input=maxBatchLoad(...array_slice($argv,2));
            echo maxEditJson(['ready'=>array_keys($input['ready']), 'held'=>$input['held'], 'provider_writes'=>0]), "\n";
            return 0;
        }
        maxEditNeed(($mode === '--batch-check' && count($argv) === 8) || ($mode === '--batch-apply' && count($argv) === 9), 'exact_batch_arguments_required');
        [, , $archive, $source, $sourceSha, $repo, $app, $id] = $argv;
        maxEditNeed(ctype_digit($id) && (string)(int)$id === $id, 'exact_hotel_required');
        $admitted = maxBatchLoad($archive, $source, $sourceSha, $repo, $app);
        $approval = $admitted['ready'][(int)$id] ?? null;
        maxEditNeed(is_array($approval), $admitted['held'][(int)$id] ?? 'hotel_outside_reviewed_batch');
        $checkpoint = $argv[8] ?? null;
        maxEditNeed($checkpoint === null || preg_match('/\A[a-f0-9]{64}\z/D', $checkpoint) === 1, 'invalid_checkpoint');
        $path = tempnam(sys_get_temp_dir(),'reviewed-max-plan-');
        maxEditNeed(is_string($path), 'temporary_plan_unavailable');
        $contents = maxEditJson($approval['plan']);
        maxEditNeed(file_put_contents($path,$contents) === strlen($contents), 'temporary_plan_unavailable');
        $args = [$argv[0], $mode === '--batch-apply' ? '--apply' : '--check', $path, $approval['sha256'], $app];
        if ($checkpoint !== null) $args[] = $checkpoint;
        return maxEditRun($args,$approval);
    } catch (Throwable $e) {
        $reason = preg_match('/\A[a-z_]{1,100}\z/D', $e->getMessage()) ? $e->getMessage() : 'invalid_batch_input';
        echo maxEditJson(['state'=>'stopped_before_edit','reason'=>$reason]), "\n";
        return 2;
    } finally {
        if (is_string($path)) unlink($path);
    }
}
