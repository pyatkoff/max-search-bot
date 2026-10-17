<?php
declare(strict_types=1);

require_once __DIR__.'/TelegramDialogueArchive.php';
require_once __DIR__.'/ProjectConfig.php';

/** Explicit opt-in capture only; business events never enter self-service dialogue. */
final class TelegramBusinessArchiveRuntime
{
    public static function receive(array $update, ?array $config = null, ?TelegramDialogueArchive $archive = null): array
    {
        if (!TelegramDialogueNormalizer::isBusinessUpdate($update)) return ['state'=>'unrecognized'];
        if ($config === null) {
            $raw = getenv('MAX_SEARCH_TELEGRAM_DIALOGUE_ARCHIVE');
            $config = $raw === false || $raw === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        }
        if (!is_array($config) || (array_key_exists('enabled', $config) && !is_bool($config['enabled']))) throw new RuntimeException('invalid_business_archive_config');
        if (($config['enabled'] ?? false) !== true) return ['state'=>'disabled'];
        $secret = defined('TELEGRAM_WEBHOOK_SECRET') ? (string)TELEGRAM_WEBHOOK_SECRET : '';
        $provided = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        if ($secret === '' || !hash_equals($secret, $provided)) throw new RuntimeException('business_webhook_auth_required');
        $types = array_values(array_filter(TelegramDialogueNormalizer::UPDATE_TYPES, static fn(string $type): bool => array_key_exists($type, $update)));
        if (count($types) !== 1 || !is_array($update[$types[0]])) throw new InvalidArgumentException('invalid_business_update');
        $sequence = TelegramDialogueNormalizer::sequence($update['update_id'] ?? null);
        $project = TelegramDialogueArchive::key(ProjectConfig::projectId());
        $bot = TelegramDialogueArchive::key((string)($config['bot_key'] ?? ''), 96);
        $accounts = $config['accounts'] ?? null;
        if (!is_array($accounts) || !$accounts) throw new RuntimeException('business_accounts_required');
        $type = $types[0]; $data = $update[$type];
        $connection = TelegramDialogueNormalizer::connectionId($type === 'business_connection' ? ($data['id'] ?? null) : ($data['business_connection_id'] ?? null));
        if ($type === 'business_connection') {
            $account = TelegramDialogueNormalizer::id($data['user']['id'] ?? null);
            if (!isset($accounts[$account])) return ['state'=>'account_not_allowed'];
            if (!is_bool($data['is_enabled'] ?? null)) throw new InvalidArgumentException('invalid_business_connection');
            ($archive ?? new TelegramDialogueArchive())->saveConnection($project, $bot, $connection, $account, $data['is_enabled'], $sequence);
            return ['state'=>$data['is_enabled'] ? 'connected' : 'disconnected'];
        }
        $archive = $archive ?? new TelegramDialogueArchive();
        $registered = $archive->connection($project, $bot, $connection);
        if (!$registered) throw new RuntimeException('unknown_business_connection');
        $account = (string)$registered['account_id'];
        if (!isset($accounts[$account])) return ['state'=>'account_not_allowed'];
        if (!(bool)$registered['is_enabled']) return ['state'=>'disconnected'];
        $accountConfig = $accounts[$account];
        if (!is_array($accountConfig)) throw new RuntimeException('invalid_business_account_config');
        $manager = isset($accountConfig['manager_id']) ? TelegramDialogueNormalizer::id($accountConfig['manager_id']) : null;
        $chat = TelegramDialogueNormalizer::id($data['chat']['id'] ?? null);
        if (isset($accountConfig['chat_ids'])) {
            if (!is_array($accountConfig['chat_ids']) || !$accountConfig['chat_ids']) return ['state'=>'chat_not_allowed'];
            if (!in_array($chat, array_map([TelegramDialogueNormalizer::class, 'id'], $accountConfig['chat_ids']), true)) return ['state'=>'chat_not_allowed'];
        }
        $records = [];
        if ($type === 'deleted_business_messages') {
            if (($data['chat']['type'] ?? '') !== 'private' || !is_array($data['message_ids'] ?? null) || count($data['message_ids']) > 10000) throw new InvalidArgumentException('invalid_business_deletion');
            foreach ($data['message_ids'] as $id) $records[] = TelegramDialogueNormalizer::tombstone($account, $chat, TelegramDialogueNormalizer::id($id), $connection, $sequence);
        } else $records[] = TelegramDialogueNormalizer::businessMessage($data, $account, $connection, $sequence);
        return $archive->receive($project, $bot, $connection, $records, $manager);
    }
}
