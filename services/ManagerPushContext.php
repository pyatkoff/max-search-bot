<?php

declare(strict_types=1);
require_once __DIR__.'/Search3TourHandoffSnapshot.php';
require_once __DIR__.'/ConversationRecordClock.php';

/** Read-only display context for notices that have no external message ID. */
final class ManagerPushContext
{
    public static function forNotice(PDO $pdo, int $conversationId, string $notice): ?array
    {
        if (!in_array($notice, ['Новая заявка ждёт менеджера','Обращение из поиска','Обращение по веб-подборке'], true)) return null;
        try {
            // A different latest input is a barrier, never scan past it for a
            // more attractive historical tour or customer phrase.
            $q=$pdo->prepare("SELECT id,SUBSTR(text,1,1000) AS text,CASE WHEN LENGTH(metadata_json)<=32768 THEN metadata_json ELSE NULL END AS metadata_json,created_at FROM messages WHERE conversation_id=? AND direction='inbound' AND sender_type='customer' ORDER BY id DESC LIMIT 1");
            $q->execute([$conversationId]);$message=$q->fetch(PDO::FETCH_ASSOC);
            if (!$message) return null;
            $metadata=json_decode((string)($message['metadata_json']??''),true);
            if (!is_array($metadata)) return null;
            $type=$metadata['type']??'message';
            if ($notice==='Новая заявка ждёт менеджера') {
                $q=$pdo->prepare("SELECT CASE WHEN LENGTH(payload_json)<=32768 THEN payload_json ELSE NULL END FROM conversation_events WHERE conversation_id=? AND event_type='waiting_manager' ORDER BY id DESC LIMIT 1");
                $q->execute([$conversationId]);$waiting=$q->fetchColumn();
                $messageTime=in_array($type,['tour_search_inquiry','tour_web_inquiry'],true)
                    ?self::tourTime((string)$message['created_at']):ConversationRecordClock::read($message['metadata_json']);
                if (!self::near($messageTime,ConversationRecordClock::read($waiting))) return null;
                if (in_array($type,['message','contact'],true)) return ['message'=>$message];
            } elseif ($type!==($notice==='Обращение из поиска'?'tour_search_inquiry':'tour_web_inquiry')) return null;
            if (!in_array($type,['tour_search_inquiry','tour_web_inquiry'],true)) return null;

            $q=$pdo->prepare('SELECT CASE WHEN LENGTH(payload_json)<=32768 THEN payload_json ELSE NULL END AS payload_json,created_at FROM conversation_events WHERE conversation_id=? AND event_type=? ORDER BY id DESC LIMIT 10');
            $q->execute([$conversationId,$type]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $event) {
                $p=json_decode((string)($event['payload_json']??''),true);
                if (!is_array($p)||($p['schema']??null)!==1||!self::near(self::tourTime((string)$event['created_at']),self::tourTime((string)$message['created_at']))) continue;
                if ($type==='tour_search_inquiry') {
                    if (!is_string($metadata['handoff_id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$metadata['handoff_id'])||($p['handoff_id']??null)!==$metadata['handoff_id']) continue;
                    $snapshot=is_array($p['snapshot']??null)?Search3TourHandoffSnapshot::normalize($p['snapshot']):null;
                    if (!$snapshot) return null;
                    $o=$snapshot['observation'];$trip=$o['trip'];
                    return ['tour'=>['origin'=>'search','hotel'=>$o['hotel']['name'],'date'=>$trip['departure'],'nights'=>$trip['nights'],'adults'=>$trip['adults'],'children'=>count($trip['childAges'])]];
                }
                foreach (['publication_id','link_version','variant'] as $key) {
                    if (!isset($metadata[$key])||($p[$key]??null)!==$metadata[$key]) continue 2;
                }
                $offer=$p['public_offer']??null;
                if (!is_array($offer)||!is_string($offer['hotel']??null)||!is_string($offer['date_from']??null)
                    ||!is_int($offer['nights']??null)||!is_int($offer['adults']??null)||!is_int($offer['children']??null)) return null;
                return ['tour'=>['origin'=>'web','hotel'=>$offer['hotel'],'date'=>$offer['date_from'],'nights'=>$offer['nights'],'adults'=>$offer['adults'],'children'=>$offer['children']]];
            }
        } catch (Throwable $ignored) {
            // Optional context cannot block the original notification.
        }
        return null;
    }

    private static function near(?int $earlier, ?int $later): bool
    {
        return $earlier!==null&&$later!==null&&$later>=$earlier&&$later-$earlier<=60;
    }

    private static function tourTime(string $value): ?int
    {
        // These two tour owners explicitly write gmdate, unlike legacy DB
        // default DATETIME values. Ordinary inputs use the recording clock.
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
        return $date&&$date->format('Y-m-d H:i:s')===$value?$date->getTimestamp():null;
    }
}
