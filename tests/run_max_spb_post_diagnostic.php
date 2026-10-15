<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/tools/max_spb_post_diagnostic.php';
$secret = 'NEVER_EMIT_PRIVATE_VALUE';
$row = [
    'version_number'=>1,'state'=>'requires_attention','error_reason'=>'album_not_supported',
    'attempt_count'=>1,'write_attempts'=>0,'observation_count'=>1,
    'snapshot_json'=>json_encode([
        'post_url'=>'https://max.ru/c/-77309177991187/AaE-mekYJW0',
        'text'=>$secret,'raw_body'=>['sender'=>['user_id'=>$secret]],
        'attachments'=>[['type'=>'image','photo_id'=>$secret,'token'=>$secret,'url'=>$secret],['type'=>'image','photo_id'=>$secret]],
        'metadata'=>['capability'=>'requires_attention','capability_reason'=>'album_not_supported','source_event_id'=>$secret],
        'keyboard'=>[[['url'=>$secret,'text'=>$secret]]],
    ]),
    'receipt_json'=>json_encode(['event_id'=>$secret,'message_id'=>$secret,'verified'=>false,'request_count'=>2,'raw'=>$secret,'http_results'=>[['operation'=>'channel_rights','http'=>200,'transport_error'=>false,'raw'=>$secret],['operation'=>$secret,'http'=>200]]]),
];
$out = MaxSpbPostDiagnostic::summarize($row);
if (str_contains(json_encode($out), $secret)) throw new RuntimeException('Privacy regression');
if ($out['error_reason'] !== 'album_not_supported' || $out['media_types'] !== ['image'=>2] || !$out['target_permalink_match'] || $out['receipt']['http_results'] !== [['operation'=>'channel_rights','http'=>200,'transport_error'=>false]]) throw new RuntimeException('Summary regression');
$row['error_reason']=$secret;
$row['snapshot_json']=json_encode(['post_url'=>$secret,'attachments'=>[['type'=>$secret]],'metadata'=>['capability'=>$secret,'capability_reason'=>$secret]]);
$out=MaxSpbPostDiagnostic::summarize($row);
if (str_contains(json_encode($out), $secret) || $out['error_reason']!=='unrecognized_reason' || $out['target_permalink_match']) throw new RuntimeException('Untrusted enum regression');
if (MaxSpbPostDiagnostic::blocked('test')['writes']!==0) throw new RuntimeException('Write contract regression');
echo "PASS fixed diagnostic redaction, media summary, exact permalink and untrusted enum checks\n";
