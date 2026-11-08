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
// Official MAX fixture: 😀АБ with From=2, Length=1 formats only А.
// A preceding shortened heading shifts that native offset from 20 to 8.
$maxAfterEmoji = shortTitleProviderText('max', "Hotel с перелётом\n😀АБ", "Hotel\n😀АБ",
    [['type'=>'strong', 'from'=>20, 'length'=>1]]);
markupAssert($maxAfterEmoji['markup'] === [['type'=>'strong', 'from'=>8, 'length'=>1]]
    && $maxAfterEmoji['max_html'] === "Hotel\n😀<b>А</b>Б", 'max_utf16_body_span_shift_changed');
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

// Exact public caption and spans from the read-only Susesi probe, run 42439607760.
$susesiFixtureJson = <<<'SUSESI_NATIVE_UTF16'
{
  "probe_run_id": 42439607760,
  "publication_id": 432,
  "version_id": 668,
  "text": "✈️ Туры в Susesi Luxury Resort с перелётом\n📍 Белек, Турция.\n\n🏖 Пляж и бассейны на территории.\n🧖 Спа La Calisse.\n🍽 Рестораны с кухнями разных стран.\n🛏 Есть номера Deluxe и семейные категории.\n\n💡 Собственный бассейн и услуги дворецкого предусмотрены в отдельных особых категориях размещения.\n\n\n✈️ Вылет: Москва\n📅 09.11.2026 по 15.11.2026, 6 ночей\n👥 2 взрослых\n🛏 Номер: deluxe land view\n🍽 Питание: UAI\n💰 222 267 ₽ за тур\n🕒 Проверено 05.11.2026 06:35 UTC\n\n🔎 Подобрать тур:\nhttps://anytour.online/poisk-turov/?days_from=6&days_till=6&from=1&count_people=2&country=4&hotel=1543&date_from=2026-11-09&date_till=2026-11-09\n\n✈️ Нажмите кнопку, чтобы узнать стоимость тура в Susesi Luxury Resort.",
  "text_sha256": "7bd4c85d689770f0c9f6a5875112b5e4ad014d10436a757eb428c3999a01df36",
  "native_markup": [
    {
      "from": 0,
      "length": 42,
      "type": "strong"
    },
    {
      "url": "https://anytour.online/poisk-turov/?days_from=6&days_till=6&from=1&count_people=2&country=4&hotel=1543&date_from=2026-11-09&date_till=2026-11-09",
      "from": 482,
      "length": 144,
      "type": "link"
    }
  ],
  "telegram_markup": [
    {
      "offset": 482,
      "length": 144,
      "type": "url"
    }
  ],
  "expected_new_text_sha256": "baea794371d21a2a9ca376277fa259d7f2e5cb1e5d2f1f4e3535dad0065b72fb",
  "expected_max_html_sha256": "fc593c823f0941a52f2cd6c43dfaeb728e2f5c7b633e11394cec9fc37cc11c93"
}
SUSESI_NATIVE_UTF16;
$susesiFixture = json_decode($susesiFixtureJson, true, 16, JSON_THROW_ON_ERROR);
$susesiOld = $susesiFixture['text'];
markupAssert(hash('sha256', $susesiOld) === '7bd4c85d689770f0c9f6a5875112b5e4ad014d10436a757eb428c3999a01df36',
    'susesi_original_fixture_changed');
$susesiNew = '✈️ Туры в Susesi Luxury Resort' . substr($susesiOld, strpos($susesiOld, "\n"));
markupAssert(hash('sha256', $susesiNew) === $susesiFixture['expected_new_text_sha256'], 'susesi_body_bytes_changed');
$susesiMax = shortTitleProviderText('max', $susesiOld, $susesiNew, $susesiFixture['native_markup']);
$susesiLink = $susesiFixture['native_markup'][1]['url'];
markupAssert($susesiMax['markup'] === [['from'=>0, 'length'=>30, 'type'=>'strong'],
    ['url'=>$susesiLink, 'from'=>470, 'length'=>144, 'type'=>'link']], 'susesi_native_utf16_spans_changed');
markupAssert(hash('sha256', $susesiMax['max_html']) === $susesiFixture['expected_max_html_sha256'],
    'susesi_exact_formatted_caption_changed');
markupAssert(shortTitleUtf16Slice(mb_convert_encoding($susesiOld, 'UTF-16LE', 'UTF-8'), 482, 144) === $susesiLink
    && shortTitleUtf16Slice(mb_convert_encoding($susesiNew, 'UTF-16LE', 'UTF-8'), 470, 144) === $susesiLink,
    'susesi_native_link_substring_changed');
$susesiTg = shortTitleProviderText('telegram', $susesiOld, $susesiNew, $susesiFixture['telegram_markup']);
markupAssert($susesiTg['markup'] === [['offset'=>470, 'length'=>144, 'type'=>'url']]
    && $susesiTg['max_html'] === null, 'susesi_telegram_native_entities_changed');

echo json_encode(['ok'=>true, 'checks'=>$checks, 'provider_calls'=>0, 'post_write_attempts'=>0],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
