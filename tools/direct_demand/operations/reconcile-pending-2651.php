<?php
declare(strict_types=1);
// Built after the exact accepted hook function prefix and its editor's helpers.
// Inspect is the default. The explicit apply gate completes only this original operation.
const RECONCILE_ROOT = '/var/www/anytoour/data/channel-post-operations';
const RECONCILE_RUNTIME = '/var/www/anytoour/data/www/app.anytoour.ru';
const RECONCILE_RUNTIME_SHA = '53382c760a5cf07a2075751670a46c35ee77feb9';
const RECONCILE_TV = 2651;
const RECONCILE_OPERATION = 'commercial-monitor-price-h2651-3c6637d73d3424159b7d01b9ffcf2e2051fc3fbae084bb645c7b259c33413d11';
const RECONCILE_RECORD = '3c6637d73d3424159b7d01b9ffcf2e2051fc3fbae084bb645c7b259c33413d11';
const RECONCILE_MANIFEST = '4515961a91d0f940a3df10361f9dab1adad7360d24b3f55b067c4e81411a9784';
const RECONCILE_GLOBAL_SHA = 'cb30682781ed2a55972564c98604ca26694e0c3154141ad936dd5e06252baf51';
const RECONCILE_ORIGINAL_SHA = '2e8404f8804911fa062f458bd2adee0426a35a4428c99a377be4fd5518d06b27';
const RECONCILE_KEY = 'commercial-rose-rayhaan-2651-v1';
const RECONCILE_MAX_ID = 957;
const RECONCILE_MAX_OLD_VERSION = 2995;
const RECONCILE_MAX_MID = 'mid.ffffb6caf0606fed01a1b793c24c6a94';
const RECONCILE_MAX_OLD_TEXT_SHA = '565ba5d258f5d9f215bf7082b38e3e4e24fd120072234ff4586d1a551bf260a0';
const RECONCILE_REQUEST_START_MS = 1794475571361;

