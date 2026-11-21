<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
$app=dirname(__DIR__,4);
require_once $app.'/tools/max2_common_value_state_v2.php';
require_once $app.'/services/Max2CommonValueStateV2.php';
$migration=file_get_contents($app.'/migrations/113_max2_common_runtime_v2.sql');
if (!is_string($migration) || hash('sha256',$migration)!=='092c123615aae8261c19b8aa7316b0631926698906491c03869f28373646ada9')
    throw new RuntimeException('EXACT_MIGRATION_113_REQUIRED');
$checks=0;
function stateCliCheck(bool $ok,string $label): void
{
    global $checks; $checks++; if (!$ok) throw new RuntimeException('ASSERTION_FAILED_'.$label);
}
function stateCliReject(callable $call,string $label): void
{
    $rejected=false; try { $call(); } catch (DomainException $expected) { $rejected=true; }
    stateCliCheck($rejected,$label);
}
final class StateCliMemoryPdo extends PDO
{
    public bool $loseAfterCommit=false;
    public function commit(): bool
    {
        $result=parent::commit();
        if ($this->loseAfterCommit) { $this->loseAfterCommit=false; throw new PDOException('synthetic lost commit acknowledgement'); }
        return $result;
    }
}
function stateCliDb(): StateCliMemoryPdo
{
    global $migration;
    $db=new StateCliMemoryPdo('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $db->sqliteCreateFunction('OCTET_LENGTH',static fn($v): int=>strlen((string)$v),1); $db->exec('PRAGMA foreign_keys=ON');
    $sql=str_replace(' CHARACTER SET ascii COLLATE ascii_bin','',$migration);
    $sql=str_replace('id TINYINT UNSIGNED NOT NULL,','id INTEGER PRIMARY KEY,',$sql);
    $sql=preg_replace('/^\s*PRIMARY KEY \(id\),\n/m','',$sql);
    $sql=preg_replace('/UNIQUE KEY ([a-z0-9_]+) \(/','CONSTRAINT $1 UNIQUE (',$sql);
    $sql=preg_replace('/^\s*KEY [a-z0-9_]+ \([^\n]+\),\n/m','',$sql);
    $sql=str_replace(') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',');',$sql);
    $db->exec($sql);
    $db->exec('CREATE TABLE modeled_conversion_profile_versions(id INTEGER PRIMARY KEY,project_key TEXT NOT NULL,platform TEXT NOT NULL)');
    $db->exec("INSERT INTO modeled_conversion_profile_versions VALUES(10,'anytour','max'),(20,'anytour','telegram'),(999,'other','max')");
    $db->exec('CREATE TABLE max_channel_conversion_outbox(id INTEGER PRIMARY KEY,value_rub INTEGER,target TEXT)');
    $db->exec("INSERT INTO max_channel_conversion_outbox VALUES(1,300,'anytour_modeled_value_v1')");
    return $db;
}
function stateCliRequest(string $op='status',?string $epoch=null): array
{
    return ['version'=>1,'operation'=>$op,'project_key'=>'anytour','counter_id'=>98615635,'goal_id'=>687941321,
        'target'=>'anytour_modeled_value_v2_max2','source_sha'=>sha1('synthetic installed app source'),'expected_epoch'=>$epoch];
}
function stateCliParse(array $request,bool $newline=false): array
{
    $raw=json_encode($request,JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).($newline?"\n":'');
    return Max2CommonValueStateCliV2::parse(['synthetic-tool',$request['operation'],$request['source_sha'],hash('sha256',$raw)],$raw);
}
function stateCliTables(): array
{
    return Max2CommonValueStateCliV2::observedTables(array_map(static fn(string $name): array=>['table_name'=>$name,'engine'=>'InnoDB'],Max2CommonValueStateCliV2::TABLES));
}
function stateCliDispatch(Max2CommonValueStateV2 $state,array $request,?array $tables=null): array
{
    $parsed=stateCliParse($request);
    return Max2CommonValueStateCliV2::dispatch($parsed['request'],$parsed['request_sha256'],$request['source_sha'],$tables ?? stateCliTables(),$state);
}

$request=stateCliRequest(); $parsed=stateCliParse($request);
stateCliCheck($parsed['request']===$request && strlen($parsed['request_sha256'])===64,'exact_eight_key_request');
stateCliCheck(stateCliParse($request,true)['request']===$request,'one_final_newline_is_hashed_and_supported');
foreach (['old_goal','old_target','wrong_counter','wrong_project','unknown_operation','extra_grant','source_format','wrong_version'] as $case) {
    $r=$request;
    if ($case==='old_goal') $r['goal_id']=678187097;
    if ($case==='old_target') $r['target']='anytour_modeled_value_v1';
    if ($case==='wrong_counter') $r['counter_id']=1;
    if ($case==='wrong_project') $r['project_key']='other';
    if ($case==='unknown_operation') $r['operation']='tick';
    if ($case==='extra_grant') $r['approved']=true;
    if ($case==='source_format') $r['source_sha']='main';
    if ($case==='wrong_version') $r['version']='1';
    stateCliReject(static fn()=>stateCliParse($r),'request_rejects_'.$case);
}
stateCliReject(static fn()=>stateCliParse(stateCliRequest('send')),'mode_requires_actual_epoch');
stateCliReject(static fn()=>stateCliParse(stateCliRequest('activate',str_repeat('a',64))),'activate_cannot_supply_existing_epoch');
$raw=json_encode($request,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
stateCliReject(static fn()=>Max2CommonValueStateCliV2::parse(['tool','status',$request['source_sha'],str_repeat('0',64)],$raw),'wrong_raw_request_sha_rejected');
stateCliReject(static fn()=>Max2CommonValueStateCliV2::parse(['tool','status',sha1('other source'),hash('sha256',$raw)],$raw),'expected_source_bound_to_request');
stateCliReject(static fn()=>Max2CommonValueStateCliV2::parse(['tool','status',$request['source_sha'],hash('sha256',$raw),'--admitted'],$raw),'invented_grant_argument_rejected');
$duplicate='{"version":0,'.substr($raw,1);
stateCliReject(static fn()=>Max2CommonValueStateCliV2::parse(['tool','status',$request['source_sha'],hash('sha256',$duplicate)],$duplicate),'duplicate_json_keys_rejected');
$pretty=json_encode($request,JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
stateCliReject(static fn()=>Max2CommonValueStateCliV2::parse(['tool','status',$request['source_sha'],hash('sha256',$pretty)],$pretty),'noncanonical_request_rejected');
$oversize=str_repeat(' ',8193);
stateCliReject(static fn()=>Max2CommonValueStateCliV2::parse(['tool','status',$request['source_sha'],hash('sha256',$oversize)],$oversize),'request_size_bound');

// Only synthetic local Git metadata, never the real checkout HEAD or a shell.
$tmp=sys_get_temp_dir().'/max2-state-cli-'.bin2hex(random_bytes(12));
if (!mkdir($tmp,0700)) throw new RuntimeException('SYNTHETIC_TEMP_FAILED');
try {
    $ordinary=$tmp.'/ordinary'; mkdir($ordinary.'/.git/refs/heads',0700,true);
    file_put_contents($ordinary.'/.git/HEAD',"ref: refs/heads/main\n");
    file_put_contents($ordinary.'/.git/refs/heads/main',$request['source_sha']."\n");
    stateCliCheck(Max2CommonValueStateCliV2::installedHead($ordinary)===$request['source_sha'],'actual_loose_git_head_read');
    unlink($ordinary.'/.git/refs/heads/main');
    file_put_contents($ordinary.'/.git/packed-refs',"# pack-refs with: peeled\n".$request['source_sha']." refs/heads/main\n".sha1('other')." refs/heads/main-other\n");
    stateCliCheck(Max2CommonValueStateCliV2::installedHead($ordinary)===$request['source_sha'],'packed_ref_exact_name');
    file_put_contents($ordinary.'/.git/packed-refs',$request['source_sha']." refs/heads/main\n".sha1('other')." refs/heads/main\n");
    stateCliReject(static fn()=>Max2CommonValueStateCliV2::installedHead($ordinary),'duplicate_packed_target_is_unknown');
    file_put_contents($ordinary.'/.git/HEAD',$request['source_sha']."\n");
    stateCliCheck(Max2CommonValueStateCliV2::installedHead($ordinary)===$request['source_sha'],'detached_installed_head');
    file_put_contents($ordinary.'/.git/HEAD',"ref: refs/heads/../../private\n");
    stateCliReject(static fn()=>Max2CommonValueStateCliV2::installedHead($ordinary),'git_ref_traversal_rejected');
    $worktree=$tmp.'/worktree'; mkdir($worktree,0700);
    mkdir($tmp.'/git-store/worktrees/one',0700,true); mkdir($tmp.'/git-store/refs/heads',0700,true);
    file_put_contents($worktree.'/.git',"gitdir: ../git-store/worktrees/one\n");
    file_put_contents($tmp.'/git-store/worktrees/one/HEAD',"ref: refs/heads/main\n");
    file_put_contents($tmp.'/git-store/worktrees/one/commondir',"../..\n");
    file_put_contents($tmp.'/git-store/refs/heads/main',$request['source_sha']."\n");
    stateCliCheck(Max2CommonValueStateCliV2::installedHead($worktree)===$request['source_sha'],'actual_worktree_common_directory_ref');
    file_put_contents($tmp.'/git-store/refs/heads/main',sha1('changed deployment')."\n");
    stateCliCheck(Max2CommonValueStateCliV2::installedHead($worktree)!==$request['source_sha'],'changed_installed_source_is_observed');
} finally {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($tmp);
}

$rows=array_map(static fn(string $name): array=>['table_name'=>$name,'engine'=>'InnoDB'],Max2CommonValueStateCliV2::TABLES);
$tables=Max2CommonValueStateCliV2::observedTables(array_reverse($rows));
stateCliCheck($tables['status']==='OBSERVED_TABLES_READY' && $tables['observed_count']===7 && $tables['full_schema_validation']===false,'bounded_presence_engine_not_full_schema_claim');
$bad=$rows; array_pop($bad);
stateCliCheck(Max2CommonValueStateCliV2::observedTables($bad)['status']==='TABLES_UNAVAILABLE','missing_owned_table_unready');
$bad=$rows; $bad[0]['engine']='MyISAM';
stateCliCheck(Max2CommonValueStateCliV2::observedTables($bad)['status']==='TABLES_UNAVAILABLE','wrong_storage_engine_unready');
$bad=$rows; $bad[0]=$bad[1];
stateCliCheck(Max2CommonValueStateCliV2::observedTables($bad)['status']==='TABLES_UNAVAILABLE','duplicate_table_not_complete_set');
$bad=$rows; $bad[]=['table_name'=>'other','engine'=>'InnoDB'];
stateCliCheck(Max2CommonValueStateCliV2::observedTables($bad)['status']==='TABLES_UNAVAILABLE','extra_table_not_admitted');

$db=stateCliDb(); $clock=1795263000;
$state=new Max2CommonValueStateV2($db,static function() use (&$clock): int { return $clock; },true);
$status=stateCliDispatch($state,$request);
stateCliCheck($status['status']==='OBSERVED' && $status['ok'] && $status['state']['mode']==='off'
    && $status['state']['activation']===null && !$status['mutation_attempted'] && $status['readback'],'status_observes_default_off');
stateCliCheck($status['provider_calls']===0 && !$status['tick_invoked'] && !$status['v1_invoked'],'status_has_no_business_dispatch');
stateCliReject(static fn()=>Max2CommonValueStateCliV2::dispatch($request,$parsed['request_sha256'],sha1('wrong actual HEAD'),$tables,$state),'source_mismatch_before_state_mutation');
$notReady=Max2CommonValueStateCliV2::observedTables([]);
$held=stateCliDispatch($state,stateCliRequest('activate'),$notReady);
stateCliCheck($held['status']==='HOLD' && !$held['mutation_attempted'] && $state->settings()['mode']==='off','missing_schema_presence_stops_before_activation');
$made=stateCliDispatch($state,stateCliRequest('activate')); $epoch=$made['state']['activation']['activation_sha256'];
stateCliCheck($made['status']==='ACTIVATED' && $made['ok'] && $made['state']['mode']==='collect' && $made['readback']
    && $made['state']['cursor']==='20' && $made['state']['activation']['activated_at_unix']===$clock,'real_state_activation_samples_global_profile_max');
$db->exec("INSERT INTO modeled_conversion_profile_versions VALUES(107,'anytour','max')"); $clock=1795263660;
stateCliCheck(stateCliDispatch($state,stateCliRequest('activate'))['status']==='HOLD'
    && $state->settings()['cursor']==='20','repeat_activation_does_not_reset_epoch_or_floor');
stateCliCheck(stateCliDispatch($state,stateCliRequest('status'))['state']['activation']['activation_sha256']===$epoch,'status_can_discover_existing_epoch');
stateCliCheck(stateCliDispatch($state,stateCliRequest('status',str_repeat('a',64)))['status']==='HOLD','status_can_require_exact_epoch');
stateCliCheck(stateCliDispatch($state,stateCliRequest('send',str_repeat('a',64)))['status']==='HOLD'
    && $state->settings()['mode']==='collect','wrong_epoch_cannot_enable_send');
foreach (['send','off','collect'] as $mode) {
    $changed=stateCliDispatch($state,stateCliRequest($mode,$epoch));
    stateCliCheck($changed['status']==='MODE_SET' && $changed['ok'] && $changed['state']['mode']===$mode
        && $changed['state']['activation']['activation_sha256']===$epoch && $changed['state']['cursor']==='20'
        && $changed['readback'] && !$changed['automatic_retry'],'finite_mode_'.$mode.'_preserves_epoch_and_floor');
}
$tick=$state->beginTick($clock);
$held=stateCliDispatch($state,stateCliRequest('off',$epoch));
stateCliCheck($tick['status']==='ACQUIRED' && $held['status']==='HOLD' && $held['reason']==='ACTIVE_TICK_MODE_TRANSITION_HELD'
    && $state->settings()['mode']==='collect','active_native_state_lease_protects_transition');
stateCliCheck((int)$db->query('SELECT value_rub FROM max_channel_conversion_outbox')->fetchColumn()===300,'old_v1_sentinel_unchanged');

$db=stateCliDb(); $clock=1795263000;
$state=new Max2CommonValueStateV2($db,static function() use (&$clock): int { return $clock; },true);
$db->loseAfterCommit=true; $unknown=stateCliDispatch($state,stateCliRequest('activate'));
stateCliCheck($unknown['status']==='UNKNOWN' && !$unknown['ok'] && !$unknown['automatic_retry'] && $unknown['mutation_attempted']
    && $unknown['state']['mode']==='collect' && $unknown['readback'],'unknown_commit_not_promoted_by_same_call_readback');
$epoch=$unknown['state']['activation']['activation_sha256'];
stateCliCheck(stateCliDispatch($state,stateCliRequest('activate'))['status']==='HOLD','unknown_activation_no_blind_repeat');
$clock=1795263660; $db->loseAfterCommit=true;
$unknown=stateCliDispatch($state,stateCliRequest('send',$epoch));
stateCliCheck($unknown['status']==='UNKNOWN' && !$unknown['ok'] && !$unknown['automatic_retry']
    && $unknown['state']['mode']==='send' && $unknown['state']['activation']['activation_sha256']===$epoch,'mode_commit_unknown_stays_unknown');
stateCliCheck(stateCliDispatch($state,stateCliRequest('status',$epoch))['status']==='OBSERVED','separate_status_observes_actual_state_without_repeating_mode');

echo 'PASS ',$checks," synthetic CLI/state admission assertions; local Git fixtures and SQLite-memory only; no real config, SSH authorization, HTTP/provider or production activation tested\n";
