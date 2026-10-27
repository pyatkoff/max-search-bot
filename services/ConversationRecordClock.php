<?php
declare(strict_types=1);

/** Forward recording-time provenance; never infer an instant from a bare DATETIME. */
final class ConversationRecordClock
{
    private const KEY = '_recorded_clock';

    public static function stamp(array $metadata, ?int $unix = null): array
    {
        $unix = $unix ?? time();
        if ($unix < 946684800 || $unix > 4102444800) throw new InvalidArgumentException('recording_clock_invalid');
        $metadata[self::KEY] = ['version'=>1, 'basis'=>'server_recording_unix', 'unix'=>$unix];
        return $metadata;
    }

    public static function read($json): ?int
    {
        if (!is_string($json)) return null;
        $metadata = json_decode($json, true, 32);
        $clock = is_array($metadata) ? ($metadata[self::KEY] ?? null) : null;
        if (!is_array($clock) || count($clock)!==3 || ($clock['version'] ?? null) !== 1
            || ($clock['basis'] ?? null) !== 'server_recording_unix'
            || !is_int($clock['unix'] ?? null) || $clock['unix'] < 946684800 || $clock['unix'] > 4102444800) return null;
        return $clock['unix'];
    }

    /** Only internal column literals are accepted; malformed/unmarked rows remain NULL. */
    public static function sql(string $column, bool $mysql): string
    {
        if (!in_array($column, ['metadata_json','payload_json','c.metadata_json','s.metadata_json','s.payload_json'], true)) throw new InvalidArgumentException('recording_clock_column');
        $json = "CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END";
        $get = static fn(string $field): string => $mysql
            ? "JSON_UNQUOTE(JSON_EXTRACT($json,'$._recorded_clock.$field'))"
            : "json_extract($json,'$._recorded_clock.$field')";
        $type = $mysql ? "JSON_TYPE(JSON_EXTRACT($json,'$._recorded_clock.unix'))='INTEGER'"
            : "json_type($json,'$._recorded_clock.unix')='integer'";
        $versionType = $mysql ? "JSON_TYPE(JSON_EXTRACT($json,'$._recorded_clock.version'))='INTEGER'"
            : "json_type($json,'$._recorded_clock.version')='integer'";
        $size=$mysql?"JSON_LENGTH($json,'$._recorded_clock')=3":"(SELECT COUNT(*) FROM json_each($json,'$._recorded_clock'))=3";
        return "CASE WHEN $size AND $versionType AND ".$get('version')."=1 AND ".$get('basis')."='server_recording_unix' AND $type AND ".$get('unix')." BETWEEN 946684800 AND 4102444800 THEN CAST(".$get('unix').($mysql?' AS UNSIGNED)':' AS INTEGER)')." ELSE NULL END";
    }
}
