<?php

declare(strict_types=1);

/**
 * Pure cutover-readiness assessment for running MAX Search without local Bitrix.
 *
 * The checker receives facts instead of reading secrets directly, so it is safe
 * to regression-test and never exposes credential values.
 */
final class StandaloneReadiness
{
    public static function assess(array $facts): array
    {
        $checks = [
            'standalone_runtime_enabled' => !empty($facts['standalone_runtime_enabled']),
            'runtime_storage_mysql' => (($facts['runtime_storage'] ?? '') === 'mysql'),
            'destination_storage_mysql' => (($facts['destination_storage'] ?? '') === 'mysql'),
            'conversation_db_configured' => !empty($facts['conversation_db_configured']),
            'catalog_db_configured' => !empty($facts['catalog_db_configured']),
            'lead_delivery_standalone_safe' => (($facts['lead_delivery'] ?? 'bitrix') !== 'bitrix'),
        ];

        $blockers = [];
        foreach ($checks as $name => $ok) {
            if (!$ok) $blockers[] = $name;
        }

        return [
            'ready' => $blockers === [],
            'checks' => $checks,
            'blockers' => $blockers,
        ];
    }
}
