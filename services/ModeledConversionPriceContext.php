<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionDealValue.php';

/** Optional bounded native-price admission, inside the existing intake snapshot. No native writes or external calls. */
final class ModeledConversionPriceContext
{
    public const VERSION='entry_price_context_v1';
    public static function capture(PDO $pdo,array $meta,array $payload,int $now):array
    {
        $none=['context'=>null,'rows'=>0,'bytes'=>0];
        if(($payload['event_type']??null)!=='channel_post_entry'||($payload['actor_type']??null)!=='customer')return $none;
        try{
            $data=json_decode($payload['payload_json']??'',true,16,JSON_THROW_ON_ERROR);
            foreach(['entry_id','publication_id','version_id']as$k)if(!is_int($data[$k]??null)||$data[$k]<=0)return $none;
            $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $text="JSON_EXTRACT(v.snapshot_json,'$.text')";$pub="JSON_EXTRACT(v.snapshot_json,'$.published_at')";
            if($mysql){$text='JSON_UNQUOTE('.$text.')';$pub='JSON_UNQUOTE('.$pub.')';}
            $len=$mysql?'OCTET_LENGTH':'LENGTH';
            $sql='SELECT v.content_hash,CASE WHEN JSON_VALID(v.snapshot_json) THEN CASE WHEN '.$len.'('.$text.')<=8192 THEN '.$text.' ELSE NULL END ELSE NULL END AS post_text,'
                .'CASE WHEN JSON_VALID(v.snapshot_json) THEN CASE WHEN '.$len.'('.$pub.')<=191 THEN '.$pub.' ELSE NULL END ELSE NULL END AS published_at'
                .' FROM post_entries e JOIN conversations c ON c.id=e.conversation_id JOIN post_publications pp ON pp.id=e.publication_id'
                .' JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id'
                .' JOIN post_versions v ON v.id=e.version_id AND v.publication_id=pp.id'
                .' WHERE e.id=? AND e.conversation_id=? AND pp.id=? AND v.id=? AND c.project_key=? AND p.project_key=? AND pc.platform=? AND c.channel=pc.platform LIMIT 1';
            $q=$pdo->prepare($sql);$q->execute([$data['entry_id'],$meta['conversation_id'],$data['publication_id'],$data['version_id'],'anytour','anytour',$meta['platform']]);
            $row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$row)return ['context'=>null,'rows'=>1,'bytes'=>0];
            $bytes=strlen(json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $out=['context'=>null,'rows'=>1,'bytes'=>$bytes];
            $text=$row['post_text']??null;$published=$row['published_at']??null;
            if(!is_string($text)||!is_string($published))return $out;
            $at=ctype_digit($published)?(strlen($published)>11?(int)floor((int)$published/1000):(int)$published):strtotime($published);
            if(!is_int($at)||$at<946684800||$at>$now||$now-$at>14*86400)return $out;
            $range=ModeledConversionDealValue::publicationRange($text);if($range===null)return $out;
            $out['context']=['version'=>self::VERSION,'captured_at_unix'=>$now,'published_at_unix'=>$at,
                'publication_id'=>(string)$data['publication_id'],'version_id'=>(string)$data['version_id'],
                'content_sha256'=>$row['content_hash'],'range'=>$range,'price_verified'=>false];
            return $out;
        }catch(Throwable $ignored){return $none;}
    }
}
