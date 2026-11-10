<?php
declare(strict_types=1);
require_once __DIR__.'/../services/YandexRegionCatalog.php';

$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
foreach (['', '0', '000', '-2', '1.0', '2e3', '{region_id}', '213 OR 1=1', str_repeat('1', 21), true, [], 2.5] as $invalid) {
    $check(YandexRegionCatalog::normalizeId($invalid) === '', 'Invalid region must remain unresolved');
}
$check(YandexRegionCatalog::normalizeId(' 000213 ') === '213', 'Canonical advertising ID');
$check(YandexRegionCatalog::normalizeId('18446744073709551615') === '18446744073709551615', 'No lossy integer coercion');
$check(YandexRegionCatalog::normalizeId('999999') === '999999', 'Unknown numeric geography remains recordable');
$check(YandexRegionCatalog::findByYandexId('999999') === null, 'Unknown geography never invents a departure');
$spb = YandexRegionCatalog::findByYandexId('2');
$check($spb !== null && $spb['id'] === '2' && $spb['name'] === 'Санкт-Петербург', 'External ID lookup');
$check(in_array('С.Петербург', $spb['departure_names'], true), 'Known catalogue spelling supported');
$check(YandexRegionCatalog::findByYandexId('225')['departure_names'] === [], 'Country must not imply an airport');
$catalog = json_decode(file_get_contents(__DIR__.'/../resources/yandex_regions.json'), true, 32, JSON_THROW_ON_ERROR);
foreach ($catalog['regions'] as $id => $row) {
    $resolved = YandexRegionCatalog::findByYandexId((string)$id);
    $check($resolved !== null && $resolved['id'] === (string)$id, 'Every published region resolves by its Yandex ID');
    $check(!array_key_exists('city_id', $resolved), 'Geography must not masquerade as a travel catalogue ID');
}
echo "Yandex region catalogue regression passed\n";
