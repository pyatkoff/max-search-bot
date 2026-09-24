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
$projection = new ReflectionMethod(DestinationResolver::class, 'destinationPreferenceChanges');
$projection->setAccessible(true);

$cases = [
    'explicit hotel choice becomes canonical preference' => [
        ['hotel'=>'Rixos Premium Tekirova', 'region'=>'Кемер'],
        'Хочу отель Rixos Premium Tekirova',
        ['preferences'=>[], 'negative_preferences'=>[]],
        false,
        false,
        ['preferences'=>['Отель: Rixos Premium Tekirova']],
    ],
    'hard hotel condition stays visibly hard' => [
        ['hotel'=>'Rixos Premium Tekirova', 'region'=>'Кемер'],
        'Только Rixos Premium Tekirova, обязательно этот отель',
        ['preferences'=>[], 'negative_preferences'=>[]],
        false,
        false,
        ['preferences'=>['Обязательно — отель: Rixos Premium Tekirova']],
    ],
    'explicit hotel exclusion stays negative' => [
        ['hotel'=>'Rixos Premium Tekirova', 'region'=>'Кемер'],
        'Не хочу отель Rixos Premium Tekirova',
        ['preferences'=>[], 'negative_preferences'=>[]],
        false,
        false,
        ['negative_preferences'=>['Исключить отель: Rixos Premium Tekirova']],
    ],
    'neutral hotel question does not invent a preference' => [
        ['hotel'=>'Rixos Premium Tekirova', 'region'=>'Кемер'],
        'Что скажете про отель Rixos Premium Tekirova?',
        ['preferences'=>[], 'negative_preferences'=>[]],
        false,
        false,
        [],
    ],
    'new hotel replaces only previous resolver-owned hotel condition' => [
        ['hotel'=>'Akka Antedon', 'region'=>'Кемер'],
        'Давайте отель Akka Antedon',
        [
            'preferences'=>['первая линия', 'Отель: Rixos Premium Tekirova'],
            'negative_preferences'=>['шумный отель'],
        ],
        false,
        false,
        [
            'preferences'=>['Отель: Akka Antedon'],
            'preferences_remove'=>['Отель: Rixos Premium Tekirova'],
        ],
    ],
    'hotel no longer important removes old hotel without making it forbidden' => [
        ['hotel'=>'', 'region'=>'Кемер'],
        'Отель уже не важен, можно любой',
        [
            'preferences'=>['Отель: Rixos Premium Tekirova'],
            'negative_preferences'=>[],
        ],
        false,
        false,
        ['preferences_remove'=>['Отель: Rixos Premium Tekirova']],
    ],
    'explicit resort is kept for manager context' => [
        ['hotel'=>'', 'region'=>'Кемер'],
        'Хочу в Кемер',
        ['preferences'=>[], 'negative_preferences'=>[]],
        false,
        false,
        ['preferences'=>['Курорт/район: Кемер']],
    ],
    'hard resort condition stays visibly hard' => [
        ['hotel'=>'', 'region'=>'Кемер'],
        'Только Кемер',
        ['preferences'=>[], 'negative_preferences'=>[]],
        false,
        false,
        ['preferences'=>['Обязательно — курорт/район: Кемер']],
    ],
    'country correction clears only resolver-owned resort and hotel labels' => [
        ['hotel'=>'', 'region'=>''],
        'Давайте Египет',
        [
            'preferences'=>['первая линия', 'Курорт/район: Кемер', 'Обязательно — отель: Rixos Premium Tekirova'],
            'negative_preferences'=>['Исключить курорт/район: Белек', 'без шумной дороги'],
        ],
        true,
        false,
        [
            'preferences_remove'=>['Курорт/район: Кемер', 'Обязательно — отель: Rixos Premium Tekirova'],
            'negative_preferences_remove'=>['Исключить курорт/район: Белек'],
        ],
    ],
];

foreach ($cases as $name => [$data, $text, $current, $countryChanged, $regionChanged, $expected]) {
    $actual = $projection->invoke(null, $data, $text, $current, $countryChanged, $regionChanged);
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

$runtimeChecks = [
    'resolver applies explicit destination conditions through canonical need application' => str_contains($source, 'NeedApplicationService::applyExtractedPreferences'),
    'resolver does not add another destination metadata store' => !str_contains($source, "'_destination_preferences'") && !str_contains($source, 'destination_preferences.json'),
];
foreach ($runtimeChecks as $name => $ok) {
    if ($ok) {
        echo "PASS  {$name}\n";
    } else {
        echo "FAIL  {$name}\n";
        $failed++;
    }
}

$total = count($checks) + count($cases) + count($countryChoiceCases) + count($runtimeChecks);
echo "\n--------------------------\n";
echo 'TOTAL ' . $total . ' | PASS ' . ($total - $failed) . ' | FAIL ' . $failed . "\n";
exit($failed > 0 ? 1 : 0);
