<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/LocalAiFallbackService.php';

$passed = 0;
$failed = 0;

function localCheck(string $name, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "PASS  {$name}\n";
        $passed++;
        return;
    }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

echo "Local AI fallback regression\n";
echo "============================\n\n";

$route = LocalAiFallbackService::classify('Египет');
localCheck('short country stays local', $route['rich'], false);
localCheck('short country is simple', $route['simple'], true);

$route = LocalAiFallbackService::classify('Хургада, 2 взрослых, отель 5 звезд, на 9-11 ночей');
localCheck('rich request goes directly to AI', $route['rich'], true);

$route = LocalAiFallbackService::classify('Что лучше Турция или Египет?');
localCheck('comparison does not early-prompt as simple local', $route['simple'], false);

$params = LocalAiFallbackService::parameters('Египет', []);
$params = LocalAiFallbackService::applyDestinationDefaults($params, []);
localCheck('empty context defaults departure to Moscow', $params['city'] ?? null, 'Москва');
localCheck('Egypt recognized locally', $params['country'] ?? null, 'Египет');
localCheck('Egypt default meal preserved', $params['meal'] ?? null, 'all_inclusive');
localCheck('Egypt default stars preserved', $params['stars'] ?? null, 4);

$params = LocalAiFallbackService::parameters('ЕГИПЕТ', ['city'=>'Москва']);
localCheck('uppercase Cyrillic country is recognized without lowercasing dependency', $params['country'] ?? null, 'Египет');
$params = LocalAiFallbackService::parameters('еГиПеТ', ['city'=>'Москва']);
localCheck('mixed-case Cyrillic country is recognized', $params['country'] ?? null, 'Египет');

$source = (string)file_get_contents(__DIR__ . '/../services/LocalAiFallbackService.php');
localCheck('country matching uses Unicode case-insensitive PCRE', strpos($source, 'preg_quote($stem, \'/\')') !== false && strpos($source, "'/ui'") !== false, true);

$params = LocalAiFallbackService::parameters('На двоих без детей на неделю', ['city'=>'Казань']);
localCheck('existing departure is not replaced', array_key_exists('city', $params), false);
localCheck('two adults recognized', $params['adults'] ?? null, 2);
localCheck('no children recognized', $params['children'] ?? null, 0);
localCheck('week recognized as seven nights', $params['nights'] ?? null, '7');

// Compound replies must isolate the explicitly labelled duration before the
// canonical nights resolver sees it. Date, budget and party numbers are not
// contextual answers to the nights question on this free-text surface.
$nightsContext = ['city'=>'Самара','country'=>'Турция','date'=>'12.12.2030','nights'=>'8','adults'=>2,'children'=>0];
foreach ([
    ['Самара с 12 декабря на 10 ночей', '10'],
    ['Самара, 12.12.2030, на 10 ночей', '10'],
    ['Из Самары с 12 декабря на 7–10 ночей', '7-10'],
    ['На 7 — 10 ночей', '7-10'],
    ['На 7-10 ночей', '7-10'],
    ['Бюджет 250000, на 10 ночей', '10'],
    ['Через 7 дней, на 10 ночей', '10'],
    ['Через неделю, на 10 ночей', '10'],
    ['На 1 ночь', '1'],
    ['На 28 ночей', '28'],
    ['На 1–28 ночей', '1-28'],
    ['На неделю', '7'],
    ['Неделю', '7'],
    ['На недельку', '7'],
] as [$text, $expectedNights]) {
    $params = LocalAiFallbackService::parameters($text, $nightsContext);
    localCheck('explicit duration changes only nights: '.$text, $params, ['nights'=>$expectedNights]);
}

