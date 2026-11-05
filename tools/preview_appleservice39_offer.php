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
$options = getopt('', ['catalog:', 'cover:'], $nextArgument);
if ($options === false || $nextArgument !== $argc || (isset($options['catalog']) && !is_string($options['catalog']))
    || (isset($options['cover']) && (!is_string($options['cover']) || !isset($options['catalog'])))) {
    fwrite(STDERR, 'Usage: php tools/preview_appleservice39_offer.php [--catalog /path/to/catalog.json [--cover /path/to/reviewed.png]]' . PHP_EOL);
    exit(2);
}
if (isset($options['catalog'])) {
    $path = $options['catalog'];
    if (!is_file($path) || !is_readable($path) || filesize($path) > 1048576) {
        throw new InvalidArgumentException('repair_offer_catalog_unavailable');
    }
    $json = file_get_contents($path, false, null, 0, 1048577);
    if ($json === false || strlen($json) > 1048576) throw new InvalidArgumentException('repair_offer_catalog_unavailable');
    $catalog = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($catalog)) throw new InvalidArgumentException('repair_offer_catalog_mismatch');
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
echo json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
