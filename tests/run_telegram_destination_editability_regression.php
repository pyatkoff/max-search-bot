<?php
declare(strict_types=1);

// Load selected declarations only; keep the CLI bootstrap and HTTP entry points unloaded.
$readerPath = getenv('TELEGRAM_EDITABILITY_READER') ?: dirname(__DIR__)
    . '/tools/direct_demand/operations/read-1566-telegram-46-20261123.php';
$readerSource = file_get_contents($readerPath);
if (!is_string($readerSource) || preg_match('/\n\$out\s*=/', $readerSource, $runtimeBoundary, PREG_OFFSET_CAPTURE) !== 1)
    throw new RuntimeException('Reader declaration boundary unavailable');
$runtimeStart = $runtimeBoundary[0][1];
$wanted = array_fill_keys(['requireRead', 'jsonRead', 'reasonRead', 'telegramCandidatePublicRead',
    'telegramNativeEditEvidenceRead', 'telegramTargetEditabilityRead', 'receiptRead',
    'parsePublicRead', 'albumStructureRead', 'corroborateTargetKeyboard', 'publicationRead',
    'telegramMediaOperationRead', 'telegramMediaOperationHeldRead', 'telegramPreparedPairPublicRead',
    'telegramPreparedPairTargetRead', 'telegramPreparedPairSourceRead', 'telegramPreparedPairNativeRead'], false);
$tokens = token_get_all(substr($readerSource, 0, $runtimeStart));
for ($i = 0, $count = count($tokens); $i < $count; $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    $nameAt = $i + 1;
    while (is_array($tokens[$nameAt]) && $tokens[$nameAt][0] === T_WHITESPACE) $nameAt++;
    if (!is_array($tokens[$nameAt]) || $tokens[$nameAt][0] !== T_STRING) continue;
    $name = $tokens[$nameAt][1];
    $declaration = ''; $depth = 0; $entered = false;
    for (; $i < $count; $i++) {
        $token = $tokens[$i];
        $declaration .= is_array($token) ? $token[1] : $token;
        if ($token === '{') { $depth++; $entered = true; }
        elseif ($token === '}' && --$depth === 0 && $entered) break;
    }
    if (array_key_exists($name, $wanted)) {
        if ($wanted[$name]) throw new RuntimeException('Duplicate reader declaration: ' . $name);
        eval($declaration);
        $wanted[$name] = true;
    }
}
foreach ($wanted as $name => $loaded)
    if (!$loaded) throw new RuntimeException('Missing reader declaration: ' . $name);

$checks = 0;
function editabilityCheck(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $checks++;
}
function editabilityState(array $result, string $state, bool $blocked, bool $noRetry, string $label): void
{
    editabilityCheck($result['state'] === $state && $result['preparation_blocked'] === $blocked
        && $result['no_retry'] === $noRetry, $label . ': classification');
    editabilityCheck(array_key_exists('success_probability', $result) && $result['success_probability'] === null
        && $result['editability_guaranteed'] === false && $result['per_message_capability'] === null,
        $label . ': no invented capability or probability');
}

$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/telegram_destination_editability_observations.json'),
    true, 64, JSON_THROW_ON_ERROR);
editabilityCheck(count($fixture['observations']) === 33
    && count(array_unique(array_column($fixture['observations'], 'message_id'))) === 33,
    '33 distinct actual historical messages');
$accepted = 0; $rejected = 0;
foreach ($fixture['observations'] as $observation) {
    $mid = $observation['message_id'];
    $public = ['published_at' => $observation['published_at'], 'single_photo' => $observation['single_photo']];
    $before = telegramTargetEditabilityRead($mid, $fixture['common_before']['target_native'],
        $fixture['common_before']['prior_media_operation'], $public, null, true);
    editabilityState($before, 'unknown_editability', false, false, 'historical preflight MID ' . $mid);
    if ($observation['outcome'] === 'message_id_invalid') {
        $rejected++;
        $journal = $observation['historical_journal_projection'];
        foreach ($journal['events'] as $event)
            editabilityCheck(!array_key_exists('target_matches', $event), 'historical projection is not fabricated');
        editabilityCheck($observation['reservation']['current_version_id'] === null
            && $observation['target_public_preimage_unchanged'] === true, 'actual failed reservation and preserved preimage');
        // The old export has no per-event target binding. It must still forbid a retry,
        // without pretending that the newer reader has verified a full raw journal.
        $after = telegramTargetEditabilityRead($mid, null, $journal, $public, null, true, true);
        editabilityState($after, 'operation_requires_readback', true, true, 'historical rejection MID ' . $mid);
    } else {
        $accepted++;
        editabilityCheck($observation['accepted_native_result']['state'] === 'native_and_registry_verified'
            && $observation['accepted_native_result']['ok'] === true
            && $observation['accepted_public_result']['applied'] === true
            && $observation['full_journal_available_in_export'] === false, 'actual native and public acceptance');
        // This marker is a test adapter for an unavailable full journal, NOT an
        // invented production journal reconstructed from the terminal result.
        $after = telegramTargetEditabilityRead($mid, null,
            ['state' => 'historical_result_without_full_journal'], $public, null, true, true);
        editabilityState($after, 'operation_requires_readback', true, true, 'historical accepted MID ' . $mid);
    }
}
editabilityCheck($accepted === 18 && $rejected === 15, 'actual 18 accepted and 15 rejected outcomes');

// From here onward every message, response and journal is an explicit synthetic
// offline fixture. No synthetic object is cited as production evidence.
const TEST_EDIT_CHAT = '-1001845835645';
const TEST_EDIT_MID = '9000001';
const TEST_EDIT_BOT = '900001';
function editabilityRaw(array $changes = []): array
{
    return array_replace(['message_id' => (int)TEST_EDIT_MID,
        'chat' => ['id' => (int)TEST_EDIT_CHAT, 'type' => 'channel'],
        'photo' => [['file_id' => 'SYNTHETIC_PRIVATE_FILE', 'file_unique_id' => 'SYNTHETIC_UNIQUE']],
        'date' => 1609459200], $changes);
}
function editabilityNative(array $raw, array $public = [], ?string $editor = TEST_EDIT_BOT): array
{
    return telegramTargetEditabilityRead(TEST_EDIT_MID,
        ['telegram_message' => telegramNativeEditEvidenceRead(['raw_body' => $raw], TEST_EDIT_CHAT, TEST_EDIT_MID)],
        ['state' => 'absent'], $public, $editor, true);
}
$missing = telegramNativeEditEvidenceRead(['raw_body' => editabilityRaw()], TEST_EDIT_CHAT, TEST_EDIT_MID);
foreach (['from_bot_id', 'via_bot_id', 'author_signature', 'sender_chat_id', 'forwarded', 'is_paid_post',
    'has_protected_content', 'original_send_method', 'can_be_edited', 'can_edit_media'] as $field)
    editabilityCheck(array_key_exists($field, $missing) && $missing[$field] === null, 'missing native field remains null: ' . $field);
editabilityCheck(!str_contains(jsonRead($missing), 'SYNTHETIC_PRIVATE_FILE')
    && !str_contains(jsonRead($missing), 'SYNTHETIC_UNIQUE'), 'native projection does not disclose provider file identifiers');
editabilityCheck(telegramNativeEditEvidenceRead([], TEST_EDIT_CHAT, TEST_EDIT_MID) === ['state' => 'unavailable'],
    'missing raw message is unavailable');
editabilityState(editabilityNative(editabilityRaw()), 'unknown_editability', false, false, 'plain old channel photo');
editabilityState(editabilityNative(editabilityRaw(['from' => ['id' => 12345, 'is_bot' => false],
    'sender_chat' => ['id' => (int)TEST_EDIT_CHAT], 'has_protected_content' => true,
    'author_signature' => 'Different human author'])), 'unknown_editability', false, false,
    'ordinary other author, age and protected-content flag do not prohibit channel editing');
