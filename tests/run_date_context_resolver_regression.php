<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/DateContextResolver.php';
require_once __DIR__ . '/../services/AiDateContextService.php';

$passed = 0;
$failed = 0;
function dcrCheck(string $name, bool $ok): void {
    global $passed, $failed;
    if ($ok) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n"; $failed++;
}

$chatId = -926082702;
DateContextResolver::clear($chatId);

// Synthetic reproduction: a month at the end of a full numeric date must not
// become the first day of a shorthand range (24.11-29.11 -> 11-29.11).
// Unsupported full-endpoint ranges retain the existing first-literal-date
// fallback; this repair does not introduce a new range or search-window policy.
foreach ([
    ['24.11-29.11', '24.11'],
    ['24.11–29.11', '24.11'],
    ['24.11—29.11', '24.11'],
    ['24/11-29/11', '24/11'],
    ['24. 11 - 29.11', '24.11'],
    ["24.\u{00A0}11–29.11", '24.11'],
    ['24.11.28-29.11.28', '24.11.28'],
    ['24.11.2030-29.11.2030', '24.11.2030'],
    ['24.11-03.12', '24.11'],
    ['Из Москвы в Египет, 24.11-29.11, 2 взрослых без детей', '24.11'],
] as [$input, $firstDate]) {
    $expected = DateParser::resolveDate($firstDate);
    dcrCheck('numeric range cannot start inside a date: ' . $input,
        DateParser::resolveDate($input) === $expected);
}
foreach (['24-29.11.2030', 'туры 24–29/11/2030', "Вылет\n24 — 29.11.2030"] as $input) {
    $range = DateParser::resolveDate($input);
    dcrCheck('standalone shorthand range retains midpoint and endpoints: ' . $input,
        ($range['date'] ?? '') === '27.11.2030'
        && ($range['range_from'] ?? '') === '24.11.2030'
        && ($range['range_to'] ?? '') === '29.11.2030');
}
DateContextResolver::rememberMonth($chatId, 12, 2030);
$numericLocal = AiDateContextService::resolveLocal($chatId, '24.11.28-29.11.28');
dcrCheck('AI local parsing does not seed a suffix-derived date', ($numericLocal['date'] ?? '') === '24.11.2028');
dcrCheck('literal numeric date clears obsolete pending month', PendingMonthStore::get($chatId) === []);
$numericGuard = AiDateContextService::applyAiGuard($chatId, '24.11.28-29.11.28', ['date'=>'20.11.2028', 'nights'=>'5']);
dcrCheck('AI guard preserves literal date instead of an invented range midpoint', ($numericGuard['date'] ?? '') === '24.11.2028');
dcrCheck('numeric date guard does not change other trip values', ($numericGuard['nights'] ?? '') === '5');

// Synthetic reproduction of the observed spaced numeric-date rejection.
// Formatting must not alter the explicit year, calendar validation or AI guard.
foreach ([
    ['18 . 11.2030', '18.11.2030'],
    ['18.11 . 2030', '18.11.2030'],
    [' 18 . 11 . 2030 ', '18.11.2030'],
    ["18\u{00A0}.\u{00A0}11 . 2030", '18.11.2030'],
    ['18 / 11 / 30', '18/11/30'],
    ['18 . 11 . 2020', '18.11.2020'],
    ['29 . 02 . 2032', '29.02.2032'],
    ['29 . 02 . 2030', '29.02.2030'],
    ['31 . 11 . 2030', '31.11.2030'],
] as [$spaced, $compact]) {
    dcrCheck('explicit numeric date spacing: ' . $compact,
        DateParser::resolveDate($spaced) === DateParser::resolveDate($compact));
}
DateContextResolver::rememberMonth($chatId, 12, 2030);
$spacedLocal = AiDateContextService::resolveLocal($chatId, '18 . 11.2030');
dcrCheck('AI local date recognizes spaced explicit input', ($spacedLocal['date'] ?? '') === '18.11.2030');
dcrCheck('spaced explicit date clears obsolete month context', PendingMonthStore::get($chatId) === []);
$spacedGuard = AiDateContextService::applyAiGuard($chatId, '18 . 11.2030', ['date'=>'20.12.2030']);
dcrCheck('spaced explicit user date wins over AI guess', ($spacedGuard['date'] ?? '') === '18.11.2030');

