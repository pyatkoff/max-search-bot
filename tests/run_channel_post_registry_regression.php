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
echo 'OK ' . $passed . " channel post registry checks\n";

