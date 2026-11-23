<?php

declare(strict_types=1);

require_once __DIR__ . '/AiRuntimeLogger.php';

/**
 * Private, bounded metadata projection of existing AI invocation frames.
 *
 * Payload bytes are checked locally and discarded. The projection is not an
 * original log, an authenticated trace, or a link to a customer/session. The
 * existing encrypted evidence projector replaces random invocation IDs with
 * per-capture aliases; no public diagnostic may retain this collection.
 */
final class AiInvocationMetadataSnapshot
{
    public const SCHEMA = 'anytour.ai_invocation_metadata.v1';
    public const MAX_FILE_BYTES = 1048576;
    public const MAX_FRAME_BYTES = 65536;
    public const MAX_HEADER_BYTES = 4096;
    public const MAX_RETAINED_PER_STREAM = 128;
    public const MAX_FRAMES_PER_STREAM = 4096;
    public const MAX_AGE_SECONDS = 3600;
    public const MAX_FUTURE_SECONDS = 60;

    public static function collect(?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $result = [
            'schema' => self::SCHEMA,
            'generated_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'ok' => true,
            'projection' => 'metadata_only',
            'trust' => 'unauthenticated_text_log',
            'runtime_trace_proven' => false,
            'causal_diagnosis_performed' => false,
            'cross_stream_atomic' => false,
            'source_history_complete' => false,
            'session_link_available' => false,
            'limits' => [
                'file_bytes' => self::MAX_FILE_BYTES,
                'frame_bytes' => self::MAX_FRAME_BYTES,
                'header_bytes' => self::MAX_HEADER_BYTES,
                'retained_per_stream' => self::MAX_RETAINED_PER_STREAM,
                'parsed_per_stream' => self::MAX_FRAMES_PER_STREAM,
                'age_seconds' => self::MAX_AGE_SECONDS,
                'future_seconds' => self::MAX_FUTURE_SECONDS,
            ],
            'streams' => [],
            'records' => [],
        ];
        foreach (['debug' => AiRuntimeLogger::debugFile(false), 'error' => AiRuntimeLogger::errorFile(false)] as $stream => $path) {
            $source = self::readSnapshot($path);
            $state = [
                'stream' => $stream,
                'read_status' => $source['reason'] === null ? 'available' : 'unavailable',
                'reason' => $source['reason'],
                'bytes_read' => strlen($source['bytes']),
                'valid_frames' => 0,
                'retained_frames' => 0,
                'omitted_old_frames' => 0,
                'omitted_future_frames' => 0,
                'omitted_by_limit' => 0,
                'legacy_bytes' => 0,
                'cutoff_reason' => null,
                'untrusted_tail_bytes' => 0,
            ];
            if ($source['reason'] === null) {
                [$records, $state] = self::project($source['bytes'], $state, $now->getTimestamp());
                $result['records'] = array_merge($result['records'], $records);
            }
            unset($source);
            if ($state['read_status'] !== 'available' || $state['cutoff_reason'] !== null || $state['omitted_by_limit'] > 0) $result['ok'] = false;
            $result['streams'][] = $state;
        }
        return $result;
    }

