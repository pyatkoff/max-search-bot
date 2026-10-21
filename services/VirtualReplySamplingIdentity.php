<?php

declare(strict_types=1);

/** Private whole-dialogue aliases, never a claim to semantic trip segmentation. */
final class VirtualReplySamplingIdentity
{
    public static function conversation(int $conversation, ?int $customer, string $channel, array $privatePeers, string $text): array
    {
        $keys=[self::key('conversation',(string)$conversation)];
        if ($customer!==null && $customer>0) $keys[]=self::key('customer',(string)$customer);
        $peers=array_values(array_unique(array_filter($privatePeers,self::positivePeer(...))));
        $boundPeer=$channel==='telegram' && count($peers)===1
            && count(array_filter($privatePeers,self::positivePeer(...)))===count($privatePeers);
        if ($boundPeer) $keys[]=self::key('telegram_peer',$peers[0]);
        return self::value($keys,$customer!==null && $customer>0
            && ($channel==='telegram'?$boundPeer:in_array($channel,['max','website'],true)),$text);
    }

    public static function archive(string $peer, string $text): array
    {
        // A negative/group peer stays one conservative dialogue but is not a
        // reliably linked private-person cohort eligible for unseen controls.
        return self::value([self::key(self::positivePeer($peer)?'telegram_peer':'telegram_chat',$peer)],self::positivePeer($peer),$text);
    }

    public static function kind(string $text): string
    {
        $value=mb_strtolower(trim($text),'UTF-8');
        $value=preg_replace('/[\s\p{P}\p{So}\p{Sk}\x{FE0F}\x{200D}]+/u',' ',$value);
        return preg_match('/\A(?:здравствуйте|здравствуй|добрый день|добрый вечер|доброе утро|привет|спасибо|большое спасибо|спасибо большое|понятно спасибо|хорошо спасибо|понятно|хорошо|ок|okay|ok|hello|hi|thanks|thank you)\z/u',trim((string)$value))===1?'social':'substantive';
    }

    private static function positivePeer($value): bool
    {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/D',$value)===1;
    }

    private static function key(string $kind,string $value): string
    {
        return hash('sha256','virtual_reply_dialogue_v1|anytour|'.$kind.'|'.$value);
    }

    private static function value(array $keys,bool $complete,string $text): array
    {
        sort($keys,SORT_STRING);
        return ['identity_keys'=>$keys,'identity_complete'=>$complete,'kind'=>self::kind($text)];
    }
}
