<?php

declare(strict_types=1);
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ManagerConversationAccessPolicy.php';
require_once __DIR__.'/ProjectConfig.php';

/** Optional channel portraits. No business writes; never called by list/detail APIs. */
final class ManagerCustomerAvatarService
{
    public const MAX_BYTES = 262144;
    public const CACHE_SLOTS = 128;
    public const POSITIVE_TTL = 21600;
    public const NEGATIVE_TTL = 300;
    private const MAX_RECORD_BYTES = 351000;
    private string $directory;
    private $fetch;

    /** Injectable bounded transport/cache location for offline regression only. */
    public function __construct(?string $directory=null, ?callable $fetch=null)
    {
        $this->directory=$directory ?? sys_get_temp_dir().'/max-search-bot-manager-avatars-'.substr(hash('sha256',dirname(__DIR__)),0,16);
        $this->fetch=$fetch ?? [self::class,'request'];
    }

    /** Returns validated bytes and MIME or null. Authorization precedes ALL cache reads. */
    public function avatar(int $managerId, int $conversationId): ?array
    {
        if ($managerId<=0 || $conversationId<=0 || !ManagerAuthService::byId($managerId)) return null;
        $q=ConversationDb::connection()->prepare('SELECT c.id,c.project_key,c.source_id,c.status,c.manager_id,c.started_at,c.last_message_at,c.channel,cc.external_user_id FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.id=? LIMIT 1');
        $q->execute([$conversationId]);$identity=$q->fetch(PDO::FETCH_ASSOC);
        if (!$identity || !ManagerConversationAccessPolicy::canView($managerId,$identity)) return null;
        // The existing token belongs to this deployment's exact project, not to
        // every project a manager can view. Never try another project's token.
        if ((string)$identity['project_key']!==ProjectConfig::projectId()) return null;
        if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
        $key=hash('sha256',json_encode([(string)$identity['project_key'],(string)$identity['channel'],(string)$identity['external_user_id']],JSON_THROW_ON_ERROR));
        $dir=$this->privateDirectory();if ($dir===null) return null;
        // Fixed slots bound both records AND lock files. A slot collision evicts
        // only the cache entry; the full identity hash must match before serving.
        $slot=(string)(hexdec(substr($key,0,4))%self::CACHE_SLOTS);
        $path=$dir.'/'.$slot.'.json';$lockPath=$dir.'/'.$slot.'.lock';
        if (is_link($lockPath) || is_link($path)) return null;
        $lock=@fopen($lockPath,'c');if (!$lock) return null;
        @chmod($lockPath,0600);
        if (!@flock($lock,LOCK_EX|LOCK_NB)) {fclose($lock);return null;}
        try {
            $record=$this->readRecord($path);
            if ($record!==null && ($record['key']??'')===$key && (int)($record['expires']??0)>time()) {
                if (!isset($record['bytes'])) return null;
                $bytes=base64_decode((string)$record['bytes'],true);
                return is_string($bytes)?self::image($bytes):null;
            }
            try {$photo=$this->telegramPhoto($identity);} catch (Throwable $e) {$photo=null;}
            $record=['key'=>$key,'expires'=>time()+($photo?self::POSITIVE_TTL:self::NEGATIVE_TTL)];
            if ($photo) $record['bytes']=base64_encode($photo['bytes']);
            $tmp=$dir.'/'.$slot.'.tmp';
            if (is_link($tmp)) return null;
            // A retained temp file is bounded by this same slot and overwritten
            // under its stable lock; atomic rename keeps cache reads complete.
            $h=@fopen($tmp,'wb');
            if ($h) {
                @chmod($tmp,0600);$json=json_encode($record,JSON_THROW_ON_ERROR);
                $ok=fwrite($h,$json)===strlen($json) && fflush($h);fclose($h);
                if ($ok) @rename($tmp,$path);else @unlink($tmp);
            }
            return $photo;
        } finally {@flock($lock,LOCK_UN);fclose($lock);}
    }

