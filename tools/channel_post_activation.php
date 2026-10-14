<?php
declare(strict_types=1);

/**
 * One-time owner-authorized operator action; deliberately outside the webroot.
 * No DDL, account impersonation, webhook changes, source creation, customer
 * dispatch, publication registration, or provider writes exist in this script.
 * The sole apply mutation uses the installed registry's transactional CAS.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new RuntimeException('operation_warning');
});

const ACTIVATION_ROOT = '/var/www/anytoour/data/www/app.anytoour.ru';
const ACTIVATION_PROJECT = 'anytour';
const ACTIVATION_PLATFORM = 'max';
const ACTIVATION_BOT = 'id9704048781_1_bot';
const ACTIVATION_BOT_URL = 'https://max.ru/id9704048781_1_bot';
const ACTIVATION_CHANNEL = '-80095447388179';
const ACTIVATION_SOURCE = 'max_anytour_msk1';
const ACTIVATION_BUTTON = 'Рассчитать на мои даты';
const ACTIVATION_ORIGINAL_MID = 'mid.ffffb727534f6fed01a139de2c83326c';

function activationEmit(array $result, int $code): never
{
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    exit($code);
}

function activationCheck(bool $condition, string $reason): void
{
    if (!$condition) throw new DomainException($reason);
}

function activationGit(array $arguments): array
{
    $pipes = [];
    $process = proc_open(array_merge(['git', '-C', ACTIVATION_ROOT], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    activationCheck(is_resource($process), 'checkout_verification_unavailable');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); // Suppressed; never emit shell/provider errors.
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    return ['code' => $code, 'output' => trim(is_string($stdout) ? $stdout : '')];
}

function activationCheckout(string $expectedSha): void
{
    activationCheck(is_dir(ACTIVATION_ROOT), 'production_checkout_unavailable');
    $head = activationGit(['rev-parse', 'HEAD']);
    activationCheck($head['code'] === 0 && hash_equals($expectedSha, $head['output']), 'production_sha_mismatch');
    activationCheck(activationGit(['diff', '--quiet'])['code'] === 0
        && activationGit(['diff', '--cached', '--quiet'])['code'] === 0, 'tracked_checkout_drift');
}

function activationOne(PDO $pdo, string $query, array $arguments): ?array
{
    $statement = $pdo->prepare($query); $statement->execute($arguments);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    activationCheck(count($rows) <= 1, 'ambiguous_configuration');
    return $rows[0] ?? null;
}

function activationSource(PDO $pdo): array
{
    $project = activationOne($pdo, 'SELECT id,project_key,is_active FROM projects WHERE project_key=? LIMIT 2', [ACTIVATION_PROJECT]);
    activationCheck($project !== null && $project['project_key'] === ACTIVATION_PROJECT
        && (int)$project['is_active'] === 1, 'active_project_required');
    $source = activationOne($pdo, 'SELECT id,project_id,source_key,channel,handling_mode,is_active FROM conversation_sources WHERE project_id=? AND source_key=? LIMIT 2',
        [(int)$project['id'], ACTIVATION_SOURCE]);
    activationCheck($source !== null && $source['source_key'] === ACTIVATION_SOURCE
        && $source['channel'] === ACTIVATION_PLATFORM && (int)$source['is_active'] === 1
        && in_array($source['handling_mode'], ['manager', 'ask'], true), 'exact_active_manager_source_required');
    return ['project' => $project, 'source' => $source];
}

function activationCurrent(PDO $pdo, array $scope): ?array
{
    $row = activationOne($pdo, 'SELECT * FROM post_channels WHERE platform=? AND channel_id=? LIMIT 2', [ACTIVATION_PLATFORM, ACTIVATION_CHANNEL]);
    if ($row !== null) {
        activationCheck((int)$row['project_id'] === (int)$scope['project']['id']
            && (int)$row['source_id'] === (int)$scope['source']['id']
            && $row['platform'] === ACTIVATION_PLATFORM && $row['channel_id'] === ACTIVATION_CHANNEL
            && $row['bot_key'] === ACTIVATION_BOT && $row['canonical_bot_username'] === ACTIVATION_BOT
            && $row['bot_url'] === ACTIVATION_BOT_URL && (int)$row['config_version'] > 0, 'existing_channel_identity_conflict');
    }
    return $row;
}

function activationOtherChannels(PDO $pdo): array
{
    $statement = $pdo->prepare('SELECT * FROM post_channels WHERE NOT (platform=? AND channel_id=?) ORDER BY id');
    $statement->execute([ACTIVATION_PLATFORM, ACTIVATION_CHANNEL]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    return ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
}

function activationMaxMessage(string $mid): array
{
    activationCheck(preg_match('/\Amid\.[A-Za-z0-9_.-]{1,195}\z/D', $mid) === 1, 'invalid_observed_message_id');
    $response = ChannelPostApiTransport::buttonRequest('max', 'GET', '/messages/' . $mid, [], null);
    activationCheck(empty($response['transport_error']) && ($response['http'] ?? 0) >= 200
        && ($response['http'] ?? 0) < 300 && is_array($response['data'] ?? null), 'provider_message_read_failed');
    $message = $response['data'];
    activationCheck(($message['body']['mid'] ?? '') === $mid
        && (string)($message['recipient']['chat_id'] ?? '') === ACTIVATION_CHANNEL
        && ($message['recipient']['chat_type'] ?? '') === 'channel', 'provider_message_identity_mismatch');
    $snapshot = ChannelPostIncomingAdapter::maxSnapshot($message);
    activationCheck(is_array($snapshot), 'provider_message_invalid');
    return ['message' => $message, 'snapshot' => $snapshot];
}

function activationPostSummary(array $observed): array
{
    $message = $observed['message']; $snapshot = $observed['snapshot'];
    $photos = [];
    foreach ($snapshot['attachments'] as $attachment) {
        if (($attachment['type'] ?? '') === 'image') $photos[] = (string)($attachment['photo_id'] ?? '');
    }
    $owned = 0; $buttons = 0;
    foreach ($snapshot['keyboard'] as $row) foreach ((array)$row as $button) {
        if (!is_array($button)) continue;
        $buttons++;
        if (($button['type'] ?? '') === 'link' && is_string($button['url'] ?? null)
            && preg_match('/\A' . preg_quote(ACTIVATION_BOT_URL . '?start=', '/') . 'p_[A-Za-z0-9_-]{24}\z/D', $button['url'])) $owned++;
    }
    return ['message_id' => (string)$message['body']['mid'], 'photo_count' => count($photos),
        'photo_identity_sha256' => hash('sha256', json_encode($photos, JSON_THROW_ON_ERROR)),
        'caption_sha256' => hash('sha256', json_encode($message['body']['text'] ?? null, JSON_THROW_ON_ERROR)),
        'markup_sha256' => hash('sha256', json_encode($message['body']['markup'] ?? null, JSON_THROW_ON_ERROR)),
        'link_sha256' => hash('sha256', json_encode($message['link'] ?? null, JSON_THROW_ON_ERROR)),
        'content_sha256' => ChannelPostIncomingAdapter::contentFingerprint($snapshot, '', ACTIVATION_BOT_URL),
        'provider_post_url_available' => $snapshot['post_url'] !== '', 'button_count' => $buttons,
        'owned_post_button_count' => $owned, 'capability' => ($snapshot['metadata']['capability'] ?? '') === 'supported' ? 'supported' : 'requires_attention'];
}

function activationPublications(PDO $pdo, ?array $channel): array
{
    if ($channel === null) return ['publication_count' => 0, 'entry_count' => 0, 'states' => [], 'latest' => []];
    $id = (int)$channel['id'];
    $statement = $pdo->prepare('SELECT v.state,COUNT(*) AS total FROM post_publications pp JOIN post_versions v ON v.id=pp.current_version_id WHERE pp.post_channel_id=? GROUP BY v.state ORDER BY v.state');
    $statement->execute([$id]); $states = [];
    $safeStates = ['pending', 'claimed', 'write_started', 'sent', 'unknown', 'requires_attention', 'superseded'];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $state = in_array($row['state'], $safeStates, true) ? $row['state'] : 'unrecognized';
        $states[$state] = ($states[$state] ?? 0) + (int)$row['total'];
    }
    $statement = $pdo->prepare('SELECT COUNT(*) FROM post_entries pe JOIN post_publications pp ON pp.id=pe.publication_id WHERE pp.post_channel_id=?');
    $statement->execute([$id]); $entries = (int)$statement->fetchColumn();
    $statement = $pdo->prepare('SELECT pp.external_message_id,pp.start_payload,v.snapshot_json,v.state FROM post_publications pp JOIN post_versions v ON v.id=pp.current_version_id WHERE pp.post_channel_id=? ORDER BY pp.id DESC LIMIT 2');
    $statement->execute([$id]); $latest = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $saved = json_decode((string)$row['snapshot_json'], true, 64, JSON_THROW_ON_ERROR);
        activationCheck(is_array($saved) && preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/D', (string)$row['start_payload']) === 1, 'publication_readback_invalid');
        $observed = activationMaxMessage((string)$row['external_message_id']);
        $summary = activationPostSummary($observed);
        $url = ACTIVATION_BOT_URL . '?start=' . rawurlencode((string)$row['start_payload']);
        $summary['stored_content_matches_provider'] = hash_equals(
            ChannelPostIncomingAdapter::contentFingerprint($saved, $url, ACTIVATION_BOT_URL),
            ChannelPostIncomingAdapter::contentFingerprint($observed['snapshot'], $url, ACTIVATION_BOT_URL));
        $summary['exact_native_button_present'] = ChannelPostIncomingAdapter::containsButton($observed['snapshot'], $url, (string)$channel['button_text']);
        $summary['single_owned_native_button_verified'] = $summary['exact_native_button_present'] && $summary['owned_post_button_count'] === 1;
        $summary['state'] = in_array($row['state'], $safeStates, true) ? $row['state'] : 'unrecognized';
        $latest[] = $summary;
    }
    return ['publication_count' => array_sum($states), 'entry_count' => $entries, 'states' => $states, 'latest' => $latest];
}

$apply = false; $requireEnabled = false; $expectedSha = null; $mutationAttempted = false; $mutationCompleted = false;
$report = ['operation' => 'owner_test_channel_activation', 'platform' => ACTIVATION_PLATFORM,
    'project_key' => ACTIVATION_PROJECT, 'bot_key' => ACTIVATION_BOT,
    'channel_id' => ACTIVATION_CHANNEL, 'source_key' => ACTIVATION_SOURCE];
try {
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--apply' && !$apply) $apply = true;
        elseif ($argument === '--require-enabled' && !$requireEnabled) $requireEnabled = true;
        elseif (str_starts_with($argument, '--expected-sha=') && $expectedSha === null) $expectedSha = substr($argument, 15);
        else throw new DomainException('invalid_arguments');
    }
    activationCheck(is_string($expectedSha) && preg_match('/\A[a-f0-9]{40}\z/D', $expectedSha) === 1, 'exact_expected_sha_required');
    $report['mode'] = $apply ? 'apply' : 'read_only'; $report['expected_sha'] = $expectedSha;
    activationCheckout($expectedSha);
    activationCheck(is_file(ACTIVATION_ROOT . '/config.php'), 'production_configuration_unavailable');
    // Configuration must not accidentally emit credentials or notices into output.
    $bufferLevel = ob_get_level(); ob_start();
    try { require_once ACTIVATION_ROOT . '/config.php'; }
    finally { while (ob_get_level() > $bufferLevel) ob_end_clean(); }
    require_once ACTIVATION_ROOT . '/services/ChannelPostRuntime.php';
    require_once ACTIVATION_ROOT . '/services/ChannelPostButtonTransport.php';
    require_once ACTIVATION_ROOT . '/services/MigrationRunner.php';
    // Native MigrationRunner.status semantics, with its implicit DDL replaced
    // by a SELECT guard. Missing schema blocks; this operation never creates it.
    class ReadOnlyChannelActivationMigrationRunner extends MigrationRunner
    {
        private PDO $readPdo;
        public function __construct(PDO $pdo) { $this->readPdo = $pdo; parent::__construct(ACTIVATION_ROOT . '/migrations', $pdo); }
        public function ensureTable(): void
        {
            $q = $this->readPdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='schema_migrations'");
            activationCheck((int)$q->fetchColumn() === 1, 'migration_registry_unavailable');
        }
    }
    activationCheck(ConversationDb::isConfigured(), 'conversation_storage_unavailable');
    $pdo = ConversationDb::connection();
    $migrations = (new ReadOnlyChannelActivationMigrationRunner($pdo))->status();
    $pending = 0; $mismatch = 0; $featureApplied = false;
    foreach ($migrations as $migration) {
        if (!$migration['applied']) $pending++;
        if ($migration['applied'] && !$migration['checksum_ok']) $mismatch++;
        if ($migration['version'] === '033_channel_post_context.sql') $featureApplied = $migration['applied'] && $migration['checksum_ok'];
    }
    $report['migrations'] = ['count' => count($migrations), 'pending' => $pending,
        'checksum_errors' => $mismatch, 'channel_post_context_applied' => $featureApplied];
    activationCheck($pending === 0 && $mismatch === 0 && $featureApplied, 'migration_readiness_required');
    $identity = ChannelPostRuntime::identity(ACTIVATION_PLATFORM);
    activationCheck($identity === ['project_key' => ACTIVATION_PROJECT, 'platform' => ACTIVATION_PLATFORM,
        'bot_key' => ACTIVATION_BOT, 'bot_url' => ACTIVATION_BOT_URL], 'installed_canonical_identity_mismatch');
    $scope = activationSource($pdo);
    $q = $pdo->prepare("SELECT COUNT(DISTINCT m.id) FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id WHERE mp.project_id=? AND m.is_active=1 AND m.role='admin'");
    $q->execute([(int)$scope['project']['id']]);
    $report['authorized_active_project_admin_count'] = (int)$q->fetchColumn();
    $report['source_handling_mode'] = $scope['source']['handling_mode'];
    $before = activationCurrent($pdo, $scope);
    $otherBefore = activationOtherChannels($pdo);
    $configuration = $identity + ['canonical_bot_username' => ACTIVATION_BOT,
        'channel_id' => ACTIVATION_CHANNEL, 'source_key' => ACTIVATION_SOURCE,
        'display_name' => $before !== null && trim((string)$before['display_name']) !== '' ? (string)$before['display_name'] : 'Тестовый канал MAX',
        'button_text' => ACTIVATION_BUTTON, 'is_enabled' => true,
        'expected_version' => $before !== null ? (int)$before['config_version'] : 0];
    $verification = (new ChannelPostButtonTransport())->verifyChannel($configuration);
    $report['provider_capability_state'] = ($verification['state'] ?? '') === 'ready' ? 'ready' : (($verification['state'] ?? '') === 'unknown' ? 'unknown' : 'requires_attention');
    activationCheck(($verification['state'] ?? '') === 'ready', 'provider_capability_not_ready');
    $originalBefore = activationPostSummary(activationMaxMessage(ACTIVATION_ORIGINAL_MID));
    $report['original_post'] = $originalBefore;
    $alreadyEnabled = $before !== null && (int)$before['is_enabled'] === 1 && $before['button_text'] === ACTIVATION_BUTTON;
    $report['channel_before'] = ['exists' => $before !== null, 'enabled' => $before !== null && (int)$before['is_enabled'] === 1,
        'config_version' => $before !== null ? (int)$before['config_version'] : 0, 'desired_button_label_matches' => $before !== null && $before['button_text'] === ACTIVATION_BUTTON];
    $report['other_channels_before'] = $otherBefore;
    $after = $before;
    if ($apply && !$alreadyEnabled) {
        activationCheckout($expectedSha);
        // Native registry transaction revalidates the exact active source and
        // channel identity and consumes expected_version as a compare-and-swap.
        $mutationAttempted = true;
        $after = (new ChannelPostRegistryService($pdo))->configureChannel($configuration);
        $mutationCompleted = true;
    }
    $scopeAfter = activationSource($pdo);
    $after = activationCurrent($pdo, $scopeAfter);
    $otherAfter = activationOtherChannels($pdo);
    $originalAfter = $apply && $mutationCompleted ? activationPostSummary(activationMaxMessage(ACTIVATION_ORIGINAL_MID)) : $originalBefore;
    $report['channel_after'] = ['exists' => $after !== null, 'enabled' => $after !== null && (int)$after['is_enabled'] === 1,
        'config_version' => $after !== null ? (int)$after['config_version'] : 0];
    $report['configuration_write_attempted'] = $mutationAttempted;
    $report['configuration_write_completed'] = $mutationCompleted;
    $report['other_channels_unchanged'] = $otherBefore === $otherAfter;
    $report['other_channels_after'] = $otherAfter;
    $report['original_post_unchanged'] = $apply && $mutationCompleted ? $originalBefore === $originalAfter : null;
    activationCheck($otherBefore === $otherAfter, 'other_channel_configuration_changed');
    activationCheck($originalBefore === $originalAfter, 'original_post_changed_during_operation');
    if ($apply || $requireEnabled) activationCheck($after !== null && (int)$after['is_enabled'] === 1
        && $after['button_text'] === ACTIVATION_BUTTON, 'channel_enable_readback_failed');
    $report['publications'] = activationPublications($pdo, $after);
    $report['ok'] = true;
    $report['state'] = $apply ? ($mutationCompleted ? 'enabled' : 'already_enabled') : 'read_only_ready';
    $report['provider_write_attempts'] = 0;
    activationEmit($report, 0);
} catch (Throwable $error) {
    $allowedReasons = ['invalid_arguments', 'exact_expected_sha_required', 'production_checkout_unavailable',
        'checkout_verification_unavailable', 'production_sha_mismatch', 'tracked_checkout_drift',
        'production_configuration_unavailable', 'conversation_storage_unavailable', 'migration_registry_unavailable',
        'migration_readiness_required', 'installed_canonical_identity_mismatch', 'ambiguous_configuration',
        'active_project_required', 'exact_active_manager_source_required', 'existing_channel_identity_conflict',
        'provider_capability_not_ready', 'provider_message_read_failed', 'provider_message_identity_mismatch',
        'provider_message_invalid', 'invalid_observed_message_id', 'publication_readback_invalid',
        'other_channel_configuration_changed', 'original_post_changed_during_operation', 'channel_enable_readback_failed'];
    $reason = $error instanceof DomainException && in_array($error->getMessage(), $allowedReasons, true)
        ? $error->getMessage() : ($mutationAttempted && !$mutationCompleted ? 'configuration_write_outcome_requires_readback' : 'operation_unavailable');
    $report += ['mode' => $apply ? 'apply' : 'read_only'];
    $report['ok'] = false; $report['state'] = $mutationAttempted ? 'requires_readback' : 'blocked';
    $report['reason'] = $reason; $report['configuration_write_attempted'] = $mutationAttempted;
    $report['configuration_write_completed'] = $mutationCompleted; $report['provider_write_attempts'] = 0;
    activationEmit($report, 1);
}