function reconcile_need(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException($reason);
}
function reconcile_json(array $value): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function reconcile_canonical(array $value): array {
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as &$item) if (is_array($item)) $item = reconcile_canonical($item);
    unset($item);
    return $value;
}
function reconcile_stable_offer(array $offer): array {
    unset($offer['ageSecondsAtExport'], $offer['expiresAt']);
    return reconcile_canonical($offer);
}
/** Fresh admission is mandatory for the first Telegram edit, independent of historical receipts. */
function reconcile_price_admission(array $original, array $fresh, int $now): array {
    $historical = monitor_price_observation($original, RECONCILE_TV, $now, false);
    $current = monitor_price_observation($fresh, RECONCILE_TV, $now, true);
    reconcile_need($historical['record_id'] === RECONCILE_RECORD && $current['record_id'] === RECONCILE_RECORD
        && monitor_price_operation($historical) === RECONCILE_OPERATION, 'reconcile_selected_record_changed');
    reconcile_need(reconcile_stable_offer($historical['offer']) === reconcile_stable_offer($current['offer'])
        && $historical['observed_at'] === $current['observed_at'], 'reconcile_immutable_offer_changed');
    reconcile_need(array_map('reconcile_stable_offer', $original['item']['offers']) === array_map('reconcile_stable_offer', $fresh['item']['offers']),
        'reconcile_immutable_offer_set_changed');
    reconcile_need(monitor_price_time($fresh['generatedAt']) >= monitor_price_time($original['generatedAt']), 'reconcile_export_time_regressed');
    foreach (['departureId','dateFrom','dateTo','nightsFrom','nightsTo','adults','childrenCount','currency'] as $field) {
        reconcile_need(($fresh['scope'][$field] ?? null) === ($original['scope'][$field] ?? null), 'reconcile_fresh_scope_changed');
    }
    return ['historical' => $historical, 'current' => $current];
}
/** The only admitted original prefix has one returned MAX intent and no Telegram intent. */
function reconcile_original_state(array $state): array {
    reconcile_need(($state['operation'] ?? null) === RECONCILE_OPERATION && ($state['manifest_hash'] ?? null) === RECONCILE_MANIFEST
        && ($state['configs'] ?? null) === [] && !isset($state['terminal']) && ($state['complete'] ?? false) === false,
        'reconcile_original_state_changed');
    $posts = $state['posts'] ?? null;
    reconcile_need(is_array($posts) && array_keys($posts) === ['max:' . RECONCILE_KEY], 'reconcile_unexpected_post_intent');
    $post = $posts['max:' . RECONCILE_KEY]; $plan = $post['plan'] ?? null;
    reconcile_need(($post['state'] ?? null) === 'text_edit_returned' && ($post['provider_edit_returned'] ?? null) === true
        && ($post['edit_request_start_ms'] ?? null) === RECONCILE_REQUEST_START_MS
        && !isset($post['receipt']) && !isset($post['button_result']) && is_array($plan), 'reconcile_max_intent_changed');
    reconcile_need(($plan['platform'] ?? null) === 'max' && ($plan['key'] ?? null) === RECONCILE_KEY
        && ($plan['logical_id'] ?? null) === RECONCILE_KEY && ($plan['publication_id'] ?? null) === RECONCILE_MAX_ID
        && ($plan['old_version_id'] ?? null) === RECONCILE_MAX_OLD_VERSION && ($plan['message_id'] ?? null) === RECONCILE_MAX_MID
        && ($plan['post_url'] ?? null) === 'https://max.ru/channel_anytour/AaG3k8JMapQ'
        && ($plan['photo_signature'] ?? null) === 'max:49869501448' && ($plan['update_text'] ?? null) === true
        && is_string($plan['text'] ?? null) && $plan['text'] !== ''
        && is_string($plan['token'] ?? null) && preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/D', $plan['token']) === 1
        && ($plan['monitor_price']['record_id'] ?? null) === RECONCILE_RECORD, 'reconcile_max_plan_changed');
    return $plan;
}
function reconcile_prefix_guard(string $raw, string $expected, int $bytes, int $lines): array {
    reconcile_need(strlen($raw) === $bytes && hash('sha256', $raw) === $expected && str_ends_with($raw, "\n"), 'reconcile_journal_prefix_changed');
    $all = explode("\n", substr($raw, 0, -1));
    reconcile_need(count($all) === $lines, 'reconcile_journal_line_count_changed');
    $last = null;
    foreach ($all as $line) {
        $last = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        reconcile_need(is_array($last), 'reconcile_journal_shape_invalid');
    }
    return $last;
}
function reconcile_open(string $path, int $cap, bool $apply, array &$locks): array {
    reconcile_need(!is_link(dirname($path)) && realpath(dirname($path)) === dirname($path)
        && !is_link($path) && is_file($path) && realpath($path) === $path, 'reconcile_journal_path_invalid');
    $f = fopen($path, $apply ? 'r+b' : 'rb');
    reconcile_need(is_resource($f), 'reconcile_journal_open_failed'); $locks[] = $f;
    reconcile_need(flock($f, ($apply ? LOCK_EX : LOCK_SH) | LOCK_NB), 'reconcile_original_operation_busy');
    $stat = fstat($f); $named = lstat($path);
    reconcile_need(is_array($stat) && is_array($named) && $stat['ino'] === $named['ino'] && $stat['dev'] === $named['dev']
        && $stat['size'] > 0 && $stat['size'] <= $cap, 'reconcile_journal_identity_changed');
    $raw = stream_get_contents($f, $cap + 1);
    reconcile_need(is_string($raw) && strlen($raw) === $stat['size'], 'reconcile_journal_read_failed');
    return [$f, $raw];
}
function reconcile_assert_prefix($stream, string $prefix): void {
    reconcile_need(rewind($stream), 'reconcile_journal_rewind_failed');
    $read = stream_get_contents($stream, strlen($prefix));
    reconcile_need($read === $prefix, 'reconcile_original_prefix_not_preserved');
}
function reconcile_runtime(): void {
    reconcile_need(realpath(RECONCILE_RUNTIME) === RECONCILE_RUNTIME && chdir(RECONCILE_RUNTIME), 'reconcile_runtime_path_changed');
    reconcile_need(trim((string)shell_exec('git rev-parse --verify HEAD')) === RECONCILE_RUNTIME_SHA
        && trim((string)shell_exec('git --no-optional-locks status --porcelain --untracked-files=no')) === '', 'reconcile_runtime_changed');
    $pins = json_decode(base64_decode(HOOK_RUNTIME_PINS_B64, true), true, 32, JSON_THROW_ON_ERROR);
    reconcile_need(count($pins) === 22, 'reconcile_runtime_pin_count_changed');
    foreach ($pins as $file => $expected) {
        reconcile_need(preg_match('~\A(?:services|integrations|migrations)/[A-Za-z0-9_.-]+\z~D', $file) === 1
            && !is_link($file) && is_file($file), 'reconcile_runtime_file_invalid');
        reconcile_need(trim((string)shell_exec('git hash-object -- ' . escapeshellarg($file))) === $expected, 'reconcile_runtime_pin_changed');
    }
}
function reconcile_identity(array $p, string $platform, array $proof, array $row): void {
    $targets = ['max' => ['id9704048781_1_bot','-80492244209683','max_anytour_msk1'], 'telegram' => ['anyru_bot','-1002262666616','tg_anytour_msk']];
    $target = $targets[$platform];
    reconcile_need(($p['project_key'] ?? null) === 'anytour' && ($p['platform'] ?? null) === $platform
        && ($p['bot_key'] ?? null) === $target[0] && ($p['channel_id'] ?? null) === $target[1]
        && ($p['source_key'] ?? null) === $target[2] && (int)($p['publication_id'] ?? 0) === (int)$proof['publication_id']
        && ($p['external_message_id'] ?? null) === (string)$proof['message_id'] && ($p['snapshot']['post_url'] ?? null) === $row['posts'][$platform]
        && ($p['state'] ?? null) === 'sent' && is_string($p['start_payload'] ?? null)
        && preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/D', $p['start_payload']) === 1
        && (int)($p['channel']['id'] ?? 0) === ($platform === 'max' ? 9 : 10)
        && (int)($p['channel']['config_version'] ?? 0) === 2 && ($p['channel']['button_text'] ?? null) === '✈️ Узнать стоимость тура', 'reconcile_publication_identity_changed');
}
/** Rebuild the original canonical manifest with the exact old MAX version and untouched Telegram. */
function reconcile_context(ChannelPostRegistryService $registry, array $state, array $maxPlan): array {
    $roster = json_decode(gzdecode(base64_decode(HOOK_ROSTER_GZIP_B64, true)), true, 64, JSON_THROW_ON_ERROR)['rows'];
    $matches = array_values(array_filter($roster, static fn($r): bool => ($r['tourvisor_hotel_id'] ?? null) === RECONCILE_TV));
    reconcile_need(count($roster) === 481 && count($matches) === 1, 'reconcile_frozen_roster_changed'); $row = $matches[0];
    reconcile_need($row['key'] === RECONCILE_KEY && $row['logical_id'] === RECONCILE_KEY
        && $row['feed_binding'] === $state['feed_binding'], 'reconcile_frozen_binding_changed');
    $price = monitor_price_observation($state['monitor_snapshot'], RECONCILE_TV, time(), false);
    reconcile_need($price === $maxPlan['monitor_price'], 'reconcile_original_price_changed');
    $publications = []; $old = []; $proofs = [];
    foreach (['max','telegram'] as $platform) {
        $file = RECONCILE_ROOT . '/' . $row['original_publication_operation'] . '/' . $platform . '-' . $row['logical_id'] . '-approved.json';
        $original = json_decode(hook_read($file, 1048576), true, 64, JSON_THROW_ON_ERROR); $proof = $original['receipt'] ?? [];
        reconcile_need(($original['state'] ?? null) === 'confirmed' && ($original['text_sha256'] ?? null) === $row['original_text_sha256']
            && ($original['photo_sha256'] ?? null) === $row['cover_sha256'] && ($proof['logical_id'] ?? null) === $row['logical_id']
            && (int)($proof['hotel_id'] ?? 0) === $row['hotel_id'] && ($proof['platform'] ?? null) === $platform
            && ($proof['post_url'] ?? null) === $row['posts'][$platform] && ($proof['body_photo_button_verified'] ?? null) === true,
            'reconcile_original_publication_receipt_changed');
        $p = $registry->publication((int)$proof['publication_id']);
        reconcile_need(is_array($p), 'reconcile_publication_missing'); reconcile_identity($p, $platform, $proof, $row);
        $publications[$platform] = $p; $proofs[$platform] = $proof;
        if ($platform === 'max') {
            reconcile_need((int)$p['publication_id'] === RECONCILE_MAX_ID && (int)$p['version_id'] === RECONCILE_MAX_OLD_VERSION
                && hash('sha256', $p['snapshot']['text']) === RECONCILE_MAX_OLD_TEXT_SHA
                && hash_equals($maxPlan['token'], $p['start_payload']) && photoSignature($p['snapshot']) === $maxPlan['photo_signature'],
                'reconcile_max_registry_not_original_old_version');
            $v = $registry->versionForPublication(RECONCILE_MAX_ID, RECONCILE_MAX_OLD_VERSION);
            reconcile_need(is_array($v) && $v['project_key'] === 'anytour' && $v['platform'] === 'max' && $v['bot_key'] === 'id9704048781_1_bot'
                && $v['channel_id'] === '-80492244209683' && $v['external_message_id'] === RECONCILE_MAX_MID
                && hash('sha256', $v['snapshot']['text']) === RECONCILE_MAX_OLD_TEXT_SHA, 'reconcile_old_max_snapshot_changed');
            $old[$platform] = ['version_id' => RECONCILE_MAX_OLD_VERSION, 'snapshot' => $v['snapshot']];
        } else {
            reconcile_need($p['external_message_id'] === '424' && $row['posts']['telegram'] === 'https://t.me/any_toour/424', 'reconcile_telegram_target_changed');
            $old[$platform] = ['version_id' => (int)$p['version_id'], 'snapshot' => $p['snapshot']];
        }
    }
    $r = array_intersect_key($row, array_flip(['key','hotel_id','tourvisor_hotel_id','logical_id','original_publication_operation','cover_sha256']));
    $r['channel_texts'] = [];
    foreach (['max','telegram'] as $platform) {
        $text = monitor_hook_draft($row['templates'][$platform], $price); $before = $old[$platform]['snapshot']['text'];
        $r['channel_texts'][$platform] = ['old_text' => $before, 'old_text_sha256' => hash('sha256', $before),
            'new_text' => $text, 'new_text_sha256' => hash('sha256', $text), 'original_text_sha256' => $row['original_text_sha256'],
            'expected_version_id' => $old[$platform]['version_id'], 'expected_post_url' => $row['posts'][$platform]];
    }
    $payload = ['operation' => RECONCILE_OPERATION, 'price_basis' => 'scheduled_monitor_result', 'monitor_snapshot' => $state['monitor_snapshot'],
        'button_text' => '✈️ Узнать стоимость тура', 'feed_binding' => $row['feed_binding'], 'rows' => [$r]];
    reconcile_need(hash('sha256', reconcile_json($payload)) === RECONCILE_MANIFEST, 'reconcile_exact_original_manifest_not_reconstructed');
    reconcile_need($r['channel_texts']['max']['new_text'] === $maxPlan['text'], 'reconcile_original_max_target_changed');
    $tg = $publications['telegram']; $tgText = $r['channel_texts']['telegram']['new_text'];
    $tgPlan = ['platform' => 'telegram', 'key' => $row['key'], 'logical_id' => $row['logical_id'], 'message_id' => $tg['external_message_id'],
        'publication_id' => (int)$tg['publication_id'], 'old_version_id' => (int)$tg['version_id'], 'token' => $tg['start_payload'],
        'post_url' => $tg['snapshot']['post_url'], 'photo_signature' => photoSignature($tg['snapshot']), 'text' => $tgText,
        'update_text' => $tgText !== $tg['snapshot']['text'], 'monitor_price' => $price];
    reconcile_need($tgPlan['update_text'] === true, 'reconcile_telegram_not_first_caption_change');
    return ['row' => $row, 'price' => $price, 'payload' => $payload, 'publications' => $publications, 'proofs' => $proofs, 'telegram_plan' => $tgPlan];
}
function reconcile_read_max(array $plan, array $publication, array &$out): array {
    $out['max_gets']++;
    $response = ChannelPostApiTransport::buttonRequest('max', 'GET', '/messages/' . RECONCILE_MAX_MID, [], null);
    reconcile_need(empty($response['transport_error']) && (int)($response['http'] ?? 0) === 200 && is_array($response['data'] ?? null), 'reconcile_max_read_failed');
    $raw = $response['data'];
    reconcile_need(($raw['body']['mid'] ?? null) === RECONCILE_MAX_MID && ($raw['recipient']['chat_type'] ?? null) === 'channel'
        && (string)($raw['recipient']['chat_id'] ?? '') === '-80492244209683', 'reconcile_max_provider_identity_changed');
    $snapshot = ChannelPostIncomingAdapter::maxSnapshot($raw);
    reconcile_need(is_array($snapshot) && ($snapshot['metadata']['capability'] ?? null) === 'supported'
        && snapshotMatches($publication, $snapshot, $plan), 'reconcile_max_target_not_proven');
    $out['max_target_readback'] = ['http' => 200, 'message_id' => RECONCILE_MAX_MID, 'text_sha256' => hash('sha256', $snapshot['text']),
        'photo_signature' => photoSignature($snapshot), 'post_url' => $snapshot['post_url'], 'only_expected_button' => true,
        'response_sha256' => hash('sha256', reconcile_json($raw)), 'captured_at' => gmdate('c')];
    return $snapshot;
}
function reconcile_fresh_source(array $original, array &$out): array {
    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Moscow'));
    $dateFrom = $today->modify('+1 day')->format('Y-m-d');
    $dateTo = $today->modify('first day of this month')->modify('+12 months')->modify('-1 day')->format('Y-m-d');
    reconcile_need(($original['scope']['dateFrom'] ?? null) === $dateFrom && ($original['scope']['dateTo'] ?? null) === $dateTo, 'reconcile_calendar_scope_changed');
    $url = 'https://anytoour.ru/data/hotel-monitor-prices-read-v1.php?' . http_build_query(
        ['tourvisorHotelIds' => [RECONCILE_TV], 'departureId' => 1, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo], '', '&', PHP_QUERY_RFC3986);
    $out['saved_monitor_gets']++; $raw = hook_get($url, 2000000); $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    reconcile_need(($payload['ok'] ?? null) === true && count($payload['items'] ?? []) === 1
        && ($payload['scope']['tourvisorHotelIds'] ?? null) === [RECONCILE_TV], 'reconcile_fresh_reader_scope_changed');
    $fresh = monitor_hook_source($payload, RECONCILE_TV, $url); $prices = reconcile_price_admission($original, $fresh, time());
    $out['fresh_admission'] = ['record_id' => $prices['current']['record_id'], 'price' => $prices['current']['price'],
        'generated_at' => $fresh['generatedAt'], 'expires_at' => $prices['current']['offer']['expiresAt'],
        'validated_at' => gmdate('c'), 'all_offers_validated' => count($fresh['item']['offers']),
        'passed' => true, 'immutable_selected_offer_matches' => true, 'all_offers_immutable_equal' => true, 'response_sha256' => hash('sha256', $raw),
        'snapshot_sha256' => hash('sha256', reconcile_json($fresh))];
    return $fresh;
}
/** Confirm an already authenticated, exact provider outcome. This never invokes a transport. */
function reconcile_confirm_observed(PDO $pdo, ChannelPostRegistryService $registry, array $publication, array $snapshot, array $plan, string $event): array {
    $version = (int)$publication['version_id']; $id = (int)$publication['publication_id'];
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT id FROM post_publications WHERE id=? FOR UPDATE'); $q->execute([$id]);
        reconcile_need((int)$q->fetchColumn() === $id, 'reconcile_publication_lock_failed');
        $q = $pdo->prepare('SELECT id FROM post_versions WHERE id=? AND publication_id=? FOR UPDATE'); $q->execute([$version,$id]);
        reconcile_need((int)$q->fetchColumn() === $version, 'reconcile_version_lock_failed');
        $p = $registry->publication($id);
        reconcile_need(is_array($p) && (int)$p['version_id'] === $version && hash_equals($plan['token'], (string)$p['start_payload'])
            && snapshotMatches($p, $snapshot, $plan) && snapshotMatches($p, $p['snapshot'], $plan)
            && ($p['snapshot']['metadata']['source_event_id'] ?? null) === $event
            && ($p['snapshot']['metadata']['observation_kind'] ?? null) === 'authorized_admin_content_edit'
            && ($p['snapshot']['metadata']['monitor_price_record_id'] ?? null) === RECONCILE_RECORD,
            'reconcile_registered_outcome_changed');
        if ($plan['platform'] === 'max') {
            reconcile_need(($p['snapshot']['metadata']['source_revision_ms'] ?? null) === (string)RECONCILE_REQUEST_START_MS
                && ($p['snapshot']['metadata']['revision_clock_origin'] ?? null) === 'authorized_edit_request_start',
                'reconcile_original_max_revision_not_preserved');
        }
        if ($p['state'] !== 'sent') {
            reconcile_need($p['state'] === 'pending' && (int)$p['write_attempts'] === 0 && empty($p['claim_token']) && empty($p['claimed_at']), 'reconcile_new_version_not_unclaimed');
            reconcile_need($registry->confirmObservedButton($id, $version, ['event_id' => $event, 'message_id' => $plan['message_id'],
                'http_status' => 200, 'verified' => true, 'reconciled' => true, 'request_count' => 1,
                'write_attempts' => 0, 'content_preserved' => true, 'button_verified' => true]), 'reconcile_observed_button_not_confirmed');
        }
        $p = $registry->publication($id);
        reconcile_need(is_array($p) && (int)$p['version_id'] === $version && $p['state'] === 'sent', 'reconcile_registry_not_sent');
        $pdo->commit(); return $p;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function reconcile_final_receipt(ChannelPostRegistryService $registry, array $p, array $plan): array {
    $current = $registry->publication((int)$p['publication_id']);
    reconcile_need(is_array($current) && $current['state'] === 'sent' && $current['platform'] === $plan['platform']
        && (int)$current['version_id'] === (int)$p['version_id'] && hash_equals($plan['token'], (string)$current['start_payload'])
        && snapshotMatches($current, $current['snapshot'], $plan), 'reconcile_final_registry_mismatch');
    $resolved = ChannelPostEntryService::resolve($plan['platform'], $plan['token'], $registry);
    reconcile_need(is_array($resolved) && (int)$resolved['version_id'] === (int)$current['version_id']
        && ($resolved['snapshot']['text'] ?? null) === $plan['text'] && $resolved['source_key'] === $current['source_key'], 'reconcile_token_resolution_failed');
    return safeResult($current, $plan);
}

function reconcile_self_test(): void {
    $cases = 0;
    $check = static function(bool $ok, string $name) use (&$cases): void { reconcile_need($ok, 'selftest_' . $name); $cases++; };
    $reject = static function(callable $f, string $name) use (&$cases): void {
        $failed = false; try { $f(); } catch (Throwable $e) { $failed = true; }
        reconcile_need($failed, 'selftest_' . $name); $cases++;
    };
    $generated = strtotime('2026-11-12T09:22:45Z'); $now = strtotime('2026-11-12T09:45:00Z');
    $offer = ['tourvisorHotelId' => RECONCILE_TV, 'tourId' => 'test-tour', 'searchId' => 'test-search', 'observationId' => 'test-observation',
        'departureId' => 1, 'countryId' => 9, 'departureDate' => '2026-11-14', 'nights' => 5, 'adults' => 2, 'childrenCount' => 0,
        'mealId' => 3, 'roomId' => null, 'roomType' => 'guest room', 'operatorId' => 25, 'price' => 150221, 'fuelCharge' => 0,
        'currency' => 'RUB', 'observedAtRaw' => '2026-11-11 22:00:04', 'source' => 'scheduled_monitor', 'priceKind' => 'listing_observation',
        'recordId' => RECONCILE_RECORD, 'ageSecondsAtExport' => 51761, 'expiresAt' => '2026-11-12T09:37:45Z',
        'currentPriceVerified' => false, 'fullPackagePriceVerified' => false, 'fuelIncluded' => null, 'availabilityVerified' => false];
    $original = ['format' => 'anytour-hotel-monitor-prices-v1', 'identityNamespace' => 'tourvisor', 'source' => 'scheduled_monitor',
        'observationWindowHours' => 24, 'supplierRequests' => 0, 'writes' => 0,
        'timeBasis' => 'database-relative; stored observation timezone is not asserted',
        'sourceUrl' => 'https://anytoour.ru/data/hotel-monitor-prices-read-v1.php?test=fixture', 'generatedAt' => gmdate('Y-m-d\TH:i:s\Z', $generated),
        'scope' => ['tourvisorHotelIds' => [RECONCILE_TV], 'departureId' => 1, 'dateFrom' => '2026-11-13', 'dateTo' => '2027-10-31',
            'nightsFrom' => 5, 'nightsTo' => 14, 'adults' => 2, 'childrenCount' => 0, 'currency' => 'RUB'],
        'item' => ['tourvisorHotelId' => RECONCILE_TV, 'state' => 'observed', 'offers' => [$offer]]];
    for ($i = 1; $i <= 2; $i++) { $o = $offer; $o['recordId'] = str_repeat((string)$i,64); $o['price'] += $i * 100; $original['item']['offers'][] = $o; }
    $fresh = $original; $fresh['generatedAt'] = gmdate('Y-m-d\TH:i:s\Z', $now - 5);
    foreach ($fresh['item']['offers'] as &$o) { $o['ageSecondsAtExport'] += $now - 5 - $generated; $o['expiresAt'] = gmdate('Y-m-d\TH:i:s\Z', $now + 895); } unset($o);
    $prices = reconcile_price_admission($original, $fresh, $now);
    $check($prices['historical']['record_id'] === RECONCILE_RECORD && $prices['current']['record_id'] === RECONCILE_RECORD, 'expired_original_fresh_same_record');
    $reject(static fn() => monitor_price_observation($original, RECONCILE_TV, $now, true), 'original_alone_cannot_admit_new_edit');
    $bad = $fresh; $bad['item']['offers'][2]['mealId'] = null;
    $reject(static fn() => reconcile_price_admission($original, $bad, $now), 'all_offers_terms_guard');
    $bad = $fresh; $bad['item']['offers'][0]['recordId'] = str_repeat('a',64);
    $reject(static fn() => reconcile_price_admission($original, $bad, $now), 'different_selected_record');
    $bad = $fresh; $bad['item']['offers'][0]['roomType'] = 'different room';
    $reject(static fn() => reconcile_price_admission($original, $bad, $now), 'same_record_changed_terms');
    $bad = $fresh; $bad['item']['offers'][0]['operatorId'] = 99;
    $reject(static fn() => reconcile_price_admission($original, $bad, $now), 'same_record_changed_extra_field');
    $bad = $fresh; $bad['item']['offers'][2]['operatorId'] = 99;
    $reject(static fn() => reconcile_price_admission($original, $bad, $now), 'nonminimum_immutable_offer_changed');
    $bad = $fresh; $bad['item']['offers'][0]['ageSecondsAtExport']--;
    $reject(static fn() => reconcile_price_admission($original, $bad, $now), 'relative_observation_changed');
    $reject(static fn() => reconcile_price_admission($original, $fresh, $now + 900), 'fresh_export_expired');
    $bad = $fresh; foreach ($bad['item']['offers'] as &$o) $o['ageSecondsAtExport'] = 86400; unset($o);
    $reject(static fn() => reconcile_price_admission($original, $bad, $now), 'fresh_24h_age_expired');
    $plan = ['platform'=>'max','key'=>RECONCILE_KEY,'logical_id'=>RECONCILE_KEY,'publication_id'=>RECONCILE_MAX_ID,
        'old_version_id'=>RECONCILE_MAX_OLD_VERSION,'message_id'=>RECONCILE_MAX_MID,'post_url'=>'https://max.ru/channel_anytour/AaG3k8JMapQ',
        'photo_signature'=>'max:49869501448','update_text'=>true,'text'=>'synthetic target caption','token'=>'p_'.str_repeat('x',24),'monitor_price'=>$prices['historical']];
    $state = ['operation'=>RECONCILE_OPERATION,'manifest_hash'=>RECONCILE_MANIFEST,'configs'=>[],
        'posts'=>['max:'.RECONCILE_KEY=>['state'=>'text_edit_returned','provider_edit_returned'=>true,'edit_request_start_ms'=>RECONCILE_REQUEST_START_MS,'plan'=>$plan]]];
    $check(reconcile_original_state($state) === $plan, 'exact_original_intent');
    $bad = $state; $bad['posts']['telegram:'.RECONCILE_KEY] = ['state'=>'edit_started'];
    $reject(static fn() => reconcile_original_state($bad), 'telegram_intent_never_replayed');
    $bad = $state; $bad['posts']['max:'.RECONCILE_KEY]['state'] = 'edit_started';
    $reject(static fn() => reconcile_original_state($bad), 'unknown_max_stage_not_admitted');
    $bad = $state; $bad['manifest_hash'] = str_repeat('b',64);
    $reject(static fn() => reconcile_original_state($bad), 'manifest_changed');
    $raw = reconcile_json($state)."\n";
    $check(reconcile_prefix_guard($raw,hash('sha256',$raw),strlen($raw),1)===$state, 'prefix_exact');
    $reject(static fn() => reconcile_prefix_guard($raw."\n",hash('sha256',$raw),strlen($raw),1), 'prefix_changed');
    $check(!isset($state['posts']['telegram:'.RECONCILE_KEY]), 'fixtures_unchanged');
    echo 'MONITOR_PENDING_2651_SELFTEST_OK cases='.$cases." no_network=1 no_writes=1\n";
}

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('HOOK_EDITOR_B64') || !function_exists('safeResult') || !function_exists('hook_finish_feed')) {
    fwrite(STDERR, "reconcile_frozen_bundle_required\n"); exit(2);
}
if (count($argv) === 2 && $argv[1] === '--self-test') { reconcile_self_test(); exit(0); }
$apply = getenv('ANYTOUR_MONITOR_PENDING_RECONCILE_APPLY') === '1';
$out = ['ok'=>false, 'operation'=>RECONCILE_OPERATION, 'mode'=>$apply?'apply_original_pending_only':'inspect_original_pending_only',
    'checked_at'=>gmdate('c'),'driver_sha256'=>RECONCILE_DRIVER_SHA256,'embedded_prefix_sha256'=>RECONCILE_EMBEDDED_PREFIX_SHA256,
    'max_puts'=>0,'max_gets'=>0,'saved_monitor_gets'=>0,'telegram_edit_attempts'=>0,'new_posts'=>0,'supplier_calls'=>0,
    'telegram_capability_checks'=>0,'write_admission_complete'=>false,
    'database_write_calls'=>0,'journal_appends'=>0,'feed_resume_calls'=>0,'original_completed_pairs_replayed'=>0];
