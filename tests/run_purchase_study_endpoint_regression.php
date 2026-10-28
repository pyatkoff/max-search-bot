<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$base=dirname(__DIR__);$dir=sys_get_temp_dir().'/purchase-endpoint-'.bin2hex(random_bytes(8));
$private=$dir.'-private';$process=null;$passed=0;$failed=0;
function studyEndpointCheck(string $name,bool $ok):void{global$passed,$failed;echo($ok?'PASS  ':'FAIL  ').$name.PHP_EOL;if($ok)$passed++;else$failed++;}
function studyEndpointRemove(string $p):void{if(is_file($p)||is_link($p)){unlink($p);return;}if(!is_dir($p))return;foreach(scandir($p)as$n)if($n!=='.'&&$n!=='..')studyEndpointRemove($p.'/'.$n);rmdir($p);}
try{
    mkdir($dir,0700);mkdir($dir.'/manager',0700);mkdir($dir.'/manager/lib',0700);mkdir($dir.'/services',0700);mkdir($dir.'/tools',0700);mkdir($private,0700);
    copy($base.'/manager/purchase-study-api.php',$dir.'/manager/purchase-study-api.php');
    copy($base.'/manager/lib/ManagerHttp.php',$dir.'/manager/lib/ManagerHttp.php');
    copy($base.'/services/PurchaseStudyOperationalSnapshot.php',$dir.'/services/PurchaseStudyOperationalSnapshot.php');
    file_put_contents($dir.'/services/ModeledConversionOutcomeReceipt.php',"<?php // synthetic reader input\n");
    foreach(['purchase_conversion_study.py','purchase_study_runtime.py','profile_conversion_journal.py','extract_conversion_profiles.py']as$name)
        file_put_contents($dir.'/tools/'.$name,"# synthetic reader input\n");
    file_put_contents($dir.'/config.php', <<<'PHP'
<?php
class ProjectConfig{static function projectId():string{return $_COOKIE['study_project']??'anytour';}}
class ProjectAccessService{
 static bool $readOnly=false;static int $accessReads=0;
 static function initializeReadOnly():void{if(($_COOKIE['study_init']??'')==='throw')throw new RuntimeException('PRIVATE_SCHEMA_FAILURE');self::$readOnly=true;}
 static function canAccess(int $id,string $project):bool{self::$accessReads++;return self::$readOnly&&$id===7&&$project==='anytour'&&($_COOKIE['study_access']??'yes')==='yes'&&!(($_COOKIE['study_revoke']??'')==='access'&&self::$accessReads>1);}
}
class DiagnosticLogger{static function filePath():string{return getenv('PURCHASE_ENDPOINT_PRIVATE').'/events.log';}}
class ConversationDb{
 static ?PDO $pdo=null;
 static function connection():PDO{
  file_put_contents(getenv('PURCHASE_ENDPOINT_MARKER'),'connection\n',FILE_APPEND);
  if(self::$pdo===null){self::$pdo=new PDO('sqlite::memory:');if(($_COOKIE['study_transaction']??'')==='yes')self::$pdo->beginTransaction();}
  return self::$pdo;
 }
}
PHP);
    file_put_contents($dir.'/services/ManagerRequestContext.php', <<<'PHP'
<?php
class ManagerRequestContext{
 static int $managerReads=0;
 static function startSession():void{}
 static function manager():?array{
  if(!ProjectAccessService::$readOnly)file_put_contents(getenv('PURCHASE_ENDPOINT_MARKER'),'AUTH_SCHEMA_UPSERT',FILE_APPEND);
  self::$managerReads++;$r=$_COOKIE['study_role']??'none';
  if(self::$managerReads>1){if(($_COOKIE['study_revoke']??'')==='manager')return null;if(($_COOKIE['study_revoke']??'')==='admin')$r='manager';}
  return$r==='none'?null:['id'=>7,'role'=>$r];
 }
 static function managerId():int{return($_COOKIE['study_role']??'none')==='none'?0:7;}
 static function isAdmin(array $m):bool{return($m['role']??'')==='admin';}
}
PHP);
    file_put_contents($dir.'/services/ModeledConversionPurchaseStudy.php', <<<'PHP'
<?php
class ModeledConversionPurchaseStudy{
 const VERSION='purchase_study_supplier_v1';
 function __construct(PDO $p){}
 function tick(int $now):array{throw new RuntimeException('endpoint_must_not_tick');}
 function report():array{return $this->status()+['registered_at_utc'=>'2026-10-28T12:00:00Z','horizon_days'=>30,'comparison'=>null];}
 function status():array{
  file_put_contents(getenv('PURCHASE_ENDPOINT_MARKER'),'status\n',FILE_APPEND);
  $mode=$_COOKIE['study_state']??'registered';
  if($mode==='throw')throw new RuntimeException('PRIVATE_SENSITIVE_FIXTURE');
  return['status'=>$mode,'cases'=>12,'model_fitted'=>0,'conversion_uploads'=>0];
 }
}
PHP);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('fixture_socket');
    $address=stream_socket_get_name($socket,false);fclose($socket);
    $env=getenv();$env['PURCHASE_ENDPOINT_MARKER']=$dir.'/marker';$env['PURCHASE_ENDPOINT_PRIVATE']=$private;
    $process=proc_open([PHP_BINARY,'-S',$address,'-t',$dir],[0=>['file','/dev/null','r'],1=>['file',$dir.'/server.out','a'],2=>['file',$dir.'/server.err','a']],$pipes,null,$env);
    if(!is_resource($process))throw new RuntimeException('fixture_server');
    $url='http://'.$address.'/manager/purchase-study-api.php';
    $request=static function(string $cookie='',string $method='GET',string $query='')use($url):array{
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>"Cookie: $cookie\r\n",'ignore_errors'=>true,'timeout'=>5]]);
        $body=@file_get_contents($url.$query,false,$context);$headers=$http_response_header??[];
        preg_match('/\s(\d{3})\s/',$headers[0]??'',$m);
        return[(int)($m[1]??0),is_string($body)?json_decode($body,true):null,strtolower(implode("\n",$headers)),(string)$body];
    };
    for($i=0;$i<50;$i++){[$code]=$request();if($code===401)break;usleep(20000);}
    file_put_contents($dir.'/marker','');
    [$code,$body]=$request();studyEndpointCheck('anonymous request denied before private registry access',$code===401&&($body['error']??'')==='unauthorized'&&file_get_contents($dir.'/marker')==='');
    [$code,$body]=$request('study_role=manager');studyEndpointCheck('ordinary manager cannot read project-wide research',$code===403&&($body['error']??'')==='forbidden'&&file_get_contents($dir.'/marker')==='');
    [$code,$body]=$request('study_role=admin; study_access=no');studyEndpointCheck('admin without AnyTour membership cannot read study',$code===403&&($body['error']??'')==='forbidden'&&file_get_contents($dir.'/marker')==='');
    [$code,$body]=$request('study_role=admin; study_project=other');studyEndpointCheck('foreign project denied before data access',$code===404&&($body['error']??'')==='project_unavailable'&&file_get_contents($dir.'/marker')==='');
    [$code,$body]=$request('study_role=admin','POST');studyEndpointCheck('endpoint cannot start or mutate a study',$code===405&&($body['error']??'')==='method_not_allowed'&&file_get_contents($dir.'/marker')==='');
    [$code,$body]=$request('study_role=admin','GET','?project_key=other');studyEndpointCheck('caller cannot select a project, scope or private file path',$code===422&&($body['error']??'')==='unexpected_parameters'&&file_get_contents($dir.'/marker')==='');
    [$code,$body,$headers,$raw]=$request('study_role=admin');
    studyEndpointCheck('admin receives private aggregate report and interpretation',$code===200&&($body['ok']??false)===true&&($body['study']['cases']??null)===12&&array_key_exists('comparison',$body['study'])&&($body['interpretation']['horizon_days']??null)===30&&($body['interpretation']['monetary_export_allowed']??true)===false);
    studyEndpointCheck('private JSON response cannot enter a shared browser cache',str_contains($headers,'content-type: application/json')&&str_contains($headers,'cache-control: private, no-store')&&str_contains($headers,'vary: cookie')&&str_contains($headers,'x-content-type-options: nosniff'));
    studyEndpointCheck('HTTP path inspects registry without running models',file_get_contents($dir.'/marker')==='connection\nstatus\n');
    [$code,$body]=$request('study_role=admin; study_state=failed');studyEndpointCheck('failed registry status stays a real HTTP failure',$code===503&&($body['ok']??true)===false);
    [$code,$body,,$raw]=$request('study_role=admin; study_state=throw');studyEndpointCheck('storage exception emits a fixed error without private detail',$code===503&&($body['error']??'')==='study_unavailable'&&!str_contains($raw,'PRIVATE_SENSITIVE_FIXTURE'));
    [$code,$body,,$raw]=$request('study_role=admin; study_init=throw');studyEndpointCheck('readonly initialization failure is caught before auth mutations',$code===503&&($body['error']??'')==='study_unavailable'&&!str_contains($raw,'PRIVATE_SCHEMA_FAILURE')&&!str_contains(file_get_contents($dir.'/marker'),'AUTH_SCHEMA_UPSERT'));

    // Real companion reader over synthetic private files; no study/model execution.
    $view='?view=operational';$studyDir=$private.'/purchase-study';
    file_put_contents($dir.'/marker','');
    foreach([['',401],['study_role=manager',403],['study_role=admin; study_access=no',403],['study_role=admin; study_project=other',404]]as[$cookie,$expected]){
        [$code,$body]=$request($cookie,'GET',$view);
        studyEndpointCheck('operational authorization rejects '.$expected.' before private access',$code===$expected&&!isset($body['operational'])&&file_get_contents($dir.'/marker')==='');
    }
    [$code,$body]=$request('study_role=admin','GET',$view.'&path=other');
    studyEndpointCheck('operational view rejects caller-selected storage and extra parameters',$code===422&&!isset($body['operational'])&&file_get_contents($dir.'/marker')==='');
    [$code,$body]=$request('study_role=admin','POST',$view);
    studyEndpointCheck('operational view is strictly read-only GET',$code===405&&!isset($body['operational'])&&file_get_contents($dir.'/marker')==='');
    [$code,$body]=$request('study_role=admin','GET',$view);
    studyEndpointCheck('missing registry remains unknown without creating storage',$code===503&&($body['operational']['status']??'')==='unknown'&&!file_exists($studyDir));

    mkdir($studyDir,0700);file_put_contents($studyDir.'/lock','');chmod($studyDir.'/lock',0600);
    $hashes=[];
    foreach(['services/ModeledConversionPurchaseStudy.php','services/ModeledConversionOutcomeReceipt.php',
        'tools/purchase_conversion_study.py','tools/purchase_study_runtime.py',
        'tools/profile_conversion_journal.py','tools/extract_conversion_profiles.py']as$path)$hashes[$path]=hash_file('sha256',$dir.'/'.$path);
    ksort($hashes,SORT_STRING);
    $reader=hash('sha256',json_encode($hashes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $registered=gmdate('Y-m-d\TH:i:s\Z',time()-120);$observed=gmdate('Y-m-d\TH:i:s\Z',time()-60);
    $state=['kind'=>'purchase_study_state','version'=>'purchase_study_runtime_v1',
        'manifest'=>['reader_kind'=>'reviewed_runtime_reader','reader_sha256'=>$reader,'registered_at'=>$registered],
        'protocol_sha256'=>str_repeat('a',64),'profile_cursor'=>'0','profile_fence'=>0,'monitor_cursor'=>0,
        'cases'=>[['packet'=>['scope'=>['customer_key'=>'fixture-private-person'],'private_feature'=>'fixture-private-feature'],'outcome'=>'fixture-private-outcome']],
        'model'=>['private_weight'=>'fixture-private-weight']];
    $receipt=['protocol_sha256'=>str_repeat('a',64),'observed_at_utc'=>$observed,
        'cases'=>12345,'paired_brier_delta'=>0.123,'private_note'=>'fixture-private-receipt'];
    $save=static function(array $s,array $r)use($studyDir):string{
        $sj=json_encode($s,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$rj=json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $raw=json_encode(['schema_version'=>1,'state_json'=>$sj,'state_sha256'=>hash('sha256',$sj),
            'receipt_json'=>$rj,'receipt_sha256'=>hash('sha256',$rj)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        file_put_contents($studyDir.'/state.json',$raw);chmod($studyDir.'/state.json',0600);return$raw;
    };
    $saved=$save($state,$receipt);$lockBefore=stat($studyDir.'/lock');
    file_put_contents($dir.'/marker','');
    [$code,$body,$headers,$raw]=$request('study_role=admin; study_state=throw','GET',$view);
    $dto=$body['operational']??[];
    $fixed=['status','supplier_version','protocol_registered_at_utc','receipt_observed_at_utc',
        'protocol_sha256','runtime_compatibility','ordinary_cron_provenance'];
    studyEndpointCheck('real operational reader projects exactly seven technical fields',$code===200&&($body['ok']??false)===true&&array_keys($dto)===$fixed&&$dto['status']==='saved_metadata_valid'&&$dto['supplier_version']==='purchase_study_supplier_v1'&&strlen($raw)<=2048);
    studyEndpointCheck('registration and receipt clocks retain their actual distinct meaning',$dto['protocol_registered_at_utc']===$registered&&$dto['receipt_observed_at_utc']===$observed&&$dto['protocol_sha256']===str_repeat('a',64)&&$dto['runtime_compatibility']==='unknown'&&$dto['ordinary_cron_provenance']==='unknown'&&!isset($dto['last_saved_at_utc']));
    studyEndpointCheck('operational path never invokes full report status tick or source/model work',file_get_contents($dir.'/marker')==='connection\n'&&!str_contains($raw,'fixture-private-')&&!str_contains($raw,'12345')&&!isset($body['study'])&&!isset($body['interpretation']));
    clearstatcache(true,$studyDir.'/lock');$lockAfter=stat($studyDir.'/lock');
    studyEndpointCheck('shared inspection neither rewrites registry nor chmods or creates its lock',file_get_contents($studyDir.'/state.json')===$saved&&$lockBefore['ino']===$lockAfter['ino']&&$lockBefore['mode']===$lockAfter['mode']&&$lockBefore['ctime']===$lockAfter['ctime']&&$lockBefore['mtime']===$lockAfter['mtime']&&file_get_contents($studyDir.'/lock')==='');
    studyEndpointCheck('operational metadata retains private no-store headers',str_contains($headers,'cache-control: private, no-store')&&str_contains($headers,'vary: cookie')&&str_contains($headers,'x-content-type-options: nosniff'));

    $lock=fopen($studyDir.'/lock','rb');flock($lock,LOCK_EX);
    [$code,$body]=$request('study_role=admin','GET',$view);
    studyEndpointCheck('operational inspection respects the existing nonblocking shared lock',$code===503&&($body['operational']['status']??'')==='busy'&&file_get_contents($studyDir.'/state.json')===$saved);
    flock($lock,LOCK_UN);fclose($lock);

    $bad=json_decode($saved,true,8,JSON_THROW_ON_ERROR);$bad['state_sha256']=str_repeat('0',64);
    file_put_contents($studyDir.'/state.json',json_encode($bad,JSON_THROW_ON_ERROR));
    [$code,$body,,$raw]=$request('study_role=admin','GET',$view);
    studyEndpointCheck('checksum failure exposes no metadata or private detail',$code===503&&($body['operational']['status']??'')==='failed'&&($body['operational']['protocol_registered_at_utc']??null)===null&&!str_contains($raw,'fixture-private-'));
    foreach([['manager',401],['admin',403],['access',403]]as[$revoke,$expected]){
        [$code,$body]=$request('study_role=admin; study_revoke='.$revoke,'GET',$view);
        studyEndpointCheck('post-read '.$revoke.' revocation takes precedence even over failed storage',$code===$expected&&!isset($body['operational']));
    }
    $saved=$save($state,$receipt);
    foreach([['manager',401],['admin',403],['access',403]]as[$revoke,$expected]){
        [$code,$body]=$request('study_role=admin; study_revoke='.$revoke,'GET',$view);
        studyEndpointCheck('post-read '.$revoke.' revocation suppresses a valid private DTO',$code===$expected&&!isset($body['operational']));
    }
    [$code,$body]=$request('study_role=admin; study_transaction=yes','GET',$view);
    studyEndpointCheck('an existing transaction cannot lend stale authorization to a private read',$code===503&&($body['error']??'')==='study_unavailable'&&!isset($body['operational']));

    $variants=[];
    $v=$state;$v['manifest']['reader_sha256']=str_repeat('b',64);$variants['foreign reader recipe']=$v;
    $v=$state;$v['manifest']['registered_at']='2026-02-30T12:00:00Z';$variants['invalid registration clock']=$v;
    $v=$state;$v['version']='unknown';$variants['foreign registry version']=$v;
    $v=$state;$v['protocol_sha256']=str_repeat('c',64);$variants['conflicting protocol digest']=$v;
    $v=$state;$v['cases']=array_fill(0,257,[]);$variants['case container beyond existing bound']=$v;
    $v=$state;$v['private_padding']=str_repeat('x',1048576);$variants['inner state beyond one MiB']=$v;
    foreach($variants as$name=>$variant){
        $save($variant,$receipt);[$code,$body,,$raw]=$request('study_role=admin','GET',$view);
        studyEndpointCheck($name.' fails closed with fixed null metadata',$code===503&&($body['operational']['status']??'')==='failed'&&($body['operational']['protocol_sha256']??null)===null&&!str_contains($raw,'fixture-private-')&&strlen($raw)<=2048);
    }
    $saved=$save($state,$receipt);$invalid=$receipt;$invalid['observed_at_utc']=gmdate('Y-m-d\TH:i:s\Z',time()+86400);
    $save($state,$invalid);[$code,$body]=$request('study_role=admin','GET',$view);
    studyEndpointCheck('future receipt does not become current cron evidence',$code===503&&($body['operational']['status']??'')==='failed');
    file_put_contents($studyDir.'/state.json',str_repeat('x',2097153));
    [$code,$body]=$request('study_role=admin','GET',$view);
    studyEndpointCheck('outer private file is bounded before JSON parsing',$code===503&&($body['operational']['status']??'')==='failed');
    $saved=$save($state,$receipt);

    chmod($studyDir.'/state.json',0644);[$code,$body]=$request('study_role=admin','GET',$view);clearstatcache(true,$studyDir.'/state.json');
    studyEndpointCheck('publicly readable state is rejected rather than repaired',$code===503&&($body['operational']['status']??'')==='failed'&&(fileperms($studyDir.'/state.json')&0777)===0644);chmod($studyDir.'/state.json',0600);
    chmod($studyDir.'/lock',0644);[$code,$body]=$request('study_role=admin','GET',$view);clearstatcache(true,$studyDir.'/lock');
    studyEndpointCheck('unsafe lock is rejected without chmod',$code===503&&($body['operational']['status']??'')==='failed'&&(fileperms($studyDir.'/lock')&0777)===0644);chmod($studyDir.'/lock',0600);
    chmod($studyDir,0755);[$code,$body]=$request('study_role=admin','GET',$view);clearstatcache(true,$studyDir);
    studyEndpointCheck('unsafe private directory is rejected without repair',$code===503&&($body['operational']['status']??'')==='failed'&&(fileperms($studyDir)&0777)===0755);chmod($studyDir,0700);

    $target=$private.'/outside-state';file_put_contents($target,$saved);chmod($target,0600);
    unlink($studyDir.'/state.json');symlink($target,$studyDir.'/state.json');
    [$code,$body]=$request('study_role=admin','GET',$view);
    studyEndpointCheck('state symlink is never followed and target is unchanged',$code===503&&($body['operational']['status']??'')==='failed'&&file_get_contents($target)===$saved);
    unlink($studyDir.'/state.json');link($target,$studyDir.'/state.json');
    [$code,$body]=$request('study_role=admin','GET',$view);
    studyEndpointCheck('multiply linked state is rejected',$code===503&&($body['operational']['status']??'')==='failed');
    unlink($studyDir.'/state.json');$saved=$save($state,$receipt);

    $input=$dir.'/tools/extract_conversion_profiles.py';$inputBefore=file_get_contents($input);
    file_put_contents($input,$inputBefore."# synthetic changed recipe\n");
    [$code,$body]=$request('study_role=admin','GET',$view);
    studyEndpointCheck('actual current source recipe mismatch fails closed without changing registry',$code===503&&($body['operational']['status']??'')==='failed'&&file_get_contents($studyDir.'/state.json')===$saved);
    file_put_contents($input,$inputBefore);
    [$code,$body]=$request('study_role=admin','GET',$view);
    studyEndpointCheck('restoring the exact reader recipe restores only the technical projection',$code===200&&($body['operational']['status']??'')==='saved_metadata_valid'&&$body['operational']['ordinary_cron_provenance']==='unknown');
}catch(Throwable $e){studyEndpointCheck('private research HTTP fixture completes',false);fwrite(STDERR,'purchase_endpoint_fixture_failed: '.get_class($e).PHP_EOL);}
finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}studyEndpointRemove($dir);studyEndpointRemove($private);}
echo "purchase study endpoint: $passed passed, $failed failed\n";exit($failed?1:0);
