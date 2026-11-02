<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $discard):string=>'');
$p=null;$out=['operation'=>'hotel-retained-inputs-readonly-v1','provider_calls'=>0,'data_writes'=>0,'new_queue_created'=>false,'CENTRAL41'=>'UNKNOWN'];
try{
 $root='/var/www/anytoour/data/www/app.anytoour.ru';
 if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$root||!function_exists('posix_geteuid')||posix_geteuid()!==1012)throw new RuntimeException('target_unconfirmed');
 require $root.'/config.php';require_once $root.'/services/ConversationDb.php';
 $p=ConversationDb::connection();
 if($p->inTransaction()||$p->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$p->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION)throw new RuntimeException('connection_unconfirmed');
 $p->exec('SET TRANSACTION READ ONLY');$p->beginTransaction();
 $s=$p->prepare('SELECT DATABASE() AS db,CURRENT_USER() AS account,VERSION() AS version,@@version_comment AS comment,@@hostname AS host,@@port AS port,@@server_id AS server_id');
 if(!$s||!$s->execute())throw new RuntimeException('target_unconfirmed');
 $id=$s->fetch(PDO::FETCH_ASSOC);if(!$s->closeCursor())throw new RuntimeException('connection_unconfirmed');
 if(!is_array($id)||!hash_equals('9c5a356696060c37a9ac581c9849f8c53e38a29296eedee1c536f9ce794b3389',hash('sha256',json_encode($id,JSON_THROW_ON_ERROR))))throw new RuntimeException('target_unconfirmed');
 unset($id);$out['target_binding_confirmed']=true;
 $queue='HOTEL-CONTINUOUS-20261027';
 $s=$p->prepare('SELECT snapshot_bytes,original_state_sha256,receipt_bytes FROM hotel_work_retirements WHERE queue_id=? AND LENGTH(snapshot_bytes)<=4194304 AND LENGTH(receipt_bytes)<=4096');
 $s->execute([$queue]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);$s->closeCursor();
 if(count($rows)!==1||!hash_equals($rows[0]['original_state_sha256'],hash('sha256',$rows[0]['snapshot_bytes'])))throw new RuntimeException('retirement_snapshot_unconfirmed');
 $snap=json_decode($rows[0]['snapshot_bytes'],true,64,JSON_THROW_ON_ERROR);
 $ret=json_decode($rows[0]['receipt_bytes'],true,32,JSON_THROW_ON_ERROR);
 if(($ret['queue_id']??null)!==$queue||($ret['state']??null)!=='retired'||($ret['original_operation_outcome']??null)!=='UNKNOWN'||($ret['accepted_batches']??null)!==19||($ret['acceptance_credit']??null)!==0)throw new RuntimeException('retirement_receipt_unconfirmed');
 $q=$snap['hotel_work_queues'][0]??[];
 if(($q['queue_id']??null)!==$queue||(int)($q['accepted_batches']??-1)!==19||(int)($q['stage_index']??-1)!==41)throw new RuntimeException('retirement_snapshot_unconfirmed');
 $receipts=[];$resolutions=[];$accepted=[];$unknown=0;
 foreach($snap['hotel_work_resolutions']??[] as$r){
  if(!hash_equals((string)$r['receipt_sha256'],hash('sha256',(string)$r['receipt_json'])))throw new RuntimeException('receipt_hash_mismatch');
  $resolutions[(int)$r['stage_index']]=$r;
 }
 foreach($snap['hotel_work_receipts']??[] as$r){
  $i=(int)$r['stage_index'];
  if(isset($receipts[$i])||!hash_equals((string)$r['receipt_sha256'],hash('sha256',(string)$r['receipt_json'])))throw new RuntimeException('receipt_hash_mismatch');
  $receipts[$i]=$r;$body=json_decode($r['receipt_json'],true,32,JSON_THROW_ON_ERROR);
  if(($body['outcome']??null)==='unknown'&&isset($resolutions[$i])){
   $a=$resolutions[$i];if(!hash_equals($a['unknown_receipt_sha256'],$r['receipt_sha256']))throw new RuntimeException('resolution_binding_mismatch');
   $body=json_decode($a['receipt_json'],true,32,JSON_THROW_ON_ERROR);$effective=$a['receipt_sha256'];
  }else $effective=$r['receipt_sha256'];
  if(($body['outcome']??null)==='unknown')++$unknown;
  if($i%2===1&&($body['outcome']??null)==='accepted_readback'){
   if($i>=41||!is_string($body['packet_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$body['packet_sha256']))throw new RuntimeException('accepted_registry_unconfirmed');
   $accepted[$i]=['stage_index'=>$i,'stage_id_sha256'=>hash('sha256',$r['stage_id']),'original_receipt_sha256'=>$r['receipt_sha256'],'effective_receipt_sha256'=>$effective,'packet_sha256'=>$body['packet_sha256']];
  }
 }
 ksort($accepted,SORT_NUMERIC);
 if(count($accepted)!==19||count(array_unique(array_column($accepted,'packet_sha256')))!==19)throw new RuntimeException('accepted_registry_unconfirmed');
 $out+=['retirement_snapshot_verified'=>true,'receipt_hashes_verified'=>true,'original_receipt_count'=>count($receipts),'resolution_count'=>count($resolutions),'unresolved_receipt_count'=>$unknown,'accepted_batch_count'=>19,'accepted_packet_count'=>19,'accepted_registry_sha256'=>hash('sha256',json_encode(array_values($accepted),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'scope_mapping_verified'=>false,'semantic_exclusions_ready'=>false];
 if(!$p->rollBack())throw new RuntimeException('read_transaction_unconfirmed');
 // Bounded exact basenames in known application input locations only. No home, credentials or recursive scan.
 $names=['AnyTour_hotels_backup_20261101.zip','anytour-hotel-data-working.json','anytour-hotels-TH-research-20261020.zip','anytour-hotels-VN-research-20261020.zip','anytour-hotels-TR-research-20261020.zip','anytour-hotels-EG-research-20261020.zip','anytour-selection-criteria-20261020.zip','anytour-dialogue-mass-review-data-20261019.zip'];
 $base=dirname($root,2);
 $dirs=[$base,$base.'/backups',$base.'/hotel-inputs',$base.'/hotel-collection',$base.'/hotel-restart',$root.'/data',$root.'/storage',$root.'/exports'];
 $files=[];$budget=536870912;$locations=0;$unreadable=0;
 foreach($dirs as$dir){
  $st=@lstat($dir);if(!$st||($st['mode']&0170000)!==0040000)continue;
  if(!is_readable($dir)||!is_executable($dir)){++$unreadable;continue;}++$locations;
  foreach($names as$n){
   $path=$dir.'/'.$n;$f=@lstat($path);if(!$f)continue;
   if(($f['mode']&0170000)!==0100000||!is_readable($path)){$files[]=['kind'=>array_search($n,$names,true),'status'=>'unreadable_or_not_regular'];continue;}
   if($f['size']<1||$f['size']>$budget){$files[]=['kind'=>array_search($n,$names,true),'status'=>'hash_budget_exceeded'];continue;}
   $budget-=$f['size'];$sha=hash_file('sha256',$path);clearstatcache(true,$path);$after=@lstat($path);
   if(!$after||$after['ino']!==$f['ino']||$after['size']!==$f['size']||$after['mtime']!==$f['mtime']||!is_string($sha))throw new RuntimeException('input_changed_during_read');
   $match=$n==='AnyTour_hotels_backup_20261101.zip'?$f['size']===246123355&&hash_equals('61917edb636c752176a5d369d885e1aea249dae8be0d1764d46d7b744b5ca1f2',$sha):null;
   $files[]=['kind'=>array_search($n,$names,true),'status'=>'hashed','bytes'=>$f['size'],'sha256'=>$sha,'retained_backup_match'=>$match];
  }
 }
 $out+=['state'=>'inventory_complete','known_locations_readable'=>$locations,'known_locations_unreadable'=>$unreadable,'bounded_file_matches'=>$files,'search_complete_for_server'=>false,'map_location_verified'=>false,'input_bytes_exported'=>false];
}catch(Throwable$e){
 $allowed=['target_unconfirmed','connection_unconfirmed','retirement_snapshot_unconfirmed','retirement_receipt_unconfirmed','receipt_hash_mismatch','resolution_binding_mismatch','accepted_registry_unconfirmed','read_transaction_unconfirmed','input_changed_during_read'];
 $out['state']='HOLD';$out['reason']=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'inventory_unconfirmed';
}finally{
 if($p instanceof PDO&&$p->inTransaction()){try{if(!$p->rollBack())$out=['operation'=>'hotel-retained-inputs-readonly-v1','state'=>'HOLD','reason'=>'read_transaction_unconfirmed','data_writes'=>0,'provider_calls'=>0];}catch(Throwable$ignored){$out=['operation'=>'hotel-retained-inputs-readonly-v1','state'=>'HOLD','reason'=>'read_transaction_unconfirmed','data_writes'=>0,'provider_calls'=>0];}}
 while(ob_get_level()>$level)ob_end_clean();
}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['state']==='inventory_complete'?0:1);