// A complete whitespace-delimited date confirms its own month and year.
// Synthetic values are independent of the private dialogue that exposed this.
$explicitYear = (int)date('Y') + 2;
foreach ([
    '17 12 '.$explicitYear,
    ' 17  12  '.$explicitYear.' ',
    "17\t12\t".$explicitYear,
    "17\u{00A0}12\u{00A0}".$explicitYear,
    "17\u{202F}12\u{202F}".$explicitYear,
] as $completeDate) {
    dcrCheck('whole spaced date retains the exact explicit calendar value',
        DateParser::resolveDate($completeDate) === DateParser::resolveDate('17.12.'.$explicitYear));
}
foreach ([
    '31 04 '.$explicitYear, '29 02 2030', '17 13 '.$explicitYear,
    '17 12', '17 12 30', '17 12 0000', '17 12 0030',
    '17 12 '.$explicitYear.'?', 'не 17 12 '.$explicitYear,
    'вылет 17 12 '.$explicitYear, '17 12 '.$explicitYear.' на 8 ночей',
    '17-18 12 '.$explicitYear, '17 12 '.$explicitYear.' или 18 12 '.$explicitYear,
    "17\n12\n".$explicitYear, '2 взрослых 12 лет 2030',
] as $notAConfirmedDate) {
    dcrCheck('unsupported spaced numeric text cannot invent a departure',
        empty(DateParser::resolveDate($notAConfirmedDate)['date']));
}
dcrCheck('whole explicit correction retains only the positive replacement',
    (DateParser::resolveDate('не 17 12 '.$explicitYear.', а 18 12 '.$explicitYear)['date'] ?? '') === '18.12.'.$explicitYear);
PendingMonthStore::set($chatId, 7, $explicitYear, true);
$completeLocal = AiDateContextService::resolveLocal($chatId, '17 12 '.$explicitYear);
dcrCheck('complete spaced date clears another months pending range',
    $completeLocal['date'] === '17.12.'.$explicitYear && PendingMonthStore::get($chatId) === []);
$completeGuard = AiDateContextService::applyAiGuard($chatId, '17 12 '.$explicitYear,
    ['date'=>'18.07.'.$explicitYear, 'nights'=>'8']);
dcrCheck('complete literal date wins over model guess without changing duration',
    $completeGuard['date'] === '17.12.'.$explicitYear && $completeGuard['nights'] === '8');
PendingMonthStore::set($chatId, 7, $explicitYear, true);
$pastComplete = AiDateContextService::resolveLocal($chatId, '17 12 2001');
dcrCheck('explicit past year is not advanced or allowed to clear clarification',
    $pastComplete['date'] === '17.12.2001' && PendingMonthStore::requiresClarification($chatId));
DateContextResolver::clear($chatId);

// A missing space in a complete short date retains the ordinary date contract.
foreach ([
    ['9апреля 2030', '9 апреля 2030'],
    ['9АПРЕЛЯ 2030', '9 апреля 2030'],
    ['29февраля 2032', '29 февраля 2032'],
    ['31января 2030.', '31 января 2030'],
    ['9апреля', '9 апреля'],
] as [$attached, $spaced]) {
    dcrCheck('attached named date equals ordinary spelling: '.$attached,
        DateParser::resolveDate($attached) === DateParser::resolveDate($spaced));
}
foreach (['31апреля 2030', '29февраля 2030', '9апреля 2030?', 'ребёнку9апреля 2030', '9апреля или 10апреля 2030'] as $unsupportedAttached) {
    dcrCheck('attached spelling cannot invent an invalid or ambiguous day: '.$unsupportedAttached,
        empty(DateParser::resolveDate($unsupportedAttached)['date']));
}
DateContextResolver::rememberMonth($chatId, 12, 2030);
$attachedLocal = AiDateContextService::resolveLocal($chatId, '9апреля 2030');
dcrCheck('attached date completes actual local parsing and clears stale month',
    $attachedLocal['date'] === '09.04.2030' && PendingMonthStore::get($chatId) === []);
