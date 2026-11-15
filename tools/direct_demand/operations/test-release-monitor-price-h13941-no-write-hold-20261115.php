<?php
declare(strict_types=1);
define('H13941_TEST_LIBRARY',true);
require_once __DIR__.'/release-monitor-price-h13941-no-write-hold-20261115.php';

// Pure safety tests. No runtime configuration, DB, provider, journal handle or write is used.
$checks=0;
function h13941_test(bool $ok,string $case):void {
    global $checks;$checks++;if(!$ok)throw new RuntimeException('h13941_test_'.$case);
}
function h13941_refuses(callable $call,string $reason,string $case):void {
    $caught=null;try{$call();}catch(RuntimeException $e){$caught=$e->getMessage();}
    h13941_test($caught===$reason,$case);
}
$journal=['operation'=>H13941_OPERATION,'manifest_hash'=>H13941_MANIFEST,'posts'=>[],'configs'=>[],'complete'=>false,
    'feed_binding'=>['tourvisor_hotel_id'=>13941,'posts'=>array_map(static fn($t)=>$t['post_url'],H13941_TARGETS)],
    'terminal'=>H13941_TERMINAL,'_terminal_journal_sha256'=>H13941_CHILD_SHA256];
$state=['schema'=>1,'pending'=>null,'completed'=>[13941=>H13941_PRIOR_COMPLETED,512=>['operation'=>'synthetic_new_observation','receipt'=>'retained']],
    'holds'=>[13941=>H13941_HOLD,42=>['operation'=>'unrelated','reason'=>'unknown_existing_hold','extra'=>['retained'=>true]]],
    'nightly_cycle'=>['state'=>'budget_exhausted','collector_run_id'=>'43520564593','night_date'=>'2026-11-14',
        'collection_started_at'=>'2026-11-13T22:53:19+00:00','existing_unknown_cycle_field'=>['keep'=>true]],
    'unknown_object'=>(object)['empty'=>(object)[],'list'=>[]],'unknown_scalar'=>'retained','unknown_null'=>null];
