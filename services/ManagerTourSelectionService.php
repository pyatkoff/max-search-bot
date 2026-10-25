<?php
declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ManagerConversationService.php';
require_once __DIR__.'/ManagerConversationAccessPolicy.php';
require_once __DIR__.'/ManagerTourSelectionDraft.php';
require_once __DIR__.'/TourSelectionPhotoStore.php';

/** Protected drafts. No provider calls, customer messages or transcript reads. */
final class ManagerTourSelectionService
{
    public static function get(int $actor,int $conversationId): array
    {
        $context=self::context($actor,$conversationId);
        if (isset($context['error'])) return $context;
        return ['ok'=>true,'selection'=>self::view(ConversationDb::connection(),$context)];
    }

    /** File intake does not save a draft or create any customer/provider action. */
    public static function uploadPhoto(int $actor,int $conversationId,array $input): array
    {
        $context=self::context($actor,$conversationId);
        if (isset($context['error'])) return $context;
        if (!$context['can_edit']) return self::error('forbidden');
        $version=$input['version']??null;$offer=$input['offer_id']??null;$kind=$input['kind']??null;$op=$input['operation_id']??null;$encoded=$input['image_base64']??null;
        if (!is_int($version) || $version<1 || !is_string($offer) || !preg_match('/\A[a-f0-9]{32}\z/D',$offer) || !is_string($op) || !self::validOperation($op) || !in_array($kind,['cover','beach','room'],true)) return self::error('invalid_photo');
        $current=self::view(ConversationDb::connection(),$context);
        if ($current['version']!==$version || !in_array($offer,array_column($current['draft']['offers'],'id'),true)) return self::error('draft_changed');
        if (!is_string($encoded) || strlen($encoded)>intdiv(TourSelectionPhotoStore::MAX_BYTES+2,3)*4 || !preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/D',$encoded)) return self::error('invalid_photo');
        $bytes=base64_decode($encoded,true);
        if ($bytes===false || $bytes==='' || strlen($bytes)>TourSelectionPhotoStore::MAX_BYTES) return self::error('invalid_photo');
        try {
            if (array_key_exists('part_index',$input)) {
                foreach (['part_index','part_count','image_bytes'] as $field) if (!is_int($input[$field]??null)) return self::error('invalid_photo');
                if (!is_string($input['image_sha256']??null) || strlen($encoded)>96000) return self::error('invalid_photo');
                $image=TourSelectionPhotoStore::uploadChunk($actor,$conversationId,$offer,$kind,$op,$bytes,$input['part_index'],$input['part_count'],$input['image_bytes'],$input['image_sha256']);
            } else $image=TourSelectionPhotoStore::upload($actor,$conversationId,$offer,$kind,$op,$bytes);
        }
        catch (RuntimeException $e) {return self::error(in_array($e->getMessage(),['invalid_photo','photo_busy','photo_capacity','operation_mismatch'],true)?$e->getMessage():'photo_storage_unavailable');}
        // No DB/session lock is held while validating/writing bytes. Recheck the
        // current account, source/project/assignment and exact draft afterwards.
        $fresh=self::context($actor,$conversationId);
        if (isset($fresh['error'])) return $fresh;
        if (!$fresh['can_edit']) return self::error('forbidden');
        $latest=self::view(ConversationDb::connection(),$fresh);
        if ($latest['version']!==$version || array_intersect_key($fresh['conversation'],array_flip(['id','project_key','source_id','manager_id','customer_id','channel','status']))!==array_intersect_key($context['conversation'],array_flip(['id','project_key','source_id','manager_id','customer_id','channel','status']))) return self::error('draft_changed');
        if (!isset($image['url'])) return ['ok'=>true,'operation_id'=>$op]+$image;
        return ['ok'=>true,'operation_id'=>$op,'photo'=>['url'=>$image['url'],'kind'=>$kind,'illustrative'=>true],'sha256'=>$image['sha256']];
    }

    public static function uploadedPhoto(int $actor,int $conversationId,string $key): ?array
    {
        $context=self::context($actor,$conversationId);
        return isset($context['error'])?null:TourSelectionPhotoStore::uploaded($conversationId,$key);
    }

    public static function operation(int $actor,int $conversationId,string $operation): array
    {
        $context=self::context($actor,$conversationId);
        if (isset($context['error'])) return $context;
        if (!self::validOperation($operation)) return self::error('invalid_operation_id');
        $pdo=ConversationDb::connection(); $receipt=self::receipt($pdo,$actor,$operation);
        if ($receipt!==null && (int)$receipt['conversation_id']!==$conversationId) return self::error('operation_mismatch');
        return ['ok'=>true,'found'=>$receipt!==null,'saved_version'=>$receipt===null?null:(int)$receipt['saved_version'],'selection'=>self::view($pdo,$context)];
    }

    public static function save(int $actor,int $conversationId,array $input): array
    {
        $operation=$input['operation_id']??null; $version=$input['version']??null;
        if (!is_string($operation) || !self::validOperation($operation)) return self::error('invalid_operation_id');
        if (!is_int($version) || $version<0) return self::error('invalid_version');
        $draft=ManagerTourSelectionDraft::normalize($input['draft']??null);
        if ($draft===null) return self::error('invalid_draft');
        $hash=hash('sha256',json_encode([$conversationId,$version,$draft],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $pdo=ConversationDb::connection();
        if ($pdo->inTransaction()) throw new LogicException('Selection save owns its transaction');
        for ($attempt=0;$attempt<3;$attempt++) {
            $pdo->beginTransaction();
            try {
                // Lock the exact parent even for the first write. Assignment
                // cannot change between authorization and saving the draft.
                $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
                $q=$pdo->prepare('SELECT id FROM managers WHERE id=? AND is_active=1'.$lock); $q->execute([$actor]);
                if (!$q->fetchColumn()) {$pdo->rollBack();return self::error('unauthorized');}
                $q=$pdo->prepare('SELECT id FROM conversations WHERE id=?'.$lock); $q->execute([$conversationId]);
                if (!$q->fetchColumn()) {$pdo->rollBack();return self::error('not_found');}
                $context=self::context($actor,$conversationId);
                if (isset($context['error'])) {$pdo->rollBack();return $context;}
                if (!$context['can_edit']) {$pdo->rollBack();return self::error('forbidden');}
                $receipt=self::receipt($pdo,$actor,$operation,true);
                if ($receipt!==null) {
                    if ((int)$receipt['conversation_id']!==$conversationId || !hash_equals($receipt['request_hash'],$hash)) {$pdo->rollBack();return self::error('operation_mismatch');}
                    $result=['ok'=>true,'saved_version'=>(int)$receipt['saved_version'],'selection'=>self::view($pdo,$context,true)];
                    $pdo->commit(); return $result;
                }
                $current=self::view($pdo,$context,true);
                if ($current['version']!==$version) {$pdo->rollBack();return self::error('conflict')+['selection'=>$current];}
                $sources=[];
                foreach ($current['draft']['offers'] as $old) if (isset($old['import_source'])) $sources[$old['id']]=$old['import_source'];
                foreach ($draft['offers'] as $offer) {
                    foreach ($offer['photos'] as $photo) if (!TourSelectionPhotoStore::forConversation($photo['url'],$conversationId)) {$pdo->rollBack();return self::error('invalid_photo');}
                    if (($sources[$offer['id']]??null)!==($offer['import_source']??null)) {$pdo->rollBack();return self::error('source_changed');}
                }
                $next=$version+1; $now=gmdate('Y-m-d H:i:s'); $json=json_encode($draft,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                if ($version===0) {
                    $q=$pdo->prepare('INSERT INTO manager_tour_selection_drafts (conversation_id,version,draft_json,updated_by_manager_id,updated_at) VALUES (?,?,?,?,?)');
                    $q->execute([$conversationId,$next,$json,$actor,$now]);
                } else {
                    $q=$pdo->prepare('UPDATE manager_tour_selection_drafts SET version=?,draft_json=?,updated_by_manager_id=?,updated_at=? WHERE conversation_id=? AND version=?');
                    $q->execute([$next,$json,$actor,$now,$conversationId,$version]);
                    if ($q->rowCount()!==1) throw new RuntimeException('Selection revision changed under lock');
                }
                $q=$pdo->prepare('INSERT INTO manager_tour_selection_operations (manager_id,operation_id,conversation_id,request_hash,saved_version,created_at) VALUES (?,?,?,?,?,?)');
                $q->execute([$actor,$operation,$conversationId,$hash,$next,$now]);
                $result=['ok'=>true,'saved_version'=>$next,'selection'=>self::view($pdo,$context,true)];
                $pdo->commit(); return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($attempt<2 && $e instanceof PDOException && (in_array((string)$e->getCode(),['23000','23505','40001'],true) || in_array((int)($e->errorInfo[1]??0),[5,6,1213],true))) {usleep(10000*($attempt+1));continue;}
                throw $e;
            }
        }
        throw new LogicException('Unreachable selection retry');
    }

    /** Append to the fresh canonical draft under its existing parent lock/receipt owner. */
    public static function import(int $actor,int $conversationId,string $session,array $input): array
    {
        return self::importCommand($actor,$conversationId,$session,$input,false);
    }

    /** Check exactly the persisted import intent, with the same current fences. */
    public static function importOperation(int $actor,int $conversationId,string $session,array $input): array
    {
        return self::importCommand($actor,$conversationId,$session,$input,true);
    }

    private static function importCommand(int $actor,int $conversationId,string $session,array $input,bool $readOnly): array
    {
        require_once __DIR__.'/ManagerTourSelectionImportDraft.php';
        require_once __DIR__.'/ManagerTourSelectionCollectorSession.php';
        $operation=$input['operation_id']??null;$connection=$input['connection_id']??'';
        if (!is_string($operation) || !self::validOperation($operation)) return self::error('invalid_operation_id');
        $import=ManagerTourSelectionImportDraft::normalize($input['observation']??null);
        if ($import===null) return self::error('invalid_import');
        $variant=$input['as_variant']??false;
        if (!is_bool($variant)) return self::error('invalid_import');
        $managerAmount=null;
        if (array_key_exists('manager_amount',$input)) {
            $edited=$import['offer'];$edited['amount']=$input['manager_amount'];
            $checked=ManagerTourSelectionDraft::normalize(['title'=>'Импорт','intro'=>'','offers'=>[$edited]]);
            if ($checked===null || $checked['offers'][0]['amount']==='') return self::error('invalid_import');
            $managerAmount=$checked['offers'][0]['amount'];
        }
        // Absent adjustment preserves every existing collector-v1 receipt hash.
        $intent=$managerAmount===null?['collector-v1',$conversationId,$connection,$import,$variant]:['collector-v2',$conversationId,$connection,$import,$variant,$managerAmount];
        $hash=hash('sha256',json_encode($intent,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $priceAck=$managerAmount===null?[]:['manager_amount'=>$managerAmount];
        $source=ManagerTourSelectionImportDraft::source($import);
        $fingerprint=ManagerTourSelectionImportDraft::fingerprint($source);
        $conditions=ManagerTourSelectionImportDraft::fingerprint($source,false);
        $pdo=ConversationDb::connection();
        if ($pdo->inTransaction()) throw new LogicException('Selection import owns its transaction');
        for ($attempt=0;$attempt<3;$attempt++) {
            $pdo->beginTransaction();
            try {
                $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
                $q=$pdo->prepare('SELECT id FROM managers WHERE id=? AND is_active=1'.$lock);$q->execute([$actor]);
                if (!$q->fetchColumn()) {$pdo->rollBack();return self::error('unauthorized');}
                $q=$pdo->prepare('SELECT id FROM conversations WHERE id=?'.$lock);$q->execute([$conversationId]);
                if (!$q->fetchColumn()) {$pdo->rollBack();return self::error('not_found');}
                $context=self::context($actor,$conversationId);
                if (isset($context['error'])) {$pdo->rollBack();return $context;}
                if (!$context['can_edit']) {$pdo->rollBack();return self::error('forbidden');}
                $current=self::view($pdo,$context,true);
                if (!is_string($connection) || !ManagerTourSelectionCollectorSession::authorize($pdo,$actor,$conversationId,$session,$connection,$context['conversation'],$current['draft'])) {$pdo->rollBack();return self::error('connection_changed');}
                $receipt=self::receipt($pdo,$actor,$operation,true);
                if ($receipt) {
                    if ((int)$receipt['conversation_id']!==$conversationId || !hash_equals($receipt['request_hash'],$hash)) {$pdo->rollBack();return self::error('operation_mismatch');}
                    $result=['ok'=>true,'state'=>'saved','saved_version'=>(int)$receipt['saved_version'],'count'=>count($current['draft']['offers'])]+$priceAck;
                    if ($readOnly) $result['found']=true;
                    $pdo->commit();return $result;
                }
                if ($readOnly) {$pdo->commit();return ['ok'=>true,'found'=>false,'count'=>count($current['draft']['offers'])]+$priceAck;}
                $duplicate=null;
                foreach ($current['draft']['offers'] as $old) {
                    if (!isset($old['import_source'])) continue;
                    $s=$old['import_source'];
                    if ($s['adapter']!==$source['adapter']) continue;
                    $sameQuote=hash_equals($s['quote_id'],$source['quote_id']);
                    $sameConditions=hash_equals(ManagerTourSelectionImportDraft::fingerprint($s,false),$conditions);
                    $sameTerms=hash_equals(ManagerTourSelectionImportDraft::fingerprint($s),$fingerprint);
                    if ($sameQuote && $sameTerms) {
                        if ($managerAmount!==null && self::amountKey($old['amount'])!==self::amountKey($managerAmount)) {$pdo->rollBack();return self::error('manual_price_conflict');}
                        $duplicate=$old['id'];break;
                    }
                    if (!$variant && ($sameQuote || $sameConditions)) {
                        $pdo->rollBack();return self::error($sameConditions&&!$sameTerms?'source_price_changed':'ambiguous_offer')+['existing_offer_id'=>$old['id']];
                    }
                }
                $next=$current['version'];$now=gmdate('Y-m-d H:i:s');$offerId=$duplicate;$changed=false;
                if ($duplicate===null) {
                    if (count($current['draft']['offers'])>=ManagerTourSelectionDraft::MAX_OFFERS) {$pdo->rollBack();return self::error('selection_full');}
                    $offer=$import['offer'];$offerId=bin2hex(random_bytes(16));$offer['id']=$offerId;$offer['import_source']=$source;
                    if ($managerAmount!==null) $offer['amount']=$managerAmount;
                    $current['draft']['offers'][]=$offer;
                    $changed=true;
                } elseif (!empty($source['photos'])) {
                    // Re-adding a legacy photo-less import enriches that same
                    // offer. Never overwrite photos already chosen by staff.
                    foreach ($current['draft']['offers'] as &$old) if ($old['id']===$duplicate && $old['photos']===[]) {
                        $old['photos']=$import['offer']['photos'];$old['import_source']['photos']=$source['photos'];$changed=true;break;
                    }
                    unset($old);
                }
                if ($changed) {
                    $draft=ManagerTourSelectionDraft::normalize($current['draft']);
                    if ($draft===null) throw new LogicException('Import produced invalid draft');
                    $next++;$json=json_encode($draft,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                    if ($current['version']===0) {
                        $q=$pdo->prepare('INSERT INTO manager_tour_selection_drafts(conversation_id,version,draft_json,updated_by_manager_id,updated_at) VALUES(?,?,?,?,?)');$q->execute([$conversationId,$next,$json,$actor,$now]);
                    } else {
                        $q=$pdo->prepare('UPDATE manager_tour_selection_drafts SET version=?,draft_json=?,updated_by_manager_id=?,updated_at=? WHERE conversation_id=? AND version=?');$q->execute([$next,$json,$actor,$now,$conversationId,$current['version']]);
                        if ($q->rowCount()!==1) throw new RuntimeException('Import revision changed under lock');
                    }
                }
                $q=$pdo->prepare('INSERT INTO manager_tour_selection_operations(manager_id,operation_id,conversation_id,request_hash,saved_version,created_at) VALUES(?,?,?,?,?,?)');$q->execute([$actor,$operation,$conversationId,$hash,$next,$now]);
                $result=['ok'=>true,'state'=>$duplicate===null?'added':'already_added','offer_id'=>$offerId,'saved_version'=>$next,'count'=>count($current['draft']['offers'])]+$priceAck;
                $pdo->commit();return $result;
            } catch(Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($attempt<2 && $e instanceof PDOException && (in_array((string)$e->getCode(),['23000','23505','40001'],true) || in_array((int)($e->errorInfo[1]??0),[5,6,1213],true))) {usleep(10000*($attempt+1));continue;}
                throw $e;
            }
        }
        throw new LogicException('Unreachable import retry');
    }

    private static function amountKey(string $amount): string
    {
        [$whole,$fraction]=explode('.',$amount.'.');
        return $whole.'.'.str_pad($fraction,2,'0');
    }

    private static function context(int $actor,int $conversationId): array
    {
        $manager=ManagerAuthService::byId($actor);
        if (!$manager || empty($manager['is_active'])) return self::error('unauthorized');
        if ($conversationId<=0) return self::error('invalid_conversation_id');
        $c=ManagerConversationService::visibleConversation($conversationId,$actor);
        if (!$c) return self::error('not_found');
        return ['conversation'=>$c,'can_edit'=>$c['status']!=='closed' && ManagerConversationAccessPolicy::canEditVisibleConversation($c,$manager)];
    }

    private static function view(PDO $pdo,array $context,bool $lock=false): array
    {
        $id=(int)$context['conversation']['id'];
        $suffix=$lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT version,draft_json,updated_by_manager_id,updated_at FROM manager_tour_selection_drafts WHERE conversation_id=?'.$suffix);
        $q->execute([$id]); $row=$q->fetch(PDO::FETCH_ASSOC);
        $draft=$row?ManagerTourSelectionDraft::restoreStored(json_decode((string)$row['draft_json'],true,32,JSON_THROW_ON_ERROR)):ManagerTourSelectionDraft::empty();
        if ($draft===null) throw new RuntimeException('Invalid stored selection draft');
        return ['conversation_id'=>$id,'version'=>$row?(int)$row['version']:0,'draft'=>$draft,'can_edit'=>$context['can_edit'],'updated_at'=>$row['updated_at']??null,'updated_by_manager_id'=>$row?(int)$row['updated_by_manager_id']:null];
    }

    private static function receipt(PDO $pdo,int $actor,string $id,bool $lock=false): ?array
    {
        $suffix=$lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT conversation_id,request_hash,saved_version FROM manager_tour_selection_operations WHERE manager_id=? AND operation_id=?'.$suffix);
        $q->execute([$actor,$id]); $row=$q->fetch(PDO::FETCH_ASSOC); return $row?:null;
    }
    private static function validOperation(string $id): bool {return preg_match('/\A[A-Za-z0-9_-]{16,96}\z/D',$id)===1;}
    private static function error(string $code): array {return ['ok'=>false,'error'=>$code];}
}
