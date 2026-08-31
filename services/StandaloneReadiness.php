<?php

declare(strict_types=1);

/**
 * Pure cutover-readiness assessment for running MAX Search without local Bitrix.
 * The checker receives facts instead of secret values, so it is safe to log.
 */
final class StandaloneReadiness
{
    public static function assess(array $facts): array
    {
        $leadDelivery = (string)($facts['lead_delivery'] ?? 'bitrix');
        $leadBridgeConfigured = !empty($facts['lead_bridge_url_configured'])
            && !empty($facts['lead_bridge_secret_configured']);

        $checks = [
            'standalone_runtime_enabled' => !empty($facts['standalone_runtime_enabled']),
            'runtime_storage_mysql' => (($facts['runtime_storage'] ?? '') === 'mysql'),
            'destination_storage_mysql' => (($facts['destination_storage'] ?? '') === 'mysql'),
            'conversation_db_configured' => !empty($facts['conversation_db_configured']),
            'catalog_db_configured' => !empty($facts['catalog_db_configured']),
            'catalog_id_compatibility' => !empty($facts['catalog_id_compatibility']),
            'lead_delivery_standalone_safe' => ($leadDelivery === 'bridge'),
            // Cutover targets bridge mode, so its prerequisites must be visible
            // even while the current passive standby still says driver=bitrix.
            'lead_bridge_configured' => $leadBridgeConfigured,
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
