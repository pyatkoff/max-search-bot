<?php
declare(strict_types=1);

/**
 * Offline fixture. Evaluate only the two pure heading functions extracted from
 * the draft. Never include or execute the publisher's try block. All canonical
 * ButtonTransport calls use the explicit in-memory request injection below.
 */
function insist(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }

$source = file_get_contents(__DIR__.'/commercial-batch-template-20261102.php');
$start = strpos($source, 'function commercialTelegramCaptionEntities(');
$end = strpos($source, 'function safeReceipt(', $start === false ? 0 : $start);
check($start !== false && $end !== false && $end > $start, 'pure_heading_helpers_not_found');
eval(substr($source, $start, $end - $start));
require_once __DIR__.'/current-source/integrations/ChannelPostIncomingAdapter.php';
require_once __DIR__.'/current-source/services/ChannelPostButtonTransport.php';

$checks = 0;
foreach ([
    ["✈️ Туры в A", 11],
    ["✈️ Туры в 🏝️", 13],
    ["✈️ Туры в 👩‍👩‍👧‍👦", 21],
    ["✈️ Туры в e\u{0301}", 12],
] as [$title, $length]) {
    $entities = commercialTelegramCaptionEntities($title."\n📍 Пхукет, Таиланд.\n\nТекст.");
    check($entities === [['type'=>'bold', 'offset'=>0, 'length'=>$length]], 'utf16_title_entity_mismatch');
    $checks++;
}
try {
    commercialTelegramCaptionEntities("\nТекст.");
    throw new LogicException('empty_title_accepted');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'telegram_heading_invalid', 'wrong_empty_title_failure');
    $checks++;
}
$caption = "✈️ Туры в X-Hotel 🏝️ & Spa\n📍 Пхукет, Таиланд.\n\nКонтролируемый текст.";
$expected = commercialTelegramCaptionEntities($caption);
$reordered = [['length'=>$expected[0]['length'], 'offset'=>0, 'type'=>'bold']];
check(commercialTelegramCaptionEntitiesMatch($reordered, $expected), 'object_key_order_must_not_change_meaning');
$checks++;
foreach ([
    null, [], [['type'=>'italic', 'offset'=>0, 'length'=>$expected[0]['length']]],
    [['type'=>'bold', 'offset'=>0.0, 'length'=>$expected[0]['length']]],
    [['type'=>'bold', 'offset'=>0, 'length'=>(float)$expected[0]['length']]],
    [['type'=>'bold', 'offset'=>0, 'length'=>$expected[0]['length']+1]],
    [['type'=>'bold', 'offset'=>0, 'length'=>$expected[0]['length'], 'url'=>'https://example.org/']],
    [$expected[0], $expected[0]],
] as $bad) {
    check(!commercialTelegramCaptionEntitiesMatch($bad, $expected), 'changed_or_ambiguous_entity_accepted');
    $checks++;
}

$message = [
    'message_id'=>123, 'date'=>1700000000,
    'chat'=>['id'=>-1002262666616, 'username'=>'any_toour', 'type'=>'channel'],
    'caption'=>$caption, 'caption_entities'=>$reordered,
    'photo'=>[['file_id'=>'fixture-file', 'file_unique_id'=>'fixture-stable', 'width'=>1200, 'height'=>900]],
];
$snapshot = ChannelPostIncomingAdapter::telegramSnapshot($message);
check(is_array($snapshot) && $snapshot['text'] === $caption && $snapshot['markup'] === $reordered, 'adapter_must_preserve_heading');
$without = $message; unset($without['caption_entities']);
check(ChannelPostIncomingAdapter::contentFingerprint($snapshot) !==
    ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::telegramSnapshot($without)),
    'fingerprint_must_bind_caption_entities');
$checks += 2;

$channel = [
    'platform'=>'telegram', 'channel_id'=>'-1002262666616', 'bot_key'=>'anyru_bot',
    'canonical_bot_username'=>'anyru_bot', 'bot_url'=>'https://t.me/anyru_bot',
    'is_enabled'=>true, 'post_mode'=>'append_button', 'button_text'=>'✈️ Узнать стоимость тура',
];
$operation = ['platform'=>'telegram', 'channel'=>$channel, 'external_message_id'=>'123',
    'snapshot'=>$snapshot, 'start_payload'=>'p_fixturetoken123'];

foreach (['preserved', 'format_removed', 'format_changed', 'unknown_response', 'reconcile_only', 'claim_refused'] as $scenario) {
    $calls = []; $claims = 0;
    $wire = static function(string $platform, string $method, string $path, array $query, ?array $body)
        use (&$calls, $message, $scenario): array {
        $calls[] = $path;
        check($platform === 'telegram' && $method === 'POST' && $query === [], 'unexpected_fixture_request');
        if ($path === 'getMe') $value = ['id'=>777, 'is_bot'=>true, 'username'=>'anyru_bot'];
        elseif ($path === 'getChatMember') $value = ['user'=>['id'=>777, 'is_bot'=>true, 'username'=>'anyru_bot'],
            'status'=>'administrator', 'can_edit_messages'=>true];
        elseif ($path === 'editMessageReplyMarkup') {
            check(array_keys($body) === ['chat_id', 'message_id', 'reply_markup'], 'button_must_edit_only_keyboard');
            if ($scenario === 'unknown_response') return ['http'=>0, 'transport_error'=>true, 'data'=>null];
            $value = $message;
            $value['reply_markup'] = $body['reply_markup'];
            if ($scenario === 'format_removed') unset($value['caption_entities']);
            if ($scenario === 'format_changed') $value['caption_entities'][0]['length']++;
        } else throw new RuntimeException('unexpected_provider_path');
        return ['http'=>200, 'transport_error'=>false, 'data'=>['ok'=>true, 'result'=>$value]];
    };
    $transport = new ChannelPostButtonTransport($wire);
    $before = static function() use (&$claims, $scenario): bool { $claims++; return $scenario !== 'claim_refused'; };
    $result = $transport->attempt($operation, $before, $scenario === 'reconcile_only');
    $writeCalls = count(array_filter($calls, static fn(string $path): bool => $path === 'editMessageReplyMarkup'));
    if ($scenario === 'preserved') {
        check($result['state'] === 'sent' && $result['receipt']['content_preserved'] === true
            && $result['receipt']['button_verified'] === true && $writeCalls === 1 && $claims === 1,
            'preserved_heading_button_not_confirmed');
    } elseif ($scenario === 'reconcile_only' || $scenario === 'claim_refused') {
        check($result['state'] !== 'sent' && $writeCalls === 0
            && $result['receipt']['write_attempts'] === 0, 'reconciliation_or_claim_repeated_write');
    } else {
        check($result['state'] === 'unknown' && $writeCalls === 1
            && $result['receipt']['write_attempts'] === 1, 'unknown_or_changed_format_must_stop_without_retry');
    }
    check(count($calls) <= 3, 'unexpected_extra_request');
    $checks++;
}

echo json_encode(['ok'=>true, 'checks'=>$checks, 'provider_calls'=>0, 'db_writes'=>0,
    'publisher_executed'=>false, 'mode'=>'canonical_injected_transport_heading_fixture'],
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), PHP_EOL;
