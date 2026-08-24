<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectConfig.php';

class WebsiteSessionService
{
    private const SOURCE_KEY = 'website:anytour-main';
    private static $ready = false;

    public static function sourceKey(): string { return self::SOURCE_KEY; }

    public static function ensureSchema(): void
    {
        if (self::$ready) return;
        $pdo = ConversationDb::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS website_chat_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token_hash CHAR(64) NOT NULL,
            external_user_id VARCHAR(96) NOT NULL,
            chat_id BIGINT UNSIGNED NOT NULL,
            source_key VARCHAR(96) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_website_chat_token (token_hash),
            UNIQUE KEY uq_website_chat_user (external_user_id),
            UNIQUE KEY uq_website_chat_chat (chat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::$ready = true;
    }

    public static function resolve(?string $token): array
    {
        self::ensureSchema();
        $token = strtolower(trim((string)$token));
        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            $hash = hash('sha256', $token);
            $q = ConversationDb::connection()->prepare('SELECT external_user_id,chat_id,source_key FROM website_chat_sessions WHERE token_hash=? LIMIT 1');
            $q->execute([$hash]);
            $row = $q->fetch();
            if ($row) {
                ConversationDb::connection()->prepare('UPDATE website_chat_sessions SET last_seen_at=NOW() WHERE token_hash=?')->execute([$hash]);
                return ['token'=>$token,'external_user_id'=>(string)$row['external_user_id'],'chat_id'=>(int)$row['chat_id'],'source_key'=>(string)$row['source_key']];
            }
        }
        return self::create();
    }

    private static function create(): array
    {
        $pdo = ConversationDb::connection();
        for ($i=0; $i<10; $i++) {
            $token = bin2hex(random_bytes(32));
            $external = 'web_' . bin2hex(random_bytes(16));
            $chatId = random_int(100000000, 2000000000);
            try {
                $q = $pdo->prepare('INSERT INTO website_chat_sessions (token_hash,external_user_id,chat_id,source_key) VALUES (?,?,?,?)');
                $q->execute([hash('sha256',$token),$external,$chatId,self::SOURCE_KEY]);
                return ['token'=>$token,'external_user_id'=>$external,'chat_id'=>$chatId,'source_key'=>self::SOURCE_KEY];
            } catch (Throwable $e) {
                if ($i === 9) throw $e;
            }
        }
        throw new RuntimeException('Could not create website chat session');
    }

    public static function conversation(string $externalUserId): ?array
    {
        $pdo = ConversationDb::connection();
        $q = $pdo->prepare('SELECT c.id,c.status,c.manager_id,c.project_key,c.channel,c.last_message_at FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id WHERE cc.project_key=? AND cc.channel=? AND cc.external_user_id=? ORDER BY c.id DESC LIMIT 1');
        $q->execute([ProjectConfig::projectId(),'website',$externalUserId]);
        $row = $q->fetch();
        return $row ?: null;
    }

    public static function messages(string $externalUserId, int $afterId = 0): array
    {
        $conversation = self::conversation($externalUserId);
        if (!$conversation) return ['conversation'=>null,'messages'=>[]];
        $afterId = max(0,$afterId);
        $q = ConversationDb::connection()->prepare('SELECT id,direction,sender_type,text,metadata_json,created_at FROM messages WHERE conversation_id=? AND id>? ORDER BY id ASC LIMIT 250');
        $q->execute([(int)$conversation['id'],$afterId]);
        $messages = [];
        foreach ($q->fetchAll() as $row) {
            $meta = [];
            if (!empty($row['metadata_json'])) {
                $decoded = json_decode((string)$row['metadata_json'], true);
                if (is_array($decoded)) $meta = $decoded;
            }
            $text = (string)$row['text'];
            if (($row['sender_type'] ?? '') === 'manager') $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $messages[] = [
                'id'=>(int)$row['id'],
                'direction'=>(string)$row['direction'],
                'sender_type'=>(string)$row['sender_type'],
                'text'=>$text,
                'buttons'=>is_array($meta['buttons'] ?? null) ? $meta['buttons'] : [],
                'created_at'=>(string)$row['created_at'],
            ];
        }
        return ['conversation'=>$conversation,'messages'=>$messages];
    }
}
