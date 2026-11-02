<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');umask(0077);
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');
$fixed=['operation'=>'hotel-server-private-upload-ticket-v1','database_writes'=>0,'provider_calls'=>0,'runtime_enable_writes'=>0];
$out=$fixed+['state'=>'HOLD','reason'=>'ticket_unconfirmed'];$lock=null;
function privateTicketFile(string $path): bool {
    clearstatcache(true,$path);return !is_link($path)&&is_file($path)&&(fileperms($path)&0777)===0600&&fileowner($path)===posix_geteuid();
}
function writeTicketFile(string $path,string $bytes): void {
    $f=fopen($path,'x');if(!$f)throw new RuntimeException('ticket_unconfirmed');
    try{if(!chmod($path,0600)||fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||(function_exists('fsync')&&!fsync($f)))throw new RuntimeException('ticket_unconfirmed');}
    finally{fclose($f);}
    if(!privateTicketFile($path)||!hash_equals(hash('sha256',$bytes),hash_file('sha256',$path)))throw new RuntimeException('ticket_unconfirmed');
}
try{
    $app='/var/www/anytoour/data/www/app.anytoour.ru';
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app||!function_exists('posix_geteuid')||posix_geteuid()!==1012)throw new RuntimeException('target_unconfirmed');
    if(!function_exists('openssl_encrypt'))throw new RuntimeException('sealing_unavailable');
    $root=dirname($app).'/max-search-bot-hotel-server';$dir=$root.'/ingress-v1';
    foreach([$root,$dir] as $path)if(is_link($path)||realpath($path)!==$path||!is_dir($path)||(fileperms($path)&0777)!==0700||fileowner($path)!==posix_geteuid())throw new RuntimeException('private_channel_unconfirmed');
    if(!privateTicketFile($dir.'/recipient.public.pem')||!hash_equals('137da1e625bcd005e4db69335781f0396fb44995b9668e1b35111f2307810388',hash_file('sha256',$dir.'/recipient.public.pem')))throw new RuntimeException('private_channel_unconfirmed');
    $lockPath=$dir.'/channel.lock';if(is_link($lockPath)||(file_exists($lockPath)&&!privateTicketFile($lockPath)))throw new RuntimeException('private_channel_unconfirmed');
    $lock=fopen($lockPath,'c');if(!$lock||!chmod($lockPath,0600)||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('private_channel_unconfirmed');
    $ticketPath=$dir.'/ticket.json';$receiptPath=$dir.'/ticket-receipt.sealed.json';
    $aad='hotel-server-private-upload-ticket-v1|62814763dd6274b2e5c9f03ef6e2d689a7ed96af|8e4c0372a0338bc98429d47bc0fe3327238f5be69d94c7d28dbf977647a85e35';
    if(file_exists($ticketPath)||is_link($ticketPath)||file_exists($receiptPath)||is_link($receiptPath)){
        if(!privateTicketFile($ticketPath)||!privateTicketFile($receiptPath)||filesize($ticketPath)>4096||filesize($receiptPath)>32768)throw new RuntimeException('existing_ticket_unconfirmed');
        $ticket=json_decode((string)file_get_contents($ticketPath),true,16,JSON_THROW_ON_ERROR);
        $saved=json_decode((string)file_get_contents($receiptPath),true,16,JSON_THROW_ON_ERROR);
        if(($ticket['operation_id']??null)!=='8e4c0372a0338bc98429d47bc0fe3327238f5be69d94c7d28dbf977647a85e35'||($ticket['payload_sha256']??null)!=='a85746bce5b71d4d241777a30e6bd643efecbd512bc5a50a2b741336012e84eb'
            ||($ticket['payload_bytes']??null)!==5696586||($saved['aad']??null)!==$aad
            ||($saved['ticket_sha256']??null)!==hash_file('sha256',$ticketPath)||($saved['state']??null)!=='sealed')throw new RuntimeException('existing_ticket_unconfirmed');
        $out=$saved; // Exact saved receipt only; no token regeneration, lifetime extension or overwrite.
    }else{
        if(file_exists($dir.'/sealed-inputs.bin')||is_link($dir.'/sealed-inputs.bin')||file_exists($dir.'/upload.part')||is_link($dir.'/upload.part'))throw new RuntimeException('existing_ticket_unconfirmed');
        $token=bin2hex(random_bytes(32));$now=time();
        $ticket=['schema'=>'hotel-server-private-upload-v1','operation_id'=>'8e4c0372a0338bc98429d47bc0fe3327238f5be69d94c7d28dbf977647a85e35',
            'payload_bytes'=>5696586,'payload_sha256'=>'a85746bce5b71d4d241777a30e6bd643efecbd512bc5a50a2b741336012e84eb',
            'token_sha256'=>hash('sha256',$token),'created_at'=>$now,'expires_at'=>$now+3600];
        $ticketBytes=json_encode($ticket,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        $ticketSha=hash('sha256',$ticketBytes);
        $payload=['schema'=>'hotel-server-private-upload-ticket-v1','installed_sha'=>'62814763dd6274b2e5c9f03ef6e2d689a7ed96af',
            'endpoint'=>'https://app.anytoour.ru/hotel-server-input.php','ticket'=>$ticket,'ticket_sha256'=>$ticketSha,'token'=>$token,
            'database_writes'=>0,'provider_calls'=>0,'runtime_enable_writes'=>0];
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
        $key=random_bytes(32);$nonce=random_bytes(12);$tag='';$wrapped='';
        $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
        if(!is_string($cipher)||!openssl_public_encrypt($key,$wrapped,$recipient,OPENSSL_PKCS1_OAEP_PADDING)||strlen($tag)!==16)throw new RuntimeException('sealing_unavailable');
        $out=$fixed+['state'=>'sealed','algorithm'=>'RSA-OAEP-SHA1+A256GCM','recipient_sha256'=>'56f838b25c67967d6261577bc1f9bd0e689f224c6f5b042de4a4292f9067678d','aad'=>$aad,
            'ticket_sha256'=>$ticketSha,'key'=>base64_encode($wrapped),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),
            'ciphertext'=>base64_encode($cipher),'ciphertext_sha256'=>hash('sha256',$cipher)];
        // Preserve sealed capability before enabling it. Partial creation holds for explicit reconciliation.
        writeTicketFile($receiptPath,json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
        writeTicketFile($ticketPath,$ticketBytes);
        unset($token,$key,$plain,$payload);
    }
}catch(Throwable $e){
    $allowed=['target_unconfirmed','sealing_unavailable','private_channel_unconfirmed','existing_ticket_unconfirmed','ticket_unconfirmed'];
    $out=$fixed+['state'=>'HOLD','reason'=>in_array($e->getMessage(),$allowed,true)?$e->getMessage():'ticket_unconfirmed'];
}finally{
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
    while(ob_get_level()>$level)ob_end_clean();
}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
exit($out['state']==='sealed'?0:1);
