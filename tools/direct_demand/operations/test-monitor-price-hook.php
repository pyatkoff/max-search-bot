<?php
declare(strict_types=1);
require __DIR__.'/monitor-price-hook-contract.php';
require_once __DIR__.'/monitor-price-telegram-ads19.php';
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

$priorityTime=new DateTimeImmutable('2026-11-22 12:00',new DateTimeZone('Europe/Moscow'));
$publicationFeed='<yml_catalog><shop><offers>'
    .'<offer id="max_1"><price>100</price><description>Вылет из Москвы 23.11.2026. Цена найдена мониторингом 22.11.2026, 11:45 МСК</description></offer>'
    .'<offer id="max_2"><description>Путёвка в отель</description></offer>'
    .'<offer id="max_3"><price>100</price><description>Вылет из Москвы 23.11.2026. Цена найдена мониторингом 21.11.2026, 00:00 МСК</description></offer>'
    .'<offer id="tg_4"><price>100</price><description>Вылет из Москвы 21.11.2026. Цена найдена мониторингом 22.11.2026, 11:45 МСК</description></offer>'
    .'<offer id="max_5"><price>100</price><description>Цена найдена мониторингом 21.11.2026 00:01 МСК</description></offer>'
    .'</offers></shop></yml_catalog>';
$publicationDriver=file_get_contents(__DIR__.'/monitor-price-after-collection.php');
$priorityStart=strpos($publicationDriver,'function hook_publication_order(');
$priorityEnd=strpos($publicationDriver,'/** Same saved monitor reader',$priorityStart===false?0:$priorityStart);
monitor_price_require($priorityStart!==false&&$priorityEnd!==false,'hook_publication_priority_source_missing');
eval(substr($publicationDriver,$priorityStart,$priorityEnd-$priorityStart));
$rows=[];
foreach([5,3,4,1,2] as $id)$rows[]=['tourvisor_hotel_id'=>$id,'feed_binding'=>['offer_ids'=>['max'=>'max_'.$id,'telegram'=>'tg_'.$id]]];
$oldestFirst=monitor_hook_order($rows,[1=>['at'=>'2026-11-21T10:00:00Z'],3=>['at'=>'2026-11-22T09:00:00Z'],4=>['at'=>'2026-11-22T09:00:00Z']]);
$priorityOrder=hook_publication_order($oldestFirst,$publicationFeed,$priorityTime->setTime(0,0),$priorityTime->getTimestamp());
monitor_price_require(array_column($priorityOrder,'tourvisor_hotel_id')===[4,2,3,5,1],'hook_feed_priority_does_not_precede_age');$cases++;
$checked=['known_failures'=>[['tour_id'=>'known-tour','at'=>'2026-11-11T12:00:00Z','reason'=>'retained_operator_rejection_2']]];
monitor_price_require(monitor_hook_known_failure($checked,['tour_id'=>'known-tour','observed_at'=>'2026-11-11T11:00:00Z'])==='retained_operator_rejection_2','hook_known_rejection_ignored');$cases++;
monitor_price_require(monitor_hook_known_failure($checked,['tour_id'=>'different-tour','observed_at'=>'2026-11-11T11:00:00Z'])===null,'hook_different_tour_blocked');$cases++;
monitor_price_require(monitor_hook_known_failure($checked,['tour_id'=>'known-tour','observed_at'=>'2026-11-11T13:00:00Z'])===null,'hook_newer_observation_blocked');$cases++;

// Targeted no-write terminal cases use retained fixture bytes; no runtime, database or API.
function hook_test_check(bool $ok,string $name):void { global $cases;monitor_price_require($ok,$name);$cases++; }
$snapshotStart=strpos($publicationDriver,'function hook_snapshot_batch(');
$snapshotEnd=strpos($publicationDriver,'function hook_output_and_exit(',$snapshotStart===false?0:$snapshotStart);
hook_test_check($snapshotStart!==false&&$snapshotEnd!==false,'hook_snapshot_reader_source_missing');
eval(substr($publicationDriver,$snapshotStart,$snapshotEnd-$snapshotStart));
$readScope=[];
$fixtureReader=static function(string $url)use(&$readScope):string {
    parse_str(parse_url($url,PHP_URL_QUERY),$query);
    $ids=array_map('intval',$query['tourvisorHotelIds']);
    $readScope[]=$ids;
    if(count($ids)>5)throw new RuntimeException('hook_http_read_failed');
    return json_encode(['ok'=>true,'items'=>array_map(static fn(int $id):array=>['tourvisorHotelId'=>$id],$ids)],JSON_THROW_ON_ERROR);
};
$snapshotRoster=array_map(static fn(int $id):array=>['tourvisor_hotel_id'=>$id],range(1,12));
$snapshotSet=hook_snapshots($snapshotRoster,$priorityTime,$fixtureReader);
hook_test_check(array_keys($snapshotSet)===range(1,12)
    &&$readScope===[range(1,12),range(1,5),range(6,10),[11,12]],'hook_export_retry_changes_scope_or_order');
$identityCalls=0;
try {
    hook_snapshots($snapshotRoster,$priorityTime,static function()use(&$identityCalls):string {
        $identityCalls++;
        return '{"ok":true,"items":[]}';
    });
    hook_test_check(false,'hook_export_missing_identity_accepted');
} catch(RuntimeException $error) {
    hook_test_check($error->getMessage()==='hook_monitor_identity_missing'&&$identityCalls===1,'hook_export_identity_failure_retried');
}
$transportCalls=0;
try {
    hook_snapshots($snapshotRoster,$priorityTime,static function()use(&$transportCalls):string {
        $transportCalls++;
        throw new RuntimeException('hook_http_read_failed');
    });
    hook_test_check(false,'hook_export_unavailable_accepted');
} catch(RuntimeException $error) {
    hook_test_check($error->getMessage()==='hook_http_read_failed'&&$transportCalls===2,'hook_export_unbounded_retry');
}

$ads19Roster = json_decode(gzdecode(file_get_contents(__DIR__.'/monitor-price-expanded-roster-20261111.json.gz')), true, 64, JSON_THROW_ON_ERROR);
$ads19Count = 0;
foreach ($ads19Roster['rows'] as $ads19Row) {
    $ads19Id = $ads19Row['tourvisor_hotel_id'];
    $ads19Redirect = monitor_hook_telegram_redirect($ads19Id);
    $ads19Next = monitor_hook_apply_telegram_redirect($ads19Row);
    if ($ads19Redirect === null) {
        hook_test_check($ads19Next === $ads19Row, 'unrelated_price_binding_changed');
        continue;
    }
    $ads19Count++;
    [$ads19Old, $ads19New] = $ads19Redirect;
    $ads19Expected = $ads19Row;
    $ads19Expected['feed_binding']['destinations']['telegram'] = $ads19New;
    hook_test_check($ads19Next === $ads19Expected, 'price_ad_destination_scope_changed');
    hook_test_check(monitor_hook_select_telegram_redirect($ads19Row, $ads19Old, $ads19Old) === $ads19Row, 'price_old_pair_rejected');
    hook_test_check(monitor_hook_select_telegram_redirect($ads19Row, $ads19New, $ads19New) === $ads19Next, 'price_new_pair_rejected');
    hook_test_check(monitor_hook_receipt_feed_binding_matches($ads19Row['feed_binding'], $ads19Next), 'price_historical_receipt_rejected');
    hook_test_check(monitor_hook_receipt_feed_binding_matches($ads19Next['feed_binding'], $ads19Row), 'price_rollback_receipt_rejected');
    $ads19Changed = $ads19Row['feed_binding'];
    $ads19Changed['offer_ids']['max'] = 'max_wrong';
    hook_test_check(!monitor_hook_receipt_feed_binding_matches($ads19Changed, $ads19Next), 'price_foreign_binding_accepted');
    try {
        monitor_hook_select_telegram_redirect($ads19Row, $ads19Old, $ads19New);
        hook_test_check(false, 'price_split_pair_accepted');
    } catch (RuntimeException $ads19Error) {
        hook_test_check($ads19Error->getMessage() === 'hook_telegram_redirect_feed_not_ready', 'price_split_pair_accepted');
    }
}
hook_test_check($ads19Count === 19, 'price_ad_redirect_scope_count_changed');
unset($ads19Roster, $ads19Count, $ads19Row, $ads19Id, $ads19Redirect, $ads19Next, $ads19Old, $ads19New, $ads19Expected, $ads19Changed);
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

