<?php
declare(strict_types=1);
// Functions to integrate into the EXISTING preparation/read carrier; no entry point.
if (!function_exists('caption_feed_render')) require_once __DIR__.'/commercial-caption-batch16-20261113-feed-contract.php';

/** Read-only capture after the one finite18 caption operation completes. No journal contents are written. */
function caption_feed_capture(array $approvedOperations, ?string $feedOperation = null): array
{
    $counts = caption_feed_operation_counts(); $root = '/var/www/anytoour/data/channel-post-operations';
    $feedDir = '/var/www/anytoour/data/www/app.anytoour.ru/feed'; $locks = [];
    caption_feed_need(array_column($approvedOperations, 'operation') === array_keys($counts), 'caption_capture_scope_changed');
    caption_feed_need(!is_link($root) && realpath($root) === $root && !is_link($feedDir)
        && realpath($feedDir) === $feedDir, 'caption_capture_root_changed');
    try {
        $global = caption_feed_locked_jsonl($root.'/monitor-price-after-collection-v1.jsonl', 100000000, LOCK_EX, $locks);
        caption_feed_need(($global['state']['schema'] ?? null) === 1 && array_key_exists('pending', $global['state'])
            && $global['state']['pending'] === null, 'caption_monitor_pending_stop');
        if ($feedOperation !== null) caption_feed_unused_operation($global, $feedOperation);
        $states = []; $operations = [];
        $planFields = ['hotel_id', 'platform', 'publication_id', 'message_id', 'post_url', 'photo_signature',
            'button_url_sha256', 'version_id', 'old_text_sha256', 'new_text_sha256', 'old_text', 'new_text',
            'attachments_sha256', 'source_key'];
        $receiptFields = ['hotel_id', 'platform', 'publication_id', 'previous_version_id', 'version_id',
            'message_id', 'post_url', 'text_sha256', 'previous_text_sha256', 'photo_signature',
            'previous_attachments_sha256', 'attachments_sha256', 'button_url_sha256', 'button_text',
            'source_key', 'state', 'body_photo_button_verified', 'formatting_verified',
            'existing_message_reused', 'existing_token_resolves_to_current_version',
            'monitor_price_record_id', 'caption_correction_operation'];
        foreach ($approvedOperations as $b) {
            $op = $b['operation'];
            caption_feed_need(array_keys($b) === ['operation', 'manifest_sha256', 'installed_sha', 'row_count']
                && $b['row_count'] === $counts[$op], 'caption_capture_operation_invalid');
            $read = caption_feed_locked_jsonl($root.'/'.$op.'/receipt.jsonl', 134217728, LOCK_SH, $locks);
            $s = $read['state'];
            caption_feed_need(($s['operation'] ?? null) === $op && ($s['manifest_sha256'] ?? null) === $b['manifest_sha256']
                && ($s['installed_sha'] ?? null) === $b['installed_sha']
                && ($s['complete'] ?? null) === true && ($s['row_count'] ?? null) === $counts[$op]
                && is_array($s['posts'] ?? null) && count($s['posts']) === $counts[$op], 'caption_capture_incomplete');
            $safe = array_intersect_key($s, array_flip(['operation', 'manifest_sha256', 'installed_sha', 'complete', 'row_count']));
            $safe['posts'] = [];
            foreach ($s['posts'] as $key => $post) {
                caption_feed_need(($post['state'] ?? null) === 'confirmed' && is_array($post['plan'] ?? null)
                    && is_array($post['receipt'] ?? null), 'caption_capture_unconfirmed');
                $safe['posts'][$key] = ['state' => 'confirmed',
                    'plan' => array_intersect_key($post['plan'], array_flip($planFields)),
                    'receipt' => array_intersect_key($post['receipt'], array_flip($receiptFields))];
            }
            // No provider_response_private, token, button URL or old_metadata leaves the host.
            $states[$op] = $safe;
            $operations[] = ['operation' => $op, 'manifest_sha256' => $b['manifest_sha256'],
                'installed_sha' => $b['installed_sha'], 'journal_sha256' => $read['sha256'], 'row_count' => $counts[$op]];
        }
        $files = [];
        foreach (caption_feed_names() as $name) {
            $path = $feedDir.'/'.$name;
            caption_feed_need(!is_link($path) && is_file($path) && realpath($path) === $path, 'caption_preimage_path_changed');
            $before = lstat($path);
            caption_feed_need(is_array($before) && $before['size'] > 1000 && $before['size'] < 4000000, 'caption_preimage_size_invalid');
            $stream = fopen($path, 'rb'); caption_feed_need(is_resource($stream), 'caption_preimage_open_failed');
            try {
                $opened = fstat($stream); $bytes = stream_get_contents($stream, 4000001); $after = fstat($stream);
            } finally { fclose($stream); }
            clearstatcache(true, $path); $named = lstat($path);
            caption_feed_need(is_string($bytes) && strlen($bytes) === $before['size'] && is_array($opened)
                && is_array($after) && is_array($named) && !is_link($path) && realpath($path) === $path,
                'caption_preimage_read_failed');
            foreach ([$opened, $after, $named] as $stat) {
                caption_feed_need($stat['ino'] === $before['ino'] && $stat['dev'] === $before['dev']
                    && $stat['size'] === $before['size'], 'caption_preimage_changed_during_read');
            }
            caption_feed_xml($bytes);
            $files[] = ['name' => $name, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
                'content_base64' => base64_encode($bytes)];
        }
        return ['observed_at_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'files' => $files,
            'caption_operations' => $operations, 'caption_states' => $states,
            'feed_operation' => $feedOperation, 'monitor_journal_sha256' => $global['sha256'],
            'monitor_journal_unchanged' => true, 'locks_released_before_return' => true];
    } finally {
        foreach (array_reverse($locks) as $stream) if (is_resource($stream)) {
            caption_feed_need(flock($stream, LOCK_UN) && fclose($stream), 'caption_capture_lock_release_failed');
        }
    }
}

