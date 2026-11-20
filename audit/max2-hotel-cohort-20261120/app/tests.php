<?php
declare(strict_types=1);
/** Synthetic pure fixtures. No config bootstrap, PDO connection, provider call, or production capture. */
define('MAX2_HOTEL_APP_TEST_LIBRARY',true);
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
/** Reuse accepted pure admission; only the new private projection is added. */
function mfixture(int $pid=1001,int $entryId=1,int $version=100,int $entryOffset=0,int $freezeOffset=300,int $anchorOffset=60,bool $office=false):array {
 $f=hfixture($pid,$entryId,$version,$entryOffset,$freezeOffset,$anchorOffset,$office);
 $f['entry']['verified_native_actor']='234567890123456789';return $f;
}
function mledger(array $f,int $id,int $rub,string $state='pending'):array {
 return hledger($f,$id,$rub,$state)+['verified_native_actor'=>$f['entry']['verified_native_actor'],
  'counter_id'=>'98615635','identity_source_key'=>'max_tracker','client_id'=>'17632048761234567890',
  'tracker_record_id'=>'901','tracker_observed_unix'=>$f['base']];
}
function mrun(OpenSSLAsymmetricKey $key,array $entries,array $meta,array $packets,array $journals,array $ledger=[],?array $latest=null):array {
 $out=hef_empty_aggregate(strtotime('2026-11-20T12:00:00Z'));
 $out['coverage']['global_entry_rows_scanned']=count($entries);$out['coverage']['global_window_entries']=count($entries);
 $cohort=hef_cohort($entries,$out);
 if($latest===null){$latest=[];foreach($cohort['scopes']as$k=>$scope)$latest[$k]=[['access_status'=>'allowed']];}
 $cohort=hef_current_access($cohort,$latest,$out);
 $meta=array_values(array_filter($meta,static fn(array $r):bool=>isset($cohort['scopes'][hef_scope($r)])));
 $valid=hef_profiles($meta,$packets,$cohort,$out);$links=hef_link($valid,$journals,$cohort,$out);
 hef_finish($cohort,$links,$ledger,$out);
 return ['aggregate'=>$out,'bridge'=>m2a_bridge($cohort,$links,$ledger,$out,$key)];
}
function mdecode(?string $capsule,OpenSSLAsymmetricKey $private):array {
 hcheck(is_string($capsule),'cipher capsule is a string');$raw=base64_decode($capsule,true);
 hcheck(is_string($raw)&&strlen($raw)===384&&base64_encode($raw)===$capsule,'exact canonical RSA3072 ciphertext');
 $plain='';hcheck(openssl_private_decrypt($raw,$plain,$private,OPENSSL_PKCS1_OAEP_PADDING),'fixture OAEP-SHA1 decryption');
 hcheck(strlen($plain)<=342,'capsule plaintext bound');return json_decode($plain,true,8,JSON_THROW_ON_ERROR);
}
function mno_private_fields(mixed $value):bool {
 if(!is_array($value))return true;
 $forbidden=['user_decimal','cid_decimal','yclid_if_relevant','verified_native_actor','customer_key','conversation_id',
  'profile_json','snapshot_json','client_id','external_user_id','external_chat_id','customer_id','post_text','text',
  'initData','raw_init_data','password','private_key'];
 foreach($value as$key=>$child)if(in_array($key,$forbidden,true)||!mno_private_fields($child))return false;
 return true;
}
try{
 // An ephemeral fixture key stays in test memory. It is unrelated to the report key.
 $private=openssl_pkey_new(['private_key_bits'=>3072,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
 hcheck($private instanceof OpenSSLAsymmetricKey,'fixture RSA key created');$details=openssl_pkey_get_details($private);
 $key=m2a_public_key($details['key']);$f=mfixture();$money=$f['wrapper']['deal_value_prediction'];
 $ledger=mledger($f,800,$money['value_rub'],'processed');
 $run=static fn(array $rows):array=>mrun($key,[$f['entry']],[$f['meta']],[$f['meta']['id']=>hpacket($f['wrapper'])],[$f['journal']['id']=>$f['journal']],$rows);
 $result=$run([$ledger]);$bridge=$result['bridge'];$c=$bridge['coverage'];
 hcheck($c['current_allowed_max_entries']===1&&$c['nonambiguous_max_profiles']===1&&$c['selected_earliest_max_profiles']===1,'exact admitted entry profile selection');
 hcheck($c['max_ledger_rows']===1&&$c['max_ledger_value_rub_sum']===$money['value_rub']&&$c['ledger_identity_present']===1,'actual unique saved ledger money');
 $e=$bridge['entries'][0];$p=$bridge['profiles'][0];$l=$bridge['ledger_rows'][0];
 hcheck($p['entry_ref']===$e['entry_ref']&&$l['entry_ref']===$e['entry_ref']&&$l['value_profile_ref']===$p['value_profile_ref']
  &&$l['anchor_ref']===$p['anchor_ref'],'domain separated refs retain exact joins');
 hcheck($p['saved_value_rub']===$money['value_rub']&&$p['saved_base_rub']===$money['full_value_base_rub']
  &&$p['estimated_probability']===$money['estimated_probability']&&$p['deal_digest']===$money['deal_digest']
  &&$p['price']['point']===$money['deal_amount_rub']['point']&&$p['price']['exact_frozen_entry_price_link']===true,'sealed immutable numeric fields preserved');
 hcheck($p['monetary_rules_sha256']===$money['rules_sha256']&&$p['monetary_version']===$money['version']
  &&$p['model_sha256']===$f['wrapper']['semantic_prediction']['model_sha256']&&$p['age_seconds']===240,'saved model and request age preserved');
 $entryTuple=mdecode($e['identity_capsule_b64'],$private);$ledgerTuple=mdecode($l['identity_capsule_b64'],$private);
 $expected=['domain'=>'max2_hotel_identity_v1','counter'=>'98615635','user_decimal'=>$f['entry']['verified_native_actor'],
  'cid_decimal'=>null,'yclid_if_relevant'=>null];
 hcheck($entryTuple===$expected&&$e['basis']==='native_actor_only'&&$e['identity_state']==='not_requested','allowed entry has no inferred CID');
 $expected['cid_decimal']=$ledger['client_id'];
 hcheck($ledgerTuple===$expected&&$l['basis']==='native_actor_and_client'&&$l['identity_state']==='present','capsule uses saved outbox identity');
 hcheck($l['tracker_record_id']==='901'&&$l['tracker_observed_unix']===$f['base']&&$l['tracker_clock_state']==='at_or_before_signal'
  &&$l['api_accepted_at_unix']===$f['frozen']+100&&$l['processed_at_unix']===$f['frozen']+200,'locator clock and actual lifecycle kept distinct');
 $encoded=hpacket($result);
 foreach([$f['entry']['verified_native_actor'],$ledger['client_id'],$f['entry']['customer_key'],$f['entry']['conversation_id'],
  'Подберите тур']as$secret)
  hcheck(!str_contains($encoded,$secret),'no plaintext private identity or text');
 hcheck(mno_private_fields($result),'no private JSON field at any nesting level');
 hcheck(mno_private_fields(['semantics'=>['full_profile_json_read'=>false]]),'safe aggregate flag is not a raw profile field');
 foreach(['profile_json','snapshot_json','user_decimal','cid_decimal','yclid_if_relevant','verified_native_actor',
  'customer_key','conversation_id','client_id','external_user_id','post_text','text']as$field)
  hcheck(!mno_private_fields(['outer'=>[['nested'=>[$field=>'synthetic-private-value']]]]),'nested private field remains rejected');
 hcheck($bridge['semantics']['identity_agreement_is_visit_or_causal_attribution']===false
  &&$bridge['semantics']['profile_values_and_ledger_rows_additive']===false,'noncausal and nonadditive interpretation');

 // Unknown identity is retained as unknown; it never becomes an empty-string or zero CID.
 foreach([[null,'missing'],['','missing'],['000123','invalid'],['12.34','invalid'],[str_repeat('7',41),'invalid']]as[$cid,$state]){
  $r=$ledger;$r['client_id']=$cid;$b=$run([$r])['bridge'];$row=$b['ledger_rows'][0];$t=mdecode($row['identity_capsule_b64'],$private);
  hcheck($row['identity_state']===$state&&$row['basis']==='native_actor_only'&&$t['cid_decimal']===null
   &&$b['coverage']['ledger_identity_'.$state]===1&&$b['coverage']['max_ledger_rows']===1,'missing or noncanonical CID never drops saved value');
 }
 $r=$ledger;$r['client_id']=str_repeat('7',40);$row=$run([$r])['bridge']['ledger_rows'][0];
 hcheck(mdecode($row['identity_capsule_b64'],$private)['cid_decimal']===$r['client_id'],'lookup canonical 40-digit CID is exact');
 $r=$ledger;$r['identity_source_key']='other';$b=$run([$r])['bridge'];
 hcheck($b['coverage']['identity_source_mismatch_rows']===1&&$b['ledger_rows'][0]['identity_capsule_b64']===null
  &&$b['ledger_rows'][0]['tracker_record_id']===null&&$b['coverage']['max_ledger_rows']===1,'another identity source cannot supply a tracker binding');
 $r=$ledger;$r['verified_native_actor']='234567890123456780';
 hreject(fn()=>$run([$r]),'identity_scope_mismatch','different saved outbox actor fails closed');
 $r=$ledger;unset($r['verified_native_actor']);
 hreject(fn()=>$run([$r]),'identity_scope_mismatch','absent outbox actor cannot borrow entry actor');
 $invalidEntry=$f['entry'];$invalidEntry['verified_native_actor']='not_decimal';
 $b=mrun($key,[$invalidEntry],[],[],[])['bridge'];
 hcheck($b['coverage']['entries_invalid_native_actor']===1&&$b['entries'][0]['identity_capsule_b64']===null,'invalid native actor explicitly unavailable');

 // Current access closes the whole historical scope before any identifier capsule is projected.
 $scope=hef_scope($f['meta']);
 foreach(['blocked','revoked',null,'unknown']as$status){
  $closed=mrun($key,[$f['entry']],[$f['meta']],[$f['meta']['id']=>hpacket($f['wrapper'])],[$f['journal']['id']=>$f['journal']],[$ledger],[$scope=>[['access_status'=>$status]]]);
  hcheck($closed['bridge']['entries']===[]&&$closed['bridge']['profiles']===[]&&$closed['bridge']['ledger_rows']===[]
   &&$closed['aggregate']['coverage']['entry_rows_read']===1&&$closed['aggregate']['coverage']['admitted_entries']===0,'latest access excludes all private projections while retaining denominator');
 }

 // Repeated frozen versions do not create another selected request or duplicate actual ledger rows.
 $later=mfixture(1002,1,100,0,600,60,true);$a=mledger($f,801,$money['value_rub']);$b=mledger($later,802,17,'processed');
 $twice=mrun($key,[$f['entry']],[$later['meta'],$f['meta']],[$f['meta']['id']=>hpacket($f['wrapper']),$later['meta']['id']=>hpacket($later['wrapper'])],
  [$f['journal']['id']=>$f['journal']],[$a,$b]);$t=$twice['bridge'];
 hcheck(count($t['profiles'])===2&&array_sum(array_column($t['profiles'],'selected_earliest'))===1&&count($t['ledger_rows'])===2
  &&$t['coverage']['max_ledger_value_rub_sum']===$money['value_rub']+17,'same anchor profiles and actual ledger rows remain separate');
 hcheck($t['ledger_rows'][0]['outbox_ref']!==$t['ledger_rows'][1]['outbox_ref']&&$t['ledger_rows'][0]['tracker_record_id']===$t['ledger_rows'][1]['tracker_record_id']
  &&$twice['aggregate']['coverage']['selected_request_anchors']===1,'repeated tracker locator is not a distinct visit or request');
 hreject(fn()=>$run([$ledger,$ledger]),'ledger_duplicate','duplicate outbox ID rejected instead of double-counted');
 $other=mfixture(1003,2,101,10,390,50,false);
 $amb=mrun($key,[$f['entry'],$other['entry']],[$f['meta'],$other['meta']],[$f['meta']['id']=>hpacket($f['wrapper']),$other['meta']['id']=>hpacket($other['wrapper'])],
  [$f['journal']['id']=>$f['journal'],$other['journal']['id']=>$other['journal']],[$a,mledger($other,803,9)]);
 hcheck(count($amb['bridge']['entries'])===2&&$amb['bridge']['profiles']===[]&&$amb['bridge']['ledger_rows']===[]
  &&$amb['bridge']['coverage']['ambiguous_max_profiles_excluded']===2,'two entry versions of one anchor are held without Cartesian money');
 $wrong=$f['journal'];$wrong['packet']['version_id']=101;
 $unlinked=mrun($key,[$f['entry']],[$f['meta']],[$f['meta']['id']=>hpacket($f['wrapper'])],[$wrong['id']=>$wrong],[$ledger]);
 hcheck(count($unlinked['bridge']['entries'])===1&&$unlinked['bridge']['profiles']===[]&&$unlinked['bridge']['ledger_rows']===[],'another pinned version never receives frozen value');

 // Tracker time remains diagnostic evidence; no timestamp repairs or inferred visit clock.
 $r=$ledger;$r['tracker_observed_unix']=$f['frozen']+1;$b=$run([$r])['bridge'];
 hcheck($b['coverage']['tracker_clock_after_signal']===1&&$b['ledger_rows'][0]['tracker_clock_state']==='after_signal'
  &&$b['coverage']['max_ledger_rows']===1,'after-signal recorded tracker clock is explicit');
 foreach([null,'unknown',0,strtotime('2026-11-21T00:00:00Z')]as$clock){$r=$ledger;$r['tracker_observed_unix']=$clock;$b=$run([$r])['bridge'];
  hcheck($b['ledger_rows'][0]['tracker_observed_unix']===null&&$b['coverage']['tracker_clock_unknown']===1,'unknown or future tracker clock is not zero');}
 $r=$ledger;$r['tracker_record_id']='000901';$b=$run([$r])['bridge'];
 hcheck($b['ledger_rows'][0]['tracker_record_id']===null&&$b['coverage']['tracker_record_missing_or_invalid']===1,'noncanonical locator never normalizes to an existing record');

 // Crypto capacity, exact policy and source reuse are fail-closed acceptance gates.
 $wrongKey=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$wrongDetails=openssl_pkey_get_details($wrongKey);
 hreject(fn()=>m2a_public_key($wrongDetails['key']),'report_public_key_invalid','wrong RSA size rejected');
 hreject(fn()=>m2a_capsule(['oversize'=>str_repeat('x',343)],$key),'identity_capsule_size','oversize tuple not truncated or emitted raw');
 $source=file_get_contents(__DIR__.'/program.php');$start=strpos($source,"// BEGIN ACCEPTED PURE SELECTION LIBRARY\n")+strlen("// BEGIN ACCEPTED PURE SELECTION LIBRARY\n");
 $end=strpos($source,"// END ACCEPTED PURE SELECTION LIBRARY\n");
 hcheck(hash('sha256',substr($source,$start,$end-$start))===M2A_SELECTION_LIBRARY_SHA256,'accepted pure selection copied byte-for-byte');
 hcheck(substr_count($source,'__M2A_REPORT_PUBLIC_KEY_B64__')===1&&!function_exists('hef_read'),'one public marker and no completed reader entrypoint');
 hcheck(str_contains($source,'CAST(o.counter_id AS CHAR) AS counter_id')&&str_contains($source,'SET TRANSACTION READ ONLY')
  &&str_contains($source,'$pdo->rollBack();return $result;')&&!str_contains($source,'$pdo->commit()'),'readonly orchestration and counter string projection');
 hcheck(m2a_safe_error(new RuntimeException('PRIVATE_SQL_OR_IDENTITY'))==='bounded_read_stopped','private exceptions sanitized');
 require_once $root.'/services/MaxTrackerLookupProtocol.php';
 foreach(['1',str_repeat('9',40),str_repeat('9',41),'0','01','1 ','1.0']as$cid)
  hcheck((m2a_decimal($cid,40)!==null)===MaxTrackerLookupProtocol::id($cid,40),'CID validator follows installed lookup contract');
 echo 'PASS '.$checks." pure assertions; database/network/config not opened\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
