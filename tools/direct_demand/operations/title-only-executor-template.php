<?php
declare(strict_types=1);
// Compiled constants: EXPECTED_INSTALLED_SHA, APPROVED_UPDATE_OPERATION,
// APPROVED_MANIFEST_SHA256, MANIFEST_BASE64. No web entry point or retry mode.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);

function need(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function jsonValue($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function durable($file, array $state): void
{
    need(fseek($file, 0, SEEK_END) === 0, 'receipt_write_failed');
    $raw = jsonValue($state)."\n";
    need(fwrite($file, $raw) === strlen($raw) && fflush($file), 'receipt_write_failed');
    if (function_exists('fsync')) need(fsync($file), 'receipt_sync_failed');
}

function installedGuard(): void
{
    $lines = []; $exitCode = 1;
    exec('git rev-parse HEAD', $lines, $exitCode);
    need($exitCode === 0 && trim(implode("\n", $lines)) === EXPECTED_INSTALLED_SHA,
        'installed_source_changed');
    $lines = []; $exitCode = 1;
    exec('git status --porcelain --untracked-files=no', $lines, $exitCode);
    need($exitCode === 0 && $lines === [], 'installed_checkout_changed');
}

function acceptedSurface(): void
{
    // Exactly the 22 reviewed pins from verify-commercial-source.py, including
    // ProjectConfig's new dependency. No title-edit/provider behavior is changed.
    $accepted = [
        "integrations/ChannelPostApiTransport.php" => "300634d77cb9fddf51adf9869974f956cca6e3f9",
        "migrations/033_channel_post_context.sql" => "0530458030a4bfe6b6cc55d1e5e441745c29b947",
        "migrations/036_channel_post_member_delivery.sql" => "1bda463e52b33130ad12752ddef4ee5c92699c05",
        "migrations/040_channel_post_reconciliation.sql" => "0f24b838eacd45333c5ade9a2991d060488c6035",
        "services/ChannelPostAdminService.php" => "b80eefc801054bfe6fdb58ec345ecdf687b2e89a",
        "services/ChannelPostAutomationService.php" => "f5975ab95de5f45e087eb7a0f768f566c5bca648",
        "services/ChannelPostButtonTransport.php" => "7b6711f1de63000346d8e5789f2dbb7f725535b8",
        "services/ChannelPostEntryService.php" => "f0e04e9b4e0ed6859ced96279cbf0619931596e6",
        "services/ChannelPostMaxAlbumRecovery.php" => "de266cd068587cc3b2ff83f308a966ea66887a04",
        "services/ChannelPostMediaService.php" => "f794efd0f6f24df916e18909ff6281b0bc928433",
        "services/ChannelPostMemberDeliveryService.php" => "20c1d7984898b2a5996a5f9e20e4d2d8c20b4778",
        "services/ChannelPostReconciliationService.php" => "2b559b067a91abb9402a9dc2f3ce75d1ddedc7c3",
        "services/ChannelPostReconciliationState.php" => "d9583ceca80d1424a44ade3561c21282a23a52f0",
        "services/ChannelPostRegistryService.php" => "8335bf2fb0c1cd78f268d4e90fad1a4465fa6390",
        "services/ChannelPostRuntime.php" => "2f1dd9b77474fdbaa92f0402f3b0aa170c595474",
        "services/ChannelPostTelegramAlbum.php" => "faa1a14bc279a4af33936282126bf0d46e343040",
        "services/ChannelPostWebhookConfigService.php" => "d5fdcb056088f1e3a541b4046325d97eef6417d9",
        "services/ConversationDb.php" => "bb6281faf55aca7beeae7f8a57389d19751936f7",
        "services/MaxTlsConfig.php" => "032c0211ee3f39c6f0d27c669abd5b467e321dea",
        "services/MaxTransport.php" => "2cb4f8786b4704a8e62a3a9c40dc2cc73dad6159",
        "services/ProjectConfig.php" => "63c17c8103412f8cff8cdcc4bbb062e744106af9",
        "services/ProjectConfiguration.php" => "07019d473c588e3386ace42977db93092e8112e3",
    ];
    need(count($accepted) === 22, 'publishing_surface_count_changed');
    foreach ($accepted as $path => $blob) {
        $lines = []; $exitCode = 1;
        exec('git hash-object -- '.escapeshellarg($path), $lines, $exitCode);
        need($exitCode === 0 && trim(implode("\n", $lines)) === $blob, 'publishing_surface_changed');
    }
}

function shortenedText(string $old): string
{
    need(mb_check_encoding($old, 'UTF-8') && !str_contains($old, "\0"), 'invalid_caption');
    $parts = explode("\n", $old, 2);
    $count = 0;
    $heading = preg_replace('/ с перел[её]том\z/u', '', $parts[0], 1, $count);
    need($count === 1 && is_string($heading) && $heading !== '', 'exact_terminal_suffix_missing');
    return $heading.(count($parts) === 2 ? "\n".$parts[1] : '');
}


// Pure helpers. The bounded executor supplies need(bool, string).
// No provider requests, registry changes, filesystem writes, or entry point.

function shortTitleUtf16Boundary(string $text16, int $offset): bool
{
    $units = intdiv(strlen($text16), 2);
    if ($offset < 0 || $offset > $units || strlen($text16) % 2 !== 0) return false;
    if ($offset === 0 || $offset === $units) return true;
    $before = ord($text16[$offset * 2 - 2]) | (ord($text16[$offset * 2 - 1]) << 8);
    $after = ord($text16[$offset * 2]) | (ord($text16[$offset * 2 + 1]) << 8);
    return !($before >= 0xD800 && $before <= 0xDBFF && $after >= 0xDC00 && $after <= 0xDFFF);
}

function shortTitleUtf16Slice(string $text16, int $offset, int $length): string
{
    need($length >= 0 && shortTitleUtf16Boundary($text16, $offset)
        && shortTitleUtf16Boundary($text16, $offset + $length), 'markup_splits_surrogate');
    return mb_convert_encoding(substr($text16, $offset * 2, $length * 2), 'UTF-8', 'UTF-16LE');
}

function shortTitleMarkupFields(array $span, string $platform): void
{
    $type = $span['type'] ?? null;
    need(is_string($type), 'markup_type_invalid');
    if ($platform === 'max') {
        need(in_array($type, ['strong', 'emphasized', 'underline', 'strikethrough', 'monospaced', 'link'], true),
            'max_markup_type_unsupported');
        $allowed = $type === 'link' ? ['type', 'from', 'length', 'url'] : ['type', 'from', 'length'];
        need(array_diff(array_keys($span), $allowed) === [], 'max_markup_fields_unsupported');
        if ($type === 'link') {
            $url = $span['url'] ?? null;
            need(is_string($url) && mb_check_encoding($url, 'UTF-8')
                && preg_match('~\Ahttps?://[^\x00-\x20\x7f]+\z~D', $url) === 1
                && is_string(parse_url($url, PHP_URL_HOST)) && parse_url($url, PHP_URL_HOST) !== '',
                'max_markup_link_unsupported');
        }
        return;
    }
    $simple = ['mention', 'hashtag', 'cashtag', 'bot_command', 'url', 'email', 'phone_number',
        'bold', 'italic', 'underline', 'strikethrough', 'spoiler', 'blockquote', 'expandable_blockquote', 'code'];
    $extra = ['pre'=>['language'], 'text_link'=>['url'], 'text_mention'=>['user'],
        'custom_emoji'=>['custom_emoji_id'], 'date_time'=>['unix_time', 'date_time_format']];
    need(in_array($type, $simple, true) || isset($extra[$type]), 'telegram_markup_type_unsupported');
    need(array_diff(array_keys($span), array_merge(['type', 'offset', 'length'], $extra[$type] ?? [])) === [],
        'telegram_markup_fields_unsupported');
    foreach (['language', 'url', 'custom_emoji_id', 'date_time_format'] as $field) {
        if (array_key_exists($field, $span)) need(is_string($span[$field]) && mb_check_encoding($span[$field], 'UTF-8'),
            'telegram_markup_value_invalid');
    }
    if ($type === 'text_link') need(is_string($span['url'] ?? null) && $span['url'] !== '', 'telegram_markup_link_invalid');
    if ($type === 'text_mention') need(is_array($span['user'] ?? null) && is_int($span['user']['id'] ?? null)
        && $span['user']['id'] > 0, 'telegram_markup_mention_invalid');
    if ($type === 'custom_emoji') need(is_string($span['custom_emoji_id'] ?? null)
        && preg_match('/\A[0-9]+\z/D', $span['custom_emoji_id']) === 1, 'telegram_markup_emoji_invalid');
    if (array_key_exists('unix_time', $span)) need(is_int($span['unix_time']), 'telegram_markup_datetime_invalid');
}

/** Preserve native spans around exactly one terminal first-line suffix deletion. */
function shortTitleProviderText(string $platform, string $oldText, string $newText, array $oldMarkup): array
{
    need(in_array($platform, ['max', 'telegram'], true), 'markup_platform_invalid');
    need(mb_check_encoding($oldText, 'UTF-8') && mb_check_encoding($newText, 'UTF-8'), 'markup_text_invalid');
    need(array_is_list($oldMarkup) && count($oldMarkup) <= 100, 'markup_list_invalid');
    $firstLineBytes = strcspn($oldText, "\r\n");
    $firstLine = substr($oldText, 0, $firstLineBytes);
    $suffix = null;
    foreach ([' с перелётом', ' с перелетом'] as $candidate) {
        if (str_ends_with($firstLine, $candidate)) { $suffix = $candidate; break; }
    }
    need(is_string($suffix) && strlen($firstLine) > strlen($suffix ?? ''), 'first_line_suffix_missing');
    $cutByte = $firstLineBytes - strlen($suffix);
    need($newText === substr($oldText, 0, $cutByte) . substr($oldText, $firstLineBytes),
        'only_first_line_suffix_removal_allowed');
    $old16 = mb_convert_encoding($oldText, 'UTF-16LE', 'UTF-8');
    $new16 = mb_convert_encoding($newText, 'UTF-16LE', 'UTF-8');
    $cutStart = intdiv(strlen(mb_convert_encoding(substr($oldText, 0, $cutByte), 'UTF-16LE', 'UTF-8')), 2);
    $cutEnd = intdiv(strlen(mb_convert_encoding($firstLine, 'UTF-16LE', 'UTF-8')), 2);
    $oldUnits = intdiv(strlen($old16), 2);
    $position = $platform === 'max' ? 'from' : 'offset';
    // MAX's official formatter uses UTF-16 code units, including surrogate pairs:
    // https://github.com/max-messenger/maxbot/blob/06b71f88c49e80222204ad1a10614338ddfe4124/formatter/formatter.go
    $adjusted = [];
    $ranges = [];
    foreach ($oldMarkup as $span) {
        need(is_array($span) && !array_is_list($span), 'markup_span_invalid');
        shortTitleMarkupFields($span, $platform);
        $start = $span[$position] ?? null;
        $length = $span['length'] ?? null;
        need(is_int($start) && is_int($length) && $start >= 0 && $start <= $oldUnits
            && $length > 0 && $length <= $oldUnits - $start, 'markup_range_invalid');
        $end = $start + $length;
        need(shortTitleUtf16Boundary($old16, $start) && shortTitleUtf16Boundary($old16, $end), 'markup_splits_surrogate');
        $ranges[] = [$start, $end];
        $map = static fn(int $x): int => $x <= $cutStart ? $x : ($x >= $cutEnd ? $x - ($cutEnd - $cutStart) : $cutStart);
        $newStart = $map($start);
        $newEnd = $map($end);
        if ($newEnd === $newStart) continue;
        need(shortTitleUtf16Boundary($new16, $newStart) && shortTitleUtf16Boundary($new16, $newEnd), 'markup_splits_surrogate');
        $span[$position] = $newStart;
        $span['length'] = $newEnd - $newStart;
        $adjusted[] = $span;
    }
    if ($platform === 'telegram') return ['markup'=>$adjusted, 'max_html'=>null];
    usort($ranges, static fn(array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
    $end = 0;
    foreach ($ranges as $range) {
        need($range[0] >= $end, 'max_overlapping_markup_unsupported');
        $end = $range[1];
    }
    usort($adjusted, static fn(array $a, array $b): int => ($a['from'] <=> $b['from']) ?: ($a['length'] <=> $b['length']));
    $tags = ['strong'=>'b', 'emphasized'=>'i', 'underline'=>'u', 'strikethrough'=>'s', 'monospaced'=>'code', 'link'=>'a'];
    $html = '';
    $cursor = 0;
    foreach ($adjusted as $span) {
        $html .= htmlspecialchars(shortTitleUtf16Slice($new16, $cursor, $span['from'] - $cursor), ENT_QUOTES, 'UTF-8');
        $tag = $tags[$span['type']];
        $attribute = $span['type'] === 'link' ? ' href="' . htmlspecialchars($span['url'], ENT_QUOTES, 'UTF-8') . '"' : '';
        $html .= '<' . $tag . $attribute . '>'
            . htmlspecialchars(shortTitleUtf16Slice($new16, $span['from'], $span['length']), ENT_QUOTES, 'UTF-8')
            . '</' . $tag . '>';
        $cursor = $span['from'] + $span['length'];
    }
    $html .= htmlspecialchars(shortTitleUtf16Slice($new16, $cursor, intdiv(strlen($new16), 2) - $cursor), ENT_QUOTES, 'UTF-8');
    return ['markup'=>$adjusted, 'max_html'=>$html];
}

function shortTitleMarkupCanonicalValue($value)
{
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as &$item) $item = shortTitleMarkupCanonicalValue($item);
    unset($item);
    return $value;
}

/** Span ordering and object-key ordering do not change formatting semantics. */
function shortTitleMarkupEqual(array $expected, array $actual): bool
{
    if (!array_is_list($expected) || !array_is_list($actual) || count($expected) !== count($actual)) return false;
    $normalized = [];
    try {
        foreach ([$expected, $actual] as $spans) {
            $items = [];
            foreach ($spans as $span) {
                if (!is_array($span) || array_is_list($span)) return false;
                $items[] = json_encode(shortTitleMarkupCanonicalValue($span),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            }
            sort($items, SORT_STRING);
            $normalized[] = $items;
        }
    } catch (Throwable $ignored) { return false; }
    return $normalized[0] === $normalized[1];
}

function photoSignature(array $snapshot): string
{
    $media = $snapshot['attachments'] ?? null;
    need(is_array($media) && array_is_list($media) && count($media) === 1
        && ($media[0]['type'] ?? '') === 'image', 'expected_one_photo');
    $m = $media[0];
    $provider = (string)($m['provider'] ?? '');
    $id = (string)($m['photo_id'] ?? $m['file_unique_id'] ?? '');
    need(in_array($provider, ['max', 'telegram'], true)
        && preg_match('/\A[A-Za-z0-9_-]+\z/D', $id) === 1, 'photo_stable_id_missing');
    return $provider.':'.$id;
}

function attachmentHash(array $snapshot): string
{
    need(is_array($snapshot['attachments'] ?? null), 'attachment_snapshot_missing');
    return hash('sha256', jsonValue($snapshot['attachments']));
}

function channelFingerprint(array $channel): string
{
    $identity = [];
    foreach (['id', 'project_id', 'project_key', 'platform', 'channel_id', 'bot_key',
              'canonical_bot_username', 'source_id', 'source_key', 'bot_url',
              'button_text', 'post_mode', 'config_version'] as $key) {
        $identity[$key] = (string)($channel[$key] ?? ($key === 'post_mode' ? 'append_button' : ''));
    }
    $identity['is_enabled'] = !empty($channel['is_enabled']);
    return hash('sha256', jsonValue($identity));
}

function noActiveVersions(PDO $pdo, array $ids): void
{
    need(count($ids) >= 1 && count($ids) <= 100, 'active_check_scope_invalid');
    $query = $pdo->prepare('SELECT COUNT(*) FROM post_versions WHERE publication_id IN ('
        .implode(',', array_fill(0, count($ids), '?')).") AND (state IN ('claimed','write_started','unknown')"
        .' OR claim_token IS NOT NULL OR claimed_at IS NOT NULL)');
    $query->execute($ids);
    need((int)$query->fetchColumn() === 0, 'existing_publication_active_or_unknown_stop');
}

function readMax(string $id, string $channel): array
{
    need(preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D', $id) === 1, 'invalid_max_message_id');
    $response = ChannelPostApiTransport::buttonRequest('max', 'GET', '/messages/'.$id, [], null);
    need(empty($response['transport_error']) && (int)$response['http'] === 200
        && is_array($response['data']), 'max_read_failed');
    $message = $response['data'];
    need(($message['body']['mid'] ?? null) === $id
        && (string)($message['recipient']['chat_id'] ?? '') === $channel
        && ($message['recipient']['chat_type'] ?? '') === 'channel', 'max_read_identity_changed');
    return $message;
}

function telegramCaption(array $body): array
{
    // The only Telegram mutation in this executor is the existing-caption API.
    $handle = curl_init('https://api.telegram.org/bot'.TELEGRAM_BOT_TOKEN.'/editMessageCaption');
    curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($body),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45]);
    $raw = curl_exec($handle);
    $http = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $errno = curl_errno($handle);
    curl_close($handle);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return ['http' => $http, 'transport_error' => $errno, 'data' => is_array($data) ? $data : []];
}

function snapshotMatches(array $snapshot, array $plan, string $text, array $markup): bool
{
    return ($snapshot['text'] ?? '') === $text
        && photoSignature($snapshot) === $plan['photo_signature']
        && ($snapshot['post_url'] ?? '') === $plan['post_url']
        && is_array($snapshot['markup'] ?? null)
        && shortTitleMarkupEqual($markup, $snapshot['markup'])
        && ChannelPostIncomingAdapter::containsOnlyButton($snapshot, $plan['button_url'], $plan['button_text']);
}

function currentPublication($registry, array $row, array $target, string $channelHash): array
{
    $p = $registry->publication($row['publication_id']);
    need(is_array($p) && (int)$p['publication_id'] === $row['publication_id']
        && $p['project_key'] === 'anytour' && $p['platform'] === $row['platform']
        && $p['bot_key'] === $target['bot'] && $p['channel_id'] === $target['channel']
        && $p['external_message_id'] === $row['message_id'] && $p['source_key'] === $target['source'],
        'existing_publication_identity_changed');
    need((int)$p['version_id'] === $row['version_id'] && $p['state'] === 'sent',
        'captured_version_or_state_changed');
    need((int)$p['channel']['config_version'] === $row['config_version']
        && channelFingerprint($p['channel']) === $channelHash, 'channel_configuration_changed');
    $snapshot = $p['snapshot'];
    need(($snapshot['text'] ?? '') === $row['old_text']
        && hash('sha256', (string)$snapshot['text']) === $row['old_text_sha256'], 'existing_caption_changed');
    need(($snapshot['post_url'] ?? '') === $row['post_url']
        && photoSignature($snapshot) === $row['photo_signature']
        && attachmentHash($snapshot) === $row['attachments_sha256'], 'captured_photo_or_post_changed');
    $url = ChannelPostButtonTransport::buttonUrl($p);
    need($url !== '' && hash('sha256', $url) === $row['button_url_sha256'], 'captured_button_url_changed');
    // Absence in old imported metadata is retained; it is not a missing-button diagnosis.
    need(ChannelPostIncomingAdapter::containsOnlyButton($snapshot, $url, '✈️ Узнать стоимость тура')
        === $row['button_observed_in_snapshot'], 'captured_button_observation_changed');
    $resolved = ChannelPostEntryService::resolve($row['platform'], $p['start_payload'], $registry);
    need(is_array($resolved) && (int)$resolved['publication_id'] === $row['publication_id']
        && (int)$resolved['version_id'] === $row['version_id'] && $resolved['source_key'] === $row['source_key'],
        'existing_token_resolution_changed');
    return $p;
}

/** A separate, newly authorized content revision inside the existing once-only editor. */
function isApprovedCaptionQuality(array $payload): bool
{
    return ($payload['update_kind'] ?? '') === 'approved_existing_caption_quality_revision';
}

function captionQualityScope(array $payload): void
{
    need(isApprovedCaptionQuality($payload)
        && ($payload['quality_cohort_sha256'] ?? '') === 'ef5e14d2aba7f4b412acfcfd71967ae986d2876d7e3ba14361119cff62b815fd'
        && ($payload['quality_authorization'] ?? '') === 'user_20261108_accepted_content_quality_followup'
        && ($payload['blocked_operation_not_resumed'] ?? '') === 'commercial-hotel-caption-review6706115450-20261107-1-v1',
        'approved_quality_revision_scope_changed');
    $prefix = 'commercial-content-quality-20261108-cohort159';
    $index = $payload['batch_index'] ?? null;
    $rows = $payload['rows'] ?? [];
    if (($payload['batch_kind'] ?? '') === 'canary') {
        need($index === 0 && ($payload['operation'] ?? '') === $prefix.'-canary-v1'
            && count($rows) === 8 && ($payload['predecessor'] ?? null) === null,
            'quality_canary_scope_changed');
        $pairs = [];
        foreach ($rows as $row) $pairs[] = $row['hotel_id'].':'.$row['platform'];
        sort($pairs);
        $expected = ['1352:max','1352:telegram','1074:max','1074:telegram','462:max','462:telegram','1659:max','1659:telegram'];
        sort($expected);
        need($pairs === $expected, 'quality_canary_hotels_changed');
    } else {
        need(($payload['batch_kind'] ?? '') === 'batch' && is_int($index) && $index >= 1 && $index <= 7
            && ($payload['operation'] ?? '') === $prefix.sprintf('-batch%03d-v1', $index)
            && count($rows) === ($index === 7 ? 10 : 50), 'quality_batch_scope_changed');
        $previous = $prefix.($index === 1 ? '-canary-v1' : sprintf('-batch%03d-v1', $index - 1));
        need(($payload['predecessor']['operation'] ?? '') === $previous
            && preg_match('/\A[a-f0-9]{64}\z/D', (string)($payload['predecessor']['manifest_sha256'] ?? '')) === 1,
            'quality_predecessor_changed');
        foreach ($rows as $row) {
            need(!in_array($row['hotel_id'], [1352,1074,462,1659], true), 'quality_canary_repeated');
        }
    }
}

/** Approved copy uses a bold unchanged heading and plain, escaped body on both providers. */
function captionQualityProviderText(string $platform, string $oldText, string $newText): array
{
    need(in_array($platform, ['max','telegram'], true)
        && mb_check_encoding($oldText, 'UTF-8') && mb_check_encoding($newText, 'UTF-8'),
        'quality_text_or_platform_invalid');
    $old = explode("\n", $oldText, 2);
    $new = explode("\n", $newText, 2);
    need(count($old) === 2 && count($new) === 2 && $old[0] === $new[0]
        && str_starts_with($new[0], '✈️ Туры в ') && !str_contains($new[0], 'с перел')
        && $oldText !== $newText && !str_contains($newText, "\r")
        && strlen(mb_convert_encoding($newText, 'UTF-16LE', 'UTF-8')) / 2 <= 1024,
        'quality_unchanged_short_heading_required');
    need(!str_contains($newText, 'https://anytour.online/poisk-turov/')
        && !str_contains($newText, '🕒 Проверено') && !str_contains($newText, '✈️ Вылет:')
        && !str_contains($newText, 'Нажмите'), 'quality_legacy_block_remaining');
    $length = intdiv(strlen(mb_convert_encoding($new[0], 'UTF-16LE', 'UTF-8')), 2);
    $markup = $platform === 'max'
        ? [['type'=>'strong','from'=>0,'length'=>$length]]
        : [['type'=>'bold','offset'=>0,'length'=>$length]];
    return ['markup'=>$markup, 'max_html'=>$platform === 'max'
        ? '<b>'.htmlspecialchars($new[0], ENT_QUOTES, 'UTF-8').'</b>'."\n".htmlspecialchars($new[1], ENT_QUOTES, 'UTF-8')
        : null];
}

function validateManifest(array $payload): array
{
    need(($payload['schema_version'] ?? null) === 1
        && ($payload['operation'] ?? null) === APPROVED_UPDATE_OPERATION
        && preg_match('/\Acommercial-[a-z0-9-]{1,115}-v1\z/D', APPROVED_UPDATE_OPERATION) === 1,
        'exact_title_operation_changed');
    need(($payload['expected_installed_sha'] ?? null) === EXPECTED_INSTALLED_SHA
        && (($payload['update_kind'] ?? null) === 'first_line_flight_suffix_only' || isApprovedCaptionQuality($payload))
        && ($payload['button_text'] ?? null) === '✈️ Узнать стоимость тура'
        && ($payload['throttle_microseconds'] ?? null) === 3200000, 'title_only_scope_changed');
    $source = $payload['snapshot'] ?? [];
    need(($source['operation'] ?? null) === 'commercial-short-heading-registry-audit-20261107-v1'
        && ($source['installed_sha'] ?? null) === EXPECTED_INSTALLED_SHA
        && ($source['post_count'] ?? null) === 916
        && preg_match('/\A[a-f0-9]{64}\z/D', (string)($source['sha256'] ?? '')) === 1
        && is_string($source['observed_at_utc'] ?? null) && strtotime($source['observed_at_utc']) !== false,
        'fresh_snapshot_binding_changed');
    $rows = $payload['rows'] ?? null;
    need(is_array($rows) && array_is_list($rows) && count($rows) >= 1 && count($rows) <= 100
        && ($payload['row_count'] ?? null) === count($rows), 'bounded_post_scope_changed');
    $publications = []; $messages = []; $pairs = [];
    foreach ($rows as $row) {
        need(is_array($row) && in_array($row['platform'] ?? '', ['max', 'telegram'], true), 'invalid_platform');
        foreach (['hotel_id', 'publication_id', 'version_id', 'config_version'] as $field) {
            need(is_int($row[$field] ?? null) && $row[$field] > 0, 'invalid_bound_identity');
        }
        $id = $row['publication_id']; $platform = $row['platform']; $mid = $row['message_id'] ?? null;
        need(is_string($mid) && preg_match($platform === 'max' ? '/\A[A-Za-z0-9_.-]{1,191}\z/D'
            : '/\A[1-9][0-9]{0,19}\z/D', $mid) === 1, 'unsupported_message_identity');
        need(!isset($publications[$id]) && !isset($messages[$platform.':'.$mid])
            && !isset($pairs[$row['hotel_id'].':'.$platform]), 'duplicate_bound_post');
        $publications[$id] = true; $messages[$platform.':'.$mid] = true; $pairs[$row['hotel_id'].':'.$platform] = true;
        need(($row['source_key'] ?? '') === ($platform === 'max' ? 'max_anytour_msk1' : 'tg_anytour_msk'),
            'bound_source_changed');
        need(is_string($row['post_url'] ?? null) && ($platform === 'max'
            ? preg_match('~\Ahttps://max\.ru/channel_anytour/[A-Za-z0-9_-]+\z~D', $row['post_url']) === 1
            : $row['post_url'] === 'https://t.me/any_toour/'.$mid), 'bound_post_url_changed');
        foreach (['old_text_sha256', 'new_text_sha256', 'attachments_sha256', 'button_url_sha256'] as $hash) {
            need(is_string($row[$hash] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $row[$hash]) === 1,
                'bound_hash_missing');
        }
        need(is_string($row['old_text'] ?? null) && is_string($row['new_text'] ?? null)
            && hash('sha256', $row['old_text']) === $row['old_text_sha256']
            && hash('sha256', $row['new_text']) === $row['new_text_sha256']
            && (isApprovedCaptionQuality($payload) || shortenedText($row['old_text']) === $row['new_text'])
            && strlen(mb_convert_encoding($row['new_text'], 'UTF-16LE', 'UTF-8')) / 2 <= 1024,
            'only_terminal_first_line_suffix_allowed');
        need(is_string($row['photo_signature'] ?? null)
            && preg_match('/\A'.preg_quote($platform, '/').':[A-Za-z0-9_-]+\z/D', $row['photo_signature']) === 1
            && is_bool($row['button_observed_in_snapshot'] ?? null), 'bound_photo_or_observation_missing');
    }
    if (isApprovedCaptionQuality($payload)) {
        captionQualityScope($payload);
        foreach ($rows as $row) captionQualityProviderText($row['platform'], $row['old_text'], $row['new_text']);
        return $rows;
    }
    $index = $payload['batch_index'] ?? null;
    need(is_int($index) && $index >= 0 && $index <= 916, 'batch_index_invalid');
    if (($payload['batch_kind'] ?? '') === 'canary') {
        need($index === 0 && count($rows) === 2 && isset($pairs['1543:max'], $pairs['1543:telegram'])
            && ($payload['predecessor'] ?? null) === null
            && str_ends_with(APPROVED_UPDATE_OPERATION, '-canary-v1'), 'susesi_canary_scope_changed');
    } else {
        need(($payload['batch_kind'] ?? '') === 'batch' && $index > 0
            && str_ends_with(APPROVED_UPDATE_OPERATION, sprintf('-batch%03d-v1', $index)), 'batch_identity_changed');
        $prefix = substr(APPROVED_UPDATE_OPERATION, 0, -strlen(sprintf('-batch%03d-v1', $index)));
        $previous = $prefix.($index === 1 ? '-canary-v1' : sprintf('-batch%03d-v1', $index - 1));
        need(($payload['predecessor']['operation'] ?? '') === $previous
            && preg_match('/\A[a-f0-9]{64}\z/D', (string)($payload['predecessor']['manifest_sha256'] ?? '')) === 1,
            'sequential_predecessor_changed');
    }
    return $rows;
}

function verifyPredecessor(string $base, array $payload): void
{
    $previous = $payload['predecessor'] ?? null;
    if ($previous === null) return;
    $dir = $base.'/'.$previous['operation']; $path = $dir.'/receipt.jsonl';
    need(!is_link($dir) && realpath($dir) === $dir && !is_link($path) && is_file($path),
        'previous_batch_receipt_missing');
    need(filesize($path) <= 134217728, 'previous_batch_receipt_too_large');
    $raw = file_get_contents($path);
    need(is_string($raw) && $raw !== '' && str_ends_with($raw, "\n"), 'previous_batch_receipt_incomplete');
    $lines = explode("\n", rtrim($raw, "\n"));
    $state = json_decode(end($lines), true, 128, JSON_THROW_ON_ERROR);
    need(($state['operation'] ?? '') === $previous['operation']
        && ($state['manifest_sha256'] ?? '') === $previous['manifest_sha256']
        && ($state['complete'] ?? false) === true
        && count($state['posts'] ?? []) === ($state['row_count'] ?? null), 'previous_batch_not_confirmed');
    foreach ($state['posts'] as $post) {
        need(($post['state'] ?? '') === 'confirmed'
            && ($post['receipt']['body_photo_button_verified'] ?? false) === true
            && ($post['receipt']['existing_message_reused'] ?? false) === true,
            'previous_batch_outcome_unresolved');
    }
}

$out = ['operation' => defined('APPROVED_UPDATE_OPERATION') ? APPROVED_UPDATE_OPERATION : '',
    'mode' => 'existing_post_first_line_suffix_only', 'ok' => false, 'post_write_attempts' => 0,
    'new_posts_created' => 0, 'configuration_write_attempts' => 0, 'button_write_attempts' => 0,
    'tour_search_calls' => 0, 'price_queries' => 0, 'posts' => []];
$lock = null; $state = null;
try {
    need(PHP_SAPI === 'cli' && defined('EXPECTED_INSTALLED_SHA') && defined('MANIFEST_BASE64')
        && defined('APPROVED_MANIFEST_SHA256') && defined('APPROVED_UPDATE_OPERATION'), 'compiled_constants_missing');
    need(preg_match('/\A[a-f0-9]{40}\z/D', EXPECTED_INSTALLED_SHA) === 1, 'installed_sha_invalid');
    installedGuard(); acceptedSurface();
    $inputRaw = base64_decode(MANIFEST_BASE64, true);
    need(is_string($inputRaw) && hash('sha256', $inputRaw) === APPROVED_MANIFEST_SHA256,
        'approved_manifest_bytes_changed');
    $payload = json_decode($inputRaw, true, 128, JSON_THROW_ON_ERROR);
    need(is_array($payload), 'invalid_manifest');
    $rows = validateManifest($payload);
    $quality = isApprovedCaptionQuality($payload);
    if ($quality) $out['mode'] = 'approved_existing_caption_quality_revision';
    ob_start();
    try {
        require getcwd().'/config.php';
        require_once getcwd().'/services/ChannelPostRuntime.php';
        require_once getcwd().'/services/ChannelPostAdminService.php';
        require_once getcwd().'/services/ChannelPostEntryService.php';
        require_once getcwd().'/services/MaxTransport.php';
        require_once getcwd().'/integrations/ChannelPostApiTransport.php';
        require_once getcwd().'/services/ChannelPostButtonTransport.php';
        require_once getcwd().'/services/ChannelPostAutomationService.php';
    } finally { ob_end_clean(); }
    $pdo = ConversationDb::connection();
    $registry = new ChannelPostRegistryService($pdo);
    // Keep the canonical claim/confirmation path. The expected button is already
    // present: an unexpected extra button write is outside this operation.
    $confirmationTransport = new ChannelPostButtonTransport(static function (
        string $platform, string $method, string $path, array $query, ?array $body
    ): array {
        need(($platform === 'max' && $method === 'GET')
            || ($platform === 'telegram' && $method === 'POST'
                && in_array($path, ['getMe', 'getChat', 'getChatMember'], true)), 'button_write_outside_title_scope');
        return ChannelPostButtonTransport::networkRequest($platform, $method, $path, $query, $body);
    });
    $automation = new ChannelPostAutomationService($registry, $confirmationTransport);
    foreach (['033_channel_post_context.sql', '036_channel_post_member_delivery.sql',
              '040_channel_post_reconciliation.sql'] as $migration) {
        $query = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');
        $query->execute([$migration]);
        need($query->fetchColumn() === hash_file('sha256', getcwd().'/migrations/'.$migration),
            'publishing_schema_not_accepted');
    }
    noActiveVersions($pdo, array_column($rows, 'publication_id'));
    $base = '/var/www/anytoour/data/channel-post-operations';
    need(!is_link($base) && realpath($base) === $base, 'invalid_operation_root');
    verifyPredecessor($base, $payload);
    $dir = $base.'/'.APPROVED_UPDATE_OPERATION;
    need(!is_link($dir) && !file_exists($dir), 'operation_already_exists_no_replay');
    $targets = [
        'max' => ['bot' => 'id9704048781_1_bot', 'channel' => '-80492244209683', 'source' => 'max_anytour_msk1', 'id' => 9],
        'telegram' => ['bot' => 'anyru_bot', 'channel' => '-1002262666616', 'source' => 'tg_anytour_msk', 'id' => 10],
    ];
    $channels = []; $channelHashes = []; $plans = [];
    foreach (array_unique(array_column($rows, 'platform')) as $platform) {
        $target = $targets[$platform];
        $identity = ChannelPostRuntime::identity($platform);
        need(is_array($identity) && $identity['project_key'] === 'anytour'
            && $identity['bot_key'] === $target['bot'], 'configured_bot_changed');
        $channel = $registry->channel('anytour', $platform, $target['bot'], $target['channel']);
        need(is_array($channel) && (int)$channel['id'] === $target['id']
            && $channel['source_key'] === $target['source'] && $channel['button_text'] === $payload['button_text']
            && !empty($channel['is_enabled']) && ($channel['post_mode'] ?? 'append_button') === 'append_button',
            'channel_configuration_changed');
        need(((new ChannelPostButtonTransport())->verifyChannel($channel)['state'] ?? '') === 'ready',
            'provider_channel_rights_changed');
        $channels[$platform] = $channel; $channelHashes[$platform] = channelFingerprint($channel);
    }
    // Resolve the entire bounded batch, including formatting, before its first write.
    foreach ($rows as $row) {
        $platform = $row['platform']; $target = $targets[$platform];
        $p = currentPublication($registry, $row, $target, $channelHashes[$platform]);
        $plan = $row + ['token' => $p['start_payload'], 'button_url' => ChannelPostButtonTransport::buttonUrl($p),
            'button_text' => $payload['button_text'], 'attachments' => $p['snapshot']['attachments'],
            'old_metadata' => (array)($p['snapshot']['metadata'] ?? [])];
        $before = $p['snapshot'];
        if ($platform === 'max') {
            $before = ChannelPostIncomingAdapter::maxSnapshot(readMax($row['message_id'], $target['channel']));
            need(is_array($before) && ($before['text'] ?? '') === $row['old_text']
                && photoSignature($before) === $row['photo_signature'] && ($before['post_url'] ?? '') === $row['post_url']
                && ChannelPostIncomingAdapter::containsOnlyButton($before, $plan['button_url'], $payload['button_text']),
                'existing_max_readback_changed');
        }
        need(is_array($before['markup'] ?? null), 'current_native_markup_unavailable');
        $plan['old_markup'] = $before['markup'];
        $plan['old_forward_context'] = $before['metadata']['link'] ?? null;
        $format = $quality ? captionQualityProviderText($platform, $row['old_text'], $row['new_text'])
            : shortTitleProviderText($platform, $row['old_text'], $row['new_text'], $plan['old_markup']);
        $plan['new_markup'] = $format['markup']; $plan['max_html'] = $format['max_html'];
        $plans[] = $plan;
    }
    installedGuard();
    need(mkdir($dir, 0700) && realpath($dir) === $dir, 'operation_already_exists_no_replay');
    $receiptPath = $dir.'/receipt.jsonl';
    need(!is_link($receiptPath) && !file_exists($receiptPath), 'prior_edit_requires_reconciliation');
    $lock = fopen($receiptPath, 'x+');
    need($lock !== false && flock($lock, LOCK_EX | LOCK_NB), 'operation_busy');
    $state = ['operation' => APPROVED_UPDATE_OPERATION, 'manifest_sha256' => APPROVED_MANIFEST_SHA256,
        'snapshot_sha256' => $payload['snapshot']['sha256'], 'row_count' => count($plans),
        'installed_sha' => EXPECTED_INSTALLED_SHA, 'complete' => false, 'posts' => []];
    durable($lock, $state);
    $out['preflight_existing_messages'] = count($plans);
    foreach ($plans as $plan) {
        $key = $plan['platform'].':'.$plan['publication_id'];
        $platform = $plan['platform']; $target = $targets[$platform]; $channel = $channels[$platform];
        $out['current'] = ['platform' => $platform, 'publication_id' => $plan['publication_id']];
        installedGuard();
        noActiveVersions($pdo, [$plan['publication_id']]);
        $p = currentPublication($registry, $plan, $target, $channelHashes[$platform]);
        need(hash_equals((string)$p['start_payload'], $plan['token']), 'target_token_changed_before_edit');
        if ($platform === 'max') {
            $before = ChannelPostIncomingAdapter::maxSnapshot(readMax($plan['message_id'], $target['channel']));
            need(is_array($before) && snapshotMatches($before, $plan, $plan['old_text'], $plan['old_markup'])
                && ($before['metadata']['link'] ?? null) === $plan['old_forward_context'], 'max_changed_before_edit');
        }
        $requestStart = (int)floor(microtime(true) * 1000);
        $state['posts'][$key] = ['state' => 'edit_started', 'plan' => $plan, 'edit_request_start_ms' => $requestStart];
        durable($lock, $state);
        // Last source fence immediately before each single provider edit; no retry.
        installedGuard();
        $out['post_write_attempts']++;
        if ($platform === 'telegram') {
            $body = ['chat_id' => $target['channel'], 'message_id' => $plan['message_id'],
                'caption' => $plan['new_text'], 'caption_entities' => jsonValue($plan['new_markup']),
                'reply_markup' => jsonValue(['inline_keyboard' => [[['text' => $payload['button_text'],
                                                                  'url' => $plan['button_url']]]]])];
            $response = telegramCaption($body);
            $state['posts'][$key]['provider_response_private'] = $response;
            $state['posts'][$key]['state'] = 'provider_response_recorded'; durable($lock, $state);
            need($response['transport_error'] === 0 && $response['http'] === 200
                && ($response['data']['ok'] ?? false) === true && is_array($response['data']['result'] ?? null),
                'telegram_edit_not_confirmed_no_retry');
            $message = $response['data']['result'];
            need((string)($message['chat']['id'] ?? '') === $target['channel']
                && (string)($message['message_id'] ?? '') === $plan['message_id']
                && ($message['chat']['username'] ?? '') === 'any_toour'
                && ($message['chat']['type'] ?? '') === 'channel', 'telegram_response_identity_changed');
            $snap = ChannelPostIncomingAdapter::telegramSnapshot($message);
            need(is_array($snap) && snapshotMatches($snap, $plan, $plan['new_text'], $plan['new_markup']),
                'telegram_body_photo_button_mismatch');
            $revision = $message['edit_date'] ?? null;
            need(is_int($revision) && $revision > 0, 'telegram_edit_clock_missing');
        } else {
            // Existing photo and keyboard are retained by omitting attachments.
            $response = MaxTransport::request('https://platform-api2.max.ru', MAX_SEARCH_TOKEN, 'PUT',
                '/messages', ['message_id' => $plan['message_id']], ['text' => $plan['max_html'], 'format' => 'html'], null);
            $state['posts'][$key]['provider_response_private'] = $response;
            $state['posts'][$key]['provider_error_private'] = MaxTransport::lastError();
            $state['posts'][$key]['state'] = 'text_edit_returned'; durable($lock, $state);
            $message = readMax($plan['message_id'], $target['channel']);
            $snap = ChannelPostIncomingAdapter::maxSnapshot($message);
            need(is_array($snap) && snapshotMatches($snap, $plan, $plan['new_text'], $plan['new_markup'])
                && ($snap['metadata']['link'] ?? null) === $plan['old_forward_context'], 'max_content_edit_readback_mismatch');
        }
        $snap['metadata'] = array_replace($plan['old_metadata'], (array)($snap['metadata'] ?? []));
        // This is an administrator edit observation, never a fabricated webhook.
        unset($snap['metadata']['source_sequence'], $snap['metadata']['source_sequence_scope']);
        $snap['metadata']['source_event_id'] = APPROVED_UPDATE_OPERATION.':'.$key;
        $snap['metadata']['observation_kind'] = 'authorized_admin_content_edit';
        $snap['metadata']['caption_correction_operation'] = APPROVED_UPDATE_OPERATION;
        $snap['metadata']['operation_observed_at_ms'] = (string)(int)floor(microtime(true) * 1000);
        $snap['metadata']['source_revision_ms'] = (string)($platform === 'telegram' ? $revision * 1000 : $requestStart);
        $snap['metadata']['revision_clock_origin'] = $platform === 'telegram' ? 'telegram_edit_response' : 'authorized_edit_request_start';
        $snap['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($snap, '', (string)$channel['bot_url']);
        $p = $registry->registerPublication('anytour', $platform, $target['bot'], $target['channel'], $plan['message_id'], $snap);
        need(is_array($p) && (int)$p['publication_id'] === $plan['publication_id']
            && (int)$p['version_id'] !== $plan['version_id']
            && ($p['snapshot']['text'] ?? '') === $plan['new_text'], 'registry_new_version_not_current');
        if ($p['state'] !== 'sent') {
            installedGuard();
            $button = $automation->processPublication('anytour', $platform, $target['bot'],
                (int)$p['publication_id'], (int)$p['version_id']);
            $state['posts'][$key]['button_result'] = $button; durable($lock, $state);
            need(($button['state'] ?? '') === 'sent' && (int)($button['receipt']['write_attempts'] ?? 0) === 0,
                'existing_button_confirmation_unresolved');
        }
        $p = $registry->publication($plan['publication_id']);
        need(is_array($p) && $p['state'] === 'sent' && $p['platform'] === $platform
            && $p['source_key'] === $plan['source_key'] && $p['external_message_id'] === $plan['message_id']
            && channelFingerprint($p['channel']) === $channelHashes[$platform]
            && hash_equals((string)$p['start_payload'], $plan['token'])
            && snapshotMatches($p['snapshot'], $plan, $plan['new_text'], $plan['new_markup']),
            'final_registry_context_mismatch');
        if ($platform === 'max') {
            $final = ChannelPostIncomingAdapter::maxSnapshot(readMax($plan['message_id'], $target['channel']));
            need(is_array($final) && snapshotMatches($final, $plan, $plan['new_text'], $plan['new_markup'])
                && ($final['metadata']['link'] ?? null) === $plan['old_forward_context'], 'max_final_readback_mismatch');
        }
        $resolved = ChannelPostEntryService::resolve($platform, $plan['token'], $registry);
        need(is_array($resolved) && (int)$resolved['publication_id'] === $plan['publication_id']
            && (int)$resolved['version_id'] === (int)$p['version_id']
            && $resolved['source_key'] === $plan['source_key']
            && ($resolved['snapshot']['text'] ?? '') === $plan['new_text'], 'existing_token_new_version_resolution_failed');
        $receipt = ['hotel_id' => $plan['hotel_id'], 'platform' => $platform,
            'publication_id' => $plan['publication_id'], 'previous_version_id' => $plan['version_id'],
            'version_id' => (int)$p['version_id'], 'message_id' => $plan['message_id'], 'post_url' => $plan['post_url'],
            'text_sha256' => $plan['new_text_sha256'], 'previous_text_sha256' => $plan['old_text_sha256'],
            'photo_signature' => $plan['photo_signature'], 'previous_attachments_sha256' => $plan['attachments_sha256'],
            'attachments_sha256' => attachmentHash($p['snapshot']), 'button_url_sha256' => $plan['button_url_sha256'],
            'button_text' => $payload['button_text'], 'source_key' => $plan['source_key'], 'state' => 'sent',
            'body_photo_button_verified' => true, 'formatting_verified' => true,
            'existing_message_reused' => true, 'existing_token_resolves_to_current_version' => true];
        $state['posts'][$key]['state'] = 'confirmed'; $state['posts'][$key]['receipt'] = $receipt;
        durable($lock, $state); $out['posts'][] = $receipt;
        echo jsonValue(['progress' => $quality ? 'existing_caption_quality_revised' : 'existing_title_shortened', 'platform' => $platform,
            'publication_id' => $plan['publication_id'], 'post_url' => $plan['post_url']]), PHP_EOL;
        flush(); usleep(3200000);
    }
    $state['complete'] = true; durable($lock, $state);
    $out['ok'] = true; $out['installed_sha'] = EXPECTED_INSTALLED_SHA;
    $out['manifest_sha256'] = APPROVED_MANIFEST_SHA256;
    $out[$quality ? 'caption_updates' : 'title_updates'] = count($out['posts']); unset($out['current']);
} catch (Throwable $error) {
    $reason = $error->getMessage();
    $out['error'] = preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D', $reason) === 1 ? $reason : 'bounded_title_update_failed';
    // Preserve partial confirmed receipts and the unresolved attempt. No clearing,
    // resumption, fresh operation ID, or second provider edit is authorized here.
    if (is_resource($lock) && is_array($state)) {
        $state['complete'] = false; $state['error'] = $out['error'];
        try { durable($lock, $state); } catch (Throwable $ignored) {}
    }
}
if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
echo jsonValue($out), PHP_EOL;
exit($out['ok'] ? 0 : 2);