// Run the actual editor gate against the installed transport's bounded result shapes.
// No provider call, child process, journal or publication is used by these cases.
final class ChannelPostButtonTransport {
    public static array $preflightResult=[];
    public static int $preflightCalls=0;
    public function verifyChannel(array $channel):array { self::$preflightCalls++;return self::$preflightResult; }
}
function need(bool $ok,string $reason):void { monitor_price_require($ok,$reason); }
function hook_test_editor_preflight(array $verification,string $platform,string $source):array {
    ChannelPostButtonTransport::$preflightResult=$verification;ChannelPostButtonTransport::$preflightCalls=0;
    $channel=['platform'=>$platform];$channels=[];$out=['post_write_attempts'=>0];$error=null;
    try{eval($source);}catch(Throwable $e){$error=$e->getMessage();}
    return ['error'=>$error,'out'=>$out,'admitted'=>isset($channels[$platform]),'calls'=>ChannelPostButtonTransport::$preflightCalls];
}
$editorSource=file_get_contents(__DIR__.'/update-commercial-price-20261104-remaining1.php');
$preflightStart=strpos($editorSource,'$channelVerification=(new ChannelPostButtonTransport())->verifyChannel($channel);');
$preflightEnd=strpos($editorSource,'$channels[$platform]=$channel;',$preflightStart===false?0:$preflightStart);
hook_test_check($preflightStart!==false&&$preflightEnd!==false,'editor_preflight_gate_missing');
$preflightSource=substr($editorSource,$preflightStart,$preflightEnd-$preflightStart+strlen('$channels[$platform]=$channel;'));
$preflightHttp=[['operation'=>'bot_identity','http'=>200,'transport_error'=>false],
    ['operation'=>'channel_rights','http'=>200,'transport_error'=>false],['operation'=>'channel_type','http'=>200,'transport_error'=>false]];
$unavailableHttp=array_slice($preflightHttp,0,2);$unavailableHttp[1]['http']=503;
$preflightCases=[
    ['max','unknown','provider_read_failed',$unavailableHttp,'provider_channel_preflight_unavailable'],
    ['telegram','requires_attention','channel_edit_permission_required',array_slice($preflightHttp,0,2),'provider_channel_permission_denied'],
    ['max','requires_attention','canonical_bot_identity_mismatch',array_slice($preflightHttp,0,1),'provider_channel_identity_mismatch'],
    ['max','unknown','verification_failure',[],'provider_channel_preflight_unavailable'],
    ['max','ready','capability_verified',$preflightHttp,null],
];
foreach($preflightCases as [$platform,$stateCode,$reason,$http,$expectedError]){
    $verification=['state'=>$stateCode,'reason'=>$reason,'receipt'=>['request_count'=>count($http),'write_attempts'=>0,'http_results'=>$http]];
    $checked=hook_test_editor_preflight($verification,$platform,$preflightSource);$safe=$checked['out']['channel_preflight'];
    hook_test_check($checked['error']===$expectedError&&$checked['admitted']===($expectedError===null)
        &&$checked['calls']===1&&$checked['out']['post_write_attempts']===0,'preflight_admission_or_retry_changed_'.$reason);
    hook_test_check($safe['platform']===$platform&&$safe['state']===$stateCode&&$safe['reason']===$reason
        &&$safe['request_count']===count($http)&&$safe['write_attempts']===0&&$safe['http_results']===$http,'preflight_evidence_lost_'.$reason);
    if($expectedError!==null){
        $failure=monitor_hook_child_failure(2,['ok'=>false,'error'=>$expectedError,'channel_preflight'=>$safe]);
        hook_test_check($failure['error']===$expectedError&&$failure['channel_preflight']===$safe
            &&!monitor_hook_local_preflight_failure($failure),'preflight_failure_not_retained_or_released_'.$reason);
        hook_test_reject(static fn()=>monitor_hook_terminal_line($terminalPrefix,$terminalOperation,$terminalAt,'child_preflight_failed_before_write',
            ['preflight_error'=>$expectedError,'child_exit_code'=>2]),'preflight_failure_auto_abandoned_'.$reason);
    }
}
$unsafePreflight=['state'=>'requires_attention','reason'=>'LEAK_SECRET','token'=>'LEAK_SECRET','raw_response'=>'LEAK_SECRET',
    'receipt'=>['request_count'=>5,'write_attempts'=>0,'http_results'=>array_fill(0,6,
        ['operation'=>'https://example.test/LEAK_SECRET','http'=>'LEAK_SECRET','transport_error'=>'LEAK_SECRET','data'=>['token'=>'LEAK_SECRET']])]];
$boundedPreflight=monitor_price_channel_preflight('max',$unsafePreflight);
$boundedFailure=monitor_hook_child_failure(2,['error'=>'provider_channel_verification_failed','channel_preflight'=>$boundedPreflight,
    'stdout'=>'LEAK_SECRET','stderr'=>'LEAK_SECRET']);
hook_test_check(count($boundedPreflight['http_results'])===5&&$boundedPreflight['http_results_truncated']===true
    &&$boundedFailure['channel_preflight']===$boundedPreflight&&!str_contains(json_encode($boundedFailure,JSON_THROW_ON_ERROR),'LEAK_SECRET'),'preflight_provider_data_leaked_or_unbounded');
$unsafeReady=['state'=>'ready','reason'=>'capability_verified','receipt'=>['request_count'=>3,'write_attempts'=>1,'http_results'=>$preflightHttp]];
$checked=hook_test_editor_preflight($unsafeReady,'max',$preflightSource);
hook_test_check(!$checked['admitted']&&$checked['error']==='provider_channel_verification_failed','preflight_unexpected_write_admitted');
$wrongState=['state'=>'unknown','reason'=>'channel_edit_permission_required','receipt'=>['request_count'=>2,'write_attempts'=>0,'http_results'=>array_slice($preflightHttp,0,2)]];
hook_test_check(monitor_price_channel_preflight('max',$wrongState)['error']==='provider_channel_verification_failed','preflight_unknown_reported_as_denied');
foreach(['unknown','ready'] as $stateCode){
    $rawVerification=['state'=>$stateCode,'reason'=>$stateCode==='ready'?'capability_verified':'provider_read_failed',
        'receipt'=>['request_count'=>1,'write_attempts'=>0,'http_results'=>['not_a_list'=>$preflightHttp[0]]]];
    $checked=hook_test_editor_preflight($rawVerification,'max',$preflightSource);$projected=$checked['out']['channel_preflight'];
    $carried=monitor_hook_child_failure(2,['error'=>$checked['error'],'channel_preflight'=>$projected]);
    hook_test_check(!$checked['admitted']&&$projected['receipt_valid']===false&&$carried['channel_preflight']===$projected
        &&$carried['channel_preflight']['error']!==null&&!monitor_hook_channel_preflight_failure($carried),'malformed_original_receipt_became_valid_after_projection_'.$stateCode);
}
$invalidState=['state'=>'invalid_original_state','reason'=>'provider_read_failed','receipt'=>['request_count'=>1,'write_attempts'=>0,'http_results'=>[$preflightHttp[0]]]];
$checked=hook_test_editor_preflight($invalidState,'max',$preflightSource);
$carried=monitor_hook_child_failure(2,['error'=>$checked['error'],'channel_preflight'=>$checked['out']['channel_preflight']]);
hook_test_check($carried['channel_preflight']['receipt_valid']===false&&!monitor_hook_channel_preflight_failure($carried),'unrecognized_original_state_became_proven_after_projection');

