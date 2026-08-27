<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectAccessService.php';
require_once __DIR__ . '/RoutingAccessService.php';
require_once __DIR__ . '/AuditLogService.php';

class ManagerAvailabilityService
{
    public const WORKDAY_START_HOUR = 10;
    public const WORKDAY_END_HOUR = 20;
    public const BUSINESS_TIMEZONE = 'Europe/Kaliningrad';

    public static function ensureSchema(): void
    {
        // Schema is managed by versioned migrations.
    }

    public static function withinWorkingHours(?int $now = null): bool
    {
        $now = $now ?? time();
        $dt = new DateTimeImmutable('@' . $now);
        $dt = $dt->setTimezone(new DateTimeZone(self::BUSINESS_TIMEZONE));
        $hour = (int)$dt->format('G');
        return $hour >= self::WORKDAY_START_HOUR && $hour < self::WORKDAY_END_HOUR;
    }

    public static function setWorking(int $managerId, bool $working): bool
    {
        self::ensureSchema();
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare('SELECT is_working FROM managers WHERE id=? AND is_active=1 LIMIT 1');
        $q->execute([$managerId]);
        $before=$q->fetchColumn();
        if($before===false)return false;
        $q=$pdo->prepare('UPDATE managers SET is_working=? WHERE id=? AND is_active=1');
        $q->execute([$working?1:0,$managerId]);
        if((bool)$before!==$working)AuditLogService::record($managerId,'manager_working_changed','manager',(string)$managerId,'',['is_working'=>(bool)$before],['is_working'=>$working]);
        return true;
    }

    public static function isWorking(int $managerId): bool
    {
        self::ensureSchema();
        $q=ConversationDb::connection()->prepare('SELECT is_working FROM managers WHERE id=? AND is_active=1 LIMIT 1');
        $q->execute([$managerId]);
        return (bool)$q->fetchColumn();
    }

    public static function anyWorkingForConversation(array $conversation): bool
    {
        self::ensureSchema();
        $projectKey=trim((string)($conversation['project_key']??''));
        if($projectKey==='')return false;
        $projectId=ProjectAccessService::projectIdByKey($projectKey);
        if($projectId<=0)return false;
        $q=ConversationDb::connection()->prepare('SELECT DISTINCT m.id FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id WHERE mp.project_id=? AND m.is_active=1 AND m.is_working=1');
        $q->execute([$projectId]);
        foreach($q->fetchAll() as $row){
            $id=(int)$row['id'];
            if(RoutingAccessService::canSeeConversation($id,$conversation))return true;
        }
        return false;
    }
}
