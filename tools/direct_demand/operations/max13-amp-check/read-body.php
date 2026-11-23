<?php
/** Exact MAX13 read-only adapter. Raw provider payloads never leave memory. */
function max13Canonical($value)
{
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = max13Canonical($item);
    return $value;
}

function max13Hash($value): string
{
    return hash('sha256', jsonValue(max13Canonical($value)));
}

function validateMax13Publication(array $row, array $publication): void
{
    need(($publication['project_key'] ?? null) === 'anytour'
        && ($publication['platform'] ?? null) === 'max'
        && ($publication['bot_key'] ?? null) === 'id9704048781_1_bot'
        && ($publication['channel_id'] ?? null) === '-80492244209683'
        && ($publication['source_key'] ?? null) === 'max_anytour_msk1'
        && (int)($publication['publication_id'] ?? 0) === $row['publication_id']
        && (int)($publication['version_id'] ?? 0) > 0
        && ($publication['external_message_id'] ?? null) === $row['message_id']
        && ($publication['state'] ?? null) === 'sent'
        && ($publication['snapshot']['post_url'] ?? null) === $row['post_url'],
        'current_publication_identity_changed');
    $metadata = $publication['snapshot']['metadata'] ?? [];
    if (isset($metadata['hotel_catalog_id'])) {
        need((is_int($metadata['hotel_catalog_id']) || is_string($metadata['hotel_catalog_id']))
            && ctype_digit((string)$metadata['hotel_catalog_id'])
            && (int)$metadata['hotel_catalog_id'] === $row['hotel_id'], 'current_hotel_binding_changed');
    }
    if (isset($metadata['logical_id'])) {
        need($metadata['logical_id'] === 'commercial-hotel-'.$row['hotel_id'].'-v1', 'current_logical_identity_changed');
    }
    need((int)($publication['channel']['id'] ?? 0) === 9
        && (int)($publication['channel']['config_version'] ?? 0) > 0
        && ($publication['channel']['button_text'] ?? null) === '✈️ Узнать стоимость тура',
        'current_channel_configuration_changed');
}

function max13CurrentFingerprint(array $publication): string
{
    return max13Hash([$publication['publication_id'], $publication['version_id'],
        $publication['external_message_id'], $publication['state'], $publication['snapshot'],
        $publication['channel']['config_version'], $publication['channel']['button_text'],
        ChannelPostButtonTransport::buttonUrl($publication)]);
}

