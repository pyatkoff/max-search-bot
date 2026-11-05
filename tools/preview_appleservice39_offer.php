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
$draft = RepairOfferDraft::prepare($project, $project->get('offer'));
$assetPath = dirname(__DIR__) . '/resources/project-examples/assets/' . $draft['publication']['image_asset'];
if (!is_file($assetPath) || hash_file('sha256', $assetPath) !== $draft['publication']['image_sha256']) {
    throw new RuntimeException('repair_offer_preview_image_mismatch');
}
echo json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
