<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

// Existing #1566 reader, restricted to the ranked batch of candidate pairs.
// This program has no apply mode, publication registration or provider mutation.
const APP = '/var/www/anytoour/data/www/app.anytoour.ru';
const OPERATIONS = '/var/www/anytoour/data/channel-post-operations';
const APPLICATION_SHA = '__APPLICATION_SHA__';
const REPOSITORY_SHA = '__REPOSITORY_SHA__';
const NATIVE_RUN_ID = '__NATIVE_RUN_ID__';
function requireRead(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}
function jsonRead(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function reasonRead(Throwable $e): string
{
    return get_class($e) === RuntimeException::class
        && preg_match('/\A[a-z_]{1,100}\z/D', $e->getMessage()) === 1 ? $e->getMessage() : 'read_evidence_unavailable';
}
function publicRetryLimit(?string $raw, int $now): array
{
    if ($raw !== null && (strlen($raw)>160 || preg_match('/\A[\x20-\x7E]*\z/D',$raw)!==1)) $raw=null;
    $seconds = null;
    if ($raw !== null && preg_match('/\A[0-9]{1,8}\z/D',$raw) === 1) $seconds=(int)$raw;
    elseif ($raw !== null) {
        $when=DateTimeImmutable::createFromFormat(DateTimeInterface::RFC7231,$raw,new DateTimeZone('UTC'));
        if ($when!==false && $when->format(DateTimeInterface::RFC7231)===$raw) $seconds=max(0,$when->getTimestamp()-$now);
    }
    return ['observed_at_utc'=>gmdate(DATE_ATOM,$now), 'retry_after_raw'=>$raw,
        'retry_after_seconds'=>$seconds, 'resume_not_before_unix'=>$seconds===null?null:$now+$seconds];
}
/** Bounded host observations; never open Manager2 or return process arguments. */
function resourcesRead(): array
{
    $result = ['observed_at_utc'=>gmdate(DATE_ATOM), 'scope'=>'same_readonly_preparation_not_manager_load_test'];
    $memory = @file_get_contents('/proc/meminfo', false, null, 0, 32768);
    foreach (['MemTotal','MemAvailable','SwapTotal','SwapFree'] as $name) {
        $result['memory_kb'][$name] = is_string($memory) && preg_match('/^'. $name .':\s+([0-9]+) kB$/m', $memory, $m) ? (int)$m[1] : null;
    }
    $stat = @file_get_contents('/proc/stat', false, null, 0, 32768);
    $result['cpu_ticks'] = is_string($stat) && preg_match('/^cpu\s+([0-9 ]+)$/m',$stat,$m)
        ? array_map('intval', preg_split('/\s+/',trim($m[1]))) : null;
    $load = @file_get_contents('/proc/loadavg', false, null, 0, 256);
    $result['load_average'] = is_string($load) && preg_match('/^([0-9.]+) ([0-9.]+) ([0-9.]+) /',$load,$m)
        ? [(float)$m[1],(float)$m[2],(float)$m[3]] : null;
    $result['processes'] = ['php_fpm'=>['observed'=>0,'rss_kb'=>0], 'mysql'=>['observed'=>0,'rss_kb'=>0]];
    $paths = glob('/proc/[0-9]*/comm');
    if (is_array($paths) && count($paths) <= 8192) foreach ($paths as $path) {
        $name = @file_get_contents($path, false, null, 0, 128);
        $kind = is_string($name) && preg_match('/\Aphp-fpm[0-9.]*\n?\z/D',$name) ? 'php_fpm'
            : (is_string($name) && in_array(trim($name),['mysqld','mariadbd'],true) ? 'mysql' : null);
        if ($kind === null) continue;
        $result['processes'][$kind]['observed']++;
        $status = @file_get_contents(dirname($path).'/status', false, null, 0, 16384);
        if (is_string($status) && preg_match('/^VmRSS:\s+([0-9]+) kB$/m',$status,$m)) $result['processes'][$kind]['rss_kb'] += (int)$m[1];
    }
    $result['process_inventory_readable'] = is_array($paths) && count($paths) <= 8192;
    return $result;
}

function mysqlResourcesRead(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Max_used_connections','Innodb_row_lock_current_waits','Innodb_row_lock_time','Innodb_row_lock_waits')")->fetchAll(PDO::FETCH_ASSOC);
        $values = [];
        foreach ($rows as $row) if (preg_match('/\A[0-9]+\z/D',(string)$row['Value'])) $values[$row['Variable_name']] = (int)$row['Value'];
        return ['state'=>'observed','values'=>$values];
    } catch (Throwable $e) { return ['state'=>'unavailable']; }
}

