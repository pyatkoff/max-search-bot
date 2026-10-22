<?php

declare(strict_types=1);

require_once __DIR__ . '/ManagerAuthService.php';
require_once __DIR__ . '/RoutingAccessService.php';
require_once __DIR__ . '/ManagerSupervisorAccessService.php';

final class ManagerConversationAccessPolicy
{
    public static function canView(int $managerId, array $conversation): bool
    {
        return $managerId > 0 && RoutingAccessService::canSeeConversation($managerId, $conversation);
    }

    public static function visibleRows(int $managerId,array $conversations): array
    {
        return $managerId > 0 ? RoutingAccessService::visibleConversations($managerId,$conversations) : [];
    }

    /** Read surfaces opt in. Legacy mutation/queue visibility is deliberately unchanged. */
    public static function canRead(int $managerId,array $conversation): bool
    {
        return self::canView($managerId,$conversation)||ManagerSupervisorAccessService::canRead($managerId,$conversation);
    }

    /** The conversation must already have passed canView(). */
    public static function canEditVisibleConversation(array $conversation, array $manager): bool
    {
        if (ManagerAuthService::isAdmin($manager)) {
            return true;
        }
        $managerId = (int) ($manager['id'] ?? 0);
        if ($managerId <= 0) {
            return false;
        }
        $assignedManagerId = (int) ($conversation['manager_id'] ?? 0);
        return $assignedManagerId > 0 && $assignedManagerId === $managerId;
    }
}
