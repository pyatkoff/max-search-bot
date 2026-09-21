<?php

declare(strict_types=1);

require_once __DIR__ . '/RuntimeStorage.php';

final class MysqlDialogueStateRepository
{
    private static function pdo(): PDO
    {
        return RuntimeStorage::connection();
    }

    private static function projectKey(): string
    {
        return RuntimeStorage::projectKey();
    }

    public static function currentStatus($chatId)
    {
        $stmt = self::pdo()->prepare('SELECT status_id FROM runtime_dialogue_state WHERE project_key = ? AND chat_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([self::projectKey(), (string)$chatId]);
        $value = $stmt->fetchColumn();
        return $value === false ? false : (int)$value;
    }

    public static function addStatus($chatId, $statusId, $messageId = false)
    {
        $stmt = self::pdo()->prepare('INSERT INTO runtime_dialogue_state (project_key, chat_id, status_id, message_id) VALUES (?, ?, ?, ?)');
        return $stmt->execute([self::projectKey(), (string)$chatId, (int)$statusId, $messageId ? (string)$messageId : '']);
    }

    public static function latestMessageRow($chatId)
    {
        $stmt = self::pdo()->prepare('SELECT id AS ID, message_id AS UF_MESSID FROM runtime_dialogue_state WHERE project_key = ? AND chat_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([self::projectKey(), (string)$chatId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: false;
    }

    public static function deleteRow($rowId)
    {
        $stmt = self::pdo()->prepare('DELETE FROM runtime_dialogue_state WHERE project_key = ? AND id = ?');
        return $stmt->execute([self::projectKey(), (int)$rowId]);
    }

    public static function deleteAll($chatId): bool
    {
        $stmt = self::pdo()->prepare('DELETE FROM runtime_dialogue_state WHERE project_key = ? AND chat_id = ?');
        return $stmt->execute([self::projectKey(), (string)$chatId]);
    }

    public static function saveLastValue($chatId, $statusId, $value, $startStatusId = 64): bool
    {
        $row = self::latestStatusRow($chatId, $statusId);
        if (!$row) return false;
        $startRow = self::latestStatusRow($chatId, $startStatusId);
        if (!ConversationStateRepository::shouldReuseValueRow($row['ID'] ?? 0, $startRow['ID'] ?? 0)) return false;
        $stmt = self::pdo()->prepare('UPDATE runtime_dialogue_state SET value_text = ? WHERE project_key = ? AND id = ?');
        return $stmt->execute([(string)$value, self::projectKey(), (int)$row['ID']]);
    }

    public static function lastValue($chatId, $statusId, $startStatusId = 64)
    {
        $row = self::latestStatusRow($chatId, $statusId);
        if (!$row) return false;
        $startRow = self::latestStatusRow($chatId, $startStatusId);
        if (!ConversationStateRepository::shouldReuseValueRow($row['ID'] ?? 0, $startRow['ID'] ?? 0)) return false;
        return $row['UF_VALUE'];
    }

    public static function upsertValue($chatId, $statusId, $value, $startStatusId = 64): string
    {
        $row = self::latestStatusRow($chatId, $statusId);
        $startRow = self::latestStatusRow($chatId, $startStatusId);
        if ($row && ConversationStateRepository::shouldReuseValueRow($row['ID'] ?? 0, $startRow['ID'] ?? 0)) {
            $stmt = self::pdo()->prepare('UPDATE runtime_dialogue_state SET value_text = ? WHERE project_key = ? AND id = ?');
            $stmt->execute([(string)$value, self::projectKey(), (int)$row['ID']]);
            return 'updated';
        }
        $stmt = self::pdo()->prepare('INSERT INTO runtime_dialogue_state (project_key, chat_id, status_id, value_text, message_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([self::projectKey(), (string)$chatId, (int)$statusId, (string)$value, '']);
        return 'inserted';
    }

    public static function savedData($chatId, $statusStart, $statusCheck): array
    {
        $stmt = self::pdo()->prepare('SELECT id AS ID, status_id AS UF_STATUS, value_text AS UF_VALUE, message_id AS UF_MESSID FROM runtime_dialogue_state WHERE project_key = ? AND chat_id = ? ORDER BY id DESC');
        $stmt->execute([self::projectKey(), (string)$chatId]);
        return ConversationStateRepository::savedDataFromRows($stmt->fetchAll(PDO::FETCH_ASSOC), $statusStart, $statusCheck);
    }

    private static function latestStatusRow($chatId, $statusId)
    {
        $stmt = self::pdo()->prepare('SELECT id AS ID, value_text AS UF_VALUE FROM runtime_dialogue_state WHERE project_key = ? AND chat_id = ? AND status_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([self::projectKey(), (string)$chatId, (int)$statusId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: false;
    }

    public static function startValue($chatId, int $statusId): array
    {
        return self::latestStatusRow($chatId,$statusId) ?: [];
    }

    /** CAS uses byte-exact values, independent of case-insensitive/padded text collations. */
    public static function compareStartValue($chatId, int $statusId, int $startId, $old, string $new): bool
    {
        $sql='UPDATE runtime_dialogue_state SET value_text = ? WHERE project_key = ? AND chat_id = ? AND status_id = ? AND id = ?'
            . " AND HEX(COALESCE(value_text, '')) = HEX(?) AND id = (SELECT latest.id FROM"
            . ' (SELECT MAX(id) AS id FROM runtime_dialogue_state WHERE project_key = ? AND chat_id = ? AND status_id = ?) latest)';
        $stmt=self::pdo()->prepare($sql);
        $stmt->execute([$new,self::projectKey(),(string)$chatId,$statusId,$startId,(string)$old,self::projectKey(),(string)$chatId,$statusId]);
        return $stmt->rowCount()===1;
    }

}
