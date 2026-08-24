<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ConversationControlService.php';
require_once __DIR__ . '/ProjectConfig.php';

class ManagerConversationService
{
    public static function list(int $managerId, string $status = '', int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $where = ['c.project_key=?']; $args = [ProjectConfig::projectId()];
        if ($status !== '' && in_array($status, ['ai','waiting_manager','manager','closed'], true)) { $where[]='c.status=?'; $args[]=$status; }
        $sql = 'SELECT c.id,c.channel,c.status,c.manager_id,c.started_at,c.last_message_at,c.closed_at,cu.display_name,m.display_name AS manager_name,'
            . '(SELECT mm.text FROM messages mm WHERE mm.conversation_id=c.id ORDER BY mm.id DESC LIMIT 1) AS last_text,'
            . '(SELECT mm.direction FROM messages mm WHERE mm.conversation_id=c.id ORDER BY mm.id DESC LIMIT 1) AS last_direction '
            . 'FROM conversations c JOIN customers cu ON cu.id=c.customer_id LEFT JOIN managers m ON m.id=c.manager_id WHERE '.implode(' AND ',$where)
            . ' ORDER BY COALESCE(c.last_message_at,c.started_at) DESC LIMIT '.$limit;
        $q = ConversationDb::connection()->prepare($sql); $q->execute($args); return $q->fetchAll();
    }

    public static function detail(int $conversationId): ?array
    {
        $q = ConversationDb::connection()->prepare('SELECT c.id,c.channel,c.status,c.manager_id,c.started_at,c.last_message_at,c.closed_at,c.external_chat_id,cu.display_name,cu.phone,cu.email,m.display_name AS manager_name FROM conversations c JOIN customers cu ON cu.id=c.customer_id LEFT JOIN managers m ON m.id=c.manager_id WHERE c.id=? AND c.project_key=? LIMIT 1');
        $q->execute([$conversationId,ProjectConfig::projectId()]);
        $conversation = $q->fetch(); if (!$conversation) return null;
        $q = ConversationDb::connection()->prepare('SELECT id,direction,sender_type,text,created_at FROM messages WHERE conversation_id=? ORDER BY id ASC LIMIT 500');
        $q->execute([$conversationId]);
        return ['conversation'=>$conversation,'messages'=>$q->fetchAll()];
    }

    public static function take(int $conversationId, int $managerId): bool
    {
        $pdo=ConversationDb::connection(); $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('SELECT status,manager_id FROM conversations WHERE id=? AND project_key=? FOR UPDATE'); $q->execute([$conversationId,ProjectConfig::projectId()]); $row=$q->fetch();
            if(!$row || (string)$row['status']==='closed' || (!empty($row['manager_id']) && (int)$row['manager_id']!==$managerId)){ $pdo->rollBack(); return false; }
            $pdo->prepare('UPDATE conversations SET status=?,manager_id=? WHERE id=?')->execute(['manager',$managerId,$conversationId]);
            $pdo->prepare('INSERT INTO manager_assignments (conversation_id,manager_id,assignment_type) VALUES (?,?,?)')->execute([$conversationId,$managerId,'manual']);
            ConversationControlService::event($conversationId,'manager_taken','manager',$managerId); $pdo->commit(); return true;
        } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }

    public static function release(int $conversationId, int $managerId): bool
    {
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare('UPDATE conversations SET status=?,manager_id=NULL WHERE id=? AND project_key=? AND manager_id=? AND status=?');
        $q->execute(['ai',$conversationId,ProjectConfig::projectId(),$managerId,'manager']); if(!$q->rowCount()) return false;
        $pdo->prepare('UPDATE manager_assignments SET released_at=NOW() WHERE conversation_id=? AND manager_id=? AND released_at IS NULL')->execute([$conversationId,$managerId]);
        ConversationControlService::event($conversationId,'manager_released','manager',$managerId); return true;
    }

    public static function close(int $conversationId, int $managerId): bool
    {
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare('UPDATE conversations SET status=?,closed_at=NOW() WHERE id=? AND project_key=? AND (manager_id=? OR manager_id IS NULL)');
        $q->execute(['closed',$conversationId,ProjectConfig::projectId(),$managerId]); if(!$q->rowCount()) return false;
        $pdo->prepare('UPDATE manager_assignments SET released_at=NOW() WHERE conversation_id=? AND released_at IS NULL')->execute([$conversationId]);
        ConversationControlService::event($conversationId,'conversation_closed','manager',$managerId); return true;
    }
}
