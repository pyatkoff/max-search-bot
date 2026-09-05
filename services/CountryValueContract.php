<?php

/**
 * Side-effect-free country directory-ID normalization contract.
 *
 * This class is intentionally disconnected from runtime writers. It accepts
 * only positive canonical directory IDs and preserves their exact decimal
 * representation for the existing wizard value.
 */
final class CountryValueContract
{
    public static function fromDirectoryId($directoryId): ?string
    {
        if (is_int($directoryId)) {
            return $directoryId > 0 ? (string)$directoryId : null;
        }

        if (!is_string($directoryId)) return null;
        if (preg_match('/^[1-9][0-9]*\\z/', $directoryId) !== 1) return null;

        return $directoryId;
    }

    public static function fromCallbackPayload(string $payload): ?string
    {
        if (preg_match('/^pick_country_([1-9][0-9]*)\\z/', $payload, $matches) !== 1) return null;

        return self::fromDirectoryId($matches[1]);
    }
}