/** Pure classifier; publication and live snapshot are the actual current reads. */
function inspectMax13Snapshots(array $row, array $publication, array $live, string $button, ?array $resolved): array
{
    validateMax13Publication($row, $publication);
    $raw = $live['raw_body'] ?? null;
    need(is_array($raw) && ($raw['body']['mid'] ?? null) === $row['message_id']
        && (string)($raw['recipient']['chat_id'] ?? '') === '-80492244209683'
        && ($raw['recipient']['chat_type'] ?? null) === 'channel'
        && ($live['post_url'] ?? null) === $row['post_url'], 'native_post_identity_changed');
    $snapshot = $publication['snapshot'];
    $caption = $live['text'] ?? null;
    need(is_string($caption) && $caption !== '' && strlen($caption) <= 65536
        && mb_check_encoding($caption, 'UTF-8') && !str_contains($caption, "\0"), 'native_caption_invalid');
    need(is_string($snapshot['text'] ?? null) && is_array($live['attachments'] ?? null)
        && array_is_list($live['attachments']) && is_array($snapshot['attachments'] ?? null)
        && array_is_list($snapshot['attachments']), 'native_snapshot_invalid');
    $holds = [];
    $textMatch = $caption === $snapshot['text'];
    if (!$textMatch) $holds[] = 'registry_caption_mismatch';
    $nativePhoto = null; $registryPhoto = null;
    try { $nativePhoto = photoSignature($live); } catch (Throwable $ignored) { $holds[] = 'native_media_shape_changed'; }
    try { $registryPhoto = photoSignature($snapshot); } catch (Throwable $ignored) { $holds[] = 'registry_media_shape_changed'; }
    $mediaMatch = $nativePhoto !== null && $registryPhoto !== null && $nativePhoto === $registryPhoto;
    if (!$mediaMatch) $holds[] = 'native_registry_media_mismatch';
    $nativeMarkup = $live['markup'] ?? null; $registryMarkup = $snapshot['markup'] ?? null;
    $markupMatch = is_array($nativeMarkup) && array_is_list($nativeMarkup)
        && is_array($registryMarkup) && array_is_list($registryMarkup)
        && max13Canonical($nativeMarkup) === max13Canonical($registryMarkup);
    if (!$markupMatch) $holds[] = 'native_registry_markup_mismatch';
    $label = '✈️ Узнать стоимость тура';
    $nativeButton = ChannelPostIncomingAdapter::containsOnlyButton($live, $button, $label);
    $registryButton = ChannelPostIncomingAdapter::containsOnlyButton($snapshot, $button, $label);
    $resolves = is_array($resolved) && (int)($resolved['publication_id'] ?? 0) === $row['publication_id']
        && (int)($resolved['version_id'] ?? 0) === (int)$publication['version_id']
        && ($resolved['source_key'] ?? null) === 'max_anytour_msk1';
    if (!$nativeButton || !$registryButton || !$resolves) $holds[] = 'current_button_binding_unverified';
    if (($live['metadata']['capability'] ?? null) !== 'supported'
        || ($snapshot['metadata']['capability'] ?? null) !== 'supported') $holds[] = 'snapshot_capability_held';
    $literal = substr_count($caption, '&amp;');
    $nativeHash = hash('sha256', $caption);
    $nativeAttachmentsHash = attachmentHash($live); $registryAttachmentsHash = attachmentHash($snapshot);
    return [
        'hotel_id' => $row['hotel_id'], 'title' => $row['title'], 'platform' => 'max',
        'publication_id' => $row['publication_id'], 'version_id' => (int)$publication['version_id'],
        'message_id' => $row['message_id'], 'post_url' => $row['post_url'], 'source_key' => 'max_anytour_msk1',
        'config_version' => (int)$publication['channel']['config_version'], 'status' => 'sent',
        'native_http_status' => 200, 'provider_live_verified' => true,
        'snapshot_provenance' => 'current_registry_and_live_max_get',
        'caption' => $caption, 'caption_sha256' => $nativeHash,
        'registry_caption_sha256' => hash('sha256', $snapshot['text']),
        'native_registry_caption_match' => $textMatch,
        'literal_amp_entity_count' => $literal,
        'ordinary_ampersand_count' => substr_count($caption, '&') - $literal,
        'media_count' => count($live['attachments']),
        'media_types' => array_map(static fn(array $item): string => (string)($item['type'] ?? ''), $live['attachments']),
        'photo_signature' => $nativePhoto, 'registry_photo_signature' => $registryPhoto,
        'native_registry_media_match' => $mediaMatch,
        'attachments_sha256' => $nativeAttachmentsHash, 'registry_attachments_sha256' => $registryAttachmentsHash,
        'raw_attachment_hash_match' => $nativeAttachmentsHash === $registryAttachmentsHash,
        'markup_sha256' => max13Hash($nativeMarkup), 'registry_markup_sha256' => max13Hash($registryMarkup),
        'native_registry_markup_match' => $markupMatch,
        'button_url_sha256' => hash('sha256', $button), 'button_text' => $label,
        'native_button_verified' => $nativeButton, 'registry_button_verified' => $registryButton,
        'button_resolves_to_current_post' => $resolves,
        'historical_version_id' => $row['historical']['version_id'],
        'historical_caption_sha256' => $row['historical']['text_sha256'],
        'matches_historical_caption' => $nativeHash === $row['historical']['text_sha256'],
        'amp_status' => $literal > 0 ? 'native_literal_amp_entity' : 'native_correct',
        'verdict' => $holds !== [] ? 'hold' : ($literal > 0 ? 'conditional_edit_candidate' : 'native-correct'),
        'hold_reasons' => array_values(array_unique($holds)),
    ];
}

// MAX13_MAIN -- the compiler includes this body only in the exact read stage.
$out = ['schema' => 1, 'kind' => 'MAX13_NATIVE_READ_ONLY', 'operation' => MAX13_READ_OPERATION,
    'ok' => false, 'installed_sha' => EXPECTED_INSTALLED_SHA,
    'manifest_sha256' => APPROVED_MANIFEST_SHA256, 'approval_comment_id' => 7072422617,
    'provider_calls' => 0, 'post_write_attempts' => 0, 'db_writes' => 0, 'xml_writes' => 0,
    'filesystem_writes' => 0, 'message_sends' => 0, 'posts' => []];
