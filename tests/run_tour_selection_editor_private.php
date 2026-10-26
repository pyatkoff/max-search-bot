<?php
declare(strict_types=1);
// Actual loopback PHP/session/HTTP boundary with the existing synthetic selection DB.
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/selection-private-http-'.bin2hex(random_bytes(8));mkdir($tmp,0700);mkdir($tmp.'/manager',0700);mkdir($tmp.'/sessions',0700);
$proc=null;$pipes=[];$checks=0;$artifactBackend=$argv[1]??'';$artifactReleases=$argv[2]??'';
if($artifactBackend!=='')require_once $artifactBackend.'/services/ManagerTourSelectionEditorHost.php';
function privateCheck(string $name,bool $ok):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);++$checks;echo "PASS $name\n";}
function privateRemove(string $p):void{if(is_link($p)||is_file($p)){unlink($p);return;}foreach(array_diff(scandir($p),['.','..'])as$n)privateRemove($p.'/'.$n);rmdir($p);}
try {
    foreach(['tour-selection-editor-api.php','tour-selections-api.php','tour-selection-photo.php']as$name)copy($root.'/manager/'.$name,$tmp.'/manager/'.$name);
    symlink($root.'/manager/lib',$tmp.'/manager/lib');symlink($root.'/services',$tmp.'/services');
    $config=<<<'PHP'
<?php
require_once ROOT.'/tests/support/manager-tour-selection.php';
session_save_path(FIXTURE.'/sessions');
$pdo=deliveryDb();
if(is_file(FIXTURE.'/draft.sqlite')){
    $pdo=new PDO('sqlite:'.FIXTURE.'/draft.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('NOW',static fn():string=>gmdate('Y-m-d H:i:s'));
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
}else $pdo->exec('VACUUM INTO '.$pdo->quote(FIXTURE.'/draft.sqlite'));
register_shutdown_function(static function()use($pdo){file_put_contents(FIXTURE.'/audit.json',json_encode(['reads'=>$pdo->query('SELECT last_read_message_id FROM manager_conversation_reads')->fetchColumn(),'messages'=>$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn(),'drafts'=>$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_drafts')->fetchColumn()]));});
PHP;
    $constants="define('ROOT',".var_export($root,true).");define('FIXTURE',".var_export($tmp,true).");";
    file_put_contents($tmp.'/config.php',str_replace('<?php','<?php '.$constants,$config));
    $shellRoute=$artifactBackend!==''?"if(\$path==='/manager/tour-selection-editor.php'){require_once ".var_export($artifactBackend.'/services/ManagerTourSelectionEditorHost.php',true).";DiagnosticLogger::setFile(".var_export(dirname($artifactReleases).'/fixture.log',true).");require ".var_export($artifactBackend.'/manager/tour-selection-editor.php',true).";return;}\n":'';
    file_put_contents($tmp.'/router.php',"<?php\n\$path=parse_url(\$_SERVER['REQUEST_URI'],PHP_URL_PATH);\n".$shellRoute."if(\$path==='/manager/fixture-session'){require __DIR__.'/config.php';require __DIR__.'/manager/lib/ManagerHttp.php';ManagerHttp::startJson();\$_SESSION['manager_id']=(int)(\$_GET['actor']??7);\$_SESSION['csrf']='synthetic-csrf';ManagerHttp::respond(['ok'=>true]);}\nif(in_array(\$path,['/manager/tour-selection-editor-api.php','/manager/tour-selections-api.php'],true)){require __DIR__.\$path;return;}\nhttp_response_code(404);\n");
    $sock=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$sock)throw new RuntimeException($errstr);$port=(int)substr(strrchr(stream_socket_get_name($sock,false),':'),1);fclose($sock);
    $proc=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,$tmp.'/router.php'],[['pipe','r'],['file',$tmp.'/server.log','a'],['file',$tmp.'/server.log','a']],$pipes,$tmp);
    if(!is_resource($proc))throw new RuntimeException('fixture server unavailable');
    $request=static function(string $path,array $data=[],string $cookie='',string $method='POST')use($port):array{
        $body=json_encode($data);$headers="Content-Type: application/json\r\n".($cookie!==''?'Cookie: '.$cookie."\r\n":'');
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$method==='POST'?$body:'','ignore_errors'=>true,'timeout'=>5]]);
        $bytes=@file_get_contents('http://127.0.0.1:'.$port.$path,false,$context);$raw=$http_response_header??[];preg_match('/\s([0-9]{3})\s/',$raw[0]??'',$match);
        return ['status'=>(int)($match[1]??0),'headers'=>implode("\n",$raw),'data'=>json_decode((string)$bytes,true),'body'=>(string)$bytes];
    };
    for($i=0;$i<50;$i++){if(($connection=@fsockopen('127.0.0.1',$port))!==false){fclose($connection);break;}usleep(20000);}
    $path='/manager/tour-selection-editor-api.php';$r=$request($path);
    privateCheck('anonymous private API fails closed',$r['status']===401&&($r['data']['error']??'')==='unauthorized');
    if($artifactBackend!==''){
        $installed=ManagerTourSelectionEditorHost::installed($artifactBackend,$artifactReleases);
        $r=$request('/manager/tour-selection-editor.php',[],'','GET');
        if($r['status']!==200||!str_contains($r['body'],$installed['package_id']))fwrite(STDERR,json_encode($r)."\n".(string)file_get_contents($tmp.'/server.log'));
        privateCheck('rolled-back A serves real editor shell without cookie',$r['status']===200&&str_contains($r['body'],'id="editorLauncher"')&&stripos($r['headers'],'Set-Cookie:')===false&&str_contains($r['body'],$installed['package_id']));
        foreach(ManagerTourSelectionEditorHost::ASSETS as$name=>$mime){
            $r=$request('/manager/tour-selection-editor.php?asset='.$name.'&package='.$installed['package_id'],[],'','GET');
            privateCheck('rolled-back A serves sealed '.$name,$r['status']===200&&hash('sha256',$r['body'])===hash_file('sha256',$installed['directory'].'/'.$name));
        }
    }
    $r=$request('/manager/fixture-session?actor=7',[],'','GET');preg_match('/Set-Cookie:\s*([^;\r\n]+)/i',$r['headers'],$match);$cookie=$match[1]??'';
    privateCheck('canonical cookie remains secure host-only manager path',$cookie!==''&&stripos($r['headers'],'path=/manager/')!==false&&stripos($r['headers'],'secure')!==false&&stripos($r['headers'],'httponly')!==false&&stripos($r['headers'],'domain=')===false);
    $base=['action'=>'editor_context','csrf'=>'synthetic-csrf','editor_actor'=>7,'conversation_id'=>1];
    $r=$request($path,array_replace($base,['csrf'=>'bad']),$cookie);privateCheck('missing or bad CSRF rejected by canonical guard',$r['status']===403&&($r['data']['error']??'')==='csrf');
    $r=$request($path,array_replace($base,['editor_actor'=>8]),$cookie);privateCheck('foreign actor cannot use existing account session',$r['status']===409&&($r['data']['error']??'')==='stale_actor');
    $r=$request($path,$base,$cookie);privateCheck('visible own context uses real visibility without transcript',$r['status']===200&&($r['data']['context']['id']??0)===1&&($r['data']['context']['actor']??0)===7&&!isset($r['data']['messages'],$r['data']['contact']));
    $audit=json_decode(file_get_contents($tmp.'/audit.json'),true);privateCheck('context read does not mark transcript or write a draft',$audit===['reads'=>0,'messages'=>1,'drafts'=>0]);
    $r=$request($path,array_replace($base,['conversation_id'=>999]),$cookie);privateCheck('arbitrary conversation ID fails closed',$r['status']===404);
    $r=$request($path,array_replace($base,['action'=>'get','editor_resource'=>'../api.php']),$cookie);privateCheck('resource input cannot enter a foreign manager API',$r['status']===422&&($r['data']['error']??'')==='invalid_editor_resource');
    $r=$request($path,array_replace($base,['action'=>'get','editor_resource'=>'tour-selections-api.php']),$cookie);privateCheck('original JSON is delegated to canonical selection GET',$r['status']===200&&($r['data']['selection']['conversation_id']??0)===1);
    $save=array_replace($base,['action'=>'save','editor_resource'=>'tour-selections-api.php','version'=>0,'operation_id'=>str_repeat('c',32),'draft'=>['title'=>'Синтетический сохранённый черновик','intro'=>'','offers'=>[]]]);
    $r=$request($path,$save,$cookie);privateCheck('canonical protected draft save commits one version',$r['status']===200&&($r['data']['selection']['version']??0)===1);
    $r=$request($path,$save,$cookie);privateCheck('exact protected retry reuses canonical receipt',$r['status']===200&&($r['data']['selection']['version']??0)===1);
    $r=$request($path,array_replace($base,['action'=>'get','editor_resource'=>'tour-selections-api.php']),$cookie);privateCheck('protected draft reload reads same persisted conditions',$r['status']===200&&($r['data']['selection']['draft']['title']??'')===$save['draft']['title']&&($r['data']['selection']['version']??0)===1);
    $request('/manager/fixture-session?actor=8',[],$cookie,'GET');
    $r=$request($path,array_replace($base,['action'=>'save','editor_resource'=>'tour-selections-api.php','version'=>0,'operation_id'=>str_repeat('a',32),'draft'=>['title'=>'No cross-account write','intro'=>'','offers'=>[]]]),$cookie);
    privateCheck('same cookie and CSRF after account switch cannot write old editor',$r['status']===409&&($r['data']['error']??'')==='stale_actor');
    $audit=json_decode(file_get_contents($tmp.'/audit.json'),true);privateCheck('rejected switched-account mutation saved no new draft',$audit['drafts']===1);
    echo "private editor HTTP: $checks PASS\n";
}finally{if(is_resource($proc)){proc_terminate($proc);proc_close($proc);}foreach($pipes as$p)if(is_resource($p))fclose($p);privateRemove($tmp);}
