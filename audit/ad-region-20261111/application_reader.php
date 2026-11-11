<?php
declare(strict_types=1);

/* One-shot private operator reader. Stdout MUST be captured by the established
 * SSH runner and sealed there before any log/artifact publication. No web mode. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(90);
const AUDIT_SCHEMA = 'anytour-advertising-entry-inventory-v1';
const AUDIT_CAP = 20000;
const AUDIT_DIRECTORY_CAP = 1000;
const AUDIT_CONFIG_CAP = 5000;
const AUDIT_OUTPUT_CAP = 262144;
const AUDIT_URL_PATHS = [
    'messenger.bot_url', 'messenger.miniapp_bot_url',
    'messenger.channel_offer.max_url', 'messenger.channel_offer.telegram_url',
    'messenger.telegram.bot_url', 'messenger.max.webhook_url',
    'channel_posts.max.bot_url', 'channel_posts.telegram.bot_url',
    'search.base_domain', 'search.tracking_base_domain',
    'website.base_url', 'website.widget_url',
];

function auditRequire(bool $condition): void { if (!$condition) throw new RuntimeException('AUDIT_FAILED'); }
function auditProject($key): bool { return is_string($key) && preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $key) === 1; }
function auditAt(array $settings, string $path) {
    $v = $settings;
    foreach (explode('.', $path) as $part) {
        if (!is_array($v) || !array_key_exists($part, $v)) return null;
        $v = $v[$part];
    }
    return $v;
}

/* Never export a live click ID, opaque start token, query value or fragment. */
function auditPayloadShape(string $value): string {
    $map = ['{yclid}'=>'QCLICK', '{region_id}'=>'QREGION', '{campaign_id}'=>'QCAMPAIGN', '{entry_channel}'=>'QENTRY'];
    $parts = explode('_', strtr($value, $map));
    $out = [];
    foreach ($parts as $part) {
        $out[] = match ($part) {
            'QCLICK'=>'{yclid}', 'QREGION'=>'{region_id}', 'QCAMPAIGN'=>'{campaign_id}', 'QENTRY'=>'{entry_channel}',
            'region','campaign','entry','r','c'=>$part,
            ''=>'', default=>preg_match('/\A[0-9]+\z/D', $part) ? '{numeric}' : '{opaque}',
        };
    }
    return substr(implode('_', $out), 0, 512);
}

function auditUrl($value): array {
    if ($value === null || $value === '') return ['present'=>false];
    if (!is_string($value) || strlen($value)>2048 || preg_match('/[\x00-\x20\x7f]/', $value)) return ['present'=>true,'valid_public_url'=>false];
    $u = parse_url($value);
    if (!is_array($u) || !in_array(strtolower((string)($u['scheme']??'')), ['http','https'], true)
        || isset($u['user']) || isset($u['pass']) || isset($u['port'])) return ['present'=>true,'valid_public_url'=>false];
    $host = strtolower((string)($u['host']??''));
    if (!preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/D', $host) || !str_contains($host,'.')
        || filter_var($host,FILTER_VALIDATE_IP) || str_ends_with($host,'.local') || str_ends_with($host,'.internal')) return ['present'=>true,'valid_public_url'=>false];
    $path = (string)($u['path']??'/');
    // Public bot endpoints are retained; other paths are represented structurally.
    $bot = in_array($host,['max.ru','t.me','telegram.me'],true)
        && preg_match('~\A/[A-Za-z0-9_]{1,187}_bot/?\z~iD',$path);
    $safePath = $bot || $path==='/' || $path==='' ? $path : '/{configured_path}';
    $out=['present'=>true,'valid_public_url'=>true,'endpoint'=>strtolower($u['scheme']).'://'.$host.$safePath,
        'path_redacted'=>!($bot||$path==='/'||$path===''),'fragment_omitted'=>isset($u['fragment']),'query'=>[]];
    foreach (explode('&',(string)($u['query']??'')) as $pair) {
        if ($pair==='') continue;
        [$k,$v]=array_pad(explode('=',$pair,2),2,''); $k=rawurldecode($k); $v=rawurldecode($v);
        $key=preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D',$k)?$k:'{invalid_key}';
        $entry=['key'=>$key,'value_omitted'=>true];
        if (in_array(strtolower($key),['start','startapp'],true)) {
            $entry += ['payload_shape'=>auditPayloadShape($v),'region_placeholder'=>str_contains($v,'{region_id}'),
                'campaign_placeholder'=>str_contains($v,'{campaign_id}'),'region_marker'=>(bool)preg_match('/(?:^|_)region_/', $v),
                'campaign_marker'=>(bool)preg_match('/(?:^|_)campaign_/', $v)];
        }
        $out['query'][]=$entry;
        auditRequire(count($out['query'])<=32);
    }
    return $out;
}

