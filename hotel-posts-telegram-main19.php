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
        'tg_17595' => ['https://t.me/any_toour/455', 'https://t.me/anytour/862'],
        'tg_358' => ['https://t.me/any_toour/65', 'https://t.me/anytour/328'],
        'tg_393' => ['https://t.me/any_toour/85', 'https://t.me/anytour/1083'],
        'tg_1260' => ['https://t.me/any_toour/548', 'https://t.me/anytour/288'],
        'tg_408' => ['https://t.me/any_toour/120', 'https://t.me/anytour/997'],
        'tg_15693' => ['https://t.me/any_toour/532', 'https://t.me/anytour/224'],
        'tg_293' => ['https://t.me/any_toour/83', 'https://t.me/anytour/249'],
        'tg_1972' => ['https://t.me/any_toour/391', 'https://t.me/anytour/316'],
        'tg_2588' => ['https://t.me/any_toour/689', 'https://t.me/anytour/346'],
        'tg_24909' => ['https://t.me/any_toour/188', 'https://t.me/anytour/372'],
        'tg_57028' => ['https://t.me/any_toour/76', 'https://t.me/anytour/626'],
        'tg_65877' => ['https://t.me/any_toour/227', 'https://t.me/anytour/646'],
        'tg_43689' => ['https://t.me/any_toour/878', 'https://t.me/anytour/884'],
        'tg_47025' => ['https://t.me/any_toour/271', 'https://t.me/anytour/901'],
        'tg_139' => ['https://t.me/any_toour/140', 'https://t.me/anytour/905'],
        'tg_435' => ['https://t.me/any_toour/96', 'https://t.me/anytour/906'],
        'tg_513' => ['https://t.me/any_toour/217', 'https://t.me/anytour/908'],
        'tg_2470' => ['https://t.me/any_toour/658', 'https://t.me/anytour/914'],
        'tg_56092' => ['https://t.me/any_toour/99', 'https://t.me/anytour/915'],
        'tg_59114' => ['https://t.me/any_toour/619', 'https://t.me/anytour/917'],
        'tg_63191' => ['https://t.me/any_toour/674', 'https://t.me/anytour/918'],
        'tg_77914' => ['https://t.me/any_toour/45', 'https://t.me/anytour/924'],
        'tg_106' => ['https://t.me/any_toour/468', 'https://t.me/anytour/928'],
        'tg_466' => ['https://t.me/any_toour/473', 'https://t.me/anytour/925'],
        'tg_114' => ['https://t.me/any_toour/469', 'https://t.me/anytour/948'],
        'tg_140' => ['https://t.me/any_toour/209', 'https://t.me/anytour/949'],
        'tg_195' => ['https://t.me/any_toour/617', 'https://t.me/anytour/952'],
        'tg_206' => ['https://t.me/any_toour/622', 'https://t.me/anytour/963'],
        'tg_231' => ['https://t.me/any_toour/598', 'https://t.me/anytour/969'],
        'tg_246' => ['https://t.me/any_toour/106', 'https://t.me/anytour/970'],
        'tg_263' => ['https://t.me/any_toour/90', 'https://t.me/anytour/999'],
        'tg_266' => ['https://t.me/any_toour/757', 'https://t.me/anytour/1003'],
        'tg_371' => ['https://t.me/any_toour/80', 'https://t.me/anytour/1037'],
        'tg_416' => ['https://t.me/any_toour/42', 'https://t.me/anytour/1051'],
        'tg_465' => ['https://t.me/any_toour/116', 'https://t.me/anytour/1052'],
        'tg_497' => ['https://t.me/any_toour/597', 'https://t.me/anytour/1057'],
        'tg_932' => ['https://t.me/any_toour/574', 'https://t.me/anytour/1059'],
        'tg_948' => ['https://t.me/any_toour/582', 'https://t.me/anytour/1087'],
        'tg_964' => ['https://t.me/any_toour/259', 'https://t.me/anytour/1110'],
        'tg_975' => ['https://t.me/any_toour/254', 'https://t.me/anytour/1131'],
        'tg_1013' => ['https://t.me/any_toour/591', 'https://t.me/anytour/920'],
        'tg_64918' => ['https://t.me/any_toour/1046', 'https://t.me/anytour/1020'],
        'tg_996' => ['https://t.me/any_toour/325', 'https://t.me/anytour/1063'],
        'tg_4091' => ['https://t.me/any_toour/264', 'https://t.me/anytour/1092'],
        'tg_75100' => ['https://t.me/any_toour/726', 'https://t.me/anytour/1380'],
        'tg_150' => ['https://t.me/any_toour/71', 'https://t.me/anytour/1034'],
        'tg_288' => ['https://t.me/any_toour/471', 'https://t.me/anytour/1128'],
        'tg_483' => ['https://t.me/any_toour/575', 'https://t.me/anytour/603'],
        'tg_713' => ['https://t.me/any_toour/297', 'https://t.me/anytour/731'],
        'tg_768' => ['https://t.me/any_toour/855', 'https://t.me/anytour/516'],
        'tg_11791' => ['https://t.me/any_toour/1047', 'https://t.me/anytour/560'],
        'tg_26844' => ['https://t.me/any_toour/1048', 'https://t.me/anytour/799'],
        'tg_28460' => ['https://t.me/any_toour/743', 'https://t.me/anytour/1570'],
        'tg_54605' => ['https://t.me/any_toour/431', 'https://t.me/anytour/892'],
        'tg_64914' => ['https://t.me/any_toour/426', 'https://t.me/anytour/938'],
        'tg_82106' => ['https://t.me/any_toour/754', 'https://t.me/anytour/983'],
        'tg_107938' => ['https://t.me/any_toour/224', 'https://t.me/anytour/783'],
        'tg_236' => ['https://t.me/any_toour/1110', 'https://t.me/anytour/1074'],
        'tg_454' => ['https://t.me/any_toour/62', 'https://t.me/anytour/956'],
        'tg_1035' => ['https://t.me/any_toour/185', 'https://t.me/anytour/610'],
        'tg_1105' => ['https://t.me/any_toour/349', 'https://t.me/anytour/1018'],
        'tg_1451' => ['https://t.me/any_toour/151', 'https://t.me/anytour/702'],
        'tg_1960' => ['https://t.me/any_toour/203', 'https://t.me/anytour/578'],
        'tg_3415' => ['https://t.me/any_toour/258', 'https://t.me/anytour/755'],
        'tg_4270' => ['https://t.me/any_toour/857', 'https://t.me/anytour/911'],
        'tg_51238' => ['https://t.me/any_toour/514', 'https://t.me/anytour/431'],
        'tg_79071' => ['https://t.me/any_toour/234', 'https://t.me/anytour/846'],
        'tg_85417' => ['https://t.me/any_toour/638', 'https://t.me/anytour/737'],
        'tg_109' => ['https://t.me/any_toour/82', 'https://t.me/anytour/819'],
        'tg_125' => ['https://t.me/any_toour/77', 'https://t.me/anytour/852'],
        'tg_133' => ['https://t.me/any_toour/735', 'https://t.me/anytour/867'],
        'tg_247' => ['https://t.me/any_toour/206', 'https://t.me/anytour/889'],
        'tg_272' => ['https://t.me/any_toour/117', 'https://t.me/anytour/894'],
        'tg_287' => ['https://t.me/any_toour/95', 'https://t.me/anytour/964'],
        'tg_302' => ['https://t.me/any_toour/222', 'https://t.me/anytour/972'],
        'tg_441' => ['https://t.me/any_toour/121', 'https://t.me/anytour/1001'],
        'tg_599' => ['https://t.me/any_toour/839', 'https://t.me/anytour/995'],
        'tg_632' => ['https://t.me/any_toour/928', 'https://t.me/anytour/955'],
        'tg_706' => ['https://t.me/any_toour/853', 'https://t.me/anytour/355'],
        'tg_954' => ['https://t.me/any_toour/393', 'https://t.me/anytour/1055'],
        'tg_955' => ['https://t.me/any_toour/392', 'https://t.me/anytour/1095'],
        'tg_1032' => ['https://t.me/any_toour/387', 'https://t.me/anytour/1069'],
        'tg_1055' => ['https://t.me/any_toour/419', 'https://t.me/anytour/1090'],
        'tg_1067' => ['https://t.me/any_toour/273', 'https://t.me/anytour/1066'],
        'tg_1100' => ['https://t.me/any_toour/173', 'https://t.me/anytour/1072'],
        'tg_1142' => ['https://t.me/any_toour/343', 'https://t.me/anytour/1081'],
        'tg_1151' => ['https://t.me/any_toour/152', 'https://t.me/anytour/1102'],
        'tg_1221' => ['https://t.me/any_toour/238', 'https://t.me/anytour/1078'],
        'tg_1334' => ['https://t.me/any_toour/169', 'https://t.me/anytour/664'],
        'tg_1336' => ['https://t.me/any_toour/453', 'https://t.me/anytour/1032'],
        'tg_1421' => ['https://t.me/any_toour/552', 'https://t.me/anytour/1076'],
        'tg_1429' => ['https://t.me/any_toour/285', 'https://t.me/anytour/1125'],
        'tg_2522' => ['https://t.me/any_toour/1049', 'https://t.me/anytour/1120'],
        'tg_2794' => ['https://t.me/any_toour/1056', 'https://t.me/anytour/768'],
        'tg_4060' => ['https://t.me/any_toour/289', 'https://t.me/anytour/916'],
        'tg_8020' => ['https://t.me/any_toour/899', 'https://t.me/anytour/839'],
        'tg_32322' => ['https://t.me/any_toour/682', 'https://t.me/anytour/1603'],
        'tg_1014' => ['https://t.me/any_toour/345', 'https://t.me/anytour/1604'],
        'tg_14140' => ['https://t.me/any_toour/593', 'https://t.me/anytour/909'],
        'tg_1402' => ['https://t.me/any_toour/312', 'https://t.me/anytour/1085'],
        'tg_1959' => ['https://t.me/any_toour/470', 'https://t.me/anytour/526'],
        'tg_13872' => ['https://t.me/any_toour/57', 'https://t.me/anytour/842'],
        'tg_13873' => ['https://t.me/any_toour/58', 'https://t.me/anytour/774'],
        'tg_280' => ['https://t.me/any_toour/59', 'https://t.me/anytour/292'],
        'tg_1569' => ['https://t.me/any_toour/571', 'https://t.me/anytour/674'],
        'tg_1096' => ['https://t.me/any_toour/302', 'https://t.me/anytour/743'],
        'tg_1257' => ['https://t.me/any_toour/251', 'https://t.me/anytour/865'],
        'tg_1149' => ['https://t.me/any_toour/741', 'https://t.me/anytour/890'],
        'tg_1033' => ['https://t.me/any_toour/212', 'https://t.me/anytour/698'],
        'tg_1143' => ['https://t.me/any_toour/235', 'https://t.me/anytour/738'],
        'tg_1420' => ['https://t.me/any_toour/540', 'https://t.me/anytour/769'],
        'tg_1196' => ['https://t.me/any_toour/440', 'https://t.me/anytour/860'],
        'tg_1246' => ['https://t.me/any_toour/351', 'https://t.me/anytour/885'],
        'tg_1258' => ['https://t.me/any_toour/280', 'https://t.me/anytour/418'],
        'tg_1354' => ['https://t.me/any_toour/197', 'https://t.me/anytour/453'],
        'tg_1456' => ['https://t.me/any_toour/362', 'https://t.me/anytour/919'],
        'tg_28326' => ['https://t.me/any_toour/347', 'https://t.me/anytour/967'],
        'tg_961' => ['https://t.me/any_toour/348', 'https://t.me/anytour/971'],
        'tg_1433' => ['https://t.me/any_toour/350', 'https://t.me/anytour/1314'],
        'tg_78905' => ['https://t.me/any_toour/354', 'https://t.me/anytour/1510'],
        'tg_21475' => ['https://t.me/any_toour/63', 'https://t.me/anytour/445'],
        'tg_1963' => ['https://t.me/any_toour/64', 'https://t.me/anytour/587'],
        'tg_32575' => ['https://t.me/any_toour/47', 'https://t.me/anytour/596'],
        'tg_112914' => ['https://t.me/any_toour/48', 'https://t.me/anytour/876'],
        'tg_262' => ['https://t.me/any_toour/50', 'https://t.me/anytour/1080'],
        'tg_12119' => ['https://t.me/any_toour/52', 'https://t.me/anytour/1039'],
        'tg_26044' => ['https://t.me/any_toour/53', 'https://t.me/anytour/1528'],
        'tg_66150' => ['https://t.me/any_toour/54', 'https://t.me/anytour/1736'],
        'tg_108146' => ['https://t.me/any_toour/190', 'https://t.me/anytour/937'],
        'tg_1362' => ['https://t.me/any_toour/191', 'https://t.me/anytour/950'],
        'tg_1702' => ['https://t.me/any_toour/515', 'https://t.me/anytour/953'],
        'tg_237' => ['https://t.me/any_toour/46', 'https://t.me/anytour/1021'],
        'tg_28448' => ['https://t.me/any_toour/370', 'https://t.me/anytour/1434'],
        'tg_88750' => ['https://t.me/any_toour/356', 'https://t.me/anytour/926'],
        'tg_1649' => ['https://t.me/any_toour/369', 'https://t.me/anytour/882'],
        'tg_1015' => ['https://t.me/any_toour/368', 'https://t.me/anytour/1526'],
        'tg_1716' => ['https://t.me/any_toour/371', 'https://t.me/anytour/1250'],
        'tg_1026' => ['https://t.me/any_toour/263', 'https://t.me/anytour/676'],
        'tg_55828' => ['https://t.me/any_toour/268', 'https://t.me/anytour/1121'],
        'tg_63833' => ['https://t.me/any_toour/422', 'https://t.me/anytour/1093'],
        'tg_2651' => ['https://t.me/any_toour/424', 'https://t.me/anytour/605'],
        'tg_2673' => ['https://t.me/any_toour/425', 'https://t.me/anytour/1534'],
        'tg_28512' => ['https://t.me/any_toour/353', 'https://t.me/anytour/1461'],
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

