<?php
declare(strict_types=1);

/** Compile inputs only. The caller authenticates the native artifact and the approved scope independently. */
function maxBatchInput(string $approvedBytes, string $approvedHash, string $archiveBytes, string $sourceBytes, string $sourceArtifactHash, string $sourceRef, string $release): array
{
    maxEditNeed(hash_equals(hash('sha256', $approvedBytes), $approvedHash), 'approval_content_changed');
    $scope = json_decode($approvedBytes, true, 32, JSON_THROW_ON_ERROR);
    maxEditNeed($scope['version'] === 1 && $scope['rows'] !== [], 'invalid_approved_scope');
    $archiveHash=hash('sha256', $archiveBytes);
    $selected=isset($scope['archive_selected_sha256']) && hash_equals($scope['archive_selected_sha256'],$archiveHash);
    maxEditNeed($selected || hash_equals($scope['archive_registry_sha256'],$archiveHash), 'native_archive_digest_changed');
    maxEditNeed(preg_match('/\A[a-f0-9]{40}\z/D', $release) === 1 && preg_match('/\A[a-f0-9]{40}\z/D', $sourceRef) === 1
        && hash_equals($sourceArtifactHash, hash('sha256', $sourceBytes)), 'native_source_digest_changed');
    $archive = json_decode($archiveBytes, true, 32, JSON_THROW_ON_ERROR);
    maxEditNeed(!$selected || ($archive['registry_json_sha256'] ?? null) === $scope['archive_registry_sha256'], 'native_archive_origin_changed');
    $source = json_decode($sourceBytes, true, 32, JSON_THROW_ON_ERROR);
    maxEditNeed(($archive['ok'] ?? false) === true && $archive['history_complete'] === true && $archive['price_content_shared_lease'] === true
        && $archive['native_run_id'] === $scope['archive_run_id'] && $archive['production_writes'] === 0 && $archive['provider_writes'] === 0, 'invalid_archive');
    $sourceOperation = $scope['native_source']['operation'] ?? 'max_seven_source_rights';
    maxEditNeed(in_array($sourceOperation, ['max_seven_source_rights', 'max_five_ranked_read_only', 'max_next_twelve_read_only', 'max_next_nine_read_only'], true), 'invalid_native_operation');
    maxEditNeed(($source['ok'] ?? false) === true && $source['operation'] === $sourceOperation
        && $source['application_sha'] === $release && $source['repository_sha'] === $sourceRef && $source['archive_run'] === (int)$scope['archive_run_id']
        && $source['provider_writes'] === 0 && $source['db_writes'] === 0 && $source['bot_starts'] === 0, 'invalid_native_evidence');
    if ($sourceOperation === 'max_next_twelve_read_only') {
        // The authenticated reader only includes groups after checking the editor right in each native response.
        $groups = $source['groups'] ?? [];
        maxEditNeed(count($groups) === 3, 'invalid_native_reader_groups');
        foreach ($groups as $group) {
            maxEditNeed(isset($group['read_utc']) && !isset($group['failure']) && count($group['ids'] ?? []) === 4, 'invalid_native_reader_group');
        }
        maxEditNeed(array_merge(...array_column($groups, 'ids')) === array_column($source['rows'], 'hotel_id'), 'invalid_native_reader_membership');
        $source['target_editor_rights'] = true;
    }
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
            $displayName = $row['display_name'] ?? $row['name'];
            maxEditNeed($displayName === $row['name'] || $displayName === str_replace(' & ', ' and ', $row['name']), 'invalid_display_hotel_name');
            $plan = ['kind'=>'expired_reviewed_photo_promotion', 'hotel'=>$id, 'hotel_name'=>$displayName,
                'target_mid'=>$row['target_mid'],'target_ms'=>$row['target_ms'],'target_url'=>$row['target_url'],
                'target_fingerprint'=>$row['target_fingerprint'], 'source_publication'=>$row['source_id'],
                'source_version'=>$observed['source_version'],'source_content_hash'=>$observed['source_content_sha256'],
                'source_mid'=>$row['source_mid'],'source_url'=>$row['source_url'],'caption'=>$caption];
            if ($displayName !== $row['name']) {
                $plan['source_aliases'] = [$row['name']];
            }
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
    $missing=$observed['blockers'] ?? null;
    $catalog=$observed['source_catalog_id'] ?? null;
    maxEditNeed(($observed['hotel_id'] ?? null) === $id && ($observed['source_id'] ?? null) === $row['source_id'] && ($observed['source_mid'] ?? null) === $row['source_mid']
        && ($observed['target_mid'] ?? null) === $row['target_mid'] && ($observed['target_url'] ?? null) === $row['target_url']
        && ($catalog === 0 || $catalog === $id) && ($observed['native_source_photo_count'] ?? null) === 1
        && is_int($observed['source_version'] ?? null) && $observed['source_version'] > 0, 'different_hotel_or_source');
    if ($catalog === 0) {
        $identity=[];
        foreach (['hotel_id','source_id','source_mid','native_source_date_ms','source_version','source_content_sha256','source_photo_sha256','source_caption_sha256','target_mid'] as $key) {
            $identity[]=$observed[$key] ?? null;
        }
        maxEditNeed(isset($row['original_legacy_identity_sha256']) && hash_equals($row['original_legacy_identity_sha256'],hash('sha256',maxEditJson($identity)))
            && $missing === ['source_hotel_id_matches','source_button_resolves_to_hotel']
            && ($observed['source_hotel_id_matches'] ?? null) === false && ($observed['source_button_resolves_to_hotel'] ?? null) === false
            && ($observed['source_name_in_native_caption'] ?? null) === true && ($observed['readable_source_hotel_identity'] ?? null) === true
            && ($observed['source_button_count'] ?? null) === 1 && ($observed['source_one_button_matches_url'] ?? null) === true
            && ($observed['source_button_resolves_publication'] ?? null) === true, 'legacy_publication_identity_changed');
    } else {
        maxEditNeed($missing === [] && ($observed['source_hotel_id_matches'] ?? null) === true
            && ($observed['source_button_resolves_to_hotel'] ?? null) === true, 'missing_native_proof');
    }
    foreach (['source_content_sha256','source_photo_sha256','source_caption_sha256'] as $field) {
        maxEditNeed(is_string($observed[$field]) && preg_match('/\A[a-f0-9]{64}\z/D', $observed[$field]) === 1, 'invalid_source_fingerprint');
    }
    foreach (['source_caption_nonempty','native_source_photo_reusable','source_caption_matches_registry','source_photo_matches_registry',
        'source_single_registry_button','target_matches_native_archive'] as $field) {
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
