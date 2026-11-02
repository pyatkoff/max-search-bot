<?php
declare(strict_types=1);
// DRAFT: fill exact installed SHA. Identify only the original expired ticket inside the locked operation.
// This is one forward renewal only. It never uploads, decrypts, imports, enables or calls a provider.
ini_set('display_errors','0');ini_set('log_errors','0');umask(0077);
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');
$fixed=['operation'=>'hotel-server-private-upload-renewal-v1','database_writes'=>0,'provider_calls'=>0,'runtime_enable_writes'=>0];
$out=$fixed+['state'=>'HOLD','reason'=>'renewal_unconfirmed'];$locks=[];
const HOTEL_EXPECTED_INSTALLED_SHA='8eb3458d46d5ed7bcf2cd87f98b7372563801833';
const HOTEL_OPERATION='8e4c0372a0338bc98429d47bc0fe3327238f5be69d94c7d28dbf977647a85e35';
const HOTEL_PAYLOAD_BYTES=5696586;
const HOTEL_PAYLOAD_SHA='a85746bce5b71d4d241777a30e6bd643efecbd512bc5a50a2b741336012e84eb';
const HOTEL_RECIPIENT_SHA='71f2b177d6baa924fa466765027c37f51a00f23f803a6d900060bf42944d237d';
const HOTEL_RECIPIENT_BASE64='LS0tLS1CRUdJTiBQVUJMSUMgS0VZLS0tLS0KTUlJQm9qQU5CZ2txaGtpRzl3MEJBUUVGQUFPQ0FZOEFNSUlCaWdLQ0FZRUF1TjhXM1lRcGZYWmFrL0QyV2htYwo1OERmY1dWckxKUnVtWFBGTFFqYUhoY3VlQnE5elduRmY2anA4L3g5SUlaL0NaRHovdnh2ZGg3UG1TQVcvRmFhCmNpVmEySERSSm14TzNadGVCVmNuVldhdE85ajFHcmJ2QVpjL09DWXhmbHFCd3VlZXRTaWtobHFGZGVxS0RvazQKZ0tFODErdVBobDI4VW04bGVWNCtvdUxtTTlQaWM0Z0RTcUw4N3VNeUtTZTFPNWxEbVVidkptM1F5ZnMrWmZPbgpQQnVFc1V1dk1Va1ZaN2M2RnJoWTc0cEV6eVQ2TXg2WGRublZkeVora3NHY21DQXJvQVFvUHRpYTdlYnYxN1hpCnBKamd3RW9rbnRiQUFsdnF3cmJIOFg3WCtNcGVJODRFWEFoNmtKT1B4Q2o4bC9PeGIxeGRaUi93QzBEb3JRa2oKUjd5aXBENnkwbnlqV1dIZHd1Y2Q0THR5MEgzMzJjcnRueGt0R1lJZnltUlhwclpiL2NyZUhhR093MzlnSXZHVgpUOFlORlJ1MUptTDN0Ymp4bk80ZWt2SmZ2T3p3RXJWeExhaEhUMFJKQ2NaNDNhN3pnYSs5b01hRkxJSTlScUdkCkxQQVgwVDE0KzYzeXF2YXphTUxwazdVT1RqU2VkYUlHQ3hFZnV2aVZSNHB6QWdNQkFBRT0KLS0tLS1FTkQgUFVCTElDIEtFWS0tLS0tCg==';
function renewPrivateFile(string $path,int $max): string {
    clearstatcache(true,$path);$before=lstat($path);
    if(!is_array($before)||is_link($path)||!is_file($path)||($before['mode']&0777)!==0600||$before['uid']!==posix_geteuid()
        ||$before['size']<1||$before['size']>$max)throw new RuntimeException('private_channel_unconfirmed');
    $raw=file_get_contents($path);clearstatcache(true,$path);$after=lstat($path);
    if(!is_string($raw)||strlen($raw)!==$before['size']||!is_array($after)||$before['ino']!==$after['ino']
        ||$before['dev']!==$after['dev']||$before['size']!==$after['size']||$before['mtime']!==$after['mtime'])throw new RuntimeException('private_channel_unconfirmed');
    return $raw;
}
function renewNewFile(string $path,string $bytes): void {
    if(file_exists($path)||is_link($path))throw new RuntimeException('renewal_unconfirmed');
    $f=fopen($path,'x');if(!$f)throw new RuntimeException('renewal_unconfirmed');
    try{if(!chmod($path,0600)||fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||(function_exists('fsync')&&!fsync($f)))throw new RuntimeException('renewal_unconfirmed');}
    finally{fclose($f);}
    if(!hash_equals(hash('sha256',$bytes),hash('sha256',renewPrivateFile($path,max(1,strlen($bytes))))))throw new RuntimeException('renewal_unconfirmed');
}
try{
    $app='/var/www/anytoour/data/www/app.anytoour.ru';
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app||!function_exists('posix_geteuid')||posix_geteuid()!==1012)throw new RuntimeException('target_unconfirmed');
    if(!preg_match('/\A[a-f0-9]{40}\z/D',HOTEL_EXPECTED_INSTALLED_SHA))throw new RuntimeException('exact_pins_required');
    // The workflow additionally checks the exact installed clean checkout before PHP execution.
    if(!hash_equals('f0180d547da2566d6cbc1fe0ef34530837e3c0c87e0a5cf9c407094ef07f4ee1',hash_file('sha256',$app.'/services/HotelServerPrivateUpload.php')))throw new RuntimeException('target_unconfirmed');
    $root=dirname($app).'/max-search-bot-hotel-server';$dir=$root.'/ingress-v1';$journal=$dir.'/renewal-20261102-chunks-v1';
    foreach([$root,$dir] as $p)if(is_link($p)||realpath($p)!==$p||!is_dir($p)||(fileperms($p)&0777)!==0700||fileowner($p)!==posix_geteuid())throw new RuntimeException('private_channel_unconfirmed');
    if(file_exists($root.'/runtime.json')||is_link($root.'/runtime.json'))throw new RuntimeException('activation_already_present');
    if(!hash_equals('137da1e625bcd005e4db69335781f0396fb44995b9668e1b35111f2307810388',hash('sha256',renewPrivateFile($dir.'/recipient.public.pem',8192))))throw new RuntimeException('private_channel_unconfirmed');
    $lockPath=$dir.'/channel.lock';clearstatcache(true,$lockPath);
    if(is_link($lockPath)||!is_file($lockPath)||(fileperms($lockPath)&0777)!==0600||fileowner($lockPath)!==posix_geteuid())throw new RuntimeException('private_channel_unconfirmed');
    $channel=fopen($lockPath,'rb');if(!$channel)throw new RuntimeException('renewal_unconfirmed');$locks[]=$channel;
    if(!flock($channel,LOCK_EX|LOCK_NB))throw new RuntimeException('active_private_operation');
    // Never repeat this renewal, including after a partial/unknown outcome. Read its existing receipt separately.
    if(file_exists($journal)||is_link($journal))throw new RuntimeException('existing_renewal_requires_readback');
    foreach(['upload.lock','upload.part','sealed-inputs.bin','chunks','ticket.next','ticket-receipt.next'] as $name){
        clearstatcache(true,$dir.'/'.$name);if(file_exists($dir.'/'.$name)||is_link($dir.'/'.$name))throw new RuntimeException('upload_artifacts_present');
    }
    require_once $app.'/config.php';require_once $app.'/services/ProjectAccessService.php';
    ProjectAccessService::initializeReadOnly();$pdo=ConversationDb::connection();
    if($pdo->inTransaction()||$pdo->query('SELECT 1 FROM hotel_server_campaigns LIMIT 1')->fetchColumn()!==false)throw new RuntimeException('campaign_already_present');
    $oldTicket=renewPrivateFile($dir.'/ticket.json',4096);$oldReceipt=renewPrivateFile($dir.'/ticket-receipt.sealed.json',32768);
    $oldTicketSha=hash('sha256',$oldTicket);$oldReceiptSha=hash('sha256',$oldReceipt);
    $ticket=json_decode($oldTicket,true,16,JSON_THROW_ON_ERROR);$receipt=json_decode($oldReceipt,true,16,JSON_THROW_ON_ERROR);$now=time();
    $keys=array_keys($ticket);sort($keys);
    if($keys!==['created_at','expires_at','operation_id','payload_bytes','payload_sha256','schema','token_sha256']
        ||$ticket['schema']!=='hotel-server-private-upload-v1'||$ticket['operation_id']!==HOTEL_OPERATION
        ||$ticket['payload_bytes']!==HOTEL_PAYLOAD_BYTES||$ticket['payload_sha256']!==HOTEL_PAYLOAD_SHA
        ||!is_string($ticket['token_sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$ticket['token_sha256'])
        ||!is_int($ticket['created_at'])||!is_int($ticket['expires_at'])||$ticket['expires_at']>$now
        ||$ticket['created_at']<1793631600||$ticket['created_at']>1793632364
        ||$ticket['created_at']>=$ticket['expires_at']||$ticket['expires_at']-$ticket['created_at']>3600)throw new RuntimeException('prior_ticket_CAS');
    $oldAad='hotel-server-private-upload-ticket-v1|62814763dd6274b2e5c9f03ef6e2d689a7ed96af|'.HOTEL_OPERATION;
    $receiptKeys=array_keys($receipt);sort($receiptKeys);
    $expectedReceiptKeys=['operation','database_writes','provider_calls','runtime_enable_writes','state','algorithm','recipient_sha256',
        'aad','ticket_sha256','key','nonce','tag','ciphertext','ciphertext_sha256'];sort($expectedReceiptKeys);
    if($receiptKeys!==$expectedReceiptKeys||($receipt['database_writes']??null)!==0||($receipt['provider_calls']??null)!==0
        ||($receipt['runtime_enable_writes']??null)!==0||($receipt['algorithm']??null)!=='RSA-OAEP-SHA1+A256GCM')throw new RuntimeException('prior_ticket_CAS');
    if(($receipt['operation']??null)!=='hotel-server-private-upload-ticket-v1'||($receipt['state']??null)!=='sealed'
        ||($receipt['aad']??null)!==$oldAad||($receipt['ticket_sha256']??null)!==$oldTicketSha
        ||($receipt['recipient_sha256']??null)!=='56f838b25c67967d6261577bc1f9bd0e689f224c6f5b042de4a4292f9067678d')throw new RuntimeException('prior_ticket_CAS');
    $oldParts=[];foreach(['key','nonce','tag','ciphertext'] as $name){
        if(!is_string($receipt[$name]))throw new RuntimeException('prior_ticket_CAS');$oldParts[$name]=base64_decode($receipt[$name],true);
        if(!is_string($oldParts[$name]))throw new RuntimeException('prior_ticket_CAS');
    }
    if(strlen($oldParts['key'])!==384||strlen($oldParts['nonce'])!==12||strlen($oldParts['tag'])!==16
        ||strlen($oldParts['ciphertext'])<1||strlen($oldParts['ciphertext'])>65536
        ||!is_string($receipt['ciphertext_sha256'])||!hash_equals($receipt['ciphertext_sha256'],hash('sha256',$oldParts['ciphertext'])))throw new RuntimeException('prior_ticket_CAS');
    unset($oldParts);
    $recipient=base64_decode(HOTEL_RECIPIENT_BASE64,true);
    if(!is_string($recipient)||hash('sha256',$recipient)!==HOTEL_RECIPIENT_SHA)throw new RuntimeException('sealing_unavailable');
    // Both endpoint and issuer are serialized before the new capability becomes usable.
    $upload=fopen($dir.'/upload.lock','x');if(!$upload||!chmod($dir.'/upload.lock',0600))throw new RuntimeException('renewal_unconfirmed');$locks[]=$upload;
    if(!flock($upload,LOCK_EX|LOCK_NB))throw new RuntimeException('active_private_operation');
    if(!mkdir($journal,0700))throw new RuntimeException('renewal_unconfirmed');
    renewNewFile($journal.'/prior-ticket.json',$oldTicket);renewNewFile($journal.'/prior-ticket-receipt.sealed.json',$oldReceipt);
    $token=bin2hex(random_bytes(32));
    $newTicket=['schema'=>'hotel-server-private-upload-v1','operation_id'=>HOTEL_OPERATION,'payload_bytes'=>HOTEL_PAYLOAD_BYTES,
        'payload_sha256'=>HOTEL_PAYLOAD_SHA,'token_sha256'=>hash('sha256',$token),'created_at'=>$now,'expires_at'=>$now+3600];
    $newBytes=json_encode($newTicket,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";$newSha=hash('sha256',$newBytes);
    $payload=['schema'=>$fixed['operation'],'installed_sha'=>HOTEL_EXPECTED_INSTALLED_SHA,'endpoint'=>'https://app.anytoour.ru/hotel-server-input.php',
        'operation_id'=>HOTEL_OPERATION,'prior_ticket_sha256'=>$oldTicketSha,'prior_receipt_sha256'=>$oldReceiptSha,
        'ticket'=>$newTicket,'ticket_sha256'=>$newSha,'token'=>$token,'previous_outcome'=>'pre_ingress_failure_reconciled',
        'zip_archive_available'=>class_exists('ZipArchive'),
        'database_writes'=>0,'provider_calls'=>0,'runtime_enable_writes'=>0];
    $aad=$fixed['operation'].'|'.HOTEL_OPERATION.'|'.$oldTicketSha;
    $key=random_bytes(32);$nonce=random_bytes(12);$tag='';$wrapped='';$plain=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
    if(!is_string($cipher)||!openssl_public_encrypt($key,$wrapped,$recipient,OPENSSL_PKCS1_OAEP_PADDING)||strlen($wrapped)!==384||strlen($tag)!==16)throw new RuntimeException('sealing_unavailable');
    $sealed=$fixed+['state'=>'sealed','algorithm'=>'RSA-OAEP-SHA1+A256GCM','recipient_sha256'=>HOTEL_RECIPIENT_SHA,'aad'=>$aad,
        'prior_ticket_sha256'=>$oldTicketSha,'prior_receipt_sha256'=>$oldReceiptSha,'ticket_sha256'=>$newSha,
        'key'=>base64_encode($wrapped),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),
        'ciphertext'=>base64_encode($cipher),'ciphertext_sha256'=>hash('sha256',$cipher)];
    $sealedBytes=json_encode($sealed,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    renewNewFile($journal.'/new-ticket.json',$newBytes);renewNewFile($journal.'/new-ticket-receipt.sealed.json',$sealedBytes);
    renewNewFile($dir.'/ticket-receipt.next',$sealedBytes);renewNewFile($dir.'/ticket.next',$newBytes);
    if(!hash_equals($oldTicketSha,hash_file('sha256',$dir.'/ticket.json'))
        ||!hash_equals($oldReceiptSha,hash_file('sha256',$dir.'/ticket-receipt.sealed.json')))throw new RuntimeException('prior_ticket_CAS');
    if(file_exists($root.'/runtime.json')||is_link($root.'/runtime.json')
        ||$pdo->query('SELECT 1 FROM hotel_server_campaigns LIMIT 1')->fetchColumn()!==false)throw new RuntimeException('campaign_already_present');
    // Receipt first; ticket last is the activation point. A crash leaves the journal held for readback, never automatic replay.
    if(!rename($dir.'/ticket-receipt.next',$dir.'/ticket-receipt.sealed.json')||!rename($dir.'/ticket.next',$dir.'/ticket.json'))throw new RuntimeException('renewal_unconfirmed');
    if(!hash_equals($newSha,hash('sha256',renewPrivateFile($dir.'/ticket.json',4096)))
        ||!hash_equals(hash('sha256',$sealedBytes),hash('sha256',renewPrivateFile($dir.'/ticket-receipt.sealed.json',32768))))throw new RuntimeException('renewal_unconfirmed');
    $out=$sealed;unset($token,$key,$plain,$payload,$oldTicket,$oldReceipt);
}catch(Throwable $e){
    $allowed=['target_unconfirmed','exact_pins_required','private_channel_unconfirmed','activation_already_present','campaign_already_present','active_private_operation',
        'existing_renewal_requires_readback','upload_artifacts_present','prior_ticket_CAS','renewal_unconfirmed','sealing_unavailable'];
    $out=$fixed+['state'=>'HOLD','reason'=>in_array($e->getMessage(),$allowed,true)?$e->getMessage():'renewal_unconfirmed'];
}finally{foreach(array_reverse($locks) as $lock){flock($lock,LOCK_UN);fclose($lock);}while(ob_get_level()>$level)ob_end_clean();}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";exit($out['state']==='sealed'?0:1);
