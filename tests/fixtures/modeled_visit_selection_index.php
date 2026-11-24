<?php
declare(strict_types=1);

/** Run inside the existing isolated visit-proof filesystem fixture. */
function visitSelectionIndexAssertions(array $sample, int $now): bool
{
    require_once dirname(ModeledConversionVisitProof::path(), 2).'/app/services/ModeledConversionVisitArchiveIndex.php';
    $sample['client_id'] = '900000000000000089';
    $sample['state'] = 'processed';
    $sample['upload_status'] = 'PROCESSED';
    $sample['value_api_accepted_at_unix'] = $now - 901;
    $sample['value_processed_at_unix'] = $now - 900;
    $rows = [];
    $events = [];
    foreach ([104, 103, 102, 101] as $id) {
        $row = $sample;
        $row['id'] = (string)$id;
        $row['upload_id'] = (string)(4000 + $id);
        $row['event_unix'] = $now - 172800;
        $rows[] = $row;
        $events[] = [
            'outbox_id'=>(string)$id, 'fingerprint'=>ModeledConversionVisitProof::fingerprint($row),
            'increment_rub'=>intdiv($id, 10), 'platform'=>$row['platform'], 'stage'=>$row['value_stage'],
            'cohort'=>false, 'checked_at'=>gmdate('c', $now - 86460 - $id),
            'upload_status'=>'PROCESSED', 'linkage'=>'unique_visit_reconciled',
        ];
    }
    $receipt = [
        'version'=>ModeledConversionVisitProof::VERSION, 'project_key'=>'anytour',
        'counter_id'=>'98615635', 'goal_id'=>'678187097', 'currency'=>'RUB',
        'checked_at'=>gmdate('c', $now - 86460), 'checkpoint_at'=>gmdate('c', $now - 172800),
        'provider_write_requests'=>0, 'source_writes'=>0, 'ledger_complete'=>true, 'events'=>$events,
    ];
    ModeledConversionVisitProof::save($receipt);
    $index = ModeledConversionVisitArchiveIndex::open();
    if ($index === null) return !in_array('sqlite', PDO::getAvailableDrivers(), true);
    $direct = static fn(array $row): array => ModeledConversionVisitProof::prior($row, $now);
    $indexed = static function(array $row) use (&$index, $now): array { return $index->prior($row, $now); };
    $expected = ModeledConversionVisitProof::dueIds($rows, $now - 172800, $now, $direct, 2);
    $cold = ModeledConversionVisitProof::dueIds($rows, $now - 172800, $now, $indexed, 2);
    $index->close();
    $index = ModeledConversionVisitArchiveIndex::open();
    if ($index === null) return false;
    $warm = ModeledConversionVisitProof::dueIds($rows, $now - 172800, $now, $indexed, 2);
    if ($expected !== ['104', '103'] || $cold !== $expected || $warm !== $expected || isset($indexed($rows[0])['cache'])) return false;

    $updated = $receipt;
    $updated['checked_at'] = gmdate('c', $now);
    $updated['events'] = [$events[0]];
    $updated['events'][0]['checked_at'] = $updated['checked_at'];
    ModeledConversionVisitProof::save($updated);
    unset($index);
    $index = ModeledConversionVisitArchiveIndex::open();
    if ($index === null) return false;
    $fresh = $indexed($rows[0]);
    $index->close();
    if (($fresh['at'] ?? 0) !== $now || !isset($fresh['cache'])) return false;
    if (ModeledConversionVisitProof::dueIds($rows, $now - 172800, $now, $indexed, 2)
        !== ModeledConversionVisitProof::dueIds($rows, $now - 172800, $now, $direct, 2)) return false;
    // A changed fingerprint forces an independent receipt. A bad file can never be a hint hit.
    $changed = $rows[1];
    $changed['upload_id'] = '4999';
    if ($indexed($changed) !== []) return false;
    $path = dirname(ModeledConversionVisitProof::path()).'/events/'.$events[1]['fingerprint'].'.json';
    chmod($path, 0644);
    if ($indexed($rows[1]) !== [] || $direct($rows[1]) !== []) return false;
    unset($index);
    $indexFile = dirname(ModeledConversionVisitProof::path()).'/selection-v1.sqlite';
    file_put_contents($indexFile, 'broken index fixture');
    file_put_contents($indexFile.'-journal', 'invalid journal fixture');
    if (ModeledConversionVisitArchiveIndex::open() !== null) return false;
    ModeledConversionVisitProof::save($updated);
    return !file_exists($indexFile) && !file_exists($indexFile.'-journal')
        && ($direct($rows[0])['at'] ?? 0) === $now;
}
