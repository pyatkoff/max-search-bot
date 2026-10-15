<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
require_once dirname(__DIR__).'/services/MaxTrackerLookupConfig.php';
try{
    if(($argv[1]??'')!=='init'||count($argv)!==2)throw new RuntimeException('invalid_operation');
    $path=MaxTrackerLookupConfig::privateKeyPath();$dir=dirname($path);
    if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('private_directory_unavailable');
    if(is_link($dir)||is_link($path)||(fileperms($dir)&0077)!==0)throw new RuntimeException('private_permissions_required');
    if(!file_exists($path)){
        $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        if(!$key||!openssl_pkey_export($key,$pem))throw new RuntimeException('key_generation_failed');
        $old=umask(0077);$file=fopen($path,'x');umask($old);
        if(!$file)throw new RuntimeException('private_key_exists');
        $ok=fwrite($file,$pem)===strlen($pem);fclose($file);
        if(!$ok){unlink($path);throw new RuntimeException('private_key_storage_failed');}
    }
    $key=openssl_pkey_get_private(MaxTrackerLookupConfig::privateKey());$details=$key?openssl_pkey_get_details($key):false;
    if(!$details||$details['type']!==OPENSSL_KEYTYPE_RSA||$details['bits']<2048)throw new RuntimeException('invalid_existing_key');
    // Only the PUBLIC verification key is exported. The private key never leaves this host.
    echo $details['key'];
}catch(Throwable $ignored){fwrite(STDERR,"Signing key operation failed.\n");exit(1);}
