<?php
declare(strict_types=1);

/**
 * One forward correction of the exact 104/086 DDL collision.
 * The 105 receipt and 104 baseline flag durably distinguish adoption from
 * execution of the faulty 104 SQL. No legacy value or business row is changed.
 */
final class ProjectFunnelContextMigrationRepair
{
    public const ORIGINAL = '104_project_funnel_context.sql';
    public const REPAIR = '105_adopt_project_funnel_context.sql';
    private const LEGACY = '086_modeled_conversion_value_export.sql';
    private const BLOBS = [
        self::LEGACY => 'e6e5bef110527b909fb7a84edc01c3b1d8f125e0',
        self::ORIGINAL => '7ce7b551a8568b6b78be0043ae4e1308d3530da7',
        self::REPAIR => '4607abd421a63e7c00a18b083a99378f33f166c6',
    ];

    public function __construct(private PDO $pdo, private string $dir) {}

    public function apply(): array
    {
        $checksums = [];
        foreach (self::BLOBS as $version => $blob) {
            $path = $this->dir . '/' . $version;
            $sql = is_file($path) ? file_get_contents($path) : false;
            if (!is_string($sql) || !hash_equals($blob, sha1('blob ' . strlen($sql) . "\0" . $sql))) {
                throw new RuntimeException('Funnel repair source changed: ' . $version);
            }
            $checksums[$version] = hash('sha256', $sql);
        }
        $before = $this->journal();
        if (!isset($before[self::LEGACY]) || !hash_equals($checksums[self::LEGACY], $before[self::LEGACY]['checksum'])) {
            throw new RuntimeException('Funnel repair requires the accepted 086 receipt');
        }
        foreach ([self::ORIGINAL, self::REPAIR] as $version) {
            if (isset($before[$version]) && !hash_equals($checksums[$version], $before[$version]['checksum'])) {
                throw new RuntimeException('Applied migration was modified: ' . $version);
            }
        }
        if (isset($before[self::REPAIR]) && (int)$before[self::REPAIR]['baseline'] !== 0) {
            throw new RuntimeException('Funnel repair receipt is not an execution');
        }

        // Read every relevant definition before any ALTER. Applied receipts
        // additionally require the complete final schema, never silent drift repair.
        $this->schema(isset($before[self::ORIGINAL]) || isset($before[self::REPAIR]));
        $executed = [];
        if (!isset($before[self::REPAIR])) {
            $started = microtime(true);
            $sql = (string)file_get_contents($this->dir . '/' . self::REPAIR);
            foreach (preg_split('/;\s*(?:\r?\n|$)/', trim($sql)) ?: [] as $statement) {
                if (trim($statement) !== '') {
                    $this->pdo->exec(trim($statement));
                }
            }
            $this->schema(true);
            $executed[] = ['version' => self::REPAIR, 'execution_ms' => (int)round((microtime(true) - $started) * 1000)];
        }
        if ($this->journal() !== $before) {
            throw new RuntimeException('Funnel repair journal changed');
        }

        // Only journal metadata is transactional: MySQL ALTER is already committed.
        // A failure retains the compatible partial schema for a checked next release.
        $baselined = [];
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare('INSERT INTO schema_migrations (version,checksum,baseline,execution_ms) VALUES (?,?,?,?)');
            if ($executed) {
                $insert->execute([self::REPAIR, $checksums[self::REPAIR], 0, $executed[0]['execution_ms']]);
            }
            if (!isset($before[self::ORIGINAL])) {
                $insert->execute([self::ORIGINAL, $checksums[self::ORIGINAL], 1, 0]);
                $baselined[] = self::ORIGINAL;
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return ['baselined' => $baselined, 'executed' => $executed];
    }

    private function journal(): array
    {
        $q = $this->pdo->prepare('SELECT version,checksum,baseline,execution_ms,applied_at FROM schema_migrations WHERE version IN (?,?,?) ORDER BY version');
        $q->execute([self::LEGACY, self::ORIGINAL, self::REPAIR]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[$row['version']] = $row;
        return $out;
    }

    private function schema(bool $complete): void
    {
        // COLUMN_DEFAULT is quoted SQL metadata on modern MariaDB, but an
        // unquoted value on MySQL. Never combine both interpretations.
        $server = (string)$this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        if (preg_match('/\A(?:5\.5\.5-)?(\d+\.\d+\.\d+)-MariaDB(?:[-+].*)?\z/iD', $server, $match)
            && version_compare($match[1], '10.2.7', '>=')) {
            $maria = true;
        } elseif (!str_contains(strtolower($server), 'mariadb')
            && preg_match('/\A(?:5\.7|8\.[0-9]+|9\.[0-9]+)\.[0-9]+(?:[-+].*)?\z/D', $server)) {
            $maria = false;
        } else {
            throw new RuntimeException('Funnel repair metadata family is unsupported');
        }
        $collations = [];
        $table = $this->pdo->prepare('SELECT ENGINE AS engine,TABLE_COLLATION AS collation_name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        foreach (['schema_migrations', 'advertising_visit_observations', 'max_channel_conversion_outbox'] as $name) {
            $table->execute([$name]);
            $row = $table->fetch(PDO::FETCH_ASSOC);
            if (!$row || strcasecmp((string)$row['engine'], 'InnoDB') !== 0 || !str_starts_with((string)$row['collation_name'], 'utf8mb4_')) {
                throw new RuntimeException('Funnel repair table mismatch: ' . $name);
            }
            $collations[$name] = $row['collation_name'];
        }
        $specs = [
            ['max_channel_conversion_outbox', 'value_rub', 'decimal(12,2)', true, null, null, true],
            ['advertising_visit_observations', 'post_ref', 'varchar(128)', false, 'ascii', 'ascii_bin', $complete],
            ['advertising_visit_observations', 'offer_ref', 'varchar(128)', false, 'ascii', 'ascii_bin', $complete],
            ['advertising_visit_observations', 'context_json', 'text', true, 'utf8mb4', $collations['advertising_visit_observations'], $complete],
            ['max_channel_conversion_outbox', 'funnel_context_json', 'text', true, 'utf8mb4', $collations['max_channel_conversion_outbox'], $complete],
            ['max_channel_conversion_outbox', 'funnel_policy_sha256', 'char(64)', true, 'ascii', 'ascii_bin', $complete],
        ];
        $column = $this->pdo->prepare('SELECT COLUMN_TYPE AS column_type,IS_NULLABLE AS is_nullable,COLUMN_DEFAULT AS column_default,CHARACTER_SET_NAME AS charset_name,COLLATION_NAME AS collation_name,EXTRA AS extra,GENERATION_EXPRESSION AS generation_expression FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        foreach ($specs as [$name, $field, $type, $nullable, $charset, $collation, $required]) {
            $column->execute([$name, $field]);
            $row = $column->fetch(PDO::FETCH_ASSOC);
            if (!$row && !$required) continue;
            // MariaDB: SQL NULL -> "NULL", empty literal -> "''".
            // MySQL: SQL NULL -> null, empty literal -> "". Literal "NULL"
            // and two actual quote characters must never pass as those defaults.
            $default = $row['column_default'] ?? null;
            $expectedDefault = $nullable ? ($maria ? 'NULL' : null) : ($maria ? "''" : '');
            $defaultOk = $default === $expectedDefault;
            if (!$row || strtolower((string)$row['column_type']) !== $type
                || $row['is_nullable'] !== ($nullable ? 'YES' : 'NO') || !$defaultOk
                || $row['charset_name'] !== $charset || $row['collation_name'] !== $collation
                || (string)$row['extra'] !== '' || (string)$row['generation_expression'] !== '') {
                throw new RuntimeException('Funnel repair column mismatch: ' . $name . '.' . $field);
            }
        }
    }
}