// Exercise the actual driver reconciliation function with recording dependencies.
// Only that function is evaluated; the CLI driver and all real I/O remain unloaded.
$testReadJournal=$terminalParsed;$testDriverCalls=['append'=>0,'child'=>0,'feed'=>0,'read'=>0];$testAppendedStates=[];$testChildFailure=null;
function hook_need(bool $ok,string $why):void { monitor_price_require($ok,$why); }
function hook_journal(string $operation):?array { global $testReadJournal;return $testReadJournal; }
function hook_append($lock,array $state):void { global $testDriverCalls,$testAppendedStates;$testDriverCalls['append']++;$testAppendedStates[]=$state; }
function hook_child(string $source,array $constants,?array &$failure=null):array {
    global $testDriverCalls,$testChildFailure;$testDriverCalls['child']++;
    if(is_array($testChildFailure)){$failure=$testChildFailure;throw new RuntimeException('hook_child_failed_read_original_journal');}
    throw new RuntimeException('test_unexpected_child');
}
function hook_prepare_feed(array $journal,string $installed):array { global $testDriverCalls;$testDriverCalls['feed']++;throw new RuntimeException('test_unexpected_feed'); }
function hook_read(string $path,int $max=10000000):string { global $testDriverCalls;$testDriverCalls['read']++;throw new RuntimeException('test_unexpected_read'); }
function hook_get(string $url,int $max=4000000):string { global $testDriverCalls;$testDriverCalls['read']++;throw new RuntimeException('test_unexpected_read'); }
$driver=file_get_contents(__DIR__.'/monitor-price-after-collection.php');
$measureStart=strpos($driver,'function hook_measure(');$measureEnd=strpos($driver,'function hook_read(',$measureStart===false?0:$measureStart);
hook_test_check($measureStart!==false&&$measureEnd!==false,'driver_measure_fixture_missing');
eval(substr($driver,$measureStart,$measureEnd-$measureStart));
$childFailureStart=strpos($driver,"    if(".'$exit!==0||($result[\'ok\']??false)!==true){');
$childFailureEnd=strpos($driver,'    }return $result;',$childFailureStart===false?0:$childFailureStart);
hook_test_check($childFailureStart!==false&&$childFailureEnd!==false,'driver_child_failure_gate_missing');
$exit=2;$failure=null;$result=['ok'=>false,'error'=>'provider_channel_preflight_unavailable',
    'channel_preflight'=>monitor_price_channel_preflight('max',['state'=>'unknown','reason'=>'provider_read_failed',
        'receipt'=>['request_count'=>2,'write_attempts'=>0,'http_results'=>$unavailableHttp]])];
$childStopped=false;
try{eval(substr($driver,$childFailureStart,$childFailureEnd-$childFailureStart).'    }');}
catch(Throwable $e){$childStopped=$e->getMessage()==='hook_child_failed_read_original_journal';}
hook_test_check($childStopped&&$failure===monitor_hook_child_failure(2,$result),'driver_discarded_channel_preflight');
$start=strpos($driver,'function hook_finish_feed(');$end=strpos($driver,"\n\n".'$inspect=',$start);
hook_test_check($start!==false&&$end!==false,'driver_reconciliation_fixture_missing');
eval(substr($driver,$start,$end-$start));
$testGlobal=$terminalGlobal;$testMetrics=[];
hook_test_check(hook_finish_feed($testGlobal,null,str_repeat('e',40),$testMetrics)==='held_before_write'
    &&$testGlobal===$terminalNext&&$testDriverCalls===['append'=>1,'child'=>0,'feed'=>0,'read'=>0],'driver_replayed_terminal_before_feed');
$testReadJournal=$terminalOriginal;$testReadJournal['posts']=['max:fixture'=>['state'=>'edit_started']];
$beforeCalls=$testDriverCalls;$testGlobal=$terminalGlobal;
$unresolvedStopped=false;try{hook_finish_feed($testGlobal,null,str_repeat('e',40),$testMetrics);}catch(Throwable $e){$unresolvedStopped=$e->getMessage()==='hook_post_outcome_unresolved_stop';}
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

// Global channel failures close this exact pass without a hotel hold or a second child.
// The actual editor catch/stop/reconcile functions execute against recording dependencies.
$testTerminalPrefix=$terminalPrefix;$testTerminalAppends=0;
function hook_abandon_failed_preflight(string $operation,array $failure):array {
    global $testTerminalPrefix,$testTerminalAppends,$testReadJournal,$testAppendedStates;
    $saved=end($testAppendedStates);
    if(monitor_hook_local_preflight_failure($failure)){
        hook_test_check(is_array($saved)&&($saved['pending']['child_failure']??null)===$failure,
            'driver_did_not_persist_local_failure_before_terminal');
        $line=monitor_hook_terminal_line($testTerminalPrefix,$operation,gmdate('c'),'child_preflight_failed_before_write',
            ['preflight_error'=>$failure['error'],'child_exit_code'=>$failure['exit_code']]);
        $testReadJournal=monitor_hook_parse_journal($testTerminalPrefix.$line,$operation);$testTerminalAppends++;return $testReadJournal;
    }
    hook_test_check(is_array($saved)&&($saved['pending']['child_failure']??null)===$failure
        &&monitor_hook_preflight_progress_valid($saved['pending']['preflight_progress']??[]),'driver_did_not_persist_progress_before_terminal');
    $line=monitor_hook_terminal_line($testTerminalPrefix,$operation,gmdate('c'),'channel_preflight_blocked_before_write',
        ['preflight_error'=>$failure['error'],'child_exit_code'=>$failure['exit_code'],'channel_preflight'=>$failure['channel_preflight']]);
    $testReadJournal=monitor_hook_parse_journal($testTerminalPrefix.$line,$operation);$testTerminalAppends++;return $testReadJournal;
}
$stopStart=strpos($driver,'function hook_stop_channel_preflight(');
$stopEnd=strpos($driver,'/** Original completed caption batches',$stopStart);
hook_test_check($stopStart!==false&&$stopEnd!==false,'driver_global_preflight_functions_missing');
eval(substr($driver,$stopStart,$stopEnd-$stopStart));
$editStart=strpos($driver,"        try{hook_measure(".'$out,\'post_edit\',');
$editEnd=strpos($driver,"        ".'hook_finish_feed($state,$lock,$installed,$out);$out[\'updated_pairs\']++;',$editStart===false?0:$editStart);
hook_test_check($editStart!==false&&$editEnd!==false,'driver_editor_failure_fixture_missing');
$editCatch=substr($driver,$editStart,$editEnd-$editStart);
if(!defined('HOOK_EDITOR_B64'))define('HOOK_EDITOR_B64','');
function hook_test_channel_driver(array $failure,string $source,array $base,string $operation,int $tv):array {
    global $testChildFailure,$testDriverCalls,$testAppendedStates,$testTerminalAppends;
    $testChildFailure=$failure;$testDriverCalls=['append'=>0,'child'=>0,'feed'=>0,'read'=>0];$testAppendedStates=[];$testTerminalAppends=0;
    $now=time();$startedAt=$now-10;$nightly=true;$lock=null;$installed=str_repeat('e',40);$op=$operation;$raw='{}';
    $state=$base;$state['nightly_cycle']=monitor_hook_nightly_gate([],$startedAt,gmdate('c',$now-20),'900000001')['cycle'];
    $state['pending']['at']=gmdate('c',$now-1);$state['pending']['collector_cycle']=monitor_hook_preflight_cycle_identity($state['nightly_cycle']);
    $state['holds']=[999998=>['retained'=>'unrelated-hold']];$before=$state;
    $out=['ok'=>false,'updated_pairs'=>1,'unchanged_pairs'=>1,'eligible_pairs'=>2,'held_pairs'=>0,'scanned_pairs'=>3,'deferred'=>[]];
    $roster=array_fill(0,10,[]);$visited=[];$caught=null;
    $row=['hotel_id'=>1,'tourvisor_hotel_id'=>$tv,'key'=>'fixture','logical_id'=>'fixture'];
    try{eval('foreach([$row,$row] as $row){'.$source.'$visited[]=$row[\'tourvisor_hotel_id\'];}');}
    catch(Throwable $e){$caught=$e->getMessage();}
    $testChildFailure=null;
    return ['state'=>$state,'before'=>$before,'out'=>$out,'error'=>$caught,'visited'=>$visited,'calls'=>$testDriverCalls,
        'terminal_appends'=>$testTerminalAppends,'appended'=>$testAppendedStates];
}
$channelFailures=[];$channelResults=[];
foreach(array_slice($preflightCases,0,3) as [$platform,$stateCode,$reason,$http,$expectedError]){
    $safe=monitor_price_channel_preflight($platform,['state'=>$stateCode,'reason'=>$reason,
        'receipt'=>['request_count'=>count($http),'write_attempts'=>0,'http_results'=>$http]]);
    $failure=['exit_code'=>2,'error'=>$expectedError,'channel_preflight'=>$safe];$channelFailures[]=$failure;
    hook_test_check(monitor_hook_channel_preflight_failure($failure),'typed_channel_failure_not_admitted_'.$reason);
    $run=hook_test_channel_driver($failure,$editCatch,$terminalGlobal,$terminalOperation,$terminalTv);$channelResults[]=$run;
    hook_test_check($run['error']===$expectedError&&$run['out']['ok']===false&&$run['visited']===[]
        &&$run['calls']===['append'=>2,'child'=>1,'feed'=>0,'read'=>0]&&$run['terminal_appends']===1,'global_failure_retried_or_continued_'.$reason);
    hook_test_check($run['state']['pending']===null&&monitor_hook_preflight_cycle_valid($run['state']['nightly_cycle'])
        &&$run['state']['nightly_cycle']['state']==='preflight_blocked'&&$run['out']['nightly_cycle_completed']===false
        &&$run['state']['completed']===$run['before']['completed']&&$run['state']['holds']===$run['before']['holds'],'global_failure_became_hold_or_completion_'.$reason);
    hook_test_check($run['state']['nightly_cycle']['remaining_unscanned_pairs']===7&&$run['state']['nightly_cycle']['updated_pairs']===1
        &&$run['out']['child_failure']===$failure&&$run['appended'][0]['pending']['preflight_progress']===$run['state']['last_preflight_block']['progress'],'global_failure_lost_progress_'.$reason);
    hook_test_reject(static fn()=>monitor_hook_apply_abandonment($run['before'],$testReadJournal),'global_failure_accepted_as_hotel_hold');
}