foreach ([
    'Самара с 12 декабря на 10 ночей, 2 взрослых и ребёнок 6 лет',
    '2 взрослых и ребёнок 6 лет, Самара с 12 декабря на 10 ночей',
] as $text) {
    $params = LocalAiFallbackService::parameters($text, $nightsContext);
    localCheck('duration and complete party stay independent: '.$text, $params,
        ['adults'=>2,'children'=>1,'child_ages'=>[6],'nights'=>'10']);
}
$params = LocalAiFallbackService::parameters('2 взрослых, ребёнок 1 год 11 месяцев, на 10 ночей', $nightsContext);
localCheck('infant age months are not a conflicting trip duration', $params['nights'] ?? null, '10');
localCheck('infant age remains canonical beside explicit nights', $params['child_ages'] ?? null, [1]);
foreach (['Детей нет, Самара с 12 декабря на 10 ночей', 'На 10 ночей, детей нет'] as $text) {
    $params = LocalAiFallbackService::parameters($text, $nightsContext);
    localCheck('negative child composition does not negate duration: '.$text, $params,
        ['children'=>0,'nights'=>'10']);
}
$params = LocalAiFallbackService::parameters('Не Турция, а Египет, 2 взрослых без детей на 10 ночей', $nightsContext);
localCheck('independent country correction keeps explicit numeric nights', $params,
    ['country'=>'Египет','adults'=>2,'children'=>0,'nights'=>'10']);

foreach ([
    'Самара с 12 декабря',
    'Самара 12.12.2030',
    '2 взрослых и ребёнок 10 лет',
    'Бюджет 250000',
    '10',
    'На 10 ночей?',
    'На 10 ночей？',
    'Можно на 10 ночей',
    'Подскажите цену на 10 ночей',
    'Какая стоимость тура на 10 ночей',
    'Если на 10 ночей',
    'Возможно, на 10 ночей',
    'Например, на 10 ночей',
    'Хотели бы на 10 ночей',
    'Не на 10 ночей',
    'Нам не нужен тур на 10 ночей',
    'На 10 ночей не хочу',
    'Не 7, а 10 ночей',
    'Не Турция на 7 ночей, а Египет на 10 ночей',
    '7 или 10 ночей',
    'На неделю или 10 ночей',
    'На 10 ночей, а 7',
    'На 10 ночей и 7',
    'На 7 ночей и на 10 ночей',
    'На неделю, на 10 ночей',
    'На 7 дней, на 10 ночей',
    '7 дней, на 10 ночей',
    'На 10 ночей и 7 дней',
    'На 10 ночей, на месяц',
    'На 0 ночей',
    'На 29 ночей',
    'На 100 ночей',
    'На 10–7 ночей',
    'На 7–29 ночей',
    'От 10 ночей',
    'До 10 ночей',
    'От 7 до 10 ночей',
    'Не менее 10 ночей',
    '10 ночей и больше',
    '10 ночей и дольше',
    '10 ночей и короче',
    '10 ночей+',
    '10 ночей с плюсом',
    'Примерно 10 ночей',
    'Почти 10 ночей',
    '1.10 ночей',
    '3,4 ночей',
    '3, 4 ночей',
    'На 7..10 ночей',
    '7/10 ночей',
    '7 10 ночей',
    '-10 ночей',
    '- 10 ночей',
    'На 10 ночей2',
    'На 2 недели',
    'На 10 дней',
    'На месяц',
] as $text) {
    $params = LocalAiFallbackService::parameters($text, $nightsContext);
    localCheck('unconfirmed duration is not partially accepted: '.$text, array_key_exists('nights', $params), false);
}
$params = LocalAiFallbackService::parameters('2 взрослых без детей, на 7 ночей или на 10 ночей', $nightsContext);
localCheck('ambiguous nights do not discard independent party facts', $params, ['adults'=>2,'children'=>0]);

$liveRich = 'Хотим из Москвы в Турцию в конце сентября, 2 взрослых и ребёнок 6 лет';
$params = LocalAiFallbackService::parameters($liveRich, []);
localCheck('live rich request deterministically keeps explicit country', $params['country'] ?? null, 'Турция');
localCheck('live rich request deterministically keeps explicit adults', $params['adults'] ?? null, 2);
localCheck('live rich request deterministically infers singular child', $params['children'] ?? null, 1);
localCheck('live rich request deterministically keeps child age next to child wording', $params['child_ages'] ?? null, [6]);

$params = LocalAiFallbackService::parameters('1 взрослый и 2 ребенка', ['city'=>'Москва']);
localCheck('explicit numeric child count in sentence is retained', $params['children'] ?? null, 2);