    private function telegramPhoto(array $identity): ?array
    {
        // MAX User has no avatar. Its official avatar-capable ChatMember endpoint
        // requires a group/channel and bot admin rights, not a private dialogue:
        // https://dev.max.ru/docs-api/objects/User
        // https://dev.max.ru/docs-api/methods/GET/chats/-chatId-/members
        // No supported private-user profile lookup exists in the current adapter.
        if ($identity['channel']!=='telegram') return null;
        $id=(string)$identity['external_user_id'];
        $token=defined('TELEGRAM_BOT_TOKEN')?trim((string)TELEGRAM_BOT_TOKEN):'';
        if (!preg_match('/^[1-9][0-9]{0,15}$/D',$id) || !preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/D',$token)) return null;
        // https://core.telegram.org/bots/api#getuserprofilephotos and #getfile.
        $deadline=microtime(true)+5.0;$fetch=$this->fetch;
        $call=static function(string $url,?array $post,int $limit) use($fetch,$deadline): ?string {
            $budget=(int)(($deadline-microtime(true))*1000);
            if ($budget<=0) return null;
            $bytes=$fetch($url,$post,$limit,$budget);
            return is_string($bytes) && strlen($bytes)<=$limit?$bytes:null;
        };
        $photos=json_decode($call('https://api.telegram.org/bot'.$token.'/getUserProfilePhotos',['user_id'=>$id,'offset'=>0,'limit'=>1],65536)??'',true);
        if (empty($photos['ok'])) return null;
        $sizes=array_filter((array)($photos['result']['photos'][0]??[]),static fn($p):bool=>is_array($p) && (int)($p['width']??0)>0 && (int)($p['width']??0)<=1024 && (int)($p['height']??0)>0 && (int)($p['height']??0)<=1024 && (int)($p['file_size']??0)<=self::MAX_BYTES);
        if (!$sizes) return null;
        usort($sizes,static fn($a,$b):int=>(int)$a['width']*(int)$a['height']<=>(int)$b['width']*(int)$b['height']);
        $fileId=(string)($sizes[0]['file_id']??'');
        if ($fileId==='' || strlen($fileId)>1024) return null;
        $data=json_decode($call('https://api.telegram.org/bot'.$token.'/getFile',['file_id'=>$fileId],65536)??'',true);
        $file=$data['result']??[];$path=(string)($file['file_path']??'');
        if (empty($data['ok']) || !preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)+$~D',$path) || str_contains($path,'..') || (int)($file['file_size']??0)>self::MAX_BYTES) return null;
        $bytes=$call('https://api.telegram.org/file/bot'.$token.'/'.$path,null,self::MAX_BYTES);
        return $bytes!==null?self::image($bytes):null;
    }

    private static function image(string $bytes): ?array
    {
        if ($bytes==='' || strlen($bytes)>self::MAX_BYTES) return null;
        $info=@getimagesizefromstring($bytes);
        if (!$info || $info[0]<1 || $info[1]<1 || $info[0]>1024 || $info[1]>1024) return null;
        $mime=(string)($info['mime']??'');
        if (!in_array($mime,['image/jpeg','image/png','image/webp'],true) || (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)!==$mime) return null;
        return ['bytes'=>$bytes,'mime'=>$mime];
    }

    private function privateDirectory(): ?string
    {
        $dir=$this->directory;
        if ($dir==='' || $dir[0]!=='/' || is_link($dir)) return null;
        // Resolve the parent before creation, so aliases into document root are
        // rejected without creating even an empty cache inside the application.
        $parent=realpath(dirname($dir));if ($parent===false) return null;
        $resolved=$parent.'/'.basename($dir);
        foreach ([realpath(dirname(__DIR__)),realpath((string)($_SERVER['DOCUMENT_ROOT']??''))] as $root) {
            if ($root!==false && ($resolved===$root || str_starts_with($resolved,rtrim($root,'/').'/'))) return null;
        }
        if (!is_dir($dir) && !@mkdir($dir,0700)) return null;
        $actual=realpath($dir);
        if ($actual!==$resolved || !@chmod($dir,0700)) return null;
        return $actual;
    }

    private function readRecord(string $path): ?array
    {
        if (!is_file($path) || is_link($path) || filesize($path)>self::MAX_RECORD_BYTES) return null;
        $bytes=@file_get_contents($path,false,null,0,self::MAX_RECORD_BYTES+1);
        return is_string($bytes) && strlen($bytes)<=self::MAX_RECORD_BYTES && is_array($data=json_decode($bytes,true))?$data:null;
    }

    private static function request(string $url, ?array $post, int $limit, int $budget): ?string
    {
        // Only constructed Telegram HTTPS URLs; no redirect or provider URL can
        // choose a host, credentials destination, local file or arbitrary port.
        if (!preg_match('~^https://api\.telegram\.org/(?:bot[0-9]+:[A-Za-z0-9_-]+/(?:getUserProfilePhotos|getFile)|file/bot[0-9]+:[A-Za-z0-9_-]+/[A-Za-z0-9_./-]+)$~D',$url)) return null;
        $ch=curl_init($url);$bytes='';
        curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT_MS=>min(1000,$budget),CURLOPT_TIMEOUT_MS=>min(5000,$budget),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_WRITEFUNCTION=>static function($h,string $chunk) use(&$bytes,$limit):int {if (strlen($bytes)+strlen($chunk)>$limit) return 0;$bytes.=$chunk;return strlen($chunk);}]);
        if ($post!==null) {curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));}
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        return $ok!==false && $status===200?$bytes:null;
    }
}