// Neither a typed label nor the sanitizer can admit malformed or uncertain evidence.
$goodFailure=$channelFailures[0];
foreach(['exit','count','negative_count','string_count','write','truncated','operation','http','transport','extra','unknown_reason','error_mismatch'] as $bad){
    $failure=$goodFailure;
    if($bad==='exit')$failure['exit_code']=-1;
    if($bad==='count')$failure['channel_preflight']['request_count']++;
    if($bad==='negative_count')$failure['channel_preflight']['request_count']=-1;
    if($bad==='string_count')$failure['channel_preflight']['request_count']='2';
    if($bad==='write')$failure['channel_preflight']['write_attempts']=1;
    if($bad==='truncated')$failure['channel_preflight']['http_results_truncated']=true;
    if($bad==='operation')$failure['channel_preflight']['http_results'][0]['operation']='unknown';
    if($bad==='http')$failure['channel_preflight']['http_results'][0]['http']=null;
    if($bad==='transport')$failure['channel_preflight']['http_results'][0]['transport_error']=null;
    if($bad==='extra')$failure['channel_preflight']['http_results'][0]['response']='LEAK_SECRET';
    if($bad==='unknown_reason')$failure['channel_preflight']['reason']='unrecognized_verification_result';
    if($bad==='error_mismatch')$failure['error']='provider_channel_permission_denied';
    hook_test_check(!monitor_hook_channel_preflight_failure($failure),'global_failure_admitted_malformed_'.$bad);
    hook_test_reject(static fn()=>monitor_hook_terminal_line($terminalPrefix,$terminalOperation,gmdate('c'),'channel_preflight_blocked_before_write',
        ['preflight_error'=>$failure['error'],'child_exit_code'=>$failure['exit_code'],'channel_preflight'=>$failure['channel_preflight']]),'global_terminal_admitted_malformed_'.$bad);
    $run=hook_test_channel_driver($failure,$editCatch,$terminalGlobal,$terminalOperation,$terminalTv);
    hook_test_check($run['error']==='hook_child_failed_read_original_journal'&&$run['state']['pending']!==null
        &&$run['state']['nightly_cycle']['state']==='started'&&$run['terminal_appends']===0&&$run['calls']['child']===1
        &&$run['calls']['append']===1&&$run['visited']===[],'malformed_failure_not_held_'.$bad);
}
foreach(['posts_started','posts_confirmed','config_started','already_complete'] as $bad){
    $changed=$terminalOriginal;
    if($bad==='posts_started')$changed['posts']=['max:fixture'=>['state'=>'edit_started']];
    if($bad==='posts_confirmed')$changed['posts']=['max:fixture'=>['state'=>'confirmed']];
    if($bad==='config_started')$changed['configs']=['fixture'=>['state'=>'started']];
    if($bad==='already_complete')$changed['complete']=true;
    $testTerminalPrefix=hook_test_json_line($changed).$terminalPrefix;
    $run=hook_test_channel_driver($goodFailure,$editCatch,$terminalGlobal,$terminalOperation,$terminalTv);
    hook_test_check($run['state']['pending']!==null&&$run['state']['nightly_cycle']['state']==='started'
        &&$run['terminal_appends']===0&&$run['calls']['append']===1&&$run['visited']===[],'global_failure_erased_prior_'.$bad);
}
$testTerminalPrefix=$terminalPrefix;
$run=hook_test_channel_driver($goodFailure,$editCatch,$terminalGlobal,$terminalOperation,$terminalTv);
$crashState=$run['appended'][0];$crashJournal=$testReadJournal;$crashBefore=$crashState;
$testDriverCalls=['append'=>0,'child'=>0,'feed'=>0,'read'=>0];$testAppendedStates=[];$recoveryOut=['ok'=>false];$stopped=false;
try{hook_reconcile_channel_preflight($crashState,null,$recoveryOut);}catch(Throwable $e){$stopped=$e->getMessage()===$goodFailure['error'];}
hook_test_check($stopped&&$crashState['pending']===null&&$recoveryOut['reconciled_prior_preflight']===true
    &&$testDriverCalls===['append'=>1,'child'=>0,'feed'=>0,'read'=>0]&&$crashState['completed']===$crashBefore['completed']
    &&$crashState['holds']===$crashBefore['holds'],'crash_recovery_replayed_provider_or_lost_state');
hook_test_check($crashState['nightly_cycle']['duration_seconds']===$crashBefore['pending']['preflight_progress']['duration_seconds']
    &&$crashState['nightly_cycle']['remaining_unscanned_pairs']===7,'crash_recovery_invented_progress');
