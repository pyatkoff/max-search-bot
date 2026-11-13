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
    return ['commercial-caption-review73-20261113-canary-v1' => 8, 'commercial-caption-review73-20261113-batch001-v1' => 46, 'commercial-caption-review73-20261113-batch002-v1' => 46, 'commercial-caption-review73-20261113-batch003-v1' => 46];
}

/** A binding is derived from approved-cohort + actual feed offers, never a name match. */
function caption_feed_bindings(array $rows): array
{
    caption_feed_need(array_is_list($rows) && count($rows) === 146, 'caption_binding_scope_changed');
    caption_feed_need(array_column($rows, 'publication_id') === [218, 214, 790, 782, 1063, 1053, 1343, 1340, 242, 232, 320, 314, 354, 350, 406, 404, 409, 407, 436, 434, 464, 460, 501, 497, 526, 520, 529, 523, 550, 545, 564, 555, 588, 579, 637, 628, 693, 683, 775, 765, 789, 781, 791, 783, 792, 784, 814, 804, 828, 818, 832, 822, 870, 862, 883, 873, 901, 893, 926, 916, 939, 930, 940, 931, 944, 935, 957, 947, 958, 948, 960, 950, 962, 952, 964, 954, 973, 968, 988, 978, 1027, 1017, 1041, 1034, 1064, 1054, 1065, 1055, 1074, 1068, 1075, 1069, 1076, 1070, 1078, 1072, 1089, 1079, 1090, 1080, 1091, 1081, 1092, 1082, 1094, 1084, 1096, 1086, 1111, 1102, 1128, 1119, 1134, 1125, 1183, 1173, 1186, 1176, 1189, 1179, 1191, 1181, 1197, 1194, 1198, 1195, 1210, 1200, 1217, 1207, 1218, 1208, 1230, 1220, 1233, 1223, 1249, 1240, 1251, 1242, 1265, 1257, 1281, 1273, 1321, 1317], 'caption_exact_publication_scope_changed');
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
        caption_feed_need((caption_feed_site_ids()[$r['hotel_id']] ?? null) === $r['site_id']
            && is_string($r['site_id']) && preg_match('/\A[a-f0-9-]{36}\z/D', $r['site_id']) === 1
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
    caption_feed_need(array_values($hotels) === [55574, 1141, 71259, 32232, 371, 273, 12116, 1348, 1199, 1100, 43236, 128087, 8053, 386, 512, 20971, 4071, 1203, 65020, 74032, 15872, 1142, 28159, 88750, 365, 3434, 1585, 1032, 1440, 45496, 1493, 1154, 1124, 2651, 2673, 2688, 2514, 54605, 2791, 1196, 49083, 9260, 117935, 81154, 70032, 28509, 57082, 80605, 70033, 57652, 57650, 23766, 24778, 14133, 57039, 73055, 5389, 132002, 3469, 9321, 15693, 66564, 68907, 65895, 102031, 102774, 1260, 83563, 1368, 129406, 418, 932, 497], 'caption_hotel_count_changed');
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
    caption_feed_need(count($updates) === 146, 'caption_confirmed_scope_incomplete');
    return array_values($updates);
}

final class CaptionFeedLockBusy extends RuntimeException
{
    public array $diagnostic;
    public function __construct(array $diagnostic)
    {
        parent::__construct('caption_existing_operation_busy');
        $this->diagnostic = $diagnostic;
    }
}

/** Observe only the five existing journal identities after a failed nonblocking lock. */
function caption_feed_lock_diagnostic(string $path, int $kind, int $wouldBlock, array $stat): array
{
    $root = '/var/www/anytoour/data/channel-post-operations';
    $known = [$root.'/monitor-price-after-collection-v1.jsonl' => 'monitor-price-after-collection-v1'];
    foreach (array_keys(caption_feed_operation_counts()) as $operation) $known[$root.'/'.$operation.'/receipt.jsonl'] = $operation;
    caption_feed_need(isset($known[$path]) && in_array($kind, [LOCK_EX, LOCK_SH], true)
        && in_array($wouldBlock, [0, 1], true), 'caption_lock_diagnostic_scope_changed');
    $out = ['journal_id' => $known[$path], 'requested_lock' => $kind === LOCK_EX ? 'exclusive' : 'shared',
        'would_block' => $wouldBlock, 'holder_lookup' => 'unavailable', 'holders' => [],
        'holders_observed_after_failure' => true];
    $raw = @file_get_contents('/proc/locks', false, null, 0, 1048577);
    if (!is_string($raw) || strlen($raw) > 1048576) return $out;
    $dev = $stat['dev']; $major = (($dev >> 8) & 0xfff) | (($dev >> 32) & 0xfffff000);
    $minor = ($dev & 0xff) | (($dev >> 12) & 0xffffff00);
    $out['holder_lookup'] = 'no_matching_holder_observed';
    foreach (explode("\n", $raw) as $line) {
        // Waiting rows ("->") are excluded. Only PID and process comm can leave the host.
        if (preg_match('/\A[0-9]+:\s+(FLOCK|POSIX|OFDLCK)\s+ADVISORY\s+(READ|WRITE)\s+([1-9][0-9]*)\s+([0-9a-f]+):([0-9a-f]+):([0-9]+)\s+/i', $line, $m) !== 1
            || hexdec($m[4]) !== $major || hexdec($m[5]) !== $minor || (int)$m[6] !== $stat['ino']) continue;
        if (count($out['holders']) >= 8) { $out['holder_lookup'] = 'matching_holders_truncated'; break; }
        $comm = @file_get_contents('/proc/'.$m[3].'/comm', false, null, 0, 81);
        $comm = is_string($comm) ? trim($comm) : '';
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,64}\z/D', $comm) !== 1) $comm = 'unavailable';
        $out['holders'][] = ['pid' => (int)$m[3], 'comm' => $comm, 'kind' => $m[1], 'mode' => $m[2]];
        $out['holder_lookup'] = 'matching_holders_observed';
    }
    return $out;
}