// Synthetic compound requests keep the whole party independently of date
// clarification, without feeding date or party-count digits to the age parser.
$compound = '24 июля по 2 августа 2030, 2 взрослых 2 ребёнка 6,12 лет';
$params = LocalAiFallbackService::parameters($compound, ['city'=>'Казань']);
localCheck('compound request retains both adults', $params['adults'] ?? null, 2);
localCheck('compound request retains both children', $params['children'] ?? null, 2);
localCheck('compound request retains ages tied to the children', $params['child_ages'] ?? null, [6,12]);
localCheck('compound request never replaces the saved departure', array_key_exists('city', $params), false);
foreach ([
    ['24 июля по 2 августа 2030, 2 взрослых 2 ребёнка 6 и 12 лет', [6,12]],
    ['2 ребёнка 6 лет и 12 лет, 2 взрослых, 24 июля по 2 августа 2030', [6,12]],
    ['2 ребёнка 0 и 8 лет, на неделю', [0,8]],
] as [$text, $expectedAges]) {
    $params = LocalAiFallbackService::parameters($text, ['city'=>'Казань']);
    localCheck('explicit age list uses canonical bounds: '.$text, $params['child_ages'] ?? null, $expectedAges);
}
foreach ([
    '24 июля по 2 августа 2030, 2 взрослых 2 ребёнка',
    '2 ребёнка 6 лет',
    '2 ребёнка 6-12 лет',
    '2 ребёнка 06.12',
    '2 ребёнка 6 и 18 лет',
    '2 ребёнка 6,12,14 лет',
    '2 ребёнка 6,12 лет, 14',
    '2 ребёнка 6,12 лет или 14 лет',
    '2 ребёнка около 6 и 12 лет',
] as $text) {
    $params = LocalAiFallbackService::parameters($text, ['city'=>'Казань']);
    localCheck('unconfirmed or extra age details are not guessed: '.$text, array_key_exists('child_ages', $params), false);
}
foreach ([
    ['Ребёнок возрастом 6 лет. На неделю', [6]],
    ['Ребёнок возраст 6 лет', [6]],
    ['Ребёнку в возрасте 6 лет', [6]],
    ['Ребёнку 1 год 11 месяцев', [1]],
    ['Ребёнку 1 год и 11 месяцев. Когда вылет?', [1]],
] as [$text, $expectedAges]) {
    $params = LocalAiFallbackService::parameters($text, ['city'=>'Казань','children'=>1]);
    localCheck('complete explicit single-child age remains canonical: '.$text, $params['child_ages'] ?? null, $expectedAges);
}
foreach (['Ребёнку 1 год 15 месяцев', 'Ребёнку 1 год и 15 месяцев. Когда вылет?', 'Ребёнку будет 6 лет'] as $text) {
    $params = LocalAiFallbackService::parameters($text, ['city'=>'Казань','children'=>1]);
    localCheck('single-child age is never accepted from a truncated clause: '.$text, array_key_exists('child_ages', $params), false);
}
foreach ([
    ['Детям по 7 лет. Вы уже спрашивали', 2, [7,7]],
    ['Обоим по 6 лет. Когда вылет?', 2, [6,6]],
    ['Каждому ребёнку 8 лет. Нужна ещё информация?', 3, [8,8,8]],
] as [$text, $children, $expectedAges]) {
    $params = LocalAiFallbackService::parameters($text, ['city'=>'Казань','children'=>$children]);
    localCheck('complete shared-age clause survives an unrelated tail: '.$text, $params['child_ages'] ?? null, $expectedAges);
    localCheck('shared-age clause never changes known child count: '.$text, array_key_exists('children', $params), false);
}
foreach ([
    ['Детям по 7 лет. Одному 8 лет', 2],
    ['Детям по 7 лет, ребёнку 8 лет', 2],
    ['Детям по 7 лет или 8 лет', 2],
    ['Детям по 7 лет, 8', 2],
    ['Детям по 7 лет. Не 7, а 8', 2],
    ['Не обоим по 7 лет. Уточню', 2],
    ['Обоим по 7 лет. Когда вылет?', 3],
    ['Каждому ребёнку 6 и 12 лет', 2],
] as [$text, $children]) {
    $params = LocalAiFallbackService::parameters($text, ['city'=>'Казань','children'=>$children]);
    localCheck('conflicting shared ages are not partially accepted: '.$text, array_key_exists('child_ages', $params), false);
}

