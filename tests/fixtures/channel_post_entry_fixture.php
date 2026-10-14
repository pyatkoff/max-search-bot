<?php
declare(strict_types=1);

/** Only legacy/external side effects are captured; all entry/source/DB owners are real. */
final class MaxSearchApi
{
    public static array $greetings = [], $resets = [], $cancelled = [], $editModes = [], $funnel = [], $goals = [], $deletedMessages = [];
    public static int $statusPhone = 75, $statusCheck = 76, $statusAi = 79;
    public static function getTrafficMeta($chat): array { return TrafficAttributionService::get(dirname(__DIR__, 2), $chat); }
    public static function getCurentStatus($chat): int { return 0; }
    public static function getLastClaimForChat($chat): array { return ['CODE' => 'fixture-existing-claim']; }
    public static function getSavedData($chat): array { return ['NAME' => 'Fixture Tourist', 'ADULTS' => '2']; }
    public static function saveClaim(...$args): bool { throw new RuntimeException('unexpected_claim_write'); }
    public static function deletePrevMessage($chat): void { self::$deletedMessages[] = $chat; }
    public static function addYclid(...$args): void { throw new RuntimeException('unexpected_yclid_mirror'); }
    public static function funnelLog($chat, $type, $payload = []): void { self::$funnel[] = compact('chat', 'type', 'payload'); }
    public static function queueMetrikaGoal($chat, $goal): bool { self::$goals[] = compact('chat', 'goal'); return true; }
    public static function cancelToursFollowup($chat): void { self::$cancelled[] = $chat; }
    public static function deleteAllStatus($chat): void { self::$resets[] = $chat; }
    public static function setEditMode($chat, $value): void { self::$editModes[] = [$chat, $value]; }
    public static function showStart($chat, $meta = []): bool { self::$greetings[] = compact('chat', 'meta'); return true; }
}

function createChannelPostEntryFixture(): array
{
    $root = dirname(__DIR__, 2);
    foreach (['CONVERSATION_DB_HOST' => 'fixture', 'CONVERSATION_DB_NAME' => 'fixture', 'CONVERSATION_DB_USER' => 'fixture', 'CONVERSATION_DB_PASS' => 'fixture'] as $name => $value) if (!defined($name)) define($name, $value);
    require_once $root . '/services/ChannelPostEntryService.php';
    require_once $root . '/handlers/MaxUpdateHandler.php';
    require_once $root . '/handlers/TelegramWebhookHandler.php';
    require_once __DIR__ . '/channel_post_registry_fixture.php';
    ProjectConfig::resetForTests([
        'id' => 'anytour', 'brand' => ['name' => 'AnyTour'],
        'routing' => ['source_key' => 'max:anytour-main'],
        'messenger' => ['provider' => 'max', 'telegram' => ['source_key' => 'telegram:anytour-main']],
        'channel_posts' => [
            'max' => ['bot_key' => 'id9704048781_1_bot', 'bot_url' => 'https://max.ru/id9704048781_1_bot'],
            'telegram' => ['bot_key' => 'Any_tour_bot', 'bot_url' => 'https://t.me/Any_tour_bot'],
        ],
    ]);
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('NOW', static fn() => gmdate('Y-m-d H:i:s'));
    $pdo->exec("CREATE TABLE conversations(id INTEGER PRIMARY KEY AUTOINCREMENT,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,external_chat_id TEXT,status TEXT DEFAULT 'ai',manager_id INTEGER,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,last_message_at TEXT,started_at TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE conversation_events(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,event_type TEXT,actor_type TEXT,actor_id TEXT,payload_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE customers(id INTEGER PRIMARY KEY AUTOINCREMENT,display_name TEXT);
        CREATE TABLE customer_channels(id INTEGER PRIMARY KEY AUTOINCREMENT,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT,username TEXT,metadata_json TEXT);
        CREATE TABLE messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,direction TEXT,sender_type TEXT,sender_id TEXT,channel TEXT,external_message_id TEXT,text TEXT,metadata_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE managers(id INTEGER PRIMARY KEY,is_active INTEGER,is_working INTEGER);
        CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
        CREATE TABLE manager_push_subscriptions(id INTEGER PRIMARY KEY,manager_id INTEGER,endpoint TEXT);");
    createChannelPostRegistryFixture($pdo);
    $pdo->exec("INSERT INTO projects(id,project_key,display_name,is_active) VALUES (1,'anytour','AnyTour',1),(2,'other','Other',1);
        INSERT INTO conversation_sources(id,project_id,source_key,display_name,channel,is_active,handling_mode) VALUES
        (1,1,'max:anytour-main','MAX main','max',1,'ai'),(2,1,'max_anytour_msk1','MAX posts','max',1,'manager'),
        (3,1,'telegram:anytour-main','TG main','telegram',1,'ai'),(4,1,'tg_anytour_msk','TG posts','telegram',1,'manager'),
        (5,1,'max_fixture_ask','MAX ask','max',1,'ask'),(6,1,'tg_fixture_ask','TG ask','telegram',1,'ask'),
        (7,1,'max_fixture_ai','MAX AI','max',1,'ai'),(8,2,'other_max','Other','max',1,'manager'),
        (9,1,'tg_fixture_ai','TG AI','telegram',1,'ai');");
    (new ReflectionProperty(ConversationDb::class, 'pdo'))->setValue(null, $pdo);
    ProjectAccessService::initializeReadOnly();
    $registry = new ChannelPostRegistryService($pdo);
    $publications = [];
    $settings = [
        'max' => ['bot' => 'id9704048781_1_bot', 'source' => 'max_anytour_msk1', 'id' => '-80095447388179', 'url' => 'https://max.ru/id9704048781_1_bot'],
        'telegram' => ['bot' => 'Any_tour_bot', 'source' => 'tg_anytour_msk', 'id' => '-1001234567890', 'url' => 'https://t.me/Any_tour_bot'],
    ];
    foreach ($settings as $platform => $setting) {
        $registry->configureChannel(['project_key' => 'anytour', 'platform' => $platform, 'bot_key' => $setting['bot'], 'channel_id' => $setting['id'], 'source_key' => $setting['source'], 'bot_url' => $setting['url'], 'is_enabled' => true]);
        $publications[$platform] = $registry->registerPublication('anytour', $platform, $setting['bot'], $setting['id'], $platform === 'max' ? 'mid.fixture-entry' : '31', ['text' => $platform . ' исходный тур', 'format' => 'native', 'published_at' => '2026-10-14 08:00:00', 'post_url' => $platform === 'max' ? 'https://max.ru/c/-80095447388179/fixture' : 'https://t.me/c/1234567890/31']);
    }
    return [$pdo, $registry, $publications];
}
