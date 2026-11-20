<?php
declare(strict_types=1);
/** One finite SELECT-only historical entry-price read; transport admission belongs to the pinned runner. */
const EXPECTED_RUNTIME='b4b65daaa472c15426a244b87ede3e6c0394626b';
const EXPECTED_PRICE_CLASS_SHA256='1e57fdf4a06c20e5136c968418a151d46aba8516e7f249ebd7c2f705325933da';
const ENTRY_FROM='2026-11-11 21:00:00';
const ENTRY_UNTIL='2026-11-18 21:00:00';
const ENTRY_LIMIT=10001;
const VERSION_LIMIT=1000;
const SELECTED_BYTE_LIMIT=4194304;
const OUTPUT_BYTE_LIMIT=4194304;
function audit_need(bool $v,string $e):void {if(!$v)throw new RuntimeException($e);}
function runtime_check():void {
 audit_need(trim((string)shell_exec('git rev-parse HEAD'))===EXPECTED_RUNTIME,'runtime_changed');
 audit_need(trim((string)shell_exec('git status --porcelain --untracked-files=no'))==='','runtime_dirty');
 audit_need(trim((string)shell_exec('git hash-object -- services/ConversationDb.php'))==='bb6281faf55aca7beeae7f8a57389d19751936f7','db_source_changed');
 audit_need(hash_file('sha256',getcwd().'/services/ModeledConversionDealValue.php')===EXPECTED_PRICE_CLASS_SHA256,'price_source_changed');
}
function audit_empty_price(string $state='missing'):array {
 audit_need(in_array($state,['missing','oversize','unparseable'],true),'price_state');
 return ['state'=>$state,'low'=>null,'high'=>null,'point'=>null,'unit'=>null,'party_size'=>null,'basis'=>null,'price_verified'=>false];
}
function audit_price(array $row):array {
 $state=$row['text_state']??'missing';
 if($state!=='present')return audit_empty_price($state);
 $text=$row['post_text']??null;
 if(!is_string($text)||$text==='')return audit_empty_price('missing');
 if(strlen($text)>8192)return audit_empty_price('oversize');
 $range=ModeledConversionDealValue::publicationRange($text);
 if($range===null)return audit_empty_price('unparseable');
 audit_need(array_keys($range)===['low','high','point','unit','party_size','basis'],'price_contract');
 audit_need(is_int($range['low'])&&$range['low']>=10000&&$range['low']<=10000000
  &&is_int($range['point'])&&$range['point']>=$range['low']&&$range['point']<=10000000
  &&($range['high']===null||(is_int($range['high'])&&$range['high']>=$range['point']&&$range['high']<=10000000))
  &&in_array($range['unit'],['unknown','party_total','per_person'],true)
  &&($range['party_size']===null||(is_int($range['party_size'])&&$range['party_size']>=0&&$range['party_size']<=99))
  &&in_array($range['basis'],['advertised_floor_proxy','advertised_range_proxy'],true),'price_contract');
 return ['state'=>'parsed']+$range+['price_verified'=>false];
}
function audit_source_key(mixed $raw):?string {
 return is_string($raw)&&preg_match('/\A[A-Za-z][A-Za-z0-9_:-]{0,95}\z/D',$raw)===1?$raw:null;
}
function audit_version_requests(array $rows):array {
 $requests=[];
 foreach($rows as$r){
  if(($r['publication_id']??null)===null||($r['binding_valid']??0)!=1)continue;
  $version=(string)($r['version_id']??'');$publication=(string)$r['publication_id'];
  audit_need(preg_match('/\A[1-9][0-9]{0,18}\z/D',$version)===1&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$publication)===1,'version_binding');
  audit_need(!isset($requests[$version])||$requests[$version]===$publication,'version_binding');
  $requests[$version]=$publication;
  audit_need(count($requests)<=VERSION_LIMIT,'version_limit_incomplete');
 }
 return $requests;
}
function audit_version_projection(array $rows,array $requests):array {
 $out=[];
 foreach($rows as$r){
  $version=(string)($r['version_id']??'');$publication=(string)($r['publication_id']??'');
  audit_need(isset($requests[$version])&&$requests[$version]===$publication&&!isset($out[$version]),'version_binding');
  $hash=$r['content_hash']??null;
  $out[$version]=['content_sha256'=>is_string($hash)&&preg_match('/\A[a-f0-9]{64}\z/D',$hash)===1?$hash:null,'price'=>audit_price($r)];
 }
 return $out;
}
function audit_project(array $rows,array $prices=[],int $versionRows=0):array {
 audit_need(count($rows)<ENTRY_LIMIT,'entry_limit_incomplete');
 $requests=audit_version_requests($rows);$groups=[];$seen=[];$unknown=0;$invalid=0;$unsafeSource=0;
 foreach($rows as$r){
  audit_need(isset($r['entry_id'])&&!isset($seen[$r['entry_id']]),'entry_duplicate');$seen[$r['entry_id']]=true;
  audit_need(is_string($r['entered_at'])&&$r['entered_at']>=ENTRY_FROM&&$r['entered_at']<ENTRY_UNTIL,'entry_clock');
  if($r['publication_id']===null){$unknown++;continue;}
  if(($r['binding_valid']??0)!=1){$invalid++;continue;}
  audit_need(in_array($r['platform'],['max','telegram'],true),'platform');
  $source=audit_source_key($r['source_key']??null);if($source===null)$unsafeSource++;
  $version=(string)$r['version_id'];
  $key=$r['platform'].'|'.$r['channel_id'].'|'.$r['publication_id'].'|'.$version;
  if(!isset($groups[$key])){
   $saved=$prices[$version]??['content_sha256'=>null,'price'=>audit_empty_price()];
   $groups[$key]=['publication_id'=>(int)$r['publication_id'],'version_id'=>(int)$version,
    'post_id'=>(string)$r['external_message_id'],'platform'=>$r['platform'],'channel_id'=>(string)$r['channel_id'],
    'source_key'=>$source,'content_sha256'=>$saved['content_sha256'],'price'=>$saved['price'],
    'entries'=>0,'dialogues'=>[],'first_entry_utc'=>$r['entered_at'],'last_entry_utc'=>$r['entered_at']];
  }
  $g=&$groups[$key];audit_need($g['source_key']===$source&&$g['post_id']===(string)$r['external_message_id'],'group_binding');
  $g['entries']++;$g['dialogues'][(string)$r['conversation_id']]=true;
  $g['first_entry_utc']=min($g['first_entry_utc'],$r['entered_at']);$g['last_entry_utc']=max($g['last_entry_utc'],$r['entered_at']);unset($g);
 }
 $priceEntries=['missing'=>0,'oversize'=>0,'unparseable'=>0,'parsed'=>0];
 foreach($groups as&$g){$g['distinct_dialogues']=count($g['dialogues']);unset($g['dialogues']);$priceEntries[$g['price']['state']]+=$g['entries'];}unset($g);
 $groups=array_values($groups);usort($groups,fn($a,$b)=>($b['entries']<=>$a['entries'])?:($a['publication_id']<=>$b['publication_id'])?:($a['version_id']<=>$b['version_id']));
 audit_need(array_sum(array_column($groups,'entries'))+$unknown+$invalid===count($rows),'entry_reconciliation');
 audit_need($versionRows>=0&&$versionRows<=count($requests),'version_binding');
 return ['schema'=>'post_entry_price_aggregate_v1',
  'window'=>['from_utc'=>ENTRY_FROM,'until_utc_exclusive'=>ENTRY_UNTIL,'business_timezone'=>'Europe/Moscow','days'=>7],
  'coverage'=>['entry_rows_read'=>count($rows),'complete_within_bound'=>true,'unavailable_publication_entries'=>$unknown,
   'invalid_binding_entries'=>$invalid,'distinct_pinned_versions'=>count($requests),'version_rows_read'=>$versionRows,
   'source_key_unavailable_entries'=>$unsafeSource,'price_state_entries'=>$priceEntries],
  'posts'=>$groups,'semantics'=>['basis'=>'registry_accepted_entries_event_join_not_rechecked',
   'entries_are_clicks'=>false,'dialogues_are_unique_people'=>false,'paid_organic'=>'unknown',
   'cross_post_or_version_dialogues_additive'=>false,'leads_measured'=>false,'current_post_liveness_verified'=>false,
   'price_basis'=>'pure_parse_of_exact_entry_pinned_publication_snapshot',
   'price_is_frozen_modeled_context'=>false,'price_verified'=>false,
   'point_is'=>'advertised_range_midpoint_or_floor_not_sales_mean',
   'source_key_basis'=>'current_publication_routing_not_hotel_identity',
   'missing_price_is_zero'=>false,'customer_texts_read'=>false,'profiles_read'=>false]];
}
function audit_plan(array $plan,bool $requireEntry=true):void {
 audit_need(count($plan)>0&&count($plan)<=20,'explain_shape');$cost=0;$seen=false;
 foreach($plan as $row){
  $table=(string)($row['table']??'');$type=strtoupper((string)($row['type']??''));
  audit_need(isset($row['rows'])&&is_numeric($row['rows']),'explain_rows_unknown');$cost+=(int)$row['rows'];
  audit_need(!str_contains(strtoupper((string)($row['Extra']??'')),'DEPENDENT'),'explain_dependent');
  if(in_array($table,['pe','scope'],true)){
   if($table==='pe')$seen=true;
   audit_need(in_array($type,['CONST','EQ_REF','REF','RANGE'],true)&&!empty($row['key']),'unbounded_base_access');
  }
  if($type==='ALL'||$type==='INDEX')audit_need(str_starts_with($table,'<derived')&&(int)$row['rows']<=10001,'explain_full_scan');
 }
 audit_need((!$requireEntry||$seen)&&$cost<=50000,'explain_cost_or_missing_base');
}
function audit_server_version(string $version):void {
 // Only positively identified Oracle/MySQL compatible version syntax; MariaDB ignores this hint.
 audit_need(!preg_match('/mariadb|tidb|percona|aurora/i',$version),'unsupported_server_version');
 audit_need(preg_match('/\A(5\.7\.(\d+)|8\.(0|4)\.\d+)(?:-(?:log|[0-9]+ubuntu[0-9.]+|debian[0-9.]+))?\z/D',$version)===1,'unsupported_server_version');
 if(str_starts_with($version,'5.7.'))audit_need(version_compare(preg_replace('/-.*/','',$version),'5.7.8','>='),'unsupported_server_version');
}

