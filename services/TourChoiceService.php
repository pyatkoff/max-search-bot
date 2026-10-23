<?php

declare(strict_types=1);

/**
 * Private, append-only sent-offer snapshots and customer choices.
 * No transport, AI, routing, schema creation or booking side effects.
 * The sender must verify delivery before recording its native message/chat IDs.
 */
final class TourChoiceService
{
    private const PREPARED = 'tour_offer_prepared';
    private const DELIVERED = 'tour_offer_delivered';
    private const LIKED = 'tour_offer_liked';

    public static function handles(string $payload): bool
    {
        return strpos($payload, 'tour_like_') === 0;
    }

    /** Called by a trusted sender before delivery, never with customer-posted JSON. */
    public static function prepare(PDO $pdo, int $conversationId, array $snapshot): array
    {
        $snapshot = self::normalize($snapshot);
        if ($conversationId <= 0 || $snapshot === null) return self::error('invalid_offer');
        self::begin($pdo);
        try {
            $result = self::prepareInTransaction($pdo,$conversationId,$snapshot);
            if (!$result['ok']) { $pdo->rollBack(); return $result; }
            $pdo->commit();
            return $result;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** Trusted application composition: the caller owns commit/rollback. */
    public static function prepareInTransaction(PDO $pdo,int $conversationId,array $snapshot): array
    {
        if (!$pdo->inTransaction()) throw new LogicException('Offer preparation requires an owning transaction');
        $snapshot=self::normalize($snapshot);
        if ($conversationId<=0 || $snapshot===null) return self::error('invalid_offer');
        $conversation=self::conversation($pdo,$conversationId,true);
        if ($conversation===null) return self::error('unavailable');
        $token=bin2hex(random_bytes(12));
        $payload=['schema'=>1,'snapshot'=>$snapshot,'binding'=>self::binding($conversation),'token_hash'=>hash('sha256',$token)];
        $id=self::append($pdo,$conversationId,self::PREPARED,'system',null,$payload);
        return ['ok'=>true,'offer_id'=>$id,'callback_data'=>'tour_like_'.$id.'_'.$token];
    }

    /** Delivery, one transcript row and the creation receipt commit together. */
    public static function recordDeliveredInTransaction(PDO $pdo,int $offerId,string $nativeMid,string $nativeChatId): array
    {
        if (!$pdo->inTransaction()) throw new LogicException('Offer delivery requires an owning transaction');
        if (!self::nativeId($nativeMid) || !self::nativeId($nativeChatId)) return self::error('invalid_receipt');
        $offer=self::offer($pdo,$offerId);
        if ($offer===null) return self::error('unavailable');
        $conversation=self::conversation($pdo,$offer['conversation_id'],true);
        if ($conversation===null || self::binding($conversation)!==$offer['payload']['binding']) return self::error('unavailable');
        if ($conversation['channel']==='telegram' && $nativeChatId!==$conversation['external_user_id']) return self::error('invalid_receipt');
        $receipt=['offer_id'=>$offerId,'native_mid'=>$nativeMid,'native_chat_id'=>$nativeChatId];
        $previous=self::related($pdo,$offer['conversation_id'],self::DELIVERED,$offerId);
        if ($previous!==null) return $previous['payload']===$receipt?['ok'=>true,'changed'=>false]:self::error('receipt_mismatch');
        self::append($pdo,$offer['conversation_id'],self::DELIVERED,'system',null,$receipt);
        return ['ok'=>true,'changed'=>true];
    }

    /** An unconfirmed/failed send must never call this method. */
    public static function recordDelivered(PDO $pdo, int $offerId, string $nativeMid, string $nativeChatId): array
    {
        if (!self::nativeId($nativeMid) || !self::nativeId($nativeChatId)) return self::error('invalid_receipt');
        self::begin($pdo);
        try {
            $result=self::recordDeliveredInTransaction($pdo,$offerId,$nativeMid,$nativeChatId);
            if (!$result['ok'] || !$result['changed']) $pdo->rollBack(); else $pdo->commit();
            return $result;
        } catch (Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
    }

    /** Accept only the normalized callback plus the original native envelope. */
    public static function like(PDO $pdo, array $incoming): array
    {
        $payload = $incoming['callback_data'] ?? null;
        if (($incoming['type'] ?? '') !== 'callback' || !is_string($payload)
            || !preg_match('/\Atour_like_([1-9][0-9]{0,18})_([a-f0-9]{24})\z/D', $payload, $match)
            || (string)(int)$match[1] !== $match[1]) return self::error('invalid_callback');
        $offerId = (int)$match[1];
        $offer = self::offer($pdo, $offerId);
        $native = self::nativeCallback($incoming);
        if ($offer === null || $native === null || !hash_equals($offer['payload']['token_hash'], hash('sha256', $match[2]))) return self::error('unavailable');
        self::begin($pdo);
        try {
            // Every mutation locks the owning conversation first. This also
            // serializes duplicate callbacks without a second idempotency store.
            $conversation = self::conversation($pdo, $offer['conversation_id'], true);
            $binding = $offer['payload']['binding'];
            $user = is_array($incoming['user'] ?? null) ? $incoming['user'] : [];
            if ($conversation === null || self::binding($conversation) !== $binding
                || ($incoming['project_key'] ?? null) !== $binding['project_key']
                || ($incoming['platform'] ?? null) !== $binding['channel']
                || (string)($user['external_user_id'] ?? '') !== $binding['external_user_id']
                || (string)($user['chat_id'] ?? '') !== $binding['external_chat_id']) { $pdo->rollBack(); return self::error('unavailable'); }
            $delivery = self::related($pdo, $offer['conversation_id'], self::DELIVERED, $offerId);
            if ($delivery === null || $delivery['payload'] !== ['offer_id'=>$offerId, 'native_mid'=>$native['mid'], 'native_chat_id'=>$native['chat']]) { $pdo->rollBack(); return self::error('unavailable'); }
            $previous = self::related($pdo, $offer['conversation_id'], self::LIKED, $offerId);
            if ($previous !== null) {
                $pdo->rollBack();
                return ['ok'=>true, 'changed'=>false, 'conversation_id'=>$offer['conversation_id'], 'offer'=>self::projection($offer, $delivery, $previous)];
            }
            $id = self::append($pdo, $offer['conversation_id'], self::LIKED, 'customer', $binding['external_user_id'], ['offer_id'=>$offerId]);
            $liked = ['id'=>$id, 'created_at'=>gmdate('Y-m-d H:i:s')];
            $pdo->commit();
            return ['ok'=>true, 'changed'=>true, 'conversation_id'=>$offer['conversation_id'], 'offer'=>self::projection($offer, $delivery, $liked)];
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** Caller must first pass existing Manager conversation visibility checks.
     * Recent explicit choices take precedence over newer unliked offers.
     */
    public static function forConversation(PDO $pdo, int $conversationId, int $limit = 20): array
    {
        $out=self::nativeForConversation($pdo,$conversationId,$limit);
        $inquiries=self::webInquiries($pdo,$conversationId,$limit);
        if ($inquiries) $out['web_inquiries']=$inquiries;
        return $out;
    }

    /** Read-only aggregate for an already-authorized immutable publication.
     * Native records remain authoritative; public clicks never enter this path.
     */
    public static function selectionActivity(PDO $pdo,int $conversation,string $publication,string $since): array
    {
        $q=$pdo->prepare('SELECT offer_id FROM manager_tour_selection_cards WHERE publication_id=? ORDER BY card_index LIMIT 20');$q->execute([$publication]);
        $ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        $native=self::nativeForConversation($pdo,$conversation,20);$liked=0;
        foreach ($native['offers'] as $offer) if (in_array($offer['offer_id'],$ids,true) && is_string($offer['liked_at']) && $offer['liked_at']>=$since) $liked++;
        $q=$pdo->prepare('SELECT project_key FROM conversations WHERE id=?');$q->execute([$conversation]);$project=$q->fetchColumn();
        $json=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.publication_id'))":"JSON_EXTRACT(e.payload_json,'$.publication_id')";
        // Fixed project/window and row bound, no foreign identity or quote text.
        $q=$pdo->prepare('SELECT CASE WHEN JSON_VALID(e.payload_json) THEN '.$json.' ELSE NULL END AS publication_id,e.created_at FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id WHERE e.event_type=? AND c.project_key=? AND e.created_at>=? ORDER BY e.created_at DESC,e.id DESC LIMIT 1001');
        $q->execute(['tour_web_inquiry',$project,$since]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$limited=count($rows)>1000;$inquiries=0;
        foreach (array_slice($rows,0,1000) as $row) if ($row['publication_id']===$publication) $inquiries++;
        return ['liked'=>$liked,'likes_limited'=>$native['limited'],'inquiries'=>$inquiries,'inquiries_limited'=>$limited];
    }

    private static function nativeForConversation(PDO $pdo,int $conversationId,int $limit): array
    {
        if ($conversationId <= 0) return ['offers'=>[], 'limited'=>false];
        $limit = max(1, min(20, $limit));
        $q = $pdo->prepare('SELECT id,payload_json,created_at FROM conversation_events WHERE conversation_id=? AND event_type=? ORDER BY id DESC LIMIT '.($limit + 1));
        $q->execute([$conversationId, self::LIKED]);
        $likeRows = $q->fetchAll(PDO::FETCH_ASSOC);
        $likes = [];
        foreach ($likeRows as $row) {
            $p = self::decode($row['payload_json']);
            if (is_int($p['offer_id'] ?? null) && $p['offer_id'] > 0 && !isset($likes[$p['offer_id']])) $likes[$p['offer_id']] = $row;
        }
        $q->execute([$conversationId, self::DELIVERED]);
        $deliveryRows = $q->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_keys($likes);
        foreach ($deliveryRows as $row) {
            $p = self::decode($row['payload_json']);
            if (is_int($p['offer_id'] ?? null) && $p['offer_id'] > 0 && !in_array($p['offer_id'], $ids, true)) $ids[] = $p['offer_id'];
        }
        $limited = count($ids) > $limit || count($likeRows) > $limit || count($deliveryRows) > $limit;
        $ids = array_slice($ids, 0, $limit);
        if ($ids === []) return ['offers'=>[], 'limited'=>$limited];
        $slots = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare('SELECT id,conversation_id,payload_json,created_at FROM conversation_events WHERE conversation_id=? AND event_type=? AND id IN ('.$slots.')');
        $q->execute(array_merge([$conversationId, self::PREPARED], $ids));
        $offers = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) { $offer = self::decodedOffer($row); if ($offer !== null) $offers[$offer['id']] = $offer; }
        $q = $pdo->prepare("SELECT id,payload_json,created_at FROM conversation_events WHERE conversation_id=? AND event_type=? AND CAST(JSON_EXTRACT(payload_json,'$.offer_id') AS CHAR) IN (".$slots.') ORDER BY id ASC LIMIT 20');
        $q->execute(array_merge([$conversationId, self::DELIVERED], array_map('strval', $ids)));
        $deliveries = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['payload'] = self::decode($row['payload_json']);
            $id = $row['payload']['offer_id'] ?? null;
            if (is_int($id) && !isset($deliveries[$id])) $deliveries[$id] = $row;
        }
        $out = [];
        foreach ($ids as $id) if (isset($offers[$id], $deliveries[$id])) $out[] = self::projection($offers[$id], $deliveries[$id], $likes[$id] ?? null);
        return ['offers'=>$out, 'limited'=>$limited];
    }

    /** Trusted native-entry composition; no fabricated callback/delivery event. */
    public static function webInquiryInTransaction(PDO $pdo,int $conversation,string $externalUser,string $entryKey,array $entry): array
    {
        if (!$pdo->inTransaction()) throw new LogicException('Web inquiry requires native-entry transaction');
        $offer=$entry['public_offer']??null;
        $allowed=['index','hotel','departure','date_from','nights','adults','children','room','meal','amount','currency','rationale','compromise','destination','flights','inclusions','constraints','observed_at','price_verified'];
        if (!is_array($offer) || array_diff(array_keys($offer),$allowed) || !is_string($offer['hotel']??null) || $offer['hotel']==='' || !preg_match('/\A[a-f0-9]{64}\z/D',$entryKey)) return self::error('invalid_offer');
        $json=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.entry_key'))":"JSON_EXTRACT(payload_json,'$.entry_key')";
        $q=$pdo->prepare("SELECT id FROM conversation_events WHERE conversation_id=? AND event_type='tour_web_inquiry' AND ".$json.'=? LIMIT 1');
        $q->execute([$conversation,$entryKey]);
        if ($q->fetchColumn()) return ['ok'=>true,'changed'=>false,'conversation_id'=>$conversation];
        self::append($pdo,$conversation,'tour_web_inquiry','customer',$externalUser,['schema'=>1,'entry_key'=>$entryKey,'publication_id'=>$entry['publication_id'],'link_version'=>(int)$entry['version'],'variant'=>(int)$entry['card']+1,'public_offer'=>$offer]);
        return ['ok'=>true,'changed'=>true,'conversation_id'=>$conversation];
    }

    private static function webInquiries(PDO $pdo,int $conversation,int $limit): array
    {
        if ($conversation<=0) return [];
        $q=$pdo->prepare("SELECT payload_json,created_at FROM conversation_events WHERE conversation_id=? AND event_type='tour_web_inquiry' ORDER BY id DESC LIMIT ".max(1,min(20,$limit)));
        $q->execute([$conversation]);$out=[];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $p=self::decode($row['payload_json']);
            if (($p['schema']??null)!==1 || !is_array($p['public_offer']??null)) continue;
            $out[]=['public_offer'=>$p['public_offer'],'link_version'=>(int)$p['link_version'],'variant'=>(int)$p['variant'],'created_at'=>$row['created_at']];
        }
        return $out;
    }

    public static function normalize(array $input): ?array
    {
        $out = [];
        foreach (['hotel'=>191, 'departure'=>160, 'room'=>300, 'meal'=>160] as $key=>$max) {
            $value = self::text($input[$key] ?? null, $max);
            if ($value === null || $value === '') return null;
            $out[$key] = $value;
        }
        foreach (['date_from', 'date_to'] as $key) {
            $value = $input[$key] ?? null;
            if (!is_string($value) || !preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $value, $date)
                || !checkdate((int)$date[2], (int)$date[3], (int)$date[1])) return null;
            $out[$key] = $value;
        }
        if ($out['date_to'] <= $out['date_from']) return null;
        if (array_key_exists('date_to_basis',$input)) {
            if ($input['date_to_basis']!=='lodging_estimate') return null;
            $out['date_to_basis']='lodging_estimate';
        }
        foreach (['nights'=>60, 'adults'=>20] as $key=>$max) { if (!is_int($input[$key] ?? null) || $input[$key] < 1 || $input[$key] > $max) return null; $out[$key] = $input[$key]; }
        $ages = $input['child_ages'] ?? [];
        if (!is_array($ages) || count($ages) > 12 || array_values($ages) !== $ages) return null;
        foreach ($ages as $age) if (!is_int($age) || $age < 0 || $age > 17) return null;
        $out['child_ages'] = $ages;
        $amount = $input['amount'] ?? null;
        if (!is_string($amount) || !preg_match('/\A(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?\z/D', $amount) || (float)$amount <= 0) return null;
        if (!in_array($input['currency'] ?? null, ['RUB','EUR','USD'], true) || ($input['price_basis'] ?? null) !== 'party'
            || !is_bool($input['is_demo'] ?? null) || (array_key_exists('price_verified', $input) && $input['price_verified'] !== false)) return null;
        $out += ['amount'=>$amount, 'currency'=>$input['currency'], 'price_basis'=>'party', 'is_demo'=>$input['is_demo'], 'price_verified'=>false];
        $flights = $input['flights'] ?? [];
        if (!is_array($flights) || count($flights) > 6 || array_values($flights) !== $flights) return null;
        $out['flights'] = [];
        foreach ($flights as $flight) { $text = self::text($flight, 300); if ($text === null || $text === '') return null; $out['flights'][] = $text; }
        foreach (['rationale','compromise'] as $key) { $text = self::text($input[$key] ?? '', 600); if ($text === null) return null; $out[$key] = $text; }
        if (array_key_exists('quoted_details',$input)) {
            require_once __DIR__.'/ManagerTourSelectionDraft.php';
            $details=$input['quoted_details'];
            $keys=['destination','source_label','source_url','observed_at','flights','inclusions','constraints','photos'];
            if (!is_array($details) || array_diff(array_keys($details),$keys)) return null;
            $draft=ManagerTourSelectionDraft::normalize(['title'=>'','intro'=>'','offers'=>[
                ['id'=>str_repeat('0',32),'selected'=>true]+$details,
            ]]);
            if ($draft===null) return null;
            $out['quoted_details']=array_intersect_key($draft['offers'][0],array_flip($keys));
        }
        return $out;
    }

    private static function projection(array $offer, array $delivery, ?array $like): array
    {
        return ['offer_id'=>$offer['id'], 'snapshot'=>$offer['payload']['snapshot'], 'delivered_at'=>$delivery['created_at'], 'liked_at'=>$like['created_at'] ?? null];
    }

    private static function begin(PDO $pdo): void
    {
        if ($pdo->inTransaction()) throw new LogicException('Tour choice requires its own transaction');
        $pdo->beginTransaction();
    }

    private static function conversation(PDO $pdo, int $id, bool $lock): ?array
    {
        $suffix = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $q = $pdo->prepare('SELECT c.id,c.project_key,c.channel,c.external_chat_id,c.status,cc.external_user_id FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.id=?'.$suffix);
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !in_array($row['status'], ['ai','waiting_manager','manager'], true) || !in_array($row['channel'], ['max','telegram'], true)) return null;
        foreach (['project_key','external_chat_id','external_user_id'] as $key) if (!self::nativeId((string)($row[$key] ?? ''))) return null;
        return $row;
    }

    private static function binding(array $conversation): array
    {
        return ['project_key'=>(string)$conversation['project_key'], 'channel'=>(string)$conversation['channel'], 'external_chat_id'=>(string)$conversation['external_chat_id'], 'external_user_id'=>(string)$conversation['external_user_id']];
    }

    private static function offer(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) return null;
        $q = $pdo->prepare('SELECT id,conversation_id,payload_json,created_at FROM conversation_events WHERE id=? AND event_type=?');
        $q->execute([$id, self::PREPARED]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::decodedOffer($row) : null;
    }

    private static function decodedOffer(array $row): ?array
    {
        $p = self::decode($row['payload_json']);
        if (($p['schema'] ?? null) !== 1 || !is_array($p['snapshot'] ?? null) || self::normalize($p['snapshot']) !== $p['snapshot']
            || !is_array($p['binding'] ?? null) || array_keys($p['binding']) !== ['project_key','channel','external_chat_id','external_user_id']
            || !is_string($p['token_hash'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $p['token_hash'])) return null;
        return ['id'=>(int)$row['id'], 'conversation_id'=>(int)$row['conversation_id'], 'payload'=>$p, 'created_at'=>(string)$row['created_at']];
    }

    private static function related(PDO $pdo, int $conversationId, string $type, int $offerId): ?array
    {
        $q = $pdo->prepare("SELECT id,payload_json,created_at FROM conversation_events WHERE conversation_id=? AND event_type=? AND CAST(JSON_EXTRACT(payload_json,'$.offer_id') AS CHAR)=? ORDER BY id ASC LIMIT 1");
        $q->execute([$conversationId, $type, (string)$offerId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $row['payload'] = self::decode($row['payload_json']);
        return $row;
    }

    private static function append(PDO $pdo, int $conversationId, string $type, string $actorType, ?string $actorId, array $payload): int
    {
        $q = $pdo->prepare('INSERT INTO conversation_events (conversation_id,event_type,actor_type,actor_id,payload_json,created_at) VALUES (?,?,?,?,?,?)');
        $q->execute([$conversationId, $type, $actorType, $actorId, json_encode($payload, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), gmdate('Y-m-d H:i:s')]);
        return (int)$pdo->lastInsertId();
    }

    private static function nativeCallback(array $incoming): ?array
    {
        $raw = $incoming['raw'] ?? null;
        $user = (string)($incoming['user']['external_user_id'] ?? '');
        if (!is_array($raw)) return null;
        if (($incoming['platform'] ?? '') === 'telegram') {
            $q = $raw['callback_query'] ?? [];
            $message = $q['message'] ?? [];
            if (($q['data'] ?? null) !== ($incoming['callback_data'] ?? null) || (string)($q['from']['id'] ?? '') !== $user
                || ($message['chat']['type'] ?? '') !== 'private' || (string)($message['chat']['id'] ?? '') !== $user) return null;
            $mid = (string)($message['message_id'] ?? ''); $chat = (string)$message['chat']['id'];
        } elseif (($incoming['platform'] ?? '') === 'max') {
            $q = $raw['callback'] ?? []; $message = $raw['message'] ?? [];
            if (($q['payload'] ?? null) !== ($incoming['callback_data'] ?? null) || (string)($q['user']['user_id'] ?? $q['user']['id'] ?? '') !== $user
                || ($message['recipient']['chat_type'] ?? '') !== 'dialog') return null;
            $mid = (string)($message['body']['mid'] ?? ''); $chat = (string)($message['recipient']['chat_id'] ?? '');
        } else return null;
        return self::nativeId($mid) && self::nativeId($chat) ? ['mid'=>$mid, 'chat'=>$chat] : null;
    }

    private static function nativeId(string $value): bool { return $value !== '' && strlen($value) <= 191 && preg_match('/[\x00-\x20\x7f]/', $value) === 0; }
    private static function text($value, int $max): ?string { if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/u', $value)) return null; $value = trim($value); return preg_match_all('/./us', $value) <= $max ? $value : null; }
    private static function decode($value): ?array { if (!is_string($value) || strlen($value) > 65536) return null; $data = json_decode($value, true); return is_array($data) ? $data : null; }
    private static function error(string $code): array { return ['ok'=>false, 'error'=>$code]; }
}
