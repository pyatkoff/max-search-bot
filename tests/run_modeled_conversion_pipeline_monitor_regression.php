<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ModeledConversionPipelineMonitorStatus.php';
require_once dirname(__DIR__).'/services/ModeledConversionDeliveryHealth.php';

function pipelineMonitorFixtureWrite(string $path,array|string $value,int $mode=0600):void
{
    if(is_link($path))unlink($path);
    if(file_put_contents($path,is_array($value)?json_encode($value,JSON_THROW_ON_ERROR):$value)===false||!chmod($path,$mode))
        throw new RuntimeException('pipeline_fixture_write');
    clearstatcache(true,$path);
}
function pipelineMonitorFixtureRemove(string $path):void
{
    if(is_link($path)||!is_dir($path)){if(file_exists($path)||is_link($path))unlink($path);return;}
    foreach(scandir($path)?:[]as$name)if($name!=='.'&&$name!=='..')pipelineMonitorFixtureRemove($path.'/'.$name);
    rmdir($path);
}
function pipelineMonitorFixtureActivation(int $now):array
{
    return['schema_version'=>1,'project'=>'anytour','counter_id'=>'98615635','role'=>'pipeline',
        'operation'=>'12345678901','activated_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-3600),
        'source_sha'=>str_repeat('a',40),'state_imported'=>true];
}
function pipelineMonitorFixtureReceipt(int $now,int $count=3):array
{
    $checks=[];
    for($i=0;$i<$count;$i++)$checks[]=['cycle_id'=>str_pad(dechex($i+1),32,'0',STR_PAD_LEFT),
        'checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-300*($count-1-$i)),'status'=>'ok','ok'=>true,'source_sha'=>str_repeat('a',40)];
    $tail=$checks===[]?['cycle_id'=>str_repeat('1',32),'checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$now)]:$checks[count($checks)-1];
    return['schema_version'=>1,'project'=>'anytour','counter_id'=>'98615635','role'=>'pipeline','origin'=>'server_cron',
        'checked_at'=>$tail['checked_at'],'status'=>'ok','flags'=>[],'notification'=>'quiet',
        'last_notification_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-3700),'source_sha'=>str_repeat('a',40),
        'cycle_id'=>$tail['cycle_id'],'ok'=>true,'checks'=>$checks];
}
function pipelineMonitorFixtureRun(array $command,string $cwd):array
{
    $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd);
    if(!is_resource($process))throw new RuntimeException('pipeline_fixture_process');
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1],8193);fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2],8193);fclose($pipes[2]);$code=proc_close($process);
    return['code'=>$code,'body'=>is_string($stdout)?json_decode($stdout,true):null,'stderr'=>$stderr];
}
function pipelineMonitorStatusAssertions(callable $check):void
{
    $base=sys_get_temp_dir().'/modeled-pipeline-fixture-'.bin2hex(random_bytes(8));
    if(!mkdir($base,0700)||!mkdir($base.'/private',0700))throw new RuntimeException('pipeline_fixture_directory');
    $dir=$base.'/private';$activationPath=$dir.'/activation.json';$receiptPath=$dir.'/pipeline-checks.json';$now=1793700000;
    $read=static fn(int $clock):array=>ModeledConversionPipelineMonitorStatus::read(new ModeledConversionStatusBudget(),static function():void{},$clock,$dir);
    $availability=static fn(int $clock):?array=>ModeledConversionPipelineMonitorStatus::availability($clock,$dir);
    try{
        $r=$read($now);
        $check('absent activation is explicitly inactive and delegates legacy coverage',$r['active']===false
            &&$r['reason']==='not_activated'&&$availability($now)===null);
        pipelineMonitorFixtureWrite($dir.'/runtime-observations.json',['kind'=>'modeled_monitor_local_observations_v1',
            'source'=>'local_runtime','external_proof'=>false,'scheduled_proof'=>false,'recent'=>pipelineMonitorFixtureReceipt($now)['checks']]);
        pipelineMonitorFixtureWrite($dir.'/state.json',str_repeat('synthetic-credential-state-never-read',3000));
        $check('generic runtime observations cannot activate or prove scheduled operation',$read($now)['active']===false
            &&!$read($now)['scheduled']['regular']);
        $activation=pipelineMonitorFixtureActivation($now);
        pipelineMonitorFixtureWrite($activationPath,$activation);
        $r=$read($now);
        $check('activated checker without first tick is unknown rather than fabricated zero',$r['active']===true
            &&$r['available']===false&&$r['reason']==='not_recorded'&&!$r['scheduled']['regular']);
        $freshActivation=array_replace($activation,['activated_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-600)]);
        pipelineMonitorFixtureWrite($activationPath,$freshActivation);
        $a=$availability($now);
        $check('first ten minutes permit explicit first-tick grace without SQL',$a['status']==='ok'
            &&$a['flags']===[]&&$a['waiting_first_tick']&&$a['monitor_scope']==='external');
        $a=$availability($now+1);
        $check('missing first tick after grace alerts stale checker',$a['status']==='unavailable'
            &&$a['flags']===['pipeline_checker_stale']&&!$a['waiting_first_tick']);
        pipelineMonitorFixtureWrite($activationPath,$activation);
        $receipt=pipelineMonitorFixtureReceipt($now);
        pipelineMonitorFixtureWrite($receiptPath,$receipt);
        $r=$read($now);
        $check('three distinct timely cron receipts establish only local regularity',$r['available']&&$r['origin']==='server_cron'
            &&$r['scheduled']['success_streak']===3&&$r['scheduled']['regular']&&$r['cycle_id']===$receipt['cycle_id']);
        $check('last confirmed notification date is preserved',$r['last_notification_at']===$receipt['last_notification_at']);
        $check('fresh local business check gives independent availability OK',$availability($now)['status']==='ok'
            &&$availability($now)['flags']===[]);
        $budget=new ModeledConversionStatusBudget();
        ModeledConversionPipelineMonitorStatus::read($budget,static function():void{},$now,$dir);
        $expectedBytes=strlen(json_encode($activation,JSON_THROW_ON_ERROR))+strlen(json_encode($receipt,JSON_THROW_ON_ERROR));
        $check('read budget accounts only the two typed files and never raw credentials',
            $budget->remainingBytes()===ModeledConversionStatusBudget::MAX_BYTES-$expectedBytes);
        $degraded=$receipt;$degraded['status']='degraded';$degraded['flags']=['delivery_tick_stale'];
        $degraded['checks'][2]['status']='degraded';$degraded['checks'][2]['ok']=false;
        pipelineMonitorFixtureWrite($receiptPath,$degraded);
        $r=$read($now);
        $check('local business degradation remains visible in its own role',$r['status']==='degraded'
            &&$r['flags']===['delivery_tick_stale']&&!$r['scheduled']['regular']);
        $check('confirmed local business incident does not duplicate an external alert',$availability($now)['status']==='ok'
            &&$availability($now)['flags']===[]);
        $unknown=$receipt;$unknown['notification']='unknown';$unknown['ok']=false;$unknown['checks'][2]['ok']=false;
        pipelineMonitorFixtureWrite($receiptPath,$unknown);
        $check('fresh uncertain local notification has its own control alert',$availability($now)['flags']===['pipeline_checker_notification_unknown']
            &&!$read($now)['scheduled']['regular']);
        $notPersisted=$receipt;$notPersisted['ok']=false;
        pipelineMonitorFixtureWrite($receiptPath,$notPersisted);
        $check('fresh unsuccessful local persistence does not qualify despite a healthy business status',
            $availability($now)['flags']===['pipeline_checker_notification_unknown']&&$read($now)['scheduled']['success_streak']===0);
        pipelineMonitorFixtureWrite($receiptPath,$receipt);
        $check('freshness includes exact fifteen-minute boundary',$read($now+900)['fresh']&&$read($now+900)['scheduled']['regular']);
        $check('old local heartbeat becomes a stale control alert',$availability($now+901)['flags']===['pipeline_checker_stale']
            &&!$read($now+901)['scheduled']['regular']);
        $gap=$receipt;$gap['checks'][0]['checked_at']=gmdate('Y-m-d\TH:i:s\Z',$now-1301);
        $gap['checks'][1]['checked_at']=gmdate('Y-m-d\TH:i:s\Z',$now-1000);
        pipelineMonitorFixtureWrite($receiptPath,$gap);
        $check('a missed long interval resets consecutive proof',$read($now)['scheduled']['success_streak']===1);
        $one=pipelineMonitorFixtureReceipt($now,1);pipelineMonitorFixtureWrite($receiptPath,$one);
        $check('one actual cycle cannot become three by repeated reads',$read($now)['scheduled']['success_streak']===1
            &&$read($now)['scheduled']['success_streak']===1&&!$read($now)['scheduled']['regular']);
        $empty=pipelineMonitorFixtureReceipt($now,0);pipelineMonitorFixtureWrite($receiptPath,$empty);
        $check('receipt without history leaves regularity unknown',$read($now)['available']
            &&!$read($now)['scheduled']['available']&&!$read($now)['scheduled']['regular']);
        $mutations=[
            'foreign project'=>static function(array $r):array{$r['project']='foreign';return$r;},
            'foreign counter'=>static function(array $r):array{$r['counter_id']='999';return$r;},
            'external role'=>static function(array $r):array{$r['role']='external';return$r;},
            'runtime origin'=>static function(array $r):array{$r['origin']='local_runtime';return$r;},
            'explicit non-proof'=>static function(array $r):array{$r['scheduled_proof']=false;return$r;},
            'unknown top field'=>static function(array $r):array{$r['token']='synthetic-private-canary';return$r;},
            'external transport flag'=>static function(array $r):array{$r['status']='degraded';$r['flags']=['monitor_transport_unavailable'];$r['checks'][2]['status']='degraded';$r['checks'][2]['ok']=false;return$r;},
            'control flag in business role'=>static function(array $r):array{$r['status']='degraded';$r['flags']=['pipeline_checker_stale'];$r['checks'][2]['status']='degraded';$r['checks'][2]['ok']=false;return$r;},
            'repeated cycle identifier'=>static function(array $r):array{$r['checks'][1]['cycle_id']=$r['checks'][0]['cycle_id'];return$r;},
            'equal cycle clocks'=>static function(array $r):array{$r['checks'][1]['checked_at']=$r['checks'][0]['checked_at'];return$r;},
            'reversed clocks'=>static function(array $r):array{$r['checks'][0]['checked_at']=$r['checks'][1]['checked_at'];return$r;},
            'tail differs from current cycle'=>static function(array $r):array{$r['cycle_id']=str_repeat('f',32);return$r;},
            'noncanonical source'=>static function(array $r):array{$r['source_sha']=str_repeat('A',40);return$r;},
            'invented GitHub identifier'=>static function(array $r):array{$r['cycle_id']='12345678901';return$r;},
            'unhealthy check marked successful'=>static function(array $r):array{$r['checks'][0]['status']='degraded';return$r;},
            'more than three checks'=>static function(array $r):array{$r['checks'][]=$r['checks'][2];return$r;},
            'missing required outcome'=>static function(array $r):array{unset($r['ok']);return$r;},
            'future observation'=>static function(array $r)use($now):array{$r['checked_at']=gmdate('Y-m-d\TH:i:s\Z',$now+61);return$r;},
            'preactivation observation'=>static function(array $r)use($now):array{$r['checks'][0]['checked_at']=gmdate('Y-m-d\TH:i:s\Z',$now-3601);return$r;},
        ];
        foreach($mutations as$name=>$mutate){
            pipelineMonitorFixtureWrite($receiptPath,$mutate($receipt));
            $r=$read($now);
            $check('invalid '.$name.' is rejected without qualifying proof',$r['available']===false
                &&$r['reason']==='invalid_metadata'&&!$r['scheduled']['regular']
                &&$availability($now)['flags']===['pipeline_checker_stale']);
        }
        pipelineMonitorFixtureWrite($receiptPath,$receipt,0644);
        $check('nonprivate receipt is never admitted',$read($now)['reason']==='invalid_metadata');
        pipelineMonitorFixtureWrite($receiptPath,str_repeat('x',4097));
        $check('oversized receipt fails before parsing',$read($now)['reason']==='invalid_metadata');
        pipelineMonitorFixtureWrite($receiptPath,'{broken');
        $check('malformed receipt fails closed',$read($now)['reason']==='invalid_metadata');
        pipelineMonitorFixtureWrite($base.'/safe-receipt.json',$receipt);unlink($receiptPath);symlink($base.'/safe-receipt.json',$receiptPath);
        $check('receipt symlink is rejected',$read($now)['reason']==='invalid_metadata');
        unlink($receiptPath);pipelineMonitorFixtureWrite($receiptPath,$receipt);
        $badActivation=$activation;$badActivation['state_imported']=false;pipelineMonitorFixtureWrite($activationPath,$badActivation);
        $check('unconfirmed state import does not enter legacy SQL or active proof',$read($now)['active']===null
            &&$read($now)['reason']==='invalid_activation'&&$availability($now)['flags']===['pipeline_checker_stale']);
        pipelineMonitorFixtureWrite($activationPath,$activation,0644);
        $check('nonprivate activation marker is not accepted',$read($now)['reason']==='invalid_activation');
        pipelineMonitorFixtureWrite($activationPath,str_repeat('x',2049));
        $check('activation size bound is enforced',$read($now)['reason']==='invalid_activation');
        pipelineMonitorFixtureWrite($base.'/safe-activation.json',$activation);unlink($activationPath);symlink($base.'/safe-activation.json',$activationPath);
        $check('activation symlink is rejected',$read($now)['reason']==='invalid_activation');
        unlink($activationPath);pipelineMonitorFixtureWrite($activationPath,$activation);
        chmod($dir,0755);$check('nonprivate directory fails closed',$read($now)['reason']==='unsafe_storage');chmod($dir,0700);
        symlink($dir,$base.'/linked');
        $linked=ModeledConversionPipelineMonitorStatus::read(new ModeledConversionStatusBudget(),static function():void{},$now,$base.'/linked');
        $check('directory symlink is rejected',$linked['reason']==='unsafe_storage');
        $revoked=false;$calls=0;
        try{ModeledConversionPipelineMonitorStatus::read(new ModeledConversionStatusBudget(),
            static function()use(&$calls):void{if(++$calls>=7)throw new DomainException('synthetic_revocation');},$now,$dir);}
        catch(DomainException $ignored){$revoked=true;}
        $check('authorization revoked during file access prevents all release',$revoked);
        $limited=false;$clock=0.0;
        try{ModeledConversionPipelineMonitorStatus::read(new ModeledConversionStatusBudget(0.0,
            static function()use(&$clock):float{$clock+=1.0;return$clock;}),static function():void{},$now,$dir);}
        catch(ModeledConversionStatusLimit $ignored){$limited=true;}
        $check('shared status time limit is preserved',$limited);
        foreach(['pipeline_checker_stale','pipeline_checker_notification_unknown']as$flag){
            $record=ModeledConversionDeliveryHealth::composeExternalReceipt(['schema_version'=>1,'project'=>'anytour',
                'counter_id'=>'98615635','status'=>'unavailable','flags'=>[$flag],'notification'=>'quiet','ok'=>true],null,$now);
            $check('external recorder accepts allowlisted control flag '.$flag,$record['flags']===[$flag]);
        }

        // Execute the real CLI and real readers in a synthetic tree, with tripwires for SQL.
        $app=$base.'/app';mkdir($app,0700);mkdir($app.'/services',0700);mkdir($app.'/tools',0700);
        foreach(['ModeledConversionStatusBudget.php','ModeledConversionPipelineMonitorStatus.php','ModeledConversionDeliveryHealth.php']as$file)
            copy(dirname(__DIR__).'/services/'.$file,$app.'/services/'.$file);
        copy(dirname(__DIR__).'/tools/modeled_conversion_monitor.php',$app.'/tools/modeled_conversion_monitor.php');
        $config="<?php\nconst METRIKA_COUNTER_ID='98615635';\nclass ProjectConfig{public static function projectId():string{return 'anytour';}}\n"
            ."class ConversationDb{public static function connection():PDO{file_put_contents(".var_export($base.'/PDO_USED',true).",'1');return new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}}\n";
        pipelineMonitorFixtureWrite($app.'/config.php',$config);
        pipelineMonitorFixtureWrite($app.'/services/ProjectAccessService.php',"<?php\nclass ProjectAccessService{public static function initializeReadOnly():void{file_put_contents(".var_export($base.'/ACCESS_USED',true).",'1');}}\n");
        $local=$base.'/max-search-bot-modeled-pipeline';mkdir($local,0700);
        $command=[PHP_BINARY,$app.'/tools/modeled_conversion_monitor.php','availability'];
        $legacy=pipelineMonitorFixtureRun($command,$base);
        $check('CLI preserves legacy full health before activation',$legacy['code']===0
            &&($legacy['body']['monitor_scope']??null)==='legacy'&&is_file($base.'/PDO_USED')&&is_file($base.'/ACCESS_USED'));
        unlink($base.'/PDO_USED');unlink($base.'/ACCESS_USED');
        $liveNow=time();$liveActivation=pipelineMonitorFixtureActivation($liveNow);$liveReceipt=pipelineMonitorFixtureReceipt($liveNow);
        pipelineMonitorFixtureWrite($local.'/activation.json',$liveActivation);
        pipelineMonitorFixtureWrite($local.'/pipeline-checks.json',$liveReceipt);
        $active=pipelineMonitorFixtureRun($command,$base);
        $check('activated CLI availability has no PDO or ProjectAccess initialization',$active['code']===0
            &&($active['body']['monitor_scope']??null)==='external'&&($active['body']['status']??null)==='ok'
            &&!is_file($base.'/PDO_USED')&&!is_file($base.'/ACCESS_USED'));
        $liveReceipt['status']='degraded';$liveReceipt['flags']=['delivery_tick_stale'];
        $liveReceipt['checks'][2]['status']='degraded';$liveReceipt['checks'][2]['ok']=false;
        pipelineMonitorFixtureWrite($local.'/pipeline-checks.json',$liveReceipt);
        $business=pipelineMonitorFixtureRun($command,$base);
        $check('CLI external role leaves business alerts to the local sender',$business['code']===0
            &&($business['body']['flags']??null)===[]&&($business['body']['status']??null)==='ok'
            &&!is_file($base.'/PDO_USED'));
        $externalDir=$base.'/max-search-bot-modeled-monitor';mkdir($externalDir,0700);
        $external=['schema_version'=>1,'project'=>'anytour','counter_id'=>'98615635',
            'checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$liveNow-30),'status'=>'degraded','flags'=>['monitor_transport_unavailable'],
            'notification'=>'quiet','last_notification_at'=>gmdate('Y-m-d\TH:i:s\Z',$liveNow-3600)];
        pipelineMonitorFixtureWrite($externalDir.'/last-check.json',$external);
        $script='require '.var_export($app.'/services/ModeledConversionDeliveryHealth.php',true).';'
            .'echo json_encode(ModeledConversionDeliveryHealth::externalStatus(new ModeledConversionStatusBudget(),static function():void{},time()),JSON_THROW_ON_ERROR);';
        $combined=pipelineMonitorFixtureRun([PHP_BINARY,'-r',$script],$base);
        $check('external status fields stay external while local status is nested',$combined['code']===0
            &&($combined['body']['status']??null)==='degraded'
            &&($combined['body']['checked_at']??null)===$external['checked_at']
            &&($combined['body']['last_notification_at']??null)===$external['last_notification_at']
            &&($combined['body']['pipeline']['origin']??null)==='server_cron'
            &&($combined['body']['pipeline']['status']??null)==='degraded'
            &&($combined['body']['pipeline']['ok']??null)===true);
        $check('combined reader does not initialize a database',!is_file($base.'/PDO_USED')&&!is_file($base.'/ACCESS_USED'));
    }finally{pipelineMonitorFixtureRemove($base);}
}
if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    $passed=0;$check=static function(string $name,bool $ok)use(&$passed):void{
        if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;};
    pipelineMonitorStatusAssertions($check);
    echo 'MODELED PIPELINE MONITOR STATUS PASSED '.$passed.PHP_EOL;
}
