<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/services/ChannelPostRegistryService.php';
require_once dirname(__DIR__) . '/services/ChannelPostAutomationService.php';
require_once dirname(__DIR__) . '/integrations/ChannelPostIncomingAdapter.php';
require_once __DIR__ . '/fixtures/channel_post_registry_fixture.php';
require_once dirname(__DIR__) . '/tools/anytour_existing_post_batch.php';

$passed = 0;
function postCheck(string $name, bool $condition): void
{
    global $passed;
    if (!$condition) throw new RuntimeException('FAIL ' . $name);
    $passed++;
    echo 'PASS ' . $name . "\n";
}
function postThrows(callable $operation, string $reason): bool
{
    try { $operation(); } catch (InvalidArgumentException $error) { return $error->getMessage() === $reason; }
    return false;
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
createChannelPostRegistryFixture($pdo);
$pdo->exec("INSERT INTO projects VALUES (1,'anytour','AnyTour',1),(2,'anex','ANEX',1);
INSERT INTO conversation_sources VALUES (11,1,'max_anytour_msk1','Москва','max',1,'manager'),(12,1,'tg_anytour_msk','ТГ Москва','telegram',1,'manager'),(13,1,'max_other','Другой MAX','max',1,'manager'),(14,1,'max_ai','ИИ','max',1,'ai'),(21,2,'max_anytour_msk1','Чужой источник','max',1,'manager');
INSERT INTO conversations (id,project_key,channel,status,manager_id,source_id,external_chat_id) VALUES (100,'anytour','max','manager',77,11,'501'),(101,'anytour','telegram','waiting_manager',NULL,12,'502'),(200,'anex','max','ai',NULL,21,'503');");
$registry = new ChannelPostRegistryService($pdo);
$config = ['project_key' => 'anytour', 'platform' => 'max', 'bot_key' => 'id9704048781_1_bot', 'channel_id' => '80095447388179', 'source_key' => 'max_anytour_msk1', 'display_name' => 'Тест AnyTour', 'bot_url' => 'https://max.ru/id9704048781_1_bot'];
$disabled = $registry->configureChannel($config);
postCheck('channel registration defaults disabled and normalizes MAX negative ID', (int)$disabled['is_enabled'] === 0 && $disabled['channel_id'] === '-80095447388179' && (int)$disabled['config_version'] === 1);
$snapshot = ['text' => "  Турция\n7 ночей — 150 000 ₽ за всех  ", 'title' => 'Турция', 'format' => 'html', 'attachments' => [['type' => 'image', 'provider' => 'max', 'payload' => ['token' => 'PRIVATE-photo-token', 'url' => 'https://private.test/image.jpg']]], 'post_url' => 'https://max.ru/c/-80095447388179/AaE53iyDMmw', 'published_at' => '2026-10-14 10:00:00', 'metadata' => ['source_event_id' => 'post-created-0001'], 'raw_body' => ['private' => 'not-public']];
postCheck('disabled channels neither register publications nor expose tokens', $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '-80095447388179', 'AaE53iyDMmw', $snapshot) === null);
$enabled = $registry->configureChannel($config + ['is_enabled' => true, 'expected_version' => 1]);
postCheck('explicit channel activation increments persisted configuration version', (int)$enabled['is_enabled'] === 1 && (int)$enabled['config_version'] === 2);
postCheck('stale administrator cannot overwrite a newer configuration', postThrows(fn() => $registry->configureChannel($config + ['is_enabled' => false, 'expected_version' => 1]), 'channel_configuration_conflict'));
postCheck('bot URL must match the exact configured public identity', postThrows(fn() => $registry->configureChannel(array_replace($config, ['bot_url' => 'https://max.ru/id9704048781_2_bot'])), 'bot_url_identity_mismatch'));
postCheck('a neighboring project cannot claim the same platform channel', postThrows(fn() => $registry->configureChannel(array_replace($config, ['project_key' => 'anex', 'expected_version' => 2])), 'channel_identity_conflict'));
postCheck('channel source must be an active source of this exact platform', postThrows(fn() => $registry->configureChannel(array_replace($config, ['channel_id' => '-999', 'source_key' => 'tg_anytour_msk'])), 'active_platform_source_required'));
postCheck('AI-only source cannot create a manager CTA without changing source policy', postThrows(fn() => $registry->configureChannel(array_replace($config, ['channel_id' => '-999', 'source_key' => 'max_ai'])), 'manager_source_required') && $pdo->query('SELECT handling_mode FROM conversation_sources WHERE id=14')->fetchColumn() === 'ai');

$first = $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '-80095447388179', 'AaE53iyDMmw', $snapshot);
postCheck('publication token is opaque, non-numeric, URL-safe and shorter than 64 characters', $first !== null && preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/', $first['start_payload']) === 1 && strlen($first['start_payload']) < 64 && !str_contains($first['start_payload'], 'msk'));
postCheck('exact original whitespace, photograph data and canonical source are stored privately', $first['snapshot']['text'] === $snapshot['text'] && $first['snapshot']['attachments'] === $snapshot['attachments'] && $first['source_key'] === 'max_anytour_msk1');
$duplicate = $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '80095447388179', 'AaE53iyDMmw', $snapshot);
postCheck('duplicate publication delivery preserves identity, version and token', $duplicate['publication_id'] === $first['publication_id'] && $duplicate['version_id'] === $first['version_id'] && $duplicate['start_payload'] === $first['start_payload'] && (int)$pdo->query('SELECT COUNT(*) FROM post_versions')->fetchColumn() === 1);
postCheck('token resolution enforces exact project, bot and platform isolation', $registry->resolveToken('anytour', 'max', 'id9704048781_1_bot', $first['start_payload'])['source_key'] === 'max_anytour_msk1' && $registry->resolveToken('anex', 'max', 'id9704048781_1_bot', $first['start_payload']) === null && $registry->resolveToken('anytour', 'max', 'id9704048781_2_bot', $first['start_payload']) === null && $registry->resolveToken('anytour', 'telegram', 'id9704048781_1_bot', $first['start_payload']) === null);
postCheck('malformed, unknown and numeric identifiers cannot resolve as source suffixes', $registry->resolveToken('anytour', 'max', 'id9704048781_1_bot', 'p_1') === null && $registry->resolveToken('anytour', 'max', 'id9704048781_1_bot', 'p_' . str_repeat('A', 24)) === null && $registry->resolveToken('anytour', 'max', 'id9704048781_1_bot', 'max_anytour_msk1') === null);
$beforeState = $pdo->query('SELECT status,manager_id,source_id FROM conversations WHERE id=100')->fetch();
$entry = $registry->recordEntry(100, $first, 'max-start-event-0001');
$replayedEntry = $registry->recordEntry(100, $first, 'max-start-event-0001');
postCheck('entry recording is idempotent and emits one canonical pointer event', $entry['entry_id'] === $replayedEntry['entry_id'] && $replayedEntry['duplicate'] && (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='channel_post_entry'")->fetchColumn() === 1);
postCheck('recording context never mutates dialogue ownership, routing source or manager', $pdo->query('SELECT status,manager_id,source_id FROM conversations WHERE id=100')->fetch() === $beforeState);
postCheck('cross-project and wrong-transport conversations cannot attach another publication', $registry->recordEntry(200, $first, 'wrong-project') === null && $registry->recordEntry(101, $first, 'wrong-platform') === null);
$forged = $first; $forged['version_id'] = 123456;
postCheck('a nonexistent snapshot version is rejected before an entry or event is written', $registry->recordEntry(100, $forged, 'forged-version') === null);
$selfEdit = $snapshot; $selfEdit['metadata']['source_event_id'] = 'same-body-own-button-edit'; $selfEdit['raw_body'] = ['inline_keyboard' => ['PRIVATE-bot-button-payload']];
$selfObserved = $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '-80095447388179', 'AaE53iyDMmw', $selfEdit);
postCheck('same-content channel edits receive a durable event receipt without a new version', $selfObserved['version_id'] === $first['version_id'] && (int)$pdo->query('SELECT COUNT(*) FROM post_observations')->fetchColumn() === 2);

$changed = $snapshot; $changed['text'] = 'Турция — новая цена 140 000 ₽'; $changed['edited_at'] = '2026-10-14 10:01:00'; $changed['metadata']['source_event_id'] = 'post-edited-0002';
$second = $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '-80095447388179', 'AaE53iyDMmw', $changed);
postCheck('editing a publication appends an immutable version and keeps the same token', $second['version_number'] === 2 && $second['version_id'] !== $first['version_id'] && $second['start_payload'] === $first['start_payload']);
$observedOriginal = $registry->versionForPublication($first['publication_id'], $first['version_id']);
postCheck('archival getter reads exact earlier observed image after the current version changes', $observedOriginal['snapshot']['text'] === $snapshot['text'] && $observedOriginal['snapshot']['attachments'] === $snapshot['attachments'] && $observedOriginal['version_id'] === $first['version_id'] && $observedOriginal['project_key'] === 'anytour' && $observedOriginal['platform'] === 'max' && $observedOriginal['bot_key'] === 'id9704048781_1_bot' && $observedOriginal['channel_id'] === '-80095447388179' && $observedOriginal['external_message_id'] === 'AaE53iyDMmw');
postCheck('archival getter rejects missing publication/version pairs without latest fallback', $registry->versionForPublication(0, $first['version_id']) === null && $registry->versionForPublication($first['publication_id'], 999999) === null);
$context = $registry->managerContext(100, 'anytour');
postCheck('manager context remains the original price at entry even after an edit', $context['current']['text'] === $snapshot['text'] && $context['current']['version_id'] === $first['version_id']);
$again = $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '-80095447388179', 'AaE53iyDMmw', $snapshot);
postCheck('replaying an older create event cannot restore an obsolete version', $again['version_id'] === $second['version_id'] && !empty($again['duplicate_event']));
$oldSelfEdit = $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '-80095447388179', 'AaE53iyDMmw', $selfEdit);
postCheck('replaying an old same-content bot edit cannot restore a post after later SMM edits', $oldSelfEdit['version_id'] === $second['version_id'] && !empty($oldSelfEdit['duplicate_event']));
$stale = $snapshot; $stale['metadata']['source_event_id'] = 'late-arriving-old-event';
$late = $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '-80095447388179', 'AaE53iyDMmw', $stale);
postCheck('out-of-order provider timestamps cannot overwrite newer published content', $late['version_id'] === $second['version_id'] && !empty($late['stale_observation']));
$entry2 = $registry->recordEntry(100, $second, 'max-start-event-0002');
$history = $registry->managerContext(100, 'anytour', 2);
postCheck('another authenticated entry pins the latest snapshot while retaining previous history', $history['current']['version_id'] === $second['version_id'] && $history['history'][0]['version_id'] === $first['version_id']);
postCheck('manager projection excludes raw media URLs, access tokens and native payload', !str_contains(json_encode($history), 'PRIVATE-photo-token') && !str_contains(json_encode($history), 'private.test') && !str_contains(json_encode($history), 'not-public') && $history['current']['media_url'] === '/manager/post-context-media.php?conversation_id=100&entry_id=' . $entry2['entry_id'] . '&attachment=0');
postCheck('private photo owner receives only the immutable entry version and checks project again', $registry->versionForEntry(100, 'anytour', $entry['entry_id'])['snapshot']['text'] === $snapshot['text'] && $registry->versionForEntry(100, 'anex', $entry['entry_id']) === null && $registry->versionForEntry(200, 'anex', $entry['entry_id']) === null);
postCheck('history API repeats project isolation and clamps its result bound', $registry->managerContext(100, 'anex')['current'] === null && count($registry->managerContext(100, 'anytour', 1)['history']) === 0);
$unavailable = $registry->recordUnavailableEntry(100, 'anytour', 'max', 'missing-token-event');
$unavailableAgain = $registry->recordUnavailableEntry(100, 'anytour', 'max', 'missing-token-event');
$unavailableContext = $registry->managerContext(100, 'anytour');
postCheck('an unknown post clears current context without deleting previous snapshots', $unavailableContext['current']['unavailable'] && $unavailableContext['current']['text'] === '' && $unavailableContext['history'][0]['version_id'] === $second['version_id'] && $unavailable['entry_id'] === $unavailableAgain['entry_id']);
postCheck('unavailable entries cannot be inserted into another project or transport', $registry->recordUnavailableEntry(200, 'anytour', 'max', 'bad-unavailable') === null && $registry->recordUnavailableEntry(100, 'anytour', 'telegram', 'bad-platform') === null);

postCheck('published channel canonical source cannot be reassigned under existing tokens', postThrows(fn() => $registry->configureChannel(array_replace($config, ['source_key' => 'max_other', 'is_enabled' => true, 'expected_version' => 2])), 'published_channel_source_immutable'));
$pending = $registry->pendingPublications('anytour', 'max', 'id9704048781_1_bot');
postCheck('durable queue exposes only the current active snapshot and no neighboring scope', count($pending) === 1 && $pending[0]['version_id'] === $second['version_id'] && $registry->pendingPublications('anex', 'max', 'id9704048781_1_bot') === []);
$claim = $registry->claimPublication($second['publication_id'], $second['version_id']);
postCheck('operation claim is exclusive and contains its own opaque lease token', $claim !== null && strlen($claim['claim_token']) === 32 && $registry->claimPublication($second['publication_id'], $second['version_id']) === null);
postCheck('wrong lease cannot begin an external API write', !$registry->markExternalWriteStarted($second['publication_id'], $second['version_id'], 'wrong-claim'));
postCheck('external write intent is durable and cannot be repeated', $registry->markExternalWriteStarted($second['publication_id'], $second['version_id'], $claim['claim_token']) && !$registry->markExternalWriteStarted($second['publication_id'], $second['version_id'], $claim['claim_token']));
postCheck('attempted external writes cannot be returned to the writable pending queue', !$registry->finishPublication($second['publication_id'], $second['version_id'], $claim['claim_token'], 'pending'));
$pdo->exec("UPDATE post_versions SET claimed_at='2000-01-01 00:00:00' WHERE id=" . $second['version_id']);
$unknown = $registry->pendingPublications('anytour', 'max', 'id9704048781_1_bot')[0];
postCheck('expired write lease becomes UNKNOWN with its write-attempt flag intact', $unknown['state'] === 'unknown' && $unknown['write_attempted']);
postCheck('UNKNOWN operations cannot receive another write claim', $registry->claimPublication($second['publication_id'], $second['version_id']) === null);
$reconcile = $registry->claimPublication($second['publication_id'], $second['version_id'], true);
postCheck('UNKNOWN can be claimed for reconciliation but can never start a second PUT', $reconcile['reconcile_only'] && $reconcile['write_attempted'] && !$registry->markExternalWriteStarted($second['publication_id'], $second['version_id'], $reconcile['claim_token']));
postCheck('safe verified receipt commits without raw API response leakage', $registry->finishPublication($second['publication_id'], $second['version_id'], $reconcile['claim_token'], 'sent', '', ['verified' => true, 'raw_body' => 'PRIVATE-response', 'access_token' => 'PRIVATE-key', 'request_count' => 3, 'http_results' => [['operation' => 'verify_message', 'http' => 200, 'transport_error' => 0, 'body' => 'PRIVATE-body']]]) && !str_contains((string)$pdo->query('SELECT receipt_json FROM post_versions WHERE id=' . $second['version_id'])->fetchColumn(), 'PRIVATE') && $registry->publication($second['publication_id'])['receipt']['request_count'] === 3 && $registry->publication($second['publication_id'])['receipt']['http_results'] === [['operation' => 'verify_message', 'http' => 200, 'transport_error' => 0]]);
postCheck('a completed operation cannot be claimed or re-completed by its old lease', $registry->claimPublication($second['publication_id'], $second['version_id']) === null && !$registry->finishPublication($second['publication_id'], $second['version_id'], $reconcile['claim_token'], 'sent'));

