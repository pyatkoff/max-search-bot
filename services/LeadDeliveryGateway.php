<?php

declare(strict_types=1);

require_once __DIR__ . '/BitrixLeadDeliveryGateway.php';
require_once __DIR__ . '/HttpLeadDeliveryGateway.php';

/** Explicit boundary for lead persistence/delivery. */
final class LeadDeliveryGateway
{
    public static function driver(): string
    {
        if (defined('MAX_SEARCH_LEAD_DELIVERY')) {
            $driver = strtolower(trim((string) MAX_SEARCH_LEAD_DELIVERY));
            return $driver !== '' ? $driver : 'bitrix';
        }
        return 'bitrix';
    }

    public static function create(array $element)
    {
        $driver = self::driver();
        if ($driver === 'bitrix') return BitrixLeadDeliveryGateway::create($element);
        if ($driver === 'bridge') return HttpLeadDeliveryGateway::create($element);
        throw new RuntimeException('Unsupported MAX_SEARCH_LEAD_DELIVERY driver: ' . $driver);
    }
}
