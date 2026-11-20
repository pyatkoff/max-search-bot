<?php
declare(strict_types=1);
/** Synthetic pure fixtures. No config bootstrap, PDO connection, provider call, or production capture. */
define('HOTEL_ENTRY_FORECAST_TEST_LIBRARY',true);
require __DIR__.'/program.php';
$root=$argv[1]??dirname(__DIR__,2);
foreach(HEF_SOURCE_PINS as$file=>$hash){
 if(!is_file($root.'/'.$file)||hash_file('sha256',$root.'/'.$file)!==$hash){fwrite(STDERR,"source_fixture_unavailable\n");exit(1);}
}
require_once $root.'/services/ModeledConversionValueRegistry.php';
require_once $root.'/services/ModeledConversionDealValue.php';
require_once $root.'/services/ModeledConversionDeliveryRegistry.php';
$checks=0;
function hcheck(bool $v,string $name):void {global $checks;$checks++;if(!$v)throw new RuntimeException('FAIL '.$name);}
function hreject(callable $fn,string $code,string $name):void {
 try{$fn();}catch(RuntimeException $e){hcheck($e->getMessage()===$code,$name);return;}hcheck(false,$name);
}
function hfixture(int $pid=1001,int $entryId=1,int $version=100,int $entryOffset=0,int $freezeOffset=300,int $anchorOffset=60,bool $office=false):array {
 $base=strtotime('2026-11-12T09:00:00Z')+$entryOffset;$frozen=$base+$freezeOffset;$anchorAt=$base+$anchorOffset;
 $subject=['project_key'=>'anytour','platform'=>'max','customer_key'=>'77700123456789','conversation_id'=>'99900123456789'];
 $when=gmdate('Y-m-d\TH:i:s\Z',$frozen);$anchorRef='journal:201';
 $model=new ModeledConversionSemanticPrediction($subject,$when,$when,$when,'1000','1000',null,true);
 $state=ArchiveResearchSemanticExtractor::initialState();$message='Подберите тур в Турцию на 7 ночей. Едем вдвоём.';
 $messages=[[$message,'201',$anchorAt]];if($office)$messages[]=['Где находится ваш офис?','202',$anchorAt+30];
 $evidence=[];$caseRows=[];
 foreach($messages as[$text,$jid,$at]){
  $ref='journal:'.$jid;$available=$at+1;
  $r=ArchiveResearchSemanticExtractor::step(['source_ref'=>$ref,'created_at'=>$at,'read_status'=>'available','text'=>$text,
   'role'=>'customer','direction'=>'inbound','role_verified'=>true,'text_minimized'=>true,'forwarded'=>false,
   'media'=>[],'media_contents_read'=>false,'selection_links_unread'=>false,'links_redacted'=>false],$state);
  $state=$r['state'];$model->observeMessage('customer',$text,$ref,$at,$available,1,$r);
  $atText=gmdate('Y-m-d\TH:i:s\Z',$at);$availableText=gmdate('Y-m-d\TH:i:s\Z',$available);
  $reference=['source'=>'message','id'=>$ref,'at'=>$atText,'available_at'=>$availableText];
  $evidence[]=['id'=>'message:'.$ref,'subject'=>$subject,'kind'=>'customer_message','at'=>$atText,'available_at'=>$availableText,'references'=>[$reference]];
  $caseRows[]=['journal_id'=>$jid,'source_kind'=>'message','source_id'=>$jid,'source_version_no'=>1,'observed_at_unix'=>$available,
   'snapshot'=>$subject+['source_kind'=>'message','source_id'=>$jid,'source_status'=>'present','payload_status'=>'copied',
    'source_created_at_unix'=>$at,'source_timestamp_basis'=>'canonical_recording_unix_v1',
    'payload'=>['text'=>$text,'direction'=>'inbound','sender_type'=>'customer','metadata_json'=>'{"type":"message"}']]];
 }
 $journalId=(string)(100+$entryId);
 $context=['version'=>'entry_price_context_v1','captured_at_unix'=>$base+1,'published_at_unix'=>$base-3600,
  'publication_id'=>'10','version_id'=>(string)$version,'content_sha256'=>str_repeat('a',64),
  'range'=>['low'=>200000,'high'=>200000,'point'=>200000,'unit'=>'party_total','party_size'=>2,'basis'=>'advertised_range_proxy'],'price_verified'=>false];
 $caseRows[]=['journal_id'=>$journalId,'source_kind'=>'event','source_id'=>(string)(900+$entryId),'source_version_no'=>1,'observed_at_unix'=>$base+1,
  'snapshot'=>$subject+['source_kind'=>'event','source_id'=>(string)(900+$entryId),'source_status'=>'present','payload_status'=>'copied',
   'source_created_at_unix'=>$base,'source_timestamp_basis'=>'database_session_timezone_to_unix',
   'payload'=>['event_type'=>'channel_post_entry','actor_type'=>'customer','_modeled_price_context'=>$context]]];
 $w=['subject'=>$subject,'access_status'=>'allowed','profile'=>['prediction_at'=>$when,
  'observation_input'=>['subject'=>$subject,'evidence'=>$evidence],'party'=>['total'=>['status'=>'known','value'=>2]]],
  'processing'=>['source_sha256'=>str_repeat('b',64),'journal_cursor'=>'1000','source_fence_journal_id'=>'1000']];
 $w['semantic_prediction']=$model->finish(['early_forecast'=>['status'=>'early_assessment']]);
 $w['deal_value_prediction']=ModeledConversionDealValue::freeze($w,['subject'=>$subject,'rows'=>$caseRows]);
 // The actual reader never copies observation_input; construct only its SQL projection here.
 $w['profile']=['prediction_at'=>$when];
 $entry=['entry_id'=>$entryId,'conversation_id'=>$subject['conversation_id'],'customer_key'=>$subject['customer_key'],
  'publication_id'=>10,'version_id'=>$version,'entered_at'=>gmdate('Y-m-d H:i:s',$base),'platform'=>'max','channel_id'=>'-80095447388179',
  'content_hash'=>str_repeat('a',64),'binding_valid'=>1,'owner_scope_valid'=>1];
 $meta=['id'=>(string)$pid,'platform'=>'max','customer_key'=>$subject['customer_key'],'conversation_id'=>$subject['conversation_id'],
  'journal_cursor'=>'1000','observed_at_unix'=>$frozen,'price_origin'=>$w['deal_value_prediction']['deal_amount_rub']['source_kind']];
 $journal=['id'=>$journalId,'project_key'=>'anytour','source_kind'=>'event','source_id'=>(string)(900+$entryId),'version_no'=>1,'observed_at_unix'=>$base+1,
  'packet'=>['subject'=>$subject,'source_kind'=>'event','source_id'=>(string)(900+$entryId),'source_status'=>'present','payload_status'=>'copied',
   'source_created_at_unix'=>$base,'source_timestamp_basis'=>'database_session_timezone_to_unix','event_type'=>'channel_post_entry','actor_type'=>'customer',
   'entry_id'=>$entryId,'publication_id'=>10,'version_id'=>$version,'price_context'=>$context]];
 return ['entry'=>$entry,'meta'=>$meta,'wrapper'=>$w,'journal'=>$journal,'base'=>$base,'frozen'=>$frozen];
}
function hrun(array $entries,array $meta,array $packets,array $journals,array $ledger=[],?array $latest=null):array {
 $out=hef_empty_aggregate(strtotime('2026-11-20T12:00:00Z'));
 $out['coverage']['global_entry_rows_scanned']=count($entries);$out['coverage']['global_window_entries']=count($entries);
 $cohort=hef_cohort($entries,$out);
 if($latest===null){$latest=[];foreach($cohort['scopes']as$key=>$scope)$latest[$key]=[['access_status'=>'allowed']];}
 $cohort=hef_current_access($cohort,$latest,$out);
 // The real SQL candidate queries run only for scopes still allowed by the latest-profile fence.
 $meta=array_values(array_filter($meta,static fn(array $r):bool=>isset($cohort['scopes'][hef_scope($r)])));
 $valid=hef_profiles($meta,$packets,$cohort,$out);$links=hef_link($valid,$journals,$cohort,$out);
 hef_finish($cohort,$links,$ledger,$out);return $out;
}
function hpacket(array $w):string {return json_encode($w,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function hledger(array $f,int $id,int $rub,string $state='pending'):array {
 $processed=$state==='processed';
 return ['id'=>(string)$id,'conversation_id'=>$f['meta']['conversation_id'],'platform'=>'max','value_profile_id'=>$f['meta']['id'],
  'target'=>'anytour_value_pred_'.$f['meta']['id'].'_v1','event_unix'=>$f['frozen'],'value_rub'=>(string)$rub.'.00','state'=>$state,
  'value_api_accepted_at_unix'=>$processed?$f['frozen']+100:null,'value_processed_at_unix'=>$processed?$f['frozen']+200:null,
  'price_action'=>'unchanged','original_value_rub'=>$rub,'applied_value_rub'=>$rub,'prior_reserved_rub'=>0];
}
try{
 $f=hfixture();$p=$f['wrapper']['semantic_prediction'];$m=$f['wrapper']['deal_value_prediction'];
 hcheck((ModeledConversionValueRegistry::predictionFields($f['wrapper'])['semantic_prediction']['availability']??null)==='available','real canonical semantic capsule accepted');
 hcheck(ModeledConversionDealValue::saved($f['wrapper'])!==null&&$m['deal_amount_rub']['source_kind']==='channel_post_range'
  &&$m['status']==='estimated'&&$p['current_request_anchor']==='journal:201','real synthetic frozen entry-price capsule');
 $out=hrun([$f['entry']],[$f['meta']],[$f['meta']['id']=>hpacket($f['wrapper'])],[$f['journal']['id']=>$f['journal']]);
 hcheck($out['coverage']['exact_linked_profiles']===1&&$out['coverage']['selected_request_anchors']===1,'exact immutable entry link');
 $row=$out['posts'][0]['forecasts'][0];
 hcheck($row['estimated_count']===1&&$row['probability_sum']===$m['estimated_probability']
  &&$row['frozen_value_rub_sum']===$m['value_rub']&&$row['base_rub_sum']===$m['full_value_base_rub'],'saved probability and rounded money used unchanged');
 hcheck($row['age_seconds_min']===240&&$row['age_bands']['first_hour']===1,'request age preserved separately from entry window');
 hcheck($out['coverage']['current_access_scopes_checked']===1&&$out['coverage']['current_access_allowed_scopes']===1
  &&$out['coverage']['admitted_scopes']===1,'latest exact subject allowed independently of historical packet');
 // A newer blocked/revoked status outside the historical window cannot fall back to this allowed capsule.
 $scope=hef_scope($f['meta']);
 foreach(['blocked','revoked']as$status){
  $closed=hrun([$f['entry']],[$f['meta']],[$f['meta']['id']=>hpacket($f['wrapper'])],[$f['journal']['id']=>$f['journal']],
   [hledger($f,800,17)],[$scope=>[['access_status'=>$status]]]);
  hcheck($closed['coverage']['current_access_blocked_scopes']===1&&$closed['coverage']['current_access_blocked_entries']===1
   &&$closed['coverage']['admitted_entries']===0&&$closed['coverage']['admitted_scopes']===0
   &&$closed['coverage']['entry_rows_read']===1&&$closed['posts'][0]['entries']===1&&$closed['posts'][0]['admitted_entries']===0,
   'current '.$status.' excludes admission but retains baseline entry');
  hcheck($closed['coverage']['profile_rows_read']===0&&$closed['coverage']['profile_packets_read']===0
   &&array_sum($closed['coverage']['profile_origin_counts'])===0&&$closed['coverage']['selected_request_anchors']===0
   &&$closed['ledger']['rows']===0,'current '.$status.' never reads or uses historical profiles or ledger');
 }
 foreach([[],[['access_status'=>null]],[['access_status'=>'unknown']],[['access_status'=>'ALLOWED']],
  [['access_status'=>'allowed ']],[['access_status'=>str_repeat('x',33)]],[['access_status'=>[]]]]as$latestRows){
  $unknown=hrun([$f['entry']],[$f['meta']],[$f['meta']['id']=>hpacket($f['wrapper'])],[],[],[$scope=>$latestRows]);
  hcheck($unknown['coverage']['current_access_unavailable_scopes']===1&&$unknown['coverage']['current_access_unavailable_entries']===1
   &&$unknown['coverage']['current_access_allowed_scopes']===0&&$unknown['coverage']['profile_rows_read']===0,
   'absent invalid or unknown latest status fails closed');
 }
 $missing=hrun([$f['entry']],[$f['meta']],[$f['meta']['id']=>hpacket($f['wrapper'])],[],[],[]);
 hcheck($missing['coverage']['current_access_unavailable_scopes']===1,'missing exact subject lookup never borrows another scope');
 $secondEntry=$f['entry'];$secondEntry['entry_id']=2;$secondEntry['conversation_id']='99900123456790';
 $isolated=hrun([$f['entry'],$secondEntry],[$f['meta']],[$f['meta']['id']=>hpacket($f['wrapper'])],[$f['journal']['id']=>$f['journal']],[],
  [$scope=>[['access_status'=>'allowed']],hef_scope($secondEntry)=>[['access_status'=>'blocked']]]);
 hcheck($isolated['coverage']['current_access_scopes_checked']===2&&$isolated['coverage']['current_access_allowed_scopes']===1
  &&$isolated['coverage']['current_access_blocked_scopes']===1&&$isolated['coverage']['admitted_entries']===1
  &&$isolated['coverage']['selected_request_anchors']===1&&$isolated['posts'][0]['entries']===2,'current access isolated by exact conversation subject');
 $repeatedEntry=$f['entry'];$repeatedEntry['entry_id']=3;
 $closed=hrun([$f['entry'],$repeatedEntry],[],[],[],[],[$scope=>[['access_status'=>'revoked']]]);
 hcheck($closed['coverage']['current_access_scopes_checked']===1&&$closed['coverage']['current_access_blocked_entries']===2
  &&$closed['posts'][0]['entries']===2,'one access lookup excludes every repeated entry in that scope');
 $accessSql=hef_current_access_sql();
 hcheck(str_contains($accessSql,'OCTET_LENGTH(p.profile_json)<=131072')&&str_contains($accessSql,'JSON_VALID(p.profile_json)')
  &&str_contains($accessSql,'OCTET_LENGTH(')&&str_contains($accessSql,'<=32')
  &&str_contains($accessSql,'ORDER BY p.id DESC LIMIT 1')&&str_contains($accessSql,'FORCE INDEX (ix_profile_scope_time)')
  &&str_contains($accessSql,'JOIN customers cu')&&!str_contains($accessSql,'observed_at_unix'),
  'latest access scalar is bounded indexed and not limited to historical followup');
 hcheck(str_contains($accessSql,'p.project_key=? AND p.platform=? AND p.customer_key=? AND p.conversation_id=?')
  &&str_contains($accessSql,'HEX(CAST(c.id AS CHAR))=HEX(p.conversation_id)')
  &&str_contains($accessSql,'HEX(CAST(c.customer_id AS CHAR))=HEX(p.customer_key)')
  &&str_contains($accessSql,'c.is_test=0 AND NOT ('),'latest access repeats installed exact native subject fence');
 $accessOut=hef_empty_aggregate(time());$accessCohort=hef_cohort([$f['entry']],$accessOut);
 hreject(fn()=>hef_current_access($accessCohort,[$scope=>[['access_status'=>'allowed'],['access_status'=>'blocked']]],$accessOut),
  'current_access_binding','ambiguous latest lookup cannot choose an older allowed status');
 hcheck(hef_semantics()['current_access']==='latest_exact_subject_profile_access_status_allowed_at_read_time'
  &&hef_semantics()['source_clock_basis']==='saved_database_recording_evidence_not_native_action_time','access and saved clock semantics explicit');
 // Later exact snapshots of the same anchor do not add another forecast. Actual ledger deltas remain separate.
 $later=hfixture(1002,1,100,0,600,60,true);
 $a=hledger($f,1,$m['value_rub']);$b=hledger($later,2,17,'processed');$b['price_action']='rebased';$b['original_value_rub']=99;$b['prior_reserved_rub']=$m['value_rub'];
 $twice=hrun([$f['entry']],[$later['meta'],$f['meta']],
  [$f['meta']['id']=>hpacket($f['wrapper']),$later['meta']['id']=>hpacket($later['wrapper'])],[$f['journal']['id']=>$f['journal']],[$a,$b]);
 hcheck($twice['coverage']['exact_linked_profiles']===2&&$twice['coverage']['selected_request_anchors']===1
  &&$twice['coverage']['duplicate_profiles_same_anchor']===1,'same anchor deduplicated independently of input order');
 hcheck($twice['posts'][0]['forecasts'][0]['frozen_value_rub_sum']===$m['value_rub']
  &&$twice['ledger']['value_rub_sum']===$m['value_rub']+17,'ledger is actual deltas and not sum of frozen forecasts');
 hcheck($twice['ledger']['rows']===2&&$twice['ledger']['api_accepted_rows']===1&&$twice['ledger']['processed_rows']===1
  &&$twice['ledger']['price_audit']['counts']['rebased']===1,'recorded lifecycle and price audit retained');
 // A preserved re-entry may reference the same anchor; it cannot become a second attributed request.
 $other=hfixture(1003,2,101,10,390,50,false);
 $ambiguous=hrun([$f['entry'],$other['entry']],[$f['meta'],$other['meta']],
  [$f['meta']['id']=>hpacket($f['wrapper']),$other['meta']['id']=>hpacket($other['wrapper'])],
  [$f['journal']['id']=>$f['journal'],$other['journal']['id']=>$other['journal']],[$a,hledger($other,3,9)]);
 hcheck($ambiguous['coverage']['exact_linked_profiles']===2&&$ambiguous['coverage']['ambiguous_request_anchors']===1
  &&$ambiguous['coverage']['selected_request_anchors']===0&&$ambiguous['ledger']['rows']===0,'multiple exact entries for one anchor held as ambiguous');
 $wrong=$f['journal'];$wrong['packet']['version_id']=101;
 $bad=hrun([$f['entry']],[$f['meta']],[$f['meta']['id']=>hpacket($f['wrapper'])],[$wrong['id']=>$wrong]);
 hcheck($bad['coverage']['profiles_not_exact_entry']===1&&$bad['posts'][0]['forecasts']===[],'another immutable publication version cannot match');
 $wrong=$f['journal'];$wrong['packet']['subject']['customer_key']='123';
 $bad=hrun([$f['entry']],[$f['meta']],[$f['meta']['id']=>hpacket($f['wrapper'])],[$wrong['id']=>$wrong]);
 hcheck($bad['coverage']['profiles_not_exact_entry']===1,'journal subject isolation');
 $wrong=$f['journal'];$wrong['packet']['source_created_at_unix']=null;
 $bad=hrun([$f['entry']],[$f['meta']],[$f['meta']['id']=>hpacket($f['wrapper'])],[$wrong['id']=>$wrong]);
 hcheck($bad['coverage']['profiles_unknown_clock']===1,'unknown clock stays unknown');
 $wrong=$f['journal'];unset($wrong['packet']['price_context']);
 $bad=hrun([$f['entry']],[$f['meta']],[$f['meta']['id']=>hpacket($f['wrapper'])],[$wrong['id']=>$wrong]);
 hcheck($bad['coverage']['profiles_missing_price_context']===1,'missing frozen price context is not reconstructed');
 $wrong=$f['journal'];$wrong['packet']['price_context']['range']['unit']='per_person';
 $bad=hrun([$f['entry']],[$f['meta']],[$f['meta']['id']=>hpacket($f['wrapper'])],[$wrong['id']=>$wrong]);
 hcheck($bad['coverage']['profiles_missing_price_context']===1,'per person context cannot be borrowed as total deal');
 $damaged=$f['wrapper'];$damaged['deal_value_prediction']['value_rub']++;
 $bad=hrun([$f['entry']],[$f['meta']],[$f['meta']['id']=>hpacket($damaged)],[$f['journal']['id']=>$f['journal']]);
 hcheck($bad['coverage']['invalid_profile_packets']===1&&$bad['coverage']['exact_linked_profiles']===0,'tampered frozen money digest rejected');
 $bad=hrun([$f['entry']],[$f['meta']],[$f['meta']['id']=>str_repeat('x',32769)],[]);
 hcheck($bad['coverage']['unavailable_profile_packets']===1,'oversized projected packet held');
 $otherOrigin=$f['meta'];$otherOrigin['price_origin']='customer_budget';
 $bad=hrun([$f['entry']],[$otherOrigin],[],[]);
 hcheck($bad['coverage']['profile_origin_counts']['customer_budget']===1&&$bad['coverage']['profile_packets_read']===0
  &&$bad['posts'][0]['forecasts']===[],'same conversation budget is not attributed to hotel');
 $none=hrun([$f['entry']],[],[],[]);
 hcheck($none['coverage']['scopes_without_profile_in_followup']===1&&$none['posts'][0]['forecasts']===[],'no profile is not zero probability');
 // The same sealed arithmetic is kept under its historical monetary policy.
 $legacy=$f['wrapper'];$legacy['deal_value_prediction']['version']='early_deal_commission_7pct_v2';
 $legacy['deal_value_prediction']['rules_sha256']='8a35e248d8237b9042d6aa94eca975c00aae8ae2dc6b3ef42c61b50ee4273669';
 unset($legacy['deal_value_prediction']['deal_digest']);
 $legacy['deal_value_prediction']['deal_digest']=ModeledConversionSemanticPrediction::digest($legacy['deal_value_prediction']);
 $old=hrun([$f['entry']],[$f['meta']],[$f['meta']['id']=>hpacket($legacy)],[$f['journal']['id']=>$f['journal']]);
 hcheck($old['posts'][0]['forecasts'][0]['monetary_version']==='early_deal_commission_7pct_v2'
  &&$old['posts'][0]['forecasts'][0]['frozen_value_rub_sum']===$m['value_rub'],'historical V2 not relabelled or repriced as V3');
 // Entry clock, not the latest conversation state, fixes the 24-hour limit.
 $late=hfixture(1004,1,100,0,90000,60);$nextEntry=$f['entry'];$nextEntry['entry_id']=2;$nextEntry['entered_at']='2026-11-13 09:00:00';
 $outside=hrun([$f['entry'],$nextEntry],[$late['meta']],[$late['meta']['id']=>hpacket($late['wrapper'])],[$late['journal']['id']=>$late['journal']]);
 hcheck($outside['coverage']['profiles_outside_entry_24h']===1&&$outside['coverage']['selected_request_anchors']===0,'forecast outside exact entry followup excluded');
 // Full baseline count reconciles even when publication/scope evidence is unavailable.
 $entries=[];for($i=1;$i<=710;$i++){$e=$f['entry'];$e['entry_id']=$i;$entries[]=$e;}
 $entries[0]['publication_id']=null;$entries[1]['binding_valid']=0;$entries[2]['owner_scope_valid']=0;
 $all=hrun($entries,[],[],[]);
 hcheck($all['coverage']['entry_rows_read']===710&&$all['coverage']['unavailable_publication_entries']===1
  &&$all['coverage']['invalid_binding_entries']===1&&$all['coverage']['owner_scope_excluded_entries']===1
  &&$all['coverage']['admitted_entries']===707&&array_sum(array_column($all['posts'],'entries'))===708,'710 denominator retained with separate exclusion accounting');
 $serialized=json_encode($twice,JSON_THROW_ON_ERROR);
 foreach(['"conversation_id"','"customer_key"','"profile_id"','"value_profile_id"','"entry_id"','"source_ref"','77700123456789','99900123456789']as$private)
  hcheck(!str_contains($serialized,$private),'private binding absent from egress');
 hcheck(!str_contains(hef_packet_sql("'{}'"),'observation_input')&&!str_contains(hef_journal_packet_sql(),'payload.text')
  &&!str_contains(hef_journal_packet_sql(),'post_text'),'SQL projects no customer/source texts');
 $empty=hef_empty_aggregate(time());
 hreject(fn()=>hef_cohort(array_fill(0,10001,$f['entry']),$empty),'entry_limit_incomplete','entry cap fails closed');
 hreject(fn()=>hef_profiles(array_fill(0,10001,$f['meta']),[],['scopes'=>[]],$empty),'profile_limit_incomplete','profile cap fails closed');
 hreject(fn()=>hrun([$f['entry']],[$f['meta']],[$f['meta']['id']=>hpacket($f['wrapper'])],[$f['journal']['id']=>$f['journal']],[$a,$a]),'ledger_duplicate','duplicate ledger row never counted twice');
 hef_plan([['table'=>'pe','type'=>'range','key'=>'PRIMARY','rows'=>100,'Extra'=>'']],true);
 hcheck(true,'bounded primary entry plan');
 hreject(fn()=>hef_plan([['table'=>'pe','type'=>'ALL','key'=>null,'rows'=>100,'Extra'=>'']],true),'unbounded_base_access','unbounded entry plan rejected');
 hreject(fn()=>hef_plan([['table'=>'p','type'=>'ALL','key'=>null,'rows'=>100,'Extra'=>'']],false),'explain_full_scan','unbounded profile plan rejected');
 hcheck(hef_safe_error(new RuntimeException('PRIVATE_SQL_BODY'))==='bounded_read_stopped','private error sanitized');
 echo 'PASS '.$checks." pure assertions; database/network/config not opened\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}

