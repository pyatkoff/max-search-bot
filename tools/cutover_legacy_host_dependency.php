<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/services/CutoverLegacyHostDependency.php';

$result = CutoverLegacyHostDependency::assess();

echo 'LEGACY_HOST_DEPENDENCY=' . ($result['legacy_host_dependency'] ? 'YES' : 'NO') . PHP_EOL;
echo 'LEAD_RECEIVER_HOST=' . $result['lead_receiver_host'] . PHP_EOL;
foreach ($result['dependencies'] as $dependency) {
    echo 'LEGACY_DEPENDENCY=' . $dependency . PHP_EOL;
}
