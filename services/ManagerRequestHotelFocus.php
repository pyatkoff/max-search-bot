<?php
declare(strict_types=1);

require_once __DIR__.'/ManagerHotelReferenceContext.php';

/** An explicit profile confirmation, never an inferred latest-hotel fallback. */
final class ManagerRequestHotelFocus
{
    /** Suggestions only; a newer inquiry always supersedes an older candidate. */
    public static function candidates(PDO $pdo,array $conversation,array $ctx,int $boundary): array
    {
        $id=self::latestInquiry($ctx);
        if($id===null||$id<$boundary)return [];
        $r=self::resolve($pdo,$conversation,$ctx,$boundary,$id);
        return isset($r['candidate'])?[$r['candidate']]:[];
    }

    public static function confirm(PDO $pdo,array $conversation,array $ctx,int $boundary,mixed $command,string $basis,string $profileHash): ?array
    {
        if($command===null)return null;
        if(!is_array($command)||!self::keys($command,['message_id','revision'])||!is_int($command['message_id'])
            ||$command['message_id']<=0||!self::hex($command['revision']))throw new InvalidArgumentException('invalid_hotel_focus');
        $r=self::resolve($pdo,$conversation,$ctx,$boundary,$command['message_id']);
        if(!isset($r['candidate'])||!hash_equals($r['candidate']['revision'],$command['revision']))throw new InvalidArgumentException('hotel_focus_changed');
        return $r['candidate']+['confirmed_context_sha256'=>$basis,'confirmed_profile_sha256'=>$profileHash];
    }

    /** Public view and optional trusted reference are deliberately separate. */
    public static function inspect(PDO $pdo,array $conversation,array $ctx,int $boundary,?array $stored,string $basis,string $profileHash,string $profileStatus): array
    {
        if($stored===null)return ['view'=>null,'reference'=>null];
        $status='needs_review';$reason='context_changed';$reference=null;
        if($stored['message_id']<$boundary){$status='before_boundary';$reason='before_boundary';}
        else {
            $r=self::resolve($pdo,$conversation,$ctx,$boundary,$stored['message_id']);
            if(!isset($r['candidate'])){$status='unavailable';$reason=$r['reason']??'selector_unavailable';}
            elseif(!hash_equals($stored['knowledge_revision'],$r['candidate']['knowledge_revision'])){
                $status='knowledge_changed';$reason='knowledge_changed';
                foreach(['source_message_sha256','source_sha256','publication_sha256','event_sha256'] as $key) {
                    if(!hash_equals($stored[$key],$r['candidate'][$key])){$status='source_changed';$reason='source_changed';break;}
                }
            } elseif(!hash_equals($stored['revision'],$r['candidate']['revision'])){$status='source_changed';$reason='source_changed';}
            elseif($profileStatus==='fresh'&&hash_equals($stored['confirmed_context_sha256'],$basis)
                &&hash_equals($stored['confirmed_profile_sha256'],$profileHash)){
                $status='confirmed';$reason='manager_confirmed';$reference=$r['reference'];
            }
        }
        return ['view'=>$stored+['status'=>$status,'reason'=>$reason],'reference'=>$reference];
    }

