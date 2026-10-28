<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$base=dirname(__DIR__);$dir=sys_get_temp_dir().'/purchase-endpoint-'.bin2hex(random_bytes(8));
$process=null;$passed=0;$failed=0;
function studyEndpointCheck(string $name,bool $ok):void{global$passed,$failed;echo($ok?'PASS  ':'FAIL  ').$name.PHP_EOL;if($ok)$passed++;else$failed++;}
function studyEndpointRemove(string $p):void{if(is_file($p)||is_link($p)){unlink($p);return;}if(!is_dir($p))return;foreach(scandir($p)as$n)if($n!=='.'&&$n!=='..')studyEndpointRemove($p.'/'.$n);rmdir($p);}
try{
    mkdir($dir,0700);mkdir($dir.'/manager',0700);mkdir($dir.'/manager/lib',0700);mkdir($dir.'/services',0700);
    copy($base.'/manager/purchase-study-api.php',$dir.'/manager/purchase-study-api.php');
    copy($base.'/manager/lib/ManagerHttp.php',$dir.'/manager/lib/ManagerHttp.php');
    file_put_contents($dir.'/config.php', <<<'PHP'
<?php
class ProjectConfig{static function projectId():string{return $_COOKIE['study_project']??'anytour';}}
class ProjectAccessService{
 static bool $readOnly=false;
 static function initializeReadOnly():void{if(($_COOKIE['study_init']??'')==='throw')throw new RuntimeException('PRIVATE_SCHEMA_FAILURE');self::$readOnly=true;}
 static function canAccess(int $id,string $project):bool{return self::$readOnly&&$id===7&&$project==='anytour'&&($_COOKIE['study_access']??'yes')==='yes';}
}
class ConversationDb{static function connection():PDO{file_put_contents(getenv('PURCHASE_ENDPOINT_MARKER'),'connection\n',FILE_APPEND);return new PDO('sqlite::memory:');}}
PHP);
    file_put_contents($dir.'/services/ManagerRequestContext.php', <<<'PHP'
<?php
class ManagerRequestContext{
 static function startSession():void{}
 static function manager():?array{if(!ProjectAccessService::$readOnly)file_put_contents(getenv('PURCHASE_ENDPOINT_MARKER'),'AUTH_SCHEMA_UPSERT',FILE_APPEND);$r=$_COOKIE['study_role']??'none';return$r==='none'?null:['id'=>7,'role'=>$r];}
 static function managerId():int{return self::manager()?7:0;}
 static function isAdmin(array $m):bool{return($m['role']??'')==='admin';}
}
PHP);
    file_put_contents($dir.'/services/ModeledConversionPurchaseStudy.php', <<<'PHP'
<?php
class ModeledConversionPurchaseStudy{
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
    $env=getenv();$env['PURCHASE_ENDPOINT_MARKER']=$dir.'/marker';
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
}catch(Throwable $e){studyEndpointCheck('private research HTTP fixture completes',false);fwrite(STDERR,'purchase_endpoint_fixture_failed: '.get_class($e).PHP_EOL);}
finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}studyEndpointRemove($dir);}
echo "purchase study endpoint: $passed passed, $failed failed\n";exit($failed?1:0);
