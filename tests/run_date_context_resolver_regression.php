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
$localMonth = AiDateContextService::resolveLocal($chatId, 'в декабре 2026');
dcrCheck('AI local policy exposes month-only clarification', $localMonth['date'] === '' && $localMonth['month_only'] === true);
$guardedMonth = AiDateContextService::applyAiGuard($chatId, 'в декабре 2026', ['date'=>'15.01.2027']);
dcrCheck('AI guard rejects invented date when user named only month', array_key_exists('date', $guardedMonth) && $guardedMonth['date'] === null);
$guardedExact = AiDateContextService::applyAiGuard($chatId, '15.11.2026', ['date'=>'20.12.2026']);
dcrCheck('AI guard prefers explicit user date', ($guardedExact['date'] ?? '') === '15.11.2026');

$handler = (string)file_get_contents(__DIR__ . '/../handlers/AiDateHandler.php');
dcrCheck('AI date handler delegates to shared date context resolver', strpos($handler, 'DateContextResolver::resolveFromText') !== false && strpos($handler, 'DateContextResolver::resolvePendingShortDate') !== false);
dcrCheck('AI date handler no longer owns pending store directly', strpos($handler, 'PendingMonthStore::') === false);

$messageHandler = (string)file_get_contents(__DIR__ . '/../handlers/AiMessageHandler.php');
dcrCheck('AI message handler delegates month-only and date guard policy', strpos($messageHandler, 'AiDateContextService::resolveLocal') !== false && strpos($messageHandler, 'AiDateContextService::applyAiGuard') !== false);
dcrCheck('AI message handler no longer owns direct date parsing policy', strpos($messageHandler, 'AiDateHandler::rememberMonthFromText') === false);

DateContextResolver::clear($chatId);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
