<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Warnings from configuration or file access must not print private paths/data.
set_error_handler(static function(int $severity): never { throw new RuntimeException('virtual_reply_learning_failed'); });

function virtualReplyLearningUsage(): never
{
    fwrite(STDERR,"Usage: php tools/virtual_reply_learning.php capture conversation|telegram_archive fresh|history 1..20\n"
        ."       php tools/virtual_reply_learning.php reconcile 1..20\n"
        ."       php tools/virtual_reply_learning.php control-status\n"
        ."       php tools/virtual_reply_learning.php control-register baseline.json candidate.json 1..20 admin_id\n");
    exit(2);
}

/** Local regular files only; never resolve a stream URL or read unbounded bytes. */
function virtualReplyLearningContract(string $path): array
{
    if ($path==='' || str_contains($path,"\0") || str_contains($path,'://')) throw new InvalidArgumentException();
    $stat=@lstat($path);
    if (!$stat || ($stat['mode']&0170000)!==0100000 || $stat['size']<1 || $stat['size']>262144) throw new InvalidArgumentException();
    $file=@fopen($path,'rb');
    if ($file===false) throw new InvalidArgumentException();
    try {
        $opened=fstat($file);
        if (!$opened || ($opened['mode']&0170000)!==0100000 || $opened['dev']!==$stat['dev'] || $opened['ino']!==$stat['ino']
            || $opened['size']!==$stat['size']) throw new InvalidArgumentException();
        $json=stream_get_contents($file,262145);
    } finally { fclose($file); }
    if (!is_string($json) || strlen($json)!==$stat['size'] || preg_match('//u',$json)!==1) throw new InvalidArgumentException();
    $contract=json_decode($json,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($contract)) throw new InvalidArgumentException();
    return VirtualReplyControlProtocol::validateAuthorContract($contract);
}

function virtualReplyLearningCounts(array $values,array $keys): array
{
    $counts=[];
    foreach ($keys as $key) if (is_int($values[$key]??null) && $values[$key]>=0) $counts[$key]=$values[$key];
    return $counts;
}

/** Explicit public projection even if a future store adds private fields. */
function virtualReplyLearningSampling(array $value): array
{
    $safe=[];
    if (in_array($value['status']??null,['enabled','migration_unavailable'],true)) $safe['status']=$value['status'];
    $safe+=virtualReplyLearningCounts($value,['legacy_pending','legacy_unresolved_exposure','author_groups_claimed_today','social_groups_claimed_today','social_daily_limit']);
    foreach (['author_blocked_reason','reservation_blocked_reason'] as $key) {
        if (array_key_exists($key,$value) && in_array($value[$key],[null,'legacy_backfill_pending','legacy_unresolved_exposure'],true)) $safe[$key]=$value[$key];
    }
    if (is_array($value['groups']??null)) $safe['groups']=virtualReplyLearningCounts($value['groups'],['development','reserved','contaminated']);
    return $safe;
}

$mode=$argv[1]??'';$source=$argv[2]??'';$lane=$argv[3]??'';
$rawLimit=$argv[$mode==='capture'?4:($mode==='control-register'?4:2)]??'';
$validLimit=is_string($rawLimit)&&preg_match('/\A(?:[1-9]|1[0-9]|20)\z/D',$rawLimit);
$rawAdmin=$argv[5]??'';
$validAdmin=is_string($rawAdmin)&&preg_match('/\A[1-9][0-9]*\z/D',$rawAdmin)
    && strlen($rawAdmin)<=strlen((string)PHP_INT_MAX) && (string)(int)$rawAdmin===$rawAdmin;
$valid=match($mode) {
    'capture'=>count($argv)===5 && $validLimit && in_array($source,['conversation','telegram_archive'],true) && in_array($lane,['fresh','history'],true),
    'reconcile'=>count($argv)===3 && $validLimit,
    'control-status'=>count($argv)===2,
    'control-register'=>count($argv)===6 && $validLimit && $validAdmin,
    default=>false,
};
if (!$valid) virtualReplyLearningUsage();

