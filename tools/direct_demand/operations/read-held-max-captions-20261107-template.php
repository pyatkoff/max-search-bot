<?php
declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','0');

function requireReadback(bool $ok,string $reason):void
{
    if (!$ok) throw new RuntimeException($reason);
}

function photoKey(array $s):string
{
    $media=$s['attachments']??[];
    if (count($media)!==1 || ($media[0]['type']??'')!=='image') return '';
    return (string)($media[0]['photo_id']??'');
}

$out=['operation'=>'commercial-read-held-max-captions-20261107-v1','ok'=>false,
      'post_write_attempts'=>0,'provider_reads'=>0,'posts'=>[]];
$pdo=null;
try {
    requireReadback(PHP_SAPI==='cli' && trim((string)shell_exec('git rev-parse HEAD'))===EXPECTED_INSTALLED_SHA,'installed_source_changed');
    $raw=base64_decode(MANIFEST_BASE64,true);
    requireReadback(is_string($raw)&&hash('sha256',$raw)===APPROVED_MANIFEST_SHA256,'readback_scope_changed');
    $payload=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
    requireReadback(($payload['operation']??null)===$out['operation'],'readback_operation_changed');
    $rows=$payload['rows']??[];
    requireReadback(count($rows)>0&&count($rows)<=30,'readback_scope_outside_range');
    ob_start();
    try {
        require getcwd().'/config.php';
        require_once getcwd().'/services/ChannelPostRuntime.php';
        require_once getcwd().'/services/ChannelPostAdminService.php';
        require_once getcwd().'/services/ChannelPostEntryService.php';
        require_once getcwd().'/services/ChannelPostButtonTransport.php';
        require_once getcwd().'/integrations/ChannelPostApiTransport.php';
    } finally {
        ob_end_clean();
    }
    $pdo=ConversationDb::connection();
    $pdo->exec('START TRANSACTION READ ONLY');
    $registry=new ChannelPostRegistryService($pdo);
    $identity=ChannelPostRuntime::identity('max');
    requireReadback(is_array($identity)&&$identity['project_key']==='anytour'&&$identity['bot_key']==='id9704048781_1_bot','runtime_identity_changed');
    foreach ($rows as $row) {
        $id=$row['publication_id'];
        $mid=$row['message_id'];
        requireReadback(is_int($id)&&is_int($row['hotel_id'])&&preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/',$mid)===1,'invalid_readback_identity');
        requireReadback(preg_match('~\Ahttps://max\.ru/channel_anytour/[A-Za-z0-9_-]+\z~D',$row['post_url'])===1,'invalid_readback_public_link');
        $p=$registry->publication($id);
        requireReadback(is_array($p)&&$p['project_key']==='anytour'&&$p['platform']==='max'&&$p['channel_id']==='-80492244209683'
                     &&$p['source_key']==='max_anytour_msk1'&&(string)$p['external_message_id']===$mid&&($p['snapshot']['post_url']??null)===$row['post_url'],'readback_identity_changed');
        $s=$p['snapshot'];
        $button=ChannelPostButtonTransport::buttonUrl($p);
        $resolved=ChannelPostEntryService::resolve('max',$p['start_payload'],$registry);
        $out['provider_reads']++;
        $read=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$mid,[],null);
        requireReadback(empty($read['transport_error'])&&(int)$read['http']===200&&is_array($read['data']),'held_max_read_failed');
        $actual=ChannelPostIncomingAdapter::maxSnapshot($read['data']);
        requireReadback(is_array($actual)&&($actual['post_url']??null)===$row['post_url'],'held_max_post_url_changed');
        $caption=(string)$actual['text'];
        $out['posts'][]=[
            'hotel_id'=>$row['hotel_id'],'post_url'=>$row['post_url'],'message_id'=>$mid,'publication_id'=>$id,
            'version_id'=>(int)$p['version_id'],'registry_state'=>$p['state'],
            'registry_caption'=>(string)$s['text'],'registry_caption_sha256'=>hash('sha256',(string)$s['text']),
            'live_caption'=>$caption,'live_caption_sha256'=>hash('sha256',$caption),
            'same_photo'=>photoKey($actual)!==''&&photoKey($actual)===photoKey($s),
            'canonical_button_observed'=>ChannelPostIncomingAdapter::containsOnlyButton($actual,$button,'✈️ Узнать стоимость тура'),
            'canonical_button_url_sha256'=>hash('sha256',$button),
            'canonical_route_resolves'=>is_array($resolved)&&(int)$resolved['publication_id']===$id&&(int)$resolved['version_id']===(int)$p['version_id'],
        ];
        usleep(400000);
    }
    $pdo->exec('ROLLBACK');
    $out['ok']=true;
} catch (Throwable $error) {
    if ($pdo instanceof PDO) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
    }
    $message=$error->getMessage();
    $out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$message)===1?$message:'bounded_held_max_read_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
