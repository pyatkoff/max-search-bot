<?php
declare(strict_types=1);
// One finite, read-only instance of the existing remaining-resolver reader.
// No registry service, old reader scope, token resolver or provider is executed.
const EXPECTED_INSTALLED_SHA = '62fc5cf8cf5cb3e41b370a808c2281d27ae83da7';
const R60_OPERATION = 'commercial-rewaya60-lineage-read-20261115-v1';
const R60_PUBLICATION = 60;
const R60_VERSION = 72;
const R60_CANDIDATE = 74036;
const R60_URL = 'https://max.ru/channel_anytour/AaF1Af9VDuA';
const R60_CAPTION = '506770800150c3e9b5606b5163b744ad1959eb6d8b81721e2367704fef997a8f';
const R60_HELPER_SHA256 = '6211fe86c413e6ae8f25683c27800a70388c849ab920523ac6426ee573491e7e';
const R60_FIELDS = ['hotel_catalog_id','catalog_hotel_id','tourvisor_id','tourvisorId','native_hotel_id','hotel_id','logical_id'];
const R60_PINS = [
    'integrations/ChannelPostApiTransport.php'=>'300634d77cb9fddf51adf9869974f956cca6e3f9',
    'migrations/033_channel_post_context.sql'=>'0530458030a4bfe6b6cc55d1e5e441745c29b947',
    'migrations/036_channel_post_member_delivery.sql'=>'1bda463e52b33130ad12752ddef4ee5c92699c05',
    'migrations/040_channel_post_reconciliation.sql'=>'0f24b838eacd45333c5ade9a2991d060488c6035',
    'services/ChannelPostAdminService.php'=>'b80eefc801054bfe6fdb58ec345ecdf687b2e89a',
    'services/ChannelPostAutomationService.php'=>'f5975ab95de5f45e087eb7a0f768f566c5bca648',
    'services/ChannelPostButtonTransport.php'=>'7b6711f1de63000346d8e5789f2dbb7f725535b8',
    'services/ChannelPostEntryService.php'=>'f0e04e9b4e0ed6859ced96279cbf0619931596e6',
    'services/ChannelPostMaxAlbumRecovery.php'=>'de266cd068587cc3b2ff83f308a966ea66887a04',
    'services/ChannelPostMediaService.php'=>'f794efd0f6f24df916e18909ff6281b0bc928433',
    'services/ChannelPostMemberDeliveryService.php'=>'20c1d7984898b2a5996a5f9e20e4d2d8c20b4778',
    'services/ChannelPostReconciliationService.php'=>'43133f576845c14446102dedfffb7f0de2d4ba1c',
    'services/ChannelPostReconciliationState.php'=>'d9583ceca80d1424a44ade3561c21282a23a52f0',
    'services/ChannelPostRegistryService.php'=>'db8181426091a685a79ee55991cde22671f0d9dd',
    'services/ChannelPostRuntime.php'=>'2f1dd9b77474fdbaa92f0402f3b0aa170c595474',
    'services/ChannelPostTelegramAlbum.php'=>'faa1a14bc279a4af33936282126bf0d46e343040',
    'services/ChannelPostWebhookConfigService.php'=>'d5fdcb056088f1e3a541b4046325d97eef6417d9',
    'services/ConversationDb.php'=>'bb6281faf55aca7beeae7f8a57389d19751936f7',
    'services/MaxTlsConfig.php'=>'032c0211ee3f39c6f0d27c669abd5b467e321dea',
    'services/MaxTransport.php'=>'2cb4f8786b4704a8e62a3a9c40dc2cc73dad6159',
    'services/ProjectConfig.php'=>'63c17c8103412f8cff8cdcc4bbb062e744106af9',
    'services/ProjectConfiguration.php'=>'07019d473c588e3386ace42977db93092e8112e3',
];
const R60_HEADER_SQL = <<<'HEADER_SQL'
SELECT pp.id AS publication_id, pp.post_channel_id, pp.source_id,
       pp.external_message_id,
       pp.current_version_id, pp.created_at AS publication_created_at,
       v.id AS version_id, v.version_number, v.state,
       v.content_hash, v.write_attempts,
       CASE WHEN v.claim_token IS NULL AND v.claimed_at IS NULL THEN 0 ELSE 1 END AS claim_present,
       LENGTH(v.snapshot_json) AS snapshot_bytes,
       JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.post_url')) AS post_url,
       CASE WHEN JSON_TYPE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id')) IN ('INTEGER','STRING') AND JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id')) REGEXP '^[1-9][0-9]{0,9}$' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id')) AS DECIMAL(20,0))<=2147483647 THEN JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id')) ELSE NULL END AS hotel_catalog_id,
       CASE WHEN JSON_TYPE(JSON_EXTRACT(v.snapshot_json,'$.metadata.logical_id'))='STRING' AND JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.logical_id')) REGEXP '^[A-Za-z0-9_.:-]{1,191}$' THEN JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.logical_id')) ELSE NULL END AS logical_id,
       SHA2(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.text')),256) AS caption_sha256
FROM post_publications pp
JOIN post_channels pc ON pc.id=pp.post_channel_id
JOIN projects p ON p.id=pc.project_id
JOIN conversation_sources s ON s.id=pp.source_id AND s.id=pc.source_id
  AND s.project_id=pc.project_id AND s.channel=pc.platform
JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id
WHERE pp.id=? AND v.id=? AND pp.post_channel_id=9 AND pp.source_id=5
  AND p.project_key='anytour' AND pc.platform='max'
  AND pc.bot_key='id9704048781_1_bot'
  AND pc.canonical_bot_username='id9704048781_1_bot'
  AND pc.channel_id='-80492244209683' AND s.source_key='max_anytour_msk1'
  AND BINARY JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.post_url'))=BINARY ?
  AND SHA2(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.text')),256)=?
LIMIT 2;
HEADER_SQL;
const R60_HISTORY_SQL = <<<'HISTORY_SQL'
SELECT id AS version_id, publication_id, version_number, state, content_hash,
       write_attempts,
       CASE WHEN claim_token IS NULL AND claimed_at IS NULL THEN 0 ELSE 1 END AS claim_present,
       LENGTH(snapshot_json) AS snapshot_bytes,
       SHA2(snapshot_json,256) AS snapshot_sha256,
       SHA2(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.text')),256) AS caption_sha256,
       CASE WHEN LENGTH(snapshot_json)<=131072 AND JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.post_url'))='STRING' AND CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.post_url')))<=512 THEN JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.post_url')) ELSE NULL END AS post_url,
       CASE WHEN LENGTH(snapshot_json)<=131072 THEN JSON_OBJECT(
         'hotel_catalog_id', CASE WHEN JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.metadata.hotel_catalog_id')) IN ('INTEGER','STRING') AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.hotel_catalog_id')) REGEXP '^[1-9][0-9]{0,9}$' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.hotel_catalog_id')) AS DECIMAL(20,0))<=2147483647 THEN JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.hotel_catalog_id')) ELSE NULL END,
         'catalog_hotel_id', CASE WHEN JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.metadata.catalog_hotel_id')) IN ('INTEGER','STRING') AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.catalog_hotel_id')) REGEXP '^[1-9][0-9]{0,9}$' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.catalog_hotel_id')) AS DECIMAL(20,0))<=2147483647 THEN JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.catalog_hotel_id')) ELSE NULL END,
         'tourvisor_id', CASE WHEN JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.metadata.tourvisor_id')) IN ('INTEGER','STRING') AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.tourvisor_id')) REGEXP '^[1-9][0-9]{0,9}$' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.tourvisor_id')) AS DECIMAL(20,0))<=2147483647 THEN JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.tourvisor_id')) ELSE NULL END,
         'tourvisorId', CASE WHEN JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.metadata.tourvisorId')) IN ('INTEGER','STRING') AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.tourvisorId')) REGEXP '^[1-9][0-9]{0,9}$' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.tourvisorId')) AS DECIMAL(20,0))<=2147483647 THEN JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.tourvisorId')) ELSE NULL END,
         'native_hotel_id', CASE WHEN JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.metadata.native_hotel_id')) IN ('INTEGER','STRING') AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.native_hotel_id')) REGEXP '^[1-9][0-9]{0,9}$' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.native_hotel_id')) AS DECIMAL(20,0))<=2147483647 THEN JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.native_hotel_id')) ELSE NULL END,
         'hotel_id', CASE WHEN JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.metadata.hotel_id')) IN ('INTEGER','STRING') AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.hotel_id')) REGEXP '^[1-9][0-9]{0,9}$' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.hotel_id')) AS DECIMAL(20,0))<=2147483647 THEN JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.hotel_id')) ELSE NULL END,
         'logical_id', CASE WHEN JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.metadata.logical_id'))='STRING' AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.logical_id')) REGEXP '^[A-Za-z0-9_.:-]{1,191}$' THEN JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.metadata.logical_id')) ELSE NULL END) ELSE NULL END AS typed_metadata_json,
       CASE WHEN LENGTH(snapshot_json)<=131072 THEN JSON_OBJECT(
         'hotel_catalog_id', JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.metadata.hotel_catalog_id')),
         'catalog_hotel_id', JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.metadata.catalog_hotel_id')),
         'tourvisor_id', JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.metadata.tourvisor_id')),
         'tourvisorId', JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.metadata.tourvisorId')),
         'native_hotel_id', JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.metadata.native_hotel_id')),
         'hotel_id', JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.metadata.hotel_id')),
         'logical_id', JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.metadata.logical_id'))) ELSE NULL END AS typed_metadata_types_json,
       created_at, updated_at
FROM post_versions
WHERE publication_id=?
ORDER BY id
LIMIT 65;
HISTORY_SQL;

if (!function_exists('rrNeed')) {
    $helper = __DIR__.'/commercial-remaining-resolver-20261113/reader_lib.php';
    if (!is_file($helper) || hash_file('sha256', $helper) !== R60_HELPER_SHA256) {
        throw new RuntimeException('accepted_reader_helper_changed');
    }
    require_once $helper;
}

function rrInstalledGuard(array $pins): void
{
    rrNeed(PHP_SAPI === 'cli', 'cli_required');
    $lines = []; $code = 1;
    exec('git rev-parse HEAD', $lines, $code);
    rrNeed($code === 0 && trim(implode("\n", $lines)) === EXPECTED_INSTALLED_SHA, 'installed_source_changed');
    $lines = []; $code = 1;
    exec('git status --porcelain --untracked-files=no', $lines, $code);
    rrNeed($code === 0 && $lines === [], 'installed_checkout_changed');
    foreach ($pins as $path=>$sha) {
        rrNeed(preg_match('~\A(?:services|integrations|migrations)/[A-Za-z0-9_.-]+\z~D', $path) === 1
            && preg_match('/\A[a-f0-9]{40}\z/D', $sha) === 1, 'installed_pin_invalid');
        $lines = []; $code = 1;
        exec('git hash-object -- '.escapeshellarg($path), $lines, $code);
        rrNeed($code === 0 && trim(implode("\n", $lines)) === $sha, 'accepted_installed_surface_changed');
    }
}

function r60Keys(array $value, array $expected, string $reason): void
{
    $actual = array_keys($value); sort($actual); sort($expected);
    rrNeed($actual === $expected, $reason);
}

function r60Count($value, int $ceiling, string $reason): int
{
    rrNeed(is_int($value) || is_string($value), $reason);
    $text = (string)$value;
    rrNeed(preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $text) === 1 && (float)$text <= $ceiling, $reason);
    return (int)$text;
}