$laterSnapshot = $changed; $laterSnapshot['text'] = 'Другое предложение'; $laterSnapshot['metadata']['source_event_id'] = 'new-publication';
$later = $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '-80095447388179', 'OtherPost', $laterSnapshot);
postCheck('each separate publication receives its own unique token', $later['start_payload'] !== $first['start_payload']);
$prewrite = $registry->claimPublication($later['publication_id'], $later['version_id']);
$pdo->exec("UPDATE post_versions SET claimed_at='2000-01-01 00:00:00' WHERE id=" . $later['version_id']);
postCheck('a lease that crashed before external write can safely become pending again', $registry->pendingPublications('anytour', 'max', 'id9704048781_1_bot')[0]['state'] === 'pending');
postCheck('verified authenticated own-button observation can complete an unclaimed version', $registry->confirmObservedButton($later['publication_id'], $later['version_id'], ['verified' => true]) && $registry->publication($later['publication_id'])['state'] === 'sent');
postCheck('archival getter rejects a version from another publication', $registry->versionForPublication($first['publication_id'], $later['version_id']) === null);
$pdo->exec("INSERT INTO conversations (id,project_key,channel,status,source_id,external_chat_id) VALUES (102,'anytour','max','waiting_manager',11,'fallback-only'),(103,'anytour','max','waiting_manager',11,'unavailable-fallback-only')");
$fallbackA1 = $registry->recordEntry(102, $second);
$fallbackB = $registry->recordEntry(102, $later);
$fallbackA2 = $registry->recordEntry(102, $second);
$fallbackContext = $registry->managerContext(102, 'anytour');
postCheck('missing provider key preserves A-to-B-to-A current context with distinct entry IDs', $fallbackA1['entry_id'] < $fallbackB['entry_id'] && $fallbackB['entry_id'] < $fallbackA2['entry_id'] && !$fallbackA2['duplicate'] && $fallbackContext['current']['entry_id'] === $fallbackA2['entry_id'] && $fallbackContext['current']['publication_id'] === $second['publication_id'] && $fallbackContext['history'][0]['publication_id'] === $later['publication_id']);
$fallbackUnavailable1 = $registry->recordUnavailableEntry(103, 'anytour', 'max');
$fallbackValid = $registry->recordEntry(103, $second);
$fallbackUnavailable2 = $registry->recordUnavailableEntry(103, 'anytour', 'max');
$fallbackUnavailableContext = $registry->managerContext(103, 'anytour');
postCheck('missing provider key preserves unavailable-to-valid-to-unavailable entry order', $fallbackUnavailable1['entry_id'] < $fallbackValid['entry_id'] && $fallbackValid['entry_id'] < $fallbackUnavailable2['entry_id'] && !$fallbackUnavailable2['duplicate'] && $fallbackUnavailableContext['current']['entry_id'] === $fallbackUnavailable2['entry_id'] && $fallbackUnavailableContext['current']['unavailable'] && $fallbackUnavailableContext['history'][0]['publication_id'] === $second['publication_id']);

$tgConfig = ['project_key' => 'anytour', 'platform' => 'telegram', 'bot_key' => 'Any_tour_bot', 'channel_id' => '-10012345', 'source_key' => 'tg_anytour_msk', 'display_name' => 'ТГ тест', 'bot_url' => 'https://t.me/Any_tour_bot', 'is_enabled' => true];
$registry->configureChannel($tgConfig);
$tgSnapshot = ['text' => 'Telegram: фото тура', 'attachments' => [['type' => 'image', 'provider' => 'telegram', 'file_id' => 'TG-private-file-id']], 'post_url' => 'https://t.me/c/10012345/5', 'content_hash' => hash('sha256', 'stable-provider-photo-unique-id')];
$tg = $registry->registerPublication('anytour', 'telegram', 'Any_tour_bot', '-10012345', '5', $tgSnapshot);
$tgEdit = $tgSnapshot; $tgEdit['attachments'][0]['file_id'] = 'TG-rotated-file-id'; $tgEdit['raw_body'] = ['new_private_provider_token' => 'secret'];
$tgSame = $registry->registerPublication('anytour', 'telegram', 'Any_tour_bot', '-10012345', '5', $tgEdit);
postCheck('stable provider fingerprint ignores expiring photo credentials and own edit metadata', $tgSame['version_id'] === $tg['version_id']);
postCheck('Telegram shares token format but never the MAX bot/source identity', preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/', $tg['start_payload']) === 1 && $tg['source_key'] === 'tg_anytour_msk' && $registry->resolveToken('anytour', 'max', 'id9704048781_1_bot', $tg['start_payload']) === null && $registry->recordEntry(101, $tg, 'tg-start-1') !== null);
$observeTg = static function ($sequence, int $editedAt, string $text, int $messageId) use ($registry): array {
    $update = ['update_id' => $sequence, 'edited_channel_post' => ['message_id' => $messageId, 'date' => 1791972290, 'edit_date' => $editedAt, 'chat' => ['id' => -10012345, 'type' => 'channel'], 'text' => $text]];
    $event = ChannelPostIncomingAdapter::fromUpdate('telegram', $update);
    $event['snapshot']['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($event['snapshot']);
    return $registry->registerPublication('anytour', 'telegram', 'Any_tour_bot', $event['channel_id'], $event['external_message_id'], $event['snapshot']);
};
$sameSecondLatest = $observeTg(1004, 1791972294, 'Latest Telegram text', 81);
$sameSecondOld = $observeTg(1003, 1791972294, 'Older Telegram text', 81);
postCheck('actual Telegram adapter sequence rejects an unseen older edit in the same second', !empty($sameSecondOld['stale_observation']) && $sameSecondOld['version_id'] === $sameSecondLatest['version_id'] && $sameSecondOld['snapshot']['text'] === 'Latest Telegram text');
$sameBodyLatest = $observeTg(1006, 1791972294, 'Latest Telegram text', 81);
$sameBodyOld = $observeTg(1005, 1791972294, 'Intermediate Telegram text', 81);
postCheck('same-body Telegram observation advances ordering without changing the immutable snapshot', $sameBodyLatest['version_id'] === $sameSecondLatest['version_id'] && $sameBodyLatest['snapshot']['metadata']['source_sequence'] === '1004' && !empty($sameBodyOld['stale_observation']) && $sameBodyOld['snapshot']['text'] === 'Latest Telegram text');
$laterDate = $observeTg(1, 1791972295, 'Latest Telegram text', 81);
$olderDate = $observeTg(9000, 1791972294, 'Older Telegram date', 81);
postCheck('a later Telegram date advances even when update IDs reset and text is unchanged', $laterDate['version_id'] === $sameSecondLatest['version_id'] && !empty($olderDate['stale_observation']) && $olderDate['snapshot']['text'] === 'Latest Telegram text');
$largeSequence = $observeTg('10000000000000000000', 1791972294, 'Large latest sequence', 82);
$smallerSequence = $observeTg('9999999999999999999', 1791972294, 'Smaller older sequence', 82);
postCheck('Telegram sequence comparison uses exact decimal strings beyond native integer precision', !empty($smallerSequence['stale_observation']) && $smallerSequence['version_id'] === $largeSequence['version_id']);
$invalidSequence = ChannelPostIncomingAdapter::fromUpdate('telegram', ['update_id' => '01004', 'edited_channel_post' => ['message_id' => 83, 'date' => 1791972290, 'edit_date' => 1791972294, 'chat' => ['id' => -10012345, 'type' => 'channel'], 'text' => 'Invalid sequence fixture']]);
postCheck('noncanonical Telegram sequence never becomes an authenticated ordering key', !isset($invalidSequence['snapshot']['metadata']['source_sequence']) && !isset($invalidSequence['snapshot']['metadata']['source_sequence_scope']));
$maxClockSnapshot = ['text' => 'Latest MAX text', 'metadata' => ['source_event_id' => 'max-clock-4', 'source_revision_ms' => '1791973000004', 'source_sequence' => '1004', 'source_sequence_scope' => 'telegram_update_id']];
$maxClockFirst = $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '-80095447388179', 'ClockPost', $maxClockSnapshot);
$maxClockSnapshot['metadata']['source_event_id'] = 'max-clock-6'; $maxClockSnapshot['metadata']['source_revision_ms'] = '1791973000006';
$maxClockSame = $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '-80095447388179', 'ClockPost', $maxClockSnapshot);
$maxClockSnapshot['text'] = 'Older MAX text'; $maxClockSnapshot['metadata']['source_event_id'] = 'max-clock-5'; $maxClockSnapshot['metadata']['source_revision_ms'] = '1791973000005';
$maxClockOld = $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '-80095447388179', 'ClockPost', $maxClockSnapshot);
postCheck('same-body MAX observation advances the date clock while ignoring Telegram sequences', $maxClockSame['version_id'] === $maxClockFirst['version_id'] && !empty($maxClockOld['stale_observation']) && $maxClockOld['snapshot']['text'] === 'Latest MAX text' && $pdo->query('SELECT telegram_sequence FROM post_publications WHERE id=' . $maxClockFirst['publication_id'])->fetchColumn() === null);

$pdo->exec('UPDATE conversation_sources SET is_active=0 WHERE id=11');
postCheck('retired sources disable token resolution, registrations and worker operations', $registry->resolveToken('anytour', 'max', 'id9704048781_1_bot', $first['start_payload']) === null && $registry->publication($first['publication_id']) === null && $registry->pendingPublications('anytour', 'max', 'id9704048781_1_bot') === [] && $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '-80095447388179', 'Denied', $snapshot) === null);
postCheck('retired sources retain historical manager evidence and entry photos', $registry->managerContext(100, 'anytour')['history'][0]['text'] === $changed['text'] && $registry->versionForEntry(100, 'anytour', $entry['entry_id'])['version_id'] === $first['version_id']);
$pdo->exec('UPDATE conversation_sources SET is_active=1 WHERE id=11');
$pdo->exec("UPDATE conversation_sources SET handling_mode='ai' WHERE id=11");
postCheck('later AI-only source policy disables token entry and external post work without mutation', $registry->resolveToken('anytour', 'max', 'id9704048781_1_bot', $first['start_payload']) === null && $registry->pendingPublications('anytour', 'max', 'id9704048781_1_bot') === [] && $pdo->query('SELECT handling_mode FROM conversation_sources WHERE id=11')->fetchColumn() === 'ai');
$pdo->exec("UPDATE conversation_sources SET handling_mode='manager' WHERE id=11");
$pdo->exec('UPDATE projects SET is_active=0 WHERE id=1');
postCheck('inactive projects fail closed for public tokens and new work', $registry->resolveToken('anytour', 'max', 'id9704048781_1_bot', $first['start_payload']) === null && $registry->pendingPublications('anytour', 'max', 'id9704048781_1_bot') === []);
$pdo->exec('UPDATE projects SET is_active=1 WHERE id=1');

$tooBig = $snapshot; $tooBig['text'] = str_repeat('я', 12001);
postCheck('oversized post content is rejected rather than silently changing original text', postThrows(fn() => $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '-80095447388179', 'TooBig', $tooBig), 'post_text_too_large_or_invalid'));
$tooDeep = $snapshot; $nested = ['token' => 'x']; for ($i = 0; $i < 12; $i++) $nested = [$nested]; $tooDeep['raw_body'] = $nested;
postCheck('private snapshot recursion is bounded before any storage write', postThrows(fn() => $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '-80095447388179', 'TooDeep', $tooDeep), 'snapshot_depth_exceeded'));
$badUrlSnapshot = ['text' => '<script>test</script>', 'post_url' => 'javascript:alert(1)'];
$badUrl = $registry->registerPublication('anytour', 'max', 'id9704048781_1_bot', '-80095447388179', 'BadUrl', $badUrlSnapshot);
$registry->recordEntry(100, $badUrl, 'bad-link-event');
postCheck('unsafe original URLs never leave the manager projection', $registry->managerContext(100, 'anytour')['current']['post_url'] === '');
$entryCount = (int)$pdo->query('SELECT COUNT(*) FROM post_entries')->fetchColumn();
$pdo->exec("CREATE TRIGGER reject_post_event BEFORE INSERT ON conversation_events BEGIN SELECT RAISE(FAIL,'synthetic event failure'); END");
$failed = false; try { $registry->recordEntry(100, $first, 'atomic-failure'); } catch (PDOException $error) { $failed = true; }
postCheck('event persistence failure atomically rolls back the pinned entry', $failed && (int)$pdo->query('SELECT COUNT(*) FROM post_entries')->fetchColumn() === $entryCount);
$pdo->exec('DROP TRIGGER reject_post_event');
postCheck('retry after rollback records one entry and one pointer event', $registry->recordEntry(100, $first, 'atomic-failure') !== null && (int)$pdo->query('SELECT COUNT(*) FROM post_entries')->fetchColumn() === $entryCount + 1);
postCheck('administration list contains bounded safe configuration and state counts', count($registry->listChannels('anytour')) === 2 && !str_contains(json_encode($registry->listChannels('anytour')), 'PRIVATE') && $registry->listChannels('anex') === []);

$publicChannel = $registry->channel('anytour', 'max', 'id9704048781_1_bot', '-80095447388179');
$publicMessage = ['recipient'=>['chat_type'=>'channel','chat_id'=>-80095447388179], 'timestamp'=>1791972290000, 'url'=>'https://max.ru/fixture_channel/PublicPost', 'body'=>['mid'=>'public-provider-fixture','text'=>'Public channel fixture','attachments'=>[]]];
$publicPublication = $registry->registerProviderObservation('anytour', 'id9704048781_1_bot', $publicChannel, $publicMessage, 1);
postCheck('provider public permalink survives canonical registry snapshot', $publicPublication !== null && $publicPublication['snapshot']['post_url'] === $publicMessage['url']);
$publicEntry = $registry->recordEntry(100, $publicPublication, 'public-provider-entry');
postCheck('public permalink reaches immutable manager context through existing entry', $publicEntry !== null && $registry->managerContext(100, 'anytour')['current']['post_url'] === $publicMessage['url']);
$publicWrongRecipient = $publicMessage; $publicWrongRecipient['recipient']['chat_id'] = -999;
postCheck('public permalink does not bypass exact provider recipient admission', $registry->registerProviderObservation('anytour', 'id9704048781_1_bot', $publicChannel, $publicWrongRecipient, 1) === null);
postCheck('public permalink token retains project and bot isolation', $registry->resolveToken('anex', 'max', 'id9704048781_1_bot', $publicPublication['start_payload']) === null && $registry->resolveToken('anytour', 'max', 'id9704048781_2_bot', $publicPublication['start_payload']) === null);

$sql = (string)file_get_contents(dirname(__DIR__) . '/migrations/033_channel_post_context.sql');
postCheck('production schema preserves binary token equality and persistent identity uniqueness', str_contains($sql, 'uq_post_publications_identity') && str_contains($sql, 'uq_post_publications_token') && str_contains($sql, 'uq_post_entries_event') && substr_count($sql, 'COLLATE ascii_bin') >= 10 && !preg_match('/^\s*(?:INSERT|UPDATE|DELETE)\b/m', preg_replace('/^--.*$/m', '', $sql)));