/** Resolve actual ids from fresh XML. A hotel/native/TV numeric id alone is not an admission. */
function caption_feed_prepare_from_capture(array $cohort, array $manifestBytes, array $capture, string $feedOperation, int $at, ?string $currentInstalled = null): array
{
    $counts = caption_feed_operation_counts(); $approved = []; $rowOperations = []; $cohortMap = [];
    $currentInstalled = $currentInstalled ?? $cohort['expected_installed_sha'];
    $operationSources = [];
    foreach ($capture['caption_operations'] ?? [] as $binding) $operationSources[$binding['operation']] = $binding;
    $stamp = $capture['observed_at_utc'] ?? null;
    caption_feed_need(is_string($stamp) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $stamp) === 1,
        'caption_capture_time_invalid');
    $observed = strtotime($stamp);
    caption_feed_need(is_int($observed) && gmdate('Y-m-d\TH:i:s\Z', $observed) === $stamp
        && $observed <= $at + 60 && $observed >= $at - 900, 'caption_capture_expired');
    caption_feed_need(array_keys($manifestBytes) === array_keys($counts) && count($cohort['rows'] ?? []) === 18,
        'caption_preparation_approved_scope_changed');
    foreach ($cohort['rows'] as $r) {
        $key = $r['platform'].':'.$r['publication_id']; caption_feed_need(!isset($cohortMap[$key]), 'caption_cohort_duplicate');
        $cohortMap[$key] = $r;
    }
    foreach ($manifestBytes as $op => $raw) {
        $m = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        caption_feed_need(($m['operation'] ?? null) === $op && ($m['row_count'] ?? null) === $counts[$op]
            && ($m['expected_installed_sha'] ?? null) === ($operationSources[$op]['installed_sha'] ?? null)
            && hash('sha256', $raw) === ($operationSources[$op]['manifest_sha256'] ?? null)
            && is_array($m['rows'] ?? null) && count($m['rows']) === $counts[$op], 'caption_manifest_changed');
        foreach ($m['rows'] as $r) {
            $key = $r['platform'].':'.$r['publication_id'];
            caption_feed_need(isset($cohortMap[$key]) && $cohortMap[$key] === $r && !isset($rowOperations[$key]), 'caption_manifest_cohort_mismatch');
            $rowOperations[$key] = $op;
        }
        $approved[$op] = hash('sha256', $raw);
    }
    caption_feed_need(count($rowOperations) === 18 && array_column($capture['files'] ?? [], 'name') === caption_feed_names(),
        'caption_capture_files_changed');
    $before = []; $parsed = [];
    foreach ($capture['files'] as $file) {
        $xml = base64_decode($file['content_base64'], true);
        caption_feed_need(is_string($xml) && strlen($xml) === $file['bytes'] && hash('sha256', $xml) === $file['sha256'],
            'caption_capture_file_hash_changed');
        $before[$file['name']] = $xml; $parsed[$file['name']] = caption_feed_xml($xml);
    }
    $bindings = [];
    foreach ($cohortMap as $key => $r) {
        $name = $r['platform'] === 'max' ? 'hotel-posts-max.xml' : 'hotel-posts-telegram.xml';
        $matches = [];
        foreach ($parsed[$name]['nodes'] as $id => $node) {
            $url = caption_feed_node_text($node, 'url');
            if ($r['platform'] === 'telegram') $match = str_starts_with($id, 'tg_') && $url === $r['post_url'];
            else {
                $alias = 'h1_'.substr(hash('sha256', 'anytour|'.$id.'|'.$r['post_url']), 0, 32);
                $context = json_encode(['post_id' => $alias, 'offer_id' => $id], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $expected = 'https://max.ru/id9704048781_2_bot?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_'
                    .rtrim(strtr(base64_encode($context), '+/', '-_'), '=');
                $match = str_starts_with($id, 'max_') && $url === $expected;
            }
            if ($match) $matches[] = ['id' => $id, 'url' => $url, 'node' => $node];
        }
        caption_feed_need(count($matches) === 1, 'caption_actual_offer_not_unique'); $found = $matches[0]; $id = $found['id'];
        $combined = $parsed['hotel-posts-all.xml']['nodes'][$id] ?? null;
        caption_feed_need($combined instanceof DOMElement && caption_feed_node_text($combined, 'url') === $found['url']
            && caption_feed_node_text($combined, 'custom_label_0') === $r['platform']
            && caption_feed_node_text($found['node'], 'custom_label_0') === $r['platform'], 'caption_combined_offer_mismatch');
        $region = null;
        $bindings[] = ['operation' => $rowOperations[$key], 'hotel_id' => $r['hotel_id'], 'site_id' => $r['site_id'],
            'platform' => $r['platform'], 'publication_id' => $r['publication_id'], 'message_id' => $r['message_id'],
            'post_url' => $r['post_url'], 'previous_version_id' => $r['version_id'],
            'previous_text_sha256' => $r['old_text_sha256'], 'text_sha256' => $r['new_text_sha256'],
            'photo_signature' => $r['photo_signature'], 'attachments_sha256' => $r['attachments_sha256'],
            'button_url_sha256' => $r['button_url_sha256'], 'offer_id' => $id, 'feed_url' => $found['url'], 'region_change' => $region];
    }
    foreach ($capture['caption_operations'] as $b) {
        caption_feed_need(($approved[$b['operation']] ?? null) === $b['manifest_sha256'], 'caption_captured_manifest_changed');
    }
    $updates = caption_feed_confirmed_updates($capture['caption_states'], $capture['caption_operations'], $bindings, $currentInstalled);
    return caption_feed_prepare($before, $updates, $capture['caption_operations'], $bindings, $feedOperation, $at);
}

