<?php
declare(strict_types=1);
// Standalone assembly: replace LIBRARY_CODE marker with reviewed reader.php without PHP opening tag/declare.
const REVIEWED_TARGETS=[['platform'=>'telegram','channel_id'=>'-1001845835645','message_id'=>'1754'],['platform'=>'telegram','channel_id'=>'-1001845835645','message_id'=>'1756'],['platform'=>'telegram','channel_id'=>'-1001845835645','message_id'=>'1757'],['platform'=>'telegram','channel_id'=>'-1001845835645','message_id'=>'1760']];
const ADMISSION_OPEN=true; // Transport owner binds only after review.
const EXPECTED_RUNTIME='bd8e248f7138ea75cb9fda2dcf44beb631c633a5';
set_time_limit(20);ini_set('display_errors','0');ini_set('log_errors','0');
function runtime_check():void {
 if(trim((string)shell_exec('git rev-parse HEAD'))!==EXPECTED_RUNTIME)throw new RuntimeException('runtime_changed');
 if(trim((string)shell_exec('git status --porcelain --untracked-files=no'))!=='')throw new RuntimeException('runtime_dirty');
 if(trim((string)shell_exec('git hash-object -- services/ConversationDb.php'))!=='bb6281faf55aca7beeae7f8a57389d19751936f7')throw new RuntimeException('db_source_changed');
}
// Library only: no bootstrap, network, or execution on include. Native wrapper must pin this file.
const ENTRY_FROM='2026-11-11 21:00:00';
const ENTRY_UNTIL='2026-11-18 21:00:00';
const ENTRY_LIMIT=10001;
function audit_need(bool $v,string $e):void {if(!$v)throw new RuntimeException($e);}
function audit_project(array $rows,array $targets):array {
 audit_need(count($rows)<ENTRY_LIMIT,'entry_limit_incomplete');
 audit_need(count($targets)===4,'targets_scope');
 audit_need(array_column($targets,'message_id')===['1754','1756','1757','1760'],'targets_scope');
 foreach($targets as $t) audit_need(($t['platform']??null)==='telegram'&&is_string($t['channel_id']??null)&&preg_match('/^-?[0-9]{1,64}$/D',$t['channel_id'])===1,'target_channel_unbound');
 $groups=[];$seen=[];$unknown=0;$invalid=0;
 foreach($rows as $r){
  audit_need(isset($r['entry_id'])&&!isset($seen[$r['entry_id']]),'entry_duplicate');$seen[$r['entry_id']]=true;
  audit_need($r['entered_at']>=ENTRY_FROM&&$r['entered_at']<ENTRY_UNTIL,'entry_clock');
  if($r['publication_id']===null){$unknown++;continue;}
  if(($r['binding_valid']??0)!=1){$invalid++;continue;}
  audit_need(in_array($r['platform'],['max','telegram'],true),'platform');
  $key=$r['platform'].'|'.$r['channel_id'].'|'.$r['publication_id'];
  if(!isset($groups[$key]))$groups[$key]=['publication_id'=>(int)$r['publication_id'],'post_id'=>(string)$r['external_message_id'],'platform'=>$r['platform'],'channel_id'=>(string)$r['channel_id'],'entries'=>0,'dialogues'=>[],'first_entry_utc'=>$r['entered_at'],'last_entry_utc'=>$r['entered_at']];
  $g=&$groups[$key];$g['entries']++;$g['dialogues'][(string)$r['conversation_id']]=true;
  $g['first_entry_utc']=min($g['first_entry_utc'],$r['entered_at']);$g['last_entry_utc']=max($g['last_entry_utc'],$r['entered_at']);unset($g);
 }
 foreach($groups as &$g){$g['distinct_dialogues']=count($g['dialogues']);unset($g['dialogues']);}unset($g);
 $groups=array_values($groups);usort($groups,fn($a,$b)=>($b['distinct_dialogues']<=>$a['distinct_dialogues'])?:($b['entries']<=>$a['entries'])?:($a['publication_id']<=>$b['publication_id']));
 $priority=[];foreach($targets as $t){$matched=array_values(array_filter($groups,fn($g)=>$g['platform']===$t['platform']&&$g['channel_id']===$t['channel_id']&&$g['post_id']===$t['message_id']));$priority[]=['target'=>$t,'observed_posts'=>$matched,'zero_means'=>'no_observed_registry_entry_publication_existence_reported_separately'];}
 return ['schema'=>'post_entry_aggregate_v1','window'=>['from_utc'=>ENTRY_FROM,'until_utc_exclusive'=>ENTRY_UNTIL,'business_timezone'=>'Europe/Moscow','days'=>7],'coverage'=>['entry_rows_read'=>count($rows),'complete_within_bound'=>true,'unavailable_publication_entries'=>$unknown,'invalid_binding_entries'=>$invalid],'posts'=>$groups,'priority'=>$priority,'semantics'=>['basis'=>'registry_accepted_entries_event_join_not_rechecked','entries_are_clicks'=>false,'dialogues_are_unique_people'=>false,'paid_organic'=>'unknown','cross_post_dialogues_additive'=>false,'leads_measured'=>false,'current_post_liveness_verified'=>false]];
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
function audit_read(PDO $pdo,array $targets):array {
 $deadline=microtime(true)+17.0;
 audit_need($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql','mysql_required');
 audit_need(!$pdo->inTransaction(),'existing_transaction');
 audit_server_version((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
 $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
 try {
  // Validate targets before any diagnostic SELECT. Exact channel must be reviewed externally.
  audit_project([],$targets);$publications=[];
  $meta="SELECT /*+ MAX_EXECUTION_TIME(3000) */ pp.id AS publication_id,pp.external_message_id AS post_id,pc.platform,pc.channel_id,v.id AS version_id,v.state FROM post_channels pc JOIN projects p ON p.id=pc.project_id JOIN post_publications pp ON pp.post_channel_id=pc.id LEFT JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE p.project_key='anytour' AND pc.platform='telegram' AND pc.channel_id=? AND pp.external_message_id=? LIMIT 2";
  foreach($targets as $t){audit_need(microtime(true)<$deadline,'wall_budget');$args=[$t['channel_id'],$t['message_id']];$q=$pdo->prepare('EXPLAIN '.$meta);$q->execute($args);audit_plan($q->fetchAll(PDO::FETCH_ASSOC),false);audit_need(microtime(true)<$deadline,'wall_budget');$q=$pdo->prepare($meta);$q->execute($args);$found=$q->fetchAll(PDO::FETCH_ASSOC);audit_need(count($found)<=1,'target_ambiguous');foreach($found as $m){$m['publication_id']=(string)$m['publication_id'];$m['version_id']=$m['version_id']===null?null:(string)$m['version_id'];$m['post_id']=(string)$m['post_id'];$m['channel_id']=(string)$m['channel_id'];audit_need(strlen(json_encode($m))<=1024,'metadata_size');$publications[]=$m;}}
  // Exact PRIMARY-only capped full-table scan: no WHERE or join can force scanning past cap.
  $scan="SELECT /*+ MAX_EXECUTION_TIME(3000) */ id,created_at FROM post_entries FORCE INDEX (PRIMARY) ORDER BY id LIMIT 10001";
  $q=$pdo->query('EXPLAIN '.$scan);$plan=$q->fetchAll(PDO::FETCH_ASSOC);audit_need(count($plan)===1&&($plan[0]['key']??null)==='PRIMARY'&&strtoupper((string)($plan[0]['type']??''))==='INDEX','capped_primary_plan');
  audit_need(microtime(true)<$deadline,'wall_budget');$q=$pdo->query($scan);$ids=[];$scanned=0;
  while($r=$q->fetch(PDO::FETCH_ASSOC)){audit_need(++$scanned<ENTRY_LIMIT,'entry_limit_incomplete');if($r['created_at']>=ENTRY_FROM&&$r['created_at']<ENTRY_UNTIL)$ids[]=(int)$r['id'];}
  $rows=[];$bytes=0;
  foreach(array_chunk($ids,500) as $chunk){audit_need(microtime(true)<$deadline,'wall_budget');
   $marks=implode(',',array_fill(0,count($chunk),'?'));
   $sql="SELECT /*+ MAX_EXECUTION_TIME(3000) */ pe.id AS entry_id,pe.conversation_id,pe.publication_id,pe.created_at AS entered_at,pp.external_message_id,pc.platform,pc.channel_id,
    CASE WHEN pp.id IS NOT NULL AND v.id IS NOT NULL AND p.project_key='anytour' AND pc.platform=c.channel AND pp.source_id=pc.source_id AND src.project_id=pc.project_id THEN 1 ELSE 0 END AS binding_valid
    FROM post_entries pe FORCE INDEX (PRIMARY) JOIN conversations c ON c.id=pe.conversation_id
    LEFT JOIN post_publications pp ON pp.id=pe.publication_id LEFT JOIN post_channels pc ON pc.id=pp.post_channel_id
    LEFT JOIN projects p ON p.id=pc.project_id LEFT JOIN conversation_sources src ON src.id=pp.source_id
    LEFT JOIN post_versions v ON v.id=pe.version_id AND v.publication_id=pp.id
    WHERE pe.id IN ($marks) AND c.project_key='anytour' AND c.is_test=0 AND c.channel IN ('max','telegram') ORDER BY pe.id";
   $q=$pdo->prepare('EXPLAIN '.$sql);$q->execute($chunk);audit_plan($q->fetchAll(PDO::FETCH_ASSOC));
   audit_need(microtime(true)<$deadline,'wall_budget');$q=$pdo->prepare($sql);$q->execute($chunk);$batch=0;
   while($r=$q->fetch(PDO::FETCH_ASSOC)){audit_need(++$batch<=count($chunk),'join_multiplication');$bytes+=strlen(json_encode($r,JSON_THROW_ON_ERROR));audit_need($bytes<=4194304,'selected_byte_limit');$rows[]=$r;}
  }
  $out=audit_project($rows,$targets);$out['publications']=$publications;$out['coverage']['global_entry_rows_scanned']=$scanned;$out['coverage']['global_window_entries']=count($ids);audit_need(strlen(json_encode($out,JSON_THROW_ON_ERROR))<=2097152,'output_byte_limit');$pdo->rollBack();return $out;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$safe=['unsupported_server_version','wall_budget','entry_limit_incomplete','selected_byte_limit','output_byte_limit','targets_scope','target_channel_unbound','target_ambiguous','metadata_size','capped_primary_plan','join_multiplication','entry_duplicate','entry_clock','platform','explain_shape','explain_rows_unknown','explain_dependent','unbounded_base_access','explain_full_scan','explain_cost_or_missing_base'];throw new RuntimeException(in_array($e->getMessage(),$safe,true)?$e->getMessage():'aggregate_read_failed');}
}

try {
 if(!ADMISSION_OPEN||!is_array(REVIEWED_TARGETS))throw new RuntimeException('targets_closed');
 runtime_check();ob_start();try{require getcwd().'/config.php';require_once getcwd().'/services/ConversationDb.php';}finally{ob_end_clean();}
 $aggregate=audit_read(ConversationDb::connection(),REVIEWED_TARGETS);runtime_check();
 $out=['ok'=>true,'installed_sha'=>EXPECTED_RUNTIME,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0,'aggregate'=>$aggregate];
 $json=json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 if(strlen($json)>2097152)throw new RuntimeException('output_limit');echo $json,"\n";
}catch(Throwable $e){$safe=['unsupported_server_version','wall_budget','targets_closed','runtime_changed','runtime_dirty','db_source_changed','entry_limit_incomplete','selected_byte_limit','output_byte_limit','target_ambiguous','capped_primary_plan','join_multiplication','explain_shape','explain_rows_unknown','explain_dependent','unbounded_base_access','explain_full_scan','explain_cost_or_missing_base'];echo json_encode(['ok'=>false,'error'=>in_array($e->getMessage(),$safe,true)?$e->getMessage():'bounded_read_stopped']),"\n";exit(1);}
