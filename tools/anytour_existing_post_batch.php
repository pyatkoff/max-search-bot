<?php
declare(strict_types=1);

namespace AnyTour\ExistingPostBatch;

use AnyTourRankedHotelTransfer46;
use ChannelPostApiTransport;
use ChannelPostButtonTransport;
use ChannelPostEntryService;
use ChannelPostIncomingAdapter;
use ChannelPostRegistryService;
use ConversationDb;
use DOMDocument;
use DOMXPath;
use DateTimeImmutable;
use PDO;
use ProjectConfig;
use PromotedHotelPriceWriter;
use RuntimeException;
use Throwable;
require_once __DIR__ . '/../config/anytour_ranked_transfer_scope_20261123.php';

const POST_OPS = '/var/www/anytoour/data/channel-post-operations';
const EDITORIAL_PRICE_SOURCE = 'https://anytoour.ru/data/hotel-monitor-prices-read-v1.php?tourvisorHotelIds%5B%5D=17595&tourvisorHotelIds%5B%5D=358&tourvisorHotelIds%5B%5D=393&tourvisorHotelIds%5B%5D=1260&departureId=1&dateFrom=2026-11-25&dateTo=2027-10-31';

function requirePost(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function publicHotelPost(array $row): array
{
    if ($row['platform'] === 'max') {
        return ['text' => PromotedHotelPriceWriter::maxPublicCaption(PromotedHotelPriceWriter::publicBytes($row['url']))];
    }
    $raw = PromotedHotelPriceWriter::publicBytes('https://t.me/s/anytour/'.$row['mid']);
    $dom = new DOMDocument();
    requirePost(@$dom->loadHTML('<?xml encoding="UTF-8">'.$raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), 'telegram_public_html');
    $xpath = new DOMXPath($dom);
    $posts = $xpath->query('//*[@data-post="anytour/'.$row['mid'].'"]');
    requirePost($posts->length === 1, 'telegram_public_identity');
    $node = $posts->item(0);
    $texts = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," tgme_widget_message_text ")]', $node);
    requirePost($texts->length === 1, 'telegram_public_text');
    $inner = '';
    foreach ($texts->item(0)->childNodes as $child) {
        $inner .= $dom->saveHTML($child);
    }
    $buttons = [];
    foreach ($xpath->query('.//a[contains(concat(" ",normalize-space(@class)," ")," tgme_widget_message_inline_button ")]', $node) as $button) {
        $buttons[] = ['text' => trim($button->textContent), 'url' => $button->getAttribute('href')];
    }
    $result = ['text' => html_entity_decode(strip_tags(preg_replace('~<br\s*/?>~i', "\n", $inner)), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'buttons' => $buttons];
    if (isset($row['next_media'])) {
        $parsed = parseTransferPublic($raw, 'anytour', $row['mid']);
        $result += array_intersect_key($parsed, array_flip(['published_unixtime','single_photo','photo_url','photo_marker_sha256']));
    }
    return $result;
}

function postPhotoIds(array $snapshot, string $platform): array
{
    $photos = [];
    $key = $platform === 'max' ? 'photo_id' : 'file_unique_id';
    foreach ($snapshot['attachments'] ?? [] as $item) {
        $value = $item[$key] ?? null;
        requirePost(($item['provider'] ?? '') === $platform && ($item['type'] ?? '') === 'image' && is_string($value) && $value !== '', 'existing_photo_unverified');
        $photos[] = $value;
    }
    requirePost($photos !== [], 'existing_photo_missing');
    return $photos;
}

/** The two owner-approved originals; no arbitrary paths, URLs or target expansion. */
function hotelEditMediaPlan(array $row): ?array
{
    if (!array_key_exists('next_media', $row)) return null;
    $scope = ChannelPostApiTransport::existingHotelPhotoScope((string)($row['mid'] ?? ''));
    requirePost(($row['platform'] ?? '') === 'telegram' && $scope !== null
        && ($row['hotel'] ?? null) === $scope['hotel'] && is_array($row['next_media']), 'existing_media_scope_invalid');
    $expected = array_intersect_key($scope, array_flip(['path','sha256','bytes','width','height']));
    requirePost($row['next_media'] === $expected, 'existing_media_plan_changed');
    return $scope;
}

/** Read once before the journal marker. The transport receives these same bytes. */
function hotelEditPhotoBytes(array $row, string $root): ?string
{
    $scope = hotelEditMediaPlan($row);
    if ($scope === null) return null;
    $root = realpath($root);
    requirePost(is_string($root), 'existing_media_root_unavailable');
    $path = $root.'/'.$scope['path'];
    requirePost(realpath($path) === $path && is_file($path) && !is_link($path), 'existing_media_file_unavailable');
    $bytes = file_get_contents($path, false, null, 0, $scope['bytes'] + 1);
    requirePost(is_string($bytes) && strlen($bytes) === $scope['bytes']
        && hash_equals($scope['sha256'], hash('sha256', $bytes)), 'existing_media_bytes_changed');
    return $bytes;
}

/** A matching caption alone never proves the approved image was installed. */
function hotelEditPhotoAccepted(array $snapshot, array $row): bool
{
    if (!array_key_exists('next_media', $row)) return true;
    try {
        $scope = hotelEditMediaPlan($row);
        $ids = postPhotoIds($snapshot, 'telegram');
        $proof = $snapshot['metadata']['hotel_editor_photo'] ?? null;
        return count($ids) === 1 && is_array($proof) && ($proof['sha256'] ?? null) === $scope['sha256']
            && ($proof['file_unique_id'] ?? null) === $ids[0];
    } catch (Throwable $error) { return false; }
}

/** Native readback is bound to the existing message, photo semantics and exact CTA. */
function hotelEditNativeMatches(array $prior, array $after, array $row, string $button, string $buttonText): bool
{
    try {
        if (($after['post_url'] ?? null) !== $row['url'] || ($after['text'] ?? null) !== $row['next']
            || !ChannelPostIncomingAdapter::containsOnlyButton($after, $button, $buttonText)) return false;
        $old = postPhotoIds($prior, $row['platform']);
        $new = postPhotoIds($after, $row['platform']);
        if (!array_key_exists('next_media', $row)) return $new === $old;
        return count($old) === 1 && count($new) === 1 && $new !== $old
            && ($after['metadata']['capability'] ?? null) === 'supported'
            && empty($after['metadata']['media_group_id']) && ($after['markup'] ?? null) === []
            && is_string($after['attachments'][0]['telegram_file_id'] ?? null)
            && $after['attachments'][0]['telegram_file_id'] !== ''
            && isset($prior['published_at']) && ($after['published_at'] ?? null) === $prior['published_at'];
    } catch (Throwable $error) { return false; }
}

/** Reuse the saved-price admission without rewriting the owner-approved editorial body. */
function hotelEditPriceMatches(array $row, array $export, int $now): void
{
    require_once __DIR__.'/direct_demand/operations/monitor-price-hook-contract.php';
    $check = $row['price_check'] ?? null;
    requirePost(is_array($check) && isset($check['record_id'], $check['offer']) && is_array($check['offer']), 'existing_price_plan_invalid');
    $snapshot = \monitor_hook_source($export, $row['hotel'], EDITORIAL_PRICE_SOURCE);
    $price = \monitor_price_observation($snapshot, $row['hotel'], $now, true);
    $actual = array_intersect_key($price['offer'], $check['offer']);
    $expected = $check['offer'];
    ksort($actual); ksort($expected);
    requirePost($price['record_id'] === $check['record_id'] && $actual === $expected, 'existing_approved_price_changed');
}

/** Read a prior attempt, including a response saved before a possible DB failure. */
function hotelEditOriginalAttempt(string $path, array $row): ?array
{
    if (!file_exists($path)) return null;
    requirePost(is_file($path) && !is_link($path), 'post_journal_unavailable');
    $file = fopen($path, 'rb');
    requirePost(is_resource($file), 'post_journal_unavailable');
    try {
        requirePost(flock($file, LOCK_SH | LOCK_NB), 'post_editor_busy');
        $raw = stream_get_contents($file, 4194305);
    } finally { fclose($file); }
    if ($raw === '') return null;
    requirePost(is_string($raw) && strlen($raw) <= 4194304 && str_ends_with($raw, "\n"), 'post_journal_incomplete');
    $events = array_map(static fn(string $line): array => json_decode($line, true, 64, JSON_THROW_ON_ERROR),
        explode("\n", rtrim($raw, "\n")));
    $starts = array_values(array_filter($events, static fn(array $event): bool => ($event['state'] ?? '') === 'provider_edit_started'));
    $responses = array_values(array_filter($events, static fn(array $event): bool => ($event['state'] ?? '') === 'provider_response_saved'));
    requirePost(count($starts) === 1 && count($responses) <= 1 && is_array($starts[0]['before'] ?? null)
        && is_array($starts[0]['button'] ?? null) && ($starts[0]['message_id'] ?? null) === $row['mid']
        && ($starts[0]['caption_sha256'] ?? null) === hash('sha256', $row['next'])
        && ($starts[0]['photo_sha256'] ?? null) === ($row['next_media']['sha256'] ?? null), 'previous_edit_needs_readback');
    $response = $responses[0]['response'] ?? null;
    requirePost($response === null || (is_array($response)
        && ($responses[0]['response_sha256'] ?? null) === hash('sha256', json_encode($response, JSON_THROW_ON_ERROR))),
        'post_original_response_changed');
    return $starts[0] + ['response'=>$response, 'journal_sha256'=>hash('sha256', $raw),
        'public_verified'=>in_array('public_verified', array_column($events, 'state'), true)];
}