function auditSettings(string $origin, string $key, $settings, $bot=null, ?bool $tokenPresent=null): array {
    $valid=is_array($settings)&&($settings['id']??null)===$key;
    $out=['origin'=>$origin,'project_key'=>$key,'settings_scope_valid'=>$valid,'links'=>[]];
    if (is_string($bot)&&preg_match('/\A[A-Za-z0-9_]{1,191}\z/D',$bot)) $out['public_bot_key']=$bot;
    if ($tokenPresent!==null) $out['token_present']=$tokenPresent;
    if ($valid) foreach(AUDIT_URL_PATHS as $path) $out['links'][$path]=auditUrl(auditAt($settings,$path));
    return $out;
}

function auditRows(PDO $pdo,string $sql,array $args,int $cap): array {
    auditRequire(str_starts_with(ltrim($sql),'SELECT '));
    $q=$pdo->prepare($sql);$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
    auditRequire(count($rows)<=$cap);return $rows;
}

function auditColumns(PDO $pdo,string $table): array {
    $rows=auditRows($pdo,'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table],300);
    return array_fill_keys(array_map('strtolower',array_column($rows,'COLUMN_NAME')),true);
}

function auditSchema(PDO $pdo,string $table,array $columns,bool $required=true): bool {
    $actual=auditColumns($pdo,$table);
    if (!$actual&&!$required) return false;
    foreach($columns as $column) auditRequire(isset($actual[$column]));
    return true;
}

/* Bounded field projection, preserving types. Region names are never selected. */
function auditReceiptExpr(string $json,string $path): string {
    $pairs=[];
    foreach(['version','yandex_region_id','name','kind','source','platform','recorded_at_unix','receipt_sha256'] as $field) {
        $get="JSON_EXTRACT($json,'$path.$field')";
        if ($field==='name') $value="CASE WHEN JSON_TYPE($get)='STRING' AND OCTET_LENGTH(JSON_UNQUOTE($get))<=400 THEN '' ELSE NULL END";
        elseif (in_array($field,['version','recorded_at_unix'],true)) $value="CASE WHEN JSON_TYPE($get)='INTEGER' THEN CAST(JSON_UNQUOTE($get) AS SIGNED) ELSE NULL END";
        else $value="CASE WHEN JSON_TYPE($get)='STRING' AND OCTET_LENGTH(JSON_UNQUOTE($get))<=64 THEN JSON_UNQUOTE($get) ELSE NULL END";
        $pairs[]="'$field'";$pairs[]=$value;
    }
    return 'JSON_OBJECT('.implode(',',$pairs).')';
}

function auditUnixExpr(string $json,string $path): string {
    $get="JSON_EXTRACT($json,'$path')";
    return "CASE WHEN JSON_TYPE($get)='INTEGER' AND CAST(JSON_UNQUOTE($get) AS UNSIGNED) BETWEEN 946684800 AND 4102444800 THEN CAST(JSON_UNQUOTE($get) AS UNSIGNED) ELSE NULL END";
}

function auditBump(array &$groups,string $project,string $platform,string $source,string $counter): void {
    $key=json_encode([$project,$platform,$source],JSON_THROW_ON_ERROR);
    if (!isset($groups[$key])) $groups[$key]=['project_key'=>$project,'platform'=>$platform,'first_source_kind'=>$source,'counts'=>[]];
    $groups[$key]['counts'][$counter]=($groups[$key]['counts'][$counter]??0)+1;
}

function auditSha(string $root): string {
    $p=proc_open(['/usr/bin/git','-C',$root,'rev-parse','--verify','HEAD'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    auditRequire(is_resource($p));fclose($pipes[0]);$out=stream_get_contents($pipes[1],256);fclose($pipes[1]);stream_get_contents($pipes[2],4096);fclose($pipes[2]);
    auditRequire(proc_close($p)===0);$sha=trim((string)$out);auditRequire((bool)preg_match('/\A[a-f0-9]{40}\z/D',$sha));return $sha;
}

function auditCollect(string $root,string $sha,string $nonce): array {
    auditRequire(is_dir($root)&&is_file($root.'/config.php')&&auditSha($root)===$sha);
    require_once $root.'/config.php';
    require_once $root.'/services/ConversationDb.php';
    require_once $root.'/services/ProjectConfig.php';
    require_once $root.'/services/ConversationEntryRegionService.php';
    require_once $root.'/services/ConversationRecordClock.php';
    auditRequire(ConversationDb::isConfigured());
    $pdo=ConversationDb::connection();$GLOBALS['audit_database_opened']=true;auditRequire($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql');
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    try {
        auditSchema($pdo,'projects',['id','project_key','is_active']);
        auditSchema($pdo,'conversation_sources',['id','project_id','source_key','channel','is_active','handling_mode','suppress_channel_offer']);
        auditSchema($pdo,'conversations',['id','project_key','channel','is_test','metadata_json']);
        auditSchema($pdo,'conversation_events',['id','conversation_id','event_type','payload_json']);
        $hasClients=auditSchema($pdo,'project_client_connections',['project_id','bot_key','settings_json'],false);
        $hasChannels=auditSchema($pdo,'post_channels',['id','project_id','source_id','platform','bot_key','bot_url','is_enabled','config_version'],false);
        $now=time();$cutoff=$now-14*86400;
        $out=['schema'=>AUDIT_SCHEMA,'status'=>'COLLECTED','request_nonce'=>$nonce,'application_sha'=>$sha,'collected_at_unix'=>$now,
            'database_opened'=>true,'database_changed'=>false,'server_writes'=>false,'advertising_writes'=>false,'token_exported'=>false,
            'customer_rows_exported'=>false,'customer_names_exported'=>false,'window'=>['days'=>14,'since_unix'=>$cutoff,'until_unix'=>$now,
                'basis'=>'validated_conversation_recording_or_entry_receipt_or_region_event_unix','bare_datetime_used'=>false],
            'limits'=>['cohort_rows_global'=>AUDIT_CAP,'directory_rows'=>AUDIT_DIRECTORY_CAP,'configuration_rows'=>AUDIT_CONFIG_CAP,'output_bytes'=>AUDIT_OUTPUT_CAP],
            'projects'=>[],'settings'=>[],'sources'=>[],'post_channels'=>[],'coverage'=>['db_clients_table'=>$hasClients,'post_channels_table'=>$hasChannels,
                'credential_readiness_checked'=>false,'effective_routes_checked'=>false,'external_ad_cabinet_inventory'=>false,'startapp_roundtrip_proven'=>false,
                'startapp_receipt_subtype_available'=>false,'invalid_protected_registry_keys'=>0], 'receipt_groups'=>[]];
        $out['projects']=auditRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(5000) */ id,project_key,is_active FROM projects ORDER BY id LIMIT 1001',[],AUDIT_DIRECTORY_CAP);
        foreach($out['projects'] as &$p){auditRequire(auditProject($p['project_key']));$p['id']=(int)$p['id'];$p['is_active']=(bool)$p['is_active'];}unset($p);
        if($hasClients){
            $json="CASE WHEN JSON_VALID(c.settings_json) THEN c.settings_json ELSE '{}' END";
            $cols=["JSON_UNQUOTE(JSON_EXTRACT($json,'$.id')) AS settings_id",'JSON_VALID(c.settings_json) AS settings_valid'];
            foreach(AUDIT_URL_PATHS as $i=>$path)$cols[]="JSON_UNQUOTE(JSON_EXTRACT($json,'$.".$path."')) AS link".$i;
            $rows=auditRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(5000) */ p.project_key,c.bot_key,'.implode(',',$cols).' FROM project_client_connections c JOIN projects p ON p.id=c.project_id ORDER BY c.project_id LIMIT 1001',[],AUDIT_DIRECTORY_CAP);
            foreach($rows as $row){$settings=['id'=>$row['settings_id']];foreach(AUDIT_URL_PATHS as $i=>$path){$v=&$settings;foreach(explode('.',$path) as $part){if(!isset($v[$part])||!is_array($v[$part]))$v[$part]=[];$v=&$v[$part];}$v=$row['link'.$i];unset($v);}
                $entry=auditSettings('db_client_settings',$row['project_key'],$settings,$row['bot_key']);$entry['settings_json_valid']=(bool)$row['settings_valid'];$out['settings'][]=$entry;}
        }
        $legacy=ProjectConfig::all();$legacyKey=ProjectConfig::projectId();auditRequire(auditProject($legacyKey));
        $out['settings'][]=auditSettings('legacy_project_config',$legacyKey,$legacy,auditAt($legacy,'channel_posts.max.bot_key'),defined('MAX_SEARCH_TOKEN')&&is_string(MAX_SEARCH_TOKEN)&&MAX_SEARCH_TOKEN!=='');
        foreach(['MAX_SEARCH_PUBLIC_BASE_URL','MAX_SEARCH_TRACKING_BASE_URL','WEBSITE_WIDGET_URL'] as $constant) if(defined($constant)) $out['runtime_public_overrides'][$constant]=auditUrl(constant($constant));
        if(defined('MAX_SEARCH_PROJECT_CONNECTIONS')){
            auditRequire(is_array(MAX_SEARCH_PROJECT_CONNECTIONS)&&count(MAX_SEARCH_PROJECT_CONNECTIONS)<=AUDIT_DIRECTORY_CAP);
            foreach(array_keys(MAX_SEARCH_PROJECT_CONNECTIONS) as $key){
                if(!auditProject($key)){$out['coverage']['invalid_protected_registry_keys']++;continue;}
                $entry=MAX_SEARCH_PROJECT_CONNECTIONS[$key];
                $out['settings'][]=auditSettings('protected_runtime_registry',$key,is_array($entry)?($entry['project']??null):null,is_array($entry)?($entry['max']['bot_key']??null):null,
                    is_array($entry)&&is_string($entry['max']['token']??null)&&$entry['max']['token']!=='');
            }
        }
        $out['sources']=auditRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(5000) */ p.project_key,s.id,s.source_key,s.channel,s.is_active,s.handling_mode,s.suppress_channel_offer FROM conversation_sources s JOIN projects p ON p.id=s.project_id ORDER BY s.id LIMIT 5001',[],AUDIT_CONFIG_CAP);
        if($hasChannels){$rows=auditRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(5000) */ p.project_key,s.source_key,pc.id,pc.platform,pc.bot_key,pc.bot_url,pc.is_enabled,pc.config_version FROM post_channels pc JOIN projects p ON p.id=pc.project_id JOIN conversation_sources s ON s.id=pc.source_id AND s.project_id=pc.project_id ORDER BY pc.id LIMIT 5001',[],AUDIT_CONFIG_CAP);
            foreach($rows as $row){$row['public_bot_endpoint']=auditUrl($row['bot_url']);unset($row['bot_url']);$out['post_channels'][]=$row;}}
        $knownProjects=array_fill_keys(array_column($out['projects'],'project_key'),true);
        foreach($out['settings'] as $entry)$knownProjects[$entry['project_key']]=true;
        $unknownProjects=[];
        $j="CASE WHEN OCTET_LENGTH(c.metadata_json)<=262144 AND JSON_VALID(c.metadata_json) THEN c.metadata_json ELSE '{}' END";
        $creation=ConversationRecordClock::sql('c.metadata_json',true);
        $firstAt=auditUnixExpr($j,'$.first_entry_region.recorded_at_unix');$latestAt=auditUnixExpr($j,'$.entry_region.recorded_at_unix');
        $totals=auditRows($pdo,"SELECT /*+ MAX_EXECUTION_TIME(5000) */ c.project_key,c.channel,c.is_test,COUNT(*) AS row_count,SUM(CASE WHEN $creation IS NULL AND $firstAt IS NULL AND $latestAt IS NULL THEN 1 ELSE 0 END) AS rows_without_conversation_or_receipt_unix FROM conversations c GROUP BY c.project_key,c.channel,c.is_test ORDER BY c.project_key,c.channel,c.is_test LIMIT 5001",[],AUDIT_CONFIG_CAP);
        $safeTotals=[];
        foreach($totals as $row){
            if(!isset($knownProjects[$row['project_key']])){$unknownProjects[(string)$row['project_key']]=true;$row['project_key']='{unregistered_project}';}
            if(!in_array($row['channel'],['max','telegram','website'],true))$row['channel']='other';
            $key=json_encode([$row['project_key'],$row['channel'],(bool)$row['is_test']],JSON_THROW_ON_ERROR);
            if(!isset($safeTotals[$key]))$safeTotals[$key]=['project_key'=>$row['project_key'],'platform'=>$row['channel'],'is_test'=>(bool)$row['is_test'],'row_count'=>0,'rows_without_conversation_or_receipt_unix'=>0];
            $safeTotals[$key]['row_count']+=(int)$row['row_count'];$safeTotals[$key]['rows_without_conversation_or_receipt_unix']+=(int)$row['rows_without_conversation_or_receipt_unix'];
        }
        $out['all_stored_conversation_counts']=array_values($safeTotals);
        $eventClock=ConversationRecordClock::sql('s.payload_json',true);
        $eventJson="CASE WHEN OCTET_LENGTH(s.payload_json)<=262144 AND JSON_VALID(s.payload_json) THEN s.payload_json ELSE '{}' END";
        $firstEvent="(SELECT CASE WHEN JSON_TYPE(JSON_EXTRACT($eventJson,'$.receipt_sha256'))='STRING' AND OCTET_LENGTH(JSON_UNQUOTE(JSON_EXTRACT($eventJson,'$.receipt_sha256'))) =64 THEN JSON_UNQUOTE(JSON_EXTRACT($eventJson,'$.receipt_sha256')) ELSE '' END FROM conversation_events s WHERE s.conversation_id=c.id AND s.event_type='entry_region_observed' ORDER BY s.id LIMIT 1)";
        $metadataValid="CASE WHEN c.metadata_json IS NULL OR c.metadata_json='' THEN 1 WHEN OCTET_LENGTH(c.metadata_json)<=262144 AND JSON_VALID(c.metadata_json) AND JSON_TYPE($j) IN ('OBJECT','ARRAY') THEN 1 ELSE 0 END";
        $sql="SELECT /*+ MAX_EXECUTION_TIME(10000) */ c.project_key,c.channel,c.is_test,$metadataValid AS metadata_valid,JSON_CONTAINS_PATH($j,'one','$.first_entry_region') AS first_present,JSON_CONTAINS_PATH($j,'one','$.entry_region') AS latest_present,".auditReceiptExpr($j,'$.first_entry_region')." AS first_receipt,".auditReceiptExpr($j,'$.entry_region')." AS latest_receipt,$firstEvent AS first_event_digest FROM conversations c WHERE ($creation BETWEEN ? AND ?) OR ($firstAt BETWEEN ? AND ?) OR ($latestAt BETWEEN ? AND ?) OR EXISTS (SELECT 1 FROM conversation_events s WHERE s.conversation_id=c.id AND s.event_type='entry_region_observed' AND $eventClock BETWEEN ? AND ?) ORDER BY c.id DESC LIMIT 20001";
        $rows=auditRows($pdo,$sql,[$cutoff,$now,$cutoff,$now,$cutoff,$now,$cutoff,$now],AUDIT_CAP+1);
        $truncated=count($rows)>AUDIT_CAP;if($truncated)array_pop($rows);$groups=[];
        foreach($rows as $row){
            $project=(string)$row['project_key'];$platform=(string)$row['channel'];
            if(!isset($knownProjects[$project])){$unknownProjects[$project]=true;$project='{unregistered_project}';}
            if(!in_array($platform,['max','telegram','website'],true))$platform='other';
            $first=ConversationEntryRegionService::firstReceipt(json_encode(['first_entry_region'=>json_decode((string)$row['first_receipt'],true)],JSON_THROW_ON_ERROR),$platform);
            $latest=ConversationEntryRegionService::latestReceipt(json_encode(['entry_region'=>json_decode((string)$row['latest_receipt'],true)],JSON_THROW_ON_ERROR),$platform);
            $source=$first['source']??($row['first_present']?'invalid_first':'missing_first');$bump=static function(string $counter)use(&$groups,$project,$platform,$source):void{auditBump($groups,$project,$platform,$source,$counter);};
            $bump('cohort_rows');if((int)$row['is_test']){$bump('test_rows');continue;}$bump('non_test_rows');
            if(!$row['metadata_valid'])$bump('invalid_metadata');
            $bump($first?'valid_first':($row['first_present']?'invalid_first':'missing_first'));
            $bump($latest?'valid_latest':($row['latest_present']?'invalid_latest':'missing_latest'));
            if($latest&&!$first)$bump('valid_latest_without_valid_first');
            if($first&&$latest)$bump(hash_equals($first['receipt_sha256'],$latest['receipt_sha256'])?'first_equals_latest':'first_differs_from_latest');
            if($first){$d=$row['first_event_digest'];$bump($d===null?'first_event_missing':(!preg_match('/\A[a-f0-9]{64}\z/D',(string)$d)?'first_event_invalid_digest':(hash_equals($first['receipt_sha256'],$d)?'first_matches_earliest_region_event':'first_differs_from_earliest_region_event')));}
        }
        $out['receipt_groups']=array_values($groups);$out['coverage']['cohort_scanned_rows']=count($rows);$out['coverage']['cohort_truncated']=$truncated;
        $out['coverage']['clockless_rows_not_assigned_to_14day_window']=true;
        $out['coverage']['unregistered_conversation_project_count']=count($unknownProjects);
        $out['coverage']['all_projects_receipt_correctness_proven']=false;
        $out['coverage']['configuration_inventory_complete']=true;
        $out['coverage']['recent_receipt_cohort_complete']=!$truncated;
        if($truncated)$out['status']='PARTIAL';
        auditRequire(auditSha($root)===$sha);
        $pdo->rollBack();return $out;
    } finally {if($pdo->inTransaction())$pdo->rollBack();}
}

if (defined('AUDIT_LIBRARY_ONLY')&&AUDIT_LIBRARY_ONLY) return;
$bufferLevel=ob_get_level();$GLOBALS['audit_unexpected_output']=false;
ob_start(static function(string $chunk):string{if($chunk!=='')$GLOBALS['audit_unexpected_output']=true;return '';},4096);
$result=null;$GLOBALS['audit_database_opened']=false;
set_error_handler(static function():never{throw new RuntimeException('AUDIT_FAILED');});
try {
    $options=getopt('',['root:','expected-sha:','request-nonce:']);
    $root=$options['root']??null;$sha=$options['expected-sha']??null;$nonce=$options['request-nonce']??null;
    auditRequire(is_string($root)&&is_string($sha)&&is_string($nonce)&&preg_match('/\A[a-f0-9]{40}\z/D',$sha)&&preg_match('/\A[A-Za-z0-9_-]{24,128}\z/D',$nonce));
    $root=realpath($root);auditRequire(is_string($root));$result=auditCollect($root,$sha,$nonce);
    auditRequire(!$GLOBALS['audit_unexpected_output']&&ob_get_length()===0);
    $json=json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);auditRequire(strlen($json)<=AUDIT_OUTPUT_CAP);
} catch(Throwable $ignored) {
    $json=json_encode(['schema'=>AUDIT_SCHEMA,'status'=>'READ_FAILED','error_code'=>'APP_AUDIT_FAILED','request_nonce'=>is_string($nonce??null)&&preg_match('/\A[A-Za-z0-9_-]{24,128}\z/D',$nonce)?$nonce:null,'application_sha'=>is_string($sha??null)&&preg_match('/\A[a-f0-9]{40}\z/D',$sha)?$sha:null,'database_opened'=>$GLOBALS['audit_database_opened'],'database_changed'=>false,'server_writes'=>false,'advertising_writes'=>false,'token_exported'=>false],JSON_THROW_ON_ERROR);
} finally {restore_error_handler();while(ob_get_level()>$bufferLevel)ob_end_clean();}
fwrite(STDOUT,$json."\n");
exit(($result['status']??'')==='COLLECTED'?0:2);
