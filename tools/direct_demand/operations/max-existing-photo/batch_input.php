<?php
declare(strict_types=1);

/** Compile inputs only. The caller authenticates the native artifact and the approved scope independently. */
function maxBatchInput(string $approvedBytes, string $approvedHash, string $archiveBytes, string $sourceBytes, string $sourceArtifactHash, string $sourceRef, string $release): array
{
    maxEditNeed(hash_equals(hash('sha256', $approvedBytes), $approvedHash), 'approval_content_changed');
    $scope = json_decode($approvedBytes, true, 32, JSON_THROW_ON_ERROR);
    maxEditNeed($scope['version'] === 1 && $scope['rows'] !== [], 'invalid_approved_scope');
    maxEditNeed(is_string($scope['archive_registry_sha256'] ?? null) && hash_equals($scope['archive_registry_sha256'], hash('sha256', $archiveBytes)), 'native_archive_digest_changed');
    maxEditNeed(preg_match('/\A[a-f0-9]{40}\z/D', $release) === 1 && preg_match('/\A[a-f0-9]{40}\z/D', $sourceRef) === 1
        && hash_equals($sourceArtifactHash, hash('sha256', $sourceBytes)), 'native_source_digest_changed');
    $archive = json_decode($archiveBytes, true, 32, JSON_THROW_ON_ERROR);
    $source = json_decode($sourceBytes, true, 32, JSON_THROW_ON_ERROR);
    maxEditNeed(($archive['ok'] ?? false) === true && $archive['history_complete'] === true && $archive['price_content_shared_lease'] === true
        && $archive['native_run_id'] === $scope['archive_run_id'] && $archive['production_writes'] === 0 && $archive['provider_writes'] === 0, 'invalid_archive');
    maxEditNeed(($source['ok'] ?? false) === true && $source['operation'] === 'max_seven_source_rights'
        && $source['application_sha'] === $release && $source['repository_sha'] === $sourceRef && $source['archive_run'] === (int)$scope['archive_run_id']
        && $source['provider_writes'] === 0 && $source['db_writes'] === 0 && $source['bot_starts'] === 0, 'invalid_native_evidence');
    maxEditNeed(($source['target_editor_rights'] ?? null) === true && (is_int($source['channel_config_version'] ?? null) || is_string($source['channel_config_version'] ?? null))
        && filter_var($source['channel_config_version'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) !== false, 'main_channel_edit_not_ready');
    $rows = $scope['rows'];
    foreach (['hotel_id','source_id','source_mid','source_url','target_mid','target_url'] as $field) {
        maxEditNeed(count(array_unique(array_column($rows, $field))) === count($rows), 'duplicate_or_missing_scope_key');
    }
    $targets = array_column($archive['rows'], null, 'mid');
    $native = array_column($source['rows'], null, 'hotel_id');
    maxEditNeed(count($targets) === count($archive['rows']) && count($native) === count($source['rows'])
        && array_diff(array_keys($native), array_column($rows, 'hotel_id')) === [], 'conflicting_evidence');
    $out = ['ready'=>[], 'held'=>[], 'provider_writes'=>0];
    foreach ($rows as $row) {
        $id = $row['hotel_id'];
        try {
            $target = $targets[$row['target_mid']] ?? null;
            maxEditNeed(is_array($target) && $target['registered'] === false && $target['media'] === ['image']
                && $target['keyboard_present'] === false && $target['linked_message'] === false
                && $target['published_ms'] === $row['target_ms'] && $target['url'] === $row['target_url']
                && hash_equals($row['target_fingerprint'], $target['fingerprint']), 'target_changed_or_occupied');
            $observed = $native[$id] ?? [];
            maxBatchValidateNativeRow($row, $observed);
            $caption = $row['caption_without_price'];
            $plan = ['kind'=>'expired_reviewed_photo_promotion', 'hotel'=>$id, 'hotel_name'=>$row['name'],
                'target_mid'=>$row['target_mid'],'target_ms'=>$row['target_ms'],'target_url'=>$row['target_url'],
                'target_fingerprint'=>$row['target_fingerprint'], 'source_publication'=>$row['source_id'],
                'source_version'=>$observed['source_version'],'source_content_hash'=>$observed['source_content_sha256'],
                'source_mid'=>$row['source_mid'],'source_url'=>$row['source_url'],'caption'=>$caption];
            $out['ready'][$id] = ['plan'=>$plan, 'sha256'=>hash('sha256', maxEditJson($plan)),
                'source_photo_sha256'=>$observed['source_photo_sha256'], 'source_caption_sha256'=>$observed['source_caption_sha256'],
                'config_version'=>(int)$source['channel_config_version'], 'approved_row'=>$row];
        } catch (RuntimeException $e) {
            $out['held'][$id] = $e->getMessage();
        }
    }
    return $out;
}

function maxBatchValidateNativeRow(array $row, array $observed): void
{
    $id = $row['hotel_id'];
    maxEditNeed(($observed['blockers'] ?? null) === [], 'native_source_or_target_unconfirmed');
    maxEditNeed($observed['source_id'] === $row['source_id'] && $observed['source_mid'] === $row['source_mid']
        && $observed['target_mid'] === $row['target_mid'] && $observed['target_url'] === $row['target_url']
        && $observed['source_catalog_id'] === $id && $observed['native_source_photo_count'] === 1
        && is_int($observed['source_version']) && $observed['source_version'] > 0, 'different_hotel_or_source');
    foreach (['source_content_sha256','source_photo_sha256','source_caption_sha256'] as $field) {
        maxEditNeed(is_string($observed[$field]) && preg_match('/\A[a-f0-9]{64}\z/D', $observed[$field]) === 1, 'invalid_source_fingerprint');
    }
    foreach (['source_caption_nonempty','native_source_photo_reusable','source_caption_matches_registry','source_photo_matches_registry',
        'source_hotel_id_matches','source_single_registry_button','source_button_resolves_to_hotel','target_matches_native_archive'] as $field) {
        maxEditNeed(($observed[$field] ?? null) === true, 'missing_native_proof');
    }
    foreach (['target_occupied','source_held','target_or_hotel_registry_conflicts'] as $field) {
        maxEditNeed(($observed[$field] ?? null) === 0, 'source_or_target_busy');
    }
}

/** Re-read under the common publisher lock. Compare check-time source, content and channel with the original native proof. */
function maxBatchVerifyCurrent(array $approval, array $current, int $channelVersion): void
{
    maxBatchValidateNativeRow($approval['approved_row'], $current);
    $plan = $approval['plan'];
    maxEditNeed(($current['hotel_id'] ?? null) === $plan['hotel'] && $current['source_version'] === $plan['source_version']
        && hash_equals($plan['source_content_hash'], $current['source_content_sha256'])
        && hash_equals($approval['source_photo_sha256'], $current['source_photo_sha256'])
        && hash_equals($approval['source_caption_sha256'], $current['source_caption_sha256'])
        && $channelVersion === $approval['config_version'], 'changed_since_native_review');
}
