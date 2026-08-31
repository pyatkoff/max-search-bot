<?php

declare(strict_types=1);

require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectConfig.php';

final class RuntimeStorage
{
    public static function usesMysql(): bool
    {
        return defined('MAX_SEARCH_RUNTIME_STORAGE')
            && strtolower(trim((string) MAX_SEARCH_RUNTIME_STORAGE)) === 'mysql';
    }

    public static function projectKey(): string
    {
        $key = trim(ProjectConfig::projectId());
        return $key !== '' ? $key : 'default';
    }

    public static function connection(): PDO
    {
        return ConversationDb::connection();
    }
}
