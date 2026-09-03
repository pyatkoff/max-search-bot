<?php

declare(strict_types=1);

return [
    'optional' => [],
    'manual' => [],
    'compatibility' => [
        'tests/run_live_regressions.php' => 'Compatibility alias; required dialogue scenarios run through tests/run_scenarios.php.',
    ],
    'infrastructure' => [
        'tests/run_required_group.php' => 'Required-check runner invoked by the canonical shell runner and CI matrix.',
    ],
];