$callsBefore=$testDriverCalls;hook_reconcile_channel_preflight($crashState,null,$recoveryOut);
hook_test_check($testDriverCalls===$callsBefore,'crash_recovery_repeated_global_close');
foreach(['failure','progress','cycle','phase'] as $bad){
    $changed=$crashBefore;
    if($bad==='failure')$changed['pending']['child_failure']=$channelFailures[1];
    if($bad==='progress')unset($changed['pending']['preflight_progress']);
    if($bad==='cycle')$changed['nightly_cycle']['collector_run_id']='900000002';
    if($bad==='phase')$changed['pending']['phase']='feed_started';
    hook_test_reject(static fn()=>monitor_hook_close_channel_preflight($changed,$crashJournal,time()),'global_close_admitted_changed_'.$bad);
}
$standalone=$crashBefore;$standalone['pending']['collector_cycle']=null;$oldCycle=$standalone['nightly_cycle'];
$standalone=monitor_hook_close_channel_preflight($standalone,$crashJournal,time());
hook_test_check($standalone['pending']===null&&$standalone['nightly_cycle']===$oldCycle
    &&$standalone['last_preflight_block']['collector_cycle']===null,'standalone_closed_foreign_collector');
$reconcileAt=strpos($driver,'if(!$inspect)hook_reconcile_channel_preflight($state,$lock,$out);');
$storedGateAt=strpos($driver,'$nightGate=monitor_hook_nightly_gate($state,');
hook_test_check($reconcileAt!==false&&$storedGateAt!==false&&$reconcileAt<$storedGateAt,'crash_recovery_after_nightly_admission');

// The actual record gate skips only this validated operation; a new record keeps the normal path.
$recordStart=strpos($driver,"            if(is_array(".'$prior)&&array_key_exists(\'terminal\',$prior)){');
$recordEnd=strpos($driver,"            ".'$context=$context??',$recordStart);
hook_test_check($recordStart!==false&&$recordEnd!==false,'driver_record_terminal_gate_missing');
$recordGate=substr($driver,$recordStart,$recordEnd-$recordStart);
foreach([$terminalParsed,$crashJournal] as $parsed){
    $out=['deferred'=>[]];$tv=$terminalTv;$visited=[];$newOperation='commercial-monitor-price-h'.$tv.'-'.str_repeat('f',64);
    $records=[[$terminalOperation,$parsed],[$newOperation,null]];
    eval('foreach($records as [$op,$prior]){'.$recordGate.'$visited[]=$op;}');
    hook_test_check($visited===[$newOperation]&&$out['deferred']===[['tourvisor_hotel_id'=>$tv,'reason'=>'abandoned_before_write_record']],'record_terminal_held_new_observation');
}

// A wider reader window keeps the same source identity and cannot replay a completed pair.
$sameRecord36=$terminalSnapshot;$sameRecord36['observationWindowHours']=36;
$samePrice36=monitor_price_observation($sameRecord36,$terminalTv,strtotime($sameRecord36['generatedAt']));
hook_test_check($samePrice36===$terminalPrice&&monitor_price_operation($samePrice36)===$terminalOperation,'window_change_created_new_price_operation');
$completedStart=strpos($driver,'            if($prior!==null){');
$completedEnd=strpos($driver,'            $r=array_intersect_key(',$completedStart===false?0:$completedStart);
hook_test_check($completedStart!==false&&$completedEnd!==false,'driver_completed_record_gate_missing');
$completedGate=substr($driver,$completedStart,$completedEnd-$completedStart);
$completedPrior=$terminalOriginal;$completedPrior['complete']=true;$sameContext=[];
foreach(['max','telegram'] as $platform){
    $completedPrior['posts'][$platform]=['state'=>'confirmed','receipt'=>['platform'=>$platform,'monitor_price'=>$terminalPrice]];
    $sameContext[$platform]=['snapshot'=>['metadata'=>['monitor_price_record_id'=>$terminalPrice['record_id']]]];
}
$repeatCompleted=static function(array $prior,array $context)use($sameRecord36,$terminalTv,$completedGate):array{
    $tv=$terminalTv;$price=monitor_price_observation($sameRecord36,$tv,strtotime($sameRecord36['generatedAt']));
    $out=['unchanged_pairs'=>0,'unchanged_ids'=>[]];
    // These recording stubs throw if the actual continue ever reaches a writer.
    eval('foreach([1] as $ignored){'.$completedGate.'hook_child("fixture_only",[]);hook_prepare_feed($prior,"fixture_only");}');
    return $out;
};
$beforeCalls=$testDriverCalls;$beforeCompleted=$completedPrior;
hook_test_check($repeatCompleted($completedPrior,$sameContext)===['unchanged_pairs'=>1,'unchanged_ids'=>[$terminalTv]]
    &&$testDriverCalls===$beforeCalls&&$completedPrior===$beforeCompleted,'wider_window_replayed_completed_pair');
$changedContext=$sameContext;$changedContext['telegram']['snapshot']['metadata']['monitor_price_record_id']=str_repeat('f',64);
$changedError=null;try{$repeatCompleted($completedPrior,$changedContext);}catch(Throwable $e){$changedError=$e->getMessage();}
hook_test_check($changedError==='hook_completed_record_no_longer_current'&&$testDriverCalls===$beforeCalls,'wider_window_bypassed_native_record_guard');
$op=monitor_price_operation($samePrice36);$prior=$terminalParsed;$out=['deferred'=>[]];$visited=[];$tv=$terminalTv;
eval('foreach([1] as $ignored){'.$recordGate.'$visited[]=$op;}');
hook_test_check($visited===[]&&$out['deferred']===[['tourvisor_hotel_id'=>$tv,'reason'=>'abandoned_before_write_record']]
    &&monitor_hook_parse_journal($terminalPrefix.$terminalLine,$terminalOperation)===$terminalParsed
    &&$terminalParsed['monitor_snapshot']['observationWindowHours']===24,'wider_window_reinterpreted_historical_terminal');
// Parser failures must reach the outer stop, even if the original code has no unresolved/incomplete suffix.
$parseStart=strpos($driver,'    try{return monitor_hook_parse_journal($raw,$op);}');
$parseEnd=strpos($driver,"\n}",$parseStart);
hook_test_check($parseStart!==false&&$parseEnd!==false,'driver_parser_stop_wrapper_missing');
$parseActual=eval('return function(string $raw,string $op):array {'.substr($driver,$parseStart,$parseEnd-$parseStart).'};');
$badLine=json_decode($terminalLine,true,64,JSON_THROW_ON_ERROR);$badLine['terminal']['prior_sha256']=str_repeat('f',64);
$parserError=null;try{$parseActual($terminalPrefix.hook_test_json_line($badLine),$terminalOperation);}catch(Throwable $e){$parserError=$e->getMessage();}
hook_test_check($parserError==='hook_journal_invalid_stop','malformed_terminal_not_normalized_to_stop');
$candidateCatchStart=strpos($driver,"}catch(Throwable ".'$e){'."\n            if(str_contains");
$candidateCatchEnd=strpos($driver,"        ".'$out[\'eligible_pairs\']++;',$candidateCatchStart);
hook_test_check($candidateCatchStart!==false&&$candidateCatchEnd!==false,'driver_candidate_catch_missing');
$candidateCatch=substr($driver,$candidateCatchStart,$candidateCatchEnd-$candidateCatchStart);
foreach(['hook_journal_invalid_stop','hook_record_terminal_invalid_stop','hook_no_write_prefix_has_intent_stop'] as $error){
    $out=['deferred'=>[]];$visited=[];$stopped=false;
    try{eval('foreach([1] as $ignored){try{throw new RuntimeException($error);'.$candidateCatch.'$visited[]=1;}');}
    catch(Throwable $e){$stopped=$e->getMessage()===$error;}
    hook_test_check($stopped&&$out['deferred']===[]&&$visited===[],'candidate_swallowed_terminal_failure_'.$error);
}

