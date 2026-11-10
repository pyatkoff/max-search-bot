<?php
declare(strict_types=1);

// Proposal only: stream this CLI reader through the existing canonical AnyTour
// SSH connection. No web endpoint, bootstrap, supplier request or operational DB.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(0);

final class ActiveDepartureExportFailure extends RuntimeException {}

function departureExportRequire(bool $ok, string $code): void
{
    if (!$ok) throw new ActiveDepartureExportFailure($code);
}

function departureExportLabel($value, bool $optional = false): string
{
    departureExportRequire(is_string($value) && strlen($value) <= 400 && preg_match('//u', $value) === 1
        && !preg_match('/[\x00-\x1f\x7f]/', $value) && ($optional || trim($value) !== ''), 'INVALID_LABEL');
    return $value;
}

function departureExportPayload(array $rows, int $expected, array $columns, array $schema, string $capturedAt): array
{
    departureExportRequire($expected > 0 && $expected <= 2000 && count($rows) === $expected, 'COUNT_MISMATCH');
    departureExportRequire($columns === array_values(array_intersect(['id', 'name', 'name_genitive', 'is_active'], $columns))
        && in_array('id', $columns, true) && in_array('name', $columns, true) && in_array('is_active', $columns, true), 'COLUMN_ALLOWLIST');
    $output = [];
    $previous = '0';
    foreach ($rows as $row) {
        departureExportRequire(is_array($row) && array_keys($row) === $columns, 'ROW_COLUMNS');
        $id = is_int($row['id']) ? (string)$row['id'] : $row['id'];
        departureExportRequire(is_string($id) && preg_match('/^[1-9][0-9]{0,19}$/D', $id) === 1, 'INVALID_ID');
        departureExportRequire(strlen($id) > strlen($previous) || (strlen($id) === strlen($previous) && strcmp($id, $previous) > 0), 'ID_ORDER');
        departureExportRequire(in_array($row['is_active'], [1, '1'], true), 'INACTIVE_ROW');
        $record = ['id' => $id, 'name' => departureExportLabel($row['name'])];
        if (in_array('name_genitive', $columns, true)) {
            $record['name_genitive'] = $row['name_genitive'] === null ? null : departureExportLabel($row['name_genitive'], true);
        }
        $record['is_active'] = 1;
        $output[] = $record;
        $previous = $id;
    }
    $encoded = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    departureExportRequire(strlen($encoded) <= 1048576, 'OUTPUT_LIMIT');
    return ['kind' => 'anytour_active_departures_v1', 'source_table' => 'catalog_departures',
        'captured_at' => $capturedAt, 'active_filter' => 1, 'columns' => $columns,
        'available_column_metadata' => $schema, 'row_count' => count($output), 'rows' => $output,
        'rows_sha256' => hash('sha256', $encoded), 'complete' => true, 'consistent_snapshot' => true,
        'read_only' => true, 'database_changed' => false];
}

function departureExportMain(): void
{
    $pdo = null;
    $transaction = false;
    $failed = false;
    try {
        departureExportRequire(PHP_SAPI === 'cli', 'CLI_REQUIRED');
        set_time_limit(60);
        $root = '/var/www/anytoour/data/www/app.anytoour.ru';
        ob_start();
        try {
            require_once $root . '/config.php';
            require_once $root . '/services/HotelDatabase.php';
        } finally {
            ob_end_clean();
        }
        departureExportRequire(HotelDatabase::configured(), 'CATALOG_NOT_CONFIGURED');
        $pdo = HotelDatabase::connection();
        departureExportRequire($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'MYSQL_REQUIRED');
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        $transaction = true;
        $capturedAt = gmdate('Y-m-d\TH:i:s\Z');
        $engine = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='catalog_departures' LIMIT 1")->fetchColumn();
        departureExportRequire(strtoupper((string)$engine) === 'INNODB', 'TRANSACTIONAL_TABLE_REQUIRED');
        $schema = $pdo->query("SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='catalog_departures' AND COLUMN_NAME IN ('id','name','name_genitive','is_active') ORDER BY ORDINAL_POSITION LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        departureExportRequire(count($schema) >= 3 && count($schema) <= 4, 'SCHEMA_LIMIT');
        $available = array_column($schema, 'COLUMN_NAME');
        $columns = array_values(array_intersect(['id', 'name', 'name_genitive', 'is_active'], $available));
        departureExportRequire(in_array('id', $columns, true) && in_array('name', $columns, true) && in_array('is_active', $columns, true), 'REQUIRED_COLUMNS_MISSING');
        $count = $pdo->query('SELECT COUNT(*) FROM catalog_departures WHERE is_active=1')->fetchColumn();
        departureExportRequire(preg_match('/^[1-9][0-9]{0,3}$/D', (string)$count) === 1 && (int)$count <= 2000, 'COUNT_LIMIT');
        $select = implode(',', array_map(static function (string $field): string { return '`' . $field . '`'; }, $columns));
        $rows = $pdo->query('SELECT ' . $select . ' FROM catalog_departures WHERE is_active=1 ORDER BY id ASC LIMIT 2001')->fetchAll(PDO::FETCH_ASSOC);
        $payload = departureExportPayload($rows, (int)$count, $columns, $schema, $capturedAt);
        $pdo->rollBack();
        $transaction = false;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        departureExportRequire(strlen($json) <= 1048576, 'OUTPUT_LIMIT');
        echo $json . "\n";
    } catch (Throwable $error) {
        $failed = true;
        fwrite(STDERR, 'ACTIVE_DEPARTURES_ERROR=' . ($error instanceof ActiveDepartureExportFailure ? $error->getMessage() : 'READ_FAILED') . "\n");
    } finally {
        if ($transaction && $pdo instanceof PDO) {
            try { $pdo->rollBack(); } catch (Throwable $ignored) {}
        }
    }
    if ($failed) exit(1);
}

if (!defined('ACTIVE_DEPARTURE_EXPORT_LIBRARY_ONLY')) departureExportMain();
