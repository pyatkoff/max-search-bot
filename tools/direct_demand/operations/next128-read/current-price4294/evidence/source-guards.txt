<?php
declare(strict_types=1);
require_once __DIR__.'/primitives.php';
const HI_OPERATION='price1619-compatible-hook-20261118-1';
const HI_RECOVERED='commercial-monitor-price-h4294-f72863a3310b60862a907f040008fd46c82e7e044485488e9eece8adb1237e24';
const HI_OLD='d605b8292a8d380d328d585f90d388a254d1dc780188e4b0edbfc92b6c407c30';
const HI_NEW='605f8e628f5ad8bb07ab7e12f0e8a33c51a773defad43711d46908db0cb1caa4';
const HI_CONSUMER_DB_SHA='ca7f11d6ec53e0cd3d4464e0f645f15b761bb734da1323689479838868d4abc4';
function hi_sql_owner_idle(PDO $db):void {
 try {$q=$db->query("SELECT IS_USED_LOCK('anytour_top500_monthly_v1')");must($q instanceof PDOStatement,'consumer_sql_unknown');$owner=$q->fetchColumn();}
 catch(Throwable $e){throw new RuntimeException('consumer_sql_unknown');}
 must($owner===null,'consumer_sql_lock_busy');
}
function hi_sql_bootstrap_snapshot():array {
 $p=__DIR__.'/subprocess.php';
 must(!is_link($p)&&is_file($p)&&hash_file('sha256',$p)==='52a0001732c7ecf9142060b3e13bde6d9631d44f417a28945f27105801296884','isolated_sql_source_changed');
 require_once $p;
 return isolated_consumer_env_snapshot();
}
function hi_consumer_sql_idle():array {
 global $hiOriginalConsumerEnv;
 must(is_array($hiOriginalConsumerEnv),'isolated_sql_snapshot_missing');
 return isolated_consumer_sql_probe(__DIR__.'/probe-worker.php','99744059eb16cd3560bf301015a26f3ea1f34346dd9180477ffccccf24a19782',$hiOriginalConsumerEnv);
}
function hi_constant(string $source,string $name):array {
 must(preg_match('/const '.preg_quote($name,'/').'="([A-Za-z0-9+\/=]+)";/',$source,$m)===1,'bundle_constant_missing');return [$m[0],base64_decode($m[1],true)];
}
function hi_repin(string $old,string $new):void {
 must(hash('sha256',$old)===HI_OLD&&hash('sha256',$new)===HI_NEW&&strlen($new)===779768,'exact_bundle_changed');
 [$oldLine,$oldPins]=hi_constant($old,'HOOK_RUNTIME_PINS_B64');[$newLine,$newPins]=hi_constant($new,'HOOK_RUNTIME_PINS_B64');
 $a=json_decode($oldPins,true,32,JSON_THROW_ON_ERROR);$b=json_decode($newPins,true,32,JSON_THROW_ON_ERROR);
 $changes=['services/ChannelPostRegistryService.php'=>['94fb1988ac8caf90dc5103aa23c58e7aad6ea691','cf42a44257d1a58d1ff516678615cf9d01f45b55'],'services/ChannelPostReconciliationService.php'=>['43133f576845c14446102dedfffb7f0de2d4ba1c','1fa260e9720a8421227ecbe740bfbe33a42e398d']];
 must(count($a)===23&&count($b)===23,'pin_count_changed');
 foreach($changes as $path=>[$before,$after]){must(($a[$path]??null)===$before&&($b[$path]??null)===$after,'two_pin_transition_changed');$b[$path]=$before;}must($a===$b,'other_runtime_pins_changed');
 $inverse=str_replace($newLine,$oldLine,$new,$n);must($n===1,'pin_constant_ambiguous');
 [$oldEditorLine,$oldEditor]=hi_constant($old,'HOOK_EDITOR_B64');[$newEditorLine,$newEditor]=hi_constant($new,'HOOK_EDITOR_B64');
 foreach($changes as [$before,$after]){$newEditor=str_replace($after,$before,$newEditor,$n);must($n===1,'embedded_pin_ambiguous');}
 must($newEditor===$oldEditor,'editor_behavior_changed');$inverse=str_replace($newEditorLine,$oldEditorLine,$inverse,$n);
 must($n===1&&$inverse===$old,'non_pin_bundle_changed');
}
function hi_pin($v):array {
 must(is_array($v),'pin_shape');$keys=array_keys($v);sort($keys,SORT_STRING);
 must($keys===['bytes','lines','sha256']&&is_string($v['sha256'])&&preg_match('/\A[a-f0-9]{64}\z/D',$v['sha256'])===1
  &&is_int($v['bytes'])&&$v['bytes']>=0&&is_int($v['lines'])&&$v['lines']>=0,'pin_shape');
 return ['sha256'=>$v['sha256'],'bytes'=>$v['bytes'],'lines'=>$v['lines']];
}
function hi_pin_equal($a,$b):bool {return hi_pin($a)===hi_pin($b);}
function hi_feed_map($v,bool $counts=false):array {
 must(is_array($v),'feed_scope_invalid');$keys=array_keys($v);sort($keys,SORT_STRING);must($keys===['all','max','telegram'],'feed_scope_invalid');$out=[];
 foreach(['telegram','max','all'] as $k){must($counts?(is_int($v[$k])&&$v[$k]>0):(is_string($v[$k])&&preg_match('/\A[a-f0-9]{64}\z/D',$v[$k])===1),'feed_value_invalid');$out[$k]=$v[$k];}return $out;
}
function hi_sync_dir(string $path):void {$f=fopen($path,'r');must(is_resource($f)&&fsync($f),'directory_sync_failed');fclose($f);}
function hi_deadline(array $admission,array $inspect,int $now,?int $baseline=null):void {
 foreach([$admission['observed_at_epoch']??null,$inspect['observed_at_epoch']??null,$admission['producer_proof']['observed_at_epoch']??null,$admission['route_observed_at_epoch']??null] as $at)
  must(is_int($at)&&$at<=$now&&$now-$at<=300,'installation_admission_expired');
 if($baseline!==null)must($baseline<=$now&&$now-$baseline<=300,'baseline_expired');
}
function hi_inspect(array $wrapper,array $c,array $ids):void {
 must(($wrapper['candidate_hook_sha256']??null)===HI_NEW&&($wrapper['returncode']??null)===0&&is_string($wrapper['native_result_raw']??null),'inspect_identity_changed');
 $r=json_decode($wrapper['native_result_raw'],true,64,JSON_THROW_ON_ERROR);
 must(hash('sha256',$wrapper['native_result_raw'])===($wrapper['native_result_sha256']??null)&&($r['ok']??null)===true
  &&($r['mode']??null)==='read_only_hook_inspection'&&($r['installed_sha']??null)===$c['runtime_sha']
  &&hi_pin_equal($r['journal_read']??null,$c['journal_pin'])&&hi_feed_map($wrapper['feed_shas']??null)===hi_feed_map($c['feed_shas'])
  &&($r['roster_pairs']??null)===1000&&($r['scanned_pairs']??null)===1000,'inspect_scope_changed');
 $got=$r['verified_ids']??[];sort($got);sort($ids);must(count($got)===1000&&$got===$ids,'inspect_roster_changed');
 foreach(['new_posts','supplier_calls','site_writes','updated_pairs','held_pairs'] as $k)must(($r[$k]??null)===0,'inspection_not_read_only');
 must(price1000_inspection_errors_classified($r),'inspect_unclassified_error');
}
function hi_admission(array $a,array $c):void {
 must(preg_match('/\A[a-f0-9]{64}\z/D',$a['route_attestation_sha256']??'')===1,'route_attestation_missing');
 must(($a['operation']??null)===HI_OPERATION&&($a['runtime_sha']??null)===$c['runtime_sha']&&($a['candidate_hook_sha256']??null)===HI_NEW
  &&($a['no_active_collectors']??null)===true&&($a['route_idle']??null)===true&&($a['price_window_owned']??null)===true
  &&($a['technical_release_accepted']??null)===true&&($a['technical_publisher_free']??null)===true,'admission_not_ready');
 foreach(['price_window_claims','technical_release_proof','producer_proof'] as $key)
  must(is_array($c[$key]??null)&&($a[$key]??null)===$c[$key],'admission_binding_changed');
 foreach(['journal_pin','child_pin'] as $key)must(hi_pin_equal($a[$key]??null,$c[$key]??null),'admission_binding_changed');
 foreach(['feed_shas','feed_counts'] as $key)must(hi_feed_map($a[$key]??null,$key==='feed_counts')===hi_feed_map($c[$key]??null,$key==='feed_counts'),'admission_binding_changed');
 must(count($c['price_window_claims'])>0,'claim_missing');
 foreach($c['price_window_claims'] as $claim)foreach(['claim_body_sha256','ack_body_sha256'] as $key)
  must(is_int($claim['claim_id']??null)&&$claim['claim_id']>0&&is_int($claim['ack_id']??null)&&$claim['ack_id']>0&&preg_match('/\A[a-f0-9]{64}\z/D',$claim[$key]??'')===1,'claim_invalid');
 must(($c['technical_release_proof']['application_sha']??null)===$c['runtime_sha']&&is_int($c['technical_release_proof']['deploy_run_id']??null)
  &&preg_match('/\A[a-f0-9]{64}\z/D',$c['technical_release_proof']['receipt_sha256']??'')===1
  &&is_int($c['producer_proof']['run_id']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$c['producer_proof']['receipt_sha256']??'')===1,'release_producer_invalid');
 must(($a['recovery_receipt_sha256']??null)===$c['recovery_receipt_sha256']&&preg_match('/\A[a-f0-9]{64}\z/D',$c['recovery_receipt_sha256']??'')===1,'recovery_not_verified');
}
function hi_state(array $state):void {
 must(array_key_exists('pending',$state)&&$state['pending']===null&&($state['completed'][4294]['operation']??null)===HI_RECOVERED
  &&is_string($state['completed'][4294]['feed_operation']??null),'recovery_not_completed');
 must(($state['nightly_cycle']['collector_run_id']??null)==='44236272559'&&monitor_hook_preflight_cycle_valid($state['nightly_cycle']),'historical_cycle_changed');
}
function hi_cas(array $before,array $now):void {
 foreach(['dev','ino','mode','uid','gid','size','mtime','ctime'] as $key)must(($before[$key]??null)===($now[$key]??null),'target_identity_changed');
}

function hi_stat(array $s):array {return array_intersect_key($s,array_flip(['dev','ino','mode','uid','gid','size','mtime','ctime']));}
