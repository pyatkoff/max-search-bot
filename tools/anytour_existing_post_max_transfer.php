<?php
declare(strict_types=1);

namespace AnyTour\ExistingPostBatch;

use ChannelPostApiTransport;
use ChannelPostButtonTransport;
use ChannelPostIncomingAdapter;
use ChannelPostRegistryService;
use ChannelPostRuntime;
use ConversationDb;
use PDO;
use ProjectConfig;
use PromotedHotelPriceWriter;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/anytour_existing_post_batch.php';

const MAX_TRANSFER_PLAN = 'config/anytour_existing_post_transfer_max_800.json';
const MAX_TRANSFER_DIGEST = '536eac513fc1491363b27d9a909043abfb3ef6fece67245327d1e49eac1f2ab6';
const MAX_TRANSFER_OPERATION = 'anytour_sugar_marina_max_transfer_20261123';
const MAX_TRANSFER_LEASE = 'anytour-issue1566-max-sugar-marina-800-v1';

function maxTransferPlan(string $root, string $approvedDigest): array
{
    requirePost(hash_equals(MAX_TRANSFER_DIGEST, $approvedDigest), 'max_transfer_plan_not_approved');
    $bytes = file_get_contents($root.'/'.MAX_TRANSFER_PLAN, false, null, 0, 4097);
    requirePost(is_string($bytes) && hash_equals($approvedDigest, hash('sha256', $bytes)), 'max_transfer_plan_changed');
    return json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
}

function maxTransferRead(array $row): array
{
    $response = ChannelPostApiTransport::buttonRequest('max', 'GET', '/messages/'.$row['message_id'], [], null);
    $message = $response['data'] ?? null;
    requirePost(($response['http'] ?? 0) === 200 && empty($response['transport_error']) && is_array($message)
        && ($message['body']['mid'] ?? '') === $row['message_id']
        && ($message['recipient']['chat_type'] ?? '') === 'channel'
        && (string)($message['recipient']['chat_id'] ?? '') === $row['channel_id']
        && ($message['url'] ?? '') === $row['url'], 'max_transfer_native_identity_mismatch');
    $snapshot = ChannelPostIncomingAdapter::maxSnapshot($message);
    requirePost(is_array($snapshot) && ($snapshot['metadata']['capability'] ?? '') === 'supported'
        && count($snapshot['attachments']) === 1 && ($snapshot['attachments'][0]['type'] ?? '') === 'image',
        'max_transfer_native_photo_unconfirmed');
    return [$message, $snapshot];
}

function maxTransferOriginal(array $snapshot, array $row): bool
{
    return hash_equals($row['photo_sha256'], hash('sha256', (string)$snapshot['attachments'][0]['photo_id']))
        && hash_equals($row['caption_sha256'], hash('sha256', $snapshot['text']));
}

function maxTransferNativeEditor(array $channel, array $target): void
{
    $identity = ChannelPostRuntime::identity('max');
    requirePost(($identity['project_key'] ?? '') === 'anytour' && ($identity['bot_key'] ?? '') === $channel['bot_key']
        && (int)$channel['config_version'] === $target['config_version'], 'max_transfer_channel_configuration_changed');
    // MAX omits sender for channel posts. Validate the documented edit permission.
    $scope = $channel + ['expected_public_url'=>'https://max.ru/anytour'];
    $read = static function (string $platform, string $method, string $path, array $query, ?array $body) use ($target): array {
        $chat = '/chats/'.$target['channel_id'];
        requirePost($platform === 'max' && $method === 'GET' && $query === [] && $body === null
            && in_array($path, ['/me', $chat, $chat.'/members/me'], true), 'max_transfer_rights_scope_invalid');
        return ChannelPostApiTransport::buttonRequest($platform, $method, $path, $query, $body);
    };
    $rights = (new ChannelPostButtonTransport($read))->verifyChannel($scope);
    requirePost(($rights['state'] ?? '') === 'ready' && ($rights['receipt']['write_attempts'] ?? null) === 0,
        'max_transfer_native_rights_unconfirmed');
}

