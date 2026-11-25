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


function receiptRead(string $path): array
{
    if (!file_exists($path) && !is_link($path)) return ['state' => 'absent'];
    requireRead(!is_link($path) && is_file($path) && filesize($path) <= 1_000_000, 'operation_receipt_unreadable');
    $raw = file_get_contents($path);
    requireRead(is_string($raw), 'operation_receipt_unreadable');
    $lines = array_values(array_filter(explode("\n", $raw), static fn($s) => trim($s) !== ''));
    requireRead(count($lines) <= 500, 'operation_receipt_unbounded');
    $states = []; $events = [];
    foreach ($lines as $line) {
        $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        $state = $record['state'] ?? '';
        $states[] = is_string($state) && preg_match('/\A[a-z0-9_:-]{1,100}\z/D', $state) === 1 ? $state : 'unclassified';
        $safe = ['state'=>end($states)];
        foreach (['at_utc','reason','publication_id','version_id','provider_write_attempts','plan_sha256'] as $key) {
            $v=$record[$key]??null;
            if(is_int($v) || (is_string($v)&&strlen($v)<=100&&preg_match('/\A[a-zA-Z0-9_:T.+-]{1,100}\z/D',$v)===1))$safe[$key]=$v;
        }
        if($state==='provider_response_saved') {
            $response=$record['response']??[];
            $safe['provider_http']=is_int($response['http']??null)?$response['http']:null;
            $safe['transport_error']=is_bool($response['transport_error']??null)?$response['transport_error']:null;
            $safe['provider_ok']=is_bool($response['data']['ok']??null)?$response['data']['ok']:null;
            $safe['provider_error_code']=is_int($response['data']['error_code']??null)?$response['data']['error_code']:null;
            $safe['message_id_invalid']=($response['data']['description']??null)==='Bad Request: MESSAGE_ID_INVALID';
        }
        if($state==='intent_saved') {
            $x=$record['execution']??[];
            $safe['original_native_run_id']=preg_match('/\A[0-9]{1,20}\z/D',$x['native_run_id']??'')?$x['native_run_id']:null;
            $safe['original_application_sha']=preg_match('/\A[a-f0-9]{40}\z/D',$x['application_sha']??'')?$x['application_sha']:null;
        }
        $events[]=$safe;
    }
    return ['state' => 'present_do_not_replay', 'sha256' => hash('sha256', $raw), 'line_count' => count($lines), 'states' => $states, 'events'=>$events];
}