/** Existing rb file, existing lock; nothing is created, appended, or modified. */
function caption_feed_locked_jsonl(string $path, int $cap, int $lockKind, array &$locks): array
{
    caption_feed_need(!is_link(dirname($path)) && realpath(dirname($path)) === dirname($path)
        && !is_link($path) && is_file($path) && realpath($path) === $path, 'caption_journal_path_invalid');
    $before = lstat($path); $stream = fopen($path, 'rb');
    caption_feed_need(is_array($before) && is_resource($stream), 'caption_journal_open_failed');
    $locks[] = $stream;
    $wouldBlock = 0;
    if (!flock($stream, $lockKind | LOCK_NB, $wouldBlock)) {
        throw new CaptionFeedLockBusy(caption_feed_lock_diagnostic($path, $lockKind, $wouldBlock, $before));
    }
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
    caption_feed_need(in_array($name, caption_feed_names(), true) && count($updates) === 146, 'caption_feed_target_scope_changed');
    $out = [];
    foreach ($updates as $u) {
        if (($name === 'hotel-posts-max.xml' && $u['platform'] !== 'max')
            || ($name === 'hotel-posts-telegram.xml' && $u['platform'] !== 'telegram')) continue;
        caption_feed_need(!isset($out[$u['offer_id']]), 'caption_feed_target_duplicate'); $out[$u['offer_id']] = $u;
    }
    caption_feed_need(count($out) === ($name === 'hotel-posts-all.xml' ? 146 : 73), 'caption_feed_target_count_changed');
    return $out;
}

/** Only the 146 approved descriptions may differ; every other byte is retained. */
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

