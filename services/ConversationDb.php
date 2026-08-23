<?php

/**
 * Dedicated PDO connection for the omnichannel conversation store.
 *
 * This service is intentionally isolated from Bitrix and the existing bot state.
 * Until the conversation recorder is introduced, merely adding this class does
 * not change production dialogue behavior.
 */
class ConversationDb
{
    private static $pdo;

    public static function isConfigured(): bool
    {
        return self::missingConfig() === [];
    }

    public static function missingConfig(): array
    {
        $required = [
            'CONVERSATION_DB_HOST',
            'CONVERSATION_DB_NAME',
            'CONVERSATION_DB_USER',
            'CONVERSATION_DB_PASS',
        ];

        $missing = [];
        foreach ($required as $name) {
            if (!defined($name) || trim((string)constant($name)) === '') {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        $missing = self::missingConfig();
        if ($missing) {
            throw new RuntimeException('Conversation DB is not configured: ' . implode(', ', $missing));
        }

        $host = (string)CONVERSATION_DB_HOST;
        $name = (string)CONVERSATION_DB_NAME;
        $user = (string)CONVERSATION_DB_USER;
        $pass = (string)CONVERSATION_DB_PASS;
        $charset = defined('CONVERSATION_DB_CHARSET') && trim((string)CONVERSATION_DB_CHARSET) !== ''
            ? (string)CONVERSATION_DB_CHARSET
            : 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $name, $charset);
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$pdo;
    }

    public static function ping(): array
    {
        $started = microtime(true);
        $pdo = self::connection();
        $value = $pdo->query('SELECT 1')->fetchColumn();

        return [
            'ok' => ((string)$value === '1'),
            'database' => (string)CONVERSATION_DB_NAME,
            'host' => (string)CONVERSATION_DB_HOST,
            'charset' => defined('CONVERSATION_DB_CHARSET') ? (string)CONVERSATION_DB_CHARSET : 'utf8mb4',
            'latency_ms' => round((microtime(true) - $started) * 1000, 1),
        ];
    }

    public static function resetForTests(): void
    {
        self::$pdo = null;
    }
}
