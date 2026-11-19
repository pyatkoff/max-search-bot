<?php
declare(strict_types=1);

const ANYTOUR_TELEGRAM_FEED_LIMIT = 4000000;

function anytourTelegramFeedOfferUrls(string $xml): array
{
    if ($xml === '' || strlen($xml) > ANYTOUR_TELEGRAM_FEED_LIMIT || preg_match('~<!\s*(?:DOCTYPE|ENTITY)\b~i', $xml)) {
        throw new RuntimeException('Invalid feed input');
    }

    $document = new DOMDocument();
    $errorsEnabled = libxml_use_internal_errors(true);
    try {
        if (!$document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
            throw new RuntimeException('Invalid feed XML');
        }
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($errorsEnabled);
    }

    $xpath = new DOMXPath($document);
    $containers = $xpath->query('/yml_catalog/shop/offers');
    $offers = $xpath->query('/yml_catalog/shop/offers/offer');
    if ($containers->length !== 1 || $offers->length < 1
        || $offers->length !== $document->getElementsByTagName('offer')->length) {
        throw new RuntimeException('Unexpected feed structure');
    }

    $urls = [];
    foreach ($offers as $offer) {
        $id = $offer->getAttribute('id');
        $nodes = $xpath->query('./url', $offer);
        if (!preg_match('/\Atg_[1-9][0-9]*\z/D', $id) || isset($urls[$id]) || $nodes->length !== 1) {
            throw new RuntimeException('Invalid offer or URL');
        }
        $url = $nodes->item(0);
        foreach ($url->childNodes as $child) {
            if ($child->nodeType !== XML_TEXT_NODE) {
                throw new RuntimeException('Unexpected URL content');
            }
        }
        $urls[$id] = $url->textContent;
    }
    return $urls;
}

/** Project the new destinations into the current source snapshot, preserving all other bytes. */
function anytourTelegramFeedProject(string $xml): string
{
    $destinations = [
        'tg_174' => ['https://t.me/any_toour/67', 'https://t.me/anytour/1792'],
        'tg_12107' => ['https://t.me/any_toour/556', 'https://t.me/anytour/809'],
        'tg_132' => ['https://t.me/any_toour/78', 'https://t.me/anytour/17'],
        'tg_469' => ['https://t.me/any_toour/12', 'https://t.me/anytour/19'],
        'tg_12111' => ['https://t.me/any_toour/24', 'https://t.me/anytour/23'],
        'tg_70048' => ['https://t.me/any_toour/523', 'https://t.me/anytour/37'],
        'tg_82606' => ['https://t.me/any_toour/248', 'https://t.me/anytour/48'],
        'tg_1344' => ['https://t.me/any_toour/411', 'https://t.me/anytour/82'],
        'tg_260' => ['https://t.me/any_toour/118', 'https://t.me/anytour/192'],
        'tg_415' => ['https://t.me/any_toour/472', 'https://t.me/anytour/1754'],
        'tg_2493' => ['https://t.me/any_toour/661', 'https://t.me/anytour/196'],
        'tg_3452' => ['https://t.me/any_toour/220', 'https://t.me/anytour/1148'],
        'tg_4181' => ['https://t.me/any_toour/555', 'https://t.me/anytour/204'],
        'tg_37668' => ['https://t.me/any_toour/66', 'https://t.me/anytour/402'],
        'tg_43676' => ['https://t.me/any_toour/642', 'https://t.me/anytour/414'],
        'tg_64895' => ['https://t.me/any_toour/49', 'https://t.me/anytour/993'],
        'tg_73974' => ['https://t.me/any_toour/207', 'https://t.me/anytour/455'],
        'tg_74503' => ['https://t.me/any_toour/146', 'https://t.me/anytour/474'],
        'tg_99713' => ['https://t.me/any_toour/641', 'https://t.me/anytour/483'],
    ];
    $current = anytourTelegramFeedOfferUrls($xml);
    $counts = array_count_values($current);
    $expected = $current;

    foreach ($destinations as $id => [$old, $new]) {
        $url = $current[$id] ?? null;
        if ($url === $new && ($counts[$new] ?? 0) === 1) {
            continue;
        }
        if ($url !== $old || ($counts[$old] ?? 0) !== 1 || isset($counts[$new])) {
            throw new RuntimeException('Unexpected Telegram offer destination');
        }
        $oldTag = '<url>'.$old.'</url>';
        if (substr_count($xml, $oldTag) !== 1) {
            throw new RuntimeException('Telegram URL element is not unique');
        }
        $xml = str_replace($oldTag, '<url>'.$new.'</url>', $xml);
        $expected[$id] = $new;
    }

    if (anytourTelegramFeedOfferUrls($xml) !== $expected) {
        throw new RuntimeException('Projected URL verification failed');
    }
    return $xml;
}

function anytourTelegramFeedRead(): string
{
    $snapshot = @file_get_contents(__DIR__.'/feed/hotel-posts-telegram.xml', false, null, 0,
        ANYTOUR_TELEGRAM_FEED_LIMIT + 1);
    if (!is_string($snapshot) || $snapshot === '') {
        throw new RuntimeException('Cannot read feed source');
    }
    return $snapshot;
}

if (PHP_SAPI === 'cli') {
    return;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    return;
}

try {
    $xml = anytourTelegramFeedProject(anytourTelegramFeedRead());
} catch (Throwable $error) {
    error_log('AnyTour Telegram feed: '.$error::class.' '.$error->getMessage());
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Retry-After: 300');
    return;
}

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=60');
header('X-Content-Type-Options: nosniff');
if ($method === 'GET') {
    echo $xml;
}
