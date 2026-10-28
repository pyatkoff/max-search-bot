<?php
declare(strict_types=1);
putenv('DEVELOPMENT_WORK_NATIVE_MYSQL=1');
require dirname(__DIR__).'/run_development_work_queue_regression.php';

// Separate real migrated OAuth + dispatcher composition after the queue suite.
require_once __DIR__.'/development-work-connection-authority.php';
developmentHandoffNativeAuthority();