$attachedGuard = AiDateContextService::applyAiGuard($chatId, '9апреля 2030', ['date'=>'20.12.2030','nights'=>'11']);
dcrCheck('attached date overrides conflicting model date without losing nights',
    $attachedGuard['date'] === '09.04.2030' && $attachedGuard['nights'] === '11');
DateContextResolver::rememberMonth($chatId, 7, 2030);
PendingMonthStore::set($chatId, 7, 2030, true);
$attachedClarification = AiDateContextService::resolveLocal($chatId, '9апреля 2030');
dcrCheck('attached explicit date also completes pending window clarification',
    $attachedClarification['date'] === '09.04.2030' && PendingMonthStore::get($chatId) === []);
// A failed negative control must not leak its pending month into later cases.
DateContextResolver::clear($chatId);

// Synthetic cross-month windows cannot borrow a phase from another month.
foreach (['конец июня — начало июля', 'июль или июнь', 'с середины августа до начала сентября'] as $window) {
    DateContextResolver::rememberMonth($chatId, 12, 2030);
    $windowLocal = AiDateContextService::resolveLocal($chatId, $window);
    dcrCheck('two-month window requires clarification: ' . $window,
        !empty($windowLocal['ambiguous_months']) && $windowLocal['date'] === '' && !$windowLocal['month_only']);
    dcrCheck('two-month window discards stale pending month', PendingMonthStore::get($chatId) === []);
    $windowGuard = AiDateContextService::applyAiGuard($chatId, $window, ['date'=>'05.06.2030', 'nights'=>'8']);
    dcrCheck('AI cannot invent one departure for two months', $windowGuard['date'] === null && $windowGuard['nights'] === '8');
}
dcrCheck('single-month phase still resolves in its own month',
    (DateParser::resolveDate('начало июля 2030')['date'] ?? '') === '05.07.2030');
$clarified = AiDateContextService::resolveLocal($chatId, '29.06.2030');
dcrCheck('explicit clarification resolves without a stale pending month', $clarified['date'] === '29.06.2030');


// Synthetic spellings and replacements; no real tourist transcript in fixtures.
foreach (['17ое августа 2030', '17ого августа 2030', '17-го августа 2030', '17-е августа 2030'] as $ordinal) {
    dcrCheck('ordinal day before named month: ' . $ordinal,
        (DateParser::resolveDate($ordinal)['date'] ?? '') === '17.08.2030');
}
dcrCheck('ordinal parsing still rejects an impossible calendar day',
    empty(DateParser::resolveDate('31ое февраля 2030')['date']));
dcrCheck('ordinal date does not turn a child age into a departure day',
    empty(DateParser::resolveDate('ребёнку 5 лет, август 2030')['date']));
foreach ([
    ['не 12.03.2030, а 19.04.2030', '19.04.2030'],
    ['Не 12 марта 2030 а 19 апреля 2030', '19.04.2030'],
    ['не 12 марта, а 19ое апреля 2030', '19.04.2030'],
] as [$correction, $expectedDate]) {
    dcrCheck('replacement date wins over rejected first literal: ' . $correction,
        (DateParser::resolveDate($correction)['date'] ?? '') === $expectedDate);
    $guard = AiDateContextService::applyAiGuard($chatId, $correction, ['date'=>'12.03.2030', 'nights'=>'9']);
    dcrCheck('canonical guard keeps replacement and unrelated nights',
        ($guard['date'] ?? '') === $expectedDate && $guard['nights'] === '9');
}
dcrCheck('invalid replacement never falls back to rejected old date',
    empty(DateParser::resolveDate('не 12.03.2030, а 31.02.2030')['date']));
