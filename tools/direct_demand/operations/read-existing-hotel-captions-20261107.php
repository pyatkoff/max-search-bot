<?php
declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','0');

function requireAudit(bool $ok,string $reason):void
{
    if (!$ok) throw new RuntimeException($reason);
}

$out=['operation'=>'commercial-read-existing-hotel-captions-20261107-v1','ok'=>false,'post_write_attempts'=>0,'provider_calls'=>0,'posts'=>[]];
$pdo=null;
try {
    requireAudit(PHP_SAPI==='cli' && trim((string)shell_exec('git rev-parse HEAD'))===EXPECTED_INSTALLED_SHA,'installed_source_changed');
    $raw=base64_decode(MANIFEST_BASE64,true);
    requireAudit(is_string($raw) && hash('sha256',$raw)===APPROVED_MANIFEST_SHA256,'approved_manifest_changed');
    $scope=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    requireAudit(($scope['operation']??null)===$out['operation'] && count($scope['rows']??[])===266,'audit_scope_changed');
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
        foreach ($channels as $platform=>$target) {
            $id=$platform==='max'?$item['max_publication_id']:(int)$telegramId;
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
            $caption=(string)($s['text']??'');
            $media=$s['attachments']??[];
            $out['posts'][]=$row+[
                'status'=>$p['state']==='sent'?'sent':'registry_unconfirmed',
                'publication_id'=>(int)$p['publication_id'],
                'version_id'=>(int)$p['version_id'],
                'message_id'=>(string)$p['external_message_id'],
                'caption'=>$caption,
                'caption_sha256'=>hash('sha256',$caption),
                'media_count'=>count($media),
                'media_types'=>array_values(array_map(static fn($m)=>(string)($m['type']??''),$media)),
                'button_url_sha256'=>hash('sha256',$button),
                'button_observed_in_snapshot'=>ChannelPostIncomingAdapter::containsOnlyButton($s,$button,'✈️ Узнать стоимость тура'),
                'button_resolves_to_current_post'=>is_array($resolved)
                    && (int)$resolved['publication_id']===(int)$p['publication_id']
                    && (int)$resolved['version_id']===(int)$p['version_id']
                    && $resolved['source_key']===$target['source'],
            ];
        }
    }
    requireAudit(count($out['posts'])===532,'audit_scope_incomplete');
    $pdo->exec('ROLLBACK');
    $out['ok']=true;
} catch (Throwable $error) {
    if ($pdo instanceof PDO) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
    }
    $message=$error->getMessage();
    $out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$message)===1?$message:'bounded_registry_audit_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
