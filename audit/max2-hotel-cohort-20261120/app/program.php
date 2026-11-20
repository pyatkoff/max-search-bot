<?php
declare(strict_types=1);
const M2A_REPORT_PUBLIC_KEY_B64='__M2A_REPORT_PUBLIC_KEY_B64__';
/** New independent private read. No execution of the completed prior audit scope. */
const M2A_BASELINE_SHA256='fb6d34ec90a09568e1e2e37d3186fb37e8093599fa19f28169ede205330006fd';
const M2A_SELECTION_LIBRARY_SHA256='7b4e92e66146550210c18a85b57e2d24dc082f526c74fc3b0711336779469493';
// BEGIN ACCEPTED PURE SELECTION LIBRARY
const HEF_RUNTIME='b4b65daaa472c15426a244b87ede3e6c0394626b';
const HEF_FROM='2026-11-11 21:00:00';
const HEF_UNTIL='2026-11-18 21:00:00';
const HEF_FOLLOWUP_UNTIL='2026-11-19 21:00:00';
const HEF_SOURCE_PINS=[
 'services/ConversationDb.php'=>'8a3c8ef5625aaa24294ba5b9799613920a51a0355c431d0d629662b887652fe1',
 'services/InternalOwnerDialogueScope.php'=>'cf44c20f9f868d82c35c9e4491e060af4c8232105a0b59f89a377f78cecc57d4',
 'services/ModeledConversionValueRegistry.php'=>'6fd7a4588a1c9bf9ee2c3dd3657b624b7a89132f199a4594f1eb1a2d6df9f9ee',
 'services/ModeledConversionSemanticPrediction.php'=>'a56051d3d4baa941db78d75afb613fe30e6620cea3074cf96bea2bd6219347f0',
 'services/ModeledConversionDealValue.php'=>'1e57fdf4a06c20e5136c968418a151d46aba8516e7f249ebd7c2f705325933da',
 'services/ModeledConversionDeliveryRegistry.php'=>'caf7a54cca21d65b09f9cd2ac11cc7cb994be02fa5854daa753c5f35ab80a5a0',
 'services/ModeledConversionForecastPaymentReader.php'=>'c080d47d9f8c33830afbf65ef878d466a7f747d7e3b6b610ad8c463856bd60ac',
];
const HEF_ORIGINS=['unknown','customer_budget','delivered_offer','native_hotel_inquiry','channel_post_range','unavailable'];
const HEF_STATES=['pending','ready','uploading','accepted','processed','unknown','failed','expired','unmatched'];
const HEF_POLICIES=['early_deal_commission_7pct_v1','early_deal_commission_7pct_v2','early_deal_commission_7pct_v3'];
const HEF_BANDS=['first_hour','first_three_hours','first_day','later_update','unknown'];
const HEF_AUDIT_ACTIONS=['missing','unchanged','rebased','reduced','voided','unverified','review_required'];
function hef_need(bool $ok,string $code):void {if(!$ok)throw new RuntimeException($code);}
function hef_limits():array {return [
 'max_entry_rows'=>10000,'max_profile_rows'=>10000,'max_profile_packet_bytes'=>32768,
 'max_current_access_scopes'=>10000,'max_current_access_profile_bytes'=>131072,'max_current_access_scalar_bytes'=>32,
 'max_journal_rows'=>1000,'max_journal_packet_bytes'=>4096,'max_ledger_rows'=>10000,
 'max_selected_bytes'=>4194304,'max_output_bytes'=>4194304,'max_execution_seconds'=>20,'sql_timeout_ms'=>3000];}
function hef_semantics():array {return [
 'entry_basis'=>'registry_accepted_entries_event_join_not_rechecked',
 'current_access'=>'latest_exact_subject_profile_access_status_allowed_at_read_time',
 'source_clock_basis'=>'saved_database_recording_evidence_not_native_action_time',
 'profile_candidate_basis'=>'same_subject_and_scope_time_bounds_not_entry_attribution',
 'profile_origin_counts_basis'=>'declared_bounded_metadata_not_validated_money',
 'selection'=>'earliest_valid_exact_price_linked_frozen_profile_per_request_anchor_within_entry_24h',
 'request_identity'=>'observed_anchor_not_verified_person_or_trip',
 'exact_link'=>'frozen_channel_post_range_reference_and_matching_entry_publication_version_context',
 'other_price_origins_attributed_to_post'=>false,
 'ledger_scope'=>'all_valid_exact_linked_profiles_within_entry_24h_excluding_ambiguous_anchors',
 'ledger_lifecycle_basis'=>'recorded_observation_timestamps_and_current_states_as_of_read',
 'ledger_audit_sums_are_per_record_diagnostics'=>true,
 'forecast_value_and_ledger_delta_additive'=>false,
 'price_verified'=>false,'calibration_status'=>'expert_estimate_not_calibrated',
 'entries_are_clicks'=>false,'dialogues_are_unique_people'=>false,
 'max2_visit_linkage_established'=>false,'entry_capture_completeness'=>'not_scanned_without_exact_frozen_reference',
 'customer_texts_read'=>false,'full_profile_json_read'=>false,'recomputation_performed'=>false];}
function hef_ledger_empty():array {
 $states=[];foreach(HEF_STATES as$s)$states[$s]=['rows'=>0,'value_rub_sum'=>0];
 return ['rows'=>0,'value_rub_sum'=>0,'by_state'=>$states,'api_accepted_rows'=>0,'api_accepted_value_rub_sum'=>0,
  'processed_rows'=>0,'processed_value_rub_sum'=>0,'price_audit'=>['counts'=>array_fill_keys(HEF_AUDIT_ACTIONS,0),
   'original_rub_sum'=>0,'applied_rub_sum'=>0,'prior_reserved_rub_sum'=>0]];
}

function hef_empty_aggregate(int $now):array {return [
 'schema'=>'post_entry_forecast_aggregate_v1',
 'window'=>['from_utc'=>HEF_FROM,'until_utc_exclusive'=>HEF_UNTIL,'followup_until_utc_exclusive'=>HEF_FOLLOWUP_UNTIL,
  'business_timezone'=>'Europe/Moscow','days'=>7,'followup_hours'=>24],
 'checked_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now),
 'coverage'=>[
  'entry_rows_read'=>0,'complete_within_bound'=>true,'global_entry_rows_scanned'=>0,'global_window_entries'=>0,
  'unavailable_publication_entries'=>0,'invalid_binding_entries'=>0,'owner_scope_excluded_entries'=>0,'admitted_entries'=>0,
  'current_access_scopes_checked'=>0,'current_access_allowed_scopes'=>0,'current_access_blocked_scopes'=>0,
  'current_access_unavailable_scopes'=>0,'current_access_blocked_entries'=>0,'current_access_unavailable_entries'=>0,
  'admitted_scopes'=>0,'scopes_without_profile_in_followup'=>0,'profile_rows_read'=>0,'profile_packets_read'=>0,
  'profile_origin_counts'=>array_fill_keys(HEF_ORIGINS,0),'invalid_profile_packets'=>0,'unavailable_profile_packets'=>0,
  'profiles_unknown_clock'=>0,'profiles_outside_entry_24h'=>0,'profiles_not_exact_entry'=>0,'profiles_missing_price_context'=>0,
  'exact_linked_profiles'=>0,'exact_linked_entries'=>0,'selected_request_anchors'=>0,'ambiguous_request_anchors'=>0,
  'duplicate_profiles_same_anchor'=>0,'ledger_rows_read'=>0,'ledger_invalid_rows'=>0],
 'posts'=>[],'ledger'=>hef_ledger_empty(),'semantics'=>hef_semantics(),'limits'=>hef_limits()];}
