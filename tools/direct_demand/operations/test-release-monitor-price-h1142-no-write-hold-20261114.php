<?php
declare(strict_types=1);
define('H1142_TEST_LIBRARY',true);
require_once __DIR__.'/release-monitor-price-h1142-no-write-hold-20261114.php';

// Pure safety tests. No runtime configuration, DB, provider, journal handle or write is used.
$checks=0;
function h1142_test(bool $ok,string $case):void {
    global $checks;$checks++;if(!$ok)throw new RuntimeException('h1142_test_'.$case);
}
function h1142_refuses(callable $call,string $reason,string $case):void {
    $caught=null;try{$call();}catch(RuntimeException $e){$caught=$e->getMessage();}
    h1142_test($caught===$reason,$case);
}
$journal=['operation'=>H1142_OPERATION,'manifest_hash'=>H1142_MANIFEST,'posts'=>[],'configs'=>[],'complete'=>false,
    'feed_binding'=>['tourvisor_hotel_id'=>1142,'posts'=>array_map(static fn($t)=>$t['post_url'],H1142_TARGETS)],
    'terminal'=>H1142_TERMINAL,'_terminal_journal_sha256'=>H1142_CHILD_SHA256];
$state=['schema'=>1,'pending'=>null,'completed'=>[512=>['operation'=>'synthetic_new_observation','receipt'=>'retained']],
    'holds'=>[1142=>H1142_HOLD,42=>['operation'=>'unrelated','reason'=>'unknown_existing_hold','extra'=>['retained'=>true]]],
    'nightly_cycle'=>['state'=>'budget_exhausted','collector_run_id'=>'43520564593','night_date'=>'2026-11-14',
        'collection_started_at'=>'2026-11-13T22:53:19+00:00','existing_unknown_cycle_field'=>['keep'=>true]],
    'unknown_object'=>(object)['empty'=>(object)[],'list'=>[]],'unknown_scalar'=>'retained','unknown_null'=>null];
