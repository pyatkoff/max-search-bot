<?php
declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ManagerConversationService.php';
require_once __DIR__.'/ManagerConversationAccessPolicy.php';
require_once __DIR__.'/ManagerTourSelectionDraft.php';

/** Protected drafts. No provider calls, customer messages or transcript reads. */
final class ManagerTourSelectionService
{
    public static function get(int $actor,int $conversationId): array
    {
        $context=self::context($actor,$conversationId);
        if (isset($context['error'])) return $context;
        return ['ok'=>true,'selection'=>self::view(ConversationDb::connection(),$context)];
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
        $hash=hash('sha256',json_encode(['collector-v1',$conversationId,$connection,$import,$variant],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
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
                    $result=['ok'=>true,'state'=>'saved','saved_version'=>(int)$receipt['saved_version'],'count'=>count($current['draft']['offers'])];
                    if ($readOnly) $result['found']=true;
                    $pdo->commit();return $result;
                }
                if ($readOnly) {$pdo->commit();return ['ok'=>true,'found'=>false,'count'=>count($current['draft']['offers'])];}
                $duplicate=null;
                foreach ($current['draft']['offers'] as $old) {
                    if (!isset($old['import_source'])) continue;
                    $s=$old['import_source'];
                    if ($s['adapter']!==$source['adapter']) continue;
                    $sameQuote=hash_equals($s['quote_id'],$source['quote_id']);
                    $sameConditions=hash_equals(ManagerTourSelectionImportDraft::fingerprint($s,false),$conditions);
                    $sameTerms=hash_equals(ManagerTourSelectionImportDraft::fingerprint($s),$fingerprint);
                    if ($sameQuote && $sameTerms) {$duplicate=$old['id'];break;}
                    if (!$variant && ($sameQuote || $sameConditions)) {
                        $pdo->rollBack();return self::error($sameConditions&&!$sameTerms?'source_price_changed':'ambiguous_offer')+['existing_offer_id'=>$old['id']];
                    }
                }
                $next=$current['version'];$now=gmdate('Y-m-d H:i:s');$offerId=$duplicate;
                if ($duplicate===null) {
                    if (count($current['draft']['offers'])>=ManagerTourSelectionDraft::MAX_OFFERS) {$pdo->rollBack();return self::error('selection_full');}
                    $offer=$import['offer'];$offerId=bin2hex(random_bytes(16));$offer['id']=$offerId;$offer['import_source']=$source;
                    $current['draft']['offers'][]=$offer;
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
                $result=['ok'=>true,'state'=>$duplicate===null?'added':'already_added','offer_id'=>$offerId,'saved_version'=>$next,'count'=>count($current['draft']['offers'])];
                $pdo->commit();return $result;
            } catch(Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($attempt<2 && $e instanceof PDOException && (in_array((string)$e->getCode(),['23000','23505','40001'],true) || in_array((int)($e->errorInfo[1]??0),[5,6,1213],true))) {usleep(10000*($attempt+1));continue;}
                throw $e;
            }
        }
        throw new LogicException('Unreachable import retry');
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
        $draft=$row?ManagerTourSelectionDraft::normalize(json_decode((string)$row['draft_json'],true,32,JSON_THROW_ON_ERROR)):ManagerTourSelectionDraft::empty();
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
