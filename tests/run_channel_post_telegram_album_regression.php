<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/services/ChannelPostRegistryService.php';
require_once __DIR__ . '/fixtures/channel_post_registry_fixture.php';

$albumPassed = 0;
function telegramAlbumCheck(string $name, bool $ok): void {
    global $albumPassed;
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    $albumPassed++;
    echo 'PASS ' . $name . "\n";
}
function telegramAlbumSnapshot(string $group, string $mid, int $sequence, string $caption = '', int $revision = 1791972294000): array {
    return [
        'text' => $caption, 'format' => 'native', 'markup' => [], 'keyboard' => [],
        'attachments' => [['type' => 'image', 'provider' => 'telegram', 'telegram_file_id' => 'file-' . $mid, 'file_unique_id' => 'unique-' . $mid]],
        'post_url' => 'https://t.me/anytour_test/' . $mid, 'published_at' => '2026-10-14 10:00:00',
        'metadata' => ['capability' => 'supported', 'capability_reason' => '', 'media_group_id' => $group,
            'actual_message_id' => $mid, 'source_event_id' => 'album-' . $group . '-' . $mid . '-' . $sequence,
            'source_revision_ms' => (string)$revision, 'source_sequence' => (string)$sequence, 'source_sequence_scope' => 'telegram_update_id'],
        'raw_body' => ['message_id' => $mid, 'media_group_id' => $group],
    ];
}
$albumPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
createChannelPostRegistryFixture($albumPdo);
$albumPdo->exec("INSERT INTO projects VALUES(1,'anytour','AnyTour',1);
INSERT INTO conversation_sources VALUES(12,1,'tg_anytour_msk','ТГ Москва','telegram',1,'manager');");
$albumRegistry = new ChannelPostRegistryService($albumPdo);
$albumRegistry->configureChannel(['project_key' => 'anytour', 'platform' => 'telegram', 'bot_key' => 'anyru_bot',
    'channel_id' => '-1001234567890', 'source_key' => 'tg_anytour_msk', 'display_name' => 'Album fixture',
    'bot_url' => 'https://t.me/anyru_bot', 'is_enabled' => true]);
$albumRegister = static function (array $snapshot) use ($albumRegistry): array {
    return $albumRegistry->registerPublication('anytour', 'telegram', 'anyru_bot', '-1001234567890', 'group:' . $snapshot['metadata']['media_group_id'], $snapshot);
};

$first = $albumRegister(telegramAlbumSnapshot('full', '61', 100));
$members = $albumRegistry->memberDelivery();
$claim = $members->claim($first['publication_id']);
telegramAlbumCheck('first album observation creates one opaque publication', $first['external_message_id'] === 'group:full' && preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/', $first['start_payload']) === 1);
telegramAlbumCheck('anchor member claims its numeric identity and starts only once', $claim !== null && $claim['external_message_id'] === '61' && $members->markStarted($claim) && !$members->markStarted($claim));
$second = $albumRegister(telegramAlbumSnapshot('full', '62', 101));
telegramAlbumCheck('photo arriving during the anchor write retains both member rows', count($members->rows($first['publication_id'])) === 2 && count($second['snapshot']['attachments']) === 2);
$finished = $members->finish($claim, 'sent', 'verified', ['verified' => true, 'button_verified' => true, 'content_preserved' => true, 'write_attempts' => 1]);
$latest = $albumRegistry->publication($first['publication_id']);
telegramAlbumCheck('verified anchor response survives another member arrival', $finished && $latest['state'] === 'sent' && $latest['version_id'] === $second['version_id']);
telegramAlbumCheck('a completed member receipt cannot be applied twice', !$members->finish($claim, 'unknown', 'waiting', []));

$caption = $albumRegister(telegramAlbumSnapshot('full', '60', 99, 'Полный альбом — 10 фотографий'));
telegramAlbumCheck('an older-sequence different member is merged and supplies the caption without moving the button', empty($caption['stale_observation']) && $caption['snapshot']['text'] === 'Полный альбом — 10 фотографий' && $caption['snapshot']['metadata']['actual_message_id'] === '61' && $caption['state'] === 'sent');
telegramAlbumCheck('target snapshot is the actual numeric anchor and keeps its own image', ChannelPostTelegramAlbum::target($caption['snapshot'])['metadata']['actual_message_id'] === '61' && ChannelPostTelegramAlbum::target($caption['snapshot'])['attachments'][0]['file_unique_id'] === 'unique-61');
foreach (range(63, 69) as $mid) $caption = $albumRegister(telegramAlbumSnapshot('full', (string)$mid, 100 + $mid));
telegramAlbumCheck('ten photos share one token and one target with no six-photo cutoff', count($caption['snapshot']['attachments']) === 10 && count($caption['snapshot']['metadata']['telegram_album_items']) === 10 && $caption['start_payload'] === $first['start_payload'] && $caption['snapshot']['metadata']['actual_message_id'] === '61' && $caption['state'] === 'sent');
telegramAlbumCheck('aggregate media order is numeric message order despite out-of-order delivery', array_column($caption['snapshot']['attachments'], 'file_unique_id') === array_map(static fn($mid) => 'unique-' . $mid, range(60, 69)));
telegramAlbumCheck('earlier immutable entry version retains only media observed at that time', count($albumRegistry->versionForPublication($first['publication_id'], $first['version_id'])['snapshot']['attachments']) === 1);
telegramAlbumCheck('completed album has no pending write operations', $albumRegistry->pendingPublications('anytour', 'telegram', 'anyru_bot') === []);
$duplicate = $albumRegister(telegramAlbumSnapshot('full', '69', 169));
telegramAlbumCheck('exact webhook redelivery keeps the current immutable version', !empty($duplicate['duplicate_event']) && $duplicate['version_id'] === $caption['version_id']);
$stale = $albumRegister(telegramAlbumSnapshot('full', '60', 98, 'Устаревшая подпись'));
telegramAlbumCheck('per-member ordering rejects an older caption event', !empty($stale['stale_observation']) && $stale['snapshot']['text'] === 'Полный альбом — 10 фотографий');
$newCaption = $albumRegister(telegramAlbumSnapshot('full', '60', 200, 'Новая подпись', 1791972295000));
telegramAlbumCheck('newer caption update appends immutable context and retains the already verified target', $newCaption['snapshot']['text'] === 'Новая подпись' && $newCaption['state'] === 'sent' && $newCaption['snapshot']['metadata']['actual_message_id'] === '61');
$lateOther = $albumRegister(telegramAlbumSnapshot('full', '63', 164));
telegramAlbumCheck('group-wide newer caption clock does not discard an eligible older member update', empty($lateOther['stale_observation']) && $lateOther['snapshot']['text'] === 'Новая подпись');

$beforeChange = $albumRegistry->publication($first['publication_id']);
$anchorChanged = $albumRegister(telegramAlbumSnapshot('full', '61', 400, 'Новая подпись якоря', 1791972296000));
telegramAlbumCheck('a fresh authenticated anchor edit remains a distinct observation', $anchorChanged['snapshot']['metadata']['actual_message_id'] === '61' && !ChannelPostTelegramAlbum::sameTargetObservation($beforeChange['snapshot'], $anchorChanged['snapshot']));
$legacy = telegramAlbumSnapshot('legacy', '501', 500, 'Ранее неподдерживаемый альбом');
$legacy['metadata']['capability'] = 'requires_attention'; $legacy['metadata']['capability_reason'] = 'album_not_supported';
$mergedLegacy = ChannelPostTelegramAlbum::merge($legacy, telegramAlbumSnapshot('legacy', '502', 501));
telegramAlbumCheck('historical album-only rejection can join the supported photo path', $mergedLegacy['snapshot']['metadata']['capability'] === 'supported' && count($mergedLegacy['snapshot']['attachments']) === 2);
$large = ChannelPostTelegramAlbum::merge(null, telegramAlbumSnapshot('large', '10000000000000000000', 1));
$large = ChannelPostTelegramAlbum::merge($large['snapshot'], telegramAlbumSnapshot('large', '9999999999999999999', 2));
telegramAlbumCheck('numeric message ordering preserves identifiers beyond PHP integer precision', array_keys($large['snapshot']['metadata']['telegram_album_items']) === ['m_9999999999999999999', 'm_10000000000000000000']);
telegramAlbumCheck('member storage avoids recursive snapshots and duplicated raw payloads', !isset(ChannelPostTelegramAlbum::target($caption['snapshot'])['metadata']['telegram_album_items']) && !isset(ChannelPostTelegramAlbum::target($caption['snapshot'])['raw_body']));
$legacyVideo = $legacy;
$legacyVideo['attachments'] = [['type' => 'video', 'provider' => 'telegram']];
$legacyVideoResult = ChannelPostTelegramAlbum::merge($legacyVideo, telegramAlbumSnapshot('legacy', '502', 501));
telegramAlbumCheck('legacy album guard never grants unsupported video media capabilities', $legacyVideoResult['snapshot']['metadata']['capability'] === 'requires_attention');
$unsupported = telegramAlbumSnapshot('full', '69', 900, '', 1791972299000);
$unsupported['attachments'] = [['type' => 'video', 'provider' => 'telegram']];
$unsupported['metadata']['capability'] = 'requires_attention';
$unsupported['metadata']['capability_reason'] = 'unsupported_media';
$unsupportedStored = $albumRegister($unsupported);
telegramAlbumCheck('unsupported non-anchor observation cannot carry a misleading sent state', $unsupportedStored['state'] === 'requires_attention' && $unsupportedStored['error_reason'] === 'unsupported_media');
$foreign = telegramAlbumSnapshot('foreign', '602', 602);
$foreign['keyboard'] = [[['text' => 'Чужие комментарии', 'callback_data' => 'foreign-comment']]];
$foreignMerged = ChannelPostTelegramAlbum::merge(telegramAlbumSnapshot('foreign', '601', 601), $foreign);
telegramAlbumCheck('foreign markup on another member remains eligible for durable cleanup', $foreignMerged['snapshot']['metadata']['capability'] === 'supported' && $foreignMerged['snapshot']['metadata']['telegram_album_items']['m_602']['keyboard'] === $foreign['keyboard']);
echo 'OK ' . $albumPassed . " Telegram album checks\n";
