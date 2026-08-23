<?php
require_once __DIR__ . '/ProjectConfig.php';
require_once __DIR__ . '/../contracts/MessengerInterface.php';
require_once __DIR__ . '/../contracts/SearchProviderInterface.php';
require_once __DIR__ . '/../contracts/LeadDestinationInterface.php';
require_once __DIR__ . '/../integrations/MaxMessengerAdapter.php';
require_once __DIR__ . '/../integrations/TourvisorSearchProvider.php';
require_once __DIR__ . '/../integrations/BitrixLeadDestination.php';

class IntegrationRegistry
{
    private static $messenger;
    private static $searchProvider;
    private static $leadDestination;

    public static function messenger(): MessengerInterface
    {
        if (self::$messenger instanceof MessengerInterface) return self::$messenger;
        $provider = strtolower((string)ProjectConfig::get('messenger.provider', 'max'));
        if ($provider === 'max') return self::$messenger = new MaxMessengerAdapter();
        throw new RuntimeException('Unsupported messenger provider: ' . $provider);
    }

    public static function searchProvider(): SearchProviderInterface
    {
        if (self::$searchProvider instanceof SearchProviderInterface) return self::$searchProvider;
        $provider = strtolower((string)ProjectConfig::get('search.provider', 'tourvisor'));
        if ($provider === 'tourvisor') return self::$searchProvider = new TourvisorSearchProvider();
        throw new RuntimeException('Unsupported search provider: ' . $provider);
    }

    public static function leadDestination(): LeadDestinationInterface
    {
        if (self::$leadDestination instanceof LeadDestinationInterface) return self::$leadDestination;
        $provider = strtolower((string)ProjectConfig::get('leads.provider', 'bitrix'));
        if ($provider === 'bitrix') return self::$leadDestination = new BitrixLeadDestination();
        throw new RuntimeException('Unsupported lead destination: ' . $provider);
    }

    public static function resetForTests($messenger = null, $searchProvider = null, $leadDestination = null): void
    {
        self::$messenger = $messenger;
        self::$searchProvider = $searchProvider;
        self::$leadDestination = $leadDestination;
    }
}
