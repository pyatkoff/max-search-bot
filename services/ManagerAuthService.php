<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectAccessService.php';
require_once __DIR__ . '/ManagerAvailabilityService.php';

class ManagerAuthService
{
    private static $schemaReady=false;
    public static function ensureSchema(): void
    {
        if(self::$schemaReady)return;
        // Schema is managed by versioned migrations.
        ManagerAvailabilityService::ensureSchema();
        ProjectAccessService::ensureSchema();
        self::$schemaReady=true;
    }
    public static function isAdmin(?array $manager): bool
    {
        return $manager!==null && (string)($manager['role']??'manager')==='admin';
    }
    public static function isWorking(?array $manager): bool
    {
        return $manager!==null && !empty($manager['is_working']);
    }
    public static function hasAccounts(): bool
    {
        self::ensureSchema();return (int)ConversationDb::connection()->query("SELECT COUNT(*) FROM managers WHERE is_active=1 AND password_hash IS NOT NULL AND password_hash<>''")->fetchColumn()>0;
    }
    public static function bootstrap(string $login,string $password,string $displayName=''):?array
    {
        self::ensureSchema();$login=trim($login);$displayName=trim($displayName);
        if(self::hasAccounts()||$login===''||strlen($password)<8)return null;
        $pdo=ConversationDb::connection();$hash=password_hash($password,PASSWORD_DEFAULT);
        $q=$pdo->prepare('SELECT id FROM managers WHERE login=? LIMIT 1');$q->execute([$login]);$id=(int)$q->fetchColumn();
        if($id){$pdo->prepare('UPDATE managers SET password_hash=?,display_name=COALESCE(NULLIF(?,\'\'),display_name),is_active=1 WHERE id=?')->execute([$hash,$displayName,$id]);}
        else{$q=$pdo->prepare('INSERT INTO managers (login,password_hash,display_name,is_active) VALUES (?,?,?,1)');$q->execute([$login,$hash,$displayName!==''?$displayName:null]);$id=(int)$pdo->lastInsertId();}
        ProjectAccessService::ensureSchema();
        return self::byId($id);
    }
    public static function authenticate(string $login,string $password):?array
    {
        self::ensureSchema();$q=ConversationDb::connection()->prepare('SELECT id,login,password_hash,display_name,role,email,is_active,is_working FROM managers WHERE login=? LIMIT 1');$q->execute([trim($login)]);$row=$q->fetch();
        if(!$row||!(int)$row['is_active']||empty($row['password_hash'])||!password_verify($password,(string)$row['password_hash']))return null;
        ConversationDb::connection()->prepare('UPDATE managers SET last_login_at=NOW() WHERE id=?')->execute([(int)$row['id']]);unset($row['password_hash']);$row['is_working']=(bool)$row['is_working'];$row['projects']=ProjectAccessService::projectsForManager((int)$row['id']);return $row;
    }
    public static function byId(int $id):?array
    {
        self::ensureSchema();$q=ConversationDb::connection()->prepare('SELECT id,login,display_name,role,email,is_active,is_working FROM managers WHERE id=? LIMIT 1');$q->execute([$id]);$row=$q->fetch();
        if(!$row||!(int)$row['is_active'])return null;$row['is_working']=(bool)$row['is_working'];$row['projects']=ProjectAccessService::projectsForManager($id);return $row;
    }
}
