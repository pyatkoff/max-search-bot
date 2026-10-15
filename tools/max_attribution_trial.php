<?php
declare(strict_types=1);

/** Fixed MAX-only observation. JSON is private input to later encryption, never a public artifact. */
final class MaxAttributionTrial
{
    public const EXPECTED_SHA = 'c78428948ff290ce5cb5ff1af3bdcb848519c364';
    public const ROOT = '/var/www/anytoour/data/www/app.anytoour.ru';
    public const HASH_DOMAIN = 'max-attribution-trial-20261015-v1|';
    private const POST_LIMIT = 50;
    private const FALLBACK_LIMIT = 100;

    public static function blocked(string $reason): array
    {
        return ['ok'=>false, 'mode'=>'max_attribution_read_only_trial', 'reason'=>$reason,
            'provider_requests'=>0, 'conversion_calls'=>0, 'business_writes'=>0];
    }

    public static function userKey(string $externalUserId): string
    {
        if (!preg_match('/\A[1-9][0-9]{0,19}\z/D', $externalUserId)) {
            throw new RuntimeException('invalid_identity');
        }
        return hash('sha256', self::HASH_DOMAIN . $externalUserId);
    }

    /** Explicit recorder types only: absent/invalid metadata never invents customer activity. */
    public static function realCustomerPredicate(): string
    {
        return "m.direction='inbound' AND m.sender_type='customer' AND m.channel='max'"
            . " AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(m.metadata_json) THEN m.metadata_json ELSE '{}' END,'$.type')) IN ('message','contact')"
            . " AND LOWER(LTRIM(COALESCE(m.text,''))) NOT LIKE '/start%'";
    }

    public static function postSql(): string
    {
        // Fetch one sentinel row to report truncation; never return more than POST_LIMIT.
        return "SELECT e.id AS entry_id,e.conversation_id,e.created_at AS anchor_at,"
            . "c.started_at AS conversation_started_at,cc.external_user_id,c.external_chat_id,pc.channel_id"
            . " FROM post_entries e"
            . " JOIN conversations c ON c.id=e.conversation_id"
            . " JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.project_key=c.project_key AND cc.channel=c.channel"
            . " JOIN post_publications pp ON pp.id=e.publication_id"
            . " JOIN post_versions pv ON pv.id=e.version_id AND pv.publication_id=pp.id"
            . " JOIN post_channels pc ON pc.id=pp.post_channel_id"
            . " JOIN projects p ON p.id=pc.project_id"
            . " WHERE c.project_key='anytour' AND c.channel='max'"
            . " AND cc.project_key='anytour' AND cc.channel='max'"
            . " AND p.project_key='anytour' AND pc.platform='max'"
            . " ORDER BY e.created_at DESC,e.id DESC LIMIT 51";
    }

    public static function fallbackSql(int $excludedCount): string
    {
        if ($excludedCount < 0 || $excludedCount > self::POST_LIMIT) throw new RuntimeException('invalid_bound');
        $exclude = $excludedCount > 0
            ? ' AND c.id NOT IN (' . implode(',', array_fill(0, $excludedCount, '?')) . ')' : '';
        return "SELECT c.id AS conversation_id,c.started_at AS anchor_at,c.started_at AS conversation_started_at,"
            . "cc.external_user_id,c.external_chat_id"
            . " FROM conversations c"
            . " JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.project_key=c.project_key AND cc.channel=c.channel"
            . " JOIN conversation_sources s ON s.id=c.source_id AND s.channel=c.channel"
            . " JOIN projects p ON p.id=s.project_id AND p.project_key=c.project_key"
            . " WHERE c.project_key='anytour' AND c.channel='max'"
            . " AND cc.project_key='anytour' AND cc.channel='max'"
            . " AND p.project_key='anytour' AND s.handling_mode='manager'"
            . " AND c.started_at>=DATE_SUB(NOW(), INTERVAL 21 DAY)" . $exclude
            . " ORDER BY c.started_at DESC,c.id DESC LIMIT 101";
    }