$stateBefore=h1142_json($state);$journalBefore=h1142_json($journal);
$next=h1142_remove_hold($state,$journal);
h1142_test(!array_key_exists(1142,$next['holds'])&&$next['holds'][42]===$state['holds'][42],'only_exact_hold_removed');
h1142_test($next['completed']===$state['completed']&&$next['pending']===null&&$next['nightly_cycle']===$state['nightly_cycle'],'completion_pending_cycle_preserved');
h1142_test(h1142_json($state)===$stateBefore&&h1142_json($journal)===$journalBefore,'inputs_and_child_immutable');
$before=$state;$after=$next;unset($before['holds'],$after['holds']);h1142_test(h1142_json($before)===h1142_json($after),'unknown_and_other_fields_preserved');
$planned=h1142_next_line($stateBefore."\n",$journal);$object=json_decode($planned['line']);
h1142_test($object->unknown_object->empty instanceof stdClass&&is_array($object->unknown_object->list),'json_object_array_shapes_preserved');
$single=$state;$single['holds']=[1142=>H1142_HOLD];$singlePlan=h1142_next_line(h1142_json($single)."\n",$journal);
h1142_test(json_decode($singlePlan['line'])->holds instanceof stdClass&&$singlePlan['state']['holds']===[],'empty_holds_not_retyped');
h1142_refuses(static fn()=>h1142_remove_hold($next,$journal),'h1142_exact_hold_changed','second_invocation_refused');
foreach(['reason','operation','terminal_journal_sha256','at','extra'] as $field){
    $bad=$state;$bad['holds'][1142][$field]='changed';
    h1142_refuses(static fn()=>h1142_remove_hold($bad,$journal),'h1142_exact_hold_changed','changed_hold_'.$field);
}
$bad=$state;$bad['pending']=['phase'=>'post_started','operation'=>'active'];
h1142_refuses(static fn()=>h1142_remove_hold($bad,$journal),'h1142_pending_operation_stop','active_pending_refused');
$bad=$state;unset($bad['pending']);
h1142_refuses(static fn()=>h1142_remove_hold($bad,$journal),'h1142_pending_operation_stop','missing_pending_refused');
$bad=$state;$bad['nightly_cycle']['state']='started';
h1142_refuses(static fn()=>h1142_remove_hold($bad,$journal),'h1142_active_collector_cycle_stop','active_cycle_refused');
$bad=$state;$bad['nightly_cycle']['state']='unknown';
h1142_refuses(static fn()=>h1142_remove_hold($bad,$journal),'hook_nightly_history_invalid','unknown_cycle_refused');
$bad=$state;$bad['completed'][1142]=['operation'=>'different_observation'];
h1142_refuses(static fn()=>h1142_remove_hold($bad,$journal),'h1142_target_already_completed_stop','changed_target_history_refused');
foreach(['reason','recorded_at','prior_sha256','prior_bytes','prior_lines','readback_evidence_sha256'] as $field){
    $bad=$journal;$bad['terminal'][$field]='changed';
    h1142_refuses(static fn()=>h1142_remove_hold($state,$bad),'h1142_original_terminal_changed','changed_terminal_'.$field);
}
foreach(['manifest_hash','_terminal_journal_sha256','operation'] as $field){
    $bad=$journal;$bad[$field]='changed';
    h1142_refuses(static fn()=>h1142_remove_hold($state,$bad),'h1142_original_terminal_changed','changed_child_'.$field);
}
foreach(['posts','configs','complete'] as $field){
    $bad=$journal;$bad[$field]=$field==='complete'?true:['write_started'=>true];
    h1142_refuses(static fn()=>h1142_remove_hold($state,$bad),'h1142_original_has_write_intent','child_intent_'.$field);
}
h1142_refuses(static fn()=>h1142_child("{}\n{}\n"),'h1142_original_child_changed','unproven_child_bytes_refused');
h1142_refuses(static fn()=>h1142_next_line(' '.$stateBefore."\n",$journal),'h1142_global_encoding_changed','noncanonical_global_refused');
$duplicate=str_replace('"schema":1','"schema":2,"schema":1',$stateBefore)."\n";
h1142_refuses(static fn()=>h1142_next_line($duplicate,$journal),'h1142_global_encoding_changed','duplicate_global_key_refused');
h1142_source(H1142_INSTALLED,true,H1142_PINS);h1142_test(true,'accepted_source');
h1142_refuses(static fn()=>h1142_source(str_repeat('0',40),true,H1142_PINS),'h1142_installed_source_changed','changed_runtime_source_refused');
h1142_refuses(static fn()=>h1142_source(H1142_INSTALLED,false,H1142_PINS),'h1142_tracked_tree_changed','dirty_runtime_refused');
$pins=H1142_PINS;$pins['services/ChannelPostRegistryService.php']=str_repeat('0',40);
h1142_refuses(static fn()=>h1142_source(H1142_INSTALLED,true,$pins),'h1142_publishing_surface_changed','changed_runtime_pin_refused');
$pins=H1142_PINS;array_pop($pins);
h1142_refuses(static fn()=>h1142_source(H1142_INSTALLED,true,$pins),'h1142_publishing_surface_changed','missing_runtime_pin_refused');
h1142_no_active(false);h1142_test(true,'no_active_writer');
h1142_refuses(static fn()=>h1142_no_active(true),'h1142_active_publication_writer_stop','active_writer_refused');
foreach(H1142_TARGETS as $platform=>$t){
    $proof=['publication_id'=>$t['publication_id'],'version_id'=>5000,'project_key'=>'anytour','platform'=>$platform,'bot_key'=>$t['bot'],
        'channel_id'=>$t['channel'],'source_key'=>$t['source'],'state'=>'sent','write_attempts'=>0,'claim_token'=>null,'claimed_at'=>null,
        'message_id'=>$t['message_id'],'post_url'=>$t['post_url'],'photo_signature'=>$t['photo_signature'],'button_url_sha256'=>$t['button_url_sha256'],
        'text_sha256'=>H1142_TEXT,'monitor_price_record_id'=>null,'only_expected_button'=>true,'button_resolves_current_version'=>true,
        'attachments_sha256'=>hash('sha256','synthetic-current-attachments')];
    h1142_context($platform,$proof);h1142_test(true,'accepted_current_'.$platform);
    $bad=$proof;$bad['text_sha256']='6bc4602248209fd7734affa6fab028954db0fd19704d7811dca5969732d1b022';
    h1142_refuses(static fn()=>h1142_context($platform,$bad),'h1142_current_caption_changed','historical_caption_'.$platform);
    $bad=$proof;$bad['version_id']=$t['previous_version_id'];
    h1142_refuses(static fn()=>h1142_context($platform,$bad),'h1142_current_publication_unresolved','historical_version_'.$platform);
    foreach(['publication_id','message_id','post_url','photo_signature','button_url_sha256'] as $field){
        $bad=$proof;$bad[$field]='changed';
        h1142_refuses(static fn()=>h1142_context($platform,$bad),'h1142_current_identity_changed','changed_'.$platform.'_'.$field);
    }
    $bad=$proof;$bad['claim_token']='active';
    h1142_refuses(static fn()=>h1142_context($platform,$bad),'h1142_current_publication_unresolved','claimed_'.$platform);
    $bad=$proof;$bad['state']='unknown';
    h1142_refuses(static fn()=>h1142_context($platform,$bad),'h1142_current_publication_unresolved','unknown_'.$platform);
    $bad=$proof;$bad['write_attempts']=1;
    h1142_refuses(static fn()=>h1142_context($platform,$bad),'h1142_current_publication_unresolved','write_attempt_'.$platform);
    $bad=$proof;$bad['only_expected_button']=false;
    h1142_refuses(static fn()=>h1142_context($platform,$bad),'h1142_current_photo_or_button_changed','button_mismatch_'.$platform);
    $bad=$proof;$bad['button_resolves_current_version']=false;
    h1142_refuses(static fn()=>h1142_context($platform,$bad),'h1142_current_photo_or_button_changed','stale_button_'.$platform);
    $bad=$proof;$bad['monitor_price_record_id']=substr(H1142_OPERATION,-64);
    h1142_refuses(static fn()=>h1142_context($platform,$bad),'h1142_current_caption_changed','old_record_not_reopened_'.$platform);
    $cap=monitor_price_channel_preflight($platform,['state'=>'ready','reason'=>'capability_verified','receipt'=>['request_count'=>3,'write_attempts'=>0,
        'http_results'=>array_map(static fn($op)=>['operation'=>$op,'http'=>200,'transport_error'=>false],['bot_identity','channel_rights','channel_type'])]]);
    h1142_ready($cap,$platform);h1142_test(true,'fresh_ready_'.$platform);
    foreach(['missing_error','denied','write','truncated','count','unknown_http','transport'] as $case){
        $bad=$cap;
        if($case==='missing_error')unset($bad['error']);elseif($case==='denied')$bad['state']='requires_attention';
        elseif($case==='write')$bad['write_attempts']=1;elseif($case==='truncated')$bad['http_results_truncated']=true;
        elseif($case==='count')$bad['request_count']=2;elseif($case==='unknown_http')$bad['http_results'][0]['http']=null;
        else $bad['http_results'][0]['transport_error']=true;
        $reason=in_array($case,['unknown_http','transport'],true)?'h1142_capability_http_not_ready':'h1142_current_channel_not_ready';
        h1142_refuses(static fn()=>h1142_ready($bad,$platform),$reason,'capability_'.$platform.'_'.$case);
    }
}
h1142_refuses(static fn()=>h1142_photo(['attachments'=>[['type'=>'image','provider'=>'max','photo_id'=>'49855578632'],['type'=>'image','provider'=>'max','photo_id'=>'1']]]),'h1142_current_photo_shape_changed','unexpected_second_photo');
h1142_test(h1142_photo(['attachments'=>[['type'=>'image','provider'=>'max','photo_id'=>'49855578632','token'=>'rotated-synthetic-token']]])===H1142_TARGETS['max']['photo_signature'],'stable_photo_id_not_ephemeral_token');
echo 'MONITOR_H1142_HOLD_RELEASE_SELF_TEST='.h1142_json(['ok'=>true,'checks'=>$checks,'filesystem_writes'=>0,'database_writes'=>0,'provider_requests'=>0])."\n";
