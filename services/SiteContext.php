<?php

declare(strict_types=1);

/**
 * Resolves the AnyTour-site attribution flag without coupling runtime code to
 * the Bitrix CSiteParams global. Legacy production still reads the same value;
 * standalone environments must opt in explicitly through config.
 */
final class SiteContext
{
    public static function isAnytourOnline(): bool
    {
        if (defined('MAX_SEARCH_IS_ANYTOUR_ONLINE')) {
            return (bool)MAX_SEARCH_IS_ANYTOUR_ONLINE;
        }

        if (class_exists('CSiteParams')) {
            return (bool)CSiteParams::$isAnytourOnline;
        }

        throw new RuntimeException('site_context_anytour_online_unconfigured');
    }
}
