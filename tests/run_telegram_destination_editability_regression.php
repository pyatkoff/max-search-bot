<?php
declare(strict_types=1);

// Load selected declarations only; keep the CLI bootstrap and HTTP entry points unloaded.
$readerPath = getenv('TELEGRAM_EDITABILITY_READER') ?: dirname(__DIR__)
    . '/tools/direct_demand/operations/read-1566-telegram-46-20261123.php';
$readerSource = file_get_contents($readerPath);
if (!is_string($readerSource) || ($runtimeStart = strpos($readerSource, "\n\$out =")) === false)
    throw new RuntimeException('Reader declaration boundary unavailable');
$wanted = array_fill_keys(['requireRead', 'jsonRead', 'telegramCandidatePublicRead',
    'telegramNativeEditEvidenceRead', 'telegramTargetEditabilityRead', 'receiptRead',
    'parsePublicRead', 'albumStructureRead', 'corroborateTargetKeyboard', 'publicationRead',
    'telegramAcceptedCandidateAssessmentRead', 'telegramAcceptedReadbackRowRead'], false);
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
// This integration covers publicationRead plus classification only. The CLI
// per-row source-skipping branch is intentionally not copied or executed here.

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


// The current CLI reads accepted routes. Candidate policy must annotate that
// readback without skipping its public/native reads or changing its nine checks.
$acceptedPurpose = telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state'=>'absent'],
    ['buttons'=>[['text'=>'Synthetic accepted hotel button']]], TEST_EDIT_BOT, true, false, 'accepted_readback');
editabilityState($acceptedPurpose, 'accepted_target_not_for_reuse', true, true, 'accepted scope never becomes a fresh candidate');
editabilityCheck($acceptedPurpose['read_purpose'] === 'accepted_readback'
    && $acceptedPurpose['readback_required'] === true && $acceptedPurpose['candidate_preparation_allowed'] === false
    && $acceptedPurpose['channel_rights_verified'] === null && $acceptedPurpose['editor_bot_id'] === null
    && $acceptedPurpose['reasons'] === ['accepted_destination_scope'], 'accepted purpose does not invent editor or channel rights');
editabilityState(telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state'=>'absent'], [], null, null),
    'channel_not_verified', true, false, 'nullable candidate rights remain unverified');