// File/contract errors are argument errors and precede configuration or DB access.
$baseline=$candidate=null;
if ($mode==='control-register') {
    try {
        require_once dirname(__DIR__).'/services/VirtualReplyControlProtocol.php';
        $baseline=virtualReplyLearningContract($argv[2]);$candidate=virtualReplyLearningContract($argv[3]);
        if ($baseline['instruction_revision']===$candidate['instruction_revision']) throw new InvalidArgumentException();
    } catch (Throwable $ignored) { virtualReplyLearningUsage(); }
}

try {
    require_once dirname(__DIR__).'/config.php';
    require_once dirname(__DIR__).'/services/ProjectConfig.php';
    require_once dirname(__DIR__).'/services/ConversationDb.php';
    if (ProjectConfig::projectId()!=='anytour') throw new RuntimeException();
    if (in_array($mode,['capture','reconcile'],true)) {
        require_once dirname(__DIR__).'/services/VirtualReplyStore.php';
        $store=new VirtualReplyStore(ConversationDb::connection());
        $result=$mode==='capture'?$store->capture($source,$lane,(int)$rawLimit):$store->reconcile((int)$rawLimit);
        // Existing capture/reconcile output remains aggregate only.
        $counts=virtualReplyLearningCounts($result,['scanned','captured','unchanged','invalidated','checked','recaptured','expired']);
        $output=['ok'=>true,'operation'=>$mode,'counts'=>$counts,'provider_calls'=>0];
    } else {
        require_once dirname(__DIR__).'/services/VirtualReplyControlStore.php';
        $store=new VirtualReplyControlStore(ConversationDb::connection());
        if ($mode==='control-register') {
            $result=$store->register($baseline,$candidate,(int)$rawLimit,(int)$rawAdmin);
            if (!is_string($result['run_id']??null) || preg_match('/\A[a-f0-9]{64}\z/D',$result['run_id'])!==1) throw new RuntimeException();
            $output=['ok'=>true,'operation'=>$mode,'purpose'=>'control','run_id'=>$result['run_id'],
                'revisions'=>['baseline'=>$baseline['instruction_revision'],'candidate'=>$candidate['instruction_revision'],'reviewer'=>VirtualReplyControlProtocol::revision()],
                'counts'=>virtualReplyLearningCounts($result,['registered_cases','requested_cases','ineligible_candidates']),
                'has_more'=>($result['has_more']??false)===true];
        } else {
            $result=$store->status();
            if (!in_array($result['status']??null,['enabled','migration_unavailable'],true)) throw new RuntimeException();
            $output=['ok'=>true,'operation'=>$mode,'purpose'=>'control','status'=>$result['status']];
            if (is_array($result['runs']??null)) {
                $output['runs']=[];
                foreach (array_slice($result['runs'],0,10) as $run) {
                    if (!is_array($run) || !is_string($run['run_id']??null) || preg_match('/\A[a-f0-9]{64}\z/D',$run['run_id'])!==1) throw new RuntimeException();
                    $output['runs'][]=['run_id'=>$run['run_id']]+virtualReplyLearningCounts($run,['registered_cases']);
                }
            }
            if (is_array($result['reconciliation']??null)) $output['counts']=virtualReplyLearningCounts($result['reconciliation'],['checked','invalidated']);
            if (is_array($result['quotas']??null)) $output['quotas']=virtualReplyLearningCounts($result['quotas'],['limit_per_role','author_used','reviewer_used']);
        }
        if (is_array($result['sampling']??null)) $output['sampling']=virtualReplyLearningSampling($result['sampling']);
        $output['provider_calls']=0;
    }
    echo json_encode($output,JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $ignored) {
    fwrite(STDERR,"virtual_reply_learning_failed\n");exit(1);
}
