<?php
declare(strict_types=1);

/**
 * Pure MAX attribution rules. No storage, network, clock reads or conversion sends.
 *
 * ClientID selection follows the tracker's append order (record_id), rather than
 * its unverified wall clock. The event's actual timestamp is a separate input.
 */
final class MaxSubscriptionAttributionPolicy
{
    /**
     * Return the latest valid ClientID record in the requested identity/counter.
     *
     * Required candidate fields: record_id, external_user_id, effective_counter_id,
     * platform='max', client_id. All IDs must be positive decimal strings without
     * leading zeros. observed_at_unix is optional metadata, never an ordering key
     * or an event-time cutoff. Invalid candidates are ignored.
     *
     * @return array<string,mixed>|null Normalized winning candidate only.
     */
    public static function selectLatestClientId(array $candidates, $externalUserId, $counterId): ?array
    {
        if (!self::validId($externalUserId, 20) || !self::validId($counterId, 20)) {
            throw new InvalidArgumentException('Invalid requested identity or counter.');
        }

        $winner = null;
        $winnerConflict = false;
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)
                || ($candidate['platform'] ?? null) !== 'max'
                || ($candidate['external_user_id'] ?? null) !== $externalUserId
                || ($candidate['effective_counter_id'] ?? null) !== $counterId
                || !self::validId($candidate['record_id'] ?? null, 20)
                || !self::validId($candidate['client_id'] ?? null, 40)) {
                continue;
            }

            $observedAt = null;
            if (array_key_exists('observed_at_unix', $candidate) && $candidate['observed_at_unix'] !== null) {
                try {
                    $observedAt = self::unixValue($candidate['observed_at_unix']);
                } catch (InvalidArgumentException $ignored) {
                    $observedAt = null;
                }
            }
            $normalized = [
                'record_id' => $candidate['record_id'],
                'external_user_id' => $externalUserId,
                'effective_counter_id' => $counterId,
                'platform' => 'max',
                'client_id' => $candidate['client_id'],
                'observed_at_unix' => $observedAt,
            ];

            $order = $winner === null ? 1 : self::compareDecimal($normalized['record_id'], $winner['record_id']);
            if ($order > 0) {
                $winner = $normalized;
                $winnerConflict = false;
            } elseif ($order === 0) {
                // An append record has one ClientID; contradictory copies cannot
                // be resolved by whichever input happened to be enumerated last.
                if ($normalized['client_id'] !== $winner['client_id']) {
                    $winnerConflict = true;
                }
                if ($normalized['observed_at_unix'] !== $winner['observed_at_unix']) {
                    $winner['observed_at_unix'] = null;
                }
            }
        }
        if ($winnerConflict) throw new InvalidArgumentException('Conflicting copies of the latest attribution record.');
        return $winner;
    }

    /**
     * A supplied metric source overrides source. An unknown override fails closed;
     * it must never silently fall back to the original source's counter.
     * Values in $counterBySource must themselves be canonical decimal strings.
     */
    public static function resolveEffectiveCounterId($sourceId, $metricSourceId, array $counterBySource): ?string
    {
        $chosen = $metricSourceId !== null && $metricSourceId !== '' ? $metricSourceId : $sourceId;
        if (!self::validId($chosen, 20)) return null;
        $counter = $counterBySource[$chosen] ?? null;
        return self::validId($counter, 20) ? $counter : null;
    }

    /**
     * Convert an actual event time to Unix seconds, without consulting a touch.
     *
     * Supported timestamps: YYYY-MM-DD HH:MM:SS (or T separator), optionally
     * followed by Z or +/-HH:MM. The named timezone is mandatory. A suffix must
     * agree with that zone at the resulting instant. DST gaps and ambiguous local
     * times are rejected; an explicit matching offset resolves an overlap.
     */
    public static function eventTimeUnix($timestamp, DateTimeZone $timezone): int
    {
        if (!is_string($timestamp)
            || !preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2})[ T]([0-9]{2}:[0-9]{2}:[0-9]{2})(Z|[+-][0-9]{2}:[0-9]{2})?\z/D', $timestamp, $parts)) {
            throw new InvalidArgumentException('Unsupported event timestamp.');
        }
        $local = $parts[1] . ' ' . $parts[2];
        $naive = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $local, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$naive || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $naive->format('Y-m-d H:i:s') !== $local || $naive->getTimestamp() < 0) {
            throw new InvalidArgumentException('Invalid event calendar time.');
        }

        $explicitOffset = null;
        if (isset($parts[3]) && $parts[3] !== '') {
            $suffix = $parts[3];
            if ($suffix === 'Z') {
                $explicitOffset = 0;
            } else {
                $hours = (int)substr($suffix, 1, 2);
                $minutes = (int)substr($suffix, 4, 2);
                if ($hours > 23 || $minutes > 59 || $suffix === '-00:00') {
                    throw new InvalidArgumentException('Invalid explicit event offset.');
                }
                $explicitOffset = ($hours * 3600 + $minutes * 60) * ($suffix[0] === '-' ? -1 : 1);
            }
        }

        // Enumerate offsets around the local wall clock, then round-trip every
        // candidate. This detects both PHP's silent DST-gap normalization and the
        // two possible instants inside a fall-back overlap.
        $wallUnix = $naive->getTimestamp();
        $offsets = [$timezone->getOffset($naive)];
        $transitions = $timezone->getTransitions($wallUnix - 259200, $wallUnix + 259200);
        if (is_array($transitions)) {
            foreach ($transitions as $transition) $offsets[] = (int)$transition['offset'];
        }
        $instants = [];
        foreach (array_unique($offsets) as $offset) {
            if ($explicitOffset !== null && $explicitOffset !== $offset) continue;
            $unix = $wallUnix - $offset;
            if ($unix < 0) continue;
            $roundTrip = (new DateTimeImmutable('@' . $unix))->setTimezone($timezone);
            if ($roundTrip->format('Y-m-d H:i:s') === $local && $roundTrip->getOffset() === $offset) {
                $instants[(string)$unix] = $unix;
            }
        }
        if (count($instants) !== 1) {
            throw new InvalidArgumentException('Nonexistent, ambiguous or offset-mismatched event time.');
        }
        return array_values($instants)[0];
    }

    /** Produce an explicit-offset timestamp that eventTimeUnix can round-trip. */
    public static function formatEventTime($unix, DateTimeZone $timezone): string
    {
        return (new DateTimeImmutable('@' . self::unixValue($unix)))->setTimezone($timezone)->format('Y-m-d H:i:sP');
    }

    private static function validId($value, int $maxDigits): bool
    {
        return is_string($value) && strlen($value) <= $maxDigits
            && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1;
    }

    /** Compare unbounded positive decimal strings without an integer/float cast. */
    private static function compareDecimal(string $left, string $right): int
    {
        $lengthOrder = strlen($left) <=> strlen($right);
        return $lengthOrder !== 0 ? $lengthOrder : strcmp($left, $right);
    }

    /** Unix seconds are numbers; customer, counter and record IDs never are. */
    private static function unixValue($value): int
    {
        if (is_int($value) && $value >= 0) return $value;
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1
            && (strlen($value) < strlen((string)PHP_INT_MAX)
                || (strlen($value) === strlen((string)PHP_INT_MAX) && strcmp($value, (string)PHP_INT_MAX) <= 0))) {
            return (int)$value;
        }
        throw new InvalidArgumentException('Invalid Unix timestamp.');
    }
}