DateContextResolver::rememberMonth($chatId, 12, 2030);
$ordinalLocal = AiDateContextService::resolveLocal($chatId, '17ое августа 2030');
dcrCheck('ordinal day passes through actual local date boundary',
    $ordinalLocal['date'] === '17.08.2030' && PendingMonthStore::get($chatId) === []);

$monthOnly = DateContextResolver::resolveFromText($chatId, 'в декабре 2026');
dcrCheck('month-only text is recognized without inventing a day', empty($monthOnly['date']) && ($monthOnly['month'] ?? null) === 12 && ($monthOnly['year'] ?? null) === 2026);
$pending = PendingMonthStore::get($chatId);
dcrCheck('month-only recognition stores pending context', ($pending['month'] ?? null) === 12 && ($pending['year'] ?? null) === 2026);

dcrCheck('pending short day resolves against remembered month', DateContextResolver::resolvePendingShortDate($chatId, '14') === '14.12.2026');
dcrCheck('successful short day clears pending context', PendingMonthStore::get($chatId) === []);

DateContextResolver::resolveFromText($chatId, 'декабрь 2026');
dcrCheck('pending day range keeps existing midpoint semantics', DateContextResolver::resolvePendingShortDate($chatId, '28-31') === '30.12.2026');

DateContextResolver::resolveFromText($chatId, 'декабрь 2026');
dcrCheck('pending end-of-month keeps existing anchor semantics', DateContextResolver::resolvePendingShortDate($chatId, 'конец месяца') === '28.12.2026');

DateContextResolver::resolveFromText($chatId, 'декабрь 2026');
dcrCheck('invalid short day is rejected', DateContextResolver::resolvePendingShortDate($chatId, '99') === '');
$pendingAfterInvalid = PendingMonthStore::get($chatId);
dcrCheck('invalid clarification does not discard pending month', ($pendingAfterInvalid['month'] ?? null) === 12 && ($pendingAfterInvalid['year'] ?? null) === 2026);

$exact = DateContextResolver::resolveFromText($chatId, '15.11.2026');
dcrCheck('explicit full date keeps DateParser canonical value', ($exact['date'] ?? '') === '15.11.2026');
dcrCheck('explicit full date clears older pending context', PendingMonthStore::get($chatId) === []);

DateContextResolver::clear($chatId);
$local = AiDateContextService::resolveLocal($chatId, 'в декабре 2026');
dcrCheck('AI local date policy exposes month-only clarification', ($local['date'] ?? null) === '' && !empty($local['month_only']));
$guarded = AiDateContextService::applyAiGuard($chatId, 'в декабре 2026', ['date'=>'15.11.2026']);
dcrCheck('AI date guard rejects invented date when user named only month', array_key_exists('date', $guarded) && $guarded['date'] === null);
$guarded = AiDateContextService::applyAiGuard($chatId, '15.11.2026', ['date'=>'20.12.2026']);
dcrCheck('explicit user date overrides conflicting AI date', ($guarded['date'] ?? '') === '15.11.2026');
DateContextResolver::resolveFromText($chatId, 'декабрь 2026');
$guarded = AiDateContextService::applyAiGuard($chatId, 'без даты в тексте', ['date'=>'20.12.2026']);
dcrCheck('AI date without user month remains allowed', ($guarded['date'] ?? '') === '20.12.2026');
dcrCheck('allowed AI date clears older pending month', PendingMonthStore::get($chatId) === []);


