<?php
declare(strict_types=1);

/** Stable across MySQL JSON storage normalization; raw request bytes are never stored. */
final class Max2CommonFrameV2
{
    public static function canonical(array $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (!is_array($v)) return $v;
            if (!array_is_list($v)) ksort($v, SORT_STRING);
            foreach ($v as &$item) $item = $sort($item);
            unset($item); return $v;
        };
        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    public static function id(mixed $v): bool { return is_string($v) && preg_match('/\A[1-9][0-9]{0,19}\z/D', $v) === 1; }
    public static function sha(mixed $v): bool { return is_string($v) && preg_match('/\A[a-f0-9]{64}\z/D', $v) === 1; }
    public static function active(array $a, int $now): bool
    {
        return in_array($a['mode'] ?? null, ['collect','send'], true) && ($a['counter_id'] ?? null) === 98615635
            && ($a['goal_id'] ?? null) === 687941321 && ($a['target'] ?? null) === 'anytour_modeled_value_v2_max2'
            && is_int($a['activated_at_unix'] ?? null) && $a['activated_at_unix'] > 1795262676 && $a['activated_at_unix'] <= $now
            && self::sha($a['activation_sha256'] ?? null);
    }
    public static function messageKey(array $subject, string $messageId): string
    {
        return hash('sha256', self::canonical(['purpose'=>'max2/common/message-frame/v2','subject'=>$subject,'message_id'=>$messageId]));
    }
    public static function decoded(mixed $json, mixed $sha): ?array
    {
        if (!is_string($json) || strlen($json) > 16384 || !self::sha($sha)) return null;
        try {
            $v = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            return is_array($v) && hash_equals($sha, hash('sha256', self::canonical($v))) ? $v : null;
        } catch (Throwable $ignored) { return null; }
    }
    /** The installed AnyTour MAX webhook secret and actual request must both be present. */
    public static function currentRequest(string $type): ?array
    {
        if (!defined('MAX_SEARCH_WEBHOOK_SECRET') || !is_string(MAX_SEARCH_WEBHOOK_SECRET) || MAX_SEARCH_WEBHOOK_SECRET === ''
            || !is_string($_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? null)
            || !hash_equals(MAX_SEARCH_WEBHOOK_SECRET, $_SERVER['HTTP_X_MAX_BOT_API_SECRET'])
            || ($_SERVER['REQUEST_METHOD'] ?? null) !== 'POST'
            || (defined('MAX_SEARCH_MAX_SHADOW_MODE') && MAX_SEARCH_MAX_SHADOW_MODE === true)) return null;
        $input = fopen('php://input','rb'); if ($input === false) return null;
        try { $raw = stream_get_contents($input, 65537); } finally { fclose($input); }
        if (!is_string($raw) || $raw === '' || strlen($raw) > 65536) return null;
        try {
            $u = json_decode($raw, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            return is_array($u) && ($u['update_type'] ?? null) === $type ? $u : null;
        } catch (Throwable $ignored) { return null; }
    }
}