$pdo = null; $monitorLock = null;
try {
    need(PHP_SAPI === 'cli', 'cli_only'); installedGuard(); acceptedSurface();
    $stamp = strtotime(MAX13_METADATA_OBSERVED_AT);
    need($stamp !== false && $stamp >= time() - 900 && $stamp <= time() + 60, 'journal_metadata_expired');
    $raw = base64_decode(MANIFEST_BASE64, true);
    need(is_string($raw) && hash('sha256', $raw) === APPROVED_MANIFEST_SHA256, 'approved_manifest_changed');
    $scope = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    need(($scope['schema'] ?? null) === 1 && ($scope['kind'] ?? null) === 'MAX13_AMP_READ_ONLY_SCOPE'
        && ($scope['operation'] ?? null) === MAX13_READ_OPERATION && ($scope['approval_comment_id'] ?? null) === 7072422617
        && ($scope['authorization_scope'] ?? null) === 'one_time_native_read_13_only'
        && array_column($scope['rows'] ?? [], 'hotel_id') === [2335,2603,2602,11751,11775,64918,11791,1703,2721,33531,11847,17608,236],
        'exact_read_scope_changed');
    $monitorPath = '/var/www/anytoour/data/channel-post-operations/monitor-price-after-collection-v1.jsonl';
    need(is_file($monitorPath) && !is_link($monitorPath) && realpath($monitorPath) === $monitorPath, 'monitor_journal_unavailable');
    $monitorLock = fopen($monitorPath, 'r');
    need(is_resource($monitorLock) && flock($monitorLock, LOCK_SH | LOCK_NB), 'monitor_already_running');
    $pin = json_decode(CAPTION_PRICE_PIN, true, 16, JSON_THROW_ON_ERROR);
    $proof = verifiedCaptionPriceJournal($monitorPath, $monitorLock, $pin);
    $out['journal_pin'] = $pin; $out['monitor_journal_sha256'] = $proof['sha256']; $out['monitor_pending'] = false;
    ob_start();
    try {
        require getcwd().'/config.php';
        require_once getcwd().'/services/ChannelPostRuntime.php';
        require_once getcwd().'/services/ChannelPostEntryService.php';
        require_once getcwd().'/services/ChannelPostButtonTransport.php';
        require_once getcwd().'/integrations/ChannelPostApiTransport.php';
    } finally { ob_end_clean(); }
    $pdo = ConversationDb::connection();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->exec('START TRANSACTION READ ONLY');
    $registry = new ChannelPostRegistryService($pdo);
    $identity = ChannelPostRuntime::identity('max');
    $channel = $registry->channel('anytour', 'max', 'id9704048781_1_bot', '-80492244209683');
    need(is_array($identity) && ($identity['project_key'] ?? null) === 'anytour'
        && ($identity['bot_key'] ?? null) === 'id9704048781_1_bot'
        && is_array($channel) && (int)($channel['id'] ?? 0) === 9
        && ($channel['source_key'] ?? null) === 'max_anytour_msk1', 'runtime_channel_identity_changed');
    $ids = array_column($scope['rows'], 'publication_id');
    need(count($ids) === 13 && count(array_unique($ids)) === 13, 'publication_scope_invalid');
    noActiveVersions($pdo, $ids);
    $targets = [];
    foreach ($scope['rows'] as $row) {
        $p = $registry->publication($row['publication_id']);
        need(is_array($p), 'current_publication_missing'); validateMax13Publication($row, $p);
        $targets[$row['publication_id']] = $p;
    }
    foreach ($scope['rows'] as $row) {
        $p = $targets[$row['publication_id']];
        $button = ChannelPostButtonTransport::buttonUrl($p);
        $resolved = ChannelPostEntryService::resolve('max', $p['start_payload'], $registry);
        ++$out['provider_calls'];
        $native = readMax($row['message_id'], '-80492244209683');
        $live = ChannelPostIncomingAdapter::maxSnapshot($native);
        need(is_array($live), 'native_snapshot_unavailable');
        $out['posts'][] = inspectMax13Snapshots($row, $p, $live, $button, $resolved);
        unset($native, $live);
    }
    noActiveVersions($pdo, $ids);
    foreach ($scope['rows'] as $row) {
        $current = $registry->publication($row['publication_id']);
        need(is_array($current), 'current_publication_missing'); validateMax13Publication($row, $current);
        need(max13CurrentFingerprint($current) === max13CurrentFingerprint($targets[$row['publication_id']]),
            'current_publication_changed_during_read');
    }
    $pdo->exec('ROLLBACK'); $pdo = null;
    verifiedCaptionPriceJournal($monitorPath, $monitorLock, $pin);
    installedGuard(); acceptedSurface();
    need($stamp >= time() - 900 && count($out['posts']) === 13 && $out['provider_calls'] === 13, 'native_read_incomplete_or_expired');
    $out['observed_at_utc'] = gmdate('c');
    $out['post_count'] = 13; $out['monitor_journal_unchanged'] = true;
    $out['counts'] = ['native-correct' => 0, 'conditional_edit_candidate' => 0, 'hold' => 0];
    foreach ($out['posts'] as $post) ++$out['counts'][$post['verdict']];
    $out['ok'] = true;
} catch (Throwable $error) {
    $message = $error->getMessage();
    $out['error'] = preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D', $message) === 1 ? $message : 'bounded_native_read_failed';
} finally {
    if ($pdo instanceof PDO) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {} }
    if (is_resource($monitorLock)) { flock($monitorLock, LOCK_UN); fclose($monitorLock); }
}
$out['locks_released_before_return'] = true;
echo jsonValue($out), PHP_EOL;
exit($out['ok'] ? 0 : 2);
