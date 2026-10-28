<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/DevelopmentWorkHealth.php';
require_once dirname(__DIR__).'/services/DevelopmentWorkCronVerifier.php';

$tmp=sys_get_temp_dir().'/development-health-'.bin2hex(random_bytes(6));mkdir($tmp,0700);
DiagnosticLogger::setFile($tmp.'/structured.log');$file=$tmp.'/development_work_health.json';$passed=0;
function developmentHealthCheck(string $name,bool $ok): void {
    global $passed;if (!$ok) throw new RuntimeException('FAIL '.$name);++$passed;echo 'PASS '.$name."\n";
}
try {
    developmentHealthCheck('missing tick is unknown',DevelopmentWorkHealth::snapshot()['available']===false);
    DevelopmentWorkHealth::record('running','bootstrap');$s=DevelopmentWorkHealth::snapshot();
    developmentHealthCheck('start captured before config or database work',$s['state']==='running'&&$s['trigger']==='manual'&&!$s['stale']&&$s['last_finished_at']===null);
    developmentHealthCheck('private external file',(fileperms($file)&0777)===0600&&!str_starts_with($file,dirname(__DIR__).'/'));
    DevelopmentWorkHealth::record('ok','complete',['subscriptions'=>0,'accepted'=>0,'queue_id'=>'PRIVATE QUEUE','secret'=>'PRIVATE TOKEN','retried'=>-1]);
    $s=DevelopmentWorkHealth::snapshot();$success=$s['last_success_at'];
    developmentHealthCheck('empty successful tick differs from missing tick',$s['state']==='ok'&&$success!==null&&$s['counts']===['subscriptions'=>0,'accepted'=>0]);
    developmentHealthCheck('no identity or credentials in checkpoint',!str_contains((string)file_get_contents($file),'PRIVATE'));
    DevelopmentWorkHealth::record('running','bootstrap');DevelopmentWorkHealth::record('failed','delivery');$s=DevelopmentWorkHealth::snapshot();
    developmentHealthCheck('fixed error stage preserves last success',$s['state']==='failed'&&$s['last_error_stage']==='delivery'&&$s['last_success_at']===$success);
    DevelopmentWorkHealth::record('awaiting_connection','subscription');$s=DevelopmentWorkHealth::snapshot();
    developmentHealthCheck('missing connection is explicit',$s['state']==='awaiting_connection'&&$s['last_error_stage']==='delivery');
    developmentHealthCheck('stale is not healthy',DevelopmentWorkHealth::snapshot(time()+181)['stale']===true);
    developmentHealthCheck('future timestamp is not healthy',DevelopmentWorkHealth::snapshot(time()-10)['stale']===true);
    $v=new DevelopmentWorkCronVerifier(1000);
    $sample=static fn(int $at,string $state='ok'):array=>['available'=>true,'stale'=>false,'state'=>$state,'trigger'=>'cron',
        'last_started_at'=>gmdate('Y-m-d\TH:i:s\Z',$at),'last_finished_at'=>gmdate('Y-m-d\TH:i:s\Z',$at+1)];
    developmentHealthCheck('cron verifier rejects a preexisting tick',$v->observe($sample(999),1100)===null);
    developmentHealthCheck('two future manual ticks cannot prove cron',
        $v->observe(array_replace($sample(1020),['trigger'=>'manual']),1025)===null
        &&$v->observe(array_replace($sample(1080),['trigger'=>'manual']),1085)===null);
    developmentHealthCheck('one future tick is not proof of recurrence',$v->observe($sample(1020,'awaiting_connection'),1025)===null);
    developmentHealthCheck('same tick read twice is not recurrence',$v->observe($sample(1020),1085)===null);
    $proof=$v->observe($sample(1080),1085);
    developmentHealthCheck('two completed future ticks prove observed minute interval',$proof['observed_interval_seconds']===60&&$proof['first_state']==='awaiting_connection');
    $v=new DevelopmentWorkCronVerifier(1000);$v->observe($sample(1020),1025);$rejected=false;
    try {$v->observe($sample(1140),1145);} catch(RuntimeException $e) {$rejected=$e->getMessage()==='development_cron_interval_out_of_bounds';}
    developmentHealthCheck('missed minute cannot be reported as minute proof',$rejected);
    $v=new DevelopmentWorkCronVerifier(1000);$rejected=false;
    try {$v->observe($sample(1020,'failed'),1025);} catch(RuntimeException $e) {$rejected=$e->getMessage()==='development_cron_worker_failed';}
    developmentHealthCheck('failed fresh worker fails scheduled proof',$rejected);
    $raw=file_get_contents($file);DevelopmentWorkHealth::record('PRIVATE','PRIVATE');
    developmentHealthCheck('unknown categories rejected',file_get_contents($file)===$raw);
    $injected=json_decode($raw,true)+['callback_url'=>'PRIVATE CALLBACK'];$injected['counts']['queue_id']='PRIVATE';
    file_put_contents($file,json_encode($injected));
    developmentHealthCheck('reader re-allowlists fields',!str_contains(json_encode(DevelopmentWorkHealth::snapshot()),'PRIVATE'));
    file_put_contents($file,str_repeat('x',8193));developmentHealthCheck('oversized is unknown',!DevelopmentWorkHealth::snapshot()['available']);
    file_put_contents($file,'{broken');developmentHealthCheck('truncated is unknown',!DevelopmentWorkHealth::snapshot()['available']);
    unlink($file);file_put_contents($tmp.'/sentinel','unchanged');symlink($tmp.'/sentinel',$file);DevelopmentWorkHealth::record('running','bootstrap');
    developmentHealthCheck('symlink neither read nor overwritten',!DevelopmentWorkHealth::snapshot()['available']&&file_get_contents($tmp.'/sentinel')==='unchanged');
    unlink($file);unlink($tmp.'/sentinel');

    // Execute the real CLI in a synthetic application; no production bootstrap.
    $app=$tmp.'/app';mkdir($app,0700);mkdir($app.'/tools',0700);mkdir($app.'/services',0700);
    copy(dirname(__DIR__).'/tools/development_work.php',$app.'/tools/development_work.php');
    foreach (['DevelopmentWorkHealth','DiagnosticLogger'] as $name) copy(dirname(__DIR__).'/services/'.$name.'.php',$app.'/services/'.$name.'.php');
    foreach (['ProjectAccessService','DevelopmentWorkEventService'] as $name) file_put_contents($app.'/services/'.$name.'.php','<?php');
    file_put_contents($app.'/config.php', <<<'PHP'
<?php
if (is_file(__DIR__.'/fail')) throw new RuntimeException('PRIVATE BOOTSTRAP ERROR');
class ProjectAccessService {public static function initializeReadOnly(){}}
class DevelopmentWorkEventService {
    public function hasActiveSubscriptions(){return !is_file(__DIR__.'/waiting');}
    public function deliverPending(){
        if(is_file(__DIR__.'/deliveryfail'))throw new RuntimeException('PRIVATE DELIVERY ERROR');
        return ['subscriptions'=>1,'accepted'=>0,'retried'=>0,'stopped'=>is_file(__DIR__.'/stopped')?1:0,'unchanged'=>1];
    }
}
PHP);
    $checkpoint=$tmp.'/max-search-bot-private-logs/development_work_health.json';
    foreach (['ok'=>0,'waiting'=>0,'deliveryfail'=>1,'stopped'=>1,'fail'=>1] as $mode=>$expected) {
        if ($mode!=='ok') file_put_contents($app.'/'.$mode,'1');
        $out=[];$code=-1;exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($app.'/tools/development_work.php').' tick 2>&1',$out,$code);
        $row=json_decode((string)file_get_contents($checkpoint),true);
        $state=['ok'=>'ok','waiting'=>'awaiting_connection','deliveryfail'=>'failed','stopped'=>'failed','fail'=>'failed'][$mode];
        developmentHealthCheck('actual CLI '.$mode.' records bounded outcome',$code===$expected&&($row['state']??null)===$state&&($row['trigger']??null)==='manual'&&!str_contains(implode("\n",$out),'PRIVATE')&&str_contains(implode("\n",$out),'"provider_calls":0'));
        if ($mode==='fail') developmentHealthCheck('bootstrap failure distinguished',($row['stage']??null)==='bootstrap');
        if ($mode!=='ok') unlink($app.'/'.$mode);
    }
    $out=[];$code=-1;
    exec('DEVELOPMENT_WORK_TRIGGER=cron '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($app.'/tools/development_work.php').' tick 2>&1',$out,$code);
    $row=json_decode((string)file_get_contents($checkpoint),true);
    developmentHealthCheck('installed cron marker captured by real CLI',$code===0&&($row['trigger']??null)==='cron');
    file_put_contents($app.'/fail','1');$out=[];$code=-1;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($app.'/tools/development_work.php').' status 2>&1',$out,$code);
    developmentHealthCheck('status does not bootstrap application',$code===0&&json_decode(implode("\n",$out),true)['provider_calls']===0);
    echo 'TOTAL '.$passed." PASS\n";
} finally {
    DiagnosticLogger::setFile('');
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {if ($entry->isDir()&&!$entry->isLink()) rmdir($entry->getPathname());else unlink($entry->getPathname());}
    rmdir($tmp);
}
