<?php
declare(strict_types=1);
require __DIR__.'/monitor-price-hook-contract.php';
$roster=json_decode(file_get_contents(__DIR__.'/monitor-price-hook-roster.json'),true,64,JSON_THROW_ON_ERROR);
$handoff=json_decode(file_get_contents(__DIR__.'/monitor-price-six-20261111-handoff.json'),true,64,JSON_THROW_ON_ERROR);
$cases=0;
foreach($roster['rows'] as $r){
    $matches=array_values(array_filter($handoff['hotels'],static fn($h)=>(int)$h['tourvisorHotelId']===$r['tourvisor_hotel_id']));
    if(!$matches)continue;
    $h=$matches[0];
    $price=monitor_price_observation($h['source'],$r['tourvisor_hotel_id'],strtotime($h['source']['generatedAt']));
    foreach(['max','telegram'] as $channel){
        $text=monitor_hook_draft($r['templates'][$channel],$price);
        monitor_price_require(str_replace(["\u{00a0}","\u{202f}"],' ',$h['updatePlan']['posts'][$channel]['text'])===$text,'hook_existing_six_drafts_changed');$cases++;
    }
    $operation=monitor_price_operation($price);$feed=monitor_hook_feed_operation($operation,'20261111');
    monitor_price_require($feed===monitor_hook_feed_operation($operation,'20261111')&&preg_match('/\Acommercial-channel-hotel-feeds-20261111-update[1-9][0-9]*\z/D',$feed)===1,'hook_feed_operation_unstable');$cases++;
}
$r=$roster['rows'][0];$record=str_repeat('a',64);$text='accepted automated caption';
$p=['publication_id'=>7,'version_id'=>9,'external_message_id'=>'mid.test','snapshot'=>['text'=>$text,'post_url'=>$r['posts']['max'],'metadata'=>['monitor_price_record_id'=>$record]]];
$journal=['complete'=>true,'operation'=>'commercial-monitor-price-h'.$r['tourvisor_hotel_id'].'-'.$record,'feed_binding'=>$r['feed_binding'],'posts'=>[['state'=>'confirmed','receipt'=>['platform'=>'max','publication_id'=>7,'version_id'=>9,'message_id'=>'mid.test','post_url'=>$r['posts']['max'],'text'=>$text,'text_sha256'=>hash('sha256',$text),'existing_message_reused'=>true,'body_photo_button_verified'=>true]]]];
monitor_price_require(monitor_hook_caption_allowed($p,$r,'max',$journal),'hook_completed_edit_not_recognized');$cases++;
foreach(['caption','version','operation','complete','url','identity','receipt','missing'] as $bad){
    $pp=$p;$jj=$journal;
    if($bad==='caption')$pp['snapshot']['text']='manual edit';
    if($bad==='version')$pp['version_id']=10;
    if($bad==='operation')$jj['operation'].='x';
    if($bad==='complete')$jj['complete']=false;
    if($bad==='url')$pp['snapshot']['post_url'].='x';
    if($bad==='identity')$pp['publication_id']=8;
    if($bad==='receipt')$jj['posts'][0]['state']='edit_started';
    if($bad==='missing')$jj=null;
    monitor_price_require(!monitor_hook_caption_allowed($pp,$r,'max',$jj),'hook_manual_or_unknown_accepted');$cases++;
}
$fixture=[['tourvisor_hotel_id'=>3],['tourvisor_hotel_id'=>2],['tourvisor_hotel_id'=>1]];
$ordered=monitor_hook_order($fixture,[1=>['at'=>'2026-11-11T10:00:00Z'],3=>['at'=>'2026-11-10T10:00:00Z']]);
monitor_price_require(array_column($ordered,'tourvisor_hotel_id')===[2,3,1],'hook_unprocessed_starved');$cases++;
$checked=['known_failures'=>[['tour_id'=>'known-tour','at'=>'2026-11-11T12:00:00Z','reason'=>'retained_operator_rejection_2']]];
monitor_price_require(monitor_hook_known_failure($checked,['tour_id'=>'known-tour','observed_at'=>'2026-11-11T11:00:00Z'])==='retained_operator_rejection_2','hook_known_rejection_ignored');$cases++;
monitor_price_require(monitor_hook_known_failure($checked,['tour_id'=>'different-tour','observed_at'=>'2026-11-11T11:00:00Z'])===null,'hook_different_tour_blocked');$cases++;
monitor_price_require(monitor_hook_known_failure($checked,['tour_id'=>'known-tour','observed_at'=>'2026-11-11T13:00:00Z'])===null,'hook_newer_observation_blocked');$cases++;
echo 'MONITOR_PRICE_HOOK_OK cases='.$cases." no_provider_calls=1 no_writes=1\n";
