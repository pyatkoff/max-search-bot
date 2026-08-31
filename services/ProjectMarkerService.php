<?php

declare(strict_types=1);

/**
 * Resolves the legacy AnyTour project marker without making application code
 * depend directly on Bitrix/site bootstrap globals.
 */
final class ProjectMarkerService
{
    public static function anytourOnline()
    {
        if (defined('MAX_SEARCH_IS_ANYTOUR_ONLINE')) {
            return constant('MAX_SEARCH_IS_ANYTOUR_ONLINE');
        }

        if (class_exists('CSiteParams') && property_exists('CSiteParams', 'isAnytourOnline')) {
            return CSiteParams::$isAnytourOnline;
        }

        return null;
    }
}
