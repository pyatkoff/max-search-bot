<?php
declare(strict_types=1);

/** Advisory cache for candidate selection. Verification and reporting still read original receipts. */
final class ModeledConversionVisitArchiveIndex
{
    private const COMMIT_ROWS = 256;
    private const FILE_NAME = 'selection-v1.sqlite';
    private const EVENT_FIELDS = ['upload_status', 'linkage', 'reason'];
    private const AUDIT_FIELDS = ['diagnosis', 'wire_correction_status'];

    private ?PDO $db;
    private PDOStatement $find;
    private PDOStatement $replace;
    private int $pending = 0;

    private function __construct(private readonly string $directory, PDO $db)
    {
        $this->db = $db;
        $db->exec('PRAGMA busy_timeout=50');
        $db->exec('CREATE TABLE IF NOT EXISTS hints (
            fingerprint TEXT PRIMARY KEY, file_version TEXT NOT NULL,
            payload TEXT NOT NULL, checksum TEXT NOT NULL
        ) WITHOUT ROWID');
        $this->find = $db->prepare('SELECT file_version,payload,checksum FROM hints WHERE fingerprint=?');
        $this->replace = $db->prepare('REPLACE INTO hints VALUES (?,?,?,?)');
    }

    /** Call only while holding the existing visit proof refresh lock. */
    public static function open(): ?self
    {
        $directory = dirname(ModeledConversionVisitProof::path());
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)
            || fileowner(__FILE__) !== posix_geteuid() || !self::privatePath($directory, 0040700)) {
            return null;
        }
        $archive = $directory.'/events';
        if (!self::privatePath($archive, 0040700)) {
            return null;
        }
        $path = $directory.'/'.self::FILE_NAME;
        if (!file_exists($path) && !is_link($path)) {
            $mask = umask(0077);
            try {
                $file = @fopen($path, 'xb');
                if ($file === false) return null;
                fclose($file);
            } finally {
                umask($mask);
            }
        }
        if (!self::privatePath($path, 0100600)) {
            return null;
        }
        try {
            return new self($archive, new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
        } catch (PDOException $ignored) {
            return null;
        }
    }

    /** Invalidate before atomic replacement of any authoritative event file. */
    public static function beforeArchiveSave(array $events): void
    {
        $path = dirname(ModeledConversionVisitProof::path()).'/'.self::FILE_NAME;
        if (!self::privatePath($path, 0100600)) return;
        $index = self::open();
        if ($index !== null) {
            try {
                $index->db->beginTransaction();
                $delete = $index->db->prepare('DELETE FROM hints WHERE fingerprint=?');
                foreach ($events as $event) $delete->execute([$event['fingerprint']]);
                $index->db->commit();
                return;
            } catch (PDOException $ignored) {
                $index->discard();
            }
        }
        // Discard any SQLite recovery files along with this derived index.
        foreach (['-journal', '-wal', '-shm', ''] as $suffix) {
            $file = $path.$suffix;
            clearstatcache(true, $file);
            if ((file_exists($file) || is_link($file)) && !@unlink($file)) {
                throw new RuntimeException('verification_index_invalidate');
            }
        }
    }

    public function prior(array $row, int $now): array
    {
        if ($this->db === null) return ModeledConversionVisitProof::prior($row, $now);
        $fingerprint = ModeledConversionVisitProof::fingerprint($row);
        $path = $this->directory.'/'.$fingerprint.'.json';
        $version = self::fileVersion($path);
        if ($version === null) return ModeledConversionVisitProof::prior($row, $now);
        try {
            $this->find->execute([$fingerprint]);
            $entry = $this->find->fetch(PDO::FETCH_ASSOC);
            $this->find->closeCursor();
            if (is_array($entry) && $entry['file_version'] === $version
                && is_string($entry['payload'] ?? null) && is_string($entry['checksum'] ?? null)
                && hash_equals(self::digest($fingerprint, $version, $entry['payload']), $entry['checksum'])) {
                $payload = json_decode($entry['payload'], true);
                if (is_array($payload) && is_int($payload['receipt_at'] ?? null) && $payload['receipt_at'] <= $now
                    && is_int($payload['at'] ?? null) && $payload['at'] <= $now && is_array($payload['event'] ?? null)) {
                    return $payload;
                }
            }
        } catch (PDOException $ignored) {
            $this->discard();
        }

        $prior = ModeledConversionVisitProof::prior($row, $now);
        $receiptAt = strtotime($prior['cache']['receipt']['checked_at'] ?? '');
        if ($this->db !== null && is_int($prior['at'] ?? null) && is_int($receiptAt)
            && self::fileVersion($path) === $version) {
            $event = $prior['event'];
            $hint = self::fields($event, self::EVENT_FIELDS);
            $hint['failure_audit'] = self::fields($event['failure_audit'] ?? null, self::AUDIT_FIELDS);
            $payload = json_encode(['receipt_at' => $receiptAt, 'at' => $prior['at'], 'event' => $hint], JSON_THROW_ON_ERROR);
            try {
                if ($this->pending === 0) $this->db->beginTransaction();
                $this->replace->execute([$fingerprint, $version, $payload, self::digest($fingerprint, $version, $payload)]);
                if (++$this->pending === self::COMMIT_ROWS) $this->close();
            } catch (PDOException $ignored) {
                $this->discard();
            }
        }
        return $prior;
    }

    public function close(): void
    {
        if ($this->pending === 0 || $this->db === null) return;
        try {
            $this->db->commit();
            $this->pending = 0;
        } catch (PDOException $ignored) {
            $this->discard();
        }
    }

    private function discard(): void
    {
        if ($this->db?->inTransaction()) {
            try {
                $this->db->rollBack();
            } catch (PDOException $ignored) {}
        }
        $this->pending = 0;
        $this->db = null;
    }

    private static function privatePath(string $path, int $mode): bool
    {
        clearstatcache(true, $path);
        $info = @lstat($path);
        return is_array($info) && $info['mode'] === $mode && $info['uid'] === posix_geteuid();
    }

    private static function fileVersion(string $path): ?string
    {
        clearstatcache(true, $path);
        $info = @lstat($path);
        if (!is_array($info) || $info['mode'] !== 0100600 || $info['uid'] !== posix_geteuid() || $info['nlink'] !== 1) {
            return null;
        }
        return implode(':', [$info['dev'], $info['ino'], $info['mtime'], $info['ctime'], $info['size']]);
    }

    private static function digest(string $fingerprint, string $version, string $payload): string
    {
        return hash('sha256', $fingerprint.'|'.$version.'|'.$payload);
    }

    private static function fields(mixed $source, array $keys): array
    {
        if (!is_array($source)) return [];
        $fields = [];
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;
            if (is_string($value)) $fields[$key] = $value;
        }
        return $fields;
    }
}
