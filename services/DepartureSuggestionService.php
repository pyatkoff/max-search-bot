<?php
declare(strict_types=1);

require_once __DIR__ . '/DepartureSuggestionResolver.php';
require_once __DIR__ . '/YandexRegionCatalog.php';
require_once __DIR__ . '/ConversationEntryRegionService.php';
require_once __DIR__ . '/TravelDirectoryRepository.php';
require_once __DIR__ . '/IntegrationRegistry.php';

/** An unconfirmed departure suggestion never becomes a search condition here. */
final class DepartureSuggestionService
{
    public static function platform(string $platform = ''): string
    {
        $platform = strtolower(trim($platform));
        if ($platform !== '') return in_array($platform, ['max','telegram','website'], true) ? $platform : '';
        $messenger = IntegrationRegistry::messenger();
        if ($messenger instanceof TelegramMessengerAdapter) return 'telegram';
        if ($messenger instanceof WebsiteMessengerAdapter) return 'website';
        if ($messenger instanceof MaxMessengerAdapter) return 'max';
        return '';
    }

    public static function forChat($chatId, ?array $current = null, string $platform = ''): array
    {
        try {
            if (!class_exists('MaxSearchApi')) return [];
            $current = $current ?? (array)MaxSearchApi::getAiSearchContext($chatId);
            if (!empty($current['city'])) return [];
            // A saved ID, including no-flight, remains authoritative even if its label is unavailable.
            if (!in_array('city', (array)MaxSearchApi::getAiMissingFields($chatId), true)) return [];
            $platform = self::platform($platform);
            if ($platform === '') return [];
            $entry = ConversationEntryRegionService::forChat($chatId, $platform);
            $regionId = YandexRegionCatalog::normalizeId($entry['yandex_region_id'] ?? '');
            if ($regionId === '') return [];
            $overrides = (array)ProjectConfig::get('regional_departures.overrides', []);
            $region = YandexRegionCatalog::findByYandexId($regionId);
            if ($region === null) {
                if (!array_key_exists($regionId, $overrides)) return [];
                $region = ['id'=>$regionId, 'name'=>'', 'departure_names'=>[]];
            }
            return DepartureSuggestionResolver::resolve($region, TravelDirectoryRepository::activeDepartures(),
                $overrides);
        } catch (Throwable $ignored) {
            return [];
        }
    }

    /** An extracted AI city remains explicit input; only an added default is suppressed. */
    public static function withoutDefaultCity(array $params, array $current, array $suggestion, array $recognizedParameters = []): array
    {
        if (empty($current['city']) && empty($recognizedParameters['city']) && !empty($suggestion['candidates'])) unset($params['city']);
        return $params;
    }

    public static function candidate(array $suggestion, string $id): ?array
    {
        foreach ((array)($suggestion['candidates'] ?? []) as $candidate) {
            if ((string)($candidate['id'] ?? '') === $id) return $candidate;
        }
        return null;
    }
}
