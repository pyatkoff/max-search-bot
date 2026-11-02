<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelWorkHealth.php';
require_once dirname(__DIR__).'/services/HotelWorkCronVerifier.php';
require_once dirname(__DIR__).'/services/HotelServerRuntimeConfig.php';
require_once dirname(__DIR__).'/services/HotelServerPrivateUpload.php';

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
    HotelWorkHealth::record('ok','server_cycle');
    hotelHealthCheck('server cycle stage is a bounded observable outcome',HotelWorkHealth::snapshot()['stage']==='server_cycle');
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
    foreach (['HotelWorkHealth','DiagnosticLogger','HotelServerCronRuntime','HotelServerRuntimeConfig','HotelServerCycle','HotelServerInputs','HotelRetainedBaseline','HotelServerLane','HotelServerProvider'] as $name) copy(dirname(__DIR__).'/services/'.$name.'.php',$app.'/services/'.$name.'.php');
    foreach (['ProjectAccessService','HotelWorkEventService'] as $name) file_put_contents($app.'/services/'.$name.'.php','<?php');
    file_put_contents($app.'/config.php', <<<'PHP'
<?php
if (is_file(__DIR__.'/fail')) throw new RuntimeException('PRIVATE BOOTSTRAP ERROR');
// A disabled collector must not construct even an invalid private AI policy.
$GLOBALS['ANYTOUR_HOTEL_SERVER_AI_POLICY']=['invalid_private_policy'=>'PRIVATE POLICY MUST NOT LOAD'];
class ProjectAccessService {public static function initializeReadOnly(){}}
class ConversationDb {public static function connection(){return null;}}
class ManagerPreparationOAuth {
    public const HOTEL_SCOPE='hotel_work:write';
    public function __construct($pdo=null){}
    public function eventConnectionAuthorizedAcrossProfiles(string $domain,string $connection,int $admin):bool {
        if($domain!=='hotels'||$connection!==str_repeat('a',32)||$admin!==1)throw new RuntimeException('PRIVATE WRONG AUTHORITY');
        if(is_file(__DIR__.'/authfail'))throw new RuntimeException('PRIVATE AUTHORITY UNAVAILABLE');
        return true;
    }
}
class HotelWorkEventService {
    public function __construct($pdo=null,private $connectionAuthorized=null){if(!is_callable($connectionAuthorized))throw new RuntimeException('PRIVATE MISSING AUTHORITY');}
    public function hasActiveSubscriptions(){return ($this->connectionAuthorized)(str_repeat('a',32),1,['hotel_work:write'])&&!is_file(__DIR__.'/waiting');}
    public function deliverPending(){
        if(is_file(__DIR__.'/deliveryfail'))throw new RuntimeException('PRIVATE DELIVERY ERROR');
        return ['subscriptions'=>1,'accepted'=>0,'retried'=>0,'stopped'=>is_file(__DIR__.'/stopped')?1:0,'unchanged'=>1];
    }
}
PHP);
    $checkpoint=$tmp.'/max-search-bot-private-logs/hotel_work_health.json';
    foreach (['ok'=>0,'waiting'=>0,'authfail'=>1,'deliveryfail'=>1,'stopped'=>1,'fail'=>1] as $mode=>$expected) {
        if ($mode!=='ok') file_put_contents($app.'/'.$mode,'1');
        $out=[];$code=-1;exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($app.'/tools/hotel_work.php').' tick 2>&1',$out,$code);
        $row=json_decode((string)file_get_contents($checkpoint),true);
        $state=['ok'=>'ok','waiting'=>'awaiting_connection','authfail'=>'failed','deliveryfail'=>'failed','stopped'=>'failed','fail'=>'failed'][$mode];
        hotelHealthCheck('actual CLI '.$mode.' records bounded outcome',$code===$expected&&($row['state']??null)===$state&&($row['trigger']??null)==='manual'&&!str_contains(implode("\n",$out),'PRIVATE')&&str_contains(implode("\n",$out),'"provider_calls":0'));
        if ($mode==='authfail') hotelHealthCheck('unknown authority is not a healthy disconnected hotel worker',($row['stage']??null)==='subscription');
        if ($mode==='fail') hotelHealthCheck('bootstrap failure distinguished',($row['stage']??null)==='bootstrap');
        if ($mode!=='ok') unlink($app.'/'.$mode);
    }
    $out=[];$code=-1;
    exec('HOTEL_WORK_TRIGGER=cron '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($app.'/tools/hotel_work.php').' tick 2>&1',$out,$code);
    $row=json_decode((string)file_get_contents($checkpoint),true);
    hotelHealthCheck('installed cron marker captured by real CLI',$code===0&&($row['trigger']??null)==='cron');
    $runtimeDir=$tmp.'/max-search-bot-hotel-server';$runtimeFile=$runtimeDir.'/runtime.json';
    hotelHealthCheck('absent private runtime file preserves dormant configuration',HotelServerRuntimeConfig::load($app)===null);
    mkdir($runtimeDir,0700);
    $runtime=['schema'=>'hotel-server-runtime-v1','campaign_id'=>'synthetic-campaign','bootstrap_sha256'=>str_repeat('a',64),
        'flags'=>['enabled'=>false,'dispatch_allowed'=>false,'replacement_dispatch_allowed'=>false],
        'policy'=>['synthetic'=>'PRIVATE POLICY']];
    $writeRuntime=static function(array|string $v)use($runtimeFile):void{
        file_put_contents($runtimeFile,is_array($v)?json_encode($v,JSON_THROW_ON_ERROR):$v);chmod($runtimeFile,0600);clearstatcache();
    };
    $writeRuntime($runtime);$loaded=HotelServerRuntimeConfig::load($app);
    hotelHealthCheck('private JSON pins exact campaign without enabling or constructing a provider',
        $loaded['campaign_id']==='synthetic-campaign'&&$loaded['flags']['enabled']===false
        &&$loaded['configuration_sha256']===hash_file('sha256',$runtimeFile));
    foreach(['string_flag','foreign_key','broken_json','oversized','public_permissions','symlink'] as $mode){
        $bad=$runtime;
        if($mode==='string_flag')$bad['flags']['enabled']='true';
        if($mode==='foreign_key')$bad['arbitrary_include']='PRIVATE PHP PATH';
        $writeRuntime($mode==='broken_json'?'{PRIVATE':($mode==='oversized'?str_repeat('x',65537):$bad));
        if($mode==='public_permissions'){chmod($runtimeFile,0644);clearstatcache();}
        if($mode==='symlink'){rename($runtimeFile,$runtimeDir.'/target');symlink($runtimeDir.'/target',$runtimeFile);}
        $rejected=false;try{HotelServerRuntimeConfig::load($app);}catch(Throwable $e){$rejected=true;}
        hotelHealthCheck('unsafe private runtime '.$mode.' is rejected',$rejected);
        if($mode==='symlink'){unlink($runtimeFile);unlink($runtimeDir.'/target');}
    }
    // The real CLI must hold before legacy delivery or provider construction on bad/conflicting config.
    foreach(['conflicting'=>$runtime,'malformed'=>'{PRIVATE RUNTIME ERROR'] as $mode=>$input){
        $writeRuntime($input);$out=[];$code=-1;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($app.'/tools/hotel_work.php').' tick 2>&1',$out,$code);
        $row=json_decode((string)file_get_contents($checkpoint),true);
        hotelHealthCheck('actual CLI '.$mode.' runtime fails closed before delivery',
            $code===1&&$row['state']==='failed'&&$row['stage']==='bootstrap'
            &&!str_contains(implode("\n",$out),'PRIVATE'));
    }
    unlink($runtimeFile);rmdir($runtimeDir);
    // The HTTP ingress can only retain one administrator-pinned opaque file; it never imports it.
    $payload="synthetic sealed bytes\0\xff";$token=str_repeat('b',64);$uploadNow=1800000000;
    $uploadTicket=['schema'=>'hotel-server-private-upload-v1','operation_id'=>str_repeat('c',64),
        'payload_bytes'=>strlen($payload),'payload_sha256'=>hash('sha256',$payload),
        'token_sha256'=>hash('sha256',$token),'created_at'=>$uploadNow-1,'expires_at'=>$uploadNow+300];
    $uploadRequest=['REQUEST_METHOD'=>'POST','HTTPS'=>'on','HTTP_HOST'=>'app.anytoour.ru',
        'CONTENT_TYPE'=>'application/octet-stream','CONTENT_LENGTH'=>(string)strlen($payload),
        'HTTP_AUTHORIZATION'=>'Bearer '.$token];
    $uploadFixture=static function(string $name,?string $bytes=null)use($tmp,$uploadTicket):array{
        $base=$tmp.'/upload-'.$name;$app=$base.'/app';$dir=$base.'/max-search-bot-hotel-server/ingress-v1';
        mkdir($base,0700);mkdir($app,0700);mkdir(dirname($dir),0700);mkdir($dir,0700);
        $ticket=$uploadTicket;if($bytes!==null){$ticket['payload_bytes']=strlen($bytes);$ticket['payload_sha256']=hash('sha256',$bytes);}
        file_put_contents($dir.'/ticket.json',json_encode($ticket,JSON_THROW_ON_ERROR));chmod($dir.'/ticket.json',0600);
        return [$app,$dir];
    };
    $uploadCall=static function(string $root,array $request,string $bytes)use($uploadNow):array{
        $stream=fopen('php://temp','w+b');fwrite($stream,$bytes);rewind($stream);
        try{return HotelServerPrivateUpload::receive($root,$request,$stream,$uploadNow);}finally{fclose($stream);}
    };
    // The dedicated header transports the same ticket-bound capability through ordinary CGI headers.
    $customRequest=$uploadRequest;unset($customRequest['HTTP_AUTHORIZATION']);
    $customRequest['HTTP_X_HOTEL_UPLOAD_CAPABILITY']=$token;
    foreach(['missing','empty','prefixed','non_scalar','wrong_token','invalid_custom_with_valid_legacy',
        'conflicting_legacy','invalid_legacy','empty_legacy'] as $mode){
        [$uploadApp,$uploadDir]=$uploadFixture('custom-capability-'.$mode);$request=$customRequest;
        if($mode==='missing')unset($request['HTTP_X_HOTEL_UPLOAD_CAPABILITY']);
        if($mode==='empty')$request['HTTP_X_HOTEL_UPLOAD_CAPABILITY']='';
        if($mode==='prefixed')$request['HTTP_X_HOTEL_UPLOAD_CAPABILITY']='Bearer '.$token;
        if($mode==='non_scalar')$request['HTTP_X_HOTEL_UPLOAD_CAPABILITY']=[$token];
        if($mode==='wrong_token')$request['HTTP_X_HOTEL_UPLOAD_CAPABILITY']=str_repeat('d',64);
        if($mode==='invalid_custom_with_valid_legacy'){
            $request['HTTP_X_HOTEL_UPLOAD_CAPABILITY']='invalid';$request['HTTP_AUTHORIZATION']='Bearer '.$token;
        }
        if($mode==='conflicting_legacy')$request['HTTP_AUTHORIZATION']='Bearer '.str_repeat('d',64);
        if($mode==='invalid_legacy')$request['HTTP_AUTHORIZATION']='Basic invalid';
        if($mode==='empty_legacy')$request['HTTP_AUTHORIZATION']='';
        $result=$uploadCall($uploadApp,$request,$payload);
        hotelHealthCheck('CGI capability rejects '.$mode.' before writing',
            $result===['code'=>404,'body'=>['ok'=>false,'state'=>'unavailable']]
            &&!file_exists($uploadDir.'/upload.lock')&&!file_exists($uploadDir.'/chunks')
            &&!file_exists($uploadDir.'/upload.part')&&!file_exists($uploadDir.'/sealed-inputs.bin'));
    }
    foreach(['custom_only','matching_legacy'] as $mode){
        [$uploadApp,$uploadDir]=$uploadFixture('custom-capability-'.$mode);$request=$customRequest;
        if($mode==='matching_legacy')$request['HTTP_AUTHORIZATION']='Bearer '.$token;
        $result=$uploadCall($uploadApp,$request,$payload);$sealed=$uploadDir.'/sealed-inputs.bin';
        hotelHealthCheck('ticket-bound CGI capability stores exact bytes with '.$mode,
            $result===['code'=>200,'body'=>['ok'=>true,'state'=>'stored','bytes'=>strlen($payload),'sha256'=>hash('sha256',$payload)]]
            &&file_get_contents($sealed)===$payload&&(fileperms($sealed)&0777)===0600
            &&!file_exists($uploadDir.'/upload.part'));
    }
    // Parser checks cover ambiguous/invalid process identity, not a substitute script-file owner.
    $uidParser=new ReflectionMethod(HotelServerPrivateUpload::class,'uidFromProcStatus');$uidParser->setAccessible(true);
    hotelHealthCheck('Linux process credentials preserve one canonical identity',
        $uidParser->invoke(null,"Name:\tphp-cgi\nUid:\t1012\t1012\t1012\t1012\nGid:\t1012\t1012\t1012\t1012\n")===1012
        &&$uidParser->invoke(null,"Uid:\t0\t0\t0\t0\n")===0);
    foreach([
        'mixed'=>"Uid:\t1012\t0\t1012\t1012\n",
        'duplicate'=>"Uid:\t1012\t1012\t1012\t1012\nUid:\t1012\t1012\t1012\t1012\n",
        'missing'=>"Name:\tphp-cgi\n",
        'leading_zero'=>"Uid:\t01012\t01012\t01012\t01012\n",
        'overflow'=>"Uid:\t4294967296\t4294967296\t4294967296\t4294967296\n",
        'split_line'=>"Uid:\t1012\t1012\n\t1012\t1012\n",
        'too_large'=>str_repeat('x',65537)."\nUid:\t1012\t1012\t1012\t1012\n",
    ] as $mode=>$status)hotelHealthCheck('Linux process credentials reject '.$mode,$uidParser->invoke(null,$status)===null);
    if(PHP_OS_FAMILY==='Linux'){
        // Real child processes exercise receive(), with no extra fixture source file or server access.
        $uidChild=<<<'PHP'
require $argv[1];
$request=json_decode(base64_decode($argv[3],true),true,32,JSON_THROW_ON_ERROR);
$payload=base64_decode($argv[4],true);$stream=fopen('php://temp','w+b');fwrite($stream,$payload);rewind($stream);
try{$result=HotelServerPrivateUpload::receive($argv[2],$request,$stream,(int)$argv[5]);}finally{fclose($stream);}
echo json_encode(['posix_module'=>extension_loaded('posix'),'posix_function'=>function_exists('posix_geteuid'),
    'fileowner_function'=>function_exists('fileowner'),'result'=>$result],JSON_THROW_ON_ERROR),"\n";
PHP;
        $uidChildCall=static function(string $root,array $request,string $options)use($uidChild,$payload,$uploadNow):array{
            $lines=[];$code=-1;
            exec(escapeshellarg(PHP_BINARY).' '.$options.' -r '.escapeshellarg($uidChild).' -- '
                .escapeshellarg(dirname(__DIR__).'/services/HotelServerPrivateUpload.php').' '.escapeshellarg($root).' '
                .escapeshellarg(base64_encode(json_encode($request,JSON_THROW_ON_ERROR))).' '.escapeshellarg(base64_encode($payload)).' '
                .escapeshellarg((string)$uploadNow).' 2>&1',$lines,$code);
            return [$code,json_decode(implode("\n",$lines),true)];
        };
        [$uploadApp,$uploadDir]=$uploadFixture('kernel-uid-no-posix');
        [$code,$child]=$uidChildCall($uploadApp,$customRequest,'-n');$sealed=$uploadDir.'/sealed-inputs.bin';
        hotelHealthCheck('real no-POSIX process retains private exact ingress storage',
            $code===0&&($child['posix_module']??null)===false&&($child['posix_function']??null)===false
            &&($child['result']??null)===['code'=>200,'body'=>['ok'=>true,'state'=>'stored','bytes'=>strlen($payload),'sha256'=>hash('sha256',$payload)]]
            &&is_file($sealed)&&file_get_contents($sealed)===$payload&&(fileperms($sealed)&0777)===0600);
        [$uploadApp,$uploadDir]=$uploadFixture('kernel-uid-ownership-unavailable');
        [$code,$child]=$uidChildCall($uploadApp,$customRequest,'-n -d disable_functions=fileowner');
        hotelHealthCheck('real no-POSIX process fails closed without ownership metadata',
            $code===0&&($child['posix_module']??null)===false&&($child['fileowner_function']??null)===false
            &&($child['result']??null)===['code'=>404,'body'=>['ok'=>false,'state'=>'unavailable']]
            &&!file_exists($uploadDir.'/upload.lock')&&!file_exists($uploadDir.'/sealed-inputs.bin'));
        if(extension_loaded('posix')){
            [$uploadApp,$uploadDir]=$uploadFixture('kernel-uid-posix-explicitly-disabled');
            [$code,$child]=$uidChildCall($uploadApp,$customRequest,'-d disable_functions=posix_geteuid');
            hotelHealthCheck('loaded POSIX with disabled function cannot use the kernel fallback',
                $code===0&&($child['posix_module']??null)===true&&($child['posix_function']??null)===false
                &&($child['result']??null)===['code'=>404,'body'=>['ok'=>false,'state'=>'unavailable']]
                &&!file_exists($uploadDir.'/upload.lock')&&!file_exists($uploadDir.'/sealed-inputs.bin'));
        }
    }
    foreach(['missing_ticket','wrong_token','method','plain_http','foreign_host','content_type','origin','chunked','wrong_length',
        'expired','future','long_lifetime','extra_key','size_cap','public_ticket','public_directory','ticket_symlink'] as $mode){
        [$uploadApp,$uploadDir]=$uploadFixture($mode);$request=$uploadRequest;$ticket=$uploadTicket;
        if($mode==='wrong_token')$request['HTTP_AUTHORIZATION']='Bearer '.str_repeat('d',64);
        if($mode==='method')$request['REQUEST_METHOD']='GET';
        if($mode==='plain_http')$request['HTTPS']='off';
        if($mode==='foreign_host')$request['HTTP_HOST']='other.example';
        if($mode==='content_type')$request['CONTENT_TYPE']='text/plain';
        if($mode==='origin')$request['HTTP_ORIGIN']='https://other.example';
        if($mode==='chunked')$request['HTTP_TRANSFER_ENCODING']='chunked';
        if($mode==='wrong_length')$request['CONTENT_LENGTH']='1';
        if($mode==='expired')$ticket['expires_at']=$uploadNow;
        if($mode==='future')$ticket['created_at']=$uploadNow+1;
        if($mode==='long_lifetime')$ticket['expires_at']=$ticket['created_at']+3601;
        if($mode==='extra_key')$ticket['target_path']='PRIVATE ARBITRARY PATH';
        if($mode==='size_cap'){$ticket['payload_bytes']=HotelServerPrivateUpload::MAX_BYTES+1;$request['CONTENT_LENGTH']=(string)$ticket['payload_bytes'];}
        file_put_contents($uploadDir.'/ticket.json',json_encode($ticket,JSON_THROW_ON_ERROR));
        if($mode==='missing_ticket')unlink($uploadDir.'/ticket.json');
        if($mode==='public_ticket')chmod($uploadDir.'/ticket.json',0644);
        if($mode==='public_directory')chmod($uploadDir,0755);
        if($mode==='ticket_symlink'){rename($uploadDir.'/ticket.json',$uploadDir.'/target');symlink($uploadDir.'/target',$uploadDir.'/ticket.json');}
        $result=$uploadCall($uploadApp,$request,$payload);
        hotelHealthCheck('private ingress rejects '.$mode.' before writing',
            $result===['code'=>404,'body'=>['ok'=>false,'state'=>'unavailable']]
            &&!file_exists($uploadDir.'/upload.part')&&!file_exists($uploadDir.'/sealed-inputs.bin')&&!file_exists($uploadDir.'/upload.lock'));
    }
    [$uploadApp,$uploadDir]=$uploadFixture('valid');$maskBefore=umask();
    $result=$uploadCall($uploadApp,$uploadRequest,$payload);$sealed=$uploadDir.'/sealed-inputs.bin';
    hotelHealthCheck('exact sealed ingress bytes survive private atomic storage',
        $result['code']===200&&$result['body']['state']==='stored'&&$result['body']['bytes']===strlen($payload)
        &&$result['body']['sha256']===hash('sha256',$payload)&&file_get_contents($sealed)===$payload
        &&(fileperms($sealed)&0777)===0600&&!file_exists($uploadDir.'/upload.part')
        &&!str_starts_with(realpath($sealed),realpath($uploadApp).'/')&&umask()===$maskBefore);
    $inode=fileinode($sealed);
    hotelHealthCheck('same completed ingress is read back without replacing bytes',
        $uploadCall($uploadApp,$uploadRequest,$payload)===$result&&fileinode($sealed)===$inode&&file_get_contents($sealed)===$payload);
    file_put_contents($sealed,'unchanged conflicting bytes');
    hotelHealthCheck('existing different payload is held and not overwritten',
        $uploadCall($uploadApp,$uploadRequest,$payload)['code']===409&&file_get_contents($sealed)==='unchanged conflicting bytes');
    foreach(['short'=>substr($payload,1),'long'=>$payload.'x','different'=>str_repeat('x',strlen($payload))] as $mode=>$bytes){
        [$uploadApp,$uploadDir]=$uploadFixture('payload-'.$mode);
        $result=$uploadCall($uploadApp,$uploadRequest,$bytes);$partial=$uploadDir.'/upload.part';
        $before=file_get_contents($partial);
        hotelHealthCheck('incomplete '.$mode.' upload is held without automatic restart',
            $result['code']===409&&!file_exists($uploadDir.'/sealed-inputs.bin')&&(fileperms($partial)&0777)===0600
            &&$uploadCall($uploadApp,$uploadRequest,$payload)['code']===409&&file_get_contents($partial)===$before);
    }
    [$uploadApp,$uploadDir]=$uploadFixture('lock');$uploadLock=fopen($uploadDir.'/upload.lock','c');chmod($uploadDir.'/upload.lock',0600);
    flock($uploadLock,LOCK_EX);
    hotelHealthCheck('active upload is never taken over',
        $uploadCall($uploadApp,$uploadRequest,$payload)['code']===404&&!file_exists($uploadDir.'/upload.part'));
    flock($uploadLock,LOCK_UN);fclose($uploadLock);
    [$uploadApp,$uploadDir]=$uploadFixture('destination-symlink');file_put_contents($uploadDir.'/sentinel','unchanged');
    symlink($uploadDir.'/sentinel',$uploadDir.'/sealed-inputs.bin');
    hotelHealthCheck('destination symlink is held without touching its target',
        $uploadCall($uploadApp,$uploadRequest,$payload)['code']===409&&file_get_contents($uploadDir.'/sentinel')==='unchanged');
    // Exercise the real bounded-body protocol with a payload exceeding the observed FastCGI limit.
    $pieces=[str_repeat('a',65536),str_repeat('b',65536),"last\0\xff"];$chunkPayload=implode('',$pieces);
    $chunkRequest=static fn(int $index,string $bytes):array=>array_replace($uploadRequest,[
        'CONTENT_LENGTH'=>(string)strlen($bytes),'HTTP_X_HOTEL_CHUNK_INDEX'=>(string)$index,
        'HTTP_X_HOTEL_CHUNK_SHA256'=>hash('sha256',$bytes)]);
    foreach(['missing_index','missing_hash','negative','leading_zero','out_of_range','invalid_hash','wrong_length'] as $mode){
        [$uploadApp,$uploadDir]=$uploadFixture('chunk-header-'.$mode,$chunkPayload);$request=$chunkRequest(0,$pieces[0]);
        if($mode==='missing_index')unset($request['HTTP_X_HOTEL_CHUNK_INDEX']);
        if($mode==='missing_hash')unset($request['HTTP_X_HOTEL_CHUNK_SHA256']);
        if($mode==='negative')$request['HTTP_X_HOTEL_CHUNK_INDEX']='-1';
        if($mode==='leading_zero')$request['HTTP_X_HOTEL_CHUNK_INDEX']='00';
        if($mode==='out_of_range')$request['HTTP_X_HOTEL_CHUNK_INDEX']='3';
        if($mode==='invalid_hash')$request['HTTP_X_HOTEL_CHUNK_SHA256']='invalid';
        if($mode==='wrong_length')$request['CONTENT_LENGTH']='65535';
        hotelHealthCheck('bounded ingress rejects '.$mode.' before writing',
            $uploadCall($uploadApp,$request,$pieces[0])['code']===404&&!file_exists($uploadDir.'/upload.lock')
            &&!file_exists($uploadDir.'/chunks')&&!file_exists($uploadDir.'/sealed-inputs.bin'));
    }
    [$uploadApp,$uploadDir]=$uploadFixture('chunks-valid',$chunkPayload);$maskBefore=umask();
    $result=$uploadCall($uploadApp,$chunkRequest(1,$pieces[1]),$pieces[1]);$chunkFile=$uploadDir.'/chunks/001.bin';
    hotelHealthCheck('out of order fragment is retained privately without claiming full storage',
        $result['code']===200&&$result['body']===['ok'=>true,'state'=>'chunk_stored','index'=>1,'bytes'=>65536,
            'sha256'=>hash('sha256',$pieces[1]),'payload_sha256'=>hash('sha256',$chunkPayload)]
        &&file_get_contents($chunkFile)===$pieces[1]&&(fileperms($chunkFile)&0777)===0600
        &&(fileperms(dirname($chunkFile))&0777)===0700&&!file_exists($uploadDir.'/sealed-inputs.bin')&&umask()===$maskBefore);
    $inode=fileinode($chunkFile);
    hotelHealthCheck('completed fragment readback cannot replace its bytes',
        $uploadCall($uploadApp,$chunkRequest(1,$pieces[1]),$pieces[1])===$result&&fileinode($chunkFile)===$inode);
    hotelHealthCheck('different fragment under an occupied index cannot overwrite it',
        $uploadCall($uploadApp,$chunkRequest(1,$pieces[0]),$pieces[0])['code']===409&&file_get_contents($chunkFile)===$pieces[1]);
    $wholeRequest=array_replace($uploadRequest,['CONTENT_LENGTH'=>(string)strlen($chunkPayload)]);
    hotelHealthCheck('whole-body mode cannot replace an unfinished fragmented transfer',
        $uploadCall($uploadApp,$wholeRequest,$chunkPayload)['code']===409&&!file_exists($uploadDir.'/upload.part'));
    hotelHealthCheck('last indexed fragment alone does not complete missing earlier bytes',
        $uploadCall($uploadApp,$chunkRequest(2,$pieces[2]),$pieces[2])['body']['state']==='chunk_stored'
        &&!file_exists($uploadDir.'/sealed-inputs.bin'));
    $result=$uploadCall($uploadApp,$chunkRequest(0,$pieces[0]),$pieces[0]);$sealed=$uploadDir.'/sealed-inputs.bin';
    hotelHealthCheck('bounded requests assemble the exact pinned file in index order',
        $result['code']===200&&$result['body']===['ok'=>true,'state'=>'stored','bytes'=>strlen($chunkPayload),'sha256'=>hash('sha256',$chunkPayload)]
        &&file_get_contents($sealed)===$chunkPayload&&(fileperms($sealed)&0777)===0600&&!file_exists($uploadDir.'/upload.part'));
    $inode=fileinode($sealed);
    hotelHealthCheck('assembled full-file readback does not restart or overwrite',
        $uploadCall($uploadApp,$chunkRequest(2,$pieces[2]),$pieces[2])===$result&&fileinode($sealed)===$inode);
    foreach(['short'=>substr($pieces[0],1),'long'=>$pieces[0].'x','wrong_hash'=>$pieces[1]] as $mode=>$bytes){
        [$uploadApp,$uploadDir]=$uploadFixture('chunk-body-'.$mode,$chunkPayload);
        $result=$uploadCall($uploadApp,$chunkRequest(0,$pieces[0]),$bytes);$partial=$uploadDir.'/chunks/000.bin.part';
        $before=file_get_contents($partial);
        hotelHealthCheck('failed '.$mode.' fragment is preserved and cannot be restarted',
            $result['code']===409&&!file_exists($uploadDir.'/sealed-inputs.bin')&&(fileperms($partial)&0777)===0600
            &&$uploadCall($uploadApp,$chunkRequest(0,$pieces[0]),$pieces[0])['code']===409&&file_get_contents($partial)===$before);
    }
    [$uploadApp,$uploadDir]=$uploadFixture('chunk-full-hash',$chunkPayload.'x');
    $uploadCall($uploadApp,$chunkRequest(0,$pieces[0]),$pieces[0]);$uploadCall($uploadApp,$chunkRequest(1,$pieces[1]),$pieces[1]);
    $wrongLast=$pieces[2].'y';$result=$uploadCall($uploadApp,$chunkRequest(2,$wrongLast),$wrongLast);
    hotelHealthCheck('individual fragment hashes never substitute for the full ticket pin',
        $result['code']===409&&!file_exists($uploadDir.'/sealed-inputs.bin')
        &&file_get_contents($uploadDir.'/upload.part')===$chunkPayload.'y'
        &&$uploadCall($uploadApp,$chunkRequest(2,$pieces[2].'x'),$pieces[2].'x')['code']===409);
    foreach(['directory','file'] as $mode){
        [$uploadApp,$uploadDir]=$uploadFixture('chunk-symlink-'.$mode,$chunkPayload);
        mkdir($uploadDir.'/sentinel-dir',0700);file_put_contents($uploadDir.'/sentinel-dir/sentinel','unchanged');
        if($mode==='directory')symlink($uploadDir.'/sentinel-dir',$uploadDir.'/chunks');
        else{mkdir($uploadDir.'/chunks',0700);symlink($uploadDir.'/sentinel-dir/sentinel',$uploadDir.'/chunks/000.bin');}
        hotelHealthCheck('fragment '.$mode.' symlink is held without touching its target',
            $uploadCall($uploadApp,$chunkRequest(0,$pieces[0]),$pieces[0])['code']===409
            &&file_get_contents($uploadDir.'/sentinel-dir/sentinel')==='unchanged'&&!file_exists($uploadDir.'/sealed-inputs.bin'));
    }
    [$uploadApp,$uploadDir]=$uploadFixture('chunk-after-whole-partial',$chunkPayload);
    $uploadCall($uploadApp,$wholeRequest,'unfinished');$partial=$uploadDir.'/upload.part';$before=file_get_contents($partial);
    hotelHealthCheck('fragment mode never takes over an existing whole-file partial',
        $uploadCall($uploadApp,$chunkRequest(0,$pieces[0]),$pieces[0])['code']===409
        &&file_get_contents($partial)===$before&&!file_exists($uploadDir.'/chunks'));
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
