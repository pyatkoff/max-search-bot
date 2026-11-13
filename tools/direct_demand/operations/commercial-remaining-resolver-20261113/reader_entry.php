<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_time_limit(45);

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

$GLOBALS['RR_QUERY_AUDIT'] = [];
$out = ['operation'=>'commercial-remaining-anex48-and-max3-read-20261113-v1', 'ok'=>false,
    'mode'=>'bounded_existing_accepted_identities_and_post_context_read',
    'observed_at_utc'=>gmdate('c'), 'installed_sha'=>EXPECTED_INSTALLED_SHA,
    'scope_sha256'=>REMAINING_SCOPE_SHA256, 'db_writes'=>0, 'provider_calls'=>0,
    'price_reads'=>0, 'model_calls'=>0, 'customer_rows_read'=>0, 'message_sends'=>0,
    'new_publications'=>0, 'identity_bindings_written'=>0, 'automation_changes'=>0,
    'catalog_resolution'=>['status'=>'not_started','complete'=>false],
    'old_max_context'=>['status'=>'not_started','complete'=>false],
    'current_native_posts'=>['status'=>'not_started','complete'=>false]];
$catalog = null; $pdo = null;
try {
    $scopeRaw = base64_decode(REMAINING_SCOPE_B64, true);
    rrNeed(is_string($scopeRaw) && hash_equals(REMAINING_SCOPE_SHA256, hash('sha256', $scopeRaw)), 'scope_bytes_changed');
    $scope = json_decode($scopeRaw, true, 64, JSON_THROW_ON_ERROR);
    rrNeed($scope['operation'] === $out['operation'] && $scope['anex_count'] === 48
        && count($scope['anex_ids']) === 48 && $scope['old_max_count'] === 3
        && count($scope['old_max_posts']) === 3 && $scope['source_namespace'] === 'anex_hotel_id'
        && $scope['target_namespace'] === 'legacy_catalog/catalog_hotels.id' && $scope['resolver_scope'] === 'preview', 'scope_contract_changed');
    foreach ($scope['permissions'] as $permission=>$value) rrNeed($value === 0, 'read_permission_changed');
    $pinsRaw = base64_decode(REMAINING_PINS_B64, true);
    rrNeed(is_string($pinsRaw) && hash_equals(REMAINING_PINS_SHA256, hash('sha256', $pinsRaw)), 'source_pins_changed');
    $pins = json_decode($pinsRaw, true, 32, JSON_THROW_ON_ERROR);
    rrInstalledGuard($pins);
    ob_start();
    try {
        require getcwd().'/config.php';
        require_once getcwd().'/services/HotelDatabase.php';
        require_once getcwd().'/services/ConversationDb.php';
        require_once getcwd().'/services/ChannelPostRegistryService.php';
    } finally { ob_end_clean(); }
    ini_set('display_errors', '0'); ini_set('log_errors', '0');
    try {
        rrNeed(HotelDatabase::configured(), 'catalog_connection_not_configured');
        $catalog = HotelDatabase::connection();
        rrStartRead($catalog);
        $out['catalog_resolution'] = rrReadCatalog($catalog, $scope);
    } catch (Throwable $error) {
        $out['catalog_resolution'] = rrSafeError($error);
    } finally {
        if ($catalog instanceof PDO && $catalog->inTransaction()) $catalog->rollBack();
    }
    try {
        rrNeed(ConversationDb::isConfigured(), 'publication_connection_not_configured');
        $pdo = ConversationDb::connection();
        rrStartRead($pdo);
        $checks = [];
        foreach (['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $migration) {
            $rows = rrRows($pdo, 'SELECT checksum FROM schema_migrations WHERE version=? LIMIT 2', [$migration], 1, 'migration_checksum');
            rrNeed(count($rows) === 1 && $rows[0]['checksum'] === hash_file('sha256', getcwd().'/migrations/'.$migration), 'publishing_schema_not_accepted');
            $checks[$migration] = $rows[0]['checksum'];
        }
        $out['publication_schema_checks'] = $checks;
        try { $out['old_max_context'] = rrReadOldMax($pdo, $scope); }
        catch (Throwable $error) { $out['old_max_context'] = rrSafeError($error); }
        $ids = ($out['catalog_resolution']['complete'] ?? false)
            ? $out['catalog_resolution']['native_post_lookup_ids']
            : array_column($scope['old_max_posts'], 'candidate_catalog_hotel_id');
        $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC);
        try { $out['current_native_posts'] = rrReadNativePosts($pdo, $ids); }
        catch (Throwable $error) { $out['current_native_posts'] = rrSafeError($error); }
    } catch (Throwable $error) {
        $out['old_max_context'] = rrSafeError($error);
        $out['current_native_posts'] = rrSafeError($error);
    } finally {
        if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    }
    rrInstalledGuard($pins);
    $out['ok'] = ($out['catalog_resolution']['complete'] ?? false)
        && ($out['old_max_context']['complete'] ?? false) && ($out['current_native_posts']['complete'] ?? false);
} catch (Throwable $error) {
    $out['fatal'] = rrSafeError($error);
} finally {
    if ($catalog instanceof PDO && $catalog->inTransaction()) $catalog->rollBack();
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
}
$out['query_audit'] = $GLOBALS['RR_QUERY_AUDIT'];
$out['finished_at_utc'] = gmdate('c');
$out['read_result_is_not_publication_or_identity_mutation_permission'] = true;
echo rrJson($out), PHP_EOL;
exit($out['ok'] ? 0 : 2);
