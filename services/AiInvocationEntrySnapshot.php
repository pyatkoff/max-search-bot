<?php

declare(strict_types=1);

require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ConversationRecordClock.php';

/** Bounded, private observation of the independent invocation-entry store. */
final class AiInvocationEntrySnapshot
{
    public const SCHEMA = 'anytour.ai_invocation_entries.v1';
    public const MAX_ROWS = 128;
    public const MAX_PAYLOAD_BYTES = 1024;
    public const MAX_AGE_SECONDS = 3600;
    public const MAX_FUTURE_SECONDS = 60;

    public static function collect(?DateTimeImmutable $now = null, ?PDO $pdo = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();
        $result = [
            'schema' => self::SCHEMA, 'available' => false, 'reason' => 'database_not_configured',
            'trust' => 'source_reported_database_event', 'source_history_complete' => false,
            'runtime_trace_proven' => false, 'provider_request_proven' => false, 'natural_origin_proven' => false,
            'limits' => ['rows' => self::MAX_ROWS, 'payload_bytes' => self::MAX_PAYLOAD_BYTES,
                'age_seconds' => self::MAX_AGE_SECONDS, 'future_seconds' => self::MAX_FUTURE_SECONDS],
            'rows_read' => 0, 'retained_rows' => 0, 'invalid_rows' => 0,
            'omitted_old_rows' => 0, 'omitted_future_rows' => 0, 'has_more' => false, 'records' => [],
        ];
        try {
            if ($pdo === null) {
                if (!ConversationDb::isConfigured()) return $result;
                $pdo = ConversationDb::connection();
            }
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!in_array($driver, ['mysql', 'sqlite'], true)) throw new RuntimeException('unsupported_driver');
            $mysql = $driver === 'mysql';
            $jsonValue = static function (string $column, string $key) use ($mysql): string {
                $safe = "CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END";
                return $mysql ? "JSON_UNQUOTE(JSON_EXTRACT($safe, '$.$key'))" : "json_extract($safe, '$.$key')";
            };
            $payloadLength = $mysql ? 'OCTET_LENGTH(e.payload_json)' : 'length(CAST(e.payload_json AS BLOB))';
            // MySQL string/BIGINT comparison can round through floating point.
            // An explicit integer operand keeps the primary-key join unique;
            // canonical decimal shape/range is still checked below before use.
            $inputId = 'CAST(' . $jsonValue('s.payload_json', 'input_message_id') . ($mysql ? ' AS UNSIGNED)' : ' AS INTEGER)');
            $inputKind = $jsonValue('m.metadata_json', 'type');
            // Bound event rows and transferred payload bytes before the exact
            // input join. No message text, raw native ID or full input metadata
            // is selected. A broken input link must not hide the entry event.
            $sql = "SELECT s.id AS event_id, s.actor_type, s.payload_json,
                m.id AS input_row_id, m.direction, m.sender_type, m.channel,
                CASE WHEN $inputKind = 'message' THEN 'message' WHEN $inputKind = 'callback' THEN 'callback' ELSE NULL END AS input_kind,
                CASE WHEN m.external_message_id IS NOT NULL AND TRIM(m.external_message_id) <> '' THEN 1 ELSE 0 END AS native_id_present
                FROM (SELECT e.id, e.conversation_id, e.actor_type, e.created_at,
                    CASE WHEN $payloadLength <= " . self::MAX_PAYLOAD_BYTES . " THEN e.payload_json ELSE NULL END AS payload_json
                    FROM conversation_events e JOIN conversations c ON c.id = e.conversation_id
                    WHERE c.project_key = ? AND e.event_type = ?
                    ORDER BY e.created_at DESC, e.id DESC LIMIT " . (self::MAX_ROWS + 1) . ") s
                LEFT JOIN messages m ON m.id = $inputId AND m.conversation_id = s.conversation_id
                ORDER BY s.created_at DESC, s.id DESC";
            $statement = $pdo->prepare($sql);
            if ($statement === false || $statement->execute(['anytour', 'ai_invocation_entered']) !== true) {
                throw new RuntimeException('read_failed');
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || $statement->errorCode() !== '00000') throw new RuntimeException('read_failed');
            $result['available'] = true;
            $result['reason'] = null;
            $result['has_more'] = count($rows) > self::MAX_ROWS;
            $seen = [];
            foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
                $result['rows_read']++;
                $eventId = (string)($row['event_id'] ?? '');
                $payload = self::payload($row['payload_json'] ?? null);
                if (!self::identifier($eventId) || isset($seen[$eventId]) || $row['actor_type'] !== 'system' || $payload === null) {
                    $result['invalid_rows']++;
                    continue;
                }
                $seen[$eventId] = true;
                $age = $now - $payload['_recorded_clock']['unix'];
                if ($age > self::MAX_AGE_SECONDS) { $result['omitted_old_rows']++; continue; }
                if ($age < -self::MAX_FUTURE_SECONDS) { $result['omitted_future_rows']++; continue; }
                $link = 'retained_inbound';
                if ($row['input_row_id'] === null) {
                    $link = 'input_row_unavailable';
                } elseif ((string)$row['input_row_id'] !== $payload['input_message_id']
                    || $row['direction'] !== 'inbound' || $row['sender_type'] !== 'customer'
                    || $row['channel'] !== $payload['input_channel'] || $row['input_kind'] !== $payload['input_kind']) {
                    $link = 'input_provenance_mismatch';
                }
                $result['records'][] = [
                    'event_id' => $eventId, 'invocation_id' => $payload['invocation_id'], 'age_seconds' => $age,
                    'input_message_id' => $link === 'retained_inbound' ? $payload['input_message_id'] : null,
                    'input_link' => $link, 'input_kind' => $payload['input_kind'], 'input_channel' => $payload['input_channel'],
                    'native_id_present' => $link === 'retained_inbound' ? (string)$row['native_id_present'] === '1' : null,
                ];
            }
            $result['retained_rows'] = count($result['records']);
            return $result;
        } catch (Throwable $ignored) {
            // Unknown read outcome is unavailable, never a successful empty.
            $result['available'] = false;
            $result['reason'] = 'read_failed';
            $result['rows_read'] = $result['retained_rows'] = $result['invalid_rows'] = 0;
            $result['omitted_old_rows'] = $result['omitted_future_rows'] = 0;
            $result['has_more'] = false;
            $result['records'] = [];
            return $result;
        }
    }

    private static function identifier($value): bool
    {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,19}\z/D', $value) === 1
            && (strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0);
    }

    private static function payload($json): ?array
    {
        if (!is_string($json) || strlen($json) > self::MAX_PAYLOAD_BYTES) return null;
        try { $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR); }
        catch (Throwable $ignored) { return null; }
        $keys = ['schema', 'invocation_id', 'input_message_id', 'input_kind', 'input_channel', '_recorded_clock'];
        if (!is_array($data) || count($data) !== count($keys) || array_diff(array_keys($data), $keys)
            || preg_match_all('/"(?:[^"\\\\]|\\\\.)*"\s*:/', $json) !== 9
            || $data['schema'] !== 'anytour.ai_invocation_entry.v1'
            || ($data['invocation_id'] !== null && (!is_string($data['invocation_id'])
                || preg_match('/\A[0-9a-f]{32}\z/D', $data['invocation_id']) !== 1))
            || !self::identifier($data['input_message_id'])
            || !in_array($data['input_kind'], ['message', 'callback'], true)
            || !in_array($data['input_channel'], ['max', 'telegram', 'website'], true)
            || ConversationRecordClock::read($json) === null) return null;
        return $data;
    }
}
