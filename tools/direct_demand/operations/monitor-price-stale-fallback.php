<?php
declare(strict_types=1);

/** Pure fail-closed preparation only. No publication, feed write, DB or supplier transport. */
function monitor_stale_need(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

/** Preserve the exact old caption outside the one original monitored-price block. */
function monitor_stale_caption_plan(string $currentText, string $expectedTextSha256, int $now): array
{
    monitor_stale_need(preg_match('/\A[a-f0-9]{64}\z/D', $expectedTextSha256) === 1
        && hash_equals($expectedTextSha256, hash('sha256', $currentText)), 'stale_caption_cas_mismatch');
    monitor_stale_need(substr_count($currentText, '💰 От ') === 1
        && substr_count($currentText, 'Цена найдена мониторингом ') === 1, 'stale_caption_shape_changed');
    $pattern = '/^💰 От [1-9][0-9 ]*(?:,[0-9]{1,2})? ₽ за двоих\n'
        .'✈️ Вылет из Москвы [0-3][0-9]\.[01][0-9]\.[0-9]{4} · (?:[5-9]|1[0-4]) ноч(?:ь|и|ей)\n'
        .'👥 2 взрослых\n🛏 [^\n]{1,200}\n🍽 [^\n]{1,100}\n'
        .'Цена найдена мониторингом ([0-3][0-9]\.[01][0-9]\.[0-9]{4}, [0-2][0-9]:[0-5][0-9]) МСК\. Стоимость и наличие могут измениться\.$/mu';
    $count = preg_match_all($pattern, $currentText, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    monitor_stale_need($count === 1, 'stale_caption_block_not_exact');
    $stamp = $matches[0][1][0];
    $observed = DateTimeImmutable::createFromFormat('!d.m.Y, H:i', $stamp, new DateTimeZone('Europe/Moscow'));
    monitor_stale_need($observed instanceof DateTimeImmutable && $observed->format('d.m.Y, H:i') === $stamp,
        'stale_caption_timestamp_invalid');
    $age = $now - $observed->getTimestamp();
    monitor_stale_need($age >= 0, 'stale_caption_future_observation');
    if ($age < 36 * 3600) return [
        'state' => 'not_expired', 'age_seconds' => $age, 'observed_at' => $observed->format(DateTimeInterface::ATOM),
    ];
    $oldBlock = $matches[0][0][0];
    $position = $matches[0][0][1];
    $newText = substr_replace($currentText, '💰 Стоимость тура — по запросу', $position, strlen($oldBlock));
    monitor_stale_need(!str_contains($newText, 'Цена найдена мониторингом ')
        && !str_contains($newText, '💰 От '), 'stale_caption_price_remains');
    return [
        'state' => 'ready', 'age_seconds' => $age, 'observed_at' => $observed->format(DateTimeInterface::ATOM),
        'old_text_sha256' => $expectedTextSha256, 'new_text' => $newText,
        'new_text_sha256' => hash('sha256', $newText),
    ];
}

/** Prepare one exact YML offer for the existing feed writer; never write the returned XML directly. */
function monitor_stale_feed_plan(string $originalXml, string $expectedXmlSha256, string $offerId,
    string $expectedUrl, string $oldText, array $captionPlan, int $now): array
{
    monitor_stale_need(($captionPlan['state'] ?? null) === 'ready'
        && ($captionPlan['old_text_sha256'] ?? null) === hash('sha256', $oldText)
        && ($captionPlan['new_text_sha256'] ?? null) === hash('sha256', (string)($captionPlan['new_text'] ?? '')),
        'stale_feed_caption_unconfirmed');
    monitor_stale_need(preg_match('/\A[a-f0-9]{64}\z/D', $expectedXmlSha256) === 1
        && hash_equals($expectedXmlSha256, hash('sha256', $originalXml)), 'stale_feed_cas_mismatch');
    monitor_stale_need(strlen($originalXml) <= 4000000
        && !preg_match('/<!DOCTYPE|<!ENTITY|available=/i', $originalXml)
        && preg_match('/<yml_catalog date="[^"<>]+">/', $originalXml) === 1,
        'stale_feed_format_changed');
    monitor_stale_need(preg_match('/\A(?:tg|max)_[A-Za-z0-9_-]+\z/D', $offerId) === 1
        && preg_match('~\Ahttps://(?:t\.me|max\.ru)/~', $expectedUrl) === 1,
        'stale_feed_identity_invalid');
    $pattern = '/<offer id="'.preg_quote($offerId, '/').'">.*?<\/offer>/s';
    monitor_stale_need(preg_match_all($pattern, $originalXml, $hits, PREG_OFFSET_CAPTURE) === 1,
        'stale_feed_offer_not_unique');
    $originalBlock = $hits[0][0][0];
    $position = $hits[0][0][1];
    foreach (['url', 'description', 'price', 'currencyId'] as $tag) {
        monitor_stale_need(preg_match_all('~<'.$tag.'>(.*?)</'.$tag.'>~s', $originalBlock, $part) === 1,
            'stale_feed_fields_changed');
        $fields[$tag] = html_entity_decode($part[1][0], ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
    monitor_stale_need($fields['url'] === $expectedUrl && $fields['description'] === $oldText
        && $fields['currencyId'] === 'RUB' && preg_match('/\A[1-9][0-9]*(?:\.[0-9]{1,2})?\z/D', $fields['price']) === 1,
        'stale_feed_current_offer_mismatch');
    $removed = 0;
    $block = preg_replace('~\s*<(price|currencyId|description)>.*?</\1>~s', '', $originalBlock, -1, $removed);
    monitor_stale_need(is_string($block) && $removed === 3 && substr_count($block, '</url>') === 1,
        'stale_feed_patch_invalid');
    $newBlock = str_replace('</url>', '</url>' . "\n        <description>"
        . htmlspecialchars($captionPlan['new_text'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</description>', $block);
    $newXml = substr_replace($originalXml, $newBlock, $position, strlen($originalBlock));
    $date = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i');
    $newXml = preg_replace('/<yml_catalog date="[^"<>]+">/', '<yml_catalog date="' . $date . '">',
        $newXml, 1, $datesChanged);
    monitor_stale_need(is_string($newXml) && $datesChanged === 1, 'stale_feed_date_invalid');
    return [
        'state' => 'ready', 'offer_id' => $offerId, 'old_xml_sha256' => $expectedXmlSha256,
        'new_xml_sha256' => hash('sha256', $newXml), 'new_xml' => $newXml,
    ];
}
