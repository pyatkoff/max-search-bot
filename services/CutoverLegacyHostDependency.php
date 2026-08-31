<?php

declare(strict_types=1);

require_once __DIR__ . '/LeadBridgeConfig.php';

final class CutoverLegacyHostDependency
{
    /**
     * @return array{legacy_host_dependency:bool, lead_receiver_host:string, dependencies:list<string>}
     */
    public static function assess(): array
    {
        $url = LeadBridgeConfig::receiverUrl();
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $dependencies = [];

        if ($host === 'anytour.online' || str_ends_with($host, '.anytour.online')) {
            $dependencies[] = 'lead_bridge';
        }

        return [
            'legacy_host_dependency' => $dependencies !== [],
            'lead_receiver_host' => $host,
            'dependencies' => $dependencies,
        ];
    }
}
