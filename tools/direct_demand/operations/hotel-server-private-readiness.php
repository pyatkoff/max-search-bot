<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');
$fixed=['operation'=>'hotel-server-private-upload-readback-v1','database_writes'=>0,'provider_calls'=>0,'runtime_enable_writes'=>0];
$out=$fixed+['state'=>'HOLD','reason'=>'readback_unconfirmed'];$lock=null;
try{
    $app='/var/www/anytoour/data/www/app.anytoour.ru';
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app||!function_exists('posix_geteuid')||posix_geteuid()!==1012)throw new RuntimeException('target_unconfirmed');
    $root=dirname($app).'/max-search-bot-hotel-server';$dir=$root.'/ingress-v1';
    foreach([$root,$dir] as $path)if(is_link($path)||realpath($path)!==$path||!is_dir($path)||(fileperms($path)&0777)!==0700||fileowner($path)!==posix_geteuid())throw new RuntimeException('private_channel_unconfirmed');
    $files=[];$free=null;
    foreach(['ticket.json','upload.lock','upload.part','sealed-inputs.bin'] as $name){
        $path=$dir.'/'.$name;clearstatcache(true,$path);$exists=file_exists($path)||is_link($path);$row=['exists'=>$exists];
        if($exists){
            if(is_link($path)||!is_file($path)||fileowner($path)!==posix_geteuid())throw new RuntimeException('private_channel_unconfirmed');
            $before=stat($path);$row+=['bytes'=>$before['size'],'mode'=>decoct($before['mode']&0777),'uid'=>$before['uid'],'modified_at'=>$before['mtime']];
            if($before['size']<=8388608)$row['sha256']=hash_file('sha256',$path);
            clearstatcache(true,$path);$after=stat($path);$row['stable']=$before['size']===$after['size']&&$before['mtime']===$after['mtime']&&$before['ino']===$after['ino'];
        }
        $files[$name]=$row;
    }
    if($files['upload.lock']['exists']){
        $lock=fopen($dir.'/upload.lock','rb');if(!$lock)throw new RuntimeException('readback_unconfirmed');$free=flock($lock,LOCK_SH|LOCK_NB);
    }
    $ticket=[];
    if($files['ticket.json']['exists']&&$files['ticket.json']['bytes']<=4096){
        $raw=json_decode((string)file_get_contents($dir.'/ticket.json'),true,16,JSON_THROW_ON_ERROR);
        foreach(['schema','operation_id','payload_bytes','payload_sha256','created_at','expires_at'] as $k)$ticket[$k]=$raw[$k]??null;
        if($ticket['operation_id']!=='8e4c0372a0338bc98429d47bc0fe3327238f5be69d94c7d28dbf977647a85e35'||$ticket['payload_bytes']!==5696586||$ticket['payload_sha256']!=='a85746bce5b71d4d241777a30e6bd643efecbd512bc5a50a2b741336012e84eb')throw new RuntimeException('private_channel_unconfirmed');
    }
    $logs=[];$logRoot=dirname(dirname($app)).'/logs';
    foreach(['access.log','error.log'] as $suffix){
        $path=$logRoot.'/'.basename($app).'.'.$suffix;
        if(is_link($path)||!is_file($path)||!is_readable($path)){$logs[$suffix]=['available'=>false];continue;}
        $f=fopen($path,'rb');if(!$f)continue;$size=filesize($path);fseek($f,max(0,$size-131072));$tail=stream_get_contents($f,131072);fclose($f);
        $matches=[];foreach(explode("\n",(string)$tail) as $line)if(str_contains($line,'/hotel-server-input.php')&&strlen($line)<=2048)$matches[]=$line;
        $logs[$suffix]=['available'=>true,'matching_lines'=>array_slice($matches,-12)];
    }
    $payload=['schema'=>$fixed['operation'],'read_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'files'=>$files,'upload_lock_free'=>$free,
        'ticket'=>$ticket,'logs'=>$logs,'operator_provider_calls'=>0,'database_writes'=>0,'runtime_enable_writes'=>0,
        'runtime_file_present'=>file_exists($root.'/runtime.json'),'php_version'=>PHP_VERSION,'cli_memory_limit'=>ini_get('memory_limit')];
    $plain=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $recipient=<<<'RECIPIENT'
-----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEA2KDW+zWMm3sjer6sf1be
YDUKuXhXe91QJ1f47Q8rLLGlWMJazHngTlMFyTh9G8t8ox23rkk1r+12/aywWwvI
mRVey9Ul1F9ZKtmn6Nw/lCxG6oQj958BdftWSOM/Z7SqxdoCzn8BfwizxiCrVooH
S2K+kaQYibMUKgrRYLlnoOVSXQpQH+7/mjoxTqLYySIrzG8DQhC2mPg3UzYbwtCu
Hr+FBgPFJt95oEOvsl79jd92GZm7Plh5v7UfgkAfaZDyjK8fwdSGfG/q0lk04t1m
HEE6BV10LUj5LTlhLF6TmsocAsNd1m+6Zk1eyUSPauEX89srxgZSTOny3O5GLF7u
VwbvVxLZTcT6kNJVO6WRbQ+pQCyA7U3wc712W80kw9I+nmzRORlbCNzWKeqJYkE+
ehNet25tFd1RqPOu1CsDgOYkOI2jS0PEEIzwkSg485Icq8+ypXkRtidC7ntPpgYy
r1gw5YdmtFCiSkNWncve9rq6EGniiaVTO1S7HTuQQqBBAgMBAAE=
-----END PUBLIC KEY-----
RECIPIENT;
    $recipient.="\n";if(!hash_equals('56f838b25c67967d6261577bc1f9bd0e689f224c6f5b042de4a4292f9067678d',hash('sha256',$recipient)))throw new RuntimeException('sealing_unavailable');
    $key=random_bytes(32);$nonce=random_bytes(12);$tag='';$wrapped='';$aad='hotel-server-private-upload-readback-v1|62814763dd6274b2e5c9f03ef6e2d689a7ed96af|8e4c0372a0338bc98429d47bc0fe3327238f5be69d94c7d28dbf977647a85e35';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
    if(!is_string($cipher)||!openssl_public_encrypt($key,$wrapped,$recipient,OPENSSL_PKCS1_OAEP_PADDING)||strlen($tag)!==16)throw new RuntimeException('sealing_unavailable');
    $out=$fixed+['state'=>'sealed','algorithm'=>'RSA-OAEP-SHA1+A256GCM','recipient_sha256'=>'56f838b25c67967d6261577bc1f9bd0e689f224c6f5b042de4a4292f9067678d','aad'=>$aad,
        'key'=>base64_encode($wrapped),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($cipher),'ciphertext_sha256'=>hash('sha256',$cipher)];
    unset($key,$plain,$raw);
}catch(Throwable $e){
    $allowed=['target_unconfirmed','private_channel_unconfirmed','readback_unconfirmed','sealing_unavailable'];
    $out=$fixed+['state'=>'HOLD','reason'=>in_array($e->getMessage(),$allowed,true)?$e->getMessage():'readback_unconfirmed'];
}finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}while(ob_get_level()>$level)ob_end_clean();}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";exit($out['state']==='sealed'?0:1);
