<?php
declare(strict_types=1);
require_once __DIR__.'/AdvertisingVisitContract.php';

/** Reads a private copy of the append-only Mini App journal; never authenticates a sender. */
final class AdvertisingVisitJournalReader
{
    private const MAX_FILE_BYTES = 536870912;
    private const MAX_LINE_BYTES = 4096;

    public static function read(string $path, array $scope, array $checkpoint, int $now): array
    {
        $scope = AdvertisingVisitContract::scope($scope);
        $after = $checkpoint['cursor'] ?? null;
        $anchor = $checkpoint['fingerprint'] ?? null;
        if (!is_string($after) || !preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D', $after)
            || AdvertisingVisitContract::compare($after, (string)PHP_INT_MAX) > 0
            || ($after === '0' ? $anchor !== null : !is_string($anchor) || !preg_match('/\A[a-f0-9]{64}\z/D', $anchor))) {
            throw new InvalidArgumentException('advertising_visit_journal_checkpoint');
        }
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || is_link($path) || !is_file($path)) {
            throw new InvalidArgumentException('advertising_visit_journal_file');
        }
        $handle = @fopen($path, 'rb');
        if (!$handle) throw new InvalidArgumentException('advertising_visit_journal_file');
        try {
            if (!flock($handle, LOCK_SH | LOCK_NB)) throw new InvalidArgumentException('advertising_visit_journal_busy');
            $stat = fstat($handle);
            if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0
                || $stat['size'] > self::MAX_FILE_BYTES) throw new InvalidArgumentException('advertising_visit_journal_file');
            $size = $stat['size'];
            if ($after !== '0') {
                $offset = (int)$after - 1;
                if ($offset >= $size || ($offset > 0 && (fseek($handle, $offset - 1) !== 0 || fread($handle, 1) !== "\n"))
                    || fseek($handle, $offset) !== 0) throw new InvalidArgumentException('advertising_visit_journal_checkpoint');
                $row = self::row($handle, $scope, $now);
                if (!hash_equals($anchor, AdvertisingVisitContract::fingerprint($row))) {
                    throw new InvalidArgumentException('advertising_visit_journal_anchor_changed');
                }
            }
            $rows = [];
            while (ftell($handle) < $size && count($rows) < AdvertisingVisitContract::MAX_PAGE) {
                $rows[] = self::row($handle, $scope, $now);
            }
            $next = $rows ? $rows[count($rows)-1]['source_cursor'] : $after;
            $page = ['schema_version'=>1] + $scope + ['after_cursor'=>$after,'next_cursor'=>$next,'observations'=>$rows];
            $page = AdvertisingVisitContract::page($page, $scope, $now);
            if (strlen(json_encode($page, JSON_THROW_ON_ERROR)) > AdvertisingVisitContract::MAX_BYTES) {
                throw new InvalidArgumentException('advertising_visit_journal_page_bound');
            }
            return ['page'=>$page,'has_more'=>ftell($handle) < $size];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function row($handle, array $scope, int $now): array
    {
        $offset = ftell($handle);
        $line = fgets($handle, self::MAX_LINE_BYTES + 2);
        if ($line === false || strlen($line) > self::MAX_LINE_BYTES || !str_ends_with($line, "\n")) {
            throw new InvalidArgumentException('advertising_visit_journal_incomplete');
        }
        try { $record = json_decode($line, true, 20, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new InvalidArgumentException('advertising_visit_journal_record'); }
        $keys = ['schema_version','project_key','source_key','platform','counter_id','observation'];
        if (!is_array($record) || count($record) !== count($keys) || array_diff($keys, array_keys($record))
            || $record['schema_version'] !== 1 || !is_array($record['observation'])) {
            throw new InvalidArgumentException('advertising_visit_journal_record');
        }
        foreach ($scope as $key=>$value) if ($record[$key] !== $value) throw new InvalidArgumentException('advertising_visit_journal_scope');
        $row = $record['observation'];
        if (($row['source_cursor'] ?? null) !== (string)($offset + 1)) throw new InvalidArgumentException('advertising_visit_journal_offset');
        $page = ['schema_version'=>1] + $scope + ['after_cursor'=>'0','next_cursor'=>$row['source_cursor'],'observations'=>[$row]];
        return AdvertisingVisitContract::page($page, $scope, $now)['observations'][0];
    }
}