function audit_read(PDO $pdo):array {
 $deadline=microtime(true)+17.0;
 audit_need($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql','mysql_required');
 audit_need(!$pdo->inTransaction(),'existing_transaction');
 audit_server_version((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
 $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
 try {
  // Preserve the original PRIMARY-only capped scan. A cap is incomplete, never a sample.
  $scan="SELECT /*+ MAX_EXECUTION_TIME(3000) */ id,created_at FROM post_entries FORCE INDEX (PRIMARY) ORDER BY id LIMIT 10001";
  $q=$pdo->query('EXPLAIN '.$scan);$plan=$q->fetchAll(PDO::FETCH_ASSOC);
  audit_need(count($plan)===1&&($plan[0]['key']??null)==='PRIMARY'&&strtoupper((string)($plan[0]['type']??''))==='INDEX','capped_primary_plan');
  audit_need(microtime(true)<$deadline,'wall_budget');$q=$pdo->query($scan);$ids=[];$scanned=0;
  while($r=$q->fetch(PDO::FETCH_ASSOC)){
   audit_need(++$scanned<ENTRY_LIMIT,'entry_limit_incomplete');
   if($r['created_at']>=ENTRY_FROM&&$r['created_at']<ENTRY_UNTIL)$ids[]=(int)$r['id'];
  }
  $rows=[];$bytes=0;
  foreach(array_chunk($ids,500)as$chunk){
   audit_need(microtime(true)<$deadline,'wall_budget');$marks=implode(',',array_fill(0,count($chunk),'?'));
   $sql="SELECT /*+ MAX_EXECUTION_TIME(3000) */ pe.id AS entry_id,pe.conversation_id,pe.publication_id,pe.version_id,pe.created_at AS entered_at,pp.external_message_id,pc.platform,pc.channel_id,
    CASE WHEN OCTET_LENGTH(src.source_key)<=96 THEN src.source_key ELSE NULL END AS source_key,
    CASE WHEN pp.id IS NOT NULL AND v.id IS NOT NULL AND p.project_key='anytour' AND pc.platform=c.channel AND pp.source_id=pc.source_id AND src.project_id=pc.project_id THEN 1 ELSE 0 END AS binding_valid
    FROM post_entries pe FORCE INDEX (PRIMARY) JOIN conversations c ON c.id=pe.conversation_id
    LEFT JOIN post_publications pp ON pp.id=pe.publication_id LEFT JOIN post_channels pc ON pc.id=pp.post_channel_id
    LEFT JOIN projects p ON p.id=pc.project_id LEFT JOIN conversation_sources src ON src.id=pp.source_id
    LEFT JOIN post_versions v ON v.id=pe.version_id AND v.publication_id=pp.id
    WHERE pe.id IN ($marks) AND c.project_key='anytour' AND c.is_test=0 AND c.channel IN ('max','telegram') ORDER BY pe.id";
   $q=$pdo->prepare('EXPLAIN '.$sql);$q->execute($chunk);audit_plan($q->fetchAll(PDO::FETCH_ASSOC));
   audit_need(microtime(true)<$deadline,'wall_budget');$q=$pdo->prepare($sql);$q->execute($chunk);$batch=0;
   while($r=$q->fetch(PDO::FETCH_ASSOC)){
    audit_need(++$batch<=count($chunk),'join_multiplication');$bytes+=strlen(json_encode($r,JSON_THROW_ON_ERROR));
    audit_need($bytes<=SELECTED_BYTE_LIMIT,'selected_byte_limit');$rows[]=$r;
   }
  }
  $requests=audit_version_requests($rows);$prices=[];$versionRows=0;
  foreach(array_chunk(array_keys($requests),50)as$chunk){
   audit_need(microtime(true)<$deadline,'wall_budget');$marks=implode(',',array_fill(0,count($chunk),'?'));
   $text="JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.text'))";
   $type="JSON_TYPE(JSON_EXTRACT(v.snapshot_json,'$.text'))";
   $sql="SELECT /*+ MAX_EXECUTION_TIME(3000) */ v.id AS version_id,v.publication_id,v.content_hash,
    CASE WHEN JSON_VALID(v.snapshot_json) THEN
      CASE WHEN $type IS NULL OR $type='NULL' THEN 'missing'
      WHEN $type<>'STRING' THEN 'unparseable'
      WHEN OCTET_LENGTH($text)>8192 THEN 'oversize' ELSE 'present' END
    ELSE 'unparseable' END AS text_state,
    CASE WHEN JSON_VALID(v.snapshot_json) THEN CASE WHEN $type='STRING'
      THEN CASE WHEN OCTET_LENGTH($text)<=8192 THEN $text ELSE NULL END ELSE NULL END ELSE NULL END AS post_text
    FROM post_versions v FORCE INDEX (PRIMARY) WHERE v.id IN ($marks) ORDER BY v.id";
   $q=$pdo->prepare('EXPLAIN '.$sql);$q->execute($chunk);audit_plan($q->fetchAll(PDO::FETCH_ASSOC),false);
   audit_need(microtime(true)<$deadline,'wall_budget');$q=$pdo->prepare($sql);$q->execute($chunk);$batch=[];
   while($r=$q->fetch(PDO::FETCH_ASSOC)){
    audit_need(count($batch)<count($chunk),'join_multiplication');$bytes+=strlen(json_encode($r,JSON_THROW_ON_ERROR));
    audit_need($bytes<=SELECTED_BYTE_LIMIT,'selected_byte_limit');$batch[]=$r;
   }
   $projected=audit_version_projection($batch,$requests);
   foreach($projected as$version=>$price){audit_need(!isset($prices[$version]),'version_binding');$prices[$version]=$price;}
   $versionRows+=count($batch);unset($batch,$projected);
  }
  audit_need(microtime(true)<$deadline,'wall_budget');
  $out=audit_project($rows,$prices,$versionRows);
  $out['coverage']['global_entry_rows_scanned']=$scanned;$out['coverage']['global_window_entries']=count($ids);
  audit_need(strlen(json_encode($out,JSON_THROW_ON_ERROR))<=OUTPUT_BYTE_LIMIT,'output_byte_limit');
  $pdo->rollBack();return $out;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function audit_safe_error(Throwable $e):string {
 $safe=['unsupported_server_version','wall_budget','runtime_changed','runtime_dirty','db_source_changed','price_source_changed',
  'entry_limit_incomplete','version_limit_incomplete','selected_byte_limit','output_byte_limit','capped_primary_plan','join_multiplication',
  'entry_duplicate','entry_clock','platform','explain_shape','explain_rows_unknown','explain_dependent','unbounded_base_access',
  'explain_full_scan','explain_cost_or_missing_base','version_binding','group_binding','entry_reconciliation','price_state','price_contract'];
 return in_array($e->getMessage(),$safe,true)?$e->getMessage():'bounded_read_stopped';
}
if(!defined('HOTEL_ENTRY_PRICE_TEST_LIBRARY')){
 set_time_limit(20);ini_set('display_errors','0');ini_set('log_errors','0');
 try {
  audit_need(PHP_SAPI==='cli','cli_required');runtime_check();
  ob_start();try{
   require getcwd().'/config.php';require_once getcwd().'/services/ConversationDb.php';
   require_once getcwd().'/services/ModeledConversionDealValue.php';
  }finally{ob_end_clean();}
  $aggregate=audit_read(ConversationDb::connection());runtime_check();
  $out=['ok'=>true,'installed_sha'=>EXPECTED_RUNTIME,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0,'aggregate'=>$aggregate];
  $json=json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  audit_need(strlen($json)<=OUTPUT_BYTE_LIMIT,'output_byte_limit');echo $json,"\n";
 }catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>audit_safe_error($e)]),"\n";exit(1);}
}

