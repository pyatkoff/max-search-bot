<?php
declare(strict_types=1);
require_once __DIR__.'/TelegramArchiveBotConfig.php';
require_once __DIR__.'/TelegramArchiveAccountRegistry.php';
require_once __DIR__.'/TelegramBusinessArchiveRuntime.php';

/** Dedicated transport. Ordinary bot updates are acknowledged without dispatch. */
final class TelegramArchiveBotRuntime
{
    public static function authenticated(?array $config, string $provided): bool
    {
        return $config !== null && is_string($config['secret'] ?? null) && $config['secret'] !== '' && hash_equals($config['secret'], $provided);
    }

    public static function receive(array $update, array $config, ?PDO $pdo = null): array
    {
        if (!self::authenticated($config, (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) throw new RuntimeException('archive_webhook_forbidden');
        if (!TelegramDialogueNormalizer::isBusinessUpdate($update)) return ['state'=>'ignored'];
        $types = array_values(array_filter(TelegramDialogueNormalizer::UPDATE_TYPES, static fn(string $type): bool => array_key_exists($type, $update)));
        if (count($types) !== 1 || !is_array($update[$types[0]])) throw new RuntimeException('invalid_business_update');
        $project = TelegramDialogueArchive::key(ProjectConfig::projectId()); $bot = TelegramArchiveBotConfig::USERNAME;
        $archive = new TelegramDialogueArchive($pdo); $registry = new TelegramArchiveAccountRegistry($pdo);
        $data = $update[$types[0]];
        if ($types[0] === 'business_connection') {
            $sequence = TelegramDialogueNormalizer::sequence($update['update_id'] ?? null);
            $connection = TelegramDialogueNormalizer::connectionId($data['id'] ?? null);
            $account = TelegramDialogueNormalizer::id($data['user']['id'] ?? null);
            if (!is_bool($data['is_enabled'] ?? null)) throw new RuntimeException('invalid_business_connection');
            // Validate metadata before any connection write.
            TelegramArchiveAccountRegistry::validateConnection($data);
            $archive->saveConnection($project, $bot, $connection, $account, $data['is_enabled'], $sequence);
            $saved = $archive->connection($project, $bot, $connection);
            $registry->observe($project, $bot, $data, $sequence, (bool)$saved['is_enabled']);
            return ['state'=>'connection_registered'];
        }
        $connection = TelegramDialogueNormalizer::connectionId($data['business_connection_id'] ?? null);
        $registered = $archive->connection($project, $bot, $connection);
        if (!$registered) throw new RuntimeException('unknown_business_connection');
        $account = (string)$registered['account_id'];
        $row = $registry->account($project, $bot, $account);
        if (!$row || !(bool)$row['approved']) return ['state'=>'account_not_approved','stored'=>0];
        $capture = ['enabled'=>true,'bot_key'=>$bot,'accounts'=>[$account=>['manager_id'=>$row['manager_id'] === null ? null : (string)$row['manager_id']]]];
        return TelegramBusinessArchiveRuntime::receive($update, $capture, $archive, $config['secret'],
            static fn(string $id) => $registry->capturePermission($project, $bot, $id, $connection));
    }
}
