<?php
declare(strict_types=1);
define('ACTIVE_DEPARTURE_EXPORT_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/tools/export_active_departure_catalog.php';
$columns = ['id', 'name', 'name_genitive', 'is_active'];
$schema = array_map(static function (string $column): array {
    return ['COLUMN_NAME' => $column, 'DATA_TYPE' => in_array($column, ['id', 'is_active'], true) ? 'int' : 'varchar', 'IS_NULLABLE' => $column === 'name_genitive' ? 'YES' : 'NO'];
}, $columns);
$rows = [['id' => '1', 'name' => 'Москва', 'name_genitive' => 'Москвы', 'is_active' => '1'],
    ['id' => '5', 'name' => 'С.Петербург', 'name_genitive' => 'С.Петербурга', 'is_active' => 1],
    ['id' => '99', 'name' => 'Без перелёта', 'name_genitive' => null, 'is_active' => 1]];
$payload = departureExportPayload($rows, 3, $columns, $schema, '2026-11-10T00:00:00Z');
if (!($payload['complete'] === true && $payload['row_count'] === 3 && $payload['rows'][1]['name'] === 'С.Петербург')) throw new RuntimeException('Positive payload mismatch');
if ($payload['rows_sha256'] !== hash('sha256', json_encode($payload['rows'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) throw new RuntimeException('Row hash mismatch');
$checks = 2;
foreach (['count', 'inactive', 'duplicate', 'label', 'column'] as $case) {
    $input = $rows; $count = 3; $fields = $columns;
    if ($case === 'count') $count = 4;
    if ($case === 'inactive') $input[1]['is_active'] = 0;
    if ($case === 'duplicate') $input[1]['id'] = '1';
    if ($case === 'label') $input[1]['name'] = "Invalid\x00Name";
    if ($case === 'column') $fields[] = 'password';
    $rejected = false;
    try { departureExportPayload($input, $count, $fields, [], '2026-11-10T00:00:00Z'); }
    catch (ActiveDepartureExportFailure $error) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Fixture was not rejected: ' . $case);
    ++$checks;
}
echo 'Active departure export synthetic checks passed: ' . $checks . "\n";
if (in_array('--emit-fixture', $argv ?? [], true)) {
    echo 'SYNTHETIC_ACTIVE_DEPARTURE_JSON_BASE64=' . base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") . "\n";
}
