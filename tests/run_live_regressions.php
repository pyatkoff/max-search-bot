<?php

declare(strict_types=1);

// Compatibility entry point. New production-derived scenarios live under
// tests/scenarios/<suite>/ and execute through the shared scenario engine.
$argv=[__FILE__,'dialogue'];
require __DIR__.'/run_scenarios.php';
