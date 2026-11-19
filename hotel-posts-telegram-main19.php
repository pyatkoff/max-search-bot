<?php
declare(strict_types=1);

const ANYTOUR_TELEGRAM_FEED_LIMIT = 4000000;
const ANYTOUR_TELEGRAM_OFFER_ID = 'tg_[1-9][0-9]{0,11}';

function anytourTelegramFeedOfferUrls(string $xml, int $limit = ANYTOUR_TELEGRAM_FEED_LIMIT): array
{
    if ($xml === '' || strlen($xml) > $limit || preg_match('~<!\s*(?:DOCTYPE|ENTITY)\b~i', $xml)) {
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
        if (!preg_match('/\A'.ANYTOUR_TELEGRAM_OFFER_ID.'\z/', $id) || isset($urls[$id]) || $nodes->length !== 1) {
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

/** Resolve only explicit post references in the authoritative feed, with the reviewed overrides. */
function anytourTelegramFeedDestinationPosts(array $source): array
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
    $landing = $source;
    foreach ($source as $id => $url) {
        if (!preg_match('~\Ahttps://t\.me/(?:any_toour|anytour)/[1-9][0-9]*\z~D', $url)) {
            throw new RuntimeException('Unsupported Telegram offer destination');
        }
        if (isset($destinations[$id])) {
            [$old, $direct] = $destinations[$id];
            if ($url !== $old && $url !== $direct) {
                throw new RuntimeException('Unexpected reviewed Telegram offer destination');
            }
            $landing[$id] = $direct;
        }
    }
    return $landing;
}

/** Project every offer URL into the Mini App without reserializing the XML. */
function anytourTelegramFeedProject(string $xml): string
{
    $current = anytourTelegramFeedOfferUrls($xml);
    anytourTelegramFeedDestinationPosts($current);
    if (preg_match_all('~<offers(?=[\s>])(?:[^<>"\']+|"[^"]*"|\'[^\']*\')*>~', $xml, $opening, PREG_OFFSET_CAPTURE) !== 1
        || preg_match_all('~</offers\s*>~', $xml, $closing, PREG_OFFSET_CAPTURE) !== 1) {
        throw new RuntimeException('Ambiguous feed offers boundaries');
    }
    $start = $opening[0][0][1] + strlen($opening[0][0][0]);
    $end = $closing[0][0][1];
    if ($end <= $start) {
        throw new RuntimeException('Invalid feed offers boundaries');
    }

    $ids = array_keys($current);
    $expected = [];
    $index = 0;
    $offers = preg_replace_callback('~<url>(https://t\.me/(?:any_toour|anytour)/[1-9][0-9]*)</url>~',
        static function (array $match) use ($ids, $current, &$expected, &$index): string {
            $id = $ids[$index++] ?? null;
            if ($id === null || $match[1] !== $current[$id]) {
                throw new RuntimeException('Offer URL order changed in source');
            }
            $url = 'https://t.me/Any_tour_bot?startapp={yclid}_'.$id;
            $expected[$id] = $url;
            return '<url>'.$url.'</url>';
        }, substr($xml, $start, $end - $start));
    if (!is_string($offers) || $index !== count($current)) {
        throw new RuntimeException('Unexpected number of offer URL leaves');
    }
    $result = substr($xml, 0, $start).$offers.substr($xml, $end);
    if (anytourTelegramFeedOfferUrls($result, strlen($result)) !== $expected) {
        throw new RuntimeException('Projected URL verification failed');
    }
    return $result;
}

function anytourTelegramFeedOfferFromQuery(string $query): ?string
{
    return preg_match('/\Aoffer=('.ANYTOUR_TELEGRAM_OFFER_ID.')\z/', $query, $match) ? $match[1] : null;
}

function anytourTelegramFeedResolve(string $xml, string $offerId): ?array
{
    if (!preg_match('/\A'.ANYTOUR_TELEGRAM_OFFER_ID.'\z/', $offerId)) {
        throw new InvalidArgumentException('Invalid Telegram offer identifier');
    }
    $landing = anytourTelegramFeedDestinationPosts(anytourTelegramFeedOfferUrls($xml));
    return isset($landing[$offerId]) ? ['offer_id' => $offerId, 'post_url' => $landing[$offerId]] : null;
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

$isResolver = array_key_exists('offer', $_GET);
if ($isResolver) {
    header('Access-Control-Allow-Origin: https://tg.rhat.ru');
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    $offerId = anytourTelegramFeedOfferFromQuery((string) ($_SERVER['QUERY_STRING'] ?? ''));
    if ($offerId === null || ($_GET['offer'] ?? null) !== $offerId) {
        http_response_code(400);
        header('Cache-Control: no-store');
        return;
    }
}

try {
    $source = anytourTelegramFeedRead();
    if ($isResolver) {
        $resolved = anytourTelegramFeedResolve($source, $offerId);
        if ($resolved === null) {
            http_response_code(404);
            header('Cache-Control: no-store');
            return;
        }
        header('Cache-Control: public, max-age=60');
        if ($method === 'GET') {
            echo json_encode($resolved, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        return;
    }
    $xml = anytourTelegramFeedProject($source);
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
