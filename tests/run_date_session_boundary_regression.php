<?php

declare(strict_types=1);

require_once __DIR__ . '/../handlers/AiDateHandler.php';

$passed = 0;
$failed = 0;
function dsbCheck(string $name, bool $ok): void {
    global $passed, $failed;
    if ($ok) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n"; $failed++;
}

$chatId = -926082701;
AiDateHandler::clear($chatId);
AiDateHandler::rememberMonth($chatId, 9, 2026);
$pending = PendingMonthStore::get($chatId);
dsbCheck('pending month is stored for current date clarification', ($pending['month'] ?? null) === 9 && ($pending['year'] ?? null) === 2026);

AiDateHandler::clear($chatId);
dsbCheck('date transient state can be cleared at session reset', PendingMonthStore::get($chatId) === []);

$controller = (string)file_get_contents(__DIR__ . '/../services/CallbackController.php');
dsbCheck('callback controller loads date transient-state boundary', strpos($controller, "handlers/AiDateHandler.php") !== false);

$restartStart = strpos($controller, "if (\$q === 'restart')");
$restartEnd = $restartStart === false ? false : strpos($controller, "if (\$q === 'back_phone')", $restartStart);
$restartBlock = ($restartStart !== false && $restartEnd !== false) ? substr($controller, $restartStart, $restartEnd - $restartStart) : '';
dsbCheck(
    'restart clears pending month before starting fresh session',
    strpos($restartBlock, 'AiDateHandler::clear($chatId);') !== false
    && strpos($restartBlock, 'MaxSearchApi::deleteAllStatus($chatId);') !== false
    && strpos($restartBlock, 'MaxSearchApi::showStart($chatId);') !== false
    && strpos($restartBlock, 'AiDateHandler::clear($chatId);') < strpos($restartBlock, 'MaxSearchApi::deleteAllStatus($chatId);')
);

$backStart = strpos($controller, "if (\$q === 'back_phone')");
$backEnd = $backStart === false ? false : strpos($controller, 'InteractionGuard::reportSuppressed', $backStart);
$backBlock = ($backStart !== false && $backEnd !== false) ? substr($controller, $backStart, $backEnd - $backStart) : '';
dsbCheck(
    'back phone full reset also clears pending month',
    strpos($backBlock, 'AiDateHandler::clear($chatId);') !== false
    && strpos($backBlock, 'MaxSearchApi::deleteAllStatus($chatId);') !== false
    && strpos($backBlock, 'AiDateHandler::clear($chatId);') < strpos($backBlock, 'MaxSearchApi::deleteAllStatus($chatId);')
);

AiDateHandler::clear($chatId);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
