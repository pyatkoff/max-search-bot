<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');

/** No credentials, paths, payloads, message IDs or arbitrary commands are inputs. */
function applePilotCheckout(string $root, string $expected): bool
{
    $run = static function (array $args): ?string {
        $pipes = [];
        $process = proc_open($args, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
        if (!is_resource($process)) return null;
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        return proc_close($process) === 0 && is_string($output) ? trim($output) : null;
    };
    return realpath($root) === '/var/www/anytoour/data/www/app.anytoour.ru'
        && $run(['git','-C',$root,'rev-parse','HEAD']) === $expected
        && $run(['git','-C',$root,'status','--porcelain','--untracked-files=no']) === '';
}

try {
    $mode = $argv[1] ?? '';
    if ($mode === '--help' && $argc === 2) {
        echo "php tools/publish_appleservice39_pilot.php inspect|prepare|publish|finalize|pin|reconcile|landing_inspect|landing_prepare|landing_apply|landing_reconcile --expected-sha=<accepted application SHA> --channel-id=<exact negative numeric ID>\n";
        exit(0);
    }
    if (!in_array($mode, ['inspect','prepare','publish','finalize','pin','reconcile','landing_inspect','landing_prepare','landing_apply','landing_reconcile'], true) || $argc !== 4) throw new RuntimeException('fixed_operation_and_target_required');
    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if (!preg_match('/\A--(expected-sha|channel-id)=(.*)\z/D', $argument, $match) || isset($options[$match[1]])) throw new RuntimeException('fixed_operation_and_target_required');
        $options[$match[1]] = $match[2];
    }
    if (!preg_match('/\A[a-f0-9]{40}\z/D', $options['expected-sha'] ?? '')
        || !preg_match('/\A-[1-9][0-9]{0,18}\z/D', $options['channel-id'] ?? '')) throw new RuntimeException('fixed_operation_and_target_required');
    $root = dirname(__DIR__); $expected = $options['expected-sha'];
    if (!applePilotCheckout($root, $expected)) throw new RuntimeException('installed_checkout_changed');
    $private = '/var/www/anytoour/data/config/max-search.php';
    if (!is_file($private) || is_link($private)) throw new RuntimeException('protected_connection_unavailable');
    $privateHash = hash_file('sha256', $private);
    if (!is_string($privateHash)) throw new RuntimeException('protected_connection_unavailable');
    require_once $root.'/config.php';
    require_once $root.'/services/ConversationDb.php';
    require_once $root.'/services/AppleService39PilotPublisher.php';
    $manifest = AppleService39PilotManifest::installed();
    $provenance = static function () use ($root,$expected,$private,$privateHash): bool {
        clearstatcache(true, $private);
        return is_file($private) && !is_link($private) && hash_file('sha256', $private) === $privateHash
            && applePilotCheckout($root, $expected);
    };
    $publisher = new AppleService39PilotPublisher(ConversationDb::connection(), $manifest, $options['channel-id'],
        $provenance, null, null, new AppleService39CatalogueRevision($manifest));
    $result = $publisher->run($mode);
    $result['application_sha'] = $expected;
    echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $error) {
    $allowed = ['fixed_operation_and_target_required','installed_checkout_changed','protected_connection_unavailable',
        'pilot_connection_unavailable','pilot_connection_changed','pilot_connection_binding_mismatch','publication_authority_changed',
        'active_project_admin_and_source_required','canonical_bot_identity_mismatch','provider_bot_identity_changed',
        'public_channel_identity_mismatch','channel_admin_required','channel_publish_edit_read_permissions_required','pilot_requires_autocommit',
        'channel_pin_permission_required','campaign_binding_changed','pilot_operation_changed','pilot_operation_held',
        'pilot_upload_held','pilot_create_held','pilot_edit_held','pilot_pin_held','prepare_images_first',
        'all_pilot_posts_required','all_pilot_links_required','post_readback_mismatch','post_attachment_mismatch',
        'post_image_mismatch','post_button_mismatch','post_url_changed','native_post_url_required',
        'landing_existing_campaign_required','landing_menu_pin_changed','landing_edit_held','landing_original_receipts_required',
        'landing_revision_changed','landing_revision_unavailable','landing_revision_identity_mismatch','landing_exact_posts_required',
        'landing_post_invalid','landing_entry_invalid','landing_reviewed_price_required','landing_verified_link_required',
        'landing_post_too_long','landing_original_image_required','landing_plain_text_required',
        'landing_artwork_manifest_changed','landing_artwork_catalog_changed','landing_artwork_changed',
        'landing_upload_held','landing_upload_receipt_required','landing_images_not_prepared',
        'landing_native_image_evidence_required','invalid_landing_image_receipt','image_receipt_storage_unknown',
        'landing_review_index_unavailable','landing_review_evidence_unavailable','landing_review_evidence_changed','landing_review_evidence_mismatch',
        'provider_read_unknown','approved_image_changed','approved_image_invalid','resolved_post_too_long',
        'catalogue_revision_unavailable','catalogue_revision_invalid','catalogue_parent_changed','catalogue_parent_incomplete',
        'catalogue_source_changed','catalogue_source_invalid','catalogue_posts_changed','catalogue_post_invalid',
        'catalogue_links_changed','catalogue_reviewed_price_changed','catalogue_price_projection_changed',
        'catalogue_revision_changed','catalogue_image_changed','catalogue_edit_held'];
    $reason = in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'pilot_operation_unavailable';
    echo json_encode(['ok'=>false,'state'=>'stopped','reason'=>$reason,'automatic_retry'=>false], JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(2);
}
