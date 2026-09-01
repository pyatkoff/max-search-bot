<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/AiBusinessDefaultsService.php';

$passed = 0;
$failed = 0;
function abdCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) {
        echo "PASS  {$name}\n";
        $passed++;
        return;
    }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

$r = AiBusinessDefaultsService::apply(['parameters'=>[]], 'Хочу в Грецию', []);
abdCheck('missing departure defaults to Moscow', $r['parameters']['city'] ?? null, 'Москва');

$r = AiBusinessDefaultsService::apply(['parameters'=>[]], 'Хотим вдвоём', ['city'=>'Калининград']);
abdCheck('pair phrase defaults adults', $r['parameters']['adults'] ?? null, 2);
abdCheck('pair phrase defaults children zero', $r['parameters']['children'] ?? null, 0);

$r = AiBusinessDefaultsService::apply(['parameters'=>['adults'=>3,'children'=>1]], 'на двоих', ['city'=>'Москва']);
abdCheck('explicit AI adults preserved', $r['parameters']['adults'] ?? null, 3);
abdCheck('explicit AI children preserved', $r['parameters']['children'] ?? null, 1);

$r = AiBusinessDefaultsService::apply(
    ['parameters'=>['city'=>'Москва','country'=>'Турция','adults'=>2,'children'=>1,'child_ages'=>null]],
    'Хотим из Москвы в Турцию в конце сентября, 2 взрослых и ребёнок 6 лет',
    []
);
abdCheck('live rich single child age recovered', $r['parameters']['child_ages'] ?? null, [6]);
abdCheck('live rich child count remains one', $r['parameters']['children'] ?? null, 1);

$r = AiBusinessDefaultsService::apply(
    ['parameters'=>['children'=>1,'child_ages'=>[8]]],
    '2 взрослых и ребёнок 6 лет',
    []
);
abdCheck('explicit AI child age is never overridden', $r['parameters']['child_ages'] ?? null, [8]);

$r = AiBusinessDefaultsService::apply(
    ['parameters'=>['children'=>2,'child_ages'=>null]],
    '2 взрослых и ребёнок 6 лет',
    []
);
abdCheck('single child phrase does not override multi-child composition', $r['parameters']['child_ages'] ?? null, null);

$r = AiBusinessDefaultsService::apply(['parameters'=>['country'=>'Египет']], 'Египет', ['city'=>'Москва']);
abdCheck('Egypt defaults all inclusive', $r['parameters']['meal'] ?? null, 'all_inclusive');
abdCheck('Egypt defaults four stars', $r['parameters']['stars'] ?? null, 4);

$r = AiBusinessDefaultsService::apply(['parameters'=>['country'=>'Турция','meal'=>'breakfast','stars'=>5]], 'Турция', ['city'=>'Москва']);
abdCheck('explicit meal preserved', $r['parameters']['meal'] ?? null, 'breakfast');
abdCheck('explicit stars preserved', $r['parameters']['stars'] ?? null, 5);

$r = AiBusinessDefaultsService::apply(
    ['parameters'=>[]],
    'Из Москвы Хургада, Эль каусер, 2 чел предпочтительно пикальбатрос с 28 сентября +-',
    []
);
abdCheck('live rich Hurghada request deterministically seeds Egypt', $r['parameters']['country'] ?? null, 'Египет');
abdCheck('seeded Egypt receives existing meal default', $r['parameters']['meal'] ?? null, 'all_inclusive');
abdCheck('seeded Egypt receives existing star default', $r['parameters']['stars'] ?? null, 4);

$r = AiBusinessDefaultsService::apply(['parameters'=>[]], 'Хотим в Эль-Кусейр', ['city'=>'Москва']);
abdCheck('El Quseir transliteration seeds Egypt', $r['parameters']['country'] ?? null, 'Египет');

$r = AiBusinessDefaultsService::apply(['parameters'=>['country'=>'Турция']], 'Хургада или что-то похожее', ['city'=>'Москва']);
abdCheck('explicit AI country is never overridden by resort hint', $r['parameters']['country'] ?? null, 'Турция');

$r = AiBusinessDefaultsService::apply(['parameters'=>[]], 'Хургада', ['city'=>'Москва','country'=>'ОАЭ']);
abdCheck('current country is never overridden by resort hint', array_key_exists('country', $r['parameters']), false);

$r = AiBusinessDefaultsService::apply(['_error'=>true], 'Египет', []);
abdCheck('error payload preserved', $r, ['_error'=>true]);

$source = (string)file_get_contents(__DIR__ . '/../handlers/AiMessageHandler.php');
abdCheck('handler uses defaults service', strpos($source, 'AiBusinessDefaultsService::apply($ai, $userText, $current)') !== false, true);
abdCheck('handler no longer owns Turkey Egypt defaults', strpos($source, "in_array(\$countryKey, ['турция','египет'], true)") === false, true);

echo "\n--------------------------\n";
echo 'TOTAL '.($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
