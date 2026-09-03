<?php

declare(strict_types=1);

require_once __DIR__ . '/ManagerAuthService.php';
require_once __DIR__ . '/RoutingAccessService.php';

final class ManagerConversationAccessPolicy
{
    public static function canView(int $managerId, array $conversation): bool
    {
        return $managerId > 0 && RoutingAccessService::canSeeConversation($managerId, $conversation);
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
