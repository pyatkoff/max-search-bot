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

    // Exact held-UNKNOWN distinction: keep the original error and no acceptance credit.
    DevelopmentWorkHealth::record('running','bootstrap',[],'cron');DevelopmentWorkHealth::record('failed','delivery',[],'cron');
    $originalFailure=DevelopmentWorkHealth::snapshot();
    DevelopmentWorkHealth::record('running','bootstrap',[],'cron');
    DevelopmentWorkHealth::record('held_unknown','complete',['accepted'=>0,'held'=>1],'cron');$held=DevelopmentWorkHealth::snapshot();
    developmentHealthCheck('held original UNKNOWN preserves original error without claiming success',
        $held['state']==='held_unknown'&&$held['counts']===['accepted'=>0,'held'=>1]
        &&$held['last_error_at']===$originalFailure['last_error_at']&&$held['last_error_stage']===$originalFailure['last_error_stage']
        &&$held['last_success_at']===$originalFailure['last_success_at']);
    $v=new DevelopmentWorkCronVerifier(1000);
    $heldSample=static fn(int $at):array=>$sample($at,'held_unknown')+['stage'=>'complete','counts'=>['accepted'=>0,'held'=>1]];
    developmentHealthCheck('one held UNKNOWN tick does not prove recurrence',$v->observe($heldSample(1020),1025)===null);
    $heldProof=$v->observe($heldSample(1080),1085);
    developmentHealthCheck('two future held UNKNOWN ticks prove schedule only',
        $heldProof['observed_interval_seconds']===60&&$heldProof['retained_work_state']==='unknown'&&$heldProof['work_accepted']===false);
    $v=new DevelopmentWorkCronVerifier(1000);
    developmentHealthCheck('held state without verified no-acceptance counters is not proof',
        $v->observe($sample(1020,'held_unknown'),1025)===null
        &&$v->observe(array_replace($heldSample(1080),['counts'=>['accepted'=>1,'held'=>1]]),1085)===null);
    $v=new DevelopmentWorkCronVerifier(1000);$v->observe($heldSample(1020),1025);$rejected=false;
    try{$v->observe($sample(1080,'failed'),1085);}catch(RuntimeException $error){$rejected=$error->getMessage()==='development_cron_worker_failed';}
    developmentHealthCheck('new real runtime failure still fails after held observation',$rejected);
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
    foreach (['ProjectAccessService','DevelopmentWorkEventService','DevelopmentWorkServerRuntime'] as $name) file_put_contents($app.'/services/'.$name.'.php','<?php');
    file_put_contents($app.'/config.php', <<<'PHP'
<?php
if (is_file(__DIR__.'/fail')) throw new RuntimeException('PRIVATE BOOTSTRAP ERROR');
class ProjectAccessService {public static function initializeReadOnly(){}}
class ConversationDb {public static function connection(){return new PDO('sqlite::memory:');}}
class DevelopmentWorkServerRuntime {
    public static function tick($pdo,$trigger,$authorize){
        if(is_file(__DIR__.'/serverfail'))throw new RuntimeException('PRIVATE SERVER RESULT STORAGE');
        if(is_file(__DIR__.'/serverunknown'))return ['handled'=>true,'state'=>'unknown','provider_calls'=>null];
        if(is_file(__DIR__.'/serverheld'))return ['handled'=>true,'state'=>'unknown','provider_calls'=>0,'execution_status'=>'held_existing_unknown','work_accepted'=>false];
        if(is_file(__DIR__.'/serverfakeheld'))return ['handled'=>true,'state'=>'unknown','provider_calls'=>0];
        if(is_file(__DIR__.'/servercomplete'))return ['handled'=>true,'state'=>'complete','accepted_task'=>'PRIVATE TASK','provider_calls'=>1];
        return ['handled'=>false,'provider_calls'=>0];
    }
}
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
    foreach(['servercomplete','serverunknown','serverfail'] as $mode) {
        file_put_contents($app.'/'.$mode,'1');$out=[];$code=-1;
        exec('DEVELOPMENT_WORK_TRIGGER=cron '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($app.'/tools/development_work.php').' tick 2>&1',$out,$code);
        $row=json_decode((string)file_get_contents($checkpoint),true);$body=json_decode(implode("\n",$out),true);
        developmentHealthCheck('actual server CLI '.$mode.' preserves known health categories and call uncertainty',
            $code===($mode==='servercomplete'?0:1)&&($row['state']??null)===($mode==='servercomplete'?'ok':'failed')
            &&($row['stage']??null)===($mode==='servercomplete'?'complete':'delivery')
            &&($body['provider_calls']??null)===($mode==='servercomplete'?1:null)
            &&!str_contains(implode("\n",$out),'PRIVATE'));
        unlink($app.'/'.$mode);
    }

    $prior=json_decode((string)file_get_contents($checkpoint),true);
    foreach(['serverheld'=>0,'serverfakeheld'=>1] as $mode=>$expected){
        file_put_contents($app.'/'.$mode,'1');$out=[];$code=-1;
        exec('DEVELOPMENT_WORK_TRIGGER=cron '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($app.'/tools/development_work.php').' tick 2>&1',$out,$code);
        $row=json_decode((string)file_get_contents($checkpoint),true);$body=json_decode(implode("\n",$out),true);
        developmentHealthCheck('actual CLI '.$mode.' distinguishes preserved UNKNOWN from new failure',
            $code===$expected&&$body['state']==='unknown'&&$body['provider_calls']===0
            &&$row['state']===($mode==='serverheld'?'held_unknown':'failed')&&!str_contains(implode("\n",$out),'PRIVATE'));
        if($mode==='serverheld')developmentHealthCheck('actual safe held CLI retains original error and zero accepted',
            $body['work_accepted']===false&&$row['counts']===['accepted'=>0,'held'=>1]
            &&$row['last_error_at']===$prior['last_error_at']&&$row['last_error_stage']===$prior['last_error_stage']);
        unlink($app.'/'.$mode);
    }
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
