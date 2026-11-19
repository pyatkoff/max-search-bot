<?php
declare(strict_types=1);
function monitor_hook_telegram_redirect(int $hotelId): ?array {
    $redirects = [
        174 => ['https://t.me/any_toour/67', 'https://t.me/anytour/1792'],
        12107 => ['https://t.me/any_toour/556', 'https://t.me/anytour/809'],
        132 => ['https://t.me/any_toour/78', 'https://t.me/anytour/17'],
        469 => ['https://t.me/any_toour/12', 'https://t.me/anytour/19'],
        12111 => ['https://t.me/any_toour/24', 'https://t.me/anytour/23'],
        70048 => ['https://t.me/any_toour/523', 'https://t.me/anytour/37'],
        82606 => ['https://t.me/any_toour/248', 'https://t.me/anytour/48'],
        1344 => ['https://t.me/any_toour/411', 'https://t.me/anytour/82'],
        260 => ['https://t.me/any_toour/118', 'https://t.me/anytour/192'],
        415 => ['https://t.me/any_toour/472', 'https://t.me/anytour/1754'],
        2493 => ['https://t.me/any_toour/661', 'https://t.me/anytour/196'],
        3452 => ['https://t.me/any_toour/220', 'https://t.me/anytour/1148'],
        4181 => ['https://t.me/any_toour/555', 'https://t.me/anytour/204'],
        37668 => ['https://t.me/any_toour/66', 'https://t.me/anytour/402'],
        43676 => ['https://t.me/any_toour/642', 'https://t.me/anytour/414'],
        64895 => ['https://t.me/any_toour/49', 'https://t.me/anytour/993'],
        73974 => ['https://t.me/any_toour/207', 'https://t.me/anytour/455'],
        74503 => ['https://t.me/any_toour/146', 'https://t.me/anytour/474'],
        99713 => ['https://t.me/any_toour/641', 'https://t.me/anytour/483'],
    ];
    return $redirects[$hotelId] ?? null;
}

/** Keep the receipt's native post and substitute the destination of its existing ad offer. */
function monitor_hook_apply_telegram_redirect(array $row): array {
    $hotelId = $row['tourvisor_hotel_id'];
    $redirect = monitor_hook_telegram_redirect($hotelId);
    if ($redirect === null) {
        return $row;
    }
    [$oldUrl, $newUrl] = $redirect;
    monitor_price_require(($row['posts']['telegram'] ?? null) === $oldUrl
        && ($row['feed_binding']['posts']['telegram'] ?? null) === $oldUrl
        && ($row['feed_binding']['offer_ids']['telegram'] ?? null) === 'tg_'.$hotelId
        && ($row['feed_binding']['destinations']['telegram'] ?? null) === $oldUrl,
        'hook_telegram_redirect_source_changed');
    $row['feed_binding']['destinations']['telegram'] = $newUrl;
    return $row;
}

/** Read the selected offer only when there is exactly one matching offer and URL. */
function monitor_hook_telegram_offer_url(string $xml, string $offerId): ?string {
    if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
        return null;
    }
    $doc = new DOMDocument();
    $prior = libxml_use_internal_errors(true);
    try {
        $valid = $doc->loadXML($xml, LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($prior);
    }
    if (!$valid || $doc->documentElement?->tagName !== 'yml_catalog') {
        return null;
    }
    $match = null;
    foreach ($doc->getElementsByTagName('offer') as $offer) {
        if ($offer->getAttribute('id') === $offerId) {
            if ($match !== null) {
                return null;
            }
            $match = $offer;
        }
    }
    $urls = $match?->getElementsByTagName('url');
    return $urls !== null && $urls->length === 1 ? $urls->item(0)->textContent : null;
}

/** A collector release can continue to price the old destination until both feeds switch. */
function monitor_hook_select_telegram_redirect(array $row, ?string $telegramUrl, ?string $combinedUrl): array {
    $next = monitor_hook_apply_telegram_redirect($row);
    $oldUrl = $row['feed_binding']['destinations']['telegram'];
    $newUrl = $next['feed_binding']['destinations']['telegram'];
    monitor_price_require($telegramUrl === $combinedUrl && ($telegramUrl === $oldUrl || $telegramUrl === $newUrl),
        'hook_telegram_redirect_feed_not_ready');
    return $telegramUrl === $oldUrl ? $row : $next;
}

/** Admit the old destination only when checking an existing completed caption receipt. */
function monitor_hook_receipt_feed_binding_matches(mixed $previous, array $row): bool {
    $binding = $row['feed_binding'];
    if ($previous === $binding) {
        return true;
    }
    $redirect = monitor_hook_telegram_redirect($row['tourvisor_hotel_id']);
    $current = $binding['destinations']['telegram'] ?? null;
    if ($redirect === null || !in_array($current, $redirect, true)
        || ($binding['offer_ids']['telegram'] ?? null) !== 'tg_'.$row['tourvisor_hotel_id']
        || ($binding['posts']['telegram'] ?? null) !== $redirect[0]) {
        return false;
    }
    $binding['destinations']['telegram'] = $current === $redirect[0] ? $redirect[1] : $redirect[0];
    return $previous === $binding;
}

/** Adapt only the receipt copy. Use the original caption and identity checks unchanged. */
function monitor_hook_telegram_caption_allowed(array $publication, array $row, string $platform, ?array $prior): bool {
    if ($prior !== null && monitor_hook_receipt_feed_binding_matches($prior['feed_binding'] ?? null, $row)) {
        $prior['feed_binding'] = $row['feed_binding'];
    }
    return monitor_hook_caption_allowed($publication, $row, $platform, $prior);
}

