<?php
declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/TelegramDialogueNormalizer.php';

/** Private archive owner. No live conversations, read markers, sends or analytics. */
final class TelegramDialogueArchive
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null) { $this->pdo = $pdo ?? ConversationDb::connection(); }

    public static function key(string $value, int $limit = 64): string
    {
        if (strlen($value) > $limit || !preg_match('/\A[A-Za-z0-9_.:-]+\z/', $value)) throw new InvalidArgumentException('invalid_archive_scope');
        return $value;
    }

    private function lock(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }

    private function query(string $sql, array $values): PDOStatement
    {
        $q = $this->pdo->prepare($sql); $q->execute($values); return $q;
    }

    private function transaction(callable $operation)
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('archive_transaction_already_active');
        $this->pdo->beginTransaction();
        try { $result = $operation(); $this->pdo->commit(); return $result; }
        catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }

    public function connection(string $project, string $bot, string $connection): ?array
    {
        $row = $this->query('SELECT account_id,is_enabled,last_update_id FROM telegram_dialogue_connections WHERE project_key=? AND bot_key=? AND connection_id=?',
            [self::key($project), self::key($bot, 96), TelegramDialogueNormalizer::connectionId($connection)])->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function saveConnection(string $project, string $bot, string $connection, string $account, bool $enabled, string $sequence): void
    {
        $project = self::key($project); $bot = self::key($bot, 96); $connection = TelegramDialogueNormalizer::connectionId($connection);
        $account = TelegramDialogueNormalizer::id($account); $sequence = TelegramDialogueNormalizer::sequence($sequence);
        $this->transaction(function () use ($project, $bot, $connection, $account, $enabled, $sequence): void {
            $row = $this->query('SELECT account_id,is_enabled,last_update_id FROM telegram_dialogue_connections WHERE project_key=? AND bot_key=? AND connection_id=?'.$this->lock(), [$project, $bot, $connection])->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ((string)$row['account_id'] !== $account) throw new RuntimeException('business_connection_identity_conflict');
                // Revocation always fails closed, even if updates arrive out of order.
                if ($enabled && (int)$sequence <= (int)$row['last_update_id']) return;
                $this->query('UPDATE telegram_dialogue_connections SET is_enabled=?,last_update_id=? WHERE project_key=? AND bot_key=? AND connection_id=?',
                    [$enabled ? 1 : 0, max((int)$sequence, (int)$row['last_update_id']), $project, $bot, $connection]);
            } else $this->query('INSERT INTO telegram_dialogue_connections (project_key,bot_key,connection_id,account_id,is_enabled,last_update_id) VALUES (?,?,?,?,?,?)',
                [$project, $bot, $connection, $account, $enabled ? 1 : 0, $sequence]);
        });
    }

    /** Parsing/validation must finish before starting an import transaction. */
    public function import(string $project, array $records, ?string $manager = null): array
    {
        $project = self::key($project);
        if ($manager !== null) $manager = TelegramDialogueNormalizer::id($manager);
        return $this->transaction(function () use ($project, $records, $manager): array {
            $counts = ['inserted'=>0, 'updated'=>0, 'unchanged'=>0];
            foreach ($records as $record) { $record['manager_id'] = $manager; $counts[$this->store($project, $record)]++; }
            return $counts;
        });
    }

    /** The connection lock fences concurrent disconnects before archive writes. */
    public function receive(string $project, string $bot, string $connection, array $records, ?string $manager = null): array
    {
        return $this->transaction(function () use ($project, $bot, $connection, $records, $manager): array {
            $row = $this->query('SELECT account_id,is_enabled FROM telegram_dialogue_connections WHERE project_key=? AND bot_key=? AND connection_id=?'.$this->lock(),
                [self::key($project), self::key($bot, 96), TelegramDialogueNormalizer::connectionId($connection)])->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('unknown_business_connection');
            if (!(bool)$row['is_enabled']) return ['state'=>'disconnected', 'stored'=>0];
            $stored = 0;
            foreach ($records as $record) {
                if ($record['account_id'] !== (string)$row['account_id']) throw new RuntimeException('business_message_account_conflict');
                $record['manager_id'] = $manager;
                $record['bot_key'] = $bot;
                if ($this->store($project, $record) !== 'unchanged') $stored++;
            }
            return ['state'=>'archived', 'stored'=>$stored];
        });
    }

    private function store(string $project, array $record): string
    {
        $keys = [$project, TelegramDialogueNormalizer::id($record['account_id']), TelegramDialogueNormalizer::id($record['chat_id']), TelegramDialogueNormalizer::id($record['message_id'])];
        $sql = 'SELECT id,record_json FROM telegram_dialogue_messages WHERE project_key=? AND account_id=? AND chat_id=? AND message_id=?'.$this->lock();
        $old = $this->query($sql, $keys)->fetch(PDO::FETCH_ASSOC);
        if (!$old) {
            try {
                $this->query('INSERT INTO telegram_dialogue_messages (project_key,account_id,chat_id,message_id,version_at,source_kind,is_deleted,record_json) VALUES (?,?,?,?,?,?,?,?)',
                    array_merge($keys, [$record['version_at'], $record['source_kind'], $record['is_deleted'] ? 1 : 0, self::encode($record)]));
                return 'inserted';
            } catch (PDOException $error) {
                // A concurrent importer/live request may have won the unique key.
                if ($error->getCode() !== '23000') throw $error;
                $old = $this->query($sql, $keys)->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw $error;
            }
        }
        $previous = json_decode($old['record_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!empty($previous['is_deleted'])) return 'unchanged';
        if (empty($record['is_deleted'])) {
            if ($record['version_at'] < $previous['version_at']) return 'unchanged';
            if ($record['version_at'] === $previous['version_at']) {
                if ($record['source_kind'] === 'desktop_import') return 'unchanged';
                if ($previous['source_kind'] === 'business_message' && ($record['bot_key'] ?? null) === ($previous['bot_key'] ?? null)
                    && (int)$record['update_id'] <= (int)$previous['update_id']) return 'unchanged';
            }
            if ($record['manager_id'] === null) $record['manager_id'] = $previous['manager_id'] ?? null;
        } else {
            // Keep original chronology but discard content and media on deletion.
            $record['created_at'] = $previous['created_at'];
            $record['version_at'] = $previous['version_at'];
        }
        $encoded = self::encode($record);
        if ($encoded === $old['record_json']) return 'unchanged';
        $this->query('UPDATE telegram_dialogue_messages SET version_at=?,source_kind=?,is_deleted=?,record_json=? WHERE id=?',
            [$record['version_at'], $record['source_kind'], $record['is_deleted'] ? 1 : 0, $encoded, $old['id']]);
        return 'updated';
    }

    public static function encode(array $record): string
    {
        $encoded = json_encode($record, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 2097152) throw new InvalidArgumentException('archive_record_limit');
        return $encoded;
    }

    /** Stable keyset export of the selected private archive; no public HTTP API. */
    public function export(string $project, string $account, array $chats): Generator
    {
        $project = self::key($project); $account = TelegramDialogueNormalizer::id($account);
        if (!$chats || count($chats) > 1000) throw new InvalidArgumentException('explicit_chat_scope_required');
        $chats = array_map([TelegramDialogueNormalizer::class, 'id'], $chats);
        if ($this->pdo->inTransaction()) throw new RuntimeException('archive_transaction_already_active');
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        } else $this->pdo->beginTransaction();
        try {
            $last = 0;
            do {
                $rows = $this->query('SELECT id,record_json FROM telegram_dialogue_messages WHERE project_key=? AND account_id=? AND chat_id IN ('.implode(',', array_fill(0, count($chats), '?')).') AND id>? ORDER BY id LIMIT 200',
                    array_merge([$project, $account], $chats, [$last]))->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) { $last = (int)$row['id']; yield json_decode($row['record_json'], true, 32, JSON_THROW_ON_ERROR); }
            } while (count($rows) === 200);
        } finally { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); }
    }
}