editabilityState(editabilityNative(editabilityRaw(['from' => ['id' => 900002, 'is_bot' => true]])),
    'unknown_editability', false, false, 'ordinary other bot author is not an inline-bot prohibition');
$fakeCapability = telegramNativeEditEvidenceRead(['raw_body' => editabilityRaw(['can_be_edited' => true,
    'can_edit_media' => true, 'is_paid_post' => 'true', 'has_protected_content' => 1])], TEST_EDIT_CHAT, TEST_EDIT_MID);
editabilityCheck($fakeCapability['can_be_edited'] === null && $fakeCapability['can_edit_media'] === null
    && $fakeCapability['is_paid_post'] === null && $fakeCapability['has_protected_content'] === null,
    'non-Bot-API capability fields and malformed flags do not become evidence');
foreach ([['from' => ['id' => (int)TEST_EDIT_BOT, 'is_bot' => true]],
    ['via_bot' => ['id' => (int)TEST_EDIT_BOT, 'is_bot' => true]]] as $origin) {
    $result = editabilityNative(editabilityRaw($origin));
    editabilityState($result, 'bot_origin_observed', false, false, 'current bot origin');
    editabilityCheck($result['evidence_tier'] === 'origin_only', 'origin evidence is not edit capability');
}
foreach ([
    'paid' => ['is_paid_post' => true],
    'forward' => ['forward_origin' => ['type' => 'channel']],
    'legacy forward' => ['forward_date' => 1609459200],
    'other inline bot' => ['via_bot' => ['id' => 900002, 'is_bot' => true]],
    'reply keyboard' => ['reply_markup' => ['keyboard' => [['text' => 'Synthetic']]]],
    'approved suggested post' => ['suggested_post_info' => ['state' => 'approved']],
    'declined suggested post' => ['suggested_post_info' => ['state' => 'declined']],
] as $label => $changes)
    editabilityState(editabilityNative(editabilityRaw($changes)), 'native_constraint', true, false, $label);
editabilityState(editabilityNative(editabilityRaw(['via_bot' => ['id' => 900002, 'is_bot' => true]]), [], null),
    'unknown_editability', false, false, 'unknown editor identity cannot identify another inline bot');
foreach (['Отзыв туриста', 'Отзывы гостей', 'Видео от менеджера', 'Видеокружок менеджера'] as $caption)
    editabilityState(editabilityNative(editabilityRaw(), ['caption' => $caption]),
        'protected_by_owner', true, false, 'protected review or manager video');
editabilityState(editabilityNative(editabilityRaw(['video' => ['file_id' => 'SYNTHETIC_VIDEO']])),
    'format_requires_review', true, false, 'ordinary native video requires content review');
editabilityState(editabilityNative(editabilityRaw(['video_note' => ['file_id' => 'SYNTHETIC_VIDEO_NOTE']])),
    'protected_by_owner', true, false, 'native round video remains protected');
foreach (['Отзыв туриста об отеле', 'Видео от менеджера о поездке'] as $albumCaption)
    editabilityState(editabilityNative(editabilityRaw(), ['caption' => null,
        'format_observation' => ['caption_nodes' => [['text' => 'Публикация с несколькими фото'], ['text' => $albumCaption]]]]),
        'protected_by_owner', true, false, 'album caption nodes preserve owner protection');
editabilityState(editabilityNative(editabilityRaw(), ['caption' => 'Отель у моря: номера, питание и песчаный пляж.']),
    'unknown_editability', false, false, 'ordinary hotel caption is not a protected review');
editabilityState(editabilityNative(editabilityRaw(), ['caption' => null,
    'format_observation' => ['caption_nodes' => [['text' => 'Отель у моря'], ['text' => 'Номера и песчаный пляж']]]]),
    'unknown_editability', false, false, 'ordinary album caption nodes do not invent a content restriction');
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state' => 'absent'],
    ['video_count' => 1, 'roundvideo_count' => 0], TEST_EDIT_BOT, true),
    'format_requires_review', true, false, 'public ordinary video requires review without native provenance');
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state' => 'absent'],
    ['video_count' => 0, 'roundvideo_count' => 1], TEST_EDIT_BOT, true),
    'protected_by_owner', true, false, 'public round video remains protected');
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state' => 'absent'],
    ['video_count' => 1, 'roundvideo_count' => 1], TEST_EDIT_BOT, true),
    'protected_by_owner', true, false, 'round-video protection takes precedence over generic video review');
foreach ([['message_id' => 9000002], ['chat' => ['id' => -1001234567890]]] as $identityChange)
    editabilityState(editabilityNative(editabilityRaw($identityChange)), 'evidence_conflict', true, false, 'native identity mismatch');
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state' => 'absent'], [], TEST_EDIT_BOT, false),
    'channel_not_verified', true, false, 'missing channel rights');
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, ['state' => 'unaccepted_reserved_identity'],
    ['state' => 'absent'], [], TEST_EDIT_BOT, true), 'reserved_no_retry', true, true, 'reservation is not a sent message');
foreach ([
    'held native claim' => ['state' => 'sent', 'native_claim_held_or_unknown' => true],
    'claimed native version' => ['state' => 'claimed', 'native_claim_held_or_unknown' => false],
    'started native write' => ['state' => 'write_started', 'native_claim_held_or_unknown' => false],
    'unknown native write outcome' => ['state' => 'unknown', 'native_claim_held_or_unknown' => false],
] as $label => $nativeState) {
    $nativeState['telegram_message'] = $missing;
    editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, $nativeState,
        ['state' => 'absent'], [], TEST_EDIT_BOT, true), 'operation_requires_readback', true, true, $label);
}
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID,
    ['state' => 'sent', 'native_claim_held_or_unknown' => false, 'telegram_message' => $missing],
    ['state' => 'absent'], [], TEST_EDIT_BOT, true), 'unknown_editability', false, false,
    'sent unclaimed native version alone does not prove a media edit');
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID,
    ['state' => 'sent', 'native_claim_held_or_unknown' => false, 'telegram_message' => $missing,
        'content_edit_journal' => ['state' => 'present_do_not_replay']],
    ['state' => 'absent'], [], TEST_EDIT_BOT, true), 'operation_requires_readback', true, true,
    'existing content-edit operation remains held independently of media journal');

// Synthetic DOMs exercise the actual reader parsers without publicRead or HTTP.
function editabilityHtml(string $mid, array $captions, string $media = '', string $keyboard = ''): string
{
    $text = '';
    foreach ($captions as $caption)
        $text .= '<div class="tgme_widget_message_text">'
            . htmlspecialchars($caption, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div>';
    return '<!doctype html><html><head><meta charset="UTF-8"></head><body>'
        . '<div class="tgme_widget_message" data-post="anytour/' . $mid . '">'
        . $text . $media . $keyboard . '<time datetime="2020-01-01T10:00:00+00:00">10:00</time>'
        . '</div></body></html>';
}
$ordinaryVideoHtml = '<div class="tgme_widget_message_video_thumb"><video></video></div>';
$roundVideoHtml = '<div class="tgme_widget_message_roundvideo"><video></video></div>';
$ordinaryVideo = parsePublicRead(editabilityHtml(TEST_EDIT_MID, ['Отель у моря'], $ordinaryVideoHtml),
    'anytour', TEST_EDIT_MID);
editabilityCheck($ordinaryVideo['video_count'] > 0 && $ordinaryVideo['roundvideo_count'] === 0
    && $ordinaryVideo['caption'] === 'Отель у моря', 'ordinary parser exports video markers and UTF-8 caption');
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state' => 'absent'],
    $ordinaryVideo, TEST_EDIT_BOT, true), 'format_requires_review', true, false, 'parsed ordinary video requires review');