// Synthetic named-month windows must not become their last endpoint or midpoint.
// Only the new clarification flow is stateful; ordinary numeric policies above stay intact.
DateContextResolver::clear($chatId);
foreach (['с 17 по 26 июля 2030', 'с17по26 июля 2030', '17–26 июля 2030', '17 - 26 июля 2030', 'с 17-го по 26ое июля 2030', '2 взрослых, с 17 до 26 июля 2030 на 9 ночей'] as $window) {
    $parsed = DateParser::resolveDate($window);
    dcrCheck('named window needs clarification: ' . $window,
        !empty($parsed['ambiguous_range']) && empty($parsed['date']) && $parsed['month'] === 7 && $parsed['year'] === 2030);
}
foreach (['с 26 по 17 июля 2030', 'с 31 по 35 февраля 2030'] as $invalidWindow) {
    dcrCheck('invalid/reversed named interval cannot select an endpoint',
        !empty(DateParser::resolveDate($invalidWindow)['ambiguous_range'])
        && empty(DateParser::resolveDate($invalidWindow)['date']));
}
dcrCheck('same-day named interval stays unambiguous',
    (DateParser::resolveDate('17–17 июля 2030')['date'] ?? '') === '17.07.2030');
DateContextResolver::rememberMonth($chatId, 12, 2030);
$rangeLocal = AiDateContextService::resolveLocal($chatId, 'с 17 по 26 июля 2030');
dcrCheck('range replaces an obsolete pending month without guessing a departure',
    !empty($rangeLocal['ambiguous_range']) && $rangeLocal['date'] === ''
    && PendingMonthStore::get($chatId) === ['month'=>7,'year'=>2030,'requires_clarification'=>true]);
$guardedWindow = AiDateContextService::applyAiGuard($chatId, '2 взрослых', ['date'=>'26.07.2030','adults'=>2,'nights'=>'9']);
dcrCheck('later unrelated answer cannot restore an AI-guessed departure',
    $guardedWindow === ['date'=>null,'adults'=>2,'nights'=>'9'] && PendingMonthStore::requiresClarification($chatId));
$questionContext = AiDateContextService::resolveLocal($chatId, 'Есть ли бассейн?');
dcrCheck('pending clarification does not misclassify a later FAQ as another literal range',
    empty($questionContext['ambiguous_range']) && PendingMonthStore::requiresClarification($chatId));
dcrCheck('repeating a short interval does not turn it into a midpoint in this flow',
    DateContextResolver::resolvePendingShortDate($chatId, '17-26') === '' && PendingMonthStore::requiresClarification($chatId));
dcrCheck('clarification state remains scoped to its own dialogue',
    !PendingMonthStore::requiresClarification($chatId - 1));
DateContextResolver::rememberMonth($chatId, 8, 2030);
dcrCheck('compatibility month updates retain the unresolved departure',
    PendingMonthStore::get($chatId) === ['month'=>8,'year'=>2030,'requires_clarification'=>true]);
$severalMonths = AiDateContextService::resolveLocal($chatId, 'июль или август 2030');
dcrCheck('later multiple months cannot inherit the previous range month',
    !empty($severalMonths['ambiguous_months'])
    && PendingMonthStore::get($chatId) === ['month'=>0,'year'=>0,'requires_clarification'=>true]);
dcrCheck('bare day with an unresolved month does not acquire the previous month',
    DateContextResolver::resolvePendingShortDate($chatId, '18') === '');
AiDateContextService::resolveLocal($chatId, 'август 2030');
dcrCheck('a newly specified month retains the unresolved day',
    PendingMonthStore::get($chatId) === ['month'=>8,'year'=>2030,'requires_clarification'=>true]);
dcrCheck('explicit day finishes the clarified month once',
    DateContextResolver::resolvePendingShortDate($chatId, '18') === '18.08.2030' && PendingMonthStore::get($chatId) === []);
AiDateContextService::resolveLocal($chatId, 'с 17 по 26 июля 2030');
$exactGuard = AiDateContextService::applyAiGuard($chatId, '17.07.2030', ['date'=>'26.07.2030']);
dcrCheck('explicit full date clears range clarification and overrides the model',
    $exactGuard['date'] === '17.07.2030' && PendingMonthStore::get($chatId) === []);
