<?php

/**
 * Side-effect-free representation contract for the stored departure date.
 *
 * This class deliberately owns only the exact DD.MM.YYYY shape and calendar
 * validity. It does not choose today/future policy; each authorized caller
 * retains ownership of that separate policy.
 */
final class DateValueContract
{
    public static function fromStorageValue($value): ?string
    {
        if (!is_string($value)) return null;
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\z/', $value, $matches) !== 1) return null;

        $day = (int)$matches[1];
        $month = (int)$matches[2];
        $year = (int)$matches[3];
        if (!checkdate($month, $day, $year)) return null;

        return $value;
    }

    public static function fromCallbackPayload($payload): ?string
    {
        if (!is_string($payload)) return null;
        if (preg_match('/^pick_date_(\d{2}\.\d{2}\.\d{4})\z/', $payload, $matches) !== 1) return null;

        return self::fromStorageValue($matches[1]);
    }
}
