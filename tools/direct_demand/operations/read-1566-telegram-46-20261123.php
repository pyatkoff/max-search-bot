<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

// Existing #1566 reader, restricted to the ranked batch of candidate pairs.
// This program has no apply mode, publication registration or provider mutation.
const APP = '/var/www/anytoour/data/www/app.anytoour.ru';
const OPERATIONS = '/var/www/anytoour/data/channel-post-operations';
const APPLICATION_SHA = '__APPLICATION_SHA__';
const REPOSITORY_SHA = '__REPOSITORY_SHA__';
const NATIVE_RUN_ID = '__NATIVE_RUN_ID__';
const PAIRS = [
    64918 => ['name' => 'You & Me Maldives', 'source' => '1046', 'target' => '1020', 'date' => '2025-02-21T08:56:56Z'],
    996 => ['name' => 'Corendon Playa Kemer', 'source' => '325', 'target' => '1063', 'date' => '2025-04-01T07:52:34Z'],
];
const PAIR_DIGEST = '695a72171a90fdbe8daf3d94f5bf9bb82892c791e830f9758a25c5a4e532bd01';


function requireRead(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}
function jsonRead(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function reasonRead(Throwable $e): string
{
    return get_class($e) === RuntimeException::class
        && preg_match('/\A[a-z_]{1,100}\z/D', $e->getMessage()) === 1 ? $e->getMessage() : 'read_evidence_unavailable';
}
function runtimeRead(): void
{
    requireRead(PHP_SAPI === 'cli' && realpath(APP) === APP && getcwd() === APP, 'application_root_changed');
    foreach ([
        'GIT_OPTIONAL_LOCKS=0 git -c core.fsmonitor=false rev-parse HEAD' => APPLICATION_SHA,
        'GIT_OPTIONAL_LOCKS=0 git -c core.fsmonitor=false status --porcelain --untracked-files=no' => '',
    ] as $command => $expected) {
        $lines = [];
        exec($command . ' 2>/dev/null', $lines, $status);
        requireRead($status === 0 && implode("\n", $lines) === $expected, 'installed_application_changed');
    }
}

/** Fixed public GETs only; TLS verified, no redirects or retries. */
function publicEvidence(string $channel, string $message): array
{
    try { return ['ok' => true] + publicRead($channel, $message); }
    catch (Throwable $e) {
        return ['ok' => false, 'url' => 'https://t.me/' . $channel . '/' . $message, 'reason' => reasonRead($e)];
    }
}
function publicRead(string $channel, string $message): array
{
    $allowed = $channel === 'anytour' ? array_column(PAIRS, 'target')
        : ($channel === 'any_toour' ? array_column(PAIRS, 'source') : []);
    requireRead(in_array($message, $allowed, true), 'public_scope_invalid');
    $url = 'https://t.me/' . $channel . '/' . $message;
    $body = '';
    $curl = curl_init($url . '?embed=1&mode=tme');
    requireRead($curl !== false, 'public_transport_unavailable');
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 18, CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_WRITEFUNCTION => static function ($unused, string $part) use (&$body): int {
            if (strlen($body) + strlen($part) > 1_000_000) return 0;
            $body .= $part;
            return strlen($part);
        },
    ]);
    $ok = curl_exec($curl) !== false && curl_errno($curl) === 0;
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    requireRead($ok && $status === 200, 'public_post_unavailable');
    return parsePublicRead($body, $channel, $message);
}
function parsePublicRead(string $body, string $channel, string $message): array
{
    $url = 'https://t.me/' . $channel . '/' . $message;
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prior = libxml_use_internal_errors(true);
    try { $loaded = $doc->loadHTML($body, LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    requireRead($loaded, 'public_post_invalid');
    $xp = new DOMXPath($doc);
    $nodes = $xp->query('//*[@data-post="' . $channel . '/' . $message . '"]');
    requireRead($nodes && $nodes->length === 1, 'public_identity_changed');
    $node = $nodes->item(0);
    $cls = static fn(string $c): string => 'contains(concat(" ",normalize-space(@class)," ")," ' . $c . ' ")';
    $text = $xp->query('.//*[' . $cls('tgme_widget_message_text') . ']', $node);
    $photos = $xp->query('.//*[' . $cls('tgme_widget_message_photo_wrap') . ']', $node);
    $dates = $xp->query('.//time[@datetime]', $node);
    $other = $xp->query('.//video|.//audio|.//*[' . $cls('tgme_widget_message_video_thumb')
        . ' or ' . $cls('tgme_widget_message_roundvideo') . ' or ' . $cls('tgme_widget_message_grouped') . ']', $node);
    requireRead($text && $photos && $dates && $other && $text->length === 1 && $dates->length === 1,
        'public_caption_or_date_unavailable');
    $html = '';
    foreach ($text->item(0)->childNodes as $child) $html .= $doc->saveHTML($child);
    $caption = html_entity_decode(strip_tags(preg_replace('~<br\s*/?>~i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    requireRead($caption !== '' && mb_check_encoding($caption, 'UTF-8') && mb_strlen($caption, 'UTF-8') <= 12000,
        'public_caption_invalid');
    $single = $photos->length === 1 && $other->length === 0;
    $photo = $single ? $photos->item(0) : null;
    $marker = $photo ? trim($photo->getAttribute('class')) : '';
    $photoUrl = '';
    if ($photo && $photo->getAttribute('href') === $url
        && preg_match('/(?:^|\s)[0-9]{5,}(?:_[0-9]+)?(?:\s|$)/D', $marker) === 1
        && preg_match('~background-image:\s*url\([\'"]?(https://[^\'")]+)[\'"]?\)~', $photo->getAttribute('style'), $match) === 1) {
        $parsed = parse_url($match[1]);
        if (is_array($parsed) && ($parsed['scheme'] ?? '') === 'https'
            && preg_match('/\A(?:[a-z0-9-]+\.)*(?:cdn-telegram\.org|telegram-cdn\.org|telesco\.pe)\z/D', $parsed['host'] ?? '') === 1
            && !isset($parsed['user']) && !isset($parsed['pass']) && !isset($parsed['port'])) $photoUrl = $match[1];
    }
    $buttons = [];
    $links = $xp->query('.//a[' . $cls('tgme_widget_message_inline_button') . ']', $node);
    requireRead($links && $links->length <= 20, 'public_keyboard_unbounded');
    foreach ($links as $link) {
        $href = $link->getAttribute('href');
        $buttons[] = ['text' => trim($link->textContent),
            'hotel_url' => preg_match('~\Ahttps://t\.me/anyru_bot\?start=p_[A-Za-z0-9_-]{8,60}\z~D', $href) === 1 ? $href : null,
            'url_sha256' => hash('sha256', $href)];
    }
    return ['url' => $url, 'caption' => $caption, 'caption_sha256' => hash('sha256', $caption),
        'html_sha256' => hash('sha256', $body), 'published_at' => $dates->item(0)->getAttribute('datetime'),
        'published_unixtime' => (new DateTimeImmutable($dates->item(0)->getAttribute('datetime')))->getTimestamp(),
        'single_photo' => $single && $photoUrl !== '', 'photo_url' => $photoUrl,
        'photo_marker_sha256' => $marker !== '' ? hash('sha256', $marker) : null, 'buttons' => $buttons];
}

/** No raw receipt, provider file id, media token or claim token leaves the host. */
function receiptRead(string $path): array
{
    if (!file_exists($path) && !is_link($path)) return ['state' => 'absent'];
    requireRead(!is_link($path) && is_file($path) && filesize($path) <= 1_000_000, 'operation_receipt_unreadable');
    $raw = file_get_contents($path);
    requireRead(is_string($raw), 'operation_receipt_unreadable');
    $lines = array_values(array_filter(explode("\n", $raw), static fn($s) => trim($s) !== ''));
    requireRead(count($lines) <= 500, 'operation_receipt_unbounded');
    $states = [];
    foreach ($lines as $line) {
        $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        $state = $record['state'] ?? '';
        $states[] = is_string($state) && preg_match('/\A[a-z0-9_:-]{1,100}\z/D', $state) === 1 ? $state : 'unclassified';
    }
    return ['state' => 'present_do_not_replay', 'sha256' => hash('sha256', $raw), 'line_count' => count($lines), 'states' => $states];
}
function publicationRead(PDO $pdo, ChannelPostRegistryService $registry, int $channel, string $message): ?array
{
    $q = $pdo->prepare('SELECT id FROM post_publications WHERE post_channel_id=? AND external_message_id=? LIMIT 2');
    $q->execute([$channel, $message]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    requireRead(count($ids) <= 1, 'registry_identity_ambiguous');
    if ($ids === []) return null;
    $p = $registry->publication((int) $ids[0]);
    requireRead(is_array($p) && $p['project_key'] === 'anytour' && $p['platform'] === 'telegram'
        && $p['bot_key'] === 'anyru_bot' && $p['message_id'] === $message
        && (int) $p['channel']['id'] === $channel, 'registry_identity_changed');
    $q = $pdo->prepare('SELECT id,version_number,state,attempt_count,write_attempts,error_reason,'
        . 'claim_token IS NOT NULL AS has_claim,claimed_at IS NOT NULL AS has_claimed_at '
        . 'FROM post_versions WHERE publication_id=? ORDER BY version_number LIMIT 101');
    $q->execute([(int) $p['publication_id']]);
    $history = $q->fetchAll(PDO::FETCH_ASSOC);
    requireRead(count($history) <= 100, 'version_history_incomplete');
    $held = false;
    foreach ($history as $v) {
        if ((int) $v['has_claim'] !== 0 || (int) $v['has_claimed_at'] !== 0
            || in_array($v['state'], ['claimed', 'write_started', 'unknown'], true)) $held = true;
    }
    $s = $p['snapshot'];
    $media = [];
    foreach (($s['attachments'] ?? []) as $a) $media[] = [
        'type' => $a['type'] ?? '', 'provider' => $a['provider'] ?? '',
        'file_id_sha256' => !empty($a['telegram_file_id']) ? hash('sha256', $a['telegram_file_id']) : null,
        'file_unique_id_sha256' => !empty($a['file_unique_id']) ? hash('sha256', $a['file_unique_id']) : null,
        'reusable_telegram_photo' => ($a['type'] ?? '') === 'image' && ($a['provider'] ?? '') === 'telegram'
            && preg_match('/\A[A-Za-z0-9_-]{8,1024}\z/D', $a['telegram_file_id'] ?? '') === 1
            && preg_match('/\A[A-Za-z0-9_-]{5,160}\z/D', $a['file_unique_id'] ?? '') === 1,
    ];
    $url = ChannelPostButtonTransport::buttonUrl($p);
    $resolved = $registry->resolveToken('anytour', 'telegram', 'anyru_bot', (string) $p['start_payload']);
    $same = is_array($resolved) && (int) $resolved['publication_id'] === (int) $p['publication_id']
        && (int) $resolved['version_id'] === (int) $p['version_id'];
    $r = $p['receipt'];
    return ['publication_id' => $p['publication_id'], 'version_id' => $p['version_id'],
        'version_number' => $p['version_number'], 'content_hash' => $p['content_hash'], 'state' => $p['state'],
        'post_url' => $s['post_url'] ?? '', 'caption_sha256' => hash('sha256', $s['text'] ?? ''),
        'hotel_id' => $s['metadata']['hotel_catalog_id'] ?? null, 'logical_id' => $s['metadata']['logical_id'] ?? null,
        'monitor_price_record_id' => $s['metadata']['monitor_price_record_id'] ?? null,
        'attachments' => $media, 'button_url' => $url,
        'one_expected_button' => $url !== '' && ChannelPostIncomingAdapter::containsOnlyButton($s, $url, $p['channel']['button_text']),
        'current_token_resolves' => $same, 'native_claim_held_or_unknown' => $held, 'version_history' => $history,
        'receipt' => ['sha256' => hash('sha256', jsonRead($r)), 'write_attempts' => $r['write_attempts'] ?? null,
            'request_count' => $r['request_count'] ?? null, 'content_preserved' => $r['content_preserved'] ?? null,
            'button_verified' => $r['button_verified'] ?? null],
        'content_edit_journal' => receiptRead(OPERATIONS . '/issue-1566-edit-existing-' . $p['publication_id'] . '.jsonl')];
}

$out = ['schema' => 1, 'issue' => 1566, 'demand_run_id' => '45385556709', 'demand_artifact_id' => 14915463382, 'demand_registry_sha256' => '3cd1d3fd24410be051f562aaf71040eee29e7c6299d50fc4de4eb12030dc577d', 'operation' => 'hotel_archive_telegram_ranked_registry', 'ok' => false,
    'repository_sha' => REPOSITORY_SHA, 'application_sha' => APPLICATION_SHA,
    'native_run_id' => NATIVE_RUN_ID, 'native_run_attempt' => 1,
    'production_writes' => 0, 'provider_writes' => 0, 'bot_starts' => 0, 'public_get_attempts' => 0];
$pdo = null;
$priceLock = null;
$stage = 'installed_release';
try {
    $data = [];
    foreach (PAIRS as $id => $row) {
        $data[] = [$id, (int) $row['source'], (int) $row['target'], $row['date']];
    }
    requireRead(count($data) === 2 && count(array_unique(array_column($data, 1))) === 2
        && count(array_unique(array_column($data, 2))) === 2
        && !array_intersect(array_column($data, 0), [800,408,466,17595,393,358,1260])
        && !array_intersect(array_column($data, 2), [1588,997,1101,862,1083,328,288,1678])
        && hash_equals(PAIR_DIGEST, hash('sha256', jsonRead($data))), 'input_packet_changed');
    runtimeRead();
    requireRead(realpath(OPERATIONS) === OPERATIONS, 'operations_root_changed');
    $stage = 'bounded_public_photos';
    $public = [];
    foreach (PAIRS as $hotel => $pair) {
        $public[$hotel] = [publicEvidence('any_toour', $pair['source']), publicEvidence('anytour', $pair['target'])];
        $out['public_get_attempts'] += 2;
    }
    runtimeRead();
    require_once APP . '/hotel-posts-telegram-main19.php';
    $feed = anytourTelegramFeedOfferUrls(anytourTelegramFeedRead());
    requireRead(count($feed) > 0, 'feed_inventory_unavailable');
    $landings = anytourTelegramFeedDestinationPosts($feed);
    // Both possible destinations of the two table-backed rows remain reserved.
    $protectedTargets = array_fill_keys(array_merge(array_values($landings), [
        'https://t.me/anytour/1780', 'https://t.me/anytour/1760',
    ]), true);
    $lockPath = OPERATIONS . '/monitor-price-after-collection-v1.jsonl';
    requireRead(!is_link($lockPath) && is_file($lockPath), 'price_editor_lease_unknown');
    $priceLock = fopen($lockPath, 'rb');
    requireRead(is_resource($priceLock), 'price_read_lease_unavailable');
    $lockStarted = hrtime(true);
    $lockDeadline = $lockStarted + 240_000_000_000;
    $shared = false;
    do {
        $shared = flock($priceLock, LOCK_SH | LOCK_NB);
        if ($shared || hrtime(true) >= $lockDeadline) break;
        usleep(2_000_000);
    } while (true);
    $out['price_lease_wait_ms'] = (int) ((hrtime(true) - $lockStarted) / 1_000_000);
    requireRead($shared, 'price_editor_busy_or_unknown');
    runtimeRead();
    $out['price_editor_shared_read_lease'] = true;
    $stage = 'initialize_registry';
    ob_start();
    try {
        require APP . '/config.php';
        require_once APP . '/services/ChannelPostRegistryService.php';
        require_once APP . '/services/ChannelPostButtonTransport.php';
        require_once APP . '/services/ChannelPostRuntime.php';
    } finally { ob_end_clean(); }
    requireRead(ProjectConfig::projectId() === 'anytour', 'project_changed');
    $pdo = ConversationDb::connection();
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $registry = new ChannelPostRegistryService($pdo);
    $target = $registry->channel('anytour', 'telegram', 'anyru_bot', '-1001845835645');
    $source = $registry->channel('anytour', 'telegram', 'anyru_bot', '-1002262666616');
    $identity = ChannelPostRuntime::identity('telegram');
    $q = $pdo->query("SELECT pc.id,pc.platform,pc.channel_id FROM post_channels pc JOIN projects p ON p.id=pc.project_id "
        . "WHERE p.project_key='anytour' AND pc.id=4");
    $mainMax = $q->fetch(PDO::FETCH_ASSOC);
    requireRead(is_array($target) && (int) $target['id'] === 6 && $target['source_key'] === 'tg_anytour_msk'
        && ($target['post_mode'] ?? 'append_button') === 'append_button'
        && is_array($source) && (int) $source['id'] === 10
        && ($identity['project_key'] ?? '') === 'anytour' && ($identity['bot_key'] ?? '') === 'anyru_bot',
        'channel_identity_changed');
    requireRead(is_array($mainMax) && $mainMax['platform'] === 'max' && $mainMax['channel_id'] === '-68765999669267',
        'large_max_registry_identity_changed');
    $out['target_channel'] = ['registry_id' => 6, 'chat_id' => '-1001845835645', 'url' => 'https://t.me/anytour',
        'config_version' => (int) $target['config_version'], 'button_text' => $target['button_text'], 'bot_url' => $target['bot_url']];
    $stage = 'native_channel_rights';
    $targetUsernameMatches = false;
    $currentPin = null;
    $readTransport = static function (string $platform, string $method, string $path, array $query, ?array $body) use (&$targetUsernameMatches, &$currentPin): array {
        requireRead($platform === 'telegram' && $method === 'POST' && $query === []
            && in_array($path, ['getMe', 'getChatMember', 'getChat'], true)
            && ($path === 'getMe' || (string) ($body['chat_id'] ?? '') === '-1001845835645'), 'provider_read_scope_changed');
        $response = ChannelPostApiTransport::buttonRequest($platform, $method, $path, $query, $body);
        if ($path === 'getChat') {
            $chat = $response['data']['result'] ?? [];
            $targetUsernameMatches = strtolower((string) ($chat['username'] ?? '')) === 'anytour';
            $currentPin = isset($chat['pinned_message']['message_id']) ? (string) $chat['pinned_message']['message_id'] : null;
        }
        return $response;
    };
    $out['native_channel_rights'] = (new ChannelPostButtonTransport($readTransport))->verifyChannel($target);
    requireRead(($out['native_channel_rights']['receipt']['write_attempts'] ?? null) === 0, 'read_only_transport_contract_changed');
    $sourceChatRead = ChannelPostApiTransport::buttonRequest('telegram', 'POST', 'getChat', [], ['chat_id' => '-1002262666616']);
    $sourceChat = $sourceChatRead['data']['result'] ?? [];
    $sourceUsernameMatches = ($sourceChatRead['http'] ?? 0) === 200 && empty($sourceChatRead['transport_error'])
        && ($sourceChatRead['data']['ok'] ?? null) === true && ($sourceChat['type'] ?? '') === 'channel'
        && (string) ($sourceChat['id'] ?? '') === '-1002262666616'
        && strtolower((string) ($sourceChat['username'] ?? '')) === 'any_toour';
    $out['native_public_channel_binding'] = ['anytour' => $targetUsernameMatches, 'any_toour' => $sourceUsernameMatches];
    $out['native_read_request_count'] = (int) ($out['native_channel_rights']['receipt']['request_count'] ?? 0) + 1;
    $out['rows'] = [];
    foreach (PAIRS as $hotel => $pair) {
        $stage = 'hotel_' . $hotel;
        $operation = 'anytour-issue1566-historical-tg-media-' . $pair['target'] . '-v1';
        $q = $pdo->prepare('SELECT IS_FREE_LOCK(?)');
        $q->execute([$operation]);
        $free = $q->fetchColumn();
        $q->closeCursor();
        $directory = OPERATIONS . '/' . $operation;
        requireRead(!is_link($directory), 'operation_directory_symlink');
        $sourceNative = publicationRead($pdo, $registry, 10, $pair['source']);
        $targetNative = publicationRead($pdo, $registry, 6, $pair['target']);
        [$sourcePublic, $targetPublic] = $public[$hotel];
        $q = $pdo->prepare("SELECT p.id AS publication_id,p.post_channel_id,p.external_message_id,v.id AS version_id,v.state,"
            . "JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.post_url')) AS post_url FROM post_publications p "
            . "JOIN post_versions v ON v.id=p.current_version_id AND v.publication_id=p.id "
            . "WHERE p.post_channel_id IN (4,6) AND (JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id'))=? "
            . "OR JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.logical_id')) IN (?,?)) ORDER BY p.id LIMIT 101");
        $q->execute([(string) $hotel, 'commercial-hotel-' . $hotel . '-v1', 'commercial-main-hotel-' . $hotel . '-v1']);
        $existing = $q->fetchAll(PDO::FETCH_ASSOC);
        requireRead(count($existing) <= 100, 'existing_hotel_inventory_incomplete');
        $sourceMatches = $sourcePublic['ok'] && is_array($sourceNative) && $sourceNative['state'] === 'sent'
            && $sourceNative['post_url'] === $sourcePublic['url'] && $sourceNative['caption_sha256'] === $sourcePublic['caption_sha256']
            && (string) $sourceNative['hotel_id'] === (string) $hotel && !$sourceNative['native_claim_held_or_unknown']
            && count($sourceNative['attachments']) === 1 && $sourceNative['attachments'][0]['reusable_telegram_photo']
            && $sourcePublic['single_photo'] && $sourceNative['current_token_resolves'];
        $targetMatches = $targetPublic['ok'] && $targetPublic['published_unixtime'] === (new DateTimeImmutable($pair['date']))->getTimestamp()
            && $targetPublic['single_photo'] && preg_match('/(?<![\pL\pN])(?:отзыв\pL*|видеокруж\pL*|видео\s+от\s+менеджер\pL*)(?![\pL\pN])/iu', $targetPublic['caption']) === 0
            && ($targetNative === null || ($targetNative['state'] === 'sent' && !$targetNative['native_claim_held_or_unknown']
                && $targetNative['post_url'] === $targetPublic['url'] && $targetNative['caption_sha256'] === $targetPublic['caption_sha256']
                && count($targetNative['attachments']) === 1 && $targetNative['attachments'][0]['type'] === 'image'
                && empty($targetNative['monitor_price_record_id'])));
        $journal = receiptRead($directory . '/receipt.jsonl');
        $existingTg = array_values(array_filter($existing, static fn($p) => (int) $p['post_channel_id'] === 6));
        $existingMax = array_values(array_filter($existing, static fn($p) => (int) $p['post_channel_id'] === 4));
        $blockers = [];
        $targetUrl = 'https://t.me/anytour/' . $pair['target'];
        if (($feed['tg_' . $hotel] ?? null) !== 'https://t.me/any_toour/' . $pair['source']) $blockers[] = 'source_offer_changed';
        if (isset($protectedTargets[$targetUrl])) $blockers[] = 'target_used_by_live_telegram_offer';
        if ($pair['target'] === $currentPin) $blockers[] = 'target_is_current_channel_pin';
        if (($targetPublic['buttons'] ?? null) !== []) $blockers[] = 'target_buttons_changed';
        if (!$sourceMatches) $blockers[] = 'source_snapshot_or_photo_not_confirmed';
        if (!$targetMatches) $blockers[] = 'historical_target_not_confirmed';
        if ($free === false || $free === null || (int) $free !== 1) $blockers[] = 'target_editor_busy_or_unknown';
        if (file_exists($directory)) $blockers[] = 'existing_media_operation_do_not_replay';
        if ($targetNative !== null && $targetNative['content_edit_journal']['state'] !== 'absent') $blockers[] = 'existing_content_operation_requires_readback';
        if ($existingTg !== []) $blockers[] = 'hotel_already_registered_in_large_telegram';
        if (($out['native_channel_rights']['state'] ?? '') !== 'ready') $blockers[] = 'channel_edit_rights_not_confirmed';
        if (!$targetUsernameMatches || !$sourceUsernameMatches) $blockers[] = 'native_public_channel_binding_not_confirmed';
        $out['rows'][] = ['target_current_pin_matches' => $currentPin === $pair['target'], 'hotel_id' => $hotel, 'name' => $pair['name'], 'source_message_id' => $pair['source'],
            'target_message_id' => $pair['target'], 'source_native' => $sourceNative, 'target_native' => $targetNative,
            'source_public' => $sourcePublic, 'target_public' => $targetPublic,
            'target_media_lock_free' => $free !== false && $free !== null && (int) $free === 1,
            'prior_media_operation_directory_exists' => file_exists($directory), 'prior_media_operation' => $journal,
            'existing_large_channel_hotel_registry_matches' => $existing, 'large_max_hotel_already_registered' => $existingMax !== [],
            'source_matches' => $sourceMatches,
            'historical_target_matches' => $targetMatches,
            'preview_blockers' => $blockers, 'preview_ready' => $blockers === []];
    }
    $pdo->rollBack();
    runtimeRead();
    $out['read_at_utc'] = gmdate(DATE_ATOM);
    $out['ok'] = true;
    $out['checkpoint_sha256'] = hash('sha256', jsonRead($out));
} catch (Throwable $e) {
    $out['failure_stage'] = $stage;
    // Only our fixed machine reason is exposed; PDO/provider diagnostics may contain secrets.
    $out['failure_reason'] = reasonRead($e);
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (is_resource($priceLock)) { flock($priceLock, LOCK_UN); fclose($priceLock); }
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
exit($out['ok'] ? 0 : 2);

