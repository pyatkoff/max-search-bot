<?php

declare(strict_types=1);

require_once __DIR__.'/ManagerAiTextRedactor.php';

/** Bounded, read-only source snapshots. Never calls a provider or a sender. */
final class VirtualReplySourceReader
{
    public const MAX_MESSAGES = 120;
    public const MAX_TEXT_CHARACTERS = 40000;
    public const MAX_SCAN = 500;
    // LENGTH is bytes on MySQL and characters on SQLite. Four bytes per
    // character bounds either dialect before any large raw value is fetched.
    private const MAX_RAW_UNITS = 262144;
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** IDs are discovery cursors only. Existing cases must also be reconciled. */
    public function scan(string $source, int $afterId, int $limit): array
    {
        $empty = ['source_ids'=>[], 'last_id'=>max(0, $afterId), 'has_more'=>false];
        if (!$this->validSource($source) || $afterId < 0 || $limit < 1 || !$this->currentProject()) return $empty;
        $limit = min(self::MAX_SCAN, $limit);
        try {
            $sql = $source === 'conversation'
                ? 'SELECT m.id FROM messages m JOIN conversations c ON c.id=m.conversation_id JOIN projects p ON p.project_key=c.project_key WHERE c.project_key=? AND p.is_active=1 AND c.is_test=0 AND m.id>? ORDER BY m.id LIMIT '
                : 'SELECT m.id FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE m.project_key=? AND p.is_active=1 AND m.id>? ORDER BY m.id LIMIT ';
            $q = $this->pdo->prepare($sql.($limit + 1));
            $q->execute(['anytour', $afterId]);
            $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            $more = count($ids) > $limit;
            $ids = array_slice($ids, 0, $limit);
            return ['source_ids'=>$ids, 'last_id'=>$ids ? $ids[count($ids)-1] : $afterId, 'has_more'=>$more];
        } catch (Throwable $ignored) { throw new RuntimeException('virtual_reply_source_unavailable'); }
    }

    /** Bootstrap the fresh lane without walking the historical import first. */
    public function recent(string $source, int $limit): array
    {
        $empty = ['source_ids'=>[], 'last_id'=>0, 'has_more'=>false];
        if (!$this->validSource($source) || $limit < 1 || !$this->currentProject()) return $empty;
        $limit = min(self::MAX_SCAN, $limit);
        try {
            // Archive SQL has no created_at column; its import ID is explicitly
            // discovery order, never a claim that imported history is fresh.
            $sql = $source === 'conversation'
                ? 'SELECT m.id FROM messages m JOIN conversations c ON c.id=m.conversation_id JOIN projects p ON p.project_key=c.project_key WHERE c.project_key=? AND p.is_active=1 AND c.is_test=0 ORDER BY m.id DESC LIMIT '
                : 'SELECT m.id FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE m.project_key=? AND p.is_active=1 ORDER BY m.id DESC LIMIT ';
            $q = $this->pdo->prepare($sql.($limit + 1));
            $q->execute(['anytour']);
            $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            $more = count($ids) > $limit;
            $ids = array_reverse(array_slice($ids, 0, $limit));
            return ['source_ids'=>$ids, 'last_id'=>$ids ? $ids[count($ids)-1] : 0, 'has_more'=>$more];
        } catch (Throwable $ignored) { throw new RuntimeException('virtual_reply_source_unavailable'); }
    }

