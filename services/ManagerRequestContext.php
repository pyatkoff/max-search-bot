<?php

declare(strict_types=1);

require_once __DIR__ . '/ManagerAuthService.php';
require_once __DIR__ . '/ManagerConversationAccessPolicy.php';

final class ManagerRequestContext
{
    public static function sessionCookiePath(?string $scriptName = null): string
    {
        $scriptName = $scriptName ?? (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptName !== '' && preg_match('~^(.*?/manager)(?:/|$)~', $scriptName, $matches) === 1) {
            return rtrim((string) $matches[1], '/') . '/';
        }
        return '/manager/';
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('anytour_manager_panel');
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 12,
            'path' => self::sessionCookiePath(),
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function managerId(): int
    {
        return (int) ($_SESSION['manager_id'] ?? 0);
    }

    public static function manager(): ?array
    {
        $id = self::managerId();
        return $id > 0 ? ManagerAuthService::byId($id) : null;
    }

    public static function csrf(bool $create = false): string
    {
        if ($create && empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
        }
        return (string) ($_SESSION['csrf'] ?? '');
    }

    public static function validCsrf(?string $provided): bool
    {
        $expected = self::csrf(false);
        return $expected !== '' && hash_equals($expected, (string) $provided);
    }

    public static function isAdmin(array $manager): bool
    {
        return ManagerAuthService::isAdmin($manager);
    }

    public static function canEditAssignedConversation(array $conversation, array $manager): bool
    {
        return ManagerConversationAccessPolicy::canEditVisibleConversation($conversation, $manager);
    }

    public static function jsonBody(): array
    {
        $value = json_decode((string) file_get_contents('php://input'), true);
        return is_array($value) ? $value : [];
    }
}
