<?php

declare(strict_types=1);
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ManagerConversationAccessPolicy.php';
require_once __DIR__.'/ProjectConfig.php';
require_once __DIR__.'/../integrations/MaxCustomerAvatarAdapter.php';

/** Optional channel portraits. No business writes; never called by list/detail APIs. */
final class ManagerCustomerAvatarService
{
    public const MAX_BYTES = 262144;
    public const FULL_MAX_BYTES = 1048576;
    public const MAX_PHOTOS = 1000;
    public const CACHE_SLOTS = 128;
    public const FULL_CACHE_SLOTS = 32;
    public const POSITIVE_TTL = 86400;
    public const NEGATIVE_TTL = 300;
    private const MAX_RECORD_BYTES = 351000;
    private string $directory;
    private $fetch;
    private MaxCustomerAvatarAdapter $max;

    /** Injectable bounded transport/cache location for offline regression only. */
    public function __construct(?string $directory=null, ?callable $fetch=null, ?MaxCustomerAvatarAdapter $max=null)
    {
        $this->directory=$directory ?? sys_get_temp_dir().'/max-search-bot-manager-avatars-'.substr(hash('sha256',dirname(__DIR__)),0,16);
        $this->fetch=$fetch ?? [self::class,'request'];
        $this->max=$max ?? new MaxCustomerAvatarAdapter();
    }

    /** Returns validated bytes and MIME or null. Authorization precedes ALL cache reads. */
    public function avatar(int $managerId, int $conversationId, string $size='thumb', int $photo=0, ?string $expectedChannel=null): ?array
    {
        if (!in_array($size,['thumb','full'],true) || $photo<0 || $photo>=self::MAX_PHOTOS || ($size==='thumb' && $photo!==0)) return null;
        if ($expectedChannel!==null && !in_array($expectedChannel,['telegram','max'],true)) return null;
        $full=$size==='full';
        if ($managerId<=0 || $conversationId<=0 || !ManagerAuthService::byId($managerId)) return null;
        $q=ConversationDb::connection()->prepare('SELECT c.id,c.customer_channel_id,c.project_key,c.source_id,c.status,c.manager_id,c.started_at,c.last_message_at,c.channel,cc.external_user_id FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.id=? LIMIT 1');
        $q->execute([$conversationId]);$identity=$q->fetch(PDO::FETCH_ASSOC);
        if (!$identity || !ManagerConversationAccessPolicy::canView($managerId,$identity)) return null;
        // Browser cache URLs carry the channel as well as the account, so a
        // canonical identity change cannot reuse another messenger's image.
        if ($expectedChannel!==null && $identity['channel']!==$expectedChannel) return null;
        // The existing token belongs to this deployment's exact project, not to
        // every project a manager can view. Never try another project's token.
        if ((string)$identity['project_key']!==ProjectConfig::projectId()) return null;
        if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
        $cacheIdentity=[(string)$identity['project_key'],(string)$identity['channel'],(string)$identity['external_user_id']];
        // Version the newly supported provider and bind its stored-message proof
        // to this conversation/channel, without invalidating Telegram's cache.
        if ($identity['channel']==='max') $cacheIdentity=['max-private-v1',...$cacheIdentity,(int)$identity['id'],(int)$identity['customer_channel_id']];
        if ($full) $cacheIdentity=['full-v1',...$cacheIdentity,$photo];
        $key=hash('sha256',json_encode($cacheIdentity,JSON_THROW_ON_ERROR));
        $dir=$this->privateDirectory();if ($dir===null) return null;
        // Independent fixed full slots cannot evict thumbnails. Both namespaces
        // bound records AND lock files; a full identity hash guards collisions.
        $slot=($full?'full-':'').(string)(hexdec(substr($key,0,4))%($full?self::FULL_CACHE_SLOTS:self::CACHE_SLOTS));
        $path=$dir.'/'.$slot.'.json';$lockPath=$dir.'/'.$slot.'.lock';
        if (is_link($lockPath) || is_link($path)) return null;
        $lock=@fopen($lockPath,'c');if (!$lock) return null;
        @chmod($lockPath,0600);
        if (!@flock($lock,LOCK_EX|LOCK_NB)) {fclose($lock);return null;}
        try {
            $record=$this->readRecord($path,$full);
            if ($record!==null && ($record['key']??'')===$key && (int)($record['expires']??0)>time()) {
                if (!isset($record['bytes'])) return null;
                $bytes=base64_decode((string)$record['bytes'],true);
                $cached=is_string($bytes)?self::image($bytes,$full):null;
                if (!$full || $cached===null) return $cached;
                $count=$record['photo_count']??null;
                if (!is_int($count) || $count<1 || $count>self::MAX_PHOTOS || $photo>=$count || !is_bool($record['limited']??null)) return null;
                return $cached+['photo_count'=>$count,'limited'=>$record['limited']];
            }
            try {$image=$identity['channel']==='max'?$this->maxPhoto($identity,$full,$photo):$this->telegramPhoto($identity,$full,$photo);} catch (Throwable $e) {$image=null;}
            $record=['key'=>$key,'expires'=>time()+($image?self::POSITIVE_TTL:self::NEGATIVE_TTL)];
            if ($image) {
                $record['bytes']=base64_encode($image['bytes']);
                if ($full) {$record['photo_count']=$image['photo_count'];$record['limited']=$image['limited'];}
            }
            $tmp=$dir.'/'.$slot.'.tmp';
            if (is_link($tmp)) return null;
            // A retained temp file is bounded by this same slot and overwritten
            // under its stable lock; atomic rename keeps cache reads complete.
            $h=@fopen($tmp,'wb');
            if ($h) {
                @chmod($tmp,0600);$json=json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
                $ok=strlen($json)<=self::recordLimit($full) && fwrite($h,$json)===strlen($json) && fflush($h);fclose($h);
                if ($ok) @rename($tmp,$path);else @unlink($tmp);
            }
            return $image;
        } finally {@flock($lock,LOCK_UN);fclose($lock);}
    }

