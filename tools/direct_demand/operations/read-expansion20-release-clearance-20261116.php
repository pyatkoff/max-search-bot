<?php
declare(strict_types=1);
// Exact expansion20; read-only transactions; no transport, sender or persistent writes.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
function need20(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function matches20(array $c,array $r):array{
 $why=[];if((int)($r['hotel_catalog_id']??0)===$c['hotel_id'])$why[]='native_id';
 if(($r['logical_id']??null)===$c['logical_id'])$why[]='logical_id';
 $line=mb_strtolower((string)($r['first_line']??''),'UTF-8');
 foreach($c['aliases'] as $a)if(mb_strpos($line,mb_strtolower($a,'UTF-8'),0,'UTF-8')!==false)$why[]='alias:'.$a;
 return array_values(array_unique($why));
}
if(($argv[1]??'')==='--self-test'){
 $c=['hotel_id'=>123,'logical_id'=>'commercial-hotel-123-v1','aliases'=>['Test Resort','Тест Отель']];
 need20(matches20($c,['hotel_catalog_id'=>'123'])===['native_id'],'test_native');
 need20(matches20($c,['logical_id'=>$c['logical_id']])===['logical_id'],'test_logical');
 need20(matches20($c,['first_line'=>'✈️ TEST RESORT'])===['alias:Test Resort'],'test_alias');
 need20(matches20($c,['first_line'=>'ТЕСТ ОТЕЛЬ'])===['alias:Тест Отель'],'test_unicode');
 need20(matches20($c,['first_line'=>'Other'])===[],'test_other');
 echo "EXPANSION20_CLEARANCE_TESTS_OK checks=5 writes=0\n";exit;
}
$out=['ok'=>false,'at'=>gmdate('c'),'mode'=>'expansion20_current_read_only','db_writes'=>0,'post_writes'=>0,'feed_writes'=>0,'journal_writes'=>0,'provider_calls'=>0,'supplier_calls'=>0,'publication_allowed'=>false];$handles=[];
try{
 need20(defined('C20_INPUT_B64')&&defined('C20_PINS_B64'),'input_required');
 $raw=base64_decode(C20_INPUT_B64,true);$rows=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
 $ids=[88169,547,15774,12125,57335,2480,2417,2418,2468,12127,27481,23289,50262,72939,2489,85417,2466,2460,99713,43676];
 need20(array_is_list($rows)&&array_column($rows,'hotel_id')===$ids,'exact_scope_changed');
 foreach($rows as $c){need20(is_string($c['name'])&&is_int($c['country_id'])&&is_int($c['region_id'])&&$c['logical_id']==='commercial-hotel-'.$c['hotel_id'].'-v1','identity_invalid');need20(count($c['aliases'])>0&&count($c['aliases'])<=12,'alias_bound');foreach($c['aliases'] as $a)need20(is_string($a)&&trim($a)!==''&&mb_strlen($a,'UTF-8')<=160,'alias_invalid');}
 $out['input_sha256']=hash('sha256',$raw);$app='/var/www/anytoour/data/www/app.anytoour.ru';need20(realpath($app)===$app&&chdir($app),'app_root_changed');
 $out['runtime']=['head'=>trim((string)shell_exec('git rev-parse HEAD')),'tracked_clean'=>trim((string)shell_exec('git status --porcelain --untracked-files=no'))==='','component_mismatches'=>[]];
 $pins=json_decode(base64_decode(C20_PINS_B64,true),true,32,JSON_THROW_ON_ERROR);need20(count($pins)===22,'pin_count');
 foreach($pins as $path=>$expected){need20(preg_match('~\A(?:services|integrations|migrations)/[A-Za-z0-9_.-]+\z~D',$path)===1,'pin_path');$actual=trim((string)shell_exec('git hash-object -- '.escapeshellarg($path)));if($actual!==$expected)$out['runtime']['component_mismatches'][]=['path'=>$path,'expected'=>$expected,'actual'=>$actual];}
 need20(trim((string)shell_exec('git hash-object services/HotelDatabase.php'))==='0973a849add7f0c594ae920c5eb0ef62c58dffb5','catalog_boundary_changed');
 need20(trim((string)shell_exec('git hash-object services/ConversationDb.php'))==='bb6281faf55aca7beeae7f8a57389d19751936f7','registry_boundary_changed');
 $private='/var/www/anytoour/data/channel-post-operations';need20(!is_link($private)&&realpath($private)===$private,'private_root_changed');$path=$private.'/monitor-price-after-collection-v1.jsonl';
 need20(!is_link($path)&&is_file($path)&&realpath($path)===$path,'monitor_path');$f=fopen($path,'rb');need20(is_resource($f),'monitor_open');$handles[]=$f;need20(flock($f,LOCK_SH|LOCK_NB),'monitor_busy_stop');$st=fstat($f);need20($st['size']>0,'monitor_empty');$off=max(0,$st['size']-4194304);need20(fseek($f,$off)===0,'monitor_seek');$tail=stream_get_contents($f,4194304);need20(is_string($tail)&&str_ends_with($tail,"\n"),'monitor_incomplete');$tail=substr($tail,0,-1);$pos=strrpos($tail,"\n");need20($off===0||$pos!==false,'monitor_line_bound');$last=$pos===false?$tail:substr($tail,$pos+1);$s=json_decode($last,true,64,JSON_THROW_ON_ERROR);
 $out['monitor']=['last_line_sha256'=>hash('sha256',$last."\n"),'pending'=>is_array($s['pending']??null)?array_intersect_key($s['pending'],array_flip(['tourvisor_hotel_id','operation','phase','child_failure'])):null,'cycle'=>is_array($s['nightly_cycle']??null)?array_intersect_key($s['nightly_cycle'],array_flip(['collector_run_id','state','night_date'])):null];
 ob_start();try{require $app.'/config.php';require_once $app.'/services/ConversationDb.php';require_once $app.'/services/HotelDatabase.php';}finally{ob_end_clean();}
 need20(HotelDatabase::configured(),'catalog_not_configured');$catalog=HotelDatabase::connection();$registry=ConversationDb::connection();foreach([$catalog,$registry] as $db){$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();}
 $q=$catalog->prepare('SELECT h.id,h.name,h.is_active,h.country_id,h.region_id,r.name AS region FROM catalog_hotels h LEFT JOIN catalog_regions r ON r.id=h.region_id AND r.country_id=h.country_id WHERE h.id IN ('.implode(',',array_fill(0,20,'?')).') ORDER BY h.id');$q->execute($ids);$hotels=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $h)$hotels[(int)$h['id']]=$h;
 $first="SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.text')),CHAR(10),1)";$native="JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id'))";$logical="JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.logical_id'))";$out['rows']=[];
 foreach($rows as $c){$id=$c['hotel_id'];$h=$hotels[$id]??null;$blocks=[];
  if(!$h||(int)$h['is_active']!==1)$blocks[]='active_native_missing';
  if($h&&((int)$h['country_id']!==$c['country_id']||(int)$h['region_id']!==$c['region_id']||mb_strtolower(trim($h['name']),'UTF-8')!==mb_strtolower(trim($c['name']),'UTF-8')))$blocks[]='native_identity_changed';
  $pred=['CAST('.$native.' AS UNSIGNED)=?','BINARY '.$logical.'=?'];$params=[$id,$c['logical_id']];foreach($c['aliases'] as $alias){$pred[]='LOCATE(?,LOWER('.$first.'))>0';$params[]=mb_strtolower($alias,'UTF-8');}
  $sql="SELECT pp.id AS publication_id,pc.platform,v.id AS version_id,v.state,".$first." AS first_line,".$native." AS hotel_catalog_id,".$logical." AS logical_id,JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.post_url')) AS post_url,SHA2(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.text')),256) AS text_sha256 FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.post_channel_id IN (9,10) AND (".implode(' OR ',$pred).") ORDER BY pp.id LIMIT 21";
  $q=$registry->prepare($sql);$q->execute($params);$found=$q->fetchAll(PDO::FETCH_ASSOC);need20(count($found)<21,'matches_bound');$matches=[];foreach($found as $r){$reasons=matches20($c,$r);if($reasons){$r['match_reasons']=$reasons;$r['same_text_hash']=$r['text_sha256']===$c['text_sha256'];$matches[]=$r;}}
  if($matches)$blocks[]='existing_registry_match';$receipts=[];
  foreach(['telegram','max'] as $platform){$paths=glob($private.'/commercial-hotel-*/'.$platform.'-'.$c['logical_id'].'-approved.json');need20(is_array($paths)&&count($paths)<=30,'receipt_bound');foreach($paths as $p){need20(!is_link(dirname($p))&&!is_link($p)&&realpath($p)===$p&&is_file($p)&&filesize($p)<2000000,'receipt_path');$bytes=file_get_contents($p);$r=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);$receipts[]=['operation'=>basename(dirname($p)),'platform'=>$platform,'sha256'=>hash('sha256',$bytes),'state'=>$r['state']??null,'publication_id'=>$r['receipt']['publication_id']??$r['publication_id']??null,'post_url'=>$r['receipt']['post_url']??null];}}
  if($receipts)$blocks[]='prior_operation_receipt_present';$out['rows'][]=['hotel_id'=>$id,'native'=>$h,'matches'=>$matches,'prior_receipts'=>$receipts,'blockers'=>$blocks,'candidate_clear'=>$blocks===[]];
 }
 $registry->rollBack();$catalog->rollBack();$out['candidate_count']=20;$out['clear_count']=count(array_filter($out['rows'],static fn($r)=>$r['candidate_clear']));$out['blocked_count']=20-$out['clear_count'];$out['ok']=true;
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]+\z/D',$e->getMessage())===1?$e->getMessage():'bounded_read_failed';}
finally{foreach(['registry','catalog'] as $name)if(isset($$name)&&$$name->inTransaction())$$name->rollBack();foreach(array_reverse($handles) as $h){flock($h,LOCK_UN);fclose($h);}}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";exit($out['ok']?0:2);