function hotelEditPublicMatches(array $public, array $row, array $buttons, ?string $oldMarker): bool
{
    if (($public['text'] ?? null) !== $row['next'] || ($public['buttons'] ?? null) !== $buttons) return false;
    return !isset($row['next_media']) || (($public['single_photo'] ?? false) === true
        && ($public['published_unixtime'] ?? null) === $row['target_published_unixtime']
        && is_string($oldMarker) && is_string($public['photo_marker_sha256'] ?? null)
        && $public['photo_marker_sha256'] !== $oldMarker);
}

/** Complete only the already-saved response and public readback; never call a provider. */
function resumeHotelEdit(ChannelPostRegistryService $registry, array $row, array $attempt, string $journal): array
{
    requirePost(is_array($attempt['response']), 'previous_edit_needs_readback');
    $saved = $registry->completeTelegramExistingHotelEdit($attempt['before'], $row, $attempt['response']);
    requirePost($saved['state'] === 'sent' && $saved['snapshot']['text'] === $row['next']
        && hotelEditPhotoAccepted($saved['snapshot'], $row), 'registry_readback_unconfirmed');
    $base = ['platform'=>'telegram','hotel_id'=>$row['hotel'],'publication_id'=>(int)$saved['publication_id'],
        'mid'=>$row['mid'],'url'=>$row['url'],'provider_write_attempts'=>0,'readback_only'=>true];
    if ($attempt['public_verified']) return $base + ['ok'=>true,'state'=>'already_verified','caption_and_button_verified'=>true];
    $public = publicHotelPost($row);
    if (!hotelEditPublicMatches($public, $row, [$attempt['button']], $attempt['public_photo_marker_sha256'] ?? null)) {
        return $base + ['ok'=>false,'state'=>'edited_public_read_pending'];
    }
    $file = fopen($journal, 'r+b');
    requirePost(is_resource($file), 'post_journal_unavailable');
    try {
        requirePost(flock($file, LOCK_EX | LOCK_NB), 'post_editor_busy');
        $raw = stream_get_contents($file, 4194305);
        $current = $registry->publication((int)$saved['publication_id']);
        requirePost(is_string($raw) && hash('sha256', $raw) === $attempt['journal_sha256']
            && is_array($current) && $current['version_id'] === $saved['version_id']
            && $current['start_payload'] === $saved['start_payload'] && $current['snapshot'] === $saved['snapshot'],
            'post_readback_state_changed');
        $line = json_encode(['state'=>'public_verified','at'=>gmdate(DATE_ATOM),
            'photo_url'=>$public['photo_url'] ?? null,'photo_marker_sha256'=>$public['photo_marker_sha256'] ?? null], JSON_THROW_ON_ERROR).PHP_EOL;
        requirePost(fwrite($file, $line) === strlen($line) && fflush($file), 'post_journal_write_failed');
    } finally { fclose($file); }
    return $base + ['ok'=>true,'state'=>'edited_verified','caption_and_button_verified'=>true];
}

function readHotelMax(string $mid, string $channel): array
{
    $response = ChannelPostApiTransport::buttonRequest('max', 'GET', '/messages/'.$mid, [], null);
    $message = $response['data'] ?? [];
    requirePost($response['http'] === 200 && !$response['transport_error'] && ($message['body']['mid'] ?? '') === $mid
        && (string)($message['recipient']['chat_id'] ?? '') === $channel && ($message['recipient']['chat_type'] ?? '') === 'channel', 'max_native_identity');
    $post = ChannelPostIncomingAdapter::maxSnapshot($message);
    requirePost(is_array($post), 'max_native_snapshot');
    return $post;
}

