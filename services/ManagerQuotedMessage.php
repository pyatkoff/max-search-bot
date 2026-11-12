<?php
declare(strict_types=1);

/** Compare a captured quotation with the saved text, never with a live provider. */
final class ManagerQuotedMessage
{
    public static function compare(array $linked, ?string $original): array
    {
        $type=$linked['type']??null;
        if(!in_array($type,['reply','forward'],true))return ['state'=>'unavailable','snapshot'=>null];
        $text=is_string($linked['text']??null)?trim($linked['text']):'';
        $sender=is_string($linked['sender_name']??null)?trim($linked['sender_name']):'';
        $snapshot=['type'=>$type,'text'=>mb_substr($text,0,500,'UTF-8'),
            'sender_name'=>mb_substr($sender,0,160,'UTF-8'),
            // Both native adapters historically retained at most500 characters.
            // Exactly500 cannot prove that the original quotation was complete.
            'may_be_truncated'=>mb_strlen($text,'UTF-8')>=500];
        $state='unavailable';
        if($original!==null){
            $original=trim($original);
            if($text==='')$state='not_comparable';
            elseif($snapshot['may_be_truncated']&&hash_equals($snapshot['text'],mb_substr($original,0,500,'UTF-8')))$state='excerpt_matches';
            elseif(!$snapshot['may_be_truncated']&&hash_equals($text,$original))$state='text_matches';
            else $state='text_differs';
        }
        return ['state'=>$state,'snapshot'=>$snapshot];
    }
}
