<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectConfig.php';
require_once __DIR__ . '/../integrations/MaxCredentialProvider.php';

/** Trusted server configuration. Request data may select a key, never credentials. */
final class ProjectMaxConnection
{
    private static ?array $testRegistry = null;
    private ProjectConfiguration $settings;
    private string $botKey;
    private string $token;
    private string $webhookSecret;

    private function __construct(ProjectConfiguration $settings, string $botKey, string $token, string $secret)
    {
        $this->settings = $settings;
        $this->botKey = $botKey;
        $this->token = $token;
        $this->webhookSecret = $secret;
    }

    public static function resolve(string $projectKey): ?self
    {
        if (!preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D', $projectKey)) return null;
        if (self::isLegacyProject($projectKey)) {
            // Reading an existing local archive does not require a live token.
            return new self(ProjectConfig::forProject($projectKey, ProjectConfig::all()),
                (string)ProjectConfig::get('channel_posts.max.bot_key', ''),
                MaxCredentialProvider::token(), MaxCredentialProvider::webhookSecret());
        }
        $registry = self::$testRegistry ?? (defined('MAX_SEARCH_PROJECT_CONNECTIONS') && is_array(MAX_SEARCH_PROJECT_CONNECTIONS) ? MAX_SEARCH_PROJECT_CONNECTIONS : []);
        $entry = $registry[$projectKey] ?? null;
        if (!is_array($entry) || !is_array($entry['project'] ?? null) || !is_array($entry['max'] ?? null)) return null;
        $max = $entry['max'];
        $bot = $max['bot_key'] ?? null;
        $token = $max['token'] ?? null;
        $secret = $max['webhook_secret'] ?? null;
        if (!is_string($bot) || !preg_match('/\A[A-Za-z0-9_]{1,191}\z/D', $bot)
            || !self::validCredential($token, 8, 4096) || !self::validCredential($secret, 32, 256)) return null;

        // Reusing another company's bot/token/secret would make scope ambiguous.
        $legacy = [
            'bot_key'=>(string)ProjectConfig::get('channel_posts.max.bot_key', ''),
            'token'=>MaxCredentialProvider::token(),
            'webhook_secret'=>MaxCredentialProvider::webhookSecret(),
        ];
        if (self::sameConnection($max, $legacy)) return null;
        foreach ($registry as $key=>$other) {
            if ($key === $projectKey || !is_array($other) || !is_array($other['max'] ?? null)) continue;
            if (self::sameConnection($max, $other['max'])) return null;
        }
        try {
            return new self(ProjectConfig::forProject($projectKey, $entry['project']), $bot, $token, $secret);
        } catch (InvalidArgumentException $error) {
            return null;
        }
    }

    public static function authenticate(string $projectKey, string $secret): ?self
    {
        $connection = self::resolve($projectKey);
        if (!$connection || $connection->webhookSecret === '' || $secret === ''
            || !hash_equals($connection->webhookSecret, $secret)) return null;
        return $connection;
    }

    /** The saved bot binding prevents reconfiguration from retargeting history. */
    public static function forConversation(array $conversation): ?self
    {
        if (($conversation['channel'] ?? null) !== 'max' || !is_string($conversation['project_key'] ?? null)) return null;
        $connection = self::resolve($conversation['project_key']);
        if (!$connection || self::isLegacyConversation($conversation)) return $connection;
        $id = (int)($conversation['id'] ?? 0);
        if ($id <= 0) return null;
        try {
            require_once __DIR__ . '/ConversationDb.php';
            $query = ConversationDb::connection()->prepare('SELECT metadata_json FROM conversations WHERE id=? AND project_key=? AND channel=? LIMIT 1');
            $query->execute([$id, $connection->projectKey(), 'max']);
            $metadata = json_decode((string)$query->fetchColumn(), true);
            return is_array($metadata) && ($metadata['max_bot_key'] ?? null) === $connection->botKey() ? $connection : null;
        } catch (Throwable $error) {
            return null;
        }
    }

    public static function isLegacyProject(string $projectKey): bool
    {
        return $projectKey !== '' && $projectKey === ProjectConfig::projectId();
    }

    public static function isLegacyConversation(array $conversation): bool
    {
        return ($conversation['channel'] ?? null) === 'max'
            && self::isLegacyProject((string)($conversation['project_key'] ?? ''));
    }

    public function projectKey(): string { return $this->settings->projectId(); }
    public function botKey(): string { return $this->botKey; }
    public function token(): string { return $this->token; }
    public function webhookSecret(): string { return $this->webhookSecret; }
    public function baseUrl(): string { return MaxCredentialProvider::apiBaseUrl(); }
    public function settings(): ProjectConfiguration { return $this->settings; }

    public function __debugInfo(): array
    {
        return ['project_key'=>$this->projectKey(), 'bot_key'=>$this->botKey, 'credentials'=>'[redacted]'];
    }

    /** Test dependency injection cannot be enabled by an HTTP request. */
    public static function resetForTests(?array $registry = null): void
    {
        if (PHP_SAPI !== 'cli') throw new LogicException('test_override_requires_cli');
        self::$testRegistry = $registry;
    }

    private static function validCredential($value, int $min, int $max): bool
    {
        return is_string($value) && strlen($value) >= $min && strlen($value) <= $max
            && !preg_match('/[\x00-\x20\x7f]/', $value);
    }

    private static function sameConnection(array $one, array $other): bool
    {
        foreach (['bot_key','token','webhook_secret'] as $field) {
            $left = $one[$field] ?? null;
            $right = $other[$field] ?? null;
            if (!is_string($left) || !is_string($right) || $left === '' || $right === '') continue;
            if ($field === 'bot_key' ? strcasecmp($left, $right) === 0 : hash_equals($left, $right)) return true;
        }
        return false;
    }
}
