<?php
require_once __DIR__ . '/../handlers/StateMessageHandler.php';
require_once __DIR__ . '/../services/NightsParser.php';
require_once __DIR__ . '/../services/DateParser.php';

$tests = [
    ['Хочу в Турцию в сентябре', true, 'natural country + month'],
    ['Турция в сентябре', true, 'country + month'],
    ['Хотим из Москвы в Египет на 8 ночей', true, 'full tour request'],
    ['2 взрослых в октябре', true, 'tourists + month'],
    ['Турция', false, 'single country stays wizard value'],
    ['Москва', false, 'single city stays wizard value'],
    ['Самара', false, 'single city stays wizard value'],
    ['', false, 'empty value'],
];

$passed = 0;
$failed = 0;
foreach ($tests as [$text, $expected, $label]) {
    $actual = StateMessageHandler::shouldRouteFreeTextToAi($text);
    if ($actual === $expected) {
        echo "PASS  {$label}\n";
        $passed++;
    } else {
        echo "FAIL  {$label}\n";
        echo '      text: ' . $text . "\n";
        echo '      expected: ' . var_export($expected, true) . "\n";
        echo '      actual:   ' . var_export($actual, true) . "\n";
        $failed++;
    }
}

// A natural answer to the explicit child-count question must use the same
// deterministic resolver as the AI collector instead of disappearing silently.
$childrenFreeTextTests = [
    ['Нет', 0, 'live natural no-children answer'],
    ['без детей', 0, 'natural no-children phrase'],
    ['1 ребёнок', 1, 'one child phrase'],
    ['двое детей', 2, 'word child count'],
    ['3', 3, 'plain child count'],
    ['четверо', null, 'unsupported child count is rejected'],
];
foreach ($childrenFreeTextTests as [$text, $expected, $label]) {
    $resolved = NeedValueResolver::resolve('children', $text);
    $actual = !empty($resolved['recognized']) ? (int)$resolved['value'] : null;
    if ($actual === $expected) { echo "PASS  {$label}\n"; $passed++; }
    else {
        echo "FAIL  {$label}\n";
        echo '      text: ' . $text . "\n";
        echo '      expected: ' . var_export($expected, true) . "\n";
        echo '      actual:   ' . var_export($actual, true) . "\n";
        $failed++;
    }
}

// Keep exact live nights phrases in required CI. Conversation 308 exposed the
// prefixed range "От 7-9"; conversation 484 exposed the natural short range
// "8 9"; conversation 555 exposed comma-separated short ranges such as "3,4";
// a fresh #780 history exposed repeated-dot shorthand "8..9"; a later protected
// #780 history exposed an explicit approximation prefix before one valid count.
// These must not force the tourist to re-enter an otherwise unambiguous duration.
$nightsTests = [
    ['6', '6', 'plain nights'],
    ['На 6', '6', 'live MAX phrase На 6'],
    ['6 ночей', '6', 'nights with noun'],
    ['на 6 ночей', '6', 'natural nights phrase'],
    ['примерно 5', '5', 'approximate single nights'],
    ['примерно 5 ночей', '5', 'approximate single nights with noun'],
    ['7-10', '7-10', 'plain nights range'],
    ['на 7–10 ночей', '7-10', 'natural range with en dash'],
    ['От 7-9', '7-9', 'live AI phrase prefixed range'],
    ['от 7–9 ночей', '7-9', 'prefixed range with noun and en dash'],
    ['8 9', '8-9', 'live MAX whitespace nights range'],
    ['8 9 ночей', '8-9', 'whitespace nights range with noun'],
    ['3,4', '3-4', 'live MAX comma nights range'],
    ['2,3', '2-3', 'live MAX second comma nights range'],
    ['2,3 д', '2-3', 'live MAX comma duration with short day suffix'],
    ['8..9', '8-9', 'live MAX repeated-dot nights range'],
    ['8.9', '', 'single-dot value stays invalid'],
    ['1.10', '', 'date-like value is not reinterpreted as nights'],
    ['1/10', '', 'slash date-like value is not reinterpreted as nights'],
    ['10,8', '', 'reject reversed comma range'],
    ['8,29', '', 'reject comma range above nights limit'],
    ['10 8', '', 'reject reversed whitespace range'],
    ['8 29', '', 'reject whitespace range above nights limit'],
    ['от 7', '', 'do not invent upper bound from minimum-only phrase'],
    ['неделя', '7', 'week synonym'],
    ['на неделю', '7', 'natural week synonym'],
    ['29', '', 'reject too many nights'],
    ['примерно 29', '', 'reject approximate value above nights limit'],
    ['10-7', '', 'reject reversed range'],
];
foreach ($nightsTests as [$text, $expected, $label]) {
    $actual = NightsParser::parse($text);
    if ($actual === $expected) { echo "PASS  {$label}\n"; $passed++; }
    else {
        echo "FAIL  {$label}\n";
        echo '      text: ' . $text . "\n";
        echo '      expected: ' . var_export($expected, true) . "\n";
        echo '      actual:   ' . var_export($actual, true) . "\n";
        $failed++;
    }
}

$novemberYear = 11 < (int)date('n') ? ((int)date('Y') + 1) : (int)date('Y');
$dateResolved = DateParser::resolveDate('8 Ноября');
$expectedDate = sprintf('08.11.%04d', $novemberYear);
if (($dateResolved['date'] ?? '') === $expectedDate) {
    echo "PASS  live date phrase 8 Ноября\n";
    $passed++;
} else {
    echo "FAIL  live date phrase 8 Ноября\n";
    echo '      expected: ' . $expectedDate . "\n";
    echo '      actual:   ' . var_export($dateResolved, true) . "\n";
    $failed++;
}

