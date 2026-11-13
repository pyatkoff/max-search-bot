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

// Targeted no-write terminal cases use retained fixture bytes; no runtime, database or API.
function hook_test_check(bool $ok,string $name):void { global $cases;monitor_price_require($ok,$name);$cases++; }
function hook_test_reject(callable $run,string $name):void {
    global $cases;$failed=false;try{$run();}catch(Throwable $e){$failed=true;}
    monitor_price_require($failed,$name);$cases++;
}
function hook_test_json_line(array $state):string { return json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"; }
$terminalHotel=$handoff['hotels'][0];$terminalSnapshot=$terminalHotel['source'];$terminalTv=(int)$terminalHotel['tourvisorHotelId'];
$terminalPrice=monitor_price_observation($terminalSnapshot,$terminalTv,strtotime($terminalSnapshot['generatedAt']));
$terminalOperation=monitor_price_operation($terminalPrice);
$terminalBinding=array_values(array_filter($roster['rows'],static fn($r)=>$r['tourvisor_hotel_id']===$terminalTv))[0]['feed_binding'];
$terminalOriginal=['operation'=>$terminalOperation,'manifest_hash'=>str_repeat('b',64),'configs'=>[],'posts'=>[],
    'monitor_snapshot'=>$terminalSnapshot,'feed_binding'=>$terminalBinding];
$terminalPrefix=hook_test_json_line($terminalOriginal);
$terminalAt=gmdate('c',strtotime($terminalPrice['offer']['expiresAt'])+60);
$terminalLine=monitor_hook_terminal_line($terminalPrefix,$terminalOperation,$terminalAt,'expired_observation_after_no_write_failure',
    ['readback_evidence_sha256'=>str_repeat('c',64)]);
$terminalParsed=monitor_hook_parse_journal($terminalPrefix.$terminalLine,$terminalOperation);
hook_test_check(substr($terminalPrefix.$terminalLine,0,strlen($terminalPrefix))===$terminalPrefix,'terminal_original_prefix_changed');
hook_test_check(($terminalParsed['complete']??false)===false&&$terminalParsed['posts']===[]&&$terminalParsed['configs']===[]
    &&$terminalParsed['manifest_hash']===$terminalOriginal['manifest_hash'],'terminal_falsely_completed');
hook_test_check($terminalParsed['_terminal_journal_sha256']===hash('sha256',$terminalPrefix.$terminalLine),'terminal_hash_not_actual_bytes');

$terminalGlobal=['schema'=>1,'pending'=>['tourvisor_hotel_id'=>$terminalTv,'operation'=>$terminalOperation,'phase'=>'post_started','at'=>'2026-11-11T22:20:06+00:00'],'completed'=>[]];
foreach(range(1000,1048) as $doneId)$terminalGlobal['completed'][$doneId]=['operation'=>'retained-'.$doneId,'at'=>'2026-11-11T20:00:00+00:00','feed_operation'=>'retained-feed-'.$doneId];
$terminalNext=monitor_hook_apply_abandonment($terminalGlobal,$terminalParsed);
hook_test_check(is_array($terminalNext)&&$terminalNext['pending']===null&&count($terminalNext['completed'])===49
    &&$terminalNext['completed']===$terminalGlobal['completed'],'terminal_changed_accepted_completed');
hook_test_check(monitor_hook_hold_reason($terminalTv,$terminalNext['holds'][$terminalTv],$terminalParsed)==='abandoned_before_write','terminal_hold_missing');

foreach(['posts_started','posts_confirmed','config_started','already_complete'] as $bad){
    $changed=$terminalOriginal;
    if($bad==='posts_started')$changed['posts']=['max:fixture'=>['state'=>'edit_started']];
    if($bad==='posts_confirmed')$changed['posts']=['max:fixture'=>['state'=>'confirmed']];
    if($bad==='config_started')$changed['configs']=['fixture'=>['state'=>'started']];
    if($bad==='already_complete')$changed['complete']=true;
    // A later empty state cannot erase earlier intent or success.
    $history=hook_test_json_line($changed).$terminalPrefix;
    hook_test_reject(static fn()=>monitor_hook_terminal_line($history,$terminalOperation,$terminalAt,'expired_observation_after_no_write_failure',
        ['readback_evidence_sha256'=>str_repeat('c',64)]),'terminal_admitted_prior_'.$bad);
}
hook_test_reject(static fn()=>monitor_hook_terminal_line(substr($terminalPrefix,0,-1),$terminalOperation,$terminalAt,'expired_observation_after_no_write_failure',
    ['readback_evidence_sha256'=>str_repeat('c',64)]),'terminal_admitted_partial_prefix');
hook_test_reject(static fn()=>monitor_hook_terminal_line($terminalPrefix,$terminalOperation,$terminalSnapshot['generatedAt'],'expired_observation_after_no_write_failure',
    ['readback_evidence_sha256'=>str_repeat('c',64)]),'terminal_accepted_unexpired_abandonment');

foreach(['hash','bytes','lines','manifest','snapshot','record','unverified_field'] as $bad){
    $changed=json_decode($terminalLine,true,64,JSON_THROW_ON_ERROR);
    if($bad==='hash')$changed['terminal']['prior_sha256']=str_repeat('d',64);
    if($bad==='bytes')$changed['terminal']['prior_bytes']++;
    if($bad==='lines')$changed['terminal']['prior_lines']++;
    if($bad==='manifest')$changed['manifest_hash']=str_repeat('d',64);
    if($bad==='snapshot')$changed['monitor_snapshot']['item']['offers'][0]['price']++;
    if($bad==='record')$changed['operation']='commercial-monitor-price-h'.$terminalTv.'-'.str_repeat('d',64);
    if($bad==='unverified_field')$changed['terminal']['skip_readback']=true;
    hook_test_reject(static fn()=>monitor_hook_parse_journal($terminalPrefix.hook_test_json_line($changed),$terminalOperation),'terminal_admitted_changed_'.$bad);
}
$wrongPending=$terminalGlobal;$wrongPending['pending']['phase']='feed_started';
hook_test_reject(static fn()=>monitor_hook_apply_abandonment($wrongPending,$terminalParsed),'terminal_admitted_feed_started');
$wrongHold=$terminalNext['holds'][$terminalTv];$wrongHold['terminal_journal_sha256']=str_repeat('d',64);
hook_test_reject(static fn()=>monitor_hook_hold_reason($terminalTv,$wrongHold,$terminalParsed),'terminal_ignored_changed_hold');
$wrongDone=$terminalGlobal;$wrongDone['completed'][$terminalTv]=['operation'=>$terminalOperation];
hook_test_reject(static fn()=>monitor_hook_apply_abandonment($wrongDone,$terminalParsed),'terminal_reclassified_completed');
$normalComplete=$terminalOriginal;$normalComplete['complete']=true;
hook_test_check(monitor_hook_parse_journal(hook_test_json_line($normalComplete),$terminalOperation)===$normalComplete
    &&monitor_hook_apply_abandonment($terminalGlobal,$normalComplete)===null,'terminal_changed_legacy_complete_path');

hook_test_check(monitor_hook_fixed_child_error('existing_max_readback_changed')==='existing_max_readback_changed','child_fixed_code_lost');
hook_test_check(monitor_hook_fixed_child_error("request failed https://example.test/?token=private")===null
    &&monitor_hook_fixed_child_error(['provider'=>'body'])===null,'child_unstructured_error_exposed');
$localFailure=['exit_code'=>2,'error'=>'existing_max_readback_changed'];
hook_test_check(monitor_hook_local_preflight_failure($localFailure),'local_no_write_failure_not_admitted');
$autoLine=monitor_hook_terminal_line($terminalPrefix,$terminalOperation,$terminalAt,'child_preflight_failed_before_write',
    ['preflight_error'=>$localFailure['error'],'child_exit_code'=>2]);
$autoParsed=monitor_hook_parse_journal($terminalPrefix.$autoLine,$terminalOperation);
hook_test_check($autoParsed['terminal']['preflight_error']===$localFailure['error']
    &&monitor_hook_apply_abandonment($terminalGlobal,$autoParsed)['completed']===$terminalGlobal['completed'],'local_failure_terminal_changed_result');
foreach(['publishing_surface_changed','publishing_schema_not_accepted','channel_configuration_changed','provider_channel_rights_changed',
    'prior_edit_outcome_unresolved','telegram_edit_not_confirmed_no_retry','unrecognized_error'] as $blocked){
    hook_test_check(!monitor_hook_local_preflight_failure(['exit_code'=>2,'error'=>$blocked]),'nonlocal_failure_admitted_'.$blocked);
    hook_test_reject(static fn()=>monitor_hook_terminal_line($terminalPrefix,$terminalOperation,$terminalAt,'child_preflight_failed_before_write',
        ['preflight_error'=>$blocked,'child_exit_code'=>2]),'terminal_admitted_nonlocal_'.$blocked);
}
hook_test_check(!monitor_hook_local_preflight_failure(['exit_code'=>-1,'error'=>'max_read_failed'])
    &&!monitor_hook_local_preflight_failure(['exit_code'=>0,'error'=>'max_read_failed']),'uncertain_child_exit_admitted');

// Exercise the actual driver reconciliation function with recording dependencies.
// Only that function is evaluated; the CLI driver and all real I/O remain unloaded.
$testReadJournal=$terminalParsed;$testDriverCalls=['append'=>0,'child'=>0,'feed'=>0,'read'=>0];
function hook_need(bool $ok,string $why):void { monitor_price_require($ok,$why); }
function hook_journal(string $operation):?array { global $testReadJournal;return $testReadJournal; }
function hook_append($lock,array $state):void { global $testDriverCalls;$testDriverCalls['append']++; }
function hook_child(string $source,array $constants,?array &$failure=null):array { global $testDriverCalls;$testDriverCalls['child']++;throw new RuntimeException('test_unexpected_child'); }
function hook_prepare_feed(array $journal,string $installed):array { global $testDriverCalls;$testDriverCalls['feed']++;throw new RuntimeException('test_unexpected_feed'); }
function hook_read(string $path,int $max=10000000):string { global $testDriverCalls;$testDriverCalls['read']++;throw new RuntimeException('test_unexpected_read'); }
function hook_get(string $url,int $max=4000000):string { global $testDriverCalls;$testDriverCalls['read']++;throw new RuntimeException('test_unexpected_read'); }
$driver=file_get_contents(__DIR__.'/monitor-price-after-collection.php');
$start=strpos($driver,'function hook_finish_feed(');$end=strpos($driver,"\n\n".'$inspect=',$start);
hook_test_check($start!==false&&$end!==false,'driver_reconciliation_fixture_missing');
eval(substr($driver,$start,$end-$start));
$testGlobal=$terminalGlobal;
hook_test_check(hook_finish_feed($testGlobal,null,str_repeat('e',40))==='held_before_write'
    &&$testGlobal===$terminalNext&&$testDriverCalls===['append'=>1,'child'=>0,'feed'=>0,'read'=>0],'driver_replayed_terminal_before_feed');
$testReadJournal=$terminalOriginal;$testReadJournal['posts']=['max:fixture'=>['state'=>'edit_started']];
$beforeCalls=$testDriverCalls;$testGlobal=$terminalGlobal;
$unresolvedStopped=false;try{hook_finish_feed($testGlobal,null,str_repeat('e',40));}catch(Throwable $e){$unresolvedStopped=$e->getMessage()==='hook_post_outcome_unresolved_stop';}
hook_test_check($unresolvedStopped&&$testGlobal===$terminalGlobal&&$testDriverCalls===$beforeCalls,'driver_reconciled_unknown_or_partial');

// Use the actual loop gate: old/new observations of the held hotel both skip,
// while an unrelated row remains available. No duplicate gate implementation.
$testReadJournal=$terminalParsed;$state=$terminalNext;$out=['held_pairs'=>0,'held_ids'=>[],'deferred'=>[]];$visited=[];
$rows=[['tourvisor_hotel_id'=>$terminalTv,'record_id'=>$terminalPrice['record_id']],
    ['tourvisor_hotel_id'=>$terminalTv,'record_id'=>str_repeat('f',64)],['tourvisor_hotel_id'=>999999]];
$start=strpos($driver,"        ".'$tv=$row[\'tourvisor_hotel_id\'];');$end=strpos($driver,"        try{",$start);
hook_test_check($start!==false&&$end!==false,'driver_hold_gate_fixture_missing');
eval('foreach($rows as $row){'.substr($driver,$start,$end-$start).'$visited[]=$row[\'tourvisor_hotel_id\'];}');
hook_test_check($visited===[999999]&&$out['held_pairs']===2&&$state['completed']===$terminalGlobal['completed'],'driver_new_record_bypassed_hold');

// Exercise actual packet construction and the first/subsequent legacy caption gates.
$expanded=json_decode(gzdecode(file_get_contents(__DIR__.'/monitor-price-expanded-roster-20261111.json.gz')),true,64,JSON_THROW_ON_ERROR);
$legacyRows=array_values(array_filter($expanded['rows'],static fn($r)=>array_key_exists('legacy_proof',$r)));
$packetStart=strpos($driver,"            ".'$r=array_intersect_key($row,');
$packetEnd=strpos($driver,"        }catch(Throwable ".'$e)', $packetStart);
hook_test_check($packetStart!==false&&$packetEnd!==false,'actual_driver_packet_fixture_missing');
foreach($legacyRows as $row){
    $tv=$row['tourvisor_hotel_id'];$snapshot=$terminalSnapshot;$snapshot['scope']['tourvisorHotelIds']=[$tv];$snapshot['item']['tourvisorHotelId']=$tv;
    foreach($snapshot['item']['offers'] as $i=>$offer){$snapshot['item']['offers'][$i]['tourvisorHotelId']=$tv;$snapshot['item']['offers'][$i]['recordId']=hash('sha256','legacy-test-'.$tv.'-'.$i);}
    $price=monitor_price_observation($snapshot,$tv,strtotime($snapshot['generatedAt']));$op=monitor_price_operation($price);$context=[];
    foreach(['max','telegram'] as $platform){
        $c=$row['legacy_proof']['contexts'][$platform];
        $slot="💰 [Минимальная актуальная цена тура — подтвердить перед выпуском]\n[Город вылета · даты · ночи · состав туристов · номер · питание · время проверки]\n\n";
        $caption=str_replace($slot,'',$row['templates'][$platform]);
        $context[$platform]=['publication_id'=>$c['publication_id'],'version_id'=>$c['baseline_version_id'],
            'external_message_id'=>$c['message_id'],'snapshot'=>['text'=>$caption,'post_url'=>$c['post_url'],'metadata'=>[]]];
        hook_test_check(monitor_hook_caption_allowed($context[$platform],$row,$platform,null),'legacy_first_baseline_rejected');
        $manual=$context[$platform];$manual['version_id']++;
        hook_test_check(!monitor_hook_caption_allowed($manual,$row,$platform,null),'legacy_same_text_manual_revision_accepted');
    }
    eval(substr($driver,$packetStart,$packetEnd-$packetStart));
    $packet=json_decode($raw,true,64,JSON_THROW_ON_ERROR);$childRow=$packet['rows'][0];
    hook_test_check(monitor_price_legacy_binding($childRow)===$row['legacy_proof']
        &&$childRow['hotel_id']===$row['hotel_id']&&!array_key_exists('cover_sha256',$childRow)
        &&!array_key_exists('original_publication_operation',$childRow),'driver_lost_legacy_proof_or_publisher_role');
    $journal=['complete'=>true,'operation'=>$op,'feed_binding'=>$row['feed_binding'],'posts'=>[]];
    foreach(['max','telegram'] as $platform){
        $p=$context[$platform];$p['version_id']++;$p['snapshot']['text']=$childRow['channel_texts'][$platform]['new_text'];
        $p['snapshot']['metadata']['monitor_price_record_id']=$price['record_id'];$context[$platform]=$p;
        $journal['posts'][$platform]=['state'=>'confirmed','receipt'=>['platform'=>$platform,'publication_id'=>$p['publication_id'],'version_id'=>$p['version_id'],
            'message_id'=>$p['external_message_id'],'post_url'=>$p['snapshot']['post_url'],'text'=>$p['snapshot']['text'],
            'text_sha256'=>hash('sha256',$p['snapshot']['text']),'existing_message_reused'=>true,'body_photo_button_verified'=>true]];
        monitor_price_validate_text($p['snapshot']['text'],$price);
        hook_test_check(str_ends_with($p['snapshot']['text'],'✈️ Подберём подходящий номер и рассчитаем тур с перелётом на ваши даты.'),'legacy_accepted_cta_lost');
    }
    foreach(['max','telegram'] as $platform){
        $p=$context[$platform];
        hook_test_check(monitor_hook_caption_allowed($p,$row,$platform,$journal),'legacy_second_cycle_rejected');
        hook_test_check(!monitor_hook_caption_allowed($p,$row,$platform,null),'legacy_second_cycle_without_receipt_accepted');
        $bad=$journal;$bad['complete']=false;hook_test_check(!monitor_hook_caption_allowed($p,$row,$platform,$bad),'legacy_unknown_monitor_accepted');
        $bad=$journal;$bad['feed_binding']['tourvisor_hotel_id']=9373;hook_test_check(!monitor_hook_caption_allowed($p,$row,$platform,$bad),'legacy_foreign_monitor_feed_accepted');
        $bad=$p;$bad['version_id']++;hook_test_check(!monitor_hook_caption_allowed($bad,$row,$platform,$journal),'legacy_later_manual_revision_accepted');
    }
}
foreach(['hook_legacy_source_incomplete_stop','hook_legacy_source_unresolved_stop'] as $error)
    hook_test_check(!monitor_hook_local_preflight_failure(['exit_code'=>2,'error'=>$error]),'legacy_unknown_source_auto_released');

// All23 retained additions use the actual packet code; no provider or filesystem journals are created.
$additionProof=json_decode(file_get_contents(__DIR__.'/monitor-price-remaining23-20261113-proof.json'),true,64,JSON_THROW_ON_ERROR);
$addedRows=array_values(array_filter($expanded['rows'],static fn($r)=>in_array($r['tourvisor_hotel_id'],$additionProof['appended_tourvisor_ids'],true)));
hook_test_check(count($addedRows)===23&&count($expanded['rows'])>=592,'remaining_published_scope_changed');
$captionCount=0;$canonicalCount=0;
foreach($addedRows as $row){
    $tv=$row['tourvisor_hotel_id'];$snapshot=$terminalSnapshot;$snapshot['scope']['tourvisorHotelIds']=[$tv];$snapshot['item']['tourvisorHotelId']=$tv;
    foreach($snapshot['item']['offers'] as $i=>$offer){$snapshot['item']['offers'][$i]['tourvisorHotelId']=$tv;$snapshot['item']['offers'][$i]['recordId']=hash('sha256','remaining23-test-'.$tv.'-'.$i);}
    $price=monitor_price_observation($snapshot,$tv,strtotime($snapshot['generatedAt']));$op=monitor_price_operation($price);$context=[];
    $captionMode=array_key_exists('caption_proof',$row);
    $sourceProof=array_values(array_filter($additionProof['rows'],static fn($r)=>$r['tourvisor_hotel_id']===$tv))[0];
    foreach(['max','telegram'] as $platform){
        $c=$sourceProof['current_accepted_contexts'][$platform];
        $slot="💰 [Минимальная актуальная цена тура — подтвердить перед выпуском]\n[Город вылета · даты · ночи · состав туристов · номер · питание · время проверки]\n\n";
        $caption=str_replace($slot,'',$row['templates'][$platform]);
        hook_test_check(hash('sha256',$caption)===$row['expected_text_sha256'][$platform],'remaining_template_changed_accepted_caption');
        $context[$platform]=['publication_id'=>$c['publication_id'],'version_id'=>$c['baseline_version_id'],
            'external_message_id'=>$c['message_id'],'snapshot'=>['text'=>$caption,'post_url'=>$c['post_url'],'metadata'=>[]]];
        hook_test_check(monitor_hook_caption_allowed($context[$platform],$row,$platform,null),'remaining_first_baseline_rejected');
        $manual=$context[$platform];$manual['snapshot']['text'].=' manual';
        hook_test_check(!monitor_hook_caption_allowed($manual,$row,$platform,null),'remaining_manual_caption_accepted');
        if($captionMode){$manual=$context[$platform];$manual['version_id']++;
            hook_test_check(!monitor_hook_caption_allowed($manual,$row,$platform,null),'caption_same_text_manual_revision_accepted');}
    }
    eval(substr($driver,$packetStart,$packetEnd-$packetStart));
    $packet=json_decode($raw,true,64,JSON_THROW_ON_ERROR);$childRow=$packet['rows'][0];
    hook_test_check($childRow['hotel_id']===$row['hotel_id']&&$packet['feed_binding']===$row['feed_binding'],'remaining_packet_identity_changed');
    if($captionMode){
        $captionCount++;
        hook_test_check(monitor_price_caption_binding($childRow)===$row['caption_proof']&&$childRow['hotel_id']===null
            &&!array_key_exists('cover_sha256',$childRow)&&!array_key_exists('original_publication_operation',$childRow)
            &&!array_key_exists('original_text_sha256',$childRow['channel_texts']['max'])
            &&!array_key_exists('original_text_sha256',$childRow['channel_texts']['telegram']),'driver_lost_caption_proof_or_invented_sender');
    }else{
        $canonicalCount++;
        hook_test_check($childRow['original_publication_operation']===$row['original_publication_operation']
            &&$childRow['cover_sha256']===$row['cover_sha256']&&!array_key_exists('caption_proof',$childRow)
            &&$childRow['channel_texts']['max']['original_text_sha256']===$row['original_text_sha256']
            &&$childRow['channel_texts']['telegram']['original_text_sha256']===$row['original_text_sha256'],'remaining_canonical_packet_changed');
    }
    $journal=['complete'=>true,'operation'=>$op,'feed_binding'=>$row['feed_binding'],'posts'=>[]];
    foreach(['max','telegram'] as $platform){
        $p=$context[$platform];$p['version_id']++;$p['snapshot']['text']=$childRow['channel_texts'][$platform]['new_text'];
        $p['snapshot']['metadata']['monitor_price_record_id']=$price['record_id'];$context[$platform]=$p;
        $journal['posts'][$platform]=['state'=>'confirmed','receipt'=>['platform'=>$platform,'publication_id'=>$p['publication_id'],'version_id'=>$p['version_id'],
            'message_id'=>$p['external_message_id'],'post_url'=>$p['snapshot']['post_url'],'text'=>$p['snapshot']['text'],
            'text_sha256'=>hash('sha256',$p['snapshot']['text']),'existing_message_reused'=>true,'body_photo_button_verified'=>true]];
        $closing=substr($row['templates'][$platform],strrpos($row['templates'][$platform],"\n\n")+2);
        hook_test_check(str_ends_with($p['snapshot']['text'],"\n\n".$closing),'remaining_accepted_closing_lost');
    }
    foreach(['max','telegram'] as $platform){
        $p=$context[$platform];
        hook_test_check(monitor_hook_caption_allowed($p,$row,$platform,$journal),'remaining_second_cycle_rejected');
        hook_test_check(!monitor_hook_caption_allowed($p,$row,$platform,null),'remaining_second_cycle_without_receipt_accepted');
        $bad=$journal;$bad['complete']=false;hook_test_check(!monitor_hook_caption_allowed($p,$row,$platform,$bad),'remaining_unknown_monitor_accepted');
        $bad=$p;$bad['version_id']++;hook_test_check(!monitor_hook_caption_allowed($bad,$row,$platform,$journal),'remaining_later_manual_revision_accepted');
    }
}
hook_test_check($captionCount===3&&$canonicalCount===20,'remaining_provenance_kind_counts_changed');
foreach(['hook_caption_source_incomplete_stop','hook_caption_source_unresolved_stop'] as $error)
    hook_test_check(!monitor_hook_local_preflight_failure(['exit_code'=>2,'error'=>$error]),'caption_unknown_source_auto_released');

echo 'MONITOR_PRICE_HOOK_OK cases='.$cases." no_provider_calls=1 no_writes=1\n";