function editHotelExisting(PDO $pdo, ChannelPostRegistryService $registry, array $row, bool &$providerWriteStarted = false): array
{
    $providerWriteStarted = false;
    if (($row['platform'] ?? null) === 'telegram' && in_array($row['mid'] ?? null, ['862','328','1083','288'], true)) {
        ChannelPostRegistryService::telegramExistingHotelEditDigest($row);
    }
    $platform = $row['platform'];
    $mid = $row['mid'];
    $channel = $platform === 'max' ? '-68765999669267' : '-1001845835645';
    $channelRow = $platform === 'max' ? 4 : 6;
    $q = $pdo->prepare('SELECT id FROM post_publications WHERE post_channel_id = ? AND external_message_id = ?');
    $q->execute([$channelRow, $mid]);
    $versions = $q->fetchAll(PDO::FETCH_COLUMN);
    requirePost(count($versions) === 1, 'expected_exactly_one_registered_publication');
    $id = (int)$versions[0];
    requirePost(!isset($row['post']) || (string)$id === $row['post'], 'publication_id_changed');
    $journal = POST_OPS.'/issue-1566-edit-existing-'.$id.'.jsonl';
    if (isset($row['price_check'])) {
        $attempt = hotelEditOriginalAttempt($journal, $row);
        if ($attempt !== null) return resumeHotelEdit($registry, $row, $attempt, $journal);
    }
    $post = $registry->publication($id);
    requirePost(is_array($post) && $post['state'] === 'sent' && $post['project_key'] === 'anytour' && $post['platform'] === $platform
        && $post['channel_id'] === $channel && (int)$post['channel']['id'] === $channelRow
        && $post['snapshot']['post_url'] === $row['url'], 'expected_live_publication');
    $prior = $post['snapshot'];
    $catalog = $prior['metadata']['hotel_catalog_id'] ?? null;
    requirePost($catalog === null || (int)$catalog === $row['hotel'], 'catalogue_id_conflict');
    $q = $pdo->prepare("SELECT COUNT(*) FROM post_versions WHERE publication_id=? AND
        (state IN ('claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL)");
    $q->execute([$id]);
    requirePost((int)$q->fetchColumn() === 0, 'publication_has_active_writer');
    $q->closeCursor();

    $button = ChannelPostButtonTransport::buttonUrl($post);
    $buttonText = (string)$post['channel']['button_text'];
    $context = ChannelPostEntryService::resolve($platform, $post['start_payload']);
    requirePost($button !== '' && $buttonText !== '' && (int)($context['publication_id'] ?? 0) === $id
        && (string)($context['external_message_id'] ?? '') === $mid, 'button_context_not_exact');
    requirePost(!isset($row['button_sha']) || hash_equals($row['button_sha'], hash('sha256', $button)), 'button_changed_since_archive');
    $physical = postPhotoIds($prior, $platform);
    requirePost(!is_link($journal), 'post_journal_unavailable');
    if ($prior['text'] === $row['next'] && (int)$catalog === $row['hotel'] && hotelEditPhotoAccepted($prior, $row)
        && is_file($journal) && str_contains((string)file_get_contents($journal, false, null, 0, 4194305), '"state":"public_verified"')) {
        return ['ok'=>true, 'platform'=>$platform, 'hotel_id'=>$row['hotel'], 'publication_id'=>$id,
            'mid'=>$mid, 'url'=>$row['url'], 'state'=>'already_verified', 'caption_and_button_verified'=>true];
    }
    $public = publicHotelPost($row);
    $expectedButtons = [['text' => $buttonText, 'url' => $button]];
    if ($platform === 'max') {
        $native = readHotelMax($mid, $channel);
        requirePost($native['post_url'] === $row['url'] && $native['text'] === $public['text']
            && postPhotoIds($native, $platform) === $physical
            && ChannelPostIncomingAdapter::containsOnlyButton($native, $button, $buttonText), 'provider_post_or_photo_changed');
    } else {
        // An older registry snapshot may predate the confirmed button edit.
        $liveButton = $public['buttons'][0] ?? null;
        requirePost(count($public['buttons']) === 1 && is_array($liveButton) && $liveButton['url'] === $button
            && $liveButton['text'] !== '' && (!isset($row['button_text']) || $liveButton['text'] === $row['button_text']),
            'telegram_public_button_changed');
        $buttonText = $liveButton['text'];
        $expectedButtons = [$liveButton];
    }

    $base = ['platform' => $platform, 'hotel_id' => $row['hotel'], 'publication_id' => $id, 'mid' => $mid, 'url' => $row['url']];
    if ($public['text'] === $row['next'] && $prior['text'] === $row['next'] && (int)$catalog === $row['hotel']
        && hotelEditPhotoAccepted($prior, $row)) {
        return $base + ['ok' => true, 'state' => 'already_verified', 'caption_and_button_verified' => true];
    }
    requirePost(hash_equals($row['old_caption_sha256'], hash('sha256', $public['text']))
        && $prior['text'] === $public['text'], 'pinned_caption_changed');
    $photoBytes = hotelEditPhotoBytes($row, __DIR__.'/..');
    $payload = $platform === 'max'
        ? ['text' => htmlspecialchars($row['next'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'format'=>'html']
        : ['caption'=>$row['next'], 'caption_entities'=>[], 'reply_markup'=>['inline_keyboard'=>[$expectedButtons]]];
    if ($photoBytes !== null) {
        requirePost(extension_loaded('curl') && class_exists('CURLStringFile')
            && ChannelPostApiTransport::existingHotelMediaPayload($channel, $mid, $payload, $photoBytes) !== null,
            'existing_media_transport_unavailable');
        requirePost(($public['single_photo'] ?? false) === true
            && ($public['published_unixtime'] ?? null) === ($row['target_published_unixtime'] ?? null)
            && is_string($public['photo_marker_sha256'] ?? null), 'existing_media_public_preimage_changed');
    }
    if (isset($row['price_check'])) {
        $export = json_decode(PromotedHotelPriceWriter::publicBytes(EDITORIAL_PRICE_SOURCE), true, 32, JSON_THROW_ON_ERROR);
        requirePost(is_array($export), 'existing_price_source_unavailable');
        hotelEditPriceMatches($row, $export, time());
    }
    $verifyChannel = $post['channel'];
    if ($platform === 'max') {
        $verifyChannel['expected_public_url'] = 'https://max.ru/anytour';
    }
    requirePost(((new ChannelPostButtonTransport())->verifyChannel($verifyChannel)['state'] ?? '') === 'ready', 'channel_edit_rights_unavailable');
    requirePost($registry->publication($id) === $post, 'publication_changed_before_write');
    $q->execute([$id]);
    requirePost((int)$q->fetchColumn() === 0, 'publication_claim_changed_before_write');
    $q->closeCursor();

    requirePost(realpath(POST_OPS) === POST_OPS && !is_link($journal), 'post_journal_unavailable');
    $record = @fopen($journal, 'c+b');
    requirePost(is_resource($record), 'post_journal_unavailable');
    try {
        requirePost(flock($record, LOCK_EX | LOCK_NB), 'post_editor_busy');
        requirePost(fstat($record)['size'] === 0 && $registry->publication($id) === $post, 'previous_edit_needs_readback');
        $write = static function (string $state, array $evidence = []) use ($record): void {
            $line = json_encode(['state'=>$state,'at'=>gmdate(DATE_ATOM)] + $evidence, JSON_THROW_ON_ERROR).PHP_EOL;
            requirePost(fwrite($record, $line) === strlen($line) && fflush($record), 'post_journal_write_failed');
        };
        if (isset($export)) hotelEditPriceMatches($row, $export, time());
        $write('provider_edit_started', ['publication_id'=>$id, 'message_id'=>$mid,
            'caption_sha256'=>hash('sha256', $row['next']), 'photo_sha256'=>$row['next_media']['sha256'] ?? null,
            'before'=>$post,'button'=>$expectedButtons[0] ?? null,
            'public_photo_marker_sha256'=>$public['photo_marker_sha256'] ?? null]);
        $providerWriteStarted = true;
        $at = (int)floor(microtime(true)*1000);
        $response = $photoBytes === null
            ? ChannelPostApiTransport::captionRequest($platform, $channel, $mid, $payload)
            : ChannelPostApiTransport::existingHotelMediaRequest($channel, $mid, $payload, $photoBytes);
        // Preserve the original answer before any DB action. An unknown write is never retried.
        $write('provider_response_saved', ['response'=>$response,
            'response_sha256'=>hash('sha256', json_encode($response, JSON_THROW_ON_ERROR))]);
        if ($platform === 'max') {
            $after = readHotelMax($mid, $channel);
        } else {
            $message = $response['data']['result'] ?? [];
            requirePost($response['http'] === 200 && !$response['transport_error'] && ($response['data']['ok'] ?? false) === true
                && (string)($message['chat']['id'] ?? '') === $channel && (string)($message['message_id'] ?? '') === $mid
                && ($message['chat']['username'] ?? '') === 'anytour' && (int)($message['edit_date'] ?? 0) > 0, 'telegram_edit_response_unconfirmed');
            requirePost(!isset($row['target_published_unixtime'])
                || ($message['date'] ?? null) === $row['target_published_unixtime'], 'telegram_original_date_changed');
            $at = (int)$message['edit_date']*1000;
            $after = ChannelPostIncomingAdapter::telegramSnapshot($message);
        }
        requirePost(is_array($after) && hotelEditNativeMatches($prior, $after, $row, $button, $buttonText), 'provider_readback_unconfirmed');
        $after['metadata'] = array_replace($prior['metadata'], $after['metadata'], [
            'hotel_catalog_id'=>$row['hotel'], 'source_event_id'=>'issue-1566-existing:'.$id.':'.$at,
            'observation_kind'=>'authorized_admin_content_edit', 'source_revision_ms'=>(string)$at,
        ]);
        if ($photoBytes !== null) {
            $after['metadata']['hotel_editor_photo'] = ['sha256'=>$row['next_media']['sha256'],
                'file_unique_id'=>$after['attachments'][0]['file_unique_id']];
        }
        unset($after['metadata']['source_sequence'], $after['metadata']['source_sequence_scope']);
        $after['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($after, '', $post['channel']['bot_url']);
        $saved = isset($row['price_check'])
            ? $registry->completeTelegramExistingHotelEdit($post, $row, $response)
            : $registry->registerPublication('anytour', $platform, $post['bot_key'], $channel, $mid, $after);
        requirePost(is_array($saved) && (int)$saved['publication_id'] === $id && $saved['start_payload'] === $post['start_payload']
            && $saved['snapshot']['text'] === $row['next'] && hotelEditPhotoAccepted($saved['snapshot'], $row), 'registry_readback_unconfirmed');
        if ($saved['state'] !== 'sent') {
            requirePost($saved['state'] === 'pending' && $registry->confirmObservedButton($id, (int)$saved['version_id'], [
                'verified'=>true, 'button_verified'=>true, 'content_preserved'=>true, 'write_attempts'=>0,
            ]), 'registry_button_confirmation_failed');
        }
        $write('provider_and_registry_verified');
        for ($read = 0; $read < 3; ++$read) {
            if ($read > 0) { sleep(2); }
            try {
                $seen = publicHotelPost($row);
                $publicMatches = $platform === 'max' ? $seen['text'] === $row['next']
                    : hotelEditPublicMatches($seen, $row, $expectedButtons, $public['photo_marker_sha256'] ?? null);
                if ($publicMatches) {
                    $write('public_verified', ['photo_url'=>$seen['photo_url'] ?? null,
                        'photo_marker_sha256'=>$seen['photo_marker_sha256'] ?? null]);
                    return $base + ['ok' => true, 'state' => 'edited_verified', 'caption_and_button_verified' => true];
                }
            } catch (Throwable $error) {
                // The provider edit was already verified. Repeat only the public read.
            }
        }
        return $base + ['ok'=>false, 'state'=>'edited_public_read_pending'];
    } finally {
        fclose($record);
    }
}

function validHotelEditPlan(array $row): bool
{
    try { hotelEditMediaPlan($row); } catch (Throwable $error) { return false; }
    if (!is_int($row['hotel'] ?? null) || $row['hotel'] <= 0
        || !is_string($row['mid'] ?? null) || !is_string($row['url'] ?? null)
        || !preg_match('/\A[a-f0-9]{64}\z/D', $row['old_caption_sha256'] ?? '')
        || !is_string($row['next'] ?? null) || trim($row['next']) !== $row['next']
        || !mb_check_encoding($row['next'], 'UTF-8')
        || strlen(mb_convert_encoding($row['next'], 'UTF-16LE', 'UTF-8')) > 2048) {
        return false;
    }
    if (isset($row['target_published_unixtime']) && (!is_int($row['target_published_unixtime']) || $row['target_published_unixtime'] <= 0)) return false;
    if (array_key_exists('price_check', $row) && (($row['platform'] ?? '') !== 'telegram'
        || ([17595=>'862',358=>'328',393=>'1083',1260=>'288'][$row['hotel']] ?? null) !== $row['mid']
        || !is_array($row['price_check']) || !is_array($row['price_check']['offer'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/D', $row['price_check']['record_id'] ?? '') !== 1)) return false;
    if ($row['platform'] === 'telegram') {
        return preg_match('/\A[1-9][0-9]{0,9}\z/D', $row['mid']) === 1
            && $row['url'] === 'https://t.me/anytour/'.$row['mid'];
    }
    if ($row['platform'] !== 'max' || !preg_match('/\Amid\.ffffc1752b045fed([a-f0-9]{16})\z/D', $row['mid'], $mid)
        || !preg_match('/\A[1-9][0-9]*\z/D', $row['post'] ?? '')
        || !preg_match('/\A[a-f0-9]{64}\z/D', $row['button_sha'] ?? '')) {
        return false;
    }
    return $row['url'] === 'https://max.ru/anytour/'.rtrim(strtr(base64_encode(hex2bin($mid[1])), '+/', '-_'), '=');
}

/** Runs from the existing MAX channel worker. At most one verified content edit per tick. */
function tick(string $root): array
{
    if (PHP_SAPI !== 'cli' || ProjectConfig::projectId() !== 'anytour') {
        return [];
    }
    require_once $root.'/services/ChannelPostEntryService.php';
    require_once $root.'/services/ChannelPostButtonTransport.php';
    require_once $root.'/services/PromotedHotelPriceWriter.php';
    require_once $root.'/integrations/ChannelPostIncomingAdapter.php';
    require_once $root.'/integrations/ChannelPostApiTransport.php';

    $file = $root.'/config/anytour_existing_post_batch.json';
    $raw = @file_get_contents($file, false, null, 0, 64001);
    requirePost(is_string($raw) && strlen($raw) <= 64000, 'invalid_post_batch_file');
    $batch = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
    requirePost(is_array($batch) && array_is_list($batch) && count($batch) <= 20, 'invalid_post_batch_size');
    $seen = [];
    foreach ($batch as $row) {
        requirePost(is_array($row) && validHotelEditPlan($row) && !isset($seen[$row['platform'].':'.$row['mid']]), 'invalid_post_batch_row');
        $seen[$row['platform'].':'.$row['mid']] = true;
    }
    $pdo = ConversationDb::connection();
    $registry = new ChannelPostRegistryService($pdo);
    $results = [];
    $shared = null;
    $previousUmask = umask(0077);
    try {
        if (count(array_filter($batch, static fn(array $row): bool => isset($row['next_media']) || isset($row['price_check']))) > 0) {
            $lease = POST_OPS.'/monitor-price-after-collection-v1.jsonl';
            requirePost(is_file($lease) && !is_link($lease), 'original_price_lock_missing');
            $shared = fopen($lease, 'rb');
            requirePost(is_resource($shared) && flock($shared, LOCK_EX | LOCK_NB), 'shared_editor_busy');
        }
        foreach ($batch as $row) {
            $providerWriteStarted = false;
            try {
                $status = editHotelExisting($pdo, $registry, $row, $providerWriteStarted);
            } catch (Throwable $error) {
                $message = $error->getMessage();
                $status = ['ok'=>false, 'platform'=>$row['platform'], 'hotel_id'=>$row['hotel'], 'url'=>$row['url'],
                    'error'=>preg_match('/\A[a-z0-9_]{3,70}\z/D', $message) ? $message : 'verification_failed'];
                if ($providerWriteStarted) $status += ['state'=>'requires_readback','provider_write_attempts'=>1];
            }
            $results[] = $status;
            if ($providerWriteStarted || in_array($status['state'] ?? '', ['edited_verified','edited_public_read_pending'], true)) {
                break;
            }
        }
    } finally {
        if (is_resource($shared)) fclose($shared);
        umask($previousUmask);
    }
    return $results;
}


/**
 * Explicit operator transfer, isolated from tick() and its automatic caption batch.
 * The manifest is review data. Only a separately approved --apply-transfer invocation
 * may reserve identities, write the once-only journal, or reach the media transport.
 */
const TRANSFER_OPERATION = 'anytour_hotel_transfer_four_20261123';
const TRANSFER_PLAN_FILE = 'config/anytour_existing_post_transfer_four_20261123.json';
const TRANSFER_MONITOR_URL = 'https://anytoour.ru/data/hotel-monitor-prices-read-v1.php?tourvisorHotelIds%5B%5D=17595&tourvisorHotelIds%5B%5D=358&tourvisorHotelIds%5B%5D=393&tourvisorHotelIds%5B%5D=1260&departureId=1&dateFrom=2026-11-24&dateTo=2027-10-31';
const TRANSFER_PAIRS = [
    17595 => ['source'=>'455','source_publication'=>1003,'target'=>'862','date'=>1726731731],
    358 => ['source'=>'65','source_publication'=>211,'target'=>'328','date'=>1705145228],
    393 => ['source'=>'85','source_publication'=>247,'target'=>'1083','date'=>1745392994],
    1260 => ['source'=>'548','source_publication'=>1220,'target'=>'288','date'=>1703918786],
];

const NEXT_TRANSFER_OPERATION = 'anytour_hotel_transfer_next_20261123';
const REMAINING_TRANSFER_OPERATION = 'anytour_hotel_transfer_remaining_20261123';
const ORIGINAL_NEXT_TRANSFER_RUN = '45235830990';
const ORIGINAL_NEXT_TRANSFER_APP = 'bb7d0bde64092cbd24a69084e651ff44336ba8b5';
const NEXT_TRANSFER_PLAN_FILE = 'config/anytour_existing_post_transfer_next_20261123.json';
const NEXT_TRANSFER_PAIRS = [
    800 => ['source'=>'219','source_publication'=>535,'target'=>'1588','date'=>1781855273],
    408 => ['source'=>'120','source_publication'=>323,'target'=>'997','date'=>1737725306],
    466 => ['source'=>'473','source_publication'=>1047,'target'=>'1101','date'=>1747735272],
];

function transferRankedProfile(string $digest): array
{
    return AnyTourRankedHotelTransfer46::PLANS[$digest] ?? throw new RuntimeException('transfer_ranked_plan_unknown');
}

function transferProfilePairs(bool|string $next): array
{
    if (is_string($next)) return transferRankedProfile($next)['pairs'];
    return $next ? NEXT_TRANSFER_PAIRS : TRANSFER_PAIRS;
}

function transferProfileOperation(bool|string $next): string
{
    if (is_string($next)) return transferRankedProfile($next)['operation'];
    return $next ? NEXT_TRANSFER_OPERATION : TRANSFER_OPERATION;
}

function transferProfileFile(bool|string $next): string
{
    if (is_string($next)) return transferRankedProfile($next)['path'];
    return $next ? NEXT_TRANSFER_PLAN_FILE : TRANSFER_PLAN_FILE;
}

function transferRankedOfferScope(array $rows): void
{
    require_once dirname(__DIR__) . '/hotel-posts-telegram-main19.php';
    $feed = anytourTelegramFeedOfferUrls(anytourTelegramFeedRead());
    requirePost($feed !== [], 'transfer_ranked_feed_unavailable');
    $routes = anytourTelegramFeedDestinationPosts($feed);
    $used = array_fill_keys(array_merge(array_values($routes), [
        'https://t.me/anytour/1780', 'https://t.me/anytour/1760',
    ]), true);
    foreach ($rows as $row) {
        $offer = 'tg_' . $row['hotel_id'];
        $source = 'https://t.me/any_toour/' . $row['source_message_id'];
        $target = 'https://t.me/anytour/' . $row['target_message_id'];
        requirePost(($feed[$offer] ?? null) === $source && ($routes[$offer] ?? null) === $source,
            'transfer_ranked_source_offer_changed');
        requirePost(!isset($used[$target]), 'transfer_ranked_target_in_use');
    }
}

function transferRegistryRow(array $row): array
{
    unset($row['price_template'], $row['source_photo_marker_sha256']);
    return $row;
}

/** Pure decoding: no runtime bootstrap, reservation, HTTP, or journal creation. */
function transferPlanFromBytes(string $raw, string $approvedDigest, bool|string $next = false): array
{
    $pairs = transferProfilePairs($next);
    $operation = transferProfileOperation($next);
    requirePost(strlen($raw) <= 64000 && preg_match('/\A[a-f0-9]{64}\z/D', $approvedDigest) === 1
        && hash_equals($approvedDigest, hash('sha256', $raw)), 'transfer_approved_plan_changed');
    $plan = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    requirePost(is_array($plan) && array_keys($plan) === ['schema','operation','execution','native_preflight',
        'monitor_source_url','price_policy','rows']
        && $plan['schema'] === 1 && $plan['operation'] === $operation
        && $plan['execution'] === 'explicit_owner_apply_only'
        && $plan['monitor_source_url'] === ($next ? null : TRANSFER_MONITOR_URL)
        && $plan['price_policy'] === ($next ? 'omit_previous_price_keep_hotel_calculation_button'
            : 'fresh_saved_export_must_render_identical_approved_caption')
        && is_array($plan['rows']) && array_is_list($plan['rows'])
        && array_column($plan['rows'], 'hotel_id') === array_keys($pairs), 'transfer_plan_scope_invalid');
    $proof = $plan['native_preflight'];
    if (is_string($next)) {
        requirePost(hash_equals($next, $approvedDigest) && $proof === AnyTourRankedHotelTransfer46::PROOF,
            'transfer_ranked_native_proof_changed');
    } elseif ($next) {
        // The read ran while an unrelated release was deploying. Pin both identities.
        requirePost($proof === [
            'run_id' => '45156882185',
            'run_attempt' => 1,
            'artifact_id' => 14808682666,
            'artifact_zip_sha256' => '5b68a4cf346f4cf07214dc7f12b2eefbf3a03c8a91300fc39cc71c53a2854b82',
            'registry_sha256' => '998403be4281b5ec5deaddbdabcba0119b8882e9d97ced1a6d6c95db6ce7bc62',
            'checkpoint_sha256' => 'bbdf76f114a97a1796be46fd1b68e494156689787fec7e2e0e4f265ebf6b6dfc',
            'repository_sha' => 'f2894cdf9a14031d53ee8622934364c35dab5730',
            'application_sha' => 'f773b4d5a88e4a3d2a08031b01c34f061d00608a',
        ], 'transfer_native_preflight_changed');
    } else {
        requirePost(is_array($proof) && ($proof['run_id'] ?? '') === '45088999012'
            && ($proof['run_attempt'] ?? 0) === 1 && ($proof['artifact_id'] ?? 0) === 14780019122
            && ($proof['artifact_zip_sha256'] ?? '') === '1eafbbb2500ef340ada373384db98ee5743e48da40256891e3e25e473e60f0a3'
            && ($proof['registry_sha256'] ?? '') === 'c8379052d1c669aeae4aa960267caca92e7d621d2e88294bfcfcdbcc4100f70c'
            && ($proof['checkpoint_sha256'] ?? '') === '85f3ce09285f3f0e3c98b93ab295dfe3daf58946166baf2c2e79e09dd3feef0a'
            && ($proof['repository_sha'] ?? '') === '847bd25bee0d5dc3e7b418fe897805607233f7f0'
            && ($proof['application_sha'] ?? '') === $proof['repository_sha'], 'transfer_native_preflight_changed');
    }
    $keys = ['hotel_id','source_message_id','source_publication_id','source_version_id','source_content_hash',
        'source_caption_sha256','source_file_id_sha256','source_file_unique_id_sha256','target_message_id',
        'target_published_unixtime','target_caption_sha256','target_photo_marker_sha256','target_config_version',
        'next_caption','next_caption_sha256','button_text','bot_url','price_template','price_record_id',
        'source_photo_marker_sha256'];
    sort($keys);
    foreach ($plan['rows'] as $row) {
        requirePost(is_array($row), 'transfer_plan_row_invalid');
        $actual = array_keys($row); sort($actual);
        $pair = $pairs[$row['hotel_id']];
        requirePost($actual === $keys && $row['source_message_id'] === $pair['source']
            && $row['source_publication_id'] === $pair['source_publication']
            && is_int($row['source_version_id']) && $row['source_version_id'] > 0
            && $row['target_message_id'] === $pair['target'] && $row['target_published_unixtime'] === $pair['date']
            && $row['target_config_version'] === 2 && $row['bot_url'] === 'https://t.me/anyru_bot'
            && $row['button_text'] === '✈️ Рассчитать на мои даты', 'transfer_plan_row_identity');
        foreach (['source_content_hash','source_caption_sha256','source_file_id_sha256','source_file_unique_id_sha256',
            'target_caption_sha256','target_photo_marker_sha256','next_caption_sha256',
            'source_photo_marker_sha256'] as $field) {
            requirePost(is_string($row[$field]) && preg_match('/\A[a-f0-9]{64}\z/D', $row[$field]) === 1,
                'transfer_plan_digest_invalid');
        }
        requirePost(is_string($row['next_caption']) && $row['next_caption'] !== ''
            && mb_check_encoding($row['next_caption'], 'UTF-8')
            && strlen(mb_convert_encoding($row['next_caption'], 'UTF-16LE', 'UTF-8')) <= 2048
            && hash_equals($row['next_caption_sha256'], hash('sha256', $row['next_caption']))
            && is_string($row['price_template']) && mb_check_encoding($row['price_template'], 'UTF-8')
            && strlen($row['price_template']) <= 8000, 'transfer_caption_invalid');
        requirePost($next
            ? $row['price_record_id'] === null && $row['price_template'] === ''
            : is_string($row['price_record_id']) && preg_match('/\A[a-f0-9]{64}\z/D', $row['price_record_id']) === 1,
            'transfer_price_policy_invalid');
    }
    return $plan;
}

function transferPublicAllowed(string $channel, string $message, bool|string $next = false): bool
{
    $pairs = transferProfilePairs($next);
    $allowed = $channel === 'anytour' ? array_column($pairs, 'target')
        : ($channel === 'any_toour' ? array_column($pairs, 'source') : []);
    return in_array($message, $allowed, true);
}

/** Only fixed public posts and, for the original batch, the saved monitor export. */
function transferReadBytes(string $url, bool|string $next = false): string
{
    $allowed = $next ? [] : [TRANSFER_MONITOR_URL];
    foreach ((transferProfilePairs($next)) as $pair) {
        $allowed[] = 'https://t.me/anytour/'.$pair['target'].'?embed=1&mode=tme';
        $allowed[] = 'https://t.me/any_toour/'.$pair['source'].'?embed=1&mode=tme';
    }
    requirePost(in_array($url, $allowed, true), 'transfer_read_scope_invalid');
    $body = '';
    $curl = curl_init($url);
    requirePost($curl !== false, 'transfer_read_unavailable');
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>18, CURLOPT_USERAGENT=>'Mozilla/5.0',
        CURLOPT_WRITEFUNCTION=>static function ($unused, string $part) use (&$body): int {
            if (strlen($body) + strlen($part) > 1_000_000) return 0;
            $body .= $part; return strlen($part);
        },
    ]);
    $ok = curl_exec($curl) !== false && curl_errno($curl) === 0;
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    requirePost($ok && $status === 200, 'transfer_read_unavailable');
    return $body;
}

function transferPublic(string $channel, string $message, bool|string $next = false): array
{
    requirePost(transferPublicAllowed($channel, $message, $next), 'transfer_public_scope_invalid');
    return parseTransferPublic(transferReadBytes('https://t.me/'.$channel.'/'.$message.'?embed=1&mode=tme', $next),
        $channel, $message, $next);
}

function parseTransferPublic(string $body, string $channel, string $message, bool|string $next = false): array
{
    requirePost(transferPublicAllowed($channel, $message, $next), 'transfer_public_scope_invalid');
    requirePost(strlen($body) <= 1_000_000, 'transfer_public_body_unbounded');
    $url = 'https://t.me/' . $channel . '/' . $message;
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prior = libxml_use_internal_errors(true);
    try { $loaded = $doc->loadHTML($body, LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    requirePost($loaded, 'public_post_invalid');
    $xp = new DOMXPath($doc);
    $nodes = $xp->query('//*[@data-post="' . $channel . '/' . $message . '"]');
    requirePost($nodes && $nodes->length === 1, 'public_identity_changed');
    $node = $nodes->item(0);
    $cls = static fn(string $c): string => 'contains(concat(" ",normalize-space(@class)," ")," ' . $c . ' ")';
    $text = $xp->query('.//*[' . $cls('tgme_widget_message_text') . ']', $node);
    $photos = $xp->query('.//*[' . $cls('tgme_widget_message_photo_wrap') . ']', $node);
    $dates = $xp->query('.//time[@datetime]', $node);
    $other = $xp->query('.//video|.//audio|.//*[' . $cls('tgme_widget_message_video_thumb')
        . ' or ' . $cls('tgme_widget_message_roundvideo') . ' or ' . $cls('tgme_widget_message_grouped') . ']', $node);
    requirePost($text && $photos && $dates && $other && $text->length === 1 && $dates->length === 1,
        'public_caption_or_date_unavailable');
    $html = '';
    foreach ($text->item(0)->childNodes as $child) $html .= $doc->saveHTML($child);
    $caption = html_entity_decode(strip_tags(preg_replace('~<br\s*/?>~i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    requirePost($caption !== '' && mb_check_encoding($caption, 'UTF-8') && mb_strlen($caption, 'UTF-8') <= 12000,
        'public_caption_invalid');
    $single = $photos->length === 1 && $other->length === 0;
    $photo = $single ? $photos->item(0) : null;
    $marker = $photo ? trim($photo->getAttribute('class')) : '';
    $photoUrl = '';
    if ($photo && $photo->getAttribute('href') === $url
        && preg_match('/(?:^|\s)[0-9]{5,}(?:_[0-9]+)?(?:\s|$)/D', $marker) === 1
        && preg_match('~background-image:\s*url\([\'"]?(https://[^\'")]+)[\'"]?\)~', $photo->getAttribute('style'), $match) === 1) {
        $parsed = parse_url($match[1]);
        if (is_array($parsed) && ($parsed['scheme'] ?? '') === 'https'
            && preg_match('/\A(?:[a-z0-9-]+\.)*(?:cdn-telegram\.org|telegram-cdn\.org|telesco\.pe)\z/D', $parsed['host'] ?? '') === 1
            && !isset($parsed['user']) && !isset($parsed['pass']) && !isset($parsed['port'])) $photoUrl = $match[1];
    }
    $buttons = [];
    $links = $xp->query('.//a[' . $cls('tgme_widget_message_inline_button') . ']', $node);
    requirePost($links && $links->length <= 20, 'public_keyboard_unbounded');
    foreach ($links as $link) {
        $href = $link->getAttribute('href');
        $buttons[] = ['text' => trim($link->textContent),
            'hotel_url' => preg_match('~\Ahttps://t\.me/anyru_bot\?start=p_[A-Za-z0-9_-]{8,60}\z~D', $href) === 1 ? $href : null,
            'url_sha256' => hash('sha256', $href)];
    }
    return ['url' => $url, 'caption' => $caption, 'caption_sha256' => hash('sha256', $caption),
        'html_sha256' => hash('sha256', $body), 'published_at' => $dates->item(0)->getAttribute('datetime'),
        'published_unixtime' => (new DateTimeImmutable($dates->item(0)->getAttribute('datetime')))->getTimestamp(),
        'single_photo' => $single && $photoUrl !== '', 'photo_url' => $photoUrl,
        'photo_marker_sha256' => $marker !== '' ? hash('sha256', $marker) : null, 'buttons' => $buttons];
}

function transferPublicPreimage(array $row, array $source, array $target): void
{
    requirePost($source['url'] === 'https://t.me/any_toour/'.$row['source_message_id']
        && $source['single_photo'] === true && $source['caption_sha256'] === $row['source_caption_sha256']
        && $source['photo_marker_sha256'] === $row['source_photo_marker_sha256'], 'transfer_source_public_changed');
    requirePost($target['url'] === 'https://t.me/anytour/'.$row['target_message_id']
        && $target['single_photo'] === true && $target['published_unixtime'] === $row['target_published_unixtime']
        && $target['caption_sha256'] === $row['target_caption_sha256']
        && $target['photo_marker_sha256'] === $row['target_photo_marker_sha256'], 'transfer_target_public_changed');
}

function transferRuntime(string $root, string $applicationSha): void
{
    requirePost(PHP_SAPI === 'cli' && $root === '/var/www/anytoour/data/www/app.anytoour.ru'
        && realpath($root) === $root && getcwd() === $root
        && preg_match('/\A[a-f0-9]{40}\z/D', $applicationSha) === 1, 'transfer_runtime_identity_changed');
    foreach ([
        'GIT_OPTIONAL_LOCKS=0 git -c core.fsmonitor=false rev-parse HEAD' => $applicationSha,
        'GIT_OPTIONAL_LOCKS=0 git -c core.fsmonitor=false status --porcelain --untracked-files=no' => '',
    ] as $command => $expected) {
        $lines = []; exec($command.' 2>/dev/null', $lines, $status);
        requirePost($status === 0 && implode("\n", $lines) === $expected, 'transfer_installed_application_changed');
    }
}

/** Existing pure price contract; freshly re-read quote must reproduce the approved text exactly. */
function transferPrice(array $plan, array $row, array $export, int $now): array
{
    $snapshot = \monitor_hook_source($export, $row['hotel_id'], $plan['monitor_source_url']);
    $price = \monitor_price_observation($snapshot, $row['hotel_id'], $now, true);
    $caption = \monitor_hook_draft($row['price_template'], $price);
    requirePost($price['record_id'] === $row['price_record_id'] && $caption === $row['next_caption']
        && hash_equals($row['next_caption_sha256'], hash('sha256', $caption)), 'transfer_price_or_caption_changed');
    return $price;
}

function transferSource(PDO $pdo, ChannelPostRegistryService $registry, array $row): array
{
    $journal = POST_OPS.'/issue-1566-edit-existing-'.$row['source_publication_id'].'.jsonl';
    requirePost(!file_exists($journal) && !is_link($journal), 'transfer_source_editor_requires_readback');
    $source = $registry->publication($row['source_publication_id']);
    requirePost(is_array($source) && $source['project_key'] === 'anytour' && $source['platform'] === 'telegram'
        && $source['bot_key'] === 'anyru_bot' && $source['channel_id'] === '-1002262666616'
        && (int)$source['channel']['id'] === 10 && $source['message_id'] === $row['source_message_id']
        && $source['version_id'] === $row['source_version_id'] && $source['state'] === 'sent'
        && hash_equals($row['source_content_hash'], $source['content_hash'])
        && hash_equals($row['source_caption_sha256'], hash('sha256', $source['snapshot']['text']))
        && (int)($source['snapshot']['metadata']['hotel_catalog_id'] ?? 0) === $row['hotel_id'],
        'transfer_source_version_changed');
    $media = $source['snapshot']['attachments'] ?? [];
    requirePost(count($media) === 1 && ($media[0]['type'] ?? '') === 'image'
        && ($media[0]['provider'] ?? '') === 'telegram'
        && is_string($media[0]['telegram_file_id'] ?? null) && is_string($media[0]['file_unique_id'] ?? null)
        && hash_equals($row['source_file_id_sha256'], hash('sha256', $media[0]['telegram_file_id']))
        && hash_equals($row['source_file_unique_id_sha256'], hash('sha256', $media[0]['file_unique_id'])),
        'transfer_source_photo_changed');
    $q = $pdo->prepare("SELECT COUNT(*) FROM post_versions WHERE publication_id=? AND "
        ."(state IN ('claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL)");
    $q->execute([$row['source_publication_id']]);
    requirePost((int)$q->fetchColumn() === 0, 'transfer_source_writer_held'); $q->closeCursor();
    $resolved = $registry->resolveToken('anytour', 'telegram', 'anyru_bot', $source['start_payload']);
    requirePost(is_array($resolved) && $resolved['publication_id'] === $source['publication_id']
        && $resolved['version_id'] === $source['version_id'], 'transfer_source_token_changed');
    return $source;
}

function transferNoTarget(PDO $pdo, array $row): void
{
    $q = $pdo->prepare('SELECT id FROM post_publications WHERE post_channel_id=6 AND external_message_id=? LIMIT 2');
    $q->execute([$row['target_message_id']]);
    requirePost($q->fetchAll(PDO::FETCH_COLUMN) === [], 'transfer_target_identity_exists'); $q->closeCursor();
    $q = $pdo->prepare("SELECT COUNT(*) FROM post_publications p JOIN post_versions v "
        ."ON v.id=p.current_version_id AND v.publication_id=p.id WHERE p.post_channel_id=6 AND "
        ."(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id'))=? OR "
        ."JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.logical_id')) IN (?,?))");
    $q->execute([(string)$row['hotel_id'], 'commercial-hotel-'.$row['hotel_id'].'-v1',
        'commercial-main-hotel-'.$row['hotel_id'].'-v1']);
    requirePost((int)$q->fetchColumn() === 0, 'transfer_hotel_already_in_large_channel'); $q->closeCursor();
}

function transferChannelRights(ChannelPostRegistryService $registry): array
{
    $channel = $registry->channel('anytour', 'telegram', 'anyru_bot', '-1001845835645');
    $identity = \ChannelPostRuntime::identity('telegram');
    requirePost(is_array($channel) && (int)$channel['id'] === 6 && (int)$channel['config_version'] === 2
        && $channel['source_key'] === 'tg_anytour_msk' && $channel['button_text'] === '✈️ Рассчитать на мои даты'
        && $channel['bot_url'] === 'https://t.me/anyru_bot' && ($channel['post_mode'] ?? 'append_button') === 'append_button'
        && ($identity['project_key'] ?? '') === 'anytour' && ($identity['bot_key'] ?? '') === 'anyru_bot',
        'transfer_channel_identity_changed');
    $username = false;
    $read = static function (string $platform, string $method, string $path, array $query, ?array $body) use (&$username): array {
        requirePost($platform === 'telegram' && $method === 'POST' && $query === []
            && in_array($path, ['getMe','getChatMember','getChat'], true)
            && ($path === 'getMe' || (string)($body['chat_id'] ?? '') === '-1001845835645'),
            'transfer_rights_scope_invalid');
        $response = ChannelPostApiTransport::buttonRequest($platform, $method, $path, $query, $body);
        if ($path === 'getChat') $username = strtolower((string)($response['data']['result']['username'] ?? '')) === 'anytour';
        return $response;
    };
    $rights = (new ChannelPostButtonTransport($read))->verifyChannel($channel);
    requirePost(($rights['state'] ?? '') === 'ready' && ($rights['receipt']['write_attempts'] ?? null) === 0
        && $username, 'transfer_native_rights_unconfirmed');
    $response = ChannelPostApiTransport::buttonRequest('telegram', 'POST', 'getChat', [],
        ['chat_id'=>'-1002262666616']);
    $source = $response['data']['result'] ?? [];
    requirePost(($response['http'] ?? 0) === 200 && empty($response['transport_error'])
        && ($response['data']['ok'] ?? null) === true && ($source['type'] ?? '') === 'channel'
        && (string)($source['id'] ?? '') === '-1002262666616'
        && strtolower((string)($source['username'] ?? '')) === 'any_toour', 'transfer_source_channel_changed');
    return $channel;
}

/** A durable local receipt is independent of ordinary button-version attempt counters. */
function transferJournal($record, array $event): void
{
    requirePost(is_resource($record), 'transfer_journal_unavailable');
    $line = json_encode($event + ['at_utc'=>gmdate(DATE_ATOM)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    requirePost(strlen($line) <= 3_000_000, 'transfer_journal_event_unbounded');
    for ($offset = 0; $offset < strlen($line); $offset += $written) {
        $written = fwrite($record, substr($line, $offset));
        requirePost(is_int($written) && $written > 0, 'transfer_journal_write_failed');
    }
    requirePost(fflush($record) && function_exists('fsync') && fsync($record), 'transfer_journal_sync_failed');
}

function transferReason(Throwable $error): string
{
    return $error instanceof RuntimeException && preg_match('/\A[a-z0-9_]{3,100}\z/D', $error->getMessage())
        ? $error->getMessage() : 'transfer_verification_failed';
}


/**
 * The operation journal is append-only. Injectable internal effects allow the
 * production control flow to be tested without bootstrapping runtime credentials.
 */
function transferExecuteRow(array $row, string $planDigest, array $effects, string $operations = POST_OPS, bool|string $next = false): array
{
    $pairs = transferProfilePairs($next);
    if (is_string($next)) requirePost(hash_equals($next, $planDigest), 'transfer_ranked_digest_changed');
    foreach (['reserve','prepare','assert','request','complete','runtime'] as $name) {
        requirePost(isset($effects[$name]) && is_callable($effects[$name]), 'transfer_effects_invalid');
    }
    requirePost(isset($pairs[$row['hotel_id']])
        && $pairs[$row['hotel_id']]['target'] === $row['target_message_id']
        && realpath($operations) === $operations, 'transfer_operation_scope_invalid');
    $operation = 'anytour-issue1566-historical-tg-media-'.$row['target_message_id'].'-v1';
    $directory = $operations.'/'.$operation;
    $record = null; $attempts = 0; $responseSaved = false; $reservation = null;
    $base = ['hotel_id'=>$row['hotel_id'], 'message_id'=>$row['target_message_id'],
        'url'=>'https://t.me/anytour/'.$row['target_message_id'], 'operation'=>$operation];
    try {
        requirePost(!file_exists($directory) && !is_link($directory), 'transfer_previous_operation_do_not_replay');
        requirePost(mkdir($directory, 0700), 'transfer_operation_creation_failed');
        $record = fopen($directory.'/receipt.jsonl', 'x+b');
        requirePost(is_resource($record) && flock($record, LOCK_EX | LOCK_NB), 'transfer_journal_unavailable');
        transferJournal($record, ['state'=>'intent_saved','operation'=>transferProfileOperation($next),
            'plan_sha256'=>$planDigest,'row'=>$row,'execution'=>$effects['context'] ?? []]);
        $reservation = $effects['reserve']();
        transferJournal($record, ['state'=>'identity_reserved','plan_sha256'=>$planDigest,
            'reservation'=>$reservation]);
        $prepared = $effects['prepare']($reservation);
        requirePost(is_array($prepared) && array_keys($prepared) === ['body','evidence']
            && is_array($prepared['body']) && is_array($prepared['evidence']), 'transfer_prepared_evidence_invalid');
        $body = $prepared['body'];
        $payload = ChannelPostApiTransport::hotelMediaPayload('-1001845835645', $row['target_message_id'], $body, $next);
        requirePost(is_array($payload), 'transfer_media_payload_invalid');
        transferJournal($record, ['state'=>'request_prepared','request'=>$payload,'evidence'=>$prepared['evidence'],
            'request_sha256'=>hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))]);
        $effects['runtime']();
        $effects['assert']($reservation);
        transferJournal($record, ['state'=>'provider_edit_started','provider_write_attempts'=>1]);
        $attempts = 1;
        // Exactly one call. No retry, alternate method, button worker or fallback.
        $response = $effects['request']($body);
        requirePost(is_array($response), 'transfer_provider_response_invalid');
        transferJournal($record, ['state'=>'provider_response_saved','response'=>$response]);
        $responseSaved = true;
        $effects['runtime']();
        if (($effects['context']['continuation_of_native_run_id'] ?? null) === ORIGINAL_NEXT_TRANSFER_RUN
            && transferMessageIdRejected($response)) {
            $result = $base + ['ok'=>false,'state'=>'provider_message_id_rejected','provider_write_attempts'=>1,
                'reserved_publication_id'=>$reservation['publication_id']];
            transferJournal($record, $result);
            return $result;
        }
        $saved = null;
        for ($read = 0; $read < 3; ++$read) {
            try {
                $saved = $effects['complete']($reservation, $response);
                break;
            } catch (RuntimeException $error) {
                // Re-read only a matching zero-write native observer claim.
                // The same saved response is used; the provider is never called again.
                if ($error->getMessage() !== 'hotel_transfer_native_claim_busy' || $read === 2) throw $error;
                usleep(200000);
            }
        }
        requirePost(is_array($saved) && $saved['state'] === 'sent'
            && $saved['message_id'] === $row['target_message_id']
            && (int)($saved['snapshot']['metadata']['hotel_catalog_id'] ?? 0) === $row['hotel_id'],
            'transfer_completion_unconfirmed');
        $effects['runtime']();
        $result = $base + ['ok'=>true,'state'=>'native_and_registry_verified',
            'provider_write_attempts'=>1,'publication_id'=>$saved['publication_id'],
            'version_id'=>$saved['version_id'],'content_hash'=>$saved['content_hash'],
            'button_url'=>ChannelPostButtonTransport::buttonUrl($saved),
            'hotel_id_verified'=>true,'photo_caption_button_verified'=>true,
            'original_message_id_and_date_preserved'=>true];
        transferJournal($record, $result);
        return $result;
    } catch (Throwable $error) {
        $result = $base + ['ok'=>false,'state'=>$attempts
            ? ($responseSaved ? 'response_saved_completion_requires_readback' : 'provider_outcome_unknown')
            : 'held_before_provider','reason'=>transferReason($error),'provider_write_attempts'=>$attempts];
        if (is_array($reservation)) $result['reserved_publication_id'] = $reservation['publication_id'] ?? null;
        if (is_resource($record)) {
            try { transferJournal($record, $result); } catch (Throwable $ignored) {}
        }
        return $result;
    } finally {
        if (is_resource($record)) fclose($record);
    }
}

function selectRemainingTransferRows(array $plan): array
{
    requirePost(($plan['operation'] ?? null) === NEXT_TRANSFER_OPERATION
        && array_column($plan['rows'], 'hotel_id') === [800,408,466]
        && array_column($plan['rows'], 'target_message_id') === ['1588','997','1101'],
        'transfer_remaining_scope_invalid');
    $plan['operation'] = REMAINING_TRANSFER_OPERATION;
    $plan['rows'] = array_slice($plan['rows'], 1);
    return $plan;
}

/** This exact saved negative answer proves that Telegram made no edit to the requested post. */
function transferMessageIdRejected(array $response): bool
{
    return ($response['http'] ?? null) === 400 && ($response['transport_error'] ?? null) === false
        && ($response['data']['ok'] ?? null) === false && ($response['data']['error_code'] ?? null) === 400
        && ($response['data']['description'] ?? null) === 'Bad Request: MESSAGE_ID_INVALID';
}

function requireOriginalNextTransferRejection(array $events, array $firstRow, string $digest): void
{
    requirePost(array_column($events, 'state') === ['intent_saved','identity_reserved','request_prepared',
        'provider_edit_started','provider_response_saved','response_saved_completion_requires_readback']
        && ($events[0]['operation'] ?? null) === NEXT_TRANSFER_OPERATION
        && ($events[0]['row'] ?? null) === $firstRow && $firstRow['hotel_id'] === 800
        && $firstRow['target_message_id'] === '1588' && ($events[0]['plan_sha256'] ?? null) === $digest
        && ($events[0]['execution']['native_run_id'] ?? null) === ORIGINAL_NEXT_TRANSFER_RUN
        && ($events[0]['execution']['application_sha'] ?? null) === ORIGINAL_NEXT_TRANSFER_APP
        && ($events[3]['provider_write_attempts'] ?? null) === 1
        && ($events[5]['provider_write_attempts'] ?? null) === 1
        && transferMessageIdRejected($events[4]['response'] ?? []), 'transfer_original_rejection_unconfirmed');
}

/** Read the original journal under the shared publisher lock. Leave it intact. */
function transferOriginalNextJournal(array $firstRow, string $digest): void
{
    $directory = POST_OPS.'/anytour-issue1566-historical-tg-media-1588-v1';
    $path = $directory.'/receipt.jsonl';
    requirePost(realpath($directory) === $directory && is_file($path) && !is_link($path), 'transfer_original_journal_unavailable');
    $raw = file_get_contents($path, false, null, 0, 262145);
    requirePost(is_string($raw) && strlen($raw) <= 262144, 'transfer_original_journal_unavailable');
    $events = array_map(static fn(string $line): array => json_decode($line, true, 64, JSON_THROW_ON_ERROR),
        explode("\n", rtrim($raw, "\r\n")));
    requireOriginalNextTransferRejection($events, $firstRow, $digest);
}

/** Only the explicit CLI branch below calls this; tick() remains unchanged. */
function applyTransferPlan(string $root, string $approvedDigest, string $applicationSha, string $nativeRunId, bool|string $next = false, bool $remaining = false): array
{
    requirePost($next === true || !$remaining, 'transfer_remaining_scope_invalid');
    $planFile = transferProfileFile($next);
    requirePost(preg_match('/\A[1-9][0-9]{0,19}\z/D', $nativeRunId) === 1, 'transfer_native_run_invalid');
    transferRuntime($root, $applicationSha);
    $raw = file_get_contents($root.'/'.$planFile, false, null, 0, 64001);
    requirePost(is_string($raw), 'transfer_plan_unavailable');
    $plan = transferPlanFromBytes($raw, $approvedDigest, $next);
    if (!$next) require_once $root.'/tools/direct_demand/operations/monitor-price-hook-contract.php';
    ob_start();
    try {
        require_once $root.'/config.php';
        require_once $root.'/services/ChannelPostRegistryService.php';
        require_once $root.'/services/ChannelPostButtonTransport.php';
        require_once $root.'/services/ChannelPostRuntime.php';
        require_once $root.'/integrations/ChannelPostApiTransport.php';
    } finally { ob_end_clean(); }
    requirePost(ProjectConfig::projectId() === 'anytour', 'transfer_project_changed');
    requirePost(realpath(POST_OPS) === POST_OPS, 'transfer_operations_root_changed');
    $leasePath = POST_OPS.'/monitor-price-after-collection-v1.jsonl';
    requirePost(is_file($leasePath) && !is_link($leasePath), 'transfer_price_lease_unknown');
    $priceLease = fopen($leasePath, 'rb');
    requirePost(is_resource($priceLease), 'transfer_price_lease_unavailable');
    $pdo = null; $locks = []; $results = []; $oldUmask = umask(0077);
    try {
        requirePost(flock($priceLease, LOCK_EX | LOCK_NB), 'transfer_content_editor_busy');
        if (is_string($next)) transferRankedOfferScope($plan['rows']);
        if ($remaining) {
            transferOriginalNextJournal($plan['rows'][0], $approvedDigest);
            $plan = selectRemainingTransferRows($plan);
        }
        $pdo = ConversationDb::connection();
        requirePost($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'transfer_native_database_required');
        $registry = new ChannelPostRegistryService($pdo);
        transferChannelRights($registry);
        foreach ($plan['rows'] as $row) {
            $operation = 'anytour-issue1566-historical-tg-media-'.$row['target_message_id'].'-v1';
            $q = $pdo->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$operation]);
            requirePost((int)$q->fetchColumn() === 1, 'transfer_target_editor_busy'); $q->closeCursor();
            $locks[] = $operation;
            requirePost(!file_exists(POST_OPS.'/'.$operation) && !is_link(POST_OPS.'/'.$operation),
                'transfer_previous_operation_do_not_replay');
            transferNoTarget($pdo, $row);
            transferSource($pdo, $registry, $row);
        }
        $export = null;
        if (!$next) {
            $export = json_decode(transferReadBytes($plan['monitor_source_url']), true, 64, JSON_THROW_ON_ERROR);
            requirePost(is_array($export), 'transfer_price_export_invalid');
        }
        // Validate every row before any identity reservation or publication change.
        foreach ($plan['rows'] as $row) {
            if (!$next) transferPrice($plan, $row, $export, time());
            transferPublicPreimage($row, transferPublic('any_toour', $row['source_message_id'], $next),
                transferPublic('anytour', $row['target_message_id'], $next));
        }
        transferRuntime($root, $applicationSha);
        foreach ($plan['rows'] as $row) {
            $registryRow = transferRegistryRow($row);
            $preparedExport = null;
            $runtime = static function () use ($root, $applicationSha, $approvedDigest, $planFile): void {
                transferRuntime($root, $applicationSha);
                requirePost(hash_file('sha256', $root.'/'.$planFile) === $approvedDigest,
                    'transfer_installed_plan_changed');
            };
            $effects = [
                'reserve'=>static function () use ($registry, $registryRow, $approvedDigest): array {
                    return $registry->reserveTelegramHotelTransfer($registryRow, $approvedDigest,
                        static fn(array $validated, string $digest): bool => $validated === $registryRow && $digest === $approvedDigest);
                },
                'prepare'=>static function (array $reservation) use ($pdo, $registry, $row, $plan, $next, &$preparedExport): array {
                    $source = transferSource($pdo, $registry, $row);
                    $sourcePublic = transferPublic('any_toour', $row['source_message_id'], $next);
                    $targetPublic = transferPublic('anytour', $row['target_message_id'], $next);
                    transferPublicPreimage($row, $sourcePublic, $targetPublic);
                    $evidence = ['source_public'=>$sourcePublic,'target_public'=>$targetPublic];
                    if (!$next) {
                        $freshBytes = transferReadBytes($plan['monitor_source_url']);
                        $preparedExport = json_decode($freshBytes, true, 64, JSON_THROW_ON_ERROR);
                        requirePost(is_array($preparedExport), 'transfer_price_export_invalid');
                        $evidence += ['monitor_source_url'=>$plan['monitor_source_url'],
                            'monitor_export_sha256'=>hash('sha256',$freshBytes),'monitor_export'=>$preparedExport,
                            'selected_price'=>transferPrice($plan, $row, $preparedExport, time())];
                    }
                    $body = ['media'=>['type'=>'photo','media'=>$source['snapshot']['attachments'][0]['telegram_file_id'],
                        'caption'=>$row['next_caption'],'caption_entities'=>[]],
                        'reply_markup'=>['inline_keyboard'=>[[['text'=>$row['button_text'],'url'=>$reservation['button_url']]]]]];
                    return ['body'=>$body,'evidence'=>$evidence];
                },
                'assert'=>static function (array $reservation) use ($registry, $registryRow, $approvedDigest, $row, $plan, $next, &$preparedExport): void {
                    if (!$next) {
                        requirePost(is_array($preparedExport), 'transfer_price_export_invalid');
                        transferPrice($plan, $row, $preparedExport, time());
                    }
                    if (is_string($next)) transferRankedOfferScope([$row]);
                    $registry->assertTelegramHotelTransferReservation($reservation, $registryRow, $approvedDigest);
                },
                'request'=>static fn(array $body): array => ChannelPostApiTransport::hotelMediaRequest(
                    '-1001845835645', $row['target_message_id'], $body, $next),
                'complete'=>static function (array $reservation, array $response) use ($registry, $registryRow, $approvedDigest, $row): array {
                    $saved = $registry->completeTelegramHotelTransfer($reservation, $registryRow, $approvedDigest, $response);
                    $resolved = $registry->resolveToken('anytour', 'telegram', 'anyru_bot', $saved['start_payload']);
                    requirePost(is_array($resolved) && $resolved['publication_id'] === $saved['publication_id']
                        && $resolved['version_id'] === $saved['version_id'] && $resolved['message_id'] === $row['target_message_id']
                        && (int)($resolved['snapshot']['metadata']['hotel_catalog_id'] ?? 0) === $row['hotel_id'],
                        'transfer_target_context_unconfirmed');
                    return $saved;
                },
                'runtime'=>$runtime,
                'context'=>['native_run_id'=>$nativeRunId,'application_sha'=>$applicationSha,
                    'native_preflight'=>$plan['native_preflight']]
                    + ($remaining ? ['continuation_of_native_run_id'=>ORIGINAL_NEXT_TRANSFER_RUN] : []),
            ];
            $result = transferExecuteRow($row, $approvedDigest, $effects, POST_OPS, $next);
            $results[] = $result;
            if (!$result['ok'] && (!$remaining || $result['state'] !== 'provider_message_id_rejected')) break;
        }
        return ['schema'=>1,'operation'=>$plan['operation'],'native_run_id'=>$nativeRunId,
            'application_sha'=>$applicationSha,'plan_sha256'=>$approvedDigest,
            'ok'=>count($results) === count($plan['rows']) && !in_array(false, array_column($results, 'ok'), true),
            'provider_write_attempts'=>array_sum(array_column($results, 'provider_write_attempts')),
            'supplier_requests'=>0,'bot_starts'=>0,'results'=>$results,'finished_at_utc'=>gmdate(DATE_ATOM)];
    } finally {
        if ($pdo instanceof PDO) {
            foreach ($locks as $name) {
                try { $q = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$name]); $q->closeCursor(); }
                catch (Throwable $ignored) {}
            }
        }
        fclose($priceLease); umask($oldUmask);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0'); ini_set('log_errors', '0');
    $exitCode = 2;
    $command = $argv[1] ?? '';
    $remaining = in_array($command, ['--check-remaining-transfer-plan','--apply-remaining-transfer'], true);
    $next = $remaining || in_array($command, ['--check-next-transfer-plan','--apply-next-transfer'], true);
    try {
        if (in_array($command, ['--check-ranked-transfer-plan','--apply-ranked-transfer'], true)) {
            $selected = $argv[2] ?? '';
            transferRankedProfile($selected);
            $next = $selected;
        }
        $root = dirname(__DIR__);
        if (count($argv) === 3 && in_array($command, ['--check-transfer-plan','--check-next-transfer-plan','--check-ranked-transfer-plan','--check-remaining-transfer-plan'], true)) {
            $planFile = transferProfileFile($next);
            $raw = file_get_contents($root.'/'.$planFile, false, null, 0, 64001);
            requirePost(is_string($raw), 'transfer_plan_unavailable');
            $plan = transferPlanFromBytes($raw, $argv[2], $next);
            if ($remaining) $plan = selectRemainingTransferRows($plan);
            $result = ['ok'=>true,'operation'=>$plan['operation'],'mode'=>'offline_plan_check',
                'production_writes'=>0,'provider_writes'=>0,'plan_sha256'=>hash('sha256',$raw),
                'rows'=>array_map(static fn(array $row): array => ['hotel_id'=>$row['hotel_id'],
                    'target_url'=>'https://t.me/anytour/'.$row['target_message_id'],
                    'caption_sha256'=>$row['next_caption_sha256']], $plan['rows'])];
        } elseif (count($argv) === 5 && in_array($command, ['--apply-transfer','--apply-next-transfer','--apply-ranked-transfer','--apply-remaining-transfer'], true)) {
            $result = applyTransferPlan($root, $argv[2], $argv[3], $argv[4], $next, $remaining);
        } else {
            throw new RuntimeException('transfer_explicit_command_required');
        }
        $exitCode = $result['ok'] ? 0 : 2;
    } catch (Throwable $error) {
        // A global failure may occur after a row was confirmed. Do not infer zero
        // writes from this summary; each original per-target journal is retained.
        $result = ['ok'=>false,'operation'=>$remaining ? REMAINING_TRANSFER_OPERATION : transferProfileOperation($next),'state'=>'requires_readback',
            'reason'=>transferReason($error)];
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    exit($exitCode);
}