$roundVideo = parsePublicRead(editabilityHtml(TEST_EDIT_MID, ['Отель у моря'], $roundVideoHtml),
    'anytour', TEST_EDIT_MID);
editabilityCheck($roundVideo['video_count'] > 0 && $roundVideo['roundvideo_count'] > 0,
    'ordinary parser retains the round-video marker alongside its video element');
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state' => 'absent'],
    $roundVideo, TEST_EDIT_BOT, true), 'protected_by_owner', true, false, 'parsed round video remains protected');

// MID931 selects the existing album parser. These HTML strings are synthetic,
// not new observations of that real publication or an album membership claim.
$albumVideo = parsePublicRead(editabilityHtml('931', ['Отель у моря', 'Отзыв туриста'], $ordinaryVideoHtml),
    'anytour', '931');
editabilityCheck($albumVideo['caption'] === null
    && count($albumVideo['format_observation']['caption_nodes']) === 2
    && $albumVideo['format_observation']['caption_nodes'][1]['text'] === 'Отзыв туриста'
    && $albumVideo['video_count'] > 0 && $albumVideo['roundvideo_count'] === 0,
    'album parser exports both caption nodes and ordinary-video markers');
editabilityState(telegramTargetEditabilityRead('931', null, ['state' => 'absent'],
    $albumVideo, TEST_EDIT_BOT, true), 'protected_by_owner', true, false,
    'parsed album review takes priority over ordinary-video review');
$albumRound = parsePublicRead(editabilityHtml('931', ['Отель у моря', 'Номера и пляж'], $roundVideoHtml),
    'anytour', '931');
editabilityCheck($albumRound['caption'] === null && $albumRound['video_count'] > 0
    && $albumRound['roundvideo_count'] > 0, 'album parser exports round-video markers');
editabilityState(telegramTargetEditabilityRead('931', null, ['state' => 'absent'],
    $albumRound, TEST_EDIT_BOT, true), 'protected_by_owner', true, false, 'parsed album round video remains protected');

$photoHtml = '<a class="tgme_widget_message_photo_wrap 123456789" href="https://t.me/anytour/'
    . TEST_EDIT_MID . '" style="background-image:url(\'https://cdn4.telesco.pe/file/synthetic.jpg\')"></a>';
$plain = parsePublicRead(editabilityHtml(TEST_EDIT_MID, ['Отель у моря'], $photoHtml), 'anytour', TEST_EDIT_MID);
editabilityCheck($plain['single_photo'] === true && $plain['video_count'] === 0 && $plain['roundvideo_count'] === 0
    && $plain['buttons'] === [], 'ordinary photo DOM does not invent video or keyboard restrictions');
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state' => 'absent'],
    $plain, TEST_EDIT_BOT, true), 'unknown_editability', false, false, 'parsed photo remains unproven');
$buttonHtml = '<a class="tgme_widget_message_inline_button" href="https://example.invalid/existing-entry">Подобрать тур</a>';
$archive = parsePublicRead(editabilityHtml(TEST_EDIT_MID, ['Отель у моря'], $photoHtml, $buttonHtml),
    'anytour', TEST_EDIT_MID);
$corroborated = corroborateTargetKeyboard($plain, $archive);
editabilityCheck($corroborated['target_keyboard_archive_verified'] === true && count($corroborated['buttons']) === 1,
    'existing archive parser preserves a keyboard omitted by the synthetic embedded widget');
$keyboardClass = telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state' => 'absent'],
    $corroborated, TEST_EDIT_BOT, true);
editabilityState($keyboardClass, 'existing_keyboard_review', true, false, 'observed keyboard protects existing attribution');
editabilityCheck($keyboardClass['evidence_tier'] === 'existing_attribution',
    'keyboard review is attribution evidence rather than Telegram edit capability');
$emptyKeyboard = corroborateTargetKeyboard($plain, $plain);
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state' => 'absent'],
    $emptyKeyboard, TEST_EDIT_BOT, true), 'unknown_editability', false, false, 'empty confirmed keyboard adds no prohibition');
editabilityState(editabilityNative(editabilityRaw(['from' => ['id' => (int)TEST_EDIT_BOT, 'is_bot' => true]]),
    $corroborated), 'existing_keyboard_review', true, false, 'existing attribution takes priority over current-bot origin');
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state' => 'absent'],
    $corroborated, TEST_EDIT_BOT, true, true), 'operation_requires_readback', true, true,
    'prior operation retains no-retry priority over keyboard review');
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state' => 'absent'],
    array_replace($corroborated, ['caption' => 'Отзыв туриста']), TEST_EDIT_BOT, true),
    'protected_by_owner', true, false, 'owner review protection takes priority over keyboard review');
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state' => 'absent'],
    array_replace($corroborated, ['video_count' => 1]), TEST_EDIT_BOT, true),
    'format_requires_review', true, false, 'video review takes priority over keyboard review');