function anytourTelegramFeedResolve(string $xml, string $offerId, ?callable $routeLookup = null): ?array
{
    if (!preg_match('/\A'.ANYTOUR_TELEGRAM_OFFER_ID.'\z/', $offerId)) {
        throw new InvalidArgumentException('Invalid Telegram offer identifier');
    }
    $landing = anytourTelegramFeedDestinationPosts(anytourTelegramFeedOfferUrls($xml));
    $tableRoutes = [
        'tg_118475' => ['https://t.me/any_toour/194', 'https://t.me/anytour/1780'],
        'tg_3405' => ['https://t.me/any_toour/158', 'https://t.me/anytour/1760'],
    ];
    if ($routeLookup !== null && isset($landing[$offerId], $tableRoutes[$offerId])) {
        [$old, $new] = $tableRoutes[$offerId];
        if ($landing[$offerId] !== $old) {
            throw new RuntimeException('Unexpected Telegram route source');
        }
        $tableRoute = $routeLookup($offerId);
        if ($tableRoute !== null) {
            if (($tableRoute['offer_id'] ?? null) !== $offerId || !in_array($tableRoute['post_url'] ?? null, [$old, $new], true)) {
                throw new RuntimeException('Unexpected Telegram table destination');
            }
            return ['offer_id' => $offerId, 'post_url' => $tableRoute['post_url'], 'route_table' => 'max2-v1'];
        }
    }
    return isset($landing[$offerId]) ? ['offer_id' => $offerId, 'post_url' => $landing[$offerId]] : null;
}

