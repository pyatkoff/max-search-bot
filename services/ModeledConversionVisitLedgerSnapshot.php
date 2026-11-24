<?php
declare(strict_types=1);

/** A complete, replayable V1 journal from one database read view. */
final class ModeledConversionVisitLedgerSnapshot implements IteratorAggregate, Countable
{
    private const PAGE_ROWS = 256;

    /** @param resource $stream An unlinked private file open only in this process. */
    private function __construct(
        private $stream,
        private readonly int $rows,
        private readonly ?float $deadline
    ) {}

    public static function selectSql(bool $mysql, bool $idPage = false): string
    {
        require_once __DIR__.'/ModeledConversionDeliveryRegistry.php';
        return 'SELECT o.id,o.conversation_id,o.project_key,o.counter_id,o.transport_target,o.value_contract,o.platform,o.target,o.value_profile_id,'
            .'o.value_stage,o.value_rub,o.event_unix,o.client_id,o.upload_id,o.external_user_id,o.state,o.upload_status,o.value_api_accepted_at_unix,o.value_processed_at_unix,o.claimed_at_unix,o.tracker_observed_unix '
            .ModeledConversionDeliveryRegistry::sourceSql($mysql, false, $idPage);
    }

    public static function capture(PDO $pdo, ?float $deadline = null): self
    {
        self::checkDeadline($deadline);
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $mysql = $driver === 'mysql';
        if (!$mysql && $driver !== 'sqlite') {
            throw new RuntimeException('verification_snapshot_driver');
        }
        $outer = $pdo->inTransaction();
        // SQLite keeps the caller's read view. On MySQL a caller may have selected READ COMMITTED.
        if ($mysql && $outer) {
            throw new RuntimeException('verification_snapshot_outer_transaction');
        }
        $sql = self::selectSql($mysql, true);
        $source = ModeledConversionDeliveryRegistry::sourceSql($mysql);
        $stream = self::privateStream();
        $own = false;
        try {
            if (!$outer) {
                if ($mysql && $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY') === false) {
                    throw new RuntimeException('verification_snapshot_transaction');
                }
                if (!$pdo->beginTransaction()) {
                    throw new RuntimeException('verification_snapshot_transaction');
                }
                $own = true;
            }
            $summarySql = 'SELECT COUNT(*) AS n, COALESCE(MAX(o.id),0) AS fence '.$source;
            $summary = $pdo->query(self::boundQuery($summarySql, $mysql, $deadline));
            $scope = $summary->fetch(PDO::FETCH_ASSOC);
            $summary->closeCursor();
            $expected = (string)($scope['n'] ?? '');
            $fence = (string)($scope['fence'] ?? '');
            if (!preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D', $fence)
                || !preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $expected)
                || ($expected === '0') !== ($fence === '0')) {
                throw new RuntimeException('verification_snapshot_scope');
            }

            $rows = 0;
            $last = null;
            $cursorParam = $mysql ? 'CAST(? AS DECIMAL(20,0))' : 'CAST(? AS INTEGER)';
            do {
                self::checkDeadline($deadline);
                $range = $last === null ? ' <= ' : ' < ';
                $pageSql = $sql.' AND o.id'.$range.$cursorParam.' ORDER BY o.id DESC LIMIT '.self::PAGE_ROWS;
                $page = $pdo->prepare(self::boundQuery($pageSql, $mysql, $deadline));
                $page->execute([$last ?? $fence]);
                $pageRows = 0;
                try {
                    while (($row = $page->fetch(PDO::FETCH_ASSOC)) !== false) {
                        $id = (string)$row['id'];
                        if (!preg_match('/\A[1-9][0-9]{0,19}\z/D', $id)
                            || ($last === null && $id !== $fence)
                            || ($last !== null && (strlen($id) > strlen($last)
                                || (strlen($id) === strlen($last) && strcmp($id, $last) >= 0)))) {
                            throw new RuntimeException('verification_snapshot_order');
                        }
                        $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n";
                        if (fwrite($stream, $line) !== strlen($line)) {
                            throw new RuntimeException('verification_snapshot_write');
                        }
                        $last = $id;
                        ++$pageRows;
                        ++$rows;
                    }
                } finally {
                    $page->closeCursor();
                }
            } while ($pageRows === self::PAGE_ROWS);
            self::checkDeadline($deadline);
            if ((string)$rows !== $expected || !fflush($stream)) {
                throw new RuntimeException('verification_snapshot_incomplete');
            }
            if ($own && !$pdo->commit()) {
                throw new RuntimeException('verification_snapshot_transaction');
            }
            return new self($stream, $rows, $deadline);
        } catch (Throwable $e) {
            fclose($stream);
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function boundQuery(string $sql, bool $mysql, ?float $deadline): string
    {
        if (!$mysql || $deadline === null) return $sql;
        $ms = (int)floor(($deadline - microtime(true)) * 1000);
        if ($ms < 1) throw new RuntimeException('verification_snapshot_deadline');
        // The PHP deadline is also checked after each page, including the final page.
        return 'SELECT /*+ MAX_EXECUTION_TIME('.$ms.') */ '.substr($sql, strlen('SELECT '));
    }

    private static function privateStream()
    {
        // Unlink before placing any private identifiers on disk, including if cron kills this process.
        $path = sys_get_temp_dir().'/modeled-visit-'.bin2hex(random_bytes(16));
        $oldMask = umask(0077);
        try {
            $stream = fopen($path, 'x+b');
        } finally {
            umask($oldMask);
        }
        if ($stream === false) {
            throw new RuntimeException('verification_snapshot_storage');
        }
        if (!unlink($path)) {
            fclose($stream);
            throw new RuntimeException('verification_snapshot_storage');
        }
        return $stream;
    }

    private static function checkDeadline(?float $deadline): void
    {
        if ($deadline !== null && (!is_finite($deadline) || microtime(true) >= $deadline)) {
            throw new RuntimeException('verification_snapshot_deadline');
        }
    }

    public function count(): int
    {
        return $this->rows;
    }

    /** One private index of byte offsets per selected ClientID, with no second copy of ledger rows. */
    public function indexClientUploads(array $clientIds): Closure
    {
        if (count($clientIds) > ModeledConversionVisitProof::MAX_ROWS) {
            throw new InvalidArgumentException('verification_client_bound');
        }
        $counts = array_fill_keys($clientIds, 0);
        $files = [];
        $offset = 0;
        foreach ($this as $row) {
            $cid = (string)($row['client_id'] ?? '');
            if (isset($counts[$cid]) && (string)($row['upload_id'] ?? '') !== '') {
                $files[$cid] ??= self::privateStream();
                if (fwrite($files[$cid], pack('J', $offset)) !== 8) {
                    throw new RuntimeException('verification_snapshot_write');
                }
                ++$counts[$cid];
            }
            $offset = ftell($this->stream);
            if ($offset === false) throw new RuntimeException('verification_snapshot_seek');
        }
        foreach ($files as $file) {
            if (!fflush($file)) throw new RuntimeException('verification_snapshot_write');
        }

        // Return only a row iterator; a client subset cannot be passed to verify() as a complete snapshot.
        return function (string $client) use ($counts, $files): Generator {
            $rows = $counts[$client] ?? 0;
            for ($i = 0; $i < $rows; ++$i) {
                if ($i % self::PAGE_ROWS === 0) self::checkDeadline($this->deadline);
                $file = $files[$client];
                // Iterator-local positions allow nested reads of either file.
                if (ftell($file) !== $i * 8 && fseek($file, $i * 8) !== 0) {
                    throw new RuntimeException('verification_snapshot_seek');
                }
                $raw = fread($file, 8);
                if ($raw === false || strlen($raw) !== 8) throw new RuntimeException('verification_snapshot_read');
                yield json_decode($this->lineAt(unpack('J', $raw)[1]), true, 16, JSON_THROW_ON_ERROR);
            }
        };
    }

    private function lineAt(int $offset): string
    {
        if (ftell($this->stream) !== $offset && fseek($this->stream, $offset) !== 0) {
            throw new RuntimeException('verification_snapshot_seek');
        }
        $line = fgets($this->stream);
        if ($line === false || !str_ends_with($line, "\n")) {
            throw new RuntimeException('verification_snapshot_read');
        }
        return $line;
    }

    public function getIterator(): Traversable
    {
        $offset = 0;
        for ($index = 0; $index < $this->rows; ++$index) {
            if ($index % self::PAGE_ROWS === 0) {
                self::checkDeadline($this->deadline);
            }
            // Nested scans share a handle, but every iterator owns its next logical offset.
            $line = $this->lineAt($offset);
            $offset += strlen($line);
            yield json_decode($line, true, 16, JSON_THROW_ON_ERROR);
        }
    }
}
