<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/DepartureCityResolver.php';

$passed = 0; $failed = 0;
function rcheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n"; $failed++;
}

$rows = [
    ['id'=>1,'name'=>'Москва','name_genitive'=>'Москвы'],
    ['id'=>2,'name'=>'Санкт-Петербург','name_genitive'=>'Санкт-Петербурга'],
    ['id'=>3,'name'=>'Калининград','name_genitive'=>'Калининграда'],
    ['id'=>4,'name'=>'Минеральные Воды','name_genitive'=>'Минеральных Вод'],
];

rcheck('matches canonical departure name', DepartureCityResolver::bestMatch('хочу тур с вылетом из москва', $rows), ['city'=>'Москва','city_id'=>1,'matched'=>'Москва']);
rcheck('matches genitive departure name', DepartureCityResolver::bestMatch('хочу тур из калининграда', $rows), ['city'=>'Калининград','city_id'=>3,'matched'=>'Калининграда']);
rcheck('does not match city without departure marker', DepartureCityResolver::bestMatch('живу в калининграде', $rows), false);
rcheck('does not invent unknown departure', DepartureCityResolver::bestMatch('вылет из омска', $rows), false);

rcheck('field match ignores case for live full city', DepartureCityResolver::bestFieldMatch('Минеральные воды', $rows), ['city'=>'Минеральные Воды','city_id'=>4,'matched'=>'Минеральные Воды']);
rcheck('field match accepts live abbreviated city', DepartureCityResolver::bestFieldMatch('Мин. Воды', $rows), ['city'=>'Минеральные Воды','city_id'=>4,'matched'=>'Минеральные Воды']);
rcheck('field match does not treat bare prefix as abbreviation', DepartureCityResolver::bestFieldMatch('Мин Воды', $rows), false);
rcheck('field match does not invent unknown city', DepartureCityResolver::bestFieldMatch('Мин. Озёра', $rows), false);
rcheck('field match rejects ambiguous abbreviation', DepartureCityResolver::bestFieldMatch('Сан. Город', [
    ['id'=>10,'name'=>'Санкт Город','name_genitive'=>'Санкт Города'],
    ['id'=>11,'name'=>'Санаторный Город','name_genitive'=>'Санаторного Города'],
]), false);

$source = (string) file_get_contents(__DIR__ . '/../services/DepartureCityResolver.php');
rcheck('resolver has no Bitrix dependency', strpos($source, 'Bitrix') === false, true);
rcheck('resolver owns no HL block lookup', strpos($source, 'HighloadBlock') === false, true);
rcheck('resolver reads through directory repository', strpos($source, 'TravelDirectoryRepository::activeDepartures()') !== false, true);

$handlerSource = (string) file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
rcheck('city wizard reuses departure field resolver', strpos($handlerSource, 'DepartureCityResolver::resolveFieldValue($city)') !== false, true);

echo "\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
