<?php
declare(strict_types=1);

// Required only by an ephemeral loopback config, never a production endpoint.
require_once __DIR__.'/manager_lifecycle_fixture.php';
function lifecycleFixtureHttpBootstrap(string $database): void
{
    lifecycleFixtureBind(new LifecycleFixturePdo('sqlite:'.$database));
}
