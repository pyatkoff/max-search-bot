<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ConversationControlService.php';
require_once __DIR__ . '/ProjectAccessService.php';

class ManagerConversationService
{
    private static function resolveProject(int $managerId, string $projectKey=''): string
    {
        $projectKey=trim($projectKey);
        if($projectKey==='')$projectKey=ProjectAccessService::defaultProjectKey($managerId);
        return ProjectAccessService::canAccess($managerId,$projectKey)?$projectKey:'';
    }

    public static function list(int $managerId, string $status = '', int $limit = 100, string $projectKey=''): array
    {
        $projectKey=self::resolveProject($managerId,$projectKey);if($projectKey==='')return[];
        $limit = max(1, min(200, $limit));
        $where = ['c.project_key=?']; $args = [$projectKey];
        if ($status !== '' && in_array($status, ['ai','waiting_manager','manager','closed'], true)) { $where[]='c.status=?'; $args[]=$status; }
        $sql = 'SELECT c.id,c.project_key,c.channel,c.status,c.manager_id,c.started_at,c.last_message_at,c.closed_at,cu.display_name,m.display_name AS manager_name,'
            . '(SELECT mm.text FROM messages mm WHERE mm.conversation_id=c.id ORDER BY mm.id DESC LIMIT 1) AS last_text,'
            . '(SELECT mm.direction FROM messages mm WHERE mm.conversation_id=c.id ORDER BY mm.id DESC LIMIT 1) AS last_direction '
            . 'FROM conversations c JOIN customers cu ON cu.id=c.customer_id LEFT JOIN managers m ON m.id=c.manager_id WHERE '.implode(' AND ',$where)
            . ' ORDER BY COALESCE(c.last_message_at,c.started_at) DESC LIMIT '.$limit;
        $q = ConversationDb::connection()->prepare($sql); $q->execute($args); return $q->fetchAll();
    }

    public static function detail(int $conversationId,int $managerId): ?array
    {
        $q = ConversationDb::connection()->prepare('SELECT c.id,c.project_key,c.channel,c.status,c.manager_id,c.started_at,c.last_message_at,c.closed_at,c.external_chat_id,cu.display_name,cu.phone,cu.email,m.display_name AS manager_name FROM conversations c JOIN customers cu ON cu.id=c.customer_id LEFT JOIN managers m ON m.id=c.manager_id WHERE c.id=? LIMIT 1');
        $q->execute([$conversationId]);
        $conversation = $q->fetch(); if (!$conversation || !ProjectAccessService::canAccess($managerId,(string)$conversation['project_key'])) return null;
        $q = ConversationDb::connection()->prepare('SELECT id,direction,sender_type,text,created_at FROM messages WHERE conversation_id=? ORDER BY id ASC LIMIT 500');
        $q->execute([$conversationId]);
        $messages = $q->fetchAll();
        foreach ($messages as &$message) {
            if (($message['sender_type'] ?? '') === 'manager') $message['text'] = html_entity_decode((string)$message['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        unset($message);
        return ['conversation'=>$conversation,'messages'=>$messages];
    }

    private static function accessibleConversation(int $conversationId,int $managerId,bool $forUpdate=false): ?array
    {
        $sql='SELECT id,project_key,status,manager_id,external_chat_id FROM conversations WHERE id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
        $q=ConversationDb::connection()->prepare($sql);$q->execute([$conversationId]);$row=$q->fetch();
        if(!$row||!ProjectAccessService::canAccess($managerId,(string)$row['project_key']))return null;
        return$row;
    }

    public static function take(int $conversationId, int $managerId): bool
    {
        $pdo=ConversationDb::connection(); $pdo->beginTransaction();
        try {
            $row=self::accessibleConversation($conversationId,$managerId,true);
            if(!$row || (string)$row['status']==='closed' || (!empty($row['manager_id']) && (int)$row['manager_id']!==$managerId)){ $pdo->rollBack(); return false; }
            $pdo->prepare('UPDATE conversations SET status=?,manager_id=? WHERE id=?')->execute(['manager',$managerId,$conversationId]);
            $pdo->prepare('INSERT INTO manager_assignments (conversation_id,manager_id,assignment_type) VALUES (?,?,?)')->execute([$conversationId,$managerId,'manual']);
            ConversationControlService::event($conversationId,'manager_taken','manager',$managerId); $pdo->commit(); return true;
        } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }

    public static function release(int $conversationId, int $managerId): bool
    {
        $pdo=ConversationDb::connection();$row=self::accessibleConversation($conversationId,$managerId);
        if(!$row||(int)($row['manager_id']??0)!==$managerId||(string)$row['status']!=='manager')return false;$chatId=$row['external_chat_id'];
        $pdo->prepare('UPDATE conversations SET status=?,manager_id=NULL WHERE id=?')->execute(['ai',$conversationId]);
        $pdo->prepare('UPDATE manager_assignments SET released_at=NOW() WHERE conversation_id=? AND manager_id=? AND released_at IS NULL')->execute([$conversationId,$managerId]);
        if(class_exists('MaxSearchApi')) { try { MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusAi); } catch(Throwable $ignored) {} }
        ConversationControlService::event($conversationId,'manager_released','manager',$managerId); return true;
    }

    public static function close(int $conversationId, int $managerId): bool
    {
        $pdo=ConversationDb::connection();$row=self::accessibleConversation($conversationId,$managerId);
        if(!$row||(string)$row['status']==='closed'||(!empty($row['manager_id'])&&(int)$row['manager_id']!==$managerId))return false;$chatId=$row['external_chat_id'];
        $pdo->prepare('UPDATE conversations SET status=?,closed_at=NOW() WHERE id=?')->execute(['closed',$conversationId]);
        $pdo->prepare('UPDATE manager_assignments SET released_at=NOW() WHERE conversation_id=? AND released_at IS NULL')->execute([$conversationId]);
        if(class_exists('MaxSearchApi')) { try { MaxSearchApi::deleteAllStatus($chatId); } catch(Throwable $ignored) {} }
        ConversationControlService::event($conversationId,'conversation_closed','manager',$managerId); return true;
    }

    public static function reopen(int $conversationId, int $managerId): bool
    {
        $pdo=ConversationDb::connection(); $pdo->beginTransaction();
        try {
            $row=self::accessibleConversation($conversationId,$managerId,true);
            if(!$row||(string)$row['status']!=='closed'){ $pdo->rollBack(); return false; }
            $pdo->prepare('UPDATE conversations SET status=?,manager_id=?,closed_at=NULL WHERE id=?')->execute(['manager',$managerId,$conversationId]);
            $pdo->prepare('INSERT INTO manager_assignments (conversation_id,manager_id,assignment_type) VALUES (?,?,?)')->execute([$conversationId,$managerId,'reopen']);
            ConversationControlService::event($conversationId,'conversation_reopened','manager',$managerId); $pdo->commit(); return true;
        } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }
}
