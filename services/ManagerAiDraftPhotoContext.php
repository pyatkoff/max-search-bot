<?php
declare(strict_types=1);

/** Images are capabilities captured by the authorized snapshot, never request URLs. */
final class ManagerAiDraftPhotoContext
{
    public const MAX_IMAGES = 4;
    public const MAX_IMAGE_BYTES = 2097152;
    public const MAX_TOTAL_BYTES = 4194304;
    private function __construct(private string $binding, private int $actor, private string $project,
        private string $channel, private array $sources, private array $messageMedia) {}

    public static function capture(array $context, int $actor, string $project, string $channel, array $metadata, ?array $post): self
    {
        $sources=[];$media=[];
        if ($post !== null && empty($post['unavailable']) && !empty($post['media_unread'])) {
            $sources[]=['kind'=>'origin_post','entry_id'=>(int)$post['entry_id'],
                'version_id'=>$post['version_id'],'media_count'=>(int)($post['media_count']??1)];
        }
        foreach (array_reverse($context['messages']) as $row) {
            if ($row['sender_type']!=='customer') continue;
            $meta=json_decode($metadata[$row['id']]??'',true);
            if (!is_array($meta)) continue;
            $all=is_array($meta['attachments']??null)?$meta['attachments']:[];
            $media[$row['id']]=['total'=>count($all),'linked'=>!empty($meta['linked_message'])];
            $index=0;
            foreach ($all as $item) {
                if (!is_array($item)||!in_array($item['type']??'',['image','video','audio','file'],true)) continue;
                if ($item['type']==='image') $sources[]=['kind'=>'message','message_id'=>$row['id'],'attachment'=>$index];
                ++$index;
            }
        }
        return new self(self::binding($context),$actor,$project,$channel,$sources,$media);
    }

    /** Only explicit generation/context reads call this; readiness never downloads. */
    public function read(array $context, ?callable $opener=null): ?array
    {
        if ($this->actor<=0 || $this->project==='' || !hash_equals($this->binding,self::binding($context))) return null;
        // A missing photo transport must never disable the canonical text path.
        if (!in_array($this->channel,['max','telegram'],true)) return ['context'=>$context,'images'=>[]];
        $images=[];$evidence=[];$read=[];$bytes=0;$started=microtime(true);
        foreach ($this->sources as $position=>$source) {
            $row=$source;unset($row['media_count']);$row['status']='unavailable';
            if ($position>=self::MAX_IMAGES || microtime(true)-$started>=18) {
                $row['status']='omitted';$evidence[]=$row;continue;
            }
            $file=null;
            try {
                $file=$opener!==null?$opener($source):$this->open($source);
                $image=self::image($file);
                if ($image!==null && $bytes+strlen($image['bytes'])<=self::MAX_TOTAL_BYTES) {
                    $bytes+=strlen($image['bytes']);
                    $images[]=['type'=>'input_image','image_url'=>'data:'.$image['mime'].';base64,'.base64_encode($image['bytes']),'detail'=>'high'];
                    $row['status']='attached';$row['image_index']=count($images);
                    if ($source['kind']==='message') $read[$source['message_id']]=($read[$source['message_id']]??0)+1;
                    elseif (($source['media_count']??1)===1 && ($context['origin_posts']['current']['entry_id']??null)===$source['entry_id']
                        && ($context['origin_posts']['current']['version_id']??null)===$source['version_id']) $context['origin_posts']['current']['media_unread']=false;
                }
            } catch (Throwable $ignored) { /* Keep a precise unread source, never provider details. */ }
            finally { if (is_resource($file['stream']??null)) fclose($file['stream']); }
            $evidence[]=$row;
        }
        foreach ($context['messages'] as &$row) {
            $state=$this->messageMedia[$row['id']]??null;
            if ($state!==null && !$state['linked'] && $state['total']>0 && ($read[$row['id']]??0)===$state['total']) $row['media_unread']=false;
        }
        unset($row);
        $context['media_unread']=in_array(true,array_column($context['messages'],'media_unread'),true);
        if ($evidence) $context['photo_evidence']=$evidence;
        return ['context'=>$context,'images'=>$images];
    }

    private function open(array $source): ?array
    {
        if ($this->actor<=0 || $this->project==='' || !in_array($this->channel,['max','telegram'],true)) return null;
        if ($source['kind']==='origin_post') {
            require_once __DIR__.'/ChannelPostMediaService.php';
            return (new ChannelPostMediaService())->openEntry($this->conversationId(),$this->project,$source['entry_id']);
        }
        if ($this->channel==='max') {
            require_once __DIR__.'/ManagerMaxMediaService.php';
            $attachment=ManagerMaxMediaService::attachment($source['message_id'],$source['attachment'],$this->actor);
            return $attachment===null?null:ManagerMaxMediaService::open($attachment,null,null,false);
        }
        require_once __DIR__.'/ManagerTelegramMediaService.php';
        $attachment=ManagerTelegramMediaService::attachment($source['message_id'],$source['attachment'],$this->actor);
        return $attachment===null?null:ManagerTelegramMediaService::open($attachment,null,self::MAX_IMAGE_BYTES,6);
    }

    private function conversationId(): int { return (int)explode(':',$this->binding,2)[0]; }
    private static function binding(array $context): string
    {
        $rows=[];foreach ($context['messages']??[] as $row) $rows[]=[$row['id']??null,$row['sender_type']??null,$row['text']??null];
        return ($context['conversation_id']??0).':'.hash('sha256',json_encode([$context['conversation_id']??null,$context['as_of_message_id']??null,$rows],JSON_THROW_ON_ERROR));
    }

    private static function image(?array $file): ?array
    {
        if (!is_resource($file['stream']??null)) return null;
        $size=(int)(fstat($file['stream'])['size']??0);
        if ($size<=0 || $size>5*1024*1024) return null;
        rewind($file['stream']);$bytes=stream_get_contents($file['stream'],5*1024*1024+1);
        if (!is_string($bytes)||strlen($bytes)!==$size) return null;
        $info=@getimagesizefromstring($bytes);$mime=$info['mime']??'';
        if (!is_array($info)||!in_array($mime,['image/jpeg','image/png','image/webp'],true)
            ||$info[0]<=0||$info[1]<=0||$info[0]>16000||$info[1]>16000||$info[0]*$info[1]>40000000) return null;
        if (strlen($bytes)>self::MAX_IMAGE_BYTES && function_exists('imagecreatefromstring')) {
            $source=@imagecreatefromstring($bytes);
            if ($source===false) return null;
            $ratio=min(1,2048/max($info[0],$info[1]));
            $target=imagecreatetruecolor(max(1,(int)round($info[0]*$ratio)),max(1,(int)round($info[1]*$ratio)));
            try { imagecopyresampled($target,$source,0,0,0,0,imagesx($target),imagesy($target),$info[0],$info[1]);
                ob_start();imagejpeg($target,null,88);$bytes=ob_get_clean();$mime='image/jpeg';
            } finally { imagedestroy($source);imagedestroy($target); }
        }
        return is_string($bytes)&&strlen($bytes)<=self::MAX_IMAGE_BYTES?['mime'=>$mime,'bytes'=>$bytes]:null;
    }
}