// Exercise actual packet construction and the first/subsequent legacy caption gates.
$expanded=json_decode(gzdecode(file_get_contents(__DIR__.'/monitor-price-expanded-roster-20261111.json.gz')),true,64,JSON_THROW_ON_ERROR);
// Original maintenance43537828604 rejected seven real templates at the existing CTA contract.
// The same saved-source literal occurs in three more rows: two had no fresh offer, one was not scanned.
$ctaFixIds=[948,1335,1338,1369,1416,1551,2144,12107,15913,132016];
$badCta='✈️ Рассчитаем полную стоимость тура с перелётом на ваши даты.';
$acceptedCta='✈️ Подберём подходящий номер и рассчитаем тур с перелётом на ваши даты.';
$ctaRows=array_values(array_filter($expanded['rows'],static fn($row)=>in_array($row['tourvisor_hotel_id'],$ctaFixIds,true)));
hook_test_check(count($ctaRows)===10,'cta_repair_fixture_scope_changed');
foreach($ctaRows as $row){
    $tv=$row['tourvisor_hotel_id'];$snapshot=$terminalSnapshot;$snapshot['scope']['tourvisorHotelIds']=[$tv];$snapshot['item']['tourvisorHotelId']=$tv;
    foreach($snapshot['item']['offers'] as $i=>$offer){$snapshot['item']['offers'][$i]['tourvisorHotelId']=$tv;$snapshot['item']['offers'][$i]['recordId']=hash('sha256','cta-repair-test-'.$tv.'-'.$i);}
    $price=monitor_price_observation($snapshot,$tv,strtotime($snapshot['generatedAt']));
    foreach(['max','telegram'] as $platform){
        $template=$row['templates'][$platform];
        hook_test_check(str_ends_with($template,"\n\n".$acceptedCta),'cta_repair_missing_'.$tv.'_'.$platform);
        $before=substr($template,0,-strlen($acceptedCta)).$badCta;$beforeError=null;
        try{monitor_hook_draft($before,$price);}catch(Throwable $e){$beforeError=$e->getMessage();}
        hook_test_check($beforeError==='monitor_text_conditions_mismatch','cta_old_template_did_not_reproduce_'.$tv.'_'.$platform);
        $draft=monitor_hook_draft($template,$price);
        hook_test_check(str_ends_with($draft,"\n\n".$acceptedCta),'cta_current_real_template_rejected_'.$tv.'_'.$platform);
    }
}
$retainedBadCta=0;foreach($expanded['rows'] as $row)foreach($row['templates'] as $template)$retainedBadCta+=substr_count($template,$badCta);
hook_test_check($retainedBadCta===0,'cta_exact_defect_retained_in_saved_roster');
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
// Two of these23 captions were subsequently edited in the confirmed73-pair operation.
// The saved public caption is the baseline; a future price template has its own CTA.
$editorFixturePacked=base64_decode(trim(file_get_contents(__DIR__.'/commercial-caption-review73-20261113-price-runtime-fixture.json.gz.b64')),true);
hook_test_check(is_string($editorFixturePacked),'editorial_fixture_encoding_changed');
$editorFixtureRaw=gzdecode($editorFixturePacked);
hook_test_check(is_string($editorFixtureRaw)&&hash('sha256',$editorFixtureRaw)==='ef8226914d11e12078395853b2f244c0d46afa8c32ca3502d7ec5d2ab2ba9978','editorial_fixture_bytes_changed');
$editorFixture=json_decode($editorFixtureRaw,true,128,JSON_THROW_ON_ERROR);
hook_test_check($editorFixture['fixture_only']===true&&$editorFixture['original_caption_run_id']===43455664622
    &&$editorFixture['completion_caption_run_id']===43463340468&&count($editorFixture['cases'])===73,'editorial_fixture_provenance_changed');
$editorialRemaining=[];
foreach($editorFixture['cases'] as $case)if(in_array($case['tourvisor_hotel_id'],[386,8053],true)){
    hook_test_check($case['priced']===false&&!isset($editorialRemaining[$case['tourvisor_hotel_id']]),'remaining_editorial_baseline_scope_changed');
    $editorialRemaining[$case['tourvisor_hotel_id']]=$case;
}
hook_test_check(count($editorialRemaining)===2,'remaining_editorial_baselines_missing');
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
        $context[$platform]=['publication_id'=>$c['publication_id'],'version_id'=>$c['baseline_version_id'],
            'external_message_id'=>$c['message_id'],'snapshot'=>['text'=>$caption,'post_url'=>$c['post_url'],'metadata'=>[]]];
        if(isset($editorialRemaining[$tv])){
            $accepted=$editorialRemaining[$tv]['publications'][$platform];
            hook_test_check($accepted['publication_id']===$c['publication_id']&&$accepted['external_message_id']===$c['message_id']
                &&$accepted['snapshot']['post_url']===$c['post_url'],'remaining_editorial_publication_identity_changed');
            $context[$platform]=$accepted;$caption=$accepted['snapshot']['text'];
        }
        hook_test_check(hash('sha256',$caption)===$row['expected_text_sha256'][$platform],'remaining_template_changed_accepted_caption');
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

// Three newly published pairs: preserve the entire old604 prefix and accepted warnings.
$published607Raw=gzdecode(file_get_contents(__DIR__.'/monitor-price-expanded-roster-20261111.json.gz'));
hook_test_check(hash('sha256',substr($published607Raw,0,1855119))==='2c5d5bb35e06b6917f674f5c3550d738495a7d4526f62a4e16375d60c882c53e','published_three_old604_bytes_changed');
$published607Requirements=[
    59114=>['text_sha256'=>'dc25b1490c9b7582f4474a1592f6e3edf0ae323cc04b3662b36a9f482c9aa812','warning'=>'Рядом с отелем стройка — возможен шум.'],
    475=>['text_sha256'=>'fed32eeeb4f9ccf5494191e0ab1ff7ffee41619248bde52a6ffdb9cf1ff8a2d8','warning'=>'В отеле ремонтируют номера — возможен шум.'],
    473=>['text_sha256'=>'f3544153d6c39f174070029ea8af1ff2bc2f9a848176df9334d18aaac7079e54','warning'=>'В отеле ремонтируют номера и часть общественных зон — возможны шум и временные ограничения.'],
];
$published607Manifest=json_decode(file_get_contents(__DIR__.'/commercial-batch-20261115-1-manifest.json'),true,64,JSON_THROW_ON_ERROR);
hook_test_check(array_column($published607Manifest['rows'],'hotel_id')===[59114,475,473],'published_three_manifest_scope_changed');
$published607Rows=array_values(array_filter($expanded['rows'],static fn($row)=>array_key_exists($row['tourvisor_hotel_id'],$published607Requirements)));
hook_test_check(array_column($published607Rows,'tourvisor_hotel_id')===[59114,475,473]&&count($expanded['rows'])>=607,'published_three_roster_scope_changed');
$published607Slot="\n\n💰 [Минимальная актуальная цена тура — подтвердить перед выпуском]\n[Город вылета · даты · ночи · состав туристов · номер · питание · время проверки]\n\nУзнать стоимость тура";
$published607Closing="\n\n👇 Узнать стоимость тура";
foreach($published607Rows as $row){
    $tv=$row['tourvisor_hotel_id'];$required=$published607Requirements[$tv];
    $matches=array_values(array_filter($published607Manifest['rows'],static fn($m)=>$m['hotel_id']===$tv));
    hook_test_check(count($matches)===1&&hash('sha256',$matches[0]['text'])===$required['text_sha256'],'published_three_accepted_text_changed');
    $accepted=$matches[0]['text'];
    hook_test_check(str_ends_with($accepted,$published607Closing)&&substr_count($accepted,$required['warning'])===1,'published_three_accepted_warning_missing');
    $expectedTemplate=substr($accepted,0,-strlen($published607Closing)).$published607Slot;
    $snapshot=$terminalSnapshot;$snapshot['scope']['tourvisorHotelIds']=[$tv];$snapshot['item']['tourvisorHotelId']=$tv;
    foreach($snapshot['item']['offers'] as $i=>$offer){$snapshot['item']['offers'][$i]['tourvisorHotelId']=$tv;$snapshot['item']['offers'][$i]['recordId']=hash('sha256','published-three-20261115-'.$tv.'-'.$i);}
    $price=monitor_price_observation($snapshot,$tv,strtotime($snapshot['generatedAt']));
    foreach(['max','telegram'] as $platform){
        hook_test_check($row['templates'][$platform]===$expectedTemplate&&$row['expected_text_sha256'][$platform]===$required['text_sha256'],'published_three_template_changed_accepted_facts');
        $draft=monitor_hook_draft($row['templates'][$platform],$price);
        hook_test_check(substr_count($draft,$required['warning'])===1&&str_ends_with($draft,"\n\nУзнать стоимость тура"),'published_three_price_draft_lost_warning_or_cta');
    }
}

