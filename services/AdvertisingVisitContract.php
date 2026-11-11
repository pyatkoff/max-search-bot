<?php
declare(strict_types=1);

/** Versioned private transport for observations, including entries without a subscription. */
final class AdvertisingVisitContract
{
    public const MAX_PAGE = 100;
    public const MAX_BYTES = 262144;

    public static function scope(array $scope, bool $allowUnbound = false): array
    {
        self::keys($scope, ['project_key','source_key','platform','counter_id']);
        if (!is_string($scope['project_key']) || !preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D', $scope['project_key'])) self::fail('project_key');
        if (!is_string($scope['source_key']) || !preg_match('/\A[a-z][a-z0-9_.-]{0,79}\z/D', $scope['source_key'])) self::fail('source_key');
        if (!in_array($scope['platform'], ['telegram','max','website'], true)) self::fail('platform');
        self::decimal($scope['counter_id'], 20, $allowUnbound, 'counter_id');
        $ordered = [];
        foreach (['project_key','source_key','platform','counter_id'] as $key) $ordered[$key] = $scope[$key];
        return $ordered;
    }

    public static function page(array $page, array $expected, int $now): array
    {
        $version = $page['schema_version'] ?? null;
        if (!in_array($version, [1,2], true)) self::fail('schema_version');
        $scope = self::scope($expected, $version === 2);
        self::keys($page, ['schema_version','project_key','source_key','platform','counter_id','after_cursor','next_cursor','observations']);
        foreach ($scope as $key => $value) if ($page[$key] !== $value) self::fail('scope_mismatch');
        self::cursor($page['after_cursor']);
        self::cursor($page['next_cursor']);
        if (!is_array($page['observations']) || !array_is_list($page['observations']) || count($page['observations']) > self::MAX_PAGE) self::fail('page_bound');
        $last = $page['after_cursor'];
        $observations = [];
        foreach ($page['observations'] as $row) {
            if (!is_array($row)) self::fail('observation');
            $fields = ['source_cursor','visit_id','visit_at_unix','observed_at_unix','external_user_id','identity_status','client_id','yclid','campaign_id','region_id'];
            if ($version === 2) $fields = [...$fields,'post_ref','offer_ref','context_json'];
            self::keys($row, $fields);
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
            if ($version === 2) {
                foreach (['post_ref','offer_ref'] as $key) if (!is_string($row[$key]) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D',$row[$key])) self::fail($key);
                if (!is_string($row['context_json']) || strlen($row['context_json'])>8192) self::fail('context_json');
                try {$context=json_decode($row['context_json'],true,8,JSON_THROW_ON_ERROR);} catch(Throwable $e) {self::fail('context_json');}
                if (!is_array($context) || array_keys($context)!==['post_url','post_title','profile_sha256']
                    || !is_string($context['post_url']) || !preg_match('~\Ahttps://max\.ru/[A-Za-z0-9_]{3,100}/[A-Za-z0-9_-]{1,191}\z~D',$context['post_url'])
                    || !is_string($context['post_title']) || $context['post_title']==='' || strlen($context['post_title'])>1000 || preg_match('/[\x00-\x1f<>]/',$context['post_title'])
                    || !is_string($context['profile_sha256']) || !preg_match('/\A[a-f0-9]{64}\z/D',$context['profile_sha256'])) self::fail('context_json');
            }
            foreach ($fields as $key) $normalized[$key] = $row[$key];
            $observations[] = $normalized;
        }
        if ($last !== $page['next_cursor']) self::fail('next_cursor');
        return ['schema_version'=>$version] + $scope + ['after_cursor'=>$page['after_cursor'],'next_cursor'=>$last,'observations'=>$observations];
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