    /** Every recheck owns a fresh transaction, never a caller's stale snapshot. */
    public function snapshot(string $source, int $sourceId): ?array
    {
        if (!$this->validSource($source) || $sourceId < 1 || !$this->currentProject() || $this->pdo->inTransaction()) return null;
        $started = false;
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->pdo->exec('SET TRANSACTION READ ONLY');
            } elseif ($driver !== 'sqlite') return null;
            $this->pdo->beginTransaction();
            $started = true;
            $snapshot = $source === 'conversation' ? $this->conversation($sourceId) : $this->archive($sourceId);
            $this->pdo->commit();
            $started = false;
            return $snapshot;
        } catch (Throwable $ignored) {
            // Source identities, text, metadata and SQL never enter logs.
            throw new RuntimeException('virtual_reply_source_unavailable');
        } finally {
            if ($started && $this->pdo->inTransaction()) {
                try { $this->pdo->rollBack(); } catch (Throwable $ignored) {}
            }
        }
    }

    private function conversation(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT m.*,c.project_key,c.is_test,c.channel AS scope_channel FROM messages m JOIN conversations c ON c.id=m.conversation_id JOIN projects p ON p.project_key=c.project_key WHERE m.id=? AND c.project_key=? AND c.is_test=0 AND p.is_active=1');
        $q->execute([$id, 'anytour']);
        $question = $q->fetch(PDO::FETCH_ASSOC);
        if (!$question) return null;
        $meta = $this->metadata($question['metadata_json'] ?? null);
        $created = $this->time($question['created_at'] ?? null);
        if ($meta === null || $created === null || !$this->ordinaryContent($question, $meta)
            || ($question['sender_type'] ?? '') !== 'customer' || ($question['direction'] ?? '') !== 'inbound') return null;
        $edited = array_key_exists('edited_at', $meta) ? $this->time($meta['edited_at']) : $created;
        if ($edited === null || $edited < $created) return null;
        $reference = max($created, $edited);
        [$rows,$incomplete]=$this->prefixRows('messages','conversation_id=? AND id<=? AND created_at<=?',
            [(int)$question['conversation_id'],$id,gmdate('Y-m-d H:i:s',$reference)],'id',
            "LENGTH(COALESCE(text,''))+LENGTH(COALESCE(metadata_json,''))",
            'id,direction,sender_type,text,metadata_json,created_at');
        $messages = [];
        $reconstructed = $edited > $created;
        foreach ($rows as $row) {
            $metadata = $this->metadata($row['metadata_json'] ?? null);
            $at = $this->time($row['created_at'] ?? null);
            if ($metadata === null || $at === null) { $incomplete = true; continue; }
            if (!$this->ordinaryContent($row, $metadata)) {
                if (!empty($metadata['attachments']) || !empty($metadata['is_deleted'])) $incomplete = true;
                continue;
            }
            $version = array_key_exists('edited_at', $metadata) ? $this->time($metadata['edited_at']) : $at;
            $unavailable = $version === null || $version < $at || $version > $reference;
            if ($unavailable) { $incomplete = true; $reconstructed = true; }
            $messages[] = $this->message((int)$row['id'], (string)$row['sender_type'],
                $unavailable ? '[Исходный текст на этот момент недоступен: сообщение изменено позже или время изменения неизвестно.]' : (string)$row['text'],
                $at, $metadata, $unavailable);
        }
        $scope = 'conversation:'.(int)$question['conversation_id'];
        return $this->result('conversation', $id, $scope, $reference, 'live', $rows,
            ['project_key'=>'anytour', 'is_active'=>1, 'is_test'=>0, 'channel'=>(string)$question['scope_channel']],
            $messages, $incomplete, $reconstructed, $edited > $created, ['source_id'=>$id, 'source_order'=>'database_message_id']);
    }

    private function archive(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT m.* FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE m.id=? AND m.project_key=? AND p.is_active=1');
        $q->execute([$id, 'anytour']);
        $question = $q->fetch(PDO::FETCH_ASSOC);
        if (!$question || (int)$question['is_deleted'] !== 0) return null;
        $record = $this->archiveRecord($question);
        if ($record === null || ($record['sender_type'] ?? '') !== 'customer' || ($record['direction'] ?? '') !== 'inbound'
            || !$this->hasText((string)($record['text'] ?? ''))) return null;
        $created = $this->time($record['created_at'] ?? null);
        $version = $this->time($record['version_at'] ?? null);
        if ($created === null || $version === null || $version < $created) return null;
        $reference = max($created, $version);
        // Current approval grants the selected account's stored history. Old
        // connection IDs are evidence, not a replacement for current permission.
        $sql = 'SELECT a.bot_key,a.account_id,a.connection_id,a.approved,a.provider_enabled,a.manager_id,c.is_enabled,c.account_id AS connected_account FROM telegram_archive_accounts a JOIN telegram_dialogue_connections c ON c.project_key=a.project_key AND c.bot_key=a.bot_key AND c.connection_id=a.connection_id WHERE a.project_key=? AND a.account_id=? AND a.approved=1 AND a.provider_enabled=1 AND c.is_enabled=1 AND c.account_id=a.account_id';
        $args = ['anytour', (string)$question['account_id']];
        if (is_string($record['bot_key'] ?? null) && $record['bot_key'] !== '') {
            $sql .= ' AND a.bot_key=?';
            $args[] = $record['bot_key'];
        }
        $q = $this->pdo->prepare($sql.' ORDER BY a.bot_key,a.connection_id');
        $q->execute($args);
        $permission = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$permission) return null;
        // Native IDs preserve Telegram order even when desktop history arrives
        // after fresh messages and therefore has newer database row IDs.
        [$rows,$incomplete]=$this->prefixRows('telegram_dialogue_messages',
            'project_key=? AND account_id=? AND chat_id=? AND message_id<=?',
            ['anytour',(string)$question['account_id'],(string)$question['chat_id'],(string)$question['message_id']],
            'message_id',"LENGTH(COALESCE(record_json,''))",'*');
        $messages = [];
        $reconstructed = $version > $created;
        foreach ($rows as $row) {
            $item = $this->archiveRecord($row);
            if ($item === null) { $incomplete = true; continue; }
            $at = $this->time($item['created_at'] ?? null);
            if ($at === null || $at > $reference) { $incomplete = true; continue; }
            $deleted = (int)$row['is_deleted'] !== 0;
            $changed = $this->time($item['version_at'] ?? null);
            $unavailable = $deleted || $changed === null || $changed < $at || $changed > $reference;
            if (!$unavailable && !$this->hasText((string)($item['text'] ?? ''))) {
                if (!empty($item['attachments'])) $incomplete = true;
                continue;
            }
            if ($unavailable) { $incomplete = true; $reconstructed = true; }
            $text = $deleted ? '[Исходный текст недоступен: сообщение удалено, момент удаления неизвестен.]'
                : ($unavailable ? '[Исходный текст на этот момент недоступен: сообщение изменено позже или время изменения неизвестно.]' : (string)$item['text']);
            $messages[] = $this->message((int)$row['id'], (string)($item['sender_type'] ?? 'unknown'), $text, $at, $item, $unavailable);
        }
        $scope = 'telegram_archive:'.(string)$question['account_id'].':'.(string)$question['chat_id'];
        return $this->result('telegram_archive', $id, $scope, $reference,
            $question['source_kind'] === 'desktop_import' ? 'historical_replay' : 'live', $rows,
            ['project_key'=>'anytour', 'is_active'=>1, 'capture_permissions'=>$permission],
            $messages, $incomplete, $reconstructed, $version > $created,
            ['source_id'=>$id, 'source_order'=>'telegram_native_message_id', 'native_message_id'=>(string)$question['message_id']]);
    }

    private function result(string $source, int $id, string $scope, int $reference, string $mode, array $rows, array $eligibility,
        array $messages, bool $incomplete, bool $reconstructed, bool $revised, array $cutoff): ?array
    {
        if (!$messages || (int)$messages[count($messages)-1]['id'] !== $id) return null;
        $remaining = self::MAX_TEXT_CHARACTERS;
        $bounded = [];
        for ($i=count($messages)-1; $i>=0; --$i) {
            $message = $messages[$i];
            $length = $this->length($message['text']);
            if ($length > $remaining) {
                $incomplete = true;
                if (!$bounded) {
                    $message['text'] = $this->suffix($message['text'], $remaining);
                    $message['text_truncated'] = true;
                    $bounded[] = $message;
                }
                break;
            }
            $bounded[] = $message;
            $remaining -= $length;
        }
        $messages = array_reverse($bounded);
        $iso = gmdate('Y-m-d\TH:i:s\Z', $reference);
        $cutoff['reference_time'] = $iso;
        $payload = ['messages'=>$messages, 'history_incomplete'=>$incomplete, 'cutoff'=>$cutoff,
            'historical_context_reconstructed'=>$reconstructed, 'revised_question'=>$revised];
        return ['project_key'=>'anytour', 'source_kind'=>$source, 'source_id'=>$id, 'source_scope'=>$scope,
            'source_revision'=>hash('sha256', json_encode(['schema'=>'virtual_reply_source_v1', 'scope'=>$scope,
                'eligibility'=>$eligibility, 'source_rows'=>$rows, 'payload'=>$payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'reference_time'=>$iso, 'mode'=>$mode, 'evidence_message_ids'=>array_column($messages, 'id'), 'payload'=>$payload];
    }

    /** Preflight lengths inside the same snapshot; never materialize 120 large JSON blobs. */
    private function prefixRows(string $table,string $where,array $args,string $order,string $size,string $columns): array
    {
        $q=$this->pdo->prepare('SELECT id,'.$size.' AS raw_units FROM '.$table.' WHERE '.$where.' ORDER BY '.$order.' DESC LIMIT '.(self::MAX_MESSAGES+1));
        $q->execute($args);$sizes=$q->fetchAll(PDO::FETCH_ASSOC);
        $incomplete=count($sizes)>self::MAX_MESSAGES;$ids=[];$units=0;
        foreach (array_slice($sizes,0,self::MAX_MESSAGES) as $row) {
            $next=(int)$row['raw_units'];
            if ($next<0||$next>self::MAX_RAW_UNITS-$units) {$incomplete=true;break;}
            $ids[]=(int)$row['id'];$units+=$next;
        }
        if (!$ids) return [[],true];
        $q=$this->pdo->prepare('SELECT '.$columns.' FROM '.$table.' WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY '.$order.' ASC');
        $q->execute($ids);
        return [$q->fetchAll(PDO::FETCH_ASSOC),$incomplete];
    }

    private function message(int $id, string $sender, string $text, int $created, array $metadata, bool $unavailable): array
    {
        $selection = false; $links = false;
        $text = ManagerAiTextRedactor::redact($text, $selection, $links);
        $text = preg_replace('/(?<![\pL\pN_])@[A-Za-z][A-Za-z0-9_]{3,31}\b/u', '[контакт скрыт]', $text) ?? $text;
        return ['id'=>$id, 'sender_type'=>match ($sender) { 'customer'=>'tourist', 'manager'=>'manager', 'ai','system','bot'=>'bot', default=>'unknown' },
            'text'=>$text, 'created_at'=>gmdate('Y-m-d\TH:i:s\Z', $created), 'event_type'=>'message',
            'media_unread'=>!empty($metadata['attachments']) || !empty($metadata['linked_message']),
            'links_redacted'=>$links || !empty($metadata['links']), 'selections_link_unread'=>$selection,
            'source_text_unavailable'=>$unavailable];
    }

    private function ordinaryContent(array $row, array $metadata): bool
    {
        if (($metadata['type'] ?? 'message') !== 'message' || !empty($metadata['is_deleted'])) return false;
        $text = (string)($row['text'] ?? '');
        // Recorder synthesizes this preview when the actual message has no text.
        if (!empty($metadata['attachments']) && str_starts_with($text, '📎 ')) return false;
        return $this->hasText($text);
    }

    private function hasText(string $text): bool
    {
        if (preg_match('//u', $text) !== 1 || preg_match('/[^\s\pZ\pC]/u', $text) !== 1
            || preg_match('~\A\s*/start(?:\s+\S+)?\s*\z~iu', $text) === 1) return false;
        // Legacy imports label private service peers as customers. Reject the
        // explicit non-tourist content at both question and prefix boundaries.
        if (preg_match('~\A\s*(?:тестирую|тест(?:\s+(?:архива|связи))?(?:\s+[0-9]{1,8})?)[.!…\s]*\z~iu', $text) === 1) return false;
        $login = preg_match('~\A\s*(?:Код\s+для\s+входа\s+в\s+(?:ваш\s+)?аккаунт\s+Telegram|Login\s+code)\s*:\s*[0-9]{4,8}\b~iu', $text) === 1;
        $telegram = preg_match('~\bTelegram\b~i', $text) === 1;
        $warning = preg_match('~(?:Не\s+давайте\s+код\s+никому|Do\s+not\s+give\s+this\s+code\s+to\s+anyone)~iu', $text) === 1;
        return !$login || !$telegram || !$warning;
    }

    private function archiveRecord(array $row): ?array
    {
        $record = $this->metadata($row['record_json'] ?? null);
        if ($record === null || !in_array($row['source_kind'] ?? '', ['business_message','desktop_import'], true)) return null;
        foreach (['account_id','chat_id','message_id'] as $key) {
            if (!isset($record[$key]) || (string)$record[$key] !== (string)$row[$key]) return null;
        }
        if (($record['source_kind'] ?? null) !== $row['source_kind'] || (bool)($record['is_deleted'] ?? false) !== (bool)$row['is_deleted']) return null;
        if (($record['version_at'] ?? null) !== null && (string)$record['version_at'] !== (string)$row['version_at']) return null;
        return $record;
    }

    private function metadata($raw): ?array
    {
        if ($raw === null || $raw === '') return [];
        if (!is_string($raw)) return null;
        try { $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (Throwable $ignored) { return null; }
        return is_array($value) ? $value : null;
    }

    private function time($value): ?int
    {
        if (is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]{0,11}\z/', $value))) {
            $timestamp = (int)$value;
            return $timestamp > 0 && $timestamp <= 253402300799 ? $timestamp : null;
        }
        if (!is_string($value)) return null;
        $format = preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value) ? 'Y-m-d H:i:s'
            : (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value) ? 'Y-m-d\TH:i:s\Z' : null);
        if ($format === null) return null;
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('UTC'));
        return $date && $date->format($format) === $value && $date->getTimestamp() > 0 ? $date->getTimestamp() : null;
    }

    private function validSource(string $source): bool { return in_array($source, ['conversation','telegram_archive'], true); }
    private function currentProject(): bool
    {
        return !class_exists('ProjectConfig', false) || ProjectConfig::projectId() === 'anytour';
    }
    private function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : (int)preg_match_all('/./us', $text);
    }
    private function suffix(string $text, int $length): string
    {
        if ($length <= 0) return '';
        if (function_exists('mb_substr')) return mb_substr($text, -$length, null, 'UTF-8');
        return implode('', array_slice(preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [], -$length));
    }
}
