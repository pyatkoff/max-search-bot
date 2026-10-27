<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelWorkHealth.php';
require_once dirname(__DIR__).'/services/HotelWorkCronVerifier.php';

$tmp=sys_get_temp_dir().'/hotel-health-'.bin2hex(random_bytes(6));mkdir($tmp,0700);
DiagnosticLogger::setFile($tmp.'/structured.log');$file=$tmp.'/hotel_work_health.json';$passed=0;
function hotelHealthCheck(string $name,bool $ok): void {
    global $passed;if (!$ok) throw new RuntimeException('FAIL '.$name);++$passed;echo 'PASS '.$name."\n";
}
try {
    hotelHealthCheck('missing tick is unknown',HotelWorkHealth::snapshot()['available']===false);
    HotelWorkHealth::record('running','bootstrap');$s=HotelWorkHealth::snapshot();
    hotelHealthCheck('start captured before config or database work',$s['state']==='running'&&$s['trigger']==='manual'&&!$s['stale']&&$s['last_finished_at']===null);
    hotelHealthCheck('private external file',(fileperms($file)&0777)===0600&&!str_starts_with($file,dirname(__DIR__).'/'));
    HotelWorkHealth::record('ok','complete',['subscriptions'=>0,'accepted'=>0,'queue_id'=>'PRIVATE QUEUE','secret'=>'PRIVATE TOKEN','retried'=>-1]);
    $s=HotelWorkHealth::snapshot();$success=$s['last_success_at'];
    hotelHealthCheck('empty successful tick differs from missing tick',$s['state']==='ok'&&$success!==null&&$s['counts']===['subscriptions'=>0,'accepted'=>0]);
    hotelHealthCheck('no identity or credentials in checkpoint',!str_contains((string)file_get_contents($file),'PRIVATE'));
    HotelWorkHealth::record('running','bootstrap');HotelWorkHealth::record('failed','delivery');$s=HotelWorkHealth::snapshot();
    hotelHealthCheck('fixed error stage preserves last success',$s['state']==='failed'&&$s['last_error_stage']==='delivery'&&$s['last_success_at']===$success);
    HotelWorkHealth::record('awaiting_connection','subscription');$s=HotelWorkHealth::snapshot();
    hotelHealthCheck('missing connection is explicit',$s['state']==='awaiting_connection'&&$s['last_error_stage']==='delivery');
    hotelHealthCheck('stale is not healthy',HotelWorkHealth::snapshot(time()+181)['stale']===true);
    hotelHealthCheck('future timestamp is not healthy',HotelWorkHealth::snapshot(time()-10)['stale']===true);
    $v=new HotelWorkCronVerifier(1000);
    $sample=static fn(int $at,string $state='ok'):array=>['available'=>true,'stale'=>false,'state'=>$state,'trigger'=>'cron',
        'last_started_at'=>gmdate('Y-m-d\TH:i:s\Z',$at),'last_finished_at'=>gmdate('Y-m-d\TH:i:s\Z',$at+1)];
    hotelHealthCheck('cron verifier rejects a preexisting tick',$v->observe($sample(999),1100)===null);
    hotelHealthCheck('two future manual ticks cannot prove cron',
        $v->observe(array_replace($sample(1020),['trigger'=>'manual']),1025)===null
        &&$v->observe(array_replace($sample(1080),['trigger'=>'manual']),1085)===null);
    hotelHealthCheck('one future tick is not proof of recurrence',$v->observe($sample(1020,'awaiting_connection'),1025)===null);
    hotelHealthCheck('same tick read twice is not recurrence',$v->observe($sample(1020),1085)===null);
    $proof=$v->observe($sample(1080),1085);
    hotelHealthCheck('two completed future ticks prove observed minute interval',$proof['observed_interval_seconds']===60&&$proof['first_state']==='awaiting_connection');
    $v=new HotelWorkCronVerifier(1000);$v->observe($sample(1020),1025);$rejected=false;
    try {$v->observe($sample(1140),1145);} catch(RuntimeException $e) {$rejected=$e->getMessage()==='hotel_cron_interval_out_of_bounds';}
    hotelHealthCheck('missed minute cannot be reported as minute proof',$rejected);
    $v=new HotelWorkCronVerifier(1000);$rejected=false;
    try {$v->observe($sample(1020,'failed'),1025);} catch(RuntimeException $e) {$rejected=$e->getMessage()==='hotel_cron_worker_failed';}
    hotelHealthCheck('failed fresh worker fails scheduled proof',$rejected);
    $raw=file_get_contents($file);HotelWorkHealth::record('PRIVATE','PRIVATE');
    hotelHealthCheck('unknown categories rejected',file_get_contents($file)===$raw);
    $injected=json_decode($raw,true)+['callback_url'=>'PRIVATE CALLBACK'];$injected['counts']['queue_id']='PRIVATE';
    file_put_contents($file,json_encode($injected));
    hotelHealthCheck('reader re-allowlists fields',!str_contains(json_encode(HotelWorkHealth::snapshot()),'PRIVATE'));
    file_put_contents($file,str_repeat('x',8193));hotelHealthCheck('oversized is unknown',!HotelWorkHealth::snapshot()['available']);
    file_put_contents($file,'{broken');hotelHealthCheck('truncated is unknown',!HotelWorkHealth::snapshot()['available']);
    unlink($file);file_put_contents($tmp.'/sentinel','unchanged');symlink($tmp.'/sentinel',$file);HotelWorkHealth::record('running','bootstrap');
    hotelHealthCheck('symlink neither read nor overwritten',!HotelWorkHealth::snapshot()['available']&&file_get_contents($tmp.'/sentinel')==='unchanged');
    unlink($file);unlink($tmp.'/sentinel');

    // Execute the real CLI in a synthetic application; no production bootstrap.
    $app=$tmp.'/app';mkdir($app,0700);mkdir($app.'/tools',0700);mkdir($app.'/services',0700);
    copy(dirname(__DIR__).'/tools/hotel_work.php',$app.'/tools/hotel_work.php');
    foreach (['HotelWorkHealth','DiagnosticLogger'] as $name) copy(dirname(__DIR__).'/services/'.$name.'.php',$app.'/services/'.$name.'.php');
    foreach (['ProjectAccessService','HotelWorkEventService'] as $name) file_put_contents($app.'/services/'.$name.'.php','<?php');
    file_put_contents($app.'/config.php', <<<'PHP'
<?php
if (is_file(__DIR__.'/fail')) throw new RuntimeException('PRIVATE BOOTSTRAP ERROR');
class ProjectAccessService {public static function initializeReadOnly(){}}
class HotelWorkEventService {
    public function hasActiveSubscriptions(){return !is_file(__DIR__.'/waiting');}
    public function deliverPending(){
        if(is_file(__DIR__.'/deliveryfail'))throw new RuntimeException('PRIVATE DELIVERY ERROR');
        return ['subscriptions'=>1,'accepted'=>0,'retried'=>0,'stopped'=>is_file(__DIR__.'/stopped')?1:0,'unchanged'=>1];
    }
}
PHP);
    $checkpoint=$tmp.'/max-search-bot-private-logs/hotel_work_health.json';
    foreach (['ok'=>0,'waiting'=>0,'deliveryfail'=>1,'stopped'=>1,'fail'=>1] as $mode=>$expected) {
        if ($mode!=='ok') file_put_contents($app.'/'.$mode,'1');
        $out=[];$code=-1;exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($app.'/tools/hotel_work.php').' tick 2>&1',$out,$code);
        $row=json_decode((string)file_get_contents($checkpoint),true);
        $state=['ok'=>'ok','waiting'=>'awaiting_connection','deliveryfail'=>'failed','stopped'=>'failed','fail'=>'failed'][$mode];
        hotelHealthCheck('actual CLI '.$mode.' records bounded outcome',$code===$expected&&($row['state']??null)===$state&&($row['trigger']??null)==='manual'&&!str_contains(implode("\n",$out),'PRIVATE')&&str_contains(implode("\n",$out),'"provider_calls":0'));
        if ($mode==='fail') hotelHealthCheck('bootstrap failure distinguished',($row['stage']??null)==='bootstrap');
        if ($mode!=='ok') unlink($app.'/'.$mode);
    }
    $out=[];$code=-1;
    exec('HOTEL_WORK_TRIGGER=cron '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($app.'/tools/hotel_work.php').' tick 2>&1',$out,$code);
    $row=json_decode((string)file_get_contents($checkpoint),true);
    hotelHealthCheck('installed cron marker captured by real CLI',$code===0&&($row['trigger']??null)==='cron');
    file_put_contents($app.'/fail','1');$out=[];$code=-1;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($app.'/tools/hotel_work.php').' status 2>&1',$out,$code);
    hotelHealthCheck('status does not bootstrap application',$code===0&&json_decode(implode("\n",$out),true)['provider_calls']===0);
    echo 'TOTAL '.$passed." PASS\n";
} finally {
    DiagnosticLogger::setFile('');
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {if ($entry->isDir()&&!$entry->isLink()) rmdir($entry->getPathname());else unlink($entry->getPathname());}
    rmdir($tmp);
}
