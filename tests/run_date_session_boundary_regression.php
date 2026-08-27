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
dsbCheck('restart clears pending month before starting fresh session', preg_match("/if \(\$q === 'restart'\).*?AiDateHandler::clear\(\$chatId\);.*?deleteAllStatus\(\$chatId\).*?showStart\(\$chatId\)/s", $controller) === 1);
dsbCheck('back phone full reset also clears pending month', preg_match("/if \(\$q === 'back_phone'\).*?AiDateHandler::clear\(\$chatId\);.*?deleteAllStatus\(\$chatId\)/s", $controller) === 1);

AiDateHandler::clear($chatId);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
