<?php
declare(strict_types=1);

// Pure local checks: require only the formatting helper, never the executor.
function need(bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); }
require_once __DIR__ . '/title-only-markup-functions.php';

$checks = 0;
function markupAssert(bool $ok, string $reason): void
{
    global $checks;
    need($ok, $reason);
    $checks++;
}
function markupReject(callable $run, string $expectedReason): void
{
    try { $run(); }
    catch (RuntimeException $error) {
        markupAssert($error->getMessage() === $expectedReason, 'unexpected_rejection_reason');
        return;
    }
    throw new RuntimeException('unsafe_markup_accepted');
}

$url = 'https://x.test/?a=1&b=2';
$old = "Hotel с перелётом\n" . $url;
$new = "Hotel\n" . $url;
$spans = [['type'=>'strong', 'from'=>0, 'length'=>17],
    ['type'=>'link', 'from'=>18, 'length'=>23, 'url'=>$url]];
$result = shortTitleProviderText('max', $old, $new, $spans);
markupAssert($result['markup'] === [['type'=>'strong', 'from'=>0, 'length'=>5],
    ['type'=>'link', 'from'=>6, 'length'=>23, 'url'=>$url]], 'header_or_body_link_range_changed');
markupAssert($result['max_html'] === '<b>Hotel</b>' . "\n"
    . '<a href="https://x.test/?a=1&amp;b=2">https://x.test/?a=1&amp;b=2</a>', 'max_html_not_preserved');

// U+1F600 occupies two UTF-16 units; the combining accent is a separate unit.
$oldEmoji = "😀e\u{0301} с перелётом\nСайт";
$newEmoji = "😀e\u{0301}\nСайт";
$tgSpans = [['type'=>'bold', 'offset'=>0, 'length'=>16],
    ['type'=>'text_link', 'offset'=>17, 'length'=>4, 'url'=>'https://x.test/signed?a=1&b=2']];
$tgExpected = [['type'=>'bold', 'offset'=>0, 'length'=>4],
    ['type'=>'text_link', 'offset'=>5, 'length'=>4, 'url'=>'https://x.test/signed?a=1&b=2']];
$tg = shortTitleProviderText('telegram', $oldEmoji, $newEmoji, $tgSpans);
markupAssert($tg['markup'] === $tgExpected && $tg['max_html'] === null, 'telegram_entity_or_utf16_shift_changed');
markupReject(static fn() => shortTitleProviderText('max', $oldEmoji, $newEmoji,
    [['type'=>'strong', 'from'=>0, 'length'=>16]]), 'max_markup_unicode_units_unverified');
$bodyEmoji = shortTitleProviderText('max', "Hotel с перелётом\n😀 Body", "Hotel\n😀 Body",
    [['type'=>'strong', 'from'=>0, 'length'=>17]]);
markupAssert($bodyEmoji['max_html'] === "<b>Hotel</b>\n😀 Body", 'max_unformatted_body_emoji_changed');

$cross = shortTitleProviderText('telegram', "Hotel с перелётом\nBody", "Hotel\nBody",
    [['type'=>'italic', 'offset'=>2, 'length'=>20]]);
markupAssert($cross['markup'] === [['type'=>'italic', 'offset'=>2, 'length'=>8]], 'straddling_entity_changed');
$removed = shortTitleProviderText('telegram', "Hotel с перелётом\nBody", "Hotel\nBody",
    [['type'=>'bold', 'offset'=>5, 'length'=>12]]);
markupAssert($removed['markup'] === [], 'deleted_only_entity_retained');

$plain = shortTitleProviderText('max', "<A & B> с перелетом\r\nBody", "<A & B>\r\nBody", []);
markupAssert($plain['max_html'] === "&lt;A &amp; B&gt;\r\nBody" && $plain['markup'] === [],
    'plain_text_or_line_endings_changed');
markupAssert(shortTitleMarkupEqual($tgExpected, [
    ['url'=>'https://x.test/signed?a=1&b=2', 'length'=>4, 'offset'=>5, 'type'=>'text_link'],
    ['length'=>4, 'type'=>'bold', 'offset'=>0],
]), 'semantic_markup_order_not_equal');
$changedLink = $tgExpected;
$changedLink[1]['url'] = 'https://x.test/different';
markupAssert(!shortTitleMarkupEqual($tgExpected, $changedLink), 'changed_link_accepted');

markupReject(static fn() => shortTitleProviderText('max', $old, $new,
    [['type'=>'strong', 'from'=>0, 'length'=>17], ['type'=>'emphasized', 'from'=>1, 'length'=>3]]),
    'max_overlapping_markup_unsupported');
markupReject(static fn() => shortTitleProviderText('telegram', $oldEmoji, $newEmoji,
    [['type'=>'bold', 'offset'=>1, 'length'=>1]]), 'markup_splits_surrogate');
markupReject(static fn() => shortTitleProviderText('telegram', $oldEmoji, $newEmoji,
    [['type'=>'bold', 'offset'=>0, 'length'=>1]]), 'markup_splits_surrogate');
markupReject(static fn() => shortTitleProviderText('max', $old, $new,
    [['type'=>'quote', 'from'=>0, 'length'=>5]]), 'max_markup_type_unsupported');
markupReject(static fn() => shortTitleProviderText('telegram', $old, "Hotel\nChanged", []),
    'only_first_line_suffix_removal_allowed');

echo json_encode(['ok'=>true, 'checks'=>$checks, 'provider_calls'=>0, 'post_write_attempts'=>0],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
