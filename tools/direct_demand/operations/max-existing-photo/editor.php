<?php
declare(strict_types=1);

const MAIN_MAX = '-68765999669267';
const HOTEL_MAX = '-80492244209683';
const MAIN_REGISTRY = 4;
const HOTEL_REGISTRY = 9;
const MAX_BOT = 'id9704048781_1_bot';
const MAX_PROJECT = 'anytour';
const MAX_MAIN_SOURCE = 'max_anytour_msk1';
const MAX_FIRST_GROUP = [174,12107,132,469,12111,70048,82606,1344,260,415,2493,3452,4181,37668,43676,64895,73974,74503,99713];

function maxEditNeed(bool $ok, string $reason): void
{
    if (!$ok) {
        throw new RuntimeException($reason);
    }
}

function maxEditJson(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function maxEditInstalled(string $sha): void
{
    maxEditNeed(getcwd() === '/var/www/anytoour/data/www/app.anytoour.ru'
        && trim((string)shell_exec('git rev-parse HEAD 2>/dev/null')) === $sha
        && trim((string)shell_exec('git status --porcelain --untracked-files=no 2>/dev/null')) === '', 'installed_checkout_changed');
}

function maxEditUrl(string $mid, string $channel): string
{
    maxEditNeed(preg_match('/\Amid\.[0-9a-f]{32}\z/D', $mid) === 1
        && in_array($channel, [MAIN_MAX, HOTEL_MAX], true), 'invalid_native_message_id');
    $bytes = hex2bin(substr($mid, -16));
    return 'https://max.ru/' . ($channel === MAIN_MAX ? 'anytour' : 'channel_anytour') . '/'
        . rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function maxEditPlan(string $path, string $expected, ?array $approval = null): array
{
    maxEditNeed(!is_link($path) && is_file($path) && filesize($path) <= 16384, 'invalid_reviewed_plan_file');
    $raw = file_get_contents($path);
    maxEditNeed(is_string($raw) && hash_equals($expected, hash('sha256', $raw)), 'reviewed_plan_hash_changed');
    if ($approval !== null) {
        maxEditNeed(hash_equals($approval['sha256'], $expected) && hash_equals(maxEditJson($approval['plan']), $raw), 'row_outside_reviewed_batch');
    }
    $plan = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    $required = ['kind', 'hotel', 'hotel_name', 'target_mid', 'target_ms', 'target_url', 'target_fingerprint',
        'source_publication', 'source_version', 'source_content_hash', 'source_mid', 'source_url', 'caption'];
    maxEditNeed(is_array($plan) && array_diff($required, array_keys($plan)) === []
        && array_diff(array_keys($plan), [...$required, 'source_aliases', 'target_photo_count']) === [], 'reviewed_plan_scope_changed');
    maxEditNeed($plan['kind'] === 'expired_reviewed_photo_promotion' && ($approval !== null || in_array($plan['hotel'], MAX_FIRST_GROUP, true))
        && !in_array($plan['hotel'], [174, 415], true)
        && is_int($plan['target_ms']) && $plan['target_ms'] > 0
        && is_int($plan['source_publication']) && $plan['source_publication'] > 0
        && is_int($plan['source_version']) && $plan['source_version'] > 0
        && is_string($plan['source_content_hash']) && preg_match('/\A[a-f0-9]{64}\z/D', $plan['source_content_hash']) === 1
        && is_string($plan['hotel_name']) && trim($plan['hotel_name']) === $plan['hotel_name']
        && mb_strlen($plan['hotel_name']) >= 5, 'reviewed_hotel_or_old_target_invalid');
    maxEditNeed(is_string($plan['target_mid']) && is_string($plan['source_mid'])
        && $plan['target_mid'] !== $plan['source_mid']
        && $plan['target_url'] === maxEditUrl($plan['target_mid'], MAIN_MAX)
        && $plan['source_url'] === maxEditUrl($plan['source_mid'], HOTEL_MAX)
        && is_string($plan['target_fingerprint']) && preg_match('/\A[a-f0-9]{64}\z/D', $plan['target_fingerprint']) === 1,
        'exact_old_main_and_hotel_source_required');
    if (isset($plan['target_photo_count'])) {
        maxEditNeed($approval !== null && is_int($plan['target_photo_count'])
            && $plan['target_photo_count'] >= 2 && $plan['target_photo_count'] <= 11,
            'unapproved_target_album');
    }
    $caption = $plan['caption'];
    maxEditNeed(is_string($caption) && trim($caption) === $caption && mb_strlen($caption) >= 60
        && mb_strlen($caption) <= 1300 && !preg_match('/(?:https?:\/\/|₽|\$|€|\b(?:RUB|USD|EUR)\b|\bруб(?:\.|лей|ля)?\b)/iu', $caption)
        && str_contains(mb_strtolower(explode("\n", $caption, 2)[0]), mb_strtolower($plan['hotel_name'])),
        'reviewed_caption_invalid');
    $aliases = $plan['source_aliases'] ?? [$plan['hotel_name']];
    maxEditNeed(is_array($aliases) && array_is_list($aliases) && count($aliases) >= 1
        && count($aliases) <= 6, 'reviewed_hotel_aliases_invalid');
    foreach ($aliases as $alias) {
        maxEditNeed(is_string($alias) && trim($alias) === $alias && mb_strlen($alias) >= 6
            && mb_strlen($alias) <= 130, 'reviewed_hotel_aliases_invalid');
    }
    $plan['source_aliases'] = $aliases;
    return $plan;
}

function maxEditRead(string $mid, string $channel): array
{
    $read = ChannelPostApiTransport::buttonRequest('max', 'GET', '/messages/' . $mid, [], null);
    $message = $read['data'] ?? null;
    maxEditNeed(empty($read['transport_error']) && ($read['http'] ?? 0) === 200 && is_array($message)
        && ($message['body']['mid'] ?? '') === $mid && ($message['recipient']['chat_type'] ?? '') === 'channel'
        && (string)($message['recipient']['chat_id'] ?? '') === $channel, 'native_message_identity_unconfirmed');
    $snapshot = ChannelPostIncomingAdapter::maxSnapshot($message);
    $url = maxEditUrl($mid, $channel);
    maxEditNeed(is_array($snapshot) && ($snapshot['metadata']['capability'] ?? '') === 'supported'
        && (($snapshot['post_url'] ?? '') === '' || basename((string)$snapshot['post_url']) === basename($url)),
        'native_photo_or_url_unsupported');
    $snapshot['post_url'] = $url;
    return ['message' => $message, 'snapshot' => $snapshot];
}

function maxEditPhotos(array $snapshot): array
{
    $media = $snapshot['attachments'] ?? [];
    maxEditNeed(is_array($media) && array_is_list($media) && count($media) >= 1 && count($media) <= 11,
        'ordinary_photo_only_message_required');
    $ids = [];
    foreach ($media as $photo) {
        maxEditNeed(($photo['provider'] ?? '') === 'max' && ($photo['type'] ?? '') === 'image'
            && preg_match('/\A[0-9]+\z/D', (string)($photo['photo_id'] ?? '')) === 1,
            'ordinary_photo_only_message_required');
        $ids[] = (string)$photo['photo_id'];
    }
    return $ids;
}

function maxEditExistingPublication(PDO $pdo, string $mid): ?int
{
    $statement = $pdo->prepare('SELECT id FROM post_publications WHERE post_channel_id=? AND external_message_id=?');
    $statement->execute([MAIN_REGISTRY, $mid]);
    $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
    maxEditNeed(count($ids) <= 1, 'native_publication_not_unique');
    return $ids ? (int)$ids[0] : null;
}

function maxEditTarget(PDO $pdo, array $plan): array
{
    $target = maxEditRead($plan['target_mid'], MAIN_MAX);
    $snapshot = $target['snapshot'];
    maxEditNeed((string)($target['message']['timestamp'] ?? '') === (string)$plan['target_ms']
        && hash_equals($plan['target_fingerprint'], ChannelPostIncomingAdapter::contentFingerprint($snapshot))
        && empty($snapshot['keyboard']) && empty($target['message']['link'])
        && !preg_match('/(?:отзыв\s+(?:турист|гост|семь)|видеокружок|кружок менеджера)/iu', (string)$snapshot['text'])
        && maxEditExistingPublication($pdo, $plan['target_mid']) === null, 'old_promotion_changed_or_already_bound');
    $photos = maxEditPhotos($snapshot);
    maxEditNeed(count($photos) === ($plan['target_photo_count'] ?? 1), 'reviewed_target_photo_count_changed');
    return $photos;
}

function maxEditEvidence(PDO $pdo, ChannelPostRegistryService $registry, array $plan, string $sha): array
{
    $channel = $registry->channel(MAX_PROJECT, 'max', MAX_BOT, MAIN_MAX);
    maxEditNeed(is_array($channel) && (int)$channel['id'] === MAIN_REGISTRY && $channel['source_key'] === MAX_MAIN_SOURCE
        && ($channel['post_mode'] ?? 'append_button') === 'append_button'
        && (new ChannelPostButtonTransport())->verifyChannel($channel)['state'] === 'ready', 'main_channel_edit_not_ready');
    $targetPhotos = maxEditTarget($pdo, $plan);
    $sourceRow = $registry->publication($plan['source_publication']);
    $sourceHotel = (int)($sourceRow['snapshot']['metadata']['hotel_catalog_id'] ?? 0);
    maxEditNeed(is_array($sourceRow) && ($sourceRow['state'] ?? '') === 'sent'
        && (int)($sourceRow['channel']['id'] ?? 0) === HOTEL_REGISTRY
        && ($sourceRow['external_message_id'] ?? '') === $plan['source_mid']
        && is_string($sourceRow['content_hash'] ?? null) && hash_equals($plan['source_content_hash'], $sourceRow['content_hash'])
        && (int)($sourceRow['version_id'] ?? 0) === $plan['source_version']
        && ($sourceHotel === 0 || $sourceHotel === $plan['hotel']),
        'canonical_hotel_source_mismatch');
    $source = maxEditRead($plan['source_mid'], HOTEL_MAX);
    $sourceTitle = mb_substr((string)$source['snapshot']['text'], 0, 300);
    $sourceNameMatches = false;
    foreach ($plan['source_aliases'] as $alias) {
        if (mb_stripos($sourceTitle, $alias) !== false) {
            $sourceNameMatches = true;
            break;
        }
    }
    maxEditNeed($sourceNameMatches, 'verified_source_hotel_title_unconfirmed');
    $sourceIds = maxEditPhotos($source['snapshot']);
    $sourceToken = $source['snapshot']['attachments'][0]['token'] ?? '';
    maxEditNeed(count($sourceIds) === 1 && is_string($sourceToken) && $sourceToken !== ''
        && strlen($sourceToken) <= 4096 && maxEditPhotos($sourceRow['snapshot']) === $sourceIds,
        'verified_source_photo_reuse_unavailable');
    $proof = [
        'installed_sha' => $sha, 'hotel_id' => $plan['hotel'], 'target_mid' => $plan['target_mid'],
        'target_url' => $plan['target_url'], 'target_ms' => $plan['target_ms'],
        'target_fingerprint' => $plan['target_fingerprint'], 'target_photo_ids' => $targetPhotos,
        'source_publication' => $plan['source_publication'], 'source_version' => (int)$sourceRow['version_id'],
        'source_content_hash' => $sourceRow['content_hash'],
        'source_mid' => $plan['source_mid'], 'source_url' => $plan['source_url'], 'source_photo_id' => $sourceIds[0],
        'target_config' => (int)$channel['config_version'], 'caption_sha256' => hash('sha256', $plan['caption']),
    ];
    return [$proof, $channel, $sourceToken];
}

function maxEditReceipt($file, array $data): void
{
    $line = maxEditJson($data) . "\n";
    maxEditNeed(fwrite($file, $line) === strlen($line) && fflush($file) && fsync($file), 'durable_edit_receipt_failed');
}

function maxEditValidatePost(array $read, array $proof, array $plan): void
{
    maxEditNeed((string)($read['message']['timestamp'] ?? '') === (string)$proof['target_ms']
        && empty($read['message']['link']) && $read['snapshot']['post_url'] === $proof['target_url']
        && $read['snapshot']['text'] === $plan['caption']
        && maxEditPhotos($read['snapshot']) === [$proof['source_photo_id']], 'updated_text_photo_or_history_unconfirmed');
}

/** Reconcile an ambiguous body response by reading the same old MID once. */
function maxEditBodyOnce($file, array $plan, array $proof, callable $send, callable $read): array
{
    $result = $send();
    maxEditReceipt($file, ['state'=>'first_edit_response_received', 'provider_explicit_success'=>$result['success'], 'provider_error_http'=>$result['http']]);
    $observed = $read($plan['target_mid'], MAIN_MAX);
    maxEditValidatePost($observed, $proof, $plan);
    return $observed;
}

function maxEditAssertLeaseIdentity($lease, string $path): void
{
    clearstatcache(true, $path);
    $named = lstat($path);
    $held = fstat($lease);
    maxEditNeed(is_array($named) && is_array($held) && ($named['mode'] & 0170000) === 0100000
        && $named['dev'] === $held['dev'] && $named['ino'] === $held['ino'], 'editor_lock_unavailable');
}

function maxEditBatchLease(string $path, bool $writing)
{
    maxEditNeed(is_file($path) && !is_link($path), 'editor_lock_unavailable');
    $lease = fopen($path, 'rb');
    $mode = $writing ? LOCK_EX : LOCK_SH;
    maxEditNeed(is_resource($lease) && flock($lease, $mode | LOCK_NB), 'editor_busy');
    maxEditAssertLeaseIdentity($lease, $path);
    return $lease;
}

function maxEditRun(array $argv, ?array $batchApproval = null): int
{
    $lease = null;
    $file = null;
    $pdo = null;
    $lock = '';
    $held = false;
    $readOnlyTransaction = false;
    $started = false;
    $proof = [];
    try {
        $mode = $argv[1] ?? '';
        $path = $argv[2] ?? '';
        $planSha = $argv[3] ?? '';
        $sha = $argv[4] ?? '';
        $approved = $argv[5] ?? '';
        $preparing = $mode === '--prepare-check';
        $checking = $mode === '--check' || ($preparing && $batchApproval !== null);
        maxEditNeed(($checking || $mode === '--apply')
            && preg_match('/\A[a-f0-9]{64}\z/D', $planSha) === 1 && preg_match('/\A[a-f0-9]{40}\z/D', $sha) === 1
            && ($checking ? count($argv) === 5 : count($argv) === 6
                && preg_match('/\A[a-f0-9]{64}\z/D', $approved) === 1), 'exact_mode_plan_and_release_required');
        $plan = maxEditPlan($path, $planSha, $batchApproval);
        $base = '/var/www/anytoour/data/channel-post-operations';
        $leasePath = $base . '/monitor-price-after-collection-v1.jsonl';
        if ($batchApproval !== null && !$preparing) {
            // The host installer holds this same inode through the checkout switch.
            $lease = maxEditBatchLease($leasePath, $mode === '--apply');
        }
        maxEditInstalled($sha);
        $root = getcwd();
        ob_start();
        try {
            require $root . '/config.php';
            require_once $root . '/services/ChannelPostRuntime.php';
            require_once $root . '/services/ChannelPostEntryService.php';
            require_once $root . '/services/ChannelPostAutomationService.php';
            require_once $root . '/services/MaxTransport.php';
        } finally {
            ob_end_clean();
        }
        $pdo = ConversationDb::connection();
        $registry = new ChannelPostRegistryService($pdo);
        $key = substr(hash('sha256', MAIN_MAX . '|' . $plan['target_mid']), 0, 36);
        if ($preparing) {
            // This snapshot is provisional. The writer re-reads the pair under the exclusive lease.
            maxEditNeed($pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY') !== false
                && $pdo->beginTransaction(), 'read_only_snapshot_unavailable');
            $readOnlyTransaction = true;
        }
        $lock = 'anytour-1566-main-max-' . $key;
        $directory = $base . '/' . $lock;
        maxEditNeed(realpath($base) === $base && !is_link($directory) && !file_exists($directory), 'target_receipt_exists_no_replay');
        if ($mode === '--apply') {
            $query = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $query->execute([$lock]);
            $held = (int)$query->fetchColumn() === 1;
            $query->closeCursor();
            maxEditNeed($held, 'same_target_edit_in_progress');
        }
        [$proof, $channel, $imageToken] = maxEditEvidence($pdo, $registry, $plan, $sha);
        if ($batchApproval !== null) {
            require_once __DIR__ . '/batch_input.php';
            require_once __DIR__ . '/observe_pair.php';
            require_once $root . '/tools/anytour_existing_post_max_transfer.php';
            \AnyTour\ExistingPostBatch\maxTransferNativeEditor($channel, ['channel_id'=>MAIN_MAX, 'config_version'=>$batchApproval['config_version']]);
            $current = maxBatchObservePair($pdo, $registry, $channel, $batchApproval['approved_row']);
            maxBatchVerifyCurrent($batchApproval, $current, $proof['target_config']);
        }
        $sealed = hash('sha256', maxEditJson(['plan_sha256' => $planSha] + $proof));
        if ($checking) {
            if ($preparing) {
                maxEditNeed($pdo->rollBack(), 'read_only_snapshot_unavailable');
                $readOnlyTransaction = false;
                maxEditInstalled($sha);
            }
            echo maxEditJson(['state' => 'ready_read_only', 'checkpoint_sha256' => $sealed,
                'provider_writes' => 0, 'new_posts' => 0, 'ad_writes' => 0] + $proof), "\n";
            return 0;
        }
        maxEditNeed(hash_equals($approved, $sealed), 'approved_checkpoint_changed');
        maxEditInstalled($sha);
        maxEditNeed(maxEditTarget($pdo, $plan) === $proof['target_photo_ids'], 'original_target_changed_before_edit');
        if (is_resource($lease)) {
            maxEditAssertLeaseIdentity($lease, $leasePath);
        }
        maxEditNeed(mkdir($directory, 0700), 'target_receipt_exists_no_replay');
        $file = fopen($directory . '/receipt.jsonl', 'x');
        maxEditNeed(is_resource($file), 'durable_edit_receipt_create_failed');
        maxEditReceipt($file, ['state' => 'first_edit_started', 'checkpoint_sha256' => $sealed,
            'plan_sha256' => $planSha] + $proof);
        $started = true;
        $changed = maxEditBodyOnce($file, $plan, $proof, static function() use ($plan,$imageToken): array {
            $put = MaxTransport::request('https://platform-api2.max.ru', MAX_SEARCH_TOKEN, 'PUT', '/messages',
                ['message_id' => $plan['target_mid']],
                ['text' => htmlspecialchars($plan['caption'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'format' => 'html',
                    'attachments' => [['type' => 'image', 'payload' => ['token' => $imageToken]]]], null);
            return ['success'=>is_array($put) && ($put['success'] ?? false) === true, 'http'=>(int)(MaxTransport::lastError()['http_code'] ?? 0)];
        }, 'maxEditRead');
        unset($imageToken);
        maxEditReceipt($file, ['state' => 'same_message_text_and_hotel_photo_verified']);
        $nowChannel = $registry->channel(MAX_PROJECT, 'max', MAX_BOT, MAIN_MAX);
        maxEditNeed(is_array($nowChannel) && (int)$nowChannel['config_version'] === $proof['target_config'],
            'channel_changed_after_edit');
        $snapshot = $changed['snapshot'];
        $snapshot['metadata']['hotel_catalog_id'] = $plan['hotel'];
        $snapshot['metadata']['logical_id'] = 'commercial-main-hotel-' . $plan['hotel'] . '-v1';
        $snapshot['metadata']['observation_kind'] = 'authorized_admin_content_edit';
        $snapshot['metadata']['source_event_id'] = $lock . ':native-edit';
        $snapshot['metadata']['source_revision_ms'] = (string)(int)floor(microtime(true) * 1000);
        $snapshot['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($snapshot, '', $channel['bot_url']);
        $registered = $registry->registerPublication(MAX_PROJECT, 'max', MAX_BOT, MAIN_MAX, $plan['target_mid'], $snapshot);
        maxEditNeed(is_array($registered) && ($registered['external_message_id'] ?? '') === $plan['target_mid']
            && (int)($registered['snapshot']['metadata']['hotel_catalog_id'] ?? 0) === $plan['hotel'],
            'canonical_hotel_binding_unconfirmed');
        $id = (int)$registered['publication_id'];
        $version = (int)$registered['version_id'];
        maxEditReceipt($file, ['state' => 'existing_message_registered', 'publication_id' => $id, 'version_id' => $version]);
        if (($registered['state'] ?? '') === 'pending') {
            maxEditInstalled($sha);
            maxEditReceipt($file, ['state' => 'existing_message_button_check_started', 'publication_id' => $id, 'version_id' => $version]);
            $transport = new ChannelPostButtonTransport(static function (
                string $platform, string $method, string $path, array $query, ?array $body
            ) use ($plan): array {
                maxEditNeed($platform === 'max' && (($method === 'GET' && $body === null
                    && in_array($path, ['/me', '/chats/' . MAIN_MAX . '/members/me', '/messages/' . $plan['target_mid']], true))
                    || ($method === 'PUT' && $path === '/messages' && $query === ['message_id' => $plan['target_mid']]
                    && array_keys($body ?? []) === ['attachments'])), 'outside_exact_existing_message_transport_scope');
                return ChannelPostApiTransport::buttonRequest($platform, $method, $path, $query, $body);
            });
            (new ChannelPostAutomationService($registry, $transport))->processPublication(MAX_PROJECT, 'max', MAX_BOT, $id, $version);
        }
        $final = $registry->publication($id);
        maxEditNeed(is_array($final) && (int)$final['version_id'] === $version && $final['state'] === 'sent'
            && ($final['snapshot']['post_url'] ?? '') === $proof['target_url']
            && (int)($final['snapshot']['metadata']['hotel_catalog_id'] ?? 0) === $plan['hotel'],
            'final_registry_state_unconfirmed');
        $last = maxEditRead($plan['target_mid'], MAIN_MAX);
        maxEditValidatePost($last, $proof, $plan);
        $button = ChannelPostButtonTransport::buttonUrl($final);
        maxEditNeed(ChannelPostIncomingAdapter::containsOnlyButton($last['snapshot'], $button, $channel['button_text']),
            'single_native_context_button_unconfirmed');
        $resolved = ChannelPostEntryService::resolve('max', $final['start_payload'], $registry);
        maxEditNeed(is_array($resolved) && (int)$resolved['publication_id'] === $id && (int)$resolved['version_id'] === $version
            && (int)($resolved['snapshot']['metadata']['hotel_catalog_id'] ?? 0) === $plan['hotel']
            && ($resolved['snapshot']['post_url'] ?? '') === $proof['target_url'], 'canonical_hotel_context_unconfirmed');
        $receipt = ['state' => 'existing_max_message_updated_and_verified', 'publication_id' => $id, 'version_id' => $version,
            'body_photo_button_verified' => true, 'button_url_sha256'=>hash('sha256',$button),
            'button_label_sha256'=>hash('sha256',$channel['button_text']), 'source_photo_sha256'=>hash('sha256',$proof['source_photo_id']), 'new_posts' => 0, 'ad_writes' => 0] + $proof;
        maxEditReceipt($file, $receipt);
        echo maxEditJson($receipt), "\n";
        return 0;
    } catch (Throwable $error) {
        $reason = preg_match('/\A[a-z_]{1,100}\z/D', $error->getMessage()) === 1 ? $error->getMessage() : 'operation_failed';
        $failure = ['state' => $started ? 'held_no_retry' : 'stopped_before_edit', 'reason' => $reason, 'new_posts' => 0,
            'ad_writes' => 0] + $proof;
        if ($started && is_resource($file)) {
            try {
                maxEditReceipt($file, $failure);
            } catch (Throwable $ignored) {
                $failure['journal_error'] = true;
            }
        }
        echo maxEditJson($failure), "\n";
        return 2;
    } finally {
        if ($readOnlyTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_resource($lease)) {
            fclose($lease);
        }
        if (is_resource($file)) {
            fclose($file);
        }
        if ($held && $pdo instanceof PDO) {
            $query = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $query->execute([$lock]);
        }
    }
}

if (PHP_SAPI !== 'cli' || realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}
if (($argv[1] ?? null) === '--correct-public-render-949') {
    require_once __DIR__ . '/batch_entry.php';
    require_once __DIR__ . '/public_render.php';
    exit(maxRenderMain($argv));
}
if (in_array($argv[1] ?? '', ['--batch-plan','--batch-check','--batch-prepare-check','--batch-apply'], true)) {
    require_once __DIR__ . '/batch_input.php';
    require_once __DIR__ . '/batch_entry.php';
    exit(maxBatchEntry($argv));
}
exit(maxEditRun($argv));
