<?php
declare(strict_types=1);
// Draft library for the EXISTING static feed writer/preparer. No top-level action.
// Its only writes are returned strings. Admission reads original journals/registry.

function caption_feed_need(bool $ok, string $why): void
{
    if (!$ok) throw new RuntimeException($why);
}

function caption_feed_names(): array
{
    return ['hotel-posts-telegram.xml', 'hotel-posts-max.xml', 'hotel-posts-all.xml'];
}

function caption_feed_operation_counts(): array
{
    return ['commercial-caption-batch16-20261113-edit-v1' => 18];
}

/** A binding is derived from approved-cohort + actual feed offers, never a name match. */
function caption_feed_bindings(array $rows): array
{
    caption_feed_need(array_is_list($rows) && count($rows) === 18, 'caption_binding_scope_changed');
    caption_feed_need(array_column($rows, 'publication_id') === [1354, 1345, 1355, 1346, 1356, 1347, 1357, 1348, 1358, 1349, 1359, 1350, 1360, 1351, 1361, 1352, 1362, 1353], 'caption_exact_publication_scope_changed');
    $out = []; $pairs = []; $offers = []; $counts = array_fill_keys(array_keys(caption_feed_operation_counts()), 0);
    $keys = ['operation', 'hotel_id', 'site_id', 'platform', 'publication_id', 'message_id', 'post_url',
        'previous_version_id', 'previous_text_sha256', 'text_sha256', 'photo_signature',
        'attachments_sha256', 'button_url_sha256', 'offer_id', 'feed_url', 'region_change'];
    foreach ($rows as $r) {
        caption_feed_need(is_array($r) && array_keys($r) === $keys, 'caption_binding_schema_changed');
        $platform = $r['platform'];
        caption_feed_need(in_array($platform, ['max', 'telegram'], true) && isset($counts[$r['operation']]), 'caption_binding_operation_changed');
        foreach (['hotel_id', 'publication_id', 'previous_version_id'] as $key) {
            caption_feed_need(is_int($r[$key]) && $r[$key] > 0, 'caption_binding_identity_invalid');
        }
        caption_feed_need(is_string($r['site_id']) && preg_match('/\A[a-f0-9-]{36}\z/D', $r['site_id']) === 1
            && is_string($r['message_id']) && $r['message_id'] !== '', 'caption_binding_identity_invalid');
        foreach (['previous_text_sha256', 'text_sha256', 'attachments_sha256', 'button_url_sha256'] as $key) {
            caption_feed_need(is_string($r[$key]) && preg_match('/\A[a-f0-9]{64}\z/D', $r[$key]) === 1, 'caption_binding_hash_invalid');
        }
        caption_feed_need(is_string($r['photo_signature']) && str_starts_with($r['photo_signature'], $platform.':'), 'caption_binding_photo_invalid');
        $prefix = $platform === 'max' ? 'max_' : 'tg_';
        caption_feed_need(is_string($r['offer_id']) && preg_match('/\A'.preg_quote($prefix, '/').'[A-Za-z0-9_-]+\z/D', $r['offer_id']) === 1,
            'caption_binding_offer_invalid');
        if ($platform === 'telegram') {
            caption_feed_need(preg_match('/\A[1-9][0-9]*\z/D', $r['message_id']) === 1
                && $r['post_url'] === 'https://t.me/any_toour/'.$r['message_id']
                && $r['feed_url'] === $r['post_url'], 'caption_binding_telegram_url_changed');
        } else {
            caption_feed_need(is_string($r['post_url']) && preg_match('~\Ahttps://max\.ru/channel_anytour/[A-Za-z0-9_-]+\z~D', $r['post_url']) === 1,
                'caption_binding_max_post_invalid');
            $alias = 'h1_'.substr(hash('sha256', 'anytour|'.$r['offer_id'].'|'.$r['post_url']), 0, 32);
            $context = json_encode(['post_id' => $alias, 'offer_id' => $r['offer_id']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $url = 'https://max.ru/id9704048781_2_bot?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_'
                .rtrim(strtr(base64_encode($context), '+/', '-_'), '=');
            caption_feed_need($r['feed_url'] === $url, 'caption_binding_max_route_changed');
        }
        caption_feed_need($r['region_change'] === null, 'caption_region_edit_not_authorized');
        $key = $platform.':'.$r['publication_id']; $pair = $r['hotel_id'].':'.$platform;
        caption_feed_need(!isset($out[$key]) && !isset($offers[$r['offer_id']]) && !isset($pairs[$pair]), 'caption_binding_duplicate');
        $out[$key] = $r; $pairs[$pair] = $r['site_id']; $offers[$r['offer_id']] = true; $counts[$r['operation']]++;
    }
    caption_feed_need($counts === caption_feed_operation_counts(), 'caption_operation_counts_changed');
    $hotels = array_unique(array_column($rows, 'hotel_id'));
    caption_feed_need(array_values($hotels) === [81358, 343, 432, 65254, 443, 107940, 32356, 195, 20288], 'caption_hotel_count_changed');
    foreach ($hotels as $hotel) {
        caption_feed_need(isset($pairs[$hotel.':max'], $pairs[$hotel.':telegram'])
            && $pairs[$hotel.':max'] === $pairs[$hotel.':telegram'], 'caption_pair_incomplete');
    }
    return $out;
}

/** Same pure validator in offline preparation and host admission. */
function caption_feed_confirmed_updates(array $states, array $operations, array $rows, string $installed): array
{
    $bindings = caption_feed_bindings($rows); $counts = caption_feed_operation_counts();
    caption_feed_need(preg_match('/\A[a-f0-9]{40}\z/D', $installed) === 1, 'caption_current_source_invalid');
    caption_feed_need(array_is_list($operations) && array_column($operations, 'operation') === array_keys($counts)
        && array_keys($states) === array_keys($counts), 'caption_journal_scope_changed');
    $updates = [];
    foreach ($operations as $binding) {
        caption_feed_need(array_keys($binding) === ['operation', 'manifest_sha256', 'installed_sha', 'journal_sha256', 'row_count'], 'caption_journal_binding_invalid');
        caption_feed_need(is_string($binding['installed_sha']) && preg_match('/\A[a-f0-9]{40}\z/D', $binding['installed_sha']) === 1,
            'caption_original_source_invalid');
        foreach (['manifest_sha256', 'journal_sha256'] as $field) {
            caption_feed_need(is_string($binding[$field]) && preg_match('/\A[a-f0-9]{64}\z/D', $binding[$field]) === 1,
                'caption_journal_hash_invalid');
        }
        $op = $binding['operation']; $state = $states[$op];
        caption_feed_need($binding['row_count'] === $counts[$op] && ($state['operation'] ?? null) === $op
            && ($state['manifest_sha256'] ?? null) === $binding['manifest_sha256']
            && ($state['installed_sha'] ?? null) === $binding['installed_sha'] && ($state['complete'] ?? null) === true
            && ($state['row_count'] ?? null) === $counts[$op] && is_array($state['posts'] ?? null)
            && count($state['posts']) === $counts[$op], 'caption_journal_not_complete');
        foreach ($state['posts'] as $key => $post) {
            $r = $bindings[$key] ?? null; $plan = $post['plan'] ?? null; $receipt = $post['receipt'] ?? null;
            caption_feed_need(is_array($r) && $r['operation'] === $op && !isset($updates[$key])
                && ($post['state'] ?? null) === 'confirmed' && is_array($plan) && is_array($receipt), 'caption_confirmed_post_missing');
            foreach (['hotel_id', 'platform', 'publication_id', 'message_id', 'post_url', 'photo_signature', 'button_url_sha256'] as $field) {
                caption_feed_need(($plan[$field] ?? null) === $r[$field] && ($receipt[$field] ?? null) === $r[$field], 'caption_receipt_identity_changed');
            }
            caption_feed_need(($plan['version_id'] ?? null) === $r['previous_version_id']
                && ($receipt['previous_version_id'] ?? null) === $r['previous_version_id']
                && is_int($receipt['version_id'] ?? null) && $receipt['version_id'] > 0
                && $receipt['version_id'] !== $r['previous_version_id']
                && ($plan['old_text_sha256'] ?? null) === $r['previous_text_sha256']
                && ($receipt['previous_text_sha256'] ?? null) === $r['previous_text_sha256']
                && ($plan['new_text_sha256'] ?? null) === $r['text_sha256']
                && ($receipt['text_sha256'] ?? null) === $r['text_sha256']
                && is_string($plan['old_text'] ?? null) && hash('sha256', $plan['old_text']) === $r['previous_text_sha256']
                && is_string($plan['new_text'] ?? null) && hash('sha256', $plan['new_text']) === $r['text_sha256'], 'caption_receipt_text_version_changed');
            caption_feed_need(($plan['attachments_sha256'] ?? null) === $r['attachments_sha256']
                && ($receipt['previous_attachments_sha256'] ?? null) === $r['attachments_sha256']
                && is_string($receipt['attachments_sha256'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/D', $receipt['attachments_sha256']) === 1
                && ($receipt['state'] ?? null) === 'sent' && ($receipt['body_photo_button_verified'] ?? null) === true
                && ($receipt['formatting_verified'] ?? null) === true && ($receipt['existing_message_reused'] ?? null) === true
                && ($receipt['existing_token_resolves_to_current_version'] ?? null) === true, 'caption_receipt_preservation_missing');
            $source = $r['platform'] === 'max' ? 'max_anytour_msk1' : 'tg_anytour_msk';
            caption_feed_need(($plan['source_key'] ?? null) === $source && ($receipt['source_key'] ?? null) === $source
                && ($receipt['button_text'] ?? null) === '✈️ Узнать стоимость тура', 'caption_receipt_source_changed');
            $updates[$key] = $r + ['text' => $plan['new_text'], 'confirmed_version_id' => $receipt['version_id'],
                'confirmed_attachments_sha256' => $receipt['attachments_sha256']];
        }
    }
    caption_feed_need(count($updates) === 18, 'caption_confirmed_scope_incomplete');
    return array_values($updates);
}

/** Existing rb file, existing lock; nothing is created, appended, or modified. */
function caption_feed_locked_jsonl(string $path, int $cap, int $lockKind, array &$locks): array
{
    caption_feed_need(!is_link(dirname($path)) && realpath(dirname($path)) === dirname($path)
        && !is_link($path) && is_file($path) && realpath($path) === $path, 'caption_journal_path_invalid');
    $before = lstat($path); $stream = fopen($path, 'rb');
    caption_feed_need(is_array($before) && is_resource($stream), 'caption_journal_open_failed');
    $locks[] = $stream;
    caption_feed_need(flock($stream, $lockKind | LOCK_NB), 'caption_existing_operation_busy');
    $locked = fstat($stream);
    caption_feed_need(is_array($locked) && $locked['ino'] === $before['ino'] && $locked['dev'] === $before['dev']
        && ($locked['mode'] & 0170000) === 0100000 && $locked['size'] > 0 && $locked['size'] <= $cap,
        'caption_journal_identity_changed');
    $hash = hash_init('sha256'); $bytes = 0; $last = null; $feedOperations = [];
    while (!feof($stream)) {
        $line = fgets($stream, 16777218);
        if ($line === false) { caption_feed_need(feof($stream), 'caption_journal_read_failed'); break; }
        $bytes += strlen($line);
        caption_feed_need($bytes <= $cap && strlen($line) <= 16777216 && str_ends_with($line, "\n"), 'caption_journal_incomplete');
        hash_update($hash, $line); $last = $line;
        preg_match_all('/"(commercial-channel-hotel-feeds-[0-9]{8}-update[1-9][0-9]*)"/', $line, $foundOperations);
        foreach ($foundOperations[1] as $op) $feedOperations[$op] = true;
    }
    clearstatcache(true, $path); $after = fstat($stream); $named = lstat($path);
    caption_feed_need(is_array($after) && is_array($named) && $bytes === $locked['size']
        && $after['ino'] === $locked['ino'] && $after['dev'] === $locked['dev'] && $after['size'] === $locked['size']
        && $named['ino'] === $locked['ino'] && $named['dev'] === $locked['dev'] && $named['size'] === $locked['size']
        && !is_link($path) && realpath($path) === $path && is_string($last), 'caption_journal_changed_during_read');
    $state = json_decode($last, true, 128, JSON_THROW_ON_ERROR);
    caption_feed_need(is_array($state), 'caption_journal_state_invalid');
    return ['state' => $state, 'sha256' => hash_final($hash), 'bytes' => $bytes, 'feed_operations_seen' => array_keys($feedOperations)];
}

function caption_feed_unused_operation(array $globalRead, string $operation): void
{
    caption_feed_need(preg_match('/\Acommercial-channel-hotel-feeds-[0-9]{8}-update[1-9][0-9]*\z/D', $operation) === 1
        && !in_array($operation, $globalRead['feed_operations_seen'] ?? [], true), 'caption_feed_operation_previously_used');
    $private = '/var/www/anytoour/data/channel-post-operations/'.$operation;
    caption_feed_need(!file_exists($private) && !is_link($private), 'caption_feed_prior_operation_requires_reconciliation');
    foreach (caption_feed_names() as $name) {
        $temp = '/var/www/anytoour/data/www/app.anytoour.ru/feed/.'.$operation.'-'.$name.'.tmp';
        caption_feed_need(!file_exists($temp) && !is_link($temp), 'caption_feed_prior_attempt_requires_reconciliation');
    }
}

/** Host admission: shared original receipts plus the already-existing nightly EX lock. */
function caption_feed_admit(array $input, string $installed, array &$locks): array
{
    $root = '/var/www/anytoour/data/channel-post-operations';
    caption_feed_need(!is_link($root) && realpath($root) === $root, 'caption_private_root_changed');
    $global = caption_feed_locked_jsonl($root.'/monitor-price-after-collection-v1.jsonl', 100000000, LOCK_EX, $locks);
    caption_feed_need(($global['state']['schema'] ?? null) === 1 && array_key_exists('pending', $global['state'])
        && $global['state']['pending'] === null, 'caption_monitor_pending_stop');
    caption_feed_unused_operation($global, $input['operation']);
    $operations = $input['caption_operations'];
    caption_feed_need(is_array($operations) && array_column($operations, 'operation') === array_keys(caption_feed_operation_counts()), 'caption_journal_scope_changed');
    $states = [];
    foreach ($operations as $binding) {
        $read = caption_feed_locked_jsonl($root.'/'.$binding['operation'].'/receipt.jsonl', 134217728, LOCK_SH, $locks);
        caption_feed_need($read['sha256'] === ($binding['journal_sha256'] ?? null), 'caption_original_journal_changed');
        $states[$binding['operation']] = $read['state'];
    }
    $updates = caption_feed_confirmed_updates($states, $operations, $input['caption_updates'], $installed);
    // Reject a later legitimate caption/price version. Do not project stale receipts over it.
    ob_start();
    try { require_once getcwd().'/config.php'; require_once getcwd().'/services/ChannelPostRuntime.php'; }
    finally { ob_end_clean(); }
    $pdo = ConversationDb::connection(); $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
    try {
        $registry = new ChannelPostRegistryService($pdo);
        foreach ($updates as $u) {
            $p = $registry->publication($u['publication_id']);
            caption_feed_need(is_array($p) && (int)$p['publication_id'] === $u['publication_id']
                && (int)$p['version_id'] === $u['confirmed_version_id'] && ($p['state'] ?? null) === 'sent'
                && ($p['platform'] ?? null) === $u['platform'] && ($p['external_message_id'] ?? null) === $u['message_id']
                && ($p['source_key'] ?? null) === ($u['platform'] === 'max' ? 'max_anytour_msk1' : 'tg_anytour_msk')
                && ($p['snapshot']['post_url'] ?? null) === $u['post_url'] && ($p['snapshot']['text'] ?? null) === $u['text'],
                'caption_current_registry_changed');
        }
    } finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
    return $updates;
}

/** DOM only validates; the returned blocks are exact substrings of the original bytes. */
function caption_feed_xml(string $xml): array
{
    caption_feed_need($xml !== '' && strlen($xml) < 4000000 && preg_match('//u', $xml) === 1
        && stripos($xml, '<!DOCTYPE') === false && stripos($xml, '<!ENTITY') === false
        && stripos($xml, 'available=') === false, 'caption_feed_xml_invalid');
    $dom = new DOMDocument(); $prior = libxml_use_internal_errors(true);
    try { $loaded = $dom->loadXML($xml, LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    caption_feed_need($loaded && $dom->documentElement?->tagName === 'yml_catalog', 'caption_feed_xml_invalid');
    $nodes = [];
    foreach ($dom->getElementsByTagName('offer') as $node) {
        $id = $node->getAttribute('id');
        caption_feed_need(preg_match('/\A[A-Za-z0-9_-]+\z/D', $id) === 1 && !isset($nodes[$id]), 'caption_feed_offer_duplicate');
        $nodes[$id] = $node;
    }
    $matched = preg_match_all('/<offer id="([A-Za-z0-9_-]+)">.*?<\/offer>/s', $xml, $matches, PREG_SET_ORDER);
    caption_feed_need(is_int($matched) && $matched === count($nodes), 'caption_feed_offer_envelope_changed');
    $blocks = [];
    foreach ($matches as $m) { caption_feed_need(!isset($blocks[$m[1]]), 'caption_feed_offer_duplicate'); $blocks[$m[1]] = $m[0]; }
    caption_feed_need(array_keys($blocks) === array_keys($nodes), 'caption_feed_offer_order_changed');
    return ['nodes' => $nodes, 'blocks' => $blocks];
}

function caption_feed_node_text(DOMElement $offer, string $tag): string
{
    $nodes = $offer->getElementsByTagName($tag);
    caption_feed_need($nodes->length === 1 && $nodes->item(0)?->parentNode?->isSameNode($offer) === true, 'caption_feed_field_not_unique');
    return $nodes->item(0)->textContent;
}

function caption_feed_replace_field(string $block, string $tag, string $escaped): string
{
    $count = 0;
    $out = preg_replace_callback('~<'.preg_quote($tag, '~').'>.*?</'.preg_quote($tag, '~').'>~s',
        static fn(array $m): string => '<'.$tag.'>'.$escaped.'</'.$tag.'>', $block, -1, $count);
    caption_feed_need(is_string($out) && $count === 1, 'caption_feed_raw_field_not_unique');
    return $out;
}

function caption_feed_targets(array $updates, string $name): array
{
    caption_feed_need(in_array($name, caption_feed_names(), true) && count($updates) === 18, 'caption_feed_target_scope_changed');
    $out = [];
    foreach ($updates as $u) {
        if (($name === 'hotel-posts-max.xml' && $u['platform'] !== 'max')
            || ($name === 'hotel-posts-telegram.xml' && $u['platform'] !== 'telegram')) continue;
        caption_feed_need(!isset($out[$u['offer_id']]), 'caption_feed_target_duplicate'); $out[$u['offer_id']] = $u;
    }
    caption_feed_need(count($out) === ($name === 'hotel-posts-all.xml' ? 18 : 9), 'caption_feed_target_count_changed');
    return $out;
}

/** Only the18 approved descriptions may differ; every other byte is retained. */
function caption_feed_assert_preserved(string $before, string $after, array $updates, string $name): void
{
    $a = caption_feed_xml($before); $b = caption_feed_xml($after); $targets = caption_feed_targets($updates, $name);
    caption_feed_need(count($a['blocks']) === ($name === 'hotel-posts-all.xml' ? 1208 : 604)
        && array_keys($a['blocks']) === array_keys($b['blocks']), 'caption_feed_rows_changed');
    $mask = static function (string $xml) use ($targets): string {
        $out = preg_replace_callback('/<offer id="([A-Za-z0-9_-]+)">.*?<\/offer>/s', static function (array $m) use ($targets): string {
            if (!isset($targets[$m[1]])) return $m[0];
            $block = caption_feed_replace_field($m[0], 'description', '__ALLOWED_DESCRIPTION__');
            if ($targets[$m[1]]['region_change'] !== null) $block = caption_feed_replace_field($block, 'custom_label_1', '__ALLOWED_REGION__');
            return $block;
        }, $xml);
        caption_feed_need(is_string($out), 'caption_feed_mask_failed'); return $out;
    };
    caption_feed_need($mask($before) === $mask($after), 'caption_feed_unapproved_bytes_changed');
    foreach ($targets as $id => $u) {
        caption_feed_need(isset($a['nodes'][$id], $b['nodes'][$id]), 'caption_feed_target_missing');
        caption_feed_need(caption_feed_node_text($a['nodes'][$id], 'url') === $u['feed_url']
            && caption_feed_node_text($a['nodes'][$id], 'custom_label_0') === $u['platform']
            && caption_feed_node_text($b['nodes'][$id], 'description') === $u['text'], 'caption_feed_receipt_projection_changed');
        if ($u['region_change'] !== null) {
            caption_feed_need(caption_feed_node_text($a['nodes'][$id], 'custom_label_1') === $u['region_change']['old']
                && caption_feed_node_text($b['nodes'][$id], 'custom_label_1') === $u['region_change']['new'], 'caption_feed_region_preimage_changed');
        }
    }
}

function caption_feed_render(string $xml, array $updates, string $name): string
{
    $parsed = caption_feed_xml($xml); $targets = caption_feed_targets($updates, $name);
    foreach ($targets as $id => $u) {
        caption_feed_need(isset($parsed['nodes'][$id]) && is_string($u['text'] ?? null)
            && hash('sha256', $u['text']) === $u['text_sha256'], 'caption_feed_target_or_text_changed');
    }
    $escape = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $out = preg_replace_callback('/<offer id="([A-Za-z0-9_-]+)">.*?<\/offer>/s', static function (array $m) use ($targets, $escape): string {
        if (!isset($targets[$m[1]])) return $m[0];
        $u = $targets[$m[1]]; $block = caption_feed_replace_field($m[0], 'description', $escape($u['text']));
        if ($u['region_change'] !== null) $block = caption_feed_replace_field($block, 'custom_label_1', $escape($u['region_change']['new']));
        return $block;
    }, $xml);
    caption_feed_need(is_string($out), 'caption_feed_render_failed');
    caption_feed_assert_preserved($xml, $out, $updates, $name);
    return $out;
}

/** Pure preparation: call inside the previous preparation path with fresh preimages and confirmed updates. */
function caption_feed_prepare(array $before, array $updates, array $operations, array $bindings, string $operation, int $at): array
{
    caption_feed_need(array_keys($before) === caption_feed_names()
        && preg_match('/\Acommercial-channel-hotel-feeds-[0-9]{8}-update[1-9][0-9]*\z/D', $operation) === 1,
        'caption_feed_preparation_scope_changed');
    $files = [];
    foreach ($before as $name => $xml) {
        $next = caption_feed_render($xml, $updates, $name);
        $files[] = ['name' => $name, 'sha256' => hash('sha256', $next), 'bytes' => strlen($next),
            'rows' => substr_count($next, '<offer id="'), 'expected_previous_sha256' => hash('sha256', $xml),
            'content_base64' => base64_encode($next), 'previous_content_base64' => base64_encode($xml)];
    }
    return ['schema_version' => 5, 'operation' => $operation, 'files' => $files,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $at), 'caption_operations' => $operations, 'caption_updates' => $bindings];
}
