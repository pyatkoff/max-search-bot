<?php

declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../services/DestinationResolver.php');

$checks = [
    'resolver requires catalog repository' => str_contains($source, "DestinationCatalogRepository.php"),
    'resolver routes queries through catalog repository' => str_contains($source, 'DestinationCatalogRepository::query'),
    'resolver no longer owns Bitrix highload lookup' => !str_contains($source, 'HighloadBlockTable'),
    'resolver no longer loads highloadblock module directly' => !str_contains($source, "includeModule('highloadblock')"),
];


// Exercise the real catalog/resolver/application/cache boundary in an isolated
// in-memory catalog. No application database, live transcript or production ID.
define('MAX_SEARCH_DESTINATION_STORAGE', 'mysql');
require_once __DIR__ . '/../services/DestinationResolver.php';
class MaxSearchApi
{
    public static array $context = [];
    public static bool $writeAllowed = true;
    public static bool $reportOnly = false;
    public static function getAiSearchContext($chatId): array { return self::$context; }
    public static function applyAiParameters($chatId, array $params): array
    {
        if (!self::$writeAllowed) return [];
        if (!self::$reportOnly) self::$context = array_merge(self::$context, $params);
        return array_fill_keys(array_keys($params), true);
    }
    public static function funnelLog($chatId, $event, $data): void {}
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE catalog_countries (id INTEGER, name TEXT, is_active INTEGER)');
$pdo->exec('CREATE TABLE catalog_regions (id INTEGER, country_id INTEGER, name TEXT, is_active INTEGER)');
$pdo->exec('CREATE TABLE catalog_hotels (id INTEGER, country_id INTEGER, region_id INTEGER, name TEXT, rating TEXT, is_active INTEGER)');
$pdo->exec("INSERT INTO catalog_countries VALUES (41,'Турция',1),(42,'Египет',1)");
$pdo->exec("INSERT INTO catalog_regions VALUES (151,41,'Sample Coast',1)");
$pdo->exec("INSERT INTO catalog_hotels VALUES (251,41,151,'Synthetic Harbor Resort','4',1)");
$connection = new ReflectionProperty(HotelDatabase::class, 'pdo');
$connection->setAccessible(true);
$connection->setValue(null, $pdo);
$chatId = -926110615;
DestinationResolver::clear($chatId);

try {
    $party = ['city'=>'Казань','adults'=>2,'children'=>0,'nights'=>'9','date'=>'18.08.2030'];
    MaxSearchApi::$context = $party + ['country'=>'Турция'];
    $old = DestinationResolver::resolveAndStore($chatId, 'Хочу Турция Sample Coast Synthetic Harbor Resort');
    $checks['fixture really seeds the original region and hotel'] =
        ($old['country_id'] ?? 0) === 41 && ($old['region_id'] ?? 0) === 151 && ($old['hotel_id'] ?? 0) === 251;

    $changed = DestinationResolver::resolveAndStore($chatId, 'Не Турция, а Египет');
    $checks['explicit replacement wins over stored country'] =
        ($changed['country_id'] ?? 0) === 42 && MaxSearchApi::$context['country'] === 'Египет';
    $checks['country replacement clears previous region and hotel'] =
        ($changed['region_id'] ?? -1) === 0 && ($changed['hotel_id'] ?? -1) === 0;
    $checks['country replacement preserves other canonical needs'] =
        array_intersect_key(MaxSearchApi::$context, $party) === $party;

    $next = DestinationResolver::resolveAndStore($chatId, '2 взрослых');
    $checks['next ordinary turn cannot restore the rejected country or hotel'] =
        ($next['country_id'] ?? 0) === 42 && ($next['region_id'] ?? -1) === 0 && ($next['hotel_id'] ?? -1) === 0;

    foreach (['Не Турция', 'А в Турцию можно?', 'Если Турция', 'Турция или Египет', 'Расскажите про Турцию', 'Раньше были в Турции', 'Пляж в Турции'] as $neutral) {
        $before = DestinationResolver::getStored($chatId);
        DestinationResolver::resolveAndStore($chatId, $neutral);
        $checks['no replacement for question/exclusion/mention: ' . $neutral] =
            MaxSearchApi::$context['country'] === 'Египет'
            && DestinationResolver::getStored($chatId) === $before;
    }

    foreach (['Турция', 'В Турцию', 'Хочу в Турцию, 2 взрослых'] as $choice) {
        MaxSearchApi::$context = $party + ['country'=>'Египет'];
        DestinationResolver::clear($chatId);
        $resolved = DestinationResolver::resolveAndStore($chatId, $choice);
        $checks['positive country choice is applied: ' . $choice] =
            ($resolved['country_id'] ?? 0) === 41 && MaxSearchApi::$context['country'] === 'Турция';
    }

    DestinationResolver::clear($chatId);
    MaxSearchApi::$context = $party + ['country'=>'Турция'];
    DestinationResolver::resolveAndStore($chatId, 'Хочу Турция Sample Coast Synthetic Harbor Resort');
    $before = DestinationResolver::getStored($chatId);
    MaxSearchApi::$writeAllowed = false;
    $refused = DestinationResolver::resolveAndStore($chatId, 'Не Турция, а Египет');
    $checks['refused canonical write cannot publish a new destination'] =
        $refused === [] && DestinationResolver::getStored($chatId) === $before
        && MaxSearchApi::$context['country'] === 'Турция';
    MaxSearchApi::$writeAllowed = true;
    MaxSearchApi::$reportOnly = true;
    $unconfirmed = DestinationResolver::resolveAndStore($chatId, 'Не Турция, а Египет');
    $checks['application flag without canonical readback cannot publish a new destination'] =
        $unconfirmed === [] && DestinationResolver::getStored($chatId) === $before;
} finally {
    DestinationResolver::clear($chatId);
    HotelDatabase::resetForTests();
}

$failed = 0;
foreach ($checks as $name => $ok) {
    if ($ok) {
        echo "PASS  {$name}\n";
    } else {
        echo "FAIL  {$name}\n";
        $failed++;
    }
}

echo "\n--------------------------\n";
echo 'TOTAL ' . count($checks) . ' | PASS ' . (count($checks) - $failed) . ' | FAIL ' . $failed . "\n";
exit($failed > 0 ? 1 : 0);