$stateBefore=h13941_json($state);$journalBefore=h13941_json($journal);
$next=h13941_remove_hold($state,$journal);
h13941_test(!array_key_exists(13941,$next['holds'])&&$next['holds'][42]===$state['holds'][42],'only_exact_hold_removed');
h13941_test($next['completed']===$state['completed']&&$next['pending']===null&&$next['nightly_cycle']===$state['nightly_cycle'],'completion_pending_cycle_preserved');
h13941_test(h13941_json($state)===$stateBefore&&h13941_json($journal)===$journalBefore,'inputs_and_child_immutable');
$before=$state;$after=$next;unset($before['holds'],$after['holds']);h13941_test(h13941_json($before)===h13941_json($after),'unknown_and_other_fields_preserved');
$planned=h13941_next_line($stateBefore."\n",$journal);$object=json_decode($planned['line']);
h13941_test($object->unknown_object->empty instanceof stdClass&&is_array($object->unknown_object->list),'json_object_array_shapes_preserved');
$single=$state;$single['holds']=[13941=>H13941_HOLD];$singlePlan=h13941_next_line(h13941_json($single)."\n",$journal);
h13941_test(json_decode($singlePlan['line'])->holds instanceof stdClass&&$singlePlan['state']['holds']===[],'empty_holds_not_retyped');
h13941_refuses(static fn()=>h13941_remove_hold($next,$journal),'h13941_exact_hold_changed','second_invocation_refused');
foreach(['reason','operation','terminal_journal_sha256','at','extra'] as $field){
    $bad=$state;$bad['holds'][13941][$field]='changed';
    h13941_refuses(static fn()=>h13941_remove_hold($bad,$journal),'h13941_exact_hold_changed','changed_hold_'.$field);
}
$bad=$state;$bad['pending']=['phase'=>'post_started','operation'=>'active'];
h13941_refuses(static fn()=>h13941_remove_hold($bad,$journal),'h13941_pending_operation_stop','active_pending_refused');
$bad=$state;unset($bad['pending']);
h13941_refuses(static fn()=>h13941_remove_hold($bad,$journal),'h13941_pending_operation_stop','missing_pending_refused');
$bad=$state;$bad['nightly_cycle']['state']='started';
h13941_refuses(static fn()=>h13941_remove_hold($bad,$journal),'h13941_active_collector_cycle_stop','active_cycle_refused');
$bad=$state;$bad['nightly_cycle']['state']='unknown';
h13941_refuses(static fn()=>h13941_remove_hold($bad,$journal),'hook_nightly_history_invalid','unknown_cycle_refused');
$bad=$state;$bad['completed'][13941]=['operation'=>'different_observation'];
h13941_refuses(static fn()=>h13941_remove_hold($bad,$journal),'h13941_prior_completed_changed_stop','changed_target_history_refused');
foreach(['reason','recorded_at','prior_sha256','prior_bytes','prior_lines','preflight_error','child_exit_code'] as $field){
    $bad=$journal;$bad['terminal'][$field]='changed';
    h13941_refuses(static fn()=>h13941_remove_hold($state,$bad),'h13941_original_terminal_changed','changed_terminal_'.$field);
}
foreach(['manifest_hash','_terminal_journal_sha256','operation'] as $field){
    $bad=$journal;$bad[$field]='changed';
    h13941_refuses(static fn()=>h13941_remove_hold($state,$bad),'h13941_original_terminal_changed','changed_child_'.$field);
}
foreach(['posts','configs','complete'] as $field){
    $bad=$journal;$bad[$field]=$field==='complete'?true:['write_started'=>true];
    h13941_refuses(static fn()=>h13941_remove_hold($state,$bad),'h13941_original_has_write_intent','child_intent_'.$field);
}
h13941_refuses(static fn()=>h13941_child("{}\n{}\n"),'h13941_original_child_changed','unproven_child_bytes_refused');
h13941_refuses(static fn()=>h13941_next_line(' '.$stateBefore."\n",$journal),'h13941_global_encoding_changed','noncanonical_global_refused');
$duplicate=str_replace('"schema":1','"schema":2,"schema":1',$stateBefore)."\n";
h13941_refuses(static fn()=>h13941_next_line($duplicate,$journal),'h13941_global_encoding_changed','duplicate_global_key_refused');
h13941_source(H13941_INSTALLED,true,H13941_PINS);h13941_test(true,'accepted_source');
h13941_refuses(static fn()=>h13941_source(str_repeat('0',40),true,H13941_PINS),'h13941_installed_source_changed','changed_runtime_source_refused');
h13941_refuses(static fn()=>h13941_source(H13941_INSTALLED,false,H13941_PINS),'h13941_tracked_tree_changed','dirty_runtime_refused');
$pins=H13941_PINS;$pins['services/ChannelPostRegistryService.php']=str_repeat('0',40);
h13941_refuses(static fn()=>h13941_source(H13941_INSTALLED,true,$pins),'h13941_publishing_surface_changed','changed_runtime_pin_refused');
$pins=H13941_PINS;array_pop($pins);
h13941_refuses(static fn()=>h13941_source(H13941_INSTALLED,true,$pins),'h13941_publishing_surface_changed','missing_runtime_pin_refused');
h13941_no_active(false);h13941_test(true,'no_active_writer');
h13941_refuses(static fn()=>h13941_no_active(true),'h13941_active_publication_writer_stop','active_writer_refused');
foreach(H13941_TARGETS as $platform=>$t){
    $proof=['publication_id'=>$t['publication_id'],'version_id'=>$t['version_id'],'project_key'=>'anytour','platform'=>$platform,'bot_key'=>$t['bot'],
        'channel_id'=>$t['channel'],'source_key'=>$t['source'],'state'=>'sent','write_attempts'=>0,'claim_token'=>null,'claimed_at'=>null,
        'message_id'=>$t['message_id'],'post_url'=>$t['post_url'],'photo_signature'=>$t['photo_signature'],'button_url_sha256'=>$t['button_url_sha256'],
        'text_sha256'=>$t['text_sha256'],'monitor_price_record_id'=>H13941_PRIOR_RECORD,'only_expected_button'=>true,'button_resolves_current_version'=>true,
        'attachments_sha256'=>hash('sha256','synthetic-current-attachments')];
    h13941_context($platform,$proof);h13941_test(true,'accepted_current_'.$platform);
    $bad=$proof;$bad['text_sha256']='6bc4602248209fd7734affa6fab028954db0fd19704d7811dca5969732d1b022';
    h13941_refuses(static fn()=>h13941_context($platform,$bad),'h13941_current_caption_changed','historical_caption_'.$platform);
    $bad=$proof;$bad['version_id']=$t['previous_version_id'];
    h13941_refuses(static fn()=>h13941_context($platform,$bad),'h13941_current_publication_unresolved','historical_version_'.$platform);
    foreach(['publication_id','message_id','post_url','photo_signature','button_url_sha256'] as $field){
        $bad=$proof;$bad[$field]='changed';
        h13941_refuses(static fn()=>h13941_context($platform,$bad),'h13941_current_identity_changed','changed_'.$platform.'_'.$field);
    }
    $bad=$proof;$bad['claim_token']='active';
    h13941_refuses(static fn()=>h13941_context($platform,$bad),'h13941_current_publication_unresolved','claimed_'.$platform);
    $bad=$proof;$bad['state']='unknown';
    h13941_refuses(static fn()=>h13941_context($platform,$bad),'h13941_current_publication_unresolved','unknown_'.$platform);
    $bad=$proof;$bad['write_attempts']=1;
    h13941_refuses(static fn()=>h13941_context($platform,$bad),'h13941_current_publication_unresolved','write_attempt_'.$platform);
    $bad=$proof;$bad['only_expected_button']=false;
    h13941_refuses(static fn()=>h13941_context($platform,$bad),'h13941_current_photo_or_button_changed','button_mismatch_'.$platform);
    $bad=$proof;$bad['button_resolves_current_version']=false;
    h13941_refuses(static fn()=>h13941_context($platform,$bad),'h13941_current_photo_or_button_changed','stale_button_'.$platform);
    $bad=$proof;$bad['monitor_price_record_id']=substr(H13941_OPERATION,-64);
    h13941_refuses(static fn()=>h13941_context($platform,$bad),'h13941_current_caption_changed','old_record_not_reopened_'.$platform);
    $cap=monitor_price_channel_preflight($platform,['state'=>'ready','reason'=>'capability_verified','receipt'=>['request_count'=>3,'write_attempts'=>0,
        'http_results'=>array_map(static fn($op)=>['operation'=>$op,'http'=>200,'transport_error'=>false],['bot_identity','channel_rights','channel_type'])]]);
    h13941_ready($cap,$platform);h13941_test(true,'fresh_ready_'.$platform);
    foreach(['missing_error','denied','write','truncated','count','unknown_http','transport'] as $case){
        $bad=$cap;
        if($case==='missing_error')unset($bad['error']);elseif($case==='denied')$bad['state']='requires_attention';
        elseif($case==='write')$bad['write_attempts']=1;elseif($case==='truncated')$bad['http_results_truncated']=true;
        elseif($case==='count')$bad['request_count']=2;elseif($case==='unknown_http')$bad['http_results'][0]['http']=null;
        else $bad['http_results'][0]['transport_error']=true;
        $reason=in_array($case,['unknown_http','transport'],true)?'h13941_capability_http_not_ready':'h13941_current_channel_not_ready';
        h13941_refuses(static fn()=>h13941_ready($bad,$platform),$reason,'capability_'.$platform.'_'.$case);
    }
}
h13941_refuses(static fn()=>h13941_photo(['attachments'=>[['type'=>'image','provider'=>'max','photo_id'=>'49819906568'],['type'=>'image','provider'=>'max','photo_id'=>'1']]]),'h13941_current_photo_shape_changed','unexpected_second_photo');
h13941_test(h13941_photo(['attachments'=>[['type'=>'image','provider'=>'max','photo_id'=>'49819906568','token'=>'rotated-synthetic-token']]])===H13941_TARGETS['max']['photo_signature'],'stable_photo_id_not_ephemeral_token');