require_once dirname(__DIR__) . '/services/ProjectConfig.php';
require_once dirname(__DIR__) . '/services/RepairOfferDraft.php';
require_once dirname(__DIR__) . '/services/ChannelPostButtonTransport.php';
$repairValues = require dirname(__DIR__) . '/resources/project-examples/appleservice39.php';
$repair = ProjectConfig::forProject('appleservice39', $repairValues);
$offer = $repair->get('offer');
$draft = RepairOfferDraft::prepare($repair, $offer);
$pdo->exec("INSERT INTO projects VALUES (3,'appleservice39','AppleService39 fixture',1);
    INSERT INTO conversation_sources VALUES (31,3,'repair_max_fixture','Repair MAX','max',1,'manager'),(32,3,'repair_tg_fixture','Repair Telegram','telegram',1,'manager');
    INSERT INTO conversations (id,project_key,channel,status,manager_id,source_id) VALUES
    (301,'appleservice39','max','manager',78,31),(302,'appleservice39','telegram','manager',78,32);");
$pilotBindings = [
    'max' => ['-9100101', 'mid.repair-fixture', 'https://max.ru/', 'repair_max_fixture', 301, 100],
    'telegram' => ['-1009100102', '41', 'https://t.me/', 'repair_tg_fixture', 302, 101],
];
foreach ($pilotBindings as $platform => [$channelId, $mid, $origin, $source, $conversation, $tourConversation]) {
    $project = $draft['project_key'];
    $bot = 'Repair_fixture_bot';
    $botUrl = $origin . $bot;
    $registry->configureChannel(['project_key'=>$project,'platform'=>$platform,'channel_id'=>$channelId,
        'bot_key'=>$bot,'bot_url'=>$botUrl,'source_key'=>$source,'is_enabled'=>true,'button_text'=>$draft['publication']['button_text']]);
    $register = static fn(array $item) => $registry->registerPublication($project, $platform, $bot, $channelId, $mid, [
        'text' => $item['publication']['caption'], 'title' => $item['publication']['title'], 'format' => 'native',
    ]);
    $published = $register($draft);
    $token = $published['start_payload'];
    $resolved = $registry->resolveToken($project, $platform, $bot, $token);
    postCheck($platform . ' repair draft uses the configured button label and existing opaque link',
        $resolved['channel']['button_text'] === 'Уточнить ремонт' && ChannelPostButtonTransport::buttonUrl($resolved) === $botUrl . '?start=' . $token);
    $entry = $registry->recordEntry($conversation, $resolved, 'repair-fixture-entry');
    $retry = $registry->recordEntry($conversation, $resolved, 'repair-fixture-entry');
    postCheck($platform . ' repair entry is idempotent and isolated from AnyTour', $entry && $retry['duplicate']
        && $retry['entry_id'] === $entry['entry_id'] && $registry->resolveToken('anytour', $platform, $bot, $token) === null
        && $registry->recordEntry($tourConversation, $resolved, 'wrong-project') === null
        && $registry->versionForEntry($conversation, 'anytour', $entry['entry_id']) === null);

    $updatedOffer = $offer;
    $updatedOffer['price_review_required'] = true;
    $update = $register(RepairOfferDraft::prepare($repair, $updatedOffer));
    $manager = $registry->managerContext($conversation, $project)['current'];
    postCheck($platform . ' editing the same repair message keeps one publication and preserves manager history',
        $update['publication_id'] === $published['publication_id'] && $update['start_payload'] === $token
        && $update['version_id'] !== $published['version_id'] && $manager['version_id'] === $published['version_id']
        && $manager['text'] === $draft['publication']['caption'] && $manager['source_key'] === $source);
}
require_once __DIR__.'/fixtures/channel_post_zero_write_recovery.php';
runChannelPostZeroWriteRecoveryChecks($pdo, 'postCheck');

/** Isolated public preparation; no Telegram dispatch, click, or production DB. */
function manualPostFixture(): array
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    createChannelPostRegistryFixture($pdo);
    $pdo->exec("INSERT INTO projects VALUES (1,'anytour','AnyTour fixture',1);
        INSERT INTO conversation_sources VALUES (9,1,'tg_anytour_msk','Channel fixture','telegram',1,'manager');");
    $registry = new ChannelPostRegistryService($pdo);
    $channel = $registry->configureChannel(['project_key'=>'anytour','platform'=>'telegram','bot_key'=>'anyru_bot',
        'channel_id'=>'-1001845835645','source_key'=>'tg_anytour_msk','display_name'=>'Aloft fixture',
        'bot_url'=>'https://t.me/anyru_bot','is_enabled'=>true]);
    $snapshot = ['text'=>"Aloft Palm Jumeirah\nТочная тестовая подпись остаётся прежней.", 'title'=>'Aloft Palm Jumeirah',
        'format'=>'public_html','post_url'=>'https://t.me/anytour/1678','published_at'=>'2026-08-11 11:51:56','edited_at'=>'',
        'attachments'=>[['type'=>'video','provider'=>'telegram','duration'=>28,'public_media_path'=>'/file/test1678.mp4']],
        'markup'=>[], 'keyboard'=>[[['text'=>'First','url'=>'https://example.test/one']], [['text'=>'Second','url'=>'https://example.test/two']], [['text'=>'Third','url'=>'https://example.test/three']]],
        'raw_body'=>[], 'metadata'=>['capability'=>'requires_attention','capability_reason'=>'manual_approval_required',
            'observation_kind'=>'public_read','actual_message_id'=>'1678','channel_id'=>'-1001845835645',
            'hotel_catalog_id'=>61159,'checked_at'=>gmdate('c')]];
    return [$pdo, $registry, $channel, $snapshot, ['username'=>'ANYTOURBOT_bot','bot_id'=>'777000111','credential_version'=>1]];
}
function manualPostThrows(callable $operation, string $reason = ''): bool
{
    try { $operation(); } catch (Throwable $error) { return $reason === '' || $error->getMessage() === $reason; }
    return false;
}
function manualNativeObservation(ChannelPostRegistryService $registry, array $snapshot, int $sequence = 930001, array $messagePatch = []): array
{
    $message = [
        'chat'=>['id'=>-1001845835645,'type'=>'channel','username'=>'anytour'], 'message_id'=>1678,
        'date'=>strtotime('2026-08-11 11:51:56 UTC'), 'edit_date'=>1800000000 + $sequence,
        'caption'=>$snapshot['text'], 'video'=>['file_id'=>'SYNTHETIC_FILE_ID','file_unique_id'=>'synthetic_video_1678','duration'=>28],
        'reply_markup'=>['inline_keyboard'=>$snapshot['keyboard']]];
    $event = ChannelPostIncomingAdapter::fromUpdate('telegram', ['update_id'=>$sequence, 'edited_channel_post'=>array_replace($message,$messagePatch)]);
    if (!$event) throw new RuntimeException('native_fixture_invalid');
    $event['snapshot']['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($event['snapshot']);
    return $registry->registerPublication('anytour','telegram','anyru_bot','-1001845835645','1678',$event['snapshot']);
}

[$manualDb,$manualRegistry,$manualChannel,$manualSnapshot,$manualPublisher] = manualPostFixture();
$changes = (int)$manualDb->query('SELECT total_changes()')->fetchColumn();
postCheck('manual exact-target GET does not create a publication or token', $manualRegistry->manualTelegramButtonPublication() === null && (int)$manualDb->query('SELECT total_changes()')->fetchColumn() === $changes);
$manualDb->exec("CREATE TRIGGER refuse_pending_manual BEFORE INSERT ON post_versions WHEN NEW.state='pending' BEGIN SELECT RAISE(FAIL,'preparation briefly queued'); END");
$prepared = $manualRegistry->prepareManualTelegramButton($manualChannel,$manualSnapshot,$manualPublisher);
$manualDb->exec('DROP TRIGGER refuse_pending_manual');
postCheck('manual preparation is born held atomically and owns its own opaque token', $prepared['state'] === 'requires_attention' && $prepared['error_reason'] === 'manual_approval_required'
    && $prepared['attempt_count'] === 0 && $prepared['write_attempts'] === 0 && $prepared['source_id'] === 9 && $prepared['external_message_id'] === '1678'
    && preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/D',$prepared['start_payload']) === 1 && $prepared['publisher'] === $manualPublisher && $prepared['manual_button_repair'] === true);
$clock = $manualDb->query('SELECT observed_revision_ms,telegram_sequence FROM post_publications')->fetch();
postCheck('public preparation never manufactures native observations or provider clocks', $clock['observed_revision_ms'] === null && $clock['telegram_sequence'] === null
    && (int)$manualDb->query('SELECT COUNT(*) FROM post_observations')->fetchColumn() === 0 && $prepared['snapshot']['raw_body'] === []
    && $prepared['snapshot']['metadata']['observation_kind'] === 'public_read' && !isset($prepared['snapshot']['metadata']['source_sequence']));
postCheck('prepared token resolves through the canonical bot with the exact hotel heading', $manualRegistry->resolveToken('anytour','telegram','anyru_bot',$prepared['start_payload'])['snapshot']['title'] === 'Aloft Palm Jumeirah'
    && $manualRegistry->resolveToken('anytour','telegram','ANYTOURBOT_bot',$prepared['start_payload']) === null
    && ChannelPostButtonTransport::buttonUrl($prepared) === 'https://t.me/anyru_bot?start='.$prepared['start_payload']);
$changes = (int)$manualDb->query('SELECT total_changes()')->fetchColumn();
$sameSnapshot = $manualSnapshot; $sameSnapshot['metadata']['checked_at'] = '2026-11-22T23:59:59+00:00';
$same = $manualRegistry->prepareManualTelegramButton($manualChannel,$sameSnapshot,$manualPublisher);
$read = $manualRegistry->manualTelegramButtonPublication();
postCheck('identical preparation and readback preserve token version receipt without writes', $same === $prepared && array_diff_key($read,['manual_original'=>true]) === $prepared
    && $read['manual_original']['version_id'] === $prepared['version_id'] && $read['manual_original']['state'] === 'requires_attention'
    && (int)$manualDb->query('SELECT total_changes()')->fetchColumn() === $changes);
postCheck('manual preparation is absent from automatic work and ordinary claims', $manualRegistry->pendingPublications('anytour','telegram','anyru_bot',5,false) === []
    && $manualRegistry->claimPublication($prepared['publication_id'],$prepared['version_id']) === null
    && !$manualRegistry->confirmObservedButton($prepared['publication_id'],$prepared['version_id'],['verified'=>true]));
$changedSnapshot = $manualSnapshot; $changedSnapshot['text'] .= ' Изменено.';
postCheck('changed public content cannot silently replace a reviewed token snapshot', manualPostThrows(fn()=>$manualRegistry->prepareManualTelegramButton($manualChannel,$changedSnapshot,$manualPublisher),'manual_publication_conflict')
    && array_diff_key($manualRegistry->manualTelegramButtonPublication(),['manual_original'=>true]) === $prepared);
$otherPublisher = $manualPublisher; $otherPublisher['credential_version'] = 2;
postCheck('preparation cannot silently rebind a saved publisher version', manualPostThrows(fn()=>$manualRegistry->prepareManualTelegramButton($manualChannel,$manualSnapshot,$otherPublisher),'manual_publication_conflict'));
$digest = ChannelPostRegistryService::manualTelegramButtonDigest($prepared);
$differentLabel = $prepared; $differentLabel['channel']['button_text'] = 'Другой текст';
$differentCredential = $prepared; $differentCredential['receipt']['credential_version'] = 2;
$differentVersion = $prepared; $differentVersion['version_id']++;
postCheck('approval digest binds the actual token target version label and publisher credential', $digest === $prepared['plan_digest']
    && $digest !== ChannelPostRegistryService::manualTelegramButtonDigest($differentLabel)
    && $digest !== ChannelPostRegistryService::manualTelegramButtonDigest($differentCredential)
    && $digest !== ChannelPostRegistryService::manualTelegramButtonDigest($differentVersion)
    && manualPostThrows(fn()=>$manualRegistry->claimManualTelegramButton($prepared,str_repeat('a',64)),'manual_approval_changed'));
foreach (['project_key'=>'anex','bot_key'=>'ANYTOURBOT_bot','channel_id'=>'-1001234567','source_id'=>10,'source_key'=>'tg_other'] as $key=>$value) {
    $wrong = $manualChannel; $wrong[$key] = $value;
    postCheck('manual preparation rejects foreign '.$key, manualPostThrows(fn()=>$manualRegistry->prepareManualTelegramButton($wrong,$manualSnapshot,$manualPublisher),'manual_target_required'));
}
$badSnapshot = $manualSnapshot; $badSnapshot['raw_body'] = ['message_id'=>1678];
postCheck('public preparation refuses a forged native body', manualPostThrows(fn()=>$manualRegistry->prepareManualTelegramButton($manualChannel,$badSnapshot,$manualPublisher)));
$badSnapshot = $manualSnapshot; $badSnapshot['metadata']['hotel_catalog_id'] = 1207;
postCheck('public preparation refuses another hotel or archive post identity', manualPostThrows(fn()=>$manualRegistry->prepareManualTelegramButton($manualChannel,$badSnapshot,$manualPublisher)));

$claim = $manualRegistry->claimManualTelegramButton($prepared,$digest);
postCheck('manual approval consumes one exclusive durable claim', $claim['state'] === 'claimed' && $claim['attempt_count'] === 1 && strlen($claim['claim_token']) === 32
    && manualPostThrows(fn()=>$manualRegistry->claimManualTelegramButton($prepared,$digest),'manual_repair_not_claimable')
    && manualPostThrows(fn()=>$manualRegistry->prepareManualTelegramButton($manualChannel,$manualSnapshot,$manualPublisher),'manual_repair_not_claimable'));
postCheck('manual external-write fence permits exactly one attempt', $manualRegistry->markExternalWriteStarted($claim['publication_id'],$claim['version_id'],$claim['claim_token'])
    && !$manualRegistry->markExternalWriteStarted($claim['publication_id'],$claim['version_id'],$claim['claim_token']));
postCheck('manual operation cannot be marked sent with an unverified summary', !$manualRegistry->finishPublication($claim['publication_id'],$claim['version_id'],$claim['claim_token'],'sent','verified',['verified'=>true,'button_verified'=>true]));
$verifiedReceipt = ['verified'=>true,'button_verified'=>true,'body_unchanged_by_method'=>true,'public_caption_matched'=>true,
    'publication_date_matched'=>true,'media_type_duration_matched'=>true,'native_media_identity_before_verified'=>false,
    'native_video_unique_id'=>'synthetic_video_1678','provider_result_sha256'=>str_repeat('b',64),'request_count'=>3,'write_attempts'=>1,
    'publisher_bot_id'=>'999','credential_version'=>99,'plan_digest'=>str_repeat('c',64),'content_preserved'=>true,
    'raw_body'=>'PRIVATE-native-response','access_token'=>'PRIVATE-key'];
postCheck('verified manual result finishes the original claim without storing secrets', $manualRegistry->finishPublication($claim['publication_id'],$claim['version_id'],$claim['claim_token'],'sent','verified',$verifiedReceipt));
$finished = $manualRegistry->manualTelegramButtonPublication();
postCheck('manual completion preserves immutable approval and reports limited evidence honestly', $finished['state'] === 'sent' && $finished['write_attempts'] === 1
    && $finished['publisher'] === $manualPublisher && $finished['plan_digest'] === $digest && $finished['receipt']['native_media_identity_before_verified'] === false
    && !isset($finished['receipt']['content_preserved']) && !str_contains(json_encode($finished['receipt']),'PRIVATE')
    && $finished['receipt']['native_video_unique_id'] === 'synthetic_video_1678');
postCheck('finished manual operation cannot be approved or finished a second time', manualPostThrows(fn()=>$manualRegistry->claimManualTelegramButton($finished,$digest),'manual_write_already_attempted')
    && !$manualRegistry->finishPublication($claim['publication_id'],$claim['version_id'],$claim['claim_token'],'sent','verified',$verifiedReceipt));
$afterSuccess = manualNativeObservation($manualRegistry,$manualSnapshot);
$afterRead = $manualRegistry->manualTelegramButtonPublication();
$afterResolved = $manualRegistry->resolveToken('anytour','telegram','anyru_bot',$prepared['start_payload']);
postCheck('same-body native successor preserves the hotel CTA without inventing native-before proof', $afterResolved['version_id'] === $afterSuccess['version_id']
    && $afterResolved['snapshot']['title'] === 'Aloft Palm Jumeirah' && $afterResolved['snapshot']['metadata']['hotel_catalog_id'] === 61159
    && $afterResolved['snapshot']['metadata']['context_reference_kind'] === 'public_hotel_binding'
    && $afterResolved['snapshot']['metadata']['capability'] === 'requires_attention'
    && $afterResolved['snapshot']['metadata']['capability_reason'] === 'unsupported_media'
    && $afterResolved['snapshot']['raw_body']['video']['file_unique_id'] === 'synthetic_video_1678');
postCheck('native current hold and original successful attempt remain separately readable', $afterRead['state'] === 'requires_attention'
    && $afterRead['version_id'] !== $afterRead['manual_original']['version_id'] && $afterRead['manual_original']['version_id'] === $claim['version_id']
    && $afterRead['manual_original']['state'] === 'sent' && $afterRead['manual_original']['write_attempts'] === 1
    && $afterRead['manual_original']['receipt']['provider_result_sha256'] === str_repeat('b',64)
    && $manualRegistry->pendingPublications('anytour','telegram','anyru_bot') === []);
$wrongMedia = manualNativeObservation($manualRegistry,$manualSnapshot,930002,['video'=>[
    'file_id'=>'SYNTHETIC_FILE_ID','file_unique_id'=>'synthetic_video_1678','duration'=>29]]);
postCheck('changed native video clears the inherited hotel reference despite the old generic fingerprint', $wrongMedia['version_id'] !== $afterSuccess['version_id']
    && $wrongMedia['snapshot']['title'] === '' && !isset($wrongMedia['snapshot']['metadata']['hotel_catalog_id'])
    && $wrongMedia['snapshot']['raw_body']['video']['duration'] === 29 && $wrongMedia['state'] === 'requires_attention');
$restoredMedia = manualNativeObservation($manualRegistry,$manualSnapshot,930003);
$otherNativeVideo = manualNativeObservation($manualRegistry,$manualSnapshot,930004,['video'=>[
    'file_id'=>'SYNTHETIC_OTHER_FILE','file_unique_id'=>'different_video_1678','duration'=>28]]);
postCheck('a video differing from the successful native result cannot inherit its hotel binding', $restoredMedia['snapshot']['title'] === 'Aloft Palm Jumeirah'
    && $otherNativeVideo['version_id'] !== $restoredMedia['version_id'] && $otherNativeVideo['snapshot']['title'] === ''
    && !isset($otherNativeVideo['snapshot']['metadata']['hotel_catalog_id']));
$wrongCaption = manualNativeObservation($manualRegistry,$manualSnapshot,930005,['caption'=>'Another Hotel — changed native caption']);
postCheck('changed native caption keeps its actual body and does not inherit Aloft metadata', $wrongCaption['snapshot']['text'] === 'Another Hotel — changed native caption'
    && $wrongCaption['snapshot']['title'] === '' && !isset($wrongCaption['snapshot']['metadata']['hotel_catalog_id'])
    && $wrongCaption['snapshot']['raw_body']['caption'] === 'Another Hotel — changed native caption');

[$atomicDb,$atomicRegistry,$atomicChannel,$atomicSnapshot,$atomicPublisher] = manualPostFixture();
$atomicDb->exec("CREATE TRIGGER fail_manual_version BEFORE INSERT ON post_versions BEGIN SELECT RAISE(FAIL,'synthetic version failure'); END");
postCheck('preparation failure rolls back the token and held version together', manualPostThrows(fn()=>$atomicRegistry->prepareManualTelegramButton($atomicChannel,$atomicSnapshot,$atomicPublisher))
    && (int)$atomicDb->query('SELECT COUNT(*) FROM post_publications')->fetchColumn() === 0 && (int)$atomicDb->query('SELECT COUNT(*) FROM post_versions')->fetchColumn() === 0);

[$guardDb,$guardRegistry,$guardChannel,$guardPublic,$guardPublisher] = manualPostFixture();
$guardCalls = 0; $guardOwned = true;
$denyPreparation = static function () use ($guardDb, &$guardCalls, &$guardOwned): bool {
    $guardCalls++; $guardOwned = $guardOwned && $guardDb->inTransaction(); return false;
};
$emptyDenied = manualPostThrows(fn()=>$guardRegistry->prepareManualTelegramButton($guardChannel,$guardPublic,$guardPublisher,$denyPreparation),'forbidden');
$emptyUntouched = $guardRegistry->manualTelegramButtonPublication() === null
    && (int)$guardDb->query('SELECT COUNT(*) FROM post_publications')->fetchColumn() === 0
    && (int)$guardDb->query('SELECT COUNT(*) FROM post_versions')->fetchColumn() === 0;
$guardRegistry->prepareManualTelegramButton($guardChannel,$guardPublic,$guardPublisher);
$heldBeforeDenial = $guardRegistry->manualTelegramButtonPublication();
$heldChanges = (int)$guardDb->query('SELECT total_changes()')->fetchColumn();
$heldDenied = manualPostThrows(fn()=>$guardRegistry->prepareManualTelegramButton($guardChannel,$guardPublic,$guardPublisher,$denyPreparation),'forbidden');
postCheck('revoked preparation authority creates no token and leaves an existing held plan unchanged', $emptyDenied && $emptyUntouched && $heldDenied
    && $guardCalls === 2 && $guardOwned && !$guardDb->inTransaction()
    && $guardRegistry->manualTelegramButtonPublication() === $heldBeforeDenial
    && (int)$guardDb->query('SELECT total_changes()')->fetchColumn() === $heldChanges);

[$changedDb,$changedRegistry,$changedChannel,$changedPublic,$changedPublisher] = manualPostFixture();
$changedPrepared = $changedRegistry->prepareManualTelegramButton($changedChannel,$changedPublic,$changedPublisher);
$changedRegistry->configureChannel(['project_key'=>'anytour','platform'=>'telegram','bot_key'=>'anyru_bot','channel_id'=>'-1001845835645',
    'source_key'=>'tg_anytour_msk','bot_url'=>'https://t.me/anyru_bot','is_enabled'=>true,'expected_version'=>1,'button_text'=>'Изменённый текст']);
postCheck('channel configuration changes invalidate an earlier approval', manualPostThrows(fn()=>$changedRegistry->claimManualTelegramButton($changedPrepared,$changedPrepared['plan_digest']),'manual_channel_changed')
    && manualPostThrows(fn()=>$changedRegistry->prepareManualTelegramButton($changedChannel,$changedPublic,$changedPublisher),'manual_channel_changed'));
[$finalDb,$finalRegistry,$finalChannel,$finalPublic,$finalPublisher] = manualPostFixture();
$finalPrepared = $finalRegistry->prepareManualTelegramButton($finalChannel,$finalPublic,$finalPublisher);
$finalClaim = $finalRegistry->claimManualTelegramButton($finalPrepared,$finalPrepared['plan_digest']);
$finalRegistry->configureChannel(['project_key'=>'anytour','platform'=>'telegram','bot_key'=>'anyru_bot','channel_id'=>'-1001845835645',
    'source_key'=>'tg_anytour_msk','bot_url'=>'https://t.me/anyru_bot','is_enabled'=>true,'expected_version'=>1,'button_text'=>'Новая конфигурация']);
postCheck('configuration change after claim blocks the final durable write decision', !$finalRegistry->markExternalWriteStarted($finalClaim['publication_id'],$finalClaim['version_id'],$finalClaim['claim_token'])
    && $finalRegistry->manualTelegramButtonPublication()['write_attempts'] === 0
    && $finalRegistry->manualTelegramButtonPublication()['manual_original']['write_attempts'] === 0);
[$sourceDb,$sourceRegistry,$sourceChannel,$sourcePublic,$sourcePublisher] = manualPostFixture();
$sourcePrepared = $sourceRegistry->prepareManualTelegramButton($sourceChannel,$sourcePublic,$sourcePublisher);
$sourceDb->exec("UPDATE conversation_sources SET handling_mode='ai' WHERE id=9");
postCheck('a retired or AI-only source blocks a prepared manual approval', manualPostThrows(fn()=>$sourceRegistry->claimManualTelegramButton($sourcePrepared,$sourcePrepared['plan_digest']),'manual_channel_changed')
    && $sourceRegistry->manualTelegramButtonPublication() === null);

[$nativeDb,$nativeRegistry,$nativeChannel,$nativePublic,$nativePublisher] = manualPostFixture();
$nativeFirst = manualNativeObservation($nativeRegistry,$nativePublic);
postCheck('an existing native publication cannot be relabelled as a public preparation', manualPostThrows(fn()=>$nativeRegistry->prepareManualTelegramButton($nativeChannel,$nativePublic,$nativePublisher),'manual_publication_changed')
    && $nativeRegistry->manualTelegramButtonPublication()['start_payload'] === $nativeFirst['start_payload']
    && (int)$nativeDb->query('SELECT COUNT(*) FROM post_versions')->fetchColumn() === 1);

[$raceDb,$raceRegistry,$raceChannel,$racePublic,$racePublisher] = manualPostFixture();
$racePrepared = $raceRegistry->prepareManualTelegramButton($raceChannel,$racePublic,$racePublisher);
$raceClaim = $raceRegistry->claimManualTelegramButton($racePrepared,$racePrepared['plan_digest']);
$nativeLater = manualNativeObservation($raceRegistry,$racePublic);
postCheck('a native event racing the manual claim preserves provenance and holds its successor', $nativeLater['version_id'] !== $raceClaim['version_id']
    && $nativeLater['start_payload'] === $racePrepared['start_payload'] && $nativeLater['state'] === 'requires_attention'
    && $nativeLater['error_reason'] === 'manual_observation_changed' && $nativeLater['manual_button_repair'] === true
    && ($nativeLater['snapshot']['metadata']['observation_kind'] ?? '') !== 'public_read'
    && $nativeLater['snapshot']['metadata']['source_sequence'] === '930001'
    && $raceRegistry->versionForPublication($racePrepared['publication_id'],$racePrepared['version_id'])['snapshot']['metadata']['observation_kind'] === 'public_read');
postCheck('raced native version cannot permit the old write or an automatic successor', !$raceRegistry->markExternalWriteStarted($raceClaim['publication_id'],$raceClaim['version_id'],$raceClaim['claim_token'])
    && $raceRegistry->pendingPublications('anytour','telegram','anyru_bot') === []
    && $raceRegistry->claimPublication($nativeLater['publication_id'],$nativeLater['version_id']) === null);

[$leaseDb,$leaseRegistry,$leaseChannel,$leasePublic,$leasePublisher] = manualPostFixture();
$leasePrepared = $leaseRegistry->prepareManualTelegramButton($leaseChannel,$leasePublic,$leasePublisher);
$leaseClaim = $leaseRegistry->claimManualTelegramButton($leasePrepared,$leasePrepared['plan_digest']);
$leaseDb->exec("UPDATE post_versions SET claimed_at='2000-01-01 00:00:00' WHERE id=".$leaseClaim['version_id']);
postCheck('an expired zero-write manual lease stays held and cannot become a retry', $leaseRegistry->pendingPublications('anytour','telegram','anyru_bot') === []
    && $leaseRegistry->manualTelegramButtonPublication()['state'] === 'requires_attention'
    && $leaseRegistry->manualTelegramButtonPublication()['write_attempts'] === 0
    && manualPostThrows(fn()=>$leaseRegistry->claimManualTelegramButton($leasePrepared,$leasePrepared['plan_digest']),'manual_repair_not_claimable'));

[$unknownDb,$unknownRegistry,$unknownChannel,$unknownPublic,$unknownPublisher] = manualPostFixture();
$unknownPrepared = $unknownRegistry->prepareManualTelegramButton($unknownChannel,$unknownPublic,$unknownPublisher);
$unknownClaim = $unknownRegistry->claimManualTelegramButton($unknownPrepared,$unknownPrepared['plan_digest']);
$unknownRegistry->markExternalWriteStarted($unknownClaim['publication_id'],$unknownClaim['version_id'],$unknownClaim['claim_token']);
$unknownDb->exec("UPDATE post_versions SET claimed_at='2000-01-01 00:00:00' WHERE id=".$unknownClaim['version_id']);
$unknownPending = $unknownRegistry->pendingPublications('anytour','telegram','anyru_bot');
postCheck('an expired attempted manual write remains UNKNOWN outside automatic work', $unknownPending === []
    && $unknownRegistry->manualTelegramButtonPublication()['state'] === 'unknown'
    && $unknownRegistry->manualTelegramButtonPublication()['write_attempts'] === 1
    && manualPostThrows(fn()=>$unknownRegistry->claimManualTelegramButton($unknownPrepared,$unknownPrepared['plan_digest']),'manual_write_already_attempted'));
$unknownNative = manualNativeObservation($unknownRegistry,$unknownPublic);
postCheck('a later native version retains the all-version no-replay fence', $unknownNative['state'] === 'requires_attention'
    && $unknownRegistry->pendingPublications('anytour','telegram','anyru_bot') === []
    && $unknownRegistry->claimPublication($unknownNative['publication_id'],$unknownNative['version_id']) === null
    && (int)$unknownDb->query('SELECT SUM(write_attempts) FROM post_versions')->fetchColumn() === 1
    && $unknownRegistry->manualTelegramButtonPublication()['manual_original']['state'] === 'unknown');

[$historicalDb,$historicalRegistry,$historicalChannel,$historicalPublic,$historicalPublisher] = manualPostFixture();
$historicalPrepared = $historicalRegistry->prepareManualTelegramButton($historicalChannel,$historicalPublic,$historicalPublisher);
$historicalDb->exec("INSERT INTO post_versions (publication_id,version_number,content_hash,snapshot_json,state,write_attempts,receipt_json,created_at,updated_at)
    SELECT publication_id,2,content_hash,snapshot_json,'unknown',1,receipt_json,created_at,updated_at FROM post_versions WHERE id=".$historicalPrepared['version_id']);
postCheck('a non-current historical attempted write still blocks the exact held version', manualPostThrows(fn()=>$historicalRegistry->claimManualTelegramButton($historicalPrepared,$historicalPrepared['plan_digest']),'manual_write_already_attempted'));

function hotelTransferFixture(int $hotel = 17595): array
{
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    createChannelPostRegistryFixture($db);
    $db->exec("INSERT INTO projects VALUES (1,'anytour','AnyTour fixture',1);
        INSERT INTO conversation_sources VALUES (9,1,'tg_anytour_msk','Large channel','telegram',1,'manager'),(10,1,'tg_hotel_source','Source channel','telegram',1,'manager');");
    $service = new ChannelPostRegistryService($db);
    $config = ['project_key'=>'anytour','platform'=>'telegram','bot_key'=>'anyru_bot','channel_id'=>'-1001845835645',
        'source_key'=>'tg_anytour_msk','display_name'=>'Large channel','bot_url'=>'https://t.me/anyru_bot',
        'button_text'=>'Рассчитать на мои даты','is_enabled'=>true];
    $service->configureChannel($config);
    $service->configureChannel($config + ['expected_version'=>1]);
    $sourceChannel = $service->configureChannel(array_replace($config, ['channel_id'=>'-1002262666616','source_key'=>'tg_hotel_source','display_name'=>'Source channel']));
    $pairs = [17595=>['455',1003,'862',1726731731], 358=>['65',211,'328',1705145228],
        393=>['85',247,'1083',1745392994],1260=>['548',1220,'288',1703918786]];
    [$sourceMid,$sourceId,$targetMid,$published] = $pairs[$hotel];
    $token = 'p_' . str_pad('source' . $hotel, 24, 'x');
    $db->prepare('INSERT INTO post_publications (id,post_channel_id,source_id,external_message_id,start_payload) VALUES (?,?,?,?,?)')
        ->execute([$sourceId,$sourceChannel['id'],10,$sourceMid,$token]);
    $message = ['chat'=>['id'=>-1002262666616,'type'=>'channel','username'=>'any_toour'], 'message_id'=>(int)$sourceMid,
        'date'=>1700000000, 'caption'=>'Original hotel '.$hotel.' with an old price',
        'photo'=>[['file_id'=>'source_file_'.$hotel,'file_unique_id'=>'source_unique_'.$hotel,'width'=>1000,'height'=>1000]],
        'reply_markup'=>['inline_keyboard'=>[[['text'=>$config['button_text'],'url'=>$config['bot_url'].'?start='.$token]]]]];
    $snapshot = ChannelPostIncomingAdapter::telegramSnapshot($message);
    $snapshot['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($snapshot);
    $snapshot['metadata']['hotel_catalog_id'] = $hotel;
    $snapshot['metadata']['monitor_price_record_id'] = 'old-price-must-not-be-copied';
    $snapshot['metadata']['source_event_id'] = 'source-hotel-fixture-'.$hotel;
    $source = $service->registerPublication('anytour','telegram','anyru_bot','-1002262666616',$sourceMid,$snapshot);
    $service->confirmObservedButton($sourceId,$source['version_id'],['verified'=>true,'button_verified'=>true]);
    $caption = 'Approved hotel '.$hotel."\nNew quoted price — 123 456 ₽";
    $row = ['hotel_id'=>$hotel,'source_message_id'=>$sourceMid,'source_publication_id'=>$sourceId,
        'source_version_id'=>$source['version_id'],'source_content_hash'=>$source['content_hash'],
        'source_caption_sha256'=>hash('sha256',$message['caption']),
        'source_file_id_sha256'=>hash('sha256',$message['photo'][0]['file_id']),
        'source_file_unique_id_sha256'=>hash('sha256',$message['photo'][0]['file_unique_id']),
        'target_message_id'=>$targetMid,'target_published_unixtime'=>$published,
        'target_caption_sha256'=>hash('sha256','Original generic target'), 'target_photo_marker_sha256'=>hash('sha256','original-target-photo'),
        'target_config_version'=>2,'next_caption'=>$caption,'next_caption_sha256'=>hash('sha256',$caption),
        'button_text'=>$config['button_text'],'bot_url'=>$config['bot_url'],'price_record_id'=>hash('sha256','approved-quote-'.$hotel)];
    return [$db,$service,$row,hash('sha256','approved-plan-'.$hotel),$message];
}
function hotelTransferResponse(array $reservation, array $row, array $source): array
{
    return ['http'=>200,'transport_error'=>false,'data'=>['ok'=>true,'result'=>[
        'chat'=>['id'=>-1001845835645,'type'=>'channel','username'=>'anytour'],
        'message_id'=>(int)$row['target_message_id'],'date'=>$row['target_published_unixtime'],'edit_date'=>1795396000,
        'caption'=>$row['next_caption'],'photo'=>$source['photo'],
        'reply_markup'=>['inline_keyboard'=>[[['text'=>$row['button_text'],'url'=>$reservation['button_url']]]]]]]];
}
function hotelTransferObserve(ChannelPostRegistryService $service, array $response, bool $confirm = true, int $sequence = 955001): array
{
    $update = ['update_id'=>$sequence,'edited_channel_post'=>$response['data']['result']];
    $event = ChannelPostIncomingAdapter::fromUpdate('telegram',$update);
    $snapshot = $event['snapshot']; $snapshot['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($snapshot);
    $post = $service->registerPublication('anytour','telegram','anyru_bot','-1001845835645',$event['external_message_id'],$snapshot);
    if ($confirm) $service->confirmObservedButton($post['publication_id'],$post['version_id'],['event_id'=>$event['source_event_id'],'verified'=>true]);
    return $service->publication($post['publication_id']);
}
$approveTransfer = static fn(array $row, string $digest): bool => true;
[$transferDb,$transferRegistry,$transferRow,$transferPlan,$transferSource] = hotelTransferFixture();
$beforeTransfer = $transferDb->query('SELECT * FROM post_publications')->fetchAll();
postCheck('hotel identity reservation defaults denied without creating a native observation or identity',
    manualPostThrows(fn()=>$transferRegistry->reserveTelegramHotelTransfer($transferRow,$transferPlan),'hotel_transfer_approval_required')
    && $beforeTransfer === $transferDb->query('SELECT * FROM post_publications')->fetchAll());
$wrongTransferScope = $transferRow; $wrongTransferScope['target_message_id'] = '1678';
postCheck('hotel transfer cannot expand its fixed pairs into the separate Aloft repair',
    manualPostThrows(fn()=>$transferRegistry->reserveTelegramHotelTransfer($wrongTransferScope,$transferPlan,$approveTransfer),'hotel_transfer_scope_invalid')
    && $beforeTransfer === $transferDb->query('SELECT * FROM post_publications')->fetchAll());
$reservation = $transferRegistry->reserveTelegramHotelTransfer($transferRow,$transferPlan,$approveTransfer);
$rawReservation = $transferDb->query('SELECT * FROM post_publications WHERE id='.$reservation['publication_id'])->fetch();
postCheck('approved identity-only reservation is invisible to queue, token resolution and native-version getters',
    $rawReservation['current_version_id'] === null && $rawReservation['observed_revision_ms'] === null && $rawReservation['telegram_sequence'] === null
    && $transferRegistry->publication($reservation['publication_id']) === null
    && $transferRegistry->resolveToken('anytour','telegram','anyru_bot',$reservation['start_payload']) === null
    && $transferRegistry->pendingPublications('anytour','telegram','anyru_bot') === []
    && (int)$transferDb->query('SELECT COUNT(*) FROM post_versions')->fetchColumn() === 1);
postCheck('an existing or orphan reservation is never silently reused',
    manualPostThrows(fn()=>$transferRegistry->reserveTelegramHotelTransfer($transferRow,$transferPlan,$approveTransfer),'hotel_transfer_identity_exists'));
$reorderedReservation = $reservation; ksort($reorderedReservation);
postCheck('final reservation CAS accepts durable JSON key order but rejects changed plans',
    $transferRegistry->assertTelegramHotelTransferReservation($reorderedReservation,$transferRow,$transferPlan) === $reorderedReservation
    && manualPostThrows(fn()=>$transferRegistry->assertTelegramHotelTransferReservation($reservation,$transferRow,str_repeat('f',64)),'hotel_transfer_reservation_changed'));
$sourceBeforeTransfer = $transferDb->query('SELECT snapshot_json FROM post_versions WHERE id='.$transferRow['source_version_id'])->fetchColumn();
$transferResponse = hotelTransferResponse($reservation,$transferRow,$transferSource);
foreach (['caption','date','photo','keyboard','lost_response'] as $invalid) {
    $badResponse = $transferResponse;
    if ($invalid === 'caption') $badResponse['data']['result']['caption'] = 'Unapproved text';
    elseif ($invalid === 'date') $badResponse['data']['result']['date']++;
    elseif ($invalid === 'photo') $badResponse['data']['result']['photo'][0]['file_unique_id'] = 'wrong-photo';
    elseif ($invalid === 'keyboard') $badResponse['data']['result']['reply_markup']['inline_keyboard'][0][] = ['text'=>'extra','url'=>'https://example.test'];
    else $badResponse = ['http'=>0,'transport_error'=>true,'data'=>null];
    postCheck('unconfirmed hotel media '.$invalid.' cannot create a native version',
        manualPostThrows(fn()=>$transferRegistry->completeTelegramHotelTransfer($reservation,$transferRow,$transferPlan,$badResponse),'hotel_transfer_response_unconfirmed')
        && $transferRegistry->publication($reservation['publication_id']) === null);
}
$completedTransfer = $transferRegistry->completeTelegramHotelTransfer($reservation,$transferRow,$transferPlan,$transferResponse);
postCheck('one authentic media response creates a sent hotel-bound native version with no button attempt or false preservation claim',
    $completedTransfer['state'] === 'sent' && $completedTransfer['version_number'] === 1 && $completedTransfer['write_attempts'] === 0
    && $completedTransfer['snapshot']['text'] === $transferRow['next_caption'] && $completedTransfer['snapshot']['raw_body'] === $transferResponse['data']['result']
    && $completedTransfer['snapshot']['metadata']['hotel_catalog_id'] === 17595
    && $completedTransfer['snapshot']['metadata']['hotel_transfer_price_record_id'] === $transferRow['price_record_id']
    && !isset($completedTransfer['snapshot']['metadata']['monitor_price_record_id'])
    && !isset($completedTransfer['snapshot']['metadata']['source_sequence'])
    && $completedTransfer['receipt']['media_write_attempts'] === 1 && !isset($completedTransfer['receipt']['content_preserved'])
    && $transferRegistry->resolveToken('anytour','telegram','anyru_bot',$reservation['start_payload'])['snapshot']['metadata']['hotel_catalog_id'] === 17595
    && $sourceBeforeTransfer === $transferDb->query('SELECT snapshot_json FROM post_versions WHERE id='.$transferRow['source_version_id'])->fetchColumn());
$beforeDuplicateCompletion = [$transferDb->query('SELECT * FROM post_versions')->fetchAll(),$transferDb->query('SELECT * FROM post_observations')->fetchAll()];
$againTransfer = $transferRegistry->completeTelegramHotelTransfer($reservation,$transferRow,$transferPlan,$transferResponse);
postCheck('repeating only DB completion is read-only and cannot allocate a second version or observation',
    $againTransfer['already_completed'] && $againTransfer['version_id'] === $completedTransfer['version_id']
    && $beforeDuplicateCompletion === [$transferDb->query('SELECT * FROM post_versions')->fetchAll(),$transferDb->query('SELECT * FROM post_observations')->fetchAll()]);
$lateTransfer = hotelTransferObserve($transferRegistry,$transferResponse);
postCheck('late authentic media webhook retains completed binding and cannot enqueue a second button edit',
    $lateTransfer['version_id'] === $completedTransfer['version_id'] && $lateTransfer['snapshot']['metadata']['hotel_catalog_id'] === 17595
    && $transferRegistry->pendingPublications('anytour','telegram','anyru_bot') === []);

foreach ([true,false] as $webhookConfirmed) {
    [$raceDb,$raceRegistry,$raceRow,$racePlan,$raceSource] = hotelTransferFixture(358);
    $raceReservation = $raceRegistry->reserveTelegramHotelTransfer($raceRow,$racePlan,$approveTransfer);
    $raceResponse = hotelTransferResponse($raceReservation,$raceRow,$raceSource);
    $nativeFirst = hotelTransferObserve($raceRegistry,$raceResponse,$webhookConfirmed,955009);
    $nativeSnapshot = $raceDb->query('SELECT snapshot_json FROM post_versions WHERE id='.$nativeFirst['version_id'])->fetchColumn();
    $nativeClock = $raceDb->query('SELECT observed_revision_ms,telegram_sequence FROM post_publications WHERE id='.$nativeFirst['publication_id'])->fetch();
    $raceDone = $raceRegistry->completeTelegramHotelTransfer($raceReservation,$raceRow,$racePlan,$raceResponse);
    postCheck('webhook-first '.($webhookConfirmed?'sent':'unclaimed pending').' result adopts immutable hotel metadata and retains real clocks',
        $raceDone['state'] === 'sent' && $raceDone['version_number'] === 2 && $raceDone['start_payload'] === $nativeFirst['start_payload']
        && $raceDone['snapshot']['metadata']['hotel_catalog_id'] === 358 && $raceDone['snapshot']['metadata']['source_sequence'] === '955009'
        && $nativeSnapshot === $raceDb->query('SELECT snapshot_json FROM post_versions WHERE id='.$nativeFirst['version_id'])->fetchColumn()
        && $nativeClock === $raceDb->query('SELECT observed_revision_ms,telegram_sequence FROM post_publications WHERE id='.$nativeFirst['publication_id'])->fetch());
}
[$rollbackDb,$rollbackRegistry,$rollbackRow,$rollbackPlan,$rollbackSource] = hotelTransferFixture(358);
$rollbackReservation = $rollbackRegistry->reserveTelegramHotelTransfer($rollbackRow,$rollbackPlan,$approveTransfer);
$rollbackResponse = hotelTransferResponse($rollbackReservation,$rollbackRow,$rollbackSource);
$rollbackNative = hotelTransferObserve($rollbackRegistry,$rollbackResponse,false);
$beforeFailedCompletion = [$rollbackDb->query('SELECT * FROM post_publications')->fetchAll(),$rollbackDb->query('SELECT * FROM post_versions')->fetchAll(),$rollbackDb->query('SELECT * FROM post_observations')->fetchAll()];
$rollbackDb->exec("CREATE TRIGGER reject_transfer_receipt BEFORE INSERT ON post_observations BEGIN SELECT RAISE(FAIL,'synthetic receipt failure'); END");
postCheck('receipt storage failure rolls back the appended binding, current-version CAS and pending supersession together',
    manualPostThrows(fn()=>$rollbackRegistry->completeTelegramHotelTransfer($rollbackReservation,$rollbackRow,$rollbackPlan,$rollbackResponse))
    && $beforeFailedCompletion === [$rollbackDb->query('SELECT * FROM post_publications')->fetchAll(),$rollbackDb->query('SELECT * FROM post_versions')->fetchAll(),$rollbackDb->query('SELECT * FROM post_observations')->fetchAll()]);
$rollbackDb->exec('DROP TRIGGER reject_transfer_receipt');
$rollbackDb->exec('UPDATE post_publications SET observed_revision_ms=observed_revision_ms+1000 WHERE id='.$rollbackNative['publication_id']);
postCheck('an older HTTP result never lowers a newer authenticated observation clock',
    manualPostThrows(fn()=>$rollbackRegistry->completeTelegramHotelTransfer($rollbackReservation,$rollbackRow,$rollbackPlan,$rollbackResponse),'hotel_transfer_target_changed')
    && $rollbackRegistry->publication($rollbackNative['publication_id'])['version_id'] === $rollbackNative['version_id']);
foreach (['claimed','write_started','unknown'] as $heldState) {
    [$heldDb,$heldRegistry,$heldRow,$heldPlan,$heldSource] = hotelTransferFixture(393);
    $heldReservation = $heldRegistry->reserveTelegramHotelTransfer($heldRow,$heldPlan,$approveTransfer);
    $heldResponse = hotelTransferResponse($heldReservation,$heldRow,$heldSource);
    $heldPost = hotelTransferObserve($heldRegistry,$heldResponse,false);
    $heldClaim = $heldRegistry->claimPublication($heldPost['publication_id'],$heldPost['version_id']);
    if ($heldState !== 'claimed') $heldRegistry->markExternalWriteStarted($heldPost['publication_id'],$heldPost['version_id'],$heldClaim['claim_token']);
    if ($heldState === 'unknown') $heldRegistry->finishPublication($heldPost['publication_id'],$heldPost['version_id'],$heldClaim['claim_token'],'unknown','edit_outcome_unknown');
    $heldBefore = $heldDb->query('SELECT * FROM post_versions')->fetchAll();
    $reason = $heldState === 'claimed' ? 'hotel_transfer_native_claim_busy' : 'hotel_transfer_target_held';
    postCheck('hotel adoption holds '.$heldState.' without resetting attempts, claims or snapshots',
        manualPostThrows(fn()=>$heldRegistry->completeTelegramHotelTransfer($heldReservation,$heldRow,$heldPlan,$heldResponse),$reason)
        && $heldBefore === $heldDb->query('SELECT * FROM post_versions')->fetchAll());
    if ($heldState === 'claimed') {
        $heldRegistry->finishPublication($heldPost['publication_id'],$heldPost['version_id'],$heldClaim['claim_token'],'sent','observed_in_channel_update',['verified'=>true]);
        postCheck('completion alone succeeds after the zero-write native observer releases its exact claim',
            $heldRegistry->completeTelegramHotelTransfer($heldReservation,$heldRow,$heldPlan,$heldResponse)['state'] === 'sent');
    }
}
[$foreignDb,$foreignRegistry,$foreignRow,$foreignPlan,$foreignSource] = hotelTransferFixture(1260);
$foreignReservation = $foreignRegistry->reserveTelegramHotelTransfer($foreignRow,$foreignPlan,$approveTransfer);
$foreignExpected = hotelTransferResponse($foreignReservation,$foreignRow,$foreignSource);
$foreignResponse = $foreignExpected; $foreignResponse['data']['result']['caption'] = 'A different author changed this post';
$foreignPost = hotelTransferObserve($foreignRegistry,$foreignResponse,false);
postCheck('a real intervening target observation invalidates pre-write reservation and rejects adoption of different content',
    manualPostThrows(fn()=>$foreignRegistry->assertTelegramHotelTransferReservation($foreignReservation,$foreignRow,$foreignPlan),'hotel_transfer_reservation_changed')
    && manualPostThrows(fn()=>$foreignRegistry->completeTelegramHotelTransfer($foreignReservation,$foreignRow,$foreignPlan,$foreignExpected),'hotel_transfer_target_changed')
    && $foreignRegistry->publication($foreignPost['publication_id'])['version_id'] === $foreignPost['version_id']);
$foreignRegistry->claimPublication($foreignPost['publication_id'],$foreignPost['version_id']);
postCheck('a claim on different native content cannot be misreported as retryable observer contention',
    manualPostThrows(fn()=>$foreignRegistry->completeTelegramHotelTransfer($foreignReservation,$foreignRow,$foreignPlan,$foreignExpected),'hotel_transfer_target_changed'));

[$changedDb,$changedRegistry,$changedRow,$changedPlan,$changedSource] = hotelTransferFixture();
$changedReservation = $changedRegistry->reserveTelegramHotelTransfer($changedRow,$changedPlan,$approveTransfer);
$changedResponse = hotelTransferResponse($changedReservation,$changedRow,$changedSource);
$changedDb->exec('UPDATE post_channels SET config_version=config_version+1 WHERE channel_id=\'-1001845835645\'');
postCheck('current channel configuration drift prevents final CAS and native completion',
    manualPostThrows(fn()=>$changedRegistry->assertTelegramHotelTransferReservation($changedReservation,$changedRow,$changedPlan),'hotel_transfer_configuration_changed')
    && manualPostThrows(fn()=>$changedRegistry->completeTelegramHotelTransfer($changedReservation,$changedRow,$changedPlan,$changedResponse),'hotel_transfer_configuration_changed'));
[$sourceRaceDb,$sourceRaceRegistry,$sourceRaceRow,$sourceRacePlan,$sourceRaceMessage] = hotelTransferFixture();
$sourceRaceReservation = $sourceRaceRegistry->reserveTelegramHotelTransfer($sourceRaceRow,$sourceRacePlan,$approveTransfer);
$sourceRaceResponse = hotelTransferResponse($sourceRaceReservation,$sourceRaceRow,$sourceRaceMessage);
$sourceRaceMessage['caption'] = 'A newer source price';
$sourceRaceSnapshot = ChannelPostIncomingAdapter::telegramSnapshot($sourceRaceMessage);
$sourceRaceSnapshot['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($sourceRaceSnapshot);
$sourceRaceSnapshot['metadata']['source_event_id'] = 'new-source-event';
$sourceRaceRegistry->registerPublication('anytour','telegram','anyru_bot','-1002262666616',$sourceRaceRow['source_message_id'],$sourceRaceSnapshot);
postCheck('a changed source version cannot be used by pre-write CAS or completion of an obsolete approved draft',
    manualPostThrows(fn()=>$sourceRaceRegistry->assertTelegramHotelTransferReservation($sourceRaceReservation,$sourceRaceRow,$sourceRacePlan),'hotel_transfer_source_changed')
    && manualPostThrows(fn()=>$sourceRaceRegistry->completeTelegramHotelTransfer($sourceRaceReservation,$sourceRaceRow,$sourceRacePlan,$sourceRaceResponse),'hotel_transfer_source_changed'));


[$bindingDb, $bindingRegistry, $bindingTransferRow, , $bindingMessage] = hotelTransferFixture();
$bindingDigest = hash('sha256', 'accepted-source-binding-evidence');
$readBindingTables = static fn(): array => [
    $bindingDb->query('SELECT * FROM post_publications ORDER BY id')->fetchAll(),
    $bindingDb->query('SELECT * FROM post_versions ORDER BY id')->fetchAll(),
    $bindingDb->query('SELECT * FROM post_observations ORDER BY publication_id,source_event_key')->fetchAll(),
];
$bindingVersion = $bindingTransferRow['source_version_id'];
$bindingCurrent = $bindingRegistry->publication($bindingTransferRow['source_publication_id']);
$bindingSnapshot = $bindingCurrent['snapshot'];
unset($bindingSnapshot['metadata']['hotel_catalog_id']);
$bindingDb->prepare('UPDATE post_versions SET snapshot_json=? WHERE id=?')
    ->execute([json_encode($bindingSnapshot, JSON_THROW_ON_ERROR), $bindingVersion]);
$bindingDb->prepare('UPDATE post_publications SET observed_revision_ms=?,telegram_sequence=? WHERE id=?')
    ->execute([1700000000000, '90001', $bindingCurrent['publication_id']]);
$bindingRow = [
    'source_publication_id'=>$bindingCurrent['publication_id'],
    'source_version_id'=>$bindingVersion,
    'source_message_id'=>$bindingTransferRow['source_message_id'],
    'hotel_id'=>$bindingTransferRow['hotel_id'],
    'source_content_hash'=>$bindingCurrent['content_hash'],
    'source_photo_sha256'=>$bindingTransferRow['source_file_unique_id_sha256'],
    'source_button_sha256'=>hash('sha256', $bindingMessage['reply_markup']['inline_keyboard'][0][0]['url']),
];

$secondBindingToken = 'p_' . str_pad('source358', 24, 'x');
$bindingDb->prepare('INSERT INTO post_publications (post_channel_id,source_id,external_message_id,start_payload)
    VALUES (?,?,?,?)')->execute([$bindingCurrent['channel']['id'], $bindingCurrent['source_id'], '65', $secondBindingToken]);
$secondBindingMessage = $bindingMessage;
$secondBindingMessage['message_id'] = 65;
$secondBindingMessage['caption'] = 'A different hotel from the small channel';
$secondBindingMessage['photo'][0]['file_unique_id'] = 'source_unique_358';
$secondBindingMessage['reply_markup']['inline_keyboard'][0][0]['url'] =
    $bindingCurrent['channel']['bot_url'].'?start='.$secondBindingToken;
$secondBindingSnapshot = ChannelPostIncomingAdapter::telegramSnapshot($secondBindingMessage);
$secondBindingSnapshot['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($secondBindingSnapshot);
$secondBinding = $bindingRegistry->registerPublication('anytour', 'telegram', 'anyru_bot',
    '-1002262666616', '65', $secondBindingSnapshot);
$bindingRegistry->confirmObservedButton($secondBinding['publication_id'], $secondBinding['version_id'],
    ['verified'=>true,'button_verified'=>true]);
$secondBindingRow = [
    'source_publication_id'=>$secondBinding['publication_id'],
    'source_version_id'=>$secondBinding['version_id'],
    'source_message_id'=>'65',
    'hotel_id'=>358,
    'source_content_hash'=>$secondBinding['content_hash'],
    'source_photo_sha256'=>hash('sha256', 'source_unique_358'),
    'source_button_sha256'=>hash('sha256', $secondBindingMessage['reply_markup']['inline_keyboard'][0][0]['url']),
];

$bindingBefore = $readBindingTables();
$bindingCheck = \AnyTour\ExistingPostBatch\restoreTelegramHotelSourceBindings($bindingDb, $bindingRegistry, [$bindingRow], $bindingDigest);
postCheck('source hotel binding dry run returns its exact version without writing',
    count($bindingCheck) === 1 && array_values($bindingCheck)[0]['state'] === 'ready'
    && array_values($bindingCheck)[0]['publication_id'] === $bindingRow['source_publication_id']
    && array_values($bindingCheck)[0]['version_id'] === $bindingVersion
    && array_values($bindingCheck)[0]['hotel_id'] === $bindingRow['hotel_id']
    && $readBindingTables() === $bindingBefore);
$wrongBindingRow = array_replace($secondBindingRow, ['source_content_hash'=>str_repeat('f', 64)]);
postCheck('source hotel binding refuses an invalid second row without restoring the first',
    manualPostThrows(fn()=>\AnyTour\ExistingPostBatch\restoreTelegramHotelSourceBindings($bindingDb, $bindingRegistry, 
        [$bindingRow, $wrongBindingRow], $bindingDigest, true), 'hotel_source_binding_changed')
    && $readBindingTables() === $bindingBefore);

$bindingDb->prepare("INSERT INTO post_versions
    (publication_id,version_number,content_hash,snapshot_json,state,write_attempts,created_at,updated_at)
    SELECT publication_id,version_number+1,content_hash,snapshot_json,'unknown',1,created_at,updated_at
    FROM post_versions WHERE id=?")->execute([$secondBindingRow['source_version_id']]);
$unknownBindingVersion = (int)$bindingDb->lastInsertId();
$heldBindingTables = $readBindingTables();
postCheck('source hotel binding preserves an unresolved provider operation',
    manualPostThrows(fn()=>\AnyTour\ExistingPostBatch\restoreTelegramHotelSourceBindings($bindingDb, $bindingRegistry, 
        [$bindingRow, $secondBindingRow], $bindingDigest, true), 'hotel_source_binding_held')
    && $readBindingTables() === $heldBindingTables);
$bindingDb->prepare('DELETE FROM post_versions WHERE id=?')->execute([$unknownBindingVersion]);

$bindingStored = array_values(\AnyTour\ExistingPostBatch\restoreTelegramHotelSourceBindings($bindingDb, $bindingRegistry, 
    [$bindingRow], $bindingDigest, true))[0];
$bindingAfter = $bindingRegistry->publication($bindingRow['source_publication_id']);
$bindingResolved = $bindingRegistry->resolveToken('anytour', 'telegram', 'anyru_bot', $bindingCurrent['start_payload']);
$bindingAfterTables = $readBindingTables();
$expectedBindingSnapshot = $bindingSnapshot;
$expectedBindingSnapshot['metadata']['hotel_catalog_id'] = $bindingRow['hotel_id'];
postCheck('source hotel binding writes only an immutable same-content sent successor and retains the token and clock',
    $bindingStored['state'] === 'restored' && $bindingStored['version_id'] === $bindingAfter['version_id']
    && $bindingAfter['version_id'] > $bindingVersion && $bindingAfter['state'] === 'sent'
    && $bindingAfter['content_hash'] === $bindingRow['source_content_hash']
    && $bindingAfter['snapshot']['metadata']['hotel_catalog_id'] === $bindingRow['hotel_id']
    && array_intersect_key($bindingAfter['snapshot'], array_flip(['text','post_url','published_at','attachments','keyboard']))
       === array_intersect_key($expectedBindingSnapshot, array_flip(['text','post_url','published_at','attachments','keyboard']))
    && $bindingAfter['start_payload'] === $bindingCurrent['start_payload']
    && $bindingResolved['version_id'] === $bindingAfter['version_id']
    && $bindingAfterTables[1][0] === $bindingBefore[1][0]
    && $bindingAfterTables[0][0] === array_replace($bindingBefore[0][0], ['current_version_id'=>$bindingAfter['version_id']])
    && $bindingRegistry->pendingPublications('anytour', 'telegram', 'anyru_bot') === []);
$bindingRepeat = array_values(\AnyTour\ExistingPostBatch\restoreTelegramHotelSourceBindings($bindingDb, $bindingRegistry, 
    [$bindingRow], $bindingDigest, true))[0];
postCheck('source hotel binding replay returns the restored version without a second observation or version',
    $bindingRepeat['state'] === 'already_restored' && $bindingRepeat['version_id'] === $bindingAfter['version_id']
    && $readBindingTables() === $bindingAfterTables);


$publicBindingUrl = 'https://t.me/any_toour/21';
$publicBindingButton = 'https://t.me/anyru_bot?start=p_'.str_repeat('a', 24);
$publicBindingLabel = '✈️ Узнать стоимость тура';
$publicBindingPhotoClass = 'tgme_widget_message_photo_wrap 123456';
$publicBindingDate = '2026-11-02T08:00:00+00:00';
$publicBindingPost = static function (array $buttons, string $caption = 'Exact source caption') use (
    $publicBindingUrl, $publicBindingPhotoClass, $publicBindingDate
): string {
    $html = '<!doctype html><html><head><meta charset="utf-8"></head><body>'
        .'<article data-post="any_toour/21"><div class="tgme_widget_message_text">'.$caption.'</div>'
        .'<a class="'.$publicBindingPhotoClass.'" href="'.$publicBindingUrl
        .'" style="background-image:url(https://cdn-telegram.org/photo.jpg)"></a>'
        .'<time datetime="'.$publicBindingDate.'"></time>';
    foreach ($buttons as [$label, $url]) {
        $html .= '<a class="tgme_widget_message_inline_button" href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">'
            .$label.'</a>';
    }
    return $html.'</article></body></html>';
};
$publicBindingRow = ['source_message_id'=>'21', 'source_public_unixtime'=>(new DateTimeImmutable($publicBindingDate))->getTimestamp(),
    'source_public_caption_sha256'=>hash('sha256', 'Exact source caption'),
    'source_public_photo_sha256'=>hash('sha256', $publicBindingPhotoClass),
    'source_button_sha256'=>hash('sha256', $publicBindingButton)];
$bindingButton = [[$publicBindingLabel, $publicBindingButton]];
$bindingEmbedUrl = $publicBindingUrl.'?embed=1&mode=tme';
$bindingArchiveUrl = 'https://t.me/s/any_toour?before=22';
$bindingPublicRead = static function (string $embed, string $archive) use ($bindingEmbedUrl, $bindingArchiveUrl): Closure {
    return static fn(string $url): string => match ($url) {
        $bindingEmbedUrl => $embed,
        $bindingArchiveUrl => $archive,
        default => throw new RuntimeException('unexpected_public_binding_request'),
    };
};
$publicBindingReadback = \AnyTour\ExistingPostBatch\observeTelegramSourceBindingPublic(
    $publicBindingRow, $bindingPublicRead($publicBindingPost([]), $publicBindingPost($bindingButton)));
postCheck('source binding verifies the exact legacy button in the archive even when the embed omits it',
    $publicBindingReadback['button_sha256'] === $publicBindingRow['source_button_sha256']
    && $publicBindingReadback['source_message_id'] === '21'
    && $publicBindingReadback['archive_sha256'] === hash('sha256', $publicBindingPost($bindingButton)));
postCheck('source binding refuses additional or changed public buttons',
    manualPostThrows(fn()=>\AnyTour\ExistingPostBatch\observeTelegramSourceBindingPublic($publicBindingRow,
        $bindingPublicRead($publicBindingPost([]), $publicBindingPost([...$bindingButton, ...$bindingButton]))),
        'hotel_source_binding_public_button_changed')
    && manualPostThrows(fn()=>\AnyTour\ExistingPostBatch\observeTelegramSourceBindingPublic($publicBindingRow,
        $bindingPublicRead($publicBindingPost([[$publicBindingLabel, $publicBindingButton.'x']]), $publicBindingPost($bindingButton))),
        'hotel_source_binding_public_button_changed'));
postCheck('source binding refuses a changed public preimage or unrelated MID',
    manualPostThrows(fn()=>\AnyTour\ExistingPostBatch\observeTelegramSourceBindingPublic($publicBindingRow,
        $bindingPublicRead($publicBindingPost([]), $publicBindingPost($bindingButton, 'Different caption'))),
        'hotel_source_binding_public_preimage_changed')
    && manualPostThrows(fn()=>\AnyTour\ExistingPostBatch\observeTelegramSourceBindingPublic(
        array_replace($publicBindingRow, ['source_message_id'=>'24']), fn()=>throw new RuntimeException('unexpected_public_binding_request')),
        'hotel_source_binding_public_scope_invalid'));

/** Real receive/registry/worker flow; the only provider is this in-memory fixture. */
function historicalPublisherKeyboardFixture(array $changes = []): array
{
    $scope = array_replace(['project'=>'anytour','bot'=>'anyru_bot','channel_id'=>'-1001845835645',
        'channel_record'=>6,'source_id'=>9,'publication_id'=>3847,'message_id'=>'1047'], $changes);
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    createChannelPostRegistryFixture($db);
    $db->prepare('INSERT INTO projects VALUES (1,?,?,1)')->execute([$scope['project'],'Synthetic publisher pilot']);
    $db->prepare('INSERT INTO conversation_sources VALUES (?,1,?,?,?,1,?)')
        ->execute([$scope['source_id'],'tg_anytour_msk','Synthetic channel','telegram','manager']);
    $registry = new ChannelPostRegistryService($db);
    $config = ['project_key'=>$scope['project'],'platform'=>'telegram','bot_key'=>$scope['bot'],
        'channel_id'=>$scope['channel_id'],'source_key'=>'tg_anytour_msk','display_name'=>'Synthetic channel',
        'bot_url'=>'https://t.me/'.$scope['bot'],'button_text'=>'✈️ Рассчитать на мои даты','is_enabled'=>true];
    $channel = $registry->configureChannel($config);
    $registry->configureChannel($config + ['expected_version'=>1]);
    $db->prepare('UPDATE post_channels SET id=? WHERE id=?')->execute([$scope['channel_record'],$channel['id']]);
    $insert = $db->prepare('INSERT INTO post_publications (id,post_channel_id,source_id,external_message_id,start_payload) VALUES (?,?,?,?,?)');
    $insert->execute([$scope['publication_id'],$scope['channel_record'],$scope['source_id'],$scope['message_id'],'p_'.str_repeat('A',24)]);
    // Other rejected reservations have no version either; observing this pilot
    // must not allocate their work or alter their existing identity/token.
    for ($i=0; $i<14; ++$i) $insert->execute([7000+$i,$scope['channel_record'],$scope['source_id'],(string)(9000+$i),'p_'.str_pad((string)$i,24,'B')]);
    $message = ['message_id'=>(int)$scope['message_id'],'chat'=>['id'=>(int)$scope['channel_id'],'type'=>'channel','username'=>'anytour'],
        'date'=>1741936304,'edit_date'=>1795640000,'caption'=>'Lusso Sorgun — synthetic unchanged old publication',
        'photo'=>[['file_id'=>'synthetic_old_photo','file_unique_id'=>'synthetic_old_unique','width'=>1000,'height'=>800]],
        'reply_markup'=>['inline_keyboard'=>[[['text'=>'Original first →','url'=>'https://example.test/original-first']],
            [['text'=>'Original second','url'=>'https://example.test/original-second']]]]];
    $effects = (object)['calls'=>[],'writes'=>0];
    $request = static function(string $platform,string $method,string $path,array $query,?array $body) use ($scope,$message,$effects): array {
        $effects->calls[] = $path;
        $bot = ['id'=>123456,'username'=>$scope['bot'],'is_bot'=>true];
        if ($path === 'getMe') $result = $bot;
        elseif ($path === 'getChatMember') $result = ['user'=>$bot,'status'=>'administrator','can_edit_messages'=>true];
        elseif ($path === 'getChat') $result = $message['chat'];
        elseif ($path === 'editMessageReplyMarkup') {
            ++$effects->writes;
            $result = array_replace($message, ['reply_markup'=>$body['reply_markup']]);
        } else throw new LogicException('unexpected synthetic provider call');
        return ['http'=>200,'transport_error'=>false,'data'=>['ok'=>true,'result'=>$result]];
    };
    $service = new ChannelPostAutomationService($registry, new ChannelPostButtonTransport($request));
    return [$db,$registry,$service,$message,$scope,$effects];
}

[$pilotDb,$pilotRegistry,$pilotService,$pilotMessage,$pilotScope,$pilotEffects] = historicalPublisherKeyboardFixture();
$pilotReservationBefore = $pilotDb->query('SELECT * FROM post_publications ORDER BY id')->fetchAll();
postCheck('publisher pilot fence leaves all fifteen identity-only reservations and tokens untouched before a native update',
    $pilotRegistry->publication(3847) === null && $pilotRegistry->pendingPublications('anytour','telegram','anyru_bot') === []
    && $pilotService->processPending('anytour','telegram','anyru_bot') === [] && $pilotEffects->calls === []
    && $pilotReservationBefore === $pilotDb->query('SELECT * FROM post_publications ORDER BY id')->fetchAll()
    && (int)$pilotDb->query('SELECT COUNT(*) FROM post_versions')->fetchColumn() === 0
    && (int)$pilotDb->query('SELECT COUNT(*) FROM post_observations')->fetchColumn() === 0);
$pilotUpdate = ['update_id'=>771001,'edited_channel_post'=>$pilotMessage];
$pilotReceived = $pilotService->receive('telegram',$pilotUpdate,'anytour','anyru_bot');
$pilotNative = $pilotRegistry->publication(3847);
postCheck('real publisher pilot native update is held without inventing Selectum hotel1501 acceptance or publisher outcome',
    $pilotReceived['queued'] === false && $pilotReceived['state'] === 'requires_attention'
    && $pilotNative['state'] === 'requires_attention' && $pilotNative['error_reason'] === 'publisher_keyboard_pilot_requires_review'
    && $pilotNative['receipt'] === ['repair_kind'=>'historical_publisher_keyboard_pilot']
    && $pilotNative['publisher_keyboard_pilot_hold'] === true && $pilotNative['manual_button_repair'] === true
    && !isset($pilotNative['publisher']) && !isset($pilotNative['snapshot']['metadata']['hotel_catalog_id'])
    && !isset($pilotNative['snapshot']['metadata']['hotel_transfer'])
    && $pilotNative['snapshot']['raw_body'] === $pilotMessage && $pilotNative['snapshot']['text'] === $pilotMessage['caption']
    && $pilotNative['start_payload'] === $pilotReservationBefore[0]['start_payload']
    && $pilotNative['write_attempts'] === 0 && $pilotNative['attempt_count'] === 0);
$pilotClock = $pilotDb->query('SELECT observed_revision_ms,telegram_sequence FROM post_publications WHERE id=3847')->fetch();
postCheck('pilot keeps real native clock and other fourteen reservations without creating automatic provider work',
    (int)$pilotClock['observed_revision_ms'] === $pilotMessage['edit_date'] * 1000 && (string)$pilotClock['telegram_sequence'] === '771001'
    && array_slice($pilotReservationBefore,1) === $pilotDb->query('SELECT * FROM post_publications WHERE id<>3847 ORDER BY id')->fetchAll()
    && $pilotService->processPending('anytour','telegram','anyru_bot') === [] && $pilotEffects->calls === []);
$pilotVersionBefore = $pilotDb->query('SELECT * FROM post_versions ORDER BY id')->fetchAll();
postCheck('pilot rejects ordinary claim, write start, completion and own-button confirmation without state changes',
    $pilotRegistry->claimPublication(3847,$pilotNative['version_id']) === null
    && !$pilotRegistry->markExternalWriteStarted(3847,$pilotNative['version_id'],str_repeat('c',32))
    && !$pilotRegistry->finishPublication(3847,$pilotNative['version_id'],str_repeat('c',32),'sent')
    && !$pilotRegistry->confirmObservedButton(3847,$pilotNative['version_id'],['verified'=>true])
    && $pilotVersionBefore === $pilotDb->query('SELECT * FROM post_versions ORDER BY id')->fetchAll());
$pilotDuplicate = $pilotService->receive('telegram',$pilotUpdate,'anytour','anyru_bot');
postCheck('duplicate pilot webhook cannot allocate another version or enqueue a fallback writer',
    $pilotDuplicate['queued'] === false && $pilotVersionBefore === $pilotDb->query('SELECT * FROM post_versions ORDER BY id')->fetchAll()
    && $pilotService->processPending('anytour','telegram','anyru_bot') === [] && $pilotEffects->writes === 0);

// Even a future uncertain or expired operation must not be reset or replayed.
$pilotDb->prepare("UPDATE post_versions SET state='unknown',write_attempts=1,attempt_count=1,receipt_json=? WHERE id=?")
    ->execute([json_encode(['fixture'=>'immutable unknown receipt','write_attempts'=>1]),$pilotNative['version_id']]);
$pilotUnknownBefore = $pilotDb->query('SELECT * FROM post_versions WHERE id='.$pilotNative['version_id'])->fetch();
postCheck('exact pilot identity excludes UNKNOWN from ordinary and reconciliation claims without modifying its receipt',
    $pilotRegistry->pendingPublications('anytour','telegram','anyru_bot') === []
    && $pilotRegistry->claimPublication(3847,$pilotNative['version_id'],true) === null
    && $pilotUnknownBefore === $pilotDb->query('SELECT * FROM post_versions WHERE id='.$pilotNative['version_id'])->fetch());
$pilotCanonical = $pilotMessage;
$pilotCanonical['edit_date']++;
$pilotCanonical['reply_markup'] = ['inline_keyboard'=>[[['text'=>'✈️ Рассчитать на мои даты',
    'url'=>'https://t.me/anyru_bot?start='.$pilotNative['start_payload']]]]];
$pilotLater = $pilotService->receive('telegram',['update_id'=>771002,'edited_channel_post'=>$pilotCanonical],'anytour','anyru_bot');
$pilotLatest = $pilotRegistry->publication(3847);
postCheck('later native canonical keyboard is new held evidence and never erases UNKNOWN or proves the hotel transfer',
    $pilotLater['queued'] === false && $pilotLater['state'] === 'requires_attention'
    && $pilotLatest['version_number'] === 2 && $pilotLatest['snapshot']['raw_body'] === $pilotCanonical
    && !isset($pilotLatest['snapshot']['metadata']['hotel_catalog_id'])
    && $pilotUnknownBefore === $pilotDb->query('SELECT * FROM post_versions WHERE id='.$pilotNative['version_id'])->fetch()
    && $pilotService->processPending('anytour','telegram','anyru_bot') === [] && $pilotEffects->calls === []);
$pilotDb->prepare("UPDATE post_versions SET state='claimed',claim_token=?,claimed_at='2000-01-01 00:00:00' WHERE id=?")
    ->execute([str_repeat('d',32),$pilotLatest['version_id']]);
$pilotClaimBefore = $pilotDb->query('SELECT * FROM post_versions WHERE id='.$pilotLatest['version_id'])->fetch();
postCheck('expired pilot lease cannot become writable pending or bypass the final write boundary',
    $pilotRegistry->pendingPublications('anytour','telegram','anyru_bot') === []
    && !$pilotRegistry->markExternalWriteStarted(3847,$pilotLatest['version_id'],str_repeat('d',32))
    && $pilotClaimBefore === $pilotDb->query('SELECT * FROM post_versions WHERE id='.$pilotLatest['version_id'])->fetch());

[$sourceOwnerDb,$sourceOwnerRegistry,$sourceOwnerService,$sourceOwnerMessage] = historicalPublisherKeyboardFixture(['source_id'=>10]);
$sourceOwnerReceived = $sourceOwnerService->receive('telegram',['update_id'=>771050,'edited_channel_post'=>$sourceOwnerMessage],'anytour','anyru_bot');
postCheck('pilot fence does not rely on an independently unverified source record number',
    $sourceOwnerReceived['queued'] === false && $sourceOwnerReceived['state'] === 'requires_attention'
    && $sourceOwnerRegistry->pendingPublications('anytour','telegram','anyru_bot') === []);

foreach (['publication_id'=>3848,'channel_record'=>7,'message_id'=>'1048',
    'project'=>'anex','bot'=>'another_bot','channel_id'=>'-1001845835646'] as $field=>$value) {
    [$nearDb,$nearRegistry,$nearService,$nearMessage,$nearScope,$nearEffects] = historicalPublisherKeyboardFixture([$field=>$value]);
    $nearReceived = $nearService->receive('telegram',['update_id'=>771101,'edited_channel_post'=>$nearMessage],$nearScope['project'],$nearScope['bot']);
    $near = $nearRegistry->publication($nearScope['publication_id']);
    postCheck('publisher fence does not capture neighboring identity field '.$field,
        $nearReceived['queued'] === true && $near['state'] === 'pending' && empty($near['manual_button_repair'])
        && count($nearRegistry->pendingPublications($nearScope['project'],'telegram',$nearScope['bot'])) === 1
        && $nearRegistry->claimPublication($nearScope['publication_id'],$near['version_id']) !== null);
}

// The existing approved-packet API supplies a synthetic source lineage. These
// are database/provider fixtures, never evidence of a real publisher operation.
function publisherMediaRegistryFixture(bool $advanceSource = true): array
{
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    createChannelPostRegistryFixture($db);
    $db->exec("INSERT INTO projects VALUES (1,'anytour','Synthetic media pilot',1);
        INSERT INTO conversation_sources VALUES (9,1,'tg_anytour_msk','Large','telegram',1,'manager'),(10,1,'tg_hotel_source','Small','telegram',1,'manager');");
    $registry = new ChannelPostRegistryService($db);
    $config = ['project_key'=>'anytour','platform'=>'telegram','bot_key'=>'anyru_bot','channel_id'=>'-1001845835645',
        'source_key'=>'tg_anytour_msk','display_name'=>'Synthetic target','bot_url'=>'https://t.me/anyru_bot',
        'button_text'=>'✈️ Рассчитать на мои даты','is_enabled'=>true];
    $targetChannel = $registry->configureChannel($config);
    $registry->configureChannel($config + ['expected_version'=>1]);
    $db->prepare('UPDATE post_channels SET id=6 WHERE id=?')->execute([$targetChannel['id']]);
    $sourceChannel = $registry->configureChannel(array_replace($config,
        ['channel_id'=>'-1002262666616','source_key'=>'tg_hotel_source','display_name'=>'Synthetic source']));
    $db->prepare('UPDATE post_channels SET id=10 WHERE id=?')->execute([$sourceChannel['id']]);
    $db->exec("INSERT INTO post_publications (id,post_channel_id,source_id,external_message_id,start_payload) VALUES
        (952,10,10,'429','p_ABCDEFGHIJKLMNOPQRSTUVWX')");
    $sourceMessage = ['chat'=>['id'=>-1002262666616,'type'=>'channel','username'=>'any_toour'], 'message_id'=>429,
        'date'=>1794081258,'caption'=>'Synthetic source before PRICE',
        'photo'=>[['file_id'=>'synthetic_source_anyru_file','file_unique_id'=>'synthetic_source_unique','width'=>600,'height'=>600]],
        'reply_markup'=>['inline_keyboard'=>[[['text'=>$config['button_text'],
            'url'=>'https://t.me/anyru_bot?start=p_ABCDEFGHIJKLMNOPQRSTUVWX']]]]];
    $saveSource = static function(array $message, int $sequence) use ($registry): array {
        $event = ChannelPostIncomingAdapter::fromUpdate('telegram', ['update_id'=>$sequence,'edited_channel_post'=>$message]);
        $snapshot = $event['snapshot']; $snapshot['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($snapshot);
        $snapshot['metadata']['hotel_catalog_id'] = 2514;
        $source = $registry->registerPublication('anytour','telegram','anyru_bot','-1002262666616','429',$snapshot);
        $registry->confirmObservedButton(952,$source['version_id'],['verified'=>true,'button_verified'=>true]);
        return $registry->publication(952);
    };
    $sourceMessage['edit_date'] = 1795670000;
    $initialSource = $saveSource($sourceMessage, 661001);
    $approved = json_decode(file_get_contents(dirname(__DIR__).'/config/anytour_ranked_pairs_45552458333.json'), true, 64, JSON_THROW_ON_ERROR);
    $original = array_values(array_filter($approved['rows'], static fn(array $row): bool => $row['hotel_id'] === 2514))[0];
    $row = array_intersect_key($original, array_fill_keys(AnyTourRankedHotelTransfer46::REGISTRY_FIELDS, true));
    $row = array_replace($row, ['source_version_id'=>$initialSource['version_id'],'source_content_hash'=>$initialSource['content_hash'],
        'source_caption_sha256'=>hash('sha256',$sourceMessage['caption']),
        'source_file_id_sha256'=>hash('sha256',$sourceMessage['photo'][0]['file_id']),
        'source_file_unique_id_sha256'=>hash('sha256',$sourceMessage['photo'][0]['file_unique_id'])]);
    $planBytes = json_encode(['schema'=>1,'operation'=>'synthetic_publisher_media_parent','native_preflight'=>['fixture'=>true],
        'rows'=>[$row]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $digest = hash('sha256', $planBytes);
    if (AnyTourRankedHotelTransfer46::profile($digest) === null) {
        $indexBytes = json_encode(['schema'=>1,'native_preflight'=>['fixture'=>true], 'plans'=>[[
            'path'=>'config/anytour_publisher_media_registry_fixture.json','sha256'=>$digest,'bytes'=>strlen($planBytes),
            'git_blob'=>sha1('blob '.strlen($planBytes)."\0".$planBytes)]]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        AnyTourRankedHotelTransfer46::acceptPacket($indexBytes,hash('sha256',$indexBytes),$planBytes,$digest);
    }
    $db->exec("UPDATE sqlite_sequence SET seq=3847 WHERE name='post_publications'");
    $reservation = $registry->reserveTelegramHotelTransfer($row,$digest,static fn(): bool => true);
    if ($advanceSource) {
        $sourceMessage['caption'] = 'Synthetic source after PRICE; same hotel and photo';
        $sourceMessage['edit_date']++;
        $sourceMessage['photo'][0]['file_id'] = 'synthetic_new_anyru_file_id';
        $saveSource($sourceMessage, 661002);
    }
    $source = $registry->publication(952);
    $photo = $source['snapshot']['attachments'][0];
    $pin = ['version_id'=>$source['version_id'],'content_hash'=>$source['content_hash'],
        'caption_sha256'=>hash('sha256',$source['snapshot']['text']),
        'file_id_sha256'=>hash('sha256',$photo['telegram_file_id']),
        'file_unique_id_sha256'=>hash('sha256',$photo['file_unique_id'])];
    // A newly uploaded image may have a new unique ID. Its file_id in this
    // response belongs to ANYTOURBOT, while the webhook below belongs to anyru.
    $response = ['http'=>200,'transport_error'=>false,'data'=>['ok'=>true,'result'=>[
        'chat'=>['id'=>-1001845835645,'type'=>'channel','username'=>'anytour'], 'message_id'=>1058,
        'date'=>1742976715,'edit_date'=>1795700000,'caption'=>$row['next_caption'],
        'photo'=>[['file_id'=>'synthetic_ANYTOURBOT_only_file','file_unique_id'=>'synthetic_uploaded_unique','width'=>600,'height'=>600]],
        'reply_markup'=>['inline_keyboard'=>[[['text'=>$row['button_text'],'url'=>$reservation['button_url']]]]]]]];
    $upload = '3364da0d8257468c9f2933d042dfc7b5d5b7ca705d2ca1564b328966257bbe58';
    $request = ['chat_id'=>'-1001845835645','message_id'=>'1058',
        'media'=>['type'=>'photo','media'=>'attach://hotel_photo','caption'=>$row['next_caption'],'caption_entities'=>[]],
        'reply_markup'=>$response['data']['result']['reply_markup'],'upload_sha256'=>$upload];
    $evidence = ['schema'=>1,'publisher'=>['username'=>'ANYTOURBOT_bot','bot_id'=>'777000111','credential_version'=>4],
        'native_run_id'=>'88888888888',
        'parent_receipt_sha256'=>'b30bddac7fb9ddcb40e2f41c44bec8988e7fc0e98ddb3a0ca9f825e38492aeb0',
        'upload_sha256'=>$upload,'request_sha256'=>hash('sha256',json_encode($request,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))];
    return [$db,$registry,$row,$digest,$reservation,$pin,$response,$evidence,$saveSource,$sourceMessage];
}
function publisherMediaWebhook(ChannelPostRegistryService $registry, array $response): array
{
    $native = $response;
    $native['data']['result']['photo'][0]['file_id'] = 'synthetic_anyru_webhook_file';
    return hotelTransferObserve($registry,$native,false,662001);
}
[$mediaDb,$mediaRegistry,$mediaRow,$mediaPlan,$mediaReservation,$mediaPin,$mediaResponse,$mediaEvidence,$mediaSaveSource,$mediaSourceMessage] = publisherMediaRegistryFixture();
$mediaBefore = $mediaDb->query('SELECT * FROM post_publications WHERE id=3848')->fetch();
postCheck('publisher media admits current PRICE source revision while preserving original reservation and parent row',
    $mediaPin['version_id'] > $mediaRow['source_version_id']
    && $mediaRegistry->assertPublisherMediaPilotReservation($mediaReservation,$mediaRow,$mediaPlan,$mediaPin) === $mediaReservation
    && $mediaBefore === $mediaDb->query('SELECT * FROM post_publications WHERE id=3848')->fetch()
    && (int)$mediaDb->query('SELECT COUNT(*) FROM post_versions WHERE publication_id=3848')->fetchColumn() === 0);
postCheck('publisher media never invents a canonical observation from its alternative bot response',
    manualPostThrows(fn()=>$mediaRegistry->completePublisherMediaPilot($mediaReservation,$mediaRow,$mediaPlan,$mediaPin,$mediaResponse,$mediaEvidence),
        'publisher_media_native_observation_required') && $mediaBefore === $mediaDb->query('SELECT * FROM post_publications WHERE id=3848')->fetch());
foreach (['upload_sha256','parent_receipt_sha256','request_sha256'] as $field) {
    $bad = $mediaEvidence; $bad[$field] = str_repeat('f',64);
    postCheck('publisher media rejects altered proof '.$field,
        manualPostThrows(fn()=>$mediaRegistry->completePublisherMediaPilot($mediaReservation,$mediaRow,$mediaPlan,$mediaPin,$mediaResponse,$bad),'publisher_media_evidence_invalid'));
}
foreach (['username'=>'other_bot','bot_id'=>'invalid','credential_version'=>0] as $field=>$value) {
    $bad = $mediaEvidence; $bad['publisher'][$field] = $value;
    postCheck('publisher media validates publisher evidence '.$field,
        manualPostThrows(fn()=>$mediaRegistry->completePublisherMediaPilot($mediaReservation,$mediaRow,$mediaPlan,$mediaPin,$mediaResponse,$bad),'publisher_media_evidence_invalid'));
}
foreach (['message_id'=>1059,'date'=>1742976716,'caption'=>'Changed caption','media_group_id'=>'unexpected-album'] as $field=>$value) {
    $bad = $mediaResponse; $bad['data']['result'][$field] = $value;
    postCheck('publisher media response rejects changed '.$field,
        manualPostThrows(fn()=>$mediaRegistry->completePublisherMediaPilot($mediaReservation,$mediaRow,$mediaPlan,$mediaPin,$bad,$mediaEvidence),'publisher_media_response_unconfirmed'));
}
$wrongSourcePin = $mediaPin; $wrongSourcePin['file_unique_id_sha256'] = hash('sha256','another-photo');
postCheck('publisher media refuses source photo changes before write admission',
    manualPostThrows(fn()=>$mediaRegistry->assertPublisherMediaPilotReservation($mediaReservation,$mediaRow,$mediaPlan,$wrongSourcePin),'publisher_media_source_pin_invalid'));
$wrongSourcePin = $mediaPin; $wrongSourcePin['version_id']++;
postCheck('publisher media source revision CAS is checked in the database',
    manualPostThrows(fn()=>$mediaRegistry->assertPublisherMediaPilotReservation($mediaReservation,$mediaRow,$mediaPlan,$wrongSourcePin),'hotel_transfer_source_changed'));
$wrongReservation = $mediaReservation; $wrongReservation['publication_id'] = 3849;
postCheck('publisher media cannot redirect its existing reservation to a neighboring identity',
    manualPostThrows(fn()=>$mediaRegistry->assertPublisherMediaPilotReservation($wrongReservation,$mediaRow,$mediaPlan,$mediaPin),'publisher_media_reservation_changed'));
$mediaNative = publisherMediaWebhook($mediaRegistry,$mediaResponse);
$nativeSnapshot = $mediaNative['snapshot'];
$nativeClock = $mediaDb->query('SELECT observed_revision_ms,telegram_sequence,start_payload FROM post_publications WHERE id=3848')->fetch();
postCheck('publisher media recheck after a native observation cannot authorize another provider attempt',
    manualPostThrows(fn()=>$mediaRegistry->assertPublisherMediaPilotReservation($mediaReservation,$mediaRow,$mediaPlan,$mediaPin),'publisher_media_reservation_changed'));
$mediaComplete = $mediaRegistry->completePublisherMediaPilot($mediaReservation,$mediaRow,$mediaPlan,$mediaPin,$mediaResponse,$mediaEvidence);
postCheck('publisher media adopts matching genuine anyru webhook and keeps the bot scoped file ID',
    $mediaComplete['state'] === 'sent' && $mediaComplete['already_completed'] === false
    && $mediaComplete['snapshot']['attachments'] === $nativeSnapshot['attachments']
    && $mediaComplete['snapshot']['attachments'][0]['telegram_file_id'] === 'synthetic_anyru_webhook_file'
    && $mediaComplete['snapshot']['raw_body'] === $nativeSnapshot['raw_body']
    && $mediaComplete['snapshot']['metadata']['source_event_id'] === $nativeSnapshot['metadata']['source_event_id']
    && $mediaComplete['snapshot']['metadata']['hotel_catalog_id'] === 2514
    && $mediaComplete['receipt']['file_id_bot_key'] === 'anyru_bot'
    && $mediaComplete['receipt']['publisher_evidence'] === $mediaEvidence
    && $mediaComplete['start_payload'] === $mediaReservation['start_payload']
    && $mediaNative['version_id'] === $mediaComplete['receipt']['adopted_from_version_id']
    && $nativeClock === $mediaDb->query('SELECT observed_revision_ms,telegram_sequence,start_payload FROM post_publications WHERE id=3848')->fetch());
$allAfter = $mediaDb->query('SELECT * FROM post_versions WHERE publication_id=3848 ORDER BY id')->fetchAll();
$mediaSourceMessage['caption'] = 'Later PRICE update after completed publisher operation'; $mediaSourceMessage['edit_date']++;
$mediaSaveSource($mediaSourceMessage,661003);
$mediaAgain = $mediaRegistry->completePublisherMediaPilot($mediaReservation,$mediaRow,$mediaPlan,$mediaPin,$mediaResponse,$mediaEvidence);
postCheck('publisher media completion is read only when already accepted even after source PRICE advances',
    $mediaAgain['already_completed'] === true && $mediaAgain['version_id'] === $mediaComplete['version_id']
    && $allAfter === $mediaDb->query('SELECT * FROM post_versions WHERE publication_id=3848 ORDER BY id')->fetchAll());
postCheck('publisher media accepted hotel context resolves through the original anyru token',
    $mediaRegistry->resolveToken('anytour','telegram','anyru_bot',$mediaReservation['start_payload'])['snapshot']['metadata']['hotel_catalog_id'] === 2514);

foreach (['missing_event','missing_sequence','changed_photo','changed_caption','unknown','claimed','source_advanced'] as $case) {
    [$db,$service,$row,$plan,$reservation,$pin,$response,$evidence,$save,$sourceMessage] = publisherMediaRegistryFixture();
    if ($case === 'missing_event') {
        $message = $response['data']['result']; $message['photo'][0]['file_id'] = 'synthetic_anyru_webhook_file';
        $snapshot = ChannelPostIncomingAdapter::telegramSnapshot($message);
        $snapshot['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($snapshot);
        $native = $service->registerPublication('anytour','telegram','anyru_bot','-1001845835645','1058',$snapshot);
    } else $native = publisherMediaWebhook($service,$response);
    if ($case === 'missing_sequence') {
        $snapshot = $native['snapshot']; unset($snapshot['metadata']['source_sequence_scope']);
        $db->prepare('UPDATE post_versions SET snapshot_json=? WHERE id=?')->execute([json_encode($snapshot),$native['version_id']]);
    } elseif ($case === 'changed_photo' || $case === 'changed_caption') {
        $changed = $response;
        if ($case === 'changed_photo') $changed['data']['result']['photo'][0]['file_unique_id'] = 'another_actual_photo';
        else $changed['data']['result']['caption'] = 'Different hotel or caption';
        hotelTransferObserve($service,$changed,false,662002);
    } elseif ($case === 'unknown') {
        $db->prepare("UPDATE post_versions SET state='unknown',write_attempts=1,receipt_json=? WHERE id=?")
            ->execute([json_encode(['immutable_unknown'=>true]),$native['version_id']]);
    } elseif ($case === 'claimed') {
        $db->prepare("UPDATE post_versions SET state='claimed',claim_token=?,claimed_at=? WHERE id=?")
            ->execute([str_repeat('d',32),gmdate('Y-m-d H:i:s'),$native['version_id']]);
    } elseif ($case === 'source_advanced') {
        $sourceMessage['caption'] = 'PRICE advanced before completion'; $sourceMessage['edit_date']++;
        $save($sourceMessage,661003);
    }
    $before = $db->query('SELECT * FROM post_versions WHERE publication_id=3848 ORDER BY id')->fetchAll();
    $identity = $db->query('SELECT * FROM post_publications WHERE id=3848')->fetch();
    postCheck('publisher media leaves unconfirmed or held state untouched: '.$case,
        manualPostThrows(fn()=>$service->completePublisherMediaPilot($reservation,$row,$plan,$pin,$response,$evidence))
        && $before === $db->query('SELECT * FROM post_versions WHERE publication_id=3848 ORDER BY id')->fetchAll()
        && $identity === $db->query('SELECT * FROM post_publications WHERE id=3848')->fetch());
}

foreach ([['hotel_catalog_id'=>2514], ['hotel_catalog_id'=>'2514'],
    ['logical_id'=>'commercial-hotel-2514-v1'], ['logical_id'=>'commercial-main-hotel-2514-v1'],
    ['logical_id'=>'commercial-amwaj-rotana-2514-v1']] as $index=>$duplicateIdentity) {
    [$db,$service,$row,$plan,$reservation,$pin,$response,$evidence] = publisherMediaRegistryFixture();
    $otherMessage = $response['data']['result']; $otherMessage['message_id'] = 1060;
    $otherEvent = ChannelPostIncomingAdapter::fromUpdate('telegram', ['update_id'=>663001,'edited_channel_post'=>$otherMessage]);
    $otherSnapshot = $otherEvent['snapshot'];
    $otherSnapshot['metadata'] = array_replace($otherSnapshot['metadata'],$duplicateIdentity);
    $otherSnapshot['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($otherSnapshot);
    $service->registerPublication('anytour','telegram','anyru_bot','-1001845835645','1060',$otherSnapshot);
    $before = $db->query('SELECT * FROM post_publications ORDER BY id')->fetchAll();
    $refusedBeforeWrite = manualPostThrows(fn()=>$service->assertPublisherMediaPilotReservation($reservation,$row,$plan,$pin),
        'publisher_media_hotel_already_in_channel');
    $beforeUnchanged = $before === $db->query('SELECT * FROM post_publications ORDER BY id')->fetchAll();
    publisherMediaWebhook($service,$response);
    $before = $db->query('SELECT * FROM post_versions ORDER BY id')->fetchAll();
    postCheck('publisher media freshly rejects another large-channel hotel identity '.$index,
        $refusedBeforeWrite && $beforeUnchanged
        && manualPostThrows(fn()=>$service->completePublisherMediaPilot($reservation,$row,$plan,$pin,$response,$evidence),
            'publisher_media_hotel_already_in_channel')
        && $before === $db->query('SELECT * FROM post_versions ORDER BY id')->fetchAll());
}
$mediaCurrentSource = $mediaRegistry->publication(952);
$mediaDb->prepare("UPDATE post_versions SET state='claimed',claim_token=?,claimed_at=? WHERE id=?")
    ->execute([str_repeat('e',32),gmdate('Y-m-d H:i:s'),$mediaCurrentSource['version_id']]);
$claimedSourceBefore = $mediaDb->query('SELECT * FROM post_versions WHERE publication_id=952 ORDER BY id')->fetchAll();
postCheck('publisher media accepted receipt stays idempotent without clearing a later source claim',
    $mediaRegistry->completePublisherMediaPilot($mediaReservation,$mediaRow,$mediaPlan,$mediaPin,$mediaResponse,$mediaEvidence)['already_completed'] === true
    && $claimedSourceBefore === $mediaDb->query('SELECT * FROM post_versions WHERE publication_id=952 ORDER BY id')->fetchAll()
    && $allAfter === $mediaDb->query('SELECT * FROM post_versions WHERE publication_id=3848 ORDER BY id')->fetchAll());

echo 'OK ' . $passed . " channel post registry checks\n";