    private static function readSnapshot(string $path): array
    {
        $unavailable = static fn(string $reason): array => ['bytes' => '', 'reason' => $reason];
        if ($path === '') return $unavailable('log_directory_unavailable');
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false) return $unavailable('file_missing');
        if (($before['mode'] & 0170000) !== 0100000 || is_link($path)) return $unavailable('unsafe_file_type');
        if (($before['mode'] & 0777) !== 0600 || (@fileperms(dirname($path)) & 0777) !== 0700) return $unavailable('unsafe_permissions');
        if ($before['size'] > self::MAX_FILE_BYTES) return $unavailable('file_size_limit');
        $handle = @fopen($path, 'rb');
        if ($handle === false) return $unavailable('file_unreadable');
        try {
            $opened = @fstat($handle);
            if (!is_array($opened) || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']
                || ($opened['mode'] & 0170000) !== 0100000 || ($opened['mode'] & 0777) !== 0600) return $unavailable('file_changed');
            // Never wait on a model/request logger or repair its paths/modes.
            if (!@flock($handle, LOCK_SH | LOCK_NB)) return $unavailable('file_busy');
            try {
                $locked = @fstat($handle);
                if (!is_array($locked) || $locked['size'] > self::MAX_FILE_BYTES) return $unavailable('file_size_limit');
                $bytes = @stream_get_contents($handle, self::MAX_FILE_BYTES + 1);
                $after = @fstat($handle);
                if ($bytes === false) return $unavailable('read_failed');
                if (strlen($bytes) > self::MAX_FILE_BYTES) return $unavailable('file_size_limit');
                if (!is_array($after) || strlen($bytes) !== $locked['size'] || $after['size'] !== $locked['size']
                    || $after['mtime'] !== $locked['mtime'] || $after['ctime'] !== $locked['ctime']) return $unavailable('file_changed');
                return ['bytes' => $bytes, 'reason' => null];
            } finally {
                @flock($handle, LOCK_UN);
            }
        } finally {
            @fclose($handle);
        }
    }

    private static function metadata(string $header): array
    {
        if (!str_starts_with($header, 'AI INVOCATION: ') || str_ends_with($header, "\r")) throw new RuntimeException('invalid_header');
        $json = substr($header, 15);
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $ignored) {
            throw new RuntimeException('invalid_metadata_json');
        }
        $keys = ['schema', 'invocation_id', 'stage', 'recorded_at_utc', 'payload_bytes', 'payload_sha256'];
        if (!is_array($data) || count($data) !== count($keys) || array_diff(array_keys($data), $keys)) throw new RuntimeException('invalid_metadata_fields');
        // All admitted values below are simple scalars without quoted keys.
        // Counting JSON key tokens also rejects duplicate keys, which PHP's
        // json_decode would otherwise silently replace. Escaped keys are valid.
        if (preg_match_all('/"(?:[^"\\\\]|\\\\.)*"\s*:/', $json) !== count($keys)) throw new RuntimeException('duplicate_metadata_key');
        if ($data['schema'] !== 'anytour.ai_invocation.v1') throw new RuntimeException('unsupported_schema');
        if ($data['invocation_id'] !== null && (!is_string($data['invocation_id']) || !preg_match('/\A[0-9a-f]{32}\z/D', $data['invocation_id']))) throw new RuntimeException('invalid_invocation_id');
        if (!is_string($data['stage']) || !in_array($data['stage'], ['input', 'result', 'exception'], true)) throw new RuntimeException('invalid_stage');
        if (!is_string($data['recorded_at_utc']) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $data['recorded_at_utc'])) throw new RuntimeException('invalid_timestamp');
        $stamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $data['recorded_at_utc'], new DateTimeZone('UTC'));
        if ($stamp === false || $stamp->format('Y-m-d\TH:i:s\Z') !== $data['recorded_at_utc']) throw new RuntimeException('invalid_timestamp');
        if (!is_int($data['payload_bytes']) || $data['payload_bytes'] < 0 || $data['payload_bytes'] + strlen($header) + 1 > self::MAX_FRAME_BYTES) throw new RuntimeException('frame_size_limit');
        if (!is_string($data['payload_sha256']) || !preg_match('/\A[0-9a-f]{64}\z/D', $data['payload_sha256'])) throw new RuntimeException('invalid_payload_digest');
        $data['timestamp'] = $stamp->getTimestamp();
        return $data;
    }

    private static function project(string $bytes, array $state, int $now): array
    {
        $records = [];
        $position = 0;
        $length = strlen($bytes);
        $stop = static function (string $reason, int $from) use (&$state, $length): void {
            $state['cutoff_reason'] = $reason;
            $state['untrusted_tail_bytes'] = $length - $from;
        };
        while ($position < $length) {
            $found = preg_match('/^AI INVOCATION:/m', $bytes, $match, PREG_OFFSET_CAPTURE, $position);
            $start = $found === 1 ? $match[0][1] : $length;
            if ($start > $position) {
                $legacy = substr($bytes, $position, $start - $position);
                if (preg_match('/^\[record truncated\]\r?$/m', $legacy)) {
                    $stop('truncation_marker_in_legacy', $position);
                    break;
                }
                $state['legacy_bytes'] += strlen($legacy);
                $position = $start;
            }
            if ($found !== 1) break;
            if ($state['valid_frames'] >= self::MAX_FRAMES_PER_STREAM) {
                $stop('parsed_frame_limit', $position);
                break;
            }
            $end = strpos($bytes, "\n", $position);
            if ($end === false || $end - $position > self::MAX_HEADER_BYTES) {
                $stop($length - $position > self::MAX_HEADER_BYTES ? 'header_size_limit' : 'incomplete_header', $position);
                break;
            }
            try {
                $metadata = self::metadata(substr($bytes, $position, $end - $position));
                $payloadStart = $end + 1;
                $payloadEnd = $payloadStart + $metadata['payload_bytes'];
                if ($payloadEnd > $length) throw new RuntimeException('payload_truncated');
                $payload = substr($bytes, $payloadStart, $metadata['payload_bytes']);
                if (!hash_equals($metadata['payload_sha256'], hash('sha256', $payload))) throw new RuntimeException('payload_digest_mismatch');
                if ($payload !== '' && !str_ends_with($payload, "\n")) throw new RuntimeException('payload_not_lf_aligned');
                if (preg_match('/^\[record truncated\]\r?$/m', $payload)) throw new RuntimeException('truncation_marker_in_payload');
            } catch (RuntimeException $error) {
                $stop($error->getMessage(), $position);
                break; // No resynchronization through payload/unknown tail.
            }
            $state['valid_frames']++;
            $age = $now - $metadata['timestamp'];
            if ($age > self::MAX_AGE_SECONDS) $state['omitted_old_frames']++;
            elseif ($age < -self::MAX_FUTURE_SECONDS) $state['omitted_future_frames']++;
            else {
                $shape = match ($metadata['stage']) {
                    'input' => preg_match('/^AI INPUT: /m', $payload) === 1 && preg_match('/^AI CONTEXT BEFORE: /m', $payload) === 1,
                    'result' => str_starts_with($payload, 'AI RAW: '),
                    'exception' => preg_match('/\A\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}--- chat=[^\r\n]* --- /', $payload) === 1,
                };
                $records[] = [
                    'stream' => $state['stream'],
                    'frame_index' => $state['valid_frames'],
                    'invocation_id' => $metadata['invocation_id'],
                    'stage' => $metadata['stage'],
                    'age_seconds' => $age,
                    'payload_bytes' => $metadata['payload_bytes'],
                    'frame_bytes' => $payloadEnd - $position,
                    'payload_sha256_verified' => true,
                    'shape_confirmed' => $shape,
                    'embedded_prefix_lines' => preg_match_all('/^AI INVOCATION:/m', $payload),
                ];
                if (count($records) > self::MAX_RETAINED_PER_STREAM) {
                    array_shift($records);
                    $state['omitted_by_limit']++;
                }
            }
            unset($payload, $metadata);
            $position = $payloadEnd;
        }
        $state['retained_frames'] = count($records);
        return [$records, $state];
    }
}