function hef_decimal(mixed $v):bool {return (is_int($v)||is_string($v))&&preg_match('/\A[1-9][0-9]{0,18}\z/D',(string)$v)===1;}
function hef_iso(mixed $v):?int {
 if(!is_string($v)||preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/D',$v)!==1)return null;
 $t=strtotime($v);return $t!==false&&gmdate('Y-m-d\TH:i:s\Z',$t)===$v?$t:null;
}
function hef_entry_time(mixed $v):?int {
 if(!is_string($v)||preg_match('/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\z/D',$v)!==1)return null;
 $t=strtotime(str_replace(' ','T',$v).'Z');return $t!==false&&gmdate('Y-m-d H:i:s',$t)===$v?$t:null;
}
function hef_scope(array $r):string {return $r['platform'].'|'.$r['customer_key'].'|'.$r['conversation_id'];}
function hef_subject(mixed $s,array $expected):bool {
 if(!is_array($s)||count($s)!==4)return false;foreach($expected as$k=>$v)if(($s[$k]??null)!==$v)return false;return true;
}
function hef_band(int $age):string {return $age<=3600?'first_hour':($age<=10800?'first_three_hours':($age<=86400?'first_day':'later_update'));}
function hef_runtime_check():void {
 hef_need(trim((string)shell_exec('git rev-parse HEAD'))===HEF_RUNTIME,'runtime_changed');
 hef_need(trim((string)shell_exec('git status --porcelain --untracked-files=no'))==='','runtime_dirty');
 foreach(HEF_SOURCE_PINS as$file=>$hash)hef_need(hash_file('sha256',getcwd().'/'.$file)===$hash,'source_changed');
}
function hef_cohort(array $rows,array &$out):array {
 hef_need(count($rows)<=10000,'entry_limit_incomplete');$entries=[];$groups=[];$scopes=[];$seen=[];
 $c=&$out['coverage'];$c['entry_rows_read']=count($rows);
 foreach($rows as$r){
  hef_need(hef_decimal($r['entry_id']??null)&&!isset($seen[(string)$r['entry_id']]),'entry_duplicate');$seen[(string)$r['entry_id']]=true;
  $at=hef_entry_time($r['entered_at']??null);
  hef_need($at!==null&&$r['entered_at']>=HEF_FROM&&$r['entered_at']<HEF_UNTIL,'entry_clock');
  if(($r['publication_id']??null)===null){$c['unavailable_publication_entries']++;continue;}
  if(($r['binding_valid']??0)!=1){$c['invalid_binding_entries']++;continue;}
  hef_need(hef_decimal($r['publication_id'])&&hef_decimal($r['version_id']??null)
   &&in_array($r['platform']??null,['max','telegram'],true)
   &&is_string($r['channel_id']??null)&&preg_match('/\A-?[0-9]{1,64}\z/D',$r['channel_id'])===1,'entry_binding');
  $g=$r['platform'].'|'.$r['channel_id'].'|'.$r['publication_id'].'|'.$r['version_id'];
  if(!isset($groups[$g]))$groups[$g]=[
   'publication_id'=>(int)$r['publication_id'],'version_id'=>(int)$r['version_id'],'platform'=>$r['platform'],'channel_id'=>$r['channel_id'],
   'entries'=>0,'admitted_entries'=>0,'exact_linked_entries'=>0,'exact_linked_profiles'=>0,'selected_anchors'=>0,
   'forecasts'=>[],'ledger'=>hef_ledger_empty()];
  $groups[$g]['entries']++;
  if(($r['owner_scope_valid']??0)!=1){$c['owner_scope_excluded_entries']++;continue;}
  hef_need(hef_decimal($r['conversation_id']??null)&&hef_decimal($r['customer_key']??null),'entry_binding');
  $r['conversation_id']=(string)$r['conversation_id'];$r['customer_key']=(string)$r['customer_key'];
  $key=hef_scope($r);$r['entry_unix']=$at;$r['group']=$g;$r['scope']=$key;$entries[(string)$r['entry_id']]=$r;
  if(!isset($scopes[$key]))$scopes[$key]=[
   'subject'=>['project_key'=>'anytour','platform'=>$r['platform'],'customer_key'=>$r['customer_key'],'conversation_id'=>$r['conversation_id']],
   'from'=>$at,'until'=>$at+86400];
  $scopes[$key]['from']=min($scopes[$key]['from'],$at);$scopes[$key]['until']=max($scopes[$key]['until'],$at+86400);
  $groups[$g]['admitted_entries']++;$c['admitted_entries']++;
 }
 $c['admitted_scopes']=count($scopes);
 hef_need(array_sum(array_column($groups,'entries'))+$c['unavailable_publication_entries']+$c['invalid_binding_entries']===count($rows),'entry_reconciliation');
 return ['entries'=>$entries,'groups'=>$groups,'scopes'=>$scopes];
}
/** Latest current access is a separate fence; an older allowed capsule never restores it. */
function hef_current_access(array $cohort,array $latest,array &$out):array {
 hef_need(count($cohort['scopes'])<=10000,'current_access_limit_incomplete');
 $c=&$out['coverage'];$states=[];$c['current_access_scopes_checked']=count($cohort['scopes']);
 foreach($cohort['scopes']as$key=>$scope){
  $rows=$latest[$key]??[];
  hef_need(is_array($rows)&&count($rows)<=1,'current_access_binding');
  $status=count($rows)===1?($rows[0]['access_status']??null):null;
  $state=$status==='allowed'?'allowed':(in_array($status,['blocked','revoked'],true)?'blocked':'unavailable');
  $states[$key]=$state;$c['current_access_'.$state.'_scopes']++;
  if($state!=='allowed')unset($cohort['scopes'][$key]);
 }
 foreach($cohort['entries']as$id=>$entry){
  $state=$states[$entry['scope']]??'unavailable';
  if($state==='allowed')continue;
  $c['current_access_'.$state.'_entries']++;$c['admitted_entries']--;
  $cohort['groups'][$entry['group']]['admitted_entries']--;unset($cohort['entries'][$id]);
 }
 $c['admitted_scopes']=count($cohort['scopes']);
 return $cohort;
}
/** Mirrors the installed ForecastPaymentReader current native-scope scalar fence. */
function hef_current_access_sql():string {
 $safe="CASE WHEN OCTET_LENGTH(p.profile_json)<=131072 AND JSON_VALID(p.profile_json) THEN p.profile_json ELSE '{}' END";
 $status="JSON_UNQUOTE(JSON_EXTRACT($safe,'$.access_status'))";
 $owner=InternalOwnerDialogueScope::conversationSql('c');
 return "SELECT /*+ MAX_EXECUTION_TIME(3000) */ CASE WHEN OCTET_LENGTH($status)<=32 THEN $status ELSE NULL END AS access_status
  FROM modeled_conversion_profile_versions p FORCE INDEX (ix_profile_scope_time)
  JOIN conversations c ON c.id=p.conversation_id AND c.customer_id=p.customer_key
   AND c.project_key=p.project_key AND c.channel=p.platform AND HEX(CAST(c.id AS CHAR))=HEX(p.conversation_id)
   AND HEX(CAST(c.customer_id AS CHAR))=HEX(p.customer_key) AND HEX(c.project_key)=HEX(p.project_key) AND HEX(c.channel)=HEX(p.platform)
  JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id
   AND cc.project_key=c.project_key AND cc.channel=c.channel
   AND HEX(cc.project_key)=HEX(c.project_key) AND HEX(cc.channel)=HEX(c.channel)
   AND c.is_test=0 AND NOT ($owner)
   AND HEX(CAST(cc.external_chat_id AS CHAR))=HEX(CAST(c.external_chat_id AS CHAR))
   AND HEX(CAST(c.external_chat_id AS CHAR))=HEX(CAST(CASE WHEN p.platform='max' THEN CONCAT('-',cc.external_user_id) ELSE cc.external_user_id END AS CHAR))
  JOIN customers cu ON cu.id=c.customer_id
  WHERE p.project_key=? AND p.platform=? AND p.customer_key=? AND p.conversation_id=?
   AND p.project_key='anytour' AND HEX(p.project_key)=HEX('anytour')
   AND p.platform IN ('max','telegram') AND HEX(p.platform) IN (HEX('max'),HEX('telegram'))
  ORDER BY p.id DESC LIMIT 1";
}
/** Metadata only selects candidates; only two existing pure validators admit numbers. */
function hef_profiles(array $meta,array $packets,array $cohort,array &$out):array {
 hef_need(count($meta)<=10000,'profile_limit_incomplete');$c=&$out['coverage'];$c['profile_rows_read']=count($meta);
 $seen=[];$scopesWith=[];$valid=[];
 foreach($meta as$r){
  $id=(string)($r['id']??'');hef_need(hef_decimal($id)&&!isset($seen[$id]),'profile_binding');$seen[$id]=true;
  $key=hef_scope($r);$scope=$cohort['scopes'][$key]??null;hef_need($scope!==null,'profile_binding');$scopesWith[$key]=true;
  $origin=in_array($r['price_origin']??null,HEF_ORIGINS,true)?$r['price_origin']:'unavailable';$c['profile_origin_counts'][$origin]++;
  if($origin!=='channel_post_range')continue;
  $body=$packets[$id]??null;
  if(!is_string($body)||strlen($body)>32768){$c['unavailable_profile_packets']++;continue;}
  $c['profile_packets_read']++;
  try{$w=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){$c['invalid_profile_packets']++;continue;}
  if(!is_array($w)||!hef_subject($w['subject']??[],$scope['subject'])||($w['access_status']??null)!=='allowed'){
   $c['invalid_profile_packets']++;continue;
  }
  try{
   $checked=ModeledConversionValueRegistry::predictionFields($w);
   $money=ModeledConversionDealValue::saved($w);
  }catch(Throwable $e){$checked=[];$money=null;}
  $p=$w['semantic_prediction']??null;
  if(($checked['semantic_prediction']['availability']??null)!=='available'||$money===null||!is_array($p)
   ||($p['mode']??null)!=='contextual_advertising_v1'||!in_array($money['version'],HEF_POLICIES,true)
   ||$money['deal_amount_rub']['source_kind']!=='channel_post_range'||count($money['deal_amount_rub']['source_refs'])!==1
   ||!is_string($w['processing']['source_sha256']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$w['processing']['source_sha256'])!==1
   ||(string)($w['processing']['journal_cursor']??'')!==(string)$r['journal_cursor']
   ||(string)$p['journal_cursor']!==(string)$r['journal_cursor']
   ||($w['processing']['source_fence_journal_id']??null)!==$p['source_fence_journal_id']){
   $c['invalid_profile_packets']++;continue;
  }
  $frozen=hef_iso($money['frozen_at']);$cutoff=hef_iso($money['input_cutoff_at']);$profile=hef_iso($p['profile_prediction_at']);$anchorAt=null;$anchors=0;
  foreach($p['evidence']??[]as$e)if(($e['feature']??null)==='current_request_anchor'&&($e['source_ref']??null)===$p['current_request_anchor']){
   $anchors++;$anchorAt=hef_iso($e['occurred_at']??null);
  }
  $ref=$money['deal_amount_rub']['source_refs'][0];$jid=substr($ref,8);
  if($frozen===null||$cutoff===null||$profile===null||$anchors!==1||$anchorAt===null
   ||$anchorAt>$cutoff||$cutoff>$profile||$profile>$frozen||$profile!==(int)$r['observed_at_unix']
   ||$money['profile_prediction_at']!==$p['profile_prediction_at']
   ||!is_int($money['age_seconds'])||$money['age_seconds']!==$frozen-$anchorAt
   ||$money['early_window']!==hef_band($money['age_seconds'])
   ||!hef_decimal($jid)||(strlen($jid)>strlen($p['source_fence_journal_id']))
   ||(strlen($jid)===strlen($p['source_fence_journal_id'])&&strcmp($jid,$p['source_fence_journal_id'])>0)){
   $c['profiles_unknown_clock']++;continue;
  }
  if($frozen>=$scope['until']||$frozen<$scope['from']){$c['profiles_outside_entry_24h']++;continue;}
  $valid[$id]=['id'=>$id,'subject'=>$scope['subject'],'scope'=>$key,'semantic'=>$p,'money'=>$money,
   'frozen'=>$frozen,'cutoff'=>$cutoff,'anchor_at'=>$anchorAt,'journal_id'=>$jid,
   'anchor_key'=>$key.'|'.$p['current_request_anchor']];
 }
 $c['scopes_without_profile_in_followup']=count($cohort['scopes'])-count($scopesWith);
 return $valid;
}
function hef_link(array $valid,array $journals,array $cohort,array &$out):array {
 $linked=[];$anchors=[];$entrySet=[];$c=&$out['coverage'];
 foreach($valid as$id=>$p){
  $j=$journals[$p['journal_id']]??null;$s=is_array($j)?($j['packet']??null):null;
  if(!is_array($j)||!is_array($s)||($j['project_key']??null)!=='anytour'||($j['source_kind']??null)!=='event'
   ||!hef_subject($s['subject']??[],$p['subject'])||($s['source_kind']??null)!=='event'
   ||(string)($s['source_id']??'')!==(string)($j['source_id']??'')
   ||($s['source_status']??null)!=='present'||($s['payload_status']??null)!=='copied'
   ||($s['event_type']??null)!=='channel_post_entry'||($s['actor_type']??null)!=='customer'){
   $c['profiles_not_exact_entry']++;continue;
  }
  $entry=$cohort['entries'][(string)($s['entry_id']??'')]??null;
  if($entry===null||$entry['scope']!==$p['scope']
   ||!is_int($s['entry_id']??null)||!is_int($s['publication_id']??null)||!is_int($s['version_id']??null)
   ||(string)$s['publication_id']!==(string)$entry['publication_id']||(string)$s['version_id']!==(string)$entry['version_id']){
   $c['profiles_not_exact_entry']++;continue;
  }
  $entryAt=$entry['entry_unix'];$occurred=$s['source_created_at_unix']??null;$available=(int)($j['observed_at_unix']??0);
  if(!is_int($occurred)||$occurred<946684800||$occurred>$available||$available>$p['cutoff']
   ||!in_array($s['source_timestamp_basis']??null,['canonical_recording_unix_v1','database_session_timezone_to_unix'],true)
   ||$occurred>$p['anchor_at']||$p['anchor_at']-$occurred>86400||$p['anchor_at']<$entryAt){
   $c['profiles_unknown_clock']++;continue;
  }
  if($p['frozen']<$entryAt||$p['frozen']>=$entryAt+86400){$c['profiles_outside_entry_24h']++;continue;}
  $ctx=$s['price_context']??null;$d=$p['money']['deal_amount_rub'];$range=is_array($ctx)?($ctx['range']??null):null;
  $good=is_array($ctx)&&is_array($range)&&($ctx['version']??null)==='entry_price_context_v1'
   &&($ctx['captured_at_unix']??null)===$available
   &&(string)($ctx['publication_id']??'')===(string)$entry['publication_id']
   &&(string)($ctx['version_id']??'')===(string)$entry['version_id']
   &&is_string($ctx['content_sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$ctx['content_sha256'])===1
   &&$ctx['content_sha256']===($entry['content_hash']??null)&&($range['unit']??null)==='party_total';
  foreach(['low','high','point','basis']as$k)if(($range[$k]??null)!==($d[$k]??null))$good=false;
  if(!$good){$c['profiles_missing_price_context']++;continue;}
  $p['entry_id']=(string)$entry['entry_id'];$p['group']=$entry['group'];$linked[$id]=$p;
  $anchors[$p['anchor_key']][$p['entry_id']]=true;$entrySet[$p['entry_id']]=true;
 }
 $c['exact_linked_profiles']=count($linked);$c['exact_linked_entries']=count($entrySet);$selected=[];$admitted=[];
 foreach($anchors as$key=>$ids)if(count($ids)>1)$c['ambiguous_request_anchors']++;
 foreach($linked as$id=>$p){
  if(count($anchors[$p['anchor_key']])!==1)continue;$admitted[$id]=$p;$key=$p['anchor_key'];
  if(!isset($selected[$key])||[$p['frozen'],(int)$p['id']]<[$selected[$key]['frozen'],(int)$selected[$key]['id']])$selected[$key]=$p;
 }
 $c['selected_request_anchors']=count($selected);$c['duplicate_profiles_same_anchor']=count($admitted)-count($selected);
 return ['all'=>$linked,'admitted'=>$admitted,'selected'=>$selected];
}
function hef_money_cents(mixed $v):?int {
 if(!is_string($v)&&!is_int($v)&&!is_float($v))return null;$s=(string)$v;
 if(preg_match('/\A(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,2}))?\z/D',$s,$m)!==1)return null;
 return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
}
function hef_add_money(int|float $a,int $cents):int|float {
 $total=(int)round($a*100)+$cents;return $total%100===0?intdiv($total,100):$total/100;
}
function hef_ledger_add(array &$to,array $r,int $now):void {
 $cents=hef_money_cents($r['value_rub']);$state=$r['state'];$to['rows']++;$to['value_rub_sum']=hef_add_money($to['value_rub_sum'],$cents);
 $to['by_state'][$state]['rows']++;$to['by_state'][$state]['value_rub_sum']=hef_add_money($to['by_state'][$state]['value_rub_sum'],$cents);
 foreach(['api_accepted'=>'value_api_accepted_at_unix','processed'=>'value_processed_at_unix']as$label=>$key){
  $t=$r[$key]??null;if($t!==null&&(int)$t>=946684800&&(int)$t<=$now){
   $to[$label.'_rows']++;$to[$label.'_value_rub_sum']=hef_add_money($to[$label.'_value_rub_sum'],$cents);
  }
 }
 $action=$r['price_action']??'missing';
 if(!in_array($action,HEF_AUDIT_ACTIONS,true))$action='unverified';
 $numbers=[];foreach(['original_value_rub','applied_value_rub','prior_reserved_rub']as$k)$numbers[$k]=hef_money_cents($r[$k]??null);
 if($action!=='missing'&&in_array(null,$numbers,true))$action='unverified';
 $to['price_audit']['counts'][$action]++;
 if($action!=='missing'&&!in_array(null,$numbers,true))foreach(['original_value_rub'=>'original_rub_sum','applied_value_rub'=>'applied_rub_sum','prior_reserved_rub'=>'prior_reserved_rub_sum']as$k=>$dest)
  $to['price_audit'][$dest]=hef_add_money($to['price_audit'][$dest],$numbers[$k]);
}
function hef_finish(array $cohort,array $links,array $ledger,array &$out):void {
 $groups=$cohort['groups'];$seenEntries=[];
 foreach($links['all']as$p){$groups[$p['group']]['exact_linked_profiles']++;$seenEntries[$p['group']][$p['entry_id']]=true;}
 foreach($seenEntries as$g=>$ids)$groups[$g]['exact_linked_entries']=count($ids);
 foreach($links['selected']as$p){
  $g=&$groups[$p['group']];$g['selected_anchors']++;$m=$p['money'];$s=$p['semantic'];$key=$m['version'].'|'.$s['model_sha256'];
  if(!isset($g['forecasts'][$key]))$g['forecasts'][$key]=[
   'monetary_version'=>$m['version'],'semantic_version'=>$s['version'],'model_sha256'=>$s['model_sha256'],'anchors'=>0,
   'estimated_count'=>0,'probability_sum'=>null,'probability_mean'=>null,'frozen_value_rub_sum'=>null,'base_rub_sum'=>0,
   'deal_point_min_rub'=>null,'deal_point_max_rub'=>null,'age_seconds_min'=>$m['age_seconds'],'age_seconds_max'=>$m['age_seconds'],
   'age_bands'=>array_fill_keys(HEF_BANDS,0),'first_frozen_at'=>$m['frozen_at'],'last_frozen_at'=>$m['frozen_at']];
  $v=&$g['forecasts'][$key];$v['anchors']++;$v['base_rub_sum']+=$m['full_value_base_rub'];$v['age_bands'][$m['early_window']]++;
  $v['age_seconds_min']=min($v['age_seconds_min'],$m['age_seconds']);$v['age_seconds_max']=max($v['age_seconds_max'],$m['age_seconds']);
  $v['first_frozen_at']=min($v['first_frozen_at'],$m['frozen_at']);$v['last_frozen_at']=max($v['last_frozen_at'],$m['frozen_at']);
  $point=$m['deal_amount_rub']['point'];
  if(is_int($point)){$v['deal_point_min_rub']=$v['deal_point_min_rub']===null?$point:min($v['deal_point_min_rub'],$point);$v['deal_point_max_rub']=$v['deal_point_max_rub']===null?$point:max($v['deal_point_max_rub'],$point);}
  if($m['status']==='estimated'){$v['estimated_count']++;$v['probability_sum']=($v['probability_sum']??0)+$m['estimated_probability'];$v['frozen_value_rub_sum']=($v['frozen_value_rub_sum']??0)+$m['value_rub'];}
  unset($v,$g);
 }
 $seen=[];$out['coverage']['ledger_rows_read']=count($ledger);$now=hef_iso($out['checked_at_utc']);
 foreach($ledger as$r){
  $id=(string)($r['id']??'');hef_need(hef_decimal($id)&&!isset($seen[$id]),'ledger_duplicate');$seen[$id]=true;
  $p=$links['admitted'][(string)($r['value_profile_id']??'')]??null;if($p===null)continue;
  $cents=hef_money_cents($r['value_rub']??null);$target=$r['target']??'';
  $targetOk=$target==='anytour_value_pred_'.$p['id'].'_v1'||preg_match('/\Aanytour_value_[1-6]_v1\z/D',$target)===1;
  if($cents===null||$cents<=0||$cents>10000000||!in_array($r['state']??null,HEF_STATES,true)||!$targetOk
   ||($r['platform']??null)!==$p['subject']['platform']||(string)($r['conversation_id']??'')!==$p['subject']['conversation_id']
   ||(int)($r['event_unix']??0)<$cohort['entries'][$p['entry_id']]['entry_unix']
   ||(int)$r['event_unix']>$now){
   $out['coverage']['ledger_invalid_rows']++;continue;
  }
  hef_ledger_add($out['ledger'],$r,$now);hef_ledger_add($groups[$p['group']]['ledger'],$r,$now);
 }
 foreach($groups as&$g){
  foreach($g['forecasts']as&$v)if($v['estimated_count']>0){
   $v['probability_sum']=round($v['probability_sum'],6);$v['probability_mean']=round($v['probability_sum']/$v['estimated_count'],6);
  }unset($v);$g['forecasts']=array_values($g['forecasts']);
 }unset($g);
 $out['posts']=array_values($groups);
 usort($out['posts'],fn($a,$b)=>($a['publication_id']<=>$b['publication_id'])?:($a['version_id']<=>$b['version_id']));
}
function hef_plan(array $plan,bool $requireEntry=true):void {
 hef_need(count($plan)>0&&count($plan)<=20,'explain_shape');$cost=0;$seen=false;
 foreach($plan as $row){
  $table=(string)($row['table']??'');$type=strtoupper((string)($row['type']??''));
  hef_need(isset($row['rows'])&&is_numeric($row['rows']),'explain_rows_unknown');$cost+=(int)$row['rows'];
  hef_need(!str_contains(strtoupper((string)($row['Extra']??'')),'DEPENDENT'),'explain_dependent');
  if(in_array($table,['pe','scope'],true)){
   if($table==='pe')$seen=true;
   hef_need(in_array($type,['CONST','EQ_REF','REF','RANGE'],true)&&!empty($row['key']),'unbounded_base_access');
  }
  if($type==='ALL'||$type==='INDEX')hef_need(str_starts_with($table,'<derived')&&(int)$row['rows']<=10001,'explain_full_scan');
 }
 hef_need((!$requireEntry||$seen)&&$cost<=50000,'explain_cost_or_missing_base');
}
function hef_server_version(string $version):void {
 // Only positively identified Oracle/MySQL compatible version syntax; MariaDB ignores this hint.
 hef_need(!preg_match('/mariadb|tidb|percona|aurora/i',$version),'unsupported_server_version');
 hef_need(preg_match('/\A(5\.7\.(\d+)|8\.(0|4)\.\d+)(?:-(?:log|[0-9]+ubuntu[0-9.]+|debian[0-9.]+))?\z/D',$version)===1,'unsupported_server_version');
 if(str_starts_with($version,'5.7.'))hef_need(version_compare(preg_replace('/-.*/','',$version),'5.7.8','>='),'unsupported_server_version');
}

final class HEFReadBudget {
 public int $bytes=0;
 public function __construct(public PDO $pdo,public float $deadline){}
 public function check():void {hef_need(microtime(true)<$this->deadline,'wall_budget');}
 public function account(array $r):void {$this->bytes+=strlen(json_encode($r,JSON_THROW_ON_ERROR));hef_need($this->bytes<=4194304,'selected_byte_limit');$this->check();}
 public function select(string $sql,array $args=[],bool $entryPlan=false,int $limit=10001):array {
  $this->check();hef_need(str_starts_with($sql,'SELECT /*+ MAX_EXECUTION_TIME(3000) */'),'query_scope');
  $q=$this->pdo->prepare('EXPLAIN '.$sql);$q->execute($args);$plan=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
  $this->account($plan);hef_plan($plan,$entryPlan);$this->check();
  $q=$this->pdo->prepare($sql);$q->execute($args);$rows=[];
  while($r=$q->fetch(PDO::FETCH_ASSOC)){hef_need(count($rows)<$limit,'row_limit_incomplete');$this->account($r);$rows[]=$r;}
  $q->closeCursor();return $rows;
 }
}
function hef_packet_sql(string $safe):string {
 $x=static fn(string $path):string=>"JSON_EXTRACT($safe,'$.$path')";
 return "JSON_OBJECT('access_status',".$x('access_status').",'subject',JSON_OBJECT('project_key',".$x('subject.project_key')
  .",'platform',".$x('subject.platform').",'customer_key',".$x('subject.customer_key').",'conversation_id',".$x('subject.conversation_id')
  ."),'profile',JSON_OBJECT('prediction_at',".$x('profile.prediction_at')."),'processing',JSON_OBJECT('source_sha256',".$x('processing.source_sha256')
  .",'journal_cursor',".$x('processing.journal_cursor').",'source_fence_journal_id',".$x('processing.source_fence_journal_id')
  ."),'semantic_prediction',".$x('semantic_prediction').",'deal_value_prediction',".$x('deal_value_prediction').")";
}
function hef_journal_packet_sql():string {
 $safe="CASE WHEN JSON_VALID(j.snapshot_json) THEN j.snapshot_json ELSE '{}' END";
 $x=static fn(string $path):string=>"JSON_EXTRACT($safe,'$.$path')";
 $raw="JSON_UNQUOTE(".$x('payload.payload_json').")";$event="CASE WHEN JSON_VALID($raw) THEN $raw ELSE '{}' END";
 $e=static fn(string $key):string=>"JSON_EXTRACT($event,'$.$key')";
 $pc=static fn(string $key):string=>$x('payload._modeled_price_context.'.$key);
 return "JSON_OBJECT('subject',JSON_OBJECT('project_key',JSON_UNQUOTE(".$x('project_key')."),'platform',JSON_UNQUOTE(".$x('platform')
  ."),'customer_key',JSON_UNQUOTE(".$x('customer_key')."),'conversation_id',JSON_UNQUOTE(".$x('conversation_id')
  .")),'source_kind',".$x('source_kind').",'source_id',".$x('source_id').",'source_status',".$x('source_status')
  .",'payload_status',".$x('payload_status').",'source_created_at_unix',".$x('source_created_at_unix')
  .",'source_timestamp_basis',".$x('source_timestamp_basis').",'event_type',".$x('payload.event_type').",'actor_type',".$x('payload.actor_type')
  .",'entry_id',".$e('entry_id').",'publication_id',".$e('publication_id').",'version_id',".$e('version_id')
  .",'price_context',JSON_OBJECT('version',".$pc('version').",'captured_at_unix',".$pc('captured_at_unix')
  .",'publication_id',".$pc('publication_id').",'version_id',".$pc('version_id').",'content_sha256',".$pc('content_sha256')
  .",'range',JSON_OBJECT('low',".$pc('range.low').",'high',".$pc('range.high').",'point',".$pc('range.point')
  .",'unit',".$pc('range.unit').",'party_size',".$pc('range.party_size').",'basis',".$pc('range.basis').")))";
}
// END ACCEPTED PURE SELECTION LIBRARY
/** Pure private projection. Embedded into the self-contained program by build.py. */
function m2a_public_key(string $pem):OpenSSLAsymmetricKey {
 $key=openssl_pkey_get_public($pem);hef_need($key!==false,'report_public_key_invalid');
 $d=openssl_pkey_get_details($key);
 hef_need(is_array($d)&&($d['type']??null)===OPENSSL_KEYTYPE_RSA&&($d['bits']??null)===3072,'report_public_key_invalid');
 return $key;
}
function m2a_decimal(mixed $v,int $max=20):?string {
 if(!is_int($v)&&!is_string($v))return null;$s=(string)$v;
 return preg_match('/\A[1-9][0-9]{0,'.($max-1).'}\z/D',$s)===1?$s:null;
}
function m2a_ref(string $domain,array $parts):string {
 return hash('sha256',json_encode([$domain,...array_map(static fn($v):string=>(string)$v,$parts)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}
function m2a_capsule(array $tuple,OpenSSLAsymmetricKey $key):string {
 $plain=json_encode($tuple,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 hef_need(strlen($plain)<=342,'identity_capsule_size');$cipher='';
 hef_need(openssl_public_encrypt($plain,$cipher,$key,OPENSSL_PKCS1_OAEP_PADDING)&&strlen($cipher)===384,'identity_capsule_encrypt');
 return base64_encode($cipher);
}
/** Missing/invalid CID remains explicit. The native actor has already passed the current access fence. */
function m2a_identity(mixed $user,mixed $cid,OpenSSLAsymmetricKey $key,bool $actorOnly=false):array {
 $u=m2a_decimal($user);$c=m2a_decimal($cid,40);
 $state=$u===null?'invalid_native_actor':($actorOnly?'not_requested':($c!==null?'present':($cid===null||$cid===''?'missing':'invalid')));
 if($actorOnly)$c=null;
 $capsule=$u===null?null:m2a_capsule(['domain'=>'max2_hotel_identity_v1','counter'=>'98615635',
  'user_decimal'=>$u,'cid_decimal'=>$c,'yclid_if_relevant'=>null],$key);
 return ['basis'=>$c===null?'native_actor_only':'native_actor_and_client','identity_state'=>$state,'identity_capsule_b64'=>$capsule];
}
function m2a_clock(mixed $v,int $now):?int {
 if(!is_int($v)&&!is_string($v))return null;
 if(preg_match('/\A[1-9][0-9]{8,10}\z/D',(string)$v)!==1)return null;
 $at=(int)$v;return $at>=946684800&&$at<=$now?$at:null;
}
function m2a_empty_bridge():array {return [
 'schema'=>'max2_hotel_app_identity_bridge_v1',
 'inner_scheme'=>'RSA-OAEP-SHA1','rsa_bits'=>3072,'identity_domain'=>'max2_hotel_identity_v1',
 'coverage'=>['current_allowed_max_entries'=>0,'entries_invalid_native_actor'=>0,
  'nonambiguous_max_profiles'=>0,'selected_earliest_max_profiles'=>0,'ambiguous_max_profiles_excluded'=>0,
  'max_ledger_rows'=>0,'max_ledger_value_rub_sum'=>0,'identity_source_mismatch_rows'=>0,
  'ledger_identity_present'=>0,'ledger_identity_missing'=>0,'ledger_identity_invalid'=>0,
  'ledger_invalid_native_actor'=>0,'tracker_record_present'=>0,'tracker_record_missing_or_invalid'=>0,
  'tracker_clock_valid'=>0,'tracker_clock_unknown'=>0,'tracker_clock_after_signal'=>0],
 'entries'=>[],'profiles'=>[],'ledger_rows'=>[],
 'semantics'=>[
  'entry_selection'=>'same_accepted_registry_window_and_current_latest_profile_access_fence',
  'profile_selection'=>'all_nonambiguous_exact_price_linked_frozen_profiles_within_entry_24h',
  'earliest_flag'=>'earliest_valid_exact_price_linked_profile_per_subject_scoped_anchor',
  'identity_source'=>'actual_saved_outbox_max_tracker_binding_not_new_lookup',
  'identity_agreement_is_visit_or_causal_attribution'=>false,
  'native_actor_only_is_exact_client_agreement'=>false,
  'source_clock'=>'saved_tracker_clock_interpreted_by_installed_policy_not_native_action',
  'ledger_lifecycle'=>'current_saved_rows_as_of_read_not_entry_24h_state',
  'profile_values_and_ledger_rows_additive'=>false,
  'repeated_tracker_ids_are_distinct_visits'=>false,
  'raw_customer_identity_exported'=>false,'raw_customer_text_exported'=>false,
  'yclid_projection'=>'not_read_from_app_outbox',
  'decryption'=>'local_private_key_memory_only_after_outer_authentication'],
 'limits'=>['max_entries'=>10000,'max_profiles'=>10000,'max_ledger_rows'=>10000,
  'max_tuple_bytes'=>342,'ciphertext_bytes'=>384,'max_selected_bytes'=>4194304,'max_output_bytes'=>4194304]];}
function m2a_bridge(array $cohort,array $links,array $ledger,array $aggregate,OpenSSLAsymmetricKey $key):array {
 $out=m2a_empty_bridge();$c=&$out['coverage'];$now=hef_iso($aggregate['checked_at_utc']);hef_need($now!==null,'entry_clock');
 $entries=[];
 foreach($cohort['entries']as$id=>$entry){
  if($entry['platform']!=='max')continue;
  hef_need(isset($cohort['scopes'][$entry['scope']]),'current_access_binding');
  $identity=m2a_identity($entry['verified_native_actor']??null,null,$key,true);
  $ref=m2a_ref('max2_hotel_app_entry_v1',['anytour','max',$id]);$entries[$id]=['ref'=>$ref,'row'=>$entry];
  $c['current_allowed_max_entries']++;if($identity['identity_state']==='invalid_native_actor')$c['entries_invalid_native_actor']++;
  $out['entries'][]=['entry_ref'=>$ref,'platform'=>'max','channel_id'=>$entry['channel_id'],
   'publication_id'=>(int)$entry['publication_id'],'version_id'=>(int)$entry['version_id'],
   'entered_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',$entry['entry_unix']),...$identity];
 }
 $selected=[];foreach($links['selected']as$p)$selected[$p['id']]=true;
 foreach($links['all']as$id=>$p)if($p['subject']['platform']==='max'&&!isset($links['admitted'][$id]))$c['ambiguous_max_profiles_excluded']++;
 $profiles=[];
 foreach($links['admitted']as$id=>$p){
  if($p['subject']['platform']!=='max')continue;
  hef_need(isset($entries[$p['entry_id']]),'profile_binding');$entry=$entries[$p['entry_id']];$m=$p['money'];$s=$p['semantic'];
  $profileRef=m2a_ref('max2_hotel_app_profile_v1',['anytour','max',$id]);
  $anchorRef=m2a_ref('max2_hotel_app_anchor_v1',['anytour',$p['anchor_key']]);
  $first=isset($selected[$id]);$c['nonambiguous_max_profiles']++;if($first)$c['selected_earliest_max_profiles']++;
  $profiles[$id]=['ref'=>$profileRef,'anchor'=>$anchorRef,'profile'=>$p,'selected'=>$first];
  $out['profiles'][]=['value_profile_ref'=>$profileRef,'entry_ref'=>$entry['ref'],'anchor_ref'=>$anchorRef,
   'publication_id'=>(int)$entry['row']['publication_id'],'version_id'=>(int)$entry['row']['version_id'],
   'channel_id'=>$entry['row']['channel_id'],'selected_earliest'=>$first,
   'monetary_version'=>$m['version'],'monetary_rules_sha256'=>$m['rules_sha256'],
   'semantic_version'=>$s['version'],'model_sha256'=>$s['model_sha256'],
   'deal_digest'=>$m['deal_digest'],'frozen_at'=>$m['frozen_at'],'input_cutoff_at'=>$m['input_cutoff_at'],
   'profile_prediction_at'=>$m['profile_prediction_at'],'age_seconds'=>$m['age_seconds'],'early_window'=>$m['early_window'],
   'forecast_status'=>$m['status'],'estimated_probability'=>$m['estimated_probability'],
   'saved_value_rub'=>$m['value_rub'],'saved_base_rub'=>$m['full_value_base_rub'],
   'commission_estimate_rub'=>$m['commission_estimate_rub'],
   'price'=>['low'=>$m['deal_amount_rub']['low'],'high'=>$m['deal_amount_rub']['high'],
    'point'=>$m['deal_amount_rub']['point'],'basis'=>$m['deal_amount_rub']['basis'],
    'source_kind'=>'channel_post_range','unit'=>'party_total','price_verified'=>false,
    'content_sha256'=>$entry['row']['content_hash'],'exact_frozen_entry_price_link'=>true]];
 }
 $seen=[];
 foreach($ledger as$r){
  $id=(string)($r['id']??'');hef_need(hef_decimal($id)&&!isset($seen[$id]),'ledger_duplicate');$seen[$id]=true;
  if(($r['platform']??null)!=='max')continue;
  $bound=$profiles[(string)($r['value_profile_id']??'')]??null;if($bound===null)continue;
  $p=$bound['profile'];$entry=$entries[$p['entry_id']];$cents=hef_money_cents($r['value_rub']??null);$target=$r['target']??'';
  $targetOk=$target==='anytour_value_pred_'.$p['id'].'_v1'||preg_match('/\Aanytour_value_[1-6]_v1\z/D',$target)===1;
  // Same admission as hef_finish: no new score, delta, event or clock policy.
  if($cents===null||$cents<=0||$cents>10000000||!in_array($r['state']??null,HEF_STATES,true)||!$targetOk
   ||(string)($r['conversation_id']??'')!==$p['subject']['conversation_id']
   ||(int)($r['event_unix']??0)<$entry['row']['entry_unix']||(int)$r['event_unix']>$now)continue;
  $user=m2a_decimal($r['verified_native_actor']??null);$entryUser=m2a_decimal($entry['row']['verified_native_actor']??null);
  // Native bindings are already enforced by canonical sourceSql; require agreement again before disclosure.
  hef_need($user===$entryUser,'identity_scope_mismatch');
  $sourceOk=($r['identity_source_key']??null)==='max_tracker'&&($r['counter_id']??null)==='98615635';
  $identity=$sourceOk?m2a_identity($user,$r['client_id']??null,$key):[
   'basis'=>'unavailable','identity_state'=>'identity_source_mismatch','identity_capsule_b64'=>null];
  $tracker=$sourceOk?m2a_decimal($r['tracker_record_id']??null):null;
  $clock=$sourceOk?m2a_clock($r['tracker_observed_unix']??null,$now):null;
  $c['max_ledger_rows']++;$c['max_ledger_value_rub_sum']=hef_add_money($c['max_ledger_value_rub_sum'],$cents);
  if(!$sourceOk)$c['identity_source_mismatch_rows']++;
  elseif($identity['identity_state']==='invalid_native_actor')$c['ledger_invalid_native_actor']++;
  else $c['ledger_identity_'.$identity['identity_state']]++;
  $c[$tracker===null?'tracker_record_missing_or_invalid':'tracker_record_present']++;
  $c[$clock===null?'tracker_clock_unknown':'tracker_clock_valid']++;
  if($clock!==null&&$clock>(int)$r['event_unix'])$c['tracker_clock_after_signal']++;
  $out['ledger_rows'][]=['outbox_ref'=>m2a_ref('max2_hotel_app_outbox_v1',['anytour','max',$id]),
   'value_profile_ref'=>$bound['ref'],'entry_ref'=>$entry['ref'],'anchor_ref'=>$bound['anchor'],
   'publication_id'=>(int)$entry['row']['publication_id'],'version_id'=>(int)$entry['row']['version_id'],'channel_id'=>$entry['row']['channel_id'],
   'selected_earliest_profile'=>$bound['selected'],'counter_id'=>'98615635',
   'identity_source_key'=>$sourceOk?'max_tracker':'unavailable',
   'tracker_record_id'=>$tracker,'tracker_observed_unix'=>$clock,
   'tracker_clock_state'=>$clock===null?'unknown':($clock>(int)$r['event_unix']?'after_signal':'at_or_before_signal'),
   'event_unix'=>(int)$r['event_unix'],'target_kind'=>str_starts_with($target,'anytour_value_pred_')?'profile_prediction':'legacy_stage',
   'state'=>$r['state'],'value_rub'=>hef_add_money(0,$cents),
   'api_accepted_at_unix'=>m2a_clock($r['value_api_accepted_at_unix']??null,$now),
   'processed_at_unix'=>m2a_clock($r['value_processed_at_unix']??null,$now),...$identity];
 }
 $expectedRows=0;$expectedCents=0;
 foreach($aggregate['posts']as$row)if($row['platform']==='max'){
  $expectedRows+=$row['ledger']['rows'];$v=hef_money_cents($row['ledger']['value_rub_sum']);hef_need($v!==null,'bridge_reconciliation');$expectedCents+=$v;
 }
 hef_need($expectedRows===$c['max_ledger_rows']&&$expectedCents===hef_money_cents($c['max_ledger_value_rub_sum']),'bridge_reconciliation');
 return $out;
}
function m2a_read(PDO $pdo,int $now,OpenSSLAsymmetricKey $reportKey):array {
 hef_need($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql','mysql_required');hef_need(!$pdo->inTransaction(),'outer_transaction');
 hef_server_version((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
 hef_need($now>=hef_entry_time(HEF_FOLLOWUP_UNTIL),'followup_not_mature');
 $budget=new HEFReadBudget($pdo,microtime(true)+17.0);$out=hef_empty_aggregate($now);
 $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
 try{
  $settings=$budget->select("SELECT /*+ MAX_EXECUTION_TIME(3000) */ counter_id,goal_id,contract_version FROM modeled_conversion_value_export_settings WHERE project_key='anytour' LIMIT 2");
  hef_need(count($settings)===1&&(string)$settings[0]['counter_id']==='98615635'&&(string)$settings[0]['goal_id']==='678187097'
   &&$settings[0]['contract_version']==='semantic_stage_value_25000_v1','goal_scope_changed');
  $scan="SELECT /*+ MAX_EXECUTION_TIME(3000) */ id,created_at FROM post_entries FORCE INDEX (PRIMARY) ORDER BY id LIMIT 10001";
  $q=$pdo->query('EXPLAIN '.$scan);$plan=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$budget->account($plan);
  hef_need(count($plan)===1&&($plan[0]['key']??null)==='PRIMARY'&&strtoupper((string)($plan[0]['type']??''))==='INDEX','capped_primary_plan');
  $budget->check();$q=$pdo->query($scan);$ids=[];$scanned=0;
  while($r=$q->fetch(PDO::FETCH_ASSOC)){
   hef_need(++$scanned<=10000,'entry_limit_incomplete');$budget->account($r);
   if($r['created_at']>=HEF_FROM&&$r['created_at']<HEF_UNTIL)$ids[]=(int)$r['id'];
  }$q->closeCursor();$entryRows=[];
  foreach(array_chunk($ids,500)as$chunk){
   $marks=implode(',',array_fill(0,count($chunk),'?'));$owner=InternalOwnerDialogueScope::conversationSql('c');
   $sql="SELECT /*+ MAX_EXECUTION_TIME(3000) */ pe.id AS entry_id,pe.conversation_id,c.customer_id AS customer_key,pe.publication_id,pe.version_id,pe.created_at AS entered_at,pc.platform,pc.channel_id,v.content_hash,CAST(cc.external_user_id AS CHAR) AS verified_native_actor,
    CASE WHEN pp.id IS NOT NULL AND v.id IS NOT NULL AND p.project_key='anytour' AND pc.platform=c.channel AND pp.source_id=pc.source_id AND src.project_id=pc.project_id THEN 1 ELSE 0 END AS binding_valid,
    CASE WHEN NOT ($owner) AND HEX(c.project_key)=HEX('anytour') AND HEX(c.channel)=HEX(pc.platform)
     AND cc.id IS NOT NULL AND HEX(cc.project_key)=HEX(c.project_key) AND HEX(cc.channel)=HEX(c.channel)
     AND HEX(CAST(cc.external_chat_id AS CHAR))=HEX(CAST(c.external_chat_id AS CHAR))
     AND HEX(CAST(c.external_chat_id AS CHAR))=HEX(CAST(CASE WHEN pc.platform='max' THEN CONCAT('-',cc.external_user_id) ELSE cc.external_user_id END AS CHAR))
     THEN 1 ELSE 0 END AS owner_scope_valid
    FROM post_entries pe FORCE INDEX (PRIMARY) JOIN conversations c ON c.id=pe.conversation_id
    LEFT JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id
    LEFT JOIN post_publications pp ON pp.id=pe.publication_id LEFT JOIN post_channels pc ON pc.id=pp.post_channel_id
    LEFT JOIN projects p ON p.id=pc.project_id LEFT JOIN conversation_sources src ON src.id=pp.source_id
    LEFT JOIN post_versions v ON v.id=pe.version_id AND v.publication_id=pp.id
    WHERE pe.id IN ($marks) AND c.project_key='anytour' AND c.is_test=0 AND c.channel IN ('max','telegram') ORDER BY pe.id";
   $batch=$budget->select($sql,$chunk,true,count($chunk));$entryRows=[...$entryRows,...$batch];
  }
  $out['coverage']['global_entry_rows_scanned']=$scanned;$out['coverage']['global_window_entries']=count($ids);
  $cohort=hef_cohort($entryRows,$out);$latest=[];$accessSql=hef_current_access_sql();
  hef_need(count($cohort['scopes'])<=10000,'current_access_limit_incomplete');
  foreach($cohort['scopes']as$key=>$scope){
   $s=$scope['subject'];$latest[$key]=$budget->select($accessSql,
    [$s['project_key'],$s['platform'],$s['customer_key'],$s['conversation_id']],false,1);
  }
  $cohort=hef_current_access($cohort,$latest,$out);
  $meta=[];$safe="CASE WHEN JSON_VALID(p.profile_json) THEN p.profile_json ELSE '{}' END";
  $origin="JSON_UNQUOTE(JSON_EXTRACT($safe,'$.deal_value_prediction.deal_amount_rub.source_kind'))";
  foreach(array_chunk(array_values($cohort['scopes']),25)as$scopes){
   $where=[];$args=[];
   foreach($scopes as$scope){$s=$scope['subject'];$where[]='(p.platform=? AND p.customer_key=? AND p.conversation_id=? AND p.observed_at_unix>=? AND p.observed_at_unix<?)';
    array_push($args,$s['platform'],$s['customer_key'],$s['conversation_id'],$scope['from'],$scope['until']);}
   $remaining=10001-count($meta);
   $sql="SELECT /*+ MAX_EXECUTION_TIME(3000) */ p.id,p.platform,p.customer_key,p.conversation_id,p.journal_cursor,p.observed_at_unix,
    CASE WHEN OCTET_LENGTH($origin)<=64 THEN $origin ELSE NULL END AS price_origin
    FROM modeled_conversion_profile_versions p FORCE INDEX (ix_profile_scope_time)
    WHERE p.project_key='anytour' AND (".implode(' OR ',$where).") ORDER BY p.id LIMIT ".$remaining;
   $batch=$budget->select($sql,$args,false,$remaining);$meta=[...$meta,...$batch];hef_need(count($meta)<=10000,'profile_limit_incomplete');
  }
  $wanted=[];foreach($meta as$r)if(($r['price_origin']??null)==='channel_post_range')$wanted[]=(string)$r['id'];
  $packets=[];$packet=hef_packet_sql($safe);
  foreach(array_chunk($wanted,50)as$chunk){
   $marks=implode(',',array_fill(0,count($chunk),'?'));
   $sql="SELECT /*+ MAX_EXECUTION_TIME(3000) */ id,CASE WHEN OCTET_LENGTH(CAST(packet AS CHAR))<=32768 THEN packet ELSE NULL END AS packet
    FROM (SELECT p.id,$packet AS packet FROM modeled_conversion_profile_versions p FORCE INDEX (PRIMARY)
     WHERE p.project_key='anytour' AND p.id IN ($marks) ORDER BY p.id LIMIT ".count($chunk)." OFFSET 0) bounded_packets";
   foreach($budget->select($sql,$chunk,false,count($chunk))as$r)$packets[(string)$r['id']]=$r['packet'];
  }
  $valid=hef_profiles($meta,$packets,$cohort,$out);$journalIds=[];
  foreach($valid as$p)$journalIds[$p['journal_id']]=true;hef_need(count($journalIds)<=1000,'journal_limit_incomplete');
  $journals=[];$jp=hef_journal_packet_sql();
  foreach(array_chunk(array_keys($journalIds),50)as$chunk){
   $marks=implode(',',array_fill(0,count($chunk),'?'));
   $sql="SELECT /*+ MAX_EXECUTION_TIME(3000) */ id,project_key,source_kind,source_id,version_no,observed_at_unix,
    CASE WHEN OCTET_LENGTH(CAST(packet AS CHAR))<=4096 THEN packet ELSE NULL END AS packet
    FROM (SELECT j.id,j.project_key,j.source_kind,j.source_id,j.version_no,j.observed_at_unix,$jp AS packet
      FROM modeled_conversion_stream_versions j FORCE INDEX (PRIMARY)
      WHERE j.project_key='anytour' AND j.id IN ($marks) ORDER BY j.id LIMIT ".count($chunk)." OFFSET 0) bounded_journal";
   foreach($budget->select($sql,$chunk,false,count($chunk))as$r){
    try{$r['packet']=is_string($r['packet'])?json_decode($r['packet'],true,24,JSON_THROW_ON_ERROR):null;}catch(Throwable $e){$r['packet']=null;}
    $journals[(string)$r['id']]=$r;
   }
  }
  $links=hef_link($valid,$journals,$cohort,$out);$byConversation=[];
  foreach($links['admitted']as$p)$byConversation[$p['subject']['conversation_id']][]=$p['id'];
  $ledger=[];$canonical=ModeledConversionDeliveryRegistry::sourceSql(true,true);$pos=strpos($canonical,' WHERE ');
  hef_need($pos!==false,'query_scope');$canonical=substr_replace($canonical,' LEFT JOIN modeled_conversion_price_audit a ON a.outbox_id=o.id',$pos,0);
  foreach(array_chunk(array_keys($byConversation),50)as$conversations){
   $profiles=[];foreach($conversations as$cid)$profiles=[...$profiles,...$byConversation[$cid]];
   $cm=implode(',',array_fill(0,count($conversations),'?'));$pm=implode(',',array_fill(0,count($profiles),'?'));$remaining=10001-count($ledger);
   $sql="SELECT /*+ MAX_EXECUTION_TIME(3000) */ o.id,o.conversation_id,o.platform,o.value_profile_id,o.target,o.event_unix,o.value_rub,o.state,
    o.value_api_accepted_at_unix,o.value_processed_at_unix,
    CAST(o.external_user_id AS CHAR) AS verified_native_actor,CAST(o.counter_id AS CHAR) AS counter_id,o.identity_source_key,
    o.client_id,o.tracker_record_id,o.tracker_observed_unix,
    a.action AS price_action,a.original_value_rub,a.applied_value_rub,a.prior_reserved_rub ".$canonical
    ." AND p.id=o.value_profile_id AND o.conversation_id IN ($cm) AND o.value_profile_id IN ($pm) ORDER BY o.id LIMIT ".$remaining;
   $batch=$budget->select($sql,[...$conversations,...$profiles],false,$remaining);$ledger=[...$ledger,...$batch];
   hef_need(count($ledger)<=10000,'ledger_limit_incomplete');
  }
  hef_finish($cohort,$links,$ledger,$out);$budget->check();
  $bridge=m2a_bridge($cohort,$links,$ledger,$out,$reportKey);$budget->check();
  $result=['aggregate'=>$out,'bridge'=>$bridge];
  hef_need(strlen(json_encode($result,JSON_THROW_ON_ERROR))<=4194304,'output_byte_limit');
  $pdo->rollBack();return $result;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function hef_safe_error(Throwable $e):string {
 $codes=['runtime_changed','runtime_dirty','source_changed','mysql_required','outer_transaction','unsupported_server_version',
  'followup_not_mature','goal_scope_changed','wall_budget','selected_byte_limit','output_byte_limit','query_scope','row_limit_incomplete',
  'entry_limit_incomplete','profile_limit_incomplete','journal_limit_incomplete','ledger_limit_incomplete',
  'current_access_limit_incomplete','current_access_binding',
  'entry_duplicate','entry_clock','entry_binding','entry_reconciliation','profile_binding','ledger_duplicate','capped_primary_plan',
  'explain_shape','explain_rows_unknown','explain_dependent','unbounded_base_access','explain_full_scan','explain_cost_or_missing_base'];
 return in_array($e->getMessage(),$codes,true)?$e->getMessage():'bounded_read_stopped';
}

function m2a_safe_error(Throwable $e):string {
 $own=['report_public_key_invalid','identity_capsule_size','identity_capsule_encrypt','identity_scope_mismatch','bridge_reconciliation'];
 return in_array($e->getMessage(),$own,true)?$e->getMessage():hef_safe_error($e);
}
if(!defined('MAX2_HOTEL_APP_TEST_LIBRARY')&&!defined('HOTEL_ENTRY_FORECAST_TEST_LIBRARY')){
 set_time_limit(20);ini_set('display_errors','0');ini_set('log_errors','0');
 try{
  hef_need(PHP_SAPI==='cli','cli_required');hef_runtime_check();
  $pem=base64_decode(M2A_REPORT_PUBLIC_KEY_B64,true);
  hef_need(is_string($pem)&&strlen($pem)<=4096&&base64_encode($pem)===M2A_REPORT_PUBLIC_KEY_B64,'report_public_key_invalid');
  $reportKey=m2a_public_key($pem);
  ob_start();try{
   require getcwd().'/config.php';require_once getcwd().'/services/ConversationDb.php';
   require_once getcwd().'/services/ModeledConversionValueRegistry.php';
   require_once getcwd().'/services/ModeledConversionDealValue.php';
   require_once getcwd().'/services/ModeledConversionDeliveryRegistry.php';
  }finally{ob_end_clean();}
  $result=m2a_read(ConversationDb::connection(),time(),$reportKey);
  hef_runtime_check();
  $out=['ok'=>true,'installed_sha'=>HEF_RUNTIME,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0,...$result];
  $json=json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  hef_need(strlen($json)<=4194304,'output_byte_limit');echo $json,"\n";
 }catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>m2a_safe_error($e),'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0]),"\n";exit(1);}
}
