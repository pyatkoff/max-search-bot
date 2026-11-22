<?php
declare(strict_types=1);

require_once __DIR__.'/ProjectClientCredentialStore.php';
require_once __DIR__.'/TelegramArchiveBotSetup.php';

/** Dedicated, opt-in, credential-only connection. Never touches Telegram webhooks. */
final class AnyTourPublisherBotConnection
{
    public const USERNAME = 'ANYTOURBOT_bot';
    private string $root;
    private ProjectClientCredentialStore $vault;
    private $request;

    public function __construct(?string $root=null, ?callable $request=null)
    {
        $log = DiagnosticLogger::filePath();
        if ($root === null && $log === '') throw new RuntimeException('storage_unavailable');
        $this->root = $root ?? dirname($log).'/anytour-publisher-credentials';
        $this->vault = new ProjectClientCredentialStore($this->root);
        $this->request = $request ?? [TelegramArchiveBotSetup::class,'network'];
    }

    private function directory(): void
    {
        if ($this->root === '' || $this->root[0] !== '/' || str_contains($this->root,"\\0"))
            throw new RuntimeException('storage_unavailable');
        foreach (explode('/', $this->root) as $part)
            if ($part === '.' || $part === '..') throw new RuntimeException('storage_unavailable');
        $cursor = '';
        foreach (explode('/', $this->root) as $part) {
            if ($part === '') continue;
            $cursor .= '/'.$part;
            if (is_link($cursor)) throw new RuntimeException('storage_unavailable');
        }
        $checkout = realpath(dirname(__DIR__));
        if ($checkout !== false && ($this->root === $checkout || str_starts_with($this->root,$checkout.'/')))
            throw new RuntimeException('storage_unavailable');
        if (!is_dir($this->root) && (!@mkdir($this->root,0700,true) && !is_dir($this->root)))
            throw new RuntimeException('storage_unavailable');
        if (realpath($this->root) !== $this->root || (fileperms($this->root) & 0077) !== 0)
            throw new RuntimeException('storage_unavailable');
    }

    private function locked(callable $fn): mixed
    {
        $this->directory();
        $path = $this->root.'/connection.lock';
        if (is_link($path)) throw new RuntimeException('storage_unavailable');
        $mask = umask(0077);
        $fp = @fopen($path,'c+b');
        umask($mask);
        if (!$fp) throw new RuntimeException('storage_unavailable');
        try {
            if (!flock($fp,LOCK_EX) || (fstat($fp)['mode'] & 0077) !== 0)
                throw new RuntimeException('storage_unavailable');
            return $fn();
        } finally {
            flock($fp,LOCK_UN);
            fclose($fp);
        }
    }

    private function record(): ?array
    {
        $path=$this->root.'/connection.json';
        if (is_link($path)) throw new RuntimeException('storage_unavailable');
        if (!file_exists($path)) return null;
        if (!is_file($path) || filesize($path)>20000) throw new RuntimeException('storage_unavailable');
        $data=json_decode((string)file_get_contents($path),true);
        if (!is_array($data) || ($data['username']??null)!==self::USERNAME
            || !is_int($data['version']??null) || $data['version']<1
            || !preg_match('/\\A[1-9][0-9]{4,18}\\z/D',(string)($data['bot_id']??''))
            || !is_string($data['envelope']??null)) throw new RuntimeException('storage_unavailable');
        return $data;
    }

    private function identity(string $token): string
    {
        if (!preg_match('/\\A([1-9][0-9]{4,18}):[A-Za-z0-9_-]{20,200}\\z/D',$token,$m))
            throw new RuntimeException('invalid_token');
        try { $response=($this->request)($token,'getMe',[]); }
        catch (Throwable $ignored) { throw new RuntimeException('identity_unavailable'); }
        $me=$response['result']??null;
        if (($response['http']??0)!==200 || ($response['ok']??false)!==true || !is_array($me))
            throw new RuntimeException('identity_unavailable');
        if (($me['is_bot']??null)!==true || strcasecmp((string)($me['username']??''),self::USERNAME)!==0
            || (string)($me['id']??'')!==$m[1]) throw new RuntimeException('identity_mismatch');
        return $m[1];
    }

    public function status(): array
    {
        return $this->locked(function(): array {
            $row=$this->record();
            if ($row) $this->vault->open('anytour','telegram:'.$row['bot_id'],$row['envelope']);
            return ['configured'=>$row!==null,'username'=>self::USERNAME,
                'version'=>$row['version']??0,'updated_at'=>$row['updated_at']??null,
                'webhook_unchanged'=>true];
        });
    }

    public function save(int $version,string $token): void
    {
        $id=$this->identity($token);
        $this->locked(function() use ($version,$token,$id): void {
            $row=$this->record();
            if (($row['version']??0)!==$version) throw new RuntimeException('context_changed');
            if ($row && $row['bot_id']!==$id) throw new RuntimeException('identity_mismatch');
            $this->vault->initialize($row===null);
            if ($row) $this->vault->open('anytour','telegram:'.$id,$row['envelope']);
            $envelope=$this->vault->seal('anytour','telegram:'.$id,
                ['token'=>$token,'webhook_secret'=>'not-used']);
            $data=json_encode(['username'=>self::USERNAME,'bot_id'=>$id,'version'=>$version+1,
                'updated_at'=>gmdate('c'),'envelope'=>$envelope],JSON_THROW_ON_ERROR);
            $tmp=$this->root.'/connection.'.bin2hex(random_bytes(12)).'.tmp';
            $mask=umask(0077); $fp=@fopen($tmp,'x+b'); umask($mask);
            if (!$fp) throw new RuntimeException('storage_unavailable');
            try {
                if (fwrite($fp,$data)!==strlen($data) || !fflush($fp) || !chmod($tmp,0600))
                    throw new RuntimeException('storage_unavailable');
            } finally { fclose($fp); }
            if (!rename($tmp,$this->root.'/connection.json'))
                throw new RuntimeException('storage_unavailable');
        });
    }
}