$invalidPurpose = false;
try { telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state'=>'absent'], [], null, null, false, 'arbitrary_mode'); }
catch (RuntimeException $error) { $invalidPurpose = $error->getMessage() === 'read_purpose_invalid'; }
editabilityCheck($invalidPurpose, 'only the two internal literal read purposes are accepted');
foreach ([
    'reservation'=>[['state'=>'unaccepted_reserved_identity'], 'reserved_no_retry'],
    'claim'=>[['native_claim_held_or_unknown'=>true], 'operation_requires_readback'],
    'unknown'=>[['state'=>'unknown'], 'operation_requires_readback'],
    'content operation'=>[['content_edit_journal'=>['state'=>'present_do_not_replay']], 'operation_requires_readback'],
    'identity conflict'=>[['telegram_message'=>['state'=>'identity_mismatch']], 'evidence_conflict'],
] as $label => [$nativeCase, $state]) {
    $assessment = telegramTargetEditabilityRead(TEST_EDIT_MID, $nativeCase, ['state'=>'absent'], [],
        null, null, false, 'accepted_readback');
    editabilityState($assessment, $state, true, true, 'accepted scope retains precise ' . $label);
    editabilityCheck(in_array('accepted_destination_scope', $assessment['reasons'], true),
        'accepted no-reuse reason survives detailed ' . $label);
}
$unreadAcceptedJournal = telegramTargetEditabilityRead(TEST_EDIT_MID, null, ['state'=>'unavailable'], [],
    null, null, false, 'accepted_readback');
editabilityState($unreadAcceptedJournal, 'operation_requires_readback', true, true,
    'unread accepted journal cannot become absence or completion');
editabilityCheck($unreadAcceptedJournal['evidence_tier'] === 'unavailable'
    && $unreadAcceptedJournal['reasons'] === ['accepted_destination_scope','media_operation_evidence_unavailable'],
    'unread journal without an observed directory is unavailable evidence, not a proven prior operation');

// Parse only literal PAIRS data, never the production bootstrap or its constants.
editabilityCheck(preg_match('/^const PAIRS = (\[.*?^\]);/ms', $readerSource, $pairMatch) === 1
    && preg_match("/^const PAIR_DIGEST = '([a-f0-9]{64})';/m", $readerSource, $digestMatch) === 1,
    'current accepted packet is present');
foreach (token_get_all('<?php ' . $pairMatch[1]) as $token) {
    if (is_array($token)) {
        if (!in_array($token[0], [T_OPEN_TAG,T_WHITESPACE,T_LNUMBER,T_CONSTANT_ENCAPSED_STRING,T_DOUBLE_ARROW], true))
            throw new RuntimeException('Accepted packet must contain only literal data');
    } elseif (!in_array($token, ['[',']',','], true)) throw new RuntimeException('Non-literal accepted packet');
}
$acceptedPairs = eval('return ' . $pairMatch[1] . ';');
$packet = [];
foreach ($acceptedPairs as $hotel => $pair)
    $packet[] = [$hotel,(int)$pair['source'],(int)$pair['target'],$pair['publication_id'],$pair['version_id'],
        $pair['published_unixtime'],$pair['expected_caption_sha256']];
$historicalAccepted = array_column(array_filter($fixture['observations'],
    static fn(array $row): bool => $row['outcome'] !== 'message_id_invalid'), 'message_id');
$currentAccepted = array_column($acceptedPairs, 'target'); sort($historicalAccepted); sort($currentAccepted);
editabilityCheck(count($packet) === 18 && $currentAccepted === $historicalAccepted,
    'only the existing18 accepted destinations remain selected');
editabilityCheck($digestMatch[1] === '91ad991e49fcc3848b71c307573d91616d8e1de6644f324f10834979b9c0c2fa'
    && hash('sha256', jsonRead($packet)) === $digestMatch[1],
    'exact upstream90 publication/version/original-date/caption packet is preserved');
$runtimeSource = substr($readerSource, $runtimeStart);
editabilityCheck(str_contains($runtimeSource, '$out[\'rows\'][]=telegramAcceptedReadbackRowRead($hotel,$pair,$src,$dst,$pub,$feed,$landings);')
    && str_contains($runtimeSource, '$out[\'rows\'][]=telegramAcceptedReadbackRowRead($hotel,$pair,null,null,[],$feed,$landings,reasonRead($rowError));'),
    'success and error CLI rows call the actual tested readback helper without stale native reuse');
editabilityCheck(!str_contains($runtimeSource, 'telegramCandidatePublicRead(')
    && !str_contains($runtimeSource, 'getMe') && !str_contains($runtimeSource, 'ChannelPostRuntime')
    && str_contains($runtimeSource, "'native_read_request_count'=>0"),
    'accepted CLI does not restore candidate skips or native API identity probes');

$readbackHotel = 900099;
$readbackHash = hash('sha256', 'Synthetic accepted caption');
$readbackPair = ['source'=>'9000002','target'=>TEST_EDIT_MID,'publication_id'=>9000031,'version_id'=>9000032,
    'published_unixtime'=>1609459200,'expected_caption_sha256'=>$readbackHash];
$readbackPhoto = ['reusable_telegram_photo'=>true,'file_unique_id_sha256'=>hash('sha256','Synthetic accepted photo')];
$readbackSource = ['attachments'=>[$readbackPhoto]];
$readbackTarget = ['publication_id'=>9000031,'version_id'=>9000032,'state'=>'sent','hotel_id'=>$readbackHotel,
    'post_url'=>'https://t.me/anytour/'.TEST_EDIT_MID,'native_claim_held_or_unknown'=>false,
    'attachments'=>[$readbackPhoto],'caption_sha256'=>$readbackHash,'one_expected_button'=>true,
    'current_token_resolves'=>true,'button_url'=>'https://t.me/anyru_bot?start=p_SYNTHETIC_READBACK_ONLY',
    'content_edit_journal'=>['state'=>'absent'],'telegram_message'=>$missing,
    'published_at'=>'2021-01-01T00:00:00+00:00'];
$readbackPublic = ['ok'=>true,'single_photo'=>true,'caption_sha256'=>$readbackHash,'published_unixtime'=>1609459200,
    'target_keyboard_archive_verified'=>true,'buttons'=>[['hotel_url'=>$readbackTarget['button_url']]]];
$readbackFeed = ['tg_'.$readbackHotel=>'https://t.me/any_toour/'.$readbackPair['source']];
$readbackLandings = ['tg_'.$readbackHotel=>$readbackTarget['post_url']];
$readback = static function (?array $src = null, ?array $dst = null, ?array $pub = null,
    ?array $feed = null, ?array $landings = null) use ($readbackHotel,$readbackPair,$readbackSource,
    $readbackTarget,$readbackPublic,$readbackFeed,$readbackLandings): array {
    return telegramAcceptedReadbackRowRead($readbackHotel,$readbackPair,$src ?? $readbackSource,
        $dst ?? $readbackTarget,$pub ?? $readbackPublic,$feed ?? $readbackFeed,$landings ?? $readbackLandings);
};
$goodRow = $readback();
$checkNames = ['registered_identity','original_version_current','native_source_photo','public_caption',
    'public_single_photo','one_hotel_button_and_exact_token_context','accepted_original_date',
    'accepted_original_caption','installed_route'];
editabilityCheck($goodRow['verified'] === true && array_keys($goodRow['checks']) === $checkNames
    && !in_array(false,$goodRow['checks'],true), 'actual accepted row helper preserves all9 successful upstream checks');
editabilityCheck($goodRow['read_purpose'] === 'accepted_readback' && $goodRow['readback_required']
    && $goodRow['candidate_assessment']['readback_required'] && !$goodRow['candidate_preparation_allowed']
    && $goodRow['no_retry'] === true && $goodRow['candidate_assessment']['state'] === 'accepted_target_not_for_reuse'
    && $goodRow['candidate_assessment']['prior_media_operation'] === ['state'=>'absent'],
    'accepted button and genuinely absent journal allow readback but never reuse');
$badCases = [
    'registered_identity'=>['dst'=>['publication_id'=>9000999]],
    'original_version_current'=>['dst'=>['version_id'=>9000999]],
    'native_source_photo'=>['src'=>['attachments'=>[array_replace($readbackPhoto,['file_unique_id_sha256'=>'different'])]]],
    'public_caption'=>['dst'=>['caption_sha256'=>hash('sha256','Different native caption')]],
    'public_single_photo'=>['pub'=>['single_photo'=>false]],
    'one_hotel_button_and_exact_token_context'=>['dst'=>['current_token_resolves'=>false]],
    'accepted_original_date'=>['pub'=>['published_unixtime'=>1609459201]],
    'accepted_original_caption'=>['dst'=>['caption_sha256'=>hash('sha256','Changed accepted caption')],
        'pub'=>['caption_sha256'=>hash('sha256','Changed accepted caption')]],
    'installed_route'=>['landings'=>['tg_'.$readbackHotel=>'https://t.me/anytour/9000999']],
];
foreach ($badCases as $check => $changes) {
    $row = $readback(array_replace($readbackSource,$changes['src']??[]),
        array_replace($readbackTarget,$changes['dst']??[]),array_replace($readbackPublic,$changes['pub']??[]),
        $readbackFeed,array_replace($readbackLandings,$changes['landings']??[]));
    editabilityCheck($row['verified'] === false && $row['checks'][$check] === false
        && $row['no_retry'] === true && !$row['candidate_preparation_allowed'],
        'actual readback retains failing upstream check: '.$check);
}
$heldRow = $readback(null,array_replace($readbackTarget,['native_claim_held_or_unknown'=>true]));
editabilityCheck(!$heldRow['verified'] && !$heldRow['checks']['registered_identity']
    && $heldRow['candidate_assessment']['state'] === 'operation_requires_readback',
    'actual accepted row does not waive a held native claim');
$nativeDateDrift = $readback(null,array_replace($readbackTarget,['published_at'=>'2099-01-01T00:00:00Z']));
editabilityCheck($nativeDateDrift['verified'] && $nativeDateDrift['checks']['accepted_original_date'],
    'upstream90 original date comparison is not replaced with obsolete native date equality');
$errorRow = telegramAcceptedReadbackRowRead($readbackHotel,$readbackPair,null,null,[],$readbackFeed,$readbackLandings,
    'registry_identity_changed');
editabilityCheck(!$errorRow['verified'] && $errorRow['checks'] === ['row_available'=>false]
    && $errorRow['reason'] === 'registry_identity_changed' && $errorRow['no_retry']
    && !$errorRow['candidate_preparation_allowed'] && $errorRow['readback_required']
    && $errorRow['candidate_assessment']['readback_required'] && $errorRow['candidate_assessment']['editor_bot_id'] === null,
    'actual error-row output retains accepted scope without stale native data');

$mediaDirectory = OPERATIONS.'/anytour-issue1566-historical-tg-media-'.TEST_EDIT_MID.'-v1';
mkdir($mediaDirectory,0700);
$mediaReceipt = $mediaDirectory.'/receipt.jsonl';
foreach ([false=>'rejected_no_retry',true=>'completed_no_retry'] as $success => $state) {
    file_put_contents($mediaReceipt,implode("\n",array_map('jsonRead',editabilityRawJournal((bool)$success)))."\n");
    $row = $readback();
    editabilityCheck($row['verified'] && $row['checks'] === $goodRow['checks'],
        'journal annotation cannot change accepted checks: '.$state);
    editabilityState($row['candidate_assessment'],$state,true,true,'accepted actual journal: '.$state);
    editabilityCheck(!str_contains(jsonRead($row['candidate_assessment']),'SYNTHETIC_PRIVATE_FILE')
        && $row['candidate_assessment']['channel_rights_verified'] === null,
        'accepted journal projection remains bounded and does not invent rights: '.$state);
}
foreach (['malformed'=>'{not valid JSON','oversized'=>str_repeat('x',1000001)] as $case => $bytes) {
    file_put_contents($mediaReceipt,$bytes);clearstatcache(true,$mediaReceipt);
    $row = $readback();
    editabilityCheck($row['verified'] && $row['checks'] === $goodRow['checks']
        && $row['candidate_assessment']['state'] === 'operation_requires_readback'
        && $row['candidate_assessment']['prior_media_operation'] ===
            ['state'=>'unavailable','reason'=>'media_operation_read_unavailable'],
        'optional '.$case.' journal changes annotation only');
}
// A malformed optional journal must restore the caller's error handler even on exception.
$callerNotices = 0;
set_error_handler(static function (int $severity, string $ignored) use (&$callerNotices): bool {
    $callerNotices++;
    return true;
});
try {
    file_put_contents($mediaReceipt,'{malformed optional journal');clearstatcache(true,$mediaReceipt);
    $restorationRow = $readback();
    trigger_error('Synthetic caller handler probe',E_USER_NOTICE);
    editabilityCheck($callerNotices === 1 && $restorationRow['verified'],
        'optional journal exception restores the caller handler and preserves readback');
    file_put_contents($mediaReceipt,implode("\n",array_map('jsonRead',editabilityRawJournal(true)))."\n");
    clearstatcache(true,$mediaReceipt);
    $restorationRow = $readback();
    trigger_error('Synthetic caller handler probe',E_USER_NOTICE);
    editabilityCheck($callerNotices === 2 && $restorationRow['verified'],
        'successful optional journal observation restores the caller handler');
} finally {
    restore_error_handler();
}
file_put_contents($mediaReceipt,implode("\n",array_map('jsonRead',editabilityRawJournal(true)))."\n");
$otherDirectory = OPERATIONS.'/synthetic-unfollowed-operation';
rename($mediaDirectory,$otherDirectory);symlink($otherDirectory,$mediaDirectory);clearstatcache();
$symlinkRow = $readback();
editabilityCheck($symlinkRow['verified'] && $symlinkRow['candidate_assessment']['state'] === 'operation_requires_readback'
    && $symlinkRow['candidate_assessment']['prior_media_operation'] ===
        ['state'=>'unavailable','reason'=>'operation_directory_symlink'],
    'operation directory symlink is not followed and does not suppress accepted readback');
unlink($mediaDirectory);mkdir($mediaDirectory,0700);symlink($otherDirectory.'/receipt.jsonl',$mediaReceipt);clearstatcache();
$symlinkRow = $readback();
editabilityCheck($symlinkRow['verified'] && $symlinkRow['candidate_assessment']['state'] === 'operation_requires_readback'
    && $symlinkRow['candidate_assessment']['prior_media_operation'] ===
        ['state'=>'unavailable','reason'=>'operation_receipt_symlink'],
    'receipt symlink is not followed and does not suppress accepted readback');
unlink($mediaReceipt);clearstatcache();
$emptyDirectoryRow = $readback();
editabilityCheck($emptyDirectoryRow['verified'] && $emptyDirectoryRow['candidate_assessment']['state'] === 'operation_requires_readback'
    && $emptyDirectoryRow['candidate_assessment']['prior_media_operation'] === ['state'=>'absent']
    && $emptyDirectoryRow['candidate_assessment']['prior_media_operation_directory_exists'] === true,
    'existing operation directory without receipt stays held while readback runs');
rmdir($mediaDirectory);clearstatcache();
foreach (['../escape','0','09000001'] as $invalidMid) {
    $assessment = telegramAcceptedCandidateAssessmentRead($invalidMid,null,[]);
    editabilityCheck($assessment['state'] === 'operation_requires_readback'
        && $assessment['prior_media_operation_directory_exists'] === null
        && $assessment['prior_media_operation'] === ['state'=>'unavailable','reason'=>'invalid_target_message_id'],
        'invalid MID is rejected before constructing an operation path: '.$invalidMid);
}
editabilityCheck($assessment['evidence_tier'] === 'unavailable'
    && $assessment['reasons'] === ['accepted_destination_scope','media_operation_evidence_unavailable'],
    'invalid MID without filesystem observation does not invent a prior media operation');
$nativeWithDate = publicationRead($readDb,$readRegistry,6,$sqlCases['sent']);
editabilityCheck(is_string($nativeWithDate['published_at']??null) && strtotime($nativeWithDate['published_at']) === 1609459200
    && $nativeWithDate['telegram_message']['date'] === 1609459200,
    'actual readonly SQL retains upstream published_at alongside native evidence');
editabilityCheck((int)$readDb->query('SELECT total_changes()')->fetchColumn() === $changesBefore
    && editabilityDatabaseSnapshot($readDb) === $databaseBefore,
    'accepted assessment and readonly SQL projection leave the fixture database unchanged');

echo 'OK ' . $checks . ' destination-editability checks; historical 33 before/15 rejected/18 accepted; '
    . "synthetic native/journal/SQL cases; provider calls 0\n";
