<?php
declare(strict_types=1);
require_once __DIR__.'/AdvertisingVisitFeedProtocol.php';
require_once __DIR__.'/AdvertisingVisitImportService.php';

/** Fixed AnyTour source. No goal uploader, customer transport or source journal mutation. */
final class AdvertisingVisitTransport
{
    public const URL='https://tg.rhat.ru/tgmini/tgmini_identity_feed.php';
    public const SCOPE=['project_key'=>'anytour','source_key'=>'tgmini','platform'=>'telegram','counter_id'=>'98615635'];
    private string $directory;
    public function __construct(?string $directory=null)
    {
        $this->directory=$directory??dirname(dirname(__DIR__)).'/max-search-bot-advertising-transport';
        self::safePath($this->directory);
    }

    public function initialized(): bool { return is_file($this->directory.'/receiver.json'); }
    public function enabled(): bool
    {
        $path=$this->directory.'/enabled.json';
        if (!file_exists($path) && !is_link($path)) return false;
        $value=json_decode($this->readPrivate($path),true,4,JSON_THROW_ON_ERROR);
        return is_array($value) && $value===['public_fingerprint'=>$this->publicKeys()['public_fingerprint']];
    }

    /** One-time receiver-owned keys; retries reuse them. Never prints or rotates private keys. */
    public function initialize(): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory,0700)) self::fail('storage');
        $this->directoryReady();
        $path=$this->directory.'/receiver.json';
        if (file_exists($path) || is_link($path)) return $this->publicKeys();
        $h=@fopen($path,'xb');
        if (!$h) { if (is_file($path)) return $this->publicKeys();self::fail('storage'); }
        $ok=false;
        try {
            if (!@chmod($path,0600)) self::fail('storage');
            $raw=json_encode(['schema_version'=>1,'sign_seed'=>bin2hex(random_bytes(32)),
                'box_secret'=>bin2hex(sodium_crypto_box_secretkey(sodium_crypto_box_keypair()))],JSON_THROW_ON_ERROR)."\n";
            if (fwrite($h,$raw)!==strlen($raw) || !fflush($h)) self::fail('storage');
            $ok=true;
        } finally { fclose($h);if(!$ok)@unlink($path); }
        return $this->publicKeys();
    }

    public function publicKeys(): array
    {
        $keys=$this->keys();
        $public=['request_public_key'=>bin2hex(sodium_crypto_sign_publickey($keys['sign_pair'])),
            'response_public_key'=>bin2hex(sodium_crypto_box_publickey($keys['box_pair']))];
        return $public+['public_fingerprint'=>hash('sha256',json_encode($public,JSON_THROW_ON_ERROR))];
    }

    public function status(): array
    {
        $initialized=$this->initialized();
        $last=null;$p=$this->directory.'/last-run.json';
        if (is_file($p)) $last=json_decode($this->readPrivate($p),true,8,JSON_THROW_ON_ERROR);
        return ['initialized'=>$initialized,'enabled'=>$initialized?$this->enabled():false,'source_url'=>self::URL,
            'scope'=>self::SCOPE,'last_run'=>$last,'conversion_uploads'=>0];
    }

    /** Only enable after an authenticated, decrypted page has committed successfully. */
    public function enable(PDO $pdo,?callable $fetch=null,?int $now=null): array
    {
        $result=$this->pull($pdo,$fetch,$now,true);
        if ($result['status']!=='imported') self::fail('enable');
        $this->writePrivate('enabled.json',['public_fingerprint'=>$this->publicKeys()['public_fingerprint']]);
        return $result+['enabled'=>true];
    }

    public function disable(): void
    {
        $path=$this->directory.'/enabled.json';
        if (!file_exists($path) && !is_link($path)) return;
        $this->readPrivate($path);
        if (!unlink($path)) self::fail('storage');
    }

    /** One bounded page per scheduler tick. Database checkpoint moves only with accepted data. */
    public function pull(PDO $pdo,?callable $fetch=null,?int $now=null,bool $force=false): array
    {
        if (!$force && !$this->enabled()) return ['status'=>'off','conversion_uploads'=>0];
        $now=$now??time();$keys=$this->keys();
        $lockPath=$this->directory.'/pull.lock';
        self::safePath($lockPath);
        $h=@fopen($lockPath,'c+b');
        if (!$h || !@chmod($lockPath,0600)) {if(is_resource($h))fclose($h);self::fail('storage');}
        if (!flock($h,LOCK_EX|LOCK_NB)) {fclose($h);return ['status'=>'busy','conversion_uploads'=>0];}
        try {
            $repo=new AdvertisingVisitRepository($pdo);$checkpoint=$repo->checkpoint(self::SCOPE);
            $request=AdvertisingVisitFeedProtocol::request(self::SCOPE,$checkpoint,sodium_crypto_sign_secretkey($keys['sign_pair']),$now);
            $wire=$fetch?$fetch($request):self::fetch($request);
            if (!is_string($wire)) self::fail('response');
            $received=$fetch?$now:time();
            $read=AdvertisingVisitFeedProtocol::open($wire,$request['body'],$keys['box_pair'],self::SCOPE,$checkpoint,$received);
            $imported=(new AdvertisingVisitImportService($repo,self::SCOPE))->import($read['page'],$received);
            $verified=0;$withClient=0;
            foreach($read['page']['observations'] as $row){$verified+=(int)($row['identity_status']==='verified');$withClient+=(int)($row['client_id']!=='');}
            $result=['status'=>'imported']+$imported+['observations'=>count($read['page']['observations']),
                'verified_identities'=>$verified,'with_client_id'=>$withClient,'has_more'=>$read['has_more'],
                'received_at_unix'=>$received,'conversion_uploads'=>0];
            // The DB commit is authoritative even if a nonessential telemetry write fails.
            try{$this->writePrivate('last-run.json',$result);}catch(Throwable $ignored){}
            return $result;
        } finally {flock($h,LOCK_UN);fclose($h);}
    }

    /** Unconfigured/disabled is a cheap no-op; reuse the existing scheduler and isolate failures. */
    public static function tick(): array
    {
        $self=new self();
        if (!$self->initialized() || !$self->enabled()) return ['status'=>'off','conversion_uploads'=>0];
        require_once __DIR__.'/ProjectConfig.php';
        require_once __DIR__.'/ConversationDb.php';
        if (ProjectConfig::projectId()!==self::SCOPE['project_key']) self::fail('project');
        return $self->pull(ConversationDb::connection());
    }

    private static function fetch(array $request): string
    {
        if (!function_exists('curl_init')) self::fail('curl_unavailable');
        $body='';$h=curl_init(self::URL);
        curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$request['body'],
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/octet-stream','X-AnyTour-Signature: '.$request['signature']],
            CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$body):int{
                if(strlen($body)+strlen($chunk)>AdvertisingVisitFeedProtocol::MAX_WIRE)return 0;
                $body.=$chunk;return strlen($chunk);
            }]);
        $ok=curl_exec($h);$code=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);curl_close($h);
        if($ok===false || $code!==200) self::fail('source_unavailable');
        return $body;
    }

    private function keys(): array
    {
        $raw=json_decode($this->readPrivate($this->directory.'/receiver.json'),true,4,JSON_THROW_ON_ERROR);
        if(!is_array($raw)||array_keys($raw)!==['schema_version','sign_seed','box_secret']||$raw['schema_version']!==1) self::fail('key');
        foreach(['sign_seed','box_secret'] as $k)if(!is_string($raw[$k])||!preg_match('/\A[a-f0-9]{64}\z/D',$raw[$k]))self::fail('key');
        $secret=hex2bin($raw['box_secret']);
        return ['sign_pair'=>sodium_crypto_sign_seed_keypair(hex2bin($raw['sign_seed'])),
            'box_pair'=>sodium_crypto_box_keypair_from_secretkey_and_publickey($secret,sodium_crypto_box_publickey_from_secretkey($secret))];
    }

    private function directoryReady(): void
    {
        self::safePath($this->directory);clearstatcache(true,$this->directory);$s=@lstat($this->directory);
        if(!$s||($s['mode']&0170000)!==0040000||($s['mode']&0077)!==0)self::fail('storage');
    }
    private function readPrivate(string $path): string
    {
        $this->directoryReady();self::safePath($path);clearstatcache(true,$path);$h=@fopen($path,'rb');
        if(!$h)self::fail('storage');
        try{
            $s=fstat($h);$n=lstat($path);
            if(!$s||!$n||$s['ino']!==$n['ino']||$s['dev']!==$n['dev']||($s['mode']&0170000)!==0100000||($s['mode']&0077)!==0||$s['size']>2048)self::fail('storage');
            $raw=stream_get_contents($h,2049);if($raw===false||strlen($raw)>2048)self::fail('storage');return $raw;
        }finally{fclose($h);}
    }
    private function writePrivate(string $name,array $value): void
    {
        $this->directoryReady();$path=$this->directory.'/'.$name;self::safePath($path);
        if(file_exists($path))$this->readPrivate($path);
        $temp=$this->directory.'/.write-'.bin2hex(random_bytes(8));
        $h=fopen($temp,'xb');$ok=false;
        try{
            if(!chmod($temp,0600))self::fail('storage');$raw=json_encode($value,JSON_THROW_ON_ERROR)."\n";
            if(fwrite($h,$raw)!==strlen($raw)||!fflush($h))self::fail('storage');fclose($h);$h=null;
            self::safePath($path);if(!rename($temp,$path))self::fail('storage');$ok=true;
        }finally{if(is_resource($h))fclose($h);if(!$ok)@unlink($temp);}
    }
    private static function safePath(string $path): void
    {
        $root=realpath(dirname(__DIR__));
        if(!$root||$path===''||$path[0]!=='/'||strpos($path,"\0")!==false||$path===$root||str_starts_with($path,$root.'/'))self::fail('storage');
        $part='';foreach(explode('/',$path)as$p){if($p==='')continue;if($p==='.'||$p==='..')self::fail('storage');$part.='/'.$p;if(is_link($part))self::fail('storage');}
    }
    private static function fail(string $reason): void {throw new RuntimeException('advertising_transport_'.$reason);}
}
