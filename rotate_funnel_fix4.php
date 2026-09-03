<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$src=__DIR__.'/funnel.csv';
header('Content-Type: text/plain; charset=utf-8');
if(!is_file($src)) {
    echo "NO funnel.csv — новый создастся автоматически.\n";
    exit;
}
$dst=__DIR__.'/funnel_before_fix4_'.date('Ymd_His').'.csv';
if(@rename($src,$dst)) {
    echo "OK archived: ".basename($dst)."\n";
    echo "Новый funnel.csv создастся при следующем событии.\n";
} else {
    http_response_code(500);
    echo "ERROR: cannot rotate funnel.csv\n";
}