$observed=json_decode('[{"publication_id":3600,"version_id":11845,"message_id":"809","hotel_id":12107,"caption_sha256":"6e34811950abe39d6951f87bc8477b6326fcd1d2bc6a1401fbe9c3abd2627800","button_url":"https://t.me/anyru_bot?start=p_nY39Mjv3PzQmiCjYXbOWgLUd","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2024-08-19T07:46:00+00:00"},{"publication_id":3602,"version_id":11847,"message_id":"1148","hotel_id":3452,"caption_sha256":"e4a789955b08c410bedef1254e3dcdd72a1c3cd7ef3acb73acd36faaed8faf49","button_url":"https://t.me/anyru_bot?start=p_GBgs9ScuVsvXWJXNhHOudn83","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2025-07-13T07:46:39+00:00"},{"publication_id":3621,"version_id":11866,"message_id":"17","hotel_id":132,"caption_sha256":"727e7d0cd6d094c4ed943fdef0225667a7328ff43c00d72e43ae427314a5706c","button_url":"https://t.me/anyru_bot?start=p_-NJMLjoQ_ETN_tNQiOndFfNd","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2023-09-30T15:00:06+00:00"},{"publication_id":3622,"version_id":11867,"message_id":"19","hotel_id":469,"caption_sha256":"960f26d6c8b5c716341a39fb2e8b8185e1832e56273d0b2464224317b814dc98","button_url":"https://t.me/anyru_bot?start=p_nOc9jH-DUifrV4MHuNMbQR39","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2023-10-01T18:23:18+00:00"},{"publication_id":3624,"version_id":11869,"message_id":"37","hotel_id":70048,"caption_sha256":"a462a9c22f118047771591faad4515ad735479054450bad1fb40f2c0dca645e5","button_url":"https://t.me/anyru_bot?start=p_iO0lZZb3gRZfLMpeCEcB28qs","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2023-10-08T07:00:12+00:00"},{"publication_id":3626,"version_id":11871,"message_id":"82","hotel_id":1344,"caption_sha256":"d68501a13b77ba5de1d788d55f0d216870de6681fd23b3459e1ade835e8b5a94","button_url":"https://t.me/anyru_bot?start=p_XMnmab6nfFYauSCTCmZe2meb","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2023-10-22T07:15:00+00:00"},{"publication_id":3627,"version_id":11872,"message_id":"192","hotel_id":260,"caption_sha256":"ce29ee61fd54212ec026a51eb67dc4a319fb6b096dca38a4df6d67f881abd8bc","button_url":"https://t.me/anyru_bot?start=p_BA6YSdmYQBn2LTOeVTfyT4Nf","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2023-11-28T17:45:00+00:00"},{"publication_id":3628,"version_id":11873,"message_id":"196","hotel_id":2493,"caption_sha256":"8de9f87308708dcadfce4758ca5d858a3073970f3a8811225ab0378d98a527f0","button_url":"https://t.me/anyru_bot?start=p_AsFVp4z4J_JlmbNXmu2CKhPx","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2023-11-30T07:15:00+00:00"},{"publication_id":3629,"version_id":11874,"message_id":"204","hotel_id":4181,"caption_sha256":"844d119b604a02d0e20b9a5e94db8ad7eb6d44ac320f6e998c009c190e13a226","button_url":"https://t.me/anyru_bot?start=p_JdrHLHnSkOYHx7JFaIG0LZpk","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2023-12-01T18:00:03+00:00"},{"publication_id":3631,"version_id":11876,"message_id":"414","hotel_id":43676,"caption_sha256":"5e026aaec4439b7c18a538a88baf9b6e49872dc3c2e8fc39a2d6da3c1dbdc4b3","button_url":"https://t.me/anyru_bot?start=p_pJuLnqOfEKIJnQZYIoAz_Fgg","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2024-02-10T12:21:45+00:00"},{"publication_id":3632,"version_id":11877,"message_id":"455","hotel_id":73974,"caption_sha256":"063fcb2e626e839c6fc5d47662e4f17a08c27d312ce3fc914dca42eabbd43ba7","button_url":"https://t.me/anyru_bot?start=p_k771AKDbNHMJuo5B6760KS33","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2024-02-27T06:57:34+00:00"},{"publication_id":3634,"version_id":11879,"message_id":"483","hotel_id":99713,"caption_sha256":"cbbbb62b2c8df800007d96fe24139a76f08c7b5dbf6b6b2eb29613827f828377","button_url":"https://t.me/anyru_bot?start=p_bdWJQJiU2vvdCMnJWxH7I2Ey","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2024-03-11T07:53:49+00:00"},{"publication_id":3672,"version_id":13065,"message_id":"612","hotel_id":253,"caption_sha256":"3fab7b6b11a28c7b2cc07bd1642fc112db1a6ffb2910efcbdc6eec6dea89b781","button_url":"https://t.me/anyru_bot?start=p_Z7JroaPNw2OtOCRhJU0wkE4D","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2024-05-12T10:36:58+00:00"},{"publication_id":3674,"version_id":13067,"message_id":"230","hotel_id":12125,"caption_sha256":"8f9f6bade83f02b50e85042db1d76e0310bfb1bdb33d6c9cdce5b8a6235eefd5","button_url":"https://t.me/anyru_bot?start=p_mKEYgVBSxa2-U0--KBjpL_S4","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2023-12-11T07:54:15+00:00"},{"publication_id":3675,"version_id":13068,"message_id":"237","hotel_id":547,"caption_sha256":"221dae549e0554b218dda732b0ba01799bfd5e8631a8a8f44fc5c9a008c5738d","button_url":"https://t.me/anyru_bot?start=p_Ij2wd8r3kHNa_gB7-F00Xq52","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2023-12-13T15:24:34+00:00"},{"publication_id":3680,"version_id":13073,"message_id":"277","hotel_id":341,"caption_sha256":"4bd1b4f10b601fd8791983316fd387d27f34dcd14e0c27971c9e550ec830c697","button_url":"https://t.me/anyru_bot?start=p_Sxorh2MILY_Cpvu0BW7EiGPi","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2023-12-26T15:25:14+00:00"},{"publication_id":3682,"version_id":13075,"message_id":"325","hotel_id":132016,"caption_sha256":"950cad41305c64dfb43e782b78c303dc17a352fa3acc7a577d513078e462a9ce","button_url":"https://t.me/anyru_bot?start=p_vcy9NZZwXaVtvOrRkPoyQGMK","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2024-01-12T12:09:02+00:00"},{"publication_id":3684,"version_id":13077,"message_id":"805","hotel_id":4079,"caption_sha256":"11f6b8083d3be8162ec039464cb6a0feca27648594b616d3ff71ff32b33947ab","button_url":"https://t.me/anyru_bot?start=p_kxf8Ybu5hX_5CND_q7Y9RgLH","button_text":"\u2708\ufe0f \u0420\u0430\u0441\u0441\u0447\u0438\u0442\u0430\u0442\u044c \u043d\u0430 \u043c\u043e\u0438 \u0434\u0430\u0442\u044b","published_at":"2024-08-15T07:48:50+00:00"}]',true,32,JSON_THROW_ON_ERROR);
$out=['schema'=>1,'issue'=>1566,'operation'=>'hotel_telegram_unknown_evidence_read','ok'=>false,'repository_sha'=>REPOSITORY_SHA,'application_sha'=>APPLICATION_SHA,'native_run_id'=>NATIVE_RUN_ID,'write_admission'=>false,'production_writes'=>0,'provider_writes'=>0,'bot_starts'=>0,'rows'=>[]];
$pdo=null;$lock=null;$acquired=null;$stage='runtime';$start=hrtime(true);
try{
 runtimeRead();requireRead(realpath(OPERATIONS)===OPERATIONS,'operations_root_changed');$out['public_evidence_artifacts']=[15012438524,15013182107];$out['resources_before']=resourcesRead();
 $path=OPERATIONS.'/monitor-price-after-collection-v1.jsonl';requireRead(!is_link($path)&&is_file($path),'price_editor_lease_unknown');$lock=fopen($path,'rb');requireRead(is_resource($lock),'price_read_lease_unavailable');
 $wait=hrtime(true);requireRead(flock($lock,LOCK_SH|LOCK_NB),'price_editor_busy_or_unknown');$acquired=hrtime(true);$out['price_lease_wait_ms']=(int)(($acquired-$wait)/1000000);runtimeRead();
 $stage='configuration';ob_start();try{require APP.'/config.php';require_once APP.'/services/ProjectConfig.php';require_once APP.'/services/ChannelPostRegistryService.php';require_once APP.'/services/ChannelPostButtonTransport.php';}finally{ob_end_clean();}
 requireRead(ProjectConfig::projectId()==='anytour','project_changed');$pdo=ConversationDb::connection();$pdo->exec('SET SESSION TRANSACTION READ ONLY');$registry=new ChannelPostRegistryService($pdo);
 $target=$registry->channel('anytour','telegram','anyru_bot','-1001845835645');requireRead(is_array($target)&&(int)$target['id']===6&&$target['source_key']==='tg_anytour_msk','channel_identity_changed');
 $out['mysql_before']=mysqlResourcesRead($pdo);$stage='pending_read';$pdo->beginTransaction();

 foreach($observed as $o){$row=['publication_id'=>$o['publication_id'],'version_id'=>$o['version_id'],'message_id'=>$o['message_id'],'write_admission'=>false];try{
  $r=$registry->publication($o['publication_id']);requireRead(is_array($r)&&$r['project_key']==='anytour'&&$r['platform']==='telegram'&&$r['bot_key']==='anyru_bot'&&(int)$r['channel']['id']===6&&$r['version_id']===$o['version_id']&&$r['message_id']===$o['message_id'],'publication_identity_changed');
  $snapshot=$r['snapshot'];$receipt=$r['receipt'];requireRead($r['state']==='unknown','original_unknown_state_changed');requireRead(preg_match('~\Ahttps://t\.me/anyru_bot\?start=(p_[A-Za-z0-9_-]{24})\z~D',$o['button_url'],$m)===1,'observed_button_invalid');
  $context=$registry->resolveToken('anytour','telegram','anyru_bot',$m[1]);
  $row+=['state'=>$r['state'],'error_reason'=>$r['error_reason'],'write_attempts'=>$r['write_attempts'],'attempt_count'=>$r['attempt_count'],'claim_present'=>$r['claim_token']!==null||$r['claimed_at']!==null,'hotel_id'=>$snapshot['metadata']['hotel_catalog_id']??null,'caption_equal'=>hash('sha256',$snapshot['text']??'')===$o['caption_sha256'],'published_at'=>$snapshot['published_at']??null,'observed_public_date'=>$o['published_at'],'expected_button_equal'=>ChannelPostButtonTransport::buttonUrl($r)===$o['button_url'],'observed_button_url'=>$o['button_url'],'receipt_sha256'=>hash('sha256',jsonRead($receipt)),'receipt_content_preserved'=>$receipt['content_preserved']??null,'receipt_button_verified'=>$receipt['button_verified']??null,'context_same_publication_version'=>is_array($context)&&$context['publication_id']===$r['publication_id']&&$context['version_id']===$r['version_id'],'context_hotel_id'=>$context['snapshot']['metadata']['hotel_catalog_id']??null,'context_source_id'=>$context['source_id']??null,'context_source_key'=>$context['channel']['source_key']??null,'photo_unique_hashes'=>array_map(static fn($a)=>empty($a['file_unique_id'])?null:hash('sha256',$a['file_unique_id']),$snapshot['attachments']??[]),'original_content_journal'=>receiptRead(OPERATIONS.'/issue-1566-edit-existing-'.$r['publication_id'].'.jsonl')];
 }catch(Throwable $e){$row['hold_reason']=reasonRead($e);}$out['rows'][]=$row;}
 $pdo->rollBack();$out['mysql_after']=mysqlResourcesRead($pdo);runtimeRead();$out['ok']=true;
}catch(Throwable $e){$out['failure_stage']=$stage;$out['failure_reason']=reasonRead($e);}
finally{if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();if(is_resource($lock)){if($acquired!==null)$out['price_lease_held_ms']=(int)((hrtime(true)-$acquired)/1000000);flock($lock,LOCK_UN);fclose($lock);}}
$out['resources_after']=resourcesRead();$out['read_at_utc']=gmdate(DATE_ATOM);$out['read_elapsed_ms']=(int)((hrtime(true)-$start)/1000000);echo jsonRead($out),PHP_EOL;exit($out['ok']?0:2);
