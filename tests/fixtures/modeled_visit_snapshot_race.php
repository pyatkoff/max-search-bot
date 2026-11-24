<?php
declare(strict_types=1);

final class ModeledVisitSnapshotRaceStatement extends PDOStatement
{
    protected function __construct(private Closure $beforePage) {}

    public function execute(?array $params = null): bool
    {
        if (str_contains($this->queryString, ' ORDER BY o.id DESC LIMIT ')) {
            ($this->beforePage)();
        }
        return parent::execute($params);
    }
}

/** Concurrently revoke the oldest native owner and insert a new ID while the reader is between pages. */
function modeledVisitSnapshotRaceAssertions(callable $check): void
{
    $path = tempnam(sys_get_temp_dir(), 'visit-snapshot-test-');
    if ($path === false) {
        throw new RuntimeException('visit_snapshot_fixture_storage');
    }
    try {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        $reader = new PDO('sqlite:'.$path, null, null, $options);
        $writer = new PDO('sqlite:'.$path, null, null, $options);
        $reader->exec('PRAGMA journal_mode=WAL');
        deliveryRegistrySchema($writer);
        $writer->beginTransaction();
        $seedIds = [];
        $firstConversation = 18001;
        $timestamp = 1795000000;
        for ($i = 0; $i < 257; ++$i) {
            $seedIds[] = deliveryRegistryFixtureEvent($writer, $firstConversation+$i, 1, $timestamp+$i);
        }
        $writer->commit();
        $pageCount = 0;
        $newId = null;
        $writeBetweenPages = static function () use ($writer, $firstConversation, $timestamp, &$pageCount, &$newId): void {
            if (++$pageCount === 2) {
                $writer->beginTransaction();
                $writer->prepare('UPDATE conversations SET is_test=1 WHERE id=?')->execute([$firstConversation]);
                $newId = deliveryRegistryFixtureEvent($writer, 20000, 1, $timestamp+400);
                $writer->commit();
            }
        };
        $reader->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ModeledVisitSnapshotRaceStatement::class, [$writeBetweenPages]]);
        $original = ModeledConversionVisitLedgerSnapshot::capture($reader);
        $next = ModeledConversionVisitLedgerSnapshot::capture($reader);
        $ids = static fn($snapshot): array => array_map('strval', array_column(iterator_to_array($snapshot, false), 'id'));

        $check('visit snapshot keeps one owner-bound read view when a second connection commits between pages',
            $newId !== null && !$reader->inTransaction() && $ids($original) === array_reverse($seedIds));
        array_shift($seedIds);
        $seedIds[] = $newId;
        $check('the next visit snapshot observes the new ID and revoked owner together',
            $ids($next) === array_reverse($seedIds));
    } finally {
        unset($reader, $writer, $writeBetweenPages);
        foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
