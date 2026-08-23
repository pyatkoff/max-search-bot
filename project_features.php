<?php
/**
 * Non-secret project feature switches.
 *
 * These flags are versioned on purpose so V2 actions can be promoted or rolled
 * back independently without touching config.php or secrets.
 */
return [
    'ai_v2' => [
        'shadow' => true,
        // Safe first promotion: only explicit text requests for a human manager
        // are intercepted; V2EarlyActionHandler also applies a deterministic guard.
        'manager_request' => true,
        // Keep route-advice promotion off until the shadow comparison has enough
        // real samples. Existing legacy DepartureRouteAdviceHandler still works.
        'destination_advice' => false,
    ],
];