// Palace206: append one pair while preserving every accepted607 byte and both disclosures.
$published608Raw=gzdecode(file_get_contents(__DIR__.'/monitor-price-expanded-roster-20261111.json.gz'));
hook_test_check(hash('sha256',substr($published608Raw,0,1864379))==='8153a918aeafbb689d8812d1600e51aac34f05c835bdf4f05f6be56a5cdbe8f3','published_palace_old607_bytes_changed');
$published608TextSha='797080d42216cc6934f46d2c675bea7a172e18498ae4ab3838c90682b67dc9d2';
$published608Warnings=[
    'Доступ к соседнему аквапарку не подтверждён.',
    'Для части номеров на первом этаже заявлен ремонт без даты окончания (описание от сентября 2025 года).',
];
$published608Manifest=json_decode(file_get_contents(__DIR__.'/commercial-batch-20261115-2-manifest.json'),true,64,JSON_THROW_ON_ERROR);
hook_test_check($published608Manifest['operation']==='commercial-hotel-batch-20261115-2-v1'&&array_column($published608Manifest['rows'],'hotel_id')===[206],'published_palace_manifest_scope_changed');
$published608Rows=array_values(array_filter($expanded['rows'],static fn($row)=>$row['tourvisor_hotel_id']===206));
hook_test_check(count($published608Rows)===1&&count($expanded['rows'])>=608,'published_palace_roster_scope_changed');
$published608Accepted=$published608Manifest['rows'][0]['text'];
$published608Closing="\n\n👇 Узнать стоимость тура";
$published608Slot="\n\n💰 [Минимальная актуальная цена тура — подтвердить перед выпуском]\n[Город вылета · даты · ночи · состав туристов · номер · питание · время проверки]\n\nУзнать стоимость тура";
hook_test_check(hash('sha256',$published608Accepted)===$published608TextSha&&str_ends_with($published608Accepted,$published608Closing),'published_palace_accepted_text_changed');
foreach($published608Warnings as $warning){hook_test_check(substr_count($published608Accepted,$warning)===1,'published_palace_accepted_disclosure_missing');}
$published608Template=substr($published608Accepted,0,-strlen($published608Closing)).$published608Slot;
$published608Row=$published608Rows[0];
$published608Snapshot=$terminalSnapshot;$published608Snapshot['scope']['tourvisorHotelIds']=[206];$published608Snapshot['item']['tourvisorHotelId']=206;
foreach($published608Snapshot['item']['offers'] as $i=>$offer){$published608Snapshot['item']['offers'][$i]['tourvisorHotelId']=206;$published608Snapshot['item']['offers'][$i]['recordId']=hash('sha256','published-palace-20261115-206-'.$i);}
$published608Price=monitor_price_observation($published608Snapshot,206,strtotime($published608Snapshot['generatedAt']));
foreach(['max','telegram'] as $platform){
    hook_test_check($published608Row['templates'][$platform]===$published608Template&&$published608Row['expected_text_sha256'][$platform]===$published608TextSha,'published_palace_template_changed_accepted_facts');
    $published608Draft=monitor_hook_draft($published608Row['templates'][$platform],$published608Price);
    foreach($published608Warnings as $warning){hook_test_check(substr_count($published608Draft,$warning)===1,'published_palace_price_draft_lost_disclosure');}
    hook_test_check(str_ends_with($published608Draft,"\n\nУзнать стоимость тура"),'published_palace_price_draft_lost_cta');
}

// A confirmed MAX edit followed by a Telegram failure must stop the whole pass.
// Execute the actual parent catch and the following feed call; use the real
// no-write terminal validator, so an allowlisted error cannot erase that receipt.
$partialMaxReceipt=['platform'=>'max','publication_id'=>7,'version_id'=>9,'message_id'=>'mid.pacing-fixture',
    'state'=>'sent','body_photo_button_verified'=>true,'price_link_verified'=>true];
$partialMax=$terminalOriginal;$partialMax['posts']=['max:pacing-fixture'=>['state'=>'confirmed','receipt'=>$partialMaxReceipt]];
$partialPrefix=$terminalPrefix.hook_test_json_line($partialMax);
$feedCallEnd=strpos($driver,"\n",$editEnd);
hook_test_check($feedCallEnd!==false,'driver_post_edit_feed_boundary_missing');
$editThroughFeed=substr($driver,$editStart,$feedCallEnd-$editStart);
foreach(['telegram_edit_not_confirmed_no_retry','telegram_price_link_not_verified_no_retry',
    'target_changed_before_edit','monitor_price_expired'] as $partialError){
    $testTerminalPrefix=$partialPrefix;$testReadJournal=$partialMax;
    $partialFailure=['exit_code'=>2,'error'=>$partialError];
    $run=hook_test_channel_driver($partialFailure,$editThroughFeed,$terminalGlobal,$terminalOperation,$terminalTv);
    $expectedError=monitor_hook_local_preflight_failure($partialFailure)
        ?'hook_no_write_prefix_has_intent_stop':'hook_child_failed_read_original_journal';
    hook_test_check($run['error']===$expectedError&&$run['calls']['child']===1&&$run['visited']===[]
        &&$run['calls']['feed']===0&&$run['calls']['read']===0&&$run['out']['updated_pairs']===1,
        'partial_max_started_next_pair_or_feed_'.$partialError);
    hook_test_check($run['state']['pending']===array_replace($run['before']['pending'],['child_failure'=>$partialFailure])
        &&$run['state']['completed']===$run['before']['completed']&&$run['state']['holds']===$run['before']['holds']
        &&$run['state']['nightly_cycle']===$run['before']['nightly_cycle']&&$run['calls']['append']===1
        &&$run['terminal_appends']===0,'partial_max_cleared_pending_or_completed_'.$partialError);
    hook_test_check($testTerminalPrefix===$partialPrefix&&$testReadJournal===$partialMax
        &&$testReadJournal['posts']['max:pacing-fixture']['receipt']===$partialMaxReceipt,
        'partial_max_changed_confirmed_receipt_'.$partialError);
}