    private function telegramPhoto(array $identity, bool $full=false, int $photo=0): ?array
    {
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
            return microtime(true)<$deadline && is_string($bytes) && strlen($bytes)<=$limit?$bytes:null;
        };
        $limit=$full?self::FULL_MAX_BYTES:self::MAX_BYTES;
        $dimension=$full?4096:1024;
        $photos=json_decode($call('https://api.telegram.org/bot'.$token.'/getUserProfilePhotos',['user_id'=>$id,'offset'=>$photo,'limit'=>1],65536)??'',true);
        if (empty($photos['ok'])) return null;
        $count=$photos['result']['total_count']??null;
        if ($full && (!is_int($count) || $count<1 || $photo>=min($count,self::MAX_PHOTOS))) return null;
        $sizes=array_filter((array)($photos['result']['photos'][0]??[]),static function($p) use($full,$dimension,$limit):bool {
            if (!is_array($p) || (int)($p['width']??0)<1 || (int)($p['width']??0)>$dimension
                || (int)($p['height']??0)<1 || (int)($p['height']??0)>$dimension || (int)($p['file_size']??0)>$limit) return false;
            if (!$full) return true;
            return is_int($p['width']) && is_int($p['height'])
                && (!isset($p['file_size']) || (is_int($p['file_size']) && $p['file_size']>=0))
                && is_string($p['file_id']??null) && $p['file_id']!=='' && strlen($p['file_id'])<=1024;
        });
        if (!$sizes) return null;
        usort($sizes,static fn($a,$b):int=>$full?((int)$b['width']*(int)$b['height']<=>(int)$a['width']*(int)$a['height']):((int)$a['width']*(int)$a['height']<=>(int)$b['width']*(int)$b['height']));
        $fileId=(string)($sizes[0]['file_id']??'');
        if ($fileId==='' || strlen($fileId)>1024) return null;
        $data=json_decode($call('https://api.telegram.org/bot'.$token.'/getFile',['file_id'=>$fileId],65536)??'',true);
        $file=$data['result']??[];$path=(string)($file['file_path']??'');
        if (empty($data['ok']) || !preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)+$~D',$path) || str_contains($path,'..') || (int)($file['file_size']??0)>$limit) return null;
        $bytes=$call('https://api.telegram.org/file/bot'.$token.'/'.$path,null,$limit);
        $image=$bytes!==null?self::image($bytes,$full):null;
        return $image!==null && $full?$image+['photo_count'=>min($count,self::MAX_PHOTOS),'limited'=>$count>self::MAX_PHOTOS]:$image;
    }

    private function maxPhoto(array $identity, bool $full=false, int $photo=0): ?array
    {
        if ($photo!==0) return null;
        $user=MaxCustomerAvatarAdapter::decimalId($identity['external_user_id']??null);
        if ($user===null) return null;
        // The existing (conversation_id,id) index bounds this read to 256 recent
        // metadata rows, even in a long callback/outbound-only conversation.
        // Never hydrate text/history or use the internal chat alias as a MAX ID.
        $q=ConversationDb::connection()->prepare("SELECT external_message_id FROM (SELECT id,channel,direction,sender_type,sender_id,external_message_id,metadata_json FROM messages WHERE conversation_id=? ORDER BY id DESC LIMIT 256) recent WHERE channel='max' AND direction='inbound' AND sender_type='customer' AND sender_id=? AND external_message_id IS NOT NULL AND external_message_id<>'' AND CASE WHEN JSON_VALID(metadata_json) THEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.type')) ELSE NULL END='message' ORDER BY id DESC LIMIT 1");
        $q->execute([(int)$identity['id'],$user]);$mid=$q->fetchColumn();
        if (!is_string($mid)) return null;
        $bytes=$this->max->photo($user,$mid,$full?self::FULL_MAX_BYTES:self::MAX_BYTES,$full);
        $image=$bytes!==null?self::image($bytes,$full):null;
        return $image!==null && $full?$image+['photo_count'=>1,'limited'=>false]:$image;
    }

    private static function image(string $bytes, bool $full=false): ?array
    {
        if ($bytes==='' || strlen($bytes)>($full?self::FULL_MAX_BYTES:self::MAX_BYTES)) return null;
        $info=@getimagesizefromstring($bytes);
        $dimension=$full?4096:1024;
        if (!$info || $info[0]<1 || $info[1]<1 || $info[0]>$dimension || $info[1]>$dimension) return null;
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

    private function readRecord(string $path, bool $full=false): ?array
    {
        $limit=self::recordLimit($full);
        if (!is_file($path) || is_link($path) || filesize($path)>$limit) return null;
        $bytes=@file_get_contents($path,false,null,0,$limit+1);
        return is_string($bytes) && strlen($bytes)<=$limit && is_array($data=json_decode($bytes,true))?$data:null;
    }

    private static function recordLimit(bool $full): int
    {
        // Base64 ceiling plus bounded hash/expiry/count/boolean JSON metadata.
        return $full?4*intdiv(self::FULL_MAX_BYTES+2,3)+512:self::MAX_RECORD_BYTES;
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
