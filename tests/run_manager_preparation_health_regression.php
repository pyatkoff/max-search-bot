<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerPreparationHealth.php';

$tmp=sys_get_temp_dir().'/preparation-health-'.bin2hex(random_bytes(6));mkdir($tmp,0700);
DiagnosticLogger::setFile($tmp.'/structured.log');$file=$tmp.'/manager_preparation_health.json';$passed=0;
function healthCheck(string $name,bool $ok): void {
    global $passed;if (!$ok) throw new RuntimeException('FAIL '.$name);++$passed;echo 'PASS '.$name."\n";
}
try {
    healthCheck('missing checkpoint is unknown, never a healthy empty queue',ManagerPreparationHealth::snapshot()['available']===false);
    ManagerPreparationHealth::record('running','bootstrap');
    $s=ManagerPreparationHealth::snapshot();
    healthCheck('start is visible before any config or database work',$s['state']==='running' && !$s['stale'] && $s['last_finished_at']===null);
    healthCheck('checkpoint is private and outside application root',(fileperms($file)&0777)===0600 && !str_starts_with($file,dirname(__DIR__).'/'));
    ManagerPreparationHealth::record('ok','complete',['captured'=>0,'accepted'=>0,'secret'=>'PRIVATE TOKEN','payload'=>['text'=>'PRIVATE TEXT'],'retried'=>-1]);
    $s=ManagerPreparationHealth::snapshot();$success=$s['last_success_at'];
    healthCheck('successful empty pass is distinct from an unobserved pass',$s['state']==='ok' && $success!==null && $s['counts']===['captured'=>0,'accepted'=>0]);
    healthCheck('writer excludes sensitive/unknown fields',!str_contains((string)file_get_contents($file),'PRIVATE'));
    ManagerPreparationHealth::record('running','bootstrap');ManagerPreparationHealth::record('failed','collection');
    $s=ManagerPreparationHealth::snapshot();
    healthCheck('failure records its stage without erasing last successful run',$s['state']==='failed' && $s['last_error_stage']==='collection' && $s['last_error_at']!==null && $s['last_success_at']===$success);
    ManagerPreparationHealth::record('awaiting_connection','subscription');$s=ManagerPreparationHealth::snapshot();
    healthCheck('missing connection is explicit and preserves historical error/success',$s['state']==='awaiting_connection' && $s['last_success_at']===$success && $s['last_error_stage']==='collection');
    healthCheck('stale completed pass is never fresh',ManagerPreparationHealth::snapshot(time()+181)['stale']===true);
    healthCheck('future checkpoint is never fresh',ManagerPreparationHealth::snapshot(time()-10)['stale']===true);
    $raw=file_get_contents($file);ManagerPreparationHealth::record('PRIVATE STATE','PRIVATE STAGE');
    healthCheck('invalid categories cannot enter checkpoint',file_get_contents($file)===$raw);
    $injected=json_decode($raw,true)+['token'=>'PRIVATE TOKEN','callback_url'=>'PRIVATE CALLBACK','draft'=>'PRIVATE DRAFT'];
    $injected['counts']['conversation_id']=123;$injected['counts']['text']='PRIVATE TEXT';
    file_put_contents($file,json_encode($injected));$published=json_encode(ManagerPreparationHealth::snapshot());
    healthCheck('reader re-allowlists stored fields',!str_contains($published,'PRIVATE') && !str_contains($published,'conversation_id'));
    file_put_contents($file,str_repeat('x',8193));
    healthCheck('oversized checkpoint is unknown',ManagerPreparationHealth::snapshot()['available']===false);
    file_put_contents($file,'{broken');
    healthCheck('truncated checkpoint is unknown',ManagerPreparationHealth::snapshot()['available']===false);
    unlink($file);file_put_contents($tmp.'/sentinel','unchanged');symlink($tmp.'/sentinel',$file);
    ManagerPreparationHealth::record('running','bootstrap');
    healthCheck('symlink is neither read nor overwritten',ManagerPreparationHealth::snapshot()['available']===false && file_get_contents($tmp.'/sentinel')==='unchanged');
    unlink($file);unlink($tmp.'/sentinel');

    // Execute the actual CLI in an isolated synthetic application, including bootstrap failures.
    $app=$tmp.'/app';mkdir($app,0700);mkdir($app.'/tools',0700);mkdir($app.'/services',0700);
    copy(dirname(__DIR__).'/tools/manager_prepared_replies.php',$app.'/tools/manager_prepared_replies.php');
    foreach (['ManagerPreparationHealth','DiagnosticLogger'] as $name) copy(dirname(__DIR__).'/services/'.$name.'.php',$app.'/services/'.$name.'.php');
    foreach (['ManagerDraftPilotCollector','ManagerPreparedReplyService','ManagerPreparationMcpService'] as $name) file_put_contents($app.'/services/'.$name.'.php','<?php');
    $fixture= <<<'PHP'
<?php
$mode=$argv[0]; // mode is supplied by an isolated fixture file, never a production option.
if (is_file(__DIR__.'/fail')) throw new RuntimeException('PRIVATE BOOTSTRAP ERROR');
class ConversationDb {public static function connection(){return new class {function prepare($sql){return new class {function execute($args){} function fetchAll($mode){return [1];}};}};}}
class ProjectAccessService {public static function initializeReadOnly(){}}
class ProjectConfig {public static function projectId(){return 'anytour';}}
class ManagerPreparationMcpService {public function hasActiveSubscriptions(){return !is_file(__DIR__.'/waiting');}public function deliverPending(){return ['subscriptions'=>1,'accepted'=>0,'retried'=>0,'stopped'=>0,'unchanged'=>1];}}
class ManagerDraftPilotCollector {public static function collect($pdo,$cohort,$limit){return ['ok'=>true,'candidate_count'=>0,'exported_count'=>0,'conversations'=>[]];}}
class ManagerPreparedReplyService {public static function enqueue($rows){return ['ok'=>true,'inserted'=>0,'existing'=>0,'skipped'=>0];}}
PHP;
    file_put_contents($app.'/config.php',$fixture);
    $checkpoint=$tmp.'/max-search-bot-private-logs/manager_preparation_health.json';
    foreach (['ok'=>0,'waiting'=>0,'fail'=>1] as $mode=>$expected) {
        if ($mode!=='ok') file_put_contents($app.'/'.$mode,'1');
        $out=[];$code=-1;exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($app.'/tools/manager_prepared_replies.php').' tick 2>&1',$out,$code);
        $row=json_decode((string)file_get_contents($checkpoint),true);
        $state=['ok'=>'ok','waiting'=>'awaiting_connection','fail'=>'failed'][$mode];
        healthCheck('real CLI records '.$mode.' without exposing private error',$code===$expected && ($row['state']??null)===$state && !str_contains(implode("\n",$out),'PRIVATE'));
        if ($mode==='fail') healthCheck('real CLI catches configuration failure at bootstrap',($row['stage']??null)==='bootstrap');
        if ($mode!=='ok') unlink($app.'/'.$mode);
    }
    echo 'TOTAL '.$passed." PASS\n";
} finally {
    DiagnosticLogger::setFile('');
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());else unlink($entry->getPathname());}
    rmdir($tmp);
}
