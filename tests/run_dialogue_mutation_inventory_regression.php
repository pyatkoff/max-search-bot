<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tool = $root . '/tools/dialogue_mutation_inventory.php';
$fixture = $root . '/services/UnclassifiedMutationWriter.php';
register_shutdown_function(static function () use ($fixture): void {
    @unlink($fixture);
});

function mutationInventoryRun(string $tool, bool $json = false): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ($json ? ' --json' : '') . ' 2>&1';
    $lines = [];
    exec($command, $lines, $code);
    return [$code, implode("\n", $lines)];
}

function mutationInventoryCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS  {$message}\n";
}

[$currentCode, $currentOutput] = mutationInventoryRun($tool, true);
$current = json_decode($currentOutput, true);
mutationInventoryCheck($currentCode === 0 && is_array($current) && !empty($current['ok']), 'current mutation inventory is complete');
mutationInventoryCheck(($current['summary']['caller_groups'] ?? 0) === 28, 'inventory owns the exact current caller-group baseline');
mutationInventoryCheck(($current['summary']['occurrences'] ?? 0) === 50, 'inventory owns the exact current occurrence baseline');

$methods = array_values(array_unique(array_column($current['callers'] ?? [], 'method')));
foreach (['setStatus', 'saveLastValue', 'upsertStatusValue', 'deleteAll', 'applyAiParameters'] as $method) {
    mutationInventoryCheck(in_array($method, $methods, true), "required mutation method is inventoried: {$method}");
}
$classifications = array_values(array_unique(array_column($current['callers'] ?? [], 'classification')));
foreach (['trip_value', 'transition', 'reset', 'metadata', 'manager_technical_state'] as $classification) {
    mutationInventoryCheck(in_array($classification, $classifications, true), "classification has a current caller: {$classification}");
}

try {
    file_put_contents($fixture, "<?php\nMaxSearchApi::setStatus(1, 2);\n");
    [$unknownCode, $unknownOutput] = mutationInventoryRun($tool);
    mutationInventoryCheck($unknownCode !== 0, 'new unclassified writer fails the required check');
    mutationInventoryCheck(str_contains($unknownOutput, 'unclassified_writer:services/UnclassifiedMutationWriter.php|MaxSearchApi::setStatus'), 'failure identifies the exact unclassified writer');
} finally {
    @unlink($fixture);
}

[$restoredCode, $restoredOutput] = mutationInventoryRun($tool);
mutationInventoryCheck($restoredCode === 0 && str_contains($restoredOutput, 'DIALOGUE MUTATION INVENTORY: OK'), 'inventory returns green after the temporary writer is removed');

echo "DIALOGUE MUTATION INVENTORY REGRESSION: OK\n";
