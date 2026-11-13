<?php
declare(strict_types=1);
require_once __DIR__.'/commercial-caption-review73-20261113-feed-preparation.php';
$tmp = getenv('RUNNER_TEMP'); caption_feed_need(is_string($tmp) && $tmp !== '', 'caption_temporary_directory_missing');
$private = $tmp.'/caption-repair-private'; $safe = $tmp.'/caption-repair-safe';
$plan = json_decode(file_get_contents(__DIR__.'/commercial-caption-review73-20261113-feed-plan.json'), true, 64, JSON_THROW_ON_ERROR);
$cohort = json_decode(file_get_contents($private.'/approved-cohort.json'), true, 128, JSON_THROW_ON_ERROR);
$capture = json_decode(file_get_contents($safe.'/capture.json'), true, 128, JSON_THROW_ON_ERROR);
caption_feed_need(($capture['ok'] ?? null) === true && ($capture['installed_sha'] ?? null) === $plan['current_installed_sha']
    && ($capture['operation'] ?? null) === $plan['operation'] && ($capture['locks_released_before_return'] ?? null) === true,
    'caption_capture_not_confirmed');
$manifests = [];
foreach ($plan['operations'] as $b) {
    $raw = file_get_contents($private.'/'.$b['name'].'.manifest.json');
    caption_feed_need(hash('sha256', $raw) === $b['manifest_sha256'], 'caption_manifest_binding_changed');
    $manifests[$b['operation']] = $raw;
}
$input = caption_feed_prepare_from_capture($cohort, $manifests, $capture, $plan['operation'], time(), $plan['current_installed_sha']);
$raw = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$compiled = caption_feed_compile($input, $plan['current_installed_sha'], file_get_contents(__DIR__.'/monitor-price-contract.php'),
    file_get_contents(__DIR__.'/commercial-caption-review73-20261113-feed-contract.php'), file_get_contents(__DIR__.'/publish-commercial-channel-hotel-feeds-20261103-1.php'));
caption_feed_need(!file_exists($safe.'/actual-feed-input.json') && !file_exists($private.'/writer.php'), 'caption_preparation_replay_stop');
caption_feed_need(file_put_contents($safe.'/actual-feed-input.json', $raw."\n") === strlen($raw)+1
    && file_put_contents($private.'/writer.php', $compiled) === strlen($compiled), 'caption_preparation_local_write_failed');
$summary = ['operation' => $input['operation'], 'schema_version' => 5, 'input_sha256' => hash('sha256', $raw),
    'saved_input_file_sha256' => hash('sha256', $raw."\n"), 'compiled_writer_sha256' => hash('sha256', $compiled),
    'current_installed_sha' => $plan['current_installed_sha'], 'generated_at' => $input['generated_at'], 'hotel_count' => 73,
    'caption_count' => 146, 'xml_target_occurrences' => 292,
    'files' => array_map(static fn(array $f): array => array_intersect_key($f, array_flip(['name','sha256','bytes','rows','expected_previous_sha256'])), $input['files']),
    'external_writes' => 0, 'caption_edits' => 0, 'price_changes' => 0, 'route_changes' => 0];
file_put_contents($safe.'/preparation.json', json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
