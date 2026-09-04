<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tool = $root . '/tools/handoff_policy_inventory.php';
$fixture = $root . '/services/UnclassifiedHandoffCaller.php';
register_shutdown_function(static function () use ($fixture): void { @unlink($fixture); });

require_once $root . '/services/ManagerAvailabilityService.php';
require_once $root . '/services/ManagerHandoffDispatchService.php';
require_once $root . '/services/ManagerPhoneFallbackService.php';

function handoffInventoryRun(string $tool, bool $json = false): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ($json ? ' --json' : '') . ' 2>&1';
    $lines = [];
    exec($command, $lines, $code);
    return [$code, implode("\n", $lines)];
}

function handoffInventoryCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS  {$message}\n";
}

[$currentCode, $currentOutput] = handoffInventoryRun($tool, true);
$current = json_decode($currentOutput, true);
handoffInventoryCheck($currentCode === 0 && is_array($current) && !empty($current['ok']), 'current handoff policy inventory is complete');
handoffInventoryCheck(($current['summary']['caller_groups'] ?? 0) === 13, 'inventory owns the exact current caller-group baseline');
handoffInventoryCheck(($current['summary']['occurrences'] ?? 0) === 14, 'inventory owns the exact current occurrence baseline');
handoffInventoryCheck(($current['summary']['policy_surfaces'] ?? 0) === 5, 'inventory records every current policy surface');

$policy = $current['policy'] ?? [];
handoffInventoryCheck(($policy['timezone'] ?? null) === ManagerAvailabilityService::BUSINESS_TIMEZONE, 'inventory timezone matches the canonical runtime constant');
handoffInventoryCheck(($policy['working_start_hour'] ?? null) === ManagerAvailabilityService::WORKDAY_START_HOUR, 'inventory start boundary matches the canonical runtime constant');
handoffInventoryCheck(($policy['working_end_hour'] ?? null) === ManagerAvailabilityService::WORKDAY_END_HOUR, 'inventory end boundary matches the canonical runtime constant');
handoffInventoryCheck(($policy['phone_fallback_delay_seconds'] ?? null) === ManagerPhoneFallbackService::DELAY_SECONDS, 'inventory fallback delay matches the canonical runtime constant');
handoffInventoryCheck(($policy['outside_hours_queue_waiting'] ?? null) === false, 'inventory records that outside-hours presentation does not enter the live queue');

$at = static function (string $local): int {
    return (new DateTimeImmutable($local, new DateTimeZone(ManagerAvailabilityService::BUSINESS_TIMEZONE)))->getTimestamp();
};
handoffInventoryCheck(!ManagerAvailabilityService::withinWorkingHours($at('2026-09-04 09:59:59')), 'golden case: one second before 10:00 is outside hours');
handoffInventoryCheck(ManagerAvailabilityService::withinWorkingHours($at('2026-09-04 10:00:00')), 'golden case: 10:00 starts the working window');
handoffInventoryCheck(ManagerAvailabilityService::withinWorkingHours($at('2026-09-04 19:59:59')), 'golden case: one second before 20:00 is inside hours');
handoffInventoryCheck(!ManagerAvailabilityService::withinWorkingHours($at('2026-09-04 20:00:00')), 'golden case: 20:00 starts outside-hours behavior');
handoffInventoryCheck(ManagerHandoffDispatchService::shouldQueueWaiting(true, true), 'golden case: a sent working-hours request enters waiting_manager');
handoffInventoryCheck(!ManagerHandoffDispatchService::shouldQueueWaiting(true, false), 'golden case: a sent outside-hours offer does not enter waiting_manager');

$classes = array_values(array_unique(array_column($current['callers'] ?? [], 'classification')));
foreach (['entrypoint', 'decision', 'presentation', 'mutation', 'fallback_scheduler', 'fallback_application', 'projection'] as $classification) {
    handoffInventoryCheck(in_array($classification, $classes, true), "classification has a current caller: {$classification}");
}

try {
    file_put_contents($fixture, "<?php\nManagerHandoffDispatchService::dispatch(1, 'max');\n");
    [$unknownCode, $unknownOutput] = handoffInventoryRun($tool);
    handoffInventoryCheck($unknownCode !== 0, 'new unclassified handoff caller fails the required check');
    handoffInventoryCheck(str_contains($unknownOutput, 'unclassified_handoff_caller:services/UnclassifiedHandoffCaller.php|ManagerHandoffDispatchService::dispatch'), 'failure identifies the exact unclassified caller');
} finally {
    @unlink($fixture);
}

[$restoredCode, $restoredOutput] = handoffInventoryRun($tool);
handoffInventoryCheck($restoredCode === 0 && str_contains($restoredOutput, 'HANDOFF POLICY INVENTORY: OK'), 'inventory returns green after the temporary caller is removed');

echo "HANDOFF POLICY INVENTORY REGRESSION: OK\n";
