<?php
declare(strict_types=1);
require_once __DIR__.'/TelegramDialogueArchive.php';
require_once __DIR__.'/ManagerAuthService.php';

/** Only connection metadata is retained until an administrator approves capture. */
final class TelegramArchiveAccountRegistry
{
    private PDO $pdo;
    public function __construct(?PDO $pdo = null) { $this->pdo = $pdo ?? ConversationDb::connection(); }
    private function lock(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    private function query(string $sql, array $values): PDOStatement { $q = $this->pdo->prepare($sql); $q->execute($values); return $q; }

    public static function validateConnection(array $data): void
    {
        TelegramDialogueNormalizer::id($data['user']['id'] ?? null);
        TelegramDialogueNormalizer::connectionId($data['id'] ?? null);
        TelegramDialogueNormalizer::timestamp($data['date'] ?? null);
        foreach (['first_name','last_name'] as $field) {
            if (isset($data['user'][$field]) && (!is_string($data['user'][$field]) || !mb_check_encoding($data['user'][$field], 'UTF-8') || strlen($data['user'][$field]) > 2048)) throw new RuntimeException('invalid_business_account');
        }
        if (isset($data['user']['username']) && (!is_string($data['user']['username']) || !preg_match('/\A[A-Za-z0-9_]{0,64}\z/', $data['user']['username']))) throw new RuntimeException('invalid_business_account');
    }

    public function account(string $project, string $bot, string $id, bool $lock = false): ?array
    {
        if ($lock && !$this->pdo->inTransaction()) throw new RuntimeException('archive_capture_guard_requires_transaction');
        $row = $this->query('SELECT * FROM telegram_archive_accounts WHERE project_key=? AND bot_key=? AND account_id=?'.($lock ? $this->lock() : ''),
            [TelegramDialogueArchive::key($project), TelegramDialogueArchive::key($bot, 96), TelegramDialogueNormalizer::id($id)])->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function observe(string $project, string $bot, array $data, string $sequence, bool $enabled): void
    {
        self::validateConnection($data);
        $id = TelegramDialogueNormalizer::id($data['user']['id'] ?? null);
        $connection = TelegramDialogueNormalizer::connectionId($data['id'] ?? null);
        $date = TelegramDialogueNormalizer::timestamp($data['date'] ?? null);
        $name = trim((string)($data['user']['first_name'] ?? '').' '.(string)($data['user']['last_name'] ?? ''));
        if (!mb_check_encoding($name, 'UTF-8')) throw new RuntimeException('invalid_business_account');
        $name = mb_substr($name, 0, 120);
        $username = (string)($data['user']['username'] ?? '');
        if (!preg_match('/\A[A-Za-z0-9_]{0,64}\z/', $username)) throw new RuntimeException('invalid_business_account');
        $this->pdo->beginTransaction();
        try {
            $old = $this->account($project, $bot, $id, true);
            if (!$old) {
                $this->query('INSERT INTO telegram_archive_accounts (project_key,bot_key,account_id,connection_id,display_name,username,connection_date,last_update_id,provider_enabled,approved) VALUES (?,?,?,?,?,?,?,?,?,0)',
                    [$project,$bot,$id,$connection,$name,$username,$date,$sequence,$enabled ? 1 : 0]);
            } else {
                $same = $old['connection_id'] === $connection;
                if (($same && (!$enabled || (int)$sequence > (int)$old['last_update_id']))
                    || (!$same && ($date > (int)$old['connection_date'] || ($date === (int)$old['connection_date'] && (int)$sequence > (int)$old['last_update_id'])))) {
                    $this->query('UPDATE telegram_archive_accounts SET connection_id=?,display_name=?,username=?,connection_date=?,last_update_id=?,provider_enabled=? WHERE project_key=? AND bot_key=? AND account_id=?',
                        [$connection,$name,$username,$date,$same ? max((int)$sequence,(int)$old['last_update_id']) : $sequence,$enabled ? 1 : 0,$project,$bot,$id]);
                }
            }
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }

    public function approve(string $project, string $bot, string $account, ?int $manager): void
    {
        if ($manager !== null && (!ManagerAuthService::byId($manager) || !ProjectAccessService::canAccess($manager, $project))) throw new RuntimeException('archive_manager_not_allowed');
        $this->pdo->beginTransaction();
        try {
            $row = $this->account($project, $bot, $account, true);
            if (!$row) throw new RuntimeException('archive_account_not_found');
            if ($manager !== null && !(bool)$row['provider_enabled']) throw new RuntimeException('archive_account_disconnected');
            $this->query('UPDATE telegram_archive_accounts SET approved=?,manager_id=? WHERE project_key=? AND bot_key=? AND account_id=?',
                [$manager !== null ? 1 : 0,$manager,$project,$bot,$account]);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }

    public function capturePermission(string $project, string $bot, string $account, string $connection)
    {
        $row = $this->account($project, $bot, $account, true);
        return $row && (bool)$row['approved'] && (bool)$row['provider_enabled'] && $row['connection_id'] === $connection && $row['manager_id'] !== null
            ? ['manager_id'=>(string)$row['manager_id']] : false;
    }

    public function listing(string $project, string $bot): array
    {
        $rows = $this->query('SELECT account_id,display_name,username,provider_enabled,approved,manager_id FROM telegram_archive_accounts WHERE project_key=? AND bot_key=? ORDER BY account_id LIMIT 100', [$project,$bot])->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $row): array => ['account_id'=>(string)$row['account_id'],'display_name'=>$row['display_name'],'username'=>$row['username'],
            'connected'=>(bool)$row['provider_enabled'],'approved'=>(bool)$row['approved'],'manager_id'=>$row['manager_id'] !== null ? (string)$row['manager_id'] : null], $rows);
    }

    public function managers(string $project): array
    {
        return $this->query("SELECT m.id,COALESCE(NULLIF(m.display_name,''),m.login) AS display_name FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id JOIN projects p ON p.id=mp.project_id WHERE p.project_key=? AND p.is_active=1 AND m.is_active=1 ORDER BY m.id", [$project])->fetchAll(PDO::FETCH_ASSOC);
    }
}
