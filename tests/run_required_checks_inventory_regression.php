<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tool = $root . '/tools/required_checks_inventory.php';
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' --compact';
exec($cmd, $lines, $code);
$data = json_decode(implode("\n", $lines), true);

if (!is_array($data)) {
    throw new RuntimeException('required_checks_inventory_invalid_json');
}
if (($data['schema_version'] ?? null) !== 2) {
    throw new RuntimeException('required_checks_inventory_schema');
}
if ($code !== 0 || empty($data['ok'])) {
    throw new RuntimeException('required_checks_inventory_not_ok: ' . json_encode([
        'orphans' => $data['orphan_regressions'] ?? null,
        'missing_referenced' => $data['missing_referenced_checks'] ?? null,
        'duplicate_regressions' => $data['duplicate_regression_assignments'] ?? null,
        'duplicate_commands' => $data['duplicate_commands'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
if (($data['counts']['groups'] ?? 0) < 5) {
    throw new RuntimeException('required_checks_inventory_groups');
}
if (($data['counts']['commands'] ?? 0) <= 0 || ($data['counts']['discovered'] ?? 0) <= 0) {
    throw new RuntimeException('required_checks_inventory_empty');
}
if (($data['counts']['discovered'] ?? null) !== ($data['counts']['covered'] ?? null)) {
    throw new RuntimeException('required_checks_inventory_coverage_mismatch');
}
foreach (['orphans','missing_referenced','duplicate_regression_assignments','duplicate_commands'] as $metric) {
    if (($data['counts'][$metric] ?? null) !== 0) {
        throw new RuntimeException('required_checks_inventory_' . $metric);
    }
}
foreach (['architecture','dialogue','website','manager','diagnostics'] as $group) {
    if (($data['groups'][$group] ?? 0) <= 0) {
        throw new RuntimeException('required_checks_inventory_missing_group_' . $group);
    }
}

echo 'REQUIRED CHECKS INVENTORY REGRESSION PASSED' . PHP_EOL;
