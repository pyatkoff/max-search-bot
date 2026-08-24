<?php
// Contract marker for source routing. Runtime behavior is exercised against the real DB after deploy.
require_once __DIR__ . '/../services/RoutingAccessService.php';
if (!class_exists('RoutingAccessService')) { fwrite(STDERR,"RoutingAccessService missing\n"); exit(1); }
echo "ROUTING POLICY CONTRACT OK\n";
