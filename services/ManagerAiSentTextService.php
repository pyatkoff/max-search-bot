<?php
declare(strict_types=1);

/** Optional private initial text, bound to one actual canonical message INSERT. */
final class ManagerAiSentTextService
{
    public const MAX_BYTES=80000;

    public static function record(PDO $pdo,int $messageId,int $cid,string $senderType,$senderId,string $storedText,array $metadata,string $text): bool
    {
        try{
            $ref=$metadata['ai_draft']??null;$project=$metadata['project_key']??null;
            if($messageId<=0||$cid<=0||$senderType!=='manager'||!ctype_digit((string)$senderId)||(int)$senderId<=0
                ||(string)(int)$senderId!==(string)$senderId||!is_string($project)||$project===''||strlen($project)>256||preg_match('//u',$project)!==1||preg_match_all('/./us',$project)>64||preg_match('/[\x00-\x1F\x7F]/',$project)
                ||!is_array($ref)||count($ref)!==3||!self::hex($ref['draft_id']??null,32)
                ||!is_bool($ref['text_matches_proposal']??null)||!self::hex($ref['sent_text_sha256']??null,64)
                ||!empty($metadata['attachments'])||!self::text($text)||trim($text)!==$text
                ||!hash_equals($ref['sent_text_sha256'],hash('sha256',$text))
                ||htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')!==$storedText)return false;
            $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY':'BLOB';
            // The message ID is obtained immediately after INSERT by its owner.
            // Verify exact historical scope without another authorization or send.
            $q=$pdo->prepare("INSERT INTO manager_ai_sent_texts (message_id,draft_id,conversation_id,manager_id,project_key,text_value,text_sha256)
                SELECT m.id,d.draft_id,m.conversation_id,d.manager_id,?, ?, ? FROM messages m JOIN manager_ai_drafts d
                ON d.draft_id=? AND d.conversation_id=m.conversation_id
                WHERE m.id=? AND m.conversation_id=? AND m.direction='outbound' AND m.sender_type='manager'
                AND m.sender_id=? AND d.manager_id=? AND CAST(d.project_key AS {$binary})=CAST(? AS {$binary})
                AND CAST(m.text AS {$binary})=CAST(? AS {$binary})");
            $q->execute([$project,$text,$ref['sent_text_sha256'],$ref['draft_id'],$messageId,$cid,(string)$senderId,(int)$senderId,$project,$storedText]);
            return $q->rowCount()===1;
        }catch(Throwable $ignored){return false;}
    }

    /** Caller must first authorize the exact historical usage comparison. No backfill. */
    public static function read(PDO $pdo,array $message,array $draft): array
    {
        try{
            $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY':'BLOB';
            $q=$pdo->prepare('SELECT message_id,draft_id,conversation_id,manager_id,project_key,SUBSTR(CAST(text_value AS '.$binary.'),1,'.(self::MAX_BYTES+1).') AS text_value,text_sha256 FROM manager_ai_sent_texts WHERE message_id=?');
            $q->execute([$message['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$row)return ['available'=>false,'reason'=>'not_recorded'];
            $metadata=json_decode($message['metadata_json'],true,16,JSON_THROW_ON_ERROR);$ref=$metadata['ai_draft'];$text=$row['text_value']??null;$hash=$row['text_sha256']??null;
            if((int)$row['message_id']!==(int)$message['id']||$row['draft_id']!==$draft['draft_id']
                ||(int)$row['conversation_id']!==(int)$message['conversation_id']||(int)$row['manager_id']!==(int)$draft['manager_id']
                ||$row['project_key']!==$draft['project_key']||!self::text($text)||trim($text)!==$text||!self::hex($hash,64)
                ||!hash_equals($hash,hash('sha256',$text))||!hash_equals($ref['sent_text_sha256'],$hash))return ['available'=>false,'reason'=>'invalid_evidence'];
            return ['available'=>true,'text'=>$text,'text_sha256'=>$hash];
        }catch(Throwable $ignored){return ['available'=>false,'reason'=>'storage_unavailable'];}
    }

    private static function hex($value,int $length): bool{return is_string($value)&&preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$value)===1;}
    private static function text($value): bool{return is_string($value)&&$value!==''&&strlen($value)<=self::MAX_BYTES
        &&preg_match('//u',$value)===1&&preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)===0&&preg_match_all('/./us',$value)<=20000;}
}
