<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/TravelDirectoryRepository.php';

$passed = 0;
$failed = 0;

function dcheck(string $name, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "PASS  {$name}\n";
        $passed++;
        return;
    }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

echo "Travel directory regression suite\n";
echo "=================================\n\n";

dcheck('city name from row', TravelDirectoryRepository::cityNameFromRow(['UF_NAME'=>'Калининград']), 'Калининград');
dcheck('standalone departure name from row', TravelDirectoryRepository::cityNameFromRow(['id'=>7,'name'=>'Калининград']), 'Калининград');
dcheck('city name missing row', TravelDirectoryRepository::cityNameFromRow(false), false);
dcheck('city from-name from row', TravelDirectoryRepository::cityFromNameFromRow(['UF_NAME2'=>'Калининграда']), 'Калининграда');
dcheck('standalone departure canonical from-name', TravelDirectoryRepository::cityFromNameFromRow(['id'=>7,'name'=>'Калининград']), 'Калининград');
dcheck(
    'city record keeps Tourvisor departure id',
    TravelDirectoryRepository::cityRecordFromRow(['UF_NAME'=>'Москва','UF_DEPID'=>1]),
    ['NAME'=>'Москва','ID'=>1]
);
dcheck(
    'standalone departure record keeps catalogue id',
    TravelDirectoryRepository::cityRecordFromRow(['id'=>1,'name'=>'Москва']),
    ['NAME'=>'Москва','ID'=>1]
);
dcheck('city record rejects incomplete row', TravelDirectoryRepository::cityRecordFromRow(['UF_NAME'=>'Москва']), false);
dcheck('country name from row', TravelDirectoryRepository::countryNameFromRow(['UF_NAME'=>'Турция']), 'Турция');
dcheck('standalone country name from row', TravelDirectoryRepository::countryNameFromRow(['id'=>4,'name'=>'Турция']), 'Турция');
dcheck(
    'country record keeps country id',
    TravelDirectoryRepository::countryRecordFromRow(['UF_NAME'=>'Турция','UF_CID'=>4]),
    ['NAME'=>'Турция','ID'=>4]
);
dcheck(
    'standalone country record keeps catalogue id',
    TravelDirectoryRepository::countryRecordFromRow(['id'=>4,'name'=>'Турция']),
    ['NAME'=>'Турция','ID'=>4]
);
dcheck('country record rejects incomplete row', TravelDirectoryRepository::countryRecordFromRow(['UF_CID'=>4]), false);

$meal = TravelDirectoryRepository::mealMap();
dcheck('meal any legacy id', $meal['999'] ?? null, 'ЛЮБОЕ');
dcheck('meal all-inclusive id', $meal['7'] ?? null, 'ВСЕ ВКЛЮЧЕНО');
dcheck('meal breakfast id', $meal['3'] ?? null, 'ЗАВТРАК');
dcheck('meal half-board id', $meal['4'] ?? null, 'ПОЛУПАНСИОН');
dcheck('meal full-board id', $meal['5'] ?? null, 'ПОЛНЫЙ ПАНСИОН');

$source = (string) file_get_contents(__DIR__ . '/../services/TravelDirectoryRepository.php');
dcheck('runtime no longer references Bitrix directory API', strpos($source, 'Bitrix') === false, true);
dcheck('runtime uses catalog_departures', strpos($source, 'catalog_departures') !== false, true);
dcheck('runtime uses catalog_countries', strpos($source, 'catalog_countries') !== false, true);

$total = $passed + $failed;
echo "\n---------------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