// Production conversation 274 exposed a same-month rollover defect: on 26 August,
// "5 августа" was parsed as 05.08 of the already-past current year, producing a
// reversed search window. Keep this regression calendar-independent by testing a
// previous day of the current month whenever such a day exists.
$todayDay = (int)date('j');
if ($todayDay > 1) {
    $monthNames = [1=>'января',2=>'февраля',3=>'марта',4=>'апреля',5=>'мая',6=>'июня',7=>'июля',8=>'августа',9=>'сентября',10=>'октября',11=>'ноября',12=>'декабря'];
    $pastDay = $todayDay - 1;
    $month = (int)date('n');
    $year = (int)date('Y');
    $phrase = $pastDay . ' ' . $monthNames[$month];
    $resolved = DateParser::resolveDate($phrase);
    $expected = sprintf('%02d.%02d.%04d', $pastDay, $month, $year + 1);
    if (($resolved['date'] ?? '') === $expected) {
        echo "PASS  implicit past natural date rolls to next year\n";
        $passed++;
    } else {
        echo "FAIL  implicit past natural date rolls to next year\n";
        echo '      phrase: ' . $phrase . "\n";
        echo '      expected: ' . $expected . "\n";
        echo '      actual:   ' . var_export($resolved, true) . "\n";
        $failed++;
    }

    $explicitPhrase = $pastDay . ' ' . $monthNames[$month] . ' ' . $year;
    $explicitResolved = DateParser::resolveDate($explicitPhrase);
    $explicitExpected = sprintf('%02d.%02d.%04d', $pastDay, $month, $year);
    if (($explicitResolved['date'] ?? '') === $explicitExpected) {
        echo "PASS  explicit past natural year is preserved\n";
        $passed++;
    } else {
        echo "FAIL  explicit past natural year is preserved\n";
        echo '      expected: ' . $explicitExpected . "\n";
        echo '      actual:   ' . var_export($explicitResolved, true) . "\n";
        $failed++;
    }
}

$source = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
$aiShortSource = (string)file_get_contents(__DIR__ . '/../handlers/AiShortAnswerHandler.php');
$guards = [
    'country fallback invokes free-text routing' => strpos($source, 'elseif(self::shouldRouteFreeTextToAi($country))') !== false,
    'city fallback invokes free-text routing' => strpos($source, 'elseif(self::shouldRouteFreeTextToAi($city))') !== false,
    'free text switches to AI status' => strpos($source, 'MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusAi);') !== false,
    'free text reaches AiMessageHandler' => strpos($source, 'AiMessageHandler::handle($message, $chatId);') !== false,
    'wizard child step accepts free text' => strpos($source, 'elseif($status==MaxSearchApi::$statusChild)') !== false,
    'wizard child step uses canonical resolver/application boundary' => strpos($source, "NeedApplicationService::resolveAndApplyExistingWizardStep(\n                    \$chat_id,\n                    'children',") !== false,
    'wizard child step keeps existing-step status id' => strpos($source, "'children',\n                    (string)(\$message['text'] ?? ''),\n                    (int)MaxSearchApi::\$statusChild") !== false,
    'zero children advances without age question' => strpos($source, "EditFlowService::finishIfNeeded(\$chat_id,'tourists')") !== false && strpos($source, 'MaxSearchApi::showStarsButtons($chat_id);') !== false,
    'positive child count advances to ages' => strpos($source, 'MaxSearchApi::showAgeButtons($chat_id,$children);') !== false,
    'wizard nights uses deterministic resolver' => strpos($source, "NeedValueResolver::resolve('nights'") !== false,
    'wizard nights uses existing-step application boundary' => strpos($source, 'ExistingWizardStepApplicationService::apply(') !== false,
    'AI short nights uses NeedApplicationService boundary' => strpos($aiShortSource, 'NeedApplicationService::resolveAndApply($chat_id, $field, $lower)') !== false,
    'date state accepts free-text path' => strpos($source, 'elseif($status==MaxSearchApi::$statusDate)') !== false,
    'date state uses pending short-date resolver' => strpos($source, 'AiDateHandler::resolvePendingShortDate(') !== false,
    'date state resolves natural month text' => strpos($source, 'AiDateHandler::rememberMonthFromText(') !== false,
    'wizard date uses exact value contract' => strpos($source, 'DateValueContract::fromStorageValue($date)') !== false,
    'wizard date uses existing-step application boundary' => strpos($source, 'MaxSearchApi::$statusDate,') !== false && strpos($source, '$dateValue') !== false,
    'wizard date no longer directly writes a value' => strpos($source, 'MaxSearchApi::saveLastValue($chat_id, MaxSearchApi::$statusDate, $date);') === false,
    'resolved date reaches canonical progression' => strpos($source, "EditFlowService::finishIfNeeded(\$chat_id,'date')") !== false && strpos($source, 'NeedProgressionService::advance($chat_id);') !== false,
];
foreach ($guards as $label => $ok) {
    if ($ok) { echo "PASS  {$label}\n"; $passed++; }
    else { echo "FAIL  {$label}\n"; $failed++; }
}

echo "\n----------------------------------------\n";
echo "TOTAL " . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);