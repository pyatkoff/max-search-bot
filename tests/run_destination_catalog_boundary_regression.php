<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/DestinationCatalogRepository.php';

$passed = 0;
$failed = 0;
function dcbCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

dcbCheck('legacy default stays bitrix', DestinationCatalogRepository::storage(), 'bitrix');

dcbCheck('country row keeps resolver shape', DestinationCatalogRepository::legacyShape('country', [
    'id' => 4,
    'name' => 'Турция',
]), [
    'UF_CID' => 4,
    'UF_NAME' => 'Турция',
]);

dcbCheck('region row keeps resolver shape', DestinationCatalogRepository::legacyShape('region', [
    'id' => 17,
    'country_id' => 4,
    'name' => 'Анталья',
]), [
    'UF_TID' => 17,
    'UF_CID' => 4,
    'UF_NAME' => 'Анталья',
    'UF_PARENT_TID' => 0,
]);

dcbCheck('hotel row keeps resolver shape', DestinationCatalogRepository::legacyShape('hotel', [
    'id' => 901,
    'country_id' => 4,
    'region_id' => 17,
    'name' => 'Example Resort',
    'rating' => '4.7',
]), [
    'UF_HID' => 901,
    'UF_NAME' => 'Example Resort',
    'UF_CID' => 4,
    'UF_TID' => 17,
    'UF_RATE' => '4.7',
]);

$source = (string)file_get_contents(__DIR__ . '/../services/DestinationCatalogRepository.php');
dcbCheck('mysql source uses countries table', str_contains($source, 'catalog_countries'), true);
dcbCheck('mysql source uses regions table', str_contains($source, 'catalog_regions'), true);
dcbCheck('mysql source uses hotels table', str_contains($source, 'catalog_hotels'), true);
dcbCheck('mysql source uses AnyTour data DB boundary', str_contains($source, 'HotelDatabase::connection()'), true);
dcbCheck('storage does not auto-switch from standalone flag', str_contains($source, 'MAX_SEARCH_STANDALONE_RUNTIME'), false);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
