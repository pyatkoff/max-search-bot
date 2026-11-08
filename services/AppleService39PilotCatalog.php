<?php
declare(strict_types=1);

/** The reviewed pilot entries shared by the fixed publisher and repair ingress. */
final class AppleService39PilotCatalog
{
    public const PROJECT = 'appleservice39';
    public const BOT = 'id390521129062_bot';
    private const MODELS = [
        'iphone-x'=>'iPhone X',
        'iphone-15'=>'iPhone 15',
        'iphone-15-plus'=>'iPhone 15 Plus',
        'iphone-15-pro'=>'iPhone 15 Pro',
        'iphone-15-pro-max'=>'iPhone 15 Pro Max',
    ];
    private const SERVICES = [
        'battery_replacement'=>['battery-replacement','Замена аккумулятора'],
        'front_glass_replacement'=>['front-glass-replacement','Замена стекла без дисплея'],
        'display'=>['display','Замена дисплея'],
    ];
    private static ?array $catalog = null;

    private static function check(bool $ok): void
    {
        if (!$ok) throw new RuntimeException('appleservice39_pilot_catalog_invalid');
    }

    private static function expected(): array
    {
        $expected = [];
        foreach (self::MODELS as $key=>$model) {
            foreach (self::SERVICES as $serviceKey=>[$suffix,$service]) {
                $expected['repair-'.$key.'-'.$suffix] = [$model,$serviceKey,$service];
            }
        }
        return $expected;
    }

    private static function text($value, int $limit): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= $limit
            && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value) === 0;
    }

    /** No provider request, runtime configuration or publication state is consulted. */
    public static function entries(): array
    {
        if (self::$catalog !== null) return array_values(self::$catalog);
        $raw = @file_get_contents(__DIR__.'/../resources/appleservice39-pilot-entries.json', false, null, 0, 65537);
        self::check(is_string($raw) && strlen($raw) <= 65536);
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        self::check(is_array($data) && ($data['schema'] ?? null) === 1
            && ($data['project_key'] ?? null) === self::PROJECT && ($data['bot_key'] ?? null) === self::BOT
            && is_string($data['checked_date'] ?? null) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $data['checked_date']) === 1
            && is_string($data['source_posts_sha256'] ?? null) && preg_match('/\A[0-9a-f]{64}\z/D', $data['source_posts_sha256']) === 1
            && is_array($data['entries'] ?? null) && array_is_list($data['entries']) && count($data['entries']) === 15);
        $expected = self::expected();
        $catalog = [];
        foreach ($data['entries'] as $entry) {
            self::check(is_array($entry) && is_string($entry['post_id'] ?? null));
            $id = $entry['post_id'];
            self::check(isset($expected[$id]) && !isset($catalog[$id]));
            [$model,$serviceKey,$service] = $expected[$id];
            self::check(($entry['model'] ?? null) === $model && ($entry['service_key'] ?? null) === $serviceKey
                && ($entry['service'] ?? null) === $service && in_array($entry['price_kind'] ?? '', ['listed','variants','on_request'], true)
                && self::text($entry['price_text'] ?? null, 1600) && self::text($entry['question'] ?? null, 1000)
                && is_array($entry['source_ids'] ?? null) && array_is_list($entry['source_ids']) && count($entry['source_ids']) >= 1
                && count($entry['source_ids']) <= 20 && is_array($entry['source_urls'] ?? null)
                && array_is_list($entry['source_urls']) && count($entry['source_urls']) >= 1 && count($entry['source_urls']) <= 20);
            foreach ($entry['source_ids'] as $sourceId) {
                self::check(is_string($sourceId) && preg_match('/\Aas39_[0-9a-f]{20}\z/D', $sourceId) === 1);
            }
            foreach ($entry['source_urls'] as $url) {
                self::check(is_string($url) && strlen($url) <= 512
                    && preg_match('~\Ahttps://appleservice39\.ru/[a-zA-Z0-9/_-]+\z~D', $url) === 1);
            }
            $entry['start_payload'] = 'as39_'.$id;
            self::check(strlen($entry['start_payload']) <= 64);
            $catalog[$id] = $entry;
        }
        self::check(count($catalog) === count($expected));
        self::$catalog = $catalog;
        return array_values($catalog);
    }

    public static function forPost(string $postId): ?array
    {
        $expected = self::expected();
        if (!isset($expected[$postId])) return null;
        self::entries();
        return self::$catalog[$postId] ?? null;
    }

    public static function payloadForPost(string $postId): ?string
    {
        $entry = self::forPost($postId);
        return $entry['start_payload'] ?? null;
    }

    public static function resolve(string $projectKey, string $botKey, string $payload): ?array
    {
        if ($projectKey !== self::PROJECT || $botKey !== self::BOT) return null;
        if ($payload === 'as39_x_battery') return self::forPost('repair-iphone-x-battery-replacement');
        return str_starts_with($payload, 'as39_') ? self::forPost(substr($payload, 5)) : null;
    }

    /** Capture this text once in the entry event before attempting delivery. */
    public static function responseText(array $entry, string $brand): string
    {
        return 'Здравствуйте! Это '.$brand.".\n".$entry['service'].' — '.$entry['model'].".\n"
            .$entry['price_text']."\n".$entry['question'];
    }
}
