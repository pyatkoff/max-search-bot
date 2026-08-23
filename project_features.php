<?php
/**
 * Non-secret project feature switches.
 *
 * These flags are versioned so individual V2 actions can be promoted or rolled
 * back independently without touching config.php or secrets.
 */
return [
    'ai_v2' => [
        'shadow' => true,
        'manager_request' => true,
        'destination_advice' => false,
        'ask' => false,
        'open_search' => false,
        'channel' => false,
    ],
];
