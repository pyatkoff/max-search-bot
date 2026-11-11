<?php
declare(strict_types=1);
require_once __DIR__.'/DiagnosticLogger.php';

/** One private key outside the checkout; ciphertext is bound to its client. */
final class ProjectClientCredentialStore
{
    public function __construct(private ?string $directory=null) {}
    private function key(bool $create=false): string
    {
        $root=$this->directory;
        if($root===null){$log=DiagnosticLogger::filePath();if($log==='')throw new RuntimeException('credential_store_unavailable');$root=dirname($log).'/client-credentials';}
        if($root===''||$root[0]!=='/'||str_contains($root,"\0"))throw new RuntimeException('credential_store_unavailable');
        $checkout=realpath(dirname(__DIR__));$cursor='';
        foreach(explode('/',$root) as $part){if($part==='')continue;if($part==='.'||$part==='..')throw new RuntimeException('credential_store_unavailable');$cursor.='/'.$part;if(is_link($cursor))throw new RuntimeException('credential_store_unavailable');}
        if($checkout!==false&&($root===$checkout||str_starts_with($root,$checkout.'/')))throw new RuntimeException('credential_store_unavailable');
        if(!is_dir($root)&&(!$create||(!@mkdir($root,0700,true)&&!is_dir($root))))throw new RuntimeException('credential_store_unavailable');
        $stat=@lstat($root);
        if(realpath($root)!==$root||!$stat||($stat['mode']&0170000)!==0040000||($stat['mode']&0077)!==0)throw new RuntimeException('credential_store_unavailable');
        $path=$root.'/master.key';
        if(!file_exists($path)&&$create){
            $mask=umask(0077);$handle=@fopen($path,'x+b');umask($mask);
            if($handle){try{$bytes=random_bytes(32);if(fwrite($handle,$bytes)!==32||!fflush($handle)||!@chmod($path,0600))throw new RuntimeException('credential_store_unavailable');}finally{fclose($handle);}}
        }
        clearstatcache(true,$path);$stat=@lstat($path);
        if(!$stat||($stat['mode']&0170000)!==0100000||($stat['mode']&0077)!==0||$stat['size']!==32)throw new RuntimeException('credential_store_unavailable');
        $handle=@fopen($path,'rb');if(!$handle)throw new RuntimeException('credential_store_unavailable');
        try{$opened=fstat($handle);if(!$opened||$opened['ino']!==$stat['ino']||$opened['dev']!==$stat['dev'])throw new RuntimeException('credential_store_unavailable');$key=stream_get_contents($handle,33);}finally{fclose($handle);}
        if(!is_string($key)||strlen($key)!==32)throw new RuntimeException('credential_store_unavailable');return $key;
    }
    /** Explicit first creation only. Never silently replace a missing live key. */
    public function initialize(bool $registryEmpty): void
    {
        try{$this->key();}catch(RuntimeException $e){if(!$registryEmpty)throw $e;$this->key(true);}
    }
    public function fingerprint(string $value): string {return hash_hmac('sha256',$value,$this->key());}
    public function seal(string $project,string $bot,array $credentials): string
    {
        $nonce=random_bytes(12);$tag='';$plain=json_encode($credentials,JSON_THROW_ON_ERROR);
        $cipher=openssl_encrypt($plain,'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$nonce,$tag,'client-v1:'.$project.':'.$bot,16);
        if($cipher===false)throw new RuntimeException('credential_store_unavailable');
        return json_encode(['version'=>1,'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'cipher'=>base64_encode($cipher)],JSON_THROW_ON_ERROR);
    }
    public function open(string $project,string $bot,string $envelope): array
    {
        if(strlen($envelope)>16384)throw new RuntimeException('credential_store_unavailable');$row=json_decode($envelope,true);
        if(!is_array($row)||($row['version']??null)!==1)throw new RuntimeException('credential_store_unavailable');
        $values=[];foreach(['nonce','tag','cipher'] as $key){if(!is_string($row[$key]??null)||($values[$key]=base64_decode($row[$key],true))===false)throw new RuntimeException('credential_store_unavailable');}
        if(strlen($values['nonce'])!==12||strlen($values['tag'])!==16)throw new RuntimeException('credential_store_unavailable');
        $plain=openssl_decrypt($values['cipher'],'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$values['nonce'],$values['tag'],'client-v1:'.$project.':'.$bot);
        $credentials=$plain===false?null:json_decode($plain,true);if(!is_array($credentials)||!is_string($credentials['token']??null)||!is_string($credentials['webhook_secret']??null))throw new RuntimeException('credential_store_unavailable');return $credentials;
    }
    public function __debugInfo(): array {return ['credentials'=>'[redacted]'];}
}
