<?php
require_once __DIR__ . '/ConversationDb.php';

/**
 * Business sales pipeline for manager workspace.
 * This layer is deliberately independent from technical dialogue states
 * (ai / waiting_manager / manager / closed).
 */
class SalesPipelineService
{
    public static function stages(bool $activeOnly=true): array
    {
        $sql='SELECT stage_key,display_name,color,sort_order,is_active,is_terminal,is_won FROM lead_stages';
        if($activeOnly)$sql.=' WHERE is_active=1';
        $sql.=' ORDER BY sort_order,display_name,stage_key';
        return ConversationDb::connection()->query($sql)->fetchAll();
    }

    public static function tags(bool $activeOnly=true): array
    {
        $sql='SELECT id,tag_key,display_name,color,sort_order,is_active FROM lead_tags';
        if($activeOnly)$sql.=' WHERE is_active=1';
        $sql.=' ORDER BY sort_order,display_name,id';
        return ConversationDb::connection()->query($sql)->fetchAll();
    }

    public static function stageForConversation(int $conversationId): ?array
    {
        $q=ConversationDb::connection()->prepare(
            'SELECT s.stage_key,s.display_name,s.color,s.sort_order,s.is_terminal,s.is_won '
            .'FROM conversations c LEFT JOIN lead_stages s ON s.stage_key=c.lead_stage_key WHERE c.id=? LIMIT 1'
        );
        $q->execute([$conversationId]);
        $row=$q->fetch();
        return $row?:null;
    }

    public static function tagsForConversation(int $conversationId): array
    {
        $q=ConversationDb::connection()->prepare(
            'SELECT t.id,t.tag_key,t.display_name,t.color,t.sort_order '
            .'FROM conversation_lead_tags ct JOIN lead_tags t ON t.id=ct.tag_id '
            .'WHERE ct.conversation_id=? AND t.is_active=1 ORDER BY t.sort_order,t.display_name,t.id'
        );
        $q->execute([$conversationId]);
        return $q->fetchAll();
    }

    public static function setStage(int $conversationId,string $stageKey): bool
    {
        $stageKey=trim($stageKey);
        if($conversationId<=0||$stageKey==='')return false;
        $q=ConversationDb::connection()->prepare('SELECT 1 FROM lead_stages WHERE stage_key=? AND is_active=1 LIMIT 1');
        $q->execute([$stageKey]);
        if(!$q->fetchColumn())return false;
        $q=ConversationDb::connection()->prepare('UPDATE conversations SET lead_stage_key=? WHERE id=?');
        $q->execute([$stageKey,$conversationId]);
        return $q->rowCount()>0 || self::currentStageKey($conversationId)===$stageKey;
    }

    public static function setTags(int $conversationId,array $tagIds,int $actorManagerId=0): bool
    {
        if($conversationId<=0)return false;
        $tagIds=array_values(array_unique(array_filter(array_map('intval',$tagIds),static function($id){return$id>0;})));
        $pdo=ConversationDb::connection();
        $pdo->beginTransaction();
        try{
            $valid=[];
            if($tagIds){
                $in=implode(',',array_fill(0,count($tagIds),'?'));
                $q=$pdo->prepare("SELECT id FROM lead_tags WHERE is_active=1 AND id IN ({$in})");
                $q->execute($tagIds);
                $valid=array_map('intval',array_column($q->fetchAll(),'id'));
            }
            $pdo->prepare('DELETE FROM conversation_lead_tags WHERE conversation_id=?')->execute([$conversationId]);
            if($valid){
                $ins=$pdo->prepare('INSERT INTO conversation_lead_tags (conversation_id,tag_id,added_by_manager_id) VALUES (?,?,?)');
                foreach($valid as $tagId)$ins->execute([$conversationId,$tagId,$actorManagerId>0?$actorManagerId:null]);
            }
            $pdo->commit();
            return count($valid)===count($tagIds);
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw$e;
        }
    }

    public static function conversationSnapshot(int $conversationId): array
    {
        return [
            'stage'=>self::stageForConversation($conversationId),
            'tags'=>self::tagsForConversation($conversationId),
        ];
    }

    private static function currentStageKey(int $conversationId): string
    {
        $q=ConversationDb::connection()->prepare('SELECT lead_stage_key FROM conversations WHERE id=? LIMIT 1');
        $q->execute([$conversationId]);
        return (string)($q->fetchColumn()?:'');
    }
}
