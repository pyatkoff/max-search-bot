<?php
/**
 * Versioned, non-secret project configuration.
 * Secrets remain in config.php. This file describes only project identity,
 * integration choices and public/non-secret routing settings.
 */
return [
    'id' => 'anytour',
    'brand' => [
        'name' => 'AnyTour',
    ],
    'routing' => [
        'source_key' => 'max:anytour-main',
    ],
    'messenger' => [
        'provider' => 'max',
        'channel_url' => 'https://max.ru/anytour',
        'miniapp_bot_url' => 'https://max.ru/id9704048781_2_bot',
        'open_channel_path' => '/max-search/open_channel.php',
    ],
    'search' => [
        'provider' => 'tourvisor',
        // Public search/claim destination. Keep independent from repo-local tracking endpoints.
        'base_domain' => 'https://anytour.online',
        // Origin that owns /max-search/open_tours.php and other tracking routes.
        'tracking_base_domain' => 'https://anytour.online',
        'claim_path' => '/poisk-turov-tg/{code}/',
        'open_tours_path' => '/max-search/open_tours.php',
    ],
    'leads' => [
        'provider' => 'bitrix',
        'claim_hl' => 33,
        'iblock_id' => 4,
        'section_id' => 26,
        'status_id' => 9,
        'uon_source_id' => 36,
    ],
    'state' => [
        'legacy_hl' => 32,
        'v2_store_dir' => 'runtime/trip_state',
    ],
    'analytics' => [
        'provider' => 'metrika',
    ],
];