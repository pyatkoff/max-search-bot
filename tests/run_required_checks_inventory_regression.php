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
if (($data['schema_version'] ?? null) !== 1) {
    throw new RuntimeException('required_checks_inventory_schema');
}
if ($code !== 0 || empty($data['ok'])) {
    throw new RuntimeException('required_checks_inventory_not_ok: ' . json_encode([
        'orphans' => $data['orphan_regressions'] ?? null,
        'missing_referenced' => $data['missing_referenced_regressions'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
if (($data['counts']['discovered'] ?? 0) <= 0) {
    throw new RuntimeException('required_checks_inventory_empty');
}
if (($data['counts']['discovered'] ?? null) !== ($data['counts']['covered'] ?? null)) {
    throw new RuntimeException('required_checks_inventory_coverage_mismatch');
}
if (($data['counts']['orphans'] ?? null) !== 0) {
    throw new RuntimeException('required_checks_inventory_orphans');
}
if (($data['counts']['missing_referenced'] ?? null) !== 0) {
    throw new RuntimeException('required_checks_inventory_missing_references');
}

echo 'REQUIRED CHECKS INVENTORY REGRESSION PASSED' . PHP_EOL;