$locks = []; $pdo = null;
try {
    reconcile_need(count($argv) === 1 && in_array(getenv('ANYTOUR_MONITOR_PENDING_RECONCILE_APPLY'), [false,'','1'], true), 'reconcile_arguments_invalid');
    reconcile_runtime(); $out['runtime_sha'] = RECONCILE_RUNTIME_SHA;
    reconcile_need(!is_link(RECONCILE_ROOT) && realpath(RECONCILE_ROOT) === RECONCILE_ROOT, 'reconcile_private_root_changed');
    [$globalLock,$globalRaw] = reconcile_open(RECONCILE_ROOT.'/monitor-price-after-collection-v1.jsonl',100000000,$apply,$locks);
    $global = reconcile_prefix_guard($globalRaw,RECONCILE_GLOBAL_SHA,6324466,398);
    reconcile_need(($global['schema']??null)===1 && count($global['completed']??[])===131
        && ($global['pending']['tourvisor_hotel_id']??null)===RECONCILE_TV && ($global['pending']['operation']??null)===RECONCILE_OPERATION
        && ($global['pending']['phase']??null)==='post_started' && ($global['pending']['child_failure']??null)===['exit_code'=>2,'error'=>'max_read_failed']
        && !isset($global['completed'][RECONCILE_TV]), 'reconcile_original_pending_changed');
    [$journalLock,$journalRaw] = reconcile_open(RECONCILE_ROOT.'/'.RECONCILE_OPERATION.'/receipt.jsonl',10000000,$apply,$locks);
    $state = reconcile_prefix_guard($journalRaw,RECONCILE_ORIGINAL_SHA,26418,3); $maxPlan = reconcile_original_state($state);
    $originalSnapshot=$state['monitor_snapshot'];
    $initialCompleted=$global['completed']; $initialHolds=$global['holds']??[];
    ob_start(); try {
        require RECONCILE_RUNTIME.'/config.php'; require_once RECONCILE_RUNTIME.'/services/ChannelPostRuntime.php';
        require_once RECONCILE_RUNTIME.'/services/ChannelPostAdminService.php'; require_once RECONCILE_RUNTIME.'/services/ChannelPostEntryService.php';
        require_once RECONCILE_RUNTIME.'/integrations/ChannelPostApiTransport.php'; require_once RECONCILE_RUNTIME.'/services/ChannelPostButtonTransport.php';
    } finally { ob_end_clean(); }
    foreach (['max'=>'id9704048781_1_bot','telegram'=>'anyru_bot'] as $platform=>$bot) {
        $identity=ChannelPostRuntime::identity($platform); reconcile_need(is_array($identity)&&$identity['project_key']==='anytour'&&$identity['bot_key']===$bot,'reconcile_runtime_bot_changed');
    }
    $pdo=ConversationDb::connection();
    if (!$apply) { $pdo->exec('SET SESSION TRANSACTION READ ONLY'); $pdo->beginTransaction(); $out['database_session_read_only']=true; }
    foreach (['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $migration) {
        $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?'); $q->execute([$migration]);
        reconcile_need($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$migration),'reconcile_publishing_schema_not_accepted');
    }
    $out['publishing_schema_accepted']=true;
    $registry=new ChannelPostRegistryService($pdo); $context=reconcile_context($registry,$state,$maxPlan);
    $maxSnapshot=reconcile_read_max($maxPlan,$context['publications']['max'],$out);
    $fresh=reconcile_fresh_source($state['monitor_snapshot'],$out);
    $admission=reconcile_price_admission($state['monitor_snapshot'],$fresh,time());
    $tgPlan=$context['telegram_plan'];
    $out['admission']=['original_manifest_reconstructed'=>true,'manifest_sha256'=>RECONCILE_MANIFEST,'original_prefix_sha256'=>RECONCILE_ORIGINAL_SHA,
        'global_prefix_sha256'=>RECONCILE_GLOBAL_SHA,'max_original_version_id'=>RECONCILE_MAX_OLD_VERSION,
        'telegram_publication_id'=>$tgPlan['publication_id'],'telegram_old_version_id'=>$tgPlan['old_version_id'],
        'telegram_message_id'=>$tgPlan['message_id'],'telegram_target_text_sha256'=>hash('sha256',$tgPlan['text']),
        'telegram_original_intent_absent'=>true,'original_monitor_snapshot_preserved'=>true,'same_record_fresh_admission'=>true,
        'frozen_callback_loop_used'=>false,'expected_max_puts'=>0,'expected_telegram_first_edits'=>1,'previous_completed_count'=>count($initialCompleted)];
    $out['original_operation']=RECONCILE_OPERATION;
    $out['original_manifest_sha256']=RECONCILE_MANIFEST;
    $out['original_journal_prefix_sha256']=RECONCILE_ORIGINAL_SHA;
    $out['original_global_prefix_sha256']=RECONCILE_GLOBAL_SHA;
    $out['original_monitor_snapshot_sha256']=hash('sha256',reconcile_json($originalSnapshot));
    if (!$apply) {
        $out['telegram_capability_read']=['checked'=>false,'required_before_apply_mutation'=>true];
        $out['ready_for_explicit_apply']=true; $out['ok']=true;
    } else {
        // All validation above precedes every journal or database mutation.
        // verifyChannel performs bounded read-only capability RPCs, even though Telegram uses HTTP POST.
        $out['telegram_capability_checks']++;
        $rights=(new ChannelPostButtonTransport())->verifyChannel($context['publications']['telegram']['channel']);
        reconcile_need(is_array($rights)&&($rights['state']??null)==='ready'&&($rights['write_attempts']??null)===0,'reconcile_telegram_channel_rights_changed');
        $out['telegram_capability_read']=['checked'=>true,'state'=>'ready','write_attempts'=>0,
            'read_methods'=>['getMe','getChatMember','getChat'],'transport'=>'HTTP POST'];
        reconcile_runtime();
        $maxBefore=$registry->publication(RECONCILE_MAX_ID);
        reconcile_need(is_array($maxBefore)&&(int)$maxBefore['version_id']===RECONCILE_MAX_OLD_VERSION&&$maxBefore['state']==='sent'
            &&hash('sha256',$maxBefore['snapshot']['text'])===RECONCILE_MAX_OLD_TEXT_SHA&&hash_equals($maxPlan['token'],$maxBefore['start_payload']), 'reconcile_max_changed_before_adoption');
        reconcile_identity($maxBefore,'max',$context['proofs']['max'],$context['row']);
        reconcile_need(snapshotMatches($maxBefore,$maxSnapshot,$maxPlan),'reconcile_max_context_changed_before_adoption');
        reconcile_price_admission($state['monitor_snapshot'],$fresh,time());
        $maxSnapshot['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($maxSnapshot,'',(string)$maxBefore['channel']['bot_url']);
        $maxSnapshot['metadata']=array_replace($maxBefore['snapshot']['metadata']??[],$maxSnapshot['metadata']??[]);
        $maxSnapshot['metadata']=priceObservationMetadata($maxSnapshot['metadata'],$context['payload'],$context['price']);
        $maxEvent=RECONCILE_OPERATION.':max:'.RECONCILE_KEY;
        $maxSnapshot['metadata']['source_event_id']=$maxEvent;
        $maxSnapshot['metadata']['observation_kind']='authorized_admin_content_edit';
        $maxSnapshot['metadata']['operation_observed_at_ms']=(string)(int)floor(microtime(true)*1000);
        $maxSnapshot['metadata']['source_revision_ms']=(string)RECONCILE_REQUEST_START_MS;
        $maxSnapshot['metadata']['revision_clock_origin']='authorized_edit_request_start';
        $out['database_write_calls']++;
        $maxCurrent=$registry->registerPublication('anytour','max','id9704048781_1_bot','-80492244209683',RECONCILE_MAX_MID,$maxSnapshot);
        reconcile_need(is_array($maxCurrent)&&(int)$maxCurrent['publication_id']===RECONCILE_MAX_ID
            &&(int)$maxCurrent['version_id']>RECONCILE_MAX_OLD_VERSION&&empty($maxCurrent['stale_observation'])&&empty($maxCurrent['duplicate_event']), 'reconcile_max_adoption_not_new_exact_version');
        $out['database_write_calls']++;
        $maxCurrent=reconcile_confirm_observed($pdo,$registry,$maxCurrent,$maxSnapshot,$maxPlan,$maxEvent);
        $maxReceipt=reconcile_final_receipt($registry,$maxCurrent,$maxPlan);
        $state['posts']['max:'.RECONCILE_KEY]['state']='confirmed'; $state['posts']['max:'.RECONCILE_KEY]['receipt']=$maxReceipt;
        $state['reconciliation']=['kind'=>'original_admin_edit_readback_and_first_telegram_edit','original_prefix_sha256'=>RECONCILE_ORIGINAL_SHA,
            'original_manifest_sha256'=>RECONCILE_MANIFEST,'max_puts'=>0,'max_readback'=>$out['max_target_readback'],
            'fresh_source_attestation'=>['validated_at'=>gmdate('c'),'snapshot_sha256'=>hash('sha256',reconcile_json($fresh)),'snapshot'=>$fresh]];
        durable($journalLock,$state); $out['journal_appends']++;
        $tgBefore=$registry->publication($tgPlan['publication_id']);
        reconcile_need(is_array($tgBefore)&&(int)$tgBefore['version_id']===$tgPlan['old_version_id']&&$tgBefore['state']==='sent'
            &&$tgBefore['snapshot']['text']===$context['payload']['rows'][0]['channel_texts']['telegram']['old_text']
            &&hash_equals($tgPlan['token'],$tgBefore['start_payload'])&&photoSignature($tgBefore['snapshot'])===$tgPlan['photo_signature'], 'reconcile_telegram_changed_before_first_edit');
        reconcile_identity($tgBefore,'telegram',$context['proofs']['telegram'],$context['row']);
        reconcile_need(!isset($state['posts']['telegram:'.RECONCILE_KEY]), 'reconcile_telegram_intent_already_exists');
        // Strict current validation is required immediately before this first Telegram intent.
        reconcile_runtime();
        reconcile_price_admission($state['monitor_snapshot'],$fresh,time());
        monitor_price_validate_text($tgPlan['text'],$context['price']);
        $out['fresh_admission']['validated_before_telegram_intent_at']=gmdate('c');
        $out['write_admission_complete']=true;
        $state['posts']['telegram:'.RECONCILE_KEY]=['state'=>'edit_started','plan'=>$tgPlan,'edit_request_start_ms'=>(int)floor(microtime(true)*1000),
            'fresh_attestation_sha256'=>hash('sha256',reconcile_json($fresh))];
        durable($journalLock,$state); $out['journal_appends']++;
        $out['telegram_edit_attempts']++;
        $response=tg('editMessageCaption',['chat_id'=>'-1002262666616','message_id'=>$tgPlan['message_id'],'caption'=>$tgPlan['text'],
            'reply_markup'=>reconcile_json(['inline_keyboard'=>[[['text'=>'✈️ Узнать стоимость тура','url'=>ChannelPostButtonTransport::buttonUrl($tgBefore)]]]])]);
        $state['posts']['telegram:'.RECONCILE_KEY]['provider_response']=$response;
        $state['posts']['telegram:'.RECONCILE_KEY]['state']='provider_response_recorded'; durable($journalLock,$state); $out['journal_appends']++;
        reconcile_need($response['http']===200&&($response['data']['ok']??null)===true&&is_array($response['data']['result']??null), 'reconcile_telegram_outcome_unknown_no_retry');
        $message=$response['data']['result'];
        reconcile_need((string)($message['chat']['id']??'')==='-1002262666616'&&($message['chat']['username']??null)==='any_toour'
            &&(string)($message['message_id']??'')===$tgPlan['message_id'], 'reconcile_telegram_response_identity_changed');
        $tgSnapshot=ChannelPostIncomingAdapter::telegramSnapshot($message);
        reconcile_need(is_array($tgSnapshot)&&($tgSnapshot['metadata']['capability']??null)==='supported'&&snapshotMatches($tgBefore,$tgSnapshot,$tgPlan), 'reconcile_telegram_target_not_proven');
        $tgSnapshot['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($tgSnapshot,'',(string)$tgBefore['channel']['bot_url']);
        $tgSnapshot['metadata']=array_replace($tgBefore['snapshot']['metadata']??[],$tgSnapshot['metadata']??[]);
        $tgSnapshot['metadata']=priceObservationMetadata($tgSnapshot['metadata'],$context['payload'],$context['price']);
        $tgEvent=RECONCILE_OPERATION.':telegram:'.$tgPlan['message_id']; $tgSnapshot['metadata']['source_event_id']=$tgEvent;
        $tgSnapshot['metadata']['observation_kind']='authorized_admin_content_edit';
        $out['database_write_calls']++;
        $tgCurrent=$registry->registerPublication('anytour','telegram','anyru_bot','-1002262666616',$tgPlan['message_id'],$tgSnapshot);
        reconcile_need(is_array($tgCurrent)&&(int)$tgCurrent['publication_id']===$tgPlan['publication_id']
            &&(int)$tgCurrent['version_id']>$tgPlan['old_version_id']&&empty($tgCurrent['stale_observation'])&&empty($tgCurrent['duplicate_event']), 'reconcile_telegram_registration_changed');
        $out['database_write_calls']++;
        $tgCurrent=reconcile_confirm_observed($pdo,$registry,$tgCurrent,$tgSnapshot,$tgPlan,$tgEvent);
        $tgReceipt=reconcile_final_receipt($registry,$tgCurrent,$tgPlan);
        $state['posts']['telegram:'.RECONCILE_KEY]['state']='confirmed'; $state['posts']['telegram:'.RECONCILE_KEY]['receipt']=$tgReceipt;
        durable($journalLock,$state); $out['journal_appends']++;
        $state['posts']['max:'.RECONCILE_KEY]['receipt']=reconcile_final_receipt($registry,$maxCurrent,$maxPlan);
        $state['complete']=true; durable($journalLock,$state); $out['journal_appends']++;
        reconcile_assert_prefix($journalLock,$journalRaw); reconcile_assert_prefix($globalLock,$globalRaw);
        $binding=$state['feed_binding']+['operation'=>RECONCILE_OPERATION,'manifest_hash'=>RECONCILE_MANIFEST];
        monitor_price_confirmed_journal($state,$binding,time());
        $out['feed_resume_calls']++;
        reconcile_need(hook_finish_feed($global,$globalLock,RECONCILE_RUNTIME_SHA)==='feed_completed', 'reconcile_original_feed_not_completed');
        reconcile_need(fseek($globalLock,strlen($globalRaw),SEEK_SET)===0,'reconcile_feed_evidence_seek_failed');
        $appended=stream_get_contents($globalLock,1000001);
        reconcile_need(is_string($appended)&&strlen($appended)<=1000000&&str_ends_with($appended,"\n"),'reconcile_feed_evidence_read_failed');
        $feedFiles=null; $feedOperation=null;
        foreach(explode("\n",substr($appended,0,-1)) as $line){
            $entry=json_decode($line,true,64,JSON_THROW_ON_ERROR);
            if(($entry['pending']['operation']??null)===RECONCILE_OPERATION&&($entry['pending']['phase']??null)==='feed_started'){
                reconcile_need($feedFiles===null,'reconcile_duplicate_feed_start_evidence');
                $feedFiles=$entry['pending']['files'];$feedOperation=$entry['pending']['feed_operation'];
            }
        }
        reconcile_need(is_array($feedFiles)&&array_column($feedFiles,'name')===['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml']
            &&$feedOperation===($global['completed'][RECONCILE_TV]['feed_operation']??null),'reconcile_feed_hash_evidence_missing');
        foreach($feedFiles as $file)reconcile_need(is_string($file['sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$file['sha256'])===1,'reconcile_feed_hash_evidence_invalid');
        $out['feed_evidence']=['operation'=>$feedOperation,'files'=>$feedFiles,'local_and_public_hashes_verified'=>true,
            'verification_owner'=>'frozen_hook_finish_feed','additional_public_gets'=>0,'appended_global_states_sha256'=>hash('sha256',$appended)];
        $remaining=$global['completed']; unset($remaining[RECONCILE_TV]);
        reconcile_need($global['pending']===null&&$remaining===$initialCompleted&&($global['holds']??[])===$initialHolds
            &&($global['completed'][RECONCILE_TV]['operation']??null)===RECONCILE_OPERATION, 'reconcile_unrelated_global_state_changed');
        reconcile_assert_prefix($journalLock,$journalRaw); reconcile_assert_prefix($globalLock,$globalRaw);
        $completedJournal=hook_journal(RECONCILE_OPERATION);
        reconcile_need(is_array($completedJournal)&&($completedJournal['complete']??null)===true&&$completedJournal['manifest_hash']===RECONCILE_MANIFEST
            &&$completedJournal['monitor_snapshot']===$originalSnapshot,'reconcile_original_source_not_preserved');
        $out['ok']=true; $out['updated_pairs']=1; $out['updates']=[['tourvisor_hotel_id'=>RECONCILE_TV,'operation'=>RECONCILE_OPERATION,
            'price'=>$context['price']['price'],'record_id'=>RECONCILE_RECORD,'posts'=>[$state['posts']['max:'.RECONCILE_KEY]['receipt'],$tgReceipt],
            'feed_operation'=>$global['completed'][RECONCILE_TV]['feed_operation']]];
        $out['original_prefix_preserved']=true; $out['completed_operations_preserved']=count($initialCompleted); $out['pending_cleared']=true;
        $out['original_journal_complete']=true;
        $out['original_manifest_preserved']=true; $out['original_monitor_snapshot_preserved']=true; $out['pending']=null;
        $out['completed_original_operation']=$global['completed'][RECONCILE_TV];
    }
    $out['completed_at']=gmdate('c');
} catch (Throwable $e) {
    $out['error']=preg_match('/\A[a-z][a-z0-9_]{1,120}\z/D',$e->getMessage())?$e->getMessage():'reconcile_failed_read_original_journal';
}
if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); $out['database_transaction_rolled_back']=true; }
foreach (array_reverse($locks) as $lock) if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); }
echo 'MONITOR_PENDING_2651_RECONCILIATION='.reconcile_json($out)."\n"; exit($out['ok']?0:2);