$params = LocalAiFallbackService::applyDestinationDefaults(['date'=>'15.09.2026'], ['country'=>'Египет']);
localCheck('current Egypt still supplies defaults for date-only local correction', $params['meal'] ?? null, 'all_inclusive');
localCheck('current Egypt still supplies star default for date-only local correction', $params['stars'] ?? null, 4);

localCheck(
    'missing country before and after forces one AI destination fallback',
    LocalAiFallbackService::unresolvedDestination(['country','adults'], ['country','adults']),
    true
);
localCheck(
    'resolved country allows deterministic next question',
    LocalAiFallbackService::unresolvedDestination(['country','adults'], ['adults']),
    false
);

$handler = (string)file_get_contents(__DIR__ . '/../handlers/AiMessageHandler.php');
$sharedDeparturePattern = <<<'REGEX'
/DepartureCityResolver::resolveAndStore\(\s*\$chat_id,\s*\$userText,\s*\(\$missingNow\[0\]\s*\?\?\s*''\)\s*===\s*'city'\s*\)/s
REGEX;
$sharedDepartureMatched = preg_match($sharedDeparturePattern, $handler, $sharedDepartureMatch, PREG_OFFSET_CAPTURE) === 1;
localCheck('shared departure resolution retains the current-city question guard', $sharedDepartureMatched, true);
$localDateOffset = strpos($handler, 'AiDateContextService::resolveLocal($chat_id, $userText)');
$routeOffset = strpos($handler, 'LocalAiFallbackService::classify($userText)');
localCheck('one shared departure resolver runs before date and rich/local routing',
    $sharedDepartureMatched && substr_count($handler, 'DepartureCityResolver::resolveAndStore(') === 1
        && $localDateOffset !== false && $routeOffset !== false
        && $sharedDepartureMatch[0][1] < $localDateOffset && $localDateOffset < $routeOffset,
    true);
localCheck('rich request pre-applies deterministic local parameters before external AI', strpos($handler, 'NeedApplicationService::applyParameters($chat_id, $richLocalParams)') !== false, true);
localCheck('rich preseed never applies legacy Moscow default unless departure was explicit', strpos($handler, "unset(\$richLocalParams['city'])") !== false, true);
localCheck('rich AI receives refreshed deterministic context', strpos($handler, "AiInvocationService::invoke('RICH_AI', \$chat_id, \$userText, \$current)") !== false, true);


$params = LocalAiFallbackService::parameters('Не Турция, а Египет, 2 взрослых без детей на неделю', ['city'=>'Казань','country'=>'Турция']);
localCheck('country preseed cannot reapply the rejected first country', $params['country'] ?? null, 'Египет');
localCheck('country correction keeps explicit party', $params['adults'] ?? null, 2);
localCheck('country correction keeps explicit child zero', $params['children'] ?? null, 0);
localCheck('country correction keeps explicit duration', $params['nights'] ?? null, '7');
localCheck('country correction does not overwrite departure', array_key_exists('city', $params), false);
foreach (['Не Египет', 'А в Египет можно?', 'Если Египет', 'Турция или Египет', 'Расскажите про Египет', 'Раньше были в Египте', 'Пляж в Египте'] as $neutral) {
    $params = LocalAiFallbackService::parameters($neutral, ['city'=>'Казань','country'=>'Турция']);
    localCheck('neutral/negative country cannot become a replacement: ' . $neutral, array_key_exists('country', $params), false);
}
$params = LocalAiFallbackService::parameters('В Египет', ['city'=>'Казань','country'=>'Турция']);
localCheck('explicit short country choice replaces current country', $params['country'] ?? null, 'Египет');
$params = LocalAiFallbackService::parameters('Хочу в Египет, 2 взрослых', ['city'=>'Казань','country'=>'Турция']);
localCheck('explicit rich country choice replaces current country', $params['country'] ?? null, 'Египет');

$total = $passed + $failed;
echo "\n----------------------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
