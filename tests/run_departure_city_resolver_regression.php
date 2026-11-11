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
    ['id'=>5,'name'=>'Самара','name_genitive'=>'Самары'],
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

$compoundCases = [
    ['Самара с 12 декабря на 10 ночей', 'Самара', 5, 'Самара', 'с 12 декабря на 10 ночей'],
    ['Самара, на 10 ночей с 12.12.2026', 'Самара', 5, 'Самара', 'на 10 ночей с 12.12.2026'],
    ['Самара 10 ночей с 12 декабря', 'Самара', 5, 'Самара', '10 ночей с 12 декабря'],
    ['Самара 12 декабря 2026', 'Самара', 5, 'Самара', '12 декабря 2026'],
    ['Самара на 10-12 ночей', 'Самара', 5, 'Самара', 'на 10-12 ночей'],
    ['Самара на неделю', 'Самара', 5, 'Самара', 'на неделю'],
    ['Самара 2 взрослых и 1 ребёнок 5 лет, с 12 декабря на 10 ночей', 'Самара', 5, 'Самара', '2 взрослых и 1 ребёнок 5 лет, с 12 декабря на 10 ночей'],
    ['Самара 2 взрослых и 2 детей', 'Самара', 5, 'Самара', '2 взрослых и 2 детей'],
    ['Самара 1 ребёнок (5 лет) и 2 взрослых', 'Самара', 5, 'Самара', '1 ребёнок (5 лет) и 2 взрослых'],
    ['Самара 2 взрослых и 0 детей, с 12 декабря', 'Самара', 5, 'Самара', '2 взрослых и 0 детей, с 12 декабря'],
    ['Мин. Воды, с 12 декабря на 10 ночей', 'Минеральные Воды', 4, 'Минеральные Воды', 'с 12 декабря на 10 ночей'],
    ['Калининграда на 10 ночей', 'Калининград', 3, 'Калининграда', 'на 10 ночей'],
];
foreach ($compoundCases as [$text, $city, $id, $matched, $remaining]) {
    rcheck('compound departure keeps facts: '.$text,
        DepartureCityResolver::bestCompoundFieldMatch($text, $rows),
        ['city'=>$city, 'city_id'=>$id, 'matched'=>$matched, 'remaining_text'=>$remaining]);
}

foreach ([
    'Самара',
    'Самара с 12 декабря на 10 ночей?',
    'Самара с 12 декабря на 10 ночей？',
    'не Самара с 12 декабря на 10 ночей',
    'Самара не на 10 ночей',
    'Самара или Москва с 12 декабря',
    'Самара, Москва с 12 декабря',
    'Самара на 10 либо 12 ночей',
    'если Самара с 12 декабря',
    'Самара возможно с 12 декабря',
    'Самара на 10 ночей если получится',
    'в Самару с 12 декабря на 10 ночей',
    'прилёт в Самару с 12 декабря',
    'Самара прилёт 12 декабря',
    'Омск с 12 декабря на 10 ночей',
    'Мин Воды с 12 декабря',
    'Самара в Египет с 12 декабря',
    'Самара с 12 декабря, нужен песчаный пляж',
    'Самара с 12 декабря, расскажите об отеле',
    'Самара на 10 ночей и',
    'Самара на 10 ночей +',
    'Самара на 10 ночей + 2 взрослых',
    'Самара неделю',
    'Самара с 12.12.2027 10 ночей',
    'Самара 2 взр. на 10 ночей',
    'Самара 2взрослых на 10 ночей',
    'Самара 2 взрослых, на 10 ночей',
    'Самара,2 взрослых на 10 ночей',
    'Самара 7 взрослых на 10 ночей',
    'Самара вдвоём на 10 ночей',
    'Самара 1 ребёнок 5 и 8 лет',
    'Самара 2 детей 5 лет',
    'Самара 2 детей 5 и 8 лет',
    'Самара 0 детей 5 лет',
    'Самара 1 ребёнок 18 лет',
    'Самара 1 ребёнок 5лет',
    'Самара на 29 ночей',
    'Самара на 12-10 ночей',
    'Самара на 10 ночей, на 12 ночей',
    'Самара 2 взрослых, 3 взрослых',
    'Самара с 12 декабря с 15 декабря',
    'Самара из Москвы с 12 декабря',
] as $text) {
    rcheck('compound departure leaves non-fact or uncertain input: '.$text,
        DepartureCityResolver::bestCompoundFieldMatch($text, $rows), false);
}
rcheck('compound departure rejects ambiguous leading abbreviation',
    DepartureCityResolver::bestCompoundFieldMatch('Сан. Город с 12 декабря', [
        ['id'=>10,'name'=>'Санкт Город','name_genitive'=>'Санкт Города'],
        ['id'=>11,'name'=>'Санаторный Город','name_genitive'=>'Санаторного Города'],
    ]), false);
