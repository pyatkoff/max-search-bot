<?php
declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','0');

function requireAudit(bool $ok,string $reason):void
{
    if (!$ok) throw new RuntimeException($reason);
}

function need(bool $ok,string $reason):void { requireAudit($ok,$reason); }
function jsonValue($v):string { return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function installedGuard(): void
{
    $lines = []; $exitCode = 1;
    exec('git rev-parse HEAD', $lines, $exitCode);
    need($exitCode === 0 && trim(implode("\n", $lines)) === EXPECTED_INSTALLED_SHA,
        'installed_source_changed');
    $lines = []; $exitCode = 1;
    exec('git status --porcelain --untracked-files=no', $lines, $exitCode);
    need($exitCode === 0 && $lines === [], 'installed_checkout_changed');
}

function acceptedSurface(): void
{
    // Exactly the 22 reviewed pins from verify-commercial-source.py, including
    // ProjectConfig's new dependency. No title-edit/provider behavior is changed.
    $accepted = [
        "integrations/ChannelPostApiTransport.php" => "300634d77cb9fddf51adf9869974f956cca6e3f9",
        "migrations/033_channel_post_context.sql" => "0530458030a4bfe6b6cc55d1e5e441745c29b947",
        "migrations/036_channel_post_member_delivery.sql" => "1bda463e52b33130ad12752ddef4ee5c92699c05",
        "migrations/040_channel_post_reconciliation.sql" => "0f24b838eacd45333c5ade9a2991d060488c6035",
        "services/ChannelPostAdminService.php" => "b80eefc801054bfe6fdb58ec345ecdf687b2e89a",
        "services/ChannelPostAutomationService.php" => "f5975ab95de5f45e087eb7a0f768f566c5bca648",
        "services/ChannelPostButtonTransport.php" => "7b6711f1de63000346d8e5789f2dbb7f725535b8",
        "services/ChannelPostEntryService.php" => "f0e04e9b4e0ed6859ced96279cbf0619931596e6",
        "services/ChannelPostMaxAlbumRecovery.php" => "de266cd068587cc3b2ff83f308a966ea66887a04",
        "services/ChannelPostMediaService.php" => "f794efd0f6f24df916e18909ff6281b0bc928433",
        "services/ChannelPostMemberDeliveryService.php" => "20c1d7984898b2a5996a5f9e20e4d2d8c20b4778",
        "services/ChannelPostReconciliationService.php" => "43133f576845c14446102dedfffb7f0de2d4ba1c",
        "services/ChannelPostReconciliationState.php" => "d9583ceca80d1424a44ade3561c21282a23a52f0",
        "services/ChannelPostRegistryService.php" => "db8181426091a685a79ee55991cde22671f0d9dd",
        "services/ChannelPostRuntime.php" => "2f1dd9b77474fdbaa92f0402f3b0aa170c595474",
        "services/ChannelPostTelegramAlbum.php" => "faa1a14bc279a4af33936282126bf0d46e343040",
        "services/ChannelPostWebhookConfigService.php" => "d5fdcb056088f1e3a541b4046325d97eef6417d9",
        "services/ConversationDb.php" => "bb6281faf55aca7beeae7f8a57389d19751936f7",
        "services/MaxTlsConfig.php" => "032c0211ee3f39c6f0d27c669abd5b467e321dea",
        "services/MaxTransport.php" => "2cb4f8786b4704a8e62a3a9c40dc2cc73dad6159",
        "services/ProjectConfig.php" => "63c17c8103412f8cff8cdcc4bbb062e744106af9",
        "services/ProjectConfiguration.php" => "07019d473c588e3386ace42977db93092e8112e3",
    ];
    need(count($accepted) === 22, 'publishing_surface_count_changed');
    foreach ($accepted as $path => $blob) {
        $lines = []; $exitCode = 1;
        exec('git hash-object -- '.escapeshellarg($path), $lines, $exitCode);
        need($exitCode === 0 && trim(implode("\n", $lines)) === $blob, 'publishing_surface_changed');
    }
}

function photoSignature(array $snapshot): string
{
    $media = $snapshot['attachments'] ?? null;
    need(is_array($media) && array_is_list($media) && count($media) === 1
        && ($media[0]['type'] ?? '') === 'image', 'expected_one_photo');
    $m = $media[0];
    $provider = (string)($m['provider'] ?? '');
    $id = (string)($m['photo_id'] ?? $m['file_unique_id'] ?? '');
    need(in_array($provider, ['max', 'telegram'], true)
        && preg_match('/\A[A-Za-z0-9_-]+\z/D', $id) === 1, 'photo_stable_id_missing');
    return $provider.':'.$id;
}

function attachmentHash(array $snapshot): string
{
    need(is_array($snapshot['attachments'] ?? null), 'attachment_snapshot_missing');
    return hash('sha256', jsonValue($snapshot['attachments']));
}

function noActiveVersions(PDO $pdo, array $ids): void
{
    need(count($ids) >= 1 && count($ids) <= 100, 'active_check_scope_invalid');
    $query = $pdo->prepare('SELECT COUNT(*) FROM post_versions WHERE publication_id IN ('
        .implode(',', array_fill(0, count($ids), '?')).") AND (state IN ('claimed','write_started','unknown')"
        .' OR claim_token IS NOT NULL OR claimed_at IS NOT NULL)');
    $query->execute($ids);
    need((int)$query->fetchColumn() === 0, 'existing_publication_active_or_unknown_stop');
}

function readMax(string $id, string $channel): array
{
    need(preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D', $id) === 1, 'invalid_max_message_id');
    $response = ChannelPostApiTransport::buttonRequest('max', 'GET', '/messages/'.$id, [], null);
    need(empty($response['transport_error']) && (int)$response['http'] === 200
        && is_array($response['data']), 'max_read_failed');
    $message = $response['data'];
    need(($message['body']['mid'] ?? null) === $id
        && (string)($message['recipient']['chat_id'] ?? '') === $channel
        && ($message['recipient']['chat_type'] ?? '') === 'channel', 'max_read_identity_changed');
    return $message;
}


/** Read only the exact original monitor journal named by this current publication. */
function priorMonitorReceipt(int $tv,$record,array &$cache):?array
{
    if ($record===null) return null;
    requireAudit(is_string($record)&&preg_match('/\A[a-f0-9]{64}\z/D',$record)===1,'current_monitor_record_invalid');
    $op='commercial-monitor-price-h'.$tv.'-'.$record;
    if (isset($cache[$op])) return $cache[$op];
    $base='/var/www/anytoour/data/channel-post-operations';$dir=$base.'/'.$op;$path=$dir.'/receipt.jsonl';
    requireAudit(realpath($base)===$base&&!is_link($base)&&realpath($dir)===$dir&&!is_link($dir)
        &&is_file($path)&&!is_link($path)&&realpath($path)===$path,'prior_monitor_journal_unavailable');
    $f=fopen($path,'r');requireAudit(is_resource($f),'prior_monitor_journal_unavailable');
    try {
        requireAudit(flock($f,LOCK_SH|LOCK_NB),'prior_monitor_journal_busy');
        $stat=fstat($f);requireAudit(is_array($stat)&&$stat['size']>0&&$stat['size']<=10000000,'prior_monitor_journal_size');
        $raw=stream_get_contents($f,10000001);
        requireAudit(is_string($raw)&&strlen($raw)===$stat['size']&&str_ends_with($raw,"\n"),'prior_monitor_journal_incomplete');
        $last=null;$terminal=null;
        foreach (explode("\n",substr($raw,0,-1)) as $line) {
            $v=json_decode($line,true,128,JSON_THROW_ON_ERROR);
            requireAudit(is_array($v)&&($v['operation']??null)===$op,'prior_monitor_operation_changed');
            requireAudit($terminal===null||$terminal===$v,'prior_monitor_terminal_changed');
            if (($v['complete']??false)===true) $terminal=$v;
            $last=$v;
        }
        requireAudit(is_array($last)&&($last['complete']??false)===true&&is_array($last['posts']??null)
            &&count($last['posts'])===2&&is_array($last['feed_binding']??null),'prior_monitor_not_complete');
        $safe=[];$seen=[];
        $fields=['platform','publication_id','previous_version_id','version_id','message_id','post_url','text','text_sha256',
            'previous_text_sha256','source_key','logical_id','photo_signature','state','body_photo_button_verified',
            'existing_message_reused','monitor_price'];
        foreach ($last['posts'] as $key=>$post) {
            $r=$post['receipt']??[];$platform=$r['platform']??null;
            requireAudit(($post['state']??null)==='confirmed'&&is_array($r)&&in_array($platform,['max','telegram'],true)
                &&!isset($seen[$platform])&&($r['state']??null)==='sent'
                &&($r['existing_message_reused']??false)===true&&($r['body_photo_button_verified']??false)===true
                &&($r['monitor_price']['record_id']??null)===$record
                &&is_string($r['text']??null)&&($r['text_sha256']??null)===hash('sha256',$r['text']),
                'prior_monitor_receipt_invalid');
            $seen[$platform]=true;$safe[$key]=['state'=>'confirmed','receipt'=>array_intersect_key($r,array_flip($fields))];
        }
        requireAudit(count($seen)===2,'prior_monitor_pair_incomplete');
        return $cache[$op]=['operation'=>$op,'complete'=>true,'manifest_hash'=>$last['manifest_hash']??null,
            'feed_binding'=>$last['feed_binding'],'posts'=>$safe,'journal_sha256'=>hash('sha256',$raw),'journal_bytes'=>strlen($raw)];
    } finally { flock($f,LOCK_UN);fclose($f); }
}

$out=['operation'=>'commercial-caption-batch16-20261113-read-v1','ok'=>false,'post_write_attempts'=>0,'provider_calls'=>0,'installed_sha'=>EXPECTED_INSTALLED_SHA,'observed_at_utc'=>gmdate('c'),'posts'=>[]];
$pdo=null; $monitorLock=null; $priorCache=[];
try {
    requireAudit(PHP_SAPI==='cli','cli_only');
    installedGuard(); acceptedSurface();
    $monitorPath='/var/www/anytoour/data/channel-post-operations/monitor-price-after-collection-v1.jsonl';
    requireAudit(is_file($monitorPath)&&!is_link($monitorPath),'monitor_journal_unavailable');
    $monitorLock=fopen($monitorPath,'r');
    requireAudit($monitorLock!==false && flock($monitorLock,LOCK_SH|LOCK_NB),'monitor_already_running');
    $monitorRaw=stream_get_contents($monitorLock);
    requireAudit(is_string($monitorRaw)&&$monitorRaw!==''&&strlen($monitorRaw)<=100000000&&str_ends_with($monitorRaw,"\n"),'monitor_journal_incomplete');
    $monitorLines=explode("\n",rtrim($monitorRaw,"\n"));
    $monitorState=json_decode(end($monitorLines),true,64,JSON_THROW_ON_ERROR);
    requireAudit(array_key_exists('pending',$monitorState)&&$monitorState['pending']===null,'monitor_pending_operation_stop');
    $out['monitor_journal_sha256']=hash('sha256',$monitorRaw);
    $out['monitor_pending']=false;
    $raw=base64_decode(MANIFEST_BASE64,true);
    requireAudit(is_string($raw) && hash('sha256',$raw)===APPROVED_MANIFEST_SHA256,'approved_manifest_changed');
    $scope=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    requireAudit(($scope['operation']??null)===$out['operation'] && count($scope['rows']??[])===9,'audit_scope_changed');
    ob_start();
    try {
        require getcwd().'/config.php';
        require_once getcwd().'/services/ChannelPostRuntime.php';
        require_once getcwd().'/services/ChannelPostEntryService.php';
        require_once getcwd().'/services/ChannelPostAdminService.php';
        require_once getcwd().'/services/ChannelPostButtonTransport.php';
        require_once getcwd().'/integrations/ChannelPostApiTransport.php';
    } finally {
        ob_end_clean();
    }
    $pdo=ConversationDb::connection();
    $pdo->exec('START TRANSACTION READ ONLY');
    $registry=new ChannelPostRegistryService($pdo);
    $channels=[
        'max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','id'=>9],
        'telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','id'=>10],
    ];
    foreach ($channels as $platform=>$target) {
        $identity=ChannelPostRuntime::identity($platform);
        $channel=$registry->channel('anytour',$platform,$target['bot'],$target['channel']);
        requireAudit(is_array($identity)&&$identity['project_key']==='anytour'&&$identity['bot_key']===$target['bot'],'runtime_identity_changed');
        requireAudit(is_array($channel)&&(int)$channel['id']===$target['id']&&$channel['source_key']===$target['source'],'channel_identity_changed');
    }
    $telegram=$pdo->prepare('SELECT id FROM post_publications WHERE post_channel_id=10 AND external_message_id=? LIMIT 1');
    $telegramUrl=$pdo->prepare("SELECT pp.id FROM post_publications pp JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.post_channel_id=10 AND JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.post_url'))=? LIMIT 2");
    $maxUrl=$pdo->prepare("SELECT pp.id FROM post_publications pp JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.post_channel_id=9 AND JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.post_url'))=? LIMIT 2");
    requireAudit(array_column($scope['rows'],'hotel_id')===[81358,343,432,65254,443,107940,32356,195,20288],'exact_hotels_changed');
    requireAudit(array_column($scope['rows'],'max_publication_id')===range(1354,1362)
        &&array_column($scope['rows'],'telegram_publication_id')===range(1345,1353),'exact_publications_changed');
    noActiveVersions($pdo,array_merge(range(1345,1353),range(1354,1362)));
    foreach ($scope['rows'] as $item) {
        requireAudit(is_int($item['hotel_id'])&&is_int($item['max_publication_id'])&&is_int($item['telegram_message_id']),'invalid_bound_post_identity');
        requireAudit(preg_match('~\Ahttps://max\.ru/channel_anytour/[A-Za-z0-9_-]+\z~D',$item['max_url'])===1,'invalid_bound_max_url');
        requireAudit($item['telegram_url']==='https://t.me/any_toour/'.$item['telegram_message_id'],'invalid_bound_telegram_url');
        $telegram->execute([(string)$item['telegram_message_id']]);
        $telegramId=$telegram->fetchColumn();
        if ($telegramId===false) {
            $telegramUrl->execute([$item['telegram_url']]);
            $ids=$telegramUrl->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids)===1) $telegramId=$ids[0];
        }
        requireAudit((int)$telegramId===$item['telegram_publication_id'],'telegram_publication_identity_changed');
        $maxId=$item['max_publication_id'];
        if ($maxId===0) {
            $maxUrl->execute([$item['max_url']]);
            $ids=$maxUrl->fetchAll(PDO::FETCH_COLUMN);
            requireAudit(count($ids)===1,'max_url_not_unique');
            $maxId=(int)$ids[0];
        }
        foreach ($channels as $platform=>$target) {
            $id=$platform==='max'?$maxId:(int)$telegramId;
            $row=['hotel_id'=>$item['hotel_id'],'platform'=>$platform,'post_url'=>$item[$platform.'_url']];
            $p=$id>0?$registry->publication($id):null;
            if (!is_array($p) || $p['project_key']!=='anytour' || $p['platform']!==$platform || $p['channel_id']!==$target['channel'] || $p['source_key']!==$target['source']) {
                $out['posts'][]=$row+['status'=>'registry_identity_unavailable'];
                continue;
            }
            $s=$p['snapshot'];
            $samePost=$platform==='max'
                ? ($s['post_url']??null)===$item['max_url']
                : ((string)$p['external_message_id']===(string)$item['telegram_message_id']
                    || ($s['post_url']??null)===$item['telegram_url']);
            if (!$samePost) {
                $out['posts'][]=$row+['status'=>'registry_post_url_mismatch'];
                continue;
            }
            $button=ChannelPostButtonTransport::buttonUrl($p);
            $resolved=ChannelPostEntryService::resolve($platform,$p['start_payload'],$registry);
            $liveVerified=false;
            if ($platform==='max') {
                $out['provider_calls']++;
                $live=ChannelPostIncomingAdapter::maxSnapshot(readMax((string)$p['external_message_id'],$target['channel']));
                requireAudit(is_array($live)&&($live['text']??null)===($s['text']??null)
                    &&photoSignature($live)===photoSignature($s)&&($live['post_url']??null)===$item['max_url']
                    &&ChannelPostIncomingAdapter::containsOnlyButton($live,$button,'✈️ Узнать стоимость тура'),'max_provider_snapshot_changed');
                $liveVerified=true;
            }
            $caption=(string)($s['text']??'');
            $media=$s['attachments']??[];
            $out['posts'][]=$row+[
                'status'=>$p['state']==='sent'?'sent':'registry_unconfirmed',
                'publication_id'=>(int)$p['publication_id'],
                'version_id'=>(int)$p['version_id'],
                'message_id'=>(string)$p['external_message_id'],
                'caption'=>$caption,
                'provider_live_verified'=>$liveVerified,
                'snapshot_provenance'=>$platform==='max'?'registry_and_live_max_get':'canonical_registry_latest_version',
                'monitor_price_record_id'=>$s['metadata']['monitor_price_record_id']??null,
                'prior_monitor_receipt'=>priorMonitorReceipt($item['hotel_id'],$s['metadata']['monitor_price_record_id']??null,$priorCache),
                'caption_correction_operation'=>$s['metadata']['caption_correction_operation']??null,
                'caption_sha256'=>hash('sha256',$caption),
                'media_count'=>count($media),
                'media_types'=>array_values(array_map(static fn($m)=>(string)($m['type']??''),$media)),
                'photo_signature'=>count($media)===1?(string)($media[0]['provider']??'').':'.(string)($media[0]['photo_id']??$media[0]['file_unique_id']??''):null,
                'attachments_sha256'=>hash('sha256',json_encode($media,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
                'config_version'=>(int)($p['channel']['config_version']??0),
                'source_key'=>$p['source_key'],
                'button_url_sha256'=>hash('sha256',$button),
                'button_observed_in_snapshot'=>ChannelPostIncomingAdapter::containsOnlyButton($s,$button,'✈️ Узнать стоимость тура'),
                'button_resolves_to_current_post'=>is_array($resolved)
                    && (int)$resolved['publication_id']===(int)$p['publication_id']
                    && (int)$resolved['version_id']===(int)$p['version_id']
                    && $resolved['source_key']===$target['source'],
            ];
        }
    }
    requireAudit(count($out['posts'])===18,'audit_scope_incomplete');
    $pdo->exec('ROLLBACK');
    installedGuard();
    requireAudit($out['provider_calls']===9,'max_read_scope_incomplete');
    $out['ok']=true;
} catch (Throwable $error) {
    if ($pdo instanceof PDO) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
    }
    $message=$error->getMessage();
    $out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$message)===1?$message:'bounded_registry_audit_failed';
}
if (is_resource($monitorLock)) { flock($monitorLock,LOCK_UN); fclose($monitorLock); }
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);

