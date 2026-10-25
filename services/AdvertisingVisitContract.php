<?php
declare(strict_types=1);

/** Versioned private transport for observations, including entries without a subscription. */
final class AdvertisingVisitContract
{
    public const MAX_PAGE = 100;
    public const MAX_BYTES = 262144;

    public static function scope(array $scope): array
    {
        self::keys($scope, ['project_key','source_key','platform','counter_id']);
        if (!is_string($scope['project_key']) || !preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D', $scope['project_key'])) self::fail('project_key');
        if (!is_string($scope['source_key']) || !preg_match('/\A[a-z][a-z0-9_.-]{0,79}\z/D', $scope['source_key'])) self::fail('source_key');
        if (!in_array($scope['platform'], ['telegram','max','website'], true)) self::fail('platform');
        self::decimal($scope['counter_id'], 20, false, 'counter_id');
        $ordered = [];
        foreach (['project_key','source_key','platform','counter_id'] as $key) $ordered[$key] = $scope[$key];
        return $ordered;
    }

    public static function page(array $page, array $expected, int $now): array
    {
        $scope = self::scope($expected);
        self::keys($page, ['schema_version','project_key','source_key','platform','counter_id','after_cursor','next_cursor','observations']);
        if ($page['schema_version'] !== 1) self::fail('schema_version');
        foreach ($scope as $key => $value) if ($page[$key] !== $value) self::fail('scope_mismatch');
        self::cursor($page['after_cursor']);
        self::cursor($page['next_cursor']);
        if (!is_array($page['observations']) || !array_is_list($page['observations']) || count($page['observations']) > self::MAX_PAGE) self::fail('page_bound');
        $last = $page['after_cursor'];
        $observations = [];
        foreach ($page['observations'] as $row) {
            if (!is_array($row)) self::fail('observation');
            self::keys($row, ['source_cursor','visit_id','visit_at_unix','observed_at_unix','external_user_id','identity_status','client_id','yclid','campaign_id','region_id']);
            self::decimal($row['source_cursor'], 20, false, 'source_cursor');
            if (self::compare($row['source_cursor'], $last) <= 0) self::fail('cursor_order');
            $last = $row['source_cursor'];
            if (!is_string($row['visit_id']) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $row['visit_id'])) self::fail('visit_id');
            foreach (['visit_at_unix','observed_at_unix'] as $key) {
                if (!is_int($row[$key]) || $row[$key] < 946684800 || $row[$key] > $now + 300) self::fail($key);
            }
            if ($row['observed_at_unix'] < $row['visit_at_unix']) self::fail('observation_before_visit');
            self::decimal($row['external_user_id'], 32, true, 'external_user_id');
            if (!in_array($row['identity_status'], ['missing','unverified','verified'], true)) self::fail('identity_status');
            if (($row['identity_status'] === 'missing') !== ($row['external_user_id'] === '')) self::fail('identity_status');
            if ($scope['platform'] === 'website' && $row['external_user_id'] !== '') self::fail('website_identity');
            foreach (['client_id','yclid'] as $key) self::decimal($row[$key], 40, true, $key);
            foreach (['campaign_id','region_id'] as $key) self::decimal($row[$key], 20, true, $key);
            // Fixed field order makes exact replays independent of JSON object key order.
            $normalized = [];
            foreach (['source_cursor','visit_id','visit_at_unix','observed_at_unix','external_user_id','identity_status','client_id','yclid','campaign_id','region_id'] as $key) $normalized[$key] = $row[$key];
            $observations[] = $normalized;
        }
        if ($last !== $page['next_cursor']) self::fail('next_cursor');
        return ['schema_version'=>1] + $scope + ['after_cursor'=>$page['after_cursor'],'next_cursor'=>$last,'observations'=>$observations];
    }

    /** Decimal IDs stay strings even above PHP_INT_MAX. */
    public static function compare(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    public static function fingerprint(array $row): string
    {
        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function cursor($value): void
    {
        if ($value !== '0') self::decimal($value, 20, false, 'cursor');
    }

    private static function decimal($value, int $max, bool $empty, string $field): void
    {
        if ($empty && $value === '') return;
        if (!is_string($value) || strlen($value) > $max || !preg_match('/\A[1-9][0-9]*\z/D', $value)) self::fail($field);
    }

    private static function keys(array $value, array $keys): void
    {
        if (count($value) !== count($keys) || array_diff(array_keys($value), $keys) || array_diff($keys, array_keys($value))) self::fail('fields');
    }

    private static function fail(string $field): void
    {
        throw new InvalidArgumentException('advertising_visit_invalid_'.$field);
    }
}
