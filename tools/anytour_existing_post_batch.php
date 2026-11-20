<?php
declare(strict_types=1);

namespace AnyTour\ExistingPostBatch;

use ChannelPostApiTransport;
use ChannelPostButtonTransport;
use ChannelPostEntryService;
use ChannelPostIncomingAdapter;
use ChannelPostRegistryService;
use ConversationDb;
use DOMDocument;
use DOMXPath;
use PDO;
use ProjectConfig;
use PromotedHotelPriceWriter;
use RuntimeException;
use Throwable;
const POST_OPS = '/var/www/anytoour/data/channel-post-operations';

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
    return ['text' => html_entity_decode(strip_tags(preg_replace('~<br\s*/?>~i', "\n", $inner)), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'buttons' => $buttons];
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

function editHotelExisting(PDO $pdo, ChannelPostRegistryService $registry, array $row): array
{
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
    $post = $registry->publication($id);
    requirePost(is_array($post) && $post['state'] === 'sent' && $post['project_key'] === 'anytour' && $post['platform'] === $platform
        && $post['channel_id'] === $channel && (int)$post['channel']['id'] === $channelRow
        && $post['snapshot']['post_url'] === $row['url'], 'expected_live_publication');
    $prior = $post['snapshot'];
    $journal = POST_OPS.'/issue-1566-edit-existing-'.$id.'.jsonl';
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
    if ($prior['text'] === $row['next'] && (int)$catalog === $row['hotel']
        && is_file($journal) && str_contains((string)file_get_contents($journal, false, null, 0, 8192), '"state":"public_verified"')) {
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
            && $liveButton['text'] !== '', 'telegram_public_button_changed');
        $buttonText = $liveButton['text'];
        $expectedButtons = [$liveButton];
    }

    $base = ['platform' => $platform, 'hotel_id' => $row['hotel'], 'publication_id' => $id, 'mid' => $mid, 'url' => $row['url']];
    if ($public['text'] === $row['next'] && $prior['text'] === $row['next'] && (int)$catalog === $row['hotel']) {
        return $base + ['ok' => true, 'state' => 'already_verified', 'caption_and_button_verified' => true];
    }
    requirePost(hash_equals($row['old_caption_sha256'], hash('sha256', $public['text']))
        && $prior['text'] === $public['text'], 'pinned_caption_changed');
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
        $write = static function (string $state) use ($record): void {
            $line = json_encode(['state'=>$state,'at'=>gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR).PHP_EOL;
            requirePost(fwrite($record, $line) === strlen($line) && fflush($record), 'post_journal_write_failed');
        };
        $write('provider_edit_started');
        $payload = $platform === 'max'
            ? ['text' => htmlspecialchars($row['next'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'format'=>'html']
            : ['caption'=>$row['next'], 'caption_entities'=>[], 'reply_markup'=>['inline_keyboard'=>[$expectedButtons]]];
        $at = (int)floor(microtime(true)*1000);
        $response = ChannelPostApiTransport::captionRequest($platform, $channel, $mid, $payload);
        if ($platform === 'max') {
            $after = readHotelMax($mid, $channel);
        } else {
            $message = $response['data']['result'] ?? [];
            requirePost($response['http'] === 200 && !$response['transport_error'] && ($response['data']['ok'] ?? false) === true
                && (string)($message['chat']['id'] ?? '') === $channel && (string)($message['message_id'] ?? '') === $mid
                && ($message['chat']['username'] ?? '') === 'anytour' && (int)($message['edit_date'] ?? 0) > 0, 'telegram_edit_response_unconfirmed');
            $at = (int)$message['edit_date']*1000;
            $after = ChannelPostIncomingAdapter::telegramSnapshot($message);
        }
        requirePost(is_array($after) && $after['post_url'] === $row['url'] && $after['text'] === $row['next']
            && postPhotoIds($after, $platform) === $physical && ChannelPostIncomingAdapter::containsOnlyButton($after, $button, $buttonText), 'provider_readback_unconfirmed');
        $after['metadata'] = array_replace($prior['metadata'], $after['metadata'], [
            'hotel_catalog_id'=>$row['hotel'], 'source_event_id'=>'issue-1566-existing:'.$id.':'.$at,
            'observation_kind'=>'authorized_admin_content_edit', 'source_revision_ms'=>(string)$at,
        ]);
        unset($after['metadata']['source_sequence'], $after['metadata']['source_sequence_scope']);
        $after['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($after, '', $post['channel']['bot_url']);
        $saved = $registry->registerPublication('anytour', $platform, $post['bot_key'], $channel, $mid, $after);
        requirePost(is_array($saved) && (int)$saved['publication_id'] === $id && $saved['start_payload'] === $post['start_payload']
            && $saved['snapshot']['text'] === $row['next'], 'registry_readback_unconfirmed');
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
                if ($seen['text'] === $row['next'] && ($platform === 'max' || $seen['buttons'] === $expectedButtons)) {
                    $write('public_verified');
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
    if (!is_int($row['hotel'] ?? null) || $row['hotel'] <= 0
        || !is_string($row['mid'] ?? null) || !is_string($row['url'] ?? null)
        || !preg_match('/\A[a-f0-9]{64}\z/D', $row['old_caption_sha256'] ?? '')
        || !is_string($row['next'] ?? null) || trim($row['next']) !== $row['next']
        || !mb_check_encoding($row['next'], 'UTF-8')
        || strlen(mb_convert_encoding($row['next'], 'UTF-16LE', 'UTF-8')) > 2048) {
        return false;
    }
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
    $previousUmask = umask(0077);
    try {
        foreach ($batch as $row) {
            try {
                $status = editHotelExisting($pdo, $registry, $row);
            } catch (Throwable $error) {
                $message = $error->getMessage();
                $status = ['ok'=>false, 'platform'=>$row['platform'], 'hotel_id'=>$row['hotel'], 'url'=>$row['url'],
                    'error'=>preg_match('/\A[a-z0-9_]{3,70}\z/D', $message) ? $message : 'verification_failed'];
            }
            $results[] = $status;
            if (in_array($status['state'] ?? '', ['edited_verified','edited_public_read_pending'], true)) {
                break;
            }
        }
    } finally {
        umask($previousUmask);
    }
    return $results;
}
