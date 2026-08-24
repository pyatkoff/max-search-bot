<?php
require_once __DIR__ . '/ConversationDb.php';

class MigrationRunner
{
    private string $dir;
    private PDO $pdo;

    public function __construct(?string $dir = null, ?PDO $pdo = null)
    {
        $this->dir = $dir ?: dirname(__DIR__) . '/migrations';
        $this->pdo = $pdo ?: ConversationDb::connection();
    }

    public function ensureTable(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(191) NOT NULL,
            checksum CHAR(64) NOT NULL,
            baseline TINYINT(1) NOT NULL DEFAULT 0,
            execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function status(): array
    {
        $this->ensureTable();
        $applied = $this->applied();
        $out = [];
        foreach ($this->files() as $file) {
            $version = basename($file);
            $checksum = hash_file('sha256', $file);
            $row = $applied[$version] ?? null;
            $out[] = [
                'version' => $version,
                'applied' => (bool)$row,
                'baseline' => $row ? (bool)$row['baseline'] : false,
                'checksum_ok' => !$row || hash_equals((string)$row['checksum'], $checksum),
                'applied_at' => $row['applied_at'] ?? null,
            ];
        }
        return $out;
    }

    public function migrate(): array
    {
        $this->ensureTable();
        $files = $this->files();
        $applied = $this->applied();
        $baselined = [];
        $executed = [];

        // This project existed before version tracking. If the conversation core is
        // already present and no versions have ever been recorded, register the
        // current migration set as the baseline instead of replaying old DDL.
        if (!$applied && $files && $this->tableExists('conversations')) {
            $q = $this->pdo->prepare('INSERT INTO schema_migrations (version,checksum,baseline,execution_ms) VALUES (?,?,1,0)');
            foreach ($files as $file) {
                $version = basename($file);
                $q->execute([$version, hash_file('sha256', $file)]);
                $baselined[] = $version;
            }
            return ['baselined' => $baselined, 'executed' => [], 'pending' => []];
        }

        foreach ($files as $file) {
            $version = basename($file);
            $checksum = hash_file('sha256', $file);
            if (isset($applied[$version])) {
                if (!hash_equals((string)$applied[$version]['checksum'], $checksum)) {
                    throw new RuntimeException('Applied migration was modified: ' . $version);
                }
                continue;
            }

            $started = microtime(true);
            $sql = trim((string)file_get_contents($file));
            foreach ($this->splitStatements($sql) as $statement) {
                $this->pdo->exec($statement);
            }
            $elapsed = (int)round((microtime(true) - $started) * 1000);
            $q = $this->pdo->prepare('INSERT INTO schema_migrations (version,checksum,baseline,execution_ms) VALUES (?,?,0,?)');
            $q->execute([$version, $checksum, $elapsed]);
            $executed[] = ['version' => $version, 'execution_ms' => $elapsed];
        }

        $pending = array_values(array_map(
            static fn(array $row): string => (string)$row['version'],
            array_filter($this->status(), static fn(array $row): bool => !$row['applied'])
        ));
        return ['baselined' => $baselined, 'executed' => $executed, 'pending' => $pending];
    }

    private function files(): array
    {
        $files = glob(rtrim($this->dir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    private function applied(): array
    {
        $rows = $this->pdo->query('SELECT version,checksum,baseline,execution_ms,applied_at FROM schema_migrations ORDER BY version')->fetchAll();
        $out = [];
        foreach ($rows as $row) $out[(string)$row['version']] = $row;
        return $out;
    }

    private function tableExists(string $table): bool
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        return (int)$q->fetchColumn() > 0;
    }

    private function splitStatements(string $sql): array
    {
        if ($sql === '') return [];
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $s): bool => $s !== ''));
    }
}