rcheck('compound departure does not match a city outside the active directory',
    DepartureCityResolver::bestCompoundFieldMatch('Самара с 12 декабря', array_slice($rows, 0, 4)), false);
rcheck('compound answer does not loosen the scalar city matcher',
    DepartureCityResolver::bestFieldMatch('Самара с 12 декабря на 10 ночей', $rows), false);
rcheck('compound answer is not stored without current-field permission',
    DepartureCityResolver::resolveAndStore(0, 'Самара с 12 декабря на 10 ночей'), false);
rcheck('explicitly disabled field answers do not select a leading city',
    DepartureCityResolver::resolveAndStore(0, 'Самара 2 взрослых', false), false);
rcheck('explicit departure marker takes precedence over a leading arrival city',
    DepartureCityResolver::bestMatch('Самара из Москвы с 12 декабря', $rows),
    ['city'=>'Москва','city_id'=>1,'matched'=>'Москвы']);

rcheck('rejected longer departure cannot beat the explicit replacement',
    DepartureCityResolver::bestMatch('не из санкт-петербурга, а из москвы', $rows),
    ['city'=>'Москва','city_id'=>1,'matched'=>'Москвы']);
rcheck('repeated positive occurrence of the same city remains valid',
    DepartureCityResolver::bestMatch('не из москвы, а из москвы', $rows),
    ['city'=>'Москва','city_id'=>1,'matched'=>'Москвы']);
rcheck('negated departure alone is not a selection',
    DepartureCityResolver::bestMatch('не из санкт-петербурга', $rows), false);
rcheck('availability question does not replace departure',
    DepartureCityResolver::bestMatch('а из москвы есть вылет?', $rows), false);
rcheck('two positive departure choices are not resolved by name length',
    DepartureCityResolver::bestMatch('из москвы или из санкт-петербурга', $rows), false);
rcheck('two positive cities without an alternative marker still need clarification',
    DepartureCityResolver::bestMatch('из москвы, из санкт-петербурга', $rows), false);
rcheck('hypothetical departure is not applied',
    DepartureCityResolver::bestMatch('если из москвы', $rows), false);

// Clarification is read-only and uses exactly the same active directory forms.
foreach (['Самара или Калининград', 'из Самары либо из Калининграда', 'САМАРА или Калининград.'] as $alternatives) {
    rcheck('two distinct complete departures can be clarified: '.$alternatives,
        array_column(DepartureCityResolver::bestFieldAlternatives($alternatives, $rows), 'city'),
        ['Самара','Калининград']);
    rcheck('clarification does not loosen scalar selection: '.$alternatives,
        DepartureCityResolver::bestFieldMatch($alternatives, $rows), false);
}
foreach ([
    'Самара', 'Самара или Самара', 'Самара или Самары',
    'Самара или Неизвестный', 'Самара или Калининград или Москва',
    'в Самару или Калининград', 'не Самара или Калининград',
    'если Самара или Калининград', 'Самара или Калининград?',
    'Самара или Калининград на 8 ночей', "Самара или\nКалининград",
] as $notAnAlternativeAnswer) {
    rcheck('clarification rejects non-confirmed whole departures: '.$notAnAlternativeAnswer,
        DepartureCityResolver::bestFieldAlternatives($notAnAlternativeAnswer, $rows), []);
}
rcheck('clarification cannot admit an inactive second departure',
    DepartureCityResolver::bestFieldAlternatives('Самара или Калининград',
        array_values(array_filter($rows, static fn(array $row): bool => $row['id'] !== 3))), []);
rcheck('ambiguous abbreviation cannot create a second departure',
    DepartureCityResolver::bestFieldAlternatives('Самара или Сан. Город', array_merge($rows, [
        ['id'=>10,'name'=>'Санкт Город','name_genitive'=>'Санкт Города'],
        ['id'=>11,'name'=>'Санаторный Город','name_genitive'=>'Санаторного Города'],
    ])), []);

$source = (string) file_get_contents(__DIR__ . '/../services/DepartureCityResolver.php');
rcheck('resolver has no Bitrix dependency', strpos($source, 'Bitrix') === false, true);
rcheck('resolver owns no HL block lookup', strpos($source, 'HighloadBlock') === false, true);
rcheck('resolver reads through directory repository', strpos($source, 'TravelDirectoryRepository::activeDepartures()') !== false, true);

$handlerSource = (string) file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
rcheck('city wizard reuses departure field resolver', strpos($handlerSource, 'DepartureCityResolver::resolveFieldValue($city)') !== false, true);

echo "\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
