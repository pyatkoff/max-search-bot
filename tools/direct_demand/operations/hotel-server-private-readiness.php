<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');
$fixed=['operation'=>'hotel-server-private-readiness-v1','database_writes'=>0,'provider_calls'=>0,'runtime_enable_writes'=>0];
$out=$fixed+['state'=>'HOLD','reason'=>'readiness_unconfirmed'];$lock=null;
try{
    $app='/var/www/anytoour/data/www/app.anytoour.ru';
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app
        ||!function_exists('posix_geteuid')||posix_geteuid()!==1012)throw new RuntimeException('target_unconfirmed');
    if(!function_exists('openssl_pkey_new')||!function_exists('openssl_encrypt'))throw new RuntimeException('sealing_unavailable');
    require $app.'/config.php';require_once $app.'/ai/ManagerDraftClient.php';
    $body=ManagerDraftClient::requestBody('',[]);
    $profile=[];foreach(['model','reasoning','service_tier','max_output_tokens','store'] as $k){
        if(!array_key_exists($k,$body))throw new RuntimeException('profile_unconfirmed');$profile[$k]=$body[$k];
    }
    if(!is_string($profile['model'])||!is_array($profile['reasoning'])||!is_string($profile['service_tier'])
        ||$profile['max_output_tokens']!==2200||$profile['store']!==false)throw new RuntimeException('profile_unconfirmed');
    $root=dirname($app).'/max-search-bot-hotel-server';
    if(!file_exists($root)&&!mkdir($root,0700))throw new RuntimeException('private_channel_unconfirmed');
    if(is_link($root)||realpath($root)!==$root||(fileperms($root)&0777)!==0700||fileowner($root)!==posix_geteuid())throw new RuntimeException('private_channel_unconfirmed');
    $dir=$root.'/ingress-v1';
    if(!file_exists($dir)&&!mkdir($dir,0700))throw new RuntimeException('private_channel_unconfirmed');
    if(is_link($dir)||realpath($dir)!==$dir||(fileperms($dir)&0777)!==0700||fileowner($dir)!==posix_geteuid())throw new RuntimeException('private_channel_unconfirmed');
    $lockPath=$dir.'/channel.lock';if(is_link($lockPath))throw new RuntimeException('private_channel_unconfirmed');
    $lock=fopen($lockPath,'c');if($lock===false||!chmod($lockPath,0600)||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('private_channel_unconfirmed');
    $secretPath=$dir.'/recipient.private.pem';$publicPath=$dir.'/recipient.public.pem';$created=false;
    if(!file_exists($secretPath)&&!file_exists($publicPath)&&!is_link($secretPath)&&!is_link($publicPath)){
        $key=openssl_pkey_new(['private_key_bits'=>3072,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        if($key===false||!openssl_pkey_export($key,$private))throw new RuntimeException('private_channel_unconfirmed');
        $details=openssl_pkey_get_details($key);$public=$details['key']??null;
        if(!is_string($public)||($details['bits']??null)!==3072)throw new RuntimeException('private_channel_unconfirmed');
        $handle=fopen($secretPath,'x');if($handle===false)throw new RuntimeException('private_channel_unconfirmed');
        try{if(!chmod($secretPath,0600)||fwrite($handle,$private)!==strlen($private)||!fflush($handle))throw new RuntimeException('private_channel_unconfirmed');}finally{fclose($handle);}
        $handle=fopen($publicPath,'x');if($handle===false)throw new RuntimeException('private_channel_unconfirmed');
        try{if(!chmod($publicPath,0600)||fwrite($handle,$public)!==strlen($public)||!fflush($handle))throw new RuntimeException('private_channel_unconfirmed');}finally{fclose($handle);}
        unset($private,$key);$created=true;
    }
    foreach([$secretPath,$publicPath] as $path)if(is_link($path)||!is_file($path)||(fileperms($path)&0777)!==0600||fileowner($path)!==posix_geteuid())throw new RuntimeException('private_channel_unconfirmed');
    $public=(string)file_get_contents($publicPath);$details=openssl_pkey_get_details(openssl_pkey_get_public($public));
    if(($details['bits']??null)!==3072||($details['type']??null)!==OPENSSL_KEYTYPE_RSA)throw new RuntimeException('private_channel_unconfirmed');
    $payload=['schema'=>'hotel-server-private-readiness-v1','installed_sha'=>'9c5be6ba829e5083fd27f377887121d58492bf54',
        'read_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'profile'=>$profile,
        'api_key_configured'=>defined('OPENAI_API_KEY')&&is_string(OPENAI_API_KEY)&&trim(OPENAI_API_KEY)!=='',
        'curl_available'=>function_exists('curl_init'),'private_ingress_created'=>$created,
        'ingress_public_key'=>$public,'ingress_public_sha256'=>hash('sha256',$public),
        'runtime_file_present'=>file_exists($root.'/runtime.json'),'database_writes'=>0,'provider_calls'=>0];
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
    $recipient.="\n";
    if(!hash_equals('56f838b25c67967d6261577bc1f9bd0e689f224c6f5b042de4a4292f9067678d',hash('sha256',$recipient)))throw new RuntimeException('sealing_unavailable');
    $key=random_bytes(32);$nonce=random_bytes(12);$tag='';$aad='hotel-server-private-readiness-v1|9c5be6ba829e5083fd27f377887121d58492bf54';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);$wrapped='';
    if(!is_string($cipher)||!openssl_public_encrypt($key,$wrapped,$recipient,OPENSSL_PKCS1_OAEP_PADDING)||strlen($tag)!==16)throw new RuntimeException('sealing_unavailable');
    $out=$fixed+['state'=>'sealed','algorithm'=>'RSA-OAEP-SHA1+A256GCM','recipient_sha256'=>'56f838b25c67967d6261577bc1f9bd0e689f224c6f5b042de4a4292f9067678d','aad'=>$aad,
        'key'=>base64_encode($wrapped),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),
        'ciphertext'=>base64_encode($cipher),'ciphertext_sha256'=>hash('sha256',$cipher)];
    unset($plain,$payload,$key);
}catch(Throwable $e){
    $allowed=['target_unconfirmed','sealing_unavailable','profile_unconfirmed','private_channel_unconfirmed'];
    $out=$fixed+['state'=>'HOLD','reason'=>in_array($e->getMessage(),$allowed,true)?$e->getMessage():'readiness_unconfirmed'];
}finally{
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
    while(ob_get_level()>$level)ob_end_clean();
}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
exit($out['state']==='sealed'?0:1);
