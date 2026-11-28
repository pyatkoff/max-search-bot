<?php
declare(strict_types=1);

use function AnyTour\ExistingPostBatch\{editHotelExisting, postPhotoIds, readHotelMax, validHotelEditPlan};

function ensure(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

$root = '/var/www/anytoour/data/www/app.anytoour.ru';
$ops = '/var/www/anytoour/data/channel-post-operations';
$mid = 'mid.ffffc1752b045fed01a0aa565299012a';
$url = 'https://max.ru/anytour/AaCqVlKZASo';
$channel = '-68765999669267';
$original = '281c0beaa2824a97bc68375413e685e3006993a6e63a0fa8da01e66d026677bd';
$button = '4cc564f912289321f30e690187a9f113f97676b05b41550892c519124655b414';
$lease = null;
$journal = $ops.'/issue-1566-edit-existing-3928.jsonl';
$state = 'held_before_write';

ob_start();
try {
    $input = json_decode(file_get_contents(__DIR__.'/request.json'), true, 8, JSON_THROW_ON_ERROR);
    $assertInstalled = static function () use ($root, $input): void {
        ensure(getcwd() === $root && trim((string)shell_exec('GIT_OPTIONAL_LOCKS=0 git rev-parse HEAD')) === $input['app'], 'installed_head_changed');
    };
    $lock = $ops.'/monitor-price-after-collection-v1.jsonl';
    ensure(is_file($lock) && !is_link($lock), 'price_lock_missing');
    $lease = fopen($lock, 'rb');
    ensure(is_resource($lease) && flock($lease, LOCK_EX | LOCK_NB), 'shared_editor_busy');
    $assertInstalled();
    foreach (['config.php', 'tools/anytour_existing_post_batch.php', 'services/PromotedHotelPriceWriter.php',
        'services/ChannelPostButtonTransport.php', 'services/ChannelPostEntryService.php',
        'integrations/ChannelPostIncomingAdapter.php', 'integrations/ChannelPostApiTransport.php'] as $file) {
        require_once $root.'/'.$file;
    }
    ensure(ProjectConfig::projectId() === 'anytour', 'wrong_project');
    ensure(!is_link($journal) && (!is_file($journal) || filesize($journal) === 0), 'previous_edit_needs_readback');
    $pdo = ConversationDb::connection();
    $registry = new ChannelPostRegistryService($pdo);
    $post = $registry->publication(3928);
    $snapshot = $post['snapshot'] ?? [];
    ensure(($post['state'] ?? null) === 'sent' && (int)($post['version_id'] ?? 0) === 18854
        && ($post['channel_id'] ?? null) === $channel && ($post['external_message_id'] ?? $post['message_id'] ?? null) === $mid
        && ($snapshot['post_url'] ?? null) === $url && (int)($snapshot['metadata']['hotel_catalog_id'] ?? 0) === 466,
        'original_publication_changed');
    $caption = $snapshot['text'] ?? '';
    ensure(is_string($caption) && hash_equals($original, hash('sha256', $caption)), 'original_caption_changed');
    $native = readHotelMax($mid, $channel);
    ensure($native['text'] === $caption && count(postPhotoIds($native, 'max')) === 1
        && postPhotoIds($native, 'max') === postPhotoIds($snapshot, 'max'), 'native_caption_or_photo_changed');
    $cta = ChannelPostButtonTransport::buttonUrl($post);
    ensure(hash_equals($button, hash('sha256', $cta))
        && ChannelPostIncomingAdapter::containsOnlyButton($native, $cta, $post['channel']['button_text']), 'button_changed');
    [$title] = explode("\n", $caption, 2);
    $heading = str_replace('Stella Makadi Beach Resort & Spa', 'Stella Makadi Beach Resort and Spa', $title, $changes);
    ensure($changes === 1 && !str_contains($heading, '&'), 'expected_heading_changed');
    $row = ['platform'=>'max', 'hotel'=>466, 'post'=>'3928', 'mid'=>$mid, 'url'=>$url,
        'button_sha'=>$button, 'old_caption_sha256'=>$original, 'next'=>$heading.substr($caption, strlen($title))];
    ensure(validHotelEditPlan($row), 'invalid_editor_input');
    $assertInstalled();
    $held = fstat($lease);
    clearstatcache(true, $lock);
    $current = lstat($lock);
    ensure(is_array($held) && is_array($current) && $held['ino'] === $current['ino'] && $held['dev'] === $current['dev'], 'editor_lock_changed');
    $observed = strtotime($input['observed_utc']);
    ensure($observed !== false && time() >= $observed && time() - $observed <= 720, 'route_receipt_stale');
    $state = 'correction_unconfirmed';
    $result = editHotelExisting($pdo, $registry, $row);
    $latest = $registry->publication(3928);
    $result['version_id'] = (int)($latest['version_id'] ?? 0);
    $result['caption_sha256'] = hash('sha256', $row['next']);
} catch (Throwable $e) {
    $reason = preg_match('/\A[a-z_]{3,80}\z/D', $e->getMessage()) ? $e->getMessage() : 'editor_error';
    clearstatcache(true, $journal);
    $result = ['ok'=>false, 'state'=>is_file($journal) && filesize($journal)>0 ? 'unknown_read_before_retry' : 'held_before_write',
        'reason'=>$reason];
} finally {
    if (is_resource($lease)) {
        flock($lease, LOCK_UN);
        fclose($lease);
    }
}
ob_end_clean();
echo 'MAX_STELLA_CAPTION_CORRECTION='.json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(empty($result['ok']) ? 1 : 0);