// The last successful observation remains present and immutable, including its feed.
h13941_test($next['completed'][13941]===H13941_PRIOR_COMPLETED,'prior_completed_hotel_preserved');
$bad=$state;unset($bad['completed'][13941]);
h13941_refuses(static fn()=>h13941_remove_hold($bad,$journal),'h13941_prior_completed_changed_stop','missing_prior_completed_refused');
$bad=$state;$bad['completed'][13941]['operation']=H13941_OPERATION;
h13941_refuses(static fn()=>h13941_remove_hold($bad,$journal),'h13941_prior_completed_changed_stop','held_operation_never_completed');
$response=['http'=>404,'transport_error'=>false,'data'=>['message'=>'not exported'],'headers'=>['Authorization'=>'secret'],'token'=>'secret'];
$diagnostic=h13941_max_diagnostic($response);
h13941_test($diagnostic['http']===404&&$diagnostic['transport_error']===false&&$diagnostic['data_is_array']===true,'http_failure_retained');
h13941_test(!str_contains(h13941_json($diagnostic),'secret')&&!str_contains(h13941_json($diagnostic),'not exported'),'status_diagnostic_redacted');
h13941_test($diagnostic['message_id']===H13941_TARGETS['max']['message_id']&&$diagnostic['request_count']===1,'status_diagnostic_fixed_scope');
$diagnostic=h13941_max_diagnostic(['http'=>0,'transport_error'=>true]);
h13941_test($diagnostic['http']===0&&$diagnostic['transport_error']===true&&!$diagnostic['data_is_array'],'transport_failure_retained');
$diagnostic=h13941_max_diagnostic(['http'=>'200','transport_error'=>'false','data'=>null]);
h13941_test($diagnostic['http']===null&&$diagnostic['transport_error']===null&&!$diagnostic['data_is_array'],'untyped_status_not_promoted');
$diagnostic=h13941_max_diagnostic(['http'=>200,'transport_error'=>false,'data'=>[]]);
h13941_test($diagnostic['http']===200&&$diagnostic['identity_matches']===null&&$diagnostic['caption_matches']===null&&$diagnostic['photo_matches']===null,'http_success_not_message_proof');
echo 'MONITOR_H13941_HOLD_RELEASE_SELF_TEST='.h13941_json(['ok'=>true,'checks'=>$checks,'filesystem_writes'=>0,'database_writes'=>0,'provider_requests'=>0])."\n";