function maxTransferWithLease(PDO $pdo, array $plan, string $root, string $applicationSha, $journal, array &$result): void
{
    $registry = new ChannelPostRegistryService($pdo);
    $identity = ChannelPostRuntime::identity('max');
    $target = $plan['target'];
    $source = $plan['source'];
    $channel = $registry->channel('anytour', 'max', (string)($identity['bot_key'] ?? ''), $target['channel_id']);
    requirePost(is_array($channel), 'max_transfer_channel_configuration_changed');
    maxTransferNativeEditor($channel, $target);
    $sourceRegistry = $registry->publication($source['publication_id']);
    requirePost(is_array($sourceRegistry) && $sourceRegistry['project_key'] === 'anytour'
        && $sourceRegistry['platform'] === 'max' && $sourceRegistry['bot_key'] === $channel['bot_key']
        && $sourceRegistry['channel_id'] === $source['channel_id'] && $sourceRegistry['message_id'] === $source['message_id']
        && $sourceRegistry['version_id'] === $source['version_id'] && $sourceRegistry['state'] === 'sent'
        && hash_equals($source['content_sha256'], $sourceRegistry['content_hash']), 'max_transfer_source_registry_changed');
    [, $sourceSnapshot] = maxTransferRead($source);
    requirePost(maxTransferOriginal($sourceSnapshot, $source), 'max_transfer_source_photo_or_text_changed');
    $photoToken = $sourceSnapshot['attachments'][0]['token'] ?? null;
    requirePost(is_string($photoToken) && preg_match('/\A[\x21-\x7e]{1,2048}\z/D', $photoToken) === 1,
        'max_transfer_reusable_source_photo_missing');
    [$original, $oldSnapshot] = maxTransferRead($target);
    requirePost(maxTransferOriginal($oldSnapshot, $target) && $oldSnapshot['keyboard'] === []
        && (string)($original['timestamp'] ?? '') === (string)$target['published_timestamp'], 'max_transfer_target_changed');
    $publicBefore = PromotedHotelPriceWriter::maxPublicCaption(PromotedHotelPriceWriter::publicBytes($target['url']));
    requirePost($publicBefore === $oldSnapshot['text'], 'max_transfer_target_public_baseline_changed');
    $reservation = $registry->reserveMaxSugarMarinaTransfer($channel);
    $button = rtrim((string)$channel['bot_url'], '/').'?start='.rawurlencode($reservation['payload']);
    requirePost(in_array($channel['button_text'], ['Рассчитать на мои даты', '✈️ Рассчитать на мои даты'], true)
        && preg_match('~\Ahttps://max\.ru/[A-Za-z0-9_]{2,80}\?start=p_[A-Za-z0-9_-]{24}\z~D', $button) === 1,
        'max_transfer_button_configuration_changed');
    [, $freshSource] = maxTransferRead($source);
    $photoToken = $freshSource['attachments'][0]['token'] ?? null;
    requirePost(maxTransferOriginal($freshSource, $source) && is_string($photoToken)
        && preg_match('/\A[\x21-\x7e]{1,2048}\z/D', $photoToken) === 1, 'max_transfer_source_photo_or_text_changed');
    [$freshTarget, $freshSnapshot] = maxTransferRead($target);
    requirePost(maxTransferOriginal($freshSnapshot, $target) && $freshSnapshot['keyboard'] === []
        && (string)($freshTarget['timestamp'] ?? '') === (string)$target['published_timestamp'], 'max_transfer_target_changed');
    $registry->assertMaxSugarMarinaTransfer($channel, $reservation);
    transferRuntime($root, $applicationSha);
    transferJournal($journal, ['state'=>'write_started', 'publication_id'=>$reservation['id']]);
    $result['provider_write_attempts'] = 1;
    $response = ChannelPostApiTransport::maxHotelTransferRequest($target['channel_id'], $target['message_id'],
        $plan['text'], $photoToken, $channel['button_text'], $button);
    $result['provider_http'] = $response['http'] ?? 0;
    $result['provider_success'] = $response['data']['success'] ?? null;
    $code = $response['data']['code'] ?? null;
    if (is_string($code) && preg_match('/\A[a-zA-Z0-9_.-]{1,100}\z/D', $code)) {
        $result['provider_code'] = $code;
    }
    transferJournal($journal, ['state'=>'provider_result', 'http'=>$result['provider_http'],
        'success'=>$result['provider_success'], 'code'=>$result['provider_code'] ?? null]);
    // Reconcile by reading even after a timeout or a refusal. Never send a second PUT.
    [$published, $publishedSnapshot] = maxTransferRead($target);
    $saved = $registry->completeMaxSugarMarinaTransfer($channel, $reservation, $published, $plan, MAX_TRANSFER_DIGEST);
    $resolved = $registry->resolveToken('anytour', 'max', $channel['bot_key'], $reservation['payload']);
    requirePost(($resolved['version_id'] ?? null) === $saved['version_id'] && $saved['state'] === 'sent'
        && ($resolved['snapshot']['metadata']['hotel_catalog_id'] ?? null) === 800,
        'max_transfer_context_readback_mismatch');
    $result += ['publication_id'=>$saved['publication_id'], 'version_id'=>$saved['version_id'],
        'native_photo_sha256'=>hash('sha256', (string)$publishedSnapshot['attachments'][0]['photo_id']),
        'native_caption_sha256'=>hash('sha256', $publishedSnapshot['text']), 'button_hotel_id'=>800,
        'published_timestamp'=>$target['published_timestamp']];
    transferJournal($journal, ['state'=>'native_verified'] + $result);
    // Public caching changes the read schedule, not the number of writes.
    for ($i = 0; $i < 3; $i++) {
        if ($i !== 0) sleep(20);
        try {
            $caption = PromotedHotelPriceWriter::maxPublicCaption(PromotedHotelPriceWriter::publicBytes($target['url']));
            if ($caption === $plan['text']) {
                $result['public_caption_sha256'] = hash('sha256', $caption);
                transferJournal($journal, ['state'=>'public_verified'] + $result);
                $result['ok'] = true;
                return;
            }
        } catch (RuntimeException $ignored) {}
    }
    throw new RuntimeException('max_transfer_public_readback_pending');
}