function r60Hash($value, bool $nullable = false): ?string
{
    if ($value === null && $nullable) return null;
    rrNeed(is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1, 'projected_hash_invalid');
    return $value;
}

function r60Logical($value): ?string
{
    return is_string($value) && preg_match('/\A[A-Za-z0-9_.:-]{1,191}\z/D', $value) === 1 ? $value : null;
}

function r60PublicUrl($value): ?string
{
    // The identical rrSnapshot URL expression, after the bounded SQL projection.
    return is_string($value) && strlen($value) <= 512
        && preg_match('~\Ahttps://(?:max\.ru/channel_anytour/[A-Za-z0-9_-]+|t\.me/any_toour/[1-9][0-9]*)\z~D', $value) === 1 ? $value : null;
}

function r60Timestamp($value): ?string
{
    if ($value === null) return null;
    rrNeed(is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?\z/D', $value) === 1, 'stored_timestamp_invalid');
    return $value; // Retain the database value; do not reinterpret its timezone.
}

function r60Common(array $row): array
{
    $version = rrId($row['version_id'] ?? null);
    $number = rrId($row['version_number'] ?? null);
    rrNeed($version !== null && $number !== null, 'projected_version_identity_invalid');
    rrNeed(is_string($row['state'] ?? null) && preg_match('/\A[a-z][a-z0-9_]{0,23}\z/D', $row['state']) === 1, 'projected_state_invalid');
    $bytes = r60Count($row['snapshot_bytes'] ?? null, 131072, 'snapshot_missing_or_too_large');
    rrNeed($bytes > 0, 'snapshot_missing_or_too_large');
    return ['version_id'=>$version, 'version_number'=>$number, 'state'=>$row['state'],
        'content_hash'=>r60Hash($row['content_hash'] ?? null),
        'write_attempts'=>r60Count($row['write_attempts'] ?? null, 2147483647, 'write_attempts_invalid'),
        'claim_present'=>(bool)r60Count($row['claim_present'] ?? null, 1, 'claim_presence_invalid'),
        'snapshot_bytes'=>$bytes, 'post_url'=>r60PublicUrl($row['post_url'] ?? null),
        'caption_sha256'=>r60Hash($row['caption_sha256'] ?? null, true)];
}

function r60Header(array $row): array
{
    r60Keys($row, ['publication_id','post_channel_id','source_id','external_message_id','current_version_id',
        'publication_created_at','version_id','version_number','state','content_hash','write_attempts','claim_present',
        'snapshot_bytes','post_url','hotel_catalog_id','logical_id','caption_sha256'], 'header_projection_changed');
    rrNeed(rrId($row['publication_id']) === R60_PUBLICATION && rrId($row['current_version_id']) === R60_VERSION
        && rrId($row['post_channel_id']) === 9 && rrId($row['source_id']) === 5, 'header_scope_changed');
    rrNeed(is_string($row['external_message_id']) && preg_match('/\A[A-Za-z0-9_.:-]{1,191}\z/D', $row['external_message_id']) === 1, 'observed_message_id_invalid');
    $common = r60Common($row);
    rrNeed($common['version_id'] === R60_VERSION && $common['post_url'] === R60_URL
        && $common['caption_sha256'] === R60_CAPTION, 'anchored_current_version_changed');
    return ['publication_id'=>R60_PUBLICATION, 'post_channel_id'=>9, 'source_id'=>5,
        'external_message_id'=>$row['external_message_id'], 'current_version_id'=>R60_VERSION,
        'publication_created_at'=>r60Timestamp($row['publication_created_at'])] + $common
        + ['hotel_catalog_id'=>rrId($row['hotel_catalog_id']), 'logical_id'=>r60Logical($row['logical_id'])];
}

function r60Version(array $row): array
{
    r60Keys($row, ['version_id','publication_id','version_number','state','content_hash','write_attempts','claim_present',
        'snapshot_bytes','snapshot_sha256','caption_sha256','post_url','typed_metadata_json','typed_metadata_types_json',
        'created_at','updated_at'], 'history_projection_changed');
    rrNeed(rrId($row['publication_id']) === R60_PUBLICATION, 'history_publication_scope_changed');
    $common = r60Common($row);
    foreach (['typed_metadata_json','typed_metadata_types_json'] as $field) {
        rrNeed(is_string($row[$field]) && strlen($row[$field]) <= 2048, 'typed_projection_unavailable');
    }
    $values = json_decode($row['typed_metadata_json'], true, 8, JSON_THROW_ON_ERROR);
    $types = json_decode($row['typed_metadata_types_json'], true, 8, JSON_THROW_ON_ERROR);
    rrNeed(is_array($values) && is_array($types), 'typed_projection_invalid');
    r60Keys($values, R60_FIELDS, 'typed_projection_keys_changed');
    r60Keys($types, R60_FIELDS, 'typed_projection_type_keys_changed');
    $typed = [];
    foreach (R60_FIELDS as $field) {
        rrNeed($types[$field] === null || in_array($types[$field], ['OBJECT','ARRAY','BOOLEAN','INTEGER','DOUBLE','DECIMAL','STRING','NULL'], true), 'typed_projection_type_invalid');
        if ($values[$field] !== null) {
            rrNeed(in_array($types[$field], $field === 'logical_id' ? ['STRING'] : ['INTEGER','STRING'], true), 'typed_projection_type_conflict');
        }
        $typed[$field] = $field === 'logical_id' ? r60Logical($values[$field]) : rrId($values[$field]);
    }
    $snapshot = ['snapshot_sha256'=>r60Hash($row['snapshot_sha256']), 'caption_sha256'=>$common['caption_sha256'],
        'post_url'=>$common['post_url'], 'hotel_catalog_id'=>$typed['hotel_catalog_id'], 'logical_id'=>$typed['logical_id'],
        'selected_direct_metadata'=>$typed, 'selected_direct_metadata_types'=>$types,
        'typed_reference_fields'=>rrTypedReferences($typed), 'nested_metadata_not_read'=>true];
    return ['publication_id'=>R60_PUBLICATION] + $common + ['snapshot'=>$snapshot,
        'created_at'=>r60Timestamp($row['created_at']), 'updated_at'=>r60Timestamp($row['updated_at'])];
}

function r60Evidence(array $headers, array $rows): array
{
    rrNeed(count($headers) === 1, 'anchored_publication_not_unique_or_missing');
    rrNeed(count($rows) >= 1 && count($rows) <= 64, 'history_coverage_limit_or_missing');
    $header = r60Header($headers[0]); $history = []; $current = null; $last = 0; $numbers = [];
    foreach ($rows as $row) {
        $version = r60Version($row);
        rrNeed($version['version_id'] > $last && !isset($numbers[$version['version_number']]), 'history_order_or_identity_changed');
        $last = $version['version_id']; $numbers[$version['version_number']] = true;
        if ($last === R60_VERSION) $current = $version;
        $history[] = $version;
    }
    rrNeed($current !== null, 'anchored_current_missing_from_history');
    foreach (['version_id','version_number','state','content_hash','write_attempts','claim_present','snapshot_bytes','post_url','caption_sha256'] as $key) {
        rrNeed($current[$key] === $header[$key], 'current_history_snapshot_conflict');
    }
    foreach (['hotel_catalog_id','logical_id'] as $key) rrNeed($current['snapshot'][$key] === $header[$key], 'current_history_typed_conflict');
    $relation = rrOldRelation($current['snapshot'], $history, R60_CANDIDATE);
    $drift = $header['hotel_catalog_id'] !== null || $header['logical_id'] !== null;
    $stable = $header['state'] === 'sent' && !$header['claim_present'];
    if ($drift) $relation['status'] = 'current_typed_metadata_changed_since_read4';
    if (!$stable) $relation['status'] = 'current_publication_not_stable';
    // Original read4 observed null/null on this exact immutable current version.
    // Even an unexpected current typed value needs review, never automatic binding.
    $relation['native_relation_proven'] = false; $relation['evidence_field'] = null;
    $relation['current_metadata_changed_since_read4'] = $drift;
    $relation['current_publication_stable'] = $stable;
    $relation['historical_native_conflicts_with_candidate'] = array_values(array_filter(
        $relation['historical_catalog_hotel_ids'], static fn(int $id): bool => $id !== R60_CANDIDATE));
    return ['status'=>'complete', 'complete'=>true, 'current'=>$header, 'versions'=>$history,
        'version_count'=>count($history), 'history_sentinel_reached'=>false, 'relation'=>$relation,
        'coverage'=>'all_retained_versions_of_publication60_up_to64_only_seven_direct_metadata_fields',
        'arbitrary_nested_metadata_and_external_archives_not_searched'=>true,
        'stored_timestamps_not_reinterpreted_as_utc'=>true];
}

function r60Read(PDO $pdo, string $root, ?array &$transaction = null): array
{
    $transaction = ['started'=>false, 'rollback_attempted'=>false, 'rolled_back'=>false];
    try {
        rrStartRead($pdo); $transaction['started'] = true;
        $checks = [];
        foreach (['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $migration) {
            $rows = rrRows($pdo, 'SELECT checksum FROM schema_migrations WHERE version=? LIMIT 2', [$migration], 1, 'migration_checksum');
            rrNeed(count($rows) === 1 && $rows[0]['checksum'] === hash_file('sha256', $root.'/migrations/'.$migration), 'publishing_schema_not_accepted');
            $checks[$migration] = $rows[0]['checksum'];
        }
        $header = rrRows($pdo, R60_HEADER_SQL, [R60_PUBLICATION,R60_VERSION,R60_URL,R60_CAPTION], 1, 'publication60_header');
        rrNeed(count($header) === 1, 'anchored_publication_not_unique_or_missing');
        $history = rrRows($pdo, R60_HISTORY_SQL, [R60_PUBLICATION], 64, 'publication60_versions');
        $evidence = r60Evidence($header, $history);
        return ['publication_schema_checks'=>$checks, 'lineage'=>$evidence];
    } finally {
        if ($transaction['started'] && $pdo->inTransaction()) {
            $transaction['rollback_attempted'] = true;
            $transaction['rolled_back'] = $pdo->rollBack();
            rrNeed($transaction['rolled_back'], 'read_only_rollback_failed');
        }
        unset($GLOBALS['RR_QUERY_AUDIT']); // No query audit or raw query log is exported.
    }
}

if (defined('REWAYA60_LIBRARY_ONLY') && REWAYA60_LIBRARY_ONLY === true) return;
ini_set('display_errors', '0'); ini_set('log_errors', '0'); set_time_limit(45);
$out = ['operation'=>R60_OPERATION, 'ok'=>false, 'complete'=>false,
    'mode'=>'bounded_existing_publication_direct_typed_history_read', 'observed_at_utc'=>gmdate('c'),
    'installed_sha'=>EXPECTED_INSTALLED_SHA, 'publication_scope'=>[R60_PUBLICATION], 'expected_current_version_id'=>R60_VERSION,
    'candidate_native_id'=>R60_CANDIDATE, 'candidate_is_not_confirmed_binding'=>true,
    'history_row_cap'=>64, 'snapshot_byte_cap'=>131072, 'database_connections'=>0,
    'db_writes'=>0, 'journal_writes'=>0, 'provider_calls'=>0, 'message_sends'=>0, 'identity_bindings_written'=>0,
    'customer_rows_read'=>0, 'catalog_mapping_queries'=>0, 'name_or_alias_searches'=>0, 'other_publications_read'=>0,
    'raw_metadata_read'=>0, 'metadata_link_read'=>0, 'raw_tokens_read'=>0, 'token_hashes_read'=>0,
    'raw_provider_body_or_media_read'=>0, 'receipt_json_read'=>0, 'query_logs_read'=>0,
    'query_audit_export'=>false, 'transaction_started'=>false, 'rollback_attempted'=>false, 'transaction_rolled_back'=>false,
    'read_result_is_not_publication_or_identity_mutation_permission'=>true];
$pdo = null; $transaction = null;
try {
    rrInstalledGuard(R60_PINS);
    ob_start();
    try {
        require getcwd().'/config.php';
        require_once getcwd().'/services/ConversationDb.php';
    } finally { ob_end_clean(); }
    ini_set('display_errors', '0'); ini_set('log_errors', '0');
    rrNeed(ConversationDb::isConfigured(), 'publication_connection_not_configured');
    $pdo = ConversationDb::connection(); $out['database_connections'] = 1;
    $result = r60Read($pdo, getcwd(), $transaction);
    rrNeed($transaction['started'] && $transaction['rolled_back'] && !$pdo->inTransaction(), 'read_only_transaction_still_active');
    rrInstalledGuard(R60_PINS);
    $out += $result; $out['complete'] = true; $out['ok'] = true;
} catch (Throwable $error) {
    $out['error'] = rrSafeError($error);
} finally {
    if ($pdo instanceof PDO && ($transaction['started'] ?? false) && $pdo->inTransaction()) {
        try {
            $transaction['rollback_attempted'] = true;
            $transaction['rolled_back'] = $pdo->rollBack();
        }
        catch (Throwable $error) { $out['error'] = rrSafeError($error); $out['ok'] = false; $out['complete'] = false; }
    }
    if (is_array($transaction)) {
        $out['transaction_started'] = $transaction['started'];
        $out['rollback_attempted'] = $transaction['rollback_attempted'];
        $out['transaction_rolled_back'] = $transaction['rolled_back'];
    }
    unset($GLOBALS['RR_QUERY_AUDIT']);
}
$out['finished_at_utc'] = gmdate('c');
echo 'REWAYA60_LINEAGE_READ_RESULT=', rrJson($out), PHP_EOL;
exit($out['ok'] ? 0 : 2);
