<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionService.php';
require_once __DIR__.'/ManagerTourSelectionPublicationDraft.php';
require_once __DIR__.'/TourSelectionPhotoStore.php';
require_once __DIR__.'/ConversationRecorder.php';
require_once __DIR__.'/../integrations/TourSelectionNativeTransport.php';

/** Manager intent -> frozen offers -> durable one-creation fences -> native proof. */
final class ManagerTourSelectionDeliveryService
{
    public static function prepare(int $actor,int $conversation,int $version): array
    {
        $pdo=ConversationDb::connection();self::begin($pdo);
        try {
            $context=self::context($actor,$conversation,true);
            if (!$context['ok']) {$pdo->rollBack();return $context;}
            if (!$context['can_edit']) {$pdo->rollBack();return self::error('forbidden');}
            $q=$pdo->prepare('SELECT version,draft_json FROM manager_tour_selection_drafts WHERE conversation_id=?'.self::lock($pdo));$q->execute([$conversation]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int)$row['version']!==$version) {$pdo->rollBack();return self::error('draft_changed');}
            $draft=json_decode($row['draft_json'],true,32,JSON_THROW_ON_ERROR);
            foreach ($draft['offers']??[] as $offer) foreach ($offer['photos']??[] as $photo) if (!TourSelectionPhotoStore::forConversation((string)($photo['url']??''),$conversation)) {$pdo->rollBack();return self::error('invalid_photo');}
            $selection=ManagerTourSelectionPublicationDraft::build($draft);
            if (!$selection['ok']) {$pdo->rollBack();return $selection;}
            unset($selection['ok']);$json=self::json($selection);$hash=hash('sha256',self::json(self::binding($context)).$json);
            $q=$pdo->prepare('SELECT publication_id FROM manager_tour_selection_publications WHERE conversation_id=? AND draft_hash=?');$q->execute([$conversation,$hash]);$existing=$q->fetchColumn();
            if ($existing) {$pdo->commit();return self::get($actor,$conversation,$existing);}
            $id=bin2hex(random_bytes(16));$now=gmdate('Y-m-d H:i:s');
            $q=$pdo->prepare('INSERT INTO manager_tour_selection_publications(publication_id,conversation_id,draft_version,draft_hash,created_by_manager_id,binding_json,selection_json,created_at) VALUES(?,?,?,?,?,?,?,?)');
            $q->execute([$id,$conversation,$version,$hash,$actor,self::json(self::binding($context)),$json,$now]);
            foreach ($selection['offers'] as $i=>$offer) {
                $offerHash=hash('sha256',self::json(['binding'=>self::binding($context),'snapshot'=>$offer['snapshot'],'presentation'=>$offer['presentation']]));
                $q=$pdo->prepare('SELECT offer_id,callback_data FROM manager_tour_selection_deliveries WHERE conversation_id=? AND offer_hash=?');$q->execute([$conversation,$offerHash]);$prepared=$q->fetch(PDO::FETCH_ASSOC);
                if (!$prepared) {
                    $prepared=TourChoiceService::prepareInTransaction($pdo,$conversation,$offer['snapshot']);
                    if (!$prepared['ok']) throw new RuntimeException('Offer binding unavailable');
                    $q=$pdo->prepare('INSERT INTO manager_tour_selection_deliveries(offer_id,conversation_id,offer_hash,callback_data,state,receipt_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
                    $q->execute([$prepared['offer_id'],$conversation,$offerHash,$prepared['callback_data'],'pending','{}',$now,$now]);
                }
                $q=$pdo->prepare('INSERT INTO manager_tour_selection_cards(publication_id,card_index,offer_id,callback_data,created_at) VALUES(?,?,?,?,?)');
                $q->execute([$id,$i,$prepared['offer_id'],$prepared['callback_data'],$now]);
            }
            $pdo->commit();return self::get($actor,$conversation,$id);
        } catch (Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
    }

    public static function history(int $actor,int $conversation): array
    {
        $context=self::context($actor,$conversation);
        if (!$context['ok']) return $context;
        $q=ConversationDb::connection()->prepare('SELECT publication_id,draft_version,created_at FROM manager_tour_selection_publications WHERE conversation_id=? ORDER BY created_at DESC,publication_id DESC LIMIT 20');$q->execute([$conversation]);
        return ['ok'=>true,'publications'=>$q->fetchAll(PDO::FETCH_ASSOC)];
    }

    public static function get(int $actor,int $conversation,string $id): array
    {
        $context=self::context($actor,$conversation);
        if (!$context['ok']) return $context;
        $publication=self::publication($conversation,$id);
        if (!$publication) return self::error('not_found');
        $cards=[];
        foreach ($publication['cards'] as $row) {
            $offer=$publication['selection']['offers'][(int)$row['card_index']];$card=TourOfferCardRenderer::render($offer['snapshot'],$offer['presentation'],$row['callback_data']);
            $cards[]=['index'=>(int)$row['card_index'],'offer_id'=>(int)$row['offer_id'],'state'=>$row['state'],'text'=>$card['plain_text'],'snapshot'=>$offer['snapshot'],
                'photos'=>array_map(static fn(int $i):string=>self::photoUrl($conversation,$id,(int)$row['card_index'],$i),array_keys($offer['presentation']['photos'])),
                ...(($offer['presentation']['layout']??'')==='gallery-v1'?['photo_layout'=>'gallery-v1']:[])];
        }
        return ['ok'=>true,'publication'=>['id'=>$id,'draft_version'=>(int)$publication['draft_version'],'created_at'=>$publication['created_at'],
            'title'=>$publication['selection']['title'],'channel'=>$context['conversation']['channel'],
            'can_send'=>$context['can_send'] && self::binding($context)===$publication['binding'],'cards'=>$cards]];
    }

    public static function photo(int $actor,int $conversation,string $id,int $card,int $photo): ?array
    {
        $context=self::context($actor,$conversation);
        if (!$context['ok'] || $photo<0 || $photo>2) return null;
        $publication=self::publication($conversation,$id);$source=$publication['selection']['offers'][$card]['presentation']['photos'][$photo]['url']??null;
        return is_string($source)?TourSelectionPhotoStore::image(self::imageKey((int)$publication['cards'][$card]['offer_id']),0,$photo,$source):null;
    }

    /** Dependency seams are PHP-only; no HTTP input selects transport or image bytes. */
    public static function send(int $actor,int $conversation,string $id,int $index,?TourSelectionNativeTransport $transport=null,?callable $images=null): array
    {
        $context=self::context($actor,$conversation);
        if (!$context['ok']) return $context;
        if (!$context['can_send']) return self::error('forbidden');
        $publication=self::publication($conversation,$id);$row=$publication['cards'][$index]??null;
        if (!$row || self::binding($context)!==$publication['binding']) return self::error('not_found');
        $transport=$transport??new TourSelectionNativeTransport();
        if ($row['state']==='delivered') return self::get($actor,$conversation,$id);
        $offer=$publication['selection']['offers'][$index];$card=TourOfferCardRenderer::render($offer['snapshot'],$offer['presentation'],$row['callback_data']);
        if ($row['state']==='known') {
            $receipt=json_decode($row['receipt_json'],true,64,JSON_THROW_ON_ERROR);
            if (!$transport->verify($context['conversation']['channel'],$card,$receipt['identity'],$receipt['native'])) return self::error('delivery_unverified')+self::get($actor,$conversation,$id);
            self::finish($publication,$row,$receipt,$card);return self::get($actor,$conversation,$id);
        }
        if (!in_array($row['state'],['pending','rejected'],true)) return self::error('delivery_unknown')+self::get($actor,$conversation,$id);
        $q=ConversationDb::connection()->prepare("SELECT offer_id FROM manager_tour_selection_deliveries WHERE conversation_id=? AND state IN ('reserved','unknown','known') LIMIT 1");$q->execute([$conversation]);
        if ($q->fetchColumn()) return self::error('delivery_unknown');
        // Preparation can be retried: it never creates a customer message.
        try {
            $files=$images?$images($id,$index,$offer):array_map(static fn(int $i):array=>TourSelectionPhotoStore::image(self::imageKey((int)$row['offer_id']),0,$i,$offer['presentation']['photos'][$i]['url']),array_keys($offer['presentation']['photos']));
            if (!is_array($files) || count($files)!==count($offer['presentation']['photos'])) throw new RuntimeException('invalid_photo');
            $identity=$transport->prepare($context['transport_binding'],$files);
        } catch (Throwable $e) {return self::error('preparation_failed');}
        $receipt=['actor'=>$actor,'identity'=>$identity,'image_hashes'=>array_column($files,'sha256'),'native'=>null];
        if (count($receipt['image_hashes'])!==count($offer['presentation']['photos'])) return self::error('preparation_failed');
        $pdo=ConversationDb::connection();self::begin($pdo);
        try {
            $fresh=self::context($actor,$conversation,true);
            if (!$fresh['ok'] || !$fresh['can_send'] || self::binding($fresh)!==$publication['binding']) {$pdo->rollBack();return self::error('forbidden');}
            // Never create another card while any earlier creation is unresolved.
            $q=$pdo->prepare("SELECT state FROM manager_tour_selection_deliveries WHERE conversation_id=? AND state IN ('reserved','unknown','known') LIMIT 1".self::lock($pdo));$q->execute([$conversation]);
            if ($q->fetchColumn()) {$pdo->rollBack();return self::error('delivery_unknown');}
            $q=$pdo->prepare("UPDATE manager_tour_selection_deliveries SET state='reserved',receipt_json=?,updated_at=? WHERE offer_id=? AND state IN ('pending','rejected')");
            $q->execute([self::json($receipt),gmdate('Y-m-d H:i:s'),$row['offer_id']]);
            if ($q->rowCount()!==1) {$pdo->rollBack();return self::error('delivery_in_progress');}
            $pdo->commit();
        } catch (Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
        // Exactly one provider creation after the committed durable reservation.
        try {$native=$transport->create($context['conversation']['channel'],$card,$identity,$files);}
        catch (Throwable $e) {$native=['mid'=>null,'message'=>null];}
        $receipt['native']=$native;$known=is_string($native['mid']??null)&&$native['mid']!=='';
        $q=$pdo->prepare("UPDATE manager_tour_selection_deliveries SET state=?,receipt_json=?,updated_at=? WHERE offer_id=? AND state='reserved'");
        $rejected=!$known&&($native['rejected']??false)===true;
        $q->execute([$known?'known':($rejected?'rejected':'unknown'),self::json($receipt),gmdate('Y-m-d H:i:s'),$row['offer_id']]);
        if (!$known) return self::error($rejected?'delivery_rejected':'delivery_unknown')+self::get($actor,$conversation,$id);
        if (!$transport->verify($context['conversation']['channel'],$card,$identity,$native)) return self::error('delivery_unverified')+self::get($actor,$conversation,$id);
        self::finish($publication,$row,$receipt,$card);
        return self::get($actor,$conversation,$id);
    }

    private static function finish(array $publication,array $cardRow,array $receipt,array $card): void
    {
        $pdo=ConversationDb::connection();self::begin($pdo);
        try {
            $q=$pdo->prepare('SELECT id FROM conversations WHERE id=?'.self::lock($pdo));$q->execute([$publication['conversation_id']]);
            $q=$pdo->prepare('SELECT state FROM manager_tour_selection_deliveries WHERE offer_id=?'.self::lock($pdo));$q->execute([$cardRow['offer_id']]);$state=$q->fetchColumn();
            if ($state==='delivered') {$pdo->rollBack();return;}
            if ($state!=='known') throw new RuntimeException('Missing native creation proof');
            $bound=TourChoiceService::recordDeliveredInTransaction($pdo,(int)$cardRow['offer_id'],$receipt['native']['mid'],$receipt['identity']['chat']);
            if (!$bound['ok']) throw new RuntimeException('Native offer binding changed');
            $channel=$publication['binding']['channel'];$attachments=[];
            foreach ([0,1,2] as $i) $attachments[]=['type'=>'image','name'=>'Фото отеля','url'=>self::photoUrl((int)$publication['conversation_id'],$publication['publication_id'],(int)$cardRow['card_index'],$i)];
            $metadata=['project_key'=>$publication['binding']['project_key'],'tour_selection'=>$publication['publication_id'],'tour_offer_id'=>(int)$cardRow['offer_id'],'attachments'=>$attachments];
            if (!ConversationRecorder::outboundForConversation((int)$publication['conversation_id'],$channel,htmlspecialchars($card['plain_text'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),'manager',(string)$receipt['actor'],$metadata,$receipt['native']['mid'])) throw new RuntimeException('Transcript storage unavailable');
            $q=$pdo->prepare("UPDATE manager_tour_selection_deliveries SET state='delivered',updated_at=? WHERE offer_id=? AND state='known'");$q->execute([gmdate('Y-m-d H:i:s'),$cardRow['offer_id']]);$pdo->commit();
        } catch (Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
    }

    private static function context(int $actor,int $conversation,bool $lock=false): array
    {
        $pdo=ConversationDb::connection();
        if ($lock) {$q=$pdo->prepare('SELECT id FROM managers WHERE id=?'.self::lock($pdo));$q->execute([$actor]);$q=$pdo->prepare('SELECT id FROM conversations WHERE id=?'.self::lock($pdo));$q->execute([$conversation]);}
        $manager=ManagerAuthService::byId($actor);
        if (!$manager || empty($manager['is_active'])) return self::error('unauthorized');
        $c=ManagerConversationService::visibleConversation($conversation,$actor);
        if (!$c) return self::error('not_found');
        $q=$pdo->prepare('SELECT cc.external_user_id,cc.external_chat_id FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.id=?');$q->execute([$conversation]);$channel=$q->fetch(PDO::FETCH_ASSOC);
        if (!$channel || (string)$channel['external_chat_id']!==(string)$c['external_chat_id'] || !in_array($c['channel'],['max','telegram'],true) || $c['project_key']!==ProjectConfig::projectId()) return self::error('unsupported_channel');
        $binding=['project_key'=>(string)$c['project_key'],'channel'=>$c['channel'],'external_chat_id'=>(string)$c['external_chat_id'],'external_user_id'=>(string)$channel['external_user_id']];
        if ($c['channel']==='max') {
            $type=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.type'))":"JSON_EXTRACT(metadata_json,'$.type')";
            $q=$pdo->prepare("SELECT external_message_id FROM messages WHERE conversation_id=? AND channel='max' AND direction='inbound' AND sender_type='customer' AND sender_id=? AND external_message_id IS NOT NULL AND external_message_id<>'' AND ".$type."='message' ORDER BY id DESC LIMIT 1");$q->execute([$conversation,$binding['external_user_id']]);$binding['proof_mid']=(string)($q->fetchColumn()?:'');
        }
        return ['ok'=>true,'conversation'=>$c,'transport_binding'=>$binding,'can_edit'=>$c['status']!=='closed'&&ManagerConversationAccessPolicy::canEditVisibleConversation($c,$manager),'can_send'=>$c['status']==='manager'&&(int)$c['manager_id']===$actor];
    }
    private static function binding(array $context): array {return array_intersect_key($context['transport_binding'],array_flip(['project_key','channel','external_chat_id','external_user_id']));}
    private static function publication(int $conversation,string $id): ?array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$id)) return null;$pdo=ConversationDb::connection();$q=$pdo->prepare('SELECT * FROM manager_tour_selection_publications WHERE publication_id=? AND conversation_id=?');$q->execute([$id,$conversation]);$row=$q->fetch(PDO::FETCH_ASSOC);if (!$row) return null;
        $row['selection']=json_decode($row['selection_json'],true,48,JSON_THROW_ON_ERROR);$row['binding']=json_decode($row['binding_json'],true,16,JSON_THROW_ON_ERROR);
        $q=$pdo->prepare('SELECT c.*,d.state,d.receipt_json FROM manager_tour_selection_cards c JOIN manager_tour_selection_deliveries d ON d.offer_id=c.offer_id WHERE c.publication_id=? ORDER BY c.card_index LIMIT 21');$q->execute([$id]);$row['cards']=[];foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $card) $row['cards'][(int)$card['card_index']]=$card;
        if (count($row['cards'])!==count($row['selection']['offers']) || count($row['cards'])<1 || count($row['cards'])>20 || array_keys($row['cards'])!==range(0,count($row['cards'])-1)) throw new RuntimeException('Incomplete selection publication');
        return $row;
    }
    private static function photoUrl(int $conversation,string $id,int $card,int $photo): string {return 'tour-selection-photo.php?'.http_build_query(['conversation_id'=>$conversation,'publication_id'=>$id,'card'=>$card,'photo'=>$photo]);}
    private static function imageKey(int $offer): string {return TourSelectionPhotoStore::offerKey($offer);}
    private static function lock(PDO $pdo): string {return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    private static function begin(PDO $pdo): void {if ($pdo->inTransaction()) throw new LogicException('Selection delivery owns its transaction');$pdo->beginTransaction();}
    private static function json(array $data): string {return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function error(string $error): array {return ['ok'=>false,'error'=>$error];}
}
