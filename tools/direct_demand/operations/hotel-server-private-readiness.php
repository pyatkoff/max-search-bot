<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
const CHUNK_READ_OPERATION='hotel-server-private-php82-module-readback-v1';
const CHUNK_READ_INSTALLED='0fb9d88cd714ae951baa68f013b7a46472af06c4';
const CHUNK_READ_ID='8e4c0372a0338bc98429d47bc0fe3327238f5be69d94c7d28dbf977647a85e35';
const CHUNK_READ_TICKET_SHA='5e91679b20b7906686fc6f02b02841fbec615af99a3177a56d3f36e904b8dc43';
const CHUNK_READ_PAYLOAD_SHA='a85746bce5b71d4d241777a30e6bd643efecbd512bc5a50a2b741336012e84eb';
const CHUNK_READ_RECIPIENT_SHA='71f2b177d6baa924fa466765027c37f51a00f23f803a6d900060bf42944d237d';
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');
$fixed=['operation'=>CHUNK_READ_OPERATION,'database_writes'=>0,'provider_calls'=>0,'runtime_enable_writes'=>0];
$out=$fixed+['state'=>'HOLD','reason'=>'target_unconfirmed'];$lock=null;$target=false;
$report=['schema'=>CHUNK_READ_OPERATION,'operation_id'=>CHUNK_READ_ID,'state'=>'HOLD','reason'=>'readback_unconfirmed',
    'operator_provider_calls'=>0,'database_writes'=>0,'runtime_enable_writes'=>0,'server_file_writes'=>0,
    'http_requests'=>0,'probe_php_script_executions'=>0,'module_inventory_returned'=>false,'resend_authorized'=>false];