// Run the editor's actual pacing control flow against a virtual clock. Only
// usleep is replaced; zero-duration provider work exercises the tightest channel
// spacing across three successive pairs without provider calls or real waits.
function hook_test_editor_sleep(int $microseconds):void {
    global $testPacingClockUs,$testPacingWaits,$testPacingContext;
    hook_test_check($microseconds===3200000,'editor_changed_channel_cooldown');
    $testPacingWaits[]=$testPacingContext+['at_us'=>$testPacingClockUs,'duration_us'=>$microseconds];
    $testPacingClockUs+=$microseconds;
}
function hook_test_editor_pacing(string $source,bool $monitorMode,int $pairCount):array {
    global $testPacingClockUs,$testPacingWaits,$testPacingContext;
    $testPacingClockUs=0;$testPacingWaits=[];$testPacingContext=[];$writes=[];
    for($pair=0;$pair<$pairCount;$pair++){
        foreach(['max','telegram'] as $platform){
            $testPacingContext=['pair'=>$pair,'platform'=>$platform];
            $writes[]=$testPacingContext+['at_us'=>$testPacingClockUs];
            eval($source);
        }
    }
    return ['writes'=>$writes,'waits'=>$testPacingWaits,'elapsed_us'=>$testPacingClockUs];
}
$progressStart=strpos($editorSource,"echo json_encode(['progress'=>'existing_post_updated'");
$flushAt=$progressStart===false?false:strpos($editorSource,'flush();',$progressStart);
$pacingEnd=$flushAt===false?false:strpos($editorSource,"\n    }\n    ".'$out[\'ok\']=true;',$flushAt);
hook_test_check($progressStart!==false&&$flushAt!==false&&$pacingEnd!==false,'editor_pacing_boundary_missing');
$pacingStart=$flushAt+strlen('flush();');
$pacingSource=substr($editorSource,$pacingStart,$pacingEnd-$pacingStart);
$pacingSource=str_replace('usleep(','hook_test_editor_sleep(',$pacingSource,$sleepDependencies);
hook_test_check($sleepDependencies===1,'editor_pacing_sleep_dependency_missing');
$pacingPairCount=3;$monitorPacing=hook_test_editor_pacing($pacingSource,true,$pacingPairCount);
hook_test_check(count($monitorPacing['waits'])===$pacingPairCount&&$monitorPacing['elapsed_us']===$pacingPairCount*3200000,
    'monitor_pair_repeats_cooldown_waits_'.count($monitorPacing['waits']).'_elapsed_us_'.$monitorPacing['elapsed_us']);
hook_test_check(array_column($monitorPacing['waits'],'platform')===array_fill(0,$pacingPairCount,'telegram'),
    'monitor_pair_did_not_retain_final_channel_cooldown');
$ordinaryPacing=hook_test_editor_pacing($pacingSource,false,$pacingPairCount);
hook_test_check(count($ordinaryPacing['waits'])===2*$pacingPairCount&&$ordinaryPacing['elapsed_us']===2*$pacingPairCount*3200000
    &&array_column($ordinaryPacing['waits'],'platform')===['max','telegram','max','telegram','max','telegram'],
    'ordinary_editor_cooldown_changed');
foreach(['monitor'=>$monitorPacing,'ordinary'=>$ordinaryPacing] as $mode=>$pacing){
    $previous=[];
    foreach($pacing['writes'] as $write){
        $platform=$write['platform'];
        if(isset($previous[$platform]))hook_test_check($write['at_us']-$previous[$platform]>=3200000,
            'editor_channel_interval_shortened_'.$mode.'_'.$platform);
        $previous[$platform]=$write['at_us'];
    }
}

// PR1612 compatibility is monitor-only. Execute the editor's actual pin selection
// and the driver's exact runtime gate with mocked Git responses, never runtime I/O.
$pinStart=strpos($editorSource,'$accepted=[');
$pinEnd=strpos($editorSource,'foreach($accepted as',$pinStart);
hook_test_check($pinStart!==false&&$pinEnd!==false,'monitor_pin_selection_missing');
$pinSelection=substr($editorSource,$pinStart,$pinEnd-$pinStart);
$monitorMode=false;eval($pinSelection);$legacyPins=$accepted;
$monitorMode=true;eval($pinSelection);$monitorPins=$accepted;
hook_test_check($legacyPins['services/ChannelPostAutomationService.php']==='f5975ab95de5f45e087eb7a0f768f566c5bca648'
    &&$legacyPins['services/ChannelPostRegistryService.php']==='a407757b9ac54a9e286900d34385d519d22b8c84','legacy_manual_pin_scope_changed');
hook_test_check(count($monitorPins)===23
    &&$monitorPins['integrations/ChannelPostApiTransport.php']==='cf008c9f53b046b51b88ec1372a0c7b4fd5f7ba3'
    &&$monitorPins['services/ChannelPostAutomationService.php']==='9914ff7af27ab714204f7478a75a5dd971de152d'
    &&$monitorPins['services/ChannelPostButtonTransport.php']==='2dffadf8679caf6e9ed364a45ecfc44f7eb67388'
    &&$monitorPins['services/ChannelPostRegistryService.php']==='da1dddb370ce5e9987f97a80f72123b51fd927e3'
    &&$monitorPins['services/ChannelPostReconciliationService.php']==='1fa260e9720a8421227ecbe740bfbe33a42e398d','reviewed_pr1657_monitor_pins_missing');
$bundlePath=tempnam(sys_get_temp_dir(),'monitor-pin-test-');
try {
    exec('python3 '.escapeshellarg(__DIR__.'/build-monitor-price-hook.py').' '.escapeshellarg($bundlePath),$bundleOutput,$bundleExit);
    hook_test_check($bundleExit===0,'monitor_pin_bundle_build_failed');
    $bundleSource=file_get_contents($bundlePath);
    hook_test_check(preg_match('/const HOOK_RUNTIME_PINS_B64=("[^"]+");/',$bundleSource,$pinMatch)===1,'monitor_bundle_pins_missing');
    $bundlePins=json_decode(base64_decode(json_decode($pinMatch[1],true)),true);
    hook_test_check($bundlePins===$monitorPins,'bundle_editor_pins_differ');
} finally { unlink($bundlePath); }
function hook_test_runtime_git(array $args,string $error):string {
    global $runtimeFixturePins;
    if($args===['rev-parse','HEAD'])return str_repeat('a',40);
    if($args[0]==='status'||$args[0]==='merge-base')return '';
    if($args[0]==='hash-object')return implode("\n",array_values($runtimeFixturePins));
    throw new RuntimeException('unexpected_git_in_pin_test');
}
$runtimeStart=strpos($driver,'function hook_runtime():string {');
$runtimeEnd=strpos($driver,'/** Aggregate elapsed stage time',$runtimeStart);
hook_test_check($runtimeStart!==false&&$runtimeEnd!==false,'runtime_pin_gate_missing');
$runtimeSource=substr($driver,$runtimeStart,$runtimeEnd-$runtimeStart);
$runtimeSource=str_replace(['function hook_runtime','hook_git(','hook_need(','HOOK_ACCEPTED_BASE','HOOK_RUNTIME_PINS_B64'],
    ['function hook_test_runtime','hook_test_runtime_git(','monitor_price_require(',var_export(str_repeat('b',40),true),var_export(base64_encode(json_encode($bundlePins)),true)],$runtimeSource);
eval($runtimeSource);
$runtimeFixturePins=$monitorPins;
hook_test_check(hook_test_runtime()===str_repeat('a',40),'reviewed_surface_rejected');
foreach(array_keys($monitorPins) as $path){
    $runtimeFixturePins=$monitorPins;$runtimeFixturePins[$path]=str_repeat('0',40);
    hook_test_reject(static fn()=>hook_test_runtime(),'changed_surface_accepted_'.$path);
}
foreach(['services/ChannelPostAutomationService.php'=>'f5975ab95de5f45e087eb7a0f768f566c5bca648',
    'services/ChannelPostRegistryService.php'=>'94fb1988ac8caf90dc5103aa23c58e7aad6ea691',
    'services/ChannelPostReconciliationService.php'=>'43133f576845c14446102dedfffb7f0de2d4ba1c'] as $path=>$oldPin){
    $runtimeFixturePins=$monitorPins;$runtimeFixturePins[$path]=$oldPin;
    hook_test_reject(static fn()=>hook_test_runtime(),'superseded_surface_accepted_'.$path);
}

// Preserve the older pre-PR1612 Registry rejection as a separate historical case.
$runtimeFixturePins=$monitorPins;$runtimeFixturePins['services/ChannelPostRegistryService.php']='7304af19cc19666f00ca4ead1b04c2bdce719646';
hook_test_reject(static fn()=>hook_test_runtime(),'pre_pr1612_surface_accepted');

echo 'MONITOR_PRICE_HOOK_OK cases='.$cases." no_provider_calls=1 no_writes=1\n";



require __DIR__.'/test-monitor-price-81173-cycle.php';