function anytourTelegramReadSharedRoute(string $offerId): ?array
{
    if (!in_array($offerId, ['tg_118475', 'tg_3405'], true)) {
        return null;
    }
    $request = curl_init('https://tour-max.ru/new/max2/telegram-hotel-route.php?offer='.$offerId);
    try {
        $buffer = '';
        $configured = curl_setopt_array($request, [
            CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $part) use (&$buffer): int {
                if (strlen($buffer) + strlen($part) > 4096) {
                    return 0;
                }
                $buffer .= $part;
                return strlen($part);
            },
        ]);
        if (!$configured || curl_exec($request) !== true || curl_getinfo($request, CURLINFO_RESPONSE_CODE) !== 200) {
            return null;
        }
        $payload = json_decode($buffer, true, 6, JSON_THROW_ON_ERROR);
        return is_array($payload) ? $payload : null;
    } catch (JsonException $error) {
        return null;
    } finally {
        curl_close($request);
    }
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
        $resolved = anytourTelegramFeedResolve($source, $offerId, 'anytourTelegramReadSharedRoute');
        if ($resolved === null) {
            http_response_code(404);
            header('Cache-Control: no-store');
            return;
        }
        header(isset($resolved['route_table']) ? 'Cache-Control: no-store' : 'Cache-Control: public, max-age=60');
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