function chunkFail(string $why):never {throw new DomainException($why);}
function chunkDir(string $path):void {
    clearstatcache(true,$path);
    if(is_link($path)||realpath($path)!==$path||!is_dir($path)||(fileperms($path)&0777)!==0700||fileowner($path)!==posix_geteuid())
        chunkFail('private_channel_unconfirmed');
}
/** Only fixed metadata and a streamed digest leave this function; file bytes never do. */
function chunkMeta(string $path,int $max):array {
    clearstatcache(true,$path);$s=lstat($path);if($s===false)return ['exists'=>false];
    if(is_link($path)||!is_file($path)||($s['mode']&0777)!==0600||$s['uid']!==posix_geteuid()||$s['size']<0||$s['size']>$max)
        chunkFail('private_channel_unconfirmed');
    $f=fopen($path,'rb');if(!$f)chunkFail('readback_unconfirmed');
    try{
        $opened=fstat($f);if($s['dev']!==$opened['dev']||$s['ino']!==$opened['ino'])chunkFail('private_file_changed');
        $ctx=hash_init('sha256');$read=hash_update_stream($ctx,$f);$sha=hash_final($ctx);$end=fstat($f);
    }finally{fclose($f);}
    clearstatcache(true,$path);$after=lstat($path);
    $stable=is_array($after)&&$s['dev']===$after['dev']&&$s['ino']===$after['ino']&&$s['size']===$after['size']
        &&$s['mtime']===$after['mtime']&&$s['ctime']===$after['ctime']&&$read===$s['size']
        &&$end['size']===$s['size']&&$end['mtime']===$s['mtime'];
    return ['exists'=>true,'bytes'=>$s['size'],'mode'=>decoct($s['mode']&0777),'uid'=>$s['uid'],
        'modified_at_utc'=>gmdate('c',$s['mtime']),'changed_at_utc'=>gmdate('c',$s['ctime']),'sha256'=>$sha,'stable'=>$stable];
}
/** Metadata only for the one potentially untracked root configuration file; never follow links. */
function chunkHtaccessMeta(string $path):array {
    clearstatcache(true,$path);$s=lstat($path);if($s===false)return ['exists'=>false];
    $meta=['exists'=>true,'bytes'=>$s['size'],'mode'=>decoct($s['mode']&0777),'uid'=>$s['uid'],
        'symlink'=>is_link($path),'regular_file'=>is_file($path),'hash_performed'=>false];
    if($meta['symlink']||!$meta['regular_file']||!is_readable($path)||$s['size']<0||$s['size']>131072)return $meta;
    $f=fopen($path,'rb');if(!$f)return $meta;
    try{
        $opened=fstat($f);if($opened['ino']!==$s['ino']||$opened['dev']!==$s['dev'])return $meta+['stable'=>false];
        $ctx=hash_init('sha256');$read=hash_update_stream($ctx,$f);$sha=hash_final($ctx);$end=fstat($f);
    }finally{fclose($f);}
    clearstatcache(true,$path);$after=lstat($path);
    $meta['hash_performed']=true;$meta['sha256']=$sha;
    $meta['stable']=is_array($after)&&$s['dev']===$after['dev']&&$s['ino']===$after['ino']&&$s['size']===$after['size']
        &&$s['mtime']===$after['mtime']&&$s['ctime']===$after['ctime']&&$read===$s['size']&&$end['size']===$s['size'];
    return $meta;
}
/** Fixed file identities observed in the preceding sealed own-PHP readback. */
function chunkPinnedPhpConfig(string $path,int $bytes,string $sha):array {
    $m=chunkHtaccessMeta($path);
    if(($m['exists']??false)!==true||($m['stable']??false)!==true||($m['bytes']??null)!==$bytes
        ||!hash_equals($sha,$m['sha256']??''))chunkFail('php_config_pin_mismatch');
    return ['bytes'=>$bytes,'sha256'=>$sha,'stable'=>true];
}
/** No PHP code, input script, HTTP request, inherited request environment, or output file. */
function chunkPhp82Modules(string $app):array {
    if(!function_exists('proc_open')||!is_executable('/usr/bin/timeout')||!is_executable('/opt/php82/bin/php-cgi'))
        chunkFail('php82_probe_unavailable');
    $argv=['/usr/bin/timeout','--signal=TERM','--kill-after=1s','10s','/opt/php82/bin/php-cgi','-m'];
    $env=['PHPRC'=>'/var/www/php-bin-isp-php82/anytoour','PATH'=>'/usr/bin:/bin','LANG'=>'C','LC_ALL'=>'C'];
    $pipes=[];$process=proc_open($argv,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$app,$env,['bypass_shell'=>true]);
    if(!is_resource($process))chunkFail('php82_probe_unavailable');
    $out='';$err='';$exit=-1;
    try{
        fclose($pipes[0]);unset($pipes[0]);
        stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        while(true){
            $read=[];foreach([1,2] as $fd)if(!feof($pipes[$fd]))$read[]=$pipes[$fd];
            if($read===[])break;
            $write=null;$except=null;
            $ready=stream_select($read,$write,$except,1);
            if($ready===false)chunkFail('php82_probe_read_unconfirmed');
            foreach($read as $stream){
                $part=fread($stream,8192);if($part===false)chunkFail('php82_probe_read_unconfirmed');
                if($stream===$pipes[1])$out.=$part;else $err.=$part;
                if(strlen($out)+strlen($err)>65536)chunkFail('php82_probe_output_bounds');
            }
        }
    }finally{
        foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
        $exit=proc_close($process);
    }
    $normalized=str_replace("\r\n","\n",$out);$lines=explode("\n",$normalized);
    $php=array_search('[PHP Modules]',$lines,true);$zend=array_search('[Zend Modules]',$lines,true);
    $framed=is_int($php)&&is_int($zend)&&$php<$zend;
    $modules=$framed?array_map('trim',array_slice($lines,$php+1,$zend-$php-1)):[];
    $result=['posix'=>$framed&&in_array('posix',$modules,true),'openssl'=>$framed&&in_array('openssl',$modules,true),
        'hash'=>$framed&&in_array('hash',$modules,true),'json'=>$framed&&in_array('json',$modules,true),
        'exit_code'=>$exit,'output_sha256'=>hash('sha256',"stdout\0".$out."\0stderr\0".$err)];
    unset($out,$err,$normalized,$lines,$modules);
    return [$result,$framed&&$exit===0];
}