    private static function firstMessage(PDO $pdo, string $sql, array $params): ?array
    {
        $q = $pdo->prepare($sql);
        $q->execute($params);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** Ordered funnel, anchored independently for every post entry or fallback conversation. */
    private static function stages(PDO $pdo, string $conversationId, string $anchor): array
    {
        $customer = self::realCustomerPredicate();
        $projection = 'SELECT m.id,m.created_at FROM messages m WHERE m.conversation_id=? AND ';
        $first = self::firstMessage($pdo, $projection . $customer
            . ' AND m.created_at>=? ORDER BY m.created_at,m.id LIMIT 1', [$conversationId,$anchor]);
        $manager = $first ? self::firstMessage($pdo, $projection
            . "m.direction='outbound' AND m.sender_type='manager' AND m.channel='max'"
            . ' AND (m.created_at>? OR (m.created_at=? AND m.id>?)) ORDER BY m.created_at,m.id LIMIT 1',
            [$conversationId,$first['created_at'],$first['created_at'],$first['id']]) : null;
        $next = $manager ? self::firstMessage($pdo, $projection . $customer
            . ' AND (m.created_at>? OR (m.created_at=? AND m.id>?)) ORDER BY m.created_at,m.id LIMIT 1',
            [$conversationId,$manager['created_at'],$manager['created_at'],$manager['id']]) : null;
        return [
            'first_customer_message_at'=>$first['created_at'] ?? null,
            'first_manager_reply_at'=>$manager['created_at'] ?? null,
            'customer_reply_after_manager_at'=>$next['created_at'] ?? null,
        ];
    }

    public static function projectRow(array $row, string $kind, array $stages): array
    {
        $external = (string)$row['external_user_id'];
        $conversation = (string)$row['conversation_id'];
        if (!preg_match('/\A[1-9][0-9]{0,19}\z/D', $conversation)) throw new RuntimeException('invalid_record');
        if (!in_array($kind, ['channel_post','manager_source_fallback'], true)) throw new RuntimeException('invalid_kind');
        $record = $kind === 'channel_post' ? 'post|' . (string)$row['entry_id'] : 'conversation|' . $conversation;
        $out = [
            'kind'=>$kind,
            'record_key'=>hash('sha256', self::HASH_DOMAIN . $record),
            'conversation_key'=>hash('sha256', self::HASH_DOMAIN . 'conversation|' . $conversation),
            'user_key'=>self::userKey($external),
            'internal_id_matches_expected'=>(string)$row['external_chat_id'] === '-' . $external,
            'anchor_at'=>(string)$row['anchor_at'],
            'conversation_started_at'=>(string)$row['conversation_started_at'],
            'post_entry_at'=>$kind === 'channel_post' ? (string)$row['anchor_at'] : null,
            'first_customer_message_at'=>$stages['first_customer_message_at'] ?? null,
            'first_manager_reply_at'=>$stages['first_manager_reply_at'] ?? null,
            'customer_reply_after_manager_at'=>$stages['customer_reply_after_manager_at'] ?? null,
        ];
        // A configured channel is routing context, not a person's chat identity.
        if ($kind === 'channel_post' && preg_match('/\A-?[1-9][0-9]{0,19}\z/D', (string)($row['channel_id'] ?? ''))) {
            $out['channel_id'] = (string)$row['channel_id'];
        }
        return $out;
    }

    public static function run(): array
    {
        $sha = trim((string)@shell_exec('git -C ' . escapeshellarg(self::ROOT) . ' rev-parse HEAD 2>/dev/null'));
        if ($sha !== self::EXPECTED_SHA) return self::blocked('installed_sha_mismatch');
        foreach (['diff --quiet','diff --cached --quiet'] as $operation) {
            $status = 1;
            @exec('git -C ' . escapeshellarg(self::ROOT) . ' ' . $operation . ' 2>/dev/null', $ignored, $status);
            if ($status !== 0) return self::blocked('installed_checkout_dirty');
        }

        $bufferLevel = ob_get_level();
        ob_start();
        try {
            require_once self::ROOT . '/config.php';
            require_once self::ROOT . '/services/ConversationDb.php';
            $pdo = ConversationDb::connection();
        } finally {
            while (ob_get_level() > $bufferLevel) ob_end_clean();
        }
        $counter = defined('METRIKA_COUNTER_ID') ? trim((string)METRIKA_COUNTER_ID) : '';
        if (!preg_match('/\A[1-9][0-9]{0,19}\z/D', $counter)) return self::blocked('numeric_counter_missing');
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return self::blocked('mysql_required');
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        try {
            // Post entries use gmdate, while messages/start times use MySQL defaults.
            // Refuse to invent comparable instants when that clock is not currently UTC.
            $clockOffset = (int)$pdo->query('SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW())')->fetchColumn();
            if ($clockOffset !== 0) return self::blocked('database_clock_not_utc');
            $posts = $pdo->query(self::postSql())->fetchAll(PDO::FETCH_ASSOC);
            $postCapped = count($posts) > self::POST_LIMIT;
            $posts = array_slice($posts, 0, self::POST_LIMIT);
            $fallback = [];
            $fallbackCapped = false;
            $fallbackTriggered = count($posts) < self::POST_LIMIT;
            if ($fallbackTriggered) {
                $excluded = array_values(array_unique(array_map(static fn(array $r): string => (string)$r['conversation_id'], $posts)));
                $q = $pdo->prepare(self::fallbackSql(count($excluded)));
                $q->execute($excluded);
                $fallback = $q->fetchAll(PDO::FETCH_ASSOC);
                $fallbackCapped = count($fallback) > self::FALLBACK_LIMIT;
                $fallback = array_slice($fallback, 0, self::FALLBACK_LIMIT);
            }
            $rows = [];
            foreach (['channel_post'=>$posts,'manager_source_fallback'=>$fallback] as $kind=>$selected) {
                foreach ($selected as $row) {
                    $stages = self::stages($pdo, (string)$row['conversation_id'], (string)$row['anchor_at']);
                    $rows[] = self::projectRow($row, $kind, $stages);
                }
            }
            return [
                'ok'=>true, 'schema_version'=>1, 'mode'=>'max_attribution_read_only_trial',
                'installed_sha'=>self::EXPECTED_SHA, 'generated_at'=>gmdate('c'),
                'project'=>'anytour', 'platform'=>'max', 'counter_id'=>$counter,
                'hash_domain'=>self::HASH_DOMAIN, 'timestamp_timezone'=>'UTC',
                'stage_policy'=>'anchor_then_explicit_customer_message_or_contact_then_manager_then_customer; created_at,id_order; start_prefix_excluded',
                'bounds'=>[
                    'post_limit'=>self::POST_LIMIT, 'post_rows'=>count($posts), 'post_cap_reached'=>$postCapped,
                    'fallback_triggered'=>$fallbackTriggered, 'fallback_limit'=>self::FALLBACK_LIMIT,
                    'fallback_lookback_days'=>21, 'fallback_rows'=>count($fallback), 'fallback_cap_reached'=>$fallbackCapped,
                    'row_count'=>count($rows), 'distinct_users'=>count(array_unique(array_column($rows, 'user_key'))),
                ],
                'provider_requests'=>0, 'conversion_calls'=>0, 'business_writes'=>0, 'rows'=>$rows,
            ];
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    error_reporting(0);
    if (array_slice($argv, 1) !== ['--expected-sha=' . MaxAttributionTrial::EXPECTED_SHA]) {
        $result = MaxAttributionTrial::blocked('invalid_arguments');
    } else {
        try { $result = MaxAttributionTrial::run(); }
        catch (Throwable $ignored) { $result = MaxAttributionTrial::blocked('diagnostic_unavailable'); }
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR) . "\n";
    exit($result['ok'] ? 0 : 1);
}