AiDateContextService::resolveLocal($chatId, 'с 17 по 26 июля 2030');
$rejectedPast = AiDateContextService::applyAiGuard($chatId, '17.07.2020', ['date'=>'26.07.2030']);
dcrCheck('a past full date rejected by current admission does not expose old saved needs',
    $rejectedPast['date'] === '17.07.2020' && PendingMonthStore::requiresClarification($chatId));
AiDateContextService::resolveLocal($chatId, 'с 17 по 26 июля 2020');
dcrCheck('a past short date retains clarification until an admissible departure is supplied',
    DateContextResolver::resolvePendingShortDate($chatId, '18') === '18.07.2020'
    && PendingMonthStore::requiresClarification($chatId));
DateContextResolver::clear($chatId);
dcrCheck('existing date/reset clearing removes the new pending state', PendingMonthStore::get($chatId) === []);
$routeHandler = (string)file_get_contents(dirname(__DIR__).'/handlers/DepartureRouteAdviceHandler.php');
$rangeDecline = strpos($routeHandler, "if (!empty(\$dateInfo['ambiguous_range'])) return false;");
dcrCheck('route advice leaves named-window clarification to its canonical owner before constructing advice',
    $rangeDecline !== false && $rangeDecline < strpos($routeHandler, 'new DepartureRouteResolver()'));

$handler = (string)file_get_contents(__DIR__ . '/../handlers/AiDateHandler.php');
dcrCheck('AI date handler delegates to shared date context resolver', strpos($handler, 'DateContextResolver::resolveFromText') !== false && strpos($handler, 'DateContextResolver::resolvePendingShortDate') !== false);
dcrCheck('AI date handler no longer owns pending store directly', strpos($handler, 'PendingMonthStore::') === false);

$messageHandler = (string)file_get_contents(__DIR__ . '/../handlers/AiMessageHandler.php');
dcrCheck('message handler routes local date policy through service', strpos($messageHandler, 'AiDateContextService::resolveLocal') !== false);
dcrCheck('message handler routes AI date guard through service', strpos($messageHandler, 'AiDateContextService::applyAiGuard') !== false);
dcrCheck('message handler no longer owns resolveFromText date policy', strpos($messageHandler, 'AiDateHandler::rememberMonthFromText') === false);
dcrCheck('pending short date uses canonical application boundary', strpos($messageHandler, 'NeedApplicationService::applyParameters') !== false && strpos($messageHandler, "['date'=>\$shortDateValue]") !== false);
dcrCheck('pending short date uses canonical progression boundary', strpos($messageHandler, 'NeedProgressionService::advance($chat_id)') !== false);
dcrCheck('message handler no longer writes pending date directly', strpos($messageHandler, 'MaxSearchApi::saveLastValue') === false && strpos($messageHandler, 'MaxSearchApi::$statusDate') === false);

$stateHandler = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
$dateBranchStart = strpos($stateHandler, 'elseif($status==MaxSearchApi::$statusDate)');
$dateBranchEnd = $dateBranchStart === false ? false : strpos($stateHandler, 'elseif($status==MaxSearchApi::$statusPhone)', $dateBranchStart);
$dateBranch = ($dateBranchStart === false || $dateBranchEnd === false) ? '' : substr($stateHandler, $dateBranchStart, $dateBranchEnd - $dateBranchStart);
dcrCheck('explicit wizard date branch exists', $dateBranch !== '');
dcrCheck('explicit wizard date completion uses canonical progression', strpos($dateBranch, 'NeedProgressionService::advance($chat_id);') !== false);
dcrCheck('explicit wizard date completion no longer bypasses optional-budget progression', strpos($dateBranch, 'DialogueView::check($chat_id);') === false);

DateContextResolver::clear($chatId);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
