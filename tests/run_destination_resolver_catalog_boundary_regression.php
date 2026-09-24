<?php

declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../services/DestinationResolver.php');

$checks = [
    'resolver requires catalog repository' => str_contains($source, "DestinationCatalogRepository.php"),
    'resolver routes queries through catalog repository' => str_contains($source, 'DestinationCatalogRepository::query'),
    'resolver no longer owns Bitrix highload lookup' => !str_contains($source, 'HighloadBlockTable'),
    'resolver no longer loads highloadblock module directly' => !str_contains($source, "includeModule('highloadblock')"),
];

$failed = 0;
foreach ($checks as $name => $ok) {
    if ($ok) {
        echo "PASS  {$name}\n";
    } else {
        echo "FAIL  {$name}\n";
        $failed++;
    }
}

require_once __DIR__ . '/../services/DestinationResolver.php';
$turkey = ['UF_CID'=>4, 'UF_NAME'=>'Турция'];
$egypt = ['UF_CID'=>3, 'UF_NAME'=>'Египет'];
$countryChoiceCases = [
    'explicit new country choice replaces already saved country' => [$turkey, $egypt, 'Давайте Египет', $egypt],
    'explicit now-country correction replaces already saved country' => [$turkey, $egypt, 'Нет, теперь Египет', $egypt],
    'destination preposition can explicitly replace already saved country' => [$turkey, $egypt, 'Тогда в Египет', $egypt],
    'negative country mention does not overwrite saved country' => [$turkey, $egypt, 'Не Египет', $turkey],
    'hard negative country mention does not overwrite saved country' => [$turkey, $egypt, 'Только не Египет', $turkey],
    'neutral country question does not overwrite saved country' => [$turkey, $egypt, 'А Египет?', $turkey],
    'positive country mention is accepted when country was unknown' => [null, $egypt, 'Хочу Египет', $egypt],
    'negative country mention stays unknown when country was unknown' => [null, $egypt, 'Не Египет', null],
];

if (!method_exists(DestinationResolver::class, 'selectCountryCandidate')) {
    foreach ($countryChoiceCases as $name => $_) {
        echo "FAIL  {$name}\n";
        echo "      missing intent-aware country candidate selection\n";
        $failed++;
    }
} else {
    $countrySelector = new ReflectionMethod(DestinationResolver::class, 'selectCountryCandidate');
    $countrySelector->setAccessible(true);
    foreach ($countryChoiceCases as $name => [$currentCountry, $mentionedCountry, $text, $expected]) {
        $actual = $countrySelector->invoke(null, $currentCountry, $mentionedCountry, $text);
        $ok = $actual === $expected;
        if ($ok) {
            echo "PASS  {$name}\n";
        } else {
            echo "FAIL  {$name}\n";
            echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
            echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
            $failed++;
        }
    }
}

$total = count($checks) + count($countryChoiceCases);
echo "\n--------------------------\n";
echo 'TOTAL ' . $total . ' | PASS ' . ($total - $failed) . ' | FAIL ' . $failed . "\n";
exit($failed > 0 ? 1 : 0);
