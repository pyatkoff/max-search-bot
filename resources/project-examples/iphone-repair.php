<?php

/**
 * Public settings template only. No project, channel, goal or worker is enabled.
 * Populate through a server-owned provisioning path, never from request input.
 */
return [
    'id' => 'iphone_repair',
    'business' => [
        'profile' => 'device_repair',
    ],
    'brand' => [
        'name' => null,
    ],
    'routing' => [],
    'messenger' => [],
    'analytics' => [],
];
