<?php

declare(strict_types=1);

class CSiteParams
{
    public static $isAnytourOnline = 'legacy-marker';
}

require_once dirname(__DIR__) . '/services/ProjectMarkerService.php';

$failures = [];

if (ProjectMarkerService::anytourOnline() !== 'legacy-marker') {
    $failures[] = 'legacy CSiteParams fallback was not preserved';
}

define('MAX_SEARCH_IS_ANYTOUR_ONLINE', 'standalone-marker');
if (ProjectMarkerService::anytourOnline() !== 'standalone-marker') {
    $failures[] = 'standalone config must override legacy global marker';
}

$maxSearchSource = file_get_contents(dirname(__DIR__) . '/maxsearchclass.php');
if (!is_string($maxSearchSource) || strpos($maxSearchSource, 'ProjectMarkerService::anytourOnline()') === false) {
    $failures[] = 'MaxSearchApi must resolve the marker through ProjectMarkerService';
}
if (is_string($maxSearchSource) && strpos($maxSearchSource, 'CSiteParams::$isAnytourOnline') !== false) {
    $failures[] = 'MaxSearchApi must not directly depend on CSiteParams';
}

if ($failures) {
    fwrite(STDERR, "PROJECT MARKER BOUNDARY REGRESSION FAILED\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PROJECT MARKER BOUNDARY REGRESSION PASSED\n";
