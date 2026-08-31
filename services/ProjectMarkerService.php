<?php

declare(strict_types=1);

/**
 * Resolves the AnyTour project marker without coupling application code to the
 * Bitrix/site bootstrap. A non-empty standalone override wins; otherwise the
 * legacy site value remains authoritative when available.
 */
final class ProjectMarkerService
{
    public static function anytourOnline()
    {
        if (defined('MAX_SEARCH_IS_ANYTOUR_ONLINE')) {
            $configured = constant('MAX_SEARCH_IS_ANYTOUR_ONLINE');
            if ($configured !== null && $configured !== '') {
                return $configured;
            }
        }

        if (class_exists('CSiteParams') && property_exists('CSiteParams', 'isAnytourOnline')) {
            return CSiteParams::$isAnytourOnline;
        }

        return null;
    }
}
