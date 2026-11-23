<?php
declare(strict_types=1);

/**
 * Reads the exact PRICE handoff under the caller's existing journal lock.
 * Only the final state is retained. Every byte is authenticated by the pin.
 */
function verifiedCaptionPriceJournal(string $path, $journal, array $pin): array
{
    $statKeys = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'];
    $fileStat = static function ($stat) use ($statKeys): array {
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException('monitor_journal_stat_invalid');
        }
        return array_intersect_key($stat, array_fill_keys($statKeys, true));
    };
    $pathStat = static function (string $name) use ($fileStat): array {
        clearstatcache(true, $name);
        if (realpath($name) !== $name || is_link($name)) {
            throw new RuntimeException('monitor_journal_path_invalid');
        }
        return $fileStat(lstat($name));
    };
    if (!is_resource($journal) || !rewind($journal)) {
        throw new RuntimeException('monitor_journal_unavailable');
    }
    $before = $fileStat(fstat($journal));
    if ($before !== $pathStat($path) || $before['size'] !== $pin['bytes']) {
        throw new RuntimeException('monitor_journal_handoff_size_or_identity_changed');
    }

    $hash = hash_init('sha256');
    $bytes = 0;
    $lines = 0;
    $last = '';
    while (($record = fgets($journal, 8388609)) !== false) {
        if (!str_ends_with($record, "\n") || trim($record) === '') {
            throw new RuntimeException('monitor_journal_record_incomplete');
        }
        $bytes += strlen($record);
        ++$lines;
        if ($bytes > $pin['bytes'] || $lines > $pin['lines']) {
            throw new RuntimeException('monitor_journal_handoff_bound_exceeded');
        }
        hash_update($hash, $record);
        $last = $record;
    }
    $digest = hash_final($hash);
    if (!feof($journal) || $bytes !== $pin['bytes'] || $lines !== $pin['lines']
        || !hash_equals($pin['sha256'], $digest)
        || $fileStat(fstat($journal)) !== $before || $pathStat($path) !== $before) {
        throw new RuntimeException('monitor_journal_handoff_pin_changed');
    }

    $latest = json_decode($last, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($latest) || !array_key_exists('pending', $latest) || $latest['pending'] !== null) {
        throw new RuntimeException('monitor_pending_operation_stop');
    }
    $cycle = $latest['nightly_cycle'] ?? null;
    if (!is_array($cycle) || ($cycle['collector_run_id'] ?? null) !== $pin['collector_run_id']
        || ($cycle['state'] ?? null) !== $pin['cycle_state']) {
        throw new RuntimeException('monitor_journal_handoff_cycle_changed');
    }
    return ['sha256' => $digest, 'bytes' => $bytes, 'lines' => $lines];
}
