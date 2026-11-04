<?php
declare(strict_types=1);
putenv('HOTEL_WORK_NATIVE_MYSQL=1');

// Retain the original suite order and fail-fast behavior; expose which suite
// dominates the existing native-MySQL queue test without extra CI runs.
foreach ([
    'run_hotel_work_queue_regression.php',
    'run_hotel_retirement_ops_regression.php',
    'run_hotel_server_cycle_regression.php',
    'run_hotel_server_ai_provider_regression.php',
] as $suite) {
    $started = microtime(true);
    try {
        require dirname(__DIR__).'/'.$suite;
    } finally {
        fprintf(STDERR, "HOTEL_MYSQL_SUITE_TIMING suite=%s seconds=%.3f\n", $suite, microtime(true) - $started);
    }
}
