<?php
declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','0');
function requireFormatRead(bool $ok,string $reason):void { if (!$ok) throw new RuntimeException($reason); }
$out=['operation'=>'commercial-short-heading-native-format-read-20261108-v1','ok'=>false,
    'post_write_attempts'=>0,'configuration_write_attempts'=>0,'provider_read_calls'=>0,
    'operation_file_writes'=>0,'installed_sha'=>EXPECTED_INSTALLED_SHA,'posts'=>[]];
$pdo=null;
try {
    requireFormatRead(PHP_SAPI==='cli'&&trim((string)shell_exec('git rev-parse HEAD'))===EXPECTED_INSTALLED_SHA,'installed_source_changed');
    requireFormatRead(trim((string)shell_exec('git status --porcelain --untracked-files=no'))==='','installed_checkout_changed');
    $operation='/var/www/anytoour/data/channel-post-operations/commercial-short-heading-20261108-canary-v1';
    requireFormatRead(!is_link($operation)&&!file_exists($operation),'canary_operation_exists_no_replay');
    $out['canary_operation_directory_absent']=true;
    ob_start();
    try {
        require getcwd().'/config.php';
        require_once getcwd().'/services/ChannelPostRuntime.php';
        require_once getcwd().'/services/ChannelPostEntryService.php';
        require_once getcwd().'/services/ChannelPostButtonTransport.php';
        require_once getcwd().'/integrations/ChannelPostApiTransport.php';
    } finally { ob_end_clean(); }
    $pdo=ConversationDb::connection();$pdo->exec('START TRANSACTION READ ONLY');
    $registry=new ChannelPostRegistryService($pdo);
    foreach ([['platform'=>'max','id'=>432,'version'=>668,'message'=>'mid.ffffb6caf0606fed01a1a7691c1b1d29','channel'=>'-80492244209683','source'=>'max_anytour_msk1'],
              ['platform'=>'telegram','id'=>431,'version'=>669,'message'=>'171','channel'=>'-1002262666616','source'=>'tg_anytour_msk']] as $target) {
        $p=$registry->publication($target['id']);
        requireFormatRead(is_array($p)&&(int)$p['publication_id']===$target['id']&&(int)$p['version_id']===$target['version']
            &&$p['state']==='sent'&&$p['project_key']==='anytour'&&$p['platform']===$target['platform']
            &&$p['external_message_id']===$target['message']&&$p['channel_id']===$target['channel']&&$p['source_key']===$target['source'],
            'canary_current_identity_changed');
        $s=$p['snapshot'];
        requireFormatRead(hash('sha256',(string)($s['text']??''))==='7bd4c85d689770f0c9f6a5875112b5e4ad014d10436a757eb428c3999a01df36','canary_old_caption_changed');
        $row=['platform'=>$target['platform'],'publication_id'=>$target['id'],'version_id'=>$target['version'],
            'text'=>$s['text'],'text_sha256'=>hash('sha256',$s['text']),'registry_markup'=>$s['markup']??null,
            'post_url'=>$s['post_url']??null];
        if ($target['platform']==='max') {
            $out['provider_read_calls']++;
            $r=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$target['message'],[],null);
            requireFormatRead(empty($r['transport_error'])&&(int)$r['http']===200&&is_array($r['data']??null),'max_native_read_failed');
            $m=$r['data'];
            requireFormatRead(($m['body']['mid']??null)===$target['message']&&(string)($m['recipient']['chat_id']??'')===$target['channel']
                &&($m['recipient']['chat_type']??'')==='channel','max_native_identity_changed');
            $native=ChannelPostIncomingAdapter::maxSnapshot($m);
            requireFormatRead(is_array($native)&&($native['text']??null)===$s['text']&&($native['post_url']??null)===($s['post_url']??null),
                'max_native_caption_changed');
            $url=ChannelPostButtonTransport::buttonUrl($p);
            requireFormatRead(ChannelPostIncomingAdapter::containsOnlyButton($native,$url,'✈️ Узнать стоимость тура'),'max_native_button_changed');
            $photo=$native['attachments']??[];
            requireFormatRead(count($photo)===1&&($photo[0]['type']??'')==='image'&&(string)($photo[0]['photo_id']??'')==='49283728136',
                'max_native_photo_changed');
            $row['native_markup']=$native['markup']??null;
            $row['provider_body_markup']=$m['body']['markup']??null;
            $row['photo_signature']='max:49283728136';$row['existing_button_verified']=true;
        }
        $out['posts'][]=$row;
    }
    $pdo->exec('ROLLBACK');$out['ok']=true;
} catch(Throwable $error) {
    if ($pdo instanceof PDO) { try {$pdo->exec('ROLLBACK');} catch(Throwable $ignored){} }
    $reason=$error->getMessage();$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$reason)===1?$reason:'bounded_native_format_read_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