function caption_feed_site_ids(): array
{
    return [55574 => '32609f66-9f19-42eb-a640-580e3d18c939', 1141 => 'e72ad958-afb8-48cb-be79-444986378d43', 71259 => 'cb8d44de-25c0-4fdb-a30c-71dce999ec48', 32232 => '7df7f617-7aeb-476c-8286-82f891e0196a', 371 => '229c11fe-e820-4486-ae35-d34f4299ee7b', 273 => '20a3b54e-649d-4d69-82d8-fd041d9b1254', 12116 => '16a37d69-8314-46fe-97ff-7afd8f99c8a9', 1348 => '9b3746e2-e314-496b-b3ec-22a2ae40ab72', 1199 => 'd96e5fb8-1b2e-493f-9e0f-27808ab06052', 1100 => 'eacfb67a-3247-4020-a073-4b5dd526433d', 43236 => 'e6515eb0-f233-4a5c-8fa5-cfbf218ac7bf', 128087 => '6de35553-3fd6-44cf-82ac-96fbd8c6fc24', 8053 => '382ef1e3-b1e1-431b-8436-f9b5e8b8c09e', 386 => '3b713d15-492b-4f94-b899-bba605110913', 512 => 'd0b67a5f-d762-4541-a061-7b5466f089ef', 20971 => 'e84966fa-124d-4586-bcfe-ad26e0204871', 4071 => '398df4c9-6205-495a-940c-b3bc4cacd888', 1203 => '5ab9b2d3-1d14-49a3-85cb-cd6c795b65c4', 65020 => 'e51dd0c1-5652-4ad0-ac35-65f683cb03cc', 74032 => '970a090c-9d53-413b-9a5d-6f46faa452f1', 15872 => '20bab3d2-6f9b-43df-87a9-c29a3d06e15f', 1142 => '6459fec4-5592-4d63-9354-3e93724e56fa', 28159 => '2ad4614b-718e-4efd-bede-4547137b01e0', 88750 => '0c3601a1-12b6-4e8e-8fd8-e04a40c61fe6', 365 => '8f2f5e95-4ff5-4029-953c-217ab1cfd8e1', 3434 => 'fbb44e99-ff7f-439a-bd04-00a42d891990', 1585 => '72e7de96-ce68-4298-a04b-9744792217d6', 1032 => 'e0eaa1ab-65d1-4785-8fd3-6e92c9600fb6', 1440 => '0a5d7f2a-aca9-4446-af12-086b1afd0f69', 45496 => '6d403f3d-b744-46c7-b44e-7334946a7cfe', 1493 => '5620a15a-b420-4bff-b3b4-2b420cdaf603', 1154 => 'de9b9827-60bf-4705-bfd9-b74027f26736', 1124 => '879c1c93-c145-4bf3-959d-4248b82b2de7', 2651 => 'ea018c7e-8e62-4883-b4f1-b85b77ac05f3', 2673 => '777007c3-53ae-42f6-9772-a5c9f95b302b', 2688 => 'fc74813c-a566-44fa-8f66-2e434b7c7cb0', 2514 => '4b4165ab-f69f-43e2-90af-f01b70ee6483', 54605 => '3057c2be-5c39-4f6c-bdf3-84802ab65f42', 2791 => 'cc60590a-a073-4562-8143-06b11c43034c', 1196 => '709cc788-4965-4d9a-a8b3-e9082f7bccf9', 49083 => 'e60f365f-a0cb-418c-9862-8b398ea24def', 9260 => '68782e4e-562f-4795-a905-7675e1364af9', 117935 => '00ea98e4-317f-423e-bb8a-334e20fc3b29', 81154 => 'dfd5f5c8-d662-4247-8c34-be7ef4bc82e2', 70032 => 'a3e896dc-ff47-4017-9106-badcded77f71', 28509 => 'cd534b32-6d09-40b9-ab1f-9d3739f91093', 57082 => 'c7d1c4f2-4060-4919-8312-62f6510af584', 80605 => 'b77b38ea-8244-48b7-be3b-f6c4437c648f', 70033 => '1091b430-8104-44e0-8e04-b9fde5ba0cd9', 57652 => '16be335f-a73a-405b-925a-675fa09f4b01', 57650 => '6c34d739-801e-418e-9d6d-0f56559877c0', 23766 => 'fa204acc-9ff7-4013-80eb-dd4a25cf7fb4', 24778 => '9efddeaf-542b-40a2-9de6-d0c999c40ce1', 14133 => '880d6ac8-1c00-4010-933d-afb02d50cba0', 57039 => 'c857af60-028a-414f-b91d-4338170dc4ca', 73055 => 'bbbd4a18-bc9f-4284-8ebf-0c822878626a', 5389 => '4a219580-2c96-4b0b-8266-ff49887ec31b', 132002 => '5936c8a9-1d7b-4b17-88e9-624fb0d19008', 3469 => 'f273f929-d9a6-5fa5-a479-65f3309b759e', 9321 => 'fb648e34-5731-5c9c-9080-9babfd917485', 15693 => '18cddb98-8f29-54c4-bf72-5591d33b7759', 66564 => '0b613eb7-045d-4e89-8321-9aa80e7a8e2c', 68907 => '1dd87e52-4498-5bcd-bb12-b97e35be08c6', 65895 => '8082cfa1-ca58-53aa-8b00-5c9fa2328793', 102031 => 'fe09d84b-1914-59e0-9e35-ed6775ece19b', 102774 => '20c67db2-8fe7-50d9-9e63-acba0711a563', 1260 => 'a2e28fd6-26cf-5925-a80e-2fda77a4744f', 83563 => '54fc96ed-2f67-5d42-bafa-aa6ec8a2662e', 1368 => '257902e5-19c0-5a80-8ab8-34b3f7dd1559', 129406 => '348c18b0-17d7-44fb-8a89-168c58bf6546', 418 => '001a61be-6c4b-5207-b0bc-f00caeb4bddc', 932 => 'd52e61ff-3be7-5f42-be1d-e05dfb4491df', 497 => '94945106-2d21-5f24-a03a-a09892d074b8'];
}