/** Same compile pattern as prepare-monitor-price-feeds-20261111.php; caller saves to RUNNER_TEMP. */
function caption_feed_compile(array $input, string $installed, string $monitorContract, string $captionContract, string $writer): string
{
    $prefix = "<?php\ndeclare(strict_types=1);\n";
    foreach ([$monitorContract, $captionContract, $writer] as $source) caption_feed_need(str_starts_with($source, $prefix), 'caption_source_header_changed');
    caption_feed_need(preg_match('/\A[a-f0-9]{40}\z/D', $installed) === 1, 'caption_installed_sha_invalid');
    $raw = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $defines = ['EXPECTED_INSTALLED_SHA' => $installed, 'PUBLIC_STATIC_FEED_OPERATION' => $input['operation'],
        'PUBLIC_STATIC_FEED_INPUT_SHA256' => hash('sha256', $raw), 'PUBLIC_STATIC_FEED_INPUT_B64' => base64_encode($raw)];
    $header = '';
    foreach ($defines as $key => $value) $header .= 'const '.$key.'='.var_export($value, true).";\n";
    $body = substr($writer, strlen($prefix));
    $require = "require_once __DIR__.'/monitor-price-contract.php';";
    caption_feed_need(substr_count($body, $require) === 1, 'caption_writer_require_changed');
    $body = str_replace($require, '', $body);
    caption_feed_need(substr_count($body, "if(!function_exists('caption_feed_admit'))require_once __DIR__.'/caption-feed-contract.php';") === 1,
        'caption_conditional_dependency_changed');
    return $prefix.$header.substr($monitorContract, strlen($prefix))."\n".substr($captionContract, strlen($prefix))."\n".$body;
}