    public static function validStored(array $v): bool
    {
        if(!self::keys($v,['message_id','revision','hotel_ref','selected_hotel','selected_room','public_offer','knowledge_revision',
            'source_message_sha256','source_sha256','publication_sha256','event_id','event_sha256','confirmed_context_sha256','confirmed_profile_sha256'])
            ||!is_int($v['message_id'])||$v['message_id']<=0||!is_int($v['event_id'])||$v['event_id']<=0
            ||!is_array($v['hotel_ref'])||!self::keys($v['hotel_ref'],['catalog','id'])||$v['hotel_ref']['catalog']!=='anytour'
            ||!is_int($v['hotel_ref']['id'])||$v['hotel_ref']['id']<=0
            ||!is_string($v['selected_hotel'])||$v['selected_hotel']===''||mb_strlen($v['selected_hotel'])>191
            ||!is_string($v['selected_room'])||$v['selected_room']===''||mb_strlen($v['selected_room'])>300
            ||!is_array($v['public_offer'])||array_is_list($v['public_offer']))return false;
        foreach(['revision','knowledge_revision','source_message_sha256','source_sha256','publication_sha256','event_sha256',
            'confirmed_context_sha256','confirmed_profile_sha256'] as $key)if(!self::hex($v[$key]))return false;
        try{
            $candidate=$v;unset($candidate['revision'],$candidate['confirmed_context_sha256'],$candidate['confirmed_profile_sha256']);
            return hash_equals($v['revision'],self::hash($candidate))
                &&strlen(json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=24000;
        }
        catch(Throwable $ignored){return false;}
    }

    public static function profileHash(string $trip,int $boundary,string $label,array $fields): string
    {return self::hash([$trip,$boundary,$label,$fields]);}

    public static function instructions(): string
    {
        return "\nhotel_focus со status=confirmed — явно подтверждённая менеджером связь сохранённого предложения с этой поездкой, а не новая цена, наличие или согласие туриста на тариф. hotel_reference может относиться к этому более раннему проверенному обращению: используй его только в пределах подтверждённого hotel_focus. public_offer сохраняет исходные даты, состав, номер и питание; фактический запрос и party не переписывай по карточке. Сначала ответь на последний вопрос. По обязательному условию объясни «подходит», «не подходит» или «нужно уточнить» только в пределах фактов и их scope; по предпочтениям объясни подтверждённую пользу и существенный компромисс. Неизвестные размещение ребёнка, путь к пляжу, питание и условия на даты оставь конкретными checks. Противоречие источников не разрешается мнением туриста или менеджера; ограничения и версии источников сохраняются. При ином status прежний focus не подтверждён для текущего ответа, его факты не восстанавливай по памяти.\n";
    }

    private static function latestInquiry(array $ctx): ?int
    {
        $latest=null;
        foreach($ctx['window_ids'] as $id)if(!empty($ctx['sources'][$id]['hotel_inquiry'])&&($latest===null||$id>$latest))$latest=$id;
        return $latest;
    }

    private static function resolve(PDO $pdo,array $conversation,array $ctx,int $boundary,int $id): array
    {
        try {
            if($id<$boundary)return ['reason'=>'before_boundary'];
            if(!in_array($id,$ctx['window_ids'],true))return ['reason'=>'selector_not_retained'];
            if(self::latestInquiry($ctx)!==$id)return ['reason'=>'newer_hotel_inquiry'];
            $source=$ctx['sources'][$id]??null;
            if(!$source||empty($source['hotel_readable']))return ['reason'=>'selector_unavailable'];
            $messages=[];
            foreach($ctx['window_ids'] as $messageId){
                $s=$ctx['sources'][$messageId];
                if($messageId>$id&&$s['sender_type']==='customer'&&(!empty($s['unavailable'])||!empty($s['media_unread'])))return ['reason'=>'later_activity_unavailable'];
                if($messageId<=$id)$messages[]=['id'=>$messageId,'sender_type'=>$s['sender_type'],'text_truncated'=>empty($s['hotel_readable'])&&$messageId===$id];
            }
            $reference=ManagerHotelReferenceContext::capture($pdo,$conversation,
                ['conversation_id'=>(int)$conversation['id'],'as_of_message_id'=>$id,'messages'=>$messages]);
            if(($reference['status']??'')!=='available')return ['reason'=>$reference['reason']??'knowledge_unavailable'];
            $p=$reference['selector_provenance'];
            if($p['message_id']!==$id)return ['reason'=>'selector_unavailable'];
            $q=$pdo->prepare('SELECT CASE WHEN LENGTH(payload_json)<=65536 THEN payload_json ELSE NULL END AS payload_json,created_at FROM conversation_events WHERE id=? AND conversation_id=? AND event_type=\'tour_web_inquiry\'');
            $q->execute([$p['event_id'],(int)$conversation['id']]);$event=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$event||!is_string($event['payload_json']))return ['reason'=>'selector_event_unavailable'];
            $payload=json_decode($event['payload_json'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($payload['public_offer']??null))return ['reason'=>'selector_event_unavailable'];
            $candidate=['message_id'=>$id,'hotel_ref'=>$reference['hotel_ref'],'selected_hotel'=>$reference['selected_hotel'],
                'selected_room'=>$reference['selected_room'],'public_offer'=>$payload['public_offer'],'knowledge_revision'=>$reference['knowledge_revision'],
                'source_message_sha256'=>$source['sha256'],'source_sha256'=>$p['source_sha256'],'publication_sha256'=>$p['publication_sha256'],
                'event_id'=>$p['event_id'],'event_sha256'=>self::hash([$event['payload_json'],$event['created_at']])];
            $candidate=['message_id'=>$id,'revision'=>self::hash($candidate)]+$candidate;
            return ['candidate'=>$candidate,'reference'=>$reference];
        }catch(Throwable $ignored){return ['reason'=>'selector_unavailable'];}
    }

    private static function hash(array $v): string
    {
        $sort=static function(mixed $v)use(&$sort):mixed{if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=$sort($x);return $v;};
        return hash('sha256',json_encode($sort($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    private static function keys(array $v,array $keys): bool{return count($v)===count($keys)&&!array_diff(array_keys($v),$keys);}
    private static function hex(mixed $v): bool{return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;}
}