$tempBase = getenv('TMPDIR') ?: sys_get_temp_dir();
if (!is_dir($tempBase) || !is_writable($tempBase)) throw new RuntimeException('Writable test TMPDIR required');
$tempDirectory = rtrim($tempBase, DIRECTORY_SEPARATOR) . '/telegram-editability-' . bin2hex(random_bytes(6));
if (!mkdir($tempDirectory, 0700)) throw new RuntimeException('Cannot create synthetic journal directory');
register_shutdown_function(static function () use ($tempDirectory): void {
    $remove = static function (string $path) use (&$remove): void {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (glob($path . '/*') ?: [] as $child) $remove($child);
        rmdir($path);
    };
    $remove($tempDirectory);
});
function editabilityRawJournal(bool $success): array
{
    $request = ['chat_id' => TEST_EDIT_CHAT, 'message_id' => (int)TEST_EDIT_MID,
        'media' => ['type' => 'photo', 'media' => 'SYNTHETIC_PRIVATE_FILE', 'caption' => 'Synthetic fixture only'],
        'reply_markup' => ['inline_keyboard' => [[['text' => 'Synthetic', 'url' => 'https://example.invalid/fixture']]]]];
    $response = $success
        ? ['http' => 200, 'transport_error' => false, 'data' => ['ok' => true, 'result' => editabilityRaw()]]
        : ['http' => 400, 'transport_error' => false, 'data' => ['ok' => false, 'error_code' => 400,
            'description' => 'Bad Request: MESSAGE_ID_INVALID']];
    return [
        ['state' => 'intent_saved', 'synthetic_fixture_only' => true,
            'execution' => ['native_run_id' => '900000001', 'application_sha' => str_repeat('a', 40)],
            'row' => ['target_message_id' => TEST_EDIT_MID]],
        ['state' => 'identity_reserved', 'synthetic_fixture_only' => true],
        ['state' => 'request_prepared', 'request' => $request, 'request_sha256' => hash('sha256', jsonRead($request))],
        ['state' => 'provider_edit_started', 'provider_write_attempts' => 1],
        ['state' => 'provider_response_saved', 'response' => $response],
        ['state' => $success ? 'native_and_registry_verified' : 'provider_message_id_rejected',
            'provider_write_attempts' => 1, 'message_id' => TEST_EDIT_MID, 'url' => 'https://t.me/anytour/' . TEST_EDIT_MID],
    ];
}
function editabilityProjectedJournal(array $records): array
{
    global $tempDirectory;
    $path = $tempDirectory . '/' . bin2hex(random_bytes(6)) . '.jsonl';
    file_put_contents($path, implode("\n", array_map('jsonRead', $records)) . "\n");
    return receiptRead($path, TEST_EDIT_MID);
}
function editabilityJournalState(array $records, string $expected, string $label): void
{
    $projected = editabilityProjectedJournal($records);
    editabilityCheck(!str_contains(jsonRead($projected), 'SYNTHETIC_PRIVATE_FILE')
        && !str_contains(jsonRead($projected), 'example.invalid'), $label . ': safe projection');
    editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, $projected, [], TEST_EDIT_BOT, true, true),
        $expected, true, true, $label);
}
editabilityJournalState(editabilityRawJournal(false), 'rejected_no_retry', 'synthetic exact rejected operation');
editabilityJournalState(editabilityRawJournal(true), 'completed_no_retry', 'synthetic exact media acceptance');
$mutations = [
    'intent MID mismatch' => static function (array &$j): void { $j[0]['row']['target_message_id'] = '9000002'; },
    'request MID mismatch' => static function (array &$j): void { $j[2]['request']['message_id'] = 9000002; },
    'request chat mismatch' => static function (array &$j): void { $j[2]['request']['chat_id'] = '-1001234567890'; },
    'response MID mismatch' => static function (array &$j): void { $j[4]['response']['data']['result']['message_id'] = 9000002; },
    'response chat mismatch' => static function (array &$j): void { $j[4]['response']['data']['result']['chat']['id'] = -1001234567890; },
    'terminal MID mismatch' => static function (array &$j): void { $j[5]['message_id'] = '9000002'; },
    'terminal URL mismatch' => static function (array &$j): void { $j[5]['url'] = 'https://t.me/another_channel/' . TEST_EDIT_MID; },
    'explicit other method' => static function (array &$j): void { $j[2]['request']['method'] = 'editMessageReplyMarkup'; },
    'reply-markup success is not media-edit proof' => static function (array &$j): void { unset($j[2]['request']['media']); },
    'non-photo media request' => static function (array &$j): void { $j[2]['request']['media']['type'] = 'video'; },
    'unexpected JSON-string media' => static function (array &$j): void { $j[2]['request']['media'] = jsonRead($j[2]['request']['media']); },
    'transport failure cannot prove success' => static function (array &$j): void { $j[4]['response']['transport_error'] = true; },
    'zero terminal write count' => static function (array &$j): void { $j[5]['provider_write_attempts'] = 0; },
];
foreach ($mutations as $label => $mutate) {
    $journal = editabilityRawJournal(true);
    $mutate($journal);
    $journal[2]['request_sha256'] = hash('sha256', jsonRead($journal[2]['request']));
    editabilityJournalState($journal, 'operation_requires_readback', $label);
}
$badHash = editabilityRawJournal(true); $badHash[2]['request_sha256'] = str_repeat('0', 64);
editabilityJournalState($badHash, 'operation_requires_readback', 'request hash mismatch');
$incomplete = editabilityRawJournal(true); array_pop($incomplete);
editabilityJournalState($incomplete, 'operation_requires_readback', 'incomplete operation');
$duplicate = editabilityRawJournal(true); $duplicate[] = $duplicate[5];
editabilityJournalState($duplicate, 'operation_requires_readback', 'duplicate terminal history');
$otherFailure = editabilityRawJournal(false); $otherFailure[4]['response']['data']['description'] = 'Bad Request: message not found';
editabilityJournalState($otherFailure, 'operation_requires_readback', 'different provider error remains unresolved');
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state' => 'absent'], [], TEST_EDIT_BOT, true, true),
    'operation_requires_readback', true, true, 'operation directory without journal');
editabilityCheck(receiptRead($tempDirectory . '/absent.jsonl', TEST_EDIT_MID) === ['state' => 'absent'], 'missing journal remains absent');
$emptyPath = $tempDirectory . '/empty.jsonl'; file_put_contents($emptyPath, '');
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, receiptRead($emptyPath, TEST_EDIT_MID), [], TEST_EDIT_BOT, true),
    'operation_requires_readback', true, true, 'empty existing journal does not permit retry');

