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
        'telegram' => [
            'bot_url' => 'https://t.me/Any_tour_bot',
            'source_key' => 'telegram:anytour-main',
            'webhook_path' => '/max-search/telegram_webhook.php',
        ],
    ],
    'search' => [
        'provider' => 'tourvisor',
        // Every customer-facing tour-search link belongs to the canonical search page.
        'base_domain' => 'https://anytoour.ru',
        'search_path' => '/poisk-turov/',
        // Backward-compatible claim helper resolves to the same public origin/path.
        'claim_base_domain' => 'https://anytoour.ru',
        'claim_path' => '/poisk-turov/',
        // Tracking endpoints remain repository-owned infrastructure on the legacy app origin.
        'tracking_base_domain' => 'https://anytour.online',
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