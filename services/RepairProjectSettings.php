<?php
declare(strict_types=1);

require_once __DIR__.'/ProjectMaxConnection.php';
require_once __DIR__.'/AppleService39PilotCatalog.php';

/** Pure descriptors from the authenticated connection's immutable server settings. */
final class RepairProjectSettings
{
    public static function businessKind(ProjectConfiguration $settings): ?string
    {
        $business = $settings->get('business');
        if (!is_array($business)) return null;
        foreach (['kind','profile'] as $key) {
            if (array_key_exists($key, $business) && (!is_string($business[$key])
                || preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D', $business[$key]) !== 1)) return null;
        }
        if (isset($business['kind'], $business['profile']) && $business['kind'] !== $business['profile']) return null;
        return $business['kind'] ?? $business['profile'] ?? null;
    }

    /** No request-supplied URL, arbitrary host, implicit registration or legacy removal. */
    public static function webhook(ProjectMaxConnection $connection): ?array
    {
        $settings = $connection->settings();
        $project = $connection->projectKey();
        if (ProjectMaxConnection::isLegacyProject($project) || self::businessKind($settings) !== 'device_repair') return null;
        $source = $settings->get('routing.source_key');
        if (!is_string($source) || preg_match('/\Amax:[A-Za-z0-9_-]{1,150}\z/D', $source) !== 1) return null;
        if ($project === AppleService39PilotCatalog::PROJECT) {
            if ($connection->botKey() !== AppleService39PilotCatalog::BOT || $source !== 'max:appleservice39-main') return null;
            $url = 'https://app.anytoour.ru/appleservice39_webhook.php';
            $legacy = ['https://tour-max.ru/new/appleservice39/webhook.php'];
        } else {
            $url = 'https://app.anytoour.ru/project_max_webhook.php?project='.$project;
            if ($settings->get('messenger.max.webhook_url') !== $url) return null;
            $legacy = [];
        }
        return ['project_key'=>$project,'bot_key'=>$connection->botKey(),'source_key'=>$source,'url'=>$url,'legacy_urls'=>$legacy];
    }

    /** Finite reviewed entry data; publication IDs and provider URLs are not invented. */
    public static function entry(ProjectMaxConnection $connection, string $payload): ?array
    {
        if ($connection->projectKey() === AppleService39PilotCatalog::PROJECT) {
            return AppleService39PilotCatalog::resolve($connection->projectKey(), $connection->botKey(), $payload);
        }
        $scope = self::webhook($connection);
        if ($scope === null || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $payload) !== 1
            || preg_match('/\A(?:p_|as39_|ts_|s3_)/D', $payload) === 1) return null;
        $entries = $connection->settings()->get('business.repair.entries');
        if (!is_array($entries) || count($entries) > 128) return null;
        $entry = $entries[$payload] ?? null;
        if (!is_array($entry) || count($entry) !== 2 || !self::label($entry['model'] ?? null)
            || !self::label($entry['service'] ?? null)) return null;
        $brand = $connection->settings()->get('brand.name', $connection->projectKey());
        if (!self::label($brand)) return null;
        $fingerprint = hash('sha256', json_encode([$scope,$payload,$brand,$entry['model'],$entry['service']], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        return ['post_id'=>$payload,'model'=>$entry['model'],'service'=>$entry['service'],
            'start_payload'=>$payload,'config_sha256'=>$fingerprint];
    }

    public static function responseText(array $entry, string $brand): string
    {
        if (!isset($entry['config_sha256'])) return AppleService39PilotCatalog::responseText($entry, $brand);
        return 'Здравствуйте! Это '.$brand.".\n".$entry['model'].' — '.mb_strtolower($entry['service'], 'UTF-8')
            .".\nРасскажите, что случилось с устройством. Можно приложить фото — менеджер увидит ваше сообщение здесь.";
    }

    /** Old Apple intents keep their original contract; configured pending text is revision-bound. */
    public static function pendingEntryMatches(ProjectMaxConnection $connection, array $intent): bool
    {
        if ($connection->projectKey() === AppleService39PilotCatalog::PROJECT) {
            return $connection->botKey() === AppleService39PilotCatalog::BOT;
        }
        $code = $intent['entry_code'] ?? null;
        $sha = $intent['config_sha256'] ?? null;
        if (!is_string($code) || !is_string($sha) || preg_match('/\A[a-f0-9]{64}\z/D', $sha) !== 1) return false;
        $entry = self::entry($connection, $code);
        if ($entry === null || !hash_equals($entry['config_sha256'], $sha) || ($intent['post_id'] ?? null) !== $entry['post_id']) return false;
        $brand = $connection->settings()->get('brand.name', $connection->projectKey());
        return ($intent['text'] ?? null) === self::responseText($entry, $brand);
    }

    private static function label($value): bool
    {
        return is_string($value) && $value !== '' && trim($value) === $value && strlen($value) <= 320
            && preg_match('/[\p{C}<>&]/u', $value) === 0;
    }
}
