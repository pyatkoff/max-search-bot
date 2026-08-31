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

echo "\n--------------------------\n";
echo 'TOTAL ' . count($checks) . ' | PASS ' . (count($checks) - $failed) . ' | FAIL ' . $failed . "\n";
exit($failed > 0 ? 1 : 0);