function chunkSeal(array $payload):array {
    $recipient=<<<'PUBLIC_RECIPIENT'
-----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAuN8W3YQpfXZak/D2Whmc
58DfcWVrLJRumXPFLQjaHhcueBq9zWnFf6jp8/x9IIZ/CZDz/vxvdh7PmSAW/Faa
ciVa2HDRJmxO3ZteBVcnVWatO9j1GrbvAZc/OCYxflqBwueetSikhlqFdeqKDok4
gKE81+uPhl28Um8leV4+ouLmM9Pic4gDSqL87uMyKSe1O5lDmUbvJm3Qyfs+ZfOn
PBuEsUuvMUkVZ7c6FrhY74pEzyT6Mx6XdnnVdyZ+ksGcmCAroAQoPtia7ebv17Xi
pJjgwEokntbAAlvqwrbH8X7X+MpeI84EXAh6kJOPxCj8l/Oxb1xdZR/wC0DorQkj
R7yipD6y0nyjWWHdwucd4Lty0H332crtnxktGYIfymRXprZb/creHaGOw39gIvGV
T8YNFRu1JmL3tbjxnO4ekvJfvOzwErVxLahHT0RJCcZ43a7zga+9oMaFLII9RqGd
LPAX0T14+63yqvazaMLpk7UOTjSedaIGCxEfuviVR4pzAgMBAAE=
-----END PUBLIC KEY-----
PUBLIC_RECIPIENT;
    $recipient.="\n";if(!hash_equals(CHUNK_READ_RECIPIENT_SHA,hash('sha256',$recipient)))chunkFail('sealing_unavailable');
    $plain=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(strlen($plain)>65536)chunkFail('readback_bounds_unconfirmed');
    $key=random_bytes(32);$nonce=random_bytes(12);$tag='';$wrapped='';$aad=CHUNK_READ_OPERATION.'|'.CHUNK_READ_INSTALLED.'|'.CHUNK_READ_ID;
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
    if(!is_string($cipher)||strlen($tag)!==16||!openssl_public_encrypt($key,$wrapped,$recipient,OPENSSL_PKCS1_OAEP_PADDING)||strlen($wrapped)!==384)
        chunkFail('sealing_unavailable');
    unset($key,$plain);
    return ['operation'=>CHUNK_READ_OPERATION,'database_writes'=>0,'provider_calls'=>0,'runtime_enable_writes'=>0,
        'state'=>'sealed','algorithm'=>'RSA-OAEP-SHA1+A256GCM','recipient_sha256'=>CHUNK_READ_RECIPIENT_SHA,'aad'=>$aad,
        'key'=>base64_encode($wrapped),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($cipher),
        'ciphertext_sha256'=>hash('sha256',$cipher)];
}
try {
    $app='/var/www/anytoour/data/www/app.anytoour.ru';
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app||!function_exists('posix_geteuid')||posix_geteuid()!==1012)
        chunkFail('target_unconfirmed');
    $target=true;$report['read_at_utc']=gmdate('c');
    $lines=[];$code=1;exec('git -C '.escapeshellarg($app).' rev-parse HEAD 2>/dev/null',$lines,$code);
    $installed=trim(implode('',$lines));$report['installed_sha']=$installed;
    if($code!==0||$installed!==CHUNK_READ_INSTALLED)chunkFail('installed_release_unconfirmed');
    if(!hash_equals('f0180d547da2566d6cbc1fe0ef34530837e3c0c87e0a5cf9c407094ef07f4ee1',hash_file('sha256',$app.'/services/HotelServerPrivateUpload.php')))
        chunkFail('installed_helper_unconfirmed');
    $report['application_root_htaccess']=chunkHtaccessMeta($app.'/.htaccess');
    if(($report['application_root_htaccess']['stable']??false)!==true
        ||!hash_equals('a4c8912801aa74844279e2c2c09230e3f555da54e16a9a6a31ec3005ec9ed8ff',$report['application_root_htaccess']['sha256']??''))
        chunkFail('installed_root_htaccess_unconfirmed');
    $root=dirname($app).'/max-search-bot-hotel-server';$dir=$root.'/ingress-v1';chunkDir($root);chunkDir($dir);
    $lockMeta=chunkMeta($dir.'/upload.lock',1);$report['upload_lock_metadata']=$lockMeta;
    if(!$lockMeta['exists']||$lockMeta['bytes']!==0||$lockMeta['stable']!==true)chunkFail('existing_upload_lock_unconfirmed');
    $lock=fopen($dir.'/upload.lock','rb');if(!$lock)chunkFail('readback_unconfirmed');
    if(!flock($lock,LOCK_SH|LOCK_NB))chunkFail('active_upload_requires_wait');
    $report['upload_lock_free']=true;
    $files=[];foreach(['ticket.json'=>4096,'upload.part'=>8388608,'sealed-inputs.bin'=>8388608] as $name=>$limit)
        $files[$name]=chunkMeta($dir.'/'.$name,$limit);
    $report['files']=$files;
    if(($files['ticket.json']['exists']??false)!==true||($files['ticket.json']['stable']??false)!==true
        ||!hash_equals(CHUNK_READ_TICKET_SHA,$files['ticket.json']['sha256']??''))chunkFail('ticket_identity_unconfirmed');
    $report['ticket_sha256_matches_renewal']=true;
    $report['chunks_directory_present']=file_exists($dir.'/chunks')||is_link($dir.'/chunks');
    $report['runtime_file_present']=file_exists($root.'/runtime.json')||is_link($root.'/runtime.json');
    $report['runtime_staging_present']=file_exists($root.'/runtime.activation-20261102.part')||is_link($root.'/runtime.activation-20261102.part');
    if($files['upload.part']['exists']||$files['sealed-inputs.bin']['exists']||$report['chunks_directory_present']
        ||$report['runtime_file_present']||$report['runtime_staging_present'])chunkFail('new_upload_or_runtime_artifacts_hold');
    $wrapper='/var/www/php-bin-isp-php82/anytoour/app.anytoour.ru/php';
    $ini='/var/www/php-bin-isp-php82/anytoour/php.ini';
    $report['known_wrapper']=chunkPinnedPhpConfig($wrapper,191,'25a01b5a4c077fff9bf95d39ef4ff30a05ff50808a3a0883ad9c30f58ac2d0c1');
    $report['known_php_ini']=chunkPinnedPhpConfig($ini,71625,'a914159930abbdeabebceab5cbd94b7ae06cc4c6207332192ea69ec47645f356');
    [$moduleResult,$probeOk]=chunkPhp82Modules($app);$report['php82_modules']=$moduleResult;
    // A changed configuration or lock turns this observation into HOLD, never into a resend permission.
    chunkPinnedPhpConfig($wrapper,191,'25a01b5a4c077fff9bf95d39ef4ff30a05ff50808a3a0883ad9c30f58ac2d0c1');
    chunkPinnedPhpConfig($ini,71625,'a914159930abbdeabebceab5cbd94b7ae06cc4c6207332192ea69ec47645f356');
    $after=chunkMeta($dir.'/upload.lock',1);
    if($after!==$lockMeta)chunkFail('upload_lock_changed');
    if(!$probeOk)chunkFail('php82_module_probe_unconfirmed');
    $report['state']='readback';unset($report['reason']);
}catch(Throwable $e){
    $why=$e->getMessage();$report['reason']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$why)?$why:'readback_unconfirmed';
}finally{
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}while(ob_get_level()>$level)ob_end_clean();
}
try{if($target)$out=chunkSeal($report);}
catch(Throwable $ignored){$out=$fixed+['state'=>'HOLD','reason'=>'sealing_unavailable'];}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
exit($report['state']==='readback'&&$out['state']==='sealed'?0:1);
