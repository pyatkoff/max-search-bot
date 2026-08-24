<?php
require_once __DIR__ . '/ConversationDb.php';

class ManagerAuthService
{
    public static function authenticate(string $login, string $password): ?array
    {
        $q = ConversationDb::connection()->prepare('SELECT id,login,password_hash,display_name,email,is_active FROM managers WHERE login=? LIMIT 1');
        $q->execute([trim($login)]);
        $row = $q->fetch();
        if (!$row || !(int)$row['is_active'] || empty($row['password_hash']) || !password_verify($password, (string)$row['password_hash'])) return null;
        ConversationDb::connection()->prepare('UPDATE managers SET last_login_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
        unset($row['password_hash']);
        return $row;
    }

    public static function byId(int $id): ?array
    {
        $q = ConversationDb::connection()->prepare('SELECT id,login,display_name,email,is_active FROM managers WHERE id=? LIMIT 1');
        $q->execute([$id]);
        $row = $q->fetch();
        return ($row && (int)$row['is_active']) ? $row : null;
    }
}
