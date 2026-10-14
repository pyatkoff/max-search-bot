<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/services/ChannelPostRegistryService.php';
require_once dirname(__DIR__) . '/integrations/ChannelPostIncomingAdapter.php';
require_once __DIR__ . '/fixtures/channel_post_registry_fixture.php';

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

$sql = (string)file_get_contents(dirname(__DIR__) . '/migrations/033_channel_post_context.sql');
postCheck('production schema preserves binary token equality and persistent identity uniqueness', str_contains($sql, 'uq_post_publications_identity') && str_contains($sql, 'uq_post_publications_token') && str_contains($sql, 'uq_post_entries_event') && substr_count($sql, 'COLLATE ascii_bin') >= 10 && !preg_match('/^\s*(?:INSERT|UPDATE|DELETE)\b/m', preg_replace('/^--.*$/m', '', $sql)));
echo 'OK ' . $passed . " channel post registry checks\n";