// Execute the real reader SQL and canonical registry against the established
// migration-backed SQLite fixture. No runtime configuration or registry substitute.
require_once dirname(__DIR__) . '/services/ChannelPostRegistryService.php';
require_once dirname(__DIR__) . '/services/ChannelPostButtonTransport.php';
require_once __DIR__ . '/fixtures/channel_post_registry_fixture.php';
define('OPERATIONS', $tempDirectory);
$readDb = new PDO('sqlite::memory:', null, null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
createChannelPostRegistryFixture($readDb);
$readDb->exec("INSERT INTO projects VALUES (1,'anytour','Synthetic reader fixture',1);
    INSERT INTO conversation_sources VALUES
        (9,1,'tg_anytour_msk','Synthetic target source','telegram',1,'manager'),
        (10,1,'tg_hotel_source','Synthetic source channel','telegram',1,'manager');
    INSERT INTO post_channels
        (id,project_id,source_id,platform,bot_key,canonical_bot_username,channel_id,display_name,bot_url,button_text,is_enabled)
    VALUES
        (6,1,9,'telegram','anyru_bot','anyru_bot','-1001845835645','Synthetic target','https://t.me/anyru_bot','Рассчитать на мои даты',1),
        (10,1,10,'telegram','anyru_bot','anyru_bot','-1002262666616','Synthetic source','https://t.me/anyru_bot','Рассчитать на мои даты',1)");
$readRegistry = new ChannelPostRegistryService($readDb);
$insertIdentity = $readDb->prepare('INSERT INTO post_publications
    (post_channel_id,source_id,external_message_id,start_payload,current_version_id) VALUES (6,9,?,?,?)');
$insertIdentity->execute(['9000011', 'p_' . str_repeat('r', 24), null]);
$reservedId = (int)$readDb->lastInsertId();
$insertIdentity->execute(['9000012', 'p_' . str_repeat('b', 24), 90009999]);
$sqlCases = [];
$number = 9000101;
foreach (['unknown', 'claimed', 'write_started', 'sent_with_claim', 'historical_unknown', 'sent'] as $case) {
    $mid = (string)$number++;
    $raw = editabilityRaw(['message_id' => (int)$mid, 'caption' => 'Synthetic SQL reader fixture',
        'chat' => ['id' => (int)TEST_EDIT_CHAT, 'type' => 'channel', 'username' => 'anytour']]);
    $snapshot = ChannelPostIncomingAdapter::telegramSnapshot($raw);
    $registered = $readRegistry->registerPublication('anytour', 'telegram', 'anyru_bot', TEST_EDIT_CHAT, $mid, $snapshot);
    if (!is_array($registered)) throw new RuntimeException('Synthetic registry setup failed');
    $state = $case === 'sent_with_claim' ? 'sent' : ($case === 'historical_unknown' ? 'unknown' : $case);
    $held = in_array($case, ['claimed', 'write_started', 'sent_with_claim'], true);
    $readDb->prepare('UPDATE post_versions SET state=?,claim_token=?,claimed_at=?,attempt_count=1,write_attempts=? WHERE id=?')
        ->execute([$state, $held ? 'synthetic-held-claim' : null, $held ? '2020-01-01 10:00:00' : null,
            in_array($state, ['unknown', 'write_started'], true) ? 1 : 0, $registered['version_id']]);
    if ($case === 'historical_unknown') {
        // A newer sent version must not erase the held outcome of an older one.
        $readDb->prepare("INSERT INTO post_versions
            (publication_id,version_number,content_hash,snapshot_json,state,attempt_count,write_attempts,receipt_json)
            SELECT publication_id,2,content_hash,snapshot_json,'sent',1,0,receipt_json FROM post_versions WHERE id=?")
            ->execute([$registered['version_id']]);
        $version = (int)$readDb->lastInsertId();
        $readDb->prepare('UPDATE post_publications SET current_version_id=? WHERE id=?')
            ->execute([$version, $registered['publication_id']]);
    }
    $sqlCases[$case] = $mid;
}
// Add a source and an unrelated assigned hotel using the same real registry.
// These are synthetic local records, never new owner-selected destinations.
$pairHotel = 900002;
$sourceRaw = editabilityRaw(['message_id' => 9000201, 'caption' => 'Synthetic prepared hotel fixture',
    'chat' => ['id' => -1002262666616, 'type' => 'channel', 'username' => 'any_toour']]);
$sourceSnapshot = ChannelPostIncomingAdapter::telegramSnapshot($sourceRaw);
$sourceSnapshot['metadata']['hotel_catalog_id'] = $pairHotel;
$sourceRegistered = $readRegistry->registerPublication('anytour', 'telegram', 'anyru_bot',
    '-1002262666616', '9000201', $sourceSnapshot);
if (!is_array($sourceRegistered)) throw new RuntimeException('Synthetic source registration failed');
$readDb->prepare("UPDATE post_versions SET state='sent' WHERE id=?")->execute([$sourceRegistered['version_id']]);
$duplicateSnapshot = ChannelPostIncomingAdapter::telegramSnapshot(editabilityRaw(['message_id' => 9000301]));
$duplicateSnapshot['metadata']['hotel_catalog_id'] = 900003;
$duplicateSnapshot['metadata']['logical_id'] = 'commercial-hotel-900003-v1';
$duplicateRegistered = $readRegistry->registerPublication('anytour', 'telegram', 'anyru_bot',
    TEST_EDIT_CHAT, '9000301', $duplicateSnapshot);
if (!is_array($duplicateRegistered)) throw new RuntimeException('Synthetic duplicate registration failed');
$readDb->prepare("UPDATE post_versions SET state='sent' WHERE id=?")->execute([$duplicateRegistered['version_id']]);
// SQLite JSON_EXTRACT already returns an unquoted scalar; keep the actual
// MySQL query intact with this syntax-only fixture adapter.
$readDb->sqliteCreateFunction('JSON_UNQUOTE', static fn($value) => $value, 1);
function editabilityDatabaseSnapshot(PDO $pdo): string
{
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $rows = [];
    foreach ($tables as $table) {
        if (!preg_match('/\A[a-z_]+\z/D', $table)) throw new RuntimeException('Unexpected SQLite fixture table');
        $rows[$table] = $pdo->query('SELECT * FROM "' . $table . '" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256', jsonRead($rows));
}
$databaseBefore = editabilityDatabaseSnapshot($readDb);
$changesBefore = (int)$readDb->query('SELECT total_changes()')->fetchColumn();
$readDb->exec('PRAGMA query_only=ON');
$reservedNative = publicationRead($readDb, $readRegistry, 6, '9000011');
editabilityCheck($reservedNative['publication_id'] === $reservedId
    && $reservedNative['state'] === 'unaccepted_reserved_identity'
    && $reservedNative['accepted_message_receipt_verified'] === false
    && $reservedNative['telegram_message']['state'] === 'unavailable',
    'actual SQL reserve without current version is read without registry_identity_changed');
editabilityState(telegramTargetEditabilityRead('9000011', $reservedNative, ['state' => 'absent'],
    [], TEST_EDIT_BOT, true), 'reserved_no_retry', true, true, 'actual SQL reservation remains held');
editabilityCheck(publicationRead($readDb, $readRegistry, 6, '90009998') === null,
    'actual SQL missing publication remains distinct from a reservation');
$brokenVersionHeld = false;
try { publicationRead($readDb, $readRegistry, 6, '9000012'); }
catch (RuntimeException $error) { $brokenVersionHeld = $error->getMessage() === 'reserved_native_observation_unavailable'; }
editabilityCheck($brokenVersionHeld, 'broken non-null current version is not misclassified as an empty reservation');
foreach ($sqlCases as $case => $mid) {
    $native = publicationRead($readDb, $readRegistry, 6, $mid);
    editabilityCheck(is_array($native) && $native['telegram_message']['state'] === 'native_snapshot'
        && $native['current_token_resolves'] === true, 'actual registry projection and token resolution: ' . $case);
    if ($case === 'sent') {
        editabilityCheck($native['native_claim_held_or_unknown'] === false, 'actual sent version has no held history');
        editabilityState(telegramTargetEditabilityRead($mid, $native, ['state' => 'absent'], [], TEST_EDIT_BOT, true),
            'unknown_editability', false, false, 'actual sent version does not invent media editability');
    } else {
        editabilityCheck($native['native_claim_held_or_unknown'] === true, 'actual SQL history remains held: ' . $case);
        editabilityState(telegramTargetEditabilityRead($mid, $native, ['state' => 'absent'], [], TEST_EDIT_BOT, true),
            'operation_requires_readback', true, true, 'actual SQL held version: ' . $case);
        if ($case === 'historical_unknown')
            editabilityCheck($native['state'] === 'sent' && count($native['version_history']) === 2,
                'non-current UNKNOWN is preserved behind a current sent version');
    }
}
editabilityCheck((int)$readDb->query('PRAGMA query_only')->fetchColumn() === 1
    && (int)$readDb->query('SELECT total_changes()')->fetchColumn() === $changesBefore
    && editabilityDatabaseSnapshot($readDb) === $databaseBefore,
    'actual reader and classifier perform no SQLite writes or lease expiration');
editabilityCheck(!defined('APP') && !defined('APPLICATION_SHA') && !defined('CONVERSATION_DB_HOST')
    && !function_exists('runtimeRead') && !function_exists('publicRead') && !function_exists('readTelegramPublicHtml'),
    'production runtime configuration and HTTP entry points were never loaded');
// The current prepared-pair functions called by the CLI are exercised below.

$calls = [];
$read = static function (string $channel, string $mid) use (&$calls): array {
    $calls[] = [$channel, $mid];
    return ['ok' => true, 'channel' => $channel, 'mid' => $mid];
};
$pair = ['source' => '9000002', 'target' => TEST_EDIT_MID];
$skipped = telegramCandidatePublicRead($pair, true, $read);
editabilityCheck($calls === [] && count($skipped) === 2
    && $skipped[0]['http_attempts'] === 0 && $skipped[1]['http_attempts'] === 0
    && $skipped[0]['reason'] === 'prior_operation_do_not_replay'
    && $skipped[1]['reason'] === 'prior_operation_do_not_replay', 'known prior operation makes zero public callbacks');
$readResult = telegramCandidatePublicRead($pair, false, $read);
editabilityCheck($calls === [['any_toour', '9000002'], ['anytour', TEST_EDIT_MID]]
    && $readResult[0]['ok'] === true && $readResult[0]['channel'] === 'any_toour'
    && $readResult[1]['ok'] === true && $readResult[1]['channel'] === 'anytour',
    'unknown candidate preserves the existing public source-target reads and return order');

// Only these literal owner constants are parsed. Never evaluate the bootstrap,
// runtime constants, provider setup, or selected-destination CLI loop.
function editabilityReaderLiteral(string $source, string $name): array
{
    if (preg_match('/^const ' . preg_quote($name, '/') . '\s*=\s*(\[[\s\S]*?\]);/m', $source, $match) !== 1)
        throw new RuntimeException('Owner constant missing: ' . $name);
    foreach (token_get_all('<?php ' . $match[1]) as $token) {
        if (is_array($token) && !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_LNUMBER,
            T_CONSTANT_ENCAPSED_STRING, T_DOUBLE_ARROW], true))
            throw new RuntimeException('Owner constant is not a literal array');
        if (is_string($token) && !in_array($token, ['[', ']', ','], true))
            throw new RuntimeException('Owner constant contains an expression');
    }
    return eval('return ' . $match[1] . ';');
}
editabilityCheck(editabilityReaderLiteral($readerSource, 'INSTALLED_BLOBS') === [
    '.github/workflows/edit-1566-ranked-46-historical-telegram.yml' => 'bf59d830df21fe9bc1cc4ba46d5ea01d14a4c972',
    'tests/run_channel_post_automation_regression.php' => '2d64bee3b446bb3569356e1d46b22ce4f8733786',
    'tools/anytour_existing_post_batch.php' => '444ba4561802ae96e9c65bb5c9e85b731e61022d',
], 'current owner installed source requirements remain exact');
editabilityCheck(editabilityReaderLiteral($readerSource, 'SMALL_CAPTION_SCOPE') === [
    102203 => ['telegram' => '1561', 'max' => 'AaHwVf1OCGc'],
    37931 => ['telegram' => '1554', 'max' => 'AaHwVbsRYzs'],
    102201 => ['telegram' => '1580', 'max' => 'AaHwWbJMJac'],
], 'current small-caption scope remains exact');
editabilityCheck(editabilityReaderLiteral($readerSource, 'PREPARED_PAIRS') === [
    8094 => ['source' => '905', 'source_publication' => 1951, 'target' => '567', 'target_date' => 1713769144,
        'target_caption' => '37fd47408b3e74a0dff1233df09f1f45ce5b94f3aa5a0b05c7a1f88fbffb4870',
        'target_photo' => '07989c88a8f257d8580905c1b368649dca1b00099b531c173b048f0930141a4a'],
    73082 => ['source' => '1025', 'source_publication' => 2204, 'target' => '588', 'target_date' => 1714555735,
        'target_caption' => '80a7b342b26fdf2f1310fc1125d5f8549c18f0cc8b350013f3d11823a033a252',
        'target_photo' => 'd5abd6334904eb43a00a531e64b5e812775524df8d7547e28c09087f130ec7c5'],
], 'current prepared pairs and preimages remain exact');

$noOperation = ['directory_exists' => false, 'directory_symlink' => false, 'journal' => ['state' => 'absent']];
$heldObservations = [
    'known journal' => array_replace($noOperation, ['directory_exists' => true,
        'journal' => ['state' => 'present_do_not_replay', 'states' => []]]),
    'directory without journal' => array_replace($noOperation, ['directory_exists' => true]),
    'unread evidence' => ['directory_exists' => null, 'directory_symlink' => null,
        'journal' => ['state' => 'unavailable']],
];
foreach ($heldObservations as $label => $operation) {
    $calls = [];
    $result = telegramPreparedPairPublicRead($pair, $operation, $read);
    editabilityCheck(telegramMediaOperationHeldRead($operation) && $calls === []
        && $result[0]['http_attempts'] === 0 && $result[1]['http_attempts'] === 0
        && $result[0]['ok'] === false && $result[1]['ok'] === false,
        'actual public pair helper makes zero callbacks: ' . $label);
}
$calls = [];
$result = telegramPreparedPairPublicRead($pair, $noOperation, $read);
editabilityCheck(!telegramMediaOperationHeldRead($noOperation)
    && $calls === [['any_toour', '9000002'], ['anytour', TEST_EDIT_MID]]
    && $result === [['ok' => true, 'channel' => 'any_toour', 'mid' => '9000002'],
        ['ok' => true, 'channel' => 'anytour', 'mid' => TEST_EDIT_MID]],
    'actual public pair helper preserves normal public reads and observations');

$preparedPair = ['source' => '9000201', 'source_publication' => $sourceRegistered['publication_id'],
    'target' => '9000401', 'target_date' => 1609459200,
    'target_caption' => hash('sha256', 'Synthetic destination fixture'),
    'target_photo' => hash('sha256', 'Synthetic destination photo marker')];
$preparedSource = telegramPreparedPairSourceRead($readDb, $readRegistry, $pairHotel, $preparedPair);
$preparedPublic = ['source' => ['ok' => true, 'single_photo' => true,
    'caption_sha256' => hash('sha256', $sourceSnapshot['text']),
    'buttons' => [['hotel_url' => $preparedSource['button']]]],
    'target' => ['ok' => true, 'single_photo' => true, 'caption' => 'Synthetic destination fixture',
        'caption_sha256' => $preparedPair['target_caption'], 'published_unixtime' => $preparedPair['target_date'],
        'photo_marker_sha256' => $preparedPair['target_photo'], 'buttons' => []]];
$pairFeed = ['tg_' . $pairHotel => 'https://t.me/any_toour/' . $preparedPair['source']];
$ownerNames = ['source_native_identity', 'source_public_body', 'source_one_live_button', 'source_token_context',
    'source_photo', 'source_no_writer', 'target_no_registry_or_duplicate', 'target_no_prior_media_operation',
    'target_preimage', 'target_no_keyboard', 'canonical_feed_and_route', 'native_channel_rights'];
$preparedInput = ['hotel' => $pairHotel, 'pair' => $preparedPair, 'public' => $preparedPublic,
    'prior' => $noOperation, 'fresh' => $noOperation, 'rights' => true, 'feed' => $pairFeed, 'routes' => $pairFeed,
    'target' => telegramPreparedPairTargetRead($readDb, $readRegistry, $pairHotel, $preparedPair),
    'source' => $preparedSource];
function editabilityPreparedCase(array $input): array
{
    $calls = [];
    $row = telegramPreparedPairNativeRead($input['hotel'], $input['pair'], $input['public'], $input['prior'],
        $input['rights'], $input['feed'], $input['routes'],
        static function (int $hotel, array $pair) use (&$calls, $input): array {
            $calls[] = 'target';
            if (($input['throw'] ?? '') === 'target') throw new RuntimeException('Synthetic target failure /private/path');
            return $input['target'];
        },
        static function (int $hotel, array $pair) use (&$calls, $input): array {
            $calls[] = 'source';
            if (($input['throw'] ?? '') === 'source') throw new RuntimeException('Synthetic source failure /private/path');
            return $input['source'];
        },
        static function (string $mid) use (&$calls, $input): array {
            $calls[] = 'operation';
            if (($input['throw'] ?? '') === 'operation') throw new RuntimeException('Synthetic journal failure /private/path');
            return $input['fresh'];
        });
    return [$row, $calls];
}
[$normal, $normalCalls] = editabilityPreparedCase($preparedInput);
editabilityCheck($normalCalls === ['operation', 'target', 'source']
    && array_keys($normal['checks']) === $ownerNames && array_values($normal['checks']) === array_fill(0, 12, true)
    && $normal['prepared_checks_pass'] === true && $normal['candidate_preparation_allowed'] === true
    && $normal['candidate_preparation_skipped'] === false && $normal['write_admission'] === false,
    'actual prepared pair passes all twelve owner checks after target-before-source reads');
editabilityState($normal['candidate_assessment'], 'unknown_editability', false, false,
    'normal preparation does not prove per-message editability');
editabilityCheck($normal['candidate_assessment']['editor_bot_id'] === null
    && $normal['candidate_assessment']['channel_rights_verified'] === true
    && $normal['candidate_assessment']['method'] === 'editMessageMedia'
    && $normal['candidate_assessment']['read_purpose'] === 'candidate_preparation',
    'actual channel-rights result is not fabricated bot identity or method capability');
editabilityCheck($preparedInput['public'] === $preparedPublic, 'prepared helper does not rewrite public observations');
$sqlBoundaryCalls = [];
$sqlNormal = telegramPreparedPairNativeRead($pairHotel, $preparedPair, $preparedPublic, $noOperation,
    true, $pairFeed, $pairFeed,
    static function (int $hotel, array $pair) use ($readDb, $readRegistry, &$sqlBoundaryCalls): array {
        $sqlBoundaryCalls[] = 'target';
        return telegramPreparedPairTargetRead($readDb, $readRegistry, $hotel, $pair);
    },
    static function (int $hotel, array $pair) use ($readDb, $readRegistry, &$sqlBoundaryCalls): array {
        $sqlBoundaryCalls[] = 'source';
        return telegramPreparedPairSourceRead($readDb, $readRegistry, $hotel, $pair);
    },
    static function (string $mid) use (&$sqlBoundaryCalls): array {
        $sqlBoundaryCalls[] = 'operation';
        return telegramMediaOperationRead($mid);
    });
editabilityCheck($sqlBoundaryCalls === ['operation', 'target', 'source'] && $sqlNormal === $normal,
    'normal CLI helper boundary executes the actual journal and SQL readers with the same result');

// Protected content must stop this row before either SQL target or source
// preparation. Exercise the public caption and album-caption-node boundaries;
// the following ordinary commercial row must remain independently preparable.
$protectedCaptions = [
    'Многие туристы отмечают, что здесь отличный сервис.',
    'Впечатления от нашей поездки в отель.',
    'Наш менеджер вернулся из поездки.',
    'Мы побывали в этом отеле и делимся подробностями.',
    'Туристы поделились впечатлениями о поездке.',
    'По словам наших гостей, всё понравилось.',
    'Цитата туриста о поездке.',
    'Скриншоты сообщений после отдыха.',
    'Видео менеджера из отеля.',
    'Поездка нашего сотрудника в отель.',
];
foreach ($protectedCaptions as $caption) foreach (['caption', 'album_node'] as $representation) {
    $input = $preparedInput;
    if ($representation === 'caption') $input['public']['target']['caption'] = $caption;
    else {
        $input['public']['target']['caption'] = null;
        $input['public']['target']['format_observation']['caption_nodes'] = [['text' => $caption]];
    }
    [$protected, $calls] = editabilityPreparedCase($input);
    editabilityState($protected['candidate_assessment'], 'protected_by_owner', true, false,
        'protected ' . $representation . ': ' . $caption);
    editabilityCheck($calls === ['operation'] && $protected['candidate_preparation_allowed'] === false
        && $protected['prepared_checks_pass'] === false && $protected['write_admission'] === false,
        'protected content does not reach target or source preparation');
}
[$afterProtected, $afterProtectedCalls] = editabilityPreparedCase($preparedInput);
editabilityCheck($afterProtectedCalls === ['operation', 'target', 'source']
    && $afterProtected['candidate_preparation_allowed'] === true
    && $afterProtected['prepared_checks_pass'] === true && $afterProtected['write_admission'] === false,
    'protected row does not block the following ordinary commercial photo row');

// A new operation can appear after the refreshed journal observation. The
// owner's final filesystem check must still prevent preparation admission.
$lateOperationPath = OPERATIONS . '/anytour-issue1566-historical-tg-media-' . $preparedPair['target'] . '-v1';
$lateOperationCalls = []; $lateOperationObserved = null;
try {
    $lateOperationRow = telegramPreparedPairNativeRead($pairHotel, $preparedPair, $preparedPublic, $noOperation,
        true, $pairFeed, $pairFeed,
        static function (int $hotel, array $pair) use ($readDb, $readRegistry, &$lateOperationCalls): array {
            $lateOperationCalls[] = 'target';
            return telegramPreparedPairTargetRead($readDb, $readRegistry, $hotel, $pair);
        },
        static function (int $hotel, array $pair) use ($readDb, $readRegistry, $lateOperationPath, &$lateOperationCalls): array {
            $lateOperationCalls[] = 'source';
            requireRead(mkdir($lateOperationPath, 0700), 'synthetic_late_operation_unavailable');
            return telegramPreparedPairSourceRead($readDb, $readRegistry, $hotel, $pair);
        },
        static function (string $mid) use (&$lateOperationCalls, &$lateOperationObserved): array {
            $lateOperationCalls[] = 'operation';
            $lateOperationObserved = telegramMediaOperationRead($mid);
            return $lateOperationObserved;
        });
    editabilityCheck($lateOperationObserved === $noOperation
        && $lateOperationCalls === ['operation', 'target', 'source']
        && $lateOperationRow['checks']['target_no_prior_media_operation'] === false
        && $lateOperationRow['prepared_checks_pass'] === false
        && $lateOperationRow['candidate_preparation_allowed'] === false
        && $lateOperationRow['write_admission'] === false,
        'operation appearing during the sole source callback fails the final owner check and admission');
} finally {
    if (is_dir($lateOperationPath)) rmdir($lateOperationPath);
}

foreach ($heldObservations as $label => $operation) {
    [$held, $heldCalls] = editabilityPreparedCase(array_replace($preparedInput, ['prior' => $operation]));
    editabilityCheck($heldCalls === ['operation'] && $held['candidate_preparation_allowed'] === false
        && $held['prepared_checks_pass'] === false && $held['write_admission'] === false
        && $held['candidate_assessment']['no_retry'] === true,
        'earlier hold survives directory disappearance without native callbacks: ' . $label);
}
foreach ([null, false] as $rights) {
    [$held, $heldCalls] = editabilityPreparedCase(array_replace($preparedInput, ['rights' => $rights]));
    editabilityCheck($heldCalls === ['operation'] && $held['candidate_preparation_allowed'] === false
        && $held['prepared_checks_pass'] === false
        && $held['candidate_assessment']['channel_rights_verified'] === $rights
        && $held['candidate_assessment']['editor_bot_id'] === null,
        'missing or failed actual rights cannot become a fabricated ready result');
}

// Actual SQL reads exercise reserved, broken-version, all existing states, and
// the hotel's existing assignment. No target may reach source preparation.
$targetCases = ['reserve without version' => '9000011', 'broken current version' => '9000012'] + $sqlCases;
foreach ($targetCases as $label => $mid) {
    $casePair = array_replace($preparedPair, ['target' => $mid]);
    $actualTarget = telegramPreparedPairTargetRead($readDb, $readRegistry, $pairHotel, $casePair);
    [$held, $heldCalls] = editabilityPreparedCase(array_replace($preparedInput,
        ['pair' => $casePair, 'target' => $actualTarget]));
    editabilityCheck(count($actualTarget['target_native_ids']) === 1 && $heldCalls === ['operation', 'target']
        && $held['candidate_preparation_allowed'] === false && $held['prepared_checks_pass'] === false
        && $held['checks']['target_no_registry_or_duplicate'] === false && $held['write_admission'] === false,
        'real registered target prevents source preparation: ' . $label);
    if ($label === 'reserve without version')
        editabilityCheck($held['candidate_assessment']['state'] === 'reserved_no_retry'
            && $actualTarget['target_native']['state'] === 'unaccepted_reserved_identity',
            'real null-version reserve retains its precise reason');
    if ($label === 'broken current version')
        editabilityCheck($actualTarget['target_native'] === null
            && $actualTarget['target_native_projection_reason'] === 'target_native_projection_unavailable'
            && $held['candidate_assessment']['state'] === 'existing_registry_assignment',
            'projection failure cannot erase a real target identity');
}
$duplicateTarget = telegramPreparedPairTargetRead($readDb, $readRegistry, 900003, $preparedPair);
[$duplicateHeld, $duplicateCalls] = editabilityPreparedCase(array_replace($preparedInput,
    ['hotel' => 900003, 'target' => $duplicateTarget]));
editabilityCheck($duplicateTarget['target_native_ids'] === []
    && $duplicateTarget['hotel_duplicate_publication_ids'] === [$duplicateRegistered['publication_id']]
    && $duplicateCalls === ['operation', 'target'] && $duplicateHeld['candidate_preparation_allowed'] === false,
    'actual duplicate-hotel query prevents source preparation without a target identity');
// The canonical schema normally enforces one identity. If the query ever
// observes more than one, an unavailable projection must still prevent reuse.
$ambiguousTarget = array_replace($preparedInput['target'], ['target_native_ids' => [900098, 900099],
    'target_native' => null, 'target_native_projection_reason' => 'target_native_projection_unavailable']);
[$ambiguousHeld, $ambiguousCalls] = editabilityPreparedCase(array_replace($preparedInput,
    ['target' => $ambiguousTarget]));
editabilityCheck($ambiguousCalls === ['operation', 'target']
    && $ambiguousHeld['checks']['target_no_registry_or_duplicate'] === false
    && $ambiguousHeld['candidate_preparation_allowed'] === false
    && $ambiguousHeld['prepared_checks_pass'] === false && $ambiguousHeld['write_admission'] === false,
    'multiple observed identities cannot release a target with no native projection');

$ownerFailures = [
    'source_native_identity' => static function (array &$in): void { $in['source']['source']['state'] = 'pending'; },
    'source_public_body' => static function (array &$in): void { $in['public']['source']['caption_sha256'] = str_repeat('0', 64); },
    'source_one_live_button' => static function (array &$in): void { $in['public']['source']['buttons'] = []; },
    'source_token_context' => static function (array &$in): void { $in['source']['context']['version_id']++; },
    'source_photo' => static function (array &$in): void { $in['source']['source']['snapshot']['attachments'] = []; },
    'source_no_writer' => static function (array &$in): void { $in['source']['native_writer_ids'] = [900099]; },
    'target_no_registry_or_duplicate' => static function (array &$in): void { $in['target']['target_native_ids'] = [900099]; },
    'target_no_prior_media_operation' => static function (array &$in): void { $in['fresh']['directory_exists'] = true; },
    'target_preimage' => static function (array &$in): void { $in['public']['target']['published_unixtime']++; },
    'target_no_keyboard' => static function (array &$in): void { $in['public']['target']['buttons'] = [['hotel_url' => 'https://example.invalid/fixture']]; },
    'canonical_feed_and_route' => static function (array &$in): void { $in['routes'] = []; },
    'native_channel_rights' => static function (array &$in): void { $in['rights'] = false; },
];
editabilityCheck(array_keys($ownerFailures) === $ownerNames, 'every existing owner condition has a negative case');
foreach ($ownerFailures as $name => $mutate) {
    $input = $preparedInput; $mutate($input);
    [$failed] = editabilityPreparedCase($input);
    editabilityCheck($failed['prepared_checks_pass'] === false && $failed['candidate_preparation_allowed'] === false
        && $failed['write_admission'] === false && ($failed['checks'][$name] ?? false) !== true,
        'existing owner condition remains required for preparation: ' . $name);
}
foreach (['operation', 'target', 'source'] as $boundary) {
    [$failed, $failedCalls] = editabilityPreparedCase(array_replace($preparedInput, ['throw' => $boundary]));
    editabilityCheck($failed['candidate_preparation_allowed'] === false && $failed['prepared_checks_pass'] === false
        && $failed['write_admission'] === false && $failed['candidate_assessment']['evidence_tier'] === 'unavailable'
        && !str_contains(jsonRead($failed), '/private/path')
        && ($boundary !== 'target' || !in_array('source', $failedCalls, true)),
        'failed read boundary holds preparation with a bounded reason: ' . $boundary);
}

// The actual journal observer is local, bounded, symlink-safe and restores any
// outer error handler on success and on failed optional observations.
$operationPath = OPERATIONS . '/anytour-issue1566-historical-tg-media-' . TEST_EDIT_MID . '-v1';
editabilityCheck(telegramMediaOperationRead(TEST_EDIT_MID) === $noOperation,
    'actual optional journal observer distinguishes confirmed absence');
mkdir($operationPath, 0700);
editabilityCheck(telegramMediaOperationHeldRead(telegramMediaOperationRead(TEST_EDIT_MID)),
    'actual existing empty operation directory remains held');
$receiptPath = $operationPath . '/receipt.jsonl';
file_put_contents($receiptPath, implode("\n", array_map('jsonRead', editabilityRawJournal(false))) . "\n");
$rejectedOperation = telegramMediaOperationRead(TEST_EDIT_MID);
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, $rejectedOperation['journal'], [], null,
    true, $rejectedOperation['directory_exists']), 'rejected_no_retry', true, true,
    'actual optional journal read retains exact method-bound rejection');
file_put_contents($receiptPath, '{bad synthetic JSON');
$outerWarnings = 0;
set_error_handler(static function (int $severity, string $message) use (&$outerWarnings): bool { $outerWarnings++; return true; });
$malformedOperation = telegramMediaOperationRead(TEST_EDIT_MID);
trigger_error('Synthetic restoration check', E_USER_NOTICE);
restore_error_handler();
editabilityCheck($malformedOperation['journal'] === ['state' => 'unavailable', 'reason' => 'media_operation_read_unavailable']
    && $outerWarnings === 1 && telegramMediaOperationHeldRead($malformedOperation),
    'malformed optional journal is held and the caller error handler is restored');
file_put_contents($receiptPath, str_repeat('x', 1_000_001));
editabilityCheck(telegramMediaOperationRead(TEST_EDIT_MID)['journal']['state'] === 'unavailable',
    'actual optional journal enforces the byte bound');
file_put_contents($receiptPath, str_repeat("{}\n", 501));
editabilityCheck(telegramMediaOperationRead(TEST_EDIT_MID)['journal']['state'] === 'unavailable',
    'actual optional journal enforces the event bound');
unlink($receiptPath);
$symlinkReceiptTarget = $tempDirectory . '/synthetic-linked-journal.jsonl';
file_put_contents($symlinkReceiptTarget, "{}\n");
symlink($symlinkReceiptTarget, $receiptPath);
editabilityCheck(telegramMediaOperationRead(TEST_EDIT_MID)['journal']['reason'] === 'operation_receipt_symlink',
    'actual optional journal refuses a symlink receipt');
unlink($receiptPath); rmdir($operationPath);
symlink($tempDirectory, $operationPath);
editabilityCheck(telegramMediaOperationRead(TEST_EDIT_MID)['journal']['reason'] === 'operation_directory_symlink',
    'actual optional journal refuses a symlink operation directory');
unlink($operationPath);
foreach (['0', '01', '../9000001', str_repeat('9', 21)] as $invalidMid) {
    $unread = telegramMediaOperationRead($invalidMid);
    editabilityCheck($unread['directory_exists'] === null && $unread['journal']['state'] === 'unavailable'
        && $unread['journal']['reason'] === 'invalid_target_message_id' && telegramMediaOperationHeldRead($unread),
        'invalid MID cannot select an operation path: ' . $invalidMid);
}
editabilityCheck((int)$readDb->query('PRAGMA query_only')->fetchColumn() === 1
    && (int)$readDb->query('SELECT total_changes()')->fetchColumn() === $changesBefore
    && editabilityDatabaseSnapshot($readDb) === $databaseBefore,
    'current actual prepared-pair source and target queries leave every SQL table unchanged');

echo 'OK ' . $checks . ' destination-editability checks; historical 33 before/15 rejected/18 accepted; '
    . "synthetic native/journal/SQL/current prepared-pair cases; provider calls 0\n";

