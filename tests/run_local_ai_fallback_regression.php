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

$liveRich = 'Хотим из Москвы в Турцию в конце сентября, 2 взрослых и ребёнок 6 лет';
$params = LocalAiFallbackService::parameters($liveRich, []);
localCheck('live rich request deterministically keeps explicit country', $params['country'] ?? null, 'Турция');
localCheck('live rich request deterministically keeps explicit adults', $params['adults'] ?? null, 2);
localCheck('live rich request deterministically infers singular child', $params['children'] ?? null, 1);
localCheck('live rich request deterministically keeps child age next to child wording', $params['child_ages'] ?? null, [6]);

$params = LocalAiFallbackService::parameters('1 взрослый и 2 ребенка', ['city'=>'Москва']);
localCheck('explicit numeric child count in sentence is retained', $params['children'] ?? null, 2);

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
localCheck('rich request resolves explicit departure before external AI', strpos($handler, 'DepartureCityResolver::resolveAndStore($chat_id, $userText)') !== false, true);
localCheck('rich request pre-applies deterministic local parameters before external AI', strpos($handler, 'NeedApplicationService::applyParameters($chat_id, $richLocalParams)') !== false, true);
localCheck('rich preseed never applies legacy Moscow default unless departure was explicit', strpos($handler, "unset(\$richLocalParams['city'])") !== false, true);
localCheck('rich AI receives refreshed deterministic context', strpos($handler, "AiInvocationService::invoke('RICH_AI', \$chat_id, \$userText, \$current)") !== false, true);

$total = $passed + $failed;
echo "\n----------------------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