function runtimeRead(): void
{
    requireRead(PHP_SAPI === 'cli' && realpath(APP) === APP && getcwd() === APP, 'application_root_changed');
    foreach ([
        'GIT_OPTIONAL_LOCKS=0 git -c core.fsmonitor=false rev-parse HEAD' => APPLICATION_SHA,
        'GIT_OPTIONAL_LOCKS=0 git -c core.fsmonitor=false status --porcelain --untracked-files=no' => '',
    ] as $command => $expected) {
        $lines = [];
        exec($command . ' 2>/dev/null', $lines, $status);
        requireRead($status === 0 && implode("\n", $lines) === $expected, 'installed_application_changed');
    }
}


$out=['schema'=>1,'issue'=>1566,'operation'=>'hotel_telegram_pending_button_inventory','ok'=>false,'repository_sha'=>REPOSITORY_SHA,'application_sha'=>APPLICATION_SHA,'native_run_id'=>NATIVE_RUN_ID,'write_admission'=>false,'production_writes'=>0,'provider_writes'=>0,'bot_starts'=>0,'rows'=>[]];
$pdo=null;$lock=null;$acquired=null;$stage='runtime';$start=hrtime(true);
try{
 runtimeRead();$out['resources_before']=resourcesRead();
 $path=OPERATIONS.'/monitor-price-after-collection-v1.jsonl';requireRead(!is_link($path)&&is_file($path),'price_editor_lease_unknown');$lock=fopen($path,'rb');requireRead(is_resource($lock),'price_read_lease_unavailable');
 $wait=hrtime(true);requireRead(flock($lock,LOCK_SH|LOCK_NB),'price_editor_busy_or_unknown');$acquired=hrtime(true);$out['price_lease_wait_ms']=(int)(($acquired-$wait)/1000000);runtimeRead();
 $stage='configuration';ob_start();try{require APP.'/config.php';require_once APP.'/services/ProjectConfig.php';require_once APP.'/services/ChannelPostRegistryService.php';require_once APP.'/services/ChannelPostButtonTransport.php';}finally{ob_end_clean();}
 requireRead(ProjectConfig::projectId()==='anytour','project_changed');$pdo=ConversationDb::connection();$pdo->exec('SET SESSION TRANSACTION READ ONLY');$registry=new ChannelPostRegistryService($pdo);
 $target=$registry->channel('anytour','telegram','anyru_bot','-1001845835645');requireRead(is_array($target)&&(int)$target['id']===6&&$target['source_key']==='tg_anytour_msk','channel_identity_changed');
 $out['mysql_before']=mysqlResourcesRead($pdo);$stage='pending_read';$pdo->beginTransaction();
 $q=$pdo->query("SELECT p.id FROM post_publications p JOIN post_versions v ON v.id=p.current_version_id WHERE p.post_channel_id=6 AND v.state<>'sent' ORDER BY p.id LIMIT 51");$ids=$q->fetchAll(PDO::FETCH_COLUMN);$out['inventory_truncated']=count($ids)>50;
 foreach(array_slice($ids,0,50) as $id){$r=$registry->publication((int)$id);requireRead(is_array($r)&&$r['project_key']==='anytour'&&$r['platform']==='telegram'&&$r['bot_key']==='anyru_bot'&&(int)$r['channel']['id']===6,'publication_identity_changed');$s=$r['snapshot'];$receipt=$r['receipt'];
  $out['rows'][]=['publication_id'=>(int)$r['publication_id'],'version_id'=>(int)$r['version_id'],'state'=>$r['state'],'message_id'=>$r['message_id'],'published_at'=>$s['published_at']??null,'post_url'=>$s['post_url']??null,'caption'=>$s['text']??null,'caption_sha256'=>hash('sha256',$s['text']??''),'hotel_id'=>$s['metadata']['hotel_catalog_id']??null,'logical_id'=>$s['metadata']['logical_id']??null,'observation_kind'=>$s['metadata']['observation_kind']??null,'attachment_types'=>array_column($s['attachments']??[],'type'),'keyboard_rows_count'=>count($s['keyboard']??[]),'receipt_write_attempts'=>$receipt['write_attempts']??null,'receipt_request_count'=>$receipt['request_count']??null,'canonical_zero_write_recovery_eligible'=>ChannelPostRegistryService::telegramReadFailureRecoveryEligible($r),'write_admission'=>false];
 }
 $pdo->rollBack();$out['mysql_after']=mysqlResourcesRead($pdo);runtimeRead();$out['ok']=true;
}catch(Throwable $e){$out['failure_stage']=$stage;$out['failure_reason']=reasonRead($e);}
finally{if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();if(is_resource($lock)){if($acquired!==null)$out['price_lease_held_ms']=(int)((hrtime(true)-$acquired)/1000000);flock($lock,LOCK_UN);fclose($lock);}}
$out['resources_after']=resourcesRead();$out['read_at_utc']=gmdate(DATE_ATOM);$out['read_elapsed_ms']=(int)((hrtime(true)-$start)/1000000);echo jsonRead($out),PHP_EOL;exit($out['ok']?0:2);
