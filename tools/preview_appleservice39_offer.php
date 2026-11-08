<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/services/ProjectConfig.php';
require_once dirname(__DIR__) . '/services/RepairOfferDraft.php';

$values = require dirname(__DIR__) . '/resources/project-examples/appleservice39.php';
$project = ProjectConfig::forProject('appleservice39', $values);
$offer = $project->get('offer');
$options = getopt('', ['catalog:', 'cover:', 'posts:', 'channel-id:'], $nextArgument);
if ($options === false || $nextArgument !== $argc
    || array_filter($options, static fn($option) => !is_string($option) || $option === '') !== []
    || (isset($options['cover']) && !isset($options['catalog']))
    || (isset($options['posts']) && !isset($options['cover']))
    || (isset($options['channel-id']) && !isset($options['posts']))) {
    fwrite(STDERR, 'Usage: php tools/preview_appleservice39_offer.php [--catalog catalog.json [--cover reviewed.png [--posts posts.json [--channel-id ID]]]]' . PHP_EOL);
    exit(2);
}
if (isset($options['catalog'])) {
    $catalog = readAppleServiceJson($options['catalog'], 'catalog');
    $offer = RepairOfferDraft::catalogItem($catalog, $offer);
}
$draft = RepairOfferDraft::prepare($project, $offer);
$assetPath = dirname(__DIR__) . '/resources/project-examples/assets/' . $draft['publication']['image_asset'];
if (isset($options['cover'])) {
    $cover = $project->get('reviewed_cover');
    $matches = array_values(array_filter($catalog['entries'], static fn($row) => is_array($row) && ($row['id'] ?? null) === $cover['diagnostics_entry_id']));
    $diagnostics = $matches[0] ?? [];
    if ($draft['price_rub'] !== $cover['price_rub'] || count($matches) !== 1
        || ($diagnostics['business_key'] ?? null) !== $draft['business_key'] || ($diagnostics['operation_key'] ?? null) !== 'diagnostics'
        || ($diagnostics['model_scope']['kind'] ?? null) !== 'model' || ($diagnostics['model_scope']['model_keys'] ?? null) !== ['iphone_x']
        || ($diagnostics['price']['kind'] ?? null) !== 'free' || ($diagnostics['price']['amount'] ?? null) !== 0
        || ($diagnostics['price']['raw'] ?? null) !== 'БЕСПЛАТНО'
        || !in_array($diagnostics['source_url'] ?? null, array_column($draft['source_rows'], 'url'), true)
        || ($diagnostics['review_flags'] ?? null) !== []) {
        throw new InvalidArgumentException('repair_offer_reviewed_cover_terms_changed');
    }
    $assetPath = $options['cover'];
    $offer['image_asset'] = $cover['name'];
    $offer['image_sha256'] = $cover['sha256'];
    $draft = RepairOfferDraft::prepare($project, $offer);
}
if (!is_file($assetPath) || !is_readable($assetPath) || filesize($assetPath) > 3145728
    || hash_file('sha256', $assetPath) !== $draft['publication']['image_sha256']) {
    throw new RuntimeException('repair_offer_preview_image_mismatch');
}
$exitStatus = 0;
if (isset($options['posts'])) {
    $pilot = $project->get('pilot');
    $posts = readAppleServiceJson($options['posts'], 'posts');
    if (($posts['schema_version'] ?? null) !== 1 || ($posts['project_key'] ?? null) !== $project->projectId()
        || !is_array($posts['posts'] ?? null)) {
        throw new InvalidArgumentException('repair_offer_posts_mismatch');
    }
    $matches = array_values(array_filter($posts['posts'], static fn($post) => is_array($post)
        && ($post['id'] ?? null) === $pilot['post_id']));
    $post = $matches[0] ?? [];
    $paragraphs = $post['body'] ?? null;
    $title = $post['title'] ?? null;
    if (!is_string($title) || !is_array($paragraphs) || !array_is_list($paragraphs)
        || array_filter($paragraphs, 'is_string') !== $paragraphs) {
        throw new InvalidArgumentException('repair_offer_pilot_text_mismatch');
    }
    $caption = implode("\n\n", [$title, ...$paragraphs]) . "\n";
    $evidence = array_column(array_column($draft['source_rows'], 'catalog_ref'), 'entry_id');
    $postEvidence = $post['source_ids'] ?? null;
    if (!is_array($postEvidence)) throw new InvalidArgumentException('repair_offer_pilot_evidence_mismatch');
    sort($evidence);
    sort($postEvidence);
    if (count($matches) !== 1 || ($post['phase'] ?? null) !== 'pilot'
        || ($post['kind'] ?? null) !== 'repair' || ($post['scope']['model_keys'] ?? null) !== ['iphone_x']
        || ($post['service'] ?? null) !== 'battery_replacement' || ($post['price_kind'] ?? null) !== 'listed'
        || ($post['amount_rub'] ?? null) !== $draft['price_rub'] || $postEvidence !== $evidence
        || ($post['button_label'] ?? null) !== $draft['publication']['button_text']
        || ($post['cover_kind'] ?? null) !== 'approved_asset'
        || hash('sha256', $caption) !== $pilot['text_sha256']) {
        throw new InvalidArgumentException('repair_offer_pilot_posts_mismatch');
    }
    $draft['pilot'] = ['post_id' => $pilot['post_id'], 'caption' => $caption,
        'channel_url' => $pilot['channel_url'], 'bot_url' => $pilot['bot_url'],
        'provider_check' => ['state' => 'not_checked', 'reason' => 'channel_id_not_supplied']];
    if (isset($options['channel-id'])) {
        require_once dirname(__DIR__) . '/services/ChannelPostRegistryService.php';
        require_once dirname(__DIR__) . '/services/ChannelPostButtonTransport.php';
        $channelId = ChannelPostRegistryService::canonicalChannelId('max', $options['channel-id']);
        $token = getenv('APPLESERVICE39_MAX_TOKEN');
        $check = ['state' => 'not_checked', 'reason' => 'apple_service_token_unavailable'];
        if (is_string($token) && $token !== '' && !preg_match('/[\x00-\x20\x7f]/', $token) && !defined('MAX_SEARCH_TOKEN')) {
            define('MAX_SEARCH_TOKEN', $token);
            $check = (new ChannelPostButtonTransport())->verifyChannel([
                'platform' => 'max', 'channel_id' => $channelId, 'bot_key' => $pilot['bot_key'],
                'bot_url' => $pilot['bot_url'], 'expected_public_url' => $pilot['channel_url'],
            ]);
        }
        $draft['pilot']['provider_check'] = $check;
        if ($check['state'] !== 'ready') $exitStatus = 3;
    }
}
echo json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($exitStatus);

function readAppleServiceJson(string $path, string $kind): array
{
    if (!is_file($path) || !is_readable($path) || filesize($path) > 1048576) {
        throw new InvalidArgumentException('repair_offer_' . $kind . '_unavailable');
    }
    $json = file_get_contents($path, false, null, 0, 1048577);
    if ($json === false || strlen($json) > 1048576) throw new InvalidArgumentException('repair_offer_' . $kind . '_unavailable');
    $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new InvalidArgumentException('repair_offer_' . $kind . '_mismatch');
    return $decoded;
}