function applyMaxTransfer(string $root, array $plan, string $applicationSha, string $nativeRunId, array &$result): void
{
    requirePost(preg_match('/\A[1-9][0-9]{0,19}\z/D', $nativeRunId) === 1, 'max_transfer_run_invalid');
    transferRuntime($root, $applicationSha);
    ob_start();
    try {
        require_once $root.'/config.php';
        require_once $root.'/services/PromotedHotelPriceWriter.php';
        require_once $root.'/services/ChannelPostRegistryService.php';
        require_once $root.'/services/ChannelPostButtonTransport.php';
        require_once $root.'/services/ChannelPostRuntime.php';
        require_once $root.'/integrations/ChannelPostApiTransport.php';
    } finally { ob_end_clean(); }
    requirePost(ProjectConfig::projectId() === 'anytour' && realpath(POST_OPS) === POST_OPS, 'max_transfer_runtime_changed');
    $priceLock = POST_OPS.'/monitor-price-after-collection-v1.jsonl';
    requirePost(is_file($priceLock) && !is_link($priceLock), 'max_transfer_shared_lease_unconfirmed');
    $lease = fopen($priceLock, 'rb');
    requirePost(is_resource($lease), 'max_transfer_shared_lease_unconfirmed');
    $pdo = null;
    $journal = null;
    $locked = false;
    $oldUmask = umask(0077);
    try {
        requirePost(flock($lease, LOCK_EX | LOCK_NB), 'max_transfer_content_editor_busy');
        $pdo = ConversationDb::connection();
        requirePost($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'max_transfer_native_database_required');
        $q = $pdo->prepare('SELECT GET_LOCK(?,0)');
        $q->execute([MAX_TRANSFER_LEASE]);
        $locked = (int)$q->fetchColumn() === 1;
        $q->closeCursor();
        requirePost($locked, 'max_transfer_target_editor_busy');
        $directory = POST_OPS.'/'.MAX_TRANSFER_LEASE;
        requirePost(!file_exists($directory) && !is_link($directory), 'max_transfer_previous_operation_readback_only');
        requirePost(mkdir($directory, 0700), 'max_transfer_journal_unavailable');
        $journal = fopen($directory.'/events.jsonl', 'xb');
        requirePost(is_resource($journal), 'max_transfer_journal_unavailable');
        transferJournal($journal, ['state'=>'preflight_started'] + $result);
        maxTransferWithLease($pdo, $plan, $root, $applicationSha, $journal, $result);
    } catch (Throwable $error) {
        if (is_resource($journal)) transferJournal($journal, ['state'=>'hold','reason'=>transferReason($error)] + $result);
        throw $error;
    } finally {
        if (is_resource($journal)) fclose($journal);
        if ($locked) {
            $q = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $q->execute([MAX_TRANSFER_LEASE]);
            $q->closeCursor();
        }
        fclose($lease);
        umask($oldUmask);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    $result = ['operation'=>MAX_TRANSFER_OPERATION, 'hotel_id'=>800, 'ok'=>false,
        'provider_write_attempts'=>0, 'supplier_requests'=>0, 'bot_starts'=>0];
    try {
        $root = dirname(__DIR__);
        $plan = maxTransferPlan($root, $argv[2] ?? '');
        $result += ['target_url'=>$plan['target']['url'], 'plan_sha256'=>MAX_TRANSFER_DIGEST];
        if (count($argv) === 3 && $argv[1] === '--check-plan') {
            $result['ok'] = true;
            $result['mode'] = 'offline_plan_check';
        } elseif (count($argv) === 5 && $argv[1] === '--apply') {
            $result += ['application_sha'=>$argv[3], 'native_run_id'=>$argv[4]];
            applyMaxTransfer($root, $plan, $argv[3], $argv[4], $result);
        } else {
            throw new RuntimeException('max_transfer_explicit_command_required');
        }
    } catch (Throwable $error) {
        $result['reason'] = transferReason($error);
        $result['state'] = 'requires_readback';
        $result['ok'] = false;
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    exit($result['ok'] ? 0 : 2);
}
