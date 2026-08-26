<?php

declare(strict_types=1);
require_once __DIR__ . '/../services/EditFlowService.php';

$failed = 0;
function espCheck(string $name, $actual, $expected): void {
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

// Production scenario from conversation 311: a complete selection existed, the
// country was edited through the manual-country path, and the resulting check
// screen lost all unchanged parameters. Simulate the post-edit state reader only
// seeing the newly edited country while the pre-edit snapshot still has the trip.
$snapshot = [
    65 => '1',
    66 => '12',
    67 => '2',
    68 => '0',
    70 => '5',
    71 => '999',
    72 => '6-8',
    73 => '28.08.2026',
];
$current = [66 => '10']; // newly edited country must win
$restore = EditFlowService::missingSnapshotValues($current, $snapshot, [66]);

espCheck('edited country is never restored from snapshot', array_key_exists(66, $restore), false);
espCheck('city restored', $restore[65] ?? null, '1');
espCheck('adults restored', $restore[67] ?? null, '2');
espCheck('zero children is preserved', array_key_exists(68, $restore) ? $restore[68] : null, '0');
espCheck('stars restored', $restore[70] ?? null, '5');
espCheck('meal restored', $restore[71] ?? null, '999');
espCheck('nights restored', $restore[72] ?? null, '6-8');
espCheck('date restored', $restore[73] ?? null, '28.08.2026');

$editSource = (string)file_get_contents(__DIR__ . '/../actions/callbacks/EditCallbackAction.php');
$controllerSource = (string)file_get_contents(__DIR__ . '/../services/DialogueController.php');
$flowSource = (string)file_get_contents(__DIR__ . '/../services/EditFlowService.php');
espCheck('all edit field entries capture snapshot', substr_count($editSource, 'EditFlowService::begin(') >= 4, true);
espCheck('dialogue reset clears snapshot', strpos($controllerSource, 'EditFlowService::clearSnapshot($chatId)') !== false, true);
espCheck('missing snapshot values are re-appended before check', strpos($flowSource, 'MaxSearchApi::setStatus($chatId, $status)') !== false && strpos($flowSource, 'MaxSearchApi::saveLastValue($chatId, $status, $value)') !== false, true);

exit($failed > 0 ? 1 : 0);
